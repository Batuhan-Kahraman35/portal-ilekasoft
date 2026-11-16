<?php
/**
 * Girişsiz fatura görüntüleme — pazaryerlerine verilen fatura linki.
 *   /fatura.php?k=<ETTN>      (web.config "Fatura PDF" kuralı: /fatura/<ETTN>.pdf de buraya düşer)
 *
 * Örnek Holding e-Fatura için girişsiz link vermediği için (e-Arşiv'de WEB_KEY var) pazaryeri
 * müşterisinin faturayı açabileceği adres buradan sunulur. Yalnız kesinleşmiş, giden ve bir
 * pazaryeri siparişine bağlı fatura gösterilir (FaturaKesim::girissizPdf); ETTN tahmin
 * edilemez UUID olduğu için erişim anahtarı yerine geçer.
 */

require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/includes/OrnekHoldingApi.php';
require_once __DIR__ . '/admin/includes/FaturaKesim.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');

try {
    $sonuc = (new FaturaKesim(1))->girissizPdf($_GET['k'] ?? '');
} catch (Throwable $e) {
    error_log('fatura.php: ' . $e->getMessage());
    $sonuc = ['success' => false, 'message' => 'Fatura şu anda görüntülenemiyor, lütfen daha sonra tekrar deneyin.'];
}

if (empty($sonuc['success'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $sonuc['message'];
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $sonuc['dosya_adi']) . '"');
header('Content-Length: ' . strlen($sonuc['pdf']));
echo $sonuc['pdf'];
