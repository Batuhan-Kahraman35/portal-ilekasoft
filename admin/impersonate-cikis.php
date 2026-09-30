<?php
/**
 * Admin Panel - Impersonate Çıkışı
 * Orijinal (admin) hesaba geri döner.
 * Portal Örnek Soft
 */

require_once __DIR__ . '/auth.php';
requireAuth();

if (!Auth::isImpersonating()) {
    redirect('/admin/anasayfa');
}

Auth::stopImpersonate();

redirect('/admin/personel-yonetimi');
