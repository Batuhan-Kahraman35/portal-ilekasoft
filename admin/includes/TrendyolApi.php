<?php
/**
 * Trendyol Marketplace API
 *
 * Ortak altyapı PazaryeriApi sınıfındadır. Bu sınıfta yalnızca Trendyol'a
 * özgü olanlar bulunur: uç yolları, yanıt ayrıştırma ve alan eşlemesi.
 * Veritabanına yazma, doğrulama, hata yönetimi ve durum takibi ortak katmanda.
 *
 * Ayarlar veritabanından okunur:
 *   Entegrasyonlar.Entegrasyonlar_AyarJSON               → {"BaseURL":"...","EntegrasyonReferansKodu":"..."}
 *   EntegrasyonKanallari.EntegrasyonKanallari_AyarJSON   → {"SaticiId":"...","ApiKey":"...","ApiSecret":"..."}
 *
 * Kimlik doğrulama: Basic base64(ApiKey:ApiSecret)
 * User-Agent zorunludur: "{SaticiId} - {EntegrasyonReferansKodu}"
 *
 * Portal kapsamı: bağlantı testi, sipariş, iade, paket durumu, kargo etiketi,
 * fatura linki bildirimi ve toplu istek (batch) takibi. Ürün/kategori/marka/
 * soru işlemleri bu sürümde yoktur.
 */

require_once __DIR__ . '/PazaryeriApi.php';

class TrendyolApi extends PazaryeriApi
{
    /** Sipariş sorgusunda tarih aralığı en fazla 2 hafta olabilir */
    const SIPARIS_GUN_SINIRI = 14;

    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('trendyol', $userId, $kanalId);
    }

    // =================================================================
    // Sözleşme
    // =================================================================

    protected function baseUrl($hizmet = 'varsayilan')
    {
        // Trendyol tüm hizmetleri tek adres üzerinden sunar; $hizmet yok sayılır.
        return rtrim($this->config['BaseURL'] ?? 'https://apigw.trendyol.com', '/');
    }

    protected function authHeaders()
    {
        $token = base64_encode(($this->config['ApiKey'] ?? '') . ':' . ($this->config['ApiSecret'] ?? ''));

        $referans  = trim($this->config['EntegrasyonReferansKodu'] ?? '');
        $saticiId  = trim((string)($this->config['SaticiId'] ?? ''));
        $userAgent = $referans !== ''
            ? $saticiId . ' - ' . $referans
            : $saticiId . ' - SelfIntegration';

        return [
            'Authorization: Basic ' . $token,
            'User-Agent: ' . $userAgent
        ];
    }

    protected function configDogrula()
    {
        if (empty($this->config['SaticiId'])) {
            return 'Trendyol kanal ayarlarında SaticiId tanımlı değil.';
        }
        if (empty($this->config['ApiKey']) || empty($this->config['ApiSecret'])) {
            return 'Trendyol kanal ayarlarında ApiKey / ApiSecret tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'SaticiId', 'etiket' => 'Satıcı ID (Cari ID)',
             'gizli' => false, 'zorunlu' => true,
             'aciklama' => 'Trendyol Satıcı Paneli > Entegrasyon Bilgileri'],
            ['anahtar' => 'ApiKey', 'etiket' => 'API Key',
             'gizli' => false, 'zorunlu' => true, 'aciklama' => ''],
            ['anahtar' => 'ApiSecret', 'etiket' => 'API Secret',
             'gizli' => true, 'zorunlu' => true, 'aciklama' => ''],
            ['anahtar' => 'EntegrasyonReferansKodu', 'etiket' => 'Entegrasyon Referans Kodu',
             'gizli' => false, 'zorunlu' => false,
             'aciklama' => 'User-Agent başlığında kullanılır: "{SaticiId} - {referans}"'],
        ];
    }

    /** Trendyol hata gövdesi: {"errors":[{"key":"...","message":"..."}]} */
    protected function hataMesajiCoz($httpKod, $veri, $hamYanit)
    {
        if ($httpKod === 401 || $httpKod === 403) {
            return 'HTTP ' . $httpKod . ': Yetkisiz erişim. API Key/Secret, Satıcı ID '
                 . 've User-Agent (Entegrasyon Referans Kodu) bilgilerini kontrol edin.';
        }
        return parent::hataMesajiCoz($httpKod, $veri, $hamYanit);
    }

    private function saticiId()
    {
        return rawurlencode((string)$this->config['SaticiId']);
    }

    /**
     * Sipariş uçlarının ortak öneki.
     * Sipariş, iade ve paket işlemlerinin tamamı /integration/order altındadır;
     * "/integration" öneki olmadan çağrılan uçlar HTTP 556 döndürür.
     */
    private function siparisYolu($ek = '')
    {
        return '/integration/order/sellers/' . $this->saticiId() . $ek;
    }

    /** Sipariş servisi dışındaki satıcı uçları (fatura linki vb.) */
    private function saticiYolu($ek = '')
    {
        return '/integration/sellers/' . $this->saticiId() . $ek;
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->istek('GET', '/integration/sellers/' . $this->saticiId() . '/addresses',
                              null, [], 'Bağlantı Testi');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        $adresler = $sonuc['veri']['supplierAddresses'] ?? [];
        return [
            'basarili' => true,
            'mesaj'    => 'Bağlantı başarılı. Tanımlı adres sayısı: ' . count($adresler),
            'veri'     => $sonuc['veri']
        ];
    }

    // =================================================================
    // Toplu istek (batch) takibi
    // =================================================================

    /**
     * Batch sonucunu sorgula; kayıt güncellemesini ortak katman yapar.
     * Trendyol batch sorgu ucu ürün servisi altındadır.
     */
    public function batchDurumGuncelle($batchId)
    {
        $batchId = trim((string)$batchId);
        if ($batchId === '') {
            return ['basarili' => false, 'hata' => 'Batch ID boş.'];
        }

        $sonuc = $this->istek('GET',
            '/integration/product/sellers/' . $this->saticiId()
                . '/products/batch-requests/' . rawurlencode($batchId),
            null, [], 'Batch Durum Sorgu');

        if (!$sonuc['basarili']) {
            $simdi = date('Y-m-d H:i:s');
            $this->db->update('PazaryeriBatchIstekleri', [
                'PazaryeriBatchIstekleri_SonKontrol'           => $simdi,
                'PazaryeriBatchIstekleri_GuncelleyenKullanici' => $this->userId,
                'PazaryeriBatchIstekleri_GuncellemeTarihi'     => $simdi
            ], [
                'PazaryeriBatchIstekleri_EntegrasyonKanallari_id' => $this->kanalId,
                'PazaryeriBatchIstekleri_BatchId'                 => $batchId
            ]);
            return ['basarili' => false, 'hata' => $sonuc['hata']];
        }

        $veri  = $sonuc['veri'] ?? [];
        $items = $veri['items'] ?? [];

        $kalemler = [];
        foreach ($items as $item) {
            $barkod = (string)($item['requestItem']['barcode'] ?? '');
            if ($barkod === '') {
                continue;
            }

            $basarili = strtoupper((string)($item['status'] ?? '')) === 'SUCCESS';
            $mesajlar = [];
            foreach ($item['failureReasons'] ?? [] as $sebep) {
                $mesajlar[] = is_array($sebep)
                    ? ($sebep['message'] ?? json_encode($sebep, JSON_UNESCAPED_UNICODE))
                    : (string)$sebep;
            }

            $kalemler[] = [
                'barkod'   => $barkod,
                'basarili' => $basarili,
                'hata'     => implode(' | ', $mesajlar)
            ];
        }

        // items boşken Trendyol henüz işlemeye başlamamıştır → beklemede kalır
        $tamamlandi = !empty($items)
                   && strtoupper((string)($veri['status'] ?? '')) !== 'PROCESSING';

        return $this->batchSonucIsle($batchId, [
            'tamamlandi' => $tamamlandi,
            'kalemler'   => $kalemler
        ]);
    }

    // =================================================================
    // Siparişler
    // =================================================================

    /**
     * @param array $filtre status, orderNumber, startDate/endDate (ms epoch)
     * @param int   $boyut  en fazla 200
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $query = array_merge($filtre, [
            'page' => (int)$sayfa,
            'size' => min(200, (int)$boyut)
        ]);

        $sonuc = $this->istek('GET', '/integration/order/sellers/' . $this->saticiId() . '/orders',
                              null, $query, 'Sipariş Listele');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'],
                    'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => $sonuc['veri']['content'] ?? [],
            'toplam'      => (int)($sonuc['veri']['totalElements'] ?? 0),
            'sayfa'       => (int)($sonuc['veri']['page'] ?? 0),
            'toplamSayfa' => (int)($sonuc['veri']['totalPages'] ?? 0)
        ];
    }

    /**
     * Siparişleri çek ve veritabanına yaz.
     * Tarih aralığı 2 haftalık dilimlere bölünür (Trendyol sınırı).
     */
    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $sayfa = 0;
            do {
                $sonuc = $this->siparisleriListele([
                    'startDate' => $dilim['bas'] * 1000,
                    'endDate'   => $dilim['bit'] * 1000
                ], $sayfa, 200);

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
                }

                foreach ($sonuc['siparisler'] as $s) {
                    if ($this->siparisKaydet($this->siparisNotrle($s))) {
                        $toplam++;
                    }
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0));
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /**
     * Komisyonları cari hesap ekstresinden (finance/che settlements) çekip sipariş kalemlerine yazar.
     * Sipariş servisi komisyon tutarı vermez. Kayıtlar yalnız TESLİM EDİLEN siparişler için oluşur;
     * tarih filtresi işlem (teslim) tarihidir, aralık en fazla 15 gün, sayfa boyutu 500/1000.
     * Eşleme: orderNumber = SiparisNo, barcode = Barkod. Aynı barkodlu birden fazla kalem varsa
     * komisyon kalem tutarına göre paylaştırılır.
     */
    public function komisyonlariSenkronla($gunSayisi = 30)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);

        // orderNumber|barcode => [tutar, oran]
        $komisyonlar = [];
        foreach ($this->tarihDilimle($baslangic, $bitis, 15) as $dilim) {
            $sayfa = 0;
            do {
                $sonuc = $this->istek('GET',
                    '/integration/finance/che/sellers/' . $this->saticiId() . '/settlements', null, [
                        'transactionType' => 'Sale',
                        'startDate'       => $dilim['bas'] * 1000,
                        'endDate'         => $dilim['bit'] * 1000,
                        'page'            => $sayfa,
                        'size'            => 1000
                    ], 'Komisyon (Cari Ekstre)');

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => 0];
                }

                foreach ($sonuc['veri']['content'] ?? [] as $r) {
                    if (empty($r['orderNumber']) || !isset($r['commissionAmount'])) {
                        continue;
                    }
                    $anahtar = $r['orderNumber'] . '|' . ($r['barcode'] ?? '');
                    $komisyonlar[$anahtar]['tutar'] = ($komisyonlar[$anahtar]['tutar'] ?? 0) + abs((float)$r['commissionAmount']);
                    $komisyonlar[$anahtar]['oran']  = isset($r['commissionRate']) ? (float)$r['commissionRate'] : null;
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['veri']['totalPages'] ?? 0));
        }

        $adet  = 0;
        $simdi = date('Y-m-d H:i:s');
        foreach ($komisyonlar as $anahtar => $k) {
            [$siparisNo, $barkod] = explode('|', $anahtar, 2);

            $kalemler = $this->db->fetchAll("
                SELECT c.PazaryeriSiparisKalemleri_id AS id, ISNULL(c.PazaryeriSiparisKalemleri_ToplamTutar, 0) AS tutar
                FROM dbo.PazaryeriSiparisKalemleri c
                INNER JOIN dbo.PazaryeriSiparisleri s
                        ON s.PazaryeriSiparisleri_id = c.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id
                WHERE s.PazaryeriSiparisleri_EntegrasyonKanallari_id = ?
                  AND s.PazaryeriSiparisleri_SiparisNo = ?
                  AND ISNULL(c.PazaryeriSiparisKalemleri_Barkod, '') = ?
            ", [$this->kanalId, $siparisNo, $barkod]) ?: [];

            if (!$kalemler) {
                continue;
            }

            $toplamTutar = array_sum(array_column($kalemler, 'tutar'));
            foreach ($kalemler as $kalem) {
                $pay = $toplamTutar > 0 ? (float)$kalem['tutar'] / $toplamTutar : 1 / count($kalemler);
                $this->db->update('PazaryeriSiparisKalemleri', [
                    'PazaryeriSiparisKalemleri_KomisyonTutar'       => round($k['tutar'] * $pay, 2),
                    'PazaryeriSiparisKalemleri_KomisyonOran'        => $k['oran'],
                    'PazaryeriSiparisKalemleri_GuncelleyenKullanici' => $this->userId,
                    'PazaryeriSiparisKalemleri_GuncellemeTarihi'    => $simdi
                ], ['PazaryeriSiparisKalemleri_id' => (int)$kalem['id']]);
                $adet++;
            }
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $adet, 'kayit' => count($komisyonlar)];
    }

    /** Trendyol siparişi → nötr sipariş */
    private function siparisNotrle(array $s)
    {
        $adres  = $s['shipmentAddress'] ?? ($s['invoiceAddress'] ?? []);
        $fatura = $s['invoiceAddress'] ?? [];
        $paket  = $s['shipmentPackages'][0] ?? [];

        $kalemler = [];
        foreach ($s['lines'] ?? [] as $line) {
            $kalemler[] = [
                'disId'         => (string)($line['id'] ?? ''),
                'barkod'        => $line['barcode'] ?? null,
                'stokKodu'      => $line['merchantSku'] ?? null,
                'disUrunId'     => (string)($line['productCode'] ?? ''),
                'urunAdi'       => $line['productName'] ?? null,
                'miktar'        => (int)($line['quantity'] ?? 1),
                'birimFiyat'    => $line['price'] ?? null,
                'toplamFiyat'   => $line['amount'] ?? null,
                'toplamTutar'   => $line['amount'] ?? null,
                'indirimTutar'  => $line['discount'] ?? null,
                'kdvOran'       => $line['vatBaseAmount'] ?? null,
                'komisyonOran'  => $line['commissionRate'] ?? null,
                'hamDurum'      => $line['orderLineItemStatusName'] ?? null,
                'ham'           => $line
            ];
        }

        $musteri = trim(($s['customerFirstName'] ?? '') . ' ' . ($s['customerLastName'] ?? ''));
        $alici   = trim(($adres['firstName'] ?? '') . ' ' . ($adres['lastName'] ?? ''));

        return [
            'disId'          => (string)($s['orderNumber'] ?? ($s['id'] ?? '')),
            'siparisNo'      => (string)($s['orderNumber'] ?? ''),
            'paketNo'        => (string)($s['shipmentPackageId'] ?? ($paket['id'] ?? '')),
            'durumKod'       => $this->siparisDurumKodu($s['status'] ?? ''),
            'hamDurum'       => $s['status'] ?? null,
            'toplamTutar'    => $s['totalPrice'] ?? null,
            'paraBirimi'     => $s['currencyCode'] ?? 'TRY',
            'musteriAdi'     => $musteri ?: null,
            'aliciAdi'       => $alici ?: null,
            'eposta'         => $s['customerEmail'] ?? null,
            'telefon'        => $adres['phone'] ?? null,
            'tckn'           => $s['tcIdentityNumber'] ?? null,
            'vergiNo'        => $fatura['taxNumber'] ?? null,
            'vergiDairesi'   => $fatura['taxOffice'] ?? null,
            'il'             => $adres['city']     ?? null,
            'ilce'           => $adres['district'] ?? null,
            'semt'           => $adres['neighborhood'] ?? null,
            'teslimatAdresi' => $adres,
            'faturaAdresi'   => $fatura,
            'kargoFirmasi'   => $s['cargoProviderName'] ?? null,
            'kargoTakipNo'   => $paket['cargoTrackingNumber'] ?? ($s['cargoTrackingNumber'] ?? null),
            'kargoBarkod'    => $s['cargoTrackingLink'] ?? null,
            'siparisTarihi'  => $this->msTarih($s['orderDate'] ?? null),
            'terminTarihi'   => $this->msTarih($s['agreedDeliveryDate'] ?? null),
            'tahminiTeslim'  => $this->msTarih($s['estimatedDeliveryEndDate'] ?? null),
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /** Trendyol sipariş durumu → yerel normalize kod */
    private function siparisDurumKodu($durum)
    {
        switch (strtolower((string)$durum)) {
            case 'created':    case 'awaiting':   return 'yeni';
            case 'picking':    case 'invoiced':   return 'hazirlaniyor';
            case 'shipped':                       return 'kargoda';
            case 'delivered':                     return 'teslim';
            case 'cancelled':  case 'unsupplied': return 'iptal';
            case 'returned':                      return 'iade';
            default:                              return 'diger';
        }
    }

    // =================================================================
    // Paket işlemleri (kargo etiketi, durum, fatura)
    // =================================================================

    /**
     * Paket statüsünü güncelle.
     * @param string $durum Picking | Invoiced
     * @param array  $kalemler [['lineId'=>int,'quantity'=>int], ...]
     */
    public function paketDurumGuncelle($paketId, $durum, array $kalemler = [], array $ek = [])
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket ID boş.'];
        }

        // NOT: storeFrontCode başlığı Trendyol tarafında geçersiz sayılıyor, gönderilmez.
        $sonuc = $this->istek('PUT',
            $this->siparisYolu('/shipment-packages/' . rawurlencode((string)$paketId)),
            ['status' => $durum, 'lines' => $kalemler, 'params' => (object)$ek],
            [], 'Paket Durum Güncelle');

        return [
            'basarili' => $sonuc['basarili'],
            'hata'     => $sonuc['hata'],
            'mesaj'    => $sonuc['basarili'] ? "Paket statüsü '{$durum}' olarak güncellendi." : ''
        ];
    }

    /**
     * e-Fatura / e-Arşiv bağlantısını Trendyol'a bildir.
     * POST /integration/sellers/{id}/seller-invoice-links (201; aynı pakete tekrar → 409)
     * Eski ".../shipment-packages/{pid}/invoices" yolu resmi dokümanda yok.
     *
     * @param array $fatura ['link'=>, 'numara'=>, 'tarih'=> ms epoch]
     */
    public function faturaLinkGonder($paketId, array $fatura)
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket ID boş.'];
        }

        $sonuc = $this->istek('POST', $this->saticiYolu('/seller-invoice-links'), [
            'invoiceLink'       => $fatura['link']   ?? '',
            'shipmentPackageId' => (int)$paketId,
            'invoiceNumber'     => $fatura['numara'] ?? null,
            'invoiceDateTime'   => isset($fatura['tarih']) ? (int)$fatura['tarih'] : null
        ], [], 'Fatura Linki Gönder');

        // 409: bu pakete link daha önce gönderilmiş — bildirim yerinde, başarı sayılır
        if (!$sonuc['basarili'] && (int)$sonuc['httpKod'] === 409) {
            return ['basarili' => true, 'hata' => '', 'onceden' => true];
        }
        return ['basarili' => $sonuc['basarili'], 'hata' => $sonuc['hata']];
    }

    /**
     * Pakete gönderilmiş fatura linkini sil (yeni link ancak bundan sonra gönderilebilir).
     * POST /integration/sellers/{id}/seller-invoice-links/delete (202)
     * gövde: serviceSourceId = paket ID, channelId = 1 (Trendyol), customerId
     *
     * @param array $siparisHam Siparişin ham verisi (customerId için)
     */
    public function faturaLinkSil($paketId, array $siparisHam = [])
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket ID boş.'];
        }
        $musteriId = (int)($siparisHam['customerId'] ?? 0);
        if ($musteriId <= 0) {
            return ['basarili' => false, 'hata' => 'Siparişin Trendyol müşteri numarası (customerId) bulunamadı.'];
        }

        $sonuc = $this->istek('POST', $this->saticiYolu('/seller-invoice-links/delete'), [
            'serviceSourceId' => (int)$paketId,
            'channelId'       => 1,
            'customerId'      => $musteriId
        ], [], 'Fatura Linki Sil');

        return ['basarili' => $sonuc['basarili'], 'hata' => $sonuc['hata']];
    }

    /** Ortak kargo etiketi talebi oluştur */
    public function kargoEtiketiOlustur($paketId)
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket ID boş.'];
        }

        $sonuc = $this->istek('POST',
            $this->siparisYolu('/shipment-packages/' . rawurlencode((string)$paketId) . '/common-label'),
            null, [], 'Kargo Etiketi Oluştur');

        return [
            'basarili' => $sonuc['basarili'] || in_array($sonuc['httpKod'], [200, 201, 202], true),
            'hata'     => $sonuc['hata']
        ];
    }

    /** Oluşturulan etiketin ZPL içeriğini çek */
    public function kargoEtiketiGetir($paketId)
    {
        if (!$paketId) {
            return ['basarili' => false, 'hata' => 'Paket ID boş.', 'zpl' => null];
        }

        $sonuc = $this->istek('GET',
            $this->siparisYolu('/shipment-packages/' . rawurlencode((string)$paketId) . '/common-label'),
            null, [], 'Kargo Etiketi Getir');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'zpl' => null];
        }

        // ZPL içeriği content / barcode / zpl alanlarından herhangi birinde gelebilir;
        // bazen liste olarak döner.
        $veri = $sonuc['veri'];
        $zpl  = $veri['content'] ?? ($veri['barcode'] ?? ($veri['zpl'] ?? null));
        if (!$zpl && !empty($veri[0]['content'])) {
            $zpl = $veri[0]['content'];
        }

        return [
            'basarili' => (bool)$zpl,
            'zpl'      => $zpl,
            'hata'     => $zpl ? '' : 'ZPL içeriği alınamadı.'
        ];
    }

    // =================================================================
    // İadeler
    // =================================================================

    public function iadeleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $query = array_merge($filtre, ['page' => (int)$sayfa, 'size' => (int)$boyut]);

        $sonuc = $this->istek('GET', $this->siparisYolu('/claims'), null, $query, 'İade Listele');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'], 'iadeler' => [], 'toplam' => 0];
        }

        $veri = $sonuc['veri'] ?? [];
        return [
            'basarili'    => true,
            'hata'        => '',
            'iadeler'     => $veri['content'] ?? [],
            'toplam'      => (int)($veri['totalElements'] ?? count($veri['content'] ?? [])),
            'toplamSayfa' => (int)($veri['totalPages'] ?? 1)
        ];
    }

    /** İadeleri çek ve veritabanına yaz */
    public function iadeleriSenkronla($gunSayisi = 30)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;

        foreach ($this->tarihDilimle($baslangic, $bitis, self::SIPARIS_GUN_SINIRI) as $dilim) {
            $sayfa = 0;
            do {
                $sonuc = $this->iadeleriListele([
                    'startDate' => $dilim['bas'] * 1000,
                    'endDate'   => $dilim['bit'] * 1000
                ], $sayfa, 50);

                if (!$sonuc['basarili']) {
                    return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
                }

                foreach ($sonuc['iadeler'] as $i) {
                    if ($this->iadeKaydet($this->iadeNotrle($i))) {
                        $toplam++;
                    }
                }

                $sayfa++;
            } while ($sayfa < (int)($sonuc['toplamSayfa'] ?? 0));
        }

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /** Trendyol iadesi → nötr iade */
    private function iadeNotrle(array $i)
    {
        $kalemler = [];
        foreach ($i['claimItems'] ?? [] as $item) {
            $kalemler[] = [
                'barkod'  => $item['barcode']     ?? ($item['orderLine']['barcode'] ?? null),
                'urunAdi' => $item['productName'] ?? ($item['orderLine']['productName'] ?? null),
                'miktar'  => (int)($item['quantity'] ?? 1),
                'fiyat'   => $item['price'] ?? ($item['orderLine']['price'] ?? null),
                'sebep'   => $item['claimReason']['name'] ?? ($item['claimReason'] ?? null)
            ];
        }

        $ilk     = $i['claimItems'][0] ?? [];
        $musteri = trim(($i['customerFirstName'] ?? '') . ' ' . ($i['customerLastName'] ?? ''));

        return [
            'disId'      => (string)($i['id'] ?? ($i['claimId'] ?? '')),
            'siparisNo'  => (string)($i['orderNumber'] ?? ''),
            'paketNo'    => (string)($i['orderShipmentPackageId'] ?? ''),
            'durumKod'   => $this->iadeDurumKodu($i['claimItems'][0]['claimItemStatus']['name'] ?? ($i['status'] ?? '')),
            'hamDurum'   => $i['claimItems'][0]['claimItemStatus']['name'] ?? ($i['status'] ?? null),
            'sebep'      => is_array($ilk['claimReason'] ?? null)
                                ? ($ilk['claimReason']['name'] ?? null)
                                : ($ilk['claimReason'] ?? null),
            'aciklama'   => $ilk['customerNote'] ?? ($ilk['customerExplanation'] ?? null),
            'musteriAdi' => $musteri ?: null,
            'tutar'      => $ilk['price'] ?? null,
            'iadeTarihi' => $this->msTarih($i['claimDate'] ?? ($i['createdDate'] ?? null)),
            'kalemler'   => $kalemler,
            'ham'        => $i
        ];
    }

    private function iadeDurumKodu($durum)
    {
        $d = strtolower((string)$durum);
        if (strpos($d, 'waiting') !== false || strpos($d, 'created') !== false) return 'acik';
        if (strpos($d, 'accept')  !== false || strpos($d, 'approved') !== false) return 'onaylandi';
        if (strpos($d, 'reject')  !== false)                                     return 'reddedildi';
        if (strpos($d, 'cancel')  !== false)                                     return 'iptal';
        return 'diger';
    }
}
