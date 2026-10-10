<?php
/**
 * Admin Panel - Cari Hareketleri (Cari Kart Ekstresi)
 *
 * Bir carinin gelen fatura, giden fatura ve ödeme hareketlerini tek ekstrede
 * birleştirir, yürüyen bakiye ile borç/alacak durumunu gösterir.
 *
 * Bakiye yönü (cari kartı bakışı):
 *   Giden fatura (satış)      -> Borç   (cari bize borçlanır)
 *   Gelen fatura (alış)       -> Alacak
 *   Tahsilat (isaret = +1)    -> Alacak (borcu düşer)
 *   Ödeme    (isaret = -1)    -> Borç
 *   Bakiye = Borç - Alacak    (pozitif ise cari bize borçlu)
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

// AJAX isteklerinde oturum bitmişse JSON dön
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Oturum süresi doldu. Lütfen tekrar giriş yapın.', 'redirect' => '/admin/login.php']));
    }
} else {
    requireAuth();
}

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}
$permissions = $pagePermissions;

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cari Hareketleri';
$menuAdi   = $pageInfo['menu_adi'] ?? 'Muhasebe';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// ============================================================================
// Ortak veri fonksiyonları
// ============================================================================

/**
 * VKN/TCKN'yi karşılaştırmaya uygun biçime getirir (rakam dışı karakterler atılır).
 */
function vknNormalize(?string $vkn): string
{
    return preg_replace('/[^0-9]/', '', (string)$vkn);
}

/**
 * Fatura tutarını TL karşılığına çevirir.
 * TRY dışı para birimlerinde faturanın kendi kuru kullanılır; kur yoksa tutar
 * olduğu gibi döner ve satır "çevrilemedi" olarak işaretlenir.
 */
function faturaTlTutar(array $f): array
{
    $tutar = (float)($f['tutar_orijinal'] ?? 0);
    $birim = strtoupper(trim((string)($f['para_birimi'] ?? 'TRY')));
    $dolar = (float)($f['dolar_kur'] ?? 0);
    $euro  = (float)($f['euro_kur'] ?? 0);

    if ($birim === '' || $birim === 'TRY' || $birim === 'TL') {
        return ['tutar' => $tutar, 'cevrildi' => true];
    }
    if (($birim === 'USD' || $birim === 'DOLAR') && $dolar > 0) {
        return ['tutar' => $tutar * $dolar, 'cevrildi' => true];
    }
    if (($birim === 'EUR' || $birim === 'EURO') && $euro > 0) {
        return ['tutar' => $tutar * $euro, 'cevrildi' => true];
    }
    return ['tutar' => $tutar, 'cevrildi' => false];
}

/**
 * Fatura ve ödeme hareketlerini tek listede, tarih sıralı olarak çeker.
 * Bakiye hesaplaması yapmaz; yalnızca borç/alacak kolonlarını üretir.
 */
function cariHamHareketler(Database $db, int $cariId, ?string $bas, ?string $bit): array
{
    // Faturalar
    $fKosul  = '';
    $fParams = [$cariId];
    if ($bas) { $fKosul .= ' AND f.Faturalar_Tarih >= ?'; $fParams[] = $bas; }
    if ($bit) { $fKosul .= ' AND f.Faturalar_Tarih <= ?'; $fParams[] = $bit; }

    $faturalar = $db->fetchAll("
        SELECT
            CONVERT(VARCHAR(10), f.Faturalar_Tarih, 23) AS tarih,
            f.Faturalar_id            AS kayit_id,
            f.Faturalar_Yon           AS yon,
            f.Faturalar_Tur           AS tur,
            f.Faturalar_FaturaNo      AS belge_no,
            f.Faturalar_GibDurum      AS gib_durum,
            f.Faturalar_OdenecekTutar AS tutar_orijinal,
            f.Faturalar_ParaBirimi    AS para_birimi,
            f.Faturalar_DolarKur      AS dolar_kur,
            f.Faturalar_EuroKur       AS euro_kur
        FROM Faturalar f
        WHERE f.Faturalar_Cari_id = ? AND f.Faturalar_Durum = 1
          AND f.Faturalar_IptalDurumu = 0 $fKosul
    ", $fParams);

    // Ödeme hareketleri
    $oKosul  = '';
    $oParams = [$cariId];
    if ($bas) { $oKosul .= ' AND o.odeme_hareket_tarih >= ?'; $oParams[] = $bas; }
    if ($bit) { $oKosul .= ' AND o.odeme_hareket_tarih <= ?'; $oParams[] = $bit; }

    $odemeler = $db->fetchAll("
        SELECT
            CONVERT(VARCHAR(10), o.odeme_hareket_tarih, 23) AS tarih,
            o.odeme_hareket_id       AS kayit_id,
            o.odeme_hareket_tutar    AS tutar,
            o.odeme_hareket_aciklama AS aciklama,
            o.odeme_hareket_evrak    AS evrak,
            o.odeme_hareket_belge_no AS belge_no,
            t.odeme_tip_adi          AS tip_adi,
            t.odeme_tip_isaret       AS isaret,
            y.odeme_yontem_adi       AS yontem_adi,
            k.kasa_adi               AS kasa_adi,
            b.banka_adi              AS banka_adi,
            bh.bankaHesap_iban       AS banka_iban
        FROM Odeme_Hareketleri o
        INNER JOIN Odeme_Tipleri t ON t.odeme_tip_id = o.odeme_hareket_tip_id
        LEFT JOIN tanim_odeme_yontemleri y ON y.odeme_yontem_id = o.odeme_hareket_yontem_id
        LEFT JOIN Kasa k ON k.kasa_id = o.odeme_hareket_kasa_id
        LEFT JOIN Banka_Hesap bh ON bh.bankaHesap_id = o.odeme_hareket_banka_hesap_id
        LEFT JOIN bankalar b ON b.banka_id = bh.bankaHesap_banka_id
        WHERE o.odeme_hareket_cari_id = ? AND o.odeme_hareket_durum = 1 $oKosul
    ", $oParams);

    $liste = [];

    foreach ($faturalar as $f) {
        $tl  = faturaTlTutar($f);
        $yon = strtoupper((string)$f['yon']);
        $liste[] = [
            'tarih'       => $f['tarih'],
            'kaynak'      => 'FATURA',
            'kayit_id'    => (int)$f['kayit_id'],
            'belge_tipi'  => $yon === 'GIDEN' ? 'Satış Faturası' : 'Alış Faturası',
            'belge_no'    => (string)$f['belge_no'],
            'aciklama'    => trim(($f['tur'] === 'EFATURA' ? 'E-Fatura' : 'E-Arşiv')
                                  . ($f['gib_durum'] ? ' / ' . $f['gib_durum'] : '')),
            'borc'        => $yon === 'GIDEN' ? (float)$tl['tutar'] : 0.0,
            'alacak'      => $yon === 'GELEN' ? (float)$tl['tutar'] : 0.0,
            'para_birimi' => (string)$f['para_birimi'],
            'kur_uyari'   => $tl['cevrildi'] ? 0 : 1,
        ];
    }

    foreach ($odemeler as $o) {
        $isaret = (int)$o['isaret'];
        $tutar  = (float)$o['tutar'];

        // Ödeme yöntemi ve hedefi (kasa veya banka hesabı) açıklamanın önüne eklenir
        $yontemBilgi = [];
        if (!empty($o['yontem_adi'])) { $yontemBilgi[] = (string)$o['yontem_adi']; }
        if (!empty($o['kasa_adi']))   { $yontemBilgi[] = (string)$o['kasa_adi']; }
        if (!empty($o['banka_adi'])) {
            $yontemBilgi[] = (string)$o['banka_adi']
                . (!empty($o['banka_iban']) ? ' ' . $o['banka_iban'] : '');
        }
        $aciklama = (string)($o['aciklama'] ?? '');
        if ($yontemBilgi) {
            $aciklama = implode(' / ', $yontemBilgi) . ($aciklama !== '' ? ' - ' . $aciklama : '');
        }

        $liste[] = [
            'tarih'       => $o['tarih'],
            'kaynak'      => 'ODEME',
            'kayit_id'    => (int)$o['kayit_id'],
            'belge_tipi'  => (string)$o['tip_adi'],
            'belge_no'    => (string)($o['belge_no'] ?? ''),
            'aciklama'    => $aciklama,
            'borc'        => $isaret === -1 ? $tutar : 0.0,
            'alacak'      => $isaret === 1  ? $tutar : 0.0,
            'para_birimi' => 'TRY',
            'kur_uyari'   => 0,
        ];
    }

    // Tarih, sonra kaynak ve kayıt no sırası (aynı gün içinde tutarlı sıralama)
    usort($liste, function ($a, $b) {
        if ($a['tarih'] !== $b['tarih'])   { return strcmp($a['tarih'], $b['tarih']); }
        if ($a['kaynak'] !== $b['kaynak']) { return strcmp($a['kaynak'], $b['kaynak']); }
        return $a['kayit_id'] <=> $b['kayit_id'];
    });

    return $liste;
}

/**
 * Bir carinin hareketlerini tarih sıralı, yürüyen bakiyeli olarak döner.
 */
function cariEkstre(Database $db, int $cariId, ?string $bas, ?string $bit): array
{
    // Tarih aralığından önceki hareketlerden devir bakiyesi
    $devir = 0.0;
    if ($bas) {
        $oncekiler = cariHamHareketler($db, $cariId, null, date('Y-m-d', strtotime($bas . ' -1 day')));
        foreach ($oncekiler as $h) {
            $devir += $h['borc'] - $h['alacak'];
        }
    }

    $hareketler = cariHamHareketler($db, $cariId, $bas, $bit);

    $bakiye       = $devir;
    $toplamBorc   = 0.0;
    $toplamAlacak = 0.0;
    foreach ($hareketler as $i => $h) {
        $bakiye       += $h['borc'] - $h['alacak'];
        $toplamBorc   += $h['borc'];
        $toplamAlacak += $h['alacak'];
        $hareketler[$i]['bakiye'] = $bakiye;
    }

    return [
        'devir'      => $devir,
        'hareketler' => $hareketler,
        'ozet'       => [
            'devir'         => $devir,
            'toplam_borc'   => $toplamBorc,
            'toplam_alacak' => $toplamAlacak,
            'bakiye'        => $bakiye,
            'hareket_adedi' => count($hareketler),
        ],
    ];
}

/** Cari kartı bilgisi */
function cariGetir(Database $db, int $cariId): ?array
{
    return $db->fetchOne("
        SELECT cari_id, cari_adi, cari_unvan, cari_vergi_no, cari_vergi_dairesi,
               cari_telefon, cari_email, cari_adres, cari_musteri, cari_tedarikci, cari_aktif
        FROM Cari WHERE cari_id = ?
    ", [$cariId]) ?: null;
}

// ============================================================================
// Dışa aktarım (Excel / Yazdırılabilir mutabakat)
// ============================================================================

$export = $_GET['export'] ?? '';
if ($export === 'csv' || $export === 'print') {
    $cariId = (int)($_GET['cari_id'] ?? 0);
    $bas    = !empty($_GET['bas']) ? $_GET['bas'] : null;
    $bit    = !empty($_GET['bit']) ? $_GET['bit'] : null;
    $cari   = $cariId ? cariGetir($db, $cariId) : null;

    if (!$cari) {
        http_response_code(400);
        exit('Cari bulunamadı.');
    }

    $ekstre   = cariEkstre($db, $cariId, $bas, $bit);
    $firma    = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
    $firmaAdi = $firma['site_ayarlari_site_title'] ?? 'Örnek Soft';

    if ($export === 'csv') {
        $dosya = 'cari-ekstre-' . $cariId . '-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $dosya . '"');
        $cikti = fopen('php://output', 'w');
        fwrite($cikti, "\xEF\xBB\xBF"); // Excel için BOM
        fputcsv($cikti, ['Cari', $cari['cari_adi']], ';');
        fputcsv($cikti, ['Ünvan', $cari['cari_unvan']], ';');
        fputcsv($cikti, ['VKN/TC', $cari['cari_vergi_no']], ';');
        fputcsv($cikti, ['Dönem', ($bas ?: 'Başlangıç') . ' - ' . ($bit ?: 'Bugün')], ';');
        fputcsv($cikti, [], ';');
        fputcsv($cikti, ['Tarih', 'Belge Tipi', 'Belge No', 'Açıklama', 'Borç', 'Alacak', 'Bakiye'], ';');
        if ($ekstre['devir'] != 0) {
            fputcsv($cikti, [$bas, 'Devir Bakiye', '', 'Önceki dönem devri', '', '', number_format($ekstre['devir'], 2, ',', '')], ';');
        }
        foreach ($ekstre['hareketler'] as $h) {
            fputcsv($cikti, [
                $h['tarih'], $h['belge_tipi'], $h['belge_no'], $h['aciklama'],
                $h['borc']   ? number_format($h['borc'], 2, ',', '')   : '',
                $h['alacak'] ? number_format($h['alacak'], 2, ',', '') : '',
                number_format($h['bakiye'], 2, ',', ''),
            ], ';');
        }
        fputcsv($cikti, [], ';');
        fputcsv($cikti, ['', '', '', 'TOPLAM',
            number_format($ekstre['ozet']['toplam_borc'], 2, ',', ''),
            number_format($ekstre['ozet']['toplam_alacak'], 2, ',', ''),
            number_format($ekstre['ozet']['bakiye'], 2, ',', ''),
        ], ';');
        fclose($cikti);
        exit;
    }

    // Yazdırılabilir mutabakat mektubu (tarayıcıdan PDF olarak kaydedilebilir)
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $tl  = function ($v) { return number_format((float)$v, 2, ',', '.') . ' TL'; };
    ?>
    <!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8">
    <title>Cari Mutabakat - <?= $esc($cari['cari_adi']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: #212529; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .ust { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0d6efd; padding-bottom: 12px; margin-bottom: 16px; }
        .kutu { border: 1px solid #dee2e6; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
        .kutu td { border: 0; padding: 2px 6px 2px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dee2e6; padding: 5px 7px; }
        thead th { background: #f1f3f5; text-align: left; font-weight: 600; }
        .sag { text-align: right; }
        .toplam td { font-weight: 700; background: #f8f9fa; }
        .devir td { background: #fff8e1; font-style: italic; }
        .sonuc { margin-top: 18px; padding: 12px; border: 1px solid #dee2e6; border-radius: 4px; background: #f8f9fa; }
        .imza { display: flex; justify-content: space-between; margin-top: 48px; }
        .imza div { width: 45%; border-top: 1px solid #adb5bd; padding-top: 6px; text-align: center; }
        .not { margin-top: 16px; font-size: 11px; color: #6c757d; }
        @media print { body { margin: 0; } .yazdir { display: none; } }
    </style></head><body>
    <div class="yazdir" style="text-align:right;margin-bottom:12px">
        <button onclick="window.print()" style="padding:6px 14px;cursor:pointer">Yazdır / PDF Kaydet</button>
    </div>
    <div class="ust">
        <div><h1>CARİ MUTABAKAT MEKTUBU</h1>
            <div><?= $esc($firmaAdi) ?></div>
            <div>Düzenleme: <?= date('d.m.Y H:i') ?></div></div>
        <div style="text-align:right">
            <div><strong>Dönem</strong></div>
            <div><?= $esc($bas ? date('d.m.Y', strtotime($bas)) : 'Başlangıçtan') ?> - <?= $esc($bit ? date('d.m.Y', strtotime($bit)) : date('d.m.Y')) ?></div>
        </div>
    </div>
    <div class="kutu"><table>
        <tr><td width="110"><strong>Cari</strong></td><td><?= $esc($cari['cari_adi']) ?></td>
            <td width="110"><strong>VKN/TC</strong></td><td><?= $esc($cari['cari_vergi_no'] ?: '-') ?></td></tr>
        <tr><td><strong>Ünvan</strong></td><td><?= $esc($cari['cari_unvan'] ?: '-') ?></td>
            <td><strong>Vergi Dairesi</strong></td><td><?= $esc($cari['cari_vergi_dairesi'] ?: '-') ?></td></tr>
        <tr><td><strong>Telefon</strong></td><td><?= $esc($cari['cari_telefon'] ?: '-') ?></td>
            <td><strong>E-Posta</strong></td><td><?= $esc($cari['cari_email'] ?: '-') ?></td></tr>
    </table></div>
    <table>
        <thead><tr><th width="80">Tarih</th><th width="120">Belge Tipi</th><th width="110">Belge No</th>
            <th>Açıklama</th><th width="95" class="sag">Borç</th><th width="95" class="sag">Alacak</th><th width="105" class="sag">Bakiye</th></tr></thead>
        <tbody>
        <?php if ($ekstre['devir'] != 0): ?>
            <tr class="devir"><td><?= $esc($bas ? date('d.m.Y', strtotime($bas)) : '') ?></td><td>Devir</td><td>-</td>
                <td>Önceki dönemden devreden bakiye</td><td class="sag">-</td><td class="sag">-</td>
                <td class="sag"><?= $tl($ekstre['devir']) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($ekstre['hareketler'] as $h): ?>
            <tr><td><?= $esc(date('d.m.Y', strtotime($h['tarih']))) ?></td>
                <td><?= $esc($h['belge_tipi']) ?></td>
                <td><?= $esc($h['belge_no'] ?: '-') ?></td>
                <td><?= $esc($h['aciklama']) ?></td>
                <td class="sag"><?= $h['borc'] ? $tl($h['borc']) : '' ?></td>
                <td class="sag"><?= $h['alacak'] ? $tl($h['alacak']) : '' ?></td>
                <td class="sag"><?= $tl($h['bakiye']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$ekstre['hareketler']): ?>
            <tr><td colspan="7" style="text-align:center;color:#6c757d">Seçilen dönemde hareket bulunmamaktadır.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr class="toplam"><td colspan="4" class="sag">TOPLAM</td>
            <td class="sag"><?= $tl($ekstre['ozet']['toplam_borc']) ?></td>
            <td class="sag"><?= $tl($ekstre['ozet']['toplam_alacak']) ?></td>
            <td class="sag"><?= $tl($ekstre['ozet']['bakiye']) ?></td></tr></tfoot>
    </table>
    <div class="sonuc">
        <?php $bak = $ekstre['ozet']['bakiye']; ?>
        <?= date('d.m.Y', strtotime($bit ?: date('Y-m-d'))) ?> tarihi itibarıyla hesabınız
        <strong><?= $tl(abs($bak)) ?></strong>
        <strong><?= $bak > 0 ? 'BORÇ' : ($bak < 0 ? 'ALACAK' : 'SIFIR') ?></strong> bakiye vermektedir.
        Mutabık iseniz kaşe ve imza ile tarafımıza iletmenizi, mutabık değilseniz hesap ekstrenizi
        göndermenizi rica ederiz.
    </div>
    <div class="imza"><div>Düzenleyen<br><?= $esc($firmaAdi) ?></div><div>Mutabıkız<br>Kaşe / İmza</div></div>
    <div class="not">Bu ekstre gelen faturalar, giden faturalar ve ödeme hareketleri esas alınarak hazırlanmıştır. Tutarlar TL'dir.</div>
    </body></html>
    <?php
    exit;
}

// ============================================================================
// AJAX işlemleri
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Seçili carinin ekstresi + özeti
            case 'ekstre':
                $cariId = (int)($_POST['cari_id'] ?? 0);
                $bas    = !empty($_POST['bas']) ? $_POST['bas'] : null;
                $bit    = !empty($_POST['bit']) ? $_POST['bit'] : null;
                if (!$cariId) {
                    echo json_encode(['success' => false, 'message' => 'Cari seçilmedi.']);
                    exit;
                }

                $cari = cariGetir($db, $cariId);
                if (!$cari) {
                    echo json_encode(['success' => false, 'message' => 'Cari bulunamadı.']);
                    exit;
                }

                $ekstre = cariEkstre($db, $cariId, $bas, $bit);

                // Bu cariye VKN'si uyduğu halde bağlanmamış fatura var mı?
                $vkn      = vknNormalize($cari['cari_vergi_no']);
                $bekleyen = 0;
                if ($vkn !== '') {
                    $satir = $db->fetchOne("
                        SELECT COUNT(*) AS adet FROM Faturalar
                        WHERE Faturalar_Cari_id IS NULL AND Faturalar_Durum = 1
                          AND Faturalar_IptalDurumu = 0
                          AND REPLACE(REPLACE(LTRIM(RTRIM(Faturalar_CariVKN)),' ',''),'-','') = ?
                    ", [$vkn]);
                    $bekleyen = (int)($satir['adet'] ?? 0);
                }

                echo json_encode([
                    'success'  => true,
                    'cari'     => $cari,
                    'ozet'     => $ekstre['ozet'],
                    'data'     => $ekstre['hareketler'],
                    'bekleyen' => $bekleyen,
                ]);
                exit;

            // Cariye bağlanmamış faturalar (eşleştirme ekranı)
            case 'bagsiz_faturalar':
                $arama  = trim($_POST['arama'] ?? '');
                $cariId = (int)($_POST['cari_id'] ?? 0);
                // İptal edilmiş faturalar cariye bağlanmaz, listede de gösterilmez
                $where  = ['f.Faturalar_Cari_id IS NULL', 'f.Faturalar_Durum = 1', 'f.Faturalar_IptalDurumu = 0'];
                $params = [];

                if ($arama !== '') {
                    $like = '%' . $arama . '%';
                    $where[] = "(f.Faturalar_CariUnvan LIKE ? OR f.Faturalar_CariVKN LIKE ? OR f.Faturalar_FaturaNo LIKE ?)";
                    array_push($params, $like, $like, $like);
                } elseif ($cariId) {
                    // Arama yoksa seçili carinin VKN'sine uyanları göster
                    $cari = cariGetir($db, $cariId);
                    $vkn  = vknNormalize($cari['cari_vergi_no'] ?? '');
                    if ($vkn !== '') {
                        $where[]  = "REPLACE(REPLACE(LTRIM(RTRIM(f.Faturalar_CariVKN)),' ',''),'-','') = ?";
                        $params[] = $vkn;
                    }
                }

                $data = $db->fetchAll("
                    SELECT TOP 300
                        f.Faturalar_id, f.Faturalar_Yon, f.Faturalar_Tur, f.Faturalar_FaturaNo,
                        CONVERT(VARCHAR(10), f.Faturalar_Tarih, 23) AS Faturalar_Tarih,
                        f.Faturalar_CariUnvan, f.Faturalar_CariVKN,
                        f.Faturalar_OdenecekTutar, f.Faturalar_ParaBirimi
                    FROM Faturalar f
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY f.Faturalar_Tarih DESC, f.Faturalar_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                exit;

            // Seçili faturaları cariye bağla / bağı kaldır
            case 'fatura_ata':
                if (empty($permissions['can_edit'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok.']);
                    exit;
                }
                $cariId = (int)($_POST['cari_id'] ?? 0);
                $idler  = json_decode($_POST['fatura_idler'] ?? '[]', true);
                $idler  = array_values(array_filter(array_map('intval', (array)$idler)));

                if (!$idler) {
                    echo json_encode(['success' => false, 'message' => 'Fatura seçilmedi.']);
                    exit;
                }
                if ($cariId && !cariGetir($db, $cariId)) {
                    echo json_encode(['success' => false, 'message' => 'Cari bulunamadı.']);
                    exit;
                }

                $isaret = implode(',', array_fill(0, count($idler), '?'));
                $params = array_merge([$cariId ?: null, $user['kullanici_id'], date('Y-m-d H:i:s')], $idler);
                $db->execute("
                    UPDATE Faturalar
                       SET Faturalar_Cari_id = ?,
                           Faturalar_GuncelleyenKullanici = ?,
                           Faturalar_GuncellemeTarihi = ?
                     WHERE Faturalar_id IN ($isaret)
                ", $params);

                echo json_encode([
                    'success' => true,
                    'message' => count($idler) . ' fatura ' . ($cariId ? 'cariye bağlandı.' : 'bağı kaldırıldı.'),
                ]);
                exit;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Cari listesi (Select2 ile aranabilir)
$cariler = $db->fetchAll("
    SELECT cari_id, cari_adi, cari_unvan, cari_vergi_no, cari_musteri, cari_tedarikci
    FROM Cari WHERE cari_aktif = 1 ORDER BY cari_adi
");
$secilenCari = (int)($_GET['cari_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
<link rel="stylesheet" href="/admin/assets/css/custom.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<style>
    .ekstre-tablo tbody td { vertical-align: middle; }
    .bakiye-borc   { color: #dc3545; font-weight: 600; }
    .bakiye-alacak { color: #198754; font-weight: 600; }
</style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<main class="app-main">
<div class="app-content-header"><div class="container-fluid"><div class="row">
    <div class="col-sm-6"><h1 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h1></div>
    <div class="col-sm-6"><ol class="breadcrumb float-sm-end">
        <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
    </ol></div>
</div></div></div>

<div class="app-content"><div class="container-fluid">

    <!-- Özet kutuları -->
    <div class="row mb-3">
        <div class="col-12 col-sm-6 col-md-3"><div class="info-box">
            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-clock-history"></i></span>
            <div class="info-box-content"><span class="info-box-text">Devir Bakiye</span>
                <span class="info-box-number" id="stat-devir">0,00 TL</span></div></div></div>
        <div class="col-12 col-sm-6 col-md-3"><div class="info-box">
            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-arrow-down-circle"></i></span>
            <div class="info-box-content"><span class="info-box-text">Toplam Borç</span>
                <span class="info-box-number" id="stat-borc">0,00 TL</span></div></div></div>
        <div class="col-12 col-sm-6 col-md-3"><div class="info-box">
            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-arrow-up-circle"></i></span>
            <div class="info-box-content"><span class="info-box-text">Toplam Alacak</span>
                <span class="info-box-number" id="stat-alacak">0,00 TL</span></div></div></div>
        <div class="col-12 col-sm-6 col-md-3"><div class="info-box">
            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calculator"></i></span>
            <div class="info-box-content"><span class="info-box-text">Bakiye</span>
                <span class="info-box-number" id="stat-bakiye">0,00 TL</span>
                <span class="info-box-text small" id="stat-bakiye-yon"></span></div></div></div>
    </div>

    <!-- Filtre -->
    <div class="card card-primary card-outline mb-3 collapse show" id="filterCard">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-funnel"></i> Cari ve Dönem Seçimi</h3></div>
        <div class="card-body">
            <form id="filterForm"><div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Cari <span class="text-danger">*</span></label>
                    <select class="form-select" id="filter_cari" required>
                        <option value="">Cari seçiniz...</option>
                        <?php foreach ($cariler as $c): ?>
                            <option value="<?= (int)$c['cari_id'] ?>" <?= $secilenCari === (int)$c['cari_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['cari_adi']) ?><?= $c['cari_vergi_no'] ? ' (' . htmlspecialchars($c['cari_vergi_no']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label">Başlangıç</label>
                    <input type="date" class="form-control" id="filter_bas"></div>
                <div class="col-md-2"><label class="form-label">Bitiş</label>
                    <input type="date" class="form-control" id="filter_bit"></div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Getir</button>
                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x"></i> Temizle</button>
                </div>
            </div></form>
        </div>
    </div>

    <!-- Cari kart bilgisi -->
    <div class="card mb-3 d-none" id="cariKart"><div class="card-body py-2">
        <div class="row g-2 small">
            <div class="col-md-4"><strong>Cari:</strong> <span id="ck-adi">-</span> <span id="ck-tip"></span></div>
            <div class="col-md-4"><strong>Ünvan:</strong> <span id="ck-unvan">-</span></div>
            <div class="col-md-2"><strong>VKN/TC:</strong> <span id="ck-vkn">-</span></div>
            <div class="col-md-2"><strong>Telefon:</strong> <span id="ck-tel">-</span></div>
        </div>
    </div></div>

    <!-- Bağlanmamış fatura uyarısı -->
    <div class="alert alert-warning d-none" id="bekleyenUyari">
        <i class="bi bi-exclamation-triangle"></i>
        Bu carinin VKN'si ile eşleşen <strong><span id="bekleyenAdet">0</span></strong> adet cariye bağlanmamış fatura var.
        <button class="btn btn-sm btn-warning ms-2" onclick="openEslestir()"><i class="bi bi-link-45deg"></i> Faturaları Eşleştir</button>
    </div>

    <!-- Ekstre -->
    <div class="card card-primary card-outline">
        <div class="card-header">
            <h3 class="card-title"><i class="bi bi-journal-text"></i> Cari Ekstresi</h3>
            <div class="card-tools">
                <a class="btn btn-sm btn-success" id="btnOdemeGir" href="#" style="display:none">
                    <i class="bi bi-cash-coin"></i> Tahsilat / Ödeme Gir</a>
                <?php if (!empty($permissions['can_edit'])): ?>
                <button class="btn btn-sm btn-outline-warning" onclick="openEslestir()">
                    <i class="bi bi-link-45deg"></i> Fatura Eşleştir</button>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-success" id="btnExcel" disabled>
                    <i class="bi bi-file-earmark-excel"></i> Excel</button>
                <button class="btn btn-sm btn-outline-danger" id="btnPdf" disabled>
                    <i class="bi bi-file-earmark-pdf"></i> Mutabakat (PDF)</button>
                <button class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                    <i class="bi bi-funnel"></i> Filtrele</button>
            </div>
        </div>
        <div class="card-body">
            <table class="table table-bordered table-striped table-hover table-sm ekstre-tablo w-100" id="ekstreTable">
                <thead><tr>
                    <th>Tarih</th><th>Belge Tipi</th><th>Belge No</th><th>Açıklama</th>
                    <th class="text-end">Borç</th><th class="text-end">Alacak</th><th class="text-end">Bakiye</th>
                </tr></thead>
                <tbody></tbody>
                <tfoot><tr class="table-light fw-bold">
                    <td colspan="4" class="text-end">TOPLAM</td>
                    <td class="text-end" id="ft-borc">0,00 TL</td>
                    <td class="text-end" id="ft-alacak">0,00 TL</td>
                    <td class="text-end" id="ft-bakiye">0,00 TL</td>
                </tr></tfoot>
            </table>
        </div>
    </div>

</div></div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Fatura eşleştirme -->
<div class="modal fade" id="eslestirModal" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-link-45deg"></i> Fatura - Cari Eşleştirme</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle"></i> VKN'si cari kartla birebir aynı olan faturalar otomatik bağlanır.
            Burada listelenenler VKN'si eşleşmeyen veya boş olan faturalardır; seçip
            <strong id="es-cari-adi">seçili cariye</strong> elle bağlayabilirsiniz.
        </div>
        <div class="row g-2 mb-2">
            <div class="col-md-8"><input type="text" class="form-control" id="es_arama"
                placeholder="Fatura no / cari ünvan / VKN ile ara..."></div>
            <div class="col-md-4"><button class="btn btn-primary w-100" onclick="loadBagsiz()">
                <i class="bi bi-search"></i> Ara</button></div>
        </div>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm table-bordered table-hover">
                <thead class="table-light sticky-top"><tr>
                    <th width="34"><input type="checkbox" class="form-check-input" id="es_tum"></th>
                    <th>Tarih</th><th>Yön</th><th>Fatura No</th><th>Cari Ünvan (fatura)</th>
                    <th>VKN</th><th class="text-end">Tutar</th>
                </tr></thead>
                <tbody id="es_body"><tr><td colspan="7" class="text-center text-muted">Yükleniyor...</td></tr></tbody>
            </table>
        </div>
        <div class="text-muted small mt-1" id="es_bilgi"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
        <button class="btn btn-primary" onclick="faturaAta()"><i class="bi bi-check2"></i> Seçilenleri Cariye Bağla</button>
    </div>
</div></div></div>

<?php include __DIR__ . '/../includes/scripts.php'; ?>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script>
let ekstreTable, aktifCari = 0, eslestirModal;

$(function () {
    eslestirModal = new bootstrap.Modal(document.getElementById('eslestirModal'));
    $('#filter_cari').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Cari seçiniz...' });

    // Yürüyen bakiye satır sırasına bağlı olduğu için tablo sıralaması kapalıdır
    ekstreTable = $('#ekstreTable').DataTable({
        data: [], ordering: false, scrollX: true, pageLength: 25,
        lengthMenu: [10, 25, 50, 100, 250],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        columns: [
            { data: 'tarih', render: d => tarihFmt(d) },
            { data: 'belge_tipi', render: (d, t, r) => rozet(r) },
            { data: 'belge_no', render: d => esc(d || '-') },
            { data: 'aciklama', render: (d, t, r) => esc(d) + (r.kur_uyari == 1
                ? ' <span class="badge bg-warning text-dark" title="Kur bilgisi olmadığı için tutar TL karşılığına çevrilemedi">' + esc(r.para_birimi) + '</span>' : '') },
            { data: 'borc',   className: 'text-end', render: d => d ? tl(d) : '' },
            { data: 'alacak', className: 'text-end', render: d => d ? tl(d) : '' },
            { data: 'bakiye', className: 'text-end',
              render: d => '<span class="' + (d > 0 ? 'bakiye-borc' : (d < 0 ? 'bakiye-alacak' : '')) + '">' + tl(d) + '</span>' }
        ]
    });

    $('#filterForm').on('submit', function (e) { e.preventDefault(); loadEkstre(); });
    $('#clearFilters').on('click', function () {
        $('#filter_cari').val('').trigger('change');
        $('#filter_bas, #filter_bit').val('');
        temizle();
    });
    $('#btnExcel').on('click', function () { disaAktar('csv'); });
    $('#btnPdf').on('click', function () { disaAktar('print'); });
    $('#es_tum').on('change', function () { $('#es_body input.es-sec').prop('checked', this.checked); });
    $('#es_arama').on('keypress', function (e) { if (e.which === 13) loadBagsiz(); });

    if ($('#filter_cari').val()) loadEkstre();
});

function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v).replace(/[&<>"']/g, k => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[k]));
}
function tl(v) { return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(v || 0); }
function tarihFmt(d) { if (!d) return '-'; const p = String(d).split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : d; }
function rozet(r) {
    const renk = r.kaynak === 'ODEME' ? 'info' : (r.borc > 0 ? 'primary' : 'success');
    return '<span class="badge bg-' + renk + '">' + esc(r.belge_tipi) + '</span>';
}

function temizle() {
    aktifCari = 0;
    ekstreTable.clear().draw();
    $('#cariKart').addClass('d-none');
    $('#bekleyenUyari').addClass('d-none');
    $('#btnExcel, #btnPdf').prop('disabled', true);
    $('#btnOdemeGir').hide();
    ['#stat-devir','#stat-borc','#stat-alacak','#stat-bakiye','#ft-borc','#ft-alacak','#ft-bakiye']
        .forEach(function (s) { $(s).text(tl(0)); });
    $('#stat-bakiye-yon').text('');
}

function loadEkstre() {
    const cariId = $('#filter_cari').val();
    if (!cariId) { showWarning('Uyarı', 'Lütfen bir cari seçiniz.'); return; }

    $.post('', { action: 'ekstre', cari_id: cariId, bas: $('#filter_bas').val(), bit: $('#filter_bit').val() }, function (r) {
        if (!r.success) { showError('Hata', r.message || 'Ekstre alınamadı.'); return; }

        aktifCari = parseInt(cariId, 10);
        ekstreTable.clear().rows.add(r.data).draw();

        const o = r.ozet;
        $('#stat-devir').text(tl(o.devir));
        $('#stat-borc').text(tl(o.toplam_borc));
        $('#stat-alacak').text(tl(o.toplam_alacak));
        $('#stat-bakiye').text(tl(Math.abs(o.bakiye)));
        $('#stat-bakiye-yon').text(o.bakiye > 0 ? 'Cari bize borçlu' : (o.bakiye < 0 ? 'Cariye borçluyuz' : 'Mutabık'));
        $('#ft-borc').text(tl(o.toplam_borc));
        $('#ft-alacak').text(tl(o.toplam_alacak));
        $('#ft-bakiye').text(tl(o.bakiye));

        const c = r.cari;
        let tip = '';
        if (c.cari_musteri == 1)   tip += '<span class="badge bg-primary ms-1">Müşteri</span>';
        if (c.cari_tedarikci == 1) tip += '<span class="badge bg-success ms-1">Tedarikçi</span>';
        $('#ck-adi').text(c.cari_adi); $('#ck-tip').html(tip);
        $('#ck-unvan').text(c.cari_unvan || '-');
        $('#ck-vkn').text(c.cari_vergi_no || '-');
        $('#ck-tel').text(c.cari_telefon || '-');
        $('#cariKart').removeClass('d-none');
        $('#es-cari-adi').text(c.cari_adi);
        $('#btnExcel, #btnPdf').prop('disabled', false);
        $('#btnOdemeGir').attr('href', '/admin/pages/odeme-hareket-form.php?cari_id=' + aktifCari).show();

        if (r.bekleyen > 0) { $('#bekleyenAdet').text(r.bekleyen); $('#bekleyenUyari').removeClass('d-none'); }
        else { $('#bekleyenUyari').addClass('d-none'); }
    }, 'json').fail(function () { showError('Hata', 'Sunucuya ulaşılamadı.'); });
}

function disaAktar(tip) {
    if (!aktifCari) return;
    const q = new URLSearchParams({ export: tip, cari_id: aktifCari,
        bas: $('#filter_bas').val() || '', bit: $('#filter_bit').val() || '' });
    if (tip === 'csv') window.location = '?' + q.toString();
    else window.open('?' + q.toString(), '_blank');
}

function openEslestir() {
    if (!$('#filter_cari').val()) { showWarning('Uyarı', 'Önce bir cari seçiniz.'); return; }
    $('#es-cari-adi').text($('#filter_cari option:selected').text());
    $('#es_arama').val('');
    eslestirModal.show();
    loadBagsiz();
}

function loadBagsiz() {
    $('#es_body').html('<tr><td colspan="7" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>');
    $.post('', { action: 'bagsiz_faturalar', arama: $('#es_arama').val(), cari_id: $('#filter_cari').val() }, function (r) {
        const tb = $('#es_body').empty();
        $('#es_tum').prop('checked', false);
        if (!r.success || !r.data.length) {
            tb.html('<tr><td colspan="7" class="text-center text-muted">Bağlanmamış fatura bulunamadı.</td></tr>');
            $('#es_bilgi').text('');
            return;
        }
        r.data.forEach(function (f) {
            const yon = f.Faturalar_Yon === 'GIDEN'
                ? '<span class="badge bg-primary">Giden</span>' : '<span class="badge bg-success">Gelen</span>';
            tb.append('<tr><td><input type="checkbox" class="form-check-input es-sec" value="' + f.Faturalar_id + '"></td>'
                + '<td>' + tarihFmt(f.Faturalar_Tarih) + '</td><td>' + yon + '</td>'
                + '<td><small class="fw-bold">' + esc(f.Faturalar_FaturaNo) + '</small></td>'
                + '<td>' + esc(f.Faturalar_CariUnvan || '-') + '</td>'
                + '<td><small>' + esc(f.Faturalar_CariVKN || '-') + '</small></td>'
                + '<td class="text-end">' + tl(f.Faturalar_OdenecekTutar) + '</td></tr>');
        });
        $('#es_bilgi').text(r.count + ' fatura listelendi' + (r.count === 300 ? ' (ilk 300 kayıt, aramayı daraltın)' : '') + '.');
    }, 'json');
}

function faturaAta() {
    const idler = $('#es_body input.es-sec:checked').map(function () { return parseInt(this.value, 10); }).get();
    if (!idler.length) { showWarning('Uyarı', 'En az bir fatura seçiniz.'); return; }
    const cariId = $('#filter_cari').val();

    confirmAction('Faturalar bağlansın mı?',
        idler.length + ' fatura "' + $('#filter_cari option:selected').text() + '" carisine bağlanacak.',
        function () {
            $.post('', { action: 'fatura_ata', cari_id: cariId, fatura_idler: JSON.stringify(idler) }, function (r) {
                if (!r.success) { showError('Hata', r.message); return; }
                showToast(r.message, 'success');
                eslestirModal.hide();
                loadEkstre();
            }, 'json');
        });
}
</script>
</body>
</html>
