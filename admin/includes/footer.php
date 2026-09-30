<?php
/**
 * Admin Panel - Footer Component
 * Portal Örnek Soft
 */

// Veritabanından site ayarlarını çek
if (!isset($db)) {
    $db = Database::getInstance();
}

$footerYazi = '';
$siteAyarlari = null;

try {
    $siteAyarlari = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_footer_yazi 
        FROM dbo.tanim_site_ayarlari 
        ORDER BY site_ayarlari_id DESC
    ");
} catch (Exception $e) {
    error_log("Footer SQL hatası: " . $e->getMessage());
}

if ($siteAyarlari && !empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
    $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Soft Portal. Tüm hakları saklıdır.';
}

// En son versiyon numarasını çek
$sonVersiyon = null;
$versiyonNo = '1.0.0';

try {
    $sonVersiyon = $db->fetchOne("
        SELECT TOP 1 surum_versiyon 
        FROM Sistem_Surum_Notlari 
        ORDER BY surum_id DESC
    ");
    if ($sonVersiyon) {
        $versiyonNo = $sonVersiyon['surum_versiyon'];
    }
} catch (Exception $e) {
    error_log("Versiyon SQL hatası: " . $e->getMessage());
}
?>
<!--begin::Footer-->
<footer class="app-footer" style="display: block; visibility: visible; height: auto;">
    <div class="float-start">
        <strong><?= $footerYazi ?? '© 2026 Örnek Soft Portal' ?></strong>
    </div>
    <div class="float-end">
        <a href="/admin/surum-notlari" class="text-decoration-none" title="Sürüm notlarını görüntüle">
            <i class="bi bi-info-circle me-1"></i>Versiyon <?= htmlspecialchars($versiyonNo ?? '1.0.0') ?>
        </a>
    </div>
</footer>
<!--end::Footer-->

<?php 
$mevcutSayfa = basename($_SERVER['PHP_SELF']);
$haricSayfalar = ['destek.php', 'destek-detay.php', 'login.php', 'giris.php', 'kayit.php'];

if (isset($_SESSION['user_email']) && !in_array($mevcutSayfa, $haricSayfalar)) {
    include __DIR__ . '/destek-widget.php';
}
?>

<?php if (isset($hasNotificationAccess) && $hasNotificationAccess): ?>
<!-- Bildirim Sistemi -->
<script>
// jQuery yüklenene kadar bekle
(function checkJQuery() {
    if (typeof jQuery !== 'undefined') {
        initNotifications();
    } else {
        setTimeout(checkJQuery, 50);
    }
})();

function initNotifications() {
    // Bildirimleri yükle
    function loadNotifications() {
        $.get('/admin/api/notifications.php?action=get_notifications', function(response) {
            if (response.success) {
                const count = response.count || 0;
                const bildirimler = response.data || [];
                
                // Badge güncelle
                const badge = $('#notificationBadge');
                if (count > 0) {
                    badge.text(count > 9 ? '9+' : count).show();
                } else {
                    badge.hide();
                }
                
                // Header güncelle
                $('#notificationHeader').html(`<i class="bi bi-bell"></i> ${count} Bildirim`);
                
                // Liste güncelle
                const liste = $('#notificationList');
                liste.empty();
                
                if (bildirimler.length > 0) {
                    bildirimler.forEach(bildirim => {
                        const item = `
                            <a href="${bildirim.link}" class="dropdown-item">
                                <i class="bi ${bildirim.icon} me-2 text-${bildirim.renk}"></i>
                                <div class="d-inline-block text-truncate" style="max-width: 250px;">
                                    <strong>${bildirim.baslik}:</strong> ${bildirim.mesaj}
                                </div>
                                <span class="float-end text-muted text-sm">${bildirim.zaman}</span>
                            </a>
                            <div class="dropdown-divider"></div>
                        `;
                        liste.append(item);
                    });
                } else {
                    liste.html('<div class="dropdown-item text-center text-muted py-3">Yeni bildirim yok</div>');
                }
            }
        }).fail(function() {
            $('#notificationHeader').html('<i class="bi bi-bell"></i> Bildirimler yüklenemedi');
            $('#notificationList').html('<div class="dropdown-item text-center text-danger py-3">Hata oluştu</div>');
        });
    }
    
    // Sayfa yüklendiğinde bildirimleri yükle
    $(document).ready(function() {
        loadNotifications();
        
        // Her 60 saniyede bir güncelle
        setInterval(loadNotifications, 60000);
        
        // Dropdown açıldığında yenile
        $('#notificationDropdown').on('click', function() {
            loadNotifications();
        });
    });
}
</script>
<?php endif; ?>
<?php
// --- Oturum Sure Takibi ---
// Sure bitimine 30 sn kala uyari (uzatma / cikis), ayrica oturumu bitmis
// AJAX isteklerinin (401) yakalanmasi. Script kosulsuz yuklenir: kalan sure
// sifir olsa bile 401 yakalayicisinin devrede olmasi gerekir.
if (Auth::check()):
    $_oturumKalan = !empty($_SESSION['login_time'])
        ? oturumTimeoutSaniye() - (time() - $_SESSION['login_time'])
        : 0;
?>
<script>
window.OTURUM_TAKIP = {
    kalan: <?= max(0, (int)$_oturumKalan) ?>,
    omur:  <?= (int)oturumTimeoutSaniye() ?>
};
</script>
<script src="/admin/assets/js/oturum-takip.js?v=<?= @filemtime(__DIR__ . '/../assets/js/oturum-takip.js') ?>" defer></script>
<?php endif; ?>