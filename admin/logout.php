<?php
/**
 * Admin Panel - Çıkış İşlemi
 * Portal Örnek Soft
 */

require_once __DIR__ . '/auth.php';

// Çıkış işlemi
// Impersonate modundaysa oturum kapanmaz, orijinal hesaba dönülür
$cikisYapildi = Auth::logout();

if (!$cikisYapildi) {
    redirect('/admin/personel-yonetimi');
}

// Giriş sayfasına yönlendir
redirect('/admin/login.php');
