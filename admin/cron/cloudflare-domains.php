<?php
/**
 * Cloudflare Alan Adları Senkronizasyonu (Cron Job)
 * 
 * EntegrasyonKanallari tablosunda Entegrasyonlar_Ad='Cloudflare' olan kayıtları alır,
 * Cloudflare API'si üzerinden alan adı bitiş tarihlerini çeker ve Abonelikler tablosunu günceller.
 * 
 * Kullanım:
 * - Test modu: https://portal.ornekfirma.com/admin/cron/cloudflare-domains.php?test
 * - Cron modu: https://portal.ornekfirma.com/admin/cron/cloudflare-domains.php
 * 
 * @author OrnekSoft Portal
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

date_default_timezone_set('Europe/Istanbul');

$isTestMode = isset($_GET['test']);
// CRON_DISPATCH: cron sistemi (tasks.php) tarafından include edildiğinde
// web bağlamında bile CLI gibi davranılır — HTML bloğu basılmaz, loglar echo edilir.
$isWebRequest = (php_sapi_name() !== 'cli') && !defined('CRON_DISPATCH');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/cloudflare-domains-' . date('Y-m') . '.log';
$output = [];

function logMessage($message, $type = 'INFO') {
    global $logFile, $output, $isWebRequest;
    
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$type] $message";
    
    file_put_contents($logFile, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    $output[] = ['type' => $type, 'message' => $message, 'time' => $timestamp];
    
    if (!$isWebRequest) {
        echo $logLine . PHP_EOL;
    }
}

function extractDomain($text) {
    $text = trim((string) $text);
    // \p{L} + /u: ornekholding.net gibi Türkçe karakterli alan adları baştan kırpılmasın
    // (ASCII sınıfı 'ü' harfinde eşleşmeyi bölüp "rkbelge.net" üretiyordu)
    $pattern = '/([\p{L}\p{N}]([\p{L}\p{N}\-]*[\p{L}\p{N}])?\.)+((com|net|org|info|biz|io|co|me|eu|de|uk|fr|it|es|nl|be|at|ch|ru|pl|cz|se|no|dk|fi|pt|gr|hu|ro|bg|hr|sk|si|lt|lv|ee|ua|by|kz|az|ge|am|md|tr)(\.[a-z]{2})?|[\p{L}]{2,})/iu';

    if (preg_match($pattern, $text, $matches)) {
        return mb_strtolower($matches[0], 'UTF-8');
    }

    $firstWord = preg_split('/[\s,;]+/', $text)[0];
    $firstWord = rtrim($firstWord, '.');

    if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\-\.]+\.[\p{L}]{2,}$/u', $firstWord)) {
        return mb_strtolower($firstWord, 'UTF-8');
    }

    return null;
}

logMessage('=== Cloudflare Domain Senkronizasyonu Başladı ===');
logMessage('Mod: ' . ($isTestMode ? 'TEST (güncelleme yapılmayacak)' : 'CRON (güncelleme yapılacak)'));

try {
    require_once __DIR__ . '/../../config/database.php';
    $config = require __DIR__ . '/../../config/database.php';
    $dbConfig = $config['connections']['sqlsrv'];
    
    $serverName = $dbConfig['host'];
    $connectionInfo = [
        "Database" => $dbConfig['database'],
        "UID" => $dbConfig['username'],
        "PWD" => $dbConfig['password'],
        "CharacterSet" => "UTF-8"
    ];
    
    $conn = sqlsrv_connect($serverName, $connectionInfo);
    if ($conn === false) {
        throw new Exception("DB bağlantı hatası: " . print_r(sqlsrv_errors(), true));
    }
    
    logMessage('Veritabanı bağlantısı başarılı');

    // 1. Cloudflare Entegrasyonunu Bul
    $sqlEntegrasyon = "
        SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KullaniciAdi, k.EntegrasyonKanallari_Sifre, k.EntegrasyonKanallari_Ad
        FROM EntegrasyonKanallari k
        JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Ad LIKE '%Cloudflare%' AND k.EntegrasyonKanallari_Durum = 1 AND e.Entegrasyonlar_Durum = 1
    ";
    
    $stmtEnt = sqlsrv_query($conn, $sqlEntegrasyon);
    $kanallar = [];
    while ($row = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
        $kanallar[] = $row;
    }
    sqlsrv_free_stmt($stmtEnt);
    
    if (empty($kanallar)) {
        throw new Exception("Aktif Cloudflare entegrasyon kanalı bulunamadı!");
    }

    // 1b. Cloudflare Cari'sini Bul
    // Cari.cari_entegrasyon_kanali_id üzerinden dinamik çözülür; bulunamazsa ada göre aranır.
    $kanalIds = array_map('intval', array_column($kanallar, 'EntegrasyonKanallari_id'));
    $cfCariId = null;
    $cfCariAdi = null;

    $sqlCari = "
        SELECT TOP 1 cari_id, cari_adi FROM Cari
        WHERE cari_entegrasyon_kanali_id IN (" . implode(',', $kanalIds) . ")
    ";
    $stmtCari = sqlsrv_query($conn, $sqlCari);
    if ($stmtCari && ($rowCari = sqlsrv_fetch_array($stmtCari, SQLSRV_FETCH_ASSOC))) {
        $cfCariId = (int) $rowCari['cari_id'];
        $cfCariAdi = $rowCari['cari_adi'];
    }
    if ($stmtCari) {
        sqlsrv_free_stmt($stmtCari);
    }

    if (!$cfCariId) {
        $stmtCari = sqlsrv_query($conn, "SELECT TOP 1 cari_id, cari_adi FROM Cari WHERE cari_adi LIKE '%Cloudflare%'");
        if ($stmtCari && ($rowCari = sqlsrv_fetch_array($stmtCari, SQLSRV_FETCH_ASSOC))) {
            $cfCariId = (int) $rowCari['cari_id'];
            $cfCariAdi = $rowCari['cari_adi'];
        }
        if ($stmtCari) {
            sqlsrv_free_stmt($stmtCari);
        }
    }

    if (!$cfCariId) {
        throw new Exception("Cloudflare cari kaydı bulunamadı! Cari tablosunda entegrasyon kanalı eşleşmesi yapılmalı.");
    }

    logMessage("Cloudflare cari'si: [{$cfCariId}] {$cfCariAdi}");

    // 2. Abonelikler Tablosundaki Domainleri Çek
    $sqlUrunler = "
        SELECT urun_hizmet_id FROM Urun_Hizmet 
        WHERE urun_hizmet_adi LIKE '%ALAN ADI%' OR urun_hizmet_adi LIKE '%Alan Adı%' OR urun_hizmet_adi LIKE '%Domain%'
    ";
    $stmtUrunler = sqlsrv_query($conn, $sqlUrunler);
    $urunIds = [];
    while ($row = sqlsrv_fetch_array($stmtUrunler, SQLSRV_FETCH_ASSOC)) {
        $urunIds[] = $row['urun_hizmet_id'];
    }
    sqlsrv_free_stmt($stmtUrunler);
    
    if (empty($urunIds)) {
        throw new Exception("ALAN ADI tipinde ürün bulunamadı!");
    }

    $placeholders = implode(',', $urunIds);
    // Alan adı artık abonelik_bilgi alanında tutulur; eski kayıtlar için
    // açıklama alanından ayrıştırma yedek yol olarak korunur.
    $sqlAbonelikler = "
        SELECT
            a.abonelik_id,
            a.abonelik_bilgi,
            a.abonelik_aciklama,
            a.abonelik_cari_id,
            c.cari_adi,
            CONVERT(VARCHAR(10), a.abonelik_son_odeme_tarihi, 23) as mevcut_tarih
        FROM Abonelikler a
        LEFT JOIN Cari c ON a.abonelik_cari_id = c.cari_id
        WHERE a.abonelik_urun_hizmet_id IN ($placeholders)
          AND a.abonelik_durum = 1
          AND (
                (a.abonelik_bilgi IS NOT NULL AND a.abonelik_bilgi != '')
             OR (a.abonelik_aciklama IS NOT NULL AND a.abonelik_aciklama != '')
          )
    ";

    $stmtAbonelikler = sqlsrv_query($conn, $sqlAbonelikler);
    $abonelikler = [];
    while ($row = sqlsrv_fetch_array($stmtAbonelikler, SQLSRV_FETCH_ASSOC)) {
        $domain = !empty($row['abonelik_bilgi'])
            ? mb_strtolower(trim($row['abonelik_bilgi']), 'UTF-8')
            : extractDomain($row['abonelik_aciklama']);

        if ($domain) {
            $abonelikler[$domain] = $row;
        }
    }
    sqlsrv_free_stmt($stmtAbonelikler);
    
    logMessage(count($abonelikler) . " adet domain aboneliği bulundu.");

    // 3. Her bir Cloudflare kanalı için API çağrısı yap ve verileri topla
    $cloudflareDomains = [];
    
    foreach ($kanallar as $kanal) {
        $kanalAdi = $kanal['EntegrasyonKanallari_Ad'];
        $accountId = trim($kanal['EntegrasyonKanallari_KullaniciAdi']);
        $apiToken = trim($kanal['EntegrasyonKanallari_Sifre']);
        
        if (empty($accountId) || empty($apiToken)) {
            logMessage("Kanal [{$kanalAdi}] eksik bilgi: Kullanıcı Adı (Account ID) veya Şifre (API Token) boş!", 'WARNING');
            continue;
        }
        
        logMessage("Kanal [{$kanalAdi}] Cloudflare API'ye bağlanılıyor...");
        
        // Registrar API'sinde sayfa numarası SIFIR tabanlıdır: page=0 ilk sayfa, page=1 ikinci sayfa.
        // Parametresiz istek page=0 kabul edildiği için, hesapta per_page'den fazla domain varsa
        // taşan kayıtlar sessizce atlanıyordu (31 domainin 30'u geliyordu).
        $page = 0;
        $perPage = 50;
        $hasMore = true;

        while ($hasMore) {
            $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/registrar/domains"
                 . "?per_page={$perPage}&page={$page}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$apiToken}",
                    "Content-Type: application/json"
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if ($data && isset($data['success']) && $data['success'] === true) {
                    $results = $data['result'] ?? [];
                    $totalCount = $data['result_info']['total_count'] ?? count($results);
                    $totalPages = $data['result_info']['total_pages'] ?? 1;

                    logMessage("API'den " . count($results) . " adet sonuç döndü (Sayfa: $page / Toplam kayıt: $totalCount)");

                    foreach ($results as $item) {
                        $domainName = strtolower($item['name']);

                        // NOT: Cloudflare Registrar API'si yenileme ücretini vermiyor.
                        // Domain detay endpoint'inde 'fees' alanı bulunmuyor, /price endpoint'i 404 dönüyor
                        // (kontrol tarihi: 2026-08-17). Bu yüzden fiyat çekme kodu devre dışı bırakıldı;
                        // abonelik_dolar_tutar portalden elle girilmeye devam eder.
                        // Cloudflare ileride bu alanı eklerse aşağıdaki blok geri açılabilir:
                        //
                        // $detailUrl = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/registrar/domains/{$domainName}";
                        // ... curl isteği ...
                        // if (isset($detailData['result']['fees']['renewal']['price'])) {
                        //     $price = $detailData['result']['fees']['renewal']['price'];
                        //     $currency = $detailData['result']['fees']['renewal']['currency'] ?? 'USD';
                        //     if (isset($detailData['result']['fees']['icann']['price'])) {
                        //         $price += $detailData['result']['fees']['icann']['price'];
                        //     }
                        // }
                        $price = null;
                        $currency = null;

                        $cloudflareDomains[$domainName] = [
                            'expires_at' => substr($item['expires_at'], 0, 10),
                            'auto_renew' => isset($item['auto_renew']) ? $item['auto_renew'] : true,
                            'price' => $price,
                            'currency' => $currency
                        ];
                    }

                    // Sıfır tabanlı sayfalama: son sayfa indeksi totalPages - 1
                    if (empty($results) || $page >= ($totalPages - 1)) {
                        $hasMore = false;
                    } else {
                        $page++;
                        // Rate limit'i yormamak için sayfalar arası kısa bekleme
                        usleep(250000);
                    }
                } else {
                    logMessage("API Yanıtı Başarısız: " . json_encode($data), 'ERROR');
                    $hasMore = false;
                }
            } else {
                logMessage("HTTP Hata Kodu [{$httpCode}] - Yanıt: {$response}", 'ERROR');
                $hasMore = false;
            }
        }
    }
    
    logMessage(count($cloudflareDomains) . " adet domain Cloudflare üzerinden çekildi.");

    // 4. Eşleştirme, Güncelleme ve Yeni Kayıt Ekleme
    $stats = ['basarili' => 0, 'basarisiz' => 0, 'degisiklik_yok' => 0, 'yeni_eklenen' => 0, 'cari_tasinan' => 0];

    foreach ($cloudflareDomains as $domain => $cfData) {
        $yeniTarih = $cfData['expires_at'];
        $price = $cfData['price'];
        $currency = $cfData['currency']; // Cloudflare genelde USD döner

        if (isset($abonelikler[$domain])) {
            // GÜNCELLEME İŞLEMİ
            $ab = $abonelikler[$domain];
            $abId = $ab['abonelik_id'];
            $mevcutTarih = $ab['mevcut_tarih'];
            
            // Tarih kontrolü
            $tarihAyni = ($mevcutTarih === $yeniTarih);

            // Domain Cloudflare'da bulunduğu halde abonelik başka bir cari'de duruyorsa
            // (ör. Alastyr'den Cloudflare'a taşınan alan adları) cari otomatik güncellenir.
            $mevcutCariId = (int) $ab['abonelik_cari_id'];
            $mevcutCariAdi = $ab['cari_adi'] ?: "ID:$mevcutCariId";
            $cariTasinacak = ($mevcutCariId !== $cfCariId);

            // Ne tarih ne cari değişiyorsa boşuna UPDATE atma
            if ($tarihAyni && !$cariTasinacak) {
                $stats['degisiklik_yok']++;
                continue;
            }

            if ($isTestMode) {
                if (!$tarihAyni) {
                    logMessage("ID:$abId - $domain - [TEST] Tarih güncellenecek: $mevcutTarih → $yeniTarih");
                }
                if ($cariTasinacak) {
                    logMessage("ID:$abId - $domain - [TEST] Cari taşınacak: $mevcutCariAdi → $cfCariAdi", 'INFO');
                    $stats['cari_tasinan']++;
                }
                $stats['basarili']++;
            } else {
                $updateFields = "abonelik_son_odeme_tarihi = ?, abonelik_guncelleme_tarihi = GETDATE()";
                $params = [$yeniTarih];

                if ($price !== null && $price !== '') {
                    $updateFields .= ", abonelik_dolar_tutar = ?";
                    $params[] = $price;
                }

                if ($cariTasinacak) {
                    $updateFields .= ", abonelik_cari_id = ?";
                    $params[] = $cfCariId;
                }

                $params[] = $abId;

                $sqlUpdate = "UPDATE Abonelikler SET $updateFields WHERE abonelik_id = ?";
                $stmtUpdate = sqlsrv_query($conn, $sqlUpdate, $params);

                if ($stmtUpdate) {
                    if (!$tarihAyni) {
                        logMessage("ID:$abId - $domain - Güncellendi: $mevcutTarih → $yeniTarih", 'SUCCESS');
                    }
                    if ($cariTasinacak) {
                        logMessage("ID:$abId - $domain - Cari taşındı: $mevcutCariAdi → $cfCariAdi", 'SUCCESS');
                        $stats['cari_tasinan']++;
                    }
                    $stats['basarili']++;
                    sqlsrv_free_stmt($stmtUpdate);
                } else {
                    logMessage("ID:$abId - $domain - Güncelleme hatası: " . print_r(sqlsrv_errors(), true), 'ERROR');
                    $stats['basarisiz']++;
                }
            }
        } else {
            // YENİ KAYIT EKLEME İŞLEMİ (Eksik Domain)
            if ($isTestMode) {
                logMessage("YENİ EKLENECEK - $domain - Bitiş: $yeniTarih", 'INFO');
                $stats['yeni_eklenen']++;
            } else {
                $sqlInsert = "
                    INSERT INTO Abonelikler (
                        abonelik_urun_hizmet_id, abonelik_cari_id, abonelik_odeme_sekli,
                        abonelik_dolar_tutar, abonelik_periyot, abonelik_bilgi,
                        abonelik_son_odeme_tarihi, abonelik_durum,
                        abonelik_renk, abonelik_sira_no, abonelik_olusturan_kullanici_id
                    ) VALUES (
                        8, ?, 1,
                        ?, 365, ?,
                        ?, 1,
                        '#0d6efd', 1, 1
                    )
                ";

                $insertPrice = ($price !== null && $price !== '') ? $price : 0;
                $params = [$cfCariId, $insertPrice, $domain, $yeniTarih];
                
                $stmtInsert = sqlsrv_query($conn, $sqlInsert, $params);
                
                if ($stmtInsert) {
                    logMessage("YENİ EKLENDİ - $domain - Bitiş: $yeniTarih", 'SUCCESS');
                    $stats['yeni_eklenen']++;
                    sqlsrv_free_stmt($stmtInsert);
                } else {
                    logMessage("Ekleme hatası ($domain): " . print_r(sqlsrv_errors(), true), 'ERROR');
                    $stats['basarisiz']++;
                }
            }
        }
    }
    
    sqlsrv_close($conn);
    
    logMessage('=== İşlem Tamamlandı ===');
    logMessage("Başarılı: {$stats['basarili']}, Başarısız: {$stats['basarisiz']}, Değişiklik Yok: {$stats['degisiklik_yok']}, Yeni Eklenen: {$stats['yeni_eklenen']}, Cari Taşınan: {$stats['cari_tasinan']}");
    
} catch (Exception $e) {
    logMessage("Kritik hata: " . $e->getMessage(), 'ERROR');
}

if (isset($_POST['ajax']) || isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => !empty($stats) || empty($output),
        'stats' => $stats ?? ['basarili' => 0, 'basarisiz' => 0, 'degisiklik_yok' => 0, 'yeni_eklenen' => 0, 'cari_tasinan' => 0],
        'logs' => $output
    ]);
    exit;
}

if ($isWebRequest) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <title>Cloudflare Alan Adı Senkronizasyonu <?= $isTestMode ? '(TEST)' : '(CRON)' ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            .log-SUCCESS { color: #198754; }
            .log-ERROR { color: #dc3545; }
            .log-WARNING { color: #ffc107; }
            .log-INFO { color: #0d6efd; }
        </style>
    </head>
    <body class="bg-light">
        <div class="container py-4">
            <h2>
                <i class="bi bi-cloud"></i> Cloudflare Alan Adı Senkronizasyonu 
                <?php if ($isTestMode): ?>
                    <span class="badge bg-warning">TEST MODU</span>
                <?php else: ?>
                    <span class="badge bg-success">CRON MODU</span>
                <?php endif; ?>
            </h2>
            
            <?php if ($isTestMode): ?>
                <div class="alert alert-warning">
                    <strong>Test Modu:</strong> Veritabanı güncellemesi yapılmıyor, sadece simülasyon.
                    <br>Gerçek güncelleme için <code>?test</code> parametresini kaldırın.
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">Log Çıktısı</div>
                <div class="card-body">
                    <pre style="max-height: 600px; overflow-y: auto; font-size: 13px;"><?php
                        foreach ($output as $log) {
                            $class = 'log-' . $log['type'];
                            echo "<span class=\"$class\">[{$log['time']}] [{$log['type']}] {$log['message']}</span>\n";
                        }
                    ?></pre>
                </div>
            </div>
            
            <div class="mt-3">
                <a href="?test" class="btn btn-warning">Test Modu</a>
                <a href="?" class="btn btn-success">Cron Modu (Gerçek)</a>
                <a href="/admin/pages/abonelik-takibi.php" class="btn btn-secondary">Abonelik Takibi</a>
                <a href="/admin/pages/entegrasyon-yonetimi.php" class="btn btn-primary">Entegrasyon Yönetimi</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
