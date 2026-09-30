<?php
/**
 * Admin Panel - Default Dashboard
 * Departmana özel dashboard tanımlanmamışsa bu sayfa gösterilir
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 */

// $user ve $db değişkenleri anasayfa.php'den geliyor

// Site ayarlarını çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Basit istatistikler (hızlı sorgular)
$stats = [
    'users' => $db->fetchOne("SELECT COUNT(*) as total FROM kullanicilar WHERE kullanici_durum = 1")['total'] ?? 0,
    'total_users' => $db->fetchOne("SELECT COUNT(*) as total FROM kullanicilar")['total'] ?? 0,
];

// Dashboard'da gösterilecek sayfaları çek (yetki kontrolü ile)
$userDepartmanId = $user['departman_id'] ?? null;
$isAdmin = ($userDepartmanId == 1);

if ($isAdmin) {
    // Admin: Tüm dashboard sayfalarını göster
    $dashboardPages = $db->fetchAll("
        SELECT 
            s.sayfalar_id,
            s.sayfalar_sayfa_adi,
            s.sayfalar_sayfa_url,
            s.sayfalar_ikon,
            s.sayfalar_aciklama,
            m.menuler_menu_adi,
            m.menuler_ikon
        FROM tanim_sayfalar s
        LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
        WHERE s.sayfalar_dashboard = 1 AND s.sayfalar_durum = 1
        ORDER BY s.sayfalar_sira_no, s.sayfalar_sayfa_adi
    ");
} else {
    // Normal kullanıcı: Yetkili olduğu dashboard sayfalarını göster
    $dashboardPages = $db->fetchAll("
        SELECT 
            s.sayfalar_id,
            s.sayfalar_sayfa_adi,
            s.sayfalar_sayfa_url,
            s.sayfalar_ikon,
            s.sayfalar_aciklama,
            m.menuler_menu_adi,
            m.menuler_ikon
        FROM tanim_sayfalar s
        LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
        INNER JOIN menu_sayfa_yetkiler msy ON s.sayfalar_id = msy.sayfa_id
        WHERE s.sayfalar_dashboard = 1 
          AND s.sayfalar_durum = 1
          AND msy.departman_id = ?
          AND msy.durum = 1
          AND msy.gor = 1
        ORDER BY s.sayfalar_sira_no, s.sayfalar_sayfa_adi
    ", [$userDepartmanId]);
}

// Kart renkleri (döngüsel kullanım için)
$cardColors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];
$cardIcons = ['bi-folder', 'bi-bar-chart', 'bi-gear', 'bi-file-earmark-text', 'bi-people', 'bi-clipboard-data'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Sayfa - <?= htmlspecialchars($siteTitle) ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <style>
        .dashboard-quick-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .dashboard-quick-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
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
                            <h3 class="mb-0">Ana Sayfa</h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item active" aria-current="page">Ana Sayfa</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    <!-- Welcome Card -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                <div class="card-body">
                                    <h4 class="card-title mb-2">Hoş Geldiniz, <?= htmlspecialchars($user['name']) ?>!</h4>
                                    <p class="card-text mb-0">Yönetim paneline başarıyla giriş yaptınız.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="row">
                        <div class="col-lg-4 col-6">
                            <div class="small-box text-bg-primary">
                                <div class="inner">
                                    <h3><?= $stats['users'] ?></h3>
                                    <p>Aktif Kullanıcılar</p>
                                </div>
                                <div class="small-box-icon">
                                    <i class="bi bi-person-check-fill"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 col-6">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3><?= $stats['total_users'] ?></h3>
                                    <p>Toplam Kullanıcılar</p>
                                </div>
                                <div class="small-box-icon">
                                    <i class="bi bi-people-fill"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4 col-6">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3><i class="bi bi-check-circle-fill"></i></h3>
                                    <p>Sistem Durumu</p>
                                </div>
                                <div class="small-box-icon">
                                    <i class="bi bi-activity"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php include __DIR__ . '/../includes/dogum-gunu-karti.php'; ?>

                    <?php if (!empty($dashboardPages)): ?>
                    <!-- Hızlı Erişim Kartları -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <h5 class="mb-3"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Hızlı Erişim</h5>
                        </div>
                    </div>
                    <div class="row">
                        <?php foreach ($dashboardPages as $index => $page): 
                            $color = $cardColors[$index % count($cardColors)];
                            // Sayfa ikonu varsa kullan, yoksa menü ikonu, o da yoksa varsayılan
                            $icon = $page['sayfalar_ikon'] ?: ($page['menuler_ikon'] ?: $cardIcons[$index % count($cardIcons)]);
                            // .php uzantısını kaldır. Adres mutlak olmalı: bu dosya
                            // /admin/anasayfa üzerinden include edildiği için göreli
                            // bir bağlantı sayfanın açıldığı yola göre kayıyordu.
                            $pageUrl = '/admin/' . ltrim(str_replace('.php', '', $page['sayfalar_sayfa_url']), '/');
                        ?>
                        <div class="col-lg-3 col-md-4 col-sm-6 col-12 mb-3">
                            <a href="<?= htmlspecialchars($pageUrl) ?>" class="text-decoration-none">
                                <div class="card border-<?= $color ?> h-100 dashboard-quick-card">
                                    <div class="card-body text-center py-4">
                                        <div class="mb-3">
                                            <i class="<?= htmlspecialchars($icon) ?> text-<?= $color ?>" style="font-size: 2.5rem;"></i>
                                        </div>
                                        <h6 class="card-title mb-1 text-dark"><?= htmlspecialchars($page['sayfalar_sayfa_adi']) ?></h6>
                                        <?php if ($page['menuler_menu_adi']): ?>
                                        <small class="text-muted"><?= htmlspecialchars($page['menuler_menu_adi']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        
        <!-- jQuery footer'dan önce yüklenmeli (bildirim sistemi için) -->
        <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" crossorigin="anonymous"></script>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
</body>
</html>
