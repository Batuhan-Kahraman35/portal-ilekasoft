<?php
/**
 * Faturam.net Abonelik Senkronizasyonu (Cron Job)
 *
 * cari_entegrasyon_kanali_id = Faturam.net kanalı olan carilere bağlı ve
 * abonelik_bilgi (abone/tesisat no) dolu olan abonelikleri sorgular;
 * abonelik_tl_tutar ve abonelik_son_odeme_tarihi alanlarını günceller.
 *
 * Her sorgu EntegrasyonLoglari tablosuna yazılır (FaturamClient tarafından).
 *
 * Kullanım:
 *  - Cron:  php admin/cron/faturam-senkron.php
 *  - Test:  php admin/cron/faturam-senkron.php --test         (güncelleme yapmaz)
 *  - Tekil: php admin/cron/faturam-senkron.php --id=42
 *  - Web:   /admin/cron/faturam-senkron.php?test
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// PHP CLI .user.ini okumaz; timezone burada sabitlenir.
date_default_timezone_set('Europe/Istanbul');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/FaturamClient.php';

$isCli        = php_sapi_name() === 'cli';
$isWebRequest = !$isCli && !defined('CRON_DISPATCH');

// tasks.php üzerinden include edildiğinde parametreler $_GET'e yazılır;
// doğrudan CLI çağrısında komut satırı seçenekleri okunur.
$dogrudanCli = $isCli && !defined('CRON_DISPATCH');
$argvOpts    = $dogrudanCli ? getopt('', ['test', 'id::']) : [];

$isTestMode  = $dogrudanCli ? isset($argvOpts['test']) : isset($_GET['test']);
$tekAbonelik = $dogrudanCli
    ? (isset($argvOpts['id']) ? (int) $argvOpts['id'] : 0)
    : (int) ($_GET['id'] ?? 0);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/faturam-senkron-' . date('Y-m') . '.log';
$output  = [];

function logMessage(string $message, string $type = 'INFO'): void
{
    global $logFile, $output, $isWebRequest;

    $timestamp = date('Y-m-d H:i:s');
    $logLine   = "[$timestamp] [$type] $message";

    file_put_contents($logFile, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    $output[] = ['type' => $type, 'message' => $message, 'time' => $timestamp];

    if (!$isWebRequest) {
        echo $logLine . PHP_EOL;
    }
}

$ozet = [
    'toplam'      => 0,
    'guncellenen' => 0,
    'degismeyen'  => 0,
    'hatali'      => 0,
];

logMessage('=== Faturam.net Abonelik Senkronizasyonu Başladı ===');
logMessage('Mod: ' . ($isTestMode ? 'TEST (güncelleme yapılmayacak)' : 'CANLI'));

try {
    $db = Database::getInstance();

    $client   = new FaturamClient();
    $kanalId  = $client->kanalId();
    logMessage("Faturam.net kanalı bulundu (kanal id: {$kanalId})");

    $sql = "
        SELECT a.abonelik_id,
               a.abonelik_bilgi,
               a.abonelik_tl_tutar,
               a.abonelik_son_odeme_tarihi,
               c.cari_adi,
               c.cari_entegrasyon_kod
        FROM Abonelikler a
        INNER JOIN Cari c ON a.abonelik_cari_id = c.cari_id
        WHERE c.cari_entegrasyon_kanali_id = ?
          AND c.cari_entegrasyon_kod IS NOT NULL
          AND c.cari_entegrasyon_kod <> ''
          AND a.abonelik_bilgi IS NOT NULL
          AND a.abonelik_bilgi <> ''
          AND a.abonelik_durum = 1
    ";
    $params = [$kanalId];

    if ($tekAbonelik > 0) {
        $sql .= " AND a.abonelik_id = ?";
        $params[] = $tekAbonelik;
    }

    $sql .= " ORDER BY a.abonelik_id";

    $abonelikler = $db->fetchAll($sql, $params);
    $ozet['toplam'] = count($abonelikler);

    logMessage("Senkronize edilecek abonelik sayısı: {$ozet['toplam']}");

    foreach ($abonelikler as $ab) {
        $abonelikId = (int) $ab['abonelik_id'];
        $etiket     = "#{$abonelikId} {$ab['cari_adi']} / {$ab['abonelik_bilgi']}";

        $sonuc = $client->sorgula(
            (string) $ab['cari_entegrasyon_kod'],
            (string) $ab['abonelik_bilgi'],
            $abonelikId
        );

        if (!$sonuc['basarili']) {
            $ozet['hatali']++;
            logMessage("{$etiket} → HATA: {$sonuc['mesaj']}", 'WARNING');
            continue;
        }

        if ($sonuc['fatura_sayisi'] === 0) {
            $ozet['degismeyen']++;
            logMessage("{$etiket} → Ödenmemiş fatura yok, güncelleme yapılmadı.");
            continue;
        }

        $yeniTutar = $sonuc['tutar'];
        $yeniTarih = $sonuc['son_odeme'];

        $eskiTutar = $ab['abonelik_tl_tutar'] !== null ? (float) $ab['abonelik_tl_tutar'] : null;
        $eskiTarih = $ab['abonelik_son_odeme_tarihi'] instanceof DateTime
            ? $ab['abonelik_son_odeme_tarihi']->format('Y-m-d')
            : null;

        $degisti = ($eskiTutar === null || abs($eskiTutar - (float) $yeniTutar) > 0.001)
                || ($eskiTarih !== $yeniTarih);

        $detay = sprintf(
            '%s → %s ₺ / %s (%d fatura)',
            $etiket,
            number_format((float) $yeniTutar, 2, ',', '.'),
            $yeniTarih ?? '-',
            $sonuc['fatura_sayisi']
        );

        if (!$degisti) {
            $ozet['degismeyen']++;
            logMessage($detay . ' — değişiklik yok');
            continue;
        }

        if ($isTestMode) {
            $ozet['guncellenen']++;
            logMessage($detay . ' — TEST modu, kaydedilmedi');
            continue;
        }

        $db->execute("
            UPDATE Abonelikler
            SET abonelik_tl_tutar          = ?,
                abonelik_son_odeme_tarihi  = ?,
                abonelik_guncelleme_tarihi = GETDATE()
            WHERE abonelik_id = ?
        ", [$yeniTutar, $yeniTarih, $abonelikId]);

        $ozet['guncellenen']++;
        logMessage($detay . ' — güncellendi');
    }

    logMessage(sprintf(
        'Özet: toplam %d | güncellenen %d | değişmeyen %d | hatalı %d',
        $ozet['toplam'],
        $ozet['guncellenen'],
        $ozet['degismeyen'],
        $ozet['hatali']
    ));
    logMessage('=== Senkronizasyon Tamamlandı ===');

} catch (Throwable $e) {
    logMessage('KRİTİK HATA: ' . $e->getMessage(), 'ERROR');
}

if ($isWebRequest) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'test'    => $isTestMode,
        'ozet'    => $ozet,
        'log'     => $output,
    ], JSON_UNESCAPED_UNICODE);
}
