<?php
/**
 * Admin Panel - Kasa Yönetimi
 * Nakit kasa tanımlama ve yönetimi
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Kasa Yönetimi';
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
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Kasa")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Kasa WHERE kasa_durum = 1")['sayi'] ?? 0,
                    'pasif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM Kasa WHERE kasa_durum = 0")['sayi'] ?? 0,
                    'toplam_bakiye' => $db->fetchOne("SELECT ISNULL(SUM(kasa_bakiye), 0) as toplam FROM Kasa WHERE kasa_durum = 1")['toplam'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametreleri
                $search = $_POST['search'] ?? '';
                $durum = $_POST['durum'] ?? '';
                
                // WHERE koşulları
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($search) {
                    $whereConditions[] = "(kasa_adi LIKE ? OR kasa_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                if ($durum !== '') {
                    $whereConditions[] = "kasa_durum = ?";
                    $params[] = $durum;
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Veri çek
                $sql = "
                    SELECT 
                        k.kasa_id,
                        k.kasa_adi,
                        k.kasa_renk,
                        k.kasa_sira_no,
                        k.kasa_bakiye,
                        k.kasa_aciklama,
                        k.kasa_durum,
                        CONVERT(VARCHAR(19), k.kasa_olusturma_tarihi, 120) as kasa_olusturma_tarihi,
                        CONVERT(VARCHAR(19), k.kasa_guncelleme_tarihi, 120) as kasa_guncelleme_tarihi,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as olusturan_adi,
                        kg.kullanici_ad + ' ' + kg.kullanici_soyad as guncelleyen_adi
                    FROM Kasa k
                    LEFT JOIN kullanicilar ko ON k.kasa_olusturan_kullanici_id = ko.kullanici_id
                    LEFT JOIN kullanicilar kg ON k.kasa_guncelleyen_kullanici_id = kg.kullanici_id
                    WHERE $whereClause
                    ORDER BY ISNULL(k.kasa_sira_no, 999), k.kasa_adi
                ";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("SELECT * FROM Kasa WHERE kasa_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'save':
                // Kaydet/Güncelle
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $kasaAdi = trim($_POST['kasa_adi'] ?? '');
                $kasaRenk = trim($_POST['kasa_renk'] ?? '#0d6efd');
                $kasaSiraNo = !empty($_POST['kasa_sira_no']) ? intval($_POST['kasa_sira_no']) : null;
                $kasaBakiye = floatval($_POST['kasa_bakiye'] ?? 0);
                $kasaAciklama = trim($_POST['kasa_aciklama'] ?? '');
                $kasaDurum = isset($_POST['kasa_durum']) ? 1 : 0;
                
                // Validasyon
                if (empty($kasaAdi)) {
                    echo json_encode(['success' => false, 'message' => 'Kasa adı boş bırakılamaz!']);
                    break;
                }
                
                // Aynı isimde kasa var mı kontrol et
                $mevcutKasa = $db->fetchOne("
                    SELECT kasa_id FROM Kasa 
                    WHERE kasa_adi = ? " . ($id > 0 ? "AND kasa_id != ?" : ""),
                    $id > 0 ? [$kasaAdi, $id] : [$kasaAdi]
                );
                
                if ($mevcutKasa) {
                    echo json_encode(['success' => false, 'message' => 'Bu isimde bir kasa zaten mevcut!']);
                    break;
                }
                
                $data = [
                    'kasa_adi' => $kasaAdi,
                    'kasa_renk' => $kasaRenk,
                    'kasa_sira_no' => $kasaSiraNo,
                    'kasa_bakiye' => $kasaBakiye,
                    'kasa_aciklama' => $kasaAciklama,
                    'kasa_durum' => $kasaDurum
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['kasa_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    $data['kasa_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $db->update('Kasa', $data, ['kasa_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Kasa güncellendi!']);
                } else {
                    // Yeni kayıt
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['kasa_olusturan_kullanici_id'] = $user['kullanici_id'];
                    $db->insert('Kasa', $data);
                    echo json_encode(['success' => true, 'message' => 'Kasa eklendi!']);
                }
                break;
                
            case 'delete':
                // Sil
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                
                // Kasa bakiyesi kontrolü
                $kasa = $db->fetchOne("SELECT kasa_bakiye FROM Kasa WHERE kasa_id = ?", [$id]);
                if ($kasa && $kasa['kasa_bakiye'] != 0) {
                    echo json_encode(['success' => false, 'message' => 'Bakiyesi sıfırdan farklı olan kasa silinemez!']);
                    break;
                }
                
                $db->delete('Kasa', ['kasa_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Kasa silindi!']);
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
    <title><?= $pageTitle ?> - <?= $siteTitle ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- AdminLTE CSS -->
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
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
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-wallet2"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kasa</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Kasa</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-currency-exchange"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Bakiye</span>
                                    <span class="info-box-number" id="stat-bakiye">₺0,00</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif Kasa</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                        </div>
                        <div class="card-body">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kasa adı veya açıklama...">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
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

                    <!-- Kasa Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-list"></i> Kasa Listesi
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="openModal()">
                                        <i class="bi bi-plus-circle"></i> Yeni Kasa
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th width="50px">ID</th>
                                            <th>Kasa Adı</th>
                                            <th width="80px">Renk</th>
                                            <th width="80px">Sıra</th>
                                            <th width="120px">Bakiye</th>
                                            <th>Açıklama</th>
                                            <th width="80px">Durum</th>
                                            <th width="150px">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr>
                                            <td colspan="8" class="text-center">Yükleniyor...</td>
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

    <!-- Kasa Modal -->
    <div class="modal fade" id="kasaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Kasa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="kasaForm">
                    <input type="hidden" name="id" id="kasa_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Kasa Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="kasa_adi" id="kasa_adi" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Renk</label>
                                <input type="color" class="form-control form-control-color" name="kasa_renk" id="kasa_renk" value="#0d6efd">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sıra No</label>
                                <input type="number" class="form-control" name="kasa_sira_no" id="kasa_sira_no" min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Bakiye (₺)</label>
                            <input type="number" class="form-control" name="kasa_bakiye" id="kasa_bakiye" step="0.01" value="0.00">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="kasa_aciklama" id="kasa_aciklama" rows="3"></textarea>
                        </div>
                        
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="kasa_durum" id="kasa_durum" checked>
                            <label class="form-check-label" for="kasa_durum">Aktif</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    
    
    
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        // Yetki kontrolü
        const permissions = {
            can_add: <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
            can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
        };

        let currentFilters = {};
        let modal;

        // Sayfa hazır
        $(document).ready(function() {
            // Modal init
            modal = new bootstrap.Modal(document.getElementById('kasaModal'));
            
            // İlk yükleme
            loadStats();
            loadList();
            
            // Select2 init
            $('#filter_durum').select2({
                theme: 'bootstrap-5',
                placeholder: 'Tümü',
                allowClear: true
            });
        });

        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-bakiye').text(formatCurrency(response.data.toplam_bakiye));
                }
            });
        }

        // Liste yükle
        function loadList() {
            $.post('', { 
                action: 'list',
                ...currentFilters
            }, response => {
                const tbody = $('#tableBody');
                tbody.empty();
                
                if (response.success && response.data.length > 0) {
                    response.data.forEach(kasa => {
                        const row = `
                            <tr>
                                <td>${kasa.kasa_id}</td>
                                <td>
                                    <span class="badge" style="background-color: ${kasa.kasa_renk}; color: #fff;">
                                        ${kasa.kasa_adi}
                                    </span>
                                </td>
                                <td>
                                    <div style="width: 40px; height: 25px; background-color: ${kasa.kasa_renk}; border-radius: 4px; border: 1px solid #dee2e6;"></div>
                                </td>
                                <td class="text-center">${kasa.kasa_sira_no || '-'}</td>
                                <td class="text-end">${formatCurrency(kasa.kasa_bakiye)}</td>
                                <td>${kasa.kasa_aciklama || '-'}</td>
                                <td>
                                    <span class="badge bg-${kasa.kasa_durum == 1 ? 'success' : 'secondary'}">
                                        ${kasa.kasa_durum == 1 ? 'Aktif' : 'Pasif'}
                                    </span>
                                </td>
                                <td>
                                    ${permissions.can_edit ? `
                                        <button class="btn btn-sm btn-warning" onclick="editRecord(${kasa.kasa_id})" title="Düzenle">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    ` : ''}
                                    ${permissions.can_delete ? `
                                        <button class="btn btn-sm btn-danger" onclick="deleteRecord(${kasa.kasa_id}, '${kasa.kasa_adi}')" title="Sil">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    ` : ''}
                                </td>
                            </tr>
                        `;
                        tbody.append(row);
                    });
                } else {
                    tbody.html('<tr><td colspan="8" class="text-center">Kayıt bulunamadı</td></tr>');
                }
            });
        }

        // Modal aç (Yeni kayıt)
        function openModal() {
            if (!permissions.can_add) {
                showToast('Ekleme yetkiniz yok!', 'warning');
                return;
            }
            
            $('#modalTitle').text('Yeni Kasa');
            $('#kasaForm')[0].reset();
            $('#kasa_id').val('');
            $('#kasa_renk').val('#0d6efd');
            $('#kasa_durum').prop('checked', true);
            modal.show();
        }

        // Düzenle
        function editRecord(id) {
            if (!permissions.can_edit) {
                showToast('Düzenleme yetkiniz yok!', 'warning');
                return;
            }
            
            $.post('', { action: 'get', id: id }, response => {
                if (response.success && response.data) {
                    const data = response.data;
                    $('#modalTitle').text('Kasa Düzenle');
                    $('#kasa_id').val(data.kasa_id);
                    $('#kasa_adi').val(data.kasa_adi);
                    $('#kasa_renk').val(data.kasa_renk || '#0d6efd');
                    $('#kasa_sira_no').val(data.kasa_sira_no || '');
                    $('#kasa_bakiye').val(data.kasa_bakiye || 0);
                    $('#kasa_aciklama').val(data.kasa_aciklama || '');
                    $('#kasa_durum').prop('checked', data.kasa_durum == 1);
                    modal.show();
                } else {
                    showToast('Kayıt bulunamadı!', 'error');
                }
            });
        }

        // Sil
        function deleteRecord(id, adi) {
            if (!permissions.can_delete) {
                showToast('Silme yetkiniz yok!', 'warning');
                return;
            }
            
            confirmAction(
                `"${adi}" kasasını silmek istediğinize emin misiniz?`,
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

        // Form submit
        $('#kasaForm').on('submit', function(e) {
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

        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            currentFilters = {
                search: $('#filter_search').val(),
                durum: $('#filter_durum').val()
            };
            
            loadList();
            showToast('Filtre uygulandı', 'info');
        });

        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_durum').val('').trigger('change.select2');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        // Para formatı
        function formatCurrency(value) {
            return new Intl.NumberFormat('tr-TR', {
                style: 'currency',
                currency: 'TRY',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(value || 0);
        }
    </script>
</body>
</html>
