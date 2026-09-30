<?php
/**
 * Destek Sistemi API İstemcisi
 *
 * API adresi ve anahtarı koda gömülmez; Entegrasyonlar / EntegrasyonKanallari
 * tablolarından okunur ve Entegrasyon Yönetimi sayfasından düzenlenebilir.
 *
 *   Entegrasyonlar_AyarJSON        → {"BaseURL": "https://.../api/tickets.php"}
 *   EntegrasyonKanallari_AyarJSON  → {"ApiKey": "..."}
 *
 * Kanal seçimi: aktif kanallardan id'si en küçük olan kullanılır.
 */

class TicketApi
{
    private const ENTEGRASYON_ADI = 'Destek Sistemi';

    private $db;
    private array $config = [];
    private string $hata = '';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->configYukle();
    }

    private function configYukle(): void
    {
        $row = $this->db->fetchOne("
            SELECT TOP 1
                e.Entegrasyonlar_id,
                e.Entegrasyonlar_AyarJSON,
                k.EntegrasyonKanallari_id,
                k.EntegrasyonKanallari_AyarJSON
            FROM Entegrasyonlar e
            INNER JOIN EntegrasyonKanallari k
                    ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Ad = ?
              AND e.Entegrasyonlar_Durum = 1
              AND k.EntegrasyonKanallari_Durum = 1
            ORDER BY k.EntegrasyonKanallari_id ASC
        ", [self::ENTEGRASYON_ADI]);

        if (!$row) {
            $this->hata = 'Destek entegrasyonu tanımı bulunamadı. Entegrasyon Yönetimi sayfasını kontrol edin.';
            return;
        }

        $entAyar   = json_decode($row['Entegrasyonlar_AyarJSON'] ?? '{}', true) ?: [];
        $kanalAyar = json_decode($row['EntegrasyonKanallari_AyarJSON'] ?? '{}', true) ?: [];
        $this->config = array_merge($entAyar, $kanalAyar);

        if (empty($this->config['BaseURL'])) {
            $this->hata = 'Destek entegrasyonu ayarlarında BaseURL tanımlı değil.';
        } elseif (empty($this->config['ApiKey'])) {
            $this->hata = 'Destek entegrasyon kanalı ayarlarında ApiKey tanımlı değil.';
        }
    }

    public function hazirMi(): bool
    {
        return $this->hata === '';
    }

    public function getHata(): string
    {
        return $this->hata;
    }

    /**
     * API çağrısı yapar. Dosya verilirse multipart, aksi halde JSON gönderir.
     *
     * Hata durumlarında ayrıntı döndürür:
     *   - cURL hatası → curl_error() detayı (DNS, SSL, timeout vb.)
     *   - HTTP hatası → durum kodu
     *   - JSON hatası → ham yanıtın ilk 200 karakteri
     *
     * @param array $payload API payload'ı (action zorunlu)
     * @param array $files   $_FILES['dosyalar'] biçiminde dosya dizisi (opsiyonel)
     */
    public function call(array $payload, array $files = []): array
    {
        if (!$this->hazirMi()) {
            return ['success' => false, 'message' => $this->hata];
        }

        $url    = $this->config['BaseURL'];
        $apiKey = $this->config['ApiKey'];

        $ch = curl_init($url);
        $headers = ['X-API-KEY: ' . $apiKey];

        if (!empty($files)) {
            // Dosyalı istek: alanlar düz metin, dosyalar CURLFile olarak gider
            $postData = [];
            foreach ($payload as $anahtar => $deger) {
                $postData[$anahtar] = is_array($deger)
                    ? json_encode($deger, JSON_UNESCAPED_UNICODE)
                    : $deger;
            }

            $sayac = 0;
            foreach ($files as $dosya) {
                if (empty($dosya['tmp_name']) || !is_uploaded_file($dosya['tmp_name'])) {
                    continue;
                }
                $postData['dosyalar[' . $sayac . ']'] = new CURLFile(
                    $dosya['tmp_name'],
                    $dosya['type'] ?? 'application/octet-stream',
                    $dosya['name'] ?? ('dosya' . $sayac)
                );
                $sayac++;
            }
        } else {
            $postData  = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['success' => false, 'message' => 'API bağlantı hatası: ' . ($err ?: 'Bilinmeyen hata') . ' — Endpoint: ' . $url];
        }

        // 400 gibi kodlarda da gövde JSON olabilir; önce ayrıştırmayı dene
        $data = json_decode($res, true);
        if (is_array($data)) {
            return $data;
        }

        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'API HTTP hatası: ' . $code . ' — Yanıt: ' . mb_substr($res, 0, 200)];
        }

        return ['success' => false, 'message' => 'Geçersiz API yanıtı (JSON ayrıştırma hatası). Ham yanıt: ' . mb_substr($res, 0, 200)];
    }
}
