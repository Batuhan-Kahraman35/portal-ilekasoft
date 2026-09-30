<?php
/**
 * Cron Runner — tek giriş noktası
 * Portal Örnek Soft
 *
 * Plesk Cron her dakika çağırır (CLI önerilir):
 *   "C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe" "D:\Inetpub\vhosts\ornekfirma.com\portal.ornekfirma.com\admin\cron\runner.php"
 *   veya web: https://portal.ornekfirma.com/admin/cron/runner.php?key=KEY
 *
 * Akış:
 *   1. Aktif zamanlamaları çek (görev aktif + tarih aralığında)
 *   2. cronEslesiyor() → eşleşme yoksa ve TelafiEt=1 ise cronKacirilanTetik()
 *   3. SonCalisma dakika kontrolü (çift tetik kilidi)
 *   4. cronCalisiyorMu() → overlap koruması (SonCalisma güncellemesinden ÖNCE)
 *   5. Kilidi al → log aç → görevi çalıştır → log kapat
 */

set_time_limit(600);
ignore_user_abort(true);          // web'den tetiklenip bağlantı koparsa görev devam etsin
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/tasks.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();

if (!cronKeyDogrula($db)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Geçersiz anahtar']);
    exit;
}

/**
 * Shutdown handler — zombiyi doğuran anda yakala.
 * set_time_limit aşımı ve fatal error yakalanabilir bir Throwable üretmez;
 * cronLogBitir() hiç çağrılmaz ve log CalismaDurum=0'da asılı kalır.
 */
$aktifLog = ['id' => null, 'bas' => null, 'ad' => null];

register_shutdown_function(function () use (&$aktifLog) {
    if (!$aktifLog['id']) return;   // açık log yok, normal çıkış

    $hata = error_get_last();
    $msg  = $hata && in_array($hata['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ? 'Fatal: ' . $hata['message']
        : 'Görev yarıda kesildi (zaman aşımı veya process sonlandırıldı).';

    try {
        cronLogBitir(Database::getInstance(), $aktifLog['id'], 2, $msg, $aktifLog['bas']);
    } catch (Throwable) {
        // shutdown sırasında DB bağlantısı da düşmüş olabilir; log kaybını sessiz geç
    }
});

$simdi           = new DateTime();
$dakikaBaslangic = $simdi->format('Y-m-d H:i:00');

// 1) Aktif zamanlamalar
// SonCalismaMetin: sqlsrv DATETIME'ı nesne döndürür; 120 formatı (YYYY-MM-DD HH:MM:SS)
// leksikografik sıralamada kronolojik sırayla örtüştüğü için string karşılaştırması güvenli.
$zamanlamalar = $db->fetchAll("
    SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_GorevId, z.CronZamanlamalar_Ad,
           z.CronZamanlamalar_CronIfadesi, z.CronZamanlamalar_SabitParametreler,
           z.CronZamanlamalar_TelafiEt, z.CronZamanlamalar_TelafiSaatSiniri,
           CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120) AS SonCalismaMetin,
           g.CronGorevler_GorevKodu, g.CronGorevler_Ad AS GorevAdi, g.CronGorevler_MaxSureSn
    FROM dbo.CronZamanlamalar z
    INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
    WHERE z.Durum = 1
      AND g.Durum = 1
      AND z.CronZamanlamalar_BaslangicTarihi <= GETDATE()
      AND (z.CronZamanlamalar_BitisTarihi IS NULL OR z.CronZamanlamalar_BitisTarihi >= GETDATE())
");

$ozet = [];

foreach ($zamanlamalar as $z) {
    $ifade     = $z['CronZamanlamalar_CronIfadesi'];
    $maxSureSn = (int)$z['CronGorevler_MaxSureSn'];

    // 2) Normal eşleşme, yoksa telafi kontrolü
    $tetik  = cronEslesiyor($ifade, $simdi);
    $telafi = false;

    if (!$tetik && (int)$z['CronZamanlamalar_TelafiEt'] === 1) {
        $telafi = cronKacirilanTetik(
            $ifade,
            $z['SonCalismaMetin'],
            (int)$z['CronZamanlamalar_TelafiSaatSiniri'],
            $simdi
        );
        $tetik = $telafi;
    }

    if (!$tetik) continue;

    // 3) Çift tetik kilidi: bu dakika zaten çalıştıysa atla
    if (!empty($z['SonCalismaMetin']) && $z['SonCalismaMetin'] >= $dakikaBaslangic) {
        continue;
    }

    // 4) Overlap koruması — SonCalisma güncellemesinden ÖNCE
    if (cronCalisiyorMu($db, (int)$z['CronZamanlamalar_GorevId'], $maxSureSn)) {
        $ozet[] = ['zamanlama' => $z['CronZamanlamalar_Ad'], 'durum' => 'atlandi (calisiyor)'];
        continue;
    }

    // 5) Kilidi al ve çalıştır
    $db->update('CronZamanlamalar', [
        'CronZamanlamalar_SonCalisma' => date('Y-m-d H:i:s'),
        'GuncelleyenKullanici'        => 0,
        'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
    ], ['CronZamanlamalar_Id' => (int)$z['CronZamanlamalar_Id']]);

    $params   = json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '', true) ?: [];
    $basZaman = microtime(true);
    $logId    = cronLogOlustur($db, (int)$z['CronZamanlamalar_GorevId'], (int)$z['CronZamanlamalar_Id'], $params, 1);

    $aktifLog = ['id' => $logId, 'bas' => $basZaman, 'ad' => $z['CronZamanlamalar_Ad']];

    // Runner sıralı çalışır; her görevde sayaç sıfırlanmalı, aksi halde
    // turun sonundaki görevler öncekilerin harcadığı süre yüzünden timeout olur.
    set_time_limit($maxSureSn);

    $sonuc = gorevCalistir($z['CronGorevler_GorevKodu'], $params, $db);
    $onek  = $telafi ? '[TELAFI] ' : '';

    cronLogBitir($db, $logId, (int)$sonuc['durum'], $onek . $sonuc['sonuc'], $basZaman, $sonuc['cikti'] ?? '');

    $aktifLog = ['id' => null, 'bas' => null, 'ad' => null];   // ZORUNLU: handler son görevi ezmesin

    $ozet[] = [
        'zamanlama' => $z['CronZamanlamalar_Ad'],
        'gorev'     => $z['CronGorevler_GorevKodu'],
        'durum'     => (int)$sonuc['durum'] === 1 ? 'basarili' : 'hata',
        'telafi'    => $telafi,
        'sonuc'     => $sonuc['sonuc'],
    ];
}

echo json_encode([
    'success'  => true,
    'zaman'    => $simdi->format('Y-m-d H:i:s'),
    'kontrol'  => count($zamanlamalar),
    'calisan'  => count($ozet),
    'gorevler' => $ozet,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
