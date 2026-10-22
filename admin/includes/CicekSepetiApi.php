<?php
/**
 * Çiçeksepeti Marketplace API
 *
 * Ortak altyapı PazaryeriApi sınıfındadır; burada yalnızca Çiçeksepeti'ne
 * özgü uç yolları, sayfalama ve alan eşlemesi bulunur.
 *
 * ÜÇ ÖNEMLİ FARK:
 *   1. SAYFA NUMARASI UCA GÖRE DEĞİŞİR. Ürün ucu (/Products, Page) 1'den başlar;
 *      0 gönderilirse "page.should.be.greater.than.zero" döner. Sipariş ucu
 *      (/Order/GetOrders, page) ise 0'dan başlar: 1 gönderilirse ikinci sayfa
 *      istenir, sonuç tek sayfaysa liste BOŞ gelir ama orderListCount dolu
 *      görünür (22.10.2026 canlı doğrulandı).
 *   2. TEKRAR KORUMASI: Aynı uca AYNI PARAMETRELERLE yapılan istek
 *      10 DAKİKA boyunca reddedilir ("Limit aşımı! Bu endpointe aynı
 *      isteği 10 dakikada..."). Farklı parametreli istekler sorunsuzdur;
 *      bu yüzden sayfa sayfa ilerleyen senkron akışları etkilenmez, ancak
 *      AYNI SENKRON 10 dakika içinde tekrar çalıştırılırsa boş döner.
 *      Ayrıca uç bazlı kısa bekleme için HizSiniriSaniye ayarı kullanılır
 *      (ortak katmandaki hız sınırlayıcı okur).
 *   3. SİPARİŞ SORGUSU POST'tur. GET denenirse HTTP 405 döner.
 *
 * Kimlik doğrulama: x-api-key başlığı.
 * User-Agent "SatıcıID-EntegratörAdı" biçiminde olmalıdır (ör. 1234567890-MagazaApp;
 * boşluksuz, büyük/küçük harf duyarlı). Entegratör adı satıcı paneli > Hesap
 * Yönetimi > Entegrasyon Bilgileri'ndedir. Eşleşmezse istek çalışır ama yanıtta
 * warningMessage döner.
 *
 * Portal kapsamı: bağlantı testi ve sipariş senkronu. Ürün/kategori
 * işlemleri bu sürümde yoktur.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class CicekSepetiApi extends PazaryeriApi
{
    /** Sipariş sorgusunda kabul edilen azami gün aralığı */
    const SIPARIS_GUN_SINIRI = 14;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('ciceksepeti', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    protected function baseUrl($hizmet = 'varsayilan')
    {
        $sandbox = !empty($this->config['Sandbox']);

        $adres = $sandbox
            ? $this->getConfig('BaseURL_Sandbox', 'https://sandbox-apis.ciceksepeti.com/api/v1')
            : $this->getConfig('BaseURL', 'https://apis.ciceksepeti.com/api/v1');

        return rtrim($adres, '/');
    }

    protected function authHeaders()
    {
        $basliklar = ['x-api-key: ' . trim((string)$this->getConfig('ApiKey', ''))];

        // Panelde kayıtlı değerle eşleşmezse yanıtta uyarı döner
        $ua = trim((string)$this->getConfig('UserAgent', ''));
        if ($ua !== '') {
            $basliklar[] = 'User-Agent: ' . $ua;
        }

        return $basliklar;
    }

    protected function configDogrula()
    {
        if (empty($this->config['ApiKey'])) {
            return 'Çiçeksepeti kanal ayarlarında ApiKey tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'ApiKey', 'etiket' => 'API Anahtarı (x-api-key)',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'Satıcı paneli > Hesap Yönetimi > Entegrasyon Bilgileri'],
            ['anahtar' => 'SaticiId', 'etiket' => 'Satıcı ID',
             'gizli' => false, 'zorunlu' => false, 'aciklama' => ''],
            ['anahtar' => 'UserAgent', 'etiket' => 'User-Agent',
             'gizli' => false, 'zorunlu' => false,
             'aciklama' => 'Biçim: SatıcıID-EntegratörAdı (boşluksuz, büyük/küçük harf duyarlı). Örn: 1234567890-MagazaApp'],
            ['anahtar' => 'Sandbox', 'etiket' => 'Sandbox kullan (1/0)',
             'gizli' => false, 'zorunlu' => false,
             'aciklama' => 'Test ortamı için 1 yazın; sandbox anahtarı canlıdan farklıdır'],
        ];
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        // Hız sınırı mesajı gövdede düz metin olarak gelir
        if (is_array($veri) && !empty($veri['Message'])
            && stripos($veri['Message'], 'Limit') !== false) {
            return 'Hız sınırı: ' . $veri['Message'];
        }
        if ($httpKod === 405) {
            return 'HTTP 405: Bu uç bu HTTP metodunu kabul etmiyor. '
                 . 'Çiçeksepeti sipariş sorgusunu POST ile ister.';
        }
        if (is_string($hamYanit) && stripos($hamYanit, 'Date Limit Exceeded') !== false) {
            return 'Tarih aralığı çok geniş. Sipariş sorgusu dilimlenerek yapılmalıdır.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    /** Yanıttaki uyarı mesajını log'a düşür (istek başarılı olsa bile) */
    private function uyariKontrol($veri)
    {
        if (is_array($veri) && !empty($veri['warningMessage'])) {
            error_log('CicekSepeti uyarı: ' . $veri['warningMessage']);
        }
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->istek('GET', '/Products', null,
                              ['Page' => 1, 'PageSize' => 1], 'Bağlantı Testi');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        $this->uyariKontrol($sonuc['veri']);

        $toplam = (int)($sonuc['veri']['totalCount'] ?? 0);
        $mesaj  = 'Bağlantı başarılı. Mağazadaki ürün sayısı: ' . $toplam;

        if (!empty($sonuc['veri']['warningMessage'])) {
            $mesaj .= ' — Uyarı: ' . $sonuc['veri']['warningMessage'];
        }

        return ['basarili' => true, 'mesaj' => $mesaj, 'veri' => $sonuc['veri']];
    }

    // =================================================================
    // Siparişler
    // =================================================================

    /**
     * Siparişleri listele.
     * DİKKAT: Bu uç POST ister; GET denenirse HTTP 405 döner.
     * Gövde alan adları küçük harfle başlar (page, pageSize). Sayfa 0 TABANLIDIR.
     * Yanıttaki her kayıt bir sipariş KALEMİDİR (orderItemId); aynı orderId'li
     * kalemler senkronda tek siparişte toplanır.
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $govde = [
            'page'     => max(0, (int)$sayfa),
            'pageSize' => max(1, min(100, (int)$boyut))
        ];

        if (!empty($filtre['startDate'])) {
            $govde['startDate'] = date('Y-m-d', (int)$filtre['startDate']);
        }
        if (!empty($filtre['endDate'])) {
            $govde['endDate'] = date('Y-m-d', (int)$filtre['endDate']);
        }
        if (!empty($filtre['status'])) {
            $govde['status'] = $filtre['status'];
        }

        $sonuc = $this->istek('POST', '/Order/GetOrders', $govde, [], 'Sipariş Listele');

        // Hesap başka bir panelle (Örnek Müşteri) ortak; onun isteğiyle çakışınca "5 saniyede 1" hatası
        // döner. Yerel bekleme bunu göremez, bu yüzden bir kez bekleyip tekrar denenir.
        // Reddedilen istek de sayıldığından aynı gövde "dakikada 1" sınırına takılır: 61 sn beklenir.
        // (10 dakikalık "aynı istek" sınırı tekrarla aşılamaz, ona dokunulmaz.)
        if (!$sonuc['basarili'] && mb_stripos((string)$sonuc['hata'], 'saniyede') !== false) {
            sleep(61);
            $sonuc = $this->istek('POST', '/Order/GetOrders', $govde, [], 'Sipariş Listele (tekrar)');
        }

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'],
                    'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        $this->uyariKontrol($sonuc['veri']);

        $veri  = $sonuc['veri'] ?? [];
        $liste = $veri['supplierOrderListWithBranch'] ?? [];

        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => is_array($liste) ? $liste : [],
            'toplam'      => (int)($veri['orderListCount'] ?? 0),
            'toplamSayfa' => (int)($veri['pageCount'] ?? 0)
        ];
    }

    /**
     * Siparişleri çek ve kaydet.
     * Tarih aralığı sınırlı olduğu için dilimlenerek istenir. Kalemler orderId'ye
     * göre dilim sonunda toplanır: bir siparişin kalemleri sayfalara bölünebilir.
     */
    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $gruplar = [];
            $sayfa   = 0;
            do {
                $sonuc = $this->siparisleriListele([
                    'startDate' => $dilim['bas'],
                    'endDate'   => $dilim['bit']
                ], $sayfa, 100);

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
                }

                foreach ($sonuc['siparisler'] as $kalem) {
                    $gruplar[(string)($kalem['orderId'] ?? '')][] = $kalem;
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0) && $sayfa < 50);

            unset($gruplar['']);
            foreach ($gruplar as $kalemler) {
                if ($this->siparisKaydet($this->siparisNotrle($kalemler))) {
                    $toplam++;
                }
            }
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /**
     * Çiçeksepeti sipariş kalemleri (aynı orderId) → nötr sipariş.
     * Alanlar 22.10.2026'da canlı veriyle doğrulandı. Sipariş düzeyi bilgi
     * (alıcı, fatura, tarih) her kalemde tekrar eder; ilk kalemden alınır.
     * sender* alanları siparişi veren (fatura) tarafıdır, receiver* teslim alan.
     */
    private function siparisNotrle(array $kalemListesi)
    {
        $s = $kalemListesi[0];

        $kalemler = [];
        $tutar    = 0.0;
        foreach ($kalemListesi as $it) {
            $satir = isset($it['totalPrice']) ? (float)$it['totalPrice'] : null;
            $tutar += (float)$satir;

            $kalemler[] = [
                'disId'        => (string)($it['orderItemId'] ?? ''),
                'barkod'       => $it['barcode'] ?? null,
                'stokKodu'     => $it['code']    ?? null,
                'disUrunId'    => (string)($it['productCode'] ?? ''),
                'urunAdi'      => trim(($it['name'] ?? '') . (!empty($it['variantName']) ? ' - ' . $it['variantName'] : '')) ?: null,
                'miktar'       => (int)($it['quantity'] ?? 1),
                'birimFiyat'   => isset($it['itemPrice']) ? (float)$it['itemPrice'] : null,
                'toplamTutar'  => $satir,
                'indirimTutar' => isset($it['discount']) ? (float)$it['discount'] : null,
                'kdvOran'      => isset($it['tax']) ? (float)$it['tax'] : null,
                'hamDurum'     => isset($it['orderItemStatusId']) ? (string)$it['orderItemStatusId'] : null,
                'ham'          => $it
            ];
        }

        // senderTaxNumber: 11 hane → TCKN (bireysel), 10 hane → VKN (kurumsal)
        $vergiNo = preg_replace('/\D/', '', (string)($s['senderTaxNumber'] ?? ''));

        $teslimat = [
            'ad'        => $s['receiverName']        ?? null,
            'firma'     => $s['receiverCompanyName'] ?? null,
            'telefon'   => $s['receiverPhone']       ?? null,
            'adres'     => $s['receiverAddress']     ?? null,
            'semt'      => $s['receiverRegion']      ?? null,
            'ilce'      => $s['receiverDistrict']    ?? null,
            'il'        => $s['receiverCity']        ?? null,
            'postaKodu' => $s['zipCode']             ?? null
        ];
        $fatura = [
            'ad'      => $s['senderName']        ?? null,
            'firma'   => $s['senderCompanyName'] ?? null,
            'telefon' => $s['senderPhone']       ?? null,
            'adres'   => $s['senderAddress']     ?? null,
            'semt'    => $s['senderRegion']      ?? null,
            'ilce'    => $s['senderDistrict']    ?? null,
            'il'      => $s['senderCity']        ?? null
        ];

        return [
            'disId'          => (string)($s['orderId'] ?? ''),
            'siparisNo'      => (string)($s['orderId'] ?? ''),
            'paketNo'        => count($kalemListesi) === 1 ? ($s['partialNumber'] ?? null) : null,
            'durumKod'       => $this->kalemlerdenDurum($kalemListesi),
            'hamDurum'       => $s['orderProductStatus'] ?? null,
            'toplamTutar'    => $tutar,
            'paraBirimi'     => $s['currency'] ?? 'TRY',
            'musteriAdi'     => ($s['senderCompanyName'] ?? '') !== '' ? $s['senderCompanyName'] : ($s['senderName'] ?? null),
            'aliciAdi'       => $s['receiverName'] ?? null,
            'eposta'         => ($s['invoiceEmail'] ?? '') !== '' ? $s['invoiceEmail'] : ($s['senderEmail'] ?? null),
            'telefon'        => $s['receiverPhone'] ?? ($s['senderPhone'] ?? null),
            'tckn'           => strlen($vergiNo) === 11 ? $vergiNo : null,
            'vergiNo'        => strlen($vergiNo) === 10 ? $vergiNo : null,
            'vergiDairesi'   => $s['senderTaxOfficeName'] ?? null,
            'il'             => $s['receiverCity']     ?? null,
            'ilce'           => $s['receiverDistrict'] ?? null,
            'semt'           => $s['receiverRegion']   ?? null,
            'teslimatAdresi' => $teslimat,
            'faturaAdresi'   => $fatura,
            'kargoFirmasi'   => ($s['cargoCompany'] ?? '') !== '' ? $s['cargoCompany'] : null,
            'kargoTakipNo'   => ($s['cargoNumber']  ?? '') !== '' ? $s['cargoNumber']  : null,
            'siparisTarihi'  => $this->csTarih(($s['orderCreateDate'] ?? '') . ' ' . ($s['orderCreateTime'] ?? '')),
            'tahminiTeslim'  => $this->csTarih($s['requestedDeliveryDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $kalemListesi
        ];
    }

    /** Sipariş tarihi "dd/MM/yyyy HH:mm", teslim tarihleri "dd-MM-yyyy [HH:mm]" gelir */
    private function csTarih($metin)
    {
        $metin = trim((string)$metin);
        if ($metin === '') {
            return null;
        }
        foreach (['d/m/Y H:i', 'd/m/Y', 'd-m-Y H:i', 'd-m-Y'] as $bicim) {
            $d = DateTime::createFromFormat('!' . $bicim, $metin);
            if ($d) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        return $this->isoTarih($metin);
    }

    /**
     * Kalem durumu (orderItemStatusId): 1 yeni, 2 hazırlanıyor, 11 kargoya verilecek,
     * 5 kargoda, 7 teslim edildi (2/5/7 canlı doğrulandı). Bilinmeyen kimlikte
     * orderProductStatus metni yorumlanır. İptal olmayan kalemlerin en GERİDEKİ
     * aşaması alınır; hepsi iptalse iptal.
     */
    private function kalemlerdenDurum(array $kalemListesi)
    {
        $harita = ['1' => 'yeni', '2' => 'hazirlaniyor', '11' => 'hazirlaniyor', '5' => 'kargoda', '7' => 'teslim'];
        $sira   = ['yeni' => 1, 'hazirlaniyor' => 2, 'kargoda' => 3, 'teslim' => 4, 'iade' => 5, 'diger' => 6];

        $kodlar = [];
        foreach ($kalemListesi as $it) {
            $kodlar[] = $harita[(string)($it['orderItemStatusId'] ?? '')]
                     ?? $this->siparisDurumKodu($it['orderProductStatus'] ?? '');
        }

        $aktif = array_values(array_filter($kodlar, fn($d) => $d !== 'iptal'));
        if (!$aktif) {
            return 'iptal';
        }
        usort($aktif, fn($a, $b) => $sira[$a] <=> $sira[$b]);
        return $aktif[0];
    }

    /** Durum metni → kod. "Kargoya Verilecek" kargoda sayılmasın diye sıra önemlidir. */
    private function siparisDurumKodu($durum)
    {
        if (!is_scalar($durum)) {
            return 'diger';
        }
        $d = mb_strtolower(trim((string)$durum));

        if (strpos($d, 'iptal') !== false || strpos($d, 'cancel') !== false)     return 'iptal';
        if (strpos($d, 'iade') !== false || strpos($d, 'return') !== false)      return 'iade';
        if (strpos($d, 'teslim') !== false || strpos($d, 'delivered') !== false) return 'teslim';
        if (strpos($d, 'yeni') !== false || strpos($d, 'new') !== false
            || strpos($d, 'onay bekle') !== false)                               return 'yeni';
        if (strpos($d, 'hazırla') !== false || strpos($d, 'preparing') !== false
            || strpos($d, 'onaylandı') !== false || strpos($d, 'verilecek') !== false) return 'hazirlaniyor';
        if (strpos($d, 'kargo') !== false || strpos($d, 'shipped') !== false)    return 'kargoda';

        return 'diger';
    }

    // =================================================================
    // Desteklenmeyenler
    //
    // İade servisi için erişilebilir uç bulunamamıştır (09.09.2026'da
    // denenen adayların tamamı HTTP 404 döndürdü).
    // Bu yetenekler tanim_Pazaryerleri JSON'unda kapalıdır.
    // =================================================================
}
