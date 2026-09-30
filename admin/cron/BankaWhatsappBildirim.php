<?php
/**
 * Banka WhatsApp Bildirim Cron
 * 
 * Yeni alacak kayıtlarını WhatsApp grubuna bildirir
 * 
 * Kullanım:
 * - Plesk PHP Script: httpdocs/admin/cron/BankaWhatsappBildirim.php
 * - CLI: php BankaWhatsappBildirim.php --minutes=1500
 * - Manuel: https://portal.ornekfirma.com/admin/cron/BankaWhatsappBildirim.php?key=SECRET_KEY
 * 
 * @author ÖRNEK Soft
 * @version 1.1
 */

// Hata raporlama
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Zaman limiti (2 dakika)
set_time_limit(120);

// Güvenlik anahtarı
define('CRON_SECRET_KEY', 'degistir_cron_anahtari_2');

// Root path
define('ROOT_PATH', dirname(dirname(__DIR__)));

// WhatsApp Grup ID - "Banka Hesap Hareketleri" grubu
// Not: Bu ID'yi WhatsApp grubundan almanız gerekir
define('WHATSAPP_GRUP_ID', '120000000000000000@g.us');

// Kaç dakika geriye bakılacak (5dk cron + 5dk tolerans = güvenli örtüşme)
define('WINDOW_MINUTES', 10);

// Dry-run modu (test için - mesaj göndermez)
$DRY_RUN = false;

// Gerekli dosyaları yükle
require_once ROOT_PATH . '/admin/db.php';
require_once ROOT_PATH . '/admin/includes/EvolutionAPI.php';

/**
 * Log dosyasına yaz
 */
function writeLog(string $message, string $level = 'INFO'): void
{
    $logDir = ROOT_PATH . '/admin/cron/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/banka-whatsapp-' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * JSON çıktı (web için)
 */
function jsonResponse(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Daha önce bildirim gönderilmiş mi kontrol et
 * EntegrasyonLoglari tablosunda aynı referans ile başarılı mesaj var mı?
 *
 * Not: Eski WhatsApp_Log kayıtları da bu tabloya taşındığı için
 * göç öncesi gönderimler de burada görünür.
 */
function bildirimGonderilmisMi(int $hareketId): bool
{
    $db = Database::getInstance();

    // Referans formatı: BANKA-HAREKET-{ID}
    $referans = "BANKA-HAREKET-$hareketId";

    $mevcut = $db->fetchOne("
        SELECT TOP 1 EntegrasyonLoglari_id
        FROM EntegrasyonLoglari
        WHERE EntegrasyonLoglari_IslemTipi LIKE 'WHATSAPP[_]%'
          AND EntegrasyonLoglari_BasariliMi = 1
          AND EntegrasyonLoglari_Istek LIKE ?
    ", ["%$referans%"]);

    return $mevcut !== null;
}

/**
 * WhatsApp bildirimi gönder
 */
function sendWhatsAppNotification(array $hareket): bool
{
    try {
        $db = Database::getInstance();
        
        // Varsayılan aktif WhatsApp kanalını al
        // (Entegrasyonlar / EntegrasyonKanallari yapısı — eski WhatsApp_Instances tablosu değil)
        $kanal = EvolutionAPI::varsayilanKanal();

        if (!$kanal) {
            writeLog('Varsayılan aktif WhatsApp kanalı bulunamadı! Entegrasyon Yönetimi sayfasını kontrol edin.', 'ERROR');
            return false;
        }
        
        // Daha önce gönderilmiş mi kontrol et
        $hareketId = (int)$hareket['banka_HesapHareketleriID'];
        if (bildirimGonderilmisMi($hareketId)) {
            writeLog("Hareket ID $hareketId için bildirim zaten gönderilmiş, atlanıyor.");
            return false;
        }
        
        // Tutarı formatla
        $tutar = number_format((float)$hareket['banka_HesapHareketleriAmount'], 2, ',', '.');
        $paraBirimi = $hareket['banka_HesapHareketleriCurrencyType'] ?? 'TRY';
        
        // Tarihi formatla
        $tarih = $hareket['banka_HesapHareketleriDateTime'] ?? '';
        if ($tarih) {
            $tarih = date('d.m.Y H:i', strtotime($tarih));
        }
        
        // IBAN'ı maskele (güvenlik için son 4 hane göster)
        $iban = $hareket['banka_HesapHareketleriIBAN'] ?? '';
        if (strlen($iban) > 8) {
            $iban = substr($iban, 0, 4) . '****' . substr($iban, -4);
        }
        
        // Gönderen adı
        $gonderenAdi = trim((string)($hareket['banka_HesapHareketleriName'] ?? ''));
        if ($gonderenAdi === '' || $gonderenAdi === '-') {
            $aciklama = trim((string)($hareket['banka_HesapHareketleriDescription'] ?? ''));
            $gonderenAdi = $aciklama !== '' ? mb_substr($aciklama, 0, 40) : 'Bilinmeyen';
        }
        
        // Referans (duplicate kontrolü için)
        $referans = "BANKA-HAREKET-$hareketId";
        
        // Banka adı
        $bankaAdi = $hareket['banka_adi'] ?? 'Bilinmeyen Banka';

        // Firma adı
        $firmaAdi = trim((string)($hareket['firma_adi'] ?? ''));
        
        // Hesap IBAN
        $hesapIban = trim((string)($hareket['hesap_iban'] ?? ''));

        // Mesaj oluştur
        $mesaj = "💰 *YENİ ÖDEME ALINDI*\n\n";
        $mesaj .= "🏛️ *Banka:* $bankaAdi\n";
        if ($firmaAdi) {
            $mesaj .= "🏢 *Firma:* $firmaAdi\n";
        }
        if ($hesapIban) {
            $mesaj .= "🔢 *Hesap IBAN:* $hesapIban\n";
        }
        $mesaj .= "👤 *Gönderen:* $gonderenAdi\n";
        $mesaj .= "💵 *Tutar:* $tutar $paraBirimi\n";
        if ($iban) {
            $mesaj .= "🏦 *IBAN:* $iban\n";
        }
        $vknTc = trim((string)($hareket['VknOrTc'] ?? ''));
        if ($vknTc) {
            $mesaj .= "🆔 *VKN/TC:* $vknTc\n";
        }
        $aciklama = trim((string)($hareket['banka_HesapHareketleriDescription'] ?? ''));
        if ($aciklama && $aciklama !== '-') {
            $mesaj .= "📝 *Açıklama:* $aciklama\n";
        }
        $mesaj .= "⏰ *Tarih:* $tarih\n";
        $dekontYolu = trim((string)($hareket['banka_HesapHareketleriDekontYolu'] ?? ''));
        if ($dekontYolu) {
            $mesaj .= "📄 *Dekont:* https://portal.ornekfirma.com/$dekontYolu\n";
        }
        $mesaj .= "🔖 *Ref:* $referans";

        // DRY_RUN modu - mesaj göndermeden simüle et
        global $DRY_RUN;
        if ($DRY_RUN) {
            writeLog("[DRY-RUN] Bildirim simüle edildi: $gonderenAdi - $tutar $paraBirimi (ID: $hareketId)");
            return true;
        }

        writeLog("Bildirim gönderiliyor: $gonderenAdi - $tutar $paraBirimi (ID: $hareketId)");
        
        // Evolution API ile gönder
        $api = new EvolutionAPI($kanal['api_url'], $kanal['api_key'], $kanal['id']);
        $result = $api->sendText($kanal['instance'], WHATSAPP_GRUP_ID, $mesaj);
        
        if ($result['success']) {
            writeLog("Bildirim gönderildi: $gonderenAdi - $tutar $paraBirimi (ID: $hareketId)");
            return true;
        } else {
            writeLog('Bildirim gönderilemedi: ' . ($result['message'] ?? 'Bilinmeyen hata'), 'ERROR');
            return false;
        }
        
    } catch (\Exception $e) {
        writeLog('Bildirim hatası: ' . $e->getMessage(), 'ERROR');
        return false;
    }
}

// =============================================================================
// MAIN
// =============================================================================

$isWeb = php_sapi_name() !== 'cli';

// CLI argümanlarını parse et (örn: php script.php --minutes=1500)
$cliMinutes = null;
$cliDryRun = false;
if (!$isWeb && isset($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--minutes=') === 0) {
            $cliMinutes = (int)substr($arg, 10);
        }
        if ($arg === '--dry-run' || $arg === '--test') {
            $cliDryRun = true;
        }
    }
}

// Web'den dry-run parametresi
if ($isWeb && isset($_GET['test'])) {
    $DRY_RUN = true;
}
if ($cliDryRun) {
    $DRY_RUN = true;
}

// Web erişimi için güvenlik kontrolü
if ($isWeb) {
    $key = $_GET['key'] ?? '';
    $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) 
                   || ($_SERVER['SERVER_ADDR'] ?? '') === ($_SERVER['REMOTE_ADDR'] ?? '');
    
    // Localhost'tan gelen istekler için key kontrolü atla (Plesk Cron)
    if (!$isLocalhost && $key !== CRON_SECRET_KEY) {
        http_response_code(403);
        jsonResponse(['success' => false, 'message' => 'Geçersiz güvenlik anahtarı!']);
    }
}

writeLog('=== Banka WhatsApp Bildirim Başladı ===' . ($DRY_RUN ? ' [DRY-RUN MODE]' : ''));

try {
    $db = Database::getInstance();
    
    // Pencere süresi (CLI veya GET parametresi ile override edilebilir)
    $windowMinutes = $cliMinutes ?? (isset($_GET['minutes']) ? (int)$_GET['minutes'] : WINDOW_MINUTES);
    $windowMinutes = max(10, min(9000, $windowMinutes)); // 10 dk - 9000 dk arası
    
    // Son X dakikadaki alacak kayıtlarını çek
    // NOT: banka_HesapHareketleriAddedDateTime kullanılır (DB'ye eklenme zamanı)
    //      banka_HesapHareketleriDateTime işlem zamanıdır, cron zamanlaması için uygun değil
    // JOIN ile banka adı çekilir
    $kayitlar = $db->fetchAll("
        SELECT 
            h.banka_HesapHareketleriID,
            h.banka_HesapHareketleriName,
            CONVERT(VARCHAR(19), h.banka_HesapHareketleriDateTime, 120) as banka_HesapHareketleriDateTime,
            h.banka_HesapHareketleriAmount,
            h.banka_HesapHareketleriDescription,
            h.banka_HesapHareketleriIBAN,
            h.banka_HesapHareketleriCurrencyType,
            h.VknOrTc,
            h.banka_HesapHareketleriDekontYolu,
            ISNULL(b.banka_adi, 'Bilinmeyen Banka') as banka_adi,
            ISNULL(f.firma_adi, '') as firma_adi,
            ISNULL(bh.bankaHesap_iban, '') as hesap_iban
        FROM banka_HesapHareketleri h
        LEFT JOIN banka_Hesap bh ON h.banka_HesapId = bh.bankaHesap_id
        LEFT JOIN bankalar b ON bh.bankaHesap_banka_id = b.banka_id
        LEFT JOIN Firmalar f ON bh.bankaHesap_firma_id = f.firma_id
        WHERE h.banka_HesapHareketleriBorcAlacak = 'A'
          AND NULLIF(LTRIM(RTRIM(h.banka_HesapHareketleriName)), '') IS NOT NULL
          AND LTRIM(RTRIM(h.banka_HesapHareketleriName)) != '-'
          AND h.banka_HesapHareketleriName NOT LIKE '%ÖRNEK%'
          AND h.banka_HesapHareketleriAddedDateTime >= DATEADD(MINUTE, -$windowMinutes, GETDATE())
        ORDER BY h.banka_HesapHareketleriAddedDateTime DESC
    ");
    
    writeLog(count($kayitlar) . " alacak kaydı bulundu (son $windowMinutes dk içinde DB'ye eklenen).");
    
    $gonderilen = 0;
    $atlanan = 0;
    $hatali = 0;
    
    foreach ($kayitlar as $kayit) {
        $hareketId = (int)$kayit['banka_HesapHareketleriID'];
        
        // Daha önce gönderilmiş mi?
        if (bildirimGonderilmisMi($hareketId)) {
            $atlanan++;
            continue;
        }
        
        // Bildirim gönder
        if (sendWhatsAppNotification($kayit)) {
            $gonderilen++;
        } else {
            $hatali++;
        }
        
        // Rate limiting - mesajlar arası 1 saniye bekle (dry-run'da atla)
        if ($gonderilen > 0 && !$DRY_RUN) {
            sleep(1);
        }
    }
    
    $dryRunText = $DRY_RUN ? ' [DRY-RUN - MESAJ GÖNDERİLMEDİ]' : '';
    
    $summary = [
        'success' => true,
        'message' => "$gonderilen bildirim gönderildi, $atlanan kayıt zaten gönderilmiş, $hatali hata.$dryRunText",
        'toplam_kayit' => count($kayitlar),
        'gonderilen' => $gonderilen,
        'atlanan' => $atlanan,
        'hatali' => $hatali,
        'window_minutes' => $windowMinutes,
        'dry_run' => $DRY_RUN,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    writeLog("=== Tamamlandı: {$summary['message']} ===");
    
    if ($isWeb) {
        jsonResponse($summary);
    } else {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }
    
} catch (\Exception $e) {
    $errorMsg = 'Kritik Hata: ' . $e->getMessage();
    writeLog($errorMsg, 'CRITICAL');
    
    if ($isWeb) {
        http_response_code(500);
        jsonResponse(['success' => false, 'message' => $errorMsg]);
    } else {
        echo $errorMsg . PHP_EOL;
        exit(1);
    }
}
