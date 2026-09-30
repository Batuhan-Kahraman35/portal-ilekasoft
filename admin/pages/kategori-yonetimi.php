<?php
/**
 * Admin Panel - Kategori Yönetimi
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Kategori Yönetimi';
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
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Kategoriler")['sayi'] ?? 0,
                    'ana_kategori' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Kategoriler WHERE kategori_parent_id IS NULL")['sayi'] ?? 0,
                    'alt_kategori' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Kategoriler WHERE kategori_parent_id IS NOT NULL")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Kategoriler WHERE kategori_durum = 1")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Kategorileri listele
                $sql = "SELECT 
                            k.kategori_id,
                            k.kategori_adi,
                            k.kategori_aciklama,
                            k.kategori_renk,
                            k.kategori_gorsel_url,
                            k.kategori_parent_id,
                            k.kategori_sira_no,
                            k.kategori_durum,
                            CONVERT(VARCHAR(19), k.kategori_olusturma_tarihi, 120) as kategori_olusturma_tarihi,
                            CONVERT(VARCHAR(19), k.kategori_guncelleme_tarihi, 120) as kategori_guncelleme_tarihi,
                            parent.kategori_adi as parent_kategori_adi,
                            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
                            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
                        FROM Kategoriler k
                        LEFT JOIN Kategoriler parent ON parent.kategori_id = k.kategori_parent_id
                        LEFT JOIN kullanicilar olusturan ON olusturan.kullanici_id = k.kategori_olusturan_kullanici_id
                        LEFT JOIN kullanicilar guncelleyen ON guncelleyen.kullanici_id = k.kategori_guncelleyen_kullanici_id
                        ORDER BY ISNULL(k.kategori_parent_id, 0), k.kategori_sira_no, k.kategori_adi";
                
                $kategoriler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $kategoriler]);
                break;
                
            case 'get':
                // Tek kategori getir
                $id = $_POST['id'] ?? 0;
                $sql = "SELECT * FROM Kategoriler WHERE kategori_id = ?";
                $kategori = $db->fetchOne($sql, [$id]);
                
                if ($kategori) {
                    echo json_encode(['success' => true, 'data' => $kategori]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kategori bulunamadı!']);
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
                
                // Yeni kategori ekle veya güncelle
                $kategori_adi = trim($_POST['kategori_adi'] ?? '');
                $kategori_aciklama = trim($_POST['kategori_aciklama'] ?? '');
                $kategori_renk = trim($_POST['kategori_renk'] ?? '');
                $kategori_parent_id = $_POST['kategori_parent_id'] ?? null;
                $kategori_sira_no = $_POST['kategori_sira_no'] ?? 0;
                $kategori_durum = isset($_POST['kategori_durum']) ? 1 : 0;
                
                if (empty($kategori_adi)) {
                    echo json_encode(['success' => false, 'message' => 'Kategori adı zorunludur!']);
                    break;
                }
                
                // Parent ID boşsa null yap
                if (empty($kategori_parent_id)) {
                    $kategori_parent_id = null;
                }
                
                // Görsel yükleme işlemi
                $kategori_gorsel_url = null;
                if (isset($_FILES['kategori_gorsel']) && $_FILES['kategori_gorsel']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../assets/uploads/kategoriler/';
                    
                    // Klasör yoksa oluştur
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['kategori_gorsel']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (!in_array($fileExtension, $allowedExtensions)) {
                        echo json_encode(['success' => false, 'message' => 'Sadece resim dosyaları yüklenebilir! (jpg, jpeg, png, gif, webp)']);
                        break;
                    }
                    
                    // Dosya boyutu kontrolü (max 5MB)
                    if ($_FILES['kategori_gorsel']['size'] > 5 * 1024 * 1024) {
                        echo json_encode(['success' => false, 'message' => 'Dosya boyutu en fazla 5MB olabilir!']);
                        break;
                    }
                    
                    $newFileName = time() . '_' . uniqid() . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['kategori_gorsel']['tmp_name'], $uploadPath)) {
                        $kategori_gorsel_url = 'assets/uploads/kategoriler/' . $newFileName;
                        
                        // Eski görseli sil (güncelleme ise)
                        if ($id > 0) {
                            $oldData = $db->fetchOne("SELECT kategori_gorsel_url FROM Kategoriler WHERE kategori_id = ?", [$id]);
                            if ($oldData && !empty($oldData['kategori_gorsel_url'])) {
                                $oldFile = __DIR__ . '/../' . $oldData['kategori_gorsel_url'];
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
                    if ($kategori_gorsel_url) {
                        // Görsel değiştiyse
                        $sql = "UPDATE Kategoriler SET 
                                    kategori_adi = ?,
                                    kategori_aciklama = ?,
                                    kategori_renk = ?,
                                    kategori_gorsel_url = ?,
                                    kategori_parent_id = ?,
                                    kategori_sira_no = ?,
                                    kategori_durum = ?,
                                    kategori_guncelleme_tarihi = GETDATE(),
                                    kategori_guncelleyen_kullanici_id = ?
                                WHERE kategori_id = ?";
                        
                        $db->execute($sql, [
                            $kategori_adi,
                            $kategori_aciklama,
                            $kategori_renk,
                            $kategori_gorsel_url,
                            $kategori_parent_id,
                            $kategori_sira_no,
                            $kategori_durum,
                            $user['id'],
                            $id
                        ]);
                    } else {
                        // Görsel değişmediyse
                        $sql = "UPDATE Kategoriler SET 
                                    kategori_adi = ?,
                                    kategori_aciklama = ?,
                                    kategori_renk = ?,
                                    kategori_parent_id = ?,
                                    kategori_sira_no = ?,
                                    kategori_durum = ?,
                                    kategori_guncelleme_tarihi = GETDATE(),
                                    kategori_guncelleyen_kullanici_id = ?
                                WHERE kategori_id = ?";
                        
                        $db->execute($sql, [
                            $kategori_adi,
                            $kategori_aciklama,
                            $kategori_renk,
                            $kategori_parent_id,
                            $kategori_sira_no,
                            $kategori_durum,
                            $user['id'],
                            $id
                        ]);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Kategori başarıyla güncellendi!']);
                } else {
                    // Ekleme
                    $sql = "INSERT INTO Kategoriler (
                                kategori_adi,
                                kategori_aciklama,
                                kategori_renk,
                                kategori_gorsel_url,
                                kategori_parent_id,
                                kategori_sira_no,
                                kategori_durum,
                                kategori_olusturan_kullanici_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $db->execute($sql, [
                        $kategori_adi,
                        $kategori_aciklama,
                        $kategori_renk,
                        $kategori_gorsel_url,
                        $kategori_parent_id,
                        $kategori_sira_no,
                        $kategori_durum,
                        $user['id']
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Kategori başarıyla eklendi!']);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                // Kategori sil
                $id = $_POST['id'] ?? 0;
                
                // Alt kategorileri kontrol et
                $checkSql = "SELECT COUNT(*) as sayi FROM Kategoriler WHERE kategori_parent_id = ?";
                $result = $db->fetchOne($checkSql, [$id]);
                
                if ($result['sayi'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu kategorinin alt kategorileri var! Önce alt kategorileri silin.']);
                    break;
                }
                
                // Görseli sil
                $oldData = $db->fetchOne("SELECT kategori_gorsel_url FROM Kategoriler WHERE kategori_id = ?", [$id]);
                if ($oldData && !empty($oldData['kategori_gorsel_url'])) {
                    $oldFile = __DIR__ . '/../' . $oldData['kategori_gorsel_url'];
                    if (file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }
                
                $sql = "DELETE FROM Kategoriler WHERE kategori_id = ?";
                $db->execute($sql, [$id]);
                
                echo json_encode(['success' => true, 'message' => 'Kategori başarıyla silindi!']);
                break;
                
            case 'get_parent_categories':
                // Ana kategorileri getir (parent_id NULL olanlar)
                $sql = "SELECT kategori_id, kategori_adi 
                        FROM Kategoriler 
                        WHERE kategori_parent_id IS NULL AND kategori_durum = 1
                        ORDER BY kategori_sira_no, kategori_adi";
                
                $kategoriler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $kategoriler]);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
    <style>
        .color-box {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 1px solid #ddd;
            border-radius: 4px;
            vertical-align: middle;
        }
        .kategori-badge {
            padding: 0.35rem 0.65rem;
            border-radius: 0.25rem;
            color: white;
            font-weight: 500;
        }
        .kategori-img-preview {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        #gorselPreview {
            max-width: 100%;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
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
                                    <i class="bi bi-grid-3x3-gap"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kategori</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-diagram-3"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ana Kategori</span>
                                    <span class="info-box-number" id="stat-ana">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-diagram-2"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Alt Kategori</span>
                                    <span class="info-box-number" id="stat-alt">0</span>
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
                    
                    <!-- Kategoriler Tablosu -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Kategoriler</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Kategori
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="kategoriTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Görsel</th>
                                        <th>Kategori Adı</th>
                                        <th>Üst Kategori</th>
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
    
    <!-- Kategori Modal -->
    <div class="modal fade" id="kategoriModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Kategori</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="kategoriForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="kategori_id">
                        
                        <div class="mb-3">
                            <label for="kategori_adi" class="form-label">Kategori Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kategori_adi" name="kategori_adi" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="kategori_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="kategori_aciklama" name="kategori_aciklama" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="kategori_gorsel" class="form-label">Görsel</label>
                            <input type="file" class="form-control" id="kategori_gorsel" name="kategori_gorsel" accept="image/*">
                            <small class="text-muted">Maksimum 5MB, JPG, PNG, GIF, WEBP formatları desteklenir</small>
                            <img id="gorselPreview" style="display: none;">
                            <input type="hidden" id="mevcut_gorsel" name="mevcut_gorsel">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="kategori_parent_id" class="form-label">Üst Kategori</label>
                                    <select class="form-select" id="kategori_parent_id" name="kategori_parent_id">
                                        <option value="">Ana Kategori</option>
                                    </select>
                                    <small class="text-muted">Boş bırakılırsa ana kategori olur</small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="kategori_renk" class="form-label">Renk</label>
                                    <input type="color" class="form-control form-control-color" id="kategori_renk" name="kategori_renk" value="#6c757d">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="kategori_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="kategori_sira_no" name="kategori_sira_no" value="0">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">Durum</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="kategori_durum" name="kategori_durum" checked>
                                        <label class="form-check-label" for="kategori_durum">Aktif</label>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
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
        const modal = new bootstrap.Modal(document.getElementById('kategoriModal'));
        
        // showToast() artık custom.js'den geliyor
        
        // DataTable'ı başlat
        function initDataTable() {
            table = $('#kategoriTable').DataTable({
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
                    { data: 'kategori_id' },
                    { 
                        data: 'kategori_gorsel_url',
                        orderable: false,
                        render: function(data) {
                            if (!data) return '<i class="bi bi-image text-muted" style="font-size: 2rem;"></i>';
                            const imgPath = data.startsWith('assets/') ? '/admin/' + data : data;
                            return `<img src="${imgPath}" class="kategori-img-preview" alt="Kategori görseli">`;
                        }
                    },
                    { 
                        data: null,
                        render: function(data) {
                            let html = '';
                            if (data.kategori_parent_id) {
                                html += '<span class="ms-3">└─ </span>';
                            }
                            html += data.kategori_adi;
                            return html;
                        }
                    },
                    { 
                        data: 'parent_kategori_adi',
                        defaultContent: '<span class="badge bg-secondary">Ana Kategori</span>'
                    },
                    { 
                        data: 'kategori_renk',
                        render: function(data) {
                            if (data) {
                                return `<span class="color-box" style="background-color: ${data};" title="${data}"></span>`;
                            }
                            return '-';
                        }
                    },
                    { data: 'kategori_sira_no' },
                    { 
                        data: 'kategori_durum',
                        render: function(data) {
                            return data ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>';
                        }
                    },
                    { 
                        data: 'kategori_olusturma_tarihi',
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
                            if (pagePermissions.can_edit) {
                                buttons += `<button class="btn btn-sm btn-warning" onclick="editKategori(${data.kategori_id})" title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </button> `;
                            }
                            if (pagePermissions.can_delete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="deleteKategori(${data.kategori_id})" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }
                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[5, 'asc'], [2, 'asc']]
            });
        }
        
        // Ana kategorileri yükle
        function loadParentCategories() {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get_parent_categories' },
                success: function(response) {
                    if (response.success) {
                        const select = $('#kategori_parent_id');
                        select.find('option:not(:first)').remove();
                        
                        response.data.forEach(function(kategori) {
                            select.append(`<option value="${kategori.kategori_id}">${kategori.kategori_adi}</option>`);
                        });
                    }
                }
            });
        }
        
        // Modal aç
        function openModal() {
            document.getElementById('modalTitle').textContent = 'Yeni Kategori';
            document.getElementById('kategoriForm').reset();
            document.getElementById('kategori_id').value = '';
            document.getElementById('kategori_durum').checked = true;
            document.getElementById('gorselPreview').style.display = 'none';
            document.getElementById('mevcut_gorsel').value = '';
            loadParentCategories();
            modal.show();
        }
        
        // Görsel önizleme
        document.getElementById('kategori_gorsel').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('gorselPreview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Kategori düzenle
        function editKategori(id) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', id: id },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        document.getElementById('modalTitle').textContent = 'Kategori Düzenle';
                        document.getElementById('kategori_id').value = data.kategori_id;
                        document.getElementById('kategori_adi').value = data.kategori_adi;
                        document.getElementById('kategori_aciklama').value = data.kategori_aciklama || '';
                        document.getElementById('kategori_renk').value = data.kategori_renk || '#6c757d';
                        document.getElementById('kategori_sira_no').value = data.kategori_sira_no || 0;
                        document.getElementById('kategori_durum').checked = data.kategori_durum == 1;
                        
                        // Mevcut görseli göster
                        const preview = document.getElementById('gorselPreview');
                        if (data.kategori_gorsel_url) {
                            preview.src = '../' + data.kategori_gorsel_url;
                            preview.style.display = 'block';
                            document.getElementById('mevcut_gorsel').value = data.kategori_gorsel_url;
                        } else {
                            preview.style.display = 'none';
                            document.getElementById('mevcut_gorsel').value = '';
                        }
                        
                        loadParentCategories();
                        setTimeout(() => {
                            document.getElementById('kategori_parent_id').value = data.kategori_parent_id || '';
                        }, 100);
                        
                        modal.show();
                    } else {
                        showToast(response.message, 'error');
                    }
                }
            });
        }
        
        // Kategori sil
        function deleteKategori(id) {
            confirmAction(
                'Bu kategoriyi silmek istediğinizden emin misiniz?',
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
        $('#kategoriForm').on('submit', function(e) {
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
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-ana').text(response.data.ana_kategori);
                    $('#stat-alt').text(response.data.alt_kategori);
                    $('#stat-aktif').text(response.data.aktif);
                }
            });
        }
        
        // Sayfa yüklendiğinde
        $(document).ready(function() {
            loadStats();
            initDataTable();
        });
    </script>
</body>
</html>
