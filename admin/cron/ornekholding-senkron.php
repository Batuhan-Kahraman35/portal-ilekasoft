<?php
/**
 * Örnek Holding Fatura Senkronizasyonu — Portal Örnek Soft
 *
 * Tüm aktif ÖRNEK HOLDİNG kanallarından e-Fatura (gelen/giden) ve e-Arşiv (giden)
 * faturalarını çekip Faturalar / FaturaKalemleri tablolarına yazar.
 *
 * İki aşamalı çalışır:
 *   1. Liste  : HEADER_ONLY=Y ile başlık bilgileri → Faturalar upsert (ETTN + kanal anahtarlı)
 *   2. Detay  : yalnız kalemi olmayan faturalar için UBL XML → FaturaKalemleri + tutar kırılımı
 *
 * Servis istek başına en fazla 100 belge döndürdüğü için tarih aralığı
 * dilimlere bölünür; bir dilim tavana dayanırsa otomatik ikiye bölünür.
 *
 * Kullanım:
 *   php admin/cron/ornekholding-senkron.php [--gun=30] [--kanal=1] [--dilim=5]
 *                                        [--tur=EFATURA|EARSIV] [--yon=GELEN|GIDEN]
 *                                        [--detay-limit=300] [--detay=0]
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/OrnekHoldingApi.php';

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Europe/Istanbul');
}
date_default_timezone_set(APP_TIMEZONE);

class OrnekHoldingSenkron
{
    const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const NS_INV = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    /** Servis istek başına en fazla bu kadar belge döndürür (§9.6) */
    const SERVIS_TAVANI = 100;

    private $db;
    private int $gun;
    private int $dilimGun;
    private ?int $filtreKanal;
    private ?string $filtreTur;
    private ?string $filtreYon;
    private int $detayLimit;
    private bool $detayAcik;
    private int $detayKalan;
    private string $logDosya;
    /** Web'den çağrıldığında ekrana yazılmaz, satırlar dizide toplanır */
    private bool $sessiz;
    private array $logSatirlari = [];
    private array $ozet = ['yeni' => 0, 'guncellenen' => 0];

    public function __construct(array $p = [])
    {
        $this->db          = Database::getInstance();
        $this->gun         = (int)($p['gun'] ?? 30);
        $this->dilimGun    = max(1, (int)($p['dilim'] ?? 5));
        $this->filtreKanal = isset($p['kanal']) ? (int)$p['kanal'] : null;
        $this->filtreTur   = $p['tur'] ?? null;
        $this->filtreYon   = $p['yon'] ?? null;
        $this->detayLimit  = (int)($p['detay-limit'] ?? 300);
        $this->detayAcik   = ($p['detay'] ?? '1') !== '0';
        $this->detayKalan  = $this->detayLimit;
        $this->sessiz      = !empty($p['sessiz']);

        $logDizin = __DIR__ . '/logs';
        if (!is_dir($logDizin)) @mkdir($logDizin, 0755, true);
        $this->logDosya = $logDizin . '/ornekholding-senkron-' . date('Y-m') . '.log';
    }

    public function calistir(): void
    {
        $this->log('=== Örnek Holding Senkronizasyonu Başladı ===');
        $this->log("Tarih aralığı: son {$this->gun} gün · dilim: {$this->dilimGun} gün");

        $kanallar = OrnekHoldingApi::aktifKanallar($this->db);
        if (!$kanallar) {
            $this->log('Aktif ÖRNEK HOLDİNG kanalı bulunamadı.', 'WARNING');
            return;
        }

        $genelYeni = 0;
        $genelGuncel = 0;

        foreach ($kanallar as $kanal) {
            $kanalId = (int)$kanal['EntegrasyonKanallari_id'];
            if ($this->filtreKanal !== null && $kanalId !== $this->filtreKanal) {
                continue;
            }

            $api = new OrnekHoldingApi($kanal);
            $this->log("--- Kanal #{$kanalId} · {$api->getKanalAdi()} ---");

            if (!$api->hazirMi()) {
                $this->log($api->getHata(), 'ERROR');
                continue;
            }

            $ozet = $this->kanalSenkronize($api);
            $genelYeni   += $ozet['yeni'];
            $genelGuncel += $ozet['guncellenen'];
        }

        $this->ozet = ['yeni' => $genelYeni, 'guncellenen' => $genelGuncel];
        $this->log("Özet: $genelYeni yeni fatura, $genelGuncel güncellenen");
        $this->log('=== Senkronizasyon Tamamlandı ===');
    }

    private function kanalSenkronize(OrnekHoldingApi $api): array
    {
        $yeni = 0;
        $guncellenen = 0;

        // e-Fatura: gelen / giden
        if ($this->filtreTur === null || $this->filtreTur === 'EFATURA') {
            foreach (['GELEN' => 'IN', 'GIDEN' => 'OUT'] as $yon => $direction) {
                if ($this->filtreYon !== null && $this->filtreYon !== $yon) continue;

                $sonuc = $this->efaturaSenkron($api, $yon, $direction);
                $yeni += $sonuc['yeni'];
                $guncellenen += $sonuc['guncellenen'];
            }
        }

        // e-Arşiv: daima giden yönlü
        if (($this->filtreTur === null || $this->filtreTur === 'EARSIV')
            && ($this->filtreYon === null || $this->filtreYon === 'GIDEN')) {

            if ($api->earsivVarMi()) {
                $sonuc = $this->earsivSenkron($api);
                $yeni += $sonuc['yeni'];
                $guncellenen += $sonuc['guncellenen'];
            } else {
                $this->log('  EARSIV endpoint tanımlı değil, atlandı.', 'WARNING');
            }
        }

        return ['yeni' => $yeni, 'guncellenen' => $guncellenen];
    }

    // ================================================================
    // e-Fatura
    // ================================================================

    private function efaturaSenkron(OrnekHoldingApi $api, string $yon, string $direction): array
    {
        $yeni = 0; $guncellenen = 0; $hatali = 0; $toplam = 0;

        foreach ($this->dilimler() as [$bas, $bit]) {
            $faturalar = $this->dilimCek(
                fn($b, $s) => $api->faturaListesi($direction, ['start_date' => $b, 'end_date' => $s, 'limit' => 1000]),
                $bas, $bit, "EFATURA/$yon", 0,
                // Tek gün 100 tavanına dayanırsa karşı taraf VKN'siyle bölünür:
                // gelen faturada gönderen (SENDER), gidende alıcı (RECEIVER).
                fn($gun, $vkn) => $api->faturaListesi($direction, [
                    'start_date' => $gun,
                    'end_date'   => $gun,
                    'limit'      => 1000,
                    $direction === 'IN' ? 'sender' : 'receiver' => $vkn,
                ])
            );

            foreach ($faturalar as $f) {
                if (empty($f['ettn'])) continue;
                $toplam++;

                $karsiVkn   = $direction === 'IN' ? $f['gonderen_vkn']   : $f['alici_vkn'];
                $karsiUnvan = $direction === 'IN' ? $f['gonderen_unvan'] : $f['alici_unvan'];

                $sonuc = $this->faturaUpsert($api->getKanalId(), 'EFATURA', $yon, [
                    'ettn'        => $f['ettn'],
                    'fatura_no'   => $f['fatura_no'],
                    'tarih'       => self::tarihNormalize($f['fatura_tarihi']),
                    'cari_vkn'    => $karsiVkn,
                    'cari_unvan'  => $karsiUnvan,
                    'tutar'       => $f['tutar'],
                    'para_birimi' => $f['para_birimi'] ?: 'TRY',
                    'senaryo'     => $f['senaryo'],
                    'fatura_tipi' => $f['fatura_tipi'],
                    'gib_durum'   => $f['gib_durum'] ?: $f['durum'],
                    'gib_durum_kod'   => $f['gib_durum_kod']  ?? null,
                    'durum_aciklama'  => $f['durum']          ?? null,
                    'yanit_aciklama'  => $f['yanit_aciklama'] ?? null,
                    'iptal'           => !empty($f['iptal']),
                    'zarf_id'         => $f['zarf_id']        ?? null,
                    'servis_tarihi'   => $f['servis_tarihi']  ?? null,
                ]);

                if ($sonuc === 'yeni') $yeni++;
                elseif ($sonuc === 'guncellendi') $guncellenen++;
                else $hatali++;
            }
        }

        $this->log("  EFATURA/$yon : $toplam kayıt | $yeni yeni | $guncellenen güncellendi"
                 . ($hatali ? " | $hatali hatalı" : ''));

        if ($this->detayAcik) {
            $this->detaylariTamamla($api, 'EFATURA', $yon, $direction);
        }

        return ['yeni' => $yeni, 'guncellenen' => $guncellenen];
    }

    // ================================================================
    // e-Arşiv
    // ================================================================

    private function earsivSenkron(OrnekHoldingApi $api): array
    {
        $yeni = 0; $guncellenen = 0; $hatali = 0; $toplam = 0;

        foreach ($this->dilimler() as [$bas, $bit]) {
            $faturalar = $this->dilimCek(
                fn($b, $s) => $api->earsivListesi(['start_date' => $b, 'end_date' => $s, 'limit' => 1000]),
                $bas, $bit, 'EARSIV/GIDEN'
            );

            foreach ($faturalar as $f) {
                if (empty($f['ettn'])) continue;
                $toplam++;

                $sonuc = $this->faturaUpsert($api->getKanalId(), 'EARSIV', 'GIDEN', [
                    'ettn'        => $f['ettn'],
                    'fatura_no'   => $f['fatura_no'],
                    'tarih'       => $f['fatura_tarihi'],
                    'cari_vkn'    => $f['alici_vkn'],
                    'cari_unvan'  => $f['alici_unvan'],
                    'tutar'       => $f['tutar'],
                    'para_birimi' => $f['para_birimi'] ?: 'TRY',
                    'senaryo'     => $f['senaryo'],
                    'fatura_tipi' => $f['fatura_tipi'],
                    'gib_durum'   => $f['durum'] ?: $f['gib_durum'],
                    'gib_durum_kod'   => $f['gib_durum_kod']  ?? null,
                    'durum_aciklama'  => $f['durum']          ?? null,
                    'yanit_aciklama'  => $f['yanit_aciklama'] ?? null,
                    'iptal'           => !empty($f['iptal']),
                    'zarf_id'         => $f['zarf_id']        ?? null,
                    'servis_tarihi'   => $f['servis_tarihi']  ?? null,
                ]);

                if ($sonuc === 'yeni') $yeni++;
                elseif ($sonuc === 'guncellendi') $guncellenen++;
                else $hatali++;
            }
        }

        $this->log("  EARSIV/GIDEN : $toplam kayıt | $yeni yeni | $guncellenen güncellendi"
                 . ($hatali ? " | $hatali hatalı" : ''));

        if ($this->detayAcik) {
            $this->detaylariTamamla($api, 'EARSIV', 'GIDEN', 'OUT');
        }

        return ['yeni' => $yeni, 'guncellenen' => $guncellenen];
    }

    // ================================================================
    // Liste çekme — tarih dilimleme
    // ================================================================

    /**
     * Tarih aralığını [bas, bit] dilimlerine böler (eskiden yeniye).
     * Son dilim bugünde biter; aksi halde gün içinde kesilen faturalar
     * ertesi güne kadar hiç çekilmez.
     */
    private function dilimler(): array
    {
        $dilimler = [];
        for ($i = $this->gun; $i > 0; $i -= $this->dilimGun) {
            $bas = date('Y-m-d', strtotime("-{$i} days"));
            $bit = date('Y-m-d', strtotime('-' . max(0, $i - $this->dilimGun) . ' days'));
            $dilimler[] = [$bas, $bit];
        }
        return $dilimler;
    }

    /**
     * Bir dilimi çeker; servis tavanına dayanılırsa dilimi ikiye bölüp yeniden dener.
     * Tek güne inilmişse tavan kabul edilir (o gün gerçekten 100+ fatura var).
     */
    private function dilimCek(callable $cagri, string $bas, string $bit, string $etiket,
                              int $derinlik = 0, ?callable $vknCagri = null): array
    {
        $r = $cagri($bas, $bit);

        if (!$r['success']) {
            $this->log("  [$etiket] $bas → $bit : {$r['message']}", 'ERROR');
            return [];
        }

        $adet = count($r['data']);

        if ($adet >= self::SERVIS_TAVANI && $bas !== $bit && $derinlik < 5) {
            $orta = date('Y-m-d', (int)((strtotime($bas) + strtotime($bit)) / 2));
            $this->log("  [$etiket] $bas → $bit tavana dayandı ($adet), dilim bölünüyor.", 'WARNING');

            $sol  = $this->dilimCek($cagri, $bas, $orta, $etiket, $derinlik + 1, $vknCagri);
            $sag  = $this->dilimCek($cagri, date('Y-m-d', strtotime($orta . ' +1 day')), $bit,
                                    $etiket, $derinlik + 1, $vknCagri);

            // ETTN bazında tekilleştir
            $birlesik = [];
            foreach (array_merge($sol, $sag) as $f) {
                $birlesik[$f['ettn']] = $f;
            }
            return array_values($birlesik);
        }

        // Tek güne inildi ve hâlâ tavandaysa tarihle bölünemez (START_DATE tipi
        // date'tir, saat kabul etmez). Bu durumda karşı taraf VKN'siyle bölünür.
        if ($adet >= self::SERVIS_TAVANI && $bas === $bit && $vknCagri !== null) {
            return $this->vknIleBol($vknCagri, $r['data'], $bas, $etiket);
        }

        if ($adet >= self::SERVIS_TAVANI) {
            $this->log("  [$etiket] $bas → $bit : $adet kayıt — tavan aşılmış olabilir.", 'WARNING');
        }

        return $r['data'];
    }

    /**
     * Tek günde 100 tavanına dayanan listeyi karşı taraf VKN'siyle böler.
     * İlk yanıtta görünen her VKN için ayrı istek atılır; tavana dayanan
     * ilk 100 kayıtta hiç görünmeyen bir gönderen varsa yakalanamaz — bu
     * durum uyarı olarak loglanır.
     */
    private function vknIleBol(callable $vknCagri, array $ilkYanit, string $gun, string $etiket): array
    {
        $birlesik = [];
        foreach ($ilkYanit as $f) {
            $birlesik[$f['ettn']] = $f;
        }

        $vknler = [];
        foreach ($ilkYanit as $f) {
            $vkn = $f['gonderen_vkn'] ?? $f['alici_vkn'] ?? '';
            if ($vkn !== '') $vknler[$vkn] = true;
        }

        $this->log("  [$etiket] $gun tavana dayandı (" . count($ilkYanit) . ") ve gün bölünemiyor; "
                 . count($vknler) . " VKN ile bölünüyor.", 'WARNING');

        $doymus = [];
        foreach (array_keys($vknler) as $vkn) {
            $alt = $vknCagri($gun, $vkn);
            if (!$alt['success']) {
                $this->log("  [$etiket] $gun · VKN $vkn : {$alt['message']}", 'ERROR');
                continue;
            }
            foreach ($alt['data'] as $f) {
                $birlesik[$f['ettn']] = $f;
            }
            if (count($alt['data']) >= self::SERVIS_TAVANI) $doymus[] = $vkn;
        }

        $kazanc = count($birlesik) - count($ilkYanit);
        $this->log("  [$etiket] $gun · VKN bölmesi sonrası " . count($birlesik)
                 . " kayıt (+$kazanc)" . ($doymus ? ' — hâlâ tavanda: ' . implode(', ', $doymus) : ''),
                 $doymus ? 'WARNING' : 'INFO');

        return array_values($birlesik);
    }

    // ================================================================
    // Kayıt
    // ================================================================

    /**
     * VKN/TCKN'si birebir uyan cari kartın id'sini döner.
     * Karşılaştırmada rakam dışı karakterler dikkate alınmaz.
     */
    private function cariBul(?string $vkn): ?int
    {
        $vkn = preg_replace('/[^0-9]/', '', (string)$vkn);
        if ($vkn === '') {
            return null;
        }

        if (isset($this->cariOnbellek[$vkn])) {
            return $this->cariOnbellek[$vkn];
        }

        $satir = $this->db->fetchOne("
            SELECT TOP 1 cari_id FROM Cari
            WHERE REPLACE(REPLACE(LTRIM(RTRIM(cari_vergi_no)),' ',''),'-','') = ?
              AND cari_vergi_no IS NOT NULL AND LTRIM(RTRIM(cari_vergi_no)) <> ''
            ORDER BY cari_aktif DESC, cari_id
        ", [$vkn]);

        $this->cariOnbellek[$vkn] = $satir ? (int)$satir['cari_id'] : null;
        return $this->cariOnbellek[$vkn];
    }

    /** @var array<string,int|null> VKN -> cari_id önbelleği */
    private $cariOnbellek = [];

    /** @return string 'yeni' | 'guncellendi' | 'hata' */
    private function faturaUpsert(int $kanalId, string $tur, string $yon, array $f): string
    {
        try {
            $data = [
                'Faturalar_EntegrasyonKanallari_id' => $kanalId,
                'Faturalar_Yon'                     => $yon,
                'Faturalar_Tur'                     => $tur,
                'Faturalar_FaturaNo'                => $f['fatura_no'] ?: null,
                'Faturalar_Senaryo'                 => $f['senaryo'] ?: null,
                'Faturalar_FaturaTipi'              => $f['fatura_tipi'] ?: null,
                'Faturalar_Tarih'                   => $f['tarih'] ?: null,
                'Faturalar_CariUnvan'               => $f['cari_unvan'] ?: null,
                'Faturalar_CariVKN'                 => $f['cari_vkn'] ?: null,
                'Faturalar_OdenecekTutar'           => is_numeric($f['tutar']) ? (float)$f['tutar'] : null,
                'Faturalar_ParaBirimi'              => $f['para_birimi'] ?: 'TRY',
                'Faturalar_GibDurum'                => $f['gib_durum'] ?: null,
                'Faturalar_GibDurumKodu'            => ($f['gib_durum_kod']  ?? '') ?: null,
                'Faturalar_DurumAciklama'           => ($f['durum_aciklama'] ?? '') ?: null,
                'Faturalar_YanitAciklama'           => ($f['yanit_aciklama'] ?? '') ?: null,
                'Faturalar_IptalDurumu'             => !empty($f['iptal']) ? 1 : 0,
                'Faturalar_ZarfId'                  => ($f['zarf_id']       ?? '') ?: null,
                'Faturalar_ServisTarihi'            => ($f['servis_tarihi'] ?? '') ?: null,
                'Faturalar_Durum'                   => 1,
            ];

            // Cari kart eşleştirmesi: VKN birebir uyuyorsa fatura otomatik bağlanır.
            // Elle atanmış bağ korunur (güncellemede Cari_id yalnız boşsa yazılır).
            $cariId = $this->cariBul($f['cari_vkn'] ?? null);

            // Eşleştirme anahtarı ETTN + belge türüdür, kanal DEĞİLDİR.
            // Aynı mükellefe bağlı iki kanal (ör. ÖRNEK ve PROFİLO) aynı faturayı
            // görebiliyor; kanal anahtara girerse aynı fatura iki satıra bölünür.
            // Aktif kayıt her zaman önceliklidir: mükerrer olduğu için pasiflenmiş
            // bir satır bulunursa onu diriltmek, aktif eşiyle çakışmaya yol açar.
            $mevcut = $this->db->fetchOne(
                "SELECT TOP 1 Faturalar_id, Faturalar_Cari_id, Faturalar_Durum
                   FROM Faturalar
                  WHERE Faturalar_ETTN = ? AND Faturalar_Tur = ?
                  ORDER BY Faturalar_Durum DESC, Faturalar_id ASC",
                [$f['ettn'], $tur]
            );

            if ($mevcut) {
                $data['Faturalar_GuncelleyenKullanici'] = 1;
                $data['Faturalar_GuncellemeTarihi']     = date('Y-m-d H:i:s');
                if ($cariId && empty($mevcut['Faturalar_Cari_id'])) {
                    $data['Faturalar_Cari_id'] = $cariId;
                }
                // Pasif kayıt (mükerrer veya elle kapatılmış) pasif kalır.
                if ((int)$mevcut['Faturalar_Durum'] === 0) {
                    unset($data['Faturalar_Durum']);
                }
                // Fatura ilk hangi kanaldan kaydedildiyse orada kalır; PDF çağrısı
                // o kanalın kimliğiyle yapılır ve aynı mükellef olduğu için çalışır.
                unset($data['Faturalar_EntegrasyonKanallari_id']);
                $this->db->update('Faturalar', $data, ['Faturalar_id' => $mevcut['Faturalar_id']]);
                return 'guncellendi';
            }

            $data['Faturalar_ETTN']                 = $f['ettn'];
            $data['Faturalar_Cari_id']              = $cariId;
            $data['Faturalar_OlusturanKullanici']   = 1;
            $data['Faturalar_OlusturmaTarihi']      = date('Y-m-d H:i:s');
            $this->db->insert('Faturalar', $data);
            return 'yeni';

        } catch (Exception $e) {
            $this->log('  Fatura kaydedilemedi (' . $f['ettn'] . '): ' . $e->getMessage(), 'ERROR');
            return 'hata';
        }
    }

    // ================================================================
    // Detay (UBL XML → kalemler + tutar kırılımı)
    // ================================================================

    /**
     * Kalemi olmayan faturaların UBL XML'ini indirip kalemleri ve tutar
     * kırılımını doldurur. Her çalıştırmada --detay-limit kadar işlenir.
     */
    private function detaylariTamamla(OrnekHoldingApi $api, string $tur, string $yon, string $direction): void
    {
        if ($this->detayKalan <= 0) {
            return;
        }

        $eksikler = $this->db->fetchAll("
            SELECT TOP {$this->detayKalan} f.Faturalar_id, f.Faturalar_ETTN
            FROM Faturalar f
            WHERE f.Faturalar_EntegrasyonKanallari_id = ?
              AND f.Faturalar_Tur = ?
              AND f.Faturalar_Yon = ?
              AND f.Faturalar_ETTN IS NOT NULL
              AND f.Faturalar_Tarih >= ?
              -- İptal edilmiş belgenin içeriğini servis döndürmez; her çalıştırmada
              -- boşuna denenip kotayı tüketmemesi için kapsam dışı bırakılır.
              AND f.Faturalar_IptalDurumu = 0
              AND f.Faturalar_Durum = 1
              AND NOT EXISTS (
                    SELECT 1 FROM FaturaKalemleri k
                     WHERE k.FaturaKalemleri_Faturalar_id = f.Faturalar_id
              )
            ORDER BY f.Faturalar_Tarih DESC
        ", [
            $api->getKanalId(), $tur, $yon,
            date('Y-m-d', strtotime("-{$this->gun} days")),
        ]) ?: [];

        if (!$eksikler) {
            return;
        }

        $islenen = 0;
        $hatali  = 0;

        foreach ($eksikler as $satir) {
            if ($this->detayKalan <= 0) break;
            $this->detayKalan--;

            $ettn = $satir['Faturalar_ETTN'];
            $r = $tur === 'EARSIV'
                ? $api->earsivXml($ettn)
                : $api->faturaXml($ettn, $direction);

            if (!$r['success'] || empty($r['xml'])) {
                $hatali++;
                continue;
            }

            if ($this->detayIsle((int)$satir['Faturalar_id'], $r['xml'])) {
                $islenen++;
            } else {
                $hatali++;
            }
        }

        $this->log("  $tur/$yon detay: $islenen fatura işlendi"
                 . ($hatali ? ", $hatali başarısız" : '')
                 . ' (kalan kota: ' . $this->detayKalan . ')');
    }

    /** UBL XML'i parse edip fatura kırılımını ve kalemleri yazar */
    private function detayIsle(int $faturaId, string $xmlStr): bool
    {
        $xpath = self::ublXpath($xmlStr);
        if (!$xpath) {
            return false;
        }

        $xval   = fn($q) => $xpath->evaluate('string(/inv:Invoice/' . $q . ')');
        $xnodes = fn($q) => iterator_to_array($xpath->query('/inv:Invoice/' . $q));

        $notlar = [];
        foreach ($xnodes('cbc:Note') as $node) {
            $n = trim($node->textContent);
            if ($n !== '') $notlar[] = $n;
        }
        $notlarStr = implode("\n", $notlar);

        try {
            $this->db->update('Faturalar', [
                'Faturalar_MalHizmetTutari'    => (float)$xval('cac:LegalMonetaryTotal/cbc:LineExtensionAmount'),
                'Faturalar_IndirimTutari'      => (float)$xval('cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount'),
                'Faturalar_VergiTutari'        => (float)$xval('cac:TaxTotal/cbc:TaxAmount'),
                'Faturalar_Notlar'             => $notlarStr ?: null,
                'Faturalar_DolarKur'           => self::kurCek($notlarStr, ['USD', 'Dolar', '\$']),
                'Faturalar_EuroKur'            => self::kurCek($notlarStr, ['EUR', 'Euro', '€']),
                'Faturalar_GuncelleyenKullanici' => 1,
                'Faturalar_GuncellemeTarihi'     => date('Y-m-d H:i:s'),
            ], ['Faturalar_id' => $faturaId]);

            // Kalemleri tazele
            $this->db->delete('FaturaKalemleri', ['FaturaKalemleri_Faturalar_id' => $faturaId]);

            $simdi = date('Y-m-d H:i:s');
            foreach ($xpath->query('/inv:Invoice/cac:InvoiceLine') as $line) {
                $lv = fn($q) => $xpath->evaluate('string(' . $q . ')', $line);
                $birimNodes = $xpath->query('cbc:InvoicedQuantity', $line);

                $this->db->insert('FaturaKalemleri', [
                    'FaturaKalemleri_Faturalar_id'         => $faturaId,
                    'FaturaKalemleri_SiraNo'               => (int)$lv('cbc:ID'),
                    'FaturaKalemleri_UrunAdi'              => $lv('cac:Item/cbc:Name'),
                    'FaturaKalemleri_Aciklama'             => $lv('cac:Item/cbc:Description') ?: null,
                    'FaturaKalemleri_Miktar'               => (float)$lv('cbc:InvoicedQuantity'),
                    'FaturaKalemleri_Birim'                => $birimNodes->length
                        ? ($birimNodes->item(0)->getAttribute('unitCode') ?: null) : null,
                    'FaturaKalemleri_BirimFiyati'          => (float)$lv('cac:Price/cbc:PriceAmount'),
                    'FaturaKalemleri_KdvOrani'             => (float)$lv('cac:TaxTotal/cac:TaxSubtotal/cbc:Percent'),
                    'FaturaKalemleri_KdvTutari'            => (float)$lv('cac:TaxTotal/cbc:TaxAmount'),
                    'FaturaKalemleri_ToplamTutar'          => (float)$lv('cbc:LineExtensionAmount'),
                    'FaturaKalemleri_OlusturanKullanici'   => 1,
                    'FaturaKalemleri_OlusturmaTarihi'      => $simdi,
                    'FaturaKalemleri_GuncelleyenKullanici' => 1,
                    'FaturaKalemleri_GuncellemeTarihi'     => $simdi,
                    'FaturaKalemleri_Durum'                => 1,
                ]);
            }

            return true;

        } catch (Exception $e) {
            $this->log("  Detay yazılamadı (fatura #$faturaId): " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * UBL XML'i parse edilebilir hâle getirip DOMXPath döner.
     * İmza bloğu ve gömülü base64 görseller parse'ı boğduğu için konum
     * tabanlı (regex'siz) temizlenir.
     */
    private static function ublXpath(string $xmlStr): ?DOMXPath
    {
        // BOM temizliği
        if (substr($xmlStr, 0, 3) === "\xEF\xBB\xBF") {
            $xmlStr = substr($xmlStr, 3);
        } elseif (substr($xmlStr, 0, 2) === "\xFF\xFE") {
            $xmlStr = mb_convert_encoding($xmlStr, 'UTF-8', 'UTF-16LE');
        } elseif (substr($xmlStr, 0, 2) === "\xFE\xFF") {
            $xmlStr = mb_convert_encoding($xmlStr, 'UTF-8', 'UTF-16BE');
        }

        // 1) Dijital imza bloğu (sertifika + XSLT)
        $sigStart = strpos($xmlStr, '<ds:Signature');
        $sigEnd   = strpos($xmlStr, '</ds:Signature>');
        if ($sigStart !== false && $sigEnd !== false) {
            $xmlStr = substr($xmlStr, 0, $sigStart) . substr($xmlStr, $sigEnd + strlen('</ds:Signature>'));
        }

        // 2) Gömülü binary objeler (XSLT şablonu, logo)
        foreach (['cbc:EmbeddedDocumentBinaryObject', 'ext:ExtensionContent'] as $tag) {
            $open = "<$tag"; $close = "</$tag>";
            while (($s = strpos($xmlStr, $open)) !== false) {
                $e = strpos($xmlStr, $close, $s);
                if ($e === false) break;
                $xmlStr = substr($xmlStr, 0, $s) . substr($xmlStr, $e + strlen($close));
            }
        }

        // 3) cbc:Note içindeki base64 görseller
        $noteOpen = '<cbc:Note>'; $noteClose = '</cbc:Note>'; $offset = 0;
        while (($ns = strpos($xmlStr, $noteOpen, $offset)) !== false) {
            $ne = strpos($xmlStr, $noteClose, $ns);
            if ($ne === false) break;
            $icerik = substr($xmlStr, $ns + strlen($noteOpen), $ne - $ns - strlen($noteOpen));
            if (strpos($icerik, 'Variable_Picture:') !== false || strlen($icerik) > 5000) {
                $xmlStr = substr($xmlStr, 0, $ns) . $noteOpen . $noteClose
                        . substr($xmlStr, $ne + strlen($noteClose));
                $offset = $ns + strlen($noteOpen) + strlen($noteClose);
            } else {
                $offset = $ne + strlen($noteClose);
            }
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->recover = true;
        $ok = $dom->loadXML($xmlStr, LIBXML_PARSEHUGE | LIBXML_NOCDATA);
        libxml_clear_errors();

        if (!$ok || !$dom->documentElement) {
            return null;
        }

        // Varsayılan ad alanı nedeniyle simplexml yerine DOMXPath kullanılır
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::NS_CBC);
        $xpath->registerNamespace('cac', self::NS_CAC);
        $xpath->registerNamespace('inv', self::NS_INV);

        return $xpath;
    }

    // ================================================================
    // Yardımcılar
    // ================================================================

    /** ISSUE_DATE "2026-07-29+03:00" gibi gelebilir → Y-m-d */
    private static function tarihNormalize(?string $deger): ?string
    {
        $deger = trim((string)$deger);
        if ($deger === '') return null;

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $deger, $m)) {
            return $m[1];
        }

        $zaman = strtotime($deger);
        return $zaman !== false ? date('Y-m-d', $zaman) : null;
    }

    /** Not metninden döviz kuru yakala */
    private static function kurCek(string $metin, array $kisaltmalar): ?float
    {
        foreach ($kisaltmalar as $k) {
            if (preg_match('/[\d]+[.,][\d]+\s*' . $k . '|' . $k . '\s*[=:]\s*([\d]+[.,][\d]+)|([\d]+[.,][\d]+)\s*' . $k . '/ui', $metin, $m)) {
                $deger = end($m);
                if ($deger) return (float)str_replace(',', '.', $deger);
            }
        }
        return null;
    }

    private function log(string $mesaj, string $seviye = 'INFO'): void
    {
        $satir = '[' . date('Y-m-d H:i:s') . "] [$seviye] $mesaj";
        if ($this->sessiz) {
            $this->logSatirlari[] = $satir;
        } else {
            echo $satir . "\n";
        }
        @file_put_contents($this->logDosya, $satir . "\n", FILE_APPEND);
    }

    /** Web tarafı için: son çalışmanın log satırları */
    public function getLogSatirlari(): array
    {
        return $this->logSatirlari;
    }

    /** Web tarafı için: ['yeni' => n, 'guncellenen' => n] */
    public function getOzet(): array
    {
        return $this->ozet;
    }
}

// ===== CLI parametreleri =====
// Dosya web tarafından (Güncelle butonu) da include edilir; otomatik çalıştırma
// yalnız komut satırında yapılır.
if (PHP_SAPI === 'cli') {
    $parametreler = [];
    foreach ($argv ?? [] as $arg) {
        if (preg_match('/^--([a-z\-]+)=(.*)$/i', $arg, $m)) {
            $parametreler[strtolower($m[1])] = $m[2];
        }
    }
    if (isset($parametreler['tur'])) $parametreler['tur'] = strtoupper($parametreler['tur']);
    if (isset($parametreler['yon'])) $parametreler['yon'] = strtoupper($parametreler['yon']);

    (new OrnekHoldingSenkron($parametreler))->calistir();
}
