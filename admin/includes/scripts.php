<?php
/**
 * Admin Panel - Ortak Çekirdek JS
 * Portal Örnek Soft
 *
 * jQuery, Popper, Bootstrap ve AdminLTE tek noktadan yüklenir.
 * Sayfalarda bu dosyalar ayrıca eklenmemeli: AdminLTE iki kez yüklenirse
 * sidebar menüsü tıklamada açılıp hemen kapanır, Bootstrap iki kez
 * yüklenirse dropdown'lar aynı şekilde bozulur.
 */

// Aynı sayfada ikinci kez dahil edilirse hiçbir şey basma
if (defined('ADMIN_CEKIRDEK_JS')) {
    return;
}
define('ADMIN_CEKIRDEK_JS', true);
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js?v=<?= @filemtime(__DIR__ . '/../assets/js/adminlte.min.js') ?>"></script>
