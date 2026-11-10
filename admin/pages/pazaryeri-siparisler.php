<?php
/**
 * Admin Panel - Pazaryeri Siparişleri
 *
 * Pazaryeri kanallarından (firma hesapları) çekilen siparişleri listeler.
 * Sipariş birimi pazaryerine göre "sipariş" veya "paket" olabilir; ortak katman
 * ikisini de PazaryeriSiparisleri tablosunda tutar.
 *
 * Kapsam: sipariş → kanal (EntegrasyonKanallari) → EntegrasyonKanallari_Firmalar_id → Firmalar.
 * Kullanıcının firma kısıtı (PageAuth::firmaKisiti) her sorguya kanal id listesi olarak uygulanır.
 *
 * Satır aksiyonları: kalem detayı, kargo etiketi (ZPL), paket durumu (Picking/Invoiced),
 * fatura önizleme / kesim (FaturaKesim: Örnek Holding e-Fatura / e-Arşiv; taslak veya kesin).
 * Hangi aksiyonun görüneceği pazaryerinin yetenek bayraklarına göre belirlenir.
 *
 * Hareket tablosu → DataTables SERVER-SIDE, iki aşamalı sorgu.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PazaryeriFabrika.php';
require_once __DIR__ . '/../includes/OrnekHoldingApi.php';
require_once __DIR__ . '/../includes/FaturaKesim.php';
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

// Firma kısıtı: null → kısıt yok, >0 → yalnız o firma, 0 → hiç kayıt yok
$kisitFirmaId = PageAuth::firmaKisiti($pagePermissions, $user['kullanici_id']);

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri Siparişleri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

/** Sıralama kolonu istemciden gelen değerle doğrudan sorguya girmez */
const SIRALAMA_KOLONLARI = [
    2 => 's.PazaryeriSiparisleri_SiparisNo',
    3 => 's.PazaryeriSiparisleri_MusteriAdi',
    4 => 's.PazaryeriSiparisleri_ToplamTutar',
    5 => 's.PazaryeriSiparisleri_DurumKod',
    6 => 's.PazaryeriSiparisleri_KargoFirmasi',
    7 => 's.PazaryeriSiparisleri_SiparisTarihi',
];

/**
 * Kapsamdaki kanal id'leri (pazaryerine bağlı kanallar).
 * Pasif firmanın kanalları hiçbir kapsamda yer almaz; firmasız kanal görünür.
 * Kanalın kendisi pasifleştirilse bile geçmiş siparişleri görünmeye devam eder.
 * Sayım sorguları JOIN'siz kalsın diye liste önce çözülüp IN ile verilir.
 *
 * @return int[]
 */
function kapsamKanallari($firmaId, $pazaryeriId, $kanalId, $kisitFirmaId)
{
    $sql = "
        SELECT k.EntegrasyonKanallari_id AS id
        FROM dbo.EntegrasyonKanallari k
        INNER JOIN dbo.Entegrasyonlar e
            ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
        INNER JOIN dbo.tanim_Pazaryerleri p
            ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
        LEFT JOIN dbo.Firmalar f
            ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        WHERE (k.EntegrasyonKanallari_Firmalar_id IS NULL OR f.firma_durum = 1)
    ";
    $params = [];

    if ($firmaId > 0)     { $sql .= " AND k.EntegrasyonKanallari_Firmalar_id = ? "; $params[] = $firmaId; }
    if ($pazaryeriId > 0) { $sql .= " AND p.Pazaryerleri_id = ? ";                  $params[] = $pazaryeriId; }
    if ($kanalId > 0)     { $sql .= " AND k.EntegrasyonKanallari_id = ? ";           $params[] = $kanalId; }

    [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.EntegrasyonKanallari_Firmalar_id');
    $sql   .= $kisitSql;
    $params = array_merge($params, $kisitParams);

    return array_map('intval', array_column(Database::getInstance()->fetchAll($sql, $params), 'id'));
}

/** Kanal id listesini IN koşuluna çevirir (değerler int'e çevrilmiş olarak gelir) */
function kanalInSql(array $idler, $kolon)
{
    return $idler ? " $kolon IN (" . implode(',', array_map('intval', $idler)) . ") " : " 1 = 0 ";
}

/** Y-m-d biçiminde değilse boş döner; geçersiz tarih SQL'e gitmez */
function tarihGecerli($t)
{
    $t = trim((string)$t);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : '';
}

/**
 * Liste ve istatistik için ortak WHERE koşulu (alias: s).
 * İstatistiklerde durum filtresi uygulanmaz; kutular durum kırılımını gösterir.
 */
function siparisKosulu(array $girdi, $kisitFirmaId, $durumDahil = true)
{
    $idler = kapsamKanallari(
        (int)($girdi['firma_id'] ?? 0),
        (int)($girdi['pazaryeri_id'] ?? 0),
        (int)($girdi['kanal'] ?? 0),
        $kisitFirmaId
    );

    $kosul  = " WHERE " . kanalInSql($idler, 's.PazaryeriSiparisleri_EntegrasyonKanallari_id')
            . " AND s.PazaryeriSiparisleri_Durum = 1 ";
    $params = [];

    $ara = trim($girdi['ara'] ?? '');
    if ($ara !== '') {
        $kosul .= " AND (s.PazaryeriSiparisleri_SiparisNo LIKE ?
                      OR s.PazaryeriSiparisleri_PaketNo LIKE ?
                      OR s.PazaryeriSiparisleri_MusteriAdi LIKE ?
                      OR s.PazaryeriSiparisleri_KargoTakipNo LIKE ?) ";
        $like = '%' . $ara . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $durum = trim($girdi['durum'] ?? '');
    if ($durumDahil && $durum !== '') {
        $kosul .= " AND s.PazaryeriSiparisleri_DurumKod = ? ";
        $params[] = $durum;
    }
    // Fatura durumu (Faturalar_KesimDurumu; senkronla bağlanmış eski faturalar KesimDurumu boş, Durum = 1)
    $fatura = trim($girdi['fatura'] ?? '');
    $faturaVar = "EXISTS (SELECT 1 FROM dbo.Faturalar fa
                          WHERE fa.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id AND %s)";
    switch ($fatura) {
        case 'yok':
            $kosul .= " AND (s.PazaryeriSiparisleri_Faturalar_id IS NULL OR NOT "
                    . sprintf($faturaVar, "fa.Faturalar_Durum = 1 AND fa.Faturalar_IptalDurumu = 0") . ") ";
            break;
        case 'taslak':
            $kosul .= " AND " . sprintf($faturaVar, "fa.Faturalar_KesimDurumu = 'TASLAK'") . " ";
            break;
        case 'kesildi':
            $kosul .= " AND " . sprintf($faturaVar, "fa.Faturalar_Durum = 1 AND fa.Faturalar_IptalDurumu = 0
                          AND ISNULL(fa.Faturalar_KesimDurumu, 'GONDERILDI') = 'GONDERILDI'") . " ";
            break;
        case 'hata':
            $kosul .= " AND " . sprintf($faturaVar, "fa.Faturalar_KesimDurumu IN ('HATA', 'GONDERILIYOR')") . " ";
            break;
    }

    if (($bas = tarihGecerli($girdi['bas_tarih'] ?? '')) !== '') {
        $kosul .= " AND s.PazaryeriSiparisleri_SiparisTarihi >= ? ";
        $params[] = $bas;
    }
    if (($bit = tarihGecerli($girdi['bit_tarih'] ?? '')) !== '') {
        $kosul .= " AND s.PazaryeriSiparisleri_SiparisTarihi < DATEADD(DAY, 1, CAST(? AS DATE)) ";
        $params[] = $bit;
    }

    return [$kosul, $params];
}

/** sqlsrv DateTime nesnelerini metne çevirir (json_encode için) */
function tarihleriMetneCevir(array $satir)
{
    foreach ($satir as $k => $v) {
        if ($v instanceof DateTimeInterface) {
            $satir[$k] = $v->format('Y-m-d H:i');
        }
    }
    return $satir;
}

/** Siparişin kanalı kullanıcının kapsamında mı (firma kısıtı + pasif firma) */
function siparisKapsamda($siparis, $kisitFirmaId)
{
    $kanalId = (int)($siparis['PazaryeriSiparisleri_EntegrasyonKanallari_id'] ?? 0);
    return $kanalId > 0 && in_array($kanalId, kapsamKanallari(0, 0, $kanalId, $kisitFirmaId), true);
}

// =====================================================================
// AJAX
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $jsonBayrak = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ---------------------------------------------------------
            // InfoBox: seçili filtre / tarih aralığında özet (durum filtresi hariç)
            case 'stats':
                [$kosul, $params] = siparisKosulu($_POST, $kisitFirmaId, false);
                $s = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN ISNULL(s.PazaryeriSiparisleri_DurumKod, '') NOT IN ('iptal', 'iade')
                                 THEN ISNULL(s.PazaryeriSiparisleri_ToplamTutar, 0) ELSE 0 END) AS ciro,
                        SUM(CASE WHEN s.PazaryeriSiparisleri_DurumKod IN ('iptal', 'iade') THEN 1 ELSE 0 END) AS iptal_iade,
                        SUM(CASE WHEN s.PazaryeriSiparisleri_DurumKod IN ('yeni', 'hazirlaniyor') THEN 1 ELSE 0 END) AS bekleyen,
                        SUM(CASE WHEN ISNULL(s.PazaryeriSiparisleri_DurumKod, '') NOT IN ('iptal', 'iade')
                                  AND fa.Faturalar_id IS NULL THEN 1 ELSE 0 END) AS faturasiz
                    FROM dbo.PazaryeriSiparisleri s
                    LEFT JOIN dbo.Faturalar fa
                           ON fa.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id
                          AND fa.Faturalar_Durum = 1 AND fa.Faturalar_IptalDurumu = 0
                    $kosul
                ", $params);

                echo json_encode(['success' => true, 'data' => [
                    'toplam'     => (int)($s['toplam'] ?? 0),
                    'ciro'       => (float)($s['ciro'] ?? 0),
                    'iptal_iade' => (int)($s['iptal_iade'] ?? 0),
                    'bekleyen'   => (int)($s['bekleyen'] ?? 0),
                    'faturasiz'  => (int)($s['faturasiz'] ?? 0),
                ]], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'list':
                $draw = (int)($_POST['draw'] ?? 0);

                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) { $length = 25; }

                $siraIdx = (int)($_POST['order'][0]['column'] ?? 7);
                $siraKol = SIRALAMA_KOLONLARI[$siraIdx] ?? 's.PazaryeriSiparisleri_SiparisTarihi';
                $siraYon = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
                $orderSql = "$siraKol $siraYon, s.PazaryeriSiparisleri_id DESC";

                [$kosul, $params] = siparisKosulu($_POST, $kisitFirmaId);

                // Toplam: JOIN'siz, tek ana tablodan (yalnız firma kısıtı)
                $toplam = $db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.PazaryeriSiparisleri
                     WHERE " . kanalInSql(kapsamKanallari(0, 0, 0, $kisitFirmaId), 'PazaryeriSiparisleri_EntegrasyonKanallari_id') . "
                       AND PazaryeriSiparisleri_Durum = 1"
                );
                $filtreli = $db->fetchOne(
                    "SELECT COUNT(*) AS adet FROM dbo.PazaryeriSiparisleri s " . $kosul,
                    $params
                );

                // 1. aşama ucuz CTE (yalnız ID), 2. aşamada kanal/firma ve kalem sayımı yalnız bu satırlara
                $satirlar = $db->fetchAll("
                    WITH sayfa AS (
                        SELECT s.PazaryeriSiparisleri_id
                        FROM dbo.PazaryeriSiparisleri s
                        $kosul
                        ORDER BY $orderSql
                        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                    )
                    SELECT
                        s.PazaryeriSiparisleri_id,
                        s.PazaryeriSiparisleri_EntegrasyonKanallari_id,
                        s.PazaryeriSiparisleri_DisId,
                        s.PazaryeriSiparisleri_SiparisNo,
                        s.PazaryeriSiparisleri_PaketNo,
                        s.PazaryeriSiparisleri_DurumKod,
                        s.PazaryeriSiparisleri_HamDurum,
                        s.PazaryeriSiparisleri_ToplamTutar,
                        s.PazaryeriSiparisleri_ParaBirimi,
                        s.PazaryeriSiparisleri_MusteriAdi,
                        s.PazaryeriSiparisleri_Il,
                        s.PazaryeriSiparisleri_Ilce,
                        s.PazaryeriSiparisleri_KargoFirmasi,
                        s.PazaryeriSiparisleri_KargoTakipNo,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_SiparisTarihi, 120)         AS PazaryeriSiparisleri_SiparisTarihi,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_TerminTarihi, 120)          AS PazaryeriSiparisleri_TerminTarihi,
                        s.PazaryeriSiparisleri_Faturalar_id,
                        CONVERT(VARCHAR(16), s.PazaryeriSiparisleri_FaturaBildirimTarihi, 120)  AS PazaryeriSiparisleri_FaturaBildirimTarihi,
                        s.PazaryeriSiparisleri_FaturaBildirimHata,
                        k.EntegrasyonKanallari_Ad,
                        p.Pazaryerleri_Ad,
                        p.Pazaryerleri_Renk,
                        p.Pazaryerleri_Yetenekler,
                        f.firma_adi,
                        kl.KalemSayisi,
                        fa.Faturalar_FaturaNo,
                        fa.Faturalar_ETTN,
                        fa.Faturalar_Tur,
                        fa.Faturalar_KesimDurumu,
                        fa.Faturalar_DurumAciklama,
                        fa.Faturalar_Durum,
                        fa.Faturalar_IptalDurumu,
                        fa.Faturalar_GibDurum
                    FROM sayfa sf
                    INNER JOIN dbo.PazaryeriSiparisleri s
                            ON s.PazaryeriSiparisleri_id = sf.PazaryeriSiparisleri_id
                    LEFT JOIN dbo.EntegrasyonKanallari k
                            ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p
                            ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f
                            ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    OUTER APPLY (
                        SELECT COUNT(*) AS KalemSayisi
                        FROM dbo.PazaryeriSiparisKalemleri kk
                        WHERE kk.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = s.PazaryeriSiparisleri_id
                          AND kk.PazaryeriSiparisKalemleri_Durum = 1
                    ) kl
                    LEFT JOIN dbo.Faturalar fa
                            ON fa.Faturalar_id = s.PazaryeriSiparisleri_Faturalar_id
                    ORDER BY $orderSql
                ", array_merge($params, [$start, $length]));

                // Yetenekler JSON'u satır aksiyonlarını belirler (seçili filtreden değil siparişin kanalından)
                foreach ($satirlar as &$r) {
                    $r['yetenekler'] = json_decode($r['Pazaryerleri_Yetenekler'] ?? '{}', true) ?: new stdClass();
                    unset($r['Pazaryerleri_Yetenekler']);
                    $kesin = $r['Faturalar_Durum'] !== null && FaturaKesim::kesinlesmisMi($r);
                    $r['fatura_kesinlesmis'] = $kesin;
                    $r['fatura_kesilebilir'] = !$kesin && $r['PazaryeriSiparisleri_DurumKod'] !== 'iptal';
                }
                unset($r);

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => (int)($toplam['adet'] ?? 0),
                    'recordsFiltered' => (int)($filtreli['adet'] ?? 0),
                    'data'            => $satirlar
                ], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'detay':
                $id = (int)($_POST['id'] ?? 0);
                $siparis = $db->fetchOne("
                    SELECT s.*, k.EntegrasyonKanallari_Ad, p.Pazaryerleri_Ad, f.firma_adi, f.firma_unvan
                    FROM dbo.PazaryeriSiparisleri s
                    LEFT JOIN dbo.EntegrasyonKanallari k ON k.EntegrasyonKanallari_id = s.PazaryeriSiparisleri_EntegrasyonKanallari_id
                    LEFT JOIN dbo.tanim_Pazaryerleri p   ON p.Pazaryerleri_id = s.PazaryeriSiparisleri_Pazaryerleri_id
                    LEFT JOIN dbo.Firmalar f             ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    WHERE s.PazaryeriSiparisleri_id = ? AND s.PazaryeriSiparisleri_Durum = 1
                ", [$id]);

                if (!$siparis || !siparisKapsamda($siparis, $kisitFirmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı.'], $jsonBayrak);
                    exit;
                }
                // Ham API yanıtı ekranda kullanılmaz; yükü büyütmesin
                unset($siparis['PazaryeriSiparisleri_HamVeri']);

                // Ürün eşleştirmesi varsa portal ürün kartının adı, yoksa kalemin kendi bilgisi
                $kalemler = $db->fetchAll("
                    SELECT
                        kl.PazaryeriSiparisKalemleri_id,
                        kl.PazaryeriSiparisKalemleri_UrunAdi,
                        kl.PazaryeriSiparisKalemleri_Barkod,
                        kl.PazaryeriSiparisKalemleri_StokKodu,
                        kl.PazaryeriSiparisKalemleri_Miktar,
                        kl.PazaryeriSiparisKalemleri_BirimFiyat,
                        kl.PazaryeriSiparisKalemleri_ToplamTutar,
                        kl.PazaryeriSiparisKalemleri_IndirimTutar,
                        kl.PazaryeriSiparisKalemleri_KdvOran,
                        kl.PazaryeriSiparisKalemleri_KomisyonOran,
                        kl.PazaryeriSiparisKalemleri_KomisyonTutar,
                        kl.PazaryeriSiparisKalemleri_HamDurum,
                        kl.PazaryeriSiparisKalemleri_Urun_Hizmet_id,
                        u.urun_hizmet_kodu,
                        u.urun_hizmet_adi
                    FROM dbo.PazaryeriSiparisKalemleri kl
                    LEFT JOIN dbo.Urun_Hizmet u ON u.urun_hizmet_id = kl.PazaryeriSiparisKalemleri_Urun_Hizmet_id
                    WHERE kl.PazaryeriSiparisKalemleri_PazaryeriSiparisleri_id = ?
                      AND kl.PazaryeriSiparisKalemleri_Durum = 1
                    ORDER BY kl.PazaryeriSiparisKalemleri_id
                ", [$id]);

                echo json_encode(['success' => true, 'data' => [
                    'siparis'  => tarihleriMetneCevir($siparis),
                    'kalemler' => array_map('tarihleriMetneCevir', $kalemler)
                ]], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Filtre: tabloda geçen durum kodları (etiketler arayüzde çözülür)
            case 'durumlar':
                $idler = kapsamKanallari(0, 0, 0, $kisitFirmaId);
                $liste = $db->fetchAll("
                    SELECT DISTINCT PazaryeriSiparisleri_DurumKod AS kod
                    FROM dbo.PazaryeriSiparisleri
                    WHERE " . kanalInSql($idler, 'PazaryeriSiparisleri_EntegrasyonKanallari_id') . "
                      AND PazaryeriSiparisleri_Durum = 1
                      AND PazaryeriSiparisleri_DurumKod IS NOT NULL
                    ORDER BY PazaryeriSiparisleri_DurumKod
                ");
                echo json_encode(['success' => true, 'data' => array_column($liste, 'kod')], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Siparişleri pazaryerinden çek
            case 'senkron':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş çekmek için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }
                set_time_limit(300);

                $gun = (int)($_POST['gun'] ?? 14);
                if ($gun <= 0 || $gun > 365) { $gun = 14; }

                $firmaId     = (int)($_POST['firma_id'] ?? 0);
                $pazaryeriId = (int)($_POST['pazaryeri_id'] ?? 0);
                $kanalId     = (int)($_POST['kanal'] ?? 0);

                // Tek kanal ya da "Tümü": sipariş yeteneği olan, sınıfı yazılmış aktif
                // kanalların hepsi. Bir kanalın hatası diğerlerini durdurmaz.
                $hedefler = array_values(array_filter(
                    PazaryeriFabrika::kanallar(null, $firmaId > 0 ? $firmaId : null, $kisitFirmaId),
                    fn($k) => ($kanalId <= 0 || (int)$k['EntegrasyonKanallari_id'] === $kanalId)
                           && ($pazaryeriId <= 0 || (int)$k['Pazaryerleri_id'] === $pazaryeriId)
                           && !empty($k['sinifVar'])
                           && (int)($k['Pazaryerleri_Durum'] ?? 0) === 1
                           && !empty($k['yetenekler']['siparis'])
                ));

                if (empty($hedefler)) {
                    echo json_encode(['success' => false,
                        'message' => 'Seçimde sipariş çekmeyi destekleyen kanal yok.'], $jsonBayrak);
                    exit;
                }

                $sonuclar = [];
                $toplam   = 0;
                $basarili = 0;

                foreach ($hedefler as $k) {
                    $etiket = $k['Pazaryerleri_Ad'] . ' — ' . $k['EntegrasyonKanallari_Ad'];
                    try {
                        $api = PazaryeriFabrika::olusturKanaldan((int)$k['EntegrasyonKanallari_id'], $user['kullanici_id']);
                        if (!$api || !$api->hazirMi()) {
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => false,
                                'mesaj' => $api ? $api->getHata() : PazaryeriFabrika::sonHata()];
                            continue;
                        }
                        $sonuc = $api->siparisleriSenkronla($gun);
                        if (!empty($sonuc['basarili'])) {
                            $basarili++;
                            $toplam += (int)($sonuc['adet'] ?? 0);
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => true,
                                'mesaj' => (int)($sonuc['adet'] ?? 0) . ' sipariş'
                                    . (isset($sonuc['durumGuncellenen']) ? ', ' . (int)$sonuc['durumGuncellenen'] . ' durum güncellendi' : '')
                                    . (!empty($sonuc['durumHata']) ? ' (durum hatası: ' . $sonuc['durumHata'] . ')' : '')];
                        } else {
                            $sonuclar[] = ['kanal' => $etiket, 'basarili' => false,
                                'mesaj' => $sonuc['hata'] ?? 'Sipariş çekilemedi.'];
                        }
                    } catch (Throwable $e) {
                        error_log('pazaryeri-siparisler senkron: ' . $e->getMessage());
                        $sonuclar[] = ['kanal' => $etiket, 'basarili' => false, 'mesaj' => 'İşlem hatası'];
                    }
                }

                echo json_encode([
                    'success'  => $basarili > 0,
                    'message'  => $toplam . ' sipariş işlendi (' . $basarili . '/' . count($hedefler) . ' kanal).',
                    'kanallar' => $sonuclar
                ], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Paket durumu ve kargo etiketi: kanal, seçili filtreden değil
            // SİPARİŞİN KENDİSİNDEN alınır; "Tümü" görünümünde işlem doğru
            // pazaryeri hesabına gitmelidir.
            case 'paketDurum':
            case 'kargoEtiketi':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }

                $id = (int)($_POST['id'] ?? 0);
                $siparis = $db->fetchOne("
                    SELECT PazaryeriSiparisleri_PaketNo, PazaryeriSiparisleri_DisId, PazaryeriSiparisleri_EntegrasyonKanallari_id
                    FROM dbo.PazaryeriSiparisleri
                    WHERE PazaryeriSiparisleri_id = ? AND PazaryeriSiparisleri_Durum = 1
                ", [$id]);

                if (!$siparis || !siparisKapsamda($siparis, $kisitFirmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı.'], $jsonBayrak);
                    exit;
                }

                $api = PazaryeriFabrika::olusturKanaldan((int)$siparis['PazaryeriSiparisleri_EntegrasyonKanallari_id'], $user['kullanici_id']);
                if (!$api || !$api->hazirMi()) {
                    echo json_encode(['success' => false,
                        'message' => $api ? $api->getHata() : PazaryeriFabrika::sonHata()], $jsonBayrak);
                    exit;
                }

                $paketId = $siparis['PazaryeriSiparisleri_PaketNo'] ?: $siparis['PazaryeriSiparisleri_DisId'];

                if ($action === 'paketDurum') {
                    $durum = $_POST['yeni_durum'] ?? '';
                    // Pazaryeri API komutları (Trendyol: Picking/Invoiced, n11: yalnız Picking)
                    if (!in_array($durum, ['Picking', 'Invoiced'], true)) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz durum.'], $jsonBayrak);
                        exit;
                    }
                    if (!$api->yetenek('paketDurum')) {
                        echo json_encode(['success' => false, 'message' => $api->getPazaryeriAdi() . ' paket durumu güncellemeyi desteklemiyor.'], $jsonBayrak);
                        exit;
                    }
                    $sonuc = $api->paketDurumGuncelle($paketId, $durum);
                    echo json_encode([
                        'success' => !empty($sonuc['basarili']),
                        'message' => ($sonuc['mesaj'] ?? '') ?: ($sonuc['hata'] ?? '')
                    ], $jsonBayrak);
                    exit;
                }

                if (!$api->yetenek('kargoEtiketi')) {
                    echo json_encode(['success' => false, 'message' => $api->getPazaryeriAdi() . ' kargo etiketini desteklemiyor.'], $jsonBayrak);
                    exit;
                }
                // Önce etiket talebi oluşturulur, sonra içeriği çekilir
                $api->kargoEtiketiOlustur($paketId);
                $etiket = $api->kargoEtiketiGetir($paketId);

                echo json_encode([
                    'success' => !empty($etiket['basarili']),
                    'message' => $etiket['hata'] ?? '',
                    'zpl'     => $etiket['zpl'] ?? null
                ], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            // Fatura: önizleme (kayıt yazmaz; mükellef sorgusu + numara tahmini) ve kesim.
            // Belge her istekte siparişten yeniden üretilir; istemciden tutar/kalem alınmaz.
            case 'faturaOnizle':
            case 'faturaKes':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Fatura işlemi için düzenleme yetkisi gerekir.'], $jsonBayrak);
                    exit;
                }
                set_time_limit(180);

                $id = (int)($_POST['id'] ?? 0);
                $siparis = $db->fetchOne("
                    SELECT PazaryeriSiparisleri_id, PazaryeriSiparisleri_EntegrasyonKanallari_id
                    FROM dbo.PazaryeriSiparisleri
                    WHERE PazaryeriSiparisleri_id = ? AND PazaryeriSiparisleri_Durum = 1
                ", [$id]);
                if (!$siparis || !siparisKapsamda($siparis, $kisitFirmaId)) {
                    echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı.'], $jsonBayrak);
                    exit;
                }

                $fk = new FaturaKesim((int)$user['kullanici_id'], $db);
                $b  = $fk->pazaryeriBelge($id);
                if (!$b['success']) {
                    echo json_encode(['success' => false, 'message' => $b['message']], $jsonBayrak);
                    exit;
                }
                $belge   = $b['belge'];
                $firmaId = (int)$belge['firma']['firma_id'];

                // Örnek Holding kanalı yalnız siparişin firmasının kanallarından seçilebilir
                $tbKanallar = $fk->ornekholdingKanallari($firmaId);
                $tbKanalId  = (int)($_POST['tb_kanal'] ?? 0);
                if (!in_array($tbKanalId, array_column($tbKanallar, 'kanal_id'), true)) {
                    $tbKanalId = (int)$fk->varsayilanKanal($firmaId);
                }
                if ($tbKanalId <= 0) {
                    echo json_encode(['success' => false,
                        'message' => 'Siparişin firmasına (' . $belge['firma']['unvan'] . ') bağlı aktif Örnek Holding kanalı yok.'], $jsonBayrak);
                    exit;
                }

                if ($action === 'faturaOnizle') {
                    $o = $fk->onizle($belge, $tbKanalId);
                    if (!$o['success']) {
                        echo json_encode(['success' => false, 'message' => $o['message']], $jsonBayrak);
                        exit;
                    }
                    $mevcut = $b['mevcut'];
                    echo json_encode(['success' => true, 'data' => [
                        'onizleme'    => $o,
                        'uyarilar'    => array_values(array_merge($b['uyarilar'], $o['uyarilar'])),
                        'alici'       => $belge['alici'],
                        'satici'      => ['unvan' => $belge['firma']['unvan'], 'vkn' => $belge['firma']['vergi_no']],
                        'kalemler'    => $belge['kalemler'],
                        'toplam'      => $belge['toplam'],
                        'para'        => $belge['para'],
                        'siparis_no'  => $belge['siparis_no'],
                        'pazaryeri'   => $belge['pazaryeri'],
                        'yontem'      => $belge['tutar_yontemi'],
                        'tb_kanallar' => $tbKanallar,
                        'tb_kanal'    => $tbKanalId,
                        'mevcut'      => $mevcut ? [
                            'no'          => $mevcut['Faturalar_FaturaNo'],
                            'ettn'        => $mevcut['Faturalar_ETTN'],
                            'tur'         => $mevcut['Faturalar_Tur'],
                            'durum'       => $mevcut['Faturalar_KesimDurumu'],
                            'aciklama'    => $mevcut['Faturalar_DurumAciklama'],
                            'kesinlesmis' => FaturaKesim::kesinlesmisMi($mevcut),
                        ] : null,
                    ]], $jsonBayrak);
                    exit;
                }

                $taslak  = ($_POST['taslak'] ?? '1') !== '0';
                $tekrar  = ($_POST['belirsiz_tekrar'] ?? '0') === '1';
                $sonuc   = $fk->kes($belge, $tbKanalId, $taslak, $tekrar);
                echo json_encode($sonuc + ['success' => false], $jsonBayrak);
                exit;

            // ---------------------------------------------------------
            case 'kanallar':
                $firmaId = (int)($_POST['firma_id'] ?? 0) ?: null;
                echo json_encode(['success' => true,
                    'kanallar' => PazaryeriFabrika::kanallar(null, $firmaId, $kisitFirmaId)], $jsonBayrak);
                exit;
        }

        echo json_encode(['success' => false, 'message' => 'Geçersiz istek.'], $jsonBayrak);
        exit;

    } catch (Throwable $e) {
        error_log('pazaryeri-siparisler.php: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.'], $jsonBayrak);
        exit;
    }
}

$firmalar     = PazaryeriFabrika::firmalar($kisitFirmaId);
$pazaryerleri = PazaryeriFabrika::pazaryerleri(true);
$kanallar     = PazaryeriFabrika::kanallar(null, null, $kisitFirmaId);
$duzenleyebilir = !empty($pagePermissions['can_edit']);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .filtre-basligi { cursor: pointer; }
        .durum-rozet { font-size: .72rem; }
        .tutar { font-variant-numeric: tabular-nums; font-weight: 600; }
        .kalem-tablo th { font-size: .75rem; text-transform: uppercase; color: #6c757d; }
        .zpl-kutu {
            font-family: monospace; font-size: .7rem;
            max-height: 260px; overflow: auto;
            background: #f8f9fa; padding: .5rem; border-radius: .25rem;
            white-space: pre-wrap; word-break: break-all;
        }
        .adres-satir { font-size: .8rem; color: #495057; }
        #tblSiparisler td { vertical-align: middle; white-space: nowrap; }
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

                    <!-- InfoBox: seçili filtre ve tarih aralığına göre -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-bag"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Sipariş</span>
                                    <span class="info-box-number" id="ibToplam">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-cash-stack"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text" title="İptal ve iade siparişler hariç">Toplam Ciro</span>
                                    <span class="info-box-number" id="ibCiro">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-arrow-return-left"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İptal / İade</span>
                                    <span class="info-box-number" id="ibIptal">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text" title="Yeni ve hazırlanıyor durumundakiler">Bekleyen (Kargolanmamış)</span>
                                    <span class="info-box-number" id="ibBekleyen">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box" role="button" id="ibFaturasizKutu" title="Faturasız siparişleri listele">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-receipt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text" title="İptal ve iade hariç, kesinleşmiş ya da taslak faturası olmayanlar">Faturasız</span>
                                    <span class="info-box-number" id="ibFaturasiz">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

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
                                        <label class="form-label small mb-1">Firma</label>
                                        <select class="form-select form-select-sm" id="fFirma">
                                            <?php if ($kisitFirmaId === null): ?>
                                            <option value="">Tüm firmalar</option>
                                            <?php endif; ?>
                                            <?php foreach ($firmalar as $f): ?>
                                            <option value="<?= (int)$f['firma_id'] ?>"><?= htmlspecialchars($f['firma_adi'] ?: $f['firma_unvan']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Pazaryeri</label>
                                        <select class="form-select form-select-sm" id="fPazaryeri">
                                            <option value="">Tümü</option>
                                            <?php foreach ($pazaryerleri as $p): ?>
                                            <option value="<?= (int)$p['Pazaryerleri_id'] ?>"><?= htmlspecialchars($p['Pazaryerleri_Ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1">Kanal</label>
                                        <select class="form-select form-select-sm" id="fKanal"></select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Durum</label>
                                        <select class="form-select form-select-sm" id="fDurum">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Ara</label>
                                        <input type="text" class="form-control form-control-sm" id="fAra"
                                               placeholder="Sipariş no, müşteri, takip no...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Fatura</label>
                                        <select class="form-select form-select-sm" id="fFatura">
                                            <option value="">Tümü</option>
                                            <option value="yok">Faturasız</option>
                                            <option value="taslak">Taslak</option>
                                            <option value="kesildi">Kesildi</option>
                                            <option value="hata">Hatalı / sonucu belirsiz</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Başlangıç</label>
                                        <input type="date" class="form-control form-control-sm" id="fBas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Bitiş</label>
                                        <input type="date" class="form-control form-control-sm" id="fBit">
                                    </div>
                                    <div class="col-md-6 d-flex gap-2 justify-content-end">
                                        <button class="btn btn-sm btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tablo -->
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <h3 class="card-title flex-grow-1 mb-0">Sipariş Listesi</h3>
                            <?php if ($duzenleyebilir): ?>
                            <button class="btn btn-sm btn-primary" id="btnSenkron">
                                <i class="bi bi-cloud-download me-1"></i> Siparişleri Çek
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <table class="table table-hover table-sm align-middle w-100" id="tblSiparisler">
                                <thead>
                                    <tr>
                                        <th style="width:28px"></th>
                                        <th>Kanal</th>
                                        <th>Sipariş No</th>
                                        <th>Müşteri</th>
                                        <th class="text-end">Tutar</th>
                                        <th>Durum</th>
                                        <th>Kargo</th>
                                        <th>Tarih</th>
                                        <th>Fatura</th>
                                        <th class="text-end">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Sipariş detay -->
    <div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bag"></i> Sipariş Detayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="detayIcerik"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Fatura önizleme / kesim -->
    <div class="modal fade" id="faturaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-receipt"></i> Fatura Kes <small class="text-muted" id="faturaBaslik"></small></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="faturaIcerik"></div>
                <div class="modal-footer justify-content-between">
                    <div class="d-flex align-items-center gap-2" id="faturaKanalKutu" style="min-width:280px">
                        <label class="small text-muted mb-0" for="faturaTbKanal">Örnek Holding kanalı</label>
                        <select class="form-select form-select-sm" id="faturaTbKanal" style="width:260px"></select>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                        <button type="button" class="btn btn-outline-warning d-none" id="btnFaturaTekrar"
                                title="Önceki gönderimin sonucu bilinmiyor; aynı ETTN ile tekrar gönderilir">
                            <i class="bi bi-arrow-repeat me-1"></i> Aynı ETTN ile tekrar
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="btnFaturaTaslak"
                                title="Örnek Holding'ye taslak yüklenir, GİB'e gitmez">
                            <i class="bi bi-file-earmark me-1"></i> Taslak yükle
                        </button>
                        <button type="button" class="btn btn-success" id="btnFaturaKes">
                            <i class="bi bi-send-check me-1"></i> Kesin kes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Kargo etiketi -->
    <div class="modal fade" id="etiketModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Kargo Etiketi (ZPL)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Aşağıdaki ZPL içeriği etiket yazıcısına gönderilebilir.</p>
                    <div class="zpl-kutu" id="zplIcerik"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary" id="btnZplKopyala">
                        <i class="bi bi-clipboard me-1"></i> Kopyala
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {

        const DUZENLEYEBILIR = <?= $duzenleyebilir ? 'true' : 'false' ?>;
        const tumKanallar = <?= json_encode($kanallar, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

        const kacis = (s) => $('<div>').text(s ?? '').html();
        const tarihStr = (d) => d.toISOString().slice(0, 10);
        const paraFormat = (t, pb) => t !== null && t !== undefined
            ? Number(t).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              + ' ' + (!pb || pb === 'TRY' ? '₺' : pb)
            : '-';

        // Ortak katmanın normalize durum kodları (PazaryeriApi alt sınıfları üretir)
        const DURUM = {
            yeni:         ['text-bg-warning',   'Yeni'],
            hazirlaniyor: ['text-bg-info',      'Hazırlanıyor'],
            kargoda:      ['text-bg-primary',   'Kargoda'],
            teslim:       ['text-bg-success',   'Teslim edildi'],
            iptal:        ['text-bg-secondary', 'İptal'],
            iade:         ['text-bg-danger',    'İade'],
            diger:        ['text-bg-light',     'Diğer']
        };
        const durumEtiket = (kod) => (DURUM[kod] || [null, kod])[1];

        // custom.js tüm .form-select kutularına "Seçiniz..." yer tutuculu Select2 kurar;
        // kendi etiketleriyle yeniden kurulur.
        [['#fFirma', 'Tüm firmalar'], ['#fPazaryeri', 'Tümü'], ['#fKanal', 'Tümü'], ['#fDurum', 'Tümü'], ['#fFatura', 'Tümü']]
            .forEach(([sec, etiket]) => {
                const $s = $(sec);
                if ($s.hasClass('select2-hidden-accessible')) { $s.select2('destroy'); }
                initSelect2(sec, { placeholder: etiket, minimumResultsForSearch: 0 });
            });

        const siparisDestekler = (k) => !!(k && k.sinifVar && String(k.Pazaryerleri_Durum) === '1'
                                           && k.yetenekler && k.yetenekler.siparis);

        function secimKanallari() {
            const firmaId = $('#fFirma').val();
            const pyId = $('#fPazaryeri').val();
            return tumKanallar.filter(k =>
                (!firmaId || String(k.EntegrasyonKanallari_Firmalar_id) === String(firmaId)) &&
                (!pyId || String(k.Pazaryerleri_id) === String(pyId)));
        }

        function varsayilanTarihler() {
            const bas = new Date();
            bas.setDate(bas.getDate() - 30);
            $('#fBas').val(tarihStr(bas));
            $('#fBit').val(tarihStr(new Date()));
        }

        // ---------------------------------------------------------------
        function kanallariDoldur(yenile = true) {
            const onceki = $('#fKanal').val();
            const liste = secimKanallari();
            $('#fKanal').html('<option value="">Tümü</option>'
                + liste.map(k => '<option value="' + k.EntegrasyonKanallari_id + '">'
                    + kacis(k.Pazaryerleri_Ad + ' — ' + k.EntegrasyonKanallari_Ad
                            + (k.firma_adi ? ' (' + k.firma_adi + ')' : '')) + '</option>').join(''));
            const kalsin = liste.some(k => String(k.EntegrasyonKanallari_id) === String(onceki));
            $('#fKanal').val(kalsin ? onceki : '').trigger('change.select2');
            senkronButonu();
            if (yenile) yenileHepsi();
        }

        function senkronButonu() {
            const id = $('#fKanal').val();
            const destek = id
                ? siparisDestekler(tumKanallar.find(k => String(k.EntegrasyonKanallari_id) === String(id)))
                : secimKanallari().some(siparisDestekler);
            $('#btnSenkron').prop('disabled', !destek)
                .attr('title', destek ? '' : 'Seçimde sipariş çekmeyi destekleyen kanal yok');
        }

        function filtreVerisi() {
            return {
                firma_id:     $('#fFirma').val() || '',
                pazaryeri_id: $('#fPazaryeri').val() || '',
                kanal:        $('#fKanal').val() || '',
                durum:        $('#fDurum').val() || '',
                fatura:       $('#fFatura').val() || '',
                ara:          $('#fAra').val(),
                bas_tarih:    $('#fBas').val(),
                bit_tarih:    $('#fBit').val()
            };
        }

        // ---------------------------------------------------------------
        function loadStats() {
            $.post('', Object.assign({ action: 'stats' }, filtreVerisi()), function (res) {
                if (!res.success) return;
                $('#ibToplam').text(Number(res.data.toplam).toLocaleString('tr-TR'));
                $('#ibCiro').text(paraFormat(res.data.ciro, 'TRY'));
                $('#ibIptal').text(Number(res.data.iptal_iade).toLocaleString('tr-TR'));
                $('#ibBekleyen').text(Number(res.data.bekleyen).toLocaleString('tr-TR'));
                $('#ibFaturasiz').text(Number(res.data.faturasiz).toLocaleString('tr-TR'));
            }, 'json');
        }

        function loadDurumlar() {
            $.post('', { action: 'durumlar' }, function (res) {
                if (!res.success) return;
                const $s = $('#fDurum');
                const secili = $s.val();
                $s.find('option:not(:first)').remove();
                res.data.forEach(kod => $s.append(new Option(durumEtiket(kod), kod)));
                $s.val(res.data.includes(secili) ? secili : '').trigger('change.select2');
            }, 'json');
        }

        function yenileHepsi() {
            // Tek kanal seçiliyken Kanal kolonu tekrar eden bilgi olur
            tablo.column(1).visible(!$('#fKanal').val(), false);
            tablo.columns.adjust();
            tablo.ajax.reload();
            tablo.one('draw', loadStats);
        }

        // ---------------------------------------------------------------
        // Faturalar_KesimDurumu → rozet (FaturaKesim'in yazdığı değerler; boş = senkronla bağlanmış fatura)
        const KESIM = {
            GONDERILDI:   ['text-bg-success',   'Kesildi'],
            TASLAK:       ['text-bg-info',      'Taslak'],
            HATA:         ['text-bg-danger',    'Hata'],
            GONDERILIYOR: ['text-bg-warning',   'Sonucu belirsiz']
        };

        function faturaHucresi(r) {
            let html = '<span class="text-muted small">-</span>';
            if (r.PazaryeriSiparisleri_Faturalar_id && r.Faturalar_Durum !== null) {
                const iptal = String(r.Faturalar_IptalDurumu) === '1';
                const h = iptal ? ['text-bg-secondary', 'İptal']
                        : (r.fatura_kesinlesmis ? KESIM.GONDERILDI : (KESIM[r.Faturalar_KesimDurumu] || ['text-bg-light', '-']));
                const no = (r.Faturalar_FaturaNo && !/0{9}$/.test(r.Faturalar_FaturaNo)) ? r.Faturalar_FaturaNo : '';
                html = '<span class="badge ' + h[0] + ' durum-rozet"'
                     + (r.Faturalar_DurumAciklama ? ' title="' + kacis(r.Faturalar_DurumAciklama) + '"' : '') + '>'
                     + kacis(h[1]) + '</span>'
                     + (r.Faturalar_Tur ? ' <small class="text-muted">' + (r.Faturalar_Tur === 'EARSIV' ? 'e-Arşiv' : 'e-Fatura') + '</small>' : '')
                     + (no ? '<br><small class="fw-semibold">' + kacis(no) + '</small>' : '');
            }
            if (r.yetenekler && r.yetenekler.faturaBildirim) {
                if (r.PazaryeriSiparisleri_FaturaBildirimTarihi) {
                    html += '<br><small class="text-success" title="' + kacis(r.PazaryeriSiparisleri_FaturaBildirimTarihi)
                          + '"><i class="bi bi-check2-circle"></i> pazaryerine bildirildi</small>';
                } else if (r.PazaryeriSiparisleri_FaturaBildirimHata) {
                    html += '<br><small class="text-danger" title="' + kacis(r.PazaryeriSiparisleri_FaturaBildirimHata)
                          + '"><i class="bi bi-exclamation-circle"></i> bildirim hatası</small>';
                }
            }
            return html;
        }

        // İlk istekten önce filtre değerleri hazır olmalı (tarih aralığı + kanal listesi)
        varsayilanTarihler();
        kanallariDoldur(false);

        const tablo = $('#tblSiparisler').DataTable({
            serverSide: true,
            processing: true,
            scrollX: true,
            dom: 'lrtip',
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    Object.assign(d, filtreVerisi());
                    d.action = 'list';
                    return d;
                },
                error: function () { showToast('Liste yüklenemedi', 'error'); }
            },
            columns: [
                {
                    data: 'PazaryeriSiparisleri_id', orderable: false, className: 'text-center',
                    render: (d) => '<button class="btn btn-sm btn-link p-0 btn-detay" data-id="' + d + '" title="Detay">'
                                 + '<i class="bi bi-chevron-expand"></i></button>'
                },
                {
                    data: 'Pazaryerleri_Ad', orderable: false,
                    render: (d, t, r) => '<span class="badge" style="background:' + kacis(r.Pazaryerleri_Renk || '#6c757d') + '">'
                                       + kacis(d || '-') + '</span>'
                                       + '<br><small class="text-muted">' + kacis(r.firma_adi || r.EntegrasyonKanallari_Ad || '') + '</small>'
                },
                {
                    data: 'PazaryeriSiparisleri_SiparisNo',
                    render: (d, t, r) => {
                        let html = '<span class="fw-semibold">' + kacis(d || r.PazaryeriSiparisleri_DisId) + '</span>';
                        if (r.KalemSayisi) {
                            html += ' <span class="badge text-bg-light">' + r.KalemSayisi + ' kalem</span>';
                        }
                        if (r.PazaryeriSiparisleri_PaketNo && r.PazaryeriSiparisleri_PaketNo !== d) {
                            html += '<br><small class="text-muted">paket: ' + kacis(r.PazaryeriSiparisleri_PaketNo) + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_MusteriAdi',
                    render: (d, t, r) => {
                        let html = kacis(d || '-');
                        const yer = [r.PazaryeriSiparisleri_Ilce, r.PazaryeriSiparisleri_Il].filter(Boolean).join(' / ');
                        if (yer) html += '<br><small class="text-muted">' + kacis(yer) + '</small>';
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_ToplamTutar', className: 'text-end tutar',
                    render: (d, t, r) => paraFormat(d, r.PazaryeriSiparisleri_ParaBirimi)
                },
                {
                    data: 'PazaryeriSiparisleri_DurumKod',
                    render: (d, t, r) => {
                        const h = DURUM[d] || ['text-bg-light', d || '-'];
                        let html = '<span class="badge ' + h[0] + ' durum-rozet">' + kacis(h[1]) + '</span>';
                        if (r.PazaryeriSiparisleri_HamDurum) {
                            html += '<br><small class="text-muted">' + kacis(r.PazaryeriSiparisleri_HamDurum) + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_KargoFirmasi',
                    render: (d, t, r) => {
                        let html = kacis(d || '-');
                        if (r.PazaryeriSiparisleri_KargoTakipNo) {
                            html += '<br><small class="text-muted">' + kacis(r.PazaryeriSiparisleri_KargoTakipNo) + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'PazaryeriSiparisleri_SiparisTarihi',
                    render: (d, t, r) => (d ? kacis(d) : '-')
                        + (r.PazaryeriSiparisleri_TerminTarihi
                            ? '<br><small class="text-muted" title="Termin">termin: ' + kacis(r.PazaryeriSiparisleri_TerminTarihi) + '</small>' : '')
                },
                {
                    data: 'PazaryeriSiparisleri_Faturalar_id', orderable: false,
                    render: (d, t, r) => faturaHucresi(r)
                },
                {
                    data: 'PazaryeriSiparisleri_id', className: 'text-end', orderable: false,
                    render: function (d, t, r) {
                        // Yetenekler seçili filtreye göre değil siparişin kendi pazaryerine göre;
                        // desteklenmeyen aksiyon hiç gösterilmez (tanim_Pazaryerleri.Pazaryerleri_Yetenekler).
                        const yet = r.yetenekler || {};
                        let html = '';
                        if (DUZENLEYEBILIR && r.fatura_kesilebilir) {
                            html += '<button class="btn btn-sm btn-outline-success btn-fatura" data-id="' + d
                                  + '" title="Fatura kes"><i class="bi bi-receipt"></i></button> ';
                        }
                        if (DUZENLEYEBILIR && yet.kargoEtiketi) {
                            html += '<button class="btn btn-sm btn-outline-secondary btn-etiket" data-id="' + d
                                  + '" title="Kargo etiketi"><i class="bi bi-upc-scan"></i></button> ';
                        }
                        if (DUZENLEYEBILIR && yet.paketDurum) {
                            html += '<div class="btn-group btn-group-sm">'
                                  + '<button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" title="Paket durumu">'
                                  + '<i class="bi bi-gear"></i></button>'
                                  + '<ul class="dropdown-menu dropdown-menu-end">'
                                  + '<li><a class="dropdown-item btn-durum" href="#" data-id="' + d + '" data-durum="Picking">'
                                  + '<i class="bi bi-box-seam me-1"></i> Hazırlanıyor olarak işaretle</a></li>'
                                  + '<li><a class="dropdown-item btn-durum" href="#" data-id="' + d + '" data-durum="Invoiced">'
                                  + '<i class="bi bi-receipt me-1"></i> Faturalandı olarak işaretle</a></li>'
                                  + '</ul></div>';
                        }
                        return html || '<span class="text-muted small">-</span>';
                    }
                }
            ],
            order: [[7, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
        });

        // ---------------------------------------------------------------
        $('#fFirma, #fPazaryeri').on('change', () => kanallariDoldur(true));
        $('#fKanal').on('change', function () { senkronButonu(); yenileHepsi(); });
        $('#fDurum, #fFatura, #fBas, #fBit').on('change', yenileHepsi);
        $('#ibFaturasizKutu').on('click', function () { $('#fFatura').val('yok').trigger('change'); });
        $('#btnFiltrele').on('click', yenileHepsi);

        let aramaZaman = null;
        $('#fAra').on('keyup', function (e) {
            clearTimeout(aramaZaman);
            aramaZaman = setTimeout(yenileHepsi, e.which === 13 ? 0 : 400);
        });

        $('#btnTemizle').on('click', function () {
            $('#fAra').val('');
            varsayilanTarihler();
            if ($('#fFirma option[value=""]').length) { $('#fFirma').val('').trigger('change.select2'); }
            $('#fPazaryeri, #fDurum, #fFatura').val('').trigger('change.select2');
            kanallariDoldur(true);
            showToast('Filtreler temizlendi', 'info');
        });

        // ---------------------------------------------------------------
        // Sipariş çekme (seçimdeki destekleyen tüm kanallar)
        // ---------------------------------------------------------------
        $('#btnSenkron').on('click', function () {
            if ($(this).prop('disabled')) return;
            const kanalId = $('#fKanal').val();

            Swal.fire({
                title: kanalId ? 'Siparişleri çek' : 'Seçimdeki tüm kanallardan siparişleri çek',
                input: 'number',
                inputLabel: 'Kaç günlük sipariş çekilsin?',
                inputValue: 14,
                inputAttributes: { min: 1, max: 365, step: 1 },
                inputValidator: (v) => (!v || v < 1 || v > 365) ? '1 ile 365 arasında bir gün sayısı girin' : undefined,
                showCancelButton: true,
                confirmButtonText: 'Çek',
                cancelButtonText: 'Vazgeç'
            }).then((s) => {
                if (!s.isConfirmed) return;

                Swal.fire({ title: 'Siparişler çekiliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

                $.post('', Object.assign({ action: 'senkron', gun: s.value }, filtreVerisi()), function (res) {
                    Swal.close();
                    // Kanal bazlı sonuç: hangi hesabın neden başarısız olduğu görünür kalmalı
                    let html = '<div>' + kacis(res.message) + '</div>';
                    if (res.kanallar && res.kanallar.length) {
                        html += '<ul class="list-unstyled text-start small mt-3 mb-0">'
                              + res.kanallar.map(k =>
                                    '<li class="py-1 border-bottom"><i class="bi '
                                  + (k.basarili ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger')
                                  + ' me-1"></i><strong>' + kacis(k.kanal) + '</strong>: ' + kacis(k.mesaj) + '</li>'
                                ).join('')
                              + '</ul>';
                    }
                    const kismi = res.success && res.kanallar && res.kanallar.some(k => !k.basarili);
                    Swal.fire({
                        icon: !res.success ? 'error' : (kismi ? 'warning' : 'success'),
                        title: !res.success ? 'Çekilemedi' : (kismi ? 'Kısmen tamamlandı' : 'Tamamlandı'),
                        html: html
                    });
                    if (res.success) { loadDurumlar(); yenileHepsi(); }
                }, 'json').fail(function () {
                    Swal.close();
                    showToast('Sunucu hatası', 'error');
                });
            });
        });

        // ---------------------------------------------------------------
        // Detay
        // ---------------------------------------------------------------
        const detayModal = new bootstrap.Modal('#detayModal');

        $('#tblSiparisler').on('click', '.btn-detay', function () {
            $.post('', { action: 'detay', id: $(this).data('id') }, function (res) {
                if (!res.success) { showToast(kacis(res.message), 'error'); return; }
                const s = res.data.siparis;
                const kalemler = res.data.kalemler || [];

                let adres = '';
                try {
                    const a = JSON.parse(s.PazaryeriSiparisleri_TeslimatAdresi || '{}');
                    adres = typeof a === 'object'
                        ? [a.address, a.neighborhood, a.district, a.city].filter(Boolean).join(', ')
                        : String(a);
                } catch (e) { adres = s.PazaryeriSiparisleri_TeslimatAdresi || ''; }

                let html = '<div class="row g-3 mb-3">'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Sipariş</div>'
                    + '<div class="fw-semibold">' + kacis(s.PazaryeriSiparisleri_SiparisNo || s.PazaryeriSiparisleri_DisId) + '</div>'
                    + '<div class="small">' + kacis(s.PazaryeriSiparisleri_SiparisTarihi || '') + '</div>'
                    + '<div class="small mt-1">' + kacis(durumEtiket(s.PazaryeriSiparisleri_DurumKod) || '-')
                    + (s.PazaryeriSiparisleri_HamDurum ? ' <span class="text-muted">(' + kacis(s.PazaryeriSiparisleri_HamDurum) + ')</span>' : '') + '</div>'
                    + '</div></div>'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Kanal</div>'
                    + '<div class="fw-semibold">' + kacis(s.Pazaryerleri_Ad || '-') + '</div>'
                    + '<div class="small">' + kacis(s.EntegrasyonKanallari_Ad || '') + '</div>'
                    + (s.firma_adi ? '<div class="small text-muted">' + kacis(s.firma_unvan || s.firma_adi) + '</div>' : '')
                    + '</div></div>'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Müşteri</div>'
                    + '<div class="fw-semibold">' + kacis(s.PazaryeriSiparisleri_MusteriAdi || '-') + '</div>'
                    + (adres ? '<div class="adres-satir">' + kacis(adres) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_TCKN ? '<div class="small">TCKN: ' + kacis(s.PazaryeriSiparisleri_TCKN) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_VergiNo ? '<div class="small">VKN: ' + kacis(s.PazaryeriSiparisleri_VergiNo)
                        + (s.PazaryeriSiparisleri_VergiDairesi ? ' · ' + kacis(s.PazaryeriSiparisleri_VergiDairesi) : '') + '</div>' : '')
                    + '</div></div>'
                    + '<div class="col-md-3"><div class="border rounded p-2 h-100">'
                    + '<div class="small text-muted">Kargo</div>'
                    + '<div class="fw-semibold">' + kacis(s.PazaryeriSiparisleri_KargoFirmasi || '-') + '</div>'
                    + (s.PazaryeriSiparisleri_KargoTakipNo ? '<div class="small">Takip: ' + kacis(s.PazaryeriSiparisleri_KargoTakipNo) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_Desi ? '<div class="small">Desi: ' + kacis(s.PazaryeriSiparisleri_Desi) + '</div>' : '')
                    + (s.PazaryeriSiparisleri_Faturalar_id ? '<div class="small text-success mt-1"><i class="bi bi-receipt"></i> Fatura #'
                        + kacis(s.PazaryeriSiparisleri_Faturalar_id) + '</div>' : '')
                    + '</div></div></div>';

                if (kalemler.length) {
                    html += '<div class="table-responsive"><table class="table table-sm kalem-tablo"><thead><tr>'
                          + '<th>Ürün</th><th>Barkod / Stok</th><th class="text-center">Adet</th>'
                          + '<th class="text-end">Birim</th><th class="text-end">Toplam</th>'
                          + '<th class="text-end">Komisyon</th></tr></thead><tbody>';
                    kalemler.forEach(function (k) {
                        html += '<tr>'
                            + '<td>' + kacis(k.urun_hizmet_adi || k.PazaryeriSiparisKalemleri_UrunAdi || '-')
                            + (k.urun_hizmet_kodu ? '<br><small class="text-muted">ürün kodu: ' + kacis(k.urun_hizmet_kodu) + '</small>' : '')
                            + (k.urun_hizmet_adi && k.PazaryeriSiparisKalemleri_UrunAdi && k.urun_hizmet_adi !== k.PazaryeriSiparisKalemleri_UrunAdi
                                ? '<br><small class="text-muted">pazaryeri: ' + kacis(k.PazaryeriSiparisKalemleri_UrunAdi) + '</small>' : '')
                            + '</td>'
                            + '<td><small>' + kacis(k.PazaryeriSiparisKalemleri_Barkod || '-') + '<br>'
                            + kacis(k.PazaryeriSiparisKalemleri_StokKodu || '') + '</small></td>'
                            + '<td class="text-center">' + kacis(k.PazaryeriSiparisKalemleri_Miktar) + '</td>'
                            + '<td class="text-end">' + paraFormat(k.PazaryeriSiparisKalemleri_BirimFiyat, s.PazaryeriSiparisleri_ParaBirimi) + '</td>'
                            + '<td class="text-end">' + paraFormat(k.PazaryeriSiparisKalemleri_ToplamTutar, s.PazaryeriSiparisleri_ParaBirimi) + '</td>'
                            + '<td class="text-end">' + (k.PazaryeriSiparisKalemleri_KomisyonOran
                                ? '%' + kacis(Number(k.PazaryeriSiparisKalemleri_KomisyonOran)) : '-') + '</td>'
                            + '</tr>';
                    });
                    html += '</tbody><tfoot><tr class="fw-semibold">'
                          + '<td colspan="4" class="text-end">Sipariş Toplamı</td>'
                          + '<td class="text-end">' + paraFormat(s.PazaryeriSiparisleri_ToplamTutar, s.PazaryeriSiparisleri_ParaBirimi) + '</td>'
                          + '<td></td></tr></tfoot></table></div>';
                } else {
                    html += '<div class="alert alert-light border">Kalem bilgisi yok.</div>';
                }

                $('#detayIcerik').html(html);
                detayModal.show();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        });

        // ---------------------------------------------------------------
        // Paket durumu
        // ---------------------------------------------------------------
        $('#tblSiparisler').on('click', '.btn-durum', function (e) {
            e.preventDefault();
            const id = $(this).data('id');
            const durum = $(this).data('durum');

            Swal.fire({
                icon: 'question',
                title: 'Paket durumu güncellensin mi?',
                text: 'Pazaryerinde "' + durum + '" olarak işaretlenecek.',
                showCancelButton: true,
                confirmButtonText: 'Güncelle',
                cancelButtonText: 'Vazgeç'
            }).then((s) => {
                if (!s.isConfirmed) return;
                $.post('', { action: 'paketDurum', id: id, yeni_durum: durum }, function (res) {
                    showToast(kacis(res.message || (res.success ? 'Güncellendi' : 'Güncellenemedi')),
                              res.success ? 'success' : 'error');
                    if (res.success) tablo.ajax.reload(null, false);
                }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
            });
        });

        // ---------------------------------------------------------------
        // Kargo etiketi
        // ---------------------------------------------------------------
        const etiketModal = new bootstrap.Modal('#etiketModal');

        // ---------------------------------------------------------------
        // Fatura önizleme / kesim
        // Önizleme kayıt yazmaz (mükellef sorgusu + numara tahmini). Kesimde belge sunucuda
        // siparişten yeniden üretilir; ekrandaki tutarlar yalnız bilgi amaçlıdır.
        let faturaSiparisId = null;
        let faturaVeri = null;
        const $tbKanal = $('#faturaTbKanal');
        if ($tbKanal.hasClass('select2-hidden-accessible')) { $tbKanal.select2('destroy'); }
        initSelect2('#faturaTbKanal', { dropdownParent: $('#faturaModal'), minimumResultsForSearch: 0, allowClear: false });

        function faturaButonlari(durum) {
            // durum: 'yukleniyor' | 'hazir' | 'belirsiz' | 'kapali'
            $('#btnFaturaTaslak, #btnFaturaKes').prop('disabled', durum !== 'hazir');
            $('#btnFaturaTekrar').toggleClass('d-none', durum !== 'belirsiz');
            $tbKanal.prop('disabled', durum === 'yukleniyor');
        }

        function faturaYukle(id, tbKanal) {
            faturaSiparisId = id;
            faturaVeri = null;
            faturaButonlari('yukleniyor');
            $('#faturaBaslik').text('');
            $('#faturaIcerik').html('<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>'
                + 'Önizleme hazırlanıyor (mükellef sorgusu, numara)...</div>');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('faturaModal')).show();

            $.post('', { action: 'faturaOnizle', id: id, tb_kanal: tbKanal || '' }, function (res) {
                if (!res.success) {
                    $('#faturaIcerik').html('<div class="alert alert-danger mb-0"><i class="bi bi-x-octagon me-1"></i>'
                        + kacis(res.message) + '</div>');
                    faturaButonlari('kapali');
                    return;
                }
                faturaVeri = res.data;
                faturaCiz(res.data);
            }, 'json').fail(function () {
                $('#faturaIcerik').html('<div class="alert alert-danger mb-0">Önizleme alınamadı.</div>');
                faturaButonlari('kapali');
            });
        }

        function faturaCiz(v) {
            const o = v.onizleme;
            const efatura = o.belge_turu === 'EFATURA';
            const para = (t) => paraFormat(t, v.para);
            $('#faturaBaslik').text(v.pazaryeri + ' · ' + v.siparis_no);

            // Örnek Holding kanalı seçimi
            $tbKanal.html(v.tb_kanallar.map(k => '<option value="' + k.kanal_id + '">'
                + kacis(k.kanal_adi + (k.test ? ' (TEST)' : '') + (k.varsayilan ? ' · varsayılan' : '')) + '</option>').join(''));
            $tbKanal.val(String(v.tb_kanal)).trigger('change.select2');
            $('#faturaKanalKutu').toggleClass('invisible', v.tb_kanallar.length < 2);

            let html = '<div class="alert ' + (efatura ? 'alert-primary' : 'alert-info') + ' d-flex flex-wrap gap-3 align-items-center">'
                + '<span class="badge ' + (efatura ? 'text-bg-primary' : 'text-bg-info') + ' fs-6">' + (efatura ? 'e-Fatura' : 'e-Arşiv') + '</span>'
                + (o.gib_unvan ? '<span><i class="bi bi-building me-1"></i>GİB: ' + kacis(o.gib_unvan) + '</span>' : '')
                + '<span>Seri <b>' + kacis(o.seri || '-') + '</b> · ' + (o.numara_yontemi === 'sirali' ? 'sıralı' : 'Örnek Holding atar') + '</span>'
                + (o.tahmini_numara ? '<span>Tahmini no <b>' + kacis(o.tahmini_numara) + '</b></span>' : '')
                + '<span class="text-muted small">Şablon: ' + kacis(o.xslt || 'Örnek Holding varsayılanı') + '</span>'
                + (o.kanal && o.kanal.test ? '<span class="badge text-bg-warning">TEST hesabı: kayıt yazılmaz</span>' : '')
                + '</div>';

            const m = v.mevcut;
            let durum = 'hazir';
            if (m && !m.kesinlesmis) {
                if (m.durum === 'GONDERILIYOR') {
                    durum = 'belirsiz';
                    html += '<div class="alert alert-warning"><i class="bi bi-question-octagon me-1"></i>'
                        + 'Önceki gönderimin sonucu bilinmiyor (ETTN <code>' + kacis(m.ettn) + '</code>). '
                        + 'Örnek Holding portalında kontrol edin; fatura yoksa <b>aynı ETTN ile tekrar</b> gönderin '
                        + '(Örnek Holding aynı ETTN\'yi ikinci kez kabul etmez, çift fatura oluşmaz).</div>';
                } else if (m.durum === 'TASLAK') {
                    html += '<div class="alert alert-info"><i class="bi bi-file-earmark me-1"></i>'
                        + 'Bu siparişin Örnek Holding\'de taslağı var (ETTN <code>' + kacis(m.ettn) + '</code>). '
                        + 'Yeniden yükleme / kesin kesim yeni belge oluşturur; eski taslağı Örnek Holding portalından silin.</div>';
                } else if (m.durum === 'HATA') {
                    html += '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>Önceki deneme hata verdi: '
                        + kacis(m.aciklama || '-') + '</div>';
                }
            }
            if (v.uyarilar.length) {
                html += '<div class="alert alert-warning py-2"><ul class="mb-0 ps-3">'
                    + v.uyarilar.map(u => '<li>' + kacis(u) + '</li>').join('') + '</ul></div>';
            }

            const a = v.alici;
            html += '<div class="row g-3 mb-3">'
                + '<div class="col-md-6"><div class="border rounded p-2 h-100"><div class="small text-muted">Satıcı</div>'
                + '<div class="fw-semibold">' + kacis(v.satici.unvan) + '</div><div class="small">VKN ' + kacis(v.satici.vkn || '-') + '</div></div></div>'
                + '<div class="col-md-6"><div class="border rounded p-2 h-100"><div class="small text-muted">Alıcı</div>'
                + '<div class="fw-semibold">' + kacis(a.unvan) + '</div>'
                + '<div class="small">' + kacis(a.kimlik_tur) + ' ' + kacis(a.kimlik) + (a.vergi_dairesi ? ' · VD ' + kacis(a.vergi_dairesi) : '') + '</div>'
                + '<div class="adres-satir">' + kacis([a.adres, a.ilce, a.il].filter(Boolean).join(' / ')) + '</div>'
                + (a.eposta ? '<div class="small text-muted">' + kacis(a.eposta) + '</div>' : '')
                + '</div></div></div>';

            html += '<div class="table-responsive"><table class="table table-sm kalem-tablo mb-0"><thead><tr>'
                + '<th>Kalem</th><th class="text-center">Adet</th><th class="text-end">KDV</th>'
                + '<th class="text-end">Matrah</th><th class="text-end">KDV Tutarı</th><th class="text-end">Toplam</th></tr></thead><tbody>'
                + v.kalemler.map(k => '<tr><td>' + kacis(k.ad) + '</td><td class="text-center">' + kacis(k.miktar) + '</td>'
                    + '<td class="text-end">%' + Number(k.kdv_oran).toLocaleString('tr-TR') + '</td>'
                    + '<td class="text-end">' + para(k.matrah) + '</td><td class="text-end">' + para(k.kdv) + '</td>'
                    + '<td class="text-end tutar">' + para(k.brut) + '</td></tr>').join('')
                + '</tbody><tfoot><tr class="fw-semibold"><td colspan="3" class="text-end small text-muted">'
                + 'tutar yöntemi: ' + kacis(v.yontem) + '</td>'
                + '<td class="text-end">' + para(v.toplam.matrah) + '</td><td class="text-end">' + para(v.toplam.kdv) + '</td>'
                + '<td class="text-end tutar">' + para(v.toplam.genel) + '</td></tr></tfoot></table></div>';

            $('#faturaIcerik').html(html);
            faturaButonlari(durum);
        }

        function faturaGonder(taslak, tekrar) {
            if (!faturaSiparisId || !faturaVeri) return;
            const v = faturaVeri;
            const tur = v.onizleme.belge_turu === 'EFATURA' ? 'e-Fatura' : 'e-Arşiv';

            const gonder = () => {
                faturaButonlari('yukleniyor');
                $('#faturaIcerik').prepend('<div class="alert alert-secondary" id="faturaIsleniyor">'
                    + '<span class="spinner-border spinner-border-sm me-2"></span>Örnek Holding\'ye gönderiliyor...</div>');
                $.post('', {
                    action: 'faturaKes', id: faturaSiparisId, tb_kanal: $tbKanal.val() || '',
                    taslak: taslak ? '1' : '0', belirsiz_tekrar: tekrar ? '1' : '0'
                }, function (res) {
                    tablo.ajax.reload(null, false);
                    loadStats();
                    if (res.success) {
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('faturaModal')).hide();
                        showToast(res.message + (res.fatura_no && !/0{9}$/.test(res.fatura_no) ? ' No: ' + res.fatura_no : ''), 'success');
                        if (res.uyari) { showToast(res.uyari, 'warning'); }
                        return;
                    }
                    // Sonuç (hata / belirsiz) kayda yazıldı; önizleme yeniden alınır
                    showToast(res.message || 'Fatura kesilemedi', 'error');
                    faturaYukle(faturaSiparisId, $tbKanal.val());
                }, 'json').fail(function () {
                    $('#faturaIsleniyor').remove();
                    showToast('Bağlantı hatası; sonucu kontrol etmek için önizlemeyi yeniden açın.', 'error');
                    tablo.ajax.reload(null, false);
                    faturaButonlari('kapali');
                });
            };

            if (taslak && !tekrar) { gonder(); return; }

            Swal.fire({
                icon: 'warning',
                title: tekrar ? 'Aynı ETTN ile tekrar gönderilsin mi?' : tur + ' kesilsin mi?',
                html: (tekrar ? 'Önceki gönderim aynı ETTN ve numarayla tekrarlanır.<br>' : '')
                    + '<b>' + kacis(v.alici.unvan) + '</b><br>' + paraFormat(v.toplam.genel, v.para)
                    + (taslak ? '' : '<br><span class="text-danger small">Fatura GİB\'e gönderilir; geri alınamaz, yalnız iptal edilebilir.</span>'),
                showCancelButton: true,
                confirmButtonText: tekrar ? 'Tekrar gönder' : 'Kesin kes',
                cancelButtonText: 'Vazgeç',
                confirmButtonColor: '#198754'
            }).then(r => { if (r.isConfirmed) gonder(); });
        }

        $('#tblSiparisler').on('click', '.btn-fatura', function () { faturaYukle($(this).data('id')); });
        $tbKanal.on('change', function () {
            if (faturaVeri && String($(this).val()) !== String(faturaVeri.tb_kanal)) faturaYukle(faturaSiparisId, $(this).val());
        });
        $('#btnFaturaTaslak').on('click', () => faturaGonder(true, false));
        $('#btnFaturaKes').on('click', () => faturaGonder(false, false));
        // Tekrar: önceki denemenin türü korunur; taslak mı kesin mi bilinmediği için kesin gönderilir
        $('#btnFaturaTekrar').on('click', () => faturaGonder(false, true));

        $('#tblSiparisler').on('click', '.btn-etiket', function () {
            const btn = $(this).prop('disabled', true);
            $.post('', { action: 'kargoEtiketi', id: btn.data('id') }, function (res) {
                if (!res.success) {
                    Swal.fire({ icon: 'error', title: 'Etiket alınamadı', text: res.message });
                    return;
                }
                $('#zplIcerik').text(res.zpl || '');
                etiketModal.show();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'))
              .always(() => btn.prop('disabled', false));
        });

        $('#btnZplKopyala').on('click', function () {
            navigator.clipboard.writeText($('#zplIcerik').text()).then(
                () => showToast('ZPL kopyalandı', 'success'),
                () => showToast('Kopyalanamadı', 'error')
            );
        });

        // ---------------------------------------------------------------
        // InfoBox sayımları tablo yüklendikten sonra
        tablo.one('draw', loadStats);
        loadDurumlar();
    });
    </script>
</body>
</html>
