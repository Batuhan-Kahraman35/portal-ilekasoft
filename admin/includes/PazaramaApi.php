<?php
/**
 * Pazarama İş Ortağım API
 *
 * Ortak altyapı PazaryeriApi sınıfındadır; burada yalnızca Pazarama'ya
 * özgü kimlik doğrulama, uç yolları ve alan eşlemesi bulunur.
 *
 * Kimlik doğrulama OAuth2 client_credentials akışıdır:
 *   POST https://isortagimgiris.pazarama.com/connect/token
 *   Authorization: Basic base64(ApiKey:ApiSecret)
 *   grant_type=client_credentials&scope=merchantgatewayapi.fullaccess
 *   Yanıt: {"data":{"accessToken":..,"expiresIn":3600}, "success":true}
 * Jeton yalnız bellekte tutulur (DB'ye yazılmaz); süresi 1 saattir, cron
 * her çalışmada yeni jeton alır.
 *
 * Yanıt zarfı tüm uçlarda aynıdır:
 *   {"pageIndex","pageSize","totalPages","totalCount","data":[..],
 *    "success":bool,"messageCode","message","userMessage"}
 * HTTP 200 dönse bile success=false olabilir; hata bu yüzden zarftan okunur.
 *
 * Sipariş sorgusu POST'tur (/order/getOrdersForApi), sayfa 1'den başlar.
 * Gövde: pageNumber, pageSize, startDate/endDate ("Y-m-d" veya "Y-m-d\TH:i:s"),
 * aralık en fazla 30 gün. Uç ve gövde 23.10.2026'da canlı doğrulandı; mağazada sipariş olmadığı için
 * sipariş ALANLARI canlı veriyle henüz doğrulanmadı. Eşleme alternatif adlara
 * toleranslıdır, ham veri her durumda HamVeri'ye yazılır.
 *
 * Portal kapsamı: bağlantı testi ve sipariş senkronu.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class PazaramaApi extends PazaryeriApi
{
    /** Sipariş sorgusunda kabul edilen azami gün aralığı (aşılınca MER-4, HTTP 200 + success=false) */
    const SIPARIS_GUN_SINIRI = 30;

    /** Jetonun süresinden önce yenilenme payı (sn) */
    const TOKEN_PAYI = 60;

    private $tokenBitis = 0;
    private $tokenHata  = '';

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('pazarama', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    protected function baseUrl($hizmet = 'varsayilan')
    {
        if ($hizmet === 'giris') {
            return rtrim($this->getConfig('AuthURL', 'https://isortagimgiris.pazarama.com'), '/');
        }
        return rtrim($this->getConfig('BaseURL', 'https://isortagimapi.pazarama.com'), '/');
    }

    protected function authHeaders()
    {
        return [
            'Authorization: Bearer ' . (string)($this->config['AccessToken'] ?? ''),
            'User-Agent: ' . $this->getConfig('UserAgent', 'Pazaryeri-Entegrasyon/1.0')
        ];
    }

    protected function configDogrula()
    {
        if (empty($this->config['ApiKey']) || empty($this->config['ApiSecret'])) {
            return 'Pazarama kanal ayarlarında ApiKey veya ApiSecret tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'ApiKey', 'etiket' => 'API Key',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'Satıcı paneli > Hesabım > Hesap Bilgileri > Entegrasyon Bilgileri'],
            ['anahtar' => 'ApiSecret', 'etiket' => 'API Secret',
             'gizli' => true, 'zorunlu' => true, 'aciklama' => ''],
        ];
    }

    // =================================================================
    // Jeton
    // =================================================================

    protected function tokenGecerliMi()
    {
        return !empty($this->config['AccessToken']) && time() < $this->tokenBitis - self::TOKEN_PAYI;
    }

    /**
     * Jeton ortak istek() üzerinden alınamaz: o metot Bearer başlığı ve JSON
     * gövde gönderir, giriş ucu ise Basic başlık ve form gövdesi ister.
     */
    protected function tokenYenile()
    {
        $url = $this->baseUrl('giris') . '/connect/token';
        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'client_credentials',
                'scope'      => 'merchantgatewayapi.fullaccess'
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode(trim((string)$this->config['ApiKey']) . ':' . trim((string)$this->config['ApiSecret'])),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $yanit    = curl_exec($ch);
        $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        curl_close($ch);
        $sureMs = (int)round((microtime(true) - $start) * 1000);

        $veri  = json_decode((string)$yanit, true);
        $token = $veri['data']['accessToken'] ?? null;
        $sure  = (int)($veri['data']['expiresIn'] ?? 3600);

        $hata = '';
        if ($yanit === false || $curlHata !== '') {
            $hata = 'Bağlantı hatası: ' . $curlHata;
        } elseif (!$token) {
            $hata = $this->hataMesajiCoz($httpKod, $veri, $yanit);
        }

        // Yanıtta jeton olduğu için log'a gövde yazılmaz
        $this->logla('Jeton Al', 'POST', $url, null, '', $httpKod, $sureMs, $hata);

        // $this->hata'ya yazılmaz: nesne kalıcı olarak "hazır değil" durumuna düşer
        if ($hata !== '') {
            $this->tokenHata = $hata;
            return false;
        }

        $this->config['AccessToken'] = $token;
        $this->tokenBitis = time() + $sure;
        return true;
    }

    /**
     * İstek sonrası Pazarama zarfını denetle: HTTP 200 + success=false
     * durumunu hataya çevirir.
     */
    private function pazaramaIstek($method, $path, $body = null, array $query = [], $islem = '')
    {
        $this->tokenHata = '';
        $sonuc = $this->istek($method, $path, $body, $query, $islem);

        // Ortak katman jeton hatasını genel metne çevirir; asıl sebep gösterilsin
        if (!$sonuc['basarili'] && $this->tokenHata !== '') {
            $sonuc['hata'] = 'Jeton alınamadı: ' . $this->tokenHata;
            return $sonuc;
        }

        if ($sonuc['basarili'] && is_array($sonuc['veri'])
            && array_key_exists('success', $sonuc['veri']) && !$sonuc['veri']['success']) {
            $sonuc['basarili'] = false;
            $sonuc['hata'] = $this->hataMesajiCoz($sonuc['httpKod'], $sonuc['veri'], '');
        }
        return $sonuc;
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if (is_array($veri)) {
            foreach (['userMessage', 'message', 'error_description', 'error'] as $anahtar) {
                if (!empty($veri[$anahtar]) && is_string($veri[$anahtar])) {
                    $kod = !empty($veri['messageCode']) ? ' [' . $veri['messageCode'] . ']' : '';
                    return 'HTTP ' . $httpKod . $kod . ': ' . $veri[$anahtar];
                }
            }
        }
        if ($httpKod === 401) {
            return 'HTTP 401: Jeton geçersiz. ApiKey / ApiSecret değerlerini kontrol edin.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->pazaramaIstek('GET', '/product/products', null,
                                      ['Approved' => 'true', 'Page' => 1, 'Size' => 1], 'Bağlantı Testi');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        $toplam = (int)($sonuc['veri']['totalCount'] ?? 0);
        return ['basarili' => true,
                'mesaj'    => 'Bağlantı başarılı. Mağazadaki onaylı ürün sayısı: ' . $toplam,
                'veri'     => $sonuc['veri']];
    }

    // =================================================================
    // Siparişler
    // =================================================================

    /**
     * Siparişleri listele. Sayfa 1 TABANLIDIR.
     * Yanıttaki her kayıt bir siparişdir; kalemler items[] altında gelir.
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 1, $boyut = 50)
    {
        // Page/Size adları sessizce yok sayılır (yanıtta pageSize=0 döner);
        // tarih "Y-m-d H:i:s" biçiminde MER_30 hatası verir, ISO "T" ayraçlı kabul edilir.
        $govde = [
            'pageNumber' => max(1, (int)$sayfa),
            'pageSize'   => max(1, min(100, (int)$boyut))
        ];

        if (!empty($filtre['startDate'])) {
            $govde['startDate'] = date('Y-m-d\TH:i:s', (int)$filtre['startDate']);
        }
        if (!empty($filtre['endDate'])) {
            $govde['endDate'] = date('Y-m-d\TH:i:s', (int)$filtre['endDate']);
        }
        if (!empty($filtre['orderNumber'])) {
            $govde['orderNumber'] = (string)$filtre['orderNumber'];
        }

        $sonuc = $this->pazaramaIstek('POST', '/order/getOrdersForApi', $govde, [], 'Sipariş Listele');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'],
                    'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        $veri  = $sonuc['veri'] ?? [];
        $liste = $veri['data'] ?? [];

        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => is_array($liste) ? $liste : [],
            'toplam'      => (int)($veri['totalCount'] ?? 0),
            'toplamSayfa' => (int)($veri['totalPages'] ?? 0)
        ];
    }

    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $sayfa = 1;
            do {
                $sonuc = $this->siparisleriListele([
                    'startDate' => $dilim['bas'],
                    'endDate'   => $dilim['bit']
                ], $sayfa, 100);

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
                }

                foreach ($sonuc['siparisler'] as $s) {
                    if ($this->siparisKaydet($this->siparisNotrle($s))) {
                        $toplam++;
                    }
                }

                $sayfa++;
            } while ($sayfa <= (int)$sonuc['toplamSayfa'] && $sayfa <= 50);
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /**
     * Pazarama siparişi → nötr sipariş.
     * Alan adları canlı veriyle doğrulanmadı; ilk siparişte HamVeri ile
     * karşılaştırılıp gerekirse düzeltilecek.
     */
    private function siparisNotrle(array $s)
    {
        $teslim = is_array($s['shipmentAddress'] ?? null) ? $s['shipmentAddress'] : [];
        $fatura = is_array($s['billingAddress']  ?? null) ? $s['billingAddress']  : [];

        $kalemler = [];
        $durumlar = [];
        $kargo    = [];
        foreach (($s['items'] ?? []) as $it) {
            $urun = is_array($it['product'] ?? null) ? $it['product'] : [];
            if (empty($kargo) && is_array($it['cargo'] ?? null)) {
                $kargo = $it['cargo'];
            }
            $hamDurum = $it['orderItemStatusName'] ?? ($it['orderItemStatus'] ?? null);
            $durumlar[] = $this->siparisDurumKodu($hamDurum);

            $kalemler[] = [
                'disId'        => (string)($it['orderItemId'] ?? ''),
                'barkod'       => $urun['code']      ?? ($it['productCode'] ?? null),
                'stokKodu'     => $urun['stockCode'] ?? null,
                'disUrunId'    => isset($urun['productId']) ? (string)$urun['productId'] : null,
                'urunAdi'      => $urun['name']      ?? ($it['productName'] ?? null),
                'miktar'       => (int)($it['quantity'] ?? 1),
                'birimFiyat'   => $this->tutar($it['salePrice']  ?? null),
                'toplamTutar'  => $this->tutar($it['totalPrice'] ?? null),
                'indirimTutar' => $this->tutar($it['discountAmount'] ?? null),
                'kdvOran'      => isset($urun['vatRate']) ? (float)$urun['vatRate'] : null,
                'kdvTutar'     => $this->tutar($it['taxAmount'] ?? null),
                'hamDurum'     => is_scalar($hamDurum) ? (string)$hamDurum : null,
                'ham'          => $it
            ];
        }

        // Fatura tarafında 11 hane → TCKN (bireysel), 10 hane → VKN (kurumsal)
        $vergiNo = preg_replace('/\D/', '', (string)($fatura['taxNumber'] ?? ($fatura['identityNumber'] ?? '')));

        $aktif = array_values(array_filter($durumlar, fn($d) => $d !== 'iptal'));
        $sira  = ['yeni' => 1, 'hazirlaniyor' => 2, 'kargoda' => 3, 'teslim' => 4, 'iade' => 5, 'diger' => 6];
        usort($aktif, fn($a, $b) => $sira[$a] <=> $sira[$b]);
        $durumKod = $durumlar ? ($aktif[0] ?? 'iptal') : $this->siparisDurumKodu($s['orderStatus'] ?? '');

        return [
            'disId'          => (string)($s['orderId'] ?? ($s['orderNumber'] ?? '')),
            'siparisNo'      => isset($s['orderNumber']) ? (string)$s['orderNumber'] : null,
            'durumKod'       => $durumKod,
            'hamDurum'       => isset($s['orderStatus']) && is_scalar($s['orderStatus']) ? (string)$s['orderStatus'] : null,
            'toplamTutar'    => $this->tutar($s['orderAmount'] ?? null),
            'paraBirimi'     => is_array($s['orderAmount'] ?? null) ? ($s['orderAmount']['currency'] ?? 'TRY') : 'TRY',
            'musteriAdi'     => ($fatura['companyName'] ?? '') !== '' ? $fatura['companyName'] : ($s['customerName'] ?? null),
            'aliciAdi'       => $teslim['nameSurname'] ?? null,
            'eposta'         => $s['customerEmail'] ?? null,
            'telefon'        => $teslim['phoneNumber'] ?? null,
            'tckn'           => strlen($vergiNo) === 11 ? $vergiNo : null,
            'vergiNo'        => strlen($vergiNo) === 10 ? $vergiNo : null,
            'vergiDairesi'   => $fatura['taxOffice'] ?? null,
            'il'             => $teslim['cityName']         ?? null,
            'ilce'           => $teslim['districtName']     ?? null,
            'semt'           => $teslim['neighborhoodName'] ?? null,
            'teslimatAdresi' => $teslim ?: null,
            'faturaAdresi'   => $fatura ?: null,
            'kargoFirmasi'   => ($kargo['companyName']    ?? '') !== '' ? $kargo['companyName']    : null,
            'kargoTakipNo'   => ($kargo['trackingNumber'] ?? '') !== '' ? $kargo['trackingNumber'] : null,
            'siparisTarihi'  => $this->isoTarih($s['orderDate'] ?? ''),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /** Tutar alanı düz sayı veya {"value":..,"currency":..} nesnesi olarak gelebilir */
    private function tutar($deger)
    {
        if (is_array($deger)) {
            $deger = $deger['value'] ?? null;
        }
        return is_numeric($deger) ? (float)$deger : null;
    }

    /** Durum metni → kod. Sayısal kodlar canlı veri gelince haritalanacak. */
    private function siparisDurumKodu($durum)
    {
        if (!is_scalar($durum)) {
            return 'diger';
        }
        $d = mb_strtolower(trim((string)$durum));

        if (strpos($d, 'iptal') !== false || strpos($d, 'cancel') !== false)  return 'iptal';
        if (strpos($d, 'iade') !== false || strpos($d, 'return') !== false)   return 'iade';
        if (strpos($d, 'teslim') !== false)                                   return 'teslim';
        if (strpos($d, 'alındı') !== false || strpos($d, 'yeni') !== false)   return 'yeni';
        if (strpos($d, 'hazırla') !== false || strpos($d, 'tedarik') !== false) return 'hazirlaniyor';
        if (strpos($d, 'kargo') !== false)                                    return 'kargoda';

        return 'diger';
    }

    // =================================================================
    // Desteklenmeyenler
    //
    // İade, paket durumu (PUT /order/updateOrderStatus) ve fatura linki
    // uçları belgeyle doğrulanmadan yazılmadı. tanim_Pazaryerleri
    // yetenek JSON'unda kapalıdır.
    // =================================================================
}
