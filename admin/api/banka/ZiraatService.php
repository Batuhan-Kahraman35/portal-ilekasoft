<?php
/**
 * Ziraat Bankası API Servisi
 * 
 * Ziraat SOAP API ile iletişim kurar
 * WSDL: https://hesap.ziraatbank.com.tr/HEK_NKYWS/HesapHareketleri.asmx?wsdl
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class ZiraatService extends BaseBankaService
{
    private ?\SoapClient $soapClient = null;
    
    /**
     * SOAP Client oluştur
     */
    private function getSoapClient(): \SoapClient
    {
        if ($this->soapClient === null) {
            $wsdl = $this->apiKimlik['apiKimlik_wsdl'];
            
            if (empty($wsdl)) {
                throw new \Exception('WSDL adresi tanımlanmamış!');
            }
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ],
                'http' => [
                    'timeout' => 30
                ]
            ]);
            
            $this->soapClient = new \SoapClient($wsdl, [
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'stream_context' => $context,
                'connection_timeout' => 30,
                'soap_version' => SOAP_1_1
            ]);
        }
        
        return $this->soapClient;
    }
    
    /**
     * Kimlik doğrulama parametreleri
     */
    private function getAuthParams(): array
    {
        return [
            'kurumKod' => $this->apiKimlik['apiKimlik_kurumKod'],
            'sifre' => $this->apiKimlik['apiKimlik_sifre'],
            'vkn' => $this->apiKimlik['apiKimlik_vkn']
        ];
    }
    
    /**
     * API bağlantı testi
     */
    public function testConnection(): array
    {
        $logId = $this->logBaslat('testConnection', null, 'WSDL bağlantı testi');
        
        try {
            $client = $this->getSoapClient();
            $functions = $client->__getFunctions();
            
            $this->logBasarili($logId, count($functions), 'Bağlantı başarılı. ' . count($functions) . ' method bulundu.');
            
            return [
                'success' => true,
                'message' => 'Bağlantı başarılı! ' . count($functions) . ' method bulundu.',
                'data' => [
                    'method_count' => count($functions),
                    'methods' => array_slice($functions, 0, 10) // İlk 10 method
                ]
            ];
        } catch (\SoapFault $e) {
            $this->logHatali($logId, $e->getMessage(), $e->faultcode ?? null);
            return [
                'success' => false,
                'message' => 'SOAP Hatası: ' . $e->getMessage(),
                'data' => null
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
     * Tanımlı hesap listesini çek
     * API: GetirTanimliHesapBilgileri
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'Hesap listesi çekiliyor');
        
        try {
            $client = $this->getSoapClient();
            $auth = $this->getAuthParams();
            
            $response = $client->GetirTanimliHesapBilgileri([
                'kurumKod' => $auth['kurumKod'],
                'sifre' => $auth['sifre'],
                'tcknVkn' => $auth['vkn']
            ]);
            
            // Yanıtı parse et
            $result = $response->GetirTanimliHesapBilgileriResult ?? null;
            
            if (!$result) {
                $this->logHatali($logId, 'API yanıt vermedi');
                return ['success' => false, 'message' => 'API yanıt vermedi', 'data' => [], 'count' => 0];
            }
            
            // Hata kontrolü
            $cevapKodu = $result->CevapKodu ?? -1;
            if ($cevapKodu != 0) {
                $hataMesaji = $result->CevapMesaji ?? 'Bilinmeyen hata';
                $this->logHatali($logId, $hataMesaji, (string)$cevapKodu);
                return ['success' => false, 'message' => "Banka Hatası ($cevapKodu): $hataMesaji", 'data' => [], 'count' => 0];
            }
            
            // Hesapları parse et
            $hesaplar = [];
            $hesapListesi = $result->KullaniciHesapBilgileri->KullaniciHesapBilgi ?? [];
            
            // Tek hesap varsa array'e çevir
            if (!is_array($hesapListesi)) {
                $hesapListesi = [$hesapListesi];
            }
            
            foreach ($hesapListesi as $hesap) {
                $normalizedHesap = [
                    'iban' => $hesap->IBAN ?? '',
                    'musteriNo' => $hesap->MusteriNo ?? '',
                    'ekNo' => $hesap->EkNo ?? '',
                    'subeKodu' => $hesap->SubeKodu ?? '',
                    'hesapNo' => $hesap->HesapNo ?? '',
                    'dovizKodu' => $hesap->DovizKodu ?? 'TRY',
                    'aciklama' => $hesap->AdSoyadUnvan ?? $hesap->SubeAdi ?? null,
                    'bakiye' => $hesap->Bakiye ?? 0
                ];
                
                // Veritabanına kaydet/güncelle
                $hesapId = $this->hesapKaydetVeyaGuncelle($normalizedHesap);
                $normalizedHesap['dbId'] = $hesapId;
                
                $hesaplar[] = $normalizedHesap;
            }
            
            $this->logBasarili($logId, count($hesaplar), json_encode(['hesap_sayisi' => count($hesaplar)]));
            
            return [
                'success' => true,
                'message' => count($hesaplar) . ' hesap bulundu.',
                'data' => $hesaplar,
                'count' => count($hesaplar)
            ];
            
        } catch (\SoapFault $e) {
            $this->logHatali($logId, $e->getMessage(), $e->faultcode ?? null);
            return ['success' => false, 'message' => 'SOAP Hatası: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }
    
    /**
     * Hesap hareketlerini çek
     * API: SorgulaHesapHareket (musteriNo + ekNo ile)
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
        
        // MusteriNo ve EkNo kontrolü
        if (empty($hesap['bankaHesap_musteriNo']) || empty($hesap['bankaHesap_ekNo'])) {
            return [
                'success' => false, 
                'message' => 'Hesap için MusteriNo ve EkNo tanımlanmamış. Önce getHesaplar() çalıştırın.', 
                'data' => [], 
                'count' => 0
            ];
        }
        
        $istekOzet = json_encode([
            'hesapId' => $hesapId,
            'iban' => $hesap['bankaHesap_iban'],
            'baslangic' => $baslangicTarih,
            'bitis' => $bitisTarih
        ]);
        
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);
        
        try {
            $client = $this->getSoapClient();
            $auth = $this->getAuthParams();
            
            // SorgulaHesapHareket parametreleri (WSDL'den)
            $response = $client->SorgulaHesapHareket([
                'kurumKod' => $auth['kurumKod'],
                'sifre' => $auth['sifre'],
                'musteriNo' => (int)$hesap['bankaHesap_musteriNo'],
                'ekNo' => (int)$hesap['bankaHesap_ekNo'],
                'baslangicTr' => $baslangicTarih,
                'bitisTr' => $bitisTarih
            ]);
            
            $result = $response->SorgulaHesapHareketResult ?? null;
            
            if (!$result) {
                $this->logHatali($logId, 'API yanıt vermedi');
                return ['success' => false, 'message' => 'API yanıt vermedi', 'data' => [], 'count' => 0];
            }
            
            // Hata kontrolü (HesapHareketCevap yapısı: hataKodu, hataAck)
            $hataKodu = $result->hataKodu ?? '';
            if (!empty($hataKodu) && $hataKodu != '0' && $hataKodu != '00') {
                // Hata kodu 06: "İlgili tarih aralığında kayıt bulunamadı"
                // Bu normal bir durum, hata değil → başarılı + boş dizi döndür
                if ($hataKodu === '06') {
                    $this->logBasarili($logId, 0, 'Belirtilen tarih aralığında işlem bulunamadı.');
                    return ['success' => true, 'message' => '0 hareket bulundu.', 'data' => [], 'count' => 0];
                }
                $hataMesaji = $result->hataAck ?? 'Bilinmeyen hata';
                $this->logHatali($logId, $hataMesaji, $hataKodu);
                return ['success' => false, 'message' => "Banka Hatası ($hataKodu): $hataMesaji", 'data' => [], 'count' => 0];
            }
            
            // Hareketleri parse et (hareketdetay -> HareketlerDetay)
            $hareketler = [];
            $hareketListesi = $result->hareketdetay->HareketlerDetay ?? [];
            
            // Tek hareket varsa array'e çevir
            if (!is_array($hareketListesi)) {
                $hareketListesi = [$hareketListesi];
            }
            
            foreach ($hareketListesi as $hareket) {
                $normalizedHareket = $this->normalizeHareket($hareket);
                $hareketler[] = $normalizedHareket;
            }
            
            $this->logBasarili($logId, count($hareketler), json_encode(['hareket_sayisi' => count($hareketler)]));
            
            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu.',
                'data' => $hareketler,
                'count' => count($hareketler)
            ];
            
        } catch (\SoapFault $e) {
            $this->logHatali($logId, $e->getMessage(), $e->faultcode ?? null);
            return ['success' => false, 'message' => 'SOAP Hatası: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }
    
    /**
     * Ziraat hareket verisini normalize et
     * HareketlerDetay yapısına göre (WSDL)
     */
    private function normalizeHareket(object $hareket): array
    {
        // timeStamp'ı benzersiz tanımlayıcı olarak kullan
        $timeStamp = $hareket->timeStamp ?? '';
        
        // islemTarihi DateTime objesi olabilir
        $islemTarihi = '';
        if (isset($hareket->islemTarihi)) {
            if ($hareket->islemTarihi instanceof \DateTime) {
                $islemTarihi = $hareket->islemTarihi->format('Y-m-d H:i:s');
            } else {
                $islemTarihi = str_replace('T', ' ', (string)$hareket->islemTarihi);
            }
        }
        
        return [
            'identifier' => $timeStamp, // Benzersiz tanımlayıcı
            'islemTarihi' => $islemTarihi,
            'tutar' => floatval($hareket->tutar ?? 0),
            'borcAlacak' => ($hareket->borcAlacak ?? '') === 'A' ? 'A' : 'B',
            'aciklama' => $hareket->aciklama ?? '',
            'karsiTarafIban' => $hareket->iban ?? '',
            'karsiTarafAdUnvan' => $hareket->adUnvan ?? '',
            'vknTckn' => $hareket->tcknVkn ?? '',
            'bakiye' => floatval($hareket->Bakiye ?? 0),
            'dovizKodu' => $hareket->dovizTipi ?? 'TRY',
            'masraf' => 0,
            'islemNo' => $hareket->dekontNo ?? '',
            'receiptNo' => $hareket->dekontNo ?? '', // hareketKaydet ReceiptNo kolonuna yazar
            'islemKodu' => $hareket->islemKodu ?? '',
            'islemAciklama' => $hareket->islemAciklama ?? '',
            'programKod' => $hareket->programKod ?? ''
        ];
    }
    
    /**
     * Hesabı senkronize et
     */
    public function senkronize(int $hesapId): array
    {
        $logId = $this->logBaslat('senkronize', $hesapId, "Hesap ID: $hesapId senkronizasyonu");
        
        try {
            // Son senkron tarihini al (yoksa 7 gün önce)
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            $baslangic = $sonSenkron ?? date('Y-m-d', strtotime('-7 days'));
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
            
            // Son senkron tarihini güncelle
            // API başarılı döndüyse (0 kayıt dahil) tarihi güncelle, aksi halde bir sonraki çalışmada
            // aynı tarih aralığı sorgulanmaya devam eder ve tarih ilerlemez
            $this->sonSenkronGuncelle($hesapId, max(1, $inserted + $skipped));
            
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
    
    /**
     * Dekont sorgulama - Belirli bir işlemin dekontunu (PDF) çek
     * API: GetirPdfDekontDekontNoIle
     * 
     * WSDL Struct:
     *   GetirPdfDekontDekontNoIle { kurumKod, sifre, musteriNo, ekNo, dekontNo }
     *   DekontCevap { CevapKodu, CevapAciklama, DekontDetayi }
     *   DekontDetay { MuhasebeTarihi, MuhasebeSubeKodu, MuhasebeRef, Borc, Alacak, SiraNo, IslemZamani, IslemKodu, IslemAciklama, PdfDekont }
     * 
     * @param int $hesapId banka_Hesap tablosundaki ID
     * @param string $dekontNo Dekont numarası
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function getDekont(int $hesapId, string $dekontNo): array
    {
        // Hesap bilgilerini al
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ?
        ", [$hesapId]);
        
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'data' => []];
        }
        
        if (empty($hesap['bankaHesap_musteriNo']) || empty($hesap['bankaHesap_ekNo'])) {
            return ['success' => false, 'message' => 'Hesap için MusteriNo ve EkNo tanımlanmamış.', 'data' => []];
        }
        
        $logId = $this->logBaslat('getDekont', $hesapId, "DekontNo: {$dekontNo}, IBAN: {$hesap['bankaHesap_iban']}");
        
        try {
            $client = $this->getSoapClient();
            $auth = $this->getAuthParams();
            
            $params = [
                'kurumKod' => $auth['kurumKod'],
                'sifre' => $auth['sifre'],
                'musteriNo' => (int)$hesap['bankaHesap_musteriNo'],
                'ekNo' => (int)$hesap['bankaHesap_ekNo'],
                'dekontNo' => $dekontNo
            ];
            
            $response = $client->GetirPdfDekontDekontNoIle($params);
            
            // Debug bilgisi
            $debugInfo = [
                'gonderilen_params' => $params,
                'musteriNo' => $hesap['bankaHesap_musteriNo'],
                'ekNo' => $hesap['bankaHesap_ekNo'],
                'raw_result' => json_decode(json_encode($response), true)
            ];
            
            // Hata kontrolü
            $result = $response->GetirPdfDekontDekontNoIleResult ?? null;
            if (!$result) {
                $this->logHatali($logId, 'Dekont sorgulamadan yanıt alınamadı');
                return ['success' => false, 'message' => 'Dekont sorgulamadan yanıt alınamadı', 'data' => [], 'debug' => $debugInfo];
            }
            
            // Cevap kodu kontrolü
            $cevapKodu = $result->CevapKodu ?? -1;
            $cevapAciklama = $result->CevapAciklama ?? '';
            
            if ($cevapKodu != 0) {
                $hataMesaji = $cevapAciklama ?: "Bilinmeyen hata (Kod: $cevapKodu)";
                $this->logHatali($logId, $hataMesaji, (string)$cevapKodu);
                return ['success' => false, 'message' => "Banka Hatası: $hataMesaji", 'data' => [], 'debug' => $debugInfo];
            }
            
            // DekontDetayi parse et
            $dekontDetay = $result->DekontDetayi ?? null;
            if (!$dekontDetay) {
                $this->logHatali($logId, 'Dekont detayı bulunamadı');
                return ['success' => false, 'message' => 'Dekont detayı bulunamadı', 'data' => [], 'debug' => $debugInfo];
            }
            
            // PdfDekont alanı: string (base64) olarak gelir
            $pdfContent = $dekontDetay->PdfDekont ?? null;
            if (empty($pdfContent)) {
                $this->logHatali($logId, 'PDF içeriği boş');
                return ['success' => false, 'message' => 'PDF içeriği boş', 'data' => [], 'debug' => $debugInfo];
            }
            
            $dekontlar = [[
                'dosyaTuru' => 'PDF',
                'icerik' => $pdfContent,
                'icerikRaw' => false, // Ziraat base64 string olarak gönderir (DekontBase64 tipi)
                'dekontNo' => $dekontNo,
                'muhasebeRef' => $dekontDetay->MuhasebeRef ?? null,
                'islemKodu' => $dekontDetay->IslemKodu ?? null,
                'islemAciklama' => $dekontDetay->IslemAciklama ?? null,
                'borc' => $dekontDetay->Borc ?? 0,
                'alacak' => $dekontDetay->Alacak ?? 0
            ]];
            
            $this->logBasarili($logId, 1, json_encode(['dekont_no' => $dekontNo]));
            
            return [
                'success' => true,
                'message' => 'Dekont bulundu.',
                'data' => $dekontlar,
                'debug' => $debugInfo
            ];
            
        } catch (\SoapFault $e) {
            $debugInfo = [
                'soap_request' => $client->__getLastRequest() ?? null,
                'soap_response' => $client->__getLastResponse() ?? null,
                'fault_code' => $e->faultcode ?? null,
            ];
            $this->logHatali($logId, $e->getMessage(), $e->faultcode ?? null);
            return ['success' => false, 'message' => 'SOAP Hatası: ' . $e->getMessage(), 'data' => [], 'debug' => $debugInfo];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => []];
        }
    }
    
    /**
     * Dekont dosyasını kaydet ve dosya yolunu döndür
     * 
     * @param int $hesapId banka_Hesap tablosundaki ID
     * @param string $dekontNo Dekont numarası
     * @param string|null $kayitDizini Kaydedilecek dizin (null ise varsayılan)
     * @return array ['success' => bool, 'message' => string, 'dosyaYolu' => string|null]
     */
    public function dekontIndir(int $hesapId, string $dekontNo, ?string $kayitDizini = null): array
    {
        $result = $this->getDekont($hesapId, $dekontNo);
        
        if (!$result['success'] || empty($result['data'])) {
            return ['success' => false, 'message' => $result['message'], 'dosyaYolu' => null];
        }
        
        $dizin = $kayitDizini ?? __DIR__ . '/../../assets/uploads/dekontlar/';
        if (!is_dir($dizin)) {
            mkdir($dizin, 0755, true);
        }
        
        $dekont = $result['data'][0];
        
        if (empty($dekont['icerik'])) {
            return ['success' => false, 'message' => 'Dekont içeriği boş', 'dosyaYolu' => null];
        }
        
        // Ziraat PdfDekont alanı:
        // - DekontDetay.PdfDekont → string (base64 encoded)
        // - PHP SoapClient bazı durumlarda otomatik decode edebilir
        $icerik = $dekont['icerik'];
        
        // Eğer içerik zaten %PDF ile başlıyorsa raw binary'dir (otomatik decode edilmiş)
        if (is_string($icerik) && substr($icerik, 0, 4) === '%PDF') {
            // Zaten raw PDF, doğrudan kaydet
        } elseif (is_string($icerik)) {
            // Base64 string, decode et
            $decoded = base64_decode($icerik, true);
            if ($decoded !== false && substr($decoded, 0, 4) === '%PDF') {
                $icerik = $decoded;
            } elseif ($decoded !== false) {
                // PDF header yok ama decode başarılı, yine de kaydet
                $icerik = $decoded;
            }
            // base64_decode başarısız → olduğu gibi kaydet (belki binary gelmiştir)
        }
        
        $dosyaAdi = 'ZIRAAT_' . $dekontNo . '_' . $hesapId . '_' . date('Ymd_His') . '.pdf';
        $dosyaYolu = $dizin . $dosyaAdi;
        
        file_put_contents($dosyaYolu, $icerik);
        
        // DB'de ilgili hareket kaydının dekont yolunu güncelle
        $relativePath = 'admin/assets/uploads/dekontlar/' . $dosyaAdi;
        try {
            // Önce ReceiptNo ile eşleştirmeyi dene
            $this->db->execute("
                UPDATE banka_HesapHareketleri 
                SET banka_HesapHareketleriDekontYolu = ? 
                WHERE ReceiptNo = ? AND banka_HesapId = ?
            ", [$relativePath, $dekontNo, $hesapId]);
            
            // Identifier içindeki dekontNo ile fallback eşleştirme
            $this->db->execute("
                UPDATE banka_HesapHareketleri 
                SET banka_HesapHareketleriDekontYolu = ?,
                    ReceiptNo = ?
                WHERE banka_HesapHareketleriDekontYolu IS NULL
                    AND banka_HesapId = ?
                    AND banka_HesapHareketleriIdentifier LIKE ?
            ", [$relativePath, $dekontNo, $hesapId, '%' . $dekontNo . '%']);
        } catch (\Exception $e) {
            error_log('Ziraat Dekont DB güncelleme hatası: ' . $e->getMessage());
        }
        
        return [
            'success' => true,
            'message' => 'Dekont dosyası kaydedildi.',
            'dosyaYolu' => $dosyaYolu,
            'dosyalar' => [[
                'dosyaAdi' => $dosyaAdi,
                'dosyaYolu' => $dosyaYolu,
                'boyut' => strlen($icerik),
                'dekontNo' => $dekontNo
            ]]
        ];
    }
}
