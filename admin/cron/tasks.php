<?php
/**
 * Cron Sistemi — çekirdek yardımcılar + görev fonksiyonları
 * Portal Örnek Soft
 *
 * İçerik:
 *   - cronAyar()           : CronAyarlar tablosundan ayar okur (key, retention vb.)
 *   - cronKeyDogrula()     : web erişiminde gizli anahtar kontrolü
 *   - cronEslesiyor()      : 5 alanlı cron ifadesi ayrıştırıcı
 *   - cronCalisiyorMu()    : overlap koruması + zombi log temizliği
 *   - cronKacirilanTetik() : catch-up (telafi) kontrolü
 *   - cronLogOlustur/Bitir : CronCalismaLog yardımcıları
 *   - dinamikTarih/ParamCoz: {bugun}, {dun} vb. yer tutucular
 *   - gorevCalistir()      : GorevKodu → fonksiyon dispatcher
 *
 * Görev sözleşmesi:
 *   function gorevX(array $params, $db): array
 *   → ['durum' => 1|2, 'sonuc' => 'özet', 'cikti' => 'ob çıktısı']  (1=başarılı, 2=hata)
 */

require_once __DIR__ . '/../db.php';

/**
 * Saat dilimi — PHP CLI .user.ini'yi okumadığı için UTC'ye düşer.
 * DB (+03:00) ile arada 3 saat fark oluşur; cron ifadeleri kayar ve
 * zombi eşiği hatalı hesaplanır. Cron sisteminin tamamı bu dosyayı
 * yüklediği için timezone tek noktadan burada sabitlenir.
 */
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Europe/Istanbul');
}
date_default_timezone_set(APP_TIMEZONE);

// ================================================================
// Ayarlar — CronAyarlar tablosu (kodda hardcode YOK)
// ================================================================
function cronAyar($db, string $anahtar, ?string $varsayilan = null): ?string
{
    static $cache = [];
    if (array_key_exists($anahtar, $cache)) {
        return $cache[$anahtar];
    }

    $satir = $db->fetchOne(
        "SELECT CronAyarlar_Deger FROM dbo.CronAyarlar WHERE CronAyarlar_Anahtar = ? AND Durum = 1",
        [$anahtar]
    );

    return $cache[$anahtar] = ($satir['CronAyarlar_Deger'] ?? $varsayilan);
}

/**
 * Web isteğinde key doğrulaması; CLI/komut satırında key istenmez.
 * Plesk "Run a PHP script" modu php-cgi kullanabilir (SAPI 'cli' olmaz) —
 * HTTP bağlamı yoksa (REMOTE_ADDR boş) komut satırı kabul edilir.
 */
function cronKeyDogrula($db): bool
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['REMOTE_ADDR'])) {
        return true;
    }

    $beklenen = (string)cronAyar($db, 'cron_secret_key', '');
    $gelen    = (string)($_GET['key'] ?? '');

    return $beklenen !== '' && $gelen !== '' && hash_equals($beklenen, $gelen);
}

// ================================================================
// Cron ifadesi ayrıştırıcı (5 alan: dk sa gün ay haftagünü)
// Destek: * · 30 · 0,30 · 1-5 · */15 · 10-50/5 · kombinasyonlar
// ================================================================
function cronEslesiyor(string $ifade, DateTime $dt): bool
{
    $parts = preg_split('/\s+/', trim($ifade));
    if (count($parts) !== 5) return false;
    [$dk, $sa, $gun, $ay, $hgn] = $parts;
    return cronAlanEslesiyor($dk,  (int)$dt->format('i'))
        && cronAlanEslesiyor($sa,  (int)$dt->format('G'))
        && cronAlanEslesiyor($gun, (int)$dt->format('j'))
        && cronAlanEslesiyor($ay,  (int)$dt->format('n'))
        && cronAlanEslesiyor($hgn, (int)$dt->format('w'));   // 0=Pazar
}

function cronAlanEslesiyor(string $alan, int $deger): bool
{
    if ($alan === '*') return true;
    foreach (explode(',', $alan) as $parca) {
        if (str_contains($parca, '/')) {
            [$aralik, $adim] = explode('/', $parca, 2);
            [$bas, $son] = $aralik === '*' ? [0, 59] : array_map('intval', explode('-', $aralik));
            if ($deger >= $bas && $deger <= $son && ($deger - $bas) % (int)$adim === 0) return true;
        } elseif (str_contains($parca, '-')) {
            [$bas, $son] = array_map('intval', explode('-', $parca));
            if ($deger >= $bas && $deger <= $son) return true;
        } elseif ((int)$parca === $deger) {
            return true;
        }
    }
    return false;
}

// ================================================================
// Overlap koruması — görev şu anda çalışıyor mu?
// Zombi kayıtlar (process çökmüş, log açık kalmış) otomatik kapatılır.
// ================================================================
function cronCalisiyorMu($db, int $gorevId, int $maxSureSn): bool
{
    $kayit = $db->fetchOne("
        SELECT TOP 1
            CronCalismaLog_Id AS LogId,
            DATEDIFF(SECOND, CronCalismaLog_BaslangicTarihi, GETDATE()) AS GecenSaniye
        FROM dbo.CronCalismaLog
        WHERE CronCalismaLog_GorevId = ?
          AND CronCalismaLog_CalismaDurum = 0
        ORDER BY CronCalismaLog_Id DESC
    ", [$gorevId]);

    if (!$kayit) return false;

    // Zombi eşiği: görevin kendi max süresinin 1.5 katı (en az 120 sn)
    $zombiEsik = max(120, (int)($maxSureSn * 1.5));

    if ((int)$kayit['GecenSaniye'] > $zombiEsik) {
        $simdi = date('Y-m-d H:i:s');
        $db->update('CronCalismaLog', [
            'CronCalismaLog_BitisTarihi'  => $simdi,
            'CronCalismaLog_SureSaniye'   => (int)$kayit['GecenSaniye'],
            'CronCalismaLog_CalismaDurum' => 2,
            'CronCalismaLog_Sonuc'        => 'Zombi kayit: ' . $zombiEsik . ' sn asildi, otomatik kapatildi.',
            'GuncelleyenKullanici'        => 0,
            'GuncellemeTarihi'            => $simdi,
        ], ['CronCalismaLog_Id' => (int)$kayit['LogId']]);

        return false;   // yol açıldı, yeni çalışma başlayabilir
    }

    return true;   // gerçekten çalışıyor → atla
}

// ================================================================
// Catch-up (telafi) — son çalışmadan bu yana kaçırılmış tetik var mı?
// Kaçırılan tetik sayısı ne olursa olsun EN FAZLA BİR KEZ telafi edilir.
// ================================================================
function cronKacirilanTetik(string $ifade, ?string $sonCalisma, int $sinirSaat, DateTime $simdi): bool
{
    // Hiç çalışmamış zamanlamada telafi YAPILMAZ.
    // Aksi halde yeni eklenen zamanlama, kurulduğu anda geçmiş tetiği "kaçmış" sayar.
    if ($sonCalisma === null || $sonCalisma === '') return false;

    if ($sinirSaat < 1) $sinirSaat = 1;

    $enErken = (clone $simdi)->modify("-{$sinirSaat} hours");

    $tarama = new DateTime($sonCalisma);
    $tarama->setTime((int)$tarama->format('G'), (int)$tarama->format('i'), 0);
    $tarama->modify('+1 minute');

    // Çok eski SonCalisma değerlerinde döngüyü sınırla
    if ($tarama < $enErken) $tarama = clone $enErken;

    $simdiDakika = (clone $simdi)->setTime((int)$simdi->format('G'), (int)$simdi->format('i'), 0);
    $adim = 0;

    while ($tarama < $simdiDakika && $adim < ($sinirSaat * 60) + 1) {
        if (cronEslesiyor($ifade, $tarama)) return true;
        $tarama->modify('+1 minute');
        $adim++;
    }

    return false;
}

// ================================================================
// Log yardımcıları
// ================================================================
function cronLogOlustur($db, int $gorevId, ?int $zamanlamaId, array $params, int $tetikTur, ?int $kullanici = null): int
{
    $simdi = date('Y-m-d H:i:s');
    return (int)$db->insert('CronCalismaLog', [
        'CronCalismaLog_GorevId'             => $gorevId,
        'CronCalismaLog_ZamanlamaId'         => $zamanlamaId,
        'CronCalismaLog_BaslangicTarihi'     => $simdi,
        'CronCalismaLog_Parametreler'        => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        'CronCalismaLog_CalismaDurum'        => 0,
        'CronCalismaLog_TetikleyenTur'       => $tetikTur,
        'CronCalismaLog_TetikleyenKullanici' => $kullanici,
        'OlusturanKullanici'                 => $kullanici ?? 0,
        'OlusturmaTarihi'                    => $simdi,
        'GuncelleyenKullanici'               => $kullanici ?? 0,
        'GuncellemeTarihi'                   => $simdi,
        'Durum'                              => 1,
    ]);
}

function cronLogBitir($db, int $logId, int $durum, string $sonuc, float $basZaman, string $cikti = ''): void
{
    $simdi = date('Y-m-d H:i:s');
    $db->update('CronCalismaLog', [
        'CronCalismaLog_BitisTarihi'  => $simdi,
        'CronCalismaLog_SureSaniye'   => (int)(microtime(true) - $basZaman),
        'CronCalismaLog_CalismaDurum' => $durum,
        'CronCalismaLog_Sonuc'        => mb_substr($sonuc, 0, 2000),
        'CronCalismaLog_Cikti'        => $cikti !== '' ? mb_substr($cikti, 0, 100000) : null,
        'GuncelleyenKullanici'        => 0,
        'GuncellemeTarihi'            => $simdi,
    ], ['CronCalismaLog_Id' => $logId]);
}

// ================================================================
// Dinamik tarih parametreleri ({bugun}, {dun} vb. → dd.mm.yyyy)
// ================================================================
function dinamikTarih(string $deger): string
{
    $bugun = new DateTime();
    $map = [
        '{bugun}'         => fn() => $bugun->format('d.m.Y'),
        '{dun}'           => fn() => (clone $bugun)->modify('-1 day')->format('d.m.Y'),
        '{7gun_once}'     => fn() => (clone $bugun)->modify('-7 days')->format('d.m.Y'),
        '{30gun_once}'    => fn() => (clone $bugun)->modify('-30 days')->format('d.m.Y'),
        '{ay_basi}'       => fn() => $bugun->format('01.m.Y'),
        '{ay_sonu}'       => fn() => (new DateTime('last day of this month'))->format('d.m.Y'),
        '{gecen_ay_basi}' => fn() => (new DateTime('first day of last month'))->format('d.m.Y'),
        '{gecen_ay_sonu}' => fn() => (new DateTime('last day of last month'))->format('d.m.Y'),
        '{3ay_once}'      => fn() => (clone $bugun)->modify('-3 months')->format('d.m.Y'),
        '{6ay_once}'      => fn() => (clone $bugun)->modify('-6 months')->format('d.m.Y'),
    ];
    return isset($map[$deger]) ? ($map[$deger])() : $deger;
}

function dinamikParamCoz(array $params): array
{
    return array_map(fn($v) => is_string($v) ? dinamikTarih($v) : $v, $params);
}

// ================================================================
// Dispatcher — GorevKodu → fonksiyon
// Yeni görev eklemek: fonksiyonu yaz + buraya eşle + CronGorevler'e kayıt ekle
// ================================================================
function gorevCalistir(string $gorevKodu, array $params, $db): array
{
    $eslesme = [
        'cron_log_temizle'      => 'gorevCronLogTemizle',
        'domain_whois_guncelle' => 'gorevDomainWhoisGuncelle',
        'cloudflare_domains'    => 'gorevCloudflareDomains',
        'pazar_puantaj_olustur' => 'gorevPazarPuantajOlustur',
        'gelmedi_sms_gonder'    => 'gorevGelmediSmsGonder',
        'ornekholding_senkron'     => 'gorevOrnekHoldingSenkron',
        'faturam_senkron'       => 'gorevFaturamSenkron',
        'banka_senkron'         => 'gorevBankaSenkron',
        'banka_whatsapp_bildirim' => 'gorevBankaWhatsappBildirim',
    ];

    if (!isset($eslesme[$gorevKodu])) {
        return ['durum' => 2, 'sonuc' => 'Bilinmeyen görev kodu: ' . $gorevKodu, 'cikti' => ''];
    }

    try {
        return $eslesme[$gorevKodu](dinamikParamCoz($params), $db);
    } catch (Throwable $e) {
        // Görev ob_start() açıp bırakmış olabilir — tampon sızmasın
        $cikti = '';
        while (ob_get_level() > 0) {
            $cikti = ob_get_clean() . $cikti;
        }
        return [
            'durum' => 2,
            'sonuc' => get_class($e) . ': ' . $e->getMessage(),
            'cikti' => $cikti,
        ];
    }
}

// ================================================================
// GÖREV: cron_log_temizle
// Eski CronCalismaLog kayıtlarını partiler hâlinde siler (retention).
// gun parametresi verilmezse CronAyarlar.log_saklama_gun kullanılır.
// ================================================================
function gorevCronLogTemizle(array $params, $db): array
{
    ob_start();

    $gun = (int)($params['gun'] ?? 0);
    if ($gun <= 0) {
        $gun = (int)cronAyar($db, 'log_saklama_gun', '90');
    }
    $gun = max(7, $gun);   // güvenlik alt sınırı

    // Silinecek kayıt sayısı önce ölçülür (@@ROWCOUNT'a güvenilmez)
    $adet = (int)($db->fetchOne("
        SELECT COUNT(*) AS a
        FROM dbo.CronCalismaLog
        WHERE CronCalismaLog_BaslangicTarihi < DATEADD(DAY, ?, GETDATE())
          AND CronCalismaLog_CalismaDurum <> 0
    ", [-$gun])['a'] ?? 0);

    // Büyük tabloda tek DELETE log dosyasını şişirir ve tabloyu kilitler
    $silinen = 0;
    while ($silinen < $adet) {
        $db->execute("
            DELETE TOP (5000) FROM dbo.CronCalismaLog
            WHERE CronCalismaLog_BaslangicTarihi < DATEADD(DAY, ?, GETDATE())
              AND CronCalismaLog_CalismaDurum <> 0
        ", [-$gun]);

        $silinen += 5000;
    }

    echo "[OK] {$gun} gunden eski {$adet} log kaydi silindi.\n";

    return [
        'durum' => 1,
        'sonuc' => "{$adet} kayit silindi ({$gun} gun)",
        'cikti' => ob_get_clean(),
    ];
}

// ================================================================
// GÖREV: domain_whois_guncelle
// Mevcut admin/cron/domain-whois-guncelle.php dosyası include edilir.
//
// ZORUNLU: include edilen dosya üst düzeyde değişken atar, yardımcı
// fonksiyonları bunlara `global` ile erişir. Fonksiyon kapsamında
// require edilince bu atamalar yerel değişken olur ve logMessage()
// boş $logFile görüp "Path cannot be empty" hatası verir.
// Bu yüzden dosyanın global ile eriştiği tüm değişkenler burada bildirilir.
// ================================================================
function gorevDomainWhoisGuncelle(array $params, $db): array
{
    global $logFile, $output, $isTestMode, $isWebRequest;

    if (!defined('CRON_DISPATCH')) define('CRON_DISPATCH', true);

    // Dosya web parametrelerini okuyor; görev parametrelerinden besle
    $_GET = !empty($params['test']) ? ['test' => 1] : [];
    unset($_POST['ajax'], $_GET['ajax']);

    ob_start();
    require __DIR__ . '/domain-whois-guncelle.php';
    $cikti = ob_get_clean();

    $ozet = cronOzetle($output ?? [], 'WHOIS guncelleme tamamlandi');

    return [
        'durum' => $ozet['durum'],
        'sonuc' => $ozet['sonuc'],
        'cikti' => $cikti,
    ];
}

// ================================================================
// GÖREV: cloudflare_domains
// Mevcut admin/cron/cloudflare-domains.php dosyası include edilir.
// ================================================================
function gorevCloudflareDomains(array $params, $db): array
{
    global $logFile, $output, $isWebRequest, $isTestMode, $stats;

    if (!defined('CRON_DISPATCH')) define('CRON_DISPATCH', true);

    $_GET = !empty($params['test']) ? ['test' => 1] : [];
    unset($_POST['ajax'], $_GET['ajax']);

    ob_start();
    require __DIR__ . '/cloudflare-domains.php';
    $cikti = ob_get_clean();

    $ozet = 'Cloudflare senkronu tamamlandi';
    if (!empty($stats) && is_array($stats)) {
        $ozet = sprintf(
            'Basarili: %d, Yeni: %d, Degisiklik yok: %d, Basarisiz: %d',
            $stats['basarili'] ?? 0,
            $stats['yeni_eklenen'] ?? 0,
            $stats['degisiklik_yok'] ?? 0,
            $stats['basarisiz'] ?? 0
        );
    }

    $sonuc = cronOzetle($output ?? [], $ozet);

    return [
        'durum' => $sonuc['durum'],
        'sonuc' => $sonuc['sonuc'],
        'cikti' => $cikti,
    ];
}

// ================================================================
// GÖREV: faturam_senkron
// Faturam.net üzerinden abonelik tutarı ve son ödeme tarihini günceller.
// params: {"test": 1} verilirse güncelleme yapılmaz.
// ================================================================
function gorevFaturamSenkron(array $params, $db): array
{
    global $logFile, $output, $isWebRequest, $isTestMode, $ozet;

    if (!defined('CRON_DISPATCH')) define('CRON_DISPATCH', true);

    $_GET = !empty($params['test']) ? ['test' => 1] : [];
    unset($_POST['ajax'], $_GET['ajax']);

    ob_start();
    require __DIR__ . '/faturam-senkron.php';
    $cikti = ob_get_clean();

    $ozetMetni = 'Faturam.net senkronu tamamlandi';
    if (!empty($ozet) && is_array($ozet)) {
        $ozetMetni = sprintf(
            'Toplam: %d, Guncellenen: %d, Degismeyen: %d, Hatali: %d',
            $ozet['toplam'] ?? 0,
            $ozet['guncellenen'] ?? 0,
            $ozet['degismeyen'] ?? 0,
            $ozet['hatali'] ?? 0
        );
    }

    $sonuc = cronOzetle($output ?? [], $ozetMetni);

    return [
        'durum' => $sonuc['durum'],
        'sonuc' => $sonuc['sonuc'],
        'cikti' => $cikti,
    ];
}

/**
 * Include edilen eski cron dosyalarının $output dizisini sayar.
 * Hata/uyarı adedi log Sonuc alanına yazılır ki panelde göze çarpsın;
 * "Tamamlandı" ifadesi sorunu gizler, sayı gizlemez.
 *
 * @return array ['durum' => 1|2, 'sonuc' => '...']
 */
function cronOzetle(array $output, string $varsayilan): array
{
    if (!$output) {
        return ['durum' => 1, 'sonuc' => $varsayilan];
    }

    $hata  = 0;
    $uyari = 0;
    foreach ($output as $satir) {
        $tip = strtoupper($satir['type'] ?? '');
        if ($tip === 'ERROR')   $hata++;
        if ($tip === 'WARNING') $uyari++;
    }

    $ozet = $varsayilan . ' — ' . count($output) . ' log satiri';
    if ($hata > 0)  $ozet .= ", {$hata} hata";
    if ($uyari > 0) $ozet .= ", {$uyari} uyari";

    // Hata varsa çalışma başarısız sayılır; uyarı tek başına başarıyı bozmaz
    return ['durum' => $hata > 0 ? 2 : 1, 'sonuc' => $ozet];
}

// ================================================================
// GÖREV: pazar_puantaj_olustur
// Geçen Pazar günü için aktif personellere "Gelmedi" puantaj kaydı açar.
//
// Eski admin/cron/pazar-puantaj-olustur.php include EDİLEMEZ (4 adet exit
// çağrısı runner'ı sonlandırır); mantık Database sınıfıyla yeniden yazıldı.
// ================================================================
function gorevPazarPuantajOlustur(array $params, $db): array
{
    ob_start();

    $izinDurumId       = 8;   // Pazar Gelmedi
    $sistemKullaniciId = 1;

    // Bugün Pazartesi ise dün Pazar; değilse en son geçen Pazar
    $bugun = new DateTime();
    $pazar = ((int)$bugun->format('N') === 1)
        ? (clone $bugun)->modify('-1 day')
        : (clone $bugun)->modify('last Sunday');

    $pazarTarih = $pazar->format('Y-m-d');
    echo "Islenecek Pazar tarihi: {$pazarTarih}\n";

    // İşe giriş/çıkış tarihine göre o gün kadroda olan personeller
    $personeller = $db->fetchAll("
        SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad AS personel_adi
        FROM kullanicilar
        WHERE kullanici_durum = 1
          AND (kullanici_ise_giris_tarihi IS NULL OR kullanici_ise_giris_tarihi <= ?)
          AND (kullanici_ise_cikis_tarihi IS NULL OR kullanici_ise_cikis_tarihi >= ?)
    ", [$pazarTarih, $pazarTarih]);

    echo "Toplam aktif personel: " . count($personeller) . "\n";

    $eklenen = 0;
    $mevcut  = 0;
    $hatali  = 0;

    foreach ($personeller as $p) {
        $varMi = $db->fetchOne("
            SELECT puantaj_id FROM Personel_Puantaj
            WHERE puantaj_kullanici_id = ?
              AND puantaj_tarih = ?
              AND puantaj_aktif = 1
        ", [$p['kullanici_id'], $pazarTarih]);

        if ($varMi) {
            $mevcut++;
            continue;
        }

        try {
            $db->insert('Personel_Puantaj', [
                'puantaj_kullanici_id'           => $p['kullanici_id'],
                'puantaj_tarih'                  => $pazarTarih,
                'puantaj_izin_durum_id'          => $izinDurumId,
                'puantaj_olusturan_kullanici_id' => $sistemKullaniciId,
                'puantaj_olusturma_tarihi'       => date('Y-m-d H:i:s'),
                'puantaj_aktif'                  => 1,
            ]);
            $eklenen++;
        } catch (Throwable $e) {
            echo "HATA - {$p['personel_adi']}: " . $e->getMessage() . "\n";
            $hatali++;
        }
    }

    echo "Eklenen: {$eklenen}, Mevcut: {$mevcut}, Hatali: {$hatali}\n";

    return [
        'durum' => $hatali > 0 ? 2 : 1,
        'sonuc' => "{$pazarTarih} — eklenen: {$eklenen}, mevcut: {$mevcut}, hatali: {$hatali}",
        'cikti' => ob_get_clean(),
    ];
}

// ================================================================
// GÖREV: gelmedi_sms_gonder
// Bugün "Gelmedi" (izin_durum_id=2) kaydı olan personellere SMS gönderir.
// Pazar otomatik kaydı (izin_durum_id=8) kapsam dışıdır.
//
// Eski admin/cron/gelmedi-sms-gonder.php include EDİLEMEZ (7 adet exit
// çağrısı runner'ı sonlandırır); mantık ortak SMS helper'ları ve Database
// sınıfıyla yeniden yazıldı.
//
// params: test=1 → SMS gönderilmez, log'a TEST_MODE düşer, Pazar kontrolü atlanır
// ================================================================
function gorevGelmediSmsGonder(array $params, $db): array
{
    require_once __DIR__ . '/../includes/SmsSender.php';

    ob_start();

    $testMode = !empty($params['test']);

    if ($testMode) {
        echo "[TEST MODU] Gercek SMS gonderilmeyecek.\n";
    }

    // Pazar günü SMS gönderilmez (test modunda kontrol atlanır)
    if ((int)date('N') === 7 && !$testMode) {
        echo "Bugun Pazar - SMS gonderilmeyecek.\n";
        return ['durum' => 1, 'sonuc' => 'Pazar - gonderim yok', 'cikti' => ob_get_clean()];
    }

    // Varsayılan SMS kanalı (ortak entegrasyon yapısından)
    $kanal = ornek_sms_kanal($db);
    if (!$kanal) {
        echo "HATA: Aktif SMS kanali bulunamadi.\n";
        return ['durum' => 2, 'sonuc' => 'Aktif SMS kanali yok - gonderim iptal', 'cikti' => ob_get_clean()];
    }

    echo "SMS Kanali: {$kanal['ad']}\n";

    $bugun       = date('Y-m-d');
    $bugunFormat = date('d.m.Y');
    echo "Tarih: {$bugunFormat}\n";

    $gelmeyenler = $db->fetchAll("
        SELECT k.kullanici_id, k.kullanici_ad, k.kullanici_soyad, k.kullanici_telefon
        FROM Personel_Puantaj p
        INNER JOIN kullanicilar k ON p.puantaj_kullanici_id = k.kullanici_id
        WHERE p.puantaj_tarih = ?
          AND p.puantaj_izin_durum_id = 2
          AND p.puantaj_aktif = 1
          AND k.kullanici_durum = 1
          AND k.kullanici_telefon IS NOT NULL
          AND k.kullanici_telefon <> ''
        ORDER BY k.kullanici_ad, k.kullanici_soyad
    ", [$bugun]);

    $toplam = count($gelmeyenler);
    echo "Bugun gelmeyen personel sayisi: {$toplam}\n";

    if ($toplam === 0) {
        echo "Gonderilecek SMS yok.\n";
        return ['durum' => 1, 'sonuc' => '0 kisi - gonderilecek SMS yok', 'cikti' => ob_get_clean()];
    }

    $basarili  = 0;
    $basarisiz = 0;

    foreach ($gelmeyenler as $p) {
        $adSoyad = trim($p['kullanici_ad'] . ' ' . $p['kullanici_soyad']);
        $telefon = $p['kullanici_telefon'];
        $mesaj   = "Sayın {$adSoyad}, bugün ({$bugunFormat}) devamsızlık kaydınız bulunmaktadır.";

        echo "SMS: {$adSoyad} ({$telefon})\n";

        if ($testMode) {
            echo "  [TEST] Gonderilmedi.\n";
            ornek_sms_log_entegrasyon(
                $db, $kanal['id'], 'SMS_GELMEDI', $mesaj, 'TEST_MODE', 1,
                null, 0, $telefon, 'CRON'
            );
            $basarili++;
            continue;
        }

        $sonuc = ornek_sms_send($telefon, $mesaj, $kanal);

        if (!empty($sonuc['success'])) {
            $basarili++;
            echo "  OK - " . mb_substr((string)($sonuc['response'] ?? ''), 0, 100) . "\n";
            ornek_sms_log_entegrasyon(
                $db, $kanal['id'], 'SMS_GELMEDI', $mesaj,
                mb_substr((string)($sonuc['response'] ?? ''), 0, 500), 1,
                null, 0, $telefon, 'CRON'
            );
        } else {
            $basarisiz++;
            $hata = $sonuc['error'] ?? $sonuc['response'] ?? 'Bilinmeyen hata';
            echo "  HATA - {$hata}\n";
            ornek_sms_log_entegrasyon(
                $db, $kanal['id'], 'SMS_GELMEDI', $mesaj, null, 0,
                mb_substr((string)$hata, 0, 500), 0, $telefon, 'CRON'
            );
        }

        sleep(1);   // saglayici rate limit
    }

    echo "--- OZET ---\nToplam: {$toplam}, Basarili: {$basarili}, Basarisiz: {$basarisiz}\n";

    return [
        'durum' => $basarisiz > 0 ? 2 : 1,
        'sonuc' => ($testMode ? '[TEST] ' : '') . "toplam: {$toplam}, basarili: {$basarili}, basarisiz: {$basarisiz}",
        'cikti' => ob_get_clean(),
    ];
}

// ================================================================
// GÖREV: ornekholding_senkron
// Örnek Holding kanallarından E-Fatura / E-Arşiv faturalarını çeker.
//
// ornekholding-senkron.php kullanılır. Eski ornekholding_senkronizasyon.php tek
// istekte LIMIT=100 gönderdiği için yoğun kanallarda (ÖRNEK) servis tavanına
// takılıyor, hep aynı en eski 100 belge dönüyor ve yeni faturalar hiç
// ulaşmıyordu. Yeni dosya tarih aralığını dilimliyor, tavana dayanan dilimi
// otomatik ikiye bölüyor.
//
// Dosya include edilebilir durumda (exit yok, global yok, sınıf tabanlı,
// log() doğrudan echo ediyor). Tek uyarlama: CLI parametrelerini $argv'den
// okuduğu için görev parametreleri $argv'ye enjekte edilir.
//
// UZUN SÜRER: MaxSureSn=3600. Panelden tetiklenirse web sunucusu zaman
// aşımına düşebilir; CLI worker ile çalıştırılmalıdır:
//   php admin/cron/worker.php gorev=ornekholding_senkron gun=30
//
// params: gun (varsayılan 30), dilim (varsayılan 5), kanal, tur (EFATURA|EARSIV),
//         yon (GELEN|GIDEN), detay (0 = kalem detayı çekme), detay-limit
// ================================================================
function gorevOrnekHoldingSenkron(array $params, $db): array
{
    global $argv;

    $eskiArgv = $argv ?? null;

    // Dosyanın beklediği --anahtar=deger biçimine çevir
    $argv = ['ornekholding_senkron'];
    if (!empty($params['gun']))         $argv[] = '--gun=' . (int)$params['gun'];
    if (!empty($params['dilim']))       $argv[] = '--dilim=' . (int)$params['dilim'];
    if (!empty($params['kanal']))       $argv[] = '--kanal=' . (int)$params['kanal'];
    if (!empty($params['tur']))         $argv[] = '--tur=' . strtoupper((string)$params['tur']);
    if (!empty($params['yon']))         $argv[] = '--yon=' . strtoupper((string)$params['yon']);
    if (!empty($params['detay-limit'])) $argv[] = '--detay-limit=' . (int)$params['detay-limit'];
    if (isset($params['detay']) && (string)$params['detay'] === '0') $argv[] = '--detay=0';

    ob_start();
    try {
        require __DIR__ . '/ornekholding-senkron.php';
    } finally {
        // $argv'yi her durumda eski hâline döndür (runner sıralı çalışır,
        // sonraki görevler bozulmuş $argv görmemeli)
        $argv = $eskiArgv;
    }
    $cikti = ob_get_clean();

    // Özet: çıktının son anlamlı satırı (servis ilerlemeyi echo ile yazıyor)
    $satirlar = array_values(array_filter(array_map('trim', explode("\n", $cikti)), fn($s) => $s !== ''));
    $sonSatir = $satirlar ? end($satirlar) : 'Senkronizasyon tamamlandi';

    return [
        'durum' => 1,
        'sonuc' => mb_substr($sonSatir, 0, 500),
        'cikti' => $cikti,
    ];
}

// ================================================================
// Yardımcı: harici CLI süreci
//
// Banka cron dosyaları (banka-senkron.php, BankaWhatsappBildirim.php)
// üst düzeyde exit() çağırıyor. require ile
// include edilirlerse runner'ı da sonlandırır, açık CronCalismaLog kaydı
// asılı kalır ve sıradaki görevler hiç çalışmaz. Bu yüzden ayrı bir PHP
// CLI süreci açılır; çıkış kodu görev durumuna çevrilir.
//
// @return array ['kod' => int, 'cikti' => string]
// ================================================================
function cronHariciSurec(string $dosya, array $argumanlar = [], int $zamanAsimiSn = 600): array
{
    $php = str_ireplace('php-cgi.exe', 'php.exe', PHP_BINARY);
    $yol = __DIR__ . DIRECTORY_SEPARATOR . $dosya;

    if (!is_file($yol)) {
        return ['kod' => 1, 'cikti' => 'Dosya bulunamadi: ' . $yol];
    }

    // PHP CLI .user.ini'yi okumaz, UTC'ye düşer ve loglar 3 saat geriye kayar.
    // Timezone alt sürece açıkça geçirilir (APP_TIMEZONE tasks.php'de tanımlı).
    $komut = array_merge([$php, '-d', 'date.timezone=' . APP_TIMEZONE, $yol], $argumanlar);

    $borular = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $surec = @proc_open($komut, $borular, $pipes, __DIR__);

    if (!is_resource($surec)) {
        return ['kod' => 1, 'cikti' => 'Surec baslatilamadi: ' . $dosya];
    }

    // Bloklamayan okuma — büyük çıktıda pipe dolup kilitlenmesin
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $cikti = '';
    $bitis = time() + $zamanAsimiSn;
    $zamanAsti = false;

    while (true) {
        $cikti .= (string)stream_get_contents($pipes[1]);
        $cikti .= (string)stream_get_contents($pipes[2]);

        $durum = proc_get_status($surec);
        if (!$durum['running']) break;

        if (time() >= $bitis) {
            proc_terminate($surec);
            $zamanAsti = true;
            break;
        }

        usleep(200000); // 0.2 sn
    }

    $cikti .= (string)stream_get_contents($pipes[1]);
    $cikti .= (string)stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $kod = proc_close($surec);
    if ($zamanAsti) {
        $kod = 1;
        $cikti .= "\n[HATA] Zaman asimi ({$zamanAsimiSn} sn) — surec sonlandirildi.";
    }

    return ['kod' => $kod, 'cikti' => $cikti];
}

/**
 * Harici süreç sonucunu görev sözleşmesine çevirir.
 * Banka dosyaları JSON döndürüyor; son satır "}" olmasın diye önce
 * JSON'un message alanı denenir, olmazsa son anlamlı satıra düşülür.
 */
function cronSurecSonucu(array $sonuc, string $varsayilanOzet): array
{
    $ham = trim($sonuc['cikti']);

    $json = json_decode($ham, true);
    if (is_array($json) && isset($json['message']) && is_string($json['message'])) {
        return [
            'durum' => ($sonuc['kod'] === 0 && ($json['success'] ?? true)) ? 1 : 2,
            'sonuc' => mb_substr($json['message'], 0, 500),
            'cikti' => $sonuc['cikti'],
        ];
    }

    $satirlar = array_values(array_filter(
        array_map('trim', explode("\n", $sonuc['cikti'])),
        fn($s) => $s !== ''
    ));

    $ozet = $satirlar ? end($satirlar) : $varsayilanOzet;

    return [
        'durum' => $sonuc['kod'] === 0 ? 1 : 2,
        'sonuc' => mb_substr($ozet, 0, 500),
        'cikti' => $sonuc['cikti'],
    ];
}

// ================================================================
// GÖREV: banka_senkron
// Aktif banka API kimlikleri üzerinden hesap hareketlerini çeker.
// Ayrı süreçte çalışır (dosya exit() içeriyor).
//
// params: api_kimlik_id (bos = tum aktif kimlikler)
// ================================================================
function gorevBankaSenkron(array $params, $db): array
{
    $argumanlar = [];
    if (!empty($params['api_kimlik_id'])) {
        $argumanlar[] = (string)(int)$params['api_kimlik_id'];
    }

    $sonuc = cronHariciSurec('banka-senkron.php', $argumanlar, 900);

    return cronSurecSonucu($sonuc, 'Banka senkronu tamamlandi');
}

// ================================================================
// GÖREV: banka_whatsapp_bildirim
// Banka hareketleri için WhatsApp bildirimi gönderir.
//
// params: minutes (Plesk'te 1500 kullaniliyordu), test (1 = dry-run)
// ================================================================
function gorevBankaWhatsappBildirim(array $params, $db): array
{
    $argumanlar = [];
    if (!empty($params['minutes'])) $argumanlar[] = '--minutes=' . (int)$params['minutes'];
    if (!empty($params['test']))    $argumanlar[] = '--dry-run';

    $sonuc = cronHariciSurec('BankaWhatsappBildirim.php', $argumanlar, 600);

    return cronSurecSonucu($sonuc, 'WhatsApp bildirimi tamamlandi');
}
