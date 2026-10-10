<?php
/**
 * Admin Panel - Ödeme Yöntemleri Yönetimi
 *
 * Ödeme hareketlerinde kullanılan yöntem tanımlarını (Nakit, Kredi Kartı,
 * Havale/EFT, Çek, Senet) yönetir. Yöntem, ödeme tipinden farklıdır:
 *   Ödeme tipi   -> hareketin yönü (tahsilat / ödeme)
 *   Ödeme yöntemi-> tahsilatın veya ödemenin nasıl yapıldığı
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

// AJAX isteklerinde oturum bitmişse JSON dön
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

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ödeme Yöntemleri';
$menuAdi   = $pageInfo['menu_adi'] ?? 'Muhasebe';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $stats = $db->fetchOne("
                    SELECT
                        COUNT(*) as toplam,
                        SUM(CASE WHEN odeme_yontem_durum = 1 THEN 1 ELSE 0 END) as aktif,
                        SUM(CASE WHEN odeme_yontem_durum = 0 THEN 1 ELSE 0 END) as pasif,
                        SUM(CASE WHEN odeme_yontem_hedef_tipi = 'KASA'  THEN 1 ELSE 0 END) as kasa_bagli,
                        SUM(CASE WHEN odeme_yontem_hedef_tipi = 'BANKA' THEN 1 ELSE 0 END) as banka_bagli
                    FROM tanim_odeme_yontemleri
                ");

                $kullanim = $db->fetchOne("
                    SELECT COUNT(*) as sayi
                    FROM Odeme_Hareketleri
                    WHERE odeme_hareket_yontem_id IS NOT NULL AND odeme_hareket_durum = 1
                ");
                $stats['hareket_sayisi'] = $kullanim['sayi'] ?? 0;

                echo json_encode(['success' => true, 'data' => $stats]);
                exit;

            case 'list':
                $durum  = $_POST['durum'] ?? '';
                $search = trim($_POST['search'] ?? '');

                $whereConditions = ["1=1"];
                $params = [];

                if ($durum === '0' || $durum === '1') {
                    $whereConditions[] = "y.odeme_yontem_durum = ?";
                    $params[] = $durum;
                }

                if ($search !== '') {
                    $whereConditions[] = "(y.odeme_yontem_adi LIKE ? OR y.odeme_yontem_kod LIKE ? OR y.odeme_yontem_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }

                $whereClause = implode(" AND ", $whereConditions);

                $list = $db->fetchAll("
                    SELECT
                        y.odeme_yontem_id,
                        y.odeme_yontem_adi,
                        y.odeme_yontem_kod,
                        y.odeme_yontem_hedef_tipi,
                        y.odeme_yontem_renk,
                        y.odeme_yontem_ikon,
                        y.odeme_yontem_sira,
                        y.odeme_yontem_aciklama,
                        y.odeme_yontem_durum,
                        (SELECT COUNT(*) FROM Odeme_Hareketleri h
                          WHERE h.odeme_hareket_yontem_id = y.odeme_yontem_id
                            AND h.odeme_hareket_durum = 1) as hareket_sayisi,
                        CONVERT(VARCHAR(19), y.odeme_yontem_olusturma_tarihi, 120) as odeme_yontem_olusturma_tarihi
                    FROM tanim_odeme_yontemleri y
                    WHERE $whereClause
                    ORDER BY ISNULL(y.odeme_yontem_sira, 999), y.odeme_yontem_adi
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                exit;

            case 'get':
                $id = intval($_POST['id'] ?? 0);
                $data = $db->fetchOne("SELECT * FROM tanim_odeme_yontemleri WHERE odeme_yontem_id = ?", [$id]);

                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']);
                }
                exit;

            case 'save':
                $id = intval($_POST['id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    exit;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    exit;
                }

                $adi          = trim($_POST['adi'] ?? '');
                $kod          = strtoupper(trim($_POST['kod'] ?? ''));
                $hedefTipi    = trim($_POST['hedef_tipi'] ?? '');
                $renk         = trim($_POST['renk'] ?? '#6c757d');
                $ikon         = trim($_POST['ikon'] ?? '');
                $sira         = $_POST['sira'] !== '' ? intval($_POST['sira']) : null;
                $aciklama     = trim($_POST['aciklama'] ?? '');
                $durum        = !empty($_POST['durum']) ? 1 : 0;

                if ($adi === '') {
                    echo json_encode(['success' => false, 'message' => 'Yöntem adı boş bırakılamaz!']);
                    exit;
                }

                if (!in_array($hedefTipi, ['', 'KASA', 'BANKA'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz hedef tipi seçildi']);
                    exit;
                }

                // Aynı isimde kayıt kontrolü
                $mevcut = $db->fetchOne(
                    "SELECT odeme_yontem_id FROM tanim_odeme_yontemleri
                     WHERE odeme_yontem_adi = ? " . ($id > 0 ? "AND odeme_yontem_id != ?" : ""),
                    $id > 0 ? [$adi, $id] : [$adi]
                );

                if ($mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Bu isimde bir ödeme yöntemi zaten mevcut!']);
                    exit;
                }

                // Aynı kod kontrolü (kod benzersiz indeksli)
                if ($kod !== '') {
                    $mevcutKod = $db->fetchOne(
                        "SELECT odeme_yontem_id FROM tanim_odeme_yontemleri
                         WHERE odeme_yontem_kod = ? " . ($id > 0 ? "AND odeme_yontem_id != ?" : ""),
                        $id > 0 ? [$kod, $id] : [$kod]
                    );

                    if ($mevcutKod) {
                        echo json_encode(['success' => false, 'message' => 'Bu kod başka bir ödeme yönteminde kullanılıyor!']);
                        exit;
                    }
                }

                $data = [
                    'odeme_yontem_adi'          => $adi,
                    'odeme_yontem_kod'          => $kod !== '' ? $kod : null,
                    'odeme_yontem_hedef_tipi'   => $hedefTipi !== '' ? $hedefTipi : null,
                    'odeme_yontem_renk'         => $renk,
                    'odeme_yontem_ikon'         => $ikon !== '' ? $ikon : null,
                    'odeme_yontem_sira'         => $sira,
                    'odeme_yontem_aciklama'     => $aciklama,
                    'odeme_yontem_durum'        => $durum
                ];

                if ($id > 0) {
                    $data['odeme_yontem_guncelleme_tarihi']        = date('Y-m-d H:i:s');
                    $data['odeme_yontem_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    $db->update('tanim_odeme_yontemleri', $data, ['odeme_yontem_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Ödeme yöntemi güncellendi']);
                } else {
                    $data['odeme_yontem_olusturan_kullanici_id'] = $user['kullanici_id'];
                    $db->insert('tanim_odeme_yontemleri', $data);
                    echo json_encode(['success' => true, 'message' => 'Ödeme yöntemi eklendi']);
                }
                exit;

            case 'delete':
                if ($user['departman_id'] != 1 && !$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    exit;
                }

                $id = intval($_POST['id'] ?? 0);

                // Kullanımda olan yöntem silinemez
                $check = $db->fetchOne(
                    "SELECT COUNT(*) as sayi FROM Odeme_Hareketleri WHERE odeme_hareket_yontem_id = ?",
                    [$id]
                );

                if (($check['sayi'] ?? 0) > 0) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu ödeme yöntemi ' . $check['sayi'] . ' harekette kullanılıyor, silinemez. Pasife alabilirsiniz.'
                    ]);
                    exit;
                }

                $result = $db->delete('tanim_odeme_yontemleri', ['odeme_yontem_id' => $id]);

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Ödeme yöntemi silindi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Silme işlemi başarısız']);
                }
                exit;

            case 'toggle_status':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    exit;
                }

                $id    = intval($_POST['id'] ?? 0);
                $durum = intval($_POST['durum'] ?? 0);

                $result = $db->update(
                    'tanim_odeme_yontemleri',
                    [
                        'odeme_yontem_durum'                  => $durum,
                        'odeme_yontem_guncelleme_tarihi'      => date('Y-m-d H:i:s'),
                        'odeme_yontem_guncelleyen_kullanici_id' => $user['kullanici_id']
                    ],
                    ['odeme_yontem_id' => $id]
                );

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Durum güncellendi']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Durum güncellenemedi']);
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
                                    <span class="info-box-text">Toplam Yöntem</span>
                                    <span class="info-box-number" id="stat_toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat_aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-secondary">
                                <span class="info-box-icon"><i class="bi bi-safe"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kasa / Banka Bağlı</span>
                                    <span class="info-box-number" id="stat_kasa">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-cash-coin"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Yöntemli Hareket</span>
                                    <span class="info-box-number" id="stat_hareket">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse show" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search"
                                               placeholder="Yöntem adı, kod veya açıklama...">
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

                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Ödeme Yöntemleri Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-success btn-sm" id="addBtn">
                                    <i class="bi bi-plus-circle"></i> Yeni Ödeme Yöntemi
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="yontemlerTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="22%">Yöntem</th>
                                        <th width="10%">Kod</th>
                                        <th width="12%">Para Hedefi</th>
                                        <th width="8%">Sıra</th>
                                        <th width="25%">Açıklama</th>
                                        <th width="10%">Hareket</th>
                                        <th width="10%">Durum</th>
                                        <th width="10%">İşlemler</th>
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

<!-- Form Modal -->
<div class="modal fade" id="formModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="formModalTitle">Yeni Ödeme Yöntemi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="yontemForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="form_id" value="0">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="form_adi" class="form-label">Yöntem Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="adi" id="form_adi" maxlength="100" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="form_kod" class="form-label">Kod</label>
                            <input type="text" class="form-control" name="kod" id="form_kod" maxlength="30"
                                   placeholder="NAKIT, KKARTI, HAVALE...">
                            <small class="form-text text-muted">Benzersiz olmalıdır, boş bırakılabilir.</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="form_renk" class="form-label">Rozet Rengi</label>
                            <input type="color" class="form-control form-control-color w-100" name="renk" id="form_renk" value="#6c757d">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="form_ikon" class="form-label">İkon</label>
                            <select class="form-select" name="ikon" id="form_ikon">
                                <option value="">Seçiniz...</option>
                                <option value="bi-cash-coin">bi-cash-coin (Nakit)</option>
                                <option value="bi-credit-card">bi-credit-card (Kredi Kartı)</option>
                                <option value="bi-bank">bi-bank (Banka)</option>
                                <option value="bi-file-earmark-text">bi-file-earmark-text (Çek)</option>
                                <option value="bi-journal-text">bi-journal-text (Senet)</option>
                                <option value="bi-wallet2">bi-wallet2 (Cüzdan)</option>
                                <option value="bi-phone">bi-phone (Mobil)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="form_sira" class="form-label">Sıra</label>
                            <input type="number" class="form-control" name="sira" id="form_sira" min="0" placeholder="10">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="form_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" id="form_aciklama" rows="2" maxlength="300"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="form_hedef_tipi" class="form-label">Para Hedefi</label>
                            <select class="form-select" name="hedef_tipi" id="form_hedef_tipi">
                                <option value="">Seçim istenmez</option>
                                <option value="KASA">Kasa (Nakit)</option>
                                <option value="BANKA">Banka Hesabı (Havale / EFT)</option>
                            </select>
                            <small class="form-text text-muted">
                                Bu yöntem seçildiğinde ödeme formunda hangi hedefin zorunlu sorulacağını belirler.
                                Kasa tanımları Kasa Yönetimi, banka hesapları Banka IBAN Yönetimi ekranından gelir.
                            </small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="form_durum" name="durum" value="1" checked>
                                <label class="form-check-label" for="form_durum">Aktif</label>
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
const permissions = {
    canAdd:    <?= $permissions['can_add'] ? 'true' : 'false' ?>,
    canEdit:   <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
    canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
};

let currentFilters = {};
let table;

$(document).ready(function() {
    loadStats();

    // Aranabilir dropdown'lar
    ['#filter_durum', '#form_ikon', '#form_hedef_tipi'].forEach(function(selector) {
        $(selector).select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: selector.startsWith('#form_') ? $('#formModal') : null,
            language: {
                noResults: function() { return "Sonuç bulunamadı"; },
                searching: function() { return "Aranıyor..."; }
            }
        });
    });

    table = $('#yontemlerTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        order: [],
        pageLength: 25,
        scrollX: true,
        data: [],
        columns: [
            { data: 'odeme_yontem_id' },
            {
                data: null,
                render: function(data) {
                    const renk = data.odeme_yontem_renk || '#6c757d';
                    const ikon = data.odeme_yontem_ikon || 'bi-wallet2';
                    return `<span class="badge" style="background-color: ${renk}"><i class="bi ${ikon}"></i> ${data.odeme_yontem_adi}</span>`;
                }
            },
            {
                data: 'odeme_yontem_kod',
                render: d => d ? `<code>${d}</code>` : '-'
            },
            {
                data: 'odeme_yontem_hedef_tipi',
                render: function(d) {
                    if (d === 'KASA')  return '<span class="badge bg-warning text-dark"><i class="bi bi-safe"></i> Kasa</span>';
                    if (d === 'BANKA') return '<span class="badge bg-primary"><i class="bi bi-bank"></i> Banka Hesabı</span>';
                    return '<span class="text-muted">-</span>';
                }
            },
            { data: 'odeme_yontem_sira', render: d => d ?? '-' },
            { data: 'odeme_yontem_aciklama', render: d => d || '-' },
            {
                data: 'hareket_sayisi',
                render: d => d > 0 ? `<span class="badge bg-info">${d}</span>` : '<span class="text-muted">0</span>'
            },
            {
                data: null,
                orderable: false,
                render: function(data) {
                    const checked  = data.odeme_yontem_durum == 1 ? 'checked' : '';
                    const disabled = permissions.canEdit ? '' : 'disabled';
                    return `<div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input" type="checkbox" role="switch" ${checked} ${disabled}
                                       data-id="${data.odeme_yontem_id}" onchange="toggleStatus(this)">
                            </div>`;
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data) {
                    let buttons = '';
                    if (permissions.canEdit) {
                        buttons += `<button class="btn btn-sm btn-warning" onclick="editRecord(${data.odeme_yontem_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i></button> `;
                    }
                    if (permissions.canDelete) {
                        buttons += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${data.odeme_yontem_id})" title="Sil">
                                        <i class="bi bi-trash"></i></button>`;
                    }
                    return buttons || '-';
                }
            }
        ]
    });

    loadList();

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentFilters = {
            durum:  $('#filter_durum').val(),
            search: $('#filter_search').val()
        };
        Object.keys(currentFilters).forEach(key => {
            if (!currentFilters[key]) delete currentFilters[key];
        });
        loadList();
        showToast('Filtre uygulandı', 'info');
    });

    $('#clearFilters').on('click', function() {
        $('#filterForm')[0].reset();
        $('#filter_durum').val('').trigger('change.select2');
        currentFilters = {};
        loadList();
        showToast('Filtreler temizlendi', 'info');
    });

    $('#addBtn').on('click', function() {
        resetForm();
        $('#formModalTitle').text('Yeni Ödeme Yöntemi');
        new bootstrap.Modal(document.getElementById('formModal')).show();
    });

    $('#yontemForm').on('submit', function(e) {
        e.preventDefault();
        saveRecord();
    });
});

function loadStats() {
    $.post('', { action: 'stats' }, function(response) {
        if (response.success) {
            $('#stat_toplam').text(response.data.toplam || 0);
            $('#stat_aktif').text(response.data.aktif || 0);
            $('#stat_kasa').text((response.data.kasa_bagli || 0) + ' / ' + (response.data.banka_bagli || 0));
            $('#stat_hareket').text(response.data.hareket_sayisi || 0);
        }
    }, 'json');
}

function loadList() {
    $.post('', Object.assign({ action: 'list' }, currentFilters), function(response) {
        if (response.success) {
            table.clear().rows.add(response.data).draw();
        } else {
            showError('Hata!', response.message);
        }
    }, 'json').fail(function() {
        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
    });
}

function resetForm() {
    $('#yontemForm')[0].reset();
    $('#form_id').val(0);
    $('#form_renk').val('#6c757d');
    $('#form_ikon').val('').trigger('change.select2');
    $('#form_durum').prop('checked', true);
    $('#form_hedef_tipi').val('').trigger('change.select2');
}

function editRecord(id) {
    $.post('', { action: 'get', id: id }, function(response) {
        if (!response.success) {
            showError('Hata!', response.message);
            return;
        }

        const d = response.data;
        resetForm();
        $('#form_id').val(d.odeme_yontem_id);
        $('#form_adi').val(d.odeme_yontem_adi);
        $('#form_kod').val(d.odeme_yontem_kod);
        $('#form_renk').val(d.odeme_yontem_renk || '#6c757d');
        $('#form_ikon').val(d.odeme_yontem_ikon || '').trigger('change.select2');
        $('#form_sira').val(d.odeme_yontem_sira);
        $('#form_aciklama').val(d.odeme_yontem_aciklama);
        $('#form_hedef_tipi').val(d.odeme_yontem_hedef_tipi || '').trigger('change.select2');
        $('#form_durum').prop('checked', d.odeme_yontem_durum == 1);

        $('#formModalTitle').text('Ödeme Yöntemi Düzenle');
        new bootstrap.Modal(document.getElementById('formModal')).show();
    }, 'json');
}

function saveRecord() {
    const formData = $('#yontemForm').serialize() + '&action=save';

    $.post('', formData, function(response) {
        if (response.success) {
            bootstrap.Modal.getInstance(document.getElementById('formModal')).hide();
            showSuccess('Başarılı!', response.message);
            loadList();
            loadStats();
        } else {
            showError('Hata!', response.message);
        }
    }, 'json').fail(function() {
        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
    });
}

function deleteRecord(id) {
    Swal.fire({
        title: 'Emin misiniz?',
        text: 'Bu ödeme yöntemi silinecek!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Evet, sil',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        $.post('', { action: 'delete', id: id }, function(response) {
            if (response.success) {
                showSuccess('Başarılı!', response.message);
                loadList();
                loadStats();
            } else {
                showError('Hata!', response.message);
            }
        }, 'json');
    });
}

function toggleStatus(el) {
    const id    = $(el).data('id');
    const durum = el.checked ? 1 : 0;

    $.post('', { action: 'toggle_status', id: id, durum: durum }, function(response) {
        if (response.success) {
            showToast(response.message, 'success');
            loadStats();
        } else {
            showError('Hata!', response.message);
            $(el).prop('checked', !el.checked);
        }
    }, 'json').fail(function() {
        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
        $(el).prop('checked', !el.checked);
    });
}
</script>

</div>
</body>
</html>
