<?php
/**
 * Banka Senkronizasyon Cron
 * 
 * Tüm aktif banka API'lerini senkronize eder
 * 
 * Kullanım:
 * - Cron: php banka-senkron.php
 * - Manuel: https://portal.ornekfirma.com/admin/cron/banka-senkron.php?key=SECRET_KEY
 * - Tek banka: ?key=SECRET_KEY&api_kimlik_id=1
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

// Hata raporlama (production'da kapatılabilir)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Zaman limiti (5 dakika)
set_time_limit(300);

// Güvenlik anahtarı (değiştirin!)
define('CRON_SECRET_KEY', 'degistir_cron_anahtari_1');

// Root path
define('ROOT_PATH', dirname(dirname(__DIR__)));

// Gerekli dosyaları yükle
require_once ROOT_PATH . '/admin/db.php';
require_once ROOT_PATH . '/admin/api/banka/BankaServiceFactory.php';

use App\Services\Banka\BankaServiceFactory;

/**
 * Log dosyasına yaz
 */
function writeLog(string $message, string $level = 'INFO'): void
{
    $logDir = ROOT_PATH . '/admin/cron/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/banka-senkron-' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Lock dosyası kontrolü (çakışma önleme)
 */
function acquireLock(): bool
{
    $lockFile = ROOT_PATH . '/admin/cron/logs/banka-senkron.lock';
    
    // Lock dosyası varsa ve 8 dakikadan yeni ise, çalışma
    if (file_exists($lockFile)) {
        $lockTime = filemtime($lockFile);
        $diff = time() - $lockTime;
        
        if ($diff < 480) { // 8 dakika (cron 10 dakikada bir çalışacak)
            writeLog("Başka bir senkronizasyon çalışıyor (lock: $diff saniye önce oluşturulmuş)", 'WARN');
            return false;
        } else {
            // Eski lock, sil
            unlink($lockFile);
            writeLog("Eski lock dosyası silindi ($diff saniye)", 'WARN');
        }
    }
    
    // Lock oluştur
    file_put_contents($lockFile, date('Y-m-d H:i:s') . ' - PID: ' . getmypid());
    return true;
}

/**
 * Lock dosyasını sil
 */
function releaseLock(): void
{
    $lockFile = ROOT_PATH . '/admin/cron/logs/banka-senkron.lock';
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
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
 * Console çıktı
 */
function consoleOutput(string $message): void
{
    if (php_sapi_name() === 'cli') {
        echo $message . PHP_EOL;
    }
}

// =============================================================================
// MAIN
// =============================================================================

$isCli = php_sapi_name() === 'cli';
$isWeb = !$isCli;

// Web erişimi için güvenlik kontrolü
if ($isWeb) {
    $key = $_GET['key'] ?? '';
    if ($key !== CRON_SECRET_KEY) {
        http_response_code(403);
        jsonResponse(['success' => false, 'message' => 'Geçersiz güvenlik anahtarı!']);
    }
}

writeLog('=== Banka Senkronizasyon Başladı ===');
consoleOutput('Banka Senkronizasyon Başladı...');

// Lock kontrolü
if (!acquireLock()) {
    $message = 'Başka bir senkronizasyon çalışıyor, çıkılıyor.';
    consoleOutput($message);
    if ($isWeb) {
        jsonResponse(['success' => false, 'message' => $message]);
    }
    exit(1);
}

try {
    $db = Database::getInstance();
    $results = [];
    $toplamInserted = 0;
    $toplamSkipped = 0;
    $basariliApi = 0;
    $hataliApi = 0;
    
    // Tek API mi yoksa tümü mü?
    $tekApiKimlikId = $isWeb ? ($_GET['api_kimlik_id'] ?? null) : ($argv[1] ?? null);
    
    if ($tekApiKimlikId) {
        // Tek API senkronize
        $apiKimlikleri = $db->fetchAll("
            SELECT 
                ak.apiKimlik_id,
                ak.apiKimlik_banka_id,
                b.banka_kodu,
                b.banka_adi
            FROM banka_ApiKimlik ak
            INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
            WHERE ak.apiKimlik_id = ? AND ak.apiKimlik_durum = 1
        ", [$tekApiKimlikId]);
    } else {
        // Tüm aktif API'leri al
        $apiKimlikleri = BankaServiceFactory::getAktifApiKimlikleri();
    }
    
    if (empty($apiKimlikleri)) {
        $message = 'Aktif API kimliği bulunamadı.';
        writeLog($message, 'WARN');
        consoleOutput($message);
        releaseLock();
        if ($isWeb) {
            jsonResponse(['success' => true, 'message' => $message, 'results' => []]);
        }
        exit(0);
    }
    
    writeLog(count($apiKimlikleri) . ' aktif API kimliği bulundu.');
    consoleOutput(count($apiKimlikleri) . ' aktif API kimliği bulundu.');
    
    // Senkronizasyon öncesi max ID kontrolü kaldırıldı (gerek yok)
    writeLog("Senkronizasyon başlıyor...");
    
    // Her API için senkronizasyon
    foreach ($apiKimlikleri as $apiKimlik) {
        $bankaAdi = $apiKimlik['banka_adi'];
        $apiId = $apiKimlik['apiKimlik_id'];
        
        writeLog("[$bankaAdi] Senkronizasyon başlıyor (API ID: $apiId)");
        consoleOutput("[$bankaAdi] Senkronizasyon başlıyor...");
        
        try {
            // Servis oluştur
            $service = BankaServiceFactory::createByApiKimlik($apiId, null, 'cron');
            
            // Önce hesapları güncelle
            writeLog("[$bankaAdi] Hesap listesi çekiliyor...");
            $hesapResult = $service->getHesaplar();
            
            if (!$hesapResult['success']) {
                throw new \Exception($hesapResult['message']);
            }
            
            writeLog("[$bankaAdi] {$hesapResult['count']} hesap bulundu.");
            consoleOutput("  - {$hesapResult['count']} hesap bulundu.");
            
            // Tüm hesapları senkronize et
            $senkronResult = $service->senkronizeTumu();
            
            $results[] = [
                'api_kimlik_id' => $apiId,
                'banka' => $bankaAdi,
                'hesap_sayisi' => $hesapResult['count'],
                'inserted' => $senkronResult['summary']['toplam_inserted'] ?? 0,
                'skipped' => $senkronResult['summary']['toplam_skipped'] ?? 0,
                'success' => $senkronResult['success'],
                'message' => $senkronResult['message']
            ];
            
            $toplamInserted += $senkronResult['summary']['toplam_inserted'] ?? 0;
            $toplamSkipped += $senkronResult['summary']['toplam_skipped'] ?? 0;
            $basariliApi++;
            
            writeLog("[$bankaAdi] Tamamlandı: {$senkronResult['message']}");
            consoleOutput("  - Tamamlandı: {$senkronResult['message']}");
            
            // === DEKONT İNDİRME ===
            // Servis dekontIndir metodunu destekliyorsa, dekont yolu olmayan kayıtları indir
            if (method_exists($service, 'dekontIndir')) {
                writeLog("[$bankaAdi] Dekont indirme başlıyor...");
                consoleOutput("  - Dekont indirme başlıyor...");
                
                try {
                    // Dekont yolu NULL olan kayıtları bul (bu API'ye ait, son 30 gün, max 50 adet)
                    $dekontSizKayitlar = $db->fetchAll("
                        SELECT TOP 50
                            h.banka_HesapHareketleriID,
                            h.banka_HesapId,
                            h.ReceiptNo,
                            h.banka_HesapHareketleriIdentifier
                        FROM banka_HesapHareketleri h
                        INNER JOIN banka_Hesap bh ON h.banka_HesapId = bh.bankaHesap_id
                        WHERE h.banka_HesapHareketleriDekontYolu IS NULL
                          AND bh.bankaHesap_apiKimlik_id = ?
                          AND h.banka_HesapHareketleriDateTime >= DATEADD(DAY, -30, GETDATE())
                          AND (h.ReceiptNo IS NOT NULL OR h.banka_HesapHareketleriIdentifier LIKE 'HALKBANK_%')
                        ORDER BY h.banka_HesapHareketleriDateTime DESC
                    ", [$apiId]);
                    
                    $dekontBasarili = 0;
                    $dekontHatali = 0;
                    
                    foreach ($dekontSizKayitlar as $kayit) {
                        // DekontNo'yu belirle: ReceiptNo varsa onu kullan, yoksa Identifier'dan çıkar
                        $dekontNo = $kayit['ReceiptNo'];
                        $hesapId = $kayit['banka_HesapId'];
                        
                        if (empty($dekontNo) && !empty($kayit['banka_HesapHareketleriIdentifier'])) {
                            // Identifier formatı: HALKBANK_{hesapId}_{dekontNo}_{sirano}
                            $parts = explode('_', $kayit['banka_HesapHareketleriIdentifier']);
                            if (count($parts) >= 3) {
                                $dekontNo = $parts[2]; // 3. parça = dekontNo
                            }
                        }
                        
                        if (empty($dekontNo)) {
                            continue;
                        }
                        
                        try {
                            $dekontResult = $service->dekontIndir($hesapId, $dekontNo);
                            if ($dekontResult['success']) {
                                $dekontBasarili++;
                            } else {
                                $dekontHatali++;
                                writeLog("[$bankaAdi] Dekont indirme hatası (ID: {$kayit['banka_HesapHareketleriID']}): {$dekontResult['message']}", 'WARN');
                            }
                        } catch (\Exception $dekontEx) {
                            $dekontHatali++;
                            writeLog("[$bankaAdi] Dekont exception (ID: {$kayit['banka_HesapHareketleriID']}): " . $dekontEx->getMessage(), 'WARN');
                        }
                        
                        // API rate limiting - her dekont arası 1 saniye bekle
                        usleep(500000); // 0.5 saniye
                    }
                    
                    if (count($dekontSizKayitlar) > 0) {
                        writeLog("[$bankaAdi] Dekont indirme tamamlandı: $dekontBasarili başarılı, $dekontHatali hatalı / " . count($dekontSizKayitlar) . " toplam");
                        consoleOutput("  - Dekont: $dekontBasarili başarılı, $dekontHatali hatalı / " . count($dekontSizKayitlar) . " toplam");
                    } else {
                        writeLog("[$bankaAdi] İndirilecek dekont bulunamadı.");
                        consoleOutput("  - İndirilecek dekont yok.");
                    }
                    
                } catch (\Exception $dekontGenel) {
                    writeLog("[$bankaAdi] Dekont indirme genel hatası: " . $dekontGenel->getMessage(), 'ERROR');
                    consoleOutput("  - Dekont indirme hatası: " . $dekontGenel->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $results[] = [
                'api_kimlik_id' => $apiId,
                'banka' => $bankaAdi,
                'success' => false,
                'message' => $errorMsg
            ];
            $hataliApi++;
            
            writeLog("[$bankaAdi] HATA: $errorMsg", 'ERROR');
            consoleOutput("  - HATA: $errorMsg");
        }
        
        // API'ler arası kısa bekleme (rate limiting önlemi)
        if (count($apiKimlikleri) > 1) {
            sleep(2);
        }
    }
    
    // Özet
    $summary = [
        'success' => $hataliApi === 0,
        'message' => "$basariliApi API başarılı, $hataliApi API hatalı. Toplam $toplamInserted yeni kayıt eklendi.",
        'toplam_api' => count($apiKimlikleri),
        'basarili_api' => $basariliApi,
        'hatali_api' => $hataliApi,
        'toplam_inserted' => $toplamInserted,
        'toplam_skipped' => $toplamSkipped,
        'results' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    writeLog("=== Senkronizasyon Tamamlandı: {$summary['message']} ===");
    consoleOutput("\nSenkronizasyon Tamamlandı: {$summary['message']}");
    
    releaseLock();
    
    if ($isWeb) {
        jsonResponse($summary);
    }
    
} catch (\Exception $e) {
    $errorMsg = 'Kritik Hata: ' . $e->getMessage();
    writeLog($errorMsg, 'CRITICAL');
    consoleOutput($errorMsg);
    
    releaseLock();
    
    if ($isWeb) {
        http_response_code(500);
        jsonResponse(['success' => false, 'message' => $errorMsg]);
    }
    
    exit(1);
}
