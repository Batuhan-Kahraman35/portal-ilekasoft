<?php
/**
 * Admin Panel - Geliştirme Mesai Raporu
 *
 * Tüm CRM sistemlerinin ve portalın Sistem_Surum_Notlari tablosundaki
 * sürüm kayıtlarını toplayarak hangi gün, hangi saatlerde ne kadar
 * çalışıldığını raporlar.
 *
 * Kaynak listesi tanim_CRM_Sistemleri tablosundan gelir, kodda sabit
 * veritabanı adı tutulmaz.
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

$pageInfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Geliştirme Mesai Raporu';
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
function tabloKolonlari($conn, $tablo) {
    $stmt = @sqlsrv_query($conn, "SELECT c.name FROM sys.columns c WHERE c.object_id = OBJECT_ID(?)", ['dbo.' . $tablo]);
    if ($stmt === false) return [];

    $kolonlar = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $kolonlar[mb_strtolower($row['name'], 'UTF-8')] = $row['name'];
    }
    sqlsrv_free_stmt($stmt);
    return $kolonlar;
}

/** DATETIME değerini 'Y-m-d H:i:s' metnine çevir */
function tarihMetin($deger) {
    if ($deger instanceof DateTime) return $deger->format('Y-m-d H:i:s');
    if (!$deger) return null;
    $t = strtotime((string)$deger);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

/** Aktif CRM sistemlerini getir */
function crmSistemleri($db) {
    return $db->fetchAll("
        SELECT
            CRM_Sistemleri_id,
            CRM_Sistemleri_ad,
            CRM_Sistemleri_veritabani,
            CRM_Sistemleri_host,
            CRM_Sistemleri_versiyon_dosya,
            CRM_Sistemleri_sira
        FROM dbo.tanim_CRM_Sistemleri
        WHERE Durum = 1
          AND CRM_Sistemleri_tip = 'MSSQL'   -- Google vb. API sistemlerinin sürüm notu DB'si yok
        ORDER BY CRM_Sistemleri_sira, CRM_Sistemleri_ad
    ");
}

/** Sürüm notu tablosundan okunacak kolon eşlemesi (olmayan kolon atlanır) */
function surumKolonlari($mevcut) {
    $aday = [
        'id'          => 'surum_id',
        'versiyon'    => 'surum_versiyon',
        'tarih'       => 'surum_tarih',
        'baslangic'   => 'surum_baslangic_tarihi',
        'tip'         => 'surum_tip',
        'kategori'    => 'surum_kategori',
        'modul'       => 'surum_modul',
        'baslik'      => 'surum_baslik',
        'durum'       => 'surum_durum',
        'kullanici'   => 'surum_kullanici_id',
        'kayit_tarih' => 'surum_olusturma_tarihi'
    ];

    $sonuc = [];
    foreach ($aday as $anahtar => $kolon) {
        $sonuc[$anahtar] = $mevcut[mb_strtolower($kolon, 'UTF-8')] ?? null;
    }
    return $sonuc;
}

/** Tek bir bağlantıdan sürüm kayıtlarını okur */
function surumKayitlariOku($conn, $sistemAdi, $veritabani) {
    $mevcut = tabloKolonlari($conn, 'Sistem_Surum_Notlari');

    if (empty($mevcut)) {
        return ['kayitlar' => [], 'hata' => 'Sistem_Surum_Notlari tablosu bulunamadı veya okuma yetkisi yok.'];
    }

    $k = surumKolonlari($mevcut);

    if (!$k['tarih']) {
        return ['kayitlar' => [], 'hata' => 'surum_tarih kolonu bulunamadı.'];
    }

    $secim = [];
    foreach ($k as $takma => $kolon) {
        if ($kolon) $secim[] = "[$kolon] AS [$takma]";
    }

    $sql = "SELECT " . implode(', ', $secim) . " FROM dbo.Sistem_Surum_Notlari";
    if ($k['durum']) $sql .= " WHERE [" . $k['durum'] . "] = 1";
    $sql .= " ORDER BY [" . $k['tarih'] . "]";

    $stmt = @sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        return ['kayitlar' => [], 'hata' => trim($errors[0]['message'] ?? 'Sorgu çalıştırılamadı')];
    }

    $kayitlar = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tarih = tarihMetin($row['tarih'] ?? null);
        if (!$tarih) continue;

        $kayitlar[] = [
            'sistem'      => $sistemAdi,
            'veritabani'  => $veritabani,
            'surum_id'    => isset($row['id']) ? (int)$row['id'] : null,
            'versiyon'    => (string)($row['versiyon'] ?? ''),
            'tarih'       => $tarih,
            'baslangic'   => baslangicDogrula(tarihMetin($row['baslangic'] ?? null), $tarih),
            'tip'         => trim((string)($row['tip'] ?? '')),
            'kategori'    => trim((string)($row['kategori'] ?? '')),
            'modul'       => trim((string)($row['modul'] ?? '')),
            'baslik'      => trim((string)($row['baslik'] ?? '')),
            'kayit_tarih' => tarihMetin($row['kayit_tarih'] ?? null),
            'kullanici'   => isset($row['kullanici']) ? (int)$row['kullanici'] : null
        ];
    }
    sqlsrv_free_stmt($stmt);

    return ['kayitlar' => $kayitlar, 'hata' => null];
}

/**
 * Baslangic zamanini dogrular.
 *
 * Bitisten sonraki veya ayni gune ait olmayan bir baslangic olculebilir bir
 * sure vermez; boyle bir deger yok sayilip kayit tahmini hesaba birakilir.
 */
function baslangicDogrula($baslangic, $bitis) {
    if (!$baslangic || !$bitis) return null;
    if ($baslangic >= $bitis) return null;
    if (substr($baslangic, 0, 10) !== substr($bitis, 0, 10)) return null;
    return $baslangic;
}

/** Portal'ın kendi veritabanındaki sürüm kayıtlarını okur */
function portalSurumKayitlari($db) {
    try {
        // Baslangic kolonu heniz eklenmemis olabilir; yoksa NULL secilir
        $kolonVar = $db->fetchOne(
            "SELECT COL_LENGTH('dbo.Sistem_Surum_Notlari', 'surum_baslangic_tarihi') AS uzunluk"
        );
        $baslangicSecim = !empty($kolonVar['uzunluk'])
            ? "CONVERT(VARCHAR(19), surum_baslangic_tarihi, 120)"
            : "CAST(NULL AS VARCHAR(19))";

        $satirlar = $db->fetchAll("
            SELECT
                surum_id            AS id,
                surum_versiyon      AS versiyon,
                CONVERT(VARCHAR(19), surum_tarih, 120)             AS tarih,
                $baslangicSecim                                    AS baslangic,
                surum_tip           AS tip,
                surum_kategori      AS kategori,
                surum_modul         AS modul,
                surum_baslik        AS baslik,
                surum_kullanici_id  AS kullanici,
                CONVERT(VARCHAR(19), surum_olusturma_tarihi, 120)  AS kayit_tarih
            FROM dbo.Sistem_Surum_Notlari
            WHERE surum_durum = 1
            ORDER BY surum_tarih
        ");
    } catch (Exception $e) {
        return ['kayitlar' => [], 'hata' => $e->getMessage()];
    }

    $kayitlar = [];
    foreach ($satirlar as $row) {
        $tarih = tarihMetin($row['tarih'] ?? null);
        if (!$tarih) continue;

        $kayitlar[] = [
            'sistem'      => 'Portal',
            'veritabani'  => 'portal_ornekfirma_DB',
            'surum_id'    => (int)$row['id'],
            'versiyon'    => (string)($row['versiyon'] ?? ''),
            'tarih'       => $tarih,
            'baslangic'   => baslangicDogrula(tarihMetin($row['baslangic'] ?? null), $tarih),
            'tip'         => trim((string)($row['tip'] ?? '')),
            'kategori'    => trim((string)($row['kategori'] ?? '')),
            'modul'       => trim((string)($row['modul'] ?? '')),
            'baslik'      => trim((string)($row['baslik'] ?? '')),
            'kayit_tarih' => tarihMetin($row['kayit_tarih'] ?? null),
            'kullanici'   => isset($row['kullanici']) ? (int)$row['kullanici'] : null
        ];
    }

    return ['kayitlar' => $kayitlar, 'hata' => null];
}

/**
 * Tüm kaynaklardaki sürüm kayıtlarını toplar.
 * Bir kaynak hata verse bile diğerleri raporlanmaya devam eder.
 */
function tumSurumKayitlari($db) {
    $kayitlar = [];
    $durumlar = [];

    // Portal'ın kendisi
    $portal = portalSurumKayitlari($db);
    $kayitlar = array_merge($kayitlar, $portal['kayitlar']);
    $durumlar[] = [
        'sistem'     => 'Portal',
        'veritabani' => 'portal_ornekfirma_DB',
        'durum'      => $portal['hata'] ? 'HATA' : 'OK',
        'mesaj'      => $portal['hata'] ?? '',
        'adet'       => count($portal['kayitlar'])
    ];

    foreach (crmSistemleri($db) as $sistem) {
        $baglanti = crmBaglan($sistem);

        if ($baglanti['conn'] === false) {
            $durumlar[] = [
                'sistem'     => $sistem['CRM_Sistemleri_ad'],
                'veritabani' => $sistem['CRM_Sistemleri_veritabani'],
                'durum'      => 'HATA',
                'mesaj'      => $baglanti['hata'],
                'adet'       => 0
            ];
            continue;
        }

        $sonuc = surumKayitlariOku(
            $baglanti['conn'],
            $sistem['CRM_Sistemleri_ad'],
            $sistem['CRM_Sistemleri_veritabani']
        );
        sqlsrv_close($baglanti['conn']);

        $kayitlar = array_merge($kayitlar, $sonuc['kayitlar']);
        $durumlar[] = [
            'sistem'     => $sistem['CRM_Sistemleri_ad'],
            'veritabani' => $sistem['CRM_Sistemleri_veritabani'],
            'durum'      => $sonuc['hata'] ? 'HATA' : 'OK',
            'mesaj'      => $sonuc['hata'] ?? '',
            'adet'       => count($sonuc['kayitlar'])
        ];
    }

    usort($kayitlar, function ($a, $b) {
        return strcmp($a['tarih'], $b['tarih']);
    });

    return ['kayitlar' => $kayitlar, 'sistem_durumlari' => $durumlar];
}

// ===================================================================
// Bekleyen Sürüm Aktarımı
// ===================================================================

/**
 * versions.sql dosyasını okur ve kodlamayı UTF-8'e normalize eder.
 * Projelerin bir kısmında dosya UTF-16 veya BOM'lu kaydedilmiş olabiliyor.
 */
function versiyonDosyaOku($yol) {
    $yol = trim((string)$yol);

    if ($yol === '') {
        return ['icerik' => null, 'hata' => 'Sürüm dosyası yolu tanımlı değil (CRM_Sistemleri_versiyon_dosya).'];
    }
    /*
     * open_basedir kapsami disindaki bir yol icin is_file() de false doner;
     * ayirt edilmezse gercek neden "dosya yok" gibi gorunur.
     */
    if (!openBasedirKapsaminda($yol)) {
        return [
            'icerik' => null,
            'hata'   => 'PHP open_basedir kapsamı dışında: ' . $yol .
                        ' (mevcut kapsam: ' . ini_get('open_basedir') . ')'
        ];
    }

    if (!is_file($yol)) {
        return [
            'icerik' => null,
            'hata'   => 'Dosya görülemiyor: ' . $yol . ' — ' . phpKullanicisi() .
                        ' kullanıcısının bu yol üzerinde okuma/geçiş izni yok.'
        ];
    }

    $ham = @file_get_contents($yol);
    if ($ham === false) {
        return [
            'icerik' => null,
            'hata'   => 'Dosya okunamadı: ' . $yol . ' — ' . phpKullanicisi() . ' için okuma izni yok.'
        ];
    }

    if (substr($ham, 0, 2) === "\xFF\xFE" || substr($ham, 0, 2) === "\xFE\xFF") {
        $ham = mb_convert_encoding($ham, 'UTF-8', 'UTF-16');
    } elseif (substr($ham, 0, 3) === "\xEF\xBB\xBF") {
        $ham = substr($ham, 3);
    }

    return ['icerik' => $ham, 'hata' => null];
}

/**
 * PHP surecinin hangi Windows kullanicisi olarak calistigini dondurur.
 * Plesk'te site kullanicisi ile IIS uygulama havuzu kimligi farkli olabildigi
 * icin izin hatalarinda hangi hesaba yetki verilecegi bu bilgiyle belirlenir.
 */
function phpKullanicisi() {
    $ad = getenv('USERNAME');
    if (!$ad && function_exists('get_current_user')) $ad = get_current_user();

    $alan = getenv('USERDOMAIN');
    $surec = trim(($alan ? $alan . '\\' : '') . ($ad ?: 'bilinmiyor'));

    // IIS istek sirasinda site kullanicisini taklit eder; erisimi yapan hesap
    // surec sahibinden farkli olabilir, bu yuzden ikisi ayrik yazilir.
    $taklit = $_SERVER['LOGON_USER'] ?? ($_SERVER['AUTH_USER'] ?? '');

    return $taklit !== ''
        ? $surec . ' (istek kimliği: ' . $taklit . ')'
        : $surec . ' (süreç sahibi; IIS kimlik taklidi nedeniyle erişimi yapan hesap farklı olabilir)';
}

/**
 * Verilen yolun PHP'nin open_basedir kapsamında olup olmadığını söyler.
 * Kapsam boşsa kısıtlama yoktur.
 */
function openBasedirKapsaminda($yol) {
    $kapsam = trim((string)ini_get('open_basedir'));
    if ($kapsam === '') return true;

    $duzelt = function ($v) {
        return rtrim(mb_strtolower(str_replace('/', '\\', trim($v)), 'UTF-8'), '\\');
    };

    $hedef = $duzelt($yol);

    foreach (explode(PATH_SEPARATOR, $kapsam) as $kok) {
        $kok = $duzelt($kok);
        if ($kok === '') continue;
        if ($hedef === $kok || strpos($hedef, $kok . '\\') === 0) return true;
    }

    return false;
}

/** Dosya içeriğindeki sürüm numaralarını çıkarır */
function dosyaVersiyonlari($icerik) {
    preg_match_all("/surum_versiyon\s*=\s*'([0-9.]+)'/i", (string)$icerik, $eslesme);
    return array_values(array_unique($eslesme[1] ?? []));
}

/**
 * Dosyayı GO ayracıyla böler ve yalnızca Sistem_Surum_Notlari tablosuna
 * INSERT yapan blokları döndürür.
 *
 * Dosyadan gelen SQL doğrudan çalıştırılmaz: başka tabloya dokunan veya
 * şema değiştiren blok reddedilir ve raporda gösterilir.
 */
function surumBlokAyikla($icerik) {
    $sql = preg_replace('/\/\*.*?\*\//s', '', (string)$icerik);
    $sql = preg_replace('/--[^\r\n]*/', '', $sql);

    $yasakli = '/\b(DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|DENY|BACKUP|RESTORE|SHUTDOWN|MERGE|EXEC|EXECUTE|UPDATE|DELETE)\b/i';

    $bloklar = [];
    $reddedilen = [];

    foreach (preg_split('/^\s*GO\s*$/im', $sql) as $blok) {
        $blok = trim($blok);
        if ($blok === '') continue;

        /*
         * Guvenlik taramasi metin sabitleri cikarilmis kopya uzerinde yapilir.
         * Surum aciklamalari serbest metindir; icinde UPDATE, CREATE gibi
         * kelimeler veya surum_tip degeri olarak 'UPDATE' gecebilir. Ham blok
         * taranirsa bu bloklar hatali sekilde reddedilir. Calistirilirken
         * her zaman orijinal blok kullanilir.
         */
        $denetim = preg_replace("/'[^']*(?:''[^']*)*'/", "''", $blok);

        /*
         * Surum aciklamalari 10 KB'i asabiliyor. Maskeleme deseni geri izleme
         * yapmayacak bicimde yazildi, yine de PCRE bir sinira takilir ve null
         * donerse blok guvenli tarafta kalinarak reddedilir; sessizce atlanmaz.
         */
        if ($denetim === null) {
            $reddedilen[] = [
                'anahtar'  => 'AYRISTIRILAMADI (' . preg_last_error_msg() . ')',
                'versiyon' => blokVersiyonu($blok)
            ];
            continue;
        }

        // Sistem_Surum_Notlari'na INSERT yapmayan blok bu sayfayı ilgilendirmez
        if (!preg_match('/INSERT\s+INTO\s+(\[?dbo\]?\.)?\[?Sistem_Surum_Notlari\]?/i', $denetim)) {
            continue;
        }

        if (preg_match($yasakli, $denetim, $bulunan)) {
            $reddedilen[] = [
                'anahtar'  => strtoupper($bulunan[1]),
                'versiyon' => blokVersiyonu($blok)
            ];
            continue;
        }

        // Blok yalnızca kendi tablosuna dokunmalı
        preg_match_all('/\b(?:INTO|FROM|JOIN)\s+(?:\[?dbo\]?\.)?\[?([A-Za-z0-9_]+)\]?/i', $denetim, $tablolar);
        foreach ($tablolar[1] as $tablo) {
            if (mb_strtolower($tablo, 'UTF-8') !== 'sistem_surum_notlari') {
                $reddedilen[] = ['anahtar' => 'TABLO: ' . $tablo, 'versiyon' => blokVersiyonu($blok)];
                continue 2;
            }
        }

        /*
         * Bazi projelerde iki surum arasinda GO ayraci unutulmus; tek blokta
         * birden fazla INSERT bulunabiliyor. Bloktaki her surum ayni bloga
         * eslenir, aksi halde ikinci surum listede hic gorunmez.
         */
        $versiyonlar = blokVersiyonlari($blok);
        if (empty($versiyonlar)) continue;

        foreach ($versiyonlar as $versiyon) {
            $bloklar[$versiyon] = $blok;
        }
    }

    return ['bloklar' => $bloklar, 'reddedilen' => $reddedilen];
}

/** Bir SQL bloğundaki tüm sürüm numaralarını bulur */
function blokVersiyonlari($blok) {
    if (preg_match_all("/surum_versiyon\s*=\s*'([0-9.]+)'/i", $blok, $e)) {
        return array_values(array_unique($e[1]));
    }
    if (preg_match_all("/'([0-9]+\.[0-9]+\.[0-9]+)'/", $blok, $e)) {
        return array_values(array_unique($e[1]));
    }
    return [];
}

/** Bir SQL bloğundaki ilk sürüm numarasını bulur (hata raporlaması için) */
function blokVersiyonu($blok) {
    $v = blokVersiyonlari($blok);
    return $v[0] ?? null;
}

/** Sürüm numaralarını doğal sıraya (1.2.10 > 1.2.9) göre sıralar */
function versiyonSirala($versiyonlar) {
    usort($versiyonlar, function ($a, $b) {
        return version_compare($a, $b);
    });
    return $versiyonlar;
}

/** Aktarım işlemi için tüm kaynakların listesini hazırlar (Portal + CRM'ler) */
function aktarimKaynaklari($db) {
    $kaynaklar = [[
        'id'         => 0,
        'ad'         => 'Portal',
        'veritabani' => 'portal_ornekfirma_DB',
        'host'       => null,
        'dosya'      => realpath(__DIR__ . '/../../config/versions.sql') ?: (__DIR__ . '/../../config/versions.sql'),
        'yerel'      => true
    ]];

    foreach (crmSistemleri($db) as $s) {
        $kaynaklar[] = [
            'id'         => (int)$s['CRM_Sistemleri_id'],
            'ad'         => $s['CRM_Sistemleri_ad'],
            'veritabani' => $s['CRM_Sistemleri_veritabani'],
            'host'       => $s['CRM_Sistemleri_host'],
            'dosya'      => $s['CRM_Sistemleri_versiyon_dosya'] ?? '',
            'yerel'      => false
        ];
    }

    return $kaynaklar;
}

/** Hedef veritabanında kayıtlı sürüm numaralarını döndürür */
function kayitliVersiyonlar($db, $kaynak, &$hata) {
    $hata = null;

    if ($kaynak['yerel']) {
        try {
            $satirlar = $db->fetchAll("SELECT surum_versiyon FROM dbo.Sistem_Surum_Notlari");
            return array_column($satirlar, 'surum_versiyon');
        } catch (Exception $e) {
            $hata = $e->getMessage();
            return [];
        }
    }

    $baglanti = crmBaglan([
        'CRM_Sistemleri_veritabani' => $kaynak['veritabani'],
        'CRM_Sistemleri_host'       => $kaynak['host']
    ]);

    if ($baglanti['conn'] === false) {
        $hata = $baglanti['hata'];
        return [];
    }

    $stmt = @sqlsrv_query($baglanti['conn'], "SELECT surum_versiyon FROM dbo.Sistem_Surum_Notlari");
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $hata = trim($errors[0]['message'] ?? 'Sürüm listesi okunamadı');
        sqlsrv_close($baglanti['conn']);
        return [];
    }

    $versiyonlar = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $versiyonlar[] = (string)$row['surum_versiyon'];
    }
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($baglanti['conn']);

    return $versiyonlar;
}

/** Bir kaynağın bekleyen sürümlerini hesaplar */
function bekleyenSurumler($db, $kaynak) {
    $dosya = versiyonDosyaOku($kaynak['dosya']);

    if ($dosya['hata']) {
        return [
            'sistem'      => $kaynak['ad'],
            'sistem_id'   => $kaynak['id'],
            'veritabani'  => $kaynak['veritabani'],
            'dosya'       => $kaynak['dosya'],
            'durum'       => 'HATA',
            'mesaj'       => $dosya['hata'],
            'dosya_adet'  => 0,
            'db_adet'     => 0,
            'bekleyen'    => [],
            'reddedilen'  => []
        ];
    }

    $dosyaVersiyon = dosyaVersiyonlari($dosya['icerik']);
    $ayrisim = surumBlokAyikla($dosya['icerik']);

    $dbHata = null;
    $dbVersiyon = kayitliVersiyonlar($db, $kaynak, $dbHata);

    if ($dbHata) {
        return [
            'sistem'      => $kaynak['ad'],
            'sistem_id'   => $kaynak['id'],
            'veritabani'  => $kaynak['veritabani'],
            'dosya'       => $kaynak['dosya'],
            'durum'       => 'HATA',
            'mesaj'       => $dbHata,
            'dosya_adet'  => count($dosyaVersiyon),
            'db_adet'     => 0,
            'bekleyen'    => [],
            'reddedilen'  => $ayrisim['reddedilen']
        ];
    }

    // Yalnızca çalıştırılabilir bloğu olan sürümler aktarılabilir
    $eksik = array_diff($dosyaVersiyon, $dbVersiyon);
    $calistirilabilir = array_keys($ayrisim['bloklar']);

    $bekleyen = array_values(array_intersect($eksik, $calistirilabilir));

    // Dosyada eksik görünüp bloğu ayrıştırılamayan sürümler sessizce kaybolmasın
    $aktarilamayan = array_values(array_diff($eksik, $calistirilabilir));

    return [
        'sistem'        => $kaynak['ad'],
        'sistem_id'     => $kaynak['id'],
        'veritabani'    => $kaynak['veritabani'],
        'dosya'         => $kaynak['dosya'],
        'durum'         => 'OK',
        'mesaj'         => '',
        'dosya_adet'    => count($dosyaVersiyon),
        'db_adet'       => count($dbVersiyon),
        'bekleyen'      => versiyonSirala($bekleyen),
        'aktarilamayan' => versiyonSirala($aktarilamayan),
        'reddedilen'    => $ayrisim['reddedilen']
    ];
}

/** Bir kaynağın bekleyen sürümlerini hedef veritabanına yazar */
function surumAktar($db, $kaynak) {
    $dosya = versiyonDosyaOku($kaynak['dosya']);
    if ($dosya['hata']) {
        return ['sistem' => $kaynak['ad'], 'durum' => 'HATA', 'mesaj' => $dosya['hata'], 'eklenen' => 0, 'versiyonlar' => []];
    }

    $ayrisim = surumBlokAyikla($dosya['icerik']);

    $dbHata = null;
    $dbVersiyon = kayitliVersiyonlar($db, $kaynak, $dbHata);
    if ($dbHata) {
        return ['sistem' => $kaynak['ad'], 'durum' => 'HATA', 'mesaj' => $dbHata, 'eklenen' => 0, 'versiyonlar' => []];
    }

    $bekleyen = versiyonSirala(array_values(array_diff(array_keys($ayrisim['bloklar']), $dbVersiyon)));

    if (empty($bekleyen)) {
        return ['sistem' => $kaynak['ad'], 'durum' => 'OK', 'mesaj' => 'Zaten güncel.', 'eklenen' => 0, 'versiyonlar' => []];
    }

    // Portal kendi bağlantısını, CRM'ler ayrı bağlantıyı kullanır
    $conn = null;
    if (!$kaynak['yerel']) {
        $baglanti = crmBaglan([
            'CRM_Sistemleri_veritabani' => $kaynak['veritabani'],
            'CRM_Sistemleri_host'       => $kaynak['host']
        ]);
        if ($baglanti['conn'] === false) {
            return ['sistem' => $kaynak['ad'], 'durum' => 'HATA', 'mesaj' => $baglanti['hata'], 'eklenen' => 0, 'versiyonlar' => []];
        }
        $conn = $baglanti['conn'];
    }

    $eklenen = [];
    $hatalar = [];
    $calistirilan = [];   // Ayni blok birden fazla surum icin secilmis olabilir

    foreach ($bekleyen as $versiyon) {
        $blok = $ayrisim['bloklar'][$versiyon];
        $imza = md5($blok);

        if (isset($calistirilan[$imza])) {
            $eklenen[] = $versiyon;
            continue;
        }

        try {
            if ($kaynak['yerel']) {
                $db->execute($blok);
            } else {
                $stmt = @sqlsrv_query($conn, $blok);
                if ($stmt === false) {
                    $errors = sqlsrv_errors();
                    throw new Exception(trim($errors[0]['message'] ?? 'Bilinmeyen hata'));
                }
                sqlsrv_free_stmt($stmt);
            }
            $calistirilan[$imza] = true;
            $eklenen[] = $versiyon;
        } catch (Exception $e) {
            $hatalar[] = $versiyon . ': ' . $e->getMessage();
        }
    }

    if ($conn) sqlsrv_close($conn);

    return [
        'sistem'      => $kaynak['ad'],
        'durum'       => empty($hatalar) ? 'OK' : 'KISMI',
        'mesaj'       => empty($hatalar) ? '' : implode(' | ', $hatalar),
        'eklenen'     => count($eklenen),
        'versiyonlar' => $eklenen
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

            case 'rapor':
                $sonuc = tumSurumKayitlari($db);

                echo json_encode([
                    'success'          => true,
                    'kayitlar'         => $sonuc['kayitlar'],
                    'sistem_durumlari' => $sonuc['sistem_durumlari'],
                    'sunucu_saati'     => date('Y-m-d H:i:s')
                ]);
                break;

            case 'bekleyen_surumler':
                $liste = [];
                foreach (aktarimKaynaklari($db) as $kaynak) {
                    $liste[] = bekleyenSurumler($db, $kaynak);
                }

                echo json_encode([
                    'success' => true,
                    'data'    => $liste
                ]);
                break;

            case 'surum_aktar':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Sürüm aktarma yetkiniz yok.']);
                    break;
                }

                $hedef = $_POST['sistem_id'] ?? 'tumu';
                $sonuclar = [];

                foreach (aktarimKaynaklari($db) as $kaynak) {
                    if ($hedef !== 'tumu' && (string)$kaynak['id'] !== (string)$hedef) continue;
                    $sonuclar[] = surumAktar($db, $kaynak);
                }

                if (empty($sonuclar)) {
                    echo json_encode(['success' => false, 'message' => 'Hedef sistem bulunamadı.']);
                    break;
                }

                $toplam = array_sum(array_column($sonuclar, 'eklenen'));

                echo json_encode([
                    'success'  => true,
                    'toplam'   => $toplam,
                    'sonuclar' => $sonuclar
                ]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Filtre için sistem listesi (Portal + CRM'ler)
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

        /* Saat dagilimi sutun grafigi - harici kutuphane kullanilmaz */
        .saat-grafik { display: flex; align-items: flex-end; gap: 4px; height: 220px; padding-top: 10px; }
        .saat-sutun { flex: 1 1 0; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; }
        .saat-cubuk { width: 100%; background: var(--bs-primary); border-radius: 3px 3px 0 0; min-height: 2px; transition: opacity .2s; }
        .saat-sutun:hover .saat-cubuk { opacity: .75; }
        .saat-etiket { font-size: .7rem; margin-top: 4px; color: var(--bs-secondary-color); }
        .saat-deger { font-size: .7rem; font-weight: 600; margin-bottom: 2px; }
        .mesai-disi .saat-cubuk { background: var(--bs-warning); }

        .oran-cubuk { height: 8px; background: var(--bs-secondary-bg); border-radius: 4px; overflow: hidden; }
        .oran-cubuk > span { display: block; height: 100%; background: var(--bs-primary); }

        @media print {
            .app-header, .app-sidebar, .app-footer, .card-tools, .no-print, .breadcrumb { display: none !important; }
            .app-main { margin: 0 !important; }
            .card { break-inside: avoid; }
        }
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
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-tags"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Sürüm</span>
                                    <span class="info-box-number" id="stat-surum">-</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-calendar-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Çalışılan Gün</span>
                                    <span class="info-box-number" id="stat-gun">-</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-hourglass-split"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Süre</span>
                                    <span class="info-box-number" id="stat-sure">-</span>
                                    <span class="text-muted small d-none" id="stat-sure-detay"></span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-speedometer2"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Günlük Ortalama</span>
                                    <span class="info-box-number" id="stat-ortalama">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sistem Durumları -->
                    <div class="card card-secondary card-outline mb-3 collapse" id="sistemDurumCard">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-hdd-network"></i> Kaynak Bağlantı Durumları</h3>
                        </div>
                        <div class="card-body">
                            <div class="row g-2" id="sistemDurumListesi"></div>
                        </div>
                    </div>

                    <!-- Bekleyen Sürümler -->
                    <div class="card card-warning card-outline mb-3 collapse" id="bekleyenCard">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-upload"></i> Bekleyen Sürümler</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" id="btnBekleyenYenile">
                                    <i class="bi bi-arrow-repeat"></i> Yenile
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-sm btn-warning" id="btnTumunuAktar" disabled>
                                    <i class="bi bi-cloud-upload"></i> Tümünü Aktar (<span id="bekleyenToplam">0</span>)
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">
                                Her projenin <code>config/versions.sql</code> dosyası okunur, veritabanına henüz
                                yazılmamış sürüm kayıtları listelenir. Aktarımda yalnızca
                                <code>Sistem_Surum_Notlari</code> tablosuna <code>INSERT</code> yapan bloklar çalıştırılır.
                            </p>
                            <table class="table table-bordered table-striped table-hover w-100" id="bekleyenTable">
                                <thead>
                                    <tr>
                                        <th>Sistem</th>
                                        <th>Veritabanı</th>
                                        <th>Dosyada</th>
                                        <th>Veritabanında</th>
                                        <th>Bekleyen</th>
                                        <th>Sürümler</th>
                                        <th width="120">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
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
                                    <div class="col-md-3">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" id="filter_bas">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" id="filter_bit">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Sistem</label>
                                        <select class="form-select select2" id="filter_sistem" multiple>
                                            <option value="Portal">Portal</option>
                                            <?php foreach ($sistemListesi as $s): ?>
                                                <option value="<?= htmlspecialchars($s['CRM_Sistemleri_ad']) ?>"><?= htmlspecialchars($s['CRM_Sistemleri_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Sürüm Tipi</label>
                                        <select class="form-select select2" id="filter_tip" multiple></select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Kategori</label>
                                        <select class="form-select select2" id="filter_kategori" multiple></select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" id="filter_search" placeholder="Başlık, modül veya versiyon...">
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Oturum Kesme Süresi (dk)</label>
                                        <input type="number" class="form-control" id="filter_bosluk" value="90" min="10" max="480" step="5">
                                        <small class="text-muted">İki sürüm arası bu süreden uzunsa yeni oturum sayılır.</small>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Oturum Payı (dk)</label>
                                        <input type="number" class="form-control" id="filter_pay" value="30" min="0" max="240" step="5">
                                        <small class="text-muted">Her oturuma eklenen hazırlık/kapanış süresi.</small>
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

                    <!-- Rapor Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-clock-history"></i> Çalışma Raporu</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#sistemDurumCard">
                                    <i class="bi bi-hdd-network"></i> Kaynak Durumu
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="collapse" data-bs-target="#bekleyenCard">
                                    <i class="bi bi-upload"></i> Bekleyen Sürümler <span class="badge text-bg-dark" id="bekleyenRozet">0</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-info" id="btnYenile">
                                    <i class="bi bi-arrow-repeat"></i> Yeniden Tara
                                </button>
                                <button type="button" class="btn btn-sm btn-success" id="btnExcel">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                                <button type="button" class="btn btn-sm btn-dark" id="btnYazdir">
                                    <i class="bi bi-printer"></i> Yazdır
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <ul class="nav nav-tabs mb-3" id="raporTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-gunluk" type="button">
                                        <i class="bi bi-calendar3"></i> Günlük Özet
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-aylik" type="button">
                                        <i class="bi bi-calendar-month"></i> Aylık Özet
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-saat" type="button">
                                        <i class="bi bi-bar-chart"></i> Saat Dağılımı
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sistem" type="button">
                                        <i class="bi bi-diagram-3"></i> Sistem Dağılımı
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-detay" type="button">
                                        <i class="bi bi-list-ul"></i> Detay
                                    </button>
                                </li>
                            </ul>

                            <div class="tab-content">
                                <!-- Günlük Özet -->
                                <div class="tab-pane fade show active" id="tab-gunluk">
                                    <table class="table table-bordered table-striped table-hover w-100" id="gunlukTable">
                                        <thead>
                                            <tr>
                                                <th>Tarih</th>
                                                <th>Gün</th>
                                                <th>Başlangıç</th>
                                                <th>Bitiş</th>
                                                <th>Sürüm</th>
                                                <th>Oturum</th>
                                                <th>Toplam Süre</th>
                                                <th>Kaynak</th>
                                                <th>Dokunulan Sistemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- Aylık Özet -->
                                <div class="tab-pane fade" id="tab-aylik">
                                    <table class="table table-bordered table-striped table-hover w-100" id="aylikTable">
                                        <thead>
                                            <tr>
                                                <th>Ay</th>
                                                <th>Çalışılan Gün</th>
                                                <th>Sürüm</th>
                                                <th>Oturum</th>
                                                <th>Toplam Süre</th>
                                                <th>Ölçülen</th>
                                                <th>Günlük Ortalama</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- Saat Dağılımı -->
                                <div class="tab-pane fade" id="tab-saat">
                                    <p class="text-muted">
                                        Sürüm kayıtlarının gün içindeki saat dağılımı.
                                        <span class="badge text-bg-warning">Sarı</span> sütunlar 09:00-18:00 mesai saatleri dışını gösterir.
                                    </p>
                                    <div class="saat-grafik" id="saatGrafik"></div>
                                    <hr>
                                    <div class="row g-3" id="saatOzet"></div>
                                </div>

                                <!-- Sistem Dağılımı -->
                                <div class="tab-pane fade" id="tab-sistem">
                                    <table class="table table-bordered table-striped table-hover w-100" id="sistemTable">
                                        <thead>
                                            <tr>
                                                <th>Sistem</th>
                                                <th>Veritabanı</th>
                                                <th>Sürüm</th>
                                                <th>Çalışılan Gün</th>
                                                <th>İlk Sürüm</th>
                                                <th>Son Sürüm</th>
                                                <th width="180">Pay</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- Detay -->
                                <div class="tab-pane fade" id="tab-detay">
                                    <table class="table table-bordered table-striped table-hover w-100" id="detayTable">
                                        <thead>
                                            <tr>
                                                <th>Tarih / Saat</th>
                                                <th>Başlangıç</th>
                                                <th>Süre</th>
                                                <th>Sistem</th>
                                                <th>Versiyon</th>
                                                <th>Tip</th>
                                                <th>Kategori</th>
                                                <th>Modül</th>
                                                <th>Başlık</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
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
        const MESAI_BAS = 9;    // Mesai saati baslangici (saat dagilimi renklendirmesi icin)
        const MESAI_BIT = 18;

        let tumKayitlar = [];   // Sunucudan gelen ham surum kayitlari
        let filtreli = [];      // Filtre uygulanmis kayitlar
        let gunlukVeri = [];    // Gunluk ozet sonucu

        let gunlukTable = null, aylikTable = null, sistemTable = null, detayTable = null, bekleyenTable = null;

        /**
         * Select2 kur.
         * custom.js sayfa acilisinda butun .form-select elemanlarina Select2
         * uyguluyor; ustune ikinci kez uygulanirsa mukerrer kutu olusur.
         */
        function select2Kur(selector, ekOpsiyon) {
            $(selector).each(function () {
                if ($(this).data('select2')) $(this).select2('destroy');
            });
            $(selector).select2(Object.assign({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Tümü',
                allowClear: true
            }, ekOpsiyon || {}));
        }

        function htmlKacis(deger) {
            return $('<div>').text(deger == null ? '' : String(deger)).html();
        }

        /** 'YYYY-MM-DD HH:MM:SS' metnini Date nesnesine cevirir (tarayici saat dilimi etkisi olmadan) */
        function tarihCoz(metin) {
            const p = String(metin).split(/[- :]/);
            return new Date(+p[0], +p[1] - 1, +p[2], +(p[3] || 0), +(p[4] || 0), +(p[5] || 0));
        }

        function gunAdi(tarihMetni) {
            const gunler = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
            return gunler[tarihCoz(tarihMetni).getDay()];
        }

        function tarihGoster(metin) {
            const p = String(metin).split(' ');
            const t = p[0].split('-');
            return t[2] + '.' + t[1] + '.' + t[0];
        }

        function saatGoster(metin) {
            return String(metin).split(' ')[1].substring(0, 5);
        }

        /** Dakikayi '3 sa 20 dk' bicimine cevirir */
        function sureGoster(dakika) {
            const d = Math.round(dakika);
            const sa = Math.floor(d / 60);
            const dk = d % 60;
            if (sa === 0) return dk + ' dk';
            return sa + ' sa' + (dk > 0 ? ' ' + dk + ' dk' : '');
        }

        function tipRozeti(tip) {
            const renkler = {
                'FEATURE': 'text-bg-success',
                'BUGFIX': 'text-bg-danger',
                'IMPROVEMENT': 'text-bg-info',
                'SECURITY': 'text-bg-warning',
                'REFACTOR': 'text-bg-secondary'
            };
            const renk = renkler[String(tip).toUpperCase()] || 'text-bg-secondary';
            return '<span class="badge ' + renk + '">' + htmlKacis(tip || '-') + '</span>';
        }

        /** Kayitlardan benzersiz degerleri toplayip select doldurur */
        function secenekDoldur(selector, alan) {
            const degerler = [...new Set(tumKayitlar.map(k => k[alan]).filter(v => v !== ''))].sort();
            const $s = $(selector);
            const secili = $s.val() || [];
            $s.empty();
            degerler.forEach(v => $s.append(new Option(v, v)));
            $s.val(secili);
            select2Kur(selector);
        }

        // ===============================================================
        // Filtre + Hesaplama
        // ===============================================================

        function filtreUygula() {
            const bas = $('#filter_bas').val();
            const bit = $('#filter_bit').val();
            const sistemler = $('#filter_sistem').val() || [];
            const tipler = $('#filter_tip').val() || [];
            const kategoriler = $('#filter_kategori').val() || [];
            const arama = ($('#filter_search').val() || '').toLocaleLowerCase('tr');

            filtreli = tumKayitlar.filter(function (k) {
                const gun = k.tarih.substring(0, 10);
                if (bas && gun < bas) return false;
                if (bit && gun > bit) return false;
                if (sistemler.length && !sistemler.includes(k.sistem)) return false;
                if (tipler.length && !tipler.includes(k.tip)) return false;
                if (kategoriler.length && !kategoriler.includes(k.kategori)) return false;

                if (arama) {
                    const metin = (k.baslik + ' ' + k.modul + ' ' + k.versiyon + ' ' + k.sistem).toLocaleLowerCase('tr');
                    if (metin.indexOf(arama) === -1) return false;
                }
                return true;
            });

            hesaplaVeCiz();
        }

        /**
         * Gunluk oturum hesabi.
         * Ayni gundeki kayitlar siralanir; iki kayit arasi fark esik degerinden
         * kucukse ayni oturum sayilip fark sureye eklenir, buyukse yeni oturum
         * baslar. Her oturuma sabit bir pay eklenir (tek kayitlik oturumun da
         * bir suresi olmasi icin).
         */
        function gunlukHesapla(kayitlar, boslukDk, payDk) {
            const gruplar = {};

            kayitlar.forEach(function (k) {
                const gun = k.tarih.substring(0, 10);
                if (!gruplar[gun]) gruplar[gun] = [];
                gruplar[gun].push(k);
            });

            return Object.keys(gruplar).sort().map(function (gun) {
                const liste = gruplar[gun].slice().sort((a, b) => a.tarih.localeCompare(b.tarih));

                // Baslangic zamani kayitli olanlar olculmus aralik verir
                const araliklar = araliklariBirlestir(
                    liste.filter(k => k.baslangic)
                         .map(k => [tarihCoz(k.baslangic).getTime(), tarihCoz(k.tarih).getTime()])
                );

                const gercekSure = araliklar.reduce((t, a) => t + (a[1] - a[0]) / 60000, 0);

                // Olculmus bir araligin icine dusen kayit ayrica tahmin edilmez
                const olcusuz = liste.filter(function (k) {
                    if (k.baslangic) return false;
                    const an = tarihCoz(k.tarih).getTime();
                    return !araliklar.some(a => an >= a[0] && an <= a[1]);
                });

                let tahminiSure = 0;
                let oturum = 0;

                if (olcusuz.length) {
                    oturum = 1;
                    for (let i = 1; i < olcusuz.length; i++) {
                        const fark = (tarihCoz(olcusuz[i].tarih) - tarihCoz(olcusuz[i - 1].tarih)) / 60000;
                        if (fark <= boslukDk) {
                            tahminiSure += fark;
                        } else {
                            oturum++;
                        }
                    }
                    tahminiSure += oturum * payDk;
                }

                // Gun basi/sonu gosteriminde olculmus baslangiclar da hesaba katilir
                const tumZamanlar = liste
                    .map(k => k.baslangic || k.tarih)
                    .concat(liste.map(k => k.tarih))
                    .sort();

                return {
                    gun: gun,
                    ilk: tumZamanlar[0],
                    son: tumZamanlar[tumZamanlar.length - 1],
                    adet: liste.length,
                    olculen: liste.length - olcusuz.length,
                    oturum: oturum + araliklar.length,
                    gercek: gercekSure,
                    tahmini: tahminiSure,
                    sure: gercekSure + tahminiSure,
                    sistemler: [...new Set(liste.map(k => k.sistem))].sort()
                };
            });
        }

        /** Cakisan zaman araliklarini birlestirir; ayni sure iki kez sayilmaz */
        function araliklariBirlestir(araliklar) {
            if (!araliklar.length) return [];

            const sirali = araliklar.slice().sort((a, b) => a[0] - b[0]);
            const sonuc = [sirali[0].slice()];

            for (let i = 1; i < sirali.length; i++) {
                const son = sonuc[sonuc.length - 1];
                if (sirali[i][0] <= son[1]) {
                    son[1] = Math.max(son[1], sirali[i][1]);
                } else {
                    sonuc.push(sirali[i].slice());
                }
            }

            return sonuc;
        }

        function hesaplaVeCiz() {
            const bosluk = parseInt($('#filter_bosluk').val(), 10) || 90;
            const pay = parseInt($('#filter_pay').val(), 10) || 0;

            gunlukVeri = gunlukHesapla(filtreli, bosluk, pay);

            const toplamSure = gunlukVeri.reduce((t, g) => t + g.sure, 0);
            const toplamGercek = gunlukVeri.reduce((t, g) => t + g.gercek, 0);
            const olculenKayit = gunlukVeri.reduce((t, g) => t + g.olculen, 0);

            $('#stat-surum').text(filtreli.length.toLocaleString('tr-TR'));
            $('#stat-gun').text(gunlukVeri.length.toLocaleString('tr-TR'));
            $('#stat-sure').text(gunlukVeri.length ? sureGoster(toplamSure) : '-');
            $('#stat-ortalama').text(gunlukVeri.length ? sureGoster(toplamSure / gunlukVeri.length) : '-');

            // Olculmus surenin toplam icindeki payi
            if (olculenKayit > 0) {
                $('#stat-sure-detay')
                    .html('<i class="bi bi-stopwatch"></i> ' + sureGoster(toplamGercek) +
                          ' ölçüldü · ' + sureGoster(toplamSure - toplamGercek) + ' tahmini')
                    .removeClass('d-none');
            } else {
                $('#stat-sure-detay').addClass('d-none');
            }

            gunlukCiz();
            aylikCiz();
            saatCiz();
            sistemCiz();
            detayCiz();
        }

        // ===============================================================
        // Tablolar
        // ===============================================================

        /**
         * Gunun suresinin nereden geldigini gosterir.
         * Olculmus = baslangic zamani kayitli surumlerden gelen gercek sure,
         * tahmini = yalnizca bitis zamani olan kayitlarin oturum tahmini.
         */
        function kaynakRozeti(g) {
            if (g.gercek > 0 && g.tahmini > 0) {
                return '<span class="badge text-bg-success" title="' + sureGoster(g.gercek) + ' ölçüldü">Ölçülen</span> ' +
                       '<span class="badge text-bg-secondary" title="' + sureGoster(g.tahmini) + ' tahmin">Tahmini</span>';
            }
            if (g.gercek > 0) {
                return '<span class="badge text-bg-success">Ölçülen</span>';
            }
            return '<span class="badge text-bg-secondary">Tahmini</span>';
        }

        function gunlukCiz() {
            const satirlar = gunlukVeri.slice().reverse().map(function (g) {
                const rozetler = g.sistemler.map(s => '<span class="badge text-bg-light border me-1">' + htmlKacis(s) + '</span>').join('');
                return [
                    '<span class="d-none">' + g.gun + '</span>' + tarihGoster(g.gun),
                    gunAdi(g.gun),
                    saatGoster(g.ilk),
                    saatGoster(g.son),
                    g.adet,
                    g.oturum,
                    '<span class="d-none">' + Math.round(g.sure) + '</span><strong>' + sureGoster(g.sure) + '</strong>',
                    kaynakRozeti(g),
                    rozetler
                ];
            });

            gunlukTable.clear().rows.add(satirlar).draw();
        }

        function aylikCiz() {
            const aylar = {};

            gunlukVeri.forEach(function (g) {
                const ay = g.gun.substring(0, 7);
                if (!aylar[ay]) aylar[ay] = { gun: 0, adet: 0, oturum: 0, sure: 0, gercek: 0 };
                aylar[ay].gun++;
                aylar[ay].adet += g.adet;
                aylar[ay].oturum += g.oturum;
                aylar[ay].sure += g.sure;
                aylar[ay].gercek += g.gercek;
            });

            const aylikAd = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

            const satirlar = Object.keys(aylar).sort().reverse().map(function (ay) {
                const v = aylar[ay];
                const p = ay.split('-');
                return [
                    '<span class="d-none">' + ay + '</span>' + aylikAd[parseInt(p[1], 10) - 1] + ' ' + p[0],
                    v.gun,
                    v.adet,
                    v.oturum,
                    '<span class="d-none">' + Math.round(v.sure) + '</span><strong>' + sureGoster(v.sure) + '</strong>',
                    v.gercek > 0 ? sureGoster(v.gercek) : '-',
                    sureGoster(v.sure / v.gun)
                ];
            });

            aylikTable.clear().rows.add(satirlar).draw();
        }

        function saatCiz() {
            const saatler = new Array(24).fill(0);
            filtreli.forEach(function (k) {
                saatler[parseInt(k.tarih.substring(11, 13), 10)]++;
            });

            const enYuksek = Math.max(1, ...saatler);
            let html = '';

            saatler.forEach(function (adet, saat) {
                const mesaiDisi = (saat < MESAI_BAS || saat >= MESAI_BIT);
                const yuzde = (adet / enYuksek) * 100;
                html += '<div class="saat-sutun ' + (mesaiDisi ? 'mesai-disi' : '') + '" title="' + saat + ':00 - ' + adet + ' sürüm">' +
                        '<span class="saat-deger">' + (adet || '') + '</span>' +
                        '<div class="saat-cubuk" style="height:' + yuzde + '%"></div>' +
                        '<span class="saat-etiket">' + String(saat).padStart(2, '0') + '</span>' +
                        '</div>';
            });

            $('#saatGrafik').html(html);

            const toplam = filtreli.length || 1;
            const mesaiIci = saatler.reduce((t, a, s) => t + ((s >= MESAI_BAS && s < MESAI_BIT) ? a : 0), 0);
            const gece = saatler.reduce((t, a, s) => t + ((s >= 22 || s < 6) ? a : 0), 0);
            const enYogunSaat = saatler.indexOf(enYuksek);

            const kutular = [
                ['En yoğun saat', String(enYogunSaat).padStart(2, '0') + ':00 - ' + String((enYogunSaat + 1) % 24).padStart(2, '0') + ':00', 'primary'],
                ['Mesai içi (09-18)', mesaiIci + ' sürüm (%' + Math.round(mesaiIci / toplam * 100) + ')', 'success'],
                ['Mesai dışı', (toplam - mesaiIci) + ' sürüm (%' + Math.round((toplam - mesaiIci) / toplam * 100) + ')', 'warning'],
                ['Gece (22-06)', gece + ' sürüm (%' + Math.round(gece / toplam * 100) + ')', 'danger']
            ];

            $('#saatOzet').html(kutular.map(function (k) {
                return '<div class="col-6 col-md-3"><div class="card border-' + k[2] + '"><div class="card-body py-2">' +
                       '<div class="text-muted small">' + k[0] + '</div>' +
                       '<div class="fw-bold">' + k[1] + '</div>' +
                       '</div></div></div>';
            }).join(''));
        }

        function sistemCiz() {
            const sistemler = {};

            filtreli.forEach(function (k) {
                if (!sistemler[k.sistem]) {
                    sistemler[k.sistem] = { db: k.veritabani, adet: 0, gunler: new Set(), ilk: k.tarih, son: k.tarih };
                }
                const s = sistemler[k.sistem];
                s.adet++;
                s.gunler.add(k.tarih.substring(0, 10));
                if (k.tarih < s.ilk) s.ilk = k.tarih;
                if (k.tarih > s.son) s.son = k.tarih;
            });

            const toplam = filtreli.length || 1;

            const satirlar = Object.keys(sistemler)
                .sort((a, b) => sistemler[b].adet - sistemler[a].adet)
                .map(function (ad) {
                    const s = sistemler[ad];
                    const yuzde = Math.round(s.adet / toplam * 100);
                    return [
                        htmlKacis(ad),
                        '<code>' + htmlKacis(s.db) + '</code>',
                        s.adet,
                        s.gunler.size,
                        tarihGoster(s.ilk),
                        tarihGoster(s.son),
                        '<div class="d-flex align-items-center gap-2">' +
                            '<div class="oran-cubuk flex-grow-1"><span style="width:' + yuzde + '%"></span></div>' +
                            '<small class="text-muted">%' + yuzde + '</small>' +
                        '</div>'
                    ];
                });

            sistemTable.clear().rows.add(satirlar).draw();
        }

        function detayCiz() {
            const satirlar = filtreli.slice().reverse().map(function (k) {
                const sure = k.baslangic
                    ? (tarihCoz(k.tarih) - tarihCoz(k.baslangic)) / 60000
                    : null;

                return [
                    '<span class="d-none">' + k.tarih + '</span>' + tarihGoster(k.tarih) + ' ' + saatGoster(k.tarih),
                    k.baslangic ? saatGoster(k.baslangic) : '<span class="text-muted">-</span>',
                    sure !== null
                        ? '<span class="d-none">' + Math.round(sure) + '</span><span class="badge text-bg-success">' + sureGoster(sure) + '</span>'
                        : '<span class="d-none">0</span><span class="text-muted">-</span>',
                    htmlKacis(k.sistem),
                    '<span class="badge text-bg-primary">' + htmlKacis(k.versiyon) + '</span>',
                    tipRozeti(k.tip),
                    htmlKacis(k.kategori || '-'),
                    htmlKacis(k.modul || '-'),
                    htmlKacis(k.baslik)
                ];
            });

            detayTable.clear().rows.add(satirlar).draw();
        }

        function sistemDurumGoster(durumlar) {
            let html = '';
            durumlar.forEach(function (s) {
                const ok = s.durum === 'OK';
                html += '<div class="col-12 col-md-6 col-lg-4">' +
                        '<div class="list-group-item border rounded d-flex justify-content-between align-items-center">' +
                        '<div><strong>' + htmlKacis(s.sistem) + '</strong>' +
                        '<div class="small text-muted"><code>' + htmlKacis(s.veritabani) + '</code></div>' +
                        (ok ? '' : '<div class="small text-danger">' + htmlKacis(s.mesaj) + '</div>') +
                        '</div>' +
                        '<span class="badge ' + (ok ? 'text-bg-success' : 'text-bg-danger') + '">' +
                        (ok ? s.adet + ' kayıt' : 'HATA') + '</span>' +
                        '</div></div>';
            });
            $('#sistemDurumListesi').html(html);
        }

        // ===============================================================
        // Veri Yükleme ve Dışa Aktarım
        // ===============================================================

        function veriYukle() {
            Swal.fire({
                title: 'Sürüm kayıtları taranıyor...',
                text: 'Tüm sistemlere bağlanılıyor, lütfen bekleyin.',
                allowOutsideClick: false,
                didOpen: function () { Swal.showLoading(); }
            });

            $.post('', { action: 'rapor' }, function (response) {
                Swal.close();

                if (!response.success) {
                    Swal.fire('Hata', response.message || 'Rapor alınamadı.', 'error');
                    return;
                }

                tumKayitlar = response.kayitlar || [];
                sistemDurumGoster(response.sistem_durumlari || []);

                secenekDoldur('#filter_tip', 'tip');
                secenekDoldur('#filter_kategori', 'kategori');

                const hatali = (response.sistem_durumlari || []).filter(s => s.durum !== 'OK').length;
                if (hatali > 0) {
                    showToast(hatali + ' kaynağa bağlanılamadı. Ayrıntı için "Kaynak Durumu" bölümüne bakın.', 'warning');
                }

                filtreUygula();
            }, 'json')
            .fail(function () {
                Swal.close();
                Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
            });
        }

        /** Gunluk ozeti ve detayi tek bir Excel (HTML tablo) dosyasi olarak indirir */
        function excelAktar() {
            if (!gunlukVeri.length) {
                showToast('Dışa aktarılacak kayıt yok.', 'warning');
                return;
            }

            let html = '<meta charset="UTF-8"><h3>Geliştirme Mesai Raporu</h3>';
            html += '<p>Rapor tarihi: ' + new Date().toLocaleString('tr-TR') + '</p>';

            html += '<h4>Günlük Özet</h4><table border="1"><tr>' +
                    '<th>Tarih</th><th>Gün</th><th>Başlangıç</th><th>Bitiş</th>' +
                    '<th>Sürüm</th><th>Oturum</th><th>Toplam Süre</th>' +
                    '<th>Ölçülen</th><th>Tahmini</th><th>Sistemler</th></tr>';

            gunlukVeri.slice().reverse().forEach(function (g) {
                html += '<tr><td>' + tarihGoster(g.gun) + '</td><td>' + gunAdi(g.gun) + '</td>' +
                        '<td>' + saatGoster(g.ilk) + '</td><td>' + saatGoster(g.son) + '</td>' +
                        '<td>' + g.adet + '</td><td>' + g.oturum + '</td>' +
                        '<td>' + sureGoster(g.sure) + '</td>' +
                        '<td>' + (g.gercek > 0 ? sureGoster(g.gercek) : '-') + '</td>' +
                        '<td>' + (g.tahmini > 0 ? sureGoster(g.tahmini) : '-') + '</td>' +
                        '<td>' + htmlKacis(g.sistemler.join(', ')) + '</td></tr>';
            });
            html += '</table>';

            html += '<h4>Detay</h4><table border="1"><tr>' +
                    '<th>Tarih / Saat</th><th>Başlangıç</th><th>Süre</th>' +
                    '<th>Sistem</th><th>Versiyon</th><th>Tip</th>' +
                    '<th>Kategori</th><th>Modül</th><th>Başlık</th></tr>';

            filtreli.slice().reverse().forEach(function (k) {
                const sure = k.baslangic ? (tarihCoz(k.tarih) - tarihCoz(k.baslangic)) / 60000 : null;

                html += '<tr><td>' + tarihGoster(k.tarih) + ' ' + saatGoster(k.tarih) + '</td>' +
                        '<td>' + (k.baslangic ? saatGoster(k.baslangic) : '-') + '</td>' +
                        '<td>' + (sure !== null ? sureGoster(sure) : '-') + '</td>' +
                        '<td>' + htmlKacis(k.sistem) + '</td><td>' + htmlKacis(k.versiyon) + '</td>' +
                        '<td>' + htmlKacis(k.tip) + '</td><td>' + htmlKacis(k.kategori) + '</td>' +
                        '<td>' + htmlKacis(k.modul) + '</td><td>' + htmlKacis(k.baslik) + '</td></tr>';
            });
            html += '</table>';

            const blob = new Blob(['﻿' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'gelistirme-mesai-raporu-' + new Date().toISOString().substring(0, 10) + '.xls';
            link.click();
            URL.revokeObjectURL(link.href);
        }

        // ===============================================================
        // Bekleyen Sürüm Aktarımı
        // ===============================================================

        function bekleyenYukle(sessiz) {
            if (!sessiz) {
                Swal.fire({
                    title: 'Sürüm dosyaları okunuyor...',
                    allowOutsideClick: false,
                    didOpen: function () { Swal.showLoading(); }
                });
            }

            $.post('', { action: 'bekleyen_surumler' }, function (response) {
                if (!sessiz) Swal.close();

                if (!response.success) {
                    if (!sessiz) Swal.fire('Hata', response.message || 'Bekleyen sürümler alınamadı.', 'error');
                    return;
                }

                bekleyenCiz(response.data || []);
            }, 'json')
            .fail(function () {
                if (!sessiz) {
                    Swal.close();
                    Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
                }
            });
        }

        function bekleyenCiz(veri) {
            let toplam = 0;

            const satirlar = veri.map(function (s) {
                const adet = (s.bekleyen || []).length;
                toplam += adet;

                let surumHtml;
                if (s.durum !== 'OK') {
                    surumHtml = '<span class="text-danger small">' + htmlKacis(s.mesaj) + '</span>';
                } else if (adet === 0) {
                    surumHtml = '<span class="text-success"><i class="bi bi-check-circle"></i> Güncel</span>';
                } else {
                    surumHtml = s.bekleyen.map(v => '<span class="badge text-bg-warning me-1">' + htmlKacis(v) + '</span>').join('');
                }

                if ((s.aktarilamayan || []).length) {
                    surumHtml += '<div class="small text-warning-emphasis mt-1"><i class="bi bi-exclamation-triangle"></i> ' +
                                 s.aktarilamayan.length + ' sürüm dosyada eksik görünüyor ama SQL bloğu ayrıştırılamadı: ' +
                                 htmlKacis(s.aktarilamayan.join(', ')) + '</div>';
                }

                if ((s.reddedilen || []).length) {
                    surumHtml += '<div class="small text-danger mt-1"><i class="bi bi-shield-exclamation"></i> ' +
                                 s.reddedilen.length + ' blok güvenlik filtresine takıldı: ' +
                                 htmlKacis(s.reddedilen.map(r => r.versiyon + ' (' + r.anahtar + ')').join(', ')) + '</div>';
                }

                const buton = (adet > 0 && s.durum === 'OK')
                    ? '<button type="button" class="btn btn-sm btn-warning btn-aktar" data-sistem="' + s.sistem_id + '">' +
                      '<i class="bi bi-cloud-upload"></i> Aktar</button>'
                    : '<span class="text-muted">-</span>';

                return [
                    '<strong>' + htmlKacis(s.sistem) + '</strong>' +
                        '<div class="small text-muted" title="' + htmlKacis(s.dosya) + '">' +
                        htmlKacis(String(s.dosya).split('\\').slice(-3).join('\\')) + '</div>',
                    '<code>' + htmlKacis(s.veritabani) + '</code>',
                    s.dosya_adet,
                    s.db_adet,
                    adet > 0 ? '<span class="badge text-bg-danger">' + adet + '</span>' : '<span class="badge text-bg-success">0</span>',
                    surumHtml,
                    buton
                ];
            });

            bekleyenTable.clear().rows.add(satirlar).draw();

            $('#bekleyenToplam').text(toplam);
            $('#bekleyenRozet')
                .text(toplam)
                .removeClass('text-bg-dark text-bg-danger')
                .addClass(toplam > 0 ? 'text-bg-danger' : 'text-bg-dark');
            $('#btnTumunuAktar').prop('disabled', toplam === 0);

            if (toplam > 0) $('#bekleyenCard').addClass('show');
        }

        function surumAktar(hedef, baslik) {
            Swal.fire({
                title: 'Sürümler aktarılsın mı?',
                html: baslik,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-cloud-upload"></i> Aktar',
                cancelButtonText: 'İptal',
                confirmButtonColor: '#ffc107'
            }).then(function (sonuc) {
                if (!sonuc.isConfirmed) return;

                Swal.fire({
                    title: 'Aktarılıyor...',
                    allowOutsideClick: false,
                    didOpen: function () { Swal.showLoading(); }
                });

                $.post('', { action: 'surum_aktar', sistem_id: hedef }, function (response) {
                    Swal.close();

                    if (!response.success) {
                        Swal.fire('Hata', response.message || 'Aktarım başarısız.', 'error');
                        return;
                    }

                    let rapor = '<div class="text-start"><table class="table table-sm mb-0">';
                    (response.sonuclar || []).forEach(function (r) {
                        const renk = r.durum === 'OK' ? 'text-success' : 'text-danger';
                        rapor += '<tr><td><strong>' + htmlKacis(r.sistem) + '</strong></td>' +
                                 '<td class="' + renk + '">' + r.eklenen + ' sürüm</td>' +
                                 '<td class="small text-muted">' + htmlKacis(r.mesaj) + '</td></tr>';
                    });
                    rapor += '</table></div>';

                    Swal.fire({
                        title: response.toplam + ' sürüm eklendi',
                        html: rapor,
                        icon: response.toplam > 0 ? 'success' : 'info',
                        width: 700
                    });

                    bekleyenYukle(true);
                    veriYukle();
                }, 'json')
                .fail(function () {
                    Swal.close();
                    Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
                });
            });
        }

        // ===============================================================
        // Başlangıç
        // ===============================================================

        $(document).ready(function () {
            select2Kur('#filter_sistem');
            select2Kur('#filter_tip');
            select2Kur('#filter_kategori');

            const ortakAyar = {
                pageLength: 25,
                dom: 'lrtip',
                order: [],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' }
            };

            gunlukTable = $('#gunlukTable').DataTable(Object.assign({}, ortakAyar, { order: [[0, 'desc']] }));
            aylikTable  = $('#aylikTable').DataTable(Object.assign({}, ortakAyar, { order: [[0, 'desc']], pageLength: 12 }));
            sistemTable = $('#sistemTable').DataTable(Object.assign({}, ortakAyar, { paging: false, info: false }));
            detayTable  = $('#detayTable').DataTable(Object.assign({}, ortakAyar, { order: [[0, 'desc']], scrollX: true }));
            bekleyenTable = $('#bekleyenTable').DataTable(Object.assign({}, ortakAyar, { paging: false, info: false }));

            // Sekme degisiminde tablo genisligini duzelt
            $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
                $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
            });

            $('#filterForm').on('submit', function (e) {
                e.preventDefault();
                filtreUygula();
            });

            $('#clearFilters').on('click', function () {
                $('#filter_bas').val('');
                $('#filter_bit').val('');
                $('#filter_search').val('');
                $('#filter_bosluk').val(90);
                $('#filter_pay').val(30);
                $('#filter_sistem').val(null).trigger('change');
                $('#filter_tip').val(null).trigger('change');
                $('#filter_kategori').val(null).trigger('change');
                filtreUygula();
            });

            $('#btnBekleyenYenile').on('click', function () { bekleyenYukle(false); });

            $('#btnTumunuAktar').on('click', function () {
                surumAktar('tumu', 'Tüm sistemlerdeki <strong>' + $('#bekleyenToplam').text() +
                                   '</strong> bekleyen sürüm ilgili veritabanlarına yazılacak.');
            });

            $('#bekleyenTable tbody').on('click', '.btn-aktar', function () {
                const sistemId = $(this).data('sistem');
                const sistemAdi = $(this).closest('tr').find('td:first strong').text();
                surumAktar(sistemId, '<strong>' + htmlKacis(sistemAdi) + '</strong> sisteminin bekleyen sürümleri yazılacak.');
            });

            $('#btnYenile').on('click', veriYukle);
            $('#btnExcel').on('click', excelAktar);
            $('#btnYazdir').on('click', function () { window.print(); });

            veriYukle();
            bekleyenYukle(true);
        });
    </script>
</body>
</html>
