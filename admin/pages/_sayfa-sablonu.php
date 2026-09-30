<?php
/**
 * Admin Panel - Sayfa Şablonu
 * 
 * Yeni sayfa oluştururken bu dosyayı kopyalayın ve özelleştirin
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

// Sayfa bilgileri - Veritabanında kayıt yoksa manuel başlık kullan
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Örnek Sayfa Şablonu'; // <- Buraya manuel başlık yazabilirsiniz
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Firma ve Şube listelerini çek
$firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
$subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");

// Kategori sabitleri
$kategoriler = ['Genel', 'Önemli', 'Planlı', 'Tamamlandı', 'Arşiv'];
$durumlar = ['Bekliyor', 'Devam Ediyor', 'Tamamlandı', 'İptal'];
$oncelikler = ['Düşük', 'Normal', 'Yüksek', 'Acil'];

// BURAYA SAYFA İŞLEMLERİNİ EKLEYIN (AJAX, Form İşlemleri vb.)
// Örnek AJAX İşlemleri:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $baseSql = "SELECT COUNT(*) as sayi FROM ornek_sayfa_sablosu WHERE sablon_aktif = 1";
                $params = [];
                
                // Yetki kontrolü
                if ($pagePermissions['can_view_own_records']) {
                    $baseSql .= " AND sablon_olusturan_kullanici_id = ?";
                    $params[] = $user['kullanici_id'];
                }
                if (!$pagePermissions['can_view_firma']) {
                    $baseSql .= " AND sablon_firma_id = ?";
                    $params[] = $user['kullanici_firma_id'];
                }
                if (!$pagePermissions['can_view_sube']) {
                    $baseSql .= " AND sablon_sube_id = ?";
                    $params[] = $user['kullanici_sube_id'];
                }
                
                $stats = [
                    'toplam' => $db->fetchOne($baseSql, $params)['sayi'] ?? 0,
                    'onemli' => $db->fetchOne($baseSql . " AND sablon_kategori = 'Önemli'", $params)['sayi'] ?? 0,
                    'tamamlandi' => $db->fetchOne($baseSql . " AND sablon_durum = 'Tamamlandı'", $params)['sayi'] ?? 0,
                    'bekliyor' => $db->fetchOne($baseSql . " AND sablon_durum = 'Bekliyor'", $params)['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $search = $_POST['search'] ?? '';
                $kategori = $_POST['kategori'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $oncelik = $_POST['oncelik'] ?? '';
                
                // SQL sorgusu oluştur (View kullan)
                $sql = "SELECT * FROM vw_ornek_sayfa_sablosu_detay WHERE sablon_aktif = 1";
                $params = [];
                
                // YETKİ KONTROLÜ: Sadece kendi kayıtlarını görsün
                if ($pagePermissions['can_view_own_records']) {
                    $sql .= " AND sablon_olusturan_kullanici_id = ?";
                    $params[] = $user['kullanici_id'];
                }
                
                // YETKİ KONTROLÜ: Firma bazlı filtreleme
                if (!$pagePermissions['can_view_firma']) {
                    $sql .= " AND sablon_firma_id = ?";
                    $params[] = $user['kullanici_firma_id'];
                }
                
                // YETKİ KONTROLÜ: Şube bazlı filtreleme
                if (!$pagePermissions['can_view_sube']) {
                    $sql .= " AND sablon_sube_id = ?";
                    $params[] = $user['kullanici_sube_id'];
                }
                
                // Tarih filtresi
                if ($startDate) {
                    $sql .= " AND CONVERT(date, sablon_baslangic_tarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, sablon_bitis_tarihi) <= ?";
                    $params[] = $endDate;
                }
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (sablon_baslik LIKE ? OR sablon_kod LIKE ? OR sablon_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                // Kategori filtresi
                if ($kategori) {
                    $sql .= " AND sablon_kategori = ?";
                    $params[] = $kategori;
                }
                
                // Durum filtresi
                if ($durum) {
                    $sql .= " AND sablon_durum = ?";
                    $params[] = $durum;
                }
                
                // Öncelik filtresi
                if ($oncelik) {
                    $sql .= " AND sablon_oncelik = ?";
                    $params[] = $oncelik;
                }
                
                $sql .= " ORDER BY sablon_id DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("SELECT * FROM ornek_sayfa_sablosu WHERE sablon_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'save':
                // Kaydet/Güncelle
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                
                // Sayısal değerleri temizle (boş string'leri null yap)
                $firmaId = $_POST['firma_id'] ?? $user['kullanici_firma_id'];
                $subeId = !empty($_POST['sube_id']) ? intval($_POST['sube_id']) : null;
                $miktar = !empty($_POST['miktar']) ? floatval($_POST['miktar']) : null;
                $tutar = !empty($_POST['tutar']) ? floatval($_POST['tutar']) : null;
                $oran = !empty($_POST['oran']) ? floatval($_POST['oran']) : null;
                
                // Tarih alanlarını temizle
                $baslangicTarihi = !empty($_POST['baslangic_tarihi']) ? $_POST['baslangic_tarihi'] : null;
                $bitisTarihi = !empty($_POST['bitis_tarihi']) ? $_POST['bitis_tarihi'] : null;
                
                // String alanlarını temizle
                $kod = !empty($_POST['kod']) ? trim($_POST['kod']) : null;
                $aciklama = !empty($_POST['aciklama']) ? trim($_POST['aciklama']) : null;
                $etiketler = !empty($_POST['etiketler']) ? trim($_POST['etiketler']) : null;
                $notlar = !empty($_POST['notlar']) ? trim($_POST['notlar']) : null;
                
                $data = [
                    'sablon_firma_id' => $firmaId,
                    'sablon_sube_id' => $subeId,
                    'sablon_kategori' => $_POST['kategori'] ?? 'Genel',
                    'sablon_baslik' => trim($_POST['baslik'] ?? ''),
                    'sablon_kod' => $kod,
                    'sablon_aciklama' => $aciklama,
                    'sablon_baslangic_tarihi' => $baslangicTarihi,
                    'sablon_bitis_tarihi' => $bitisTarihi,
                    'sablon_miktar' => $miktar,
                    'sablon_tutar' => $tutar,
                    'sablon_oran' => $oran,
                    'sablon_durum' => $_POST['durum'] ?? 'Bekliyor',
                    'sablon_oncelik' => $_POST['oncelik'] ?? 'Normal',
                    'sablon_etiketler' => $etiketler,
                    'sablon_notlar' => $notlar
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['sablon_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    $db->update('ornek_sayfa_sablosu', $data, ['sablon_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Kayıt güncellendi!']);
                } else {
                    // Yeni kayıt
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['sablon_olusturan_kullanici_id'] = $user['kullanici_id'];
                    $db->insert('ornek_sayfa_sablosu', $data);
                    echo json_encode(['success' => true, 'message' => 'Kayıt eklendi!']);
                }
                break;
                
            case 'delete':
                // Sil (Soft Delete)
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->update('ornek_sayfa_sablosu', ['sablon_aktif' => 0], ['sablon_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Kayıt silindi!']);
                break;
                
            case 'get_firmalar':
                // Firma listesi
                $firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
                echo json_encode(['success' => true, 'data' => $firmalar]);
                break;
                
            case 'get_subeler':
                // Şube listesi
                $subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");
                echo json_encode(['success' => true, 'data' => $subeler]);
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
    <style>
        /* Tablo işlem butonları */
        .btn-group-sm > .btn, .btn-sm {
            padding: 0.25rem 0.5rem;
            margin: 0 2px;
        }
        
        /* Badge stilleri */
        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        
        /* Modal form düzenlemeleri */
        .modal-body .form-label {
            font-weight: 500;
            margin-bottom: 0.3rem;
        }
        
        /* Filtre kartı collapsed başlangıç */
        #filterCollapse {
            transition: all 0.3s ease;
        }
        
        /* Info box hover */
        .info-box {
            transition: transform 0.2s;
        }
        
        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
                    
                    <!-- BURADAN İTİBAREN SAYFA İÇERİĞİNİ EKLEYİN -->
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-box-seam"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kayıt</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-exclamation-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Önemli</span>
                                    <span class="info-box-number" id="stat-onemli">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tamamlandı</span>
                                    <span class="info-box-number" id="stat-tamamlandi">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-clock-history"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bekliyor</span>
                                    <span class="info-box-number" id="stat-bekliyor">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtreler
                            </h3>
                        </div>
                        <div class="card-body">
                            <form id="filterForm">
                                    <div class="row g-3">
                                        <!-- Tarih Aralığı -->
                                        <div class="col-md-2">
                                            <label class="form-label">Başlangıç Tarihi</label>
                                            <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bitiş Tarihi</label>
                                            <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                        </div>
                                        
                                        <!-- Arama -->
                                        <div class="col-md-3">
                                            <label class="form-label">Arama</label>
                                            <input type="text" class="form-control" id="filter_search" name="search" placeholder="Başlık, kod veya açıklama...">
                                        </div>
                                        
                                        <!-- Kategori -->
                                        <div class="col-md-2">
                                            <label class="form-label">Kategori</label>
                                            <select class="form-select" id="filter_kategori" name="kategori">
                                                <option value="">Tümü</option>
                                                <?php foreach ($kategoriler as $kat): ?>
                                                    <option value="<?= $kat ?>"><?= $kat ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Durum -->
                                        <div class="col-md-2">
                                            <label class="form-label">Durum</label>
                                            <select class="form-select" id="filter_durum" name="durum">
                                                <option value="">Tümü</option>
                                                <?php foreach ($durumlar as $durum): ?>
                                                    <option value="<?= $durum ?>"><?= $durum ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Öncelik -->
                                        <div class="col-md-1">
                                            <label class="form-label">Öncelik</label>
                                            <select class="form-select" id="filter_oncelik" name="oncelik">
                                                <option value="">Tümü</option>
                                                <?php foreach ($oncelikler as $onc): ?>
                                                    <option value="<?= $onc ?>"><?= $onc ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Butonlar -->
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
                    </div>
                    
                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> Kayıt Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-sm btn-primary" id="btnYeniEkle">
                                    <i class="bi bi-plus-circle"></i> Yeni Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th width="50">ID</th>
                                            <th>Firma</th>
                                            <th>Şube</th>
                                            <th>Başlık</th>
                                            <th>Kod</th>
                                            <th>Kategori</th>
                                            <th>Durum</th>
                                            <th>Öncelik</th>
                                            <th>Tarih</th>
                                            <th>Oluşturan</th>
                                            <th width="120">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr>
                                            <td colspan="11" class="text-center">Yükleniyor...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- İçerik Sonu -->
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Modal: Kayıt Ekle/Düzenle -->
    <div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Kayıt Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="saveForm">
                    <div class="modal-body">
                        <input type="hidden" id="sablon_id" name="id">
                        
                        <div class="row g-3">
                            <!-- Firma -->
                            <div class="col-md-6">
                                <label class="form-label">Firma <span class="text-danger">*</span></label>
                                <select class="form-select" id="firma_id" name="firma_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($firmalar as $firma): ?>
                                        <option value="<?= $firma['firma_id'] ?>"><?= htmlspecialchars($firma['firma_adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Şube -->
                            <div class="col-md-6">
                                <label class="form-label">Şube</label>
                                <select class="form-select" id="sube_id" name="sube_id">
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                            
                            <!-- Başlık -->
                            <div class="col-md-12">
                                <label class="form-label">Başlık <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="baslik" name="baslik" required>
                            </div>
                            
                            <!-- Kod -->
                            <div class="col-md-4">
                                <label class="form-label">Kod</label>
                                <input type="text" class="form-control" id="kod" name="kod">
                            </div>
                            
                            <!-- Kategori -->
                            <div class="col-md-4">
                                <label class="form-label">Kategori</label>
                                <select class="form-select" id="kategori" name="kategori">
                                    <?php foreach ($kategoriler as $kat): ?>
                                        <option value="<?= $kat ?>" <?= $kat === 'Genel' ? 'selected' : '' ?>><?= $kat ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Durum -->
                            <div class="col-md-4">
                                <label class="form-label">Durum</label>
                                <select class="form-select" id="durum" name="durum">
                                    <?php foreach ($durumlar as $durum): ?>
                                        <option value="<?= $durum ?>" <?= $durum === 'Bekliyor' ? 'selected' : '' ?>><?= $durum ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Öncelik -->
                            <div class="col-md-4">
                                <label class="form-label">Öncelik</label>
                                <select class="form-select" id="oncelik" name="oncelik">
                                    <?php foreach ($oncelikler as $onc): ?>
                                        <option value="<?= $onc ?>" <?= $onc === 'Normal' ? 'selected' : '' ?>><?= $onc ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Başlangıç Tarihi -->
                            <div class="col-md-4">
                                <label class="form-label">Başlangıç Tarihi</label>
                                <input type="date" class="form-control" id="baslangic_tarihi" name="baslangic_tarihi">
                            </div>
                            
                            <!-- Bitiş Tarihi -->
                            <div class="col-md-4">
                                <label class="form-label">Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="bitis_tarihi" name="bitis_tarihi">
                            </div>
                            
                            <!-- Miktar -->
                            <div class="col-md-4">
                                <label class="form-label">Miktar</label>
                                <input type="number" step="0.01" class="form-control" id="miktar" name="miktar">
                            </div>
                            
                            <!-- Tutar -->
                            <div class="col-md-4">
                                <label class="form-label">Tutar (₺)</label>
                                <input type="number" step="0.01" class="form-control" id="tutar" name="tutar">
                            </div>
                            
                            <!-- Oran -->
                            <div class="col-md-4">
                                <label class="form-label">Oran (%)</label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="oran" name="oran">
                            </div>
                            
                            <!-- Etiketler -->
                            <div class="col-md-12">
                                <label class="form-label">Etiketler</label>
                                <input type="text" class="form-control" id="etiketler" name="etiketler" placeholder="etiket1,etiket2,etiket3">
                                <small class="text-muted">Virgülle ayırarak birden fazla etiket ekleyebilirsiniz</small>
                            </div>
                            
                            <!-- Açıklama -->
                            <div class="col-md-12">
                                <label class="form-label">Açıklama</label>
                                <textarea class="form-control" id="aciklama" name="aciklama" rows="3"></textarea>
                            </div>
                            
                            <!-- Notlar -->
                            <div class="col-md-12">
                                <label class="form-label">Notlar</label>
                                <textarea class="form-control" id="notlar" name="notlar" rows="2"></textarea>
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
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <!-- DataTables JS (ihtiyaca göre ekleyin) -->
    <!-- <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script> -->
    <!-- <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script> -->
    
    <script>
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        
        // Modal
        const modal = new bootstrap.Modal('#modalForm');
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-onemli').text(response.data.onemli);
                    $('#stat-tamamlandi').text(response.data.tamamlandi);
                    $('#stat-bekliyor').text(response.data.bekliyor);
                }
            });
        }
        
        // Filtre işlemleri
        let currentFilters = {};
        
        // Liste yükle (filtreli)
        function loadList() {
            $.post('', { 
                action: 'list',
                ...currentFilters 
            }, response => {
                if (response.success) {
                    renderTable(response.data);
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        // Tabloyu render et
        function renderTable(data) {
            const tbody = $('#tableBody');
            tbody.empty();
            
            if (data.length === 0) {
                tbody.html('<tr><td colspan="11" class="text-center">Kayıt bulunamadı</td></tr>');
                return;
            }
            
            data.forEach(item => {
                // Kategori badge rengi
                const kategoriColors = {
                    'Genel': 'primary',
                    'Önemli': 'danger',
                    'Planlı': 'info',
                    'Tamamlandı': 'success',
                    'Arşiv': 'secondary'
                };
                
                // Durum badge rengi
                const durumColors = {
                    'Bekliyor': 'warning',
                    'Devam Ediyor': 'info',
                    'Tamamlandı': 'success',
                    'İptal': 'danger'
                };
                
                // Öncelik badge rengi
                const oncelikColors = {
                    'Düşük': 'secondary',
                    'Normal': 'primary',
                    'Yüksek': 'warning',
                    'Acil': 'danger'
                };
                
                const row = `
                    <tr>
                        <td>${item.sablon_id}</td>
                        <td>${item.firma_adi || '-'}</td>
                        <td>${item.sube_adi || '-'}</td>
                        <td>${item.sablon_baslik || '-'}</td>
                        <td>${item.sablon_kod || '-'}</td>
                        <td><span class="badge bg-${kategoriColors[item.sablon_kategori] || 'primary'}">${item.sablon_kategori}</span></td>
                        <td><span class="badge bg-${durumColors[item.sablon_durum] || 'secondary'}">${item.sablon_durum}</span></td>
                        <td><span class="badge bg-${oncelikColors[item.sablon_oncelik] || 'primary'}">${item.sablon_oncelik}</span></td>
                        <td>${formatDate(item.sablon_olusturma_tarihi)}</td>
                        <td>${item.olusturan_adi || '-'}</td>
                        <td>
                            ${permissions.can_edit ? `<button class="btn btn-sm btn-warning" onclick="editRecord(${item.sablon_id})"><i class="bi bi-pencil"></i></button>` : ''}
                            ${permissions.can_delete ? `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${item.sablon_id})"><i class="bi bi-trash"></i></button>` : ''}
                        </td>
                    </tr>
                `;
                tbody.append(row);
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
                    minute: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }
        
        // Yeni kayıt ekle
        $('#btnYeniEkle').on('click', function() {
            $('#modalTitle').text('Yeni Kayıt Ekle');
            $('#saveForm')[0].reset();
            $('#sablon_id').val('');
            $('#sube_id').empty().append('<option value="">Seçiniz...</option>');
            initModalSelect2();
            modal.show();
        });
        
        // Kayıt düzenle
        function editRecord(id) {
            $.post('', { action: 'get', id: id }, response => {
                if (response.success) {
                    const data = response.data;
                    $('#modalTitle').text('Kayıt Düzenle');
                    $('#sablon_id').val(data.sablon_id);
                    $('#firma_id').val(data.sablon_firma_id);
                    $('#baslik').val(data.sablon_baslik);
                    $('#kod').val(data.sablon_kod);
                    $('#kategori').val(data.sablon_kategori);
                    $('#durum').val(data.sablon_durum);
                    $('#oncelik').val(data.sablon_oncelik);
                    $('#baslangic_tarihi').val(data.sablon_baslangic_tarihi ? data.sablon_baslangic_tarihi.split(' ')[0] : '');
                    $('#bitis_tarihi').val(data.sablon_bitis_tarihi ? data.sablon_bitis_tarihi.split(' ')[0] : '');
                    $('#miktar').val(data.sablon_miktar);
                    $('#tutar').val(data.sablon_tutar);
                    $('#oran').val(data.sablon_oran);
                    $('#etiketler').val(data.sablon_etiketler);
                    $('#aciklama').val(data.sablon_aciklama);
                    $('#notlar').val(data.sablon_notlar);
                    
                    // Şubeleri yükle
                    if (data.sablon_firma_id) {
                        loadSubeler(data.sablon_firma_id, data.sablon_sube_id);
                    }
                    
                    initModalSelect2();
                    modal.show();
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        // Kayıt sil
        function deleteRecord(id) {
            confirmAction(
                'Bu kaydı silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, response => {
                        if (response.success) {
                            showToast(response.message, 'success');
                            loadStats();
                            loadList();
                        } else {
                            showToast(response.message, 'error');
                        }
                    });
                }
            );
        }
        
        // Form kaydet
        $('#saveForm').on('submit', function(e) {
            e.preventDefault();
            
            // Form verilerini topla (boş değerleri gönderme)
            const formData = new FormData(this);
            formData.append('action', 'save');
            
            // Boş sayısal alanları kaldır
            if (!formData.get('miktar')) formData.delete('miktar');
            if (!formData.get('tutar')) formData.delete('tutar');
            if (!formData.get('oran')) formData.delete('oran');
            if (!formData.get('baslangic_tarihi')) formData.delete('baslangic_tarihi');
            if (!formData.get('bitis_tarihi')) formData.delete('bitis_tarihi');
            if (!formData.get('sube_id')) formData.delete('sube_id');
            if (!formData.get('kod')) formData.delete('kod');
            if (!formData.get('aciklama')) formData.delete('aciklama');
            if (!formData.get('etiketler')) formData.delete('etiketler');
            if (!formData.get('notlar')) formData.delete('notlar');
            
            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        modal.hide();
                        loadStats();
                        loadList();
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showToast('Kayıt işlemi başarısız: ' + error, 'error');
                }
            });
        });
        
        // Firma değiştiğinde şubeleri yükle (Select2 eventi)
        $('#firma_id').on('select2:select', function(e) {
            const firmaId = $(this).val();
            if (firmaId) {
                loadSubeler(firmaId);
            } else {
                $('#sube_id').empty().append('<option value="">Seçiniz...</option>');
            }
        });
        
        // Firma temizlendiğinde
        $('#firma_id').on('select2:clear', function() {
            $('#sube_id').empty().append('<option value="">Seçiniz...</option>').trigger('change.select2');
        });
        
        // Şubeleri yükle
        function loadSubeler(firmaId, selectedSubeId = null) {
            console.log('Şubeler yükleniyor, Firma ID:', firmaId);
            $.post('', { action: 'get_subeler', firma_id: firmaId }, response => {
                console.log('Şube response:', response);
                if (response.success) {
                    const subeSelect = $('#sube_id');
                    console.log('Şube sayısı:', response.data.length);
                    
                    // Select2'yi geçici olarak kaldır
                    if (subeSelect.hasClass("select2-hidden-accessible")) {
                        subeSelect.select2('destroy');
                    }
                    
                    // Option'ları temizle ve yeniden ekle
                    subeSelect.empty().append('<option value="">Seçiniz...</option>');
                    
                    response.data.forEach(sube => {
                        const selected = selectedSubeId && sube.sube_id == selectedSubeId ? 'selected' : '';
                        subeSelect.append(`<option value="${sube.sube_id}" ${selected}>${sube.sube_adi}</option>`);
                    });
                    
                    // Select2'yi yeniden başlat
                    subeSelect.select2({
                        theme: 'bootstrap-5',
                        dropdownParent: $('#modalForm'),
                        placeholder: 'Seçiniz...',
                        allowClear: true,
                        language: {
                            noResults: function() { return "Sonuç bulunamadı"; },
                            searching: function() { return "Aranıyor..."; }
                        }
                    });
                } else {
                    showToast('Şubeler yüklenemedi', 'error');
                }
            }).fail(function() {
                showToast('Şubeler yüklenirken hata oluştu', 'error');
            });
        }
        
        // Modal Select2 başlat
        function initModalSelect2() {
            $('#firma_id, #sube_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#modalForm'),
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
        }
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            currentFilters = {
                start_date: $('#filter_start_date').val(),
                end_date: $('#filter_end_date').val(),
                search: $('#filter_search').val(),
                kategori: $('#filter_kategori').val(),
                durum: $('#filter_durum').val(),
                oncelik: $('#filter_oncelik').val()
            };
            
            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) delete currentFilters[key];
            });
            
            loadList();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_kategori').val('').trigger('change.select2');
            $('#filter_durum').val('').trigger('change.select2');
            $('#filter_oncelik').val('').trigger('change.select2');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Sayfa yüklendiğinde
        $(document).ready(function() {
            // Select2 başlat (filtre dropdown'ları)
            $('#filter_kategori, #filter_durum, #filter_oncelik').select2({
                theme: 'bootstrap-5',
                placeholder: 'Tümü',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // İstatistik ve liste yükle
            loadStats();
            loadList();
        });
    </script>
</body>
</html>
