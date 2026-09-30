<?php
/**
 * Admin Panel - Ödeme Hareketi Ekleme/Düzenleme Formu
 * Yetkiler odeme-hareketleri.php sayfasına bağlıdır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü - Ana sayfanın yetkilerini kullan
$parentPageFile = 'odeme-hareketleri.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $parentPageFile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// ID varsa düzenleme modu
$editMode = isset($_GET['id']) && intval($_GET['id']) > 0;
$hareketId = $editMode ? intval($_GET['id']) : 0;
$hareket = null;

// Düzenleme modunda yetki kontrolü
if ($editMode && !$pagePermissions['can_edit']) {
    PageAuth::accessDenied('Düzenleme yetkiniz bulunmamaktadır.');
}

// Ekleme modunda yetki kontrolü
if (!$editMode && !$pagePermissions['can_add']) {
    PageAuth::accessDenied('Ekleme yetkiniz bulunmamaktadır.');
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'kaydet':
                $id = intval($_POST['id'] ?? 0);

                // Yetki kontrolü
                if ($id > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    exit;
                }
                if ($id == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    exit;
                }

                $tip_id = intval($_POST['tip_id'] ?? 0);
                $taraf_tipi = $_POST['taraf_tipi'] ?? 'PERSONEL';
                $kullanici_id = !empty($_POST['kullanici_id']) ? intval($_POST['kullanici_id']) : null;
                $cari_id = !empty($_POST['cari_id']) ? intval($_POST['cari_id']) : null;
                $yontem_id = !empty($_POST['yontem_id']) ? intval($_POST['yontem_id']) : null;
                $kasa_id = !empty($_POST['kasa_id']) ? intval($_POST['kasa_id']) : null;
                $banka_hesap_id = !empty($_POST['banka_hesap_id']) ? intval($_POST['banka_hesap_id']) : null;
                $belge_no = trim($_POST['belge_no'] ?? '');
                $tarih = $_POST['tarih'] ?? '';
                $tutar = $_POST['tutar'] ?? 0;
                $aciklama = trim($_POST['aciklama'] ?? '');
                $evrak = trim($_POST['evrak'] ?? '');

                // Validasyon
                if (!$tip_id) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme tipi seçiniz']);
                    exit;
                }

                if (!in_array($taraf_tipi, ['PERSONEL', 'CARI'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Taraf tipi seçiniz']);
                    exit;
                }

                if ($taraf_tipi === 'PERSONEL') {
                    if (!$kullanici_id) {
                        echo json_encode(['success' => false, 'message' => 'Personel seçiniz']);
                        exit;
                    }
                    $cari_id = null;
                } else {
                    if (!$cari_id) {
                        echo json_encode(['success' => false, 'message' => 'Cari seçiniz']);
                        exit;
                    }
                    $kullanici_id = null;
                }

                if (!$tarih) {
                    echo json_encode(['success' => false, 'message' => 'Tarih giriniz']);
                    exit;
                }

                if ($tutar <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir tutar giriniz']);
                    exit;
                }

                // Ödeme yöntemi: cari hareketlerinde zorunlu, personel hareketlerinde serbest
                $yontem = null;
                if ($yontem_id) {
                    $yontem = $db->fetchOne("
                        SELECT odeme_yontem_id, odeme_yontem_adi, odeme_yontem_hedef_tipi
                        FROM tanim_odeme_yontemleri
                        WHERE odeme_yontem_id = ? AND odeme_yontem_durum = 1
                    ", [$yontem_id]);

                    if (!$yontem) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz ödeme yöntemi seçildi']);
                        exit;
                    }
                }

                if ($taraf_tipi === 'CARI' && !$yontem) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme yöntemi seçiniz (Nakit, Kredi Kartı, Havale/EFT...)']);
                    exit;
                }

                // Yöntemin hedef tipine göre kasa veya banka hesabı zorunludur.
                // İlgisiz hedef alanı temizlenir; nakit hareketi banka hesabına yazılmaz.
                $hedefTipi = $yontem['odeme_yontem_hedef_tipi'] ?? null;

                if ($hedefTipi === 'KASA') {
                    if (!$kasa_id) {
                        echo json_encode(['success' => false, 'message' => $yontem['odeme_yontem_adi'] . ' yönteminde kasa seçimi zorunludur']);
                        exit;
                    }
                    $banka_hesap_id = null;
                } elseif ($hedefTipi === 'BANKA') {
                    if (!$banka_hesap_id) {
                        echo json_encode(['success' => false, 'message' => $yontem['odeme_yontem_adi'] . ' yönteminde banka hesabı seçimi zorunludur']);
                        exit;
                    }
                    $kasa_id = null;
                }

                if ($kasa_id) {
                    $kasaVar = $db->fetchOne("SELECT kasa_id FROM Kasa WHERE kasa_id = ? AND kasa_durum = 1", [$kasa_id]);
                    if (!$kasaVar) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz kasa seçildi']);
                        exit;
                    }
                }

                if ($banka_hesap_id) {
                    $hesapVar = $db->fetchOne("
                        SELECT bankaHesap_id FROM Banka_Hesap
                        WHERE bankaHesap_id = ? AND bankaHesap_durum = 1
                    ", [$banka_hesap_id]);
                    if (!$hesapVar) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz banka hesabı seçildi']);
                        exit;
                    }
                }

                $data = [
                    'odeme_hareket_tip_id' => $tip_id,
                    'odeme_hareket_kullanici_id' => $kullanici_id,
                    'odeme_hareket_cari_id' => $cari_id,
                    'odeme_hareket_yontem_id' => $yontem_id,
                    'odeme_hareket_kasa_id' => $kasa_id,
                    'odeme_hareket_banka_hesap_id' => $banka_hesap_id,
                    'odeme_hareket_belge_no' => $belge_no,
                    'odeme_hareket_tarih' => $tarih,
                    'odeme_hareket_tutar' => $tutar,
                    'odeme_hareket_aciklama' => $aciklama,
                    'odeme_hareket_evrak' => $evrak
                ];

                if ($id > 0) {
                    $data['odeme_hareket_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['odeme_hareket_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    $db->update('Odeme_Hareketleri', $data, ['odeme_hareket_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Ödeme hareketi başarıyla güncellendi']);
                } else {
                    $data['odeme_hareket_olusturan_kullanici_id'] = $user['kullanici_id'];
                    $db->insert('Odeme_Hareketleri', $data);
                    echo json_encode(['success' => true, 'message' => 'Ödeme hareketi başarıyla eklendi']);
                }
                exit;

            case 'upload_evrak':
                if (!isset($_FILES['evrak']) || $_FILES['evrak']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'Dosya yüklenemedi']);
                    exit;
                }

                $file = $_FILES['evrak'];
                $fileName = $file['name'];
                $fileTmp = $file['tmp_name'];
                $fileSize = $file['size'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                // İzin verilen dosya tipleri
                $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];

                if (!in_array($fileExt, $allowedExts)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz dosya türü. İzin verilen: ' . implode(', ', $allowedExts)]);
                    exit;
                }

                // Max 5MB
                if ($fileSize > 5 * 1024 * 1024) {
                    echo json_encode(['success' => false, 'message' => 'Dosya boyutu 5MB\'dan büyük olamaz']);
                    exit;
                }

                // Upload dizini
                $uploadDir = __DIR__ . '/../assets/uploads/odemeler/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Benzersiz dosya adı
                $newFileName = date('YmdHis') . '_' . uniqid() . '.' . $fileExt;
                $uploadPath = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmp, $uploadPath)) {
                    $fileUrl = '/admin/assets/uploads/odemeler/' . $newFileName;
                    echo json_encode(['success' => true, 'url' => $fileUrl, 'message' => 'Dosya başarıyla yüklendi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Dosya sunucuya yüklenemedi']);
                }
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

// Mevcut kaydı çek
if ($editMode) {
    $hareket = $db->fetchOne("
        SELECT
            h.*,
            CONVERT(VARCHAR(10), h.odeme_hareket_tarih, 120) as odeme_hareket_tarih,
            CONVERT(VARCHAR(19), h.odeme_hareket_olusturma_tarihi, 120) as odeme_hareket_olusturma_tarihi,
            CONVERT(VARCHAR(19), h.odeme_hareket_guncelleme_tarihi, 120) as odeme_hareket_guncelleme_tarihi,
            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
        FROM Odeme_Hareketleri h
        LEFT JOIN kullanicilar olusturan ON h.odeme_hareket_olusturan_kullanici_id = olusturan.kullanici_id
        LEFT JOIN kullanicilar guncelleyen ON h.odeme_hareket_guncelleyen_kullanici_id = guncelleyen.kullanici_id
        WHERE h.odeme_hareket_id = ?
    ", [$hareketId]);

    if (!$hareket) {
        header('Location: /admin/odeme-hareketleri?error=notfound');
        exit;
    }
}

// Cari kartından gelindiyse (cari-yonetimi / cari-hareketleri "Tahsilat/Ödeme Gir") cari önseçili gelir
$onSecimCariId = (!$editMode && !empty($_GET['cari_id'])) ? intval($_GET['cari_id']) : 0;

// Seçili taraf tipi
if ($editMode) {
    $tarafTipi = !empty($hareket['odeme_hareket_cari_id']) ? 'CARI' : 'PERSONEL';
} else {
    $tarafTipi = $onSecimCariId > 0 ? 'CARI' : 'PERSONEL';
}

// Formda seçili görünecek cari
$seciliCariId = $editMode ? intval($hareket['odeme_hareket_cari_id'] ?? 0) : $onSecimCariId;

// Cari kartından gelindiyse kayıt sonrası o carinin ekstresine dönülür
$donusUrl = $onSecimCariId > 0
    ? '/admin/pages/cari-hareketleri.php?cari_id=' . $onSecimCariId
    : '/admin/odeme-hareketleri';

// Sayfa başlığı
$pageTitle = $editMode ? 'Ödeme Hareketi Düzenle' : 'Yeni Ödeme Hareketi Ekle';

// Ana sayfa bilgileri (menü adı)
$pageInfo = $db->fetchOne("
    SELECT
        m.menuler_menu_adi as menu_adi,
        s.sayfalar_sayfa_adi as sayfa_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%odeme-hareketleri.php']);

$menuAdi = $pageInfo['menu_adi'] ?? null;
$anaSayfaAdi = $pageInfo['sayfa_adi'] ?? 'Ödeme Hareketleri';

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Dropdown verileri
$personeller = $db->fetchAll("
    SELECT
        k.kullanici_id,
        k.kullanici_ad + ' ' + k.kullanici_soyad + CASE WHEN k.kullanici_durum = 0 THEN ' (Pasif)' ELSE '' END as tam_adi
    FROM kullanicilar k
    LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
    WHERE d.departman_personel = 1
    ORDER BY k.kullanici_durum DESC, k.kullanici_ad, k.kullanici_soyad
");

$cariler = $db->fetchAll("
    SELECT
        cari_id,
        cari_adi + CASE WHEN cari_aktif = 0 THEN ' (Pasif)' ELSE '' END as cari_adi
    FROM Cari
    ORDER BY cari_aktif DESC, cari_adi
");

$odemeTipleri = $db->fetchAll("
    SELECT odeme_tip_id, odeme_tip_adi, odeme_tip_isaret
    FROM Odeme_Tipleri
    WHERE odeme_tip_durum = 1
    ORDER BY odeme_tip_sira, odeme_tip_adi
");

$odemeYontemleri = $db->fetchAll("
    SELECT odeme_yontem_id, odeme_yontem_adi, odeme_yontem_kod, odeme_yontem_hedef_tipi
    FROM tanim_odeme_yontemleri
    WHERE odeme_yontem_durum = 1
    ORDER BY ISNULL(odeme_yontem_sira, 999), odeme_yontem_adi
");

$kasalar = $db->fetchAll("
    SELECT kasa_id, kasa_adi
    FROM Kasa
    WHERE kasa_durum = 1
    ORDER BY ISNULL(kasa_sira_no, 999), kasa_adi
");

$bankaHesaplari = $db->fetchAll("
    SELECT
        h.bankaHesap_id,
        b.banka_adi,
        h.bankaHesap_iban,
        h.bankaHesap_no,
        f.firma_adi
    FROM Banka_Hesap h
    INNER JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
    LEFT JOIN Firmalar f ON h.bankaHesap_firma_id = f.firma_id
    WHERE h.bankaHesap_durum = 1
    ORDER BY b.banka_adi, h.bankaHesap_iban
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
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
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
                                <li class="breadcrumb-item"><a href="/admin/odeme-hareketleri"><?= htmlspecialchars($anaSayfaAdi) ?></a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">

                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-<?= $editMode ? 'pencil-square' : 'plus-circle' ?>"></i>
                                <?= $editMode ? 'Ödeme Hareketi Bilgilerini Düzenle' : 'Yeni Ödeme Hareketi Bilgileri' ?>
                            </h3>
                        </div>
                        <form id="odemeHareketiForm">
                            <input type="hidden" name="id" id="form_id" value="<?= $hareketId ?>">
                            <div class="card-body">
                                <div class="row">
                                    <!-- Taraf Tipi -->
                                    <div class="col-md-6 mb-3">
                                        <label for="form_taraf_tipi" class="form-label">Taraf Tipi <span class="text-danger">*</span></label>
                                        <select class="form-select" name="taraf_tipi" id="form_taraf_tipi" required>
                                            <option value="PERSONEL" <?= $tarafTipi === 'PERSONEL' ? 'selected' : '' ?>>Personel</option>
                                            <option value="CARI" <?= $tarafTipi === 'CARI' ? 'selected' : '' ?>>Cari</option>
                                        </select>
                                    </div>

                                    <!-- Personel -->
                                    <div class="col-md-6 mb-3" id="form_personel_wrap"<?= $tarafTipi === 'CARI' ? ' style="display: none;"' : '' ?>>
                                        <label for="form_kullanici_id" class="form-label">Personel <span class="text-danger">*</span></label>
                                        <select class="form-select" name="kullanici_id" id="form_kullanici_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($personeller as $p): ?>
                                            <option value="<?= $p['kullanici_id'] ?>" <?= ($hareket['odeme_hareket_kullanici_id'] ?? 0) == $p['kullanici_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($p['tam_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Cari -->
                                    <div class="col-md-6 mb-3" id="form_cari_wrap"<?= $tarafTipi === 'PERSONEL' ? ' style="display: none;"' : '' ?>>
                                        <label for="form_cari_id" class="form-label">Cari <span class="text-danger">*</span></label>
                                        <select class="form-select" name="cari_id" id="form_cari_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($cariler as $c): ?>
                                            <option value="<?= $c['cari_id'] ?>" <?= $seciliCariId == $c['cari_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($c['cari_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Ödeme Tipi -->
                                    <div class="col-md-6 mb-3">
                                        <label for="form_tip_id" class="form-label">Ödeme Tipi <span class="text-danger">*</span></label>
                                        <select class="form-select" name="tip_id" id="form_tip_id" required>
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($odemeTipleri as $t): ?>
                                            <option value="<?= $t['odeme_tip_id'] ?>" <?= ($hareket['odeme_hareket_tip_id'] ?? 0) == $t['odeme_tip_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($t['odeme_tip_adi']) ?> <?= $t['odeme_tip_isaret'] == 1 ? '(Gelir +)' : '(Gider -)' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Ödeme Yöntemi -->
                                    <div class="col-md-6 mb-3">
                                        <label for="form_yontem_id" class="form-label">
                                            Ödeme Yöntemi <span class="text-danger" id="form_yontem_zorunlu"<?= $tarafTipi === 'CARI' ? '' : ' style="display: none;"' ?>>*</span>
                                        </label>
                                        <select class="form-select" name="yontem_id" id="form_yontem_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($odemeYontemleri as $y): ?>
                                            <option value="<?= $y['odeme_yontem_id'] ?>"
                                                    data-hedef-tipi="<?= htmlspecialchars($y['odeme_yontem_hedef_tipi'] ?? '') ?>"
                                                    <?= ($hareket['odeme_hareket_yontem_id'] ?? 0) == $y['odeme_yontem_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($y['odeme_yontem_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Nakit, Kredi Kartı, Havale/EFT, Çek veya Senet</small>
                                    </div>

                                    <!-- Kasa (yalnızca hedefi KASA olan yöntemlerde) -->
                                    <div class="col-md-6 mb-3" id="form_kasa_wrap" style="display: none;">
                                        <label for="form_kasa_id" class="form-label">
                                            Kasa <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="kasa_id" id="form_kasa_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($kasalar as $k): ?>
                                            <option value="<?= $k['kasa_id'] ?>" <?= ($hareket['odeme_hareket_kasa_id'] ?? 0) == $k['kasa_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($k['kasa_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">
                                            Kasa tanımları <a href="/admin/pages/kasa-yonetimi.php" target="_blank">Kasa Yönetimi</a> ekranından gelir.
                                            Kasa bakiyesi otomatik güncellenmez.
                                        </small>
                                    </div>

                                    <!-- Banka Hesabı (yalnızca hedefi BANKA olan yöntemlerde) -->
                                    <div class="col-md-6 mb-3" id="form_banka_wrap" style="display: none;">
                                        <label for="form_banka_hesap_id" class="form-label">
                                            Banka Hesabı <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" name="banka_hesap_id" id="form_banka_hesap_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($bankaHesaplari as $bh): ?>
                                            <?php
                                                $hesapEtiket = $bh['banka_adi'];
                                                if (!empty($bh['bankaHesap_iban'])) { $hesapEtiket .= ' - ' . $bh['bankaHesap_iban']; }
                                                elseif (!empty($bh['bankaHesap_no'])) { $hesapEtiket .= ' - ' . $bh['bankaHesap_no']; }
                                                if (!empty($bh['firma_adi'])) { $hesapEtiket .= ' (' . $bh['firma_adi'] . ')'; }
                                            ?>
                                            <option value="<?= $bh['bankaHesap_id'] ?>" <?= ($hareket['odeme_hareket_banka_hesap_id'] ?? 0) == $bh['bankaHesap_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($hesapEtiket) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">
                                            Hesaplar <a href="/admin/pages/banka-iban-yonetimi.php" target="_blank">Banka IBAN Yönetimi</a> ekranından gelir.
                                        </small>
                                    </div>

                                    <!-- Belge No -->
                                    <div class="col-md-6 mb-3">
                                        <label for="form_belge_no" class="form-label">Belge No</label>
                                        <input type="text" class="form-control" name="belge_no" id="form_belge_no" maxlength="50"
                                               placeholder="Makbuz, dekont veya slip numarası"
                                               value="<?= htmlspecialchars($hareket['odeme_hareket_belge_no'] ?? '') ?>">
                                    </div>

                                    <!-- Tarih -->
                                    <div class="col-md-6 mb-3">
                                        <label for="form_tarih" class="form-label">Tarih <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="tarih" id="form_tarih" required
                                               value="<?= htmlspecialchars($hareket['odeme_hareket_tarih'] ?? date('Y-m-d')) ?>">
                                    </div>

                                    <!-- Tutar -->
                                    <div class="col-md-6 mb-3">
                                        <label for="form_tutar" class="form-label">Tutar (₺) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="tutar" id="form_tutar" step="0.01" min="0" required
                                               value="<?= htmlspecialchars($hareket['odeme_hareket_tutar'] ?? '') ?>">
                                    </div>

                                    <!-- Açıklama -->
                                    <div class="col-md-12 mb-3">
                                        <label for="form_aciklama" class="form-label">Açıklama</label>
                                        <textarea class="form-control" name="aciklama" id="form_aciklama" rows="3"><?= htmlspecialchars($hareket['odeme_hareket_aciklama'] ?? '') ?></textarea>
                                    </div>

                                    <!-- Evrak -->
                                    <div class="col-md-12 mb-3">
                                        <label for="form_evrak_file" class="form-label">Evrak Yükle</label>
                                        <input type="file" class="form-control" id="form_evrak_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                                        <small class="form-text text-muted">İzin verilen: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX (Max: 5MB)</small>
                                        <input type="hidden" name="evrak" id="form_evrak" value="<?= htmlspecialchars($hareket['odeme_hareket_evrak'] ?? '') ?>">
                                        <div id="evrak_preview" class="mt-2"<?= empty($hareket['odeme_hareket_evrak']) ? ' style="display: none;"' : '' ?>>
                                            <a href="<?= htmlspecialchars($hareket['odeme_hareket_evrak'] ?? '') ?>" target="_blank" class="badge bg-success text-decoration-none" id="evrak_link">
                                                <i class="bi bi-file-earmark-check"></i> <span id="evrak_filename"><?= htmlspecialchars(basename($hareket['odeme_hareket_evrak'] ?? '')) ?></span>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger" id="remove_evrak">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($editMode): ?>
                                <div class="row">
                                    <div class="col-md-12">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle"></i>
                                            Oluşturan: <strong><?= htmlspecialchars($hareket['olusturan_adi'] ?? '-') ?></strong>
                                            (<?= htmlspecialchars($hareket['odeme_hareket_olusturma_tarihi'] ?? '-') ?>)
                                            <?php if (!empty($hareket['guncelleyen_adi'])): ?>
                                            &nbsp;|&nbsp; Güncelleyen: <strong><?= htmlspecialchars($hareket['guncelleyen_adi']) ?></strong>
                                            (<?= htmlspecialchars($hareket['odeme_hareket_guncelleme_tarihi'] ?? '-') ?>)
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between">
                                    <a href="<?= htmlspecialchars($donusUrl) ?>" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Geri Dön
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> <?= $editMode ? 'Güncelle' : 'Kaydet' ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE -->
<script src="/admin/assets/js/adminlte.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Custom JS -->
<script src="/admin/assets/js/custom.js"></script>

<script>
$(document).ready(function() {
    // Dropdown'ları aranabilir yap
    ['#form_taraf_tipi', '#form_kullanici_id', '#form_cari_id', '#form_tip_id',
     '#form_yontem_id', '#form_kasa_id', '#form_banka_hesap_id'].forEach(function(selector) {
        $(selector).select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Seçiniz...',
            language: {
                noResults: function() { return "Sonuç bulunamadı"; },
                searching: function() { return "Aranıyor..."; }
            }
        });
    });

    // Taraf tipi değişimi
    $('#form_taraf_tipi').on('change', function() {
        applyTarafTipi($(this).val());
    });

    // Ödeme yöntemi değişimi (nakit gibi kasa gerektiren yöntemlerde kasa zorunlu)
    $('#form_yontem_id').on('change', applyYontem);
    applyYontem();

    // Form submit
    $('#odemeHareketiForm').on('submit', function(e) {
        e.preventDefault();
        saveRecord();
    });

    // Evrak yükleme
    $('#form_evrak_file').on('change', function() {
        const file = this.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('action', 'upload_evrak');
        formData.append('evrak', file);

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#form_evrak').val(response.url);
                    $('#evrak_link').attr('href', response.url);
                    $('#evrak_filename').text(file.name);
                    $('#evrak_preview').show();
                    showToast(response.message, 'success');
                } else {
                    showToast(response.message, 'error');
                    $('#form_evrak_file').val('');
                }
            },
            error: function() {
                showToast('Dosya yüklenirken hata oluştu', 'error');
                $('#form_evrak_file').val('');
            }
        });
    });

    // Evrak kaldır
    $('#remove_evrak').on('click', function() {
        $('#form_evrak').val('');
        $('#form_evrak_file').val('');
        $('#evrak_preview').hide();
        showToast('Evrak kaldırıldı', 'info');
    });
});

// Taraf tipine göre alanları göster/gizle
function applyTarafTipi(tip) {
    if (tip === 'CARI') {
        $('#form_personel_wrap').hide();
        $('#form_cari_wrap').show();
        $('#form_kullanici_id').val('').trigger('change.select2');
        $('#form_yontem_zorunlu').show();
    } else {
        $('#form_cari_wrap').hide();
        $('#form_personel_wrap').show();
        $('#form_cari_id').val('').trigger('change.select2');
        $('#form_yontem_zorunlu').hide();
    }
}

// Seçili ödeme yönteminin hedef tipine göre kasa / banka hesabı alanını göster
//   KASA  -> Kasa Yönetimi kayıtları (nakit)
//   BANKA -> Banka IBAN Yönetimi kayıtları (havale / EFT)
function applyYontem() {
    const hedefTipi = $('#form_yontem_id').find('option:selected').data('hedef-tipi') || '';

    if (hedefTipi === 'KASA') {
        $('#form_kasa_wrap').show();
        $('#form_banka_wrap').hide();
        $('#form_banka_hesap_id').val('').trigger('change.select2');
    } else if (hedefTipi === 'BANKA') {
        $('#form_banka_wrap').show();
        $('#form_kasa_wrap').hide();
        $('#form_kasa_id').val('').trigger('change.select2');
    } else {
        $('#form_kasa_wrap').hide();
        $('#form_banka_wrap').hide();
        $('#form_kasa_id').val('').trigger('change.select2');
        $('#form_banka_hesap_id').val('').trigger('change.select2');
    }
}

// Kaydet
function saveRecord() {
    const formData = $('#odemeHareketiForm').serialize() + '&action=kaydet';

    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showSuccess('Başarılı!', response.message);
                setTimeout(function() {
                    window.location.href = <?= json_encode($donusUrl) ?>;
                }, 1200);
            } else {
                showError('Hata!', response.message);
            }
        },
        error: function() {
            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
        }
    });
}
</script>

</div>
</body>
</html>
