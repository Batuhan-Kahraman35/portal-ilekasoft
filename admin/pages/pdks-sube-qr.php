<?php
/**
 * Admin Panel - PDKS Şube QR Yönetimi
 *
 * Kaynak tablo: dbo.Subeler (sube_qr, sube_mola_qr, sube_enlem, sube_boylam,
 * sube_yaricap_metre)
 * PDKS API'si QR doğrulamasını ve geofence kontrolünü doğrudan bu kolonlardan
 * yaptığı için QR ve konum ayarları tek yerden yönetilir.
 *
 * Şube başına iki QR tanımlanır: mesai panosu (sube_qr) giriş/çıkış, mola
 * panosu (sube_mola_qr) mola başlangıç/bitiş kaydı üretir. Hangi hareketin
 * yazılacağını okutulan QR belirlediği için iki kolon da benzersiz olmalıdır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'PDKS Şube QR Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

$subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");

/**
 * "41,015137" gibi virgüllü girişleri float'a çevirir. Boş giriş için null döner.
 */
function pdksKonumSayi($deger) {
    $deger = trim((string)$deger);
    if ($deger === '') {
        return null;
    }
    $deger = str_replace(',', '.', preg_replace('/\s+/', '', $deger));
    if (!is_numeric($deger)) {
        throw new Exception('Konum alanları için geçerli bir sayı giriniz (örn: 41,015137)');
    }
    return (float)$deger;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) as c FROM Subeler WHERE sube_durum = 1")['c'] ?? 0;
                $qrVar = $db->fetchOne("
                    SELECT COUNT(*) as c FROM Subeler
                    WHERE sube_durum = 1 AND NULLIF(LTRIM(RTRIM(sube_qr)), '') IS NOT NULL
                ")['c'] ?? 0;
                $qrYok = $toplam - $qrVar;
                $molaQrVar = $db->fetchOne("
                    SELECT COUNT(*) as c FROM Subeler
                    WHERE sube_durum = 1 AND NULLIF(LTRIM(RTRIM(sube_mola_qr)), '') IS NOT NULL
                ")['c'] ?? 0;
                $geofenceVar = $db->fetchOne("
                    SELECT COUNT(*) as c FROM Subeler
                    WHERE sube_durum = 1
                      AND sube_enlem IS NOT NULL
                      AND sube_boylam IS NOT NULL
                      AND sube_yaricap_metre IS NOT NULL
                ")['c'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplam', 'qrVar', 'qrYok', 'molaQrVar', 'geofenceVar')]);
                break;

            case 'list':
                $subeId = $_POST['sube_id'] ?? '';
                $qrDurum = $_POST['qr_durum'] ?? '';
                $search = $_POST['search'] ?? '';

                $sql = "SELECT sube_id, sube_adi, sube_qr, sube_mola_qr,
                               sube_enlem, sube_boylam, sube_yaricap_metre
                        FROM Subeler
                        WHERE sube_durum = 1";
                $params = [];

                if ($subeId !== '') {
                    $sql .= " AND sube_id = ?";
                    $params[] = $subeId;
                }
                if ($qrDurum === '1') {
                    $sql .= " AND NULLIF(LTRIM(RTRIM(sube_qr)), '') IS NOT NULL";
                } elseif ($qrDurum === '0') {
                    $sql .= " AND NULLIF(LTRIM(RTRIM(sube_qr)), '') IS NULL";
                }
                if ($search) {
                    $sql .= " AND (sube_adi LIKE ? OR sube_qr LIKE ? OR sube_mola_qr LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                $sql .= " ORDER BY sube_adi";
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            case 'get':
                $id = intval($_POST['sube_id'] ?? 0);
                $data = $db->fetchOne("
                    SELECT sube_id, sube_adi, sube_qr, sube_mola_qr,
                           sube_enlem, sube_boylam, sube_yaricap_metre
                    FROM Subeler WHERE sube_id = ?
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'save':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $id = intval($_POST['sube_id'] ?? 0);
                $qrKod = trim($_POST['qr_kod'] ?? '');
                $molaQrKod = trim($_POST['mola_qr_kod'] ?? '');
                $enlem = pdksKonumSayi($_POST['enlem'] ?? '');
                $boylam = pdksKonumSayi($_POST['boylam'] ?? '');
                $yaricap = pdksKonumSayi($_POST['yaricap_metre'] ?? '');

                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'Şube seçilmedi!']);
                    break;
                }
                if ($qrKod === '') {
                    echo json_encode(['success' => false, 'message' => 'QR Kod zorunludur!']);
                    break;
                }
                if ($molaQrKod !== '' && $molaQrKod === $qrKod) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Mola QR kodu mesai QR kodu ile aynı olamaz!'
                    ]);
                    break;
                }

                // API okutulan kodu iki kolonda birden arayıp TOP 1 ile eşleştirdiği
                // için hem mesai hem mola kodu, tüm şubelerin her iki kolonuna karşı
                // benzersiz olmalıdır.
                foreach ([['Mesai', $qrKod], ['Mola', $molaQrKod]] as [$etiket, $kod]) {
                    if ($kod === '') {
                        continue;
                    }
                    $cakisma = $db->fetchOne("
                        SELECT sube_adi,
                               CASE WHEN LTRIM(RTRIM(sube_qr)) = ? THEN 'mesai' ELSE 'mola' END AS kolon
                        FROM Subeler
                        WHERE (LTRIM(RTRIM(sube_qr)) = ? OR LTRIM(RTRIM(sube_mola_qr)) = ?)
                          AND sube_id <> ?
                    ", [$kod, $kod, $kod, $id]);
                    if ($cakisma) {
                        echo json_encode([
                            'success' => false,
                            'message' => $etiket . ' QR kodu zaten kullanılıyor: '
                                . $cakisma['sube_adi'] . ' (' . $cakisma['kolon'] . ' QR)'
                        ]);
                        break 2;
                    }
                }

                // Geofence üç alanın tamamını ister; eksik tanım sessizce kontrolsüz
                // kalacağı için tümü dolu ya da tümü boş olmalıdır.
                $doluSayisi = count(array_filter([$enlem, $boylam, $yaricap], fn($v) => $v !== null));
                if ($doluSayisi > 0 && $doluSayisi < 3) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Konum kontrolü için enlem, boylam ve yarıçap birlikte girilmelidir. Konum kontrolü istemiyorsanız üçünü de boş bırakın.'
                    ]);
                    break;
                }
                if ($yaricap !== null && $yaricap <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Yarıçap sıfırdan büyük olmalıdır!']);
                    break;
                }

                $db->update('Subeler', [
                    'sube_qr'             => $qrKod,
                    'sube_mola_qr'        => $molaQrKod !== '' ? $molaQrKod : null,
                    'sube_enlem'          => $enlem,
                    'sube_boylam'         => $boylam,
                    'sube_yaricap_metre'  => $yaricap,
                ], ['sube_id' => $id]);

                echo json_encode(['success' => true, 'message' => 'Şube PDKS ayarları kaydedildi!']);
                break;

            case 'qr_temizle':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = intval($_POST['sube_id'] ?? 0);
                $tur = $_POST['qr_turu'] ?? 'mesai';

                if ($tur === 'mola') {
                    $db->update('Subeler', ['sube_mola_qr' => null], ['sube_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Mola QR kodu temizlendi! Bu şubede artık mola okutması yapılamaz.']);
                    break;
                }

                // Mesai QR'ı olmayan şubede mola kaydı da açılamaz (mola için açık
                // giriş şart), bu yüzden ikisi birlikte temizlenir.
                $db->update('Subeler', ['sube_qr' => null, 'sube_mola_qr' => null], ['sube_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'QR kodları temizlendi! Bu şubede artık okutma yapılamaz.']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .qr-code-text { font-family: monospace; font-size: 0.8rem; word-break: break-all; max-width: 200px; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item">PDKS</li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-building"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Şube</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-qr-code"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">QR Tanımlı</span>
                                <span class="info-box-number" id="stat-qrvar">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-cup-hot"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Mola QR Tanımlı</span>
                                <span class="info-box-number" id="stat-molaqrvar">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-geo-alt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Konum Kontrollü</span>
                                <span class="info-box-number" id="stat-geofence">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                    </div>
                    <div class="card-body">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Şube</label>
                                    <select class="form-select" id="filter_sube_id" name="sube_id">
                                        <option value="">Tümü</option>
                                        <?php foreach ($subeler as $s): ?>
                                            <option value="<?= $s['sube_id'] ?>"><?= htmlspecialchars($s['sube_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">QR Durumu</label>
                                    <select class="form-select" id="filter_qr_durum" name="qr_durum">
                                        <option value="">Tümü</option>
                                        <option value="1">QR Tanımlı</option>
                                        <option value="0">QR Tanımsız</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="filter_search" name="search" placeholder="Şube adı veya QR kod...">
                                </div>
                                <div class="col-md-2 d-flex align-items-end gap-2">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrele</button>
                                </div>
                                <div class="col-12">
                                    <button type="button" class="btn btn-secondary btn-sm" id="clearFilters">
                                        <i class="bi bi-x-circle"></i> Filtreleri Temizle
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-qr-code-scan"></i> Şube PDKS Ayarları</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info d-flex align-items-start" role="alert">
                            <i class="bi bi-info-circle me-2 mt-1"></i>
                            <div>
                                <strong>Mesai QR kodu</strong> giriş/çıkış, <strong>mola QR kodu</strong> mola
                                başlangıç/bitiş kaydı üretir; iki kod ayrı panolara asılmalıdır. Mola QR kodu
                                tanımlanmayan şubelerde mola takibi yapılmaz. Mesai QR kodu tanımsız şubelerde
                                personel hiç okutma yapamaz.
                                Konum bilgisi (enlem/boylam/yarıçap) boş bırakılan şubelerde
                                <strong>konum kontrolü yapılmaz</strong> — personel şubenin dışından da okutabilir.
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover" id="dataTable">
                                <thead>
                                    <tr>
                                        <th width="60">#</th>
                                        <th>Şube</th>
                                        <th>Mesai QR Kodu</th>
                                        <th>Mola QR Kodu</th>
                                        <th>Konum</th>
                                        <th width="110">Yarıçap</th>
                                        <th width="160">İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <tr><td colspan="7" class="text-center">Yükleniyor...</td></tr>
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

<!-- Modal: QR Görüntüle / İndir -->
<div class="modal fade" id="modalQRGoster" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:340px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="qrModalBaslik"><i class="bi bi-qr-code"></i> QR Kod</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="qrKodGorsel" class="d-inline-block p-3 border rounded bg-white mb-2"></div>
                <div class="text-muted small font-monospace" id="qrKodMetin"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                <button type="button" class="btn btn-success" id="btnQrIndir"><i class="bi bi-download"></i> PNG İndir</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Şube PDKS Ayarları -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Şube PDKS Ayarları</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="saveForm">
                <div class="modal-body">
                    <input type="hidden" id="sube_id" name="sube_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Şube</label>
                            <input type="text" class="form-control" id="sube_adi" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mesai QR Kodu <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control font-monospace" id="qr_kod" name="qr_kod" required placeholder="QR kod değeri...">
                                <button type="button" class="btn btn-outline-secondary" id="btnUretQr" title="Otomatik üret">
                                    <i class="bi bi-magic"></i>
                                </button>
                            </div>
                            <small class="text-muted">Giriş ve çıkış kaydı üretir. Mobil uygulamanın okuyacağı benzersiz kod değeri</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mola QR Kodu</label>
                            <div class="input-group">
                                <input type="text" class="form-control font-monospace" id="mola_qr_kod" name="mola_qr_kod" placeholder="Mola panosu kod değeri...">
                                <button type="button" class="btn btn-outline-secondary" id="btnUretMolaQr" title="Otomatik üret">
                                    <i class="bi bi-magic"></i>
                                </button>
                            </div>
                            <small class="text-muted">
                                Mola başlangıç ve bitiş kaydı üretir. Boş bırakılırsa bu şubede mola takibi yapılmaz.
                            </small>
                        </div>

                        <div class="col-12">
                            <hr class="my-1">
                            <h6 class="mb-0"><i class="bi bi-geo-alt"></i> Konum Kontrolü (Geofence)</h6>
                            <small class="text-muted">
                                Üçü birlikte doldurulmalıdır. Boş bırakılırsa bu şubede konum kontrolü yapılmaz.
                            </small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Enlem</label>
                            <input type="text" class="form-control font-monospace" id="enlem" name="enlem" placeholder="41,015137">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Boylam</label>
                            <input type="text" class="form-control font-monospace" id="boylam" name="boylam" placeholder="28,979530">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Yarıçap (metre)</label>
                            <input type="text" class="form-control" id="yaricap_metre" name="yaricap_metre" placeholder="200">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-primary w-100" id="btnKonumAl">
                                <i class="bi bi-crosshair"></i> Bulunduğum Konumu Al
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
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
const permissions = <?= json_encode($pagePermissions) ?>;

function escHtml(str) {
    return String(str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

const modalQR = new bootstrap.Modal('#modalQRGoster');
let _qrInstance = null;

/**
 * Panoya asılan çıktılarda mesai/mola kodlarının karışmaması için QR görselinin
 * altına şube adını ve kod değerini basar; indirilecek yeni bir canvas döndürür.
 */
function qrCiktiCanvasi(qrCanvas, baslik, qrKod) {
    const kenar = 24;                       // QR çevresindeki beyaz çerçeve
    const qrBoyut = qrCanvas.width;
    const genislik = qrBoyut + kenar * 2;

    const cikti = document.createElement('canvas');
    const ctx = cikti.getContext('2d');

    // Uzun şube adları taşmasın diye yazı tipi genişliğe göre küçültülür.
    let baslikPuntosu = 22;
    ctx.font = `bold ${baslikPuntosu}px "Source Sans 3", Arial, sans-serif`;
    while (baslikPuntosu > 11 && ctx.measureText(baslik).width > genislik - kenar) {
        baslikPuntosu -= 1;
        ctx.font = `bold ${baslikPuntosu}px "Source Sans 3", Arial, sans-serif`;
    }

    const baslikAlani = baslikPuntosu + 10;
    const kodAlani = 20;
    cikti.width = genislik;
    cikti.height = kenar + qrBoyut + baslikAlani + kodAlani + kenar;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, cikti.width, cikti.height);
    ctx.drawImage(qrCanvas, kenar, kenar);

    ctx.textAlign = 'center';
    ctx.fillStyle = '#000000';
    ctx.font = `bold ${baslikPuntosu}px "Source Sans 3", Arial, sans-serif`;
    ctx.fillText(baslik, genislik / 2, kenar + qrBoyut + baslikPuntosu + 4);

    ctx.fillStyle = '#6c757d';
    ctx.font = '12px "Courier New", monospace';
    ctx.fillText(qrKod, genislik / 2, kenar + qrBoyut + baslikAlani + 14);

    return cikti;
}

function showQR(qrKod, subeAdi) {
    $('#qrModalBaslik').html('<i class="bi bi-qr-code"></i> ' + (subeAdi || 'QR Kod'));
    $('#qrKodMetin').text(qrKod);

    const container = document.getElementById('qrKodGorsel');
    container.innerHTML = '';
    _qrInstance = new QRCode(container, {
        text: qrKod,
        width: 256,
        height: 256,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
    });

    $('#btnQrIndir').off('click').on('click', () => {
        const canvas = container.querySelector('canvas');
        if (!canvas) { showToast('QR görseli oluşturulamadı', 'error'); return; }
        const baslik = subeAdi || 'QR Kod';
        const link = document.createElement('a');
        link.download = 'QR-' + baslik.replace(/[^a-zA-Z0-9_-]/g, '_') + '.png';
        link.href = qrCiktiCanvasi(canvas, baslik, qrKod).toDataURL('image/png');
        link.click();
    });

    modalQR.show();
}

const modal = new bootstrap.Modal('#modalForm');
let currentFilters = {};

function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (r.success) {
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-qrvar').text(r.data.qrVar);
            $('#stat-molaqrvar').text(r.data.molaQrVar);
            $('#stat-geofence').text(r.data.geofenceVar);
        }
    });
}

function loadList() {
    $('#tableBody').html('<tr><td colspan="7" class="text-center">Yükleniyor...</td></tr>');
    $.post('', { action: 'list', ...currentFilters }, r => {
        if (!r.success) { showToast(r.message, 'error'); return; }
        const tbody = $('#tableBody');
        tbody.empty();
        if (!r.data.length) {
            tbody.html('<tr><td colspan="7" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
            return;
        }
        r.data.forEach(item => {
            const qrVar = item.sube_qr && String(item.sube_qr).trim() !== '';
            const molaQrVar = item.sube_mola_qr && String(item.sube_mola_qr).trim() !== '';
            const konumVar = item.sube_enlem !== null && item.sube_boylam !== null;

            const qrHucre = qrVar
                ? `<span class="qr-code-text text-primary">${escHtml(item.sube_qr)}</span>`
                : '<span class="badge bg-warning text-dark">Tanımsız</span>';

            const molaQrHucre = molaQrVar
                ? `<span class="qr-code-text text-warning-emphasis">${escHtml(item.sube_mola_qr)}</span>`
                : '<span class="badge bg-secondary">Mola takibi yok</span>';

            const konumHucre = konumVar
                ? `<span class="font-monospace small">${item.sube_enlem}, ${item.sube_boylam}</span>`
                : '<span class="badge bg-secondary">Kontrol yok</span>';

            const yaricapHucre = item.sube_yaricap_metre !== null
                ? `${item.sube_yaricap_metre} m`
                : '<span class="text-muted">-</span>';

            tbody.append(`
                <tr>
                    <td>${item.sube_id}</td>
                    <td>${escHtml(item.sube_adi)}</td>
                    <td>${qrHucre}</td>
                    <td>${molaQrHucre}</td>
                    <td>${konumHucre}</td>
                    <td>${yaricapHucre}</td>
                    <td>
                        ${qrVar ? `<button class="btn btn-sm btn-info me-1" onclick="showQR('${escHtml(item.sube_qr)}', '${escHtml(item.sube_adi || '')} - Mesai')" title="Mesai QR Görüntüle / İndir"><i class="bi bi-qr-code"></i></button>` : ''}
                        ${molaQrVar ? `<button class="btn btn-sm btn-warning me-1" onclick="showQR('${escHtml(item.sube_mola_qr)}', '${escHtml(item.sube_adi || '')} - Mola')" title="Mola QR Görüntüle / İndir"><i class="bi bi-cup-hot"></i></button>` : ''}
                        ${permissions.can_edit ? `<button class="btn btn-sm btn-secondary me-1" onclick="editRecord(${item.sube_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>` : ''}
                        ${(permissions.can_delete && qrVar) ? `<button class="btn btn-sm btn-danger" onclick="qrTemizle(${item.sube_id})" title="QR Kodlarını Temizle"><i class="bi bi-trash"></i></button>` : ''}
                    </td>
                </tr>`);
        });
    });
}

function editRecord(id) {
    $.post('', { action: 'get', sube_id: id }, r => {
        if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
        const d = r.data;
        $('#modalTitle').text('Şube PDKS Ayarları');
        $('#sube_id').val(d.sube_id);
        $('#sube_adi').val(d.sube_adi);
        $('#qr_kod').val(d.sube_qr || '');
        $('#mola_qr_kod').val(d.sube_mola_qr || '');
        $('#enlem').val(d.sube_enlem ?? '');
        $('#boylam').val(d.sube_boylam ?? '');
        $('#yaricap_metre').val(d.sube_yaricap_metre ?? '');
        modal.show();
    });
}

function qrTemizle(id) {
    confirmAction('Bu şubenin QR kodları temizlensin mi?', 'Mesai ve mola kodları birlikte silinir, bu şubede okutma yapılamaz!', () => {
        $.post('', { action: 'qr_temizle', sube_id: id, qr_turu: 'mesai' }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { loadStats(); loadList(); }
        });
    });
}

$('#saveForm').on('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'save');
    $.ajax({ url: '', type: 'POST', data: fd, processData: false, contentType: false,
        success: r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { modal.hide(); loadStats(); loadList(); }
        },
        error: () => showToast('Sunucu hatası', 'error')
    });
});

function qrKoduUret(onek) {
    return onek + '-' + Date.now().toString(36).toUpperCase() + '-' + Math.random().toString(36).substr(2, 6).toUpperCase();
}

$('#btnUretQr').on('click', () => $('#qr_kod').val(qrKoduUret('QR')));
$('#btnUretMolaQr').on('click', () => $('#mola_qr_kod').val(qrKoduUret('MOLA')));

// Şubede bulunan yöneticinin cihaz konumunu alanlara doldurur.
$('#btnKonumAl').on('click', function() {
    if (!navigator.geolocation) { showToast('Tarayıcı konum desteklemiyor', 'error'); return; }
    const btn = $(this);
    btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Alınıyor...');
    navigator.geolocation.getCurrentPosition(
        pos => {
            $('#enlem').val(pos.coords.latitude.toFixed(6));
            $('#boylam').val(pos.coords.longitude.toFixed(6));
            if (!$('#yaricap_metre').val()) $('#yaricap_metre').val(200);
            showToast('Konum alındı', 'success');
            btn.prop('disabled', false).html('<i class="bi bi-crosshair"></i> Bulunduğum Konumu Al');
        },
        () => {
            showToast('Konum alınamadı. Konum iznini kontrol edin.', 'error');
            btn.prop('disabled', false).html('<i class="bi bi-crosshair"></i> Bulunduğum Konumu Al');
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
});

$('#filterForm').on('submit', function(e) {
    e.preventDefault();
    currentFilters = {
        sube_id: $('#filter_sube_id').val(),
        qr_durum: $('#filter_qr_durum').val(),
        search: $('#filter_search').val()
    };
    Object.keys(currentFilters).forEach(k => { if (!currentFilters[k] && currentFilters[k] !== '0') delete currentFilters[k]; });
    loadList();
});

$('#clearFilters').on('click', () => {
    $('#filterForm')[0].reset();
    $('#filter_sube_id, #filter_qr_durum').val('').trigger('change.select2');
    currentFilters = {};
    loadList();
});

$(document).ready(() => {
    $('#filter_sube_id, #filter_qr_durum').select2({
        theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true,
        language: { noResults: () => 'Sonuç bulunamadı' }
    });
    loadStats();
    loadList();
});
</script>
</body>
</html>
