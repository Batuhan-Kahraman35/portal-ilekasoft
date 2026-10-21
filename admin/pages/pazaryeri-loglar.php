<?php
/**
 * Admin Panel - Pazaryeri Logları
 *
 * İki sekme:
 *   1. API çağrıları: pazaryeri sınıflarının yaptığı her çağrının isteği, yanıtı,
 *      süresi. Kimlik bilgileri PazaryeriApi tarafından maskelenerek yazılır;
 *      ekrana basılırken bir kez daha maskelenir.
 *   2. Toplu istekler (batch): asenkron isteklerin takibi. Bekleyen batch'in
 *      sonucu buradan pazaryerinden sorgulanır.
 *
 * Yalnız pazaryeri kaynaklı loglar listelenir: EntegrasyonLoglari_Kaynak = 'pazaryeri'
 * VE kanal bir pazaryeri entegrasyonuna bağlı (Entegrasyonlar_Kod IN tanim_Pazaryerleri
 * .Pazaryerleri_EntegrasyonKod). Portaldaki diğer entegrasyon logları karışmaz.
 *
 * EntegrasyonLoglari en hızlı büyüyen tablo olduğu için DataTables SERVER-SIDE çalışır.
 * Kaynak: Örnek Müşteri admin/pages/pazaryeri-loglar.php (portal şemasına uyarlandı).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri Logları';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Firma kısıtı: null → kısıt yok, >0 → yalnız o firma, 0 → hiç kayıt
$kisitFirmaId = PageAuth::firmaKisiti($pagePermissions, $user['kullanici_id']);

// Sıralama: DataTables kolon index → kolon adı (istemci değeri sorguya girmez)
const LOG_SIRALAMA = [
    1 => 'l.EntegrasyonLoglari_OlusturmaTarihi',
    2 => 'l.EntegrasyonLoglari_EntegrasyonKanallari_id',
    3 => 'l.EntegrasyonLoglari_IslemTipi',
    4 => 'l.EntegrasyonLoglari_HttpKod',
    5 => 'l.EntegrasyonLoglari_SureMs',
    6 => 'l.EntegrasyonLoglari_BasariliMi',
];

const BATCH_SIRALAMA = [
    0 => 'b.PazaryeriBatchIstekleri_OlusturmaTarihi',
    1 => 'b.PazaryeriBatchIstekleri_EntegrasyonKanallari_id',
    2 => 'b.PazaryeriBatchIstekleri_BatchId',
    3 => 'b.PazaryeriBatchIstekleri_Tip',
    4 => 'b.PazaryeriBatchIstekleri_ItemSayisi',
    5 => 'b.PazaryeriBatchIstekleri_IslemDurumu',
];

/**
 * Pazaryeri entegrasyonuna bağlı, kullanıcının görebildiği kanallar (pasifler dahil:
 * pasife alınmış kanalın geçmiş logları da incelenebilmeli).
 * Pasif firmaya bağlı kanallar hariç tutulur (PazaryeriFabrika::aktifKanalSql ile aynı kural).
 */
function pzKanalListesi($db, $kisitFirmaId)
{
    [$kisitSql, $params] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.EntegrasyonKanallari_Firmalar_id');

    return $db->fetchAll("
        SELECT
            k.EntegrasyonKanallari_id,
            k.EntegrasyonKanallari_Ad,
            CAST(k.EntegrasyonKanallari_Durum AS INT) AS EntegrasyonKanallari_Durum,
            k.EntegrasyonKanallari_Firmalar_id,
            f.firma_adi,
            p.Pazaryerleri_id,
            p.Pazaryerleri_Kod,
            p.Pazaryerleri_Ad,
            p.Pazaryerleri_Renk
        FROM dbo.EntegrasyonKanallari k
        INNER JOIN dbo.Entegrasyonlar e
            ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
        INNER JOIN dbo.tanim_Pazaryerleri p
            ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
        LEFT JOIN dbo.Firmalar f
            ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        WHERE (k.EntegrasyonKanallari_Firmalar_id IS NULL OR f.firma_durum = 1)
        {$kisitSql}
        ORDER BY p.Pazaryerleri_Sira, k.EntegrasyonKanallari_Ad
    ", $params);
}

/**
 * Filtreye göre kanal id'leri. Liste küçük olduğu için PHP'de süzülür; sonuç
 * tamsayı listesi olarak IN (...) içine yazılır → log sayımları JOIN'siz kalır.
 */
function pzKanalIdleri(array $kanallar, $firmaId = 0, $pazaryeriId = 0, $kanalId = 0)
{
    $idler = [];
    foreach ($kanallar as $k) {
        if ($firmaId > 0 && (int)$k['EntegrasyonKanallari_Firmalar_id'] !== $firmaId) continue;
        if ($pazaryeriId > 0 && (int)$k['Pazaryerleri_id'] !== $pazaryeriId) continue;
        if ($kanalId > 0 && (int)$k['EntegrasyonKanallari_id'] !== $kanalId) continue;
        $idler[] = (int)$k['EntegrasyonKanallari_id'];
    }
    return $idler;
}

/** Tamsayı listesinden güvenli IN içeriği; boşsa hiçbir satırla eşleşmeyen değer */
function pzInListe(array $idler)
{
    return $idler ? implode(',', array_map('intval', $idler)) : '-1';
}

/**
 * Ekrana basmadan önce ikinci maskeleme. Log yazılırken PazaryeriApi zaten
 * maskeler; burada eski veya elle yazılmış kayıtlara karşı ek güvence.
 */
function pzMaskele($metin)
{
    if ($metin === null || $metin === '') {
        return $metin;
    }
    $gizli = PazaryeriApi::GIZLI_ANAHTARLAR;

    $json = json_decode($metin, true);
    if (is_array($json)) {
        $maskele = function ($dizi) use (&$maskele, $gizli) {
            foreach ($dizi as $a => $d) {
                if (is_array($d)) {
                    $dizi[$a] = $maskele($d);
                } elseif (is_string($a) && in_array(strtolower($a), array_map('strtolower', $gizli), true)) {
                    $dizi[$a] = '***';
                }
            }
            return $dizi;
        };
        $metin = json_encode($maskele($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $grup = implode('|', array_map(fn($a) => preg_quote($a, '/'), $gizli));
    $metin = preg_replace('/("(?:' . $grup . ')"\s*:\s*)"[^"]*"/i', '$1"***"', $metin);       // JSON
    $metin = preg_replace('/(<(?:\w+:)?(?:' . $grup . ')>)[^<]*(<\/)/i', '$1***$2', $metin);    // SOAP/XML
    $metin = preg_replace('/\b((?:' . $grup . ')=)[^&"\s]+/i', '$1***', $metin);               // sorgu dizesi
    return $metin;
}

/** DataTables boş yanıt */
function pzBosListe()
{
    return ['draw' => (int)($_POST['draw'] ?? 0), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
}

// =====================================================================
// AJAX
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $cik = function ($veri) use ($jsonBayrak) {
        echo json_encode($veri, $jsonBayrak);
        exit;
    };

    $action = $_POST['action'] ?? '';

    // Ortak filtreler (her iki sekme ve InfoBox)
    $kanallar    = pzKanalListesi($db, $kisitFirmaId);
    $firmaId     = (int)($_POST['f_firma'] ?? 0);
    $pazaryeriId = (int)($_POST['f_pazaryeri'] ?? 0);
    $kanalId     = (int)($_POST['f_kanal'] ?? 0);

    $tumKanalIn     = pzInListe(pzKanalIdleri($kanallar));                                   // yalnız yetki kısıtı
    $filtreKanalIn  = pzInListe(pzKanalIdleri($kanallar, $firmaId, $pazaryeriId, $kanalId)); // + ekran filtresi
    $logKaynak      = PazaryeriApi::LOG_KAYNAK;

    try {
        switch ($action) {

            // ---------------------------------------------------------
            // InfoBox (tablo yüklendikten sonra ayrı çağrılır)
            case 'stats':
                $s = $db->fetchOne("
                    SELECT
                        (SELECT COUNT(*) FROM dbo.PazaryeriBatchIstekleri
                          WHERE PazaryeriBatchIstekleri_EntegrasyonKanallari_id IN ({$filtreKanalIn})
                            AND PazaryeriBatchIstekleri_Durum = 1
                            AND PazaryeriBatchIstekleri_IslemDurumu = 'beklemede')              AS bekleyenBatch,
                        (SELECT COUNT(*) FROM dbo.EntegrasyonLoglari
                          WHERE EntegrasyonLoglari_EntegrasyonKanallari_id IN ({$filtreKanalIn})
                            AND EntegrasyonLoglari_Kaynak = ?
                            AND EntegrasyonLoglari_OlusturmaTarihi >= DATEADD(HOUR, -24, GETDATE()))  AS gunlukCagri,
                        (SELECT COUNT(*) FROM dbo.EntegrasyonLoglari
                          WHERE EntegrasyonLoglari_EntegrasyonKanallari_id IN ({$filtreKanalIn})
                            AND EntegrasyonLoglari_Kaynak = ?
                            AND EntegrasyonLoglari_BasariliMi = 0
                            AND EntegrasyonLoglari_OlusturmaTarihi >= DATEADD(HOUR, -24, GETDATE()))  AS gunlukHata,
                        (SELECT AVG(CAST(EntegrasyonLoglari_SureMs AS FLOAT)) FROM dbo.EntegrasyonLoglari
                          WHERE EntegrasyonLoglari_EntegrasyonKanallari_id IN ({$filtreKanalIn})
                            AND EntegrasyonLoglari_Kaynak = ?
                            AND EntegrasyonLoglari_OlusturmaTarihi >= DATEADD(HOUR, -24, GETDATE()))  AS ortSure
                ", [$logKaynak, $logKaynak, $logKaynak]);

                $cik(['success' => true, 'data' => [
                    'bekleyenBatch' => (int)($s['bekleyenBatch'] ?? 0),
                    'gunlukCagri'   => (int)($s['gunlukCagri'] ?? 0),
                    'gunlukHata'    => (int)($s['gunlukHata'] ?? 0),
                    'ortSure'       => $s['ortSure'] !== null ? (int)round($s['ortSure']) : null
                ]]);

            // ---------------------------------------------------------
            // API çağrıları (server-side)
            case 'logList':
                if ($tumKanalIn === '-1') {
                    $cik(pzBosListe());
                }

                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) { $length = 25; }

                $siraKol = LOG_SIRALAMA[(int)($_POST['order'][0]['column'] ?? 1)] ?? LOG_SIRALAMA[1];
                $siraYon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
                $orderSql = "{$siraKol} {$siraYon}, l.EntegrasyonLoglari_id DESC";

                $kosul  = " WHERE l.EntegrasyonLoglari_EntegrasyonKanallari_id IN ({$filtreKanalIn})
                              AND l.EntegrasyonLoglari_Kaynak = ? ";
                $params = [$logKaynak];

                $ara = trim($_POST['f_ara'] ?? '');
                if ($ara !== '') {
                    $kosul .= " AND (l.EntegrasyonLoglari_IslemTipi LIKE ? OR l.EntegrasyonLoglari_Hedef LIKE ?
                                     OR l.EntegrasyonLoglari_HataMesaji LIKE ? OR l.EntegrasyonLoglari_Cevap LIKE ?) ";
                    $like = '%' . $ara . '%';
                    array_push($params, $like, $like, $like, $like);
                }
                $sonuc = $_POST['f_sonuc'] ?? '';
                if ($sonuc === '1' || $sonuc === '0') {
                    $kosul .= " AND l.EntegrasyonLoglari_BasariliMi = ? ";
                    $params[] = (int)$sonuc;
                }
                if (($_POST['f_bas'] ?? '') !== '') {
                    $kosul .= " AND l.EntegrasyonLoglari_OlusturmaTarihi >= CAST(? AS DATE) ";
                    $params[] = $_POST['f_bas'];
                }
                if (($_POST['f_bit'] ?? '') !== '') {
                    $kosul .= " AND l.EntegrasyonLoglari_OlusturmaTarihi < DATEADD(DAY, 1, CAST(? AS DATE)) ";
                    $params[] = $_POST['f_bit'];
                }

                // Sayımlar: JOIN'siz, tek tablo
                $toplam = $db->fetchOne("
                    SELECT COUNT(*) AS adet FROM dbo.EntegrasyonLoglari
                    WHERE EntegrasyonLoglari_EntegrasyonKanallari_id IN ({$tumKanalIn})
                      AND EntegrasyonLoglari_Kaynak = ?
                ", [$logKaynak]);
                $filtreli = $db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.EntegrasyonLoglari l {$kosul}", $params);

                // 1) Ucuz CTE: yalnız sayfadaki id'ler  2) Dış SELECT: ad çözümleri + özet
                $liste = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT l.EntegrasyonLoglari_id
                        FROM dbo.EntegrasyonLoglari l
                        {$kosul}
                        ORDER BY {$orderSql}
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        l.EntegrasyonLoglari_id                                         AS id,
                        CONVERT(VARCHAR(19), l.EntegrasyonLoglari_OlusturmaTarihi, 120) AS tarih,
                        l.EntegrasyonLoglari_EntegrasyonKanallari_id                    AS kanal_id,
                        l.EntegrasyonLoglari_IslemTipi                                  AS islem,
                        l.EntegrasyonLoglari_Hedef                                      AS hedef,
                        l.EntegrasyonLoglari_HttpKod                                    AS http_kod,
                        l.EntegrasyonLoglari_SureMs                                     AS sure_ms,
                        CAST(l.EntegrasyonLoglari_BasariliMi AS INT)                    AS basarili,
                        LEFT(COALESCE(l.EntegrasyonLoglari_HataMesaji, l.EntegrasyonLoglari_Cevap, ''), 160) AS ozet,
                        k.EntegrasyonKanallari_Ad                                       AS kanal_adi,
                        p.Pazaryerleri_Ad                                               AS pazaryeri_adi,
                        p.Pazaryerleri_Renk                                             AS pazaryeri_renk,
                        f.firma_adi                                                     AS firma_adi
                    FROM sayfa s
                    INNER JOIN dbo.EntegrasyonLoglari l   ON l.EntegrasyonLoglari_id = s.EntegrasyonLoglari_id
                    LEFT JOIN dbo.EntegrasyonKanallari k  ON k.EntegrasyonKanallari_id = l.EntegrasyonLoglari_EntegrasyonKanallari_id
                    LEFT JOIN dbo.Entegrasyonlar e        ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p    ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
                    LEFT JOIN dbo.Firmalar f              ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    ORDER BY {$orderSql}
                ", array_merge($params, [$start, $length]));

                foreach ($liste as &$satir) {
                    $satir['ozet']  = pzMaskele($satir['ozet']);
                    $satir['hedef'] = pzMaskele($satir['hedef']);
                }
                unset($satir);

                $cik([
                    'draw'            => (int)($_POST['draw'] ?? 0),
                    'recordsTotal'    => (int)($toplam['adet'] ?? 0),
                    'recordsFiltered' => (int)($filtreli['adet'] ?? 0),
                    'data'            => $liste
                ]);

            // ---------------------------------------------------------
            case 'logDetay':
                $row = $db->fetchOne("
                    SELECT
                        l.EntegrasyonLoglari_id,
                        CONVERT(VARCHAR(19), l.EntegrasyonLoglari_OlusturmaTarihi, 120) AS tarih,
                        l.EntegrasyonLoglari_IslemTipi,
                        l.EntegrasyonLoglari_Hedef,
                        l.EntegrasyonLoglari_Istek,
                        l.EntegrasyonLoglari_Cevap,
                        l.EntegrasyonLoglari_HataMesaji,
                        l.EntegrasyonLoglari_HttpKod,
                        l.EntegrasyonLoglari_SureMs,
                        CAST(l.EntegrasyonLoglari_BasariliMi AS INT) AS basarili,
                        l.EntegrasyonLoglari_IP,
                        k.EntegrasyonKanallari_Ad,
                        p.Pazaryerleri_Ad,
                        LTRIM(CONCAT(u.kullanici_ad, ' ', u.kullanici_soyad)) AS kullanici
                    FROM dbo.EntegrasyonLoglari l
                    LEFT JOIN dbo.EntegrasyonKanallari k ON k.EntegrasyonKanallari_id = l.EntegrasyonLoglari_EntegrasyonKanallari_id
                    LEFT JOIN dbo.Entegrasyonlar e       ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p   ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
                    LEFT JOIN dbo.kullanicilar u         ON u.kullanici_id = l.EntegrasyonLoglari_KullaniciId
                    WHERE l.EntegrasyonLoglari_id = ?
                      AND l.EntegrasyonLoglari_Kaynak = ?
                      AND l.EntegrasyonLoglari_EntegrasyonKanallari_id IN ({$tumKanalIn})
                ", [(int)($_POST['id'] ?? 0), $logKaynak]);

                if (!$row) {
                    $cik(['success' => false, 'message' => 'Log kaydı bulunamadı.']);
                }
                foreach (['EntegrasyonLoglari_Hedef', 'EntegrasyonLoglari_Istek', 'EntegrasyonLoglari_Cevap', 'EntegrasyonLoglari_HataMesaji'] as $alan) {
                    $row[$alan] = pzMaskele($row[$alan]);
                }
                $cik(['success' => true, 'data' => $row]);

            // ---------------------------------------------------------
            // Toplu istekler (server-side)
            case 'batchList':
                if ($tumKanalIn === '-1') {
                    $cik(pzBosListe());
                }

                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) { $length = 25; }

                $siraKol = BATCH_SIRALAMA[(int)($_POST['order'][0]['column'] ?? 0)] ?? BATCH_SIRALAMA[0];
                $siraYon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
                $orderSql = "{$siraKol} {$siraYon}, b.PazaryeriBatchIstekleri_id DESC";

                $kosul  = " WHERE b.PazaryeriBatchIstekleri_EntegrasyonKanallari_id IN ({$filtreKanalIn})
                              AND b.PazaryeriBatchIstekleri_Durum = 1 ";
                $params = [];

                if (($_POST['f_batch_durum'] ?? '') !== '') {
                    $kosul .= " AND b.PazaryeriBatchIstekleri_IslemDurumu = ? ";
                    $params[] = $_POST['f_batch_durum'];
                }
                $ara = trim($_POST['f_ara'] ?? '');
                if ($ara !== '') {
                    $kosul .= " AND (b.PazaryeriBatchIstekleri_BatchId LIKE ? OR b.PazaryeriBatchIstekleri_Tip LIKE ?) ";
                    $like = '%' . $ara . '%';
                    array_push($params, $like, $like);
                }
                if (($_POST['f_bas'] ?? '') !== '') {
                    $kosul .= " AND b.PazaryeriBatchIstekleri_OlusturmaTarihi >= CAST(? AS DATE) ";
                    $params[] = $_POST['f_bas'];
                }
                if (($_POST['f_bit'] ?? '') !== '') {
                    $kosul .= " AND b.PazaryeriBatchIstekleri_OlusturmaTarihi < DATEADD(DAY, 1, CAST(? AS DATE)) ";
                    $params[] = $_POST['f_bit'];
                }

                $toplam = $db->fetchOne("
                    SELECT COUNT(*) AS adet FROM dbo.PazaryeriBatchIstekleri
                    WHERE PazaryeriBatchIstekleri_EntegrasyonKanallari_id IN ({$tumKanalIn})
                      AND PazaryeriBatchIstekleri_Durum = 1
                ");
                $filtreli = $db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.PazaryeriBatchIstekleri b {$kosul}", $params);

                $liste = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT b.PazaryeriBatchIstekleri_id
                        FROM dbo.PazaryeriBatchIstekleri b
                        {$kosul}
                        ORDER BY {$orderSql}
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        b.PazaryeriBatchIstekleri_id                                         AS id,
                        CONVERT(VARCHAR(16), b.PazaryeriBatchIstekleri_OlusturmaTarihi, 120) AS tarih,
                        b.PazaryeriBatchIstekleri_EntegrasyonKanallari_id                    AS kanal_id,
                        b.PazaryeriBatchIstekleri_BatchId                                    AS batch_id,
                        b.PazaryeriBatchIstekleri_Tip                                        AS tip,
                        b.PazaryeriBatchIstekleri_ItemSayisi                                 AS item_sayisi,
                        b.PazaryeriBatchIstekleri_BasariliSayi                               AS basarili_sayi,
                        b.PazaryeriBatchIstekleri_HataliSayi                                 AS hatali_sayi,
                        b.PazaryeriBatchIstekleri_IslemDurumu                                AS islem_durumu,
                        CONVERT(VARCHAR(16), b.PazaryeriBatchIstekleri_SonKontrol, 120)      AS son_kontrol,
                        k.EntegrasyonKanallari_Ad                                            AS kanal_adi,
                        p.Pazaryerleri_Ad                                                    AS pazaryeri_adi,
                        p.Pazaryerleri_Renk                                                  AS pazaryeri_renk
                    FROM sayfa s
                    INNER JOIN dbo.PazaryeriBatchIstekleri b ON b.PazaryeriBatchIstekleri_id = s.PazaryeriBatchIstekleri_id
                    LEFT JOIN dbo.EntegrasyonKanallari k     ON k.EntegrasyonKanallari_id = b.PazaryeriBatchIstekleri_EntegrasyonKanallari_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p       ON p.Pazaryerleri_id = b.PazaryeriBatchIstekleri_Pazaryerleri_id
                    ORDER BY {$orderSql}
                ", array_merge($params, [$start, $length]));

                $cik([
                    'draw'            => (int)($_POST['draw'] ?? 0),
                    'recordsTotal'    => (int)($toplam['adet'] ?? 0),
                    'recordsFiltered' => (int)($filtreli['adet'] ?? 0),
                    'data'            => $liste
                ]);

            // ---------------------------------------------------------
            // Batch işlem durumu seçenekleri (koda gömülmez, kayıtlardan)
            case 'batchDurumlar':
                $cik(['success' => true, 'data' => array_column($db->fetchAll("
                    SELECT DISTINCT PazaryeriBatchIstekleri_IslemDurumu AS durum
                    FROM dbo.PazaryeriBatchIstekleri
                    WHERE PazaryeriBatchIstekleri_Durum = 1
                      AND PazaryeriBatchIstekleri_EntegrasyonKanallari_id IN ({$tumKanalIn})
                    ORDER BY PazaryeriBatchIstekleri_IslemDurumu
                "), 'durum')]);

            // ---------------------------------------------------------
            // Bekleyen batch'in sonucunu pazaryerinden sorgula
            case 'batchSorgula':
                if (empty($pagePermissions['can_edit'])) {
                    $cik(['success' => false, 'message' => 'Bu işlem için düzenleme yetkisi gerekir.']);
                }

                $batch = $db->fetchOne("
                    SELECT PazaryeriBatchIstekleri_BatchId AS batch_id,
                           PazaryeriBatchIstekleri_EntegrasyonKanallari_id AS kanal_id
                    FROM dbo.PazaryeriBatchIstekleri
                    WHERE PazaryeriBatchIstekleri_id = ?
                      AND PazaryeriBatchIstekleri_Durum = 1
                      AND PazaryeriBatchIstekleri_EntegrasyonKanallari_id IN ({$tumKanalIn})
                ", [(int)($_POST['id'] ?? 0)]);

                if (!$batch) {
                    $cik(['success' => false, 'message' => 'Toplu istek bulunamadı.']);
                }
                if (!PazaryeriFabrika::kanalAktifMi($batch['kanal_id'])) {
                    $cik(['success' => false, 'message' => 'Kanalın bağlı olduğu firma pasif.']);
                }

                $api = PazaryeriFabrika::olusturKanaldan($batch['kanal_id'], $user['kullanici_id']);
                if (!$api) {
                    $cik(['success' => false, 'message' => PazaryeriFabrika::sonHata()]);
                }
                if (!$api->hazirMi()) {
                    $cik(['success' => false, 'message' => $api->getHata()]);
                }

                $sonuc = $api->batchDurumGuncelle($batch['batch_id']);
                $cik([
                    'success' => !empty($sonuc['basarili']),
                    'message' => !empty($sonuc['basarili'])
                        ? ('Durum: ' . ($sonuc['durum'] ?? '-')
                           . ' · başarılı: ' . ($sonuc['basarili_adet'] ?? 0)
                           . ' · hatalı: ' . ($sonuc['hatali_adet'] ?? 0))
                        : ($sonuc['hata'] ?? 'Sorgulanamadı.')
                ]);
        }

        $cik(['success' => false, 'message' => 'Geçersiz istek.']);

    } catch (Throwable $e) {
        error_log('pazaryeri-loglar.php: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$firmalar     = PazaryeriFabrika::firmalar($kisitFirmaId);
$pazaryerleri = PazaryeriFabrika::pazaryerleri(false);
$kanallar     = pzKanalListesi($db, $kisitFirmaId);

// Toplu istek sekmesi yalnız batch yeteneği olan bir pazaryeri varsa gösterilir
$batchVar = false;
foreach ($pazaryerleri as $p) {
    if (!empty($p['yetenekler']['batch'])) { $batchVar = true; break; }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .filtre-basligi { cursor: pointer; }
        .durum-rozet { font-size: .72rem; }
        .kod-kutu {
            font-family: var(--bs-font-monospace); font-size: .74rem;
            background: var(--bs-tertiary-bg); padding: .6rem; border-radius: .25rem;
            max-height: 300px; overflow: auto; white-space: pre-wrap; word-break: break-word;
        }
        .yanit-ozet {
            font-family: var(--bs-font-monospace); font-size: .72rem; color: var(--bs-secondary-color);
            max-width: 460px; white-space: normal; display: block;
        }
        .renk-nokta { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
        .sure { font-variant-numeric: tabular-nums; }
        .sure-yavas { color: #b02a37; font-weight: 600; }
        #tblLog td, #tblBatch td { vertical-align: middle; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                            <?php if ($pageDescription): ?>
                            <small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <?php endif; ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- InfoBox: seçili firma/pazaryeri/kanal için -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-arrow-left-right"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Son 24 Saat Çağrı</span>
                                    <span class="info-box-number" id="ibCagri">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-octagon"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Son 24 Saat Hata</span>
                                    <span class="info-box-number" id="ibHata">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-speedometer2"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ortalama Süre (24 saat)</span>
                                    <span class="info-box-number" id="ibSure">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bekleyen Toplu İstek</span>
                                    <span class="info-box-number" id="ibBekleyen">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-primary card-outline card-outline-tabs">
                        <div class="card-header p-0 border-bottom-0">
                            <ul class="nav nav-tabs" id="logSekme" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" href="#" data-sekme="log" role="tab">
                                        <i class="bi bi-journal-text me-1"></i> API Çağrıları
                                    </a>
                                </li>
                                <?php if ($batchVar): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="#" data-sekme="batch" role="tab">
                                        <i class="bi bi-stack me-1"></i> Toplu İstekler
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="card-body">

                            <!-- Filtre -->
                            <div class="card card-secondary card-outline mb-3">
                                <div class="card-header filtre-basligi" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                                    <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                                </div>
                                <div class="collapse show" id="filtrePanel">
                                    <div class="card-body">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-3">
                                                <label class="form-label">Firma</label>
                                                <select class="form-select filtre-select2" id="f_firma">
                                                    <option value="">Tüm firmalar</option>
                                                    <?php foreach ($firmalar as $f): ?>
                                                    <option value="<?= (int)$f['firma_id'] ?>"><?= htmlspecialchars($f['firma_unvan'] ?: $f['firma_adi']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Pazaryeri</label>
                                                <select class="form-select filtre-select2" id="f_pazaryeri">
                                                    <option value="">Tümü</option>
                                                    <?php foreach ($pazaryerleri as $p): ?>
                                                    <option value="<?= (int)$p['Pazaryerleri_id'] ?>"><?= htmlspecialchars($p['Pazaryerleri_Ad']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Kanal</label>
                                                <select class="form-select filtre-select2" id="f_kanal">
                                                    <option value="">Tüm kanallar</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Başlangıç</label>
                                                <input type="date" class="form-control" id="f_bas">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Bitiş</label>
                                                <input type="date" class="form-control" id="f_bit">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="f_ara" placeholder="İşlem, adres, hata veya yanıt içinde...">
                                            </div>
                                            <div class="col-md-2 sekme-log">
                                                <label class="form-label">Sonuç</label>
                                                <select class="form-select filtre-select2" id="f_sonuc">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Başarılı</option>
                                                    <option value="0">Hatalı</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2 sekme-batch d-none">
                                                <label class="form-label">İşlem Durumu</label>
                                                <select class="form-select filtre-select2" id="f_batch_durum">
                                                    <option value="">Tümü</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <button class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                                <button class="btn btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- API çağrıları -->
                            <div class="sekme-log">
                                <table class="table table-bordered table-striped table-hover table-sm align-middle w-100" id="tblLog">
                                    <thead>
                                        <tr>
                                            <th style="width:28px"></th>
                                            <th>Tarih</th>
                                            <th>Pazaryeri / Kanal</th>
                                            <th>İşlem</th>
                                            <th class="text-center">HTTP</th>
                                            <th class="text-end">Süre</th>
                                            <th class="text-center">Sonuç</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>

                            <?php if ($batchVar): ?>
                            <!-- Toplu istekler -->
                            <div class="sekme-batch d-none">
                                <table class="table table-bordered table-striped table-hover table-sm align-middle w-100" id="tblBatch">
                                    <thead>
                                        <tr>
                                            <th>Gönderim</th>
                                            <th>Pazaryeri / Kanal</th>
                                            <th>Takip No</th>
                                            <th>Tip</th>
                                            <th class="text-center">Kalem</th>
                                            <th>Durum</th>
                                            <th class="text-end">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Log detay modal -->
    <div class="modal fade" id="logModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-text"></i> Log Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="logDetayIcerik"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {

        const permissions = <?= json_encode($pagePermissions) ?>;
        const tumKanallar = <?= json_encode($kanallar, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
        const BATCH_VAR   = <?= $batchVar ? 'true' : 'false' ?>;

        const kacis = (s) => $('<div>').text(s ?? '').html();
        const tarihTr = (s) => s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' + s.slice(0, 4) + (s.length > 10 ? ' ' + s.slice(11) : '') : '-';
        const kanalHucre = (r) => '<span class="renk-nokta" style="background:' + kacis(r.pazaryeri_renk || '#adb5bd') + '"></span>'
            + kacis(r.pazaryeri_adi || '-') + '<br><small class="text-muted">' + kacis(r.kanal_adi || '')
            + (r.firma_adi ? ' · ' + kacis(r.firma_adi) : '') + '</small>';

        let sekme = 'log';
        let batchTablo = null;

        $('.filtre-select2').select2({
            theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 0,
            language: { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' }
        });

        // Kanal seçici firma ve pazaryeri seçimine göre daralır
        function kanallariDoldur() {
            const firmaId = $('#f_firma').val();
            const pzId    = $('#f_pazaryeri').val();
            const secili  = $('#f_kanal').val();
            const liste = tumKanallar.filter(k =>
                (!firmaId || String(k.EntegrasyonKanallari_Firmalar_id) === String(firmaId)) &&
                (!pzId || String(k.Pazaryerleri_id) === String(pzId)));

            $('#f_kanal').html('<option value="">Tüm kanallar</option>' + liste.map(k =>
                '<option value="' + k.EntegrasyonKanallari_id + '">'
                + kacis(k.Pazaryerleri_Ad + ' — ' + k.EntegrasyonKanallari_Ad
                        + (Number(k.EntegrasyonKanallari_Durum) === 1 ? '' : ' [pasif]')) + '</option>').join(''));
            $('#f_kanal').val(liste.some(k => String(k.EntegrasyonKanallari_id) === String(secili)) ? secili : '')
                         .trigger('change.select2');
        }

        function ortakFiltre(d) {
            d.f_firma     = $('#f_firma').val();
            d.f_pazaryeri = $('#f_pazaryeri').val();
            d.f_kanal     = $('#f_kanal').val();
            d.f_bas       = $('#f_bas').val();
            d.f_bit       = $('#f_bit').val();
            d.f_ara       = $('#f_ara').val();
            return d;
        }

        function loadStats() {
            $.post('', ortakFiltre({ action: 'stats' }), function (res) {
                if (!res.success) return;
                $('#ibBekleyen').text(res.data.bekleyenBatch ?? 0);
                $('#ibCagri').text(res.data.gunlukCagri ?? 0);
                $('#ibHata').text(res.data.gunlukHata ?? 0);
                $('#ibSure').text(res.data.ortSure !== null ? res.data.ortSure + ' ms' : '-');
            }, 'json');
        }

        // ---------------------------------------------------------------
        // API çağrıları
        // ---------------------------------------------------------------
        const logTablo = $('#tblLog').DataTable({
            serverSide: true,
            processing: true,
            scrollX: true,
            dom: 'lrtip',
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action  = 'logList';
                    d.f_sonuc = $('#f_sonuc').val();
                    return ortakFiltre(d);
                },
                error: function () { showToast('Loglar yüklenemedi', 'error'); }
            },
            columns: [
                {
                    data: 'id', orderable: false, className: 'text-center',
                    render: (d) => '<button class="btn btn-sm btn-link p-0 btn-log-detay" data-id="' + d + '" title="Detay">'
                                 + '<i class="bi bi-eye"></i></button>'
                },
                { data: 'tarih', render: (d) => tarihTr(d) },
                { data: 'kanal_id', render: (d, t, r) => kanalHucre(r) },
                {
                    data: 'islem',
                    render: (d, t, r) => {
                        let html = kacis(d || '-');
                        if (r.ozet) {
                            html += '<span class="yanit-ozet">' + kacis(r.ozet) + '</span>';
                        }
                        return html;
                    }
                },
                {
                    data: 'http_kod', className: 'text-center',
                    render: (d) => {
                        if (!d) return '<span class="text-muted">-</span>';
                        const sinif = d >= 200 && d < 300 ? 'text-bg-success'
                                    : (d >= 400 && d < 500 ? 'text-bg-warning' : 'text-bg-danger');
                        return '<span class="badge ' + sinif + '">' + d + '</span>';
                    }
                },
                {
                    data: 'sure_ms', className: 'text-end sure',
                    render: (d) => d
                        ? (d > 3000 ? '<span class="sure-yavas">' + d + ' ms</span>' : d + ' ms')
                        : '-'
                },
                {
                    data: 'basarili', className: 'text-center',
                    render: (d) => Number(d) === 1
                        ? '<i class="bi bi-check-circle-fill text-success" title="Başarılı"></i>'
                        : '<i class="bi bi-x-circle-fill text-danger" title="Hatalı"></i>'
                }
            ],
            order: [[1, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, 200], [10, 25, 50, 100, 200]],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
        });

        // ---------------------------------------------------------------
        // Toplu istekler
        // ---------------------------------------------------------------
        // İşlem durumu metni PazaryeriApi::batchSonucIsle() tarafından yazılır;
        // burada yalnız rozet rengi eşlenir, bilinmeyen değer ham gösterilir.
        const BATCH_RENK = { beklemede: 'text-bg-warning', tamamlandi: 'text-bg-success', hata: 'text-bg-danger' };

        function batchTablosunuKur() {
            if (!BATCH_VAR || batchTablo) return;
            batchTablo = $('#tblBatch').DataTable({
                serverSide: true,
                processing: true,
                scrollX: true,
                dom: 'lrtip',
                ajax: {
                    url: '', type: 'POST',
                    data: function (d) {
                        d.action        = 'batchList';
                        d.f_batch_durum = $('#f_batch_durum').val();
                        return ortakFiltre(d);
                    },
                    error: function () { showToast('Toplu istekler yüklenemedi', 'error'); }
                },
                columns: [
                    { data: 'tarih', render: (d) => tarihTr(d) },
                    { data: 'kanal_id', render: (d, t, r) => kanalHucre(r) },
                    { data: 'batch_id', render: (d) => '<code style="font-size:.75rem">' + kacis(d) + '</code>' },
                    { data: 'tip', render: (d) => kacis(d || '-') },
                    {
                        data: 'item_sayisi', className: 'text-center',
                        render: (d, t, r) => {
                            let html = '<span class="fw-semibold">' + (d ?? 0) + '</span>';
                            const ok = r.basarili_sayi ?? 0;
                            const no = r.hatali_sayi ?? 0;
                            if (ok || no) {
                                html += '<br><small><span class="text-success">' + ok + '</span>'
                                      + ' / <span class="text-danger">' + no + '</span></small>';
                            }
                            return html;
                        }
                    },
                    {
                        data: 'islem_durumu',
                        render: (d, t, r) => {
                            let html = '<span class="badge ' + (BATCH_RENK[d] || 'text-bg-light border') + ' durum-rozet">' + kacis(d || '-') + '</span>';
                            if (r.son_kontrol) {
                                html += '<br><small class="text-muted">kontrol: ' + kacis(tarihTr(r.son_kontrol)) + '</small>';
                            }
                            return html;
                        }
                    },
                    {
                        data: 'id', className: 'text-end', orderable: false,
                        render: (d, t, r) => r.islem_durumu === 'beklemede' && permissions.can_edit
                            ? '<button class="btn btn-sm btn-outline-primary btn-sorgula" data-id="' + d + '">'
                              + '<i class="bi bi-arrow-clockwise me-1"></i> Sonucu Sorgula</button>'
                            : '<span class="text-muted small">-</span>'
                    }
                ],
                order: [[0, 'desc']],
                pageLength: 25,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
            });

            $.post('', { action: 'batchDurumlar' }, function (res) {
                if (!res.success) return;
                (res.data || []).forEach(d => $('#f_batch_durum').append(new Option(d, d)));
            }, 'json');
        }

        $('#tblBatch').on('click', '.btn-sorgula', function () {
            const btn = $(this).prop('disabled', true);
            $.post('', { action: 'batchSorgula', id: btn.data('id') }, function (res) {
                showToast(res.message, res.success ? 'success' : 'error');
                if (res.success) { batchTablo.ajax.reload(null, false); loadStats(); }
            }, 'json')
            .fail(() => showToast('Sunucu hatası', 'error'))
            .always(() => btn.prop('disabled', false));
        });

        // ---------------------------------------------------------------
        // Sekmeler ve filtre
        // ---------------------------------------------------------------
        function aktifTablo() { return sekme === 'batch' ? batchTablo : logTablo; }

        function yenile() {
            aktifTablo().ajax.reload();
            loadStats();
        }

        $('#logSekme .nav-link').on('click', function (e) {
            e.preventDefault();
            $('#logSekme .nav-link').removeClass('active');
            $(this).addClass('active');
            sekme = $(this).data('sekme');

            $('.sekme-log').toggleClass('d-none', sekme !== 'log');
            $('.sekme-batch').toggleClass('d-none', sekme !== 'batch');

            if (sekme === 'batch' && !batchTablo) {
                batchTablosunuKur();
            } else {
                aktifTablo().columns.adjust().ajax.reload(null, false);
            }
        });

        $('#f_firma, #f_pazaryeri').on('change', kanallariDoldur);
        $('#btnFiltrele').on('click', yenile);
        $('#f_ara').on('keypress', e => { if (e.which === 13) yenile(); });

        $('#btnTemizle').on('click', function () {
            $('#f_bas, #f_bit, #f_ara').val('');
            $('#f_firma, #f_pazaryeri, #f_sonuc, #f_batch_durum').val('').trigger('change.select2');
            kanallariDoldur();
            yenile();
        });

        // ---------------------------------------------------------------
        // Log detayı
        // ---------------------------------------------------------------
        const logModal = new bootstrap.Modal('#logModal');

        function guzelJson(s) {
            if (!s) return '';
            try { return JSON.stringify(JSON.parse(s), null, 2); } catch (e) { return s; }
        }

        $('#tblLog').on('click', '.btn-log-detay', function () {
            $.post('', { action: 'logDetay', id: $(this).data('id') }, function (res) {
                if (!res.success) { showToast(res.message, 'error'); return; }
                const l = res.data;
                const alan = (etiket, deger) => '<div class="col-md-3"><div class="small text-muted">' + etiket
                                             + '</div><div class="fw-semibold">' + deger + '</div></div>';

                $('#logDetayIcerik').html(
                    '<div class="row g-2 mb-3">'
                  + alan('Pazaryeri / Kanal', kacis((l.Pazaryerleri_Ad || '-') + ' · ' + (l.EntegrasyonKanallari_Ad || '-')))
                  + alan('İşlem', kacis(l.EntegrasyonLoglari_IslemTipi))
                  + alan('Tarih', kacis(tarihTr(l.tarih)))
                  + alan('Sonuç', Number(l.basarili) === 1
                        ? '<span class="badge text-bg-success">Başarılı</span>'
                        : '<span class="badge text-bg-danger">Hatalı</span>')
                  + alan('HTTP', kacis(l.EntegrasyonLoglari_HttpKod || '-'))
                  + alan('Süre', kacis((l.EntegrasyonLoglari_SureMs || 0) + ' ms'))
                  + alan('Kullanıcı', kacis(l.kullanici || '-'))
                  + alan('IP', kacis(l.EntegrasyonLoglari_IP || '-'))
                  + '</div>'
                  + (l.EntegrasyonLoglari_Hedef
                        ? '<div class="mb-1 small text-muted">Adres</div><div class="kod-kutu mb-3">' + kacis(l.EntegrasyonLoglari_Hedef) + '</div>'
                        : '')
                  + (l.EntegrasyonLoglari_HataMesaji
                        ? '<div class="alert alert-danger py-2 small">' + kacis(l.EntegrasyonLoglari_HataMesaji) + '</div>'
                        : '')
                  + '<div class="mb-1 small text-muted">İstek</div>'
                  + '<div class="kod-kutu mb-3">' + kacis(guzelJson(l.EntegrasyonLoglari_Istek)) + '</div>'
                  + '<div class="mb-1 small text-muted">Yanıt</div>'
                  + '<div class="kod-kutu">' + kacis(guzelJson(l.EntegrasyonLoglari_Cevap)) + '</div>'
                  + '<div class="form-text mt-2">Kimlik bilgileri kayıt sırasında ve görüntülenirken maskelenir.</div>'
                );
                logModal.show();
            }, 'json');
        });

        // ---------------------------------------------------------------
        kanallariDoldur();
        // InfoBox sayımları tablo yüklendikten sonra
        logTablo.one('draw', loadStats);
    });
    </script>
</body>
</html>
