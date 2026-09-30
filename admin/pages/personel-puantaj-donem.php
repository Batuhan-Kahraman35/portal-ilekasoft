<?php
/**
 * Admin Panel - Personel Puantaj Dönem Yönetimi
 * 
 * Personellerin hangi puantaj dönemine göre değerlendirileceğini yönetme sayfası
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
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

// Sayfa bilgileri
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Personel Puantaj Dönem Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? '';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM tanim_personel_puantaj_donem")['sayi'] ?? 0,
                    'kullanilan' => $db->fetchOne("
                        SELECT COUNT(DISTINCT kullanici_puantaj_donem_id) as sayi 
                        FROM kullanicilar 
                        WHERE kullanici_puantaj_donem_id IS NOT NULL
                    ")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM tanim_personel_puantaj_donem WHERE donem_durum = 1")['sayi'] ?? 0,
                    'pasif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM tanim_personel_puantaj_donem WHERE donem_durum = 0")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';
                
                // SQL sorgusu oluştur
                $sql = "
                    SELECT 
                        d.*,
                        CONVERT(VARCHAR(19), d.donem_olusturma_tarihi, 120) as donem_olusturma_tarihi,
                        CONVERT(VARCHAR(19), d.donem_guncelleme_tarihi, 120) as donem_guncelleme_tarihi,
                        (SELECT COUNT(*) FROM kullanicilar WHERE kullanici_puantaj_donem_id = d.donem_id) as kullanici_sayisi
                    FROM tanim_personel_puantaj_donem d
                    WHERE 1=1
                ";
                $params = [];
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (d.donem_adi LIKE ? OR d.donem_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                // Durum filtresi
                if ($status !== '') {
                    $sql .= " AND d.donem_durum = ?";
                    $params[] = $status;
                }
                
                $sql .= " ORDER BY d.donem_id DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("SELECT * FROM tanim_personel_puantaj_donem WHERE donem_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'save':
                // Kaydet
                $id = $_POST['id'] ?? 0;
                $donemAdi = $_POST['donem_adi'] ?? '';
                $baslangicGun = $_POST['donem_baslangic_gun'] ?? 1;
                $bitisGun = $_POST['donem_bitis_gun'] ?? 30;
                $aciklama = $_POST['donem_aciklama'] ?? '';
                $durum = isset($_POST['donem_durum']) ? (int)$_POST['donem_durum'] : 1;
                
                // Validasyon
                if (empty($donemAdi)) {
                    echo json_encode(['success' => false, 'message' => 'Dönem adı boş olamaz!']);
                    exit;
                }
                
                if ($baslangicGun < 1 || $baslangicGun > 31) {
                    echo json_encode(['success' => false, 'message' => 'Başlangıç günü 1-31 arasında olmalıdır!']);
                    exit;
                }
                
                if ($bitisGun < 1 || $bitisGun > 31) {
                    echo json_encode(['success' => false, 'message' => 'Bitiş günü 1-31 arasında olmalıdır!']);
                    exit;
                }
                
                if ($id > 0) {
                    // Güncelleme
                    $db->execute("
                        UPDATE tanim_personel_puantaj_donem 
                        SET 
                            donem_adi = ?,
                            donem_baslangic_gun = ?,
                            donem_bitis_gun = ?,
                            donem_aciklama = ?,
                            donem_durum = ?,
                            donem_guncelleme_tarihi = GETDATE()
                        WHERE donem_id = ?
                    ", [$donemAdi, $baslangicGun, $bitisGun, $aciklama, $durum, $id]);
                    
                    echo json_encode(['success' => true, 'message' => 'Dönem başarıyla güncellendi!']);
                } else {
                    // Yeni kayıt
                    $db->execute("
                        INSERT INTO tanim_personel_puantaj_donem 
                        (donem_adi, donem_baslangic_gun, donem_bitis_gun, donem_aciklama, donem_durum)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$donemAdi, $baslangicGun, $bitisGun, $aciklama, $durum]);
                    
                    echo json_encode(['success' => true, 'message' => 'Dönem başarıyla eklendi!']);
                }
                break;
                
            case 'delete':
                // Sil
                $id = $_POST['id'] ?? 0;
                
                // Önce bu dönemi kullanan personel var mı kontrol et
                $kullaniciSayisi = $db->fetchOne("
                    SELECT COUNT(*) as sayi 
                    FROM kullanicilar 
                    WHERE kullanici_puantaj_donem_id = ?
                ", [$id])['sayi'] ?? 0;
                
                if ($kullaniciSayisi > 0) {
                    echo json_encode([
                        'success' => false, 
                        'message' => "Bu dönem $kullaniciSayisi personel tarafından kullanılıyor. Önce personellerin dönemini değiştirin!"
                    ]);
                    exit;
                }
                
                $db->execute("DELETE FROM tanim_personel_puantaj_donem WHERE donem_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Dönem başarıyla silindi!']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
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
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-calendar-range"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Dönem</span>
                                    <span class="info-box-number" id="stat_toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-people"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kullanılan Dönem</span>
                                    <span class="info-box-number" id="stat_kullanilan">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat_aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif</span>
                                    <span class="info-box-number" id="stat_pasif">0</span>
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
                                    <div class="col-md-4">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Dönem adı veya açıklama...">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="status" id="filter_status">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    
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
                            <h3 class="card-title">
                                <i class="bi bi-calendar-range"></i> Puantaj Dönemleri
                            </h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_create']): ?>
                                <button type="button" class="btn btn-success btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Dönem Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="donemTable">
                                    <thead>
                                        <tr>
                                            <th width="5%">ID</th>
                                            <th width="20%">Dönem Adı</th>
                                            <th width="10%">Başlangıç Günü</th>
                                            <th width="10%">Bitiş Günü</th>
                                            <th width="25%">Açıklama</th>
                                            <th width="10%">Kullanıcı Sayısı</th>
                                            <th width="8%">Durum</th>
                                            <th width="12%">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="donemTableBody">
                                        <tr>
                                            <td colspan="8" class="text-center">Yükleniyor...</td>
                                        </tr>
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
    
    <!-- Dönem Modal -->
    <div class="modal fade" id="donemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Dönem Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="donemForm">
                    <input type="hidden" name="id" id="donem_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="donem_adi" class="form-label">Dönem Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="donem_adi" id="donem_adi" required placeholder="Örn: 1-30 Arası Dönem">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="donem_baslangic_gun" class="form-label">Başlangıç Günü <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="donem_baslangic_gun" id="donem_baslangic_gun" 
                                       min="1" max="31" required placeholder="1">
                                <small class="text-muted">1-31 arası</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="donem_bitis_gun" class="form-label">Bitiş Günü <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="donem_bitis_gun" id="donem_bitis_gun" 
                                       min="1" max="31" required placeholder="30">
                                <small class="text-muted">1-31 arası</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="donem_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" name="donem_aciklama" id="donem_aciklama" rows="3" 
                                      placeholder="Dönem hakkında detaylı açıklama..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="donem_durum" id="donem_durum" value="1" checked>
                                <label class="form-check-label" for="donem_durum">Aktif</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    // Global değişkenler
    let modal;
    let currentFilters = {};
    
    $(document).ready(function() {
        // Modal instance
        modal = new bootstrap.Modal(document.getElementById('donemModal'));
        
        // Select2 başlat
        $('#filter_status').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Tümü',
            allowClear: true
        });
        
        // İstatistikleri yükle
        loadStats();
        
        // Listeyi yükle
        loadList();
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            // Filtreleri topla
            currentFilters = {
                search: $('#filter_search').val(),
                status: $('#filter_status').val()
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
            $('#filter_status').val('').trigger('change.select2');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Form submit
        $('#donemForm').on('submit', function(e) {
            e.preventDefault();
            saveDonem();
        });
    });
    
    // İstatistikleri yükle
    function loadStats() {
        $.post('', { action: 'stats' }, function(response) {
            if (response.success) {
                $('#stat_toplam').text(response.data.toplam);
                $('#stat_kullanilan').text(response.data.kullanilan);
                $('#stat_aktif').text(response.data.aktif);
                $('#stat_pasif').text(response.data.pasif);
            }
        });
    }
    
    // Listeyi yükle
    function loadList() {
        $.post('', { 
            action: 'list',
            ...currentFilters
        }, function(response) {
            if (response.success) {
                renderTable(response.data);
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    
    // Tabloyu render et
    function renderTable(data) {
        const tbody = $('#donemTableBody');
        tbody.empty();
        
        if (data.length === 0) {
            tbody.append('<tr><td colspan="8" class="text-center">Kayıt bulunamadı.</td></tr>');
            return;
        }
        
        data.forEach(function(item) {
            const durumBadge = item.donem_durum == 1 
                ? '<span class="badge bg-success">Aktif</span>' 
                : '<span class="badge bg-secondary">Pasif</span>';
            
            const row = `
                <tr>
                    <td>${item.donem_id}</td>
                    <td><strong>${item.donem_adi}</strong></td>
                    <td class="text-center">${item.donem_baslangic_gun}</td>
                    <td class="text-center">${item.donem_bitis_gun}</td>
                    <td>${item.donem_aciklama || '-'}</td>
                    <td class="text-center">
                        ${item.kullanici_sayisi > 0 ? '<span class="badge bg-info">' + item.kullanici_sayisi + ' Personel</span>' : '-'}
                    </td>
                    <td class="text-center">${durumBadge}</td>
                    <td class="text-center">
                        <?php if ($pagePermissions['can_update']): ?>
                        <button class="btn btn-warning btn-sm" onclick="editDonem(${item.donem_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($pagePermissions['can_delete']): ?>
                        <button class="btn btn-danger btn-sm" onclick="deleteDonem(${item.donem_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
            `;
            tbody.append(row);
        });
    }
    
    // Modal aç (yeni kayıt)
    function openModal() {
        $('#donemForm')[0].reset();
        $('#donem_id').val('');
        $('#donem_durum').prop('checked', true);
        $('#modalTitle').text('Yeni Dönem Ekle');
        modal.show();
    }
    
    // Düzenle
    function editDonem(id) {
        $.post('', { action: 'get', id: id }, function(response) {
            if (response.success) {
                const data = response.data;
                $('#donem_id').val(data.donem_id);
                $('#donem_adi').val(data.donem_adi);
                $('#donem_baslangic_gun').val(data.donem_baslangic_gun);
                $('#donem_bitis_gun').val(data.donem_bitis_gun);
                $('#donem_aciklama').val(data.donem_aciklama);
                $('#donem_durum').prop('checked', data.donem_durum == 1);
                $('#modalTitle').text('Dönem Düzenle');
                modal.show();
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    
    // Kaydet
    function saveDonem() {
        const formData = $('#donemForm').serialize() + '&action=save';
        
        $.post('', formData, function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                modal.hide();
                loadList();
                loadStats();
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    
    // Sil
    function deleteDonem(id) {
        confirmAction(
            'Bu dönemi silmek istediğinize emin misiniz?',
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
                });
            }
        );
    }
    </script>
</body>
</html>
