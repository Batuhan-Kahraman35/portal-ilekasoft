<?php
/**
 * Admin Panel - PDKS Giriş/Çıkış Kayıtları
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

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
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'PDKS Giriş/Çıkış Kayıtları';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Veri kapsamı: sube_gor yetkisi olmayan kullanıcı yalnızca kendisini ve
// kendisine bağlı personeli (kullanici_ust_id hiyerarşisi) görür. Şube bilgisi
// yalnızca QR/geofence için kullanılır, görünürlüğü etkilemez.
$kapsamKisitli = !$pagePermissions['can_view_sube'];

// Kendisi + tüm alt kademeler. MAXRECURSION, hatalı veriyle oluşabilecek
// döngüyü (A -> B -> A) keser.
$ekipIdler = [];
if ($kapsamKisitli) {
    $ekip = $db->fetchAll("
        WITH Ekip AS (
            SELECT kullanici_id
            FROM kullanicilar
            WHERE kullanici_id = ?
            UNION ALL
            SELECT k.kullanici_id
            FROM kullanicilar k
            INNER JOIN Ekip e ON k.kullanici_ust_id = e.kullanici_id
        )
        SELECT kullanici_id FROM Ekip
        OPTION (MAXRECURSION 20)
    ", [$user['kullanici_id']]);
    $ekipIdler = array_column($ekip, 'kullanici_id');
}

/**
 * Kapsam koşulunu SQL'e ekler. $kolon, sorguda personeli gösteren kullanıcı
 * kimliği kolonudur (örn. "g.kullanici_id").
 */
$kapsamKosulu = function (array &$params, string $kolon = 'g.kullanici_id') use ($kapsamKisitli, $ekipIdler) {
    if (!$kapsamKisitli) {
        return '';
    }
    if (!$ekipIdler) {
        return ' AND 1 = 0';
    }
    foreach ($ekipIdler as $id) {
        $params[] = $id;
    }
    return " AND $kolon IN (" . implode(',', array_fill(0, count($ekipIdler), '?')) . ")";
};

// Şube listesi filtre amaçlı; kısıtlı kullanıcıya yalnızca ekibinin okutma
// yaptığı şubeler gösterilir.
$subeSql = "SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1";
$subeParams = [];
if ($kapsamKisitli) {
    $subeSql .= " AND sube_id IN (
                      SELECT DISTINCT g.sube_id FROM Personel_GirisCikis g
                      WHERE 1=1" . $kapsamKosulu($subeParams) . "
                  )";
}
$subeler = $db->fetchAll($subeSql . " ORDER BY sube_adi", $subeParams);

$personelSql = "SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad as ad_soyad
                FROM kullanicilar
                WHERE kullanici_durum = 1";
$personelParams = [];
$personelSql .= $kapsamKosulu($personelParams, 'kullanici_id');
$personeller = $db->fetchAll($personelSql . " ORDER BY kullanici_ad", $personelParams);

/**
 * Personel_GirisCikis.tip değerleri. Mesai QR'ı giriş/çıkış, mola QR'ı mola
 * başlangıç/bitiş kaydı üretir; kolon CK_PersonelGirisCikis_Tip kısıtı ile
 * bu dört değerle sınırlıdır. Etiket, rozet rengi ve ikon tek yerden okunsun
 * diye filtre, tablo, detay modalı ve Excel bu diziyi kullanır.
 */
$hareketTipleri = [
    'giris'      => ['ad' => 'Giriş',      'renk' => '#198754', 'ikon' => 'bi-box-arrow-in-right'],
    'mola_giris' => ['ad' => 'Mola',       'renk' => '#fd7e14', 'ikon' => 'bi-cup-hot'],
    'mola_cikis' => ['ad' => 'Mola Bitiş', 'renk' => '#0dcaf0', 'ikon' => 'bi-cup'],
    'cikis'      => ['ad' => 'Çıkış',      'renk' => '#dc3545', 'ikon' => 'bi-box-arrow-right'],
];

/**
 * Liste sorgusunu kurar. Hem ekran listesi hem Excel aktarımı aynı filtreleri
 * kullansın diye tek yerde toplandı.
 */
$listeSorgusu = function (array $filtre) use ($kapsamKosulu) {
    $sql = "SELECT
                g.kayit_id, g.kullanici_id, g.sube_id, g.tip,
                CONVERT(VARCHAR(19), g.zaman, 120) as zaman,
                g.enlem, g.boylam, g.qr_kod, g.cihaz_modeli, g.cihaz_id, g.ip_adresi,
                k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                s.sube_adi
            FROM Personel_GirisCikis g
            LEFT JOIN kullanicilar k ON g.kullanici_id = k.kullanici_id
            LEFT JOIN Subeler s ON g.sube_id = s.sube_id
            WHERE 1=1";
    $params = [];
    $sql .= $kapsamKosulu($params);

    if (($filtre['sube_id'] ?? '') !== '') {
        $sql .= " AND g.sube_id = ?";
        $params[] = $filtre['sube_id'];
    }
    if (($filtre['kullanici_id'] ?? '') !== '') {
        $sql .= " AND g.kullanici_id = ?";
        $params[] = $filtre['kullanici_id'];
    }
    if (($filtre['tip'] ?? '') !== '') {
        $sql .= " AND g.tip = ?";
        $params[] = $filtre['tip'];
    }
    if (!empty($filtre['start_date'])) {
        $sql .= " AND CONVERT(date, g.zaman) >= ?";
        $params[] = $filtre['start_date'];
    }
    if (!empty($filtre['end_date'])) {
        $sql .= " AND CONVERT(date, g.zaman) <= ?";
        $params[] = $filtre['end_date'];
    }
    if (!empty($filtre['search'])) {
        $sql .= " AND (k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ? OR g.ip_adresi LIKE ? OR g.cihaz_modeli LIKE ?)";
        $arama = '%' . $filtre['search'] . '%';
        $params[] = $arama;
        $params[] = $arama;
        $params[] = $arama;
        $params[] = $arama;
    }
    $sql .= " ORDER BY g.zaman DESC";

    return [$sql, $params];
};

// Excel aktarımı JSON değil dosya döndürdüğü için JSON başlığından önce ele alınır.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excel_indir') {
    if (!$pagePermissions['can_view']) {
        http_response_code(403);
        exit('Görüntüleme yetkiniz bulunmamaktadır.');
    }

    [$sql, $params] = $listeSorgusu($_POST);
    $kayitlar = $db->fetchAll($sql, $params);

    $filename = 'pdks_giris_cikis_' . date('Y-m-d_H-i-s') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM (Türkçe karakterler için)
    echo "\xEF\xBB\xBF";

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
    echo '<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    echo '<x:Name>Giris Cikis Kayitlari</x:Name>';
    echo '<x:WorksheetOptions><x:Print><x:ValidPrinterInfo/></x:Print></x:WorksheetOptions>';
    echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>';
    echo '</head><body>';
    echo '<table border="1">';
    echo '<thead><tr style="background-color: #0d6efd; color: white; font-weight: bold;">';
    foreach (['Kayıt No', 'Personel', 'Şube', 'Tip', 'Tarih', 'Saat', 'Cihaz Modeli', 'Cihaz ID', 'IP Adresi', 'Enlem', 'Boylam', 'QR Kod'] as $baslik) {
        echo '<th>' . $baslik . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($kayitlar as $k) {
        $zaman = $k['zaman'] ?? '';
        $tarih = $zaman ? date('d.m.Y', strtotime($zaman)) : '';
        $saat  = $zaman ? date('H:i:s', strtotime($zaman)) : '';

        echo '<tr>';
        echo '<td>' . htmlspecialchars($k['kayit_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['personel_adi'] ?? ('ID: ' . ($k['kullanici_id'] ?? ''))) . '</td>';
        echo '<td>' . htmlspecialchars($k['sube_adi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($hareketTipleri[$k['tip']]['ad'] ?? ($k['tip'] ?? '')) . '</td>';
        echo '<td>' . $tarih . '</td>';
        echo '<td>' . $saat . '</td>';
        echo '<td>' . htmlspecialchars($k['cihaz_modeli'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['cihaz_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['ip_adresi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['enlem'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['boylam'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['qr_kod'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $bugun = date('Y-m-d');
                $statParams = [];
                $statSql = "SELECT COUNT(*) as c
                            FROM Personel_GirisCikis g
                            WHERE 1=1" . $kapsamKosulu($statParams);

                $toplam  = $db->fetchOne($statSql, $statParams)['c'] ?? 0;
                $giris   = $db->fetchOne($statSql . " AND g.tip = 'giris'", $statParams)['c'] ?? 0;
                $cikis   = $db->fetchOne($statSql . " AND g.tip = 'cikis'", $statParams)['c'] ?? 0;
                $mola    = $db->fetchOne($statSql . " AND g.tip = 'mola_giris'", $statParams)['c'] ?? 0;
                $bugunKayit = $db->fetchOne(
                    $statSql . " AND CONVERT(date, g.zaman) = ?",
                    array_merge($statParams, [$bugun])
                )['c'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam', 'giris', 'cikis', 'mola', 'bugunKayit')]);
                break;

            case 'list':
                [$sql, $params] = $listeSorgusu($_POST);
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            case 'get':
                $id = intval($_POST['kayit_id'] ?? 0);
                $params = [$id];
                $sql = "SELECT g.*,
                               k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                               s.sube_adi
                        FROM Personel_GirisCikis g
                        LEFT JOIN kullanicilar k ON g.kullanici_id = k.kullanici_id
                        LEFT JOIN Subeler s ON g.sube_id = s.sube_id
                        WHERE g.kayit_id = ?";
                $sql .= $kapsamKosulu($params);

                $data = $db->fetchOne($sql, $params);
                if (!$data) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı veya görüntüleme yetkiniz yok!']);
                    break;
                }
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .tip-icon { font-size: 1rem; }
        .detail-label { font-weight: 600; color: #6c757d; font-size: 0.8rem; text-transform: uppercase; }
        .map-link { font-size: 0.8rem; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item">PDKS</li>
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
                    <div class="col-12 col-sm-6 col-lg">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-list-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kayıt</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-box-arrow-in-right"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Giriş</span>
                                <span class="info-box-number" id="stat-giris">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-cup-hot"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Mola</span>
                                <span class="info-box-number" id="stat-mola">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-box-arrow-right"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Çıkış</span>
                                <span class="info-box-number" id="stat-cikis">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-calendar-day"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün</span>
                                <span class="info-box-number" id="stat-bugun">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                    </div>
                    <div class="card-body">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Personel</label>
                                    <select class="form-select" id="filter_kullanici_id" name="kullanici_id">
                                        <option value="">Tümü</option>
                                        <?php foreach ($personeller as $p): ?>
                                            <option value="<?= $p['kullanici_id'] ?>"><?= htmlspecialchars($p['ad_soyad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Şube</label>
                                    <select class="form-select" id="filter_sube_id" name="sube_id">
                                        <option value="">Tümü</option>
                                        <?php foreach ($subeler as $s): ?>
                                            <option value="<?= $s['sube_id'] ?>"><?= htmlspecialchars($s['sube_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tip</label>
                                    <select class="form-select" id="filter_tip" name="tip">
                                        <option value="">Tümü</option>
                                        <?php foreach ($hareketTipleri as $kod => $bilgi): ?>
                                            <option value="<?= $kod ?>"><?= htmlspecialchars($bilgi['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç</label>
                                    <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş</label>
                                    <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="filter_search" name="search" placeholder="Personel adı, IP, cihaz...">
                                </div>
                                <div class="col-md-2 d-flex align-items-end gap-2">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrele</button>
                                </div>
                                <div class="col-12">
                                    <button type="button" class="btn btn-secondary btn-sm" id="clearFilters">
                                        <i class="bi bi-x-circle"></i> Filtreleri Temizle
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-clock-history"></i> Giriş/Çıkış Kayıtları</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-success btn-sm me-1" id="excelIndir">
                                <i class="bi bi-file-earmark-excel"></i> Excel İndir
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover" id="dataTable">
                                <thead>
                                    <tr>
                                        <th width="60">#</th>
                                        <th>Personel</th>
                                        <th>Şube</th>
                                        <th width="80">Tip</th>
                                        <th width="140">Zaman</th>
                                        <th>Cihaz</th>
                                        <th width="120">IP Adresi</th>
                                        <th width="80">Konum</th>
                                        <th width="60">Detay</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <tr><td colspan="9" class="text-center">Yükleniyor...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Modal: Detay -->
<div class="modal fade" id="modalDetay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle me-1"></i> Kayıt Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detayBody">
                <div class="text-center p-3"><div class="spinner-border text-primary" role="status"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script>
const modalDetay = new bootstrap.Modal('#modalDetay');
const hareketTipleri = <?= json_encode($hareketTipleri, JSON_UNESCAPED_UNICODE) ?>;
let currentFilters = {};

/** Hareket tipi rozetini üretir; tanımsız tip için ham değeri gösterir. */
function tipRozet(tip, ekSinif = '') {
    const bilgi = hareketTipleri[tip];
    if (!bilgi) return `<span class="badge bg-secondary ${ekSinif}">${tip || '-'}</span>`;
    return `<span class="badge ${ekSinif}" style="background-color:${bilgi.renk}">`
         + `<i class="bi ${bilgi.ikon} me-1"></i>${bilgi.ad}</span>`;
}

function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (r.success) {
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-giris').text(r.data.giris);
            $('#stat-mola').text(r.data.mola);
            $('#stat-cikis').text(r.data.cikis);
            $('#stat-bugun').text(r.data.bugunKayit);
        }
    });
}

function loadList() {
    $('#tableBody').html('<tr><td colspan="9" class="text-center">Yükleniyor...</td></tr>');
    $.post('', { action: 'list', ...currentFilters }, r => {
        if (!r.success) { showToast(r.message, 'error'); return; }
        const tbody = $('#tableBody');
        tbody.empty();
        if (!r.data.length) {
            tbody.html('<tr><td colspan="9" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
            return;
        }
        r.data.forEach(item => {
            const tipBadge = tipRozet(item.tip);

            const konumBtn = (item.enlem && item.boylam)
                ? `<a href="https://maps.google.com/?q=${item.enlem},${item.boylam}" target="_blank" class="btn btn-sm btn-outline-info map-link" title="Haritada göster"><i class="bi bi-geo-alt"></i></a>`
                : '<span class="text-muted small">-</span>';

            tbody.append(`
                <tr>
                    <td>${item.kayit_id}</td>
                    <td>${item.personel_adi || '<span class="text-muted">ID:' + item.kullanici_id + '</span>'}</td>
                    <td>${item.sube_adi || '<span class="text-muted">-</span>'}</td>
                    <td>${tipBadge}</td>
                    <td>${formatDate(item.zaman)}</td>
                    <td><small>${item.cihaz_modeli || '-'}</small></td>
                    <td><small class="font-monospace">${item.ip_adresi || '-'}</small></td>
                    <td class="text-center">${konumBtn}</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary" onclick="showDetay(${item.kayit_id})" title="Detay">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>`);
        });
    });
}

function showDetay(id) {
    $('#detayBody').html('<div class="text-center p-3"><div class="spinner-border text-primary" role="status"></div></div>');
    modalDetay.show();
    $.post('', { action: 'get', kayit_id: id }, r => {
        if (!r.success || !r.data) {
            $('#detayBody').html('<div class="alert alert-danger">Kayıt bulunamadı.</div>');
            return;
        }
        const d = r.data;
        const tipBadge = tipRozet(d.tip, 'fs-6');

        const konumHtml = (d.enlem && d.boylam)
            ? `<a href="https://maps.google.com/?q=${d.enlem},${d.boylam}" target="_blank" class="btn btn-sm btn-outline-info">
                   <i class="bi bi-geo-alt-fill me-1"></i>${d.enlem}, ${d.boylam}
               </a>`
            : '<span class="text-muted">Konum bilgisi yok</span>';

        $('#detayBody').html(`
            <div class="row g-3">
                <div class="col-md-6">
                    <p class="detail-label">Kayıt ID</p>
                    <p class="mb-0 fw-bold">#${d.kayit_id}</p>
                </div>
                <div class="col-md-6">
                    <p class="detail-label">İşlem Tipi</p>
                    <p class="mb-0">${tipBadge}</p>
                </div>
                <div class="col-md-6">
                    <p class="detail-label">Personel</p>
                    <p class="mb-0">${d.personel_adi || 'ID: ' + d.kullanici_id}</p>
                </div>
                <div class="col-md-6">
                    <p class="detail-label">Şube</p>
                    <p class="mb-0">${d.sube_adi || '-'}</p>
                </div>
                <div class="col-md-6">
                    <p class="detail-label">Zaman</p>
                    <p class="mb-0">${formatDate(d.zaman)}</p>
                </div>
                <div class="col-md-6">
                    <p class="detail-label">IP Adresi</p>
                    <p class="mb-0 font-monospace">${d.ip_adresi || '-'}</p>
                </div>
                <div class="col-md-6">
                    <p class="detail-label">Cihaz Modeli</p>
                    <p class="mb-0">${d.cihaz_modeli || '-'}</p>
                </div>
                <div class="col-md-6">
                    <p class="detail-label">Cihaz ID</p>
                    <p class="mb-0 font-monospace" style="word-break:break-all">${d.cihaz_id || '-'}</p>
                </div>
                <div class="col-12">
                    <p class="detail-label">QR Kod</p>
                    <p class="mb-0 font-monospace" style="word-break:break-all">${d.qr_kod || '-'}</p>
                </div>
                <div class="col-12">
                    <p class="detail-label">Konum</p>
                    <p class="mb-0">${konumHtml}</p>
                </div>
            </div>
        `);
    });
}

function formatDate(d) {
    if (!d) return '-';
    try {
        const dt = new Date(d.replace(' ', 'T'));
        return isNaN(dt) ? d : dt.toLocaleDateString('tr-TR', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' });
    } catch { return d; }
}

$('#filterForm').on('submit', function(e) {
    e.preventDefault();
    currentFilters = {
        kullanici_id: $('#filter_kullanici_id').val(),
        sube_id: $('#filter_sube_id').val(),
        tip: $('#filter_tip').val(),
        start_date: $('#filter_start_date').val(),
        end_date: $('#filter_end_date').val(),
        search: $('#filter_search').val()
    };
    Object.keys(currentFilters).forEach(k => { if (!currentFilters[k]) delete currentFilters[k]; });
    loadList();
});

// Ekrandaki filtrelerle Excel aktarımı
$('#excelIndir').on('click', function() {
    const form = $('<form>', { method: 'POST', action: window.location.href });
    form.append($('<input>', { type: 'hidden', name: 'action', value: 'excel_indir' }));

    Object.keys(currentFilters).forEach(key => {
        if (currentFilters[key]) {
            form.append($('<input>', { type: 'hidden', name: key, value: currentFilters[key] }));
        }
    });

    $('body').append(form);
    form.submit();
    form.remove();

    showToast('Excel dosyası indiriliyor...', 'info');
});

$('#clearFilters').on('click', () => {
    $('#filterForm')[0].reset();
    $('#filter_kullanici_id, #filter_sube_id, #filter_tip').val('').trigger('change.select2');
    currentFilters = {};
    loadList();
});

$(document).ready(() => {
    $('#filter_kullanici_id, #filter_sube_id, #filter_tip').select2({
        theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true,
        language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' }
    });

    // Bugünü varsayılan tarih yap
    const bugun = new Date().toISOString().split('T')[0];
    $('#filter_start_date').val(bugun);
    $('#filter_end_date').val(bugun);
    currentFilters = { start_date: bugun, end_date: bugun };

    loadStats();
    loadList();
});
</script>
</body>
</html>
