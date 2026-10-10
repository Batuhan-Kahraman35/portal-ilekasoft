<?php
/**
 * Admin Panel - Menü Sayfa Yetkileri Yönetimi
 * 
 * Bu sayfa menü ve sayfa bazlı kullanıcı grubu yetkilerini yönetir
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Menü Sayfa Yetkileri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'Menü ve sayfa bazlı kullanıcı grubu yetkilerini yönetin';
$menuAdi = $pageInfo['menu_adi'] ?? 'Sistem Ayarları';

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'list':
                // Yetki listesini getir
                $yetkiler = $db->fetchAll("
                    SELECT 
                        menu_sayfa_yetki_id,
                        menu_adi AS menuler_menu_adi,
                        sayfa_adi AS sayfalar_sayfa_adi,
                        departman_adi,
                        gor,
                        kendi_kullanicini_gor,
                        firma_gor,
                        sube_gor,
                        ekle,
                        duzenle,
                        sil,
                        durum,
                        created_at,
                        updated_at
                    FROM vw_menu_sayfa_yetkiler_detay
                    ORDER BY menu_adi, sayfa_adi, departman_adi
                ");
                echo json_encode(['success' => true, 'data' => $yetkiler]);
                break;
                
            case 'get':
                $id = $_POST['id'] ?? 0;
                $yetki = $db->fetchOne("
                    SELECT * FROM menu_sayfa_yetkiler WHERE menu_sayfa_yetki_id = ?
                ", [$id]);
                
                if ($yetki) {
                    echo json_encode(['success' => true, 'data' => $yetki]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Yetki kaydı bulunamadı']);
                }
                break;
                
            case 'save':
                // Yetki kontrolü
                $id = $_POST['menu_sayfa_yetki_id'] ?? 0;
                if ($id > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                // Düzenleme modu kontrolü
                if ($id > 0) {
                    // Düzenleme: Tek kayıt
                    $menuId = !empty($_POST['menu_id']) ? $_POST['menu_id'] : null;
                    $sayfaId = !empty($_POST['sayfa_id']) ? $_POST['sayfa_id'] : null;
                    $departmanId = !empty($_POST['departman_id']) ? $_POST['departman_id'] : null;
                    
                    if (!$menuId && !$sayfaId) {
                        echo json_encode(['success' => false, 'message' => 'Menü veya Sayfa seçmelisiniz!']);
                        break;
                    }
                    
                    $data = [
                        'sayfa_id' => $sayfaId,
                        'menu_id' => $menuId,
                        'departman_id' => $departmanId,
                        'gor' => isset($_POST['gor']) ? 1 : 0,
                        'kendi_kullanicini_gor' => isset($_POST['kendi_kullanicini_gor']) ? 1 : 0,
                        'firma_gor' => isset($_POST['firma_gor']) ? 1 : 0,
                        'sube_gor' => isset($_POST['sube_gor']) ? 1 : 0,
                        'ekle' => isset($_POST['ekle']) ? 1 : 0,
                        'duzenle' => isset($_POST['duzenle']) ? 1 : 0,
                        'sil' => isset($_POST['sil']) ? 1 : 0,
                        'durum' => isset($_POST['durum']) ? 1 : 0,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $db->update('menu_sayfa_yetkiler', $data, ['menu_sayfa_yetki_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Yetki başarıyla güncellendi']);
                    break;
                }
                
                // Yeni ekleme: Multiselect
                $menuIds = isset($_POST['menu_id']) && is_array($_POST['menu_id']) ? array_filter($_POST['menu_id']) : [];
                $sayfaIds = isset($_POST['sayfa_id']) && is_array($_POST['sayfa_id']) ? array_filter($_POST['sayfa_id']) : [];
                $departmanId = !empty($_POST['departman_id']) ? $_POST['departman_id'] : null;
                
                // Validasyon: En az bir menü veya sayfa seçilmeli
                if (empty($menuIds) && empty($sayfaIds)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir Menü veya Sayfa seçmelisiniz!']);
                    break;
                }
                
                if (!$departmanId) {
                    echo json_encode(['success' => false, 'message' => 'Departman seçmelisiniz!']);
                    break;
                }
                
                // Yetki bilgileri
                $permissions = [
                    'departman_id' => $departmanId,
                    'gor' => isset($_POST['gor']) ? 1 : 0,
                    'kendi_kullanicini_gor' => isset($_POST['kendi_kullanicini_gor']) ? 1 : 0,
                    'firma_gor' => isset($_POST['firma_gor']) ? 1 : 0,
                    'sube_gor' => isset($_POST['sube_gor']) ? 1 : 0,
                    'ekle' => isset($_POST['ekle']) ? 1 : 0,
                    'duzenle' => isset($_POST['duzenle']) ? 1 : 0,
                    'sil' => isset($_POST['sil']) ? 1 : 0,
                    'durum' => isset($_POST['durum']) ? 1 : 0
                ];
                
                $successCount = 0;
                $skipCount = 0;
                $errorMessages = [];
                
                try {
                    // Menüler için kaydet
                    foreach ($menuIds as $menuId) {
                        // Aynı kayıt var mı kontrol et
                        $existing = $db->fetchOne("
                            SELECT menu_sayfa_yetki_id 
                            FROM menu_sayfa_yetkiler 
                            WHERE departman_id = ? AND menu_id = ? AND sayfa_id IS NULL
                        ", [$departmanId, $menuId]);
                        
                        if ($existing) {
                            $skipCount++;
                            continue;
                        }
                        
                        $data = array_merge($permissions, [
                            'menu_id' => $menuId,
                            'sayfa_id' => null,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $db->insert('menu_sayfa_yetkiler', $data);
                        $successCount++;
                    }
                    
                    // Sayfalar için kaydet
                    foreach ($sayfaIds as $sayfaId) {
                        // Aynı kayıt var mı kontrol et
                        $existing = $db->fetchOne("
                            SELECT menu_sayfa_yetki_id 
                            FROM menu_sayfa_yetkiler 
                            WHERE departman_id = ? AND sayfa_id = ? AND menu_id IS NULL
                        ", [$departmanId, $sayfaId]);
                        
                        if ($existing) {
                            $skipCount++;
                            continue;
                        }
                        
                        $data = array_merge($permissions, [
                            'menu_id' => null,
                            'sayfa_id' => $sayfaId,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $db->insert('menu_sayfa_yetkiler', $data);
                        $successCount++;
                    }
                    
                    $message = "$successCount yetki başarıyla eklendi";
                    if ($skipCount > 0) {
                        $message .= ", $skipCount yetki zaten mevcut (atlandı)";
                    }
                    
                    echo json_encode(['success' => true, 'message' => $message]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->delete('menu_sayfa_yetkiler', ['menu_sayfa_yetki_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Yetki başarıyla silindi']);
                break;
                
            case 'toggle_status':
                // Yetki kontrolü
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $currentStatus = $db->fetchOne("SELECT durum FROM menu_sayfa_yetkiler WHERE menu_sayfa_yetki_id = ?", [$id]);
                $newStatus = $currentStatus['durum'] == 1 ? 0 : 1;
                $db->update('menu_sayfa_yetkiler', 
                    ['durum' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 
                    ['menu_sayfa_yetki_id' => $id]
                );
                echo json_encode(['success' => true, 'message' => 'Durum güncellendi']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Sayfalar listesi
$sayfalar = $db->fetchAll("SELECT sayfalar_id, sayfalar_sayfa_adi FROM tanim_sayfalar WHERE sayfalar_durum = 1 ORDER BY sayfalar_sayfa_adi");

// Menüler listesi
$menuler = $db->fetchAll("SELECT menuler_id, menuler_menu_adi FROM tanim_menuler WHERE menuler_durum = 1 ORDER BY menuler_menu_adi");

// Departmanlar listesi
$departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");

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
    
    <style>
        .table-actions {
            white-space: nowrap;
        }
        .permission-badge {
            display: inline-block;
            margin: 2px;
            font-size: 0.75rem;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 20px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #28a745;
        }
        input:checked + .slider:before {
            transform: translateX(20px);
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
                    
                    <!-- İstatistik Kutuları -->
                    <div class="row mb-4">
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-info">
                                <div class="inner">
                                    <h3 id="totalYetkiler">0</h3>
                                    <p>Toplam Yetki</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-shield-lock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3 id="activeYetkiler">0</h3>
                                    <p>Aktif Yetki</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-warning">
                                <div class="inner">
                                    <h3 id="totalDepartmanlar">0</h3>
                                    <p>Departman</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-people"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-danger">
                                <div class="inner">
                                    <h3 id="totalSayfalar">0</h3>
                                    <p>Sayfa</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterMenu">Menü</label>
                                        <select class="form-select" id="filterMenu">
                                            <option value="">Tümü</option>
                                            <?php foreach ($menuler as $menu): ?>
                                                <option value="<?= htmlspecialchars($menu['menuler_menu_adi']) ?>">
                                                    <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterSayfa">Sayfa</label>
                                        <select class="form-select" id="filterSayfa">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sayfalar as $sayfa): ?>
                                                <option value="<?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>">
                                                    <?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterDepartman">Departman</label>
                                        <select class="form-select" id="filterDepartman">
                                            <option value="">Tümü</option>
                                            <?php foreach ($departmanlar as $departman): ?>
                                                <option value="<?= htmlspecialchars($departman['departman_adi']) ?>">
                                                    <?= htmlspecialchars($departman['departman_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterDurum">Durum</label>
                                        <select class="form-select" id="filterDurum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <button type="button" class="btn btn-primary" onclick="applyFilters()">
                                        <i class="bi bi-search"></i> Filtrele
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                                        <i class="bi bi-x-circle"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Yetki Tablosu -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-shield-lock"></i> Menü Sayfa Yetkileri
                            </h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Yetki Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="yetkiTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px">#</th>
                                            <th>Menü</th>
                                            <th>Sayfa</th>
                                            <th>Departman</th>
                                            <th style="width: 300px">Yetkiler</th>
                                            <th style="width: 80px">Durum</th>
                                            <th style="width: 120px">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="7" class="text-center">
                                                <div class="spinner-border" role="status">
                                                    <span class="visually-hidden">Yükleniyor...</span>
                                                </div>
                                            </td>
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
    
    <!-- Yetki Ekleme/Düzenleme Modal -->
    <div class="modal fade" id="yetkiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Yetki Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="yetkiForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="menu_sayfa_yetki_id" id="menu_sayfa_yetki_id">
                    
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Not:</strong> Menü veya Sayfa'dan <strong>birini veya birden fazlasını</strong> seçebilirsiniz. Toplu yetki ataması yapılacaktır.
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="menu_id" class="form-label">Menü (Çoklu Seçim)</label>
                                <select class="form-select" id="menu_id" name="menu_id[]" multiple size="8">
                                    <?php foreach ($menuler as $menu): ?>
                                        <option value="<?= $menu['menuler_id'] ?>">
                                            <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Ctrl+Click ile birden fazla menü seçin</small>
                            </div>
                            <div class="col-md-6">
                                <label for="sayfa_id" class="form-label">Sayfa (Çoklu Seçim)</label>
                                <select class="form-select" id="sayfa_id" name="sayfa_id[]" multiple size="8">
                                    <?php foreach ($sayfalar as $sayfa): ?>
                                        <option value="<?= $sayfa['sayfalar_id'] ?>">
                                            <?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Ctrl+Click ile birden fazla sayfa seçin</small>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="departman_id" class="form-label">Departman</label>
                                <select class="form-select" id="departman_id" name="departman_id" required>
                                    <option value="">Departman Seçiniz</option>
                                    <?php foreach ($departmanlar as $departman): ?>
                                        <option value="<?= $departman['departman_id'] ?>">
                                            <?= htmlspecialchars($departman['departman_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Yetkiler</label>
                                <div class="card">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="gor" name="gor" value="1">
                                                    <label class="form-check-label" for="gor">
                                                        <i class="bi bi-eye text-info"></i> Görüntüleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="kendi_kullanicini_gor" name="kendi_kullanicini_gor" value="1">
                                                    <label class="form-check-label" for="kendi_kullanicini_gor">
                                                        <i class="bi bi-person text-secondary"></i> Kendi Kullanıcısını Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="firma_gor" name="firma_gor" value="1">
                                                    <label class="form-check-label" for="firma_gor">
                                                        <i class="bi bi-building text-primary"></i> Firma Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="sube_gor" name="sube_gor" value="1">
                                                    <label class="form-check-label" for="sube_gor">
                                                        <i class="bi bi-shop text-info"></i> Şube Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="ekle" name="ekle" value="1">
                                                    <label class="form-check-label" for="ekle">
                                                        <i class="bi bi-plus-circle text-success"></i> Ekleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="duzenle" name="duzenle" value="1">
                                                    <label class="form-check-label" for="duzenle">
                                                        <i class="bi bi-pencil text-warning"></i> Düzenleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="sil" name="sil" value="1">
                                                    <label class="form-check-label" for="sil">
                                                        <i class="bi bi-trash text-danger"></i> Silme
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="durum" name="durum" value="1" checked>
                                    <label class="form-check-label" for="durum">Aktif</label>
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
    
    <script>
        // Sayfa yetkileri
        const pagePermissions = {
            can_add: <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
            can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        let yetkiModal;
        let menuSelect;
        let sayfaSelect;
        let allYetkiler = [];
        let filteredYetkiler = [];
        
        document.addEventListener('DOMContentLoaded', function() {
            yetkiModal = new bootstrap.Modal(document.getElementById('yetkiModal'));
            menuSelect = document.getElementById('menu_id');
            sayfaSelect = document.getElementById('sayfa_id');
            
            loadYetkiler();
            
            // Form submit
            document.getElementById('yetkiForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validasyon: En az bir Menü veya Sayfa seçilmeli
                const selectedMenus = Array.from(menuSelect.selectedOptions).map(opt => opt.value);
                const selectedPages = Array.from(sayfaSelect.selectedOptions).map(opt => opt.value);
                
                if (selectedMenus.length === 0 && selectedPages.length === 0) {
                    showAlert('Lütfen en az bir Menü veya Sayfa seçiniz!', 'warning');
                    return;
                }
                
                saveYetki();
            });
        });
        
        // Yetkileri yükle
        function loadYetkiler() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=list'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allYetkiler = data.data;
                    filteredYetkiler = data.data;
                    updateInfoBoxes();
                    renderTable(filteredYetkiler);
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Veriler yüklenirken hata oluştu', 'danger');
            });
        }
        
        // İnfo box'ları güncelle
        function updateInfoBoxes() {
            const activeYetkiler = allYetkiler.filter(y => y.durum == 1);
            const uniqueDepartmanlar = [...new Set(allYetkiler.map(y => y.departman_adi))];
            const uniqueSayfalar = [...new Set(allYetkiler.filter(y => y.sayfalar_sayfa_adi).map(y => y.sayfalar_sayfa_adi))];
            
            document.getElementById('totalYetkiler').textContent = allYetkiler.length;
            document.getElementById('activeYetkiler').textContent = activeYetkiler.length;
            document.getElementById('totalDepartmanlar').textContent = uniqueDepartmanlar.length;
            document.getElementById('totalSayfalar').textContent = uniqueSayfalar.length;
        }
        
        // Filtreleri uygula
        function applyFilters() {
            const filterMenu = document.getElementById('filterMenu').value.toLowerCase();
            const filterSayfa = document.getElementById('filterSayfa').value.toLowerCase();
            const filterDepartman = document.getElementById('filterDepartman').value.toLowerCase();
            const filterDurum = document.getElementById('filterDurum').value;
            
            filteredYetkiler = allYetkiler.filter(yetki => {
                const menuMatch = !filterMenu || (yetki.menuler_menu_adi && yetki.menuler_menu_adi.toLowerCase().includes(filterMenu));
                const sayfaMatch = !filterSayfa || (yetki.sayfalar_sayfa_adi && yetki.sayfalar_sayfa_adi.toLowerCase().includes(filterSayfa));
                const departmanMatch = !filterDepartman || (yetki.departman_adi && yetki.departman_adi.toLowerCase().includes(filterDepartman));
                const durumMatch = filterDurum === '' || yetki.durum == filterDurum;
                
                return menuMatch && sayfaMatch && departmanMatch && durumMatch;
            });
            
            renderTable(filteredYetkiler);
            
            showAlert(`${filteredYetkiler.length} kayıt bulundu`, 'info');
        }
        
        // Filtreleri temizle
        function clearFilters() {
            document.getElementById('filterMenu').value = '';
            document.getElementById('filterSayfa').value = '';
            document.getElementById('filterDepartman').value = '';
            document.getElementById('filterDurum').value = '';
            
            filteredYetkiler = allYetkiler;
            renderTable(filteredYetkiler);
            
            showAlert('Filtreler temizlendi', 'info');
        }
        
        // Tabloyu render et
        function renderTable(yetkiler) {
            const tbody = document.querySelector('#yetkiTable tbody');
            
            if (yetkiler.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">Henüz yetki kaydı bulunmuyor</td></tr>';
                return;
            }
            
            let html = '';
            yetkiler.forEach((yetki, index) => {
                const permissions = [];
                if (yetki.gor == 1) permissions.push('<span class="badge bg-info permission-badge"><i class="bi bi-eye"></i> Gör</span>');
                if (yetki.kendi_kullanicini_gor == 1) permissions.push('<span class="badge bg-secondary permission-badge"><i class="bi bi-person"></i> Kendi</span>');
                if (yetki.firma_gor == 1) permissions.push('<span class="badge bg-primary permission-badge"><i class="bi bi-building"></i> Firma</span>');
                if (yetki.sube_gor == 1) permissions.push('<span class="badge bg-info permission-badge"><i class="bi bi-shop"></i> Şube</span>');
                if (yetki.ekle == 1) permissions.push('<span class="badge bg-success permission-badge"><i class="bi bi-plus"></i> Ekle</span>');
                if (yetki.duzenle == 1) permissions.push('<span class="badge bg-warning permission-badge"><i class="bi bi-pencil"></i> Düzenle</span>');
                if (yetki.sil == 1) permissions.push('<span class="badge bg-danger permission-badge"><i class="bi bi-trash"></i> Sil</span>');
                
                const statusChecked = yetki.durum == 1 ? 'checked' : '';
                const statusClass = yetki.durum == 1 ? 'bg-success' : 'bg-secondary';
                
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${yetki.menuler_menu_adi || '-'}</td>
                        <td>${yetki.sayfalar_sayfa_adi || '-'}</td>
                        <td><span class="badge ${statusClass}">${yetki.departman_adi || '-'}</span></td>
                        <td>${permissions.join(' ')}</td>
                        <td class="text-center">
                            ${pagePermissions.can_edit ? `
                            <label class="switch">
                                <input type="checkbox" ${statusChecked} onchange="toggleStatus(${yetki.menu_sayfa_yetki_id})">
                                <span class="slider"></span>
                            </label>
                            ` : `<span class="badge ${statusClass}">${yetki.durum == 1 ? 'Aktif' : 'Pasif'}</span>`}
                        </td>
                        <td class="table-actions">
                            ${pagePermissions.can_edit ? `
                            <button class="btn btn-sm btn-warning" onclick="editYetki(${yetki.menu_sayfa_yetki_id})" title="Düzenle">
                                <i class="bi bi-pencil"></i>
                            </button>
                            ` : ''}
                            ${pagePermissions.can_delete ? `
                            <button class="btn btn-sm btn-danger" onclick="deleteYetki(${yetki.menu_sayfa_yetki_id})" title="Sil">
                                <i class="bi bi-trash"></i>
                            </button>
                            ` : ''}
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }
        
        // Modal aç
        function openModal(id = null) {
            document.getElementById('yetkiForm').reset();
            document.getElementById('menu_sayfa_yetki_id').value = '';
            document.getElementById('modalTitle').textContent = id ? 'Yetki Düzenle' : 'Toplu Yetki Ekle';
            document.getElementById('durum').checked = true;
            
            // Multiselect seçimlerini temizle
            menuSelect.value = '';
            sayfaSelect.value = '';
            $('#menu_id').val(null).trigger('change');
            $('#sayfa_id').val(null).trigger('change');
            
            // Multiselect'i göster/gizle
            if (id) {
                // Düzenlemede multiselect gizle, single select göster
                menuSelect.removeAttribute('multiple');
                sayfaSelect.removeAttribute('multiple');
                menuSelect.removeAttribute('size');
                sayfaSelect.removeAttribute('size');
                loadYetkiData(id);
            } else {
                // Yeni eklemede multiselect göster
                menuSelect.setAttribute('multiple', 'multiple');
                sayfaSelect.setAttribute('multiple', 'multiple');
                menuSelect.setAttribute('size', '8');
                sayfaSelect.setAttribute('size', '8');
            }
            
            yetkiModal.show();
        }
        
        // Yetki verilerini yükle (Düzenleme için)
        function loadYetkiData(id) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const yetki = data.data;
                    document.getElementById('menu_sayfa_yetki_id').value = yetki.menu_sayfa_yetki_id;
                    menuSelect.value = yetki.menu_id || '';
                    sayfaSelect.value = yetki.sayfa_id || '';
                    document.getElementById('departman_id').value = yetki.departman_id || '';
                    document.getElementById('gor').checked = yetki.gor == 1;
                    document.getElementById('kendi_kullanicini_gor').checked = yetki.kendi_kullanicini_gor == 1;
                    document.getElementById('firma_gor').checked = yetki.firma_gor == 1;
                    document.getElementById('sube_gor').checked = yetki.sube_gor == 1;
                    document.getElementById('ekle').checked = yetki.ekle == 1;
                    document.getElementById('duzenle').checked = yetki.duzenle == 1;
                    document.getElementById('sil').checked = yetki.sil == 1;
                    document.getElementById('durum').checked = yetki.durum == 1;
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Yetki kaydet
        function saveYetki() {
            const formData = new FormData(document.getElementById('yetkiForm'));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    yetkiModal.hide();
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Kayıt sırasında hata oluştu', 'danger');
            });
        }
        
        // Düzenle
        function editYetki(id) {
            openModal(id);
        }
        
        // Yetki sil
        function deleteYetki(id) {
            if (!confirm('Bu yetkiyi silmek istediğinizden emin misiniz?')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Durum değiştir
        function toggleStatus(id) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=toggle_status&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Alert göster
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
            alertDiv.style.zIndex = '9999';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
    </script>
</body>
</html>
