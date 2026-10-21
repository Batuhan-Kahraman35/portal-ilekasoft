<?php
/**
 * Çiçeksepeti Marketplace API
 *
 * Ortak altyapı PazaryeriApi sınıfındadır; burada yalnızca Çiçeksepeti'ne
 * özgü uç yolları, sayfalama ve alan eşlemesi bulunur.
 *
 * ÜÇ ÖNEMLİ FARK:
 *   1. SAYFA NUMARASI 1'DEN BAŞLAR. Ortak katman 0 tabanlı çalışır;
 *      dönüşüm bu sınıfta yapılır. 0 gönderilirse API
 *      "page.should.be.greater.than.zero" hatası döndürür.
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

    /** Ortak katman 0 tabanlı sayfalar; Çiçeksepeti 1 tabanlı ister */
    private function sayfaNo($sayfa)
    {
        return max(1, (int)$sayfa + 1);
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
     * Gövde alan adları küçük harfle başlar (page, pageSize).
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $govde = [
            'page'     => $this->sayfaNo($sayfa),
            'pageSize' => (int)$boyut
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
        $liste = $veri['orders'] ?? ($veri['orderList'] ?? ($veri['data'] ?? []));

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
     * Tarih aralığı sınırlı olduğu için dilimlenerek istenir.
     */
    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $sayfa = 0;
            do {
                $sonuc = $this->siparisleriListele([
                    'startDate' => $dilim['bas'],
                    'endDate'   => $dilim['bit']
                ], $sayfa, 50);

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
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /**
     * Çiçeksepeti siparişi → nötr sipariş.
     * NOT: Alan adları canlı veriyle doğrulanmamıştır (mağazada sipariş yoktu);
     * yaygın adlar sırayla denenerek yazılmıştır.
     */
    private function siparisNotrle(array $s)
    {
        $kalemler = [];
        foreach ($s['items'] ?? ($s['orderItems'] ?? []) as $it) {
            $kalemler[] = [
                'disId'        => (string)($it['id'] ?? ($it['itemId'] ?? '')),
                'barkod'       => $it['barcode']   ?? null,
                'stokKodu'     => $it['stockCode'] ?? null,
                'disUrunId'    => (string)($it['productCode'] ?? ''),
                'urunAdi'      => $it['productName'] ?? null,
                'miktar'       => (int)($it['quantity'] ?? 1),
                'birimFiyat'   => isset($it['price'])      ? (float)$it['price']      : null,
                'toplamTutar'  => isset($it['totalPrice']) ? (float)$it['totalPrice'] : null,
                'komisyonOran' => $this->yuzdeCoz($it['commissionRate'] ?? null),
                'hamDurum'     => $it['status'] ?? null,
                'ham'          => $it
            ];
        }

        $adres = $s['deliveryAddress'] ?? ($s['shippingAddress'] ?? []);

        return [
            'disId'          => (string)($s['orderId'] ?? ($s['id'] ?? ($s['orderNumber'] ?? ''))),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => null,
            'durumKod'       => $this->siparisDurumKodu($s['status'] ?? ($s['orderStatus'] ?? '')),
            'hamDurum'       => is_scalar($s['status'] ?? null) ? (string)$s['status'] : null,
            'toplamTutar'    => isset($s['totalPrice']) ? (float)$s['totalPrice'] : null,
            'paraBirimi'     => 'TRY',
            'musteriAdi'     => $s['customerName'] ?? ($s['buyerName'] ?? null),
            'aliciAdi'       => $adres['name'] ?? ($s['receiverName'] ?? null),
            'eposta'         => $s['email']  ?? null,
            'telefon'        => $adres['phone'] ?? ($s['phone'] ?? null),
            'tckn'           => $s['identityNumber'] ?? null,
            'vergiNo'        => $s['taxNumber'] ?? null,
            'vergiDairesi'   => $s['taxOffice'] ?? null,
            'il'             => $adres['city']     ?? null,
            'ilce'           => $adres['town']     ?? ($adres['district'] ?? null),
            'semt'           => $adres['district'] ?? null,
            'teslimatAdresi' => $adres,
            'faturaAdresi'   => $s['invoiceAddress'] ?? [],
            'kargoFirmasi'   => $s['cargoCompany']  ?? null,
            'kargoTakipNo'   => $s['cargoTrackingNumber'] ?? null,
            'siparisTarihi'  => $this->isoTarih($s['orderDate'] ?? ($s['createdDate'] ?? null)),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /** Komisyon oranı "%25" biçiminde METİN gelebilir */
    private function yuzdeCoz($deger)
    {
        if ($deger === null || $deger === '') {
            return null;
        }
        if (is_numeric($deger)) {
            return (float)$deger;
        }
        $temiz = str_replace(['%', ' ', ','], ['', '', '.'], (string)$deger);
        return is_numeric($temiz) ? (float)$temiz : null;
    }

    private function siparisDurumKodu($durum)
    {
        if (!is_scalar($durum)) {
            return 'diger';
        }
        $d = mb_strtolower(trim((string)$durum));

        if (strpos($d, 'yeni') !== false || strpos($d, 'new') !== false
            || strpos($d, 'onay bekle') !== false)                     return 'yeni';
        if (strpos($d, 'hazırla') !== false || strpos($d, 'preparing') !== false
            || strpos($d, 'onaylandı') !== false)                      return 'hazirlaniyor';
        if (strpos($d, 'kargo') !== false || strpos($d, 'shipped') !== false) return 'kargoda';
        if (strpos($d, 'teslim') !== false || strpos($d, 'delivered') !== false) return 'teslim';
        if (strpos($d, 'iptal') !== false || strpos($d, 'cancel') !== false) return 'iptal';
        if (strpos($d, 'iade') !== false || strpos($d, 'return') !== false) return 'iade';

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
