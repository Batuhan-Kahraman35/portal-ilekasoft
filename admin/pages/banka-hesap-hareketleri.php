<?php
/**
 * Admin Panel - Banka Hesap Hareketleri
 * Banka hesap hareketlerinin listelenmesi ve yönetimi
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

// YETKİ: firma_gor açıksa kullanıcı, şubesine bağlı firmanın kayıtlarıyla kısıtlanır
// (Administrator departmanı kısıtlamadan muaftır)
$kisitFirmaId = null;
$firmaKisitliMi = false;

if (!$pagePermissions['is_admin'] && $pagePermissions['can_view_firma']) {
    $firmaKisitliMi = true;
    $kullaniciFirma = $db->fetchOne("
        SELECT s.sube_firma_id
        FROM kullanicilar k
        LEFT JOIN Subeler s ON k.kullanici_sube_id = s.sube_id
        WHERE k.kullanici_id = ?
    ", [$user['kullanici_id']]);

    $kisitFirmaId = $kullaniciFirma['sube_firma_id'] ?? null;
}

// Kısıt aktif ama firma çözülemediyse hiçbir kayıt gösterilmez
$firmaKisitSql = '';
$firmaKisitParams = [];
if ($firmaKisitliMi) {
    if ($kisitFirmaId) {
        $firmaKisitSql = " AND bh.bankaHesap_firma_id = ?";
        $firmaKisitParams[] = $kisitFirmaId;
    } else {
        $firmaKisitSql = " AND 1 = 0";
    }
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Banka Hesap Hareketleri';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// IBAN listesini çek (dropdown için)
$ibanlar = $db->fetchAll("
    SELECT DISTINCT h.banka_HesapHareketleriIBAN as iban
    FROM banka_HesapHareketleri h
    LEFT JOIN banka_Hesap bh ON h.banka_HesapId = bh.bankaHesap_id
    WHERE h.banka_HesapHareketleriIBAN IS NOT NULL
    $firmaKisitSql
    ORDER BY h.banka_HesapHareketleriIBAN
", $firmaKisitParams);

// Bankalar listesini çek (filtre dropdown için)
$bankalar = $db->fetchAll("
    SELECT banka_id, banka_adi 
    FROM bankalar 
    WHERE banka_durum = 1 
    ORDER BY banka_adi
");

// Firma listesini çek (filtre dropdown için) - kısıt varsa sadece kendi firması
if ($firmaKisitliMi) {
    $firmalar = $kisitFirmaId
        ? $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_id = ?", [$kisitFirmaId])
        : [];
} else {
    $firmalar = $db->fetchAll("
        SELECT firma_id, firma_adi
        FROM Firmalar
        WHERE firma_durum = 1
        ORDER BY firma_adi
    ");
}

// Hesap No listesini çek (banka bilgisiyle birlikte, filtre dropdown için)
$hesapNolar = $db->fetchAll("
    SELECT bh.bankaHesap_no, bh.bankaHesap_banka_id, b.banka_adi
    FROM banka_Hesap bh
    LEFT JOIN bankalar b ON bh.bankaHesap_banka_id = b.banka_id
    WHERE bh.bankaHesap_no IS NOT NULL AND LTRIM(RTRIM(bh.bankaHesap_no)) != ''
    $firmaKisitSql
    ORDER BY b.banka_adi, bh.bankaHesap_no
", $firmaKisitParams);

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                // Firma kısıtı ile ortak FROM/WHERE bloğu
                $statsFrom = "FROM banka_HesapHareketleri h
                              LEFT JOIN banka_Hesap bh ON h.banka_HesapId = bh.bankaHesap_id
                              WHERE 1=1" . $firmaKisitSql;

                $sayiSorgu = function($ekWhere = '') use ($db, $statsFrom, $firmaKisitParams) {
                    return $db->fetchOne("SELECT COUNT(*) as sayi $statsFrom $ekWhere", $firmaKisitParams)['sayi'] ?? 0;
                };

                $tutarSorgu = function($ekWhere) use ($db, $statsFrom, $firmaKisitParams) {
                    $sql = "SELECT ISNULL(SUM(CAST(h.banka_HesapHareketleriAmount AS DECIMAL(18,2))), 0) as tutar $statsFrom $ekWhere";
                    return $db->fetchOne($sql, $firmaKisitParams)['tutar'] ?? 0;
                };

                $stats = [
                    'toplam' => $sayiSorgu(),
                    'alacak' => $sayiSorgu("AND h.banka_HesapHareketleriBorcAlacak = 'A'"),
                    'borc' => $sayiSorgu("AND h.banka_HesapHareketleriBorcAlacak = 'B'"),
                    'masraf' => $sayiSorgu("AND h.banka_HesapHareketleriCost = 1"),
                    'toplam_alacak' => $tutarSorgu("AND h.banka_HesapHareketleriBorcAlacak = 'A'"),
                    'toplam_borc' => $tutarSorgu("AND h.banka_HesapHareketleriBorcAlacak = 'B'")
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $search = $_POST['search'] ?? '';
                $iban = $_POST['iban'] ?? '';
                $borcAlacak = $_POST['borc_alacak'] ?? '';
                $currencyType = $_POST['currency_type'] ?? '';
                $isCost = $_POST['is_cost'] ?? '';
                $bankaId = $_POST['banka_id'] ?? '';
                $firmaId = $_POST['firma_id'] ?? '';
                $hesapNo = $_POST['hesap_no'] ?? '';
                
                // SQL sorgusu oluştur (LEFT JOIN ile banka ve firma bilgisi)
                $sql = "SELECT 
                    h.banka_HesapHareketleriID,
                    h.banka_HesapHareketleriIBAN,
                    h.banka_HesapHareketleriName,
                    CONVERT(VARCHAR(19), h.banka_HesapHareketleriDateTime, 120) as banka_HesapHareketleriDateTime,
                    h.banka_HesapHareketleriAmount,
                    h.banka_HesapHareketleriDescription,
                    h.banka_HesapHareketleriBorcAlacak,
                    h.banka_HesapHareketleriCurrencyType,
                    h.banka_HesapHareketleriRemainingBalance,
                    h.banka_HesapHareketleriIdentifier,
                    h.ReceiptNo,
                    CONVERT(VARCHAR(19), h.banka_HesapHareketleriAddedDateTime, 120) as banka_HesapHareketleriAddedDateTime,
                    h.VknOrTc,
                    h.banka_HesapHareketleriCost,
                    h.banka_HesapId,
                    h.banka_HesapHareketleriDekontYolu,
                    bh.bankaHesap_iban as hesap_iban,
                    bh.bankaHesap_aciklama as hesap_aciklama,
                    bh.bankaHesap_no as hesap_no,
                    b.banka_adi,
                    b.banka_logo_url,
                    b.banka_id as banka_id,
                    f.firma_adi
                FROM banka_HesapHareketleri h
                LEFT JOIN banka_Hesap bh ON h.banka_HesapId = bh.bankaHesap_id
                LEFT JOIN bankalar b ON bh.bankaHesap_banka_id = b.banka_id
                LEFT JOIN Firmalar f ON bh.bankaHesap_firma_id = f.firma_id
                WHERE 1=1";
                $params = [];

                // YETKİ: Firma bazlı kısıtlama (kullanıcının şubesine bağlı firma)
                if ($firmaKisitliMi) {
                    $sql .= $firmaKisitSql;
                    $params = array_merge($params, $firmaKisitParams);

                    // Kısıt SQL'e eklendi; kullanıcıdan gelen firma seçimi yok sayılır
                    $firmaId = '';
                }

                // Tarih filtresi
                if ($startDate) {
                    $sql .= " AND CONVERT(date, h.banka_HesapHareketleriDateTime) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, h.banka_HesapHareketleriDateTime) <= ?";
                    $params[] = $endDate;
                }
                
                // IBAN filtresi
                if ($iban) {
                    $sql .= " AND h.banka_HesapHareketleriIBAN = ?";
                    $params[] = $iban;
                }
                
                // Banka filtresi
                if ($bankaId) {
                    $sql .= " AND b.banka_id = ?";
                    $params[] = $bankaId;
                }
                
                // Firma filtresi
                if ($firmaId) {
                    $sql .= " AND bh.bankaHesap_firma_id = ?";
                    $params[] = $firmaId;
                }
                
                // Borç/Alacak filtresi
                if ($borcAlacak) {
                    $sql .= " AND h.banka_HesapHareketleriBorcAlacak = ?";
                    $params[] = $borcAlacak;
                }
                
                // Para birimi filtresi
                if ($currencyType) {
                    $sql .= " AND h.banka_HesapHareketleriCurrencyType = ?";
                    $params[] = $currencyType;
                }
                
                // Masraf filtresi
                if ($isCost !== '') {
                    $sql .= " AND h.banka_HesapHareketleriCost = ?";
                    $params[] = $isCost;
                }
                
                // Hesap No filtresi
                if ($hesapNo) {
                    $sql .= " AND bh.bankaHesap_no = ?";
                    $params[] = $hesapNo;
                }
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (h.banka_HesapHareketleriName LIKE ? OR h.banka_HesapHareketleriDescription LIKE ? OR h.VknOrTc LIKE ? OR h.ReceiptNo LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY h.banka_HesapHareketleriDateTime DESC";
                
                // Hiçbir filtre yoksa sadece son 25 kaydı getir
                $hasFilter = $startDate || $endDate || $search || $iban || $borcAlacak || $currencyType || $isCost !== '' || $bankaId || $firmaId || $hesapNo;
                if (!$hasFilter) {
                    $sql = str_replace('SELECT ', 'SELECT TOP 25 ', $sql);
                }
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("
                    SELECT *,
                        CONVERT(VARCHAR(19), banka_HesapHareketleriDateTime, 120) as banka_HesapHareketleriDateTime,
                        CONVERT(VARCHAR(19), banka_HesapHareketleriAddedDateTime, 120) as banka_HesapHareketleriAddedDateTime
                    FROM banka_HesapHareketleri 
                    WHERE banka_HesapHareketleriID = ?
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
                
                $data = [
                    'banka_HesapHareketleriIBAN' => trim($_POST['iban'] ?? ''),
                    'banka_HesapHareketleriName' => trim($_POST['name'] ?? ''),
                    'banka_HesapHareketleriDateTime' => $_POST['date_time'] ?? null,
                    'banka_HesapHareketleriAmount' => trim($_POST['amount'] ?? ''),
                    'banka_HesapHareketleriDescription' => trim($_POST['description'] ?? ''),
                    'banka_HesapHareketleriBorcAlacak' => $_POST['borc_alacak'] ?? 'A',
                    'banka_HesapHareketleriCurrencyType' => $_POST['currency_type'] ?? 'TRY',
                    'banka_HesapHareketleriRemainingBalance' => trim($_POST['remaining_balance'] ?? ''),
                    'banka_HesapHareketleriIdentifier' => trim($_POST['identifier'] ?? ''),
                    'ReceiptNo' => trim($_POST['receipt_no'] ?? ''),
                    'VknOrTc' => trim($_POST['vkn_or_tc'] ?? ''),
                    'banka_HesapHareketleriCost' => isset($_POST['is_cost']) ? 1 : 0
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }
                    
                    $db->update('banka_HesapHareketleri', $data, ['banka_HesapHareketleriID' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Hareket güncellendi!']);
                } else {
                    // Yeni kayıt
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['banka_HesapHareketleriAddedDateTime'] = date('Y-m-d H:i:s');
                    $db->insert('banka_HesapHareketleri', $data);
                    echo json_encode(['success' => true, 'message' => 'Hareket eklendi!']);
                }
                break;
                
            case 'delete':
                // Sil
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->execute("DELETE FROM banka_HesapHareketleri WHERE banka_HesapHareketleriID = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Hareket silindi!']);
                break;
                
            case 'get_ibanlar':
                // IBAN listesi
                $ibanlar = $db->fetchAll("
                    SELECT DISTINCT banka_HesapHareketleriIBAN as iban 
                    FROM banka_HesapHareketleri 
                    WHERE banka_HesapHareketleriIBAN IS NOT NULL 
                    ORDER BY banka_HesapHareketleriIBAN
                ");
                echo json_encode(['success' => true, 'data' => $ibanlar]);
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
        .badge-alacak { background-color: #198754; color: white; }
        .badge-borc { background-color: #dc3545; color: white; }
        .badge-masraf { background-color: #fd7e14; color: white; }
        .text-amount { font-family: 'Consolas', monospace; font-weight: 600; }
        .text-alacak { color: #198754; }
        .text-borc { color: #dc3545; }
        .iban-text { font-family: 'Consolas', monospace; font-size: 0.85rem; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .info-box { transition: all 0.3s ease; }
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
                                    <span class="info-box-text">Toplam Hareket</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-arrow-down-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Alacak (Gelen)</span>
                                    <span class="info-box-number" id="stat-alacak">0</span>
                                    <small class="text-muted" id="stat-toplam-alacak">₺0</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-arrow-up-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Borç (Giden)</span>
                                    <span class="info-box-number" id="stat-borc">0</span>
                                    <small class="text-muted" id="stat-toplam-borc">₺0</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-cash-coin"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Masraf</span>
                                    <span class="info-box-number" id="stat-masraf">0</span>
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
                                        <label class="form-label">Banka</label>
                                        <select class="form-select" name="banka_id" id="filter_banka_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($bankalar as $banka): ?>
                                            <option value="<?= $banka['banka_id'] ?>"><?= htmlspecialchars($banka['banka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select" name="firma_id" id="filter_firma_id" <?= $firmaKisitliMi ? 'disabled' : '' ?>>
                                            <?php if (!$firmaKisitliMi): ?>
                                            <option value="">Tümü</option>
                                            <?php endif; ?>
                                            <?php foreach ($firmalar as $firma): ?>
                                            <option value="<?= $firma['firma_id'] ?>"><?= htmlspecialchars($firma['firma_adi']) ?></option>
                                            <?php endforeach; ?>
                                            <?php if ($firmaKisitliMi && !$kisitFirmaId): ?>
                                            <option value="">Firma tanımlı değil</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">IBAN</label>
                                        <select class="form-select" name="iban" id="filter_iban">
                                            <option value="">Tümü</option>
                                            <?php foreach ($ibanlar as $ib): ?>
                                            <option value="<?= htmlspecialchars($ib['iban']) ?>"><?= htmlspecialchars($ib['iban']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Hesap No</label>
                                        <select class="form-select" name="hesap_no" id="filter_hesap_no">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Hareket Tipi</label>
                                        <select class="form-select" name="borc_alacak" id="filter_borc_alacak">
                                            <option value="">Tümü</option>
                                            <option value="A">Alacak (Gelen)</option>
                                            <option value="B">Borç (Giden)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Para Birimi</label>
                                        <select class="form-select" name="currency_type" id="filter_currency_type">
                                            <option value="">Tümü</option>
                                            <option value="TRY">TRY</option>
                                            <option value="USD">USD</option>
                                            <option value="EUR">EUR</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Masraf</label>
                                        <select class="form-select" name="is_cost" id="filter_is_cost">
                                            <option value="">Tümü</option>
                                            <option value="1">Evet</option>
                                            <option value="0">Hayır</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="İsim, Açıklama, VKN/TC...">
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
                    
                    <!-- Ana İçerik Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-bank"></i> Hesap Hareketleri
                            </h3>
                            <div class="card-tools">
                                
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="dataTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Tarih</th>
                                            <th>Banka</th>
                                            <th>Firma</th>
                                            <th>İsim/Unvan</th>
                                            <th>Tutar</th>
                                            <th>Tip</th>
                                            <th>Bakiye</th>
                                            <th>Hesap No</th>
                                            <th>IBAN</th>
                                            <th>Açıklama</th>
                                            <th>Dekont</th>
                                            <th>İşlemler</th>
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
    
    <!-- Ekleme/Düzenleme Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="saveForm">
                    <input type="hidden" name="id" id="form_id">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalTitle"><i class="bi bi-plus-circle"></i> Yeni Hareket</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">IBAN <span class="text-danger">*</span></label>
                                <input type="text" class="form-control iban-text" name="iban" id="form_iban" required placeholder="TR...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">İsim/Unvan <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="form_name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">İşlem Tarihi <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" name="date_time" id="form_date_time" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tutar <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="amount" id="form_amount" required placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Para Birimi</label>
                                <select class="form-select" name="currency_type" id="form_currency_type">
                                    <option value="TRY">TRY</option>
                                    <option value="USD">USD</option>
                                    <option value="EUR">EUR</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Hareket Tipi <span class="text-danger">*</span></label>
                                <select class="form-select" name="borc_alacak" id="form_borc_alacak" required>
                                    <option value="A">Alacak (Gelen)</option>
                                    <option value="B">Borç (Giden)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Kalan Bakiye</label>
                                <input type="text" class="form-control" name="remaining_balance" id="form_remaining_balance" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">VKN/TC</label>
                                <input type="text" class="form-control" name="vkn_or_tc" id="form_vkn_or_tc">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Açıklama</label>
                                <textarea class="form-control" name="description" id="form_description" rows="2"></textarea>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">İşlem Tanımlayıcı</label>
                                <input type="text" class="form-control" name="identifier" id="form_identifier">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Makbuz No</label>
                                <input type="text" class="form-control" name="receipt_no" id="form_receipt_no">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="is_cost" id="form_is_cost">
                                    <label class="form-check-label" for="form_is_cost">
                                        <i class="bi bi-cash-coin text-warning"></i> Masraf/Komisyon
                                    </label>
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
    
    <!-- Detay Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-info-circle"></i> Hareket Detayı</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <!-- AJAX ile doldurulacak -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentFilters = {};
        const addModal = new bootstrap.Modal(document.getElementById('addModal'));
        const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
        
        // Para formatla
        function formatMoney(amount, currency = 'TRY') {
            const num = parseFloat(amount) || 0;
            const symbols = { 'TRY': '₺', 'USD': '$', 'EUR': '€' };
            return symbols[currency] + num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        // Tarih formatla
        function formatDate(dateString) {
            if (!dateString) return '-';
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                });
            } catch (e) { return '-'; }
        }
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-alacak').text(response.data.alacak);
                    $('#stat-borc').text(response.data.borc);
                    $('#stat-masraf').text(response.data.masraf);
                    $('#stat-toplam-alacak').text(formatMoney(response.data.toplam_alacak));
                    $('#stat-toplam-borc').text(formatMoney(response.data.toplam_borc));
                }
            });
        }
        
        // DataTable başlat
        function initDataTable() {
            table = $('#dataTable').DataTable({
                processing: true,
                serverSide: false,
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        d.action = 'list';
                        Object.assign(d, currentFilters);
                    },
                    dataSrc: function(json) {
                        return json.data || [];
                    }
                },
                columns: [
                    { data: 'banka_HesapHareketleriID' },
                    { 
                        data: 'banka_HesapHareketleriDateTime',
                        render: function(data, type) { 
                            // Sıralama için raw datetime, görüntüleme için formatlanmış
                            if (type === 'sort' || type === 'type') {
                                return data;
                            }
                            return formatDate(data); 
                        }
                    },
                    { 
                        data: 'banka_adi',
                        render: function(data, type, row) {
                            if (row.banka_logo_url) {
                                return '<img src="' + row.banka_logo_url + '" alt="' + (data || '') + '" title="' + (data || '') + '" style="max-height:28px; max-width:80px; object-fit:contain;">';
                            }
                            return data ? '<span class="badge bg-secondary">' + data + '</span>' : '<span class="text-muted">-</span>';
                        }
                    },
                    { 
                        data: 'firma_adi',
                        render: function(data) {
                            return data || '<span class="text-muted">-</span>';
                        }
                    },
                    { 
                        data: 'banka_HesapHareketleriName',
                        render: function(data, type, row) {
                            let html = '<strong>' + (data || '-') + '</strong>';
                            if (row.VknOrTc && row.VknOrTc !== '-') {
                                html += '<br><small class="text-muted">VKN/TC: ' + row.VknOrTc + '</small>';
                            }
                            return html;
                        }
                    },
                    { 
                        data: 'banka_HesapHareketleriAmount',
                        render: function(data, type, row) {
                            const isAlacak = row.banka_HesapHareketleriBorcAlacak === 'A';
                            const cls = isAlacak ? 'text-alacak' : 'text-borc';
                            const prefix = isAlacak ? '+' : '-';
                            return '<span class="text-amount ' + cls + '">' + prefix + formatMoney(data, row.banka_HesapHareketleriCurrencyType) + '</span>';
                        }
                    },
                    { 
                        data: 'banka_HesapHareketleriBorcAlacak',
                        render: function(data, type, row) {
                            let badge = data === 'A' 
                                ? '<span class="badge badge-alacak">Alacak</span>' 
                                : '<span class="badge badge-borc">Borç</span>';
                            if (row.banka_HesapHareketleriCost == 1) {
                                badge += ' <span class="badge badge-masraf">Masraf</span>';
                            }
                            return badge;
                        }
                    },
                    { 
                        data: 'banka_HesapHareketleriRemainingBalance',
                        render: function(data, type, row) {
                            return '<span class="text-amount">' + formatMoney(data, row.banka_HesapHareketleriCurrencyType) + '</span>';
                        }
                    },
                    { 
                        data: 'hesap_no',
                        render: function(data) {
                            return data ? '<span class="iban-text">' + data + '</span>' : '<span class="text-muted">-</span>';
                        }
                    },
                    { 
                        data: 'banka_HesapHareketleriIBAN',
                        render: function(data) {
                            if (!data) return '-';
                            // IBAN'ı kısalt
                            return '<span class="iban-text" title="' + data + '">' + data.substring(0, 10) + '...</span>';
                        }
                    },
                    { 
                        data: 'banka_HesapHareketleriDescription',
                        render: function(data) {
                            if (!data) return '-';
                            // Açıklamayı kısalt
                            const short = data.length > 20 ? data.substring(0, 20) + '...' : data;
                            return '<span title="' + data.replace(/"/g, '&quot;') + '">' + short + '</span>';
                        }
                    },
                    {
                        data: 'banka_HesapHareketleriDekontYolu',
                        orderable: false,
                        className: 'text-center',
                        render: function(data, type, row) {
                            if (data) {
                                return '<a href="../../' + data + '" target="_blank" class="btn btn-sm btn-outline-danger" title="Dekont PDF"><i class="bi bi-file-earmark-pdf"></i></a>';
                            }
                            return '<span class="text-muted">-</span>';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        render: function(data, type, row) {
                            let buttons = '<div class="btn-group btn-group-sm">';
                            buttons += '<button class="btn btn-info btn-sm" onclick="showDetail(' + row.banka_HesapHareketleriID + ')" title="Detay"><i class="bi bi-eye"></i></button>';
                            <?php if ($pagePermissions['can_delete']): ?>
                            buttons += '<button class="btn btn-danger btn-sm" onclick="deleteRecord(' + row.banka_HesapHareketleriID + ')" title="Sil"><i class="bi bi-trash"></i></button>';
                            <?php endif; ?>
                            buttons += '</div>';
                            return buttons;
                        }
                    }
                ],
                order: [[1, 'desc']],
                pageLength: 25,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                }
            });
        }
        
        // Detay göster
        function showDetail(id) {
            $.post('', { action: 'get', id: id }, function(response) {
                if (response.success && response.data) {
                    const d = response.data;
                    const isAlacak = d.banka_HesapHareketleriBorcAlacak === 'A';
                    
                    let html = '<div class="row">';
                    html += '<div class="col-md-6"><strong>IBAN:</strong><br><span class="iban-text">' + (d.banka_HesapHareketleriIBAN || '-') + '</span></div>';
                    html += '<div class="col-md-6"><strong>İsim/Unvan:</strong><br>' + (d.banka_HesapHareketleriName || '-') + '</div>';
                    html += '</div><hr>';
                    
                    html += '<div class="row">';
                    html += '<div class="col-md-4"><strong>İşlem Tarihi:</strong><br>' + formatDate(d.banka_HesapHareketleriDateTime) + '</div>';
                    html += '<div class="col-md-4"><strong>Tutar:</strong><br><span class="text-amount ' + (isAlacak ? 'text-alacak' : 'text-borc') + '">' + (isAlacak ? '+' : '-') + formatMoney(d.banka_HesapHareketleriAmount, d.banka_HesapHareketleriCurrencyType) + '</span></div>';
                    html += '<div class="col-md-4"><strong>Kalan Bakiye:</strong><br><span class="text-amount">' + formatMoney(d.banka_HesapHareketleriRemainingBalance, d.banka_HesapHareketleriCurrencyType) + '</span></div>';
                    html += '</div><hr>';
                    
                    html += '<div class="row">';
                    html += '<div class="col-md-4"><strong>Hareket Tipi:</strong><br>' + (isAlacak ? '<span class="badge badge-alacak">Alacak</span>' : '<span class="badge badge-borc">Borç</span>');
                    if (d.banka_HesapHareketleriCost == 1) html += ' <span class="badge badge-masraf">Masraf</span>';
                    html += '</div>';
                    html += '<div class="col-md-4"><strong>Para Birimi:</strong><br>' + (d.banka_HesapHareketleriCurrencyType || 'TRY') + '</div>';
                    html += '<div class="col-md-4"><strong>VKN/TC:</strong><br>' + (d.VknOrTc || '-') + '</div>';
                    html += '</div><hr>';
                    
                    html += '<div class="row">';
                    html += '<div class="col-12"><strong>Açıklama:</strong><br>' + (d.banka_HesapHareketleriDescription || '-') + '</div>';
                    html += '</div><hr>';
                    
                    html += '<div class="row">';
                    html += '<div class="col-md-6"><strong>İşlem Tanımlayıcı:</strong><br><small>' + (d.banka_HesapHareketleriIdentifier || '-') + '</small></div>';
                    html += '<div class="col-md-6"><strong>Makbuz No:</strong><br><small>' + (d.ReceiptNo || '-') + '</small></div>';
                    html += '</div><hr>';
                    
                    if (d.banka_HesapHareketleriDekontYolu) {
                        html += '<div class="row">';
                        html += '<div class="col-12"><strong>Dekont:</strong> <a href="../../' + d.banka_HesapHareketleriDekontYolu + '" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>Dekont Görüntüle</a></div>';
                        html += '</div><hr>';
                    }
                    
                    html += '<div class="row">';
                    html += '<div class="col-12"><strong>Eklenme Tarihi:</strong> ' + formatDate(d.banka_HesapHareketleriAddedDateTime) + '</div>';
                    html += '</div>';
                    
                    $('#detailContent').html(html);
                    detailModal.show();
                } else {
                    showToast('Kayıt bulunamadı!', 'error');
                }
            });
        }
        
        // Düzenleme
        function editRecord(id) {
            $.post('', { action: 'get', id: id }, function(response) {
                if (response.success && response.data) {
                    const d = response.data;
                    $('#form_id').val(d.banka_HesapHareketleriID);
                    $('#form_iban').val(d.banka_HesapHareketleriIBAN);
                    $('#form_name').val(d.banka_HesapHareketleriName);
                    
                    // Tarih formatını datetime-local için düzenle
                    if (d.banka_HesapHareketleriDateTime) {
                        const dt = d.banka_HesapHareketleriDateTime.replace(' ', 'T').substring(0, 16);
                        $('#form_date_time').val(dt);
                    }
                    
                    $('#form_amount').val(d.banka_HesapHareketleriAmount);
                    $('#form_description').val(d.banka_HesapHareketleriDescription);
                    $('#form_borc_alacak').val(d.banka_HesapHareketleriBorcAlacak);
                    $('#form_currency_type').val(d.banka_HesapHareketleriCurrencyType);
                    $('#form_remaining_balance').val(d.banka_HesapHareketleriRemainingBalance);
                    $('#form_identifier').val(d.banka_HesapHareketleriIdentifier);
                    $('#form_receipt_no').val(d.ReceiptNo);
                    $('#form_vkn_or_tc').val(d.VknOrTc);
                    $('#form_is_cost').prop('checked', d.banka_HesapHareketleriCost == 1);
                    
                    $('#modalTitle').html('<i class="bi bi-pencil"></i> Hareket Düzenle');
                    addModal.show();
                } else {
                    showToast('Kayıt bulunamadı!', 'error');
                }
            });
        }
        
        // Silme
        function deleteRecord(id) {
            confirmAction(
                'Bu hareketi silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, function(response) {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            table.ajax.reload();
                            loadStats();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            );
        }
        
        // Form temizle
        function resetForm() {
            $('#saveForm')[0].reset();
            $('#form_id').val('');
            $('#modalTitle').html('<i class="bi bi-plus-circle"></i> Yeni Hareket');
        }
        
        // Sayfa hazır
        $(document).ready(function() {
            loadStats();
            initDataTable();
            
            // Select2 başlat
            $('#filter_iban').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'IBAN Seçin...',
                allowClear: true
            });
            
            $('#filter_firma_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Firma Seçin...',
                allowClear: true
            });
            
            // Hesap No verisi (PHP'den JS'e)
            const allHesaplar = <?= json_encode(array_values($hesapNolar)) ?>;

            // Hesap No dropdown'ını banka bazında oluştur
            function buildHesapNoOptions(bankaId) {
                const $select = $('#filter_hesap_no');
                const currentVal = $select.val();

                if ($select.hasClass('select2-hidden-accessible')) {
                    $select.select2('destroy');
                }

                $select.empty().append('<option value="">Tümü</option>');

                const filtered = bankaId
                    ? allHesaplar.filter(h => h.bankaHesap_banka_id == bankaId)
                    : allHesaplar;

                if (!bankaId) {
                    // Tümü: banka bazında optgroup
                    const groups = {};
                    filtered.forEach(h => {
                        const g = h.banka_adi || 'Diğer';
                        if (!groups[g]) groups[g] = [];
                        groups[g].push(h);
                    });
                    Object.keys(groups).sort().forEach(bankaAdi => {
                        const $og = $('<optgroup>').attr('label', bankaAdi);
                        groups[bankaAdi].forEach(h => {
                            $og.append($('<option>').val(h.bankaHesap_no).text(h.bankaHesap_no));
                        });
                        $select.append($og);
                    });
                } else {
                    // Seçili banka: düz liste
                    filtered.forEach(h => {
                        $select.append($('<option>').val(h.bankaHesap_no).text(h.bankaHesap_no));
                    });
                }

                // Önceki seçim hâlâ listede varsa koru
                if (currentVal && $select.find('option[value="' + currentVal + '"]').length) {
                    $select.val(currentVal);
                }

                $select.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: 'Hesap No Seçin...',
                    allowClear: true
                });
            }

            // İlk yükleme
            buildHesapNoOptions('');

            // Banka değişince hesap no listesini güncelle
            $('#filter_banka_id').on('change', function() {
                $('#filter_hesap_no').val('').trigger('change.select2');
                buildHesapNoOptions($(this).val());
            });
            
            // Modal açıldığında form temizle
            $('#addModal').on('show.bs.modal', function(e) {
                if (!$('#form_id').val()) {
                    resetForm();
                }
            });
            
            // Modal kapandığında form temizle
            $('#addModal').on('hidden.bs.modal', function() {
                resetForm();
            });
            
            // Form submit
            $('#saveForm').on('submit', function(e) {
                e.preventDefault();
                const formData = $(this).serialize() + '&action=save';
                
                $.post('', formData, function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        addModal.hide();
                        table.ajax.reload();
                        loadStats();
                    } else {
                        showToast(response.message, 'error');
                    }
                });
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                currentFilters = {
                    start_date: $('#filter_start_date').val(),
                    end_date: $('#filter_end_date').val(),
                    search: $('#filter_search').val(),
                    iban: $('#filter_iban').val(),
                    hesap_no: $('#filter_hesap_no').val(),
                    banka_id: $('#filter_banka_id').val(),
                    firma_id: $('#filter_firma_id').val(),
                    borc_alacak: $('#filter_borc_alacak').val(),
                    currency_type: $('#filter_currency_type').val(),
                    is_cost: $('#filter_is_cost').val()
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
                $('#filter_iban').val('').trigger('change.select2');
                $('#filter_banka_id').val('').trigger('change.select2');
                <?php if (!$firmaKisitliMi): ?>
                $('#filter_firma_id').val('').trigger('change.select2');
                <?php endif; ?>
                buildHesapNoOptions(''); // Tümü: optgroup yapısıyla sıfırla
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
    </script>
</body>
</html>
