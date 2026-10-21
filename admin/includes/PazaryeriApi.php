<?php
/**
 * Pazaryeri Entegrasyonu — Soyut Temel Sınıf (ortak gövde)
 *
 * Tasarım ilkesi:
 *   "Ne çekileceği" alt sınıfın işi, "çekilenle ne yapılacağı" ortak sınıfın işi.
 *   Alt sınıf API'den nötr bir dizi döndürür; veritabanına yazma, doğrulama,
 *   hata yönetimi ve durum takibi burada tek yerde yaşar.
 *
 * Kapsam (portal): sipariş, iade, paket durumu, kargo etiketi, fatura linki
 * bildirimi ve toplu istek (batch) takibi. Ürün / kategori / marka / öznitelik /
 * kargo listesi / müşteri sorusu senkronu bu sürümde YOKTUR.
 *
 * Yapılandırma zinciri:
 *   tanim_Pazaryerleri → Entegrasyonlar → EntegrasyonKanallari
 *   Kanal = bir firmanın bir pazaryerindeki hesabı. Veri sahipliği kanala
 *   bağlıdır (EntegrasyonKanallari_Firmalar_id → Firmalar.firma_id).
 *
 * API kimlik bilgileri kod dosyasında TUTULMAZ;
 * EntegrasyonKanallari_AyarJSON alanından okunur.
 *
 * Alt sınıf sözleşmesi (zorunlu):
 *   baseUrl($hizmet)   → API kök adresi (hizmet bazlı; Hepsiburada 3 sunucu kullanır)
 *   authHeaders()      → ['Authorization: ...', 'User-Agent: ...']
 *   configDogrula()    → eksik ayar varsa hata metni, yoksa ''
 *
 * Diğer iş metotları soyut DEĞİLDİR; varsayılan olarak "desteklenmiyor" döner.
 * Alt sınıf yalnızca kendi desteklediği yetenekleri ezer. Hangi yeteneğin
 * desteklendiği tanim_Pazaryerleri.Pazaryerleri_Yetenekler JSON'unda tutulur.
 */

require_once __DIR__ . '/../db.php';
// Saat dilimi: web için .user.ini, cron için admin/cron/tasks.php

abstract class PazaryeriApi
{
    /** Log'a yazılırken maskelenecek ayar anahtarları */
    const GIZLI_ANAHTARLAR = [
        'ApiSecret', 'Sifre', 'Password', 'Secret', 'Token', 'ApiKey',
        'SecretKey', 'AppSecret', 'AppKey', 'AccessToken', 'RefreshToken', 'ClientSecret'
    ];

    /** Yanıt gövdesinin log'a yazılacak azami karakter sayısı */
    const LOG_YANIT_LIMIT = 4000;

    /** EntegrasyonLoglari_Kaynak değeri */
    const LOG_KAYNAK = 'pazaryeri';

    protected $db;
    protected $config = [];
    protected $pazaryeriId = null;
    protected $pazaryeriKod = '';
    protected $pazaryeriAdi = '';
    protected $entegrasyonId = null;
    protected $kanalId = null;
    protected $kanalAdi = '';
    protected $firmaId = null;
    protected $yetenekler = [];
    protected $userId = null;
    protected $hata = '';

    /** Uç bazlı son istek zamanı (hız sınırlayıcı için) — mikro saniye */
    private $sonIstekZamani = [];

    /**
     * @param string   $pazaryeriKod tanim_Pazaryerleri.Pazaryerleri_Kod
     * @param int|null $userId       işlemi yapan kullanıcı (log ve kayıt alanları için)
     * @param int|null $kanalId      belirli bir kanal (firma hesabı); null ise varsayılan kanal
     */
    public function __construct($pazaryeriKod, $userId = null, $kanalId = null)
    {
        $this->db = Database::getInstance();
        $this->pazaryeriKod = $pazaryeriKod;
        $this->userId = $userId;
        $this->kanalId = $kanalId !== null ? (int)$kanalId : null;
        $this->configYukle();
    }

    // =================================================================
    // Yapılandırma
    // =================================================================

    /**
     * Pazaryeri + entegrasyon + kanal ayarlarını yükle ve birleştir.
     * Kanal belirtilmişse o kanal, belirtilmemişse varsayılan aktif kanal alınır.
     */
    protected function configYukle()
    {
        $sql = "
            SELECT TOP 1
                p.Pazaryerleri_id,
                p.Pazaryerleri_Ad,
                p.Pazaryerleri_Yetenekler,
                e.Entegrasyonlar_id,
                e.Entegrasyonlar_AyarJSON,
                k.EntegrasyonKanallari_id,
                k.EntegrasyonKanallari_Ad,
                k.EntegrasyonKanallari_AyarJSON,
                k.EntegrasyonKanallari_Firmalar_id
            FROM dbo.tanim_Pazaryerleri p
            INNER JOIN dbo.Entegrasyonlar e
                ON e.Entegrasyonlar_Kod = p.Pazaryerleri_EntegrasyonKod
               AND e.Entegrasyonlar_Durum = 1
            INNER JOIN dbo.EntegrasyonKanallari k
                ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
               AND k.EntegrasyonKanallari_Durum = 1
            LEFT JOIN dbo.Firmalar f
                ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
            WHERE p.Pazaryerleri_Kod = ?
              AND p.Pazaryerleri_Durum = 1
              AND (k.EntegrasyonKanallari_Firmalar_id IS NULL OR f.firma_durum = 1)   -- pasif firmanın hesabı kullanılmaz
        ";
        $params = [$this->pazaryeriKod];

        if ($this->kanalId !== null) {
            $sql .= " AND k.EntegrasyonKanallari_id = ? ";
            $params[] = $this->kanalId;
        }

        $sql .= " ORDER BY k.EntegrasyonKanallari_Varsayilan DESC, k.EntegrasyonKanallari_id ASC";

        $row = $this->db->fetchOne($sql, $params);

        if (!$row) {
            $this->hata = $this->pazaryeriKod . ' entegrasyonu tanımlı veya aktif değil. '
                        . 'Entegrasyon Yönetimi sayfasını kontrol edin.';
            return;
        }

        $this->pazaryeriId   = (int)$row['Pazaryerleri_id'];
        $this->pazaryeriAdi  = $row['Pazaryerleri_Ad'] ?? '';
        $this->entegrasyonId = (int)$row['Entegrasyonlar_id'];
        $this->kanalId       = (int)$row['EntegrasyonKanallari_id'];
        $this->kanalAdi      = $row['EntegrasyonKanallari_Ad'] ?? '';
        $this->firmaId       = $row['EntegrasyonKanallari_Firmalar_id'] !== null
                             ? (int)$row['EntegrasyonKanallari_Firmalar_id'] : null;

        $entAyar   = json_decode($row['Entegrasyonlar_AyarJSON'] ?? '{}', true) ?: [];
        $kanalAyar = json_decode($row['EntegrasyonKanallari_AyarJSON'] ?? '{}', true) ?: [];
        $this->config = array_merge($entAyar, $kanalAyar);

        $this->yetenekler = json_decode($row['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: [];

        $this->hata = $this->configDogrula();
    }

    // --- Alt sınıfın doldurması zorunlu olanlar ----------------------

    /**
     * @param string $hizmet 'varsayilan' | 'katalog' | 'listing' | 'siparis' ...
     *                       Tek adres kullanan pazaryerleri parametreyi yok sayar.
     */
    abstract protected function baseUrl($hizmet = 'varsayilan');
    abstract protected function authHeaders();
    abstract protected function configDogrula();

    /**
     * Bu pazaryerinin beklediği kimlik alanları.
     * Arayüz kanal düzenleme formunu bu tanıma göre üretir; alan listesi
     * sayfaya gömülmez, pazaryerinin kendi bilgisi olduğu için sınıfta durur.
     *
     * Statiktir: nesne üretilemeyen (kimliği henüz girilmemiş) pazaryerleri
     * için de okunabilmesi gerekir.
     *
     * @return array [['anahtar'=>, 'etiket'=>, 'gizli'=>bool, 'zorunlu'=>bool, 'aciklama'=>], ...]
     */
    public static function kimlikAlanlari()
    {
        return [];
    }

    // --- Durum sorguları ---------------------------------------------

    public function hazirMi()         { return $this->hata === ''; }
    public function getHata()         { return $this->hata; }
    public function getPazaryeriId()  { return $this->pazaryeriId; }
    public function getPazaryeriKod() { return $this->pazaryeriKod; }
    public function getPazaryeriAdi() { return $this->pazaryeriAdi; }
    public function getKanalId()      { return $this->kanalId; }
    public function getKanalAdi()     { return $this->kanalAdi; }
    public function getFirmaId()      { return $this->firmaId; }

    public function getConfig($anahtar, $varsayilan = null)
    {
        return $this->config[$anahtar] ?? $varsayilan;
    }

    /** Bu pazaryeri ilgili yeteneği destekliyor mu? */
    public function yetenek($ad)
    {
        return !empty($this->yetenekler[$ad]);
    }

    public function getYetenekler() { return $this->yetenekler; }

    /** Desteklenmeyen yetenek için standart yanıt */
    protected function desteklenmiyor($ad)
    {
        return [
            'basarili' => false,
            'hata'     => $this->pazaryeriAdi . ' bu işlemi desteklemiyor (' . $ad . ').',
            'veri'     => null
        ];
    }

    // =================================================================
    // Ayar çözümleme
    // =================================================================

    /**
     * Ayarı en özelden en genele doğru çöz; ilk dolu bulunan kazanır.
     *   1. Kanal (firma) bazlı   → PazaryeriAyarlari_EntegrasyonKanallari_id dolu
     *   2. Pazaryeri bazlı       → PazaryeriAyarlari_Pazaryerleri_id dolu
     *   3. Genel varsayılan      → ikisi de NULL
     *
     * Not: Örnek Müşteri'teki ürün kartı / ürün menüsü katmanları portalda
     * olmadığı için zincirde yer almaz.
     */
    public function ayar($anahtar, $varsayilan = null)
    {
        static $onbellek = [];
        $anahtarTam = $this->kanalId . '|' . $this->pazaryeriId . '|' . $anahtar;

        if (array_key_exists($anahtarTam, $onbellek)) {
            return $onbellek[$anahtarTam];
        }

        $row = $this->db->fetchOne("
            SELECT TOP 1 PazaryeriAyarlari_Deger, PazaryeriAyarlari_Tip
            FROM dbo.PazaryeriAyarlari
            WHERE PazaryeriAyarlari_Anahtar = ?
              AND PazaryeriAyarlari_Durum = 1
              AND (
                    PazaryeriAyarlari_EntegrasyonKanallari_id = ?
                 OR (PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id = ?)
                 OR (PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id IS NULL)
              )
            ORDER BY
                CASE WHEN PazaryeriAyarlari_EntegrasyonKanallari_id IS NOT NULL THEN 1
                     WHEN PazaryeriAyarlari_Pazaryerleri_id IS NOT NULL THEN 2
                     ELSE 3 END
        ", [$anahtar, $this->kanalId, $this->pazaryeriId]);

        if (!$row || $row['PazaryeriAyarlari_Deger'] === null) {
            return $onbellek[$anahtarTam] = $varsayilan;
        }

        $deger = $row['PazaryeriAyarlari_Deger'];

        switch ($row['PazaryeriAyarlari_Tip']) {
            case 'sayi': $deger = is_numeric($deger) ? $deger + 0 : $varsayilan; break;
            case 'bit':  $deger = (int)$deger === 1;                             break;
            case 'json': $deger = json_decode($deger, true) ?: $varsayilan;      break;
        }

        return $onbellek[$anahtarTam] = $deger;
    }

    // =================================================================
    // Taşıyıcı katman — hız sınırı, JSON, SOAP
    // =================================================================

    /**
     * Uç bazlı hız sınırı. Çiçeksepeti aynı uca 5 saniyede 1 istek kabul eder;
     * Hepsiburada dakikada ~240 istek sınırı uygular.
     * Sınır entegrasyon ayarlarındaki HizSiniriSaniye değerinden okunur.
     */
    protected function hizSiniriBekle($uc)
    {
        $sinir = (float)$this->getConfig('HizSiniriSaniye', 0);
        if ($sinir <= 0) {
            return;
        }

        $simdi = microtime(true);
        if (isset($this->sonIstekZamani[$uc])) {
            $gecen = $simdi - $this->sonIstekZamani[$uc];
            // +1 sn pay: sunucu sınırı kendi saatiyle ölçer, tam sınırda gönderilen istek reddedilir
            if ($gecen < $sinir + 1) {
                usleep((int)round(($sinir + 1 - $gecen) * 1000000));
            }
        }
        $this->sonIstekZamani[$uc] = microtime(true);
    }

    /**
     * Tarih aralığını API'nin kabul ettiği dilimlere böl.
     * Trendyol sipariş sorgusunda aralık en fazla 2 hafta olabilir;
     * Çiçeksepeti geniş aralıkta "Date Limit Exceeded" döndürür.
     *
     * @return array [['bas'=>timestamp,'bit'=>timestamp], ...]
     */
    protected function tarihDilimle($baslangic, $bitis, $gunSiniri = null)
    {
        $gunSiniri = $gunSiniri ?: (int)$this->ayar('SiparisCekmeGun', 14);
        if ($gunSiniri <= 0) { $gunSiniri = 14; }

        $adim = $gunSiniri * 86400;
        $dilimler = [];

        for ($bas = $baslangic; $bas < $bitis; $bas += $adim) {
            $dilimler[] = ['bas' => $bas, 'bit' => min($bas + $adim, $bitis)];
        }

        return $dilimler ?: [['bas' => $baslangic, 'bit' => $bitis]];
    }

    // --- Token yaşam döngüsü (OAuth2 tabanlı pazaryerleri) -----------

    /**
     * Erişim jetonu geçerli mi? Sabit başlıkla kimlik doğrulayan
     * pazaryerleri için her zaman true'dur (Trendyol, n11, Çiçeksepeti...).
     * Amazon SP-API gibi OAuth2 kullananlar bunu ezer.
     */
    protected function tokenGecerliMi()
    {
        return true;
    }

    /** Jetonu yenile ve kanal ayar JSON'una yaz. Varsayılan: yapacak iş yok. */
    protected function tokenYenile()
    {
        return true;
    }

    /** Yenilenen jetonu kanal ayarlarına kalıcı olarak yaz */
    protected function tokenKaydet(array $yeniAyarlar)
    {
        $this->config = array_merge($this->config, $yeniAyarlar);

        $kanalAyar = $this->db->fetchOne(
            "SELECT EntegrasyonKanallari_AyarJSON FROM dbo.EntegrasyonKanallari WHERE EntegrasyonKanallari_id = ?",
            [$this->kanalId]
        );
        $mevcut = json_decode($kanalAyar['EntegrasyonKanallari_AyarJSON'] ?? '{}', true) ?: [];

        $this->db->update('EntegrasyonKanallari', [
            'EntegrasyonKanallari_AyarJSON'           => json_encode(array_merge($mevcut, $yeniAyarlar),
                                                          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'EntegrasyonKanallari_GuncelleyenKullanici' => $this->userId,
            'EntegrasyonKanallari_GuncellemeTarihi'     => date('Y-m-d H:i:s')
        ], ['EntegrasyonKanallari_id' => $this->kanalId]);
    }

    /**
     * JSON API isteği gönder
     *
     * @param string     $method GET|POST|PUT|DELETE
     * @param string     $path   baseUrl sonrası yol (/ ile başlar)
     * @param array|null $body   POST/PUT gövdesi
     * @param array      $query  sorgu parametreleri
     * @param string     $islem  log'da görünecek işlem adı
     * @param string     $hizmet baseUrl() için hizmet adı
     *
     * @return array ['basarili'=>bool,'httpKod'=>int,'veri'=>array|null,'hata'=>string]
     */
    protected function istek($method, $path, $body = null, array $query = [], $islem = '', $hizmet = 'varsayilan')
    {
        if (!$this->hazirMi()) {
            return ['basarili' => false, 'httpKod' => 0, 'veri' => null, 'hata' => $this->hata];
        }

        // OAuth2 kullanan pazaryerlerinde jeton süresi dolmuşsa yenile
        if (!$this->tokenGecerliMi() && !$this->tokenYenile()) {
            return ['basarili' => false, 'httpKod' => 0, 'veri' => null,
                    'hata' => 'Erişim jetonu yenilenemedi.'];
        }

        $url = rtrim($this->baseUrl($hizmet), '/') . $path;
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $this->hizSiniriBekle($hizmet . '|' . strtok($path, '?'));

        $govde = $body === null
            ? null
            : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = array_merge($this->authHeaders(), [
            'Accept: application/json',
            'Content-Type: application/json'
        ]);

        $islem = $islem !== '' ? $islem : ($method . ' ' . $path);

        $httpKod = 0;
        $yanit = '';
        $curlHata = '';
        $sureMs = 0;
        $denemeSayisi = 0;

        // 429 (rate limit) ve 5xx için sınırlı yeniden deneme
        while ($denemeSayisi < 3) {
            $denemeSayisi++;
            $start = microtime(true);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ]);
            if ($govde !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $govde);
            }

            $yanit    = curl_exec($ch);
            $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlHata = curl_error($ch);
            curl_close($ch);

            $sureMs = (int)round((microtime(true) - $start) * 1000);

            if ($yanit === false) {
                $yanit = '';
                if ($denemeSayisi < 3) { usleep(500000 * $denemeSayisi); continue; }
                break;
            }

            if ($httpKod === 429 || $httpKod >= 500) {
                if ($denemeSayisi < 3) { sleep($denemeSayisi); continue; }
            }
            break;
        }

        $veri = null;
        $hata = '';

        if ($curlHata !== '' && $yanit === '') {
            $hata = 'Bağlantı hatası: ' . $curlHata;
        } else {
            $cozulen = json_decode($yanit, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $veri = $cozulen;
            }

            if ($httpKod < 200 || $httpKod >= 300) {
                $hata = $this->hataMesajiCoz($httpKod, $veri, $yanit);
            }
        }

        $this->logla($islem, $method, $url, $body, $yanit, $httpKod, $sureMs, $hata);

        return [
            'basarili' => $hata === '',
            'httpKod'  => $httpKod,
            'veri'     => $veri,
            'hata'     => $hata
        ];
    }

    /**
     * SOAP isteği gönder. n11'de sipariş ve kargo işlemleri
     * yalnızca SOAP üzerinden erişilebilir.
     *
     * @param string $url    tam SOAP servis adresi
     * @param string $zarf   hazır XML zarfı
     * @param string $islem  log adı
     * @return array ['basarili'=>bool,'httpKod'=>int,'xml'=>SimpleXMLElement|null,'hata'=>string]
     */
    protected function soapIstek($url, $zarf, $islem = 'SOAP', $soapAction = '')
    {
        if (!$this->hazirMi()) {
            return ['basarili' => false, 'httpKod' => 0, 'xml' => null, 'hata' => $this->hata];
        }

        $this->hizSiniriBekle('soap|' . $islem);

        $basliklar = ['Content-Type: text/xml; charset=utf-8'];
        if ($soapAction !== '') {
            $basliklar[] = 'SOAPAction: "' . $soapAction . '"';
        }

        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $zarf,
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $yanit    = curl_exec($ch);
        $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        curl_close($ch);
        $sureMs = (int)round((microtime(true) - $start) * 1000);

        $hata = '';
        $xml  = null;

        if ($yanit === false || $curlHata !== '') {
            $hata = 'Bağlantı hatası: ' . $curlHata;
            $yanit = '';
        } else {
            $xml = @simplexml_load_string($yanit);
            if ($xml === false) {
                $xml  = null;
                $hata = 'SOAP yanıtı çözümlenemedi (HTTP ' . $httpKod . ').';
            } else {
                // n11 kalıbı: <result><status>failure</status><errorMessage>...
                foreach ($xml->xpath('//result') ?: [] as $r) {
                    $durum = strtolower((string)($r->status ?? ''));
                    $mesaj = (string)($r->errorMessage ?? '');
                    if ($durum === 'failure' || $mesaj !== '') {
                        $hata = $mesaj !== '' ? $mesaj : 'SOAP isteği başarısız.';
                        break;
                    }
                }
                // SOAP Fault
                foreach ($xml->xpath('//*[local-name()="Fault"]') ?: [] as $f) {
                    $hata = trim((string)($f->faultstring ?? 'SOAP Fault'));
                    break;
                }
            }
        }

        // Zarf içinde kimlik bilgisi olduğu için log'a gövde yazılmaz
        $this->logla($islem, 'POST', $url, null, $yanit, $httpKod, $sureMs, $hata);

        return ['basarili' => $hata === '', 'httpKod' => $httpKod, 'xml' => $xml, 'hata' => $hata];
    }

    /**
     * API hata gövdesinden okunabilir mesaj üret.
     * Türetilen sınıflar kendi hata formatına göre ezebilir.
     */
    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if (is_array($veri)) {
            if (!empty($veri['errors']) && is_array($veri['errors'])) {
                $parcalar = [];
                foreach ($veri['errors'] as $e) {
                    $parcalar[] = is_array($e)
                        ? ($e['message'] ?? $e['errorMessage'] ?? json_encode($e, JSON_UNESCAPED_UNICODE))
                        : (string)$e;
                }
                return 'HTTP ' . $httpKod . ': ' . implode(' | ', $parcalar);
            }
            foreach (['message', 'Message', 'errorMessage', 'error', 'title', 'detail'] as $anahtar) {
                if (!empty($veri[$anahtar]) && is_string($veri[$anahtar])) {
                    return 'HTTP ' . $httpKod . ': ' . $veri[$anahtar];
                }
            }
        }

        $ozet = mb_substr(trim((string)$hamYanit), 0, 500);
        return 'HTTP ' . $httpKod . ($ozet !== '' ? ': ' . $ozet : '');
    }

    // =================================================================
    // Loglama ve maskeleme
    // =================================================================

    /**
     * İsteği EntegrasyonLoglari tablosuna yaz.
     * Portal şemasında log satırı kanala bağlıdır (NOT NULL); entegrasyon
     * bilgisi kanal üzerinden JOIN ile bulunur. Kanal çözülememişse
     * (yapılandırma hatası) satır yazılmaz, PHP hata loguna düşülür.
     */
    protected function logla($islem, $method, $url, $body, $hamYanit, $httpKod, $sureMs, $hata)
    {
        try {
            if ($this->kanalId === null) {
                error_log('PazaryeriApi log atlandı (kanal yok): ' . $islem . ' ' . $hata);
                return;
            }

            $maskeliUrl = $this->maskeleMetin($url);

            $logIstek = [
                'method' => $method,
                'url'    => $maskeliUrl,
                'body'   => is_array($body) ? $this->maskeleDizi($body) : null
            ];

            $yanitOzet = mb_substr($this->maskeleMetin((string)$hamYanit), 0, self::LOG_YANIT_LIMIT);
            $simdi     = date('Y-m-d H:i:s');

            $this->db->insert('EntegrasyonLoglari', [
                'EntegrasyonLoglari_EntegrasyonKanallari_id' => $this->kanalId,
                'EntegrasyonLoglari_IslemTipi'               => mb_substr((string)$islem, 0, 100),
                'EntegrasyonLoglari_Kaynak'                  => self::LOG_KAYNAK,
                'EntegrasyonLoglari_Hedef'                   => mb_substr(strtok($maskeliUrl, '?'), 0, 200),
                'EntegrasyonLoglari_Istek'                   => json_encode($logIstek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'EntegrasyonLoglari_Cevap'                   => $yanitOzet !== '' ? $yanitOzet : null,
                'EntegrasyonLoglari_HataMesaji'              => $hata !== '' ? $this->maskeleMetin($hata) : null,
                'EntegrasyonLoglari_HttpKod'                 => $httpKod ?: null,
                'EntegrasyonLoglari_SureMs'                  => $sureMs,
                'EntegrasyonLoglari_BasariliMi'              => $hata === '' ? 1 : 0,
                'EntegrasyonLoglari_KullaniciId'             => $this->userId,
                'EntegrasyonLoglari_IP'                      => isset($_SERVER['REMOTE_ADDR'])
                                                                ? mb_substr((string)$_SERVER['REMOTE_ADDR'], 0, 50) : null,
                'EntegrasyonLoglari_OlusturanKullanici'      => $this->userId ?? 0,
                'EntegrasyonLoglari_OlusturmaTarihi'         => $simdi,
                'EntegrasyonLoglari_Durum'                   => 1
            ]);
        } catch (Throwable $e) {
            error_log('PazaryeriApi log hatası: ' . $e->getMessage());
        }
    }

    protected function maskeleDizi(array $veri)
    {
        foreach ($veri as $anahtar => $deger) {
            if (is_array($deger)) {
                $veri[$anahtar] = $this->maskeleDizi($deger);
            } elseif (in_array($anahtar, self::GIZLI_ANAHTARLAR, true)) {
                $veri[$anahtar] = '***';
            }
        }
        return $veri;
    }

    protected function maskeleMetin($metin)
    {
        $metin = (string)$metin;
        foreach (self::GIZLI_ANAHTARLAR as $anahtar) {
            $deger = $this->config[$anahtar] ?? '';
            if (is_string($deger) && strlen($deger) >= 6) {
                $metin = str_replace($deger, '***', $metin);
            }
        }
        return $metin;
    }

    // =================================================================
    // Sipariş / iade yazıcıları — ORTAK
    // =================================================================

    /**
     * Siparişi ve kalemlerini kaydet (varsa güncelle).
     * Nötr sipariş yapısı: disId, siparisNo, paketNo, durumKod, hamDurum, toplamTutar,
     * paraBirimi, musteriAdi, aliciAdi, eposta, telefon, tckn, vergiNo, vergiDairesi,
     * il, ilce, semt, teslimatAdresi, faturaAdresi, kargoFirmasi, kargoTakipNo,
     * kargoBarkod, desi, siparisTarihi, terminTarihi, tahminiTeslim, ham, kalemler[].
     */
    protected function siparisKaydet(array $s)
    {
        $disId = (string)($s['disId'] ?? '');
        if ($disId === '') {
            return false;
        }

        $simdi = date('Y-m-d H:i:s');

        $veri = [
            'PazaryeriSiparisleri_SiparisNo'      => $s['siparisNo']      ?? null,
            'PazaryeriSiparisleri_PaketNo'        => $s['paketNo']        ?? null,
            'PazaryeriSiparisleri_DurumKod'       => $s['durumKod']       ?? null,
            'PazaryeriSiparisleri_HamDurum'       => $s['hamDurum']       ?? null,
            'PazaryeriSiparisleri_ToplamTutar'    => $s['toplamTutar']    ?? null,
            'PazaryeriSiparisleri_ParaBirimi'     => $s['paraBirimi']     ?? 'TRY',
            'PazaryeriSiparisleri_MusteriAdi'     => $s['musteriAdi']     ?? null,
            'PazaryeriSiparisleri_AliciAdi'       => $s['aliciAdi']       ?? null,
            'PazaryeriSiparisleri_Eposta'         => $s['eposta']         ?? null,
            'PazaryeriSiparisleri_Telefon'        => $s['telefon']        ?? null,
            'PazaryeriSiparisleri_TCKN'           => $s['tckn']           ?? null,
            'PazaryeriSiparisleri_VergiNo'        => $s['vergiNo']        ?? null,
            'PazaryeriSiparisleri_VergiDairesi'   => $s['vergiDairesi']   ?? null,
            'PazaryeriSiparisleri_Il'             => $s['il']             ?? null,
            'PazaryeriSiparisleri_Ilce'           => $s['ilce']           ?? null,
            'PazaryeriSiparisleri_Semt'           => $s['semt']           ?? null,
            'PazaryeriSiparisleri_TeslimatAdresi' => isset($s['teslimatAdresi']) ? json_encode($s['teslimatAdresi'], JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriSiparisleri_FaturaAdresi'   => isset($s['faturaAdresi'])   ? json_encode($s['faturaAdresi'],   JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriSiparisleri_KargoFirmasi'   => $s['kargoFirmasi']   ?? null,
            'PazaryeriSiparisleri_KargoTakipNo'   => $s['kargoTakipNo']   ?? null,
            'PazaryeriSiparisleri_KargoBarkod'    => $s['kargoBarkod']    ?? null,
            'PazaryeriSiparisleri_Desi'           => $s['desi']           ?? null,
            'PazaryeriSiparisleri_SiparisTarihi'  => $s['siparisTarihi']  ?? null,
            'PazaryeriSiparisleri_TerminTarihi'   => $s['terminTarihi']   ?? null,
            'PazaryeriSiparisleri_TahminiTeslim'  => $s['tahminiTeslim']  ?? null,
            'PazaryeriSiparisleri_HamVeri'        => isset($s['ham']) ? json_encode($s['ham'], JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriSiparisleri_Durum'          => 1
        ];

        $mevcut = $this->db->fetchOne("
            SELECT PazaryeriSiparisleri_id FROM dbo.PazaryeriSiparisleri
            WHERE PazaryeriSiparisleri_EntegrasyonKanallari_id = ? AND PazaryeriSiparisleri_DisId = ?
        ", [$this->kanalId, $disId]);

        if ($mevcut) {
            $veri['PazaryeriSiparisleri_GuncelleyenKullanici'] = $this->userId;
            $veri['PazaryeriSiparisleri_GuncellemeTarihi']     = $simdi;
            $this->db->update('PazaryeriSiparisleri', $veri,
                ['PazaryeriSiparisleri_id' => $mevcut['PazaryeriSiparisleri_id']]);
            $siparisId = (int)$mevcut['PazaryeriSiparisleri_id'];
        } else {
            $veri['PazaryeriSiparisleri_EntegrasyonKanallari_id'] = $this->kanalId;
            $veri['PazaryeriSiparisleri_Pazaryerleri_id']         = $this->pazaryeriId;
            $veri['PazaryeriSiparisleri_DisId']                   = $disId;
            $veri['PazaryeriSiparisleri_OlusturanKullanici']      = $this->userId;
            $veri['PazaryeriSiparisleri_OlusturmaTarihi']         = $simdi;
            $siparisId = (int)$this->db->insert('PazaryeriSiparisleri', $veri);
        }

        if ($siparisId > 0 && !empty($s['kalemler'])) {
            $this->siparisKalemleriKaydet($siparisId, $s['kalemler']);
        }

        return $siparisId;
    }

    /**
     * Sipariş kalemlerini yaz (mevcutlar silinip yeniden yazılır).
     * Portal ürün kartı eşleştirmesi (PazaryeriSiparisKalemleri_Urun_Hizmet_id)
     * şimdilik yapılmaz; nötr kalemde 'urunId' gelirse yazılır, yoksa NULL kalır.
     */
    protected function siparisKalemleriKaydet($siparisId, array $kalemler)
    {
        // Komisyon bazı pazaryerlerinde sipariş servisinden gelmez, sonradan finans servisinden
        // yazılır (Trendyol). Kalemler yeniden yazılırken sipariş verisinde komisyon yoksa eskisi korunur.
        $eskiKomisyon = [];
        foreach ($this->db->fetchAll("
            SELECT PazaryeriSiparisKalemleri_DisId AS disId, PazaryeriSiparisKalemleri_Barkod AS barkod,
                   PazaryeriSiparisKalemleri_KomisyonOran AS oran, PazaryeriSiparisKalemleri_KomisyonTutar AS tutar
            FROM dbo.PazaryeriSiparisKalemleri
            WHERE PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = ?
              AND PazaryeriSiparisKalemleri_KomisyonTutar IS NOT NULL
        ", [$siparisId]) ?: [] as $e) {
            $eskiKomisyon[(string)$e['disId'] . '|' . (string)$e['barkod']] = $e;
        }

        $this->db->delete('PazaryeriSiparisKalemleri', ['PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id' => $siparisId]);
        $simdi = date('Y-m-d H:i:s');

        foreach ($kalemler as $k) {
            $eski = $eskiKomisyon[(string)($k['disId'] ?? '') . '|' . (string)($k['barkod'] ?? '')] ?? null;
            if ($eski && ($k['komisyonTutar'] ?? null) === null) {
                $k['komisyonTutar'] = $eski['tutar'];
                $k['komisyonOran']  = $k['komisyonOran'] ?? $eski['oran'];
            }

            $this->db->insert('PazaryeriSiparisKalemleri', [
                'PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id' => $siparisId,
                'PazaryeriSiparisKalemleri_DisId'          => $k['disId']        ?? null,
                'PazaryeriSiparisKalemleri_Barkod'         => $k['barkod']       ?? null,
                'PazaryeriSiparisKalemleri_StokKodu'       => $k['stokKodu']     ?? null,
                'PazaryeriSiparisKalemleri_DisUrunId'      => $k['disUrunId']    ?? null,
                'PazaryeriSiparisKalemleri_UrunAdi'        => $k['urunAdi']      ?? null,
                'PazaryeriSiparisKalemleri_Urun_Hizmet_id' => $k['urunId']       ?? null,
                'PazaryeriSiparisKalemleri_Miktar'         => $k['miktar']       ?? 1,
                'PazaryeriSiparisKalemleri_BirimFiyat'     => $k['birimFiyat']   ?? null,
                'PazaryeriSiparisKalemleri_ToplamTutar'    => $k['toplamTutar']  ?? null,
                'PazaryeriSiparisKalemleri_IndirimTutar'   => $k['indirimTutar'] ?? null,
                'PazaryeriSiparisKalemleri_KdvOran'        => $k['kdvOran']      ?? null,
                'PazaryeriSiparisKalemleri_KdvTutar'       => $k['kdvTutar']     ?? null,
                'PazaryeriSiparisKalemleri_KomisyonOran'   => $k['komisyonOran'] ?? null,
                'PazaryeriSiparisKalemleri_KomisyonTutar'  => $k['komisyonTutar']?? null,
                'PazaryeriSiparisKalemleri_Desi'           => $k['desi']         ?? null,
                'PazaryeriSiparisKalemleri_HamDurum'       => $k['hamDurum']     ?? null,
                'PazaryeriSiparisKalemleri_HamVeri'        => isset($k['ham']) ? json_encode($k['ham'], JSON_UNESCAPED_UNICODE) : null,
                'PazaryeriSiparisKalemleri_OlusturanKullanici' => $this->userId,
                'PazaryeriSiparisKalemleri_OlusturmaTarihi'    => $simdi,
                'PazaryeriSiparisKalemleri_Durum'              => 1
            ]);
        }
    }

    /** İade kaydet (varsa güncelle) */
    protected function iadeKaydet(array $i)
    {
        $disId = (string)($i['disId'] ?? '');
        if ($disId === '') {
            return false;
        }

        $simdi = date('Y-m-d H:i:s');

        $veri = [
            'PazaryeriIadeler_SiparisNo'  => $i['siparisNo']  ?? null,
            'PazaryeriIadeler_PaketNo'    => $i['paketNo']    ?? null,
            'PazaryeriIadeler_DurumKod'   => $i['durumKod']   ?? null,
            'PazaryeriIadeler_HamDurum'   => $i['hamDurum']   ?? null,
            'PazaryeriIadeler_Sebep'      => $i['sebep']      ?? null,
            'PazaryeriIadeler_Aciklama'   => $i['aciklama']   ?? null,
            'PazaryeriIadeler_MusteriAdi' => $i['musteriAdi'] ?? null,
            'PazaryeriIadeler_Tutar'      => $i['tutar']      ?? null,
            'PazaryeriIadeler_IadeTarihi' => $i['iadeTarihi'] ?? null,
            'PazaryeriIadeler_Urunler'    => isset($i['kalemler']) ? json_encode($i['kalemler'], JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriIadeler_HamVeri'    => isset($i['ham'])      ? json_encode($i['ham'],      JSON_UNESCAPED_UNICODE) : null,
            'PazaryeriIadeler_Durum'      => 1
        ];

        $mevcut = $this->db->fetchOne("
            SELECT PazaryeriIadeler_id FROM dbo.PazaryeriIadeler
            WHERE PazaryeriIadeler_EntegrasyonKanallari_id = ? AND PazaryeriIadeler_DisId = ?
        ", [$this->kanalId, $disId]);

        if ($mevcut) {
            $veri['PazaryeriIadeler_GuncelleyenKullanici'] = $this->userId;
            $veri['PazaryeriIadeler_GuncellemeTarihi']     = $simdi;
            $this->db->update('PazaryeriIadeler', $veri,
                ['PazaryeriIadeler_id' => $mevcut['PazaryeriIadeler_id']]);
            return (int)$mevcut['PazaryeriIadeler_id'];
        }

        $veri['PazaryeriIadeler_EntegrasyonKanallari_id'] = $this->kanalId;
        $veri['PazaryeriIadeler_Pazaryerleri_id']         = $this->pazaryeriId;
        $veri['PazaryeriIadeler_DisId']                   = $disId;
        $veri['PazaryeriIadeler_OlusturanKullanici']      = $this->userId;
        $veri['PazaryeriIadeler_OlusturmaTarihi']         = $simdi;
        return (int)$this->db->insert('PazaryeriIadeler', $veri);
    }

    // =================================================================
    // Toplu istek (batch) takibi — ORTAK
    // =================================================================

    /** Gönderilen toplu isteği takip tablosuna yaz */
    protected function batchKaydet($batchId, $tip, $itemSayisi)
    {
        try {
            $this->db->insert('PazaryeriBatchIstekleri', [
                'PazaryeriBatchIstekleri_Pazaryerleri_id'         => $this->pazaryeriId,
                'PazaryeriBatchIstekleri_EntegrasyonKanallari_id' => $this->kanalId,
                'PazaryeriBatchIstekleri_BatchId'                 => $batchId,
                'PazaryeriBatchIstekleri_Tip'                     => $tip,
                'PazaryeriBatchIstekleri_ItemSayisi'              => $itemSayisi,
                'PazaryeriBatchIstekleri_IslemDurumu'             => 'beklemede',
                'PazaryeriBatchIstekleri_OlusturanKullanici'      => $this->userId,
                'PazaryeriBatchIstekleri_OlusturmaTarihi'         => date('Y-m-d H:i:s'),
                'PazaryeriBatchIstekleri_Durum'                   => 1
            ]);
        } catch (Throwable $e) {
            error_log('Batch kaydı hatası: ' . $e->getMessage());
        }
    }

    /**
     * Batch sonucunu işle (yalnız takip kaydı güncellenir).
     * Nötr sonuç: ['tamamlandi'=>bool, 'kalemler'=>[['barkod'=>, 'basarili'=>bool, 'hata'=>], ...]]
     *
     * Not: Örnek Müşteri'te kalem sonuçları ürün eşleşme tablosuna da yazılıyordu;
     * portalda ürün senkronu olmadığı için yalnız sayımlar tutulur.
     */
    protected function batchSonucIsle($batchId, array $sonuc)
    {
        $simdi = date('Y-m-d H:i:s');
        $basarili = 0;
        $hatali   = 0;

        foreach ($sonuc['kalemler'] ?? [] as $kalem) {
            if (!empty($kalem['basarili'])) {
                $basarili++;
            } else {
                $hatali++;
            }
        }

        $batchDurum = 'beklemede';
        if (!empty($sonuc['tamamlandi'])) {
            $batchDurum = $hatali > 0 ? 'hata' : 'tamamlandi';
        }

        $this->db->update('PazaryeriBatchIstekleri', [
            'PazaryeriBatchIstekleri_IslemDurumu'          => $batchDurum,
            'PazaryeriBatchIstekleri_BasariliSayi'         => $basarili,
            'PazaryeriBatchIstekleri_HataliSayi'           => $hatali,
            'PazaryeriBatchIstekleri_SonKontrol'           => $simdi,
            'PazaryeriBatchIstekleri_GuncelleyenKullanici' => $this->userId,
            'PazaryeriBatchIstekleri_GuncellemeTarihi'     => $simdi
        ], [
            'PazaryeriBatchIstekleri_EntegrasyonKanallari_id' => $this->kanalId,
            'PazaryeriBatchIstekleri_BatchId'                 => $batchId
        ]);

        return [
            'basarili'      => true,
            'hata'          => '',
            'durum'         => $batchDurum,
            'basarili_adet' => $basarili,
            'hatali_adet'   => $hatali
        ];
    }

    // =================================================================
    // Ortak yardımcılar
    // =================================================================

    /** Milisaniye epoch → 'Y-m-d H:i:s' (Trendyol ve Hepsiburada kullanır) */
    protected function msTarih($ms)
    {
        if (empty($ms)) {
            return null;
        }
        return date('Y-m-d H:i:s', (int)((int)$ms / 1000));
    }

    /** ISO 8601 → 'Y-m-d H:i:s' */
    protected function isoTarih($metin)
    {
        $metin = trim((string)$metin);
        if ($metin === '') {
            return null;
        }
        $zaman = strtotime($metin);
        return $zaman ? date('Y-m-d H:i:s', $zaman) : null;
    }

    // =================================================================
    // İş metotları — varsayılan olarak desteklenmiyor döner.
    // Alt sınıf yalnızca kendi desteklediklerini ezer.
    // =================================================================

    public function baglantiTest()                                        { return $this->desteklenmiyor('bağlantı testi'); }
    public function batchDurumGuncelle($batchId)                          { return $this->desteklenmiyor('toplu istek takibi'); }
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50) { return $this->desteklenmiyor('sipariş listeleme'); }
    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)    { return $this->desteklenmiyor('iade listeleme'); }
    /** Cron / sayfa her kanalda çağırır; desteklemeyen pazaryeri standart yanıt döner */
    public function siparisleriSenkronla($gunSayisi = 14)                 { return $this->desteklenmiyor('sipariş senkronu'); }
    /** Sipariş servisinde olmayan komisyonları finans servisinden kalemlere yazar (yetenek: komisyon) */
    public function komisyonlariSenkronla($gunSayisi = 30)                { return $this->desteklenmiyor('komisyon senkronu'); }
    public function iadeleriSenkronla($gunSayisi = 30)                    { return $this->desteklenmiyor('iade senkronu'); }
    public function paketDurumGuncelle($paketId, $durum, array $kalemler = [], array $ek = []) { return $this->desteklenmiyor('paket durumu'); }
    public function faturaLinkGonder($paketId, array $fatura)             { return $this->desteklenmiyor('fatura bildirimi'); }
    public function faturaLinkSil($paketId, array $siparisHam = [])       { return $this->desteklenmiyor('fatura linki silme'); }
    /** Pazaryeri fatura linkinde dosya uzantısı (.pdf) istiyor mu (n11 ister) */
    public function faturaLinkPdfZorunlu()                                { return false; }
    public function kargoEtiketiOlustur($paketId)                         { return $this->desteklenmiyor('kargo etiketi'); }
    public function kargoEtiketiGetir($paketId)                           { return $this->desteklenmiyor('kargo etiketi'); }
}
