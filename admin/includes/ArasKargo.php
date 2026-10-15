<?php
/**
 * Aras Kargo entegrasyonu
 *
 * Bağlantı bilgileri DB'den okunur:
 *   Entegrasyonlar (Ad = 'Aras Kargo') → AyarJSON: SiparisURL, TakipURL
 *   EntegrasyonKanallari              → KullaniciAdi, Sifre, AyarJSON: MusteriKodu
 *
 * Servisler:
 *   SiparisURL (asmx)  → SetOrder (SOAP 1.2)
 *   TakipURL   (svc)   → GetQueryXML (SOAP 1.1). GetQueryJSON bu hesapta HTTP 500 veriyor.
 *
 * GetQueryXML sorgu tipleri:
 *   1  → entegrasyon koduyla tek gönderi
 *   13 → tarih aralığında giden kargolar
 *   30 → tarih aralığında gelen kargolar
 *
 * Her servis çağrısı EntegrasyonLoglari'na yazılır (şifre maskelenir).
 */

class ArasKargo
{
    public const ENTEGRASYON_AD = 'Aras Kargo';

    private const QT_ENTEGRASYON_KODU = 1;
    private const QT_GIDEN            = 13;
    private const QT_GELEN            = 30;

    // Liste yanıtları büyük olabilir; log tablosu şişmesin
    private const LOG_CEVAP_LIMIT = 20000;

    private $db;
    private ?array $kanal = null;
    private string $hata  = '';
    private int $kullaniciId;
    private string $kaynak;

    /** @var array<int,bool> kargo_durumlari kod önbelleği */
    private array $durumKodlari = [];

    /**
     * @param int|null $kanalId Boşsa 'Aras Kargo' entegrasyonunun ilk aktif kanalı kullanılır
     */
    public function __construct($db, ?int $kanalId = null, int $kullaniciId = 1, string $kaynak = 'PORTAL')
    {
        $this->db          = $db;
        $this->kullaniciId = $kullaniciId;
        $this->kaynak      = $kaynak;

        $sql = "SELECT TOP 1 k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_Ad,
                       k.EntegrasyonKanallari_KullaniciAdi, k.EntegrasyonKanallari_Sifre,
                       CAST(k.EntegrasyonKanallari_AyarJSON AS NVARCHAR(MAX)) AS KanalAyar,
                       CAST(e.Entegrasyonlar_AyarJSON AS NVARCHAR(MAX))       AS EntAyar
                FROM EntegrasyonKanallari k
                INNER JOIN Entegrasyonlar e ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
                WHERE e.Entegrasyonlar_Ad = ? AND e.Entegrasyonlar_Durum = 1 AND k.EntegrasyonKanallari_Durum = 1";
        $params = [self::ENTEGRASYON_AD];

        if ($kanalId) {
            $sql .= " AND k.EntegrasyonKanallari_id = ?";
            $params[] = $kanalId;
        }
        $sql .= " ORDER BY k.EntegrasyonKanallari_id";

        $k = $db->fetchOne($sql, $params);
        if (!$k) {
            $this->hata = 'Aktif Aras Kargo kanalı bulunamadı (Entegrasyon Yönetimi).';
            return;
        }

        $entAyar   = json_decode($k['EntAyar'] ?? '{}', true) ?: [];
        $kanalAyar = json_decode($k['KanalAyar'] ?? '{}', true) ?: [];

        $this->kanal = [
            'id'          => (int)$k['EntegrasyonKanallari_id'],
            'ad'          => (string)$k['EntegrasyonKanallari_Ad'],
            'kullanici'   => trim((string)$k['EntegrasyonKanallari_KullaniciAdi']),
            'sifre'       => (string)$k['EntegrasyonKanallari_Sifre'],
            'musteriKodu' => trim((string)($kanalAyar['MusteriKodu'] ?? '')),
            'gondericiUnvan' => trim((string)($kanalAyar['GondericiUnvan'] ?? '')),
            'siparisUrl'  => trim((string)($entAyar['SiparisURL'] ?? '')),
            'takipUrl'    => trim((string)($entAyar['TakipURL'] ?? '')),
        ];

        foreach (['kullanici' => 'Kullanıcı Adı', 'sifre' => 'Şifre', 'musteriKodu' => 'MusteriKodu',
                  'siparisUrl' => 'SiparisURL', 'takipUrl' => 'TakipURL'] as $alan => $etiket) {
            if ($this->kanal[$alan] === '') {
                $this->hata  = "Aras Kargo ayarında {$etiket} boş.";
                $this->kanal = null;
                return;
            }
        }
    }

    public function hazirMi(): bool  { return $this->kanal !== null; }
    public function getHata(): string { return $this->hata; }
    public function kanalId(): int    { return (int)($this->kanal['id'] ?? 0); }
    public function kanalAd(): string { return (string)($this->kanal['ad'] ?? ''); }

    // ================================================================
    // Gönderi oluşturma — SetOrder
    // ================================================================

    /**
     * Zorunlu: alici, adres, telefon, il, ilce, adet, desi
     * Opsiyonel: odemeTipi (1 = gönderici, 2 = alıcı), tahsilat, aciklama, faturaNo, irsaliyeNo
     *
     * @return array ['success', 'message', 'entegrasyonKodu', 'barkod']
     */
    public function gonderiOlustur(array $d): array
    {
        if (!$this->hazirMi()) return ['success' => false, 'message' => $this->hata];

        $kod      = $this->yeniEntegrasyonKodu();
        $adet     = max(1, min(99, (int)($d['adet'] ?? 1)));
        $barkodlar = self::parcaBarkodlari($this->yeniBarkodTabani(), $adet);
        $barkod   = $barkodlar[0];
        $x      = fn($s) => $this->xml((string)$s);
        $buyuk  = fn($s) => mb_strtoupper(trim((string)$s), 'UTF-8');

        $desi  = max(1, (float)($d['desi'] ?? 1));
        $tel   = self::telefonNormalize($d['telefon'] ?? '');
        if ($tel === null) return ['success' => false, 'message' => 'Telefon numarası geçersiz.'];
        $odeme = (int)($d['odemeTipi'] ?? 1) === 2 ? 2 : 1;

        // Tahsilatlı gönderi (telekombasvurusu'nda çalışan biçim)
        $tahsilatEtiketi = '';
        if ((float)($d['tahsilat'] ?? 0) > 0) {
            $tahsilatEtiketi = '<CodAmount>' . number_format((float)$d['tahsilat'], 2, ',', '') . '</CodAmount>';
        }

        // Her parçaya ayrı barkod; tabloya ilki yazılır, diğerleri ondan türetilir
        $parcalar = '';
        foreach ($barkodlar as $parcaBarkod) {
            $parcalar .= '<PieceDetail>'
                       . '<VolumetricWeight>' . $desi . '</VolumetricWeight>'
                       . '<Weight>' . $desi . '</Weight>'
                       . '<BarcodeNumber>' . $parcaBarkod . '</BarcodeNumber>'
                       . '<Description>' . $x($d['aciklama'] ?? '') . '</Description>'
                       . '</PieceDetail>';
        }

        $govde = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            . '<SetOrder xmlns="http://tempuri.org/"><orderInfo><Order>'
            . '<UserName>' . $x($this->kanal['kullanici']) . '</UserName>'
            . '<Password>' . $x($this->kanal['sifre']) . '</Password>'
            . '<TradingWaybillNumber>' . $x($d['irsaliyeNo'] ?? '') . '</TradingWaybillNumber>'
            . '<InvoiceNumber>' . $x($d['faturaNo'] ?? '') . '</InvoiceNumber>'
            . '<ReceiverName>' . $x($d['alici'] ?? '') . '</ReceiverName>'
            . '<ReceiverAddress>' . $x($d['adres'] ?? '') . '</ReceiverAddress>'
            . '<ReceiverPhone1>' . $tel . '</ReceiverPhone1>'
            . '<ReceiverCityName>' . $x($buyuk($d['il'] ?? '')) . '</ReceiverCityName>'
            . '<ReceiverTownName>' . $x($buyuk($d['ilce'] ?? '')) . '</ReceiverTownName>'
            . '<VolumetricWeight>' . $desi . '</VolumetricWeight>'
            . '<PieceCount>' . $adet . '</PieceCount>'
            . '<IntegrationCode>' . $kod . '</IntegrationCode>'
            . '<PayorTypeCode>' . $odeme . '</PayorTypeCode>'
            . $tahsilatEtiketi
            . '<PieceDetails>' . $parcalar . '</PieceDetails>'
            . '</Order></orderInfo>'
            . '<userName>' . $x($this->kanal['kullanici']) . '</userName>'
            . '<password>' . $x($this->kanal['sifre']) . '</password>'
            . '</SetOrder></soap:Body></soap:Envelope>';

        $r = $this->soap($this->kanal['siparisUrl'], 'http://tempuri.org/SetOrder', $govde, '1.2');

        $sonucKod = $this->etiket($r['res'], 'ResultCode');
        $sonucMsj = $this->etiket($r['res'], 'ResultMessage');
        $basarili = $r['err'] === '' && $r['code'] === 200 && $sonucKod === '0';

        $hata = null;
        if (!$basarili) {
            $hata = $r['err'] ?: ($sonucMsj ?: ($this->etiket($r['res'], 'Text') ?: ('HTTP ' . $r['code'])));
        }

        $this->log('ARAS_SetOrder', $govde, $r['res'] ?: $r['err'], $basarili, $hata, $kod);

        return $basarili
            ? ['success' => true, 'message' => $sonucMsj ?: 'Gönderi oluşturuldu.', 'entegrasyonKodu' => $kod, 'barkod' => $barkod]
            : ['success' => false, 'message' => 'Aras: ' . $hata];
    }

    // ================================================================
    // Sorgular — GetQueryXML
    // ================================================================

    /** @return array|null Normalize edilmiş tek gönderi; Aras'ta henüz yoksa null */
    public function entegrasyonKoduSorgula(string $kod): ?array
    {
        $kayitlar = $this->sorgu(self::QT_ENTEGRASYON_KODU,
            '<IntegrationCode>' . $this->xml($kod) . '</IntegrationCode>', $kod);
        return $kayitlar ? $this->normalize($kayitlar[0], 'GIDEN') : null;
    }

    /** @return array[]|null Hata durumunda null */
    public function gidenListe(DateTimeInterface $bas, DateTimeInterface $bit): ?array
    {
        $kayitlar = $this->sorgu(self::QT_GIDEN, $this->tarihAraligi($bas, $bit), 'giden');
        return $kayitlar === null ? null : array_map(fn($c) => $this->normalize($c, 'GIDEN'), $kayitlar);
    }

    /** @return array[]|null Hata durumunda null */
    public function gelenListe(DateTimeInterface $bas, DateTimeInterface $bit): ?array
    {
        $kayitlar = $this->sorgu(self::QT_GELEN, $this->tarihAraligi($bas, $bit), 'gelen');
        return $kayitlar === null ? null : array_map(fn($c) => $this->normalize($c, 'GELEN'), $kayitlar);
    }

    // ================================================================
    // Senkron — cron (aras_kargo_senkron) ve "Şimdi senkronize et" butonu
    // ================================================================

    /**
     * 1) Portaldan açılıp takip no almamış gönderiler entegrasyon koduyla eşlenir
     * 2) Son $gun günün giden ve gelen kargoları eklenir / güncellenir
     *
     * @return array ['success', 'message', 'istatistik' => [...]]
     */
    public function senkronize(int $gun = 30): array
    {
        if (!$this->hazirMi()) return ['success' => false, 'message' => $this->hata, 'istatistik' => []];

        $gun = max(1, min($gun, 90));
        $ist = ['eslesen' => 0, 'yeni_giden' => 0, 'yeni_gelen' => 0, 'guncellenen' => 0, 'hata' => 0];
        $hatalar = [];

        // 1) Takip no bekleyen portal gönderileri
        $bekleyenler = $this->db->fetchAll("
            SELECT kargo_gonderiler_id, kargo_gonderiler_EntegrasyonKodu
            FROM kargo_gonderiler
            WHERE kargo_gonderiler_Durum = 1
              AND kargo_gonderiler_Kaynak = 'PORTAL'
              AND kargo_gonderiler_TakipNo IS NULL
              AND kargo_gonderiler_EntegrasyonKodu IS NOT NULL
              AND kargo_gonderiler_EntegrasyonKanallari_id = ?
              AND kargo_gonderiler_OlusturmaTarihi >= DATEADD(DAY, ?, GETDATE())
        ", [$this->kanalId(), -$gun]);

        foreach ($bekleyenler as $b) {
            $k = $this->entegrasyonKoduSorgula($b['kargo_gonderiler_EntegrasyonKodu']);
            if (!$k || !$k['TakipNo']) continue;

            // Aynı takip no senkronla önceden ARAS kaynaklı eklendiyse mükerrer olmasın
            $this->db->execute("
                DELETE FROM kargo_gonderiler
                WHERE kargo_gonderiler_Yon = 'GIDEN' AND kargo_gonderiler_TakipNo = ? AND kargo_gonderiler_Kaynak = 'ARAS'
            ", [$k['TakipNo']]);

            $this->guncelle((int)$b['kargo_gonderiler_id'], $k, true);
            $ist['eslesen']++;
        }

        // 2) Listeler
        $bit = new DateTime();
        $bas = (new DateTime())->modify('-' . $gun . ' days');

        foreach (['GIDEN' => $this->gidenListe($bas, $bit), 'GELEN' => $this->gelenListe($bas, $bit)] as $yon => $liste) {
            if ($liste === null) {
                $ist['hata']++;
                $hatalar[] = ($yon === 'GIDEN' ? 'Giden' : 'Gelen') . ' liste alınamadı: ' . $this->hata;
                continue;
            }

            foreach ($liste as $k) {
                if (!$k['TakipNo']) continue;

                $mevcut = $this->db->fetchOne("
                    SELECT kargo_gonderiler_id FROM kargo_gonderiler
                    WHERE kargo_gonderiler_Yon = ? AND kargo_gonderiler_TakipNo = ?
                ", [$yon, $k['TakipNo']]);

                if ($mevcut) {
                    $this->guncelle((int)$mevcut['kargo_gonderiler_id'], $k, false);
                    $ist['guncellenen']++;
                } else {
                    $this->ekle($k);
                    $ist[$yon === 'GIDEN' ? 'yeni_giden' : 'yeni_gelen']++;
                }
            }
        }

        $mesaj = sprintf('Eşleşen: %d, Yeni giden: %d, Yeni gelen: %d, Güncellenen: %d',
            $ist['eslesen'], $ist['yeni_giden'], $ist['yeni_gelen'], $ist['guncellenen']);
        if ($hatalar) $mesaj .= ' | ' . implode(' | ', $hatalar);

        return ['success' => $ist['hata'] === 0, 'message' => $mesaj, 'istatistik' => $ist];
    }

    /** Portaldan açılan gönderiyi kaydeder (gonderiOlustur başarılıysa) */
    public function portalKaydiEkle(array $form, array $sonuc): int
    {
        return (int)$this->db->insert('kargo_gonderiler', [
            'kargo_gonderiler_Yon'                     => 'GIDEN',
            'kargo_gonderiler_Kaynak'                  => 'PORTAL',
            'kargo_gonderiler_EntegrasyonKanallari_id' => $this->kanalId(),
            'kargo_gonderiler_EntegrasyonKodu'         => $sonuc['entegrasyonKodu'],
            'kargo_gonderiler_Barkod'                  => $sonuc['barkod'],
            'kargo_gonderiler_IrsaliyeNo'              => ($form['irsaliyeNo'] ?? '') ?: null,
            'kargo_gonderiler_Gonderici'               => $this->gondericiUnvan(),
            'kargo_gonderiler_Alici'                   => $form['alici'],
            'kargo_gonderiler_AliciTelefon'            => $form['telefon'],
            'kargo_gonderiler_AliciAdres'              => $form['adres'],
            'kargo_gonderiler_AliciIl'                 => mb_strtoupper($form['il'], 'UTF-8'),
            'kargo_gonderiler_AliciIlce'               => mb_strtoupper($form['ilce'], 'UTF-8'),
            'kargo_gonderiler_CikisTarihi'             => date('Y-m-d H:i:s'),
            'kargo_gonderiler_Adet'                    => (int)$form['adet'],
            'kargo_gonderiler_Desi'                    => (float)$form['desi'],
            'kargo_gonderiler_OdemeTipi'               => (int)($form['odemeTipi'] ?? 1) === 2 ? 'Alıcı Öder' : 'Gönderici Öder',
            'kargo_gonderiler_TahsilatTutari'          => (float)($form['tahsilat'] ?? 0) > 0 ? (float)$form['tahsilat'] : null,
            'kargo_gonderiler_Aciklama'                => ($form['aciklama'] ?? '') ?: null,
            'kargo_gonderiler_DurumKodu'               => 0,
            'kargo_gonderiler_DurumMetni'              => null,
            'kargo_gonderiler_OlusturanKullanici'      => $this->kullaniciId,
            'kargo_gonderiler_OlusturmaTarihi'         => date('Y-m-d H:i:s'),
            'kargo_gonderiler_Durum'                   => 1,
        ]);
    }

    // ================================================================
    // DB yardımcıları
    // ================================================================

    private function ekle(array $k): void
    {
        $this->durumKoduHazirla($k['DurumKodu'], $k['DurumMetni']);

        $veri = ['kargo_gonderiler_Kaynak' => 'ARAS',
                 'kargo_gonderiler_EntegrasyonKanallari_id' => $this->kanalId(),
                 'kargo_gonderiler_OlusturanKullanici' => $this->kullaniciId,
                 'kargo_gonderiler_OlusturmaTarihi' => date('Y-m-d H:i:s'),
                 'kargo_gonderiler_Durum' => 1,
                 'kargo_gonderiler_SonSenkron' => date('Y-m-d H:i:s')];

        foreach ($k as $alan => $deger) {
            $veri['kargo_gonderiler_' . $alan] = $deger;
        }
        $this->db->insert('kargo_gonderiler', $veri);
    }

    /**
     * @param bool $portalEslesme Portal kaydında alıcı/adres gibi elle girilen alanlar korunur
     */
    private function guncelle(int $id, array $k, bool $portalEslesme): void
    {
        $this->durumKoduHazirla($k['DurumKodu'], $k['DurumMetni']);

        // Aras'ın her zaman güncel tuttuğu alanlar
        $alanlar = ['TakipNo', 'KargoKodu', 'IrsaliyeNo', 'CikisSube', 'VarisSube', 'Tutar', 'OdemeTipi',
                    'DurumKodu', 'DurumMetni', 'TeslimAlan', 'TeslimTarihi', 'Desi', 'Agirlik'];

        $veri = [];
        foreach ($alanlar as $a) {
            if ($k[$a] !== null && $k[$a] !== '') $veri['kargo_gonderiler_' . $a] = $k[$a];
        }
        if (!$portalEslesme && $k['CikisTarihi']) {
            $veri['kargo_gonderiler_CikisTarihi'] = $k['CikisTarihi'];
        }

        $veri['kargo_gonderiler_SonSenkron']           = date('Y-m-d H:i:s');
        $veri['kargo_gonderiler_GuncelleyenKullanici'] = $this->kullaniciId;
        $veri['kargo_gonderiler_GuncellemeTarihi']     = date('Y-m-d H:i:s');

        $this->db->update('kargo_gonderiler', $veri, ['kargo_gonderiler_id' => $id]);
    }

    /** Bilinmeyen durum kodunu Aras metniyle kargo_durumlari'na ekler */
    private function durumKoduHazirla(?int $kod, ?string $metin): void
    {
        if ($kod === null || isset($this->durumKodlari[$kod])) return;

        $var = $this->db->fetchOne("SELECT 1 AS v FROM kargo_durumlari WHERE kargo_durumlari_Kod = ?", [$kod]);
        if (!$var) {
            // "TESLİMATTA - NORMAL" → "TESLİMATTA"
            $ad = trim(explode(' - ', (string)$metin)[0]) ?: ('Durum ' . $kod);
            $this->db->insert('kargo_durumlari', [
                'kargo_durumlari_Kod'                => $kod,
                'kargo_durumlari_Ad'                 => self::trBaslik($ad),
                'kargo_durumlari_Sira'               => $kod,
                'kargo_durumlari_OlusturanKullanici' => $this->kullaniciId,
                'kargo_durumlari_OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                'kargo_durumlari_Durum'              => 1,
            ]);
        }
        $this->durumKodlari[$kod] = true;
    }

    private function yeniEntegrasyonKodu(): string
    {
        // 10 haneli, tabloda benzersiz
        do {
            $kod = (string)random_int(1000000000, 9999999999);
            $var = $this->db->fetchOne("SELECT 1 AS v FROM kargo_gonderiler WHERE kargo_gonderiler_EntegrasyonKodu = ?", [$kod]);
        } while ($var);
        return $kod;
    }

    /** 9 haneli taban; parça barkodu = taban + 2 haneli sıra (11 hane) */
    private function yeniBarkodTabani(): string
    {
        do {
            $taban = (string)random_int(100000000, 999999999);
            $var = $this->db->fetchOne("SELECT 1 AS v FROM kargo_gonderiler WHERE kargo_gonderiler_Barkod = ?", [$taban . '01']);
        } while ($var);
        return $taban;
    }

    /**
     * Gönderinin tüm parça barkodları. İlk parametre taban (9 hane) veya
     * tabloya yazılan ilk parça barkodu (11 hane) olabilir.
     * @return string[]
     */
    public static function parcaBarkodlari(string $tabanVeyaIlkBarkod, int $adet): array
    {
        $taban = substr($tabanVeyaIlkBarkod, 0, 9);
        $liste = [];
        for ($i = 1; $i <= max(1, min(99, $adet)); $i++) {
            $liste[] = $taban . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        }
        return $liste;
    }

    /**
     * Etiket ve kayıtta görünen gönderici unvanı:
     * kanal AyarJSON → GondericiUnvan, yoksa Aras'ın giden kayıtlarında döndürdüğü unvan, o da yoksa kanal adı.
     */
    public function gondericiUnvan(): string
    {
        if (!empty($this->kanal['gondericiUnvan'])) return $this->kanal['gondericiUnvan'];

        $r = $this->db->fetchOne("
            SELECT TOP 1 kargo_gonderiler_Gonderici AS u
            FROM kargo_gonderiler
            WHERE kargo_gonderiler_Yon = 'GIDEN' AND kargo_gonderiler_Kaynak = 'ARAS'
              AND kargo_gonderiler_EntegrasyonKanallari_id = ? AND kargo_gonderiler_Gonderici IS NOT NULL
            ORDER BY kargo_gonderiler_id DESC
        ", [$this->kanalId()]);

        return $r['u'] ?? $this->kanalAd();
    }

    // ================================================================
    // Aras yanıtı → kargo_gonderiler alanları
    // ================================================================

    private function normalize(SimpleXMLElement $c, string $yon): array
    {
        $v = function (string ...$adlar) use ($c): ?string {
            foreach ($adlar as $ad) {
                if (isset($c->$ad) && trim((string)$c->$ad) !== '') return trim((string)$c->$ad);
            }
            return null;
        };

        $teslimTarihi = null;
        if ($t = $v('TESLIM_TARIHI')) {
            $teslimTarihi = self::tarih($t . ' ' . ($v('TESLIM_SAATI') ?? '00:00'));
        }

        $durumKodu = $v('DURUM_KODU');

        return [
            'Yon'          => $yon,
            'TakipNo'      => $v('KARGO_TAKIP_NO', 'TRACKINGNUMBER'),
            'KargoKodu'    => $v('KARGO_KODU'),
            'IrsaliyeNo'   => $v('IRSALIYE_NUMARA', 'IRSALIYE_NUMARASI'),
            'Gonderici'    => $v('GONDERICI', 'GONDERICI_ADI'),
            'Alici'        => $v('ALICI', 'ALICI_ADI'),
            'AliciIl'      => $v('SEHIR'),
            'CikisSube'    => $v('CIKIS_SUBE', 'CIKIS_SUBESI'),
            'VarisSube'    => $v('VARIS_SUBE', 'VARIS_SUBESI'),
            'CikisTarihi'  => self::tarih($v('CIKIS_TARIH', 'DUZENLENME_TARIHI', 'DUZENLEME_TARIHI')),
            'Adet'         => ($a = $v('ADET')) !== null ? (int)$a : null,
            'Desi'         => self::sayi($v('DESI', 'HACIMSEL_AGIRLIK')),
            'Agirlik'      => self::sayi($v('AGIRLIK')),
            'Tutar'        => self::sayi($v('TUTAR')),
            'OdemeTipi'    => $v('ODEME_TIPI', 'ODEME_TURU'),
            'DurumKodu'    => $durumKodu !== null ? (int)$durumKodu : null,
            'DurumMetni'   => $v('DURUMU'),
            'TeslimAlan'   => $v('TESLIM_ALAN'),
            'TeslimTarihi' => $teslimTarihi,
        ];
    }

    /**
     * Aras farklı sorgularda farklı tarih biçimi döndürüyor:
     *   2026-10-06T00:00:00+03:00 · 14.09.2026 14:59:39 · 07/10/2026 12:52
     */
    public static function tarih(?string $s): ?string
    {
        $s = trim((string)$s);
        if ($s === '') return null;

        foreach (['Y-m-d\TH:i:sP', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $bicim) {
            $dt = DateTime::createFromFormat($bicim, $s);
            if ($dt && DateTime::getLastErrors() === false) {
                if (str_contains($bicim, 'P')) $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                return $dt->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /**
     * Türkçe kurallarla kelime başı büyük harf: "TESLİMAT ŞUBESİNDE" → "Teslimat Şubesinde"
     * (mb_strtolower 'İ' harfini "i + birleşik nokta" yapıyor)
     */
    public static function trBaslik(string $s): string
    {
        $s = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $s), 'UTF-8');

        return preg_replace_callback('/(^|\s)(\S)/u', function ($m) {
            $harf = ['i' => 'İ', 'ı' => 'I'][$m[2]] ?? mb_strtoupper($m[2], 'UTF-8');
            return $m[1] . $harf;
        }, $s);
    }

    /**
     * Telefonu Aras'a giden tek biçime çevirir: 0 + 10 hane (05321234567).
     * "+90 532 ...", "0532 ...", "532-..." aynı sonucu verir.
     * @return string|null Geçersizse null (10 haneli çekirdek değil veya alan kodu 2/3/4/5/8 ile başlamıyor)
     */
    public static function telefonNormalize(?string $s): ?string
    {
        $r = preg_replace('/\D/', '', (string)$s);

        if (strlen($r) === 12 && str_starts_with($r, '90')) $r = substr($r, 2);
        elseif (strlen($r) === 11 && $r[0] === '0')       $r = substr($r, 1);

        return (strlen($r) === 10 && strpos('23458', $r[0]) !== false) ? '0' . $r : null;
    }

    /** "90.54" ve "78,36" ikisi de kabul edilir */
    public static function sayi(?string $s): ?float
    {
        if ($s === null || $s === '') return null;
        return (float)str_replace(',', '.', $s);
    }

    // ================================================================
    // SOAP
    // ================================================================

    /** @return SimpleXMLElement[]|null Hata durumunda null, kayıt yoksa [] */
    private function sorgu(int $tip, string $ekAlanlar, string $hedef): ?array
    {
        if (!$this->hazirMi()) return null;

        $login = '<LoginInfo><UserName>' . $this->xml($this->kanal['kullanici']) . '</UserName>'
               . '<Password>' . $this->xml($this->kanal['sifre']) . '</Password>'
               . '<CustomerCode>' . $this->xml($this->kanal['musteriKodu']) . '</CustomerCode></LoginInfo>';
        $sorgu = '<QueryInfo><QueryType>' . $tip . '</QueryType>' . $ekAlanlar . '</QueryInfo>';

        $govde = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
               . '<GetQueryXML xmlns="http://tempuri.org/">'
               . '<loginInfo>' . $this->xml($login) . '</loginInfo>'
               . '<queryInfo>' . $this->xml($sorgu) . '</queryInfo>'
               . '</GetQueryXML></soap:Body></soap:Envelope>';

        $r = $this->soap($this->kanal['takipUrl'], 'http://tempuri.org/IArasCargoIntegrationService/GetQueryXML', $govde, '1.1');

        $sonuc    = null;
        $basarili = false;
        $hata     = null;

        if ($r['err'] !== '') {
            $hata = $r['err'];
        } elseif ($r['code'] !== 200) {
            // Aras kimlik hatasında da HTTP 500 InternalServiceFault döndürüyor
            $hata = 'HTTP ' . $r['code'] . ($r['code'] === 500 ? ' — kimlik bilgilerini kontrol edin' : '');
        } elseif (preg_match('/<GetQueryXMLResult>(.*)<\/GetQueryXMLResult>/s', $r['res'], $m)) {
            $ic = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $xml = trim($ic) === '' ? null : @simplexml_load_string($ic);
            if (trim($ic) === '' || $xml !== false) {
                $sonuc    = $xml ? array_values(iterator_to_array($xml->children(), false)) : [];
                $basarili = true;
            } else {
                $hata = 'Yanıt ayrıştırılamadı';
            }
        } else {
            $hata = 'Beklenmeyen yanıt';
        }

        $this->hata = $hata ?? '';
        $this->log('ARAS_QT' . $tip, $govde, mb_substr($r['res'] ?: $r['err'], 0, self::LOG_CEVAP_LIMIT), $basarili, $hata, $hedef);

        return $sonuc;
    }

    private function soap(string $url, string $action, string $govde, string $surum): array
    {
        $basliklar = $surum === '1.2'
            ? ['Content-Type: application/soap+xml; charset=utf-8; action="' . $action . '"']
            : ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . $action . '"'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $govde,
            CURLOPT_HTTPHEADER     => $basliklar,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 90,
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['res' => (string)$res, 'err' => $err, 'code' => (int)$code];
    }

    private function log(string $islem, string $istek, string $cevap, bool $basarili, ?string $hata, string $hedef): void
    {
        // Şifre hiçbir biçimde loga düşmez (düz, XML-escape'li ve iç içe escape'li hâlleri)
        $sifre = (string)($this->kanal['sifre'] ?? '');
        if ($sifre !== '') {
            $istek = str_replace([$sifre, $this->xml($sifre), $this->xml($this->xml($sifre))], '***', $istek);
        }

        try {
            $this->db->execute("
                INSERT INTO EntegrasyonLoglari (
                    EntegrasyonLoglari_EntegrasyonKanallari_id, EntegrasyonLoglari_IslemTipi,
                    EntegrasyonLoglari_Istek, EntegrasyonLoglari_Cevap, EntegrasyonLoglari_BasariliMi,
                    EntegrasyonLoglari_HataMesaji, EntegrasyonLoglari_Hedef, EntegrasyonLoglari_KullaniciId,
                    EntegrasyonLoglari_IP, EntegrasyonLoglari_Kaynak,
                    EntegrasyonLoglari_OlusturanKullanici, EntegrasyonLoglari_OlusturmaTarihi,
                    EntegrasyonLoglari_Durum
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), 1)
            ", [
                $this->kanalId() ?: null,
                $islem,
                $istek,
                $cevap,
                $basarili ? 1 : 0,
                $hata ? mb_substr($hata, 0, 1000) : null,
                mb_substr($hedef, 0, 200),
                $this->kullaniciId,
                isset($_SERVER['REMOTE_ADDR']) ? mb_substr($_SERVER['REMOTE_ADDR'], 0, 50) : null,
                $this->kaynak,
                $this->kullaniciId,
            ]);
        } catch (Throwable $e) {
            error_log('ArasKargo log hatası: ' . $e->getMessage());
        }
    }

    private function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function etiket(string $xml, string $ad): string
    {
        return preg_match('/<(?:\w+:)?' . $ad . '>([^<]*)</', $xml, $m) ? trim(html_entity_decode($m[1])) : '';
    }

    /** Aras bitiş gününü kapsamıyor; o günün kargoları için +1 gün gönderilir */
    private function tarihAraligi(DateTimeInterface $bas, DateTimeInterface $bit): string
    {
        $bitis = DateTime::createFromInterface($bit)->modify('+1 day');
        return '<StartDate>' . $bas->format('d.m.Y') . '</StartDate><EndDate>' . $bitis->format('d.m.Y') . '</EndDate>';
    }
}
