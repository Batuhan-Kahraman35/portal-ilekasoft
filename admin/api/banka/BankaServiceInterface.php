<?php
/**
 * Banka API Servis Interface
 * 
 * Tüm banka servisleri bu interface'i implement etmeli
 * Her banka için ayrı adapter class oluşturulur
 * 
 * @author ÖRNEK Soft
 * @version 1.0
 */

namespace App\Services\Banka;

interface BankaServiceInterface
{
    /**
     * API bağlantı testi
     * 
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public function testConnection(): array;
    
    /**
     * Tanımlı hesap listesini API'den çek
     * 
     * @return array ['success' => bool, 'message' => string, 'data' => array, 'count' => int]
     */
    public function getHesaplar(): array;
    
    /**
     * Belirli bir hesabın hareketlerini çek
     * 
     * @param int $hesapId banka_Hesap tablosundaki ID
     * @param string $baslangicTarih Y-m-d formatında
     * @param string $bitisTarih Y-m-d formatında
     * @return array ['success' => bool, 'message' => string, 'data' => array, 'count' => int]
     */
    public function getHareketler(int $hesapId, string $baslangicTarih, string $bitisTarih): array;
    
    /**
     * Hesabı senkronize et (son senkrondan bugüne kadar)
     * 
     * @param int $hesapId banka_Hesap tablosundaki ID
     * @return array ['success' => bool, 'message' => string, 'inserted' => int, 'skipped' => int]
     */
    public function senkronize(int $hesapId): array;
    
    /**
     * Tüm hesapları senkronize et
     * 
     * @return array ['success' => bool, 'message' => string, 'results' => array]
     */
    public function senkronizeTumu(): array;
    
    /**
     * API kimlik bilgilerini döndür
     * 
     * @return array
     */
    public function getApiKimlik(): array;
    
    /**
     * Banka ID'sini döndür
     * 
     * @return int
     */
    public function getBankaId(): int;
}
