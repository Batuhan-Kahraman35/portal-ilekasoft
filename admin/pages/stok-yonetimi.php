<?php
/**
 * Admin Panel - Stok Yönetimi
 * 
 * Ürün/Hizmet Giriş-Çıkış İşlemleri (Fatura Benzeri Sistem)
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

// Erişim yetkisi yoksa hata göster
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
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

// Sayfa bilgileri
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Stok Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Şube, Cari, Ürün, KDV listelerini çek
$subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");
$cariler = $db->fetchAll("SELECT cari_id, cari_adi FROM Cari WHERE cari_aktif = 1 ORDER BY cari_adi");
$urunler = $db->fetchAll("SELECT urun_hizmet_id, urun_hizmet_adi, urun_hizmet_birim, urun_hizmet_satis_fiyati, urun_hizmet_kdv_id, urun_hizmet_serino FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");
$kdvler = $db->fetchAll("SELECT kdv_id, kdv_adi, kdv_oran FROM KDV_Tanimlari WHERE kdv_durum = 1 ORDER BY kdv_oran");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam_islem' => $db->fetchOne("SELECT COUNT(DISTINCT stok_hareket_fatura_no) as sayi FROM Stok_Hareket WHERE (stok_hareket_durum = 1 OR stok_hareket_durum IS NULL)")['sayi'] ?? 0,
                    'giris_islem' => $db->fetchOne("SELECT COUNT(DISTINCT stok_hareket_fatura_no) as sayi FROM Stok_Hareket WHERE stok_hareket_tipi = 0 AND (stok_hareket_durum = 1 OR stok_hareket_durum IS NULL)")['sayi'] ?? 0,
                    'cikis_islem' => $db->fetchOne("SELECT COUNT(DISTINCT stok_hareket_fatura_no) as sayi FROM Stok_Hareket WHERE stok_hareket_tipi = 1 AND (stok_hareket_durum = 1 OR stok_hareket_durum IS NULL)")['sayi'] ?? 0,
                    'bugun_islem' => $db->fetchOne("SELECT COUNT(DISTINCT stok_hareket_fatura_no) as sayi FROM Stok_Hareket WHERE CONVERT(date, stok_hareket_tarihi) = CONVERT(date, GETDATE()) AND (stok_hareket_durum = 1 OR stok_hareket_durum IS NULL)")['sayi'] ?? 0
                ];
                error_log('Stats: ' . print_r($stats, true));
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtreleri al
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $belgeTipi = $_POST['belge_tipi'] ?? '';
                $subeId = $_POST['sube_id'] ?? '';
                $cariId = $_POST['cari_id'] ?? '';
                $search = $_POST['search'] ?? '';
                
                // WHERE koşulları
                $whereConditions = ["(sh.stok_hareket_durum = 1 OR sh.stok_hareket_durum IS NULL)"];
                $params = [];
                
                if ($startDate) {
                    $whereConditions[] = "CONVERT(date, sh.stok_hareket_tarihi) >= ?";
                    $params[] = $startDate;
                }
                
                if ($endDate) {
                    $whereConditions[] = "CONVERT(date, sh.stok_hareket_tarihi) <= ?";
                    $params[] = $endDate;
                }
                
                if ($tip !== '') {
                    $whereConditions[] = "sh.stok_hareket_tipi = ?";
                    $params[] = $tip;
                }
                
                if ($belgeTipi) {
                    $whereConditions[] = "sh.stok_hareket_belge_tipi = ?";
                    $params[] = $belgeTipi;
                }
                
                if ($subeId) {
                    $whereConditions[] = "sh.stok_hareket_sube_id = ?";
                    $params[] = $subeId;
                }
                
                if ($cariId) {
                    $whereConditions[] = "sh.stok_hareket_cari_id = ?";
                    $params[] = $cariId;
                }
                
                if ($search) {
                    $whereConditions[] = "sh.stok_hareket_fatura_no LIKE ?";
                    $params[] = "%$search%";
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Fatura listesini getir (gruplama ile)
                $list = $db->fetchAll("
                    SELECT 
                        stok_hareket_fatura_no,
                        MIN(CAST(stok_hareket_tipi AS INT)) as stok_hareket_tipi,
                        MIN(stok_hareket_belge_tipi) as stok_hareket_belge_tipi,
                        MIN(CONVERT(VARCHAR(19), stok_hareket_tarihi, 120)) as stok_hareket_tarihi,
                        MIN(stok_hareket_sube_id) as stok_hareket_sube_id,
                        MIN(s.sube_adi) as sube_adi,
                        MIN(stok_hareket_cari_id) as stok_hareket_cari_id,
                        MIN(c.cari_adi) as cari_adi,
                        COUNT(*) as satir_sayisi,
                        SUM(stok_hareket_satir_toplam) as genel_toplam,
                        MIN(CONVERT(VARCHAR(19), stok_hareket_olusturma_tarihi, 120)) as stok_hareket_olusturma_tarihi
                    FROM Stok_Hareket sh
                    LEFT JOIN Subeler s ON sh.stok_hareket_sube_id = s.sube_id
                    LEFT JOIN Cari c ON sh.stok_hareket_cari_id = c.cari_id
                    WHERE $whereClause
                    GROUP BY stok_hareket_fatura_no
                    ORDER BY MIN(stok_hareket_tarihi) DESC, stok_hareket_fatura_no DESC
                ", $params);
                
                echo json_encode(['success' => true, 'data' => $list]);
                break;
                
            case 'get':
                // Fatura detayını getir
                $faturaNo = $_POST['fatura_no'] ?? '';
                $detay = $db->fetchAll("
                    SELECT 
                        sh.*,
                        u.urun_hizmet_adi,
                        u.urun_hizmet_birim,
                        k.kdv_adi,
                        k.kdv_oran
                    FROM Stok_Hareket sh
                    LEFT JOIN Urun_Hizmet u ON sh.urun_hizmet_id = u.urun_hizmet_id
                    LEFT JOIN KDV_Tanimlari k ON sh.stok_hareket_kdv_id = k.kdv_id
                    WHERE sh.stok_hareket_fatura_no = ?
                    ORDER BY sh.stok_hareket_sira_no
                ", [$faturaNo]);
                echo json_encode(['success' => true, 'data' => $detay]);
                break;
                
            case 'save':
                // Yeni stok hareketi kaydet
                $faturaNo = $_POST['fatura_no'] ?? '';
                $tip = $_POST['tip'] ?? 0;
                $belgeTipi = $_POST['belge_tipi'] ?? 'FATURA';
                $belgeTarihi = $_POST['belge_tarihi'] ?? date('Y-m-d');
                $subeId = $_POST['sube_id'] ?: null;
                $cariId = $_POST['cari_id'] ?: null;
                $aciklama = $_POST['aciklama'] ?? '';
                $referans = $_POST['referans'] ?? '';
                
                $urunler = json_decode($_POST['urunler'] ?? '[]', true);
                
                if (empty($faturaNo) || empty($urunler)) {
                    throw new Exception('Fatura no ve ürün bilgileri zorunludur!');
                }
                
                // Aynı fatura no var mı kontrol et
                $mevcutFatura = $db->fetchOne("SELECT COUNT(*) as sayi FROM Stok_Hareket WHERE stok_hareket_fatura_no = ?", [$faturaNo]);
                if ($mevcutFatura['sayi'] > 0) {
                    throw new Exception('Bu fatura numarası daha önce kullanılmış!');
                }
                
                // Ürün satırlarını kaydet
                $siraNo = 1;
                foreach ($urunler as $urun) {
                    $data = [
                        'stok_hareket_fatura_no' => $faturaNo,
                        'stok_hareket_tipi' => $tip,
                        'stok_hareket_belge_tipi' => $belgeTipi,
                        'stok_hareket_tarihi' => $belgeTarihi,
                        'stok_hareket_sube_id' => $subeId,
                        'stok_hareket_cari_id' => $cariId,
                        'urun_hizmet_id' => $urun['urun_id'],
                        'stok_hareket_seri_no' => !empty($urun['seri_no']) ? $urun['seri_no'] : null,
                        'stok_hareket_miktar' => $urun['miktar'],
                        'stok_hareket_birim' => $urun['birim'],
                        'stok_hareket_birim_fiyat' => $urun['birim_fiyat'],
                        'stok_hareket_kdv_id' => $urun['kdv_id'],
                        'stok_hareket_kdv_tutari' => $urun['kdv_tutari'],
                        'stok_hareket_ara_toplam' => $urun['ara_toplam'],
                        'stok_hareket_satir_toplam' => $urun['satir_toplam'],
                        'stok_hareket_aciklama' => $aciklama,
                        'stok_hareket_referans' => $referans,
                        'stok_hareket_sira_no' => $siraNo,
                        'stok_hareket_durum' => 1,
                        'stok_hareket_olusturan_kullanici_id' => $user['kullanici_id']
                    ];
                    
                    $db->insert('Stok_Hareket', $data);
                    $siraNo++;
                }
                
                echo json_encode(['success' => true, 'message' => 'Stok hareketi başarıyla kaydedildi!']);
                break;
                
            case 'delete':
                // Fatura sil (tüm satırları)
                $faturaNo = $_POST['fatura_no'] ?? '';
                $db->execute("DELETE FROM Stok_Hareket WHERE stok_hareket_fatura_no = ?", [$faturaNo]);
                echo json_encode(['success' => true, 'message' => 'Stok hareketi başarıyla silindi!']);
                break;
                
            case 'get_seri_no':
                // Ürüne göre stokta bulunan seri numaralarını getir
                $urunId = $_POST['urun_id'] ?? 0;
                
                // Giriş yapılan seri nolar - Çıkış yapılan seri nolar = Stokta olan
                $seriNolar = $db->fetchAll("
                    SELECT DISTINCT stok_hareket_seri_no as seri_no
                    FROM Stok_Hareket 
                    WHERE urun_hizmet_id = ? 
                        AND stok_hareket_tipi = 0 
                        AND stok_hareket_seri_no IS NOT NULL 
                        AND stok_hareket_seri_no != ''
                        AND stok_hareket_seri_no NOT IN (
                            SELECT stok_hareket_seri_no 
                            FROM Stok_Hareket 
                            WHERE urun_hizmet_id = ? 
                                AND stok_hareket_tipi = 1 
                                AND stok_hareket_seri_no IS NOT NULL
                        )
                    ORDER BY stok_hareket_seri_no
                ", [$urunId, $urunId]);
                
                echo json_encode(['success' => true, 'data' => $seriNolar]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
        .urun-satirlari {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .urun-satiri {
            background: #f8f9fa;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
            position: relative;
        }
        .urun-satiri .btn-remove {
            position: absolute;
            top: 10px;
            right: 10px;
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
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon">
                                    <i class="bi bi-box-seam-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam İşlem</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon">
                                    <i class="bi bi-arrow-down-circle-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Giriş İşlemi</span>
                                    <span class="info-box-number" id="stat-giris">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon">
                                    <i class="bi bi-arrow-up-circle-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Çıkış İşlemi</span>
                                    <span class="info-box-number" id="stat-cikis">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon">
                                    <i class="bi bi-calendar-check-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün</span>
                                    <span class="info-box-number" id="stat-bugun">0</span>
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
                                        <label class="form-label">İşlem Tipi</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <option value="0">Giriş</option>
                                            <option value="1">Çıkış</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Belge Tipi</label>
                                        <select class="form-select" name="belge_tipi" id="filter_belge_tipi">
                                            <option value="">Tümü</option>
                                            <option value="FATURA">Fatura</option>
                                            <option value="IRSALIYE">İrsaliye</option>
                                            <option value="IADE">İade</option>
                                            <option value="TRANSFER">Transfer</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şube</label>
                                        <select class="form-select" name="sube_id" id="filter_sube_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($subeler as $sube): ?>
                                                <option value="<?= $sube['sube_id'] ?>"><?= htmlspecialchars($sube['sube_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Cari</label>
                                        <select class="form-select" name="cari_id" id="filter_cari_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($cariler as $cari): ?>
                                                <option value="<?= $cari['cari_id'] ?>"><?= htmlspecialchars($cari['cari_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Fatura no...">
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
                    
                    <!-- Stok Hareket Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Stok Hareketleri</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni İşlem
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="stokTable">
                                    <thead>
                                        <tr>
                                            <th>Fatura No</th>
                                            <th>Tip</th>
                                            <th>Belge</th>
                                            <th>Tarih</th>
                                            <th>Şube</th>
                                            <th>Cari</th>
                                            <th>Satır</th>
                                            <th>Toplam Fiyat</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Dinamik içerik -->
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
    
    <!-- Stok Modal -->
    <div class="modal fade" id="stokModal" tabindex="-1" aria-labelledby="stokModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="stokModalLabel">Yeni Stok Hareketi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="stokForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Genel Bilgiler -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3">Genel Bilgiler</h6>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Fatura No *</label>
                                <input type="text" class="form-control" id="fatura_no" name="fatura_no" required>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">İşlem Tipi *</label>
                                <select class="form-select" id="tip" name="tip" required>
                                    <option value="0">Giriş</option>
                                    <option value="1">Çıkış</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Belge Tipi *</label>
                                <select class="form-select" id="belge_tipi" name="belge_tipi" required>
                                    <option value="FATURA">Fatura</option>
                                    <option value="IRSALIYE">İrsaliye</option>
                                    <option value="IADE">İade</option>
                                    <option value="TRANSFER">Transfer</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">Belge Tarihi *</label>
                                <input type="date" class="form-control" id="belge_tarihi" name="belge_tarihi" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Şube</label>
                                <select class="form-select" id="sube_id" name="sube_id">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($subeler as $sube): ?>
                                        <option value="<?= $sube['sube_id'] ?>"><?= htmlspecialchars($sube['sube_adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Cari (Müşteri/Tedarikçi)</label>
                                <select class="form-select" id="cari_id" name="cari_id">
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($cariler as $cari): ?>
                                        <option value="<?= $cari['cari_id'] ?>"><?= htmlspecialchars($cari['cari_adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Referans</label>
                                <input type="text" class="form-control" id="referans" name="referans">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Açıklama</label>
                                <input type="text" class="form-control" id="aciklama" name="aciklama">
                            </div>
                            
                            <!-- Ürün Satırları -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">Ürün/Hizmet Satırları</h6>
                            </div>
                            
                            <div class="col-12">
                                <div class="urun-satirlari" id="urun-satirlari">
                                    <!-- Dinamik satırlar buraya eklenecek -->
                                </div>
                                <button type="button" class="btn btn-success btn-sm" onclick="addUrunSatiri()">
                                    <i class="bi bi-plus-circle"></i> Satır Ekle
                                </button>
                            </div>
                            
                            <!-- Toplam Bilgileri -->
                            <div class="col-12">
                                <div class="row">
                                    <div class="col-md-9"></div>
                                    <div class="col-md-3">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Ara Toplam:</th>
                                                <td class="text-end" id="genel-ara-toplam">0.00 ₺</td>
                                            </tr>
                                            <tr>
                                                <th>KDV:</th>
                                                <td class="text-end" id="genel-kdv">0.00 ₺</td>
                                            </tr>
                                            <tr class="table-primary">
                                                <th>Genel Toplam:</th>
                                                <th class="text-end" id="genel-toplam">0.00 ₺</th>
                                            </tr>
                                        </table>
                                    </div>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let stokModal;
        let urunSatirNo = 0;
        let currentFilters = {};
        
        // Sayfa yetkileri
        const pagePermissions = <?= json_encode($pagePermissions) ?>;
        console.log('Sayfa Yetkileri:', pagePermissions);
        
        // Ürünler ve KDV'ler
        const urunler = <?= json_encode($urunler) ?>;
        const kdvler = <?= json_encode($kdvler) ?>;
        
        console.log('Ürünler:', urunler);
        console.log('KDV\'ler:', kdvler);
        
        $(document).ready(function() {
            // Select2 otomatik başlatılıyor (custom.js'de)
            
            stokModal = new bootstrap.Modal(document.getElementById('stokModal'));
            loadStats();
            loadList();
            console.log('Sayfa yüklendi, liste getiriliyor...');
            
            // İşlem tipi değiştiğinde satırları yenile
            $('#tip').on('change', function() {
                $('#urun-satirlari').empty();
                urunSatirNo = 0;
                addUrunSatiri();
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentFilters = {
                    start_date: $('#filter_start_date').val(),
                    end_date: $('#filter_end_date').val(),
                    tip: $('#filter_tip').val(),
                    belge_tipi: $('#filter_belge_tipi').val(),
                    sube_id: $('#filter_sube_id').val(),
                    cari_id: $('#filter_cari_id').val(),
                    search: $('#filter_search').val()
                };
                loadList();
                showToast('Filtre uygulandı', 'success');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip').val('').trigger('change.select2');
                $('#filter_belge_tipi').val('').trigger('change.select2');
                $('#filter_sube_id').val('').trigger('change.select2');
                $('#filter_cari_id').val('').trigger('change.select2');
                currentFilters = {};
                loadList();
                showToast('Filtreler temizlendi', 'info');
            });
        });
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam_islem);
                    $('#stat-giris').text(response.data.giris_islem);
                    $('#stat-cikis').text(response.data.cikis_islem);
                    $('#stat-bugun').text(response.data.bugun_islem);
                }
            });
        }
        
        // Listeyi yükle
        function loadList() {
            $.post('', { 
                action: 'list',
                ...currentFilters
            }, response => {
                console.log('Liste AJAX yanıtı:', response);
                if (response.success) {
                    console.log('Veri sayısı:', response.data.length);
                    renderTable(response.data);
                } else {
                    console.error('Liste yüklenemedi:', response.message);
                }
            });
        }
        
        // Tabloyu render et
        function renderTable(data) {
            console.log('renderTable çağrıldı, data:', data);
            const tbody = $('#stokTable tbody');
            tbody.empty();
            
            if (data.length === 0) {
                tbody.append('<tr><td colspan="9" class="text-center">Kayıt bulunamadı</td></tr>');
                return;
            }
            
            data.forEach(item => {
                console.log('Satır ekleniyor:', item);
                const tipBadge = item.stok_hareket_tipi == 0 
                    ? '<span class="badge bg-success">Giriş</span>' 
                    : '<span class="badge bg-warning">Çıkış</span>';
                
                // Butonları yetkilere göre oluştur
                let buttons = '';
                if (pagePermissions.can_view) {
                    buttons += `<button class="btn btn-sm btn-info" onclick="viewDetail('${item.stok_hareket_fatura_no}')" title="Görüntüle">
                                <i class="bi bi-eye"></i>
                            </button>`;
                }
                if (pagePermissions.can_delete) {
                    buttons += ` <button class="btn btn-sm btn-danger" onclick="deleteItem('${item.stok_hareket_fatura_no}')" title="Sil">
                                <i class="bi bi-trash"></i>
                            </button>`;
                }
                if (!buttons) {
                    buttons = '<span class="text-muted">-</span>';
                }
                
                const row = `
                    <tr>
                        <td>${item.stok_hareket_fatura_no}</td>
                        <td>${tipBadge}</td>
                        <td>${item.stok_hareket_belge_tipi}</td>
                        <td>${item.stok_hareket_tarihi ? new Date(item.stok_hareket_tarihi).toLocaleDateString('tr-TR') : '-'}</td>
                        <td>${item.sube_adi || '-'}</td>
                        <td>${item.cari_adi || '-'}</td>
                        <td>${item.satir_sayisi}</td>
                        <td class="text-end">${parseFloat(item.genel_toplam || 0).toFixed(2)} ₺</td>
                        <td>${buttons}</td>
                    </tr>
                `;
                tbody.append(row);
            });
            console.log('Toplam satır eklendi:', data.length);
        }
        
        // Modal aç
        function openModal() {
            $('#stokForm')[0].reset();
            $('.form-select').val(null).trigger('change'); // Select2'yi temizle
            $('#urun-satirlari').empty();
            urunSatirNo = 0;
            addUrunSatiri(); // İlk satırı ekle
            stokModal.show();
        }
        
        // Ürün satırı ekle
        function addUrunSatiri() {
            urunSatirNo++;
            const satirSayisi = $('.urun-satiri').length;
            const satirNo = satirSayisi + 1;
            const islemTipi = $('#tip').val(); // 0=Giriş, 1=Çıkış
            
            // Seri no alanı: Giriş için textarea, Çıkış için select (ürün seçildikten sonra doldurulacak)
            const seriNoField = islemTipi === '1' ? 
                `<select class="form-select seri-no-select" data-satir="${urunSatirNo}" multiple size="3">
                    <option value="">Önce ürün seçin</option>
                </select>` :
                `<textarea class="form-control seri-no-input" data-satir="${urunSatirNo}" rows="3" placeholder="Her satıra bir seri no"></textarea>`;
            
            const labelHtml = satirSayisi === 0 ? `
                        <div class="col-auto" style="width: 40px;">
                            <label class="form-label">#</label>
                            <div class="form-control-plaintext text-center fw-bold">${satirNo}</div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Ürün/Hizmet *</label>
                            <select class="form-select urun-select" data-satir="${urunSatirNo}" required>
                                <option value="">Seçiniz...</option>
                                ${urunler.map(u => `<option value="${u.urun_hizmet_id}" data-birim="${u.urun_hizmet_birim}" data-fiyat="${u.urun_hizmet_satis_fiyati}" data-kdv="${u.urun_hizmet_kdv_id}" data-serino="${u.urun_hizmet_serino}">${u.urun_hizmet_adi}</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Seri No ${islemTipi === '1' ? '<small class="text-muted">(Stoktan seç)</small>' : ''}</label>
                            ${seriNoField}
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Miktar *</label>
                            <input type="number" class="form-control miktar-input" data-satir="${urunSatirNo}" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Birim</label>
                            <input type="text" class="form-control birim-input" data-satir="${urunSatirNo}" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Birim Fiyat *</label>
                            <input type="number" class="form-control fiyat-input" data-satir="${urunSatirNo}" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">KDV</label>
                            <select class="form-select kdv-select" data-satir="${urunSatirNo}">
                                <option value="">Seç</option>
                                ${kdvler.map(k => `<option value="${k.kdv_id}" data-oran="${k.kdv_oran}">${k.kdv_oran}%</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Toplam Fiyat</label>
                            <input type="text" class="form-control satir-toplam" data-satir="${urunSatirNo}" readonly>
                        </div>
            ` : `
                        <div class="col-auto" style="width: 40px;">
                            <div class="form-control-plaintext text-center fw-bold">${satirNo}</div>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select urun-select" data-satir="${urunSatirNo}" required>
                                <option value="">Seçiniz...</option>
                                ${urunler.map(u => `<option value="${u.urun_hizmet_id}" data-birim="${u.urun_hizmet_birim}" data-fiyat="${u.urun_hizmet_satis_fiyati}" data-kdv="${u.urun_hizmet_kdv_id}" data-serino="${u.urun_hizmet_serino}">${u.urun_hizmet_adi}</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-md-2">
                            ${seriNoField}
                        </div>
                        <div class="col-md-1">
                            <input type="number" class="form-control miktar-input" data-satir="${urunSatirNo}" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-1">
                            <input type="text" class="form-control birim-input" data-satir="${urunSatirNo}" readonly>
                        </div>
                        <div class="col-md-2">
                            <input type="number" class="form-control fiyat-input" data-satir="${urunSatirNo}" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-1">
                            <select class="form-select kdv-select" data-satir="${urunSatirNo}">
                                <option value="">Seç</option>
                                ${kdvler.map(k => `<option value="${k.kdv_id}" data-oran="${k.kdv_oran}">${k.kdv_oran}%</option>`).join('')}
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control satir-toplam" data-satir="${urunSatirNo}" readonly>
                        </div>
            `;
            const satir = `
                <div class="urun-satiri" id="satir-${urunSatirNo}">
                    <button type="button" class="btn btn-sm btn-danger btn-remove" onclick="removeSatir(${urunSatirNo})">
                        <i class="bi bi-x"></i>
                    </button>
                    <div class="row g-2">
                        ${labelHtml}
                    </div>
                </div>
            `;
            $('#urun-satirlari').append(satir);
            
            // Yeni eklenen satırdaki select'lere Select2 uygula
            $(`#satir-${urunSatirNo} .form-select`).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true,
                dropdownParent: $('#stokModal'), // Modal içinde düzgün çalışması için
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Event listeners
            $(`[data-satir="${urunSatirNo}"]`).on('change input', function() {
                hesaplaSatir(urunSatirNo);
            });
            
            // GİRİŞ için event listener'lar
            if (islemTipi === '0') {
                // Seri no girilince miktar readonly yap ve otomatik say
                $(`.seri-no-input[data-satir="${urunSatirNo}"]`).on('input', function() {
                    const seriNoText = $(this).val().trim();
                    const satirSayisi = seriNoText ? seriNoText.split('\n').filter(s => s.trim() !== '').length : 0;
                    const miktarInput = $(`.miktar-input[data-satir="${urunSatirNo}"]`);
                    
                    if (satirSayisi > 0) {
                        miktarInput.val(satirSayisi).prop('readonly', true).addClass('bg-light');
                    } else {
                        miktarInput.prop('readonly', false).removeClass('bg-light');
                    }
                    hesaplaSatir(urunSatirNo);
                });
                
                // Miktar girilince seri no readonly yap
                $(`.miktar-input[data-satir="${urunSatirNo}"]`).on('input', function() {
                    const miktar = parseFloat($(this).val()) || 0;
                    const seriNoInput = $(`.seri-no-input[data-satir="${urunSatirNo}"]`);
                    const seriNoText = seriNoInput.val().trim();
                    
                    if (miktar > 0 && !seriNoText) {
                        seriNoInput.prop('readonly', true).addClass('bg-light').attr('placeholder', 'Miktar girildi, seri no devre dışı');
                    } else if (!seriNoText) {
                        seriNoInput.prop('readonly', false).removeClass('bg-light').attr('placeholder', 'Her satıra bir seri no');
                    }
                });
            } 
            // ÇIKIŞ için event listener'lar
            else if (islemTipi === '1') {
                // Seri no seçildiğinde miktar otomatik ayarla
                $(`.seri-no-select[data-satir="${urunSatirNo}"]`).on('change', function() {
                    const secilenSayisi = $(this).val() ? $(this).val().length : 0;
                    const miktarInput = $(`.miktar-input[data-satir="${urunSatirNo}"]`);
                    
                    if (secilenSayisi > 0) {
                        miktarInput.val(secilenSayisi).prop('readonly', true).addClass('bg-light');
                    } else {
                        miktarInput.val('').prop('readonly', false).removeClass('bg-light');
                    }
                    hesaplaSatir(urunSatirNo);
                });
            }
            
            // Ürün seçildiğinde otomatik doldur
            $(`.urun-select[data-satir="${urunSatirNo}"]`).on('change', function() {
                const selected = $(this).find(':selected');
                const kdvId = selected.data('kdv');
                const urunId = $(this).val();
                const serinoTakipli = selected.data('serino') == 1; // Seri no takipli mi?
                
                $(`.birim-input[data-satir="${urunSatirNo}"]`).val(selected.data('birim') || '');
                $(`.fiyat-input[data-satir="${urunSatirNo}"]`).val(selected.data('fiyat') || 0);
                
                // KDV'yi seç (ID'ye göre)
                if (kdvId) {
                    $(`.kdv-select[data-satir="${urunSatirNo}"]`).val(kdvId);
                }
                
                // Seri no takipli ürün ise seri no alanını zorunlu yap ve miktar readonly
                if (serinoTakipli) {
                    if (islemTipi === '0') {
                        // Giriş: Textarea zorunlu, miktar readonly
                        $(`.seri-no-input[data-satir="${urunSatirNo}"]`).prop('required', true).attr('placeholder', 'Her satıra bir seri no (ZORUNLU)');
                        $(`.miktar-input[data-satir="${urunSatirNo}"]`).prop('readonly', true).addClass('bg-light').attr('title', 'Seri no takipli ürün: Miktar otomatik hesaplanır');
                    } else if (islemTipi === '1') {
                        // Çıkış: Select zorunlu, miktar readonly
                        $(`.seri-no-select[data-satir="${urunSatirNo}"]`).prop('required', true);
                        $(`.miktar-input[data-satir="${urunSatirNo}"]`).prop('readonly', true).addClass('bg-light').attr('title', 'Seri no takipli ürün: Miktar otomatik hesaplanır');
                    }
                } else {
                    // Normal ürün: Seri no opsiyonel, miktar düzenlenebilir
                    if (islemTipi === '0') {
                        $(`.seri-no-input[data-satir="${urunSatirNo}"]`).prop('required', false).attr('placeholder', 'Her satıra bir seri no (Opsiyonel)');
                        $(`.miktar-input[data-satir="${urunSatirNo}"]`).prop('readonly', false).removeClass('bg-light').removeAttr('title');
                    } else if (islemTipi === '1') {
                        $(`.seri-no-select[data-satir="${urunSatirNo}"]`).prop('required', false);
                        $(`.miktar-input[data-satir="${urunSatirNo}"]`).prop('readonly', false).removeClass('bg-light').removeAttr('title');
                    }
                }
                
                // Eğer Çıkış işlemi ise, seri noları yükle
                if (islemTipi === '1' && urunId) {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'get_seri_no',
                            urun_id: urunId
                        },
                        success: function(response) {
                            if (response.success) {
                                const seriNoSelect = $(`.seri-no-select[data-satir="${urunSatirNo}"]`);
                                seriNoSelect.empty();
                                
                                if (response.data.length > 0) {
                                    response.data.forEach(item => {
                                        seriNoSelect.append(`<option value="${item.seri_no}">${item.seri_no}</option>`);
                                    });
                                } else {
                                    seriNoSelect.append(`<option value="">Bu üründe stok yok</option>`);
                                }
                            }
                        },
                        error: function() {
                            showToast('Seri noları yüklenirken hata oluştu!', 'error');
                        }
                    });
                }
                
                hesaplaSatir(urunSatirNo);
            });
        }
        
        // Satır sil
        function removeSatir(satirNo) {
            $(`#satir-${satirNo}`).remove();
            hesaplaGenelToplam();
        }
        
        // Satır hesapla
        function hesaplaSatir(satirNo) {
            const miktar = parseFloat($(`.miktar-input[data-satir="${satirNo}"]`).val()) || 0;
            const fiyat = parseFloat($(`.fiyat-input[data-satir="${satirNo}"]`).val()) || 0;
            const kdvOran = parseFloat($(`.kdv-select[data-satir="${satirNo}"] :selected`).data('oran')) || 0;
            
            const araToplam = miktar * fiyat;
            const kdvTutar = araToplam * (kdvOran / 100);
            const satirToplam = araToplam + kdvTutar;
            
            $(`.satir-toplam[data-satir="${satirNo}"]`).val(satirToplam.toFixed(2) + ' ₺');
            
            hesaplaGenelToplam();
        }
        
        // Genel toplam hesapla
        function hesaplaGenelToplam() {
            let toplamAraToplam = 0;
            let toplamKdv = 0;
            
            $('.urun-satiri').each(function() {
                const satirNo = $(this).attr('id').split('-')[1];
                const miktar = parseFloat($(`.miktar-input[data-satir="${satirNo}"]`).val()) || 0;
                const fiyat = parseFloat($(`.fiyat-input[data-satir="${satirNo}"]`).val()) || 0;
                const kdvOran = parseFloat($(`.kdv-select[data-satir="${satirNo}"] :selected`).data('oran')) || 0;
                
                const araToplam = miktar * fiyat;
                const kdvTutar = araToplam * (kdvOran / 100);
                
                toplamAraToplam += araToplam;
                toplamKdv += kdvTutar;
            });
            
            const genelToplam = toplamAraToplam + toplamKdv;
            
            $('#genel-ara-toplam').text(toplamAraToplam.toFixed(2) + ' ₺');
            $('#genel-kdv').text(toplamKdv.toFixed(2) + ' ₺');
            $('#genel-toplam').text(genelToplam.toFixed(2) + ' ₺');
        }
        
        // Form kaydet
        $('#stokForm').on('submit', function(e) {
            e.preventDefault();
            
            const islemTipi = $('#tip').val();
            
            // Ürün satırlarını topla
            const urunListesi = [];
            
            $('.urun-satiri').each(function() {
                const satirNo = $(this).attr('id').split('-')[1];
                const urunId = $(`.urun-select[data-satir="${satirNo}"]`).val();
                
                // İşlem tipine göre seri no al
                let seriNoSatirlari = [];
                if (islemTipi === '0') {
                    // Giriş: Textarea'dan al
                    const seriNoText = $(`.seri-no-input[data-satir="${satirNo}"]`).val().trim();
                    seriNoSatirlari = seriNoText ? seriNoText.split('\n').filter(s => s.trim() !== '') : [];
                } else if (islemTipi === '1') {
                    // Çıkış: Select'ten seçilenleri al
                    const secilenler = $(`.seri-no-select[data-satir="${satirNo}"]`).val();
                    seriNoSatirlari = secilenler && secilenler.length > 0 ? secilenler : [];
                }
                
                const miktar = parseFloat($(`.miktar-input[data-satir="${satirNo}"]`).val()) || 0;
                const birim = $(`.birim-input[data-satir="${satirNo}"]`).val();
                const fiyat = parseFloat($(`.fiyat-input[data-satir="${satirNo}"]`).val()) || 0;
                const kdvId = $(`.kdv-select[data-satir="${satirNo}"]`).val();
                const kdvOran = parseFloat($(`.kdv-select[data-satir="${satirNo}"] :selected`).data('oran')) || 0;
                
                const araToplam = miktar * fiyat;
                const kdvTutar = araToplam * (kdvOran / 100);
                const satirToplam = araToplam + kdvTutar;
                
                if (urunId && miktar > 0) {
                    // Seri no varsa her biri için ayrı satır, yoksa tek satır
                    if (seriNoSatirlari.length > 0) {
                        // Her seri no için ayrı satır (miktar otomatik olarak seri no sayısına eşit)
                        seriNoSatirlari.forEach((seriNo, index) => {
                            urunListesi.push({
                                urun_id: urunId,
                                seri_no: seriNo.trim(),
                                miktar: 1, // Her seri no için miktar 1
                                birim: birim,
                                birim_fiyat: fiyat,
                                kdv_id: kdvId,
                                kdv_tutari: (fiyat * (kdvOran / 100)),
                                ara_toplam: fiyat,
                                satir_toplam: fiyat + (fiyat * (kdvOran / 100))
                            });
                        });
                    } else {
                        // Seri no yoksa, tek satır olarak ekle (manuel miktar)
                        urunListesi.push({
                            urun_id: urunId,
                            seri_no: '',
                            miktar: miktar,
                            birim: birim,
                            birim_fiyat: fiyat,
                            kdv_id: kdvId,
                            kdv_tutari: kdvTutar,
                            ara_toplam: araToplam,
                            satir_toplam: satirToplam
                        });
                    }
                }
            });
            
            if (urunListesi.length === 0) {
                showToast('En az bir ürün eklemelisiniz!', 'error');
                return;
            }
            
            const formData = new FormData(this);
            formData.append('action', 'save');
            formData.append('urunler', JSON.stringify(urunListesi));
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        stokModal.hide();
                        loadStats();
                        loadList();
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function() {
                    showToast('Bir hata oluştu!', 'error');
                }
            });
        });
        
        // Detay görüntüle
        function viewDetail(faturaNo) {
            $.post('', { action: 'get', fatura_no: faturaNo }, response => {
                if (response.success && response.data.length > 0) {
                    let detayHtml = '<table class="table table-sm"><thead><tr><th>Ürün</th><th>Miktar</th><th>Birim Fiyat</th><th>KDV</th><th>Toplam</th></tr></thead><tbody>';
                    response.data.forEach(item => {
                        detayHtml += `<tr>
                            <td>${item.urun_hizmet_adi}</td>
                            <td>${item.stok_hareket_miktar} ${item.stok_hareket_birim}</td>
                            <td>${parseFloat(item.stok_hareket_birim_fiyat).toFixed(2)} ₺</td>
                            <td>${item.kdv_adi}</td>
                            <td>${parseFloat(item.stok_hareket_satir_toplam).toFixed(2)} ₺</td>
                        </tr>`;
                    });
                    detayHtml += '</tbody></table>';
                    
                    const modalHtml = `
                        <div class="modal fade" id="detayModal" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Fatura Detayı: ${faturaNo}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">${detayHtml}</div>
                                </div>
                            </div>
                        </div>
                    `;
                    $('body').append(modalHtml);
                    const detayModal = new bootstrap.Modal(document.getElementById('detayModal'));
                    detayModal.show();
                    $('#detayModal').on('hidden.bs.modal', function() {
                        $(this).remove();
                    });
                }
            });
        }
        
        // Sil
        function deleteItem(faturaNo) {
            confirmAction(
                `${faturaNo} numaralı işlemi silmek istediğinize emin misiniz?`,
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', fatura_no: faturaNo }, response => {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            loadStats();
                            loadList();
                        } else {
                            showError('Hata!', response.message);
                        }
                    }).fail(function() {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
    </script>
</body>
</html>
