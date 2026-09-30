<?php
/**
 * Admin Panel - Personel Banka Raporu
 * 
 * Personel banka bilgilerinin raporlanması
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Personel Banka Raporu';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Banka listesini çek (sadece personel için kullanılanlar)
$bankalar = $db->fetchAll("SELECT banka_id, banka_adi FROM bankalar WHERE banka_durum = 1 ORDER BY banka_adi");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum IS NOT NULL")['sayi'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum = 1")['sayi'] ?? 0,
                    'pasif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum = 0")['sayi'] ?? 0,
                    'banka_eksik' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum = 1 AND (kullanici_iban IS NULL OR kullanici_iban = '' OR kullanici_banka_id IS NULL)")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $bankalarFiltre = $_POST['bankalar'] ?? [];
                $durum = $_POST['durum'] ?? '';
                $search = $_POST['search'] ?? '';
                
                // SQL sorgusu oluştur
                $sql = "
                    SELECT 
                        k.kullanici_id,
                        k.kullanici_ad,
                        k.kullanici_soyad,
                        k.kullanici_tc_kimlik_no,
                        CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 104) as kullanici_dogum_tarihi,
                        k.kullanici_telefon,
                        k.kullanici_iban,
                        k.kullanici_banka_id,
                        b.banka_adi,
                        CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 104) as kullanici_ise_giris_tarihi,
                        CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 104) as kullanici_ise_cikis_tarihi,
                        k.kullanici_durum
                    FROM kullanicilar k
                    LEFT JOIN bankalar b ON k.kullanici_banka_id = b.banka_id
                    WHERE 1=1
                ";
                $params = [];
                
                // Banka filtresi (çoklu seçim)
                if (!empty($bankalarFiltre) && is_array($bankalarFiltre)) {
                    $placeholders = implode(',', array_fill(0, count($bankalarFiltre), '?'));
                    $sql .= " AND k.kullanici_banka_id IN ($placeholders)";
                    $params = array_merge($params, $bankalarFiltre);
                }
                
                // Durum filtresi
                if ($durum !== '') {
                    $sql .= " AND k.kullanici_durum = ?";
                    $params[] = $durum;
                }
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ? OR k.kullanici_tc_kimlik_no LIKE ? OR k.kullanici_iban LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY k.kullanici_ad, k.kullanici_soyad";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'export':
                // Excel/CSV export işlemi için veri hazırla
                $bankalarFiltre = $_POST['bankalar'] ?? [];
                $durum = $_POST['durum'] ?? '';
                $search = $_POST['search'] ?? '';
                
                $sql = "
                    SELECT 
                        k.kullanici_ad + ' ' + k.kullanici_soyad as ad_soyad,
                        k.kullanici_tc_kimlik_no as tc,
                        CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 104) as dogum_tarihi,
                        k.kullanici_telefon as telefon,
                        k.kullanici_iban as iban,
                        b.banka_adi,
                        CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 104) as ise_giris,
                        CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 104) as ise_cikis
                    FROM kullanicilar k
                    LEFT JOIN bankalar b ON k.kullanici_banka_id = b.banka_id
                    WHERE 1=1
                ";
                $params = [];
                
                if (!empty($bankalarFiltre) && is_array($bankalarFiltre)) {
                    $placeholders = implode(',', array_fill(0, count($bankalarFiltre), '?'));
                    $sql .= " AND k.kullanici_banka_id IN ($placeholders)";
                    $params = array_merge($params, $bankalarFiltre);
                }
                
                if ($durum !== '') {
                    $sql .= " AND k.kullanici_durum = ?";
                    $params[] = $durum;
                }
                
                if ($search) {
                    $sql .= " AND (k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ? OR k.kullanici_tc_kimlik_no LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY k.kullanici_ad, k.kullanici_soyad";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        /* Info box hover */
        .info-box {
            transition: transform 0.2s;
        }
        
        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        /* Badge stilleri */
        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        
        /* Tablo stilleri */
        #dataTable th, #dataTable td {
            vertical-align: middle;
            white-space: nowrap;
        }
        
        /* Select2 çoklu seçim düzeltmesi */
        .select2-container--bootstrap-5 .select2-selection--multiple {
            min-height: 38px;
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
                                    <i class="bi bi-people"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Personel</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-person-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Personel</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm">
                                    <i class="bi bi-person-x"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif Personel</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Banka Bilgisi Eksik</span>
                                    <span class="info-box-number" id="stat-banka-eksik">0</span>
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
                                    <!-- Banka (Çoklu Seçim) -->
                                    <div class="col-md-4">
                                        <label class="form-label">Banka</label>
                                        <select class="form-select" id="filter_bankalar" name="bankalar[]" multiple>
                                            <?php foreach ($bankalar as $banka): ?>
                                                <option value="<?= $banka['banka_id'] ?>"><?= htmlspecialchars($banka['banka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Birden fazla banka seçebilirsiniz</small>
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
                                    
                                    <!-- Arama -->
                                    <div class="col-md-4">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" id="filter_search" name="search" placeholder="İsim, TC veya IBAN...">
                                    </div>
                                    
                                    <!-- Butonlar -->
                                    <div class="col-md-2 d-flex align-items-end">
                                        <div class="btn-group w-100">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-search"></i> Filtrele
                                            </button>
                                            <button type="button" class="btn btn-secondary" id="clearFilters">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-bank"></i> Personel Banka Bilgileri</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <button type="button" class="btn btn-sm btn-success" id="btnExportExcel">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th>İsim Soyisim</th>
                                            <th>TC Kimlik No</th>
                                            <th>Doğum Tarihi</th>
                                            <th>Telefon</th>
                                            <th>IBAN</th>
                                            <th>Banka</th>
                                            <th>İşe Giriş Tarihi</th>
                                            <th>İşten Çıkış Tarihi</th>
                                            <th>Durum</th>
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
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.3.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    $(document).ready(function() {
        // DataTable instance
        let table;
        
        // Filtre değerleri
        let currentFilters = {};
        
        // Select2 başlat - Çoklu banka seçimi
        $('#filter_bankalar').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Banka seçiniz...',
            allowClear: true,
            closeOnSelect: false,
            language: {
                noResults: function() { return "Sonuç bulunamadı"; },
                searching: function() { return "Aranıyor..."; }
            }
        });
        
        // DataTable başlat
        table = $('#dataTable').DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: '',
                type: 'POST',
                data: function(d) {
                    return {
                        action: 'list',
                        ...currentFilters
                    };
                },
                dataSrc: function(json) {
                    if (json.success) {
                        return json.data;
                    }
                    showToast('Veri yüklenirken hata oluştu', 'error');
                    return [];
                }
            },
            columns: [
                { 
                    data: null,
                    render: function(data) {
                        return (data.kullanici_ad || '') + ' ' + (data.kullanici_soyad || '');
                    }
                },
                { data: 'kullanici_tc_kimlik_no', defaultContent: '-' },
                { data: 'kullanici_dogum_tarihi', defaultContent: '-' },
                { data: 'kullanici_telefon', defaultContent: '-' },
                { 
                    data: 'kullanici_iban',
                    defaultContent: '-',
                    render: function(data) {
                        if (!data) return '<span class="text-muted">-</span>';
                        // IBAN'ı formatla
                        return '<code class="text-primary">' + data + '</code>';
                    }
                },
                { 
                    data: 'banka_adi',
                    defaultContent: '-',
                    render: function(data) {
                        if (!data) return '<span class="badge bg-warning">Tanımsız</span>';
                        return data;
                    }
                },
                { data: 'kullanici_ise_giris_tarihi', defaultContent: '-' },
                { 
                    data: 'kullanici_ise_cikis_tarihi',
                    defaultContent: '-',
                    render: function(data) {
                        if (!data) return '<span class="text-muted">-</span>';
                        return '<span class="text-danger">' + data + '</span>';
                    }
                },
                { 
                    data: 'kullanici_durum',
                    render: function(data) {
                        if (data == 1) {
                            return '<span class="badge bg-success">Aktif</span>';
                        }
                        return '<span class="badge bg-secondary">Pasif</span>';
                    }
                }
            ],
            order: [[0, 'asc']],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
            },
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
        });
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-banka-eksik').text(response.data.banka_eksik);
                }
            });
        }
        
        // Sayfa yüklendiğinde
        loadStats();
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            // Filtreleri topla
            currentFilters = {
                'bankalar[]': $('#filter_bankalar').val() || [],
                durum: $('#filter_durum').val(),
                search: $('#filter_search').val()
            };
            
            table.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_bankalar').val(null).trigger('change.select2');
            $('#filter_durum').val('').trigger('change.select2');
            currentFilters = {};
            table.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Excel export
        $('#btnExportExcel').on('click', function() {
            // Mevcut filtreleri kullanarak veri çek
            $.post('', { 
                action: 'export',
                'bankalar[]': currentFilters['bankalar[]'] || [],
                durum: currentFilters.durum || '',
                search: currentFilters.search || ''
            }, function(response) {
                if (response.success && response.data.length > 0) {
                    exportToExcel(response.data);
                } else {
                    showToast('Dışa aktarılacak veri bulunamadı', 'warning');
                }
            });
        });
        
        // Excel export fonksiyonu
        function exportToExcel(data) {
            // CSV formatında oluştur
            let csv = '\ufeff'; // UTF-8 BOM
            
            // Başlıklar
            csv += 'İsim Soyisim;TC Kimlik No;Doğum Tarihi;Telefon;IBAN;Banka;İşe Giriş;İşten Çıkış\n';
            
            // Veriler
            data.forEach(function(row) {
                csv += '"' + (row.ad_soyad || '') + '";';
                csv += '"' + (row.tc || '') + '";';
                csv += '"' + (row.dogum_tarihi || '') + '";';
                csv += '"' + (row.telefon || '') + '";';
                csv += '"' + (row.iban || '') + '";';
                csv += '"' + (row.banka_adi || '') + '";';
                csv += '"' + (row.ise_giris || '') + '";';
                csv += '"' + (row.ise_cikis || '') + '"\n';
            });
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'personel_banka_raporu_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showToast('Excel dosyası indirildi', 'success');
        }
    });
    </script>
</body>
</html>
