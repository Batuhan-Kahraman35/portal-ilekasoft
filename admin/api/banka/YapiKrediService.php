<?php
/**
 * Yapı Kredi Bankası API Servisi
 * 
 * Yapı Kredi EHO (Electronic Home Office) SOAP API ile iletişim kurar
 * Endpoint: https://dpextprd.yapikredi.com.tr:443/Hmn/EhoAccountTransactionService
 * 
 * SOAP 1.1 | document/literal | WS-Security (UsernameToken + Nonce + Created)
 * 
 * Gerekli banka_ApiKimlik alanları:
 * - apiKimlik_kurumKod: WS-Security Username (INTGILEK)
 * - apiKimlik_kullanici: Firma Kodu (ILEK) - API'ye firmaKodu olarak gönderilir
 * - apiKimlik_sifre: WS-Security Password (ORNEK_SIFRE)
 * - apiKimlik_endpoint: https://dpextprd.yapikredi.com.tr:443/Hmn/EhoAccountTransactionService
 * 
 * Gerekli banka_Hesap alanları:
 * - bankaHesap_no: Hesap numarası (59524079)
 * - bankaHesap_sube_kodu: Şube kodu (711)
 * - bankaHesap_iban: IBAN
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 * @see admin/Banka Bilgileri/Yapi-Kredi/README.md
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class YapiKrediService extends BaseBankaService
{
    // SOAP Namespace
    private const NAMESPACE = 'http://intf.service.electronicaccountsummary.eho.hmn.ykb.com/';
    
    // Timeout (saniye)
    private const TIMEOUT = 60;
    
    // Başarılı hata kodları (boş veya null başarılı demek)
    private const SUCCESS_CODES = ['', '0', '00', null];
    
    /**
     * WS-Security header'lı SOAP isteği oluştur
     * 
     * @param string $arg0Content <arg0> içindeki XML içeriği
     * @return string SOAP XML
     */
    private function buildSoapEnvelope(string $arg0Content): string
    {
        // WS-Security gereksinimleri
        $nonce = base64_encode(random_bytes(16));
        $created = gmdate('Y-m-d\TH:i:s\Z');
        
        // Username = kurumKod (INTGILEK)
        $username = $this->apiKimlik['apiKimlik_kurumKod'] ?? '';
        $password = $this->apiKimlik['apiKimlik_sifre'] ?? '';
        
        return '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="' . self::NAMESPACE . '">
    <soap:Header>
        <wsse:Security soap:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
            <wsse:UsernameToken>
                <wsse:Username>' . htmlspecialchars($username) . '</wsse:Username>
                <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">' . htmlspecialchars($password) . '</wsse:Password>
                <wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">' . $nonce . '</wsse:Nonce>
                <wsu:Created>' . $created . '</wsu:Created>
            </wsse:UsernameToken>
        </wsse:Security>
    </soap:Header>
    <soap:Body>
        <ns:sorgula>
            <arg0>
                ' . $arg0Content . '
            </arg0>
        </ns:sorgula>
    </soap:Body>
</soap:Envelope>';
    }
    
    /**
     * SOAP isteği gönder
     * 
     * @param string $soapXml SOAP XML
     * @return array ['success' => bool, 'errorCode' => string, 'errorMsg' => string, 'response' => string, 'httpCode' => int]
     */
    private function sendSoapRequest(string $soapXml): array
    {
        $endpoint = $this->apiKimlik['apiKimlik_endpoint'] ?? 'https://dpextprd.yapikredi.com.tr:443/Hmn/EhoAccountTransactionService';
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $soapXml,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'Content-Length: ' . strlen($soapXml)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return [
                'success'   => false,
                'errorCode' => 'CURL',
                'errorMsg'  => 'cURL Hatası: ' . $curlError,
                'response'  => '',
                'httpCode'  => 0
            ];
        }
        
        if (!$response) {
            return [
                'success'   => false,
                'errorCode' => 'EMPTY',
                'errorMsg'  => 'Boş yanıt alındı (HTTP: ' . $httpCode . ')',
                'response'  => '',
                'httpCode'  => $httpCode
            ];
        }
        
        // SOAP Fault kontrolü
        if (preg_match('/<faultstring>([^<]*)</', $response, $faultMatch)) {
            return [
                'success'   => false,
                'errorCode' => 'SOAP_FAULT',
                'errorMsg'  => 'SOAP Fault: ' . html_entity_decode($faultMatch[1]),
                'response'  => $response,
                'httpCode'  => $httpCode
            ];
        }
        
        // Hata kodu kontrolü
        $errorCode = '';
        $errorMsg = '';
        
        if (preg_match('/<hataKodu>([^<]*)</', $response, $kodMatch)) {
            $errorCode = trim($kodMatch[1]);
        }
        if (preg_match('/<hataAciklamasi>([^<]*)</', $response, $acikMatch)) {
            $errorMsg = html_entity_decode(trim($acikMatch[1]));
        }
        
        // Başarı kontrolü: hataKodu boş veya 0 ise başarılı
        $success = in_array($errorCode, self::SUCCESS_CODES, true);
        
        return [
            'success'   => $success,
            'errorCode' => $errorCode ?: '-',
            'errorMsg'  => $errorMsg,
            'response'  => $response,
            'httpCode'  => $httpCode
        ];
    }
    
    /**
     * Firma kodu al (kullanici alanından)
     * 
     * @return string Firma kodu
     */
    private function getFirmaKodu(): string
    {
        return $this->apiKimlik['apiKimlik_kullanici'] ?? '';
    }
    
    /**
     * Y-m-d formatını YYYYMMDD'ye çevir (Yapı Kredi formatı)
     * 
     * @param string $ymdTarih Y-m-d formatında tarih
     * @return string YYYYMMDD formatında tarih
     */
    private function formatYkbTarih(string $ymdTarih): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $ymdTarih);
        return $dt ? $dt->format('Ymd') : date('Ymd');
    }
    
    /**
     * Yapı Kredi tarih formatını (YYMMDD) → Y-m-d'ye çevir
     * 
     * @param string $ykbTarih YYMMDD formatında
     * @return string Y-m-d formatında
     */
    private function parseYkbTarih(string $ykbTarih): string
    {
        if (empty($ykbTarih) || strlen($ykbTarih) < 6) {
            return date('Y-m-d');
        }
        
        // Format: YYMMDD (örn: 260328)
        $yy = substr($ykbTarih, 0, 2);
        $mm = substr($ykbTarih, 2, 2);
        $dd = substr($ykbTarih, 4, 2);
        
        // 2000 + yy
        $year = 2000 + intval($yy);
        
        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }
    
    /**
     * Yapı Kredi saat formatını HH:mm:ss'e çevir
     * 
     * @param string $ykbSaat HHMMSSXX formatında (8 karakter)
     * @return string HH:mm:ss formatında
     */
    private function parseYkbSaat(string $ykbSaat): string
    {
        if (empty($ykbSaat) || strlen($ykbSaat) < 6) {
            return '00:00:00';
        }
        
        $hh = substr($ykbSaat, 0, 2);
        $mm = substr($ykbSaat, 2, 2);
        $ss = substr($ykbSaat, 4, 2);
        
        return sprintf('%02d:%02d:%02d', intval($hh), intval($mm), intval($ss));
    }
    
    /**
     * Hesap hareketlerini parse et
     * 
     * @param string $responseXml SOAP yanıtı
     * @return array Hareket listesi
     */
    private function parseHareketler(string $responseXml): array
    {
        $hareketler = [];
        
        // <hareket>...</hareket> bloklarını bul
        if (!preg_match_all('/<hareket>(.*?)<\/hareket>/s', $responseXml, $hareketMatches)) {
            return [];
        }
        
        foreach ($hareketMatches[1] as $hareketXml) {
            $hareket = [];
            
            // XML alanlarını parse et
            $fields = [
                'aciklama', 'alacakliVKN', 'anlikBakiye', 'borcAlacak', 'borcluVKN',
                'dekontNo', 'fizikselIslemTarihi', 'gonderenAd', 'gonderenBanka',
                'gonderenIbanNo', 'gonderenSube', 'hareketKey', 'islemTipi',
                'karsiHesapVNo', 'kontratNo', 'muhasebeTarihi', 'saat', 'siraNo',
                'tutar', 'valor'
            ];
            
            foreach ($fields as $field) {
                if (preg_match('/<' . $field . '>([^<]*)</', $hareketXml, $m)) {
                    $hareket[$field] = html_entity_decode(trim($m[1]));
                } else {
                    $hareket[$field] = '';
                }
            }
            
            $hareketler[] = $hareket;
        }
        
        return $hareketler;
    }
    
    /**
     * Hesap bilgilerini parse et
     * 
     * @param string $responseXml SOAP yanıtı
     * @return array Hesap bilgileri
     */
    private function parseHesapBilgileri(string $responseXml): array
    {
        $hesaplar = [];
        
        // <hesap>...</hesap> bloklarını bul
        if (!preg_match_all('/<hesap>(.*?)<\/hesap>/s', $responseXml, $hesapMatches)) {
            return [];
        }
        
        foreach ($hesapMatches[1] as $hesapXml) {
            $hesap = [];
            
            $fields = [
                'hesapNo', 'subeKodu', 'subeAdi', 'dovizTipi',
                'acilisBakiyesi', 'kapanisBakiyesi'
            ];
            
            foreach ($fields as $field) {
                if (preg_match('/<' . $field . '>([^<]*)</', $hesapXml, $m)) {
                    $hesap[$field] = html_entity_decode(trim($m[1]));
                } else {
                    $hesap[$field] = '';
                }
            }
            
            $hesaplar[] = $hesap;
        }
        
        return $hesaplar;
    }
    
    /**
     * Yapı Kredi hareket verisini normalize et
     * 
     * @param array $hareket parseHareketler'den gelen ham veri
     * @return array hareketKaydet'in beklediği format
     */
    private function normalizeHareket(array $hareket): array
    {
        // Tarih + saat birleştir
        $islemTarihi = $this->parseYkbTarih($hareket['fizikselIslemTarihi']);
        $saat = $this->parseYkbSaat($hareket['saat']);
        $islemTarihiSaat = $islemTarihi . ' ' . $saat;
        
        // Borç/Alacak: YKB 'B' veya 'A' kullanıyor
        $borcAlacak = strtoupper(trim($hareket['borcAlacak']));
        
        // VKN/TCKN: Borç ise borclu_vkn, Alacak ise alacakli_vkn
        $vknTckn = '';
        if ($borcAlacak === 'B' && !empty($hareket['borcluVKN'])) {
            $vknTckn = trim($hareket['borcluVKN']);
        } elseif ($borcAlacak === 'A' && !empty($hareket['alacakliVKN'])) {
            $vknTckn = trim($hareket['alacakliVKN']);
        } elseif (!empty($hareket['karsiHesapVNo'])) {
            $vknTckn = trim($hareket['karsiHesapVNo']);
        }
        
        // Açıklama
        $aciklama = trim($hareket['aciklama']);
        
        // Gönderen bilgisi
        $gonderenAd = trim($hareket['gonderenAd']);
        $gonderenIban = trim($hareket['gonderenIbanNo']);
        
        // Tutar
        $tutar = floatval(str_replace(',', '.', $hareket['tutar']));
        
        // Bakiye
        $bakiye = floatval(str_replace(',', '.', $hareket['anlikBakiye']));
        
        return [
            'identifier'        => $hareket['hareketKey'],
            'islemTarihi'       => $islemTarihiSaat,
            'tutar'             => $tutar,
            'aciklama'          => $aciklama,
            'borcAlacak'        => $borcAlacak,
            'dovizKodu'         => 'TRY', // Yapı Kredi TL olarak döndürüyor
            'bakiye'            => $bakiye,
            'karsiTarafIban'    => $gonderenIban ?: null,
            'karsiTarafAdUnvan' => $gonderenAd ?: null,
            'vknTckn'           => $vknTckn ?: null,
            'masraf'            => 0,
            'islemTipi'         => $hareket['islemTipi'] ?: null,
            'receiptNo'         => $hareket['dekontNo'] ?: null,
        ];
    }
    
    // ========================================================================
    // BankaServiceInterface Implementasyonu
    // ========================================================================
    
    /**
     * API bağlantı testi
     */
    public function testConnection(): array
    {
        $logId = $this->logBaslat('testConnection', null, 'Yapı Kredi API bağlantı testi');
        
        try {
            $firmaKodu = $this->getFirmaKodu();
            
            if (empty($firmaKodu)) {
                throw new \Exception('API Kimlik ayarlarında Firma Kodu (kullanici alanı) eksik.');
            }
            
            if (empty($this->apiKimlik['apiKimlik_kurumKod'])) {
                throw new \Exception('API Kimlik ayarlarında Kurum Kodu eksik.');
            }
            
            // Sadece firma kodu ile test sorgusu
            $arg0Content = '<firmaKodu>' . htmlspecialchars($firmaKodu) . '</firmaKodu>';
            
            $soapXml = $this->buildSoapEnvelope($arg0Content);
            $result = $this->sendSoapRequest($soapXml);
            
            if ($result['success']) {
                $this->logBasarili($logId, 0, 'Yapı Kredi API bağlantısı başarılı');
                return [
                    'success' => true,
                    'message' => 'Yapı Kredi API bağlantısı başarılı!',
                    'data'    => [
                        'endpoint'  => $this->apiKimlik['apiKimlik_endpoint'],
                        'firmaKodu' => $firmaKodu
                    ]
                ];
            }
            
            $errorDetail = 'YKB Hata: ' . $result['errorCode'] . ' - ' . $result['errorMsg'];
            $this->logHatali($logId, $errorDetail, $result['errorCode'], $result['httpCode']);
            
            return [
                'success' => false,
                'message' => $errorDetail,
                'data'    => null
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }
    
    /**
     * Hesap bilgilerini API'den çek
     * 
     * NOT: Yapı Kredi API'si hesap listesini hareket sorgusu içinde döndürür.
     * Veritabanındaki hesaplar üzerinden çalışır.
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'Yapı Kredi hesap listesi');
        
        try {
            $hesaplar = $this->getApiHesaplari();
            
            $normalizedHesaplar = [];
            
            foreach ($hesaplar as $hesap) {
                $normalizedHesaplar[] = [
                    'dbId'     => $hesap['bankaHesap_id'],
                    'iban'     => $hesap['bankaHesap_iban'],
                    'hesapNo'  => $hesap['bankaHesap_no'],
                    'subeKodu' => $hesap['bankaHesap_sube_kodu'],
                ];
            }
            
            $this->logBasarili($logId, count($normalizedHesaplar), count($normalizedHesaplar) . ' hesap listelendi');
            
            return [
                'success' => true,
                'message' => count($normalizedHesaplar) . ' hesap bulundu',
                'data'    => $normalizedHesaplar,
                'count'   => count($normalizedHesaplar)
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
                'data'    => [],
                'count'   => 0
            ];
        }
    }
    
    /**
     * Hesap hareketlerini çek
     * 
     * @param int $hesapId banka_Hesap tablosundaki ID
     * @param string $baslangicTarih Y-m-d formatında
     * @param string $bitisTarih Y-m-d formatında
     */
    public function getHareketler(int $hesapId, string $baslangicTarih, string $bitisTarih): array
    {
        // Hesap bilgilerini al
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ? AND bankaHesap_durum = 1
        ", [$hesapId]);
        
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'data' => [], 'count' => 0];
        }
        
        $istekOzet = json_encode([
            'hesapId'   => $hesapId,
            'hesapNo'   => $hesap['bankaHesap_no'],
            'baslangic' => $baslangicTarih,
            'bitis'     => $bitisTarih
        ]);
        
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);
        
        try {
            $firmaKodu = $this->getFirmaKodu();
            
            if (empty($firmaKodu)) {
                throw new \Exception('API Kimlik ayarlarında Firma Kodu eksik.');
            }
            
            // Tarihleri Yapı Kredi formatına çevir (YYYYMMDD)
            $baslar = $this->formatYkbTarih($baslangicTarih);
            $bitir = $this->formatYkbTarih($bitisTarih);
            
            // Hesap numarası
            $hesapNo = $hesap['bankaHesap_no'] ?? '';
            
            // SOAP isteği - hesapNo, dovizKodu ve saat parametreleri zorunlu
            $arg0Content = '<baslangicSaat>0000</baslangicSaat>'
                . '<baslangicTarih>' . $baslar . '</baslangicTarih>'
                . '<bitisSaat>2359</bitisSaat>'
                . '<bitisTarih>' . $bitir . '</bitisTarih>'
                . '<dovizKodu>TL</dovizKodu>'
                . '<firmaKodu>' . htmlspecialchars($firmaKodu) . '</firmaKodu>'
                . '<hesapNo>' . htmlspecialchars($hesapNo) . '</hesapNo>';
            
            $soapXml = $this->buildSoapEnvelope($arg0Content);
            $result = $this->sendSoapRequest($soapXml);
            
            if (!$result['success']) {
                $errorDetail = 'YKB Hata: ' . $result['errorCode'] . ' - ' . $result['errorMsg'];
                $this->logHatali($logId, $errorDetail, $result['errorCode'], $result['httpCode']);
                return [
                    'success' => false,
                    'message' => $errorDetail,
                    'data'    => [],
                    'count'   => 0
                ];
            }
            
            // Hareketleri parse et
            $hareketler = $this->parseHareketler($result['response']);
            
            $this->logBasarili($logId, count($hareketler), count($hareketler) . ' hareket alındı', $result['httpCode']);
            
            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu',
                'data'    => $hareketler,
                'count'   => count($hareketler)
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
                'data'    => [],
                'count'   => 0
            ];
        }
    }
    
    /**
     * Hesabı senkronize et (son senkrondan bugüne)
     */
    public function senkronize(int $hesapId): array
    {
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ? AND bankaHesap_durum = 1
        ", [$hesapId]);
        
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'inserted' => 0, 'skipped' => 0];
        }
        
        $istekOzet = json_encode(['hesapId' => $hesapId, 'iban' => $hesap['bankaHesap_iban']]);
        $logId = $this->logBaslat('senkronize', $hesapId, $istekOzet);
        
        try {
            // Son senkron tarihinden bugüne (Yapı Kredi max 15 gün!)
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            $baslangic = $sonSenkron ?: date('Y-m-d', strtotime('-14 days'));
            $bitis = date('Y-m-d');
            
            // Max 15 gün kontrolü (Yapı Kredi limiti)
            $fark = (strtotime($bitis) - strtotime($baslangic)) / 86400;
            if ($fark > 15) {
                $baslangic = date('Y-m-d', strtotime('-14 days'));
            }
            
            // Hareketleri çek
            $result = $this->getHareketler($hesapId, $baslangic, $bitis);
            
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return [
                    'success'  => false,
                    'message'  => $result['message'],
                    'inserted' => 0,
                    'skipped'  => 0
                ];
            }
            
            $inserted = 0;
            $skipped = 0;
            
            foreach ($result['data'] as $hareket) {
                $normalized = $this->normalizeHareket($hareket);
                
                // hareketKaydet bool döndürür (true: eklendi, false: mevcut/hata)
                if ($this->hareketKaydet($hesapId, $normalized)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            
            // Son hareketin bakiyesini DB'ye yaz
            if (!empty($result['data'])) {
                $sonHareket = end($result['data']);
                $sonBakiye = $sonHareket['bakiye'] ?? null;
                if ($sonBakiye !== null) {
                    $this->db->execute("
                        UPDATE banka_Hesap SET 
                            bankaHesap_bakiye = ?,
                            bankaHesap_kullanilabilirBakiye = ?,
                            bankaHesap_guncelleme_tarihi = GETDATE()
                        WHERE bankaHesap_id = ?
                    ", [$sonBakiye, $sonBakiye, $hesapId]);
                }
            }

            // Senkron tarihini güncelle (API'den veri döndüyse)
            $this->sonSenkronGuncelle($hesapId, $inserted + $skipped);
            
            $this->logBasarili($logId, $inserted, "Senkronize: $inserted yeni, $skipped mevcut kayıt");
            
            return [
                'success'  => true,
                'message'  => "Senkronizasyon tamamlandı: $inserted yeni kayıt eklendi, $skipped kayıt zaten mevcuttu.",
                'inserted' => $inserted,
                'skipped'  => $skipped
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success'  => false,
                'message'  => 'Hata: ' . $e->getMessage(),
                'inserted' => 0,
                'skipped'  => 0
            ];
        }
    }
    
    /**
     * Tüm hesapları senkronize et  
     */
    public function senkronizeTumu(): array
    {
        $logId = $this->logBaslat('senkronizeTumHesaplar', null, 'Tüm Yapı Kredi hesapları');
        
        $hesaplar = $this->getApiHesaplari();
        
        $toplamInserted = 0;
        $toplamSkipped = 0;
        $basariliHesap = 0;
        $hataliHesap = 0;
        $hatalar = [];
        
        foreach ($hesaplar as $hesap) {
            $result = $this->senkronize($hesap['bankaHesap_id']);
            
            if ($result['success']) {
                $basariliHesap++;
                $toplamInserted += $result['inserted'];
                $toplamSkipped += $result['skipped'];
            } else {
                $hataliHesap++;
                $hatalar[] = "Hesap #{$hesap['bankaHesap_id']}: " . $result['message'];
            }
        }
        
        $mesaj = "$basariliHesap hesap senkronize edildi. Toplam $toplamInserted yeni kayıt.";
        if ($hataliHesap > 0) {
            $mesaj .= " $hataliHesap hesap hatalı.";
        }
        
        $this->logBasarili($logId, $toplamInserted, $mesaj);
        
        return [
            'success'       => $hataliHesap == 0,
            'message'       => $mesaj,
            'inserted'      => $toplamInserted,
            'skipped'       => $toplamSkipped,
            'hesap_basarili' => $basariliHesap,
            'hesap_hatali'  => $hataliHesap,
            'hatalar'       => $hatalar,
            'summary'       => [
                'basarili'        => $basariliHesap,
                'hatali'          => $hataliHesap,
                'toplam_inserted' => $toplamInserted,
                'toplam_skipped'  => $toplamSkipped
            ]
        ];
    }
}
