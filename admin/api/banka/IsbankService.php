<?php
/**
 * İş Bankası API Servisi
 * 
 * İşbank Posmatik XML servisi ile iletişim kurar
 * Endpoint: https://posmatik2.isbank.com.tr/Authenticate.aspx
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class IsbankService extends BaseBankaService
{
    private const LOGIN_URL = 'https://posmatik2.isbank.com.tr/Authenticate.aspx';
    private const AUTH_URL = 'https://posmatik2.isbank.com.tr/AuthenticateSpecific.aspx';
    
    /**
     * cURL ile HTTP POST isteği gönder
     */
    private function httpPost(string $url, array $data, ?string $cookies = null): array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);
        
        if ($cookies) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => $error,
                'httpCode' => $httpCode
            ];
        }
        
        // Header ve body'yi ayır
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Cookies'i parse et
        preg_match_all('/Set-Cookie:\s*([^;]+)/i', $headers, $matches);
        $responseCookies = implode('; ', $matches[1] ?? []);
        
        return [
            'success' => true,
            'body' => $body,
            'httpCode' => $httpCode,
            'cookies' => $responseCookies,
            'headers' => $headers
        ];
    }
    
    /**
     * İşbank'a giriş yap ve XML veri al
     * 
     * @param string|null $baslangicTarih DD/MM/YYYY formatında
     * @param string|null $bitisTarih DD/MM/YYYY formatında
     * @return array
     */
    private function login(?string $baslangicTarih = null, ?string $bitisTarih = null): array
    {
        $kullanici = $this->apiKimlik['apiKimlik_kullanici'];
        $sifre = $this->apiKimlik['apiKimlik_sifre'];
        
        if (empty($kullanici) || empty($sifre)) {
            return [
                'success' => false,
                'message' => 'Kullanıcı adı veya şifre tanımlanmamış'
            ];
        }
        
        // Tarih yoksa son 30 gün
        if (!$baslangicTarih) {
            $baslangicTarih = date('d.m.Y', strtotime('-30 days')) . ' 00:00:00';
        }
        if (!$bitisTarih) {
            $bitisTarih = date('d.m.Y') . ' 23:59:59';
        }
        
        // Login isteği - Doğru form alan isimleri
        $loginData = [
            'uid' => $kullanici,
            'pwd' => $sifre,
            'BeginDate' => $baslangicTarih,
            'EndDate' => $bitisTarih
        ];
        
        $response = $this->httpPost(self::LOGIN_URL, $loginData);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'message' => 'HTTP Hatası: ' . ($response['error'] ?? 'Bilinmeyen')
            ];
        }
        
        $body = $response['body'];
        
        // XML içeriğini kontrol et
        if (strpos($body, '<XMLEXBAT>') === false) {
            // Hata mesajı ara
            if (strpos($body, 'Hatalı') !== false || strpos($body, 'hata') !== false) {
                return [
                    'success' => false,
                    'message' => 'Giriş başarısız. Kullanıcı adı veya şifre hatalı.'
                ];
            }
            return [
                'success' => false,
                'message' => 'Beklenmeyen yanıt formatı: ' . substr($body, 0, 100)
            ];
        }
        
        return [
            'success' => true,
            'xml' => $body
        ];
    }
    
    /**
     * XML'i parse et
     * 
     * @param string $xmlString
     * @return array
     */
    private function parseXml(string $xmlString): array
    {
        // XML string'i temizle
        $xmlString = trim($xmlString);
        
        // İlk satırda "This XML file..." varsa kaldır
        if (strpos($xmlString, 'This XML file') !== false) {
            $pos = strpos($xmlString, '<XMLEXBAT>');
            if ($pos !== false) {
                $xmlString = substr($xmlString, $pos);
            }
        }
        
        // windows-1254 encoding'i UTF-8'e çevir
        if (strpos($xmlString, 'windows-1254') !== false) {
            $xmlString = str_replace('encoding="windows-1254"', 'encoding="UTF-8"', $xmlString);
            $xmlString = mb_convert_encoding($xmlString, 'UTF-8', 'Windows-1254');
        }
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            return [
                'success' => false,
                'message' => 'XML parse hatası: ' . ($errors[0]->message ?? 'Bilinmeyen')
            ];
        }
        
        return [
            'success' => true,
            'data' => $xml
        ];
    }
    
    /**
     * API bağlantı testi
     */
    public function testConnection(): array
    {
        $logId = $this->logBaslat('testConnection', null, 'İşbank bağlantı testi');
        
        try {
            $result = $this->login();
            
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return [
                    'success' => false,
                    'message' => $result['message'],
                    'data' => null
                ];
            }
            
            // XML'i parse et ve hesap sayısını bul
            $parsed = $this->parseXml($result['xml']);
            if (!$parsed['success']) {
                $this->logHatali($logId, $parsed['message']);
                return [
                    'success' => false,
                    'message' => $parsed['message'],
                    'data' => null
                ];
            }
            
            $hesapSayisi = count($parsed['data']->Hesaplar->Hesap ?? []);
            
            $this->logBasarili($logId, $hesapSayisi, "Bağlantı başarılı. $hesapSayisi hesap bulundu.");
            
            return [
                'success' => true,
                'message' => "Bağlantı başarılı! $hesapSayisi hesap bulundu.",
                'data' => [
                    'hesap_sayisi' => $hesapSayisi,
                    'tarih' => (string)($parsed['data']->Tarih ?? ''),
                    'saat' => (string)($parsed['data']->Saat ?? '')
                ]
            ];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Bağlantı Hatası: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Hesap listesini çek
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'İşbank hesap listesi çekiliyor');
        
        try {
            $result = $this->login();
            
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return ['success' => false, 'message' => $result['message'], 'data' => [], 'count' => 0];
            }
            
            $parsed = $this->parseXml($result['xml']);
            if (!$parsed['success']) {
                $this->logHatali($logId, $parsed['message']);
                return ['success' => false, 'message' => $parsed['message'], 'data' => [], 'count' => 0];
            }
            
            $xml = $parsed['data'];
            $hesaplar = [];
            
            // Hesapları parse et
            foreach ($xml->Hesaplar->Hesap as $hesap) {
                $tanimlar = $hesap->Tanimlamalar;
                
                $hesapNo = (string)($tanimlar->HesapNo ?? '');
                $subeKodu = (string)($tanimlar->SubeKodu ?? '');
                $musteriNo = (string)($tanimlar->MusteriNo ?? '');
                
                // IBAN oluştur (İşbank: TR + 2 check + 0064 + 0 + subeKodu(4) + 0 + hesapNo(10))
                // Örnek: TR000006400000100000000001
                $iban = $this->generateIban($subeKodu, $hesapNo);
                
                $normalizedHesap = [
                    'iban' => $iban,
                    'musteriNo' => $musteriNo,
                    'ekNo' => null,
                    'subeKodu' => $subeKodu,
                    'hesapNo' => $hesapNo,
                    'dovizKodu' => trim((string)($tanimlar->DovizTuru ?? 'TL')) === 'TL' ? 'TRY' : trim((string)$tanimlar->DovizTuru),
                    'aciklama' => (string)($tanimlar->SubeAdi ?? ''),
                    'bakiye' => floatval(str_replace(',', '.', (string)($tanimlar->Bakiye ?? 0))),
                    'hesapTuru' => (string)($tanimlar->HesapTuru ?? ''),
                    'hesapAcilisTarihi' => (string)($tanimlar->HesapAcilisTarihi ?? ''),
                    'sonHareketTarihi' => (string)($tanimlar->SonHareketTarihi ?? '')
                ];
                
                // Veritabanına kaydet/güncelle
                $hesapId = $this->hesapKaydetVeyaGuncelle($normalizedHesap);
                $normalizedHesap['dbId'] = $hesapId;

                // Bakiyeyi DB'ye yaz (İş Bankası gerçek Bakiye alanı döndürür).
                // Aksi halde bankaHesap_bakiye NULL kalır ve bakiyeleri sayfası
                // güvenilmez "son hareket kalan bakiyesi" tahminine düşer (İş Bankası
                // hareket tarihleri saatsiz olduğundan aynı günkü hareketlerde sıralama
                // belirsizdir ve yanlış satır seçilebilir).
                $this->db->execute("
                    UPDATE banka_Hesap
                       SET bankaHesap_bakiye = ?,
                           bankaHesap_kullanilabilirBakiye = ?,
                           bankaHesap_guncelleme_tarihi = GETDATE()
                     WHERE bankaHesap_id = ?
                ", [$normalizedHesap['bakiye'], $normalizedHesap['bakiye'], $hesapId]);

                $hesaplar[] = $normalizedHesap;
            }
            
            $this->logBasarili($logId, count($hesaplar), json_encode(['hesap_sayisi' => count($hesaplar)]));
            
            return [
                'success' => true,
                'message' => count($hesaplar) . ' hesap bulundu.',
                'data' => $hesaplar,
                'count' => count($hesaplar)
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }
    
    /**
     * İşbank IBAN oluştur (basit versiyon - mevcut IBAN'ı DB'den al)
     */
    private function generateIban(string $subeKodu, string $hesapNo): string
    {
        // Mevcut IBAN'ı DB'den bul
        $mevcut = $this->db->fetchOne("
            SELECT bankaHesap_iban FROM banka_Hesap 
            WHERE bankaHesap_banka_id = ? AND bankaHesap_no = ?
        ", [$this->bankaId, $hesapNo]);
        
        if ($mevcut && !empty($mevcut['bankaHesap_iban'])) {
            return $mevcut['bankaHesap_iban'];
        }
        
        // IBAN oluştur (İşbank formatı: TR + check(2) + 0064 + 0 + sube(4) + 0 + hesapNo(kayma))
        // Basit format - gerçek check digit hesaplaması yapılmıyor
        $subeKodu = str_pad($subeKodu, 4, '0', STR_PAD_LEFT);
        $hesapNo = str_pad($hesapNo, 10, '0', STR_PAD_LEFT);
        
        // Geçici IBAN (check digit 00 olarak)
        return 'TR00006400' . $subeKodu . '0' . $hesapNo;
    }
    
    /**
     * Hesap hareketlerini çek
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
        
        // Tarihleri DD.MM.YYYY HH:MM:SS formatına çevir
        $baslangicFormatli = date('d.m.Y', strtotime($baslangicTarih)) . ' 00:00:00';
        $bitisFormatli = date('d.m.Y', strtotime($bitisTarih)) . ' 23:59:59';
        
        $istekOzet = json_encode([
            'hesapId' => $hesapId,
            'hesapNo' => $hesap['bankaHesap_no'],
            'baslangic' => $baslangicFormatli,
            'bitis' => $bitisFormatli
        ]);
        
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);
        
        try {
            $result = $this->login($baslangicFormatli, $bitisFormatli);
            
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return ['success' => false, 'message' => $result['message'], 'data' => [], 'count' => 0];
            }
            
            $parsed = $this->parseXml($result['xml']);
            if (!$parsed['success']) {
                $this->logHatali($logId, $parsed['message']);
                return ['success' => false, 'message' => $parsed['message'], 'data' => [], 'count' => 0];
            }
            
            $xml = $parsed['data'];
            $hareketler = [];
            $hedefHesapNo = $hesap['bankaHesap_no'];
            
            // Tüm hesapları döngüle, hedef hesabı bul
            foreach ($xml->Hesaplar->Hesap as $xmlHesap) {
                $tanimlar = $xmlHesap->Tanimlamalar;
                $hesapNo = (string)($tanimlar->HesapNo ?? '');
                
                // Sadece istenen hesabın hareketlerini al
                if ($hesapNo !== $hedefHesapNo) {
                    continue;
                }
                
                // Hareketleri parse et
                if (isset($xmlHesap->Hareketler->Hareket)) {
                    foreach ($xmlHesap->Hareketler->Hareket as $hareket) {
                        $normalizedHareket = $this->normalizeHareket($hareket, $hesap);
                        $hareketler[] = $normalizedHareket;
                    }
                }
                
                break; // Hesabı bulduk, döngüden çık
            }
            
            $this->logBasarili($logId, count($hareketler), json_encode(['hareket_sayisi' => count($hareketler)]));
            
            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu.',
                'data' => $hareketler,
                'count' => count($hareketler)
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }
    
    /**
     * İşbank hareket verisini normalize et
     */
    private function normalizeHareket(object $hareket, array $hesap): array
    {
        // HareketSirano benzersiz tanımlayıcı
        $hareketSirano = (string)($hareket->HareketSirano ?? '');
        
        // Tarih: DD/MM/YYYY -> Y-m-d H:i:s
        $tarihStr = (string)($hareket->Tarih ?? '');
        $islemTarihi = '';
        if ($tarihStr) {
            $parts = explode('/', $tarihStr);
            if (count($parts) === 3) {
                $islemTarihi = "{$parts[2]}-{$parts[1]}-{$parts[0]} 00:00:00";
            }
        }
        
        // Miktar: Negatif = Borç, Pozitif = Alacak
        $miktar = floatval(str_replace(',', '.', (string)($hareket->Miktar ?? 0)));
        $borcAlacak = $miktar < 0 ? 'B' : 'A';
        $tutar = abs($miktar);
        
        // Bakiye
        $bakiye = floatval(str_replace(',', '.', (string)($hareket->Bakiye ?? 0)));
        
        // Açıklama
        $aciklama = (string)($hareket->Aciklama ?? '');
        
        // Identifier: BankaId-HesapNo-HareketSirano
        $identifier = $this->bankaId . '-' . $hesap['bankaHesap_no'] . '-' . $hareketSirano;
        
        return [
            'identifier' => $identifier,
            'islemTarihi' => $islemTarihi,
            'tutar' => $tutar,
            'borcAlacak' => $borcAlacak,
            'aciklama' => $aciklama,
            'karsiTarafIban' => '', // İşbank XML'de yok
            'karsiTarafAdUnvan' => '', // İşbank XML'de yok
            'vknTckn' => '',
            'bakiye' => $bakiye,
            'dovizKodu' => 'TRY',
            'masraf' => 0,
            'islemNo' => $hareketSirano,
            'islemKodu' => '',
            'islemAciklama' => $aciklama
        ];
    }
    
    /**
     * Hesabı senkronize et
     */
    public function senkronize(int $hesapId): array
    {
        $logId = $this->logBaslat('senkronize', $hesapId, "Hesap ID: $hesapId senkronizasyonu");
        
        try {
            // Son senkron tarihini al (yoksa 30 gün önce)
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            $baslangic = $sonSenkron ?? date('Y-m-d', strtotime('-30 days'));
            $bitis = date('Y-m-d');
            
            // Hareketleri çek
            $result = $this->getHareketler($hesapId, $baslangic, $bitis);
            
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return $result;
            }
            
            // Hareketleri kaydet
            $inserted = 0;
            $skipped = 0;
            
            foreach ($result['data'] as $hareket) {
                if ($this->hareketKaydet($hesapId, $hareket)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            
            // Son senkron tarihini güncelle (API'den veri döndüyse)
            $this->sonSenkronGuncelle($hesapId, $inserted + $skipped);
            
            $message = "$inserted yeni kayıt eklendi, $skipped kayıt zaten mevcut.";
            $this->logBasarili($logId, $inserted, $message);
            
            return [
                'success' => true,
                'message' => $message,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'total' => count($result['data'])
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return [
                'success' => false,
                'message' => 'Senkronizasyon hatası: ' . $e->getMessage(),
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
        $hesaplar = $this->getApiHesaplari();
        $results = [];
        $toplamInserted = 0;
        $toplamSkipped = 0;
        $basarili = 0;
        $hatali = 0;
        
        foreach ($hesaplar as $hesap) {
            $result = $this->senkronize($hesap['bankaHesap_id']);
            $results[] = [
                'hesap_id' => $hesap['bankaHesap_id'],
                'iban' => $hesap['bankaHesap_iban'],
                'result' => $result
            ];
            
            if ($result['success']) {
                $basarili++;
                $toplamInserted += $result['inserted'] ?? 0;
                $toplamSkipped += $result['skipped'] ?? 0;
            } else {
                $hatali++;
            }
        }
        
        return [
            'success' => $hatali === 0,
            'message' => "$basarili hesap başarılı, $hatali hesap hatalı. Toplam $toplamInserted yeni kayıt.",
            'results' => $results,
            'summary' => [
                'basarili' => $basarili,
                'hatali' => $hatali,
                'toplam_inserted' => $toplamInserted,
                'toplam_skipped' => $toplamSkipped
            ]
        ];
    }
}
