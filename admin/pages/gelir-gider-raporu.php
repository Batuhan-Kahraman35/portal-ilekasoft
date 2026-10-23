<?php
/**
 * Admin Panel - Gelir ve Gider Raporu
 *
 * Gelir / gider kaynakları tanim_GelirGiderKaynaklari tablosundan okunur (Durum = 1, Sira).
 * Her kaynak Kodu için aşağıda bir sorgu fonksiyonu vardır (KAYNAK_SORGULARI haritası);
 * haritada karşılığı olmayan kod rapordan atlanır ve sayfada uyarı rozeti gösterilir.
 *
 * Her sorgu fonksiyonu (baslangic, bitis, firmaIdListesi|null) alır ve şu satırları döner:
 *   ay (YYYY-MM), firma_id, firma_adi, alt_kirilim, tutar, adet,
 *   platform     (tanim_GelirGiderPlatformlari.Kod; NULL → platformsuz, yalnız "Tümü"de görünür),
 *   detay_tur    (kırılım satırı tıklanınca açılacak detay türü: DETAY_TURLERI anahtarı),
 *   detay_anahtar(detay türünün kayıt anahtarı: pazaryeri id, banka id, 'ARAS|GIDEN' ...)
 *
 * Firma kapsamı: null → tüm firmalar (firmasız kayıtlar dahil), dizi → yalnız o firmalar.
 * Kullanıcı firma kısıtlıysa (PageAuth::firmaKisiti) liste daima kendi firmasıdır.
 * Platform kapsamı: seçiliyse yalnız o platform etiketli satırlar toplanır (raporHesapla).
 *
 * Özet rapor: satır sayısı ay × kaynak kadar küçüktür → tek AJAX çağrısı, client-side görünüm.
 * Kırılım detayı: her kırılım satırı → 'detay' action (server-side DataTables, iki aşamalı sorgu).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

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

// Firma kısıtı: null → kısıt yok, >0 → yalnız o firma, 0 → hiç kayıt yok
$kisitFirmaId = PageAuth::firmaKisiti($pagePermissions, $user['kullanici_id']);

// Ayarlar (masraf kuralları, kaynak tanımları) tüm firmaların raporunu etkiler:
// yalnız firma kısıtı olmayan ve düzenleme yetkisi olan kullanıcı değiştirir.
$ayarYetki = $kisitFirmaId === null && !empty($pagePermissions['can_edit']);

/** Rapor kapsam notu: sayfada InfoBox altında, Excel'de en üst satırda */
const RAPOR_NOTU = 'Gelir tutarları KDV dahildir ve müşterinin ödediği tutardır (indirimler düşülmüş). Pazaryeri komisyonu ve kargo ayrı gider kalemi olarak gösterilir.';

/**
 * Platform (iş kolu) kodu: tanim_GelirGiderPlatformlari.Kod ile eşleşir.
 * Kaynak sorgularındaki satır etiketi buradan girer (platformSql); kod değişirse tek yer.
 */
const PLATFORM_ETICARET = 'e_ticaret';

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Gelir ve Gider';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// =====================================================================
// Yardımcılar
// =====================================================================

/** Y-m-d biçiminde ve geçerli bir tarih değilse boş döner; geçersiz tarih SQL'e gitmez */
function tarihGecerli($t)
{
    $t = trim((string)$t);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) {
        return '';
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $t : '';
}

/**
 * Firma kapsamı koşulu. Kolon adı sabit verilir (kullanıcı girdisi değil).
 * null → koşul yok (firmasız kayıtlar dahil), [] → hiç kayıt yok, dizi → IN (int).
 */
function firmaKosulu($firmaIdler, $kolon)
{
    if ($firmaIdler === null) {
        return '';
    }
    $idler = array_values(array_filter(array_map('intval', $firmaIdler), fn($i) => $i > 0));
    return $idler ? " AND $kolon IN (" . implode(',', $idler) . ") " : " AND 1 = 0 ";
}

/**
 * Tıklanan kırılım satırının firması (detay için). Kolon adı sabit verilir.
 * null → koşul yok, 0 → firmasız kayıtlar (IS NULL), >0 → yalnız o firma.
 */
function satirFirmaKosulu($firmaId, $kolon)
{
    if ($firmaId === null) {
        return '';
    }
    $firmaId = (int)$firmaId;
    return $firmaId > 0 ? " AND $kolon = $firmaId " : " AND $kolon IS NULL ";
}

/**
 * Detay anahtarı olan id listesi → koşul (int'e çevrilir). 0 → kolon NULL olan kayıtlar
 * (ör. bankası tanımsız hesap). Boş liste hiç kayıt döndürmez.
 */
function anahtarIdKosulu($idler, $kolon)
{
    $idler = array_values(array_unique(array_map('intval', (array)$idler)));
    $parca = [];
    $dolu  = array_filter($idler, fn($i) => $i > 0);
    if ($dolu) {
        $parca[] = "$kolon IN (" . implode(',', $dolu) . ")";
    }
    if (in_array(0, $idler, true)) {
        $parca[] = "$kolon IS NULL";
    }
    return $parca ? ' AND (' . implode(' OR ', $parca) . ') ' : ' AND 1 = 0 ';
}

/** Platform satır etiketi (SQL sabiti). Kod sabitten gelir; yine de desen dışıysa NULL yazılır. */
function platformSql($kod)
{
    $kod = (string)$kod;
    return preg_match('/^[a-z0-9_]{1,50}$/', $kod)
        ? "CAST('$kod' AS VARCHAR(50))"
        : 'CAST(NULL AS VARCHAR(50))';
}

/** Renk / ikon desenleri: hem görüntülemede (renkGuvenli/ikonGuvenli) hem kayıtta aynı kural */
const RENK_DESENI = '/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,30})$/';
const IKON_DESENI = '/^[a-zA-Z0-9 _-]{1,60}$/';

/** Tanım tablosundaki renk CSS'e girer; yalnız #hex veya renk adı kabul edilir */
function renkGuvenli($renk)
{
    $renk = trim((string)$renk);
    return preg_match(RENK_DESENI, $renk) ? $renk : '#6c757d';
}

/** İkon sınıfı HTML'e girer; yalnız harf, rakam, boşluk ve tire */
function ikonGuvenli($ikon)
{
    $ikon = trim((string)$ikon);
    return preg_match(IKON_DESENI, $ikon) ? $ikon : 'bi bi-circle';
}

// =====================================================================
// Banka masraf tanımı (tek yer): rapor, masraf detayı ve kural önizlemesi bunu kullanır.
// Takma adlar sabittir: h = banka_HesapHareketleri, a = banka_Hesap.
// =====================================================================

/** Masraf satırı tutarı: bazı bankalar (Akbank) masrafı eksi işaretle verir → ABS */
const BANKA_MASRAF_TUTAR = 'ABS(ISNULL(TRY_CAST(h.banka_HesapHareketleriAmount AS DECIMAL(18,2)), 0))';

/**
 * Satıra uyan aktif bir kural var mı? (tip: 'dahil' | 'haric')
 * $atlaKuralId > 0 → o kural yok sayılır (önizlemede "bu kural olmasaydı" durumu için).
 */
function bankaKuralVar($tip, $atlaKuralId = 0)
{
    $tip  = $tip === 'haric' ? 'haric' : 'dahil';
    $atla = (int)$atlaKuralId > 0 ? ' AND r.BankaMasrafKurallari_id <> ' . (int)$atlaKuralId : '';
    return "EXISTS (SELECT 1 FROM dbo.tanim_BankaMasrafKurallari r
                    WHERE r.BankaMasrafKurallari_Durum = 1 AND r.BankaMasrafKurallari_Tip = '$tip'
                      AND (r.BankaMasrafKurallari_Bankalar_id IS NULL OR r.BankaMasrafKurallari_Bankalar_id = a.bankaHesap_banka_id)
                      AND h.banka_HesapHareketleriDescription LIKE r.BankaMasrafKurallari_Desen{$atla})";
}

/**
 * Banka masraf satırı koşulu. Yalnız BORÇ satırları:
 *  a) eski banka entegrasyonunun işaretlediği satırlar (banka_HesapHareketleriCost = 1;
 *     açıklama ana işlemden kopyalandığı için hariç kuralları bunlara uygulanmaz), veya
 *  b) bir 'dahil' desenine uyup hiçbir 'haric' desenine uymayanlar.
 * Bayraklı ALACAK satırları ters işlem iadesidir, alınmaz.
 */
function bankaMasrafKosulu($atlaKuralId = 0)
{
    return "(h.banka_HesapHareketleriBorcAlacak = 'B'
             AND (   h.banka_HesapHareketleriCost = 1
                  OR (" . bankaKuralVar('dahil', $atlaKuralId) . "
                      AND NOT " . bankaKuralVar('haric', $atlaKuralId) . ")))";
}

// =====================================================================
// Kaynak sorguları: Kod → fonksiyon(baslangic, bitis, firmaIdler|null)
// Ortak dönüş kolonları: ay, firma_id, firma_adi, alt_kirilim, tutar, adet,
//                        platform, detay_tur, detay_anahtar
// FROM + WHERE yardımcıları (pazaryeriKosulu, kargoKosulu, bankaMasrafKosulu) kırılım
// detayında da aynen kullanılır: detay toplamı kırılım toplamıyla aynı kalır.
// =====================================================================

/** Komisyon kalemleri: kaynakKomisyon ve komisyon detayı aynı JOIN'i kullanır (c = kalem) */
const KOMISYON_KALEM_JOIN = "
        INNER JOIN dbo.PazaryeriSiparisKalemleri c
                ON c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
               AND c.PazaryeriSiparisKalemleri_Durum = 1
";

/** Pazaryeri siparişleri ortak FROM + WHERE (iptal / iade hariç, sipariş tarihine göre) */
function pazaryeriKosulu($firmaIdler, $kalemJoin = '')
{
    return "
        FROM dbo.PazaryeriSiparisleri s
        INNER JOIN dbo.EntegrasyonKanallari k
                ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
        INNER JOIN dbo.tanim_Pazaryerleri p
                ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
        $kalemJoin
        LEFT JOIN dbo.Firmalar f
                ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        WHERE s.PazaryeriSiparisleri_Durum = 1
          AND ISNULL(s.PazaryeriSiparisleri_DurumKod, '') NOT IN ('iptal', 'iade')
          AND s.PazaryeriSiparisleri_SiparisTarihi >= CAST(? AS DATE)
          AND s.PazaryeriSiparisleri_SiparisTarihi <  DATEADD(DAY, 1, CAST(? AS DATE))
          " . firmaKosulu($firmaIdler, 'k.EntegrasyonKanallari_Firmalar_id') . "
    ";
}

function kaynakPazaryeriSatis($bas, $bit, $firmaIdler)
{
    return Database::getInstance()->fetchAll("
        SELECT
            CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS ay,
            k.EntegrasyonKanallari_Firmalar_id                         AS firma_id,
            ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)             AS firma_adi,
            p.Pazaryerleri_Ad                                          AS alt_kirilim,
            SUM(ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0))         AS tutar,
            COUNT(*)                                                   AS adet,
            " . platformSql(PLATFORM_ETICARET) . "                     AS platform,
            CAST('satis' AS VARCHAR(30))                               AS detay_tur,
            CAST(p.Pazaryerleri_id AS VARCHAR(50))                     AS detay_anahtar
        " . pazaryeriKosulu($firmaIdler) . "
        GROUP BY CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120),
                 k.EntegrasyonKanallari_Firmalar_id, f.firma_adi, f.firma_unvan, p.Pazaryerleri_Ad, p.Pazaryerleri_id
    ", [$bas, $bit]);
}

/**
 * Komisyon giderleri:
 *  - Pazaryeri kalem komisyonları (alt kırılım = pazaryeri adı; platform = e-ticaret)
 *  - Banka masrafları (alt kırılım = "Banka – <banka adı>"; platformsuz), tanım: bankaMasrafKosulu()
 * detay_anahtar: pazaryeri satırında Pazaryerleri_id, banka satırında banka id (tanımsız banka → 0).
 */
function kaynakKomisyon($bas, $bit, $firmaIdler)
{
    return Database::getInstance()->fetchAll("
        SELECT
            CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS ay,
            k.EntegrasyonKanallari_Firmalar_id                         AS firma_id,
            ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)             AS firma_adi,
            p.Pazaryerleri_Ad                                          AS alt_kirilim,
            SUM(ISNULL(c.PazaryeriSiparisKalemleri_KomisyonTutar, 0))  AS tutar,
            COUNT(DISTINCT s.PazaryeriSiparisleri_id)                  AS adet,
            " . platformSql(PLATFORM_ETICARET) . "                     AS platform,
            CAST('komisyon_pazaryeri' AS VARCHAR(30))                  AS detay_tur,
            CAST(p.Pazaryerleri_id AS VARCHAR(50))                     AS detay_anahtar
        " . pazaryeriKosulu($firmaIdler, KOMISYON_KALEM_JOIN) . "
        GROUP BY CONVERT(CHAR(7), s.PazaryeriSiparisleri_SiparisTarihi, 120),
                 k.EntegrasyonKanallari_Firmalar_id, f.firma_adi, f.firma_unvan, p.Pazaryerleri_Ad, p.Pazaryerleri_id

        UNION ALL

        SELECT
            CONVERT(CHAR(7), h.banka_HesapHareketleriDateTime, 120)    AS ay,
            a.bankaHesap_firma_id                                      AS firma_id,
            ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)             AS firma_adi,
            N'Banka – ' + ISNULL(b.banka_adi, N'Tanımsız')             AS alt_kirilim,
            SUM(" . BANKA_MASRAF_TUTAR . ")                            AS tutar,
            COUNT(*)                                                   AS adet,
            " . platformSql(null) . "                                  AS platform,
            CAST('banka' AS VARCHAR(30))                               AS detay_tur,
            CAST(ISNULL(a.bankaHesap_banka_id, 0) AS VARCHAR(50))      AS detay_anahtar
        FROM dbo.banka_HesapHareketleri h
        INNER JOIN dbo.banka_Hesap a ON a.bankaHesap_id = h.banka_HesapId
        LEFT JOIN dbo.bankalar b     ON b.banka_id = a.bankaHesap_banka_id
        LEFT JOIN dbo.Firmalar f     ON f.firma_id = a.bankaHesap_firma_id
        WHERE " . bankaMasrafKosulu() . "
          AND h.banka_HesapHareketleriDateTime >= CAST(? AS DATE)
          AND h.banka_HesapHareketleriDateTime <  DATEADD(DAY, 1, CAST(? AS DATE))
          " . firmaKosulu($firmaIdler, 'a.bankaHesap_firma_id') . "
        GROUP BY CONVERT(CHAR(7), h.banka_HesapHareketleriDateTime, 120),
                 a.bankaHesap_firma_id, f.firma_adi, f.firma_unvan, b.banka_adi, a.bankaHesap_banka_id
    ", [$bas, $bit, $bas, $bit]);
}

/**
 * Kargo gönderileri ortak FROM + WHERE (çıkış tarihine göre).
 * Şimdilik yalnız Aras: ücretini firmanın ödediği gönderiler: giden + ÜG (gönderici öder),
 * gelen + ÜA (alıcı öder). Başka kargo firması eklenirse kuralı buraya OR ile eklenir.
 */
function kargoKosulu($firmaIdler)
{
    return "
        FROM dbo.kargo_gonderiler g
        LEFT JOIN dbo.EntegrasyonKanallari k
               ON k.EntegrasyonKanallari_id = g.kargo_gonderiler_EntegrasyonKanallari_id
        LEFT JOIN dbo.Firmalar f
               ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        WHERE g.kargo_gonderiler_Durum = 1
          AND g.kargo_gonderiler_Kaynak = 'ARAS'
          AND (   (g.kargo_gonderiler_Yon = 'GIDEN' AND g.kargo_gonderiler_OdemeTipi = N'ÜG')
               OR (g.kargo_gonderiler_Yon = 'GELEN' AND g.kargo_gonderiler_OdemeTipi = N'ÜA'))
          AND g.kargo_gonderiler_CikisTarihi >= CAST(? AS DATE)
          AND g.kargo_gonderiler_CikisTarihi <  DATEADD(DAY, 1, CAST(? AS DATE))
          " . firmaKosulu($firmaIdler, 'k.EntegrasyonKanallari_Firmalar_id') . "
    ";
}

/**
 * Kargo giderleri (platformsuz). detay_anahtar = kargo firması kodu + yön (ör. 'ARAS|GIDEN').
 */
function kaynakKargo($bas, $bit, $firmaIdler)
{
    return Database::getInstance()->fetchAll("
        SELECT
            CONVERT(CHAR(7), g.kargo_gonderiler_CikisTarihi, 120)      AS ay,
            k.EntegrasyonKanallari_Firmalar_id                         AS firma_id,
            ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)             AS firma_adi,
            CASE g.kargo_gonderiler_Yon WHEN 'GIDEN' THEN N'Aras – Giden' ELSE N'Aras – Gelen' END AS alt_kirilim,
            SUM(ISNULL(g.kargo_gonderiler_Tutar, 0))                   AS tutar,
            COUNT(*)                                                   AS adet,
            " . platformSql(null) . "                                  AS platform,
            CAST('kargo' AS VARCHAR(30))                               AS detay_tur,
            CAST(g.kargo_gonderiler_Kaynak + '|' + g.kargo_gonderiler_Yon AS VARCHAR(50)) AS detay_anahtar
        " . kargoKosulu($firmaIdler) . "
        GROUP BY CONVERT(CHAR(7), g.kargo_gonderiler_CikisTarihi, 120),
                 k.EntegrasyonKanallari_Firmalar_id, f.firma_adi, f.firma_unvan,
                 g.kargo_gonderiler_Kaynak, g.kargo_gonderiler_Yon
    ", [$bas, $bit]);
}

/** Yeni kaynak eklemek: tanım tablosuna Kod ile kayıt + buraya fonksiyon */
const KAYNAK_SORGULARI = [
    'pazaryeri_satis'    => 'kaynakPazaryeriSatis',
    'komisyon'           => 'kaynakKomisyon',
    'kargo'              => 'kaynakKargo',
];

/** Aktif kaynaklar: gelirler önce, giderler sonra; her grup Sira'ya göre */
function aktifKaynaklar()
{
    $liste = Database::getInstance()->fetchAll("
        SELECT GelirGiderKaynaklari_id, GelirGiderKaynaklari_Kod, GelirGiderKaynaklari_Ad,
               GelirGiderKaynaklari_Tip, GelirGiderKaynaklari_Aciklama,
               GelirGiderKaynaklari_Renk, GelirGiderKaynaklari_Ikon, GelirGiderKaynaklari_Sira
        FROM dbo.tanim_GelirGiderKaynaklari
        WHERE GelirGiderKaynaklari_Durum = 1
        ORDER BY CASE GelirGiderKaynaklari_Tip WHEN 'gelir' THEN 0 ELSE 1 END,
                 GelirGiderKaynaklari_Sira, GelirGiderKaynaklari_id
    ");

    return array_map(fn($k) => [
        'kod'      => (string)$k['GelirGiderKaynaklari_Kod'],
        'ad'       => (string)$k['GelirGiderKaynaklari_Ad'],
        'tip'      => $k['GelirGiderKaynaklari_Tip'] === 'gelir' ? 'gelir' : 'gider',
        'aciklama' => (string)($k['GelirGiderKaynaklari_Aciklama'] ?? ''),
        'renk'     => renkGuvenli($k['GelirGiderKaynaklari_Renk'] ?? ''),
        'ikon'     => ikonGuvenli($k['GelirGiderKaynaklari_Ikon'] ?? ''),
        'destekli' => isset(KAYNAK_SORGULARI[$k['GelirGiderKaynaklari_Kod']]),
    ], $liste);
}

/**
 * Aktif platformlar (Sira, Ad): [kod => ad]. Tablo henüz kurulmadıysa boş liste
 * (filtrede yalnız "Tümü" kalır, sayfa çökmez). İstek başına bir kez okunur.
 */
function platformListesi()
{
    static $liste = null;
    if ($liste !== null) {
        return $liste;
    }
    $db    = Database::getInstance();
    $liste = [];
    $tablo = $db->fetchOne("SELECT OBJECT_ID('dbo.tanim_GelirGiderPlatformlari', 'U') AS id");
    if (empty($tablo['id'])) {
        return $liste;
    }
    foreach ($db->fetchAll("
        SELECT GelirGiderPlatformlari_Kod AS kod, GelirGiderPlatformlari_Ad AS ad
        FROM dbo.tanim_GelirGiderPlatformlari
        WHERE GelirGiderPlatformlari_Durum = 1
        ORDER BY GelirGiderPlatformlari_Sira, GelirGiderPlatformlari_Ad
    ") as $p) {
        $liste[(string)$p['kod']] = (string)$p['ad'];
    }
    return $liste;
}

/**
 * Filtre girdisini çözer. Hata varsa ['hata' => mesaj] döner.
 * Firma: kısıtlı kullanıcıda daima kendi firması; aksi halde '' → tüm firmalar (null).
 * Platform: '' → tümü (null); aksi halde tablodaki aktif kodlardan biri olmalı.
 */
function filtreCoz(array $girdi, $kisitFirmaId)
{
    $bas = tarihGecerli($girdi['bas_tarih'] ?? '') ?: date('Y-m-d');
    $bit = tarihGecerli($girdi['bit_tarih'] ?? '') ?: date('Y-m-d');
    if ($bas > $bit) {
        return ['hata' => 'Başlangıç tarihi bitiş tarihinden sonra olamaz.'];
    }

    $firmaId = (int)($girdi['firma_id'] ?? 0);
    if ($kisitFirmaId !== null) {
        $firmaIdler = [(int)$kisitFirmaId]; // 0 → firmaKosulu "1 = 0" üretir
    } else {
        $firmaIdler = $firmaId > 0 ? [$firmaId] : null;
    }

    $platform = trim((string)($girdi['platform'] ?? ''));
    if ($platform !== '' && !isset(platformListesi()[$platform])) {
        return ['hata' => 'Geçersiz platform.'];
    }

    $kirilim = kirilimCoz($girdi['kirilim'] ?? []);
    if (isset($kirilim['hata'])) {
        return $kirilim;
    }

    return [
        'bas'        => $bas,
        'bit'        => $bit,
        'firmaIdler' => $firmaIdler,
        'platform'   => $platform !== '' ? $platform : null,
        'platformAd' => $platform !== '' ? platformListesi()[$platform] : 'Tümü',
        'kirilim'    => $kirilim,
    ];
}

/** Kırılım filtresi üst sınırı (öğe sayısı / alt kırılım metni uzunluğu) */
const KIRILIM_EN_FAZLA = 50;
const KIRILIM_METIN_UZUNLUK = 150;

/**
 * Kırılım filtresi: 'kaynak_kod|alt_kirilim' dizisi → ['kod|metin' => 'kod|metin'].
 * Biçimi bozuk, kodu aktif kaynaklarda olmayan veya metni uzun öğeler yok sayılır
 * (raporHesapla ayrıca sonuçta bulunmayan çiftleri düşürür ve uygulanan seçimi döner).
 */
function kirilimCoz($girdi)
{
    if ($girdi === '' || $girdi === null) {
        return [];
    }
    $girdi = (array)$girdi;
    if (count($girdi) > KIRILIM_EN_FAZLA) {
        return ['hata' => 'En fazla ' . KIRILIM_EN_FAZLA . ' kırılım seçilebilir.'];
    }

    $kodlar = array_column(array_filter(aktifKaynaklar(), fn($k) => $k['destekli']), 'kod', 'kod');
    $secim  = [];
    foreach ($girdi as $o) {
        if (!is_string($o)) {
            continue;
        }
        $parca = explode('|', trim($o), 2);
        if (count($parca) !== 2 || !isset($kodlar[$parca[0]])) {
            continue;
        }
        $metin = $parca[1];
        if ($metin === '' || !mb_check_encoding($metin, 'UTF-8')
            || mb_strlen($metin, 'UTF-8') > KIRILIM_METIN_UZUNLUK || preg_match('/[\x00-\x1F\x7F]/', $metin)) {
            continue;
        }
        $anahtar = $parca[0] . '|' . $metin;
        $secim[$anahtar] = $anahtar;
    }
    return $secim;
}

/** bas..bit arasındaki tüm aylar (YYYY-MM); boş aylar da grafikte görünsün */
function aralikAylari($bas, $bit)
{
    $aylar = [];
    $d   = new DateTime(substr($bas, 0, 7) . '-01');
    $son = substr($bit, 0, 7);
    while (($a = $d->format('Y-m')) <= $son && count($aylar) < 240) {
        $aylar[] = $a;
        $d->modify('+1 month');
    }
    return $aylar;
}

/**
 * Tüm aktif kaynakları çalıştırıp rapor yapısını üretir.
 * $platform seçiliyse yalnız o platform etiketli satırlar toplanır (platformsuz satırlar dışarıda).
 * $kirilimSecim ['kod|alt_kirilim' => ...] doluysa yalnız o kaynak + alt kırılım satırları toplanır.
 * Seçenek listesi (kirilimlar) kırılım filtresinden ÖNCEKİ sonuçtan (tarih + firma + platform)
 * üretilir; seçimden bu listede olmayanlar yok sayılır, uygulanan seçim kirilimSecili ile döner.
 */
function raporHesapla($bas, $bit, $firmaIdler, $platform = null, array $kirilimSecim = [])
{
    $kaynaklar = aktifKaynaklar();
    $aylar     = aralikAylari($bas, $bit);

    $aylik     = [];   // [ay][kod] = tutar
    $kirilim   = [];   // [kod][] = {alt_kirilim, firma_id, firma_adi, tutar, adet, detay_tur, anahtarlar}
    $kaynakTop = [];   // [kod] = {tutar, adet}
    $atlanan   = [];
    $satirlar  = [];   // [kod] = platform filtresinden geçen satırlar
    $secenek   = [];   // ['kod|alt' => {kaynak_kod, kaynak_ad, tip, alt_kirilim}]

    foreach ($aylar as $a) {
        $aylik[$a] = [];
    }

    // 1) Sorgular + platform filtresi; kırılım seçenekleri bu kümeden
    foreach ($kaynaklar as $k) {
        $kod = $k['kod'];
        if (!$k['destekli']) {
            $atlanan[] = ['kod' => $kod, 'ad' => $k['ad']];
            continue;
        }
        $fonk = KAYNAK_SORGULARI[$kod];
        $satirlar[$kod] = [];
        foreach ($fonk($bas, $bit, $firmaIdler) as $r) {
            if ($platform !== null && ($r['platform'] ?? null) !== $platform) {
                continue;
            }
            $r['alt_kirilim'] = (string)($r['alt_kirilim'] ?? '-');
            $satirlar[$kod][] = $r;
            $secenek[$kod . '|' . $r['alt_kirilim']] ??= [
                'kaynak_kod'  => $kod,
                'kaynak_ad'   => $k['ad'],
                'tip'         => $k['tip'],
                'alt_kirilim' => $r['alt_kirilim'],
            ];
        }
    }

    // Seçenekler: kaynak sırası (aktifKaynaklar) → alt kırılım adı
    $kaynakSira = array_flip(array_column($kaynaklar, 'kod'));
    $harmanla   = class_exists('Collator') ? new Collator('tr_TR') : null;
    $kirilimlar = array_values($secenek);
    usort($kirilimlar, fn($x, $y) => ($kaynakSira[$x['kaynak_kod']] <=> $kaynakSira[$y['kaynak_kod']])
        ?: ($harmanla ? $harmanla->compare($x['alt_kirilim'], $y['alt_kirilim']) : strcmp($x['alt_kirilim'], $y['alt_kirilim'])));

    // Uygulanan seçim: yalnız sonuçta bulunan çiftler (boşsa filtre yok)
    $uygulanan = array_values(array_intersect_key($kirilimSecim, $secenek));
    $uygSet    = array_flip($uygulanan);

    // 2) Toplama
    foreach ($satirlar as $kod => $satir) {
        $grup = [];
        $kaynakTop[$kod] = ['tutar' => 0.0, 'adet' => 0];

        foreach ($satir as $r) {
            if ($uygSet && !isset($uygSet[$kod . '|' . $r['alt_kirilim']])) {
                continue;
            }
            $ay    = (string)$r['ay'];
            $tutar = round((float)$r['tutar'], 2);
            $adet  = (int)$r['adet'];

            $aylik[$ay][$kod] = ($aylik[$ay][$kod] ?? 0) + $tutar;
            $kaynakTop[$kod]['tutar'] += $tutar;
            $kaynakTop[$kod]['adet']  += $adet;

            $firmaAdi = $r['firma_adi'] !== null && $r['firma_adi'] !== '' ? $r['firma_adi'] : '(Firmasız)';
            $anahtar  = $r['alt_kirilim'] . "\x1F" . (int)$r['firma_id'];
            if (!isset($grup[$anahtar])) {
                $grup[$anahtar] = [
                    'alt_kirilim' => (string)($r['alt_kirilim'] ?? '-'),
                    'firma_id'    => (int)$r['firma_id'],   // 0 → firmasız (detayda IS NULL)
                    'firma_adi'   => (string)$firmaAdi,
                    'tutar'       => 0.0,
                    'adet'        => 0,
                    'detay_tur'   => $r['detay_tur'] ?? null,
                    'anahtarlar'  => [],
                ];
            }
            $grup[$anahtar]['tutar'] += $tutar;
            $grup[$anahtar]['adet']  += $adet;

            // Aynı adlı birden fazla kayıt (ör. iki banka kaydı) tek kırılımda birleşebilir → anahtar listesi
            if (isset($r['detay_anahtar']) && $r['detay_anahtar'] !== '') {
                $grup[$anahtar]['anahtarlar'][(string)$r['detay_anahtar']] = (string)$r['detay_anahtar'];
            }
        }
        foreach ($grup as &$g) {
            $g['anahtarlar'] = array_values($g['anahtarlar']);
        }
        unset($g);

        $liste = array_map(function ($g) { $g['tutar'] = round($g['tutar'], 2); return $g; }, array_values($grup));
        usort($liste, fn($x, $y) => $y['tutar'] <=> $x['tutar']);
        $kaynakTop[$kod]['tutar'] = round($kaynakTop[$kod]['tutar'], 2);
        $kirilim[$kod] = $liste;
    }

    // Ay satırları: kaynak kırılımı + gelir / gider / net
    $tipler = array_column($kaynaklar, 'tip', 'kod');
    ksort($aylik);
    $aySatirlari = [];
    $topGelir = 0.0;
    $topGider = 0.0;

    foreach ($aylik as $ay => $kodlar) {
        $gelir = 0.0;
        $gider = 0.0;
        foreach ($kodlar as $kod => $t) {
            if (($tipler[$kod] ?? '') === 'gelir') { $gelir += $t; } else { $gider += $t; }
        }
        $aySatirlari[] = [
            'ay'       => $ay,
            'kaynaklar'=> (object)array_map(fn($t) => round($t, 2), $kodlar),
            'gelir'    => round($gelir, 2),
            'gider'    => round($gider, 2),
            'net'      => round($gelir - $gider, 2),
        ];
        $topGelir += $gelir;
        $topGider += $gider;
    }

    return [
        'filtre'    => ['bas' => $bas, 'bit' => $bit, 'platform' => $platform, 'kirilim' => $uygulanan],
        'kirilimlar'    => $kirilimlar,
        'kirilimSecili' => $uygulanan,
        'kaynaklar' => $kaynaklar,
        'atlanan'   => $atlanan,
        'aylar'     => $aySatirlari,
        'kaynakTop' => (object)$kaynakTop,
        'kirilim'   => (object)$kirilim,
        'ozet'      => [
            'gelir' => round($topGelir, 2),
            'gider' => round($topGider, 2),
            'net'   => round($topGelir - $topGider, 2),
            'marj'  => $topGelir > 0 ? round(($topGelir - $topGider) / $topGelir * 100, 2) : null,
        ],
    ];
}

/**
 * Firma dropdown'u: yalnız rapora veri sağlayan aktif firmalar.
 * Kaynak firmaları (UNION, EXISTS ile ucuz):
 *   (a) pazaryeri kanalları: entegrasyonu tanim_Pazaryerleri'ne bağlı kanalların firması
 *   (b) kargo kanalları: kargo_gonderiler'de kullanılan kanalların firması
 *   (c) bankalar: en az bir hareketi olan banka hesaplarının firması
 * Yeni kaynak (KAYNAK_SORGULARI) eklendiğinde firması buraya da UNION ile eklenir.
 * Kısıtlı kullanıcıda listede olmasa bile kendi firması gösterilir (filtre boş kalmasın).
 */
function firmaListesi($kisitFirmaId)
{
    $db = Database::getInstance();
    if ($kisitFirmaId !== null) {
        [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'firma_id');
        return $db->fetchAll("
            SELECT firma_id, firma_adi, firma_unvan
            FROM dbo.Firmalar
            WHERE firma_durum = 1 {$kisitSql}
            ORDER BY firma_adi
        ", $kisitParams);
    }

    return $db->fetchAll("
        SELECT f.firma_id, f.firma_adi, f.firma_unvan
        FROM dbo.Firmalar f
        WHERE f.firma_durum = 1
          AND f.firma_id IN (
              -- (a) pazaryeri kanalları
              SELECT k.EntegrasyonKanallari_Firmalar_id
              FROM dbo.EntegrasyonKanallari k
              INNER JOIN dbo.Entegrasyonlar e ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
              WHERE EXISTS (SELECT 1 FROM dbo.tanim_Pazaryerleri p WHERE p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod)
              UNION
              -- (b) kargo kanalları
              SELECT k.EntegrasyonKanallari_Firmalar_id
              FROM dbo.EntegrasyonKanallari k
              WHERE EXISTS (SELECT 1 FROM dbo.kargo_gonderiler g
                            WHERE g.kargo_gonderiler_EntegrasyonKanallari_id = k.EntegrasyonKanallari_id
                              AND g.kargo_gonderiler_Durum = 1)
              UNION
              -- (c) hareketi olan banka hesapları
              SELECT a.bankaHesap_firma_id
              FROM dbo.banka_Hesap a
              WHERE EXISTS (SELECT 1 FROM dbo.banka_HesapHareketleri h WHERE h.banka_HesapId = a.bankaHesap_id)
          )
        ORDER BY f.firma_adi
    ");
}

// =====================================================================
// Ayarlar: banka masraf kuralları + kaynak tanımları
// =====================================================================

/** LIKE araması için kullanıcı metnindeki joker karakterler etkisizleştirilir */
function likeKacis($s)
{
    return strtr((string)$s, ['[' => '[[]', '%' => '[%]', '_' => '[_]']);
}

// =====================================================================
// Kırılım detayı: tür → tanım. Kırılım satırının detay_tur değeri buradaki anahtardır.
//   platform : türün satır etiketi (seçili platform farklıysa detay boş döner, raporla aynı)
//   anahtar  : 'id' (int, 0 → NULL) | 'kargo' ('KAYNAK|YON')
// =====================================================================
const DETAY_TURLERI = [
    'satis'              => ['platform' => PLATFORM_ETICARET, 'anahtar' => 'id'],
    'komisyon_pazaryeri' => ['platform' => PLATFORM_ETICARET, 'anahtar' => 'id'],
    'banka'              => ['platform' => null,              'anahtar' => 'id'],
    'kargo'              => ['platform' => null,              'anahtar' => 'kargo'],
];

/** Firma görünen adı (f = Firmalar takma adı; sıralama ve dış SELECT aynı ifadeyi kullanır) */
const FIRMA_AD_SQL = "ISNULL(NULLIF(f.firma_adi, ''), f.firma_unvan)";

/**
 * Detay isteği doğrulaması. Hata varsa ['hata' => mesaj] döner.
 * tur whitelist; anahtarlar türüne göre (id → rakam, kargo → KAYNAK|GIDEN/GELEN); satır firması int.
 */
function detayGirdisi(array $g)
{
    $tur = (string)($g['tur'] ?? '');
    if (!isset(DETAY_TURLERI[$tur])) {
        return ['hata' => 'Geçersiz detay türü.'];
    }
    $desen = DETAY_TURLERI[$tur]['anahtar'] === 'kargo' ? '/^[A-Z0-9_]{1,10}\|(GIDEN|GELEN)$/' : '/^\d{1,10}$/';
    $anahtarlar = [];
    foreach (array_slice((array)($g['anahtarlar'] ?? []), 0, 50) as $a) {
        $a = trim((string)$a);
        if (!preg_match($desen, $a)) {
            return ['hata' => 'Geçersiz detay anahtarı.'];
        }
        $anahtarlar[$a] = $a;
    }
    if (!$anahtarlar) {
        return ['hata' => 'Detay anahtarı eksik.'];
    }
    // Satır firması: yoksa / negatifse koşul yok (raporun firma filtresi yine uygulanır)
    $sf = isset($g['satir_firma_id']) && $g['satir_firma_id'] !== '' ? (int)$g['satir_firma_id'] : -1;

    return ['tur' => $tur, 'anahtarlar' => array_values($anahtarlar), 'satirFirma' => $sf >= 0 ? $sf : null];
}

/**
 * Detay türünün sorgu parçaları:
 *   taban    FROM + WHERE (kaynak fonksiyonuyla aynı koşul + kırılım anahtarı + satır firması)
 *   params   tabandaki ? değerleri
 *   id       satır kimliği (CTE yalnız bunu döner)
 *   ozet     COUNT / SUM ifadeleri (kırılım kartındaki adet / tutar ile aynı tanım)
 *   ara      arama koşulu (her ? aynı LIKE değeriyle doldurulur)
 *   siralama DataTables kolon index → SQL ifadesi (whitelist; olmayan index → 0)
 *   dis      dış SELECT: CTE (sayfa x) satırlarına detay kolonları; takma adlar tabandakiyle aynı
 */
function detayTanim(array $d, array $f)
{
    $tur = $d['tur'];
    $p   = [$f['bas'], $f['bit']];

    // Seçili platform bu türün etiketi değilse rapordaki gibi hiç satır yok
    $platformSql = ($f['platform'] !== null && DETAY_TURLERI[$tur]['platform'] !== $f['platform']) ? ' AND 1 = 0 ' : '';

    switch ($tur) {
        case 'satis':
            return [
                'taban'  => pazaryeriKosulu($f['firmaIdler'])
                          . anahtarIdKosulu($d['anahtarlar'], 's.PazaryeriSiparisleri_Pazaryerleri_id')
                          . satirFirmaKosulu($d['satirFirma'], 'k.EntegrasyonKanallari_Firmalar_id') . $platformSql,
                'params' => $p,
                'id'     => 's.PazaryeriSiparisleri_id',
                'ozet'   => 'COUNT(*) AS adet, ISNULL(SUM(ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0)), 0) AS toplam',
                'ara'    => 's.PazaryeriSiparisleri_SiparisNo LIKE ? OR s.PazaryeriSiparisleri_MusteriAdi LIKE ?
                             OR EXISTS (SELECT 1 FROM dbo.PazaryeriSiparisKalemleri ca
                                        WHERE ca.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                                          AND ca.PazaryeriSiparisKalemleri_Durum = 1
                                          AND (ca.PazaryeriSiparisKalemleri_UrunAdi LIKE ? OR ca.PazaryeriSiparisKalemleri_Barkod LIKE ?))',
                'siralama' => [
                    0 => 's.PazaryeriSiparisleri_SiparisTarihi',
                    1 => 's.PazaryeriSiparisleri_SiparisNo',
                    2 => 'p.Pazaryerleri_Ad',
                    3 => 'k.EntegrasyonKanallari_Ad',
                    4 => FIRMA_AD_SQL,
                    5 => 's.PazaryeriSiparisleri_MusteriAdi',
                    7 => 's.PazaryeriSiparisleri_DurumKod',
                    9 => 's.PazaryeriSiparisleri_ToplamTutar',
                ],
                // Kalem sayısı ve ürün adları yalnız bu sayfanın satırlarına (ağır alt sorgu CTE'de yok)
                'dis' => "
                    SELECT s.PazaryeriSiparisleri_id                                  AS id,
                           CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS tarih,
                           s.PazaryeriSiparisleri_SiparisNo                           AS siparis_no,
                           p.Pazaryerleri_Ad                                          AS pazaryeri,
                           k.EntegrasyonKanallari_Ad                                  AS kanal,
                           " . FIRMA_AD_SQL . "                                       AS firma,
                           s.PazaryeriSiparisleri_MusteriAdi                          AS musteri,
                           s.PazaryeriSiparisleri_DurumKod                            AS durum,
                           kl.adet                                                    AS kalem,
                           ur.urunler                                                 AS urunler,
                           ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0)              AS tutar
                    FROM sayfa x
                    INNER JOIN dbo.PazaryeriSiparisleri s ON s.PazaryeriSiparisleri_id = x.id
                    INNER JOIN dbo.EntegrasyonKanallari k ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    INNER JOIN dbo.tanim_Pazaryerleri p   ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f              ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    OUTER APPLY (
                        SELECT COUNT(*) AS adet
                        FROM dbo.PazaryeriSiparisKalemleri c
                        WHERE c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                          AND c.PazaryeriSiparisKalemleri_Durum = 1
                    ) kl
                    -- Ürün adları (miktar ile): kalemler ' ¦ ' ile birleşir, arayüz satır satır gösterir
                    OUTER APPLY (
                        SELECT STUFF((
                            SELECT N' ¦ ' + CAST(c.PazaryeriSiparisKalemleri_Miktar AS NVARCHAR(10)) + N' x '
                                 + ISNULL(NULLIF(c.PazaryeriSiparisKalemleri_UrunAdi, N''), c.PazaryeriSiparisKalemleri_Barkod)
                            FROM dbo.PazaryeriSiparisKalemleri c
                            WHERE c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                              AND c.PazaryeriSiparisKalemleri_Durum = 1
                            ORDER BY c.PazaryeriSiparisKalemleri_id
                            FOR XML PATH(''), TYPE
                        ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS urunler
                    ) ur",
                'bicim' => fn($r) => [
                    'tarih'      => (string)$r['tarih'],
                    'siparis_no' => (string)($r['siparis_no'] ?? ''),
                    'pazaryeri'  => (string)($r['pazaryeri'] ?? ''),
                    'kanal'      => (string)($r['kanal'] ?? ''),
                    'firma'      => (string)($r['firma'] ?? ''),
                    'musteri'    => (string)($r['musteri'] ?? ''),
                    'durum'      => (string)($r['durum'] ?? ''),
                    'kalem'      => (int)$r['kalem'],
                    'urunler'    => (string)($r['urunler'] ?? ''),
                    'tutar'      => round((float)$r['tutar'], 2),
                ],
            ];

        // Kalem seviyesi: adet = sipariş sayısı (kırılımdaki COUNT DISTINCT ile aynı), kalem ayrıca verilir
        case 'komisyon_pazaryeri':
            return [
                'taban'  => pazaryeriKosulu($f['firmaIdler'], KOMISYON_KALEM_JOIN)
                          . anahtarIdKosulu($d['anahtarlar'], 's.PazaryeriSiparisleri_Pazaryerleri_id')
                          . satirFirmaKosulu($d['satirFirma'], 'k.EntegrasyonKanallari_Firmalar_id') . $platformSql,
                'params' => $p,
                'id'     => 'c.PazaryeriSiparisKalemleri_id',
                'ozet'   => 'COUNT(DISTINCT s.PazaryeriSiparisleri_id) AS adet, COUNT(*) AS kalem,
                             SUM(CASE WHEN c.PazaryeriSiparisKalemleri_KomisyonTutar IS NULL THEN 1 ELSE 0 END) AS bos,
                             ISNULL(SUM(ISNULL(c.PazaryeriSiparisKalemleri_KomisyonTutar, 0)), 0) AS toplam',
                'ara'    => 's.PazaryeriSiparisleri_SiparisNo LIKE ? OR c.PazaryeriSiparisKalemleri_UrunAdi LIKE ? OR c.PazaryeriSiparisKalemleri_Barkod LIKE ?',
                'siralama' => [
                    0 => 's.PazaryeriSiparisleri_SiparisTarihi',
                    1 => 's.PazaryeriSiparisleri_SiparisNo',
                    2 => 'p.Pazaryerleri_Ad',
                    3 => FIRMA_AD_SQL,
                    4 => 'c.PazaryeriSiparisKalemleri_UrunAdi',
                    5 => 'c.PazaryeriSiparisKalemleri_Barkod',
                    6 => 'c.PazaryeriSiparisKalemleri_Miktar',
                    7 => 'c.PazaryeriSiparisKalemleri_ToplamTutar',
                    8 => 'c.PazaryeriSiparisKalemleri_KomisyonOran',
                    9 => 'c.PazaryeriSiparisKalemleri_KomisyonTutar',
                ],
                'dis' => "
                    SELECT c.PazaryeriSiparisKalemleri_id                             AS id,
                           CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS tarih,
                           s.PazaryeriSiparisleri_SiparisNo                           AS siparis_no,
                           p.Pazaryerleri_Ad                                          AS pazaryeri,
                           " . FIRMA_AD_SQL . "                                       AS firma,
                           c.PazaryeriSiparisKalemleri_UrunAdi                        AS urun,
                           c.PazaryeriSiparisKalemleri_Barkod                         AS barkod,
                           c.PazaryeriSiparisKalemleri_Miktar                         AS miktar,
                           c.PazaryeriSiparisKalemleri_ToplamTutar                    AS kalem_tutar,
                           c.PazaryeriSiparisKalemleri_KomisyonOran                   AS komisyon_oran,
                           c.PazaryeriSiparisKalemleri_KomisyonTutar                  AS komisyon_tutar
                    FROM sayfa x
                    INNER JOIN dbo.PazaryeriSiparisKalemleri c ON c.PazaryeriSiparisKalemleri_id = x.id
                    INNER JOIN dbo.PazaryeriSiparisleri s      ON s.PazaryeriSiparisleri_id = c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id
                    INNER JOIN dbo.EntegrasyonKanallari k      ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    INNER JOIN dbo.tanim_Pazaryerleri p        ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f                   ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id",
                // Komisyonu henüz gelmemiş kalem (teslim sonrası dolar): NULL korunur, arayüzde "—"
                'bicim' => fn($r) => [
                    'tarih'          => (string)$r['tarih'],
                    'siparis_no'     => (string)($r['siparis_no'] ?? ''),
                    'pazaryeri'      => (string)($r['pazaryeri'] ?? ''),
                    'firma'          => (string)($r['firma'] ?? ''),
                    'urun'           => (string)($r['urun'] ?? ''),
                    'barkod'         => (string)($r['barkod'] ?? ''),
                    'miktar'         => (int)$r['miktar'],
                    'kalem_tutar'    => round((float)$r['kalem_tutar'], 2),
                    'komisyon_oran'  => $r['komisyon_oran'] !== null ? round((float)$r['komisyon_oran'], 2) : null,
                    'komisyon_tutar' => $r['komisyon_tutar'] !== null ? round((float)$r['komisyon_tutar'], 2) : null,
                ],
            ];

        // Banka masrafı: dış SELECT'te banka adı + eşleşen dahil kuralı (OUTER APPLY, yalnız bu sayfaya)
        case 'banka':
            return [
                'taban'  => "
                    FROM dbo.banka_HesapHareketleri h
                    INNER JOIN dbo.banka_Hesap a ON a.bankaHesap_id = h.banka_HesapId
                    WHERE " . bankaMasrafKosulu() . "
                      AND h.banka_HesapHareketleriDateTime >= CAST(? AS DATE)
                      AND h.banka_HesapHareketleriDateTime <  DATEADD(DAY, 1, CAST(? AS DATE))
                      " . anahtarIdKosulu($d['anahtarlar'], 'a.bankaHesap_banka_id') . "
                      " . firmaKosulu($f['firmaIdler'], 'a.bankaHesap_firma_id') . "
                      " . satirFirmaKosulu($d['satirFirma'], 'a.bankaHesap_firma_id') . $platformSql,
                'params' => $p,
                'id'     => 'h.banka_HesapHareketleriID',
                'ozet'   => 'COUNT(*) AS adet, ISNULL(SUM(' . BANKA_MASRAF_TUTAR . '), 0) AS toplam',
                'ara'    => 'h.banka_HesapHareketleriDescription LIKE ?',
                'siralama' => [
                    0 => 'h.banka_HesapHareketleriDateTime',
                    1 => 'a.bankaHesap_iban',
                    2 => 'h.banka_HesapHareketleriDescription',
                    3 => BANKA_MASRAF_TUTAR,
                ],
                'dis' => "
                    SELECT h.banka_HesapHareketleriID                                  AS id,
                           CONVERT(VARCHAR(16), h.banka_HesapHareketleriDateTime, 120) AS tarih,
                           a.bankaHesap_banka_id                                       AS banka_id,
                           ISNULL(b.banka_adi, N'Tanımsız')                            AS banka_adi,
                           a.bankaHesap_iban                                           AS iban,
                           h.banka_HesapHareketleriDescription                         AS aciklama,
                           " . BANKA_MASRAF_TUTAR . "                                  AS tutar,
                           CAST(h.banka_HesapHareketleriCost AS INT)                   AS cost,
                           kr.desen                                                    AS kural_desen
                    FROM sayfa x
                    INNER JOIN dbo.banka_HesapHareketleri h ON h.banka_HesapHareketleriID = x.id
                    INNER JOIN dbo.banka_Hesap a ON a.bankaHesap_id = h.banka_HesapId
                    LEFT JOIN dbo.bankalar b     ON b.banka_id = a.bankaHesap_banka_id
                    OUTER APPLY (
                        SELECT TOP 1 r.BankaMasrafKurallari_Desen AS desen
                        FROM dbo.tanim_BankaMasrafKurallari r
                        WHERE r.BankaMasrafKurallari_Durum = 1 AND r.BankaMasrafKurallari_Tip = 'dahil'
                          AND (r.BankaMasrafKurallari_Bankalar_id IS NULL OR r.BankaMasrafKurallari_Bankalar_id = a.bankaHesap_banka_id)
                          AND h.banka_HesapHareketleriDescription LIKE r.BankaMasrafKurallari_Desen
                        ORDER BY CASE WHEN r.BankaMasrafKurallari_Bankalar_id IS NULL THEN 1 ELSE 0 END, r.BankaMasrafKurallari_id
                    ) kr",
                'bicim' => fn($r) => [
                    'tarih'       => (string)$r['tarih'],
                    'banka_id'    => (int)$r['banka_id'],
                    'banka'       => (string)$r['banka_adi'],
                    'iban'        => (string)($r['iban'] ?? ''),
                    'aciklama'    => (string)($r['aciklama'] ?? ''),
                    'tutar'       => round((float)$r['tutar'], 2),
                    'cost'        => (int)$r['cost'],
                    'kural_desen' => $r['kural_desen'],
                ],
            ];

        // Kargo: anahtar 'KAYNAK|YON' çiftleri parametreyle (tabandaki tarih ?'lerinden sonra)
        case 'kargo':
            $parca = [];
            foreach ($d['anahtarlar'] as $a) {
                [$kaynak, $yon] = explode('|', $a, 2);
                $parca[] = '(g.kargo_gonderiler_Kaynak = ? AND g.kargo_gonderiler_Yon = ?)';
                $p[] = $kaynak;
                $p[] = $yon;
            }
            return [
                'taban'  => kargoKosulu($f['firmaIdler'])
                          . ' AND (' . implode(' OR ', $parca) . ') '
                          . satirFirmaKosulu($d['satirFirma'], 'k.EntegrasyonKanallari_Firmalar_id') . $platformSql,
                'params' => $p,
                'id'     => 'g.kargo_gonderiler_id',
                'ozet'   => 'COUNT(*) AS adet, ISNULL(SUM(ISNULL(g.kargo_gonderiler_Tutar, 0)), 0) AS toplam',
                'ara'    => 'g.kargo_gonderiler_TakipNo LIKE ? OR g.kargo_gonderiler_Gonderici LIKE ? OR g.kargo_gonderiler_Alici LIKE ?',
                'siralama' => [
                    0 => 'g.kargo_gonderiler_CikisTarihi',
                    1 => 'g.kargo_gonderiler_Yon',
                    2 => 'g.kargo_gonderiler_TakipNo',
                    3 => 'g.kargo_gonderiler_Gonderici',
                    4 => 'g.kargo_gonderiler_Alici',
                    5 => 'g.kargo_gonderiler_AliciIl',
                    6 => 'g.kargo_gonderiler_OdemeTipi',
                    7 => 'g.kargo_gonderiler_DurumMetni',
                    8 => 'g.kargo_gonderiler_Tutar',
                ],
                'dis' => "
                    SELECT g.kargo_gonderiler_id                                       AS id,
                           CONVERT(VARCHAR(16), g.kargo_gonderiler_CikisTarihi, 120)   AS tarih,
                           g.kargo_gonderiler_Yon                                      AS yon,
                           g.kargo_gonderiler_TakipNo                                  AS takip_no,
                           g.kargo_gonderiler_Gonderici                                AS gonderici,
                           g.kargo_gonderiler_Alici                                    AS alici,
                           g.kargo_gonderiler_AliciIl                                  AS alici_il,
                           g.kargo_gonderiler_OdemeTipi                                AS odeme_tipi,
                           g.kargo_gonderiler_DurumMetni                               AS durum,
                           ISNULL(g.kargo_gonderiler_Tutar, 0)                         AS tutar
                    FROM sayfa x
                    INNER JOIN dbo.kargo_gonderiler g ON g.kargo_gonderiler_id = x.id",
                'bicim' => fn($r) => [
                    'tarih'      => (string)$r['tarih'],
                    'yon'        => (string)($r['yon'] ?? ''),
                    'takip_no'   => (string)($r['takip_no'] ?? ''),
                    'gonderici'  => (string)($r['gonderici'] ?? ''),
                    'alici'      => (string)($r['alici'] ?? ''),
                    'alici_il'   => (string)($r['alici_il'] ?? ''),
                    'odeme_tipi' => (string)($r['odeme_tipi'] ?? ''),
                    'durum'      => (string)($r['durum'] ?? ''),
                    'tutar'      => round((float)$r['tutar'], 2),
                ],
            ];
    }
    return ['hata' => 'Geçersiz detay türü.'];
}

/** Özet satırı (COUNT / SUM) → sayısal dizi */
function detayOzet($r)
{
    $o = [
        'adet'   => (int)($r['adet'] ?? 0),
        'toplam' => round((float)($r['toplam'] ?? 0), 2),
    ];
    if (isset($r['kalem'])) {
        $o['kalem'] = (int)$r['kalem'];
        $o['bos']   = (int)($r['bos'] ?? 0);
    }
    return $o;
}

/**
 * Server-side detay (iki aşamalı):
 *  1) Ucuz CTE: filtre + sıralama + OFFSET/FETCH → yalnız ID
 *  2) Dış SELECT: o sayfanın satırlarına detay kolonları / ağır alt sorgular
 * Sıralama kolonu whitelist'ten, yön ASC/DESC normalize. Sayım tek taban sorgusundan.
 */
function detayCalistir(array $t, array $istek)
{
    $db = Database::getInstance();

    $ara    = mb_substr(trim((string)($istek['ara'] ?? '')), 0, 100, 'UTF-8');
    $araSql = '';
    $pAra   = $t['params'];
    if ($ara !== '') {
        $araSql = ' AND (' . $t['ara'] . ') ';
        $deger  = '%' . likeKacis($ara) . '%';
        for ($i = substr_count($t['ara'], '?'); $i > 0; $i--) {
            $pAra[] = $deger;
        }
    }

    $top  = $db->fetchOne("SELECT {$t['ozet']} {$t['taban']}", $t['params']);
    $filt = $ara !== '' ? $db->fetchOne("SELECT {$t['ozet']} {$t['taban']} $araSql", $pAra) : $top;

    $kolonNo = (int)($istek['order'][0]['column'] ?? 0);
    $kolon   = $t['siralama'][$kolonNo] ?? $t['siralama'][0];
    $yon     = strtolower((string)($istek['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $start   = max(0, (int)($istek['start'] ?? 0));
    $len     = (int)($istek['length'] ?? 25);
    if ($len < 1 || $len > 500) {
        $len = 25;
    }

    $satirlar = $db->fetchAll("
        WITH sayfa AS (
            SELECT {$t['id']} AS id
            {$t['taban']} $araSql
            ORDER BY $kolon $yon, {$t['id']} $yon
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
        )
        {$t['dis']}
        ORDER BY $kolon $yon, {$t['id']} $yon
    ", array_merge($pAra, [$start, $len]));

    $ozetTop  = detayOzet($top);
    $kayitTop = $ozetTop['kalem'] ?? $ozetTop['adet'];       // komisyonda satır = kalem
    $ozetFilt = detayOzet($filt);
    $kayitFilt = $ozetFilt['kalem'] ?? $ozetFilt['adet'];

    return [
        'recordsTotal'    => $kayitTop,
        'recordsFiltered' => $kayitFilt,
        'ozet'            => $ozetTop,
        'ozet_filtre'     => $ara !== '' ? $ozetFilt : null,
        'data'            => array_map(fn($r) => ['id' => (int)$r['id']] + ($t['bicim'])($r), $satirlar),
    ];
}

/** Kural formu doğrulaması. Hata varsa ['hata' => mesaj] döner. */
function kuralGirdisi(array $g)
{
    $desen   = trim((string)($g['desen'] ?? ''));
    $tip     = (string)($g['tip'] ?? '');
    $bankaId = (int)($g['banka_id'] ?? 0);

    if ($desen === '') {
        return ['hata' => 'Desen zorunludur.'];
    }
    if (mb_strlen($desen, 'UTF-8') > 200) {
        return ['hata' => 'Desen en fazla 200 karakter olabilir.'];
    }
    if (strpos($desen, '%') === false && mb_strlen($desen, 'UTF-8') < 3) {
        return ['hata' => 'Desen en az bir % içermeli veya en az 3 karakter olmalı.'];
    }
    if (trim($desen, "%_ \t") === '') {
        return ['hata' => 'Yalnız joker karakterden oluşan desen tüm hareketlere uyar; bir anahtar kelime yazın.'];
    }
    if (!in_array($tip, ['dahil', 'haric'], true)) {
        return ['hata' => 'Geçersiz kural tipi.'];
    }
    if ($bankaId > 0 && !Database::getInstance()->fetchOne("SELECT banka_id FROM dbo.bankalar WHERE banka_id = ?", [$bankaId])) {
        return ['hata' => 'Banka bulunamadı.'];
    }

    return [
        'id'       => max(0, (int)($g['id'] ?? 0)),
        'desen'    => $desen,
        'tip'      => $tip,
        'banka_id' => $bankaId > 0 ? $bankaId : null,
        'aciklama' => mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 300, 'UTF-8'),
        'durum'    => !empty($g['durum']) ? 1 : 0,
    ];
}

/**
 * Kural önizlemesi: kural (yeni ya da düzenlenen) etkin olsaydı rapordaki masraf satırları nasıl değişirdi?
 *  dahil → desene uyup şu an masraf sayılmayan ve hiçbir hariç kurala uymayan BORÇ satırları (eklenecekler)
 *  haric → şu an masraf sayılan, desene uyan ve bankaca işaretlenmemiş (Cost = 0) satırlar (çıkarılacaklar)
 * "Şu an" durumu düzenlenen kural yok sayılarak hesaplanır; böylece yalnız bu kuralın etkisi görünür.
 */
function kuralOnizle(array $k, $bas, $bit, $firmaIdler)
{
    $db     = Database::getInstance();
    $params = [$k['desen']];
    $bankaSql = '';
    if ($k['banka_id']) {
        $bankaSql = ' AND a.bankaHesap_banka_id = ? ';
        $params[] = $k['banka_id'];
    }
    $params[] = $bas;
    $params[] = $bit;

    $etki = $k['tip'] === 'dahil'
        ? " AND NOT " . bankaMasrafKosulu($k['id']) . " AND NOT " . bankaKuralVar('haric', $k['id'])
        : " AND h.banka_HesapHareketleriCost = 0 AND " . bankaMasrafKosulu($k['id']);

    $kaynak = "
        FROM dbo.banka_HesapHareketleri h
        INNER JOIN dbo.banka_Hesap a ON a.bankaHesap_id = h.banka_HesapId
        WHERE h.banka_HesapHareketleriBorcAlacak = 'B'
          AND h.banka_HesapHareketleriDescription LIKE ?
          $bankaSql
          $etki
          AND h.banka_HesapHareketleriDateTime >= CAST(? AS DATE)
          AND h.banka_HesapHareketleriDateTime <  DATEADD(DAY, 1, CAST(? AS DATE))
          " . firmaKosulu($firmaIdler, 'a.bankaHesap_firma_id');

    $ozet = $db->fetchOne("SELECT COUNT(*) AS adet, ISNULL(SUM(" . BANKA_MASRAF_TUTAR . "), 0) AS toplam $kaynak", $params);

    $satirlar = $db->fetchAll("
        SELECT TOP 20
            CONVERT(VARCHAR(16), h.banka_HesapHareketleriDateTime, 120) AS tarih,
            ISNULL(b.banka_adi, N'Tanımsız')                            AS banka_adi,
            a.bankaHesap_iban                                           AS iban,
            h.banka_HesapHareketleriDescription                         AS aciklama,
            " . BANKA_MASRAF_TUTAR . "                                  AS tutar
        FROM (SELECT h.banka_HesapHareketleriID AS id $kaynak) x
        INNER JOIN dbo.banka_HesapHareketleri h ON h.banka_HesapHareketleriID = x.id
        INNER JOIN dbo.banka_Hesap a ON a.bankaHesap_id = h.banka_HesapId
        LEFT JOIN dbo.bankalar b     ON b.banka_id = a.bankaHesap_banka_id
        ORDER BY " . BANKA_MASRAF_TUTAR . " DESC, h.banka_HesapHareketleriID DESC
    ", $params);

    return [
        'tip'      => $k['tip'],
        'adet'     => (int)($ozet['adet'] ?? 0),
        'toplam'   => round((float)($ozet['toplam'] ?? 0), 2),
        'satirlar' => array_map(fn($r) => [
            'tarih'    => (string)$r['tarih'],
            'banka'    => (string)$r['banka_adi'],
            'iban'     => (string)($r['iban'] ?? ''),
            'aciklama' => (string)($r['aciklama'] ?? ''),
            'tutar'    => round((float)$r['tutar'], 2),
        ], $satirlar),
    ];
}

/** Ayar modalı verisi: kurallar, aktif bankalar, tüm kaynak tanımları */
function ayarVerisi()
{
    $db = Database::getInstance();
    return [
        'kurallar' => $db->fetchAll("
            SELECT r.BankaMasrafKurallari_id AS id, r.BankaMasrafKurallari_Desen AS desen,
                   r.BankaMasrafKurallari_Tip AS tip, r.BankaMasrafKurallari_Bankalar_id AS banka_id,
                   b.banka_adi, r.BankaMasrafKurallari_Aciklama AS aciklama,
                   CAST(r.BankaMasrafKurallari_Durum AS INT) AS durum
            FROM dbo.tanim_BankaMasrafKurallari r
            LEFT JOIN dbo.bankalar b ON b.banka_id = r.BankaMasrafKurallari_Bankalar_id
            ORDER BY CASE r.BankaMasrafKurallari_Tip WHEN 'dahil' THEN 0 ELSE 1 END,
                     r.BankaMasrafKurallari_Desen
        "),
        'bankalar' => $db->fetchAll("
            SELECT banka_id AS id, banka_adi AS ad
            FROM dbo.bankalar
            WHERE banka_durum = 1
            ORDER BY banka_sira_no, banka_adi
        "),
        'kaynaklar' => $db->fetchAll("
            SELECT GelirGiderKaynaklari_id AS id, GelirGiderKaynaklari_Kod AS kod, GelirGiderKaynaklari_Ad AS ad,
                   GelirGiderKaynaklari_Tip AS tip, GelirGiderKaynaklari_Aciklama AS aciklama,
                   GelirGiderKaynaklari_Renk AS renk, GelirGiderKaynaklari_Ikon AS ikon,
                   GelirGiderKaynaklari_Sira AS sira, CAST(GelirGiderKaynaklari_Durum AS INT) AS durum
            FROM dbo.tanim_GelirGiderKaynaklari
            ORDER BY CASE GelirGiderKaynaklari_Tip WHEN 'gelir' THEN 0 ELSE 1 END,
                     GelirGiderKaynaklari_Sira, GelirGiderKaynaklari_id
        "),
    ];
}

// =====================================================================
// AJAX
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    // Ayar işlemleri: yalnız ayar yetkisi olan kullanıcı (buton gizli olsa da sunucuda da denetlenir)
    $ayarAksiyonlari = ['ayar_liste', 'kural_onizle', 'kural_kaydet', 'kural_durum', 'kural_sil', 'kaynak_kaydet', 'kaynak_durum'];
    if (in_array($action, $ayarAksiyonlari, true) && !$ayarYetki) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Ayarları değiştirme yetkiniz yok.'], $jsonBayrak);
        exit;
    }
    $simdi = date('Y-m-d H:i:s');

    try {
        switch ($action) {

            // ---------------------------------------------------------
            // Ayar modalı: kurallar + bankalar + kaynaklar
            case 'ayar_liste':
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'data' => ayarVerisi()], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Kural önizleme: raporun filtresi (tarih + firma) ile
            case 'kural_onizle':
                header('Content-Type: application/json; charset=utf-8');
                $f = filtreCoz($_POST, $kisitFirmaId);
                $k = kuralGirdisi($_POST);
                $hata = $f['hata'] ?? $k['hata'] ?? null;
                if ($hata) {
                    echo json_encode(['success' => false, 'message' => $hata], $jsonBayrak);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => kuralOnizle($k, $f['bas'], $f['bit'], $f['firmaIdler'])], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'kural_kaydet':
                header('Content-Type: application/json; charset=utf-8');
                $k = kuralGirdisi($_POST);
                if (isset($k['hata'])) {
                    echo json_encode(['success' => false, 'message' => $k['hata']], $jsonBayrak);
                    exit;
                }
                if ($k['id'] > 0 && !$db->fetchOne("SELECT BankaMasrafKurallari_id FROM dbo.tanim_BankaMasrafKurallari WHERE BankaMasrafKurallari_id = ?", [$k['id']])) {
                    echo json_encode(['success' => false, 'message' => 'Kural bulunamadı.'], $jsonBayrak);
                    exit;
                }
                $ayni = $db->fetchOne("
                    SELECT TOP 1 BankaMasrafKurallari_id FROM dbo.tanim_BankaMasrafKurallari
                    WHERE BankaMasrafKurallari_Desen = ? AND BankaMasrafKurallari_Tip = ?
                      AND ISNULL(BankaMasrafKurallari_Bankalar_id, 0) = ? AND BankaMasrafKurallari_id <> ?
                ", [$k['desen'], $k['tip'], (int)$k['banka_id'], $k['id']]);
                if ($ayni) {
                    echo json_encode(['success' => false, 'message' => 'Aynı desen, tip ve banka için kural zaten var.'], $jsonBayrak);
                    exit;
                }

                if ($k['id'] > 0) {
                    $ok = $db->execute("
                        UPDATE dbo.tanim_BankaMasrafKurallari
                        SET BankaMasrafKurallari_Desen = ?, BankaMasrafKurallari_Tip = ?, BankaMasrafKurallari_Bankalar_id = ?,
                            BankaMasrafKurallari_Aciklama = ?, BankaMasrafKurallari_Durum = ?,
                            BankaMasrafKurallari_GuncelleyenKullanici = ?, BankaMasrafKurallari_GuncellemeTarihi = ?
                        WHERE BankaMasrafKurallari_id = ?
                    ", [$k['desen'], $k['tip'], $k['banka_id'], $k['aciklama'] ?: null, $k['durum'],
                        (int)$user['kullanici_id'], $simdi, $k['id']]);
                    $mesaj = 'Kural güncellendi.';
                } else {
                    $ok = $db->execute("
                        INSERT INTO dbo.tanim_BankaMasrafKurallari
                            (BankaMasrafKurallari_Desen, BankaMasrafKurallari_Tip, BankaMasrafKurallari_Bankalar_id,
                             BankaMasrafKurallari_Aciklama, BankaMasrafKurallari_Durum,
                             BankaMasrafKurallari_OlusturanKullanici, BankaMasrafKurallari_OlusturmaTarihi)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ", [$k['desen'], $k['tip'], $k['banka_id'], $k['aciklama'] ?: null, $k['durum'],
                        (int)$user['kullanici_id'], $simdi]);
                    $mesaj = 'Kural eklendi.';
                }
                echo json_encode($ok ? ['success' => true, 'message' => $mesaj]
                                     : ['success' => false, 'message' => 'Kural kaydedilemedi.'], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'kural_durum':
                header('Content-Type: application/json; charset=utf-8');
                $ok = $db->execute("
                    UPDATE dbo.tanim_BankaMasrafKurallari
                    SET BankaMasrafKurallari_Durum = ?, BankaMasrafKurallari_GuncelleyenKullanici = ?, BankaMasrafKurallari_GuncellemeTarihi = ?
                    WHERE BankaMasrafKurallari_id = ?
                ", [!empty($_POST['durum']) ? 1 : 0, (int)$user['kullanici_id'], $simdi, (int)($_POST['id'] ?? 0)]);
                echo json_encode($ok ? ['success' => true, 'message' => 'Kural durumu güncellendi.']
                                     : ['success' => false, 'message' => 'Durum güncellenemedi.'], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Kalıcı silme (pasife almak için Durum anahtarı kullanılır)
            case 'kural_sil':
                header('Content-Type: application/json; charset=utf-8');
                $ok = $db->execute("DELETE FROM dbo.tanim_BankaMasrafKurallari WHERE BankaMasrafKurallari_id = ?", [(int)($_POST['id'] ?? 0)]);
                echo json_encode($ok ? ['success' => true, 'message' => 'Kural silindi.']
                                     : ['success' => false, 'message' => 'Kural silinemedi.'], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Kaynak tanımı: Kod ve Tip değişmez (sorgu fonksiyonuna bağlı), yeni kaynak eklenmez
            case 'kaynak_kaydet':
                header('Content-Type: application/json; charset=utf-8');
                $id       = (int)($_POST['id'] ?? 0);
                $ad       = trim((string)($_POST['ad'] ?? ''));
                $renk     = trim((string)($_POST['renk'] ?? ''));
                $ikon     = trim((string)($_POST['ikon'] ?? ''));
                $sira     = (int)($_POST['sira'] ?? 0);
                $aciklama = mb_substr(trim((string)($_POST['aciklama'] ?? '')), 0, 300, 'UTF-8');

                $hata = null;
                if ($ad === '' || mb_strlen($ad, 'UTF-8') > 100) {
                    $hata = 'Ad zorunludur (en fazla 100 karakter).';
                } elseif (strlen($renk) > 20 || !preg_match(RENK_DESENI, $renk)) {
                    $hata = 'Renk #hex veya renk adı olmalı.';
                } elseif (!preg_match(IKON_DESENI, $ikon)) {
                    $hata = 'İkon yalnız harf, rakam, boşluk ve tire içerebilir (ör. bi bi-truck).';
                } elseif ($sira < 0 || $sira > 9999) {
                    $hata = 'Sıra 0 ile 9999 arasında olmalı.';
                } elseif (!$db->fetchOne("SELECT GelirGiderKaynaklari_id FROM dbo.tanim_GelirGiderKaynaklari WHERE GelirGiderKaynaklari_id = ?", [$id])) {
                    $hata = 'Kaynak bulunamadı.';
                }
                if ($hata) {
                    echo json_encode(['success' => false, 'message' => $hata], $jsonBayrak);
                    exit;
                }
                $ok = $db->execute("
                    UPDATE dbo.tanim_GelirGiderKaynaklari
                    SET GelirGiderKaynaklari_Ad = ?, GelirGiderKaynaklari_Renk = ?, GelirGiderKaynaklari_Ikon = ?,
                        GelirGiderKaynaklari_Sira = ?, GelirGiderKaynaklari_Aciklama = ?,
                        GelirGiderKaynaklari_GuncelleyenKullanici = ?, GelirGiderKaynaklari_GuncellemeTarihi = ?
                    WHERE GelirGiderKaynaklari_id = ?
                ", [$ad, $renk, $ikon, $sira, $aciklama !== '' ? $aciklama : null, (int)$user['kullanici_id'], $simdi, $id]);
                echo json_encode($ok ? ['success' => true, 'message' => 'Kaynak güncellendi.']
                                     : ['success' => false, 'message' => 'Kaynak kaydedilemedi.'], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'kaynak_durum':
                header('Content-Type: application/json; charset=utf-8');
                $ok = $db->execute("
                    UPDATE dbo.tanim_GelirGiderKaynaklari
                    SET GelirGiderKaynaklari_Durum = ?, GelirGiderKaynaklari_GuncelleyenKullanici = ?, GelirGiderKaynaklari_GuncellemeTarihi = ?
                    WHERE GelirGiderKaynaklari_id = ?
                ", [!empty($_POST['durum']) ? 1 : 0, (int)$user['kullanici_id'], $simdi, (int)($_POST['id'] ?? 0)]);
                echo json_encode($ok ? ['success' => true, 'message' => 'Kaynak durumu güncellendi.']
                                     : ['success' => false, 'message' => 'Durum güncellenemedi.'], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Kırılım detayı (server-side DataTables). Sayfanın görüntüleme yetkisi yeterli;
            // firma kısıtı filtreCoz ile uygulanır. tur: satis | komisyon_pazaryeri | banka | kargo
            // (eski 'masraf_detay' = tur 'banka'). Sorgu: detayCalistir (iki aşamalı).
            case 'detay':
                header('Content-Type: application/json; charset=utf-8');
                $draw = (int)($_POST['draw'] ?? 0);
                $f = filtreCoz($_POST, $kisitFirmaId);
                $d = isset($f['hata']) ? $f : detayGirdisi($_POST);
                $t = isset($d['hata']) ? $d : detayTanim($d, $f);
                if (isset($t['hata'])) {
                    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $t['hata']], $jsonBayrak);
                    exit;
                }
                echo json_encode(['draw' => $draw] + detayCalistir($t, $_POST), $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // InfoBox + grafik + aylık tablo + kırılımlar tek çağrıda
            case 'rapor':
                header('Content-Type: application/json; charset=utf-8');
                $f = filtreCoz($_POST, $kisitFirmaId);
                if (isset($f['hata'])) {
                    echo json_encode(['success' => false, 'message' => $f['hata']], $jsonBayrak);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => raporHesapla($f['bas'], $f['bit'], $f['firmaIdler'], $f['platform'], $f['kirilim'])], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Excel: aylık tablo + altında kaynak kırılımları (tek sayfa)
            case 'excel':
                $f = filtreCoz($_POST, $kisitFirmaId);
                if (isset($f['hata'])) {
                    http_response_code(400);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $f['hata'];
                    exit;
                }

                require_once __DIR__ . '/../includes/XlsxYazici.php';
                $r = raporHesapla($f['bas'], $f['bit'], $f['firmaIdler'], $f['platform'], $f['kirilim']);
                $destekli = array_values(array_filter($r['kaynaklar'], fn($k) => $k['destekli']));

                // Kırılım seçiliyse yalnız seçili kırılımı olan kaynak kolonları (sayfadaki görünümle aynı)
                $secili = $r['kirilimSecili'];
                if ($secili) {
                    $seciliKod = array_flip(array_map(fn($s) => explode('|', $s, 2)[0], $secili));
                    $destekli  = array_values(array_filter($destekli, fn($k) => isset($seciliKod[$k['kod']])));
                    $kirilimAd = [];
                    foreach ($r['kirilimlar'] as $s) {
                        $kirilimAd[$s['kaynak_kod'] . '|' . $s['alt_kirilim']] = $s['kaynak_ad'] . ': ' . $s['alt_kirilim'];
                    }
                    $kirilimMetin = count($secili) > 5
                        ? count($secili) . ' kırılım'
                        : implode(', ', array_map(fn($s) => $kirilimAd[$s] ?? $s, $secili));
                }

                // basliklar() satırı en üste alır; not ve filtre bilgisi en üstte kalsın diye başlık satırı satir() ile yazılır
                $xlsx = new XlsxYazici('Gelir ve Gider');
                $xlsx->satir([RAPOR_NOTU]);
                $xlsx->satir(['Dönem: ' . $f['bas'] . ' – ' . $f['bit'] . ' · Platform: ' . $f['platformAd']
                    . ($f['platform'] !== null ? ' (platformsuz kalemler – banka masrafları, kargo – dahil değil)' : '')
                    . ' · Kırılım: ' . ($secili ? $kirilimMetin : 'Tümü')]);
                $xlsx->satir(array_map(fn($b) => ['deger' => $b, 'tur' => 'baslik'], array_merge(
                    ['Ay'],
                    array_map(fn($k) => $k['ad'] . ' (' . ($k['tip'] === 'gelir' ? 'Gelir' : 'Gider') . ')', $destekli),
                    ['Toplam Gelir', 'Toplam Gider', 'Net']
                )));

                $kaynakTop = (array)$r['kaynakTop'];
                foreach ($r['aylar'] as $a) {
                    $kaynakTutar = (array)$a['kaynaklar'];
                    $xlsx->satir(array_merge(
                        [$a['ay']],
                        array_map(fn($k) => ['deger' => $kaynakTutar[$k['kod']] ?? 0, 'tur' => 'para'], $destekli),
                        [
                            ['deger' => $a['gelir'], 'tur' => 'para'],
                            ['deger' => $a['gider'], 'tur' => 'para'],
                            ['deger' => $a['net'],   'tur' => 'para'],
                        ]
                    ));
                }
                $xlsx->satir(array_merge(
                    [['deger' => 'GENEL TOPLAM', 'tur' => 'baslik']],
                    array_map(fn($k) => ['deger' => $kaynakTop[$k['kod']]['tutar'] ?? 0, 'tur' => 'para'], $destekli),
                    [
                        ['deger' => $r['ozet']['gelir'], 'tur' => 'para'],
                        ['deger' => $r['ozet']['gider'], 'tur' => 'para'],
                        ['deger' => $r['ozet']['net'],   'tur' => 'para'],
                    ]
                ));

                // Kırılımlar
                $kirilim = (array)$r['kirilim'];
                $xlsx->satir(['']);
                $xlsx->satir([
                    ['deger' => 'Kaynak', 'tur' => 'baslik'],
                    ['deger' => 'Tip', 'tur' => 'baslik'],
                    ['deger' => 'Alt Kırılım', 'tur' => 'baslik'],
                    ['deger' => 'Firma', 'tur' => 'baslik'],
                    ['deger' => 'Adet', 'tur' => 'baslik'],
                    ['deger' => 'Tutar', 'tur' => 'baslik'],
                ]);
                foreach ($destekli as $k) {
                    foreach ($kirilim[$k['kod']] ?? [] as $s) {
                        $xlsx->satir([
                            $k['ad'],
                            $k['tip'] === 'gelir' ? 'Gelir' : 'Gider',
                            $s['alt_kirilim'],
                            $s['firma_adi'],
                            ['deger' => $s['adet'], 'tur' => 'sayi'],
                            ['deger' => round($s['tutar'], 2), 'tur' => 'para'],
                        ]);
                    }
                }

                $xlsx->indir('gelir-gider-' . $f['bas'] . '_' . $f['bit'] . '.xlsx');
                exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Geçersiz istek.'], $jsonBayrak);
        exit;

    } catch (Throwable $e) {
        error_log('gelir-gider-raporu.php: ' . $e->getMessage());
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.'], $jsonBayrak);
        exit;
    }
}

$firmalar    = firmaListesi($kisitFirmaId);
$platformlar = platformListesi();
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .filtre-basligi { cursor: pointer; }
        .tutar { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .grafik-kutu { position: relative; height: 340px; }
        #tblAylik th, #tblAylik td { white-space: nowrap; vertical-align: middle; }
        #tblAylik thead th.kaynak-baslik { color: #fff; }
        #tblAylik tfoot td { font-weight: 700; border-top: 2px solid #adb5bd; }
        .kirilim-tablo td, .kirilim-tablo th { font-size: .85rem; }
        .kaynak-ikon { width: 2rem; height: 2rem; border-radius: .5rem; display: inline-flex;
                       align-items: center; justify-content: center; color: #fff; }
        .kirilim-tablo tbody tr.kirilim-satir { cursor: pointer; }
        .kirilim-tablo tbody tr.kirilim-satir td:first-child span { border-bottom: 1px dashed currentColor; }
        #tblKurallar td, #tblKaynaklar td { vertical-align: middle; }
        #tblKaynaklar input[type=color] { width: 3rem; padding: .15rem; }
        #tblDetay td { vertical-align: middle; }
        #tblDetay td.aciklama-hucre { white-space: normal; min-width: 260px; }
        /* Komisyonu henüz gelmemiş kalem (teslim sonrası dolar) */
        #tblDetay tr.satir-soluk td { opacity: .5; }
        /* Kural modalı ayar / detay modalının üstünde açılır */
        #kuralModal { z-index: 1065; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="d-flex align-items-center gap-2">
                                <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                                <?php if ($ayarYetki): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAyarlar" title="Banka masraf kuralları ve kaynak tanımları">
                                    <i class="bi bi-gear"></i> Ayarlar
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($pageDescription): ?>
                            <small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small>
                            <?php endif; ?>
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

                    <!-- InfoBox -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-arrow-down-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Gelir</span>
                                    <span class="info-box-number tutar" id="ibGelir">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-arrow-up-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Gider</span>
                                    <span class="info-box-number tutar" id="ibGider">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-wallet2"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Net (Gelir − Gider)</span>
                                    <span class="info-box-number tutar" id="ibNet">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-percent"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kâr Marjı</span>
                                    <span class="info-box-number" id="ibMarj">-</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mt-n2 mb-3"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars(RAPOR_NOTU) ?></p>

                    <!-- Filtre -->
                    <div class="card card-secondary card-outline mb-3">
                        <div class="card-header filtre-basligi" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                            <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                        </div>
                        <div class="collapse show" id="filtrePanel">
                            <div class="card-body">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Firma</label>
                                        <select class="form-select form-select-sm" id="fFirma">
                                            <?php if ($kisitFirmaId === null): ?>
                                            <option value="">Tüm firmalar</option>
                                            <?php endif; ?>
                                            <?php foreach ($firmalar as $f): ?>
                                            <option value="<?= (int)$f['firma_id'] ?>"><?= htmlspecialchars($f['firma_adi'] ?: $f['firma_unvan']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1" title="Platform seçiliyken platformsuz kalemler (banka masrafları, kargo) rapora girmez">Platform</label>
                                        <select class="form-select form-select-sm" id="fPlatform">
                                            <option value="">Tümü</option>
                                            <?php foreach ($platformlar as $pKod => $pAd): ?>
                                            <option value="<?= htmlspecialchars($pKod) ?>"><?= htmlspecialchars($pAd) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1" title="Seçenekler tarih, firma ve platform filtresine göre rapordan gelir; seçiliyse yalnız seçilen kırılımlar toplanır">Kırılım</label>
                                        <select class="form-select form-select-sm" id="fKirilim" multiple></select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Başlangıç</label>
                                        <input type="date" class="form-control form-control-sm" id="fBas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Bitiş</label>
                                        <input type="date" class="form-control form-control-sm" id="fBit">
                                    </div>
                                    <div class="col-12 d-flex gap-2 justify-content-end">
                                        <button class="btn btn-sm btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                        <button class="btn btn-sm btn-outline-success" id="btnExcel"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                                    </div>
                                </div>
                                <div class="mt-2" id="atlananKutu"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Grafik -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-bar-chart-line"></i> Aylık Gelir / Gider</h3>
                        </div>
                        <div class="card-body">
                            <div class="grafik-kutu"><canvas id="grafikAylik"></canvas></div>
                        </div>
                    </div>

                    <!-- Aylık tablo -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> Aylık Döküm</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle w-100 mb-0" id="tblAylik">
                                    <thead></thead>
                                    <tbody><tr><td class="text-muted">Yükleniyor...</td></tr></tbody>
                                    <tfoot></tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Kırılım kartları -->
                    <div class="row" id="kirilimKartlari"></div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Kırılım detayı: kolonlar detay türüne göre JS'te kurulur (DETAY_KOLONLARI) -->
    <div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-list-ul me-1" id="detayIkon"></i> <span id="detayBaslik"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="detayAra" id="detayAraEtiket">Ara</label>
                            <input type="text" class="form-control form-control-sm" id="detayAra" maxlength="100">
                        </div>
                        <div class="col-md-7 text-md-end small text-muted" id="detayKapsam"></div>
                    </div>
                    <table class="table table-sm table-striped table-hover w-100" id="tblDetay"></table>
                </div>
                <div class="modal-footer justify-content-start small" id="detayOzet">-</div>
            </div>
        </div>
    </div>

    <?php if ($ayarYetki): ?>
    <!-- Ayarlar -->
    <div class="modal fade" id="ayarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-gear me-1"></i> Gelir / Gider Ayarları</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sekmeKurallar" type="button" role="tab">
                                <i class="bi bi-bank me-1"></i> Banka Masraf Kuralları
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sekmeKaynaklar" type="button" role="tab">
                                <i class="bi bi-diagram-3 me-1"></i> Gelir / Gider Kaynakları
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="sekmeKurallar" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
                                <small class="text-muted">
                                    Banka BORÇ hareketi masraf sayılır: bankaca işaretliyse veya bir <b>dahil</b> desenine uyup hiçbir <b>hariç</b> desenine uymuyorsa.
                                    Desen SQL LIKE biçimindedir (<code>%</code> herhangi metin, <code>_</code> tek karakter).
                                </small>
                                <button type="button" class="btn btn-sm btn-primary" id="btnYeniKural"><i class="bi bi-plus-lg"></i> Yeni Kural</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0" id="tblKurallar">
                                    <thead>
                                        <tr>
                                            <th>Desen</th>
                                            <th>Tip</th>
                                            <th>Banka</th>
                                            <th>Açıklama</th>
                                            <th class="text-center">Durum</th>
                                            <th class="text-end">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody><tr><td colspan="6" class="text-muted">Yükleniyor...</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="sekmeKaynaklar" role="tabpanel">
                            <div class="alert alert-info py-2 small mb-2">
                                <i class="bi bi-info-circle me-1"></i> Kod ve tip sayfadaki sorgu fonksiyonuna bağlıdır, değiştirilemez.
                                Yeni kaynak türü geliştirme gerektirir.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0" id="tblKaynaklar">
                                    <thead>
                                        <tr>
                                            <th>Kod</th>
                                            <th>Tip</th>
                                            <th>Ad</th>
                                            <th>Renk</th>
                                            <th>İkon</th>
                                            <th style="width:90px">Sıra</th>
                                            <th>Açıklama</th>
                                            <th class="text-center">Durum</th>
                                            <th class="text-end">İşlem</th>
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
    </div>

    <!-- Kural ekle / düzenle (ayar veya detay modalının üstünde açılır) -->
    <div class="modal fade" id="kuralModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kuralBaslik">Kural</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="kId" value="0">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="kDesen">Desen <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="kDesen" maxlength="200" placeholder="%KOMİSYON%">
                            <div class="form-text">En az bir <code>%</code> veya en az 3 karakter. Büyük/küçük harf duyarsız; I ve İ farklıdır.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="kTip">Tip</label>
                            <select class="form-select form-select-sm" id="kTip">
                                <option value="dahil">Dahil (masraf say)</option>
                                <option value="haric">Hariç (masraf sayma)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="kBanka">Banka</label>
                            <select class="form-select form-select-sm" id="kBanka"></select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label small mb-1" for="kAciklama">Açıklama</label>
                            <input type="text" class="form-control form-control-sm" id="kAciklama" maxlength="300">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input" type="checkbox" role="switch" id="kDurum" name="kDurum" value="1" checked>
                                <label class="form-check-label" for="kDurum">Aktif</label>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-info" id="btnOnizle"><i class="bi bi-eye"></i> Önizle</button>
                        <small class="text-muted" id="onizleKapsam"></small>
                    </div>
                    <div id="onizleSonuc"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="button" class="btn btn-sm btn-primary" id="btnKuralKaydet"><i class="bi bi-check-lg"></i> Kaydet</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {

        const kacis = (s) => $('<div>').text(s ?? '').html();
        const paraBicim = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const para = (t) => paraBicim.format(Number(t) || 0);
        const ayAdi = (ay) => {
            const [y, m] = ay.split('-').map(Number);
            return new Date(y, m - 1, 1).toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' });
        };
        const yerelTarih = (d) => {
            const p = (n) => String(n).padStart(2, '0');
            return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
        };

        // custom.js tüm .form-select kutularına "Seçiniz..." yer tutuculu Select2 kurar; kendi etiketiyle yeniden kurulur
        [['#fFirma', 'Tüm firmalar'], ['#fPlatform', 'Tümü']].forEach(([sec, etiket]) => {
            const $s = $(sec);
            if ($s.hasClass('select2-hidden-accessible')) { $s.select2('destroy'); }
            initSelect2(sec, { placeholder: etiket, minimumResultsForSearch: 0 });
        });

        // Kırılım: çoklu seçim; seçenekler her rapor yanıtında yenilenir (kirilimSecenekCiz)
        if ($('#fKirilim').hasClass('select2-hidden-accessible')) { $('#fKirilim').select2('destroy'); }
        initSelect2('#fKirilim', { placeholder: 'Tüm kırılımlar', closeOnSelect: false, maximumSelectionLength: 50,
                                   language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...',
                                               maximumSelected: () => 'En fazla 50 kırılım seçilebilir' } });

        /**
         * Seçenekler: kaynak → optgroup, değer 'kaynak_kod|alt_kirilim'.
         * Seçim sunucunun uyguladığı seçime eşitlenir (sonuçta olmayanlar düşer, rapor zaten onunla hesaplandı).
         * 'change.select2' yalnız görünümü günceller; raporYukle tetiklenmez.
         */
        function kirilimSecenekCiz(liste, secili) {
            const $s = $('#fKirilim').empty();
            let $grup = null, sonKod = null;
            (liste || []).forEach(o => {
                if (o.kaynak_kod !== sonKod) {
                    $grup = $('<optgroup>', { label: o.kaynak_ad }).appendTo($s);
                    sonKod = o.kaynak_kod;
                }
                $('<option>', { value: o.kaynak_kod + '|' + o.alt_kirilim, text: o.alt_kirilim }).appendTo($grup);
            });
            $s.val(secili || []).trigger('change.select2');
        }

        /** Kırılım seçiliyse yalnız seçili kırılımı olan kaynaklar (aylık tablo kolonları, kartlar) */
        function gorunenKaynaklar(d) {
            const kodlar = new Set((d.kirilimSecili || []).map(s => s.split('|')[0]));
            return d.kaynaklar.filter(k => k.destekli && (!kodlar.size || kodlar.has(k.kod)));
        }

        function varsayilanTarihler() {
            const bugun = new Date();
            $('#fBas').val(yerelTarih(bugun));
            $('#fBit').val(yerelTarih(bugun));
        }

        function filtreVerisi() {
            return {
                firma_id:  $('#fFirma').val() || '',
                platform:  $('#fPlatform').val() || '',
                kirilim:   $('#fKirilim').val() || [],
                bas_tarih: $('#fBas').val(),
                bit_tarih: $('#fBit').val()
            };
        }

        function filtreGecerli(f) {
            if (!f.bas_tarih || !f.bit_tarih) { showToast('Tarih aralığını seçin', 'warning'); return false; }
            if (f.bas_tarih > f.bit_tarih) { showToast('Başlangıç tarihi bitişten sonra olamaz', 'warning'); return false; }
            return true;
        }

        // ---------------------------------------------------------------
        let grafik = null;

        function grafikCiz(aylar) {
            const etiketler = aylar.map(a => ayAdi(a.ay));
            const veri = {
                labels: etiketler,
                datasets: [
                    { type: 'bar',  label: 'Gelir', data: aylar.map(a => a.gelir), backgroundColor: 'rgba(25,135,84,.75)', borderColor: '#198754', borderWidth: 1, order: 2 },
                    { type: 'bar',  label: 'Gider', data: aylar.map(a => a.gider), backgroundColor: 'rgba(220,53,69,.75)', borderColor: '#dc3545', borderWidth: 1, order: 2 },
                    { type: 'line', label: 'Net',   data: aylar.map(a => a.net),   borderColor: '#0d6efd', backgroundColor: '#0d6efd', tension: .3, pointRadius: 4, order: 1 }
                ]
            };
            if (grafik) {
                grafik.data = veri;
                grafik.update();
                return;
            }
            grafik = new Chart(document.getElementById('grafikAylik'), {
                data: veri,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + para(c.parsed.y) } },
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { ticks: { callback: (v) => Number(v).toLocaleString('tr-TR') + ' ₺' } }
                    }
                }
            });
        }

        // ---------------------------------------------------------------
        function tabloCiz(d) {
            const kaynaklar = gorunenKaynaklar(d);
            const top = d.kaynakTop || {};

            let bas = '<tr><th>Ay</th>';
            kaynaklar.forEach(k => {
                bas += '<th class="text-end kaynak-baslik" style="background:' + kacis(k.renk) + '" title="' + kacis(k.aciklama) + '">'
                     + '<i class="' + kacis(k.ikon) + ' me-1"></i>' + kacis(k.ad)
                     + '<br><small class="fw-normal opacity-75">' + (k.tip === 'gelir' ? 'Gelir' : 'Gider') + '</small></th>';
            });
            bas += '<th class="text-end">Toplam Gelir</th><th class="text-end">Toplam Gider</th><th class="text-end">Net</th></tr>';
            $('#tblAylik thead').html(bas);

            const netHucre = (n) => '<td class="text-end tutar fw-semibold ' + (n < 0 ? 'text-danger' : 'text-success') + '">' + para(n) + '</td>';

            if (!d.aylar.length) {
                $('#tblAylik tbody').html('<tr><td colspan="' + (kaynaklar.length + 4) + '" class="text-center text-muted">Kayıt yok</td></tr>');
                $('#tblAylik tfoot').empty();
                return;
            }

            $('#tblAylik tbody').html(d.aylar.map(a => {
                let s = '<tr><td>' + kacis(ayAdi(a.ay)) + '</td>';
                kaynaklar.forEach(k => { s += '<td class="text-end tutar">' + para((a.kaynaklar || {})[k.kod] || 0) + '</td>'; });
                s += '<td class="text-end tutar">' + para(a.gelir) + '</td>'
                   + '<td class="text-end tutar">' + para(a.gider) + '</td>'
                   + netHucre(a.net) + '</tr>';
                return s;
            }).join(''));

            let alt = '<tr><td>Genel Toplam</td>';
            kaynaklar.forEach(k => { alt += '<td class="text-end tutar">' + para((top[k.kod] || {}).tutar || 0) + '</td>'; });
            alt += '<td class="text-end tutar">' + para(d.ozet.gelir) + '</td>'
                 + '<td class="text-end tutar">' + para(d.ozet.gider) + '</td>'
                 + netHucre(d.ozet.net) + '</tr>';
            $('#tblAylik tfoot').html(alt);
        }

        // ---------------------------------------------------------------
        // Kırılım satırları → detay modalı (index ile bu listeden okunur; filtre rapor anındaki haliyle saklanır)
        let detayKayitlari = [];

        function kirilimSatir(k, s, filtre) {
            const hucreler = '<td><span>' + kacis(s.alt_kirilim) + '</span></td><td>' + kacis(s.firma_adi) + '</td>'
                + '<td class="text-end">' + Number(s.adet).toLocaleString('tr-TR') + '</td>'
                + '<td class="text-end tutar">' + para(s.tutar) + '</td>';
            if (!DETAY_KOLONLARI[s.detay_tur] || !Array.isArray(s.anahtarlar) || !s.anahtarlar.length) {
                return '<tr>' + hucreler + '</tr>';
            }
            detayKayitlari.push({ tur: s.detay_tur, anahtarlar: s.anahtarlar, satir_firma_id: Number(s.firma_id) || 0,
                                  kaynak: k.ad, alt: s.alt_kirilim, firma: s.firma_adi, adet: s.adet, tutar: s.tutar, filtre: filtre });
            return '<tr class="kirilim-satir" data-idx="' + (detayKayitlari.length - 1) + '" title="Detayı göster">' + hucreler + '</tr>';
        }

        function kirilimCiz(d, filtre) {
            const kaynaklar = gorunenKaynaklar(d);
            const top = d.kaynakTop || {};
            detayKayitlari = [];

            $('#kirilimKartlari').html(kaynaklar.map(k => {
                const satirlar = (d.kirilim || {})[k.kod] || [];
                const t = top[k.kod] || { tutar: 0, adet: 0 };
                const govde = satirlar.length
                    ? satirlar.map(s => kirilimSatir(k, s, filtre)).join('')
                    : '<tr><td colspan="4" class="text-center text-muted">Kayıt yok</td></tr>';

                return '<div class="col-12 col-xl-6 mb-3"><div class="card h-100" style="border-top:3px solid ' + kacis(k.renk) + '">'
                    + '<div class="card-header d-flex align-items-center gap-2">'
                    + '<span class="kaynak-ikon" style="background:' + kacis(k.renk) + '"><i class="' + kacis(k.ikon) + '"></i></span>'
                    + '<div class="flex-grow-1"><div class="fw-semibold">' + kacis(k.ad) + '</div>'
                    + '<small class="text-muted">' + kacis(k.aciklama) + '</small></div>'
                    + '<span class="badge ' + (k.tip === 'gelir' ? 'text-bg-success' : 'text-bg-danger') + '">' + (k.tip === 'gelir' ? 'Gelir' : 'Gider') + '</span>'
                    + '</div><div class="card-body p-0"><div class="table-responsive">'
                    + '<table class="table table-sm table-striped mb-0 kirilim-tablo"><thead><tr>'
                    + '<th>Kırılım</th><th>Firma</th><th class="text-end">Adet</th><th class="text-end">Tutar</th></tr></thead>'
                    + '<tbody>' + govde + '</tbody>'
                    + '<tfoot><tr class="fw-bold"><td colspan="2">Toplam</td><td class="text-end">' + Number(t.adet).toLocaleString('tr-TR') + '</td>'
                    + '<td class="text-end tutar">' + para(t.tutar) + '</td></tr></tfoot>'
                    + '</table></div></div></div></div>';
            }).join(''));
        }

        // ---------------------------------------------------------------
        function ozetCiz(o) {
            $('#ibGelir').text(para(o.gelir));
            $('#ibGider').text(para(o.gider));
            $('#ibNet').text(para(o.net)).toggleClass('text-danger', o.net < 0);
            $('#ibMarj').text(o.marj === null ? '—' : '%' + Number(o.marj).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
                        .toggleClass('text-danger', o.marj !== null && o.marj < 0);
        }

        function atlananCiz(liste) {
            $('#atlananKutu').html((liste || []).map(a =>
                '<span class="badge text-bg-warning me-1" title="Bu kaynak kodu için sayfada sorgu tanımlı değil; rapora dahil edilmedi">'
                + '<i class="bi bi-exclamation-triangle me-1"></i>' + kacis(a.ad) + ' (' + kacis(a.kod) + ') atlandı</span>').join(''));
        }

        let raporIstekNo = 0;   // art arda değişimde yalnız son isteğin yanıtı çizilir

        function raporYukle() {
            const f = filtreVerisi();
            if (!filtreGecerli(f)) return;

            const no = ++raporIstekNo;
            $('#btnFiltrele').prop('disabled', true);
            $.post('', Object.assign({ action: 'rapor' }, f), function (res) {
                if (no !== raporIstekNo) return;
                if (!res.success) { showToast(res.message || 'Rapor alınamadı', 'error'); return; }
                const d = res.data;
                kirilimSecenekCiz(d.kirilimlar, d.kirilimSecili);
                f.kirilim = d.kirilimSecili || [];
                ozetCiz(d.ozet);
                atlananCiz(d.atlanan);
                grafikCiz(d.aylar);
                tabloCiz(d);
                kirilimCiz(d, Object.assign({}, f, {
                    firma_ad:    $('#fFirma option:selected').text() || 'Tüm firmalar',
                    platform_ad: $('#fPlatform option:selected').text() || 'Tümü'
                }));
            }, 'json')
            .fail(() => showToast('Sunucu hatası', 'error'))
            .always(() => { if (no === raporIstekNo) $('#btnFiltrele').prop('disabled', false); });
        }

        // ---------------------------------------------------------------
        $('#btnFiltrele').on('click', raporYukle);
        $('#fFirma, #fPlatform').on('change', raporYukle);

        // Çoklu seçimde her tıklama change üretir: kısa bekleme ile tek istek
        let kirilimZaman = null;
        $('#fKirilim').on('change', function () {
            clearTimeout(kirilimZaman);
            kirilimZaman = setTimeout(raporYukle, 500);
        });

        $('#btnTemizle').on('click', function () {
            const $ilk = $('#fFirma option:first');
            clearTimeout(kirilimZaman);
            $('#fFirma').val($ilk.val()).trigger('change.select2');
            $('#fPlatform').val('').trigger('change.select2');
            $('#fKirilim').val(null).trigger('change.select2');
            varsayilanTarihler();
            raporYukle();
        });

        // Gizli form ile POST → tarayıcı dosyayı indirir
        $('#btnExcel').on('click', function () {
            const f = filtreVerisi();
            if (!filtreGecerli(f)) return;
            const form = $('<form>', { method: 'POST', action: '' }).appendTo('body');
            Object.entries(Object.assign({ action: 'excel' }, f)).forEach(([k, v]) =>
                (Array.isArray(v) ? v : [v]).forEach(x =>
                    $('<input>', { type: 'hidden', name: Array.isArray(v) ? k + '[]' : k, value: x }).appendTo(form)));
            form.submit().remove();
            showToast('Excel dosyası hazırlanıyor...', 'info');
        });

        // ===============================================================
        // Kırılım detayı (server-side) + Ayarlar (kurallar, kaynaklar)
        // ===============================================================
        const AYAR_YETKI = <?= $ayarYetki ? 'true' : 'false' ?>;
        const s2Dil = { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' };

        function s2(sec, ebeveyn) {
            $(sec).each(function () {
                if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
                $(this).select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 0, language: s2Dil,
                                  dropdownParent: $(ebeveyn) });
            });
        }

        const tipRozet = (tip) => tip === 'haric'
            ? '<span class="badge text-bg-warning">Hariç</span>'
            : '<span class="badge text-bg-success">Dahil</span>';

        /** Açıklamanın ilk 40 karakterinden desen önerisi: rakam ve %_[ temizlenir */
        const desenOneri = (a) => {
            const s = String(a || '').slice(0, 40).replace(/[0-9%_\[]/g, '').replace(/\s+/g, ' ').trim();
            return '%' + s + '%';
        };

        // Üst üste açılan modal: arkadaki modal karartılsın, kapanınca kaydırma kilidi geri gelsin
        $(document).on('shown.bs.modal', '#kuralModal', () => $('.modal-backdrop').last().css('z-index', 1060));
        $(document).on('hidden.bs.modal', '.modal', () => { if ($('.modal.show').length) $('body').addClass('modal-open'); });

        // ---------------------------------------------------------------
        // Kırılım detayı: tür → kolonlar. Kolon sırası sunucudaki siralama whitelist index'iyle aynıdır
        // (detayTanim). orderable: false olan kolonların index'i sunucuda yoktur.
        const sayi = (n) => Number(n || 0).toLocaleString('tr-TR');
        const yuzde = (v) => v === null || v === undefined ? '—' : '%' + Number(v).toLocaleString('tr-TR', { maximumFractionDigits: 2 });
        const metin = (v) => kacis(v);

        const DETAY_KOLONLARI = {
            satis: {
                ikon: 'bi-cart-check', ara: 'Sipariş no / müşteri / ürün / barkod', birim: 'sipariş',
                kolonlar: [
                    { title: 'Sipariş Tarihi', data: 'tarih', render: metin },
                    { title: 'Sipariş No', data: 'siparis_no', render: metin },
                    { title: 'Pazaryeri', data: 'pazaryeri', render: metin },
                    { title: 'Kanal', data: 'kanal', render: metin },
                    { title: 'Firma', data: 'firma', render: metin },
                    { title: 'Müşteri', data: 'musteri', render: metin },
                    { title: 'Ürün', data: 'urunler', orderable: false, className: 'aciklama-hucre',
                      render: (v) => v ? v.split(' ¦ ').map((u) => '<div>' + kacis(u) + '</div>').join('') : '—' },
                    { title: 'Durum', data: 'durum', render: (v) => '<span class="badge text-bg-light border">' + kacis(v || '-') + '</span>' },
                    { title: 'Kalem', data: 'kalem', orderable: false, className: 'text-end', render: sayi },
                    { title: 'Tutar', data: 'tutar', className: 'text-end tutar', render: para }
                ]
            },
            komisyon_pazaryeri: {
                ikon: 'bi-percent', ara: 'Sipariş no / ürün adı / barkod', birim: 'sipariş',
                kolonlar: [
                    { title: 'Sipariş Tarihi', data: 'tarih', render: metin },
                    { title: 'Sipariş No', data: 'siparis_no', render: metin },
                    { title: 'Pazaryeri', data: 'pazaryeri', render: metin },
                    { title: 'Firma', data: 'firma', render: metin },
                    { title: 'Ürün Adı', data: 'urun', className: 'aciklama-hucre', render: metin },
                    { title: 'Barkod', data: 'barkod', render: metin },
                    { title: 'Miktar', data: 'miktar', className: 'text-end', render: sayi },
                    { title: 'Kalem Tutarı', data: 'kalem_tutar', className: 'text-end tutar', render: para },
                    { title: 'Komisyon Oranı', data: 'komisyon_oran', className: 'text-end', render: yuzde },
                    { title: 'Komisyon Tutarı', data: 'komisyon_tutar', className: 'text-end tutar', render: (v) => v === null ? '—' : para(v) }
                ],
                // Komisyonu henüz gelmemiş kalem: soluk satır
                satirSinif: (tr, r) => {
                    if (r.komisyon_tutar === null) {
                        $(tr).addClass('satir-soluk').attr('title', 'Komisyon henüz yok: pazaryeri teslim sonrası bildirir');
                    }
                }
            },
            banka: {
                ikon: 'bi-bank', ara: 'Açıklamada ara', birim: 'hareket',
                kolonlar: [
                    { title: 'Tarih', data: 'tarih', render: metin },
                    { title: 'Banka / IBAN', data: 'banka', render: (v, t, r) => kacis(v) + '<br><small class="text-muted">' + kacis(r.iban) + '</small>' },
                    { title: 'Açıklama', data: 'aciklama', className: 'aciklama-hucre', render: metin },
                    { title: 'Tutar', data: 'tutar', className: 'text-end tutar', render: para },
                    { title: 'Kaynak', data: 'kural_desen', orderable: false, render: (v, t, r) => r.cost === 1
                        ? '<span class="badge text-bg-secondary">Banka işareti</span>'
                        : '<code>' + kacis(v) + '</code>' },
                    { title: 'İşlem', data: null, orderable: false, className: 'text-center', render: (v, t, r) => {
                        if (!AYAR_YETKI) return '';
                        if (r.cost === 1) {
                            return '<small class="text-muted" title="Banka entegrasyonunun işaretlediği satırlara hariç kuralı uygulanmaz">'
                                 + '<i class="bi bi-info-circle"></i> Banka işaretli</small>';
                        }
                        return '<button type="button" class="btn btn-sm btn-outline-warning py-0 btn-haric-tut" title="Bu açıklama için hariç kuralı oluştur">'
                             + '<i class="bi bi-slash-circle"></i> Hariç tut</button>';
                    } }
                ]
            },
            kargo: {
                ikon: 'bi-truck', ara: 'Takip no / gönderici / alıcı', birim: 'gönderi',
                kolonlar: [
                    { title: 'Çıkış Tarihi', data: 'tarih', render: metin },
                    { title: 'Yön', data: 'yon', render: (v) => v === 'GIDEN'
                        ? '<span class="badge text-bg-primary">Giden</span>' : '<span class="badge text-bg-info">Gelen</span>' },
                    { title: 'Takip No', data: 'takip_no', render: metin },
                    { title: 'Gönderici', data: 'gonderici', render: metin },
                    { title: 'Alıcı', data: 'alici', render: metin },
                    { title: 'Alıcı İl', data: 'alici_il', render: metin },
                    { title: 'Ödeme Tipi', data: 'odeme_tipi', render: metin },
                    { title: 'Durum', data: 'durum', className: 'aciklama-hucre', render: metin },
                    { title: 'Tutar', data: 'tutar', className: 'text-end tutar', render: para }
                ]
            }
        };

        let detayParam = null;   // {tur, anahtarlar, satir_firma_id, firma_id, platform, bas_tarih, bit_tarih}
        let detayKayit = null;   // tıklanan kırılım satırı (başlık / özet metni için)
        let tblDetay   = null;
        let detayAraZaman = null;

        /** Özet metni: sunucudaki toplam (kırılım kartıyla aynı tanım) + arama varsa uyan kısım */
        function detayOzetCiz(json) {
            const tanim = DETAY_KOLONLARI[detayParam.tur];
            const o = json.ozet || { adet: 0, toplam: 0 };
            let s = '<span>Toplam: <b>' + sayi(o.adet) + '</b> ' + tanim.birim;
            if (o.kalem !== undefined) {
                s += ' · ' + sayi(o.kalem) + ' kalem';
                if (o.bos) s += ' <span class="text-muted">(' + sayi(o.bos) + ' kalemde komisyon henüz yok)</span>';
            }
            s += ' · <b class="tutar">' + para(o.toplam) + '</b></span>';
            if (json.ozet_filtre) {
                const a = json.ozet_filtre;
                s += '<span class="ms-3 text-muted">Aramaya uyan: ' + sayi(a.kalem !== undefined ? a.kalem : a.adet) + ' kayıt · '
                   + '<span class="tutar">' + para(a.toplam) + '</span></span>';
            }
            // Kırılım kartındaki rakamla aynı olmalı; farklıysa (ör. veri arada değişti) kullanıcı uyarılır
            if (detayKayit && (Number(o.adet) !== Number(detayKayit.adet) || Math.abs(Number(o.toplam) - Number(detayKayit.tutar)) > 0.005)) {
                s += '<span class="ms-3 text-warning" title="Rapor yüklendikten sonra veri değişmiş olabilir; raporu yenileyin">'
                   + '<i class="bi bi-exclamation-triangle"></i> Kırılım kartıyla farklı</span>';
            }
            $('#detayOzet').html(s);
        }

        /** Tür değişebildiği için tablo her açılışta yeniden kurulur (destroy + yeni columns) */
        function detayKur() {
            if (tblDetay) {
                tblDetay.destroy();
                $('#tblDetay').empty();
                tblDetay = null;
            }
            const tanim = DETAY_KOLONLARI[detayParam.tur];
            tblDetay = $('#tblDetay').DataTable({
                serverSide: true,
                processing: true,
                scrollX: true,
                dom: 'lrtip',
                pageLength: 25,
                lengthMenu: [25, 50, 100],
                order: [[0, 'desc']],
                ajax: {
                    url: '',
                    type: 'POST',
                    data: (d) => Object.assign(d, { action: 'detay', ara: $('#detayAra').val() }, detayParam || {}),
                    dataSrc: (json) => {
                        if (json.error) { showToast(json.error, 'error'); $('#detayOzet').text('-'); return []; }
                        detayOzetCiz(json);
                        return json.data || [];
                    },
                    error: () => showToast('Detay alınamadı', 'error')
                },
                columns: tanim.kolonlar,
                createdRow: tanim.satirSinif || null,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
            });
        }

        $('#kirilimKartlari').on('click', '.kirilim-satir', function () {
            const k = detayKayitlari[$(this).data('idx')];
            if (!k || !DETAY_KOLONLARI[k.tur]) return;
            const f = k.filtre;   // raporun yüklendiği filtre: detay kırılım rakamıyla aynı kapsamda
            detayKayit = k;
            detayParam = {
                tur: k.tur, anahtarlar: k.anahtarlar, satir_firma_id: k.satir_firma_id,
                firma_id: f.firma_id, platform: f.platform, bas_tarih: f.bas_tarih, bit_tarih: f.bit_tarih
            };
            const tanim = DETAY_KOLONLARI[k.tur];
            $('#detayIkon').attr('class', 'bi ' + tanim.ikon + ' me-1');
            $('#detayBaslik').text(k.kaynak + ' – ' + k.alt + ' – ' + k.firma + ' (' + f.bas_tarih + ' – ' + f.bit_tarih + ')');
            $('#detayKapsam').text('Filtre: ' + f.firma_ad + (f.platform ? ' · Platform: ' + f.platform_ad : ''));
            $('#detayAraEtiket').text(tanim.ara);
            $('#detayAra').val('').attr('placeholder', tanim.ara);
            $('#detayOzet').text('Yükleniyor...');
            if (tblDetay) { tblDetay.destroy(); $('#tblDetay').empty(); tblDetay = null; }
            bootstrap.Modal.getOrCreateInstance('#detayModal').show();
        });

        // Tablo modal görünür olunca kurulur: scrollX kolon genişlikleri doğru hesaplanır
        $('#detayModal').on('shown.bs.modal', function (e) {
            if (e.target !== this || !detayParam) return;
            if (!tblDetay) { detayKur(); } else { tblDetay.columns.adjust(); }
        });

        $('#detayAra').on('input', function () {
            clearTimeout(detayAraZaman);
            detayAraZaman = setTimeout(() => tblDetay && tblDetay.ajax.reload(), 400);
        });

        $('#tblDetay').on('click', '.btn-haric-tut', function () {
            const r = tblDetay.row($(this).closest('tr')).data();
            if (!r) return;
            kuralFormAc({ id: 0, desen: desenOneri(r.aciklama), tip: 'haric', banka_id: r.banka_id, banka_adi: r.banka,
                          aciklama: '', durum: 1 });
        });

        // ---------------------------------------------------------------
        // Ayarlar
        if (!AYAR_YETKI) {
            varsayilanTarihler();
            raporYukle();
            return;
        }

        let ayar = null;   // {kurallar, bankalar, kaynaklar}

        function ayarYukle() {
            return $.post('', { action: 'ayar_liste' }, null, 'json').then(function (res) {
                if (!res.success) { showToast(res.message || 'Ayarlar alınamadı', 'error'); return $.Deferred().reject(); }
                ayar = res.data;
                kurallarCiz();
                kaynaklarCiz();
            }, function () { showToast('Sunucu hatası', 'error'); });
        }

        function kurallarCiz() {
            const liste = ayar.kurallar || [];
            $('#tblKurallar tbody').html(liste.length ? liste.map(r =>
                '<tr data-id="' + Number(r.id) + '">'
                + '<td><code>' + kacis(r.desen) + '</code></td>'
                + '<td>' + tipRozet(r.tip) + '</td>'
                + '<td>' + (r.banka_id ? kacis(r.banka_adi || ('#' + r.banka_id)) : '<span class="text-muted">Tüm bankalar</span>') + '</td>'
                + '<td><small>' + kacis(r.aciklama) + '</small></td>'
                + '<td><div class="form-check form-switch d-flex justify-content-center">'
                + '<input class="form-check-input kural-durum" type="checkbox" role="switch" id="kd_' + Number(r.id) + '"' + (Number(r.durum) ? ' checked' : '') + '>'
                + '<label class="form-check-label" for="kd_' + Number(r.id) + '"></label></div></td>'
                + '<td class="text-end text-nowrap">'
                + '<button type="button" class="btn btn-sm btn-outline-primary py-0 btn-kural-duzenle" title="Düzenle"><i class="bi bi-pencil"></i></button> '
                + '<button type="button" class="btn btn-sm btn-outline-danger py-0 btn-kural-sil" title="Sil"><i class="bi bi-trash"></i></button>'
                + '</td></tr>').join('')
                : '<tr><td colspan="6" class="text-center text-muted">Kural yok</td></tr>');
        }

        const renkHex = (r) => {
            r = String(r || '').trim();
            if (/^#[0-9a-f]{6}$/i.test(r)) return r;
            if (/^#[0-9a-f]{3}$/i.test(r)) return '#' + r.slice(1).split('').map(c => c + c).join('');
            return '#6c757d';
        };
        const IKON_DESENI = /^[a-zA-Z0-9 _-]{1,60}$/;

        function kaynaklarCiz() {
            $('#tblKaynaklar tbody').html((ayar.kaynaklar || []).map(k => {
                const id = Number(k.id);
                const ikon = IKON_DESENI.test(k.ikon || '') ? k.ikon : 'bi bi-circle';
                return '<tr data-id="' + id + '">'
                    + '<td><code>' + kacis(k.kod) + '</code></td>'
                    + '<td><span class="badge ' + (k.tip === 'gelir' ? 'text-bg-success' : 'text-bg-danger') + '">' + (k.tip === 'gelir' ? 'Gelir' : 'Gider') + '</span></td>'
                    + '<td><input type="text" class="form-control form-control-sm k-ad" maxlength="100" value="' + kacis(k.ad) + '"></td>'
                    + '<td><input type="color" class="form-control form-control-sm k-renk" value="' + renkHex(k.renk) + '" title="' + kacis(k.renk) + '"></td>'
                    + '<td><div class="input-group input-group-sm" style="min-width:170px">'
                    + '<span class="input-group-text"><i class="k-ikon-onizle ' + kacis(ikon) + '"></i></span>'
                    + '<input type="text" class="form-control k-ikon" maxlength="60" value="' + kacis(k.ikon) + '" placeholder="bi bi-truck"></div></td>'
                    + '<td><input type="number" class="form-control form-control-sm k-sira" min="0" max="9999" value="' + Number(k.sira) + '"></td>'
                    + '<td><input type="text" class="form-control form-control-sm k-aciklama" maxlength="300" value="' + kacis(k.aciklama) + '"></td>'
                    + '<td><div class="form-check form-switch d-flex justify-content-center">'
                    + '<input class="form-check-input kaynak-durum" type="checkbox" role="switch" id="kyd_' + id + '"' + (Number(k.durum) ? ' checked' : '') + '>'
                    + '<label class="form-check-label" for="kyd_' + id + '"></label></div></td>'
                    + '<td class="text-end"><button type="button" class="btn btn-sm btn-primary py-0 btn-kaynak-kaydet" title="Kaydet"><i class="bi bi-check-lg"></i></button></td>'
                    + '</tr>';
            }).join('') || '<tr><td colspan="9" class="text-center text-muted">Kaynak yok</td></tr>');
        }

        $('#btnAyarlar').on('click', function () {
            ayarYukle();
            bootstrap.Modal.getOrCreateInstance('#ayarModal').show();
        });

        /** Değişiklik sonrası: açık listeler + rapor (+ açıksa kırılım detayı) yenilenir; kart rakamı eskidiği için karşılaştırma kapanır */
        function degisiklikSonrasi() {
            if ($('#ayarModal').hasClass('show')) ayarYukle();
            detayKayit = null;
            if (tblDetay && $('#detayModal').hasClass('show')) tblDetay.ajax.reload(null, false);
            raporYukle();
        }

        // ---- Kural formu
        s2('#kTip', '#kuralModal');

        function bankaSecenekleri(seciliId, seciliAd) {
            const $b = $('#kBanka').empty().append(new Option('Tüm bankalar', '', false, false));
            (ayar.bankalar || []).forEach(b => $b.append(new Option(b.ad, b.id, false, false)));
            // Pasif bankaya bağlı kural / hareket: listede yoksa eklenir ki değer kaybolmasın
            if (seciliId && !$b.find('option[value="' + Number(seciliId) + '"]').length) {
                $b.append(new Option((seciliAd || ('#' + seciliId)) + ' (pasif)', seciliId, false, false));
            }
            $b.val(seciliId ? String(seciliId) : '');
            s2('#kBanka', '#kuralModal');
        }

        function kuralFormAc(k) {
            const ac = () => {
                $('#kId').val(k.id || 0);
                $('#kDesen').val(k.desen || '');
                $('#kTip').val(k.tip === 'haric' ? 'haric' : 'dahil').trigger('change.select2');
                bankaSecenekleri(k.banka_id || '', k.banka_adi || '');
                $('#kAciklama').val(k.aciklama || '');
                $('#kDurum').prop('checked', k.durum === undefined ? true : !!Number(k.durum));
                $('#kuralBaslik').text(k.id ? 'Kuralı Düzenle' : 'Yeni Kural');
                $('#onizleSonuc').empty();
                $('#onizleKapsam').text('');
                bootstrap.Modal.getOrCreateInstance('#kuralModal').show();
            };
            // Detaydan açılırken banka listesi henüz yüklenmemiş olabilir
            if (ayar) { ac(); } else { ayarYukle().then(ac); }
        }

        function kuralFormVerisi() {
            return {
                id:       $('#kId').val() || 0,
                desen:    $.trim($('#kDesen').val()),
                tip:      $('#kTip').val(),
                banka_id: $('#kBanka').val() || '',
                aciklama: $.trim($('#kAciklama').val()),
                durum:    $('#kDurum').is(':checked') ? 1 : 0
            };
        }

        function kuralFormGecerli(k) {
            if (!k.desen) { showToast('Desen zorunludur', 'warning'); return false; }
            if (k.desen.length > 200) { showToast('Desen en fazla 200 karakter olabilir', 'warning'); return false; }
            if (k.desen.indexOf('%') === -1 && k.desen.length < 3) { showToast('Desen en az bir % içermeli veya en az 3 karakter olmalı', 'warning'); return false; }
            return true;
        }

        $('#btnYeniKural').on('click', () => kuralFormAc({ id: 0, tip: 'dahil', durum: 1 }));

        $('#tblKurallar').on('click', '.btn-kural-duzenle', function () {
            const id = Number($(this).closest('tr').data('id'));
            const r = (ayar.kurallar || []).find(x => Number(x.id) === id);
            if (r) kuralFormAc(r);
        });

        $('#tblKurallar').on('click', '.btn-kural-sil', function () {
            const id = Number($(this).closest('tr').data('id'));
            const r = (ayar.kurallar || []).find(x => Number(x.id) === id);
            if (!r || !confirm('"' + r.desen + '" kuralı kalıcı olarak silinecek. Emin misiniz?')) return;
            $.post('', { action: 'kural_sil', id: id }, function (res) {
                showToast(res.message || (res.success ? 'Silindi' : 'Silinemedi'), res.success ? 'success' : 'error');
                if (res.success) degisiklikSonrasi();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        });

        $('#tblKurallar').on('change', '.kural-durum', function () {
            const $c = $(this);
            const durum = $c.is(':checked') ? 1 : 0;
            $.post('', { action: 'kural_durum', id: $c.closest('tr').data('id'), durum: durum }, function (res) {
                showToast(res.message || '', res.success ? 'success' : 'error');
                if (res.success) { degisiklikSonrasi(); } else { $c.prop('checked', !durum); }
            }, 'json').fail(() => { $c.prop('checked', !durum); showToast('Sunucu hatası', 'error'); });
        });

        $('#btnOnizle').on('click', function () {
            const k = kuralFormVerisi();
            const f = filtreVerisi();
            if (!kuralFormGecerli(k) || !filtreGecerli(f)) return;

            const $btn = $(this).prop('disabled', true);
            $('#onizleKapsam').text(f.bas_tarih + ' – ' + f.bit_tarih + ' · ' + ($('#fFirma option:selected').text() || 'Tüm firmalar'));
            $('#onizleSonuc').html('<div class="text-muted small">Hesaplanıyor...</div>');
            $.post('', Object.assign({ action: 'kural_onizle' }, f, k), function (res) {
                if (!res.success) { $('#onizleSonuc').empty(); showToast(res.message || 'Önizleme alınamadı', 'error'); return; }
                const d = res.data;
                const dahil = d.tip === 'dahil';
                const ozet = '<div class="alert ' + (dahil ? 'alert-success' : 'alert-warning') + ' py-2 small mb-2">'
                    + '<i class="bi ' + (dahil ? 'bi-plus-circle' : 'bi-dash-circle') + ' me-1"></i>'
                    + 'Bu kural ile <b>' + Number(d.adet).toLocaleString('tr-TR') + '</b> hareket (<b>' + para(d.toplam) + '</b>) '
                    + (dahil ? 'masrafa <b>eklenecek</b>.' : 'masraftan <b>çıkarılacak</b>.')
                    + (d.adet > d.satirlar.length ? ' En büyük ' + d.satirlar.length + ' tanesi listelenir.' : '')
                    + '</div>';
                const tablo = d.satirlar.length
                    ? '<div class="table-responsive"><table class="table table-sm table-striped mb-0 kirilim-tablo"><thead><tr>'
                      + '<th>Tarih</th><th>Banka</th><th>Açıklama</th><th class="text-end">Tutar</th></tr></thead><tbody>'
                      + d.satirlar.map(s => '<tr><td class="text-nowrap">' + kacis(s.tarih) + '</td>'
                          + '<td>' + kacis(s.banka) + '<br><small class="text-muted">' + kacis(s.iban) + '</small></td>'
                          + '<td>' + kacis(s.aciklama) + '</td><td class="text-end tutar">' + para(s.tutar) + '</td></tr>').join('')
                      + '</tbody></table></div>'
                    : '';
                $('#onizleSonuc').html(ozet + tablo);
            }, 'json')
            .fail(() => { $('#onizleSonuc').empty(); showToast('Sunucu hatası', 'error'); })
            .always(() => $btn.prop('disabled', false));
        });

        $('#btnKuralKaydet').on('click', function () {
            const k = kuralFormVerisi();
            if (!kuralFormGecerli(k)) return;
            const $btn = $(this).prop('disabled', true);
            $.post('', Object.assign({ action: 'kural_kaydet' }, k), function (res) {
                showToast(res.message || '', res.success ? 'success' : 'error');
                if (!res.success) return;
                bootstrap.Modal.getOrCreateInstance('#kuralModal').hide();
                degisiklikSonrasi();
            }, 'json')
            .fail(() => showToast('Sunucu hatası', 'error'))
            .always(() => $btn.prop('disabled', false));
        });

        // ---- Kaynaklar
        $('#tblKaynaklar').on('input', '.k-ikon', function () {
            const v = $.trim($(this).val());
            $(this).closest('td').find('.k-ikon-onizle').attr('class', 'k-ikon-onizle ' + (IKON_DESENI.test(v) ? v : 'bi bi-question-circle text-danger'));
        });

        $('#tblKaynaklar').on('click', '.btn-kaynak-kaydet', function () {
            const $tr = $(this).closest('tr');
            const veri = {
                action:   'kaynak_kaydet',
                id:       $tr.data('id'),
                ad:       $.trim($tr.find('.k-ad').val()),
                renk:     $tr.find('.k-renk').val(),
                ikon:     $.trim($tr.find('.k-ikon').val()),
                sira:     $tr.find('.k-sira').val(),
                aciklama: $.trim($tr.find('.k-aciklama').val())
            };
            if (!veri.ad) { showToast('Ad zorunludur', 'warning'); return; }
            if (!IKON_DESENI.test(veri.ikon)) { showToast('İkon yalnız harf, rakam, boşluk ve tire içerebilir', 'warning'); return; }
            const $btn = $(this).prop('disabled', true);
            $.post('', veri, function (res) {
                showToast(res.message || '', res.success ? 'success' : 'error');
                if (res.success) degisiklikSonrasi();
            }, 'json')
            .fail(() => showToast('Sunucu hatası', 'error'))
            .always(() => $btn.prop('disabled', false));
        });

        $('#tblKaynaklar').on('change', '.kaynak-durum', function () {
            const $c = $(this);
            const durum = $c.is(':checked') ? 1 : 0;
            $.post('', { action: 'kaynak_durum', id: $c.closest('tr').data('id'), durum: durum }, function (res) {
                showToast(res.message || '', res.success ? 'success' : 'error');
                if (res.success) { degisiklikSonrasi(); } else { $c.prop('checked', !durum); }
            }, 'json').fail(() => { $c.prop('checked', !durum); showToast('Sunucu hatası', 'error'); });
        });

        varsayilanTarihler();
        raporYukle();
    });
    </script>
</body>
</html>
