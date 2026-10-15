<?php
/**
 * Admin Panel - Kargo Gönder
 *
 * Aras Kargo'da gönderi oluşturur (SetOrder), portaldan açılan gönderileri listeler
 * ve A4 çoklu etiket basar (?etiket=1,2,3).
 * Gönderiler kargo_gonderiler tablosuna Kaynak = 'PORTAL' olarak yazılır;
 * takip numarası şube kabulünde atanır ve aras_kargo_senkron göreviyle eşlenir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/ArasKargo.php';
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

// ====================================================================
// Etiket yazdırma (A4, sayfa başına 16 etiket, her parçaya bir etiket)
// ====================================================================
if (isset($_GET['etiket'])) {
    $idler = array_values(array_unique(array_map('intval', array_filter(explode(',', (string)$_GET['etiket']), 'is_numeric'))));
    if (!$idler) exit('Yazdırılacak gönderi seçilmedi.');

    $yerTutucu = implode(',', array_fill(0, count($idler), '?'));
    $kayitlar = $db->fetchAll("
        SELECT kargo_gonderiler_id, kargo_gonderiler_Gonderici, kargo_gonderiler_Alici, kargo_gonderiler_AliciTelefon,
               kargo_gonderiler_AliciAdres, kargo_gonderiler_AliciIl, kargo_gonderiler_AliciIlce,
               kargo_gonderiler_EntegrasyonKodu, kargo_gonderiler_Barkod, kargo_gonderiler_Adet,
               kargo_gonderiler_Desi, kargo_gonderiler_OdemeTipi, kargo_gonderiler_TahsilatTutari,
               kargo_gonderiler_Aciklama
        FROM kargo_gonderiler
        WHERE kargo_gonderiler_id IN ($yerTutucu)
          AND kargo_gonderiler_Kaynak = 'PORTAL' AND kargo_gonderiler_Durum = 1
          AND kargo_gonderiler_Barkod IS NOT NULL
        ORDER BY kargo_gonderiler_id
    ", $idler);

    // Her parça ayrı etiket
    $etiketler = [];
    foreach ($kayitlar as $k) {
        $adet = max(1, (int)$k['kargo_gonderiler_Adet']);
        foreach (ArasKargo::parcaBarkodlari($k['kargo_gonderiler_Barkod'], $adet) as $i => $barkod) {
            $etiketler[] = $k + ['barkod' => $barkod, 'parca' => ($i + 1) . '/' . $adet];
        }
    }
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kargo Etiketleri</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        @page { margin: 5mm; size: A4; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f0f0f0; }
        .arac-cubugu { text-align: center; padding: 10px; }
        .arac-cubugu button { padding: 8px 18px; font-size: 14px; cursor: pointer; }
        .sayfa {
            width: 200mm; height: 277mm; display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            grid-template-rows: repeat(8, minmax(0, 1fr));
            gap: 3mm; box-sizing: border-box; background: #fff;
            margin: 10px auto; border: 1px solid #ddd; padding: 2mm; page-break-after: always;
        }
        .etiket { box-sizing: border-box; padding: 3mm 4mm; position: relative; border: 1px dashed #999; overflow: hidden; }
        .ust { border-bottom: 2px solid #000; padding-bottom: 2px; margin-bottom: 3px; display: flex; justify-content: space-between; align-items: center; gap: 4px; }
        .gonderici { font-size: 7.5px; font-weight: bold; line-height: 1.2; max-width: 68%; }
        .aras { font-size: 12px; font-weight: 900; letter-spacing: -1px; white-space: nowrap; }
        .satir-ust { display: flex; justify-content: space-between; font-size: 8px; color: #555; text-transform: uppercase; }
        .satir-ust b { color: #000; }
        .alici { font-size: 12.5px; font-weight: bold; margin: 1px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .adres { font-size: 8.5px; line-height: 1.25; height: 21px; overflow: hidden; }
        .il { font-size: 10.5px; font-weight: bold; margin-top: 2px; }
        .tel { font-size: 9px; }
        .not { font-size: 8px; font-weight: bold; }
        .barkod { text-align: center; position: absolute; bottom: 1.5mm; left: 0; width: 100%; }
        .barkod svg { max-width: 95%; height: 46px; }
        @media print {
            body { background: transparent; }
            .arac-cubugu { display: none; }
            .sayfa { margin: 0; border: none; padding: 0; gap: 2mm; height: 287mm; }
            .etiket { border-color: #ccc; }
        }
    </style>
</head>
<body>
    <div class="arac-cubugu">
        <?= count($kayitlar) ?> gönderi · <?= count($etiketler) ?> etiket
        <button onclick="window.print()">Yazdır</button>
    </div>
    <?php if (!$etiketler): ?>
        <p style="text-align:center">Etiket basılabilecek gönderi bulunamadı.</p>
    <?php endif; ?>

    <?php foreach ($etiketler as $i => $e):
        $sayfaBasi = $i % 16 === 0;
        $sayfaSonu = $i % 16 === 15 || $i === count($etiketler) - 1;
        $tahsilat  = (float)$e['kargo_gonderiler_TahsilatTutari'];
    ?>
        <?php if ($sayfaBasi): ?><div class="sayfa"><?php endif; ?>
        <div class="etiket">
            <div class="ust">
                <div class="gonderici">GÖNDERİCİ: <?= $h($e['kargo_gonderiler_Gonderici']) ?></div>
                <div class="aras">ARAS KARGO</div>
            </div>
            <div class="satir-ust">
                <span>Alıcı</span>
                <span>Parça <b><?= $h($e['parca']) ?></b> · Desi <b><?= $h((float)$e['kargo_gonderiler_Desi']) ?></b></span>
            </div>
            <div class="alici"><?= $h($e['kargo_gonderiler_Alici']) ?></div>
            <div class="adres"><?= $h($e['kargo_gonderiler_AliciAdres']) ?></div>
            <div class="il"><?= $h($e['kargo_gonderiler_AliciIlce']) ?> / <?= $h($e['kargo_gonderiler_AliciIl']) ?></div>
            <div class="tel">Tel: <?= $h($e['kargo_gonderiler_AliciTelefon']) ?> · <?= $h($e['kargo_gonderiler_OdemeTipi']) ?></div>
            <?php if ($tahsilat > 0): ?>
                <div class="not">TAHSİLATLI: <?= number_format($tahsilat, 2, ',', '.') ?> ₺</div>
            <?php endif; ?>
            <div class="barkod">
                <svg class="barkod-svg" jsbarcode-format="CODE128" jsbarcode-value="<?= $h($e['barkod']) ?>"
                     jsbarcode-textmargin="0" jsbarcode-fontsize="12" jsbarcode-fontoptions="bold"></svg>
            </div>
        </div>
        <?php if ($sayfaSonu): ?></div><?php endif; ?>
    <?php endforeach; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (document.querySelector('.barkod-svg')) {
                JsBarcode('.barkod-svg').init();
                setTimeout(() => window.print(), 500);
            }
        });
    </script>
</body>
</html>
    <?php
    exit;
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Kargo Gönder';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        switch ($action) {

            // ---------------- InfoBox ----------------
            case 'stats':
                $s = $db->fetchOne("
                    SELECT SUM(CASE WHEN g.kargo_gonderiler_OlusturmaTarihi >= CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS bugun,
                           SUM(CASE WHEN g.kargo_gonderiler_TakipNo IS NULL THEN 1 ELSE 0 END) AS bekleyen,
                           SUM(CASE WHEN g.kargo_gonderiler_TakipNo IS NOT NULL AND ISNULL(d.kargo_durumlari_TeslimMi, 0) = 0 THEN 1 ELSE 0 END) AS yolda,
                           SUM(CASE WHEN d.kargo_durumlari_TeslimMi = 1 THEN 1 ELSE 0 END) AS teslim
                    FROM kargo_gonderiler g
                    LEFT JOIN kargo_durumlari d ON d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu
                    WHERE g.kargo_gonderiler_Durum = 1 AND g.kargo_gonderiler_Kaynak = 'PORTAL'
                ");
                echo json_encode(['success' => true, 'data' => array_map('intval', $s ?: [])], $jsonBayrak);
                break;

            // ---------------- Liste (server-side) ----------------
            case 'list':
                $where  = ["g.kargo_gonderiler_Durum = 1", "g.kargo_gonderiler_Kaynak = 'PORTAL'"];
                $params = [];

                if (($_POST['f_bas'] ?? '') !== '') {
                    $where[]  = "g.kargo_gonderiler_OlusturmaTarihi >= ?";
                    $params[] = $_POST['f_bas'];
                }
                if (($_POST['f_bit'] ?? '') !== '') {
                    $where[]  = "g.kargo_gonderiler_OlusturmaTarihi < DATEADD(DAY, 1, CAST(? AS DATE))";
                    $params[] = $_POST['f_bit'];
                }
                switch ($_POST['f_takip'] ?? '') {
                    case 'bekleyen': $where[] = "g.kargo_gonderiler_TakipNo IS NULL"; break;
                    case 'yolda':
                        $where[] = "g.kargo_gonderiler_TakipNo IS NOT NULL AND NOT EXISTS (SELECT 1 FROM kargo_durumlari d WHERE d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu AND d.kargo_durumlari_TeslimMi = 1)";
                        break;
                    case 'teslim':
                        $where[] = "EXISTS (SELECT 1 FROM kargo_durumlari d WHERE d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu AND d.kargo_durumlari_TeslimMi = 1)";
                        break;
                }
                if (trim($_POST['f_arama'] ?? '') !== '') {
                    $where[] = "(g.kargo_gonderiler_Alici LIKE ? OR g.kargo_gonderiler_AliciTelefon LIKE ? OR g.kargo_gonderiler_TakipNo LIKE ?
                                 OR g.kargo_gonderiler_EntegrasyonKodu LIKE ? OR g.kargo_gonderiler_Barkod LIKE ? OR g.kargo_gonderiler_AliciIl LIKE ?)";
                    $ara = '%' . trim($_POST['f_arama']) . '%';
                    array_push($params, $ara, $ara, $ara, $ara, $ara, $ara);
                }
                $whereSql = implode(' AND ', $where);

                $siralamaKolonlari = [
                    1 => 'g.kargo_gonderiler_OlusturmaTarihi',
                    2 => 'g.kargo_gonderiler_EntegrasyonKodu',
                    3 => 'g.kargo_gonderiler_Alici',
                    4 => 'g.kargo_gonderiler_AliciIl',
                    5 => 'g.kargo_gonderiler_Adet',
                    6 => 'g.kargo_gonderiler_TakipNo',
                    7 => 'g.kargo_gonderiler_DurumKodu',
                ];
                $siraKolon = $siralamaKolonlari[(int)($_POST['order'][0]['column'] ?? 1)] ?? $siralamaKolonlari[1];
                $siraYon   = strtolower($_POST['order'][0]['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
                $orderSql  = "{$siraKolon} {$siraYon}, g.kargo_gonderiler_id DESC";

                $baslangic = max(0, (int)($_POST['start'] ?? 0));
                $uzunluk   = (int)($_POST['length'] ?? 25);
                $uzunluk   = $uzunluk < 1 ? 25 : min($uzunluk, 500);

                $toplam = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS a FROM kargo_gonderiler WHERE kargo_gonderiler_Durum = 1 AND kargo_gonderiler_Kaynak = 'PORTAL'"
                )['a'] ?? 0);
                $filtreli = (int)($db->fetchOne("SELECT COUNT(*) AS a FROM kargo_gonderiler g WHERE {$whereSql}", $params)['a'] ?? 0);

                $satirlar = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT g.kargo_gonderiler_id
                        FROM kargo_gonderiler g
                        WHERE {$whereSql}
                        ORDER BY {$orderSql}
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT g.kargo_gonderiler_id                                         AS id,
                           CONVERT(VARCHAR(16), g.kargo_gonderiler_OlusturmaTarihi, 120)   AS tarih,
                           g.kargo_gonderiler_EntegrasyonKodu                            AS entegrasyon_kodu,
                           g.kargo_gonderiler_Barkod                                     AS barkod,
                           g.kargo_gonderiler_Alici                                      AS alici,
                           g.kargo_gonderiler_AliciTelefon                               AS telefon,
                           g.kargo_gonderiler_AliciIl                                    AS il,
                           g.kargo_gonderiler_AliciIlce                                  AS ilce,
                           g.kargo_gonderiler_Adet                                       AS adet,
                           g.kargo_gonderiler_Desi                                       AS desi,
                           g.kargo_gonderiler_OdemeTipi                                  AS odeme_tipi,
                           g.kargo_gonderiler_TahsilatTutari                             AS tahsilat,
                           g.kargo_gonderiler_TakipNo                                    AS takip_no,
                           g.kargo_gonderiler_DurumKodu                                  AS durum_kodu,
                           g.kargo_gonderiler_DurumMetni                                 AS durum_metni,
                           d.kargo_durumlari_Ad                                          AS durum_adi,
                           d.kargo_durumlari_Renk                                        AS durum_renk,
                           k.kullanici_ad + ' ' + k.kullanici_soyad                      AS olusturan
                    FROM sayfa s
                    INNER JOIN kargo_gonderiler g ON g.kargo_gonderiler_id = s.kargo_gonderiler_id
                    LEFT JOIN kargo_durumlari d   ON d.kargo_durumlari_Kod = g.kargo_gonderiler_DurumKodu
                    LEFT JOIN kullanicilar k      ON k.kullanici_id = g.kargo_gonderiler_OlusturanKullanici
                    ORDER BY {$orderSql}
                ", array_merge($params, [$baslangic, $uzunluk]));

                echo json_encode([
                    'draw'            => (int)($_POST['draw'] ?? 0),
                    'recordsTotal'    => $toplam,
                    'recordsFiltered' => $filtreli,
                    'data'            => $satirlar,
                ], $jsonBayrak);
                break;

            // ---------------- İl / ilçe ----------------
            case 'iller':
                echo json_encode(['success' => true, 'data' => $db->fetchAll("
                    SELECT s.SehirId AS id, s.SehirAdi AS ad
                    FROM Sehirler s
                    WHERE s.UlkeId = (SELECT TOP 1 UlkeId FROM Ulkeler WHERE IkiliKod = 'TR' OR UcluKod = 'TUR')
                    ORDER BY s.SehirAdi
                ")], $jsonBayrak);
                break;

            case 'ilceler':
                echo json_encode(['success' => true, 'data' => $db->fetchAll(
                    "SELECT ilceId AS id, IlceAdi AS ad FROM Ilceler WHERE SehirId = ? ORDER BY IlceAdi",
                    [(int)($_POST['il_id'] ?? 0)]
                )], $jsonBayrak);
                break;

            // ---------------- Gönderi oluştur (GERÇEK gönderi açar) ----------------
            case 'gonder':
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Gönderi oluşturma yetkiniz yok.'], $jsonBayrak);
                    break;
                }

                // İl / ilçe adı DB'den alınır (istemciden gelen metne güvenilmez)
                $il   = $db->fetchOne("SELECT SehirAdi AS ad FROM Sehirler WHERE SehirId = ?", [(int)($_POST['il_id'] ?? 0)]);
                $ilce = $db->fetchOne("SELECT IlceAdi AS ad FROM Ilceler WHERE ilceId = ? AND SehirId = ?",
                                      [(int)($_POST['ilce_id'] ?? 0), (int)($_POST['il_id'] ?? 0)]);

                $form = [
                    'alici'      => trim((string)($_POST['alici'] ?? '')),
                    'telefon'    => ArasKargo::telefonNormalize($_POST['telefon'] ?? '') ?? '',
                    'adres'      => trim(preg_replace('/\s+/', ' ', (string)($_POST['adres'] ?? ''))),
                    'il'         => $il['ad'] ?? '',
                    'ilce'       => $ilce['ad'] ?? '',
                    'adet'       => (int)($_POST['adet'] ?? 1),
                    'desi'       => (float)str_replace(',', '.', (string)($_POST['desi'] ?? '1')),
                    'odemeTipi'  => (int)($_POST['odeme_tipi'] ?? 1) === 2 ? 2 : 1,
                    'tahsilat'   => (float)str_replace(',', '.', (string)($_POST['tahsilat'] ?? '0')),
                    'aciklama'   => trim((string)($_POST['aciklama'] ?? '')),
                    'faturaNo'   => trim((string)($_POST['fatura_no'] ?? '')),
                    'irsaliyeNo' => trim((string)($_POST['irsaliye_no'] ?? '')),
                ];

                $hatalar = [];
                if (mb_strlen($form['alici']) < 3)                    $hatalar[] = 'Alıcı adı en az 3 karakter olmalı.';
                if ($form['telefon'] === '')                          $hatalar[] = 'Telefon geçersiz: 10 haneli numara girin (ör. 0532 123 45 67).';
                if (mb_strlen($form['adres']) < 10)                   $hatalar[] = 'Adres en az 10 karakter olmalı.';
                if ($form['il'] === '' || $form['ilce'] === '')       $hatalar[] = 'İl ve ilçe seçilmeli.';
                if ($form['adet'] < 1 || $form['adet'] > 99)          $hatalar[] = 'Parça adedi 1–99 arası olmalı.';
                if ($form['desi'] <= 0)                               $hatalar[] = 'Desi 0\'dan büyük olmalı.';
                if ($form['tahsilat'] < 0)                            $hatalar[] = 'Tahsilat tutarı negatif olamaz.';
                if ($hatalar) {
                    echo json_encode(['success' => false, 'message' => implode(' ', $hatalar)], $jsonBayrak);
                    break;
                }

                $aras = new ArasKargo($db, null, (int)$user['kullanici_id'], 'KARGO_GONDER');
                if (!$aras->hazirMi()) {
                    echo json_encode(['success' => false, 'message' => $aras->getHata()], $jsonBayrak);
                    break;
                }

                $r = $aras->gonderiOlustur($form);
                if (!$r['success']) {
                    echo json_encode(['success' => false, 'message' => $r['message']], $jsonBayrak);
                    break;
                }

                // Aras'ta açıldı; kayıt yazılamazsa kod kullanıcıya bildirilir (gönderi kaybolmasın)
                try {
                    $id = $aras->portalKaydiEkle($form, $r);
                } catch (Throwable $e) {
                    error_log('kargo-gonder kayıt hatası: ' . $e->getMessage());
                    echo json_encode([
                        'success' => false,
                        'message' => "Gönderi Aras'ta açıldı (entegrasyon kodu: {$r['entegrasyonKodu']}) ancak portala kaydedilemedi: " . $e->getMessage(),
                    ], $jsonBayrak);
                    break;
                }

                echo json_encode([
                    'success'          => true,
                    'message'          => "Gönderi oluşturuldu. Entegrasyon kodu: {$r['entegrasyonKodu']}",
                    'id'               => $id,
                    'entegrasyon_kodu' => $r['entegrasyonKodu'],
                ], $jsonBayrak);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Entegrasyon hazır mı? (sayfa üstünde uyarı)
$arasKontrol = new ArasKargo($db);
$arasHazir   = $arasKontrol->hazirMi();
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
        .info-box { transition: transform 0.2s; cursor: pointer; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .info-box.secili { outline: 2px solid var(--bs-primary); }
        .filtre-basligi { cursor: pointer; }
        #tablo td { vertical-align: middle; white-space: nowrap; }
        #tablo td.metin { white-space: normal; min-width: 180px; }
        .kod { font-family: var(--bs-font-monospace); }
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

                    <?php if (!$arasHazir): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($arasKontrol->getHata()) ?>
                    </div>
                    <?php endif; ?>

                    <!-- INFOBOX: tıklanınca filtre uygulanır -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-filtre="bugun">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-send"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Gönderilen</span>
                                    <span class="info-box-number" id="ib_bugun">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-filtre="bekleyen">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Şube Kabulü Bekleyen</span>
                                    <span class="info-box-number" id="ib_bekleyen">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-filtre="yolda">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-truck"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Yolda</span>
                                    <span class="info-box-number" id="ib_yolda">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box" data-filtre="teslim">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Teslim Edildi</span>
                                    <span class="info-box-number" id="ib_teslim">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-primary card-outline">
                        <div class="card-header d-flex align-items-center">
                            <h3 class="card-title flex-grow-1 mb-0"><i class="bi bi-box-arrow-up-right"></i> Portaldan Gönderilenler</h3>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-secondary" id="btnEtiketSecili" disabled>
                                    <i class="bi bi-printer"></i> Seçilenlerin Etiketi (<span id="seciliSayi">0</span>)
                                </button>
                                <?php if ($pagePermissions['can_add'] && $arasHazir): ?>
                                <button class="btn btn-sm btn-primary" id="btnYeni">
                                    <i class="bi bi-plus-lg"></i> Yeni Gönderi
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
                                            <div class="col-md-3">
                                                <label class="form-label">Takip Durumu</label>
                                                <select class="form-select filtre-select2" id="f_takip">
                                                    <option value="">Tümü</option>
                                                    <option value="bekleyen">Şube kabulü bekleyen</option>
                                                    <option value="yolda">Yolda</option>
                                                    <option value="teslim">Teslim edildi</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="f_arama" placeholder="Alıcı, telefon, kod, barkod, il...">
                                            </div>
                                            <div class="col-md-2">
                                                <button class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                                <button class="btn btn-outline-secondary" id="btnTemizle" title="Temizle"><i class="bi bi-x-circle"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <table id="tablo" class="table table-bordered table-striped table-hover w-100">
                                <thead>
                                    <tr>
                                        <th class="text-center">
                                            <div class="form-check form-switch d-flex justify-content-center">
                                                <input class="form-check-input" type="checkbox" role="switch" id="secTumu">
                                                <label class="form-check-label" for="secTumu"></label>
                                            </div>
                                        </th>
                                        <th>Tarih</th>
                                        <th>Entegrasyon / Barkod</th>
                                        <th>Alıcı</th>
                                        <th>İlçe / İl</th>
                                        <th>Adet / Desi</th>
                                        <th>Takip No</th>
                                        <th>Durum</th>
                                        <th>Oluşturan</th>
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

    <!-- Yeni gönderi -->
    <div class="modal fade" id="modalGonderi" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="formGonderi" autocomplete="off">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-send"></i> Yeni Aras Kargo Gönderisi</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label">Alıcı (Ad Soyad / Unvan) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="alici" maxlength="250" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Telefon <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="telefon" id="g_telefon" maxlength="20" placeholder="0532 123 45 67" required>
                                <div class="invalid-feedback">10 haneli numara girin (ör. 0532 123 45 67).</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">İl <span class="text-danger">*</span></label>
                                <select class="form-select" name="il_id" id="g_il" required>
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">İlçe <span class="text-danger">*</span></label>
                                <select class="form-select" name="ilce_id" id="g_ilce" required disabled>
                                    <option value="">Önce il seçiniz...</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Adres <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="adres" rows="2" maxlength="500" required placeholder="Mahalle, cadde/sokak, no, daire"></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Parça Adedi <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="adet" value="1" min="1" max="99" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Desi / Kg <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="desi" value="1" min="0.1" step="0.1" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Ödeme</label>
                                <select class="form-select" name="odeme_tipi" id="g_odeme">
                                    <option value="1" selected>Gönderici Öder</option>
                                    <option value="2">Alıcı Öder</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tahsilat (₺)</label>
                                <input type="number" class="form-control" name="tahsilat" min="0" step="0.01" placeholder="Yok">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fatura No</label>
                                <input type="text" class="form-control" name="fatura_no" maxlength="50">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">İrsaliye No</label>
                                <input type="text" class="form-control" name="irsaliye_no" maxlength="50">
                            </div>
                            <div class="col-12">
                                <label class="form-label">İçerik / Açıklama</label>
                                <input type="text" class="form-control" name="aciklama" maxlength="200" placeholder="Etikete ve Aras kaydına yazılır">
                            </div>
                        </div>
                        <div class="alert alert-warning mt-3 mb-0 small">
                            <i class="bi bi-info-circle"></i> Kaydet dediğinizde Aras Kargo'da <b>gerçek gönderi</b> açılır.
                            Takip numarası, kargo şubeye teslim edilince atanır ve otomatik senkronla buraya gelir.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                        <button type="submit" class="btn btn-primary" id="btnGonder"><i class="bi bi-send"></i> Gönderi Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        let tablo;
        const secili = new Set();

        const esc = s => $('<div>').text(s ?? '').html();
        const tarihStr = d => d.toISOString().slice(0, 10);
        const tarihTr = s => s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' + s.slice(0, 4) + ' ' + s.slice(11) : '-';

        function durumBadge(r) {
            if (!r.takip_no) return '<span class="badge text-bg-secondary" title="Şube kabulünde takip no atanır">Şube Kabulü Bekleniyor</span>';
            if (r.durum_kodu === null) return '-';
            return `<span class="badge text-bg-${esc(r.durum_renk || 'secondary')}" title="${esc(r.durum_metni)}">${esc(r.durum_adi || r.durum_metni)}</span>`;
        }

        // ArasKargo::telefonNormalize ile aynı kural: 0 + 10 hane, alan kodu 2/3/4/5/8
        function telefonNormalize(s) {
            let r = String(s || '').replace(/\D/g, '');
            if (r.length === 12 && r.startsWith('90')) r = r.slice(2);
            else if (r.length === 11 && r[0] === '0') r = r.slice(1);
            return r.length === 10 && '23458'.includes(r[0]) ? '0' + r : null;
        }
        const telefonGoster = t => `${t.slice(0, 4)} ${t.slice(4, 7)} ${t.slice(7, 9)} ${t.slice(9, 11)}`;

        function etiketAc(idler) {
            if (!idler.length) return;
            window.open('?etiket=' + idler.join(','), '_blank');
        }

        function seciliGuncelle() {
            $('#seciliSayi').text(secili.size);
            $('#btnEtiketSecili').prop('disabled', secili.size === 0);
        }

        function loadStats() {
            $.post('', {action: 'stats'}, function (r) {
                if (!r.success) return;
                ['bugun', 'bekleyen', 'yolda', 'teslim'].forEach(k => $('#ib_' + k).text(r.data[k] ?? 0));
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
                        d.action  = 'list';
                        d.f_bas   = $('#f_bas').val();
                        d.f_bit   = $('#f_bit').val();
                        d.f_takip = $('#f_takip').val();
                        d.f_arama = $('#f_arama').val();
                    },
                    error: function () { showToast('Liste yüklenemedi.', 'error'); }
                },
                columns: [
                    {
                        data: 'id', orderable: false, className: 'text-center',
                        render: id => `<div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input sec" type="checkbox" role="switch" id="sec_${id}" value="${id}" ${secili.has(id) ? 'checked' : ''}>
                                <label class="form-check-label" for="sec_${id}"></label></div>`
                    },
                    {data: 'tarih', render: d => tarihTr(d)},
                    {data: 'entegrasyon_kodu', render: (d, t, r) => `<span class="kod">${esc(d)}</span><br><small class="text-muted kod">${esc(r.barkod)}</small>`},
                    {data: 'alici', className: 'metin', render: (d, t, r) => `${esc(d)}<br><small class="text-muted">${esc(r.telefon)}</small>`},
                    {data: 'il', render: (d, t, r) => `${esc(r.ilce)} / ${esc(d)}`},
                    {
                        data: 'adet', className: 'text-center',
                        render: (d, t, r) => `${d} / ${Number(r.desi)}`
                            + (Number(r.tahsilat) > 0 ? `<br><span class="badge text-bg-danger">Tahsilat ${Number(r.tahsilat).toLocaleString('tr-TR')} ₺</span>` : '')
                    },
                    {data: 'takip_no', render: d => d ? `<span class="kod">${esc(d)}</span>` : '<span class="text-muted">-</span>'},
                    {data: 'durum_kodu', render: (d, t, r) => durumBadge(r)},
                    {data: 'olusturan', render: d => esc(d) || '-'},
                    {
                        data: null, orderable: false, className: 'text-center',
                        render: r => `<button class="btn btn-sm btn-outline-dark" onclick="etiketAc([${r.id}])" title="Etiket yazdır"><i class="bi bi-printer"></i></button>`
                    }
                ],
                order: [[1, 'desc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
                language: {url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'},
                drawCallback: function () {
                    const ids = this.api().column(0, {page: 'current'}).data().toArray();
                    $('#secTumu').prop('checked', ids.length > 0 && ids.every(id => secili.has(id)));
                }
            });
        }

        function illeriYukle() {
            $.post('', {action: 'iller'}, function (r) {
                if (!r.success) return;
                const $s = $('#g_il');
                r.data.forEach(i => $s.append(new Option(i.ad, i.id)));
            }, 'json');
        }

        $(function () {
            $('.filtre-select2').select2({theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 0});
            $('#g_il, #g_ilce, #g_odeme').select2({theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalGonderi')});
            illeriYukle();
            initTablo();
            tablo.one('draw', loadStats);

            // İl → ilçe
            $('#g_il').on('change', function () {
                const $ilce = $('#g_ilce').empty().append(new Option('Yükleniyor...', '')).prop('disabled', true);
                if (!this.value) { $ilce.empty().append(new Option('Önce il seçiniz...', '')).trigger('change'); return; }
                $.post('', {action: 'ilceler', il_id: this.value}, function (r) {
                    $ilce.empty().append(new Option('Seçiniz...', ''));
                    (r.data || []).forEach(i => $ilce.append(new Option(i.ad, i.id)));
                    $ilce.prop('disabled', false).trigger('change');
                }, 'json');
            });

            $('#btnYeni').on('click', function () {
                $('#formGonderi')[0].reset();
                $('#g_telefon').removeClass('is-invalid');
                $('#g_il').val('').trigger('change');
                $('#g_odeme').val('1').trigger('change');
                new bootstrap.Modal('#modalGonderi').show();
            });

            // Alandan çıkınca gidecek biçimi göster
            $('#g_telefon').on('blur', function () {
                if (!this.value.trim()) { $(this).removeClass('is-invalid'); return; }
                const t = telefonNormalize(this.value);
                $(this).toggleClass('is-invalid', !t);
                if (t) this.value = telefonGoster(t);
            }).on('input', function () { $(this).removeClass('is-invalid'); });

            $('#formGonderi').on('submit', function (e) {
                e.preventDefault();
                if (!telefonNormalize($('#g_telefon').val())) {
                    $('#g_telefon').addClass('is-invalid').trigger('focus');
                    return;
                }
                const veri = $(this).serializeArray().reduce((o, x) => (o[x.name] = x.value, o), {});
                const ilAdi = $('#g_il option:selected').text(), ilceAdi = $('#g_ilce option:selected').text();

                Swal.fire({
                    title: 'Gönderi oluşturulsun mu?',
                    html: `<b>${esc(veri.alici)}</b><br>${esc(ilceAdi)} / ${esc(ilAdi)}<br>${esc(veri.adet)} parça · ${esc(veri.desi)} desi`
                        + (Number(veri.tahsilat) > 0 ? `<br><b class="text-danger">Tahsilat: ${esc(veri.tahsilat)} ₺</b>` : '')
                        + `<br><small class="text-muted">Aras Kargo'da gerçek gönderi açılacak.</small>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Evet, oluştur',
                    cancelButtonText: 'Vazgeç'
                }).then(sonuc => {
                    if (!sonuc.isConfirmed) return;

                    const $b = $('#btnGonder').prop('disabled', true);
                    const eski = $b.html();
                    $b.html('<span class="spinner-border spinner-border-sm"></span> Gönderiliyor...');

                    $.post('', Object.assign({action: 'gonder'}, veri), function (r) {
                        if (!r.success) { showToast(r.message, 'error'); return; }
                        bootstrap.Modal.getInstance('#modalGonderi').hide();
                        tablo.ajax.reload(null, false);
                        loadStats();
                        Swal.fire({
                            title: 'Gönderi oluşturuldu',
                            html: `Entegrasyon kodu: <b class="kod">${esc(r.entegrasyon_kodu)}</b>`,
                            icon: 'success',
                            showCancelButton: true,
                            confirmButtonText: '<i class="bi bi-printer"></i> Etiketi Yazdır',
                            cancelButtonText: 'Kapat'
                        }).then(s => { if (s.isConfirmed) etiketAc([r.id]); });
                    }, 'json')
                    .fail(() => showToast('İstek başarısız. Aras\'ta gönderi açılmış olabilir; listeyi kontrol edin.', 'error'))
                    .always(() => $b.prop('disabled', false).html(eski));
                });
            });

            // Seçim
            $('#tablo').on('change', '.sec', function () {
                const id = Number(this.value);
                this.checked ? secili.add(id) : secili.delete(id);
                seciliGuncelle();
            });
            $('#secTumu').on('change', function () {
                const ac = this.checked;
                $('#tablo .sec').each(function () {
                    this.checked = ac;
                    ac ? secili.add(Number(this.value)) : secili.delete(Number(this.value));
                });
                seciliGuncelle();
            });
            $('#btnEtiketSecili').on('click', () => etiketAc([...secili]));

            // Filtre
            $('#btnFiltrele').on('click', () => tablo.ajax.reload());
            $('#f_arama').on('keypress', e => { if (e.which === 13) tablo.ajax.reload(); });
            $('#btnTemizle').on('click', function () {
                $('#f_bas, #f_bit, #f_arama').val('');
                $('#f_takip').val('').trigger('change.select2');
                $('.info-box').removeClass('secili');
                tablo.ajax.reload();
            });

            // InfoBox → hızlı filtre
            $('.info-box').on('click', function () {
                $('.info-box').removeClass('secili');
                $(this).addClass('secili');
                const f = $(this).data('filtre');
                if (f === 'bugun') {
                    $('#f_bas, #f_bit').val(tarihStr(new Date()));
                    $('#f_takip').val('').trigger('change.select2');
                } else {
                    $('#f_bas, #f_bit').val('');
                    $('#f_takip').val(f).trigger('change.select2');
                }
                tablo.ajax.reload();
            });
        });
    </script>
</body>
</html>
