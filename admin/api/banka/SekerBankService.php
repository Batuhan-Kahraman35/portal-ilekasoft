<?php
/**
 * Şekerbank API Servisi
 *
 * Şekerbank Nakit Yönetimi SOAP API ile iletişim kurar
 * Endpoint: https://nakityonetimi.sekerbank.com.tr/SekerbankNakitYonetimiWebservisleri/CashManagement.asmx
 * WSDL:     aynı adres + ?wsdl
 *
 * SOAP 1.1 | basicHttpBinding | Transport (HTTPS)
 * Auth: SOAP body içinde UserName + Password parametresi
 * Hareket metodu: GetTransactionsByTransactionDate
 *
 * Gerekli banka_ApiKimlik alanları:
 * - apiKimlik_kullanici: Kullanıcı adı (36822126)
 * - apiKimlik_sifre:     Şifre
 * - apiKimlik_endpoint:  https://nakityonetimi.sekerbank.com.tr/...CashManagement.asmx
 * - apiKimlik_wsdl:      aynı endpoint + ?wsdl
 *
 * Gerekli banka_Hesap alanları:
 * - bankaHesap_iban: IBAN (TR...)
 * - bankaHesap_no:   Hesap numarası (opsiyonel, IBAN öncelikli)
 *
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class SekerBankService extends BaseBankaService
{
    private ?\SoapClient $soapClient = null;

    private const TIMEOUT = 60;


    /**
     * SoapClient oluştur / önbellekten döndür
     */
    private function getSoapClient(): \SoapClient
    {
        if ($this->soapClient !== null) {
            return $this->soapClient;
        }

        $wsdl = $this->apiKimlik['apiKimlik_wsdl']
             ?? ($this->apiKimlik['apiKimlik_endpoint'] . '?wsdl');

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
            'http' => ['timeout' => self::TIMEOUT],
        ]);

        $this->soapClient = new \SoapClient($wsdl, [
            'trace'              => true,
            'exceptions'         => true,
            'cache_wsdl'         => WSDL_CACHE_NONE,
            'stream_context'     => $context,
            'connection_timeout' => 15,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);

        return $this->soapClient;
    }

    /**
     * Y-m-d → yyyy-MM-dd (Şekerbank request formatı)
     */
    private function formatSekerbankTarih(string $ymdTarih): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $ymdTarih);
        return $dt ? $dt->format('Y-m-d') : date('Y-m-d');
    }

    /**
     * dd/MM/yyyy [HH:mm:ss] → Y-m-d H:i:s
     */
    private function parseTarih(string $tarih, string $saat = ''): string
    {
        if (empty($tarih)) {
            return date('Y-m-d H:i:s');
        }

        $input  = trim($tarih . (!empty($saat) ? ' ' . trim($saat) : ''));
        $format = !empty($saat) ? 'd/m/Y H:i:s' : 'd/m/Y';

        $dt = \DateTime::createFromFormat($format, $input)
           ?: \DateTime::createFromFormat('Y-m-d', $tarih);

        return $dt ? $dt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }

    /**
     * Para birimi normalize (TL → TRY)
     */
    private function normalizeDovizKodu(string $kod): string
    {
        $map = ['TL' => 'TRY', 'YTL' => 'TRY', 'TRL' => 'TRY'];
        $kod = strtoupper(trim($kod));
        return $map[$kod] ?? $kod;
    }

    /**
     * SOAP yanıtındaki `any` XML string'ini hareket dizisine dönüştür.
     *
     * Gerçek yanıt yapısı (WSDL'den doğrulandı):
     *   ArrayOfAccount > Account > HesapHareketleri (0..n)
     *     HareketTarihi, HareketSaati, SiraNo, HareketTutari, BorcAlacak,
     *     DovizKodu, KapanisBakiye, Aciklama, GonderenAdSoyad,
     *     KarsiHesapVKNTCKN, ReferansNo, IslemID, IslemKodu, ...
     *
     * @param mixed $response SoapClient yanıtı (stdClass)
     * @return array
     */
    private function parseHareketler($response): array
    {
        if (empty($response)) {
            return [];
        }

        // `any` alanı XML string olarak gelir
        $xmlStr = $response->GetTransactionsByTransactionDateResult->any ?? null;

        if (empty($xmlStr) || !is_string($xmlStr)) {
            return [];
        }

        // Hata kodu mu? (örn: "52 - BaslangicTarihiFormatHatali")
        if (preg_match('/^\d+\s*-\s*/u', trim($xmlStr))) {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlStr);
        libxml_clear_errors();

        if (!$xml) {
            return [];
        }

        $hareketler = [];

        // Her Account için HesapHareketleri listesi
        foreach ($xml->Account as $account) {
            $hesapDoviz = $this->normalizeDovizKodu((string)($account->DovizKodu ?? 'TRY'));

            foreach ($account->HesapHareketleri as $h) {
                $hareketTarihi = (string)($h->HareketTarihi ?? '');
                $hareketSaati  = (string)($h->HareketSaati ?? '');

                // Boş hareket satırını atla (günde hareket olmadığında SiraNo=0 gelir)
                if (empty($hareketTarihi) || (string)($h->SiraNo ?? '0') === '0') {
                    continue;
                }

                $tutar = floatval(str_replace(',', '.', (string)($h->HareketTutari ?? '0')));

                // Identifier: IslemID > ReferansNo > DekontNo > tarih+sira
                $identifier = trim((string)($h->IslemID ?? ''))
                           ?: trim((string)($h->ReferansNo ?? ''))
                           ?: trim((string)($h->DekontNo ?? ''))
                           ?: $hareketTarihi . '_' . (string)($h->SiraNo ?? '0');

                $hareketler[] = [
                    'identifier'        => $identifier,
                    'islemTarihi'       => $this->parseTarih($hareketTarihi, $hareketSaati),
                    'tutar'             => $tutar,
                    'aciklama'          => trim((string)($h->Aciklama ?? '')),
                    'borcAlacak'        => strtoupper(trim((string)($h->BorcAlacak ?? 'A'))),
                    'dovizKodu'         => $this->normalizeDovizKodu((string)($h->DovizKodu ?? $hesapDoviz)),
                    'bakiye'            => floatval(str_replace(',', '.', (string)($h->KapanisBakiye ?? '0'))),
                    'karsiTarafIban'    => null,
                    'karsiTarafAdUnvan' => trim((string)($h->GonderenAdSoyad ?? '')) ?: null,
                    'vknTckn'           => trim((string)($h->KarsiHesapVKNTCKN ?? '')) ?: null,
                    'masraf'            => 0,
                ];
            }
        }

        return $hareketler;
    }

    /**
     * Account header'dan bakiye bilgisini çıkar.
     * Her günlük yanıtta hareket olmasa bile döner.
     *
     * @return array|null ['bakiye', 'kullanilabilir', 'bloke'] veya null
     */
    private function parseHesapBakiye($response): ?array
    {
        $xmlStr = $response->GetTransactionsByTransactionDateResult->any ?? null;

        if (empty($xmlStr) || !is_string($xmlStr)) {
            return null;
        }

        if (preg_match('/^\d+\s*-\s*/u', trim($xmlStr))) {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlStr);
        libxml_clear_errors();

        if (!$xml || !isset($xml->Account)) {
            return null;
        }

        $account = $xml->Account;

        return [
            'bakiye'        => floatval(str_replace(',', '.', (string)($account->Bakiye ?? '0'))),
            'kullanilabilir' => floatval(str_replace(',', '.', (string)($account->KullanilabilirBakiye ?? '0'))),
            'bloke'         => floatval(str_replace(',', '.', (string)($account->BlokeTutari ?? '0'))),
        ];
    }

    /**
     * Tek gün için API isteği at — hareketleri ve bakiyeyi döndür
     *
     * @return array ['hareketler' => [], 'bakiye' => [...]]
     */
    private function getGunlukVeri(array $hesap, string $gun): array
    {
        $client = $this->getSoapClient();

        $response = $client->GetTransactionsByTransactionDate([
            'userName'   => $this->apiKimlik['apiKimlik_kullanici'],
            'password'   => $this->apiKimlik['apiKimlik_sifre'],
            'customerNo' => $hesap['bankaHesap_musteriNo'] ?? $this->apiKimlik['apiKimlik_kullanici'],
            'accountNo'  => $hesap['bankaHesap_no'] ?? '',
            'startDate'  => $this->formatSekerbankTarih($gun),
            'endDate'    => $this->formatSekerbankTarih($gun),
        ]);

        return [
            'hareketler' => $this->parseHareketler($response),
            'bakiye'     => $this->parseHesapBakiye($response),
        ];
    }

    /**
     * Tek gün için API isteği at (geriye dönük uyumluluk)
     */
    private function getHareketlerGunluk(array $hesap, string $gun): array
    {
        return $this->getGunlukVeri($hesap, $gun)['hareketler'];
    }

    // ========================================================================
    // BankaServiceInterface Implementasyonu
    // ========================================================================

    public function testConnection(): array
    {
        $logId = $this->logBaslat('testConnection', null, 'Şekerbank WSDL bağlantı testi');

        try {
            $client = $this->getSoapClient();
            $functions = $client->__getFunctions();

            $this->logBasarili($logId, 0, 'WSDL bağlantısı başarılı. ' . count($functions) . ' method.');

            return [
                'success' => true,
                'message' => 'Şekerbank WSDL bağlantısı başarılı! ' . count($functions) . ' method bulundu.',
                'data'    => [
                    'endpoint'  => $this->apiKimlik['apiKimlik_endpoint'],
                    'functions' => $functions,
                ],
            ];
        } catch (\SoapFault $e) {
            $this->logHatali($logId, 'SoapFault: ' . $e->getMessage());
            return ['success' => false, 'message' => 'SOAP Hatası: ' . $e->getMessage(), 'data' => null];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => null];
        }
    }

    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'Şekerbank hesap listesi (DB)');

        try {
            $hesaplar = $this->getApiHesaplari();

            $normalized = array_map(fn($h) => [
                'dbId'    => $h['bankaHesap_id'],
                'iban'    => $h['bankaHesap_iban'],
                'hesapNo' => $h['bankaHesap_no'],
            ], $hesaplar);

            $this->logBasarili($logId, count($normalized), count($normalized) . ' hesap listelendi');

            return [
                'success' => true,
                'message' => count($normalized) . ' hesap bulundu',
                'data'    => $normalized,
                'count'   => count($normalized),
            ];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }

    public function getHareketler(int $hesapId, string $baslangicTarih, string $bitisTarih): array
    {
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ? AND bankaHesap_durum = 1
        ", [$hesapId]);

        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'data' => [], 'count' => 0];
        }

        $logId = $this->logBaslat('getHareketler', $hesapId, json_encode([
            'hesapNo'   => $hesap['bankaHesap_no'],
            'baslangic' => $baslangicTarih,
            'bitis'     => $bitisTarih,
        ]));

        try {
            // Şekerbank max 1 gün/istek — her gün ayrı çağrı
            $hareketler = [];
            $current    = new \DateTime($baslangicTarih);
            $son        = new \DateTime($bitisTarih);

            while ($current <= $son) {
                $gun          = $current->format('Y-m-d');
                $gunHareketler = $this->getHareketlerGunluk($hesap, $gun);
                $hareketler   = array_merge($hareketler, $gunHareketler);
                $current->modify('+1 day');
            }

            $this->logBasarili($logId, count($hareketler), count($hareketler) . ' hareket alındı');

            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu',
                'data'    => $hareketler,
                'count'   => count($hareketler),
            ];
        } catch (\SoapFault $e) {
            $msg = 'SOAP Hatası: ' . $e->getMessage();
            $this->logHatali($logId, $msg);
            return ['success' => false, 'message' => $msg, 'data' => [], 'count' => 0];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }

    public function senkronize(int $hesapId): array
    {
        $hesap = $this->db->fetchOne("
            SELECT * FROM banka_Hesap WHERE bankaHesap_id = ? AND bankaHesap_durum = 1
        ", [$hesapId]);

        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'inserted' => 0, 'skipped' => 0];
        }

        $logId = $this->logBaslat('senkronize', $hesapId, json_encode(['iban' => $hesap['bankaHesap_iban']]));

        try {
            // Son senkrondan bugüne, max 30 gün (1 gün/istek → 30 API çağrısı)
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            $baslangic  = $sonSenkron ?: date('Y-m-d', strtotime('-30 days'));
            $bitis      = date('Y-m-d');

            $fark = (strtotime($bitis) - strtotime($baslangic)) / 86400;
            if ($fark > 30) {
                $baslangic = date('Y-m-d', strtotime('-30 days'));
            }

            $hareketResult = $this->getHareketler($hesapId, $baslangic, $bitis);

            if (!$hareketResult['success']) {
                $this->logHatali($logId, $hareketResult['message']);
                return ['success' => false, 'message' => $hareketResult['message'], 'inserted' => 0, 'skipped' => 0];
            }

            // Güncel bakiyeyi bugünkü yanıttan al (hareket olmasa bile)
            $bugun      = date('Y-m-d');
            $bugunVeri  = $this->getGunlukVeri($hesap, $bugun);
            $guncelBakiye = $bugunVeri['bakiye'];

            if ($guncelBakiye !== null) {
                $this->db->execute("
                    UPDATE banka_Hesap SET
                        bankaHesap_bakiye              = ?,
                        bankaHesap_kullanilabilirBakiye = ?,
                        bankaHesap_bloke               = ?,
                        bankaHesap_guncelleme_tarihi   = GETDATE()
                    WHERE bankaHesap_id = ?
                ", [
                    $guncelBakiye['bakiye'],
                    $guncelBakiye['kullanilabilir'],
                    $guncelBakiye['bloke'],
                    $hesapId,
                ]);
            }

            $inserted = $skipped = 0;

            foreach ($hareketResult['data'] as $hareket) {
                if ($this->hareketKaydet($hesapId, $hareket)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }

            // Bakiyeyi son hareketten güncelle
            if (!empty($hareketResult['data'])) {
                $sonHareket = end($hareketResult['data']);
                if (($sonHareket['bakiye'] ?? null) !== null && $sonHareket['bakiye'] > 0) {
                    $this->db->execute("
                        UPDATE banka_Hesap SET
                            bankaHesap_bakiye = ?,
                            bankaHesap_kullanilabilirBakiye = ?,
                            bankaHesap_guncelleme_tarihi = GETDATE()
                        WHERE bankaHesap_id = ?
                    ", [$sonHareket['bakiye'], $sonHareket['bakiye'], $hesapId]);
                }
            }

            $this->sonSenkronGuncelle($hesapId, $inserted + $skipped);

            $mesaj = "$inserted yeni hareket eklendi, $skipped hareket zaten mevcuttu";
            $this->logBasarili($logId, $inserted, "Eklenen: $inserted, Atlanan: $skipped");

            return ['success' => true, 'message' => $mesaj, 'inserted' => $inserted, 'skipped' => $skipped];

        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Hata: ' . $e->getMessage(), 'inserted' => 0, 'skipped' => 0];
        }
    }

    public function senkronizeTumu(): array
    {
        $logId = $this->logBaslat('senkronizeTumHesaplar', null, 'Tüm Şekerbank hesapları');

        $hesaplar = $this->getApiHesaplari();
        $toplamInserted = $toplamSkipped = $basarili = $hatali = 0;
        $hatalar = [];

        foreach ($hesaplar as $hesap) {
            $result = $this->senkronize($hesap['bankaHesap_id']);

            if ($result['success']) {
                $basarili++;
                $toplamInserted += $result['inserted'];
                $toplamSkipped  += $result['skipped'];
            } else {
                $hatali++;
                $hatalar[] = "Hesap #{$hesap['bankaHesap_id']}: " . $result['message'];
            }
        }

        $mesaj = "$basarili hesap senkronize edildi. Toplam $toplamInserted yeni kayıt.";
        if ($hatali > 0) {
            $mesaj .= " $hatali hesap hatalı.";
        }

        $this->logBasarili($logId, $toplamInserted, $mesaj);

        return [
            'success'        => $hatali === 0,
            'message'        => $mesaj,
            'inserted'       => $toplamInserted,
            'skipped'        => $toplamSkipped,
            'hesap_basarili' => $basarili,
            'hesap_hatali'   => $hatali,
            'hatalar'        => $hatalar,
            'summary'        => [
                'basarili'        => $basarili,
                'hatali'          => $hatali,
                'toplam_inserted' => $toplamInserted,
                'toplam_skipped'  => $toplamSkipped,
            ],
        ];
    }
}
