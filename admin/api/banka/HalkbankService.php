<?php
/**
 * Halkbank API Servisi
 * 
 * Halkbank SOAP API ile iletişim kurar (WSSE Authentication)
 * WSDL: https://webservice.halkbank.com.tr/HesapEkstreOrtakWS/HesapEkstreOrtak.svc?wsdl
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

/**
 * WSSE Authentication Header (Halkbank için)
 * Nonce + Created + PasswordText - Halkbank resmi örneği temel alındı
 */
class WsseAuthHeader extends \SoapHeader
{
    private $wssNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private $wsuNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private $passType = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';
    private $nonceType = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    function __construct($username, $password)
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $nonce = mt_rand();
        $encodedNonce = base64_encode(pack('H*', sha1(pack('H*', $nonce) . pack('a*', $created) . pack('a*', $password))));

        $root = new \SimpleXMLElement('<root/>');
        $security = $root->addChild('wsse:Security', null, $this->wssNs);
        $usernameToken = $security->addChild('wsse:UsernameToken', null, $this->wssNs);
        $usernameToken->addChild('wsse:Username', $username, $this->wssNs);

        $passNode = $usernameToken->addChild('wsse:Password', htmlspecialchars($password, ENT_XML1, 'UTF-8'), $this->wssNs);
        $passNode->addAttribute('Type', $this->passType);

        $nonceNode = $usernameToken->addChild('wsse:Nonce', $encodedNonce, $this->wssNs);
        $nonceNode->addAttribute('EncodingType', $this->nonceType);

        $usernameToken->addChild('wsu:Created', $created, $this->wsuNs);

        $root->registerXPathNamespace('wsse', $this->wssNs);
        $full = $root->xpath('/root/wsse:Security');
        $auth = $full[0]->asXML();

        parent::__construct($this->wssNs, 'Security', new \SoapVar($auth, XSD_ANYXML), true);
    }
}

class HalkbankService extends BaseBankaService
{
    private ?\SoapClient $soapClient = null;
    
    /**
     * SOAP Client oluştur (WSSE Auth ile)
     */
    private function getSoapClient(): \SoapClient
    {
        if ($this->soapClient === null) {
            $wsdl = $this->apiKimlik['apiKimlik_wsdl'] 
                ?? 'https://webservice.halkbank.com.tr/HesapEkstreOrtakWS/HesapEkstreOrtak.svc?wsdl';
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ],
                'http' => [
                    'timeout' => 60
                ]
            ]);
            
            $this->soapClient = new \SoapClient($wsdl, [
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'stream_context' => $context,
                'connection_timeout' => 60
            ]);
            
            // WSSE Header ekle
            $header = new WsseAuthHeader(
                $this->apiKimlik['apiKimlik_kullanici'],
                $this->apiKimlik['apiKimlik_sifre']
            );
            $this->soapClient->__setSoapHeaders([$header]);
        }
        
        return $this->soapClient;
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
                    'methods' => array_slice($functions, 0, 10)
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
     * Not: Halkbank API'sinde hesap listesi yok, EkstreSorgulama ile hesap bilgisi gelir
     * Bu metod DB'deki hesapları döndürür
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'Hesap listesi çekiliyor');
        
        try {
            // Halkbank hesaplarını DB'den al
            $hesaplar = $this->db->fetchAll("
                SELECT * FROM banka_Hesap 
                WHERE bankaHesap_banka_id = ?
                  AND bankaHesap_apiKimlik_id = ?
                  AND bankaHesap_durum = 1
                  AND ISNULL(LTRIM(RTRIM(bankaHesap_iban)), '') != ''
            ", [$this->bankaId, $this->apiKimlik['apiKimlik_id']]);
            
            $normalizedHesaplar = [];
            foreach ($hesaplar as $hesap) {
                $normalizedHesaplar[] = [
                    'iban' => $hesap['bankaHesap_iban'],
                    'hesapNo' => $hesap['bankaHesap_no'],
                    'subeKodu' => $hesap['bankaHesap_sube_kodu'],
                    'bakiye' => $hesap['bankaHesap_bakiye'] ?? 0,
                    'dbId' => $hesap['bankaHesap_id']
                ];
            }
            
            $this->logBasarili($logId, count($normalizedHesaplar), json_encode(['hesap_sayisi' => count($normalizedHesaplar)]));
            
            return [
                'success' => true,
                'message' => count($normalizedHesaplar) . ' hesap bulundu.',
                'data' => $normalizedHesaplar,
                'count' => count($normalizedHesaplar)
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }
    
    /**
     * Hesap hareketlerini çek
     * API: EkstreSorgulama
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
        
        $logId = $this->logBaslat('getHareketler', $hesapId, "IBAN: {$hesap['bankaHesap_iban']}");
        
        try {
            $client = $this->getSoapClient();
            
            // Hesap numarasını parse et (9731-31202449-10261072 -> son kısım)
            $hesapNoParts = explode('-', $hesap['bankaHesap_no']);
            $hesapNo = end($hesapNoParts);
            $subeKodu = (int)$hesap['bankaHesap_sube_kodu'];
            
            // Halkbank /Basic endpoint ISO 8601 formatı bekliyor (Y-m-d)
            $baslangicFormatli = date('Y-m-d', strtotime($baslangicTarih));
            $bitisFormatli = date('Y-m-d', strtotime($bitisTarih));

            $params = [
                'request' => [
                    'HesapNo' => $hesapNo,
                    'SubeKodu' => $subeKodu,
                    'BaslangicTarihi' => $baslangicFormatli,
                    'BitisTarihi' => $bitisFormatli
                ]
            ];
            
            $response = $client->EkstreSorgulama($params);
            
            // Hata kontrolü
            $result = $response->EkstreSorgulamaResult ?? null;
            if (!$result) {
                $this->logHatali($logId, 'API yanıtında EkstreSorgulamaResult bulunamadı');
                return ['success' => false, 'message' => 'API yanıtı boş', 'data' => [], 'count' => 0];
            }
            $hataKodu = isset($result->HataKodu) ? (string)$result->HataKodu : '0';
            if ($hataKodu !== '0' && $hataKodu !== '') {
                $hataMesaji = (isset($result->HataAciklama) && $result->HataAciklama !== '') ? $result->HataAciklama : 'Bilinmeyen hata (Kod: ' . $hataKodu . ')';
                $this->logHatali($logId, $hataMesaji, $hataKodu);
                return ['success' => false, 'message' => "Banka Hatası: $hataMesaji", 'data' => [], 'count' => 0];
            }
            
            // Hesap bilgilerini al (zincirleme null erişimini güvenli yap)
            $hesaplar = isset($result->Hesaplar) ? $result->Hesaplar : null;
            $hesapBilgi = ($hesaplar !== null && isset($hesaplar->Hesap)) ? $hesaplar->Hesap : null;
            if (!$hesapBilgi) {
                // Hesap/hareket yok → başarılı ama boş
                $this->logBasarili($logId, 0, 'Hesap bilgisi/hareket bulunamadı (boş dönem)');
                return ['success' => true, 'message' => '0 hareket bulundu.', 'data' => [], 'count' => 0, 'bakiye' => null];
            }
            
            // Hareketleri parse et
            $hareketler = [];
            if (isset($hesapBilgi->Hareketler->Hareket)) {
                $hareketData = $hesapBilgi->Hareketler->Hareket;
                if (!is_array($hareketData)) {
                    $hareketData = [$hareketData];
                }
                
                foreach ($hareketData as $h) {
                    // stdClass olup olmadığını kontrol et
                    if (!is_object($h)) {
                        continue;
                    }
                    
                    // Doğru alan isimleri: HareketTutari, Tarih, EkstreAciklama, DekontNo, Bakiye
                    // Türkçe format: +2.617.780,00 -> binlik ayracı "." kaldır, ondalık "," -> "."
                    $tutarStr = $h->HareketTutari ?? '0';
                    $bakiyeStr = $h->Bakiye ?? '0';
                    
                    // Binlik ayracı "." kaldır, ondalık "," -> "." yap, "+" işaretini kaldır
                    $tutar = (float)str_replace(['+', '.', ','], ['', '', '.'], $tutarStr);
                    $bakiye = (float)str_replace(['+', '.', ','], ['', '', '.'], $bakiyeStr);
                    
                    // Tarih formatını düzelt (02/03/2026 -> 2026-03-02)
                    $tarihStr = $h->Tarih ?? null;
                    $islemTarihi = null;
                    if ($tarihStr) {
                        $parts = explode('/', $tarihStr);
                        if (count($parts) == 3) {
                            $islemTarihi = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                        }
                    }
                    
                    // Saat ekle
                    $saat = $h->Saat ?? '00:00:00';
                    if ($islemTarihi && $saat) {
                        $islemTarihi .= ' ' . $saat;
                    }
                    
                    $hareketler[] = [
                        'tarih' => $islemTarihi,
                        'referansNo' => $h->DekontNo ?? '',
                        'sirano' => $h->Sirano ?? '',
                        'aciklama' => $h->EkstreAciklama ?? $h->Aciklama ?? '',
                        'islemTipi' => $h->IslemKod ?? '',
                        'tutar' => $tutar,
                        'bakiye' => $bakiye,
                        'giris' => $tutar > 0 ? $tutar : 0,
                        'cikis' => $tutar < 0 ? abs($tutar) : 0,
                        'karsiTarafIban' => $h->KarsiHesapIBAN ?? null,
                        'karsiTarafAd' => $h->KarsiAdSoyad ?? null,
                        'karsiKimlikNo' => $h->KarsiKimlikNo ?? null,
                        'islemYapanAdSoyad' => $h->IslemYapanAdSoyad ?? null,
                        'islemYapanKimlikNo' => $h->IslemYapanKimlikNo ?? null
                    ];
                }
            }
            
            // Bakiyeyi güncelle
            $hesapBakiye = (float)str_replace(['+', ','], ['', '.'], $hesapBilgi->Bakiye ?? '0');
            
            $this->logBasarili($logId, count($hareketler), json_encode(['hareket_sayisi' => count($hareketler), 'bakiye' => $hesapBakiye]));
            
            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu.',
                'data' => $hareketler,
                'count' => count($hareketler),
                'bakiye' => $hesapBakiye
            ];
            
        } catch (\SoapFault $e) {
            $this->logHatali($logId, $e->getMessage() ?: 'SoapFault (mesaj yok)', $e->faultcode ?? null);
            return ['success' => false, 'message' => 'SOAP Hatası: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        } catch (\Throwable $e) {
            // \Error dahil tüm PHP hataları (null->property, TypeError vb.)
            $this->logHatali($logId, get_class($e) . ': ' . ($e->getMessage() ?: 'Bilinmeyen hata'));
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }
    
    /**
     * Hesabı senkronize et (son senkrondan bugüne)
     */
    public function senkronize(int $hesapId): array
    {
        // Son 7 gün varsayılan
        $baslangicTarih = date('Y-m-d', strtotime('-7 days'));
        $bitisTarih = date('Y-m-d');
        
        return $this->senkronizeHesap($hesapId, $baslangicTarih, $bitisTarih);
    }
    
    /**
     * Tek hesabı senkronize et
     */
    public function senkronizeHesap(int $hesapId, string $baslangicTarih, string $bitisTarih): array
    {
        $logId = $this->logBaslat('senkronize', $hesapId, "Tarih: $baslangicTarih - $bitisTarih");
        
        try {
            // Hareketleri çek
            $result = $this->getHareketler($hesapId, $baslangicTarih, $bitisTarih);
            
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return $result;
            }
            
            $hareketler = $result['data'];
            $inserted = 0;
            $skipped = 0;
            
            foreach ($hareketler as $hareket) {
                // Unique identifier oluştur (DekontNo + Sirano)
                $identifier = 'HALKBANK_' . $hesapId . '_' . 
                    ($hareket['referansNo'] ?? '') . '_' . 
                    ($hareket['sirano'] ?? '');
                
                // Borç/Alacak belirle
                $borcAlacak = $hareket['tutar'] >= 0 ? 'A' : 'B';
                
                // BaseBankaService'deki hareketKaydet metodunu kullan
                $hareketData = [
                    'identifier' => $identifier,
                    'islemTarihi' => $hareket['tarih'],
                    'tutar' => abs($hareket['tutar']),
                    'aciklama' => $hareket['aciklama'] ?? '',
                    'borcAlacak' => $borcAlacak,
                    'dovizKodu' => 'TRY',
                    'bakiye' => $hareket['bakiye'] ?? 0,
                    'karsiTarafIban' => $hareket['karsiTarafIban'] ?? null,
                    // Karşı taraf boş ise işlem yapan bilgisi ile doldur (arama/filter için)
                    'karsiTarafAdUnvan' => !empty(trim((string)($hareket['karsiTarafAd'] ?? '')))
                        ? $hareket['karsiTarafAd']
                        : ($hareket['islemYapanAdSoyad'] ?? null),
                    'vknTckn' => !empty(trim((string)($hareket['karsiKimlikNo'] ?? '')))
                        ? $hareket['karsiKimlikNo']
                        : ($hareket['islemYapanKimlikNo'] ?? null),
                    'masraf' => 0,
                    'receiptNo' => $hareket['referansNo'] ?? null
                ];
                
                $eklendi = $this->hareketKaydet($hesapId, $hareketData);
                
                if ($eklendi) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            
            // Bakiyeyi DB'ye yaz (API'den gelen hesap bakiyesi)
            if (isset($result['bakiye']) && $result['bakiye'] !== null) {
                $this->db->execute("
                    UPDATE banka_Hesap SET 
                        bankaHesap_bakiye = ?,
                        bankaHesap_kullanilabilirBakiye = ?,
                        bankaHesap_guncelleme_tarihi = GETDATE()
                    WHERE bankaHesap_id = ?
                ", [$result['bakiye'], $result['bakiye'], $hesapId]);
            }

            // Son senkron tarihini güncelle (başarılı API cevabında, 0 kayıt dahil)
            $this->sonSenkronGuncelle($hesapId, max(1, $inserted + $skipped));
            
            $message = "$inserted yeni kayıt eklendi, $skipped kayıt atlandı.";
            $this->logBasarili($logId, $inserted, $message);
            
            return [
                'success' => true,
                'message' => $message,
                'inserted' => $inserted,
                'skipped' => $skipped
            ];
            
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'inserted' => 0, 'skipped' => 0];
        }
    }
    
    /**
     * Dekont sorgulama - Belirli bir işlemin dekontunu (PDF) çek
     * API: DekontSorgulama
     * 
     * @param int $hesapId banka_Hesap tablosundaki ID
     * @param string $dekontNo Dekont numarası
     * @return array ['success' => bool, 'message' => string, 'data' => array, 'debug' => array]
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
        
        $logId = $this->logBaslat('getDekont', $hesapId, "DekontNo: {$dekontNo}, IBAN: {$hesap['bankaHesap_iban']}");
        
        try {
            $client = $this->getSoapClient();
            
            // Hesap numarasını tam olarak kullan (9731-31202449-10261072 formatı gerekli)
            $hesapNo = $hesap['bankaHesap_no'];
            
            $params = [
                'request' => [
                    'DekontNo' => $dekontNo,
                    'HesapNo' => $hesapNo
                ]
            ];
            
            $response = $client->DekontSorgulama($params);
            
            // Debug bilgisi
            $debugInfo = [
                'gonderilen_params' => $params,
                'hesap_no_original' => $hesap['bankaHesap_no'],
                'hesap_no_parsed' => $hesapNo,
                'sube_kodu' => $hesap['bankaHesap_sube_kodu'],
                'soap_request' => $client->__getLastRequest(),
                'soap_response' => $client->__getLastResponse(),
                'raw_result' => json_decode(json_encode($response), true)
            ];
            
            // Hata kontrolü
            $result = $response->DekontSorgulamaResult ?? null;
            if (!$result) {
                $this->logHatali($logId, 'Dekont sorgulamadan yanıt alınamadı');
                return ['success' => false, 'message' => 'Dekont sorgulamadan yanıt alınamadı', 'data' => [], 'debug' => $debugInfo];
            }
            
            // Hata kodu kontrolü
            $hataKodu = $result->HataKodu ?? null;
            $hataAciklama = $result->HataAciklama ?? null;
            
            if ($hataKodu !== null && $hataKodu != '0' && $hataKodu != '00' && $hataKodu !== 0) {
                $hataMesaji = $hataAciklama ?? 'Bilinmeyen hata (Kod: ' . $hataKodu . ')';
                $this->logHatali($logId, $hataMesaji, (string)$hataKodu);
                return ['success' => false, 'message' => "Banka Hatası: $hataMesaji", 'data' => [], 'debug' => $debugInfo];
            }
            
            // Dekontları parse et
            $dekontlar = [];
            if (isset($result->Dekontlar->Dekont)) {
                $dekontData = $result->Dekontlar->Dekont;
                if (!is_array($dekontData)) {
                    $dekontData = [$dekontData];
                }
                
                foreach ($dekontData as $d) {
                    if (!is_object($d)) {
                        continue;
                    }
                    
                    // PHP SoapClient xs:base64Binary alanlarını otomatik decode eder
                    // Bu yüzden DekontIcerik zaten raw binary (PDF) içeriktir, tekrar base64_decode YAPMA!
                    $dekontlar[] = [
                        'dosyaTuru' => $d->DekontDosyaTuru ?? 'PDF',
                        'icerik' => $d->DekontIcerik ?? null,
                        'icerikRaw' => true, // SOAP tarafından otomatik decode edilmiş
                        'dekontNo' => $d->DekontNo ?? $dekontNo,
                        'hesapNo' => $d->HesapNo ?? $hesapNo,
                        'iptalFlag' => $d->IptalFlag ?? null
                    ];
                }
            }
            
            if (empty($dekontlar)) {
                $this->logHatali($logId, 'Dekont bulunamadı');
                return ['success' => false, 'message' => 'Dekont bulunamadı', 'data' => [], 'debug' => $debugInfo];
            }
            
            $this->logBasarili($logId, count($dekontlar), json_encode(['dekont_sayisi' => count($dekontlar)]));
            
            return [
                'success' => true,
                'message' => count($dekontlar) . ' dekont bulundu.',
                'data' => $dekontlar,
                'debug' => $debugInfo
            ];
            
        } catch (\SoapFault $e) {
            $debugInfo = [
                'soap_request' => $client->__getLastRequest() ?? null,
                'soap_response' => $client->__getLastResponse() ?? null,
                'fault_code' => $e->faultcode ?? null,
                'fault_string' => $e->faultstring ?? null,
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
     * @param string|null $kayitDizini Kaydedilecek dizin (null ise varsayılan uploads/dekontlar/)
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
        
        $kaydedilenDosyalar = [];
        
        foreach ($result['data'] as $dekont) {
            if (empty($dekont['icerik'])) {
                continue;
            }
            
            $dosyaTuru = strtolower($dekont['dosyaTuru'] ?? 'PDF');
            // DekontDosyaTuru genellikle ".pdf" olarak gelir (nokta dahil)
            $uzanti = ltrim($dosyaTuru, '.');
            $uzanti = match($uzanti) {
                'pdf' => 'pdf',
                'png' => 'png',
                'jpg', 'jpeg' => 'jpg',
                default => 'pdf'
            };
            
            $dosyaAdi = 'HALKBANK_' . $dekontNo . '_' . $hesapId . '_' . date('Ymd_His') . '.' . $uzanti;
            $dosyaYolu = $dizin . $dosyaAdi;
            
            // PHP SoapClient xs:base64Binary alanlarını otomatik decode eder
            // Bu nedenle icerik zaten raw binary'dir, tekrar base64_decode YAPMA!
            $icerik = $dekont['icerik'];
            
            // Güvenlik kontrolü: Eğer hâlâ base64 string ise (farklı SOAP config) decode et
            if (is_string($icerik) && substr($icerik, 0, 4) !== '%PDF' && base64_decode($icerik, true) !== false) {
                $decoded = base64_decode($icerik);
                if ($decoded !== false && substr($decoded, 0, 4) === '%PDF') {
                    $icerik = $decoded;
                }
            }
            
            file_put_contents($dosyaYolu, $icerik);
            
            $kaydedilenDosyalar[] = [
                'dosyaAdi' => $dosyaAdi,
                'dosyaYolu' => $dosyaYolu,
                'dosyaTuru' => $dosyaTuru,
                'boyut' => strlen($icerik),
                'dekontNo' => $dekont['dekontNo']
            ];
        }
        
        if (empty($kaydedilenDosyalar)) {
            return ['success' => false, 'message' => 'Dekont dosyası kaydedilemedi', 'dosyaYolu' => null];
        }
        
        // DB'de ilgili hareket kaydının dekont yolunu güncelle
        // Relative path olarak kaydet (taşınabilirlik için)
        $relativePath = 'admin/assets/uploads/dekontlar/' . $kaydedilenDosyalar[0]['dosyaAdi'];
        try {
            // Önce ReceiptNo ile eşleştirmeyi dene
            $this->db->execute("
                UPDATE banka_HesapHareketleri 
                SET banka_HesapHareketleriDekontYolu = ? 
                WHERE ReceiptNo = ? AND banka_HesapId = ?
            ", [$relativePath, $dekontNo, $hesapId]);
            
            // Eğer ReceiptNo NULL ise Identifier içindeki dekont no ile eşleştir
            // Identifier formatı: HALKBANK_{hesapId}_{dekontNo}_{sirano}
            $this->db->execute("
                UPDATE banka_HesapHareketleri 
                SET banka_HesapHareketleriDekontYolu = ?,
                    ReceiptNo = ?
                WHERE banka_HesapHareketleriDekontYolu IS NULL
                    AND banka_HesapId = ?
                    AND banka_HesapHareketleriIdentifier LIKE ?
            ", [$relativePath, $dekontNo, $hesapId, '%_' . $dekontNo . '_%']);
        } catch (\Exception $e) {
            // Dekont kaydedildi ama DB güncellenemedi - log tut ama başarılı dön
            error_log('Dekont DB güncelleme hatası: ' . $e->getMessage());
        }
        
        return [
            'success' => true,
            'message' => count($kaydedilenDosyalar) . ' dekont dosyası kaydedildi.',
            'dosyaYolu' => $kaydedilenDosyalar[0]['dosyaYolu'],
            'dosyalar' => $kaydedilenDosyalar
        ];
    }
    
    /**
     * Tüm hesapları senkronize et
     */
    public function senkronizeTumu(?string $baslangicTarih = null, ?string $bitisTarih = null): array
    {
        // Varsayılan tarihler - son 7 gün
        $baslangicTarih = $baslangicTarih ?? date('Y-m-d', strtotime('-7 days'));
        $bitisTarih = $bitisTarih ?? date('Y-m-d');
        
        $hesaplar = $this->getHesaplar();
        
        if (!$hesaplar['success'] || empty($hesaplar['data'])) {
            return [
                'success' => false,
                'message' => 'Senkronize edilecek hesap bulunamadı.',
                'summary' => ['toplam_inserted' => 0, 'toplam_skipped' => 0]
            ];
        }
        
        $toplamInserted = 0;
        $toplamSkipped = 0;
        $detaylar = [];
        
        foreach ($hesaplar['data'] as $hesap) {
            $result = $this->senkronizeHesap($hesap['dbId'], $baslangicTarih, $bitisTarih);
            
            $detaylar[] = [
                'hesap_id' => $hesap['dbId'],
                'iban' => $hesap['iban'],
                'success' => $result['success'],
                'inserted' => $result['inserted'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'message' => $result['message']
            ];
            
            $toplamInserted += $result['inserted'] ?? 0;
            $toplamSkipped += $result['skipped'] ?? 0;
        }
        
        return [
            'success' => true,
            'message' => "$toplamInserted yeni kayıt eklendi, $toplamSkipped kayıt atlandı.",
            'summary' => [
                'toplam_inserted' => $toplamInserted,
                'toplam_skipped' => $toplamSkipped
            ],
            'details' => $detaylar
        ];
    }
}
