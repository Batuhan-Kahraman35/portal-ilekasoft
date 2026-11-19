<?php
/**
 * Admin Panel - Banka Hesap Bakiyeleri
 * Banka hesaplarının güncel bakiyelerini listeler
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Banka Hesap Bakiyeleri';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Banka listesini çek (filtre için)
$bankalar = $db->fetchAll("SELECT banka_id, banka_adi FROM bankalar WHERE banka_durum = 1 ORDER BY banka_adi");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $toplamHesap = $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_Hesap WHERE bankaHesap_durum = 1")['sayi'] ?? 0;
                $aktifHesap = $db->fetchOne("SELECT COUNT(*) as sayi FROM banka_Hesap WHERE bankaHesap_durum = 1 AND bankaHesap_otomatik = 1")['sayi'] ?? 0;
                
                // TL toplam bakiye (yeni kolon öncelikli, yoksa eski yöntem)
                $tlBakiye = $db->fetchOne("
                    SELECT ISNULL(SUM(CAST(COALESCE(h.bankaHesap_bakiye, sub.bakiye) AS DECIMAL(18,2))), 0) as toplam
                    FROM banka_Hesap h
                    OUTER APPLY (
                        SELECT TOP 1 banka_HesapHareketleriRemainingBalance as bakiye
                        FROM banka_HesapHareketleri 
                        WHERE banka_HesapId = h.bankaHesap_id 
                          AND banka_HesapHareketleriCurrencyType = 'TRY'
                        ORDER BY banka_HesapHareketleriDateTime DESC
                    ) sub
                    WHERE h.bankaHesap_durum = 1
                      AND (h.bankaHesap_bakiye IS NOT NULL OR sub.bakiye IS NOT NULL)
                ")['toplam'] ?? 0;
                
                // Toplam bloke
                $toplamBloke = $db->fetchOne("
                    SELECT ISNULL(SUM(bankaHesap_bloke), 0) as toplam
                    FROM banka_Hesap WHERE bankaHesap_durum = 1
                ")['toplam'] ?? 0;
                
                // Toplam kullanılabilir bakiye (KMH limiti tanımlı hesaplarda: bakiye - bloke + limit)
                $kullanilabilirSatir = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN bankaHesap_kmhLimit IS NOT NULL
                                        THEN ISNULL(bankaHesap_bakiye, 0) - ISNULL(bankaHesap_bloke, 0) + bankaHesap_kmhLimit
                                        ELSE COALESCE(bankaHesap_kullanilabilirBakiye, bankaHesap_bakiye, 0) END), 0) AS toplam,
                        ISNULL(SUM(bankaHesap_kmhLimit), 0) AS kmh_limit,
                        ISNULL(SUM(CASE WHEN bankaHesap_bakiye < 0 THEN -bankaHesap_bakiye ELSE 0 END), 0) AS kmh_kullanilan
                    FROM banka_Hesap WHERE bankaHesap_durum = 1
                ");
                $toplamKullanilabilir = $kullanilabilirSatir['toplam'] ?? 0;

                $stats = [
                    'toplam_hesap' => $toplamHesap,
                    'toplam_bakiye' => number_format($tlBakiye, 2, ',', '.'),
                    'toplam_bloke' => number_format($toplamBloke, 2, ',', '.'),
                    'toplam_kullanilabilir' => number_format($toplamKullanilabilir, 2, ',', '.'),
                    'kmh_kullanilan' => number_format($kullanilabilirSatir['kmh_kullanilan'] ?? 0, 2, ',', '.'),
                    'kmh_limit' => number_format($kullanilabilirSatir['kmh_limit'] ?? 0, 2, ',', '.')
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $bankaId = $_POST['banka_id'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $search = $_POST['search'] ?? '';
                
                // Ana sorgu - Bakiye önce yeni kolondan, yoksa son hareketten
                $sql = "
                    SELECT 
                        h.bankaHesap_id,
                        h.bankaHesap_iban,
                        h.bankaHesap_no,
                        h.bankaHesap_sube_adi,
                        h.bankaHesap_aciklama,
                        h.bankaHesap_otomatik,
                        h.bankaHesap_durum,
                        h.bankaHesap_bakiye,
                        h.bankaHesap_bloke,
                        h.bankaHesap_kullanilabilirBakiye,
                        h.bankaHesap_kmhLimit,
                        h.bankaHesap_kmhLimitKaynak,
                        CONVERT(VARCHAR(19), h.bankaHesap_kmhLimitTarihi, 120) as bankaHesap_kmhLimitTarihi,
                        CONVERT(VARCHAR(19), h.bankaHesap_sonSenkronTarihi, 120) as bankaHesap_sonSenkronTarihi,
                        b.banka_id,
                        b.banka_adi,
                        b.banka_logo_url,
                        f.firma_adi,
                        gb.guncel_bakiye,
                        CASE
                            WHEN gb.guncel_bakiye IS NULL THEN NULL
                            WHEN h.bankaHesap_kmhLimit IS NOT NULL
                                THEN gb.guncel_bakiye - ISNULL(h.bankaHesap_bloke, 0) + h.bankaHesap_kmhLimit
                            ELSE COALESCE(h.bankaHesap_kullanilabilirBakiye, gb.guncel_bakiye - ISNULL(h.bankaHesap_bloke, 0))
                        END as hesap_kullanilabilir
                    FROM banka_Hesap h
                    INNER JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                    LEFT JOIN Firmalar f ON h.bankaHesap_firma_id = f.firma_id
                    OUTER APPLY (
                        SELECT COALESCE(
                            h.bankaHesap_bakiye,
                            (SELECT TOP 1 CAST(hh.banka_HesapHareketleriRemainingBalance AS DECIMAL(18,2))
                             FROM banka_HesapHareketleri hh
                             WHERE hh.banka_HesapId = h.bankaHesap_id
                             ORDER BY hh.banka_HesapHareketleriDateTime DESC, hh.banka_HesapHareketleriID DESC)
                        ) as guncel_bakiye
                    ) gb
                    WHERE h.bankaHesap_durum = 1
                ";
                $params = [];
                
                // Banka filtresi
                if ($bankaId) {
                    $sql .= " AND b.banka_id = ?";
                    $params[] = $bankaId;
                }
                
                // Durum filtresi
                if ($durum !== '') {
                    $sql .= " AND h.bankaHesap_durum = ?";
                    $params[] = $durum;
                }
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (h.bankaHesap_iban LIKE ? OR h.bankaHesap_no LIKE ? OR h.bankaHesap_aciklama LIKE ? OR b.banka_adi LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY COALESCE(h.bankaHesap_bakiye, 0) DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'kmh_kaydet':
                // KMH limitini kaydet (boş = KMH yok)
                if (!$pagePermissions['can_edit'] && !$pagePermissions['is_admin']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz bulunmamaktadır.']);
                    break;
                }

                $hesapId = (int)($_POST['hesap_id'] ?? 0);
                $limitHam = trim((string)($_POST['kmh_limit'] ?? ''));

                if ($hesapId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz hesap.']);
                    break;
                }

                $limit = null;
                if ($limitHam !== '') {
                    // 1.050.000,00 / 1050000,00 / 1050000.00 biçimlerini kabul et
                    $normal = str_replace([' ', '₺'], '', $limitHam);
                    if (strpos($normal, ',') !== false) {
                        $normal = str_replace(['.', ','], ['', '.'], $normal);
                    } elseif (substr_count($normal, '.') > 1) {
                        $normal = str_replace('.', '', $normal);
                    }
                    if (!is_numeric($normal) || (float)$normal < 0) {
                        echo json_encode(['success' => false, 'message' => 'KMH limiti sıfır veya pozitif bir tutar olmalıdır (örn. 500.000,00).']);
                        break;
                    }
                    $limit = round((float)$normal, 2);
                }

                $mevcut = $db->fetchOne("SELECT bankaHesap_id, bankaHesap_kmhLimitKaynak FROM banka_Hesap WHERE bankaHesap_id = ?", [$hesapId]);
                if (!$mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı.']);
                    break;
                }
                if (($mevcut['bankaHesap_kmhLimitKaynak'] ?? null) === 'API') {
                    echo json_encode(['success' => false, 'message' => 'Bu hesabın KMH limiti bankadan otomatik alınıyor, elle değiştirilemez.']);
                    break;
                }

                $db->execute("
                    UPDATE banka_Hesap SET
                        bankaHesap_kmhLimit = ?,
                        bankaHesap_kmhLimitKaynak = ?,
                        bankaHesap_kmhLimitTarihi = GETDATE(),
                        bankaHesap_guncelleyen_kullanici_id = ?,
                        bankaHesap_guncelleme_tarihi = GETDATE()
                    WHERE bankaHesap_id = ?
                ", [
                    $limit === null ? null : number_format($limit, 2, '.', ''),
                    $limit === null ? null : 'MANUEL',
                    $user['kullanici_id'],
                    $hesapId
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => $limit === null ? 'KMH limiti kaldırıldı.' : 'KMH limiti kaydedildi.'
                ]);
                break;

            case 'sync':
                // Bakiyeleri güncelle - Cron mekanizmasını kullan
                require_once __DIR__ . '/../api/banka/BankaServiceFactory.php';
                
                $syncResults = [];
                $toplamInserted = 0;
                $toplamSkipped = 0;
                $basariliApi = 0;
                $hataliApi = 0;
                
                // Tüm aktif API kimliklerini al
                $apiKimlikleri = \App\Services\Banka\BankaServiceFactory::getAktifApiKimlikleri();
                
                if (empty($apiKimlikleri)) {
                    echo json_encode(['success' => false, 'message' => 'Aktif API kimliği bulunamadı.']);
                    break;
                }
                
                foreach ($apiKimlikleri as $apiKimlik) {
                    $bankaAdi = $apiKimlik['banka_adi'];
                    $apiId = $apiKimlik['apiKimlik_id'];
                    
                    try {
                        $service = \App\Services\Banka\BankaServiceFactory::createByApiKimlik($apiId, null, 'manual');
                        
                        // Hesapları çek
                        $hesapResult = $service->getHesaplar();
                        if (!$hesapResult['success']) {
                            throw new \Exception($hesapResult['message']);
                        }
                        
                        // Tüm hesapları senkronize et
                        $senkronResult = $service->senkronizeTumu();
                        
                        $syncResults[] = [
                            'banka' => $bankaAdi,
                            'hesap_sayisi' => $hesapResult['count'],
                            'inserted' => $senkronResult['summary']['toplam_inserted'] ?? 0,
                            'skipped' => $senkronResult['summary']['toplam_skipped'] ?? 0,
                            'success' => $senkronResult['success']
                        ];
                        
                        $toplamInserted += $senkronResult['summary']['toplam_inserted'] ?? 0;
                        $toplamSkipped += $senkronResult['summary']['toplam_skipped'] ?? 0;
                        $basariliApi++;
                        
                    } catch (\Exception $ex) {
                        $hataliApi++;
                        $syncResults[] = [
                            'banka' => $bankaAdi,
                            'success' => false,
                            'message' => $ex->getMessage()
                        ];
                    }
                }
                
                $message = "$basariliApi banka başarılı, $hataliApi hatalı. Yeni: $toplamInserted, Mevcut: $toplamSkipped";
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'data' => $syncResults,
                    'summary' => [
                        'basarili' => $basariliApi,
                        'hatali' => $hataliApi,
                        'yeni_hareket' => $toplamInserted,
                        'mevcut' => $toplamSkipped
                    ]
                ]);
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
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- AdminLTE CSS -->
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
    <!-- Custom CSS -->
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
        
        /* Bakiye renkleri */
        .bakiye-pozitif { color: #198754; font-weight: 600; }
        .bakiye-negatif { color: #dc3545; font-weight: 600; }
        .bakiye-sifir { color: #6c757d; }

        /* KMH kullanım çubuğu */
        .kmh-progress { height: 4px; min-width: 90px; margin-top: 3px; }
        
        /* Banka logosu */
        .banka-logo {
            width: 24px;
            height: 24px;
            object-fit: contain;
            margin-right: 8px;
        }
        
        /* IBAN formatı */
        .iban-text {
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            letter-spacing: 1px;
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
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-bank"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hesap</span>
                                    <span class="info-box-number" id="stat-toplam-hesap">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-currency-lira"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Bakiye</span>
                                    <span class="info-box-number" id="stat-toplam-bakiye">0,00</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-lock-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Bloke</span>
                                    <span class="info-box-number" id="stat-toplam-bloke">0,00</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-wallet2"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kullanılabilir Bakiye</span>
                                    <span class="info-box-number" id="stat-toplam-kullanilabilir">0,00</span>
                                    <small class="text-muted">KMH limiti dahil</small>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-credit-card-2-back"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">KMH Kullanımı</span>
                                    <span class="info-box-number" id="stat-kmh-kullanilan">0,00</span>
                                    <small class="text-muted">Limit: <span id="stat-kmh-limit">0,00</span> ₺</small>
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
                                        <select class="form-select" id="filter_banka" name="banka_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($bankalar as $banka): ?>
                                                <option value="<?= $banka['banka_id'] ?>"><?= htmlspecialchars($banka['banka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
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
                                        <input type="text" class="form-control" id="filter_search" name="search" placeholder="IBAN, hesap no veya açıklama...">
                                    </div>
                                    
                                    <!-- Butonlar -->
                                    <div class="col-md-3 d-flex align-items-end gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                        <button type="button" class="btn btn-success" onclick="syncBakiyeler()">
                                            <i class="bi bi-cloud-download"></i> Bakiyeleri Güncelle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-wallet2"></i> Hesap Bakiyeleri</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-success" id="btnSyncBakiye" onclick="syncBakiyeler()">
                                    <i class="bi bi-cloud-download"></i> Bakiyeleri Güncelle
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <button type="button" class="btn btn-sm btn-info" onclick="loadStats(); table.ajax.reload();">
                                    <i class="bi bi-arrow-clockwise"></i> Yenile
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th width="50">ID</th>
                                            <th>Kullanılabilir</th>
                                            <th>Banka</th>
                                            <th>Hesap Açıklaması</th>
                                            <th>Firma</th>
                                            <th>Bakiye</th>
                                            <th>Bloke</th>
                                            <th>KMH Limiti</th>
                                            <th>Son Senkron</th>
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
    
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        let table;
        let currentFilters = {};
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam-hesap').text(response.data.toplam_hesap);
                    $('#stat-toplam-bakiye').text(response.data.toplam_bakiye + ' ₺');
                    $('#stat-toplam-bloke').text(response.data.toplam_bloke + ' ₺');
                    $('#stat-toplam-kullanilabilir').text(response.data.toplam_kullanilabilir + ' ₺');
                    $('#stat-kmh-kullanilan').text(response.data.kmh_kullanilan + ' ₺');
                    $('#stat-kmh-limit').text(response.data.kmh_limit);
                }
            });
        }
        
        // Para formatla
        function formatMoney(amount) {
            return new Intl.NumberFormat('tr-TR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(amount);
        }
        
        // Para birimi sembolü
        function getCurrencySymbol(currency) {
            const symbols = {
                'TRY': '₺',
                'TL': '₺',
                'USD': '$',
                'EUR': '€',
                'GBP': '£'
            };
            return symbols[currency] || currency;
        }
        
        // Tarih formatla
        function formatDate(dateString) {
            if (!dateString) return '-';
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return '-';
            }
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
                    { data: 'bankaHesap_id' },
                    {
                        data: 'hesap_kullanilabilir',
                        render: function(data, type, row) {
                            const kullanilabilir = parseFloat(data) || 0;
                            if (type === 'sort' || type === 'type') return kullanilabilir;
                            if (data === null) return '<span class="text-muted">-</span>';
                            let cls = 'bakiye-sifir';
                            if (kullanilabilir > 0) cls = 'bakiye-pozitif';
                            else if (kullanilabilir < 0) cls = 'bakiye-negatif';
                            let html = '<span class="' + cls + '">' + formatMoney(kullanilabilir) + ' ₺</span>';
                            if (row.bankaHesap_kmhLimit !== null) {
                                html += '<br><small class="text-muted">KMH dahil</small>';
                            }
                            return html;
                        }
                    },
                    {
                        data: 'banka_adi',
                        render: function(data, type, row) {
                            return '<strong>' + (data || '-') + '</strong>';
                        }
                    },
                    { 
                        data: 'bankaHesap_aciklama',
                        render: function(data, type, row) {
                            let html = (data || '').trim();
                            if (row.bankaHesap_sube_adi && row.bankaHesap_sube_adi.trim()) {
                                html += html ? '<br>' : '';
                                html += '<small class="text-muted">' + row.bankaHesap_sube_adi.trim() + '</small>';
                            }
                            if (row.bankaHesap_iban) {
                                html += html ? '<br>' : '';
                                html += '<small class="text-info">...' + row.bankaHesap_iban.slice(-4) + '</small>';
                            }
                            return html || '-';
                        }
                    },
                    { 
                        data: 'firma_adi',
                        render: function(data) {
                            return data || '<span class="text-muted">-</span>';
                        }
                    },
                    { 
                        data: 'guncel_bakiye',
                        render: function(data, type, row) {
                            if (data === null) return '<span class="text-muted">-</span>';
                            const bakiye = parseFloat(data) || 0;
                            if (type === 'sort' || type === 'type') return bakiye;
                            let bakiyeClass = 'bakiye-sifir';
                            if (bakiye > 0) bakiyeClass = 'bakiye-pozitif';
                            else if (bakiye < 0) bakiyeClass = 'bakiye-negatif';
                            let html = '<span class="' + bakiyeClass + '">' + formatMoney(bakiye) + ' ₺</span>';

                            // Eksi bakiye: KMH kullanımı
                            if (bakiye < 0) {
                                const limit = row.bankaHesap_kmhLimit !== null ? parseFloat(row.bankaHesap_kmhLimit) : null;
                                html += ' <span class="badge text-bg-warning" title="Kredili Mevduat Hesabı kullanımı">KMH</span>';
                                if (limit && limit > 0) {
                                    const oran = Math.min(100, Math.round(Math.abs(bakiye) / limit * 100));
                                    const renk = oran >= 90 ? 'bg-danger' : (oran >= 70 ? 'bg-warning' : 'bg-info');
                                    html += '<div class="progress kmh-progress" title="Limitin %' + oran + '\'i kullanıldı">'
                                          + '<div class="progress-bar ' + renk + '" style="width:' + oran + '%"></div></div>'
                                          + '<small class="text-muted">%' + oran + ' kullanıldı</small>';
                                } else {
                                    html += '<br><small class="text-danger">KMH limiti girilmemiş</small>';
                                }
                            }
                            return html;
                        }
                    },
                    {
                        data: 'bankaHesap_bloke',
                        render: function(data, type) {
                            const bloke = parseFloat(data) || 0;
                            if (type === 'sort' || type === 'type') return bloke;
                            if (bloke <= 0) return '<span class="text-muted">-</span>';
                            return '<span class="text-danger fw-semibold">' + formatMoney(bloke) + ' ₺</span>';
                        }
                    },
                    {
                        data: 'bankaHesap_kmhLimit',
                        render: function(data, type, row) {
                            const limit = data !== null ? parseFloat(data) : null;
                            if (type === 'sort' || type === 'type') return limit || 0;
                            const kaynak = row.bankaHesap_kmhLimitKaynak;
                            let html = limit !== null
                                ? '<span class="fw-semibold">' + formatMoney(limit) + ' ₺</span>'
                                : '<span class="text-muted">-</span>';
                            if (kaynak === 'API') {
                                html += '<br><span class="badge text-bg-success" title="Bankadan alındı: ' + formatDate(row.bankaHesap_kmhLimitTarihi) + '">'
                                      + '<i class="bi bi-bank"></i> Bankadan</span>';
                                return html;
                            }
                            if (kaynak === 'MANUEL') {
                                html += '<br><span class="badge text-bg-secondary" title="Elle girildi: ' + formatDate(row.bankaHesap_kmhLimitTarihi) + '">'
                                      + '<i class="bi bi-pencil"></i> Elle</span>';
                            }
                            if (permissions.can_edit || permissions.is_admin) {
                                html += ' <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="KMH limitini düzenle"'
                                      + ' onclick="kmhLimitDuzenle(' + row.bankaHesap_id + ', ' + (limit !== null ? limit : 'null') + ')">'
                                      + '<i class="bi bi-pencil-square"></i></button>';
                            }
                            return html;
                        }
                    },
                    {
                        data: 'bankaHesap_sonSenkronTarihi',
                        render: function(data, type) {
                            if (type === 'sort' || type === 'type') return data || '';
                            return formatDate(data);
                        }
                    }
                ],
                order: [[1, 'desc']], // Kullanılabilir Bakiye sütununa göre sırala
                pageLength: 25,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                }
            });
        }
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            currentFilters = {
                banka_id: $('#filter_banka').val(),
                durum: $('#filter_durum').val(),
                search: $('#filter_search').val()
            };
            
            Object.keys(currentFilters).forEach(key => {
                if (currentFilters[key] === '' || currentFilters[key] === null) {
                    delete currentFilters[key];
                }
            });
            
            table.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_banka').val('').trigger('change.select2');
            $('#filter_durum').val('').trigger('change.select2');
            currentFilters = {};
            table.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // KMH limitini düzenle (boş bırakılırsa limit kaldırılır)
        function kmhLimitDuzenle(hesapId, mevcutLimit) {
            Swal.fire({
                title: 'KMH Limiti',
                html: '<small class="text-muted">Bankadan teyit edilen limiti girin. KMH yoksa boş bırakın.</small>',
                input: 'text',
                inputValue: mevcutLimit !== null ? formatMoney(mevcutLimit) : '',
                inputPlaceholder: 'örn. 500.000,00',
                showCancelButton: true,
                confirmButtonText: 'Kaydet',
                cancelButtonText: 'Vazgeç',
                showLoaderOnConfirm: true,
                preConfirm: (deger) => {
                    return $.post('', { action: 'kmh_kaydet', hesap_id: hesapId, kmh_limit: deger })
                        .then(response => {
                            if (!response.success) {
                                Swal.showValidationMessage(response.message || 'Kaydedilemedi');
                            }
                            return response;
                        })
                        .catch(() => Swal.showValidationMessage('Sunucuya bağlanılamadı!'));
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then(result => {
                if (result.isConfirmed && result.value && result.value.success) {
                    showToast(result.value.message, 'success');
                    loadStats();
                    table.ajax.reload(null, false);
                }
            });
        }

        // Bakiyeleri güncelle (senkronizasyon)
        function syncBakiyeler() {
            const btn = document.getElementById('btnSyncBakiye');
            const originalHtml = btn.innerHTML;
            
            // Butonu devre dışı bırak
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Güncelleniyor...';
            
            $.post('', { action: 'sync' }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    loadStats();
                    table.ajax.reload();
                } else {
                    showToast(response.message || 'Senkronizasyon sırasında hata oluştu', 'error');
                }
            }).fail(function() {
                showToast('Sunucuya bağlanılamadı!', 'error');
            }).always(function() {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
        }
        
        // Sayfa yüklendiğinde
        $(document).ready(function() {
            // Select2 başlat
            $('#filter_banka, #filter_durum').select2({
                theme: 'bootstrap-5',
                placeholder: 'Tümü',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // İstatistik yükle
            loadStats();
            
            // DataTable başlat
            initDataTable();
        });
    </script>
</body>
</html>
