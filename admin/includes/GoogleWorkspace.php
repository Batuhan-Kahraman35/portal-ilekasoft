<?php
/**
 * Google Workspace API Katmanı — Portal Örnek Soft
 *
 * Admin SDK Directory API + Enterprise License Manager API.
 * google/apiclient KULLANILMAZ; servis hesabı JWT'si openssl ile imzalanır,
 * istekler ham cURL ile atılır (Composer bağımlılığı yok).
 *
 * Ayarlar veritabanından okunur (kodda anahtar / alan adı / SKU YOK):
 *   Entegrasyonlar.Entegrasyonlar_AyarJSON  → Scopes
 *   EntegrasyonKanallari_KullaniciAdi       → adına işlem yapılacak süper yönetici
 *   EntegrasyonKanallari_Sifre              → servis hesabı JSON anahtarı
 *   EntegrasyonKanallari_AyarJSON           → customer, lisans_urun, lisans_sku
 *
 * Yetki: Admin Console → Alan genelinde yetki kaydı (servis hesabı client_id + Scopes).
 * Kullanıcı anahtarı olarak e-posta veya Google kullanıcı ID'si (21 hane, METİN) kullanılır;
 * ID asla (int)'e çevrilmemeli.
 */

require_once __DIR__ . '/../db.php';

class GoogleWorkspace
{
    const ENTEGRASYON_ADI = 'Google Workspace';
    const TOKEN_URL       = 'https://oauth2.googleapis.com/token';
    const DIRECTORY_URL   = 'https://admin.googleapis.com/admin/directory/v1';
    const LISANS_URL      = 'https://licensing.googleapis.com/apps/licensing/v1';
    const TOKEN_OMRU_SN   = 3300;   // 55 dk (Google 60 dk veriyor, öncesinde yenile)
    const SAYFA_BOYUTU    = 500;

    private $db;
    private array $kanal;
    private array $config = [];
    private array $anahtar = [];
    private int $kanalId;
    private string $kanalAdi;
    private ?int $userId;
    private string $hata = '';

    /** Kanal bazlı token önbelleği: [kanalId => ['token' => ..., 'zaman' => ts]] */
    private static array $tokenlar = [];

    /**
     * @param array    $kanal  EntegrasyonKanallari satırı + Entegrasyonlar_AyarJSON
     * @param int|null $userId Log kayıtlarına yazılacak kullanıcı
     */
    public function __construct(array $kanal, ?int $userId = 1)
    {
        $this->db       = Database::getInstance();
        $this->kanal    = $kanal;
        $this->userId   = $userId;
        $this->kanalId  = (int)($kanal['EntegrasyonKanallari_id'] ?? 0);
        $this->kanalAdi = (string)($kanal['EntegrasyonKanallari_Ad'] ?? '');

        $this->configYukle();
    }

    /** Aktif Google Workspace kanallarını entegrasyon ayarlarıyla birlikte döner */
    public static function aktifKanallar($db = null): array
    {
        $db = $db ?: Database::getInstance();

        return $db->fetchAll("
            SELECT k.*,
                   CAST(e.Entegrasyonlar_AyarJSON AS NVARCHAR(MAX)) AS Entegrasyonlar_AyarJSON,
                   e.Entegrasyonlar_id
            FROM Entegrasyonlar e
            INNER JOIN EntegrasyonKanallari k
                    ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Ad = ?
              AND e.Entegrasyonlar_Durum = 1
              AND k.EntegrasyonKanallari_Durum = 1
            ORDER BY k.EntegrasyonKanallari_id
        ", [self::ENTEGRASYON_ADI]) ?: [];
    }

    /** Kanal id'sinden istemci oluştur; kanal yoksa / pasifse null */
    public static function kanaldan(int $kanalId, ?int $userId = 1): ?self
    {
        foreach (self::aktifKanallar() as $k) {
            if ((int)$k['EntegrasyonKanallari_id'] === $kanalId) {
                return new self($k, $userId);
            }
        }
        return null;
    }

    private function configYukle(): void
    {
        $entAyar   = json_decode((string)($this->kanal['Entegrasyonlar_AyarJSON'] ?? '{}'), true) ?: [];
        $kanalAyar = json_decode((string)($this->kanal['EntegrasyonKanallari_AyarJSON'] ?? '{}'), true) ?: [];

        $this->config = array_merge(
            ['customer' => 'my_customer', 'lisans_urun' => 'Google-Apps', 'lisans_sku' => ''],
            $entAyar,
            $kanalAyar,
            ['admin' => trim((string)($this->kanal['EntegrasyonKanallari_KullaniciAdi'] ?? ''))]
        );

        $this->anahtar = json_decode((string)($this->kanal['EntegrasyonKanallari_Sifre'] ?? ''), true) ?: [];

        if (empty($this->config['Scopes']) || !is_array($this->config['Scopes'])) {
            $this->hata = 'Entegrasyon ayarında Scopes tanımlı değil.';
        } elseif ($this->config['admin'] === '') {
            $this->hata = 'Kanalda yönetici e-postası (kullanıcı adı) tanımlı değil: ' . $this->kanalAdi;
        } elseif (($this->anahtar['type'] ?? '') !== 'service_account' || empty($this->anahtar['private_key'])) {
            $this->hata = 'Kanal şifre alanında geçerli bir servis hesabı JSON anahtarı yok: ' . $this->kanalAdi;
        }
    }

    public function hazirMi(): bool
    {
        return $this->hata === '';
    }

    public function getHata(): string
    {
        return $this->hata;
    }

    public function getKanalId(): int
    {
        return $this->kanalId;
    }

    public function getKanalAdi(): string
    {
        return $this->kanalAdi;
    }

    public function lisansSku(): string
    {
        return (string)$this->config['lisans_sku'];
    }

    // ================================================================
    // Kimlik doğrulama
    // ================================================================

    private static function b64url(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }

    /** Servis hesabı JWT'si ile yönetici adına erişim token'ı al (önbellekli) */
    private function token(bool $zorla = false): ?string
    {
        $onbellek = self::$tokenlar[$this->kanalId] ?? null;
        if (!$zorla && $onbellek && (time() - $onbellek['zaman']) < self::TOKEN_OMRU_SN) {
            return $onbellek['token'];
        }

        $simdi  = time();
        $baslik = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $icerik = self::b64url(json_encode([
            'iss'   => $this->anahtar['client_email'],
            'sub'   => $this->config['admin'],
            'scope' => implode(' ', $this->config['Scopes']),
            'aud'   => self::TOKEN_URL,
            'iat'   => $simdi,
            'exp'   => $simdi + 3600,
        ]));

        if (!openssl_sign($baslik . '.' . $icerik, $imza, $this->anahtar['private_key'], 'sha256WithRSAEncryption')) {
            $this->hata = 'JWT imzalanamadı, servis hesabı anahtarı geçersiz.';
            return null;
        }

        $c = curl_init(self::TOKEN_URL);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $baslik . '.' . $icerik . '.' . self::b64url($imza),
            ]),
        ]);
        $ham  = curl_exec($c);
        $kod  = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        $cHat = curl_error($c);
        curl_close($c);

        $j = json_decode((string)$ham, true) ?: [];

        if ($cHat !== '' || $kod !== 200 || empty($j['access_token'])) {
            $this->hata = $cHat !== ''
                ? 'Google bağlantı hatası: ' . $cHat
                : 'Token alınamadı: ' . ($j['error'] ?? $kod) . ' ' . ($j['error_description'] ?? '');
            $this->logla('TOKEN', ['url' => self::TOKEN_URL], $this->hata, $kod, 0, null);
            return null;
        }

        self::$tokenlar[$this->kanalId] = ['token' => $j['access_token'], 'zaman' => $simdi];
        return $j['access_token'];
    }

    // ================================================================
    // Transport
    // ================================================================

    /**
     * API çağrısı.
     * @param string     $islem  Log işlem tipi (GW_KULLANICI_AC vb.)
     * @param bool       $logla  Okuma çağrılarında sayfa başı log yazmamak için false verilebilir
     * @return array ['success' => bool, 'http' => int, 'data' => ?array, 'message' => string]
     */
    private function istek(string $yontem, string $url, ?array $govde, string $islem, bool $logla = true): array
    {
        if (!$this->hazirMi()) {
            return ['success' => false, 'http' => 0, 'data' => null, 'message' => $this->hata];
        }

        $deneme = 0;
        do {
            $token = $this->token($deneme > 0);
            if (!$token) {
                return ['success' => false, 'http' => 0, 'data' => null, 'message' => $this->hata];
            }

            $bas = microtime(true);
            $c = curl_init($url);
            $basliklar = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
            curl_setopt_array($c, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CUSTOMREQUEST  => $yontem,
            ]);
            if ($govde !== null) {
                $basliklar[] = 'Content-Type: application/json';
                curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($govde, JSON_UNESCAPED_UNICODE));
            }
            curl_setopt($c, CURLOPT_HTTPHEADER, $basliklar);

            $ham  = curl_exec($c);
            $kod  = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
            $cHat = curl_error($c);
            curl_close($c);
            $sure = (int)round((microtime(true) - $bas) * 1000);

            $deneme++;
            // 401: token süresi dolmuş olabilir, bir kez yenile. 429/5xx: bir kez bekleyip tekrar dene.
            $tekrar = $deneme === 1 && ($kod === 401 || $kod === 429 || $kod >= 500);
            if ($tekrar && $kod !== 401) {
                sleep(1);
            }
        } while ($tekrar);

        $j = json_decode((string)$ham, true);

        if ($cHat !== '') {
            $hata = 'Google bağlantı hatası: ' . $cHat;
        } elseif ($kod >= 200 && $kod < 300) {
            $hata = '';
        } else {
            $hata = 'HTTP ' . $kod . ': ' . ($j['error']['message'] ?? 'bilinmeyen hata');
        }

        // Gövdede şifre olabilir; loga yalnız alan adları yazılır
        $logIstek = ['yontem' => $yontem, 'url' => $url];
        if ($govde !== null) {
            $logIstek['alanlar'] = array_keys($govde);
        }

        if ($logla || $hata !== '') {
            $this->logla($islem, $logIstek, $hata, $kod, $sure, $yontem === 'GET' ? null : $ham);
        }

        return [
            'success' => $hata === '',
            'http'    => $kod,
            'data'    => is_array($j) ? $j : null,
            'message' => $hata,
        ];
    }

    private static function anahtarKodla(string $kullanici): string
    {
        return rawurlencode(trim($kullanici));
    }

    // ================================================================
    // Kullanıcılar
    // ================================================================

    /**
     * Tüm kullanıcılar (sayfalı çekilir, tek sonuç döner).
     * @param bool $sadeceAktif true → askıdakiler hariç
     */
    public function kullanicilar(bool $sadeceAktif = true): array
    {
        $liste = [];
        $sayfa = null;
        $alanlar = 'users(id,primaryEmail,name(givenName,familyName),suspended,orgUnitPath,lastLoginTime,creationTime),nextPageToken';

        do {
            $url = self::DIRECTORY_URL . '/users?customer=' . rawurlencode($this->config['customer'])
                 . '&maxResults=' . self::SAYFA_BOYUTU
                 . '&fields=' . rawurlencode($alanlar)
                 . ($sadeceAktif ? '&query=' . rawurlencode('isSuspended=false') : '')
                 . ($sayfa ? '&pageToken=' . rawurlencode($sayfa) : '');

            $r = $this->istek('GET', $url, null, 'GW_KULLANICI_LISTE', false);
            if (!$r['success']) {
                return ['success' => false, 'data' => [], 'message' => $r['message']];
            }

            foreach ($r['data']['users'] ?? [] as $u) {
                $liste[] = self::kullaniciNormalize($u);
            }
            $sayfa = $r['data']['nextPageToken'] ?? null;
        } while ($sayfa);

        $this->logla('GW_KULLANICI_LISTE', ['adet' => count($liste), 'sadece_aktif' => $sadeceAktif], '', 200, 0, null);

        return ['success' => true, 'data' => $liste, 'message' => ''];
    }

    /** Tek kullanıcı; bulunamazsa success=true, data=null */
    public function kullaniciGetir(string $kullanici): array
    {
        $r = $this->istek('GET', self::DIRECTORY_URL . '/users/' . self::anahtarKodla($kullanici), null, 'GW_KULLANICI_GETIR', false);

        if ($r['http'] === 404) {
            return ['success' => true, 'data' => null, 'message' => ''];
        }
        if (!$r['success']) {
            return ['success' => false, 'data' => null, 'message' => $r['message']];
        }
        return ['success' => true, 'data' => self::kullaniciNormalize($r['data']), 'message' => ''];
    }

    /** Yeni hesap; ilk girişte şifre değişimi zorunlu */
    public function hesapAc(string $ad, string $soyad, string $eposta, string $sifre, string $birim = '/'): array
    {
        $r = $this->istek('POST', self::DIRECTORY_URL . '/users', [
            'primaryEmail'              => trim($eposta),
            'name'                      => ['givenName' => trim($ad), 'familyName' => trim($soyad)],
            'password'                  => $sifre,
            'changePasswordAtNextLogin' => true,
            'orgUnitPath'               => $birim !== '' ? $birim : '/',
        ], 'GW_KULLANICI_AC');

        return self::sonuc($r);
    }

    public function askiyaAl(string $kullanici): array
    {
        return self::sonuc($this->istek('PATCH', self::DIRECTORY_URL . '/users/' . self::anahtarKodla($kullanici),
            ['suspended' => true], 'GW_ASKIYA_AL'));
    }

    /** Askıdan çıkar; şifre verilirse sıfırlanır ve ilk girişte değiştirme zorunlu olur */
    public function askidanCikar(string $kullanici, ?string $sifre = null, ?string $birim = null): array
    {
        $govde = ['suspended' => false];
        if ($sifre !== null) {
            $govde['password'] = $sifre;
            $govde['changePasswordAtNextLogin'] = true;
        }
        if ($birim !== null && $birim !== '') {
            $govde['orgUnitPath'] = $birim;
        }

        return self::sonuc($this->istek('PATCH', self::DIRECTORY_URL . '/users/' . self::anahtarKodla($kullanici),
            $govde, 'GW_ASKIDAN_CIKAR'));
    }

    /** Kalıcı silme (Google 20 gün içinde geri almaya izin verir, bkz. geriAl) */
    public function sil(string $kullanici): array
    {
        return self::sonuc($this->istek('DELETE', self::DIRECTORY_URL . '/users/' . self::anahtarKodla($kullanici),
            null, 'GW_KULLANICI_SIL'));
    }

    /** Silinen hesabı geri al; e-posta değil Google kullanıcı ID'si gerekir */
    public function geriAl(string $googleId, string $birim = '/'): array
    {
        return self::sonuc($this->istek('POST', self::DIRECTORY_URL . '/users/' . self::anahtarKodla($googleId) . '/undelete',
            ['orgUnitPath' => $birim], 'GW_KULLANICI_GERI_AL'));
    }

    // ================================================================
    // Organizasyon birimleri / alan adları
    // ================================================================

    /** Birim yolları; API kök birimi döndürmediği için "/" başa eklenir */
    public function birimler(): array
    {
        $r = $this->istek('GET', self::DIRECTORY_URL . '/customer/' . rawurlencode($this->config['customer'])
            . '/orgunits?type=all', null, 'GW_BIRIM_LISTE', false);

        if (!$r['success']) {
            return ['success' => false, 'data' => [], 'message' => $r['message']];
        }

        $liste = [['yol' => '/', 'ad' => '/ (Kök)']];
        $diger = [];
        foreach ($r['data']['organizationUnits'] ?? [] as $o) {
            $diger[] = ['yol' => $o['orgUnitPath'], 'ad' => $o['orgUnitPath']];
        }
        usort($diger, function ($a, $b) { return strcmp($a['yol'], $b['yol']); });

        return ['success' => true, 'data' => array_merge($liste, $diger), 'message' => ''];
    }

    /** Doğrulanmış alan adları, birincil önde */
    public function alanAdlari(): array
    {
        $r = $this->istek('GET', self::DIRECTORY_URL . '/customer/' . rawurlencode($this->config['customer'])
            . '/domains', null, 'GW_ALAN_LISTE', false);

        if (!$r['success']) {
            return ['success' => false, 'data' => [], 'message' => $r['message']];
        }

        $liste = [];
        foreach ($r['data']['domains'] ?? [] as $d) {
            if (empty($d['verified'])) continue;
            $liste[] = ['alan' => $d['domainName'], 'birincil' => !empty($d['isPrimary'])];
        }
        usort($liste, function ($a, $b) {
            return [$b['birincil'], $a['alan']] <=> [$a['birincil'], $b['alan']];
        });

        return ['success' => true, 'data' => $liste, 'message' => ''];
    }

    // ================================================================
    // Lisans
    // ================================================================

    private function lisansAdresi(): string
    {
        return self::LISANS_URL . '/product/' . rawurlencode($this->config['lisans_urun'])
             . '/sku/' . rawurlencode($this->config['lisans_sku']) . '/user';
    }

    /** Kullanıcıda kanal SKU'su atanmış mı: data = true/false */
    public function lisansVarMi(string $eposta): array
    {
        if ($this->config['lisans_sku'] === '') {
            return ['success' => false, 'data' => null, 'message' => 'Kanal ayarında lisans_sku tanımlı değil.'];
        }

        $r = $this->istek('GET', $this->lisansAdresi() . '/' . self::anahtarKodla($eposta), null, 'GW_LISANS_KONTROL', false);

        if ($r['http'] === 404) {
            return ['success' => true, 'data' => false, 'message' => ''];
        }
        return ['success' => $r['success'], 'data' => $r['success'] ? true : null, 'message' => $r['message']];
    }

    /** Lisans yoksa atar (otomatik lisanslama açıksa zaten vardır, tekrar atanmaz) */
    public function lisansAta(string $eposta): array
    {
        $var = $this->lisansVarMi($eposta);
        if (!$var['success']) {
            return $var;
        }
        if ($var['data'] === true) {
            return ['success' => true, 'data' => ['zaten_vardi' => true], 'message' => ''];
        }

        return self::sonuc($this->istek('POST', $this->lisansAdresi(), ['userId' => trim($eposta)], 'GW_LISANS_ATA'));
    }

    /** Ürün bazında kullanılan SKU'lar ve kullanıcı sayıları (bağlantı testi için) */
    public function lisansOzeti(string $alanAdi): array
    {
        $ozet = [];
        $sayfa = null;

        do {
            $url = self::LISANS_URL . '/product/' . rawurlencode($this->config['lisans_urun']) . '/users'
                 . '?customerId=' . rawurlencode($alanAdi) . '&maxResults=1000'
                 . ($sayfa ? '&pageToken=' . rawurlencode($sayfa) : '');

            $r = $this->istek('GET', $url, null, 'GW_LISANS_LISTE', false);
            if (!$r['success']) {
                return ['success' => false, 'data' => [], 'message' => $r['message']];
            }

            foreach ($r['data']['items'] ?? [] as $l) {
                $sku = (string)$l['skuId'];
                if (!isset($ozet[$sku])) {
                    $ozet[$sku] = ['sku' => $sku, 'ad' => $l['skuName'] ?? $sku, 'adet' => 0];
                }
                $ozet[$sku]['adet']++;
            }
            $sayfa = $r['data']['nextPageToken'] ?? null;
        } while ($sayfa);

        return ['success' => true, 'data' => array_values($ozet), 'message' => ''];
    }

    // ================================================================
    // Yardımcılar
    // ================================================================

    /** API kullanıcı nesnesini sayfanın kullandığı düz diziye çevirir (id METİN kalır) */
    private static function kullaniciNormalize(array $u): array
    {
        return [
            'id'         => (string)($u['id'] ?? ''),
            'email'      => (string)($u['primaryEmail'] ?? ''),
            'ad'         => (string)($u['name']['givenName'] ?? ''),
            'soyad'      => (string)($u['name']['familyName'] ?? ''),
            'askida'     => !empty($u['suspended']),
            'birim'      => (string)($u['orgUnitPath'] ?? '/'),
            'son_giris'  => self::tarih($u['lastLoginTime'] ?? null),
            'olusturma'  => self::tarih($u['creationTime'] ?? null),
        ];
    }

    /** RFC3339 → yerel saat (Y-m-d H:i:s); hiç giriş yapmamışsa Google 1970 döner → null */
    private static function tarih(?string $deger): ?string
    {
        if (!$deger) return null;
        $ts = strtotime($deger);
        if ($ts === false || $ts <= 0) return null;
        return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d H:i:s');
    }

    private static function sonuc(array $r): array
    {
        return [
            'success' => $r['success'],
            'data'    => $r['success'] && $r['data'] ? self::kullaniciVeyaHam($r['data']) : null,
            'message' => $r['message'],
        ];
    }

    private static function kullaniciVeyaHam(array $d): array
    {
        return isset($d['primaryEmail']) ? self::kullaniciNormalize($d) : $d;
    }

    /**
     * EntegrasyonLoglari'na yaz. Anahtar, token ve şifre asla yazılmaz;
     * istek gövdesinden yalnız alan adları gelir.
     */
    private function logla(string $islem, array $logIstek, string $hata, int $httpKod, int $sureMs, $hamYanit): void
    {
        if ($this->kanalId <= 0) return;   // EntegrasyonKanallari_id NOT NULL

        try {
            $cevap = is_string($hamYanit) && $hamYanit !== '' ? mb_substr($hamYanit, 0, 4000) : null;

            $this->db->insert('EntegrasyonLoglari', [
                'EntegrasyonLoglari_EntegrasyonKanallari_id' => $this->kanalId,
                'EntegrasyonLoglari_IslemTipi'               => mb_substr($islem, 0, 100),
                'EntegrasyonLoglari_Istek'                   => json_encode(
                    $logIstek + ['http' => $httpKod, 'sure_ms' => $sureMs],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'EntegrasyonLoglari_Cevap'                   => $cevap,
                'EntegrasyonLoglari_BasariliMi'              => $hata === '' ? 1 : 0,
                'EntegrasyonLoglari_HataMesaji'              => $hata !== '' ? mb_substr($hata, 0, 1000) : null,
                'EntegrasyonLoglari_Hedef'                   => isset($logIstek['url']) ? mb_substr(rawurldecode(basename(parse_url($logIstek['url'], PHP_URL_PATH))), 0, 200) : null,
                'EntegrasyonLoglari_OlusturanKullanici'      => $this->userId ?? 1,
                'EntegrasyonLoglari_Kaynak'                  => 'GoogleWorkspace',
                'EntegrasyonLoglari_Durum'                   => 1,
            ]);
        } catch (Exception $e) {
            error_log('GoogleWorkspace log hatası: ' . $e->getMessage());
        }
    }
}
