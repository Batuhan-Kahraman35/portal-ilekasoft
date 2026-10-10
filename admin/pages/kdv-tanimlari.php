<?php
/**
 * Admin Panel - KDV Tanımları
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'KDV Tanımları';
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
                // İstatistikleri getir
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM KDV_Tanimlari")['sayi'] ?? 0,
                    'varsayilan' => $db->fetchOne("SELECT kdv_adi FROM KDV_Tanimlari WHERE kdv_varsayilan = 1")['kdv_adi'] ?? '-',
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM KDV_Tanimlari WHERE kdv_durum = 1")['sayi'] ?? 0,
                    'pasif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM KDV_Tanimlari WHERE kdv_durum = 0")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // KDV tanımlarını listele
                $sql = "SELECT 
                            k.kdv_id,
                            k.kdv_adi,
                            k.kdv_oran,
                            k.kdv_aciklama,
                            k.kdv_varsayilan,
                            k.kdv_sira_no,
                            k.kdv_durum,
                            CONVERT(VARCHAR(19), k.kdv_olusturma_tarihi, 120) as kdv_olusturma_tarihi,
                            CONVERT(VARCHAR(19), k.kdv_guncelleme_tarihi, 120) as kdv_guncelleme_tarihi,
                            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
                            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
                        FROM KDV_Tanimlari k
                        LEFT JOIN kullanicilar olusturan ON olusturan.kullanici_id = k.kdv_olusturan_kullanici_id
                        LEFT JOIN kullanicilar guncelleyen ON guncelleyen.kullanici_id = k.kdv_guncelleyen_kullanici_id
                        ORDER BY k.kdv_sira_no, k.kdv_oran DESC";
                
                $kdvler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $kdvler]);
                break;
                
            case 'get':
                // Tek KDV getir
                $id = $_POST['id'] ?? 0;
                $sql = "SELECT * FROM KDV_Tanimlari WHERE kdv_id = ?";
                $kdv = $db->fetchOne($sql, [$id]);
                
                if ($kdv) {
                    echo json_encode(['success' => true, 'data' => $kdv]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'KDV tanımı bulunamadı!']);
                }
                break;
                
            case 'save':
                // Yetki kontrolü
                $id = $_POST['id'] ?? 0;
                if ($id > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                // Yeni KDV ekle veya güncelle
                $kdv_adi = trim($_POST['kdv_adi'] ?? '');
                $kdv_oran = intval($_POST['kdv_oran'] ?? 0);
                $kdv_aciklama = trim($_POST['kdv_aciklama'] ?? '');
                $kdv_varsayilan = isset($_POST['kdv_varsayilan']) ? 1 : 0;
                $kdv_sira_no = $_POST['kdv_sira_no'] ?? 0;
                $kdv_durum = isset($_POST['kdv_durum']) ? 1 : 0;
                
                if (empty($kdv_adi)) {
                    echo json_encode(['success' => false, 'message' => 'KDV adı zorunludur!']);
                    break;
                }
                
                if ($kdv_oran < 0 || $kdv_oran > 100) {
                    echo json_encode(['success' => false, 'message' => 'KDV oranı 0-100 arasında olmalıdır!']);
                    break;
                }
                
                // Eğer varsayılan işaretliyse, diğerlerini sıfırla
                if ($kdv_varsayilan) {
                    $db->execute("UPDATE KDV_Tanimlari SET kdv_varsayilan = 0");
                }
                
                if ($id > 0) {
                    // Güncelleme
                    $sql = "UPDATE KDV_Tanimlari SET 
                                kdv_adi = ?,
                                kdv_oran = ?,
                                kdv_aciklama = ?,
                                kdv_varsayilan = ?,
                                kdv_sira_no = ?,
                                kdv_durum = ?,
                                kdv_guncelleme_tarihi = GETDATE(),
                                kdv_guncelleyen_kullanici_id = ?
                            WHERE kdv_id = ?";
                    
                    $db->execute($sql, [
                        $kdv_adi,
                        $kdv_oran,
                        $kdv_aciklama,
                        $kdv_varsayilan,
                        $kdv_sira_no,
                        $kdv_durum,
                        $user['id'],
                        $id
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'KDV tanımı başarıyla güncellendi!']);
                } else {
                    // Ekleme
                    $sql = "INSERT INTO KDV_Tanimlari (
                                kdv_adi,
                                kdv_oran,
                                kdv_aciklama,
                                kdv_varsayilan,
                                kdv_sira_no,
                                kdv_durum,
                                kdv_olusturan_kullanici_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $db->execute($sql, [
                        $kdv_adi,
                        $kdv_oran,
                        $kdv_aciklama,
                        $kdv_varsayilan,
                        $kdv_sira_no,
                        $kdv_durum,
                        $user['id']
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'KDV tanımı başarıyla eklendi!']);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                // KDV sil
                $id = $_POST['id'] ?? 0;
                
                // Varsayılan KDV silinemez
                $checkSql = "SELECT kdv_varsayilan FROM KDV_Tanimlari WHERE kdv_id = ?";
                $result = $db->fetchOne($checkSql, [$id]);
                
                if ($result && $result['kdv_varsayilan'] == 1) {
                    echo json_encode(['success' => false, 'message' => 'Varsayılan KDV tanımı silinemez!']);
                    break;
                }
                
                $sql = "DELETE FROM KDV_Tanimlari WHERE kdv_id = ?";
                $db->execute($sql, [$id]);
                
                echo json_encode(['success' => true, 'message' => 'KDV tanımı başarıyla silindi!']);
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
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
    <style>
        .kdv-badge {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 1.1rem;
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
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-percent"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam KDV</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-star-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Varsayılan</span>
                                    <span class="info-box-number" id="stat-varsayilan" style="font-size: 1.2rem;">-</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-x-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- KDV Tanımları Tablosu -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">KDV Oranları</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni KDV Tanımı
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="kdvTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>KDV Adı</th>
                                        <th>Oran (%)</th>
                                        <th>Açıklama</th>
                                        <th>Varsayılan</th>
                                        <th>Sıra</th>
                                        <th>Durum</th>
                                        <th>İşlemler</th>
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
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- KDV Modal -->
    <div class="modal fade" id="kdvModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni KDV Tanımı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="kdvForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="kdv_id">
                        
                        <div class="mb-3">
                            <label for="kdv_adi" class="form-label">KDV Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kdv_adi" name="kdv_adi" required placeholder="örn: KDV %20">
                        </div>
                        
                        <div class="mb-3">
                            <label for="kdv_oran" class="form-label">KDV Oranı (%) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="kdv_oran" name="kdv_oran" required min="0" max="100" placeholder="örn: 20">
                            <small class="text-muted">0-100 arasında tam sayı giriniz</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="kdv_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="kdv_aciklama" name="kdv_aciklama" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="kdv_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="kdv_sira_no" name="kdv_sira_no" value="0">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">Durum</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="kdv_durum" name="kdv_durum" checked>
                                        <label class="form-check-label" for="kdv_durum">Aktif</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="kdv_varsayilan" name="kdv_varsayilan">
                                <label class="form-check-label" for="kdv_varsayilan">
                                    <i class="bi bi-star-fill text-warning"></i> Varsayılan KDV
                                </label>
                                <small class="d-block text-muted">İşaretlerseniz, diğer tüm KDV tanımlarının varsayılan işareti kaldırılır</small>
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
    
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Sayfa yetkileri
        const pagePermissions = {
            can_add: <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
            can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        let table;
        const modal = new bootstrap.Modal(document.getElementById('kdvModal'));
        
        // Toast bildirim fonksiyonu
        function showToast(message, type = 'success') {
            const toastTypes = {
                success: { icon: 'bi-check-circle-fill', bgClass: 'bg-success' },
                error: { icon: 'bi-x-circle-fill', bgClass: 'bg-danger' },
                warning: { icon: 'bi-exclamation-triangle-fill', bgClass: 'bg-warning' },
                info: { icon: 'bi-info-circle-fill', bgClass: 'bg-info' }
            };
            
            const config = toastTypes[type] || toastTypes.success;
            
            const toastHtml = `
                <div class="toast align-items-center text-white ${config.bgClass} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi ${config.icon} me-2"></i>
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            
            let toastContainer = document.getElementById('toastContainer');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'toastContainer';
                toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
                toastContainer.style.zIndex = '9999';
                document.body.appendChild(toastContainer);
            }
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = toastContainer.lastElementChild;
            const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', function() {
                toastElement.remove();
            });
        }
        
        // DataTable'ı başlat
        function initDataTable() {
            table = $('#kdvTable').DataTable({
                processing: true,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: { action: 'list' },
                    dataSrc: function(json) {
                        if (json.success) {
                            return json.data;
                        }
                        showToast('Veri yüklenirken hata oluştu!', 'error');
                        return [];
                    }
                },
                columns: [
                    { data: 'kdv_id' },
                    { data: 'kdv_adi' },
                    { 
                        data: 'kdv_oran',
                        render: function(data) {
                            return `<span class="kdv-badge bg-primary">%${data}</span>`;
                        }
                    },
                    { 
                        data: 'kdv_aciklama',
                        render: function(data) {
                            return data || '-';
                        }
                    },
                    { 
                        data: 'kdv_varsayilan',
                        render: function(data) {
                            return data ? '<i class="bi bi-star-fill text-warning" style="font-size: 1.2rem;"></i>' : '-';
                        }
                    },
                    { data: 'kdv_sira_no' },
                    { 
                        data: 'kdv_durum',
                        render: function(data) {
                            return data ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>';
                        }
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: function(data) {
                            let buttons = '';
                            if (pagePermissions.can_edit) {
                                buttons += `<button class="btn btn-sm btn-warning" onclick="editKdv(${data.kdv_id})" title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </button> `;
                            }
                            if (pagePermissions.can_delete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="deleteKdv(${data.kdv_id})" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }
                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[5, 'asc'], [2, 'desc']]
            });
        }
        
        // Modal aç
        function openModal() {
            document.getElementById('modalTitle').textContent = 'Yeni KDV Tanımı';
            document.getElementById('kdvForm').reset();
            document.getElementById('kdv_id').value = '';
            document.getElementById('kdv_durum').checked = true;
            document.getElementById('kdv_varsayilan').checked = false;
            modal.show();
        }
        
        // KDV düzenle
        function editKdv(id) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', id: id },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        document.getElementById('modalTitle').textContent = 'KDV Tanımı Düzenle';
                        document.getElementById('kdv_id').value = data.kdv_id;
                        document.getElementById('kdv_adi').value = data.kdv_adi;
                        document.getElementById('kdv_oran').value = data.kdv_oran;
                        document.getElementById('kdv_aciklama').value = data.kdv_aciklama || '';
                        document.getElementById('kdv_sira_no').value = data.kdv_sira_no || 0;
                        document.getElementById('kdv_durum').checked = data.kdv_durum == 1;
                        document.getElementById('kdv_varsayilan').checked = data.kdv_varsayilan == 1;
                        
                        modal.show();
                    } else {
                        showToast(response.message, 'error');
                    }
                }
            });
        }
        
        // KDV sil
        function deleteKdv(id) {
            if (!confirm('Bu KDV tanımını silmek istediğinizden emin misiniz?')) {
                return;
            }
            
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'delete', id: id },
                success: function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        table.ajax.reload();
                        loadStats();
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function() {
                    showToast('Bir hata oluştu!', 'error');
                }
            });
        }
        
        // Form submit
        $('#kdvForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'save');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        modal.hide();
                        table.ajax.reload();
                        loadStats();
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function() {
                    showToast('Bir hata oluştu!', 'error');
                }
            });
        });
        
        // Sayfa yüklendiğinde
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-varsayilan').text(response.data.varsayilan);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                }
            });
        }
        
        $(document).ready(function() {
            loadStats();
            initDataTable();
        });
    </script>
</body>
</html>
