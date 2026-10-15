<?php
/**
 * Admin Panel - Kargo Takip
 *
 * Aras Kargo gelen ve giden kargoları (kargo_gonderiler).
 * Veri cron'daki aras_kargo_senkron görevi veya "Şimdi Senkronize Et" ile gelir.
 * Liste server-side: kargo_gonderiler sınırsız büyüyen hareket tablosu.
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

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Kargo Takip';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Varsayılan tarih aralığı = senkron görevinin gün parametresi
$cronParam = $db->fetchOne("
    SELECT CAST(CronGorevler_Parametreler AS NVARCHAR(MAX)) AS p
    FROM CronGorevler WHERE CronGorevler_GorevKodu = 'aras_kargo_senkron'
");
$varsayilanGun = max(1, (int)((json_decode($cronParam['p'] ?? '{}', true) ?: [])['gun'] ?? 30));

// Aras'ın web takip linki (Entegrasyonlar.AyarJSON → TakipLinkURL, {takipNo} yer tutuculu). Yoksa link gösterilmez.
$arasAyar = $db->fetchOne("
    SELECT CAST(Entegrasyonlar_AyarJSON AS NVARCHAR(MAX)) AS a
    FROM Entegrasyonlar WHERE Entegrasyonlar_Ad = 'Aras Kargo'
");
$takipLinkURL = (string)((json_decode($arasAyar['a'] ?? '{}', true) ?: [])['TakipLinkURL'] ?? '');

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    $yon = ($_POST['yon'] ?? '') === 'GELEN' ? 'GELEN' : 'GIDEN';

    try {
        switch ($action) {

            // ---------------- InfoBox ----------------
            case 'stats':
                $s = $db->fetchOne("
                    SELECT COUNT(*) AS toplam,
                           SUM(CASE WHEN ISNULL(d.kargo_durumlari_TeslimMi, 0) = 0 THEN 1 ELSE 0 END) AS yolda,
                           SUM(CASE WHEN d.kargo_durumlari_TeslimMi = 1 THEN 1 ELSE 0 END) AS teslim,
                           SUM(CASE WHEN g.kargo_gonderiler_CikisTarihi >= CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS bugun
                    FROM kargo_gonderiler g
                    LEFT JOIN kargo_durumlari d ON d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu
                    WHERE g.kargo_gonderiler_Durum = 1 AND g.kargo_gonderiler_Yon = ?
                ", [$yon]);

                $son = $db->fetchOne("
                    SELECT CONVERT(VARCHAR(16), MAX(kargo_gonderiler_SonSenkron), 120) AS t
                    FROM kargo_gonderiler WHERE kargo_gonderiler_Durum = 1
                ");

                echo json_encode(['success' => true, 'data' => [
                    'toplam'     => (int)($s['toplam'] ?? 0),
                    'yolda'      => (int)($s['yolda'] ?? 0),
                    'teslim'     => (int)($s['teslim'] ?? 0),
                    'bugun'      => (int)($s['bugun'] ?? 0),
                    'son_senkron'=> $son['t'] ?? null,
                ]], $jsonBayrak);
                break;

            // ---------------- Liste (server-side) ----------------
            case 'list':
                $where  = ["g.kargo_gonderiler_Durum = 1", "g.kargo_gonderiler_Yon = ?"];
                $params = [$yon];

                if (($_POST['f_bas'] ?? '') !== '') {
                    $where[]  = "g.kargo_gonderiler_CikisTarihi >= ?";
                    $params[] = $_POST['f_bas'];
                }
                if (($_POST['f_bit'] ?? '') !== '') {
                    $where[]  = "g.kargo_gonderiler_CikisTarihi < DATEADD(DAY, 1, CAST(? AS DATE))";
                    $params[] = $_POST['f_bit'];
                }
                if (($_POST['f_durum'] ?? '') !== '') {
                    $where[]  = "g.kargo_gonderiler_DurumKodu = ?";
                    $params[] = (int)$_POST['f_durum'];
                }
                if (in_array($_POST['f_teslim'] ?? '', ['0', '1'], true)) {
                    $where[]  = ($_POST['f_teslim'] === '1' ? '' : 'NOT ')
                              . "EXISTS (SELECT 1 FROM kargo_durumlari d WHERE d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu AND d.kargo_durumlari_TeslimMi = 1)";
                }
                if (in_array($_POST['f_kaynak'] ?? '', ['PORTAL', 'ARAS'], true)) {
                    $where[]  = "g.kargo_gonderiler_Kaynak = ?";
                    $params[] = $_POST['f_kaynak'];
                }
                if (trim($_POST['f_arama'] ?? '') !== '') {
                    $where[] = "(g.kargo_gonderiler_TakipNo LIKE ? OR g.kargo_gonderiler_IrsaliyeNo LIKE ?
                                 OR g.kargo_gonderiler_EntegrasyonKodu LIKE ? OR g.kargo_gonderiler_Alici LIKE ?
                                 OR g.kargo_gonderiler_Gonderici LIKE ?)";
                    $ara = '%' . trim($_POST['f_arama']) . '%';
                    array_push($params, $ara, $ara, $ara, $ara, $ara);
                }
                $whereSql = implode(' AND ', $where);

                // Sıralama: kolon index → kolon adı whitelist
                $siralamaKolonlari = [
                    0 => 'g.kargo_gonderiler_CikisTarihi',
                    1 => 'g.kargo_gonderiler_TakipNo',
                    2 => 'g.kargo_gonderiler_Gonderici',
                    3 => 'g.kargo_gonderiler_Alici',
                    4 => 'g.kargo_gonderiler_VarisSube',
                    5 => 'g.kargo_gonderiler_Adet',
                    6 => 'g.kargo_gonderiler_Tutar',
                    7 => 'g.kargo_gonderiler_DurumKodu',
                    8 => 'g.kargo_gonderiler_TeslimTarihi',
                ];
                $siraKolon = $siralamaKolonlari[(int)($_POST['order'][0]['column'] ?? 0)] ?? $siralamaKolonlari[0];
                $siraYon   = strtolower($_POST['order'][0]['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
                $orderSql  = "{$siraKolon} {$siraYon}, g.kargo_gonderiler_id DESC";

                $baslangic = max(0, (int)($_POST['start'] ?? 0));
                $uzunluk   = (int)($_POST['length'] ?? 25);
                $uzunluk   = $uzunluk < 1 ? 25 : min($uzunluk, 500);

                // Sayımlar: JOIN'siz, tek tablo
                $toplam = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS a FROM kargo_gonderiler WHERE kargo_gonderiler_Durum = 1 AND kargo_gonderiler_Yon = ?",
                    [$yon]
                )['a'] ?? 0);
                $filtreli = (int)($db->fetchOne("SELECT COUNT(*) AS a FROM kargo_gonderiler g WHERE {$whereSql}", $params)['a'] ?? 0);

                // 1) Ucuz CTE: yalnız sayfadaki ID'ler  2) Dış SELECT: durum bilgisi
                $satirlar = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT g.kargo_gonderiler_id
                        FROM kargo_gonderiler g
                        WHERE {$whereSql}
                        ORDER BY {$orderSql}
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT g.kargo_gonderiler_id                                   AS id,
                           CONVERT(VARCHAR(16), g.kargo_gonderiler_CikisTarihi, 120)  AS cikis_tarihi,
                           g.kargo_gonderiler_TakipNo                              AS takip_no,
                           g.kargo_gonderiler_EntegrasyonKodu                      AS entegrasyon_kodu,
                           g.kargo_gonderiler_Gonderici                            AS gonderici,
                           g.kargo_gonderiler_Alici                                AS alici,
                           g.kargo_gonderiler_CikisSube                            AS cikis_sube,
                           g.kargo_gonderiler_VarisSube                            AS varis_sube,
                           g.kargo_gonderiler_Adet                                 AS adet,
                           g.kargo_gonderiler_Desi                                 AS desi,
                           g.kargo_gonderiler_Tutar                                AS tutar,
                           g.kargo_gonderiler_OdemeTipi                            AS odeme_tipi,
                           g.kargo_gonderiler_Kaynak                               AS kaynak,
                           g.kargo_gonderiler_DurumKodu                            AS durum_kodu,
                           g.kargo_gonderiler_DurumMetni                           AS durum_metni,
                           d.kargo_durumlari_Ad                                    AS durum_adi,
                           d.kargo_durumlari_Renk                                  AS durum_renk,
                           g.kargo_gonderiler_TeslimAlan                           AS teslim_alan,
                           CONVERT(VARCHAR(16), g.kargo_gonderiler_TeslimTarihi, 120) AS teslim_tarihi
                    FROM sayfa s
                    INNER JOIN kargo_gonderiler g ON g.kargo_gonderiler_id = s.kargo_gonderiler_id
                    LEFT JOIN kargo_durumlari d   ON d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu
                    ORDER BY {$orderSql}
                ", array_merge($params, [$baslangic, $uzunluk]));

                echo json_encode([
                    'draw'            => (int)($_POST['draw'] ?? 0),
                    'recordsTotal'    => $toplam,
                    'recordsFiltered' => $filtreli,
                    'data'            => $satirlar,
                ], $jsonBayrak);
                break;

            // ---------------- Detay ----------------
            case 'detay':
                $d = $db->fetchOne("
                    SELECT g.*, d.kargo_durumlari_Ad, d.kargo_durumlari_Renk, k.EntegrasyonKanallari_Ad
                    FROM kargo_gonderiler g
                    LEFT JOIN kargo_durumlari d       ON d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu
                    LEFT JOIN EntegrasyonKanallari k  ON k.EntegrasyonKanallari_id = g.kargo_gonderiler_EntegrasyonKanallari_id
                    WHERE g.kargo_gonderiler_id = ?
                ", [(int)($_POST['id'] ?? 0)]);

                if (!$d) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı.'], $jsonBayrak);
                    break;
                }
                foreach ($d as $k => $v) {
                    if ($v instanceof DateTimeInterface) $d[$k] = $v->format('d.m.Y H:i');
                }
                echo json_encode(['success' => true, 'data' => $d], $jsonBayrak);
                break;

            // ---------------- Filtre: durumlar ----------------
            case 'durumlar':
                echo json_encode(['success' => true, 'data' => $db->fetchAll("
                    SELECT kargo_durumlari_Kod AS kod, kargo_durumlari_Ad AS ad
                    FROM kargo_durumlari WHERE kargo_durumlari_Durum = 1
                    ORDER BY kargo_durumlari_Sira, kargo_durumlari_Ad
                ")], $jsonBayrak);
                break;

            // ---------------- Şimdi senkronize et ----------------
            case 'senkron':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Senkron için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    break;
                }
                require_once __DIR__ . '/../includes/ArasKargo.php';
                set_time_limit(300);

                $aras = new ArasKargo($db, null, (int)$user['kullanici_id'], 'KARGO_TAKIP');
                if (!$aras->hazirMi()) {
                    echo json_encode(['success' => false, 'message' => $aras->getHata()], $jsonBayrak);
                    break;
                }
                $r = $aras->senkronize((int)($_POST['gun'] ?? $varsayilanGun));
                echo json_encode(['success' => $r['success'], 'message' => $r['message']], $jsonBayrak);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; cursor: pointer; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .info-box.secili { outline: 2px solid var(--bs-primary); }
        .filtre-basligi { cursor: pointer; }
        #tablo td { vertical-align: middle; white-space: nowrap; }
        #tablo td.metin { white-space: normal; min-width: 180px; }
        .takip-no { font-family: var(--bs-font-monospace); }
        .detay-tablo th { width: 35%; font-weight: 600; }
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
                            <?php if ($pageDescription): ?>
                            <small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small>
                            <?php endif; ?>
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

                    <!-- INFOBOX: tıklanınca teslim filtresi uygulanır -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-teslim="">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-boxes"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam</span>
                                    <span class="info-box-number" id="ib_toplam">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-teslim="0">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-truck"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Yolda / Teslim Edilmedi</span>
                                    <span class="info-box-number" id="ib_yolda">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-teslim="1">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Teslim Edildi</span>
                                    <span class="info-box-number" id="ib_teslim">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-bugun="1">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-calendar-day"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün</span>
                                    <span class="info-box-number" id="ib_bugun">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-primary card-outline card-outline-tabs">
                        <div class="card-header p-0 border-bottom-0 d-flex align-items-center">
                            <ul class="nav nav-tabs flex-grow-1" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" href="#" data-yon="GIDEN" role="tab"><i class="bi bi-box-arrow-up-right"></i> Giden Kargolar</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" data-yon="GELEN" role="tab"><i class="bi bi-box-arrow-in-down-left"></i> Gelen Kargolar</a>
                                </li>
                            </ul>
                            <div class="px-3 d-flex align-items-center gap-2">
                                <small class="text-muted d-none d-md-inline">Son senkron: <span id="sonSenkron">-</span></small>
                                <?php if ($pagePermissions['can_edit']): ?>
                                <button class="btn btn-sm btn-outline-primary" id="btnSenkron">
                                    <i class="bi bi-arrow-repeat"></i> Şimdi Senkronize Et
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">

                            <!-- Filtre -->
                            <div class="card card-secondary card-outline mb-3">
                                <div class="card-header filtre-basligi" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                                    <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                                </div>
                                <div class="collapse show" id="filtrePanel">
                                    <div class="card-body">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-2">
                                                <label class="form-label">Başlangıç</label>
                                                <input type="date" class="form-control" id="f_bas">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Bitiş</label>
                                                <input type="date" class="form-control" id="f_bit">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select filtre-select2" id="f_durum">
                                                    <option value="">Tümü</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Teslim</label>
                                                <select class="form-select filtre-select2" id="f_teslim">
                                                    <option value="">Tümü</option>
                                                    <option value="0">Teslim edilmedi</option>
                                                    <option value="1">Teslim edildi</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Kaynak</label>
                                                <select class="form-select filtre-select2" id="f_kaynak">
                                                    <option value="">Tümü</option>
                                                    <option value="PORTAL">Portaldan gönderilen</option>
                                                    <option value="ARAS">Aras'tan çekilen</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="f_arama" placeholder="Takip no, irsaliye, ad...">
                                            </div>
                                            <div class="col-12">
                                                <button class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                                <button class="btn btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <table id="tablo" class="table table-bordered table-striped table-hover w-100">
                                <thead>
                                    <tr>
                                        <th>Tarih</th>
                                        <th>Takip No</th>
                                        <th>Gönderici</th>
                                        <th>Alıcı</th>
                                        <th>Şube (Çıkış → Varış)</th>
                                        <th>Adet / Desi</th>
                                        <th>Tutar</th>
                                        <th>Durum</th>
                                        <th>Teslim</th>
                                        <th></th>
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

    <!-- Detay -->
    <div class="modal fade" id="modalDetay" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-seam"></i> Kargo Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="detayIcerik"></div>
                <div class="modal-footer">
                    <a href="#" target="_blank" rel="noopener" class="btn btn-outline-primary d-none" id="detayArasLink">
                        <i class="bi bi-box-arrow-up-right"></i> Aras'ta Görüntüle
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        const VARSAYILAN_GUN = <?= (int)$varsayilanGun ?>;
        const TAKIP_LINK_URL = <?= json_encode($takipLinkURL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        let yon = 'GIDEN';
        let tablo;

        const esc = s => $('<div>').text(s ?? '').html();
        const tarihStr = d => d.toISOString().slice(0, 10);
        const tarihTr = s => s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' + s.slice(0, 4) + (s.length > 10 && s.slice(11) !== '00:00' ? ' ' + s.slice(11) : '') : '-';
        const para = v => v === null || v === '' ? '-' : Number(v).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₺';
        const takipLinki = no => TAKIP_LINK_URL && no ? TAKIP_LINK_URL.replace('{takipNo}', encodeURIComponent(no)) : '';

        function durumBadge(kod, ad, renk, metin) {
            if (kod === null || kod === undefined) return '<span class="text-muted">-</span>';
            const baslik = metin ? ` title="${esc(metin)}"` : '';
            return `<span class="badge text-bg-${esc(renk || 'secondary')}"${baslik}>${esc(ad || metin || ('Kod ' + kod))}</span>`;
        }

        function varsayilanTarihler() {
            const bit = new Date();
            const bas = new Date();
            bas.setDate(bas.getDate() - VARSAYILAN_GUN);
            $('#f_bas').val(tarihStr(bas));
            $('#f_bit').val(tarihStr(bit));
        }

        function loadStats() {
            $.post('', {action: 'stats', yon: yon}, function (r) {
                if (!r.success) return;
                $('#ib_toplam').text(r.data.toplam);
                $('#ib_yolda').text(r.data.yolda);
                $('#ib_teslim').text(r.data.teslim);
                $('#ib_bugun').text(r.data.bugun);
                $('#sonSenkron').text(r.data.son_senkron ? tarihTr(r.data.son_senkron) : 'henüz yok');
            }, 'json');
        }

        function loadDurumlar() {
            $.post('', {action: 'durumlar'}, function (r) {
                if (!r.success) return;
                const $s = $('#f_durum');
                r.data.forEach(d => $s.append(new Option(d.ad, d.kod)));
            }, 'json');
        }

        function initTablo() {
            tablo = $('#tablo').DataTable({
                processing: true,
                serverSide: true,
                scrollX: true,
                dom: 'lrtip',
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function (d) {
                        d.action   = 'list';
                        d.yon      = yon;
                        d.f_bas    = $('#f_bas').val();
                        d.f_bit    = $('#f_bit').val();
                        d.f_durum  = $('#f_durum').val();
                        d.f_teslim = $('#f_teslim').val();
                        d.f_kaynak = $('#f_kaynak').val();
                        d.f_arama  = $('#f_arama').val();
                    },
                    error: function () { showToast('Liste yüklenemedi.', 'error'); }
                },
                columns: [
                    {data: 'cikis_tarihi', render: d => tarihTr(d)},
                    {
                        data: 'takip_no',
                        render: (d, t, r) => d
                            ? `<span class="takip-no">${esc(d)}</span>`
                            : `<span class="text-muted" title="Şube kabulünde atanır">Bekleniyor</span>`
                                + (r.entegrasyon_kodu ? `<br><small class="text-muted">Ent: ${esc(r.entegrasyon_kodu)}</small>` : '')
                    },
                    {data: 'gonderici', className: 'metin', render: d => esc(d) || '-'},
                    {
                        data: 'alici', className: 'metin',
                        render: (d, t, r) => (esc(d) || '-') + (r.kaynak === 'PORTAL' ? ' <span class="badge text-bg-light border" title="Portaldan gönderildi">Portal</span>' : '')
                    },
                    {data: 'varis_sube', render: (d, t, r) => `${esc(r.cikis_sube) || '-'} → ${esc(d) || '-'}`},
                    {data: 'adet', className: 'text-center', render: (d, t, r) => `${d ?? '-'} / ${r.desi !== null ? Number(r.desi) : '-'}`},
                    {data: 'tutar', className: 'text-end', render: (d, t, r) => para(d) + (r.odeme_tipi ? `<br><small class="text-muted">${esc(r.odeme_tipi)}</small>` : '')},
                    {data: 'durum_kodu', render: (d, t, r) => durumBadge(d, r.durum_adi, r.durum_renk, r.durum_metni)},
                    {
                        data: 'teslim_tarihi',
                        render: (d, t, r) => d ? `${tarihTr(d)}<br><small class="text-muted">${esc(r.teslim_alan)}</small>` : '-'
                    },
                    {
                        data: null, orderable: false, className: 'text-center',
                        render: r => `<button class="btn btn-sm btn-info" onclick="detayAc(${r.id})" title="Detay"><i class="bi bi-eye"></i></button>`
                    }
                ],
                // Açılış sekmesi Giden: gönderici hep biziz, kolon gizli başlar
                columnDefs: [{targets: 2, visible: false}],
                order: [[0, 'desc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                language: {url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'}
            });
        }

        // Giden sekmesinde gönderici hep biziz; gelende alıcı. O kolon gizlenir.
        function kolonlariAyarla() {
            tablo.column(2).visible(yon === 'GELEN', false);
            tablo.column(3).visible(yon === 'GIDEN', false);
            tablo.columns.adjust();
        }

        function detayAc(id) {
            $.post('', {action: 'detay', id: id}, function (r) {
                if (!r.success) { showToast(r.message, 'error'); return; }
                const d = r.data;
                const satir = (etiket, deger) => `<tr><th>${etiket}</th><td>${deger}</td></tr>`;
                const g = k => esc(d['kargo_gonderiler_' + k]) || '-';

                $('#detayIcerik').html(`
                    <table class="table table-sm detay-tablo">
                        ${satir('Yön', d.kargo_gonderiler_Yon === 'GIDEN' ? 'Giden' : 'Gelen')}
                        ${satir('Durum', durumBadge(d.kargo_gonderiler_DurumKodu, d.kargo_durumlari_Ad, d.kargo_durumlari_Renk, d.kargo_gonderiler_DurumMetni)
                            + (d.kargo_gonderiler_DurumMetni ? ` <small class="text-muted">${esc(d.kargo_gonderiler_DurumMetni)}</small>` : ''))}
                        ${satir('Takip No', `<span class="takip-no">${g('TakipNo')}</span>`)}
                        ${satir('Entegrasyon Kodu', g('EntegrasyonKodu'))}
                        ${satir('Barkod', g('Barkod'))}
                        ${satir('Kargo Kodu', g('KargoKodu'))}
                        ${satir('İrsaliye No', g('IrsaliyeNo'))}
                        ${satir('Gönderici', g('Gonderici'))}
                        ${satir('Alıcı', g('Alici'))}
                        ${satir('Alıcı Telefon', g('AliciTelefon'))}
                        ${satir('Alıcı Adres', g('AliciAdres') + (d.kargo_gonderiler_AliciIlce || d.kargo_gonderiler_AliciIl ? `<br>${g('AliciIlce')} / ${g('AliciIl')}` : ''))}
                        ${satir('Şube', `${g('CikisSube')} → ${g('VarisSube')}`)}
                        ${satir('Çıkış Tarihi', g('CikisTarihi'))}
                        ${satir('Adet / Desi / Ağırlık', `${g('Adet')} / ${g('Desi')} / ${g('Agirlik')}`)}
                        ${satir('Tutar / Ödeme', `${para(d.kargo_gonderiler_Tutar)} · ${g('OdemeTipi')}`)}
                        ${satir('Tahsilat', para(d.kargo_gonderiler_TahsilatTutari))}
                        ${satir('Teslim', `${g('TeslimTarihi')} · ${g('TeslimAlan')}`)}
                        ${satir('Açıklama', g('Aciklama'))}
                        ${satir('Kaynak / Kanal', `${g('Kaynak')} · ${esc(d.EntegrasyonKanallari_Ad) || '-'}`)}
                        ${satir('Son Senkron', g('SonSenkron'))}
                    </table>`);

                const link = takipLinki(d.kargo_gonderiler_TakipNo);
                $('#detayArasLink').toggleClass('d-none', !link).attr('href', link || '#');
                new bootstrap.Modal('#modalDetay').show();
            }, 'json');
        }

        function yenile() {
            tablo.ajax.reload();
            loadStats();
        }

        $(function () {
            varsayilanTarihler();
            $('.filtre-select2').select2({theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 0});
            loadDurumlar();
            initTablo();
            // InfoBox sayımları tablo yüklendikten sonra
            tablo.one('draw', loadStats);

            $('.nav-tabs .nav-link').on('click', function (e) {
                e.preventDefault();
                $('.nav-tabs .nav-link').removeClass('active');
                $(this).addClass('active');
                yon = $(this).data('yon');
                kolonlariAyarla();
                yenile();
            });

            $('#btnFiltrele').on('click', () => tablo.ajax.reload());
            $('#f_arama').on('keypress', e => { if (e.which === 13) tablo.ajax.reload(); });

            $('#btnTemizle').on('click', function () {
                varsayilanTarihler();
                $('#f_durum, #f_teslim, #f_kaynak').val('').trigger('change.select2');
                $('#f_arama').val('');
                $('.info-box').removeClass('secili');
                tablo.ajax.reload();
            });

            // InfoBox → hızlı filtre
            $('.info-box').on('click', function () {
                $('.info-box').removeClass('secili');
                $(this).addClass('secili');
                if ($(this).data('bugun')) {
                    $('#f_bas, #f_bit').val(tarihStr(new Date()));
                    $('#f_teslim').val('').trigger('change.select2');
                } else {
                    $('#f_bas, #f_bit').val('');
                    $('#f_teslim').val(String($(this).data('teslim'))).trigger('change.select2');
                }
                tablo.ajax.reload();
            });

            $('#btnSenkron').on('click', function () {
                const $b = $(this).prop('disabled', true);
                const eski = $b.html();
                $b.html('<span class="spinner-border spinner-border-sm"></span> Senkronize ediliyor...');

                $.post('', {action: 'senkron', gun: VARSAYILAN_GUN}, function (r) {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) {
                        $('#f_durum').find('option:not(:first)').remove();
                        loadDurumlar();
                        yenile();
                    }
                }, 'json')
                .fail(() => showToast('Senkron isteği başarısız.', 'error'))
                .always(() => $b.prop('disabled', false).html(eski));
            });
        });
    </script>
</body>
</html>
