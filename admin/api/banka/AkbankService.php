<?php
/**
 * Akbank API Servisi
 * 
 * Akbank SOAP API ile iletişim kurar (HTTP Basic Auth + cURL)
 * Endpoint: https://firmahizmetleri.akbank.com/Extre_InterfaceService/Service.asmx
 * 
 * ÖNEMLİ: GetExtreWithParams çalışmıyor (tarih formatı hatası)
 * Bu yüzden GetExtre kullanılır ve PHP tarafında tarih filtrelemesi yapılır
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class AkbankService extends BaseBankaService
{
    // API Endpoint
    private const ENDPOINT = 'https://firmahizmetleri.akbank.com/Extre_InterfaceService/Service.asmx';
    
    // SOAP Namespace
    private const NAMESPACE = 'http://tempuri.org/';
    
    // Timeout (saniye)
    private const TIMEOUT = 30;
    
    /**
     * HTTP Basic Auth header oluştur
     */
    private function getAuthHeader(): string
    {
        $username = $this->apiKimlik['apiKimlik_kurumKod'];
        $password = $this->apiKimlik['apiKimlik_sifre'];
        return 'Basic ' . base64_encode($username . ':' . $password);
    }
    
    /**
     * SOAP isteği gönder (cURL ile)
     * 
     * @param string $action SOAP Action (GetExtre, GetExtreWithParams)
     * @param string $soapBody SOAP Body XML içeriği
     * @return array ['success' => bool, 'data' => string|null, 'error' => string|null, 'httpCode' => int]
     */
    private function sendSoapRequest(string $action, string $soapBody): array
    {
        $soapXml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="' . self::NAMESPACE . '">
          <soap:Body>
            ' . $soapBody . '
          </soap:Body>
        </soap:Envelope>';
        
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapXml,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . self::NAMESPACE . $action . '"',
                'Authorization: ' . $this->getAuthHeader()
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return ['success' => false, 'data' => null, 'error' => 'cURL Hatası: ' . $curlError, 'httpCode' => 0];
        }
        
        if ($httpCode != 200) {
            return ['success' => false, 'data' => $response, 'error' => 'HTTP Hatası: ' . $httpCode, 'httpCode' => $httpCode];
        }
        
        // SOAP Fault kontrolü
        if (strpos($response, 'soap:Fault') !== false) {
            preg_match('/<faultstring>([^<]+)<\/faultstring>/', $response, $matches);
            $faultMessage = html_entity_decode($matches[1] ?? 'Bilinmeyen SOAP hatası');
            return ['success' => false, 'data' => $response, 'error' => 'SOAP Hatası: ' . $faultMessage, 'httpCode' => $httpCode];
        }
        
        return ['success' => true, 'data' => $response, 'error' => null, 'httpCode' => $httpCode];
    }
    
    /**
     * Türk lirası formatını float'a çevir (875.000,25 -> 875000.25)
     */
    private function parseFloat(string $str): float
    {
        $str = trim($str);
        // Nokta binlik ayracı, virgül ondalık ayracı
        return floatval(str_replace(',', '.', str_replace('.', '', $str)));
    }
    
    /**
     * YYYYMMDD formatını Y-m-d'ye çevir
     */
    private function parseDate(string $dateStr): ?string
    {
        if (strlen($dateStr) !== 8) {
            return null;
        }
        return substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
    }
    
    /**
     * API bağlantı testi
     */
    public function testConnection(): array
    {
        $logId = $this->logBaslat('testConnection', null, 'Akbank API bağlantı testi');
        
        try {
            // GetExtre ile basit bir test yapalım (boş URF ile hata alacak ama bağlantı test edilecek)
            $soapBody = '<tem:GetExtre>
              <tem:urf>TEST</tem:urf>
              <tem:hesapNo>000000</tem:hesapNo>
              <tem:dovizKodu>YTL</tem:dovizKodu>
              <tem:subeKodu>00</tem:subeKodu>
            </tem:GetExtre>';
            
            $result = $this->sendSoapRequest('GetExtre', $soapBody);
            
            // HTTP 200 aldıysak bağlantı başarılı (SOAP hatası olsa bile)
            if ($result['httpCode'] == 200) {
                $this->logBasarili($logId, 0, 'Akbank API bağlantısı başarılı');
                return [
                    'success' => true,
                    'message' => 'Akbank API bağlantısı başarılı!',
                    'data' => ['endpoint' => self::ENDPOINT]
                ];
            }
            
            $this->logHatali($logId, $result['error'] ?? 'Bilinmeyen hata', null, $result['httpCode']);
            return [
                'success' => false,
                'message' => $result['error'] ?? 'Bağlantı hatası',
                'data' => null
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * GetExtre - Belirli bir hesabın tüm hareketlerini çek
     * 
     * @param string $urf URF numarası (CustomerNo)
     * @param string $hesapNo Hesap numarası
     * @param string $subeKodu Şube kodu
     * @param string $dovizKodu Döviz kodu (YTL, USD, EUR)
     * @return array
     */
    public function getExtre(string $urf, string $hesapNo, string $subeKodu, string $dovizKodu = 'YTL'): array
    {
        $soapBody = '<tem:GetExtre>
          <tem:urf>' . htmlspecialchars($urf) . '</tem:urf>
          <tem:hesapNo>' . htmlspecialchars($hesapNo) . '</tem:hesapNo>
          <tem:dovizKodu>' . htmlspecialchars($dovizKodu) . '</tem:dovizKodu>
          <tem:subeKodu>' . htmlspecialchars($subeKodu) . '</tem:subeKodu>
        </tem:GetExtre>';
        
        $result = $this->sendSoapRequest('GetExtre', $soapBody);
        
        if (!$result['success']) {
            return ['success' => false, 'message' => $result['error'], 'hesap' => null, 'hareketler' => []];
        }
        
        return $this->parseExtreResponse($result['data']);
    }
    
    /**
     * GetExtre yanıtını parse et (DOMDocument ile)
     */
    private function parseExtreResponse(string $xmlString): array
    {
        // Geçersiz karakterleri temizle
        $cleanXml = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xmlString);
        
        // DOMDocument ile parse et (SimpleXML çalışmıyor)
        $dom = new \DOMDocument();
        $dom->recover = true;
        $dom->strictErrorChecking = false;
        
        if (!@$dom->loadXML($cleanXml)) {
            return ['success' => false, 'message' => 'XML parse hatası', 'hesap' => null, 'hareketler' => []];
        }
        
        $xpath = new \DOMXPath($dom);
        
        // Node value alma helper
        $getNodeValue = function($parent, $name) use ($xpath) {
            $nodes = $xpath->query('.//*[local-name()="' . $name . '"]', $parent);
            return $nodes->length > 0 ? $nodes->item(0)->nodeValue : '';
        };
        
        // Hesap bilgisi
        $hesapNodes = $xpath->query('//*[local-name()="Hesap"]');
        if ($hesapNodes->length === 0) {
            return ['success' => false, 'message' => 'Hesap bilgisi bulunamadı', 'hesap' => null, 'hareketler' => []];
        }
        
        $hesapNode = $hesapNodes->item(0);
        
        $hesap = [
            'hesapNo' => $getNodeValue($hesapNode, 'HesapNo'),
            'subeKodu' => $getNodeValue($hesapNode, 'SubeKodu'),
            'iban' => $getNodeValue($hesapNode, 'IBAN'),
            'dovizKodu' => $getNodeValue($hesapNode, 'DovizKodu'),
            'bakiye' => $this->parseFloat($getNodeValue($hesapNode, 'Bakiye')),
            'cariBakiye' => $this->parseFloat($getNodeValue($hesapNode, 'CariBakiye')),
            'blokeTutar' => $this->parseFloat($getNodeValue($hesapNode, 'BlokeMeblag') ?: '0'),
            'acilisTarihi' => $this->parseDate($getNodeValue($hesapNode, 'HesapAcilisTarihi')),
            'sonHareketTarihi' => $this->parseDate($getNodeValue($hesapNode, 'SonHareketTarihi'))
        ];
        
        // Hareketler
        $detayNodes = $xpath->query('//*[local-name()="Detay"]');
        $hareketler = [];
        
        foreach ($detayNodes as $detay) {
            $tutar = $this->parseFloat($getNodeValue($detay, 'Tutar'));
            $borcAlacak = $getNodeValue($detay, 'TutarBorcAlacak');
            
            // - işareti için tutarı negatif yap
            if ($borcAlacak === '-') {
                $tutar = -abs($tutar);
            }
            
            $hareketler[] = [
                'valorTarihi' => $getNodeValue($detay, 'ValorTarihi'),  // YYYYMMDD
                'islemTarihi' => $getNodeValue($detay, 'IslemTarihi'),  // YYYYMMDD
                'tarih' => $this->parseDate($getNodeValue($detay, 'IslemTarihi')), // Y-m-d
                'tutar' => $tutar,
                'bakiye' => $this->parseFloat($getNodeValue($detay, 'SonBakiye')),
                'borcAlacak' => $borcAlacak, // + veya -
                'tip' => $borcAlacak === '+' ? 'Giriş' : 'Çıkış',
                'aciklama' => $getNodeValue($detay, 'Aciklama'),
                'fonksiyonKodu' => $getNodeValue($detay, 'FonksiyonKodu'),
                'mt940Kodu' => $getNodeValue($detay, 'MT940FonksiyonKodu'),
                'fisNo' => $getNodeValue($detay, 'FisNo'),
                'vkn' => $getNodeValue($detay, 'VKN'),
                'referans' => $getNodeValue($detay, 'ReferansNo') ?: $getNodeValue($detay, 'FisNo'),
                'borcluIban' => $getNodeValue($detay, 'BorcluIBAN'),
                'alacakliIban' => $getNodeValue($detay, 'AlacakliIBAN'),
                'ozelAlan1' => $getNodeValue($detay, 'OzelAlan1'),
                'ozelAlan2' => $getNodeValue($detay, 'OzelAlan2'),
                'timestamp' => $getNodeValue($detay, 'TimeStamp')
            ];
        }
        
        return [
            'success' => true,
            'message' => count($hareketler) . ' hareket bulundu',
            'hesap' => $hesap,
            'hareketler' => $hareketler
        ];
    }
    
    /**
     * Hareketleri tarihe göre filtrele (PHP tarafında)
     * 
     * @param array $hareketler Hareket listesi
     * @param string $baslangic Y-m-d formatında
     * @param string $bitis Y-m-d formatında
     * @return array Filtrelenmiş hareketler
     */
    private function filterByDate(array $hareketler, string $baslangic, string $bitis): array
    {
        // Y-m-d formatını YYYYMMDD'ye çevir
        $baslangicYmd = str_replace('-', '', $baslangic);
        $bitisYmd = str_replace('-', '', $bitis);
        
        return array_filter($hareketler, function($hareket) use ($baslangicYmd, $bitisYmd) {
            $tarih = $hareket['islemTarihi']; // YYYYMMDD
            return $tarih >= $baslangicYmd && $tarih <= $bitisYmd;
        });
    }
    
    /**
     * Tanımlı hesap listesini çek
     * 
     * NOT: Akbank API'sinde hesap listesi çeken bir metod yok.
     * GetExtre ile hesap bilgisi alınıyor.
     * Bu yüzden veritabanındaki hesaplardan bilgi çekiyoruz.
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'Akbank hesap listesi');
        
        try {
            // Bu API kimliğine bağlı hesapları al
            $hesaplar = $this->db->fetchAll("
                SELECT * FROM banka_Hesap 
                WHERE bankaHesap_apiKimlik_id = ? 
                  AND bankaHesap_durum = 1
            ", [$this->apiKimlik['apiKimlik_id']]);
            
            $normalizedHesaplar = [];
            $basariliSayisi = 0;
            
            foreach ($hesaplar as $hesap) {
                // Her hesap için GetExtre çağır ve hesap bilgilerini güncelle
                if (!empty($hesap['bankaHesap_musteriNo']) && 
                    !empty($hesap['bankaHesap_no']) && 
                    !empty($hesap['bankaHesap_ekNo'])) {
                    
                    $extreResult = $this->getExtre(
                        $hesap['bankaHesap_musteriNo'],  // URF
                        $hesap['bankaHesap_no'],         // HesapNo
                        $hesap['bankaHesap_ekNo'],       // SubeKodu
                        $hesap['bankaHesap_doviz_turu'] ?? 'YTL'
                    );
                    
                    if ($extreResult['success'] && $extreResult['hesap']) {
                        $apiHesap = $extreResult['hesap'];
                        
                        // Hesap bilgilerini güncelle (bakiye, bloke vb.)
                        $this->db->execute("
                            UPDATE banka_Hesap SET
                                bankaHesap_iban = COALESCE(bankaHesap_iban, ?),
                                bankaHesap_bakiye = ?,
                                bankaHesap_bloke = ?,
                                bankaHesap_kullanilabilirBakiye = ?
                            WHERE bankaHesap_id = ?
                        ", [
                            $apiHesap['iban'],
                            $apiHesap['bakiye'],
                            $apiHesap['blokeTutar'],
                            $apiHesap['bakiye'] - $apiHesap['blokeTutar'],
                            $hesap['bankaHesap_id']
                        ]);
                        
                        $normalizedHesaplar[] = [
                            'dbId' => $hesap['bankaHesap_id'],
                            'iban' => $apiHesap['iban'] ?: $hesap['bankaHesap_iban'],
                            'hesapNo' => $apiHesap['hesapNo'],
                            'subeKodu' => $apiHesap['subeKodu'],
                            'dovizKodu' => $apiHesap['dovizKodu'],
                            'bakiye' => $apiHesap['bakiye'],
                            'sonHareketTarihi' => $apiHesap['sonHareketTarihi']
                        ];
                        
                        $basariliSayisi++;
                    }
                }
            }
            
            $this->logBasarili($logId, $basariliSayisi, $basariliSayisi . ' hesap güncellendi');
            
            return [
                'success' => true,
                'message' => $basariliSayisi . ' hesap bilgisi alındı',
                'data' => $normalizedHesaplar,
                'count' => $basariliSayisi
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
                'data' => [],
                'count' => 0
            ];
        }
    }
    
    /**
     * Hesap hareketlerini çek
     * 
     * NOT: GetExtreWithParams tarih formatı sorunu yüzünden çalışmıyor.
     * GetExtre ile tüm hareketler çekilip PHP'de filtreleniyor.
     */
    public function getHareketler(int $hesapId, string $baslangicTarih, string $bitisTarih): array
    {
        // Hesap bilgilerini al
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ?
        ", [$hesapId]);
        
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'data' => [], 'count' => 0];
        }
        
        // Gerekli alanlar kontrolü
        // Akbank için: musteriNo = URF (CustomerNo), hesapNo = HesapNo, ekNo = SubeKodu
        if (empty($hesap['bankaHesap_musteriNo']) || 
            empty($hesap['bankaHesap_no']) || 
            empty($hesap['bankaHesap_ekNo'])) {
            return [
                'success' => false,
                'message' => 'Hesap için gerekli bilgiler eksik (MusteriNo/URF, HesapNo, SubeKodu). Hesap ayarlarını kontrol edin.',
                'data' => [],
                'count' => 0
            ];
        }
        
        $istekOzet = json_encode([
            'hesapId' => $hesapId,
            'urf' => $hesap['bankaHesap_musteriNo'],
            'hesapNo' => $hesap['bankaHesap_no'],
            'subeKodu' => $hesap['bankaHesap_ekNo'],
            'baslangic' => $baslangicTarih,
            'bitis' => $bitisTarih
        ]);
        
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);
        
        try {
            // GetExtre ile tüm hareketleri çek
            $extreResult = $this->getExtre(
                $hesap['bankaHesap_musteriNo'],  // URF
                $hesap['bankaHesap_no'],         // HesapNo
                $hesap['bankaHesap_ekNo'],       // SubeKodu
                $hesap['bankaHesap_doviz_turu'] ?? 'YTL'
            );
            
            if (!$extreResult['success']) {
                $this->logHatali($logId, $extreResult['message']);
                return [
                    'success' => false,
                    'message' => $extreResult['message'],
                    'data' => [],
                    'count' => 0
                ];
            }
            
            // PHP tarafında tarih filtrelemesi yap
            $tumHareketler = $extreResult['hareketler'];
            $filtrelenmisHareketler = $this->filterByDate($tumHareketler, $baslangicTarih, $bitisTarih);
            
            // Array key'lerini sıfırla
            $filtrelenmisHareketler = array_values($filtrelenmisHareketler);
            
            $this->logBasarili($logId, count($filtrelenmisHareketler), 
                'Toplam: ' . count($tumHareketler) . ', Filtrelenmiş: ' . count($filtrelenmisHareketler));
            
            return [
                'success' => true,
                'message' => count($filtrelenmisHareketler) . ' hareket bulundu (toplam ' . count($tumHareketler) . ' içinden)',
                'data' => $filtrelenmisHareketler,
                'count' => count($filtrelenmisHareketler)
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
                'data' => [],
                'count' => 0
            ];
        }
    }
    
    /**
     * Hesabı senkronize et
     */
    public function senkronize(int $hesapId): array
    {
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ?
        ", [$hesapId]);
        
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'inserted' => 0, 'skipped' => 0];
        }
        
        $istekOzet = json_encode(['hesapId' => $hesapId, 'iban' => $hesap['bankaHesap_iban']]);
        $logId = $this->logBaslat('senkronize', $hesapId, $istekOzet);
        
        try {
            // Son senkrondan bugüne veya son 90 gün
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            if ($sonSenkron) {
                $baslangic = date('Y-m-d', strtotime($sonSenkron));
            } else {
                $baslangic = date('Y-m-d', strtotime('-90 days'));
            }
            $bitis = date('Y-m-d');
            
            // Hareketleri çek
            $hareketResult = $this->getHareketler($hesapId, $baslangic, $bitis);
            
            if (!$hareketResult['success']) {
                $this->logHatali($logId, $hareketResult['message']);
                return [
                    'success' => false,
                    'message' => $hareketResult['message'],
                    'inserted' => 0,
                    'skipped' => 0
                ];
            }
            
            $inserted = 0;
            $skipped = 0;
            
            foreach ($hareketResult['data'] as $hareket) {
                // Normalize et ve kaydet
                $normalized = $this->normalizeHareket($hareket);
                
                if ($this->hareketKaydet($hesapId, $normalized)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            
            // Son senkron tarihini güncelle (API'den veri döndüyse)
            $this->sonSenkronGuncelle($hesapId, $inserted + $skipped);
            
            $this->logBasarili($logId, $inserted, "Eklenen: $inserted, Atlanan: $skipped");
            
            return [
                'success' => true,
                'message' => "$inserted yeni hareket eklendi, $skipped hareket zaten mevcuttu",
                'inserted' => $inserted,
                'skipped' => $skipped
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage(),
                'inserted' => 0,
                'skipped' => 0
            ];
        }
    }
    
    /**
     * Tüm hesapları senkronize et
     */
    public function senkronizeTumu(): array
    {
        $logId = $this->logBaslat('senkronizeTumHesaplar', null, 'Tüm Akbank hesapları');
        
        $hesaplar = $this->db->fetchAll("
            SELECT bankaHesap_id FROM banka_Hesap 
            WHERE bankaHesap_apiKimlik_id = ? 
              AND bankaHesap_durum = 1
              AND bankaHesap_musteriNo IS NOT NULL
        ", [$this->apiKimlik['apiKimlik_id']]);
        
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
            'success' => $hataliHesap == 0,
            'message' => $mesaj,
            'inserted' => $toplamInserted,
            'skipped' => $toplamSkipped,
            'hesap_basarili' => $basariliHesap,
            'hesap_hatali' => $hataliHesap,
            'hatalar' => $hatalar,
            'summary' => [
                'basarili' => $basariliHesap,
                'hatali' => $hataliHesap,
                'toplam_inserted' => $toplamInserted,
                'toplam_skipped' => $toplamSkipped
            ]
        ];
    }
    
    /**
     * Akbank hareket verisini BaseBankaService formatına normalize et
     */
    private function normalizeHareket(array $hareket): array
    {
        // Benzersiz identifier oluştur
        $identifier = 'AKB_' . ($hareket['islemTarihi'] ?? '') . '_' 
            . ($hareket['fisNo'] ?? '') . '_' 
            . abs($hareket['tutar']) . '_' 
            . ($hareket['borcAlacak'] ?? '');

        return [
            'identifier' => $identifier,
            'islemTarihi' => $hareket['tarih'], // Y-m-d
            'aciklama' => $hareket['aciklama'] ?? '',
            'tutar' => $hareket['tutar'],
            'bakiye' => $hareket['bakiye'] ?? 0,
            'borcAlacak' => ($hareket['borcAlacak'] === '+') ? 'A' : 'B', // A=Alacak, B=Borç
            'dovizKodu' => 'TRY',
            'karsiTarafIban' => $hareket['alacakliIban'] ?: ($hareket['borcluIban'] ?? null),
            'karsiTarafAdUnvan' => $hareket['ozelAlan2'] ?: null,
            'receiptNo' => $hareket['fisNo'] ?? null,
            'vknTckn' => $hareket['vkn'] ?? null,
            'masraf' => 0
        ];
    }
    
    // hareketKaydet: BaseBankaService'deki doğru kolon isimlerini kullanan metod kullanılır
}
