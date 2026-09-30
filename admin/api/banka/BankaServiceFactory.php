<?php
/**
 * Banka Servis Factory
 * 
 * Banka ID'sine göre uygun servis sınıfını döndürür
 * Yeni banka eklendiğinde sadece bu dosyaya case eklemek yeterli
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BankaServiceInterface.php';
require_once __DIR__ . '/ZiraatService.php';
require_once __DIR__ . '/AkbankService.php';
require_once __DIR__ . '/IsbankService.php';
require_once __DIR__ . '/TebService.php';
require_once __DIR__ . '/HalkbankService.php';
require_once __DIR__ . '/QnbService.php';
require_once __DIR__ . '/YapiKrediService.php';
require_once __DIR__ . '/SekerBankService.php';
require_once __DIR__ . '/AnadolubankService.php';

class BankaServiceFactory
{
    // Banka kodları (bankalar tablosundaki banka_kodu ile eşleşmeli)
    const ZIRAAT = 'ZIRAAT';
    const ZIRAAT_ALT = '0001';  // Alternatif kod
    const AKBANK = 'AKBANK';
    const AKBANK_ALT = '0046';  // Alternatif kod
    const HALKBANK = 'HALKBANK';
    const HALKBANK_ALT = '0012';
    const VAKIFBANK = 'VAKIFBANK';
    const VAKIFBANK_ALT = '0015';
    const ISBANK = 'ISBANK';
    const ISBANK_ALT = '0064';
    const YAPI_KREDI = 'YAPIKREDI';
    const YAPI_KREDI_ALT = '0067';
    const GARANTI = 'GARANTI';
    const GARANTI_ALT = '0062';
    const DENIZBANK = 'DENIZBANK';
    const DENIZBANK_ALT = '0134';
    const TEB = 'TEB';
    const TEB_ALT = '0032';
    const QNBFINANS = 'QNBFINANS';
    const QNBFINANS_ALT = '31316';
    const SEKERBANK = 'SEKERBANK';
    const SEKERBANK_ALT = '71222';
    const ANADOLUBANK = 'ANADOLUBANK';
    const ANADOLUBANK_ALT = '28934';  // bankalar tablosundaki banka_kodu
    const ANADOLUBANK_EFT = '0013';   // EFT banka kodu (alternatif)
    
    /**
     * API Kimlik ID'sine göre servis oluştur
     * 
     * @param int $apiKimlikId banka_ApiKimlik tablosundaki ID
     * @param int|null $kullaniciId İşlemi yapan kullanıcı
     * @param string $kaynak 'manual', 'cron', 'api'
     * @return BankaServiceInterface
     * @throws \Exception Desteklenmeyen banka için
     */
    public static function createByApiKimlik(
        int $apiKimlikId, 
        ?int $kullaniciId = null, 
        string $kaynak = 'manual'
    ): BankaServiceInterface 
    {
        $db = \Database::getInstance();
        
        // Banka kodunu al
        $data = $db->fetchOne("
            SELECT b.banka_kodu, b.banka_adi
            FROM banka_ApiKimlik ak
            INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
            WHERE ak.apiKimlik_id = ?
        ", [$apiKimlikId]);
        
        if (!$data) {
            throw new \Exception("API Kimlik bulunamadı: $apiKimlikId");
        }
        
        return self::createByBankaKodu($data['banka_kodu'], $apiKimlikId, $kullaniciId, $kaynak);
    }
    
    /**
     * Banka koduna göre servis oluştur
     * 
     * @param string $bankaKodu Banka kodu (ZIRAAT, AKBANK, vb.)
     * @param int $apiKimlikId
     * @param int|null $kullaniciId
     * @param string $kaynak
     * @return BankaServiceInterface
     * @throws \Exception
     */
    public static function createByBankaKodu(
        string $bankaKodu, 
        int $apiKimlikId,
        ?int $kullaniciId = null, 
        string $kaynak = 'manual'
    ): BankaServiceInterface 
    {
        switch (strtoupper($bankaKodu)) {
            case self::ZIRAAT:
            case self::ZIRAAT_ALT:
                return new ZiraatService($apiKimlikId, $kullaniciId, $kaynak);
                
            case self::AKBANK:
            case self::AKBANK_ALT:
                return new AkbankService($apiKimlikId, $kullaniciId, $kaynak);
                
            case self::HALKBANK:
            case self::HALKBANK_ALT:
                return new HalkbankService($apiKimlikId, $kullaniciId, $kaynak);
                
            case self::VAKIFBANK:
            case self::VAKIFBANK_ALT:
                throw new \Exception("Vakıfbank servisi henüz hazır değil.");
                
            case self::ISBANK:
            case self::ISBANK_ALT:
                return new IsbankService($apiKimlikId, $kullaniciId, $kaynak);
                
            case self::YAPI_KREDI:
            case self::YAPI_KREDI_ALT:
                return new YapiKrediService($apiKimlikId, $kullaniciId, $kaynak);
                
            case self::GARANTI:
            case self::GARANTI_ALT:
                throw new \Exception("Garanti servisi henüz hazır değil.");
                
            case self::DENIZBANK:
            case self::DENIZBANK_ALT:
                throw new \Exception("Denizbank servisi henüz hazır değil.");
                
            case self::TEB:
            case self::TEB_ALT:
                return new TebService($apiKimlikId, $kullaniciId, $kaynak);
                
            case self::QNBFINANS:
            case self::QNBFINANS_ALT:
                return new QnbService($apiKimlikId, $kullaniciId, $kaynak);

            case self::SEKERBANK:
            case self::SEKERBANK_ALT:
                return new SekerBankService($apiKimlikId, $kullaniciId, $kaynak);

            case self::ANADOLUBANK:
            case self::ANADOLUBANK_ALT:
            case self::ANADOLUBANK_EFT:
                return new AnadolubankService($apiKimlikId, $kullaniciId, $kaynak);

            default:
                throw new \Exception("Desteklenmeyen banka: $bankaKodu");
        }
    }
    
    /**
     * Tüm aktif API kimliklerini getir
     * 
     * @return array
     */
    public static function getAktifApiKimlikleri(): array
    {
        $db = \Database::getInstance();
        
        return $db->fetchAll("
            SELECT 
                ak.apiKimlik_id,
                ak.apiKimlik_banka_id,
                b.banka_kodu,
                b.banka_adi,
                f.firma_adi,
                (SELECT COUNT(*) FROM banka_Hesap bh WHERE bh.bankaHesap_apiKimlik_id = ak.apiKimlik_id AND bh.bankaHesap_durum = 1) as hesap_sayisi
            FROM banka_ApiKimlik ak
            INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
            LEFT JOIN Firmalar f ON ak.apiKimlik_firma_id = f.firma_id
            WHERE ak.apiKimlik_durum = 1
            ORDER BY b.banka_adi
        ");
    }
    
    /**
     * Desteklenen banka kodlarını döndür
     * 
     * @return array
     */
    public static function getDesteklenenBankalar(): array
    {
        return [
            self::ZIRAAT => ['name' => 'Ziraat Bankası', 'status' => 'active'],
            self::AKBANK => ['name' => 'Akbank', 'status' => 'planned'],
            self::HALKBANK => ['name' => 'Halkbank', 'status' => 'planned'],
            self::VAKIFBANK => ['name' => 'Vakıfbank', 'status' => 'planned'],
            self::ISBANK => ['name' => 'İş Bankası', 'status' => 'active'],
            self::YAPI_KREDI => ['name' => 'Yapı Kredi', 'status' => 'planned'],
            self::GARANTI => ['name' => 'Garanti BBVA', 'status' => 'planned'],
            self::DENIZBANK => ['name' => 'Denizbank', 'status' => 'planned'],
            self::TEB => ['name' => 'TEB', 'status' => 'active'],
            self::SEKERBANK => ['name' => 'Şekerbank', 'status' => 'active'],
            self::ANADOLUBANK => ['name' => 'Anadolubank', 'status' => 'active'],
        ];
    }
}
