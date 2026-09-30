<?php
/**
 * Cron Worker — manuel/tekil tetik
 * Portal Örnek Soft
 *
 * Web:
 *   worker.php?gorev=KODU&key=KEY[&param=deger...]
 *   worker.php?zamanlama=ID&key=KEY
 * CLI (süre sınırı yok, çıktı canlı akar — uzun görevler için bunu kullanın):
 *   php worker.php gorev=KODU param=deger
 *   php worker.php zamanlama=ID
 */

set_time_limit(600);
ignore_user_abort(true);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/tasks.php';

header('Content-Type: text/plain; charset=utf-8');

$db = Database::getInstance();

if (!cronKeyDogrula($db)) {
    http_response_code(403);
    echo "Geçersiz anahtar\n";
    exit;
}

// Shutdown handler — web'den tetiklenip timeout'a düşen çalışma zombi log bırakmasın
$aktifLog = ['id' => null, 'bas' => null];

register_shutdown_function(function () use (&$aktifLog) {
    if (!$aktifLog['id']) return;

    $hata = error_get_last();
    $msg  = $hata && in_array($hata['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ? 'Fatal: ' . $hata['message']
        : 'Görev yarıda kesildi (zaman aşımı veya process sonlandırıldı).';

    try {
        cronLogBitir(Database::getInstance(), $aktifLog['id'], 2, $msg, $aktifLog['bas']);
    } catch (Throwable) {
        // shutdown sırasında DB bağlantısı da düşmüş olabilir
    }
});

// CLI argümanlarını $_GET'e çevir (gorev=x param=y)
if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $_GET[$k] = $v;
        }
    }
}

$gorevKodu   = trim($_GET['gorev'] ?? '');
$zamanlamaId = (int)($_GET['zamanlama'] ?? 0);

if ($gorevKodu === '' && $zamanlamaId <= 0) {
    echo "Kullanım: worker.php?gorev=KODU veya worker.php?zamanlama=ID\n";
    exit;
}

$params    = [];
$gorevId   = 0;
$maxSureSn = 600;

if ($zamanlamaId > 0) {
    // Zamanlama tetiği: parametreler DB'den
    $z = $db->fetchOne("
        SELECT z.CronZamanlamalar_GorevId, z.CronZamanlamalar_SabitParametreler,
               g.CronGorevler_GorevKodu, g.CronGorevler_MaxSureSn
        FROM dbo.CronZamanlamalar z
        INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
        WHERE z.CronZamanlamalar_Id = ?
    ", [$zamanlamaId]);

    if (!$z) {
        echo "Zamanlama bulunamadı: $zamanlamaId\n";
        exit;
    }

    $gorevId   = (int)$z['CronZamanlamalar_GorevId'];
    $gorevKodu = $z['CronGorevler_GorevKodu'];
    $maxSureSn = (int)$z['CronGorevler_MaxSureSn'];
    $params    = json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '', true) ?: [];
} else {
    // Görev tetiği: parametreler URL/CLI'dan (gorev+zamanlama+key hariç hepsi)
    $g = $db->fetchOne(
        "SELECT CronGorevler_Id, CronGorevler_MaxSureSn FROM dbo.CronGorevler WHERE CronGorevler_GorevKodu = ? AND Durum = 1",
        [$gorevKodu]
    );

    if (!$g) {
        echo "Görev bulunamadı: $gorevKodu\n";
        exit;
    }

    $gorevId   = (int)$g['CronGorevler_Id'];
    $maxSureSn = (int)$g['CronGorevler_MaxSureSn'];

    foreach ($_GET as $k => $v) {
        if (!in_array($k, ['gorev', 'zamanlama', 'key'], true)) {
            $params[$k] = $v;
        }
    }
}

// Overlap koruması: aynı görev çalışıyorken ikinci kez tetiklenmesin
if (cronCalisiyorMu($db, $gorevId, $maxSureSn)) {
    echo "Bu görev şu anda çalışıyor. Tetik atlandı.\n";
    exit;
}

$basZaman = microtime(true);
$logId    = cronLogOlustur($db, $gorevId, $zamanlamaId > 0 ? $zamanlamaId : null, $params, 1);

$aktifLog = ['id' => $logId, 'bas' => $basZaman];

// CLI'da süre sınırı kaldırılır; web'de görevin kendi limiti uygulanır
set_time_limit(PHP_SAPI === 'cli' ? 0 : $maxSureSn);

$sonuc = gorevCalistir($gorevKodu, $params, $db);

cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $basZaman, $sonuc['cikti'] ?? '');

$aktifLog = ['id' => null, 'bas' => null];

echo "Görev : $gorevKodu\n";
echo "Durum : " . ((int)$sonuc['durum'] === 1 ? 'BAŞARILI' : 'HATA') . "\n";
echo "Sonuç : {$sonuc['sonuc']}\n";
echo "Süre  : " . round(microtime(true) - $basZaman, 2) . " sn\n";
echo "----------------------------------------\n";
echo $sonuc['cikti'] ?? '';
