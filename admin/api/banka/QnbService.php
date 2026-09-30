<?php
/**
 * QNB Finansbank API Servisi
 * 
 * QNB Maestro SOAP 1.2 API ile iletişim kurar (getTransactionInfo)
 * WSDL: https://fbmaestro.finansbank.com.tr/MaestroCoreEkstre/services/OrnekAkademiService?wsdl
 * Endpoint: https://fbmaestro.finansbank.com.tr/MaestroCoreEkstre/services/OrnekAkademiService.OrnekAkademiServiceHttpSoap12Endpoint/
 * 
 * SOAP 1.2 | WSDL | Namespace: http://ornekakademiekstre.genericekstre.driver.maestro.ibtech.com
 * Tek operasyon: getTransactionInfo → hesap bilgileri + hareketler döner
 * 
 * Gerekli banka_ApiKimlik alanları:
 * - apiKimlik_kullanici: SOAP UserName (ORNEKAKADEMIWS)
 * - apiKimlik_sifre: SOAP Password
 * - apiKimlik_endpoint: SOAP Endpoint URL
 * 
 * Gerekli banka_Hesap alanları:
 * - bankaHesap_iban: IBAN (TR16...)
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class QnbService extends BaseBankaService
{
    // WSDL URL (endpoint'ten ayrı)
    private const WSDL_URL = 'https://fbmaestro.finansbank.com.tr/MaestroCoreEkstre/services/OrnekAkademiService?wsdl';
    
    // Timeout (saniye)
    private const TIMEOUT = 60;
    
    // Başarılı error code'lar
    private const SUCCESS_CODES = ['0', '', 'EHS01'];
    
    // SoapClient instance (cache)
    private ?\SoapClient $soapClient = null;
    
    /**
     * SoapClient oluştur (singleton)
     */
    private function getSoapClient(): \SoapClient
    {
        if ($this->soapClient === null) {
            $this->soapClient = new \SoapClient(self::WSDL_URL, [
                'trace'              => true,
                'exceptions'         => true,
                'cache_wsdl'         => WSDL_CACHE_BOTH,
                'soap_version'       => SOAP_1_2,
                'location'           => $this->apiKimlik['apiKimlik_endpoint'],
                'connection_timeout' => 15,
                'default_socket_timeout' => self::TIMEOUT,
                'stream_context'     => stream_context_create([
                    'ssl' => [
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                    ]
                ]),
            ]);
        }
        
        return $this->soapClient;
    }
    
    /**
     * getTransactionInfo SOAP çağrısı
     * 
     * @param string|null $iban IBAN (opsiyonel, null ise tüm hesaplar)
     * @param string|null $baslangicTarih Y-m-d formatında
     * @param string|null $bitisTarih Y-m-d formatında
     * @return array ['success' => bool, 'errorCode' => string, 'errorDescription' => string, 'data' => mixed]
     */
    private function callGetTransactionInfo(?string $iban = null, ?string $baslangicTarih = null, ?string $bitisTarih = null): array
    {
        $client = $this->getSoapClient();
        
        $params = new \stdClass();
        $params->transactionInfo = new \stdClass();
        $params->transactionInfo->password = $this->apiKimlik['apiKimlik_sifre'];
        $params->transactionInfo->userName = $this->apiKimlik['apiKimlik_kullanici'];
        
        // transactionInfoInputType (opsiyonel)
        if ($iban || $baslangicTarih || $bitisTarih) {
            $params->transactionInfo->transactionInfoInputType = new \stdClass();
            
            if ($iban) {
                $params->transactionInfo->transactionInfoInputType->iban = str_replace(' ', '', $iban);
            }
            if ($baslangicTarih) {
                $params->transactionInfo->transactionInfoInputType->startDate = $baslangicTarih . 'T00:00:00';
            }
            if ($bitisTarih) {
                $params->transactionInfo->transactionInfoInputType->endDate = $bitisTarih . 'T23:59:59';
            }
        }
        
        $result = $client->getTransactionInfo($params);
        
        if (!isset($result->return)) {
            return [
                'success'          => false,
                'errorCode'        => 'NO_RETURN',
                'errorDescription' => 'API yanıtında return alanı bulunamadı',
                'data'             => null,
                'httpCode'         => 200
            ];
        }
        
        $ret = $result->return;
        $errorCode = $ret->errorCode ?? '';
        $errorDesc = $ret->errorDescription ?? '';
        
        return [
            'success'          => in_array($errorCode, self::SUCCESS_CODES),
            'errorCode'        => $errorCode,
            'errorDescription' => $errorDesc,
            'data'             => $ret,
            'httpCode'         => 200
        ];
    }
    
    /**
     * API yanıtındaki accountInfos dizisini parse et
     * 
     * @param object $returnData API return objesi
     * @return array Hesap listesi
     */
    private function parseAccountInfos(object $returnData): array
    {
        if (!isset($returnData->accountInfoReturnType->accountInfos)) {
            return [];
        }
        
        $infos = $returnData->accountInfoReturnType->accountInfos;
        
        // Tekil sonuç ise diziye çevir
        if (!is_array($infos)) {
            $infos = [$infos];
        }
        
        return $infos;
    }
    
    /**
     * Bir hesabın transactions dizisini parse et
     * 
     * @param object $accountInfo accountInfos elemanı
     * @return array İşlem listesi
     */
    private function parseTransactions(object $accountInfo): array
    {
        if (!isset($accountInfo->transactions)) {
            return [];
        }
        
        $txns = $accountInfo->transactions;
        
        if (!is_array($txns)) {
            $txns = [$txns];
        }
        
        return $txns;
    }
    
    /**
     * QNB tarih formatını parse et
     * 
     * QNB: 2026-03-24T03:27:13.000+03:00 → Y-m-d H:i:s
     * 
     * @param string|null $dateStr
     * @return string Y-m-d H:i:s
     */
    private function parseTarih(?string $dateStr): string
    {
        if (empty($dateStr)) {
            return date('Y-m-d H:i:s');
        }
        
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $dateStr);
        
        if (!$dt) {
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i:sP', $dateStr);
        }
        
        if (!$dt) {
            // Son çare: ilk 19 karakteri al
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', substr($dateStr, 0, 19));
        }
        
        return $dt ? $dt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
    
    /**
     * QNB para birimi kodunu standartlaştır
     */
    private function normalizeDovizKodu(string $kod): string
    {
        $map = [
            'TL'  => 'TRY',
            'YTL' => 'TRY',
            'TRL' => 'TRY',
        ];
        
        $kod = strtoupper(trim($kod));
        return $map[$kod] ?? $kod;
    }
    
    /**
     * QNB hareket verisini BaseBankaService.hareketKaydet formatına normalize et
     * 
     * @param object $txn API'den gelen transaction objesi
     * @return array hareketKaydet'in beklediği format
     */
    private function normalizeHareket(object $txn): array
    {
        // Borç/Alacak: QNB 'B' (borç) veya 'A' (alacak) kullanıyor
        $borcAlacak = strtoupper(trim($txn->debitOrCreditCode ?? ''));
        
        // Açıklama
        $aciklama = trim($txn->transactionDescription ?? '');
        if (!empty($txn->vheTrxDescription)) {
            $aciklama .= ' | ' . trim($txn->vheTrxDescription);
        }
        
        // Identifier: transactionId benzersiz, yoksa muhasebeFisNo
        $identifier = $txn->transactionId ?? $txn->muhasebeFisNo ?? uniqid('QNB_');
        
        return [
            'identifier'        => (string) $identifier,
            'islemTarihi'       => $this->parseTarih($txn->transactionDate ?? null),
            'tutar'             => floatval($txn->transactionAmount ?? 0),
            'aciklama'          => $aciklama,
            'borcAlacak'        => $borcAlacak,
            'dovizKodu'         => $this->normalizeDovizKodu($txn->currencyCode ?? 'TRY'),
            'bakiye'            => floatval($txn->transactionBalance ?? 0),
            'karsiTarafIban'    => !empty($txn->opponentIBAN) ? $txn->opponentIBAN : null,
            'karsiTarafAdUnvan' => !empty($txn->opponentBank) ? trim($txn->opponentBank) : (!empty($txn->operationName) ? trim($txn->operationName) : null),
            'vknTckn'           => !empty($txn->opponentTAXNoPIDNo) ? $txn->opponentTAXNoPIDNo : null,
            'receiptNo'         => !empty($txn->muhasebeFisNo) ? $txn->muhasebeFisNo : (!empty($txn->eftInquiryNumber) ? $txn->eftInquiryNumber : null),
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
        $logId = $this->logBaslat('testConnection', null, 'QNB Finansbank API bağlantı testi');
        
        try {
            // IBAN olmadan çağır → EHS03 dönmeli (kimlik doğrulama başarılı demek)
            // Veya IBAN ile → hesap bilgileri dönmeli
            $hesaplar = $this->getApiHesaplari();
            
            if (!empty($hesaplar)) {
                // İlk hesabın IBAN'ı ile test et
                $iban = $hesaplar[0]['bankaHesap_iban'];
                $result = $this->callGetTransactionInfo(
                    $iban,
                    date('Y-m-d', strtotime('-1 day')),
                    date('Y-m-d')
                );
            } else {
                // Hesap yoksa sadece auth testi (EHS03 beklenir)
                $result = $this->callGetTransactionInfo();
            }
            
            if ($result['success']) {
                $accountInfos = $this->parseAccountInfos($result['data']);
                $mesaj = 'QNB Finansbank API bağlantısı başarılı! ' . count($accountInfos) . ' hesap bulundu.';
                $this->logBasarili($logId, count($accountInfos), $mesaj);
                
                return [
                    'success' => true,
                    'message' => $mesaj,
                    'data'    => [
                        'endpoint'   => $this->apiKimlik['apiKimlik_endpoint'],
                        'errorCode'  => $result['errorCode'],
                        'hesapSayisi' => count($accountInfos)
                    ]
                ];
            }
            
            // EHS03 = IBAN gerekli ama auth başarılı
            if ($result['errorCode'] === 'EHS03') {
                $this->logBasarili($logId, 0, 'API bağlantısı başarılı (auth OK, IBAN gerekli)');
                return [
                    'success' => true,
                    'message' => 'QNB Finansbank API bağlantısı başarılı! (Kimlik doğrulama OK)',
                    'data'    => [
                        'endpoint'  => $this->apiKimlik['apiKimlik_endpoint'],
                        'errorCode' => $result['errorCode']
                    ]
                ];
            }
            
            $errorDetail = 'QNB Hata: ' . $result['errorCode'] . ' - ' . $result['errorDescription'];
            $this->logHatali($logId, $errorDetail, $result['errorCode'], $result['httpCode']);
            
            return [
                'success' => false,
                'message' => $errorDetail,
                'data'    => null
            ];
            
        } catch (\SoapFault $sf) {
            $this->logHatali($logId, 'SoapFault: ' . $sf->getMessage(), $sf->faultcode ?? null);
            return [
                'success' => false,
                'message' => 'SOAP Hatası: ' . $sf->getMessage(),
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
     * QNB API tek operasyon: getTransactionInfo → accountInfos döner
     * DB'deki hesapların IBAN'ları ile sorgulanır
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'QNB hesap listesi');
        
        try {
            $dbHesaplar = $this->getApiHesaplari();
            
            if (empty($dbHesaplar)) {
                $this->logHatali($logId, 'Bu API kimliğine bağlı hesap bulunamadı.');
                return [
                    'success' => false,
                    'message' => 'Bu API kimliğine bağlı hesap bulunamadı. Önce hesap tanımlayın.',
                    'data'    => [],
                    'count'   => 0
                ];
            }
            
            $normalizedHesaplar = [];
            
            foreach ($dbHesaplar as $hesap) {
                $iban = str_replace(' ', '', $hesap['bankaHesap_iban'] ?? '');
                
                if (empty($iban)) {
                    continue;
                }
                
                // API'den hesap bilgisi çek
                $result = $this->callGetTransactionInfo(
                    $iban,
                    date('Y-m-d'),
                    date('Y-m-d')
                );
                
                if ($result['success']) {
                    $accountInfos = $this->parseAccountInfos($result['data']);
                    
                    foreach ($accountInfos as $info) {
                        // Hesap bilgilerini güncelle
                        $updateData = [];
                        
                        if (!empty($info->branchName) && empty($hesap['bankaHesap_sube_adi'])) {
                            $updateData[] = "bankaHesap_sube_adi = '" . addslashes($info->branchName) . "'";
                        }
                        if (!empty($info->branchCode) && empty($hesap['bankaHesap_sube_kodu'])) {
                            $updateData[] = "bankaHesap_sube_kodu = '" . addslashes($info->branchCode) . "'";
                        }
                        if (!empty($info->accountNo) && empty($hesap['bankaHesap_no'])) {
                            $updateData[] = "bankaHesap_no = '" . addslashes($info->accountNo) . "'";
                        }
                        
                        // Bakiye güncelle
                        // NOT: QNB getTransactionInfo API'si bakiye alanlarını (accountBalance,
                        // lastTrxClosingBalance, transactionBalance vb.) doldurmaz; canlı değer
                        // genelde 0 gelir. Eski "if != 0" koruması kaldırıldı: artık API ne
                        // dönerse (0 dahil) DB'ye yazılır ki sayfa canlı API değerini göstersin.
                        $bakiye = floatval($info->accountBalance ?? 0);
                        $updateData[] = "bankaHesap_bakiye = " . $bakiye;
                        $updateData[] = "bankaHesap_kullanilabilirBakiye = " . $bakiye;
                        
                        if (!empty($updateData)) {
                            $this->db->execute(
                                "UPDATE banka_Hesap SET " . implode(', ', $updateData) . ", bankaHesap_guncelleme_tarihi = GETDATE() WHERE bankaHesap_id = ?",
                                [$hesap['bankaHesap_id']]
                            );
                        }
                        
                        $normalizedHesaplar[] = [
                            'dbId'      => $hesap['bankaHesap_id'],
                            'iban'      => $info->iban ?? $iban,
                            'hesapNo'   => $info->accountNo ?? $hesap['bankaHesap_no'],
                            'subeKodu'  => $info->branchCode ?? $hesap['bankaHesap_sube_kodu'],
                            'subeAdi'   => $info->branchName ?? $hesap['bankaHesap_sube_adi'],
                            'bakiye'    => floatval($info->accountBalance ?? 0),
                            'dovizKodu' => $this->normalizeDovizKodu($info->accountCurrencyCode ?? 'TRY'),
                            'hesapTipi' => $info->accountType ?? '',
                            'unvan'     => $info->accountTitle ?? '',
                        ];
                    }
                }
            }
            
            $this->logBasarili($logId, count($normalizedHesaplar), count($normalizedHesaplar) . ' hesap listelendi');
            
            return [
                'success' => true,
                'message' => count($normalizedHesaplar) . ' hesap bulundu',
                'data'    => $normalizedHesaplar,
                'count'   => count($normalizedHesaplar)
            ];
            
        } catch (\SoapFault $sf) {
            $this->logHatali($logId, 'SoapFault: ' . $sf->getMessage(), $sf->faultcode ?? null);
            return [
                'success' => false,
                'message' => 'SOAP Hatası: ' . $sf->getMessage(),
                'data'    => [],
                'count'   => 0
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
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ? AND bankaHesap_durum = 1
        ", [$hesapId]);
        
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'data' => [], 'count' => 0];
        }
        
        $iban = str_replace(' ', '', $hesap['bankaHesap_iban'] ?? '');
        if (empty($iban)) {
            return [
                'success' => false,
                'message' => 'Hesap IBAN bilgisi eksik.',
                'data'    => [],
                'count'   => 0
            ];
        }
        
        $istekOzet = json_encode([
            'hesapId'   => $hesapId,
            'iban'      => $iban,
            'baslangic' => $baslangicTarih,
            'bitis'     => $bitisTarih
        ]);
        
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);
        
        try {
            $result = $this->callGetTransactionInfo($iban, $baslangicTarih, $bitisTarih);
            
            if (!$result['success']) {
                $errorDetail = 'QNB Hata: ' . $result['errorCode'] . ' - ' . $result['errorDescription'];
                $this->logHatali($logId, $errorDetail, $result['errorCode'], $result['httpCode']);
                return [
                    'success' => false,
                    'message' => $errorDetail,
                    'data'    => [],
                    'count'   => 0
                ];
            }
            
            $accountInfos = $this->parseAccountInfos($result['data']);
            $hareketler = [];
            
            foreach ($accountInfos as $info) {
                $txns = $this->parseTransactions($info);
                foreach ($txns as $txn) {
                    $hareketler[] = $this->normalizeHareket($txn);
                }
            }
            
            $this->logBasarili($logId, count($hareketler), count($hareketler) . ' hareket alındı');
            
            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu',
                'data'    => $hareketler,
                'count'   => count($hareketler)
            ];
            
        } catch (\SoapFault $sf) {
            $this->logHatali($logId, 'SoapFault: ' . $sf->getMessage(), $sf->faultcode ?? null);
            return [
                'success' => false,
                'message' => 'SOAP Hatası: ' . $sf->getMessage(),
                'data'    => [],
                'count'   => 0
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
            // Son senkron tarihinden bugüne veya son 30 gün
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            $baslangic = $sonSenkron ?: date('Y-m-d', strtotime('-30 days'));
            $bitis = date('Y-m-d');
            
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
                if ($this->hareketKaydet($hesapId, $hareket)) {
                    $inserted++;
                } else {
                    $skipped++;
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
            
        } catch (\SoapFault $sf) {
            $this->logHatali($logId, 'SoapFault: ' . $sf->getMessage(), $sf->faultcode ?? null);
            return [
                'success'  => false,
                'message'  => 'SOAP Hatası: ' . $sf->getMessage(),
                'inserted' => 0,
                'skipped'  => 0
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
        $logId = $this->logBaslat('senkronizeTumHesaplar', null, 'Tüm QNB hesapları');
        
        $hesaplar = $this->getApiHesaplari();
        
        $toplamInserted = 0;
        $toplamSkipped = 0;
        $basariliHesap = 0;
        $hataliHesap = 0;
        $hatalar = [];
        
        foreach ($hesaplar as $hesap) {
            $iban = str_replace(' ', '', $hesap['bankaHesap_iban'] ?? '');
            if (empty($iban)) {
                $hataliHesap++;
                $hatalar[] = "Hesap #{$hesap['bankaHesap_id']}: IBAN bilgisi eksik";
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
            'success'        => $hataliHesap == 0,
            'message'        => $mesaj,
            'inserted'       => $toplamInserted,
            'skipped'        => $toplamSkipped,
            'hesap_basarili' => $basariliHesap,
            'hesap_hatali'   => $hataliHesap,
            'hatalar'        => $hatalar,
            'summary'        => [
                'basarili'        => $basariliHesap,
                'hatali'          => $hataliHesap,
                'toplam_inserted' => $toplamInserted,
                'toplam_skipped'  => $toplamSkipped
            ]
        ];
    }
}
