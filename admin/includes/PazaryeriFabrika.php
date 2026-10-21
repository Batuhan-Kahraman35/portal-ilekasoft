<?php
/**
 * Pazaryeri API Fabrikası
 *
 * Hangi pazaryeri için hangi sınıfın kullanılacağı KODA GÖMÜLMEZ;
 * tanim_Pazaryerleri.Pazaryerleri_SinifAdi kolonundan okunur.
 *
 * Yeni bir pazaryeri eklemek = 1 PHP sınıfı + 1 veritabanı satırı.
 * Sayfalarda, cron'da ve bu dosyada değişiklik gerekmez.
 *
 * Kullanım:
 *   $api = PazaryeriFabrika::olustur('trendyol', $userId);
 *   $api = PazaryeriFabrika::olusturKanaldan($kanalId, $userId);   // çok firmalı
 *   if (!$api) { ... PazaryeriFabrika::sonHata() ... }
 *
 * Firma kısıtı (portal): liste metotlarına PageAuth::firmaKisiti() sonucu
 * opsiyonel $kisitFirmaId olarak verilebilir. Verilmezse (null) kısıt uygulanmaz.
 */

require_once __DIR__ . '/PazaryeriApi.php';

if (!class_exists('PageAuth')) {
    require_once __DIR__ . '/PageAuth.php';
}

class PazaryeriFabrika
{
    /** Sınıf dosyalarının bulunduğu dizin */
    const SINIF_DIZINI = __DIR__;

    private static $sonHata = '';

    /** Aynı istek içinde tekrar tekrar nesne üretilmesin */
    private static $onbellek = [];

    /**
     * Pazaryeri koduna göre API nesnesi üret.
     *
     * @param string   $pazaryeriKod tanim_Pazaryerleri.Pazaryerleri_Kod
     * @param int|null $userId       işlemi yapan kullanıcı
     * @param int|null $kanalId      belirli bir firma hesabı; null ise varsayılan kanal
     * @return PazaryeriApi|null     üretilemezse null (sebep: sonHata())
     */
    public static function olustur($pazaryeriKod, $userId = null, $kanalId = null)
    {
        self::$sonHata = '';

        $pazaryeriKod = trim((string)$pazaryeriKod);
        if ($pazaryeriKod === '') {
            self::$sonHata = 'Pazaryeri kodu boş.';
            return null;
        }

        $anahtar = $pazaryeriKod . '|' . (int)$userId . '|' . (int)$kanalId;
        if (isset(self::$onbellek[$anahtar])) {
            return self::$onbellek[$anahtar];
        }

        $db = Database::getInstance();

        $row = $db->fetchOne("
            SELECT Pazaryerleri_id, Pazaryerleri_Ad, Pazaryerleri_SinifAdi, Pazaryerleri_Durum
            FROM dbo.tanim_Pazaryerleri
            WHERE Pazaryerleri_Kod = ?
        ", [$pazaryeriKod]);

        if (!$row) {
            self::$sonHata = 'Pazaryeri tanımlı değil: ' . $pazaryeriKod;
            return null;
        }

        $sinifAdi = trim((string)$row['Pazaryerleri_SinifAdi']);
        if ($sinifAdi === '') {
            self::$sonHata = $row['Pazaryerleri_Ad'] . ' için sınıf adı tanımlanmamış '
                           . '(tanim_Pazaryerleri.Pazaryerleri_SinifAdi).';
            return null;
        }

        // Sınıf adı veritabanından geldiği için dosya yolu doğrulanır:
        // yalnızca harf ve rakam kabul edilir, dizin dışına çıkılamaz.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $sinifAdi)) {
            self::$sonHata = 'Geçersiz sınıf adı: ' . $sinifAdi;
            return null;
        }

        // Pasiflik kontrolünden ÖNCE sınıf dosyası aranır: entegrasyonu yazılmamış
        // bir pazaryerini aktif etmek işe yaramaz, kullanıcı bunu tek seferde bilmeli.
        $sinifDosyasi = self::SINIF_DIZINI . DIRECTORY_SEPARATOR . $sinifAdi . '.php';
        if (!class_exists($sinifAdi, false) && !is_file($sinifDosyasi)) {
            self::$sonHata = $row['Pazaryerleri_Ad'] . ' entegrasyonu henüz yazılmamış '
                           . '(' . $sinifAdi . '.php bulunamadı).';
            return null;
        }

        if ((int)$row['Pazaryerleri_Durum'] !== 1) {
            self::$sonHata = $row['Pazaryerleri_Ad'] . ' pasif durumda. '
                           . 'Pazaryeri Ayarları sayfasından aktif edebilirsiniz.';
            return null;
        }

        if (!class_exists($sinifAdi, false)) {
            require_once $sinifDosyasi;

            if (!class_exists($sinifAdi, false)) {
                self::$sonHata = $sinifAdi . '.php dosyası var ancak içinde '
                               . $sinifAdi . ' sınıfı tanımlı değil.';
                return null;
            }
        }

        if (!is_subclass_of($sinifAdi, 'PazaryeriApi')) {
            self::$sonHata = $sinifAdi . ' sınıfı PazaryeriApi sınıfından türetilmemiş.';
            return null;
        }

        try {
            $api = new $sinifAdi($userId, $kanalId);
        } catch (Throwable $e) {
            self::$sonHata = $sinifAdi . ' oluşturulamadı: ' . $e->getMessage();
            error_log('PazaryeriFabrika: ' . $e->getMessage());
            return null;
        }

        if (!$api->hazirMi()) {
            // Nesne üretildi ama yapılandırma eksik; çağıran taraf getHata() ile
            // ayrıntıya ulaşabilsin diye nesne yine de döndürülür.
            self::$sonHata = $api->getHata();
        }

        return self::$onbellek[$anahtar] = $api;
    }

    /**
     * Kanal id'sinden API nesnesi üret (çok firmalı kullanım).
     * Kanalın hangi pazaryerine ait olduğu entegrasyon kodundan bulunur.
     */
    public static function olusturKanaldan($kanalId, $userId = null)
    {
        self::$sonHata = '';
        $kanalId = (int)$kanalId;

        if ($kanalId <= 0) {
            self::$sonHata = 'Kanal seçilmedi.';
            return null;
        }

        $db = Database::getInstance();

        $row = $db->fetchOne("
            SELECT p.Pazaryerleri_Kod, k.EntegrasyonKanallari_Firmalar_id, f.firma_durum
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e
                ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
            INNER JOIN dbo.tanim_Pazaryerleri p
                ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
            LEFT JOIN dbo.Firmalar f
                ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
            WHERE k.EntegrasyonKanallari_id = ?
        ", [$kanalId]);

        if (!$row) {
            self::$sonHata = 'Kanal bulunamadı veya bir pazaryerine bağlı değil (kanal ' . $kanalId . ').';
            return null;
        }

        // Pasif firmanın hesabıyla hiçbir işlem yapılmaz (cron dahil)
        if ($row['EntegrasyonKanallari_Firmalar_id'] !== null && (int)$row['firma_durum'] !== 1) {
            self::$sonHata = 'Kanalın bağlı olduğu firma pasif (kanal ' . $kanalId . ').';
            return null;
        }

        return self::olustur($row['Pazaryerleri_Kod'], $userId, $kanalId);
    }

    /** Son başarısızlığın sebebi */
    public static function sonHata()
    {
        return self::$sonHata;
    }

    /**
     * Arayüzde listelenecek pazaryerleri.
     * Sınıf dosyası var mı, kanalı yapılandırılmış mı bilgisi de döner;
     * sayfalar bu bilgiye göre sekme gösterir veya uyarı basar.
     *
     * @param bool     $yalnizAktif  yalnızca Pazaryerleri_Durum = 1 olanlar
     * @param int|null $firmaId      belirli bir firmanın kanalları
     */
    public static function pazaryerleri($yalnizAktif = true, $firmaId = null)
    {
        $db = Database::getInstance();

        $sql = "
            SELECT
                p.Pazaryerleri_id,
                p.Pazaryerleri_Kod,
                p.Pazaryerleri_Ad,
                p.Pazaryerleri_Renk,
                p.Pazaryerleri_Ikon,
                p.Pazaryerleri_Sira,
                p.Pazaryerleri_SinifAdi,
                p.Pazaryerleri_Yetenekler,
                p.Pazaryerleri_Durum,
                k.EntegrasyonKanallari_id,
                k.EntegrasyonKanallari_Ad,
                k.EntegrasyonKanallari_Firmalar_id,
                f.firma_unvan,
                f.firma_adi
            FROM dbo.tanim_Pazaryerleri p
            OUTER APPLY (
                SELECT TOP 1 k2.EntegrasyonKanallari_id, k2.EntegrasyonKanallari_Ad, k2.EntegrasyonKanallari_Firmalar_id
                FROM dbo.Entegrasyonlar e2
                INNER JOIN dbo.EntegrasyonKanallari k2
                    ON k2.EntegrasyonKanallari_Entegrasyonlar_id = e2.Entegrasyonlar_id
                   AND k2.EntegrasyonKanallari_Durum = 1
                LEFT JOIN dbo.Firmalar f2
                    ON f2.firma_id = k2.EntegrasyonKanallari_Firmalar_id
                WHERE e2.Entegrasyonlar_Kod = p.Pazaryerleri_EntegrasyonKod
                  AND e2.Entegrasyonlar_Durum = 1
                  AND (k2.EntegrasyonKanallari_Firmalar_id IS NULL OR f2.firma_durum = 1)
                  " . ($firmaId !== null ? " AND k2.EntegrasyonKanallari_Firmalar_id = " . (int)$firmaId . " " : "") . "
                ORDER BY k2.EntegrasyonKanallari_Varsayilan DESC, k2.EntegrasyonKanallari_id
            ) k
            LEFT JOIN dbo.Firmalar f ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        ";

        if ($yalnizAktif) {
            $sql .= " WHERE p.Pazaryerleri_Durum = 1 ";
        }

        $sql .= " ORDER BY p.Pazaryerleri_Sira, p.Pazaryerleri_Ad";

        $liste = $db->fetchAll($sql);

        foreach ($liste as &$p) {
            $sinif = trim((string)$p['Pazaryerleri_SinifAdi']);
            $p['sinifVar']   = $sinif !== ''
                            && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $sinif)
                            && is_file(self::SINIF_DIZINI . DIRECTORY_SEPARATOR . $sinif . '.php');
            $p['kanalVar']   = !empty($p['EntegrasyonKanallari_id']);
            $p['kullanilabilir'] = $p['sinifVar'] && $p['kanalVar'] && (int)$p['Pazaryerleri_Durum'] === 1;
            $p['yetenekler'] = json_decode($p['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: [];

            if (!$p['sinifVar']) {
                $p['durumMetni'] = 'Entegrasyon yazılmadı';
            } elseif (!$p['kanalVar']) {
                $p['durumMetni'] = 'Kimlik bilgisi girilmedi';
            } elseif ((int)$p['Pazaryerleri_Durum'] !== 1) {
                $p['durumMetni'] = 'Pasif';
            } else {
                $p['durumMetni'] = 'Hazır';
            }
        }
        unset($p);

        return $liste;
    }

    /**
     * Bir pazaryerine ait tüm kanallar (firma hesapları).
     * Çok firmalı kurulumda sayfa üstündeki kanal seçicisini besler.
     *
     * @param string|null $pazaryeriKod  belirli bir pazaryeri
     * @param int|null    $firmaId       belirli bir firmanın kanalları (arayüz filtresi)
     * @param int|null    $kisitFirmaId  PageAuth::firmaKisiti() sonucu; null → kısıt yok,
     *                                   >0 → yalnız o firma, 0 → hiç kayıt dönmez
     */
    public static function kanallar($pazaryeriKod = null, $firmaId = null, $kisitFirmaId = null)
    {
        $db = Database::getInstance();
        $params = [];

        $sql = "
            SELECT
                k.EntegrasyonKanallari_id,
                k.EntegrasyonKanallari_Ad,
                k.EntegrasyonKanallari_Varsayilan,
                k.EntegrasyonKanallari_Firmalar_id,
                f.firma_unvan,
                f.firma_adi,
                p.Pazaryerleri_id,
                p.Pazaryerleri_Kod,
                p.Pazaryerleri_Ad,
                p.Pazaryerleri_Renk,
                p.Pazaryerleri_SinifAdi,
                p.Pazaryerleri_Yetenekler,
                p.Pazaryerleri_Durum
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e
                ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
               AND e.Entegrasyonlar_Durum = 1
            INNER JOIN dbo.tanim_Pazaryerleri p
                ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
            LEFT JOIN dbo.Firmalar f
                ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
            WHERE k.EntegrasyonKanallari_Durum = 1
              AND (k.EntegrasyonKanallari_Firmalar_id IS NULL OR f.firma_durum = 1)
        ";

        if ($pazaryeriKod !== null) {
            $sql .= " AND p.Pazaryerleri_Kod = ? ";
            $params[] = $pazaryeriKod;
        }
        if ($firmaId !== null) {
            $sql .= " AND k.EntegrasyonKanallari_Firmalar_id = ? ";
            $params[] = (int)$firmaId;
        }

        // Firma Gör kısıtı: kısıtlı kullanıcı yalnız kendi firmasının kanallarını görür
        [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.EntegrasyonKanallari_Firmalar_id');
        $sql   .= $kisitSql;
        $params = array_merge($params, $kisitParams);

        $sql .= " ORDER BY p.Pazaryerleri_Sira, k.EntegrasyonKanallari_Varsayilan DESC, k.EntegrasyonKanallari_Ad";

        $liste = $db->fetchAll($sql, $params);

        // Yetenekler JSON olarak saklanır; arayüz hangi aksiyonu göstereceğine
        // bu bayraklara bakarak karar verir.
        foreach ($liste as &$k) {
            $sinif = trim((string)($k['Pazaryerleri_SinifAdi'] ?? ''));
            $k['yetenekler'] = json_decode($k['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: [];
            $k['sinifVar']   = $sinif !== ''
                            && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $sinif)
                            && is_file(self::SINIF_DIZINI . DIRECTORY_SEPARATOR . $sinif . '.php');
        }
        unset($k);

        return $liste;
    }

    /**
     * Bir pazaryerinin beklediği kimlik alanlarını döndür.
     * Sınıf dosyası yoksa boş dizi döner; arayüz o durumda mevcut
     * kayıttaki anahtarları gösterip serbest alan eklemeye izin verir.
     */
    public static function kimlikAlanlari($pazaryeriKod)
    {
        $db  = Database::getInstance();
        $row = $db->fetchOne("
            SELECT Pazaryerleri_SinifAdi FROM dbo.tanim_Pazaryerleri
            WHERE Pazaryerleri_Kod = ?
        ", [$pazaryeriKod]);

        $sinifAdi = trim((string)($row['Pazaryerleri_SinifAdi'] ?? ''));
        if ($sinifAdi === '' || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $sinifAdi)) {
            return [];
        }

        if (!class_exists($sinifAdi, false)) {
            $dosya = self::SINIF_DIZINI . DIRECTORY_SEPARATOR . $sinifAdi . '.php';
            if (!is_file($dosya)) {
                return [];
            }
            require_once $dosya;
        }

        if (!is_subclass_of($sinifAdi, 'PazaryeriApi')
            || !method_exists($sinifAdi, 'kimlikAlanlari')) {
            return [];
        }

        return (array)$sinifAdi::kimlikAlanlari();
    }

    /**
     * Pasif firmaya bağlı kanalları dışarıda bırakan SQL koşulu.
     * Pazaryeri sayfalarındaki tüm liste/sayım sorguları bunu kullanır;
     * firmasız kanal (EntegrasyonKanallari_Firmalar_id NULL) görünmeye devam eder.
     *
     * @param string $kolon Kanal kolonu, ör. 's.PazaryeriSiparisleri_EntegrasyonKanallari_id'
     *                      (sabit değer verilmeli, kullanıcı girdisi değil)
     */
    public static function aktifKanalSql($kolon)
    {
        return " $kolon IN (SELECT ak.EntegrasyonKanallari_id FROM dbo.EntegrasyonKanallari ak
                            LEFT JOIN dbo.Firmalar af ON af.firma_id = ak.EntegrasyonKanallari_Firmalar_id
                            WHERE ak.EntegrasyonKanallari_Firmalar_id IS NULL OR af.firma_durum = 1) ";
    }

    /** Kanal pasif bir firmaya bağlı değilse true (firmasız kanal da aktif sayılır) */
    public static function kanalAktifMi($kanalId)
    {
        return (bool)Database::getInstance()->fetchOne(
            "SELECT 1 AS var WHERE " . self::aktifKanalSql('?'),
            [(int)$kanalId]
        );
    }

    /**
     * Arayüzdeki firma seçicisini besler.
     *
     * @param int|null $kisitFirmaId PageAuth::firmaKisiti() sonucu; null → tüm aktif firmalar
     */
    public static function firmalar($kisitFirmaId = null)
    {
        [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'firma_id');

        return Database::getInstance()->fetchAll("
            SELECT firma_id, firma_adi, firma_unvan, firma_vergi_no
            FROM dbo.Firmalar
            WHERE firma_durum = 1 {$kisitSql}
            ORDER BY firma_adi
        ", $kisitParams);
    }

    /** Test ve cron akışlarında önbelleği temizlemek için */
    public static function onbellegiTemizle()
    {
        self::$onbellek = [];
    }
}
