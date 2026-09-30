<?php
/**
 * Banka Hareketleri Dis API - v1
 * Router
 */

define('ROTA_GIRIS', true);

require_once __DIR__ . '/../../admin/db.php';
require_once __DIR__ . '/ApiLog.php';
require_once __DIR__ . '/Yanit.php';
require_once __DIR__ . '/Kimlik.php';

// Rota cozumlemesi: web.config rewrite ?rota= parametresini doldurur,
// dogrudan erisimde PATH_INFO kullanilir.
$rota = $_GET['rota'] ?? ltrim($_SERVER['PATH_INFO'] ?? '', '/');
$rota = trim(preg_replace('/[^a-zA-Z0-9\/_-]/', '', (string) $rota), '/');

ApiLog::baslat($rota !== '' ? '/' . $rota : '/');

set_exception_handler(function (Throwable $e) {
    error_log('API hatasi: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Yanit::hata(500, 'Sunucu hatasi');
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    Yanit::hata(405, 'Yalnizca GET metodu desteklenir');
}

$istemci = Kimlik::dogrula();

$parcalar = $rota === '' ? [] : explode('/', $rota);
$kaynak   = $parcalar[0] ?? '';

switch ($kaynak) {
    case 'firmalar':
        require __DIR__ . '/Uclar/Firmalar.php';
        break;

    case 'bankalar':
        require __DIR__ . '/Uclar/Bankalar.php';
        break;

    case 'hesaplar':
        require __DIR__ . '/Uclar/Hesaplar.php';
        break;

    case 'hareketler':
        require __DIR__ . '/Uclar/Hareketler.php';
        break;

    default:
        Yanit::hata(404, 'Bilinmeyen uc nokta',
            'Kullanilabilir: /firmalar, /bankalar, /hesaplar, /hareketler');
}
