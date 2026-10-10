<?php
/**
 * Admin Panel - Banka API Kimlik Bilgileri Yönetimi
 * 
 * Banka API bağlantı bilgilerini yönetir
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

// Sayfa bilgileri
$pageTitle = 'Banka API Kimlik Bilgileri';
$pageDescription = 'Banka API bağlantı bilgilerini yönetin';

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Banka listesi (aktif olanlar)
$bankalar = $db->fetchAll("SELECT banka_id, banka_adi FROM bankalar WHERE banka_durum = 1 ORDER BY banka_adi");

// Firma listesi
$firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiKimlik")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiKimlik WHERE apiKimlik_durum = 1")['sayi'] ?? 0,
                    'testEdilmis' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiKimlik WHERE apiKimlik_testEdildi = 1")['sayi'] ?? 0,
                    'pasif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_ApiKimlik WHERE apiKimlik_durum = 0")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $search = $_POST['search'] ?? '';
                $bankaId = $_POST['banka_id'] ?? '';
                $firmaId = $_POST['firma_id'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $testDurum = $_POST['test_durum'] ?? '';
                
                // SQL sorgusu
                $sql = "
                    SELECT 
                        a.*,
                        b.banka_adi,
                        f.firma_adi,
                        CONVERT(VARCHAR(19), a.apiKimlik_olusturmaTarihi, 120) as olusturma_tarihi,
                        CONVERT(VARCHAR(19), a.apiKimlik_guncellemeTarihi, 120) as guncelleme_tarihi,
                        CONVERT(VARCHAR(19), a.apiKimlik_sonTestTarihi, 120) as son_test_tarihi
                    FROM banka_ApiKimlik a
                    LEFT JOIN bankalar b ON a.apiKimlik_banka_id = b.banka_id
                    LEFT JOIN Firmalar f ON a.apiKimlik_firma_id = f.firma_id
                    WHERE 1=1
                ";
                $params = [];
                
                // Banka filtresi
                if ($bankaId) {
                    $sql .= " AND a.apiKimlik_banka_id = ?";
                    $params[] = $bankaId;
                }
                
                // Firma filtresi
                if ($firmaId) {
                    $sql .= " AND a.apiKimlik_firma_id = ?";
                    $params[] = $firmaId;
                }
                
                // Durum filtresi
                if ($durum !== '') {
                    $sql .= " AND a.apiKimlik_durum = ?";
                    $params[] = $durum;
                }
                
                // Test durum filtresi
                if ($testDurum !== '') {
                    $sql .= " AND a.apiKimlik_testEdildi = ?";
                    $params[] = $testDurum;
                }
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (a.apiKimlik_kurumKod LIKE ? OR a.apiKimlik_kullanici LIKE ? OR a.apiKimlik_vkn LIKE ? OR a.apiKimlik_endpoint LIKE ? OR b.banka_adi LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY a.apiKimlik_id DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("
                    SELECT a.*, b.banka_adi, f.firma_adi
                    FROM banka_ApiKimlik a
                    LEFT JOIN bankalar b ON a.apiKimlik_banka_id = b.banka_id
                    LEFT JOIN Firmalar f ON a.apiKimlik_firma_id = f.firma_id
                    WHERE a.apiKimlik_id = ?
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'save':
                // Kaydet/Güncelle
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                
                // Zorunlu alan kontrolü
                if (empty($_POST['banka_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Banka seçimi zorunludur!']);
                    break;
                }
                
                $data = [
                    'apiKimlik_banka_id' => intval($_POST['banka_id']),
                    'apiKimlik_firma_id' => !empty($_POST['firma_id']) ? intval($_POST['firma_id']) : null,
                    'apiKimlik_kurumKod' => !empty($_POST['kurum_kod']) ? trim($_POST['kurum_kod']) : null,
                    'apiKimlik_kullanici' => !empty($_POST['kullanici']) ? trim($_POST['kullanici']) : null,
                    'apiKimlik_sifre' => !empty($_POST['sifre']) ? trim($_POST['sifre']) : null,
                    'apiKimlik_vkn' => !empty($_POST['vkn']) ? trim($_POST['vkn']) : null,
                    'apiKimlik_tckn' => !empty($_POST['tckn']) ? trim($_POST['tckn']) : null,
                    'apiKimlik_endpoint' => !empty($_POST['endpoint']) ? trim($_POST['endpoint']) : null,
                    'apiKimlik_wsdl' => !empty($_POST['wsdl']) ? trim($_POST['wsdl']) : null,
                    'apiKimlik_soapVersion' => !empty($_POST['soap_version']) ? trim($_POST['soap_version']) : '1.1',
                    'apiKimlik_namespace' => !empty($_POST['namespace']) ? trim($_POST['namespace']) : null,
                    'apiKimlik_ipAdresleri' => !empty($_POST['ip_adresleri']) ? trim($_POST['ip_adresleri']) : null,
                    'apiKimlik_ekAyarlar' => !empty($_POST['ek_ayarlar']) ? trim($_POST['ek_ayarlar']) : null,
                    'apiKimlik_durum' => isset($_POST['durum']) ? intval($_POST['durum']) : 1,
                    'apiKimlik_aciklama' => !empty($_POST['aciklama']) ? trim($_POST['aciklama']) : null,
                    'apiKimlik_nakitYonetimEmail' => !empty($_POST['nakit_yonetim_email']) ? trim($_POST['nakit_yonetim_email']) : null
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['apiKimlik_guncellemeTarihi'] = date('Y-m-d H:i:s');
                    $data['apiKimlik_guncelleyenKullaniciId'] = $user['kullanici_id'];
                    $db->update('banka_ApiKimlik', $data, ['apiKimlik_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Kayıt güncellendi!']);
                } else {
                    // Yeni kayıt
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['apiKimlik_olusturanKullaniciId'] = $user['kullanici_id'];
                    $db->insert('banka_ApiKimlik', $data);
                    echo json_encode(['success' => true, 'message' => 'Kayıt eklendi!']);
                }
                break;
                
            case 'delete':
                // Sil
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->execute("DELETE FROM banka_ApiKimlik WHERE apiKimlik_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Kayıt silindi!']);
                break;
                
            case 'toggle_durum':
                // Durum değiştir
                $id = $_POST['id'] ?? 0;
                $durum = $_POST['durum'] ?? 0;
                $db->update('banka_ApiKimlik', [
                    'apiKimlik_durum' => $durum,
                    'apiKimlik_guncellemeTarihi' => date('Y-m-d H:i:s'),
                    'apiKimlik_guncelleyenKullaniciId' => $user['kullanici_id']
                ], ['apiKimlik_id' => $id]);
                echo json_encode(['success' => true, 'message' => $durum ? 'API aktifleştirildi!' : 'API pasifleştirildi!']);
                break;
                
            case 'test_connection':
                // API bağlantı testi
                $id = $_POST['id'] ?? 0;
                $apiData = $db->fetchOne("SELECT * FROM banka_ApiKimlik WHERE apiKimlik_id = ?", [$id]);
                
                if (!$apiData) {
                    echo json_encode(['success' => false, 'message' => 'API kaydı bulunamadı!']);
                    break;
                }
                
                $testResult = '';
                $testSuccess = false;
                
                try {
                    // WSDL varsa SOAP bağlantısı test et
                    if (!empty($apiData['apiKimlik_wsdl'])) {
                        $context = stream_context_create([
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true
                            ],
                            'http' => [
                                'timeout' => 10
                            ]
                        ]);
                        
                        $client = new SoapClient($apiData['apiKimlik_wsdl'], [
                            'trace' => true,
                            'exceptions' => true,
                            'cache_wsdl' => WSDL_CACHE_NONE,
                            'stream_context' => $context,
                            'connection_timeout' => 10
                        ]);
                        
                        // WSDL'den fonksiyonları al
                        $functions = $client->__getFunctions();
                        $testResult = 'WSDL bağlantısı başarılı! ' . count($functions) . ' method bulundu.';
                        $testSuccess = true;
                    } else {
                        $testResult = 'WSDL adresi tanımlanmamış!';
                    }
                } catch (SoapFault $e) {
                    $testResult = 'SOAP Hatası: ' . $e->getMessage();
                } catch (Exception $e) {
                    $testResult = 'Bağlantı Hatası: ' . $e->getMessage();
                }
                
                // Test sonucunu kaydet
                $db->update('banka_ApiKimlik', [
                    'apiKimlik_testEdildi' => $testSuccess ? 1 : 0,
                    'apiKimlik_sonTestTarihi' => date('Y-m-d H:i:s'),
                    'apiKimlik_sonTestSonucu' => substr($testResult, 0, 500),
                    'apiKimlik_guncellemeTarihi' => date('Y-m-d H:i:s')
                ], ['apiKimlik_id' => $id]);
                
                echo json_encode([
                    'success' => $testSuccess,
                    'message' => $testResult,
                    'test_date' => date('d.m.Y H:i:s')
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
        .btn-group-sm > .btn, .btn-sm { padding: 0.25rem 0.5rem; margin: 0 2px; }
        .badge { font-size: 0.75rem; padding: 0.35em 0.65em; }
        .info-box { transition: transform 0.2s; cursor: pointer; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .password-toggle { cursor: pointer; }
        .api-endpoint { font-family: monospace; font-size: 0.85rem; word-break: break-all; }
        .test-badge { font-size: 0.7rem; }
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
                            <h3 class="mb-0"><i class="bi bi-key"></i> <?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item">Banka İşlemleri</li>
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
                                    <i class="bi bi-database"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam API</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
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
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-shield-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Test Edilmiş</span>
                                    <span class="info-box-number" id="stat-test">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-pause-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
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
                                    <!-- Banka -->
                                    <div class="col-md-3">
                                        <label class="form-label">Banka</label>
                                        <select class="form-select" id="filter_banka_id" name="banka_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($bankalar as $banka): ?>
                                                <option value="<?= $banka['banka_id'] ?>"><?= htmlspecialchars($banka['banka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Firma -->
                                    <div class="col-md-3">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select" id="filter_firma_id" name="firma_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($firmalar as $firma): ?>
                                                <option value="<?= $firma['firma_id'] ?>"><?= htmlspecialchars($firma['firma_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Arama -->
                                    <div class="col-md-2">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" id="filter_search" name="search" placeholder="Kurum kodu, VKN...">
                                    </div>
                                    
                                    <!-- Durum -->
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" id="filter_durum" name="durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Test Durumu -->
                                    <div class="col-md-2">
                                        <label class="form-label">Test Durumu</label>
                                        <select class="form-select" id="filter_test_durum" name="test_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Test Edilmiş</option>
                                            <option value="0">Test Edilmemiş</option>
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
                    
                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> API Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-sm btn-primary" id="btnYeniEkle">
                                    <i class="bi bi-plus-circle"></i> Yeni API Ekle
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
                                            <th>Banka</th>
                                            <th>Firma</th>
                                            <th>Kurum Kodu</th>
                                            <th>VKN</th>
                                            <th>Endpoint</th>
                                            <th width="80">Durum</th>
                                            <th width="80">Test</th>
                                            <th width="150">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr>
                                            <td colspan="9" class="text-center">Yükleniyor...</td>
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
    
    <!-- Modal: Kayıt Ekle/Düzenle -->
    <div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="bi bi-plus-circle"></i> Yeni API Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="saveForm">
                    <div class="modal-body">
                        <input type="hidden" id="api_id" name="id">
                        
                        <!-- Temel Bilgiler -->
                        <div class="card card-outline card-primary mb-3">
                            <div class="card-header">
                                <h6 class="card-title mb-0"><i class="bi bi-bank"></i> Temel Bilgiler</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Banka <span class="text-danger">*</span></label>
                                        <select class="form-select" id="banka_id" name="banka_id" required>
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($bankalar as $banka): ?>
                                                <option value="<?= $banka['banka_id'] ?>"><?= htmlspecialchars($banka['banka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select" id="firma_id" name="firma_id">
                                            <option value="">Seçiniz (Opsiyonel)...</option>
                                            <?php foreach ($firmalar as $firma): ?>
                                                <option value="<?= $firma['firma_id'] ?>"><?= htmlspecialchars($firma['firma_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Kimlik Bilgileri -->
                        <div class="card card-outline card-success mb-3">
                            <div class="card-header">
                                <h6 class="card-title mb-0"><i class="bi bi-key"></i> Kimlik Bilgileri (Credentials)</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Kurum Kodu</label>
                                        <input type="text" class="form-control" id="kurum_kod" name="kurum_kod" placeholder="Örn: ORNEKAKADEMI">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Kullanıcı Adı</label>
                                        <input type="text" class="form-control" id="kullanici" name="kullanici" placeholder="Alternatif kullanıcı adı">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Şifre</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="sifre" name="sifre" placeholder="API şifresi">
                                            <button class="btn btn-outline-secondary password-toggle" type="button" data-target="sifre">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">VKN (Vergi Kimlik No)</label>
                                        <input type="text" class="form-control" id="vkn" name="vkn" placeholder="10 haneli VKN" maxlength="10">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">TCKN (Bireysel)</label>
                                        <input type="text" class="form-control" id="tckn" name="tckn" placeholder="11 haneli TCKN" maxlength="11">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Endpoint Bilgileri -->
                        <div class="card card-outline card-info mb-3">
                            <div class="card-header">
                                <h6 class="card-title mb-0"><i class="bi bi-globe"></i> Endpoint Bilgileri</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">SOAP Endpoint URL</label>
                                        <input type="url" class="form-control" id="endpoint" name="endpoint" placeholder="https://...">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">WSDL URL</label>
                                        <input type="url" class="form-control" id="wsdl" name="wsdl" placeholder="https://...?wsdl">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">SOAP Versiyon</label>
                                        <select class="form-select" id="soap_version" name="soap_version">
                                            <option value="1.1" selected>SOAP 1.1</option>
                                            <option value="1.2">SOAP 1.2</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Namespace</label>
                                        <input type="text" class="form-control" id="namespace" name="namespace" placeholder="http://tempuri.org/">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">IP Whitelist</label>
                                        <input type="text" class="form-control" id="ip_adresleri" name="ip_adresleri" placeholder="IP1;IP2;IP3">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Ek Ayarlar -->
                        <div class="card card-outline card-secondary mb-3">
                            <div class="card-header">
                                <h6 class="card-title mb-0"><i class="bi bi-gear"></i> Ek Ayarlar</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Nakit Yönetim E-posta</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                            <input type="email" class="form-control" id="nakit_yonetim_email" name="nakit_yonetim_email" placeholder="nakityonetim@banka.com.tr">
                                        </div>
                                        <small class="text-muted">API başvurusu için banka iletişim adresi</small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Ek Ayarlar (JSON)</label>
                                        <textarea class="form-control" id="ek_ayarlar" name="ek_ayarlar" rows="3" placeholder='{"param1": "value1"}'></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Açıklama / Not</label>
                                        <textarea class="form-control" id="aciklama" name="aciklama" rows="3" placeholder="Bu API hakkında notlar..."></textarea>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="durum" name="durum" value="1" checked>
                                            <label class="form-check-label" for="durum">Aktif</label>
                                        </div>
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
                            <i class="bi bi-check-circle"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    let table;
    let currentFilters = {};
    const permissions = <?= json_encode($pagePermissions) ?>;
    
    // Türkçe karakter normalize fonksiyonu
    function turkishToLower(str) {
        if (!str) return '';
        return str.toString()
            .replace(/İ/g, 'i')
            .replace(/I/g, 'ı')
            .replace(/Ş/g, 'ş')
            .replace(/Ğ/g, 'ğ')
            .replace(/Ü/g, 'ü')
            .replace(/Ö/g, 'ö')
            .replace(/Ç/g, 'ç')
            .toLowerCase();
    }
    
    // DataTables için Türkçe karakter destekli arama
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (settings.nTable.id !== 'dataTable') return true;
        
        const searchTerm = turkishToLower($('#dataTable_filter input').val());
        if (!searchTerm) return true;
        
        for (let i = 0; i < data.length; i++) {
            if (turkishToLower(data[i]).includes(searchTerm)) {
                return true;
            }
        }
        return false;
    });
    
    $(document).ready(function() {
        const modal = new bootstrap.Modal(document.getElementById('modalForm'));
        
        // Select2 başlat
        $('#banka_id, #firma_id, #filter_banka_id, #filter_firma_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            placeholder: 'Seçiniz...',
            language: {
                noResults: function() { return "Sonuç bulunamadı"; }
            }
        });
        
        // Modal içindeki Select2
        $('#modalForm').on('shown.bs.modal', function() {
            $('#banka_id, #firma_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $('#modalForm'),
                allowClear: true,
                placeholder: 'Seçiniz...'
            });
        });
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-test').text(response.data.testEdilmis);
                    $('#stat-pasif').text(response.data.pasif);
                }
            });
        }
        
        // DataTable başlat
        function initDataTable() {
            table = $('#dataTable').DataTable({
                processing: true,
                scrollX: true,
                autoWidth: false,
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
                    { data: 'apiKimlik_id' },
                    { 
                        data: 'banka_adi',
                        defaultContent: '-',
                        render: data => data ? `<strong>${data}</strong>` : '-'
                    },
                    { data: 'firma_adi', defaultContent: '<span class="text-muted">-</span>' },
                    { 
                        data: null,
                        render: data => {
                            const kod = data.apiKimlik_kurumKod || data.apiKimlik_kullanici || '-';
                            return `<code>${kod}</code>`;
                        }
                    },
                    { data: 'apiKimlik_vkn', defaultContent: '-' },
                    { 
                        data: 'apiKimlik_endpoint',
                        defaultContent: '<span class="text-muted">-</span>',
                        render: data => {
                            if (!data) return '<span class="text-muted">-</span>';
                            const short = data.length > 40 ? data.substring(0, 40) + '...' : data;
                            return `<span class="api-endpoint" title="${data}">${short}</span>`;
                        }
                    },
                    { 
                        data: 'apiKimlik_durum',
                        className: 'text-center',
                        render: data => data == 1 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-secondary">Pasif</span>'
                    },
                    { 
                        data: null,
                        className: 'text-center',
                        render: data => data.apiKimlik_testEdildi == 1 
                            ? `<span class="badge bg-info test-badge" title="${data.son_test_tarihi || ''}"><i class="bi bi-check"></i> OK</span>` 
                            : '<span class="badge bg-warning test-badge"><i class="bi bi-question"></i></span>'
                    },
                    { 
                        data: null,
                        orderable: false,
                        className: 'text-center',
                        render: data => {
                            let buttons = `<div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-info btn-test" data-id="${data.apiKimlik_id}" title="Bağlantı Test Et">
                                    <i class="bi bi-lightning"></i>
                                </button>`;
                            
                            if (permissions.can_edit) {
                                buttons += `<button type="button" class="btn btn-outline-primary btn-edit" data-id="${data.apiKimlik_id}" title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </button>`;
                            }
                            
                            if (permissions.can_delete) {
                                buttons += `<button type="button" class="btn btn-outline-danger btn-delete" data-id="${data.apiKimlik_id}" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }
                            
                            buttons += '</div>';
                            return buttons;
                        }
                    }
                ],
                order: [[0, 'desc']]
            });
        }
        
        // Sayfa yüklendiğinde
        loadStats();
        initDataTable();
        
        // Sidebar toggle - DataTable genişliğini yeniden hesapla
        $(document).on('click', '[data-lte-toggle="sidebar"]', function() {
            setTimeout(() => table.columns.adjust(), 300);
        });
        
        // Yeni Ekle butonu
        $('#btnYeniEkle').on('click', function() {
            $('#modalTitle').html('<i class="bi bi-plus-circle"></i> Yeni API Ekle');
            $('#saveForm')[0].reset();
            $('#api_id').val('');
            $('#durum').prop('checked', true);
            $('#banka_id').val('').trigger('change');
            $('#firma_id').val('').trigger('change');
            modal.show();
        });
        
        // Düzenle butonu
        $(document).on('click', '.btn-edit', function() {
            const id = $(this).data('id');
            $.post('', { action: 'get', id: id }, function(response) {
                if (response.success && response.data) {
                    const d = response.data;
                    $('#modalTitle').html('<i class="bi bi-pencil"></i> API Düzenle');
                    $('#api_id').val(d.apiKimlik_id);
                    $('#banka_id').val(d.apiKimlik_banka_id).trigger('change');
                    $('#firma_id').val(d.apiKimlik_firma_id).trigger('change');
                    $('#kurum_kod').val(d.apiKimlik_kurumKod);
                    $('#kullanici').val(d.apiKimlik_kullanici);
                    $('#sifre').val(d.apiKimlik_sifre);
                    $('#vkn').val(d.apiKimlik_vkn);
                    $('#tckn').val(d.apiKimlik_tckn);
                    $('#endpoint').val(d.apiKimlik_endpoint);
                    $('#wsdl').val(d.apiKimlik_wsdl);
                    $('#soap_version').val(d.apiKimlik_soapVersion || '1.1');
                    $('#namespace').val(d.apiKimlik_namespace);
                    $('#ip_adresleri').val(d.apiKimlik_ipAdresleri);
                    $('#ek_ayarlar').val(d.apiKimlik_ekAyarlar);
                    $('#aciklama').val(d.apiKimlik_aciklama);
                    $('#nakit_yonetim_email').val(d.apiKimlik_nakitYonetimEmail);
                    $('#durum').prop('checked', d.apiKimlik_durum == 1);
                    modal.show();
                } else {
                    showToast('Kayıt bulunamadı!', 'error');
                }
            });
        });
        
        // Kaydet
        $('#saveForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize() + '&action=save&durum=' + ($('#durum').is(':checked') ? 1 : 0);
            
            $.post('', formData, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    modal.hide();
                    table.ajax.reload();
                    loadStats();
                } else {
                    showToast(response.message, 'error');
                }
            });
        });
        
        // Sil butonu
        $(document).on('click', '.btn-delete', function() {
            const id = $(this).data('id');
            confirmAction('Bu API kaydını silmek istediğinize emin misiniz?', 'Bu işlem geri alınamaz!', function() {
                $.post('', { action: 'delete', id: id }, function(response) {
                    if (response.success) {
                        showSuccess('Silindi!', response.message);
                        table.ajax.reload();
                        loadStats();
                    } else {
                        showError('Hata!', response.message);
                    }
                });
            });
        });
        
        // Test butonu
        $(document).on('click', '.btn-test', function() {
            const id = $(this).data('id');
            const btn = $(this);
            
            btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i>');
            
            $.post('', { action: 'test_connection', id: id }, function(response) {
                btn.prop('disabled', false).html('<i class="bi bi-lightning"></i>');
                
                if (response.success) {
                    showSuccess('Bağlantı Başarılı!', response.message);
                } else {
                    showError('Bağlantı Başarısız!', response.message);
                }
                table.ajax.reload();
                loadStats();
            }).fail(function() {
                btn.prop('disabled', false).html('<i class="bi bi-lightning"></i>');
                showError('Hata!', 'Bağlantı test edilemedi.');
            });
        });
        
        // Şifre göster/gizle
        $(document).on('click', '.password-toggle', function() {
            const targetId = $(this).data('target');
            const input = $('#' + targetId);
            const icon = $(this).find('i');
            
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                icon.removeClass('bi-eye').addClass('bi-eye-slash');
            } else {
                input.attr('type', 'password');
                icon.removeClass('bi-eye-slash').addClass('bi-eye');
            }
        });
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                search: $('#filter_search').val(),
                banka_id: $('#filter_banka_id').val(),
                firma_id: $('#filter_firma_id').val(),
                durum: $('#filter_durum').val(),
                test_durum: $('#filter_test_durum').val()
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
            $('#filter_banka_id').val('').trigger('change.select2');
            $('#filter_firma_id').val('').trigger('change.select2');
            $('#filter_durum').val('');
            $('#filter_test_durum').val('');
            currentFilters = {};
            table.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });
    });
    </script>
</body>
</html>
