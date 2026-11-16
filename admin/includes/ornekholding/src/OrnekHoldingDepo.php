<?php
/**
 * OrnekHoldingDepo — OrnekHoldingApi'nin projeye bağlandığı adaptör (ornekholding-modul)
 *
 * Her proje kanal tablosu ve log tablosu farklı olduğu için bu arayüzü uygular
 * (örnek: ornek/OrnekOrnekHoldingDepo.php) ve açılışta bir kez kaydeder:
 *
 *   OrnekHoldingApi::depoAyarla(new ProjeOrnekHoldingDepo($db));
 *
 * Ham satır: projenin kanal sorgusunun döndürdüğü satır. OrnekHoldingApi onu saklar
 * (hamKanal()) ve normalize() ile ortak biçime çevirir; çağıran kod projenin kendi
 * anahtarlarını okumaya devam edebilir.
 */
interface OrnekHoldingDepo
{
    /**
     * Aktif Örnek Holding kanalları (ham satırlar).
     * @return array[]
     */
    public function aktifKanallar(): array;

    /**
     * Tek kanal (ham satır; pasif kanal da döner). Yoksa null.
     */
    public function kanal(int $kanalId): ?array;

    /**
     * Ham satır → ortak biçim:
     *   id        int
     *   ad        string   (CHANNEL_NAME olarak da gönderilir)
     *   durum     int|null (1 aktif; null = bilinmiyor/kontrol etme)
     *   firma_id  int|null
     *   kullanici string
     *   sifre     string
     *   ayar      array    birleşik ayar: KimlikDogrulama, EFatura, EArsiv (WSDL),
     *                      VKN (yoksa firmanın vergi no'su), GbEtiket, EFaturaSeri, EArsivSeri,
     *                      NumaraYontemi, XsltAdi, XsltKlasoru, Ortam (TEST) ...
     */
    public function normalize(array $hamSatir): array;

    /**
     * SOAP çağrısı logu. $kayit anahtarları:
     *   kanal_id, islem, istek (json), cevap (kırpılmış, null olabilir), basarili (bool),
     *   hata (null olabilir), http_kod, sure_ms, kullanici_id
     * Hata fırlatmamalı (log hatası servis çağrısını bozmamalı).
     */
    public function logYaz(array $kayit): void;

    /** REQUEST_HEADER APPLICATION_NAME değeri (ör. "PortalOrnekSoft") */
    public function uygulamaAdi(): string;
}
