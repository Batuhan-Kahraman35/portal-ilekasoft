<?php
/**
 * Banka Servis Soyut Sınıfı (Abstract Base Class)
 * 
 * Tüm banka servisleri için ortak metodlar ve özellikler
 * Loglama, DB işlemleri, hata yönetimi burada
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

require_once __DIR__ . '/BankaServiceInterface.php';
require_once __DIR__ . '/../../db.php';

abstract class BaseBankaService implements BankaServiceInterface
{
    protected \Database $db;
    protected array $apiKimlik;
    protected int $bankaId;
    protected ?int $kullaniciId;
    protected string $kaynak;
    protected string $ipAdresi;
    
    /**
     * Constructor
     * 
     * @param int $apiKimlikId banka_ApiKimlik tablosundaki ID
     * @param int|null $kullaniciId İşlemi yapan kullanıcı (cron için null)
     * @param string $kaynak 'manual', 'cron', 'api'
     */
    public function __construct(int $apiKimlikId, ?int $kullaniciId = null, string $kaynak = 'manual')
    {
        $this->db = \Database::getInstance();
        $this->kullaniciId = $kullaniciId;
        $this->kaynak = $kaynak;
        $this->ipAdresi = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        
        // API kimlik bilgilerini yükle
        $this->loadApiKimlik($apiKimlikId);
    }
    
    /**
     * API kimlik bilgilerini yükle
     */
    protected function loadApiKimlik(int $apiKimlikId): void
    {
        $this->apiKimlik = $this->db->fetchOne("
            SELECT ak.*, b.banka_adi, b.banka_kodu
            FROM banka_ApiKimlik ak
            INNER JOIN bankalar b ON ak.apiKimlik_banka_id = b.banka_id
            WHERE ak.apiKimlik_id = ? AND ak.apiKimlik_durum = 1
        ", [$apiKimlikId]);
        
        if (!$this->apiKimlik) {
            throw new \Exception("API kimlik bulunamadı veya pasif: $apiKimlikId");
        }
        
        $this->bankaId = $this->apiKimlik['apiKimlik_banka_id'];
    }
    
    /**
     * API kimlik bilgilerini döndür
     */
    public function getApiKimlik(): array
    {
        return $this->apiKimlik;
    }
    
    /**
     * Banka ID'sini döndür
     */
    public function getBankaId(): int
    {
        return $this->bankaId;
    }
    
    /**
     * API Log kaydı başlat
     * 
     * @param string $islemTipi testConnection, getHesaplar, getHareketler, senkronize
     * @param int|null $hesapId İlgili hesap ID (opsiyonel)
     * @param string|null $istekOzet İstek özeti
     * @return int Log ID
     */
    protected function logBaslat(string $islemTipi, ?int $hesapId = null, ?string $istekOzet = null): int
    {
        $this->db->execute("
            INSERT INTO banka_ApiLog (
                log_apiKimlik_id, log_bankaHesap_id, log_islem_tipi,
                log_istek_tarihi, log_kaynak, log_kullanici_id, 
                log_ip_adresi, log_istek_ozet
            ) VALUES (?, ?, ?, GETDATE(), ?, ?, ?, ?)
        ", [
            $this->apiKimlik['apiKimlik_id'],
            $hesapId,
            $islemTipi,
            $this->kaynak,
            $this->kullaniciId,
            $this->ipAdresi,
            $istekOzet
        ]);
        
        return $this->db->getLastInsertId();
    }
    
    /**
     * API Log kaydını tamamla (başarılı)
     * 
     * @param int $logId
     * @param int $kayitSayisi
     * @param string|null $yanitOzet
     * @param int|null $httpStatus
     */
    protected function logBasarili(int $logId, int $kayitSayisi = 0, ?string $yanitOzet = null, ?int $httpStatus = 200): void
    {
        $this->db->execute("
            UPDATE banka_ApiLog SET
                log_yanit_tarihi = GETDATE(),
                log_sure_ms = DATEDIFF(MILLISECOND, log_istek_tarihi, GETDATE()),
                log_basarili = 1,
                log_http_status = ?,
                log_kayit_sayisi = ?,
                log_yanit_ozet = ?
            WHERE log_id = ?
        ", [$httpStatus, $kayitSayisi, $yanitOzet ? substr($yanitOzet, 0, 4000) : null, $logId]);
    }
    
    /**
     * API Log kaydını tamamla (hatalı)
     * 
     * @param int $logId
     * @param string $hataMesaji
     * @param string|null $bankaHataKodu
     * @param int|null $httpStatus
     */
    protected function logHatali(int $logId, string $hataMesaji, ?string $bankaHataKodu = null, ?int $httpStatus = null): void
    {
        $this->db->execute("
            UPDATE banka_ApiLog SET
                log_yanit_tarihi = GETDATE(),
                log_sure_ms = DATEDIFF(MILLISECOND, log_istek_tarihi, GETDATE()),
                log_basarili = 0,
                log_http_status = ?,
                log_banka_hata_kodu = ?,
                log_hata_mesaji = ?
            WHERE log_id = ?
        ", [$httpStatus, $bankaHataKodu !== null ? substr($bankaHataKodu, 0, 20) : null, substr($hataMesaji, 0, 500), $logId]);
    }
    
    /**
     * Hesap bilgilerini güncelle veya ekle
     * 
     * @param array $hesapData API'den gelen hesap verisi (normalize edilmiş)
     * @return int Hesap ID
     */
    protected function hesapKaydetVeyaGuncelle(array $hesapData): int
    {
        // IBAN boşlukları temizle
        $iban = str_replace(' ', '', $hesapData['iban']);
        
        // IBAN ile mevcut hesabı ara (boşluksuz karşılaştırma)
        $mevcutHesap = $this->db->fetchOne("
            SELECT bankaHesap_id FROM banka_Hesap 
            WHERE REPLACE(bankaHesap_iban, ' ', '') = ?
        ", [$iban]);
        
        if ($mevcutHesap) {
            // Güncelle
            $this->db->execute("
                UPDATE banka_Hesap SET
                    bankaHesap_apiKimlik_id = ?,
                    bankaHesap_musteriNo = ?,
                    bankaHesap_ekNo = ?,
                    bankaHesap_aciklama = COALESCE(bankaHesap_aciklama, ?)
                WHERE bankaHesap_id = ?
            ", [
                $this->apiKimlik['apiKimlik_id'],
                $hesapData['musteriNo'] ?? null,
                $hesapData['ekNo'] ?? null,
                $hesapData['aciklama'] ?? null,
                $mevcutHesap['bankaHesap_id']
            ]);
            
            return $mevcutHesap['bankaHesap_id'];
        } else {
            // Yeni ekle
            $this->db->execute("
                INSERT INTO banka_Hesap (
                    bankaHesap_banka_id, bankaHesap_iban, bankaHesap_aciklama,
                    bankaHesap_durum, bankaHesap_apiKimlik_id,
                    bankaHesap_musteriNo, bankaHesap_ekNo
                ) VALUES (?, ?, ?, 1, ?, ?, ?)
            ", [
                $this->bankaId,
                $hesapData['iban'],
                $hesapData['aciklama'] ?? null,
                $this->apiKimlik['apiKimlik_id'],
                $hesapData['musteriNo'] ?? null,
                $hesapData['ekNo'] ?? null
            ]);
            return $this->db->getLastInsertId();
        }
    }
    
    /**
     * Hareket kaydı ekle (duplicate kontrolü ile)
     * 
     * @param int $hesapId banka_Hesap ID
     * @param array $hareketData Normalize edilmiş hareket verisi
     * @return bool true=eklendi, false=zaten var
     */
    protected function hareketKaydet(int $hesapId, array $hareketData): bool
    {
        // Hesabın IBAN'ını al (karşı taraf IBAN'ı farklı!)
        $hesap = $this->db->fetchOne("SELECT bankaHesap_iban FROM banka_Hesap WHERE bankaHesap_id = ?", [$hesapId]);
        
        // Duplicate kontrolü (identifier ile)
        $mevcut = $this->db->fetchOne("
            SELECT banka_HesapHareketleriID FROM banka_HesapHareketleri 
            WHERE banka_HesapHareketleriIdentifier = ?
        ", [$hareketData['identifier']]);
        
        if ($mevcut) {
            return false; // Zaten var
        }
        
        // Yeni kayıt ekle
        $this->db->execute("
            INSERT INTO banka_HesapHareketleri (
                banka_HesapId,
                banka_HesapHareketleriIBAN,
                banka_HesapHareketleriName,
                banka_HesapHareketleriDateTime,
                banka_HesapHareketleriAmount,
                banka_HesapHareketleriDescription,
                banka_HesapHareketleriBorcAlacak,
                banka_HesapHareketleriCurrencyType,
                banka_HesapHareketleriRemainingBalance,
                banka_HesapHareketleriIdentifier,
                ReceiptNo,
                VknOrTc,
                banka_HesapHareketleriCost,
                banka_HesapHareketleriAddedDateTime
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
        ", [
            $hesapId,
            $hareketData['karsiTarafIban'] ?? $hesap['bankaHesap_iban'],
            $hareketData['karsiTarafAdUnvan'] ?? null,
            $hareketData['islemTarihi'],
            $hareketData['tutar'],
            $hareketData['aciklama'] ?? null,
            $hareketData['borcAlacak'], // 'B' veya 'A'
            $hareketData['dovizKodu'] ?? 'TRY',
            $hareketData['bakiye'] ?? 0,
            $hareketData['identifier'],
            $hareketData['receiptNo'] ?? null,
            $hareketData['vknTckn'] ?? null,
            $hareketData['masraf'] ?? 0
        ]);
        
        return true;
    }
    
    /**
     * Hesabın son senkron tarihini güncelle
     * Senkronizasyon başarılı olduğunda çağrılır (0 hareket olsa bile güncellenir)
     * 
     * @param int $hesapId
     * @param int $processedCount İşlenen toplam kayıt sayısı (inserted + skipped)
     */
    protected function sonSenkronGuncelle(int $hesapId, int $processedCount = 0): void
    {
        // Not: Başarılı bir senkronizasyon 0 hareket getirebilir (örn: hafta sonu)
        // Bu yüzden processedCount kontrolü kaldırıldı, senkronizasyon başarılıysa
        // her zaman son senkron tarihi güncellenir.
        
        $this->db->execute("
            UPDATE banka_Hesap SET bankaHesap_sonSenkronTarihi = GETDATE()
            WHERE bankaHesap_id = ?
        ", [$hesapId]);
    }
    
    /**
     * Hesabın son senkron tarihini al
     * 
     * @param int $hesapId
     * @return string|null Y-m-d formatında
     */
    protected function getSonSenkronTarihi(int $hesapId): ?string
    {
        $hesap = $this->db->fetchOne("
            SELECT CONVERT(VARCHAR(10), bankaHesap_sonSenkronTarihi, 120) as tarih
            FROM banka_Hesap WHERE bankaHesap_id = ?
        ", [$hesapId]);
        
        return $hesap['tarih'] ?? null;
    }
    
    /**
     * API kimliğine bağlı tüm hesapları getir
     * 
     * @return array
     */
    protected function getApiHesaplari(): array
    {
        return $this->db->fetchAll("
            SELECT * FROM banka_Hesap 
            WHERE bankaHesap_apiKimlik_id = ? AND bankaHesap_durum = 1
        ", [$this->apiKimlik['apiKimlik_id']]);
    }
}
