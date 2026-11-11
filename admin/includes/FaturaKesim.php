<?php
/**
 * Pazaryeri siparişinden fatura kesme servisi (Örnek Holding e-Fatura / e-Arşiv)
 *
 * Akış:  pazaryeriBelge() → onizle() → kes()
 *
 * Bağımlılık (projede bulunmalı):
 *   OrnekHoldingApi  kanaldan(), hazirMi(), getHata(), testMi(), ayar(), kanalBilgi(),
 *                 mukellefSorgula(), efaturaGonder(), earsivGonder(), sonSiraNumarasi()
 *   Database      fetchOne(), fetchAll(), execute(), insert(), update()
 *
 * Kurallar:
 *  - Fatura, siparişin pazaryeri kanalının bağlı olduğu firmadan kesilir
 *    (EntegrasyonKanallari_Firmalar_id). Seçilen Örnek Holding kanalı da aynı firmaya bağlı olmalıdır.
 *  - Seri: PazaryeriAyarlari EFaturaSeri / EArsivSeri (kanal → pazaryeri → genel) varsa o,
 *    yoksa Örnek Holding kanal JSON'undaki EFaturaSeri / EArsivSeri.
 *  - XSLT isteğe bağlı: Örnek Holding kanal JSON'unda XsltKlasoru varsa
 *    <xsltKok>/<klasor>/0_fatura.xslt | 0_arsivfatura.xslt gömülür.
 *  - Mükellef sorgusu hata verirse işlem DURUR (yanlışlıkla e-Arşiv kesilmez).
 *  - ETTN gönderimden ÖNCE Faturalar'a yazılır ve siparişe bağlanır. Sonucu belirsiz
 *    kalan gönderim tekrar edilirse aynı ETTN kullanılır; Örnek Holding aynı ETTN'yi ikinci
 *    kez kabul etmediği için çift fatura oluşmaz.
 *  - TEST kanalında Faturalar'a ve siparişe hiçbir şey yazılmaz.
 *
 * Faturalar_KesimDurumu (bu servisin yazdığı değerler) ve Faturalar_Durum:
 *   GONDERILIYOR  gönderim başladı, sonuç bilinmiyor   Durum = 0
 *   HATA          Örnek Holding reddetti (tekrar denenir)  Durum = 0
 *   TASLAK        taslak olarak yüklendi               Durum = 1
 *   GONDERILDI    kesildi                              Durum = 1
 * Örnek Holding senkronu kaydı ETTN + tür ile bulur, Durum = 0 kaydı pasif bırakır,
 * KesimDurumu'na dokunmaz.
 */

require_once __DIR__ . '/FaturaUbl.php';
if (!class_exists('OrnekHoldingApi') && is_file(__DIR__ . '/OrnekHoldingApi.php')) {
    require_once __DIR__ . '/OrnekHoldingApi.php';
}

class FaturaKesim
{
    const DURUM_GONDERILIYOR = 'GONDERILIYOR';
    const DURUM_HATA         = 'HATA';
    const DURUM_TASLAK       = 'TASLAK';
    const DURUM_GONDERILDI   = 'GONDERILDI';

    /** Nihai tüketici (TCKN bilinmiyor) */
    const ANONIM_TCKN = '11111111111';

    /** Kalem tutarı ile sipariş toplamı arasındaki kabul edilebilir fark (kuruş yuvarlama) */
    const TOPLAM_TOLERANS = 0.05;

    /** XSLT klasörlerinin kökü; proje farklıysa dışarıdan atanır */
    public static $xsltKok = null;

    private $db;
    private $userId;

    public function __construct($userId = null, $db = null)
    {
        if (!class_exists('OrnekHoldingApi')) {
            throw new RuntimeException('FaturaKesim: OrnekHoldingApi sınıfı yüklenmemiş.');
        }
        $this->db     = $db ?: Database::getInstance();
        $this->userId = $userId;
    }

    // =================================================================
    // Kanallar
    // =================================================================

    /**
     * Firmanın Örnek Holding kanalları. Pasif kanallar yalnız "Ortam":"TEST" ise listelenir.
     * Sıra: aktif → varsayılan → id
     */
    public function ornekholdingKanallari($firmaId)
    {
        $satirlar = $this->db->fetchAll("
            SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_Ad,
                   k.EntegrasyonKanallari_Varsayilan, k.EntegrasyonKanallari_Durum,
                   CASE WHEN ISJSON(k.EntegrasyonKanallari_AyarJSON) = 1
                        THEN JSON_VALUE(k.EntegrasyonKanallari_AyarJSON, '$.Ortam') END AS Ortam
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Ad = 'ÖRNEK HOLDİNG' AND e.Entegrasyonlar_Durum = 1
              AND k.EntegrasyonKanallari_Firmalar_id = ?
            ORDER BY k.EntegrasyonKanallari_Durum DESC, k.EntegrasyonKanallari_Varsayilan DESC,
                     k.EntegrasyonKanallari_id
        ", [(int)$firmaId]);

        $liste = [];
        foreach ($satirlar as $s) {
            $test = strtoupper((string)$s['Ortam']) === 'TEST';
            if ((int)$s['EntegrasyonKanallari_Durum'] !== 1 && !$test) continue;
            $liste[] = [
                'kanal_id'   => (int)$s['EntegrasyonKanallari_id'],
                'kanal_adi'  => $s['EntegrasyonKanallari_Ad'],
                'test'       => $test,
                'varsayilan' => (int)$s['EntegrasyonKanallari_Varsayilan'] === 1 && !$test,
            ];
        }
        return $liste;
    }

    /** Toplu kesim için firmanın canlı kanalı: varsayılan, yoksa ilk canlı kanal */
    public function varsayilanKanal($firmaId)
    {
        $canli = array_values(array_filter($this->ornekholdingKanallari($firmaId), fn($k) => !$k['test']));
        foreach ($canli as $k) {
            if ($k['varsayilan']) return $k['kanal_id'];
        }
        return $canli ? $canli[0]['kanal_id'] : null;
    }

    // =================================================================
    // Kaynak: pazaryeri siparişi
    // =================================================================

    /**
     * Pazaryeri siparişini nötr belgeye çevir.
     * @return array ['success', 'message', 'belge', 'uyarilar', 'mevcut']
     */
    public function pazaryeriBelge($siparisId)
    {
        $s = $this->db->fetchOne("
            SELECT s.*, k.EntegrasyonKanallari_Firmalar_id AS FirmaId,
                   CONVERT(VARCHAR(10), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS SiparisGunu
            FROM dbo.PazaryeriSiparisleri s
            INNER JOIN dbo.EntegrasyonKanallari k
                    ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
            WHERE s.PazaryeriSiparisleri_id = ?
        ", [(int)$siparisId]);

        if (!$s) {
            return ['success' => false, 'message' => 'Sipariş bulunamadı.'];
        }
        if ((int)$s['PazaryeriSiparisleri_Durum'] !== 1) {
            return ['success' => false, 'message' => 'Sipariş pasif (bölünmüş/kaldırılmış paket); fatura kesilemez.'];
        }
        if ($s['PazaryeriSiparisleri_DurumKod'] === 'iptal') {
            return ['success' => false, 'message' => 'İptal edilmiş sipariş faturalanamaz.'];
        }
        if (!$s['FirmaId']) {
            return ['success' => false, 'message' => 'Siparişin pazaryeri kanalı bir firmaya bağlı değil (Entegrasyon Yönetimi → kanal → Firma).'];
        }

        $firma = $this->firma((int)$s['FirmaId']);
        if (!$firma) {
            return ['success' => false, 'message' => 'Firma kaydı bulunamadı.'];
        }
        if ((int)$firma['durum'] !== 1) {
            return ['success' => false, 'message' => 'Siparişin firması pasif; fatura işlemi yapılamaz.'];
        }

        $kanalId     = (int)$s['PazaryeriSiparisleri_EntegrasyonKanallari_id'];
        $pazaryeriId = (int)$s['PazaryeriSiparisleri_Pazaryerleri_id'];
        $uyarilar    = [];

        // ---- Alıcı
        $fa  = json_decode($s['PazaryeriSiparisleri_FaturaAdresi'] ?? '', true) ?: [];
        $ta  = json_decode($s['PazaryeriSiparisleri_TeslimatAdresi'] ?? '', true) ?: [];
        $adr = $fa ?: $ta;

        $ad    = trim((string)($adr['firstName'] ?? ''));
        $soyad = trim((string)($adr['lastName'] ?? ''));
        $tamAd = trim($ad . ' ' . $soyad)
              ?: trim((string)($adr['fullName'] ?? ''))
              ?: (string)($s['PazaryeriSiparisleri_AliciAdi'] ?: $s['PazaryeriSiparisleri_MusteriAdi']);
        if ($ad === '' && $tamAd !== '') {
            [$ad, $soyad] = self::adSoyadAyir($tamAd);
        }

        $vergiNo = preg_replace('/\D/', '', (string)$s['PazaryeriSiparisleri_VergiNo']);
        $tckn    = preg_replace('/\D/', '', (string)$s['PazaryeriSiparisleri_TCKN']);

        if (strlen($vergiNo) === 10) {
            $kimlik = $vergiNo; $kimlikTur = 'VKN';
            $unvan  = trim((string)($adr['company'] ?? $adr['unvan'] ?? '')) ?: $tamAd;
        } elseif (strlen($vergiNo) === 11) {
            // Şahıs şirketi: vergi no sahibinin TCKN'si; kimlikle örtüşen ad firma adıdır
            $kimlik = $vergiNo; $kimlikTur = 'TCKN';
            $firmaAdi = trim((string)($adr['company'] ?? $adr['unvan'] ?? ''));
            $unvan = $firmaAdi ?: $tamAd;
            if ($firmaAdi !== '') {
                [$ad, $soyad] = self::adSoyadAyir($firmaAdi);
            }
        } elseif (strlen($tckn) === 11) {
            $kimlik = $tckn; $kimlikTur = 'TCKN'; $unvan = $tamAd;
        } else {
            $kimlik = self::ANONIM_TCKN; $kimlikTur = 'TCKN'; $unvan = $tamAd;
            $uyarilar[] = 'Alıcı kimlik numarası yok; nihai tüketici (' . self::ANONIM_TCKN . ') olarak kesilecek.';
        }

        // Adres anahtarları pazaryerine göre değişir: Trendyol/Çiçeksepeti ham İngilizce
        // (address1, neighborhood, postalCode), Hepsiburada nötr Türkçe (adres, semt, postaKodu),
        // n11 tek alan (address).
        $il   = (string)($adr['city'] ?? $adr['il'] ?? $s['PazaryeriSiparisleri_Il']);
        $ilce = (string)($adr['district'] ?? $adr['ilce'] ?? $s['PazaryeriSiparisleri_Ilce']);
        $vd   = trim((string)($s['PazaryeriSiparisleri_VergiDairesi'] ?: ($adr['taxOffice'] ?? '')));

        $adresSatiri = trim(preg_replace('/\s+/u', ' ', ($adr['address1'] ?? '') . ' ' . ($adr['address2'] ?? '')))
                    ?: trim((string)($adr['adres'] ?? ''))
                    ?: trim(preg_replace('/\s+/u', ' ', (string)($adr['address'] ?? '')))
                    ?: trim(preg_replace('/\s+/u', ' ', (string)($adr['fullAddress'] ?? '')));
        $mahalle = trim((string)($adr['neighborhood'] ?? $adr['semt'] ?? $s['PazaryeriSiparisleri_Semt'] ?? ''));
        if ($mahalle !== '' && mb_stripos($adresSatiri, $mahalle, 0, 'UTF-8') === false) {
            $adresSatiri = trim($adresSatiri . ' ' . $mahalle);
        }
        $postaKodu = preg_replace('/\D/', '', (string)($adr['postalCode'] ?? $adr['postaKodu'] ?? ''));

        // Örnek Holding portalı pazaryeri faturalarında alıcıyı büyük harfle yazar
        $alici = [
            'kimlik'        => $kimlik,
            'kimlik_tur'    => $kimlikTur,
            'unvan'         => self::buyukHarf($unvan),
            'ad'            => self::buyukHarf($ad),
            'soyad'         => self::buyukHarf($soyad),
            'vergi_dairesi' => $kimlik === self::ANONIM_TCKN ? '' : self::buyukHarf($vd),
            'adres'         => self::buyukHarf($adresSatiri),
            'posta_kodu'    => $postaKodu,
            'il'            => self::buyukHarf($il),
            'ilce'          => self::buyukHarf($ilce),
            'ulke'          => 'TÜRKİYE',
            'telefon'       => '',
            'eposta'        => (string)($s['PazaryeriSiparisleri_Eposta'] ?? ''),
        ];
        if ($kimlikTur === 'VKN' && $vd === '') {
            $uyarilar[] = 'Kurumsal alıcının vergi dairesi boş.';
        }

        // ---- Kalemler (KDV: kalem → eşleşen ürün/hizmet kartı; varsayılan oran uygulanmaz)
        $kalemSatirlar = $this->db->fetchAll("
            SELECT k.PazaryeriSiparisKalemleri_UrunAdi, k.PazaryeriSiparisKalemleri_Miktar,
                   k.PazaryeriSiparisKalemleri_BirimFiyat, k.PazaryeriSiparisKalemleri_ToplamTutar,
                   k.PazaryeriSiparisKalemleri_IndirimTutar,
                   COALESCE(k.PazaryeriSiparisKalemleri_KdvOran, kd.kdv_oran) AS KdvOran
            FROM dbo.PazaryeriSiparisKalemleri k
            LEFT JOIN dbo.Urun_Hizmet u ON u.urun_hizmet_id = k.PazaryeriSiparisKalemleri_Urun_Hizmet_id
            LEFT JOIN dbo.KDV_Tanimlari kd ON kd.kdv_id = u.urun_hizmet_kdv_id
            WHERE k.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = ?
              AND k.PazaryeriSiparisKalemleri_Durum = 1
            ORDER BY k.PazaryeriSiparisKalemleri_id
        ", [(int)$siparisId]);

        if (!$kalemSatirlar) {
            return ['success' => false, 'message' => 'Siparişin kalemi yok; önce siparişleri yeniden çekin.'];
        }

        $siparisToplam = (float)$s['PazaryeriSiparisleri_ToplamTutar'];
        [$brutler, $yontem] = self::kalemTutarlari($kalemSatirlar, $siparisToplam);
        if ($brutler === null) {
            return ['success' => false, 'message' => 'Kalem tutarları sipariş toplamıyla ('
                . number_format($siparisToplam, 2, ',', '.') . ') tutmuyor; fatura kesilmedi.'];
        }

        // Faturadaki kalem adı: PazaryeriAyarlari 'FaturaKalemAdi'; yoksa pazaryerindeki ürün adı
        $kalemAdi = $this->pazaryeriAyar('FaturaKalemAdi', $kanalId, $pazaryeriId);

        $kalemler = [];
        foreach ($kalemSatirlar as $i => $k) {
            // Varsayılan KDV oranı BİLEREK yok: yanlış oranla kesilen fatura iptal/iade gerektirir
            $oran = $k['KdvOran'];
            if ($oran === null || $oran === '' || !is_numeric($oran)) {
                return ['success' => false, 'message' => 'KDV oranı bilinmeyen kalem var: ' . $k['PazaryeriSiparisKalemleri_UrunAdi']
                    . '. Pazaryeri KDV göndermiyor; kalemi KDV oranı tanımlı bir ürün/hizmet kartıyla eşleştirin.'];
            }
            if ((float)$oran <= 0) {
                // %0 KDV istisna kodu (TaxExemptionReasonCode) ister; bu servis desteklemiyor
                return ['success' => false, 'message' => '%0 KDV\'li kalem var (' . $k['PazaryeriSiparisKalemleri_UrunAdi']
                    . '); istisnalı fatura bu ekrandan kesilemez.'];
            }
            $kalemler[] = self::kalem(
                $kalemAdi ?: ($k['PazaryeriSiparisKalemleri_UrunAdi'] ?: 'Ürün'),
                (float)$k['PazaryeriSiparisKalemleri_Miktar'] ?: 1,
                $brutler[$i],
                (float)$oran
            );
        }

        $pazaryeri = $this->db->fetchOne("
            SELECT Pazaryerleri_Ad FROM dbo.tanim_Pazaryerleri WHERE Pazaryerleri_id = ?
        ", [$pazaryeriId]);

        $belge = [
            'kaynak_tip'     => 'pazaryeri',
            'kaynak_id'      => (int)$siparisId,
            'kanal_id'       => $kanalId,
            'pazaryeri_id'   => $pazaryeriId,
            'firma'          => $firma,
            'alici'          => $alici,
            'kalemler'       => $kalemler,
            'toplam'         => self::toplamlar($kalemler),
            'para'           => $s['PazaryeriSiparisleri_ParaBirimi'] ?: 'TRY',
            'siparis_no'     => $s['PazaryeriSiparisleri_SiparisNo'] ?: $s['PazaryeriSiparisleri_DisId'],
            'siparis_tarihi' => $s['SiparisGunu'],
            'pazaryeri'      => $pazaryeri['Pazaryerleri_Ad'] ?? '',
            // Örnek Holding portal faturasında not / sipariş referansı yok; şablon bunları ayrı satır basar
            'notlar'         => [],
            'tutar_yontemi'  => $yontem,
        ];

        return [
            'success'  => true,
            'belge'    => $belge,
            'uyarilar' => $uyarilar,
            'mevcut'   => $this->mevcutFatura($s['PazaryeriSiparisleri_Faturalar_id']),
        ];
    }

    /**
     * Sipariş toplamını tutturan kalem tutarı hesabını seç.
     * Pazaryerleri alanları farklı anlamlarla doldurur (birim/satır, indirimli/indirimsiz);
     * tahmin etmek yerine toplamla eşleşen yorum kullanılır.
     *
     * @return array [float[]|null KDV dahil satır tutarları, string yöntem]
     */
    private static function kalemTutarlari(array $satirlar, $siparisToplam)
    {
        $m = fn($k) => (float)$k['PazaryeriSiparisKalemleri_Miktar'];
        $b = fn($k) => (float)$k['PazaryeriSiparisKalemleri_BirimFiyat'];
        $t = fn($k) => (float)$k['PazaryeriSiparisKalemleri_ToplamTutar'];
        $i = fn($k) => (float)$k['PazaryeriSiparisKalemleri_IndirimTutar'];

        $yontemler = [
            'birim fiyat × adet'     => fn($k) => $b($k) * $m($k),
            'satır tutarı'           => fn($k) => $t($k),
            'satır − indirim'        => fn($k) => $t($k) - $i($k),
            'satır × adet − indirim' => fn($k) => $t($k) * $m($k) - $i($k),
        ];

        foreach ($yontemler as $ad => $f) {
            $tutarlar = array_map(fn($k) => round($f($k), 2), $satirlar);
            if (min($tutarlar) <= 0) continue;
            if (abs(array_sum($tutarlar) - $siparisToplam) <= self::TOPLAM_TOLERANS) {
                return [$tutarlar, $ad];
            }
        }
        return [null, ''];
    }

    // =================================================================
    // Önizleme ve kesim
    // =================================================================

    /**
     * Mükellef sorgusu yaparak ne kesileceğini gösterir; hiçbir kayıt yazmaz.
     */
    public function onizle(array $belge, $kanalId)
    {
        [$api, $hata] = $this->api($belge, $kanalId);
        if (!$api) {
            return ['success' => false, 'message' => $hata];
        }

        $tur = $this->belgeTuru($api, $belge);
        if (!$tur['success']) {
            return $tur;
        }

        $belgeTuru = $tur['earsiv'] ? 'EARSIV' : 'EFATURA';
        $seri   = $this->seri($api, $belge, $tur['earsiv']);
        $yontem = $this->numaraYontemi($api);
        $xslt   = $this->xslt($api, $tur['earsiv']);

        // Sıralıda kesin kesimde kullanılacak numara (kilitsiz tahmin; kesimde yeniden hesaplanır)
        $tahmini = '';
        $uyarilar = [];
        if ($yontem !== 'sirali') {
            $uyarilar[] = 'NumaraYontemi "sirali" değil; kesin kesim yapılamaz (yalnız taslak).';
        }
        if (!preg_match('/^[A-Z0-9]{3}$/', $seri)) {
            $uyarilar[] = ($tur['earsiv'] ? 'EArsivSeri' : 'EFaturaSeri') . ' tanımlı değil veya 3 karakter değil.';
        } elseif ($yontem === 'sirali') {
            $s = $this->sonrakiSira($api, $belge, $belgeTuru, $seri, date('Y'));
            $tahmini = $s['success'] ? $seri . date('Y') . str_pad((string)$s['sira'], 9, '0', STR_PAD_LEFT) : '';
            if (!$s['success']) {
                $uyarilar[] = 'Son numara okunamadı: ' . $s['message'];
            }
        }
        if (!$xslt['success']) {
            $uyarilar[] = $xslt['message'];
        }

        return [
            'success'        => true,
            'belge_turu'     => $belgeTuru,
            'alias'          => $tur['alias'],
            'gib_unvan'      => $tur['unvan'],
            'seri'           => $seri,
            'numara_yontemi' => $yontem,
            'tahmini_numara' => $tahmini,
            'xslt'           => $xslt['success'] ? $xslt['ad'] : '',
            'kanal'          => $api->kanalBilgi(),
            'uyarilar'       => $uyarilar,
        ];
    }

    /**
     * @param bool $taslak         true: e-Arşiv DRAFT / e-Fatura LoadInvoice (GİB'e gitmez)
     * @param bool $belirsizTekrar Sonucu belirsiz (GONDERILIYOR) kalan gönderimi aynı ETTN ile tekrarla
     */
    public function kes(array $belge, $kanalId, $taslak = true, $belirsizTekrar = false)
    {
        [$api, $hata] = $this->api($belge, $kanalId);
        if (!$api) {
            return ['success' => false, 'message' => $hata];
        }

        $tur = $this->belgeTuru($api, $belge);
        if (!$tur['success']) {
            return $tur;
        }
        $earsiv = $tur['earsiv'];

        $seri = $this->seri($api, $belge, $earsiv);
        if (!preg_match('/^[A-Z0-9]{3}$/', $seri)) {
            return ['success' => false, 'message' => ($earsiv ? 'EArsivSeri' : 'EFaturaSeri')
                . ' tanımlı değil veya 3 karakter değil (Örnek Holding kanal ayarı ya da Pazaryeri Ayarları).'];
        }

        $xslt = $this->xslt($api, $earsiv);
        if (!$xslt['success']) {
            return $xslt;
        }

        // Numaralandırma (kanal ayarı NumaraYontemi):
        //   sirali : max(Örnek Holding, sistem) + 1, cbc:ID'ye yazılır — kesin kesimde ZORUNLU
        //   seri   : yalnız taslakta (yer tutucu; taslak numara tüketmez)
        // Son numara Örnek Holding'den sorgulanmadan kesin fatura kesilmez (çakışma riski).
        if (!$taslak && $this->numaraYontemi($api) !== 'sirali') {
            return ['success' => false, 'message' => 'Örnek Holding kanalında NumaraYontemi "sirali" değil. '
                . 'Son fatura numarası sorgulanmadan kesin fatura kesilmez; kanal ayarına NumaraYontemi=sirali ekleyin.'];
        }
        $sirali = !$taslak;

        // Hesaplama → kayıt → gönderim aynı seride tek sırada yürür
        $kilit = $sirali ? 'ornekholding_numara_' . $api->kanalBilgi()['kanal_id'] . '_' . $seri : null;
        if ($kilit && !$this->kilitAl($kilit)) {
            return ['success' => false, 'message' => 'Başka bir fatura numaralandırılıyor; birkaç saniye sonra tekrar deneyin.'];
        }

        try {
            return $this->kesGonder($api, $belge, $tur, $seri, $xslt, $taslak, $belirsizTekrar, $sirali);
        } finally {
            if ($kilit) {
                $this->kilitBirak($kilit);
            }
        }
    }

    /** kes() gövdesi: kayıt hazırlama, numara, UBL, gönderim (kilit dışarıda) */
    private function kesGonder($api, array $belge, array $tur, $seri, array $xslt, $taslak, $belirsizTekrar, $sirali)
    {
        $test      = $api->testMi();
        $earsiv    = $tur['earsiv'];
        $belgeTuru = $earsiv ? 'EARSIV' : 'EFATURA';
        $kanalId   = (int)$api->kanalBilgi()['kanal_id'];

        // ---- Çift kesim koruması (yalnız canlı)
        $faturaId = null;
        $ettn     = FaturaUbl::ettn();
        $oncekiNo = '';

        if (!$test) {
            $hazirlik = $this->kayitHazirla($belge, $kanalId, $belgeTuru, $ettn, $belirsizTekrar);
            if (!$hazirlik['success']) {
                return $hazirlik;
            }
            $faturaId = $hazirlik['fatura_id'];
            $ettn     = $hazirlik['ettn'];
            $oncekiNo = $hazirlik['onceki_no'] ?? '';
        }

        $yil   = date('Y');
        $desen = '/^' . preg_quote($seri, '/') . $yil . '(\d{9})$/';

        // ---- Sıralı numara
        if ($sirali) {
            if ($belirsizTekrar && preg_match($desen, $oncekiNo, $m) && (int)$m[1] > 0) {
                // Sonucu belirsiz gönderim: aynı ETTN + aynı numara
                $no = $oncekiNo;
            } else {
                $sonraki = $this->sonrakiSira($api, $belge, $belgeTuru, $seri, $yil, $faturaId);
                if (!$sonraki['success']) {
                    if ($faturaId) {
                        $this->kayitGuncelle($faturaId, [
                            'Faturalar_KesimDurumu'   => self::DURUM_HATA,
                            'Faturalar_DurumAciklama' => mb_substr('Numara hesaplanamadı: ' . $sonraki['message'], 0, 255),
                        ]);
                    }
                    return ['success' => false, 'message' => 'Son fatura numarası Örnek Holding\'den okunamadı: ' . $sonraki['message']];
                }
                $no = $seri . $yil . str_pad((string)$sonraki['sira'], 9, '0', STR_PAD_LEFT);
            }
        } else {
            $no = FaturaUbl::yerTutucuNo($seri);
        }

        // ---- Gönder (sıralıda "numara kullanılmış" hatasında +1 ile en fazla 3 deneme)
        $deneme = 0;
        while (true) {
            $deneme++;
            if ($faturaId && $sirali) {
                // Numara gönderimden önce kaydedilir: sonuç belirsiz kalırsa aynı numarayla tekrar edilir
                $this->kayitGuncelle($faturaId, ['Faturalar_FaturaNo' => $no]);
            }

            $ubl = $this->ublUret($api, $belge, $xslt, $earsiv, $ettn, $no);
            // Sıralıda numara cbc:ID'dedir; SERI/SERI_PREFIX gönderilmez
            $gonderSeri = $sirali ? '' : $seri;
            $sonuc = $earsiv
                ? $api->earsivGonder($ubl, $ettn, $gonderSeri, $taslak)
                : $api->efaturaGonder($ubl, $ettn, $belge['alici']['kimlik'], $tur['alias'], $gonderSeri, $taslak);

            if (!$sonuc['success'] && $sirali && $deneme < 3 && self::numaraCakismasi($sonuc['message'])
                && preg_match($desen, $no, $m)) {
                $no = $seri . $yil . str_pad((string)((int)$m[1] + 1), 9, '0', STR_PAD_LEFT);
                continue;
            }
            break;
        }

        if (!$sonuc['success']) {
            if ($faturaId) {
                // Bağlantı hatasında sonuç bilinmez; kayıt GONDERILIYOR kalır
                $belirsiz = mb_stripos((string)$sonuc['message'], 'Bağlantı hatası') !== false;
                $this->kayitGuncelle($faturaId, [
                    'Faturalar_KesimDurumu'   => $belirsiz ? self::DURUM_GONDERILIYOR : self::DURUM_HATA,
                    'Faturalar_DurumAciklama' => mb_substr((string)$sonuc['message'], 0, 255),
                ]);
            }
            return ['success' => false, 'message' => $sonuc['message'], 'ettn' => $ettn,
                    'fatura_no' => $sirali ? $no : ''];
        }

        $donenNo = (string)($sonuc['fatura_no'] ?? '');
        $uyari = '';
        if (!$taslak && $donenNo === '') {
            $uyari = 'Örnek Holding fatura numarası döndürmedi; portaldan kontrol edin.';
        } elseif (!$taslak && !$sirali && $donenNo === FaturaUbl::yerTutucuNo($seri)) {
            $uyari = 'Dönen numara yer tutucu ile aynı; Örnek Holding seriden numara atamamış olabilir.';
        } elseif ($sirali && $donenNo !== '' && $donenNo !== $no) {
            $uyari = 'Örnek Holding farklı numara döndürdü (' . $donenNo . '); gönderilen: ' . $no . '.';
        }
        $no = $donenNo !== '' ? $donenNo : $no;

        if ($faturaId) {
            $this->kayitGuncelle($faturaId, [
                'Faturalar_FaturaNo'      => $no !== '' ? mb_substr($no, 0, 50) : null,
                'Faturalar_KesimDurumu'   => $taslak ? self::DURUM_TASLAK : self::DURUM_GONDERILDI,
                'Faturalar_DurumAciklama' => $uyari !== '' ? mb_substr($uyari, 0, 255) : null,
                'Faturalar_Durum'         => 1,
            ]);
        }

        return [
            'success'    => true,
            'message'    => ($test ? '[TEST] ' : '') . ($earsiv ? 'e-Arşiv' : 'e-Fatura')
                          . ($taslak ? ' taslağı yüklendi.' : ' kesildi.'),
            'belge_turu' => $belgeTuru,
            'fatura_id'  => $faturaId,
            'fatura_no'  => $no,
            'ettn'       => $ettn,
            'web_key'    => $sonuc['web_key'] ?? '',
            'taslak'     => $taslak,
            'test'       => $test,
            'uyari'      => $uyari,
        ];
    }

    /** Belge dizisinden UBL (numara dışarıdan) */
    private function ublUret($api, array $belge, array $xslt, $earsiv, $ettn, $no)
    {
        $f = $belge['firma'];
        return FaturaUbl::olustur([
            'ettn'           => $ettn,
            'no'             => $no,
            'earsiv'         => $earsiv,
            'senaryo'        => $earsiv ? 'EARSIVFATURA' : 'TEMELFATURA',
            'tip'            => 'SATIS',
            'tarih'          => date('Y-m-d'),
            'saat'           => date('H:i:s'),
            'para'           => $belge['para'],
            'notlar'         => $belge['notlar'],
            // Kanal ayarları: EArsivGonderimSekli (ELEKTRONIK|KAGIT), OdemeKodu, BankaHesaplari
            'gonderim_sekli' => strtoupper((string)$api->ayar('EArsivGonderimSekli', 'ELEKTRONIK')),
            'odeme'          => [
                'kod'      => (string)$api->ayar('OdemeKodu', '46'),
                'hesaplar' => is_array($api->ayar('BankaHesaplari')) ? $api->ayar('BankaHesaplari') : [],
            ],
            'xslt_ad'        => $xslt['ad'],
            'xslt_icerik'    => $xslt['icerik'],
            'satici'         => [
                // Kanal VKN'si (TEST hesabında test hesabınınki), yoksa firmanın
                'vkn'           => $api->ayar('VKN') ?: preg_replace('/\D/', '', (string)$f['vergi_no']),
                'unvan'         => $f['unvan'],
                'vergi_dairesi' => $f['vergi_dairesi'],
                'adres'         => $f['adres'],
                'il'            => $f['il'],
                'ilce'          => $f['ilce'],
                'telefon'       => $f['telefon'],
                'eposta'        => $f['eposta'],
            ],
            'alici'          => $belge['alici'],
            'kalemler'       => $belge['kalemler'],
            'toplam'         => $belge['toplam'],
        ]);
    }

    /**
     * Sıradaki numara = max(Örnek Holding'de o yıl kullanılan en büyük, sistemde ayrılmış en büyük) + 1.
     * Sistem tarafı: tür ayırt edilmeden aynı önekli, hata ile bitmemiş giden numaralar.
     * e-Fatura ve e-Arşiv aynı seriyi kullanıyorsa Örnek Holding'de iki tür de taranır.
     */
    private function sonrakiSira($api, array $belge, $belgeTuru, $seri, $yil, $haricFaturaId = null)
    {
        $turler = [$belgeTuru];
        $digerTur = $belgeTuru === 'EARSIV' ? 'EFATURA' : 'EARSIV';
        if ($this->seri($api, $belge, $digerTur === 'EARSIV') === $seri) {
            $turler[] = $digerTur;
        }

        $uzak = 0;
        foreach ($turler as $t) {
            $r = $api->sonSiraNumarasi($t, $seri, (int)$yil);
            if (!$r['success']) {
                return $r;
            }
            $uzak = max($uzak, (int)$r['sira']);
        }

        $yerel = $this->db->fetchOne("
            SELECT MAX(TRY_CAST(RIGHT(Faturalar_FaturaNo, 9) AS INT)) AS sira
            FROM dbo.Faturalar
            WHERE Faturalar_Yon = 'GIDEN'
              AND Faturalar_FaturaNo LIKE ? AND LEN(Faturalar_FaturaNo) = 16
              AND ISNULL(Faturalar_KesimDurumu, '') <> ?
              AND Faturalar_id <> ?
        ", [$seri . $yil . '%', self::DURUM_HATA, (int)$haricFaturaId]);

        return ['success' => true, 'message' => '', 'sira' => max($uzak, (int)($yerel['sira'] ?? 0)) + 1];
    }

    /** Örnek Holding hata metni "numara zaten kullanılmış" türünde mi (sıralıda +1 ile tekrar için) */
    private static function numaraCakismasi($mesaj)
    {
        return (bool)preg_match('/(daha önce|kullanılmış|kullanilmis|mevcut|mükerrer|mukerrer|zaten|already|duplicate|exist)/iu', (string)$mesaj);
    }

    /** SQL Server uygulama kilidi (bağlantı ömrü boyunca; en fazla 30 sn bekler) */
    private function kilitAl($ad)
    {
        $r = $this->db->fetchOne("
            SET NOCOUNT ON;
            DECLARE @r INT;
            EXEC @r = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 30000;
            SELECT @r AS sonuc;
        ", [$ad]);
        return $r && (int)$r['sonuc'] >= 0;
    }

    private function kilitBirak($ad)
    {
        $this->db->execute("EXEC sp_releaseapplock @Resource = ?, @LockOwner = 'Session'", [$ad]);
    }

    // =================================================================
    // Yardımcılar
    // =================================================================

    /** Kanalı aç ve siparişin firmasıyla eşleştiğini doğrula */
    private function api(array $belge, $kanalId)
    {
        $api = OrnekHoldingApi::kanaldan((int)$kanalId, $this->userId, $this->db);
        if (!$api) {
            return [null, 'Örnek Holding kanalı bulunamadı.'];
        }
        if (!$api->hazirMi()) {
            return [null, $api->getHata()];
        }
        if ((int)$api->kanalBilgi()['firma_id'] !== (int)$belge['firma']['firma_id']) {
            return [null, 'Seçilen Örnek Holding kanalı siparişin firmasına ait değil.'];
        }
        return [$api, ''];
    }

    private function belgeTuru($api, array $belge)
    {
        $kimlik = $belge['alici']['kimlik'];
        if ($kimlik === self::ANONIM_TCKN) {
            return ['success' => true, 'earsiv' => true, 'alias' => '', 'unvan' => ''];
        }
        $m = $api->mukellefSorgula($kimlik);
        if (!$m['success']) {
            return ['success' => false, 'message' => 'Mükellef sorgusu başarısız, işlem durduruldu: ' . $m['message']];
        }
        return ['success' => true, 'earsiv' => !$m['mukellef'], 'alias' => $m['alias'], 'unvan' => $m['unvan']];
    }

    /** Seri: PazaryeriAyarlari (kanal → pazaryeri → genel) → Örnek Holding kanal JSON */
    private function seri($api, array $belge, $earsiv)
    {
        $anahtar = $earsiv ? 'EArsivSeri' : 'EFaturaSeri';
        $deger = $this->pazaryeriAyar($anahtar, (int)($belge['kanal_id'] ?? 0), (int)($belge['pazaryeri_id'] ?? 0))
              ?: (string)$api->ayar($anahtar, '');
        return strtoupper(trim((string)$deger));
    }

    private function numaraYontemi($api)
    {
        return strtolower((string)$api->ayar('NumaraYontemi', 'seri')) === 'sirali' ? 'sirali' : 'seri';
    }

    /**
     * XSLT (isteğe bağlı): <xsltKok>/<XsltKlasoru>/0_fatura.xslt | 0_arsivfatura.xslt
     * Klasör tanımlı değilse boş döner (Örnek Holding varsayılan görüntüsü); tanımlı ama dosya yoksa hata.
     */
    private function xslt($api, $earsiv)
    {
        $klasor = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$api->ayar('XsltKlasoru', ''));
        if ($klasor === '') {
            return ['success' => true, 'ad' => '', 'icerik' => ''];
        }
        $ad  = $earsiv ? '0_arsivfatura.xslt' : '0_fatura.xslt';
        $kok = self::$xsltKok ?: __DIR__ . '/../assets/xslt';
        $yol = rtrim($kok, '/\\') . '/' . $klasor . '/' . $ad;
        if (!is_file($yol)) {
            return ['success' => false, 'message' => 'XSLT şablonu bulunamadı: assets/xslt/' . $klasor . '/' . $ad];
        }
        return ['success' => true, 'ad' => $ad, 'icerik' => file_get_contents($yol)];
    }

    private function firma($firmaId)
    {
        return $this->db->fetchOne("
            SELECT f.firma_id,
                   COALESCE(NULLIF(LTRIM(RTRIM(f.firma_unvan)), ''), f.firma_adi) AS unvan,
                   f.firma_vergi_no AS vergi_no, f.firma_vergi_dairesi AS vergi_dairesi,
                   f.firma_adres AS adres, s.SehirAdi AS il, i.IlceAdi AS ilce,
                   f.firma_telefon AS telefon, f.firma_email AS eposta, f.firma_durum AS durum
            FROM dbo.Firmalar f
            LEFT JOIN dbo.Sehirler s ON s.SehirId = f.firma_sehir_id
            LEFT JOIN dbo.Ilceler i ON i.ilceId = f.firma_ilce_id
            WHERE f.firma_id = ?
        ", [(int)$firmaId]);
    }

    private function mevcutFatura($faturaId)
    {
        if (!$faturaId) return null;
        return $this->db->fetchOne("
            SELECT Faturalar_id, Faturalar_FaturaNo, Faturalar_ETTN, Faturalar_Tur,
                   Faturalar_KesimDurumu, Faturalar_DurumAciklama, Faturalar_Durum,
                   Faturalar_IptalDurumu, Faturalar_GibDurum
            FROM dbo.Faturalar WHERE Faturalar_id = ?
        ", [(int)$faturaId]);
    }

    /**
     * Kayıt kesinleşmiş bir fatura mı (yeniden kesime kapalı).
     * Taslak, Örnek Holding'de onaylanıp gerçek numara aldıysa (senkron yazar) kesinleşmiş sayılır.
     */
    public static function kesinlesmisMi(?array $f)
    {
        if (!$f || (int)$f['Faturalar_Durum'] !== 1 || (int)$f['Faturalar_IptalDurumu'] === 1) {
            return false;
        }
        if ($f['Faturalar_KesimDurumu'] !== self::DURUM_TASLAK) {
            return true;
        }
        return (bool)preg_match('/^[A-Z0-9]{3}\d{4}(?!0{9})\d{9}$/', (string)$f['Faturalar_FaturaNo']);
    }

    /**
     * Gönderimden önce Faturalar kaydını aç (ya da tekrar denemede yeniden kullan)
     * ve siparişe atomik olarak bağla.
     */
    private function kayitHazirla(array $belge, $kanalId, $belgeTuru, $ettn, $belirsizTekrar)
    {
        $siparis = $this->db->fetchOne("
            SELECT PazaryeriSiparisleri_Faturalar_id FROM dbo.PazaryeriSiparisleri WHERE PazaryeriSiparisleri_id = ?
        ", [$belge['kaynak_id']]);
        $mevcut = $this->mevcutFatura($siparis['PazaryeriSiparisleri_Faturalar_id'] ?? null);

        $simdi = date('Y-m-d H:i:s');
        $alanlar = [
            'Faturalar_EntegrasyonKanallari_id' => (int)$kanalId,
            'Faturalar_Tur'                     => $belgeTuru,
            'Faturalar_Senaryo'                 => $belgeTuru === 'EARSIV' ? 'EARSIVFATURA' : 'TEMELFATURA',
            'Faturalar_FaturaTipi'              => 'SATIS',
            'Faturalar_Tarih'                   => date('Y-m-d'),
            'Faturalar_CariUnvan'               => mb_substr($belge['alici']['unvan'], 0, 255),
            'Faturalar_CariVKN'                 => $belge['alici']['kimlik'],
            'Faturalar_CariVergiDairesi'        => mb_substr((string)$belge['alici']['vergi_dairesi'], 0, 100) ?: null,
            'Faturalar_MalHizmetTutari'         => $belge['toplam']['matrah'],
            'Faturalar_VergiTutari'             => $belge['toplam']['kdv'],
            'Faturalar_OdenecekTutar'           => $belge['toplam']['genel'],
            'Faturalar_ParaBirimi'              => $belge['para'],
            'Faturalar_Notlar'                  => trim($belge['pazaryeri'] . ' sipariş ' . $belge['siparis_no']),
            'Faturalar_KesimDurumu'             => self::DURUM_GONDERILIYOR,
            'Faturalar_DurumAciklama'           => null,
            'Faturalar_IptalDurumu'             => 0,
            'Faturalar_Durum'                   => 0,
            'Faturalar_GuncelleyenKullanici'    => $this->userId,
            'Faturalar_GuncellemeTarihi'        => $simdi,
        ];

        if ($mevcut) {
            $kod = $mevcut['Faturalar_KesimDurumu'];

            // Sonucu belirsiz/hatalı sanılan gönderim Örnek Holding'de oluşmuş: senkron aynı ETTN'yi
            // bulup GİB durumunu yazdı. Kayıt kesinleştirilir, tekrar gönderilmez.
            if (in_array($kod, [self::DURUM_GONDERILIYOR, self::DURUM_HATA], true)
                && trim((string)$mevcut['Faturalar_GibDurum']) !== '') {
                $this->kayitGuncelle((int)$mevcut['Faturalar_id'], [
                    'Faturalar_KesimDurumu' => self::DURUM_GONDERILDI,
                    'Faturalar_Durum'       => 1,
                ]);
                return ['success' => false, 'message' => 'Önceki gönderim Örnek Holding\'de oluşmuş ('
                    . ($mevcut['Faturalar_FaturaNo'] ?: $mevcut['Faturalar_ETTN']) . '); kayıt kesinleştirildi.'];
            }

            if (self::kesinlesmisMi($mevcut)) {
                return ['success' => false, 'message' => 'Bu sipariş zaten faturalanmış ('
                    . ($mevcut['Faturalar_FaturaNo'] ?: $mevcut['Faturalar_ETTN']) . ').'];
            }
            if ($kod === self::DURUM_GONDERILIYOR && !$belirsizTekrar) {
                return ['success' => false, 'belirsiz' => true, 'ettn' => $mevcut['Faturalar_ETTN'],
                    'message' => 'Önceki gönderimin sonucu bilinmiyor (ETTN ' . $mevcut['Faturalar_ETTN']
                        . '). Örnek Holding portalında kontrol edin; fatura yoksa aynı ETTN ile tekrar gönderin.'];
            }

            // Aynı ETTN yalnız belge Örnek Holding'de oluşmamış olabilecekken kullanılır (hata /
            // belirsiz, tür değişmediyse). Taslak ve diğer kayıtlar yeni ETTN alır.
            $oncekiNo = '';
            if (in_array($kod, [self::DURUM_HATA, self::DURUM_GONDERILIYOR], true)
                && $mevcut['Faturalar_Tur'] === $belgeTuru) {
                $ettn = $mevcut['Faturalar_ETTN'];
                $oncekiNo = (string)$mevcut['Faturalar_FaturaNo'];
            } else {
                $alanlar['Faturalar_FaturaNo'] = null;
            }
            $alanlar['Faturalar_ETTN'] = $ettn;
            $this->db->update('Faturalar', $alanlar, ['Faturalar_id' => (int)$mevcut['Faturalar_id']]);
            return ['success' => true, 'fatura_id' => (int)$mevcut['Faturalar_id'], 'ettn' => $ettn, 'onceki_no' => $oncekiNo];
        }

        $faturaId = (int)$this->db->insert('Faturalar', $alanlar + [
            'Faturalar_Yon'                => 'GIDEN',
            'Faturalar_ETTN'               => $ettn,
            'Faturalar_OlusturanKullanici' => $this->userId ?: 1,
            'Faturalar_OlusturmaTarihi'    => $simdi,
        ]);
        if ($faturaId <= 0) {
            return ['success' => false, 'message' => 'Fatura kaydı açılamadı.'];
        }

        // Eşzamanlı iki tıklamada yalnız biri siparişi bağlayabilir
        $bag = $this->db->fetchOne("
            UPDATE dbo.PazaryeriSiparisleri
               SET PazaryeriSiparisleri_Faturalar_id = ?,
                   PazaryeriSiparisleri_GuncelleyenKullanici = ?, PazaryeriSiparisleri_GuncellemeTarihi = ?
            OUTPUT inserted.PazaryeriSiparisleri_id
            WHERE PazaryeriSiparisleri_id = ? AND PazaryeriSiparisleri_Faturalar_id IS NULL
        ", [$faturaId, $this->userId, $simdi, $belge['kaynak_id']]);

        if (!$bag) {
            $this->db->execute("DELETE FROM dbo.Faturalar WHERE Faturalar_id = ? AND Faturalar_Durum = 0", [$faturaId]);
            return ['success' => false, 'message' => 'Bu sipariş için başka bir fatura işlemi başlamış; listeyi yenileyin.'];
        }

        return ['success' => true, 'fatura_id' => $faturaId, 'ettn' => $ettn];
    }

    private function kayitGuncelle($faturaId, array $alanlar)
    {
        $alanlar['Faturalar_GuncelleyenKullanici'] = $this->userId;
        $alanlar['Faturalar_GuncellemeTarihi']     = date('Y-m-d H:i:s');
        $this->db->update('Faturalar', $alanlar, ['Faturalar_id' => (int)$faturaId]);
    }

    /** KDV dahil satır tutarından matrah/KDV */
    private static function kalem($ad, $miktar, $brut, $oran)
    {
        $matrah = round($brut / (1 + $oran / 100), 2);
        return [
            'ad'       => $ad,
            'miktar'   => $miktar,
            'birim'    => 'C62',
            'brut'     => round($brut, 2),
            'matrah'   => $matrah,
            'kdv_oran' => $oran,
            'kdv'      => round($brut - $matrah, 2),
        ];
    }

    private static function toplamlar(array $kalemler)
    {
        $t = ['matrah' => 0.0, 'kdv' => 0.0, 'genel' => 0.0, 'kdv_gruplari' => []];
        foreach ($kalemler as $k) {
            $t['matrah'] += $k['matrah'];
            $t['kdv']    += $k['kdv'];
            $t['genel']  += $k['brut'];
            $anahtar = (string)(float)$k['kdv_oran'];
            $t['kdv_gruplari'][$anahtar]['matrah'] = ($t['kdv_gruplari'][$anahtar]['matrah'] ?? 0) + $k['matrah'];
            $t['kdv_gruplari'][$anahtar]['kdv']    = ($t['kdv_gruplari'][$anahtar]['kdv'] ?? 0) + $k['kdv'];
        }
        foreach (['matrah', 'kdv', 'genel'] as $a) {
            $t[$a] = round($t[$a], 2);
        }
        return $t;
    }

    /**
     * PazaryeriAyarlari'ndan ham değer (kanal → pazaryeri → genel; ilk dolu kazanır).
     * PazaryeriApi::ayar() ile aynı çözümleme; API nesnesi kurmadan okunur.
     */
    private function pazaryeriAyar($anahtar, $kanalId, $pazaryeriId)
    {
        $row = $this->db->fetchOne("
            SELECT TOP 1 PazaryeriAyarlari_Deger
            FROM dbo.PazaryeriAyarlari
            WHERE PazaryeriAyarlari_Anahtar = ? AND PazaryeriAyarlari_Durum = 1
              AND (PazaryeriAyarlari_EntegrasyonKanallari_id = ?
                   OR (PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id = ?)
                   OR (PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id IS NULL))
            ORDER BY CASE WHEN PazaryeriAyarlari_EntegrasyonKanallari_id IS NOT NULL THEN 1
                          WHEN PazaryeriAyarlari_Pazaryerleri_id IS NOT NULL THEN 2 ELSE 3 END
        ", [$anahtar, (int)$kanalId, (int)$pazaryeriId]);
        $deger = trim((string)($row['PazaryeriAyarlari_Deger'] ?? ''));
        return $deger !== '' ? $deger : null;
    }

    /** Türkçe büyük harf (i → İ, ı → I) */
    private static function buyukHarf($metin)
    {
        return mb_strtoupper(strtr((string)$metin, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
    }

    private static function adSoyadAyir($tamAd)
    {
        $p = preg_split('/\s+/u', trim($tamAd));
        if (count($p) < 2) return [$tamAd, $tamAd];
        $soyad = array_pop($p);
        return [implode(' ', $p), $soyad];
    }
}
