<?php
/**
 * Admin Panel - Cron Yönetimi
 * Merkezi cron: görevler, zamanlayıcılar, manuel tetik, çalışma geçmişi, sıklık şablonları
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../cron/tasks.php';
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cron Yönetimi';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Plesk cron satırı — CLI yolu (CLI'da key doğrulaması yapılmaz)
$runnerYol = str_replace('/', DIRECTORY_SEPARATOR, dirname(__DIR__) . '/cron/runner.php');
$phpYol    = str_ireplace('php-cgi.exe', 'php.exe', PHP_BINARY);
$pleskSatir = '"' . $phpYol . '" "' . $runnerYol . '"';

// Web'den tetik için gizli anahtarlı URL (alternatif kullanım)
$cronKey = (string)cronAyar($db, 'cron_secret_key', '');

/**
 * NVARCHAR(MAX) alanlar sqlsrv'de stream olarak gelebilir; metne çevirir.
 */
function cronMetin($deger): string
{
    if (is_resource($deger)) {
        return (string)stream_get_contents($deger);
    }
    return (string)($deger ?? '');
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'stats':
                $stats = [
                    'gorev'     => $db->fetchOne("SELECT COUNT(*) AS a FROM CronGorevler WHERE Durum = 1")['a'] ?? 0,
                    'zamanlama' => $db->fetchOne("SELECT COUNT(*) AS a FROM CronZamanlamalar WHERE Durum = 1")['a'] ?? 0,
                    'basarili'  => $db->fetchOne("SELECT COUNT(*) AS a FROM CronCalismaLog WHERE CronCalismaLog_CalismaDurum = 1 AND CronCalismaLog_BaslangicTarihi >= CAST(GETDATE() AS DATE)")['a'] ?? 0,
                    'hatali'    => $db->fetchOne("SELECT COUNT(*) AS a FROM CronCalismaLog WHERE CronCalismaLog_CalismaDurum = 2 AND CronCalismaLog_BaslangicTarihi >= CAST(GETDATE() AS DATE)")['a'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'gorevler':
                $gorevler = $db->fetchAll("
                    SELECT CronGorevler_Id, CronGorevler_Ad, CronGorevler_Aciklama,
                           CronGorevler_GorevKodu, CronGorevler_MaxSureSn,
                           CAST(CronGorevler_Parametreler AS NVARCHAR(MAX)) AS CronGorevler_Parametreler
                    FROM CronGorevler
                    WHERE Durum = 1
                    ORDER BY CronGorevler_Ad
                ");

                foreach ($gorevler as &$g) {
                    $g['CronGorevler_Parametreler'] = cronMetin($g['CronGorevler_Parametreler']);

                    $g['zamanlamalar'] = $db->fetchAll("
                        SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_Ad, z.CronZamanlamalar_CronIfadesi,
                               CONVERT(VARCHAR(19), z.CronZamanlamalar_BaslangicTarihi, 120) AS baslangic,
                               CONVERT(VARCHAR(19), z.CronZamanlamalar_BitisTarihi, 120) AS bitis,
                               CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120) AS son_calisma,
                               z.CronZamanlamalar_TelafiEt, z.CronZamanlamalar_TelafiSaatSiniri,
                               z.Durum AS zamanlama_durum,
                               sl.CronCalismaLog_CalismaDurum AS son_log_durum,
                               sl.CronCalismaLog_SureSaniye AS son_log_sure
                        FROM CronZamanlamalar z
                        OUTER APPLY (
                            SELECT TOP 1 CronCalismaLog_CalismaDurum, CronCalismaLog_SureSaniye
                            FROM CronCalismaLog
                            WHERE CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                            ORDER BY CronCalismaLog_Id DESC
                        ) sl
                        WHERE z.CronZamanlamalar_GorevId = ?
                        ORDER BY z.CronZamanlamalar_Id
                    ", [$g['CronGorevler_Id']]);
                }
                unset($g);

                echo json_encode(['success' => true, 'data' => $gorevler], JSON_UNESCAPED_UNICODE);
                break;

            case 'tetikle': // görev manuel tetik (parametreler formdan)
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Çalıştırma yetkiniz yok!']);
                    break;
                }

                $gorevKodu = trim($_POST['gorev_kodu'] ?? '');
                $g = $db->fetchOne(
                    "SELECT CronGorevler_Id, CronGorevler_MaxSureSn FROM CronGorevler WHERE CronGorevler_GorevKodu = ? AND Durum = 1",
                    [$gorevKodu]
                );

                if (!$g) {
                    echo json_encode(['success' => false, 'message' => 'Görev bulunamadı!']);
                    break;
                }

                $gorevId   = (int)$g['CronGorevler_Id'];
                $maxSureSn = (int)$g['CronGorevler_MaxSureSn'];

                // Overlap koruması: kullanıcı sabırsızlanıp mükerrer iş üretmesin
                if (cronCalisiyorMu($db, $gorevId, $maxSureSn)) {
                    echo json_encode(['success' => false, 'message' => 'Bu görev şu anda çalışıyor. Tetik atlandı.'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $params = json_decode($_POST['parametreler'] ?? '{}', true) ?: [];

                set_time_limit($maxSureSn);
                $basZaman = microtime(true);
                $logId = cronLogOlustur($db, $gorevId, null, $params, 0, (int)$user['kullanici_id']);

                $sonuc = gorevCalistir($gorevKodu, $params, $db);

                cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $basZaman, $sonuc['cikti'] ?? '');

                echo json_encode([
                    'success' => (int)$sonuc['durum'] === 1,
                    'message' => $sonuc['sonuc'],
                    'durum'   => $sonuc['durum'],
                    'cikti'   => $sonuc['cikti'] ?? '',
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'zamanlama_tetikle': // zamanlama manuel tetik (parametreler DB'den)
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Çalıştırma yetkiniz yok!']);
                    break;
                }

                $zid = (int)($_POST['zamanlama_id'] ?? 0);
                $z = $db->fetchOne("
                    SELECT z.CronZamanlamalar_GorevId,
                           CAST(z.CronZamanlamalar_SabitParametreler AS NVARCHAR(MAX)) AS SabitParametreler,
                           g.CronGorevler_GorevKodu, g.CronGorevler_MaxSureSn
                    FROM CronZamanlamalar z
                    INNER JOIN CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
                    WHERE z.CronZamanlamalar_Id = ?
                ", [$zid]);

                if (!$z) {
                    echo json_encode(['success' => false, 'message' => 'Zamanlama bulunamadı!']);
                    break;
                }

                $gorevId   = (int)$z['CronZamanlamalar_GorevId'];
                $maxSureSn = (int)$z['CronGorevler_MaxSureSn'];

                if (cronCalisiyorMu($db, $gorevId, $maxSureSn)) {
                    echo json_encode(['success' => false, 'message' => 'Bu görev şu anda çalışıyor. Tetik atlandı.'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $params = json_decode(cronMetin($z['SabitParametreler']), true) ?: [];

                set_time_limit($maxSureSn);
                $basZaman = microtime(true);
                $logId = cronLogOlustur($db, $gorevId, $zid, $params, 0, (int)$user['kullanici_id']);

                $sonuc = gorevCalistir($z['CronGorevler_GorevKodu'], $params, $db);

                cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $basZaman, $sonuc['cikti'] ?? '');

                echo json_encode([
                    'success' => (int)$sonuc['durum'] === 1,
                    'message' => $sonuc['sonuc'],
                    'durum'   => $sonuc['durum'],
                    'cikti'   => $sonuc['cikti'] ?? '',
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'zamanlama_get':
                $zid = (int)($_POST['zamanlama_id'] ?? 0);
                $z = $db->fetchOne("
                    SELECT CronZamanlamalar_Id, CronZamanlamalar_GorevId, CronZamanlamalar_Ad,
                           CronZamanlamalar_CronIfadesi,
                           CONVERT(VARCHAR(19), CronZamanlamalar_BaslangicTarihi, 120) AS baslangic,
                           CONVERT(VARCHAR(19), CronZamanlamalar_BitisTarihi, 120) AS bitis,
                           CAST(CronZamanlamalar_SabitParametreler AS NVARCHAR(MAX)) AS SabitParametreler,
                           CronZamanlamalar_TelafiEt, CronZamanlamalar_TelafiSaatSiniri, Durum
                    FROM CronZamanlamalar
                    WHERE CronZamanlamalar_Id = ?
                ", [$zid]);

                if ($z) {
                    $z['SabitParametreler'] = cronMetin($z['SabitParametreler']);
                }

                echo json_encode(['success' => true, 'data' => $z], JSON_UNESCAPED_UNICODE);
                break;

            case 'zamanlama_kaydet':
                $zid = (int)($_POST['zamanlama_id'] ?? 0);

                if ($zid > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($zid === 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $ifade = trim($_POST['cron_ifadesi'] ?? '');
                if (count(preg_split('/\s+/', $ifade)) !== 5) {
                    echo json_encode(['success' => false, 'message' => 'Cron ifadesi 5 alan olmalı (dk sa gün ay haftagünü)!'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $sabitParams = trim($_POST['sabit_parametreler'] ?? '');
                if ($sabitParams !== '' && json_decode($sabitParams) === null && json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'message' => 'Sabit parametreler geçerli JSON değil!'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $data = [
                    'CronZamanlamalar_GorevId'           => (int)($_POST['gorev_id'] ?? 0),
                    'CronZamanlamalar_Ad'                => trim($_POST['zamanlama_adi'] ?? ''),
                    'CronZamanlamalar_CronIfadesi'       => $ifade,
                    'CronZamanlamalar_BaslangicTarihi'   => !empty($_POST['baslangic']) ? str_replace('T', ' ', $_POST['baslangic']) . ':00' : date('Y-m-d H:i:s'),
                    'CronZamanlamalar_BitisTarihi'       => !empty($_POST['bitis']) ? str_replace('T', ' ', $_POST['bitis']) . ':00' : null,
                    'CronZamanlamalar_SabitParametreler' => $sabitParams ?: null,
                    'CronZamanlamalar_TelafiEt'          => isset($_POST['telafi_et']) ? 1 : 0,
                    'CronZamanlamalar_TelafiSaatSiniri'  => max(1, (int)($_POST['telafi_saat'] ?? 6)),
                    'Durum'                              => isset($_POST['durum']) ? 1 : 0,
                    'GuncelleyenKullanici'               => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'                   => date('Y-m-d H:i:s'),
                ];

                if ($zid > 0) {
                    $db->update('CronZamanlamalar', $data, ['CronZamanlamalar_Id' => $zid]);
                    echo json_encode(['success' => true, 'message' => 'Zamanlayıcı güncellendi.'], JSON_UNESCAPED_UNICODE);
                } else {
                    $data['OlusturanKullanici'] = (int)$user['kullanici_id'];
                    $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $db->insert('CronZamanlamalar', $data);
                    echo json_encode(['success' => true, 'message' => 'Zamanlayıcı eklendi.'], JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'zamanlama_durum': // aktif/pasif toggle
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']);
                    break;
                }
                $zid = (int)($_POST['zamanlama_id'] ?? 0);
                $db->execute("
                    UPDATE CronZamanlamalar
                    SET Durum = CASE WHEN Durum = 1 THEN 0 ELSE 1 END,
                        GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE CronZamanlamalar_Id = ?
                ", [(int)$user['kullanici_id'], $zid]);
                echo json_encode(['success' => true, 'message' => 'Durum değiştirildi.'], JSON_UNESCAPED_UNICODE);
                break;

            case 'zamanlama_sil':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $zid = (int)($_POST['zamanlama_id'] ?? 0);
                $db->execute("DELETE FROM CronZamanlamalar WHERE CronZamanlamalar_Id = ?", [$zid]);
                echo json_encode(['success' => true, 'message' => 'Zamanlayıcı silindi.'], JSON_UNESCAPED_UNICODE);
                break;

            case 'sablonlar': // zamanlama modalındaki dropdown (sadece aktifler)
                $sablonlar = $db->fetchAll("
                    SELECT CronSiklikSablonlari_Ad, CronSiklikSablonlari_CronIfadesi
                    FROM CronSiklikSablonlari
                    WHERE Durum = 1
                    ORDER BY CronSiklikSablonlari_SiraNo
                ");
                echo json_encode(['success' => true, 'data' => $sablonlar], JSON_UNESCAPED_UNICODE);
                break;

            case 'sablon_liste': // yönetim listesi (pasifler dahil)
                $sablonlar = $db->fetchAll("
                    SELECT CronSiklikSablonlari_Id, CronSiklikSablonlari_Ad,
                           CronSiklikSablonlari_CronIfadesi, CronSiklikSablonlari_SiraNo, Durum
                    FROM CronSiklikSablonlari
                    ORDER BY CronSiklikSablonlari_SiraNo
                ");
                echo json_encode(['success' => true, 'data' => $sablonlar], JSON_UNESCAPED_UNICODE);
                break;

            case 'sablon_get':
                $sid = (int)($_POST['sablon_id'] ?? 0);
                $s = $db->fetchOne("SELECT * FROM CronSiklikSablonlari WHERE CronSiklikSablonlari_Id = ?", [$sid]);
                echo json_encode(['success' => true, 'data' => $s], JSON_UNESCAPED_UNICODE);
                break;

            case 'sablon_kaydet':
                $sid = (int)($_POST['sablon_id'] ?? 0);

                if ($sid > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($sid === 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $ad    = trim($_POST['sablon_adi'] ?? '');
                $ifade = trim($_POST['sablon_ifadesi'] ?? '');

                if ($ad === '') {
                    echo json_encode(['success' => false, 'message' => 'Şablon adı zorunludur!'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                if (count(preg_split('/\s+/', $ifade)) !== 5) {
                    echo json_encode(['success' => false, 'message' => 'Cron ifadesi 5 alan olmalı (dk sa gün ay haftagünü)!'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $data = [
                    'CronSiklikSablonlari_Ad'          => $ad,
                    'CronSiklikSablonlari_CronIfadesi' => $ifade,
                    'CronSiklikSablonlari_SiraNo'      => (int)($_POST['sira_no'] ?? 0),
                    'Durum'                            => isset($_POST['durum']) ? 1 : 0,
                    'GuncelleyenKullanici'             => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'                 => date('Y-m-d H:i:s'),
                ];

                if ($sid > 0) {
                    $db->update('CronSiklikSablonlari', $data, ['CronSiklikSablonlari_Id' => $sid]);
                    echo json_encode(['success' => true, 'message' => 'Şablon güncellendi.'], JSON_UNESCAPED_UNICODE);
                } else {
                    $data['OlusturanKullanici'] = (int)$user['kullanici_id'];
                    $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $db->insert('CronSiklikSablonlari', $data);
                    echo json_encode(['success' => true, 'message' => 'Şablon eklendi.'], JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'sablon_sil':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $sid = (int)($_POST['sablon_id'] ?? 0);
                $db->execute("DELETE FROM CronSiklikSablonlari WHERE CronSiklikSablonlari_Id = ?", [$sid]);
                echo json_encode(['success' => true, 'message' => 'Şablon silindi.'], JSON_UNESCAPED_UNICODE);
                break;

            case 'gecmis':
                $gorevId   = (int)($_POST['gorev_id'] ?? 0);
                $durum     = $_POST['durum'] ?? '';
                $tetik     = $_POST['tetik'] ?? '';
                $startDate = $_POST['start_date'] ?? '';
                $endDate   = $_POST['end_date'] ?? '';

                $where  = ['1=1'];
                $params = [];

                if ($gorevId > 0) {
                    $where[] = "l.CronCalismaLog_GorevId = ?";
                    $params[] = $gorevId;
                }
                if ($durum !== '') {
                    $where[] = "l.CronCalismaLog_CalismaDurum = ?";
                    $params[] = (int)$durum;
                }
                if ($tetik !== '') {
                    $where[] = "l.CronCalismaLog_TetikleyenTur = ?";
                    $params[] = (int)$tetik;
                }
                if ($startDate) {
                    $where[] = "CONVERT(date, l.CronCalismaLog_BaslangicTarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $where[] = "CONVERT(date, l.CronCalismaLog_BaslangicTarihi) <= ?";
                    $params[] = $endDate;
                }

                $whereClause = implode(' AND ', $where);

                $liste = $db->fetchAll("
                    SELECT TOP 200
                        l.CronCalismaLog_Id,
                        g.CronGorevler_Ad AS gorev_adi,
                        z.CronZamanlamalar_Ad AS zamanlama_adi,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS baslangic,
                        l.CronCalismaLog_SureSaniye,
                        l.CronCalismaLog_CalismaDurum,
                        l.CronCalismaLog_TetikleyenTur,
                        l.CronCalismaLog_Sonuc,
                        ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, '-') AS tetikleyen
                    FROM CronCalismaLog l
                    INNER JOIN CronGorevler g ON g.CronGorevler_Id = l.CronCalismaLog_GorevId
                    LEFT JOIN CronZamanlamalar z ON z.CronZamanlamalar_Id = l.CronCalismaLog_ZamanlamaId
                    LEFT JOIN kullanicilar k ON k.kullanici_id = l.CronCalismaLog_TetikleyenKullanici
                    WHERE $whereClause
                    ORDER BY l.CronCalismaLog_Id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $liste], JSON_UNESCAPED_UNICODE);
                break;

            case 'log_detay': // geçmişteki bir çalışmanın tam çıktısı
                $lid = (int)($_POST['log_id'] ?? 0);
                $l = $db->fetchOne("
                    SELECT l.CronCalismaLog_Id, g.CronGorevler_Ad AS gorev_adi,
                           l.CronCalismaLog_CalismaDurum, l.CronCalismaLog_Sonuc,
                           CAST(l.CronCalismaLog_Parametreler AS NVARCHAR(MAX)) AS parametreler,
                           CAST(l.CronCalismaLog_Cikti AS NVARCHAR(MAX)) AS cikti
                    FROM CronCalismaLog l
                    INNER JOIN CronGorevler g ON g.CronGorevler_Id = l.CronCalismaLog_GorevId
                    WHERE l.CronCalismaLog_Id = ?
                ", [$lid]);

                if (!$l) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı!'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                echo json_encode([
                    'success' => true,
                    'durum'   => $l['CronCalismaLog_CalismaDurum'],
                    'message' => $l['CronCalismaLog_Sonuc'],
                    'baslik'  => $l['gorev_adi'],
                    'params'  => cronMetin($l['parametreler']),
                    'cikti'   => cronMetin($l['cikti']),
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Throwable $e) {
        error_log('cron-yonetimi hata: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .cron-satir { font-family: monospace; font-size: .85rem; }
        .gorev-kod {
            font-family: monospace; font-size: .8rem;
            background: var(--bs-secondary-bg);
            padding: .15rem .5rem; border-radius: .25rem;
        }
        pre.cron-cikti {
            background: #212529; color: #9fef9f;
            padding: 1rem; border-radius: .375rem;
            max-height: 400px; overflow: auto; font-size: .8rem;
            white-space: pre-wrap; word-break: break-word;
        }
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn-group-sm > .btn, .btn-sm { padding: 0.25rem 0.5rem; margin: 0 2px; }
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

                    <!-- Plesk Cron satırı -->
                    <div class="card mb-3">
                        <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
                            <i class="bi bi-gear-fill text-primary fs-5"></i>
                            <strong class="text-nowrap">Plesk Cron (Tek Satır):</strong>
                            <span class="badge text-bg-secondary cron-satir">* * * * *</span>
                            <input type="text" class="form-control form-control-sm cron-satir flex-grow-1" id="pleskSatir"
                                   value="<?= htmlspecialchars($pleskSatir) ?>" readonly style="min-width:280px">
                            <button class="btn btn-outline-secondary btn-sm" onclick="kopyala()" title="Kopyala">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-list-task"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tanımlı Görev</span>
                                    <span class="info-box-number" id="stat-gorev">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-clock-history"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Zamanlayıcı</span>
                                    <span class="info-box-number" id="stat-zamanlama">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Başarılı</span>
                                    <span class="info-box-number" id="stat-basarili">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Hatalı</span>
                                    <span class="info-box-number" id="stat-hatali">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sekmeler -->
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#tabGorevler"><i class="bi bi-list-task"></i> Görevler</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#tabGecmis" id="gecmisTabLink"><i class="bi bi-journal-text"></i> Çalışma Geçmişi</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#tabSablonlar" id="sablonTabLink"><i class="bi bi-collection"></i> Sıklık Şablonları</a>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- Görevler -->
                        <div class="tab-pane fade show active" id="tabGorevler">
                            <div id="gorevListesi">
                                <div class="text-center text-muted py-5"><span class="spinner-border"></span></div>
                            </div>
                        </div>

                        <!-- Çalışma Geçmişi -->
                        <div class="tab-pane fade" id="tabGecmis">
                            <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                                </div>
                                <div class="card-body">
                                    <form id="gecmisFiltre">
                                        <div class="row g-3">
                                            <div class="col-md-2">
                                                <label class="form-label">Başlangıç Tarihi</label>
                                                <input type="date" class="form-control" id="filtre_start_date">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Bitiş Tarihi</label>
                                                <input type="date" class="form-control" id="filtre_end_date">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Görev</label>
                                                <select class="form-select" id="filtre_gorev"><option value="">Tümü</option></select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" id="filtre_durum">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Başarılı</option>
                                                    <option value="2">Hatalı</option>
                                                    <option value="0">Çalışıyor</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Tetik</label>
                                                <select class="form-select" id="filtre_tetik">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Otomatik</option>
                                                    <option value="0">Manuel</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-journal-text"></i> Çalışma Geçmişi</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                            <i class="bi bi-funnel"></i> Filtrele
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="gecmisTable" class="table table-bordered table-striped table-hover" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Görev</th>
                                                <th>Zamanlayıcı</th>
                                                <th>Başlangıç</th>
                                                <th>Süre</th>
                                                <th>Durum</th>
                                                <th>Tetik</th>
                                                <th>Tetikleyen</th>
                                                <th>Sonuç</th>
                                                <th>Çıktı</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Sıklık Şablonları -->
                        <div class="tab-pane fade" id="tabSablonlar">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-collection"></i> Sıklık Şablonları</h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="sablonEkle()">
                                            <i class="bi bi-plus-circle"></i> Yeni Şablon Ekle
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="sablonTable" class="table table-bordered table-striped table-hover" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th style="width:80px">Sıra</th>
                                                <th>Şablon Adı</th>
                                                <th>Cron İfadesi</th>
                                                <th style="width:100px">Durum</th>
                                                <th style="width:120px">İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Zamanlayıcı Modal -->
    <div class="modal fade" id="zamanlamaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="zamanlamaModalBaslik">Zamanlayıcı Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="zamanlamaForm">
                    <div class="modal-body">
                        <input type="hidden" id="z_id" name="zamanlama_id" value="0">
                        <input type="hidden" id="z_gorev_id" name="gorev_id" value="0">

                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Zamanlayıcı Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="z_adi" name="zamanlama_adi" required>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Sıklık Şablonu</label>
                                <select class="form-select" id="z_sablon" style="width:100%">
                                    <option value="">Seçiniz...</option>
                                </select>
                                <div class="form-text">Seçilen şablon cron ifadesine uygulanır; sonrasında elle düzenleyebilirsiniz.</div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                                <input type="text" class="form-control cron-satir" id="z_ifade" name="cron_ifadesi" placeholder="*/5 * * * *" required>
                                <div class="form-text">dk sa gün ay haftagünü — ör: <code>*/15 * * * *</code>, <code>0 3 * * *</code>, <code>0 9 * * 1-5</code></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Başlangıç</label>
                                <input type="datetime-local" class="form-control" id="z_baslangic" name="baslangic">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Bitiş (boş = sürekli)</label>
                                <input type="datetime-local" class="form-control" id="z_bitis" name="bitis">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Sabit Parametreler (JSON)</label>
                                <input type="text" class="form-control cron-satir" id="z_params" name="sabit_parametreler"
                                       placeholder='{"gun":"90"} — {dun}, {bugun} yer tutucuları desteklenir'>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="z_telafi" name="telafi_et" value="1">
                                    <label class="form-check-label" for="z_telafi">Kaçırılan tetiği telafi et</label>
                                </div>
                                <div class="form-text">Günlük/haftalık görevlerde açın. Dakikalık görevlerde gereksizdir.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telafi Sınırı (saat)</label>
                                <input type="number" class="form-control" id="z_telafi_saat" name="telafi_saat" min="1" max="72" value="6">
                                <div class="form-text">Bu süreden daha eski kaçan tetikler telafi edilmez.</div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="z_durum" name="durum" value="1" checked>
                                    <label class="form-check-label" for="z_durum">Aktif</label>
                                </div>
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

    <!-- Şablon Modal -->
    <div class="modal fade" id="sablonModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sablonModalBaslik">Yeni Şablon Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="sablonForm">
                    <div class="modal-body">
                        <input type="hidden" id="s_id" name="sablon_id" value="0">

                        <div class="mb-3">
                            <label class="form-label">Şablon Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="s_adi" name="sablon_adi" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                            <input type="text" class="form-control cron-satir" id="s_ifade" name="sablon_ifadesi" placeholder="0 */3 * * *" required>
                            <div class="form-text">dk sa gün ay haftagünü — ör: <code>*/15 * * * *</code>, <code>0 9 * * 1-5</code></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sıra No</label>
                            <input type="number" class="form-control" id="s_sira" name="sira_no" value="0">
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="s_durum" name="durum" value="1" checked>
                            <label class="form-check-label" for="s_durum">Aktif</label>
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

    <!-- Manuel Çalıştır Modal (parametreli görevler) -->
    <div class="modal fade" id="tetikModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Manuel Çalıştır</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="tetikForm">
                    <div class="modal-body">
                        <input type="hidden" id="t_gorev_kodu" value="">
                        <div id="tetikUyari"></div>
                        <div id="tetikParamAlanlari"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-play-fill"></i> Çalıştır</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Çıktı Modal -->
    <div class="modal fade" id="ciktiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ciktiBaslik">Çalışma Sonucu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="ciktiOzet" class="mb-2"></div>
                    <pre class="cron-cikti" id="ciktiIcerik"></pre>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        const permissions = <?= json_encode($pagePermissions) ?>;
        const canAdd    = permissions.can_add;
        const canEdit   = permissions.can_edit;
        const canDelete = permissions.can_delete;

        // Web sunucusu timeout'u (IIS/FastCGI ~300 sn) PHP'nin set_time_limit'ini
        // ezdiği için bu eşiğin üstündeki görevler panelde uyarı gösterir.
        const WEB_TIMEOUT_ESIK = 300;

        let gecmisTable, sablonTable;
        let gorevler = [];
        let currentFilters = {};

        function escapeHtml(t) { return $('<div>').text(t ?? '').html(); }

        function kopyala() {
            const el = document.getElementById('pleskSatir');
            el.select();
            navigator.clipboard.writeText(el.value).then(() => showToast('Kopyalandı', 'success'));
        }

        function loadStats() {
            $.post('', { action: 'stats' }, function(r) {
                if (r.success) {
                    $('#stat-gorev').text(r.data.gorev);
                    $('#stat-zamanlama').text(r.data.zamanlama);
                    $('#stat-basarili').text(r.data.basarili);
                    $('#stat-hatali').text(r.data.hatali);
                }
            });
        }

        function durumBadge(d, sure) {
            if (d === null || d === undefined) return '<span class="badge text-bg-secondary">Henüz çalışmadı</span>';
            const sureTxt = (sure !== null && sure !== undefined) ? `<small class="text-muted d-block">${sure}s</small>` : '';
            if (d == 1) return '<span class="badge text-bg-success">Başarılı</span>' + sureTxt;
            if (d == 2) return '<span class="badge text-bg-danger">Hatalı</span>' + sureTxt;
            return '<span class="badge text-bg-warning">Çalışıyor</span>';
        }

        // ---- Görev listesi ----
        function loadGorevler() {
            $.post('', { action: 'gorevler' }, function(r) {
                if (!r.success) return;
                gorevler = r.data;

                // Geçmiş filtresindeki görev listesini de doldur
                const fg = $('#filtre_gorev');
                const seciliGorev = fg.val();
                fg.find('option:not(:first)').remove();
                gorevler.forEach(g => fg.append(new Option(g.CronGorevler_Ad, g.CronGorevler_Id)));
                fg.val(seciliGorev || '').trigger('change.select2');

                let html = '';
                gorevler.forEach(g => {
                    const uzunGorev = parseInt(g.CronGorevler_MaxSureSn) > WEB_TIMEOUT_ESIK;

                    let satirlar = '';
                    (g.zamanlamalar || []).forEach(z => {
                        const aktif = z.zamanlama_durum == 1;
                        let islemler = '';

                        if (canEdit) {
                            islemler += `<button class="btn btn-sm ${aktif ? 'btn-outline-warning' : 'btn-outline-success'}" title="${aktif ? 'Duraklat' : 'Aktifleştir'}" onclick="zamanlamaDurum(${z.CronZamanlamalar_Id})"><i class="bi ${aktif ? 'bi-pause' : 'bi-play'}"></i></button>`;
                            islemler += `<button class="btn btn-sm btn-success" title="Şimdi çalıştır" onclick="zamanlamaTetikle(${z.CronZamanlamalar_Id}, '${escapeHtml(z.CronZamanlamalar_Ad)}', ${uzunGorev})"><i class="bi bi-play-fill"></i></button>`;
                            islemler += `<button class="btn btn-sm btn-warning" title="Düzenle" onclick="zamanlamaDuzenle(${z.CronZamanlamalar_Id}, ${g.CronGorevler_Id})"><i class="bi bi-pencil"></i></button>`;
                        }
                        if (canDelete) {
                            islemler += `<button class="btn btn-sm btn-danger" title="Sil" onclick="zamanlamaSil(${z.CronZamanlamalar_Id}, '${escapeHtml(z.CronZamanlamalar_Ad)}')"><i class="bi bi-trash"></i></button>`;
                        }

                        const telafiRozet = z.CronZamanlamalar_TelafiEt == 1
                            ? ` <span class="badge text-bg-info" title="Kaçırılan tetik ${z.CronZamanlamalar_TelafiSaatSiniri} saate kadar telafi edilir">Telafi</span>`
                            : '';

                        satirlar += `
                            <tr class="${aktif ? '' : 'table-secondary'}">
                                <td>${escapeHtml(z.CronZamanlamalar_Ad)}${telafiRozet} ${aktif ? '' : '<span class="badge text-bg-secondary">Pasif</span>'}</td>
                                <td><code>${escapeHtml(z.CronZamanlamalar_CronIfadesi)}</code></td>
                                <td>${escapeHtml(z.baslangic || '-')}<br><small class="text-muted">${z.bitis ? escapeHtml(z.bitis) : 'Sürekli'}</small></td>
                                <td>${escapeHtml(z.son_calisma || '-')}</td>
                                <td>${durumBadge(z.son_log_durum, z.son_log_sure)}</td>
                                <td class="text-nowrap">${islemler}</td>
                            </tr>`;
                    });

                    if (!satirlar) {
                        satirlar = '<tr><td colspan="6" class="text-center text-muted py-3">Zamanlayıcı tanımlı değil</td></tr>';
                    }

                    const sureRozet = uzunGorev
                        ? `<span class="badge text-bg-warning ms-1" title="Web timeout'unu aşabilir, CLI worker kullanın">${g.CronGorevler_MaxSureSn}s</span>`
                        : `<span class="badge text-bg-light text-dark ms-1">${g.CronGorevler_MaxSureSn}s</span>`;

                    html += `
                        <div class="card mb-3">
                            <div class="card-header d-flex align-items-center flex-wrap gap-2">
                                <h3 class="card-title mb-0">
                                    ${escapeHtml(g.CronGorevler_Ad)}
                                    <span class="gorev-kod ms-2">${escapeHtml(g.CronGorevler_GorevKodu)}</span>
                                    ${sureRozet}
                                </h3>
                                <small class="text-muted">${escapeHtml(g.CronGorevler_Aciklama || '')}</small>
                                <div class="ms-auto">
                                    ${canEdit ? `<button class="btn btn-success btn-sm" onclick="gorevTetikle('${escapeHtml(g.CronGorevler_GorevKodu)}')"><i class="bi bi-play-fill"></i> Manuel Çalıştır</button>` : ''}
                                    ${canAdd ? `<button class="btn btn-primary btn-sm" onclick="zamanlamaEkle(${g.CronGorevler_Id})"><i class="bi bi-plus-circle"></i> Zamanlayıcı Ekle</button>` : ''}
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead>
                                        <tr>
                                            <th>Zamanlayıcı Adı</th>
                                            <th>Cron İfadesi</th>
                                            <th>Başlangıç / Bitiş</th>
                                            <th>Son Çalışma</th>
                                            <th>Durum</th>
                                            <th style="width:190px">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>${satirlar}</tbody>
                                </table>
                            </div>
                        </div>`;
                });

                $('#gorevListesi').html(html || '<div class="text-center text-muted py-5">Tanımlı görev yok</div>');
            });
        }

        // ---- Manuel çalıştır ----
        function cliUyarisi(kod) {
            return `<div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Bu görev uzun sürebilir ve web sunucusu zaman aşımına düşebilir.
                        Güvenli yol komut satırından çalıştırmaktır:
                        <pre class="mt-2 mb-0 small">php admin/cron/worker.php gorev=${escapeHtml(kod)}</pre>
                    </div>`;
        }

        function gorevTetikle(kod) {
            const g = gorevler.find(x => x.CronGorevler_GorevKodu === kod);
            const uzunGorev = g && parseInt(g.CronGorevler_MaxSureSn) > WEB_TIMEOUT_ESIK;

            let paramSema = [];
            if (g && g.CronGorevler_Parametreler) {
                try { paramSema = JSON.parse(g.CronGorevler_Parametreler) || []; } catch (e) { paramSema = []; }
            }

            if (paramSema.length > 0 || uzunGorev) {
                let alanlar = '';
                paramSema.forEach(p => {
                    if (p.tip === 'checkbox') {
                        alanlar += `
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input tetik-param" type="checkbox" role="switch"
                                           id="tp_${escapeHtml(p.ad)}" data-ad="${escapeHtml(p.ad)}" value="1">
                                    <label class="form-check-label" for="tp_${escapeHtml(p.ad)}">${escapeHtml(p.etiket || p.ad)}</label>
                                </div>
                            </div>`;
                    } else {
                        alanlar += `
                            <div class="mb-3">
                                <label class="form-label">${escapeHtml(p.etiket || p.ad)} ${p.zorunlu ? '<span class="text-danger">*</span>' : ''}</label>
                                <input type="${p.tip === 'number' ? 'number' : 'text'}"
                                       class="form-control tetik-param" data-ad="${escapeHtml(p.ad)}"
                                       ${p.zorunlu ? 'required' : ''}
                                       ${p.tip === 'date' ? 'placeholder="gg.aa.yyyy veya {dun}"' : ''}>
                            </div>`;
                    }
                });

                $('#tetikUyari').html(uzunGorev ? cliUyarisi(kod) : '');
                $('#tetikParamAlanlari').html(alanlar || '<p class="text-muted mb-0">Bu görev parametre almıyor.</p>');
                $('#t_gorev_kodu').val(kod);
                new bootstrap.Modal('#tetikModal').show();
            } else {
                confirmAction('Görev çalıştırılsın mı?', kod, function() {
                    tetikGonder(kod, {});
                });
            }
        }

        $('#tetikForm').on('submit', function(e) {
            e.preventDefault();
            const params = {};
            $('.tetik-param').each(function() {
                if ($(this).is(':checkbox')) {
                    if ($(this).is(':checked')) params[$(this).data('ad')] = '1';
                } else if ($(this).val() !== '') {
                    params[$(this).data('ad')] = $(this).val();
                }
            });
            bootstrap.Modal.getInstance(document.getElementById('tetikModal')).hide();
            tetikGonder($('#t_gorev_kodu').val(), params);
        });

        function tetikGonder(kod, params) {
            showToast('Görev çalışıyor, lütfen bekleyin...', 'info');
            $.post('', { action: 'tetikle', gorev_kodu: kod, parametreler: JSON.stringify(params) }, function(r) {
                if (r.durum === undefined) {
                    showToast(r.message, 'error');
                } else {
                    ciktiGoster(kod, r);
                }
                loadGorevler();
                loadStats();
            }).fail(() => showToast('Çalıştırma isteği başarısız (zaman aşımı olabilir)', 'error'));
        }

        function zamanlamaTetikle(id, ad, uzunGorev) {
            const metin = uzunGorev
                ? 'Bu görev uzun sürebilir; zaman aşımı riskine karşı CLI worker önerilir.'
                : ad;

            confirmAction('Zamanlayıcı çalıştırılsın mı?', metin, function() {
                showToast('Görev çalışıyor, lütfen bekleyin...', 'info');
                $.post('', { action: 'zamanlama_tetikle', zamanlama_id: id }, function(r) {
                    if (r.durum === undefined) {
                        showToast(r.message, 'error');
                    } else {
                        ciktiGoster(ad, r);
                    }
                    loadGorevler();
                    loadStats();
                }).fail(() => showToast('Çalıştırma isteği başarısız (zaman aşımı olabilir)', 'error'));
            });
        }

        function ciktiGoster(baslik, r) {
            $('#ciktiBaslik').text('Çalışma Sonucu — ' + baslik);
            $('#ciktiOzet').html(
                (r.durum == 1
                    ? '<span class="badge text-bg-success">BAŞARILI</span> '
                    : '<span class="badge text-bg-danger">HATA</span> ')
                + escapeHtml(r.message || '')
                + (r.params ? `<div class="small text-muted mt-1">Parametreler: ${escapeHtml(r.params)}</div>` : '')
            );
            $('#ciktiIcerik').text(r.cikti || '(çıktı yok)');
            new bootstrap.Modal('#ciktiModal').show();
        }

        function logDetay(logId) {
            $.post('', { action: 'log_detay', log_id: logId }, function(r) {
                if (!r.success) { showToast(r.message, 'error'); return; }
                ciktiGoster(r.baslik + ' (#' + logId + ')', r);
            });
        }

        // ---- Sıklık şablonları ----
        function loadSablonlar() {
            $.post('', { action: 'sablonlar' }, function(r) {
                if (!r.success) return;
                const sel = $('#z_sablon');
                const mevcut = sel.val();
                sel.find('option:not(:first)').remove();
                r.data.forEach(s => {
                    sel.append(new Option(
                        s.CronSiklikSablonlari_Ad + ' — ' + s.CronSiklikSablonlari_CronIfadesi,
                        s.CronSiklikSablonlari_CronIfadesi
                    ));
                });
                sel.val(mevcut || '').trigger('change.select2');
            });
        }

        // Cron ifadesine göre şablonu seçili getir (eşleşme yoksa boş)
        function sablonSecimiEsitle(ifade) {
            const secenek = $('#z_sablon option').filter(function() { return $(this).val() === ifade; });
            $('#z_sablon').val(secenek.length ? ifade : '').trigger('change.select2');
        }

        function loadSablonListe() {
            $.post('', { action: 'sablon_liste' }, function(r) {
                if (!r.success) return;
                sablonTable.clear();
                r.data.forEach(s => {
                    let islemler = '';
                    if (canEdit) {
                        islemler += `<button class="btn btn-sm btn-warning" title="Düzenle" onclick="sablonDuzenle(${s.CronSiklikSablonlari_Id})"><i class="bi bi-pencil"></i></button>`;
                    }
                    if (canDelete) {
                        islemler += `<button class="btn btn-sm btn-danger" title="Sil" onclick="sablonSil(${s.CronSiklikSablonlari_Id}, '${escapeHtml(s.CronSiklikSablonlari_Ad)}')"><i class="bi bi-trash"></i></button>`;
                    }
                    sablonTable.row.add([
                        s.CronSiklikSablonlari_SiraNo,
                        escapeHtml(s.CronSiklikSablonlari_Ad),
                        '<code>' + escapeHtml(s.CronSiklikSablonlari_CronIfadesi) + '</code>',
                        s.Durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>',
                        islemler
                    ]);
                });
                sablonTable.draw();
            });
        }

        function sablonEkle() {
            $('#sablonForm')[0].reset();
            $('#s_id').val(0);
            $('#s_durum').prop('checked', true);
            $('#sablonModalBaslik').text('Yeni Şablon Ekle');
            new bootstrap.Modal('#sablonModal').show();
        }

        function sablonDuzenle(id) {
            $.post('', { action: 'sablon_get', sablon_id: id }, function(r) {
                if (!r.success || !r.data) return;
                const s = r.data;
                $('#s_id').val(id);
                $('#s_adi').val(s.CronSiklikSablonlari_Ad);
                $('#s_ifade').val(s.CronSiklikSablonlari_CronIfadesi);
                $('#s_sira').val(s.CronSiklikSablonlari_SiraNo);
                $('#s_durum').prop('checked', s.Durum == 1);
                $('#sablonModalBaslik').text('Şablon Düzenle');
                new bootstrap.Modal('#sablonModal').show();
            });
        }

        function sablonSil(id, ad) {
            confirmAction('Şablon silinsin mi?', ad, function() {
                $.post('', { action: 'sablon_sil', sablon_id: id }, function(r) {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) { loadSablonListe(); loadSablonlar(); }
                });
            });
        }

        $(document).on('submit', '#sablonForm', function(e) {
            e.preventDefault();
            $.post('', $(this).serialize() + '&action=sablon_kaydet', function(r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('sablonModal')).hide();
                    loadSablonListe();
                    loadSablonlar();
                }
            });
        });

        // ---- Zamanlayıcı CRUD ----
        function zamanlamaEkle(gorevId) {
            $('#zamanlamaForm')[0].reset();
            $('#z_id').val(0);
            $('#z_gorev_id').val(gorevId);
            $('#z_durum').prop('checked', true);
            $('#z_telafi').prop('checked', false);
            $('#z_telafi_saat').val(6);
            sablonSecimiEsitle('');
            $('#zamanlamaModalBaslik').text('Zamanlayıcı Ekle');
            new bootstrap.Modal('#zamanlamaModal').show();
        }

        function zamanlamaDuzenle(id, gorevId) {
            $.post('', { action: 'zamanlama_get', zamanlama_id: id }, function(r) {
                if (!r.success || !r.data) return;
                const z = r.data;
                $('#z_id').val(id);
                $('#z_gorev_id').val(gorevId);
                $('#z_adi').val(z.CronZamanlamalar_Ad);
                $('#z_ifade').val(z.CronZamanlamalar_CronIfadesi);
                $('#z_baslangic').val(z.baslangic ? z.baslangic.replace(' ', 'T').substring(0, 16) : '');
                $('#z_bitis').val(z.bitis ? z.bitis.replace(' ', 'T').substring(0, 16) : '');
                $('#z_params').val(z.SabitParametreler || '');
                $('#z_telafi').prop('checked', z.CronZamanlamalar_TelafiEt == 1);
                $('#z_telafi_saat').val(z.CronZamanlamalar_TelafiSaatSiniri || 6);
                $('#z_durum').prop('checked', z.Durum == 1);
                sablonSecimiEsitle(z.CronZamanlamalar_CronIfadesi);
                $('#zamanlamaModalBaslik').text('Zamanlayıcı Düzenle');
                new bootstrap.Modal('#zamanlamaModal').show();
            });
        }

        $('#zamanlamaForm').on('submit', function(e) {
            e.preventDefault();
            $.post('', $(this).serialize() + '&action=zamanlama_kaydet', function(r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('zamanlamaModal')).hide();
                    loadGorevler();
                    loadStats();
                }
            });
        });

        function zamanlamaDurum(id) {
            $.post('', { action: 'zamanlama_durum', zamanlama_id: id }, function(r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) { loadGorevler(); loadStats(); }
            });
        }

        function zamanlamaSil(id, ad) {
            confirmAction('Zamanlayıcı silinsin mi?', ad + ' — bu işlem geri alınamaz!', function() {
                $.post('', { action: 'zamanlama_sil', zamanlama_id: id }, function(r) {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) { loadGorevler(); loadStats(); }
                });
            });
        }

        // ---- Çalışma geçmişi ----
        function loadGecmis() {
            $.post('', {
                action: 'gecmis',
                gorev_id: $('#filtre_gorev').val(),
                durum: $('#filtre_durum').val(),
                tetik: $('#filtre_tetik').val(),
                start_date: $('#filtre_start_date').val(),
                end_date: $('#filtre_end_date').val()
            }, function(r) {
                if (!r.success) return;
                gecmisTable.clear();
                r.data.forEach(l => {
                    gecmisTable.row.add([
                        l.CronCalismaLog_Id,
                        escapeHtml(l.gorev_adi),
                        escapeHtml(l.zamanlama_adi || '-'),
                        escapeHtml(l.baslangic),
                        l.CronCalismaLog_SureSaniye !== null ? l.CronCalismaLog_SureSaniye + 's' : '-',
                        durumBadge(l.CronCalismaLog_CalismaDurum, null),
                        l.CronCalismaLog_TetikleyenTur == 1
                            ? '<span class="badge text-bg-info">Otomatik</span>'
                            : '<span class="badge text-bg-secondary">Manuel</span>',
                        escapeHtml(l.tetikleyen),
                        escapeHtml(l.CronCalismaLog_Sonuc || '-'),
                        `<button class="btn btn-sm btn-outline-primary" title="Çıktıyı gör" onclick="logDetay(${l.CronCalismaLog_Id})"><i class="bi bi-terminal"></i></button>`
                    ]);
                });
                gecmisTable.draw();
            });
        }

        $(document).ready(function() {
            // DataTables — yatay kaydırma sarmalayıcı div ile değil scrollX ile
            gecmisTable = $('#gecmisTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                order: [[0, 'desc']],
                scrollX: true,
                columnDefs: [{ targets: [5, 6, 9], orderable: false }]
            });

            sablonTable = $('#sablonTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                order: [[0, 'asc']],
                scrollX: true,
                columnDefs: [{ targets: [3, 4], orderable: false }]
            });

            // Aramalı dropdown'lar
            $('#filtre_gorev, #filtre_durum, #filtre_tetik').select2({
                theme: 'bootstrap-5',
                placeholder: 'Tümü',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });

            $('#z_sablon').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#zamanlamaModal'),
                placeholder: 'Seçiniz...',
                allowClear: true,
                width: '100%',
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });

            // Şablon seçilince cron ifadesine uygula
            $('#z_sablon').on('change', function() {
                const ifade = $(this).val();
                if (ifade) $('#z_ifade').val(ifade);
            });

            // İfade elle değişirse şablon seçimini eşitle
            $('#z_ifade').on('input', function() {
                sablonSecimiEsitle($(this).val().trim());
            });

            $('#gecmisFiltre').on('submit', function(e) {
                e.preventDefault();
                loadGecmis();
                showToast('Filtre uygulandı', 'info');
            });

            $('#clearFilters').on('click', function() {
                $('#gecmisFiltre')[0].reset();
                $('#filtre_gorev, #filtre_durum, #filtre_tetik').val('').trigger('change.select2');
                loadGecmis();
                showToast('Filtreler temizlendi', 'info');
            });

            // Gizli tab içinde ilklenen scrollX tabloları dar kalıyor;
            // tab görünür olduktan ve layout oturduktan sonra yeniden hesapla
            function tablolariHizala() {
                requestAnimationFrame(function() {
                    $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
                });
            }

            $('a[data-bs-toggle="tab"]').on('shown.bs.tab', tablolariHizala);

            $('#gecmisTabLink').on('shown.bs.tab', function() {
                loadGecmis();
            });

            $('#sablonTabLink').on('shown.bs.tab', function() {
                loadSablonListe();
            });

            // Ajax ile satırlar geldikten sonra da genişlik yeniden hesaplanmalı
            gecmisTable.on('draw', tablolariHizala);
            sablonTable.on('draw', tablolariHizala);

            $(window).on('resize', tablolariHizala);

            loadSablonlar();
            loadStats();
            loadGorevler();

            // 60 sn'de bir yenile
            setInterval(function() { loadStats(); loadGorevler(); }, 60000);
        });
    </script>
</body>
</html>
