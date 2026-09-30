<?php
/**
 * Domain WHOIS Otomatik Güncelleme (Cron Job)
 * 
 * ALAN ADI aboneliklerinin expire tarihlerini WHOIS ile günceller.
 * 
 * Kullanım:
 * - Test modu: https://portal.ornekfirma.com/admin/cron/domain-whois-guncelle.php?test
 * - Cron modu: https://portal.ornekfirma.com/admin/cron/domain-whois-guncelle.php
 * 
 * Plesk Scheduled Task:
 * - URL: https://portal.ornekfirma.com/admin/cron/domain-whois-guncelle.php
 * - Çalışma: Günlük, 03:00
 * 
 * @author OrnekSoft Portal
 * @version 1.0.0
 * @date 2026-02-06
 */

// Hata raporlama
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Encoding
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// Zaman dilimi
date_default_timezone_set('Europe/Istanbul');

// Test modu kontrolü
$isTestMode = isset($_GET['test']);
// CRON_DISPATCH: cron sistemi (tasks.php) tarafından include edildiğinde
// web bağlamında bile CLI gibi davranılır — HTML bloğu basılmaz, loglar echo edilir.
$isWebRequest = (php_sapi_name() !== 'cli') && !defined('CRON_DISPATCH');

// Log dosyası
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/domain-whois-' . date('Y-m') . '.log';

// Çıktı buffer
$output = [];

/**
 * Log ve çıktı fonksiyonu
 */
function logMessage($message, $type = 'INFO') {
    global $logFile, $output, $isTestMode, $isWebRequest;
    
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] [$type] $message";
    
    // Dosyaya yaz (test modunda da)
    file_put_contents($logFile, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    
    // Çıktıya ekle
    $output[] = ['type' => $type, 'message' => $message, 'time' => $timestamp];
    
    // CLI'da ekrana yaz
    if (!$isWebRequest) {
        echo $logLine . PHP_EOL;
    }
}

/**
 * Açıklama metninden domain adını ayıkla
 */
function extractDomain($text) {
    $text = trim($text);
    
    $pattern = '/([a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)+((com|net|org|info|biz|io|co|me|eu|de|uk|fr|it|es|nl|be|at|ch|ru|pl|cz|se|no|dk|fi|pt|gr|hu|ro|bg|hr|sk|si|lt|lv|ee|ua|by|kz|az|ge|am|md|tr)(\.[a-z]{2})?|[a-z]{2,})/i';
    
    if (preg_match($pattern, $text, $matches)) {
        return strtolower($matches[0]);
    }
    
    $firstWord = preg_split('/[\s,;]+/', $text)[0];
    $firstWord = rtrim($firstWord, '.');
    
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}$/', $firstWord)) {
        return strtolower($firstWord);
    }
    
    return null;
}

/**
 * WHOIS ile expire tarihini al
 */
function getWhoisExpireDate($domain) {
    $result = ['success' => false, 'expire_date' => null, 'source' => ''];
    
    // 1. RDAP Bootstrap dene
    $rdapResult = getRdapExpire($domain);
    if ($rdapResult['success']) {
        return $rdapResult;
    }
    
    // 2. who.is dene
    $whoisResult = getWhoIsExpire($domain);
    if ($whoisResult['success']) {
        return $whoisResult;
    }
    
    // 3. whois.com dene
    $whoisComResult = getWhoisComExpire($domain);
    if ($whoisComResult['success']) {
        return $whoisComResult;
    }
    
    return $result;
}

/**
 * RDAP Bootstrap API
 */
function getRdapExpire($domain) {
    $result = ['success' => false, 'expire_date' => null, 'source' => 'RDAP'];
    
    $url = 'https://rdap.org/domain/' . $domain;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/rdap+json, application/json',
            'User-Agent: Mozilla/5.0 (compatible; DomainChecker/1.0)'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return $result;
    }
    
    $data = json_decode($response, true);
    if ($data && isset($data['events'])) {
        foreach ($data['events'] as $event) {
            if (isset($event['eventAction']) && $event['eventAction'] === 'expiration') {
                $timestamp = strtotime($event['eventDate'] ?? '');
                if ($timestamp) {
                    $result['success'] = true;
                    $result['expire_date'] = date('Y-m-d', $timestamp);
                    return $result;
                }
            }
        }
    }
    
    return $result;
}

/**
 * who.is web scraping
 */
function getWhoIsExpire($domain) {
    $result = ['success' => false, 'expire_date' => null, 'source' => 'who.is'];
    
    $url = 'https://who.is/whois/' . urlencode($domain);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: text/html'
        ]
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (!$response) {
        return $result;
    }
    
    // JSON encoded newlines düzelt
    $textContent = str_replace(['\\n', '\\r'], "\n", $response);
    $textContent = strip_tags($textContent);
    $textContent = html_entity_decode($textContent);
    
    // .tr formatı: Expires on..............: 2026-Apr-03.
    if (preg_match('/Expires\s*on\.+:?\s*(\d{4}-[A-Za-z]+-\d{2})\.?/i', $textContent, $matches)) {
        $dateStr = trim($matches[1]);
        if (preg_match('/(\d{4})-([A-Za-z]+)-(\d{2})/', $dateStr, $dm)) {
            $months = ['jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
                       'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
            $mk = strtolower(substr($dm[2], 0, 3));
            if (isset($months[$mk])) {
                $result['success'] = true;
                $result['expire_date'] = $dm[1] . '-' . $months[$mk] . '-' . $dm[3];
                return $result;
            }
        }
    }
    
    // Genel formatlar
    $patterns = [
        '/Registry\s*Expiry\s*Date[:\s]+(\d{4}-\d{2}-\d{2})/i',
        '/Expir(?:y|ation)\s*Date[:\s]+(\d{4}-\d{2}-\d{2})/i',
        '/Expires\s*On[:\s]+(\d{4}-\d{2}-\d{2})/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $textContent, $matches)) {
            $timestamp = strtotime($matches[1]);
            if ($timestamp) {
                $result['success'] = true;
                $result['expire_date'] = date('Y-m-d', $timestamp);
                return $result;
            }
        }
    }
    
    return $result;
}

/**
 * whois.com web scraping
 */
function getWhoisComExpire($domain) {
    $result = ['success' => false, 'expire_date' => null, 'source' => 'whois.com'];
    
    $url = 'https://www.whois.com/whois/' . urlencode($domain);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: text/html'
        ]
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (!$response) {
        return $result;
    }
    
    $textContent = strip_tags(str_replace(['<br>', '<br/>'], "\n", $response));
    $textContent = html_entity_decode($textContent);
    
    $patterns = [
        '/Expires\s*On[:\s]+(\d{4}-\d{2}-\d{2})/i',
        '/Expir(?:y|ation)\s*Date[:\s]+(\d{4}-\d{2}-\d{2})/i',
        '/Registry\s*Expiry\s*Date[:\s]+(\d{4}-\d{2}-\d{2}T[\d:]+Z?)/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $textContent, $matches)) {
            $timestamp = strtotime($matches[1]);
            if ($timestamp) {
                $result['success'] = true;
                $result['expire_date'] = date('Y-m-d', $timestamp);
                return $result;
            }
        }
    }
    
    return $result;
}

// ============================================================
// ANA İŞLEM BAŞLANGICI
// ============================================================

logMessage('=== Domain WHOIS Güncelleme Başladı ===');
logMessage('Mod: ' . ($isTestMode ? 'TEST (güncelleme yapılmayacak)' : 'CRON (güncelleme yapılacak)'));

try {
    // Veritabanı bağlantısı
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
        $error = print_r(sqlsrv_errors(), true);
        logMessage("Veritabanı bağlantı hatası: $error", 'ERROR');
        throw new Exception("DB bağlantı hatası");
    }
    
    logMessage('Veritabanı bağlantısı başarılı');
    
    // ALAN ADI ürünlerini bul
    $sqlUrunler = "
        SELECT urun_hizmet_id, urun_hizmet_adi 
        FROM Urun_Hizmet 
        WHERE urun_hizmet_adi LIKE '%ALAN ADI%' 
           OR urun_hizmet_adi LIKE '%Alan Adı%'
           OR urun_hizmet_adi LIKE '%Domain%'
    ";
    
    $stmtUrunler = sqlsrv_query($conn, $sqlUrunler);
    $urunIds = [];
    
    while ($row = sqlsrv_fetch_array($stmtUrunler, SQLSRV_FETCH_ASSOC)) {
        $urunIds[] = $row['urun_hizmet_id'];
        logMessage("ALAN ADI ürünü bulundu: ID={$row['urun_hizmet_id']}, Ad={$row['urun_hizmet_adi']}");
    }
    sqlsrv_free_stmt($stmtUrunler);
    
    if (empty($urunIds)) {
        logMessage('ALAN ADI tipinde ürün bulunamadı!', 'WARNING');
        throw new Exception("Ürün bulunamadı");
    }
    
    // Domain aboneliklerini al
    $placeholders = implode(',', $urunIds);
    $sqlAbonelikler = "
        SELECT 
            a.abonelik_id,
            a.abonelik_aciklama,
            CONVERT(VARCHAR(10), a.abonelik_son_odeme_tarihi, 23) as mevcut_tarih,
            c.cari_adi
        FROM Abonelikler a
        LEFT JOIN Cari c ON a.abonelik_cari_id = c.cari_id
        WHERE a.abonelik_urun_hizmet_id IN ($placeholders)
          AND a.abonelik_durum = 1
          AND a.abonelik_aciklama IS NOT NULL
          AND a.abonelik_aciklama != ''
        ORDER BY a.abonelik_id
    ";
    
    $stmtAbonelikler = sqlsrv_query($conn, $sqlAbonelikler);
    
    $stats = [
        'toplam' => 0,
        'basarili' => 0,
        'basarisiz' => 0,
        'atlanan' => 0,
        'degisiklik_yok' => 0
    ];
    
    while ($ab = sqlsrv_fetch_array($stmtAbonelikler, SQLSRV_FETCH_ASSOC)) {
        $stats['toplam']++;
        
        $abId = $ab['abonelik_id'];
        $aciklama = $ab['abonelik_aciklama'];
        $mevcutTarih = $ab['mevcut_tarih'];
        $cari = $ab['cari_adi'] ?? '-';
        
        // Domain ayıkla
        $domain = extractDomain($aciklama);
        
        if (!$domain) {
            logMessage("ID:$abId - Domain ayıklanamadı: '$aciklama'", 'WARNING');
            $stats['atlanan']++;
            continue;
        }
        
        // Atlanan uzantılar (.tr, .ge, .de gibi RDAP desteklemeyen)
        if (preg_match('/\.(tr|ge|de|ru|by|kz|az|am|md)$/i', $domain)) {
            logMessage("ID:$abId - $domain - Desteklenmeyen uzantı, atlanıyor");
            $stats['atlanan']++;
            continue;
        }
        
        // WHOIS sorgula
        logMessage("ID:$abId - $domain - WHOIS sorgulanıyor...");
        
        $whoisResult = getWhoisExpireDate($domain);
        
        if (!$whoisResult['success']) {
            logMessage("ID:$abId - $domain - WHOIS başarısız!", 'WARNING');
            $stats['basarisiz']++;
            continue;
        }
        
        $yeniTarih = $whoisResult['expire_date'];
        $kaynak = $whoisResult['source'];
        
        // Tarih değişti mi?
        if ($mevcutTarih === $yeniTarih) {
            logMessage("ID:$abId - $domain - Tarih aynı: $yeniTarih ($kaynak)");
            $stats['degisiklik_yok']++;
            continue;
        }
        
        // Güncelleme yap (test modunda yapma)
        if ($isTestMode) {
            logMessage("ID:$abId - $domain - [TEST] Güncellenecek: $mevcutTarih → $yeniTarih ($kaynak)");
            $stats['basarili']++;
        } else {
            $sqlUpdate = "
                UPDATE Abonelikler 
                SET abonelik_son_odeme_tarihi = ?,
                    abonelik_guncelleme_tarihi = GETDATE()
                WHERE abonelik_id = ?
            ";
            
            $params = [$yeniTarih, $abId];
            $stmtUpdate = sqlsrv_query($conn, $sqlUpdate, $params);
            
            if ($stmtUpdate) {
                logMessage("ID:$abId - $domain - Güncellendi: $mevcutTarih → $yeniTarih ($kaynak)", 'SUCCESS');
                $stats['basarili']++;
                sqlsrv_free_stmt($stmtUpdate);
            } else {
                logMessage("ID:$abId - $domain - Güncelleme hatası!", 'ERROR');
                $stats['basarisiz']++;
            }
        }
        
        // Rate limiting - API'leri yormamak için
        usleep(500000); // 0.5 saniye bekle
    }
    
    sqlsrv_free_stmt($stmtAbonelikler);
    sqlsrv_close($conn);
    
    // Özet
    logMessage('=== İşlem Tamamlandı ===');
    logMessage("Toplam: {$stats['toplam']}, Başarılı: {$stats['basarili']}, Başarısız: {$stats['basarisiz']}, Atlanan: {$stats['atlanan']}, Değişiklik Yok: {$stats['degisiklik_yok']}");
    
} catch (Exception $e) {
    logMessage("Kritik hata: " . $e->getMessage(), 'ERROR');
}

// Web çıktısı
if ($isWebRequest) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <title>Domain WHOIS Güncelleme <?= $isTestMode ? '(TEST)' : '(CRON)' ?></title>
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
                <i class="bi bi-globe"></i> Domain WHOIS Güncelleme 
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
            </div>
        </div>
    </body>
    </html>
    <?php
}
