<?php
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
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

// Sayfa bilgileri
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ödeme Tipleri Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                $stats = $db->fetchOne("
                    SELECT 
                        COUNT(*) as toplam,
                        SUM(CASE WHEN odeme_tip_isaret = 1 THEN 1 ELSE 0 END) as gelir,
                        SUM(CASE WHEN odeme_tip_isaret = -1 THEN 1 ELSE 0 END) as gider,
                        SUM(CASE WHEN odeme_tip_durum = 1 THEN 1 ELSE 0 END) as aktif
                    FROM Odeme_Tipleri
                ");
                echo json_encode(['success' => true, 'data' => $stats]);
                exit;
                
            case 'list':
                // Filtreleri al
                $tip = $_POST['tip'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $search = $_POST['search'] ?? '';
                
                // WHERE koşulları
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($tip !== '') {
                    $whereConditions[] = "odeme_tip_isaret = ?";
                    $params[] = $tip;
                }
                
                if ($durum !== '') {
                    $whereConditions[] = "odeme_tip_durum = ?";
                    $params[] = $durum;
                }
                
                if ($search) {
                    $whereConditions[] = "(odeme_tip_adi LIKE ? OR odeme_tip_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                $list = $db->fetchAll("
                    SELECT 
                        odeme_tip_id,
                        odeme_tip_adi,
                        odeme_tip_isaret,
                        odeme_tip_aciklama,
                        odeme_tip_sira,
                        odeme_tip_durum,
                        CONVERT(VARCHAR(19), odeme_tip_olusturma_tarihi, 120) as odeme_tip_olusturma_tarihi,
                        CONVERT(VARCHAR(19), odeme_tip_guncelleme_tarihi, 120) as odeme_tip_guncelleme_tarihi
                    FROM Odeme_Tipleri
                    WHERE $whereClause
                    ORDER BY odeme_tip_sira ASC, odeme_tip_adi ASC
                ", $params);
                
                echo json_encode(['success' => true, 'data' => $list]);
                exit;
                
            case 'get':
                $id = $_POST['id'] ?? 0;
                $item = $db->fetchOne("
                    SELECT * FROM Odeme_Tipleri WHERE odeme_tip_id = ?
                ", [$id]);
                
                if ($item) {
                    echo json_encode(['success' => true, 'data' => $item]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']);
                }
                exit;
                
            case 'save':
                $id = $_POST['id'] ?? 0;
                $adi = $_POST['adi'] ?? '';
                $isaret = $_POST['isaret'] ?? 0;
                $aciklama = $_POST['aciklama'] ?? '';
                $sira = $_POST['sira'] ?? 0;
                $durum = isset($_POST['durum']) ? 1 : 0;
                
                // Validasyon
                if (empty($adi)) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme tipi adı gereklidir']);
                    exit;
                }
                
                if (!in_array($isaret, [1, -1])) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir tip seçiniz (Gelir/Gider)']);
                    exit;
                }
                
                $data = [
                    'odeme_tip_adi' => $adi,
                    'odeme_tip_isaret' => $isaret,
                    'odeme_tip_aciklama' => $aciklama,
                    'odeme_tip_sira' => $sira,
                    'odeme_tip_durum' => $durum
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    $data['odeme_tip_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $result = $db->update('Odeme_Tipleri', $data, ['odeme_tip_id' => $id]);
                    $message = 'Ödeme tipi başarıyla güncellendi';
                } else {
                    // Yeni kayıt
                    $result = $db->insert('Odeme_Tipleri', $data);
                    $message = 'Ödeme tipi başarıyla eklendi';
                }
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => $message]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu']);
                }
                exit;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                // Kullanımda mı kontrol et (Odeme_Hareketleri tablosu oluşturulduktan sonra aktif olacak)
                /*
                $check = $db->fetchOne("SELECT COUNT(*) as count FROM Odeme_Hareketleri WHERE odeme_hareket_tip_id = ?", [$id]);
                if ($check['count'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu ödeme tipi kullanımda olduğu için silinemez']);
                    exit;
                }
                */
                
                $result = $db->delete('Odeme_Tipleri', ['odeme_tip_id' => $id]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Ödeme tipi başarıyla silindi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Silme işlemi başarısız']);
                }
                exit;
                
            case 'updateStatus':
                $id = $_POST['id'] ?? 0;
                $durum = $_POST['durum'] ?? 0;
                
                $result = $db->update('Odeme_Tipleri', 
                    ['odeme_tip_durum' => $durum, 'odeme_tip_guncelleme_tarihi' => date('Y-m-d H:i:s')], 
                    ['odeme_tip_id' => $id]
                );
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Durum güncellendi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Durum güncellenemedi']);
                }
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        exit;
    }
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
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

            <!-- Ana İçerik -->
            <div class="app-content">
                <div class="container-fluid">

<!-- Content Header (Eski yapı - silinecek) -->
<div class="content-header" style="display: none;">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">
                    <i class="bi bi-cash-stack"></i> Ödeme Tipleri Yönetimi
                </h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="anasayfa.php">Anasayfa</a></li>
                    <li class="breadcrumb-item active">Ödeme Tipleri</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Main content (Eski yapı - silinecek) -->
<div class="content" style="display: none;">
    <div class="container-fluid">
                </h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="anasayfa.php">Anasayfa</a></li>
                    <li class="breadcrumb-item active">Ödeme Tipleri</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<div class="content">
    <div class="container-fluid">
        
        <!-- Info Boxes -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="info-box text-bg-primary">
                    <span class="info-box-icon"><i class="bi bi-list-ul"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Tip</span>
                        <span class="info-box-number" id="stat_toplam">0</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="info-box text-bg-success">
                    <span class="info-box-icon"><i class="bi bi-arrow-up-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Gelir Tipleri</span>
                        <span class="info-box-number" id="stat_gelir">0</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="info-box text-bg-danger">
                    <span class="info-box-icon"><i class="bi bi-arrow-down-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Gider Tipleri</span>
                        <span class="info-box-number" id="stat_gider">0</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="info-box text-bg-info">
                    <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Aktif Tipler</span>
                        <span class="info-box-number" id="stat_aktif">0</span>
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
                        <!-- Tip -->
                        <div class="col-md-3">
                            <label class="form-label">Tip</label>
                            <select class="form-select" name="tip" id="filter_tip">
                                <option value="">Tümü</option>
                                <option value="1">Gelir (+)</option>
                                <option value="-1">Gider (-)</option>
                            </select>
                        </div>
                        
                        <!-- Durum -->
                        <div class="col-md-2">
                            <label class="form-label">Durum</label>
                            <select class="form-select" name="durum" id="filter_durum">
                                <option value="">Tümü</option>
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>
                        
                        <!-- Arama -->
                        <div class="col-md-4">
                            <label class="form-label">Ara</label>
                            <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ödeme tipi adı veya açıklama...">
                        </div>
                        
                        <!-- Butonlar -->
                        <div class="col-md-12">
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
        
        <!-- Ana İçerik Kartı -->
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">Ödeme Tipleri Listesi</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" id="addBtn">
                        <i class="bi bi-plus-circle"></i> Yeni Ödeme Tipi Ekle
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="odemeTipleriTable" class="table table-bordered table-striped table-hover">
                    <thead>
                        <tr>
                            <th width="5%">ID</th>
                            <th width="25%">Ödeme Tipi Adı</th>
                            <th width="10%">Tip</th>
                            <th width="30%">Açıklama</th>
                            <th width="8%">Sıra</th>
                            <th width="10%">Durum</th>
                            <th width="12%">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- DataTables ile doldurulacak -->
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</div>

<!-- Modal: Ekle/Düzenle -->
<div class="modal fade" id="formModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Yeni Ödeme Tipi Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="odemeTipiForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="form_id">
                    
                    <!-- Ödeme Tipi Adı -->
                    <div class="mb-3">
                        <label for="form_adi" class="form-label">Ödeme Tipi Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="adi" id="form_adi" required>
                    </div>
                    
                    <!-- Tip (Gelir/Gider) -->
                    <div class="mb-3">
                        <label for="form_isaret" class="form-label">Tip <span class="text-danger">*</span></label>
                        <select class="form-select" name="isaret" id="form_isaret" required>
                            <option value="">Seçiniz...</option>
                            <option value="1">Gelir (+)</option>
                            <option value="-1">Gider (-)</option>
                        </select>
                        <small class="form-text text-muted">Gelir: Firmaya para girişi | Gider: Firmadan para çıkışı</small>
                    </div>
                    
                    <!-- Açıklama -->
                    <div class="mb-3">
                        <label for="form_aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" name="aciklama" id="form_aciklama" rows="3"></textarea>
                    </div>
                    
                    <!-- Sıra -->
                    <div class="mb-3">
                        <label for="form_sira" class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="sira" id="form_sira" value="0">
                        <small class="form-text text-muted">Listede gösterim sırası (küçükten büyüğe)</small>
                    </div>
                    
                    <!-- Durum -->
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="durum" id="form_durum" checked>
                            <label class="form-check-label" for="form_durum">
                                Aktif
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> İptal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/scripts.php'; ?>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<!-- Custom JS -->
<script src="/admin/assets/js/custom.js"></script>

<script>
// Filtre state
let currentFilters = {};
let table;

$(document).ready(function() {
    // İstatistikleri yükle
    loadStats();
    
    // DataTable başlat
    table = $('#odemeTipleriTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [[4, 'asc']], // Sıra kolonuna göre sırala
        pageLength: 25,
        data: [],
        columns: [
            { data: 'odeme_tip_id' },
            { data: 'odeme_tip_adi' },
            { 
                data: null,
                render: function(data) {
                    if (data.odeme_tip_isaret == 1) {
                        return '<span class="badge bg-success"><i class="bi bi-arrow-up-circle"></i> Gelir (+)</span>';
                    } else {
                        return '<span class="badge bg-danger"><i class="bi bi-arrow-down-circle"></i> Gider (-)</span>';
                    }
                }
            },
            { 
                data: 'odeme_tip_aciklama',
                render: function(data) {
                    return data || '-';
                }
            },
            { data: 'odeme_tip_sira' },
            {
                data: null,
                render: function(data) {
                    const checked = data.odeme_tip_durum == 1 ? 'checked' : '';
                    return `
                        <div class="form-check form-switch">
                            <input class="form-check-input status-switch" type="checkbox" ${checked} 
                                   data-id="${data.odeme_tip_id}" onchange="toggleStatus(this)">
                        </div>
                    `;
                }
            },
            {
                data: null,
                render: function(data) {
                    return `
                        <button class="btn btn-sm btn-warning" onclick="editRecord(${data.odeme_tip_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteRecord(${data.odeme_tip_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }
            }
        ]
    });
    
    // İlk yükleme
    loadList();
    
    // Filtre form submit
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        
        currentFilters = {
            tip: $('#filter_tip').val(),
            durum: $('#filter_durum').val(),
            search: $('#filter_search').val()
        };
        
        // Boş değerleri kaldır
        Object.keys(currentFilters).forEach(key => {
            if (!currentFilters[key]) delete currentFilters[key];
        });
        
        loadList();
        showToast('Filtre uygulandı', 'info');
    });
    
    // Filtreleri temizle
    $('#clearFilters').on('click', function() {
        $('#filterForm')[0].reset();
        $('#filter_tip').val('').trigger('change.select2');
        $('#filter_durum').val('').trigger('change.select2');
        currentFilters = {};
        loadList();
        showToast('Filtreler temizlendi', 'info');
    });
    
    // Yeni kayıt butonu
    $('#addBtn').on('click', function() {
        openModal();
    });
    
    // Form submit
    $('#odemeTipiForm').on('submit', function(e) {
        e.preventDefault();
        saveRecord();
    });
});

// İstatistikleri yükle
function loadStats() {
    $.post('', { action: 'stats' }, function(response) {
        console.log('Stats Response:', response);
        if (response.success) {
            $('#stat_toplam').text(response.data.toplam || 0);
            $('#stat_gelir').text(response.data.gelir || 0);
            $('#stat_gider').text(response.data.gider || 0);
            $('#stat_aktif').text(response.data.aktif || 0);
        }
    }).fail(function(xhr, status, error) {
        console.error('Stats AJAX Error:', error);
        console.error('Stats Response Text:', xhr.responseText);
    });
}

// Liste yükle
function loadList() {
    $.post('', { 
        action: 'list',
        ...currentFilters
    }, function(response) {
        console.log('AJAX Response:', response);
        if (response.success) {
            console.log('Data count:', response.data.length);
            table.clear();
            table.rows.add(response.data);
            table.draw();
        } else {
            showToast(response.message, 'error');
        }
    }).fail(function(xhr, status, error) {
        console.error('AJAX Error:', error);
        console.error('Response Text:', xhr.responseText);
        showToast('Veri yüklenirken hata oluştu: ' + error, 'error');
    });
}

// Modal aç
function openModal(id = 0) {
    const modal = new bootstrap.Modal(document.getElementById('formModal'));
    
    if (id > 0) {
        // Düzenleme
        $.post('', { action: 'get', id: id }, function(response) {
            if (response.success) {
                const data = response.data;
                $('#modalTitle').text('Ödeme Tipi Düzenle');
                $('#form_id').val(data.odeme_tip_id);
                $('#form_adi').val(data.odeme_tip_adi);
                $('#form_isaret').val(data.odeme_tip_isaret).trigger('change');
                $('#form_aciklama').val(data.odeme_tip_aciklama);
                $('#form_sira').val(data.odeme_tip_sira);
                $('#form_durum').prop('checked', data.odeme_tip_durum == 1);
                modal.show();
            } else {
                showToast(response.message, 'error');
            }
        });
    } else {
        // Yeni kayıt
        $('#modalTitle').text('Yeni Ödeme Tipi Ekle');
        $('#odemeTipiForm')[0].reset();
        $('#form_id').val('0');
        $('#form_durum').prop('checked', true);
        modal.show();
    }
}

// Kaydet
function saveRecord() {
    const formData = $('#odemeTipiForm').serialize() + '&action=save';
    
    $.post('', formData, function(response) {
        if (response.success) {
            showToast(response.message, 'success');
            bootstrap.Modal.getInstance(document.getElementById('formModal')).hide();
            loadList();
            loadStats();
        } else {
            showToast(response.message, 'error');
        }
    });
}

// Düzenle
function editRecord(id) {
    openModal(id);
}

// Sil
function deleteRecord(id) {
    confirmAction(
        'Bu ödeme tipini silmek istediğinize emin misiniz?',
        'Bu işlem geri alınamaz!',
        function() {
            $.post('', { action: 'delete', id: id }, function(response) {
                if (response.success) {
                    showSuccess('Silindi!', response.message);
                    loadList();
                    loadStats();
                } else {
                    showError('Hata!', response.message);
                }
            }).fail(function() {
                showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
            });
        }
    );
}

// Durum değiştir
function toggleStatus(checkbox) {
    const id = $(checkbox).data('id');
    const durum = $(checkbox).is(':checked') ? 1 : 0;
    
    $.post('', { 
        action: 'updateStatus', 
        id: id, 
        durum: durum 
    }, function(response) {
        if (response.success) {
            showToast(response.message, 'success');
            loadStats();
        } else {
            showToast(response.message, 'error');
            // Geri al
            $(checkbox).prop('checked', !durum);
        }
    });
}
</script>

                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
</body>
</html>
