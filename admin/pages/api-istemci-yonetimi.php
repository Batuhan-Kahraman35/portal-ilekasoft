<?php
/**
 * Admin Panel - API İstemci Yönetimi
 * Dış sitelerin banka hareketleri API'sine erişim yetkilerini yönetir
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$permissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// API istemci yönetimi tam erişim gerektirir
if (!$permissions['is_admin']) {
    PageAuth::accessDenied('API istemci yönetimi yalnızca yöneticiler tarafından kullanılabilir.');
}

/**
 * Yeni API token üretir
 */
function apiTokenUret(): string
{
    return 'pk_' . bin2hex(random_bytes(24));
}

/**
 * İstemcinin firma/banka yetkilerini yeniden yazar
 */
function kapsamYetkileriniYaz(Database $db, string $tablo, string $istemciKolonu, string $hedefKolonu, int $istemciId, array $idler, int $kullaniciId): void
{
    $db->execute("DELETE FROM {$tablo} WHERE {$istemciKolonu} = ?", [$istemciId]);

    foreach (array_unique(array_map('intval', $idler)) as $hedefId) {
        if ($hedefId <= 0) {
            continue;
        }
        $db->execute(
            "INSERT INTO {$tablo} ({$istemciKolonu}, {$hedefKolonu}, OlusturanKullanici, OlusturmaTarihi, Durum)
             VALUES (?, ?, ?, GETDATE(), 1)",
            [$istemciId, $hedefId, $kullaniciId]
        );
    }
}

// ---------------------------------------------------------------
// AJAX işlemleri
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) AS sayi FROM api_Istemciler")['sayi'] ?? 0;
                $aktif  = $db->fetchOne("SELECT COUNT(*) AS sayi FROM api_Istemciler WHERE Durum = 1")['sayi'] ?? 0;
                $bugun  = $db->fetchOne(
                    "SELECT COUNT(*) AS sayi FROM api_Log WHERE CONVERT(date, OlusturmaTarihi) = CONVERT(date, GETDATE())"
                )['sayi'] ?? 0;
                $hata   = $db->fetchOne(
                    "SELECT COUNT(*) AS sayi FROM api_Log
                     WHERE apiLog_http_kod >= 400
                       AND OlusturmaTarihi >= DATEADD(DAY, -1, GETDATE())"
                )['sayi'] ?? 0;

                echo json_encode([
                    'success' => true,
                    'data' => [
                        'toplam' => (int) $toplam,
                        'aktif'  => (int) $aktif,
                        'bugun'  => (int) $bugun,
                        'hata'   => (int) $hata,
                    ],
                ]);
                exit;

            case 'list':
                $sql = "SELECT
                            i.apiIstemci_id,
                            i.apiIstemci_ad,
                            i.apiIstemci_aciklama,
                            i.apiIstemci_token_onek,
                            i.apiIstemci_rate_limit,
                            i.apiIstemci_dekont_erisim,
                            i.apiIstemci_ip_whitelist,
                            CONVERT(VARCHAR(10), i.apiIstemci_bitis_tarihi, 120) AS bitis_tarihi,
                            CONVERT(VARCHAR(19), i.apiIstemci_son_erisim, 120) AS son_erisim,
                            i.Durum,
                            kf.apiKapsam_kod AS firma_kapsam,
                            kb.apiKapsam_kod AS banka_kapsam,
                            ht.apiHareketTipi_kod AS hareket_tipi_kod,
                            ht.apiHareketTipi_adi AS hareket_tipi_adi,
                            (SELECT COUNT(*) FROM api_IstemciFirmalari af
                              WHERE af.apiIstemciFirma_istemci_id = i.apiIstemci_id AND af.Durum = 1) AS firma_sayisi,
                            (SELECT COUNT(*) FROM api_IstemciBankalari ab
                              WHERE ab.apiIstemciBanka_istemci_id = i.apiIstemci_id AND ab.Durum = 1) AS banka_sayisi,
                            (SELECT COUNT(*) FROM api_Log l
                              WHERE l.apiLog_istemci_id = i.apiIstemci_id
                                AND l.OlusturmaTarihi >= DATEADD(DAY, -1, GETDATE())) AS gunluk_istek
                        FROM api_Istemciler i
                        INNER JOIN tanim_api_kapsam kf ON kf.apiKapsam_id = i.apiIstemci_firma_kapsam_id
                        INNER JOIN tanim_api_kapsam kb ON kb.apiKapsam_id = i.apiIstemci_banka_kapsam_id
                        INNER JOIN tanim_api_hareket_tipi ht ON ht.apiHareketTipi_id = i.apiIstemci_hareket_tipi_id
                        WHERE 1 = 1";
                $params = [];

                if (!empty($_POST['filtre_ad'])) {
                    $sql .= " AND i.apiIstemci_ad LIKE ?";
                    $params[] = '%' . trim($_POST['filtre_ad']) . '%';
                }

                if (isset($_POST['filtre_durum']) && $_POST['filtre_durum'] !== '') {
                    $sql .= " AND i.Durum = ?";
                    $params[] = (int) $_POST['filtre_durum'];
                }

                $sql .= " ORDER BY i.apiIstemci_ad";

                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                exit;

            case 'get':
                $id = (int) ($_POST['id'] ?? 0);

                $istemci = $db->fetchOne(
                    "SELECT i.*, CONVERT(VARCHAR(10), i.apiIstemci_bitis_tarihi, 120) AS bitis_tarihi_str
                     FROM api_Istemciler i WHERE i.apiIstemci_id = ?",
                    [$id]
                );

                if (!$istemci) {
                    throw new Exception('İstemci bulunamadı.');
                }

                unset($istemci['apiIstemci_token_hash']);

                $istemci['firmalar'] = array_column(
                    $db->fetchAll(
                        "SELECT apiIstemciFirma_firma_id AS id FROM api_IstemciFirmalari
                         WHERE apiIstemciFirma_istemci_id = ? AND Durum = 1",
                        [$id]
                    ),
                    'id'
                );

                $istemci['bankalar'] = array_column(
                    $db->fetchAll(
                        "SELECT apiIstemciBanka_banka_id AS id FROM api_IstemciBankalari
                         WHERE apiIstemciBanka_istemci_id = ? AND Durum = 1",
                        [$id]
                    ),
                    'id'
                );

                echo json_encode(['success' => true, 'data' => $istemci]);
                exit;

            case 'save':
                $id            = (int) ($_POST['apiIstemci_id'] ?? 0);
                $ad            = trim($_POST['apiIstemci_ad'] ?? '');
                $aciklama      = trim($_POST['apiIstemci_aciklama'] ?? '');
                $ipWhitelist   = trim($_POST['apiIstemci_ip_whitelist'] ?? '');
                $rateLimit     = (int) ($_POST['apiIstemci_rate_limit'] ?? 1000);
                $dekont        = !empty($_POST['apiIstemci_dekont_erisim']) ? 1 : 0;
                $durum         = !empty($_POST['Durum']) ? 1 : 0;
                $bitisTarihi   = trim($_POST['apiIstemci_bitis_tarihi'] ?? '');
                $firmaKapsamId = (int) ($_POST['apiIstemci_firma_kapsam_id'] ?? 0);
                $bankaKapsamId = (int) ($_POST['apiIstemci_banka_kapsam_id'] ?? 0);
                $hareketTipiId = (int) ($_POST['apiIstemci_hareket_tipi_id'] ?? 0);
                $firmalar      = $_POST['firmalar'] ?? [];
                $bankalar      = $_POST['bankalar'] ?? [];

                if ($ad === '') {
                    throw new Exception('İstemci adı zorunludur.');
                }

                $kapsamlar = $db->fetchAll("SELECT apiKapsam_id, apiKapsam_kod FROM tanim_api_kapsam WHERE Durum = 1");
                $kapsamKodu = array_column($kapsamlar, 'apiKapsam_kod', 'apiKapsam_id');

                if (!isset($kapsamKodu[$firmaKapsamId]) || !isset($kapsamKodu[$bankaKapsamId])) {
                    throw new Exception('Geçersiz kapsam seçimi.');
                }

                $gecerliTipler = array_column(
                    $db->fetchAll("SELECT apiHareketTipi_id FROM tanim_api_hareket_tipi WHERE Durum = 1"),
                    'apiHareketTipi_id'
                );

                if (!in_array($hareketTipiId, array_map('intval', $gecerliTipler), true)) {
                    throw new Exception('Geçersiz hareket tipi seçimi.');
                }

                if ($kapsamKodu[$firmaKapsamId] === 'SECILI' && empty($firmalar)) {
                    throw new Exception('Firma kapsamı "Seçili olanlar" iken en az bir firma seçmelisiniz.');
                }

                if ($kapsamKodu[$bankaKapsamId] === 'SECILI' && empty($bankalar)) {
                    throw new Exception('Banka kapsamı "Seçili olanlar" iken en az bir banka seçmelisiniz.');
                }

                if ($rateLimit < 0) {
                    throw new Exception('İstek limiti negatif olamaz.');
                }

                if ($bitisTarihi !== '' && !DateTime::createFromFormat('Y-m-d', $bitisTarihi)) {
                    throw new Exception('Bitiş tarihi formatı geçersiz.');
                }

                $yeniToken = null;

                if ($id > 0) {
                    $db->execute(
                        "UPDATE api_Istemciler SET
                            apiIstemci_ad = ?,
                            apiIstemci_aciklama = ?,
                            apiIstemci_ip_whitelist = ?,
                            apiIstemci_rate_limit = ?,
                            apiIstemci_dekont_erisim = ?,
                            apiIstemci_firma_kapsam_id = ?,
                            apiIstemci_banka_kapsam_id = ?,
                            apiIstemci_hareket_tipi_id = ?,
                            apiIstemci_bitis_tarihi = ?,
                            Durum = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                         WHERE apiIstemci_id = ?",
                        [
                            $ad,
                            $aciklama !== '' ? $aciklama : null,
                            $ipWhitelist !== '' ? $ipWhitelist : null,
                            $rateLimit,
                            $dekont,
                            $firmaKapsamId,
                            $bankaKapsamId,
                            $hareketTipiId,
                            $bitisTarihi !== '' ? $bitisTarihi : null,
                            $durum,
                            $user['kullanici_id'],
                            $id,
                        ]
                    );
                } else {
                    $yeniToken = apiTokenUret();

                    $db->execute(
                        "INSERT INTO api_Istemciler
                            (apiIstemci_ad, apiIstemci_aciklama, apiIstemci_token_hash, apiIstemci_token_onek,
                             apiIstemci_ip_whitelist, apiIstemci_rate_limit, apiIstemci_dekont_erisim,
                             apiIstemci_firma_kapsam_id, apiIstemci_banka_kapsam_id, apiIstemci_hareket_tipi_id,
                             apiIstemci_bitis_tarihi, OlusturanKullanici, OlusturmaTarihi, Durum)
                         VALUES (?, ?, HASHBYTES('SHA2_256', ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)",
                        [
                            $ad,
                            $aciklama !== '' ? $aciklama : null,
                            $yeniToken,
                            substr($yeniToken, 0, 12),
                            $ipWhitelist !== '' ? $ipWhitelist : null,
                            $rateLimit,
                            $dekont,
                            $firmaKapsamId,
                            $bankaKapsamId,
                            $hareketTipiId,
                            $bitisTarihi !== '' ? $bitisTarihi : null,
                            $user['kullanici_id'],
                            $durum,
                        ]
                    );

                    $id = (int) $db->getLastInsertId();
                }

                kapsamYetkileriniYaz(
                    $db, 'api_IstemciFirmalari', 'apiIstemciFirma_istemci_id', 'apiIstemciFirma_firma_id',
                    $id, $kapsamKodu[$firmaKapsamId] === 'SECILI' ? $firmalar : [], $user['kullanici_id']
                );

                kapsamYetkileriniYaz(
                    $db, 'api_IstemciBankalari', 'apiIstemciBanka_istemci_id', 'apiIstemciBanka_banka_id',
                    $id, $kapsamKodu[$bankaKapsamId] === 'SECILI' ? $bankalar : [], $user['kullanici_id']
                );

                echo json_encode([
                    'success' => true,
                    'message' => $yeniToken ? 'İstemci oluşturuldu.' : 'İstemci güncellendi.',
                    'token'   => $yeniToken,
                ]);
                exit;

            case 'token_yenile':
                $id = (int) ($_POST['id'] ?? 0);

                $mevcut = $db->fetchOne("SELECT apiIstemci_id FROM api_Istemciler WHERE apiIstemci_id = ?", [$id]);
                if (!$mevcut) {
                    throw new Exception('İstemci bulunamadı.');
                }

                $yeniToken = apiTokenUret();

                $db->execute(
                    "UPDATE api_Istemciler SET
                        apiIstemci_token_hash = HASHBYTES('SHA2_256', ?),
                        apiIstemci_token_onek = ?,
                        GuncelleyenKullanici = ?,
                        GuncellemeTarihi = GETDATE()
                     WHERE apiIstemci_id = ?",
                    [$yeniToken, substr($yeniToken, 0, 12), $user['kullanici_id'], $id]
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Token yenilendi. Eski token artık geçersizdir.',
                    'token'   => $yeniToken,
                ]);
                exit;

            case 'delete':
                $id = (int) ($_POST['id'] ?? 0);

                // Log kayitlari korunur; istemci pasife alinir
                $db->execute(
                    "UPDATE api_Istemciler SET
                        Durum = 0,
                        GuncelleyenKullanici = ?,
                        GuncellemeTarihi = GETDATE()
                     WHERE apiIstemci_id = ?",
                    [$user['kullanici_id'], $id]
                );

                echo json_encode(['success' => true, 'message' => 'İstemci pasife alındı.']);
                exit;

            case 'loglar':
                $id = (int) ($_POST['id'] ?? 0);

                $loglar = $db->fetchAll(
                    "SELECT TOP 100
                        CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS tarih,
                        apiLog_endpoint, apiLog_metot, apiLog_ip,
                        apiLog_http_kod, apiLog_kayit_sayisi, apiLog_sure_ms,
                        apiLog_hata_mesaji, apiLog_parametreler
                     FROM api_Log
                     WHERE apiLog_istemci_id = ?
                     ORDER BY apiLog_id DESC",
                    [$id]
                );

                echo json_encode(['success' => true, 'data' => $loglar]);
                exit;

            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ---------------------------------------------------------------
// Sayfa verileri
// ---------------------------------------------------------------
$pageInfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'API İstemci Yönetimi';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

$kapsamlar = $db->fetchAll(
    "SELECT apiKapsam_id, apiKapsam_kod, apiKapsam_adi
     FROM tanim_api_kapsam WHERE Durum = 1 ORDER BY apiKapsam_sira"
);

$hareketTipleri = $db->fetchAll(
    "SELECT apiHareketTipi_id, apiHareketTipi_kod, apiHareketTipi_adi
     FROM tanim_api_hareket_tipi WHERE Durum = 1 ORDER BY apiHareketTipi_sira"
);

$firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
$bankalar = $db->fetchAll("SELECT banka_id, banka_adi FROM bankalar WHERE banka_durum = 1 ORDER BY banka_adi");
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .status-badge { padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem; }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .token-kutu {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            background: #f1f3f5;
            border: 1px solid #dee2e6;
            border-radius: .375rem;
            padding: .75rem;
            word-break: break-all;
        }
        .log-tablo td { font-size: .8125rem; vertical-align: middle; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
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

            <div class="app-content">
                <div class="container-fluid">

                    <!-- InfoBox -->
                    <div class="row mb-3">
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-primary">
                                <div class="inner">
                                    <h3 id="statToplam">0</h3>
                                    <p>Toplam İstemci</p>
                                </div>
                                <i class="bi bi-people small-box-icon"></i>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3 id="statAktif">0</h3>
                                    <p>Aktif İstemci</p>
                                </div>
                                <i class="bi bi-check-circle small-box-icon"></i>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-info">
                                <div class="inner">
                                    <h3 id="statBugun">0</h3>
                                    <p>Bugünkü İstek</p>
                                </div>
                                <i class="bi bi-arrow-repeat small-box-icon"></i>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-danger">
                                <div class="inner">
                                    <h3 id="statHata">0</h3>
                                    <p>Son 24 Saat Hata</p>
                                </div>
                                <i class="bi bi-exclamation-triangle small-box-icon"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre -->
                    <div class="card mb-3">
                        <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                            <h3 class="card-title"><i class="bi bi-funnel me-1"></i> Filtreler</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool"><i class="bi bi-chevron-down"></i></button>
                            </div>
                        </div>
                        <div class="collapse" id="filtrePanel">
                            <div class="card-body">
                                <form id="filterForm" class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label" for="filtre_ad">İstemci Adı</label>
                                        <input type="text" class="form-control" id="filtre_ad" name="filtre_ad" placeholder="Ada göre ara">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="filtre_durum">Durum</label>
                                        <select class="form-select select2" id="filtre_durum" name="filtre_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end gap-2">
                                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                        <button type="button" class="btn btn-secondary" id="filtreTemizle"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Liste -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">API İstemcileri</h3>
                            <div class="d-flex gap-2">
                                <a href="/api/v1/docs.php" target="_blank" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-book"></i> API Dokümantasyonu
                                </a>
                                <button type="button" class="btn btn-primary btn-sm" id="yeniIstemciBtn">
                                    <i class="bi bi-plus-lg"></i> Yeni İstemci
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="istemciTable" class="table table-hover table-striped w-100">
                                <thead>
                                    <tr>
                                        <th>İstemci</th>
                                        <th>Token Öneki</th>
                                        <th>Firma Kapsamı</th>
                                        <th>Banka Kapsamı</th>
                                        <th>Hareket Tipi</th>
                                        <th>Limit / Saat</th>
                                        <th>24s İstek</th>
                                        <th>Son Erişim</th>
                                        <th>Durum</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- İstemci Modal -->
    <div class="modal fade" id="istemciModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="istemciForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="istemciModalBaslik">Yeni API İstemcisi</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="apiIstemci_id" id="apiIstemci_id" value="0">

                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="apiIstemci_ad">İstemci Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="apiIstemci_ad" name="apiIstemci_ad" required
                                       placeholder="Örn: Muhasebe Sitesi">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="apiIstemci_rate_limit">Saatlik İstek Limiti</label>
                                <input type="number" class="form-control" id="apiIstemci_rate_limit"
                                       name="apiIstemci_rate_limit" value="1000" min="0">
                                <div class="form-text">0 yazılırsa limit uygulanmaz.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="apiIstemci_aciklama">Açıklama</label>
                                <textarea class="form-control" id="apiIstemci_aciklama" name="apiIstemci_aciklama" rows="2"></textarea>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label" for="apiIstemci_ip_whitelist">İzinli IP Adresleri</label>
                                <input type="text" class="form-control" id="apiIstemci_ip_whitelist"
                                       name="apiIstemci_ip_whitelist" placeholder="203.0.113.10, 88.12.34.56">
                                <div class="form-text">Virgülle ayırın. Boş bırakılırsa IP kısıtlaması uygulanmaz.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="apiIstemci_bitis_tarihi">Geçerlilik Bitişi</label>
                                <input type="date" class="form-control" id="apiIstemci_bitis_tarihi" name="apiIstemci_bitis_tarihi">
                                <div class="form-text">Boş bırakılırsa süresiz geçerlidir.</div>
                            </div>

                            <hr class="mt-2">

                            <!-- Firma kapsamı -->
                            <div class="col-md-6">
                                <label class="form-label" for="apiIstemci_firma_kapsam_id">Firma Erişimi</label>
                                <select class="form-select select2" id="apiIstemci_firma_kapsam_id" name="apiIstemci_firma_kapsam_id">
                                    <?php foreach ($kapsamlar as $k): ?>
                                    <option value="<?= (int) $k['apiKapsam_id'] ?>" data-kod="<?= htmlspecialchars($k['apiKapsam_kod']) ?>">
                                        <?= htmlspecialchars($k['apiKapsam_adi']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="mt-2" id="firmaSecimAlani">
                                    <select class="form-select select2" id="firmalar" name="firmalar[]" multiple>
                                        <?php foreach ($firmalar as $f): ?>
                                        <option value="<?= (int) $f['firma_id'] ?>"><?= htmlspecialchars($f['firma_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Banka kapsamı -->
                            <div class="col-md-6">
                                <label class="form-label" for="apiIstemci_banka_kapsam_id">Banka Erişimi</label>
                                <select class="form-select select2" id="apiIstemci_banka_kapsam_id" name="apiIstemci_banka_kapsam_id">
                                    <?php foreach ($kapsamlar as $k): ?>
                                    <option value="<?= (int) $k['apiKapsam_id'] ?>" data-kod="<?= htmlspecialchars($k['apiKapsam_kod']) ?>">
                                        <?= htmlspecialchars($k['apiKapsam_adi']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="mt-2" id="bankaSecimAlani">
                                    <select class="form-select select2" id="bankalar" name="bankalar[]" multiple>
                                        <?php foreach ($bankalar as $b): ?>
                                        <option value="<?= (int) $b['banka_id'] ?>"><?= htmlspecialchars($b['banka_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Hareket tipi -->
                            <div class="col-12">
                                <label class="form-label" for="apiIstemci_hareket_tipi_id">Hareket Tipi Erişimi</label>
                                <select class="form-select select2" id="apiIstemci_hareket_tipi_id" name="apiIstemci_hareket_tipi_id">
                                    <?php foreach ($hareketTipleri as $t): ?>
                                    <option value="<?= (int) $t['apiHareketTipi_id'] ?>"><?= htmlspecialchars($t['apiHareketTipi_adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Kısıt seçilirse istemci diğer tipteki hareketleri hiç göremez;
                                    <code>tip</code> parametresini elle göndermesi de sonucu değiştirmez.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="apiIstemci_dekont_erisim" name="apiIstemci_dekont_erisim" value="1">
                                    <label class="form-check-label" for="apiIstemci_dekont_erisim">Dekont dosyalarına erişebilsin</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="Durum" name="Durum" value="1" checked>
                                    <label class="form-check-label" for="Durum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Log Modal -->
    <div class="modal fade" id="logModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Son 100 İstek</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped log-tablo">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Uç Nokta</th>
                                    <th>IP</th>
                                    <th>Kod</th>
                                    <th>Kayıt</th>
                                    <th>Süre</th>
                                    <th>Hata</th>
                                </tr>
                            </thead>
                            <tbody id="logGovde"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {
        let tablo;

        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#istemciModal .select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#istemciModal')
        });
        $('#firmalar').select2({
            theme: 'bootstrap-5', width: '100%',
            dropdownParent: $('#istemciModal'), placeholder: 'Firma seçin'
        });
        $('#bankalar').select2({
            theme: 'bootstrap-5', width: '100%',
            dropdownParent: $('#istemciModal'), placeholder: 'Banka seçin'
        });

        tablo = $('#istemciTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [9] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']],
            scrollX: true
        });

        function kapsamAlaniGuncelle(kapsamSecici, alanSecici, listeSecici) {
            const kod = $(kapsamSecici).find('option:selected').data('kod');
            if (kod === 'SECILI') {
                $(alanSecici).show();
            } else {
                $(alanSecici).hide();
                $(listeSecici).val(null).trigger('change');
            }
        }

        $('#apiIstemci_firma_kapsam_id').on('change', function () {
            kapsamAlaniGuncelle(this, '#firmaSecimAlani', '#firmalar');
        });
        $('#apiIstemci_banka_kapsam_id').on('change', function () {
            kapsamAlaniGuncelle(this, '#bankaSecimAlani', '#bankalar');
        });

        function istatistikYukle() {
            $.post('', { action: 'stats' }, function (y) {
                if (!y.success) return;
                $('#statToplam').text(y.data.toplam);
                $('#statAktif').text(y.data.aktif);
                $('#statBugun').text(y.data.bugun);
                $('#statHata').text(y.data.hata);
            }, 'json');
        }

        function kapsamRozeti(kod, sayi) {
            return kod === 'TUMU'
                ? '<span class="badge text-bg-warning">Tümü</span>'
                : '<span class="badge text-bg-secondary">' + sayi + ' seçili</span>';
        }

        function hareketTipiRozeti(kod, ad) {
            const metin = $('<div>').text(ad).html();
            if (kod === 'ALACAK') {
                return '<span class="badge text-bg-success"><i class="bi bi-arrow-down-left"></i> Gelen</span>';
            }
            if (kod === 'BORC') {
                return '<span class="badge text-bg-danger"><i class="bi bi-arrow-up-right"></i> Giden</span>';
            }
            return '<span class="badge text-bg-warning">' + metin + '</span>';
        }

        function listeYukle() {
            const veri = { action: 'list' };
            $('#filterForm').serializeArray().forEach(a => veri[a.name] = a.value);

            $.post('', veri, function (y) {
                tablo.clear();
                if (!y.success) { tablo.draw(); return; }

                y.data.forEach(function (s) {
                    const durumRozet = s.Durum == 1
                        ? '<span class="status-badge status-active">Aktif</span>'
                        : '<span class="status-badge status-inactive">Pasif</span>';

                    const dekont = s.apiIstemci_dekont_erisim == 1
                        ? ' <i class="bi bi-paperclip text-primary" title="Dekont erişimi açık"></i>' : '';

                    const ipNot = s.apiIstemci_ip_whitelist
                        ? ' <i class="bi bi-shield-check text-success" title="IP kısıtlı"></i>' : '';

                    tablo.row.add([
                        '<strong>' + $('<div>').text(s.apiIstemci_ad).html() + '</strong>' + dekont + ipNot +
                            (s.apiIstemci_aciklama ? '<br><small class="text-muted">' + $('<div>').text(s.apiIstemci_aciklama).html() + '</small>' : ''),
                        '<code>' + s.apiIstemci_token_onek + '…</code>',
                        kapsamRozeti(s.firma_kapsam, s.firma_sayisi),
                        kapsamRozeti(s.banka_kapsam, s.banka_sayisi),
                        hareketTipiRozeti(s.hareket_tipi_kod, s.hareket_tipi_adi),
                        s.apiIstemci_rate_limit > 0 ? s.apiIstemci_rate_limit : 'Sınırsız',
                        s.gunluk_istek,
                        s.son_erisim || '<span class="text-muted">—</span>',
                        durumRozet,
                        '<div class="btn-group btn-group-sm">' +
                            '<button class="btn btn-outline-primary duzenle" data-id="' + s.apiIstemci_id + '" title="Düzenle"><i class="bi bi-pencil"></i></button>' +
                            '<button class="btn btn-outline-info loglar" data-id="' + s.apiIstemci_id + '" title="İstek geçmişi"><i class="bi bi-clock-history"></i></button>' +
                            '<button class="btn btn-outline-warning token" data-id="' + s.apiIstemci_id + '" title="Token yenile"><i class="bi bi-key"></i></button>' +
                            '<button class="btn btn-outline-danger sil" data-id="' + s.apiIstemci_id + '" title="Pasife al"><i class="bi bi-trash"></i></button>' +
                        '</div>'
                    ]);
                });

                tablo.draw();
            }, 'json');
        }

        function tokenGoster(token) {
            Swal.fire({
                icon: 'success',
                title: 'Token oluşturuldu',
                html: '<p class="mb-2">Bu token yalnızca bir kez gösterilir. Kopyalayıp istemciye iletin.</p>' +
                      '<div class="token-kutu">' + token + '</div>',
                confirmButtonText: 'Kopyaladım',
                width: 600
            });
        }

        $('#yeniIstemciBtn').on('click', function () {
            $('#istemciForm')[0].reset();
            $('#apiIstemci_id').val(0);
            $('#istemciModalBaslik').text('Yeni API İstemcisi');
            $('#firmalar, #bankalar').val(null).trigger('change');
            $('#apiIstemci_firma_kapsam_id, #apiIstemci_banka_kapsam_id').trigger('change');
            $('#Durum').prop('checked', true);
            $('#istemciModal').modal('show');
        });

        $('#istemciTable').on('click', '.duzenle', function () {
            const id = $(this).data('id');

            $.post('', { action: 'get', id: id }, function (y) {
                if (!y.success) { Swal.fire('Hata', y.message, 'error'); return; }
                const d = y.data;

                $('#istemciModalBaslik').text('İstemci Düzenle');
                $('#apiIstemci_id').val(d.apiIstemci_id);
                $('#apiIstemci_ad').val(d.apiIstemci_ad);
                $('#apiIstemci_aciklama').val(d.apiIstemci_aciklama || '');
                $('#apiIstemci_ip_whitelist').val(d.apiIstemci_ip_whitelist || '');
                $('#apiIstemci_rate_limit').val(d.apiIstemci_rate_limit);
                $('#apiIstemci_bitis_tarihi').val(d.bitis_tarihi_str || '');
                $('#apiIstemci_dekont_erisim').prop('checked', d.apiIstemci_dekont_erisim == 1);
                $('#Durum').prop('checked', d.Durum == 1);

                $('#apiIstemci_hareket_tipi_id').val(d.apiIstemci_hareket_tipi_id).trigger('change');
                $('#apiIstemci_firma_kapsam_id').val(d.apiIstemci_firma_kapsam_id).trigger('change');
                $('#apiIstemci_banka_kapsam_id').val(d.apiIstemci_banka_kapsam_id).trigger('change');

                $('#firmalar').val(d.firmalar).trigger('change');
                $('#bankalar').val(d.bankalar).trigger('change');

                $('#istemciModal').modal('show');
            }, 'json');
        });

        $('#istemciForm').on('submit', function (e) {
            e.preventDefault();

            const veri = $(this).serialize() + '&action=save';

            $.post('', veri, function (y) {
                if (!y.success) { Swal.fire('Hata', y.message, 'error'); return; }

                $('#istemciModal').modal('hide');
                listeYukle();
                istatistikYukle();

                if (y.token) {
                    tokenGoster(y.token);
                } else {
                    Swal.fire({ icon: 'success', title: y.message, timer: 1500, showConfirmButton: false });
                }
            }, 'json');
        });

        $('#istemciTable').on('click', '.token', function () {
            const id = $(this).data('id');

            Swal.fire({
                icon: 'warning',
                title: 'Token yenilensin mi?',
                text: 'Mevcut token anında geçersiz olur; istemcinin entegrasyonu yeni token girilene kadar çalışmaz.',
                showCancelButton: true,
                confirmButtonText: 'Yenile',
                cancelButtonText: 'Vazgeç'
            }).then(function (sonuc) {
                if (!sonuc.isConfirmed) return;

                $.post('', { action: 'token_yenile', id: id }, function (y) {
                    if (!y.success) { Swal.fire('Hata', y.message, 'error'); return; }
                    listeYukle();
                    tokenGoster(y.token);
                }, 'json');
            });
        });

        $('#istemciTable').on('click', '.sil', function () {
            const id = $(this).data('id');

            Swal.fire({
                icon: 'warning',
                title: 'İstemci pasife alınsın mı?',
                text: 'Erişim anında durur. Log kayıtları korunur.',
                showCancelButton: true,
                confirmButtonText: 'Pasife al',
                cancelButtonText: 'Vazgeç'
            }).then(function (sonuc) {
                if (!sonuc.isConfirmed) return;

                $.post('', { action: 'delete', id: id }, function (y) {
                    if (!y.success) { Swal.fire('Hata', y.message, 'error'); return; }
                    listeYukle();
                    istatistikYukle();
                    Swal.fire({ icon: 'success', title: y.message, timer: 1500, showConfirmButton: false });
                }, 'json');
            });
        });

        $('#istemciTable').on('click', '.loglar', function () {
            const id = $(this).data('id');

            $.post('', { action: 'loglar', id: id }, function (y) {
                const govde = $('#logGovde').empty();

                if (!y.success || !y.data.length) {
                    govde.append('<tr><td colspan="7" class="text-center text-muted">Kayıt bulunmuyor.</td></tr>');
                } else {
                    y.data.forEach(function (l) {
                        const renk = l.apiLog_http_kod < 400 ? 'text-bg-success' : 'text-bg-danger';
                        govde.append(
                            '<tr>' +
                            '<td>' + l.tarih + '</td>' +
                            '<td><code>' + $('<div>').text(l.apiLog_endpoint).html() + '</code>' +
                                (l.apiLog_parametreler ? '<br><small class="text-muted">' + $('<div>').text(l.apiLog_parametreler).html() + '</small>' : '') + '</td>' +
                            '<td>' + (l.apiLog_ip || '—') + '</td>' +
                            '<td><span class="badge ' + renk + '">' + l.apiLog_http_kod + '</span></td>' +
                            '<td>' + (l.apiLog_kayit_sayisi !== null ? l.apiLog_kayit_sayisi : '—') + '</td>' +
                            '<td>' + (l.apiLog_sure_ms !== null ? l.apiLog_sure_ms + ' ms' : '—') + '</td>' +
                            '<td>' + (l.apiLog_hata_mesaji ? $('<div>').text(l.apiLog_hata_mesaji).html() : '—') + '</td>' +
                            '</tr>'
                        );
                    });
                }

                $('#logModal').modal('show');
            }, 'json');
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            listeYukle();
        });

        $('#filtreTemizle').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filtre_durum').val('').trigger('change');
            listeYukle();
        });

        istatistikYukle();
        listeYukle();
    });
    </script>
</body>
</html>
