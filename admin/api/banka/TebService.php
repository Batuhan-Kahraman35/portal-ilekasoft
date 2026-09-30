<?php
/**
 * TEB (Türk Ekonomi Bankası) API Servisi
 * 
 * TEB SOAP API ile iletişim kurar (HESHARSORGU - Hesap Hareketleri Sorgulama)
 * Endpoint: https://extws.teb.com.tr/heshar/HesHarSrv
 * 
 * SOAP 1.1 | document/literal | Namespace: http://prjwebservice/
 * InputDataXML entity-encoded XML string olarak gönderilir
 * 
 * Gerekli banka_ApiKimlik alanları:
 * - apiKimlik_kullanici: SOAP UserName (WSHSHRGON)
 * - apiKimlik_sifre: SOAP Password
 * - apiKimlik_endpoint: https://extws.teb.com.tr/heshar/HesHarSrv
 * - apiKimlik_ekAyarlar (JSON): {ServisID, Environment, FirmaAdi, FirmaAnahtari}
 * 
 * Gerekli banka_Hesap alanları:
 * - bankaHesap_no: Hesap numarası (92363819)
 * - bankaHesap_sube_kodu: Şube kodu (40)
 * - bankaHesap_iban: IBAN (TR37...)
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 * @see admin/Banka Bilgileri/TEB/README.md
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class TebService extends BaseBankaService
{
    // SOAP Namespace
    private const NAMESPACE = 'http://prjwebservice/';
    
    // Timeout (saniye)
    private const TIMEOUT = 60;
    
    // Başarılı error code
    private const SUCCESS_CODE = '00';
    
    /**
     * Ek ayarları al (JSON parse)
     */
    private function getEkAyarlar(): array
    {
        return json_decode($this->apiKimlik['apiKimlik_ekAyarlar'] ?? '{}', true) ?: [];
    }
    
    /**
     * SOAP isteği gönder (cURL ile)
     * 
     * @param string $inputDataXml Raw XML (encode edilmemiş)
     * @return array ['success' => bool, 'errorCode' => string, 'errorMsg' => string, 'output' => string, 'httpCode' => int, 'raw' => string]
     */
    private function sendSoapRequest(string $inputDataXml): array
    {
        $endpoint = $this->apiKimlik['apiKimlik_endpoint'];
        $ekAyarlar = $this->getEkAyarlar();
        
        // InputDataXML entity encode
        $encodedInput = htmlspecialchars($inputDataXml, ENT_XML1, 'UTF-8');
        
        $soapXml = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:prj="' . self::NAMESPACE . '">
   <soapenv:Header/>
   <soapenv:Body>
      <prj:TEBWebSrv>
         <prj:UserName>' . htmlspecialchars($this->apiKimlik['apiKimlik_kullanici']) . '</prj:UserName>
         <prj:Password>' . htmlspecialchars($this->apiKimlik['apiKimlik_sifre']) . '</prj:Password>
         <prj:ServiceID>' . htmlspecialchars($ekAyarlar['ServisID'] ?? '') . '</prj:ServiceID>
         <prj:Environment>' . htmlspecialchars($ekAyarlar['Environment'] ?? 'P') . '</prj:Environment>
         <prj:InputDataXML>' . $encodedInput . '</prj:InputDataXML>
      </prj:TEBWebSrv>
   </soapenv:Body>
</soapenv:Envelope>';
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $soapXml,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
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
                'output'    => '',
                'httpCode'  => 0,
                'raw'       => ''
            ];
        }
        
        if (!$response) {
            return [
                'success'   => false,
                'errorCode' => 'EMPTY',
                'errorMsg'  => 'Boş yanıt alındı (HTTP: ' . $httpCode . ')',
                'output'    => '',
                'httpCode'  => $httpCode,
                'raw'       => ''
            ];
        }
        
        // Regex ile parse et (namespace prefix'lerden bağımsız)
        $errorCode = $errorMsg = $outputDataXML = '';
        
        if (preg_match('/<[^>]*errorCode[^>]*>([^<]*)</', $response, $m)) {
            $errorCode = trim($m[1]);
        }
        if (preg_match('/<[^>]*errorMsg[^>]*>([^<]*)</', $response, $m)) {
            $errorMsg = trim($m[1]);
        }
        if (preg_match('/<[^>]*outputDataXML[^>]*>(.*?)<\/[^>]*outputDataXML/s', $response, $m)) {
            $outputDataXML = $m[1];
        }
        
        // outputDataXML entity decode + CDATA temizle
        $outputDataXML = html_entity_decode($outputDataXML, ENT_XML1, 'UTF-8');
        $outputDataXML = str_replace(['<![CDATA[', ']]>'], '', $outputDataXML);
        $outputDataXML = trim($outputDataXML);
        
        return [
            'success'   => ($errorCode === self::SUCCESS_CODE),
            'errorCode' => $errorCode ?: '-',
            'errorMsg'  => $errorMsg ?: '',
            'output'    => $outputDataXML,
            'httpCode'  => $httpCode,
            'raw'       => $response
        ];
    }
    
    /**
     * HESHARSORGU XML'i oluştur
     * 
     * @param string $subeNo Şube kodu
     * @param string $hesapNo Hesap numarası
     * @param string $baslangicTarih dd.MM.yyyy formatında
     * @param string $bitisTarih dd.MM.yyyy formatında
     * @return string Raw XML
     */
    private function buildInputXml(string $subeNo, string $hesapNo, string $baslangicTarih, string $bitisTarih): string
    {
        $ekAyarlar = $this->getEkAyarlar();
        
        return '<HESHARSORGU>'
            . '<FIRMA_AD>' . ($ekAyarlar['FirmaAdi'] ?? '') . '</FIRMA_AD>'
            . '<FIRMA_ANAHTAR>' . ($ekAyarlar['FirmaAnahtari'] ?? '') . '</FIRMA_ANAHTAR>'
            . '<SUBENO>' . $subeNo . '</SUBENO>'
            . '<HESNO>' . $hesapNo . '</HESNO>'
            . '<BASTAR>' . $baslangicTarih . '</BASTAR>'
            . '<BITTAR>' . $bitisTarih . '</BITTAR>'
            . '<GON_IBAN_EH>E</GON_IBAN_EH>'
            . '</HESHARSORGU>';
    }
    
    /**
     * outputDataXML'i parse ederek hareket listesi döndür
     * 
     * @param string $outputXml Decode edilmiş output XML
     * @return array Hareket listesi
     */
    private function parseHareketler(string $outputXml): array
    {
        if (empty($outputXml)) {
            return [];
        }
        
        $xml = @simplexml_load_string($outputXml);
        if (!$xml) {
            // simplexml başarısız olursa DOMDocument dene
            $dom = new \DOMDocument();
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            if (!@$dom->loadXML($outputXml)) {
                return [];
            }
            $xml = simplexml_import_dom($dom);
            if (!$xml) {
                return [];
            }
        }
        
        $hareketler = [];
        
        // HAREKETLER > DETAYLAR > DETAY yapısı
        if (!isset($xml->DETAYLAR) || !isset($xml->DETAYLAR->DETAY)) {
            return [];
        }
        
        foreach ($xml->DETAYLAR->DETAY as $detay) {
            $hareketler[] = [
                'hareket_key'     => (string)($detay->HAREKET_KEY ?? ''),
                'islem_tar'       => (string)($detay->ISLEM_TAR ?? ''),
                'islem_tar_saat'  => (string)($detay->ISLEM_TAR_SAAT ?? ''),
                'ba'              => (string)($detay->BA ?? ''),
                'parakod'         => (string)($detay->PARAKOD ?? ''),
                'tutar'           => (string)($detay->TUTAR ?? '0'),
                'aciklama'        => (string)($detay->ACIKLAMA ?? ''),
                'musteri_ref'     => (string)($detay->MUSTERI_REF ?? ''),
                'gonderen_ad'     => (string)($detay->GONDEREN_AD ?? ''),
                'gonderen_banka'  => (string)($detay->GONDEREN_BANKA ?? ''),
                'gonderen_sube'   => (string)($detay->GONDEREN_SUBE ?? ''),
                'anlik_bky'       => (string)($detay->ANLIK_BKY ?? '0'),
                'islem_ack'       => (string)($detay->ISLEM_ACK ?? ''),
                'islem_tur'       => (string)($detay->ISLEM_TUR ?? ''),
                'borclu_vkn'      => (string)($detay->BORCLU_VKN ?? ''),
                'alacakli_vkn'    => (string)($detay->ALACAKLI_VKN ?? ''),
                'gonderen_iban'   => (string)($detay->GONDEREN_IBAN ?? ''),
            ];
        }
        
        return $hareketler;
    }
    
    /**
     * TEB tarih formatını (dd/MM/yyyy) + saat → Y-m-d H:i:s'e çevir
     * 
     * @param string $tarih dd/MM/yyyy formatında
     * @param string $saat HH:mm:ss formatında (opsiyonel)
     * @return string Y-m-d H:i:s
     */
    private function parseTarih(string $tarih, string $saat = ''): string
    {
        if (empty($tarih)) {
            return date('Y-m-d H:i:s');
        }
        
        $format = !empty($saat) ? 'd/m/Y H:i:s' : 'd/m/Y';
        $input = !empty($saat) ? "$tarih $saat" : $tarih;
        
        $dt = \DateTime::createFromFormat($format, $input);
        
        if (!$dt) {
            // Alternatif format dene (noktalı)
            $format2 = !empty($saat) ? 'd.m.Y H:i:s' : 'd.m.Y';
            $dt = \DateTime::createFromFormat($format2, $input);
        }
        
        return $dt ? $dt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
    
    /**
     * TEB para birimi kodunu standartlaştır (TL → TRY)
     */
    private function normalizeDovizKodu(string $paraKod): string
    {
        $map = [
            'TL'  => 'TRY',
            'YTL' => 'TRY',
            'TRL' => 'TRY',
            'USD' => 'USD',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
        ];
        
        return $map[strtoupper(trim($paraKod))] ?? strtoupper(trim($paraKod));
    }
    
    /**
     * Y-m-d formatını dd.MM.yyyy'ye çevir (TEB request formatı)
     */
    private function formatTebTarih(string $ymdTarih): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $ymdTarih);
        return $dt ? $dt->format('d.m.Y') : date('d.m.Y');
    }
    
    /**
     * TEB hareket verisini BaseBankaService.hareketKaydet formatına normalize et
     * 
     * @param array $hareket parseHareketler'den gelen ham veri
     * @return array hareketKaydet'in beklediği format
     */
    private function normalizeHareket(array $hareket): array
    {
        // Tarih + saat birleştir
        $islemTarihi = $this->parseTarih($hareket['islem_tar'], $hareket['islem_tar_saat']);
        
        // Borç/Alacak: TEB 'B' veya 'A' kullanıyor → aynı format
        $borcAlacak = strtoupper(trim($hareket['ba']));
        
        // VKN/TCKN: Borç ise borclu_vkn, Alacak ise alacakli_vkn
        $vknTckn = '';
        if ($borcAlacak === 'B' && !empty($hareket['borclu_vkn'])) {
            $vknTckn = $hareket['borclu_vkn'];
        } elseif ($borcAlacak === 'A' && !empty($hareket['alacakli_vkn'])) {
            $vknTckn = $hareket['alacakli_vkn'];
        } elseif (!empty($hareket['borclu_vkn'])) {
            $vknTckn = $hareket['borclu_vkn'];
        } elseif (!empty($hareket['alacakli_vkn'])) {
            $vknTckn = $hareket['alacakli_vkn'];
        }
        
        // Açıklama: Ana açıklama + ek açıklama birleştir
        $aciklama = trim($hareket['aciklama']);
        if (!empty($hareket['islem_ack'])) {
            $aciklama .= ' | ' . trim($hareket['islem_ack']);
        }
        
        return [
            'identifier'        => $hareket['hareket_key'],
            'islemTarihi'       => $islemTarihi,
            'tutar'             => floatval(str_replace(',', '.', $hareket['tutar'])),
            'aciklama'          => $aciklama,
            'borcAlacak'        => $borcAlacak,
            'dovizKodu'         => $this->normalizeDovizKodu($hareket['parakod']),
            'bakiye'            => floatval(str_replace(',', '.', $hareket['anlik_bky'])),
            'karsiTarafIban'    => $hareket['gonderen_iban'] ?: null,
            'karsiTarafAdUnvan' => $hareket['gonderen_ad'] ?: null,
            'vknTckn'           => $vknTckn ?: null,
            'masraf'            => 0,
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
        $logId = $this->logBaslat('testConnection', null, 'TEB API bağlantı testi');
        
        try {
            $ekAyarlar = $this->getEkAyarlar();
            
            if (empty($ekAyarlar['FirmaAdi']) || empty($ekAyarlar['FirmaAnahtari'])) {
                throw new \Exception('Ek ayarlarda FirmaAdi veya FirmaAnahtari eksik.');
            }
            
            // Geçmiş 1 günlük sorgu ile test et
            $inputXml = $this->buildInputXml(
                '40', // Test için sabit şube kodu
                '92363819', // Test için sabit hesap no
                date('d.m.Y', strtotime('-1 day')),
                date('d.m.Y')
            );
            
            $result = $this->sendSoapRequest($inputXml);
            
            if ($result['success']) {
                $this->logBasarili($logId, 0, 'TEB API bağlantısı başarılı (Error Code: ' . $result['errorCode'] . ')');
                return [
                    'success' => true,
                    'message' => 'TEB API bağlantısı başarılı!',
                    'data'    => [
                        'endpoint'  => $this->apiKimlik['apiKimlik_endpoint'],
                        'errorCode' => $result['errorCode'],
                        'errorMsg'  => $result['errorMsg']
                    ]
                ];
            }
            
            $errorDetail = 'TEB Hata Kodu: ' . $result['errorCode'] . ' - ' . $result['errorMsg'];
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
     * NOT: TEB API'sinde ayrı hesap listesi servisi yok.
     * Veritabanındaki hesaplar üzerinden çalışır.
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'TEB hesap listesi');
        
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
        
        // Gerekli alanlar kontrolü
        if (empty($hesap['bankaHesap_no']) || empty($hesap['bankaHesap_sube_kodu'])) {
            return [
                'success' => false,
                'message' => 'Hesap için gerekli bilgiler eksik (HesapNo veya SubeKodu). Hesap ayarlarını kontrol edin.',
                'data'    => [],
                'count'   => 0
            ];
        }
        
        $istekOzet = json_encode([
            'hesapId'   => $hesapId,
            'subeNo'    => $hesap['bankaHesap_sube_kodu'],
            'hesapNo'   => $hesap['bankaHesap_no'],
            'baslangic' => $baslangicTarih,
            'bitis'     => $bitisTarih
        ]);
        
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);
        
        try {
            // Tarihleri TEB formatına çevir (dd.MM.yyyy)
            $baslar = $this->formatTebTarih($baslangicTarih);
            $bitir = $this->formatTebTarih($bitisTarih);
            
            // SOAP isteği
            $inputXml = $this->buildInputXml(
                $hesap['bankaHesap_sube_kodu'],
                $hesap['bankaHesap_no'],
                $baslar,
                $bitir
            );
            
            $result = $this->sendSoapRequest($inputXml);
            
            if (!$result['success']) {
                $errorDetail = 'TEB Hata: ' . $result['errorCode'] . ' - ' . $result['errorMsg'];
                $this->logHatali($logId, $errorDetail, $result['errorCode'], $result['httpCode']);
                return [
                    'success' => false,
                    'message' => $errorDetail,
                    'data'    => [],
                    'count'   => 0
                ];
            }
            
            // Hareketleri parse et
            $hareketler = $this->parseHareketler($result['output']);
            
            $this->logBasarili($logId, count($hareketler), count($hareketler) . ' hareket alındı');
            
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
            // Son senkron tarihinden bugüne veya son 28 gün 
            // TEB API max 30 gün izin veriyor, güvenli sınır olarak 28 gün kullanıyoruz
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            $baslangic = $sonSenkron ?: date('Y-m-d', strtotime('-28 days'));
            $bitis = date('Y-m-d');
            
            // TEB max 30 gün kontrolü - senkron tarihi çok eskiyse 28 güne sınırla
            $fark = (strtotime($bitis) - strtotime($baslangic)) / 86400;
            if ($fark > 30) {
                $baslangic = date('Y-m-d', strtotime('-28 days'));
            }
            
            // Hareketleri çek
            $hareketResult = $this->getHareketler($hesapId, $baslangic, $bitis);
            
            if (!$hareketResult['success']) {
                $this->logHatali($logId, $hareketResult['message']);
                return [
                    'success'  => false,
                    'message'  => $hareketResult['message'],
                    'inserted' => 0,
                    'skipped'  => 0
                ];
            }
            
            $inserted = 0;
            $skipped = 0;
            
            foreach ($hareketResult['data'] as $hareket) {
                // Normalize et ve kaydet (BaseBankaService.hareketKaydet kullanır)
                $normalized = $this->normalizeHareket($hareket);
                
                if ($this->hareketKaydet($hesapId, $normalized)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            
            // Son hareketin bakiyesini DB'ye yaz
            if (!empty($hareketResult['data'])) {
                $sonHareket = end($hareketResult['data']);
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

            // Son senkron tarihini güncelle (API'den veri döndüyse)
            $this->sonSenkronGuncelle($hesapId, $inserted + $skipped);
            
            $mesaj = "$inserted yeni hareket eklendi, $skipped hareket zaten mevcuttu";
            $this->logBasarili($logId, $inserted, "Eklenen: $inserted, Atlanan: $skipped");
            
            return [
                'success'  => true,
                'message'  => $mesaj,
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
        $logId = $this->logBaslat('senkronizeTumHesaplar', null, 'Tüm TEB hesapları');
        
        $hesaplar = $this->getApiHesaplari();
        
        $toplamInserted = 0;
        $toplamSkipped = 0;
        $basariliHesap = 0;
        $hataliHesap = 0;
        $hatalar = [];
        
        foreach ($hesaplar as $hesap) {
            // Şube kodu ve hesap no zorunlu
            if (empty($hesap['bankaHesap_no']) || empty($hesap['bankaHesap_sube_kodu'])) {
                $hataliHesap++;
                $hatalar[] = "Hesap #{$hesap['bankaHesap_id']}: Şube kodu veya hesap no eksik";
                continue;
            }
            
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
