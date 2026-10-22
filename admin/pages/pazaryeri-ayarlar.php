<?php
/**
 * Admin Panel - Pazaryeri Ayarları
 *
 * İki işi var:
 *   1. Pazaryerlerinin durumu: hangisi hazır, hangisinin entegrasyonu yazılmamış,
 *      hangisinin kimlik bilgisi eksik. Aktif/pasif ve bağlantı testi buradan.
 *   2. Ayar zinciri: bir ayar önce kanala (firmaya), sonra pazaryerine, en son
 *      genel varsayılana bakılarak çözülür. İlk dolu değer kullanılır.
 *
 * Kanal = bir firmanın bir pazaryerindeki hesabı (EntegrasyonKanallari).
 * API kimlik bilgileri EntegrasyonKanallari_AyarJSON'a yazılır; gizli alanların
 * değeri istemciye hiçbir zaman gönderilmez.
 *
 * Ayar anahtarları koda gömülmez; mevcut kayıtlardan öneri olarak sunulur.
 * Kaynak: Örnek Müşteri admin/pages/pazaryeri-ayarlar.php (portal şemasına uyarlandı).
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

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Pazaryeri Ayarları';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Firma kısıtı: null → kısıt yok, >0 → yalnız o firma, 0 → hiç kayıt
$kisitFirmaId = PageAuth::firmaKisiti($pagePermissions, $user['kullanici_id']);

// Pazaryerinin kendisi ve genel/pazaryeri kapsamlı ayarlar tüm firmaları etkiler;
// bunları yalnız firma kısıtı olmayan ve düzenleme yetkisi olan kullanıcı değiştirir.
$globalYetki = $kisitFirmaId === null && !empty($pagePermissions['can_edit']);

/**
 * Pazaryeri entegrasyonuna bağlı kanallar (pasifler dahil).
 * PazaryeriFabrika::kanallar() yalnız aktif kanalları döndürdüğü için
 * yönetim ekranı kendi sorgusunu kullanır; aksi halde pasife alınan kanal
 * listeden düşer ve yeniden aktif edilemez.
 */
function pzKanalListesi($db, $kisitFirmaId, $kanalId = null)
{
    [$kisitSql, $params] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.EntegrasyonKanallari_Firmalar_id');

    $sql = "
        SELECT
            k.EntegrasyonKanallari_id,
            k.EntegrasyonKanallari_Ad,
            k.EntegrasyonKanallari_AyarJSON,
            CAST(k.EntegrasyonKanallari_Varsayilan AS INT) AS EntegrasyonKanallari_Varsayilan,
            CAST(k.EntegrasyonKanallari_Durum AS INT)      AS EntegrasyonKanallari_Durum,
            k.EntegrasyonKanallari_Entegrasyonlar_id,
            k.EntegrasyonKanallari_Firmalar_id,
            f.firma_adi,
            f.firma_unvan,
            e.Entegrasyonlar_Ad,
            p.Pazaryerleri_id,
            p.Pazaryerleri_Kod,
            p.Pazaryerleri_Ad,
            p.Pazaryerleri_Renk,
            p.Pazaryerleri_Ikon
        FROM dbo.EntegrasyonKanallari k
        INNER JOIN dbo.Entegrasyonlar e
            ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
        INNER JOIN dbo.tanim_Pazaryerleri p
            ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
        LEFT JOIN dbo.Firmalar f
            ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
        WHERE (k.EntegrasyonKanallari_Firmalar_id IS NULL OR f.firma_durum = 1)
        {$kisitSql}
    ";

    if ($kanalId !== null) {
        $sql .= " AND k.EntegrasyonKanallari_id = ? ";
        $params[] = (int)$kanalId;
    }

    $sql .= " ORDER BY p.Pazaryerleri_Sira, k.EntegrasyonKanallari_Varsayilan DESC, k.EntegrasyonKanallari_Ad";

    return $db->fetchAll($sql, $params);
}

/** Kullanıcının erişebildiği tek kanal; yoksa null */
function pzKanalErisim($db, $kisitFirmaId, $kanalId)
{
    if ((int)$kanalId <= 0) {
        return null;
    }
    $liste = pzKanalListesi($db, $kisitFirmaId, (int)$kanalId);
    return $liste[0] ?? null;
}

/** Bir anahtar gizli mi: sınıfın bildirimi veya ortak gizli anahtar listesi */
function pzGizliMi($anahtar, array $gizliHarita)
{
    return !empty($gizliHarita[$anahtar])
        || in_array($anahtar, PazaryeriApi::GIZLI_ANAHTARLAR, true);
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
    $simdi  = date('Y-m-d H:i:s');

    try {
        switch ($action) {

            // ---------------------------------------------------------
            // InfoBox
            case 'stats':
                $pazaryerleri = PazaryeriFabrika::pazaryerleri(false, $kisitFirmaId);
                $hazir = 0;
                foreach ($pazaryerleri as $p) {
                    if ($p['kullanilabilir']) $hazir++;
                }

                [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.EntegrasyonKanallari_Firmalar_id');
                $kanal = $db->fetchOne("
                    SELECT COUNT(*) AS adet
                    FROM dbo.EntegrasyonKanallari k
                    INNER JOIN dbo.Entegrasyonlar e ON e.Entegrasyonlar_id = k.EntegrasyonKanallari_Entegrasyonlar_id
                    INNER JOIN dbo.tanim_Pazaryerleri p ON p.Pazaryerleri_EntegrasyonKod = e.Entegrasyonlar_Kod
                    WHERE k.EntegrasyonKanallari_Durum = 1
                      AND " . PazaryeriFabrika::aktifKanalSql('k.EntegrasyonKanallari_id') . $kisitSql,
                    $kisitParams);

                $ayar = $db->fetchOne("
                    SELECT COUNT(*) AS adet FROM dbo.PazaryeriAyarlari WHERE PazaryeriAyarlari_Durum = 1
                ");

                $cik(['success' => true, 'data' => [
                    'toplam' => count($pazaryerleri),
                    'hazir'  => $hazir,
                    'kanal'  => (int)($kanal['adet'] ?? 0),
                    'ayar'   => (int)($ayar['adet'] ?? 0)
                ]]);

            // ---------------------------------------------------------
            case 'pazaryerleri':
                // Kartta pazaryerinin tüm kanalları listelenir (her biri ayrı test edilir)
                $kanalGrup = [];
                foreach (pzKanalListesi($db, $kisitFirmaId) as $k) {
                    $kanalGrup[(int)$k['Pazaryerleri_id']][] = [
                        'EntegrasyonKanallari_id'         => (int)$k['EntegrasyonKanallari_id'],
                        'EntegrasyonKanallari_Ad'         => $k['EntegrasyonKanallari_Ad'],
                        'EntegrasyonKanallari_Durum'      => (int)$k['EntegrasyonKanallari_Durum'],
                        'EntegrasyonKanallari_Varsayilan' => (int)$k['EntegrasyonKanallari_Varsayilan'],
                        'firma_adi'                       => $k['firma_adi']
                    ];
                }
                $liste = PazaryeriFabrika::pazaryerleri(false, $kisitFirmaId);
                foreach ($liste as &$p) {
                    $p['kanallar'] = $kanalGrup[(int)$p['Pazaryerleri_id']] ?? [];
                }
                unset($p);
                $cik(['success' => true, 'data' => $liste]);

            // ---------------------------------------------------------
            case 'pazaryeriDurum':
                if (!$globalYetki) {
                    $cik(['success' => false, 'message' => 'Pazaryeri durumunu değiştirme yetkiniz yok.']);
                }
                $db->update('tanim_Pazaryerleri', [
                    'Pazaryerleri_Durum'                => !empty($_POST['durum']) ? 1 : 0,
                    'Pazaryerleri_GuncelleyenKullanici' => $user['kullanici_id'],
                    'Pazaryerleri_GuncellemeTarihi'     => $simdi
                ], ['Pazaryerleri_id' => (int)($_POST['id'] ?? 0)]);
                $cik(['success' => true, 'message' => 'Durum güncellendi.']);

            // ---------------------------------------------------------
            // Kanal verilmişse o kanal; verilmemişse pazaryerinin varsayılan kanalı.
            // Firma kısıtlı kullanıcı varsayılan kanalı kullanamaz (başka firmanın olabilir).
            case 'baglantiTest':
                $kanalId = (int)($_POST['kanal_id'] ?? 0);

                if ($kanalId > 0) {
                    if (!pzKanalErisim($db, $kisitFirmaId, $kanalId)) {
                        $cik(['success' => false, 'message' => 'Kanal bulunamadı veya erişim yetkiniz yok.']);
                    }
                    $api = PazaryeriFabrika::olusturKanaldan($kanalId, $user['kullanici_id']);
                } else {
                    if ($kisitFirmaId !== null) {
                        $cik(['success' => false, 'message' => 'Test için kanal seçilmedi.']);
                    }
                    $api = PazaryeriFabrika::olustur($_POST['kod'] ?? '', $user['kullanici_id']);
                }

                if (!$api) {
                    $cik(['success' => false, 'message' => PazaryeriFabrika::sonHata()]);
                }
                if (!$api->hazirMi()) {
                    $cik(['success' => false, 'message' => $api->getHata()]);
                }

                $sonuc = $api->baglantiTest();
                $cik([
                    'success' => !empty($sonuc['basarili']),
                    'message' => $sonuc['mesaj'] ?? ($sonuc['hata'] ?? 'Bilinmeyen sonuç')
                ]);

            // ---------------------------------------------------------
            case 'kanallar':
                $liste = pzKanalListesi($db, $kisitFirmaId);
                foreach ($liste as &$k) {
                    unset($k['EntegrasyonKanallari_AyarJSON']);   // kimlik bilgisi listeye girmez
                }
                unset($k);
                $cik(['success' => true, 'data' => $liste]);

            // ---------------------------------------------------------
            case 'kanalFirma':
                if (empty($pagePermissions['can_edit']) || $kisitFirmaId !== null) {
                    $cik(['success' => false, 'message' => 'Kanalın firmasını değiştirme yetkiniz yok.']);
                }
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $firmaId = (int)($_POST['firma_id'] ?? 0) ?: null;

                if (!pzKanalErisim($db, $kisitFirmaId, $kanalId)) {
                    $cik(['success' => false, 'message' => 'Kanal bulunamadı.']);
                }
                if ($firmaId !== null && !in_array($firmaId, array_map('intval', array_column(PazaryeriFabrika::firmalar(), 'firma_id')), true)) {
                    $cik(['success' => false, 'message' => 'Firma bulunamadı veya pasif.']);
                }

                $db->update('EntegrasyonKanallari', [
                    'EntegrasyonKanallari_Firmalar_id'         => $firmaId,
                    'EntegrasyonKanallari_GuncelleyenKullanici' => $user['kullanici_id'],
                    'EntegrasyonKanallari_GuncellemeTarihi'     => $simdi
                ], ['EntegrasyonKanallari_id' => $kanalId]);
                $cik(['success' => true, 'message' => 'Kanalın firması güncellendi.']);

            // ---------------------------------------------------------
            // Kanalın kimlik bilgilerini düzenleme formu için gerekli veriler.
            // Gizli alanların DEĞERİ İSTEMCİYE GÖNDERİLMEZ; yalnızca dolu olup
            // olmadığı bildirilir.
            case 'kanalDetay':
                $kanal = pzKanalErisim($db, $kisitFirmaId, (int)($_POST['kanal_id'] ?? 0));
                if (!$kanal) {
                    $cik(['success' => false, 'message' => 'Kanal bulunamadı.']);
                }

                $mevcut  = json_decode($kanal['EntegrasyonKanallari_AyarJSON'] ?? '{}', true) ?: [];
                $alanlar = PazaryeriFabrika::kimlikAlanlari($kanal['Pazaryerleri_Kod'] ?? '');

                // Sınıfın bildirmediği ama kayıtta bulunan anahtarlar da gösterilir
                $bilinen = array_column($alanlar, 'anahtar');
                foreach (array_keys($mevcut) as $anahtar) {
                    if (!in_array($anahtar, $bilinen, true)) {
                        $alanlar[] = [
                            'anahtar'  => $anahtar,
                            'etiket'   => $anahtar,
                            'gizli'    => false,
                            'zorunlu'  => false,
                            'aciklama' => 'Kayıtta bulunan ek alan'
                        ];
                    }
                }

                // Değerleri hazırla: gizli olanlar boş gider
                foreach ($alanlar as &$a) {
                    $a['gizli'] = pzGizliMi($a['anahtar'], [$a['anahtar'] => !empty($a['gizli'])]);
                    $deger = $mevcut[$a['anahtar']] ?? '';
                    $deger = is_scalar($deger) ? (string)$deger : json_encode($deger, $jsonBayrak);
                    $a['dolu']  = $deger !== '';
                    $a['deger'] = $a['gizli'] ? '' : $deger;
                }
                unset($a);

                $cik(['success' => true, 'data' => [
                    'kanal_id'   => (int)$kanal['EntegrasyonKanallari_id'],
                    'kanal_adi'  => $kanal['EntegrasyonKanallari_Ad'],
                    'firma_id'   => $kanal['EntegrasyonKanallari_Firmalar_id'],
                    'varsayilan' => (int)$kanal['EntegrasyonKanallari_Varsayilan'],
                    'durum'      => (int)$kanal['EntegrasyonKanallari_Durum'],
                    'pazaryeri'  => $kanal['Pazaryerleri_Ad'] ?? $kanal['Entegrasyonlar_Ad'],
                    'alanlar'    => $alanlar
                ]]);

            // ---------------------------------------------------------
            // Yeni kanal eklerken hangi alanların isteneceği
            case 'kanalAlanlari':
                $alanlar = PazaryeriFabrika::kimlikAlanlari($_POST['pazaryeri_kod'] ?? '');
                foreach ($alanlar as &$a) {
                    $a['gizli'] = pzGizliMi($a['anahtar'], [$a['anahtar'] => !empty($a['gizli'])]);
                }
                unset($a);
                $cik(['success' => true, 'alanlar' => $alanlar]);

            // ---------------------------------------------------------
            case 'kanalKaydet':
                $kanalId    = (int)($_POST['kanal_id'] ?? 0);
                $kanalAdi   = trim($_POST['kanal_adi'] ?? '');
                $firmaId    = (int)($_POST['firma_id'] ?? 0) ?: null;
                $varsayilan = !empty($_POST['varsayilan']) ? 1 : 0;
                $kanalDurum = !empty($_POST['kanal_durum']) ? 1 : 0;
                $gelen      = (array)($_POST['kimlik'] ?? []);

                if ($kanalId > 0 && empty($pagePermissions['can_edit'])) {
                    $cik(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']);
                }
                if ($kanalId <= 0 && empty($pagePermissions['can_add'])) {
                    $cik(['success' => false, 'message' => 'Ekleme yetkiniz yok.']);
                }
                if ($kanalAdi === '') {
                    $cik(['success' => false, 'message' => 'Kanal adı zorunludur.']);
                }
                if (mb_strlen($kanalAdi) > 100) {
                    $cik(['success' => false, 'message' => 'Kanal adı en fazla 100 karakter olabilir.']);
                }

                // Firma: kısıtlı kullanıcı yalnız kendi firmasına kanal bağlar
                if ($kisitFirmaId !== null) {
                    if ($kisitFirmaId <= 0) {
                        $cik(['success' => false, 'message' => 'Firma bilginiz tanımlı değil.']);
                    }
                    $firmaId = $kisitFirmaId;
                } elseif ($firmaId !== null
                          && !in_array($firmaId, array_map('intval', array_column(PazaryeriFabrika::firmalar(), 'firma_id')), true)) {
                    $cik(['success' => false, 'message' => 'Firma bulunamadı veya pasif.']);
                }

                if ($kanalId > 0) {
                    // --- Güncelleme ---
                    $kanal = pzKanalErisim($db, $kisitFirmaId, $kanalId);
                    if (!$kanal) {
                        $cik(['success' => false, 'message' => 'Kanal bulunamadı.']);
                    }
                    $ayarlar      = json_decode($kanal['EntegrasyonKanallari_AyarJSON'] ?? '{}', true) ?: [];
                    $pazaryeriKod = $kanal['Pazaryerleri_Kod'] ?? '';
                } else {
                    // --- Yeni kanal ---
                    $pazaryeriKod = trim($_POST['pazaryeri_kod'] ?? '');
                    $ent = $db->fetchOne("
                        SELECT e.Entegrasyonlar_id
                        FROM dbo.tanim_Pazaryerleri p
                        INNER JOIN dbo.Entegrasyonlar e
                                ON e.Entegrasyonlar_Kod = p.Pazaryerleri_EntegrasyonKod
                        WHERE p.Pazaryerleri_Kod = ?
                    ", [$pazaryeriKod]);

                    if (!$ent) {
                        $cik(['success' => false, 'message' => 'Bu pazaryeri için entegrasyon kaydı yok.']);
                    }
                    $ayarlar = [];
                }

                // Gizli alanlar boş bırakılırsa ESKİ DEĞER KORUNUR
                $gizliHarita = [];
                foreach (PazaryeriFabrika::kimlikAlanlari($pazaryeriKod) as $g) {
                    $gizliHarita[$g['anahtar']] = !empty($g['gizli']);
                }

                foreach ($gelen as $anahtar => $deger) {
                    $anahtar = trim((string)$anahtar);
                    $deger   = trim((string)$deger);

                    if ($anahtar === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,59}$/', $anahtar)) {
                        continue;
                    }

                    if ($deger === '' && pzGizliMi($anahtar, $gizliHarita) && isset($ayarlar[$anahtar])) {
                        continue;   // dokunma
                    }
                    if ($deger === '') {
                        unset($ayarlar[$anahtar]);
                        continue;
                    }
                    $ayarlar[$anahtar] = $deger;
                }

                // Zorunlu kimlik alanı ne formda ne kayıtta varsa kanal kaydedilmez;
                // aksi halde hata ancak bağlantı testinde/cron'da ortaya çıkar.
                foreach (PazaryeriFabrika::kimlikAlanlari($pazaryeriKod) as $alan) {
                    if (!empty($alan['zorunlu']) && trim((string)($ayarlar[$alan['anahtar']] ?? '')) === '') {
                        $cik(['success' => false, 'message' => ($alan['etiket'] ?? $alan['anahtar']) . ' zorunludur.']);
                    }
                }

                $veri = [
                    'EntegrasyonKanallari_Ad'                   => $kanalAdi,
                    'EntegrasyonKanallari_AyarJSON'             => json_encode($ayarlar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'EntegrasyonKanallari_Varsayilan'           => $varsayilan,
                    'EntegrasyonKanallari_Firmalar_id'          => $firmaId,
                    'EntegrasyonKanallari_Durum'                => $kanalDurum,
                    'EntegrasyonKanallari_GuncelleyenKullanici' => $user['kullanici_id'],
                    'EntegrasyonKanallari_GuncellemeTarihi'     => $simdi
                ];

                if ($kanalId > 0) {
                    $db->update('EntegrasyonKanallari', $veri, ['EntegrasyonKanallari_id' => $kanalId]);
                    $mesaj = 'Kanal güncellendi.';
                } else {
                    $veri['EntegrasyonKanallari_Entegrasyonlar_id'] = (int)$ent['Entegrasyonlar_id'];
                    $veri['EntegrasyonKanallari_OlusturanKullanici'] = (int)$user['kullanici_id'];
                    $veri['EntegrasyonKanallari_OlusturmaTarihi']    = $simdi;
                    unset($veri['EntegrasyonKanallari_GuncelleyenKullanici'], $veri['EntegrasyonKanallari_GuncellemeTarihi']);
                    $kanalId = (int)$db->insert('EntegrasyonKanallari', $veri);
                    $mesaj = 'Kanal eklendi.';
                }

                // Varsayılan işaretlendiyse aynı entegrasyondaki diğerleri düşürülür
                if ($varsayilan && $kanalId > 0) {
                    $db->execute("
                        UPDATE dbo.EntegrasyonKanallari
                        SET EntegrasyonKanallari_Varsayilan = 0
                        WHERE EntegrasyonKanallari_Entegrasyonlar_id =
                              (SELECT EntegrasyonKanallari_Entegrasyonlar_id FROM dbo.EntegrasyonKanallari WHERE EntegrasyonKanallari_id = ?)
                          AND EntegrasyonKanallari_id <> ?
                    ", [$kanalId, $kanalId]);
                }

                $cik(['success' => true, 'message' => $mesaj, 'kanal_id' => $kanalId]);

            // ---------------------------------------------------------
            case 'ayarList':
                [$kisitSql, $kisitParams] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.EntegrasyonKanallari_Firmalar_id');
                $liste = $db->fetchAll("
                    SELECT
                        a.PazaryeriAyarlari_id,
                        a.PazaryeriAyarlari_Pazaryerleri_id,
                        a.PazaryeriAyarlari_EntegrasyonKanallari_id,
                        a.PazaryeriAyarlari_Anahtar,
                        a.PazaryeriAyarlari_Deger,
                        a.PazaryeriAyarlari_Tip,
                        a.PazaryeriAyarlari_Aciklama,
                        CAST(a.PazaryeriAyarlari_Durum AS INT) AS PazaryeriAyarlari_Durum,
                        p.Pazaryerleri_Ad,
                        p.Pazaryerleri_Renk,
                        k.EntegrasyonKanallari_Ad,
                        f.firma_adi,
                        f.firma_unvan,
                        CASE WHEN a.PazaryeriAyarlari_EntegrasyonKanallari_id IS NOT NULL THEN 'kanal'
                             WHEN a.PazaryeriAyarlari_Pazaryerleri_id IS NOT NULL THEN 'pazaryeri'
                             ELSE 'genel' END AS Kapsam,
                        CASE WHEN a.PazaryeriAyarlari_EntegrasyonKanallari_id IS NOT NULL THEN 1
                             WHEN a.PazaryeriAyarlari_Pazaryerleri_id IS NOT NULL THEN 2
                             ELSE 3 END AS Oncelik
                    FROM dbo.PazaryeriAyarlari a
                    LEFT JOIN dbo.tanim_Pazaryerleri p ON p.Pazaryerleri_id = a.PazaryeriAyarlari_Pazaryerleri_id
                    LEFT JOIN dbo.EntegrasyonKanallari k ON k.EntegrasyonKanallari_id = a.PazaryeriAyarlari_EntegrasyonKanallari_id
                    LEFT JOIN dbo.Firmalar f ON f.firma_id = k.EntegrasyonKanallari_Firmalar_id
                    WHERE a.PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL
                       OR (" . PazaryeriFabrika::aktifKanalSql('a.PazaryeriAyarlari_EntegrasyonKanallari_id') . $kisitSql . ")
                    ORDER BY a.PazaryeriAyarlari_Anahtar, Oncelik
                ", $kisitParams);
                $cik(['success' => true, 'data' => $liste]);

            // ---------------------------------------------------------
            // Mevcut anahtarlar öneri olarak sunulur; liste koda gömülmez.
            case 'anahtarlar':
                $cik(['success' => true, 'data' => $db->fetchAll("
                    SELECT PazaryeriAyarlari_Anahtar        AS anahtar,
                           MAX(PazaryeriAyarlari_Tip)       AS tip,
                           MAX(PazaryeriAyarlari_Aciklama)  AS aciklama
                    FROM dbo.PazaryeriAyarlari
                    GROUP BY PazaryeriAyarlari_Anahtar
                    ORDER BY PazaryeriAyarlari_Anahtar
                ")]);

            // ---------------------------------------------------------
            case 'ayarDetay':
                $row = $db->fetchOne("
                    SELECT PazaryeriAyarlari_id, PazaryeriAyarlari_Pazaryerleri_id,
                           PazaryeriAyarlari_EntegrasyonKanallari_id, PazaryeriAyarlari_Anahtar,
                           PazaryeriAyarlari_Deger, PazaryeriAyarlari_Tip, PazaryeriAyarlari_Aciklama,
                           CAST(PazaryeriAyarlari_Durum AS INT) AS PazaryeriAyarlari_Durum
                    FROM dbo.PazaryeriAyarlari WHERE PazaryeriAyarlari_id = ?
                ", [(int)($_POST['id'] ?? 0)]);

                if (!$row) {
                    $cik(['success' => false, 'message' => 'Ayar bulunamadı.']);
                }
                if ($row['PazaryeriAyarlari_EntegrasyonKanallari_id'] !== null
                    && !pzKanalErisim($db, $kisitFirmaId, $row['PazaryeriAyarlari_EntegrasyonKanallari_id'])) {
                    $cik(['success' => false, 'message' => 'Bu ayara erişim yetkiniz yok.']);
                }
                $cik(['success' => true, 'data' => $row]);

            // ---------------------------------------------------------
            case 'ayarKaydet':
                $id      = (int)($_POST['id'] ?? 0);
                $kapsam  = $_POST['kapsam'] ?? 'genel';
                $anahtar = trim($_POST['anahtar'] ?? '');
                $deger   = (string)($_POST['deger'] ?? '');
                $tip     = $_POST['tip'] ?? 'metin';

                if ($id > 0 && empty($pagePermissions['can_edit'])) {
                    $cik(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']);
                }
                if ($id <= 0 && empty($pagePermissions['can_add'])) {
                    $cik(['success' => false, 'message' => 'Ekleme yetkiniz yok.']);
                }
                if ($anahtar === '') {
                    $cik(['success' => false, 'message' => 'Ayar anahtarı zorunludur.']);
                }
                if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,59}$/', $anahtar)) {
                    $cik(['success' => false, 'message' => 'Anahtar harfle başlamalı; harf, rakam ve alt çizgi içerebilir.']);
                }
                // Tip, PazaryeriApi::ayar() içindeki dönüşümle birebir eşleşir
                if (!in_array($tip, ['metin', 'sayi', 'bit', 'json'], true)) {
                    $cik(['success' => false, 'message' => 'Geçersiz ayar tipi.']);
                }
                if ($tip === 'sayi' && $deger !== '' && !is_numeric($deger)) {
                    $cik(['success' => false, 'message' => 'Sayı tipindeki ayara sayısal değer girilmelidir.']);
                }
                if ($tip === 'json' && $deger !== '') {
                    json_decode($deger);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $cik(['success' => false, 'message' => 'JSON değeri geçersiz.']);
                    }
                }

                // Düzenlenen kayıt kullanıcının erişebildiği kapsamda mı?
                if ($id > 0) {
                    $eski = $db->fetchOne("
                        SELECT PazaryeriAyarlari_EntegrasyonKanallari_id AS kanal_id
                        FROM dbo.PazaryeriAyarlari WHERE PazaryeriAyarlari_id = ?
                    ", [$id]);
                    if (!$eski) {
                        $cik(['success' => false, 'message' => 'Ayar bulunamadı.']);
                    }
                    if ($eski['kanal_id'] === null ? !$globalYetki : !pzKanalErisim($db, $kisitFirmaId, $eski['kanal_id'])) {
                        $cik(['success' => false, 'message' => 'Bu ayarı değiştirme yetkiniz yok.']);
                    }
                }

                $pazaryeriId = null;
                $kanalId     = null;
                if ($kapsam === 'pazaryeri') {
                    $pazaryeriId = (int)($_POST['pazaryeri_id'] ?? 0);
                    if ($pazaryeriId <= 0) {
                        $cik(['success' => false, 'message' => 'Pazaryeri seçilmedi.']);
                    }
                } elseif ($kapsam === 'kanal') {
                    $kanalId = (int)($_POST['kanal_id'] ?? 0);
                    if ($kanalId <= 0) {
                        $cik(['success' => false, 'message' => 'Kanal seçilmedi.']);
                    }
                    if (!pzKanalErisim($db, $kisitFirmaId, $kanalId)) {
                        $cik(['success' => false, 'message' => 'Kanal bulunamadı veya erişim yetkiniz yok.']);
                    }
                } else {
                    $kapsam = 'genel';
                }

                if ($kapsam !== 'kanal' && !$globalYetki) {
                    $cik(['success' => false, 'message' => 'Genel ve pazaryeri bazlı ayarları yalnız tüm firmalara yetkili kullanıcı değiştirebilir.']);
                }

                // Aynı kapsamda aynı anahtar iki kez olamaz (filtreli unique index'lerle aynı kural)
                if ($kanalId !== null) {
                    $cakismaSql    = "PazaryeriAyarlari_EntegrasyonKanallari_id = ?";
                    $cakismaParams = [$kanalId];
                } elseif ($pazaryeriId !== null) {
                    $cakismaSql    = "PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id = ?";
                    $cakismaParams = [$pazaryeriId];
                } else {
                    $cakismaSql    = "PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id IS NULL";
                    $cakismaParams = [];
                }
                $cakisma = $db->fetchOne("
                    SELECT PazaryeriAyarlari_id FROM dbo.PazaryeriAyarlari
                    WHERE PazaryeriAyarlari_Anahtar = ? AND PazaryeriAyarlari_id <> ? AND {$cakismaSql}
                ", array_merge([$anahtar, $id], $cakismaParams));
                if ($cakisma) {
                    $cik(['success' => false, 'message' => 'Bu kapsamda aynı anahtar zaten tanımlı.']);
                }

                $veri = [
                    'PazaryeriAyarlari_Pazaryerleri_id'         => $pazaryeriId,
                    'PazaryeriAyarlari_EntegrasyonKanallari_id' => $kanalId,
                    'PazaryeriAyarlari_Anahtar'                 => $anahtar,
                    'PazaryeriAyarlari_Deger'                   => $deger,
                    'PazaryeriAyarlari_Tip'                     => $tip,
                    'PazaryeriAyarlari_Aciklama'                => mb_substr(trim($_POST['aciklama'] ?? ''), 0, 300) ?: null,
                    'PazaryeriAyarlari_Durum'                   => !empty($_POST['durum']) ? 1 : 0
                ];

                if ($id > 0) {
                    $veri['PazaryeriAyarlari_GuncelleyenKullanici'] = $user['kullanici_id'];
                    $veri['PazaryeriAyarlari_GuncellemeTarihi']     = $simdi;
                    $db->update('PazaryeriAyarlari', $veri, ['PazaryeriAyarlari_id' => $id]);
                    $mesaj = 'Ayar güncellendi.';
                } else {
                    $veri['PazaryeriAyarlari_OlusturanKullanici'] = $user['kullanici_id'];
                    $veri['PazaryeriAyarlari_OlusturmaTarihi']    = $simdi;
                    $db->insert('PazaryeriAyarlari', $veri);
                    $mesaj = 'Ayar eklendi.';
                }

                $cik(['success' => true, 'message' => $mesaj]);

            // ---------------------------------------------------------
            // Kalıcı silinir: filtreli unique index'ler pasif kaydı da sayar,
            // pasife alınan anahtar aynı kapsamda yeniden eklenemezdi.
            // Geçici kapatmak için Durum anahtarı kullanılır.
            case 'ayarSil':
                if (empty($pagePermissions['can_delete'])) {
                    $cik(['success' => false, 'message' => 'Silme yetkiniz yok.']);
                }
                $id   = (int)($_POST['id'] ?? 0);
                $eski = $db->fetchOne("
                    SELECT PazaryeriAyarlari_EntegrasyonKanallari_id AS kanal_id
                    FROM dbo.PazaryeriAyarlari WHERE PazaryeriAyarlari_id = ?
                ", [$id]);
                if (!$eski) {
                    $cik(['success' => false, 'message' => 'Ayar bulunamadı.']);
                }
                $silebilir = $eski['kanal_id'] === null
                    ? $kisitFirmaId === null
                    : (bool)pzKanalErisim($db, $kisitFirmaId, $eski['kanal_id']);
                if (!$silebilir) {
                    $cik(['success' => false, 'message' => 'Bu ayarı silme yetkiniz yok.']);
                }
                $db->delete('PazaryeriAyarlari', ['PazaryeriAyarlari_id' => $id]);
                $cik(['success' => true, 'message' => 'Ayar silindi.']);

            // ---------------------------------------------------------
            // Bir anahtarın belirli kanal için nasıl çözüldüğünü göster
            case 'ayarCozumle':
                $anahtar = trim($_POST['anahtar'] ?? '');
                $kanalId = (int)($_POST['kanal_id'] ?? 0) ?: null;

                $kanal = null;
                if ($kanalId !== null) {
                    $kanal = pzKanalErisim($db, $kisitFirmaId, $kanalId);
                    if (!$kanal) {
                        $cik(['success' => false, 'message' => 'Kanal bulunamadı veya erişim yetkiniz yok.']);
                    }
                }

                $katmanlar = [];
                foreach ([
                    ['ad' => 'Kanal (firma)', 'aktif' => $kanal !== null,
                     'sql' => 'PazaryeriAyarlari_EntegrasyonKanallari_id = ?',
                     'p'   => [(int)$kanalId]],
                    ['ad' => 'Pazaryeri', 'aktif' => $kanal !== null,
                     'sql' => 'PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id = ?',
                     'p'   => [(int)($kanal['Pazaryerleri_id'] ?? 0)]],
                    ['ad' => 'Genel varsayılan', 'aktif' => true,
                     'sql' => 'PazaryeriAyarlari_EntegrasyonKanallari_id IS NULL AND PazaryeriAyarlari_Pazaryerleri_id IS NULL',
                     'p'   => []],
                ] as $k) {
                    if (!$k['aktif']) {
                        $katmanlar[] = ['ad' => $k['ad'], 'deger' => null, 'var' => false];
                        continue;
                    }
                    $row = $db->fetchOne("
                        SELECT PazaryeriAyarlari_Deger FROM dbo.PazaryeriAyarlari
                        WHERE PazaryeriAyarlari_Anahtar = ? AND PazaryeriAyarlari_Durum = 1 AND {$k['sql']}
                    ", array_merge([$anahtar], $k['p']));
                    $katmanlar[] = [
                        'ad'    => $k['ad'],
                        'deger' => $row['PazaryeriAyarlari_Deger'] ?? null,
                        'var'   => !empty($row)
                    ];
                }

                $cik(['success' => true, 'data' => [
                    'anahtar'   => $anahtar,
                    'kanal'     => $kanal['EntegrasyonKanallari_Ad'] ?? null,
                    'pazaryeri' => $kanal['Pazaryerleri_Ad'] ?? null,
                    'katmanlar' => $katmanlar
                ]]);
        }

        $cik(['success' => false, 'message' => 'Geçersiz istek.']);

    } catch (Throwable $e) {
        error_log('pazaryeri-ayarlar.php: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$firmalar = PazaryeriFabrika::firmalar($kisitFirmaId);
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
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .filtre-basligi { cursor: pointer; }
        .pz-kart { border-left: 4px solid var(--pz-renk, #adb5bd); height: 100%; }
        .pz-kart .card-body { padding: .85rem 1rem; }
        .pz-ad { font-weight: 600; font-size: 1rem; }
        .pz-durum { font-size: .75rem; }
        .yetenek-rozet {
            font-size: .68rem; font-weight: 500;
            padding: .15rem .4rem; margin: .1rem .1rem 0 0;
            display: inline-block; border-radius: .2rem;
        }
        .kapsam-genel     { background:#e9ecef; color:#495057; }
        .kapsam-pazaryeri { background:#cfe2ff; color:#084298; }
        .kapsam-kanal     { background:#d1e7dd; color:#0a3622; }
        .renk-nokta { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
        .zincir-satir { padding:.5rem .75rem; border-left:3px solid transparent; }
        .zincir-satir.kazanan { border-left-color:#198754; background:var(--bs-success-bg-subtle); font-weight:600; }
        .zincir-satir.bos     { color:#adb5bd; }
        #tblKanallar td { vertical-align: middle; }
        #tblKanallar .select2-container { min-width: 220px; }
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

                    <!-- InfoBox -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-shop"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tanımlı Pazaryeri</span>
                                    <span class="info-box-number" id="ibToplam">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kullanıma Hazır</span>
                                    <span class="info-box-number" id="ibHazir">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-plug"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Kanal</span>
                                    <span class="info-box-number" id="ibKanal">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-sliders"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Ayar</span>
                                    <span class="info-box-number" id="ibAyar">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pazaryeri kartları -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-shop me-1"></i> Pazaryerleri</h3>
                            <div class="card-tools">
                                <button class="btn btn-sm btn-outline-secondary" id="btnKartYenile" title="Yenile">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2" id="pazaryeriKartlari">
                                <div class="col-12 text-center text-muted py-3">
                                    <div class="spinner-border spinner-border-sm me-2"></div> Yükleniyor...
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Kanallar -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-key me-1"></i> Kanallar, Kimlik Bilgileri ve Firma Bağı</h3>
                            <div class="card-tools">
                                <?php if (!empty($pagePermissions['can_add'])): ?>
                                <button class="btn btn-sm btn-primary" id="btnYeniKanal">
                                    <i class="bi bi-plus-lg me-1"></i> Yeni Kanal
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-2">
                                Bir kanal, bir firmanın bir pazaryerindeki hesabıdır. API kimlik bilgileri
                                burada tutulur; siparişin faturası kanalın bağlı olduğu firma üzerinden kesilir.
                            </p>
                            <table class="table table-sm table-hover align-middle w-100" id="tblKanallar">
                                <thead>
                                    <tr>
                                        <th>Pazaryeri</th>
                                        <th>Kanal</th>
                                        <th style="width:280px">Firma</th>
                                        <th class="text-center">Durum</th>
                                        <th class="text-end">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Ayarlar -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-sliders me-1"></i> Ayarlar</h3>
                            <div class="card-tools">
                                <button class="btn btn-sm btn-outline-info" id="btnZincir">
                                    <i class="bi bi-diagram-3 me-1"></i> Zinciri Test Et
                                </button>
                                <?php if (!empty($pagePermissions['can_add'])): ?>
                                <button class="btn btn-sm btn-primary" id="btnYeniAyar">
                                    <i class="bi bi-plus-lg me-1"></i> Yeni Ayar
                                </button>
                                <?php endif; ?>
                            </div>
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
                                            <div class="col-md-4">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="fAyarAra" placeholder="Anahtar, değer veya açıklama...">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Kapsam</label>
                                                <select class="form-select filtre-select2" id="fKapsam">
                                                    <option value="">Tüm kapsamlar</option>
                                                    <option value="genel">Genel varsayılan</option>
                                                    <option value="pazaryeri">Pazaryeri bazlı</option>
                                                    <option value="kanal">Kanal (firma) bazlı</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Pazaryeri / Kanal</label>
                                                <select class="form-select filtre-select2" id="fHedef">
                                                    <option value="">Tümü</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button class="btn btn-outline-secondary w-100" id="btnAyarTemizle">
                                                    <i class="bi bi-x-circle"></i> Temizle
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <table class="table table-bordered table-striped table-hover table-sm align-middle w-100" id="tblAyarlar">
                                <thead>
                                    <tr>
                                        <th>Anahtar</th>
                                        <th>Kapsam</th>
                                        <th>Değer</th>
                                        <th>Tip</th>
                                        <th>Açıklama</th>
                                        <th class="text-center">Durum</th>
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

    <!-- Ayar modal -->
    <div class="modal fade" id="ayarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="ayarForm">
                    <input type="hidden" name="action" value="ayarKaydet">
                    <input type="hidden" name="id" id="aId" value="0">

                    <div class="modal-header">
                        <h5 class="modal-title" id="ayarBaslik">Yeni Ayar</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Kapsam <span class="text-danger">*</span></label>
                            <select class="form-select" name="kapsam" id="aKapsam" style="width:100%">
                                <?php if ($globalYetki): ?>
                                <option value="genel">Genel varsayılan — tüm pazaryerleri</option>
                                <option value="pazaryeri">Pazaryeri bazlı — bu pazaryerinde ezer</option>
                                <?php endif; ?>
                                <option value="kanal">Kanal (firma) bazlı — en öncelikli</option>
                            </select>
                            <div class="form-text">
                                Değer okunurken önce kanal, sonra pazaryeri, en son genel katmana bakılır.
                            </div>
                        </div>

                        <div class="mb-3 d-none" id="alanPazaryeri">
                            <label class="form-label">Pazaryeri</label>
                            <select class="form-select" name="pazaryeri_id" id="aPazaryeri" style="width:100%"></select>
                        </div>

                        <div class="mb-3 d-none" id="alanKanal">
                            <label class="form-label">Kanal</label>
                            <select class="form-select" name="kanal_id" id="aKanal" style="width:100%"></select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Anahtar <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="anahtar" id="aAnahtar"
                                   list="anahtarOnerileri" required maxlength="60" placeholder="HarfIleBaslar">
                            <datalist id="anahtarOnerileri"></datalist>
                        </div>

                        <div class="row">
                            <div class="col-8 mb-3">
                                <label class="form-label">Değer</label>
                                <input type="text" class="form-control" name="deger" id="aDeger">
                            </div>
                            <div class="col-4 mb-3">
                                <label class="form-label">Tip</label>
                                <select class="form-select" name="tip" id="aTip" style="width:100%">
                                    <option value="metin">metin</option>
                                    <option value="sayi">sayı</option>
                                    <option value="bit">bit (0/1)</option>
                                    <option value="json">json</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <input type="text" class="form-control" name="aciklama" id="aAciklama" maxlength="300">
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="aDurum" name="durum" value="1" checked>
                            <label class="form-check-label" for="aDurum">Aktif</label>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Vazgeç</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Kanal / kimlik modal -->
    <div class="modal fade" id="kanalModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="kanalForm" autocomplete="off">
                    <input type="hidden" name="action" value="kanalKaydet">
                    <input type="hidden" name="kanal_id" id="kKanalId" value="0">
                    <input type="hidden" name="pazaryeri_kod" id="kPazaryeriKod" value="">

                    <div class="modal-header">
                        <h5 class="modal-title" id="kanalBaslik">Kanal Bilgileri</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4 d-none" id="kPazaryeriAlani">
                                <label class="form-label">Pazaryeri <span class="text-danger">*</span></label>
                                <select class="form-select" id="kPazaryeriSec" style="width:100%"></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Kanal Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="kanal_adi" id="kKanalAdi" required maxlength="100">
                                <div class="form-text">Bu hesabı tanımlayan kısa ad.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Firma</label>
                                <select class="form-select" name="firma_id" id="kFirma" style="width:100%" <?= $kisitFirmaId !== null ? 'disabled' : '' ?>>
                                    <option value="">— firma seçilmedi —</option>
                                    <?php foreach ($firmalar as $f): ?>
                                    <option value="<?= (int)$f['firma_id'] ?>"><?= htmlspecialchars($f['firma_unvan'] ?: $f['firma_adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Fatura bu firma üzerinden kesilir.</div>
                            </div>
                        </div>

                        <hr>
                        <h6 class="mb-2"><i class="bi bi-key me-1"></i> API Kimlik Bilgileri</h6>
                        <div id="kimlikAlanlari"></div>

                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAlanEkle">
                                <i class="bi bi-plus-lg me-1"></i> Ek alan
                            </button>
                            <span class="form-text ms-2">
                                Pazaryerinin istediği ama listede olmayan bir alan varsa buradan ekleyin.
                            </span>
                        </div>

                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kVarsayilan" name="varsayilan" value="1">
                                    <label class="form-check-label" for="kVarsayilan">Varsayılan kanal</label>
                                </div>
                                <div class="form-text">Kanal belirtilmeden yapılan işlemlerde bu hesap kullanılır.</div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kDurum" name="kanal_durum" value="1" checked>
                                    <label class="form-check-label" for="kDurum">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-primary me-auto d-none" id="btnKanalTest">
                            <i class="bi bi-plug me-1"></i> Kaydet ve Test Et
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Vazgeç</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Zincir testi modal -->
    <div class="modal fade" id="zincirModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ayar Zinciri Testi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">Anahtar</label>
                            <input type="text" class="form-control" id="zAnahtar" list="anahtarOnerileri">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Kanal</label>
                            <select class="form-select" id="zKanal" style="width:100%"></select>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-primary mb-3" id="btnZincirCalistir">
                        <i class="bi bi-play me-1"></i> Çözümle
                    </button>
                    <div id="zincirSonuc"></div>
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

        const permissions  = <?= json_encode($pagePermissions) ?>;
        const GLOBAL_YETKI = <?= $globalYetki ? 'true' : 'false' ?>;
        const FIRMA_KISITLI = <?= $kisitFirmaId !== null ? 'true' : 'false' ?>;
        const firmalar = <?= json_encode($firmalar, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

        const kacis = (s) => $('<div>').text(s ?? '').html();
        const ikonSinif = (i) => { i = String(i || 'bi-shop'); return i.indexOf('bi ') === 0 ? i : 'bi ' + i; };
        const firmaAdi = (f) => f.firma_unvan || f.firma_adi || '';
        const s2Dil = { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' };

        let kanalListesi = [];
        let pazaryeriListesi = [];

        function s2(sec, ebeveyn) {
            $(sec).each(function () {
                if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
                $(this).select2({
                    theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 0, language: s2Dil,
                    dropdownParent: ebeveyn ? $(ebeveyn) : $(document.body)
                });
            });
        }

        function testSonucu(res, kanalAdi) {
            Swal.fire({
                icon: res.success ? 'success' : 'error',
                title: (kanalAdi ? kanalAdi + ': ' : '') + (res.success ? 'Bağlantı başarılı' : 'Bağlantı kurulamadı'),
                text: res.message
            });
        }

        // ---------------------------------------------------------------
        // InfoBox
        // ---------------------------------------------------------------
        function loadStats() {
            $.post('', { action: 'stats' }, function (res) {
                if (!res.success) return;
                $('#ibToplam').text(res.data.toplam);
                $('#ibHazir').text(res.data.hazir);
                $('#ibKanal').text(res.data.kanal);
                $('#ibAyar').text(res.data.ayar);
            }, 'json');
        }

        // ---------------------------------------------------------------
        // Pazaryeri kartları
        // ---------------------------------------------------------------
        function kartlariYukle() {
            $.post('', { action: 'pazaryerleri' }, function (res) {
                if (!res.success) return;
                pazaryeriListesi = res.data || [];
                const kap = $('#pazaryeriKartlari').empty();

                if (!pazaryeriListesi.length) {
                    kap.append('<div class="col-12 text-center text-muted py-3">Tanımlı pazaryeri yok.</div>');
                }

                pazaryeriListesi.forEach(function (p) {
                    const renk = p.Pazaryerleri_Renk || '#adb5bd';
                    let rozet, rozetSinif;

                    if (p.kullanilabilir)      { rozet = 'Hazır';                 rozetSinif = 'text-bg-success'; }
                    else if (!p.sinifVar)      { rozet = 'Entegrasyon yazılmadı'; rozetSinif = 'text-bg-secondary'; }
                    else if (!p.kanalVar)      { rozet = 'Kimlik bilgisi yok';    rozetSinif = 'text-bg-warning'; }
                    else                       { rozet = 'Pasif';                 rozetSinif = 'text-bg-light border'; }

                    // Desteklenen yetenekler
                    let yetenekler = '';
                    Object.keys(p.yetenekler || {}).forEach(function (y) {
                        if (p.yetenekler[y]) {
                            yetenekler += '<span class="yetenek-rozet kapsam-pazaryeri">' + kacis(y) + '</span>';
                        }
                    });
                    if (!yetenekler) {
                        yetenekler = '<span class="text-muted small">yetenek tanımlanmadı</span>';
                    }

                    // Kanallar: her satırda kendi test butonu (pasif kanal test edilemez)
                    let kanalBilgi = '';
                    (p.kanallar || []).forEach(function (k) {
                        const aktif = Number(k.EntegrasyonKanallari_Durum) === 1;
                        kanalBilgi +=
                            '<div class="d-flex justify-content-between align-items-center gap-2 border-top pt-1 mt-1 small">'
                          + '  <span class="text-truncate' + (aktif ? '' : ' text-muted text-decoration-line-through') + '">'
                          + '    <i class="bi bi-key me-1"></i>' + kacis(k.EntegrasyonKanallari_Ad)
                          +      (k.firma_adi ? ' · ' + kacis(k.firma_adi) : '')
                          +      (Number(k.EntegrasyonKanallari_Varsayilan) === 1 ? ' <span class="badge text-bg-light border">varsayılan</span>' : '')
                          + '  </span>'
                          + (p.kullanilabilir && aktif
                                ? '  <button class="btn btn-sm btn-outline-primary py-0 btn-test text-nowrap"'
                                + '          data-kod="' + kacis(p.Pazaryerleri_Kod) + '" data-kanal="' + k.EntegrasyonKanallari_id + '">'
                                + '<i class="bi bi-plug me-1"></i> Test Et</button>'
                                : '')
                          + '</div>';
                    });
                    if (kanalBilgi) {
                        kanalBilgi = '<div class="mt-2">' + kanalBilgi + '</div>';
                    }

                    kap.append(
                        '<div class="col-12 col-md-6 col-xl-4">'
                      + '<div class="card pz-kart" style="--pz-renk:' + kacis(renk) + '">'
                      + '  <div class="card-body">'
                      + '    <div class="d-flex justify-content-between align-items-start">'
                      + '      <div>'
                      + '        <div class="pz-ad"><i class="' + kacis(ikonSinif(p.Pazaryerleri_Ikon)) + ' me-1"></i>'
                      +            kacis(p.Pazaryerleri_Ad) + '</div>'
                      + '        <div class="pz-durum"><span class="badge ' + rozetSinif + '">' + rozet + '</span></div>'
                      + '      </div>'
                      + '      <div class="form-check form-switch d-flex justify-content-center">'
                      + '        <input class="form-check-input pz-durum-switch" type="checkbox" role="switch"'
                      + '               id="pzDurum' + p.Pazaryerleri_id + '" data-id="' + p.Pazaryerleri_id + '"'
                      +                (Number(p.Pazaryerleri_Durum) === 1 ? ' checked' : '')
                      +                (p.sinifVar && GLOBAL_YETKI ? '' : ' disabled')
                      + '        ><label class="form-check-label" for="pzDurum' + p.Pazaryerleri_id + '"></label>'
                      + '      </div>'
                      + '    </div>'
                      + '    <div class="mt-2">' + yetenekler + '</div>'
                      +      kanalBilgi
                      + '  </div>'
                      + '</div></div>'
                    );
                });

                pazaryeriSecicileriDoldur();
            }, 'json');
        }

        $('#btnKartYenile').on('click', function () { kartlariYukle(); loadStats(); });

        $('#pazaryeriKartlari').on('change', '.pz-durum-switch', function () {
            $.post('', { action: 'pazaryeriDurum', id: $(this).data('id'), durum: $(this).is(':checked') ? 1 : 0 }, function (res) {
                showToast(res.message, res.success ? 'success' : 'error');
                kartlariYukle();
                loadStats();
            }, 'json');
        });

        $('#pazaryeriKartlari').on('click', '.btn-test', function () {
            const btn = $(this);
            const eski = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

            const kanalAdi = btn.siblings('span').text().trim();
            $.post('', { action: 'baglantiTest', kod: btn.data('kod'), kanal_id: btn.data('kanal') }, res => testSonucu(res, kanalAdi), 'json')
                .fail(() => showToast('Sunucu hatası', 'error'))
                .always(() => btn.prop('disabled', false).html(eski));
        });

        // ---------------------------------------------------------------
        // Kanallar (tanım tablosu → client-side)
        // ---------------------------------------------------------------
        const kanalTablo = $('#tblKanallar').DataTable({
            dom: 'rt',
            paging: false,
            scrollX: true,
            order: [],
            columns: [
                { orderable: true }, { orderable: true }, { orderable: false },
                { orderable: false, className: 'text-center' }, { orderable: false, className: 'text-end' }
            ],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json', emptyTable: 'Tanımlı kanal yok.' }
        });

        function kanallariYukle() {
            $.post('', { action: 'kanallar' }, function (res) {
                if (!res.success) return;
                kanalListesi = res.data || [];

                const firmaKilitli = FIRMA_KISITLI || !permissions.can_edit;
                const satirlar = kanalListesi.map(function (k) {
                    let secenekler = '<option value="">— firma seçilmedi —</option>';
                    firmalar.forEach(function (f) {
                        secenekler += '<option value="' + f.firma_id + '"'
                                    + (Number(k.EntegrasyonKanallari_Firmalar_id) === Number(f.firma_id) ? ' selected' : '')
                                    + '>' + kacis(firmaAdi(f)) + '</option>';
                    });

                    return [
                        '<span class="renk-nokta" style="background:' + kacis(k.Pazaryerleri_Renk || '#adb5bd') + '"></span>'
                            + kacis(k.Pazaryerleri_Ad),
                        kacis(k.EntegrasyonKanallari_Ad)
                            + (Number(k.EntegrasyonKanallari_Varsayilan) === 1 ? ' <span class="badge text-bg-light border">varsayılan</span>' : ''),
                        '<select class="form-select form-select-sm kanal-firma" data-kanal="' + k.EntegrasyonKanallari_id + '"'
                            + (firmaKilitli ? ' disabled' : '') + '>' + secenekler + '</select>',
                        Number(k.EntegrasyonKanallari_Durum) === 1
                            ? '<span class="badge text-bg-success">aktif</span>'
                            : '<span class="badge text-bg-secondary">pasif</span>',
                        (permissions.can_edit
                            ? '<button class="btn btn-sm btn-outline-secondary btn-kanal-duzenle" data-kanal="' + k.EntegrasyonKanallari_id + '" title="Kimlik bilgilerini düzenle">'
                              + '<i class="bi bi-key"></i></button> '
                            : '')
                        + '<button class="btn btn-sm btn-outline-primary btn-kanal-test" data-kanal="' + k.EntegrasyonKanallari_id + '" title="Bağlantıyı test et">'
                        + '<i class="bi bi-plug"></i></button>'
                    ];
                });

                kanalTablo.clear().rows.add(satirlar).draw();
                s2('#tblKanallar .kanal-firma');
                kanalSecicileriDoldur();
            }, 'json');
        }

        $('#tblKanallar').on('change', '.kanal-firma', function () {
            $.post('', { action: 'kanalFirma', kanal_id: $(this).data('kanal'), firma_id: $(this).val() }, function (res) {
                showToast(res.message, res.success ? 'success' : 'error');
                if (res.success) { kartlariYukle(); }
            }, 'json');
        });

        $('#tblKanallar').on('click', '.btn-kanal-test', function () {
            const btn = $(this).prop('disabled', true);
            $.post('', { action: 'baglantiTest', kanal_id: btn.data('kanal') }, testSonucu, 'json')
                .fail(() => showToast('Sunucu hatası', 'error'))
                .always(() => btn.prop('disabled', false));
        });

        // ---------------------------------------------------------------
        // Kanal kimlik bilgileri
        // ---------------------------------------------------------------
        const kanalModal = new bootstrap.Modal('#kanalModal');
        s2('#kFirma, #kPazaryeriSec', '#kanalModal');

        /** Alan listesinden form üret */
        function kimlikFormuCiz(alanlar) {
            const kap = $('#kimlikAlanlari').empty();

            if (!alanlar.length) {
                kap.append('<div class="alert alert-light border py-2 small mb-0">'
                    + 'Bu pazaryeri için tanımlı kimlik alanı yok. '
                    + '"Ek alan" ile istediğiniz anahtarı ekleyebilirsiniz.</div>');
                return;
            }

            alanlar.forEach(function (a) {
                const gizli = !!a.gizli;
                const dolu  = !!a.dolu;

                kap.append(
                    '<div class="row g-2 align-items-start mb-2">'
                  + '  <div class="col-md-4">'
                  + '    <label class="form-label mb-0">' + kacis(a.etiket || a.anahtar)
                  +        (a.zorunlu ? ' <span class="text-danger">*</span>' : '') + '</label>'
                  + '    <div class="form-text mt-0"><code>' + kacis(a.anahtar) + '</code></div>'
                  + '  </div>'
                  + '  <div class="col-md-8">'
                  + '    <input type="' + (gizli ? 'password' : 'text') + '"'
                  + '           class="form-control" name="kimlik[' + kacis(a.anahtar) + ']"'
                  + '           value="' + kacis(a.deger || '') + '"'
                  + '           autocomplete="new-password"'
                  + '           placeholder="' + (gizli && dolu ? 'Kayıtlı — değiştirmek için yazın' : '') + '">'
                  + (a.aciklama ? '    <div class="form-text">' + kacis(a.aciklama) + '</div>' : '')
                  + (gizli && dolu
                        ? '    <div class="form-text text-success">'
                          + '<i class="bi bi-check-circle me-1"></i>Kayıtlı. Boş bırakırsanız değişmez.</div>'
                        : '')
                  + '  </div>'
                  + '</div>'
                );
            });
        }

        $('#btnAlanEkle').on('click', function () {
            Swal.fire({
                title: 'Ek alan',
                input: 'text',
                inputLabel: 'Alan anahtarı (örn. SiteURL)',
                inputPlaceholder: 'HarfIleBaslar',
                showCancelButton: true,
                confirmButtonText: 'Ekle',
                cancelButtonText: 'Vazgeç',
                inputValidator: (v) => !/^[A-Za-z][A-Za-z0-9_]{0,59}$/.test(v || '')
                    ? 'Harfle başlamalı; harf, rakam ve alt çizgi içerebilir.' : null
            }).then((s) => {
                if (!s.isConfirmed || !s.value) return;
                $('#kimlikAlanlari').find('.alert').remove();
                $('#kimlikAlanlari').append(
                    '<div class="row g-2 align-items-start mb-2">'
                  + '  <div class="col-md-4"><label class="form-label mb-0">' + kacis(s.value) + '</label>'
                  + '    <div class="form-text mt-0"><code>' + kacis(s.value) + '</code></div></div>'
                  + '  <div class="col-md-8"><input type="text" class="form-control" autocomplete="new-password"'
                  + '         name="kimlik[' + kacis(s.value) + ']" value=""></div>'
                  + '</div>'
                );
            });
        });

        $('#tblKanallar').on('click', '.btn-kanal-duzenle', function () {
            $.post('', { action: 'kanalDetay', kanal_id: $(this).data('kanal') }, function (res) {
                if (!res.success) { showToast(res.message, 'error'); return; }
                const d = res.data;

                $('#kKanalId').val(d.kanal_id);
                $('#kPazaryeriKod').val('');
                $('#kPazaryeriAlani').addClass('d-none');
                $('#kKanalAdi').val(d.kanal_adi);
                $('#kFirma').val(d.firma_id || '').trigger('change.select2');
                $('#kVarsayilan').prop('checked', Number(d.varsayilan) === 1);
                $('#kDurum').prop('checked', Number(d.durum) === 1);
                $('#kanalBaslik').text(d.pazaryeri + ' — Kanal Bilgileri');
                $('#btnKanalTest').removeClass('d-none');

                kimlikFormuCiz(d.alanlar || []);
                kanalModal.show();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        });

        $('#btnYeniKanal').on('click', function () {
            $('#kanalForm')[0].reset();
            $('#kKanalId').val(0);
            $('#kPazaryeriKod').val('');
            $('#kanalBaslik').text('Yeni Kanal');
            $('#btnKanalTest').addClass('d-none');
            $('#kimlikAlanlari').empty();
            $('#kFirma').val(FIRMA_KISITLI && firmalar.length ? firmalar[0].firma_id : '').trigger('change.select2');

            // Pazaryeri seçimi yalnızca yeni kanalda görünür
            $('#kPazaryeriSec').html('<option value="">Seçiniz</option>' +
                pazaryeriListesi.map(p => '<option value="' + kacis(p.Pazaryerleri_Kod) + '"'
                    + (p.sinifVar ? '' : ' disabled')
                    + '>' + kacis(p.Pazaryerleri_Ad)
                    + (p.sinifVar ? '' : ' (entegrasyon yazılmadı)') + '</option>').join('')).trigger('change.select2');
            $('#kPazaryeriAlani').removeClass('d-none');
            kanalModal.show();
        });

        $('#kPazaryeriSec').on('change', function () {
            const kod = $(this).val();
            $('#kPazaryeriKod').val(kod);
            if (!kod) { $('#kimlikAlanlari').empty(); return; }

            $.post('', { action: 'kanalAlanlari', pazaryeri_kod: kod }, function (res) {
                kimlikFormuCiz(res.alanlar || []);
            }, 'json');
        });

        function kanalKaydet(sonra) {
            const veri = $('#kanalForm').serializeArray();
            if (!$('#kVarsayilan').is(':checked')) veri.push({ name: 'varsayilan', value: '0' });
            if (!$('#kDurum').is(':checked'))      veri.push({ name: 'kanal_durum', value: '0' });

            $.post('', veri, function (res) {
                if (!res.success) { showToast(res.message || 'Kaydedilemedi', 'error'); return; }
                showToast(res.message, 'success');
                kanallariYukle();
                kartlariYukle();
                loadStats();
                if (typeof sonra === 'function') { sonra(res.kanal_id); }
                else { kanalModal.hide(); }
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        }

        $('#kanalForm').on('submit', function (e) {
            e.preventDefault();
            kanalKaydet();
        });

        // Kaydet ve hemen test et
        $('#btnKanalTest').on('click', function () {
            kanalKaydet(function (kanalId) {
                $.post('', { action: 'baglantiTest', kanal_id: kanalId }, function (res) {
                    testSonucu(res);
                    if (res.success) { kanalModal.hide(); }
                }, 'json');
            });
        });

        // ---------------------------------------------------------------
        // Ayar tablosu (tanım tablosu → client-side)
        // ---------------------------------------------------------------
        const ayarTablo = $('#tblAyarlar').DataTable({
            dom: 'lrtip',
            scrollX: true,
            ajax: {
                url: '', type: 'POST', data: { action: 'ayarList' },
                dataSrc: function (res) {
                    if (!res.success) { showToast(res.message || 'Liste yüklenemedi', 'error'); return []; }
                    return res.data || [];
                }
            },
            columns: [
                { data: 'PazaryeriAyarlari_Anahtar', render: (d) => '<code>' + kacis(d) + '</code>' },
                {
                    data: 'Kapsam',
                    render: (d, t, r) => {
                        const etiket = d === 'kanal'
                            ? ((r.Pazaryerleri_Ad ? r.Pazaryerleri_Ad + ' · ' : '') + (r.EntegrasyonKanallari_Ad || 'Kanal')
                               + (r.firma_adi ? ' (' + r.firma_adi + ')' : ''))
                            : (d === 'pazaryeri' ? (r.Pazaryerleri_Ad || 'Pazaryeri') : 'Genel');
                        return t === 'display'
                            ? '<span class="yetenek-rozet kapsam-' + d + '">' + kacis(etiket) + '</span>'
                            : etiket;
                    }
                },
                {
                    data: 'PazaryeriAyarlari_Deger',
                    render: (d, t, r) => {
                        if (t !== 'display') return d ?? '';
                        if (r.PazaryeriAyarlari_Tip === 'bit') {
                            return Number(d) === 1
                                ? '<span class="badge text-bg-success">açık</span>'
                                : '<span class="badge text-bg-secondary">kapalı</span>';
                        }
                        const s = String(d ?? '');
                        return kacis(s.length > 60 ? s.substring(0, 60) + '…' : s);
                    }
                },
                { data: 'PazaryeriAyarlari_Tip', render: (d) => '<small class="text-muted">' + kacis(d) + '</small>' },
                { data: 'PazaryeriAyarlari_Aciklama', render: (d) => kacis(d || '-') },
                {
                    data: 'PazaryeriAyarlari_Durum', className: 'text-center', orderable: false,
                    render: (d) => Number(d) === 1
                        ? '<i class="bi bi-check-circle-fill text-success"></i>'
                        : '<i class="bi bi-dash-circle text-muted"></i>'
                },
                {
                    data: 'PazaryeriAyarlari_id', className: 'text-end', orderable: false,
                    render: (d, t, r) => {
                        // Genel / pazaryeri katmanını yalnız tüm firmalara yetkili kullanıcı değiştirir
                        const yetkili = r.Kapsam === 'kanal' || GLOBAL_YETKI;
                        return (permissions.can_edit && yetkili
                                ? '<button class="btn btn-sm btn-outline-primary btn-ayar-duzenle" data-id="' + d + '" title="Düzenle">'
                                  + '<i class="bi bi-pencil"></i></button> '
                                : '')
                             + (permissions.can_delete && (r.Kapsam === 'kanal' || !FIRMA_KISITLI)
                                ? '<button class="btn btn-sm btn-outline-danger btn-ayar-sil" data-id="' + d + '"'
                                  + ' data-anahtar="' + kacis(r.PazaryeriAyarlari_Anahtar) + '" title="Sil">'
                                  + '<i class="bi bi-trash"></i></button>'
                                : '');
                    }
                }
            ],
            order: [[0, 'asc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
        });

        // Filtre paneli: kapsam ve hedef (pazaryeri/kanal) özel arama, metin DataTables araması
        $.fn.dataTable.ext.search.push(function (ayarlar, veri, i) {
            if (ayarlar.nTable.id !== 'tblAyarlar') return true;
            const s = ayarTablo.row(i).data();
            if (!s) return true;

            const kapsam = $('#fKapsam').val();
            if (kapsam !== '' && s.Kapsam !== kapsam) return false;

            const hedef = $('#fHedef').val();
            if (hedef) {
                const [tur, id] = hedef.split(':');
                if (tur === 'p' && String(s.PazaryeriAyarlari_Pazaryerleri_id) !== id) return false;
                if (tur === 'k' && String(s.PazaryeriAyarlari_EntegrasyonKanallari_id) !== id) return false;
            }
            return true;
        });

        function ayarFiltre() { ayarTablo.search($('#fAyarAra').val()).draw(); }

        let aramaZaman = null;
        $('#fAyarAra').on('keyup', function () { clearTimeout(aramaZaman); aramaZaman = setTimeout(ayarFiltre, 250); });
        $('#fKapsam, #fHedef').on('change', ayarFiltre);
        $('#btnAyarTemizle').on('click', function () {
            $('#fAyarAra').val('');
            $('#fKapsam, #fHedef').val('').trigger('change.select2');
            ayarFiltre();
        });

        // ---------------------------------------------------------------
        // Ayar ekle / düzenle
        // ---------------------------------------------------------------
        const ayarModal = new bootstrap.Modal('#ayarModal');
        s2('#aKapsam, #aPazaryeri, #aKanal, #aTip', '#ayarModal');
        s2('#zKanal', '#zincirModal');
        s2('.filtre-select2');

        function kanalSecenekleri() {
            return kanalListesi.map(k =>
                '<option value="' + k.EntegrasyonKanallari_id + '">'
              + kacis(k.Pazaryerleri_Ad + ' — ' + k.EntegrasyonKanallari_Ad
                      + (k.firma_adi ? ' (' + k.firma_adi + ')' : '')
                      + (Number(k.EntegrasyonKanallari_Durum) === 1 ? '' : ' [pasif]'))
              + '</option>').join('');
        }

        function kanalSecicileriDoldur() {
            const secenekler = kanalSecenekleri();
            $('#aKanal').html('<option value="">Seçiniz</option>' + secenekler).trigger('change.select2');
            $('#zKanal').html('<option value="">Kanal seçmeden (yalnız genel katman)</option>' + secenekler).trigger('change.select2');
            hedefFiltresiDoldur();
        }

        function pazaryeriSecicileriDoldur() {
            $('#aPazaryeri').html('<option value="">Seçiniz</option>' +
                pazaryeriListesi.map(p => '<option value="' + p.Pazaryerleri_id + '">' + kacis(p.Pazaryerleri_Ad) + '</option>').join(''))
                .trigger('change.select2');
            hedefFiltresiDoldur();
        }

        function hedefFiltresiDoldur() {
            const secili = $('#fHedef').val();
            let html = '<option value="">Tümü</option>';
            if (pazaryeriListesi.length) {
                html += '<optgroup label="Pazaryeri">'
                      + pazaryeriListesi.map(p => '<option value="p:' + p.Pazaryerleri_id + '">' + kacis(p.Pazaryerleri_Ad) + '</option>').join('')
                      + '</optgroup>';
            }
            if (kanalListesi.length) {
                html += '<optgroup label="Kanal">'
                      + kanalListesi.map(k => '<option value="k:' + k.EntegrasyonKanallari_id + '">'
                          + kacis(k.Pazaryerleri_Ad + ' — ' + k.EntegrasyonKanallari_Ad) + '</option>').join('')
                      + '</optgroup>';
            }
            $('#fHedef').html(html).val(secili || '').trigger('change.select2');
        }

        function anahtarOnerileriYukle() {
            $.post('', { action: 'anahtarlar' }, function (res) {
                if (!res.success) return;
                $('#anahtarOnerileri').html(
                    res.data.map(a => '<option value="' + kacis(a.anahtar) + '">' + kacis(a.aciklama || '') + '</option>').join('')
                );
            }, 'json');
        }

        $('#aKapsam').on('change', function () {
            const k = $(this).val();
            $('#alanPazaryeri').toggleClass('d-none', k !== 'pazaryeri');
            $('#alanKanal').toggleClass('d-none', k !== 'kanal');
        });

        $('#btnYeniAyar').on('click', function () {
            $('#ayarForm')[0].reset();
            $('#aId').val(0);
            $('#aKapsam').val(GLOBAL_YETKI ? 'genel' : 'kanal').trigger('change');
            $('#aPazaryeri, #aKanal').val('').trigger('change.select2');
            $('#aTip').val('metin').trigger('change.select2');
            $('#aDurum').prop('checked', true);
            $('#ayarBaslik').text('Yeni Ayar');
            ayarModal.show();
        });

        $('#tblAyarlar').on('click', '.btn-ayar-duzenle', function () {
            $.post('', { action: 'ayarDetay', id: $(this).data('id') }, function (res) {
                if (!res.success) { showToast(res.message, 'error'); return; }
                const a = res.data;
                $('#aId').val(a.PazaryeriAyarlari_id);
                $('#aAnahtar').val(a.PazaryeriAyarlari_Anahtar);
                $('#aDeger').val(a.PazaryeriAyarlari_Deger);
                $('#aTip').val(a.PazaryeriAyarlari_Tip).trigger('change.select2');
                $('#aAciklama').val(a.PazaryeriAyarlari_Aciklama);
                $('#aDurum').prop('checked', Number(a.PazaryeriAyarlari_Durum) === 1);

                const kapsam = a.PazaryeriAyarlari_EntegrasyonKanallari_id ? 'kanal'
                             : (a.PazaryeriAyarlari_Pazaryerleri_id ? 'pazaryeri' : 'genel');
                $('#aKapsam').val(kapsam).trigger('change');
                $('#aKanal').val(a.PazaryeriAyarlari_EntegrasyonKanallari_id || '').trigger('change.select2');
                $('#aPazaryeri').val(a.PazaryeriAyarlari_Pazaryerleri_id || '').trigger('change.select2');

                $('#ayarBaslik').text('Ayar Düzenle');
                ayarModal.show();
            }, 'json');
        });

        $('#ayarForm').on('submit', function (e) {
            e.preventDefault();
            const veri = $(this).serializeArray();
            if (!$('#aDurum').is(':checked')) veri.push({ name: 'durum', value: '0' });

            $.post('', veri, function (res) {
                if (!res.success) { showToast(res.message || 'Kaydedilemedi', 'error'); return; }
                ayarModal.hide();
                showToast(res.message, 'success');
                ayarTablo.ajax.reload(null, false);
                loadStats();
                anahtarOnerileriYukle();
            }, 'json').fail(() => showToast('Sunucu hatası', 'error'));
        });

        $('#tblAyarlar').on('click', '.btn-ayar-sil', function () {
            const id = $(this).data('id');
            const anahtar = $(this).data('anahtar');
            confirmAction('Ayar silinsin mi?', anahtar + ' ayarı silinecek; alt katmandaki değer devreye girer.', function () {
                $.post('', { action: 'ayarSil', id: id }, function (res) {
                    showToast(res.message, res.success ? 'success' : 'error');
                    if (res.success) { ayarTablo.ajax.reload(null, false); loadStats(); }
                }, 'json');
            });
        });

        // ---------------------------------------------------------------
        // Zincir testi
        // ---------------------------------------------------------------
        const zincirModal = new bootstrap.Modal('#zincirModal');
        $('#btnZincir').on('click', () => { $('#zincirSonuc').empty(); zincirModal.show(); });

        $('#btnZincirCalistir').on('click', function () {
            const anahtar = $('#zAnahtar').val();
            if (!anahtar) { showToast('Anahtar giriniz', 'warning'); return; }

            $.post('', { action: 'ayarCozumle', anahtar: anahtar, kanal_id: $('#zKanal').val() }, function (res) {
                if (!res.success) { showToast(res.message, 'error'); return; }

                let html = '<div class="border rounded">';
                let kazananBulundu = false;

                res.data.katmanlar.forEach(function (k) {
                    const kazanan = !kazananBulundu && k.var;
                    if (kazanan) kazananBulundu = true;

                    html += '<div class="zincir-satir ' + (kazanan ? 'kazanan' : (k.var ? '' : 'bos')) + '">'
                          + (kazanan ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '<i class="bi bi-dash me-1"></i>')
                          + kacis(k.ad) + ': '
                          + (k.var ? '<code>' + kacis(k.deger) + '</code>' : '<span class="fst-italic">tanımlı değil</span>')
                          + (kazanan ? ' <span class="badge text-bg-success ms-1">kullanılan</span>' : '')
                          + '</div>';
                });

                html += '</div>';
                if (!kazananBulundu) {
                    html += '<div class="alert alert-warning mt-2 mb-0 py-2 small">'
                          + 'Hiçbir katmanda tanımlı değil; kodda verilen varsayılan kullanılır.</div>';
                }

                $('#zincirSonuc').html(html);
            }, 'json');
        });

        // ---------------------------------------------------------------
        kartlariYukle();
        kanallariYukle();
        anahtarOnerileriYukle();
        // InfoBox sayımları tablo yüklendikten sonra
        ayarTablo.one('draw', loadStats);
    });
    </script>
</body>
</html>
