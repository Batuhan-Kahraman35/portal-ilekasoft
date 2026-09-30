<?php
/**
 * Admin Panel - Marka Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Mevcut sayfanın bilgilerini al
$currentPageFile = basename($_SERVER['PHP_SELF']);
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Marka Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['id'], $user['departman_id'], $currentPageFile);

// Erişim yetkisi yoksa hata sayfası göster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

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
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Markalar")['sayi'] ?? 0,
                    'websiteli' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Markalar WHERE marka_website IS NOT NULL AND marka_website != ''")['sayi'] ?? 0,
                    'logolu' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Markalar WHERE marka_logo_url IS NOT NULL AND marka_logo_url != ''")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Markalar WHERE marka_durum = 1")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtreleri al
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';
                
                // WHERE koşulları
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($search) {
                    $whereConditions[] = "(m.marka_adi LIKE ? OR m.marka_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                if ($status !== '') {
                    $whereConditions[] = "m.marka_durum = ?";
                    $params[] = $status;
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Markaları listele
                $sql = "SELECT 
                            m.marka_id,
                            m.marka_adi,
                            m.marka_aciklama,
                            m.marka_renk,
                            m.marka_logo_url,
                            m.marka_website,
                            m.marka_sira_no,
                            m.marka_durum,
                            CONVERT(VARCHAR(19), m.marka_olusturma_tarihi, 120) as marka_olusturma_tarihi,
                            CONVERT(VARCHAR(19), m.marka_guncelleme_tarihi, 120) as marka_guncelleme_tarihi,
                            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
                            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
                        FROM Markalar m
                        LEFT JOIN kullanicilar olusturan ON olusturan.kullanici_id = m.marka_olusturan_kullanici_id
                        LEFT JOIN kullanicilar guncelleyen ON guncelleyen.kullanici_id = m.marka_guncelleyen_kullanici_id
                        WHERE $whereClause
                        ORDER BY m.marka_sira_no, m.marka_adi";
                
                $markalar = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $markalar]);
                break;
                
            case 'get':
                // Tek marka getir
                $id = $_POST['id'] ?? 0;
                $sql = "SELECT * FROM Markalar WHERE marka_id = ?";
                $marka = $db->fetchOne($sql, [$id]);
                
                if ($marka) {
                    echo json_encode(['success' => true, 'data' => $marka]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Marka bulunamadı!']);
                }
                break;
                
            case 'save':
                // Yetki kontrolü
                $id = $_POST['id'] ?? 0;
                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                // Yeni marka ekle veya güncelle
                $marka_adi = trim($_POST['marka_adi'] ?? '');
                $marka_aciklama = trim($_POST['marka_aciklama'] ?? '');
                $marka_renk = trim($_POST['marka_renk'] ?? '');
                $marka_website = trim($_POST['marka_website'] ?? '');
                $marka_sira_no = $_POST['marka_sira_no'] ?? 0;
                $marka_durum = isset($_POST['marka_durum']) ? 1 : 0;
                
                if (empty($marka_adi)) {
                    echo json_encode(['success' => false, 'message' => 'Marka adı zorunludur!']);
                    break;
                }
                
                // Logo yükleme işlemi
                $marka_logo_url = null;
                if (isset($_FILES['marka_logo']) && $_FILES['marka_logo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../assets/uploads/markalar/';
                    
                    // Klasör yoksa oluştur
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['marka_logo']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                    
                    if (!in_array($fileExtension, $allowedExtensions)) {
                        echo json_encode(['success' => false, 'message' => 'Sadece resim dosyaları yüklenebilir! (jpg, jpeg, png, gif, webp, svg)']);
                        break;
                    }
                    
                    // Dosya boyutu kontrolü (max 5MB)
                    if ($_FILES['marka_logo']['size'] > 5 * 1024 * 1024) {
                        echo json_encode(['success' => false, 'message' => 'Dosya boyutu en fazla 5MB olabilir!']);
                        break;
                    }
                    
                    $newFileName = time() . '_' . uniqid() . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['marka_logo']['tmp_name'], $uploadPath)) {
                        $marka_logo_url = 'assets/uploads/markalar/' . $newFileName;
                        
                        // Eski logoyu sil (güncelleme ise)
                        if ($id > 0) {
                            $oldData = $db->fetchOne("SELECT marka_logo_url FROM Markalar WHERE marka_id = ?", [$id]);
                            if ($oldData && !empty($oldData['marka_logo_url'])) {
                                $oldFile = __DIR__ . '/../' . $oldData['marka_logo_url'];
                                if (file_exists($oldFile)) {
                                    unlink($oldFile);
                                }
                            }
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Dosya yüklenirken hata oluştu!']);
                        break;
                    }
                }
                
                if ($id > 0) {
                    // Güncelleme
                    if ($marka_logo_url) {
                        // Logo değiştiyse
                        $sql = "UPDATE Markalar SET 
                                    marka_adi = ?,
                                    marka_aciklama = ?,
                                    marka_renk = ?,
                                    marka_logo_url = ?,
                                    marka_website = ?,
                                    marka_sira_no = ?,
                                    marka_durum = ?,
                                    marka_guncelleme_tarihi = GETDATE(),
                                    marka_guncelleyen_kullanici_id = ?
                                WHERE marka_id = ?";
                        
                        $db->execute($sql, [
                            $marka_adi,
                            $marka_aciklama,
                            $marka_renk,
                            $marka_logo_url,
                            $marka_website,
                            $marka_sira_no,
                            $marka_durum,
                            $user['id'],
                            $id
                        ]);
                    } else {
                        // Logo değişmediyse
                        $sql = "UPDATE Markalar SET 
                                    marka_adi = ?,
                                    marka_aciklama = ?,
                                    marka_renk = ?,
                                    marka_website = ?,
                                    marka_sira_no = ?,
                                    marka_durum = ?,
                                    marka_guncelleme_tarihi = GETDATE(),
                                    marka_guncelleyen_kullanici_id = ?
                                WHERE marka_id = ?";
                        
                        $db->execute($sql, [
                            $marka_adi,
                            $marka_aciklama,
                            $marka_renk,
                            $marka_website,
                            $marka_sira_no,
                            $marka_durum,
                            $user['id'],
                            $id
                        ]);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Marka başarıyla güncellendi!']);
                } else {
                    // Ekleme
                    $sql = "INSERT INTO Markalar (
                                marka_adi,
                                marka_aciklama,
                                marka_renk,
                                marka_logo_url,
                                marka_website,
                                marka_sira_no,
                                marka_durum,
                                marka_olusturan_kullanici_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $db->execute($sql, [
                        $marka_adi,
                        $marka_aciklama,
                        $marka_renk,
                        $marka_logo_url,
                        $marka_website,
                        $marka_sira_no,
                        $marka_durum,
                        $user['id']
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Marka başarıyla eklendi!']);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                // Marka sil
                $id = $_POST['id'] ?? 0;
                
                // Logoyu sil
                $oldData = $db->fetchOne("SELECT marka_logo_url FROM Markalar WHERE marka_id = ?", [$id]);
                if ($oldData && !empty($oldData['marka_logo_url'])) {
                    $oldFile = __DIR__ . '/../' . $oldData['marka_logo_url'];
                    if (file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }
                
                $sql = "DELETE FROM Markalar WHERE marka_id = ?";
                $db->execute($sql, [$id]);
                
                echo json_encode(['success' => true, 'message' => 'Marka başarıyla silindi!']);
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
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <style>
        .color-box {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 1px solid #ddd;
            border-radius: 4px;
            vertical-align: middle;
        }
        .marka-img-preview {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 4px;
            border: 1px solid #ddd;
            padding: 2px;
            background: white;
        }
        #logoPreview {
            max-width: 100%;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            padding: 5px;
            background: white;
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
                                    <i class="bi bi-award"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Marka</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-globe"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Websiteli</span>
                                    <span class="info-box-number" id="stat-websiteli">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-image"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Logolu</span>
                                    <span class="info-box-number" id="stat-logolu">0</span>
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
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Marka adı, açıklama...">
                                    </div>
                                    <div class="col-md-2">
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
                    
                    <!-- Markalar Tablosu -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Markalar</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Marka
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="markaTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Logo</th>
                                        <th>Marka Adı</th>
                                        <th>Website</th>
                                        <th>Renk</th>
                                        <th>Sıra</th>
                                        <th>Durum</th>
                                        <th>Oluşturulma</th>
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
    
    <!-- Marka Modal -->
    <div class="modal fade" id="markaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Marka</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="markaForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="marka_id">
                        
                        <div class="mb-3">
                            <label for="marka_adi" class="form-label">Marka Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="marka_adi" name="marka_adi" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="marka_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="marka_aciklama" name="marka_aciklama" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="marka_logo" class="form-label">Logo</label>
                            <input type="file" class="form-control" id="marka_logo" name="marka_logo" accept="image/*">
                            <small class="text-muted">Maksimum 5MB, JPG, PNG, GIF, WEBP, SVG formatları desteklenir</small>
                            <img id="logoPreview" style="display: none;">
                            <input type="hidden" id="mevcut_logo" name="mevcut_logo">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="marka_website" class="form-label">Website</label>
                                    <input type="url" class="form-control" id="marka_website" name="marka_website" placeholder="https://ornek.com">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="marka_renk" class="form-label">Renk</label>
                                    <input type="color" class="form-control form-control-color" id="marka_renk" name="marka_renk" value="#6c757d">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="marka_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="marka_sira_no" name="marka_sira_no" value="0">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">Durum</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="marka_durum" name="marka_durum" checked>
                                        <label class="form-check-label" for="marka_durum">Aktif</label>
                                    </div>
                                </div>
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
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentFilters = {};
        const modal = new bootstrap.Modal(document.getElementById('markaModal'));
        
        // Sayfa yetkileri (PHP'den)
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        // showToast(), confirmAction(), showSuccess(), showError() artık custom.js'den geliyor
        
        // DataTable'ı başlat
        function initDataTable() {
            table = $('#markaTable').DataTable({
                processing: true,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]],
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        d.action = 'list';
                        return {...d, ...currentFilters};
                    },
                    dataSrc: function(json) {
                        if (json.success) {
                            return json.data;
                        }
                        showToast('Veri yüklenirken hata oluştu!', 'error');
                        return [];
                    }
                },
                columns: [
                    { data: 'marka_id' },
                    { 
                        data: 'marka_logo_url',
                        orderable: false,
                        render: function(data) {
                            if (data) {
                                // assets/ ile başlıyorsa /admin/ ekle
                                const logoPath = data.startsWith('assets/') ? '/admin/' + data : data;
                                return `<img src="${logoPath}" class="marka-img-preview" alt="Marka logosu">`;
                            }
                            return '<i class="bi bi-image text-muted" style="font-size: 2rem;"></i>';
                        }
                    },
                    { data: 'marka_adi' },
                    { 
                        data: 'marka_website',
                        render: function(data) {
                            if (data) {
                                return `<a href="${data}" target="_blank" class="text-decoration-none">
                                    <i class="bi bi-link-45deg"></i> ${data}
                                </a>`;
                            }
                            return '-';
                        }
                    },
                    { 
                        data: 'marka_renk',
                        render: function(data) {
                            if (data) {
                                return `<span class="color-box" style="background-color: ${data};" title="${data}"></span>`;
                            }
                            return '-';
                        }
                    },
                    { data: 'marka_sira_no' },
                    { 
                        data: 'marka_durum',
                        render: function(data) {
                            return data ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>';
                        }
                    },
                    { 
                        data: 'marka_olusturma_tarihi',
                        render: function(data) {
                            if (!data) return '-';
                            try {
                                const date = new Date(data.replace(' ', 'T'));
                                if (isNaN(date.getTime())) return '-';
                                return date.toLocaleDateString('tr-TR', {
                                    year: 'numeric',
                                    month: '2-digit',
                                    day: '2-digit'
                                });
                            } catch (e) {
                                return '-';
                            }
                        }
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: function(data) {
                            let buttons = '';
                            
                            if (permissions.canEdit) {
                                buttons += `
                                    <button class="btn btn-sm btn-warning" onclick="editMarka(${data.marka_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                `;
                            }
                            
                            if (permissions.canDelete) {
                                buttons += `
                                    <button class="btn btn-sm btn-danger" onclick="deleteMarka(${data.marka_id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                `;
                            }
                            
                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[5, 'asc'], [2, 'asc']]
            });
        }
        
        // Modal aç
        function openModal() {
            document.getElementById('modalTitle').textContent = 'Yeni Marka';
            document.getElementById('markaForm').reset();
            document.getElementById('marka_id').value = '';
            document.getElementById('marka_durum').checked = true;
            document.getElementById('logoPreview').style.display = 'none';
            document.getElementById('mevcut_logo').value = '';
            modal.show();
        }
        
        // Logo önizleme
        document.getElementById('marka_logo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('logoPreview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Marka düzenle
        function editMarka(id) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', id: id },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        document.getElementById('modalTitle').textContent = 'Marka Düzenle';
                        document.getElementById('marka_id').value = data.marka_id;
                        document.getElementById('marka_adi').value = data.marka_adi;
                        document.getElementById('marka_aciklama').value = data.marka_aciklama || '';
                        document.getElementById('marka_website').value = data.marka_website || '';
                        document.getElementById('marka_renk').value = data.marka_renk || '#6c757d';
                        document.getElementById('marka_sira_no').value = data.marka_sira_no || 0;
                        document.getElementById('marka_durum').checked = data.marka_durum == 1;
                        
                        // Mevcut logoyu göster
                        const preview = document.getElementById('logoPreview');
                        if (data.marka_logo_url) {
                            // assets/ ile başlıyorsa /admin/ ekle
                            const logoPath = data.marka_logo_url.startsWith('assets/') 
                                ? '/admin/' + data.marka_logo_url 
                                : data.marka_logo_url;
                            preview.src = logoPath;
                            preview.style.display = 'block';
                            document.getElementById('mevcut_logo').value = data.marka_logo_url;
                        } else {
                            preview.style.display = 'none';
                            document.getElementById('mevcut_logo').value = '';
                        }
                        
                        modal.show();
                    } else {
                        showToast(response.message, 'error');
                    }
                }
            });
        }
        
        // Marka sil
        function deleteMarka(id) {
            confirmAction(
                'Bu markayı silmek istediğinizden emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'delete', id: id },
                        success: function(response) {
                            if (response.success) {
                                showSuccess('Silindi!', response.message);
                                table.ajax.reload();
                                loadStats();
                            } else {
                                showError('Hata!', response.message);
                            }
                        },
                        error: function() {
                            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                        }
                    });
                }
            );
        }
        
        // Form submit
        $('#markaForm').on('submit', function(e) {
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
                    $('#stat-websiteli').text(response.data.websiteli);
                    $('#stat-logolu').text(response.data.logolu);
                    $('#stat-aktif').text(response.data.aktif);
                }
            });
        }
        
        $(document).ready(function() {
            loadStats();
            initDataTable();
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                currentFilters = {
                    search: $('#filter_search').val(),
                    status: $('#filter_status').val()
                };
                
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) delete currentFilters[key];
                });
                
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_status').val('').trigger('change.select2');
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
    </script>
</body>
</html>
