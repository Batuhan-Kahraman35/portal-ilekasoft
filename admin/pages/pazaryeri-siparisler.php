<?php
/**
 * Admin Panel - Pazaryeri Siparişleri
 *
 * Pazaryeri kanallarından (firma hesapları) çekilen siparişleri listeler.
 * Sipariş birimi pazaryerine göre "sipariş" veya "paket" olabilir; ortak katman
 * ikisini de PazaryeriSiparisleri tablosunda tutar.
 *
 * Kapsam: sipariş → kanal (EntegrasyonKanallari) → EntegrasyonKanallari_Firmalar_id → Firmalar.
 * Kullanıcının firma kısıtı (PageAuth::firmaKisiti) her sorguya kanal id listesi olarak uygulanır.
 *
 * Satır aksiyonları: kalem detayı, kargo etiketi (ZPL), paket durumu (Picking/Invoiced),
 * fatura önizleme / kesim (FaturaKesim: Örnek Holding e-Fatura / e-Arşiv; taslak veya kesin).
 * Dışarıda kesilmiş giden faturaları siparişlere eşleştirme (FaturaEslestirme: tekiller
 * otomatik, belirsizler seçim modalıyla).
 * Hangi aksiyonun görüneceği pazaryerinin yetenek bayraklarına göre belirlenir.
 *
 * Hareket tablosu → DataTables SERVER-SIDE, iki aşamalı sorgu.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
require_once __DIR__ . '/../includes/OrnekHoldingApi.php';
require_once __DIR__ . '/../includes/FaturaKesim.php';
require_once __DIR__ . '/../includes/FaturaEslestirme.php';
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

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri Siparişleri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

/** Sıralama kolonu istemciden gelen değerle doğrudan sorguya girmez */
const SIRALAMA_KOLONLARI = [
    2 => 's.PazaryeriSiparisleri_SiparisNo',
    3 => 's.PazaryeriSiparisleri_MusteriAdi',
    4 => 's.PazaryeriSiparisleri_ToplamTutar',
    5 => 's.PazaryeriSiparisleri_DurumKod',
    6 => 's.PazaryeriSiparisleri_KargoFirmasi',
    7 => 's.PazaryeriSiparisleri_SiparisTarihi',
];

/**
 * Kapsamdaki kanal id'leri (pazaryerine bağlı kanallar).
 * Pasif firmanın kanalları hiçbir kapsamda yer almaz; firmasız kanal görünür.
 * Kanalın kendisi pasifleştirilse bile geçmiş siparişleri görünmeye devam eder.
 * Sayım sorguları JOIN'siz kalsın diye liste önce çözülüp IN ile verilir.
 *
 * @return int[]
 */
function kapsamKanallari($firmaId, $pazaryeriId, $kanalId, $kisitFirmaId)
{
    $sql = "
        SELECT k.EntegrasyonKanallari_id AS id
        FROM dbo.EntegrasyonKanallari k
        INNER JOIN dbo.Entegrasyonlar e
            ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
        INNER JOIN dbo.tanim_Pazaryerleri p
            ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
        LEFT JOIN dbo.Firmalar f
            ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        WHERE (k.EntegrasyonKanallari_Firmalar_id IS NULL OR f.firma_durum = 1)
    ";
    $params = [];

    if ($firmaId > 0)     { $sql .= " AND k.EntegrasyonKanallari_Firmalar_id = ? "; $params[] = $firmaId; }
    if ($pazaryeriId > 0) { $sql .= " AND p.Pazaryerleri_id = ? ";                  $params[] = $pazaryeriId; }
    if ($kanalId > 0)     { $sql .= " AND k.EntegrasyonKanallari_id = ? ";           $params[] = $kanalId; }

    [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.EntegrasyonKanallari_Firmalar_id');
    $sql   .= $kisitSql;
    $params = array_merge($params, $kisitParams);

    return array_map('intval', array_column(Database::getInstance()->fetchAll($sql, $params), 'id'));
}

/** Kanal id listesini IN koşuluna çevirir (değerler int'e çevrilmiş olarak gelir) */
function kanalInSql(array $idler, $kolon)
{
    return $idler ? " $kolon IN (" . implode(',', array_map('intval', $idler)) . ") " : " 1 = 0 ";
}

/** Y-m-d biçiminde değilse boş döner; geçersiz tarih SQL'e gitmez */
function tarihGecerli($t)
{
    $t = trim((string)$t);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : '';
}

/**
 * Fatura filtresi → SQL koşulu (alias: s). Liste filtresi ve InfoBox sayımı aynı koşulu kullanır.
 * Faturalar_KesimDurumu: senkronla bağlanmış eski faturalarda boş, Durum = 1.
 *   yok          → iptal/iade dışı (pazaryeri iade kaydıyla tam iade dahil), geçerli faturası olmayan
 *   taslak       → taslak fatura
 *   kesildi      → kesinleşmiş/gönderilmiş fatura
 *   hata         → hatalı ya da sonucu belirsiz kesim
 *   bildirilmedi → bildirim destekleyen pazaryerinde kesinleşmiş fatura var, pazaryerine bildirilmemiş
 *                  (liste satırındaki bildirim_durum 'hayir' / 'bilinmiyor' ile aynı kapsam)
 */
function faturaFiltreSql($tur)
{
    $faturaVar = "EXISTS (SELECT 1 FROM dbo.Faturalar fa
                          WHERE fa.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id AND %s)";
    switch ($tur) {
        case 'yok':
            // Pazaryeri iade kaydıyla tamamen iade edilenler de hariç (sipariş durumu "teslim" kalır)
            return "(ISNULL(s.PazaryeriSiparisleri_DurumKod, '') NOT IN ('iptal', 'iade')
                     AND (s.PazaryeriSiparisleri_Faturalar_id IS NULL OR NOT "
                 . sprintf($faturaVar, "fa.Faturalar_Durum = 1 AND fa.Faturalar_IptalDurumu = 0") . ")
                     AND ISNULL(" . FaturaKesim::iadeDurumSql('s') . ", '') <> 'tam')";
        case 'taslak':
            return sprintf($faturaVar, "fa.Faturalar_KesimDurumu = 'TASLAK'");
        case 'kesildi':
            return sprintf($faturaVar, "fa.Faturalar_Durum = 1 AND fa.Faturalar_IptalDurumu = 0
                          AND ISNULL(fa.Faturalar_KesimDurumu, 'GONDERILDI') = 'GONDERILDI'");
        case 'hata':
            return sprintf($faturaVar, "fa.Faturalar_KesimDurumu IN ('HATA', 'GONDERILIYOR')");
        case 'bildirilmedi':
            // Kesinleşmiş: FaturaKesim::kesinlesmisMi ile aynı kural (taslakta GİB biçimli numara)
            return "(ISNULL(s.PazaryeriSiparisleri_DurumKod, '') <> 'iptal'
                     AND s.PazaryeriSiparisleri_FaturaBildirimTarihi IS NULL
                     AND EXISTS (SELECT 1 FROM dbo.tanim_Pazaryerleri bp
                                 WHERE bp.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                                   AND JSON_VALUE(bp.Pazaryerleri_Yetenekler, '$.faturaBildirim') = 'true')
                     AND " . sprintf($faturaVar, "fa.Faturalar_Durum = 1 AND fa.Faturalar_IptalDurumu = 0
                          AND (ISNULL(fa.Faturalar_KesimDurumu, '') <> 'TASLAK'
                               OR (LEN(fa.Faturalar_FaturaNo) = 16
                                   AND fa.Faturalar_FaturaNo LIKE '[A-Z0-9][A-Z0-9][A-Z0-9]' + REPLICATE('[0-9]', 13)
                                   AND RIGHT(fa.Faturalar_FaturaNo, 9) <> '000000000'))") . "
                     AND NOT (ISJSON(s.PazaryeriSiparisleri_HamVeri) = 1
                              AND NULLIF(JSON_VALUE(s.PazaryeriSiparisleri_HamVeri, '$.invoiceLink'), '') IS NOT NULL)
                     AND ISNULL(" . FaturaKesim::iadeDurumSql('s') . ", '') <> 'tam')";
    }
    return '';
}

/**
 * Liste ve istatistik için ortak WHERE koşulu (alias: s).
 * İstatistiklerde durum ve fatura filtresi uygulanmaz; kutuların kendisi bu filtreleri seçer.
 */
function siparisKosulu(array $girdi, $kisitFirmaId, $durumDahil = true, $faturaDahil = true)
{
    $idler = kapsamKanallari(
        (int)($girdi['firma_id'] ?? 0),
        (int)($girdi['pazaryeri_id'] ?? 0),
        (int)($girdi['kanal'] ?? 0),
        $kisitFirmaId
    );

    $kosul  = " WHERE " . kanalInSql($idler, 's.PazaryeriSiparisleri_EntegrasyonKanallari_id')
            . " AND s.PazaryeriSiparisleri_Durum = 1 ";
    $params = [];

    $ara = trim($girdi['ara'] ?? '');
    if ($ara !== '') {
        $kosul .= " AND (s.PazaryeriSiparisleri_SiparisNo LIKE ?
                      OR s.PazaryeriSiparisleri_PaketNo LIKE ?
                      OR s.PazaryeriSiparisleri_MusteriAdi LIKE ?
                      OR s.PazaryeriSiparisleri_KargoTakipNo LIKE ?) ";
        $like = '%' . $ara . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $durum = trim($girdi['durum'] ?? '');
    if ($durumDahil && $durum !== '') {
        $kosul .= " AND s.PazaryeriSiparisleri_DurumKod = ? ";
        $params[] = $durum;
    }
    $faturaSql = faturaFiltreSql(trim($girdi['fatura'] ?? ''));
    if ($faturaDahil && $faturaSql !== '') {
        $kosul .= " AND $faturaSql ";
    }

    if (($bas = tarihGecerli($girdi['bas_tarih'] ?? '')) !== '') {
        $kosul .= " AND s.PazaryeriSiparisleri_SiparisTarihi >= ? ";
        $params[] = $bas;
    }
    if (($bit = tarihGecerli($girdi['bit_tarih'] ?? '')) !== '') {
        $kosul .= " AND s.PazaryeriSiparisleri_SiparisTarihi < DATEADD(DAY, 1, CAST(? AS DATE)) ";
        $params[] = $bit;
    }

    return [$kosul, $params];
}

/** sqlsrv DateTime nesnelerini metne çevirir (json_encode için) */
function tarihleriMetneCevir(array $satir)
{
    foreach ($satir as $k => $v) {
        if ($v instanceof DateTimeInterface) {
            $satir[$k] = $v->format('Y-m-d H:i');
        }
    }
    return $satir;
}

/** Siparişin kanalı kullanıcının kapsamında mı (firma kısıtı + pasif firma) */
function siparisKapsamda($siparis, $kisitFirmaId)
{
    $kanalId = (int)($siparis['PazaryeriSiparisleri_EntegrasyonKanallari_id'] ?? 0);
    return $kanalId > 0 && in_array($kanalId, kapsamKanallari(0, 0, $kanalId, $kisitFirmaId), true);
}

// =====================================================================
// Fatura PDF (yeni sekmede). Yalnız kapsamdaki bir siparişe bağlı ya da
// o siparişin eşleştirme adayı olan fatura açılır; giden faturalar
// sayfasının yetkisi aranmaz.
// =====================================================================
if (($_GET['action'] ?? '') === 'faturaPdf') {
    $sid = (int)($_GET['siparis'] ?? 0);
    $fid = (int)($_GET['id'] ?? 0);

    $siparis = $db->fetchOne("
        SELECT PazaryeriSiparisleri_id, PazaryeriSiparisleri_EntegrasyonKanallari_id, PazaryeriSiparisleri_Faturalar_id
        FROM dbo.PazaryeriSiparisleri
        WHERE PazaryeriSiparisleri_id = ? AND PazaryeriSiparisleri_Durum = 1
    ", [$sid]);
    $izinli = $siparis && siparisKapsamda($siparis, $kisitFirmaId)
        && ((int)$siparis['PazaryeriSiparisleri_Faturalar_id'] === $fid
            || (new FaturaEslestirme($db, $user['kullanici_id']))
                   ->adayMi(kapsamKanallari(0, 0, 0, $kisitFirmaId), $sid, $fid));

    $f = $izinli ? $db->fetchOne("
        SELECT Faturalar_ETTN, Faturalar_FaturaNo, Faturalar_Tur, Faturalar_EntegrasyonKanallari_id
        FROM dbo.Faturalar
        WHERE Faturalar_id = ? AND Faturalar_Yon = 'GIDEN'
    ", [$fid]) : null;
    if (!$f || empty($f['Faturalar_ETTN'])) { http_response_code(404); exit('Fatura bulunamadı.'); }

    $kanal = null;
    foreach (OrnekHoldingApi::aktifKanallar($db) as $k) {
        if ((int)$k['EntegrasyonKanallari_id'] === (int)$f['Faturalar_EntegrasyonKanallari_id']) { $kanal = $k; break; }
    }
    if (!$kanal) { http_response_code(404); exit('Faturanın Örnek Holding kanalı aktif değil.'); }

    $api   = new OrnekHoldingApi($kanal, $user['kullanici_id']);
    $sonuc = $f['Faturalar_Tur'] === 'EARSIV'
        ? $api->earsivPdf($f['Faturalar_ETTN'])
        : $api->faturaPdf($f['Faturalar_ETTN'], 'OUT');
    if (!$sonuc['success'] || empty($sonuc['pdf'])) {
        http_response_code(502);
        exit('PDF alınamadı: ' . ($sonuc['message'] ?: 'bilinmeyen hata'));
    }

    while (ob_get_level()) { ob_end_clean(); }
    $ad = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $f['Faturalar_FaturaNo'] ?: $f['Faturalar_ETTN']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $ad . '"');
    header('Content-Length: ' . strlen($sonuc['pdf']));
    echo $sonuc['pdf'];
    exit;
}

// =====================================================================
// AJAX
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ---------------------------------------------------------
            // InfoBox: seçili filtre / tarih aralığında durum kırılımı + faturasız / bildirilmeyen.
            // Durum ve fatura filtresi uygulanmaz (kutular bu filtreleri seçer); sayımlar
            // liste filtresiyle aynı koşuldan (faturaFiltreSql) üretilir.
            case 'stats':
                [$kosul, $params] = siparisKosulu($_POST, $kisitFirmaId, false, false);
                // SUM içinde EXISTS kullanılamaz: bayraklar alt sorguda hesaplanıp dışarıda toplanır
                $satirlar = $db->fetchAll("
                    SELECT x.kod, COUNT(*) AS adet, SUM(x.tutar) AS tutar,
                           SUM(x.faturasiz) AS faturasiz, SUM(x.bildirilmeyen) AS bildirilmeyen
                    FROM (
                        SELECT
                            ISNULL(s.PazaryeriSiparisleri_DurumKod, 'diger') AS kod,
                            ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0) AS tutar,
                            CASE WHEN " . faturaFiltreSql('yok') . " THEN 1 ELSE 0 END AS faturasiz,
                            CASE WHEN " . faturaFiltreSql('bildirilmedi') . " THEN 1 ELSE 0 END AS bildirilmeyen
                        FROM dbo.PazaryeriSiparisleri s
                        $kosul
                    ) x
                    GROUP BY x.kod
                ", $params);

                $ozet = ['toplam' => 0, 'ciro' => 0.0, 'faturasiz' => 0, 'bildirilmeyen' => 0, 'durumlar' => []];
                foreach ($satirlar as $r) {
                    $ozet['toplam']        += (int)$r['adet'];
                    $ozet['faturasiz']     += (int)$r['faturasiz'];
                    $ozet['bildirilmeyen'] += (int)$r['bildirilmeyen'];
                    if (!in_array($r['kod'], ['iptal', 'iade'], true)) {
                        $ozet['ciro'] += (float)$r['tutar'];
                    }
                    $ozet['durumlar'][] = ['kod' => $r['kod'], 'adet' => (int)$r['adet'], 'tutar' => (float)$r['tutar']];
                }

                echo json_encode(['success' => true, 'data' => $ozet], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'list':
                $draw = (int)($_POST['draw'] ?? 0);

                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) { $length = 25; }

                $siraIdx = (int)($_POST['order'][0]['column'] ?? 7);
                $siraKol = SIRALAMA_KOLONLARI[$siraIdx] ?? 's.PazaryeriSiparisleri_SiparisTarihi';
                $siraYon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
                $orderSql = "$siraKol $siraYon, s.PazaryeriSiparisleri_id DESC";

                [$kosul, $params] = siparisKosulu($_POST, $kisitFirmaId);

                // Toplam: JOIN'siz, tek ana tablodan (yalnız firma kısıtı)
                $toplam = $db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.PazaryeriSiparisleri
                     WHERE " . kanalInSql(kapsamKanallari(0, 0, 0, $kisitFirmaId), 'PazaryeriSiparisleri_EntegrasyonKanallari_id') . "
                       AND PazaryeriSiparisleri_Durum = 1"
                );
                $filtreli = $db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.PazaryeriSiparisleri s " . $kosul,
                    $params
                );

                // 1. aşama ucuz CTE (yalnız ID), 2. aşamada kanal/firma ve kalem sayımı yalnız bu satırlara
                $satirlar = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT s.PazaryeriSiparisleri_id
                        FROM dbo.PazaryeriSiparisleri s
                        $kosul
                        ORDER BY $orderSql
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        s.PazaryeriSiparisleri_id,
                        s.PazaryeriSiparisleri_EntegrasyonKanallari_id,
                        s.PazaryeriSiparisleri_DisId,
                        s.PazaryeriSiparisleri_SiparisNo,
                        s.PazaryeriSiparisleri_PaketNo,
                        s.PazaryeriSiparisleri_DurumKod,
                        s.PazaryeriSiparisleri_HamDurum,
                        s.PazaryeriSiparisleri_ToplamTutar,
                        s.PazaryeriSiparisleri_ParaBirimi,
                        s.PazaryeriSiparisleri_MusteriAdi,
                        s.PazaryeriSiparisleri_Il,
                        s.PazaryeriSiparisleri_Ilce,
                        s.PazaryeriSiparisleri_KargoFirmasi,
                        s.PazaryeriSiparisleri_KargoTakipNo,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120)         AS PazaryeriSiparisleri_SiparisTarihi,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_TerminTarihi, 120)          AS PazaryeriSiparisleri_TerminTarihi,
                        s.PazaryeriSiparisleri_Faturalar_id,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_FaturaBildirimTarihi, 120)  AS PazaryeriSiparisleri_FaturaBildirimTarihi,
                        s.PazaryeriSiparisleri_FaturaBildirimHata,
                        -- Pazaryerinin kendi kaydındaki fatura (son senkron itibarıyla; Trendyol
                        -- paket yanıtı invoiceNumber/invoiceLink döner, diğerleri dönmez).
                        -- Ağır alan yalnız bu sayfanın satırlarında okunur.
                        hv.PyFaturaNo,
                        hv.PyFaturaLink,
                        hv.PyFaturaAlanVar,
                        pfi.PyFaturaIptal,
                        -- Pazaryeri iade kaydı: tam | kismi | acik | NULL (yalnız sayfa satırları)
                        " . FaturaKesim::iadeDurumSql('s') . " AS IadeDurum,
                        k.EntegrasyonKanallari_Ad,
                        p.Pazaryerleri_Ad,
                        p.Pazaryerleri_Renk,
                        p.Pazaryerleri_Yetenekler,
                        f.firma_adi,
                        kl.KalemSayisi,
                        fa.Faturalar_FaturaNo,
                        fa.Faturalar_ETTN,
                        fa.Faturalar_Tur,
                        fa.Faturalar_KesimDurumu,
                        fa.Faturalar_DurumAciklama,
                        fa.Faturalar_Durum,
                        fa.Faturalar_IptalDurumu,
                        fa.Faturalar_GibDurum
                    FROM sayfa sf
                    INNER JOIN dbo.PazaryeriSiparisleri s
                            ON s.PazaryeriSiparisleri_id = sf.PazaryeriSiparisleri_id
                    LEFT JOIN dbo.EntegrasyonKanallari k
                            ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p
                            ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f
                            ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    OUTER APPLY (
                        SELECT COUNT(*) AS KalemSayisi
                        FROM dbo.PazaryeriSiparisKalemleri kk
                        WHERE kk.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                          AND kk.PazaryeriSiparisKalemleri_Durum = 1
                    ) kl
                    OUTER APPLY (
                        SELECT
                            CASE WHEN ISJSON(s.PazaryeriSiparisleri_HamVeri) = 1
                                 THEN NULLIF(JSON_VALUE(s.PazaryeriSiparisleri_HamVeri, '$.invoiceNumber'), '') END AS PyFaturaNo,
                            CASE WHEN ISJSON(s.PazaryeriSiparisleri_HamVeri) = 1
                                 THEN NULLIF(JSON_VALUE(s.PazaryeriSiparisleri_HamVeri, '$.invoiceLink'), '') END AS PyFaturaLink,
                            CASE WHEN s.PazaryeriSiparisleri_HamVeri LIKE '%\"invoiceNumber\"%'
                                   OR s.PazaryeriSiparisleri_HamVeri LIKE '%\"invoiceLink\"%' THEN 1 ELSE 0 END AS PyFaturaAlanVar
                    ) hv
                    -- Pazaryerine bildirilmiş fatura portalda iptal mi (iptal edilip yenisi kesildiyse
                    -- pazaryerinde eski fatura kalır; yenisinin bildirilmesi gerekir).
                    -- Aynı numaradan aktif olan varsa iptal sayılmaz.
                    OUTER APPLY (
                        SELECT TOP 1 CAST(pf.Faturalar_IptalDurumu AS INT) AS PyFaturaIptal
                        FROM dbo.Faturalar pf
                        WHERE hv.PyFaturaNo IS NOT NULL
                          AND pf.Faturalar_FaturaNo = hv.PyFaturaNo
                          AND pf.Faturalar_Yon = 'GIDEN'
                        ORDER BY pf.Faturalar_IptalDurumu
                    ) pfi
                    LEFT JOIN dbo.Faturalar fa
                            ON fa.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id
                    ORDER BY $orderSql
                ", array_merge($params, [$start, $length]));

                // Yetenekler JSON'u satır aksiyonlarını belirler (seçili filtreden değil siparişin kanalından)
                foreach ($satirlar as &$r) {
                    $r['yetenekler'] = json_decode($r['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: new stdClass();
                    unset($r['Pazaryerleri_Yetenekler']);
                    $kesin = $r['Faturalar_Durum'] !== null && FaturaKesim::kesinlesmisMi($r);
                    $r['fatura_kesinlesmis'] = $kesin;
                    $tamIade = $r['IadeDurum'] === 'tam';
                    $r['fatura_kesilebilir'] = !$kesin && $r['PazaryeriSiparisleri_DurumKod'] !== 'iptal' && !$tamIade;

                    // Pazaryerine fatura bildirimi:
                    //   evet       → portal bildirdi ya da pazaryeri kaydında fatura linki var
                    //   iptal      → pazaryerinde duran fatura portalda iptal edilmiş; yenisi bildirilmeli
                    //   hayir      → kesinleşmiş fatura var, pazaryeri kaydında link yok (ya da bildirim hatası)
                    //   bilinmiyor → pazaryeri sipariş yanıtında fatura bilgisi dönmüyor (panelden bakılmalı)
                    //   null       → pazaryeri bildirim desteklemiyor ya da bildirilecek fatura yok
                    $bildirimVar = !empty($r['PazaryeriSiparisleri_FaturaBildirimTarihi']) || !empty($r['PyFaturaLink']);
                    $r['bildirim_durum'] = null;
                    $yet = (array)$r['yetenekler'];
                    if (!empty($yet['faturaBildirim'])) {
                        $aktifFatura = $r['Faturalar_Durum'] !== null && (int)$r['Faturalar_IptalDurumu'] === 0;
                        if ($tamIade && !$aktifFatura) {
                            // İade edilen siparişte bildirim beklenmez; duran link iade öncesine ait
                            $r['bildirim_durum'] = $bildirimVar ? 'iade' : null;
                        } elseif ($bildirimVar && (int)($r['PyFaturaIptal'] ?? 0) === 1) {
                            $r['bildirim_durum'] = 'iptal';
                        } elseif ($bildirimVar) {
                            $r['bildirim_durum'] = 'evet';
                        } elseif ($kesin && $r['PazaryeriSiparisleri_DurumKod'] !== 'iptal' && !$tamIade) {
                            $r['bildirim_durum'] = ((int)$r['PyFaturaAlanVar'] === 1 || !empty($r['PazaryeriSiparisleri_FaturaBildirimHata']))
                                ? 'hayir' : 'bilinmiyor';
                        }
                    }
                    unset($r['PyFaturaAlanVar']);
                }
                unset($r);

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => (int)($toplam['adet'] ?? 0),
                    'recordsFiltered' => (int)($filtreli['adet'] ?? 0),
                    'data'            => $satirlar
                ], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'detay':
                $id = (int)($_POST['id'] ?? 0);
                $siparis = $db->fetchOne("
                    SELECT s.*, k.EntegrasyonKanallari_Ad, p.Pazaryerleri_Ad, f.firma_adi, f.firma_unvan
                    FROM dbo.PazaryeriSiparisleri s
                    LEFT JOIN dbo.EntegrasyonKanallari k ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p   ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f             ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    WHERE s.PazaryeriSiparisleri_id = ? AND s.PazaryeriSiparisleri_Durum = 1
                ", [$id]);

                if (!$siparis || !siparisKapsamda($siparis, $kisitFirmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı.'], $jsonBayrak);
                    exit;
                }
                // Ham API yanıtı ekranda kullanılmaz; yükü büyütmesin
                unset($siparis['PazaryeriSiparisleri_HamVeri']);

                // Ürün eşleştirmesi varsa portal ürün kartının adı, yoksa kalemin kendi bilgisi
                $kalemler = $db->fetchAll("
                    SELECT
                        kl.PazaryeriSiparisKalemleri_id,
                        kl.PazaryeriSiparisKalemleri_UrunAdi,
                        kl.PazaryeriSiparisKalemleri_Barkod,
                        kl.PazaryeriSiparisKalemleri_StokKodu,
                        kl.PazaryeriSiparisKalemleri_Miktar,
                        kl.PazaryeriSiparisKalemleri_BirimFiyat,
                        kl.PazaryeriSiparisKalemleri_ToplamTutar,
                        kl.PazaryeriSiparisKalemleri_IndirimTutar,
                        kl.PazaryeriSiparisKalemleri_KdvOran,
                        kl.PazaryeriSiparisKalemleri_KomisyonOran,
                        kl.PazaryeriSiparisKalemleri_KomisyonTutar,
                        kl.PazaryeriSiparisKalemleri_HamDurum,
                        kl.PazaryeriSiparisKalemleri_Urun_Hizmet_id,
                        u.urun_hizmet_kodu,
                        u.urun_hizmet_adi
                    FROM dbo.PazaryeriSiparisKalemleri kl
                    LEFT JOIN dbo.Urun_Hizmet u ON u.urun_hizmet_id = kl.PazaryeriSiparisKalemleri_Urun_Hizmet_id
                    WHERE kl.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = ?
                      AND kl.PazaryeriSiparisKalemleri_Durum = 1
                    ORDER BY kl.PazaryeriSiparisKalemleri_id
                ", [$id]);

                echo json_encode(['success' => true, 'data' => [
                    'siparis'  => tarihleriMetneCevir($siparis),
                    'kalemler' => array_map('tarihleriMetneCevir', $kalemler)
                ]], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Filtre: tabloda geçen durum kodları (etiketler arayüzde çözülür)
            case 'durumlar':
                $idler = kapsamKanallari(0, 0, 0, $kisitFirmaId);
                $liste = $db->fetchAll("
                    SELECT DISTINCT PazaryeriSiparisleri_DurumKod AS kod
                    FROM dbo.PazaryeriSiparisleri
                    WHERE " . kanalInSql($idler, 'PazaryeriSiparisleri_EntegrasyonKanallari_id') . "
                      AND PazaryeriSiparisleri_Durum = 1
                      AND PazaryeriSiparisleri_DurumKod IS NOT NULL
                    ORDER BY PazaryeriSiparisleri_DurumKod
                ");
                echo json_encode(['success' => true, 'data' => array_column($liste, 'kod')], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Siparişleri pazaryerinden çek
            case 'senkron':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş çekmek için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }
                set_time_limit(300);

                $gun = (int)($_POST['gun'] ?? 14);
                if ($gun <= 0 || $gun > 365) { $gun = 14; }

                $firmaId     = (int)($_POST['firma_id'] ?? 0);
                $pazaryeriId = (int)($_POST['pazaryeri_id'] ?? 0);
                $kanalId     = (int)($_POST['kanal'] ?? 0);

                // Tek kanal ya da "Tümü": sipariş yeteneği olan, sınıfı yazılmış aktif
                // kanalların hepsi. Bir kanalın hatası diğerlerini durdurmaz.
                $hedefler = array_values(array_filter(
                    PazaryeriFabrika::kanallar(null, $firmaId > 0 ? $firmaId : null, $kisitFirmaId),
                    fn($k) => ($kanalId <= 0 || (int)$k['EntegrasyonKanallari_id'] === $kanalId)
                           && ($pazaryeriId <= 0 || (int)$k['Pazaryerleri_id'] === $pazaryeriId)
                           && !empty($k['sinifVar'])
                           && (int)($k['Pazaryerleri_Durum'] ?? 0) === 1
                           && !empty($k['yetenekler']['siparis'])
                ));

                if (empty($hedefler)) {
                    echo json_encode(['success' => false,
                        'message' => 'Seçimde sipariş çekmeyi destekleyen kanal yok.'], $jsonBayrak);
                    exit;
                }

                $sonuclar = [];
                $toplam   = 0;
                $basarili = 0;

                foreach ($hedefler as $k) {
                    $etiket = $k['Pazaryerleri_Ad'] . ' — ' . $k['EntegrasyonKanallari_Ad'];
                    try {
                        $api = PazaryeriFabrika::olusturKanaldan((int)$k['EntegrasyonKanallari_id'], $user['kullanici_id']);
                        if (!$api || !$api->hazirMi()) {
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => false,
                                'mesaj' => $api ? $api->getHata() : PazaryeriFabrika::sonHata()];
                            continue;
                        }
                        $sonuc = $api->siparisleriSenkronla($gun);
                        if (!empty($sonuc['basarili'])) {
                            $basarili++;
                            $toplam += (int)($sonuc['adet'] ?? 0);
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => true,
                                'mesaj' => (int)($sonuc['adet'] ?? 0) . ' sipariş'
                                    . (isset($sonuc['durumGuncellenen']) ? ', ' . (int)$sonuc['durumGuncellenen'] . ' durum güncellendi' : '')
                                    . (!empty($sonuc['durumHata']) ? ' (durum hatası: ' . $sonuc['durumHata'] . ')' : '')];
                        } else {
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => false,
                                'mesaj' => $sonuc['hata'] ?? 'Sipariş çekilemedi.'];
                        }
                    } catch (Throwable $e) {
                        error_log('pazaryeri-siparisler senkron: ' . $e->getMessage());
                        $sonuclar[] = ['kanal' => $etiket, 'basarili' => false, 'mesaj' => 'İşlem hatası'];
                    }
                }

                // Yeni gelen siparişler dışarıda kesilmiş faturalarla eşleşsin (yalnız tekiller)
                $eslesmeNot = '';
                if ($basarili > 0) {
                    try {
                        $o = (new FaturaEslestirme($db, $user['kullanici_id']))
                                 ->otomatik(kapsamKanallari(0, 0, 0, $kisitFirmaId));
                        if ($o['eslesen'] > 0) { $eslesmeNot = ' ' . $o['eslesen'] . ' sipariş mevcut faturayla eşleştirildi.'; }
                    } catch (Throwable $e) {
                        error_log('pazaryeri-siparisler eslestirme: ' . $e->getMessage());
                    }
                }

                echo json_encode([
                    'success'  => $basarili > 0,
                    'message'  => $toplam . ' sipariş işlendi (' . $basarili . '/' . count($hedefler) . ' kanal).' . $eslesmeNot,
                    'kanallar' => $sonuclar
                ], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Paket durumu ve kargo etiketi: kanal, seçili filtreden değil
            // SİPARİŞİN KENDİSİNDEN alınır; "Tümü" görünümünde işlem doğru
            // pazaryeri hesabına gitmelidir.
            case 'paketDurum':
            case 'kargoEtiketi':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }

                $id = (int)($_POST['id'] ?? 0);
                $siparis = $db->fetchOne("
                    SELECT PazaryeriSiparisleri_PaketNo, PazaryeriSiparisleri_DisId, PazaryeriSiparisleri_EntegrasyonKanallari_id
                    FROM dbo.PazaryeriSiparisleri
                    WHERE PazaryeriSiparisleri_id = ? AND PazaryeriSiparisleri_Durum = 1
                ", [$id]);

                if (!$siparis || !siparisKapsamda($siparis, $kisitFirmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı.'], $jsonBayrak);
                    exit;
                }

                $api = PazaryeriFabrika::olusturKanaldan((int)$siparis['PazaryeriSiparisleri_EntegrasyonKanallari_id'], $user['kullanici_id']);
                if (!$api || !$api->hazirMi()) {
                    echo json_encode(['success' => false,
                        'message' => $api ? $api->getHata() : PazaryeriFabrika::sonHata()], $jsonBayrak);
                    exit;
                }

                $paketId = $siparis['PazaryeriSiparisleri_PaketNo'] ?: $siparis['PazaryeriSiparisleri_DisId'];

                if ($action === 'paketDurum') {
                    $durum = $_POST['yeni_durum'] ?? '';
                    // Pazaryeri API komutları (Trendyol: Picking/Invoiced, n11: yalnız Picking)
                    if (!in_array($durum, ['Picking', 'Invoiced'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz durum.'], $jsonBayrak);
                        exit;
                    }
                    if (!$api->yetenek('paketDurum')) {
                        echo json_encode(['success' => false, 'message' => $api->getPazaryeriAdi() . ' paket durumu güncellemeyi desteklemiyor.'], $jsonBayrak);
                        exit;
                    }
                    $sonuc = $api->paketDurumGuncelle($paketId, $durum);
                    echo json_encode([
                        'success' => !empty($sonuc['basarili']),
                        'message' => ($sonuc['mesaj'] ?? '') ?: ($sonuc['hata'] ?? '')
                    ], $jsonBayrak);
                    exit;
                }

                if (!$api->yetenek('kargoEtiketi')) {
                    echo json_encode(['success' => false, 'message' => $api->getPazaryeriAdi() . ' kargo etiketini desteklemiyor.'], $jsonBayrak);
                    exit;
                }
                // Önce etiket talebi oluşturulur, sonra içeriği çekilir
                $api->kargoEtiketiOlustur($paketId);
                $etiket = $api->kargoEtiketiGetir($paketId);

                echo json_encode([
                    'success' => !empty($etiket['basarili']),
                    'message' => $etiket['hata'] ?? '',
                    'zpl'     => $etiket['zpl'] ?? null
                ], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Fatura: önizleme (kayıt yazmaz; mükellef sorgusu + numara tahmini) ve kesim.
            // Belge her istekte siparişten yeniden üretilir; istemciden tutar/kalem alınmaz.
            case 'faturaOnizle':
            case 'faturaKes':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Fatura işlemi için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }
                set_time_limit(180);

                $id = (int)($_POST['id'] ?? 0);
                $siparis = $db->fetchOne("
                    SELECT PazaryeriSiparisleri_id, PazaryeriSiparisleri_EntegrasyonKanallari_id
                    FROM dbo.PazaryeriSiparisleri
                    WHERE PazaryeriSiparisleri_id = ? AND PazaryeriSiparisleri_Durum = 1
                ", [$id]);
                if (!$siparis || !siparisKapsamda($siparis, $kisitFirmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı.'], $jsonBayrak);
                    exit;
                }

                $fk = new FaturaKesim((int)$user['kullanici_id'], $db);
                $b  = $fk->pazaryeriBelge($id);
                if (!$b['success']) {
                    echo json_encode(['success' => false, 'message' => $b['message']], $jsonBayrak);
                    exit;
                }
                $belge   = $b['belge'];
                $firmaId = (int)$belge['firma']['firma_id'];

                // Örnek Holding kanalı yalnız siparişin firmasının kanallarından seçilebilir
                $tbKanallar = $fk->ornekholdingKanallari($firmaId);
                $tbKanalId  = (int)($_POST['tb_kanal'] ?? 0);
                if (!in_array($tbKanalId, array_column($tbKanallar, 'kanal_id'), true)) {
                    $tbKanalId = (int)$fk->varsayilanKanal($firmaId);
                }
                if ($tbKanalId <= 0) {
                    echo json_encode(['success' => false,
                        'message' => 'Siparişin firmasına (' . $belge['firma']['unvan'] . ') bağlı aktif Örnek Holding kanalı yok.'], $jsonBayrak);
                    exit;
                }

                if ($action === 'faturaOnizle') {
                    $o = $fk->onizle($belge, $tbKanalId);
                    if (!$o['success']) {
                        echo json_encode(['success' => false, 'message' => $o['message']], $jsonBayrak);
                        exit;
                    }
                    $mevcut = $b['mevcut'];
                    echo json_encode(['success' => true, 'data' => [
                        'onizleme'    => $o,
                        'uyarilar'    => array_values(array_merge($b['uyarilar'], $o['uyarilar'])),
                        'alici'       => $belge['alici'],
                        'satici'      => ['unvan' => $belge['firma']['unvan'], 'vkn' => $belge['firma']['vergi_no']],
                        'kalemler'    => $belge['kalemler'],
                        'toplam'      => $belge['toplam'],
                        'para'        => $belge['para'],
                        'siparis_no'  => $belge['siparis_no'],
                        'pazaryeri'   => $belge['pazaryeri'],
                        'yontem'      => $belge['tutar_yontemi'],
                        'tb_kanallar' => $tbKanallar,
                        'tb_kanal'    => $tbKanalId,
                        'mevcut'      => $mevcut ? [
                            'no'          => $mevcut['Faturalar_FaturaNo'],
                            'ettn'        => $mevcut['Faturalar_ETTN'],
                            'tur'         => $mevcut['Faturalar_Tur'],
                            'durum'       => $mevcut['Faturalar_KesimDurumu'],
                            'aciklama'    => $mevcut['Faturalar_DurumAciklama'],
                            'kesinlesmis' => FaturaKesim::kesinlesmisMi($mevcut),
                        ] : null,
                    ]], $jsonBayrak);
                    exit;
                }

                $taslak  = ($_POST['taslak'] ?? '1') !== '0';
                $tekrar  = ($_POST['belirsiz_tekrar'] ?? '0') === '1';
                // Toplu işlem yalnız taslak yükler (karar: kesin kesim tek tek, önizlemeyle yapılır)
                if (($_POST['toplu'] ?? '0') === '1') {
                    $taslak = true;
                    $tekrar = false;
                }
                $ciftOnay = ($_POST['cift_onay'] ?? '0') === '1' && ($_POST['toplu'] ?? '0') !== '1';
                $sonuc   = $fk->kes($belge, $tbKanalId, $taslak, $tekrar, $ciftOnay);
                // Kesin kesimden sonra fatura linki pazaryerine bildirilir (hatası kesimi bozmaz)
                if (!empty($sonuc['success']) && !$taslak && empty($sonuc['test'])) {
                    try {
                        $sonuc['bildirim'] = $fk->pazaryeriBildir($id, $sonuc['web_key'] ?? '');
                    } catch (Throwable $e) {
                        $sonuc['bildirim'] = ['success' => false, 'message' => 'Bildirim hatası: ' . $e->getMessage()];
                    }
                }
                echo json_encode($sonuc + ['success' => false], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Faturayı pazaryerine bildir (fatura linki). Tekli: id; toplu: ids[] (en fazla 50)
            case 'faturaBildir':
            case 'faturaBildirToplu':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bildirim için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }
                set_time_limit(300);
                $ids = $action === 'faturaBildir'
                    ? [(int)($_POST['id'] ?? 0)]
                    : array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))))), 0, 50);
                $fk = new FaturaKesim((int)$user['kullanici_id'], $db);
                $sonuclar = [];
                $basarili = 0;
                foreach ($ids as $sid) {
                    $sp = $db->fetchOne("
                        SELECT PazaryeriSiparisleri_id, PazaryeriSiparisleri_EntegrasyonKanallari_id, PazaryeriSiparisleri_SiparisNo
                        FROM dbo.PazaryeriSiparisleri WHERE PazaryeriSiparisleri_id = ? AND PazaryeriSiparisleri_Durum = 1
                    ", [$sid]);
                    if (!$sp || !siparisKapsamda($sp, $kisitFirmaId)) {
                        $sonuclar[] = ['id' => $sid, 'success' => false, 'message' => 'Sipariş bulunamadı.'];
                        continue;
                    }
                    try {
                        $r = $fk->pazaryeriBildir($sid);
                    } catch (Throwable $e) {
                        $r = ['success' => false, 'message' => $e->getMessage()];
                    }
                    if (!empty($r['success'])) $basarili++;
                    $sonuclar[] = ['id' => $sid, 'siparis_no' => $sp['PazaryeriSiparisleri_SiparisNo']] + $r;
                }
                if ($action === 'faturaBildir') {
                    echo json_encode($sonuclar[0] ?? ['success' => false, 'message' => 'Sipariş seçilmedi.'], $jsonBayrak);
                    exit;
                }
                echo json_encode(['success' => true, 'basarili' => $basarili, 'toplam' => count($ids),
                                  'sonuclar' => $sonuclar], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Giden fatura eşleştirme (FaturaEslestirme). Kapsam filtreden değil
            // kullanıcının tüm kanallarından alınır; daraltma belirsiz eşleşmeyi gizleyebilir.
            case 'eslestirOtomatik':
            case 'eslestirBelirsiz':
            case 'eslestirBagla':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Eşleştirme için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }
                set_time_limit(120);
                $es     = new FaturaEslestirme($db, $user['kullanici_id']);
                $kapsam = kapsamKanallari(0, 0, 0, $kisitFirmaId);

                if ($action === 'eslestirOtomatik') {
                    $o = $es->otomatik($kapsam);
                    echo json_encode(['success' => true, 'data' => $o + [
                        'gun' => $es->getGun(), 'tolerans' => $es->getTolerans()
                    ]], $jsonBayrak);
                    exit;
                }
                if ($action === 'eslestirBelirsiz') {
                    echo json_encode(['success' => true, 'data' => $es->belirsizler($kapsam)], $jsonBayrak);
                    exit;
                }
                echo json_encode($es->bagla($kapsam, (int)($_POST['siparis_id'] ?? 0), (int)($_POST['fatura_id'] ?? 0)), $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'kanallar':
                $firmaId = (int)($_POST['firma_id'] ?? 0) ?: null;
                echo json_encode(['success' => true,
                    'kanallar' => PazaryeriFabrika::kanallar(null, $firmaId, $kisitFirmaId)], $jsonBayrak);
                exit;
        }

        echo json_encode(['success' => false, 'message' => 'Geçersiz istek.'], $jsonBayrak);
        exit;

    } catch (Throwable $e) {
        error_log('pazaryeri-siparisler.php: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.'], $jsonBayrak);
        exit;
    }
}

$firmalar     = PazaryeriFabrika::firmalar($kisitFirmaId);
$pazaryerleri = PazaryeriFabrika::pazaryerleri(true);
$kanallar     = PazaryeriFabrika::kanallar(null, null, $kisitFirmaId);
$duzenleyebilir = !empty($pagePermissions['can_edit']);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .filtre-basligi { cursor: pointer; }
        .durum-rozet { font-size: .72rem; }
        .tutar { font-variant-numeric: tabular-nums; font-weight: 600; }
        .kalem-tablo th { font-size: .75rem; text-transform: uppercase; color: #6c757d; }
        .zpl-kutu {
            font-family: monospace; font-size: .7rem;
            max-height: 260px; overflow: auto;
            background: #f8f9fa; padding: .5rem; border-radius: .25rem;
            white-space: pre-wrap; word-break: break-all;
        }
        .adres-satir { font-size: .8rem; color: #495057; }
        #tblSiparisler td { vertical-align: middle; white-space: nowrap; }
        /* Bildirilmemiş fatura: üstü çarpılı kırmızı "B" */
        .bildirim-yok {
            position: relative; overflow: hidden;
            color: #dc3545; background: #fff; border: 1px solid #dc3545;
        }
        .bildirim-yok::before, .bildirim-yok::after {
            content: ''; position: absolute; left: -2px; right: -2px; top: 50%;
            border-top: 2px solid #dc3545;
        }
        .bildirim-yok::before { transform: rotate(45deg); }
        .bildirim-yok::after  { transform: rotate(-45deg); }
        /* Tıklanabilir InfoBox; seçili filtre kutusu çerçeveli */
        #ibAlan .info-box { cursor: pointer; transition: box-shadow .15s; }
        #ibAlan .info-box:hover { box-shadow: 0 0 0 1px var(--bs-primary); }
        #ibAlan .info-box.ib-secili { box-shadow: 0 0 0 2px var(--bs-primary); }
        #ibAlan .info-box-text { white-space: normal; }
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
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
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

                    <!-- InfoBox: seçili filtre ve tarih aralığına göre -->
                    <!-- Kutular loadStats() ile durum kırılımından üretilir; tıklanınca filtre uygular -->
                    <div class="row mb-3" id="ibAlan"></div>

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
                                        <label class="form-label small mb-1">Pazaryeri</label>
                                        <select class="form-select form-select-sm" id="fPazaryeri">
                                            <option value="">Tümü</option>
                                            <?php foreach ($pazaryerleri as $p): ?>
                                            <option value="<?= (int)$p['Pazaryerleri_id'] ?>"><?= htmlspecialchars($p['Pazaryerleri_Ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Kanal</label>
                                        <select class="form-select form-select-sm" id="fKanal"></select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Durum</label>
                                        <select class="form-select form-select-sm" id="fDurum">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Ara</label>
                                        <input type="text" class="form-control form-control-sm" id="fAra"
                                               placeholder="Sipariş no, müşteri, takip no...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Fatura</label>
                                        <select class="form-select form-select-sm" id="fFatura">
                                            <option value="">Tümü</option>
                                            <option value="yok">Faturasız</option>
                                            <option value="taslak">Taslak</option>
                                            <option value="kesildi">Kesildi</option>
                                            <option value="hata">Hatalı / sonucu belirsiz</option>
                                            <option value="bildirilmedi">Pazaryerine bildirilmeyen</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Başlangıç</label>
                                        <input type="date" class="form-control form-control-sm" id="fBas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Bitiş</label>
                                        <input type="date" class="form-control form-control-sm" id="fBit">
                                    </div>
                                    <div class="col-md-6 d-flex gap-2 justify-content-end">
                                        <button class="btn btn-sm btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tablo -->
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h3 class="card-title flex-grow-1 mb-0">Sipariş Listesi</h3>
                            <?php if ($duzenleyebilir): ?>
                            <button class="btn btn-sm btn-outline-success me-2" id="btnTopluTaslak" disabled
                                    title="Seçilen siparişler için Örnek Holding'ye taslak yüklenir (GİB'e gitmez)">
                                <i class="bi bi-files me-1"></i> Seçilenlere taslak yükle (<span id="seciliSayi">0</span>)
                            </button>
                            <button class="btn btn-sm btn-outline-warning me-2" id="btnTopluBildir" disabled
                                    title="Seçilen siparişlerin kesinleşmiş faturalarının linki pazaryerine gönderilir">
                                <i class="bi bi-send me-1"></i> Seçilenleri bildir (<span class="secili-sayi">0</span>)
                            </button>
                            <?php endif; ?>
                            <?php if ($duzenleyebilir): ?>
                            <button class="btn btn-sm btn-outline-primary me-2" id="btnEslestir"
                                    title="Örnek Holding'de kesilmiş giden faturaları faturasız siparişlere bağlar (pazaryerine bildirim gitmez)">
                                <i class="bi bi-link-45deg me-1"></i> Faturaları Eşleştir
                            </button>
                            <button class="btn btn-sm btn-primary" id="btnSenkron">
                                <i class="bi bi-cloud-download me-1"></i> Siparişleri Çek
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <table class="table table-hover table-sm align-middle w-100" id="tblSiparisler" style="width:100%">
                                <thead>
                                    <tr>
                                        <th style="width:64px">
                                            <?php if ($duzenleyebilir): ?>
                                            <div class="form-check form-switch d-flex justify-content-center mb-0" title="Sayfadaki faturalanabilir siparişlerin tümü">
                                                <input class="form-check-input chk-tumu" type="checkbox" role="switch" id="chkTumu" value="1">
                                                <label class="form-check-label" for="chkTumu"></label>
                                            </div>
                                            <?php endif; ?>
                                        </th>
                                        <th>Kanal</th>
                                        <th>Sipariş No</th>
                                        <th>Müşteri</th>
                                        <th class="text-end">Tutar</th>
                                        <th>Durum</th>
                                        <th>Kargo</th>
                                        <th>Tarih</th>
                                        <th>Fatura</th>
                                        <th class="text-end">İşlem</th>
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

    <!-- Sipariş detay -->
    <div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bag"></i> Sipariş Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="detayIcerik"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Fatura önizleme / kesim -->
    <div class="modal fade" id="faturaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-receipt"></i> Fatura Kes <small class="text-muted" id="faturaBaslik"></small></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="faturaIcerik"></div>
                <div class="modal-footer justify-content-between">
                    <div class="d-flex align-items-center gap-2" id="faturaKanalKutu" style="min-width:280px">
                        <label class="small text-muted mb-0" for="faturaTbKanal">Örnek Holding kanalı</label>
                        <select class="form-select form-select-sm" id="faturaTbKanal" style="width:260px"></select>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                        <button type="button" class="btn btn-outline-warning d-none" id="btnFaturaTekrar"
                                title="Önceki gönderimin sonucu bilinmiyor; aynı ETTN ile tekrar gönderilir">
                            <i class="bi bi-arrow-repeat me-1"></i> Aynı ETTN ile tekrar
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="btnFaturaTaslak"
                                title="Örnek Holding'ye taslak yüklenir, GİB'e gitmez">
                            <i class="bi bi-file-earmark me-1"></i> Taslak yükle
                        </button>
                        <button type="button" class="btn btn-success" id="btnFaturaKes">
                            <i class="bi bi-send-check me-1"></i> Kesin kes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toplu taslak -->
    <div class="modal fade" id="topluModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="topluBaslik"><i class="bi bi-files"></i> Toplu Taslak Yükleme</h5>
                </div>
                <div class="modal-body">
                    <div class="progress mb-2" style="height:20px">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="topluBar" style="width:0%">0%</div>
                    </div>
                    <div class="small text-muted mb-3" id="topluOzet"></div>
                    <div class="table-responsive">
                        <table class="table table-sm kalem-tablo mb-0">
                            <thead><tr><th>Sipariş</th><th>Müşteri</th><th>Sonuç</th></tr></thead>
                            <tbody id="topluSonuc"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger" id="btnTopluDurdur">Durdur</button>
                    <button type="button" class="btn btn-secondary" id="btnTopluKapat" data-bs-dismiss="modal" disabled>Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Fatura eşleştirme: birden fazla adayı olan siparişler -->
    <div class="modal fade" id="eslestirModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-link-45deg"></i> Fatura Eşleştirme <small class="text-muted">birden fazla aday</small></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="small text-muted mb-2" id="eslestirOzet"></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:34%">Sipariş</th>
                                    <th>Aday faturalar</th>
                                </tr>
                            </thead>
                            <tbody id="eslestirListe"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Kargo etiketi -->
    <div class="modal fade" id="etiketModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Kargo Etiketi (ZPL)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Aşağıdaki ZPL içeriği etiket yazıcısına gönderilebilir.</p>
                    <div class="zpl-kutu" id="zplIcerik"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary" id="btnZplKopyala">
                        <i class="bi bi-clipboard me-1"></i> Kopyala
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {

        const DUZENLEYEBILIR = <?= $duzenleyebilir ? 'true' : 'false' ?>;
        const tumKanallar = <?= json_encode($kanallar, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

        const kacis = (s) => $('<div>').text(s ?? '').html();
        const tarihStr = (d) => d.toISOString().slice(0, 10);
        const paraFormat = (t, pb) => t !== null && t !== undefined
            ? Number(t).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              + ' ' + (!pb || pb === 'TRY' ? '₺' : pb)
            : '-';

        // Ortak katmanın normalize durum kodları (PazaryeriApi alt sınıfları üretir)
        // [rozet sınıfı, etiket, InfoBox ikonu]; sıra InfoBox sırasını belirler
        const DURUM = {
            yeni:         ['text-bg-warning',   'Yeni',          'bi-stars'],
            hazirlaniyor: ['text-bg-info',      'Hazırlanıyor',  'bi-hourglass-split'],
            kargoda:      ['text-bg-primary',   'Kargoda',       'bi-truck'],
            teslim:       ['text-bg-success',   'Teslim edildi', 'bi-check2-circle'],
            iptal:        ['text-bg-secondary', 'İptal',         'bi-x-circle'],
            iade:         ['text-bg-danger',    'İade',          'bi-arrow-return-left'],
            diger:        ['text-bg-light',     'Diğer',         'bi-question-circle']
        };
        const durumEtiket = (kod) => (DURUM[kod] || [null, kod])[1];

        // custom.js tüm .form-select kutularına "Seçiniz..." yer tutuculu Select2 kurar;
        // kendi etiketleriyle yeniden kurulur.
        [['#fFirma', 'Tüm firmalar'], ['#fPazaryeri', 'Tümü'], ['#fKanal', 'Tümü'], ['#fDurum', 'Tümü'], ['#fFatura', 'Tümü']]
            .forEach(([sec, etiket]) => {
                const $s = $(sec);
                if ($s.hasClass('select2-hidden-accessible')) { $s.select2('destroy'); }
                initSelect2(sec, { placeholder: etiket, minimumResultsForSearch: 0 });
            });

        const siparisDestekler = (k) => !!(k && k.sinifVar && String(k.Pazaryerleri_Durum) === '1'
                                           && k.yetenekler && k.yetenekler.siparis);

        function secimKanallari() {
            const firmaId = $('#fFirma').val();
            const pyId = $('#fPazaryeri').val();
            return tumKanallar.filter(k =>
                (!firmaId || String(k.EntegrasyonKanallari_Firmalar_id) === String(firmaId)) &&
                (!pyId || String(k.Pazaryerleri_id) === String(pyId)));
        }

        function varsayilanTarihler() {
            const bas = new Date();
            bas.setDate(bas.getDate() - 30);
            $('#fBas').val(tarihStr(bas));
            $('#fBit').val(tarihStr(new Date()));
        }

        // ---------------------------------------------------------------
        function kanallariDoldur(yenile = true) {
            const onceki = $('#fKanal').val();
            const liste = secimKanallari();
            $('#fKanal').html('<option value="">Tümü</option>'
                + liste.map(k => '<option value="' + k.EntegrasyonKanallari_id + '">'
                    + kacis(k.Pazaryerleri_Ad + ' — ' + k.EntegrasyonKanallari_Ad
                            + (k.firma_adi ? ' (' + k.firma_adi + ')' : '')) + '</option>').join(''));
            const kalsin = liste.some(k => String(k.EntegrasyonKanallari_id) === String(onceki));
            $('#fKanal').val(kalsin ? onceki : '').trigger('change.select2');
            senkronButonu();
            if (yenile) yenileHepsi();
        }

        function senkronButonu() {
            const id = $('#fKanal').val();
            const destek = id
                ? siparisDestekler(tumKanallar.find(k => String(k.EntegrasyonKanallari_id) === String(id)))
                : secimKanallari().some(siparisDestekler);
            $('#btnSenkron').prop('disabled', !destek)
                .attr('title', destek ? '' : 'Seçimde sipariş çekmeyi destekleyen kanal yok');
        }

        function filtreVerisi() {
            return {
                firma_id:     $('#fFirma').val() || '',
                pazaryeri_id: $('#fPazaryeri').val() || '',
                kanal:        $('#fKanal').val() || '',
                durum:        $('#fDurum').val() || '',
                fatura:       $('#fFatura').val() || '',
                ara:          $('#fAra').val(),
                bas_tarih:    $('#fBas').val(),
                bit_tarih:    $('#fBit').val()
            };
        }

        // ---------------------------------------------------------------
        // InfoBox: Toplam + seçimde geçen her durum + Faturasız + Bildirilmeyen.
        // data-filtre="alan:değer" → tıklanınca o filtre uygulanır (seçiliyse kaldırılır).
        const sayiFormat = (n) => Number(n || 0).toLocaleString('tr-TR');

        function infoKutu(filtre, ikonSinif, ikon, baslik, sayi, alt, ipucu) {
            return '<div class="col-6 col-md-4 col-xl-2">'
                + '<div class="info-box" role="button" data-filtre="' + filtre + '" title="' + kacis(ipucu) + '">'
                + '<span class="info-box-icon ' + ikonSinif + ' shadow-sm"><i class="bi ' + ikon + '"></i></span>'
                + '<div class="info-box-content">'
                + '<span class="info-box-text">' + kacis(baslik) + '</span>'
                + '<span class="info-box-number">' + sayiFormat(sayi) + '</span>'
                + (alt ? '<span class="small text-muted">' + kacis(alt) + '</span>' : '')
                + '</div></div></div>';
        }

        function infoSeciliIsaretle() {
            const durum = $('#fDurum').val() || '', fatura = $('#fFatura').val() || '';
            $('#ibAlan .info-box').each(function () {
                const [alan, deger] = String($(this).data('filtre')).split(':');
                const secili = alan === 'tumu' ? (!durum && !fatura)
                             : alan === 'durum' ? durum === deger
                             : fatura === deger;
                $(this).toggleClass('ib-secili', secili);
            });
        }

        function loadStats() {
            $.post('', Object.assign({ action: 'stats' }, filtreVerisi()), function (res) {
                if (!res.success) return;
                const d = res.data;
                const sira = Object.keys(DURUM);
                const durumlar = d.durumlar.slice().sort((a, b) =>
                    (sira.indexOf(a.kod) + 1 || 99) - (sira.indexOf(b.kod) + 1 || 99));

                let html = infoKutu('tumu:', 'text-bg-dark', 'bi-bag', 'Toplam Sipariş', d.toplam,
                    paraFormat(d.ciro, 'TRY'), 'Tüm durumlar; ciro iptal ve iade hariç. Tıklayınca durum ve fatura filtresi kaldırılır.');
                durumlar.forEach(r => {
                    const t = DURUM[r.kod] || ['text-bg-light', r.kod, 'bi-circle'];
                    html += infoKutu('durum:' + r.kod, t[0], t[2], t[1], r.adet, paraFormat(r.tutar, 'TRY'),
                        t[1] + ' durumundaki siparişleri listele');
                });
                html += infoKutu('fatura:yok', 'text-bg-secondary', 'bi-receipt', 'Faturasız', d.faturasiz, '',
                    'İptal ve iade hariç, geçerli (kesinleşmiş ya da taslak) faturası olmayan siparişler');
                html += infoKutu('fatura:bildirilmedi', 'text-bg-danger', 'bi-bell-slash', 'Bildirilmeyen', d.bildirilmeyen, '',
                    'Fatura bildirimini destekleyen pazaryerlerinde kesinleşmiş faturası olup pazaryerine bildirilmemiş siparişler');
                $('#ibAlan').html(html);
                infoSeciliIsaretle();
            }, 'json');
        }

        $('#ibAlan').on('click', '.info-box', function () {
            const [alan, deger] = String($(this).data('filtre')).split(':');
            const secili = $(this).hasClass('ib-secili');
            if (alan === 'tumu') {
                $('#fDurum, #fFatura').val('').trigger('change.select2');
            } else {
                const $s = alan === 'durum' ? $('#fDurum') : $('#fFatura');
                if (!secili && !$s.find('option').filter((i, o) => o.value === deger).length) {
                    $s.append(new Option(durumEtiket(deger), deger));
                }
                $s.val(secili ? '' : deger).trigger('change.select2');
            }
            yenileHepsi();
        });

        function loadDurumlar() {
            $.post('', { action: 'durumlar' }, function (res) {
                if (!res.success) return;
                const $s = $('#fDurum');
                const secili = $s.val();
                $s.find('option:not(:first)').remove();
                res.data.forEach(kod => $s.append(new Option(durumEtiket(kod), kod)));
                $s.val(res.data.includes(secili) ? secili : '').trigger('change.select2');
            }, 'json');
        }

        function yenileHepsi() {
            seciliSiparisler.clear();
            seciliGuncelle();
            // Tek kanal seçiliyken Kanal kolonu tekrar eden bilgi olur
            // scrollX: genişlikler yeni veri çizildikten SONRA hesaplanmalı; önce
            // yapılırsa başlık/gövde eski içeriğin ölçüsünde kalır ve tablo sıkışır
            tablo.column(1).visible(!$('#fKanal').val(), false);
            tablo.one('draw', function () { tablo.columns.adjust(); loadStats(); });
            tablo.ajax.reload();
        }

        // ---------------------------------------------------------------
        // Faturalar_KesimDurumu → rozet (FaturaKesim'in yazdığı değerler; boş = senkronla bağlanmış fatura)
        const KESIM = {
            GONDERILDI:   ['text-bg-success',   'Kesildi'],
            TASLAK:       ['text-bg-info',      'Taslak'],
            HATA:         ['text-bg-danger',    'Hata'],
            GONDERILIYOR: ['text-bg-warning',   'Sonucu belirsiz']
        };

        // Pazaryerine bildirim rozeti (sunucu: bildirim_durum = evet | iptal | hayir | bilinmiyor | null)
        //   B (yeşil)        bildirildi; pazaryerinin linki varsa tıklanınca o fatura açılır
        //   B (sarı)         bildirilmiş ama pazaryerindeki fatura no bizim faturadan farklı
        //   B (kırmızı çarpı) kesinleşmiş fatura var, pazaryerine bildirilmemiş ya da
        //                     pazaryerinde iptal edilmiş eski fatura duruyor
        //   B? (gri)         pazaryeri sipariş verisinde bildirim bilgisi dönmüyor
        // Bildirilmemiş rozet tıklanınca fatura linki pazaryerine gönderilir (düzenleme yetkisi)
        const BILDIREBILIR = <?= $duzenleyebilir ? 'true' : 'false' ?>;
        const bildirLinki = (r, rozet) => BILDIREBILIR
            ? '<a href="#" class="btn-bildir text-decoration-none" data-id="' + r.PazaryeriSiparisleri_id + '">' + rozet + '</a>'
            : rozet;

        function bildirimRozeti(r, no) {
            const d = r.bildirim_durum;
            if (!d) return '';
            if (d === 'evet') {
                const farkli = r.PyFaturaNo && no && r.PyFaturaNo !== no;
                const baslik = farkli
                    ? 'Pazaryerine farklı fatura bildirilmiş: ' + r.PyFaturaNo + ' (portaldaki: ' + no + ')'
                    : 'Pazaryerine bildirildi'
                      + (r.PyFaturaNo ? ': ' + r.PyFaturaNo : '')
                      + (r.PazaryeriSiparisleri_FaturaBildirimTarihi ? ' · ' + r.PazaryeriSiparisleri_FaturaBildirimTarihi : '');
                const rozet = '<span class="badge ' + (farkli ? 'text-bg-warning' : 'text-bg-success') + ' durum-rozet" title="'
                            + kacis(baslik) + '">B</span>';
                return /^https?:\/\//i.test(r.PyFaturaLink || '')
                    ? '<a href="' + kacis(r.PyFaturaLink) + '" target="_blank" rel="noopener" class="text-decoration-none">' + rozet + '</a>'
                    : rozet;
            }
            if (d === 'iade') {
                // İade edilen sipariş: pazaryerindeki link iade öncesi (iptal edilmiş) faturaya ait
                const rozet = '<span class="badge text-bg-secondary durum-rozet" title="'
                    + kacis('Sipariş iade edildi; pazaryerindeki fatura linki iade öncesine ait' + (r.PyFaturaNo ? ' (' + r.PyFaturaNo + ')' : '')
                            + '. Bildirim gerekmez.') + '">B</span>';
                return /^https?:\/\//i.test(r.PyFaturaLink || '')
                    ? '<a href="' + kacis(r.PyFaturaLink) + '" target="_blank" rel="noopener" class="text-decoration-none">' + rozet + '</a>'
                    : rozet;
            }
            const tikla = BILDIREBILIR ? ' — tıklayın: pazaryerine bildir' : '';
            if (d === 'iptal') {
                return bildirLinki(r, '<span class="badge durum-rozet bildirim-yok" title="'
                     + kacis('Pazaryerinde iptal edilmiş fatura duruyor (' + (r.PyFaturaNo || '-') + '); yeni fatura'
                            + (no ? ' ' + no : '') + ' bildirilmeli' + tikla)
                     + '">B</span>');
            }
            if (d === 'hayir') {
                return bildirLinki(r, '<span class="badge durum-rozet bildirim-yok" title="'
                     + kacis((r.PazaryeriSiparisleri_FaturaBildirimHata
                            ? 'Bildirim hatası: ' + r.PazaryeriSiparisleri_FaturaBildirimHata
                            : 'Pazaryerine bildirilmedi; bildirilmesi gerekiyor') + tikla)
                     + '">B</span>');
            }
            return bildirLinki(r, '<span class="badge text-bg-light border durum-rozet" title="'
                 + kacis('Bu pazaryeri sipariş verisinde fatura bildirim bilgisi vermiyor; pazaryeri panelinden kontrol edin' + tikla)
                 + '">B?</span>');
        }

        $('#tblSiparisler').on('click', '.btn-bildir', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const r = tablo.row($(this).closest('tr')).data() || {};
            Swal.fire({
                icon: 'question',
                title: 'Fatura pazaryerine bildirilsin mi?',
                html: kacis((r.Pazaryerleri_Ad || '') + ' · ' + (r.PazaryeriSiparisleri_SiparisNo || ''))
                    + (r.Faturalar_FaturaNo ? '<br>Fatura: <b>' + kacis(r.Faturalar_FaturaNo) + '</b>' : '')
                    + '<br><span class="small text-muted">Faturanın girişsiz linki pazaryerindeki siparişe kaydedilir.</span>',
                showCancelButton: true, confirmButtonText: 'Bildir', cancelButtonText: 'Vazgeç', confirmButtonColor: '#198754'
            }).then(c => {
                if (!c.isConfirmed) return;
                $.post('', { action: 'faturaBildir', id: id }, function (res) {
                    showToast(res.message || (res.success ? 'Bildirildi' : 'Bildirilemedi'), res.success ? 'success' : 'error');
                    tablo.ajax.reload(null, false);
                    loadStats();
                }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
            });
        });

        function faturaHucresi(r) {
            let ust = '';
            let alt = '';
            let no = '';
            if (r.PazaryeriSiparisleri_Faturalar_id && r.Faturalar_Durum !== null) {
                const iptal = String(r.Faturalar_IptalDurumu) === '1';
                // Kesinleşmiş fatura kısaca "K"; diğer durumlar açık yazılır
                const h = iptal ? ['text-bg-secondary', 'İptal', 'İptal edildi']
                        : (r.fatura_kesinlesmis ? ['text-bg-success', 'K', 'Kesildi']
                                                : (KESIM[r.Faturalar_KesimDurumu] || ['text-bg-light', '-']));
                no = (r.Faturalar_FaturaNo && !/0{9}$/.test(r.Faturalar_FaturaNo)) ? r.Faturalar_FaturaNo : '';
                // PDF yalnız GİB'e gitmiş (kesinleşmiş / iptal edilmiş) ve ETTN'si olan faturada
                const pdf = r.Faturalar_ETTN && (r.fatura_kesinlesmis || iptal)
                    ? '?action=faturaPdf&siparis=' + r.PazaryeriSiparisleri_id + '&id=' + r.PazaryeriSiparisleri_Faturalar_id : '';
                const baslik = [h[2], r.Faturalar_DurumAciklama].filter(Boolean).join(' · ');
                ust = '<span class="badge ' + h[0] + ' durum-rozet"' + (baslik ? ' title="' + kacis(baslik) + '"' : '') + '>'
                    + kacis(h[1]) + '</span>';
                if (r.Faturalar_Tur) {
                    alt = ' <small class="text-muted">' + (r.Faturalar_Tur === 'EARSIV' ? 'e-Arşiv' : 'e-Fatura') + '</small>';
                }
                if (no) {
                    alt += '<br>' + (pdf
                        ? '<a class="small fw-semibold text-decoration-none" href="' + pdf + '" target="_blank" rel="noopener" title="Faturayı görüntüle (PDF)">'
                          + '<i class="bi bi-file-earmark-pdf text-danger"></i> ' + kacis(no) + '</a>'
                        : '<small class="fw-semibold">' + kacis(no) + '</small>');
                }
            }
            const b = bildirimRozeti(r, no);
            // Pazaryeri iade kaydı (sipariş durumu "teslim" kalsa da)
            const IADE = {
                tam:   ['text-bg-danger',  'İade',   'Sipariş iade edildi (pazaryerinde onaylandı); fatura kesilmez'],
                kismi: ['text-bg-warning', 'K.İade', 'Kısmi iade onaylandı; iade edilen kalemler için iade faturası gerekebilir'],
                acik:  ['text-bg-light border', 'İade?', 'Sonuçlanmamış iade talebi var']
            };
            let iade = '';
            const ih = IADE[r.IadeDurum];
            if (ih) {
                let t = ih[2];
                // Tam iade edilen siparişte iptal edilmemiş fatura duruyorsa iptal / iade faturası gerekir
                if (r.IadeDurum === 'tam' && r.PazaryeriSiparisleri_Faturalar_id && r.Faturalar_Durum !== null
                    && String(r.Faturalar_IptalDurumu) !== '1') {
                    t = 'Sipariş iade edildi; fatura (' + (no || '-') + ') aktif. İadenin muhasebe kaydı (fatura iptali / iade belgesi) kontrol edilmeli';
                }
                iade = '<span class="badge ' + ih[0] + ' durum-rozet" title="' + kacis(t) + '">' + ih[1] + '</span>';
            }
            if (!ust && !b && !iade) return '<span class="text-muted small">-</span>';
            return '<span class="d-inline-flex gap-1 align-middle">' + iade + ust + b + '</span>' + alt;
        }

        // Toplu taslak seçimi: id → {no, musteri}; sayfalar arası korunur, filtre değişince temizlenir
        const seciliSiparisler = new Map();
        function seciliGuncelle() {
            $('#seciliSayi, .secili-sayi').text(seciliSiparisler.size);
            $('#btnTopluTaslak, #btnTopluBildir').prop('disabled', seciliSiparisler.size === 0);
            const $sayfa = $('#tblSiparisler .chk-siparis');
            $('.chk-tumu').prop('checked', $sayfa.length > 0 && $sayfa.filter(':checked').length === $sayfa.length);
        }

        // İlk istekten önce filtre değerleri hazır olmalı (tarih aralığı + kanal listesi)
        varsayilanTarihler();
        kanallariDoldur(false);

        const tablo = $('#tblSiparisler').DataTable({
            serverSide: true,
            processing: true,
            scrollX: true,
            autoWidth: false,
            dom: 'lrtip',
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    Object.assign(d, filtreVerisi());
                    d.action = 'list';
                    return d;
                },
                error: function () { showToast('Liste yüklenemedi', 'error'); }
            },
            columns: [
                {
                    data: 'PazaryeriSiparisleri_id', orderable: false, className: 'text-center',
                    render: (d, t, r) => '<div class="d-flex align-items-center justify-content-center gap-1">'
                        + (DUZENLEYEBILIR && r.fatura_kesilebilir
                            ? '<div class="form-check form-switch d-flex justify-content-center mb-0">'
                              + '<input class="form-check-input chk-siparis" type="checkbox" role="switch" id="chk' + d + '" value="1"'
                              + ' data-id="' + d + '"' + (seciliSiparisler.has(String(d)) ? ' checked' : '') + '>'
                              + '<label class="form-check-label" for="chk' + d + '"></label></div>'
                            : '')
                        + '<button class="btn btn-sm btn-link p-0 btn-detay" data-id="' + d + '" title="Detay">'
                        + '<i class="bi bi-chevron-expand"></i></button></div>'
                },
                {
                    data: 'Pazaryerleri_Ad', orderable: false,
                    render: (d, t, r) => '<span class="badge" style="background:' + kacis(r.Pazaryerleri_Renk || '#6c757d') + '">'
                                       + kacis(d || '-') + '</span>'
                                       + '<br><small class="text-muted">' + kacis(r.firma_adi || r.EntegrasyonKanallari_Ad || '') + '</small>'
                },
                {
                    data: 'PazaryeriSiparisleri_SiparisNo',
                    render: (d, t, r) => {
                        let html = '<span class="fw-semibold">' + kacis(d || r.PazaryeriSiparisleri_DisId) + '</span>';
                        if (r.KalemSayisi) {
                            html += ' <span class="badge text-bg-light">' + r.KalemSayisi + ' kalem</span>';
                        }
                        if (r.PazaryeriSiparisleri_PaketNo && r.PazaryeriSiparisleri_PaketNo !== d) {
                            html += '<br><small class="text-muted">paket: ' + kacis(r.PazaryeriSiparisleri_PaketNo) + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_MusteriAdi',
                    render: (d, t, r) => {
                        let html = kacis(d || '-');
                        const yer = [r.PazaryeriSiparisleri_Ilce, r.PazaryeriSiparisleri_Il].filter(Boolean).join(' / ');
                        if (yer) html += '<br><small class="text-muted">' + kacis(yer) + '</small>';
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_ToplamTutar', className: 'text-end tutar',
                    render: (d, t, r) => paraFormat(d, r.PazaryeriSiparisleri_ParaBirimi)
                },
                {
                    data: 'PazaryeriSiparisleri_DurumKod',
                    render: (d, t, r) => {
                        const h = DURUM[d] || ['text-bg-light', d || '-'];
                        let html = '<span class="badge ' + h[0] + ' durum-rozet">' + kacis(h[1]) + '</span>';
                        if (r.PazaryeriSiparisleri_HamDurum) {
                            html += '<br><small class="text-muted">' + kacis(r.PazaryeriSiparisleri_HamDurum) + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_KargoFirmasi',
                    render: (d, t, r) => {
                        let html = kacis(d || '-');
                        if (r.PazaryeriSiparisleri_KargoTakipNo) {
                            html += '<br><small class="text-muted">' + kacis(r.PazaryeriSiparisleri_KargoTakipNo) + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_SiparisTarihi',
                    render: (d, t, r) => (d ? kacis(d) : '-')
                        + (r.PazaryeriSiparisleri_TerminTarihi
                            ? '<br><small class="text-muted" title="Termin">termin: ' + kacis(r.PazaryeriSiparisleri_TerminTarihi) + '</small>' : '')
                },
                {
                    data: 'PazaryeriSiparisleri_Faturalar_id', orderable: false,
                    render: (d, t, r) => faturaHucresi(r)
                },
                {
                    data: 'PazaryeriSiparisleri_id', className: 'text-end', orderable: false,
                    render: function (d, t, r) {
                        // Yetenekler seçili filtreye göre değil siparişin kendi pazaryerine göre;
                        // desteklenmeyen aksiyon hiç gösterilmez (tanim_Pazaryerleri.Pazaryerleri_Yetenekler).
                        const yet = r.yetenekler || {};
                        let html = '';
                        if (DUZENLEYEBILIR && r.fatura_kesilebilir) {
                            html += '<button class="btn btn-sm btn-outline-success btn-fatura" data-id="' + d
                                  + '" title="Fatura kes"><i class="bi bi-receipt"></i></button> ';
                        }
                        if (DUZENLEYEBILIR && yet.kargoEtiketi) {
                            html += '<button class="btn btn-sm btn-outline-secondary btn-etiket" data-id="' + d
                                  + '" title="Kargo etiketi"><i class="bi bi-upc-scan"></i></button> ';
                        }
                        if (DUZENLEYEBILIR && yet.paketDurum) {
                            html += '<div class="btn-group btn-group-sm">'
                                  + '<button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" title="Paket durumu">'
                                  + '<i class="bi bi-gear"></i></button>'
                                  + '<ul class="dropdown-menu dropdown-menu-end">'
                                  + '<li><a class="dropdown-item btn-durum" href="#" data-id="' + d + '" data-durum="Picking">'
                                  + '<i class="bi bi-box-seam me-1"></i> Hazırlanıyor olarak işaretle</a></li>'
                                  + '<li><a class="dropdown-item btn-durum" href="#" data-id="' + d + '" data-durum="Invoiced">'
                                  + '<i class="bi bi-receipt me-1"></i> Faturalandı olarak işaretle</a></li>'
                                  + '</ul></div>';
                        }
                        return html || '<span class="text-muted small">-</span>';
                    }
                }
            ],
            order: [[7, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
        });

        // Filtre paneli açılıp kapanınca ve pencere boyutu değişince kart genişliği
        // değişir; scrollX başlığı ile gövdesi yeniden hizalanmalı
        $('#filtrePanel').on('shown.bs.collapse hidden.bs.collapse', () => tablo.columns.adjust());
        let boyutZaman = null;
        $(window).on('resize', function () {
            clearTimeout(boyutZaman);
            boyutZaman = setTimeout(() => tablo.columns.adjust(), 150);
        });
        // Kenar menü aç/kapa geçiş animasyonu bitince
        $(document).on('click', '[data-lte-toggle="sidebar"]', () => setTimeout(() => tablo.columns.adjust(), 350));

        // ---------------------------------------------------------------
        $('#fFirma, #fPazaryeri').on('change', () => kanallariDoldur(true));
        $('#fKanal').on('change', function () { senkronButonu(); yenileHepsi(); });
        $('#fDurum, #fFatura, #fBas, #fBit').on('change', yenileHepsi);
        $('#btnFiltrele').on('click', yenileHepsi);

        let aramaZaman = null;
        $('#fAra').on('keyup', function (e) {
            clearTimeout(aramaZaman);
            aramaZaman = setTimeout(yenileHepsi, e.which === 13 ? 0 : 400);
        });

        $('#btnTemizle').on('click', function () {
            $('#fAra').val('');
            varsayilanTarihler();
            if ($('#fFirma option[value=""]').length) { $('#fFirma').val('').trigger('change.select2'); }
            $('#fPazaryeri, #fDurum, #fFatura').val('').trigger('change.select2');
            kanallariDoldur(true);
            showToast('Filtreler temizlendi', 'info');
        });

        // ---------------------------------------------------------------
        // Fatura eşleştirme: tekiller sunucuda otomatik bağlanır,
        // birden fazla adayı olanlar modalda tek tek seçilir.
        // ---------------------------------------------------------------
        const eslestirModal = new bootstrap.Modal('#eslestirModal');
        let eslestirDegisti = false;

        $('#btnEslestir').on('click', function () {
            Swal.fire({ title: 'Faturalar eşleştiriliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.post('', { action: 'eslestirOtomatik' }, function (res) {
                Swal.close();
                if (!res.success) { showToast(res.message || 'Eşleştirilemedi', 'error'); return; }
                const d = res.data;
                showToast(d.eslesen + ' sipariş otomatik eşleştirildi'
                    + (d.belirsiz ? ', ' + d.belirsiz + ' sipariş seçim bekliyor' : ''), d.eslesen ? 'success' : 'info');
                if (d.eslesen) { yenileHepsi(); }
                if (d.belirsiz) {
                    $('#eslestirOzet').text('Kural: tutar farkı ≤ '
                        + Number(d.tolerans).toLocaleString('tr-TR') + ' TL, fatura tarihi sipariş günü ile +'
                        + d.gun + ' gün arası; VKN/TCKN eşit ya da (aynı firmada) ünvan eşit. '
                        + 'Ürünleri karşılaştırarak seçin. Bağlama pazaryerine bildirim göndermez.');
                    belirsizYukle();
                    eslestirModal.show();
                }
            }, 'json').fail(function () { Swal.close(); showToast('Sunucu hatası', 'error'); });
        });

        function belirsizYukle() {
            $('#eslestirListe').html('<tr><td colspan="2" class="text-center text-muted py-3">Yükleniyor...</td></tr>');
            $.post('', { action: 'eslestirBelirsiz' }, function (res) {
                if (!res.success) { showToast(res.message || 'Liste alınamadı', 'error'); return; }
                belirsizCiz(res.data);
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        }

        function belirsizCiz(satirlar) {
            // Satırlar sipariş başına gruplanır (sunucu sid sırasıyla gönderir)
            const gruplar = [];
            satirlar.forEach(r => {
                const son = gruplar[gruplar.length - 1];
                if (son && son.sid === r.sid) { son.adaylar.push(r); } else { gruplar.push({ sid: r.sid, s: r, adaylar: [r] }); }
            });
            if (!gruplar.length) {
                $('#eslestirListe').html('<tr><td colspan="2" class="text-center text-muted py-3">Seçim bekleyen sipariş yok.</td></tr>');
                return;
            }
            // Ürün listesi: "2 × Ürün adı (stok kodu) · 1.049,00 ₺"
            const kalemListe = (liste, bosMetin, tutarEk = '') => (liste && liste.length)
                ? '<ul class="list-unstyled small mb-0 mt-1 ps-2 border-start">' + liste.map(k =>
                    '<li><span class="text-muted">' + Number(k.miktar || 0).toLocaleString('tr-TR') + ' ×</span> '
                    + kacis(k.ad || '-') + (k.kod ? ' <span class="text-muted">(' + kacis(k.kod) + ')</span>' : '')
                    + (k.tutar !== null && k.tutar !== undefined ? ' <span class="text-muted">· ' + paraFormat(k.tutar, 'TRY') + tutarEk + '</span>' : '')
                    + '</li>').join('') + '</ul>'
                : '<div class="small text-muted fst-italic mt-1">' + bosMetin + '</div>';

            $('#eslestirListe').html(gruplar.map(g => {
                const s = g.s;
                return '<tr data-sid="' + g.sid + '">'
                    + '<td><div class="fw-semibold">' + kacis(s.PazaryeriSiparisleri_SiparisNo) + '</div>'
                    + '<div class="small">' + kacis(s.PazaryeriSiparisleri_MusteriAdi) + '</div>'
                    + '<div class="small text-muted">' + kacis(s.Pazaryerleri_Ad || '') + ' · '
                    + kacis(s.PazaryeriSiparisleri_SiparisTarihi) + ' · '
                    + paraFormat(s.PazaryeriSiparisleri_ToplamTutar, s.PazaryeriSiparisleri_ParaBirimi) + '</div>'
                    + (s.PazaryeriSiparisleri_KargoTakipNo ? '<div class="small text-muted">Kargo: ' + kacis(s.PazaryeriSiparisleri_KargoTakipNo) + '</div>' : '')
                    + kalemListe(s.siparis_kalemleri, 'Sipariş kalemi yok')
                    + '</td>'
                    + '<td>' + g.adaylar.map(a =>
                        '<div class="d-flex align-items-start gap-2 py-1 border-bottom aday" data-fid="' + a.fid + '">'
                        + '<button class="btn btn-sm btn-outline-success btn-bagla" data-sid="' + g.sid + '" data-fid="' + a.fid + '">'
                        + '<i class="bi bi-link-45deg"></i> Bağla</button>'
                        + '<a class="btn btn-sm btn-outline-danger" title="Faturayı görüntüle (PDF)" target="_blank" rel="noopener"'
                        + ' href="?action=faturaPdf&siparis=' + g.sid + '&id=' + a.fid + '"><i class="bi bi-file-earmark-pdf"></i></a>'
                        + '<div class="small flex-grow-1"><span class="fw-semibold">' + kacis(a.Faturalar_FaturaNo) + '</span>'
                        + ' <span class="text-muted">' + (a.Faturalar_Tur === 'EARSIV' ? 'e-Arşiv' : 'e-Fatura') + '</span>'
                        + ' · ' + kacis(a.Faturalar_Tarih) + ' · ' + paraFormat(a.Faturalar_OdenecekTutar, 'TRY')
                        + '<br>' + kacis(a.Faturalar_CariUnvan)
                        + (a.Faturalar_CariVKN ? ' <span class="text-muted">(' + kacis(a.Faturalar_CariVKN) + ')</span>' : '')
                        + (String(a.unvan_eslesti) === '1' ? ' <span class="badge text-bg-info">Ünvan eşit</span>' : '')
                        + (String(a.vkn_eslesti) === '1' ? ' <span class="badge text-bg-success">VKN/TCKN eşit</span>' : '')
                        + (String(a.firma_farkli) === '1' ? ' <span class="badge text-bg-warning" title="Fatura siparişin firmasından farklı bir Örnek Holding kanalından kesilmiş">Farklı firma: '
                            + kacis(a.FaturaFirma || '-') + '</span>' : '')
                        + (Number(a.fid_aday) > 1 ? ' <span class="badge text-bg-warning" title="Bu fatura başka siparişlerin de adayı">'
                            + a.fid_aday + ' siparişte aday</span>' : '')
                        + kalemListe(a.fatura_kalemleri, 'Fatura kalemleri senkronlanmamış; PDF\'ten bakın', ' (KDV hariç)')
                        + '</div></div>').join('')
                    + '</td></tr>';
            }).join(''));
        }

        $('#eslestirListe').on('click', '.btn-bagla', function () {
            const $b = $(this);
            const sid = $b.data('sid'), fid = $b.data('fid');
            $b.prop('disabled', true);
            $.post('', { action: 'eslestirBagla', siparis_id: sid, fatura_id: fid }, function (res) {
                if (!res.success) {
                    $b.prop('disabled', false);
                    showToast(res.message || 'Bağlanamadı', 'error');
                    return;
                }
                showToast(res.message, 'success');
                eslestirDegisti = true;
                // Sipariş listeden çıkar; aynı fatura diğer siparişlerin adaylarından düşer
                $('#eslestirListe tr[data-sid="' + sid + '"]').remove();
                $('#eslestirListe .aday[data-fid="' + fid + '"]').remove();
                $('#eslestirListe tr').each(function () {
                    if (!$(this).find('.aday').length && $(this).data('sid')) { $(this).remove(); }
                });
                if (!$('#eslestirListe tr[data-sid]').length) { belirsizCiz([]); }
            }, 'json').fail(function () { $b.prop('disabled', false); showToast('Sunucu hatası', 'error'); });
        });

        $('#eslestirModal').on('hidden.bs.modal', function () {
            if (eslestirDegisti) { eslestirDegisti = false; yenileHepsi(); }
        });

        // ---------------------------------------------------------------
        // Sipariş çekme (seçimdeki destekleyen tüm kanallar)
        // ---------------------------------------------------------------
        $('#btnSenkron').on('click', function () {
            if ($(this).prop('disabled')) return;
            const kanalId = $('#fKanal').val();

            Swal.fire({
                title: kanalId ? 'Siparişleri çek' : 'Seçimdeki tüm kanallardan siparişleri çek',
                input: 'number',
                inputLabel: 'Kaç günlük sipariş çekilsin?',
                inputValue: 14,
                inputAttributes: { min: 1, max: 365, step: 1 },
                inputValidator: (v) => (!v || v < 1 || v > 365) ? '1 ile 365 arasında bir gün sayısı girin' : undefined,
                showCancelButton: true,
                confirmButtonText: 'Çek',
                cancelButtonText: 'Vazgeç'
            }).then((s) => {
                if (!s.isConfirmed) return;

                Swal.fire({ title: 'Siparişler çekiliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                $.post('', Object.assign({ action: 'senkron', gun: s.value }, filtreVerisi()), function (res) {
                    Swal.close();
                    // Kanal bazlı sonuç: hangi hesabın neden başarısız olduğu görünür kalmalı
                    let html = '<div>' + kacis(res.message) + '</div>';
                    if (res.kanallar && res.kanallar.length) {
                        html += '<ul class="list-unstyled text-start small mt-3 mb-0">'
                              + res.kanallar.map(k =>
                                    '<li class="py-1 border-bottom"><i class="bi '
                                  + (k.basarili ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger')
                                  + ' me-1"></i><strong>' + kacis(k.kanal) + '</strong>: ' + kacis(k.mesaj) + '</li>'
                                ).join('')
                              + '</ul>';
                    }
                    const kismi = res.success && res.kanallar && res.kanallar.some(k => !k.basarili);
                    Swal.fire({
                        icon: !res.success ? 'error' : (kismi ? 'warning' : 'success'),
                        title: !res.success ? 'Çekilemedi' : (kismi ? 'Kısmen tamamlandı' : 'Tamamlandı'),
                        html: html
                    });
                    if (res.success) { loadDurumlar(); yenileHepsi(); }
                }, 'json').fail(function () {
                    Swal.close();
                    showToast('Sunucu hatası', 'error');
                });
            });
        });

        // ---------------------------------------------------------------
        // Detay
        // ---------------------------------------------------------------
        const detayModal = new bootstrap.Modal('#detayModal');

        $('#tblSiparisler').on('click', '.btn-detay', function () {
            $.post('', { action: 'detay', id: $(this).data('id') }, function (res) {
                if (!res.success) { showToast(kacis(res.message), 'error'); return; }
                const s = res.data.siparis;
                const kalemler = res.data.kalemler || [];

                let adres = '';
                try {
                    const a = JSON.parse(s.PazaryeriSiparisleri_TeslimatAdresi || '{}');
                    adres = typeof a === 'object'
                        ? [a.address, a.neighborhood, a.district, a.city].filter(Boolean).join(', ')
                        : String(a);
                } catch (e) { adres = s.PazaryeriSiparisleri_TeslimatAdresi || ''; }

                let html = '<div class="row g-3 mb-3">'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Sipariş</div>'
                    + '<div class="fw-semibold">' + kacis(s.PazaryeriSiparisleri_SiparisNo || s.PazaryeriSiparisleri_DisId) + '</div>'
                    + '<div class="small">' + kacis(s.PazaryeriSiparisleri_SiparisTarihi || '') + '</div>'
                    + '<div class="small mt-1">' + kacis(durumEtiket(s.PazaryeriSiparisleri_DurumKod) || '-')
                    + (s.PazaryeriSiparisleri_HamDurum ? ' <span class="text-muted">(' + kacis(s.PazaryeriSiparisleri_HamDurum) + ')</span>' : '') + '</div>'
                    + '</div></div>'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Kanal</div>'
                    + '<div class="fw-semibold">' + kacis(s.Pazaryerleri_Ad || '-') + '</div>'
                    + '<div class="small">' + kacis(s.EntegrasyonKanallari_Ad || '') + '</div>'
                    + (s.firma_adi ? '<div class="small text-muted">' + kacis(s.firma_unvan || s.firma_adi) + '</div>' : '')
                    + '</div></div>'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Müşteri</div>'
                    + '<div class="fw-semibold">' + kacis(s.PazaryeriSiparisleri_MusteriAdi || '-') + '</div>'
                    + (adres ? '<div class="adres-satir">' + kacis(adres) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_TCKN ? '<div class="small">TCKN: ' + kacis(s.PazaryeriSiparisleri_TCKN) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_VergiNo ? '<div class="small">VKN: ' + kacis(s.PazaryeriSiparisleri_VergiNo)
                        + (s.PazaryeriSiparisleri_VergiDairesi ? ' · ' + kacis(s.PazaryeriSiparisleri_VergiDairesi) : '') + '</div>' : '')
                    + '</div></div>'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Kargo</div>'
                    + '<div class="fw-semibold">' + kacis(s.PazaryeriSiparisleri_KargoFirmasi || '-') + '</div>'
                    + (s.PazaryeriSiparisleri_KargoTakipNo ? '<div class="small">Takip: ' + kacis(s.PazaryeriSiparisleri_KargoTakipNo) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_Desi ? '<div class="small">Desi: ' + kacis(s.PazaryeriSiparisleri_Desi) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_Faturalar_id ? '<div class="small text-success mt-1"><i class="bi bi-receipt"></i> Fatura #'
                        + kacis(s.PazaryeriSiparisleri_Faturalar_id) + '</div>' : '')
                    + '</div></div></div>';

                if (kalemler.length) {
                    html += '<div class="table-responsive"><table class="table table-sm kalem-tablo"><thead><tr>'
                          + '<th>Ürün</th><th>Barkod / Stok</th><th class="text-center">Adet</th>'
                          + '<th class="text-end">Birim</th><th class="text-end">Toplam</th>'
                          + '<th class="text-end">Komisyon</th></tr></thead><tbody>';
                    kalemler.forEach(function (k) {
                        html += '<tr>'
                            + '<td>' + kacis(k.urun_hizmet_adi || k.PazaryeriSiparisKalemleri_UrunAdi || '-')
                            + (k.urun_hizmet_kodu ? '<br><small class="text-muted">ürün kodu: ' + kacis(k.urun_hizmet_kodu) + '</small>' : '')
                            + (k.urun_hizmet_adi && k.PazaryeriSiparisKalemleri_UrunAdi && k.urun_hizmet_adi !== k.PazaryeriSiparisKalemleri_UrunAdi
                                ? '<br><small class="text-muted">pazaryeri: ' + kacis(k.PazaryeriSiparisKalemleri_UrunAdi) + '</small>' : '')
                            + '</td>'
                            + '<td><small>' + kacis(k.PazaryeriSiparisKalemleri_Barkod || '-') + '<br>'
                            + kacis(k.PazaryeriSiparisKalemleri_StokKodu || '') + '</small></td>'
                            + '<td class="text-center">' + kacis(k.PazaryeriSiparisKalemleri_Miktar) + '</td>'
                            + '<td class="text-end">' + paraFormat(k.PazaryeriSiparisKalemleri_BirimFiyat, s.PazaryeriSiparisleri_ParaBirimi) + '</td>'
                            + '<td class="text-end">' + paraFormat(k.PazaryeriSiparisKalemleri_ToplamTutar, s.PazaryeriSiparisleri_ParaBirimi) + '</td>'
                            + '<td class="text-end">' + (k.PazaryeriSiparisKalemleri_KomisyonOran
                                ? '%' + kacis(Number(k.PazaryeriSiparisKalemleri_KomisyonOran)) : '-') + '</td>'
                            + '</tr>';
                    });
                    html += '</tbody><tfoot><tr class="fw-semibold">'
                          + '<td colspan="4" class="text-end">Sipariş Toplamı</td>'
                          + '<td class="text-end">' + paraFormat(s.PazaryeriSiparisleri_ToplamTutar, s.PazaryeriSiparisleri_ParaBirimi) + '</td>'
                          + '<td></td></tr></tfoot></table></div>';
                } else {
                    html += '<div class="alert alert-light border">Kalem bilgisi yok.</div>';
                }

                $('#detayIcerik').html(html);
                detayModal.show();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        });

        // ---------------------------------------------------------------
        // Paket durumu
        // ---------------------------------------------------------------
        $('#tblSiparisler').on('click', '.btn-durum', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const durum = $(this).data('durum');

            Swal.fire({
                icon: 'question',
                title: 'Paket durumu güncellensin mi?',
                text: 'Pazaryerinde "' + durum + '" olarak işaretlenecek.',
                showCancelButton: true,
                confirmButtonText: 'Güncelle',
                cancelButtonText: 'Vazgeç'
            }).then((s) => {
                if (!s.isConfirmed) return;
                $.post('', { action: 'paketDurum', id: id, yeni_durum: durum }, function (res) {
                    showToast(kacis(res.message || (res.success ? 'Güncellendi' : 'Güncellenemedi')),
                              res.success ? 'success' : 'error');
                    if (res.success) tablo.ajax.reload(null, false);
                }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
            });
        });

        // ---------------------------------------------------------------
        // Kargo etiketi
        // ---------------------------------------------------------------
        const etiketModal = new bootstrap.Modal('#etiketModal');

        // ---------------------------------------------------------------
        // Fatura önizleme / kesim
        // Önizleme kayıt yazmaz (mükellef sorgusu + numara tahmini). Kesimde belge sunucuda
        // siparişten yeniden üretilir; ekrandaki tutarlar yalnız bilgi amaçlıdır.
        let faturaSiparisId = null;
        let faturaVeri = null;
        const $tbKanal = $('#faturaTbKanal');
        if ($tbKanal.hasClass('select2-hidden-accessible')) { $tbKanal.select2('destroy'); }
        initSelect2('#faturaTbKanal', { dropdownParent: $('#faturaModal'), minimumResultsForSearch: 0, allowClear: false });

        function faturaButonlari(durum) {
            // durum: 'yukleniyor' | 'hazir' | 'engelli' | 'belirsiz' | 'kapali'
            // engelli: sipariş başka yerden kesin faturalanmış / kontrol yapılamadı → yalnız taslak
            $('#btnFaturaTaslak').prop('disabled', durum !== 'hazir' && durum !== 'engelli');
            $('#btnFaturaKes').prop('disabled', durum !== 'hazir')
                .attr('title', durum === 'engelli' ? 'Çift fatura kontrolü kesin kesime izin vermiyor' : '');
            $('#btnFaturaTekrar').toggleClass('d-none', durum !== 'belirsiz');
            $tbKanal.prop('disabled', durum === 'yukleniyor');
        }

        function faturaYukle(id, tbKanal) {
            faturaSiparisId = id;
            faturaVeri = null;
            faturaButonlari('yukleniyor');
            $('#faturaBaslik').text('');
            $('#faturaIcerik').html('<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>'
                + 'Önizleme hazırlanıyor (mükellef sorgusu, numara)...</div>');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('faturaModal')).show();

            $.post('', { action: 'faturaOnizle', id: id, tb_kanal: tbKanal || '' }, function (res) {
                if (!res.success) {
                    $('#faturaIcerik').html('<div class="alert alert-danger mb-0"><i class="bi bi-x-octagon me-1"></i>'
                        + kacis(res.message) + '</div>');
                    faturaButonlari('kapali');
                    return;
                }
                faturaVeri = res.data;
                faturaCiz(res.data);
            }, 'json').fail(function () {
                $('#faturaIcerik').html('<div class="alert alert-danger mb-0">Önizleme alınamadı.</div>');
                faturaButonlari('kapali');
            });
        }

        function faturaCiz(v) {
            const o = v.onizleme;
            const efatura = o.belge_turu === 'EFATURA';
            const para = (t) => paraFormat(t, v.para);
            $('#faturaBaslik').text(v.pazaryeri + ' · ' + v.siparis_no);

            // Örnek Holding kanalı seçimi
            $tbKanal.html(v.tb_kanallar.map(k => '<option value="' + k.kanal_id + '">'
                + kacis(k.kanal_adi + (k.test ? ' (TEST)' : '') + (k.varsayilan ? ' · varsayılan' : '')) + '</option>').join(''));
            $tbKanal.val(String(v.tb_kanal)).trigger('change.select2');
            $('#faturaKanalKutu').toggleClass('invisible', v.tb_kanallar.length < 2);

            let html = '<div class="alert ' + (efatura ? 'alert-primary' : 'alert-info') + ' d-flex flex-wrap gap-3 align-items-center">'
                + '<span class="badge ' + (efatura ? 'text-bg-primary' : 'text-bg-info') + ' fs-6">' + (efatura ? 'e-Fatura' : 'e-Arşiv') + '</span>'
                + (o.gib_unvan ? '<span><i class="bi bi-building me-1"></i>GİB: ' + kacis(o.gib_unvan) + '</span>' : '')
                + '<span>Seri <b>' + kacis(o.seri || '-') + '</b> · ' + (o.numara_yontemi === 'sirali' ? 'sıralı' : 'Örnek Holding atar') + '</span>'
                + (o.tahmini_numara ? '<span>Tahmini no <b>' + kacis(o.tahmini_numara) + '</b></span>' : '')
                + '<span class="text-muted small">Şablon: ' + kacis(o.xslt || 'Örnek Holding varsayılanı') + '</span>'
                + (o.kanal && o.kanal.test ? '<span class="badge text-bg-warning">TEST hesabı: kayıt yazılmaz</span>' : '')
                + '</div>';

            const m = v.mevcut;
            let durum = 'hazir';
            if (m && !m.kesinlesmis) {
                if (m.durum === 'GONDERILIYOR') {
                    durum = 'belirsiz';
                    html += '<div class="alert alert-warning"><i class="bi bi-question-octagon me-1"></i>'
                        + 'Önceki gönderimin sonucu bilinmiyor (ETTN <code>' + kacis(m.ettn) + '</code>). '
                        + 'Örnek Holding portalında kontrol edin; fatura yoksa <b>aynı ETTN ile tekrar</b> gönderin '
                        + '(Örnek Holding aynı ETTN\'yi ikinci kez kabul etmez, çift fatura oluşmaz).</div>';
                } else if (m.durum === 'TASLAK') {
                    html += '<div class="alert alert-info"><i class="bi bi-file-earmark me-1"></i>'
                        + 'Bu siparişin Örnek Holding\'de taslağı var (ETTN <code>' + kacis(m.ettn) + '</code>). '
                        + 'Yeniden yükleme / kesin kesim yeni belge oluşturur; eski taslağı Örnek Holding portalından silin.</div>';
                } else if (m.durum === 'HATA') {
                    html += '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>Önceki deneme hata verdi: '
                        + kacis(m.aciklama || '-') + '</div>';
                }
            }
            // Sunucu kesin kesimi bu durumlarda zaten reddeder; buton baştan kapatılır
            const cift = o.cift || {};
            if (durum === 'hazir' && !(o.kanal && o.kanal.test)) {
                if (cift.durum === 'iade') durum = 'kapali';          // tamamen iade: taslak da yok
                else if (cift.durum === 'kesin' || cift.durum === 'hata') durum = 'engelli';
            }
            if (v.uyarilar.length) {
                html += '<div class="alert alert-warning py-2"><ul class="mb-0 ps-3">'
                    + v.uyarilar.map(u => '<li>' + kacis(u) + '</li>').join('') + '</ul></div>';
            }

            const a = v.alici;
            html += '<div class="row g-3 mb-3">'
                + '<div class="col-md-6"><div class="border rounded p-2 h-100"><div class="small text-muted">Satıcı</div>'
                + '<div class="fw-semibold">' + kacis(v.satici.unvan) + '</div><div class="small">VKN ' + kacis(v.satici.vkn || '-') + '</div></div></div>'
                + '<div class="col-md-6"><div class="border rounded p-2 h-100"><div class="small text-muted">Alıcı</div>'
                + '<div class="fw-semibold">' + kacis(a.unvan) + '</div>'
                + '<div class="small">' + kacis(a.kimlik_tur) + ' ' + kacis(a.kimlik) + (a.vergi_dairesi ? ' · VD ' + kacis(a.vergi_dairesi) : '') + '</div>'
                + '<div class="adres-satir">' + kacis([a.adres, a.ilce, a.il].filter(Boolean).join(' / ')) + '</div>'
                + (a.eposta ? '<div class="small text-muted">' + kacis(a.eposta) + '</div>' : '')
                + '</div></div></div>';

            html += '<div class="table-responsive"><table class="table table-sm kalem-tablo mb-0"><thead><tr>'
                + '<th>Kalem</th><th class="text-center">Adet</th><th class="text-end">KDV</th>'
                + '<th class="text-end">Matrah</th><th class="text-end">KDV Tutarı</th><th class="text-end">Toplam</th></tr></thead><tbody>'
                + v.kalemler.map(k => '<tr><td>' + kacis(k.ad) + '</td><td class="text-center">' + kacis(k.miktar) + '</td>'
                    + '<td class="text-end">%' + Number(k.kdv_oran).toLocaleString('tr-TR') + '</td>'
                    + '<td class="text-end">' + para(k.matrah) + '</td><td class="text-end">' + para(k.kdv) + '</td>'
                    + '<td class="text-end tutar">' + para(k.brut) + '</td></tr>').join('')
                + '</tbody><tfoot><tr class="fw-semibold"><td colspan="3" class="text-end small text-muted">'
                + 'tutar yöntemi: ' + kacis(v.yontem) + '</td>'
                + '<td class="text-end">' + para(v.toplam.matrah) + '</td><td class="text-end">' + para(v.toplam.kdv) + '</td>'
                + '<td class="text-end tutar">' + para(v.toplam.genel) + '</td></tr></tfoot></table></div>';

            $('#faturaIcerik').html(html);
            faturaButonlari(durum);
        }

        function faturaGonder(taslak, tekrar) {
            if (!faturaSiparisId || !faturaVeri) return;
            const v = faturaVeri;
            const tur = v.onizleme.belge_turu === 'EFATURA' ? 'e-Fatura' : 'e-Arşiv';

            const gonder = (ciftOnay = false) => {
                faturaButonlari('yukleniyor');
                $('#faturaIcerik').prepend('<div class="alert alert-secondary" id="faturaIsleniyor">'
                    + '<span class="spinner-border spinner-border-sm me-2"></span>Örnek Holding\'ye gönderiliyor...</div>');
                $.post('', {
                    action: 'faturaKes', id: faturaSiparisId, tb_kanal: $tbKanal.val() || '',
                    taslak: taslak ? '1' : '0', belirsiz_tekrar: tekrar ? '1' : '0', cift_onay: ciftOnay ? '1' : '0'
                }, function (res) {
                    // Olası çift fatura: bulunan faturalar gösterilir, kullanıcı onaylarsa yeniden gönderilir
                    if (!res.success && res.onay_gerekli) {
                        $('#faturaIsleniyor').remove();
                        faturaButonlari('hazir');
                        const liste = ((res.cift && res.cift.faturalar) || []).map(f =>
                            '<li><b>' + kacis(f.no || 'numarasız') + '</b> ' + kacis(f.tarih || '') + ' · ' + kacis(f.unvan || '')
                            + (f.tutar !== null && f.tutar !== undefined ? ' · ' + paraFormat(f.tutar, v.para) : '')
                            + ' <span class="text-muted small">(' + kacis(f.kaynak || '') + ')</span></li>').join('');
                        Swal.fire({
                            icon: 'warning',
                            title: 'Olası çift fatura',
                            html: 'Aynı alıcı ve tutarda kesilmiş fatura bulundu:<ul class="text-start small mt-2">' + liste + '</ul>'
                                + '<span class="text-danger small">Bu fatura bu siparişe ait değilse (ör. müşterinin başka siparişi) devam edebilirsiniz.</span>',
                            showCancelButton: true, confirmButtonText: 'Yine de kes', cancelButtonText: 'Vazgeç', confirmButtonColor: '#dc3545'
                        }).then(c => { if (c.isConfirmed) gonder(true); });
                        return;
                    }
                    tablo.ajax.reload(null, false);
                    loadStats();
                    if (res.success) {
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('faturaModal')).hide();
                        showToast(res.message + (res.fatura_no && !/0{9}$/.test(res.fatura_no) ? ' No: ' + res.fatura_no : ''), 'success');
                        if (res.uyari) { showToast(res.uyari, 'warning'); }
                        // Kesin kesimde sunucu fatura linkini pazaryerine de bildirir
                        if (res.bildirim && !res.bildirim.desteklenmiyor) {
                            showToast(res.bildirim.message, res.bildirim.success ? 'success' : 'warning');
                        }
                        return;
                    }
                    // Sonuç (hata / belirsiz) kayda yazıldı; önizleme yeniden alınır
                    showToast(res.message || 'Fatura kesilemedi', 'error');
                    faturaYukle(faturaSiparisId, $tbKanal.val());
                }, 'json').fail(function () {
                    $('#faturaIsleniyor').remove();
                    showToast('Bağlantı hatası; sonucu kontrol etmek için önizlemeyi yeniden açın.', 'error');
                    tablo.ajax.reload(null, false);
                    faturaButonlari('kapali');
                });
            };

            if (taslak && !tekrar) { gonder(); return; }

            Swal.fire({
                icon: 'warning',
                title: tekrar ? 'Aynı ETTN ile tekrar gönderilsin mi?' : tur + ' kesilsin mi?',
                html: (tekrar ? 'Önceki gönderim aynı ETTN ve numarayla tekrarlanır.<br>' : '')
                    + '<b>' + kacis(v.alici.unvan) + '</b><br>' + paraFormat(v.toplam.genel, v.para)
                    + (taslak ? '' : '<br><span class="text-danger small">Fatura GİB\'e gönderilir; geri alınamaz, yalnız iptal edilebilir.</span>'),
                showCancelButton: true,
                confirmButtonText: tekrar ? 'Tekrar gönder' : 'Kesin kes',
                cancelButtonText: 'Vazgeç',
                confirmButtonColor: '#198754'
            }).then(r => { if (r.isConfirmed) gonder(); });
        }

        $('#tblSiparisler').on('click', '.btn-fatura', function () { faturaYukle($(this).data('id')); });

        // ---------------------------------------------------------------
        // Toplu taslak: siparişler sırayla faturaKes (toplu=1 → sunucu yalnız taslak yükler)
        $('#tblSiparisler').on('change', '.chk-siparis', function () {
            const id = String($(this).data('id'));
            const r = tablo.row($(this).closest('tr')).data() || {};
            if (this.checked) {
                seciliSiparisler.set(id, { no: r.PazaryeriSiparisleri_SiparisNo || r.PazaryeriSiparisleri_DisId,
                                           musteri: r.PazaryeriSiparisleri_MusteriAdi || '', pazaryeri: r.Pazaryerleri_Ad || '' });
            } else {
                seciliSiparisler.delete(id);
            }
            seciliGuncelle();
        });
        // scrollX başlığı kopyaladığı için başlık kutusu belge düzeyinde dinlenir
        $(document).on('change', '.chk-tumu', function () {
            const ac = this.checked;
            $('#tblSiparisler .chk-siparis').each(function () {
                if (this.checked !== ac) { $(this).prop('checked', ac).trigger('change'); }
            });
        });
        tablo.on('draw', seciliGuncelle);

        let topluDurdur = false;
        $('#btnTopluDurdur').on('click', function () { topluDurdur = true; $(this).prop('disabled', true).text('Durduruluyor...'); });

        $('#btnTopluTaslak').on('click', function () {
            const liste = Array.from(seciliSiparisler.entries());
            if (!liste.length) return;
            Swal.fire({
                icon: 'question',
                title: liste.length + ' sipariş için taslak yüklensin mi?',
                html: 'Her sipariş için mükellef sorgusu yapılıp e-Fatura ya da e-Arşiv <b>taslağı</b> Örnek Holding\'ye yüklenir.<br>'
                    + '<span class="small text-muted">Taslaklar GİB\'e gitmez ve numara tüketmez; kesinleştirme Örnek Holding portalından ya da tek tek yapılır.</span>',
                showCancelButton: true, confirmButtonText: 'Taslak yükle', cancelButtonText: 'Vazgeç', confirmButtonColor: '#198754'
            }).then(c => { if (c.isConfirmed) topluCalistir(liste, 'taslak'); });
        });

        $('#btnTopluBildir').on('click', function () {
            const liste = Array.from(seciliSiparisler.entries());
            if (!liste.length) return;
            Swal.fire({
                icon: 'question',
                title: liste.length + ' siparişin faturası pazaryerine bildirilsin mi?',
                html: 'Kesinleşmiş faturası olan siparişlerin girişsiz fatura linki pazaryerine gönderilir.<br>'
                    + '<span class="small text-muted">Faturası olmayan, bildirilmiş ya da bildirim desteklemeyen pazaryerindeki siparişler atlanır.</span>',
                showCancelButton: true, confirmButtonText: 'Bildir', cancelButtonText: 'Vazgeç', confirmButtonColor: '#198754'
            }).then(c => { if (c.isConfirmed) topluCalistir(liste, 'bildir'); });
        });

        // tip: 'taslak' → faturaKes (toplu=1, sunucu yalnız taslak yükler) | 'bildir' → faturaBildir
        function topluCalistir(liste, tip) {
            topluDurdur = false;
            $('#topluBaslik').html(tip === 'bildir'
                ? '<i class="bi bi-send"></i> Toplu Fatura Bildirimi'
                : '<i class="bi bi-files"></i> Toplu Taslak Yükleme');
            let basarili = 0, hatali = 0;
            $('#topluSonuc').html(liste.map(([id, s]) => '<tr id="toplu' + id + '"><td>' + kacis(s.pazaryeri) + ' · <b>' + kacis(s.no)
                + '</b></td><td>' + kacis(s.musteri) + '</td><td class="text-muted small">bekliyor</td></tr>').join(''));
            $('#btnTopluDurdur').prop('disabled', false).text('Durdur').removeClass('d-none');
            $('#btnTopluKapat').prop('disabled', true);
            const ilerleme = (i) => {
                const yuzde = Math.round(i * 100 / liste.length);
                $('#topluBar').css('width', yuzde + '%').text(yuzde + '%');
                $('#topluOzet').text(i + ' / ' + liste.length + ' · ' + basarili + ' başarılı · ' + hatali + ' hatalı');
            };
            ilerleme(0);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('topluModal')).show();

            const bitir = () => {
                $('#topluBar').removeClass('progress-bar-animated');
                $('#btnTopluDurdur').addClass('d-none');
                $('#btnTopluKapat').prop('disabled', false);
                seciliSiparisler.clear();
                tablo.ajax.reload(null, false);
                loadStats();
                showToast(basarili + (tip === 'bildir' ? ' fatura bildirildi' : ' taslak yüklendi') + (hatali ? ', ' + hatali + ' hatalı' : '')
                    + (topluDurdur ? ' (durduruldu)' : ''), hatali ? 'warning' : 'success');
            };

            const sonraki = (i) => {
                if (i >= liste.length || topluDurdur) { bitir(); return; }
                const [id] = liste[i];
                const $hucre = $('#toplu' + id + ' td:last');
                $hucre.html('<span class="spinner-border spinner-border-sm"></span>');
                const istek = tip === 'bildir'
                    ? { action: 'faturaBildir', id: id }
                    : { action: 'faturaKes', id: id, toplu: '1', taslak: '1' };
                $.post('', istek, function (res) {
                    if (res.success && tip === 'bildir') {
                        basarili++;
                        $hucre.html('<span class="badge text-bg-success">bildirildi</span> <small class="text-muted">' + kacis(res.message || '') + '</small>');
                    } else if (res.success) {
                        basarili++;
                        $hucre.html('<span class="badge text-bg-info">' + (res.belge_turu === 'EARSIV' ? 'e-Arşiv' : 'e-Fatura')
                            + ' taslak</span>' + (res.uyari ? ' <small class="text-warning">' + kacis(res.uyari) + '</small>' : ''));
                    } else {
                        hatali++;
                        $hucre.html('<span class="text-danger small"><i class="bi bi-x-circle me-1"></i>' + kacis(res.message || 'Hata') + '</span>');
                    }
                }, 'json').fail(function () {
                    hatali++;
                    $hucre.html('<span class="text-danger small">Bağlantı hatası; sonucu önizlemeden kontrol edin</span>');
                }).always(function () { ilerleme(i + 1); sonraki(i + 1); });
            };
            sonraki(0);
        }
        $tbKanal.on('change', function () {
            if (faturaVeri && String($(this).val()) !== String(faturaVeri.tb_kanal)) faturaYukle(faturaSiparisId, $(this).val());
        });
        $('#btnFaturaTaslak').on('click', () => faturaGonder(true, false));
        $('#btnFaturaKes').on('click', () => faturaGonder(false, false));
        // Tekrar: önceki denemenin türü korunur; taslak mı kesin mi bilinmediği için kesin gönderilir
        $('#btnFaturaTekrar').on('click', () => faturaGonder(false, true));

        $('#tblSiparisler').on('click', '.btn-etiket', function () {
            const btn = $(this).prop('disabled', true);
            $.post('', { action: 'kargoEtiketi', id: btn.data('id') }, function (res) {
                if (!res.success) {
                    Swal.fire({ icon: 'error', title: 'Etiket alınamadı', text: res.message });
                    return;
                }
                $('#zplIcerik').text(res.zpl || '');
                etiketModal.show();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'))
              .always(() => btn.prop('disabled', false));
        });

        $('#btnZplKopyala').on('click', function () {
            navigator.clipboard.writeText($('#zplIcerik').text()).then(
                () => showToast('ZPL kopyalandı', 'success'),
                () => showToast('Kopyalanamadı', 'error')
            );
        });

        // ---------------------------------------------------------------
        // InfoBox sayımları tablo yüklendikten sonra
        tablo.one('draw', loadStats);
        loadDurumlar();
    });
    </script>
</body>
</html>
