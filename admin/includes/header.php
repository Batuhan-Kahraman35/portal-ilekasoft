<?php
/**
 * Admin Panel - Header Component
 * Portal Örnek Soft
 */

// $user değişkeni ana sayfada tanımlanmalı
if (!isset($user)) {
    die('Header component requires $user variable to be set.');
}

// Bildirim çanı herkese açık - herkes kendi bildirimlerini görebilir
$hasNotificationAccess = isset($user['kullanici_id']);

$isAdmin = ($user['departman_id'] ?? 0) == 1;

if ($isAdmin && isset($db)) {
    $headerTree = [];
    
    // Header'da gösterilmesi istenen TÜM menüleri (kendisi veya parent'ı header_goster=1 olanlar) çek
    $allHeaderMenusRaw = $db->fetchAll("
        SELECT 
            m.menuler_id, m.menuler_menu_adi, m.menuler_ikon, m.menuler_parent_id, m.menuler_header_goster
        FROM tanim_menuler m
        WHERE m.menuler_durum = 1 AND (
            m.menuler_header_goster = 1 
            OR m.menuler_parent_id IN (SELECT menuler_id FROM tanim_menuler WHERE menuler_durum = 1 AND menuler_header_goster = 1)
        )
        ORDER BY ISNULL(m.menuler_parent_id, 0), m.menuler_sira_no
    ");
    
    if (!empty($allHeaderMenusRaw)) {
        $rawIds = array_column($allHeaderMenusRaw, 'menuler_id');
        
        // Root elementleri bul
        foreach ($allHeaderMenusRaw as $m) {
            $m['pages'] = [];
            $m['children'] = [];
            
            if ($m['menuler_parent_id'] === null || !in_array($m['menuler_parent_id'], $rawIds)) {
                $headerTree[$m['menuler_id']] = $m;
            }
        }
        
        // Alt menüleri eşleştir
        foreach ($allHeaderMenusRaw as $m) {
            if ($m['menuler_parent_id'] !== null && isset($headerTree[$m['menuler_parent_id']])) {
                $m['pages'] = [];
                $headerTree[$m['menuler_parent_id']]['children'][] = $m;
            }
        }
        
        // Sayfaları çek ve ağaca ekle
        $placeholders = implode(',', array_fill(0, count($rawIds), '?'));
        $pages = $db->fetchAll("
            SELECT 
                sayfalar_menu_id, sayfalar_sayfa_adi, sayfalar_sayfa_url, sayfalar_ikon
            FROM tanim_sayfalar
            WHERE sayfalar_durum = 1 AND sayfalar_menu_id IN ($placeholders)
            ORDER BY sayfalar_sira_no
        ", $rawIds);
        
        foreach ($pages as $p) {
            $mId = $p['sayfalar_menu_id'];
            if (isset($headerTree[$mId])) {
                $headerTree[$mId]['pages'][] = $p;
            } else {
                foreach ($headerTree as $pId => &$pMenu) {
                    foreach ($pMenu['children'] as $cIdx => &$cMenu) {
                        if ($cMenu['menuler_id'] == $mId) {
                            $headerTree[$pId]['children'][$cIdx]['pages'][] = $p;
                            break 2;
                        }
                    }
                }
                unset($pMenu, $cMenu);
            }
        }
    }
}
?>
<!--begin::Header-->
<nav class="app-header navbar navbar-expand bg-body">
    <!--begin::Container-->
    <div class="container-fluid">
        <!--begin::Start Navbar Links-->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block">
                <a href="/admin/anasayfa" class="nav-link">Ana Sayfa</a>
            </li>
            <?php if ($isAdmin && !empty($headerTree)): ?>
                <?php foreach ($headerTree as $hMenu): ?>
                <li class="nav-item dropdown d-none d-md-block">
                    <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
                        <?php if($hMenu['menuler_ikon']): ?><i class="<?= htmlspecialchars($hMenu['menuler_ikon']) ?> me-1"></i><?php endif; ?>
                        <?= htmlspecialchars($hMenu['menuler_menu_adi']) ?>
                    </a>
                    <ul class="dropdown-menu shadow">
                        <?php $hasAnyItems = false; ?>
                        
                        <!-- Ana Menü Sayfaları -->
                        <?php foreach ($hMenu['pages'] as $hPage): ?>
                            <?php 
                            $cleanUrl = str_replace('.php', '', $hPage['sayfalar_sayfa_url']);
                            $pageUrl = '/admin/' . $cleanUrl;
                            $hasAnyItems = true;
                            ?>
                            <li><a class="dropdown-item" href="<?= htmlspecialchars($pageUrl) ?>">
                                <?php if($hPage['sayfalar_ikon']): ?><i class="<?= htmlspecialchars($hPage['sayfalar_ikon']) ?> me-2"></i><?php endif; ?>
                                <?= htmlspecialchars($hPage['sayfalar_sayfa_adi']) ?>
                            </a></li>
                        <?php endforeach; ?>
                        
                        <!-- Alt Menü Sayfaları (düz liste - standart görünüm) -->
                        <?php foreach ($hMenu['children'] as $childMenu): ?>
                            <?php foreach ($childMenu['pages'] as $cPage): ?>
                                <?php 
                                $cleanUrl = str_replace('.php', '', $cPage['sayfalar_sayfa_url']);
                                $pageUrl = '/admin/' . $cleanUrl;
                                $hasAnyItems = true;
                                ?>
                                <li><a class="dropdown-item" href="<?= htmlspecialchars($pageUrl) ?>">
                                    <?php if($cPage['sayfalar_ikon']): ?><i class="<?= htmlspecialchars($cPage['sayfalar_ikon']) ?> me-2"></i><?php endif; ?>
                                    <?= htmlspecialchars($cPage['sayfalar_sayfa_adi']) ?>
                                </a></li>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        
                        <?php if (!$hasAnyItems): ?>
                            <li><span class="dropdown-item text-muted">Sayfa yok</span></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
        <!--end::Start Navbar Links-->
        
        <!--begin::End Navbar Links-->
        <ul class="navbar-nav ms-auto">
            <?php if ($hasNotificationAccess): ?>
            <!--begin::Notifications Dropdown Menu-->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" id="notificationDropdown">
                    <i class="bi bi-bell"></i>
                    <span class="navbar-badge badge text-bg-warning" id="notificationBadge" style="display: none;">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" id="notificationMenu">
                    <span class="dropdown-item dropdown-header" id="notificationHeader">
                        <i class="bi bi-bell"></i> Bildirimler yükleniyor...
                    </span>
                    <div class="dropdown-divider"></div>
                    <div id="notificationList">
                        <!-- Bildirimler buraya gelecek -->
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="/admin/bildirim-yonetimi" class="dropdown-item dropdown-footer">
                        Tüm Bildirimleri Gör
                    </a>
                </div>
            </li>
            <!--end::Notifications Dropdown Menu-->
            <?php endif; ?>
            
            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
                <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                    <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                    <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none"></i>
                </a>
            </li>
            <!--end::Fullscreen Toggle-->
            
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img
                        src="/admin/assets/images/user-avatar.png"
                        class="user-image rounded-circle shadow"
                        alt="<?= htmlspecialchars($user['name']) ?>"
                        onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23667eea%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23fff%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                    />
                    <span class="d-none d-md-inline"><?= htmlspecialchars($user['name']) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <!--begin::User Image-->
                    <li class="user-header text-bg-primary">
                        <img
                            src="/admin/assets/images/user-avatar.png"
                            class="rounded-circle shadow"
                            alt="<?= htmlspecialchars($user['name']) ?>"
                            onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23ffffff%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23667eea%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                        />
                        <p>
                            <?= htmlspecialchars($user['name']) ?>
                            <small><?= htmlspecialchars($user['email']) ?></small>
                        </p>
                    </li>
                    <!--end::User Image-->
                    
                    <!--begin::Menu Body-->
                    <li class="user-body">
                        <div class="row">
                            <div class="col-12 text-center">
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i> Son giriş: <?= date('d.m.Y H:i:s', $_SESSION['login_time']) ?>
                                </small>
                            </div>
                        </div>
                    </li>
                    <!--end::Menu Body-->

                    <?php if (Auth::isImpersonating() && ($impersonator = Auth::impersonator())): ?>
                    <!--begin::Impersonate Bildirimi-->
                    <li style="background:#fff3e0; border-top:1px solid #ffe0b2; padding:8px 12px;">
                        <div style="font-size:0.78rem; color:#e65100; line-height:1.4;">
                            <i class="bi bi-person-fill-badge"></i>
                            <strong><?= htmlspecialchars($user['name']) ?></strong> olarak giriş yapılmış durumdasınız.<br>
                            <span class="text-muted">
                                Orijinal: <strong><?= htmlspecialchars($impersonator['user_name']) ?></strong>
                            </span>
                        </div>
                        <a href="/admin/impersonate-cikis.php" class="btn btn-sm btn-warning w-100 mt-2" style="font-size:0.8rem;">
                            <i class="bi bi-box-arrow-left"></i> Kendi Hesabıma Dön
                        </a>
                    </li>
                    <!--end::Impersonate Bildirimi-->
                    <?php endif; ?>

                    <!--begin::Menu Footer-->
                    <li class="user-footer">
                        <a href="/admin/profil" class="btn btn-default btn-flat">Profil</a>
                        <a href="/admin/logout.php" class="btn btn-default btn-flat float-end">Çıkış Yap</a>
                    </li>
                    <!--end::Menu Footer-->
                </ul>
            </li>
            <!--end::User Menu Dropdown-->
        </ul>
        <!--end::End Navbar Links-->
    </div>
    <!--end::Container-->
</nav>
<!--end::Header-->
