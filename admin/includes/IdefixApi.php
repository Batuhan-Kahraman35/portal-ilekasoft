<?php
/**
 * idefix Merchant API
 *
 * Ortak altyapı PazaryeriApi sınıfındadır; burada yalnızca idefix'e özgü
 * uç yolları, kimlik üretimi ve alan eşlemesi bulunur.
 *
 * YAPISAL FARKLAR:
 *   1. İKİ AYRI SERVİS. Ürün işlemleri PIM, sipariş/iade işlemleri OMS
 *      sunucusundadır. Hangi hizmetin kullanılacağı istek() çağrısının
 *      son parametresiyle belirtilir ('pim' | 'oms').
 *   2. KİMLİK BAŞLIKTA HAZIR TOKEN'DIR. Basic auth DEĞİLDİR; API anahtarı
 *      ve gizli anahtar iki nokta ile birleştirilip base64'lenir ve
 *      X-API-KEY başlığında gönderilir. Başlık adı Authorization değildir.
 *   3. SATICI ID YOL PARÇASIDIR. OMS uçlarında {vendorId} yolun içindedir
 *      ve ayrıca 'vendor' sorgu parametresi olarak da zorunludur.
 *   4. TARİH FORMATI EĞİK ÇİZGİLİDİR: 2022/09/30 23:59:59
 *      Diğer dört pazaryerinin hiçbiriyle aynı değildir.
 *   5. SAYFA NUMARASI 1'DEN BAŞLAR (ortak katman 0 tabanlıdır).
 *
 * ORTAM: Test ortamı IP izni ister, canlı ortam istemez. Kimlik bilgileri
 * iki ortamda FARKLIDIR; yanlış ortama yapılan istek 401 döner.
 *
 * Kaynak: Örnek Müşteri iskeleti (kimlik/adres/bağlantı testi) + Örnek Müşteri B sürümündeki
 * sipariş ve iade metotları (24.09.2026). Portal kapsamı: bağlantı testi,
 * sipariş ve iade senkronu. Ürün havuzu işlemleri bu sürümde yoktur.
 *
 * Doğrulama durumu: Örnek Müşteri B mağazasında sipariş ve iade YOKTU; sipariş/iade
 * eşlemesi DOKÜMANDAN yazıldı. Ham yanıt HamVeri'de saklanır; ilk gerçek
 * kayıtta eşleme yeniden doğrulanmalıdır.
 *
 * Senkron notu: lastUpdatedAt filtresi geçersiz değerde de 200 döner
 * (davranışı doğrulanamadı); sipariş senkronu updateAt'e göre azalan
 * sıralayıp yanıttaki updatedAt ile durur.
 *
 * Dokümantasyon: https://developer.idefix.com/api
 */

require_once __DIR__ . '/PazaryeriApi.php';

class IdefixApi extends PazaryeriApi
{
    /** idefix tarih biçimi — diğer pazaryerlerinden farklı */
    const TARIH_BICIMI = 'Y/m/d H:i:s';

    /** Sayfa başına varsayılan kayıt (API varsayılanı 10) */
    const SAYFA_BOYUTU = 50;

    /** Sonsuz döngü koruması */
    const MAKS_SAYFA = 100;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('idefix', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    /**
     * idefix iki sunucu kullanır:
     *   pim → ürün, kategori, marka, stok/fiyat
     *   oms → sipariş, sevkiyat, iade
     */
    protected function baseUrl($hizmet = 'varsayilan')
    {
        $sandbox = !empty($this->config['Sandbox']);

        $adresler = $sandbox
            ? [
                'pim' => $this->getConfig('BaseURL_Pim_Test', 'https://ide-pimapi.idefiks.net/api/connector'),
                'oms' => $this->getConfig('BaseURL_Oms_Test', 'https://ide-omsapi.idefiks.net/api/shipment/connect'),
              ]
            : [
                'pim' => $this->getConfig('BaseURL_Pim', 'https://merchantapi.idefix.com/pim'),
                'oms' => $this->getConfig('BaseURL_Oms', 'https://merchantapi.idefix.com/oms'),
              ];

        // Belirtilmeyen hizmet OMS sayılır; sipariş akışı varsayılandır.
        $adres = $adresler[$hizmet] ?? $adresler['oms'];

        return rtrim($adres, '/');
    }

    /**
     * VENDOR TOKEN = base64_encode(ApiKey:ApiSecret)
     * Authorization değil, X-API-KEY başlığında gönderilir.
     */
    protected function authHeaders()
    {
        $key    = trim((string)$this->getConfig('ApiKey', ''));
        $secret = trim((string)$this->getConfig('ApiSecret', ''));

        return [
            'X-API-KEY: ' . base64_encode($key . ':' . $secret),
            'Accept: application/json',
        ];
    }

    protected function configDogrula()
    {
        foreach ([
            'ApiKey'   => 'API anahtarı',
            'ApiSecret'=> 'API gizli anahtarı',
            'SaticiId' => 'Satıcı ID',
        ] as $anahtar => $etiket) {
            if (empty($this->config[$anahtar])) {
                return "idefix kanal ayarlarında $etiket ($anahtar) tanımlı değil.";
            }
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'ApiKey', 'etiket' => 'API Key',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'Satıcı paneli (satici.idefix.com) > Hesabım > Entegrasyon Bilgileri'],

            ['anahtar' => 'ApiSecret', 'etiket' => 'API Secret',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'API oluşturulduğunda kayıtlı e-posta adresine gönderilir'],

            ['anahtar' => 'SaticiId', 'etiket' => 'Satıcı ID (Vendor ID)',
             'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Sipariş uçlarında hem yol parçası hem sorgu parametresi olarak kullanılır'],

            ['anahtar' => 'Sandbox', 'etiket' => 'Test ortamı kullan (1/0)',
             'gizli' => false, 'zorunlu' => false,
             'aciklama' => 'Test ortamı IP izni ister ve kimlik bilgileri canlıdan farklıdır'],
        ];
    }

    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        // Kimlik hatasının kendine özgü gövdesi vardır
        $mesaj = is_array($veri) ? ($veri['message'] ?? '') : '';

        if ($mesaj === 'VENDOR_TOKEN_NOT_EXIST' || $httpKod === 401) {
            return 'HTTP 401: idefix kimliği reddedildi (VENDOR_TOKEN_NOT_EXIST). '
                 . 'API Key/Secret yanlış olabilir veya kimlik diğer ortama '
                 . '(test/canlı) ait olabilir.';
        }

        if ($httpKod === 429) {
            return 'HTTP 429: idefix istek eşiği aşıldı. Bir süre bekleyip tekrar deneyin.';
        }

        if ($mesaj !== '') {
            return 'idefix: ' . $mesaj;
        }

        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    // =================================================================
    // Yardımcılar
    // =================================================================

    /** Satıcı ID — OMS uçlarında yol parçasıdır */
    private function saticiId()
    {
        return trim((string)$this->getConfig('SaticiId', ''));
    }

    /** OMS yolu: /{vendorId}/<uç> */
    private function omsYolu($uc)
    {
        return '/' . rawurlencode($this->saticiId()) . '/' . ltrim($uc, '/');
    }

    /** OMS GET isteği: satıcı id yolda VE 'vendor' sorgusunda zorunludur */
    private function omsIstek($uc, array $query, $islem)
    {
        return $this->istek('GET', $this->omsYolu($uc), null,
                            array_merge(['vendor' => $this->saticiId()], $query), $islem, 'oms');
    }

    /** idefix tarih biçimi (eğik çizgili) */
    private function idefixTarih($zaman)
    {
        $ts = is_numeric($zaman) ? (int)$zaman : strtotime((string)$zaman);
        return date(self::TARIH_BICIMI, $ts ?: time());
    }

    /** Ortak katman 0 tabanlı sayfalar; idefix 1 tabanlı ister */
    private function sayfaNo($sayfa)
    {
        return max(1, (int)$sayfa + 1);
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    /**
     * Sipariş listesi ucuna en küçük sorguyu atar. Bu uç hem kimliği
     * hem de Satıcı ID'yi doğrular; ikisinden biri yanlışsa hata döner.
     */
    public function baglantiTest()
    {
        $sonuc = $this->istek(
            'GET',
            $this->omsYolu('list'),
            null,
            [
                'vendor' => $this->saticiId(),
                'page'   => 1,
                'limit'  => 1,
            ],
            'Bağlantı Testi',
            'oms'
        );

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        $veri = $sonuc['veri'];

        // Yanıt sarmalayıcısının adı doğrulanana kadar birkaç olasılık denenir;
        // canlı yanıt görüldükten sonra burası tek biçime indirilecek.
        $toplam = $veri['totalCount']
               ?? $veri['totalElements']
               ?? $veri['total']
               ?? (isset($veri['content']) && is_array($veri['content']) ? count($veri['content']) : null);

        $ortam = !empty($this->config['Sandbox']) ? 'TEST' : 'CANLI';

        $mesaj = "Bağlantı başarılı ($ortam ortamı)."
               . ($toplam !== null ? " Sorgulanabilir sevkiyat sayısı: $toplam" : '');

        return ['basarili' => true, 'mesaj' => $mesaj, 'veri' => $veri];
    }

    // =================================================================
    // Siparişler (sevkiyat) — DOKÜMANDAN, canlı doğrulanmadı
    // =================================================================

    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $r = $this->omsIstek('list', array_merge($filtre, [
            'page'  => $this->sayfaNo($sayfa),   // 1 tabanlı
            'limit' => (int)$boyut
        ]), 'Sipariş Listele');

        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }
        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => $r['veri']['items'] ?? [],
            'toplam'      => (int)($r['veri']['totalCount'] ?? 0),
            'toplamSayfa' => (int)($r['veri']['pageCount'] ?? 0)
        ];
    }

    /**
     * Son $gunSayisi gün içinde güncellenen sevkiyatları yazar.
     * Liste updateAt'e göre azalan sıralanır; yanıttaki updatedAt sınırın altına düşünce durulur.
     */
    public function siparisleriSenkronla($gunSayisi = 2)
    {
        $sinir = time() - max(1, (int)$gunSayisi) * 86400;
        $adet = 0; $yeni = 0; $hatali = 0;
        $sayfa = 0;

        do {
            $l = $this->siparisleriListele(['sortByField' => 'updateAt', 'sortDirection' => 'desc'],
                                           $sayfa, self::SAYFA_BOYUTU);
            if (!$l['basarili']) {
                return ['basarili' => false, 'hata' => $l['hata'], 'adet' => $adet];
            }

            $eskiyeUlasti = false;
            foreach ($l['siparisler'] as $s) {
                $guncelleme = strtotime((string)($s['updatedAt'] ?? $s['createdAt'] ?? ''));
                if ($guncelleme && $guncelleme < $sinir) {
                    $eskiyeUlasti = true;
                    break;
                }
                $notr  = $this->siparisNotrle($s);
                $vardi = $this->db->fetchOne(
                    "SELECT 1 AS v FROM dbo.PazaryeriSiparisleri
                      WHERE PazaryeriSiparisleri_EntegrasyonKanallari_id = ? AND PazaryeriSiparisleri_DisId = ?",
                    [$this->kanalId, $notr['disId']]
                );
                if ($this->siparisKaydet($notr)) {
                    $adet++;
                    if (!$vardi) { $yeni++; }
                } else {
                    $hatali++;
                }
            }
            $sayfa++;
        } while (!$eskiyeUlasti && $sayfa < $l['toplamSayfa'] && $sayfa < self::MAKS_SAYFA);

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'yeni' => $yeni, 'hatali' => $hatali];
    }

    /** idefix sevkiyatı → nötr sipariş. Birim SEVKİYAT (disId = sevkiyat id, bölünmüş sipariş ayrı satır). */
    private function siparisNotrle(array $s)
    {
        $teslimat = $s['shippingAddress'] ?? [];
        $fatura   = $s['invoiceAddress'] ?? $teslimat;

        $kalemler = [];
        foreach ($s['items'] ?? [] as $it) {
            $fiyat = isset($it['discountedTotalPrice']) ? (float)$it['discountedTotalPrice']
                   : (isset($it['price']) ? (float)$it['price'] : null);
            $kalemler[] = [
                'disId'         => isset($it['id']) ? (string)$it['id'] : null,
                'barkod'        => $it['barcode'] ?? null,
                'stokKodu'      => ($it['merchantSku'] ?? null) ?: null,
                'disUrunId'     => ($it['erpId'] ?? null) ?: null,
                'urunAdi'       => $it['productName'] ?? null,
                'miktar'        => (int)($it['quantity'] ?? 1),
                'birimFiyat'    => $fiyat,
                'toplamTutar'   => $fiyat,
                'indirimTutar'  => (float)($it['platformDiscount'] ?? 0) + (float)($it['vendorDiscount'] ?? 0) ?: null,
                'kdvOran'       => isset($it['vatRate']) ? (float)$it['vatRate'] : null,
                'komisyonTutar' => isset($it['commissionAmount']) ? (float)$it['commissionAmount'] : null,
                'hamDurum'      => $it['itemStatus'] ?? null,
                'ham'           => $it
            ];
        }

        $alici = trim((string)($teslimat['fullName'] ?? (($teslimat['firstName'] ?? '') . ' ' . ($teslimat['lastName'] ?? ''))));

        return [
            'disId'          => (string)($s['id'] ?? ''),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => isset($s['id']) ? (string)$s['id'] : null,
            'durumKod'       => $this->siparisDurumKodu($s['status'] ?? ''),
            'hamDurum'       => $s['status'] ?? null,
            'toplamTutar'    => isset($s['discountedTotalPrice']) ? (float)$s['discountedTotalPrice']
                                : (isset($s['totalPrice']) ? (float)$s['totalPrice'] : null),
            'paraBirimi'     => 'TRY',
            'musteriAdi'     => ($s['customerContactName'] ?? null) ?: ($alici ?: null),
            'aliciAdi'       => $alici ?: null,
            'eposta'         => $s['customerContactMail'] ?? null,
            'telefon'        => ($teslimat['phone'] ?? null) ?: null,
            'tckn'           => ($s['customerTcNumber'] ?? null) ?: (($fatura['identificationNumber'] ?? null) ?: null),
            'vergiNo'        => ($fatura['taxNumber'] ?? null) ?: null,
            'vergiDairesi'   => ($fatura['taxOffice'] ?? null) ?: null,
            'il'             => $teslimat['city']   ?? null,
            'ilce'           => $teslimat['county'] ?? null,
            'semt'           => $teslimat['neighboorhood'] ?? ($teslimat['neighbourhood'] ?? null),
            'teslimatAdresi' => $teslimat,
            'faturaAdresi'   => $fatura + ['unvan' => ($fatura['company'] ?? null) ?: ($fatura['fullName'] ?? null)],
            'kargoFirmasi'   => $s['cargoCompany'] ?? null,
            'kargoTakipNo'   => ($s['cargoTrackingNumber'] ?? null) ?: null,
            'kargoBarkod'    => ($s['cargoTrackingUrl'] ?? null) ?: null,
            'siparisTarihi'  => $this->isoTarih($s['orderDate'] ?? ($s['createdAt'] ?? null)),
            'terminTarihi'   => $this->isoTarih($s['items'][0]['lastShipmentDate'] ?? null),
            'tahminiTeslim'  => $this->isoTarih($s['estimatedDeliveryDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /** idefix sevkiyat durumu (doküman) → yerel kod */
    private function siparisDurumKodu($durum)
    {
        switch ((string)$durum) {
            case 'created':
            case 'shipment_ready':      return 'yeni';
            case 'shipment_picking':
            case 'shipment_invoiced':
            case 'shipment_split':      return 'hazirlaniyor';
            case 'shipment_in_cargo':   return 'kargoda';
            case 'shipment_delivered':
            case 'shipment_approved':   return 'teslim';
            case 'shipment_cancelled':
            case 'shipment_unsupplied': return 'iptal';
            default:                    return 'diger';   // shipment_undeliver vb.
        }
    }

    // =================================================================
    // İadeler — DOKÜMANDAN, canlı doğrulanmadı
    // =================================================================

    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $r = $this->omsIstek('claim-list', array_merge($filtre, [
            'page'  => $this->sayfaNo($sayfa),   // 1 tabanlı
            'limit' => (int)$boyut
        ]), 'İade Listele');

        if (!$r['basarili']) {
            return ['basarili' => false, 'hata' => $r['hata'], 'iadeler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }
        return [
            'basarili'    => true,
            'hata'        => '',
            'iadeler'     => $r['veri']['items'] ?? [],
            'toplam'      => (int)($r['veri']['totalCount'] ?? 0),
            'toplamSayfa' => (int)($r['veri']['pageCount'] ?? 0)
        ];
    }

    /**
     * İadeleri yazar. Uçta sıralama parametresi belgelenmemiş; kayıt sayısı düşük olduğundan
     * tüm liste sayfalanır ($gunSayisi sözleşme gereği vardır, kullanılmaz).
     */
    public function iadeleriSenkronla($gunSayisi = 30)
    {
        $adet  = 0;
        $sayfa = 0;
        do {
            $l = $this->iadeleriListele([], $sayfa, self::SAYFA_BOYUTU);
            if (!$l['basarili']) {
                return ['basarili' => false, 'hata' => $l['hata'], 'adet' => $adet];
            }
            foreach ($l['iadeler'] as $i) {
                if ($this->iadeKaydet($this->iadeNotrle($i))) {
                    $adet++;
                }
            }
            $sayfa++;
        } while ($sayfa < $l['toplamSayfa'] && $sayfa < self::MAKS_SAYFA);

        return ['basarili' => true, 'hata' => '', 'adet' => $adet];
    }

    /** idefix iadesi → nötr iade (kalemler items[]) */
    private function iadeNotrle(array $i)
    {
        $kalemler = [];
        $ilk      = null;
        foreach ($i['items'] ?? [] as $it) {
            $ilk   = $ilk ?? $it;
            $sebep = $it['customerReason'] ?? ($it['vendorReason'] ?? ($it['platformReason'] ?? null));
            $kalemler[] = [
                'barkod'  => $it['barcode'] ?? null,
                'urunAdi' => $it['productName'] ?? null,
                'miktar'  => 1,
                'fiyat'   => isset($it['totalPrice']) ? (float)$it['totalPrice'] : null,
                'sebep'   => $sebep,
                'durum'   => $it['state'] ?? null,
            ];
        }

        $durum    = (string)($ilk['state'] ?? '');
        $aciklama = trim(implode(' | ', array_filter([
            $ilk['customerNote'] ?? '', $ilk['vendorNote'] ?? '', $ilk['declineRequestNote'] ?? ''
        ])));

        return [
            'disId'      => (string)($i['id'] ?? ''),
            'siparisNo'  => (string)($i['orderNumber'] ?? ''),
            'paketNo'    => ($i['cargoKey'] ?? null) ?: null,
            'durumKod'   => $this->iadeDurumKodu($durum),
            'hamDurum'   => $durum !== '' ? $durum : null,
            'sebep'      => $kalemler[0]['sebep'] ?? null,
            'aciklama'   => $aciklama !== '' ? $aciklama : null,
            'musteriAdi' => $i['customerName'] ?? null,
            'tutar'      => array_sum(array_map(fn($k) => (float)($k['fiyat'] ?? 0), $kalemler)) ?: null,
            'iadeTarihi' => $this->isoTarih($i['createdAt'] ?? null),
            'kalemler'   => $kalemler,
            'ham'        => $i
        ];
    }

    /** idefix iade durumu (doküman) → yerel kod */
    private function iadeDurumKodu($durum)
    {
        switch ($durum) {
            case 'ready':
            case 'in_cargo':
            case 'waiting_vendor_approve': return 'acik';
            case 'approved':               return 'onaylandi';
            case 'decline':
            case 'vendor_decline_request': return 'reddedildi';
            default:                       return 'diger';
        }
    }
}
