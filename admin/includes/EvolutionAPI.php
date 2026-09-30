<?php
/**
 * Evolution API WhatsApp Helper Class
 * WhatsApp Instance yönetimi ve mesaj gönderimi
 * 
 * Evolution API Docs: https://doc.evolution-api.com/
 */

class EvolutionAPI
{
    /** Entegrasyonlar tablosundaki kayıt adı */
    const ENTEGRASYON_ADI = 'Whatsapp (Evolution API)';

    private $apiUrl;
    private $apiKey;
    private $db;
    private $kanalId;   // EntegrasyonKanallari_id — log kayıtları buna bağlanır

    /**
     * Constructor
     *
     * Bağlantı bilgileri artık WhatsApp_Instances tablosundan değil,
     * Entegrasyonlar / EntegrasyonKanallari yapısından okunur.
     *
     * @param string|null $apiUrl  API adresi (verilmezse varsayılan kanaldan alınır)
     * @param string|null $apiKey  API anahtarı (verilmezse varsayılan kanaldan alınır)
     * @param int|null    $kanalId Log kaydının bağlanacağı kanal id'si
     */
    public function __construct($apiUrl = null, $apiKey = null, $kanalId = null)
    {
        $this->db = Database::getInstance();
        $this->kanalId = $kanalId ? (int)$kanalId : null;

        if (!$apiUrl || !$apiKey) {
            $kanal = self::varsayilanKanal();
            if ($kanal) {
                $apiUrl = $apiUrl ?: $kanal['api_url'];
                $apiKey = $apiKey ?: $kanal['api_key'];
                $this->kanalId = $this->kanalId ?: (int)$kanal['id'];
            }
        }

        $this->apiUrl = rtrim($apiUrl ?? '', '/');
        $this->apiKey = $apiKey ?? '';
    }

    /**
     * Ham EntegrasyonKanallari satırını WhatsApp kanal dizisine çevirir.
     */
    private static function kanalNormalize(array $row, array $entAyar = [])
    {
        $ayar = [];
        if (!empty($row['EntegrasyonKanallari_AyarJSON'])) {
            $ayar = json_decode($row['EntegrasyonKanallari_AyarJSON'], true) ?: [];
        }

        return [
            'id'         => (int)$row['EntegrasyonKanallari_id'],
            'ad'         => (string)$row['EntegrasyonKanallari_Ad'],
            // Instance adı: kanal ayarı → kullanıcı adı → kanal adı
            'instance'   => (string)($ayar['instance']
                                ?: $row['EntegrasyonKanallari_KullaniciAdi']
                                ?: $row['EntegrasyonKanallari_Ad']),
            'api_url'    => rtrim((string)($ayar['api_url'] ?? $entAyar['BaseURL'] ?? ''), '/'),
            'api_key'    => (string)($row['EntegrasyonKanallari_Sifre'] ?: ($entAyar['GlobalApiKey'] ?? '')),
            'telefon'    => (string)($ayar['telefon'] ?? ''),
            'varsayilan' => !empty($ayar['varsayilan']),
            'durum'      => (int)$row['EntegrasyonKanallari_Durum'],
        ];
    }

    /**
     * WhatsApp entegrasyonuna bağlı kanalları döner (normalize edilmiş).
     *
     * @param bool $sadeceAktif Sadece Durum = 1 olanlar
     */
    public static function kanallar($sadeceAktif = true)
    {
        $db = Database::getInstance();

        $ent = $db->fetchOne("
            SELECT Entegrasyonlar_id,
                   CAST(Entegrasyonlar_AyarJSON AS NVARCHAR(MAX)) AS ayar
            FROM Entegrasyonlar
            WHERE Entegrasyonlar_Ad = ? AND Entegrasyonlar_Durum = 1
        ", [self::ENTEGRASYON_ADI]);

        if (!$ent) return [];

        $entAyar = json_decode($ent['ayar'] ?? '{}', true) ?: [];

        $sql = "SELECT * FROM EntegrasyonKanallari
                WHERE EntegrasyonKanallari_Entegrasyonlar_id = ?";
        if ($sadeceAktif) {
            $sql .= " AND EntegrasyonKanallari_Durum = 1";
        }
        $sql .= " ORDER BY EntegrasyonKanallari_id";

        $rows = $db->fetchAll($sql, [$ent['Entegrasyonlar_id']]) ?: [];

        return array_map(function ($r) use ($entAyar) {
            return self::kanalNormalize($r, $entAyar);
        }, $rows);
    }

    /**
     * Tek kanal döner. $id verilirse o kanal, verilmezse varsayılan kanal.
     * Varsayılan işaretli kanal yoksa ilk aktif kanal döner.
     *
     * @return array|null
     */
    public static function kanal($id = 0)
    {
        $kanallar = self::kanallar(true);
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

    /**
     * Varsayılan aktif kanal.
     * @return array|null
     */
    public static function varsayilanKanal()
    {
        return self::kanal(0);
    }

    /**
     * Bu örneğin bağlı olduğu kanal id'si (log kayıtları için).
     */
    public function getKanalId()
    {
        return $this->kanalId;
    }
    
    /**
     * API'ye istek gönder
     */
    private function request($method, $endpoint, $data = null)
    {
        $url = $this->apiUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'message' => 'CURL hatası: ' . $error, 'http_code' => 0];
        }
        
        $decoded = json_decode($response, true);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded,
            'raw' => $response
        ];
    }
    
    /**
     * Tüm instance'ları listele
     */
    public function fetchInstances()
    {
        return $this->request('GET', '/instance/fetchInstances');
    }
    
    /**
     * Yeni instance oluştur
     * @param string $instanceName Instance adı
     * @param array $options Ek seçenekler
     */
    public function createInstance($instanceName, $options = [])
    {
        $data = array_merge([
            'instanceName' => $instanceName,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS'
        ], $options);
        
        return $this->request('POST', '/instance/create', $data);
    }
    
    /**
     * Instance bağlantı durumunu al
     * @param string $instanceName Instance adı
     */
    public function connectionState($instanceName)
    {
        return $this->request('GET', '/instance/connectionState/' . rawurlencode($instanceName));
    }
    
    /**
     * Instance'ı bağla ve QR kod al
     * @param string $instanceName Instance adı
     */
    public function connect($instanceName)
    {
        return $this->request('GET', '/instance/connect/' . rawurlencode($instanceName));
    }
    
    /**
     * Instance'ı yeniden başlat
     * @param string $instanceName Instance adı
     */
    public function restart($instanceName)
    {
        return $this->request('PUT', '/instance/restart/' . rawurlencode($instanceName));
    }
    
    /**
     * Instance'ı sil
     * @param string $instanceName Instance adı
     */
    public function deleteInstance($instanceName)
    {
        return $this->request('DELETE', '/instance/delete/' . rawurlencode($instanceName));
    }
    
    /**
     * Instance bağlantısını kes (logout)
     * @param string $instanceName Instance adı
     */
    public function logout($instanceName)
    {
        return $this->request('DELETE', '/instance/logout/' . rawurlencode($instanceName));
    }
    
    /**
     * Metin mesajı gönder
     * @param string $instanceName Instance adı
     * @param string $number Telefon numarası (905xxxxxxxxx formatında)
     * @param string $text Mesaj metni
     */
    public function sendText($instanceName, $number, $text)
    {
        // Telefon numarasını formatla
        $number = $this->formatPhoneNumber($number);
        
        $data = [
            'number' => $number,
            'text' => $text
        ];
        
        $result = $this->request('POST', '/message/sendText/' . rawurlencode($instanceName), $data);
        
        // Log kaydet
        $this->logMessage($instanceName, $number, $text, 'TEXT', $result);
        
        return $result;
    }
    
    /**
     * Resim mesajı gönder
     * @param string $instanceName Instance adı
     * @param string $number Telefon numarası
     * @param string $imageUrl Resim URL'si
     * @param string $caption Açıklama (opsiyonel)
     */
    public function sendImage($instanceName, $number, $imageUrl, $caption = '')
    {
        $number = $this->formatPhoneNumber($number);
        
        $data = [
            'number' => $number,
            'mediatype' => 'image',
            'media' => $imageUrl,
            'caption' => $caption
        ];
        
        $result = $this->request('POST', '/message/sendMedia/' . rawurlencode($instanceName), $data);
        
        $this->logMessage($instanceName, $number, $caption ?: '[IMAGE]', 'IMAGE', $result);
        
        return $result;
    }
    
    /**
     * Dosya/Belge gönder
     * @param string $instanceName Instance adı
     * @param string $number Telefon numarası
     * @param string $fileUrl Dosya URL'si
     * @param string $fileName Dosya adı
     */
    public function sendDocument($instanceName, $number, $fileUrl, $fileName = '')
    {
        $number = $this->formatPhoneNumber($number);
        
        $data = [
            'number' => $number,
            'mediatype' => 'document',
            'media' => $fileUrl,
            'fileName' => $fileName
        ];
        
        $result = $this->request('POST', '/message/sendMedia/' . rawurlencode($instanceName), $data);
        
        $this->logMessage($instanceName, $number, $fileName ?: '[DOCUMENT]', 'DOCUMENT', $result);
        
        return $result;
    }
    
    /**
     * Numara kontrolü (WhatsApp'ta kayıtlı mı?)
     * @param string $instanceName Instance adı
     * @param string $number Telefon numarası
     */
    public function checkNumber($instanceName, $number)
    {
        $number = $this->formatPhoneNumber($number);
        
        $data = [
            'numbers' => [$number]
        ];
        
        return $this->request('POST', '/chat/whatsappNumbers/' . rawurlencode($instanceName), $data);
    }
    
    /**
     * Telefon numarasını formatla
     * @param string $number Telefon numarası veya grup ID
     * @return string Formatlanmış numara
     */
    private function formatPhoneNumber($number)
    {
        // Grup ID'si ise olduğu gibi döndür (@g.us veya @s.whatsapp.net)
        if (strpos($number, '@') !== false) {
            return $number;
        }
        
        // Sadece rakamları al
        $number = preg_replace('/[^0-9]/', '', $number);
        
        // Başında 0 varsa kaldır ve 90 ekle
        if (substr($number, 0, 1) === '0') {
            $number = '9' . $number;
        }
        
        // 10 haneli ise başına 90 ekle
        if (strlen($number) === 10) {
            $number = '90' . $number;
        }
        
        return $number;
    }
    
    /**
     * Mesaj logunu kaydet
     */
    private function logMessage($instanceName, $number, $message, $type, $result)
    {
        try {
            // Kanal id: örnekte tanımlıysa onu kullan, değilse instance adından çöz
            $kanalId = $this->kanalId;
            if (!$kanalId) {
                foreach (self::kanallar(false) as $k) {
                    if ($k['instance'] === $instanceName) { $kanalId = $k['id']; break; }
                }
            }

            // Kanal çözülemezse log yazılamaz (EntegrasyonKanallari_id NOT NULL)
            if (!$kanalId) return;

            $kullaniciId = $_SESSION['user_id'] ?? null;

            // Kaynak (global değişken varsa kullan, yoksa EVOLUTION_API)
            global $WHATSAPP_LOG_SOURCE;
            $kaynak = $WHATSAPP_LOG_SOURCE ?? 'EVOLUTION_API';

            $durum    = $result['success'] ? 1 : 0;
            $apiYanit = $result['raw'] ?? null;
            $hata     = $durum ? null : ($result['message'] ?? ($result['data']['message'] ?? 'Bilinmeyen hata'));

            // İstek gövdesi: gönderilen mesaj ve instance bilgisi birlikte saklanır
            $istek = json_encode([
                'instance' => $instanceName,
                'telefon'  => $number,
                'mesaj'    => $message,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            $this->db->execute("
                INSERT INTO EntegrasyonLoglari (
                    EntegrasyonLoglari_EntegrasyonKanallari_id, EntegrasyonLoglari_IslemTipi,
                    EntegrasyonLoglari_Istek, EntegrasyonLoglari_Cevap, EntegrasyonLoglari_BasariliMi,
                    EntegrasyonLoglari_HataMesaji, EntegrasyonLoglari_Hedef, EntegrasyonLoglari_KullaniciId,
                    EntegrasyonLoglari_IP, EntegrasyonLoglari_Kaynak,
                    EntegrasyonLoglari_OlusturanKullanici, EntegrasyonLoglari_OlusturmaTarihi,
                    EntegrasyonLoglari_Durum
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), 1)
            ", [
                $kanalId,
                'WHATSAPP_' . $type,
                $istek,
                $apiYanit,
                $durum,
                $hata,
                mb_substr((string)$number, 0, 200),
                $kullaniciId,
                $ip ? mb_substr($ip, 0, 50) : null,
                $kaynak,
                $kullaniciId ?: 1,
            ]);

        } catch (Exception $e) {
            // Log kaydedilemezse sessizce geç
        }
    }
    
    /**
     * Kanal id'sinden istemci oluştur
     * @param int $kanalId EntegrasyonKanallari_id
     * @return EvolutionAPI|null
     */
    public static function loadFromKanal($kanalId)
    {
        $kanal = self::kanal($kanalId);
        if (!$kanal) {
            return null;
        }

        return new self($kanal['api_url'], $kanal['api_key'], $kanal['id']);
    }

    /**
     * Geriye dönük uyumluluk — eski adıyla loadFromKanal.
     * @deprecated loadFromKanal() kullanın.
     */
    public static function loadFromDB($kanalId)
    {
        return self::loadFromKanal($kanalId);
    }

    /**
     * Geriye dönük uyumluluk — varsayılan kanalı eski instance alan
     * adlarıyla döner, böylece eski çağrı yerleri kırılmaz.
     *
     * @deprecated varsayilanKanal() kullanın.
     * @return array|null
     */
    public static function getDefaultInstance()
    {
        $kanal = self::varsayilanKanal();
        if (!$kanal) {
            return null;
        }

        return [
            'instance_id'      => $kanal['id'],
            'instance_name'    => $kanal['instance'],
            'instance_api_url' => $kanal['api_url'],
            'instance_api_key' => $kanal['api_key'],
            'instance_telefon' => $kanal['telefon'],
        ];
    }
    
    /**
     * API bağlantı testi
     */
    public function testConnection()
    {
        $result = $this->fetchInstances();
        
        if ($result['success']) {
            return ['success' => true, 'message' => 'Evolution API bağlantısı başarılı!'];
        }
        
        return [
            'success' => false, 
            'message' => 'API bağlantısı başarısız: ' . ($result['message'] ?? 'HTTP ' . $result['http_code'])
        ];
    }
}
