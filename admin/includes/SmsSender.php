<?php
/**
 * Ortak SMS Gönderim Helper'ı
 *
 * SMS sağlayıcıları artık ortak entegrasyon yapısında tutulur:
 *   Entegrasyonlar (Ad = 'SMS')  ->  EntegrasyonKanallari  ->  EntegrasyonLoglari
 *
 * Kanala özel tüm ayarlar EntegrasyonKanallari_AyarJSON içindedir:
 *   kod, api_url, api_method, content_type, request_format, auth_type,
 *   sender, api_key, success_pattern, body_template,
 *   toplu_destek, toplu_body_template, toplu_limit, varsayilan, ekstra_param
 *
 * Kullanıcı adı / şifre kanalın kendi kolonlarındadır (şifre AES-128-CBC).
 *
 * Desteklenen kimlik doğrulama (auth_type):
 *   - none   : Kimlik doğrulama yok
 *   - basic  : HTTP Basic Auth (kullanici_adi + sifre)
 *   - bearer : Authorization: Bearer {api_key}
 *
 * Şablon değişkenleri:
 *   Tekil : {{username}} {{password}} {{sender}} {{phone}} {{message}} {{api_key}} {{title}}
 *   Toplu : yukarıdakiler + {{numbers}}  (ham JSON dizi olarak yerleştirilir)
 *
 * @author Batuhan Kahraman
 */

if (!defined('ORNEK_SMS_ENTEGRASYON_ADI')) {
    define('ORNEK_SMS_ENTEGRASYON_ADI', 'SMS');
}

if (!function_exists('ornek_sms_decrypt')) {
    /**
     * Şifreli alanı çözer (kanal şifresi / api_key)
     */
    function ornek_sms_decrypt($encrypted) {
        if (empty($encrypted)) return '';
        $key = 'degistir_bu_anahtari';
        $plain = openssl_decrypt(base64_decode($encrypted), 'AES-128-CBC', $key, 0, substr(md5($key), 0, 16));
        // Şifrelenmemiş (düz) kaydedilmiş değerler için geri düşüş
        return $plain === false ? (string)$encrypted : $plain;
    }
}

if (!function_exists('ornek_sms_encrypt')) {
    /**
     * Kanal şifresi / api_key şifreler
     */
    function ornek_sms_encrypt($plain) {
        if ($plain === null || $plain === '') return '';
        $key = 'degistir_bu_anahtari';
        return base64_encode(openssl_encrypt($plain, 'AES-128-CBC', $key, 0, substr(md5($key), 0, 16)));
    }
}

if (!function_exists('ornek_sms_entegrasyon_id')) {
    /**
     * 'SMS' entegrasyonunun id'sini döner (yoksa 0)
     */
    function ornek_sms_entegrasyon_id($db) {
        static $id = null;
        if ($id !== null) return $id;

        $row = $db->fetchOne(
            "SELECT Entegrasyonlar_id FROM Entegrasyonlar WHERE Entegrasyonlar_Ad = ? AND Entegrasyonlar_Durum = 1",
            [ORNEK_SMS_ENTEGRASYON_ADI]
        );
        $id = (int)($row['Entegrasyonlar_id'] ?? 0);
        return $id;
    }
}

if (!function_exists('ornek_sms_kanal_normalize')) {
    /**
     * Ham EntegrasyonKanallari satırını SMS kanal dizisine çevirir.
     * Şifre ve api_key çözülmüş olarak döner.
     *
     * @param array $row EntegrasyonKanallari satırı (tüm kolonlar)
     * @return array
     */
    function ornek_sms_kanal_normalize(array $row) {
        $ayar = [];
        if (!empty($row['EntegrasyonKanallari_AyarJSON'])) {
            $ayar = json_decode($row['EntegrasyonKanallari_AyarJSON'], true) ?: [];
        }

        $ekstra = $ayar['ekstra_param'] ?? [];
        if (is_string($ekstra)) {
            $ekstra = json_decode($ekstra, true) ?: [];
        }

        return [
            'id'                  => (int)($row['EntegrasyonKanallari_id'] ?? 0),
            'ad'                  => (string)($row['EntegrasyonKanallari_Ad'] ?? ''),
            'kod'                 => (string)($ayar['kod'] ?? ''),
            'kullanici_adi'       => (string)($row['EntegrasyonKanallari_KullaniciAdi'] ?? ''),
            'sifre'               => ornek_sms_decrypt($row['EntegrasyonKanallari_Sifre'] ?? ''),
            'api_key'             => ornek_sms_decrypt($ayar['api_key'] ?? ''),
            'sender'              => (string)($ayar['sender'] ?? ''),
            'api_url'             => trim((string)($ayar['api_url'] ?? '')),
            'kredi_url'           => trim((string)($ayar['kredi_url'] ?? '')),
            'api_method'          => strtoupper((string)($ayar['api_method'] ?? 'POST')),
            'content_type'        => (string)($ayar['content_type'] ?? 'application/x-www-form-urlencoded'),
            'request_format'      => strtolower((string)($ayar['request_format'] ?? 'form')),
            'auth_type'           => strtolower((string)($ayar['auth_type'] ?? 'none')),
            'success_pattern'     => (string)($ayar['success_pattern'] ?? ''),
            'body_template'       => (string)($ayar['body_template'] ?? ''),
            'toplu_destek'        => !empty($ayar['toplu_destek']) && !empty($ayar['toplu_body_template']),
            'toplu_body_template' => (string)($ayar['toplu_body_template'] ?? ''),
            'toplu_limit'         => max(1, (int)($ayar['toplu_limit'] ?? 500)),
            'varsayilan'          => !empty($ayar['varsayilan']),
            'durum'               => (int)($row['EntegrasyonKanallari_Durum'] ?? 0),
            'ekstra_param'        => is_array($ekstra) ? $ekstra : [],
        ];
    }
}

if (!function_exists('ornek_sms_kanallar')) {
    /**
     * SMS entegrasyonuna bağlı kanalları döner (normalize edilmiş).
     *
     * @param object $db          Database örneği
     * @param bool   $sadeceAktif Sadece Durum = 1 olanlar
     * @return array
     */
    function ornek_sms_kanallar($db, $sadeceAktif = true) {
        $entId = ornek_sms_entegrasyon_id($db);
        if (!$entId) return [];

        $sql = "SELECT * FROM EntegrasyonKanallari
                WHERE EntegrasyonKanallari_Entegrasyonlar_id = ?";
        if ($sadeceAktif) {
            $sql .= " AND EntegrasyonKanallari_Durum = 1";
        }
        $sql .= " ORDER BY EntegrasyonKanallari_Ad";

        $rows = $db->fetchAll($sql, [$entId]) ?: [];

        return array_map('ornek_sms_kanal_normalize', $rows);
    }
}

if (!function_exists('ornek_sms_kanal')) {
    /**
     * Tek kanal döner. $id verilirse o kanal, verilmezse varsayılan kanal.
     * Varsayılan işaretli kanal yoksa ilk aktif kanal döner.
     *
     * @return array|null
     */
    function ornek_sms_kanal($db, $id = 0) {
        $kanallar = ornek_sms_kanallar($db, true);
        if (empty($kanallar)) return null;

        $id = (int)$id;
        if ($id > 0) {
            foreach ($kanallar as $k) {
                if ($k['id'] === $id) return $k;
            }
            return null;
        }

        foreach ($kanallar as $k) {
            if ($k['varsayilan']) return $k;
        }

        return $kanallar[0];
    }
}

if (!function_exists('ornek_sms_paket_basligi')) {
    /**
     * Benzersiz paket başlığı üretir.
     *
     * Sağlayıcı aynı başlık + içerik kombinasyonunu tekrar kabul etmez
     * (ERR_SMS_PKG_DUPLICATION), bu yüzden başlığa saniye hassasiyetinde
     * zaman damgası ve kısa bir ayırıcı eklenir.
     */
    function ornek_sms_paket_basligi($onek = 'Portal') {
        return substr($onek, 0, 40) . ' ' . date('d.m.Y H:i:s') . '-' . substr(uniqid(), -4);
    }
}

if (!function_exists('ornek_sms_numara_temizle')) {
    /**
     * Ham telefonu 90XXXXXXXXXX formatına çevirir.
     * Geçersizse null döner.
     */
    function ornek_sms_numara_temizle($number) {
        $number = preg_replace('/[^0-9]/', '', (string)$number);
        if (substr($number, 0, 2) === '90') $number = substr($number, 2);
        if (substr($number, 0, 1) === '0')  $number = substr($number, 1);

        return strlen($number) === 10 ? '90' . $number : null;
    }
}

if (!function_exists('ornek_sms_sablon_doldur')) {
    /**
     * Şablondaki {{degisken}} yer tutucularını doldurur.
     * JSON formatında değerler kaçırılır; $ham dizisindeki anahtarlar
     * (örn. numbers) olduğu gibi yerleştirilir.
     *
     * @param string $sablon
     * @param array  $vars   Kaçırılacak değişkenler
     * @param array  $ham    Ham (kaçırılmadan) yerleştirilecek değişkenler
     * @param string $format 'json' ise değerler JSON string olarak kaçırılır
     */
    function ornek_sms_sablon_doldur($sablon, array $vars, array $ham, $format) {
        $search = [];
        $replace = [];

        foreach ($vars as $k => $v) {
            $search[]  = '{{' . $k . '}}';
            $replace[] = ($format === 'json')
                ? substr(json_encode((string)$v, JSON_UNESCAPED_UNICODE), 1, -1)
                : (string)$v;
        }

        foreach ($ham as $k => $v) {
            $search[]  = '{{' . $k . '}}';
            $replace[] = (string)$v;
        }

        return str_replace($search, $replace, (string)$sablon);
    }
}

if (!function_exists('ornek_sms_istek')) {
    /**
     * Kanala HTTP isteği atar ve başarı kontrolünü yapar.
     *
     * @return array ['success'=>bool,'response'=>string,'http_code'=>int,'error'=>?string]
     */
    function ornek_sms_istek(array $kanal, $body, $timeout = 30) {
        if ($kanal['api_url'] === '') {
            return ['success' => false, 'response' => '', 'http_code' => 0, 'error' => 'Kanalda API URL tanimli degil'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $kanal['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $kanal['api_method']);

        if ($kanal['api_method'] !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $headers = ['Content-Type: ' . $kanal['content_type']];
        if ($kanal['auth_type'] === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $kanal['api_key'];
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($kanal['auth_type'] === 'basic') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $kanal['kullanici_adi'] . ':' . $kanal['sifre']);
        }

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'response' => '', 'http_code' => 0, 'error' => 'cURL: ' . $curlError];
        }

        // Başarı: HTTP 2xx + (pattern boşsa yeterli, doluysa eşleşmeli)
        $success = ($httpCode >= 200 && $httpCode < 300);
        if ($success && $kanal['success_pattern'] !== '') {
            $success = (bool)preg_match('/' . $kanal['success_pattern'] . '/', trim((string)$response));
        }

        return [
            'success'   => $success,
            'response'  => (string)$response,
            'http_code' => $httpCode,
            'error'     => $success ? null : 'API Yanit: ' . substr(trim((string)$response), 0, 300),
        ];
    }
}

if (!function_exists('ornek_sms_send')) {
    /**
     * Tek bir numaraya SMS gönderir.
     *
     * @param string $number     Ham telefon (5xx, 05xx, 905xx, +90 5xx kabul edilir)
     * @param string $message    Mesaj içeriği
     * @param array  $kanal      ornek_sms_kanal() çıktısı
     * @param string $title      Paket başlığı (opsiyonel)
     * @param bool   $commercial Ticari ileti mi? Bilgilendirme mesajlarında false kalmalı
     * @return array ['success'=>bool,'response'=>string,'number'=>string,'http_code'=>int,'error'=>?string]
     */
    function ornek_sms_send($number, $message, array $kanal, $title = null, $commercial = false) {
        $phone = ornek_sms_numara_temizle($number);
        if ($phone === null) {
            return [
                'success'   => false,
                'error'     => 'Gecersiz numara formati (10 haneli olmali): ' . $number,
                'number'    => preg_replace('/[^0-9]/', '', (string)$number),
                'http_code' => 0,
                'response'  => '',
            ];
        }

        $vars = array_merge($kanal['ekstra_param'], [
            'username' => $kanal['kullanici_adi'],
            'password' => $kanal['sifre'],
            'sender'   => $kanal['sender'],
            'api_key'  => $kanal['api_key'],
            'phone'    => $phone,
            'message'  => $message,
            'title'    => ornek_sms_paket_basligi($title ?: 'Portal'),
        ]);

        // {{commercial}} ham JSON degeri (true/false) olarak yerlesir
        $ham = ['commercial' => $commercial ? 'true' : 'false'];

        $body   = ornek_sms_sablon_doldur($kanal['body_template'], $vars, $ham, $kanal['request_format']);
        $sonuc  = ornek_sms_istek($kanal, $body);

        $sonuc['number'] = $phone;
        return $sonuc;
    }
}

if (!function_exists('ornek_sms_send_bulk')) {
    /**
     * Birden fazla numaraya tek istekle (1-to-N) SMS gönderir.
     * Kanal toplu gönderimi desteklemiyorsa tekil döngüye düşer.
     *
     * Numaralar toplu_limit'e göre parçalara bölünür, her parça için bir istek atılır.
     *
     * @param array  $numbers    Ham telefon listesi
     * @param string $message    Mesaj içeriği
     * @param array  $kanal      ornek_sms_kanal() çıktısı
     * @param string $title      Paket başlığı (opsiyonel)
     * @param bool   $commercial Ticari ileti mi? Bilgilendirme mesajlarında false kalmalı
     * @return array [
     *   'success'   => bool,   tüm parçalar başarılıysa true
     *   'toplam'    => int,    gönderime giren geçerli numara sayısı
     *   'basarili'  => int,
     *   'basarisiz' => int,
     *   'gecersiz'  => array,  format hatası olan ham numaralar
     *   'paketler'  => array,  parça başına ['numbers'=>[], 'success'=>bool, 'response'=>'', 'error'=>'']
     *   'numara_sonuc' => array,  90XXXXXXXXXX => ['success'=>bool,'response'=>'','error'=>'']
     * ]
     */
    function ornek_sms_send_bulk(array $numbers, $message, array $kanal, $title = null, $commercial = false) {
        $gecerli  = [];
        $gecersiz = [];

        foreach ($numbers as $ham) {
            $phone = ornek_sms_numara_temizle($ham);
            if ($phone === null) {
                $gecersiz[] = (string)$ham;
                continue;
            }
            $gecerli[$phone] = true; // tekrarları ele
        }
        $gecerli = array_keys($gecerli);

        $sonuc = [
            'success'      => false,
            'toplam'       => count($gecerli),
            'basarili'     => 0,
            'basarisiz'    => 0,
            'gecersiz'     => $gecersiz,
            'paketler'     => [],
            'numara_sonuc' => [],
        ];

        if (empty($gecerli)) {
            return $sonuc;
        }

        // Toplu desteklenmiyorsa tekil gönderime düş
        if (!$kanal['toplu_destek']) {
            foreach ($gecerli as $phone) {
                $r = ornek_sms_send($phone, $message, $kanal, $title, $commercial);
                $sonuc['numara_sonuc'][$phone] = [
                    'success'  => $r['success'],
                    'response' => $r['response'],
                    'error'    => $r['error'],
                ];
                $r['success'] ? $sonuc['basarili']++ : $sonuc['basarisiz']++;
            }
            $sonuc['success'] = ($sonuc['basarisiz'] === 0);
            return $sonuc;
        }

        // Saglayici ayni baslik + icerik tekrarini reddeder (ERR_SMS_PKG_DUPLICATION)
        $baslik   = ornek_sms_paket_basligi($title ?: 'Portal Toplu');
        $parcalar = array_chunk($gecerli, $kanal['toplu_limit']);
        $parcaSayisi = count($parcalar);

        foreach ($parcalar as $i => $parca) {
            $parcaBaslik = $parcaSayisi > 1
                ? $baslik . ' (' . ($i + 1) . '/' . $parcaSayisi . ')'
                : $baslik;

            $vars = array_merge($kanal['ekstra_param'], [
                'username' => $kanal['kullanici_adi'],
                'password' => $kanal['sifre'],
                'sender'   => $kanal['sender'],
                'api_key'  => $kanal['api_key'],
                'message'  => $message,
                'title'    => $parcaBaslik,
                'phone'    => $parca[0], // tekil yer tutucu kullanan şablonlar için
            ]);

            // Ham degerler: JSON'a tirnaksiz yerlesir
            $ham = [
                'numbers'        => json_encode(array_values($parca), JSON_UNESCAPED_UNICODE),
                'numbers_virgul' => implode(',', $parca),
                'commercial'     => $commercial ? 'true' : 'false',
            ];

            $body = ornek_sms_sablon_doldur($kanal['toplu_body_template'], $vars, $ham, $kanal['request_format']);

            // Toplu istek daha uzun sürebilir
            $r = ornek_sms_istek($kanal, $body, 120);

            $sonuc['paketler'][] = [
                'numbers'  => $parca,
                'adet'     => count($parca),
                'success'  => $r['success'],
                'response' => $r['response'],
                'error'    => $r['error'],
            ];

            foreach ($parca as $phone) {
                $sonuc['numara_sonuc'][$phone] = [
                    'success'  => $r['success'],
                    'response' => $r['response'],
                    'error'    => $r['error'],
                ];
                $r['success'] ? $sonuc['basarili']++ : $sonuc['basarisiz']++;
            }
        }

        $sonuc['success'] = ($sonuc['basarisiz'] === 0);
        return $sonuc;
    }
}

if (!function_exists('ornek_sms_kredi')) {
    /**
     * Kanalın kalan SMS bakiyesini sorgular.
     *
     * Sorgu adresi AyarJSON'daki 'kredi_url' alanından okunur; tanımlı değilse
     * api_url'in yolu /user/credit ile değiştirilerek türetilir.
     *
     * @return array ['success'=>bool, 'kredi'=>?float, 'ham'=>string, 'error'=>?string]
     */
    function ornek_sms_kredi(array $kanal) {
        $url = trim((string)($kanal['kredi_url'] ?? ''));

        // Tanimli degilse api_url'den turet: http://host:port/user/credit
        if ($url === '' && $kanal['api_url'] !== '') {
            $p = parse_url($kanal['api_url']);
            if (!empty($p['host'])) {
                $url = ($p['scheme'] ?? 'http') . '://' . $p['host']
                     . (!empty($p['port']) ? ':' . $p['port'] : '')
                     . '/user/credit';
            }
        }

        if ($url === '') {
            return ['success' => false, 'kredi' => null, 'ham' => '', 'error' => 'Kredi sorgu adresi belirlenemedi'];
        }

        // Kredi sorgusu GET; gonderim ayarlarindan bagimsiz calisir
        $sorguKanali = array_merge($kanal, [
            'api_url'         => $url,
            'api_method'      => 'GET',
            'content_type'    => 'application/json',
            'success_pattern' => '',
        ]);

        $r = ornek_sms_istek($sorguKanali, '', 15);

        if (!$r['success']) {
            return ['success' => false, 'kredi' => null, 'ham' => $r['response'], 'error' => $r['error']];
        }

        // Yanittan sayisal bakiyeyi cikarmayi dene
        $kredi = null;
        $json  = json_decode($r['response'], true);

        if (is_array($json)) {
            $aday = $json['data'] ?? $json;
            if (is_numeric($aday)) {
                $kredi = (float)$aday;
            } elseif (is_array($aday)) {
                // credit / balance / amount gibi anahtarlar, yoksa ilk sayisal deger
                foreach (['credit', 'credits', 'balance', 'amount', 'kredi', 'bakiye'] as $anahtar) {
                    if (isset($aday[$anahtar]) && is_numeric($aday[$anahtar])) {
                        $kredi = (float)$aday[$anahtar];
                        break;
                    }
                }
                if ($kredi === null) {
                    foreach ($aday as $deger) {
                        if (is_numeric($deger)) { $kredi = (float)$deger; break; }
                    }
                }
            }
        } elseif (is_numeric(trim($r['response']))) {
            $kredi = (float)trim($r['response']);
        }

        return ['success' => true, 'kredi' => $kredi, 'ham' => $r['response'], 'error' => null];
    }
}

if (!function_exists('ornek_entegrasyon_log_toplu')) {
    /**
     * EntegrasyonLoglari'na çok satırlı tek INSERT ile kayıt atar.
     * SQL Server parametre limiti (2100) için 150'şer parçalanır (13 kolon x 150 = 1950).
     *
     * @param object $db
     * @param array  $satirlar Her biri:
     *   ['kanal_id','islem_tipi','istek','cevap','durum','hata','hedef','kullanici_id',
     *    'ip','kaynak','olusturan','tarih']
     * @return int Yazılan satır sayısı
     */
    function ornek_entegrasyon_log_toplu($db, array $satirlar) {
        if (empty($satirlar)) return 0;

        $kolonlar = 'EntegrasyonLoglari_EntegrasyonKanallari_id, EntegrasyonLoglari_IslemTipi,
                     EntegrasyonLoglari_Istek, EntegrasyonLoglari_Cevap, EntegrasyonLoglari_BasariliMi,
                     EntegrasyonLoglari_HataMesaji, EntegrasyonLoglari_Hedef, EntegrasyonLoglari_KullaniciId,
                     EntegrasyonLoglari_IP, EntegrasyonLoglari_Kaynak,
                     EntegrasyonLoglari_OlusturanKullanici, EntegrasyonLoglari_OlusturmaTarihi,
                     EntegrasyonLoglari_Durum';

        $simdi   = date('Y-m-d H:i:s');
        $yazilan = 0;

        foreach (array_chunk($satirlar, 150) as $parca) {
            $values = [];
            $params = [];

            foreach ($parca as $s) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
                $params[] = (int)($s['kanal_id'] ?? 0);
                $params[] = substr((string)($s['islem_tipi'] ?? 'SMS'), 0, 100);
                $params[] = (string)($s['istek'] ?? '');
                $params[] = isset($s['cevap']) ? (string)$s['cevap'] : null;
                $params[] = !empty($s['durum']) ? 1 : 0;
                $params[] = isset($s['hata']) && $s['hata'] !== null ? (string)$s['hata'] : null;
                $params[] = isset($s['hedef']) ? substr((string)$s['hedef'], 0, 200) : null;
                $params[] = $s['kullanici_id'] ?? null;
                $params[] = isset($s['ip']) ? substr((string)$s['ip'], 0, 50) : null;
                $params[] = isset($s['kaynak']) ? substr((string)$s['kaynak'], 0, 100) : null;
                $params[] = (int)($s['olusturan'] ?? 0);
                $params[] = $s['tarih'] ?? $simdi;
            }

            $sql = "INSERT INTO EntegrasyonLoglari ($kolonlar) VALUES " . implode(', ', $values);
            if ($db->execute($sql, $params)) {
                $yazilan += count($parca);
            }
        }

        return $yazilan;
    }
}

if (!function_exists('ornek_sms_log_entegrasyon')) {
    /**
     * EntegrasyonLoglari'na tek satır kanal işlemi kaydı atar.
     */
    function ornek_sms_log_entegrasyon($db, $kanalId, $islemTipi, $istek, $cevap, $basarili, $hata = null, $kullaniciId = 0, $hedef = null, $kaynak = null) {
        return ornek_entegrasyon_log_toplu($db, [[
            'kanal_id'     => $kanalId,
            'islem_tipi'   => $islemTipi,
            'istek'        => $istek,
            'cevap'        => $cevap,
            'durum'        => $basarili,
            'hata'         => $hata,
            'hedef'        => $hedef,
            'kullanici_id' => $kullaniciId ?: null,
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
            'kaynak'       => $kaynak,
            'olusturan'    => $kullaniciId,
        ]]);
    }
}
