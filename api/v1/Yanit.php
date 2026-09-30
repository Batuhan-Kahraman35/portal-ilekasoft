<?php
/**
 * API v1 - JSON Yanit Yardimcisi
 */

class Yanit
{
    private static int $httpKod = 200;
    private static ?int $kayitSayisi = null;
    private static ?string $hataMesaji = null;

    public static function basarili(array $veri, array $meta = []): void
    {
        self::$kayitSayisi = isset($veri[0]) ? count($veri) : null;
        self::gonder(200, array_merge(['success' => true, 'data' => $veri], $meta));
    }

    public static function tekil(array $veri): void
    {
        self::$kayitSayisi = 1;
        self::gonder(200, ['success' => true, 'data' => $veri]);
    }

    public static function hata(int $kod, string $mesaj, ?string $detay = null): void
    {
        self::$hataMesaji = $mesaj . ($detay ? ' | ' . $detay : '');
        $govde = ['success' => false, 'error' => ['code' => $kod, 'message' => $mesaj]];
        if ($detay !== null) {
            $govde['error']['detail'] = $detay;
        }
        self::gonder($kod, $govde);
    }

    private static function gonder(int $kod, array $govde): void
    {
        self::$httpKod = $kod;

        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        ApiLog::yaz(self::$httpKod, self::$kayitSayisi, self::$hataMesaji);
        exit;
    }
}
