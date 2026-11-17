<?php
/**
 * Pazaryeri siparişi ↔ giden fatura eşleştirmesi
 *
 * Portal dışında (Örnek Holding panelinde vb.) kesilip senkronla gelen giden faturaları
 * faturası görünmeyen pazaryeri siparişlerine bağlar (PazaryeriSiparisleri_Faturalar_id).
 *
 * Aday kuralı:
 *   - Tutar farkı ≤ tolerans (ödenecek tutar ↔ sipariş toplamı)
 *   - Fatura tarihi: sipariş günü … sipariş günü + N gün
 *   - ve şunlardan biri:
 *       · VKN/TCKN eşit (nihai tüketici numaraları hariç) → firma aranmaz
 *       · Cari ünvan = müşteri/alıcı adı → aynı firma şartı (iki kanalın
 *         EntegrasyonKanallari_Firmalar_id değeri); isimler firmalar arası çakışabilir
 *       · İstisna: pazaryeri kaydında fatura linki olan siparişte (fatura kesin kesilmiş)
 *         ünvan eşleşmesinde firma aranmaz — sipariş bir firmanın mağazasında, fatura başka
 *         firmanın Örnek Holding hesabından kesilmiş olabilir (ör. ÖRNEK Trendyol ↔ Örnek Müşteri B ITE)
 *   - Ad karşılaştırması Türkçe karakterden bağımsızdır (adNorm: u/ü, i/ı/İ, ş/s, büyük/küçük)
 *
 * Otomatik bağlama yalnız kesin eşleşmede yapılır (bkz. otomatik()).
 * Diğerleri "belirsiz" olarak listelenir, seçimi kullanıcı yapar.
 *
 * Eşleştirme pazaryerine fatura bildirimi GÖNDERMEZ.
 *
 * Ayarlar (dbo.PazaryeriAyarlari, genel satır: pazaryeri ve kanal NULL):
 *   fatura_eslestirme_gun       → tarih penceresi (gün)
 *   fatura_eslestirme_tolerans  → tutar toleransı (TL)
 */
class FaturaEslestirme
{
    /** Ayar satırı yoksa kullanılan değerler */
    const VARSAYILAN_GUN      = 15;
    const VARSAYILAN_TOLERANS = 0.05;

    /** GİB nihai tüketici / yabancı alıcı genel numaraları: kişiyi ayırt etmez */
    const GENEL_VKN = "'11111111111', '2222222222'";

    /**
     * Aday join'i firma eşitliği + aralık koşullarından oluşur; optimizer iç içe döngü
     * seçince CTE'ler her çift için yeniden okunur (~60 sn). Hash join ~1 sn.
     */
    const IPUCU = ' OPTION (HASH JOIN, RECOMPILE)';

    private $db;
    private $userId;
    private $gun;
    private $tolerans;

    public function __construct($db, $userId)
    {
        $this->db     = $db;
        $this->userId = (int)$userId ?: 1;

        $this->gun      = (int)$this->ayar('fatura_eslestirme_gun', self::VARSAYILAN_GUN);
        $this->tolerans = (float)$this->ayar('fatura_eslestirme_tolerans', self::VARSAYILAN_TOLERANS);
        if ($this->gun <= 0 || $this->gun > 90)          { $this->gun = self::VARSAYILAN_GUN; }
        if ($this->tolerans < 0 || $this->tolerans > 10) { $this->tolerans = self::VARSAYILAN_TOLERANS; }
    }

    private function ayar($anahtar, $varsayilan)
    {
        $r = $this->db->fetchOne("
            SELECT TOP 1 PazaryeriAyarlari_Deger
            FROM dbo.PazaryeriAyarlari
            WHERE PazaryeriAyarlari_Anahtar = ? AND PazaryeriAyarlari_Durum = 1
              AND PazaryeriAyarlari_Pazaryerleri_id IS NULL
              AND PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL
        ", [$anahtar]);
        $d = $r['PazaryeriAyarlari_Deger'] ?? null;
        return is_numeric($d) ? $d + 0 : $varsayilan;
    }

    /**
     * Cron kapsamı: pazaryerine bağlı tüm kanallar (pasif firmanınkiler hariç).
     * Sayfadaki kapsamKanallari() ile aynı kural, firma kısıtı olmadan.
     *
     * @return int[]
     */
    public static function tumPazaryeriKanallari($db)
    {
        return array_map('intval', array_column($db->fetchAll("
            SELECT k.EntegrasyonKanallari_id AS id
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
            INNER JOIN dbo.tanim_Pazaryerleri p ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
            LEFT JOIN dbo.Firmalar f ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
            WHERE k.EntegrasyonKanallari_Firmalar_id IS NULL OR f.firma_durum = 1
        ") ?: [], 'id'));
    }

    public function getGun()      { return $this->gun; }
    public function getTolerans() { return $this->tolerans; }

    /**
     * Ad karşılaştırması için normalize SQL ifadesi: büyük harf (Türkçe), Türkçe/şapkalı harfler
     * Latin karşılığına, çift boşluk tekleşir, nokta atılır. "ahmet gultekin" = "AHMET GÜLTEKİN".
     */
    private static function adNorm($ifade)
    {
        $x = "UPPER(LTRIM(RTRIM($ifade)) COLLATE Turkish_CI_AS)";
        foreach ([['Ç', 'C'], ['Ğ', 'G'], ['İ', 'I'], ['Ö', 'O'], ['Ş', 'S'], ['Ü', 'U'], ['Â', 'A'], ['Î', 'I'], ['Û', 'U']] as [$a, $b]) {
            $x = "REPLACE($x, N'$a', '$b')";
        }
        return "REPLACE(REPLACE(REPLACE($x, '  ', ' '), '  ', ' '), '.', '')";
    }

    /**
     * Aday çiftleri CTE'si (son CTE: say → sid, fid, vkn_eslesti, sid_aday, fid_aday).
     *
     * Uygun sipariş: kapsamdaki kanal, iptal değil, faturası yok ya da bağlı faturası iptal.
     * Taslak / hatalı faturası olan siparişe dokunulmaz (Örnek Holding'de taslağı vardır).
     * Uygun fatura: giden, kesinleşmiş, iptal değil, hiçbir siparişe bağlı değil.
     *
     * @param int[] $kanalIdler Kapsamdaki pazaryeri kanalları (int)
     * @return array [sql, params]
     */
    private function adayCte(array $kanalIdler, $siparisId = null)
    {
        $kanalIn = $kanalIdler
            ? 's.PazaryeriSiparisleri_EntegrasyonKanallari_id IN (' . implode(',', array_map('intval', $kanalIdler)) . ')'
            : '1 = 0';
        $vknGecerli = "%1\$s IS NOT NULL AND LEN(%1\$s) >= 10 AND %1\$s NOT IN (" . self::GENEL_VKN . ")";

        $sql = "
            WITH sip AS (
                SELECT s.PazaryeriSiparisleri_id                           AS sid,
                       s.PazaryeriSiparisleri_ToplamTutar                  AS tutar,
                       CAST(s.PazaryeriSiparisleri_SiparisTarihi AS DATE)  AS trh,
                       " . self::adNorm('s.PazaryeriSiparisleri_MusteriAdi') . " AS ad,
                       " . self::adNorm('s.PazaryeriSiparisleri_AliciAdi') . "   AS alici,
                       -- Pazaryerinin kaydında fatura linki var: fatura kesin kesilmiş
                       CASE WHEN ISJSON(s.PazaryeriSiparisleri_HamVeri) = 1
                             AND NULLIF(JSON_VALUE(s.PazaryeriSiparisleri_HamVeri, '$.invoiceLink'), '') IS NOT NULL
                            THEN 1 ELSE 0 END                              AS py_fatura,
                       NULLIF(LTRIM(RTRIM(s.PazaryeriSiparisleri_TCKN)), '')    AS tckn,
                       NULLIF(LTRIM(RTRIM(s.PazaryeriSiparisleri_VergiNo)), '') AS vno,
                       k.EntegrasyonKanallari_Firmalar_id                  AS firma
                FROM dbo.PazaryeriSiparisleri s
                INNER JOIN dbo.EntegrasyonKanallari k
                        ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                LEFT JOIN dbo.Faturalar mf
                        ON mf.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id
                WHERE $kanalIn
                  AND s.PazaryeriSiparisleri_Durum = 1
                  AND ISNULL(s.PazaryeriSiparisleri_DurumKod, '') <> 'iptal'
                  AND s.PazaryeriSiparisleri_ToplamTutar > 0
                  AND s.PazaryeriSiparisleri_SiparisTarihi IS NOT NULL
                  AND k.EntegrasyonKanallari_Firmalar_id IS NOT NULL
                  AND (mf.Faturalar_id IS NULL OR mf.Faturalar_IptalDurumu = 1)
                  " . ($siparisId ? 'AND s.PazaryeriSiparisleri_id = ?' : '') . "
            ),
            fat AS (
                SELECT f.Faturalar_id                                  AS fid,
                       f.Faturalar_OdenecekTutar                       AS tutar,
                       f.Faturalar_Tarih                               AS trh,
                       " . self::adNorm('f.Faturalar_CariUnvan') . "   AS unvan,
                       NULLIF(LTRIM(RTRIM(f.Faturalar_CariVKN)), '')   AS vkn,
                       k.EntegrasyonKanallari_Firmalar_id              AS firma
                FROM dbo.Faturalar f
                INNER JOIN dbo.EntegrasyonKanallari k
                        ON k.EntegrasyonKanallari_id = f.Faturalar_EntegrasyonKanallari_id
                WHERE f.Faturalar_Yon = 'GIDEN'
                  AND f.Faturalar_Durum = 1
                  AND f.Faturalar_IptalDurumu = 0
                  -- Numarası atanmamış (…000000000) kayıtlar aynı faturanın geçici kopyasıdır
                  AND f.Faturalar_FaturaNo IS NOT NULL
                  AND f.Faturalar_FaturaNo NOT LIKE '%000000000'
                  AND NOT EXISTS (SELECT 1 FROM dbo.PazaryeriSiparisleri p
                                  WHERE p.PazaryeriSiparisleri_Faturalar_id = f.Faturalar_id)
            ),
            -- Üç yol (hash join eşitlik anahtarı ister, OR'lu join yerine UNION ALL):
            --   1. Firma içi: ünvan ya da VKN/TCKN eşit
            --   2-3. VKN/TCKN eşit (vergi no / TC kimlik): firma aranmaz; fatura başka
            --        firmanın ya da firması tanımsız kanalın olabilir (firma_farkli = 1)
            yol AS (
                SELECT s.sid, f.fid,
                       CASE WHEN " . sprintf($vknGecerli, 'f.vkn') . " AND f.vkn IN (s.tckn, s.vno)
                            THEN 1 ELSE 0 END AS vkn_eslesti,
                       CASE WHEN f.unvan IN (s.ad, s.alici) THEN 1 ELSE 0 END AS unvan_eslesti,
                       0 AS firma_farkli
                FROM sip s
                INNER JOIN fat f
                        ON f.firma = s.firma
                       AND ABS(f.tutar - s.tutar) <= ?
                       AND f.trh >= s.trh
                       AND f.trh <= DATEADD(DAY, ?, s.trh)
                WHERE (" . sprintf($vknGecerli, 'f.vkn') . " AND f.vkn IN (s.tckn, s.vno))
                   OR f.unvan IN (s.ad, s.alici)
                UNION ALL
                SELECT s.sid, f.fid, 1,
                       CASE WHEN f.unvan IN (s.ad, s.alici) THEN 1 ELSE 0 END,
                       CASE WHEN f.firma = s.firma THEN 0 ELSE 1 END
                FROM sip s
                INNER JOIN fat f
                        ON f.vkn = s.vno
                       AND ABS(f.tutar - s.tutar) <= ?
                       AND f.trh >= s.trh
                       AND f.trh <= DATEADD(DAY, ?, s.trh)
                WHERE " . sprintf($vknGecerli, 'f.vkn') . "
                UNION ALL
                SELECT s.sid, f.fid, 1,
                       CASE WHEN f.unvan IN (s.ad, s.alici) THEN 1 ELSE 0 END,
                       CASE WHEN f.firma = s.firma THEN 0 ELSE 1 END
                FROM sip s
                INNER JOIN fat f
                        ON f.vkn = s.tckn
                       AND ABS(f.tutar - s.tutar) <= ?
                       AND f.trh >= s.trh
                       AND f.trh <= DATEADD(DAY, ?, s.trh)
                WHERE " . sprintf($vknGecerli, 'f.vkn') . "
                UNION ALL
                -- 4-5. Firmalar arası ünvan: yalnız pazaryeri kaydında fatura linki olan siparişte
                --      (fatura kesin kesilmiş; başka firmanın hesabından kesilmiş olabilir)
                SELECT s.sid, f.fid, 0, 1, 1
                FROM sip s
                INNER JOIN fat f
                        ON f.unvan = s.ad
                       AND ABS(f.tutar - s.tutar) <= ?
                       AND f.trh >= s.trh
                       AND f.trh <= DATEADD(DAY, ?, s.trh)
                WHERE s.py_fatura = 1 AND (f.firma IS NULL OR f.firma <> s.firma)
                UNION ALL
                SELECT s.sid, f.fid, 0, 1, 1
                FROM sip s
                INNER JOIN fat f
                        ON f.unvan = s.alici
                       AND ABS(f.tutar - s.tutar) <= ?
                       AND f.trh >= s.trh
                       AND f.trh <= DATEADD(DAY, ?, s.trh)
                WHERE s.py_fatura = 1 AND (f.firma IS NULL OR f.firma <> s.firma)
            ),
            aday AS (
                SELECT sid, fid,
                       MAX(vkn_eslesti)   AS vkn_eslesti,
                       MAX(unvan_eslesti) AS unvan_eslesti,
                       MIN(firma_farkli)  AS firma_farkli
                FROM yol
                GROUP BY sid, fid
            ),
            say0 AS (
                -- tam: ünvan ve VKN/TCKN ikisi birden eşit (kesin eşleşme)
                SELECT a.*,
                       COUNT(*) OVER (PARTITION BY a.sid) AS sid_aday,
                       COUNT(*) OVER (PARTITION BY a.fid) AS fid_aday,
                       SUM(a.vkn_eslesti * a.unvan_eslesti) OVER (PARTITION BY a.sid) AS sid_tam,
                       SUM(a.vkn_eslesti * a.unvan_eslesti) OVER (PARTITION BY a.fid) AS fid_tam
                FROM aday a
            ),
            say AS (
                -- fid_zorunlu: bu faturanın aday olduğu ve başka adayı OLMAYAN sipariş sayısı
                SELECT z.*,
                       SUM(CASE WHEN z.sid_aday = 1 THEN 1 ELSE 0 END) OVER (PARTITION BY z.fid) AS fid_zorunlu
                FROM say0 z
            )
        ";

        $params = $siparisId ? [(int)$siparisId] : [];
        for ($i = 0; $i < 5; $i++) {   // yol CTE'sindeki beş join
            array_push($params, $this->tolerans, $this->gun);
        }
        return [$sql, $params];
    }

    /** Zincirleme bağlamada en fazla tur sayısı (her tur yeni tekiller açabilir) */
    const AZAMI_TUR = 5;

    /**
     * Kesin (%100) eşleşmeleri bağlar:
     *   0. Pazaryeri fatura no: pazaryerinin sipariş verisindeki fatura numarası
     *             (Trendyol invoiceNumber) portaldaki faturayla aynı (bkz. pazaryeriNoIleBagla)
     *   1. Tekil: sipariş tek aday, fatura tek siparişin adayı
     *   2. Tam:   siparişin ünvan + VKN/TCKN ikisi birden eşit TEK adayı var ve
     *             o fatura başka bir siparişin tam adayı değil
     *   3. Zorunlu: siparişin tek adayı var; o faturayı paylaşan diğer siparişlerin
     *             hepsinin başka adayı var (fatura bu siparişe verilmezse sipariş açıkta kalır)
     *   4. Grup:  tekil kural kalmayınca, aynı ürünlü N sipariş ↔ yalnız onlara ait N fatura
     *             sırayla bağlanır (bkz. grupBagla)
     * Bağlanan fatura diğer siparişlerin adaylarından düşer; kalanlarda yeni
     * tekiller oluşabildiği için tur, yeni bağlama kalmayana kadar tekrarlanır.
     *
     * Aynı müşteri / tutar / tarihte birden fazla sipariş ve fatura gibi
     * ayırt edilemeyen durumlar bağlanmaz, seçime bırakılır.
     *
     * Kapsam her zaman kullanıcının tüm kanallarıdır (filtre daraltması fatura
     * paylaşımını gizleyip belirsiz eşleşmeyi tekil gösterebilir).
     *
     * @return array ['eslesen' => n, 'belirsiz' => n]
     */
    public function otomatik(array $kanalIdler)
    {
        // 0. Pazaryerinin kendi kaydındaki fatura numarası (kesin bilgi) tahmin kurallarından önce
        $toplam = $this->pazaryeriNoIleBagla($kanalIdler);

        for ($tur = 0; $tur < self::AZAMI_TUR; $tur++) {
            [$cte, $params] = $this->adayCte($kanalIdler);

            // Farklı kurallar aynı faturayı iki siparişe önerebilir; seçimde her fatura
            // ve her sipariş tek kez geçmeli, çakışan çiftler bu turda bağlanmaz.
            $bagli = $this->db->fetchAll($cte . "
                , secim AS (
                    SELECT t.sid, t.fid,
                           COUNT(*) OVER (PARTITION BY t.fid) AS f_adet,
                           COUNT(*) OVER (PARTITION BY t.sid) AS s_adet
                    FROM say t
                    WHERE (t.sid_aday = 1 AND t.fid_aday = 1)
                       OR (t.vkn_eslesti = 1 AND t.unvan_eslesti = 1 AND t.sid_tam = 1 AND t.fid_tam = 1)
                       OR (t.sid_aday = 1 AND t.fid_zorunlu = 1)
                )
                UPDATE s
                   SET s.PazaryeriSiparisleri_Faturalar_id       = t.fid,
                       s.PazaryeriSiparisleri_GuncelleyenKullanici = ?,
                       s.PazaryeriSiparisleri_GuncellemeTarihi     = ?
                OUTPUT inserted.PazaryeriSiparisleri_id
                FROM dbo.PazaryeriSiparisleri s
                INNER JOIN secim t ON t.sid = s.PazaryeriSiparisleri_id
                WHERE t.f_adet = 1 AND t.s_adet = 1
                " . self::IPUCU . "
            ", array_merge($params, [$this->userId, date('Y-m-d H:i:s')]));

            $adet = count($bagli ?: []);
            if ($adet === 0) {
                // Tekil kural kalmadı: birbirinin yerine geçebilen sipariş/fatura grupları
                $adet = $this->grupBagla($kanalIdler);
            }
            $toplam += $adet;
            if ($adet === 0) { break; }
        }

        return [
            'eslesen'  => $toplam,
            'belirsiz' => $this->belirsizSayisi($kanalIdler),
        ];
    }

    /**
     * Birbirinin yerine geçebilen siparişler (grup kuralı).
     *
     * Aynı müşterinin aynı ürün ve tutardaki N siparişi ile N faturası tekil kurallarla
     * ayrılamaz (faturalarda sipariş no yok; ör. Örnek Holding panelinden kesilen e-Arşiv).
     * Şu şartlarda grup sırayla bağlanır (en eski sipariş ↔ en küçük fatura no):
     *   - Gruptaki her siparişin aday kümesi aynı N fatura
     *   - Bu faturalar yalnız bu N siparişin adayı (fid_aday = N, dışarıya pay yok)
     *   - Siparişlerin ürünleri (stok kodu/ad + miktar) birebir aynı
     * Hangi faturanın hangi siparişe gittiği içerik olarak fark etmez; her sipariş bir
     * fatura alır, hiçbir fatura iki kez kullanılmaz.
     *
     * @return int Bağlanan sipariş sayısı
     */
    private function grupBagla(array $kanalIdler)
    {
        [$cte, $params] = $this->adayCte($kanalIdler);
        $satirlar = $this->db->fetchAll($cte . "
            SELECT t.sid, t.fid, t.sid_aday, t.fid_aday,
                   CONVERT(VARCHAR(19), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS sip_trh,
                   f.Faturalar_FaturaNo AS fno
            FROM say t
            INNER JOIN dbo.PazaryeriSiparisleri s ON s.PazaryeriSiparisleri_id = t.sid
            INNER JOIN dbo.Faturalar f            ON f.Faturalar_id = t.fid
            WHERE t.sid_aday > 1
            " . self::IPUCU, $params) ?: [];
        if (!$satirlar) { return 0; }

        // Sipariş → aday faturalar (tam küme); aday kümesi anahtarına göre gruplar
        $sipFat  = [];
        $sipTrh  = [];
        $fatNo   = [];
        $fatAday = [];
        foreach ($satirlar as $r) {
            $sipFat[(int)$r['sid']][] = (int)$r['fid'];
            $sipTrh[(int)$r['sid']]   = (string)$r['sip_trh'];
            $fatNo[(int)$r['fid']]    = (string)$r['fno'];
            $fatAday[(int)$r['fid']]  = (int)$r['fid_aday'];
        }
        $gruplar = [];
        foreach ($sipFat as $sid => $fidler) {
            sort($fidler);
            // Kümedeki her fatura yalnız N siparişin adayı olmalı (gruba dışarıdan pay yok)
            $n = count($fidler);
            if (array_filter($fidler, fn($f) => $fatAday[$f] !== $n)) { continue; }
            $gruplar[implode(',', $fidler)][] = $sid;
        }
        if (!$gruplar) { return 0; }

        // Ürün imzası (aynı ürün/miktar şartı)
        $imza = [];
        $tumSid = array_merge(...array_values($gruplar));
        foreach ($this->db->fetchAll("
            SELECT PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id AS sid,
                   ISNULL(NULLIF(LTRIM(RTRIM(PazaryeriSiparisKalemleri_StokKodu)), ''), PazaryeriSiparisKalemleri_UrunAdi) AS urun,
                   PazaryeriSiparisKalemleri_Miktar AS miktar
            FROM dbo.PazaryeriSiparisKalemleri
            WHERE PazaryeriSiparisKalemleri_Durum = 1
              AND PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id IN (" . implode(',', $tumSid) . ")
        ") ?: [] as $k) {
            $imza[(int)$k['sid']][] = mb_strtoupper(trim((string)$k['urun'])) . '×' . (float)$k['miktar'];
        }

        $bagli = 0;
        foreach ($gruplar as $anahtar => $sidler) {
            $fidler = array_map('intval', explode(',', $anahtar));
            if (count($sidler) !== count($fidler)) { continue; }

            $imzalar = [];
            foreach ($sidler as $sid) {
                $i = $imza[$sid] ?? [];
                sort($i);
                $imzalar[implode('|', $i)] = true;
            }
            if (count($imzalar) !== 1 || array_key_first($imzalar) === '') { continue; }

            usort($sidler, fn($a, $b) => [$sipTrh[$a], $a] <=> [$sipTrh[$b], $b]);
            usort($fidler, fn($a, $b) => [$fatNo[$a], $a] <=> [$fatNo[$b], $b]);

            foreach ($sidler as $i => $sid) {
                // bagla() ile aynı koruma: sipariş hâlâ faturasız, fatura hâlâ boşta
                $ok = $this->db->fetchOne("
                    UPDATE s
                       SET s.PazaryeriSiparisleri_Faturalar_id         = ?,
                           s.PazaryeriSiparisleri_GuncelleyenKullanici = ?,
                           s.PazaryeriSiparisleri_GuncellemeTarihi     = ?
                    OUTPUT inserted.PazaryeriSiparisleri_id
                    FROM dbo.PazaryeriSiparisleri s
                    LEFT JOIN dbo.Faturalar mf ON mf.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id
                    WHERE s.PazaryeriSiparisleri_id = ?
                      AND (mf.Faturalar_id IS NULL OR mf.Faturalar_IptalDurumu = 1)
                      AND NOT EXISTS (SELECT 1 FROM dbo.PazaryeriSiparisleri p
                                      WHERE p.PazaryeriSiparisleri_Faturalar_id = ?)
                ", [$fidler[$i], $this->userId, date('Y-m-d H:i:s'), $sid, $fidler[$i]]);
                if ($ok) { $bagli++; }
            }
        }
        return $bagli;
    }

    /**
     * Pazaryerinin sipariş kaydında bildirilmiş fatura numarasıyla bağlama.
     *
     * Pazaryeri siparişe hangi faturanın gönderildiğini kendisi söylüyor
     * (HamVeri.invoiceNumber; şu an yalnız Trendyol döner); tarih/tutar/ünvan
     * tahmini gerekmez. Fatura numarası firmalar arasında benzersiz DEĞİLDİR
     * (aynı seri öneki farklı firmalarda kullanılabiliyor):
     *   - Siparişin firmasında eşleşen fatura varsa yalnız onlara bakılır
     *   - Yoksa başka firmadaki eşleşme yalnız tutar da tutuyorsa kabul edilir
     *   - Seçilen kümede tek fatura kalmalı; fatura başka siparişe bağlı olmamalı
     * Faturası olmayan / iptal siparişler bağlanır; tahminle (senkron faturasına) bağlanmış
     * ama numarası pazaryerininkinden farklı olan bağlantı pazaryerinin faturasına çevrilir.
     * Portaldan kesilmiş fatura bağlantısı hiçbir zaman değiştirilmez.
     *
     * @return int Bağlanan sipariş sayısı
     */
    public function pazaryeriNoIleBagla(array $kanalIdler)
    {
        if (!$kanalIdler) { return 0; }
        $kanalIn = implode(',', array_map('intval', $kanalIdler));

        $bagli = $this->db->fetchAll("
            WITH sip0 AS (
                SELECT s.PazaryeriSiparisleri_id          AS sid,
                       s.PazaryeriSiparisleri_ToplamTutar AS tutar,
                       k.EntegrasyonKanallari_Firmalar_id AS firma,
                       mf.Faturalar_id                    AS mevcut_fid,
                       mf.Faturalar_IptalDurumu           AS mevcut_iptal,
                       mf.Faturalar_KesimDurumu           AS mevcut_kesim,
                       mf.Faturalar_FaturaNo              AS mevcut_no,
                       CASE WHEN ISJSON(s.PazaryeriSiparisleri_HamVeri) = 1
                            THEN NULLIF(LTRIM(RTRIM(JSON_VALUE(s.PazaryeriSiparisleri_HamVeri, '\$.invoiceNumber'))), '')
                       END AS pno
                FROM dbo.PazaryeriSiparisleri s
                INNER JOIN dbo.EntegrasyonKanallari k
                        ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                LEFT JOIN dbo.Faturalar mf
                        ON mf.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id
                WHERE s.PazaryeriSiparisleri_EntegrasyonKanallari_id IN ($kanalIn)
                  AND s.PazaryeriSiparisleri_Durum = 1
            ),
            sip AS (
                -- Faturası yok / iptal, ya da tahminle bağlanmış (senkron faturası, KesimDurumu boş)
                -- faturanın numarası pazaryerinin bildirdiğinden farklı → düzeltilir.
                -- Portaldan kesilen faturaya (KesimDurumu dolu) dokunulmaz.
                SELECT * FROM sip0
                WHERE pno IS NOT NULL
                  AND (   mevcut_fid IS NULL
                       OR mevcut_iptal = 1
                       OR (mevcut_kesim IS NULL AND ISNULL(mevcut_no, '') <> pno))
            ),
            es AS (
                SELECT s.sid, f.Faturalar_id AS fid,
                       CASE WHEN fk.EntegrasyonKanallari_Firmalar_id = s.firma THEN 1 ELSE 0 END AS ayni_firma,
                       CASE WHEN ABS(f.Faturalar_OdenecekTutar - s.tutar) <= ? THEN 1 ELSE 0 END AS tutar_ok
                FROM sip s
                INNER JOIN dbo.Faturalar f
                        ON f.Faturalar_FaturaNo = s.pno
                       AND f.Faturalar_Yon = 'GIDEN'
                       AND f.Faturalar_Durum = 1
                       AND f.Faturalar_IptalDurumu = 0
                INNER JOIN dbo.EntegrasyonKanallari fk
                        ON fk.EntegrasyonKanallari_id = f.Faturalar_EntegrasyonKanallari_id
                WHERE s.pno IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM dbo.PazaryeriSiparisleri p
                                  WHERE p.PazaryeriSiparisleri_Faturalar_id = f.Faturalar_id)
            ),
            gecerli AS (
                -- Aynı firmada eşleşme varsa başka firmadakiler elenir
                SELECT e.*, MAX(e.ayni_firma) OVER (PARTITION BY e.sid) AS firma_var
                FROM es e
            ),
            secim AS (
                SELECT g.sid, g.fid,
                       COUNT(*) OVER (PARTITION BY g.sid) AS s_adet,
                       COUNT(*) OVER (PARTITION BY g.fid) AS f_adet
                FROM gecerli g
                WHERE (g.firma_var = 1 AND g.ayni_firma = 1)
                   OR (g.firma_var = 0 AND g.tutar_ok = 1)
            )
            UPDATE s
               SET s.PazaryeriSiparisleri_Faturalar_id       = t.fid,
                   s.PazaryeriSiparisleri_GuncelleyenKullanici = ?,
                   s.PazaryeriSiparisleri_GuncellemeTarihi     = ?
            OUTPUT inserted.PazaryeriSiparisleri_id
            FROM dbo.PazaryeriSiparisleri s
            INNER JOIN secim t ON t.sid = s.PazaryeriSiparisleri_id
            WHERE t.s_adet = 1 AND t.f_adet = 1
        ", [$this->tolerans, $this->userId, date('Y-m-d H:i:s')]);

        return count($bagli ?: []);
    }

    public function belirsizSayisi(array $kanalIdler)
    {
        [$cte, $params] = $this->adayCte($kanalIdler);
        $r = $this->db->fetchOne($cte . " SELECT COUNT(DISTINCT sid) AS adet FROM say" . self::IPUCU, $params);
        return (int)($r['adet'] ?? 0);
    }

    /**
     * Elle seçim bekleyen siparişler ve adayları (sipariş başına satırlar).
     * Otomatik çalıştıktan sonra kalan her aday zaten belirsizdir.
     */
    public function belirsizler(array $kanalIdler, $limit = 300)
    {
        [$cte, $params] = $this->adayCte($kanalIdler);
        $limit = max(1, min(1000, (int)$limit));

        $satirlar = $this->db->fetchAll($cte . "
            , secili AS (
                SELECT TOP ($limit) sid FROM say GROUP BY sid ORDER BY MIN(sid) DESC
            )
            SELECT t.sid, t.fid, t.vkn_eslesti, t.unvan_eslesti, t.firma_farkli, t.sid_aday, t.fid_aday,
                   s.PazaryeriSiparisleri_SiparisNo,
                   s.PazaryeriSiparisleri_MusteriAdi,
                   s.PazaryeriSiparisleri_ToplamTutar,
                   s.PazaryeriSiparisleri_ParaBirimi,
                   CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120) AS PazaryeriSiparisleri_SiparisTarihi,
                   s.PazaryeriSiparisleri_KargoTakipNo,
                   p.Pazaryerleri_Ad,
                   f.Faturalar_FaturaNo,
                   f.Faturalar_Tur,
                   f.Faturalar_CariUnvan,
                   f.Faturalar_CariVKN,
                   f.Faturalar_OdenecekTutar,
                   CONVERT(VARCHAR(10), f.Faturalar_Tarih, 120) AS Faturalar_Tarih,
                   ISNULL(NULLIF(fr.firma_adi, ''), fk.EntegrasyonKanallari_Ad) AS FaturaFirma
            FROM say t
            INNER JOIN secili x ON x.sid = t.sid
            INNER JOIN dbo.PazaryeriSiparisleri s ON s.PazaryeriSiparisleri_id = t.sid
            LEFT JOIN dbo.tanim_Pazaryerleri p    ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
            INNER JOIN dbo.Faturalar f            ON f.Faturalar_id = t.fid
            LEFT JOIN dbo.EntegrasyonKanallari fk ON fk.EntegrasyonKanallari_id = f.Faturalar_EntegrasyonKanallari_id
            LEFT JOIN dbo.Firmalar fr             ON fr.firma_id = fk.EntegrasyonKanallari_Firmalar_id
            ORDER BY t.sid DESC, t.vkn_eslesti + t.unvan_eslesti DESC, ABS(DATEDIFF(DAY, s.PazaryeriSiparisleri_SiparisTarihi, f.Faturalar_Tarih))
            " . self::IPUCU . "
        ", $params) ?: [];

        // Aynı müşteri / tutardaki siparişleri ayırt etmek için ürünler (sipariş ve fatura kalemleri)
        $sidler = array_unique(array_map('intval', array_column($satirlar, 'sid')));
        $fidler = array_unique(array_map('intval', array_column($satirlar, 'fid')));
        $sKalem = [];
        $fKalem = [];
        if ($sidler) {
            foreach ($this->db->fetchAll("
                SELECT PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id AS sid,
                       PazaryeriSiparisKalemleri_UrunAdi AS ad,
                       PazaryeriSiparisKalemleri_StokKodu AS kod,
                       PazaryeriSiparisKalemleri_Miktar AS miktar,
                       PazaryeriSiparisKalemleri_ToplamTutar AS tutar
                FROM dbo.PazaryeriSiparisKalemleri
                WHERE PazaryeriSiparisKalemleri_Durum = 1
                  AND PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id IN (" . implode(',', $sidler) . ")
                ORDER BY PazaryeriSiparisKalemleri_id
            ") ?: [] as $k) {
                $sKalem[(int)$k['sid']][] = ['ad' => $k['ad'], 'kod' => $k['kod'], 'miktar' => $k['miktar'], 'tutar' => $k['tutar']];
            }
        }
        if ($fidler) {
            foreach ($this->db->fetchAll("
                SELECT FaturaKalemleri_Faturalar_id AS fid,
                       FaturaKalemleri_UrunAdi AS ad,
                       FaturaKalemleri_Miktar AS miktar,
                       FaturaKalemleri_ToplamTutar AS tutar
                FROM dbo.FaturaKalemleri
                WHERE FaturaKalemleri_Faturalar_id IN (" . implode(',', $fidler) . ")
                ORDER BY FaturaKalemleri_Faturalar_id, FaturaKalemleri_SiraNo
            ") ?: [] as $k) {
                $fKalem[(int)$k['fid']][] = ['ad' => $k['ad'], 'miktar' => $k['miktar'], 'tutar' => $k['tutar']];
            }
        }
        foreach ($satirlar as &$r) {
            $r['siparis_kalemleri'] = $sKalem[(int)$r['sid']] ?? [];
            $r['fatura_kalemleri']  = $fKalem[(int)$r['fid']] ?? [];
        }
        unset($r);

        return $satirlar;
    }

    /** Fatura, siparişin güncel adayları arasında mı */
    public function adayMi(array $kanalIdler, $siparisId, $faturaId)
    {
        [$cte, $params] = $this->adayCte($kanalIdler, (int)$siparisId);
        return (bool)$this->db->fetchOne($cte . " SELECT sid FROM say WHERE fid = ?" . self::IPUCU,
            array_merge($params, [(int)$faturaId]));
    }

    /**
     * Kullanıcının seçtiği faturayı bağlar. Fatura, siparişin güncel adayları
     * arasında değilse reddedilir (istemciden gelen id'ye güvenilmez).
     */
    public function bagla(array $kanalIdler, $siparisId, $faturaId)
    {
        $siparisId = (int)$siparisId;
        $faturaId  = (int)$faturaId;

        [$cte, $params] = $this->adayCte($kanalIdler, $siparisId);
        $aday = $this->db->fetchOne($cte . " SELECT sid FROM say WHERE fid = ?" . self::IPUCU, array_merge($params, [$faturaId]));
        if (!$aday) {
            return ['success' => false,
                'message' => 'Bu fatura siparişe uygun aday değil ya da başka bir siparişe bağlandı; listeyi yenileyin.'];
        }

        // Eşzamanlı iki seçimde fatura yalnız bir siparişe bağlanabilir
        $bag = $this->db->fetchOne("
            UPDATE s
               SET s.PazaryeriSiparisleri_Faturalar_id       = ?,
                   s.PazaryeriSiparisleri_GuncelleyenKullanici = ?,
                   s.PazaryeriSiparisleri_GuncellemeTarihi     = ?
            OUTPUT inserted.PazaryeriSiparisleri_id
            FROM dbo.PazaryeriSiparisleri s
            LEFT JOIN dbo.Faturalar mf ON mf.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id
            WHERE s.PazaryeriSiparisleri_id = ?
              AND (mf.Faturalar_id IS NULL OR mf.Faturalar_IptalDurumu = 1)
              AND NOT EXISTS (SELECT 1 FROM dbo.PazaryeriSiparisleri p
                              WHERE p.PazaryeriSiparisleri_Faturalar_id = ?)
        ", [$faturaId, $this->userId, date('Y-m-d H:i:s'), $siparisId, $faturaId]);

        return $bag
            ? ['success' => true, 'message' => 'Fatura siparişe bağlandı.']
            : ['success' => false, 'message' => 'Sipariş ya da fatura bu arada değişti; listeyi yenileyin.'];
    }
}
