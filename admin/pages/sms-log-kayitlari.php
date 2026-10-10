<?php
/**
 * Admin Panel - Entegrasyon Log Kayitlari
 * 
 * Tum entegrasyon kanallarinin (SMS, mail, banka vb.) islem loglari
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Entegrasyon Log Kayitlari';
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
                // İstatistikleri getir (migrasyon işaret kayıtları Durum=0, hariç tutulur)
                $stats = [
                    'toplam'    => $db->fetchOne("SELECT COUNT(*) as sayi FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_Durum = 1")['sayi'] ?? 0,
                    'basarili'  => $db->fetchOne("SELECT COUNT(*) as sayi FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_Durum = 1 AND EntegrasyonLoglari_BasariliMi = 1")['sayi'] ?? 0,
                    'basarisiz' => $db->fetchOne("SELECT COUNT(*) as sayi FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_Durum = 1 AND EntegrasyonLoglari_BasariliMi = 0")['sayi'] ?? 0,
                    'bugun'     => $db->fetchOne("SELECT COUNT(*) as sayi FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_Durum = 1 AND CONVERT(date, EntegrasyonLoglari_OlusturmaTarihi) = CONVERT(date, GETDATE())")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'list':
                // Filtre parametrelerini al
                $startDate    = $_POST['start_date'] ?? '';
                $endDate      = $_POST['end_date'] ?? '';
                $search       = $_POST['search'] ?? '';
                $durum        = $_POST['durum'] ?? '';
                $tip          = $_POST['tip'] ?? '';
                $kaynak       = $_POST['kaynak'] ?? '';
                $entegrasyon  = $_POST['entegrasyon'] ?? '';
                $kanal        = $_POST['kanal'] ?? '';

                $sql = "SELECT
                            l.EntegrasyonLoglari_id            AS log_id,
                            l.EntegrasyonLoglari_Hedef         AS hedef,
                            l.EntegrasyonLoglari_Istek         AS istek,
                            l.EntegrasyonLoglari_IslemTipi     AS islem_tipi,
                            l.EntegrasyonLoglari_KullaniciId   AS kullanici_id,
                            l.EntegrasyonLoglari_BasariliMi    AS basarili,
                            l.EntegrasyonLoglari_Cevap         AS cevap,
                            l.EntegrasyonLoglari_HataMesaji    AS hata,
                            CONVERT(VARCHAR(19), l.EntegrasyonLoglari_OlusturmaTarihi, 120) AS tarih,
                            l.EntegrasyonLoglari_Kaynak        AS kaynak,
                            l.EntegrasyonLoglari_IP            AS ip,
                            e.Entegrasyonlar_Ad                AS entegrasyon_adi,
                            kn.EntegrasyonKanallari_Ad         AS kanal_adi,
                            ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, 'Sistem') AS kullanici_adi
                        FROM EntegrasyonLoglari l
                        LEFT JOIN EntegrasyonKanallari kn ON l.EntegrasyonLoglari_EntegrasyonKanallari_id = kn.EntegrasyonKanallari_id
                        LEFT JOIN Entegrasyonlar e ON kn.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                        LEFT JOIN kullanicilar k ON l.EntegrasyonLoglari_KullaniciId = k.kullanici_id
                        WHERE l.EntegrasyonLoglari_Durum = 1";
                $params = [];

                // Tarih filtresi
                if ($startDate) {
                    $sql .= " AND CONVERT(date, l.EntegrasyonLoglari_OlusturmaTarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, l.EntegrasyonLoglari_OlusturmaTarihi) <= ?";
                    $params[] = $endDate;
                }

                // Arama filtresi (hedef veya istek içeriği)
                if ($search) {
                    $sql .= " AND (l.EntegrasyonLoglari_Hedef LIKE ? OR l.EntegrasyonLoglari_Istek LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                if ($durum !== '') {
                    $sql .= " AND l.EntegrasyonLoglari_BasariliMi = ?";
                    $params[] = intval($durum);
                }

                if ($tip) {
                    $sql .= " AND l.EntegrasyonLoglari_IslemTipi = ?";
                    $params[] = $tip;
                }

                if ($kaynak) {
                    $sql .= " AND l.EntegrasyonLoglari_Kaynak = ?";
                    $params[] = $kaynak;
                }

                if ($entegrasyon) {
                    $sql .= " AND e.Entegrasyonlar_id = ?";
                    $params[] = intval($entegrasyon);
                }

                if ($kanal) {
                    $sql .= " AND kn.EntegrasyonKanallari_id = ?";
                    $params[] = intval($kanal);
                }

                $sql .= " ORDER BY l.EntegrasyonLoglari_id DESC";

                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;

            case 'get':
                // Tek kayıt getir (detay için)
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("
                    SELECT
                        l.EntegrasyonLoglari_id          AS log_id,
                        l.EntegrasyonLoglari_Hedef       AS hedef,
                        l.EntegrasyonLoglari_Istek       AS istek,
                        l.EntegrasyonLoglari_IslemTipi   AS islem_tipi,
                        l.EntegrasyonLoglari_BasariliMi  AS basarili,
                        l.EntegrasyonLoglari_Cevap       AS cevap,
                        l.EntegrasyonLoglari_HataMesaji  AS hata,
                        l.EntegrasyonLoglari_Kaynak      AS kaynak,
                        l.EntegrasyonLoglari_IP          AS ip,
                        CONVERT(VARCHAR(19), l.EntegrasyonLoglari_OlusturmaTarihi, 120) AS tarih,
                        e.Entegrasyonlar_Ad              AS entegrasyon_adi,
                        kn.EntegrasyonKanallari_Ad       AS kanal_adi,
                        ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, 'Sistem') AS kullanici_adi
                    FROM EntegrasyonLoglari l
                    LEFT JOIN EntegrasyonKanallari kn ON l.EntegrasyonLoglari_EntegrasyonKanallari_id = kn.EntegrasyonKanallari_id
                    LEFT JOIN Entegrasyonlar e ON kn.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                    LEFT JOIN kullanicilar k ON l.EntegrasyonLoglari_KullaniciId = k.kullanici_id
                    WHERE l.EntegrasyonLoglari_id = ?
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'get_filtreler':
                // Filtre dropdown'ları için benzersiz değerler
                echo json_encode(['success' => true, 'data' => [
                    'kaynaklar' => $db->fetchAll("
                        SELECT DISTINCT EntegrasyonLoglari_Kaynak AS kaynak
                        FROM EntegrasyonLoglari
                        WHERE EntegrasyonLoglari_Kaynak IS NOT NULL AND EntegrasyonLoglari_Durum = 1
                        ORDER BY EntegrasyonLoglari_Kaynak
                    "),
                    'tipler' => $db->fetchAll("
                        SELECT DISTINCT EntegrasyonLoglari_IslemTipi AS tip
                        FROM EntegrasyonLoglari
                        WHERE EntegrasyonLoglari_Durum = 1
                        ORDER BY EntegrasyonLoglari_IslemTipi
                    "),
                    'entegrasyonlar' => $db->fetchAll("
                        SELECT Entegrasyonlar_id AS id, Entegrasyonlar_Ad AS ad
                        FROM Entegrasyonlar
                        WHERE Entegrasyonlar_Durum = 1
                        ORDER BY Entegrasyonlar_Ad
                    "),
                    'kanallar' => $db->fetchAll("
                        SELECT kn.EntegrasyonKanallari_id AS id,
                               kn.EntegrasyonKanallari_Ad AS ad,
                               kn.EntegrasyonKanallari_Entegrasyonlar_id AS entegrasyon_id
                        FROM EntegrasyonKanallari kn
                        ORDER BY kn.EntegrasyonKanallari_Ad
                    "),
                ]]);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .btn-group-sm > .btn, .btn-sm {
            padding: 0.25rem 0.5rem;
            margin: 0 2px;
        }
        .badge { font-size: 0.75rem; padding: 0.35em 0.65em; }
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .sms-mesaj-preview { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .api-yanit-box { max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px; }
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
                            <h3 class="mb-0"><i class="bi bi-chat-dots me-2"></i><?= htmlspecialchars($pageTitle) ?></h3>
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
                    
                    <!-- 1. Info Boxes (4 adet) -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-envelope-fill"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kayit</span>
                                    <span class="info-box-number" id="statToplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle-fill"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Başarılı</span>
                                    <span class="info-box-number" id="statBasarili">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-x-circle-fill"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Başarısız</span>
                                    <span class="info-box-number" id="statBasarisiz">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-calendar-check-fill"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün</span>
                                    <span class="info-box-number" id="statBugun">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 2. Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCollapse">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- Tarih Aralığı -->
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" name="start_date" id="filter_start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" name="end_date" id="filter_end_date">
                                    </div>
                                    
                                    <!-- Arama -->
                                    <div class="col-md-2">
                                        <label class="form-label">Hedef / İçerik</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Telefon, e-posta, metin...">
                                    </div>

                                    <!-- Durum -->
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Başarılı</option>
                                            <option value="0">Başarısız</option>
                                        </select>
                                    </div>

                                    <!-- Entegrasyon -->
                                    <div class="col-md-2">
                                        <label class="form-label">Entegrasyon</label>
                                        <select class="form-select" name="entegrasyon" id="filter_entegrasyon">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <!-- Kanal -->
                                    <div class="col-md-2">
                                        <label class="form-label">Kanal</label>
                                        <select class="form-select" name="kanal" id="filter_kanal">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <!-- Tip -->
                                    <div class="col-md-2">
                                        <label class="form-label">İşlem Tipi</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <!-- Kaynak -->
                                    <div class="col-md-2">
                                        <label class="form-label">Kaynak</label>
                                        <select class="form-select" name="kaynak" id="filter_kaynak">
                                            <option value="">Tümü</option>
                                        </select>
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
                    
                    <!-- 3. Ana İçerik (DataTable) -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-list-ul"></i> Entegrasyon Log Listesi
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="loadList()">
                                    <i class="bi bi-arrow-clockwise"></i> Yenile
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="dataTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th width="50">#</th>
                                            <th width="100">Entegrasyon</th>
                                            <th width="110">Kanal</th>
                                            <th width="120">Hedef</th>
                                            <th>İçerik</th>
                                            <th width="120">İşlem Tipi</th>
                                            <th width="80">Durum</th>
                                            <th width="100">Kaynak</th>
                                            <th width="140">Tarih</th>
                                            <th width="80">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Detay Modal -->
    <div class="modal fade" id="modalDetay" tabindex="-1" aria-labelledby="modalDetayLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDetayLabel"><i class="bi bi-info-circle"></i> Log Detayi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Hedef:</strong> <span id="detay_hedef"></span></p>
                            <p><strong>Entegrasyon:</strong> <span id="detay_entegrasyon"></span></p>
                            <p><strong>Kanal:</strong> <span id="detay_kanal"></span></p>
                            <p><strong>İşlem Tipi:</strong> <span id="detay_tip"></span></p>
                            <p><strong>Durum:</strong> <span id="detay_durum"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Tarih:</strong> <span id="detay_tarih"></span></p>
                            <p><strong>Kaynak:</strong> <span id="detay_kaynak"></span></p>
                            <p><strong>Kullanıcı:</strong> <span id="detay_kullanici"></span></p>
                            <p><strong>IP:</strong> <span id="detay_ip"></span></p>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <strong>Gönderilen İçerik:</strong>
                        <div class="border p-2 mt-1 bg-light rounded" id="detay_mesaj"></div>
                    </div>
                    <div class="mb-3" id="detay_hata_container" style="display:none;">
                        <strong class="text-danger">Hata:</strong>
                        <div class="border p-2 mt-1 bg-danger-subtle text-danger rounded" id="detay_hata"></div>
                    </div>
                    <div class="mb-3">
                        <strong>Servis Yaniti:</strong>
                        <div class="api-yanit-box mt-1" id="detay_api_yanit"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    let table;
    let currentFilters = {};
    let tumKanallar = [];
    const modalDetay = new bootstrap.Modal(document.getElementById('modalDetay'));
    
    $(document).ready(function() {
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
                    return json.success ? json.data : [];
                }
            },
            columns: [
                { data: 'log_id' },
                {
                    data: 'entegrasyon_adi',
                    render: data => data ? '<span class="badge bg-primary">' + escapeHtml(data) + '</span>' : '-'
                },
                {
                    data: 'kanal_adi',
                    render: data => data ? escapeHtml(data) : '-'
                },
                {
                    data: 'hedef',
                    render: function(data) {
                        return data ? '<code>' + escapeHtml(data) + '</code>' : '-';
                    }
                },
                {
                    data: 'istek',
                    render: function(data) {
                        if (!data) return '-';
                        let preview = data.length > 50 ? data.substring(0, 50) + '...' : data;
                        return '<span class="sms-mesaj-preview" title="' + escapeHtml(data) + '">' + escapeHtml(preview) + '</span>';
                    }
                },
                {
                    data: 'islem_tipi',
                    render: function(data) {
                        if (!data) return '-';
                        const renk = data.indexOf('TEST') !== -1 ? 'bg-warning'
                                   : data.indexOf('SMS') === 0 ? 'bg-secondary'
                                   : 'bg-info';
                        return '<span class="badge ' + renk + '">' + escapeHtml(data) + '</span>';
                    }
                },
                {
                    data: 'basarili',
                    render: function(data) {
                        if (data == 1) {
                            return '<span class="badge bg-success"><i class="bi bi-check"></i> Başarılı</span>';
                        }
                        return '<span class="badge bg-danger"><i class="bi bi-x"></i> Başarısız</span>';
                    }
                },
                {
                    data: 'kaynak',
                    render: function(data) {
                        return data ? '<span class="badge bg-secondary">' + escapeHtml(data) + '</span>' : '-';
                    }
                },
                {
                    data: 'tarih',
                    render: function(data) {
                        return data ? formatDate(data) : '-';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function(data, type, row) {
                        return '<button class="btn btn-sm btn-info" onclick="showDetay(' + row.log_id + ')" title="Detay"><i class="bi bi-eye"></i></button>';
                    }
                }
            ],
            order: [[0, 'desc']],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
            },
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
        });
        
        // Select2 başlat
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            placeholder: 'Seçiniz...',
            language: {
                noResults: function() { return "Sonuç bulunamadı"; }
            }
        });
        
        // Filtre listelerini yükle
        loadFiltreler();

        // İstatistikleri yükle
        loadStats();

        // Entegrasyon seçilince kanal listesi daralsın
        $('#filter_entegrasyon').on('change', function() {
            const entId = $(this).val();
            const secili = $('#filter_kanal').val();
            let options = '<option value="">Tümü</option>';

            tumKanallar
                .filter(k => !entId || k.entegrasyon_id == entId)
                .forEach(k => {
                    options += '<option value="' + k.id + '">' + escapeHtml(k.ad) + '</option>';
                });

            $('#filter_kanal').html(options).val(secili).trigger('change.select2');
        });

        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                start_date:  $('#filter_start_date').val(),
                end_date:    $('#filter_end_date').val(),
                search:      $('#filter_search').val(),
                durum:       $('#filter_durum').val(),
                tip:         $('#filter_tip').val(),
                kaynak:      $('#filter_kaynak').val(),
                entegrasyon: $('#filter_entegrasyon').val(),
                kanal:       $('#filter_kanal').val()
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
            $('#filter_durum, #filter_tip, #filter_kaynak, #filter_entegrasyon, #filter_kanal')
                .val('').trigger('change.select2');
            currentFilters = {};
            table.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });
    });
    
    // İstatistikleri yükle
    function loadStats() {
        $.post('', { action: 'stats' }, function(response) {
            if (response.success) {
                $('#statToplam').text(response.data.toplam);
                $('#statBasarili').text(response.data.basarili);
                $('#statBasarisiz').text(response.data.basarisiz);
                $('#statBugun').text(response.data.bugun);
            }
        });
    }
    
    // Listeyi yeniden yükle
    function loadList() {
        table.ajax.reload();
        loadStats();
        showToast('Liste yenilendi', 'info');
    }
    
    // Filtre listelerini yükle (kaynak, işlem tipi, entegrasyon, kanal)
    function loadFiltreler() {
        $.post('', { action: 'get_filtreler' }, function(response) {
            if (!response.success || !response.data) return;
            const d = response.data;

            let kaynakOpt = '<option value="">Tümü</option>';
            d.kaynaklar.forEach(i => {
                if (i.kaynak) kaynakOpt += '<option value="' + escapeHtml(i.kaynak) + '">' + escapeHtml(i.kaynak) + '</option>';
            });
            $('#filter_kaynak').html(kaynakOpt).trigger('change.select2');

            let tipOpt = '<option value="">Tümü</option>';
            d.tipler.forEach(i => {
                if (i.tip) tipOpt += '<option value="' + escapeHtml(i.tip) + '">' + escapeHtml(i.tip) + '</option>';
            });
            $('#filter_tip').html(tipOpt).trigger('change.select2');

            let entOpt = '<option value="">Tümü</option>';
            d.entegrasyonlar.forEach(i => {
                entOpt += '<option value="' + i.id + '">' + escapeHtml(i.ad) + '</option>';
            });
            $('#filter_entegrasyon').html(entOpt).trigger('change.select2');

            tumKanallar = d.kanallar || [];
            let kanalOpt = '<option value="">Tümü</option>';
            tumKanallar.forEach(k => {
                kanalOpt += '<option value="' + k.id + '">' + escapeHtml(k.ad) + '</option>';
            });
            $('#filter_kanal').html(kanalOpt).trigger('change.select2');
        });
    }

    // Detay göster
    function showDetay(id) {
        $.post('', { action: 'get', id: id }, function(response) {
            if (response.success && response.data) {
                const d = response.data;

                $('#detay_hedef').text(d.hedef || '-');
                $('#detay_entegrasyon').text(d.entegrasyon_adi || '-');
                $('#detay_kanal').text(d.kanal_adi || '-');
                $('#detay_tip').html('<span class="badge bg-secondary">' + escapeHtml(d.islem_tipi || '-') + '</span>');
                $('#detay_durum').html(d.basarili == 1
                    ? '<span class="badge bg-success">Başarılı</span>'
                    : '<span class="badge bg-danger">Başarısız</span>');
                $('#detay_kaynak').text(d.kaynak || '-');
                $('#detay_ip').text(d.ip || '-');
                $('#detay_tarih').text(d.tarih ? formatDate(d.tarih) : '-');
                $('#detay_kullanici').text(d.kullanici_adi || 'Sistem');
                $('#detay_mesaj').text(d.istek || '-');

                if (d.hata) {
                    $('#detay_hata').text(d.hata);
                    $('#detay_hata_container').show();
                } else {
                    $('#detay_hata_container').hide();
                }

                $('#detay_api_yanit').text(d.cevap || '-');

                modalDetay.show();
            } else {
                showToast('Kayıt bulunamadı', 'error');
            }
        });
    }
    
    // Tarih formatlama
    function formatDate(dateString) {
        if (!dateString) return '-';
        try {
            const date = new Date(dateString.replace(' ', 'T'));
            if (isNaN(date.getTime())) return dateString;
            return date.toLocaleDateString('tr-TR', {
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit'
            });
        } catch (e) {
            return dateString;
        }
    }
    
    // HTML escape
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    </script>
</body>
</html>
