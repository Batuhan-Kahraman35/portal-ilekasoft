<?php
/**
 * Admin Panel - CRM Hesap Kontrol
 *
 * Portal'da durumu pasif olan personellerin diğer CRM sistemlerinde
 * hâlâ aktif hesabı olup olmadığını tarar ve toplu olarak pasife alır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanın bilgilerini al
$pageInfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'CRM Hesap Kontrol';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// ===================================================================
// Yardımcı Fonksiyonlar
// ===================================================================

/** CRM bağlantısı için kimlik bilgilerini oku */
function crmKimlikBilgisi() {
    static $cfg = null;
    if ($cfg === null) {
        $json = @file_get_contents(__DIR__ . '/../../config/database.json');
        $all = $json ? json_decode($json, true) : [];
        $cfg = $all['crm_kontrol'] ?? [];
    }
    return $cfg;
}

/** SQL identifier temizle - sadece harf, rakam ve alt çizgi */
function sqlAd($ad) {
    return preg_replace('/[^A-Za-z0-9_]/', '', (string)$ad);
}

/** Hedef CRM veritabanına bağlan */
function crmBaglan($sistem) {
    $cfg = crmKimlikBilgisi();

    if (empty($cfg['username']) || empty($cfg['password'])) {
        return ['conn' => false, 'hata' => 'config/database.json içinde crm_kontrol bağlantı bilgisi tanımlı değil.'];
    }

    $host = !empty($sistem['CRM_Sistemleri_host']) ? $sistem['CRM_Sistemleri_host'] : ($cfg['host'] ?? '');

    $conn = @sqlsrv_connect($host, [
        'Database'               => $sistem['CRM_Sistemleri_veritabani'],
        'UID'                    => $cfg['username'],
        'PWD'                    => $cfg['password'],
        'CharacterSet'           => 'UTF-8',
        'TrustServerCertificate' => true,
        'LoginTimeout'           => 5
    ]);

    if ($conn === false) {
        $errors = sqlsrv_errors();
        $mesaj = $errors[0]['message'] ?? 'Bilinmeyen bağlantı hatası';
        return ['conn' => false, 'hata' => trim($mesaj)];
    }

    return ['conn' => $conn, 'hata' => null];
}

/** Hedef tabloda gerçekten var olan kolonları döndür */
function crmKolonlari($conn, $tablo) {
    $sql = "SELECT c.name FROM sys.columns c WHERE c.object_id = OBJECT_ID(?)";
    $stmt = @sqlsrv_query($conn, $sql, ['dbo.' . $tablo]);
    if ($stmt === false) return [];

    $kolonlar = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $kolonlar[mb_strtolower($row['name'], 'UTF-8')] = $row['name'];
    }
    sqlsrv_free_stmt($stmt);
    return $kolonlar;
}

/** E-posta normalize */
function nrmEmail($v) {
    $v = mb_strtolower(trim((string)$v), 'UTF-8');
    return ($v !== '' && strpos($v, '@') !== false) ? $v : '';
}

/**
 * TC kimlik normalize.
 *
 * 11 hane olması yeterli değildir: 11111111111 gibi dolgu değerler farklı
 * kişilerin aynı personele eşleşmesine yol açtığı için resmi TC algoritması
 * ile doğrulanır, geçmeyen değer eşleştirmede kullanılmaz.
 */
function nrmTc($v) {
    $v = preg_replace('/\D/', '', (string)$v);

    if (strlen($v) !== 11 || $v[0] === '0') return '';

    $h = array_map('intval', str_split($v));

    // 10. hane kontrolü
    $tek = $h[0] + $h[2] + $h[4] + $h[6] + $h[8];
    $cift = $h[1] + $h[3] + $h[5] + $h[7];
    if ((($tek * 7) - $cift) % 10 !== $h[9]) return '';

    // 11. hane kontrolü
    if (array_sum(array_slice($h, 0, 10)) % 10 !== $h[10]) return '';

    return $v;
}

/** Ad soyad normalize - Türkçe karakterler sadeleştirilir */
function nrmAd($v) {
    $harita = [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's',
        'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u',
        'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c'
    ];
    $v = strtr((string)$v, $harita);
    $v = mb_strtolower($v, 'UTF-8');
    $v = preg_replace('/[^a-z0-9]+/', ' ', $v);
    return trim(preg_replace('/\s+/', ' ', $v));
}

/** DATETIME değerini metne çevir */
function tarihMetin($deger) {
    if ($deger instanceof DateTime) return $deger->format('d.m.Y H:i');
    return $deger ? (string)$deger : null;
}

/** Aktif CRM sistemlerini getir */
function crmSistemleri($db) {
    return $db->fetchAll("
        SELECT *
        FROM dbo.tanim_CRM_Sistemleri
        WHERE Durum = 1
        ORDER BY CRM_Sistemleri_sira, CRM_Sistemleri_ad
    ");
}

/** Sistem Google Workspace mi (API tabanlı; veritabanı bağlantısı yok) */
function googleMi($sistem) {
    return ($sistem['CRM_Sistemleri_tip'] ?? 'MSSQL') === 'GOOGLE';
}

/** Sistemin bağlı olduğu entegrasyon kanalından Google istemcisi */
function googleIstemci($sistem, $userId) {
    require_once __DIR__ . '/../includes/GoogleWorkspace.php';

    $kanalId = (int)($sistem['CRM_Sistemleri_EntegrasyonKanallari_id'] ?? 0);
    $gw = $kanalId > 0 ? GoogleWorkspace::kanaldan($kanalId, (int)$userId) : null;

    if (!$gw) {
        return ['gw' => null, 'hata' => 'Google Workspace entegrasyon kanalı bulunamadı veya pasif.'];
    }
    if (!$gw->hazirMi()) {
        return ['gw' => null, 'hata' => $gw->getHata()];
    }
    return ['gw' => $gw, 'hata' => null];
}

/** Google tarih metni (Y-m-d H:i:s) -> sayfadaki biçim */
function googleTarih($deger) {
    return $deger ? date('d.m.Y H:i', strtotime($deger)) : null;
}

/** Google işlemleri için log; hesap ID'si INT'e sığmadığı için harici_id kolonuna yazılır */
function googleLog($db, $userId, $sistemId, $personelId, $googleId, $email, $adSoyad, $eslesme, $islem, $mesaj) {
    $db->insert('dbo.CRM_Hesap_Islem_Log', [
        'CRM_Hesap_Islem_Log_sistem_id'     => (int)$sistemId,
        'CRM_Hesap_Islem_Log_kullanici_id'  => $personelId ? (int)$personelId : null,
        'CRM_Hesap_Islem_Log_crm_harici_id' => $googleId !== '' ? $googleId : null,
        'CRM_Hesap_Islem_Log_crm_email'     => $email ?: null,
        'CRM_Hesap_Islem_Log_crm_adsoyad'   => $adSoyad ?: null,
        'CRM_Hesap_Islem_Log_eslesme_tipi'  => $eslesme ?: null,
        'CRM_Hesap_Islem_Log_islem'         => $islem,
        'CRM_Hesap_Islem_Log_mesaj'         => $mesaj ? mb_substr($mesaj, 0, 500) : null,
        'OlusturanKullanici'                => $userId
    ]);
}

/**
 * Yapılandırılan kolon adlarını hedef tabloda gerçekten var olanlarla eşler.
 * Olmayan kolon için null döner, böylece eksik kolon sayfayı hataya düşürmez.
 */
function crmKolonEsle($sistem, $mevcut) {
    $coz = function ($alan) use ($sistem, $mevcut) {
        $ad = sqlAd($sistem[$alan] ?? '');
        if ($ad === '') return null;
        return $mevcut[mb_strtolower($ad, 'UTF-8')] ?? null;
    };

    return [
        'id'          => $coz('CRM_Sistemleri_kolon_id'),
        'durum'       => $coz('CRM_Sistemleri_kolon_durum'),
        'ad'          => $coz('CRM_Sistemleri_kolon_ad'),
        'soyad'       => $coz('CRM_Sistemleri_kolon_soyad'),
        'email'       => $coz('CRM_Sistemleri_kolon_email'),
        'tc'          => $coz('CRM_Sistemleri_kolon_tc'),
        'songiris'    => $coz('CRM_Sistemleri_kolon_songiris'),
        'departman'   => $coz('CRM_Sistemleri_kolon_departman_id'),
        'sifre'       => $coz('CRM_Sistemleri_kolon_sifre'),
        'sifre_degis' => $coz('CRM_Sistemleri_kolon_sifre_degistir')
    ];
}

/** Portal'da aktif olan personelleri getir (hesap açma için) */
function aktifPersoneller($db) {
    return $db->fetchAll("
        SELECT
            k.kullanici_id,
            k.kullanici_ad,
            k.kullanici_soyad,
            k.kullanici_email,
            k.kullanici_tc_kimlik_no,
            d.departman_adi
        FROM kullanicilar k
        LEFT JOIN Departmanlar d ON d.departman_id = k.kullanici_departman_id
        WHERE k.kullanici_durum = 1
        ORDER BY k.kullanici_ad, k.kullanici_soyad
    ");
}

/** Tek personeli getir */
function personelGetir($db, $id) {
    return $db->fetchOne("
        SELECT
            k.kullanici_id,
            k.kullanici_ad,
            k.kullanici_soyad,
            k.kullanici_email,
            k.kullanici_tc_kimlik_no,
            k.kullanici_durum,
            d.departman_adi
        FROM kullanicilar k
        LEFT JOIN Departmanlar d ON d.departman_id = k.kullanici_departman_id
        WHERE k.kullanici_id = ?
    ", [(int)$id]);
}

/**
 * Hedef CRM'de personeli arar: e-posta -> geçerli TC -> ad soyad.
 * Aktif/pasif ayrımı yapmaz, bulunan ilk kaydı döndürür.
 */
function crmKisiBul($conn, $sistem, $kolon, $personel, $ekEpostalar = []) {
    $tablo = sqlAd($sistem['CRM_Sistemleri_tablo']);

    $secim = ["[{$kolon['id']}] AS crm_id", "[{$kolon['durum']}] AS crm_durum"];
    $secim[] = $kolon['ad']    ? "[{$kolon['ad']}] AS crm_ad"       : "CAST(NULL AS NVARCHAR(200)) AS crm_ad";
    $secim[] = $kolon['soyad'] ? "[{$kolon['soyad']}] AS crm_soyad" : "CAST(NULL AS NVARCHAR(200)) AS crm_soyad";
    $secim[] = $kolon['email'] ? "[{$kolon['email']}] AS crm_email" : "CAST(NULL AS NVARCHAR(200)) AS crm_email";
    $secim[] = $kolon['tc']    ? "[{$kolon['tc']}] AS crm_tc"       : "CAST(NULL AS NVARCHAR(20)) AS crm_tc";

    $sorgula = function ($kosul, $params) use ($conn, $secim, $tablo) {
        $sql = "SELECT TOP 2 " . implode(', ', $secim) . " FROM dbo.[{$tablo}] WHERE {$kosul}";
        $stmt = @sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) return [];

        $satirlar = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $satirlar[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $satirlar;
    };

    // 1) E-posta (once kurumsal adres, sonra portaldaki adres)
    if ($kolon['email']) {
        $denenecek = [];
        foreach (array_merge($ekEpostalar, [$personel['kullanici_email']]) as $aday) {
            $aday = nrmEmail($aday);
            if ($aday !== '' && !in_array($aday, $denenecek, true)) $denenecek[] = $aday;
        }

        foreach ($denenecek as $email) {
            $bulunan = $sorgula("LOWER(LTRIM(RTRIM([{$kolon['email']}]))) = ?", [$email]);
            if (count($bulunan) === 1) return ['kayit' => $bulunan[0], 'tip' => 'EMAIL'];
        }
    }

    // 2) TC kimlik (checksum'dan geçen)
    $tc = nrmTc($personel['kullanici_tc_kimlik_no']);
    if ($tc !== '' && $kolon['tc']) {
        $bulunan = $sorgula("[{$kolon['tc']}] = ?", [$tc]);
        if (count($bulunan) === 1) return ['kayit' => $bulunan[0], 'tip' => 'TC'];
    }

    // 3) Ad soyad
    if ($kolon['ad'] && $kolon['soyad']) {
        $bulunan = $sorgula("[{$kolon['ad']}] = ? AND [{$kolon['soyad']}] = ?", [
            trim($personel['kullanici_ad']),
            trim($personel['kullanici_soyad'])
        ]);
        if (count($bulunan) === 1) return ['kayit' => $bulunan[0], 'tip' => 'ADSOYAD'];
    }

    return null;
}

/**
 * Kurumsal e-posta üret: ad ve soyadın bütün kelimeleri noktayla birleşir.
 * "Alper Çağan" + "Aydın" -> alper.cagan.aydin@<alan>
 * Alan adı sistem bazında tanım tablosundan gelir.
 */
function kurumsalEposta($ad, $soyad, $alan) {
    $alan = trim((string)$alan);
    if ($alan === '') return '';

    $parcala = function ($v) {
        $v = nrmAd($v);                 // Türkçe karakterler sadeleşir
        if ($v === '') return [];
        return array_filter(explode(' ', $v));
    };

    $kelimeler = array_merge($parcala($ad), $parcala($soyad));
    if (count($kelimeler) < 2) return '';

    return implode('.', $kelimeler) . '@' . ltrim($alan, '@');
}

/**
 * Hesap açmada kullanılacak e-posta: ekrandan elle girilmişse o, yoksa otomatik üretilen.
 * Elle girilen adres geçerli olmalı ve sistemin alan adıyla bitmeli.
 * Dönüş: ['email' => ..., 'hata' => null|string]
 */
function hesapEpostasi($personel, $alan, $girilen) {
    $girilen = nrmEmail($girilen);
    if ($girilen === '') {
        $uretilen = kurumsalEposta($personel['kullanici_ad'], $personel['kullanici_soyad'], $alan);
        return ['email' => $uretilen, 'hata' => $uretilen === '' ? 'Kurumsal e-posta üretilemedi. Personelin ad ve soyad alanları dolu olmalı, sistemin e-posta alan adı tanımlı olmalı.' : null];
    }

    if (!filter_var($girilen, FILTER_VALIDATE_EMAIL)) {
        return ['email' => '', 'hata' => 'Girilen CRM e-postası geçerli değil!'];
    }

    $alan = mb_strtolower(ltrim(trim((string)$alan), '@'), 'UTF-8');
    if ($alan !== '' && substr($girilen, -strlen('@' . $alan)) !== '@' . $alan) {
        return ['email' => '', 'hata' => 'CRM e-postası @' . $alan . ' ile bitmeli!'];
    }

    return ['email' => $girilen, 'hata' => null];
}

/** Sistemin giriş linki (tanımdaki URL, şema yoksa https eklenir) */
function sistemGirisUrl($sistem) {
    $url = trim((string)($sistem['CRM_Sistemleri_url'] ?? ''));
    if ($url === '') return null;
    return preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
}

/** Hedef CRM'de bu e-postayı kullanan kayıt var mı */
function crmEpostaSahibi($conn, $sistem, $kolon, $eposta) {
    if (!$kolon['email']) return null;

    $tablo = sqlAd($sistem['CRM_Sistemleri_tablo']);
    $sql = "SELECT TOP 1 [{$kolon['id']}] AS crm_id, [{$kolon['durum']}] AS crm_durum
            FROM dbo.[{$tablo}]
            WHERE LOWER(LTRIM(RTRIM([{$kolon['email']}]))) = ?";
    $stmt = @sqlsrv_query($conn, $sql, [$eposta]);
    if ($stmt === false) return null;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

/** Yeni hesap için şifre üret */
function sifreUret($uzunluk = 12) {
    $havuz = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $sifre = '';
    for ($i = 0; $i < $uzunluk; $i++) {
        $sifre .= $havuz[random_int(0, strlen($havuz) - 1)];
    }
    return $sifre;
}

/** Portal'da pasif olan personelleri getir */
function pasifPersoneller($db) {
    return $db->fetchAll("
        SELECT
            k.kullanici_id,
            k.kullanici_ad,
            k.kullanici_soyad,
            k.kullanici_email,
            k.kullanici_tc_kimlik_no,
            k.kullanici_ise_cikis_tarihi
        FROM kullanicilar k
        WHERE k.kullanici_durum = 0
        ORDER BY k.kullanici_ad, k.kullanici_soyad
    ");
}

/**
 * Tüm CRM sistemlerini tarar, pasif personellerin hâlâ aktif olan
 * hesaplarını döndürür. Google Workspace'te askıdaki hesaplar da döner
 * (askida = true); silme işlemi bunlar üzerinden yapılır.
 */
function crmTara($db, $userId) {
    $sistemler = crmSistemleri($db);
    $personeller = pasifPersoneller($db);

    // Eşleştirme indeksleri.
    // Aynı değer birden fazla personelde geçiyorsa (adaş, ortak e-posta,
    // hatalı girilmiş TC) hangisi olduğu belirsizdir; o değer indeksten çıkarılır.
    $emailMap = [];
    $tcMap = [];
    $adMap = [];
    $yerelMap = [];   // Google: e-postanın @ öncesi (ad.soyad) -> personel

    foreach ($personeller as $p) {
        $email = nrmEmail($p['kullanici_email']);
        if ($email !== '') {
            $emailMap[$email] = isset($emailMap[$email]) ? false : $p;
        }

        $tc = nrmTc($p['kullanici_tc_kimlik_no']);
        if ($tc !== '') {
            $tcMap[$tc] = isset($tcMap[$tc]) ? false : $p;
        }

        $ad = nrmAd(trim($p['kullanici_ad'] . ' ' . $p['kullanici_soyad']));
        if ($ad !== '' && strpos($ad, ' ') !== false) {
            $adMap[$ad] = isset($adMap[$ad]) ? false : $p;
        }

        $yerel = strstr(kurumsalEposta($p['kullanici_ad'], $p['kullanici_soyad'], 'x'), '@', true);
        if ($yerel) {
            $yerelMap[$yerel] = isset($yerelMap[$yerel]) ? false : $p;
        }
    }

    $emailMap = array_filter($emailMap);
    $tcMap = array_filter($tcMap);
    $adMap = array_filter($adMap);
    $yerelMap = array_filter($yerelMap);

    $bulgular = [];
    $sistemDurumlari = [];

    foreach ($sistemler as $s) {
        $sistemAdi = $s['CRM_Sistemleri_ad'];
        $google = googleMi($s);

        if ($google) {
            // Google Workspace: aktif + askıdaki bütün hesaplar API'den
            $g = googleIstemci($s, $userId);
            $liste = $g['gw'] ? $g['gw']->kullanicilar(false) : ['success' => false, 'message' => $g['hata']];

            if (!$liste['success']) {
                $sistemDurumlari[] = [
                    'sistem_id'  => (int)$s['CRM_Sistemleri_id'],
                    'sistem_adi' => $sistemAdi,
                    'durum'      => 'HATA',
                    'mesaj'      => $liste['message'],
                    'aktif'      => 0,
                    'bulgu'      => 0
                ];
                continue;
            }

            $satirlar = [];
            $crmTcSayac = [];
            $aktifSayi = 0;
            foreach ($liste['data'] as $u) {
                if (!$u['askida']) $aktifSayi++;
                $satirlar[] = [
                    'crm_id'       => $u['id'],
                    'crm_ad'       => $u['ad'],
                    'crm_soyad'    => $u['soyad'],
                    'crm_email'    => $u['email'],
                    'crm_tc'       => null,
                    'crm_songiris' => googleTarih($u['son_giris']),
                    'askida'       => $u['askida']
                ];
            }
        } else {
        $baglanti = crmBaglan($s);

        if ($baglanti['conn'] === false) {
            $sistemDurumlari[] = [
                'sistem_id'  => (int)$s['CRM_Sistemleri_id'],
                'sistem_adi' => $sistemAdi,
                'durum'      => 'HATA',
                'mesaj'      => $baglanti['hata'],
                'aktif'      => 0,
                'bulgu'      => 0
            ];
            continue;
        }

        $conn = $baglanti['conn'];
        $tablo = sqlAd($s['CRM_Sistemleri_tablo']);
        $mevcut = crmKolonlari($conn, $tablo);

        // Yapılandırılan kolonlardan gerçekten var olanları seç
        $kolon   = crmKolonEsle($s, $mevcut);
        $kId     = $kolon['id'];
        $kDurum  = $kolon['durum'];
        $kAd     = $kolon['ad'];
        $kSoyad  = $kolon['soyad'];
        $kEmail  = $kolon['email'];
        $kTc     = $kolon['tc'];
        $kGiris  = $kolon['songiris'];

        if (!$kId || !$kDurum) {
            sqlsrv_close($conn);
            $sistemDurumlari[] = [
                'sistem_id'  => (int)$s['CRM_Sistemleri_id'],
                'sistem_adi' => $sistemAdi,
                'durum'      => 'HATA',
                'mesaj'      => "dbo.{$tablo} tablosunda id veya durum kolonu bulunamadı.",
                'aktif'      => 0,
                'bulgu'      => 0
            ];
            continue;
        }

        $secim = ["[{$kId}] AS crm_id", "[{$kDurum}] AS crm_durum"];
        $secim[] = $kAd    ? "[{$kAd}] AS crm_ad"          : "CAST(NULL AS NVARCHAR(200)) AS crm_ad";
        $secim[] = $kSoyad ? "[{$kSoyad}] AS crm_soyad"    : "CAST(NULL AS NVARCHAR(200)) AS crm_soyad";
        $secim[] = $kEmail ? "[{$kEmail}] AS crm_email"    : "CAST(NULL AS NVARCHAR(200)) AS crm_email";
        $secim[] = $kTc    ? "[{$kTc}] AS crm_tc"          : "CAST(NULL AS NVARCHAR(20)) AS crm_tc";
        $secim[] = $kGiris ? "[{$kGiris}] AS crm_songiris" : "CAST(NULL AS DATETIME) AS crm_songiris";

        $sql = "SELECT " . implode(', ', $secim) . " FROM dbo.[{$tablo}] WHERE [{$kDurum}] = ?";
        $stmt = @sqlsrv_query($conn, $sql, [$s['CRM_Sistemleri_aktif_deger']]);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            sqlsrv_close($conn);
            $sistemDurumlari[] = [
                'sistem_id'  => (int)$s['CRM_Sistemleri_id'],
                'sistem_adi' => $sistemAdi,
                'durum'      => 'HATA',
                'mesaj'      => trim($errors[0]['message'] ?? 'Sorgu çalıştırılamadı'),
                'aktif'      => 0,
                'bulgu'      => 0
            ];
            continue;
        }

        // Satırlar önce belleğe alınır; geçerli bir TC aynı sistemde birden fazla
        // hesapta görünüyorsa o değer güvenilmezdir (dolgu / hatalı giriş) ve
        // TC eşleştirmesinden çıkarılır.
        $satirlar = [];
        $crmTcSayac = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $satirlar[] = $row;
            $tc = nrmTc($row['crm_tc']);
            if ($tc !== '') {
                $crmTcSayac[$tc] = ($crmTcSayac[$tc] ?? 0) + 1;
            }
        }

        $aktifSayi = count($satirlar);
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        } // MSSQL

        $bulguSayi = 0;

        foreach ($satirlar as $row) {
            $eslesme = null;
            $personel = null;

            $email = nrmEmail($row['crm_email']);
            if ($email !== '' && isset($emailMap[$email])) {
                $eslesme = 'EMAIL';
                $personel = $emailMap[$email];
            }

            // Google: kurumsal adresin ad.soyad kısmı portal ad soyadından üretilenle aynı mı
            if (!$personel && $google && $email !== '') {
                $yerel = strstr($email, '@', true);
                if (isset($yerelMap[$yerel])) {
                    $eslesme = 'EMAIL';
                    $personel = $yerelMap[$yerel];
                }
            }

            if (!$personel) {
                $tc = nrmTc($row['crm_tc']);
                if ($tc !== '' && ($crmTcSayac[$tc] ?? 0) === 1 && isset($tcMap[$tc])) {
                    $eslesme = 'TC';
                    $personel = $tcMap[$tc];
                }
            }

            if (!$personel) {
                $ad = nrmAd(trim(($row['crm_ad'] ?? '') . ' ' . ($row['crm_soyad'] ?? '')));
                if ($ad !== '' && isset($adMap[$ad])) {
                    $eslesme = 'ADSOYAD';
                    $personel = $adMap[$ad];
                }
            }

            if (!$personel) continue;

            $bulguSayi++;
            $bulgular[] = [
                'satir_id'        => $s['CRM_Sistemleri_id'] . '_' . $row['crm_id'],
                'sistem_id'       => (int)$s['CRM_Sistemleri_id'],
                'sistem_adi'      => $sistemAdi,
                'sistem_url'      => $s['CRM_Sistemleri_url'],
                'kullanici_id'    => (int)$personel['kullanici_id'],
                'personel_adi'    => trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']),
                'personel_email'  => $personel['kullanici_email'],
                'cikis_tarihi'    => tarihMetin($personel['kullanici_ise_cikis_tarihi']),
                // Google ID'si 21 haneli metin; (int) çevrilirse bozulur
                'crm_kullanici_id'=> $google ? (string)$row['crm_id'] : (int)$row['crm_id'],
                'crm_adsoyad'     => trim(($row['crm_ad'] ?? '') . ' ' . ($row['crm_soyad'] ?? '')),
                'crm_email'       => $row['crm_email'],
                'crm_songiris'    => $google ? $row['crm_songiris'] : tarihMetin($row['crm_songiris']),
                'eslesme_tipi'    => $eslesme,
                'sistem_tip'      => $google ? 'GOOGLE' : 'MSSQL',
                'askida'          => !empty($row['askida'])
            ];
        }

        $sistemDurumlari[] = [
            'sistem_id'  => (int)$s['CRM_Sistemleri_id'],
            'sistem_adi' => $sistemAdi,
            'durum'      => 'OK',
            'mesaj'      => null,
            'aktif'      => $aktifSayi,
            'bulgu'      => $bulguSayi
        ];
    }

    return [
        'bulgular'         => $bulgular,
        'sistem_durumlari' => $sistemDurumlari,
        'pasif_personel'   => count($personeller)
    ];
}

// ===================================================================
// AJAX İşlemleri
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {

            case 'tara':
                $sonuc = crmTara($db, $user['kullanici_id']);

                $hataliSistem = 0;
                foreach ($sonuc['sistem_durumlari'] as $sd) {
                    if ($sd['durum'] === 'HATA') $hataliSistem++;
                }

                // Askıdaki Google hesapları açık sayılmaz (yalnız silme için listelenir)
                $etkilenenPersonel = [];
                $acikHesap = 0;
                foreach ($sonuc['bulgular'] as $b) {
                    if ($b['askida']) continue;
                    $acikHesap++;
                    $etkilenenPersonel[$b['kullanici_id']] = true;
                }

                echo json_encode([
                    'success' => true,
                    'data'    => $sonuc['bulgular'],
                    'sistemler' => $sonuc['sistem_durumlari'],
                    'stats'   => [
                        'pasif_personel'     => $sonuc['pasif_personel'],
                        'acik_hesap'         => $acikHesap,
                        'etkilenen_personel' => count($etkilenenPersonel),
                        'hatali_sistem'      => $hataliSistem,
                        'toplam_sistem'      => count($sonuc['sistem_durumlari'])
                    ]
                ]);
                break;

            case 'pasife_al':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Pasife alma yetkiniz yok!']);
                    break;
                }

                $secimler = json_decode($_POST['secimler'] ?? '[]', true);
                if (!is_array($secimler) || count($secimler) === 0) {
                    echo json_encode(['success' => false, 'message' => 'Pasife alınacak hesap seçilmedi!']);
                    break;
                }

                // Sistemleri id -> kayıt olarak hazırla
                $sistemler = [];
                foreach (crmSistemleri($db) as $s) {
                    $sistemler[(int)$s['CRM_Sistemleri_id']] = $s;
                }

                // Seçimleri sisteme göre grupla (her sisteme tek bağlantı)
                $grup = [];
                foreach ($secimler as $sec) {
                    $sid = (int)($sec['sistem_id'] ?? 0);
                    $cid = trim((string)($sec['crm_kullanici_id'] ?? ''));
                    if ($sid <= 0 || $cid === '' || !isset($sistemler[$sid])) continue;
                    // CRM ID'si sayı, Google ID'si metin (rakamlardan oluşur, INT'e sığmaz)
                    if (!ctype_digit($cid) || (!googleMi($sistemler[$sid]) && (int)$cid <= 0)) continue;
                    $grup[$sid][] = $sec;
                }

                $basarili = 0;
                $basarisiz = 0;
                $detaylar = [];

                foreach ($grup as $sid => $kayitlar) {
                    $s = $sistemler[$sid];

                    // Google Workspace: hesap askıya alınır
                    if (googleMi($s)) {
                        $g = googleIstemci($s, $user['kullanici_id']);

                        foreach ($kayitlar as $k) {
                            $gid = (string)$k['crm_kullanici_id'];
                            $etiket = $s['CRM_Sistemleri_ad'] . ' / ' . ($k['crm_adsoyad'] ?? $gid);

                            $r = $g['gw'] ? $g['gw']->askiyaAl($gid) : ['success' => false, 'message' => $g['hata']];

                            if ($r['success']) {
                                $basarili++;
                            } else {
                                $basarisiz++;
                                $detaylar[] = $etiket . ': ' . $r['message'];
                            }

                            googleLog($db, $user['kullanici_id'], $sid, $k['kullanici_id'] ?? null, $gid,
                                $k['crm_email'] ?? null, $k['crm_adsoyad'] ?? null, $k['eslesme_tipi'] ?? null,
                                $r['success'] ? 'ASKIYA_ALINDI' : 'HATA',
                                $r['success'] ? null : 'Askiya alma: ' . $r['message']);
                        }
                        continue;
                    }

                    $baglanti = crmBaglan($s);

                    if ($baglanti['conn'] === false) {
                        foreach ($kayitlar as $k) {
                            $basarisiz++;
                            $detaylar[] = $s['CRM_Sistemleri_ad'] . ': ' . $baglanti['hata'];
                            $db->insert('dbo.CRM_Hesap_Islem_Log', [
                                'CRM_Hesap_Islem_Log_sistem_id'        => $sid,
                                'CRM_Hesap_Islem_Log_kullanici_id'     => !empty($k['kullanici_id']) ? (int)$k['kullanici_id'] : null,
                                'CRM_Hesap_Islem_Log_crm_kullanici_id' => (int)$k['crm_kullanici_id'],
                                'CRM_Hesap_Islem_Log_crm_email'        => $k['crm_email'] ?? null,
                                'CRM_Hesap_Islem_Log_crm_adsoyad'      => $k['crm_adsoyad'] ?? null,
                                'CRM_Hesap_Islem_Log_eslesme_tipi'     => $k['eslesme_tipi'] ?? null,
                                'CRM_Hesap_Islem_Log_islem'            => 'HATA',
                                'CRM_Hesap_Islem_Log_mesaj'            => mb_substr('Baglanti hatasi: ' . $baglanti['hata'], 0, 500),
                                'OlusturanKullanici'                   => $user['kullanici_id']
                            ]);
                        }
                        continue;
                    }

                    $conn = $baglanti['conn'];
                    $tablo  = sqlAd($s['CRM_Sistemleri_tablo']);
                    $mevcut = crmKolonlari($conn, $tablo);

                    $kId    = $mevcut[mb_strtolower(sqlAd($s['CRM_Sistemleri_kolon_id']), 'UTF-8')] ?? null;
                    $kDurum = $mevcut[mb_strtolower(sqlAd($s['CRM_Sistemleri_kolon_durum']), 'UTF-8')] ?? null;

                    if (!$kId || !$kDurum) {
                        sqlsrv_close($conn);
                        foreach ($kayitlar as $k) {
                            $basarisiz++;
                        }
                        $detaylar[] = $s['CRM_Sistemleri_ad'] . ': kolon eşlemesi hatalı.';
                        continue;
                    }

                    // Sadece hâlâ aktif olan kayıtlar güncellenir
                    $sql = "UPDATE dbo.[{$tablo}] SET [{$kDurum}] = ? WHERE [{$kId}] = ? AND [{$kDurum}] = ?";

                    foreach ($kayitlar as $k) {
                        $params = [
                            $s['CRM_Sistemleri_pasif_deger'],
                            (int)$k['crm_kullanici_id'],
                            $s['CRM_Sistemleri_aktif_deger']
                        ];
                        $stmt = @sqlsrv_query($conn, $sql, $params);

                        if ($stmt === false) {
                            $errors = sqlsrv_errors();
                            $mesaj = trim($errors[0]['message'] ?? 'Guncelleme hatasi');
                            $basarisiz++;
                            $detaylar[] = $s['CRM_Sistemleri_ad'] . ' / ' . ($k['crm_adsoyad'] ?? $k['crm_kullanici_id']) . ': ' . $mesaj;
                            $islem = 'HATA';
                        } else {
                            $etkilenen = sqlsrv_rows_affected($stmt);
                            sqlsrv_free_stmt($stmt);

                            if ($etkilenen > 0) {
                                $basarili++;
                                $islem = 'PASIFE_ALINDI';
                                $mesaj = null;
                            } else {
                                $basarisiz++;
                                $islem = 'HATA';
                                $mesaj = 'Kayit bulunamadi veya zaten pasif.';
                                $detaylar[] = $s['CRM_Sistemleri_ad'] . ' / ' . ($k['crm_adsoyad'] ?? $k['crm_kullanici_id']) . ': ' . $mesaj;
                            }
                        }

                        $db->insert('dbo.CRM_Hesap_Islem_Log', [
                            'CRM_Hesap_Islem_Log_sistem_id'        => $sid,
                            'CRM_Hesap_Islem_Log_kullanici_id'     => !empty($k['kullanici_id']) ? (int)$k['kullanici_id'] : null,
                            'CRM_Hesap_Islem_Log_crm_kullanici_id' => (int)$k['crm_kullanici_id'],
                            'CRM_Hesap_Islem_Log_crm_email'        => $k['crm_email'] ?? null,
                            'CRM_Hesap_Islem_Log_crm_adsoyad'      => $k['crm_adsoyad'] ?? null,
                            'CRM_Hesap_Islem_Log_eslesme_tipi'     => $k['eslesme_tipi'] ?? null,
                            'CRM_Hesap_Islem_Log_islem'            => $islem,
                            'CRM_Hesap_Islem_Log_mesaj'            => $mesaj ? mb_substr($mesaj, 0, 500) : null,
                            'OlusturanKullanici'                   => $user['kullanici_id']
                        ]);
                    }

                    sqlsrv_close($conn);
                }

                echo json_encode([
                    'success'   => true,
                    'basarili'  => $basarili,
                    'basarisiz' => $basarisiz,
                    'detaylar'  => array_slice($detaylar, 0, 20),
                    'message'   => $basarili . ' hesap pasife alındı' . ($basarisiz > 0 ? ', ' . $basarisiz . ' işlem başarısız.' : '.')
                ]);
                break;

            case 'hesap_ac_verileri':
                // Modal açılışında gereken listeler
                $acilabilir = [];
                foreach (crmSistemleri($db) as $s) {
                    if (!empty($s['CRM_Sistemleri_hesap_acilabilir'])) {
                        $acilabilir[] = [
                            'id'  => (int)$s['CRM_Sistemleri_id'],
                            'ad'  => $s['CRM_Sistemleri_ad'],
                            'tip' => googleMi($s) ? 'GOOGLE' : 'MSSQL'
                        ];
                    }
                }

                $personeller = [];
                foreach (aktifPersoneller($db) as $p) {
                    $personeller[] = [
                        'id'        => (int)$p['kullanici_id'],
                        'ad'        => trim($p['kullanici_ad'] . ' ' . $p['kullanici_soyad']),
                        'email'     => $p['kullanici_email'],
                        'departman' => $p['departman_adi']
                    ];
                }

                echo json_encode([
                    'success'     => true,
                    'sistemler'   => $acilabilir,
                    'personeller' => $personeller
                ]);
                break;

            case 'crm_departmanlar':
                // Hedef CRM'in kendi departman listesi
                $sistemId = (int)($_POST['sistem_id'] ?? 0);
                $sistem = null;
                foreach (crmSistemleri($db) as $s) {
                    if ((int)$s['CRM_Sistemleri_id'] === $sistemId) { $sistem = $s; break; }
                }

                // Google: departman = organizasyon birimi; alan adı listesi de birlikte döner
                if ($sistem && googleMi($sistem)) {
                    $g = googleIstemci($sistem, $user['kullanici_id']);
                    if (!$g['gw']) {
                        echo json_encode(['success' => false, 'message' => $g['hata']]);
                        break;
                    }

                    $birimler = $g['gw']->birimler();
                    $alanlar = $g['gw']->alanAdlari();
                    if (!$birimler['success'] || !$alanlar['success']) {
                        echo json_encode(['success' => false, 'message' => $birimler['message'] ?: $alanlar['message']]);
                        break;
                    }

                    echo json_encode([
                        'success'  => true,
                        'data'     => array_map(fn($b) => ['id' => $b['yol'], 'ad' => $b['ad']], $birimler['data']),
                        'alanlar'  => array_column($alanlar['data'], 'alan'),
                        'varsayilan_alan' => $sistem['CRM_Sistemleri_eposta_alan']
                    ]);
                    break;
                }

                if (!$sistem || empty($sistem['CRM_Sistemleri_departman_tablo'])) {
                    echo json_encode(['success' => false, 'message' => 'Sistem için departman tablosu tanımlı değil!']);
                    break;
                }

                $baglanti = crmBaglan($sistem);
                if ($baglanti['conn'] === false) {
                    echo json_encode(['success' => false, 'message' => $baglanti['hata']]);
                    break;
                }

                $conn = $baglanti['conn'];
                $depTablo = sqlAd($sistem['CRM_Sistemleri_departman_tablo']);
                $dId    = sqlAd($sistem['CRM_Sistemleri_kolon_dep_id']);
                $dAd    = sqlAd($sistem['CRM_Sistemleri_kolon_dep_ad']);
                $dDurum = sqlAd($sistem['CRM_Sistemleri_kolon_dep_durum']);

                $sql = "SELECT [{$dId}] AS dep_id, [{$dAd}] AS dep_ad
                        FROM dbo.[{$depTablo}]
                        WHERE [{$dDurum}] = 1
                        ORDER BY [{$dAd}]";
                $stmt = @sqlsrv_query($conn, $sql);

                if ($stmt === false) {
                    $errors = sqlsrv_errors();
                    sqlsrv_close($conn);
                    echo json_encode(['success' => false, 'message' => trim($errors[0]['message'] ?? 'Departman listesi okunamadı')]);
                    break;
                }

                $departmanlar = [];
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $departmanlar[] = ['id' => (int)$row['dep_id'], 'ad' => $row['dep_ad']];
                }
                sqlsrv_free_stmt($stmt);
                sqlsrv_close($conn);

                echo json_encode(['success' => true, 'data' => $departmanlar]);
                break;

            case 'hesap_onizleme':
                // Hedef CRM'de kişi var mı, ne yapılacak?
                $sistemId = (int)($_POST['sistem_id'] ?? 0);
                $personelId = (int)($_POST['kullanici_id'] ?? 0);

                $sistem = null;
                foreach (crmSistemleri($db) as $s) {
                    if ((int)$s['CRM_Sistemleri_id'] === $sistemId) { $sistem = $s; break; }
                }
                $personel = personelGetir($db, $personelId);

                if (!$sistem || !$personel) {
                    echo json_encode(['success' => false, 'message' => 'Sistem veya personel bulunamadı!']);
                    break;
                }

                // Google'da alan adı ekrandan seçilir; CRM'lerde sistem tanımından gelir
                $alan = googleMi($sistem)
                    ? trim((string)($_POST['alan'] ?? $sistem['CRM_Sistemleri_eposta_alan']))
                    : $sistem['CRM_Sistemleri_eposta_alan'];

                $ep = hesapEpostasi($personel, $alan, $_POST['email'] ?? '');
                if ($ep['hata']) {
                    echo json_encode(['success' => false, 'message' => $ep['hata']]);
                    break;
                }
                $kurumsal = $ep['email'];

                if (googleMi($sistem)) {
                    $g = googleIstemci($sistem, $user['kullanici_id']);
                    $r = $g['gw'] ? $g['gw']->kullaniciGetir($kurumsal) : ['success' => false, 'message' => $g['hata']];

                    if (!$r['success']) {
                        echo json_encode(['success' => false, 'message' => $r['message']]);
                        break;
                    }

                    $cevap = [
                        'success'          => true,
                        'personel'         => trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']),
                        'email'            => $kurumsal,
                        'departman_portal' => $personel['departman_adi']
                    ];

                    if (!$r['data']) {
                        echo json_encode($cevap + ['durum' => 'YENI']);
                        break;
                    }

                    echo json_encode($cevap + [
                        'durum'            => $r['data']['askida'] ? 'PASIF_VAR' : 'AKTIF_VAR',
                        'eslesme_tipi'     => 'EMAIL',
                        'crm_kullanici_id' => $r['data']['id'],
                        'crm_adsoyad'      => trim($r['data']['ad'] . ' ' . $r['data']['soyad']),
                        'crm_email'        => $r['data']['email']
                    ]);
                    break;
                }

                $baglanti = crmBaglan($sistem);
                if ($baglanti['conn'] === false) {
                    echo json_encode(['success' => false, 'message' => $baglanti['hata']]);
                    break;
                }

                $conn = $baglanti['conn'];
                $mevcut = crmKolonlari($conn, sqlAd($sistem['CRM_Sistemleri_tablo']));
                $kolon = crmKolonEsle($sistem, $mevcut);

                if (!$kolon['id'] || !$kolon['durum'] || !$kolon['sifre']) {
                    sqlsrv_close($conn);
                    echo json_encode(['success' => false, 'message' => 'Sistemin kolon eşlemesi eksik (id / durum / şifre).']);
                    break;
                }

                $bulunan = crmKisiBul($conn, $sistem, $kolon, $personel, [$kurumsal]);

                if (!$bulunan) {
                    // Kurumsal adres baskasinda kayitliysa yeni hesap acilamaz
                    $sahip = crmEpostaSahibi($conn, $sistem, $kolon, $kurumsal);
                    sqlsrv_close($conn);

                    if ($sahip) {
                        echo json_encode([
                            'success' => false,
                            'message' => $kurumsal . ' adresi sistemde başka bir hesapta kayıtlı (#' . (int)$sahip['crm_id'] . '). Ad soyad aynıysa personel kartını kontrol edin.'
                        ]);
                        break;
                    }

                    echo json_encode([
                        'success'  => true,
                        'durum'    => 'YENI',
                        'personel' => trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']),
                        'email'    => $kurumsal,
                        'departman_portal' => $personel['departman_adi']
                    ]);
                    break;
                }

                sqlsrv_close($conn);

                $kayit = $bulunan['kayit'];
                $aktifMi = ((string)$kayit['crm_durum'] === (string)$sistem['CRM_Sistemleri_aktif_deger']);

                echo json_encode([
                    'success'      => true,
                    'durum'        => $aktifMi ? 'AKTIF_VAR' : 'PASIF_VAR',
                    'personel'     => trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']),
                    'email'        => $kurumsal,
                    'departman_portal' => $personel['departman_adi'],
                    'eslesme_tipi' => $bulunan['tip'],
                    'crm_kullanici_id' => (int)$kayit['crm_id'],
                    'crm_adsoyad'  => trim(($kayit['crm_ad'] ?? '') . ' ' . ($kayit['crm_soyad'] ?? '')),
                    'crm_email'    => $kayit['crm_email']
                ]);
                break;

            case 'hesap_ac':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Hesap açma yetkiniz yok!']);
                    break;
                }

                $sistemId    = (int)($_POST['sistem_id'] ?? 0);
                $personelId  = (int)($_POST['kullanici_id'] ?? 0);
                $departmanId = (int)($_POST['departman_id'] ?? 0);

                $sistem = null;
                foreach (crmSistemleri($db) as $s) {
                    if ((int)$s['CRM_Sistemleri_id'] === $sistemId) { $sistem = $s; break; }
                }
                $personel = personelGetir($db, $personelId);

                if (!$sistem || !$personel) {
                    echo json_encode(['success' => false, 'message' => 'Sistem veya personel bulunamadı!']);
                    break;
                }

                if (empty($sistem['CRM_Sistemleri_hesap_acilabilir'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu sistemde hesap açma kapalı!']);
                    break;
                }

                // ---------- Google Workspace ----------
                // departman_id = organizasyon birimi yolu (metin), alan = seçilen alan adı
                if (googleMi($sistem)) {
                    $g = googleIstemci($sistem, $user['kullanici_id']);
                    if (!$g['gw']) {
                        echo json_encode(['success' => false, 'message' => $g['hata']]);
                        break;
                    }
                    $gw = $g['gw'];

                    // Birim ve alan adı istemciden gelir; Google'daki listelerle doğrulanır
                    $birim = trim((string)($_POST['departman_id'] ?? ''));
                    $alan  = trim((string)($_POST['alan'] ?? ''));

                    $birimler = $gw->birimler();
                    $alanlar  = $gw->alanAdlari();
                    if (!$birimler['success'] || !$alanlar['success']) {
                        echo json_encode(['success' => false, 'message' => $birimler['message'] ?: $alanlar['message']]);
                        break;
                    }
                    if (!in_array($birim, array_column($birimler['data'], 'yol'), true)) {
                        echo json_encode(['success' => false, 'message' => 'Organizasyon birimi seçilmeli!']);
                        break;
                    }
                    if (!in_array($alan, array_column($alanlar['data'], 'alan'), true)) {
                        echo json_encode(['success' => false, 'message' => 'Geçerli bir alan adı seçilmeli!']);
                        break;
                    }

                    $ep = hesapEpostasi($personel, $alan, $_POST['email'] ?? '');
                    if ($ep['hata']) {
                        echo json_encode(['success' => false, 'message' => $ep['hata']]);
                        break;
                    }
                    $kurumsal = $ep['email'];

                    $adSoyad = trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']);
                    $mevcut = $gw->kullaniciGetir($kurumsal);
                    if (!$mevcut['success']) {
                        echo json_encode(['success' => false, 'message' => $mevcut['message']]);
                        break;
                    }
                    if ($mevcut['data'] && !$mevcut['data']['askida']) {
                        echo json_encode(['success' => false, 'message' => $kurumsal . ' hesabı Google\'da zaten aktif.']);
                        break;
                    }

                    $sifre = sifreUret();

                    if ($mevcut['data']) {
                        $islem = 'YENIDEN_AKTIF';
                        $r = $gw->askidanCikar($mevcut['data']['id'], $sifre, $birim);
                        $googleId = $mevcut['data']['id'];
                    } else {
                        $islem = 'HESAP_ACILDI';
                        $r = $gw->hesapAc($personel['kullanici_ad'], $personel['kullanici_soyad'], $kurumsal, $sifre, $birim);
                        $googleId = $r['success'] ? (string)($r['data']['id'] ?? '') : '';
                    }

                    if (!$r['success']) {
                        googleLog($db, $user['kullanici_id'], $sistemId, $personelId, $googleId, $kurumsal, $adSoyad,
                            $mevcut['data'] ? 'EMAIL' : null, 'HATA', 'Hesap acma: ' . $r['message']);
                        echo json_encode(['success' => false, 'message' => $r['message']]);
                        break;
                    }

                    // Lisans: hesap açıldı, lisans hatası işlemi geri almaz; uyarı olarak döner
                    $lisans = $gw->lisansAta($kurumsal);
                    $uyari = $lisans['success'] ? null : 'Lisans atanamadı: ' . $lisans['message'];

                    $mesaj = $islem === 'YENIDEN_AKTIF'
                        ? 'Askidaki hesap acildi, sifre sifirlandi. Birim: ' . $birim
                        : 'Yeni hesap olusturuldu, ilk giriste sifre degisimi zorunlu. Birim: ' . $birim;
                    if ($uyari) $mesaj .= '. ' . $uyari;

                    googleLog($db, $user['kullanici_id'], $sistemId, $personelId, $googleId, $kurumsal, $adSoyad,
                        $mevcut['data'] ? 'EMAIL' : null, $islem, $mesaj);

                    echo json_encode([
                        'success'          => true,
                        'islem'            => $islem,
                        'crm_kullanici_id' => $googleId,
                        'email'            => $kurumsal,
                        'giris_url'        => sistemGirisUrl($sistem),
                        'sifre'            => $sifre,
                        'uyari'            => $uyari,
                        'message'          => $islem === 'YENIDEN_AKTIF'
                            ? 'Google hesabı askıdan çıkarıldı ve şifresi sıfırlandı.'
                            : 'Google hesabı oluşturuldu.'
                    ]);
                    break;
                }

                if ($departmanId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Departman seçilmeli!']);
                    break;
                }

                $ep = hesapEpostasi($personel, $sistem['CRM_Sistemleri_eposta_alan'], $_POST['email'] ?? '');
                if ($ep['hata']) {
                    echo json_encode(['success' => false, 'message' => $ep['hata']]);
                    break;
                }
                $kurumsal = $ep['email'];

                $baglanti = crmBaglan($sistem);
                if ($baglanti['conn'] === false) {
                    echo json_encode(['success' => false, 'message' => $baglanti['hata']]);
                    break;
                }

                $conn = $baglanti['conn'];
                $tablo = sqlAd($sistem['CRM_Sistemleri_tablo']);
                $mevcut = crmKolonlari($conn, $tablo);
                $kolon = crmKolonEsle($sistem, $mevcut);

                if (!$kolon['id'] || !$kolon['durum'] || !$kolon['sifre']) {
                    sqlsrv_close($conn);
                    echo json_encode(['success' => false, 'message' => 'Sistemin kolon eşlemesi eksik (id / durum / şifre).']);
                    break;
                }

                $sifre = sifreUret();
                $hash = password_hash($sifre, PASSWORD_DEFAULT);
                $bulunan = crmKisiBul($conn, $sistem, $kolon, $personel, [$kurumsal]);

                // Mevcut hesap aktifse dokunma
                if ($bulunan) {
                    $kayit = $bulunan['kayit'];
                    $aktifMi = ((string)$kayit['crm_durum'] === (string)$sistem['CRM_Sistemleri_aktif_deger']);

                    if ($aktifMi) {
                        sqlsrv_close($conn);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Bu personelin sistemde zaten aktif hesabı var (#' . (int)$kayit['crm_id'] . ').'
                        ]);
                        break;
                    }

                    // Pasif hesabı yeniden aktif et, şifreyi sıfırla
                    $set = ["[{$kolon['durum']}] = ?", "[{$kolon['sifre']}] = ?"];
                    $params = [$sistem['CRM_Sistemleri_aktif_deger'], $hash];

                    if ($kolon['sifre_degis']) { $set[] = "[{$kolon['sifre_degis']}] = 1"; }
                    if ($kolon['departman'])   { $set[] = "[{$kolon['departman']}] = ?"; $params[] = $departmanId; }
                    if ($kolon['email'])       { $set[] = "[{$kolon['email']}] = ?";     $params[] = $kurumsal; }

                    $params[] = (int)$kayit['crm_id'];
                    $sql = "UPDATE dbo.[{$tablo}] SET " . implode(', ', $set) . " WHERE [{$kolon['id']}] = ?";
                    $stmt = @sqlsrv_query($conn, $sql, $params);

                    if ($stmt === false) {
                        $errors = sqlsrv_errors();
                        sqlsrv_close($conn);
                        echo json_encode(['success' => false, 'message' => trim($errors[0]['message'] ?? 'Hesap aktifleştirilemedi')]);
                        break;
                    }
                    sqlsrv_free_stmt($stmt);
                    sqlsrv_close($conn);

                    $db->insert('dbo.CRM_Hesap_Islem_Log', [
                        'CRM_Hesap_Islem_Log_sistem_id'        => $sistemId,
                        'CRM_Hesap_Islem_Log_kullanici_id'     => $personelId,
                        'CRM_Hesap_Islem_Log_crm_kullanici_id' => (int)$kayit['crm_id'],
                        'CRM_Hesap_Islem_Log_crm_email'        => $kurumsal,
                        'CRM_Hesap_Islem_Log_crm_adsoyad'      => trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']),
                        'CRM_Hesap_Islem_Log_eslesme_tipi'     => $bulunan['tip'],
                        'CRM_Hesap_Islem_Log_islem'            => 'YENIDEN_AKTIF',
                        'CRM_Hesap_Islem_Log_mesaj'            => 'Mevcut pasif hesap aktife alindi, sifre sifirlandi.',
                        'OlusturanKullanici'                   => $user['kullanici_id']
                    ]);

                    echo json_encode([
                        'success'  => true,
                        'islem'    => 'YENIDEN_AKTIF',
                        'crm_kullanici_id' => (int)$kayit['crm_id'],
                        'email'    => $kolon['email'] ? $kurumsal : nrmEmail($kayit['crm_email']),
                        'giris_url' => sistemGirisUrl($sistem),
                        'sifre'    => $sifre,
                        'message'  => 'Mevcut hesap yeniden aktif edildi ve şifresi sıfırlandı.'
                    ]);
                    break;
                }

                // Yeni hesap - kurumsal adres baskasinda kayitli olmamali
                $sahip = crmEpostaSahibi($conn, $sistem, $kolon, $kurumsal);
                if ($sahip) {
                    sqlsrv_close($conn);
                    echo json_encode([
                        'success' => false,
                        'message' => $kurumsal . ' adresi sistemde başka bir hesapta kayıtlı (#' . (int)$sahip['crm_id'] . ').'
                    ]);
                    break;
                }

                $kolonlar = ["[{$kolon['sifre']}]", "[{$kolon['durum']}]"];
                $degerler = ['?', '?'];
                $params = [$hash, $sistem['CRM_Sistemleri_aktif_deger']];

                $ekle = function ($kolonAdi, $deger) use (&$kolonlar, &$degerler, &$params) {
                    if (!$kolonAdi) return;
                    $kolonlar[] = "[{$kolonAdi}]";
                    $degerler[] = '?';
                    $params[] = $deger;
                };

                $ekle($kolon['ad'], trim($personel['kullanici_ad']));
                $ekle($kolon['soyad'], trim($personel['kullanici_soyad']));
                $ekle($kolon['email'], $kurumsal);
                $ekle($kolon['departman'], $departmanId);

                $tc = nrmTc($personel['kullanici_tc_kimlik_no']);
                if ($tc !== '') $ekle($kolon['tc'], $tc);

                if ($kolon['sifre_degis']) {
                    $kolonlar[] = "[{$kolon['sifre_degis']}]";
                    $degerler[] = '1';
                }

                $sql = "INSERT INTO dbo.[{$tablo}] (" . implode(', ', $kolonlar) . ")
                        OUTPUT INSERTED.[{$kolon['id']}] AS yeni_id
                        VALUES (" . implode(', ', $degerler) . ")";
                $stmt = @sqlsrv_query($conn, $sql, $params);

                if ($stmt === false) {
                    $errors = sqlsrv_errors();
                    $mesaj = trim($errors[0]['message'] ?? 'Hesap olusturulamadi');
                    sqlsrv_close($conn);

                    $db->insert('dbo.CRM_Hesap_Islem_Log', [
                        'CRM_Hesap_Islem_Log_sistem_id'    => $sistemId,
                        'CRM_Hesap_Islem_Log_kullanici_id' => $personelId,
                        'CRM_Hesap_Islem_Log_crm_email'    => $kurumsal,
                        'CRM_Hesap_Islem_Log_crm_adsoyad'  => trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']),
                        'CRM_Hesap_Islem_Log_islem'        => 'HATA',
                        'CRM_Hesap_Islem_Log_mesaj'        => mb_substr('Hesap acma: ' . $mesaj, 0, 500),
                        'OlusturanKullanici'               => $user['kullanici_id']
                    ]);

                    echo json_encode(['success' => false, 'message' => $mesaj]);
                    break;
                }

                $yeni = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                $yeniId = $yeni ? (int)$yeni['yeni_id'] : null;
                sqlsrv_free_stmt($stmt);
                sqlsrv_close($conn);

                $db->insert('dbo.CRM_Hesap_Islem_Log', [
                    'CRM_Hesap_Islem_Log_sistem_id'        => $sistemId,
                    'CRM_Hesap_Islem_Log_kullanici_id'     => $personelId,
                    'CRM_Hesap_Islem_Log_crm_kullanici_id' => $yeniId,
                    'CRM_Hesap_Islem_Log_crm_email'        => $kurumsal,
                    'CRM_Hesap_Islem_Log_crm_adsoyad'      => trim($personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']),
                    'CRM_Hesap_Islem_Log_islem'            => 'HESAP_ACILDI',
                    'CRM_Hesap_Islem_Log_mesaj'            => 'Yeni hesap olusturuldu, ilk giriste sifre degisimi zorunlu.',
                    'OlusturanKullanici'                   => $user['kullanici_id']
                ]);

                echo json_encode([
                    'success'  => true,
                    'islem'    => 'HESAP_ACILDI',
                    'crm_kullanici_id' => $yeniId,
                    'email'    => $kurumsal,
                    'giris_url' => sistemGirisUrl($sistem),
                    'sifre'    => $sifre,
                    'message'  => 'Hesap oluşturuldu.'
                ]);
                break;

            case 'log_listesi':
                $kayitlar = $db->fetchAll("
                    SELECT TOP 500
                        l.CRM_Hesap_Islem_Log_id,
                        ISNULL(CAST(l.CRM_Hesap_Islem_Log_crm_kullanici_id AS NVARCHAR(64)), l.CRM_Hesap_Islem_Log_crm_harici_id) AS CRM_Hesap_Islem_Log_crm_kullanici_id,
                        l.CRM_Hesap_Islem_Log_crm_email,
                        l.CRM_Hesap_Islem_Log_crm_adsoyad,
                        l.CRM_Hesap_Islem_Log_eslesme_tipi,
                        l.CRM_Hesap_Islem_Log_islem,
                        l.CRM_Hesap_Islem_Log_mesaj,
                        l.OlusturmaTarihi,
                        s.CRM_Sistemleri_ad,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS islem_yapan
                    FROM dbo.CRM_Hesap_Islem_Log l
                    LEFT JOIN dbo.tanim_CRM_Sistemleri s ON s.CRM_Sistemleri_id = l.CRM_Hesap_Islem_Log_sistem_id
                    LEFT JOIN dbo.kullanicilar k ON k.kullanici_id = l.OlusturanKullanici
                    ORDER BY l.CRM_Hesap_Islem_Log_id DESC
                ");

                foreach ($kayitlar as &$kayit) {
                    $kayit['OlusturmaTarihi'] = tarihMetin($kayit['OlusturmaTarihi']);
                }
                unset($kayit);

                echo json_encode(['success' => true, 'data' => $kayitlar]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Filtre için sistem listesi
$sistemListesi = crmSistemleri($db);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .badge { font-size: 0.75rem; padding: 0.35em 0.65em; }
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        #sistemDurumListesi .list-group-item { padding: 0.5rem 0.75rem; }
        .satir-secim { display: flex; justify-content: center; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <!-- Sayfa Başlığı -->
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <?php endif; ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm">
                                    <i class="bi bi-person-dash"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif Personel</span>
                                    <span class="info-box-number" id="stat-pasif-personel">-</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-shield-exclamation"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Açık Kalan Hesap</span>
                                    <span class="info-box-number" id="stat-acik-hesap">-</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-people"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Etkilenen Personel</span>
                                    <span class="info-box-number" id="stat-etkilenen">-</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-hdd-network"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Taranan Sistem</span>
                                    <span class="info-box-number" id="stat-sistem">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sistem Durumları -->
                    <div class="card card-secondary card-outline mb-3 collapse" id="sistemDurumCard">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-hdd-network"></i> Sistem Bağlantı Durumları</h3>
                        </div>
                        <div class="card-body">
                            <div class="row g-2" id="sistemDurumListesi"></div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                        </div>
                        <div class="card-body">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" id="filter_search" name="search" placeholder="Personel, e-posta veya CRM hesabı...">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">CRM Sistemi</label>
                                        <select class="form-select select2" id="filter_sistem" name="sistem">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sistemListesi as $s): ?>
                                                <option value="<?= htmlspecialchars($s['CRM_Sistemleri_ad']) ?>"><?= htmlspecialchars($s['CRM_Sistemleri_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Eşleşme Tipi</label>
                                        <select class="form-select select2" id="filter_eslesme" name="eslesme">
                                            <option value="">Tümü</option>
                                            <option value="EMAIL">E-posta</option>
                                            <option value="TC">TC Kimlik</option>
                                            <option value="ADSOYAD">Ad Soyad</option>
                                        </select>
                                    </div>

                                    <div class="col-md-2">
                                        <label class="form-label">Hesap Durumu</label>
                                        <select class="form-select select2" id="filter_hesap_durum" name="hesap_durum">
                                            <option value="AKTIF" selected>Aktif</option>
                                            <option value="ASKIDA">Askıda</option>
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> Açık Kalan CRM Hesapları</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#sistemDurumCard">
                                    <i class="bi bi-hdd-network"></i> Sistem Durumu
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <button type="button" class="btn btn-sm btn-info" id="btnTara">
                                    <i class="bi bi-arrow-repeat"></i> Yeniden Tara
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-sm btn-success" id="btnHesapAc">
                                    <i class="bi bi-person-plus"></i> Hesap Aç
                                </button>
                                <?php endif; ?>
                                <?php if ($pagePermissions['can_edit']): ?>
                                <button type="button" class="btn btn-sm btn-danger" id="btnPasifeAl" disabled>
                                    <i class="bi bi-person-slash"></i> Seçilenleri Pasife Al (<span id="secimSayisi">0</span>)
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered table-striped table-hover w-100" id="bulguTable">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <div class="form-check form-switch d-flex justify-content-center">
                                                <input class="form-check-input" type="checkbox" role="switch" id="secTumu">
                                                <label class="form-check-label" for="secTumu"></label>
                                            </div>
                                        </th>
                                        <th>Personel</th>
                                        <th>İşten Çıkış</th>
                                        <th>CRM Sistemi</th>
                                        <th>CRM Hesabı</th>
                                        <th>CRM E-posta</th>
                                        <th>Son Giriş</th>
                                        <th>Eşleşme</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- İşlem Log Kartı -->
                    <div class="card card-secondary card-outline mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-clock-history"></i> İşlem Geçmişi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" id="btnLogYenile">
                                    <i class="bi bi-arrow-repeat"></i> Yenile
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered table-striped table-hover w-100" id="logTable">
                                <thead>
                                    <tr>
                                        <th>Tarih</th>
                                        <th>CRM Sistemi</th>
                                        <th>CRM Hesabı</th>
                                        <th>E-posta</th>
                                        <th>Eşleşme</th>
                                        <th>İşlem</th>
                                        <th>Mesaj</th>
                                        <th>İşlemi Yapan</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Modal: CRM Hesabı Aç -->
    <div class="modal fade" id="modalHesapAc" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> CRM Hesabı Aç</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form id="hesapAcForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Personel <span class="text-danger">*</span></label>
                                <select class="form-select" id="ha_personel" name="kullanici_id" required>
                                    <option value="">Seçiniz...</option>
                                </select>
                                <small class="text-muted">Portalda aktif olan personeller listelenir.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">CRM Sistemi <span class="text-danger">*</span></label>
                                <select class="form-select" id="ha_sistem" name="sistem_id" required>
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label"><span id="ha_departman_etiket">Departman</span> <span class="text-danger">*</span></label>
                                <select class="form-select" id="ha_departman" name="departman_id" required disabled>
                                    <option value="">Önce sistem seçin...</option>
                                </select>
                                <small class="text-muted" id="ha_departman_aciklama">Hedef CRM'in kendi departman listesidir.</small>
                            </div>

                            <div class="col-md-6 d-none" id="ha_alan_kutu">
                                <label class="form-label">Alan Adı <span class="text-danger">*</span></label>
                                <select class="form-select" id="ha_alan" name="alan">
                                    <option value="">Önce sistem seçin...</option>
                                </select>
                                <small class="text-muted">Google Workspace'teki doğrulanmış alan adlarıdır.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Portal Departmanı</label>
                                <input type="text" class="form-control" id="ha_portal_departman" readonly>
                            </div>

                            <div class="col-12">
                                <label class="form-label">CRM E-postası</label>
                                <div class="input-group">
                                    <input type="email" class="form-control font-monospace" id="ha_crm_eposta" name="email" placeholder="Personel ve sistem seçilince üretilir" autocomplete="off">
                                    <button type="button" class="btn btn-outline-secondary" id="ha_eposta_sifirla" title="Otomatik üretilen adrese dön">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Ad ve soyaddan <code>isim.soyisim@alan</code> düzeninde otomatik üretilir, gerekirse değiştirilebilir (alan adı aynı kalmalı); portaldaki kişisel e-posta CRM'e yazılmaz.</small>
                            </div>

                            <div class="col-12">
                                <div id="ha_onizleme"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-success" id="ha_kaydet" disabled>
                            <i class="bi bi-check-circle"></i> Hesabı Aç
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        const permissions = <?= json_encode($pagePermissions) ?>;

        let bulguTable = null;
        let logTable = null;
        let bulgular = [];              // Son tarama sonucu
        const secilenler = new Set();   // satir_id kümesi

        /**
         * Select2 kur.
         * custom.js sayfa acilisinda butun .form-select elemanlarina Select2
         * uyguluyor; ustune ikinci kez uygulanirsa mukerrer kutu olusur.
         * Bu yuzden once varsa mevcut ornek yikilir.
         */
        function select2Kur(selector, ekOpsiyon) {
            // Select2 4.1 örneği jQuery .data() ile değil kendi deposunda tutar;
            // data('select2') boş döndüğü için destroy hiç çalışmıyordu. Sınıf kontrolü her sürümde güvenilir.
            // Destroy tek başına yetmedi; geride kalan kutu da elle temizlenir,
            // böylece kaç kez / hangi sırayla kurulursa kurulsun tek kutu kalır.
            $(selector).each(function () {
                const $s = $(this);
                if ($s.hasClass('select2-hidden-accessible')) {
                    try { $s.select2('destroy'); } catch (e) { /* başka örnek: aşağıda temizlenir */ }
                }
                $s.siblings('.select2-container').remove();
                $s.removeClass('select2-hidden-accessible').removeAttr('data-select2-id aria-hidden tabindex');
            });
            $(selector).select2($.extend({ theme: 'bootstrap-5', width: '100%' }, ekOpsiyon || {}));
        }

        function htmlKacis(deger) {
            if (deger === null || deger === undefined) return '';
            return $('<div>').text(deger).html();
        }

        function eslesmeRozeti(tip) {
            const harita = {
                EMAIL:   ['bg-success', 'E-posta'],
                TC:      ['bg-primary', 'TC Kimlik'],
                ADSOYAD: ['bg-warning text-dark', 'Ad Soyad']
            };
            const c = harita[tip] || ['bg-secondary', tip || '-'];
            return '<span class="badge ' + c[0] + '">' + c[1] + '</span>';
        }

        function secimGuncelle() {
            $('#secimSayisi').text(secilenler.size);
            $('#btnPasifeAl').prop('disabled', secilenler.size === 0);
        }

        function tabloDoldur(veri) {
            bulguTable.clear();

            veri.forEach(function (b) {
                const secili = secilenler.has(b.satir_id) ? 'checked' : '';
                const secim =
                    '<div class="form-check form-switch d-flex justify-content-center">' +
                    '<input class="form-check-input satir-sec" type="checkbox" role="switch" ' +
                    'id="sec_' + b.satir_id + '" data-satir="' + b.satir_id + '" ' + secili + '>' +
                    '<label class="form-check-label" for="sec_' + b.satir_id + '"></label>' +
                    '</div>';

                const personel =
                    '<strong>' + htmlKacis(b.personel_adi) + '</strong>' +
                    (b.personel_email ? '<br><small class="text-muted">' + htmlKacis(b.personel_email) + '</small>' : '');

                const url = b.sistem_url
                    ? (/^https?:\/\//i.test(b.sistem_url) ? b.sistem_url : 'https://' + b.sistem_url)
                    : '';
                const sistem = url
                    ? '<a href="' + htmlKacis(url) + '" target="_blank" rel="noopener">' + htmlKacis(b.sistem_adi) + '</a>'
                    : htmlKacis(b.sistem_adi);

                const crmHesap =
                    htmlKacis(b.crm_adsoyad || '-') +
                    (b.askida ? ' <span class="badge bg-secondary">Askıda</span>' : '') +
                    '<br><small class="text-muted">#' + htmlKacis(b.crm_kullanici_id) + '</small>';

                bulguTable.row.add([
                    secim,
                    personel,
                    htmlKacis(b.cikis_tarihi || '-'),
                    sistem,
                    crmHesap,
                    htmlKacis(b.crm_email || '-'),
                    htmlKacis(b.crm_songiris || '-'),
                    eslesmeRozeti(b.eslesme_tipi)
                ]);
            });

            bulguTable.draw();
        }

        function filtreUygula() {
            const arama = ($('#filter_search').val() || '').toLocaleLowerCase('tr');
            const sistem = $('#filter_sistem').val() || '';
            const eslesme = $('#filter_eslesme').val() || '';
            const hesapDurum = $('#filter_hesap_durum').val() || '';

            const filtreli = bulgular.filter(function (b) {
                if (sistem && b.sistem_adi !== sistem) return false;
                if (eslesme && b.eslesme_tipi !== eslesme) return false;
                if (hesapDurum === 'AKTIF' && b.askida) return false;
                if (hesapDurum === 'ASKIDA' && !b.askida) return false;

                if (arama) {
                    const metin = [
                        b.personel_adi, b.personel_email, b.crm_adsoyad,
                        b.crm_email, b.sistem_adi
                    ].join(' ').toLocaleLowerCase('tr');
                    if (metin.indexOf(arama) === -1) return false;
                }
                return true;
            });

            tabloDoldur(filtreli);
        }

        function sistemDurumGoster(sistemler) {
            const kap = $('#sistemDurumListesi').empty();

            sistemler.forEach(function (s) {
                const hata = s.durum === 'HATA';
                const renk = hata ? 'border-danger' : (s.bulgu > 0 ? 'border-warning' : 'border-success');
                const ikon = hata
                    ? '<i class="bi bi-x-circle-fill text-danger"></i>'
                    : (s.bulgu > 0
                        ? '<i class="bi bi-exclamation-triangle-fill text-warning"></i>'
                        : '<i class="bi bi-check-circle-fill text-success"></i>');

                const detay = hata
                    ? '<small class="text-danger">' + htmlKacis(s.mesaj) + '</small>'
                    : '<small class="text-muted">' + s.aktif + ' aktif hesap · ' + s.bulgu + ' bulgu</small>';

                kap.append(
                    '<div class="col-12 col-md-6 col-lg-4">' +
                    '<div class="border ' + renk + ' rounded p-2 h-100">' +
                    ikon + ' <strong>' + htmlKacis(s.sistem_adi) + '</strong><br>' + detay +
                    '</div></div>'
                );
            });
        }

        function tara() {
            $('#btnTara').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Taranıyor...');

            $.post('', { action: 'tara' }, function (response) {
                if (!response.success) {
                    showToast(response.message || 'Tarama başarısız!', 'error');
                    return;
                }

                bulgular = response.data || [];
                secilenler.clear();
                secimGuncelle();

                $('#stat-pasif-personel').text(response.stats.pasif_personel);
                $('#stat-acik-hesap').text(response.stats.acik_hesap);
                $('#stat-etkilenen').text(response.stats.etkilenen_personel);
                $('#stat-sistem').text(
                    (response.stats.toplam_sistem - response.stats.hatali_sistem) + ' / ' + response.stats.toplam_sistem
                );

                sistemDurumGoster(response.sistemler || []);
                filtreUygula();

                if (response.stats.hatali_sistem > 0) {
                    showToast(response.stats.hatali_sistem + ' sisteme bağlanılamadı. Sistem Durumu bölümünü kontrol edin.', 'warning');
                    $('#sistemDurumCard').collapse('show');
                }
            }, 'json')
            .fail(function () {
                showToast('Tarama sırasında sunucu hatası oluştu!', 'error');
            })
            .always(function () {
                $('#btnTara').prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Yeniden Tara');
            });
        }

        function pasifeAl() {
            // Askıdaki Google hesapları zaten kapalı; yalnız silme için seçilebilir
            const secim = bulgular.filter(b => secilenler.has(b.satir_id) && !b.askida);
            if (secim.length === 0) {
                showToast('Seçilen hesapların hepsi zaten askıda.', 'warning');
                return;
            }

            // Önizleme listesi
            let onizleme = '<div class="text-start" style="max-height:300px;overflow-y:auto">';
            onizleme += '<table class="table table-sm table-bordered mb-0"><thead><tr>' +
                        '<th>Personel</th><th>CRM Sistemi</th><th>CRM Hesabı</th></tr></thead><tbody>';
            secim.forEach(function (b) {
                onizleme += '<tr><td>' + htmlKacis(b.personel_adi) + '</td><td>' +
                            htmlKacis(b.sistem_adi) + '</td><td>' +
                            htmlKacis(b.crm_adsoyad || ('#' + b.crm_kullanici_id)) + '</td></tr>';
            });
            onizleme += '</tbody></table></div>';

            Swal.fire({
                title: secim.length + ' hesap pasife alınacak',
                html: onizleme,
                icon: 'warning',
                width: '48rem',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-person-slash"></i> Onayla ve Pasife Al',
                cancelButtonText: 'İptal',
                confirmButtonColor: '#dc3545'
            }).then(function (sonuc) {
                if (!sonuc.isConfirmed) return;

                const yuk = secim.map(function (b) {
                    return {
                        sistem_id: b.sistem_id,
                        crm_kullanici_id: b.crm_kullanici_id,
                        kullanici_id: b.kullanici_id,
                        crm_email: b.crm_email,
                        crm_adsoyad: b.crm_adsoyad,
                        eslesme_tipi: b.eslesme_tipi
                    };
                });

                Swal.fire({ title: 'İşleniyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                $.post('', { action: 'pasife_al', secimler: JSON.stringify(yuk) }, function (response) {
                    Swal.close();

                    if (!response.success) {
                        showToast(response.message || 'İşlem başarısız!', 'error');
                        return;
                    }

                    showToast(response.message, response.basarisiz > 0 ? 'warning' : 'success');

                    if (response.detaylar && response.detaylar.length > 0) {
                        Swal.fire({
                            title: 'Başarısız İşlemler',
                            html: '<div class="text-start small">' +
                                  response.detaylar.map(d => '<div>• ' + htmlKacis(d) + '</div>').join('') +
                                  '</div>',
                            icon: 'warning',
                            width: '48rem'
                        });
                    }

                    tara();
                    logYukle();
                }, 'json')
                .fail(function () {
                    Swal.close();
                    showToast('Sunucu hatası oluştu!', 'error');
                });
            });
        }

        // ===== Hesap Açma =====
        let hesapAcModal = null;
        let hesapAcVeriYuklendi = false;
        let sonOnizleme = null;
        let onizlemeIstekNo = 0;
        let epostaElle = false;          // CRM e-postası elle değiştirildi mi
        let epostaZamanlayici = null;

        function nrmMetin(v) {
            const harita = { 'İ':'i','I':'i','ı':'i','Ş':'s','ş':'s','Ğ':'g','ğ':'g','Ü':'u','ü':'u','Ö':'o','ö':'o','Ç':'c','ç':'c' };
            return (v || '').replace(/[İIışŞğĞüÜöÖçÇ]/g, c => harita[c] || c)
                            .toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
        }

        function hesapAcVerileriYukle(sonra) {
            if (hesapAcVeriYuklendi) { sonra(); return; }

            $.post('', { action: 'hesap_ac_verileri' }, function (response) {
                if (!response.success) {
                    showToast(response.message || 'Liste yüklenemedi!', 'error');
                    return;
                }

                const pSel = $('#ha_personel').empty().append('<option value="">Seçiniz...</option>');
                response.personeller.forEach(function (p) {
                    pSel.append(
                        $('<option>').val(p.id).text(p.ad + (p.email ? ' — ' + p.email : ' — (e-posta yok)'))
                            .attr('data-departman', p.departman || '')
                            .attr('data-email', p.email || '')
                    );
                });

                const sSel = $('#ha_sistem').empty().append('<option value="">Seçiniz...</option>');
                response.sistemler.forEach(function (s) {
                    sSel.append($('<option>').val(s.id).text(s.ad).attr('data-tip', s.tip));
                });

                hesapAcVeriYuklendi = true;
                sonra();
            }, 'json').fail(function () {
                showToast('Liste yüklenirken sunucu hatası!', 'error');
            });
        }

        function googleSeciliMi() {
            return $('#ha_sistem option:selected').data('tip') === 'GOOGLE';
        }

        /** Sistem tipine göre etiketleri ve alan adı kutusunu ayarla */
        function sistemTipiUygula() {
            const google = googleSeciliMi();
            $('#ha_departman_etiket').text(google ? 'Organizasyon Birimi' : 'Departman');
            $('#ha_departman_aciklama').text(google
                ? 'Google Workspace organizasyon birimidir.'
                : "Hedef CRM'in kendi departman listesidir.");
            $('#ha_alan_kutu').toggleClass('d-none', !google);
            if (!google) $('#ha_alan').empty().append('<option value="">Önce sistem seçin...</option>').trigger('change.select2');
        }

        function departmanlariYukle() {
            const sistemId = $('#ha_sistem').val();
            const dSel = $('#ha_departman');

            sistemTipiUygula();

            if (!sistemId) {
                dSel.empty().append('<option value="">Önce sistem seçin...</option>').prop('disabled', true).trigger('change');
                return;
            }

            dSel.empty().append('<option value="">Yükleniyor...</option>').prop('disabled', true).trigger('change');

            $.post('', { action: 'crm_departmanlar', sistem_id: sistemId }, function (response) {
                if (!response.success) {
                    dSel.empty().append('<option value="">Liste alınamadı</option>').trigger('change');
                    showToast(response.message || 'Departman listesi alınamadı!', 'error');
                    return;
                }

                // Google: alan adı listesi; değişince önizleme yeniden çalışır
                if (response.alanlar) {
                    const aSel = $('#ha_alan').empty();
                    response.alanlar.forEach(function (a) {
                        aSel.append($('<option>').val(a).text(a));
                    });
                    if (response.alanlar.indexOf(response.varsayilan_alan) !== -1) aSel.val(response.varsayilan_alan);
                    aSel.trigger('change.select2');
                    onizlemeYukle();
                }

                dSel.empty().append('<option value="">Seçiniz...</option>');
                response.data.forEach(function (d) {
                    dSel.append($('<option>').val(d.id).text(d.ad));
                });
                dSel.prop('disabled', false);

                // Portal departman adıyla birebir eşleşen varsa otomatik seç
                const portalDep = nrmMetin($('#ha_personel option:selected').data('departman'));
                if (portalDep) {
                    response.data.forEach(function (d) {
                        if (nrmMetin(d.ad) === portalDep) dSel.val(d.id);
                    });
                }
                dSel.trigger('change');
            }, 'json').fail(function () {
                dSel.empty().append('<option value="">Liste alınamadı</option>').trigger('change');
                showToast('Departman listesi alınırken sunucu hatası!', 'error');
            });
        }

        function onizlemeYukle() {
            const sistemId = $('#ha_sistem').val();
            const personelId = $('#ha_personel').val();
            const kutu = $('#ha_onizleme');

            sonOnizleme = null;
            $('#ha_kaydet').prop('disabled', true);
            if (!epostaElle) $('#ha_crm_eposta').val('');
            const email = epostaElle ? $.trim($('#ha_crm_eposta').val()) : '';

            if (!sistemId || !personelId) { kutu.empty(); return; }

            // Google: alan adı listesi gelmeden sorgulanmaz (departmanlariYukle tekrar çağırır)
            const alan = googleSeciliMi() ? ($('#ha_alan').val() || '') : '';
            if (googleSeciliMi() && !alan) { kutu.empty(); return; }

            kutu.html('<div class="alert alert-secondary mb-0"><i class="bi bi-hourglass-split"></i> Hedef sistemde kontrol ediliyor...</div>');

            // Seçim hızlı değişirse eski yanıt yenisinin üzerine yazmasın
            const istekNo = ++onizlemeIstekNo;

            $.post('', { action: 'hesap_onizleme', sistem_id: sistemId, kullanici_id: personelId, alan: alan, email: email }, function (response) {
                if (istekNo !== onizlemeIstekNo) return;

                if (!response.success) {
                    kutu.html('<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> ' + htmlKacis(response.message) + '</div>');
                    return;
                }

                sonOnizleme = response;
                if (!epostaElle) $('#ha_crm_eposta').val(response.email || '');

                if (response.durum === 'AKTIF_VAR') {
                    kutu.html(
                        '<div class="alert alert-warning mb-0">' +
                        '<i class="bi bi-exclamation-triangle"></i> <strong>Zaten aktif hesabı var.</strong><br>' +
                        'Hesap #' + response.crm_kullanici_id + ' — ' + htmlKacis(response.crm_adsoyad) +
                        ' (' + htmlKacis(response.crm_email || '-') + ')<br>' +
                        '<small>Eşleşme: ' + htmlKacis(response.eslesme_tipi) + '. Yeni hesap açılmayacak.</small>' +
                        '</div>'
                    );
                    return;
                }

                if (response.durum === 'PASIF_VAR') {
                    kutu.html(
                        '<div class="alert alert-info mb-0">' +
                        '<i class="bi bi-arrow-clockwise"></i> <strong>' +
                        (googleSeciliMi() ? 'Askıdaki hesap bulundu, askıdan çıkarılacak.' : 'Pasif hesabı bulundu, yeniden aktif edilecek.') +
                        '</strong><br>' +
                        'Hesap #' + response.crm_kullanici_id + ' — ' + htmlKacis(response.crm_adsoyad) +
                        ' (' + htmlKacis(response.crm_email || '-') + ')<br>' +
                        '<small>Eşleşme: ' + htmlKacis(response.eslesme_tipi) + '. Şifre sıfırlanacak, mükerrer kayıt oluşmayacak.</small>' +
                        '</div>'
                    );
                } else {
                    kutu.html(
                        '<div class="alert alert-success mb-0">' +
                        '<i class="bi bi-person-plus"></i> <strong>Yeni hesap açılacak.</strong><br>' +
                        htmlKacis(response.personel) + ' — ' + htmlKacis(response.email) +
                        '<br><small>İlk girişte şifre değiştirme zorunlu olacak.' +
                        (googleSeciliMi() ? ' Lisans (Google Workspace) otomatik atanacak.' : '') + '</small>' +
                        '</div>'
                    );
                }

                if ($('#ha_departman').val()) $('#ha_kaydet').prop('disabled', false);
            }, 'json').fail(function () {
                kutu.html('<div class="alert alert-danger mb-0">Kontrol sırasında sunucu hatası oluştu.</div>');
            });
        }

        function hesapAcKaydet() {
            if (!sonOnizleme || sonOnizleme.durum === 'AKTIF_VAR') return;

            const veri = {
                action: 'hesap_ac',
                sistem_id: $('#ha_sistem').val(),
                kullanici_id: $('#ha_personel').val(),
                departman_id: $('#ha_departman').val(),
                alan: googleSeciliMi() ? $('#ha_alan').val() : ''
            };

            Swal.fire({ title: 'İşleniyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.post('', veri, function (response) {
                Swal.close();

                if (!response.success) {
                    showToast(response.message || 'İşlem başarısız!', 'error');
                    return;
                }

                hesapAcModal.hide();

                Swal.fire({
                    title: response.islem === 'YENIDEN_AKTIF' ? 'Hesap Yeniden Aktif Edildi' : 'Hesap Açıldı',
                    icon: 'success',
                    width: '40rem',
                    html:
                        '<div class="text-start">' +
                        '<p class="mb-2">' + htmlKacis(response.message) + ' (Hesap #' + htmlKacis(response.crm_kullanici_id) + ')</p>' +
                        (response.email ? '<p class="mb-2">Giriş adresi: <strong class="font-monospace">' + htmlKacis(response.email) + '</strong></p>' : '') +
                        (response.uyari ? '<div class="alert alert-danger mb-2"><i class="bi bi-exclamation-triangle"></i> ' + htmlKacis(response.uyari) + '</div>' : '') +
                        '<div class="alert alert-warning mb-2"><strong>Şifre yalnızca bir kez gösterilir</strong> — kapatmadan önce kopyalayın. Log kayıtlarına yazılmaz.</div>' +
                        '<div class="input-group">' +
                        '<input type="text" class="form-control font-monospace" id="ha_sifre_kutu" value="' + htmlKacis(response.sifre) + '" readonly>' +
                        '<button type="button" class="btn btn-outline-secondary" id="ha_kopyala"><i class="bi bi-clipboard"></i> Kopyala</button>' +
                        '</div>' +
                        '<p class="mt-2 mb-0 small text-muted">Kullanıcı ilk girişte şifresini değiştirmek zorunda kalacak.</p>' +
                        '</div>',
                    didOpen: function () {
                        $('#ha_kopyala').on('click', function () {
                            const kutu = document.getElementById('ha_sifre_kutu');
                            kutu.select();
                            navigator.clipboard.writeText(kutu.value).then(
                                () => showToast('Şifre kopyalandı', 'success'),
                                () => showToast('Kopyalanamadı, elle seçin', 'warning')
                            );
                        });
                    }
                });

                logYukle();
            }, 'json').fail(function () {
                Swal.close();
                showToast('Sunucu hatası oluştu!', 'error');
            });
        }

        function logYukle() {
            $.post('', { action: 'log_listesi' }, function (response) {
                if (!response.success) return;

                logTable.clear();
                (response.data || []).forEach(function (l) {
                    const islemHarita = {
                        PASIFE_ALINDI: ['bg-secondary', 'Pasife Alındı'],
                        ASKIYA_ALINDI: ['bg-secondary', 'Askıya Alındı'],
                        HESAP_ACILDI:  ['bg-success', 'Hesap Açıldı'],
                        YENIDEN_AKTIF: ['bg-info', 'Yeniden Aktif'],
                        HATA:          ['bg-danger', 'Hata']
                    };
                    const i = islemHarita[l.CRM_Hesap_Islem_Log_islem] || ['bg-dark', l.CRM_Hesap_Islem_Log_islem];
                    const islem = '<span class="badge ' + i[0] + '">' + i[1] + '</span>';

                    logTable.row.add([
                        htmlKacis(l.OlusturmaTarihi),
                        htmlKacis(l.CRM_Sistemleri_ad || '-'),
                        htmlKacis(l.CRM_Hesap_Islem_Log_crm_adsoyad || ('#' + l.CRM_Hesap_Islem_Log_crm_kullanici_id)),
                        htmlKacis(l.CRM_Hesap_Islem_Log_crm_email || '-'),
                        eslesmeRozeti(l.CRM_Hesap_Islem_Log_eslesme_tipi),
                        islem,
                        htmlKacis(l.CRM_Hesap_Islem_Log_mesaj || '-'),
                        htmlKacis(l.islem_yapan || '-')
                    ]);
                });
                logTable.draw();
            }, 'json');
        }

        $(document).ready(function () {
            select2Kur('.select2');

            bulguTable = $('#bulguTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                dom: 'lrtip',
                scrollX: true,
                order: [[1, 'asc']],
                columnDefs: [{ orderable: false, targets: [0] }],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
            });

            logTable = $('#logTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                scrollX: true,
                order: [[0, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]]
            });

            // Satır seçimi
            $('#bulguTable tbody').on('change', '.satir-sec', function () {
                const id = $(this).data('satir');
                if (this.checked) {
                    secilenler.add(id);
                } else {
                    secilenler.delete(id);
                    $('#secTumu').prop('checked', false);
                }
                secimGuncelle();
            });

            // Tümünü seç - yalnızca o an filtrelenmiş satırlar
            $('#secTumu').on('change', function () {
                const isaretli = this.checked;
                $('#bulguTable').DataTable().rows({ search: 'applied' }).nodes().to$()
                    .find('.satir-sec').each(function () {
                        const id = $(this).data('satir');
                        this.checked = isaretli;
                        if (isaretli) { secilenler.add(id); } else { secilenler.delete(id); }
                    });
                secimGuncelle();
            });

            $('#btnTara').on('click', tara);
            $('#btnPasifeAl').on('click', pasifeAl);
            $('#btnLogYenile').on('click', logYukle);

            // Hesap açma modalı
            if (document.getElementById('modalHesapAc')) {
                hesapAcModal = new bootstrap.Modal('#modalHesapAc');

                const modalSelect2 = function () {
                    select2Kur('#ha_personel, #ha_sistem, #ha_departman, #ha_alan', {
                        dropdownParent: $('#modalHesapAc')
                    });
                };
                modalSelect2();

                $('#btnHesapAc').on('click', function () {
                    hesapAcVerileriYukle(function () {
                        modalSelect2();
                        $('#hesapAcForm')[0].reset();
                        $('#ha_personel, #ha_sistem').val('').trigger('change');
                        $('#ha_departman').empty().append('<option value="">Önce sistem seçin...</option>')
                            .prop('disabled', true).trigger('change');
                        $('#ha_portal_departman').val('');
                        $('#ha_crm_eposta').val('');
                        $('#ha_onizleme').empty();
                        $('#ha_kaydet').prop('disabled', true);
                        sonOnizleme = null;
                        sistemTipiUygula();
                        hesapAcModal.show();
                    });
                });

                $('#ha_personel').on('change', function () {
                    $('#ha_portal_departman').val($('option:selected', this).data('departman') || '');
                    if ($('#ha_sistem').val()) departmanlariYukle();
                    onizlemeYukle();
                });

                $('#ha_sistem').on('change', function () {
                    departmanlariYukle();
                    onizlemeYukle();
                });

                $('#ha_alan').on('change', function () {
                    if (googleSeciliMi()) onizlemeYukle();
                });

                $('#ha_departman').on('change', function () {
                    const hazir = sonOnizleme && sonOnizleme.durum !== 'AKTIF_VAR' && $(this).val();
                    $('#ha_kaydet').prop('disabled', !hazir);
                });

                $('#hesapAcForm').on('submit', function (e) {
                    e.preventDefault();
                    hesapAcKaydet();
                });
            }

            $('#filterForm').on('submit', function (e) {
                e.preventDefault();
                filtreUygula();
            });

            $('#clearFilters').on('click', function () {
                $('#filter_search').val('');
                $('#filter_sistem').val('').trigger('change');
                $('#filter_eslesme').val('').trigger('change');
                $('#filter_hesap_durum').val('AKTIF').trigger('change');
                filtreUygula();
            });

            tara();
            logYukle();
        });
    </script>
</body>
</html>
