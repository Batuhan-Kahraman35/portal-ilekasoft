<?php
/**
 * Hepsiburada Marketplace API
 *
 * Ortak altyapı PazaryeriApi sınıfındadır; burada yalnızca Hepsiburada'ya
 * özgü uç yolları, yanıt ayrıştırma ve alan eşlemesi bulunur.
 *
 * ÜÇ AYRI SUNUCU kullanır — sözleşmedeki hizmet bazlı adres çözümlemesinin
 * varlık sebebi budur:
 *   katalog : ürün bilgisi, kategori, öznitelik   (mpop)
 *   listing : fiyat, stok, kargo, termin          (listing-external)
 *   siparis : paketler, iadeler                   (oms-external)
 *
 * Portal kapsamı: bağlantı testi (katalog), sipariş, iade ve fatura linki
 * bildirimi (siparis). Ürün/kategori/fiyat-stok işlemleri bu sürümde yoktur.
 *
 * Kimlik doğrulama: Basic base64(MerchantId:SecretKey)
 * User-Agent başlığı ZORUNLUDUR (developer username).
 *
 * Test (SIT) ortamına geçmek için entegrasyon ayarlarındaki adreslerin
 * sonuna "-sit" eklenir; kanal ayarlarında Ortam alanı bu amaçla tutulur.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class HepsiburadaApi extends PazaryeriApi
{
    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('hepsiburada', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    /**
     * Hizmete göre sunucu adresi.
     * @param string $hizmet katalog | listing | siparis
     */
    protected function baseUrl($hizmet = 'varsayilan')
    {
        $adresler = [
            'katalog' => $this->getConfig('BaseURL_Katalog', 'https://mpop.hepsiburada.com'),
            'listing' => $this->getConfig('BaseURL_Listing', 'https://listing-external.hepsiburada.com'),
            'siparis' => $this->getConfig('BaseURL_Siparis', 'https://oms-external.hepsiburada.com'),
        ];

        $adres = $adresler[$hizmet] ?? $adresler['katalog'];

        return rtrim($adres, '/');
    }

    protected function authHeaders()
    {
        $merchantId = trim((string)$this->getConfig('MerchantId', ''));
        $secret     = trim((string)$this->getConfig('SecretKey', ''));
        $userAgent  = trim((string)$this->getConfig('UserAgent', '')) ?: $merchantId;

        return [
            'Authorization: Basic ' . base64_encode($merchantId . ':' . $secret),
            'User-Agent: ' . $userAgent
        ];
    }

    protected function configDogrula()
    {
        if (empty($this->config['MerchantId'])) {
            return 'Hepsiburada kanal ayarlarında MerchantId tanımlı değil.';
        }
        if (empty($this->config['SecretKey'])) {
            return 'Hepsiburada kanal ayarlarında SecretKey tanımlı değil.';
        }
        if (empty($this->config['UserAgent'])) {
            return 'Hepsiburada User-Agent (developer username) tanımlı değil; '
                 . 'bu başlık olmadan istekler reddedilir.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'MerchantId', 'etiket' => 'Mağaza ID (Merchant ID)',
             'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'GUID biçiminde; Basic auth kullanıcı adı olarak da kullanılır'],
            ['anahtar' => 'SecretKey', 'etiket' => 'Servis Anahtarı (Secret Key)',
             'gizli' => true, 'zorunlu' => true, 'aciklama' => ''],
            ['anahtar' => 'UserAgent', 'etiket' => 'User-Agent (Developer Username)',
             'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Bu başlık gönderilmezse istekler reddedilir'],
        ];
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if ($httpKod === 401 || $httpKod === 403) {
            return 'HTTP ' . $httpKod . ': Yetkisiz erişim. MerchantId, SecretKey ve '
                 . 'User-Agent bilgilerini kontrol edin.';
        }
        if ($httpKod === 429) {
            return 'HTTP 429: İstek sınırı aşıldı (dakikada ~240 istek). '
                 . 'Bir süre bekleyip tekrar deneyin.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    private function merchantId()
    {
        return rawurlencode((string)$this->getConfig('MerchantId', ''));
    }

    /** Hepsiburada yanıtları çoğunlukla {success, code, data} sarmalayıcısı kullanır */
    private function govde($veri, $anahtar = 'data')
    {
        if (!is_array($veri)) {
            return [];
        }
        if (isset($veri[$anahtar]) && is_array($veri[$anahtar])) {
            return $veri[$anahtar];
        }
        // Bazı uçlar (paketler, iadeler) doğrudan dizi döner
        if (isset($veri[0])) {
            return $veri;
        }
        return [];
    }

    /** Parasal alanlar {currency, amount} nesnesi olarak gelir */
    private function tutar($alan)
    {
        if (is_array($alan)) {
            return isset($alan['amount']) ? (float)$alan['amount'] : null;
        }
        return $alan !== null ? (float)$alan : null;
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->istek('GET',
            '/product/api/products/all-products-of-merchant/' . $this->merchantId(),
            null, ['limit' => 1, 'offset' => 0, 'version' => 1],
            'Bağlantı Testi', 'katalog');

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
    // Siparişler
    // =================================================================

    // =================================================================
    // Siparişler
    //
    // /packages yalnız kargoya hazır paketleri döner; kargodaki, teslim edilen ve
    // iptal edilen siparişler AYRI uçlardadır ve yalnız sipariş/paket numarası verir.
    // Tüm bilgi /orders/merchantid/{id}/ordernumber/{no} detay ucundadır.
    // Birim SİPARİŞtir (disId = orderNumber); paket numaraları PaketNo'da virgülle tutulur.
    // (Örnek Müşteri B sürümünden, canlı doğrulanmış akış.)
    // =================================================================

    /** Liste sarmalayıcısı: uçlara göre items / data / doğrudan dizi */
    private function liste($veri)
    {
        if (!is_array($veri)) {
            return [];
        }
        foreach (['items', 'data', 'listings'] as $anahtar) {
            if (array_key_exists($anahtar, $veri)) {
                return is_array($veri[$anahtar]) ? $veri[$anahtar] : [];
            }
        }
        return array_is_list($veri) ? $veri : [];
    }

    /**
     * Sipariş numarası kaynakları. Her biri yalnız o durumdaki kayıtları döner.
     * 'tarih' = begindate/enddate kabul eder; 'son' = son durum (DB'de aynı durumdaysa detay çekilmez).
     */
    private function siparisKaynaklari()
    {
        $m = $this->merchantId();
        return [
            ['yol' => "/orders/merchantid/$m",               'islem' => 'Açık Siparişler',    'tarih' => false, 'son' => null],
            ['yol' => "/packages/merchantid/$m",             'islem' => 'Paketler',           'tarih' => false, 'son' => null],
            ['yol' => "/packages/merchantid/$m/shipped",     'islem' => 'Kargodaki Paketler', 'tarih' => true,  'son' => null],
            ['yol' => "/packages/merchantid/$m/delivered",   'islem' => 'Teslim Edilenler',   'tarih' => true,  'son' => 'teslim'],
            ['yol' => "/packages/merchantid/$m/undelivered", 'islem' => 'Teslim Edilemeyen',  'tarih' => true,  'son' => null],
            ['yol' => "/orders/merchantid/$m/cancelled",     'islem' => 'İptal Siparişler',   'tarih' => true,  'son' => 'iptal'],
        ];
    }

    /** Liste kaydından sipariş numaraları (uçlara göre alan adı ve büyük/küçük harf değişir) */
    private function siparisNumaralari(array $kayit)
    {
        $no = [];
        foreach (['orderNumber', 'OrderNumber'] as $a) {
            if (!empty($kayit[$a]) && is_scalar($kayit[$a])) { $no[] = (string)$kayit[$a]; }
        }
        foreach (['orderNumbers', 'OrderNumbers'] as $a) {
            foreach ((array)($kayit[$a] ?? []) as $n) { $no[] = (string)$n; }
        }
        foreach ($kayit['items'] ?? ($kayit['Items'] ?? []) as $it) {
            if (!empty($it['orderNumber'])) { $no[] = (string)$it['orderNumber']; }
        }
        return array_unique(array_filter($no));
    }

    /**
     * Durum listelerinden sipariş numaralarını toplar.
     * @return array ['basarili'=>bool,'hata'=>string,'siparisler'=>[no => sonDurum|null]]
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $gun = (int)($filtre['gun'] ?? 0);
        $tarih = $gun > 0 ? [
            'begindate' => date('Y-m-d H:i', time() - $gun * 86400),
            'enddate'   => date('Y-m-d H:i')
        ] : [];

        $numaralar = [];
        foreach ($this->siparisKaynaklari() as $k) {
            $sayfa = 0;
            do {
                $query = ['offset' => $sayfa * 50, 'limit' => 50];
                if ($k['tarih']) { $query += $tarih; }

                $r = $this->istek('GET', $k['yol'], null, $query, $k['islem'], 'siparis');
                if (!$r['basarili']) {
                    return ['basarili' => false, 'hata' => $k['islem'] . ': ' . $r['hata'], 'siparisler' => []];
                }

                $kayitlar = $this->liste($r['veri']);
                foreach ($kayitlar as $kayit) {
                    foreach ($this->siparisNumaralari($kayit) as $no) {
                        // Aynı sipariş birden fazla listede çıkarsa "son durum" işareti geçersiz
                        $numaralar[$no] = array_key_exists($no, $numaralar) ? null : $k['son'];
                    }
                }

                $toplam = isset($r['veri']['totalCount']) ? (int)$r['veri']['totalCount'] : null;
                $sayfa++;
                $devam = $toplam !== null ? $sayfa * 50 < $toplam : count($kayitlar) >= 50;
            } while ($devam && $sayfa < 40);
        }

        return ['basarili' => true, 'hata' => '', 'siparisler' => $numaralar];
    }

    /** Tek siparişin tam detayı */
    public function siparisDetay($siparisNo)
    {
        $r = $this->istek('GET', '/orders/merchantid/' . $this->merchantId() . '/ordernumber/' . rawurlencode((string)$siparisNo),
                          null, [], 'Sipariş Detay', 'siparis');
        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'siparis' => null];
        }
        if (empty($r['veri']['orderNumber'])) {
            return ['basarili' => false, 'hata' => 'Sipariş bulunamadı: ' . $siparisNo, 'siparis' => null];
        }
        return ['basarili' => true, 'hata' => '', 'siparis' => $r['veri']];
    }

    /**
     * Siparişleri çek ve yaz. Son durumda olup DB'de aynı durumda kayıtlı siparişlerin
     * detayı yeniden çekilmez. Liste penceresi (~1 ay) dışında kalanlar eskiDurumlariKontrol ile.
     */
    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $liste = $this->siparisleriListele(['gun' => (int)$gunSayisi]);
        if (!$liste['basarili']) {
            return ['basarili' => false, 'hata' => $liste['hata'], 'adet' => 0];
        }

        $adet = 0; $yeni = 0; $hatali = 0; $atlanan = 0;
        $hatalar = [];

        foreach ($liste['siparisler'] as $no => $sonDurum) {
            $mevcut = $this->db->fetchOne("
                SELECT PazaryeriSiparisleri_DurumKod AS durum FROM dbo.PazaryeriSiparisleri
                WHERE PazaryeriSiparisleri_EntegrasyonKanallari_id = ? AND PazaryeriSiparisleri_DisId = ?
            ", [$this->kanalId, (string)$no]);

            if ($mevcut && $sonDurum !== null && $mevcut['durum'] === $sonDurum) {
                $atlanan++;
                continue;
            }

            $sonuc = $this->siparisNoIleKaydet($no);
            if ($sonuc['basarili']) {
                $adet++;
                if (!$mevcut) { $yeni++; }
            } else {
                $hatali++;
                $hatalar[] = $no . ': ' . $sonuc['hata'];
            }
        }

        if ($hatali > 0 && $adet === 0 && $atlanan === 0) {
            return ['basarili' => false, 'hata' => implode(' | ', array_slice($hatalar, 0, 3)), 'adet' => 0];
        }

        $eski = $this->eskiDurumlariKontrol();

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni,
                'hatali' => $hatali, 'atlanan' => $atlanan,
                'durumGuncellenen' => $eski['adet'], 'durumHata' => $eski['hata']];
    }

    /** Sipariş numarasıyla detayı çekip kaydeder (geçmiş siparişleri almak için de kullanılır) */
    public function siparisNoIleKaydet($siparisNo)
    {
        $d = $this->siparisDetay($siparisNo);
        if (!$d['basarili']) {
            return $d;
        }
        $id = $this->siparisKaydet($this->siparisNotrle($d['siparis']));
        return $id
            ? ['basarili' => true, 'hata' => '', 'id' => $id]
            : ['basarili' => false, 'hata' => 'Kayıt yazılamadı.'];
    }

    /**
     * Durum listelerinin penceresi (~1 ay) dışında kalıp hâlâ son durumda olmayan siparişler
     * detay ucundan tek tek yenilenir. İstek sınırı için çalışma başına en fazla $limit sipariş;
     * en uzun süredir dokunulmayanlar önce.
     */
    private function eskiDurumlariKontrol($gun = 30, $limit = 20)
    {
        $adaylar = $this->db->fetchAll("
            SELECT TOP (" . (int)$limit . ") PazaryeriSiparisleri_SiparisNo
            FROM dbo.PazaryeriSiparisleri
            WHERE PazaryeriSiparisleri_EntegrasyonKanallari_id = ?
              AND ISNULL(PazaryeriSiparisleri_DurumKod, '') NOT IN ('teslim', 'iptal', 'iade')
              AND PazaryeriSiparisleri_SiparisTarihi < DATEADD(DAY, -?, GETDATE())
              AND ISNULL(PazaryeriSiparisleri_SiparisNo, '') <> ''
            ORDER BY ISNULL(PazaryeriSiparisleri_GuncellemeTarihi, PazaryeriSiparisleri_OlusturmaTarihi) ASC
        ", [$this->kanalId, (int)$gun]) ?: [];

        $adet = 0;
        $hata = '';
        foreach ($adaylar as $a) {
            $r = $this->siparisNoIleKaydet($a['PazaryeriSiparisleri_SiparisNo']);
            if (!$r['basarili']) {
                $hata = 'Sipariş detay: ' . $r['hata'];
                break;
            }
            $adet++;
        }

        return ['adet' => $adet, 'hata' => $hata];
    }

    /** Hepsiburada sipariş detayı → nötr sipariş */
    private function siparisNotrle(array $s)
    {
        $kalemler = [];
        $paketler = [];
        $durumlar = [];
        $toplam   = 0.0;
        $desi     = 0.0;
        $ilk      = null;

        foreach ($s['items'] ?? [] as $it) {
            $ilk = $ilk ?? $it;
            $tutar  = $this->tutar($it['totalPrice'] ?? null);
            $toplam += (float)$tutar;
            $desi   += (float)($it['totalDeci'] ?? 0);
            if (!empty($it['packageNumber'])) { $paketler[] = (string)$it['packageNumber']; }
            $durumlar[] = (string)($it['status'] ?? '');

            $indirim = (float)$this->tutar($it['hbDiscount']['totalPrice'] ?? null)
                     + (float)$this->tutar($it['merchantDiscount']['totalPrice'] ?? null);

            $kalemler[] = [
                'disId'         => (string)($it['id'] ?? ''),
                'barkod'        => $it['productBarcode'] ?? null,
                'stokKodu'      => $it['merchantSKU'] ?? ($it['merchantSku'] ?? null),
                'disUrunId'     => $it['sku'] ?? null,
                'urunAdi'       => $it['name'] ?? null,
                'miktar'        => (int)($it['quantity'] ?? 1),
                'birimFiyat'    => $this->tutar($it['unitPrice'] ?? null),
                'toplamTutar'   => $tutar,
                'indirimTutar'  => $indirim ?: null,
                'kdvOran'       => isset($it['vatRate']) ? (float)$it['vatRate'] : null,
                'kdvTutar'      => isset($it['vat']) ? (float)$it['vat'] : null,
                'komisyonOran'  => isset($it['commissionRate']) ? (float)$it['commissionRate'] : null,
                'komisyonTutar' => $this->tutar($it['commission'] ?? null),
                'desi'          => isset($it['totalDeci']) ? (float)$it['totalDeci'] : null,
                'hamDurum'      => $it['status'] ?? null,
                'ham'           => $it
            ];
        }

        $teslimat = $s['deliveryAddress'] ?? ($ilk['shippingAddress'] ?? []);
        $fatura   = $s['invoice'] ?? ($ilk['invoice'] ?? []);
        $fAdres   = $fatura['address'] ?? [];
        $hamDurum = $this->baskinDurum($durumlar);

        return [
            'disId'          => (string)($s['orderNumber'] ?? ''),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => implode(',', array_unique($paketler)) ?: null,
            'durumKod'       => $this->siparisDurumKodu($hamDurum),
            'hamDurum'       => $hamDurum ?: null,
            'toplamTutar'    => round($toplam, 2),
            'paraBirimi'     => $ilk['totalPrice']['currency'] ?? 'TRY',
            'musteriAdi'     => $s['customer']['name'] ?? ($ilk['customerName'] ?? null),
            'aliciAdi'       => $teslimat['name'] ?? null,
            'eposta'         => $teslimat['email'] ?? ($fAdres['email'] ?? null),
            'telefon'        => $teslimat['phoneNumber'] ?? null,
            'tckn'           => ($fatura['turkishIdentityNumber'] ?? null) ?: null,
            'vergiNo'        => ($fatura['taxNumber'] ?? null) ?: null,
            'vergiDairesi'   => ($fatura['taxOffice'] ?? null) ?: null,
            'il'             => $teslimat['city']     ?? null,
            'ilce'           => $teslimat['town']     ?? null,
            'semt'           => $teslimat['district'] ?? null,
            'teslimatAdresi' => $teslimat,
            'faturaAdresi'   => $fAdres + ['unvan' => $fAdres['name'] ?? null],
            'kargoFirmasi'   => $ilk['cargoCompany'] ?? null,
            'kargoTakipNo'   => ($ilk['barcode'] ?? null) ?: null,
            'kargoBarkod'    => ($ilk['barcode'] ?? null) ?: null,
            'desi'           => $desi ?: null,
            'siparisTarihi'  => $this->isoTarih($s['orderDate'] ?? null),
            'terminTarihi'   => $this->isoTarih($ilk['dueDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /**
     * Hepsiburada durum adı → yerel kod. Liste ve detay uçları farklı adlar kullanır
     * (Cancelled / CancelledByMerchant, UnDelivered ...); parça eşleşmesiyle çözülür.
     */
    private function siparisDurumKodu($durum)
    {
        $d = strtolower((string)$durum);
        if ($d === '')                                                              return 'diger';
        if (strpos($d, 'cancel') !== false)                                         return 'iptal';
        if (strpos($d, 'claim') !== false || strpos($d, 'return') !== false)        return 'iade';
        if (strpos($d, 'undeliver') !== false)                                      return 'diger';
        if (strpos($d, 'deliver') !== false)                                        return 'teslim';
        if (strpos($d, 'ship') !== false || strpos($d, 'transit') !== false)        return 'kargoda';
        if (strpos($d, 'pack') !== false || strpos($d, 'ready') !== false
            || strpos($d, 'picking') !== false)                                     return 'hazirlaniyor';
        if (strpos($d, 'open') !== false || strpos($d, 'new') !== false
            || strpos($d, 'created') !== false || strpos($d, 'approved') !== false) return 'yeni';
        return 'diger';
    }

    /**
     * Kalem durumlarından siparişin durumu: iptal olmayan kalemlerin en GERİDEKİ aşaması
     * (bir kalem hâlâ açıksa sipariş açık sayılır). Tüm kalemler iptalse iptal. Örnek Müşteri B ile aynı.
     */
    private function baskinDurum(array $durumlar)
    {
        $durumlar = array_values(array_filter($durumlar));
        if (!$durumlar) {
            return '';
        }
        $aktif = array_values(array_filter($durumlar, fn($d) => stripos($d, 'cancel') === false));
        if (!$aktif) {
            return $durumlar[0];
        }
        $sira = function ($d) {
            $d = strtolower($d);
            if (strpos($d, 'claim') !== false || strpos($d, 'return') !== false) return 5;
            if (strpos($d, 'undeliver') !== false)                               return 3;
            if (strpos($d, 'deliver') !== false)                                 return 4;
            if (strpos($d, 'ship') !== false || strpos($d, 'transit') !== false) return 3;
            if (strpos($d, 'pack') !== false || strpos($d, 'ready') !== false)   return 2;
            return 1;
        };
        usort($aktif, fn($a, $b) => $sira($a) <=> $sira($b));
        return $aktif[0];
    }

    /**
     * Fatura linkini pakete bağla.
     * PUT /packages/merchantid/{mid}/packagenumber/{paketNo}/invoice  gövde {invoiceLink}
     * Kaynak topluluk SDK'sı (mustafa-m-ugur/hepsiburada-api-php); resmi dokümanla
     * doğrulanmadı — ilk canlı gönderimde yanıt kontrol edilmeli.
     */
    public function faturaLinkGonder($paketId, array $fatura)
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket numarası boş.'];
        }

        $sonuc = $this->istek('PUT',
            '/packages/merchantid/' . $this->merchantId() . '/packagenumber/' . rawurlencode((string)$paketId) . '/invoice',
            ['invoiceLink' => $fatura['link'] ?? ''],
            [], 'Fatura Linki Gönder', 'siparis');

        return ['basarili' => $sonuc['basarili'], 'hata' => $sonuc['hata']];
    }

    // =================================================================
    // İadeler
    // =================================================================

    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $query = array_merge($filtre, [
            'offset' => (int)$sayfa * (int)$boyut,
            'limit'  => (int)$boyut
        ]);

        $sonuc = $this->istek('GET', '/claims/merchantid/' . $this->merchantId(),
                              null, $query, 'İade Listele', 'siparis');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'iadeler' => [], 'toplam' => 0];
        }

        $liste = $this->govde($sonuc['veri']);

        return [
            'basarili'    => true,
            'hata'        => '',
            'iadeler'     => $liste,
            'toplam'      => (int)($sonuc['veri']['totalCount'] ?? count($liste)),
            'toplamSayfa' => count($liste) < (int)$boyut ? ($sayfa + 1) : ($sayfa + 2)
        ];
    }

    public function iadeleriSenkronla($gunSayisi = 30)
    {
        $toplam = 0;
        $sayfa  = 0;
        $boyut  = 50;

        do {
            $sonuc = $this->iadeleriListele([], $sayfa, $boyut);
            if (!$sonuc['basarili']) {
                return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
            }

            foreach ($sonuc['iadeler'] as $i) {
                if ($this->iadeKaydet($this->iadeNotrle($i))) {
                    $toplam++;
                }
            }

            $gelen = count($sonuc['iadeler']);
            $sayfa++;
        } while ($gelen >= $boyut && $sayfa < 20);

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /**
     * Hepsiburada iadesi → nötr iade.
     * NOT: Alan adları canlı veriyle doğrulanmamıştır (mağazada iade yoktu);
     * birden fazla olası ad denenerek yazılmıştır.
     */
    private function iadeNotrle(array $i)
    {
        $kalemler = [];
        foreach ($i['items'] ?? ($i['claimItems'] ?? []) as $it) {
            $kalemler[] = [
                'barkod'  => $it['productBarcode'] ?? ($it['barcode'] ?? null),
                'urunAdi' => $it['productName']    ?? null,
                'miktar'  => (int)($it['quantity'] ?? 1),
                'fiyat'   => $this->tutar($it['price'] ?? null),
                'sebep'   => $it['claimReason'] ?? ($it['reason'] ?? null)
            ];
        }

        return [
            'disId'      => (string)($i['id'] ?? ($i['claimId'] ?? '')),
            'siparisNo'  => (string)($i['orderNumber'] ?? ''),
            'paketNo'    => (string)($i['packageNumber'] ?? ''),
            'durumKod'   => $this->iadeDurumKodu($i['status'] ?? ''),
            'hamDurum'   => $i['status'] ?? null,
            'sebep'      => $i['claimReason'] ?? ($i['reason'] ?? null),
            'aciklama'   => $i['customerNote'] ?? ($i['description'] ?? null),
            'musteriAdi' => $i['customerName'] ?? null,
            'tutar'      => $this->tutar($i['totalPrice'] ?? ($i['price'] ?? null)),
            'iadeTarihi' => $this->isoTarih($i['createdDate'] ?? ($i['claimDate'] ?? null)),
            'kalemler'   => $kalemler,
            'ham'        => $i
        ];
    }

    private function iadeDurumKodu($durum)
    {
        $d = strtolower((string)$durum);
        if (strpos($d, 'open') !== false || strpos($d, 'waiting') !== false
            || strpos($d, 'created') !== false)                  return 'acik';
        if (strpos($d, 'approve') !== false
            || strpos($d, 'accept') !== false)                   return 'onaylandi';
        if (strpos($d, 'reject') !== false)                      return 'reddedildi';
        if (strpos($d, 'cancel') !== false)                      return 'iptal';
        return 'diger';
    }

    // =================================================================
    // Desteklenmeyenler
    //
    // Paket durumu ve kargo etiketi bu sınıfta yoktur; tanim_Pazaryerleri
    // yetenek JSON'unda kapalıdır, ortak sınıf çağrıldığında açıklayıcı
    // hata döndürür.
    // =================================================================
}
