<?php
/**
 * PttAVM Entegrasyon API (REST, self entegratör)
 *
 * Ortak altyapı PazaryeriApi sınıfındadır; burada yalnızca PttAVM'ye
 * özgü kimlik başlıkları, uç yolları ve alan eşlemesi bulunur.
 *
 * Kimlik doğrulama sabit başlıklarla yapılır, jeton alma adımı yoktur:
 *   Api-Key          : entegrasyon@pttavm.com'dan alınan anahtar
 *   access-token     : Satıcı paneli > Hesap Yönetimi > Entegrasyon Bilgileri
 *                      > Self Entegratör kartındaki token (entegratöre özel)
 *   X-Correlation-Id : her istekte farklı olmalı; UUID v4 üretilir
 * Satıcı ID başlıkta gönderilmez, kanal ayarında bilgi amaçlı tutulur.
 *
 * Belgedeki tutarsızlıklar (developers.pttavm.com, 28.10.2026):
 *   - Bazı örneklerde http:// ve "//api" ya da "/ /api" geçiyor; https ve tek "/" kullanılır.
 *   - Başlık tablosunda access-token, curl örneklerinde Access-Token yazıyor
 *     (HTTP başlıkları büyük/küçük harfe duyarsızdır).
 *
 * Sipariş sorgusu GET /orders/search: startDate, endDate, isActiveOrders (zorunlu).
 * isActiveOrders=true yalnız kargo_yapilmasi_bekleniyor durumunu döndürür;
 * tüm siparişler için false gönderilir. Aralık en fazla 40 gün, sayfalama yok.
 * İstek tarih biçimi belgede yazmıyor; yanıt biçimi "Y-m-d\TH:i:s" olduğu için
 * aynısı gönderilir. Yanıt ürün kırılımlıdır: aynı sipariş birden çok kayıtla
 * gelebilir, siparisNo ile birleştirilir.
 *
 * Ayrı iade/iptal ucu yoktur; iade ve iptal siparisDurumu değeri olarak gelir.
 *
 * Portal kapsamı: bağlantı testi ve sipariş senkronu. Sipariş alanları 28.10.2026
 * canlı veriyle (10 sipariş) doğrulandı; ham veri her durumda HamVeri'ye yazılır.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class PttAvmApi extends PazaryeriApi
{
    /** Sipariş sorgusunda kabul edilen azami gün aralığı */
    const SIPARIS_GUN_SINIRI = 40;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('pttavm', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    protected function baseUrl($hizmet = 'varsayilan')
    {
        return rtrim($this->getConfig('BaseURL', 'https://integration-api.pttavm.com/api/v1'), '/');
    }

    /** istek() her çağrıda başlıkları yeniden ister; correlation id böylece istek başına üretilir */
    protected function authHeaders()
    {
        return [
            'Api-Key: ' . trim((string)($this->config['ApiKey'] ?? '')),
            'access-token: ' . trim((string)($this->config['Token'] ?? '')),
            'X-Correlation-Id: ' . $this->uuid(),
            'User-Agent: ' . $this->getConfig('UserAgent', 'Pazaryeri-Entegrasyon/1.0')
        ];
    }

    protected function configDogrula()
    {
        if (empty($this->config['ApiKey']) || empty($this->config['Token'])) {
            return 'PttAVM kanal ayarlarında ApiKey veya Token tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'ApiKey', 'etiket' => 'API Key',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'entegrasyon@pttavm.com adresinden talep edilir'],
            ['anahtar' => 'Token', 'etiket' => 'Token',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'Satıcı paneli > Hesap Yönetimi > Entegrasyon Bilgileri > Self Entegratör'],
            ['anahtar' => 'SaticiId', 'etiket' => 'Satıcı ID',
             'gizli' => false, 'zorunlu' => false,
             'aciklama' => 'Bilgi amaçlı; isteklerde gönderilmez'],
        ];
    }

    private function uuid()
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if (is_array($veri)) {
            foreach (['message', 'detail', 'title', 'error_description', 'error'] as $anahtar) {
                if (!empty($veri[$anahtar]) && is_string($veri[$anahtar])) {
                    return 'HTTP ' . $httpKod . ': ' . $veri[$anahtar];
                }
            }
        }
        if ($httpKod === 401 || $httpKod === 403) {
            return 'HTTP ' . $httpKod . ': Yetki reddedildi. Api Key / Token değerlerini ve panelde Self Entegratör kartının aktif olduğunu kontrol edin.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    /**
     * Ürün sorgusu (/products/search) altı kategori parametresini zorunlu
     * tuttuğu için test, son 1 günün sipariş sorgusuyla yapılır.
     */
    public function baglantiTest()
    {
        $bitis = time();
        $sonuc = $this->siparisleriListele(['startDate' => $bitis - 86400, 'endDate' => $bitis], 0, 0, 'Bağlantı Testi');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        return ['basarili' => true,
                'mesaj'    => 'Bağlantı başarılı. Son 24 saatteki sipariş satırı: ' . count($sonuc['siparisler']),
                'veri'     => $sonuc['siparisler']];
    }

    // =================================================================
    // Siparişler
    // =================================================================

    /**
     * Siparişleri listele. Uçta sayfalama yoktur; $sayfa/$boyut sözleşme
     * uyumu için alınır, kullanılmaz. Dönen liste ürün kırılımlı ham kayıtlardır.
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50, $islem = 'Sipariş Listele')
    {
        $bitis     = !empty($filtre['endDate'])   ? (int)$filtre['endDate']   : time();
        $baslangic = !empty($filtre['startDate']) ? (int)$filtre['startDate'] : $bitis - 86400;

        $sonuc = $this->istek('GET', '/orders/search', null, [
            'startDate'      => date('Y-m-d\TH:i:s', $baslangic),
            'endDate'        => date('Y-m-d\TH:i:s', $bitis),
            'isActiveOrders' => 'false'
        ], $islem);

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'siparisler' => [], 'toplam' => 0];
        }

        $liste = $sonuc['veri'];
        // Zarf ile dönerse (belgede yok) data/items altından al
        if (is_array($liste) && !array_is_list($liste)) {
            $liste = $liste['data'] ?? ($liste['items'] ?? []);
        }
        $liste = is_array($liste) ? $liste : [];

        return ['basarili' => true, 'hata' => '', 'siparisler' => $liste, 'toplam' => count($liste)];
    }

    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $sonuc = $this->siparisleriListele(['startDate' => $dilim['bas'], 'endDate' => $dilim['bit']]);

            if (!$sonuc['basarili']) {
                return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
            }

            foreach ($this->siparisGrupla($sonuc['siparisler']) as $kayitlar) {
                if ($this->siparisKaydet($this->siparisNotrle($kayitlar))) {
                    $toplam++;
                }
            }
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /** Ürün kırılımlı kayıtları sipariş numarasına göre topla */
    private function siparisGrupla(array $liste)
    {
        $gruplar = [];
        foreach ($liste as $s) {
            if (!is_array($s)) {
                continue;
            }
            $no = trim((string)($s['siparisNo'] ?? ''));
            if ($no === '') {
                continue;
            }
            $gruplar[$no][] = $s;
        }
        return $gruplar;
    }

    /**
     * PttAVM siparişi (aynı siparisNo'lu kayıtlar) → nötr sipariş.
     * Alan adları belgedeki örnek yanıttan alındı, 28.10.2026 canlı veriyle doğrulandı.
     */
    private function siparisNotrle(array $kayitlar)
    {
        $s = $kayitlar[0];

        $kalemler = [];
        $durumlar = [];
        $toplam   = 0.0;
        $tutarVar = false;
        $gorulen  = [];
        foreach ($kayitlar as $kayit) {
            foreach (($kayit['siparisUrunler'] ?? []) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                // Aynı kalem birden çok kayıtta tekrar edebilir
                $anahtar = (string)($it['lineItemId'] ?? '') . '|' . (string)($it['urunId'] ?? '') . '|' . (string)($it['urunBarkod'] ?? '');
                if (isset($gorulen[$anahtar])) {
                    continue;
                }
                $gorulen[$anahtar] = true;

                $hamDurum   = $it['siparisDurumu'] ?? null;
                $durumlar[] = $this->siparisDurumKodu($hamDurum);

                $dahil = $this->sayi($it['kdvDahilToplamTutar'] ?? null);
                $haric = $this->sayi($it['kdvHaricToplamTutar'] ?? null);
                $adet  = max(1, (int)($it['toplamIslemAdedi'] ?? 1));
                if ($dahil !== null && $this->siparisDurumKodu($hamDurum) !== 'iptal') {
                    $toplam  += $dahil;
                    $tutarVar = true;
                }

                $kalemler[] = [
                    'disId'         => isset($it['lineItemId']) ? (string)$it['lineItemId'] : null,
                    'barkod'        => ($it['urunBarkod'] ?? '') !== '' ? $it['urunBarkod'] : null,
                    'stokKodu'      => ($kayit['urunKodu'] ?? '') !== '' ? $kayit['urunKodu'] : null,
                    'disUrunId'     => isset($it['urunId']) ? (string)$it['urunId'] : null,
                    'urunAdi'       => ($it['urun'] ?? '') !== '' ? $it['urun'] : ($kayit['urunAdi'] ?? null),
                    'miktar'        => $adet,
                    'birimFiyat'    => $dahil !== null ? round($dahil / $adet, 2) : null,
                    'toplamTutar'   => $dahil,
                    'indirimTutar'  => $this->sayi($it['indirimToplam'] ?? null),
                    'kdvOran'       => $this->sayi($it['kdvOrani'] ?? null),
                    'kdvTutar'      => ($dahil !== null && $haric !== null) ? round($dahil - $haric, 2) : null,
                    'komisyonTutar' => $this->sayi($it['komisyon'] ?? null),
                    'hamDurum'      => is_scalar($hamDurum) ? (string)$hamDurum : null,
                    'ham'           => $it
                ];
            }
        }

        $aktif = array_values(array_filter($durumlar, fn($d) => $d !== 'iptal'));
        $sira  = ['yeni' => 1, 'hazirlaniyor' => 2, 'kargoda' => 3, 'teslim' => 4, 'iade' => 5, 'diger' => 6];
        usort($aktif, fn($a, $b) => $sira[$a] <=> $sira[$b]);
        $durumKod = $durumlar ? ($aktif[0] ?? 'iptal') : 'diger';

        $hamDurumlar = array_values(array_unique(array_filter(array_column($kalemler, 'hamDurum'))));

        // 11 hane → TCKN (bireysel), 10 hane → VKN (kurumsal)
        $tckn    = preg_replace('/\D/', '', (string)($s['tckn'] ?? ''));
        $vergiNo = preg_replace('/\D/', '', (string)($s['vergiNo'] ?? ''));

        $musteri = trim(($s['musteriAdi'] ?? '') . ' ' . ($s['musteriSoyadi'] ?? ''));
        $fatAdi  = trim(($s['faturaMusteriAdi'] ?? '') . ' ' . ($s['faturaMusteriSoyadi'] ?? ''));
        $unvan   = trim((string)($s['firmaUnvani'] ?? ''));

        // İl adları baştaki boşlukla geliyor (" İZMİR")
        $il      = trim((string)($s['siparisIli']  ?? '')) ?: null;
        $ilce    = trim((string)($s['siparisIlce'] ?? '')) ?: null;

        $teslim = array_filter([
            'adres'     => $s['siparisAdresi'] ?? null,
            'il'        => $il,
            'ilce'      => $ilce,
            'ilKod'     => $s['ilKod']     ?? null,
            'ilceKod'   => $s['ilceKod']   ?? null,
            'postaKodu' => $s['postaKodu'] ?? null   // belgede yok, canlı yanıtta geliyor
        ], fn($v) => $v !== null && $v !== '');
        $fatura = array_filter([
            'adSoyad' => $fatAdi,
            'unvan'   => $unvan,
            'adres'   => $s['faturaAdresi'] ?? null,
            'il'      => trim((string)($s['faturaIli']  ?? '')),
            'ilce'    => trim((string)($s['faturaIlce'] ?? '')),
            'tip'     => $s['faturaTip'] ?? ($s['faturaType'] ?? null)
        ], fn($v) => $v !== null && $v !== '');

        $kargoTutari = $this->sayi($s['kargoTutari'] ?? null);

        return [
            'disId'          => (string)$s['siparisNo'],
            'siparisNo'      => (string)$s['siparisNo'],
            'durumKod'       => $durumKod,
            'hamDurum'       => $hamDurumlar ? implode(',', $hamDurumlar) : null,
            'toplamTutar'    => $tutarVar ? round($toplam + (float)$kargoTutari, 2) : null,
            'paraBirimi'     => 'TRY',
            'musteriAdi'     => $unvan !== '' ? $unvan : ($fatAdi !== '' ? $fatAdi : ($musteri !== '' ? $musteri : null)),
            'aliciAdi'       => $musteri !== '' ? $musteri : null,
            'eposta'         => ($s['eposta'] ?? '') !== '' ? $s['eposta'] : null,
            'telefon'        => ($s['telefonNo'] ?? '') !== '' ? $s['telefonNo'] : null,
            'tckn'           => strlen($tckn) === 11 ? $tckn : null,
            'vergiNo'        => strlen($vergiNo) === 10 ? $vergiNo : null,
            'vergiDairesi'   => ($s['vergiDaire'] ?? '') !== '' ? $s['vergiDaire'] : null,
            'il'             => $il,
            'ilce'           => $ilce,
            'teslimatAdresi' => $teslim ?: null,
            'faturaAdresi'   => $fatura ?: null,
            'kargoBarkod'    => ($s['kargoBarkod'] ?? '') !== '' ? $s['kargoBarkod'] : null,
            'siparisTarihi'  => $this->isoTarih($s['islemTarihi'] ?? ''),
            'kalemler'       => $kalemler,
            'ham'            => count($kayitlar) === 1 ? $s : $kayitlar
        ];
    }

    /** Belgede bazı tutarlar string olarak tanımlı (kdvHaricToplamTutar) */
    private function sayi($deger)
    {
        if (is_string($deger)) {
            $deger = str_replace(',', '.', trim($deger));
        }
        return is_numeric($deger) ? (float)$deger : null;
    }

    /** siparisDurumu → kod (belgedeki 10 değer) */
    private function siparisDurumKodu($durum)
    {
        if (!is_scalar($durum) || trim((string)$durum) === '') {
            return 'diger';
        }
        $d = mb_strtolower(trim((string)$durum));

        if ($d === 'iptal' || $d === 'odeme_gecersiz')                              return 'iptal';
        if ($d === 'iade')                                                          return 'iade';
        if ($d === 'tamamlandi')                                                    return 'teslim';
        if (strpos($d, 'gonderil') === 0 || $d === 'gondericisine_teslim_edildi')   return 'kargoda';
        if ($d === 'kargo_yapilmasi_bekleniyor')                                    return 'hazirlaniyor';
        if ($d === 'onay_surecinde' || $d === 'havale_onayi_bekleniyor')            return 'yeni';

        return 'diger';
    }

    // =================================================================
    // Desteklenmeyenler
    //
    // Fatura gönderme, kargo profili/bilgi listesi ve fiyat-stok güncelleme
    // uçları belgede var ancak bu sürümde yazılmadı. tanim_Pazaryerleri
    // yetenek JSON'unda kapalıdır.
    // =================================================================
}
