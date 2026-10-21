<?php
/**
 * Mirakl tabanlı pazaryerleri — ortak sınıf (Koçtaş ...)
 *
 * Mirakl, birçok perakendecinin kullandığı pazaryeri altyapısıdır. Her
 * perakendecinin kendi sunucusu vardır ama API (Mirakl Seller API) aynıdır.
 * Bu yüzden iş mantığı burada bir kez yazılır; pazaryeri sınıfı yalnız kodunu
 * taşır. Sunucu adresi koda gömülmez, Entegrasyonlar ayarındaki BaseURL'den okunur.
 *
 * Kimlik doğrulama: "Authorization: <API anahtarı>" (Bearer öneki YOK).
 * Anahtar satıcı panelinde sağ üst > Kişisel ayarlar > API anahtarı'ndan alınır.
 * Anahtar birden fazla mağazaya bağlıysa ShopId ile mağaza seçilir.
 *
 * Kullanılan uçlar:
 *   A01  GET /account  → bağlantı testi (mağaza bilgisi)
 *   OR11 GET /orders   → siparişler; offset/max (max ≤ 100) sayfalama, total_count
 *
 * Sipariş kimliği: order_id ("1100000000-A") paket bazlıdır, commercial_id
 * müşterinin gördüğü sipariş numarasıdır. Bir müşteri siparişi birden fazla
 * satıcıya bölündüğünde aynı commercial_id farklı harf ekleriyle gelir.
 *
 * Portal kapsamı: bağlantı testi ve sipariş senkronu. Sipariş onaylama (OR21),
 * kargo bildirimi (OR23/OR24) ve fatura yükleme (OR74) bu sürümde yoktur.
 */

require_once __DIR__ . '/PazaryeriApi.php';

abstract class MiraklApi extends PazaryeriApi
{
    /** OR11 sayfa boyutu üst sınırı */
    const SAYFA_BOYUTU = 100;

    // =================================================================
    // Sözleşme
    // =================================================================

    protected function baseUrl($hizmet = 'varsayilan')
    {
        return rtrim((string)$this->getConfig('BaseURL', ''), '/');
    }

    protected function authHeaders()
    {
        return ['Authorization: ' . trim((string)$this->getConfig('ApiKey', ''))];
    }

    protected function configDogrula()
    {
        if (empty($this->config['BaseURL'])) {
            return $this->pazaryeriKod . ' entegrasyon ayarlarında BaseURL tanımlı değil.';
        }
        if (empty($this->config['ApiKey'])) {
            return $this->pazaryeriKod . ' kanal ayarlarında ApiKey tanımlı değil.';
        }
        return '';
    }

    public static function kimlikAlanlari()
    {
        return [
            ['anahtar' => 'ApiKey', 'etiket' => 'API Anahtarı',
             'gizli' => true, 'zorunlu' => true,
             'aciklama' => 'Mirakl satıcı paneli > sağ üst > Kişisel ayarlar > API anahtarı'],
            ['anahtar' => 'ShopId', 'etiket' => 'Mağaza ID (Shop ID)',
             'gizli' => false, 'zorunlu' => false,
             'aciklama' => 'Yalnız anahtar birden fazla mağazaya bağlıysa gerekir'],
        ];
    }

    /** Anahtar birden fazla mağazaya bağlıysa her isteğe shop_id eklenir */
    protected function magazaParametresi()
    {
        $shopId = trim((string)$this->getConfig('ShopId', ''));
        return $shopId !== '' ? ['shop_id' => $shopId] : [];
    }

    // =================================================================
    // Bağlantı testi
    // =================================================================

    public function baglantiTest()
    {
        $sonuc = $this->istek('GET', '/account', null, $this->magazaParametresi(), 'Bağlantı Testi');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'mesaj' => $sonuc['hata']];
        }

        $v = $sonuc['veri'] ?? [];
        $mesaj = 'Bağlantı başarılı. Mağaza: ' . ($v['shop_name'] ?? '-')
               . ' · Teklif: ' . (int)($v['offers_count'] ?? 0)
               . ' · Sipariş: ' . (int)($v['orders_count'] ?? 0);

        // Hesap yanıtı banka bilgisi de içerir; çağırana yalnız özet döndürülür
        return ['basarili' => true, 'mesaj' => $mesaj, 'veri' => [
            'shop_id'      => $v['shop_id']      ?? null,
            'shop_name'    => $v['shop_name']    ?? null,
            'shop_state'   => $v['shop_state']   ?? null,
            'offers_count' => $v['offers_count'] ?? null,
            'orders_count' => $v['orders_count'] ?? null
        ]];
    }

    // =================================================================
    // Siparişler
    // =================================================================

    /**
     * Siparişleri listele (OR11).
     * Filtre: startDate / endDate → güncellenme tarihi aralığı (timestamp),
     * durumlar → order_state_codes (virgüllü).
     */
    public function siparisleriListele(array $filtre = [], $sayfa = 0, $boyut = 50)
    {
        $boyut = max(1, min(self::SAYFA_BOYUTU, (int)$boyut));

        $sorgu = $this->magazaParametresi() + [
            'offset' => (int)$sayfa * $boyut,
            'max'    => $boyut,
            'sort'   => 'dateCreated',
            'order'  => 'asc'
        ];

        if (!empty($filtre['startDate'])) {
            $sorgu['start_update_date'] = gmdate('Y-m-d\TH:i:s\Z', (int)$filtre['startDate']);
        }
        if (!empty($filtre['endDate'])) {
            $sorgu['end_update_date'] = gmdate('Y-m-d\TH:i:s\Z', (int)$filtre['endDate']);
        }
        if (!empty($filtre['durumlar'])) {
            $sorgu['order_state_codes'] = $filtre['durumlar'];
        }

        $sonuc = $this->istek('GET', '/orders', null, $sorgu, 'Sipariş Listele');

        if (!$sonuc['basarili']) {
            return ['basarili' => false, 'hata' => $sonuc['hata'],
                    'siparisler' => [], 'toplam' => 0, 'toplamSayfa' => 0];
        }

        $toplam = (int)($sonuc['veri']['total_count'] ?? 0);

        return [
            'basarili'    => true,
            'hata'        => '',
            'siparisler'  => $sonuc['veri']['orders'] ?? [],
            'toplam'      => $toplam,
            'toplamSayfa' => (int)ceil($toplam / $boyut)
        ];
    }

    /**
     * Son N günde OLUŞAN veya GÜNCELLENEN siparişleri çek ve kaydet.
     * Güncellenme tarihine göre süzüldüğü için eski siparişlerin durum
     * değişiklikleri (kargo, teslim, iptal) de yakalanır.
     */
    public function siparisleriSenkronla($gunSayisi = 14)
    {
        $bitis     = time();
        $baslangic = $bitis - ((int)$gunSayisi * 86400);
        $toplam    = 0;
        $sayfa     = 0;

        do {
            $sonuc = $this->siparisleriListele([
                'startDate' => $baslangic,
                'endDate'   => $bitis
            ], $sayfa, self::SAYFA_BOYUTU);

            if (!$sonuc['basarili']) {
                return ['basarili' => false, 'hata' => $sonuc['hata'], 'adet' => $toplam];
            }

            foreach ($sonuc['siparisler'] as $s) {
                if ($this->siparisKaydet($this->siparisNotrle($s))) {
                    $toplam++;
                }
            }

            $sayfa++;
        } while ($sayfa < $sonuc['toplamSayfa'] && $sayfa < 100);

        return ['basarili' => true, 'hata' => '', 'adet' => $toplam];
    }

    /** Mirakl siparişi → nötr sipariş */
    protected function siparisNotrle(array $s)
    {
        $ek       = $this->ekAlanlar($s['order_additional_fields'] ?? []);
        $musteri  = $s['customer'] ?? [];
        $teslimat = $musteri['shipping_address'] ?? [];
        $fatura   = $musteri['billing_address']  ?? [];

        // tax-number: 11 hane → TCKN (bireysel), 10 hane → VKN (kurumsal)
        $vergiNo = preg_replace('/\D/', '', (string)($ek['tax-number'] ?? ''));

        $kalemler = [];
        foreach ($s['order_lines'] ?? [] as $l) {
            $lek   = $this->ekAlanlar($l['order_line_additional_fields'] ?? []);
            $tutar = isset($l['total_price']) ? (float)$l['total_price'] : null;

            $kalemler[] = [
                'disId'         => (string)($l['order_line_id'] ?? ''),
                'barkod'        => $lek['product-ean-value'] ?? null,
                'stokKodu'      => $l['offer_sku']   ?? null,
                'disUrunId'     => (string)($l['product_sku'] ?? ''),
                'urunAdi'       => $l['product_title'] ?? null,
                'miktar'        => (int)($l['quantity'] ?? 1),
                'birimFiyat'    => isset($l['price_unit']) ? (float)$l['price_unit'] : null,
                'toplamTutar'   => $tutar,
                'kdvOran'       => isset($lek['product-tax-percentage']) ? (float)$lek['product-tax-percentage'] : null,
                // commission_fee KDV hariç; tutar raporlarla uyumlu olsun diye KDV dahil (total_commission) yazılır
                'komisyonOran'  => ($tutar && isset($l['commission_fee']))
                                   ? round((float)$l['commission_fee'] / $tutar * 100, 2) : null,
                'komisyonTutar' => isset($l['total_commission']) ? (float)$l['total_commission'] : null,
                'desi'          => isset($lek['total-deci']) ? (float)$lek['total-deci'] : null,
                'hamDurum'      => $l['order_line_state'] ?? null,
                'ham'           => $l
            ];
        }

        $adSoyad = function (array $a) {
            $ad = trim(trim((string)($a['firstname'] ?? '')) . ' ' . trim((string)($a['lastname'] ?? '')));
            return $ad !== '' ? $ad : null;
        };

        return [
            'disId'          => (string)($s['order_id'] ?? ''),
            'siparisNo'      => (string)($s['commercial_id'] ?? ''),
            'paketNo'        => (string)($s['order_id'] ?? ''),
            'durumKod'       => $this->siparisDurumKodu($s['order_state'] ?? ''),
            'hamDurum'       => $s['order_state'] ?? null,
            'toplamTutar'    => isset($s['total_price']) ? (float)$s['total_price'] : null,
            'paraBirimi'     => $s['currency_iso_code'] ?? 'TRY',
            'musteriAdi'     => $ek['invoice-title'] ?? $adSoyad($musteri),
            'aliciAdi'       => $adSoyad($teslimat),
            'eposta'         => null,   // customer_notification_email Mirakl'ın aktarma adresidir, müşterinin değil
            'telefon'        => $teslimat['phone'] ?? ($fatura['phone'] ?? null),
            'tckn'           => strlen($vergiNo) === 11 ? $vergiNo : null,
            'vergiNo'        => strlen($vergiNo) === 10 ? $vergiNo : null,
            'vergiDairesi'   => $ek['tax-office'] ?? null,
            'il'             => $teslimat['city']  ?? null,
            'ilce'           => $teslimat['state'] ?? null,
            'semt'           => $teslimat['street_2'] ?? null,
            'teslimatAdresi' => $teslimat,
            'faturaAdresi'   => $fatura,
            'kargoFirmasi'   => ($s['shipping_company'] ?? '') !== '' ? $s['shipping_company'] : null,
            'kargoTakipNo'   => ($s['shipping_tracking'] ?? '') !== '' ? $s['shipping_tracking'] : null,
            'desi'           => isset($ek['total-deci']) ? (float)$ek['total-deci'] : null,
            'siparisTarihi'  => $this->isoTarih($s['created_date'] ?? null),
            'terminTarihi'   => $this->isoTarih($s['shipping_deadline'] ?? null),
            'tahminiTeslim'  => null,
            'kalemler'       => $kalemler,
            'ham'            => $s
        ];
    }

    /** [{code, value}, ...] → [code => value] */
    protected function ekAlanlar(array $alanlar)
    {
        $sonuc = [];
        foreach ($alanlar as $a) {
            if (isset($a['code'])) {
                $sonuc[$a['code']] = $a['value'] ?? null;
            }
        }
        return $sonuc;
    }

    /** Mirakl sipariş durumu → portal durum kodu */
    protected function siparisDurumKodu($durum)
    {
        switch (strtoupper(trim((string)$durum))) {
            case 'STAGING':
            case 'WAITING_ACCEPTANCE':
            case 'WAITING_DEBIT':
            case 'WAITING_DEBIT_PAYMENT': return 'yeni';
            case 'SHIPPING':              return 'hazirlaniyor';
            case 'SHIPPED':
            case 'TO_COLLECT':            return 'kargoda';
            case 'RECEIVED':
            case 'CLOSED':                return 'teslim';
            case 'REFUSED':
            case 'CANCELED':              return 'iptal';
            case 'REFUNDED':              return 'iade';
            default:                      return 'diger';
        }
    }

    // =================================================================
    // Desteklenmeyenler
    //
    // İade (Mirakl'da iade = order_refunds / incident akışı), sipariş onaylama,
    // kargo ve fatura uçları bu sürümde yazılmadı; tanim_Pazaryerleri
    // JSON'unda kapalıdır.
    // =================================================================
}
