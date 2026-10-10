<?php
/**
 * Admin Panel - PDKS Ayarları
 *
 * Kaynak tablo: dbo.tanim_pdks_ayarlari (anahtar/değer)
 * Form, tablodaki aktif satırlardan üretilir: yeni bir ayar için yalnızca
 * satır eklemek yeterlidir, bu sayfada kod değişikliği gerekmez.
 * pdks-api (masaustu.routes.ts) değerleri her istekte buradan okur.
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'PDKS Ayarları';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

/**
 * Ayarlar arası kurallar. Tek satırın sınırları (min/max) tabloda tutulur;
 * iki satırı birbirine bağlayan kurallar CHECK kısıtıyla yazılamadığı için
 * burada ve API'de uygulanır.
 */
function pdksAyarCaprazKontrol(array $degerler) {
    if (isset($degerler['hareketsiz_mola_dk'], $degerler['hareketsiz_cikis_dk'])
        && (int)$degerler['hareketsiz_cikis_dk'] <= (int)$degerler['hareketsiz_mola_dk']) {
        return 'Otomatik çıkış süresi, otomatik mola süresinden büyük olmalıdır!';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $ayarSayisi = $db->fetchOne("SELECT COUNT(*) as c FROM tanim_pdks_ayarlari WHERE pdks_ayar_durum = 1")['c'] ?? 0;
                $sonGuncelleme = $db->fetchOne("
                    SELECT TOP 1 CONVERT(VARCHAR(19), a.pdks_ayar_guncelleme_tarihi, 120) as tarih,
                           k.kullanici_ad + ' ' + k.kullanici_soyad as kisi
                    FROM tanim_pdks_ayarlari a
                    LEFT JOIN kullanicilar k ON k.kullanici_id = a.pdks_ayar_guncelleyen_kullanici_id
                    WHERE a.pdks_ayar_guncelleme_tarihi IS NOT NULL
                    ORDER BY a.pdks_ayar_guncelleme_tarihi DESC
                ");
                $otoBugun = $db->fetchOne("
                    SELECT COUNT(*) as c FROM Personel_GirisCikis
                    WHERE otomatik = 1 AND CONVERT(date, zaman) = CONVERT(date, GETDATE())
                ")['c'] ?? 0;
                $oto30 = $db->fetchOne("
                    SELECT COUNT(*) as c FROM Personel_GirisCikis
                    WHERE otomatik = 1 AND zaman >= DATEADD(DAY, -30, CONVERT(date, GETDATE()))
                ")['c'] ?? 0;
                echo json_encode(['success' => true, 'data' => [
                    'ayarSayisi'    => $ayarSayisi,
                    'otoBugun'      => $otoBugun,
                    'oto30'         => $oto30,
                    'sonGuncelleme' => $sonGuncelleme ?: null,
                ]]);
                break;

            case 'list':
                $data = $db->fetchAll("
                    SELECT pdks_ayar_id, pdks_ayar_anahtar, pdks_ayar_baslik,
                           -- gizli tip (API anahtarı vb.) tarayıcıya hiç gönderilmez; yalnız tanımlı olup olmadığı döner
                           CASE WHEN pdks_ayar_tip = 'gizli' THEN NULL ELSE pdks_ayar_deger END as pdks_ayar_deger,
                           CASE WHEN pdks_ayar_tip = 'gizli' AND ISNULL(pdks_ayar_deger, '') <> '' THEN 1 ELSE 0 END as gizli_tanimli,
                           pdks_ayar_aciklama, pdks_ayar_tip, pdks_ayar_min, pdks_ayar_max,
                           pdks_ayar_birim,
                           CONVERT(VARCHAR(19), pdks_ayar_guncelleme_tarihi, 120) as guncelleme_tarihi
                    FROM tanim_pdks_ayarlari
                    WHERE pdks_ayar_durum = 1
                    ORDER BY pdks_ayar_sira, pdks_ayar_baslik
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'save':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $gelen = $_POST['ayar'] ?? [];
                $tanimlar = $db->fetchAll("
                    SELECT pdks_ayar_anahtar, pdks_ayar_baslik, pdks_ayar_tip, pdks_ayar_min, pdks_ayar_max
                    FROM tanim_pdks_ayarlari WHERE pdks_ayar_durum = 1
                ");

                // Yalnızca tabloda tanımlı anahtarlar kabul edilir; her değer kendi
                // tipine ve sınırına göre doğrulanır.
                $degerler = [];
                foreach ($tanimlar as $t) {
                    $anahtar = $t['pdks_ayar_anahtar'];
                    $baslik = $t['pdks_ayar_baslik'];

                    if ($t['pdks_ayar_tip'] === 'bit') {
                        $degerler[$anahtar] = isset($gelen[$anahtar]) ? '1' : '0';
                        continue;
                    }
                    if (!array_key_exists($anahtar, $gelen)) {
                        continue;
                    }
                    $deger = trim((string)$gelen[$anahtar]);

                    // Gizli alan boş bırakıldıysa mevcut değer korunur
                    if ($t['pdks_ayar_tip'] === 'gizli' && $deger === '') {
                        continue;
                    }

                    if ($t['pdks_ayar_tip'] === 'sayi') {
                        if ($deger === '' || !ctype_digit($deger)) {
                            echo json_encode(['success' => false, 'message' => "$baslik için geçerli bir tam sayı giriniz!"]);
                            break 2;
                        }
                        $sayi = (int)$deger;
                        if (($t['pdks_ayar_min'] !== null && $sayi < (int)$t['pdks_ayar_min'])
                            || ($t['pdks_ayar_max'] !== null && $sayi > (int)$t['pdks_ayar_max'])) {
                            echo json_encode([
                                'success' => false,
                                'message' => "$baslik {$t['pdks_ayar_min']} ile {$t['pdks_ayar_max']} arasında olmalıdır!"
                            ]);
                            break 2;
                        }
                        $deger = (string)$sayi;
                    }
                    $degerler[$anahtar] = $deger;
                }

                if ($hata = pdksAyarCaprazKontrol($degerler)) {
                    echo json_encode(['success' => false, 'message' => $hata]);
                    break;
                }

                // Yalnızca değişen satırlar güncellenir; son güncelleyen bilgisi
                // gerçekten değişen ayarı gösterir.
                $mevcut = [];
                foreach ($db->fetchAll("SELECT pdks_ayar_anahtar, pdks_ayar_deger FROM tanim_pdks_ayarlari") as $m) {
                    $mevcut[$m['pdks_ayar_anahtar']] = (string)$m['pdks_ayar_deger'];
                }
                $degisen = 0;
                foreach ($degerler as $anahtar => $deger) {
                    if (($mevcut[$anahtar] ?? null) === $deger) {
                        continue;
                    }
                    $db->update('tanim_pdks_ayarlari', [
                        'pdks_ayar_deger'                    => $deger,
                        'pdks_ayar_guncelleyen_kullanici_id' => $user['kullanici_id'],
                        'pdks_ayar_guncelleme_tarihi'        => date('Y-m-d H:i:s'),
                    ], ['pdks_ayar_anahtar' => $anahtar]);
                    $degisen++;
                }

                echo json_encode([
                    'success' => true,
                    'message' => $degisen ? "PDKS ayarları kaydedildi! ($degisen ayar güncellendi)" : 'Değişiklik yok.'
                ]);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .ayar-satir + .ayar-satir { border-top: 1px solid var(--bs-border-color); }
        .ayar-anahtar { font-family: monospace; font-size: 0.75rem; }
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
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-sliders"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Ayar</span>
                                <span class="info-box-number" id="stat-ayar">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-robot"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Otomatik Kayıt (Bugün)</span>
                                <span class="info-box-number" id="stat-otobugun">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-calendar3"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Otomatik Kayıt (30 Gün)</span>
                                <span class="info-box-number" id="stat-oto30">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-clock-history"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Son Güncelleme</span>
                                <span class="info-box-number small" id="stat-songuncelleme">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ayar Formu -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-gear"></i> PDKS Ayarları</h3>
                    </div>
                    <form id="ayarForm">
                        <div class="card-body">
                            <div class="alert alert-info d-flex align-items-start" role="alert">
                                <i class="bi bi-info-circle me-2 mt-1"></i>
                                <div>
                                    Bu ayarlar <strong>PDKS masaüstü uygulaması</strong> tarafından kullanılır ve
                                    uygulama yeniden başlatılmadan birkaç dakika içinde geçerli olur.
                                    Hareketsizlikten doğan kayıtlar personelin son klavye/fare hareketinin saatiyle yazılır
                                    ve <span class="badge text-bg-dark"><i class="bi bi-robot me-1"></i>Otomatik</span>
                                    olarak işaretlenir.
                                </div>
                            </div>
                            <div id="ayarAlanlari">
                                <div class="text-center p-3"><div class="spinner-border text-primary" role="status"></div></div>
                            </div>
                        </div>
                        <?php if ($pagePermissions['can_edit']): ?>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<?php include __DIR__ . '/../includes/scripts.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script>
const permissions = <?= json_encode($pagePermissions) ?>;

function esc(str) {
    return $('<div>').text(str ?? '').html();
}

function formatDate(d) {
    if (!d) return '-';
    const dt = new Date(d.replace(' ', 'T'));
    return isNaN(dt) ? d : dt.toLocaleDateString('tr-TR', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (!r.success) return;
        $('#stat-ayar').text(r.data.ayarSayisi);
        $('#stat-otobugun').text(r.data.otoBugun);
        $('#stat-oto30').text(r.data.oto30);
        const s = r.data.sonGuncelleme;
        $('#stat-songuncelleme').html(s ? `${formatDate(s.tarih)}<br><small class="text-muted fw-normal">${esc(s.kisi || '')}</small>` : '-');
    });
}

/** Ayar satırının tipine göre form alanını üretir (sayi / metin / bit / gizli). */
function ayarAlani(a) {
    const id = 'ayar_' + a.pdks_ayar_anahtar;
    const ad = `ayar[${a.pdks_ayar_anahtar}]`;
    const kilit = permissions.can_edit ? '' : 'disabled';

    if (a.pdks_ayar_tip === 'bit') {
        return `<div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="${id}" name="${ad}" value="1"
                           ${a.pdks_ayar_deger === '1' ? 'checked' : ''} ${kilit}>
                    <label class="form-check-label" for="${id}"></label>
                </div>`;
    }
    if (a.pdks_ayar_tip === 'sayi') {
        const sinir = (a.pdks_ayar_min !== null ? `min="${a.pdks_ayar_min}" ` : '')
                    + (a.pdks_ayar_max !== null ? `max="${a.pdks_ayar_max}"` : '');
        const birim = a.pdks_ayar_birim ? `<span class="input-group-text">${esc(a.pdks_ayar_birim)}</span>` : '';
        return `<div class="input-group" style="max-width:220px">
                    <input type="number" class="form-control" id="${id}" name="${ad}" step="1" required
                           value="${esc(a.pdks_ayar_deger)}" ${sinir} ${kilit}>
                    ${birim}
                </div>
                ${a.pdks_ayar_min !== null && a.pdks_ayar_max !== null
                    ? `<small class="text-muted">${a.pdks_ayar_min} - ${a.pdks_ayar_max} ${esc(a.pdks_ayar_birim || '')}</small>` : ''}`;
    }
    if (a.pdks_ayar_tip === 'gizli') {
        // Değer sunucudan gelmez; boş bırakılırsa mevcut değer korunur
        const yer = a.gizli_tanimli == 1 ? 'Tanımlı · değiştirmek için yeni değeri yazın' : 'Tanımlı değil';
        return `<input type="password" class="form-control" id="${id}" name="${ad}" value=""
                       placeholder="${yer}" autocomplete="new-password" ${kilit}>`;
    }
    return `<input type="text" class="form-control" id="${id}" name="${ad}" value="${esc(a.pdks_ayar_deger)}" ${kilit}>`;
}

function loadAyarlar() {
    $.post('', { action: 'list' }, r => {
        if (!r.success) { showToast(r.message, 'error'); return; }
        const alan = $('#ayarAlanlari').empty();
        if (!r.data.length) {
            alan.html('<p class="text-center text-muted mb-0">Tanımlı PDKS ayarı yok</p>');
            return;
        }
        r.data.forEach(a => {
            alan.append(`
                <div class="row ayar-satir py-3 align-items-center">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1" for="ayar_${esc(a.pdks_ayar_anahtar)}">${esc(a.pdks_ayar_baslik)}</label>
                        <div class="text-muted small">${esc(a.pdks_ayar_aciklama)}</div>
                        <div class="ayar-anahtar text-body-tertiary">${esc(a.pdks_ayar_anahtar)}
                            ${a.guncelleme_tarihi ? ' · son değişiklik ' + formatDate(a.guncelleme_tarihi) : ''}</div>
                    </div>
                    <div class="col-md-6 mt-2 mt-md-0">${ayarAlani(a)}</div>
                </div>`);
        });
    });
}

$('#ayarForm').on('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('action', 'save');
    $.ajax({ url: '', type: 'POST', data: fd, processData: false, contentType: false,
        success: r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { loadStats(); loadAyarlar(); }
        },
        error: () => showToast('Sunucu hatası', 'error')
    });
});

$(document).ready(() => {
    loadStats();
    loadAyarlar();
});
</script>
</body>
</html>
