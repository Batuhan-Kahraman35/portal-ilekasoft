<?php
/**
 * Faturam.net Fatura Sorgulama İstemcisi
 *
 * Kurum ve kanal bilgileri veritabanından okunur:
 *  - Entegrasyonlar / EntegrasyonKanallari  → BaseURL, endpoint, UserAgent, timeout
 *  - Cari.cari_entegrasyon_kod              → ornekfatura.net KurumId
 *  - Abonelikler.abonelik_bilgi             → abone / tesisat numarası
 *
 * Her sorgu EntegrasyonLoglari tablosuna yazılır (IslemTipi = 'faturam_sorgu').
 */

require_once __DIR__ . '/../db.php';

class FaturamClient
{
    /** Entegrasyon adı — kanal bu ada göre bulunur */
    public const ENTEGRASYON_ADI = 'Faturam.net';
    public const ISLEM_TIPI      = 'faturam_sorgu';
    public const ISLEM_TIPI_KURUM = 'faturam_kurum_listesi';

    private Database $db;
    private array $kanal;
    private array $ayar;
    private string $cookieFile;
    private string $csrf = '';
    private int $sonIstekZamani = 0;

    /**
     * @param int|null $kanalId Belirtilmezse aktif Faturam.net kanalı seçilir.
     * @throws RuntimeException Kanal bulunamazsa
     */
    public function __construct(?int $kanalId = null)
    {
        $this->db = Database::getInstance();

        if ($kanalId !== null) {
            $kanal = $this->db->fetchOne("
                SELECT k.*, e.Entegrasyonlar_Ad, e.Entegrasyonlar_AyarJSON
                FROM EntegrasyonKanallari k
                INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                WHERE k.EntegrasyonKanallari_id = ?
            ", [$kanalId]);
        } else {
            $kanal = $this->db->fetchOne("
                SELECT TOP 1 k.*, e.Entegrasyonlar_Ad, e.Entegrasyonlar_AyarJSON
                FROM EntegrasyonKanallari k
                INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
                WHERE e.Entegrasyonlar_Ad = ?
                  AND k.EntegrasyonKanallari_Durum = 1
                  AND e.Entegrasyonlar_Durum = 1
                ORDER BY k.EntegrasyonKanallari_id
            ", [self::ENTEGRASYON_ADI]);
        }

        if (!$kanal) {
            throw new RuntimeException('Faturam.net entegrasyon kanalı bulunamadı veya pasif.');
        }

        $this->kanal = $kanal;

        $entAyar   = json_decode((string) ($kanal['Entegrasyonlar_AyarJSON'] ?? ''), true) ?: [];
        $kanalAyar = json_decode((string) ($kanal['EntegrasyonKanallari_AyarJSON'] ?? ''), true) ?: [];
        $this->ayar = array_merge([
            'BaseURL'            => 'https://ornekfatura.net',
            'QueryEndpoint'      => '/query',
            'CompanyEndpoint'    => '/company',
            'Timeout'            => 20,
            'SslVerify'          => false,
            'IstekArasiBeklemeMs' => 300,
            // ornekfatura.net kararsız çalışıyor; aynı istek rastgele HTTP 500 dönebiliyor.
            // Geçici hatalarda istek artan beklemeyle tekrarlanır.
            'DenemeSayisi'        => 8,
            'DenemeBeklemeleriMs' => [1000, 2000, 3000, 4000, 6000, 8000, 10000],
            'UserAgent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ], $entAyar, $kanalAyar);

        $this->cookieFile = tempnam(sys_get_temp_dir(), 'faturam_');
        $this->csrfAl();
    }

    public function __destruct()
    {
        if (isset($this->cookieFile) && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function kanalId(): int
    {
        return (int) $this->kanal['EntegrasyonKanallari_id'];
    }

    /**
     * Tek abonelik sorgusu. Sonuç normalize edilip döner.
     *
     * @param string   $kurumId    Cari.cari_entegrasyon_kod
     * @param string   $aboneNo    Abonelikler.abonelik_bilgi
     * @param int|null $abonelikId Log hedefi (EntegrasyonLoglari_Hedef)
     * @param int|null $kullaniciId Manuel tetiklemede işlemi yapan kullanıcı
     *
     * @return array{basarili:bool, cevap_kodu:string, mesaj:string, abone_adi:string,
     *               tutar:float|null, son_odeme:string|null, fatura_sayisi:int, ham:array}
     */
    public function sorgula(string $kurumId, string $aboneNo, ?int $abonelikId = null, ?int $kullaniciId = null): array
    {
        $kurumId = trim($kurumId);
        $aboneNo = trim($aboneNo);

        $istek = ['KurumId' => $kurumId, 'TesisatNo' => $aboneNo];
        $sonuc = [
            'basarili'      => false,
            'cevap_kodu'    => '',
            'mesaj'         => '',
            'abone_adi'     => '',
            'tutar'         => null,
            'son_odeme'     => null,
            'fatura_sayisi' => 0,
            'ham'           => [],
        ];

        if ($kurumId === '' || $aboneNo === '') {
            $sonuc['mesaj'] = 'Kurum kodu veya abone numarası boş.';
            $this->logYaz($abonelikId, $istek, null, false, $sonuc['mesaj'], $kullaniciId);
            return $sonuc;
        }

        try {
            // Servis bazen HTTP 200 ile boş CevapKodu döndürüyor. Bu da geçici hatadır:
            // aynı abone no bir denemede boş, diğerinde 0000 + fatura dönebiliyor.
            $yanit = $this->istek($this->ayar['QueryEndpoint'], $istek, static function (array $veri): bool {
                $fsc = $veri['q']['FaturaSorgulaSonuc'] ?? null;
                return is_array($fsc) && (string) ($fsc['CevapKodu'] ?? '') !== '';
            });
        } catch (RuntimeException $e) {
            $sonuc['mesaj'] = $this->kullaniciMesaji($e->getMessage());
            $this->logYaz($abonelikId, $istek, null, false, $e->getMessage(), $kullaniciId);
            return $sonuc;
        }

        $fsc = $yanit['q']['FaturaSorgulaSonuc'] ?? [];
        $sonuc['ham']        = $yanit;
        $sonuc['cevap_kodu'] = (string) ($fsc['CevapKodu'] ?? '');
        $sonuc['mesaj']      = (string) ($fsc['Mesaj_TR'] ?? '');
        $sonuc['abone_adi']  = (string) ($fsc['AboneAdi'] ?? '');

        if ($sonuc['cevap_kodu'] !== '0000') {
            if ($sonuc['mesaj'] === '') {
                $sonuc['mesaj'] = $sonuc['cevap_kodu'] === ''
                    ? 'Servis bu abone için bilgi döndürmedi; abonelik bilgisini (abone/tesisat no) kontrol edin.'
                    : 'Sorgu başarısız (kod: ' . $sonuc['cevap_kodu'] . ')';
            }
            $this->logYaz($abonelikId, $istek, $yanit, false, $sonuc['mesaj'], $kullaniciId);
            return $sonuc;
        }

        $faturalar = $fsc['FaturaListesi'] ?? [];
        $sonuc['fatura_sayisi'] = count($faturalar);

        if ($faturalar) {
            // Birden fazla fatura olabilir: tutarlar toplanır, en yakın son ödeme tarihi alınır.
            // NOT: "Toplam" alanı ornekfatura.net kart komisyonunu içerir, kullanılmaz.
            $toplam    = 0.0;
            $tarihler  = [];
            foreach ($faturalar as $f) {
                $toplam += (float) str_replace(',', '.', (string) ($f['FaturaTutari'] ?? 0));
                if (!empty($f['SonOdemeTarihi'])) {
                    $tarihler[] = (string) $f['SonOdemeTarihi'];
                }
            }
            $sonuc['tutar'] = round($toplam, 2);
            if ($tarihler) {
                sort($tarihler);
                $sonuc['son_odeme'] = $tarihler[0];
            }
        }

        $sonuc['basarili'] = true;
        $this->logYaz($abonelikId, $istek, $yanit, true, null, $kullaniciId);

        return $sonuc;
    }

    /**
     * ornekfatura.net kurum listesi (KurumId / KurumAdi / KategoriId / SorguAlanlari).
     *
     * @return array<int, array<string, mixed>>
     */
    public function kurumListesi(): array
    {
        $yanit = $this->istek($this->ayar['CompanyEndpoint'], null, static function (array $veri): bool {
            return !empty($veri['KurumListesiSonuc']['KurumListesi']);
        });
        return $yanit['KurumListesiSonuc']['KurumListesi'] ?? [];
    }

    // --------------------------------------------------------------------- //

    private function csrfAl(): void
    {
        $html = $this->curl($this->ayar['BaseURL'] . '/', null, false);

        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html['body'], $m)) {
            $this->csrf = $m[1];
        }

        if ($this->csrf === '') {
            throw new RuntimeException('ornekfatura.net CSRF token alınamadı (HTTP ' . $html['code'] . ').');
        }
    }

    /**
     * Geçici hatalarda (bağlantı kopması, HTTP 5xx, bozuk/boş gövde) istek tekrarlanır.
     * Kalıcı hatalar (4xx) ilk denemede istisna atar; tekrar denemek anlamsızdır.
     *
     * @param array|null    $govde     null ise GET, doluysa JSON POST
     * @param callable|null $gecerliMi Çözümlenen gövdeyi doğrular; false dönerse yanıt
     *                                 geçici hata sayılıp istek tekrarlanır.
     */
    private function istek(string $endpoint, ?array $govde, ?callable $gecerliMi = null): array
    {
        $denemeSayisi = max(1, (int) $this->ayar['DenemeSayisi']);
        $beklemeler   = (array) $this->ayar['DenemeBeklemeleriMs'];
        $sonHata      = 'Bilinmeyen hata.';

        for ($deneme = 1; $deneme <= $denemeSayisi; $deneme++) {
            if ($deneme > 1) {
                // Bekleme listesi denemelerden kısaysa son değer tekrar kullanılır.
                $bekleme = $beklemeler[$deneme - 2] ?? (end($beklemeler) ?: 1000);
                usleep(((int) $bekleme) * 1000);
            }

            $this->beklet();

            $yanit = $this->curl($this->ayar['BaseURL'] . $endpoint, $govde, true);

            if ($yanit['hata'] !== '') {
                $sonHata = 'Bağlantı hatası: ' . $yanit['hata'];
                continue;
            }

            if ($yanit['code'] >= 500) {
                $sonHata = 'HTTP ' . $yanit['code'] . ' yanıtı alındı.';
                continue;
            }

            if ($yanit['code'] !== 200) {
                throw new RuntimeException('HTTP ' . $yanit['code'] . ' yanıtı alındı.');
            }

            $veri = json_decode($yanit['body'], true);
            if (!is_array($veri)) {
                $sonHata = 'Yanıt JSON olarak çözümlenemedi.';
                continue;
            }

            if ($gecerliMi !== null && !$gecerliMi($veri)) {
                $sonHata = 'Servis içeriksiz yanıt döndü.';
                continue;
            }

            return $veri;
        }

        throw new RuntimeException($sonHata . ' (' . $denemeSayisi . ' deneme yapıldı)');
    }

    /** Teknik hata metnini panelde gösterilecek sade mesaja çevirir. */
    private function kullaniciMesaji(string $teknik): string
    {
        $geciciDesen = '/HTTP 5\d\d|Bağlantı hatası|içeriksiz yanıt|JSON olarak çözümlenemedi/u';

        if (preg_match($geciciDesen, $teknik)) {
            return 'ornekfatura.net sorguya yanıt vermedi. Servis kararsız çalışıyor; '
                . 'lütfen daha sonra tekrar deneyin. Sorun sürerse abonelik bilgisini kontrol edin.';
        }

        return $teknik;
    }

    /**
     * @return array{body:string, code:int, hata:string}
     */
    private function curl(string $url, ?array $govde, bool $jsonBaslik): array
    {
        $ch = curl_init($url);

        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_TIMEOUT        => (int) $this->ayar['Timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => (bool) $this->ayar['SslVerify'],
            CURLOPT_SSL_VERIFYHOST => $this->ayar['SslVerify'] ? 2 : 0,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => (string) $this->ayar['UserAgent'],
        ];

        if ($govde !== null) {
            $opt[CURLOPT_POST]       = true;
            $opt[CURLOPT_POSTFIELDS] = json_encode($govde, JSON_UNESCAPED_UNICODE);
        }

        if ($jsonBaslik) {
            $opt[CURLOPT_HTTPHEADER] = [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Requested-With: XMLHttpRequest',
                'X-CSRF-TOKEN: ' . $this->csrf,
                'Referer: ' . $this->ayar['BaseURL'] . '/',
            ];
        }

        curl_setopt_array($ch, $opt);

        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata = curl_error($ch);
        curl_close($ch);

        $this->sonIstekZamani = (int) (microtime(true) * 1000);

        return ['body' => $body, 'code' => $code, 'hata' => $hata];
    }

    /** Rate limit koruması: ardışık istekler arasında bekle. */
    private function beklet(): void
    {
        $bekleme = (int) $this->ayar['IstekArasiBeklemeMs'];
        if ($bekleme <= 0 || $this->sonIstekZamani === 0) {
            return;
        }

        $gecen = (int) (microtime(true) * 1000) - $this->sonIstekZamani;
        if ($gecen < $bekleme) {
            usleep(($bekleme - $gecen) * 1000);
        }
    }

    private function logYaz(?int $abonelikId, array $istek, ?array $yanit, bool $basarili, ?string $hata, ?int $kullaniciId): void
    {
        try {
            $this->db->execute("
                INSERT INTO EntegrasyonLoglari (
                    EntegrasyonLoglari_EntegrasyonKanallari_id,
                    EntegrasyonLoglari_IslemTipi,
                    EntegrasyonLoglari_Kaynak,
                    EntegrasyonLoglari_Hedef,
                    EntegrasyonLoglari_Istek,
                    EntegrasyonLoglari_Cevap,
                    EntegrasyonLoglari_BasariliMi,
                    EntegrasyonLoglari_HataMesaji,
                    EntegrasyonLoglari_KullaniciId,
                    EntegrasyonLoglari_OlusturanKullanici,
                    EntegrasyonLoglari_OlusturmaTarihi
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
            ", [
                $this->kanalId(),
                self::ISLEM_TIPI,
                php_sapi_name() === 'cli' ? 'cron' : 'panel',
                $abonelikId !== null ? (string) $abonelikId : null,
                json_encode($istek, JSON_UNESCAPED_UNICODE),
                $yanit !== null ? json_encode($yanit, JSON_UNESCAPED_UNICODE) : null,
                $basarili ? 1 : 0,
                $hata,
                $kullaniciId,
                $kullaniciId ?? 1,
            ]);
        } catch (Throwable $e) {
            error_log('FaturamClient log yazılamadı: ' . $e->getMessage());
        }
    }
}
