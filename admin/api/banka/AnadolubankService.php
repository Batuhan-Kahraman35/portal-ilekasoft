<?php
/**
 * Anadolubank Gateway API Servisi
 *
 * OAuth2 (grant_type=password) tabanlı REST servisi.
 * Token: aos(test)/aos.anadolubank.com.tr/anadolubanksecurity/oauth/token
 * Hesap Hareketleri: agw(test)/agw.anadolubank.com.tr/inb/nkthes/HesapHareketleri
 *
 * OAuth ayarları apiKimlik_ekAyarlar (JSON) içinde tutulur:
 *   { client_id, client_secret, scope, token_url, gateway_url, key, musteri_no }
 * username/sifre ise mevcut kolonlarda (apiKimlik_kullanici / apiKimlik_sifre).
 *
 * Kısıtlar (banka dokümanı): tarih aralığı en fazla 2 gün, geriye en fazla 60 gün.
 *
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BaseBankaService.php';

class AnadolubankService extends BaseBankaService
{
    /** Instance ömrü boyunca cache'lenen access token (JWT) */
    private ?string $accessToken = null;

    /** ekAyarlar JSON'unu diziye çöz */
    private function cfg(): array
    {
        $ek = $this->apiKimlik['apiKimlik_ekAyarlar'] ?? '';
        $c = json_decode((string)$ek, true);
        return is_array($c) ? $c : [];
    }

    /**
     * OAuth2 access token al (grant_type=password).
     * Aynı instance'ta bir kez alınır, sonrakiler cache'ten döner.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $c = $this->cfg();
        $clientId     = $c['client_id'] ?? '';
        $clientSecret = $c['client_secret'] ?? '';
        $scope        = $c['scope'] ?? 'HesapHareketleri';
        $tokenUrl     = $c['token_url'] ?? '';
        $username     = $this->apiKimlik['apiKimlik_kullanici'] ?? '';
        $password     = $this->apiKimlik['apiKimlik_sifre'] ?? '';

        if (!$clientId || !$clientSecret || !$tokenUrl || !$username || !$password) {
            throw new \Exception('Anadolubank OAuth bilgileri eksik (client_id / client_secret / token_url / kullanıcı / şifre).');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $tokenUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'username'   => $username,
                'password'   => $password,
                'scope'      => $scope,
                'grant_type' => 'password',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \Exception("Token isteği bağlantı hatası: $err");
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data) || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? substr((string)$resp, 0, 200);
            throw new \Exception("Token alınamadı (HTTP $http): $msg");
        }

        $this->accessToken = $data['access_token'];
        return $this->accessToken;
    }

    /**
     * Gateway'e tek bir hesap hareketleri sorgusu gönder (tek 2-günlük parça).
     * MusteriNo veya Iban'dan biri verilmeli.
     *
     * @param string $baslangic dd.MM.yyyy HH:mm:ss
     * @param string $bitis     dd.MM.yyyy HH:mm:ss
     */
    private function sorgula(string $baslangic, string $bitis, ?string $iban = null, ?string $musteriNo = null): array
    {
        $c     = $this->cfg();
        $gwUrl = $c['gateway_url'] ?? '';
        $key   = $c['key'] ?? '';

        if (!$gwUrl || !$key) {
            throw new \Exception('Anadolubank gateway_url veya key tanımlı değil (ekAyarlar).');
        }

        $token = $this->getAccessToken();

        $body = [
            'Key'            => $key,
            'BaslangicTarih' => $baslangic,
            'BitisTarih'     => $bitis,
            'MusteriNo'      => $musteriNo ?? '',
            'Iban'           => $iban ?? '',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $gwUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \Exception("Gateway bağlantı hatası: $err");
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new \Exception("Gateway yanıtı çözümlenemedi (HTTP $http): " . substr((string)$resp, 0, 200));
        }

        return $data;
    }

    /**
     * Y-m-d aralığını Anadolubank kısıtlarına uygun 2-günlük parçalara böl.
     * Geriye en fazla 60 gün kuralı da burada uygulanır.
     *
     * @return array<array{0:string,1:string}> [ [dd.MM.yyyy HH:mm:ss, dd.MM.yyyy HH:mm:ss], ... ]
     */
    private function tarihAraliklari(string $baslangic, string $bitis): array
    {
        $start = strtotime($baslangic . ' 00:00:00');
        $end   = strtotime($bitis . ' 23:59:59');

        // Geriye en fazla 60 gün. Banka bu sınırı saat bazında ölçtüğü için
        // (gün içinde "60 gün önce 00:00" ~60,7 gün olup kod 54 verir) 59 günlük
        // güvenli üst sınır uygulanır.
        $enEski = strtotime('-59 days 00:00:00');
        if ($start < $enEski) {
            $start = $enEski;
        }
        if ($end <= $start) {
            $end = $start + 86399;
        }

        $ikiGun    = 2 * 86400;
        $araliklar = [];
        $cur       = $start;

        while ($cur < $end) {
            $parcaBitis = min($cur + $ikiGun, $end);
            $araliklar[] = [
                date('d.m.Y H:i:s', $cur),
                date('d.m.Y H:i:s', $parcaBitis),
            ];
            $cur = $parcaBitis;
        }

        return $araliklar;
    }

    /** "dd.MM.yyyy HH:mm:ss" veya "dd.MM.yyyy" -> "Y-m-d H:i:s" */
    private function parseTarih(string $t): string
    {
        $t = trim($t);
        if ($t === '') {
            return date('Y-m-d H:i:s');
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}):(\d{2}))?$/', $t, $m)) {
            $h = $m[4] ?? '00';
            $i = $m[5] ?? '00';
            $s = $m[6] ?? '00';
            return "{$m[3]}-{$m[2]}-{$m[1]} {$h}:{$i}:{$s}";
        }
        $ts = strtotime($t);
        return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }

    /** Banka sonuç kodu 0 değilse anlamlı hata mesajı fırlat */
    private function sonucKontrol(array $data): void
    {
        $kod = $data['sonucKodu'] ?? null;
        if ((string)$kod !== '0') {
            $aciklama = $data['sonucKoduAciklama'] ?? 'Bilinmeyen hata';
            throw new \Exception("Anadolubank hata (kod $kod): $aciklama");
        }
    }

    /** hesapBilgisi -> banka_Hesap normalize formatı */
    private function normalizeHesap(array $h): array
    {
        return [
            'iban'      => str_replace(' ', '', (string)($h['iban'] ?? '')),
            'musteriNo' => (string)($this->cfg()['musteri_no'] ?? ''),
            'ekNo'      => $h['ekNo'] ?? null,
            'aciklama'  => trim((string)($h['subeAdi'] ?? '')),
            'subeKod'   => (string)($h['subeKod'] ?? ''),
            'hesapNo'   => (string)($h['hesapNo'] ?? ''),
            'bakiye'    => floatval($h['bakiye'] ?? 0),
            'kullanilabilirBakiye' => floatval($h['kullanilabilirBakiye'] ?? ($h['bakiye'] ?? 0)),
            'paraBirimi' => trim((string)($h['paraBirimi'] ?? 'TL')),
        ];
    }

    /** muhasebeKaydı -> banka_HesapHareketleri normalize formatı */
    private function normalizeHareket(array $k, array $hesapBilgisi): array
    {
        // pk her hareket için unique; yoksa referans alanlarından üret
        $pk = trim((string)($k['pk'] ?? ''));
        if ($pk === '') {
            $pk = ($k['muhasebeReferansKodu'] ?? '') . '-'
                . ($k['muhasebeReferansNo'] ?? '') . '-'
                . ($k['kayitNumarasi'] ?? '') . '-'
                . ($k['muhasebeTarihi'] ?? '');
        }
        $identifier = 'AND-' . $pk;

        $borcAlacak = strtoupper(trim((string)($k['borcAlacak'] ?? 'A'))) === 'B' ? 'B' : 'A';
        $paraBirimi = trim((string)($hesapBilgisi['paraBirimi'] ?? 'TL'));

        return [
            'identifier'        => $identifier,
            'islemTarihi'       => $this->parseTarih((string)($k['gerceklesmeTarihi'] ?? $k['muhasebeTarihi'] ?? '')),
            'tutar'             => floatval($k['tutar'] ?? 0),
            'borcAlacak'        => $borcAlacak,
            'aciklama'          => trim((string)($k['aciklama'] ?? '')),
            'karsiTarafIban'    => (string)($k['aliciHesapNo'] ?? ''),
            'karsiTarafAdUnvan' => (string)($k['gonderenAdSoyad'] ?? ''),
            'vknTckn'           => '', // gonderenKimlikNo hash'li gelir; anlamsız, boş bırakılır
            'bakiye'            => floatval($k['bakiye'] ?? 0),
            'dovizKodu'         => ($paraBirimi === 'TL' || $paraBirimi === '') ? 'TRY' : $paraBirimi,
            'masraf'            => 0,
        ];
    }

    /**
     * API bağlantı testi (token + kısa müşteri sorgusu)
     */
    public function testConnection(): array
    {
        $logId = $this->logBaslat('testConnection', null, 'Anadolubank bağlantı testi');

        try {
            $musteriNo = (string)($this->cfg()['musteri_no'] ?? '');
            if ($musteriNo === '') {
                throw new \Exception('ekAyarlar içinde musteri_no tanımlı değil.');
            }

            // Son 1 gün, sadece hesap bilgisi için
            $bas = date('d.m.Y H:i:s', strtotime('-1 day'));
            $bit = date('d.m.Y H:i:s');

            $data = $this->sorgula($bas, $bit, null, $musteriNo);
            $this->sonucKontrol($data);

            $hesapSayisi = count($data['ekstreler'] ?? []);
            $this->logBasarili($logId, $hesapSayisi, "Bağlantı başarılı. $hesapSayisi hesap.");

            return [
                'success' => true,
                'message' => "Bağlantı başarılı! $hesapSayisi hesap görüldü.",
                'data'    => ['hesap_sayisi' => $hesapSayisi],
            ];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Müşteriye tanımlı hesapları API'den çek ve DB'ye kaydet
     */
    public function getHesaplar(): array
    {
        $logId = $this->logBaslat('getHesaplar', null, 'Anadolubank hesap listesi çekiliyor');

        try {
            $musteriNo = (string)($this->cfg()['musteri_no'] ?? '');
            if ($musteriNo === '') {
                throw new \Exception('ekAyarlar içinde musteri_no tanımlı değil.');
            }

            $bas = date('d.m.Y H:i:s', strtotime('-1 day'));
            $bit = date('d.m.Y H:i:s');

            $data = $this->sorgula($bas, $bit, null, $musteriNo);
            $this->sonucKontrol($data);

            $hesaplar = [];
            foreach (($data['ekstreler'] ?? []) as $ekstre) {
                $hb = $ekstre['hesapBilgisi'] ?? null;
                if (!$hb || empty($hb['iban'])) {
                    continue;
                }

                $n = $this->normalizeHesap($hb);
                $hesapId = $this->hesapKaydetVeyaGuncelle($n);

                // Bakiye + şube bilgilerini yaz
                $this->db->execute("
                    UPDATE banka_Hesap SET
                        bankaHesap_bakiye = ?,
                        bankaHesap_kullanilabilirBakiye = ?,
                        bankaHesap_no = COALESCE(NULLIF(?, ''), bankaHesap_no),
                        bankaHesap_sube_kodu = COALESCE(NULLIF(?, ''), bankaHesap_sube_kodu),
                        bankaHesap_sube_adi = COALESCE(NULLIF(?, ''), bankaHesap_sube_adi),
                        bankaHesap_guncelleme_tarihi = GETDATE()
                    WHERE bankaHesap_id = ?
                ", [
                    $n['bakiye'],
                    $n['kullanilabilirBakiye'],
                    $n['hesapNo'],
                    $n['subeKod'],
                    $n['aciklama'],
                    $hesapId,
                ]);

                $n['dbId'] = $hesapId;
                $hesaplar[] = $n;
            }

            $this->logBasarili($logId, count($hesaplar), json_encode(['hesap_sayisi' => count($hesaplar)]));

            return [
                'success' => true,
                'message' => count($hesaplar) . ' hesap bulundu.',
                'data'    => $hesaplar,
                'count'   => count($hesaplar),
            ];
        } catch (\Exception $e) {
            $this->logHatali($logId, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => [], 'count' => 0];
        }
    }

    /**
     * Belirli bir hesabın hareketlerini çek (IBAN ile, 2-günlük parçalar halinde)
     */
    public function getHareketler(int $hesapId, string $baslangicTarih, string $bitisTarih): array
    {
        $hesap = $this->db->fetchOne("SELECT * FROM banka_Hesap WHERE bankaHesap_id = ?", [$hesapId]);
        if (!$hesap) {
            return ['success' => false, 'message' => 'Hesap bulunamadı', 'data' => [], 'count' => 0];
        }

        $iban = str_replace(' ', '', (string)$hesap['bankaHesap_iban']);
        if ($iban === '') {
            return ['success' => false, 'message' => 'Hesabın IBAN bilgisi yok', 'data' => [], 'count' => 0];
        }

        $istekOzet = json_encode(['hesapId' => $hesapId, 'iban' => $iban, 'baslangic' => $baslangicTarih, 'bitis' => $bitisTarih]);
        $logId = $this->logBaslat('getHareketler', $hesapId, $istekOzet);

        try {
            $hareketler = [];
            foreach ($this->tarihAraliklari($baslangicTarih, $bitisTarih) as [$bas, $bit]) {
                $data = $this->sorgula($bas, $bit, $iban, null);
                $this->sonucKontrol($data);

                foreach (($data['ekstreler'] ?? []) as $ekstre) {
                    $hb = $ekstre['hesapBilgisi'] ?? [];
                    foreach (($ekstre['muhasebeKayitlari'] ?? []) as $k) {
                        $hareketler[] = $this->normalizeHareket($k, $hb);
                    }
                }
            }

            $this->logBasarili($logId, count($hareketler), json_encode(['hareket_sayisi' => count($hareketler)]));

            return [
                'success' => true,
                'message' => count($hareketler) . ' hareket bulundu.',
                'data'    => $hareketler,
                'count'   => count($hareketler),
            ];
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
            // Son senkron; yoksa ilk yükleme için geriye 60 gün (banka üst limiti)
            $sonSenkron = $this->getSonSenkronTarihi($hesapId);
            $baslangic  = $sonSenkron ?? date('Y-m-d', strtotime('-60 days'));
            $bitis      = date('Y-m-d');

            $result = $this->getHareketler($hesapId, $baslangic, $bitis);
            if (!$result['success']) {
                $this->logHatali($logId, $result['message']);
                return $result;
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
        $hesaplar       = $this->getApiHesaplari();
        $results        = [];
        $toplamInserted = 0;
        $toplamSkipped  = 0;
        $basarili       = 0;
        $hatali         = 0;

        foreach ($hesaplar as $hesap) {
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
