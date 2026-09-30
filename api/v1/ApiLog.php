<?php
/**
 * API v1 - Erisim Logu
 */

class ApiLog
{
    private static float $baslangic = 0.0;
    private static ?array $istemci = null;
    private static string $endpoint = '';
    private static bool $yazildi = false;

    public static function baslat(string $endpoint): void
    {
        self::$baslangic = microtime(true);
        self::$endpoint  = $endpoint;
    }

    public static function istemciAta(array $istemci): void
    {
        self::$istemci = $istemci;
    }

    public static function yaz(int $httpKod, ?int $kayitSayisi, ?string $hataMesaji): void
    {
        if (self::$yazildi) {
            return;
        }
        self::$yazildi = true;

        $sure = self::$baslangic > 0
            ? (int) round((microtime(true) - self::$baslangic) * 1000)
            : null;

        $parametreler = $_SERVER['QUERY_STRING'] ?? '';
        if ($parametreler === '') {
            $parametreler = null;
        }

        try {
            Database::getInstance()->execute(
                "INSERT INTO api_Log
                    (apiLog_istemci_id, apiLog_endpoint, apiLog_metot, apiLog_ip,
                     apiLog_parametreler, apiLog_firma_kapsam, apiLog_banka_kapsam, apiLog_hareket_tipi,
                     apiLog_http_kod, apiLog_kayit_sayisi, apiLog_sure_ms, apiLog_hata_mesaji)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    self::$istemci['apiIstemci_id'] ?? null,
                    substr(self::$endpoint, 0, 200),
                    $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    substr(self::istemciIp(), 0, 45),
                    $parametreler !== null ? substr($parametreler, 0, 4000) : null,
                    self::$istemci['firma_kapsam'] ?? null,
                    self::$istemci['banka_kapsam'] ?? null,
                    self::$istemci['hareket_tipi'] ?? null,
                    $httpKod,
                    $kayitSayisi,
                    $sure,
                    $hataMesaji !== null ? substr($hataMesaji, 0, 1000) : null,
                ]
            );
        } catch (Throwable $e) {
            error_log('API log yazilamadi: ' . $e->getMessage());
        }
    }

    public static function istemciIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $anahtar) {
            if (!empty($_SERVER[$anahtar])) {
                $deger = explode(',', $_SERVER[$anahtar])[0];
                return trim($deger);
            }
        }
        return '';
    }
}
