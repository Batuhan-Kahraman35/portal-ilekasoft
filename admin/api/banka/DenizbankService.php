<?php
/**
 * Denizbank API Servisi (GetCorporateAccountTransactionList)
 *
 * REST/JSON, her istek gövdesi imzalanır (NONREPUD):
 *   gövde -> SHA256 -> base64 -> RS256 JWS -> "Signature" HTTP header
 * UAT: https://ssgtest.denizbank.com:9443/apiuatv2/{ApiName}
 *
 * Kimlik eşlemesi (banka_ApiKimlik):
 *   apiKimlik_endpoint  : temel URL (sonunda /{ApiName} olmadan)
 *   apiKimlik_kullanici : Channel (UAT: NONREPUD_TEST)
 *   apiKimlik_sifre     : AppKey
 *   apiKimlik_kurumKod  : Müşteri no (canlıda AppKey müşteri no bazlı verilir)
 *   apiKimlik_ekAyarlar : { "private_key": "-----BEGIN PRIVATE KEY-----..." }
 *
 * Hesap sorgusu için banka_Hesap'ta şube kodu (bankaHesap_sube_kodu) ve
 * ek no (bankaHesap_ekNo) zorunlu; müşteri no hesapta yoksa kimlikten alınır.
 *
 * Kısıt (banka dokümanı): tarih aralığı en fazla 30 gün.
 *
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class DenizbankService extends BaseBankaService
{
    private const METOT_HAREKET = 'GetCorporateAccountTransactionList';
    private const MAKS_GUN = 30;

    /** ChannelSessionId: akışın kimliği, instance boyunca sabit */
    private ?string $oturumId = null;

    /** ekAyarlar JSON'unu diziye çöz */
    private function cfg(): array
    {
        $c = json_decode((string)($this->apiKimlik['apiKimlik_ekAyarlar'] ?? ''), true);
        return is_array($c) ? $c : [];
    }

    private function guid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)));
    }

    private function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /**
     * İstek gövdesini imzala: SHA256(gövde) base64'ü, RS256 JWS payload'ı olarak imzalanır
     */
    private function imzala(string $govde): string
    {
        $pem = (string)($this->cfg()['private_key'] ?? '');
        $key = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        if (!$key) {
            throw new \Exception('Denizbank private key okunamadı (ekAyarlar.private_key).');
        }

        $baslik  = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64url(base64_encode(hash('sha256', $govde, true)));

        if (!openssl_sign("$baslik.$payload", $imza, $key, OPENSSL_ALGO_SHA256)) {
            throw new \Exception('Denizbank istek imzalanamadı: ' . openssl_error_string());
        }

        return "$baslik.$payload." . $this->b64url($imza);
    }

    /**
     * İmzalı istek gönder, hata varsa anlamlı exception fırlat
     *
     * @return array Yanıtın Data bölümü
     */
    private function istek(string $metot, array $parametreler): array
    {
        $url     = rtrim((string)($this->apiKimlik['apiKimlik_endpoint'] ?? ''), '/');
        $channel = (string)($this->apiKimlik['apiKimlik_kullanici'] ?? '');
        $appKey  = (string)($this->apiKimlik['apiKimlik_sifre'] ?? '');

        if ($url === '' || $channel === '' || $appKey === '') {
            throw new \Exception('Denizbank kimlik bilgileri eksik (endpoint / channel / AppKey).');
        }

        $this->oturumId ??= $this->guid();

        $govde = json_encode([
            'Header' => [
                'AppKey'           => $appKey,
                'Channel'          => $channel,
                'ChannelSessionId' => $this->oturumId,
                'ChannelRequestId' => $this->guid(),
                'RequestDate'      => (new \DateTime())->format('Y-m-d\TH:i:s.vP'),
            ],
            'Parameters' => [$parametreler],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init("$url/$metot");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $govde,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Signature: ' . $this->imzala($govde),
            ],
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \Exception("Denizbank bağlantı hatası: $err");
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new \Exception("Denizbank yanıtı çözümlenemedi (HTTP $http): " . substr((string)$resp, 0, 200));
        }

        if (!empty($data['Error'])) {
            $kod = $data['Error']['Code'] ?? '?';
            $aciklama = $data['Error']['Description'] ?? 'Bilinmeyen hata';
            throw new DenizbankHata("Denizbank hata (kod $kod): $aciklama", (string)$kod, $http);
        }

        return $data['Data'] ?? [];
    }

    /**
     * Hesabın sorgu parametrelerini (müşteri no, şube, ek no) hazırla
     */
    private function hesapParametreleri(array $hesap): array
    {
        $musteriNo = trim((string)($hesap['bankaHesap_musteriNo'] ?? ''))
            ?: trim((string)($this->apiKimlik['apiKimlik_kurumKod'] ?? ''));
        $sube = trim((string)($hesap['bankaHesap_sube_kodu'] ?? ''));
        $ekNo = trim((string)($hesap['bankaHesap_ekNo'] ?? ''));

        if ($musteriNo === '' || $sube === '' || $ekNo === '') {
            throw new \Exception('Hesabın müşteri no / şube kodu / ek no bilgisi eksik.');
        }

        // Doküman IBAN'ı opsiyonel gösterse de UAT boş IBAN'da 201001 (Hesap bulunamadı) döner
        $iban = strtoupper(str_replace(' ', '', (string)($hesap['bankaHesap_iban'] ?? '')));

        return [
            'CustomerNo'        => $musteriNo,
            'AccountBranchCode' => (int)$sube,
            'AccountSuffix'     => (int)$ekNo,
            'AssociationCode'   => ' ',
            'IBANNumber'        => $iban !== '' ? $iban : ' ',
        ];
    }

    /**
     * Tek parça hareket sorgusu (en fazla 30 gün)
     *
     * @return array Yanıttaki Accounts listesi
     */
    private function hareketSorgula(array $hesapParam, int $basTs, int $bitTs): array
    {
        $data = $this->istek(self::METOT_HAREKET, $hesapParam + [
            'QueryDate'    => date('Y-m-d\TH:i:s.000P', $basTs),
            'EndDate'      => date('Y-m-d\TH:i:s.000P', $bitTs),
            'RecordStatus' => 'A',
        ]);

        return $data['Accounts'] ?? [];
    }

    /**
     * Y-m-d aralığını 30 günlük parçalara böl
     *
     * @return array<array{0:int,1:int}> [ [başlangıç ts, bitiş ts], ... ]
     */
    private function tarihAraliklari(string $baslangic, string $bitis): array
    {
        $start = strtotime($baslangic . ' 00:00:00');
        $end   = strtotime($bitis . ' 23:59:59');

        $araliklar = [];
        $cur = $start;
        while ($cur <= $end) {
            $parcaBitis = min(strtotime('+' . (self::MAKS_GUN - 1) . ' days 23:59:59', $cur), $end);
            $araliklar[] = [$cur, $parcaBitis];
            $cur = $parcaBitis + 1;
        }

        return $araliklar;
    }

    /** "2021-10-01T14:23:15" -> "2021-10-01 14:23:15" */
    private function parseTarih(?string $t): string
    {
        $ts = $t ? strtotime($t) : false;
        return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }

    /** Alan değerinden ilk IBAN'ı çıkar (boşluk/"@@@" birleşik gelebilir); kendi IBAN'ımız hariç */
    private function ibanBul(?string $deger, string $kendiIban): ?string
    {
        if (preg_match_all('/TR\d{24}/', strtoupper(str_replace(' ', '', (string)$deger)), $m)) {
            foreach ($m[0] as $iban) {
                if ($iban !== $kendiIban) {
                    return $iban;
                }
            }
        }
        return null;
    }

    private function ibanMi(?string $deger): bool
    {
        return (bool)preg_match('/^TR\d{24}$/', strtoupper(str_replace(' ', '', (string)$deger)));
    }

    /** "@@@" ile birleşik alanda ilk dolu parçayı döndür, boşsa null */
    private function temiz(?string $deger): ?string
    {
        foreach (explode('@@@', (string)$deger) as $parca) {
            if (($p = trim($parca)) !== '') {
                return $p;
            }
        }
        return null;
    }

    /**
     * Karşı taraf IBAN / unvan / VKN-TCKN bilgisini işlem yönüne göre çöz.
     *
     * Doküman (TransactionDetail tablosu) - Virman / EFT / Havale / FAST:
     *   Detail1: Gönderen TCKN/VKN      Detail2: Alıcı IBAN @@@ Alıcı hesap
     *   Detail3: Muhasebe açıklaması    Detail4: Gönderen adı   Detail5: Alıcı adı
     * DestinationAccountIdendityNumber: "Gönderen@@@Gönderen@@@Alıcı" (EFT)
     * DestinationAccountTitle: doküman unvan der, UAT'ta karşı IBAN döndüğü görüldü.
     * Diğer işlem tiplerinde (çek, vergi, kurum) Detail alanları başka anlam taşır, kullanılmaz.
     *
     * @return array{iban:?string, unvan:?string, vkn:?string}
     */
    private function karsiTaraf(array $t, string $borcAlacak, string $kendiIban): array
    {
        $transfer = in_array(strtoupper(trim((string)($t['TransactionCode'] ?? ''))), ['TRF', 'VRM', 'EFT', 'FST'], true);
        $baslik   = $this->temiz($t['DestinationAccountTitle'] ?? null);
        $baslikIban = $this->ibanMi($baslik);
        $kimlik   = array_values(array_filter(array_map('trim', explode('@@@', (string)($t['DestinationAccountIdendityNumber'] ?? ''))), 'strlen'));

        if ($borcAlacak === 'A') {
            // Gelen: karşı taraf gönderen
            $iban  = ($baslikIban ? $this->ibanBul($baslik, $kendiIban) : null)
                ?? ($transfer ? $this->ibanBul(($t['TransactionDetail2'] ?? '') . ' ' . ($t['TransactionDetail1'] ?? ''), $kendiIban) : null)
                ?? $this->ibanBul($t['DestinationAccount'] ?? null, $kendiIban);
            $unvan = $transfer ? $this->temiz($t['TransactionDetail4'] ?? null) : null;
            $vkn   = ($transfer ? $this->temiz($t['TransactionDetail1'] ?? null) : null) ?? ($kimlik[0] ?? null);
        } else {
            // Giden: karşı taraf alıcı
            // FAST'te alıcı IBAN Detail1'de ("0-0-0@@@TR..") gelebiliyor
            $iban  = ($transfer ? $this->ibanBul(explode('@@@', (string)($t['TransactionDetail2'] ?? ''))[0], $kendiIban) : null)
                ?? ($transfer ? $this->ibanBul($t['TransactionDetail1'] ?? null, $kendiIban) : null)
                ?? ($baslikIban ? $this->ibanBul($baslik, $kendiIban) : null)
                ?? $this->ibanBul($t['DestinationAccount'] ?? null, $kendiIban);
            $unvan = $transfer ? $this->temiz($t['TransactionDetail5'] ?? null) : null;
            $vkn   = $kimlik ? end($kimlik) : null;
        }

        // Unvan alanı dolu ve IBAN değilse dokümandaki anlamıyla (karşı hesap unvanı) önceliklidir
        if ($baslik !== null && !$baslikIban) {
            $unvan = $baslik;
        }

        // VKN/TCKN yalnız rakamsa kabul
        if ($vkn !== null && !preg_match('/^\d{10,11}$/', $vkn)) {
            $vkn = null;
        }

        return ['iban' => $iban, 'unvan' => $unvan, 'vkn' => $vkn];
    }

    /** OnlineAccountTransactionDetail -> banka_HesapHareketleri normalize formatı */
    private function normalizeHareket(array $t, array $hesapBilgisi): array
    {
        // SerialNumber: muhasebe tekil numarası (doküman). Yoksa dekont no + sıra.
        $seri = trim((string)($t['SerialNumber'] ?? ''));
        if ($seri === '') {
            $seri = ($t['ReceiptNumber'] ?? '') . '-' . ($t['OrderNumber'] ?? '') . '-' . ($t['Date'] ?? '');
        }

        $tutar = floatval($t['Amount'] ?? 0);
        $tip = strtoupper(trim((string)($t['DebitCreditType'] ?? '')));
        $borcAlacak = in_array($tip, ['A', 'B'], true) ? $tip : ($tutar < 0 ? 'B' : 'A');

        $aciklama = trim((string)($t['BankDescription'] ?? ''))
            ?: trim((string)($t['TransactionName'] ?? ''));

        $kendiIban = strtoupper(str_replace(' ', '', (string)($hesapBilgisi['IBANNumber'] ?? '')));
        $karsi = $this->karsiTaraf($t, $borcAlacak, $kendiIban);

        $doviz = trim((string)($hesapBilgisi['AccountCurrencyCode'] ?? 'TRY'));

        return [
            'identifier'        => 'DNZ-' . $seri,
            'islemTarihi'       => $this->parseTarih($t['Date'] ?? null),
            'tutar'             => abs($tutar),
            'borcAlacak'        => $borcAlacak,
            'aciklama'          => $aciklama,
            'karsiTarafIban'    => $karsi['iban'],
            'karsiTarafAdUnvan' => $karsi['unvan'],
            'vknTckn'           => $karsi['vkn'],
            'bakiye'            => floatval($t['BalanceAfterTransaction'] ?? 0),
            'dovizKodu'         => in_array($doviz, ['', 'TL'], true) ? 'TRY' : $doviz,
            'receiptNo'         => $t['ReceiptNumber'] ?? null,
            'masraf'            => 0,
        ];
    }

    /**
     * Yanıttaki hesap bilgisinden bakiye/şube alanlarını güncelle
     */
    private function hesapBilgisiYaz(int $hesapId, array $a): void
    {
        $this->db->execute("
            UPDATE banka_Hesap SET
                bankaHesap_bakiye = ?,
                bankaHesap_bloke = ?,
                bankaHesap_kullanilabilirBakiye = ?,
                bankaHesap_sube_adi = COALESCE(NULLIF(?, ''), bankaHesap_sube_adi),
                bankaHesap_guncelleme_tarihi = GETDATE()
            WHERE bankaHesap_id = ?
        ", [
            floatval($a['AmountOfBalance'] ?? 0),
            floatval($a['BlockageAmount'] ?? 0),
            floatval($a['AvailableBalance'] ?? 0),
            trim((string)($a['AccountBranchName'] ?? '')),
            $hesapId,
        ]);
    }

    /**
     * API bağlantı testi (ilk hesabın son 1 günü)
     */
    public function testConnection(): array
    {
        $logId = $this->logBaslat('testConnection', null, 'Denizbank bağlantı testi');

        try {
            $hesaplar = $this->getApiHesaplari();
            if (!$hesaplar) {
                throw new \Exception('Bu API kimliğine bağlı aktif hesap yok.');
            }

            $accounts = $this->hareketSorgula(
                $this->hesapParametreleri($hesaplar[0]),
                strtotime('-1 day'),
                time()
            );

            $this->logBasarili($logId, count($accounts), 'Bağlantı başarılı.');

            return [
                'success' => true,
                'message' => 'Bağlantı başarılı! İmzalı istek kabul edildi.',
                'data'    => ['hesap_sayisi' => count($accounts)],
            ];
        } catch (DenizbankHata $e) {
            $this->logHatali($logId, $e->getMessage(), $e->bankaKodu, $e->http);
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Tanımlı hesapların bakiye/şube bilgilerini bankadan güncelle.
     * Denizbank hesap listesi servisi (GetAccountList) bu entegrasyonda yetkili değil;
     * hesaplar banka_Hesap'ta (şube + ek no ile) tanımlı olmalı.
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'Denizbank hesap bilgileri güncelleniyor');

        try {
            $sonuc = [];
            foreach ($this->getApiHesaplari() as $hesap) {
                $accounts = $this->hareketSorgula($this->hesapParametreleri($hesap), strtotime('-1 day'), time());
                foreach ($accounts as $a) {
                    $this->hesapBilgisiYaz((int)$hesap['bankaHesap_id'], $a);
                    $sonuc[] = [
                        'dbId'       => (int)$hesap['bankaHesap_id'],
                        'iban'       => str_replace(' ', '', (string)($a['IBANNumber'] ?? $hesap['bankaHesap_iban'])),
                        'subeKod'    => (string)($a['AccountBranchCode'] ?? ''),
                        'aciklama'   => trim((string)($a['AccountBranchName'] ?? '')),
                        'ekNo'       => (string)($a['AccountSuffix'] ?? ''),
                        'bakiye'     => floatval($a['AmountOfBalance'] ?? 0),
                        'paraBirimi' => (string)($a['AccountCurrencyCode'] ?? 'TRY'),
                    ];
                }
            }

            $this->logBasarili($logId, count($sonuc), json_encode(['hesap_sayisi' => count($sonuc)]));

            return [
                'success' => true,
                'message' => count($sonuc) . ' hesap güncellendi.',
                'data'    => $sonuc,
                'count'   => count($sonuc),
            ];
        } catch (DenizbankHata $e) {
            $this->logHatali($logId, $e->getMessage(), $e->bankaKodu, $e->http);
            return ['success' => false, 'message' => $e->getMessage(), 'data' => [], 'count' => 0];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }

    /**
     * Belirli bir hesabın hareketlerini çek (30 günlük parçalar halinde)
     */
    public function getHareketler(int $hesapId, string $baslangicTarih, string $bitisTarih): array
    {
        $hesap = $this->db->fetchOne("SELECT * FROM banka_Hesap WHERE bankaHesap_id = ?", [$hesapId]);
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'data' => [], 'count' => 0];
        }

        $istekOzet = json_encode(['hesapId' => $hesapId, 'baslangic' => $baslangicTarih, 'bitis' => $bitisTarih]);
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);

        try {
            $param = $this->hesapParametreleri($hesap);
            $hareketler = [];
            $sonHesapBilgisi = null;

            foreach ($this->tarihAraliklari($baslangicTarih, $bitisTarih) as [$bas, $bit]) {
                foreach ($this->hareketSorgula($param, $bas, $bit) as $a) {
                    $sonHesapBilgisi = $a;
                    foreach (($a['Transactions'] ?? []) as $t) {
                        $hareketler[] = $this->normalizeHareket($t, $a);
                    }
                }
            }

            if ($sonHesapBilgisi) {
                $this->hesapBilgisiYaz($hesapId, $sonHesapBilgisi);
            }

            $this->logBasarili($logId, count($hareketler), json_encode(['hareket_sayisi' => count($hareketler)]));

            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu.',
                'data'    => $hareketler,
                'count'   => count($hareketler),
            ];
        } catch (DenizbankHata $e) {
            $this->logHatali($logId, $e->getMessage(), $e->bankaKodu, $e->http);
            return ['success' => false, 'message' => $e->getMessage(), 'data' => [], 'count' => 0];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }

    /**
     * Hesabı senkronize et (son senkrondan bugüne)
     */
    public function senkronize(int $hesapId): array
    {
        $logId = $this->logBaslat('senkronize', $hesapId, "Hesap ID: $hesapId senkronizasyonu");

        try {
            // Son senkron; yoksa ilk yükleme için geriye 30 gün
            $baslangic = $this->getSonSenkronTarihi($hesapId) ?? date('Y-m-d', strtotime('-' . self::MAKS_GUN . ' days'));
            $bitis     = date('Y-m-d');

            $result = $this->getHareketler($hesapId, $baslangic, $bitis);
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return $result + ['inserted' => 0, 'skipped' => 0];
            }

            $inserted = 0;
            $skipped  = 0;
            foreach ($result['data'] as $hareket) {
                if ($this->hareketKaydet($hesapId, $hareket)) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }

            $this->sonSenkronGuncelle($hesapId, $inserted + $skipped);

            $message = "$inserted yeni kayıt eklendi, $skipped kayıt zaten mevcut.";
            $this->logBasarili($logId, $inserted, $message);

            return [
                'success'  => true,
                'message'  => $message,
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'total'    => count($result['data']),
            ];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => 'Senkronizasyon hatası: ' . $e->getMessage(), 'inserted' => 0, 'skipped' => 0];
        }
    }

    /**
     * Tüm hesapları senkronize et
     */
    public function senkronizeTumu(): array
    {
        $results        = [];
        $toplamInserted = 0;
        $toplamSkipped  = 0;
        $basarili       = 0;
        $hatali         = 0;

        foreach ($this->getApiHesaplari() as $hesap) {
            $result = $this->senkronize($hesap['bankaHesap_id']);
            $results[] = [
                'hesap_id' => $hesap['bankaHesap_id'],
                'iban'     => $hesap['bankaHesap_iban'],
                'result'   => $result,
            ];
            if ($result['success']) {
                $basarili++;
                $toplamInserted += $result['inserted'] ?? 0;
                $toplamSkipped  += $result['skipped'] ?? 0;
            } else {
                $hatali++;
            }
        }

        return [
            'success' => $hatali === 0,
            'message' => "$basarili hesap başarılı, $hatali hesap hatalı. Toplam $toplamInserted yeni kayıt.",
            'results' => $results,
            'summary' => [
                'basarili'        => $basarili,
                'hatali'          => $hatali,
                'toplam_inserted' => $toplamInserted,
                'toplam_skipped'  => $toplamSkipped,
            ],
        ];
    }
}

/**
 * Denizbank servis hatası (banka hata kodu + HTTP durumu log'a yazılır)
 */
class DenizbankHata extends \Exception
{
    public function __construct(string $mesaj, public string $bankaKodu, public ?int $http = null)
    {
        parent::__construct($mesaj);
    }
}
