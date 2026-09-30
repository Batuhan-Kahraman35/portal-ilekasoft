<?php
/**
 * API v1 - Bearer Token Dogrulama ve Yetki Kapsami
 */

class Kimlik
{
    /**
     * Istegi dogrular; basarisizsa yanit dondurup sonlandirir.
     */
    public static function dogrula(): array
    {
        $token = self::tokenOku();
        if ($token === null) {
            Yanit::hata(401, 'Yetkilendirme basligi bulunamadi',
                'Authorization: Bearer <token> basligi gonderilmelidir.');
        }

        $db = Database::getInstance();

        $istemci = $db->fetchOne(
            "SELECT
                i.apiIstemci_id,
                i.apiIstemci_ad,
                i.apiIstemci_ip_whitelist,
                i.apiIstemci_rate_limit,
                i.apiIstemci_dekont_erisim,
                i.apiIstemci_bitis_tarihi,
                kf.apiKapsam_kod AS firma_kapsam,
                kb.apiKapsam_kod AS banka_kapsam,
                ht.apiHareketTipi_kod AS hareket_tipi,
                ht.apiHareketTipi_borc_alacak AS hareket_tipi_deger
             FROM api_Istemciler i
             INNER JOIN tanim_api_kapsam kf ON kf.apiKapsam_id = i.apiIstemci_firma_kapsam_id
             INNER JOIN tanim_api_kapsam kb ON kb.apiKapsam_id = i.apiIstemci_banka_kapsam_id
             INNER JOIN tanim_api_hareket_tipi ht ON ht.apiHareketTipi_id = i.apiIstemci_hareket_tipi_id
             WHERE i.apiIstemci_token_hash = HASHBYTES('SHA2_256', ?)
               AND i.Durum = 1",
            [$token]
        );

        if (!$istemci) {
            Yanit::hata(401, 'Gecersiz token');
        }

        ApiLog::istemciAta($istemci);

        if ($istemci['apiIstemci_bitis_tarihi'] instanceof DateTimeInterface) {
            $bitis = $istemci['apiIstemci_bitis_tarihi'];
            if ($bitis < new DateTime('today')) {
                Yanit::hata(403, 'Token suresi dolmus',
                    'Bitis tarihi: ' . $bitis->format('Y-m-d'));
            }
        }

        self::ipKontrol($istemci);
        self::rateLimitKontrol($istemci);

        $db->execute(
            "UPDATE api_Istemciler SET apiIstemci_son_erisim = GETDATE() WHERE apiIstemci_id = ?",
            [$istemci['apiIstemci_id']]
        );

        return $istemci;
    }

    private static function tokenOku(): ?string
    {
        $baslik = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($baslik === '' && function_exists('apache_request_headers')) {
            $basliklar = array_change_key_case(apache_request_headers(), CASE_LOWER);
            $baslik = $basliklar['authorization'] ?? '';
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($baslik), $eslesme)) {
            return $eslesme[1];
        }

        // Bazi IIS/FastCGI yapilandirmalarinda Authorization basligi PHP'ye ulasmaz;
        // bu durumda X-Api-Key basligi kullanilabilir.
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim((string) $_SERVER['HTTP_X_API_KEY']);
        }

        return null;
    }

    private static function ipKontrol(array $istemci): void
    {
        $liste = trim((string) ($istemci['apiIstemci_ip_whitelist'] ?? ''));
        if ($liste === '') {
            return;
        }

        $ip = ApiLog::istemciIp();
        $izinli = array_filter(array_map('trim', explode(',', $liste)));

        if (!in_array($ip, $izinli, true)) {
            Yanit::hata(403, 'IP adresi yetkili degil', 'Istek IP: ' . $ip);
        }
    }

    private static function rateLimitKontrol(array $istemci): void
    {
        $limit = (int) $istemci['apiIstemci_rate_limit'];
        if ($limit <= 0) {
            return;
        }

        $sonuc = Database::getInstance()->fetchOne(
            "SELECT COUNT(*) AS sayi
             FROM api_Log
             WHERE apiLog_istemci_id = ?
               AND OlusturmaTarihi >= DATEADD(HOUR, -1, GETDATE())",
            [$istemci['apiIstemci_id']]
        );

        $kullanilan = (int) ($sonuc['sayi'] ?? 0);

        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . max(0, $limit - $kullanilan));

        if ($kullanilan >= $limit) {
            Yanit::hata(429, 'Istek limiti asildi',
                'Saatlik limit: ' . $limit . '. Bir sonraki saatte tekrar deneyin.');
        }
    }

    /**
     * Yetki kapsamina gore JOIN ve parametre uretir.
     * TUMU kapsaminda JOIN hic eklenmez.
     */
    public static function kapsamJoin(array $istemci, string $hesapTakma = 'bh'): array
    {
        $sql    = '';
        $params = [];

        if ($istemci['firma_kapsam'] === 'SECILI') {
            $sql .= " INNER JOIN api_IstemciFirmalari af
                              ON af.apiIstemciFirma_firma_id = {$hesapTakma}.bankaHesap_firma_id
                             AND af.apiIstemciFirma_istemci_id = ?
                             AND af.Durum = 1";
            $params[] = $istemci['apiIstemci_id'];
        }

        if ($istemci['banka_kapsam'] === 'SECILI') {
            $sql .= " INNER JOIN api_IstemciBankalari ab
                              ON ab.apiIstemciBanka_banka_id = {$hesapTakma}.bankaHesap_banka_id
                             AND ab.apiIstemciBanka_istemci_id = ?
                             AND ab.Durum = 1";
            $params[] = $istemci['apiIstemci_id'];
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Hareket tipi kisitini uretir.
     * Kod eslemesi tanim_api_hareket_tipi tablosundan gelir; TUMU'da kisit yoktur.
     */
    public static function hareketTipiKosulu(array $istemci, string $hareketTakma = 'h'): array
    {
        $deger = $istemci['hareket_tipi_deger'] ?? null;

        if ($deger === null || trim((string) $deger) === '') {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql'    => " AND {$hareketTakma}.banka_HesapHareketleriBorcAlacak = ?",
            'params' => [trim((string) $deger)],
        ];
    }
}
