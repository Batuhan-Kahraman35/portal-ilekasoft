<?php
/**
 * Banka API Test Paneli
 * 
 * Tamamen bağımsız sayfa — header/footer/sidebar/auth YOK
 * Sadece db.php bağımlılığı var.
 * 
 * Erişim: ?token=degistir_panel_token
 * 
 * Desteklenen bankalar:
 *   ZIRAAT   - SOAP (WSDL) + kurumKod/sifre/vkn
 *   AKBANK   - cURL SOAP + HTTP Basic Auth
 *   HALKBANK - SOAP + WSSE UsernameToken
 *   ISBANK   - cURL POST + cookie session
 *   TEB      - cURL SOAP + custom XML
 *   QNBFINANS- SOAP 1.2 + kullanici/sifre
 *   YAPIKREDI- cURL + WS-Security Nonce
 */

// ─── GÜVENLİK ───────────────────────────────────────────────
define('PANEL_TOKEN', 'degistir_panel_token');

$givenToken = $_GET['token'] ?? $_POST['token'] ?? '';
if ($givenToken !== PANEL_TOKEN) {
    http_response_code(403);
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'mesaj' => 'Geçersiz token']);
    } else {
        echo '<h2 style="font-family:monospace;padding:40px;color:#c00">403 — Geçersiz veya eksik token.<br><small>?token=XXXX</small></h2>';
    }
    exit;
}
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/../db.php';
$db = Database::getInstance();

// ─── BANKA SABİTLERİ (define'lar POST/exit'ten önce tanımlanmalı) ───────────
define('AKBANK_ENDPOINT', 'https://firmahizmetleri.akbank.com/Extre_InterfaceService/Service.asmx');
define('AKBANK_NS',       'http://tempuri.org/');
define('ISBANK_LOGIN_URL','https://posmatik2.isbank.com.tr/Authenticate.aspx');
define('TEB_NS',          'http://prjwebservice/');
define('QNB_WSDL',        'https://fbmaestro.finansbank.com.tr/MaestroCoreEkstre/services/OrnekAkademiService?wsdl');
define('YKB_NS',          'http://intf.service.electronicaccountsummary.eho.hmn.ykb.com/');
// ─────────────────────────────────────────────────────────────────────────────

// ─── AJAX / JSON İSTEKLER ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ── Banka + API + Hesap listesi ──────────────────
            case 'bankalar':
                $apiKimlikleri = $db->fetchAll("
                    SELECT
                        ak.apiKimlik_id,
                        ak.apiKimlik_aciklama,
                        ak.apiKimlik_durum,
                        b.banka_adi,
                        b.banka_kodu,
                        CONVERT(VARCHAR(19), ak.apiKimlik_sonTestTarihi, 120) as sonTest,
                        ak.apiKimlik_sonTestSonucu
                    FROM banka_ApiKimlik ak
                    INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
                    ORDER BY b.banka_adi, ak.apiKimlik_id
                ");

                // bankaHesap_apiKimlik_id NULL olan hesaplar için banka_id üzerinden eşleştirme de yapılıyor
                $hesaplar = $db->fetchAll("
                    SELECT
                        bh.bankaHesap_id,
                        -- NULL ise aynı banka'nın tek API kimliğini ata (filtreleme için)
                        ISNULL(bh.bankaHesap_apiKimlik_id, ak_tek.apiKimlik_id) AS bankaHesap_apiKimlik_id,
                        bh.bankaHesap_iban,
                        bh.bankaHesap_no,
                        bh.bankaHesap_sube_adi,
                        bh.bankaHesap_sube_kodu,
                        bh.bankaHesap_durum,
                        b.banka_adi,
                        b.banka_kodu
                    FROM banka_Hesap bh
                    INNER JOIN bankalar b ON bh.bankaHesap_banka_id = b.banka_id
                    -- NULL apiKimlik_id durumunda banka'nın API kimliğini al
                    OUTER APPLY (
                        SELECT TOP 1 apiKimlik_id
                        FROM banka_ApiKimlik
                        WHERE apiKimlik_banka_id = bh.bankaHesap_banka_id
                          AND apiKimlik_durum = 1
                        ORDER BY apiKimlik_id
                    ) ak_tek
                    ORDER BY b.banka_adi, bh.bankaHesap_id
                ");

                echo json_encode([
                    'success'       => true,
                    'apiKimlikleri' => $apiKimlikleri,
                    'hesaplar'      => $hesaplar
                ]);
                break;

            // ── Bağlantı Testi ───────────────────────────────
            case 'testConnection':
                $apiKimlikId = (int)($_POST['apiKimlikId'] ?? 0);
                if (!$apiKimlikId) { echo json_encode(['success' => false, 'mesaj' => 'apiKimlikId gerekli']); break; }

                $api = getApiKimlik($db, $apiKimlikId);
                if (!$api) { echo json_encode(['success' => false, 'mesaj' => 'API kimlik bulunamadı']); break; }

                $baslangic = microtime(true);
                $sonuc = testConnection($api);
                $sonuc['sure'] = round(microtime(true) - $baslangic, 3);
                $sonuc['banka'] = $api['banka_adi'];
                $sonuc['bankaKodu'] = $api['banka_kodu'];

                echo json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;

            // ── Hesap Listesi ────────────────────────────────
            case 'getHesaplar':
                $apiKimlikId = (int)($_POST['apiKimlikId'] ?? 0);
                if (!$apiKimlikId) { echo json_encode(['success' => false, 'mesaj' => 'apiKimlikId gerekli']); break; }

                $api = getApiKimlik($db, $apiKimlikId);
                if (!$api) { echo json_encode(['success' => false, 'mesaj' => 'API kimlik bulunamadı']); break; }

                $baslangic = microtime(true);
                $sonuc = getHesaplar($api);
                $sonuc['sure'] = round(microtime(true) - $baslangic, 3);
                $sonuc['banka'] = $api['banka_adi'];

                echo json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;

            // ── Hesap Hareketleri ────────────────────────────
            case 'getHareketler':
                $apiKimlikId   = (int)($_POST['apiKimlikId'] ?? 0);
                $hesapId       = (int)($_POST['hesapId'] ?? 0);
                $baslangicTarih = $_POST['baslangicTarih'] ?? date('Y-m-d', strtotime('-7 days'));
                $bitisTarih    = $_POST['bitisTarih'] ?? date('Y-m-d');

                if (!$apiKimlikId) { echo json_encode(['success' => false, 'mesaj' => 'apiKimlikId gerekli']); break; }

                $api = getApiKimlik($db, $apiKimlikId);
                if (!$api) { echo json_encode(['success' => false, 'mesaj' => 'API kimlik bulunamadı']); break; }

                // Hesap bilgisi (opsiyonel - bazı bankalar IBAN ile gidiyor)
                $hesap = null;
                if ($hesapId) {
                    $hesap = $db->fetchOne("
                        SELECT * FROM banka_Hesap WHERE bankaHesap_id = ?
                    ", [$hesapId]);
                }

                $baslangic = microtime(true);
                $sonuc = getHareketler($api, $hesap, $baslangicTarih, $bitisTarih);
                $sonuc['sure'] = round(microtime(true) - $baslangic, 3);
                $sonuc['banka'] = $api['banka_adi'];

                echo json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;

            default:
                echo json_encode(['success' => false, 'mesaj' => 'Bilinmeyen action: ' . htmlspecialchars($action)]);
        }
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'mesaj'   => $e->getMessage(),
            'dosya'   => basename($e->getFile()) . ':' . $e->getLine()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════
// BANKA SERVİS FONKSİYONLARI (Tamamen bağımsız)
// ═════════════════════════════════════════════════════════════

function getApiKimlik(Database $db, int $id): ?array
{
    return $db->fetchOne("
        SELECT ak.*, b.banka_adi, b.banka_kodu
        FROM banka_ApiKimlik ak
        INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
        WHERE ak.apiKimlik_id = ?
    ", [$id]);
}

// ─── testConnection ──────────────────────────────────────────
function testConnection(array $api): array
{
    $kod = strtoupper(trim($api['banka_kodu']));

    switch ($kod) {

        case 'ZIRAAT':
        case '0001':
            return testZiraat($api);

        case 'AKBANK':
        case '0046':
            return testAkbank($api);

        case 'HALKBANK':
        case '0012':
            return testHalkbank($api);

        case 'ISBANK':
        case '0064':
            return testIsbank($api);

        case 'TEB':
        case '0032':
            return testTeb($api);

        case 'QNBFINANS':
        case '31316':
            return testQnb($api);

        case 'YAPIKREDI':
        case '0067':
            return testYapiKredi($api);

        default:
            return ['success' => false, 'mesaj' => "Desteklenmeyen banka kodu: $kod"];
    }
}

// ─── getHesaplar ─────────────────────────────────────────────
function getHesaplar(array $api): array
{
    $kod = strtoupper(trim($api['banka_kodu']));

    switch ($kod) {
        case 'ZIRAAT':   case '0001':   return hesaplarZiraat($api);
        case 'AKBANK':   case '0046':   return hesaplarDB($api, 'Akbank API\'da hesap listesi endpoint\'i yok');
        case 'HALKBANK': case '0012':   return hesaplarHalkbank($api);
        case 'ISBANK':   case '0064':   return hesaplarDB($api, 'İşbank API\'da hesap listesi yok');
        case 'TEB':      case '0032':   return hesaplarDB($api, 'TEB API\'da hesap listesi yok');
        case 'QNBFINANS':case '31316':  return hesaplarQnb($api);
        case 'YAPIKREDI':case '0067':   return hesaplarDB($api, 'YKB API\'da hesap listesi yok');
        default:
            return ['success' => false, 'mesaj' => "Desteklenmeyen banka: $kod"];
    }
}

// ─── getHareketler ───────────────────────────────────────────
function getHareketler(array $api, ?array $hesap, string $baslangic, string $bitis): array
{
    $kod = strtoupper(trim($api['banka_kodu']));

    switch ($kod) {
        case 'ZIRAAT':  case '0001':   return hareketlerZiraat($api, $hesap, $baslangic, $bitis);
        case 'AKBANK':  case '0046':   return hareketlerAkbank($api, $hesap, $baslangic, $bitis);
        case 'HALKBANK':case '0012':   return hareketlerHalkbank($api, $hesap, $baslangic, $bitis);
        case 'ISBANK':  case '0064':   return hareketlerIsbank($api, $hesap, $baslangic, $bitis);
        case 'TEB':     case '0032':   return hareketlerTeb($api, $hesap, $baslangic, $bitis);
        case 'QNBFINANS':case '31316': return hareketlerQnb($api, $hesap, $baslangic, $bitis);
        case 'YAPIKREDI':case '0067':  return hareketlerYapiKredi($api, $hesap, $baslangic, $bitis);
        default:
            return ['success' => false, 'mesaj' => "Desteklenmeyen banka: $kod"];
    }
}

// ─────────────────────────────────────────────────────────────
// hesaplarDB — API endpoint'i olmayan bankalar için DB fallback
// ─────────────────────────────────────────────────────────────
function hesaplarDB(array $api, string $not = ''): array
{
    $db       = Database::getInstance();
    $kimlikId = (int)($api['apiKimlik_id'] ?? 0);
    $bankaId  = (int)($api['apiKimlik_banka_id'] ?? 0);

    // Önce bu API kimliğine özel hesapları ara
    $hesaplar = $db->fetchAll("
        SELECT
            bankaHesap_id,
            bankaHesap_iban,
            bankaHesap_no,
            bankaHesap_sube_adi,
            bankaHesap_sube_kodu,
            bankaHesap_durum
        FROM banka_Hesap
        WHERE bankaHesap_apiKimlik_id = ?
        ORDER BY bankaHesap_id
    ", [$kimlikId]);

    // Sonuç yoksa (apiKimlik_id eşleşmesi yok) banka genelinde dön
    if (!$hesaplar && $bankaId) {
        $hesaplar = $db->fetchAll("
            SELECT
                bankaHesap_id,
                bankaHesap_iban,
                bankaHesap_no,
                bankaHesap_sube_adi,
                bankaHesap_sube_kodu,
                bankaHesap_durum
            FROM banka_Hesap
            WHERE bankaHesap_banka_id = ?
              AND (bankaHesap_apiKimlik_id IS NULL OR bankaHesap_apiKimlik_id = 0)
            ORDER BY bankaHesap_id
        ", [$bankaId]);
        $kaynak = 'banka geneli';
    } else {
        $kaynak = 'API kimliğe özgü';
    }

    $mesaj = count($hesaplar) . ' hesap bulundu (DB\'den — ' . $kaynak . ($not ? ' — ' . $not : '') . ')';
    return ['success' => true, 'mesaj' => $mesaj, 'data' => $hesaplar, 'count' => count($hesaplar)];
}

// ═════════════════════════════════════════════════════════════
// ZİRAAT BANKASI — SOAP (kurumKod / sifre / vkn)
// ═════════════════════════════════════════════════════════════
function ziraatSoapClient(array $api): \SoapClient
{
    $wsdl = $api['apiKimlik_wsdl'];
    if (empty($wsdl)) throw new \Exception('Ziraat WSDL tanımlı değil');

    $ctx = stream_context_create([
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        'http' => ['timeout' => 30]
    ]);
    return new \SoapClient($wsdl, [
        'trace'              => true,
        'exceptions'         => true,
        'cache_wsdl'         => WSDL_CACHE_NONE,
        'stream_context'     => $ctx,
        'connection_timeout' => 30,
        'soap_version'       => SOAP_1_1
    ]);
}

function testZiraat(array $api): array
{
    try {
        $client = ziraatSoapClient($api);
        $methods = $client->__getFunctions();
        return [
            'success' => true,
            'mesaj'   => 'SOAP bağlantısı başarılı — ' . count($methods) . ' method',
            'data'    => ['methodSayisi' => count($methods), 'ilkMethodlar' => array_slice($methods, 0, 5)]
        ];
    } catch (\SoapFault $e) {
        return ['success' => false, 'mesaj' => 'SOAP Hatası: ' . $e->getMessage()];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => 'Hata: ' . $e->getMessage()];
    }
}

function hesaplarZiraat(array $api): array
{
    try {
        $client = ziraatSoapClient($api);
        $response = $client->GetirTanimliHesapBilgileri([
            'kurumKod' => $api['apiKimlik_kurumKod'],
            'sifre'    => $api['apiKimlik_sifre'],
            'tcknVkn'  => $api['apiKimlik_vkn']
        ]);
        $result = $response->GetirTanimliHesapBilgileriResult ?? null;
        if (!$result) return ['success' => false, 'mesaj' => 'Yanıt boş'];
        $cevap = $result->CevapKodu ?? -1;
        if ($cevap != 0) return ['success' => false, 'mesaj' => "Banka hatası ($cevap): " . ($result->CevapMesaji ?? '?')];
        $liste = $result->KullaniciHesapBilgileri->KullaniciHesapBilgi ?? [];
        if (!is_array($liste)) $liste = [$liste];
        return ['success' => true, 'mesaj' => count($liste) . ' hesap', 'data' => json_decode(json_encode($liste), true), 'count' => count($liste)];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => $e->getMessage()];
    }
}

function hareketlerZiraat(array $api, ?array $hesap, string $bas, string $bit): array
{
    if (!$hesap) {
        $db    = \Database::getInstance();
        $liste = $db->fetchAll("
            SELECT * FROM banka_Hesap
            WHERE (bankaHesap_apiKimlik_id = ? OR bankaHesap_banka_id = (
                SELECT apiKimlik_banka_id FROM banka_ApiKimlik WHERE apiKimlik_id = ?
            ))
            AND ISNULL(LTRIM(RTRIM(bankaHesap_musteriNo)), '') != ''
            ORDER BY bankaHesap_id
        ", [$api['apiKimlik_id'], $api['apiKimlik_id']]);
        if (!$liste) return ['success' => false, 'mesaj' => 'Bu API kimliğe bağlı hesap bulunamadı. Hesap Listesi butonunu deneyin.'];
        $tumSonuclar = [];
        foreach ($liste as $h) {
            $sonuc = hareketlerZiraat($api, $h, $bas, $bit);
            $tumSonuclar[] = ['hesapId' => $h['bankaHesap_id'], 'hesapNo' => $h['bankaHesap_no'], 'iban' => $h['bankaHesap_iban'], 'sube' => $h['bankaHesap_sube_adi'], 'success' => $sonuc['success'], 'mesaj' => $sonuc['mesaj'], 'count' => $sonuc['count'] ?? 0, 'data' => $sonuc['data'] ?? []];
        }
        $toplam = array_sum(array_column($tumSonuclar, 'count'));
        return ['success' => true, 'mesaj' => count($liste) . ' hesap sorgulandı, toplam ' . $toplam . ' hareket', 'data' => $tumSonuclar];
    }
    $musteriNo = $hesap['bankaHesap_musteriNo'] ?? '';
    $ekNo      = $hesap['bankaHesap_ekNo'] ?? '';
    if (!$musteriNo) return ['success' => false, 'mesaj' => 'Bu hesapta MusteriNo tanımlı değil'];
    try {
        $client = ziraatSoapClient($api);
        $response = $client->SorgulaHesapHareket([
            'kurumKod'        => $api['apiKimlik_kurumKod'],
            'sifre'           => $api['apiKimlik_sifre'],
            'tcknVkn'         => $api['apiKimlik_vkn'],
            'musteriNo'       => $musteriNo,
            'ekNo'            => $ekNo,
            'baslangicTarihi' => str_replace('-', '', $bas),
            'bitisTarihi'     => str_replace('-', '', $bit)
        ]);
        $result = $response->SorgulaHesapHareketResult ?? null;
        if (!$result) return ['success' => false, 'mesaj' => 'Yanıt boş'];
        $cevap = $result->CevapKodu ?? -1;
        if ($cevap != 0) return ['success' => false, 'mesaj' => "Banka hatası ($cevap): " . ($result->CevapMesaji ?? '?')];
        $hareketler = $result->HesapHareketleri->HesapHareket ?? [];
        if (!is_array($hareketler)) $hareketler = [$hareketler];
        return ['success' => true, 'mesaj' => count($hareketler) . ' hareket', 'data' => json_decode(json_encode($hareketler), true), 'count' => count($hareketler)];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => $e->getMessage()];
    }
}

// ═════════════════════════════════════════════════════════════
// AKBANK — cURL SOAP + HTTP Basic Auth
// ═════════════════════════════════════════════════════════════
function akbankSoap(array $api, string $action, string $body): array
{
    $auth  = 'Basic ' . base64_encode($api['apiKimlik_kurumKod'] . ':' . $api['apiKimlik_sifre']);
    $xml   = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="' . AKBANK_NS . '">
  <soap:Body>' . $body . '</soap:Body>
</soap:Envelope>';

    $ch = curl_init(AKBANK_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . AKBANK_NS . $action . '"',
            'Authorization: ' . $auth
        ],
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $code, 'error' => $err];
}

function testAkbank(array $api): array
{
    $body = '<tem:GetExtre><tem:urf>TEST</tem:urf><tem:hesapNo>000000</tem:hesapNo><tem:dovizKodu>YTL</tem:dovizKodu><tem:subeKodu>00</tem:subeKodu></tem:GetExtre>';
    $r    = akbankSoap($api, 'GetExtre', $body);
    if ($r['error']) return ['success' => false, 'mesaj' => 'cURL: ' . $r['error']];
    if ($r['httpCode'] == 200) return ['success' => true, 'mesaj' => 'Akbank endpoint erişilebilir (HTTP 200)', 'data' => ['endpoint' => AKBANK_ENDPOINT]];
    return ['success' => false, 'mesaj' => 'HTTP ' . $r['httpCode']];
}

function hareketlerAkbank(array $api, ?array $hesap, string $bas, string $bit): array
{
    if (!$hesap) {
        $db    = \Database::getInstance();
        $liste = $db->fetchAll("
            SELECT * FROM banka_Hesap
            WHERE (bankaHesap_apiKimlik_id = ? OR bankaHesap_banka_id = (
                SELECT apiKimlik_banka_id FROM banka_ApiKimlik WHERE apiKimlik_id = ?
            ))
            AND ISNULL(LTRIM(RTRIM(bankaHesap_no)), '') != ''
            ORDER BY bankaHesap_id
        ", [$api['apiKimlik_id'], $api['apiKimlik_id']]);
        if (!$liste) return ['success' => false, 'mesaj' => 'Bu API kimliğe bağlı hesap bulunamadı. Hesap Listesi butonunu deneyin.'];
        $tumSonuclar = [];
        foreach ($liste as $h) {
            $sonuc = hareketlerAkbank($api, $h, $bas, $bit);
            $tumSonuclar[] = ['hesapId' => $h['bankaHesap_id'], 'hesapNo' => $h['bankaHesap_no'], 'iban' => $h['bankaHesap_iban'], 'sube' => $h['bankaHesap_sube_adi'], 'success' => $sonuc['success'], 'mesaj' => $sonuc['mesaj'], 'count' => $sonuc['count'] ?? 0, 'data' => $sonuc['data'] ?? []];
        }
        $toplam = array_sum(array_column($tumSonuclar, 'count'));
        return ['success' => true, 'mesaj' => count($liste) . ' hesap sorgulandı, toplam ' . $toplam . ' hareket', 'data' => $tumSonuclar];
    }

    $urf      = $hesap['bankaHesap_musteriNo'] ?? $hesap['bankaHesap_no'] ?? '';
    $hesapNo  = $hesap['bankaHesap_no'] ?? '';
    $subeKodu = $hesap['bankaHesap_sube_kodu'] ?? '00';

    if (!$urf || !$hesapNo) return ['success' => false, 'mesaj' => 'Hesap no veya URF tanımlı değil'];

    $body = '<tem:GetExtre>
      <tem:urf>' . htmlspecialchars($urf) . '</tem:urf>
      <tem:hesapNo>' . htmlspecialchars($hesapNo) . '</tem:hesapNo>
      <tem:dovizKodu>YTL</tem:dovizKodu>
      <tem:subeKodu>' . htmlspecialchars($subeKodu) . '</tem:subeKodu>
    </tem:GetExtre>';

    $r = akbankSoap($api, 'GetExtre', $body);
    if ($r['error']) return ['success' => false, 'mesaj' => 'cURL: ' . $r['error']];
    if ($r['httpCode'] != 200) return ['success' => false, 'mesaj' => 'HTTP ' . $r['httpCode'], 'raw' => substr($r['response'], 0, 500)];

    // İşlem satırlarını say
    preg_match_all('/<islemTarihi>/', $r['response'], $m);
    $sayi = count($m[0]);
    return ['success' => true, 'mesaj' => "~$sayi hareket (ham XML)", 'data' => ['raw_preview' => substr($r['response'], 0, 1000)]];
}

// ═════════════════════════════════════════════════════════════
// HALKBANK — SOAP + WSSE UsernameToken (SHA1 Nonce)
// ═════════════════════════════════════════════════════════════
function halkbankSoapClient(array $api): \SoapClient
{
    $wsdl = $api['apiKimlik_wsdl'] ?? 'https://webservice.halkbank.com.tr/HesapEkstreOrtakWS/HesapEkstreOrtak.svc?wsdl';
    $ctx  = stream_context_create([
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        'http' => ['timeout' => 60]
    ]);
    $client = new \SoapClient($wsdl, [
        'trace'              => true,
        'exceptions'         => true,
        'cache_wsdl'         => WSDL_CACHE_NONE,
        'stream_context'     => $ctx,
        'connection_timeout' => 60
    ]);

    // WSSE Header — SHA1 nonce (Halkbank resmi örneği)
    $wssNs    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    $wsuNs    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    $passType = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';
    $nonceType= 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    $user    = $api['apiKimlik_kullanici'];
    $pass    = $api['apiKimlik_sifre'];
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $nonce   = mt_rand();
    $encodedNonce = base64_encode(pack('H*', sha1(pack('H*', $nonce) . pack('a*', $created) . pack('a*', $pass))));

    $root     = new \SimpleXMLElement('<root/>');
    $security = $root->addChild('wsse:Security', null, $wssNs);
    $token    = $security->addChild('wsse:UsernameToken', null, $wssNs);
    $token->addChild('wsse:Username', $user, $wssNs);
    $passNode = $token->addChild('wsse:Password', htmlspecialchars($pass, ENT_XML1, 'UTF-8'), $wssNs);
    $passNode->addAttribute('Type', $passType);
    $nonceNode = $token->addChild('wsse:Nonce', $encodedNonce, $wssNs);
    $nonceNode->addAttribute('EncodingType', $nonceType);
    $token->addChild('wsu:Created', $created, $wsuNs);

    $root->registerXPathNamespace('wsse', $wssNs);
    $secXml = $root->xpath('/root/wsse:Security');
    $header = new \SoapHeader($wssNs, 'Security', new \SoapVar($secXml[0]->asXML(), XSD_ANYXML), true);
    $client->__setSoapHeaders([$header]);

    return $client;
}

function testHalkbank(array $api): array
{
    try {
        $client  = halkbankSoapClient($api);
        $methods = $client->__getFunctions();
        return [
            'success' => true,
            'mesaj'   => 'SOAP + WSSE bağlantısı başarılı — ' . count($methods) . ' method',
            'data'    => ['methodSayisi' => count($methods), 'metodlar' => $methods]
        ];
    } catch (\SoapFault $e) {
        return ['success' => false, 'mesaj' => 'SOAP: ' . $e->getMessage()];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => $e->getMessage()];
    }
}

function hesaplarHalkbank(array $api): array
{
    // Halkbank API'sinde hesap listesi endpoint'i yok — DB'den çekiliyor
    $db       = \Database::getInstance();
    $kimlikId = (int)$api['apiKimlik_id'];

    $hesaplar = $db->fetchAll("
        SELECT
            bankaHesap_id,
            bankaHesap_iban,
            bankaHesap_no,
            bankaHesap_sube_adi,
            bankaHesap_sube_kodu,
            bankaHesap_durum
        FROM banka_Hesap
        WHERE bankaHesap_apiKimlik_id = ?
          AND ISNULL(LTRIM(RTRIM(bankaHesap_iban)), '') != ''
        ORDER BY bankaHesap_id
    ", [$kimlikId]);

    return [
        'success' => true,
        'mesaj'   => count($hesaplar) . ' hesap (DB\'den — Halkbank API hesap listesi desteklemiyor)',
        'data'    => $hesaplar,
        'count'   => count($hesaplar)
    ];
}

function hareketlerHalkbank(array $api, ?array $hesap, string $bas, string $bit): array
{
    // Hesap seçilmemişse bu API kimliğine bağlı tüm aktif hesapları DB'den çek
    if (!$hesap) {
        $db    = \Database::getInstance();
        $liste = $db->fetchAll("
            SELECT * FROM banka_Hesap
            WHERE (bankaHesap_apiKimlik_id = ? OR bankaHesap_banka_id = (
                SELECT apiKimlik_banka_id FROM banka_ApiKimlik WHERE apiKimlik_id = ?
            ))
            AND ISNULL(LTRIM(RTRIM(bankaHesap_no)), '') != ''
            ORDER BY bankaHesap_id
        ", [$api['apiKimlik_id'], $api['apiKimlik_id']]);

        if (!$liste) {
            return ['success' => false, 'mesaj' => 'Bu API kimliğe bağlı hesap bulunamadı. Hesap Listesi butonunu deneyin.'];
        }

        // Tüm hesaplar için hareketleri topla
        $tumSonuclar = [];
        foreach ($liste as $h) {
            $sonuc = hareketlerHalkbank($api, $h, $bas, $bit);
            $tumSonuclar[] = [
                'hesapId'   => $h['bankaHesap_id'],
                'hesapNo'   => $h['bankaHesap_no'],
                'iban'      => $h['bankaHesap_iban'],
                'sube'      => $h['bankaHesap_sube_adi'],
                'success'   => $sonuc['success'],
                'mesaj'     => $sonuc['mesaj'],
                'count'     => $sonuc['count'] ?? 0,
                'data'      => $sonuc['data'] ?? []
            ];
        }
        $toplam = array_sum(array_column($tumSonuclar, 'count'));
        return ['success' => true, 'mesaj' => count($liste) . ' hesap sorgulandı, toplam ' . $toplam . ' hareket', 'data' => $tumSonuclar];
    }

    $hesapNo = $hesap['bankaHesap_no'] ?? '';
    $subeKodu = $hesap['bankaHesap_sube_kodu'] ?? '';

    if (!$hesapNo) return ['success' => false, 'mesaj' => 'Hesap no tanımlı değil'];

    // "9731-31202449-10261072" formatındaysa sadece son kısmı al
    $parts   = explode('-', $hesapNo);
    $hesapNo = end($parts);

    try {
        $client = halkbankSoapClient($api);
        $response = $client->EkstreSorgulama([
            'request' => [
                'HesapNo'         => $hesapNo,
                'SubeKodu'        => (int)$subeKodu,
                'BaslangicTarihi' => date('Y-m-d', strtotime($bas)),
                'BitisTarihi'     => date('Y-m-d', strtotime($bit))
            ]
        ]);

        $result = $response->EkstreSorgulamaResult ?? null;
        if (!$result) return ['success' => false, 'mesaj' => 'EkstreSorgulamaResult boş'];

        $hataKodu = isset($result->HataKodu) ? (string)$result->HataKodu : '0';
        if ($hataKodu !== '0' && $hataKodu !== '') {
            $hataMesaji = (isset($result->HataAciklama) && $result->HataAciklama !== '')
                ? $result->HataAciklama : "Bilinmeyen hata (Kod: $hataKodu)";
            return ['success' => false, 'mesaj' => "Banka Hatası: $hataMesaji"];
        }

        $hesapBilgi = $result->Hesaplar->Hesap ?? null;
        if (!$hesapBilgi) {
            return ['success' => true, 'mesaj' => '0 hareket (boş dönem)', 'data' => [], 'count' => 0];
        }

        $hareketData = $hesapBilgi->Hareketler->Hareket ?? [];
        if (!is_array($hareketData)) $hareketData = [$hareketData];

        $hareketler = [];
        foreach ($hareketData as $h) {
            if (!is_object($h)) continue;
            $tutarStr = $h->HareketTutari ?? '0';
            $tutar    = (float)str_replace(['+', '.', ','], ['', '', '.'], $tutarStr);
            $tarihStr = $h->Tarih ?? null;
            $tarih    = null;
            if ($tarihStr) {
                $p = explode('/', $tarihStr);
                if (count($p) === 3) $tarih = "$p[2]-$p[1]-$p[0]";
            }
            $hareketler[] = [
                'tarih'        => $tarih . ' ' . ($h->Saat ?? '00:00:00'),
                'dekontNo'     => $h->DekontNo ?? '',
                'aciklama'     => $h->EkstreAciklama ?? $h->Aciklama ?? '',
                'tutar'        => $tutar,
                'bakiye'       => (float)str_replace(['+', '.', ','], ['', '', '.'], $h->Bakiye ?? '0'),
                'karsiIban'    => $h->KarsiHesapIBAN ?? null,
                'karsiAd'      => $h->KarsiAdSoyad ?? null,
            ];
        }

        return ['success' => true, 'mesaj' => count($hareketler) . ' hareket', 'data' => $hareketler, 'count' => count($hareketler)];

    } catch (\SoapFault $e) {
        return ['success' => false, 'mesaj' => 'SOAP: ' . $e->getMessage()];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => $e->getMessage()];
    }
}

// ═════════════════════════════════════════════════════════════
// İŞ BANKASI — cURL POST + cookie session
// ═════════════════════════════════════════════════════════════
function isbankLogin(array $api, string $bas, string $bit): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => ISBANK_LOGIN_URL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'uid'       => $api['apiKimlik_kullanici'],
            'pwd'       => $api['apiKimlik_sifre'],
            'BeginDate' => date('d.m.Y', strtotime($bas)) . ' 00:00:00',
            'EndDate'   => date('d.m.Y', strtotime($bit)) . ' 23:59:59'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ]
    ]);
    $response   = curl_exec($ch);
    $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err        = curl_error($ch);
    curl_close($ch);

    if ($err) return ['success' => false, 'mesaj' => 'cURL: ' . $err];

    $headers = substr($response, 0, $headerSize);
    $body    = substr($response, $headerSize);
    preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', $headers, $m);
    $cookies = implode('; ', $m[1] ?? []);

    return ['success' => true, 'body' => $body, 'cookies' => $cookies, 'httpCode' => $code];
}

function testIsbank(array $api): array
{
    $result = isbankLogin($api, date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
    if (!$result['success']) return $result;
    $body = $result['body'] ?? '';
    if (stripos($body, 'xml') !== false || stripos($body, 'Hareket') !== false || stripos($body, 'hesap') !== false) {
        return ['success' => true, 'mesaj' => 'İşbank login başarılı, XML veri alındı', 'data' => ['httpCode' => $result['httpCode'], 'bodyPreview' => substr($body, 0, 300)]];
    }
    if (stripos($body, 'hata') !== false || stripos($body, 'error') !== false || $result['httpCode'] >= 400) {
        return ['success' => false, 'mesaj' => 'Login başarısız', 'data' => ['httpCode' => $result['httpCode'], 'bodyPreview' => substr($body, 0, 300)]];
    }
    return ['success' => true, 'mesaj' => 'Bağlantı kuruldu (HTTP ' . $result['httpCode'] . ')', 'data' => ['bodyPreview' => substr($body, 0, 300)]];
}

function hareketlerIsbank(array $api, ?array $hesap, string $bas, string $bit): array
{
    $result = isbankLogin($api, $bas, $bit);
    if (!$result['success']) return $result;
    $body = $result['body'] ?? '';
    // XML hareket satırlarını say
    preg_match_all('/<Hareket>|<hareket>|<Transaction>/i', $body, $m);
    $sayi = count($m[0]);
    return ['success' => true, 'mesaj' => "~$sayi hareket (ham XML)", 'data' => ['raw_preview' => substr($body, 0, 1500), 'httpCode' => $result['httpCode']]];
}

// ═════════════════════════════════════════════════════════════
// TEB — cURL SOAP + custom XML (InputDataXML)
// ═════════════════════════════════════════════════════════════
function tebSoap(array $api, string $inputXml): array
{
    $endpoint  = $api['apiKimlik_endpoint'] ?? 'https://extws.teb.com.tr/heshar/HesHarSrv';
    $ekAyarlar = json_decode($api['apiKimlik_ekAyarlar'] ?? '{}', true) ?: [];
    $encoded   = htmlspecialchars($inputXml, ENT_XML1, 'UTF-8');

    $soapXml = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:prj="' . TEB_NS . '">
  <soapenv:Header/>
  <soapenv:Body>
    <prj:TEBWebSrv>
      <prj:UserName>' . htmlspecialchars($api['apiKimlik_kullanici']) . '</prj:UserName>
      <prj:Password>' . htmlspecialchars($api['apiKimlik_sifre']) . '</prj:Password>
      <prj:ServiceID>' . htmlspecialchars($ekAyarlar['ServisID'] ?? '') . '</prj:ServiceID>
      <prj:Environment>' . htmlspecialchars($ekAyarlar['Environment'] ?? 'P') . '</prj:Environment>
      <prj:InputDataXML>' . $encoded . '</prj:InputDataXML>
    </prj:TEBWebSrv>
  </soapenv:Body>
</soapenv:Envelope>';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $soapXml,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $code, 'error' => $err];
}

function testTeb(array $api): array
{
    $ekAyarlar = json_decode($api['apiKimlik_ekAyarlar'] ?? '{}', true) ?: [];
    $inputXml  = '<ISTEK><FIRM_AD>' . htmlspecialchars($ekAyarlar['FirmaAdi'] ?? 'TEST') . '</FIRM_AD></ISTEK>';
    $r = tebSoap($api, $inputXml);
    if ($r['error']) return ['success' => false, 'mesaj' => 'cURL: ' . $r['error']];
    if ($r['httpCode'] == 200) {
        preg_match('/<ErrorCode>([^<]+)<\/ErrorCode>/', $r['response'], $m);
        $errCode = $m[1] ?? '';
        if ($errCode === '00' || $errCode === '') return ['success' => true, 'mesaj' => 'TEB endpoint erişilebilir', 'data' => ['httpCode' => 200]];
        return ['success' => false, 'mesaj' => "TEB hata kodu: $errCode", 'data' => ['raw' => substr($r['response'], 0, 500)]];
    }
    return ['success' => false, 'mesaj' => 'HTTP ' . $r['httpCode']];
}

function hareketlerTeb(array $api, ?array $hesap, string $bas, string $bit): array
{
    if (!$hesap) {
        $db    = \Database::getInstance();
        $liste = $db->fetchAll("
            SELECT * FROM banka_Hesap
            WHERE (bankaHesap_apiKimlik_id = ? OR bankaHesap_banka_id = (
                SELECT apiKimlik_banka_id FROM banka_ApiKimlik WHERE apiKimlik_id = ?
            ))
            AND ISNULL(LTRIM(RTRIM(bankaHesap_no)), '') != ''
            ORDER BY bankaHesap_id
        ", [$api['apiKimlik_id'], $api['apiKimlik_id']]);
        if (!$liste) return ['success' => false, 'mesaj' => 'Bu API kimliğe bağlı hesap bulunamadı. Hesap Listesi butonunu deneyin.'];
        $tumSonuclar = [];
        foreach ($liste as $h) {
            $sonuc = hareketlerTeb($api, $h, $bas, $bit);
            $tumSonuclar[] = ['hesapId' => $h['bankaHesap_id'], 'hesapNo' => $h['bankaHesap_no'], 'iban' => $h['bankaHesap_iban'], 'sube' => $h['bankaHesap_sube_adi'], 'success' => $sonuc['success'], 'mesaj' => $sonuc['mesaj'], 'count' => $sonuc['count'] ?? 0, 'data' => $sonuc['data'] ?? []];
        }
        $toplam = array_sum(array_column($tumSonuclar, 'count'));
        return ['success' => true, 'mesaj' => count($liste) . ' hesap sorgulandı, toplam ' . $toplam . ' hareket', 'data' => $tumSonuclar];
    }
    $hesapNo  = $hesap['bankaHesap_no'] ?? '';
    $subeKodu = $hesap['bankaHesap_sube_kodu'] ?? '';
    if (!$hesapNo) return ['success' => false, 'mesaj' => 'Hesap no tanımlı değil'];

    $ekAyarlar = json_decode($api['apiKimlik_ekAyarlar'] ?? '{}', true) ?: [];
    $iban      = $hesap['bankaHesap_iban'] ?? '';

    $inputXml = '<ISTEK>
  <FIRM_AD>' . htmlspecialchars($ekAyarlar['FirmaAdi'] ?? '') . '</FIRM_AD>
  <FIRM_KEY>' . htmlspecialchars($ekAyarlar['FirmaAnahtari'] ?? '') . '</FIRM_KEY>
  <HESH_NO>' . htmlspecialchars($hesapNo) . '</HESH_NO>
  <SUBE_NO>' . htmlspecialchars($subeKodu) . '</SUBE_NO>
  <IBAN>' . htmlspecialchars(str_replace(' ', '', $iban)) . '</IBAN>
  <BAL_TAR>' . date('Ymd', strtotime($bas)) . '</BAL_TAR>
  <BIT_TAR>' . date('Ymd', strtotime($bit)) . '</BIT_TAR>
</ISTEK>';

    $r = tebSoap($api, $inputXml);
    if ($r['error']) return ['success' => false, 'mesaj' => 'cURL: ' . $r['error']];
    if ($r['httpCode'] != 200) return ['success' => false, 'mesaj' => 'HTTP ' . $r['httpCode']];

    preg_match('/<ErrorCode>([^<]+)<\/ErrorCode>/', $r['response'], $em);
    $errCode = $em[1] ?? '';
    if ($errCode !== '00' && $errCode !== '') return ['success' => false, 'mesaj' => "TEB hata: $errCode", 'data' => ['raw' => substr($r['response'], 0, 800)]];

    preg_match_all('/<HAREKET>|<HesapHareket>/i', $r['response'], $m);
    $sayi = count($m[0]);
    return ['success' => true, 'mesaj' => "~$sayi hareket", 'data' => ['raw_preview' => substr($r['response'], 0, 1500)]];
}

// ═════════════════════════════════════════════════════════════
// QNB FİNANSBANK — SOAP 1.2 + kullanici/sifre
// ═════════════════════════════════════════════════════════════
function qnbSoapClient(array $api): \SoapClient
{
    return new \SoapClient(QNB_WSDL, [
        'trace'              => true,
        'exceptions'         => true,
        'cache_wsdl'         => WSDL_CACHE_BOTH,
        'soap_version'       => SOAP_1_2,
        'location'           => $api['apiKimlik_endpoint'],
        'connection_timeout' => 15,
        'default_socket_timeout' => 60,
        'stream_context'     => stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
    ]);
}

function qnbCall(array $api, ?string $iban = null, ?string $bas = null, ?string $bit = null): array
{
    $client = qnbSoapClient($api);
    $params = new \stdClass();
    $params->transactionInfo = new \stdClass();
    $params->transactionInfo->password = $api['apiKimlik_sifre'];
    $params->transactionInfo->userName = $api['apiKimlik_kullanici'];
    if ($iban || $bas || $bit) {
        $params->transactionInfo->transactionInfoInputType = new \stdClass();
        if ($iban) $params->transactionInfo->transactionInfoInputType->iban = str_replace(' ', '', $iban);
        if ($bas)  $params->transactionInfo->transactionInfoInputType->startDate = $bas . 'T00:00:00';
        if ($bit)  $params->transactionInfo->transactionInfoInputType->endDate   = $bit . 'T23:59:59';
    }
    return (array)$client->getTransactionInfo($params);
}

function testQnb(array $api): array
{
    try {
        $result = qnbCall($api);
        $ret = $result['return'] ?? null;
        if (!$ret) return ['success' => false, 'mesaj' => 'Yanıt boş'];
        $errCode = $ret->errorCode ?? '';
        $errDesc = $ret->errorDescription ?? '';
        $ok = in_array($errCode, ['0', '', 'EHS01']);
        return ['success' => $ok, 'mesaj' => $ok ? 'QNB bağlantısı başarılı' : "Hata ($errCode): $errDesc", 'data' => ['errorCode' => $errCode, 'errorDescription' => $errDesc]];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => $e->getMessage()];
    }
}

function hesaplarQnb(array $api): array
{
    try {
        $result = qnbCall($api);
        $ret = $result['return'] ?? null;
        if (!$ret) return ['success' => false, 'mesaj' => 'Yanıt boş'];
        $hesaplar = $ret->accountList ?? [];
        if (!is_array($hesaplar)) $hesaplar = [$hesaplar];
        return ['success' => true, 'mesaj' => count($hesaplar) . ' hesap', 'data' => json_decode(json_encode($hesaplar), true), 'count' => count($hesaplar)];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => $e->getMessage()];
    }
}

function hareketlerQnb(array $api, ?array $hesap, string $bas, string $bit): array
{
    $iban = $hesap ? ($hesap['bankaHesap_iban'] ?? null) : null;
    try {
        $result = qnbCall($api, $iban, $bas, $bit);
        $ret = $result['return'] ?? null;
        if (!$ret) return ['success' => false, 'mesaj' => 'Yanıt boş'];
        $errCode = $ret->errorCode ?? '';
        if (!in_array($errCode, ['0', '', 'EHS01'])) return ['success' => false, 'mesaj' => "Hata ($errCode): " . ($ret->errorDescription ?? '')];
        $hareketler = $ret->transactionInfoList ?? [];
        if (!is_array($hareketler)) $hareketler = [$hareketler];
        return ['success' => true, 'mesaj' => count($hareketler) . ' hareket', 'data' => json_decode(json_encode($hareketler), true), 'count' => count($hareketler)];
    } catch (\Exception $e) {
        return ['success' => false, 'mesaj' => $e->getMessage()];
    }
}

// ═════════════════════════════════════════════════════════════
// YAPI KREDİ — cURL + WS-Security Nonce
// ═════════════════════════════════════════════════════════════
function ykbSoap(array $api, string $arg0Content): array
{
    $nonce    = base64_encode(random_bytes(16));
    $created  = gmdate('Y-m-d\TH:i:s\Z');
    $username = $api['apiKimlik_kurumKod'] ?? '';
    $password = $api['apiKimlik_sifre'] ?? '';
    $endpoint = $api['apiKimlik_endpoint'] ?? 'https://dpextprd.yapikredi.com.tr:443/Hmn/EhoAccountTransactionService';

    $xml = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="' . YKB_NS . '">
  <soap:Header>
    <wsse:Security soap:mustUnderstand="1"
      xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"
      xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>' . htmlspecialchars($username) . '</wsse:Username>
        <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">' . htmlspecialchars($password) . '</wsse:Password>
        <wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">' . $nonce . '</wsse:Nonce>
        <wsu:Created>' . $created . '</wsu:Created>
      </wsse:UsernameToken>
    </wsse:Security>
  </soap:Header>
  <soap:Body>
    <ns:sorgula><arg0>' . $arg0Content . '</arg0></ns:sorgula>
  </soap:Body>
</soap:Envelope>';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8'],
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'httpCode' => $code, 'error' => $err];
}

function testYapiKredi(array $api): array
{
    $arg0 = '<firmaKodu>' . htmlspecialchars($api['apiKimlik_kullanici'] ?? '') . '</firmaKodu><sorguTipi>H</sorguTipi>';
    $r = ykbSoap($api, $arg0);
    if ($r['error']) return ['success' => false, 'mesaj' => 'cURL: ' . $r['error']];
    if ($r['httpCode'] == 200) {
        preg_match('/<errorCode>([^<]+)<\/errorCode>/i', $r['response'], $m);
        $ec = $m[1] ?? '';
        $ok = in_array($ec, ['', '0', '00', null]);
        return ['success' => $ok, 'mesaj' => $ok ? 'YKB bağlantısı başarılı' : "Hata kodu: $ec", 'data' => ['httpCode' => 200, 'errorCode' => $ec, 'preview' => substr($r['response'], 0, 400)]];
    }
    return ['success' => false, 'mesaj' => 'HTTP ' . $r['httpCode'], 'data' => ['preview' => substr($r['response'], 0, 400)]];
}

function hareketlerYapiKredi(array $api, ?array $hesap, string $bas, string $bit): array
{
    if (!$hesap) {
        $db    = \Database::getInstance();
        $liste = $db->fetchAll("
            SELECT * FROM banka_Hesap
            WHERE (bankaHesap_apiKimlik_id = ? OR bankaHesap_banka_id = (
                SELECT apiKimlik_banka_id FROM banka_ApiKimlik WHERE apiKimlik_id = ?
            ))
            AND ISNULL(LTRIM(RTRIM(bankaHesap_no)), '') != ''
            ORDER BY bankaHesap_id
        ", [$api['apiKimlik_id'], $api['apiKimlik_id']]);
        if (!$liste) return ['success' => false, 'mesaj' => 'Bu API kimliğe bağlı hesap bulunamadı. Hesap Listesi butonunu deneyin.'];
        $tumSonuclar = [];
        foreach ($liste as $h) {
            $sonuc = hareketlerYapiKredi($api, $h, $bas, $bit);
            $tumSonuclar[] = ['hesapId' => $h['bankaHesap_id'], 'hesapNo' => $h['bankaHesap_no'], 'iban' => $h['bankaHesap_iban'], 'sube' => $h['bankaHesap_sube_adi'], 'success' => $sonuc['success'], 'mesaj' => $sonuc['mesaj'], 'count' => $sonuc['count'] ?? 0, 'data' => $sonuc['data'] ?? []];
        }
        $toplam = array_sum(array_column($tumSonuclar, 'count'));
        return ['success' => true, 'mesaj' => count($liste) . ' hesap sorgulandı, toplam ' . $toplam . ' hareket', 'data' => $tumSonuclar];
    }
    $hesapNo  = $hesap['bankaHesap_no'] ?? '';
    $subeKodu = $hesap['bankaHesap_sube_kodu'] ?? '';
    if (!$hesapNo || !$subeKodu) return ['success' => false, 'mesaj' => 'Hesap no veya şube kodu tanımlı değil'];

    $arg0 = '<firmaKodu>' . htmlspecialchars($api['apiKimlik_kullanici'] ?? '') . '</firmaKodu>
<sorguTipi>H</sorguTipi>
<hesapNo>' . htmlspecialchars($hesapNo) . '</hesapNo>
<subeKodu>' . htmlspecialchars($subeKodu) . '</subeKodu>
<basTar>' . date('d/m/Y', strtotime($bas)) . '</basTar>
<bitTar>' . date('d/m/Y', strtotime($bit)) . '</bitTar>';

    $r = ykbSoap($api, $arg0);
    if ($r['error']) return ['success' => false, 'mesaj' => 'cURL: ' . $r['error']];
    if ($r['httpCode'] != 200) return ['success' => false, 'mesaj' => 'HTTP ' . $r['httpCode']];

    preg_match_all('/<hareket>|<Hareket>|<transaction>/i', $r['response'], $m);
    $sayi = count($m[0]);
    return ['success' => true, 'mesaj' => "~$sayi hareket", 'data' => ['raw_preview' => substr($r['response'], 0, 1500), 'httpCode' => $r['httpCode']]];
}

// ═════════════════════════════════════════════════════════════
// HTML ARAYÜZ
// ═════════════════════════════════════════════════════════════
$token = htmlspecialchars(PANEL_TOKEN);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🏦 Banka API Test Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background: #0d1117; color: #c9d1d9; font-family: 'Consolas', monospace; }
  .panel-header { background: linear-gradient(135deg, #161b22, #1f2937); border-bottom: 1px solid #30363d; padding: 16px 24px; }
  .panel-header h1 { color: #58a6ff; font-size: 1.4rem; margin: 0; }
  .sidebar { background: #161b22; border-right: 1px solid #30363d; height: calc(100vh - 60px); overflow-y: auto; }
  .sidebar .bank-item { border-bottom: 1px solid #21262d; cursor: pointer; padding: 10px 16px; transition: background 0.15s; }
  .sidebar .bank-item:hover { background: #1f2937; }
  .sidebar .bank-item.active { background: #1f6feb22; border-left: 3px solid #58a6ff; }
  .sidebar .bank-name { color: #e6edf3; font-size: 0.85rem; font-weight: 600; }
  .sidebar .bank-meta { color: #8b949e; font-size: 0.72rem; }
  .badge-durum-1 { background: #238636; color: #fff; }
  .badge-durum-0 { background: #6e7681; color: #fff; }
  .main-panel { background: #0d1117; padding: 20px; height: calc(100vh - 60px); overflow-y: auto; }
  .request-bar { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 16px; }
  .method-badge { background: #1f6feb; color: #fff; padding: 3px 10px; border-radius: 4px; font-size: 0.8rem; font-weight: 700; }
  .url-display { color: #58a6ff; font-size: 0.85rem; margin-left: 10px; }
  .action-btn { font-size: 0.8rem; }
  .response-area { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 0; min-height: 300px; }
  .response-header { background: #21262d; border-bottom: 1px solid #30363d; padding: 10px 16px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; }
  .response-body { padding: 16px; font-size: 0.8rem; max-height: 500px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; }
  .status-ok { color: #56d364; }
  .status-err { color: #f85149; }
  .status-wait { color: #e3b341; }
  .loading-spinner { display: none; }
  .params-row label { color: #8b949e; font-size: 0.78rem; margin-bottom: 2px; }
  .params-row .form-control, .params-row .form-select {
    background: #21262d; border: 1px solid #30363d; color: #c9d1d9; font-size: 0.82rem;
  }
  .params-row .form-control:focus, .params-row .form-select:focus {
    background: #262c36; border-color: #58a6ff; box-shadow: none; color: #e6edf3;
  }
  .section-title { color: #8b949e; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 8px 0; }
  .hesap-list .hesap-item { padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.78rem; }
  .hesap-list .hesap-item:hover { background: #1f2937; }
  .hesap-list .hesap-item.selected { background: #1f6feb22; border-left: 2px solid #58a6ff; }
  .json-key { color: #79c0ff; }
  .json-str { color: #a5d6ff; }
  .json-num { color: #f0883e; }
  .json-bool { color: #ff7b72; }
  .json-null { color: #8b949e; }
  ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #0d1117; } ::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }
</style>
</head>
<body>

<!-- HEADER -->
<div class="panel-header d-flex align-items-center justify-content-between">
  <h1><i class="bi bi-bank2 me-2"></i>Banka API Test Paneli</h1>
  <div class="d-flex align-items-center gap-3">
    <span class="text-muted" style="font-size:0.78rem"><i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y H:i') ?></span>
    <span class="badge bg-secondary" style="font-size:0.72rem">token: <?= $token ?></span>
  </div>
</div>

<div class="d-flex" style="height:calc(100vh - 60px)">

  <!-- SOL PANEL — API KİMLİKLER -->
  <div class="sidebar" style="width:260px;min-width:260px">
    <div class="p-3 pb-1">
      <p class="section-title"><i class="bi bi-key me-1"></i>API Kimlikleri</p>
    </div>
    <div id="apiListesi">
      <div class="text-center py-4 text-muted" style="font-size:0.8rem">
        <div class="spinner-border spinner-border-sm me-2"></div> Yükleniyor...
      </div>
    </div>
    <hr style="border-color:#30363d;margin:0">
    <div class="p-3 pb-1">
      <p class="section-title"><i class="bi bi-wallet2 me-1"></i>Hesaplar <span id="hesapFilterBadge" class="badge bg-secondary" style="font-size:0.65rem">tümü</span></p>
    </div>
    <div id="hesapListesi" class="hesap-list px-2 pb-2">
      <div class="text-muted px-2" style="font-size:0.75rem">API kimliği seçin</div>
    </div>
  </div>

  <!-- SAĞ PANEL — TEST ARAYÜZÜ -->
  <div class="main-panel flex-fill">

    <!-- REQUEST BAR -->
    <div class="request-bar mb-3">
      <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
        <span class="method-badge">POST</span>
        <span class="url-display"><?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? 'portal.ornekfirma.com') . $_SERVER['PHP_SELF']) ?>?token=<?= $token ?></span>
      </div>

      <!-- ACTION BUTONLARI -->
      <div class="d-flex gap-2 flex-wrap mb-3">
        <button class="btn btn-sm btn-outline-info action-btn" onclick="calistir('testConnection')">
          <i class="bi bi-plug me-1"></i>Bağlantı Testi
        </button>
        <button class="btn btn-sm btn-outline-primary action-btn" onclick="calistir('getHesaplar')">
          <i class="bi bi-list-ul me-1"></i>Hesap Listesi
        </button>
        <button class="btn btn-sm btn-outline-warning action-btn" onclick="calistir('getHareketler')">
          <i class="bi bi-arrow-left-right me-1"></i>Hareketler
        </button>
        <button class="btn btn-sm btn-outline-secondary ms-auto action-btn" onclick="temizle()">
          <i class="bi bi-trash me-1"></i>Temizle
        </button>
      </div>

      <!-- PARAMETRELER -->
      <div class="params-row row g-2">
        <div class="col-md-2">
          <label>API Kimlik ID</label>
          <input type="text" id="pApiKimlikId" class="form-control" placeholder="Otomatik" readonly>
        </div>
        <div class="col-md-3">
          <label>Seçili Banka</label>
          <input type="text" id="pBankaAdi" class="form-control" placeholder="Sol panelden seçin" readonly>
        </div>
        <div class="col-md-3">
          <label>Hesap ID (Hareketler için)</label>
          <input type="text" id="pHesapId" class="form-control" placeholder="Sol panelden hesap seç">
        </div>
        <div class="col-md-2">
          <label>Başlangıç Tarihi</label>
          <input type="date" id="pBasTarih" class="form-control" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
        </div>
        <div class="col-md-2">
          <label>Bitiş Tarihi</label>
          <input type="date" id="pBitTarih" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
    </div>

    <!-- RESPONSE -->
    <div class="response-area">
      <div class="response-header">
        <div>
          <span id="statusBadge" class="badge bg-secondary me-2">BEKLIYOR</span>
          <span id="statusMesaj" class="text-muted">Bir action seçin ve çalıştırın</span>
        </div>
        <div class="d-flex align-items-center gap-3">
          <span id="sureBadge" class="text-muted"></span>
          <div class="loading-spinner" id="loadingSpinner">
            <div class="spinner-border spinner-border-sm text-warning"></div>
          </div>
          <button class="btn btn-sm btn-outline-secondary py-0" style="font-size:0.72rem" onclick="kopyala()">
            <i class="bi bi-clipboard"></i> Kopyala
          </button>
        </div>
      </div>
      <pre class="response-body" id="responseBody"><span class="text-muted">// Yanıt burada görünecek...</span></pre>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const TOKEN    = '<?= $token ?>';
let seciliApiKimlikId = null;
let seciliHesapId     = null;
let tumHesaplar       = [];
let sonYanit          = '';

// ── Sayfa yükle ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    yukleBankalar();
});

function yukleBankalar() {
    fetch(window.location.pathname + '?token=' + TOKEN, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=bankalar&token=' + TOKEN
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { document.getElementById('apiListesi').innerHTML = '<div class="p-3 text-danger">DB hatası</div>'; return; }
        tumHesaplar = data.hesaplar || [];
        renderApiListesi(data.apiKimlikleri || []);
    })
    .catch(e => { document.getElementById('apiListesi').innerHTML = '<div class="p-3 text-danger">' + e.message + '</div>'; });
}

function renderApiListesi(liste) {
    let html = '';
    liste.forEach(api => {
        const durum = api.apiKimlik_durum == 1 ? '<span class="badge badge-durum-1 ms-1" style="font-size:0.62rem">AKTİF</span>' : '<span class="badge badge-durum-0 ms-1" style="font-size:0.62rem">PASİF</span>';
        const sonTest = api.sonTest ? '<br><span style="color:#3fb950;font-size:0.68rem">✓ ' + api.sonTest + '</span>' : '';
        html += `<div class="bank-item" data-id="${api.apiKimlik_id}" data-banka="${escHtml(api.banka_adi)}" data-kod="${escHtml(api.banka_kodu)}" onclick="secApiKimlik(this)">
            <div class="bank-name">${escHtml(api.banka_adi)} ${durum}</div>
            <div class="bank-meta">#${api.apiKimlik_id} — ${escHtml(api.apiKimlik_aciklama || api.banka_kodu)}${sonTest}</div>
        </div>`;
    });
    document.getElementById('apiListesi').innerHTML = html || '<div class="p-3 text-muted" style="font-size:0.78rem">Kayıt yok</div>';
}

function secApiKimlik(el) {
    document.querySelectorAll('.bank-item').forEach(x => x.classList.remove('active'));
    el.classList.add('active');
    seciliApiKimlikId = el.dataset.id;
    document.getElementById('pApiKimlikId').value = el.dataset.id;
    document.getElementById('pBankaAdi').value = el.dataset.banka + ' (' + el.dataset.kod + ')';
    seciliHesapId = null;
    document.getElementById('pHesapId').value = '';
    renderHesaplar(el.dataset.id);
}

function renderHesaplar(apiKimlikId) {
    const filtreli = tumHesaplar.filter(h => h.bankaHesap_apiKimlik_id == apiKimlikId);
    document.getElementById('hesapFilterBadge').textContent = filtreli.length;
    let html = '';
    if (!filtreli.length) {
        html = '<div class="text-muted px-2 py-2" style="font-size:0.75rem">Bu API kimliğe bağlı hesap yok</div>';
    } else {
        filtreli.forEach(h => {
            const iban = h.bankaHesap_iban ? h.bankaHesap_iban.replace('TR', 'TR ') : '-';
            html += `<div class="hesap-item" data-id="${h.bankaHesap_id}" onclick="secHesap(this, ${h.bankaHesap_id})">
                <div style="color:#e6edf3">${escHtml(h.bankaHesap_no || '-')}</div>
                <div style="color:#8b949e;font-size:0.7rem">${escHtml(h.bankaHesap_sube_adi || '')} — ${escHtml(iban)}</div>
            </div>`;
        });
    }
    document.getElementById('hesapListesi').innerHTML = html;
}

function secHesap(el, id) {
    document.querySelectorAll('.hesap-item').forEach(x => x.classList.remove('selected'));
    el.classList.add('selected');
    seciliHesapId = id;
    document.getElementById('pHesapId').value = id;
}

// ── İstek gönder ─────────────────────────────────────────────
function calistir(action) {
    if (!seciliApiKimlikId) {
        setStatus('error', 'Önce sol panelden bir API kimliği seçin', '');
        return;
    }

    setStatus('loading', 'İstek gönderiliyor...', '');
    document.getElementById('responseBody').innerHTML = '<span class="text-muted">// Bekleniyor...</span>';

    const params = new URLSearchParams({
        action:          action,
        token:           TOKEN,
        apiKimlikId:     seciliApiKimlikId,
        hesapId:         document.getElementById('pHesapId').value || '',
        baslangicTarih:  document.getElementById('pBasTarih').value,
        bitisTarih:      document.getElementById('pBitTarih').value
    });

    const t0 = performance.now();

    fetch(window.location.pathname + '?token=' + TOKEN, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(r => r.text())
    .then(text => {
        const sure = ((performance.now() - t0) / 1000).toFixed(3);
        document.getElementById('sureBadge').textContent = '⏱ ' + sure + 's';
        sonYanit = text;
        try {
            const json = JSON.parse(text);
            renderResponse(json, sure);
        } catch(e) {
            // JSON değil, ham yanıt
            document.getElementById('responseBody').textContent = text;
            setStatus('error', 'JSON parse hatası — ham yanıt gösteriliyor', sure);
        }
    })
    .catch(e => {
        setStatus('error', 'Fetch hatası: ' + e.message, '');
        document.getElementById('responseBody').textContent = e.message;
    });
}

function renderResponse(json, sure) {
    const ok = json.success === true;
    setStatus(ok ? 'ok' : 'error', json.mesaj || (ok ? 'Başarılı' : 'Hata'), sure);

    let text = JSON.stringify(json, null, 2);
    // Syntax highlight
    text = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    text = text.replace(/"([^"]+)":/g, '<span class="json-key">"$1"</span>:');
    text = text.replace(/: "([^"]*)"/g, ': <span class="json-str">"$1"</span>');
    text = text.replace(/: (\d+\.?\d*)/g, ': <span class="json-num">$1</span>');
    text = text.replace(/: (true|false)/g, ': <span class="json-bool">$1</span>');
    text = text.replace(/: (null)/g, ': <span class="json-null">$1</span>');
    document.getElementById('responseBody').innerHTML = text;
}

function setStatus(type, mesaj, sure) {
    const badge = document.getElementById('statusBadge');
    const msg   = document.getElementById('statusMesaj');
    const spin  = document.getElementById('loadingSpinner');

    if (type === 'loading') {
        badge.className = 'badge bg-warning me-2'; badge.textContent = 'ÇALIŞIYOR';
        spin.style.display = 'inline';
    } else if (type === 'ok') {
        badge.className = 'badge bg-success me-2'; badge.textContent = 'BAŞARILI';
        spin.style.display = 'none';
    } else if (type === 'error') {
        badge.className = 'badge bg-danger me-2'; badge.textContent = 'HATA';
        spin.style.display = 'none';
    } else {
        badge.className = 'badge bg-secondary me-2'; badge.textContent = 'BEKLIYOR';
        spin.style.display = 'none';
    }
    msg.textContent = mesaj;
    if (sure) document.getElementById('sureBadge').textContent = '⏱ ' + sure + 's';
}

function temizle() {
    document.getElementById('responseBody').innerHTML = '<span class="text-muted">// Yanıt burada görünecek...</span>';
    setStatus('wait', 'Bir action seçin ve çalıştırın', '');
    document.getElementById('sureBadge').textContent = '';
    sonYanit = '';
}

function kopyala() {
    if (!sonYanit) return;
    navigator.clipboard.writeText(sonYanit).then(() => {
        const btn = document.querySelector('[onclick="kopyala()"]');
        btn.innerHTML = '<i class="bi bi-check2"></i> Kopyalandı';
        setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Kopyala'; }, 2000);
    });
}

function escHtml(str) {
    if (!str) return '';
    return str.toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
