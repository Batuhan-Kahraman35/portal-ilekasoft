<?php
/**
 * Admin Panel - Entegrasyon Yönetimi
 *
 * Entegrasyonlar, Kanallar ve Logların yönetimi
 */

require_once __DIR__ . '/../auth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$pageTitle = 'Entegrasyon Yönetimi';
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Bağlantı testi desteklenen entegrasyonlar (Entegrasyonlar_Ad değerleri)
const TEST_EDILEBILIR = ['ÖRNEK HOLDİNG', 'Destek Sistemi', 'Cloudflare', 'SMS', 'Whatsapp (Evolution API)', 'E-Posta (SMTP)', 'Google Workspace'];

// Log listesinde tek seferde çekilecek en fazla satır.
// Not: Istek/Cevap kolonları listede seçilmez; yalnız detay penceresinde okunur.
const LOG_LIMIT = 1000;

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $type = $_POST['type'] ?? ''; // entegrasyon, kanal, log

    // Geçersiz UTF-8 içeren log gövdeleri json_encode'u false döndürüp
    // yanıtı boşaltıyordu; bayraklarla bozuk karakterler ikame ediliyor.
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    try {
        switch ($action) {
            case 'list':
                if ($type === 'entegrasyon') {
                    $sql = "SELECT e.Entegrasyonlar_id,
                                   e.Entegrasyonlar_Ad,
                                   CAST(e.Entegrasyonlar_AyarJSON AS NVARCHAR(MAX)) AS Entegrasyonlar_AyarJSON,
                                   e.Entegrasyonlar_Durum,
                                   (SELECT COUNT(*) FROM EntegrasyonKanallari k
                                     WHERE k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id) AS KanalSayisi
                            FROM Entegrasyonlar e
                            WHERE 1 = 1";
                    $params = [];

                    if (($_POST['f_ad'] ?? '') !== '') {
                        $sql .= " AND e.Entegrasyonlar_Ad LIKE ?";
                        $params[] = '%' . $_POST['f_ad'] . '%';
                    }
                    if (($_POST['f_durum'] ?? '') !== '') {
                        $sql .= " AND e.Entegrasyonlar_Durum = ?";
                        $params[] = (int)$_POST['f_durum'];
                    }

                    $sql .= " ORDER BY e.Entegrasyonlar_Ad";
                    echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)], $jsonBayrak);

                } elseif ($type === 'kanal') {
                    $sql = "SELECT k.EntegrasyonKanallari_id,
                                   k.EntegrasyonKanallari_Entegrasyonlar_id,
                                   k.EntegrasyonKanallari_Ad,
                                   k.EntegrasyonKanallari_KullaniciAdi,
                                   k.EntegrasyonKanallari_Durum,
                                   e.Entegrasyonlar_Ad
                            FROM EntegrasyonKanallari k
                            LEFT JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                            WHERE 1 = 1";
                    $params = [];

                    if (($_POST['f_entegrasyon'] ?? '') !== '') {
                        $sql .= " AND k.EntegrasyonKanallari_Entegrasyonlar_id = ?";
                        $params[] = (int)$_POST['f_entegrasyon'];
                    }
                    if (($_POST['f_ad'] ?? '') !== '') {
                        $sql .= " AND (k.EntegrasyonKanallari_Ad LIKE ? OR k.EntegrasyonKanallari_KullaniciAdi LIKE ?)";
                        $params[] = '%' . $_POST['f_ad'] . '%';
                        $params[] = '%' . $_POST['f_ad'] . '%';
                    }
                    if (($_POST['f_durum'] ?? '') !== '') {
                        $sql .= " AND k.EntegrasyonKanallari_Durum = ?";
                        $params[] = (int)$_POST['f_durum'];
                    }

                    $sql .= " ORDER BY e.Entegrasyonlar_Ad, k.EntegrasyonKanallari_Ad";
                    echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)], $jsonBayrak);

                } elseif ($type === 'log') {
                    // Istek/Cevap (NVARCHAR(MAX)) listede çekilmez; yanıtı şişirip
                    // json_encode'un başarısız olmasına yol açıyordu.
                    $sql = "SELECT TOP " . LOG_LIMIT . "
                                   l.EntegrasyonLoglari_id                AS log_id,
                                   l.EntegrasyonLoglari_IslemTipi         AS islem_tipi,
                                   l.EntegrasyonLoglari_Hedef             AS hedef,
                                   l.EntegrasyonLoglari_BasariliMi        AS basarili,
                                   l.EntegrasyonLoglari_Kaynak            AS kaynak,
                                   CONVERT(VARCHAR(19), l.EntegrasyonLoglari_OlusturmaTarihi, 120) AS tarih,
                                   e.Entegrasyonlar_Ad                    AS entegrasyon_adi,
                                   kn.EntegrasyonKanallari_Ad             AS kanal_adi
                            FROM EntegrasyonLoglari l
                            LEFT JOIN EntegrasyonKanallari kn ON l.EntegrasyonLoglari_EntegrasyonKanallari_id = kn.EntegrasyonKanallari_id
                            LEFT JOIN Entegrasyonlar e ON kn.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                            WHERE l.EntegrasyonLoglari_Durum = 1";
                    $params = [];

                    if (($_POST['f_bas'] ?? '') !== '') {
                        $sql .= " AND CONVERT(date, l.EntegrasyonLoglari_OlusturmaTarihi) >= ?";
                        $params[] = $_POST['f_bas'];
                    }
                    if (($_POST['f_bit'] ?? '') !== '') {
                        $sql .= " AND CONVERT(date, l.EntegrasyonLoglari_OlusturmaTarihi) <= ?";
                        $params[] = $_POST['f_bit'];
                    }
                    if (($_POST['f_entegrasyon'] ?? '') !== '') {
                        $sql .= " AND e.Entegrasyonlar_id = ?";
                        $params[] = (int)$_POST['f_entegrasyon'];
                    }
                    if (($_POST['f_kanal'] ?? '') !== '') {
                        $sql .= " AND kn.EntegrasyonKanallari_id = ?";
                        $params[] = (int)$_POST['f_kanal'];
                    }
                    if (($_POST['f_tip'] ?? '') !== '') {
                        $sql .= " AND l.EntegrasyonLoglari_IslemTipi = ?";
                        $params[] = $_POST['f_tip'];
                    }
                    if (($_POST['f_sonuc'] ?? '') !== '') {
                        $sql .= " AND l.EntegrasyonLoglari_BasariliMi = ?";
                        $params[] = (int)$_POST['f_sonuc'];
                    }
                    if (($_POST['f_arama'] ?? '') !== '') {
                        $sql .= " AND (l.EntegrasyonLoglari_Hedef LIKE ? OR l.EntegrasyonLoglari_HataMesaji LIKE ?)";
                        $params[] = '%' . $_POST['f_arama'] . '%';
                        $params[] = '%' . $_POST['f_arama'] . '%';
                    }

                    $sql .= " ORDER BY l.EntegrasyonLoglari_id DESC";

                    $data = $db->fetchAll($sql, $params);
                    echo json_encode([
                        'success' => true,
                        'data'    => $data,
                        'limit'   => LOG_LIMIT,
                        'kesildi' => count($data) >= LOG_LIMIT,
                    ], $jsonBayrak);
                }
                break;

            case 'stats':
                echo json_encode(['success' => true, 'data' => [
                    'entegrasyon'  => (int)($db->fetchOne("SELECT COUNT(*) AS s FROM Entegrasyonlar WHERE Entegrasyonlar_Durum = 1")['s'] ?? 0),
                    'kanal'        => (int)($db->fetchOne("SELECT COUNT(*) AS s FROM EntegrasyonKanallari WHERE EntegrasyonKanallari_Durum = 1")['s'] ?? 0),
                    'log_bugun'    => (int)($db->fetchOne("SELECT COUNT(*) AS s FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_Durum = 1 AND CONVERT(date, EntegrasyonLoglari_OlusturmaTarihi) = CONVERT(date, GETDATE())")['s'] ?? 0),
                    'log_hata'     => (int)($db->fetchOne("SELECT COUNT(*) AS s FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_Durum = 1 AND EntegrasyonLoglari_BasariliMi = 0 AND CONVERT(date, EntegrasyonLoglari_OlusturmaTarihi) = CONVERT(date, GETDATE())")['s'] ?? 0),
                ]], $jsonBayrak);
                break;

            case 'filtre_veri':
                echo json_encode(['success' => true, 'data' => [
                    'kanallar' => $db->fetchAll("
                        SELECT EntegrasyonKanallari_id AS id,
                               EntegrasyonKanallari_Ad AS ad,
                               EntegrasyonKanallari_Entegrasyonlar_id AS entegrasyon_id
                        FROM EntegrasyonKanallari
                        ORDER BY EntegrasyonKanallari_Ad
                    "),
                    'tipler' => $db->fetchAll("
                        SELECT DISTINCT EntegrasyonLoglari_IslemTipi AS tip
                        FROM EntegrasyonLoglari
                        WHERE EntegrasyonLoglari_Durum = 1 AND EntegrasyonLoglari_IslemTipi IS NOT NULL
                        ORDER BY EntegrasyonLoglari_IslemTipi
                    "),
                ]], $jsonBayrak);
                break;

            case 'get':
                $id = $_POST['id'] ?? 0;
                if ($type === 'entegrasyon') {
                    $data = $db->fetchOne("SELECT Entegrasyonlar_id, Entegrasyonlar_Ad,
                                                  CAST(Entegrasyonlar_AyarJSON AS NVARCHAR(MAX)) AS Entegrasyonlar_AyarJSON,
                                                  Entegrasyonlar_Durum
                                           FROM Entegrasyonlar WHERE Entegrasyonlar_id = ?", [$id]);
                    echo json_encode(['success' => true, 'data' => $data], $jsonBayrak);
                } elseif ($type === 'kanal') {
                    $data = $db->fetchOne("SELECT * FROM EntegrasyonKanallari WHERE EntegrasyonKanallari_id = ?", [$id]);
                    echo json_encode(['success' => true, 'data' => $data], $jsonBayrak);
                } elseif ($type === 'log') {
                    $data = $db->fetchOne("
                        SELECT l.EntegrasyonLoglari_id       AS log_id,
                               l.EntegrasyonLoglari_IslemTipi AS islem_tipi,
                               l.EntegrasyonLoglari_Hedef     AS hedef,
                               l.EntegrasyonLoglari_Istek     AS istek,
                               l.EntegrasyonLoglari_Cevap     AS cevap,
                               l.EntegrasyonLoglari_HataMesaji AS hata,
                               l.EntegrasyonLoglari_BasariliMi AS basarili,
                               l.EntegrasyonLoglari_IP        AS ip,
                               l.EntegrasyonLoglari_Kaynak    AS kaynak,
                               CONVERT(VARCHAR(19), l.EntegrasyonLoglari_OlusturmaTarihi, 120) AS tarih,
                               e.Entegrasyonlar_Ad            AS entegrasyon_adi,
                               kn.EntegrasyonKanallari_Ad     AS kanal_adi
                        FROM EntegrasyonLoglari l
                        LEFT JOIN EntegrasyonKanallari kn ON l.EntegrasyonLoglari_EntegrasyonKanallari_id = kn.EntegrasyonKanallari_id
                        LEFT JOIN Entegrasyonlar e ON kn.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                        WHERE l.EntegrasyonLoglari_id = ?
                    ", [$id]);
                    echo json_encode(['success' => true, 'data' => $data], $jsonBayrak);
                }
                break;

            case 'save':
                $id = $_POST['id'] ?? 0;
                if ($type === 'entegrasyon') {
                    $data = [
                        'Entegrasyonlar_Ad' => $_POST['ad'] ?? '',
                        'Entegrasyonlar_AyarJSON' => !empty($_POST['ayar']) ? $_POST['ayar'] : null,
                        'Entegrasyonlar_Durum' => isset($_POST['durum']) ? 1 : 0
                    ];
                    if ($id > 0) {
                        $data['Entegrasyonlar_GuncelleyenKullanici'] = $user['id'];
                        $data['Entegrasyonlar_GuncellemeTarihi'] = date('Y-m-d H:i:s');
                        $db->update('Entegrasyonlar', $data, ['Entegrasyonlar_id' => $id]);
                    } else {
                        $data['Entegrasyonlar_OlusturanKullanici'] = $user['id'];
                        $db->insert('Entegrasyonlar', $data);
                    }
                    echo json_encode(['success' => true, 'message' => 'Entegrasyon kaydedildi.']);
                } elseif ($type === 'kanal') {
                    $data = [
                        'EntegrasyonKanallari_Entegrasyonlar_id' => $_POST['entegrasyon_id'] ?? 0,
                        'EntegrasyonKanallari_Ad' => $_POST['ad'] ?? '',
                        'EntegrasyonKanallari_KullaniciAdi' => !empty($_POST['kullanici']) ? $_POST['kullanici'] : null,
                        'EntegrasyonKanallari_Sifre' => !empty($_POST['sifre']) ? $_POST['sifre'] : null,
                        'EntegrasyonKanallari_AyarJSON' => !empty($_POST['ayar']) ? $_POST['ayar'] : null,
                        'EntegrasyonKanallari_Durum' => isset($_POST['durum']) ? 1 : 0
                    ];
                    if ($id > 0) {
                        $data['EntegrasyonKanallari_GuncelleyenKullanici'] = $user['id'];
                        $data['EntegrasyonKanallari_GuncellemeTarihi'] = date('Y-m-d H:i:s');
                        $db->update('EntegrasyonKanallari', $data, ['EntegrasyonKanallari_id' => $id]);
                    } else {
                        $data['EntegrasyonKanallari_OlusturanKullanici'] = $user['id'];
                        $db->insert('EntegrasyonKanallari', $data);
                    }
                    echo json_encode(['success' => true, 'message' => 'Kanal kaydedildi.']);
                }
                break;

            case 'delete':
                $id = $_POST['id'] ?? 0;
                if ($type === 'entegrasyon') {
                    $db->delete('Entegrasyonlar', ['Entegrasyonlar_id' => $id]);
                } elseif ($type === 'kanal') {
                    $db->delete('EntegrasyonKanallari', ['EntegrasyonKanallari_id' => $id]);
                }
                echo json_encode(['success' => true, 'message' => 'Kayıt silindi.']);
                break;

            // ========================================================
            // Bağlantı testi
            // Entegrasyon adına göre uygun sorgu çalıştırılır.
            // TÜMÜ SALT OKUNUR: hiçbir test kayıt oluşturmaz, SMS göndermez.
            // ========================================================
            case 'test_baglanti':
                $id = (int)($_POST['id'] ?? 0);

                $kanal = $db->fetchOne("
                    SELECT k.*, e.Entegrasyonlar_Ad, e.Entegrasyonlar_id,
                           CAST(e.Entegrasyonlar_AyarJSON AS NVARCHAR(MAX)) AS Entegrasyonlar_AyarJSON
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                    WHERE k.EntegrasyonKanallari_id = ?
                ", [$id]);

                if (!$kanal) {
                    echo json_encode(['success' => false, 'message' => 'Kanal bulunamadı.']);
                    break;
                }

                $entAd   = (string)$kanal['Entegrasyonlar_Ad'];
                $satirlar = [];
                $hataVar  = false;
                $aralik   = '';

                switch ($entAd) {

                    // ---------------- ÖRNEK HOLDİNG ----------------
                    case 'ÖRNEK HOLDİNG':
                        require_once __DIR__ . '/../includes/OrnekHoldingApi.php';

                        $api = new OrnekHoldingApi($kanal);
                        if (!$api->hazirMi()) {
                            echo json_encode(['success' => false, 'message' => $api->getHata()]);
                            break 2;
                        }

                        $bas    = date('Y-m-d', strtotime('-7 days'));
                        $bit    = date('Y-m-d');
                        $aralik = $bas . ' → ' . $bit;

                        foreach (['IN' => 'Gelen e-Fatura', 'OUT' => 'Giden e-Fatura'] as $yon => $etiket) {
                            $r = $api->faturaListesi($yon, [
                                'start_date' => $bas,
                                'end_date'   => $bit,
                                'limit'      => 100,
                            ]);

                            if ($r['success']) {
                                $satirlar[] = ['etiket' => $etiket, 'ok' => true, 'mesaj' => count($r['data']) . ' kayıt'];
                            } else {
                                $hataVar = true;
                                $satirlar[] = ['etiket' => $etiket, 'ok' => false, 'mesaj' => $r['message']];
                            }
                        }

                        if ($api->earsivVarMi()) {
                            $r = $api->earsivListesi(['start_date' => $bas, 'end_date' => $bit, 'limit' => 100]);
                            if ($r['success']) {
                                $satirlar[] = ['etiket' => 'Giden e-Arşiv', 'ok' => true, 'mesaj' => count($r['data']) . ' kayıt'];
                            } else {
                                $hataVar = true;
                                $satirlar[] = ['etiket' => 'Giden e-Arşiv', 'ok' => false, 'mesaj' => $r['message']];
                            }
                        } else {
                            $satirlar[] = ['etiket' => 'Giden e-Arşiv', 'ok' => false, 'mesaj' => 'Entegrasyon ayarında EArsiv adresi tanımlı değil.'];
                        }
                        break;

                    // ---------------- Destek Sistemi ----------------
                    // Kanalın kendi ApiKey'i ile ticket_meta çağrılır (salt okunur).
                    case 'Destek Sistemi':
                        $entAyar   = json_decode($kanal['Entegrasyonlar_AyarJSON'] ?? '{}', true) ?: [];
                        $kanalAyar = json_decode($kanal['EntegrasyonKanallari_AyarJSON'] ?? '{}', true) ?: [];
                        $cfg       = array_merge($entAyar, $kanalAyar);

                        $url    = trim((string)($cfg['BaseURL'] ?? ''));
                        $apiKey = trim((string)($cfg['ApiKey'] ?? $kanal['EntegrasyonKanallari_Sifre'] ?? ''));

                        if ($url === '') {
                            echo json_encode(['success' => false, 'message' => 'Entegrasyon ayarında BaseURL tanımlı değil.']);
                            break 2;
                        }
                        if ($apiKey === '') {
                            echo json_encode(['success' => false, 'message' => 'Kanal ayarında ApiKey tanımlı değil.']);
                            break 2;
                        }

                        $satirlar[] = ['etiket' => 'Endpoint', 'ok' => true, 'mesaj' => $url];

                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $apiKey, 'Content-Type: application/json'],
                            CURLOPT_POSTFIELDS     => json_encode(['action' => 'ticket_meta'], JSON_UNESCAPED_UNICODE),
                            CURLOPT_TIMEOUT        => 20,
                            CURLOPT_SSL_VERIFYPEER => false,
                        ]);
                        $res  = curl_exec($ch);
                        $err  = curl_error($ch);
                        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($res === false) {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Bağlantı', 'ok' => false, 'mesaj' => $err ?: 'Bilinmeyen cURL hatası'];
                            break;
                        }

                        $satirlar[] = ['etiket' => 'HTTP durumu', 'ok' => ($code >= 200 && $code < 300), 'mesaj' => (string)$code];
                        if ($code < 200 || $code >= 300) $hataVar = true;

                        $json = json_decode($res, true);
                        if (!is_array($json)) {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Yanıt', 'ok' => false, 'mesaj' => 'JSON ayrıştırılamadı: ' . mb_substr($res, 0, 200)];
                            break;
                        }

                        if (empty($json['success'])) {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Kimlik doğrulama', 'ok' => false, 'mesaj' => $json['message'] ?? 'API başarısız yanıt döndü.'];
                            break;
                        }

                        $meta  = $json['data'] ?? $json;
                        $ozet  = [];
                        foreach (['durumlar' => 'durum', 'oncelikler' => 'öncelik', 'kategoriler' => 'kategori', 'personeller' => 'personel'] as $anahtar => $etiket) {
                            if (isset($meta[$anahtar]) && is_array($meta[$anahtar])) {
                                $ozet[] = count($meta[$anahtar]) . ' ' . $etiket;
                            }
                        }
                        $satirlar[] = [
                            'etiket' => 'Kimlik doğrulama',
                            'ok'     => true,
                            'mesaj'  => $ozet ? ('Başarılı — ' . implode(', ', $ozet)) : 'Başarılı',
                        ];
                        break;

                    // ---------------- Cloudflare ----------------
                    // Kullanıcı Adı = Account ID, Şifre = API Token
                    case 'Cloudflare':
                        $accountId = trim((string)$kanal['EntegrasyonKanallari_KullaniciAdi']);
                        $apiToken  = trim((string)$kanal['EntegrasyonKanallari_Sifre']);

                        if ($accountId === '' || $apiToken === '') {
                            echo json_encode(['success' => false, 'message' => 'Kanalda Kullanıcı Adı (Account ID) veya Şifre (API Token) boş.']);
                            break 2;
                        }

                        // 1) Token doğrulama
                        $ch = curl_init('https://api.cloudflare.com/client/v4/user/tokens/verify');
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 20,
                            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'],
                        ]);
                        $res  = curl_exec($ch);
                        $err  = curl_error($ch);
                        curl_close($ch);

                        if ($res === false) {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'API Token', 'ok' => false, 'mesaj' => $err ?: 'Bağlantı kurulamadı'];
                        } else {
                            $j = json_decode($res, true);
                            $ok = !empty($j['success']);
                            if (!$ok) $hataVar = true;
                            $satirlar[] = [
                                'etiket' => 'API Token',
                                'ok'     => $ok,
                                'mesaj'  => $ok
                                    ? ('Geçerli (' . ($j['result']['status'] ?? 'active') . ')')
                                    : ($j['errors'][0]['message'] ?? 'Token doğrulanamadı'),
                            ];
                        }

                        // 2) Registrar domain sorgusu (salt okunur, 1 kayıt)
                        $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($accountId)
                             . '/registrar/domains?per_page=1&page=0';
                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json'],
                        ]);
                        $res  = curl_exec($ch);
                        $err  = curl_error($ch);
                        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($res === false) {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Alan adları', 'ok' => false, 'mesaj' => $err ?: 'Bağlantı kurulamadı'];
                        } else {
                            $j = json_decode($res, true);
                            if (!empty($j['success'])) {
                                $toplam = $j['result_info']['total_count'] ?? count($j['result'] ?? []);
                                $satirlar[] = ['etiket' => 'Alan adları', 'ok' => true, 'mesaj' => $toplam . ' kayıt (Registrar)'];
                            } else {
                                $hataVar = true;
                                $satirlar[] = [
                                    'etiket' => 'Alan adları',
                                    'ok'     => false,
                                    'mesaj'  => ($j['errors'][0]['message'] ?? ('HTTP ' . $code)) . ' — Account ID kontrol edin.',
                                ];
                            }
                        }
                        break;

                    // ---------------- SMS ----------------
                    // Bakiye (kredi) sorgusu; SMS gönderilmez.
                    case 'SMS':
                        require_once __DIR__ . '/../includes/SmsSender.php';

                        $smsKanal = ornek_sms_kanal($db, $id);
                        if (!$smsKanal) {
                            echo json_encode(['success' => false, 'message' => 'SMS kanalı bulunamadı veya pasif durumda.']);
                            break 2;
                        }

                        $satirlar[] = [
                            'etiket' => 'Servis adresi',
                            'ok'     => $smsKanal['api_url'] !== '',
                            'mesaj'  => $smsKanal['api_url'] !== '' ? $smsKanal['api_url'] : 'Kanal ayarında api_url tanımlı değil.',
                        ];
                        if ($smsKanal['api_url'] === '') $hataVar = true;

                        $r = ornek_sms_kredi($smsKanal);
                        if ($r['success']) {
                            $satirlar[] = [
                                'etiket' => 'Bakiye sorgusu',
                                'ok'     => true,
                                'mesaj'  => $r['kredi'] !== null
                                    ? ('Kalan kredi: ' . number_format($r['kredi'], 0, ',', '.'))
                                    : ('Yanıt alındı: ' . mb_substr((string)$r['ham'], 0, 150)),
                            ];
                        } else {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Bakiye sorgusu', 'ok' => false, 'mesaj' => $r['error'] ?: 'Sorgu başarısız'];
                        }

                        $satirlar[] = [
                            'etiket' => 'Gönderici başlığı',
                            'ok'     => $smsKanal['sender'] !== '',
                            'mesaj'  => $smsKanal['sender'] !== '' ? $smsKanal['sender'] : 'Tanımlı değil',
                        ];
                        break;

                    // ---------------- WhatsApp (Evolution API) ----------------
                    // Instance bağlantı durumu okunur; mesaj GÖNDERİLMEZ.
                    case 'Whatsapp (Evolution API)':
                        require_once __DIR__ . '/../includes/EvolutionAPI.php';

                        $entAyar   = json_decode($kanal['Entegrasyonlar_AyarJSON'] ?? '{}', true) ?: [];
                        $kanalAyar = json_decode($kanal['EntegrasyonKanallari_AyarJSON'] ?? '{}', true) ?: [];

                        $apiUrl = trim((string)($kanalAyar['api_url'] ?? $entAyar['BaseURL'] ?? ''));
                        $apiKey = trim((string)($kanal['EntegrasyonKanallari_Sifre'] ?? $entAyar['GlobalApiKey'] ?? ''));
                        // Instance adı: kanal ayarı → kullanıcı adı → kanal adı
                        $instanceAdi = trim((string)($kanalAyar['instance']
                            ?? $kanal['EntegrasyonKanallari_KullaniciAdi']
                            ?? $kanal['EntegrasyonKanallari_Ad']));

                        if ($apiUrl === '') {
                            echo json_encode(['success' => false, 'message' => 'Kanal ayarında api_url tanımlı değil.']);
                            break 2;
                        }
                        if ($apiKey === '') {
                            echo json_encode(['success' => false, 'message' => 'Kanalda API anahtarı (Şifre) tanımlı değil.']);
                            break 2;
                        }

                        $satirlar[] = ['etiket' => 'Servis adresi', 'ok' => true, 'mesaj' => $apiUrl];
                        $satirlar[] = ['etiket' => 'Instance adı',  'ok' => $instanceAdi !== '', 'mesaj' => $instanceAdi ?: 'Tanımlı değil'];
                        if ($instanceAdi === '') $hataVar = true;

                        $api = new EvolutionAPI($apiUrl, $apiKey);
                        $r   = $api->fetchInstances();

                        if (empty($r['success'])) {
                            $hataVar = true;
                            $satirlar[] = [
                                'etiket' => 'API bağlantısı',
                                'ok'     => false,
                                'mesaj'  => $r['message'] ?? ('HTTP ' . ($r['http_code'] ?? 0) . ' — API anahtarını kontrol edin.'),
                            ];
                            break;
                        }

                        $liste = is_array($r['data'] ?? null) ? $r['data'] : [];
                        $satirlar[] = ['etiket' => 'API bağlantısı', 'ok' => true, 'mesaj' => count($liste) . ' instance görüldü'];

                        // connectionState ucu Türkçe/özel karakterli adlarda çalışmadığı için
                        // instance listesi üzerinden eşleştirilir.
                        $bulunan = null;
                        foreach ($liste as $inst) {
                            if (($inst['name'] ?? null) === $instanceAdi) { $bulunan = $inst; break; }
                        }

                        if (!$bulunan) {
                            $hataVar = true;
                            $adlar = array_filter(array_map(fn($i) => $i['name'] ?? null, $liste));
                            $satirlar[] = [
                                'etiket' => 'Instance durumu',
                                'ok'     => false,
                                'mesaj'  => 'Bu adda instance bulunamadı. Sunucudakiler: ' . ($adlar ? implode(', ', $adlar) : 'yok'),
                            ];
                            break;
                        }

                        $durum  = (string)($bulunan['connectionStatus'] ?? 'close');
                        $bagli  = in_array($durum, ['open', 'connected'], true);
                        if (!$bagli) $hataVar = true;

                        $satirlar[] = [
                            'etiket' => 'Instance durumu',
                            'ok'     => $bagli,
                            'mesaj'  => $bagli
                                ? ('Bağlı (' . $durum . ')')
                                : ('Bağlı değil (' . $durum . ') — QR okutulması gerekebilir.'),
                        ];

                        $numara = explode('@', (string)($bulunan['ownerJid'] ?? ''))[0];
                        $satirlar[] = [
                            'etiket' => 'Bağlı numara',
                            'ok'     => $numara !== '',
                            'mesaj'  => $numara !== ''
                                ? ($numara . ($bulunan['profileName'] ?? '' ? ' · ' . $bulunan['profileName'] : ''))
                                : 'Numara okunamadı',
                        ];
                        break;

                    // ---------------- E-Posta (SMTP) ----------------
                    // Sunucuya bağlanılıp kimlik doğrulanır; MAİL GÖNDERİLMEZ.
                    case 'E-Posta (SMTP)':
                        require_once __DIR__ . '/../includes/Mailer.php';

                        if (!Mailer::isInstalled()) {
                            echo json_encode(['success' => false, 'message' => 'PHPMailer kurulu değil (composer require phpmailer/phpmailer).']);
                            break 2;
                        }

                        $mailKanal = Mailer::kanal($id);
                        if (!$mailKanal) {
                            echo json_encode(['success' => false, 'message' => 'Mail kanalı bulunamadı veya pasif durumda.']);
                            break 2;
                        }

                        $satirlar[] = [
                            'etiket' => 'SMTP sunucu',
                            'ok'     => $mailKanal['host'] !== '',
                            'mesaj'  => $mailKanal['host'] !== ''
                                ? ($mailKanal['host'] . ':' . $mailKanal['port'] . ' (' . strtoupper($mailKanal['encryption']) . ')')
                                : 'Kanal ayarında host tanımlı değil.',
                        ];
                        if ($mailKanal['host'] === '') { $hataVar = true; break; }

                        $satirlar[] = [
                            'etiket' => 'Kullanıcı adı',
                            'ok'     => $mailKanal['username'] !== '',
                            'mesaj'  => $mailKanal['username'] !== '' ? $mailKanal['username'] : 'Tanımlı değil',
                        ];
                        $satirlar[] = [
                            'etiket' => 'Şifre',
                            'ok'     => $mailKanal['password'] !== '',
                            'mesaj'  => $mailKanal['password'] !== '' ? 'Tanımlı' : 'Tanımlı değil',
                        ];

                        try {
                            $mailer = new Mailer($id);
                            $r = $mailer->testConnection();
                            if (!$r['success']) $hataVar = true;
                            $satirlar[] = ['etiket' => 'Bağlantı + kimlik doğrulama', 'ok' => (bool)$r['success'], 'mesaj' => $r['message']];
                        } catch (Exception $e) {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Bağlantı + kimlik doğrulama', 'ok' => false, 'mesaj' => $e->getMessage()];
                        }

                        $satirlar[] = [
                            'etiket' => 'Gönderen',
                            'ok'     => $mailKanal['from_address'] !== '',
                            'mesaj'  => $mailKanal['from_address'] !== ''
                                ? ($mailKanal['from_name'] . ' <' . $mailKanal['from_address'] . '>')
                                : 'Tanımlı değil',
                        ];
                        break;

                    // ---------------- Google Workspace ----------------
                    // Kullanıcı Adı = süper yönetici e-postası, Şifre = servis hesabı JSON anahtarı.
                    // Yalnız okuma yapılır; hesap açılmaz, değiştirilmez.
                    case 'Google Workspace':
                        require_once __DIR__ . '/../includes/GoogleWorkspace.php';

                        $gw = new GoogleWorkspace($kanal, (int)($user['kullanici_id'] ?? 1));
                        if (!$gw->hazirMi()) {
                            echo json_encode(['success' => false, 'message' => $gw->getHata()], $jsonBayrak);
                            break 2;
                        }

                        // 1) Alan adları (token + domain yetkisi burada doğrulanır)
                        $r = $gw->alanAdlari();
                        if (!$r['success']) {
                            $hataVar = true;
                            $satirlar[] = [
                                'etiket' => 'Yetki / token',
                                'ok'     => false,
                                'mesaj'  => $r['message'] . ' — Admin Console alan genelinde yetki kaydını kontrol edin.',
                            ];
                            break;
                        }
                        $satirlar[] = ['etiket' => 'Yetki / token', 'ok' => true, 'mesaj' => 'Yönetici: ' . $kanal['EntegrasyonKanallari_KullaniciAdi']];

                        $birincil = null;
                        $alanlar  = [];
                        foreach ($r['data'] as $a) {
                            $alanlar[] = $a['alan'] . ($a['birincil'] ? ' (birincil)' : '');
                            if ($a['birincil']) $birincil = $a['alan'];
                        }
                        $satirlar[] = ['etiket' => 'Alan adları', 'ok' => true, 'mesaj' => count($alanlar) . ': ' . implode(', ', $alanlar)];

                        // 2) Kullanıcılar
                        $r = $gw->kullanicilar(false);
                        if ($r['success']) {
                            $askida = count(array_filter($r['data'], fn($u) => $u['askida']));
                            $satirlar[] = [
                                'etiket' => 'Kullanıcılar',
                                'ok'     => true,
                                'mesaj'  => count($r['data']) . ' hesap (aktif ' . (count($r['data']) - $askida) . ', askıda ' . $askida . ')',
                            ];
                        } else {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Kullanıcılar', 'ok' => false, 'mesaj' => $r['message']];
                        }

                        // 3) Organizasyon birimleri
                        $r = $gw->birimler();
                        if ($r['success']) {
                            $satirlar[] = ['etiket' => 'Organizasyon birimleri', 'ok' => true, 'mesaj' => implode(', ', array_column($r['data'], 'yol'))];
                        } else {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Organizasyon birimleri', 'ok' => false, 'mesaj' => $r['message']];
                        }

                        // 4) Lisanslar: kanal ayarındaki SKU kullanımda mı
                        $r = $birincil
                            ? $gw->lisansOzeti($birincil)
                            : ['success' => false, 'data' => [], 'message' => 'Birincil alan adı bulunamadı.'];
                        if ($r['success']) {
                            $sku = $gw->lisansSku();
                            $skuVar = false;
                            $liste = [];
                            foreach ($r['data'] as $l) {
                                $liste[] = $l['sku'] . ' / ' . $l['ad'] . ': ' . $l['adet'];
                                if ($l['sku'] === $sku) $skuVar = true;
                            }
                            $satirlar[] = ['etiket' => 'Lisanslar', 'ok' => true, 'mesaj' => $liste ? implode(' | ', $liste) : 'Atanmış lisans yok'];

                            if (!$skuVar) $hataVar = true;
                            $satirlar[] = [
                                'etiket' => 'Kanal lisans SKU',
                                'ok'     => $skuVar,
                                'mesaj'  => $sku === ''
                                    ? 'Kanal ayarında lisans_sku tanımlı değil; yeni hesaba lisans atanamaz.'
                                    : ($skuVar ? $sku . ' kullanımda' : $sku . ' hiçbir kullanıcıda yok, SKU yanlış olabilir.'),
                            ];
                        } else {
                            $hataVar = true;
                            $satirlar[] = ['etiket' => 'Lisanslar', 'ok' => false, 'mesaj' => $r['message']];
                        }
                        break;

                    default:
                        echo json_encode([
                            'success' => false,
                            'message' => 'Bu entegrasyon için bağlantı testi tanımlı değil: ' . $entAd,
                        ], $jsonBayrak);
                        break 2;
                }

                echo json_encode([
                    'success'     => !$hataVar,
                    'message'     => $hataVar ? 'Bağlantıda sorun var.' : 'Bağlantı başarılı.',
                    'entegrasyon' => $entAd,
                    'kanal'       => $kanal['EntegrasyonKanallari_Ad'],
                    'aralik'      => $aralik,
                    'satirlar'    => $satirlar,
                ], $jsonBayrak);
                break;

            // ========================================================
            // WhatsApp (Evolution API) instance yönetimi
            // whatsapp-gonderim-ayarlari.php sayfasından buraya taşındı.
            // Kanal bilgileri EntegrasyonKanallari'ndan okunur.
            // ========================================================
            case 'wa_durum':
            case 'wa_qr':
            case 'wa_restart':
            case 'wa_logout':
            case 'wa_test_mesaj':
                require_once __DIR__ . '/../includes/EvolutionAPI.php';

                $kanalId = (int)($_POST['id'] ?? 0);
                $kanal   = EvolutionAPI::kanal($kanalId);

                if (!$kanal) {
                    echo json_encode(['success' => false, 'message' => 'WhatsApp kanalı bulunamadı veya pasif durumda.']);
                    break;
                }

                $api = new EvolutionAPI($kanal['api_url'], $kanal['api_key'], $kanal['id']);

                if ($action === 'wa_durum') {
                    // connectionState ucu özel karakterli adlarda çalışmadığı için
                    // instance listesi üzerinden eşleştirilir.
                    $r = $api->fetchInstances();
                    if (empty($r['success'])) {
                        echo json_encode(['success' => false, 'message' => $r['message'] ?? ('API hatası: HTTP ' . ($r['http_code'] ?? 0))], $jsonBayrak);
                        break;
                    }

                    $bulunan = null;
                    $adlar = [];
                    foreach ((array)($r['data'] ?? []) as $inst) {
                        $adlar[] = $inst['name'] ?? '?';
                        if (($inst['name'] ?? null) === $kanal['instance']) $bulunan = $inst;
                    }

                    if (!$bulunan) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Sunucuda "' . $kanal['instance'] . '" adında instance yok. Mevcutlar: ' . implode(', ', $adlar),
                        ], $jsonBayrak);
                        break;
                    }

                    $durum = (string)($bulunan['connectionStatus'] ?? 'close');
                    echo json_encode([
                        'success'  => true,
                        'durum'    => $durum,
                        'bagli'    => in_array($durum, ['open', 'connected'], true),
                        'telefon'  => explode('@', (string)($bulunan['ownerJid'] ?? ''))[0],
                        'profil'   => $bulunan['profileName'] ?? '',
                        'instance' => $kanal['instance'],
                    ], $jsonBayrak);
                    break;
                }

                if ($action === 'wa_qr') {
                    // Bağlantı kur ve QR kodu al (zaten bağlıysa QR dönmez)
                    $r = $api->connect($kanal['instance']);
                    $d = (array)($r['data'] ?? []);
                    $qr = $d['base64'] ?? ($d['qrcode']['base64'] ?? null);

                    echo json_encode([
                        'success' => !empty($r['success']),
                        'qr'      => $qr,
                        'kod'     => $d['code'] ?? ($d['pairingCode'] ?? null),
                        'message' => empty($r['success'])
                            ? ($r['message'] ?? ('HTTP ' . ($r['http_code'] ?? 0)))
                            : ($qr ? null : 'QR üretilmedi — instance zaten bağlı olabilir.'),
                    ], $jsonBayrak);
                    break;
                }

                if ($action === 'wa_restart') {
                    $r = $api->restart($kanal['instance']);
                    echo json_encode([
                        'success' => !empty($r['success']),
                        'message' => !empty($r['success'])
                            ? 'Instance yeniden başlatıldı.'
                            : ($r['message'] ?? ('HTTP ' . ($r['http_code'] ?? 0))),
                    ], $jsonBayrak);
                    break;
                }

                if ($action === 'wa_logout') {
                    $r = $api->logout($kanal['instance']);
                    echo json_encode([
                        'success' => !empty($r['success']),
                        'message' => !empty($r['success'])
                            ? 'Oturum kapatıldı. Yeniden bağlanmak için QR okutulmalı.'
                            : ($r['message'] ?? ('HTTP ' . ($r['http_code'] ?? 0))),
                    ], $jsonBayrak);
                    break;
                }

                // wa_test_mesaj — GERÇEK mesaj gönderir, EntegrasyonLoglari'na düşer
                $telefon = trim((string)($_POST['telefon'] ?? ''));
                $mesaj   = trim((string)($_POST['mesaj'] ?? ''));

                if ($telefon === '' || $mesaj === '') {
                    echo json_encode(['success' => false, 'message' => 'Telefon ve mesaj zorunludur.']);
                    break;
                }

                $GLOBALS['WHATSAPP_LOG_SOURCE'] = 'ENTEGRASYON_YONETIMI_TEST';
                $r = $api->sendText($kanal['instance'], $telefon, $mesaj);

                echo json_encode([
                    'success' => !empty($r['success']),
                    'message' => !empty($r['success'])
                        ? 'Mesaj gönderildi.'
                        : ('Gönderilemedi: ' . ($r['data']['message'] ?? $r['message'] ?? 'Bilinmeyen hata')),
                ], $jsonBayrak);
                break;

            // ========================================================
            // E-Posta (SMTP) gönderim işlemleri
            // mail-gonderim-ayarlari.php sayfasından buraya taşındı.
            // Bağlantı bilgileri EntegrasyonKanallari'ndan okunur.
            // ========================================================
            case 'mail_ayar':
            case 'mail_test':
            case 'mail_ozel':
                require_once __DIR__ . '/../includes/Mailer.php';

                $kanalId = (int)($_POST['id'] ?? 0);

                if (!Mailer::isInstalled()) {
                    echo json_encode(['success' => false, 'message' => 'PHPMailer kurulu değil (composer require phpmailer/phpmailer).']);
                    break;
                }

                try {
                    $mailer = new Mailer($kanalId);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()], $jsonBayrak);
                    break;
                }

                // Kanalın SMTP ayarlarını göster (şifre maskeli)
                if ($action === 'mail_ayar') {
                    echo json_encode(['success' => true, 'data' => $mailer->getSettings()], $jsonBayrak);
                    break;
                }

                // mail_test — GERÇEK mail gönderir, EntegrasyonLoglari'na düşer
                if ($action === 'mail_test') {
                    $alici = trim((string)($_POST['alici'] ?? ''));
                    if ($alici === '' || !filter_var($alici, FILTER_VALIDATE_EMAIL)) {
                        echo json_encode(['success' => false, 'message' => 'Geçerli bir e-posta adresi girin.']);
                        break;
                    }

                    $GLOBALS['MAIL_LOG_SOURCE'] = 'ENTEGRASYON_YONETIMI_TEST';
                    echo json_encode($mailer->sendTest($alici), $jsonBayrak);
                    break;
                }

                // mail_ozel — serbest konu/içerikle GERÇEK mail gönderir
                $alici  = trim((string)($_POST['alici'] ?? ''));
                $konu   = trim((string)($_POST['konu'] ?? ''));
                $icerik = trim((string)($_POST['icerik'] ?? ''));

                if ($alici === '' || !filter_var($alici, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir alıcı e-posta adresi girin.']);
                    break;
                }
                if ($konu === '' || $icerik === '') {
                    echo json_encode(['success' => false, 'message' => 'Konu ve içerik zorunludur.']);
                    break;
                }

                $GLOBALS['MAIL_LOG_SOURCE'] = 'ENTEGRASYON_YONETIMI';
                echo json_encode($mailer->send($alici, $konu, $icerik), $jsonBayrak);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$entegrasyonlarDD = $db->fetchAll("SELECT Entegrasyonlar_id, Entegrasyonlar_Ad FROM Entegrasyonlar WHERE Entegrasyonlar_Durum = 1 ORDER BY Entegrasyonlar_Ad");
$entegrasyonlarTumu = $db->fetchAll("SELECT Entegrasyonlar_id, Entegrasyonlar_Ad FROM Entegrasyonlar ORDER BY Entegrasyonlar_Ad");

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .filtre-basligi { cursor: pointer; }
        .badge { font-size: .75rem; padding: .35em .65em; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- INFOBOX -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-plugin"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Entegrasyon</span>
                                    <span class="info-box-number" id="ib_entegrasyon">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-diagram-3"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Kanal</span>
                                    <span class="info-box-number" id="ib_kanal">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-journal-text"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugünkü İşlem</span>
                                    <span class="info-box-number" id="ib_log">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugünkü Hata</span>
                                    <span class="info-box-number" id="ib_hata">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-primary card-outline card-outline-tabs">
                        <div class="card-header p-0 border-bottom-0">
                            <ul class="nav nav-tabs" id="custom-tabs-four-tab" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="tab-entegrasyonlar" data-bs-toggle="pill" href="#content-entegrasyonlar" role="tab" aria-controls="content-entegrasyonlar" aria-selected="true">Entegrasyonlar</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="tab-kanallar" data-bs-toggle="pill" href="#content-kanallar" role="tab" aria-controls="content-kanallar" aria-selected="false">Kanallar (Firmalar)</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="tab-loglar" data-bs-toggle="pill" href="#content-loglar" role="tab" aria-controls="content-loglar" aria-selected="false">Loglar</a>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="custom-tabs-four-tabContent">

                                <!-- ENTEGRASYONLAR TAB -->
                                <div class="tab-pane fade show active" id="content-entegrasyonlar" role="tabpanel" aria-labelledby="tab-entegrasyonlar">

                                    <!-- Filtre -->
                                    <div class="card card-secondary card-outline mb-3">
                                        <div class="card-header filtre-basligi" data-bs-toggle="collapse" data-bs-target="#filtreEntegrasyon">
                                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                                            <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                                        </div>
                                        <div class="collapse show" id="filtreEntegrasyon">
                                            <div class="card-body">
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-md-5">
                                                        <label class="form-label">Entegrasyon Adı</label>
                                                        <input type="text" class="form-control" id="fe_ad" placeholder="Ara...">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Durum</label>
                                                        <select class="form-select filtre-select2" id="fe_durum">
                                                            <option value="">Tümü</option>
                                                            <option value="1">Aktif</option>
                                                            <option value="0">Pasif</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <button class="btn btn-primary" onclick="loadEntegrasyonlar()"><i class="bi bi-search"></i> Filtrele</button>
                                                        <button class="btn btn-outline-secondary" onclick="temizleEntegrasyonFiltre()"><i class="bi bi-x-circle"></i> Temizle</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3 text-end">
                                        <button class="btn btn-primary btn-sm" onclick="openEntegrasyonModal()">
                                            <i class="bi bi-plus-circle"></i> Yeni Entegrasyon
                                        </button>
                                    </div>
                                    <table class="table table-bordered table-striped table-hover w-100" id="entegrasyonTable">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Entegrasyon Adı</th>
                                                <th>Ayarlar (JSON)</th>
                                                <th>Kanal</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- KANALLAR TAB -->
                                <div class="tab-pane fade" id="content-kanallar" role="tabpanel" aria-labelledby="tab-kanallar">

                                    <!-- Filtre -->
                                    <div class="card card-secondary card-outline mb-3">
                                        <div class="card-header filtre-basligi" data-bs-toggle="collapse" data-bs-target="#filtreKanal">
                                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                                            <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                                        </div>
                                        <div class="collapse show" id="filtreKanal">
                                            <div class="card-body">
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-md-4">
                                                        <label class="form-label">Entegrasyon</label>
                                                        <select class="form-select filtre-select2" id="fk_entegrasyon">
                                                            <option value="">Tümü</option>
                                                            <?php foreach ($entegrasyonlarTumu as $ent): ?>
                                                            <option value="<?= $ent['Entegrasyonlar_id'] ?>"><?= htmlspecialchars($ent['Entegrasyonlar_Ad']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Kanal / Kullanıcı</label>
                                                        <input type="text" class="form-control" id="fk_ad" placeholder="Ara...">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Durum</label>
                                                        <select class="form-select filtre-select2" id="fk_durum">
                                                            <option value="">Tümü</option>
                                                            <option value="1">Aktif</option>
                                                            <option value="0">Pasif</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <button class="btn btn-primary" onclick="loadKanallar()"><i class="bi bi-search"></i> Filtrele</button>
                                                        <button class="btn btn-outline-secondary" onclick="temizleKanalFiltre()"><i class="bi bi-x-circle"></i> Temizle</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3 text-end">
                                        <button class="btn btn-primary btn-sm" onclick="openKanalModal()">
                                            <i class="bi bi-plus-circle"></i> Yeni Kanal
                                        </button>
                                    </div>
                                    <table class="table table-bordered table-striped table-hover w-100" id="kanalTable">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Entegrasyon</th>
                                                <th>Kanal / Firma Adı</th>
                                                <th>Kullanıcı Adı</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- LOGLAR TAB -->
                                <div class="tab-pane fade" id="content-loglar" role="tabpanel" aria-labelledby="tab-loglar">

                                    <!-- Filtre -->
                                    <div class="card card-secondary card-outline mb-3">
                                        <div class="card-header filtre-basligi" data-bs-toggle="collapse" data-bs-target="#filtreLog">
                                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                                            <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                                        </div>
                                        <div class="collapse show" id="filtreLog">
                                            <div class="card-body">
                                                <div class="row g-2">
                                                    <div class="col-md-2">
                                                        <label class="form-label">Başlangıç</label>
                                                        <input type="date" class="form-control" id="fl_bas">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Bitiş</label>
                                                        <input type="date" class="form-control" id="fl_bit">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Entegrasyon</label>
                                                        <select class="form-select filtre-select2" id="fl_entegrasyon">
                                                            <option value="">Tümü</option>
                                                            <?php foreach ($entegrasyonlarTumu as $ent): ?>
                                                            <option value="<?= $ent['Entegrasyonlar_id'] ?>"><?= htmlspecialchars($ent['Entegrasyonlar_Ad']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Kanal</label>
                                                        <select class="form-select filtre-select2" id="fl_kanal">
                                                            <option value="">Tümü</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">İşlem Tipi</label>
                                                        <select class="form-select filtre-select2" id="fl_tip">
                                                            <option value="">Tümü</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label">Sonuç</label>
                                                        <select class="form-select filtre-select2" id="fl_sonuc">
                                                            <option value="">Tümü</option>
                                                            <option value="1">Başarılı</option>
                                                            <option value="0">Hata</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Arama (hedef / hata mesajı)</label>
                                                        <input type="text" class="form-control" id="fl_arama" placeholder="Ara...">
                                                    </div>
                                                    <div class="col-md-8 d-flex align-items-end">
                                                        <button class="btn btn-primary me-2" onclick="loadLoglar()"><i class="bi bi-search"></i> Filtrele</button>
                                                        <button class="btn btn-outline-secondary" onclick="temizleLogFiltre()"><i class="bi bi-x-circle"></i> Temizle</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="log_uyari" class="alert alert-warning d-none py-2">
                                        <i class="bi bi-info-circle"></i>
                                        Sonuç sınırına ulaşıldı; yalnız en yeni <span id="log_limit"></span> kayıt gösteriliyor. Tarih aralığını daraltın.
                                    </div>

                                    <table class="table table-bordered table-striped table-hover w-100" id="logTable">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Entegrasyon</th>
                                                <th>Kanal</th>
                                                <th>İşlem Tipi</th>
                                                <th>Hedef</th>
                                                <th>Tarih</th>
                                                <th>Sonuç</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Entegrasyon Modal -->
    <div class="modal fade" id="entegrasyonModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="entegrasyonModalTitle">Entegrasyon</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="entegrasyonForm">
                    <input type="hidden" id="ent_id" name="id" value="0">
                    <input type="hidden" name="type" value="entegrasyon">
                    <input type="hidden" name="action" value="save">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Entegrasyon Adı *</label>
                            <input type="text" class="form-control" id="ent_ad" name="ad" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ayarlar (JSON)</label>
                            <textarea class="form-control text-monospace" id="ent_ayar" name="ayar" rows="4" placeholder='{"BaseURL": "..."}'></textarea>
                            <small class="text-muted">Geçerli bir JSON verisi girilmelidir.</small>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="ent_durum" name="durum" value="1" checked>
                            <label class="form-check-label" for="ent_durum">Aktif</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Kanal Modal -->
    <div class="modal fade" id="kanalModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kanalModalTitle">Kanal (Firma)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="kanalForm">
                    <input type="hidden" id="kanal_id" name="id" value="0">
                    <input type="hidden" name="type" value="kanal">
                    <input type="hidden" name="action" value="save">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Entegrasyon Seçimi *</label>
                                <select class="form-select select2" id="kanal_entegrasyon_id" name="entegrasyon_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($entegrasyonlarDD as $ent): ?>
                                    <option value="<?= $ent['Entegrasyonlar_id'] ?>"><?= htmlspecialchars($ent['Entegrasyonlar_Ad']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kanal / Firma Adı *</label>
                                <input type="text" class="form-control" id="kanal_ad" name="ad" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kullanıcı Adı / API Key</label>
                                <input type="text" class="form-control" id="kanal_kullanici" name="kullanici">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Şifre / Secret</label>
                                <input type="text" class="form-control" id="kanal_sifre" name="sifre">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Ek Ayarlar (JSON)</label>
                                <textarea class="form-control text-monospace" id="kanal_ayar" name="ayar" rows="3"></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kanal_durum" name="durum" value="1" checked>
                                    <label class="form-check-label" for="kanal_durum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- WhatsApp Instance Yönetimi Modal -->
    <div class="modal fade" id="waModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-whatsapp text-success"></i> WhatsApp Instance Yönetimi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="wa_kanal_id" value="0">

                    <dl class="row small mb-3">
                        <dt class="col-sm-3">Kanal</dt><dd class="col-sm-9" id="wa_kanal_ad">-</dd>
                        <dt class="col-sm-3">Instance</dt><dd class="col-sm-9" id="wa_instance">-</dd>
                        <dt class="col-sm-3">Bağlantı</dt><dd class="col-sm-9" id="wa_durum"><span class="badge bg-secondary">Sorgulanmadı</span></dd>
                        <dt class="col-sm-3">Numara / Profil</dt><dd class="col-sm-9" id="wa_numara">-</dd>
                    </dl>

                    <div class="mb-3">
                        <button class="btn btn-sm btn-primary" onclick="waDurum()"><i class="bi bi-arrow-clockwise"></i> Durumu Yenile</button>
                        <button class="btn btn-sm btn-success" onclick="waQr()"><i class="bi bi-qr-code"></i> QR Kod Al</button>
                        <button class="btn btn-sm btn-warning" onclick="waRestart()"><i class="bi bi-bootstrap-reboot"></i> Yeniden Başlat</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="waLogout()"><i class="bi bi-box-arrow-right"></i> Oturumu Kapat</button>
                    </div>

                    <div id="wa_qr_kutu" class="text-center d-none mb-3">
                        <img id="wa_qr_img" alt="QR Kod" style="max-width: 260px;" class="border rounded p-2">
                        <div class="small text-muted mt-2">WhatsApp &rarr; Bağlı Cihazlar &rarr; Cihaz Bağla ile okutun.</div>
                        <div class="small mt-1" id="wa_qr_kod"></div>
                    </div>

                    <hr>

                    <h6 class="fw-bold">Test Mesajı</h6>
                    <p class="small text-muted">Bu işlem <strong>gerçek mesaj gönderir</strong> ve log kaydı oluşturur.</p>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="wa_test_telefon" placeholder="905xxxxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="wa_test_mesaj" placeholder="Test mesajı" value="Portal test mesajı">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button class="btn btn-success" onclick="waTestMesaj()"><i class="bi bi-send"></i> Gönder</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mail Gönderim Modal -->
    <div class="modal fade" id="mailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-envelope-gear text-primary"></i> Mail Gönderim Yönetimi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="mail_kanal_id" value="0">

                    <dl class="row small mb-3">
                        <dt class="col-sm-3">Kanal</dt><dd class="col-sm-9" id="mail_kanal_ad">-</dd>
                        <dt class="col-sm-3">SMTP sunucu</dt><dd class="col-sm-9" id="mail_host">-</dd>
                        <dt class="col-sm-3">Kullanıcı adı</dt><dd class="col-sm-9" id="mail_kullanici">-</dd>
                        <dt class="col-sm-3">Gönderen</dt><dd class="col-sm-9" id="mail_gonderen">-</dd>
                    </dl>

                    <div class="mb-3">
                        <button class="btn btn-sm btn-primary" onclick="mailAyarYenile()"><i class="bi bi-arrow-clockwise"></i> Ayarları Yenile</button>
                        <button class="btn btn-sm btn-secondary" onclick="testKanal($('#mail_kanal_id').val())"><i class="bi bi-plug"></i> Bağlantıyı Test Et</button>
                    </div>

                    <hr>

                    <h6 class="fw-bold">Test Maili</h6>
                    <p class="small text-muted">Bu işlem <strong>gerçek mail gönderir</strong> ve log kaydı oluşturur.</p>
                    <div class="row g-2 mb-3">
                        <div class="col-md-8">
                            <input type="email" class="form-control" id="mail_test_alici" placeholder="test@example.com">
                        </div>
                        <div class="col-md-4 d-grid">
                            <button class="btn btn-success" onclick="mailTestGonder()"><i class="bi bi-send"></i> Test Maili Gönder</button>
                        </div>
                    </div>

                    <hr>

                    <h6 class="fw-bold">Özel Mail</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">Alıcı</label>
                            <input type="email" class="form-control" id="mail_ozel_alici" placeholder="alici@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Konu</label>
                            <input type="text" class="form-control" id="mail_ozel_konu" placeholder="Mail konusu">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">İçerik (HTML desteklenir)</label>
                            <textarea class="form-control" id="mail_ozel_icerik" rows="5"></textarea>
                        </div>
                        <div class="col-12 d-grid">
                            <button class="btn btn-warning" onclick="mailOzelGonder()"><i class="bi bi-send"></i> Gönder</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Detay Modal -->
    <div class="modal fade" id="logModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Log Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <dl class="row mb-3 small">
                        <dt class="col-sm-3">Entegrasyon / Kanal</dt><dd class="col-sm-9" id="log_kanal">-</dd>
                        <dt class="col-sm-3">İşlem Tipi</dt><dd class="col-sm-9" id="log_tip">-</dd>
                        <dt class="col-sm-3">Hedef</dt><dd class="col-sm-9" id="log_hedef">-</dd>
                        <dt class="col-sm-3">Tarih / IP</dt><dd class="col-sm-9" id="log_tarih">-</dd>
                        <dt class="col-sm-3">Kaynak</dt><dd class="col-sm-9" id="log_kaynak">-</dd>
                    </dl>

                    <h6 class="fw-bold">İstek (Request)</h6>
                    <pre class="bg-light p-3 border rounded" id="log_istek" style="max-height: 200px; overflow-y: auto;"></pre>

                    <h6 class="fw-bold mt-3">Cevap (Response)</h6>
                    <pre class="bg-light p-3 border rounded" id="log_cevap" style="max-height: 200px; overflow-y: auto;"></pre>

                    <div id="log_hata_container" class="d-none">
                        <h6 class="fw-bold mt-3 text-danger">Hata Mesajı</h6>
                        <div class="alert alert-danger" id="log_hata"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        let dtEntegrasyon, dtKanal, dtLog;
        let mdEntegrasyon, mdKanal, mdLog, mdWa, mdMail;

        // Instance yönetimi bu entegrasyona bağlı kanallarda açılır
        const WA_ENTEGRASYON = 'Whatsapp (Evolution API)';

        // Mail gönderim yönetimi bu entegrasyona bağlı kanallarda açılır
        const MAIL_ENTEGRASYON = 'E-Posta (SMTP)';

        // Bağlantı testi desteklenen entegrasyonlar (PHP tarafıyla birebir aynı)
        const TEST_EDILEBILIR = <?= json_encode(TEST_EDILEBILIR, JSON_UNESCAPED_UNICODE) ?>;

        let kanalListesi = [];   // log filtresindeki kanal seçeneği (entegrasyona göre daralır)
        const dtDil = {language: {url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'}};

        function esc(v) { return $('<div>').text(v === null || v === undefined ? '' : v).html(); }

        document.addEventListener('DOMContentLoaded', function() {
            mdEntegrasyon = new bootstrap.Modal(document.getElementById('entegrasyonModal'));
            mdKanal = new bootstrap.Modal(document.getElementById('kanalModal'));
            mdLog = new bootstrap.Modal(document.getElementById('logModal'));
            mdWa = new bootstrap.Modal(document.getElementById('waModal'));
            mdMail = new bootstrap.Modal(document.getElementById('mailModal'));

            // Select2 Init
            if(typeof initSelect2 === 'function') {
                initSelect2('.select2', '#kanalModal');
            } else {
                $('.select2').select2({ theme: 'bootstrap-5', dropdownParent: $('#kanalModal') });
            }
            $('.filtre-select2').select2({ theme: 'bootstrap-5', width: '100%' });

            // Log filtresi varsayılanı: son 7 gün (tüm tabloyu çekmemek için)
            const bugun = new Date();
            const gecmis = new Date(); gecmis.setDate(gecmis.getDate() - 7);
            $('#fl_bit').val(bugun.toISOString().slice(0,10));
            $('#fl_bas').val(gecmis.toISOString().slice(0,10));

            loadStats();
            loadFiltreVerileri();
            loadEntegrasyonlar();

            // Tab changes -> Load Data
            $('a[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
                const target = $(e.target).attr('href');
                if(target === '#content-entegrasyonlar') loadEntegrasyonlar();
                if(target === '#content-kanallar') loadKanallar();
                if(target === '#content-loglar') loadLoglar();
                // Sekme gizliyken oluşan DataTables kolon genişliklerini düzelt
                $.fn.dataTable.tables({visible: true, api: true}).columns.adjust();
            });

            // Log filtresinde entegrasyon değişince kanal listesi daralsın
            $('#fl_entegrasyon').on('change', function() { kanalSecenekleriniDoldur($(this).val()); });

            // Enter ile filtreleme
            $('#fe_ad').on('keypress', e => { if(e.which === 13) loadEntegrasyonlar(); });
            $('#fk_ad').on('keypress', e => { if(e.which === 13) loadKanallar(); });
            $('#fl_arama').on('keypress', e => { if(e.which === 13) loadLoglar(); });

            // Forms submit
            $('#entegrasyonForm').on('submit', function(e) {
                e.preventDefault();
                $.post('', $(this).serialize(), function(res) {
                    if(res.success) {
                        if(typeof showToast === 'function') showToast('success', res.message);
                        mdEntegrasyon.hide();
                        loadEntegrasyonlar();
                        loadStats();
                    } else {
                        if(typeof showToast === 'function') showToast('error', res.message);
                    }
                }, 'json');
            });

            $('#kanalForm').on('submit', function(e) {
                e.preventDefault();
                $.post('', $(this).serialize(), function(res) {
                    if(res.success) {
                        if(typeof showToast === 'function') showToast('success', res.message);
                        mdKanal.hide();
                        loadKanallar();
                        loadStats();
                    } else {
                        if(typeof showToast === 'function') showToast('error', res.message);
                    }
                }, 'json');
            });
        });

        function loadStats() {
            $.post('', {action: 'stats'}, function(res) {
                if(!res.success) return;
                $('#ib_entegrasyon').text(res.data.entegrasyon);
                $('#ib_kanal').text(res.data.kanal);
                $('#ib_log').text(res.data.log_bugun);
                $('#ib_hata').text(res.data.log_hata);
            }, 'json');
        }

        function loadFiltreVerileri() {
            $.post('', {action: 'filtre_veri'}, function(res) {
                if(!res.success) return;
                kanalListesi = res.data.kanallar || [];
                kanalSecenekleriniDoldur('');

                let tipOpt = '<option value="">Tümü</option>';
                (res.data.tipler || []).forEach(t => {
                    tipOpt += `<option value="${esc(t.tip)}">${esc(t.tip)}</option>`;
                });
                $('#fl_tip').html(tipOpt).trigger('change.select2');
            }, 'json');
        }

        function kanalSecenekleriniDoldur(entegrasyonId) {
            let opt = '<option value="">Tümü</option>';
            kanalListesi
                .filter(k => !entegrasyonId || String(k.entegrasyon_id) === String(entegrasyonId))
                .forEach(k => { opt += `<option value="${k.id}">${esc(k.ad)}</option>`; });
            $('#fl_kanal').html(opt).trigger('change.select2');
        }

        function getStatusBadge(status) {
            return status == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>';
        }

        function formatJSONPreview(jsonStr) {
            if(!jsonStr) return '-';
            let metin;
            try { metin = JSON.stringify(JSON.parse(jsonStr)); } catch(e) { metin = String(jsonStr); }
            const kisa = metin.length > 60 ? metin.substring(0, 60) + '…' : metin;
            return `<small title="${esc(metin)}">${esc(kisa)}</small>`;
        }

        // ---------------- ENTEGRASYONLAR ----------------
        function temizleEntegrasyonFiltre() {
            $('#fe_ad').val('');
            $('#fe_durum').val('').trigger('change');
            loadEntegrasyonlar();
        }

        function loadEntegrasyonlar() {
            $.post('', {
                action: 'list', type: 'entegrasyon',
                f_ad: $('#fe_ad').val(), f_durum: $('#fe_durum').val()
            }, function(res) {
                if($.fn.DataTable.isDataTable('#entegrasyonTable')) $('#entegrasyonTable').DataTable().destroy();
                let tbody = '';
                (res.data || []).forEach(item => {
                    tbody += `<tr>
                        <td>${item.Entegrasyonlar_id}</td>
                        <td>${esc(item.Entegrasyonlar_Ad)}</td>
                        <td>${formatJSONPreview(item.Entegrasyonlar_AyarJSON)}</td>
                        <td>${item.KanalSayisi}</td>
                        <td>${getStatusBadge(item.Entegrasyonlar_Durum)}</td>
                        <td>
                            <button class="btn btn-sm btn-info" onclick="editEntegrasyon(${item.Entegrasyonlar_id})"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteRecord('entegrasyon', ${item.Entegrasyonlar_id})"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`;
                });
                $('#entegrasyonTable tbody').html(tbody);
                dtEntegrasyon = $('#entegrasyonTable').DataTable($.extend({}, dtDil, {dom: 'lrtip'}));
            }, 'json');
        }

        // ---------------- KANALLAR ----------------
        function temizleKanalFiltre() {
            $('#fk_ad').val('');
            $('#fk_entegrasyon').val('').trigger('change');
            $('#fk_durum').val('').trigger('change');
            loadKanallar();
        }

        function loadKanallar() {
            $.post('', {
                action: 'list', type: 'kanal',
                f_entegrasyon: $('#fk_entegrasyon').val(),
                f_ad: $('#fk_ad').val(),
                f_durum: $('#fk_durum').val()
            }, function(res) {
                if($.fn.DataTable.isDataTable('#kanalTable')) $('#kanalTable').DataTable().destroy();
                let tbody = '';
                (res.data || []).forEach(item => {
                    // Bağlantı testi yalnız desteklenen entegrasyonlar için gösterilir
                    const testBtn = TEST_EDILEBILIR.includes(item.Entegrasyonlar_Ad)
                        ? `<button class="btn btn-sm btn-secondary" title="Bağlantıyı test et" onclick="testKanal(${item.EntegrasyonKanallari_id})"><i class="bi bi-plug"></i></button> `
                        : '';
                    // WhatsApp kanallarında instance yönetimi (QR, durum, yeniden başlat)
                    const waBtn = item.Entegrasyonlar_Ad === WA_ENTEGRASYON
                        // Kanal adı JSON olarak gömülür; kesme işareti içeren adlar butonu bozmasın
                        ? `<button class="btn btn-sm btn-success" title="Instance yönetimi" onclick="openWaModal(${item.EntegrasyonKanallari_id}, ${esc(JSON.stringify(item.EntegrasyonKanallari_Ad))})"><i class="bi bi-qr-code"></i></button> `
                        : '';
                    // Mail kanallarında gönderim yönetimi (ayar, test maili, özel mail)
                    const mailBtn = item.Entegrasyonlar_Ad === MAIL_ENTEGRASYON
                        ? `<button class="btn btn-sm btn-primary" title="Mail gönderim yönetimi" onclick="openMailModal(${item.EntegrasyonKanallari_id}, ${esc(JSON.stringify(item.EntegrasyonKanallari_Ad))})"><i class="bi bi-envelope-gear"></i></button> `
                        : '';
                    tbody += `<tr>
                        <td>${item.EntegrasyonKanallari_id}</td>
                        <td>${esc(item.Entegrasyonlar_Ad || '-')}</td>
                        <td>${esc(item.EntegrasyonKanallari_Ad)}</td>
                        <td>${esc(item.EntegrasyonKanallari_KullaniciAdi || '-')}</td>
                        <td>${getStatusBadge(item.EntegrasyonKanallari_Durum)}</td>
                        <td>
                            ${waBtn}${mailBtn}${testBtn}<button class="btn btn-sm btn-info" onclick="editKanal(${item.EntegrasyonKanallari_id})"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="deleteRecord('kanal', ${item.EntegrasyonKanallari_id})"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`;
                });
                $('#kanalTable tbody').html(tbody);
                dtKanal = $('#kanalTable').DataTable($.extend({}, dtDil, {dom: 'lrtip'}));
            }, 'json');
        }

        /**
         * Kanal bağlantı testi — entegrasyona göre salt okunur bir sorgu çalıştırır.
         * ÖRNEK HOLDİNG: fatura listesi · Destek Sistemi: ticket_meta
         * Cloudflare: token doğrulama + registrar listesi · SMS: bakiye sorgusu
         * E-Posta (SMTP): sunucuya bağlanıp kimlik doğrulama
         * Hiçbiri kayıt oluşturmaz, SMS veya mail göndermez.
         */
        function testKanal(id) {
            Swal.fire({
                title: 'Bağlantı test ediliyor...',
                html: 'Servise bağlanılıyor, lütfen bekleyin.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            $.post('', {action: 'test_baglanti', type: 'kanal', id: id}, function(res) {
                if (!res.satirlar) {
                    Swal.fire({icon: 'error', title: 'Test başarısız', text: res.message || 'Bilinmeyen hata.'});
                    return;
                }

                let satirlar = res.satirlar.map(s => `
                    <tr>
                        <td class="text-start">${esc(s.etiket)}</td>
                        <td>${s.ok
                            ? '<span class="badge bg-success">Başarılı</span>'
                            : '<span class="badge bg-danger">Hata</span>'}</td>
                        <td class="text-start small" style="word-break:break-all;">${esc(s.mesaj)}</td>
                    </tr>`).join('');

                Swal.fire({
                    icon: res.success ? 'success' : 'error',
                    title: res.success ? 'Bağlantı başarılı' : 'Bağlantıda sorun var',
                    width: 720,
                    html: `
                        <p class="mb-2 text-muted small">
                            ${esc(res.entegrasyon || '-')} &middot;
                            Kanal: <strong>${esc(res.kanal || '-')}</strong>
                            ${res.aralik ? ' &middot; Tarih aralığı: ' + esc(res.aralik) : ''}
                        </p>
                        <table class="table table-sm table-bordered mb-2">
                            <thead class="table-light">
                                <tr><th class="text-start">Kontrol</th><th>Durum</th><th class="text-start">Sonuç</th></tr>
                            </thead>
                            <tbody>${satirlar}</tbody>
                        </table>
                        <p class="text-muted small mb-0">
                            Bu test yalnızca sorgulama yapar; kayıt oluşturmaz, mesaj veya mail göndermez.
                        </p>`
                });
            }, 'json').fail(function() {
                Swal.fire({icon: 'error', title: 'Test başarısız', text: 'Sunucuya ulaşılamadı.'});
            });
        }

        // ---------------- WHATSAPP INSTANCE YÖNETİMİ ----------------
        function openWaModal(kanalId, kanalAd) {
            $('#wa_kanal_id').val(kanalId);
            $('#wa_kanal_ad').text(kanalAd);
            $('#wa_instance').text('-');
            $('#wa_numara').text('-');
            $('#wa_durum').html('<span class="badge bg-secondary">Sorgulanıyor...</span>');
            $('#wa_qr_kutu').addClass('d-none');
            $('#wa_test_telefon').val('');
            mdWa.show();
            waDurum();
        }

        function waIstek(action, ek, tamamlandi) {
            const veri = Object.assign({action: action, id: $('#wa_kanal_id').val()}, ek || {});
            return $.post('', veri, tamamlandi, 'json').fail(function() {
                if(typeof showToast === 'function') showToast('error', 'Sunucuya ulaşılamadı.');
            });
        }

        function waDurum() {
            $('#wa_durum').html('<span class="badge bg-secondary">Sorgulanıyor...</span>');
            waIstek('wa_durum', null, function(res) {
                if(!res.success) {
                    $('#wa_durum').html('<span class="badge bg-danger">Hata</span>');
                    $('#wa_instance').text(res.message || '-');
                    return;
                }
                $('#wa_instance').text(res.instance);
                $('#wa_durum').html(res.bagli
                    ? `<span class="badge bg-success">Bağlı (${esc(res.durum)})</span>`
                    : `<span class="badge bg-danger">Bağlı değil (${esc(res.durum)})</span>`);
                $('#wa_numara').text((res.telefon || '-') + (res.profil ? ' · ' + res.profil : ''));
                // Bağlıyken QR anlamsızdır
                if(res.bagli) $('#wa_qr_kutu').addClass('d-none');
            });
        }

        function waQr() {
            $('#wa_qr_kutu').addClass('d-none');
            waIstek('wa_qr', null, function(res) {
                if(res.qr) {
                    $('#wa_qr_img').attr('src', res.qr);
                    $('#wa_qr_kod').text(res.kod ? 'Eşleştirme kodu: ' + res.kod : '');
                    $('#wa_qr_kutu').removeClass('d-none');
                } else {
                    if(typeof showToast === 'function') showToast('warning', res.message || 'QR alınamadı.');
                }
            });
        }

        function waRestart() {
            Swal.fire({
                icon: 'question',
                title: 'Instance yeniden başlatılsın mı?',
                text: 'Bağlantı kısa süreliğine kesilir.',
                showCancelButton: true, confirmButtonText: 'Yeniden başlat', cancelButtonText: 'İptal'
            }).then(s => {
                if(!s.isConfirmed) return;
                waIstek('wa_restart', null, function(res) {
                    if(typeof showToast === 'function') showToast(res.success ? 'success' : 'error', res.message);
                    if(res.success) setTimeout(waDurum, 2000);
                });
            });
        }

        function waLogout() {
            Swal.fire({
                icon: 'warning',
                title: 'Oturum kapatılsın mı?',
                text: 'Yeniden bağlanmak için QR okutmanız gerekir.',
                showCancelButton: true, confirmButtonText: 'Oturumu kapat', cancelButtonText: 'İptal',
                confirmButtonColor: '#dc3545'
            }).then(s => {
                if(!s.isConfirmed) return;
                waIstek('wa_logout', null, function(res) {
                    if(typeof showToast === 'function') showToast(res.success ? 'success' : 'error', res.message);
                    if(res.success) setTimeout(waDurum, 1500);
                });
            });
        }

        function waTestMesaj() {
            const telefon = $('#wa_test_telefon').val().trim();
            const mesaj = $('#wa_test_mesaj').val().trim();
            if(!telefon || !mesaj) {
                if(typeof showToast === 'function') showToast('warning', 'Telefon ve mesaj zorunludur.');
                return;
            }
            Swal.fire({
                icon: 'question',
                title: 'Test mesajı gönderilsin mi?',
                html: `<strong>${esc(telefon)}</strong> numarasına gerçek mesaj gönderilecek.`,
                showCancelButton: true, confirmButtonText: 'Gönder', cancelButtonText: 'İptal'
            }).then(s => {
                if(!s.isConfirmed) return;
                waIstek('wa_test_mesaj', {telefon: telefon, mesaj: mesaj}, function(res) {
                    if(typeof showToast === 'function') showToast(res.success ? 'success' : 'error', res.message);
                });
            });
        }

        // ---------------- MAIL GÖNDERİM YÖNETİMİ ----------------
        function openMailModal(kanalId, kanalAd) {
            $('#mail_kanal_id').val(kanalId);
            $('#mail_kanal_ad').text(kanalAd);
            $('#mail_host').text('-');
            $('#mail_kullanici').text('-');
            $('#mail_gonderen').text('-');
            $('#mail_test_alici, #mail_ozel_alici, #mail_ozel_konu, #mail_ozel_icerik').val('');
            mdMail.show();
            mailAyarYenile();
        }

        function mailIstek(action, ek, tamamlandi) {
            const veri = $.extend({action: action, id: $('#mail_kanal_id').val()}, ek || {});
            $.post('', veri, tamamlandi, 'json').fail(function() {
                if(typeof showToast === 'function') showToast('error', 'Sunucuya ulaşılamadı.');
            });
        }

        function mailAyarYenile() {
            mailIstek('mail_ayar', {}, function(res) {
                if(!res.success) {
                    $('#mail_host').html('<span class="text-danger">' + esc(res.message) + '</span>');
                    return;
                }
                const d = res.data || {};
                $('#mail_host').text(`${d.host || '-'}:${d.port || '-'} (${(d.encryption || '-').toUpperCase()})`);
                $('#mail_kullanici').text(d.username || '-');
                $('#mail_gonderen').text(`${d.from_name || ''} <${d.from_address || '-'}>`);
            });
        }

        function mailTestGonder() {
            const alici = $('#mail_test_alici').val().trim();
            if(!alici) {
                if(typeof showToast === 'function') showToast('warning', 'Alıcı e-posta adresi zorunludur.');
                return;
            }
            Swal.fire({
                icon: 'question',
                title: 'Test maili gönderilsin mi?',
                html: `<strong>${esc(alici)}</strong> adresine gerçek mail gönderilecek.`,
                showCancelButton: true, confirmButtonText: 'Gönder', cancelButtonText: 'İptal'
            }).then(s => {
                if(!s.isConfirmed) return;
                Swal.fire({title: 'Gönderiliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                mailIstek('mail_test', {alici: alici}, function(res) {
                    Swal.fire({icon: res.success ? 'success' : 'error', title: res.success ? 'Gönderildi' : 'Hata', text: res.message});
                    loadStats();
                });
            });
        }

        function mailOzelGonder() {
            const alici  = $('#mail_ozel_alici').val().trim();
            const konu   = $('#mail_ozel_konu').val().trim();
            const icerik = $('#mail_ozel_icerik').val().trim();

            if(!alici || !konu || !icerik) {
                if(typeof showToast === 'function') showToast('warning', 'Alıcı, konu ve içerik zorunludur.');
                return;
            }
            Swal.fire({
                icon: 'question',
                title: 'Mail gönderilsin mi?',
                html: `<strong>${esc(alici)}</strong> adresine gerçek mail gönderilecek.`,
                showCancelButton: true, confirmButtonText: 'Gönder', cancelButtonText: 'İptal'
            }).then(s => {
                if(!s.isConfirmed) return;
                Swal.fire({title: 'Gönderiliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                mailIstek('mail_ozel', {alici: alici, konu: konu, icerik: icerik}, function(res) {
                    Swal.fire({icon: res.success ? 'success' : 'error', title: res.success ? 'Gönderildi' : 'Hata', text: res.message});
                    if(res.success) $('#mail_ozel_alici, #mail_ozel_konu, #mail_ozel_icerik').val('');
                    loadStats();
                });
            });
        }

        // ---------------- LOGLAR ----------------
        function temizleLogFiltre() {
            const bugun = new Date();
            const gecmis = new Date(); gecmis.setDate(gecmis.getDate() - 7);
            $('#fl_bit').val(bugun.toISOString().slice(0,10));
            $('#fl_bas').val(gecmis.toISOString().slice(0,10));
            $('#fl_arama').val('');
            $('#fl_entegrasyon').val('').trigger('change');
            kanalSecenekleriniDoldur('');
            $('#fl_tip').val('').trigger('change');
            $('#fl_sonuc').val('').trigger('change');
            loadLoglar();
        }

        function loadLoglar() {
            $.post('', {
                action: 'list', type: 'log',
                f_bas: $('#fl_bas').val(),
                f_bit: $('#fl_bit').val(),
                f_entegrasyon: $('#fl_entegrasyon').val(),
                f_kanal: $('#fl_kanal').val(),
                f_tip: $('#fl_tip').val(),
                f_sonuc: $('#fl_sonuc').val(),
                f_arama: $('#fl_arama').val()
            }, function(res) {
                if($.fn.DataTable.isDataTable('#logTable')) $('#logTable').DataTable().destroy();

                if(!res || !res.success) {
                    $('#logTable tbody').html('');
                    if(typeof showToast === 'function') showToast('error', (res && res.message) || 'Loglar yüklenemedi.');
                    dtLog = $('#logTable').DataTable($.extend({}, dtDil, {dom: 'lrtip'}));
                    return;
                }

                $('#log_limit').text(res.limit);
                $('#log_uyari').toggleClass('d-none', !res.kesildi);

                let tbody = '';
                (res.data || []).forEach(item => {
                    const b = item.basarili == 1
                        ? '<span class="badge bg-success">Başarılı</span>'
                        : '<span class="badge bg-danger">Hata</span>';
                    tbody += `<tr>
                        <td>${item.log_id}</td>
                        <td>${esc(item.entegrasyon_adi || '-')}</td>
                        <td>${esc(item.kanal_adi || '-')}</td>
                        <td>${esc(item.islem_tipi || '-')}</td>
                        <td>${esc(item.hedef || '-')}</td>
                        <td>${esc(item.tarih || '-')}</td>
                        <td>${b}</td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick="viewLog(${item.log_id})"><i class="bi bi-eye"></i> Detay</button>
                        </td>
                    </tr>`;
                });
                $('#logTable tbody').html(tbody);
                dtLog = $('#logTable').DataTable($.extend({}, dtDil, {dom: 'lrtip', order: [[0, 'desc']], pageLength: 25}));
            }, 'json').fail(function() {
                if(typeof showToast === 'function') showToast('error', 'Loglar yüklenemedi (sunucu hatası).');
            });
        }

        function viewLog(id) {
            // İstek/Cevap gövdeleri listede taşınmaz; detay tek kayıt olarak çekilir
            $.post('', {action: 'get', type: 'log', id: id}, function(res) {
                if(!res.success || !res.data) {
                    if(typeof showToast === 'function') showToast('error', 'Log kaydı bulunamadı.');
                    return;
                }
                const log = res.data;
                $('#log_kanal').text((log.entegrasyon_adi || '-') + ' / ' + (log.kanal_adi || '-'));
                $('#log_tip').text(log.islem_tipi || '-');
                $('#log_hedef').text(log.hedef || '-');
                $('#log_tarih').text((log.tarih || '-') + (log.ip ? ' · ' + log.ip : ''));
                $('#log_kaynak').text(log.kaynak || '-');
                $('#log_istek').text(log.istek || 'Veri yok');
                $('#log_cevap').text(log.cevap || 'Veri yok');

                if(log.basarili != 1 && log.hata) {
                    $('#log_hata_container').removeClass('d-none');
                    $('#log_hata').text(log.hata);
                } else {
                    $('#log_hata_container').addClass('d-none');
                }
                mdLog.show();
            }, 'json');
        }

        // ---------------- ORTAK ----------------
        function openEntegrasyonModal() {
            $('#entegrasyonForm')[0].reset();
            $('#ent_id').val(0);
            mdEntegrasyon.show();
        }

        function editEntegrasyon(id) {
            $.post('', {action: 'get', type: 'entegrasyon', id: id}, function(res) {
                if(res.success) {
                    let d = res.data;
                    $('#ent_id').val(d.Entegrasyonlar_id);
                    $('#ent_ad').val(d.Entegrasyonlar_Ad);
                    $('#ent_ayar').val(d.Entegrasyonlar_AyarJSON);
                    $('#ent_durum').prop('checked', d.Entegrasyonlar_Durum == 1);
                    mdEntegrasyon.show();
                }
            }, 'json');
        }

        function openKanalModal() {
            $('#kanalForm')[0].reset();
            $('#kanal_id').val(0);
            if($('#kanal_entegrasyon_id').data('select2')) $('#kanal_entegrasyon_id').val('').trigger('change');
            mdKanal.show();
        }

        function editKanal(id) {
            $.post('', {action: 'get', type: 'kanal', id: id}, function(res) {
                if(res.success) {
                    let d = res.data;
                    $('#kanal_id').val(d.EntegrasyonKanallari_id);
                    $('#kanal_ad').val(d.EntegrasyonKanallari_Ad);
                    $('#kanal_kullanici').val(d.EntegrasyonKanallari_KullaniciAdi);
                    $('#kanal_sifre').val(d.EntegrasyonKanallari_Sifre);
                    $('#kanal_ayar').val(d.EntegrasyonKanallari_AyarJSON);
                    $('#kanal_durum').prop('checked', d.EntegrasyonKanallari_Durum == 1);
                    if($('#kanal_entegrasyon_id').data('select2')) {
                        $('#kanal_entegrasyon_id').val(d.EntegrasyonKanallari_Entegrasyonlar_id).trigger('change');
                    } else {
                        $('#kanal_entegrasyon_id').val(d.EntegrasyonKanallari_Entegrasyonlar_id);
                    }
                    mdKanal.show();
                }
            }, 'json');
        }

        function deleteRecord(type, id) {
            const sil = function() {
                $.post('', {action: 'delete', type: type, id: id}, function(res) {
                    if(res.success) {
                        if(typeof showToast === 'function') showToast('success', res.message);
                        if(type === 'entegrasyon') loadEntegrasyonlar();
                        if(type === 'kanal') loadKanallar();
                        loadStats();
                    } else {
                        if(typeof showToast === 'function') showToast('error', res.message);
                    }
                }, 'json');
            };

            if(typeof confirmDelete === 'function') {
                confirmDelete(sil);
            } else if(confirm('Silmek istediğinize emin misiniz?')) {
                sil();
            }
        }
    </script>
</body>
</html>
