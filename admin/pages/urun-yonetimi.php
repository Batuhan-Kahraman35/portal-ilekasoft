<?php
/**
 * Admin Panel - Ürün Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

// AJAX istekleri için özel auth kontrolü
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Oturum süresi doldu. Lütfen tekrar giriş yapın.', 'redirect' => '/admin/login.php']));
    }
} else {
    requireAuth();
}

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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ürün Yönetimi';
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
            case 'list':
                // Filtreleri al
                $tip = $_POST['tip'] ?? '';
                $kategoriId = $_POST['kategori_id'] ?? '';
                $markaId = $_POST['marka_id'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $search = $_POST['search'] ?? '';
                
                // WHERE koşulları
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($tip !== '') {
                    $whereConditions[] = "uh.urun_hizmet_tip = ?";
                    $params[] = $tip;
                }
                
                if ($kategoriId) {
                    $whereConditions[] = "uh.urun_hizmet_kategori_id = ?";
                    $params[] = $kategoriId;
                }
                
                if ($markaId) {
                    $whereConditions[] = "uh.urun_hizmet_marka_id = ?";
                    $params[] = $markaId;
                }
                
                if ($durum !== '') {
                    $whereConditions[] = "uh.urun_hizmet_durum = ?";
                    $params[] = $durum;
                }
                
                if ($search) {
                    $whereConditions[] = "(uh.urun_hizmet_kodu LIKE ? OR uh.urun_hizmet_adi LIKE ? OR uh.urun_hizmet_model LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Ürün/Hizmetleri listele
                $sql = "SELECT 
                            uh.urun_hizmet_id,
                            uh.urun_hizmet_tip,
                            uh.urun_hizmet_kodu,
                            uh.urun_hizmet_adi,
                            uh.urun_hizmet_model,
                            uh.urun_hizmet_aciklama,
                            uh.urun_hizmet_kategori_id,
                            uh.urun_hizmet_marka_id,
                            uh.urun_hizmet_birim,
                            uh.urun_hizmet_kritik_stok,
                            uh.urun_hizmet_satis_fiyati,
                            uh.urun_hizmet_kdv_id,
                            uh.urun_hizmet_barkod,
                            uh.urun_hizmet_serino,
                            uh.urun_hizmet_gorsel_url,
                            uh.urun_hizmet_sira_no,
                            uh.urun_hizmet_durum,
                            CONVERT(VARCHAR(19), uh.urun_hizmet_olusturma_tarihi, 120) as urun_hizmet_olusturma_tarihi,
                            k.kategori_adi,
                            m.marka_adi,
                            kdv.kdv_adi,
                            kdv.kdv_oran
                        FROM Urun_Hizmet uh
                        LEFT JOIN Kategoriler k ON k.kategori_id = uh.urun_hizmet_kategori_id
                        LEFT JOIN Markalar m ON m.marka_id = uh.urun_hizmet_marka_id
                        LEFT JOIN KDV_Tanimlari kdv ON kdv.kdv_id = uh.urun_hizmet_kdv_id
                        WHERE $whereClause
                        ORDER BY uh.urun_hizmet_tip, uh.urun_hizmet_sira_no, uh.urun_hizmet_adi";
                
                $urunler = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $urunler]);
                break;
                
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam_urun' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Urun_Hizmet WHERE urun_hizmet_tip = 1")['sayi'] ?? 0,
                    'toplam_hizmet' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Urun_Hizmet WHERE urun_hizmet_tip = 2")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Urun_Hizmet WHERE urun_hizmet_durum = 1")['sayi'] ?? 0,
                    'kritik_stok' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Urun_Hizmet WHERE urun_hizmet_kritik_stok > 0")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'get':
                // Tek ürün getir
                $id = $_POST['id'] ?? 0;
                $sql = "SELECT * FROM Urun_Hizmet WHERE urun_hizmet_id = ?";
                $urun = $db->fetchOne($sql, [$id]);
                
                if ($urun) {
                    echo json_encode(['success' => true, 'data' => $urun]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ürün/Hizmet bulunamadı!']);
                }
                break;
                
            case 'save':
                $id = $_POST['id'] ?? 0;
                
                // Yetki kontrolü
                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                // Yeni ürün ekle veya güncelle
                $urun_hizmet_tip = intval($_POST['urun_hizmet_tip'] ?? 1);
                $urun_hizmet_kodu = trim($_POST['urun_hizmet_kodu'] ?? '');
                $urun_hizmet_adi = trim($_POST['urun_hizmet_adi'] ?? '');
                $urun_hizmet_model = trim($_POST['urun_hizmet_model'] ?? '');
                $urun_hizmet_aciklama = trim($_POST['urun_hizmet_aciklama'] ?? '');
                $urun_hizmet_kategori_id = $_POST['urun_hizmet_kategori_id'] ?? null;
                $urun_hizmet_marka_id = $_POST['urun_hizmet_marka_id'] ?? null;
                $urun_hizmet_birim = trim($_POST['urun_hizmet_birim'] ?? '');
                $urun_hizmet_kritik_stok = intval($_POST['urun_hizmet_kritik_stok'] ?? 0);
                $urun_hizmet_satis_fiyati = floatval($_POST['urun_hizmet_satis_fiyati'] ?? 0);
                $urun_hizmet_kdv_id = intval($_POST['urun_hizmet_kdv_id'] ?? 0);
                $urun_hizmet_barkod = trim($_POST['urun_hizmet_barkod'] ?? '');
                $urun_hizmet_serino = isset($_POST['urun_hizmet_serino']) ? 1 : 0;
                $urun_hizmet_sira_no = intval($_POST['urun_hizmet_sira_no'] ?? 0);
                $urun_hizmet_durum = isset($_POST['urun_hizmet_durum']) ? 1 : 0;
                
                if (empty($urun_hizmet_adi)) {
                    echo json_encode(['success' => false, 'message' => 'Ürün/Hizmet adı zorunludur!']);
                    break;
                }
                
                if ($urun_hizmet_kdv_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'KDV seçimi zorunludur!']);
                    break;
                }
                
                // Boş değerleri NULL yap
                if (empty($urun_hizmet_kodu)) {
                    // Otomatik kod üret: UH-TIMESTAMP
                    $urun_hizmet_kodu = 'UH-' . time();
                }
                if (empty($urun_hizmet_kategori_id)) $urun_hizmet_kategori_id = null;
                if (empty($urun_hizmet_marka_id)) $urun_hizmet_marka_id = null;
                if (empty($urun_hizmet_barkod)) $urun_hizmet_barkod = null;
                
                // Görsel yükleme işlemi
                $urun_hizmet_gorsel_url = null;
                if (isset($_FILES['urun_hizmet_gorsel']) && $_FILES['urun_hizmet_gorsel']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../assets/uploads/urunler/';
                    
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['urun_hizmet_gorsel']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (!in_array($fileExtension, $allowedExtensions)) {
                        echo json_encode(['success' => false, 'message' => 'Sadece resim dosyaları yüklenebilir!']);
                        break;
                    }
                    
                    if ($_FILES['urun_hizmet_gorsel']['size'] > 5 * 1024 * 1024) {
                        echo json_encode(['success' => false, 'message' => 'Dosya boyutu en fazla 5MB olabilir!']);
                        break;
                    }
                    
                    $newFileName = time() . '_' . uniqid() . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['urun_hizmet_gorsel']['tmp_name'], $uploadPath)) {
                        $urun_hizmet_gorsel_url = 'assets/uploads/urunler/' . $newFileName;
                        
                        if ($id > 0) {
                            $oldData = $db->fetchOne("SELECT urun_hizmet_gorsel_url FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$id]);
                            if ($oldData && !empty($oldData['urun_hizmet_gorsel_url'])) {
                                $oldFile = __DIR__ . '/../' . $oldData['urun_hizmet_gorsel_url'];
                                if (file_exists($oldFile)) {
                                    unlink($oldFile);
                                }
                            }
                        }
                    }
                }
                
                if ($id > 0) {
                    // Güncelleme
                    if ($urun_hizmet_gorsel_url) {
                        $sql = "UPDATE Urun_Hizmet SET 
                                    urun_hizmet_tip = ?,
                                    urun_hizmet_kodu = ?,
                                    urun_hizmet_adi = ?,
                                    urun_hizmet_model = ?,
                                    urun_hizmet_aciklama = ?,
                                    urun_hizmet_kategori_id = ?,
                                    urun_hizmet_marka_id = ?,
                                    urun_hizmet_birim = ?,
                                    urun_hizmet_kritik_stok = ?,
                                    urun_hizmet_satis_fiyati = ?,
                                    urun_hizmet_kdv_id = ?,
                                    urun_hizmet_barkod = ?,
                                    urun_hizmet_serino = ?,
                                    urun_hizmet_gorsel_url = ?,
                                    urun_hizmet_sira_no = ?,
                                    urun_hizmet_durum = ?,
                                    urun_hizmet_guncelleme_tarihi = GETDATE(),
                                    urun_hizmet_guncelleyen_kullanici_id = ?
                                WHERE urun_hizmet_id = ?";
                        
                        $db->execute($sql, [
                            $urun_hizmet_tip, $urun_hizmet_kodu, $urun_hizmet_adi, $urun_hizmet_model,
                            $urun_hizmet_aciklama, $urun_hizmet_kategori_id, $urun_hizmet_marka_id,
                            $urun_hizmet_birim, $urun_hizmet_kritik_stok, $urun_hizmet_satis_fiyati,
                            $urun_hizmet_kdv_id, $urun_hizmet_barkod, $urun_hizmet_serino, $urun_hizmet_gorsel_url,
                            $urun_hizmet_sira_no, $urun_hizmet_durum, $user['id'], $id
                        ]);
                    } else {
                        $sql = "UPDATE Urun_Hizmet SET 
                                    urun_hizmet_tip = ?,
                                    urun_hizmet_kodu = ?,
                                    urun_hizmet_adi = ?,
                                    urun_hizmet_model = ?,
                                    urun_hizmet_aciklama = ?,
                                    urun_hizmet_kategori_id = ?,
                                    urun_hizmet_marka_id = ?,
                                    urun_hizmet_birim = ?,
                                    urun_hizmet_kritik_stok = ?,
                                    urun_hizmet_satis_fiyati = ?,
                                    urun_hizmet_kdv_id = ?,
                                    urun_hizmet_barkod = ?,
                                    urun_hizmet_serino = ?,
                                    urun_hizmet_sira_no = ?,
                                    urun_hizmet_durum = ?,
                                    urun_hizmet_guncelleme_tarihi = GETDATE(),
                                    urun_hizmet_guncelleyen_kullanici_id = ?
                                WHERE urun_hizmet_id = ?";
                        
                        $db->execute($sql, [
                            $urun_hizmet_tip, $urun_hizmet_kodu, $urun_hizmet_adi, $urun_hizmet_model,
                            $urun_hizmet_aciklama, $urun_hizmet_kategori_id, $urun_hizmet_marka_id,
                            $urun_hizmet_birim, $urun_hizmet_kritik_stok, $urun_hizmet_satis_fiyati,
                            $urun_hizmet_kdv_id, $urun_hizmet_barkod, $urun_hizmet_serino, $urun_hizmet_sira_no,
                            $urun_hizmet_durum, $user['id'], $id
                        ]);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Ürün/Hizmet başarıyla güncellendi!']);
                } else {
                    // Ekleme
                    $sql = "INSERT INTO Urun_Hizmet (
                                urun_hizmet_tip, urun_hizmet_kodu, urun_hizmet_adi, urun_hizmet_model,
                                urun_hizmet_aciklama, urun_hizmet_kategori_id, urun_hizmet_marka_id,
                                urun_hizmet_birim, urun_hizmet_kritik_stok, urun_hizmet_satis_fiyati,
                                urun_hizmet_kdv_id, urun_hizmet_barkod, urun_hizmet_serino, urun_hizmet_gorsel_url,
                                urun_hizmet_sira_no, urun_hizmet_durum, urun_hizmet_olusturma_tarihi, urun_hizmet_olusturan_kullanici_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)";
                    
                    $params = [
                        $urun_hizmet_tip, $urun_hizmet_kodu, $urun_hizmet_adi, $urun_hizmet_model,
                        $urun_hizmet_aciklama, $urun_hizmet_kategori_id, $urun_hizmet_marka_id,
                        $urun_hizmet_birim, $urun_hizmet_kritik_stok, $urun_hizmet_satis_fiyati,
                        $urun_hizmet_kdv_id, $urun_hizmet_barkod, $urun_hizmet_serino, $urun_hizmet_gorsel_url,
                        $urun_hizmet_sira_no, $urun_hizmet_durum, $user['id']
                    ];
                    
                    $result = $db->execute($sql, $params);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Ürün/Hizmet başarıyla eklendi!']);
                    } else {
                        // Hata detayını logla ve göster
                        $errors = sqlsrv_errors();
                        $errorMsg = $errors ? $errors[0]['message'] : 'Bilinmeyen hata';
                        error_log('Urun INSERT Hatasi: ' . print_r($errors, true));
                        error_log('Params: ' . print_r($params, true));
                        echo json_encode(['success' => false, 'message' => 'Kayıt eklenirken hata: ' . $errorMsg]);
                    }
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                
                // Görseli sil
                $oldData = $db->fetchOne("SELECT urun_hizmet_gorsel_url FROM Urun_Hizmet WHERE urun_hizmet_id = ?", [$id]);
                if ($oldData && !empty($oldData['urun_hizmet_gorsel_url'])) {
                    $oldFile = __DIR__ . '/../' . $oldData['urun_hizmet_gorsel_url'];
                    if (file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }
                
                $sql = "DELETE FROM Urun_Hizmet WHERE urun_hizmet_id = ?";
                $db->execute($sql, [$id]);
                
                echo json_encode(['success' => true, 'message' => 'Ürün/Hizmet başarıyla silindi!']);
                break;
                
            case 'get_kategoriler':
                $sql = "SELECT kategori_id, kategori_adi FROM Kategoriler WHERE kategori_durum = 1 ORDER BY kategori_sira_no, kategori_adi";
                $kategoriler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $kategoriler]);
                break;
                
            case 'get_markalar':
                $sql = "SELECT marka_id, marka_adi FROM Markalar WHERE marka_durum = 1 ORDER BY marka_sira_no, marka_adi";
                $markalar = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $markalar]);
                break;
                
            case 'get_kdv':
                $sql = "SELECT kdv_id, kdv_adi, kdv_oran FROM KDV_Tanimlari WHERE kdv_durum = 1 ORDER BY kdv_sira_no, kdv_oran DESC";
                $kdvler = $db->fetchAll($sql);
                echo json_encode(['success' => true, 'data' => $kdvler]);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <style>
        .urun-img-preview {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 4px;
            border: 1px solid #ddd;
            padding: 2px;
            background: white;
        }
        #gorselPreview {
            max-width: 100%;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            padding: 5px;
            background: white;
        }
        .tip-badge {
            font-weight: 600;
        }
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
                                    <i class="bi bi-box-seam"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Ürün</span>
                                    <span class="info-box-number" id="stat-urun">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-tools"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hizmet</span>
                                    <span class="info-box-number" id="stat-hizmet">0</span>
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
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kritik Stok</span>
                                    <span class="info-box-number" id="stat-kritik">0</span>
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
                                    <div class="col-md-2">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <option value="1">Ürün</option>
                                            <option value="2">Hizmet</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Kategori -->
                                    <div class="col-md-3">
                                        <label class="form-label">Kategori</label>
                                        <select class="form-select" name="kategori_id" id="filter_kategori_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Marka -->
                                    <div class="col-md-3">
                                        <label class="form-label">Marka</label>
                                        <select class="form-select" name="marka_id" id="filter_marka_id">
                                            <option value="">Tümü</option>
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
                                    <div class="col-md-2">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kod, ad, model...">
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
                    
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Ürün ve Hizmetler</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="urunTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tip</th>
                                        <th>Görsel</th>
                                        <th>Kod</th>
                                        <th>Ad</th>
                                        <th>Model</th>
                                        <th>Kategori</th>
                                        <th>Marka</th>
                                        <th>Fiyat</th>
                                        <th>KDV</th>
                                        <th>Durum</th>
                                        <th>İşlemler</th>
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
    
    <!-- Modal -->
    <div class="modal fade" id="urunModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Ürün/Hizmet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="urunForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="urun_hizmet_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Tip <span class="text-danger">*</span></label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="urun_hizmet_tip" id="tip_urun" value="1" checked>
                                    <label class="form-check-label" for="tip_urun">
                                        <i class="bi bi-box-seam"></i> Ürün
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="urun_hizmet_tip" id="tip_hizmet" value="2">
                                    <label class="form-check-label" for="tip_hizmet">
                                        <i class="bi bi-tools"></i> Hizmet
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kodu" class="form-label">Kod</label>
                                    <input type="text" class="form-control" id="urun_hizmet_kodu" name="urun_hizmet_kodu">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_adi" class="form-label">Ad <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="urun_hizmet_adi" name="urun_hizmet_adi" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="urun_hizmet_model" class="form-label">Model</label>
                            <input type="text" class="form-control" id="urun_hizmet_model" name="urun_hizmet_model">
                        </div>
                        
                        <div class="mb-3">
                            <label for="urun_hizmet_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="urun_hizmet_aciklama" name="urun_hizmet_aciklama" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kategori_id" class="form-label">Kategori</label>
                                    <select class="form-select" id="urun_hizmet_kategori_id" name="urun_hizmet_kategori_id">
                                        <option value="">Seçiniz</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_marka_id" class="form-label">Marka</label>
                                    <select class="form-select" id="urun_hizmet_marka_id" name="urun_hizmet_marka_id">
                                        <option value="">Seçiniz</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_birim" class="form-label">Birim</label>
                                    <select class="form-select" id="urun_hizmet_birim" name="urun_hizmet_birim">
                                        <option value="">Seçiniz</option>
                                        <option value="Adet">Adet</option>
                                        <option value="Kg">Kg</option>
                                        <option value="Gram">Gram</option>
                                        <option value="Litre">Litre</option>
                                        <option value="Metre">Metre</option>
                                        <option value="M2">M²</option>
                                        <option value="M3">M³</option>
                                        <option value="Paket">Paket</option>
                                        <option value="Koli">Koli</option>
                                        <option value="Saat">Saat</option>
                                        <option value="Gun">Gün</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kritik_stok" class="form-label">Kritik Stok</label>
                                    <input type="number" class="form-control" id="urun_hizmet_kritik_stok" name="urun_hizmet_kritik_stok" value="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="urun_hizmet_satis_fiyati" class="form-label">Satış Fiyatı</label>
                                    <input type="number" step="0.01" class="form-control" id="urun_hizmet_satis_fiyati" name="urun_hizmet_satis_fiyati" value="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_kdv_id" class="form-label">KDV <span class="text-danger">*</span></label>
                                    <select class="form-select" id="urun_hizmet_kdv_id" name="urun_hizmet_kdv_id" required>
                                        <option value="">Seçiniz</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_barkod" class="form-label">Barkod</label>
                                    <input type="text" class="form-control" id="urun_hizmet_barkod" name="urun_hizmet_barkod">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">Seri No Takipli</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="urun_hizmet_serino" name="urun_hizmet_serino">
                                        <label class="form-check-label" for="urun_hizmet_serino">
                                            Bu ürün seri no ile takip edilsin
                                            <small class="text-muted d-block">Stok giriş/çıkışta seri no zorunlu olur</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="urun_hizmet_gorsel" class="form-label">Görsel</label>
                            <input type="file" class="form-control" id="urun_hizmet_gorsel" name="urun_hizmet_gorsel" accept="image/*">
                            <small class="text-muted">Maksimum 5MB</small>
                            <img id="gorselPreview" style="display: none;">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="urun_hizmet_sira_no" class="form-label">Sıra No</label>
                                    <input type="number" class="form-control" id="urun_hizmet_sira_no" name="urun_hizmet_sira_no" value="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label d-block">Durum</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="urun_hizmet_durum" name="urun_hizmet_durum" checked>
                                        <label class="form-check-label" for="urun_hizmet_durum">Aktif</label>
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
    
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentFilters = {};
        const modal = new bootstrap.Modal(document.getElementById('urunModal'));
        
        // Sayfa yetkileri (PHP'den)
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-urun').text(response.data.toplam_urun);
                    $('#stat-hizmet').text(response.data.toplam_hizmet);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-kritik').text(response.data.kritik_stok);
                }
            });
        }
        
        function initDataTable() {
            table = $('#urunTable').DataTable({
                processing: true,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'list', ...currentFilters };
                    },
                    dataSrc: json => json.success ? json.data : []
                },
                columns: [
                    { data: 'urun_hizmet_id' },
                    { 
                        data: 'urun_hizmet_tip',
                        render: data => data == 1 
                            ? '<span class="badge bg-primary tip-badge"><i class="bi bi-box-seam"></i> Ürün</span>'
                            : '<span class="badge bg-info tip-badge"><i class="bi bi-tools"></i> Hizmet</span>'
                    },
                    { 
                        data: 'urun_hizmet_gorsel_url',
                        orderable: false,
                        render: data => {
                            if (!data) return '<i class="bi bi-image text-muted" style="font-size: 2rem;"></i>';
                            const imgPath = data.startsWith('assets/') ? '/admin/' + data : data;
                            return `<img src="${imgPath}" class="urun-img-preview">`;
                        }
                    },
                    { data: 'urun_hizmet_kodu', defaultContent: '-' },
                    { data: 'urun_hizmet_adi' },
                    { data: 'urun_hizmet_model', defaultContent: '-' },
                    { data: 'kategori_adi', defaultContent: '-' },
                    { data: 'marka_adi', defaultContent: '-' },
                    { 
                        data: 'urun_hizmet_satis_fiyati',
                        render: data => new Intl.NumberFormat('tr-TR', { 
                            style: 'currency', 
                            currency: 'TRY' 
                        }).format(data)
                    },
                    { 
                        data: null,
                        render: data => `%${data.kdv_oran}`
                    },
                    { 
                        data: 'urun_hizmet_durum',
                        render: data => data 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-danger">Pasif</span>'
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: function(data) {
                            let buttons = '';
                            
                            if (permissions.canEdit) {
                                buttons += `
                                    <button class="btn btn-sm btn-warning" onclick="editUrun(${data.urun_hizmet_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                `;
                            }
                            
                            if (permissions.canDelete) {
                                buttons += `
                                    <button class="btn btn-sm btn-danger" onclick="deleteUrun(${data.urun_hizmet_id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                `;
                            }
                            
                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[1, 'asc'], [4, 'asc']]
            });
        }
        
        function loadKategoriler(targetSelect = '#urun_hizmet_kategori_id') {
            $.post('', { action: 'get_kategoriler' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(k => select.append(`<option value="${k.kategori_id}">${k.kategori_adi}</option>`));
                }
            });
        }
        
        function loadMarkalar(targetSelect = '#urun_hizmet_marka_id') {
            $.post('', { action: 'get_markalar' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(m => select.append(`<option value="${m.marka_id}">${m.marka_adi}</option>`));
                }
            });
        }
        
        function loadKDV() {
            $.post('', { action: 'get_kdv' }, response => {
                if (response.success) {
                    const select = $('#urun_hizmet_kdv_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(kdv => select.append(`<option value="${kdv.kdv_id}">${kdv.kdv_adi} (%${kdv.kdv_oran})</option>`));
                }
            });
        }
        
        function openModal() {
            document.getElementById('modalTitle').textContent = 'Yeni Ürün/Hizmet';
            document.getElementById('urunForm').reset();
            document.getElementById('urun_hizmet_id').value = '';
            document.getElementById('urun_hizmet_durum').checked = true;
            document.getElementById('gorselPreview').style.display = 'none';
            loadKategoriler();
            loadMarkalar();
            loadKDV();
            modal.show();
        }
        
        document.getElementById('urun_hizmet_gorsel').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = e => {
                    const preview = document.getElementById('gorselPreview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        function editUrun(id) {
            $.post('', { action: 'get', id: id }, response => {
                if (response.success) {
                    const data = response.data;
                    document.getElementById('modalTitle').textContent = 'Ürün/Hizmet Düzenle';
                    document.getElementById('urun_hizmet_id').value = data.urun_hizmet_id;
                    
                    if (data.urun_hizmet_tip == 1) {
                        document.getElementById('tip_urun').checked = true;
                    } else {
                        document.getElementById('tip_hizmet').checked = true;
                    }
                    
                    document.getElementById('urun_hizmet_kodu').value = data.urun_hizmet_kodu || '';
                    document.getElementById('urun_hizmet_adi').value = data.urun_hizmet_adi;
                    document.getElementById('urun_hizmet_model').value = data.urun_hizmet_model || '';
                    document.getElementById('urun_hizmet_aciklama').value = data.urun_hizmet_aciklama || '';
                    document.getElementById('urun_hizmet_birim').value = data.urun_hizmet_birim || '';
                    document.getElementById('urun_hizmet_kritik_stok').value = data.urun_hizmet_kritik_stok || 0;
                    document.getElementById('urun_hizmet_satis_fiyati').value = data.urun_hizmet_satis_fiyati || 0;
                    document.getElementById('urun_hizmet_barkod').value = data.urun_hizmet_barkod || '';
                    document.getElementById('urun_hizmet_serino').checked = data.urun_hizmet_serino == 1;
                    document.getElementById('urun_hizmet_sira_no').value = data.urun_hizmet_sira_no || 0;
                    document.getElementById('urun_hizmet_durum').checked = data.urun_hizmet_durum == 1;
                    
                    loadKategoriler();
                    loadMarkalar();
                    loadKDV();
                    
                    setTimeout(() => {
                        document.getElementById('urun_hizmet_kategori_id').value = data.urun_hizmet_kategori_id || '';
                        document.getElementById('urun_hizmet_marka_id').value = data.urun_hizmet_marka_id || '';
                        document.getElementById('urun_hizmet_kdv_id').value = data.urun_hizmet_kdv_id || '';
                    }, 200);
                    
                    const preview = document.getElementById('gorselPreview');
                    if (data.urun_hizmet_gorsel_url) {
                        preview.src = '../' + data.urun_hizmet_gorsel_url;
                        preview.style.display = 'block';
                    } else {
                        preview.style.display = 'none';
                    }
                    
                    modal.show();
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        function deleteUrun(id) {
            confirmAction(
                'Bu ürün/hizmeti silmek istediğinizden emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, response => {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            table.ajax.reload();
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
        
        $('#urunForm').on('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: response => {
                    if (response.success) {
                        showToast(response.message, 'success');
                        modal.hide();
                        table.ajax.reload();
                        loadStats();
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr.responseText);
                    showToast('Sunucu hatası: ' + error, 'error');
                }
            });
        });
        
        $(document).ready(() => {
            loadStats();
            initDataTable();
            
            // Filtre dropdown'larını doldur
            loadKategoriler('#filter_kategori_id');
            loadMarkalar('#filter_marka_id');
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                // Filtreleri topla
                currentFilters = {
                    tip: $('#filter_tip').val(),
                    kategori_id: $('#filter_kategori_id').val(),
                    marka_id: $('#filter_marka_id').val(),
                    durum: $('#filter_durum').val(),
                    search: $('#filter_search').val()
                };
                
                // Boş değerleri kaldır
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) delete currentFilters[key];
                });
                
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip').val('').trigger('change.select2');
                $('#filter_kategori_id').val('').trigger('change.select2');
                $('#filter_marka_id').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
    </script>
</body>
</html>
