<?php
/**
 * Örnek adaptör (portal.ornekfirma.com uyarlaması) — projeye kopyalanıp uyarlanır
 *
 * Kanal: Entegrasyonlar (Entegrasyonlar_Ad = 'ÖRNEK HOLDİNG', AyarJSON = endpoint'ler)
 *        + EntegrasyonKanallari (AyarJSON = VKN, seri, XsltAdi...; KullaniciAdi / Sifre kolonları)
 *        + Firmalar (VKN kanal JSON'unda yoksa firma_vergi_no)
 * Log  : EntegrasyonLoglari (Kaynak = 'OrnekHoldingApi')
 */
class OrnekOrnekHoldingDepo implements OrnekHoldingDepo
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    private function kanalSql(): string
    {
        return "
            SELECT k.*, e.Entegrasyonlar_AyarJSON, e.Entegrasyonlar_id,
                   f.firma_vergi_no AS Firma_VergiNo
            FROM Entegrasyonlar e
            INNER JOIN EntegrasyonKanallari k
                    ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
            LEFT JOIN Firmalar f ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
            WHERE e.Entegrasyonlar_Ad = 'ÖRNEK HOLDİNG'";
    }

    public function aktifKanallar(): array
    {
        return $this->db->fetchAll($this->kanalSql() . "
              AND k.EntegrasyonKanallari_Durum = 1
            ORDER BY k.EntegrasyonKanallari_id
        ") ?: [];
    }

    public function kanal(int $kanalId): ?array
    {
        $row = $this->db->fetchOne($this->kanalSql() . "
              AND e.Entegrasyonlar_Durum = 1
              AND k.EntegrasyonKanallari_id = ?
        ", [$kanalId]);
        return $row ?: null;
    }

    public function normalize(array $k): array
    {
        $ayar      = json_decode((string)($k['Entegrasyonlar_AyarJSON'] ?? '{}'), true);
        $kanalAyar = json_decode((string)($k['EntegrasyonKanallari_AyarJSON'] ?? '{}'), true);
        $birlesik  = array_merge(is_array($ayar) ? $ayar : [], is_array($kanalAyar) ? $kanalAyar : []);

        // VKN kanal JSON'unda yoksa kanalın bağlı olduğu firmanın vergi numarası
        if (trim((string)($birlesik['VKN'] ?? '')) === '') {
            $birlesik['VKN'] = preg_replace('/\D/', '', (string)($k['Firma_VergiNo'] ?? ''));
        }

        $firma = $k['EntegrasyonKanallari_Firmalar_id'] ?? null;
        return [
            'id'        => (int)($k['EntegrasyonKanallari_id'] ?? 0),
            'ad'        => (string)($k['EntegrasyonKanallari_Ad'] ?? ''),
            'durum'     => isset($k['EntegrasyonKanallari_Durum']) ? (int)$k['EntegrasyonKanallari_Durum'] : null,
            'firma_id'  => $firma !== null ? (int)$firma : null,
            'kullanici' => (string)($k['EntegrasyonKanallari_KullaniciAdi'] ?? ''),
            'sifre'     => (string)($k['EntegrasyonKanallari_Sifre'] ?? ''),
            'ayar'      => $birlesik,
        ];
    }

    public function logYaz(array $l): void
    {
        try {
            $this->db->insert('EntegrasyonLoglari', [
                'EntegrasyonLoglari_EntegrasyonKanallari_id' => (int)$l['kanal_id'],
                'EntegrasyonLoglari_IslemTipi'               => $l['islem'],
                'EntegrasyonLoglari_Istek'                   => $l['istek'],
                'EntegrasyonLoglari_Cevap'                   => $l['cevap'],
                'EntegrasyonLoglari_BasariliMi'              => !empty($l['basarili']) ? 1 : 0,
                'EntegrasyonLoglari_HataMesaji'              => $l['hata'],
                'EntegrasyonLoglari_OlusturanKullanici'      => (int)($l['kullanici_id'] ?? 1),
                'EntegrasyonLoglari_Kaynak'                  => 'OrnekHoldingApi',
                'EntegrasyonLoglari_Durum'                   => 1,
            ]);
        } catch (Throwable $e) {
            error_log('PortalOrnekHoldingDepo log hatası: ' . $e->getMessage());
        }
    }

    public function uygulamaAdi(): string
    {
        return 'PortalOrnekSoft';
    }
}
