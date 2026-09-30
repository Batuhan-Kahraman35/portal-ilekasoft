<?php
/**
 * Admin Panel - Personel İzin Durumları Yönetimi
 * 
 * İzin türlerini tanımlamak için kullanılır
 * Puantaj kayıtları için ayrı sayfa kullanılacak
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Personel İzin Durumları';
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
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM tanim_izin_durumlari WHERE izin_durum_durum = 1")['sayi'] ?? 0,
                    'ucretli' => $db->fetchOne("SELECT COUNT(*) as sayi FROM tanim_izin_durumlari WHERE izin_durum_durum = 1 AND izin_durum_gun_carpani >= 0")['sayi'] ?? 0,
                    'ucretsiz' => $db->fetchOne("SELECT COUNT(*) as sayi FROM tanim_izin_durumlari WHERE izin_durum_durum = 1 AND izin_durum_gun_carpani < 0")['sayi'] ?? 0,
                    'dosya_gerekli' => $db->fetchOne("SELECT COUNT(*) as sayi FROM tanim_izin_durumlari WHERE izin_durum_durum = 1 AND izin_durum_dosya_gerekli = 1")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $search = $_POST['search'] ?? '';
                $ucretliDurum = $_POST['ucretli_durum'] ?? '';
                $dosyaGerekli = $_POST['dosya_gerekli'] ?? '';
                
                $sql = "SELECT * FROM tanim_izin_durumlari WHERE izin_durum_durum = 1";
                $params = [];
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (izin_durum_adi LIKE ? OR izin_durum_kod LIKE ? OR izin_durum_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                // Ücretli/Ücretsiz filtresi (gün çarpanına göre)
                if ($ucretliDurum !== '') {
                    if ($ucretliDurum == 1) {
                        $sql .= " AND izin_durum_gun_carpani >= 0"; // Ücretli
                    } else {
                        $sql .= " AND izin_durum_gun_carpani < 0"; // Ücretsiz
                    }
                }
                
                // Dosya gerekli filtresi
                if ($dosyaGerekli !== '') {
                    $sql .= " AND izin_durum_dosya_gerekli = ?";
                    $params[] = $dosyaGerekli;
                }
                
                $sql .= " ORDER BY izin_durum_sira_no ASC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("SELECT * FROM tanim_izin_durumlari WHERE izin_durum_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'save':
                // Kaydet/Güncelle
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                
                // Gerekli alanlar
                $adi = trim($_POST['izin_durum_adi'] ?? '');
                $kod = trim($_POST['izin_durum_kod'] ?? '');
                
                if (!$adi || !$kod) {
                    echo json_encode(['success' => false, 'message' => 'Ad ve kod zorunludur!']);
                    break;
                }
                
                // Kod benzersizliği kontrolü
                $existingCheck = $db->fetchOne(
                    "SELECT izin_durum_id FROM tanim_izin_durumlari WHERE izin_durum_kod = ? AND izin_durum_id != ?",
                    [$kod, $id]
                );
                
                if ($existingCheck) {
                    echo json_encode(['success' => false, 'message' => 'Bu kod zaten kullanılıyor!']);
                    break;
                }
                
                $gunCarpani = floatval($_POST['izin_durum_gun_carpani'] ?? 0);
                
                $data = [
                    'izin_durum_adi' => $adi,
                    'izin_durum_kod' => strtoupper($kod),
                    'izin_durum_renk' => $_POST['izin_durum_renk'] ?? '#6c757d',
                    'izin_durum_aciklama' => trim($_POST['izin_durum_aciklama'] ?? ''),
                    'izin_durum_dosya_gerekli' => isset($_POST['izin_durum_dosya_gerekli']) ? 1 : 0,
                    'izin_durum_ucretli' => ($gunCarpani >= 0) ? 1 : 0, // Otomatik: >= 0 ise ücretli
                    'izin_durum_gun_sayisi_etkisi' => ($gunCarpani < 0) ? 1 : 0, // Otomatik: < 0 ise izin hakkından düşer
                    'izin_durum_gun_carpani' => $gunCarpani,
                    'izin_durum_sira_no' => intval($_POST['izin_durum_sira_no'] ?? 0)
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['izin_durum_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    $data['izin_durum_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $db->update('tanim_izin_durumlari', $data, ['izin_durum_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'İzin durumu güncellendi!']);
                } else {
                    // Yeni kayıt
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['izin_durum_olusturan_kullanici_id'] = $user['kullanici_id'];
                    $db->insert('tanim_izin_durumlari', $data);
                    echo json_encode(['success' => true, 'message' => 'İzin durumu eklendi!']);
                }
                break;
                
            case 'delete':
                // Sil (Soft Delete)
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->update('tanim_izin_durumlari', ['izin_durum_durum' => 0], ['izin_durum_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'İzin durumu silindi!']);
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
        .btn-group-sm > .btn, .btn-sm {
            padding: 0.25rem 0.5rem;
            margin: 0 2px;
        }
        
        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        
        .info-box {
            transition: transform 0.2s;
        }
        
        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        /* Renk seçici önizleme */
        .color-preview {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 4px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }
        
        /* Checkbox'lar için özel stil */
        .form-check-input {
            cursor: pointer;
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
                                    <i class="bi bi-list-ul"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam İzin Türü</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-cash-coin"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ücretli</span>
                                    <span class="info-box-number" id="stat-ucretli">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-x-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ücretsiz</span>
                                    <span class="info-box-number" id="stat-ucretsiz">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-file-earmark-medical"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Dosya Gerekli</span>
                                    <span class="info-box-number" id="stat-dosya-gerekli">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtreler
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="collapse" id="filterCollapse">
                            <div class="card-body">
                                <form id="filterForm">
                                    <div class="row g-3">
                                        <!-- Arama -->
                                        <div class="col-md-6">
                                            <label class="form-label">Arama</label>
                                            <input type="text" class="form-control" id="filter_search" name="search" placeholder="İzin adı, kod veya açıklama...">
                                        </div>
                                        
                                        <!-- Ücretli/Ücretsiz -->
                                        <div class="col-md-3">
                                            <label class="form-label">Ücret Durumu</label>
                                            <select class="form-select" id="filter_ucretli" name="ucretli_durum">
                                                <option value="">Tümü</option>
                                                <option value="1">Ücretli</option>
                                                <option value="0">Ücretsiz</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Dosya Gerekli -->
                                        <div class="col-md-3">
                                            <label class="form-label">Dosya Zorunluluğu</label>
                                            <select class="form-select" id="filter_dosya" name="dosya_gerekli">
                                                <option value="">Tümü</option>
                                                <option value="1">Gerekli</option>
                                                <option value="0">Gerekli Değil</option>
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
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> İzin Durumları</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" id="btnYeniEkle">
                                    <i class="bi bi-plus-circle"></i> Yeni İzin Durumu Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th width="50">Sıra</th>
                                            <th width="80">Renk</th>
                                            <th>İzin Adı</th>
                                            <th>Kod</th>
                                            <th>Gün Çarpanı</th>
                                            <th>Dosya Gerekli</th>
                                            <th>Açıklama</th>
                                            <th width="120">İşlemler</th>
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
    
    <!-- Modal: İzin Durumu Ekle/Düzenle -->
    <div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni İzin Durumu Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="saveForm">
                    <div class="modal-body">
                        <input type="hidden" id="izin_durum_id" name="id">
                        
                        <div class="row g-3">
                            <!-- İzin Adı -->
                            <div class="col-md-6">
                                <label class="form-label">İzin Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="izin_durum_adi" name="izin_durum_adi" required placeholder="Örn: Yıllık İzin">
                            </div>
                            
                            <!-- Kod -->
                            <div class="col-md-6">
                                <label class="form-label">Kod <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="izin_durum_kod" name="izin_durum_kod" required placeholder="Örn: YILLIK_IZIN">
                                <small class="text-muted">Büyük harf ve alt çizgi kullanın</small>
                            </div>
                            
                            <!-- Renk -->
                            <div class="col-md-4">
                                <label class="form-label">Renk</label>
                                <input type="color" class="form-control" id="izin_durum_renk" name="izin_durum_renk" value="#6c757d">
                            </div>
                            
                            <!-- Sıra No -->
                            <div class="col-md-4">
                                <label class="form-label">Sıra No</label>
                                <input type="number" class="form-control" id="izin_durum_sira_no" name="izin_durum_sira_no" value="0" min="0">
                            </div>
                            
                            <!-- Gün Çarpanı -->
                            <div class="col-md-4">
                                <label class="form-label">Gün Çarpanı <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="izin_durum_gun_carpani" name="izin_durum_gun_carpani" value="0" step="0.5" min="-1" max="1" required>
                                <small class="text-muted">Örn: +1.0 (Geldi), -0.5 (Yarım gün), -1.0 (Gelmedi)</small>
                            </div>
                            
                            <!-- Dosya Gerekli -->
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="izin_durum_dosya_gerekli" name="izin_durum_dosya_gerekli">
                                    <label class="form-check-label" for="izin_durum_dosya_gerekli">
                                        <i class="bi bi-file-earmark-medical text-primary"></i> Dosya Zorunlu
                                    </label>
                                </div>
                                <small class="text-muted">Rapor/belge yükleme gerekli</small>
                            </div>
                            
                            <div class="col-md-6">
                                <!-- Boş alan -->
                            </div>
                            
                            <!-- Açıklama -->
                            <div class="col-md-12">
                                <label class="form-label">Açıklama</label>
                                <textarea class="form-control" id="izin_durum_aciklama" name="izin_durum_aciklama" rows="3" placeholder="İzin türü hakkında detaylı bilgi..."></textarea>
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
                    $('#stat-ucretli').text(response.data.ucretli);
                    $('#stat-ucretsiz').text(response.data.ucretsiz);
                    $('#stat-dosya-gerekli').text(response.data.dosya_gerekli);
                }
            });
        }
        
        // Filtre işlemleri
        let currentFilters = {};
        
        // Liste yükle
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
                tbody.html('<tr><td colspan="8" class="text-center">Kayıt bulunamadı</td></tr>');
                return;
            }
            
            data.forEach(item => {
                const gunCarpani = parseFloat(item.izin_durum_gun_carpani || 0);
                let carpaniBadge = '';
                if (gunCarpani > 0) {
                    carpaniBadge = `<span class="badge bg-success">+${gunCarpani.toFixed(1)}</span>`;
                } else if (gunCarpani < 0) {
                    carpaniBadge = `<span class="badge bg-danger">${gunCarpani.toFixed(1)}</span>`;
                } else {
                    carpaniBadge = `<span class="badge bg-secondary">0.0</span>`;
                }
                
                const row = `
                    <tr>
                        <td class="text-center">${item.izin_durum_sira_no}</td>
                        <td class="text-center">
                            <span class="color-preview" style="background-color: ${item.izin_durum_renk}" title="${item.izin_durum_renk}"></span>
                        </td>
                        <td><strong>${item.izin_durum_adi}</strong></td>
                        <td><code>${item.izin_durum_kod}</code></td>
                        <td class="text-center">${carpaniBadge}</td>
                        <td class="text-center">
                            ${item.izin_durum_dosya_gerekli ? '<i class="bi bi-check-circle text-primary" title="Dosya gerekli"></i>' : '<i class="bi bi-dash-circle text-muted" title="Gerekli değil"></i>'}
                        </td>
                        <td><small>${item.izin_durum_aciklama || '-'}</small></td>
                        <td>
                            ${permissions.can_edit ? `<button class="btn btn-sm btn-warning" onclick="editRecord(${item.izin_durum_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>` : ''}
                            ${permissions.can_delete ? `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${item.izin_durum_id})" title="Sil"><i class="bi bi-trash"></i></button>` : ''}
                        </td>
                    </tr>
                `;
                tbody.append(row);
            });
        }
        
        // Yeni kayıt ekle
        $('#btnYeniEkle').on('click', function() {
            $('#modalTitle').text('Yeni İzin Durumu Ekle');
            $('#saveForm')[0].reset();
            $('#izin_durum_id').val('');
            $('#izin_durum_renk').val('#6c757d');
            modal.show();
        });
        
        // Kayıt düzenle
        function editRecord(id) {
            $.post('', { action: 'get', id: id }, response => {
                if (response.success) {
                    const data = response.data;
                    $('#modalTitle').text('İzin Durumu Düzenle');
                    $('#izin_durum_id').val(data.izin_durum_id);
                    $('#izin_durum_adi').val(data.izin_durum_adi);
                    $('#izin_durum_kod').val(data.izin_durum_kod);
                    $('#izin_durum_renk').val(data.izin_durum_renk || '#6c757d');
                    $('#izin_durum_aciklama').val(data.izin_durum_aciklama);
                    $('#izin_durum_sira_no').val(data.izin_durum_sira_no);
                    $('#izin_durum_gun_carpani').val(data.izin_durum_gun_carpani || 0);
                    $('#izin_durum_dosya_gerekli').prop('checked', data.izin_durum_dosya_gerekli == 1);
                    
                    modal.show();
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        // Form kaydet
        $('#saveForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = $(this).serialize() + '&action=save';
            
            $.post('', formData, response => {
                if (response.success) {
                    showToast(response.message, 'success');
                    modal.hide();
                    loadStats();
                    loadList();
                } else {
                    showToast(response.message, 'error');
                }
            });
        });
        
        // Kayıt sil
        function deleteRecord(id) {
            confirmAction(
                'Bu izin durumunu silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, response => {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            loadStats();
                            loadList();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            );
        }
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            currentFilters = {
                search: $('#filter_search').val(),
                ucretli_durum: $('#filter_ucretli').val(),
                dosya_gerekli: $('#filter_dosya').val()
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
            $('#filter_ucretli').val('').trigger('change.select2');
            $('#filter_dosya').val('').trigger('change.select2');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Sayfa yüklendiğinde
        $(document).ready(function() {
            loadStats();
            loadList();
        });
    </script>
</body>
</html>
