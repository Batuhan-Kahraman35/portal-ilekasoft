<?php
/**
 * Admin Panel - Banka ve IBAN Yönetimi
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Banka ve IBAN Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'stats') {
            $stats = [
                'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Banka_Hesap")['sayi'] ?? 0,
                'firma_hesaplari' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Banka_Hesap WHERE bankaHesap_firma_id IS NOT NULL")['sayi'] ?? 0,
                'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Banka_Hesap WHERE bankaHesap_durum = 1")['sayi'] ?? 0,
                'otomatik' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Banka_Hesap WHERE bankaHesap_otomatik = 1")['sayi'] ?? 0
            ];
            
            echo json_encode(['success' => true, 'data' => $stats]);
            exit;
        }
        
        if ($action === 'get_firmalar') {
            $firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
            echo json_encode(['success' => true, 'data' => $firmalar]);
            exit;
        }
        
        if ($action === 'get_bankalar') {
            $bankalar = $db->fetchAll("SELECT banka_id, banka_adi, banka_kodu FROM bankalar WHERE banka_durum = 1 ORDER BY banka_adi");
            echo json_encode(['success' => true, 'data' => $bankalar]);
            exit;
        }
        
        if ($action === 'get_apiKimlikler') {
            $apiKimlikler = $db->fetchAll("
                SELECT 
                    ak.apiKimlik_id,
                    ak.apiKimlik_firma_id,
                    ak.apiKimlik_banka_id,
                    ak.apiKimlik_aciklama,
                    b.banka_adi,
                    f.firma_adi
                FROM banka_ApiKimlik ak
                INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
                LEFT JOIN Firmalar f ON ak.apiKimlik_firma_id = f.firma_id
                WHERE ak.apiKimlik_durum = 1
                ORDER BY b.banka_adi, f.firma_adi
            ");
            echo json_encode(['success' => true, 'data' => $apiKimlikler]);
            exit;
        }
        
        // Banka Tanımları İşlemleri
        if ($action === 'list_tanimlar') {
            $tanimlar = $db->fetchAll("
                SELECT 
                    banka_id,
                    banka_adi,
                    banka_kodu,
                    banka_logo_url,
                    banka_sira_no,
                    banka_personel,
                    banka_durum
                FROM bankalar
                ORDER BY banka_sira_no, banka_adi
            ");
            echo json_encode(['success' => true, 'data' => $tanimlar]);
            exit;
        }
        
        if ($action === 'save_tanim') {
            $id = intval($_POST['id'] ?? 0);
            
            if ($id > 0 && !$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            }
            if ($id == 0 && !$pagePermissions['can_add']) {
                throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
            }
            
            $adi = trim($_POST['adi'] ?? '');
            $kodu = trim($_POST['kodu'] ?? '');
            $sira_no = intval($_POST['sira_no'] ?? 0);
            $personel = intval($_POST['personel'] ?? 0);
            $durum = intval($_POST['durum'] ?? 1);
            
            if (empty($adi)) {
                throw new Exception('Banka adı zorunludur');
            }
            
            // Logo yükleme işlemi
            $logo_url = null;
            $logoSil = intval($_POST['logo_sil'] ?? 0);
            
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'];
                $maxSize = 2 * 1024 * 1024; // 2MB
                
                $fileType = $_FILES['logo']['type'];
                $fileSize = $_FILES['logo']['size'];
                
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception('Geçersiz dosya türü! (PNG, JPG, GIF, SVG, WEBP)');
                }
                if ($fileSize > $maxSize) {
                    throw new Exception('Dosya boyutu 2MB\'dan büyük olamaz!');
                }
                
                $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $fileName = uniqid('banka_', true) . '.' . strtolower($ext);
                $uploadDir = __DIR__ . '/../assets/uploads/bankalar/';
                $uploadPath = $uploadDir . $fileName;
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                if (!move_uploaded_file($_FILES['logo']['tmp_name'], $uploadPath)) {
                    throw new Exception('Logo yüklenirken hata oluştu!');
                }
                
                $logo_url = '/admin/assets/uploads/bankalar/' . $fileName;
                
                // Eski logoyu sil
                if ($id > 0) {
                    $eskiBanka = $db->fetchOne("SELECT banka_logo_url FROM bankalar WHERE banka_id = ?", [$id]);
                    if ($eskiBanka && $eskiBanka['banka_logo_url']) {
                        $eskiDosya = $_SERVER['DOCUMENT_ROOT'] . $eskiBanka['banka_logo_url'];
                        if (file_exists($eskiDosya)) {
                            unlink($eskiDosya);
                        }
                    }
                }
            } elseif ($logoSil && $id > 0) {
                // Logo silme isteği
                $eskiBanka = $db->fetchOne("SELECT banka_logo_url FROM bankalar WHERE banka_id = ?", [$id]);
                if ($eskiBanka && $eskiBanka['banka_logo_url']) {
                    $eskiDosya = $_SERVER['DOCUMENT_ROOT'] . $eskiBanka['banka_logo_url'];
                    if (file_exists($eskiDosya)) {
                        unlink($eskiDosya);
                    }
                }
                $logo_url = '';
            }
            
            // Mükerrer banka adı kontrolü
            $bankaKontrol = $db->fetchOne(
                "SELECT banka_id FROM bankalar WHERE banka_adi = ? AND banka_id != ?",
                [$adi, $id]
            );
            if ($bankaKontrol) {
                throw new Exception('Bu banka adı zaten kayıtlı!');
            }
            
            if ($id > 0) {
                $updateData = [
                    'banka_adi' => $adi,
                    'banka_kodu' => $kodu ?: null,
                    'banka_sira_no' => $sira_no,
                    'banka_personel' => $personel,
                    'banka_durum' => $durum,
                    'banka_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                    'banka_guncelleyen_kullanici_id' => $user['kullanici_id']
                ];
                if ($logo_url !== null) {
                    $updateData['banka_logo_url'] = $logo_url ?: null;
                }
                $db->update('bankalar', $updateData, ['banka_id' => $id]);
                
                echo json_encode(['success' => true, 'message' => 'Banka güncellendi']);
            } else {
                $insertData = [
                    'banka_adi' => $adi,
                    'banka_kodu' => $kodu ?: null,
                    'banka_sira_no' => $sira_no,
                    'banka_personel' => $personel,
                    'banka_durum' => $durum,
                    'banka_olusturan_kullanici_id' => $user['kullanici_id']
                ];
                if ($logo_url) {
                    $insertData['banka_logo_url'] = $logo_url;
                }
                $newId = $db->insert('bankalar', $insertData);
                
                echo json_encode(['success' => true, 'message' => 'Banka eklendi', 'id' => $newId]);
            }
            exit;
        }
        
        if ($action === 'delete_tanim') {
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Geçersiz ID');
            }
            
            // Kullanımda olup olmadığını kontrol et
            $kullanim = $db->fetchOne("SELECT COUNT(*) as sayi FROM Banka_Hesap WHERE bankaHesap_banka_id = ?", [$id]);
            if ($kullanim && $kullanim['sayi'] > 0) {
                throw new Exception('Bu banka ' . $kullanim['sayi'] . ' hesapta kullanılıyor, silinemez!');
            }
            
            $db->delete('bankalar', ['banka_id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Banka silindi']);
            exit;
        }
        
        if ($action === 'list') {
            // Filtreleri al
            $firma_id = $_POST['firma_id'] ?? '';
            $banka_id = $_POST['banka_id'] ?? '';
            $otomatik = $_POST['otomatik'] ?? '';
            $durum = $_POST['durum'] ?? '';
            $search = $_POST['search'] ?? '';
            
            $whereConditions = ["1=1"];
            $params = [];
            
            if ($firma_id) {
                $whereConditions[] = "h.bankaHesap_firma_id = ?";
                $params[] = $firma_id;
            }
            
            if ($banka_id) {
                $whereConditions[] = "h.bankaHesap_banka_id = ?";
                $params[] = $banka_id;
            }
            
            if ($otomatik !== '') {
                $whereConditions[] = "h.bankaHesap_otomatik = ?";
                $params[] = $otomatik;
            }
            
            if ($durum !== '') {
                $whereConditions[] = "h.bankaHesap_durum = ?";
                $params[] = $durum;
            }
            
            if ($search) {
                $whereConditions[] = "(h.bankaHesap_iban LIKE ? OR h.bankaHesap_no LIKE ? OR h.bankaHesap_sube_adi LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $whereClause = implode(" AND ", $whereConditions);
            
            $hesaplar = $db->fetchAll("
                SELECT 
                    h.bankaHesap_id,
                    h.bankaHesap_banka_id,
                    h.bankaHesap_firma_id,
                    h.bankaHesap_apiKimlik_id,
                    h.bankaHesap_swift,
                    h.bankaHesap_iban,
                    h.bankaHesap_no,
                    h.bankaHesap_sube_adi,
                    h.bankaHesap_sube_kodu,
                    h.bankaHesap_aciklama,
                    h.bankaHesap_otomatik,
                    h.bankaHesap_durum,
                    h.bankaHesap_identifier,
                    b.banka_adi,
                    b.banka_kodu,
                    b.banka_logo_url,
                    f.firma_adi,
                    ak.apiKimlik_aciklama as apiKimlik_aciklama,
                    CONVERT(VARCHAR(19), h.bankaHesap_olusturma_tarihi, 120) as bankaHesap_olusturma_tarihi,
                    CONVERT(VARCHAR(19), h.bankaHesap_guncelleme_tarihi, 120) as bankaHesap_guncelleme_tarihi
                FROM Banka_Hesap h
                INNER JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                LEFT JOIN Firmalar f ON h.bankaHesap_firma_id = f.firma_id
                LEFT JOIN banka_ApiKimlik ak ON h.bankaHesap_apiKimlik_id = ak.apiKimlik_id
                WHERE $whereClause
                ORDER BY b.banka_adi, h.bankaHesap_iban
            ", $params);
            
            echo json_encode(['success' => true, 'data' => $hesaplar]);
            exit;
        }
        
        if ($action === 'save') {
            // Yetki kontrolü
            $id = intval($_POST['id'] ?? 0);
            $banka_id = intval($_POST['banka_id'] ?? 0);
            if ($id > 0 && !$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            }
            if ($id == 0 && !$pagePermissions['can_add']) {
                throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
            }
            
            $firma_id = !empty($_POST['firma_id']) ? intval($_POST['firma_id']) : null;
            $apiKimlik_id = !empty($_POST['apiKimlik_id']) ? intval($_POST['apiKimlik_id']) : null;
            $swift = trim($_POST['swift'] ?? '');
            $iban = trim($_POST['iban'] ?? '');
            $hesap_no = trim($_POST['hesap_no'] ?? '');
            $sube_adi = trim($_POST['sube_adi'] ?? '');
            $sube_kodu = trim($_POST['sube_kodu'] ?? '');
            $aciklama = trim($_POST['aciklama'] ?? '');
            $identifier = trim($_POST['identifier'] ?? '');
            $durum = intval($_POST['durum'] ?? 1);
            
            if ($banka_id <= 0) {
                throw new Exception('Banka seçimi zorunludur');
            }
            
            if (empty($iban)) {
                throw new Exception('IBAN zorunludur');
            }
            
            // Mükerrer IBAN kontrolü
            $ibanKontrol = $db->fetchOne(
                "SELECT bankaHesap_id FROM Banka_Hesap WHERE bankaHesap_iban = ? AND bankaHesap_id != ?",
                [$iban, $id]
            );
            if ($ibanKontrol) {
                throw new Exception('Bu IBAN zaten kayıtlı! (Hesap ID: ' . $ibanKontrol['bankaHesap_id'] . ')');
            }
            
            // Otomatik hesap kontrolü (API'den gelen hesaplar güncellenemez)
            if ($id > 0) {
                $mevcut = $db->fetchOne("SELECT bankaHesap_otomatik FROM Banka_Hesap WHERE bankaHesap_id = ?", [$id]);
                if ($mevcut && $mevcut['bankaHesap_otomatik'] == 1) {
                    throw new Exception('Bu hesap otomatik sistemden geldi, manuel değiştirilemez!');
                }
            }
            
            if ($id > 0) {
                // Güncelleme
                $db->update('Banka_Hesap', [
                    'bankaHesap_banka_id' => $banka_id,
                    'bankaHesap_firma_id' => $firma_id,
                    'bankaHesap_apiKimlik_id' => $apiKimlik_id,
                    'bankaHesap_swift' => $swift ?: null,
                    'bankaHesap_iban' => $iban,
                    'bankaHesap_no' => $hesap_no ?: null,
                    'bankaHesap_sube_adi' => $sube_adi ?: null,
                    'bankaHesap_sube_kodu' => $sube_kodu ?: null,
                    'bankaHesap_aciklama' => $aciklama ?: null,
                    'bankaHesap_identifier' => $identifier ?: null,
                    'bankaHesap_durum' => $durum,
                    'bankaHesap_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                    'bankaHesap_guncelleyen_kullanici_id' => $user['kullanici_id']
                ], ['bankaHesap_id' => $id]);
                
                echo json_encode(['success' => true, 'message' => 'Banka hesabı güncellendi']);
            } else {
                // Yeni ekleme (Manuel hesap)
                $newId = $db->insert('Banka_Hesap', [
                    'bankaHesap_banka_id' => $banka_id,
                    'bankaHesap_firma_id' => $firma_id,
                    'bankaHesap_apiKimlik_id' => $apiKimlik_id,
                    'bankaHesap_swift' => $swift ?: null,
                    'bankaHesap_iban' => $iban,
                    'bankaHesap_no' => $hesap_no ?: null,
                    'bankaHesap_sube_adi' => $sube_adi ?: null,
                    'bankaHesap_sube_kodu' => $sube_kodu ?: null,
                    'bankaHesap_aciklama' => $aciklama ?: null,
                    'bankaHesap_identifier' => $identifier ?: null,
                    'bankaHesap_otomatik' => 0, // Manuel eklenen hesap
                    'bankaHesap_durum' => $durum,
                    'bankaHesap_olusturan_kullanici_id' => $user['kullanici_id']
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Banka hesabı eklendi', 'id' => $newId]);
            }
            exit;
        }
        
        if ($action === 'delete') {
            // Yetki kontrolü
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Geçersiz ID');
            }
            
            // Otomatik hesap kontrolü
            $hesap = $db->fetchOne("SELECT bankaHesap_otomatik FROM Banka_Hesap WHERE bankaHesap_id = ?", [$id]);
            if ($hesap && $hesap['bankaHesap_otomatik'] == 1) {
                throw new Exception('Otomatik hesaplar silinemez!');
            }
            
            // Kullanımda olup olmadığını kontrol et
            $kullanim = $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_banka_hesap_id = ?", [$id]);
            if ($kullanim && $kullanim['sayi'] > 0) {
                throw new Exception('Bu hesap ' . $kullanim['sayi'] . ' personelde kullanılıyor, silinemez!');
            }
            
            $db->delete('Banka_Hesap', ['bankaHesap_id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Banka hesabı silindi']);
            exit;
        }
        
        throw new Exception('Geçersiz işlem');
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
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
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-bank"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hesap</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-building"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Firma Hesapları</span>
                                    <span class="info-box-number" id="stat-firma">0</span>
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
                                    <i class="bi bi-robot"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Otomatik (API)</span>
                                    <span class="info-box-number" id="stat-otomatik">0</span>
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
                                    <!-- Firma -->
                                    <div class="col-md-3">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select" name="firma_id" id="filter_firma_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Banka -->
                                    <div class="col-md-3">
                                        <label class="form-label">Banka</label>
                                        <select class="form-select" name="banka_id" id="filter_banka_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Kaynak -->
                                    <div class="col-md-2">
                                        <label class="form-label">Kaynak</label>
                                        <select class="form-select" name="otomatik" id="filter_otomatik">
                                            <option value="">Tümü</option>
                                            <option value="1">Otomatik</option>
                                            <option value="0">Manuel</option>
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
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="IBAN, hesap no, şube...">
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
                    
                    <!-- Sekmeler -->
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="hesaplar-tab" data-bs-toggle="tab" data-bs-target="#hesaplar" type="button" role="tab">
                                <i class="bi bi-wallet2"></i> Banka Hesapları
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tanimlar-tab" data-bs-toggle="tab" data-bs-target="#tanimlar" type="button" role="tab">
                                <i class="bi bi-bank"></i> Banka Tanımları
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Sekme İçerikleri -->
                    <div class="tab-content">
                        <!-- Banka Hesapları Sekmesi -->
                        <div class="tab-pane fade show active" id="hesaplar" role="tabpanel">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="bi bi-wallet2"></i> Banka Hesapları
                                    </h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="bankaEkleModalAc()">
                                            <i class="bi bi-plus-circle"></i> Yeni Hesap
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="bankaTable" class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Firma</th>
                                                <th>Banka Adı</th>
                                                <th>IBAN</th>
                                                <th>Hesap No</th>
                                                <th>Şube</th>
                                                <th>Kaynak</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Banka Tanımları Sekmesi -->
                        <div class="tab-pane fade" id="tanimlar" role="tabpanel">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="bi bi-bank"></i> Banka Tanımları
                                    </h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="bankaTanimEkleModalAc()">
                                            <i class="bi bi-plus-circle"></i> Yeni Banka
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="bankaTanimTable" class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Logo</th>
                                                <th>Banka Adı</th>
                                                <th>Kod</th>
                                                <th>Sıra</th>
                                                <th>Personel</th>
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
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Banka Ekle/Düzenle Modal -->
    <div class="modal fade" id="bankaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bankaModalBaslik">Yeni Banka Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bankaForm">
                    <input type="hidden" id="banka_id" name="id">
                    <div class="modal-body">
                        <!-- Uyarı Mesajı (Otomatik Hesaplar için) -->
                        <div id="otomatik_uyari" class="alert alert-warning" style="display:none;">
                            <i class="bi bi-exclamation-triangle-fill"></i> <strong>Dikkat!</strong> Bu hesap otomatik sistemden geldi, değiştirilemez!
                        </div>
                        
                        <!-- Firma ve Banka Seçimi -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_firma_id" class="form-label">Firma</label>
                                <select class="form-select" id="banka_firma_id" name="firma_id">
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_banka_id" class="form-label">Banka *</label>
                                <select class="form-select" id="banka_banka_id" name="banka_id" required>
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- IBAN ve Hesap No -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_iban" class="form-label">IBAN *</label>
                                <input type="text" class="form-control" id="banka_iban" name="iban" maxlength="34" placeholder="TR00 0000 0000 0000 0000 0000 00" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_hesap_no" class="form-label">Hesap No</label>
                                <input type="text" class="form-control" id="banka_hesap_no" name="hesap_no" maxlength="50">
                            </div>
                        </div>
                        
                        <!-- SWIFT ve Şube Bilgileri -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_swift" class="form-label">SWIFT Kodu</label>
                                <input type="text" class="form-control" id="banka_swift" name="swift" placeholder="TCZBTR2AXXX" maxlength="20">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_sube_kodu" class="form-label">Şube Kodu</label>
                                <input type="text" class="form-control" id="banka_sube_kodu" name="sube_kodu" maxlength="20">
                            </div>
                        </div>
                        
                        <!-- Şube Adı ve Identifier -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_sube_adi" class="form-label">Şube Adı</label>
                                <input type="text" class="form-control" id="banka_sube_adi" name="sube_adi" maxlength="100">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_identifier" class="form-label">Identifier</label>
                                <input type="text" class="form-control" id="banka_identifier" name="identifier" maxlength="50" placeholder="API Hesap Tanımlayıcı">
                            </div>
                        </div>
                        
                        <!-- Durum ve API Kimlik -->
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="banka_durum" class="form-label">Durum</label>
                                <select class="form-select" id="banka_durum" name="durum">
                                    <option value="1">Aktif</option>
                                    <option value="0">Pasif</option>
                                </select>
                            </div>
                            <div class="col-md-8 mb-3">
                                <label for="banka_apiKimlik_id" class="form-label">API Kimlik (Entegrasyon)</label>
                                <select class="form-select" id="banka_apiKimlik_id" name="apiKimlik_id">
                                    <option value="">Seçiniz...</option>
                                </select>
                                <small class="text-muted">Bu hesap hangi API kimliği ile senkron edilsin?</small>
                            </div>
                        </div>
                        
                        <!-- Açıklama -->
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="banka_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="banka_aciklama" name="aciklama" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Banka Tanım Modal -->
    <div class="modal fade" id="bankaTanimModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bankaTanimModalBaslik">Yeni Banka Tanımı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bankaTanimForm" enctype="multipart/form-data">
                    <input type="hidden" id="tanim_id" name="id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="tanim_adi" class="form-label">Banka Adı *</label>
                                <input type="text" class="form-control" id="tanim_adi" name="adi" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="tanim_kodu" class="form-label">Banka Kodu</label>
                                <input type="text" class="form-control" id="tanim_kodu" name="kodu" maxlength="10" placeholder="0001">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="tanim_sira_no" class="form-label">Sıra No</label>
                                <input type="number" class="form-control" id="tanim_sira_no" name="sira_no" value="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="tanim_durum" class="form-label">Durum</label>
                                <select class="form-select" id="tanim_durum" name="durum">
                                    <option value="1">Aktif</option>
                                    <option value="0">Pasif</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" id="tanim_personel" name="personel" value="1">
                                    <label class="form-check-label" for="tanim_personel">
                                        <i class="bi bi-people"></i> Personel için göster
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="tanim_logo" class="form-label"><i class="bi bi-image"></i> Banka Logosu</label>
                                <input type="file" class="form-control" id="tanim_logo" name="logo" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp">
                                <small class="form-text text-muted">PNG, JPG, GIF, SVG, WEBP - Maks. 2MB</small>
                                <div id="tanim_logo_preview" class="mt-2" style="display:none;">
                                    <img id="tanim_logo_img" src="" alt="Logo" style="max-height:60px; max-width:200px; object-fit:contain; border:1px solid #dee2e6; border-radius:4px; padding:4px;">
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" id="tanim_logo_sil" title="Logoyu Kaldır">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
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
        let bankaModalInstance;
        
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-firma').text(response.data.firma_hesaplari);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-otomatik').text(response.data.otomatik);
                }
            });
        }
        
        function loadFirmalar() {
            $.post('', { action: 'get_firmalar' }, response => {
                if (response.success) {
                    const select = $('#banka_firma_id, #filter_firma_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(firma => {
                        select.append(`<option value="${firma.firma_id}">${firma.firma_adi}</option>`);
                    });
                }
            });
        }
        
        function loadBankalar() {
            $.post('', { action: 'get_bankalar' }, response => {
                if (response.success) {
                    const select = $('#banka_banka_id, #filter_banka_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(banka => {
                        select.append(`<option value="${banka.banka_id}">${banka.banka_adi}</option>`);
                    });
                    initSelect2();
                }
            });
        }
        
        function loadApiKimlikler() {
            $.post('', { action: 'get_apiKimlikler' }, response => {
                if (response.success) {
                    const select = $('#banka_apiKimlik_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(api => {
                        const label = api.banka_adi + (api.firma_adi ? ' - ' + api.firma_adi : '') + (api.apiKimlik_aciklama ? ' (' + api.apiKimlik_aciklama + ')' : '');
                        select.append(`<option value="${api.apiKimlik_id}">${label}</option>`);
                    });
                }
            });
        }
        
        function initSelect2() {
            $('.form-select').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Modal içindeki select'ler için
            $('#banka_firma_id, #banka_banka_id, #banka_apiKimlik_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#bankaModal'),
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true
            });
        }
        
        function initDataTable() {
            table = $('#bankaTable').DataTable({
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
                    { data: 'bankaHesap_id' },
                    { 
                        data: 'firma_adi',
                        defaultContent: '-',
                        render: (data) => data || '-'
                    },
                    { 
                        data: 'banka_adi',
                        render: data => `<strong>${data}</strong>`
                    },
                    { 
                        data: 'bankaHesap_iban', 
                        defaultContent: '-',
                        render: data => data || '-'
                    },
                    { 
                        data: 'bankaHesap_no', 
                        defaultContent: '-' 
                    },
                    { 
                        data: null,
                        defaultContent: '-',
                        render: (data, type, row) => {
                            if (!row.bankaHesap_sube_adi && !row.bankaHesap_sube_kodu) return '-';
                            let sube = row.bankaHesap_sube_adi || '';
                            if (row.bankaHesap_sube_kodu) {
                                sube += sube ? ` (${row.bankaHesap_sube_kodu})` : row.bankaHesap_sube_kodu;
                            }
                            return sube;
                        }
                    },
                    { 
                        data: 'bankaHesap_otomatik',
                        render: data => data 
                            ? '<span class="badge bg-info"><i class="bi bi-robot"></i> Otomatik</span>' 
                            : '<span class="badge bg-secondary"><i class="bi bi-pencil"></i> Manuel</span>'
                    },
                    { 
                        data: 'bankaHesap_durum',
                        render: data => data 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-danger">Pasif</span>'
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: data => {
                            let buttons = '';
                            
                            if (permissions.can_edit) {
                                buttons += `<button class="btn btn-sm btn-warning" onclick='bankaDuzenle(${JSON.stringify(data)})' title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </button> `;
                            }
                            
                            if (permissions.can_delete && !data.bankaHesap_otomatik) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="bankaSil(${data.bankaHesap_id}, '${data.banka_adi}')" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }
                            
                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[0, 'desc']] // ID'ye göre azalan (en yeni önce)
            });
        }
        
        $(document).ready(() => {
            bankaModalInstance = new bootstrap.Modal(document.getElementById('bankaModal'));
            
            loadStats();
            loadFirmalar();
            loadBankalar();
            loadApiKimlikler();
            initDataTable();
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                currentFilters = {
                    firma_id: $('#filter_firma_id').val(),
                    banka_id: $('#filter_banka_id').val(),
                    otomatik: $('#filter_otomatik').val(),
                    durum: $('#filter_durum').val(),
                    search: $('#filter_search').val()
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
                $('#filter_firma_id').val('').trigger('change.select2');
                $('#filter_banka_id').val('').trigger('change.select2');
                $('#filter_otomatik').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
            
            // Form submit
            $('#bankaForm').on('submit', bankaKaydet);
        });
        
        function bankaEkleModalAc() {
            document.getElementById('bankaModalBaslik').textContent = 'Yeni Banka Hesabı Ekle';
            document.getElementById('bankaForm').reset();
            document.getElementById('banka_id').value = '';
            document.getElementById('otomatik_uyari').style.display = 'none';
            
            // Tüm alanları etkinleştir
            document.querySelectorAll('#bankaForm input, #bankaForm select, #bankaForm textarea').forEach(el => {
                el.disabled = false;
            });
            document.querySelector('#bankaForm button[type="submit"]').style.display = 'inline-block';
            
            // Select2 dropdown'larını temizle
            $('#banka_firma_id').val('').trigger('change.select2');
            $('#banka_banka_id').val('').trigger('change.select2');
            $('#banka_apiKimlik_id').val('').trigger('change.select2');
            
            bankaModalInstance.show();
        }
        
        function bankaDuzenle(banka) {
            // Otomatik hesap kontrolü
            const isOtomatik = banka.bankaHesap_otomatik == 1;
            
            if (isOtomatik) {
                document.getElementById('otomatik_uyari').style.display = 'block';
                // Tüm form alanlarını disabled yap
                document.querySelectorAll('#bankaForm input, #bankaForm select, #bankaForm textarea').forEach(el => {
                    el.disabled = true;
                });
                // Submit butonunu gizle
                document.querySelector('#bankaForm button[type="submit"]').style.display = 'none';
            } else {
                document.getElementById('otomatik_uyari').style.display = 'none';
                document.querySelectorAll('#bankaForm input, #bankaForm select, #bankaForm textarea').forEach(el => {
                    el.disabled = false;
                });
                document.querySelector('#bankaForm button[type="submit"]').style.display = 'inline-block';
            }
            
            document.getElementById('bankaModalBaslik').textContent = 'Banka Hesabı Düzenle';
            document.getElementById('banka_id').value = banka.bankaHesap_id;
            document.getElementById('banka_firma_id').value = banka.bankaHesap_firma_id || '';
            document.getElementById('banka_banka_id').value = banka.bankaHesap_banka_id;
            document.getElementById('banka_iban').value = banka.bankaHesap_iban || '';
            document.getElementById('banka_hesap_no').value = banka.bankaHesap_no || '';
            document.getElementById('banka_swift').value = banka.bankaHesap_swift || '';
            document.getElementById('banka_sube_adi').value = banka.bankaHesap_sube_adi || '';
            document.getElementById('banka_sube_kodu').value = banka.bankaHesap_sube_kodu || '';
            document.getElementById('banka_identifier').value = banka.bankaHesap_identifier || '';
            document.getElementById('banka_aciklama').value = banka.bankaHesap_aciklama || '';
            document.getElementById('banka_durum').value = banka.bankaHesap_durum ? '1' : '0';
            document.getElementById('banka_apiKimlik_id').value = banka.bankaHesap_apiKimlik_id || '';
            
            // Select2 değerlerini trigger et
            $('#banka_firma_id').trigger('change.select2');
            $('#banka_banka_id').trigger('change.select2');
            $('#banka_apiKimlik_id').trigger('change.select2');
            
            initSelect2();
            bankaModalInstance.show();
        }
        
        function bankaKaydet(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('action', 'save');
            
            fetch(window.location.href, {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bankaModalInstance.hide();
                    table.ajax.reload();
                    loadStats();
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            });
        }
        
        function bankaSil(id, bankaAdi) {
            confirmAction(
                `"${bankaAdi}" bankasını silmek istediğinize emin misiniz?`,
                'Personelde kullanılıyorsa silinemez!',
                function() {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=delete&id=${id}`
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            table.ajax.reload();
                            loadStats();
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
        
        // ===== BANKA TANIMLARI FONKSİYONLARI =====
        let tableTanim;
        let bankaTanimModalInstance;
        
        function initBankaTanimTable() {
            tableTanim = $('#bankaTanimTable').DataTable({
                processing: true,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: { action: 'list_tanimlar' },
                    dataSrc: json => json.success ? json.data : []
                },
                columns: [
                    { data: 'banka_id' },
                    { 
                        data: 'banka_logo_url',
                        orderable: false,
                        defaultContent: '-',
                        render: data => {
                            if (data) {
                                return `<img src="${data}" alt="Logo" style="max-height:32px; max-width:80px; object-fit:contain;">`;
                            }
                            return '<span class="text-muted"><i class="bi bi-image"></i></span>';
                        }
                    },
                    { 
                        data: 'banka_adi',
                        render: data => `<strong>${data}</strong>`
                    },
                    { 
                        data: 'banka_kodu',
                        defaultContent: '-'
                    },
                    { 
                        data: 'banka_sira_no',
                        defaultContent: '0'
                    },
                    { 
                        data: 'banka_personel',
                        render: data => data 
                            ? '<span class="badge bg-success"><i class="bi bi-people"></i> Evet</span>' 
                            : '<span class="badge bg-secondary">Hayır</span>'
                    },
                    { 
                        data: 'banka_durum',
                        render: data => data 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-danger">Pasif</span>'
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: data => {
                            let buttons = '';
                            
                            if (permissions.can_edit) {
                                buttons += `<button class="btn btn-sm btn-warning" onclick='bankaTanimDuzenle(${JSON.stringify(data)})' title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </button> `;
                            }
                            
                            if (permissions.can_delete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="bankaTanimSil(${data.banka_id}, '${data.banka_adi}')" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }
                            
                            return buttons || '-';
                        }
                    }
                ]
            });
        }
        
        function bankaTanimEkleModalAc() {
            document.getElementById('bankaTanimModalBaslik').textContent = 'Yeni Banka Tanımı';
            document.getElementById('bankaTanimForm').reset();
            document.getElementById('tanim_id').value = '';
            document.getElementById('tanim_logo').value = '';
            document.getElementById('tanim_logo_preview').style.display = 'none';
            bankaTanimModalInstance.show();
        }
        
        function bankaTanimDuzenle(banka) {
            document.getElementById('bankaTanimModalBaslik').textContent = 'Banka Düzenle';
            document.getElementById('tanim_id').value = banka.banka_id;
            document.getElementById('tanim_adi').value = banka.banka_adi;
            document.getElementById('tanim_kodu').value = banka.banka_kodu || '';
            document.getElementById('tanim_sira_no').value = banka.banka_sira_no || 0;
            document.getElementById('tanim_personel').checked = banka.banka_personel == 1;
            document.getElementById('tanim_durum').value = banka.banka_durum ? '1' : '0';
            document.getElementById('tanim_logo').value = '';
            
            // Logo preview
            if (banka.banka_logo_url) {
                document.getElementById('tanim_logo_img').src = banka.banka_logo_url;
                document.getElementById('tanim_logo_preview').style.display = 'block';
            } else {
                document.getElementById('tanim_logo_preview').style.display = 'none';
            }
            
            bankaTanimModalInstance.show();
        }
        
        function bankaTanimKaydet(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('action', 'save_tanim');
            
            // Checkbox kontrolü
            if (!document.getElementById('tanim_personel').checked) {
                formData.set('personel', '0');
            }
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bankaTanimModalInstance.hide();
                    tableTanim.ajax.reload();
                    loadBankalar();
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            });
        }
        
        function bankaTanimSil(id, bankaAdi) {
            confirmAction(
                `"${bankaAdi}" bankasını silmek istediğinize emin misiniz?`,
                'Bu banka hesaplarda kullanılıyorsa silinemez!',
                function() {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=delete_tanim&id=${id}`
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            tableTanim.ajax.reload();
                            loadBankalar();
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
        
        // Sekme değiştiğinde tabloları başlat
        document.getElementById('tanimlar-tab').addEventListener('shown.bs.tab', function() {
            if (!tableTanim) {
                initBankaTanimTable();
                bankaTanimModalInstance = new bootstrap.Modal(document.getElementById('bankaTanimModal'));
                document.getElementById('bankaTanimForm').addEventListener('submit', bankaTanimKaydet);
                
                // Logo silme butonu
                document.getElementById('tanim_logo_sil').addEventListener('click', function() {
                    document.getElementById('tanim_logo').value = '';
                    document.getElementById('tanim_logo_preview').style.display = 'none';
                    // Gizli alan ekle - logo silme isteği
                    let logoSilInput = document.getElementById('logo_sil_input');
                    if (!logoSilInput) {
                        logoSilInput = document.createElement('input');
                        logoSilInput.type = 'hidden';
                        logoSilInput.name = 'logo_sil';
                        logoSilInput.id = 'logo_sil_input';
                        document.getElementById('bankaTanimForm').appendChild(logoSilInput);
                    }
                    logoSilInput.value = '1';
                });
                
                // Logo dosya seçildiğinde preview göster
                document.getElementById('tanim_logo').addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(ev) {
                            document.getElementById('tanim_logo_img').src = ev.target.result;
                            document.getElementById('tanim_logo_preview').style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                        // logo_sil flag'ını sıfırla
                        const logoSilInput = document.getElementById('logo_sil_input');
                        if (logoSilInput) logoSilInput.value = '0';
                    }
                });
            }
        });
    </script>
</body>
</html>
