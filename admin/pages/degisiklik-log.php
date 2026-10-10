<?php
/**
 * Admin Panel - Değişiklik Logları
 * Tüm sayfalardaki INSERT/UPDATE/DELETE değişikliklerinin listelenmesi
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

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanın bilgilerini al
$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Değişiklik Logları';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? '';

// URL'den gelen filtre parametreleri (sayfa açılışında uygulanacak)
$urlTablo = $_GET['tablo'] ?? '';
$urlKayitId = $_GET['kayit_id'] ?? '';
$urlSayfa = $_GET['sayfa'] ?? '';
$urlIslem = $_GET['islem'] ?? '';

// Dropdown için benzersiz sayfa ve tablo listesi
$sayfalar = $db->fetchAll("SELECT DISTINCT log_sayfa FROM Sistem_DegisiklikLog ORDER BY log_sayfa");
$tablolar = $db->fetchAll("SELECT DISTINCT log_tablo FROM Sistem_DegisiklikLog ORDER BY log_tablo");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                $whereBase = "WHERE Durum = 1";
                $params = [];
                
                // URL filtresi uygula
                if (!empty($_POST['tablo'])) {
                    $whereBase .= " AND log_tablo = ?";
                    $params[] = $_POST['tablo'];
                }
                if (!empty($_POST['kayit_id'])) {
                    $whereBase .= " AND log_kayit_id = ?";
                    $params[] = intval($_POST['kayit_id']);
                }
                if (!empty($_POST['sayfa'])) {
                    $whereBase .= " AND log_sayfa = ?";
                    $params[] = $_POST['sayfa'];
                }
                
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog $whereBase", $params)['sayi'] ?? 0;
                
                $insertParams = array_merge($params, ['INSERT']);
                $insertCount = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog $whereBase AND log_islem_tipi = ?", $insertParams)['sayi'] ?? 0;
                
                $updateParams = array_merge($params, ['UPDATE']);
                $updateCount = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog $whereBase AND log_islem_tipi = ?", $updateParams)['sayi'] ?? 0;
                
                $deleteParams = array_merge($params, ['DELETE']);
                $deleteCount = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sistem_DegisiklikLog $whereBase AND log_islem_tipi = ?", $deleteParams)['sayi'] ?? 0;
                
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => $toplam,
                    'insert' => $insertCount,
                    'update' => $updateCount,
                    'delete' => $deleteCount
                ]]);
                break;
                
            case 'list':
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $search = $_POST['search'] ?? '';
                $sayfa = $_POST['sayfa'] ?? '';
                $tablo = $_POST['tablo'] ?? '';
                $islem = $_POST['islem'] ?? '';
                $kayitId = $_POST['kayit_id'] ?? '';
                $kullaniciIdF = $_POST['kullanici_id'] ?? '';
                
                $sql = "SELECT 
                    l.log_id,
                    l.log_sayfa,
                    l.log_tablo,
                    l.log_kayit_id,
                    l.log_islem_tipi,
                    l.log_degisiklikler,
                    l.log_aciklama,
                    l.log_kullanici_id,
                    CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
                    k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
                FROM Sistem_DegisiklikLog l
                LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
                WHERE l.Durum = 1";
                $params = [];
                
                if ($startDate) {
                    $sql .= " AND CONVERT(date, l.log_tarih) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, l.log_tarih) <= ?";
                    $params[] = $endDate;
                }
                if ($search) {
                    $sql .= " AND (l.log_aciklama LIKE ? OR l.log_degisiklikler LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($sayfa) {
                    $sql .= " AND l.log_sayfa = ?";
                    $params[] = $sayfa;
                }
                if ($tablo) {
                    $sql .= " AND l.log_tablo = ?";
                    $params[] = $tablo;
                }
                if ($islem) {
                    $sql .= " AND l.log_islem_tipi = ?";
                    $params[] = $islem;
                }
                if ($kayitId) {
                    $sql .= " AND l.log_kayit_id = ?";
                    $params[] = intval($kayitId);
                }
                if ($kullaniciIdF) {
                    $sql .= " AND l.log_kullanici_id = ?";
                    $params[] = intval($kullaniciIdF);
                }
                
                $sql .= " ORDER BY l.log_id DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'detail':
                $logId = intval($_POST['id'] ?? 0);
                $log = $db->fetchOne("
                    SELECT 
                        l.*,
                        CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
                    FROM Sistem_DegisiklikLog l
                    LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
                    WHERE l.log_id = ?
                ", [$logId]);
                
                echo json_encode(['success' => true, 'data' => $log]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .badge-INSERT { background-color: #198754; }
        .badge-UPDATE { background-color: #0d6efd; }
        .badge-DELETE { background-color: #dc3545; }
        .diff-table td { word-break: break-all; max-width: 300px; }
        .diff-old { background-color: #fff3cd; }
        .diff-new { background-color: #d1e7dd; }
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
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
                                    <i class="bi bi-list-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Log</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-plus-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">INSERT</span>
                                    <span class="info-box-number" id="stat-insert">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-pencil-square"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">UPDATE</span>
                                    <span class="info-box-number" id="stat-update">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-trash"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">DELETE</span>
                                    <span class="info-box-number" id="stat-delete">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3 collapse <?= ($urlTablo || $urlKayitId || $urlSayfa || $urlIslem) ? 'show' : '' ?>" id="filterCard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtreler
                            </h3>
                        </div>
                        <div class="card-body">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sayfa</label>
                                        <select class="form-select" id="filter_sayfa" name="sayfa">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sayfalar as $s): ?>
                                                <option value="<?= htmlspecialchars($s['log_sayfa']) ?>" <?= $urlSayfa === $s['log_sayfa'] ? 'selected' : '' ?>><?= htmlspecialchars($s['log_sayfa']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tablo</label>
                                        <select class="form-select" id="filter_tablo" name="tablo">
                                            <option value="">Tümü</option>
                                            <?php foreach ($tablolar as $t): ?>
                                                <option value="<?= htmlspecialchars($t['log_tablo']) ?>" <?= $urlTablo === $t['log_tablo'] ? 'selected' : '' ?>><?= htmlspecialchars($t['log_tablo']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İşlem Tipi</label>
                                        <select class="form-select" id="filter_islem" name="islem">
                                            <option value="">Tümü</option>
                                            <option value="INSERT" <?= $urlIslem === 'INSERT' ? 'selected' : '' ?>>INSERT</option>
                                            <option value="UPDATE" <?= $urlIslem === 'UPDATE' ? 'selected' : '' ?>>UPDATE</option>
                                            <option value="DELETE" <?= $urlIslem === 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kayıt ID</label>
                                        <input type="number" class="form-control" id="filter_kayit_id" name="kayit_id" value="<?= htmlspecialchars($urlKayitId) ?>" placeholder="ID...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" id="filter_search" name="search" placeholder="Açıklama veya değişiklik...">
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
                    
                    <!-- Log Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-clock-history"></i> Log Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="logTable" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th width="30"></th>
                                            <th width="60">#</th>
                                            <th>Tarih</th>
                                            <th>Kullanıcı</th>
                                            <th>Sayfa</th>
                                            <th>Tablo</th>
                                            <th>Kayıt ID</th>
                                            <th>İşlem</th>
                                            <th>Açıklama</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Detay Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-clock-history"></i> Log Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <p class="text-center">Yükleniyor...</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    // URL'den gelen filtreler
    const urlFilters = {
        tablo: '<?= addslashes($urlTablo) ?>',
        kayit_id: '<?= addslashes($urlKayitId) ?>',
        sayfa: '<?= addslashes($urlSayfa) ?>',
        islem: '<?= addslashes($urlIslem) ?>'
    };
    
    let currentFilters = {};
    let table;
    
    $(document).ready(function() {
        // URL filtrelerini currentFilters'a ata
        if (urlFilters.tablo) currentFilters.tablo = urlFilters.tablo;
        if (urlFilters.kayit_id) currentFilters.kayit_id = urlFilters.kayit_id;
        if (urlFilters.sayfa) currentFilters.sayfa = urlFilters.sayfa;
        if (urlFilters.islem) currentFilters.islem = urlFilters.islem;
        
        // DataTable
        table = $('#logTable').DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: '',
                type: 'POST',
                data: function(d) {
                    d.action = 'list';
                    Object.assign(d, currentFilters);
                },
                dataSrc: function(json) {
                    return json.success ? json.data : [];
                }
            },
            columns: [
                {
                    className: 'dt-control',
                    orderable: false,
                    data: null,
                    defaultContent: '<button class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></button>'
                },
                { data: 'log_id' },
                { 
                    data: 'log_tarih',
                    render: function(data) {
                        return data ? formatDate(data) : '-';
                    }
                },
                { data: 'kullanici_adi', defaultContent: '-' },
                { data: 'log_sayfa' },
                { data: 'log_tablo' },
                { data: 'log_kayit_id' },
                { 
                    data: 'log_islem_tipi',
                    render: function(data) {
                        return '<span class="badge badge-' + data + '">' + data + '</span>';
                    }
                },
                { data: 'log_aciklama', defaultContent: '-' }
            ],
            order: [[1, 'desc']],
            pageLength: 25,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/tr.json'
            }
        });
        
        // Detay butonu
        $('#logTable').on('click', '.dt-control button', function() {
            const tr = $(this).closest('tr');
            const row = table.row(tr);
            const data = row.data();
            showDetail(data.log_id);
        });
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                start_date: $('#filter_start_date').val(),
                end_date: $('#filter_end_date').val(),
                search: $('#filter_search').val(),
                sayfa: $('#filter_sayfa').val(),
                tablo: $('#filter_tablo').val(),
                islem: $('#filter_islem').val(),
                kayit_id: $('#filter_kayit_id').val()
            };
            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) delete currentFilters[key];
            });
            table.ajax.reload();
            loadStats();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_sayfa').val('').trigger('change.select2');
            $('#filter_tablo').val('').trigger('change.select2');
            $('#filter_islem').val('').trigger('change.select2');
            currentFilters = {};
            table.ajax.reload();
            loadStats();
            showToast('Filtreler temizlendi', 'info');
        });
        
        loadStats();
    });
    
    function loadStats() {
        $.post('', { action: 'stats', ...currentFilters }, function(response) {
            if (response.success) {
                $('#stat-toplam').text(Number(response.data.toplam).toLocaleString('tr-TR'));
                $('#stat-insert').text(Number(response.data.insert).toLocaleString('tr-TR'));
                $('#stat-update').text(Number(response.data.update).toLocaleString('tr-TR'));
                $('#stat-delete').text(Number(response.data.delete).toLocaleString('tr-TR'));
            }
        });
    }
    
    function showDetail(logId) {
        $('#detailContent').html('<p class="text-center"><i class="bi bi-hourglass-split"></i> Yükleniyor...</p>');
        const modal = new bootstrap.Modal(document.getElementById('detailModal'));
        modal.show();
        
        $.post('', { action: 'detail', id: logId }, function(response) {
            if (response.success && response.data) {
                const d = response.data;
                let html = '';
                
                // Üst bilgi
                html += '<div class="row mb-3">';
                html += '<div class="col-md-2"><strong>Log ID:</strong> ' + d.log_id + '</div>';
                html += '<div class="col-md-2"><strong>İşlem:</strong> <span class="badge badge-' + d.log_islem_tipi + '">' + d.log_islem_tipi + '</span></div>';
                html += '<div class="col-md-3"><strong>Kullanıcı:</strong> ' + (d.kullanici_adi || '-') + '</div>';
                html += '<div class="col-md-3"><strong>Tarih:</strong> ' + formatDate(d.log_tarih) + '</div>';
                html += '<div class="col-md-2"><strong>Kayıt ID:</strong> ' + d.log_kayit_id + '</div>';
                html += '</div>';
                html += '<div class="row mb-3">';
                html += '<div class="col-md-3"><strong>Sayfa:</strong> ' + d.log_sayfa + '</div>';
                html += '<div class="col-md-3"><strong>Tablo:</strong> ' + d.log_tablo + '</div>';
                html += '<div class="col-md-6"><strong>Açıklama:</strong> ' + (d.log_aciklama || '-') + '</div>';
                html += '</div>';
                html += '<hr>';
                
                // Değişiklik detayları
                let degisiklikler = {};
                try {
                    degisiklikler = typeof d.log_degisiklikler === 'string' ? JSON.parse(d.log_degisiklikler) : (d.log_degisiklikler || {});
                } catch(e) {
                    degisiklikler = {};
                }
                
                if (Object.keys(degisiklikler).length > 0) {
                    html += '<h6><i class="bi bi-list-columns-reverse"></i> Değişiklik Detayları</h6>';
                    html += '<div class="table-responsive">';
                    html += '<table class="table table-sm table-bordered diff-table">';
                    html += '<thead class="table-light"><tr><th width="250">Alan</th><th>Eski Değer</th><th>Yeni Değer</th></tr></thead><tbody>';
                    
                    for (const [alan, deger] of Object.entries(degisiklikler)) {
                        const eski = deger.eski !== null && deger.eski !== undefined ? deger.eski : '<em class="text-muted">null</em>';
                        const yeni = deger.yeni !== null && deger.yeni !== undefined ? deger.yeni : '<em class="text-muted">null</em>';
                        
                        let eskiClass = '';
                        let yeniClass = '';
                        
                        if (d.log_islem_tipi === 'UPDATE') {
                            eskiClass = 'diff-old';
                            yeniClass = 'diff-new';
                        } else if (d.log_islem_tipi === 'INSERT') {
                            yeniClass = 'diff-new';
                        } else if (d.log_islem_tipi === 'DELETE') {
                            eskiClass = 'diff-old';
                        }
                        
                        html += '<tr>';
                        html += '<td><code>' + alan + '</code></td>';
                        html += '<td class="' + eskiClass + '">' + eski + '</td>';
                        html += '<td class="' + yeniClass + '">' + yeni + '</td>';
                        html += '</tr>';
                    }
                    
                    html += '</tbody></table></div>';
                } else {
                    html += '<p class="text-muted">Değişiklik detayı bulunamadı.</p>';
                }
                
                $('#detailContent').html(html);
            } else {
                $('#detailContent').html('<p class="text-danger">Log kaydı bulunamadı.</p>');
            }
        });
    }
    </script>
</body>
</html>
