<?php
/**
 * Admin Panel - Zimmet Onay Takip
 * Sirket hatlarinin personel dogrulamasi: donem baslatma, mesaj gonderimi,
 * yanit takibi ve duzeltme aksiyonlari (havuza al / devret).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/ZimmetOnay.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();
$zimmetOnay = new ZimmetOnay();

// Sayfa yetki kontrolu
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Zimmet Onay Takip';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX Islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $donem = $_POST['donem'] ?? $zimmetOnay->aktifDonem();

    try {
        switch ($action) {

            case 'stats':
                echo json_encode(['success' => true, 'data' => $zimmetOnay->istatistik($donem)]);
                break;

            case 'list':
                $data = $zimmetOnay->liste([
                    'donem'       => $donem,
                    'durum_kodu'  => $_POST['durum_kodu'] ?? '',
                    'personel_id' => $_POST['personel_id'] ?? '',
                    'aksiyon'     => $_POST['aksiyon'] ?? '',
                    'arama'       => $_POST['arama'] ?? '',
                    'cevapsiz'    => !empty($_POST['cevapsiz']),
                ]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'onizleme':
                // Donem baslatilmadan once hangi hatlara gidecegini goster
                $adaylar = $zimmetOnay->adayHatlar();
                $gonderilecek = 0;
                $atlanacak = 0;
                $zimmetli = 0;
                $havuzda = 0;
                foreach ($adaylar as $a) {
                    $a['gonderilebilir'] ? $gonderilecek++ : $atlanacak++;
                    $a['mevcut_durum'] === 'ZIMMETLI' ? $zimmetli++ : $havuzda++;
                }
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'toplam'       => count($adaylar),
                        'gonderilecek' => $gonderilecek,
                        'atlanacak'    => $atlanacak,
                        'zimmetli'     => $zimmetli,
                        'havuzda'      => $havuzda,
                        'liste'        => $adaylar,
                    ]
                ]);
                break;

            case 'donem_baslat':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                $sonuc = $zimmetOnay->donemBaslat($donem, $user['id']);
                echo json_encode([
                    'success' => true,
                    'data' => $sonuc,
                    'message' => "Dönem hazırlandı. Yeni kayıt: {$sonuc['eklenen']}, mevcut: {$sonuc['mevcut']}, gönderilecek: {$sonuc['gonderilecek']}"
                ]);
                break;

            case 'gonder_partisi':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                $testMode = !empty($_POST['test_mode']);
                $limit = (int)($_POST['limit'] ?? 10);
                $sonuc = $zimmetOnay->gonderPartisi($donem, $limit, $testMode, $user['id']);
                echo json_encode(['success' => empty($sonuc['hata']), 'data' => $sonuc, 'message' => $sonuc['hata'] ?? null]);
                break;

            case 'tekrar_gonder':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                $zimmetOnay->tekrarGonderilecekIsaretle((int)$_POST['id'], $user['id']);
                echo json_encode(['success' => true, 'message' => 'Kayıt tekrar gönderilecek listesine alındı. "Mesajları Gönder" butonuna basın.']);
                break;

            case 'manuel_onay':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                echo json_encode($zimmetOnay->manuelOnayla((int)$_POST['id'], $user['id']));
                break;

            case 'havuza_al':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                echo json_encode($zimmetOnay->havuzaAl((int)$_POST['id'], $user['id']));
                break;

            case 'kilit_ac':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                echo json_encode($zimmetOnay->kilitAc((int)$_POST['id'], $user['id']));
                break;

            case 'devret':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                echo json_encode($zimmetOnay->devret((int)$_POST['id'], $user['id']));
                break;

            case 'personele_devret':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                echo json_encode($zimmetOnay->secilenPersoneleDevret(
                    (int)$_POST['id'],
                    (int)($_POST['personel_id'] ?? 0),
                    $user['id']
                ));
                break;

            case 'ayar_kaydet':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                $zimmetOnay->ayarKaydet([
                    'kanal'         => $_POST['kanal'] ?? 'SMS',
                    'bekleme_gun'   => (int)($_POST['bekleme_gun'] ?? 7),
                    'max_deneme'    => (int)($_POST['max_deneme'] ?? 3),
                    'link_base'     => trim($_POST['link_base'] ?? ''),
                    'mesaj_sablonu' => trim($_POST['mesaj_sablonu'] ?? ''),
                ], $user['id']);
                echo json_encode(['success' => true, 'message' => 'Ayarlar kaydedildi.']);
                break;

            case 'test_gonderimi':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                echo json_encode($zimmetOnay->testGonderimi(
                    $_POST['numara'] ?? '',
                    (int)($_POST['personel_id'] ?? 0),
                    $user['id']
                ));
                break;

            case 'mesaj_onizle':
                $ornek = $zimmetOnay->mesajOlustur(str_repeat('a', 32), 'Ad Soyad', '5550000000', $donem);
                echo json_encode(['success' => true, 'data' => ['mesaj' => $ornek, 'uzunluk' => mb_strlen($ornek)]]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Sayfa verileri
$ayarlar     = $zimmetOnay->ayarlar();
$durumlar    = $zimmetOnay->durumTanimlari();
$donemler    = $zimmetOnay->donemler();
$aktifDonem  = $zimmetOnay->aktifDonem();

$personeller = $db->fetchAll("
    SELECT
        k.kullanici_id,
        k.kullanici_ad + ' ' + k.kullanici_soyad + CASE WHEN k.kullanici_durum = 0 THEN ' (Pasif)' ELSE '' END as personel_adi
    FROM kullanicilar k
    ORDER BY k.kullanici_durum DESC, k.kullanici_ad, k.kullanici_soyad
");
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
        .gonderim-log { max-height: 220px; overflow-y: auto; font-size: .85rem; }
        .karakter-sayac.uyari { color: #dc3545; font-weight: 600; }
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

                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-sim"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hat</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-patch-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Onaylandı</span>
                                    <span class="info-box-number" id="stat-onaylandi">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Yanıt Bekliyor</span>
                                    <span class="info-box-number" id="stat-bekliyor">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-octagon"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İncelenecek</span>
                                    <span class="info-box-number" id="stat-incelenecek">0</span>
                                    <span class="info-box-text text-muted" style="font-size:.75rem">Cevapsız + Farklı + Gönderilemedi</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Donem ve Gonderim -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-send"></i> Dönem ve Gönderim</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#ayarCollapse" title="Ayarlar">
                                    <i class="bi bi-gear"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-2">
                                    <label class="form-label">Dönem</label>
                                    <input type="month" class="form-control" id="donem" value="<?= htmlspecialchars($aktifDonem) ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Parti Boyutu</label>
                                    <input type="number" class="form-control" id="parti_boyutu" value="10" min="1" max="50">
                                </div>
                                <div class="col-md-8 text-md-end">
                                    <button type="button" class="btn btn-outline-secondary" id="btnOnizleme">
                                        <i class="bi bi-eye"></i> Kimlere Gidecek?
                                    </button>
                                    <?php if ($pagePermissions['can_add']): ?>
                                    <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalTest">
                                        <i class="bi bi-phone"></i> Test Mesajı
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($pagePermissions['can_add']): ?>
                                    <button type="button" class="btn btn-primary" id="btnDonemBaslat">
                                        <i class="bi bi-play-circle"></i> Dönemi Hazırla
                                    </button>
                                    <button type="button" class="btn btn-outline-success btn-gonder" data-test="1">
                                        <i class="bi bi-clipboard-check"></i> Kuru Çalıştır
                                    </button>
                                    <button type="button" class="btn btn-success btn-gonder" data-test="0">
                                        <i class="bi bi-send"></i> Gerçek Gönderim
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Ilerleme -->
                            <div class="mt-3 d-none" id="gonderimPanel">
                                <div class="progress" style="height: 22px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="gonderimBar" style="width:0%">0%</div>
                                </div>
                                <div class="mt-2 small text-muted" id="gonderimOzet"></div>
                                <div class="gonderim-log border rounded p-2 mt-2 bg-body-tertiary d-none" id="gonderimLog"></div>
                            </div>

                            <!-- Ayarlar -->
                            <div class="collapse mt-3" id="ayarCollapse">
                                <hr>
                                <form id="ayarForm">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label">Gönderim Kanalı</label>
                                            <select class="form-select" id="ayar_kanal" name="kanal">
                                                <option value="SMS" <?= ($ayarlar['kanal'] ?? 'SMS') === 'SMS' ? 'selected' : '' ?>>SMS</option>
                                                <option value="WHATSAPP" <?= ($ayarlar['kanal'] ?? '') === 'WHATSAPP' ? 'selected' : '' ?>>WhatsApp</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bekleme (gün)</label>
                                            <input type="number" class="form-control" id="ayar_bekleme_gun" name="bekleme_gun" min="1" max="60"
                                                   value="<?= htmlspecialchars($ayarlar['bekleme_gun'] ?? '7') ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Max Deneme</label>
                                            <input type="number" class="form-control" id="ayar_max_deneme" name="max_deneme" min="1" max="10"
                                                   value="<?= htmlspecialchars($ayarlar['max_deneme'] ?? '3') ?>">
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label">Onay Sayfası Adresi</label>
                                            <input type="text" class="form-control" id="ayar_link_base" name="link_base"
                                                   value="<?= htmlspecialchars($ayarlar['link_base'] ?? '') ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">
                                                Mesaj Şablonu
                                                <span class="text-muted">— değişkenler: <code>{link}</code> <code>{ad}</code> <code>{hat_no}</code> <code>{donem}</code></span>
                                            </label>
                                            <textarea class="form-control" id="ayar_mesaj_sablonu" name="mesaj_sablonu" rows="3"><?= htmlspecialchars($ayarlar['mesaj_sablonu'] ?? '') ?></textarea>
                                            <small class="karakter-sayac text-muted" id="sablonSayac"></small>
                                        </div>
                                        <div class="col-12">
                                            <?php if ($pagePermissions['can_edit']): ?>
                                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Ayarları Kaydet</button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnMesajOnizle">
                                                <i class="bi bi-chat-text"></i> Mesajı Önizle
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="collapse" id="filterCollapse">
                            <div class="card-body">
                                <form id="filterForm">
                                    <div class="row g-3">
                                        <div class="col-md-2">
                                            <label class="form-label">Dönem</label>
                                            <select class="form-select" id="filter_donem">
                                                <option value="">Tümü</option>
                                                <?php foreach ($donemler as $d): ?>
                                                    <option value="<?= htmlspecialchars($d['zimmet_onay_donem']) ?>"><?= htmlspecialchars($d['zimmet_onay_donem']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Durum</label>
                                            <select class="form-select" id="filter_durum_kodu">
                                                <option value="">Tümü</option>
                                                <?php foreach ($durumlar as $d): ?>
                                                    <option value="<?= htmlspecialchars($d['zimmet_onay_durum_kodu']) ?>"><?= htmlspecialchars($d['zimmet_onay_durum_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Personel</label>
                                            <select class="form-select" id="filter_personel_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($personeller as $p): ?>
                                                    <option value="<?= $p['kullanici_id'] ?>"><?= htmlspecialchars($p['personel_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Aksiyon</label>
                                            <select class="form-select" id="filter_aksiyon">
                                                <option value="">Tümü</option>
                                                <option value="YOK">İşlem Yapılmadı</option>
                                                <option value="HAVUZA_ALINDI">Havuza Alındı</option>
                                                <option value="DEVREDILDI">Devredildi</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-check form-switch mt-4">
                                                <input class="form-check-input" type="checkbox" id="filter_cevapsiz">
                                                <label class="form-check-label" for="filter_cevapsiz">Sadece Cevapsız</label>
                                            </div>
                                        </div>
                                        <div class="col-md-10">
                                            <input type="text" class="form-control" id="filter_arama" placeholder="Hat no veya personel adı ile ara...">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrele</button>
                                        </div>
                                        <div class="col-12">
                                            <button type="button" class="btn btn-secondary btn-sm" id="clearFilters">
                                                <i class="bi bi-x-circle"></i> Temizle
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Liste -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> Onay Kayıtları</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover align-middle" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th width="60">Dönem</th>
                                            <th width="110">Hat No</th>
                                            <th>Zimmetli Personel</th>
                                            <th>Çalıştığı Departman</th>
                                            <th width="130">Durum</th>
                                            <th width="130">Gönderim</th>
                                            <th width="130">Yanıt</th>
                                            <th>Not</th>
                                            <th width="150">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr><td colspan="9" class="text-center">Yükleniyor...</td></tr>
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

    <!-- Modal: Test Mesaji -->
    <div class="modal fade" id="modalTest" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-phone"></i> Test Mesajı Gönder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="testForm">
                    <div class="modal-body">
                        <div class="alert alert-warning py-2 small">
                            <i class="bi bi-exclamation-triangle"></i>
                            Bu işlem <strong>gerçek SMS</strong> gönderir ve ücretlendirilir.
                            Kayıt <code>TEST</code> döneminde tutulur, aylık istatistikleri etkilemez.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Hedef Numara <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="test_numara" placeholder="5551234567" required>
                            <small class="text-muted">Mesaj bu numaraya gider.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Doğrulanacak Personel <span class="text-danger">*</span></label>
                            <select class="form-select" id="test_personel_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($personeller as $p): ?>
                                    <option value="<?= $p['kullanici_id'] ?>"><?= htmlspecialchars($p['personel_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Onay sayfasında bu personelin TC kimlik no'su kabul edilecek.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" class="btn btn-info"><i class="bi bi-send"></i> Test Mesajı Gönder</button>
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
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {
        const AKTIF_DONEM = <?= json_encode($aktifDonem) ?>;
        const CAN_EDIT = <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>;
        // Devir modalindeki personel dropdown'i icin
        const PERSONELLER = <?= json_encode($personeller, JSON_UNESCAPED_UNICODE) ?>;

        $('#filter_personel_id, #filter_durum_kodu, #filter_donem, #filter_aksiyon').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });

        function escapeHtml(s) {
            return $('<div>').text(s == null ? '' : s).html();
        }

        // HTML attribute icine yazarken tirnaklar da kacirilmali
        function escapeAttr(s) {
            return escapeHtml(s).replace(/"/g, '&quot;');
        }

        // ---------- Istatistik ----------
        function loadStats() {
            $.post('', { action: 'stats', donem: $('#donem').val() }, function (res) {
                if (!res.success) return;
                const d = res.data;
                $('#stat-toplam').text(d.toplam);
                $('#stat-onaylandi').text(d.onaylandi);
                $('#stat-bekliyor').text(d.bekliyor);
                $('#stat-incelenecek').text(d.cevapsiz + d.farkli_personel + d.gonderilmedi);
            }, 'json');
        }

        // ---------- Liste ----------
        // Tablo satirlari elle basildigi icin DataTable her yenilemede kurulur.
        // Arama/filtre kendi panelimizde oldugundan searching kapali.
        let dt = null;

        function tabloyuKur() {
            if (dt) { dt.destroy(); dt = null; }
            dt = $('#dataTable').DataTable({
                searching: false,
                paging: true,
                info: true,
                order: [],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']],
                columnDefs: [{ orderable: false, targets: [8] }],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
            });
        }

        function tabloyuBosalt() {
            if (dt) { dt.destroy(); dt = null; }
        }

        function loadList() {
            $.post('', {
                action: 'list',
                // Bos birakilirsa TUM donemler listelenir (TEST kayitlari dahil)
                donem: $('#filter_donem').val(),
                durum_kodu: $('#filter_durum_kodu').val(),
                personel_id: $('#filter_personel_id').val(),
                aksiyon: $('#filter_aksiyon').val(),
                arama: $('#filter_arama').val(),
                cevapsiz: $('#filter_cevapsiz').is(':checked') ? 1 : 0
            }, function (res) {
                // Satirlari degistirmeden once DataTable yikilmali
                tabloyuBosalt();
                const tbody = $('#tableBody').empty();
                if (!res.success || !res.data.length) {
                    tbody.append('<tr><td colspan="9" class="text-center text-muted">Kayıt bulunamadı. Dönemi hazırlamak için üstteki butonu kullanın.</td></tr>');
                    return;
                }

                res.data.forEach(function (r) {
                    const renk = r.zimmet_onay_durum_renk || 'secondary';
                    const durumAdi = r.zimmet_onay_durum_adi || r.zimmet_onay_durum_kodu;

                    // Hattin kayit anindaki sistem durumu
                    const havuzda = (r.mevcut_durum === 'HAVUZDA');
                    let hatNo = escapeHtml(r.zimmet_onay_hat_no);
                    hatNo += havuzda
                        ? '<div><span class="badge text-bg-secondary" title="Kayıt açılırken sistemde sahipsiz görünüyordu">Havuzda</span></div>'
                        : '<div><span class="badge text-bg-light" title="Kayıt açılırken personele zimmetli görünüyordu">Zimmetli</span></div>';

                    let personel;
                    if (havuzda) {
                        personel = '<span class="text-muted fst-italic">Sistemde sahipsiz</span>';
                    } else {
                        personel = escapeHtml(r.personel_adi || '-');
                        if (r.personel_durum === 0 || r.personel_durum === false) {
                            personel += ' <span class="badge text-bg-dark">Pasif</span>';
                        }
                        if (r.tc_eksik == 1) {
                            personel += ' <span class="badge text-bg-warning" title="TC kaydı eksik, kendi TC si ile onaylayamaz">TC yok</span>';
                        }
                    }

                    let gonderim = '-';
                    if (r.zimmet_onay_gonderildi) {
                        gonderim = escapeHtml(r.gonderim_tarihi || '');
                        if (r.gecen_gun !== null && r.zimmet_onay_durum_kodu === 'BEKLIYOR') {
                            gonderim += ' <span class="badge text-bg-light">' + r.gecen_gun + ' gün</span>';
                        }
                    }

                    let not = '';
                    if (r.zimmet_onay_durum_kodu === 'FARKLI_PERSONEL' && r.gercek_kullanici_adi) {
                        not = '<span class="text-danger">Gerçek kullanıcı: <strong>' + escapeHtml(r.gercek_kullanici_adi) + '</strong></span>';
                        // Havuzda gorunen hatta yanit gelmesi = teslim kaydi girilmeyi unutulmus
                        if (havuzda) {
                            not += '<div class="small text-danger"><i class="bi bi-exclamation-triangle"></i> Teslim kaydı girilmemiş — devir ile tamamlayın.</div>';
                        }
                    } else if (r.zimmet_onay_gonderim_hata) {
                        not = '<span class="text-muted">' + escapeHtml(r.zimmet_onay_gonderim_hata) + '</span>';
                    }
                    if (r.zimmet_onay_kilit) {
                        not += ' <span class="badge text-bg-danger">Kilitli</span>';
                    }
                    // Eslesmeyen TC: hatti kullanan kisi sistemde kayitli degil
                    if (r.zimmet_onay_girilen_tc && r.zimmet_onay_durum_kodu !== 'FARKLI_PERSONEL') {
                        not += '<div class="small text-warning">Eşleşmeyen TC girildi: <code>' +
                               escapeHtml(r.zimmet_onay_girilen_tc) + '</code> (' + (r.zimmet_onay_deneme_sayisi || 0) + ' deneme)</div>';
                    }
                    if (r.zimmet_onay_aksiyon && r.zimmet_onay_aksiyon !== 'YOK') {
                        const aksiyonAdi = r.zimmet_onay_aksiyon === 'HAVUZA_ALINDI' ? 'Havuza Alındı' : 'Devredildi';
                        not += ' <span class="badge text-bg-info">' + aksiyonAdi + '</span>';
                    }

                    // Islem butonlari
                    let islem = '';
                    if (CAN_EDIT && r.zimmet_onay_aksiyon === 'YOK') {
                        // Henuz yanit alinmamis kayitlar IK tarafindan manuel onaylanabilir
                        if (['ONAYLANDI', 'FARKLI_PERSONEL', 'KULLANILMIYOR'].indexOf(r.zimmet_onay_durum_kodu) === -1) {
                            islem += '<button class="btn btn-sm btn-success btn-manuel-onay me-1" data-id="' + r.zimmet_onay_id +
                                     '" data-personel="' + escapeAttr(r.personel_adi || '-') +
                                     '" data-hat="' + escapeAttr(r.zimmet_onay_hat_no) +
                                     '" title="Manuel onayla"><i class="bi bi-check2-circle"></i></button>';
                        }
                        if (r.zimmet_onay_durum_kodu === 'FARKLI_PERSONEL' && r.zimmet_onay_girilen_kullanici_id) {
                            islem += '<button class="btn btn-sm btn-warning btn-devret me-1" data-id="' + r.zimmet_onay_id + '" title="Doğrulanan personele devret"><i class="bi bi-arrow-left-right"></i></button>';
                        }
                        // Serbest devir: IK istedigi personeli secer, kayit onayli kapanir
                        if (r.zimmet_onay_durum_kodu !== 'ONAYLANDI') {
                            islem += '<button class="btn btn-sm btn-primary btn-personele-devret me-1" data-id="' + r.zimmet_onay_id +
                                     '" data-hat="' + escapeAttr(r.zimmet_onay_hat_no) +
                                     '" title="Seçilen personele devret ve onayla"><i class="bi bi-person-check"></i></button>';
                        }
                        // Zaten havuzda gorunen hatta iade satiri yazilamaz
                        if (r.zimmet_onay_durum_kodu !== 'ONAYLANDI' && !havuzda) {
                            islem += '<button class="btn btn-sm btn-danger btn-havuz me-1" data-id="' + r.zimmet_onay_id + '" title="Havuza al (iade)"><i class="bi bi-box-arrow-in-left"></i></button>';
                        }
                        if (r.zimmet_onay_durum_kodu === 'BEKLIYOR') {
                            islem += '<button class="btn btn-sm btn-outline-primary btn-tekrar" data-id="' + r.zimmet_onay_id + '" title="Tekrar gönder"><i class="bi bi-arrow-repeat"></i></button>';
                        }
                        if (r.zimmet_onay_kilit) {
                            islem += '<button class="btn btn-sm btn-outline-warning btn-kilit ms-1" data-id="' + r.zimmet_onay_id + '" title="Kilidi aç"><i class="bi bi-unlock"></i></button>';
                        }
                    }

                    tbody.append(
                        '<tr>' +
                        '<td>' + escapeHtml(r.zimmet_onay_donem) + '</td>' +
                        '<td>' + hatNo + '</td>' +
                        '<td>' + personel + '</td>' +
                        '<td>' + escapeHtml(r.departman_adi || '-') + '</td>' +
                        '<td><span class="badge text-bg-' + renk + '">' + escapeHtml(durumAdi) + '</span></td>' +
                        '<td>' + gonderim + '</td>' +
                        '<td>' + escapeHtml(r.yanit_tarihi || '-') + '</td>' +
                        '<td>' + not + '</td>' +
                        '<td>' + (islem || '-') + '</td>' +
                        '</tr>'
                    );
                });

                tabloyuKur();
            }, 'json');
        }

        // ---------- Onizleme ----------
        $('#btnOnizleme').on('click', function () {
            $.post('', { action: 'onizleme' }, function (res) {
                if (!res.success) { showToast(res.message || 'Hata', 'error'); return; }
                const d = res.data;
                let html = '<div class="mb-2 text-start">Toplam <strong>' + d.toplam + '</strong> hat — ' +
                           'gönderilecek: <strong class="text-success">' + d.gonderilecek + '</strong>, ' +
                           'gönderilemeyecek: <strong class="text-danger">' + d.atlanacak + '</strong></div>' +
                           '<div class="mb-3 text-start small">' +
                           '<span class="badge text-bg-light">Zimmetli: ' + d.zimmetli + '</span> ' +
                           '<span class="badge text-bg-secondary">Havuzda: ' + d.havuzda + '</span>' +
                           '<div class="text-muted mt-1">Havuzda görünen hatlara da mesaj gider; yanıt gelirse teslim kaydı girilmemiş demektir.</div>' +
                           '</div>' +
                           '<div style="max-height:320px;overflow:auto"><table class="table table-sm table-bordered"><thead><tr><th>Hat</th><th>Kaynak</th><th>Personel</th><th>Durum</th></tr></thead><tbody>';
                d.liste.forEach(function (a) {
                    let durum = a.gonderilebilir
                        ? '<span class="text-success">Gönderilecek</span>'
                        : '<span class="text-danger">' + escapeHtml(a.atlama_nedeni) + '</span>';
                    if (a.uyari) {
                        durum += '<div class="text-warning small">' + escapeHtml(a.uyari) + '</div>';
                    }
                    const kaynak = a.mevcut_durum === 'HAVUZDA'
                        ? '<span class="badge text-bg-secondary">Havuzda</span>'
                        : '<span class="badge text-bg-light">Zimmetli</span>';
                    html += '<tr><td>' + escapeHtml(a.hat_no || a.stok_hareket_seri_no) + '</td><td>' + kaynak + '</td><td>' +
                            escapeHtml(a.personel_adi || '-') + '</td><td>' + durum + '</td></tr>';
                });
                html += '</tbody></table></div>';
                Swal.fire({ title: 'Gönderim Önizlemesi', html: html, width: 800, confirmButtonText: 'Kapat' });
            }, 'json');
        });

        // ---------- Donem hazirla ----------
        $('#btnDonemBaslat').on('click', function () {
            const donem = $('#donem').val();
            Swal.fire({
                title: 'Dönem hazırlansın mı?',
                text: donem + ' dönemi için onay kayıtları oluşturulacak. Mesaj gönderilmez.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Hazırla',
                cancelButtonText: 'Vazgeç'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'donem_baslat', donem: donem }, function (res) {
                    showToast(res.message, res.success ? 'success' : 'error');
                    loadStats(); loadList();
                }, 'json');
            });
        });

        // ---------- Parti parti gonderim ----------
        let gonderimDevam = false;

        function partiGonder(toplamHedef, gonderilenToplam, testMode) {
            $.post('', {
                action: 'gonder_partisi',
                donem: $('#donem').val(),
                limit: $('#parti_boyutu').val(),
                test_mode: testMode ? 1 : 0
            }, function (res) {
                if (!res.success) {
                    gonderimDevam = false;
                    $('.btn-gonder').prop('disabled', false);
                    showToast(res.message || 'Gönderim hatası', 'error');
                    return;
                }

                const d = res.data;
                gonderilenToplam += d.islenen;

                (d.detay || []).forEach(function (x) {
                    const renk = x.durum === 'basarisiz' ? 'text-danger' : (x.durum === 'test' ? 'text-info' : 'text-success');
                    $('#gonderimLog').removeClass('d-none').append(
                        '<div class="' + renk + '">' + escapeHtml(x.hat_no) + ' — ' + escapeHtml(x.personel || '') +
                        (x.hata ? ' — ' + escapeHtml(x.hata) : '') + '</div>'
                    );
                });
                $('#gonderimLog').scrollTop($('#gonderimLog')[0].scrollHeight);

                const yuzde = toplamHedef > 0 ? Math.min(100, Math.round(gonderilenToplam / toplamHedef * 100)) : 100;
                $('#gonderimBar').css('width', yuzde + '%').text(yuzde + '%');
                $('#gonderimOzet').text(gonderilenToplam + ' / ' + toplamHedef + ' işlendi — kalan: ' + d.kalan);

                loadStats();

                // Islenen 0 ise dur (basarisizlar sonsuz donguye girmesin)
                if (d.kalan > 0 && d.islenen > 0 && gonderimDevam) {
                    partiGonder(toplamHedef, gonderilenToplam, testMode);
                } else {
                    gonderimDevam = false;
                    $('.btn-gonder').prop('disabled', false);
                    $('#gonderimBar').removeClass('progress-bar-animated');
                    showToast('Gönderim tamamlandı. Kalan: ' + d.kalan, d.kalan > 0 ? 'warning' : 'success');
                    loadList();
                }
            }, 'json').fail(function () {
                gonderimDevam = false;
                $('.btn-gonder').prop('disabled', false);
                showToast('Sunucu hatası - gönderim durdu. Butona tekrar basarak kaldığı yerden devam edebilirsiniz.', 'error');
            });
        }

        $('.btn-gonder').on('click', function () {
            const testMode = $(this).data('test') == 1;
            Swal.fire({
                title: testMode ? 'Kuru çalıştırma' : 'GERÇEK GÖNDERİM',
                html: testMode
                    ? '<strong>Gerçek mesaj gönderilmeyecek.</strong> Kayıtlar işaretlenip akış test edilecek.'
                    : '<strong class="text-danger">Gerçek SMS gönderilecek ve ücretlendirilecektir.</strong><br>Devam edilsin mi?',
                icon: testMode ? 'info' : 'warning',
                showCancelButton: true,
                confirmButtonText: testMode ? 'Kuru Çalıştır' : 'Evet, gerçek SMS gönder',
                confirmButtonColor: testMode ? '#198754' : '#dc3545',
                cancelButtonText: 'Vazgeç'
            }).then(function (r) {
                if (!r.isConfirmed) return;

                $.post('', { action: 'stats', donem: $('#donem').val() }, function (res) {
                    const hedef = res.success ? res.data.gonderilecek : 0;
                    if (hedef <= 0) {
                        showToast('Gönderilecek kayıt yok. Önce "Dönemi Hazırla" butonunu kullanın.', 'warning');
                        return;
                    }
                    $('#gonderimPanel').removeClass('d-none');
                    $('#gonderimLog').empty().addClass('d-none');
                    $('#gonderimBar').addClass('progress-bar-animated').css('width', '0%').text('0%');
                    $('.btn-gonder').prop('disabled', true);
                    $('#gonderimOzet').text(testMode ? 'Kuru çalıştırma başladı...' : 'Gerçek gönderim başladı...');
                    gonderimDevam = true;
                    partiGonder(hedef, 0, testMode);
                }, 'json');
            });
        });

        // ---------- Satir aksiyonlari ----------
        $('#tableBody').on('click', '.btn-manuel-onay', function () {
            const id = $(this).data('id');
            const personel = $(this).data('personel');
            const hat = $(this).data('hat');
            Swal.fire({
                title: 'Manuel onay verilsin mi?',
                html: '<strong>' + escapeHtml(hat) + '</strong> numaralı hat <strong>' + escapeHtml(personel) + '</strong> adına onaylanmış sayılacak.' +
                      '<div class="text-muted small mt-2">Personel TC doğrulaması yapmadan kayıt kapanır. İşlemi yapan kullanıcı kayda yazılır.</div>',
                icon: 'question', showCancelButton: true,
                confirmButtonText: 'Onayla', confirmButtonColor: '#198754', cancelButtonText: 'Vazgeç'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'manuel_onay', id: id }, function (res) {
                    showToast(res.message, res.success ? 'success' : 'error');
                    loadStats(); loadList();
                }, 'json');
            });
        });

        $('#tableBody').on('click', '.btn-havuz', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Hat havuza alınsın mı?',
                text: 'Stok hareketine iade kaydı düşecek ve hat boşa çıkacak.',
                icon: 'warning', showCancelButton: true,
                confirmButtonText: 'Havuza Al', cancelButtonText: 'Vazgeç'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'havuza_al', id: id }, function (res) {
                    showToast(res.message, res.success ? 'success' : 'error');
                    loadStats(); loadList();
                }, 'json');
            });
        });

        $('#tableBody').on('click', '.btn-devret', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Devir yapılsın mı?',
                text: 'Hat, TC doğrulaması yapan personele aktarılacak (iade + teslim kaydı).',
                icon: 'question', showCancelButton: true,
                confirmButtonText: 'Devret', cancelButtonText: 'Vazgeç'
            }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'devret', id: id }, function (res) {
                    showToast(res.message, res.success ? 'success' : 'error');
                    loadStats(); loadList();
                }, 'json');
            });
        });

        // Serbest devir: IK istedigi personeli secer, hat devredilir ve kayit ONAYLANDI kapanir
        $('#tableBody').on('click', '.btn-personele-devret', function () {
            const id  = $(this).data('id');
            const hat = $(this).data('hat');

            let secenekler = '<option value="">Seçiniz...</option>';
            PERSONELLER.forEach(function (p) {
                secenekler += '<option value="' + p.kullanici_id + '">' + escapeHtml(p.personel_adi) + '</option>';
            });

            Swal.fire({
                title: 'Personele Devret',
                html: '<div class="text-start">' +
                      '<p class="mb-2"><strong>' + escapeHtml(hat) + '</strong> numaralı hat seçtiğiniz personele zimmetlenecek ' +
                      've kayıt <strong>onaylanmış</strong> olarak kapanacak.</p>' +
                      '<label class="form-label">Zimmetlenecek Personel</label>' +
                      '<select id="swal_personel" class="form-select">' + secenekler + '</select>' +
                      '<div class="text-muted small mt-2">Gerekiyorsa eski personelden iade, seçilen personele teslim kaydı otomatik yazılır.</div>' +
                      '</div>',
                showCancelButton: true,
                confirmButtonText: 'Devret ve Onayla',
                cancelButtonText: 'Vazgeç',
                width: 520,
                didOpen: function () {
                    $('#swal_personel').select2({
                        theme: 'bootstrap-5',
                        width: '100%',
                        dropdownParent: $('.swal2-popup')
                    });
                },
                preConfirm: function () {
                    const secilen = $('#swal_personel').val();
                    if (!secilen) {
                        Swal.showValidationMessage('Personel seçmelisiniz.');
                        return false;
                    }
                    return secilen;
                }
            }).then(function (r) {
                if (!r.isConfirmed) return;
                $.post('', { action: 'personele_devret', id: id, personel_id: r.value }, function (res) {
                    showToast(res.message, res.success ? 'success' : 'error');
                    loadStats(); loadList();
                }, 'json');
            });
        });

        $('#tableBody').on('click', '.btn-kilit', function () {
            $.post('', { action: 'kilit_ac', id: $(this).data('id') }, function (res) {
                showToast(res.message, res.success ? 'success' : 'error');
                loadList();
            }, 'json');
        });

        $('#tableBody').on('click', '.btn-tekrar', function () {
            $.post('', { action: 'tekrar_gonder', id: $(this).data('id') }, function (res) {
                showToast(res.message, res.success ? 'success' : 'error');
                loadList();
            }, 'json');
        });

        // ---------- Test gonderimi ----------
        $('#test_personel_id').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalTest') });

        $('#testForm').on('submit', function (e) {
            e.preventDefault();
            const btn = $(this).find('button[type=submit]').prop('disabled', true);

            $.post('', {
                action: 'test_gonderimi',
                numara: $('#test_numara').val(),
                personel_id: $('#test_personel_id').val()
            }, function (res) {
                btn.prop('disabled', false);

                if (!res.success) {
                    showToast(res.message, 'error');
                    return;
                }

                bootstrap.Modal.getInstance(document.getElementById('modalTest')).hide();

                // Filtreyi TEST donemine al ki kayit hemen listede gorunsun
                if (!$('#filter_donem option[value="TEST"]').length) {
                    $('#filter_donem').append(new Option('TEST', 'TEST'));
                }
                $('#filter_donem').val('TEST').trigger('change');

                Swal.fire({
                    icon: 'success',
                    title: 'Test mesajı gönderildi',
                    html: '<div class="text-start small">' +
                          '<div class="mb-2"><strong>Giden mesaj:</strong><div class="border rounded p-2 bg-body-tertiary">' + escapeHtml(res.mesaj) + '</div></div>' +
                          '<div class="mb-2"><strong>Link:</strong><br><a href="' + escapeHtml(res.link) + '" target="_blank">' + escapeHtml(res.link) + '</a></div>' +
                          '<div class="text-muted">Onay için girilecek TC: <code>' + escapeHtml(res.tc_ipucu) + '</code></div>' +
                          '</div>',
                    width: 620
                });
                loadList();
            }, 'json').fail(function () {
                btn.prop('disabled', false);
                showToast('Sunucu hatası', 'error');
            });
        });

        // ---------- Ayarlar ----------
        function sablonSayac() {
            const metin = $('#ayar_mesaj_sablonu').val() || '';
            // {link} yerine ornek link uzunlugu (base + 35) hesaba katilir
            const linkUzunluk = ($('#ayar_link_base').val() || '').length + 35;
            const tahmini = metin.replace('{link}', 'x'.repeat(linkUzunluk)).length;
            const turkce = /[çğıöşüÇĞİÖŞÜ]/.test(metin);
            const limit = turkce ? 70 : 160;
            const kredi = Math.ceil(tahmini / limit);
            $('#sablonSayac')
                .text('Tahmini uzunluk: ' + tahmini + ' karakter → ' + kredi + ' SMS kredisi' + (turkce ? ' (Türkçe karakter var, limit 70)' : ''))
                .toggleClass('uyari', kredi > 1);
        }
        $('#ayar_mesaj_sablonu, #ayar_link_base').on('input', sablonSayac);
        sablonSayac();

        $('#ayarForm').on('submit', function (e) {
            e.preventDefault();
            $.post('', {
                action: 'ayar_kaydet',
                kanal: $('#ayar_kanal').val(),
                bekleme_gun: $('#ayar_bekleme_gun').val(),
                max_deneme: $('#ayar_max_deneme').val(),
                link_base: $('#ayar_link_base').val(),
                mesaj_sablonu: $('#ayar_mesaj_sablonu').val()
            }, function (res) {
                showToast(res.message, res.success ? 'success' : 'error');
            }, 'json');
        });

        $('#btnMesajOnizle').on('click', function () {
            $.post('', { action: 'mesaj_onizle', donem: $('#donem').val() }, function (res) {
                if (!res.success) return;
                Swal.fire({
                    title: 'Mesaj Önizleme',
                    html: '<div class="border rounded p-3 text-start bg-body-tertiary">' + escapeHtml(res.data.mesaj) + '</div>' +
                          '<div class="mt-2 small text-muted">' + res.data.uzunluk + ' karakter</div>',
                    confirmButtonText: 'Kapat'
                });
            }, 'json');
        });

        // ---------- Filtre ----------
        $('#filterForm').on('submit', function (e) { e.preventDefault(); loadList(); });
        $('#clearFilters').on('click', function () {
            $('#filter_donem, #filter_durum_kodu, #filter_personel_id, #filter_aksiyon').val('').trigger('change');
            $('#filter_arama').val('');
            $('#filter_cevapsiz').prop('checked', false);
            loadList();
        });
        // Ust paneldeki donem yalnizca InfoBox ve gonderimi etkiler
        $('#donem').on('change', loadStats);
        // Filtre donemi degisince liste yenilenir
        $('#filter_donem').on('change', loadList);

        loadStats();
        loadList();
    });
    </script>
</body>
</html>
