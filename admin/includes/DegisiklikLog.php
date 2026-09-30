<?php
/**
 * Değişiklik Log Helper
 * Tüm sayfalarda INSERT/UPDATE/DELETE işlemlerinin loglanması için ortak sınıf.
 * 
 * Kullanım:
 *   require_once __DIR__ . '/../includes/DegisiklikLog.php';
 *   $log = new DegisiklikLog($db, $user['kullanici_id'], 'personel-form');
 *   $log->logInsert('kullanicilar', $newId, $newData, 'Yeni personel eklendi');
 *   $log->logUpdate('kullanicilar', $id, $eskiVeri, $yeniVeri, 'Personel güncellendi');
 *   $log->logDelete('kullanicilar', $id, $eskiVeri, 'Personel silindi');
 */

class DegisiklikLog
{
    private $db;
    private $kullaniciId;
    private $sayfa;

    /**
     * @param Database $db        Veritabanı bağlantısı
     * @param int      $kullaniciId İşlemi yapan kullanıcı ID
     * @param string   $sayfa      Sayfa adı (personel-form, sozlesme-form vb.)
     */
    public function __construct($db, int $kullaniciId, string $sayfa)
    {
        $this->db = $db;
        $this->kullaniciId = $kullaniciId;
        $this->sayfa = $sayfa;
    }

    /**
     * INSERT logu — yeni kayıtların tüm alanlarını loglar
     * 
     * @param string $tablo    Tablo adı
     * @param int    $kayitId  Eklenen kaydın ID'si
     * @param array  $yeniVeri Eklenen veri dizisi (kolon => değer)
     * @param string $aciklama Kısa açıklama
     */
    public function logInsert(string $tablo, int $kayitId, array $yeniVeri, string $aciklama = 'Yeni kayıt eklendi'): void
    {
        $degisiklikler = [];
        foreach ($yeniVeri as $alan => $deger) {
            $degisiklikler[$alan] = [
                'eski' => null,
                'yeni' => $this->formatDeger($deger)
            ];
        }

        $this->kaydet($tablo, $kayitId, 'INSERT', $degisiklikler, $aciklama);
    }

    /**
     * UPDATE logu — sadece değişen alanları loglar
     * 
     * @param string $tablo    Tablo adı
     * @param int    $kayitId  Güncellenen kaydın ID'si
     * @param array  $eskiVeri Güncelleme öncesi veri (kolon => değer)
     * @param array  $yeniVeri Güncelleme sonrası veri (kolon => değer)
     * @param string $aciklama Kısa açıklama
     * @return bool  Değişiklik var mı (false = hiçbir şey değişmemiş)
     */
    public function logUpdate(string $tablo, int $kayitId, array $eskiVeri, array $yeniVeri, string $aciklama = 'Kayıt güncellendi'): bool
    {
        $degisiklikler = [];
        foreach ($yeniVeri as $alan => $deger) {
            $eskiDeger = $eskiVeri[$alan] ?? null;
            $yeniDeger = $deger;

            // Karşılaştırma için normalize et
            if ($this->normalize($eskiDeger) !== $this->normalize($yeniDeger)) {
                $degisiklikler[$alan] = [
                    'eski' => $this->formatDeger($eskiDeger),
                    'yeni' => $this->formatDeger($yeniDeger)
                ];
            }
        }

        if (empty($degisiklikler)) {
            return false;
        }

        $this->kaydet($tablo, $kayitId, 'UPDATE', $degisiklikler, $aciklama);
        return true;
    }

    /**
     * DELETE logu — silinen kaydın tüm alanlarını loglar
     * 
     * @param string $tablo    Tablo adı
     * @param int    $kayitId  Silinen kaydın ID'si
     * @param array  $eskiVeri Silinen kayıt verisi (kolon => değer)
     * @param string $aciklama Kısa açıklama
     */
    public function logDelete(string $tablo, int $kayitId, array $eskiVeri, string $aciklama = 'Kayıt silindi'): void
    {
        $degisiklikler = [];
        foreach ($eskiVeri as $alan => $deger) {
            $degisiklikler[$alan] = [
                'eski' => $this->formatDeger($deger),
                'yeni' => null
            ];
        }

        $this->kaydet($tablo, $kayitId, 'DELETE', $degisiklikler, $aciklama);
    }

    /**
     * Tablodaki mevcut kaydı çeker (UPDATE/DELETE öncesi eski veriyi almak için)
     * 
     * @param string $tablo    Tablo adı
     * @param string $pkKolon  Primary key kolon adı
     * @param int    $kayitId  Kayıt ID'si
     * @return array|null
     */
    public function getMevcutKayit(string $tablo, string $pkKolon, int $kayitId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM [{$tablo}] WHERE [{$pkKolon}] = ?",
            [$kayitId]
        );
    }

    /**
     * Veritabanına log kaydı yazar
     */
    private function kaydet(string $tablo, int $kayitId, string $islemTipi, array $degisiklikler, string $aciklama): void
    {
        try {
            $this->db->execute("
                INSERT INTO [dbo].[Sistem_DegisiklikLog] 
                    (log_sayfa, log_tablo, log_kayit_id, log_islem_tipi, log_degisiklikler, log_aciklama, log_kullanici_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [
                $this->sayfa,
                $tablo,
                $kayitId,
                $islemTipi,
                json_encode($degisiklikler, JSON_UNESCAPED_UNICODE),
                $aciklama,
                $this->kullaniciId
            ]);
        } catch (\Exception $e) {
            error_log('DegisiklikLog hatası: ' . $e->getMessage());
        }
    }

    /**
     * Karşılaştırma için değeri normalize eder
     */
    private function normalize($deger): string
    {
        if ($deger === null || $deger === '') {
            return '';
        }
        if ($deger instanceof \DateTime) {
            return $deger->format('Y-m-d H:i:s');
        }
        return (string) $deger;
    }

    /**
     * Log için değeri formatlayıp döndürür
     */
    private function formatDeger($deger)
    {
        if ($deger === null || $deger === '') {
            return null;
        }
        if ($deger instanceof \DateTime) {
            return $deger->format('Y-m-d H:i:s');
        }
        return $deger;
    }
}
