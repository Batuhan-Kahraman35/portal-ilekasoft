<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü - GEÇİCİ OLARAK KAPALI (Sayfa database'e eklenmeli)
// $currentPageFile = basename($_SERVER['PHP_SELF']);
// $pageAuth = new PageAuth($db);
// $pageAuth->checkPagePermission($currentPageFile);

// Site bilgilerini çek
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_site_title 
    FROM dbo.tanim_site_ayarlari 
    WHERE site_ayarlari_id = 1
");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? '';

$pageTitle = "Abonelik Takibi";

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $kullaniciId = $_SESSION['kullanici_id'] ?? 1;
    
    try {
        switch ($action) {
            case 'stats':
                // Filtreleri al
                $cariId = $_POST['cari_id'] ?? '';
                $urunHizmetId = $_POST['urun_hizmet_id'] ?? '';
                $odemeSekli = $_POST['odeme_sekli'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($cariId !== '') {
                    $whereConditions[] = "abonelik_cari_id = ?";
                    $params[] = $cariId;
                }
                
                if ($urunHizmetId !== '') {
                    $whereConditions[] = "abonelik_urun_hizmet_id = ?";
                    $params[] = $urunHizmetId;
                }
                
                if ($odemeSekli !== '') {
                    $whereConditions[] = "abonelik_odeme_sekli = ?";
                    $params[] = $odemeSekli;
                }
                
                if ($durum !== '') {
                    $whereConditions[] = "abonelik_durum = ?";
                    $params[] = $durum;
                }
                
                if ($startDate) {
                    $whereConditions[] = "CONVERT(date, abonelik_son_odeme_tarihi) >= ?";
                    $params[] = $startDate;
                }
                
                if ($endDate) {
                    $whereConditions[] = "CONVERT(date, abonelik_son_odeme_tarihi) <= ?";
                    $params[] = $endDate;
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                $stats = $db->fetchOne("
                    SELECT 
                        COUNT(*) as toplam,
                        ISNULL(SUM(CASE WHEN abonelik_durum = 1 THEN abonelik_tl_tutar ELSE 0 END), 0) as toplam_tl_tutar,
                        SUM(CASE WHEN abonelik_durum = 1 
                            AND abonelik_son_odeme_tarihi <= DATEADD(DAY, 7, GETDATE()) 
                            AND abonelik_son_odeme_tarihi >= GETDATE() 
                            THEN 1 ELSE 0 END) as yaklasan,
                        SUM(CASE WHEN abonelik_durum = 1 
                            AND abonelik_son_odeme_tarihi < GETDATE() 
                            THEN 1 ELSE 0 END) as gecikmi
                    FROM Abonelikler
                    WHERE $whereClause
                ", $params);
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtreleri al
                $cariId = $_POST['cari_id'] ?? '';
                $urunHizmetId = $_POST['urun_hizmet_id'] ?? '';
                $odemeSekli = $_POST['odeme_sekli'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($cariId !== '') {
                    $whereConditions[] = "a.abonelik_cari_id = ?";
                    $params[] = $cariId;
                }
                
                if ($urunHizmetId !== '') {
                    $whereConditions[] = "a.abonelik_urun_hizmet_id = ?";
                    $params[] = $urunHizmetId;
                }
                
                if ($odemeSekli !== '') {
                    $whereConditions[] = "a.abonelik_odeme_sekli = ?";
                    $params[] = $odemeSekli;
                }
                
                if ($durum !== '') {
                    $whereConditions[] = "a.abonelik_durum = ?";
                    $params[] = $durum;
                }
                
                if ($startDate) {
                    $whereConditions[] = "CONVERT(date, a.abonelik_son_odeme_tarihi) >= ?";
                    $params[] = $startDate;
                }
                
                if ($endDate) {
                    $whereConditions[] = "CONVERT(date, a.abonelik_son_odeme_tarihi) <= ?";
                    $params[] = $endDate;
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                $list = $db->fetchAll("
                    SELECT 
                        a.abonelik_id,
                        a.abonelik_urun_hizmet_id,
                        a.abonelik_cari_id,
                        a.abonelik_odeme_sekli,
                        a.abonelik_tl_tutar,
                        a.abonelik_dolar_tutar,
                        a.abonelik_periyot,
                        a.abonelik_renk,
                        a.abonelik_sira_no,
                        a.abonelik_aciklama,
                        a.abonelik_bilgi,
                        CONVERT(VARCHAR(19), a.abonelik_son_odeme_tarihi, 120) as abonelik_son_odeme_tarihi,
                        a.abonelik_durum,
                        ISNULL(c.cari_adi, '-') as cari_adi,
                        ISNULL(u.urun_hizmet_adi, '-') as urun_hizmet_adi,
                        DATEDIFF(DAY, GETDATE(), a.abonelik_son_odeme_tarihi) as kalan_gun,
                        c.cari_entegrasyon_kanali_id,
                        c.cari_entegrasyon_kod
                    FROM Abonelikler a
                    LEFT JOIN Cari c ON a.abonelik_cari_id = c.cari_id
                    LEFT JOIN Urun_Hizmet u ON a.abonelik_urun_hizmet_id = u.urun_hizmet_id
                    WHERE $whereClause
                    ORDER BY a.abonelik_sira_no ASC, a.abonelik_son_odeme_tarihi ASC
                ", $params);
                
                echo json_encode(['success' => true, 'data' => $list]);
                break;
                
            case 'get':
                $id = $_POST['id'] ?? 0;
                $abonelik = $db->fetchOne("
                    SELECT 
                        abonelik_id,
                        abonelik_urun_hizmet_id,
                        abonelik_cari_id,
                        abonelik_odeme_sekli,
                        abonelik_tl_tutar,
                        abonelik_dolar_tutar,
                        abonelik_periyot,
                        abonelik_renk,
                        abonelik_sira_no,
                        abonelik_aciklama,
                        abonelik_bilgi,
                        CONVERT(VARCHAR(10), abonelik_son_odeme_tarihi, 23) as abonelik_son_odeme_tarihi,
                        abonelik_durum
                    FROM Abonelikler 
                    WHERE abonelik_id = ?
                ", [$id]);
                
                if ($abonelik) {
                    echo json_encode(['success' => true, 'data' => $abonelik]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı!']);
                }
                break;
                
            case 'save':
                $id = $_POST['id'] ?? 0;
                $urunHizmetId = !empty($_POST['urun_hizmet_id']) ? $_POST['urun_hizmet_id'] : null;
                $cariId = !empty($_POST['cari_id']) ? $_POST['cari_id'] : null;
                $odemeSekli = $_POST['odeme_sekli'] ?? 0;
                $tlTutar = !empty($_POST['tl_tutar']) ? $_POST['tl_tutar'] : null;
                $dolarTutar = !empty($_POST['dolar_tutar']) ? $_POST['dolar_tutar'] : null;
                $periyot = $_POST['periyot'] ?? 30;
                $renk = $_POST['renk'] ?? '#0d6efd';
                $siraNo = !empty($_POST['sira_no']) ? $_POST['sira_no'] : null;
                $aciklama = $_POST['aciklama'] ?? '';
                $abonelikBilgi = trim($_POST['bilgi'] ?? '');
                $abonelikBilgi = $abonelikBilgi !== '' ? $abonelikBilgi : null;
                $sonOdemeTarihi = !empty($_POST['son_odeme_tarihi']) ? $_POST['son_odeme_tarihi'] : null;
                // Form her durumda durum değeri gönderir (gizli input + switch).
                // İşaretsiz checkbox POST'a girmediği için isset() kontrolü pasif yapmayı engelliyordu.
                $durum = (isset($_POST['durum']) && ($_POST['durum'] === 'on' || $_POST['durum'] === '1')) ? 1 : 0;
                
                if ($id > 0) {
                    // Güncelleme
                    $result = $db->execute("
                        UPDATE Abonelikler SET
                            abonelik_urun_hizmet_id = ?,
                            abonelik_cari_id = ?,
                            abonelik_odeme_sekli = ?,
                            abonelik_tl_tutar = ?,
                            abonelik_dolar_tutar = ?,
                            abonelik_periyot = ?,
                            abonelik_renk = ?,
                            abonelik_sira_no = ?,
                            abonelik_aciklama = ?,
                            abonelik_bilgi = ?,
                            abonelik_son_odeme_tarihi = ?,
                            abonelik_durum = ?,
                            abonelik_guncelleme_tarihi = GETDATE(),
                            abonelik_guncelleyen_kullanici_id = ?
                        WHERE abonelik_id = ?
                    ", [
                        $urunHizmetId, $cariId, $odemeSekli, $tlTutar, $dolarTutar,
                        $periyot, $renk, $siraNo, $aciklama, $abonelikBilgi, $sonOdemeTarihi,
                        $durum, $kullaniciId, $id
                    ]);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Abonelik güncellendi!']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Güncelleme başarısız!']);
                    }
                } else {
                    // Yeni kayıt
                    try {
                        $result = $db->execute("
                            INSERT INTO Abonelikler (
                                abonelik_urun_hizmet_id, abonelik_cari_id, abonelik_odeme_sekli,
                                abonelik_tl_tutar, abonelik_dolar_tutar, abonelik_periyot,
                                abonelik_renk, abonelik_sira_no, abonelik_aciklama, abonelik_bilgi,
                                abonelik_son_odeme_tarihi, abonelik_durum,
                                abonelik_olusturan_kullanici_id
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ", [
                            $urunHizmetId, $cariId, $odemeSekli, $tlTutar, $dolarTutar,
                            $periyot, $renk, $siraNo, $aciklama, $abonelikBilgi, $sonOdemeTarihi,
                            $durum, $kullaniciId
                        ]);
                        
                        if ($result) {
                            echo json_encode(['success' => true, 'message' => 'Abonelik eklendi!']);
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Kayıt başarısız!']);
                        }
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
                    }
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                $result = $db->execute("
                    DELETE FROM Abonelikler WHERE abonelik_id = ?
                ", [$id]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Abonelik silindi!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Silme başarısız!']);
                }
                break;
                
            case 'odemeYap':
                $id = $_POST['id'] ?? 0;
                
                // Mevcut abonelik bilgisini al
                $abonelik = $db->fetchOne("
                    SELECT abonelik_son_odeme_tarihi, abonelik_periyot 
                    FROM Abonelikler 
                    WHERE abonelik_id = ?
                ", [$id]);
                
                if ($abonelik) {
                    // Yeni ödeme tarihi = son ödeme tarihi + periyot
                    $result = $db->execute("
                        UPDATE Abonelikler 
                        SET abonelik_son_odeme_tarihi = DATEADD(DAY, abonelik_periyot, abonelik_son_odeme_tarihi),
                            abonelik_guncelleme_tarihi = GETDATE(),
                            abonelik_guncelleyen_kullanici_id = ?
                        WHERE abonelik_id = ?
                    ", [$kullaniciId, $id]);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Ödeme kaydedildi, yeni tarih belirlendi!']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Ödeme kaydedilemedi!']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Abonelik bulunamadı!']);
                }
                break;
                
            case 'updateDolarKuru':
                $kur = $_POST['kur'] ?? 0;
                $cariId = $_POST['cari_id'] ?? '';
                
                if (empty($kur) || $kur <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir kur değeri giriniz!']);
                    exit;
                }
                
                // Cari filtresi varsa WHERE'e ekle
                $whereCondition = "abonelik_odeme_sekli = 1 AND abonelik_durum = 1";
                $updateParams = [$kur, $kullaniciId];
                
                if (!empty($cariId)) {
                    $whereCondition .= " AND abonelik_cari_id = ?";
                    $updateParams[] = $cariId;
                }
                
                // USD aboneliklerin sayısını kontrol et
                if (!empty($cariId)) {
                    $count = $db->fetchOne("SELECT COUNT(*) as total FROM Abonelikler WHERE $whereCondition", [$cariId]);
                } else {
                    $count = $db->fetchOne("SELECT COUNT(*) as total FROM Abonelikler WHERE $whereCondition");
                }
                
                // TL tutarları güncelle
                $updateSql = "UPDATE Abonelikler 
                    SET abonelik_tl_tutar = abonelik_dolar_tutar * ?,
                        abonelik_guncelleme_tarihi = GETDATE(),
                        abonelik_guncelleyen_kullanici_id = ?
                    WHERE $whereCondition";
                
                $result = $db->execute($updateSql, $updateParams);
                
                if ($result !== false) {
                    $cariText = !empty($cariId) ? ' (Seçili Cari)' : ' (Tüm Cariler)';
                    $message = $count['total'] . ' adet USD aboneliğin TL tutarı güncellendi!' . $cariText . ' (Kur: ' . number_format($kur, 2, ',', '.') . ' ₺)';
                    echo json_encode(['success' => true, 'message' => $message]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kur güncellenirken hata oluştu!']);
                }
                break;
                
            case 'getCariList':
                $cariler = $db->fetchAll("
                    SELECT cari_id, cari_adi, cari_entegrasyon_kanali_id, cari_entegrasyon_kod
                    FROM Cari
                    WHERE cari_aktif = 1
                    ORDER BY cari_adi
                ");
                echo json_encode(['success' => true, 'data' => $cariler]);
                break;

            case 'sorgula':
                // Tek abonelik: bağlı olduğu entegrasyon kanalına göre ilgili senkron çalışır.
                $id = (int) ($_POST['id'] ?? 0);

                $kayit = $db->fetchOne("
                    SELECT a.abonelik_id, a.abonelik_bilgi,
                           c.cari_adi, c.cari_entegrasyon_kod,
                           k.EntegrasyonKanallari_id, e.Entegrasyonlar_Ad
                    FROM Abonelikler a
                    INNER JOIN Cari c ON a.abonelik_cari_id = c.cari_id
                    INNER JOIN EntegrasyonKanallari k ON c.cari_entegrasyon_kanali_id = k.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                    WHERE a.abonelik_id = ?
                ", [$id]);

                if (!$kayit) {
                    echo json_encode(['success' => false, 'message' => 'Abonelik bir entegrasyon kanalına bağlı değil.']);
                    break;
                }

                if (empty($kayit['abonelik_bilgi'])) {
                    echo json_encode(['success' => false, 'message' => 'Abonelik Bilgi alanı boş; sorgulama yapılamaz.']);
                    break;
                }

                $entegrasyonAdi = (string) $kayit['Entegrasyonlar_Ad'];

                if (stripos($entegrasyonAdi, 'Faturam') !== false) {
                    require_once __DIR__ . '/../includes/FaturamClient.php';

                    if (empty($kayit['cari_entegrasyon_kod'])) {
                        echo json_encode(['success' => false, 'message' => $kayit['cari_adi'] . ' carisinde kurum kodu tanımlı değil.']);
                        break;
                    }

                    $client = new FaturamClient((int) $kayit['EntegrasyonKanallari_id']);
                    $sonuc  = $client->sorgula(
                        (string) $kayit['cari_entegrasyon_kod'],
                        (string) $kayit['abonelik_bilgi'],
                        $id,
                        $kullaniciId
                    );

                    if (!$sonuc['basarili']) {
                        echo json_encode(['success' => false, 'message' => $sonuc['mesaj']]);
                        break;
                    }

                    if ($sonuc['fatura_sayisi'] === 0) {
                        echo json_encode(['success' => true, 'message' => 'Ödenmemiş fatura bulunamadı, kayıt değiştirilmedi.']);
                        break;
                    }

                    $db->execute("
                        UPDATE Abonelikler
                        SET abonelik_tl_tutar = ?,
                            abonelik_son_odeme_tarihi = ?,
                            abonelik_guncelleme_tarihi = GETDATE(),
                            abonelik_guncelleyen_kullanici_id = ?
                        WHERE abonelik_id = ?
                    ", [$sonuc['tutar'], $sonuc['son_odeme'], $kullaniciId, $id]);

                    echo json_encode([
                        'success' => true,
                        'message' => number_format((float) $sonuc['tutar'], 2, ',', '.') . ' ₺ / son ödeme '
                            . ($sonuc['son_odeme'] ?? '-') . ' olarak güncellendi.'
                    ]);
                    break;
                }

                if (stripos($entegrasyonAdi, 'Cloudflare') !== false) {
                    // Cloudflare API'si tek alan adı sorgusu sunmuyor; hesabın tamamı senkronlanır.
                    if (!defined('CRON_DISPATCH')) define('CRON_DISPATCH', true);

                    $_GET = [];
                    $stats = [];

                    ob_start();
                    require __DIR__ . '/../cron/cloudflare-domains.php';
                    ob_end_clean();

                    echo json_encode([
                        'success' => true,
                        'message' => 'Cloudflare senkronu çalıştı. Güncellenen: ' . ($stats['basarili'] ?? 0)
                            . ', yeni: ' . ($stats['yeni_eklenen'] ?? 0)
                            . ', değişiklik yok: ' . ($stats['degisiklik_yok'] ?? 0)
                            . ', hata: ' . ($stats['basarisiz'] ?? 0) . '.'
                    ]);
                    break;
                }

                echo json_encode([
                    'success' => false,
                    'message' => $entegrasyonAdi . ' entegrasyonu için sorgulama tanımlı değil.'
                ]);
                break;

            case 'faturamSenkron':
                require_once __DIR__ . '/../includes/FaturamClient.php';

                $id = (int) ($_POST['id'] ?? 0);

                $client  = new FaturamClient();
                $kanalId = $client->kanalId();

                $sql = "
                    SELECT a.abonelik_id, a.abonelik_bilgi, c.cari_adi, c.cari_entegrasyon_kod
                    FROM Abonelikler a
                    INNER JOIN Cari c ON a.abonelik_cari_id = c.cari_id
                    WHERE c.cari_entegrasyon_kanali_id = ?
                      AND c.cari_entegrasyon_kod IS NOT NULL
                      AND c.cari_entegrasyon_kod <> ''
                      AND a.abonelik_bilgi IS NOT NULL
                      AND a.abonelik_bilgi <> ''
                      AND a.abonelik_durum = 1
                ";
                $sqlParams = [$kanalId];

                if ($id > 0) {
                    $sql .= " AND a.abonelik_id = ?";
                    $sqlParams[] = $id;
                }

                $hedefler = $db->fetchAll($sql, $sqlParams);

                if (!$hedefler) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Senkronize edilecek abonelik bulunamadı. Carinin Faturam.net kurumuna bağlı ve abonelik bilgisinin (abone no) dolu olması gerekir.'
                    ]);
                    break;
                }

                $guncellenen = 0;
                $hatali = 0;
                $hatalar = [];

                foreach ($hedefler as $hedef) {
                    $abonelikId = (int) $hedef['abonelik_id'];

                    $sonuc = $client->sorgula(
                        (string) $hedef['cari_entegrasyon_kod'],
                        (string) $hedef['abonelik_bilgi'],
                        $abonelikId,
                        $kullaniciId
                    );

                    if (!$sonuc['basarili']) {
                        $hatali++;
                        $hatalar[] = $hedef['cari_adi'] . ' / ' . $hedef['abonelik_bilgi'] . ': ' . $sonuc['mesaj'];
                        continue;
                    }

                    if ($sonuc['fatura_sayisi'] === 0) {
                        continue;
                    }

                    $db->execute("
                        UPDATE Abonelikler
                        SET abonelik_tl_tutar = ?,
                            abonelik_son_odeme_tarihi = ?,
                            abonelik_guncelleme_tarihi = GETDATE(),
                            abonelik_guncelleyen_kullanici_id = ?
                        WHERE abonelik_id = ?
                    ", [$sonuc['tutar'], $sonuc['son_odeme'], $kullaniciId, $abonelikId]);

                    $guncellenen++;
                }

                $mesaj = count($hedefler) . ' abonelik sorgulandı, ' . $guncellenen . ' tanesi güncellendi.';
                if ($hatali > 0) {
                    $mesaj .= ' ' . $hatali . ' sorguda hata oluştu: ' . implode(' | ', array_slice($hatalar, 0, 3));
                }

                echo json_encode(['success' => true, 'message' => $mesaj]);
                break;
                
            case 'getUrunHizmetList':
                $urunler = $db->fetchAll("
                    SELECT urun_hizmet_id, urun_hizmet_adi 
                    FROM Urun_Hizmet 
                    WHERE urun_hizmet_durum = 1 
                    ORDER BY urun_hizmet_adi
                ");
                echo json_encode(['success' => true, 'data' => $urunler]);
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
    <title><?= $pageTitle ?> - <?= $siteTitle ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <!-- SweetAlert2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <style>
        .app-main {
            transition: margin-left 0.3s ease-in-out;
        }
        .badge-renk {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php require_once __DIR__ . '/../includes/header.php'; ?>
        <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <!-- Header -->
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><i class="bi bi-credit-card-2-front"></i> <?= $pageTitle ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="dashboard.php">Anasayfa</a></li>
                                <li class="breadcrumb-item active"><?= $pageTitle ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="small-box text-bg-primary">
                                <div class="inner">
                                    <h3 id="stat_toplam">0</h3>
                                    <p>Toplam Abonelik</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-layers"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3 id="stat_toplam_tl_tutar">0 ₺</h3>
                                    <p>Toplam TL Tutar</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-currency-dollar"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small-box text-bg-warning">
                                <div class="inner">
                                    <h3 id="stat_yaklasan">0</h3>
                                    <p>Yaklaşan Ödemeler (7 Gün)</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-clock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small-box text-bg-danger">
                                <div class="inner">
                                    <h3 id="stat_gecikmi">0</h3>
                                    <p>Gecikmiş Ödemeler</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Cari</label>
                                        <select class="form-select" name="cari_id" id="filter_cari_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Ürün/Hizmet</label>
                                        <select class="form-select" name="urun_hizmet_id" id="filter_urun_hizmet_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ödeme Şekli</label>
                                        <select class="form-select" name="odeme_sekli" id="filter_odeme_sekli">
                                            <option value="">Tümü</option>
                                            <option value="0">TL</option>
                                            <option value="1">Dolar</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" name="start_date" id="filter_start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" name="end_date" id="filter_end_date">
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

                    <!-- Ana İçerik -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Abonelik Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-info btn-sm me-2" onclick="syncCloudflare()">
                                    <i class="bi bi-cloud-sync"></i> Cloudflare Senkronizasyon
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm me-2" onclick="faturamSenkron()">
                                    <i class="bi bi-receipt"></i> Fatura Senkronizasyon
                                </button>
                                <button type="button" class="btn btn-warning btn-sm me-2" onclick="openKurModal()">
                                    <i class="bi bi-currency-exchange"></i> Dolar Kuru Güncelle
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Abonelik Ekle
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="abonelikTable" class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>Cari</th>
                                            <th>Ürün/Hizmet</th>
                                            <th>Abonelik Bilgi</th>
                                            <th>Açıklama</th>
                                            <th>TL Tutar</th>
                                            <th>Dolar Tutar</th>
                                            <th>Periyot</th>
                                            <th>Son Ödeme</th>
                                            <th>Kalan Gün</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php require_once __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Dolar Kuru Modal -->
    <div class="modal fade" id="kurModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="bi bi-currency-exchange"></i> Dolar Kuru Güncelle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Dikkat:</strong> Seçili cariye ait USD aboneliklerin TL tutarları güncellenecektir.
                    </div>
                    <form id="kurForm">
                        <div class="mb-3">
                            <label class="form-label">Cari</label>
                            <select class="form-select" id="kur_cari_id" name="cari_id">
                                <option value="">Tüm Cariler</option>
                            </select>
                            <small class="form-text text-muted">Boş bırakırsanız tüm cariler güncellenir</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Dolar Kuru (₺) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" id="dolar_kuru" name="kur" step="0.01" min="0" placeholder="Örn: 43.10" required>
                            <small class="form-text text-muted">Güncel dolar kurunu giriniz</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> İptal
                    </button>
                    <button type="button" class="btn btn-warning" onclick="updateKur()">
                        <i class="bi bi-arrow-repeat"></i> Güncelle
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="abonelikModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Abonelik Bilgileri</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="abonelikForm">
                        <input type="hidden" name="id" id="abonelik_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cari <span class="text-danger">*</span></label>
                                <select class="form-select" name="cari_id" id="abonelik_cari_id">
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ürün/Hizmet</label>
                                <select class="form-select" name="urun_hizmet_id" id="abonelik_urun_hizmet_id">
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Ödeme Şekli <span class="text-danger">*</span></label>
                                <div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="odeme_sekli" id="odeme_tl" value="0" checked>
                                        <label class="form-check-label" for="odeme_tl">TL (₺)</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="odeme_sekli" id="odeme_usd" value="1">
                                        <label class="form-check-label" for="odeme_usd">Dolar ($)</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">TL Tutar</label>
                                <input type="number" class="form-control" name="tl_tutar" id="abonelik_tl_tutar" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Dolar Tutar</label>
                                <input type="number" class="form-control" name="dolar_tutar" id="abonelik_dolar_tutar" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Periyot (Gün) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="periyot" id="abonelik_periyot" value="30" required>
                                <small class="text-muted">Aylık: 30, Yıllık: 365</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Renk</label>
                                <input type="color" class="form-control form-control-color" name="renk" id="abonelik_renk" value="#0d6efd">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Sıra No</label>
                                <input type="number" class="form-control" name="sira_no" id="abonelik_sira_no" placeholder="1, 2, 3...">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Son Ödeme Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="son_odeme_tarihi" id="abonelik_son_odeme_tarihi" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Durum</label>
                                <div class="form-check form-switch mt-2">
                                    <!-- Isaretsiz checkbox POST'a girmez; gizli input pasif degerini tasir. -->
                                    <input type="hidden" name="durum" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="durum" id="abonelik_durum" value="1" checked>
                                    <label class="form-check-label" for="abonelik_durum">Aktif</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Abonelik Bilgi</label>
                            <input type="text" class="form-control" name="bilgi" id="abonelik_bilgi" maxlength="150"
                                   placeholder="Abone / tesisat no veya alan adı">
                            <small class="text-muted">Faturam.net kurumlarında fatura sorgulaması bu numarayla yapılır.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" id="abonelik_aciklama" rows="3" placeholder="Abonelik hakkında notlar..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" onclick="saveAbonelik()">
                        <i class="bi bi-save"></i> Kaydet
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cloudflare Sync Modal -->
    <div class="modal fade" id="cfSyncModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-cloud-sync"></i> Cloudflare Senkronizasyonu
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="cfSyncLoading" class="text-center py-4">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Yükleniyor...</span>
                        </div>
                        <p class="mt-2 text-muted">Cloudflare API'ye bağlanılıyor, alan adları kontrol ediliyor...<br>Lütfen bekleyin.</p>
                    </div>
                    <div id="cfSyncResult" class="d-none">
                        <div class="alert alert-success" id="cfSyncSummary"></div>
                        <h6>İşlem Logları:</h6>
                        <div class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;" id="cfSyncLogs">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        let table;
        let modal;
        let currentFilters = {};

        $(document).ready(function() {
            // Modal init
            modal = new bootstrap.Modal(document.getElementById('abonelikModal'));
            
            // Select2 init (modal içindekiler hariç, onlar dropdownParent ile ayrı kuruluyor)
            $('.form-select').not('#abonelik_cari_id, #abonelik_urun_hizmet_id, #kur_cari_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Modal içindeki Select2
            $('#abonelik_cari_id, #abonelik_urun_hizmet_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $('#abonelikModal'),
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });

            // Dolar Kuru modalındaki cari Select2
            $('#kur_cari_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $('#kurModal'),
                placeholder: 'Tüm Cariler',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // DataTable init
            table = $('#abonelikTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                },
                processing: true,
                scrollX: true,
                autoWidth: false,
                order: [[8, 'asc']], // Kalan Gün sütununa göre sırala (index 8)
                columnDefs: [
                    { targets: [10], orderable: false }, // İşlemler kolonu
                    {
                        targets: [8], // Kalan Gün kolonu
                        type: 'num',
                        render: function(data, type, row, meta) {
                            // Sıralama için sayısal değer, gösterim için HTML döndür
                            if (type === 'sort' || type === 'type') {
                                // data-order attribute'undan sayısal değeri al
                                const match = data.match(/data-order="(-?\d+)"/);
                                return match ? parseInt(match[1]) : 0;
                            }
                            return data;
                        }
                    }
                ]
            });
            
            // Sidebar toggle ile tablo genişlet
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        setTimeout(() => {
                            $(window).trigger('resize');
                            table.columns.adjust().draw();
                        }, 350);
                    }
                });
            });
            
            observer.observe(document.body, { attributes: true });
            
            $('[data-lte-toggle="sidebar"]').on('click', function() {
                setTimeout(() => {
                    $(window).trigger('resize');
                    table.columns.adjust().draw();
                }, 350);
            });
            
            // Stats ve liste yükle
            loadStats();
            loadList();
            loadCariList();
            loadUrunHizmetList();
            
            // Filtre form
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentFilters = {
                    cari_id: $('#filter_cari_id').val(),
                    urun_hizmet_id: $('#filter_urun_hizmet_id').val(),
                    odeme_sekli: $('#filter_odeme_sekli').val(),
                    durum: $('#filter_durum').val(),
                    start_date: $('#filter_start_date').val(),
                    end_date: $('#filter_end_date').val()
                };
                
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) delete currentFilters[key];
                });
                
                loadStats();
                loadList();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_cari_id').val('').trigger('change.select2');
                $('#filter_urun_hizmet_id').val('').trigger('change.select2');
                $('#filter_odeme_sekli').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                $('#filter_start_date').val('');
                $('#filter_end_date').val('');
                currentFilters = {};
                loadStats();
                loadList();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        function loadStats() {
            $.post('', { 
                action: 'stats',
                ...currentFilters
            }, function(response) {
                if (response.success) {
                    $('#stat_toplam').text(response.data.toplam || 0);
                    
                    // Toplam TL Tutar formatla
                    const toplamTL = parseFloat(response.data.toplam_tl_tutar || 0);
                    const formattedTL = new Intl.NumberFormat('tr-TR', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }).format(toplamTL);
                    $('#stat_toplam_tl_tutar').text(formattedTL + ' ₺');
                    
                    $('#stat_yaklasan').text(response.data.yaklasan || 0);
                    $('#stat_gecikmi').text(response.data.gecikmi || 0);
                }
            });
        }

        function loadList() {
            $.post('', { 
                action: 'list',
                ...currentFilters
            }, function(response) {
                if (response.success) {
                    renderTable(response.data);
                } else {
                    showToast(response.message, 'error');
                }
            });
        }

        function renderTable(data) {
            table.clear();
            
            data.forEach(item => {
                // Açıklama: tabloda tek satır 20 karakter, tamamı tooltip'te
                const aciklamaTam = (item.abonelik_aciklama || '').trim();
                let aciklama = '-';
                if (aciklamaTam !== '') {
                    const tekSatir = aciklamaTam.replace(/\s+/g, ' ');
                    const kisa = tekSatir.length > 20 ? tekSatir.substring(0, 20) + '…' : tekSatir;
                    const tooltipIcerik = escapeHtml(aciklamaTam).replace(/\n/g, '<br>');
                    aciklama = `<span class="text-nowrap d-inline-block text-truncate" style="max-width: 160px;"
                                      data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true"
                                      title="${tooltipIcerik}">${escapeHtml(kisa)}</span>`;
                }

                // TL Tutar
                let tlTutar = '-';
                if (item.abonelik_tl_tutar && item.abonelik_tl_tutar > 0) {
                    tlTutar = new Intl.NumberFormat('tr-TR', {style: 'currency', currency: 'TRY'}).format(item.abonelik_tl_tutar);
                }
                
                // Dolar Tutar
                let dolarTutar = '-';
                if (item.abonelik_dolar_tutar && item.abonelik_dolar_tutar > 0) {
                    dolarTutar = '$' + parseFloat(item.abonelik_dolar_tutar).toFixed(2);
                }
                
                const periyot = item.abonelik_periyot + ' gün';
                
                const sonOdeme = item.abonelik_son_odeme_tarihi 
                    ? new Date(item.abonelik_son_odeme_tarihi).toLocaleDateString('tr-TR')
                    : '-';
                
                let kalanGunBadge = '';
                const kalanGunValue = parseInt(item.kalan_gun) || 0;
                if (kalanGunValue < 0) {
                    kalanGunBadge = `<span class="badge bg-danger" data-order="${kalanGunValue}">${Math.abs(kalanGunValue)} gün geçti</span>`;
                } else if (kalanGunValue <= 7) {
                    kalanGunBadge = `<span class="badge bg-warning" data-order="${kalanGunValue}">${kalanGunValue} gün</span>`;
                } else {
                    kalanGunBadge = `<span class="badge bg-success" data-order="${kalanGunValue}">${kalanGunValue} gün</span>`;
                }
                
                const durumBadge = item.abonelik_durum == 1
                    ? '<span class="badge bg-success">Aktif</span>'
                    : '<span class="badge bg-secondary">Pasif</span>';

                const bilgi = item.abonelik_bilgi || '-';

                // Bir entegrasyon kanalına bağlı ve abonelik bilgisi girilmiş satırlar sorgulanabilir
                const sorgulanabilir = !!item.cari_entegrasyon_kanali_id && !!item.abonelik_bilgi;

                const senkronButton = sorgulanabilir ? `
                    <button class="btn btn-info btn-sm me-1" onclick="sorgula(${item.abonelik_id})" title="Sorgula">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                ` : '';

                const buttons = `
                    ${senkronButton}
                    <button class="btn btn-success btn-sm me-1" onclick="odemeYap(${item.abonelik_id})" title="Ödeme Yapıldı">
                        <i class="bi bi-cash-coin"></i>
                    </button>
                    <button class="btn btn-warning btn-sm me-1" onclick="editAbonelik(${item.abonelik_id})" title="Düzenle">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="deleteAbonelik(${item.abonelik_id})" title="Sil">
                        <i class="bi bi-trash"></i>
                    </button>
                `;

                table.row.add([
                    item.cari_adi,
                    item.urun_hizmet_adi,
                    bilgi,
                    aciklama,
                    tlTutar,
                    dolarTutar,
                    periyot,
                    sonOdeme,
                    kalanGunBadge,
                    durumBadge,
                    buttons
                ]);
            });

            table.draw();

            // Yeni eklenen satırlardaki tooltip'ler otomatik başlamaz
            document.querySelectorAll('#abonelikTable [data-bs-toggle="tooltip"]').forEach(function (el) {
                bootstrap.Tooltip.getInstance(el) || new bootstrap.Tooltip(el);
            });
        }

        /** Tablo hücrelerine basılan metinleri güvenli hale getirir. */
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text ?? '';
            return div.innerHTML;
        }

        function loadCariList() {
            $.post('', { action: 'getCariList' }, function(response) {
                if (response.success) {
                    const filterSelect = $('#filter_cari_id');
                    const modalSelect = $('#abonelik_cari_id');
                    
                    filterSelect.find('option:not(:first)').remove();
                    modalSelect.find('option:not(:first)').remove();
                    
                    response.data.forEach(item => {
                        filterSelect.append(`<option value="${item.cari_id}">${item.cari_adi}</option>`);
                        modalSelect.append(`<option value="${item.cari_id}">${item.cari_adi}</option>`);
                    });
                }
            });
        }

        function loadUrunHizmetList() {
            $.post('', { action: 'getUrunHizmetList' }, function(response) {
                if (response.success) {
                    const filterSelect = $('#filter_urun_hizmet_id');
                    const modalSelect = $('#abonelik_urun_hizmet_id');
                    
                    filterSelect.find('option:not(:first)').remove();
                    modalSelect.find('option:not(:first)').remove();
                    
                    response.data.forEach(item => {
                        filterSelect.append(`<option value="${item.urun_hizmet_id}">${item.urun_hizmet_adi}</option>`);
                        modalSelect.append(`<option value="${item.urun_hizmet_id}">${item.urun_hizmet_adi}</option>`);
                    });
                }
            });
        }

        function openModal() {
            $('#abonelikForm')[0].reset();
            $('#abonelik_id').val('');
            $('#abonelik_durum').prop('checked', true);
            $('#abonelik_renk').val('#0d6efd');
            $('#abonelik_periyot').val('30');
            $('#abonelik_cari_id').val('').trigger('change');
            $('#abonelik_urun_hizmet_id').val('').trigger('change');
            modal.show();
        }

        function editAbonelik(id) {
            $.post('', { action: 'get', id: id }, function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#abonelik_id').val(data.abonelik_id);
                    $('#abonelik_cari_id').val(data.abonelik_cari_id).trigger('change');
                    $('#abonelik_urun_hizmet_id').val(data.abonelik_urun_hizmet_id).trigger('change');
                    $(`input[name="odeme_sekli"][value="${data.abonelik_odeme_sekli}"]`).prop('checked', true);
                    $('#abonelik_tl_tutar').val(data.abonelik_tl_tutar);
                    $('#abonelik_dolar_tutar').val(data.abonelik_dolar_tutar);
                    $('#abonelik_periyot').val(data.abonelik_periyot);
                    $('#abonelik_renk').val(data.abonelik_renk);
                    $('#abonelik_sira_no').val(data.abonelik_sira_no);
                    $('#abonelik_aciklama').val(data.abonelik_aciklama);
                    $('#abonelik_bilgi').val(data.abonelik_bilgi || '');

                    // Tarih formatı zaten YYYY-MM-DD (CONVERT ile)
                    $('#abonelik_son_odeme_tarihi').val(data.abonelik_son_odeme_tarihi || '');
                    
                    $('#abonelik_durum').prop('checked', data.abonelik_durum == 1);
                    modal.show();
                } else {
                    showToast(response.message, 'error');
                }
            });
        }

        function saveAbonelik() {
            const formData = $('#abonelikForm').serialize() + '&action=save';
            
            $.post('', formData, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    modal.hide();
                    loadStats();
                    loadList();
                } else {
                    showToast(response.message, 'error');
                }
            });
        }

        function deleteAbonelik(id) {
            confirmAction(
                'Bu aboneliği silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, function(response) {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            loadStats();
                            loadList();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            );
        }

        function odemeYap(id) {
            confirmAction(
                'Ödeme yapıldı olarak işaretlemek istiyor musunuz?',
                'Son ödeme tarihi otomatik olarak periyot kadar ileri alınacak.',
                function() {
                    $.post('', { action: 'odemeYap', id: id }, function(response) {
                        if (response.success) {
                            showSuccess('Başarılı!', response.message);
                            loadStats();
                            loadList();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            );
        }

        // Dolar kuru modalını aç
        window.openKurModal = function() {
            $('#kurForm')[0].reset();
            
            // Cari listesini yükle
            const kurCariSelect = $('#kur_cari_id');
            kurCariSelect.find('option:not(:first)').remove();
            
            $.post('', { action: 'getCariList' }, function(response) {
                if (response.success) {
                    response.data.forEach(item => {
                        kurCariSelect.append(`<option value="${item.cari_id}">${item.cari_adi}</option>`);
                    });
                    kurCariSelect.val('').trigger('change.select2');
                }
            });

            const kurModalEl = document.getElementById('kurModal');
            const kurModal = new bootstrap.Modal(kurModalEl);
            $(kurModalEl).one('shown.bs.modal', function() {
                $('#dolar_kuru').trigger('focus');
            });
            kurModal.show();
        }

        // Dolar kurunu güncelle
        window.updateKur = function() {
            const kur = $('#dolar_kuru').val();
            const cariId = $('#kur_cari_id').val();
            const cariText = cariId ? $('#kur_cari_id option:selected').text() : 'Tüm Cariler';
            
            if (!kur || parseFloat(kur) <= 0) {
                showToast('Lütfen geçerli bir kur değeri giriniz!', 'warning');
                return;
            }
            
            confirmAction(
                'Dolar kurunu güncellemek istediğinize emin misiniz?',
                `"${cariText}" için USD aboneliklerin TL tutarları ${parseFloat(kur).toFixed(2).replace('.', ',')} ₺ kuru ile güncellenecek.`,
                function() {
                    $.post('', { action: 'updateDolarKuru', kur: kur, cari_id: cariId }, function(response) {
                        if (response.success) {
                            showSuccess('Güncellendi!', response.message);
                            const kurModal = bootstrap.Modal.getInstance(document.getElementById('kurModal'));
                            kurModal.hide();
                            loadStats();
                            loadList();
                        } else {
                            showError('Hata!', response.message);
                        }
                    }).fail(function() {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
        
        function syncCloudflare() {
            let cfModal = new bootstrap.Modal(document.getElementById('cfSyncModal'));
            $('#cfSyncLoading').removeClass('d-none');
            $('#cfSyncResult').addClass('d-none');
            $('#cfSyncLogs').html('');
            cfModal.show();
            
            $.post('/admin/cron/cloudflare-domains.php', {ajax: 1}, function(res) {
                $('#cfSyncLoading').addClass('d-none');
                $('#cfSyncResult').removeClass('d-none');
                
                if (res && res.stats) {
                    $('#cfSyncSummary').html(`
                        <strong>Senkronizasyon Tamamlandı!</strong><br>
                        Güncellenen: <b>${res.stats.basarili}</b> | 
                        Yeni Eklenen: <b>${res.stats.yeni_eklenen}</b> | 
                        Değişiklik Yok: <b>${res.stats.degisiklik_yok}</b> |
                        Cari Taşınan: <b>${res.stats.cari_tasinan || 0}</b> |
                        Hata: <b>${res.stats.basarisiz}</b>
                    `);
                    
                    let logHtml = '';
                    (res.logs || []).forEach(log => {
                        let color = '#fff';
                        if (log.type === 'SUCCESS') color = '#28a745';
                        if (log.type === 'ERROR') color = '#dc3545';
                        if (log.type === 'WARNING') color = '#ffc107';
                        if (log.type === 'INFO') color = '#17a2b8';
                        if (log.type === 'DEBUG') color = '#6c757d';
                        
                        logHtml += `<div style="color: ${color};">[${log.time}] [${log.type}] ${log.message}</div>`;
                    });
                    $('#cfSyncLogs').html(logHtml);
                    
                    // İşlem bittikten sonra tabloyu ve istatistikleri yenile
                    loadStats();
                    table.ajax.reload(null, false);
                } else {
                    $('#cfSyncSummary').removeClass('alert-success').addClass('alert-danger').html('Bilinmeyen bir hata oluştu veya geçerli veri alınamadı.');
                }
            }).fail(function() {
                $('#cfSyncLoading').addClass('d-none');
                $('#cfSyncResult').removeClass('d-none');
                $('#cfSyncSummary').removeClass('alert-success').addClass('alert-danger').html('Sunucuya bağlanırken hata oluştu!');
            });
        }

        /**
         * Tek aboneliği bağlı olduğu entegrasyon üzerinden sorgular.
         * Hangi entegrasyonun çalışacağına sunucu tarafı karar verir.
         */
        function sorgula(id) {
            Swal.fire({
                title: 'Sorgulanıyor...',
                text: 'Abonelik bilgileri güncelleniyor.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            $.post('', { action: 'sorgula', id: id }, function(response) {
                Swal.close();

                if (response.success) {
                    showSuccess('Sorgu tamamlandı', response.message);
                    loadStats();
                    loadList();
                } else {
                    showError('Hata!', response.message);
                }
            }, 'json').fail(function() {
                Swal.close();
                showError('Hata!', 'Sorgu sırasında sunucu hatası oluştu.');
            });
        }

        /** Faturam.net kurumlarına bağlı tüm abonelikleri toplu sorgular. */
        function faturamSenkron() {
            confirmAction(
                'Tüm uygun abonelikler sorgulansın mı?',
                'Faturam.net kurumuna bağlı ve abonelik bilgisi girilmiş aktif abonelikler güncellenecek.',
                function() {
                    Swal.fire({
                        title: 'Faturalar sorgulanıyor...',
                        text: 'Bu işlem biraz sürebilir.',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    $.post('', { action: 'faturamSenkron', id: 0 }, function(response) {
                        Swal.close();

                        if (response.success) {
                            showSuccess('Senkronizasyon tamamlandı', response.message);
                            loadStats();
                            loadList();
                        } else {
                            showError('Hata!', response.message);
                        }
                    }, 'json').fail(function() {
                        Swal.close();
                        showError('Hata!', 'Sorgu sırasında sunucu hatası oluştu.');
                    });
                }
            );
        }
    </script>
</body>
</html>
