<?php
/**
 * Admin Panel - Zimmet Yönetimi
 * Personele ürün/hizmet teslimi ve iade takibi
 * Stok_Hareket tablosu kullanılır (belge_tipi = 'ZIMMET')
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['id'],
    $user['departman_id'],
    $currentPageFile
);

// Sayfa erişim kontrolü
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Zimmet Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Personel listesini çek (aktif ve pasif tümü - pasif olanlar etiketli)
$personeller = $db->fetchAll("
    SELECT 
        k.kullanici_id,
        k.kullanici_ad + ' ' + k.kullanici_soyad + CASE WHEN k.kullanici_durum = 0 THEN ' (Pasif)' ELSE '' END as personel_adi,
        k.kullanici_calisma_departman_id,
        k.kullanici_durum,
        d.departman_adi
    FROM kullanicilar k
    LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
    ORDER BY k.kullanici_durum DESC, k.kullanici_ad, k.kullanici_soyad
");

// Zimmetlik ürünleri çek (kategori_id = 5: Fiziki SIM kart vb.)
$kategoriler = $db->fetchAll("SELECT kategori_id, kategori_adi FROM Kategoriler WHERE kategori_durum = 1 ORDER BY kategori_adi");

// Şubeleri çek
$subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikler (ZIMMET belge tipli kayıtlar)
                $toplam = $db->fetchOne("
                    SELECT COUNT(*) as sayi 
                    FROM Stok_Hareket 
                    WHERE stok_hareket_belge_tipi = 'ZIMMET' 
                    AND stok_hareket_durum = 1
                ")['sayi'] ?? 0;
                
                $teslimEdildi = $db->fetchOne("
                    SELECT COUNT(*) as sayi 
                    FROM Stok_Hareket 
                    WHERE stok_hareket_belge_tipi = 'ZIMMET' 
                    AND stok_hareket_tipi = 1
                    AND stok_hareket_durum = 1
                ")['sayi'] ?? 0;
                
                $iadeAlindi = $db->fetchOne("
                    SELECT COUNT(*) as sayi 
                    FROM Stok_Hareket 
                    WHERE stok_hareket_belge_tipi = 'ZIMMET' 
                    AND stok_hareket_tipi = 0
                    AND stok_hareket_durum = 1
                ")['sayi'] ?? 0;
                
                $aktifZimmet = $teslimEdildi - $iadeAlindi;
                
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'toplam' => $toplam,
                        'teslim_edildi' => $teslimEdildi,
                        'iade_alindi' => $iadeAlindi,
                        'aktif_zimmet' => $aktifZimmet
                    ]
                ]);
                break;
                
            case 'list':
                // Filtreler
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $search = $_POST['search'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $kategoriId = $_POST['kategori_id'] ?? '';
                $durum = $_POST['durum'] ?? ''; // teslim, iade, aktif
                $personelDurum = $_POST['personel_durum'] ?? ''; // aktif, pasif, pasif_zimmetli

                $sql = "
                    SELECT 
                        sh.stok_hareket_id,
                        sh.stok_hareket_fatura_no,
                        sh.stok_hareket_tipi,
                        sh.stok_hareket_tarihi,
                        sh.stok_hareket_seri_no,
                        sh.stok_hareket_miktar,
                        sh.stok_hareket_aciklama,
                        sh.stok_hareket_referans,
                        sh.stok_hareket_personel_id,
                        uh.urun_hizmet_adi,
                        uh.urun_hizmet_kodu,
                        uh.urun_hizmet_kategori_id,
                        k.kategori_adi,
                        p.kullanici_ad + ' ' + p.kullanici_soyad as personel_adi,
                        p.kullanici_durum as personel_durum,
                        ISNULL(pz.net_miktar, 0) as personel_acik_zimmet,
                        CASE WHEN p.kullanici_durum = 0 AND ISNULL(pz.net_miktar, 0) > 0 THEN 1 ELSE 0 END as pasif_zimmet_uyari,
                        te.kullanici_ad + ' ' + te.kullanici_soyad as teslim_eden_adi,
                        CONVERT(VARCHAR(19), sh.stok_hareket_tarihi, 120) as stok_hareket_tarihi_str,
                        CONVERT(VARCHAR(19), sh.stok_hareket_olusturma_tarihi, 120) as olusturma_tarihi_str
                    FROM Stok_Hareket sh
                    INNER JOIN Urun_Hizmet uh ON sh.urun_hizmet_id = uh.urun_hizmet_id
                    LEFT JOIN Kategoriler k ON uh.urun_hizmet_kategori_id = k.kategori_id
                    LEFT JOIN kullanicilar p ON sh.stok_hareket_personel_id = p.kullanici_id
                    LEFT JOIN kullanicilar te ON sh.stok_hareket_olusturan_kullanici_id = te.kullanici_id
                    LEFT JOIN (
                        SELECT
                            stok_hareket_personel_id as personel_id,
                            SUM(CASE WHEN stok_hareket_tipi = 1 THEN stok_hareket_miktar ELSE -stok_hareket_miktar END) as net_miktar
                        FROM Stok_Hareket
                        WHERE stok_hareket_belge_tipi = 'ZIMMET'
                          AND stok_hareket_durum = 1
                          AND stok_hareket_personel_id IS NOT NULL
                        GROUP BY stok_hareket_personel_id
                    ) pz ON pz.personel_id = sh.stok_hareket_personel_id
                    WHERE sh.stok_hareket_belge_tipi = 'ZIMMET'
                    AND sh.stok_hareket_durum = 1
                ";
                $params = [];
                
                if ($startDate) {
                    $sql .= " AND CONVERT(date, sh.stok_hareket_tarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(date, sh.stok_hareket_tarihi) <= ?";
                    $params[] = $endDate;
                }
                if ($search) {
                    $sql .= " AND (uh.urun_hizmet_adi LIKE ? OR sh.stok_hareket_seri_no LIKE ? OR sh.stok_hareket_fatura_no LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($personelId) {
                    $sql .= " AND sh.stok_hareket_personel_id = ?";
                    $params[] = $personelId;
                }
                if ($kategoriId) {
                    $sql .= " AND uh.urun_hizmet_kategori_id = ?";
                    $params[] = $kategoriId;
                }
                if ($durum === 'teslim') {
                    $sql .= " AND sh.stok_hareket_tipi = 1"; // Çıkış
                } elseif ($durum === 'iade') {
                    $sql .= " AND sh.stok_hareket_tipi = 0"; // Giriş
                }
                if ($personelDurum === 'aktif') {
                    $sql .= " AND p.kullanici_durum = 1";
                } elseif ($personelDurum === 'pasif') {
                    $sql .= " AND p.kullanici_durum = 0";
                } elseif ($personelDurum === 'pasif_zimmetli') {
                    $sql .= " AND p.kullanici_durum = 0 AND ISNULL(pz.net_miktar, 0) > 0";
                }

                $sql .= " ORDER BY sh.stok_hareket_tarihi DESC, sh.stok_hareket_id DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'get':
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("
                    SELECT * 
                    FROM Stok_Hareket 
                    WHERE stok_hareket_id = ? 
                    AND stok_hareket_belge_tipi = 'ZIMMET'
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'save':
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $islemTipi = $_POST['islem_tipi'] ?? 'teslim'; // teslim veya iade
                $personelId = $_POST['personel_id'] ?? 0;
                $urunId = $_POST['urun_id'] ?? 0;
                $seriNo = trim($_POST['seri_no'] ?? '');
                $miktar = floatval($_POST['miktar'] ?? 1);
                $tarih = !empty($_POST['tarih']) ? $_POST['tarih'] : date('Y-m-d H:i:s');
                $aciklama = trim($_POST['aciklama'] ?? '');
                
                // Tarih formatını doğrula ve düzelt
                if (!empty($tarih)) {
                    // datetime-local formatı: 2026-01-08T10:30 -> 2026-01-08 10:30:00
                    $tarih = str_replace('T', ' ', $tarih);
                    
                    // Eğer sadece tarih varsa (YYYY-MM-DD), saat ekle
                    if (strlen($tarih) === 10) {
                        $tarih .= ' 00:00:00';
                    }
                    // Eğer saniye yoksa ekle (YYYY-MM-DD HH:MM)
                    if (strlen($tarih) === 16) {
                        $tarih .= ':00';
                    }
                    
                    // Tarih geçerliliğini kontrol et
                    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $tarih);
                    if (!$dateObj) {
                        // Geçersiz format, varsayılan kullan
                        $tarih = date('Y-m-d H:i:s');
                    }
                }
                
                // Fatura no oluştur (otomatik)
                $faturaNo = 'ZMT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $data = [
                    'stok_hareket_fatura_no' => $faturaNo,
                    'stok_hareket_tipi' => $islemTipi === 'teslim' ? 1 : 0, // 1: Çıkış (Teslim), 0: Giriş (İade)
                    'stok_hareket_belge_tipi' => 'ZIMMET',
                    'stok_hareket_tarihi' => $tarih,
                    'stok_hareket_cari_id' => null,
                    'stok_hareket_personel_id' => $personelId, // Yeni kolon: Personel ID
                    'urun_hizmet_id' => $urunId,
                    'stok_hareket_miktar' => $miktar,
                    'stok_hareket_birim' => 'ADET',
                    'stok_hareket_birim_fiyat' => 0,
                    'stok_hareket_kdv_id' => 1,
                    'stok_hareket_kdv_tutari' => 0,
                    'stok_hareket_ara_toplam' => 0,
                    'stok_hareket_satir_toplam' => 0,
                    'stok_hareket_seri_no' => $seriNo,
                    'stok_hareket_aciklama' => $aciklama,
                    'stok_hareket_durum' => 1
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['stok_hareket_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['stok_hareket_guncelleyen_kullanici_id'] = $user['id'];
                    $db->update('Stok_Hareket', $data, ['stok_hareket_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Zimmet kaydı güncellendi!']);
                } else {
                    // Yeni kayıt
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['stok_hareket_olusturan_kullanici_id'] = $user['id'];
                    $db->insert('Stok_Hareket', $data);
                    echo json_encode(['success' => true, 'message' => 'Zimmet kaydı eklendi!']);
                }
                break;
                
            case 'toplu_iade':
                // Secili teslim kayitlari icin toplu iade olustur
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Bu islem icin yetkiniz yok!']);
                    break;
                }

                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = [$ids];
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

                if (empty($ids)) {
                    echo json_encode(['success' => false, 'message' => 'Iade alinacak kayit secilmedi!']);
                    break;
                }

                $tarih = !empty($_POST['tarih']) ? str_replace('T', ' ', $_POST['tarih']) : date('Y-m-d H:i:s');
                if (strlen($tarih) === 10) { $tarih .= ' 00:00:00'; }
                if (strlen($tarih) === 16) { $tarih .= ':00'; }
                if (!DateTime::createFromFormat('Y-m-d H:i:s', $tarih)) {
                    $tarih = date('Y-m-d H:i:s');
                }
                $topluAciklama = trim($_POST['aciklama'] ?? '');

                $basarili = 0;
                $atlananlar = [];

                foreach ($ids as $hid) {
                    $kayit = $db->fetchOne("
                        SELECT sh.*, uh.urun_hizmet_adi
                        FROM Stok_Hareket sh
                        INNER JOIN Urun_Hizmet uh ON sh.urun_hizmet_id = uh.urun_hizmet_id
                        WHERE sh.stok_hareket_id = ?
                        AND sh.stok_hareket_belge_tipi = 'ZIMMET'
                        AND sh.stok_hareket_durum = 1
                    ", [$hid]);

                    if (!$kayit) {
                        $atlananlar[] = '#' . $hid . ': kayit bulunamadi';
                        continue;
                    }

                    $etiket = $kayit['urun_hizmet_adi'];
                    $seriNo = trim((string)($kayit['stok_hareket_seri_no'] ?? ''));
                    if ($seriNo !== '') {
                        $etiket .= ' (' . $seriNo . ')';
                    }

                    if ((int)$kayit['stok_hareket_tipi'] !== 1) {
                        $atlananlar[] = $etiket . ': zaten bir iade kaydi';
                        continue;
                    }

                    // Zimmetin halen acik oldugunu dogrula
                    if ($seriNo !== '') {
                        $sonHareket = $db->fetchOne("
                            SELECT TOP 1 stok_hareket_tipi
                            FROM Stok_Hareket
                            WHERE urun_hizmet_id = ?
                            AND stok_hareket_seri_no = ?
                            AND stok_hareket_durum = 1
                            ORDER BY stok_hareket_tarihi DESC, stok_hareket_id DESC
                        ", [$kayit['urun_hizmet_id'], $seriNo]);

                        if (!$sonHareket || (int)$sonHareket['stok_hareket_tipi'] !== 1) {
                            $atlananlar[] = $etiket . ': zaten iade alinmis';
                            continue;
                        }
                    } else {
                        $netSql = "
                            SELECT SUM(CASE WHEN stok_hareket_tipi = 1 THEN stok_hareket_miktar ELSE -stok_hareket_miktar END) as net
                            FROM Stok_Hareket
                            WHERE stok_hareket_belge_tipi = 'ZIMMET'
                            AND stok_hareket_durum = 1
                            AND urun_hizmet_id = ?
                            AND ISNULL(stok_hareket_seri_no, '') = ''
                        ";
                        $netParams = [$kayit['urun_hizmet_id']];
                        if (!empty($kayit['stok_hareket_personel_id'])) {
                            $netSql .= " AND stok_hareket_personel_id = ?";
                            $netParams[] = $kayit['stok_hareket_personel_id'];
                        } else {
                            $netSql .= " AND stok_hareket_personel_id IS NULL";
                        }

                        $net = floatval($db->fetchOne($netSql, $netParams)['net'] ?? 0);
                        if ($net < floatval($kayit['stok_hareket_miktar'])) {
                            $atlananlar[] = $etiket . ': acik zimmet yok';
                            continue;
                        }
                    }

                    $db->insert('Stok_Hareket', [
                        'stok_hareket_fatura_no' => 'ZMT-' . date('Ymd', strtotime($tarih)) . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT),
                        'stok_hareket_tipi' => 0, // 0: Giris (Iade)
                        'stok_hareket_belge_tipi' => 'ZIMMET',
                        'stok_hareket_tarihi' => $tarih,
                        'stok_hareket_cari_id' => null,
                        'stok_hareket_personel_id' => $kayit['stok_hareket_personel_id'],
                        'urun_hizmet_id' => $kayit['urun_hizmet_id'],
                        'stok_hareket_miktar' => $kayit['stok_hareket_miktar'],
                        'stok_hareket_birim' => $kayit['stok_hareket_birim'] ?: 'ADET',
                        'stok_hareket_birim_fiyat' => 0,
                        'stok_hareket_kdv_id' => 1,
                        'stok_hareket_kdv_tutari' => 0,
                        'stok_hareket_ara_toplam' => 0,
                        'stok_hareket_satir_toplam' => 0,
                        'stok_hareket_seri_no' => $seriNo,
                        'stok_hareket_aciklama' => $topluAciklama,
                        'stok_hareket_durum' => 1,
                        'stok_hareket_olusturan_kullanici_id' => $user['id']
                    ]);

                    $basarili++;
                }

                echo json_encode([
                    'success' => $basarili > 0,
                    'message' => $basarili > 0
                        ? $basarili . ' kayit icin iade olusturuldu.'
                        : 'Hicbir kayit icin iade olusturulamadi.',
                    'basarili' => $basarili,
                    'atlanan' => $atlananlar
                ]);
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->update('Stok_Hareket', ['stok_hareket_durum' => 0], ['stok_hareket_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Zimmet kaydı silindi!']);
                break;
                
            case 'get_urunler':
                // Kategori ID'ye göre ürün listesi
                $kategoriId = $_POST['kategori_id'] ?? 0;
                $sql = "
                    SELECT 
                        urun_hizmet_id, 
                        urun_hizmet_adi,
                        urun_hizmet_kodu,
                        urun_hizmet_model
                    FROM Urun_Hizmet 
                    WHERE urun_hizmet_durum = 1
                ";
                $params = [];
                
                if ($kategoriId > 0) {
                    $sql .= " AND urun_hizmet_kategori_id = ?";
                    $params[] = $kategoriId;
                }
                
                $sql .= " ORDER BY urun_hizmet_adi";
                
                $urunler = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $urunler]);
                break;
                
            case 'get_seri_numaralari':
                // Ürüne ait seri numaralarını ve durumlarını getir
                $urunId = $_POST['urun_id'] ?? 0;
                
                if (!$urunId) {
                    echo json_encode(['success' => false, 'message' => 'Ürün ID gerekli!']);
                    break;
                }
                
                // Her seri numarasının son hareketini al
                $seriNumaralari = $db->fetchAll("
                    WITH SonHareketler AS (
                        SELECT 
                            stok_hareket_seri_no,
                            stok_hareket_tipi,
                            stok_hareket_personel_id,
                            ROW_NUMBER() OVER (
                                PARTITION BY stok_hareket_seri_no 
                                ORDER BY stok_hareket_tarihi DESC, stok_hareket_id DESC
                            ) as rn
                        FROM Stok_Hareket
                        WHERE urun_hizmet_id = ?
                        AND stok_hareket_seri_no IS NOT NULL
                        AND stok_hareket_seri_no != ''
                        AND stok_hareket_durum = 1
                    )
                    SELECT 
                        sh.stok_hareket_seri_no as seri_no,
                        sh.stok_hareket_tipi as son_hareket_tipi,
                        CASE 
                            WHEN sh.stok_hareket_tipi = 0 THEN 'stokta'
                            WHEN sh.stok_hareket_tipi = 1 THEN 'zimmetli'
                        END as durum,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as zimmetli_personel
                    FROM SonHareketler sh
                    LEFT JOIN kullanicilar k ON sh.stok_hareket_personel_id = k.kullanici_id
                    WHERE sh.rn = 1
                    ORDER BY sh.stok_hareket_seri_no
                ", [$urunId]);
                
                echo json_encode(['success' => true, 'data' => $seriNumaralari]);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .badge-teslim { background-color: #dc3545; }
        .badge-iade { background-color: #198754; }
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
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-archive"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam İşlem</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-box-arrow-right"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Teslim Edildi</span>
                                    <span class="info-box-number" id="stat-teslim">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-box-arrow-in-left"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İade Alındı</span>
                                    <span class="info-box-number" id="stat-iade">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-hourglass-split"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Zimmet</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtreler
                            </h3>
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
                                            <label class="form-label">Başlangıç Tarihi</label>
                                            <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bitiş Tarihi</label>
                                            <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Personel</label>
                                            <select class="form-select" id="filter_personel_id" name="personel_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($personeller as $personel): ?>
                                                    <option value="<?= $personel['kullanici_id'] ?>"><?= htmlspecialchars($personel['personel_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Kategori</label>
                                            <select class="form-select" id="filter_kategori_id" name="kategori_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($kategoriler as $kat): ?>
                                                    <option value="<?= $kat['kategori_id'] ?>"><?= htmlspecialchars($kat['kategori_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Durum</label>
                                            <select class="form-select" id="filter_durum" name="durum">
                                                <option value="">Tümü</option>
                                                <option value="teslim">Teslim Edildi</option>
                                                <option value="iade">İade Alındı</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Personel Durumu</label>
                                            <select class="form-select" id="filter_personel_durum" name="personel_durum">
                                                <option value="">Tümü</option>
                                                <option value="aktif">Aktif Personel</option>
                                                <option value="pasif">Pasif Personel</option>
                                                <option value="pasif_zimmetli">Pasif Personel - Zimmeti Var</option>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">Arama</label>
                                            <input type="text" class="form-control" id="filter_search" name="search" placeholder="Ürün adı, seri no veya fatura no ile ara...">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-search"></i> Ara
                                            </button>
                                            <button type="button" class="btn btn-secondary" id="clearFilters">
                                                <i class="bi bi-x-circle"></i> Temizle
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-list-ul"></i> Zimmet Kayıtları</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-danger btn-sm me-2" id="btnTeslimEt">
                                    <i class="bi bi-box-arrow-right"></i> Teslim Et
                                </button>
                                <button type="button" class="btn btn-success btn-sm" id="btnIadeAl">
                                    <i class="bi bi-box-arrow-in-left"></i> İade Al
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm ms-2" id="btnTutanakTeslim">
                                    <i class="bi bi-file-earmark-text"></i> Teslim Tutanağı
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm" id="btnTutanakIade">
                                    <i class="bi bi-file-earmark-text"></i> İade Tutanağı
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable">
                                    <thead>
                                        <tr>
                                            <th width="40" class="text-center">
                                                <input type="checkbox" id="selectAll">
                                            </th>
                                            <th width="80">İşlem</th>
                                            <th width="120">Tarih</th>
                                            <th>Personel</th>
                                            <th>Ürün</th>
                                            <th>Kategori</th>
                                            <th>Seri No</th>
                                            <th>Miktar</th>
                                            <th>Açıklama</th>
                                            <th>Teslim Eden</th>
                                            <th width="100">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr>
                                            <td colspan="11" class="text-center">Yükleniyor...</td>
                                        </tr>
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
    
    <!-- Modal: Zimmet Teslim/İade -->
    <div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Zimmet Teslim Et</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="saveForm">
                    <div class="modal-body">
                        <input type="hidden" id="zimmet_id" name="id">
                        <input type="hidden" id="islem_tipi" name="islem_tipi" value="teslim">
                        
                        <div class="row g-3">
                            <!-- Personel -->
                            <div class="col-md-6">
                                <label class="form-label">Personel <span class="text-danger">*</span></label>
                                <select class="form-select" id="personel_id" name="personel_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($personeller as $personel): ?>
                                        <option value="<?= $personel['kullanici_id'] ?>">
                                            <?= htmlspecialchars($personel['personel_adi']) ?>
                                            <?= $personel['departman_adi'] ? ' (' . htmlspecialchars($personel['departman_adi']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Tarih -->
                            <div class="col-md-6">
                                <label class="form-label">Tarih <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" id="tarih" name="tarih" value="<?= date('Y-m-d\TH:i') ?>" required>
                            </div>
                            
                            <!-- Kategori -->
                            <div class="col-md-6">
                                <label class="form-label">Kategori</label>
                                <select class="form-select" id="kategori_id" name="kategori_id">
                                    <option value="">Tümü</option>
                                    <?php foreach ($kategoriler as $kat): ?>
                                        <option value="<?= $kat['kategori_id'] ?>"><?= htmlspecialchars($kat['kategori_adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Ürün -->
                            <div class="col-md-6">
                                <label class="form-label">Ürün <span class="text-danger">*</span></label>
                                <select class="form-select" id="urun_id" name="urun_id" required>
                                    <option value="">Önce kategori seçin...</option>
                                </select>
                            </div>
                            
                            <!-- Seri No -->
                            <div class="col-md-8">
                                <label class="form-label">Seri No</label>
                                <select class="form-select" id="seri_no" name="seri_no">
                                    <option value="">Önce ürün seçin...</option>
                                </select>
                                <small class="text-muted" id="seri_no_info"></small>
                            </div>
                            
                            <!-- Miktar -->
                            <div class="col-md-4">
                                <label class="form-label">Miktar</label>
                                <input type="number" step="1" min="1" class="form-control" id="miktar" name="miktar" value="1">
                            </div>
                            
                            <!-- Açıklama -->
                            <div class="col-md-12">
                                <label class="form-label">Açıklama/Not</label>
                                <textarea class="form-control" id="aciklama" name="aciklama" rows="3" placeholder="İsteğe bağlı açıklama..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js" crossorigin="anonymous"></script>
    
    <script>
        const permissions = <?= json_encode($pagePermissions) ?>;
        const modal = new bootstrap.Modal('#modalForm');
        let tableDataMap = new Map();
        let selectedRowIds = new Set();
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-teslim').text(response.data.teslim_edildi);
                    $('#stat-iade').text(response.data.iade_alindi);
                    $('#stat-aktif').text(response.data.aktif_zimmet);
                }
            });
        }
        
        let currentFilters = {};
        
        // Liste yükle
        function loadList() {
            $.post('', { 
                action: 'list',
                ...currentFilters 
            }, response => {
                if (response.success) {
                    renderTable(response.data);
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        // Tabloyu render et
        function renderTable(data) {
            const tbody = $('#tableBody');
            tbody.empty();
            tableDataMap = new Map();
            
            if (data.length === 0) {
                tbody.html('<tr><td colspan="11" class="text-center">Kayıt bulunamadı</td></tr>');
                return;
            }
            
            data.forEach(item => {
                const rowId = String(item.stok_hareket_id);
                tableDataMap.set(rowId, item);
                const islemBadge = item.stok_hareket_tipi == 1
                    ? '<span class="badge badge-teslim">Teslim</span>'
                    : '<span class="badge badge-iade">İade</span>';

                // Personel hücresi: pasif personelin iade edilmemiş zimmeti varsa uyarı simgesi
                let personelHucre = item.personel_adi || '-';
                if (item.personel_durum == 0) {
                    personelHucre += ' <span class="badge text-bg-secondary">Pasif</span>';
                }
                if (item.pasif_zimmet_uyari == 1) {
                    const acik = parseFloat(item.personel_acik_zimmet || 0);
                    personelHucre += ` <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Personel pasif fakat ${acik} adet zimmeti iade alınmamış"></i>`;
                }

                const row = `
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="row-check" value="${rowId}" ${selectedRowIds.has(rowId) ? 'checked' : ''}>
                        </td>
                        <td class="text-center">${islemBadge}</td>
                        <td>${formatDate(item.stok_hareket_tarihi_str)}</td>
                        <td>${personelHucre}</td>
                        <td>${item.urun_hizmet_adi}<br><small class="text-muted">${item.urun_hizmet_kodu || ''}</small></td>
                        <td>${item.kategori_adi || '-'}</td>
                        <td><code>${item.stok_hareket_seri_no || '-'}</code></td>
                        <td>${item.stok_hareket_miktar}</td>
                        <td>${item.stok_hareket_aciklama || '-'}</td>
                        <td>${item.teslim_eden_adi || '-'}</td>
                        <td>
                            ${permissions.can_edit ? `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${item.stok_hareket_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>` : ''}
                            ${permissions.can_delete ? `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${item.stok_hareket_id})" title="Sil"><i class="bi bi-trash"></i></button>` : ''}
                        </td>
                    </tr>
                `;
                tbody.append(row);
            });

            const total = $('.row-check').length;
            const checked = $('.row-check:checked').length;
            $('#selectAll').prop('checked', total > 0 && total === checked);
            updateIadeButton();
        }
        
        // Tarih formatlama
        function formatDate(dateString) {
            if (!dateString) return '-';
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }

        let pdfFontBase64 = null;

        function arrayBufferToBase64(buffer) {
            let binary = '';
            const bytes = new Uint8Array(buffer);
            const len = bytes.byteLength;
            for (let i = 0; i < len; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary);
        }

        async function getPdfFontBase64() {
            if (pdfFontBase64) return pdfFontBase64;
            const response = await fetch('/admin/assets/fonts/NotoSans-Regular.ttf');
            if (!response.ok) {
                throw new Error('Font yuklenemedi');
            }
            const buffer = await response.arrayBuffer();
            pdfFontBase64 = arrayBufferToBase64(buffer);
            return pdfFontBase64;
        }

        function getSelectedRows() {
            return Array.from(selectedRowIds).map(id => tableDataMap.get(id)).filter(Boolean);
        }

        async function generateTutanak(islemTipi) {
            const selected = getSelectedRows();
            if (selected.length === 0) {
                showWarning('Uyari!', 'Lutfen tutanak icin en az bir kayit secin.');
                return;
            }

            const filtered = selected.filter(item =>
                islemTipi === 'teslim' ? item.stok_hareket_tipi == 1 : item.stok_hareket_tipi == 0
            );

            if (filtered.length === 0) {
                showWarning('Uyari!', 'Secilen kayitlar islem tipine uygun degil.');
                return;
            }

            if (filtered.length !== selected.length) {
                showToast('Uyusmayan kayitlar disari alindi.', 'warning');
            }

            const personelSet = new Set(filtered.map(item => item.personel_adi || ''));
            if (personelSet.size > 1) {
                showWarning('Uyari!', 'Tutanak icin ayni personele ait kayitlar secilmeli.');
                return;
            }

            const personelAdi = filtered[0].personel_adi || '-';
            const teslimEdenAdi = filtered[0].teslim_eden_adi || '-';
            const title = islemTipi === 'teslim' ? 'Zimmet Teslim Tutanagi' : 'Zimmet Iade Tutanagi';
            const alanLabel = islemTipi === 'teslim' ? 'Teslim Alan' : 'Iade Eden';
            const verenLabel = islemTipi === 'teslim' ? 'Teslim Eden' : 'Iade Alan';
            const tutanakTarihi = new Date().toLocaleString('tr-TR');

            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'mm', 'a4');

            try {
                const fontBase64 = await getPdfFontBase64();
                doc.addFileToVFS('NotoSans-Regular.ttf', fontBase64);
                doc.addFont('NotoSans-Regular.ttf', 'NotoSans', 'normal');
                doc.setFont('NotoSans');
            } catch (e) {
                showError('Hata!', 'PDF fontu yuklenemedi.');
                return;
            }

            doc.setFontSize(16);
            doc.text(title, 105, 18, { align: 'center' });

            doc.setFontSize(10);
            doc.text(`Tutanak Tarihi: ${tutanakTarihi}`, 14, 28);
            doc.text(`${alanLabel}: ${personelAdi}`, 14, 34);
            doc.text(`${verenLabel}: ${teslimEdenAdi}`, 14, 40);

            const body = filtered.map((item, index) => [
                String(index + 1),
                item.urun_hizmet_adi || '-',
                item.kategori_adi || '-',
                item.stok_hareket_seri_no || '-',
                String(item.stok_hareket_miktar || 0),
                item.stok_hareket_aciklama || '-'
            ]);

            doc.autoTable({
                head: [['#', 'Urun', 'Kategori', 'Seri No', 'Miktar', 'Aciklama']],
                body,
                startY: 46,
                styles: { fontSize: 9 },
                headStyles: { fillColor: [13, 110, 253] }
            });

            const finalY = doc.lastAutoTable ? doc.lastAutoTable.finalY : 46;
            let textY = finalY + 10;

            doc.setFontSize(9);
            if (islemTipi === 'teslim') {
                const p1 = 'Isbu belge ile, yukarida marka, model ve bilgileri belirtilen malzeme/cihazlarin tarafima eksiksiz ve calisir durumda teslim edildigini kabul, beyan ve taahhut ederim. Teslim edilen malzemelerin yalnizca sahsim tarafindan kullanilacagini, sirket personeli dahil olmak uzere ucuncu kisilerle hicbir sekilde degisim veya paylasim yapilmayacagini, herhangi bir ariza durumunda derhal bilgi verecegimi taahhut ederim.';
                const p2 = 'Tarafima zimmetlenen bu malzemelerin kaybolmasi, hasar gormesi veya sirketimize iade edilmemesi halinde dogacak maddi zararin tarafima ait olacagini ve bu tutarin maasimdan mahsup edilebilecegini pesinen kabul ederim. Ayrica bu taahhutume aykiri davranmam durumunda dogabilecek her turlu hukuki, idari ve cezai sorumlulugun tarafima ait olacagini kabul ve taahhut ederim.';

                const lines1 = doc.splitTextToSize(p1, 180);
                doc.text(lines1, 14, textY);
                textY += (lines1.length * 5) + 2;

                const lines2 = doc.splitTextToSize(p2, 180);
                doc.text(lines2, 14, textY);
                textY += (lines2.length * 5) + 6;
            } else {
                const p3 = 'Yukarida belirtilen malzemeleri eksizsiz ve saglam bir sekilde iade ettigimi beyan ederim.';
                const lines3 = doc.splitTextToSize(p3, 180);
                doc.text(lines3, 14, textY);
                textY += (lines3.length * 5) + 6;
            }

            doc.setFontSize(10);
            const leftX = 14;
            const rightX = 110;
            const signY = textY + 4;
            doc.text(`${alanLabel} Imza: ________________________`, leftX, signY);
            doc.text(`${verenLabel} Imza: ________________________`, rightX, signY);

            const pdfBlob = doc.output('blob');
            const pdfUrl = URL.createObjectURL(pdfBlob);

            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            iframe.src = pdfUrl;
            document.body.appendChild(iframe);

            iframe.onload = function() {
                try {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                } finally {
                    setTimeout(() => {
                        URL.revokeObjectURL(pdfUrl);
                        iframe.remove();
                    }, 1000);
                }
            };
        }
        
        // Teslim et
        $('#btnTeslimEt').on('click', function() {
            $('#modalTitle').text('Zimmet Teslim Et');
            $('#saveForm')[0].reset();
            $('#zimmet_id').val('');
            $('#islem_tipi').val('teslim');
            $('#tarih').val('<?= date('Y-m-d\TH:i') ?>');
            $('#urun_id').empty().append('<option value="">Önce kategori seçin...</option>');
            $('#seri_no').empty().append('<option value="">Önce ürün seçin...</option>');
            $('#seri_no_info').text('');
            initModalSelect2();
            modal.show();
        });

        $('#btnTutanakTeslim').on('click', function() {
            generateTutanak('teslim');
        });
        
        // Secili kayit sayisina gore İade Al butonunu guncelle
        function updateIadeButton() {
            const btn = $('#btnIadeAl');
            if (!btn.length) return;
            const adet = getSelectedRows().filter(item => item.stok_hareket_tipi == 1).length;
            btn.html('<i class="bi bi-box-arrow-in-left"></i> İade Al' + (adet > 0 ? ' (' + adet + ')' : ''));
            btn.attr('title', adet > 0 ? adet + ' secili kayit icin toplu iade alinir' : 'Tekil iade formunu acar');
        }

        // Secili teslim kayitlari icin toplu iade
        function topluIadeAl() {
            const selected = getSelectedRows();
            const teslimler = selected.filter(item => item.stok_hareket_tipi == 1);

            if (teslimler.length === 0) {
                showWarning('Uyarı!', 'Seçili kayıtlar arasında iade alınabilecek teslim kaydı yok.');
                return;
            }

            const atlanacak = selected.length - teslimler.length;
            const liste = teslimler.slice(0, 10).map(item =>
                '<li>' + item.urun_hizmet_adi + (item.stok_hareket_seri_no ? ' - ' + item.stok_hareket_seri_no : '') +
                ' <small class="text-muted">(' + (item.personel_adi || '-') + ')</small></li>'
            ).join('');
            const fazlasi = teslimler.length > 10 ? '<li>... ve ' + (teslimler.length - 10) + ' kayıt daha</li>' : '';

            Swal.fire({
                title: 'Toplu İade Al',
                html: '<p class="mb-2">' + teslimler.length + ' kayıt için iade oluşturulacak:</p>' +
                      '<ul class="text-start small">' + liste + fazlasi + '</ul>' +
                      (atlanacak > 0 ? '<p class="text-muted small mb-0">' + atlanacak + ' adet iade kaydı listeden çıkarıldı.</p>' : ''),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'İade Al',
                cancelButtonText: 'İptal',
                confirmButtonColor: '#198754'
            }).then(result => {
                if (!result.isConfirmed) return;

                $.post('', {
                    action: 'toplu_iade',
                    ids: teslimler.map(item => item.stok_hareket_id)
                }, response => {
                    const atlanan = response.atlanan || [];
                    if (response.success) {
                        selectedRowIds = new Set();
                        $('#selectAll').prop('checked', false);
                        loadStats();
                        loadList();
                        if (atlanan.length > 0) {
                            Swal.fire({
                                title: 'Kısmen tamamlandı',
                                html: response.message + '<ul class="text-start small mt-2"><li>' + atlanan.join('</li><li>') + '</li></ul>',
                                icon: 'warning'
                            });
                        } else {
                            showSuccess('İade alındı!', response.message);
                        }
                    } else {
                        showError('Hata!', response.message + (atlanan.length > 0 ? ' ' + atlanan.join(' | ') : ''));
                    }
                }, 'json');
            });
        }

        // İade al
        $('#btnIadeAl').on('click', function() {
            // Tabloda secili kayit varsa toplu iade, yoksa tekil iade formu
            if (selectedRowIds.size > 0) {
                topluIadeAl();
                return;
            }

            $('#modalTitle').text('Zimmet İade Al');
            $('#saveForm')[0].reset();
            $('#zimmet_id').val('');
            $('#islem_tipi').val('iade');
            $('#tarih').val('<?= date('Y-m-d\TH:i') ?>');
            $('#urun_id').empty().append('<option value="">Önce kategori seçin...</option>');
            $('#seri_no').empty().append('<option value="">Önce ürün seçin...</option>');
            $('#seri_no_info').text('');
            initModalSelect2();
            modal.show();
        });

        $('#btnTutanakIade').on('click', function() {
            generateTutanak('iade');
        });
        
        // Kayıt düzenle
        function editRecord(id) {
            $.post('', { action: 'get', id: id }, response => {
                if (response.success && response.data) {
                    const data = response.data;
                    
                    $('#modalTitle').text('Zimmet Kaydı Düzenle');
                    $('#zimmet_id').val(data.stok_hareket_id);
                    $('#islem_tipi').val(data.stok_hareket_tipi == 1 ? 'teslim' : 'iade');
                    
                    // Tarih formatla
                    if (data.stok_hareket_tarihi) {
                        let tarih = data.stok_hareket_tarihi;
                        if (typeof tarih === 'object' && tarih.date) {
                            tarih = tarih.date;
                        }
                        // datetime-local formatına çevir
                        tarih = tarih.replace(' ', 'T').substring(0, 16);
                        $('#tarih').val(tarih);
                    }
                    
                    $('#personel_id').val(data.stok_hareket_personel_id);
                    $('#miktar').val(data.stok_hareket_miktar);
                    $('#aciklama').val(data.stok_hareket_aciklama);
                    
                    initModalSelect2();
                    
                    // Ürün bilgisi için önce kategori yükle
                    $.post('', { action: 'get_urunler', kategori_id: 0 }, urunResponse => {
                        if (urunResponse.success) {
                            const urunSelect = $('#urun_id');
                            if (urunSelect.hasClass("select2-hidden-accessible")) {
                                urunSelect.select2('destroy');
                            }
                            
                            urunSelect.empty().append('<option value="">Seçiniz...</option>');
                            urunResponse.data.forEach(urun => {
                                const displayText = urun.urun_hizmet_adi + 
                                    (urun.urun_hizmet_kodu ? ' (' + urun.urun_hizmet_kodu + ')' : '') +
                                    (urun.urun_hizmet_model ? ' - ' + urun.urun_hizmet_model : '');
                                urunSelect.append(`<option value="${urun.urun_hizmet_id}">${displayText}</option>`);
                            });
                            
                            urunSelect.val(data.urun_hizmet_id);
                            
                            urunSelect.select2({
                                theme: 'bootstrap-5',
                                dropdownParent: $('#modalForm'),
                                placeholder: 'Ürün seçiniz...',
                                allowClear: true
                            });
                            
                            // Seri numarası yükle
                            if (data.urun_hizmet_id) {
                                loadSeriNumaralari(data.urun_hizmet_id);
                                setTimeout(() => {
                                    // Mevcut seri no'yu seç veya ekle
                                    if (data.stok_hareket_seri_no) {
                                        const seriSelect = $('#seri_no');
                                        // Option yoksa ekle
                                        if (seriSelect.find(`option[value="${data.stok_hareket_seri_no}"]`).length === 0) {
                                            seriSelect.append(`<option value="${data.stok_hareket_seri_no}">${data.stok_hareket_seri_no}</option>`);
                                        }
                                        seriSelect.val(data.stok_hareket_seri_no).trigger('change.select2');
                                    }
                                }, 500);
                            }
                        }
                    });
                    
                    modal.show();
                } else {
                    showError('Hata!', 'Kayıt bulunamadı.');
                }
            });
        }
        
        // Kayıt sil
        function deleteRecord(id) {
            confirmAction(
                'Bu zimmet kaydını silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, response => {
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
        
        // Form kaydet
        $('#saveForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize() + '&action=save';
            
            $.post('', formData, response => {
                if (response.success) {
                    showToast(response.message, 'success');
                    modal.hide();
                    loadStats();
                    loadList();
                } else {
                    showToast(response.message, 'error');
                }
            });
        });
        
        // Kategori değişince ürünleri yükle
        $('#kategori_id').on('change', function() {
            const kategoriId = $(this).val();
            loadUrunler(kategoriId);
        });
        
        // Ürünleri yükle
        function loadUrunler(kategoriId = 0) {
            $.post('', { action: 'get_urunler', kategori_id: kategoriId }, response => {
                if (response.success) {
                    const urunSelect = $('#urun_id');
                    if (urunSelect.hasClass("select2-hidden-accessible")) {
                        urunSelect.select2('destroy');
                    }
                    
                    urunSelect.empty().append('<option value="">Seçiniz...</option>');
                    response.data.forEach(urun => {
                        const displayText = urun.urun_hizmet_adi + 
                            (urun.urun_hizmet_kodu ? ' (' + urun.urun_hizmet_kodu + ')' : '') +
                            (urun.urun_hizmet_model ? ' - ' + urun.urun_hizmet_model : '');
                        urunSelect.append(`<option value="${urun.urun_hizmet_id}">${displayText}</option>`);
                    });
                    
                    urunSelect.select2({
                        theme: 'bootstrap-5',
                        dropdownParent: $('#modalForm'),
                        placeholder: 'Ürün seçiniz...',
                        allowClear: true,
                        language: {
                            noResults: function() { return "Sonuç bulunamadı"; },
                            searching: function() { return "Aranıyor..."; }
                        }
                    });
                    
                    // Ürün seçilince seri numaralarını yükle
                    urunSelect.off('change.serino').on('change.serino', function() {
                        const urunId = $(this).val();
                        loadSeriNumaralari(urunId);
                    });
                }
            });
        }
        
        // Seri numaralarını yükle
        function loadSeriNumaralari(urunId) {
            const seriSelect = $('#seri_no');
            const seriInfo = $('#seri_no_info');
            const islemTipi = $('#islem_tipi').val();
            
            // Select2 varsa kaldır
            if (seriSelect.hasClass("select2-hidden-accessible")) {
                seriSelect.select2('destroy');
            }
            
            seriSelect.empty().append('<option value="">Seri no seçin veya boş bırakın...</option>');
            seriInfo.text('');
            
            if (!urunId) {
                seriSelect.prop('disabled', false);
                return;
            }
            
            $.post('', { action: 'get_seri_numaralari', urun_id: urunId }, response => {
                if (response.success && response.data.length > 0) {
                    let stoktaSayisi = 0;
                    let zimmetliSayisi = 0;
                    
                    response.data.forEach(seri => {
                        let optionText = seri.seri_no;
                        let disabled = false;
                        
                        if (islemTipi === 'teslim') {
                            // Teslim işlemi: Sadece stokta olanlar seçilebilir
                            if (seri.durum === 'zimmetli') {
                                optionText += ' (Zimmetli: ' + seri.zimmetli_personel + ')';
                                disabled = true;
                                zimmetliSayisi++;
                            } else {
                                optionText += ' (Stokta)';
                                stoktaSayisi++;
                            }
                        } else {
                            // İade işlemi: Sadece zimmetli olanlar seçilebilir
                            if (seri.durum === 'stokta') {
                                optionText += ' (Stokta)';
                                disabled = true;
                                stoktaSayisi++;
                            } else {
                                optionText += ' (Zimmetli: ' + seri.zimmetli_personel + ')';
                                zimmetliSayisi++;
                            }
                        }
                        
                        seriSelect.append(`<option value="${seri.seri_no}" ${disabled ? 'disabled' : ''}>${optionText}</option>`);
                    });
                    
                    // Bilgi metni
                    if (islemTipi === 'teslim') {
                        seriInfo.html(`<span class="text-success">${stoktaSayisi} adet stokta</span>, <span class="text-danger">${zimmetliSayisi} adet zimmetli</span>`);
                    } else {
                        seriInfo.html(`<span class="text-danger">${zimmetliSayisi} adet zimmetli (iade alınabilir)</span>, <span class="text-secondary">${stoktaSayisi} adet stokta</span>`);
                    }
                } else {
                    seriInfo.text('Bu ürün için kayıtlı seri numarası yok');
                }
                
                // Select2 uygula
                seriSelect.select2({
                    theme: 'bootstrap-5',
                    dropdownParent: $('#modalForm'),
                    placeholder: 'Seri no seçin veya boş bırakın...',
                    allowClear: true,
                    tags: true, // Yeni seri no girilebilir
                    language: {
                        noResults: function() { return "Sonuç bulunamadı"; },
                        searching: function() { return "Aranıyor..."; }
                    }
                });
            });
        }
        
        // Modal Select2 başlat
        function initModalSelect2() {
            $('#personel_id, #kategori_id, #urun_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#modalForm'),
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
        }
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                start_date: $('#filter_start_date').val(),
                end_date: $('#filter_end_date').val(),
                search: $('#filter_search').val(),
                personel_id: $('#filter_personel_id').val(),
                kategori_id: $('#filter_kategori_id').val(),
                durum: $('#filter_durum').val(),
                personel_durum: $('#filter_personel_durum').val()
            };
            
            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) delete currentFilters[key];
            });
            
            loadList();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_personel_id').val('').trigger('change.select2');
            $('#filter_kategori_id').val('').trigger('change.select2');
            $('#filter_durum').val('').trigger('change.select2');
            $('#filter_personel_durum').val('').trigger('change.select2');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        $(document).on('change', '#selectAll', function() {
            const isChecked = $(this).is(':checked');
            $('.row-check').prop('checked', isChecked);
            selectedRowIds = new Set();
            if (isChecked) {
                $('.row-check').each(function() {
                    selectedRowIds.add(String($(this).val()));
                });
            }
            updateIadeButton();
        });

        $(document).on('change', '.row-check', function() {
            const rowId = String($(this).val());
            if ($(this).is(':checked')) {
                selectedRowIds.add(rowId);
            } else {
                selectedRowIds.delete(rowId);
            }
            const total = $('.row-check').length;
            const checked = $('.row-check:checked').length;
            $('#selectAll').prop('checked', total > 0 && total === checked);
            updateIadeButton();
        });
        
        // Filtre dropdownlarını aramalı yap
        function initFilterSelect2() {
            $('#filter_personel_id, #filter_kategori_id, #filter_durum, #filter_personel_durum').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: false,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
        }

        // Sayfa yüklendiğinde
        $(document).ready(function() {
            initFilterSelect2();
            loadStats();
            loadList();
        });
    </script>
</body>
</html>
