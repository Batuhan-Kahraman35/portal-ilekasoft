<?php
/**
 * Admin Panel - Banka API Log
 * Tüm banka API isteklerinin loglanması ve izlenmesi
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

$pageTitle = 'Banka API Log';

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Bankalar listesi (filtre için)
$bankalar = $db->fetchAll("
    SELECT DISTINCT b.banka_id, b.banka_adi 
    FROM banka_ApiKimlik ak
    INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
    WHERE ak.apiKimlik_durum = 1
    ORDER BY b.banka_adi
");

// İşlem tipleri (filtre için)
$islemTipleri = [
    'testConnection' => 'Bağlantı Testi',
    'getHesaplar' => 'Hesap Listesi',
    'getHareketler' => 'Hesap Hareketleri',
    'senkronize' => 'Senkronizasyon'
];

// Kaynak tipleri
$kaynakTipleri = [
    'manual' => 'Manuel',
    'cron' => 'Otomatik (Cron)',
    'api' => 'API'
];

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiLog")['sayi'] ?? 0,
                    'basarili' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiLog WHERE log_basarili = 1")['sayi'] ?? 0,
                    'hatali' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiLog WHERE log_basarili = 0")['sayi'] ?? 0,
                    'ort_sure' => $db->fetchOne("SELECT ISNULL(AVG(log_sure_ms), 0) as sure FROM banka_ApiLog WHERE log_sure_ms IS NOT NULL")['sure'] ?? 0,
                    'bugun' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiLog WHERE CONVERT(date, log_istek_tarihi) = CONVERT(date, GETDATE())")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametreleri
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $bankaId = $_POST['banka_id'] ?? '';
                $islemTipi = $_POST['islem_tipi'] ?? '';
                $kaynak = $_POST['kaynak'] ?? '';
                $durum = $_POST['durum'] ?? '';
                
                // SQL sorgusu
                $sql = "SELECT 
                    l.log_id,
                    l.log_apiKimlik_id,
                    l.log_bankaHesap_id,
                    l.log_islem_tipi,
                    CONVERT(VARCHAR(19), l.log_istek_tarihi, 120) as log_istek_tarihi,
                    CONVERT(VARCHAR(19), l.log_yanit_tarihi, 120) as log_yanit_tarihi,
                    l.log_sure_ms,
                    l.log_basarili,
                    l.log_http_status,
                    l.log_banka_hata_kodu,
                    l.log_hata_mesaji,
                    l.log_kayit_sayisi,
                    l.log_istek_ozet,
                    l.log_kaynak,
                    l.log_kullanici_id,
                    l.log_ip_adresi,
                    b.banka_adi,
                    bh.bankaHesap_iban,
                    bh.bankaHesap_aciklama as hesap_aciklama,
                    k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
                FROM banka_ApiLog l
                INNER JOIN banka_ApiKimlik ak ON l.log_apiKimlik_id = ak.apiKimlik_id
                INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
                LEFT JOIN banka_Hesap bh ON l.log_bankaHesap_id = bh.bankaHesap_id
                LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
                WHERE 1=1";
                $params = [];
                
                // Tarih filtresi
                if ($startDate) {
                    $sql .= " AND CONVERT(date, l.log_istek_tarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, l.log_istek_tarihi) <= ?";
                    $params[] = $endDate;
                }
                
                // Banka filtresi
                if ($bankaId) {
                    $sql .= " AND ak.apiKimlik_banka_id = ?";
                    $params[] = $bankaId;
                }
                
                // İşlem tipi filtresi
                if ($islemTipi) {
                    $sql .= " AND l.log_islem_tipi = ?";
                    $params[] = $islemTipi;
                }
                
                // Kaynak filtresi
                if ($kaynak) {
                    $sql .= " AND l.log_kaynak = ?";
                    $params[] = $kaynak;
                }
                
                // Durum filtresi
                if ($durum !== '') {
                    $sql .= " AND l.log_basarili = ?";
                    $params[] = $durum;
                }
                
                $sql .= " ORDER BY l.log_istek_tarihi DESC";
                
                // Filtre yoksa son 100 kayıt
                $hasFilter = $startDate || $endDate || $bankaId || $islemTipi || $kaynak || $durum !== '';
                if (!$hasFilter) {
                    $sql = str_replace('SELECT ', 'SELECT TOP 100 ', $sql);
                }
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt detayı
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("
                    SELECT 
                        l.*,
                        CONVERT(VARCHAR(19), l.log_istek_tarihi, 120) as log_istek_tarihi,
                        CONVERT(VARCHAR(19), l.log_yanit_tarihi, 120) as log_yanit_tarihi,
                        b.banka_adi,
                        bh.bankaHesap_iban,
                        bh.bankaHesap_aciklama as hesap_aciklama,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
                    FROM banka_ApiLog l
                    INNER JOIN banka_ApiKimlik ak ON l.log_apiKimlik_id = ak.apiKimlik_id
                    INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
                    LEFT JOIN banka_Hesap bh ON l.log_bankaHesap_id = bh.bankaHesap_id
                    LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
                    WHERE l.log_id = ?
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'delete_old':
                // Eski logları sil (X günden eski)
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $days = intval($_POST['days'] ?? 30);
                if ($days < 7) $days = 7; // Minimum 7 gün
                
                $result = $db->execute("
                    DELETE FROM banka_ApiLog 
                    WHERE log_istek_tarihi < DATEADD(day, -?, GETDATE())
                ", [$days]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => "$days günden eski loglar silindi."
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
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= $siteTitle ?></title>
    
    <!-- AdminLTE & Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <style>
        .badge-success { background-color: #198754; }
        .badge-danger { background-color: #dc3545; }
        .log-detail-pre {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
            max-height: 300px;
            overflow-y: auto;
            font-size: 12px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .sure-badge {
            font-size: 11px;
            padding: 2px 6px;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0">
                                <i class="bi bi-journal-text"></i> <?= $pageTitle ?>
                            </h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="anasayfa.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item active"><?= $pageTitle ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box shadow-sm">
                                <span class="info-box-icon text-bg-primary"><i class="bi bi-list-ul"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam İstek</span>
                                    <span class="info-box-number" id="statToplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box shadow-sm">
                                <span class="info-box-icon text-bg-success"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Başarılı</span>
                                    <span class="info-box-number" id="statBasarili">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box shadow-sm">
                                <span class="info-box-icon text-bg-danger"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Hatalı</span>
                                    <span class="info-box-number" id="statHatali">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="info-box shadow-sm">
                                <span class="info-box-icon text-bg-info"><i class="bi bi-speedometer2"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ort. Yanıt Süresi</span>
                                    <span class="info-box-number"><span id="statOrtSure">0</span> ms</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" name="start_date" id="filter_start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" name="end_date" id="filter_end_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Banka</label>
                                        <select class="form-select" name="banka_id" id="filter_banka_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($bankalar as $banka): ?>
                                                <option value="<?= $banka['banka_id'] ?>"><?= htmlspecialchars($banka['banka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İşlem Tipi</label>
                                        <select class="form-select" name="islem_tipi" id="filter_islem_tipi">
                                            <option value="">Tümü</option>
                                            <?php foreach ($islemTipleri as $key => $val): ?>
                                                <option value="<?= $key ?>"><?= $val ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kaynak</label>
                                        <select class="form-select" name="kaynak" id="filter_kaynak">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kaynakTipleri as $key => $val): ?>
                                                <option value="<?= $key ?>"><?= $val ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Başarılı</option>
                                            <option value="0">Hatalı</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                        <?php if ($pagePermissions['can_delete']): ?>
                                        <button type="button" class="btn btn-danger float-end" id="btnDeleteOld">
                                            <i class="bi bi-trash"></i> Eski Logları Sil
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Ana Tablo Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-table"></i> API Log Listesi
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadList()">
                                    <i class="bi bi-arrow-clockwise"></i> Yenile
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="logTable" class="table table-bordered table-striped table-hover" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th width="140">Tarih/Saat</th>
                                            <th width="100">Banka</th>
                                            <th>Hesap</th>
                                            <th width="120">İşlem</th>
                                            <th width="80">Süre</th>
                                            <th width="70">Kayıt</th>
                                            <th width="80">Durum</th>
                                            <th width="80">Kaynak</th>
                                            <th width="70">İşlem</th>
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
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-info-circle"></i> Log Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm table-bordered">
                                <tr><th width="120">Banka</th><td id="detail_banka"></td></tr>
                                <tr><th>Hesap</th><td id="detail_hesap"></td></tr>
                                <tr><th>İşlem Tipi</th><td id="detail_islem_tipi"></td></tr>
                                <tr><th>İstek Tarihi</th><td id="detail_istek_tarihi"></td></tr>
                                <tr><th>Yanıt Tarihi</th><td id="detail_yanit_tarihi"></td></tr>
                                <tr><th>Süre</th><td id="detail_sure"></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm table-bordered">
                                <tr><th width="120">Durum</th><td id="detail_durum"></td></tr>
                                <tr><th>HTTP Status</th><td id="detail_http_status"></td></tr>
                                <tr><th>Banka Hata Kodu</th><td id="detail_hata_kodu"></td></tr>
                                <tr><th>Kayıt Sayısı</th><td id="detail_kayit_sayisi"></td></tr>
                                <tr><th>Kaynak</th><td id="detail_kaynak"></td></tr>
                                <tr><th>Kullanıcı/IP</th><td id="detail_kullanici"></td></tr>
                            </table>
                        </div>
                    </div>
                    
                    <div class="mt-3" id="detail_hata_container" style="display:none;">
                        <h6><i class="bi bi-exclamation-triangle text-danger"></i> Hata Mesajı</h6>
                        <div class="alert alert-danger" id="detail_hata_mesaji"></div>
                    </div>
                    
                    <div class="mt-3" id="detail_istek_container" style="display:none;">
                        <h6><i class="bi bi-arrow-up-circle"></i> İstek Özeti</h6>
                        <pre class="log-detail-pre" id="detail_istek_ozet"></pre>
                    </div>
                    
                    <div class="mt-3" id="detail_yanit_container" style="display:none;">
                        <h6><i class="bi bi-arrow-down-circle"></i> Yanıt Özeti</h6>
                        <pre class="log-detail-pre" id="detail_yanit_ozet"></pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    // İşlem tipi etiketleri
    const islemTipleri = {
        'testConnection': 'Bağlantı Testi',
        'getHesaplar': 'Hesap Listesi',
        'getHareketler': 'Hesap Hareketleri',
        'senkronize': 'Senkronizasyon'
    };
    
    // Kaynak etiketleri
    const kaynakTipleri = {
        'manual': '<span class="badge bg-secondary">Manuel</span>',
        'cron': '<span class="badge bg-info">Cron</span>',
        'api': '<span class="badge bg-primary">API</span>'
    };
    
    // Filtre state
    let currentFilters = {};
    
    $(document).ready(function() {
        // Select2 init
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            placeholder: 'Seçiniz...'
        });
        
        // İlk yükleme
        loadStats();
        loadList();
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                start_date: $('#filter_start_date').val(),
                end_date: $('#filter_end_date').val(),
                banka_id: $('#filter_banka_id').val(),
                islem_tipi: $('#filter_islem_tipi').val(),
                kaynak: $('#filter_kaynak').val(),
                durum: $('#filter_durum').val()
            };
            
            // Boş değerleri kaldır
            Object.keys(currentFilters).forEach(key => {
                if (currentFilters[key] === '' || currentFilters[key] === null) {
                    delete currentFilters[key];
                }
            });
            
            loadList();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_banka_id').val('').trigger('change.select2');
            $('#filter_islem_tipi').val('').trigger('change.select2');
            $('#filter_kaynak').val('').trigger('change.select2');
            $('#filter_durum').val('').trigger('change.select2');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Eski logları sil
        $('#btnDeleteOld').on('click', function() {
            Swal.fire({
                title: 'Eski Logları Sil',
                html: '<p>Kaç günden eski loglar silinsin?</p>' +
                      '<input type="number" id="deleteDays" class="swal2-input" value="30" min="7" max="365">',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sil',
                cancelButtonText: 'İptal',
                preConfirm: () => {
                    return document.getElementById('deleteDays').value;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('', { action: 'delete_old', days: result.value }, function(response) {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            loadStats();
                            loadList();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            });
        });
    });
    
    // İstatistikleri yükle
    function loadStats() {
        $.post('', { action: 'stats' }, function(response) {
            if (response.success) {
                $('#statToplam').text(response.data.toplam.toLocaleString('tr-TR'));
                $('#statBasarili').text(response.data.basarili.toLocaleString('tr-TR'));
                $('#statHatali').text(response.data.hatali.toLocaleString('tr-TR'));
                $('#statOrtSure').text(Math.round(response.data.ort_sure).toLocaleString('tr-TR'));
            }
        });
    }
    
    // Listeyi yükle
    function loadList() {
        $.post('', { action: 'list', ...currentFilters }, function(response) {
            if (response.success) {
                renderTable(response.data);
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    
    // Tabloyu render et
    function renderTable(data) {
        // Mevcut DataTable'ı yok et
        if ($.fn.DataTable.isDataTable('#logTable')) {
            $('#logTable').DataTable().destroy();
        }
        
        let html = '';
        data.forEach(function(item) {
            const durumBadge = item.log_basarili == 1 
                ? '<span class="badge bg-success">Başarılı</span>'
                : '<span class="badge bg-danger">Hatalı</span>';
            
            const sureBadge = item.log_sure_ms 
                ? `<span class="badge ${item.log_sure_ms > 5000 ? 'bg-warning' : 'bg-secondary'} sure-badge">${item.log_sure_ms} ms</span>`
                : '-';
            
            const hesapInfo = item.bankaHesap_iban 
                ? item.bankaHesap_iban.substring(0, 10) + '...'
                : '<span class="text-muted">Genel</span>';
            
            const islemTipi = islemTipleri[item.log_islem_tipi] || item.log_islem_tipi;
            const kaynakBadge = kaynakTipleri[item.log_kaynak] || item.log_kaynak;
            
            html += `<tr>
                <td>${formatDate(item.log_istek_tarihi)}</td>
                <td>${item.banka_adi || '-'}</td>
                <td title="${item.bankaHesap_iban || ''}">${hesapInfo}</td>
                <td>${islemTipi}</td>
                <td class="text-center">${sureBadge}</td>
                <td class="text-center">${item.log_kayit_sayisi || 0}</td>
                <td class="text-center">${durumBadge}</td>
                <td class="text-center">${kaynakBadge}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-info" onclick="showDetail(${item.log_id})" title="Detay">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            </tr>`;
        });
        
        $('#logTable tbody').html(html);
        
        // DataTable init
        $('#logTable').DataTable({
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
            },
            pageLength: 25,
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [8] }
            ]
        });
    }
    
    // Detay göster
    function showDetail(id) {
        $.post('', { action: 'get', id: id }, function(response) {
            if (response.success && response.data) {
                const d = response.data;
                
                $('#detail_banka').text(d.banka_adi || '-');
                $('#detail_hesap').text(d.bankaHesap_iban || 'Genel İşlem');
                $('#detail_islem_tipi').text(islemTipleri[d.log_islem_tipi] || d.log_islem_tipi);
                $('#detail_istek_tarihi').text(formatDate(d.log_istek_tarihi));
                $('#detail_yanit_tarihi').text(formatDate(d.log_yanit_tarihi) || '-');
                $('#detail_sure').text(d.log_sure_ms ? d.log_sure_ms + ' ms' : '-');
                
                $('#detail_durum').html(d.log_basarili == 1 
                    ? '<span class="badge bg-success">Başarılı</span>' 
                    : '<span class="badge bg-danger">Hatalı</span>');
                $('#detail_http_status').text(d.log_http_status || '-');
                $('#detail_hata_kodu').text(d.log_banka_hata_kodu || '-');
                $('#detail_kayit_sayisi').text(d.log_kayit_sayisi || '0');
                $('#detail_kaynak').html(kaynakTipleri[d.log_kaynak] || d.log_kaynak);
                
                let kullaniciInfo = d.kullanici_adi || '-';
                if (d.log_ip_adresi) kullaniciInfo += ' (' + d.log_ip_adresi + ')';
                $('#detail_kullanici').text(kullaniciInfo);
                
                // Hata mesajı
                if (d.log_hata_mesaji) {
                    $('#detail_hata_mesaji').text(d.log_hata_mesaji);
                    $('#detail_hata_container').show();
                } else {
                    $('#detail_hata_container').hide();
                }
                
                // İstek özeti
                if (d.log_istek_ozet) {
                    $('#detail_istek_ozet').text(d.log_istek_ozet);
                    $('#detail_istek_container').show();
                } else {
                    $('#detail_istek_container').hide();
                }
                
                // Yanıt özeti
                if (d.log_yanit_ozet) {
                    $('#detail_yanit_ozet').text(d.log_yanit_ozet);
                    $('#detail_yanit_container').show();
                } else {
                    $('#detail_yanit_container').hide();
                }
                
                $('#detailModal').modal('show');
            } else {
                showError('Hata!', 'Kayıt bulunamadı.');
            }
        });
    }
    
    // Tarih formatlama
    function formatDate(dateString) {
        if (!dateString) return '-';
        try {
            const date = new Date(dateString.replace(' ', 'T'));
            if (isNaN(date.getTime())) return '-';
            return date.toLocaleDateString('tr-TR', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        } catch (e) {
            return '-';
        }
    }
    </script>
</body>
</html>
