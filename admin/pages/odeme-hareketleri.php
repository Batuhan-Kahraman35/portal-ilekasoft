<?php
/**
 * Admin Panel - Ödeme Hareketleri
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

// AJAX istekleri için özel auth kontrolü
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Oturum süresi doldu. Lütfen tekrar giriş yapın.', 'redirect' => '/admin/login.php']));
    }
} else {
    requireAuth();
}

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

// Yetkileri $permissions değişkenine ata (HTML ve JavaScript için)
$permissions = $pagePermissions;

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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ödeme Hareketleri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

/**
 * Ödeme hareketleri filtrelerinden WHERE koşulu ve parametreleri üretir.
 * Liste ve Excel dışa aktarma aynı sonucu vermesi için ortak kullanılır.
 *
 * @param array $girdi $_POST dizisi
 * @return array [whereClause, params]
 */
function odemeHareketFiltresi(array $girdi)
{
    $whereConditions = ["1=1"];
    $params = [];

    $start_date      = $girdi['start_date'] ?? '';
    $end_date        = $girdi['end_date'] ?? '';
    $kullanici_id    = $girdi['kullanici_id'] ?? '';
    $cari_id         = $girdi['cari_id'] ?? '';
    $taraf_tipi      = $girdi['taraf_tipi'] ?? '';
    $tip_id          = $girdi['tip_id'] ?? '';
    $yontem_id       = $girdi['yontem_id'] ?? '';
    $kasa_id         = $girdi['kasa_id'] ?? '';
    $banka_hesap_id  = $girdi['banka_hesap_id'] ?? '';
    $search          = $girdi['search'] ?? '';

    if ($start_date) {
        $whereConditions[] = "odeme_hareket_tarih >= ?";
        $params[] = $start_date;
    }

    if ($end_date) {
        $whereConditions[] = "odeme_hareket_tarih <= ?";
        $params[] = $end_date;
    }

    if ($kullanici_id) {
        $whereConditions[] = "kullanici_id = ?";
        $params[] = $kullanici_id;
    }

    if ($cari_id) {
        $whereConditions[] = "cari_id = ?";
        $params[] = $cari_id;
    }

    if ($taraf_tipi === 'PERSONEL' || $taraf_tipi === 'CARI') {
        $whereConditions[] = "taraf_tipi = ?";
        $params[] = $taraf_tipi;
    }

    if ($tip_id) {
        $whereConditions[] = "odeme_tip_id = ?";
        $params[] = $tip_id;
    }

    if ($yontem_id) {
        $whereConditions[] = "odeme_yontem_id = ?";
        $params[] = $yontem_id;
    }

    if ($kasa_id) {
        $whereConditions[] = "kasa_id = ?";
        $params[] = $kasa_id;
    }

    if ($banka_hesap_id) {
        $whereConditions[] = "bankaHesap_id = ?";
        $params[] = $banka_hesap_id;
    }

    if ($search) {
        $whereConditions[] = "(taraf_adi LIKE ? OR odeme_hareket_aciklama LIKE ? OR odeme_hareket_belge_no LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    return [implode(" AND ", $whereConditions), $params];
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    // Sayfa yetki kontrolü (AJAX için)
    $currentPageFile = basename($_SERVER['PHP_SELF']);
    $permissions = PageAuth::checkPagePermissions(
        $user['kullanici_id'],
        $user['departman_id'],
        $currentPageFile
    );
    
    try {
        switch ($action) {
            case 'stats':
                $stats = $db->fetchOne("
                    SELECT 
                        COUNT(*) as toplam,
                        SUM(CASE WHEN odeme_tip_isaret = 1 THEN odeme_hareket_tutar ELSE 0 END) as toplam_gelir,
                        SUM(CASE WHEN odeme_tip_isaret = -1 THEN odeme_hareket_tutar ELSE 0 END) as toplam_gider,
                        SUM(odeme_hareket_tutar * odeme_tip_isaret) as net_bakiye
                    FROM vw_Odeme_Hareketleri_Detay
                ");
                echo json_encode(['success' => true, 'data' => $stats]);
                exit;
                
            case 'list':
                // Filtreler (Excel dışa aktarma ile ortak)
                list($whereClause, $params) = odemeHareketFiltresi($_POST);

                $list = $db->fetchAll("
                    SELECT 
                        odeme_hareket_id,
                        CONVERT(VARCHAR(10), odeme_hareket_tarih, 120) as odeme_hareket_tarih,
                        odeme_hareket_tutar,
                        odeme_hareket_aciklama,
                        odeme_hareket_evrak,
                        odeme_hareket_belge_no,
                        odeme_tip_id,
                        odeme_tip_adi,
                        odeme_tip_isaret,
                        odeme_tip_tipi,
                        odeme_hareket_net_tutar,
                        odeme_yontem_id,
                        odeme_yontem_adi,
                        odeme_yontem_kod,
                        odeme_yontem_renk,
                        odeme_yontem_ikon,
                        odeme_yontem_hedef_tipi,
                        kasa_id,
                        kasa_adi,
                        kasa_renk,
                        bankaHesap_id,
                        bankaHesap_iban,
                        bankaHesap_no,
                        banka_adi,
                        taraf_tipi,
                        taraf_adi,
                        kullanici_id,
                        personel_tam_adi,
                        cari_id,
                        cari_adi,
                        departman_adi,
                        firma_adi
                    FROM vw_Odeme_Hareketleri_Detay
                    WHERE $whereClause
                    ORDER BY odeme_hareket_tarih DESC, odeme_hareket_id DESC
                ", $params);
                
                echo json_encode(['success' => true, 'data' => $list]);
                exit;

            case 'export':
                // Filtrelenmiş listeyi Excel (XLSX) olarak indir
                if (!$permissions['has_access']) {
                    echo json_encode(['success' => false, 'message' => 'Bu sayfaya erişim yetkiniz yok!']);
                    exit;
                }

                require_once __DIR__ . '/../includes/XlsxYazici.php';

                list($whereClause, $params) = odemeHareketFiltresi($_POST);

                $kayitlar = $db->fetchAll("
                    SELECT
                        CONVERT(VARCHAR(10), odeme_hareket_tarih, 120) as odeme_hareket_tarih,
                        taraf_tipi,
                        taraf_adi,
                        personel_tam_adi,
                        cari_adi,
                        departman_adi,
                        odeme_tip_adi,
                        odeme_tip_isaret,
                        odeme_yontem_adi,
                        kasa_adi,
                        banka_adi,
                        bankaHesap_iban,
                        odeme_hareket_tutar,
                        odeme_hareket_net_tutar,
                        odeme_hareket_belge_no,
                        odeme_hareket_aciklama
                    FROM vw_Odeme_Hareketleri_Detay
                    WHERE $whereClause
                    ORDER BY odeme_hareket_tarih DESC, odeme_hareket_id DESC
                ", $params);

                $xlsx = new XlsxYazici('Ödeme Hareketleri');
                $xlsx->basliklar([
                    'Tarih',
                    'Taraf Tipi',
                    'Taraf',
                    'Departman',
                    'Ödeme Tipi',
                    'Yön',
                    'Ödeme Yöntemi',
                    'Kasa',
                    'Banka',
                    'IBAN',
                    'Tutar',
                    'Net Tutar',
                    'Belge No',
                    'Açıklama',
                ]);

                foreach ($kayitlar as $k) {
                    $cari = ($k['taraf_tipi'] === 'CARI');

                    $xlsx->satir([
                        ['deger' => $k['odeme_hareket_tarih'], 'tur' => 'tarih'],
                        $cari ? 'Cari' : 'Personel',
                        ($cari ? $k['cari_adi'] : $k['personel_tam_adi']) ?: $k['taraf_adi'],
                        $cari ? '' : $k['departman_adi'],
                        $k['odeme_tip_adi'],
                        ($k['odeme_tip_isaret'] == 1) ? 'Gelir' : 'Gider',
                        $k['odeme_yontem_adi'],
                        $k['kasa_adi'],
                        $k['banka_adi'],
                        $k['bankaHesap_iban'],
                        ['deger' => $k['odeme_hareket_tutar'], 'tur' => 'para'],
                        ['deger' => $k['odeme_hareket_net_tutar'], 'tur' => 'para'],
                        $k['odeme_hareket_belge_no'],
                        $k['odeme_hareket_aciklama'],
                    ]);
                }

                $xlsx->indir('odeme-hareketleri-' . date('Y-m-d-Hi') . '.xlsx');
                exit;

            case 'delete':
                // Yetki kontrolü (Admin bypass)
                if ($user['departman_id'] != 1 && !$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    exit;
                }
                
                $id = $_POST['id'] ?? 0;
                
                // Soft delete (durum = 0)
                $result = $db->update('Odeme_Hareketleri', 
                    ['odeme_hareket_durum' => 0, 'odeme_hareket_guncelleme_tarihi' => date('Y-m-d H:i:s')], 
                    ['odeme_hareket_id' => $id]
                );
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Ödeme hareketi başarıyla silindi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Silme işlemi başarısız']);
                }
                exit;
                
            case 'get_personeller':
                $personeller = $db->fetchAll("
                    SELECT 
                        kullanici_id,
                        kullanici_ad + ' ' + kullanici_soyad + CASE WHEN kullanici_durum = 0 THEN ' (Pasif)' ELSE '' END as tam_adi,
                        departman_id,
                        kullanici_durum
                    FROM kullanicilar k
                    LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
                    WHERE d.departman_personel = 1
                    ORDER BY kullanici_durum DESC, kullanici_ad, kullanici_soyad
                ");
                echo json_encode(['success' => true, 'data' => $personeller]);
                exit;
                
            case 'get_cariler':
                $cariler = $db->fetchAll("
                    SELECT
                        cari_id,
                        cari_adi + CASE WHEN cari_aktif = 0 THEN ' (Pasif)' ELSE '' END as cari_adi,
                        cari_musteri,
                        cari_tedarikci,
                        cari_aktif
                    FROM Cari
                    ORDER BY cari_aktif DESC, cari_adi
                ");
                echo json_encode(['success' => true, 'data' => $cariler]);
                exit;

            case 'get_odeme_tipleri':
                $tipler = $db->fetchAll("
                    SELECT 
                        odeme_tip_id,
                        odeme_tip_adi,
                        odeme_tip_isaret
                    FROM Odeme_Tipleri
                    WHERE odeme_tip_durum = 1
                    ORDER BY odeme_tip_sira, odeme_tip_adi
                ");
                echo json_encode(['success' => true, 'data' => $tipler]);
                exit;
                
            case 'get_odeme_yontemleri':
                $yontemler = $db->fetchAll("
                    SELECT
                        odeme_yontem_id,
                        odeme_yontem_adi,
                        odeme_yontem_kod
                    FROM tanim_odeme_yontemleri
                    WHERE odeme_yontem_durum = 1
                    ORDER BY ISNULL(odeme_yontem_sira, 999), odeme_yontem_adi
                ");
                echo json_encode(['success' => true, 'data' => $yontemler]);
                exit;

            case 'get_kasalar':
                $kasalar = $db->fetchAll("
                    SELECT
                        kasa_id,
                        kasa_adi
                    FROM Kasa
                    WHERE kasa_durum = 1
                    ORDER BY ISNULL(kasa_sira_no, 999), kasa_adi
                ");
                echo json_encode(['success' => true, 'data' => $kasalar]);
                exit;

            case 'get_banka_hesaplari':
                $hesaplar = $db->fetchAll("
                    SELECT
                        h.bankaHesap_id,
                        b.banka_adi + ' - ' + ISNULL(NULLIF(h.bankaHesap_iban, ''), ISNULL(h.bankaHesap_no, '')) as hesap_adi
                    FROM Banka_Hesap h
                    INNER JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                    WHERE h.bankaHesap_durum = 1
                    ORDER BY b.banka_adi, h.bankaHesap_iban
                ");
                echo json_encode(['success' => true, 'data' => $hesaplar]);
                exit;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
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

            <!-- Ana İçerik -->
            <div class="app-content">
                <div class="container-fluid">
        
        <!-- Info Boxes -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="info-box text-bg-primary">
                    <span class="info-box-icon"><i class="bi bi-list-ul"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Hareket</span>
                        <span class="info-box-number" id="stat_toplam">0</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="info-box text-bg-success">
                    <span class="info-box-icon"><i class="bi bi-arrow-up-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Gelir</span>
                        <span class="info-box-number" id="stat_gelir">₺0,00</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="info-box text-bg-danger">
                    <span class="info-box-icon"><i class="bi bi-arrow-down-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Gider</span>
                        <span class="info-box-number" id="stat_gider">₺0,00</span>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="info-box text-bg-info">
                    <span class="info-box-icon"><i class="bi bi-wallet2"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net Bakiye</span>
                        <span class="info-box-number" id="stat_net">₺0,00</span>
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
                        <!-- Tarih Aralığı -->
                        <div class="col-md-2">
                            <label class="form-label">Başlangıç Tarihi</label>
                            <input type="date" class="form-control" name="start_date" id="filter_start_date">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Bitiş Tarihi</label>
                            <input type="date" class="form-control" name="end_date" id="filter_end_date">
                        </div>
                        
                        <!-- Taraf Tipi -->
                        <div class="col-md-2">
                            <label class="form-label">Taraf Tipi</label>
                            <select class="form-select" name="taraf_tipi" id="filter_taraf_tipi">
                                <option value="">Tümü</option>
                                <option value="PERSONEL">Personel</option>
                                <option value="CARI">Cari</option>
                            </select>
                        </div>

                        <!-- Personel -->
                        <div class="col-md-3">
                            <label class="form-label">Personel</label>
                            <select class="form-select" name="kullanici_id" id="filter_kullanici_id">
                                <option value="">Tümü</option>
                            </select>
                        </div>

                        <!-- Cari -->
                        <div class="col-md-3">
                            <label class="form-label">Cari</label>
                            <select class="form-select" name="cari_id" id="filter_cari_id">
                                <option value="">Tümü</option>
                            </select>
                        </div>

                        <!-- Ödeme Tipi -->
                        <div class="col-md-3">
                            <label class="form-label">Ödeme Tipi</label>
                            <select class="form-select" name="tip_id" id="filter_tip_id">
                                <option value="">Tümü</option>
                            </select>
                        </div>

                        <!-- Ödeme Yöntemi -->
                        <div class="col-md-3">
                            <label class="form-label">Ödeme Yöntemi</label>
                            <select class="form-select" name="yontem_id" id="filter_yontem_id">
                                <option value="">Tümü</option>
                            </select>
                        </div>

                        <!-- Kasa -->
                        <div class="col-md-3">
                            <label class="form-label">Kasa</label>
                            <select class="form-select" name="kasa_id" id="filter_kasa_id">
                                <option value="">Tümü</option>
                            </select>
                        </div>

                        <!-- Banka Hesabı -->
                        <div class="col-md-3">
                            <label class="form-label">Banka Hesabı</label>
                            <select class="form-select" name="banka_hesap_id" id="filter_banka_hesap_id">
                                <option value="">Tümü</option>
                            </select>
                        </div>

                        <!-- Arama -->
                        <div class="col-md-3">
                            <label class="form-label">Ara</label>
                            <input type="text" class="form-control" name="search" id="filter_search" placeholder="Personel/Cari/Açıklama/Belge No...">
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
        
        <!-- Ana İçerik Kartı -->
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">Ödeme Hareketleri Listesi</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-outline-success btn-sm" id="exportBtn">
                        <i class="bi bi-file-earmark-excel"></i> Excel'e Aktar
                    </button>
                    <?php if ($permissions['can_add']): ?>
                    <button type="button" class="btn btn-success btn-sm" id="addBtn">
                        <i class="bi bi-plus-circle"></i> Yeni Ödeme Hareketi Ekle
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <table id="odemeHareketleriTable" class="table table-bordered table-striped table-hover">
                    <thead>
                        <tr>
                            <th width="8%">Tarih</th>
                            <th width="18%">Taraf</th>
                            <th width="13%">Ödeme Tipi</th>
                            <th width="12%">Yöntem</th>
                            <th width="9%">Tutar</th>
                            <th width="9%">Net Tutar</th>
                            <th width="17%">Açıklama</th>
                            <th width="5%">Evrak</th>
                            <th width="9%">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- DataTables ile doldurulacak -->
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php include __DIR__ . '/../includes/scripts.php'; ?>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<!-- Custom JS -->
<script src="/admin/assets/js/custom.js"></script>

<script>
// Filtre state
let currentFilters = {};
let table;
let personelData = [];
let cariData = [];
let odemeTipleriData = [];
let odemeYontemleriData = [];
let kasaData = [];
let bankaHesapData = [];

$(document).ready(function() {
    // İstatistikleri yükle
    loadStats();

    // Dropdown'ları doldur
    loadPersoneller();
    loadCariler();
    loadOdemeTipleri();
    loadOdemeYontemleri();
    loadKasalar();
    loadBankaHesaplari();
    
    // DataTable başlat
    table = $('#odemeHareketleriTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [[0, 'desc']], // Tarihe göre azalan
        pageLength: 25,
        data: [],
        columns: [
            { data: 'odeme_hareket_tarih' },
            { 
                data: null,
                render: function(data) {
                    const isCari = data.taraf_tipi === 'CARI';
                    const badge = isCari
                        ? '<span class="badge bg-secondary">Cari</span>'
                        : '<span class="badge bg-primary">Personel</span>';
                    const ad = (isCari ? data.cari_adi : data.personel_tam_adi) || '';
                    const baslik = (!isCari && data.departman_adi) ? ` title="${data.departman_adi}"` : '';
                    return `${badge} <strong${baslik}>${ad}</strong>`;
                }
            },
            { 
                data: null,
                render: function(data) {
                    const badgeClass = data.odeme_tip_isaret == 1 ? 'bg-success' : 'bg-danger';
                    const icon = data.odeme_tip_isaret == 1 ? 'bi-arrow-up-circle' : 'bi-arrow-down-circle';
                    return `<span class="badge ${badgeClass}"><i class="bi ${icon}"></i> ${data.odeme_tip_adi}</span>`;
                }
            },
            {
                data: null,
                render: function(data) {
                    if (!data.odeme_yontem_adi) return '<span class="text-muted">-</span>';

                    const renk = data.odeme_yontem_renk || '#6c757d';
                    const ikon = data.odeme_yontem_ikon || 'bi-wallet2';
                    let html = `<span class="badge" style="background-color: ${renk}"><i class="bi ${ikon}"></i> ${data.odeme_yontem_adi}</span>`;

                    if (data.kasa_adi) {
                        html += `<br><small class="text-muted"><i class="bi bi-safe"></i> ${data.kasa_adi}</small>`;
                    }
                    if (data.banka_adi) {
                        const hesap = data.bankaHesap_iban || data.bankaHesap_no || '';
                        html += `<br><small class="text-muted"><i class="bi bi-bank"></i> ${data.banka_adi}${hesap ? ' - ' + hesap : ''}</small>`;
                    }
                    if (data.odeme_hareket_belge_no) {
                        html += `<br><small class="text-muted">No: ${data.odeme_hareket_belge_no}</small>`;
                    }
                    return html;
                }
            },
            {
                data: 'odeme_hareket_tutar',
                render: function(data) {
                    return formatCurrency(data);
                }
            },
            { 
                data: 'odeme_hareket_net_tutar',
                render: function(data) {
                    const color = data >= 0 ? 'text-success' : 'text-danger';
                    return `<strong class="${color}">${formatCurrency(data)}</strong>`;
                }
            },
            { 
                data: 'odeme_hareket_aciklama',
                render: function(data) {
                    return data || '-';
                }
            },
            {
                data: 'odeme_hareket_evrak',
                render: function(data) {
                    if (data) {
                        return `<a href="${data}" target="_blank" class="btn btn-sm btn-info" title="Evrakı Görüntüle">
                            <i class="bi bi-file-earmark-pdf"></i>
                        </a>`;
                    }
                    return '-';
                }
            },
            {
                data: null,
                render: function(data) {
                    let buttons = '';
                    
                    if (permissions.canEdit) {
                        buttons += `
                            <button class="btn btn-sm btn-warning" onclick="editRecord(${data.odeme_hareket_id})" title="Düzenle">
                                <i class="bi bi-pencil"></i>
                            </button>
                        `;
                    }
                    
                    if (permissions.canDelete) {
                        buttons += `
                            <button class="btn btn-sm btn-danger" onclick="deleteRecord(${data.odeme_hareket_id})" title="Sil">
                                <i class="bi bi-trash"></i>
                            </button>
                        `;
                    }
                    
                    return buttons || '<span class="text-muted">-</span>';
                }
            }
        ]
    });
    
    // İlk yükleme
    loadList();
    
    // Filtre form submit
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        
        currentFilters = {
            start_date: $('#filter_start_date').val(),
            end_date: $('#filter_end_date').val(),
            taraf_tipi: $('#filter_taraf_tipi').val(),
            kullanici_id: $('#filter_kullanici_id').val(),
            cari_id: $('#filter_cari_id').val(),
            tip_id: $('#filter_tip_id').val(),
            yontem_id: $('#filter_yontem_id').val(),
            kasa_id: $('#filter_kasa_id').val(),
            banka_hesap_id: $('#filter_banka_hesap_id').val(),
            search: $('#filter_search').val()
        };
        
        // Boş değerleri kaldır
        Object.keys(currentFilters).forEach(key => {
            if (!currentFilters[key]) delete currentFilters[key];
        });
        
        loadList();
        loadStats();
        showToast('Filtre uygulandı', 'info');
    });
    
    // Filtreleri temizle
    $('#clearFilters').on('click', function() {
        $('#filterForm')[0].reset();
        $('#filter_taraf_tipi').val('').trigger('change.select2');
        $('#filter_kullanici_id').val('').trigger('change.select2');
        $('#filter_cari_id').val('').trigger('change.select2');
        $('#filter_tip_id').val('').trigger('change.select2');
        $('#filter_yontem_id').val('').trigger('change.select2');
        $('#filter_kasa_id').val('').trigger('change.select2');
        $('#filter_banka_hesap_id').val('').trigger('change.select2');
        currentFilters = {};
        loadList();
        loadStats();
        showToast('Filtreler temizlendi', 'info');
    });
    
    // Yeni kayıt butonu
    $('#addBtn').on('click', function() {
        window.location.href = '/admin/odeme-hareket-form';
    });

    // Excel'e aktar (ekranda görünen filtrelerle, sayfalamadan bağımsız tüm sonuçlar)
    $('#exportBtn').on('click', function() {
        if (table.data().count() === 0) {
            showToast('Dışa aktarılacak kayıt bulunmuyor', 'warning');
            return;
        }

        // Gizli form ile POST → tarayıcı dosyayı indirir
        const form = $('<form>', { method: 'POST', action: '' }).appendTo('body');
        $('<input>', { type: 'hidden', name: 'action', value: 'export' }).appendTo(form);

        Object.keys(currentFilters).forEach(key => {
            $('<input>', { type: 'hidden', name: key, value: currentFilters[key] }).appendTo(form);
        });

        form.submit().remove();
        showToast('Excel dosyası hazırlanıyor...', 'info');
    });

    // Taraf tipi filtresini aranabilir yap
    initFilterSelect('#filter_taraf_tipi');

    // Filtrede taraf tipi değişimi (ilgisiz dropdown temizlenir)
    $('#filter_taraf_tipi').on('change', function() {
        const tip = $(this).val();
        if (tip === 'PERSONEL') {
            $('#filter_cari_id').val('').trigger('change.select2');
        } else if (tip === 'CARI') {
            $('#filter_kullanici_id').val('').trigger('change.select2');
        }
    });
    
});

// Sayfa yetkileri (PHP'den)
const permissions = {
    canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
    canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
    canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
};

// Para formatı
function formatCurrency(value) {
    return new Intl.NumberFormat('tr-TR', {
        style: 'currency',
        currency: 'TRY'
    }).format(value);
}

// Personelleri yükle
function loadPersoneller() {
    $.post('', { action: 'get_personeller' }, function(response) {
        if (response.success) {
            personelData = response.data;
            
            let filterOptions = '<option value="">Tümü</option>';

            response.data.forEach(p => {
                filterOptions += `<option value="${p.kullanici_id}">${p.tam_adi}</option>`;
            });

            $('#filter_kullanici_id').html(filterOptions);
            initFilterSelect('#filter_kullanici_id');
        }
    });
}

// Carileri yükle
function loadCariler() {
    $.post('', { action: 'get_cariler' }, function(response) {
        if (response.success) {
            cariData = response.data;

            let filterOptions = '<option value="">Tümü</option>';

            response.data.forEach(c => {
                filterOptions += `<option value="${c.cari_id}">${c.cari_adi}</option>`;
            });

            $('#filter_cari_id').html(filterOptions);
            initFilterSelect('#filter_cari_id');
        }
    });
}

// Filtre dropdown'ını aranabilir yap (option'lar AJAX ile dolduktan sonra)
function initFilterSelect(selector) {
    const $el = $(selector);
    if ($el.hasClass('select2-hidden-accessible')) {
        $el.select2('destroy');
    }
    $el.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Tümü',
        allowClear: true,
        language: {
            noResults: function() { return "Sonuç bulunamadı"; },
            searching: function() { return "Aranıyor..."; }
        }
    });
}

// Ödeme tiplerini yükle
function loadOdemeTipleri() {
    $.post('', { action: 'get_odeme_tipleri' }, function(response) {
        if (response.success) {
            odemeTipleriData = response.data;
            
            let filterOptions = '<option value="">Tümü</option>';

            response.data.forEach(t => {
                const badge = t.odeme_tip_isaret == 1 ? '(Gelir +)' : '(Gider -)';
                filterOptions += `<option value="${t.odeme_tip_id}">${t.odeme_tip_adi} ${badge}</option>`;
            });

            $('#filter_tip_id').html(filterOptions);
            initFilterSelect('#filter_tip_id');
        }
    });
}

// Ödeme yöntemlerini yükle
function loadOdemeYontemleri() {
    $.post('', { action: 'get_odeme_yontemleri' }, function(response) {
        if (response.success) {
            odemeYontemleriData = response.data;

            let filterOptions = '<option value="">Tümü</option>';

            response.data.forEach(y => {
                filterOptions += `<option value="${y.odeme_yontem_id}">${y.odeme_yontem_adi}</option>`;
            });

            $('#filter_yontem_id').html(filterOptions);
            initFilterSelect('#filter_yontem_id');
        }
    });
}

// Kasaları yükle
function loadKasalar() {
    $.post('', { action: 'get_kasalar' }, function(response) {
        if (response.success) {
            kasaData = response.data;

            let filterOptions = '<option value="">Tümü</option>';

            response.data.forEach(k => {
                filterOptions += `<option value="${k.kasa_id}">${k.kasa_adi}</option>`;
            });

            $('#filter_kasa_id').html(filterOptions);
            initFilterSelect('#filter_kasa_id');
        }
    });
}

// Banka hesaplarını yükle
function loadBankaHesaplari() {
    $.post('', { action: 'get_banka_hesaplari' }, function(response) {
        if (response.success) {
            bankaHesapData = response.data;

            let filterOptions = '<option value="">Tümü</option>';

            response.data.forEach(h => {
                filterOptions += `<option value="${h.bankaHesap_id}">${h.hesap_adi}</option>`;
            });

            $('#filter_banka_hesap_id').html(filterOptions);
            initFilterSelect('#filter_banka_hesap_id');
        }
    });
}

// İstatistikleri yükle
function loadStats() {
    $.post('', { action: 'stats' }, function(response) {
        if (response.success) {
            $('#stat_toplam').text(response.data.toplam || 0);
            $('#stat_gelir').text(formatCurrency(response.data.toplam_gelir || 0));
            $('#stat_gider').text(formatCurrency(response.data.toplam_gider || 0));
            $('#stat_net').text(formatCurrency(response.data.net_bakiye || 0));
        }
    });
}

// Liste yükle
function loadList() {
    $.post('', { 
        action: 'list',
        ...currentFilters
    }, function(response) {
        console.log('AJAX Response:', response);
        if (response.success) {
            console.log('Data count:', response.data.length);
            table.clear();
            table.rows.add(response.data);
            table.draw();
        } else {
            showToast(response.message, 'error');
        }
    }).fail(function(xhr, status, error) {
        console.error('AJAX Error:', error);
        console.error('Response Text:', xhr.responseText);
        showToast('Veri yüklenirken hata oluştu: ' + error, 'error');
    });
}

// Düzenle (form sayfasına git)
function editRecord(id) {
    window.location.href = '/admin/odeme-hareket-form?id=' + id;
}

// Sil
function deleteRecord(id) {
    confirmAction(
        'Bu ödeme hareketini silmek istediğinize emin misiniz?',
        'Bu işlem geri alınamaz!',
        function() {
            $.post('', { action: 'delete', id: id }, function(response) {
                if (response.success) {
                    showSuccess('Silindi!', response.message);
                    loadList();
                    loadStats();
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

</div>
</body>
</html>
