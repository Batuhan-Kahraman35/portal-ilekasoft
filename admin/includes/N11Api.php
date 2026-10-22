<?php
/**
 * n11 Marketplace API
 *
 * KARMA PROTOKOL — bu sınıfın en ayırt edici yanı:
 *   REST : bağlantı testi (satıcı ürün sorgusu, api.n11.com/ms/*)
 *   SOAP : siparişler, sipariş kalemi kabulü, fatura linki
 *          (api.n11.com/ws/orderService/, /ws/sellerInvoiceService/)
 *
 * Portal kapsamı: ürün, kategori, kargo şablonu ve müşteri soruları bu
 * sürümde yoktur.
 *
 * Sipariş uçları REST'te YOKTUR; /ms/order* yollarının tamamı HTTP 503
 * döndürür. Bu "servis geçici olarak kapalı" değil, "böyle bir servis yok"
 * anlamına gelir.
 *
 * Kimlik doğrulama:
 *   REST → appkey / appsecret başlıkları
 *   SOAP → zarf içinde <auth><appKey><appSecret></auth>
 *
 * Ortak altyapı PazaryeriApi sınıfındadır; soapIstek() ilk kez burada
 * kullanılmaktadır.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class N11Api extends PazaryeriApi
{
    /** SOAP şema ad alanı */
    const SOAP_NS = 'http://www.n11.com/ws/schemas';

    /**
     * Senkron sayfa boyutu. DetailedOrderList ardışık çağrıyı reddeder
     * ("detailedOrders belli süre aralıklarıyla güncellenebilir"); 50'lik sayfalarla
     * ikinci sayfa hep bu hataya düşüyordu. 200 canlıda kabul edildi (22.10.2026),
     * böylece senkron çoğu kanalda tek istekte biter.
     */
    const SENKRON_SAYFA_BOYUTU = 200;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('n11', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    protected function baseUrl($hizmet = 'varsayilan')
    {
        return rtrim($this->getConfig('BaseURL', 'https://api.n11.com'), '/');
    }

    /** REST başlıkları; SOAP kimliği zarfın içindedir */
    protected function authHeaders()
    {
        return [
            'appkey: '    . trim((string)$this->getConfig('AppKey', '')),
            'appsecret: ' . trim((string)$this->getConfig('AppSecret', ''))
        ];
    }

    protected function configDogrula()
    {
        if (empty($this->config['AppKey']) || empty($this->config['AppSecret'])) {
            return 'n11 kanal ayarlarında AppKey / AppSecret tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'AppKey', 'etiket' => 'API Anahtarı (appkey)',
             'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'so.n11.com > Hesabım > API Hesapları'],
            ['anahtar' => 'AppSecret', 'etiket' => 'API Şifresi (appsecret)',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'Panelden "Yeni API Şifresi Oluştur" ile üretilir ve e-posta ile gelir'],
        ];
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if ($httpKod === 503) {
            return 'HTTP 503: Bu REST ucu n11 tarafında bulunmuyor. '
                 . 'Sipariş, kargo ve müşteri soruları SOAP servisleri üzerinden çalışır.';
        }
        if ($httpKod === 404 && strpos((string)$hamYanit, 'No Mapping Rule') !== false) {
            return 'HTTP 404: Böyle bir n11 REST ucu tanımlı değil.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    // =================================================================
    // SOAP yardımcıları
    // =================================================================

    private function soapAdres($servis)
    {
        $adresler = [
            'siparis' => $this->getConfig('SoapSiparis', 'https://api.n11.com/ws/orderService/'),
            'fatura'  => $this->getConfig('SoapFatura',  'https://api.n11.com/ws/sellerInvoiceService/'),
        ];
        return $adresler[$servis] ?? $adresler['siparis'];
    }

    /** Kimlik bloğu; her SOAP çağrısında zarfın başında yer alır */
    private function soapAuth()
    {
        return '<auth>'
             . '<appKey>' . htmlspecialchars((string)$this->getConfig('AppKey', ''), ENT_XML1) . '</appKey>'
             . '<appSecret>' . htmlspecialchars((string)$this->getConfig('AppSecret', ''), ENT_XML1) . '</appSecret>'
             . '</auth>';
    }

    /** SOAP zarfı üret */
    private function soapZarf($operasyon, $govde)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
             . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
             . ' xmlns:sch="' . self::SOAP_NS . '">'
             . '<soapenv:Header/><soapenv:Body>'
             . '<sch:' . $operasyon . 'Request>'
             . $this->soapAuth()
             . $govde
             . '</sch:' . $operasyon . 'Request>'
             . '</soapenv:Body></soapenv:Envelope>';
    }

    /** Operasyonu çağır ve XML kökünü döndür */
    private function soapCagir($servis, $operasyon, $govde)
    {
        return $this->soapIstek(
            $this->soapAdres($servis),
            $this->soapZarf($operasyon, $govde),
            'SOAP ' . $operasyon
        );
    }

    /**
     * SimpleXML düğümünü diziye çevir.
     * Boş XML düğümü (<taxId/>) json dönüşümünde [] olur; DB'ye dizi
     * gönderilince sqlsrv parametre hatası verir. Boş düğümler null'a çevrilir.
     */
    private function xmlDizi($dugum)
    {
        $dizi = json_decode(json_encode($dugum), true) ?: [];

        $temizle = function ($v) use (&$temizle) {
            if (!is_array($v)) {
                return $v;
            }
            if ($v === []) {
                return null;
            }
            return array_map($temizle, $v);
        };

        return array_map($temizle, $dizi);
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->istek('GET', '/ms/product-query', null,
                              ['page' => 0, 'size' => 1], 'Bağlantı Testi');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        $toplam = (int)($sonuc['veri']['totalElements'] ?? 0);

        return [
            'basarili' => true,
            'mesaj'    => 'Bağlantı başarılı. Mağazadaki ürün sayısı: ' . $toplam,
            'veri'     => $sonuc['veri']
        ];
    }

    // =================================================================
    // Siparişler (SOAP)
    // =================================================================

    /**
     * Siparişleri listele (SOAP DetailedOrderList).
     * Kalemler de aynı yanıtta geldiği için ayrı detay çağrısı gerekmez.
     *
     * @param array $filtre startDate / endDate (timestamp), status
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $bas = !empty($filtre['startDate']) ? date('d/m/Y', (int)$filtre['startDate']) : '';
        $bit = !empty($filtre['endDate'])   ? date('d/m/Y', (int)$filtre['endDate'])   : '';

        $govde = '<searchData>'
               . '<productId></productId>'
               . '<status>' . htmlspecialchars((string)($filtre['status'] ?? ''), ENT_XML1) . '</status>'
               . '<buyerName></buyerName><orderNumber></orderNumber>'
               . '<productSellerCode></productSellerCode><recipient></recipient>'
               . '<sameDayDelivery></sameDayDelivery>'
               . '<period><startDate>' . $bas . '</startDate><endDate>' . $bit . '</endDate></period>'
               . '<sortForUpdateDate>false</sortForUpdateDate><updateDateSortOrder></updateDateSortOrder>'
               . '</searchData>'
               . '<pagingData><currentPage>' . (int)$sayfa . '</currentPage>'
               . '<pageSize>' . (int)$boyut . '</pageSize></pagingData>';

        $sonuc = $this->soapCagir('siparis', 'DetailedOrderList', $govde);

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'],
                    'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        $siparisler = [];
        foreach ($sonuc['xml']->xpath('//orderList/order') ?: [] as $o) {
            $siparisler[] = $this->xmlDizi($o);
        }

        $toplam = 0;
        $sayfaSayisi = 0;
        foreach ($sonuc['xml']->xpath('//pagingData') ?: [] as $p) {
            $toplam      = (int)($p->totalCount ?? 0);
            $sayfaSayisi = (int)($p->pageCount  ?? 0);
        }

        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => $siparisler,
            'toplam'      => $toplam,
            'toplamSayfa' => $sayfaSayisi
        ];
    }

    /** Siparişleri çek ve veritabanına yaz */
    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;
        $sayfa     = 0;

        do {
            $sonuc = $this->siparisleriListele(
                ['startDate' => $baslangic, 'endDate' => $bitis], $sayfa, self::SENKRON_SAYFA_BOYUTU);

            if (!$sonuc['basarili']) {
                return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
            }

            foreach ($sonuc['siparisler'] as $s) {
                if ($this->siparisKaydet($this->siparisNotrle($s))) {
                    $toplam++;
                }
            }

            $sayfa++;
        } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0) && $sayfa < 50);

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /** n11 siparişi → nötr sipariş */
    private function siparisNotrle(array $s)
    {
        // SOAP tek kalemde diziyi sarmalamayabilir; normalize edilir
        $ham = $s['orderItemList']['orderItem'] ?? [];
        if (isset($ham['id'])) {
            $ham = [$ham];
        }

        $kalemler = [];
        foreach ((array)$ham as $it) {
            $kalemler[] = [
                'disId'         => (string)($it['id'] ?? ''),
                'barkod'        => $it['gtin'] ?? null,
                'stokKodu'      => $it['productSellerCode'] ?? null,
                'disUrunId'     => (string)($it['stockKeepingUnitId'] ?? ''),
                'urunAdi'       => $it['productName'] ?? null,
                'miktar'        => (int)($it['quantity'] ?? 1),
                'birimFiyat'    => isset($it['price'])      ? (float)$it['price']      : null,
                // Fatura "Mağaza fatura tutarı" (sellerInvoiceAmount) ile kesilir; n11'in
                // karşıladığı indirim (totalMallDiscountPrice) satıcı fiyatını etkilemez.
                // dueAmount alıcının ödediği tutardır, yalnız yedek olarak kullanılır.
                'toplamTutar'   => isset($it['sellerInvoiceAmount']) ? (float)$it['sellerInvoiceAmount']
                                 : (isset($it['dueAmount']) ? (float)$it['dueAmount'] : null),
                'indirimTutar'  => isset($it['sellerDiscount']) ? (float)$it['sellerDiscount'] : null,
                'komisyonTutar' => isset($it['commission']) ? (float)$it['commission'] : null,
                'hamDurum'      => is_scalar($it['status'] ?? null) ? (string)$it['status'] : null,
                'ham'           => $it
            ];
        }

        $alici   = $s['shippingAddress'] ?? [];
        $fatura  = $s['billingAddress']  ?? [];
        $musteri = $s['buyer']           ?? [];

        return [
            'disId'          => (string)($s['id'] ?? ($s['orderNumber'] ?? '')),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => null,   // n11'de paket kavramı yok
            'durumKod'       => $this->kalemlerdenDurum($kalemler) ?? $this->siparisDurumKodu($s['status'] ?? ''),
            'hamDurum'       => is_scalar($s['status'] ?? null) ? (string)$s['status'] : null,
            'toplamTutar'    => isset($s['totalAmount']) ? (float)$s['totalAmount'] : null,
            'paraBirimi'     => 'TRY',
            'musteriAdi'     => $musteri['fullName'] ?? null,
            'aliciAdi'       => $alici['fullName']   ?? null,
            'eposta'         => $musteri['email']    ?? null,
            'telefon'        => $alici['gsm']        ?? null,
            'tckn'           => $musteri['tcId'] ?? ($s['citizenshipId'] ?? null),
            'vergiNo'        => $fatura['taxId']     ?? ($musteri['taxId'] ?? null),
            'vergiDairesi'   => $fatura['taxHouse']  ?? ($musteri['taxOffice'] ?? null),
            'il'             => $alici['city']         ?? null,
            'ilce'           => $alici['district']     ?? null,
            'semt'           => $alici['neighborhood'] ?? null,
            'teslimatAdresi' => $alici,
            'faturaAdresi'   => $fatura,
            'kargoFirmasi'   => $this->kargoFirmasiBul($ham),
            'kargoTakipNo'   => $this->kargoTakipBul($ham),
            'siparisTarihi'  => $this->n11Tarih($s['createDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /** Kargo bilgisi kalem düzeyinde taşınır; ilk dolu olan alınır */
    private function kargoFirmasiBul($kalemler)
    {
        foreach ((array)$kalemler as $it) {
            $ad = $it['shipmentInfo']['shipmentCompany']['name'] ?? null;
            if ($ad) {
                return (string)$ad;
            }
        }
        return null;
    }

    private function kargoTakipBul($kalemler)
    {
        foreach ((array)$kalemler as $it) {
            $no = $it['shipmentInfo']['trackingNumber'] ?? null;
            if ($no && is_scalar($no)) {
                return (string)$no;
            }
        }
        return null;
    }

    /** n11 tarihleri "dd/MM/yyyy HH:mm" biçimindedir */
    private function n11Tarih($metin)
    {
        $metin = trim((string)$metin);
        if ($metin === '') {
            return null;
        }
        $d = DateTime::createFromFormat('d/m/Y H:i', $metin)
          ?: DateTime::createFromFormat('d/m/Y', $metin);

        return $d ? $d->format('Y-m-d H:i:s') : $this->isoTarih($metin);
    }

    private function siparisDurumKodu($durum)
    {
        if (!is_scalar($durum)) {
            return 'diger';
        }
        switch (strtolower(trim((string)$durum))) {
            case 'new': case 'created': case '1':      return 'yeni';
            case 'approved': case 'picking': case '2': return 'hazirlaniyor';
            case 'shipped': case '3':                  return 'kargoda';
            case 'delivered': case 'completed': case '5': return 'teslim';
            case 'rejected': case 'cancelled':         return 'iptal';
            case 'returned': case 'claim':             return 'iade';
            default:                                   return 'diger';
        }
    }

    /**
     * Sipariş düzeyindeki durum ayrım yapmaz ('2' = İşlemde; kargoda ve teslim
     * edilmiş siparişler de böyle gelir). Gerçek aşama kalemlerdedir.
     * Kalem durumları: 1 İşlem bekliyor, 2 Ödendi, 3 Geçersiz, 4 İptal, 5 Kabul edildi,
     * 6 Kargoda, 7 Teslim edildi, 8 Reddedildi, 9 İade edildi, 10 Tamamlandı
     * (6/7/10 canlı veriyle doğrulandı, 22.10.2026).
     * İptal olmayan kalemlerin en GERİDEKİ aşaması alınır; hepsi iptalse iptal.
     * Kalem durumu çözülemezse null döner, sipariş düzeyi eşlemesi kullanılır.
     */
    private function kalemlerdenDurum(array $kalemler)
    {
        $harita = [
            '1' => 'yeni', '2' => 'yeni', '5' => 'hazirlaniyor', '6' => 'kargoda',
            '7' => 'teslim', '10' => 'teslim', '9' => 'iade',
            '3' => 'iptal', '4' => 'iptal', '8' => 'iptal'
        ];
        $sira = ['yeni' => 1, 'hazirlaniyor' => 2, 'kargoda' => 3, 'teslim' => 4, 'iade' => 5];

        $kodlar = [];
        foreach ($kalemler as $k) {
            $kod = $harita[(string)($k['hamDurum'] ?? '')] ?? null;
            if ($kod === null) {
                return null;
            }
            $kodlar[] = $kod;
        }
        if (!$kodlar) {
            return null;
        }

        $aktif = array_values(array_filter($kodlar, fn($d) => $d !== 'iptal'));
        if (!$aktif) {
            return 'iptal';
        }
        usort($aktif, fn($a, $b) => $sira[$a] <=> $sira[$b]);
        return $aktif[0];
    }

    /**
     * Paket durumu güncelle — n11'de sipariş kalemi kabul edilir.
     * @param string $durum 'Picking' → OrderItemAccept
     */
    public function paketDurumGuncelle($paketId, $durum, array $kalemler = [], array $ek = [])
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Sipariş kalemi kimliği boş.'];
        }
        if (strtolower($durum) !== 'picking') {
            return ['basarili' => false,
                    'hata' => 'n11 yalnızca sipariş kalemi kabul işlemini destekler.'];
        }

        $govde = '<orderItemList><orderItem><id>' . (int)$paketId . '</id></orderItem></orderItemList>'
               . '<numberOfPackages>1</numberOfPackages>';

        $sonuc = $this->soapCagir('siparis', 'OrderItemAccept', $govde);

        return [
            'basarili' => $sonuc['basarili'],
            'hata'     => $sonuc['hata'],
            'mesaj'    => $sonuc['basarili'] ? 'Sipariş kalemi kabul edildi.' : ''
        ];
    }

    // =================================================================
    // Fatura linki (SOAP SellerInvoiceService)
    // =================================================================

    /** n11 linkte dosya uzantısı ister (pdf/png/jpeg); girişsiz sayfa .pdf adresiyle verilir */
    public function faturaLinkPdfZorunlu()
    {
        return true;
    }

    /**
     * Fatura linkini siparişe kaydet (SaveLinkSellerInvoice).
     * Sipariş başına tek aktif link vardır; yeniden gönderim öncekinin yerine geçer.
     * Kimlik sipariş NUMARASIDIR (orderNumber), n11 iç sipariş id'si değil.
     * invoiceNumber / invoiceDateTime yurt içinde opsiyonel; numara 3 alfanümerik + 13 rakam
     * kalıbına uymuyorsa gönderilmez (n11 değer doğrulaması siparişi reddettirmesin).
     *
     * @param string $paketId siparişin dış kimliği (yedek; siparisNo verilmezse kullanılır)
     * @param array  $fatura  link, numara, tarih (ms), siparisNo
     */
    public function faturaLinkGonder($paketId, array $fatura)
    {
        $siparisNo = trim((string)($fatura['siparisNo'] ?? '')) ?: trim((string)$paketId);
        $link      = trim((string)($fatura['link'] ?? ''));

        if ($siparisNo === '') {
            return ['basarili' => false, 'hata' => 'Sipariş numarası boş.'];
        }
        if (!preg_match('~^https://.+\.(pdf|png|jpe?g)$~i', $link)) {
            return ['basarili' => false,
                    'hata' => 'n11 fatura linki https olmalı ve pdf/png/jpeg uzantısıyla bitmeli: ' . $link];
        }

        $govde = '<url>' . htmlspecialchars($link, ENT_XML1) . '</url>'
               . '<orderNumber>' . htmlspecialchars($siparisNo, ENT_XML1) . '</orderNumber>';

        $numara = strtoupper(trim((string)($fatura['numara'] ?? '')));
        if (preg_match('/^[A-Z0-9]{3}20[2-9][0-9][0-9]{9}$/', $numara)) {
            $govde .= '<invoiceNumber>' . $numara . '</invoiceNumber>';
            if (!empty($fatura['tarih'])) {
                $govde .= '<invoiceDateTime>' . (int)$fatura['tarih'] . '</invoiceDateTime>';
            }
        }

        $sonuc = $this->soapCagir('fatura', 'SaveLinkSellerInvoice', $govde);

        return ['basarili' => $sonuc['basarili'], 'hata' => $sonuc['hata']];
    }

    // =================================================================
    // Desteklenmeyenler
    //
    // İade servisi: OrderService'te iade listeleme operasyonu yok.
    // Kargo etiketi: karşılığı yok.
    // Bu yetenekler tanim_Pazaryerleri JSON'unda kapalıdır.
    // =================================================================
}
