<?php
/**
 * Destek Talepleri Listesi
 * Kaynak: Portal
 *
 * ═══════════════════════════════════════════════════════════════
 * API RESPONSE KEY REFERANSLARI
 * ═══════════════════════════════════════════════════════════════
 *
 * list_tickets → data[] her ticket objesi:
 *   Tickets_id          → Ticket ID (detay sayfasına yönlendirmede kullanılır)
 *   Tickets_no          → Ticket numarası (ör: TKT-0001)
 *   Tickets_konu        → Ticket konusu
 *   kategori_ad         → Kategori adı        · kategori_renk → badge rengi
 *   oncelik_ad          → Öncelik adı         · oncelik_renk  → badge rengi
 *   durum_ad            → Durum adı           · durum_renk    → badge rengi
 *   Ticket_Durumlar_kapatma_durumu → 1 ise talep kapalı sayılır
 *   olusturan_ad        → Talebi açan kişi    · olusturan_soyad
 *   atanan_ad           → Atanan kişi (null olabilir = henüz atanmadı)
 *   acilis_tarihi       → Açılış tarihi (YYYY-MM-DD HH:MM)
 *   son_yanit_tarihi    → Son yanıt tarihi (null olabilir)
 *   mesaj_sayisi        → Toplam mesaj sayısı (int)
 *   cc_mi               → 1 ise talep kullanıcıya bilgilendirme (CC) yoluyla açık
 * ═══════════════════════════════════════════════════════════════
 */
session_start();

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketApi.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$eposta = $user['email'] ?? null;
if (!$eposta) {
    header('Location: ../login.php');
    exit;
}

// ─── API: Talepleri ve filtre seçeneklerini çek ───
$api         = new TicketApi();
$apiHata     = '';
$tickets     = [];
$kategoriler = [];
$oncelikler  = [];
$durumlar    = [];

if (!$api->hazirMi()) {
    $apiHata = $api->getHata();
} else {
    $result  = $api->call(['action' => 'list_tickets', 'eposta' => $eposta]);
    $tickets = ($result['success'] ?? false) ? ($result['data'] ?? []) : [];
    if (!($result['success'] ?? false)) {
        $apiHata = $result['message'] ?? 'Talepler alınamadı.';
    }

    // Filtre seçenekleri (kategori/öncelik/durum listeleri API'den gelir)
    $metaResult = $api->call(['action' => 'ticket_meta']);
    if ($metaResult['success'] ?? false) {
        $kategoriler = $metaResult['data']['kategoriler'] ?? [];
        $oncelikler  = $metaResult['data']['oncelikler'] ?? [];
        $durumlar    = $metaResult['data']['durumlar'] ?? [];
    }
}

/**
 * Infobox sayimlari. Kapali ayrimi API'nin dondugu kapatma bayragindan gelir;
 * acik ve islemde ayrimi ise durum adi uzerinden yapilir, boylece durum id'leri
 * koda gomulmez. Meta'da bu adlar degisirse yalnizca asagidaki iki sabit guncellenir.
 */
const DURUM_ACIK    = 'Açık';
const DURUM_ISLEMDE = 'İşlemde';

$toplamTalep  = count($tickets);
$kapaliTalep  = count(array_filter($tickets, fn($t) => !empty($t['Ticket_Durumlar_kapatma_durumu'])));
$acikTalep    = count(array_filter($tickets, fn($t) => ($t['durum_ad'] ?? '') === DURUM_ACIK));
$islemdeTalep = count(array_filter($tickets, fn($t) => ($t['durum_ad'] ?? '') === DURUM_ISLEMDE));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Destek Talepleri - Portal</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
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
                            <h3 class="mb-0">Destek Talepleri</h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="/admin/pages/dashboard.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Destek Talepleri</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <?php if ($apiHata): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($apiHata) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-secondary">
                                <span class="info-box-icon"><i class="bi bi-ticket-detailed"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Talep</span>
                                    <span class="info-box-number"><?= $toplamTalep ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-envelope-open"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Açık</span>
                                    <span class="info-box-number"><?= $acikTalep ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-arrow-repeat"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İşlemde</span>
                                    <span class="info-box-number"><?= $islemdeTalep ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kapalı</span>
                                    <span class="info-box-number"><?= $kapaliTalep ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel me-1"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm" onsubmit="return false;">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Kategori</label>
                                        <select class="form-select select2" id="filter_kategori">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kategoriler as $k): ?>
                                            <option value="<?= htmlspecialchars($k['ad']) ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Öncelik</label>
                                        <select class="form-select select2" id="filter_oncelik">
                                            <option value="">Tümü</option>
                                            <?php foreach ($oncelikler as $o): ?>
                                            <option value="<?= htmlspecialchars($o['ad']) ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <?php foreach ($durumlar as $d): ?>
                                            <option value="<?= htmlspecialchars($d['ad']) ?>"><?= htmlspecialchars($d['ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" id="filter_baslangic">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" id="filter_bitis">
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end gap-2">
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle me-1"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Talep Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-headset me-1"></i> Destek Talepleri</h3>
                            <div class="card-tools">
                                <a href="/admin/destek-detay?yeni=1" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i> Yeni Talep Oluştur
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="destekTable" class="table table-hover table-striped align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Talep No</th>
                                        <th>Konu</th>
                                        <th>Kategori</th>
                                        <th>Öncelik</th>
                                        <th>Durum</th>
                                        <th>Kullanıcı</th>
                                        <th>Tarih</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tickets as $t):
                                        $talepId   = (int)($t['Tickets_id'] ?? 0);
                                        $olusturan = trim(($t['olusturan_ad'] ?? '') . ' ' . ($t['olusturan_soyad'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><?= $talepId ?></td>
                                        <td><code><?= htmlspecialchars($t['Tickets_no'] ?? '') ?></code></td>
                                        <td>
                                            <a href="/admin/destek-detay?id=<?= $talepId ?>" class="text-decoration-none fw-semibold">
                                                <?= htmlspecialchars($t['Tickets_konu'] ?? '') ?>
                                            </a>
                                            <?php if (!empty($t['cc_mi'])): ?>
                                            <i class="bi bi-people-fill text-secondary ms-1" title="Bu talebe bilgilendirme (CC) amacıyla eklendiniz"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($t['kategori_ad'])): ?>
                                            <span class="badge" style="background:<?= htmlspecialchars($t['kategori_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($t['kategori_ad']) ?></span>
                                            <?php else: ?>-<?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge" style="background:<?= htmlspecialchars($t['oncelik_renk'] ?? '#6c757d') ?>">
                                                <?= htmlspecialchars($t['oncelik_ad'] ?? '-') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge" style="background:<?= htmlspecialchars($t['durum_renk'] ?? '#6c757d') ?>">
                                                <?= htmlspecialchars($t['durum_ad'] ?? '-') ?>
                                            </span>
                                        </td>
                                        <td><?= $olusturan !== '' ? htmlspecialchars($olusturan) : '-' ?></td>
                                        <td data-order="<?= htmlspecialchars($t['acilis_tarihi'] ?? '') ?>"><?= htmlspecialchars($t['acilis_tarihi'] ?? '-') ?></td>
                                        <td>
                                            <a href="/admin/destek-detay?id=<?= $talepId ?>" class="btn btn-sm btn-info" title="Detay">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/scripts.php'; ?>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    $(function () {
        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

        // Tarih araligi filtresi: acilis tarihi kolonuna (index 7) bakar
        $.fn.dataTable.ext.search.push(function (settings, data) {
            if (settings.nTable.id !== 'destekTable') return true;

            var bas = $('#filter_baslangic').val();
            var bit = $('#filter_bitis').val();
            if (!bas && !bit) return true;

            var tarih = (data[7] || '').substring(0, 10);
            if (!tarih) return false;
            if (bas && tarih < bas) return false;
            if (bit && tarih > bit) return false;
            return true;
        });

        var table = $('#destekTable').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            scrollX: true,
            order: [[0, 'desc']],
            pageLength: 25,
            columnDefs: [
                { targets: 8, orderable: false, searchable: false }
            ]
        });

        function kolonFiltrele(kolonIndex, deger) {
            table.column(kolonIndex).search(
                deger ? '^' + $.fn.dataTable.util.escapeRegex(deger) + '$' : '',
                true,
                false
            );
        }

        $('#filter_kategori').on('change', function () { kolonFiltrele(3, this.value); table.draw(); });
        $('#filter_oncelik').on('change',  function () { kolonFiltrele(4, this.value); table.draw(); });
        $('#filter_durum').on('change',    function () { kolonFiltrele(5, this.value); table.draw(); });
        $('#filter_baslangic, #filter_bitis').on('change', function () { table.draw(); });

        $('#clearFilters').on('click', function () {
            $('#filter_kategori, #filter_oncelik, #filter_durum').val('').trigger('change.select2');
            $('#filter_baslangic, #filter_bitis').val('');
            table.columns().search('');
            table.search('').draw();
        });

        // Widget badge sayacini sifirla
        localStorage.setItem('destek_son_kontrol', new Date().toISOString());
    });
    </script>
</body>
</html>
