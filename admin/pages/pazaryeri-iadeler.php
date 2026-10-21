<?php
/**
 * Admin Panel - Pazaryeri İadeleri
 *
 * Pazaryeri kanallarındaki iade/talep kayıtlarını listeler. İade kalemleri JSON
 * olarak saklanır (pazaryerleri farklı kırılımlar döndürdüğü için) ve detay
 * penceresinde çözülerek gösterilir.
 *
 * Kapsam: iade → kanal (EntegrasyonKanallari) → EntegrasyonKanallari_Firmalar_id → Firmalar.
 * Kullanıcının firma kısıtı (PageAuth::firmaKisiti) her sorguya kanal id listesi olarak uygulanır.
 *
 * Hareket tablosu → DataTables SERVER-SIDE, iki aşamalı sorgu.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
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

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri İadeleri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

/** Sıralama kolonu istemciden gelen değerle doğrudan sorguya girmez */
const SIRALAMA_KOLONLARI = [
    2 => 'i.PazaryeriIadeler_SiparisNo',
    3 => 'i.PazaryeriIadeler_MusteriAdi',
    4 => 'i.PazaryeriIadeler_Sebep',
    5 => 'i.PazaryeriIadeler_Tutar',
    6 => 'i.PazaryeriIadeler_DurumKod',
    7 => 'i.PazaryeriIadeler_IadeTarihi',
];

/**
 * Kapsamdaki kanal id'leri (pazaryerine bağlı kanallar).
 * Pasif firmanın kanalları hiçbir kapsamda yer almaz; firmasız kanal görünür.
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
 * Liste ve istatistik için ortak WHERE koşulu (alias: i).
 * İstatistiklerde durum filtresi uygulanmaz; kutular durum kırılımını gösterir.
 */
function iadeKosulu(array $girdi, $kisitFirmaId, $durumDahil = true)
{
    $idler = kapsamKanallari(
        (int)($girdi['firma_id'] ?? 0),
        (int)($girdi['pazaryeri_id'] ?? 0),
        (int)($girdi['kanal'] ?? 0),
        $kisitFirmaId
    );

    $kosul  = " WHERE " . kanalInSql($idler, 'i.PazaryeriIadeler_EntegrasyonKanallari_id')
            . " AND i.PazaryeriIadeler_Durum = 1 ";
    $params = [];

    $ara = trim($girdi['ara'] ?? '');
    if ($ara !== '') {
        $kosul .= " AND (i.PazaryeriIadeler_SiparisNo LIKE ?
                      OR i.PazaryeriIadeler_PaketNo LIKE ?
                      OR i.PazaryeriIadeler_MusteriAdi LIKE ?
                      OR i.PazaryeriIadeler_Sebep LIKE ?) ";
        $like = '%' . $ara . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $durum = trim($girdi['durum'] ?? '');
    if ($durumDahil && $durum !== '') {
        $kosul .= " AND i.PazaryeriIadeler_DurumKod = ? ";
        $params[] = $durum;
    }
    if (($bas = tarihGecerli($girdi['bas_tarih'] ?? '')) !== '') {
        $kosul .= " AND i.PazaryeriIadeler_IadeTarihi >= ? ";
        $params[] = $bas;
    }
    if (($bit = tarihGecerli($girdi['bit_tarih'] ?? '')) !== '') {
        $kosul .= " AND i.PazaryeriIadeler_IadeTarihi < DATEADD(DAY, 1, CAST(? AS DATE)) ";
        $params[] = $bit;
    }

    return [$kosul, $params];
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
            // InfoBox: seçili filtre / tarih aralığında özet (durum filtresi hariç)
            case 'stats':
                [$kosul, $params] = iadeKosulu($_POST, $kisitFirmaId, false);
                $s = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN i.PazaryeriIadeler_DurumKod = 'acik'      THEN 1 ELSE 0 END) AS acik,
                        SUM(CASE WHEN i.PazaryeriIadeler_DurumKod = 'onaylandi' THEN 1 ELSE 0 END) AS onaylandi,
                        SUM(ISNULL(i.PazaryeriIadeler_Tutar, 0))                                    AS tutar
                    FROM dbo.PazaryeriIadeler i
                    $kosul
                ", $params);

                echo json_encode(['success' => true, 'data' => [
                    'toplam'    => (int)($s['toplam'] ?? 0),
                    'acik'      => (int)($s['acik'] ?? 0),
                    'onaylandi' => (int)($s['onaylandi'] ?? 0),
                    'tutar'     => (float)($s['tutar'] ?? 0),
                ]], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'list':
                $draw = (int)($_POST['draw'] ?? 0);

                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) { $length = 25; }

                $siraIdx = (int)($_POST['order'][0]['column'] ?? 7);
                $siraKol = SIRALAMA_KOLONLARI[$siraIdx] ?? 'i.PazaryeriIadeler_IadeTarihi';
                $siraYon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
                $orderSql = "$siraKol $siraYon, i.PazaryeriIadeler_id DESC";

                [$kosul, $params] = iadeKosulu($_POST, $kisitFirmaId);

                // Toplam: JOIN'siz, tek ana tablodan (yalnız firma kısıtı)
                $toplam = $db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.PazaryeriIadeler
                     WHERE " . kanalInSql(kapsamKanallari(0, 0, 0, $kisitFirmaId), 'PazaryeriIadeler_EntegrasyonKanallari_id') . "
                       AND PazaryeriIadeler_Durum = 1"
                );
                $filtreli = $db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.PazaryeriIadeler i " . $kosul,
                    $params
                );

                // 1. aşama ucuz CTE (yalnız ID), 2. aşamada kanal bilgisi ve sipariş eşleşmesi yalnız bu satırlara
                $satirlar = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT i.PazaryeriIadeler_id
                        FROM dbo.PazaryeriIadeler i
                        $kosul
                        ORDER BY $orderSql
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        i.PazaryeriIadeler_id,
                        i.PazaryeriIadeler_EntegrasyonKanallari_id,
                        i.PazaryeriIadeler_DisId,
                        i.PazaryeriIadeler_SiparisNo,
                        i.PazaryeriIadeler_PaketNo,
                        i.PazaryeriIadeler_DurumKod,
                        i.PazaryeriIadeler_HamDurum,
                        i.PazaryeriIadeler_Sebep,
                        LEFT(i.PazaryeriIadeler_Aciklama, 120)                     AS PazaryeriIadeler_Aciklama,
                        i.PazaryeriIadeler_MusteriAdi,
                        i.PazaryeriIadeler_Tutar,
                        CONVERT(VARCHAR(16), i.PazaryeriIadeler_IadeTarihi, 120)    AS PazaryeriIadeler_IadeTarihi,
                        k.EntegrasyonKanallari_Ad,
                        p.Pazaryerleri_Ad,
                        p.Pazaryerleri_Renk,
                        f.firma_adi,
                        sp.PazaryeriSiparisleri_id
                    FROM sayfa sf
                    INNER JOIN dbo.PazaryeriIadeler i
                            ON i.PazaryeriIadeler_id = sf.PazaryeriIadeler_id
                    LEFT JOIN dbo.EntegrasyonKanallari k
                            ON k.EntegrasyonKanallari_id = i.PazaryeriIadeler_EntegrasyonKanallari_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p
                            ON p.Pazaryerleri_id = i.PazaryeriIadeler_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f
                            ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    OUTER APPLY (
                        SELECT TOP 1 s2.PazaryeriSiparisleri_id
                        FROM dbo.PazaryeriSiparisleri s2
                        WHERE s2.PazaryeriSiparisleri_EntegrasyonKanallari_id = i.PazaryeriIadeler_EntegrasyonKanallari_id
                          AND s2.PazaryeriSiparisleri_SiparisNo = i.PazaryeriIadeler_SiparisNo
                          AND s2.PazaryeriSiparisleri_Durum = 1
                    ) sp
                    ORDER BY $orderSql
                ", array_merge($params, [$start, $length]));

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => (int)($toplam['adet'] ?? 0),
                    'recordsFiltered' => (int)($filtreli['adet'] ?? 0),
                    'data'            => $satirlar
                ], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'detay':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("
                    SELECT
                        i.PazaryeriIadeler_id,
                        i.PazaryeriIadeler_EntegrasyonKanallari_id,
                        i.PazaryeriIadeler_DisId,
                        i.PazaryeriIadeler_SiparisNo,
                        i.PazaryeriIadeler_PaketNo,
                        i.PazaryeriIadeler_DurumKod,
                        i.PazaryeriIadeler_HamDurum,
                        i.PazaryeriIadeler_Sebep,
                        i.PazaryeriIadeler_Aciklama,
                        i.PazaryeriIadeler_MusteriAdi,
                        i.PazaryeriIadeler_Tutar,
                        CONVERT(VARCHAR(16), i.PazaryeriIadeler_IadeTarihi, 120) AS PazaryeriIadeler_IadeTarihi,
                        i.PazaryeriIadeler_Urunler,
                        k.EntegrasyonKanallari_Ad,
                        p.Pazaryerleri_Ad,
                        f.firma_adi,
                        f.firma_unvan
                    FROM dbo.PazaryeriIadeler i
                    LEFT JOIN dbo.EntegrasyonKanallari k ON k.EntegrasyonKanallari_id = i.PazaryeriIadeler_EntegrasyonKanallari_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p   ON p.Pazaryerleri_id = i.PazaryeriIadeler_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f             ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    WHERE i.PazaryeriIadeler_id = ? AND i.PazaryeriIadeler_Durum = 1
                ", [$id]);

                // Kayıt kullanıcının kapsamındaki bir kanala ait olmalı (firma kısıtı + pasif firma)
                $kanalId = (int)($row['PazaryeriIadeler_EntegrasyonKanallari_id'] ?? 0);
                if (!$row || !in_array($kanalId, kapsamKanallari(0, 0, $kanalId, $kisitFirmaId), true)) {
                    echo json_encode(['success' => false, 'message' => 'İade bulunamadı.'], $jsonBayrak);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => $row], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Filtre: tabloda geçen durum kodları (etiketler arayüzde çözülür)
            case 'durumlar':
                $idler = kapsamKanallari(0, 0, 0, $kisitFirmaId);
                $liste = $db->fetchAll("
                    SELECT DISTINCT PazaryeriIadeler_DurumKod AS kod
                    FROM dbo.PazaryeriIadeler
                    WHERE " . kanalInSql($idler, 'PazaryeriIadeler_EntegrasyonKanallari_id') . "
                      AND PazaryeriIadeler_Durum = 1
                      AND PazaryeriIadeler_DurumKod IS NOT NULL
                    ORDER BY PazaryeriIadeler_DurumKod
                ");
                echo json_encode(['success' => true, 'data' => array_column($liste, 'kod')], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // İadeleri pazaryerinden çek (seçimdeki iade destekleyen tüm kanallar)
            case 'senkron':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'İade çekmek için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }
                set_time_limit(300);

                $gun = (int)($_POST['gun'] ?? 30);
                if ($gun <= 0 || $gun > 365) { $gun = 30; }

                $firmaId     = (int)($_POST['firma_id'] ?? 0);
                $pazaryeriId = (int)($_POST['pazaryeri_id'] ?? 0);
                $kanalId     = (int)($_POST['kanal'] ?? 0);

                $hedefler = array_values(array_filter(
                    PazaryeriFabrika::kanallar(null, $firmaId > 0 ? $firmaId : null, $kisitFirmaId),
                    fn($k) => ($kanalId <= 0 || (int)$k['EntegrasyonKanallari_id'] === $kanalId)
                           && ($pazaryeriId <= 0 || (int)$k['Pazaryerleri_id'] === $pazaryeriId)
                           && !empty($k['sinifVar'])
                           && (int)($k['Pazaryerleri_Durum'] ?? 0) === 1
                           && !empty($k['yetenekler']['iade'])
                ));

                if (empty($hedefler)) {
                    echo json_encode(['success' => false,
                        'message' => 'Seçimde iade çekmeyi destekleyen kanal yok.'], $jsonBayrak);
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
                        $sonuc = $api->iadeleriSenkronla($gun);
                        if (!empty($sonuc['basarili'])) {
                            $basarili++;
                            $toplam += (int)($sonuc['adet'] ?? 0);
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => true,
                                'mesaj' => (int)($sonuc['adet'] ?? 0) . ' iade'];
                        } else {
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => false,
                                'mesaj' => $sonuc['hata'] ?? 'İade çekilemedi.'];
                        }
                    } catch (Throwable $e) {
                        error_log('pazaryeri-iadeler senkron: ' . $e->getMessage());
                        $sonuclar[] = ['kanal' => $etiket, 'basarili' => false, 'mesaj' => 'İşlem hatası'];
                    }
                }

                echo json_encode([
                    'success'  => $basarili > 0,
                    'message'  => $toplam . ' iade işlendi (' . $basarili . '/' . count($hedefler) . ' kanal).',
                    'kanallar' => $sonuclar
                ], $jsonBayrak);
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
        error_log('pazaryeri-iadeler.php: ' . $e->getMessage());
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
        .sebep-metni { font-size: .8rem; }
        .aciklama-kutu {
            background: #fff8f8; border-left: 3px solid #dc3545;
            padding: .5rem .75rem; font-size: .85rem; border-radius: .25rem;
        }
        #tblIadeler td { vertical-align: middle; white-space: nowrap; }
        #tblIadeler td.metin { white-space: normal; min-width: 200px; }
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
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-arrow-return-left"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam İade</span>
                                    <span class="info-box-number" id="ibToplam">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Açık</span>
                                    <span class="info-box-number" id="ibAcik">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Onaylanan</span>
                                    <span class="info-box-number" id="ibOnay">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-cash-stack"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İade Tutarı</span>
                                    <span class="info-box-number" id="ibTutar">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

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
                                               placeholder="Sipariş no, müşteri, sebep...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Başlangıç</label>
                                        <input type="date" class="form-control form-control-sm" id="fBas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Bitiş</label>
                                        <input type="date" class="form-control form-control-sm" id="fBit">
                                    </div>
                                    <div class="col-md-8 d-flex gap-2 justify-content-end">
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
                            <h3 class="card-title flex-grow-1 mb-0">İade Listesi</h3>
                            <?php if ($duzenleyebilir): ?>
                            <button class="btn btn-sm btn-primary" id="btnSenkron">
                                <i class="bi bi-cloud-download me-1"></i> İadeleri Çek
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <table class="table table-hover table-sm align-middle w-100" id="tblIadeler">
                                <thead>
                                    <tr>
                                        <th style="width:28px"></th>
                                        <th>Kanal</th>
                                        <th>Sipariş No</th>
                                        <th>Müşteri</th>
                                        <th>Sebep</th>
                                        <th class="text-end">Tutar</th>
                                        <th>Durum</th>
                                        <th>Tarih</th>
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

    <div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-return-left"></i> İade Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="detayIcerik"></div>
                <div class="modal-footer">
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

        const tumKanallar = <?= json_encode($kanallar, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

        const kacis = (s) => $('<div>').text(s ?? '').html();
        const tarihStr = (d) => d.toISOString().slice(0, 10);
        const para = (t) => t !== null && t !== undefined && t !== ''
            ? Number(t).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺' : '-';

        // Ortak katmanın normalize iade durum kodları (PazaryeriApi alt sınıfları üretir)
        const DURUM = {
            acik:       ['text-bg-warning',   'Açık'],
            onaylandi:  ['text-bg-success',   'Onaylandı'],
            reddedildi: ['text-bg-danger',    'Reddedildi'],
            iptal:      ['text-bg-secondary', 'İptal'],
            diger:      ['text-bg-light',     'Diğer']
        };
        const durumEtiket = (kod) => (DURUM[kod] || [null, kod])[1];

        // custom.js tüm .form-select kutularına "Seçiniz..." yer tutuculu Select2 kurar;
        // kendi etiketleriyle yeniden kurulur.
        [['#fFirma', 'Tüm firmalar'], ['#fPazaryeri', 'Tümü'], ['#fKanal', 'Tümü'], ['#fDurum', 'Tümü']]
            .forEach(([sec, etiket]) => {
                const $s = $(sec);
                if ($s.hasClass('select2-hidden-accessible')) { $s.select2('destroy'); }
                initSelect2(sec, { placeholder: etiket, minimumResultsForSearch: 0 });
            });

        const iadeDestekler = (k) => !!(k && k.sinifVar && String(k.Pazaryerleri_Durum) === '1'
                                        && k.yetenekler && k.yetenekler.iade);

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

        // İade desteklemeyen seçimde çekme butonu kapalı
        function senkronButonu() {
            const id = $('#fKanal').val();
            const destek = id
                ? iadeDestekler(tumKanallar.find(k => String(k.EntegrasyonKanallari_id) === String(id)))
                : secimKanallari().some(iadeDestekler);
            $('#btnSenkron').prop('disabled', !destek)
                .attr('title', destek ? '' : 'Seçimde iade çekmeyi destekleyen kanal yok');
        }

        function filtreVerisi() {
            return {
                firma_id:     $('#fFirma').val() || '',
                pazaryeri_id: $('#fPazaryeri').val() || '',
                kanal:        $('#fKanal').val() || '',
                durum:        $('#fDurum').val() || '',
                ara:          $('#fAra').val(),
                bas_tarih:    $('#fBas').val(),
                bit_tarih:    $('#fBit').val()
            };
        }

        function loadStats() {
            $.post('', Object.assign({ action: 'stats' }, filtreVerisi()), function (res) {
                if (!res.success) return;
                $('#ibToplam').text(Number(res.data.toplam).toLocaleString('tr-TR'));
                $('#ibAcik').text(Number(res.data.acik).toLocaleString('tr-TR'));
                $('#ibOnay').text(Number(res.data.onaylandi).toLocaleString('tr-TR'));
                $('#ibTutar').text(para(res.data.tutar));
            }, 'json');
        }

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
            // Tek kanal seçiliyken Kanal kolonu tekrar eden bilgi olur
            tablo.column(1).visible(!$('#fKanal').val(), false);
            tablo.columns.adjust();
            tablo.ajax.reload();
            tablo.one('draw', loadStats);
        }

        // İlk istekten önce filtre değerleri hazır olmalı (tarih aralığı + kanal listesi)
        varsayilanTarihler();
        kanallariDoldur(false);

        const tablo = $('#tblIadeler').DataTable({
            serverSide: true,
            processing: true,
            scrollX: true,
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
                    data: 'PazaryeriIadeler_id', orderable: false, className: 'text-center',
                    render: (d) => '<button class="btn btn-sm btn-link p-0 btn-detay" data-id="' + d + '" title="Detay">'
                                 + '<i class="bi bi-chevron-expand"></i></button>'
                },
                {
                    data: 'Pazaryerleri_Ad', orderable: false,
                    render: (d, t, r) => '<span class="badge" style="background:' + kacis(r.Pazaryerleri_Renk || '#6c757d') + '">'
                                       + kacis(d || '-') + '</span>'
                                       + '<br><small class="text-muted">' + kacis(r.firma_adi || r.EntegrasyonKanallari_Ad || '') + '</small>'
                },
                {
                    data: 'PazaryeriIadeler_SiparisNo',
                    render: (d, t, r) => {
                        let html = '<span class="fw-semibold">' + kacis(d || '-') + '</span>';
                        if (r.PazaryeriSiparisleri_id) {
                            html += ' <i class="bi bi-link-45deg text-success" title="Sipariş kaydı eşleşti"></i>';
                        }
                        if (r.PazaryeriIadeler_PaketNo && r.PazaryeriIadeler_PaketNo !== d) {
                            html += '<br><small class="text-muted">paket: ' + kacis(r.PazaryeriIadeler_PaketNo) + '</small>';
                        }
                        return html;
                    }
                },
                { data: 'PazaryeriIadeler_MusteriAdi', render: (d) => kacis(d || '-') },
                {
                    data: 'PazaryeriIadeler_Sebep', className: 'metin',
                    render: (d, t, r) => {
                        let html = '<span class="sebep-metni">' + kacis(d || '-') + '</span>';
                        if (r.PazaryeriIadeler_Aciklama) {
                            const a = String(r.PazaryeriIadeler_Aciklama);
                            html += '<br><small class="text-muted">'
                                  + kacis(a.length > 60 ? a.substring(0, 60) + '…' : a) + '</small>';
                        }
                        return html;
                    }
                },
                { data: 'PazaryeriIadeler_Tutar', className: 'text-end tutar', render: (d) => para(d) },
                {
                    data: 'PazaryeriIadeler_DurumKod',
                    render: (d, t, r) => {
                        const h = DURUM[d] || ['text-bg-light', d || '-'];
                        let html = '<span class="badge ' + h[0] + ' durum-rozet">' + kacis(h[1]) + '</span>';
                        if (r.PazaryeriIadeler_HamDurum) {
                            html += '<br><small class="text-muted">' + kacis(r.PazaryeriIadeler_HamDurum) + '</small>';
                        }
                        return html;
                    }
                },
                { data: 'PazaryeriIadeler_IadeTarihi', render: (d) => d ? kacis(d) : '-' }
            ],
            order: [[7, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
        });

        // ---------------------------------------------------------------
        $('#fFirma, #fPazaryeri').on('change', () => kanallariDoldur(true));
        $('#fKanal').on('change', function () { senkronButonu(); yenileHepsi(); });
        $('#fDurum, #fBas, #fBit').on('change', yenileHepsi);
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
            $('#fPazaryeri, #fDurum').val('').trigger('change.select2');
            kanallariDoldur(true);
            showToast('Filtreler temizlendi', 'info');
        });

        // ---------------------------------------------------------------
        // İade çekme (seçimdeki destekleyen tüm kanallar)
        // ---------------------------------------------------------------
        $('#btnSenkron').on('click', function () {
            if ($(this).prop('disabled')) return;
            const kanalId = $('#fKanal').val();

            Swal.fire({
                title: kanalId ? 'İadeleri çek' : 'Seçimdeki tüm kanallardan iadeleri çek',
                input: 'number',
                inputLabel: 'Kaç günlük iade çekilsin?',
                inputValue: 30,
                inputAttributes: { min: 1, max: 365, step: 1 },
                inputValidator: (v) => (!v || v < 1 || v > 365) ? '1 ile 365 arasında bir gün sayısı girin' : undefined,
                showCancelButton: true,
                confirmButtonText: 'Çek',
                cancelButtonText: 'Vazgeç'
            }).then((s) => {
                if (!s.isConfirmed) return;
                Swal.fire({ title: 'İadeler çekiliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                $.post('', Object.assign({ action: 'senkron', gun: s.value }, filtreVerisi()), function (res) {
                    Swal.close();
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

        $('#tblIadeler').on('click', '.btn-detay', function () {
            $.post('', { action: 'detay', id: $(this).data('id') }, function (res) {
                if (!res.success) { showToast(kacis(res.message), 'error'); return; }
                const i = res.data;

                let html = '<div class="row g-3 mb-3">'
                    + '<div class="col-md-4"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Sipariş / Paket</div>'
                    + '<div class="fw-semibold">' + kacis(i.PazaryeriIadeler_SiparisNo || '-') + '</div>'
                    + (i.PazaryeriIadeler_PaketNo ? '<div class="small">paket: ' + kacis(i.PazaryeriIadeler_PaketNo) + '</div>' : '')
                    + '<div class="small text-muted mt-1">' + kacis(i.PazaryeriIadeler_IadeTarihi || '') + '</div>'
                    + '<div class="small mt-1">' + kacis(durumEtiket(i.PazaryeriIadeler_DurumKod) || '-')
                    + (i.PazaryeriIadeler_HamDurum ? ' <span class="text-muted">(' + kacis(i.PazaryeriIadeler_HamDurum) + ')</span>' : '') + '</div>'
                    + '</div></div>'
                    + '<div class="col-md-4"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Kanal</div>'
                    + '<div class="fw-semibold">' + kacis(i.Pazaryerleri_Ad || '-') + '</div>'
                    + '<div class="small">' + kacis(i.EntegrasyonKanallari_Ad || '') + '</div>'
                    + (i.firma_adi ? '<div class="small text-muted">' + kacis(i.firma_unvan || i.firma_adi) + '</div>' : '')
                    + '</div></div>'
                    + '<div class="col-md-4"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Müşteri</div>'
                    + '<div class="fw-semibold">' + kacis(i.PazaryeriIadeler_MusteriAdi || '-') + '</div>'
                    + '<div class="small">Tutar: ' + para(i.PazaryeriIadeler_Tutar) + '</div>'
                    + '</div></div></div>';

                if (i.PazaryeriIadeler_Sebep || i.PazaryeriIadeler_Aciklama) {
                    html += '<div class="aciklama-kutu mb-3">'
                          + '<div class="fw-semibold">' + kacis(i.PazaryeriIadeler_Sebep || 'İade sebebi') + '</div>'
                          + (i.PazaryeriIadeler_Aciklama ? '<div>' + kacis(i.PazaryeriIadeler_Aciklama) + '</div>' : '')
                          + '</div>';
                }

                // İade kalemleri JSON olarak saklanır
                let kalemler = [];
                try { kalemler = JSON.parse(i.PazaryeriIadeler_Urunler || '[]'); } catch (e) { kalemler = []; }
                if (!Array.isArray(kalemler)) kalemler = [];

                if (kalemler.length) {
                    html += '<div class="table-responsive"><table class="table table-sm"><thead><tr>'
                          + '<th>Ürün</th><th>Barkod</th><th class="text-center">Adet</th>'
                          + '<th class="text-end">Fiyat</th><th>Sebep</th></tr></thead><tbody>';
                    kalemler.forEach(function (k) {
                        html += '<tr>'
                              + '<td>' + kacis(k.urunAdi || k.adi || '-') + '</td>'
                              + '<td><small>' + kacis(k.barkod || '-') + '</small></td>'
                              + '<td class="text-center">' + kacis(k.miktar ?? 1) + '</td>'
                              + '<td class="text-end">' + para(k.fiyat) + '</td>'
                              + '<td><small>' + kacis(k.sebep || k.neden || '-') + '</small></td>'
                              + '</tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<div class="alert alert-light border mb-0">Kalem bilgisi yok.</div>';
                }

                $('#detayIcerik').html(html);
                detayModal.show();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        });

        // ---------------------------------------------------------------
        // InfoBox sayımları tablo yüklendikten sonra
        tablo.one('draw', loadStats);
        loadDurumlar();
    });
    </script>
</body>
</html>
