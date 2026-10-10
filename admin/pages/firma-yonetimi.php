<?php
/**
 * Admin Panel - Firma Tanımla
 * 
 * Firma kayıtlarının yönetimi
 */

require_once __DIR__ . '/../auth.php';
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Firma Tanımla';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Ülke listesini getir (Şehir ve İlçe AJAX ile yüklenecek)
$ulkeler = $db->fetchAll("SELECT UlkeId, UlkeAdi FROM Ulkeler ORDER BY UlkeAdi");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'getSehirler':
                // Belirli bir ülkeye ait şehirleri getir
                $ulkeId = $_POST['ulke_id'] ?? 0;
                if ($ulkeId) {
                    $sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Sehirler WHERE UlkeId = ? ORDER BY SehirAdi", [$ulkeId]);
                    echo json_encode(['success' => true, 'data' => $sehirler]);
                } else {
                    echo json_encode(['success' => true, 'data' => []]);
                }
                break;
                
            case 'getIlceler':
                // Belirli bir şehre ait ilçeleri getir
                $sehirId = $_POST['sehir_id'] ?? 0;
                $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $ilceler]);
                break;
                
            case 'list':
                // Firma listesini getir
                $firmaList = $db->fetchAll("
                    SELECT 
                        f.firma_id,
                        f.firma_adi,
                        f.firma_unvan,
                        f.firma_vergi_no,
                        f.firma_telefon,
                        f.firma_email,
                        f.firma_logo_url,
                        f.firma_durum,
                        CONVERT(VARCHAR(19), f.firma_olusturma_tarihi, 120) as firma_olusturma_tarihi,
                        u.UlkeAdi as ulke_adi,
                        s.SehirAdi as sehir_adi,
                        i.IlceAdi as ilce_adi,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as olusturan_kullanici,
                        kg.kullanici_ad + ' ' + kg.kullanici_soyad as guncelleyen_kullanici,
                        CONVERT(VARCHAR(19), f.firma_guncelleme_tarihi, 120) as firma_guncelleme_tarihi
                    FROM Firmalar f
                    LEFT JOIN kullanicilar ko ON f.firma_olusturan_kullanici_id = ko.kullanici_id
                    LEFT JOIN kullanicilar kg ON f.firma_guncelleyen_kullanici_id = kg.kullanici_id
                    LEFT JOIN Ulkeler u ON f.firma_ulke_id = u.UlkeId
                    LEFT JOIN Sehirler s ON f.firma_sehir_id = s.SehirId
                    LEFT JOIN Ilceler i ON f.firma_ilce_id = i.ilceId
                    ORDER BY f.firma_olusturma_tarihi DESC
                ");
                echo json_encode(['success' => true, 'data' => $firmaList]);
                break;
                
            case 'get':
                // Tek bir firma kaydını getir
                $firmaId = $_POST['firma_id'] ?? 0;
                $firma = $db->fetchOne("SELECT * FROM Firmalar WHERE firma_id = ?", [$firmaId]);
                echo json_encode(['success' => true, 'data' => $firma]);
                break;
                
            case 'save':
                // Yeni firma kaydet veya güncelle
                $firmaId = $_POST['firma_id'] ?? 0;
                $data = [
                    'firma_adi' => $_POST['firma_adi'] ?? '',
                    'firma_unvan' => $_POST['firma_unvan'] ?? null,
                    'firma_vergi_dairesi' => $_POST['firma_vergi_dairesi'] ?? null,
                    'firma_vergi_no' => $_POST['firma_vergi_no'] ?? null,
                    'firma_mersis_no' => $_POST['firma_mersis_no'] ?? null,
                    'firma_telefon' => $_POST['firma_telefon'] ?? null,
                    'firma_email' => $_POST['firma_email'] ?? null,
                    'firma_adres' => $_POST['firma_adres'] ?? null,
                    'firma_ulke_id' => !empty($_POST['firma_ulke_id']) ? $_POST['firma_ulke_id'] : null,
                    'firma_sehir_id' => !empty($_POST['firma_sehir_id']) ? $_POST['firma_sehir_id'] : null,
                    'firma_ilce_id' => !empty($_POST['firma_ilce_id']) ? $_POST['firma_ilce_id'] : null,
                    'firma_durum' => isset($_POST['firma_durum']) ? 1 : 0
                ];
                
                // Logo yükleme işlemi
                if (isset($_FILES['firma_logo']) && $_FILES['firma_logo']['error'] == 0) {
                    $uploadDir = __DIR__ . '/../assets/uploads/firmalar/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileName = time() . '_' . basename($_FILES['firma_logo']['name']);
                    $targetPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['firma_logo']['tmp_name'], $targetPath)) {
                        $data['firma_logo_url'] = 'assets/uploads/firmalar/' . $fileName;
                        
                        // Eski logoyu sil
                        if ($firmaId > 0) {
                            $oldFirma = $db->fetchOne("SELECT firma_logo_url FROM Firmalar WHERE firma_id = ?", [$firmaId]);
                            if ($oldFirma && !empty($oldFirma['firma_logo_url'])) {
                                $oldFile = __DIR__ . '/../' . $oldFirma['firma_logo_url'];
                                if (file_exists($oldFile)) {
                                    unlink($oldFile);
                                }
                            }
                        }
                    }
                }
                
                if ($firmaId > 0) {
                    // Güncelleme
                    $data['firma_guncelleyen_kullanici_id'] = $user['id']; // Auth::user() 'id' key'i kullanıyor
                    
                    // Kullanıcı NULL ise hata ver
                    if (empty($data['firma_guncelleyen_kullanici_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Kullanıcı bilgisi alınamadı']);
                        break;
                    }
                    
                    $db->update('Firmalar', $data, ['firma_id' => $firmaId]);
                    echo json_encode(['success' => true, 'message' => 'Firma başarıyla güncellendi']);
                } else {
                    // Yeni kayıt
                    $data['firma_olusturan_kullanici_id'] = $user['id']; // Auth::user() 'id' key'i kullanıyor
                    
                    // MSSQL DEFAULT constraint kullanacağı için tarih alanını eklemeye gerek yok
                    // Ancak kullanıcı NULL ise hata ver
                    if (empty($data['firma_olusturan_kullanici_id'])) {
                        echo json_encode(['success' => false, 'message' => 'Kullanıcı bilgisi alınamadı']);
                        break;
                    }
                    
                    $db->insert('Firmalar', $data);
                    echo json_encode(['success' => true, 'message' => 'Firma başarıyla eklendi']);
                }
                break;
                
            case 'delete':
                // Firma sil
                $firmaId = $_POST['firma_id'] ?? 0;
                
                // Logo dosyasını sil
                $firma = $db->fetchOne("SELECT firma_logo_url FROM Firmalar WHERE firma_id = ?", [$firmaId]);
                if ($firma && !empty($firma['firma_logo_url'])) {
                    $logoFile = __DIR__ . '/../' . $firma['firma_logo_url'];
                    if (file_exists($logoFile)) {
                        unlink($logoFile);
                    }
                }
                
                $db->delete('Firmalar', ['firma_id' => $firmaId]);
                echo json_encode(['success' => true, 'message' => 'Firma başarıyla silindi']);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <style>
        .logo-preview {
            max-width: 150px;
            max-height: 100px;
            margin-top: 10px;
            border: 1px solid #ddd;
            padding: 5px;
            border-radius: 4px;
        }
        .logo-preview-small {
            max-width: 50px;
            max-height: 50px;
            object-fit: contain;
        }
        .badge-aktif {
            background-color: #28a745;
        }
        .badge-pasif {
            background-color: #dc3545;
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
                    
                    <!-- Firma Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Firma Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-primary btn-sm" onclick="openFirmaModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Firma Ekle
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered table-striped table-hover" id="firmaTable">
                                <thead>
                                    <tr>
                                        <th>Logo</th>
                                        <th>Firma Adı</th>
                                        <th>Ünvan</th>
                                        <th>Vergi No</th>
                                        <th>Telefon</th>
                                        <th>Email</th>
                                        <th>Ülke</th>
                                        <th>Şehir</th>
                                        <th>İlçe</th>
                                        <th>Durum</th>
                                        <th>Oluşturma</th>
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
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Firma Modal -->
    <div class="modal fade" id="firmaModal" tabindex="-1" aria-labelledby="firmaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="firmaModalLabel">Firma Ekle/Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="firmaForm" class="needs-validation" novalidate>
                    <input type="hidden" id="firma_id" name="firma_id" value="0">
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Logo Yükleme -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3">Logo</h6>
                            </div>
                            
                            <div class="col-md-12">
                                <label for="firma_logo" class="form-label">Firma Logosu</label>
                                <input type="file" class="form-control" id="firma_logo" name="firma_logo" accept="image/*" onchange="previewLogo(event)">
                                <small class="text-muted">Desteklenen formatlar: JPG, PNG, GIF (Max 2MB)</small>
                                <div id="logoPreview"></div>
                            </div>
                            
                            <!-- Genel Bilgiler -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">Genel Bilgiler</h6>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="firma_adi" class="form-label">Firma Adı *</label>
                                <input type="text" class="form-control" id="firma_adi" name="firma_adi" required>
                                <div class="invalid-feedback">Firma adı zorunludur.</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="firma_unvan" class="form-label">Ünvan</label>
                                <input type="text" class="form-control" id="firma_unvan" name="firma_unvan">
                            </div>
                            
                            <!-- Vergi Bilgileri -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">Vergi Bilgileri</h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="firma_vergi_dairesi" class="form-label">Vergi Dairesi</label>
                                <input type="text" class="form-control" id="firma_vergi_dairesi" name="firma_vergi_dairesi">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="firma_vergi_no" class="form-label">Vergi No</label>
                                <input type="text" class="form-control" id="firma_vergi_no" name="firma_vergi_no">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="firma_mersis_no" class="form-label">Mersis No</label>
                                <input type="text" class="form-control" id="firma_mersis_no" name="firma_mersis_no">
                            </div>
                            
                            <!-- İletişim Bilgileri -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">İletişim Bilgileri</h6>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="firma_telefon" class="form-label">Telefon</label>
                                <input type="tel" class="form-control" id="firma_telefon" name="firma_telefon">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="firma_email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="firma_email" name="firma_email">
                            </div>
                            
                            <!-- Konum Bilgileri -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">Konum Bilgileri</h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="firma_ulke_id" class="form-label">Ülke</label>
                                <select class="form-select" id="firma_ulke_id" name="firma_ulke_id" onchange="loadSehirler()">
                                    <option value="">Ülke Seçiniz...</option>
                                    <?php foreach ($ulkeler as $ulke): ?>
                                        <option value="<?= $ulke['UlkeId'] ?>"><?= htmlspecialchars($ulke['UlkeAdi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="firma_sehir_id" class="form-label">Şehir</label>
                                <select class="form-select" id="firma_sehir_id" name="firma_sehir_id" onchange="loadIlceler()" disabled>
                                    <option value="">Önce ülke seçiniz...</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="firma_ilce_id" class="form-label">İlçe</label>
                                <select class="form-select" id="firma_ilce_id" name="firma_ilce_id" disabled>
                                    <option value="">Önce şehir seçiniz...</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="firma_adres" class="form-label">Adres</label>
                                <textarea class="form-control" id="firma_adres" name="firma_adres" rows="3"></textarea>
                            </div>
                            
                            <!-- Durum -->
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="firma_durum" name="firma_durum" checked>
                                    <label class="form-check-label" for="firma_durum">
                                        Aktif
                                    </label>
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
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let firmaModal;
        let dataTable;
        
        // Tarih formatlama fonksiyonu (MSSQL uyumlu)
        function formatDate(dateString) {
            if (!dateString) return '-';
            
            try {
                // MSSQL datetime object ise date property'sini al
                if (typeof dateString === 'object' && dateString.date) {
                    dateString = dateString.date;
                }
                
                // String değilse string'e çevir
                if (typeof dateString !== 'string') {
                    dateString = String(dateString);
                }
                
                // MSSQL datetime formatını JavaScript Date'e çevir
                // Format: "2025-11-18 15:30:00" veya "2025-11-18T15:30:00"
                const date = new Date(dateString.replace(' ', 'T'));
                
                if (isNaN(date.getTime())) {
                    return '-';
                }
                
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                console.error('Tarih formatlama hatası:', dateString, e);
                return '-';
            }
        }
        
        // showToast(), confirmAction(), showSuccess(), showError() artık custom.js'den geliyor
        
        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            firmaModal = new bootstrap.Modal(document.getElementById('firmaModal'));
            loadFirmaList();
            
            // Form validation
            const forms = document.querySelectorAll('.needs-validation');
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    if (form.checkValidity()) {
                        saveFirma();
                    }
                    
                    form.classList.add('was-validated');
                }, false);
            });
        });
        
        // Logo önizleme
        function previewLogo(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('logoPreview');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" class="logo-preview" alt="Logo Önizleme">`;
                }
                reader.readAsDataURL(file);
            }
        }
        
        // Ülke seçildiğinde şehirleri yükle
        function loadSehirler() {
            const ulkeId = document.getElementById('firma_ulke_id').value;
            const sehirSelect = document.getElementById('firma_sehir_id');
            const ilceSelect = document.getElementById('firma_ilce_id');
            
            sehirSelect.innerHTML = '<option value="">Şehir Seçiniz...</option>';
            ilceSelect.innerHTML = '<option value="">Önce şehir seçiniz...</option>';
            ilceSelect.disabled = true;
            
            if (ulkeId) {
                // AJAX ile seçilen ülkeye ait şehirleri getir
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: { action: 'getSehirler', ulke_id: ulkeId },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.data.length > 0) {
                            data.data.forEach(sehir => {
                                const option = document.createElement('option');
                                option.value = sehir.SehirId;
                                option.textContent = sehir.SehirAdi;
                                sehirSelect.appendChild(option);
                            });
                            sehirSelect.disabled = false;
                        } else {
                            sehirSelect.disabled = true;
                            sehirSelect.innerHTML = '<option value="">Bu ülke için şehir bulunamadı</option>';
                        }
                    },
                    error: function(error) {
                        console.error('Error:', error);
                        sehirSelect.disabled = true;
                    }
                });
            } else {
                sehirSelect.disabled = true;
                sehirSelect.innerHTML = '<option value="">Önce ülke seçiniz...</option>';
            }
        }
        
        // Şehir seçildiğinde ilçeleri yükle
        function loadIlceler() {
            const sehirId = document.getElementById('firma_sehir_id').value;
            const ilceSelect = document.getElementById('firma_ilce_id');
            
            ilceSelect.innerHTML = '<option value="">İlçe Seçiniz...</option>';
            
            if (sehirId) {
                // AJAX ile seçilen şehre ait ilçeleri getir
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: { action: 'getIlceler', sehir_id: sehirId },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.data.length > 0) {
                            data.data.forEach(ilce => {
                                const option = document.createElement('option');
                                option.value = ilce.ilceId;
                                option.textContent = ilce.IlceAdi;
                                ilceSelect.appendChild(option);
                            });
                            ilceSelect.disabled = false;
                        } else {
                            ilceSelect.disabled = true;
                            ilceSelect.innerHTML = '<option value="">Bu şehir için ilçe bulunamadı</option>';
                        }
                    },
                    error: function(error) {
                        console.error('Error:', error);
                        ilceSelect.disabled = true;
                    }
                });
            } else {
                ilceSelect.disabled = true;
                ilceSelect.innerHTML = '<option value="">Önce şehir seçiniz...</option>';
            }
        }
        
        // Firma listesini yükle
        function loadFirmaList() {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'list' },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        renderFirmaTable(data.data);
                    }
                },
                error: function(error) {
                    console.error('Error:', error);
                }
            });
        }
        
        // Tabloyu render et
        function renderFirmaTable(firmaList) {
            if ($.fn.DataTable.isDataTable('#firmaTable')) {
                $('#firmaTable').DataTable().destroy();
            }
            
            const tbody = document.querySelector('#firmaTable tbody');
            tbody.innerHTML = '';
            
            firmaList.forEach(firma => {
                const statusBadge = firma.firma_durum == 1 
                    ? '<span class="badge badge-aktif">Aktif</span>' 
                    : '<span class="badge badge-pasif">Pasif</span>';
                
                const logoHtml = firma.firma_logo_url 
                    ? (() => {
                        const imgPath = firma.firma_logo_url.startsWith('assets/') ? '/admin/' + firma.firma_logo_url : firma.firma_logo_url;
                        return `<img src="${imgPath}" class="logo-preview-small" alt="Logo">`;
                    })()
                    : '<i class="bi bi-image text-muted"></i>';
                
                const olusturmaTarihi = formatDate(firma.firma_olusturma_tarihi);
                    
                const row = `
                    <tr>
                        <td class="text-center">${logoHtml}</td>
                        <td>${firma.firma_adi || ''}</td>
                        <td>${firma.firma_unvan || '-'}</td>
                        <td>${firma.firma_vergi_no || '-'}</td>
                        <td>${firma.firma_telefon || '-'}</td>
                        <td>${firma.firma_email || '-'}</td>
                        <td>${firma.ulke_adi || '-'}</td>
                        <td>${firma.sehir_adi || '-'}</td>
                        <td>${firma.ilce_adi || '-'}</td>
                        <td>${statusBadge}</td>
                        <td>${olusturmaTarihi}</td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editFirma(${firma.firma_id})" title="Düzenle">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteFirma(${firma.firma_id})" title="Sil">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
            
            // DataTable başlat
            dataTable = $('#firmaTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
                },
                order: [[10, 'desc']], // Oluşturma tarihi kolonunu güncelle
                pageLength: 25,
                columnDefs: [
                    { orderable: false, targets: [0, 11] } // Logo ve İşlemler kolonları
                ]
            });
        }
        
        // Modal aç (Yeni)
        function openFirmaModal() {
            document.getElementById('firmaModalLabel').textContent = 'Yeni Firma Ekle';
            document.getElementById('firmaForm').reset();
            document.getElementById('firma_id').value = '0';
            document.getElementById('firma_durum').checked = true;
            document.getElementById('firmaForm').classList.remove('was-validated');
            document.getElementById('logoPreview').innerHTML = '';
            
            // Konum alanlarını sıfırla
            document.getElementById('firma_sehir_id').disabled = true;
            document.getElementById('firma_ilce_id').disabled = true;
            document.getElementById('firma_sehir_id').innerHTML = '<option value="">Önce ülke seçiniz...</option>';
            document.getElementById('firma_ilce_id').innerHTML = '<option value="">Önce şehir seçiniz...</option>';
            
            firmaModal.show();
        }
        
        // Firma düzenle
        function editFirma(firmaId) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', firma_id: firmaId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        const firma = data.data;
                        document.getElementById('firmaModalLabel').textContent = 'Firma Düzenle';
                        document.getElementById('firma_id').value = firma.firma_id;
                        document.getElementById('firma_adi').value = firma.firma_adi || '';
                        document.getElementById('firma_unvan').value = firma.firma_unvan || '';
                        document.getElementById('firma_vergi_dairesi').value = firma.firma_vergi_dairesi || '';
                        document.getElementById('firma_vergi_no').value = firma.firma_vergi_no || '';
                        document.getElementById('firma_mersis_no').value = firma.firma_mersis_no || '';
                        document.getElementById('firma_telefon').value = firma.firma_telefon || '';
                        document.getElementById('firma_email').value = firma.firma_email || '';
                        document.getElementById('firma_adres').value = firma.firma_adres || '';
                        document.getElementById('firma_durum').checked = firma.firma_durum == 1;
                        
                        // Logo önizleme
                        const logoPreview = document.getElementById('logoPreview');
                        if (firma.firma_logo_url) {
                            const imgPath = firma.firma_logo_url.startsWith('assets/') ? '/admin/' + firma.firma_logo_url : firma.firma_logo_url;
                            logoPreview.innerHTML = `<img src="${imgPath}" class="logo-preview" alt="Mevcut Logo">`;
                        } else {
                            logoPreview.innerHTML = '';
                        }
                        
                        // Konum alanlarını doldur
                        if (firma.firma_ulke_id) {
                            document.getElementById('firma_ulke_id').value = firma.firma_ulke_id;
                            
                            // Şehirleri yükle ve sonra şehri seç
                            $.ajax({
                                url: '',
                                method: 'POST',
                                data: { action: 'getSehirler', ulke_id: firma.firma_ulke_id },
                                dataType: 'json',
                                success: function(sehirData) {
                                    const sehirSelect = document.getElementById('firma_sehir_id');
                                    sehirSelect.innerHTML = '<option value="">Şehir Seçiniz...</option>';
                                    
                                    if (sehirData.success && sehirData.data.length > 0) {
                                        sehirData.data.forEach(sehir => {
                                            const option = document.createElement('option');
                                            option.value = sehir.SehirId;
                                            option.textContent = sehir.SehirAdi;
                                            sehirSelect.appendChild(option);
                                        });
                                        sehirSelect.disabled = false;
                                        
                                        // Şehri seç
                                        if (firma.firma_sehir_id) {
                                            sehirSelect.value = firma.firma_sehir_id;
                                            
                                            // İlçeleri yükle ve sonra ilçeyi seç
                                            $.ajax({
                                                url: '',
                                                method: 'POST',
                                                data: { action: 'getIlceler', sehir_id: firma.firma_sehir_id },
                                                dataType: 'json',
                                                success: function(ilceData) {
                                                    const ilceSelect = document.getElementById('firma_ilce_id');
                                                    ilceSelect.innerHTML = '<option value="">İlçe Seçiniz...</option>';
                                                    
                                                    if (ilceData.success && ilceData.data.length > 0) {
                                                        ilceData.data.forEach(ilce => {
                                                            const option = document.createElement('option');
                                                            option.value = ilce.ilceId;
                                                            option.textContent = ilce.IlceAdi;
                                                            ilceSelect.appendChild(option);
                                                        });
                                                        ilceSelect.disabled = false;
                                                        
                                                        // İlçeyi seç
                                                        if (firma.firma_ilce_id) {
                                                            ilceSelect.value = firma.firma_ilce_id;
                                                        }
                                                    }
                                                }
                                            });
                                        }
                                    }
                                }
                            });
                        }
                        
                        document.getElementById('firmaForm').classList.remove('was-validated');
                        firmaModal.show();
                    }
                },
                error: function(error) {
                    console.error('Error:', error);
                }
            });
        }
        
        // Firma kaydet
        function saveFirma() {
            const formData = new FormData(document.getElementById('firmaForm'));
            formData.append('action', 'save');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        firmaModal.hide();
                        loadFirmaList();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function(error) {
                    console.error('Error:', error);
                    showToast('Bir hata oluştu!', 'error');
                }
            });
        }
        
        // Firma sil
        function deleteFirma(firmaId) {
            confirmAction(
                'Bu firmayı silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'delete', firma_id: firmaId },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadFirmaList();
                            } else {
                                showError('Hata!', data.message);
                            }
                        },
                        error: function(error) {
                            console.error('Error:', error);
                            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                        }
                    });
                }
            );
        }
    </script>
</body>
</html>
