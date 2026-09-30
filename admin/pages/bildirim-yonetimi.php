<?php
/**
 * Admin Panel - Bildirim Yönetimi
 * Tüm bildirimleri listeleme, okundu işaretleme, silme
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Bildirim sayfası tüm giriş yapmış kullanıcılara açık
// PageAuth kontrolü yapılmaz

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

// Sayfa bilgileri
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Bildirim Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Bildirim tipleri
$bildirimTipleri = [
    'sistem' => ['label' => 'Sistem', 'icon' => 'bi-gear', 'renk' => 'secondary'],
    'basvuru' => ['label' => 'Başvuru', 'icon' => 'bi-person-plus', 'renk' => 'warning'],
    'talep' => ['label' => 'Talep', 'icon' => 'bi-ticket', 'renk' => 'info'],
    'mesaj' => ['label' => 'Mesaj', 'icon' => 'bi-envelope', 'renk' => 'primary'],
    'uyari' => ['label' => 'Uyarı', 'icon' => 'bi-exclamation-triangle', 'renk' => 'danger'],
    'bilgi' => ['label' => 'Bilgi', 'icon' => 'bi-info-circle', 'renk' => 'info'],
    'basari' => ['label' => 'Başarı', 'icon' => 'bi-check-circle', 'renk' => 'success'],

];

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $userId = $user['kullanici_id'];
    $departmanId = $user['departman_id'] ?? null;
    $firmaId = $user['kullanici_firma_id'] ?? null;
    $subeId = $user['kullanici_sube_id'] ?? null;
    
    // Kullanıcının IT gruplarını al
    $userGruplari = $db->fetchAll("SELECT grup_id FROM IT_Grup_Uyeleri WHERE kullanici_id = ? AND uye_durum = 1", [$userId]);
    $userGrupIds = array_column($userGruplari, 'grup_id');
    $isITPersonel = count($userGrupIds) > 0;
    $isAdmin = ($userId == 1);
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir - kullanıcının görebileceği bildirimler
                $baseWhere = "
                    FROM vw_kullanici_bildirimleri 
                    WHERE kullanici_id = ? AND silindi = 0
                ";
                
                $bildirimToplam = $db->fetchOne("SELECT COUNT(*) as sayi $baseWhere", [$userId])['sayi'] ?? 0;
                $bildirimOkunmamis = $db->fetchOne("SELECT COUNT(*) as sayi $baseWhere AND okundu = 0", [$userId])['sayi'] ?? 0;
                $bildirimOkunmus = $db->fetchOne("SELECT COUNT(*) as sayi $baseWhere AND okundu = 1", [$userId])['sayi'] ?? 0;
                $bildirimBugun = $db->fetchOne("SELECT COUNT(*) as sayi $baseWhere AND CONVERT(date, bildirim_olusturma_tarihi) = CONVERT(date, GETDATE())", [$userId])['sayi'] ?? 0;
                
                // IT Talep bildirimleri sayısını ekle
                $itTalepSayi = 0;
                $itTalepBugun = 0;
                if ($isAdmin || $isITPersonel) {
                    $itWhere = "FROM IT_Talepler t 
                        INNER JOIN IT_Talep_Kategoriler k ON t.talep_kategori_id = k.kategori_id
                        WHERE t.talep_durum_id NOT IN (SELECT durum_id FROM IT_Talep_Durumlar WHERE durum_adi LIKE '%Tamamlan%' OR durum_adi LIKE '%Kapan%' OR durum_adi LIKE '%İptal%')";
                    
                    if (!$isAdmin) {
                        $grupPH = implode(',', array_fill(0, count($userGrupIds), '?'));
                        $itWhere .= " AND (k.kategori_varsayilan_grup_id IN ($grupPH) OR t.talep_atanan_grup_id IN ($grupPH) OR t.talep_atanan_kullanici_id = ?)";
                        $itParams = array_merge($userGrupIds, $userGrupIds, [$userId]);
                    } else {
                        $itParams = [];
                    }
                    
                    $itTalepSayi = $db->fetchOne("SELECT COUNT(*) as sayi $itWhere", $itParams)['sayi'] ?? 0;
                    $itTalepBugun = $db->fetchOne("SELECT COUNT(*) as sayi $itWhere AND CONVERT(date, t.talep_olusturma_tarihi) = CONVERT(date, GETDATE())", $itParams)['sayi'] ?? 0;
                }
                
                $stats = [
                    'toplam' => $bildirimToplam + $itTalepSayi,
                    'okunmamis' => $bildirimOkunmamis + $itTalepSayi,
                    'okunmus' => $bildirimOkunmus,
                    'bugun' => $bildirimBugun + $itTalepBugun
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $okunduDurum = $_POST['okundu_durum'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $search = $_POST['search'] ?? '';
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                
                // 1) Bildirimler tablosundan çek
                $sql = "SELECT 
                    bildirim_id,
                    bildirim_hedef_tip,
                    bildirim_tip,
                    bildirim_baslik,
                    bildirim_mesaj,
                    bildirim_link,
                    bildirim_icon,
                    bildirim_renk,
                    bildirim_kaynak_tip,
                    bildirim_kaynak_id,
                    okundu,
                    CONVERT(VARCHAR(19), okunma_tarihi, 120) as okunma_tarihi,
                    CONVERT(VARCHAR(19), bildirim_olusturma_tarihi, 120) as bildirim_olusturma_tarihi
                FROM vw_kullanici_bildirimleri 
                WHERE kullanici_id = ? AND silindi = 0";
                $params = [$userId];
                
                // Tip filtresi (talep filtresi seçildiyse sadece IT talepleri göster)
                $showBildirimler = true;
                $showITTalepler = ($isAdmin || $isITPersonel);
                
                if ($tip) {
                    if ($tip === 'it_talep') {
                        $showBildirimler = false; // Sadece IT taleplerini göster
                    } else {
                        $showITTalepler = false; // Sadece bildirimleri göster
                        $sql .= " AND bildirim_tip = ?";
                        $params[] = $tip;
                    }
                }
                
                // Okundu durumu filtresi
                if ($okunduDurum !== '') {
                    if ($okunduDurum == '1') {
                        $showITTalepler = false; // IT talepleri "okundu" olarak işaretlenemez
                    }
                    if ($showBildirimler) {
                        $sql .= " AND okundu = ?";
                        $params[] = $okunduDurum;
                    }
                }
                
                // Arama filtresi
                if ($search && $showBildirimler) {
                    $sql .= " AND (bildirim_baslik LIKE ? OR bildirim_mesaj LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                // Tarih filtreleri
                if ($startDate && $showBildirimler) {
                    $sql .= " AND CONVERT(date, bildirim_olusturma_tarihi) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate && $showBildirimler) {
                    $sql .= " AND CONVERT(date, bildirim_olusturma_tarihi) <= ?";
                    $params[] = $endDate;
                }
                
                $sql .= " ORDER BY bildirim_olusturma_tarihi DESC";
                
                $data = $showBildirimler ? $db->fetchAll($sql, $params) : [];
                
                // 2) IT Talepler - yetkili kategorilerden gelen açık talepler
                if ($showITTalepler) {
                    $itSql = "SELECT 
                        t.talep_id,
                        t.talep_no,
                        t.talep_baslik,
                        t.talep_aciklama,
                        k.kategori_adi,
                        k.kategori_ikon,
                        d.durum_adi,
                        d.durum_renk,
                        d.durum_ikon,
                        a.aciliyet_adi,
                        a.aciliyet_renk,
                        CONVERT(VARCHAR(19), t.talep_olusturma_tarihi, 120) as talep_olusturma_tarihi,
                        CONVERT(VARCHAR(19), t.talep_guncelleme_tarihi, 120) as talep_guncelleme_tarihi,
                        olusturan.kullanici_adi + ' ' + olusturan.kullanici_soyadi as olusturan_adi,
                        (SELECT COUNT(*) FROM IT_Talep_Yorumlar WHERE talep_id = t.talep_id) as yorum_sayisi,
                        (SELECT TOP 1 CONVERT(VARCHAR(19), yorum_olusturma_tarihi, 120) FROM IT_Talep_Yorumlar WHERE talep_id = t.talep_id ORDER BY yorum_olusturma_tarihi DESC) as son_yorum_tarihi
                    FROM IT_Talepler t
                    INNER JOIN IT_Talep_Kategoriler k ON t.talep_kategori_id = k.kategori_id
                    INNER JOIN IT_Talep_Durumlar d ON t.talep_durum_id = d.durum_id
                    INNER JOIN IT_Talep_Aciliyetler a ON t.talep_aciliyet_id = a.aciliyet_id
                    LEFT JOIN kullanicilar olusturan ON t.talep_olusturan_id = olusturan.kullanici_id
                    WHERE d.durum_adi NOT LIKE '%Tamamlan%' 
                      AND d.durum_adi NOT LIKE '%Kapan%' 
                      AND d.durum_adi NOT LIKE '%İptal%'";
                    $itParams = [];
                    
                    if (!$isAdmin) {
                        $grupPH = implode(',', array_fill(0, count($userGrupIds), '?'));
                        $itSql .= " AND (k.kategori_varsayilan_grup_id IN ($grupPH) OR t.talep_atanan_grup_id IN ($grupPH) OR t.talep_atanan_kullanici_id = ?)";
                        $itParams = array_merge($userGrupIds, $userGrupIds, [$userId]);
                    }
                    
                    // Arama filtresi
                    if ($search) {
                        $itSql .= " AND (t.talep_baslik LIKE ? OR t.talep_no LIKE ? OR t.talep_aciklama LIKE ?)";
                        $itParams[] = "%$search%";
                        $itParams[] = "%$search%";
                        $itParams[] = "%$search%";
                    }
                    
                    // Tarih filtreleri
                    if ($startDate) {
                        $itSql .= " AND CONVERT(date, t.talep_olusturma_tarihi) >= ?";
                        $itParams[] = $startDate;
                    }
                    if ($endDate) {
                        $itSql .= " AND CONVERT(date, t.talep_olusturma_tarihi) <= ?";
                        $itParams[] = $endDate;
                    }
                    
                    $itSql .= " ORDER BY ISNULL(t.talep_guncelleme_tarihi, t.talep_olusturma_tarihi) DESC";
                    
                    $itTalepler = $db->fetchAll($itSql, $itParams);
                    
                    // IT taleplerini bildirim formatına dönüştür
                    foreach ($itTalepler as $talep) {
                        $data[] = [
                            'bildirim_id' => 'it_' . $talep['talep_id'],
                            'bildirim_hedef_tip' => 'it_talep',
                            'bildirim_tip' => 'it_talep',
                            'bildirim_baslik' => '[' . $talep['talep_no'] . '] ' . $talep['talep_baslik'],
                            'bildirim_mesaj' => $talep['kategori_adi'] . ' • ' . $talep['durum_adi'] . ' • ' . $talep['aciliyet_adi'] . ($talep['olusturan_adi'] ? ' • ' . $talep['olusturan_adi'] : '') . ($talep['yorum_sayisi'] > 0 ? ' • ' . $talep['yorum_sayisi'] . ' yorum' : ''),
                            'bildirim_link' => '/admin/it-talep-detay?id=' . $talep['talep_id'],
                            'bildirim_icon' => $talep['kategori_ikon'] ?: 'bi-ticket-perforated',
                            'bildirim_renk' => $talep['durum_renk'] ? str_replace('#', '', $talep['durum_renk']) : 'info',
                            'bildirim_kaynak_tip' => 'it_talep',
                            'bildirim_kaynak_id' => $talep['talep_id'],
                            'okundu' => 0,
                            'okunma_tarihi' => null,
                            'bildirim_olusturma_tarihi' => $talep['son_yorum_tarihi'] ?? $talep['talep_guncelleme_tarihi'] ?? $talep['talep_olusturma_tarihi']
                        ];
                    }
                }
                
                // Tarihe göre sırala (en yeni üstte)
                usort($data, function($a, $b) {
                    return strcmp($b['bildirim_olusturma_tarihi'] ?? '', $a['bildirim_olusturma_tarihi'] ?? '');
                });
                
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'mark_read':
                // Tek bildirimi okundu işaretle
                $id = $_POST['id'] ?? 0;
                
                // Önce kayıt var mı kontrol et, yoksa oluştur
                $existing = $db->fetchOne("SELECT okuma_id FROM Bildirim_Okumalari WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
                
                if ($existing) {
                    $db->execute("UPDATE Bildirim_Okumalari SET okundu = 1, okunma_tarihi = GETDATE() WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
                } else {
                    $db->execute("INSERT INTO Bildirim_Okumalari (bildirim_id, kullanici_id, okundu, okunma_tarihi) VALUES (?, ?, 1, GETDATE())", [$id, $userId]);
                }
                echo json_encode(['success' => true, 'message' => 'Bildirim okundu olarak işaretlendi']);
                break;
                
            case 'mark_unread':
                // Tek bildirimi okunmadı işaretle
                $id = $_POST['id'] ?? 0;
                
                $existing = $db->fetchOne("SELECT okuma_id FROM Bildirim_Okumalari WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
                
                if ($existing) {
                    $db->execute("UPDATE Bildirim_Okumalari SET okundu = 0, okunma_tarihi = NULL WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
                }
                echo json_encode(['success' => true, 'message' => 'Bildirim okunmadı olarak işaretlendi']);
                break;
                
            case 'mark_all_read':
                // Tüm bildirimleri okundu işaretle
                // Önce kullanıcının görebildiği bildirimleri bul
                $bildirimler = $db->fetchAll("SELECT bildirim_id FROM vw_kullanici_bildirimleri WHERE kullanici_id = ? AND okundu = 0", [$userId]);
                
                foreach ($bildirimler as $b) {
                    $existing = $db->fetchOne("SELECT okuma_id FROM Bildirim_Okumalari WHERE bildirim_id = ? AND kullanici_id = ?", [$b['bildirim_id'], $userId]);
                    if ($existing) {
                        $db->execute("UPDATE Bildirim_Okumalari SET okundu = 1, okunma_tarihi = GETDATE() WHERE bildirim_id = ? AND kullanici_id = ?", [$b['bildirim_id'], $userId]);
                    } else {
                        $db->execute("INSERT INTO Bildirim_Okumalari (bildirim_id, kullanici_id, okundu, okunma_tarihi) VALUES (?, ?, 1, GETDATE())", [$b['bildirim_id'], $userId]);
                    }
                }
                echo json_encode(['success' => true, 'message' => 'Tüm bildirimler okundu olarak işaretlendi']);
                break;
                
            case 'delete':
                // Bildirimi sil (soft delete - sadece bu kullanıcı için)
                $id = $_POST['id'] ?? 0;
                
                $existing = $db->fetchOne("SELECT okuma_id FROM Bildirim_Okumalari WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
                
                if ($existing) {
                    $db->execute("UPDATE Bildirim_Okumalari SET silindi = 1, silinme_tarihi = GETDATE() WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
                } else {
                    $db->execute("INSERT INTO Bildirim_Okumalari (bildirim_id, kullanici_id, silindi, silinme_tarihi) VALUES (?, ?, 1, GETDATE())", [$id, $userId]);
                }
                echo json_encode(['success' => true, 'message' => 'Bildirim silindi']);
                break;
                
            case 'delete_all_read':
                // Tüm okunmuş bildirimleri sil
                $bildirimler = $db->fetchAll("SELECT bildirim_id FROM vw_kullanici_bildirimleri WHERE kullanici_id = ? AND okundu = 1", [$userId]);
                
                foreach ($bildirimler as $b) {
                    $existing = $db->fetchOne("SELECT okuma_id FROM Bildirim_Okumalari WHERE bildirim_id = ? AND kullanici_id = ?", [$b['bildirim_id'], $userId]);
                    if ($existing) {
                        $db->execute("UPDATE Bildirim_Okumalari SET silindi = 1, silinme_tarihi = GETDATE() WHERE bildirim_id = ? AND kullanici_id = ?", [$b['bildirim_id'], $userId]);
                    } else {
                        $db->execute("INSERT INTO Bildirim_Okumalari (bildirim_id, kullanici_id, silindi, silinme_tarihi) VALUES (?, ?, 1, GETDATE())", [$b['bildirim_id'], $userId]);
                    }
                }
                echo json_encode(['success' => true, 'message' => 'Okunmuş bildirimler silindi']);
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
        .notification-item {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .notification-item:hover {
            background-color: rgba(0,0,0,0.02);
        }
        .notification-item.unread {
            background-color: rgba(13, 110, 253, 0.05);
            border-left-color: #0d6efd;
        }
        .notification-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.25rem;
        }
        .notification-time {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .notification-actions {
            opacity: 0;
            transition: opacity 0.2s;
        }
        .notification-item:hover .notification-actions {
            opacity: 1;
        }
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
                        <div class="col-md-3 col-sm-6 col-12">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-bell"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Bildirim</span>
                                    <span class="info-box-number" id="stat_toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 col-12">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-bell-fill"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Okunmamış</span>
                                    <span class="info-box-number" id="stat_okunmamis">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 col-12">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check2-all"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Okunmuş</span>
                                    <span class="info-box-number" id="stat_okunmus">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 col-12">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-calendar-event"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün</span>
                                    <span class="info-box-number" id="stat_bugun">0</span>
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
                                            <label class="form-label">Durum</label>
                                            <select class="form-select" id="filter_okundu_durum">
                                                <option value="">Tümü</option>
                                                <option value="0">Okunmamış</option>
                                                <option value="1">Okunmuş</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Tip</label>
                                            <select class="form-select" id="filter_tip">
                                                <option value="">Tümü</option>
                                                <?php foreach ($bildirimTipleri as $key => $tip): ?>
                                                <option value="<?= $key ?>"><?= $tip['label'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Başlangıç</label>
                                            <input type="date" class="form-control" id="filter_start_date">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bitiş</label>
                                            <input type="date" class="form-control" id="filter_end_date">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Ara</label>
                                            <input type="text" class="form-control" id="filter_search" placeholder="Başlık veya mesaj...">
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
                    </div>
                    
                    <!-- Bildirimler Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-bell"></i> Bildirimler
                                <span class="badge bg-secondary ms-2" id="totalCount">0</span>
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-success" onclick="markAllRead()">
                                    <i class="bi bi-check2-all"></i> Tümünü Okundu İşaretle
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteAllRead()">
                                    <i class="bi bi-trash"></i> Okunanları Sil
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="bildirimListesi">
                                <!-- Bildirimler buraya yüklenecek -->
                            </div>
                            <div id="emptyState" class="text-center py-5 d-none">
                                <i class="bi bi-bell-slash text-muted" style="font-size: 4rem;"></i>
                                <p class="text-muted mt-3">Henüz bildiriminiz bulunmuyor.</p>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    // Bildirim tipleri (PHP'den)
    const bildirimTipleri = <?= json_encode($bildirimTipleri) ?>;
    
    // Sayfa yüklendiğinde
    $(document).ready(function() {
        loadStats();
        loadList();
        
        // Select2
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true
        });
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            loadList();
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_okundu_durum').val('').trigger('change.select2');
            $('#filter_tip').val('').trigger('change.select2');
            loadList();
        });
    });
    
    // İstatistikleri yükle
    function loadStats() {
        $.post('', { action: 'stats' }, function(response) {
            if (response.success) {
                $('#stat_toplam').text(response.data.toplam);
                $('#stat_okunmamis').text(response.data.okunmamis);
                $('#stat_okunmus').text(response.data.okunmus);
                $('#stat_bugun').text(response.data.bugun);
            }
        }, 'json');
    }
    
    // Bildirimleri yükle
    function loadList() {
        const filters = {
            action: 'list',
            okundu_durum: $('#filter_okundu_durum').val(),
            tip: $('#filter_tip').val(),
            search: $('#filter_search').val(),
            start_date: $('#filter_start_date').val(),
            end_date: $('#filter_end_date').val()
        };
        
        $.post('', filters, function(response) {
            if (response.success) {
                renderNotifications(response.data);
                $('#totalCount').text(response.count);
            }
        }, 'json');
    }
    
    // Bildirimleri render et
    function renderNotifications(data) {
        const container = $('#bildirimListesi');
        container.empty();
        
        if (data.length === 0) {
            $('#emptyState').removeClass('d-none');
            return;
        }
        
        $('#emptyState').addClass('d-none');
        
        data.forEach(function(item) {
            const isITTalep = item.bildirim_tip === 'it_talep';
            const tipInfo = bildirimTipleri[item.bildirim_tip] || { icon: 'bi-bell', renk: 'secondary', label: 'Bildirim' };
            const icon = item.bildirim_icon || tipInfo.icon;
            const renk = isITTalep ? 'purple' : (item.bildirim_renk || tipInfo.renk);
            const unreadClass = item.okundu == 0 ? 'unread' : '';
            const readIcon = item.okundu == 0 ? 'bi-envelope' : 'bi-envelope-open';
            const readAction = item.okundu == 0 ? 'markRead' : 'markUnread';
            const readTitle = item.okundu == 0 ? 'Okundu işaretle' : 'Okunmadı işaretle';
            
            // Hedef tipine göre badge
            let hedefBadge = '';
            switch (item.bildirim_hedef_tip) {
                case 'genel': hedefBadge = '<span class="badge bg-info ms-1">Genel</span>'; break;
                case 'departman': hedefBadge = '<span class="badge bg-primary ms-1">Departman</span>'; break;
                case 'firma': hedefBadge = '<span class="badge bg-success ms-1">Firma</span>'; break;
                case 'sube': hedefBadge = '<span class="badge bg-warning ms-1">Şube</span>'; break;

            }
            
            // IT talep için aksiyonlar farklı
            let actionsHtml = '';
            if (isITTalep) {
                actionsHtml = `
                    <a href="${item.bildirim_link}" class="btn btn-sm btn-outline-primary" title="Talebe Git">
                        <i class="bi bi-box-arrow-up-right"></i> Detay
                    </a>
                `;
            } else {
                actionsHtml = `
                    ${item.bildirim_link ? `<a href="${item.bildirim_link}" class="btn btn-sm btn-outline-primary me-1" title="Git"><i class="bi bi-box-arrow-up-right"></i></a>` : ''}
                    <button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="${readAction}(${item.bildirim_id})" title="${readTitle}">
                        <i class="bi ${readIcon}"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteNotification(${item.bildirim_id})" title="Sil">
                        <i class="bi bi-trash"></i>
                    </button>
                `;
            }
            
            const html = `
                <div class="notification-item ${unreadClass} d-flex align-items-start p-3 border-bottom" data-id="${item.bildirim_id}">
                    <div class="notification-icon bg-${renk} bg-opacity-10 text-${renk} me-3" style="${isITTalep ? 'background-color: rgba(111,66,193,0.1) !important; color: #6f42c1 !important;' : ''}">
                        <i class="bi ${icon}"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 ${item.okundu == 0 ? 'fw-bold' : ''}">${isITTalep ? item.bildirim_baslik : escapeHtml(item.bildirim_baslik)} ${hedefBadge}</h6>
                                ${item.bildirim_mesaj ? `<p class="mb-1 text-muted small">${escapeHtml(item.bildirim_mesaj)}</p>` : ''}
                                <div class="notification-time">
                                    <i class="bi bi-clock me-1"></i>${formatDate(item.bildirim_olusturma_tarihi)}
                                    <span class="badge bg-${renk} bg-opacity-10 text-${renk} ms-2" style="${isITTalep ? 'background-color: rgba(111,66,193,0.1) !important; color: #6f42c1 !important;' : ''}">${tipInfo.label}</span>
                                </div>
                            </div>
                            <div class="notification-actions">
                                ${actionsHtml}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.append(html);
        });
    }
    
    // Okundu işaretle
    function markRead(id) {
        $.post('', { action: 'mark_read', id: id }, function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                loadStats();
                loadList();
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    
    // Okunmadı işaretle
    function markUnread(id) {
        $.post('', { action: 'mark_unread', id: id }, function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                loadStats();
                loadList();
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    
    // Tümünü okundu işaretle
    function markAllRead() {
        confirmAction('Tüm bildirimleri okundu olarak işaretlemek istiyor musunuz?', null, function() {
            $.post('', { action: 'mark_all_read' }, function(response) {
                if (response.success) {
                    showSuccess('Başarılı!', response.message);
                    loadStats();
                    loadList();
                } else {
                    showError('Hata!', response.message);
                }
            });
        });
    }
    
    // Bildirimi sil
    function deleteNotification(id) {
        confirmAction('Bu bildirimi silmek istiyor musunuz?', null, function() {
            $.post('', { action: 'delete', id: id }, function(response) {
                if (response.success) {
                    showSuccess('Silindi!', response.message);
                    loadStats();
                    loadList();
                } else {
                    showError('Hata!', response.message);
                }
            });
        });
    }
    
    // Okunmuş bildirimleri sil
    function deleteAllRead() {
        confirmAction('Tüm okunmuş bildirimleri silmek istiyor musunuz?', 'Bu işlem geri alınamaz!', function() {
            $.post('', { action: 'delete_all_read' }, function(response) {
                if (response.success) {
                    showSuccess('Silindi!', response.message);
                    loadStats();
                    loadList();
                } else {
                    showError('Hata!', response.message);
                }
            });
        });
    }
    
    // Tarih formatlama
    function formatDate(dateString) {
        if (!dateString) return '-';
        try {
            const date = new Date(dateString.replace(' ', 'T'));
            if (isNaN(date.getTime())) return '-';
            
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);
            
            if (diffMins < 1) return 'Az önce';
            if (diffMins < 60) return diffMins + ' dakika önce';
            if (diffHours < 24) return diffHours + ' saat önce';
            if (diffDays < 7) return diffDays + ' gün önce';
            
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
    
    // HTML escape
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    </script>
</body>
</html>
