<?php
/**
 * Admin Panel - Personel Puantaj Yönetimi
 * Personel günlük çalışma ve izin takibi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanın bilgilerini al
$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Personel Puantaj Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// İzin durumlarını çek
$izinDurumlari = $db->fetchAll("
    SELECT izin_durum_id, izin_durum_adi, izin_durum_kod, izin_durum_renk, izin_durum_gun_carpani
    FROM tanim_izin_durumlari 
    WHERE izin_durum_durum = 1 
    ORDER BY izin_durum_sira_no, izin_durum_adi
");

// Personel listesini çek (Sadece Personel, Taşeron hariç)
$personeller = $db->fetchAll("
    SELECT 
        k.kullanici_id,
        k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
        k.kullanici_calisma_departman_id,
        k.kullanici_firma_id,
        CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) as kullanici_ise_cikis_tarihi,
        d.departman_adi,
        f.firma_adi
    FROM kullanicilar k
    LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
    LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
    WHERE k.kullanici_durum = 1
    AND d.departman_personel = 1
    ORDER BY k.kullanici_ad, k.kullanici_soyad
");

// Departmanları çek (Sadece Personel departmanları)
$departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM Departmanlar WHERE departman_durum = 1 AND departman_personel = 1 ORDER BY departman_adi");

// Firmaları çek
$firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");

// Şubeleri çek
$subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");

// Excel aktarımı JSON değil dosya döndürdüğü için JSON başlığından önce ele alınır.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excel_indir') {
    if (!$pagePermissions['can_view']) {
        http_response_code(403);
        exit('Görüntüleme yetkiniz bulunmamaktadır.');
    }

    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate   = $_POST['end_date'] ?? $startDate;

    // Aktarılacak personel listesi (ekrandaki filtrelerle aynı)
    $pSql = "SELECT
                k.kullanici_id,
                k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) as kullanici_ise_cikis_tarihi,
                d.departman_adi,
                f.firma_adi
             FROM kullanicilar k
             LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
             LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
             WHERE k.kullanici_durum = 1 AND d.departman_personel = 1";
    $pParams = [];

    if (!empty($_POST['firma_id'])) {
        $pSql .= " AND k.kullanici_firma_id = ?";
        $pParams[] = $_POST['firma_id'];
    }
    if (!empty($_POST['departman_id'])) {
        $pSql .= " AND k.kullanici_calisma_departman_id = ?";
        $pParams[] = $_POST['departman_id'];
    }
    if (!empty($_POST['sube_id'])) {
        $pSql .= " AND k.kullanici_sube_id = ?";
        $pParams[] = $_POST['sube_id'];
    }
    if (!empty($_POST['personel_id'])) {
        $pSql .= " AND k.kullanici_id = ?";
        $pParams[] = $_POST['personel_id'];
    }
    $pSql .= " ORDER BY k.kullanici_ad, k.kullanici_soyad";
    $excelPersoneller = $db->fetchAll($pSql, $pParams);

    // Seçili aralıktaki puantaj kayıtları
    $kSql = "SELECT
                p.puantaj_kullanici_id,
                CONVERT(VARCHAR(10), p.puantaj_tarih, 120) as puantaj_tarih,
                p.puantaj_izin_durum_id,
                izd.izin_durum_adi
             FROM Personel_Puantaj p
             LEFT JOIN tanim_izin_durumlari izd ON p.puantaj_izin_durum_id = izd.izin_durum_id
             WHERE p.puantaj_aktif = 1
               AND CONVERT(DATE, p.puantaj_tarih) >= ?
               AND CONVERT(DATE, p.puantaj_tarih) <= ?";
    $kParams = [$startDate, $endDate];

    if (!empty($_POST['izin_durum_id'])) {
        $kSql .= " AND p.puantaj_izin_durum_id = ?";
        $kParams[] = $_POST['izin_durum_id'];
    }
    $kayitlar = $db->fetchAll($kSql, $kParams);

    $puantajMap = [];
    foreach ($kayitlar as $k) {
        $puantajMap[$k['puantaj_kullanici_id'] . '_' . $k['puantaj_tarih']] = $k;
    }

    // Aralıktaki günler
    $gunler = [];
    $gun = new DateTime($startDate);
    $son = new DateTime($endDate);
    while ($gun <= $son) {
        $gunler[] = $gun->format('Y-m-d');
        $gun->modify('+1 day');
    }

    $gunAdlari = [
        'Mon' => 'Pzt', 'Tue' => 'Sal', 'Wed' => 'Çar', 'Thu' => 'Per',
        'Fri' => 'Cum', 'Sat' => 'Cmt', 'Sun' => 'Paz'
    ];

    $filename = 'personel_puantaj_' . $startDate . '_' . $endDate . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM (Türkçe karakterler için)
    echo "\xEF\xBB\xBF";

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
    echo '<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    echo '<x:Name>Puantaj</x:Name>';
    echo '<x:WorksheetOptions><x:Print><x:ValidPrinterInfo/></x:Print></x:WorksheetOptions>';
    echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>';
    echo '</head><body>';
    echo '<table border="1">';
    echo '<thead><tr style="background-color: #0d6efd; color: white; font-weight: bold;">';
    echo '<th>Personel</th><th>Çalıştığı Departman</th><th>Firma</th>';
    foreach ($gunler as $g) {
        $ts = strtotime($g);
        echo '<th>' . ($gunAdlari[date('D', $ts)] ?? '') . ' ' . date('d.m.Y', $ts) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($excelPersoneller as $p) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($p['personel_adi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($p['departman_adi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($p['firma_adi'] ?? '') . '</td>';

        foreach ($gunler as $g) {
            $cikis = $p['kullanici_ise_cikis_tarihi'] ?? null;
            if ($cikis && $g > $cikis) {
                echo '<td>Çıkış</td>';
                continue;
            }

            $kayit = $puantajMap[$p['kullanici_id'] . '_' . $g] ?? null;
            if (!$kayit) {
                echo '<td></td>';
            } elseif (empty($kayit['puantaj_izin_durum_id'])) {
                echo '<td>Normal Çalışma</td>';
            } else {
                echo '<td>' . htmlspecialchars($kayit['izin_durum_adi'] ?? '') . '</td>';
            }
        }
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir - İzin durumlarına göre
                $baseSql = "SELECT COUNT(*) as sayi FROM Personel_Puantaj WHERE puantaj_aktif = 1";
                $params = [];
                
                // Bu hafta filtresi
                $buHaftaSql = $baseSql . " AND puantaj_tarih >= DATEADD(day, 1 - DATEPART(weekday, GETDATE()), CAST(GETDATE() AS DATE)) 
                                          AND puantaj_tarih < DATEADD(day, 8 - DATEPART(weekday, GETDATE()), CAST(GETDATE() AS DATE))";
                
                // İzin durumlarına göre sayılar (Bu hafta)
                $izinDurumlari = $db->fetchAll("
                    SELECT 
                        id.izin_durum_id,
                        id.izin_durum_adi,
                        id.izin_durum_renk,
                        ISNULL(id.izin_durum_sira_no, 999) as sira_no,
                        COUNT(pp.puantaj_id) as sayi
                    FROM tanim_izin_durumlari id
                    LEFT JOIN Personel_Puantaj pp ON id.izin_durum_id = pp.puantaj_izin_durum_id 
                        AND pp.puantaj_aktif = 1
                        AND pp.puantaj_tarih >= DATEADD(day, 1 - DATEPART(weekday, GETDATE()), CAST(GETDATE() AS DATE))
                        AND pp.puantaj_tarih < DATEADD(day, 8 - DATEPART(weekday, GETDATE()), CAST(GETDATE() AS DATE))
                    WHERE id.izin_durum_durum = 1
                    GROUP BY id.izin_durum_id, id.izin_durum_adi, id.izin_durum_renk, id.izin_durum_sira_no
                    ORDER BY ISNULL(id.izin_durum_sira_no, 999), id.izin_durum_adi
                ");
                
                $stats = [
                    'toplam' => $db->fetchOne($baseSql, $params)['sayi'] ?? 0,
                    'bu_hafta' => $db->fetchOne($buHaftaSql, $params)['sayi'] ?? 0,
                    'izin_durumlari' => $izinDurumlari
                ];
                
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametrelerini al
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $izinDurumId = $_POST['izin_durum_id'] ?? '';
                $departmanId = $_POST['departman_id'] ?? '';
                $firmaId = $_POST['firma_id'] ?? '';
                $subeId = $_POST['sube_id'] ?? '';
                
                // SQL sorgusu (Personel_Puantaj tablosundan direkt çek, performans için)
                $sql = "
                    SELECT 
                        p.puantaj_id,
                        p.puantaj_kullanici_id,
                        CONVERT(VARCHAR(10), p.puantaj_tarih, 120) as puantaj_tarih,
                        p.puantaj_izin_durum_id,
                        p.puantaj_olusturan_kullanici_id,
                        CONVERT(VARCHAR(19), p.puantaj_olusturma_tarihi, 120) as puantaj_olusturma_tarihi,
                        p.puantaj_guncelleyen_kullanici_id,
                        CONVERT(VARCHAR(19), p.puantaj_guncelleme_tarihi, 120) as puantaj_guncelleme_tarihi,
                        p.puantaj_aktif,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                        k.kullanici_email as personel_email,
                        k.kullanici_telefon as personel_telefon,
                        k.kullanici_firma_id,
                        k.kullanici_calisma_departman_id,
                        k.kullanici_sube_id,
                        f.firma_adi,
                        dep.departman_adi,
                        dep.departman_personel,
                        izd.izin_durum_adi,
                        izd.izin_durum_kod,
                        izd.izin_durum_renk,
                        izd.izin_durum_dosya_gerekli,
                        izd.izin_durum_gun_carpani,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as olusturan_adi,
                        kg.kullanici_ad + ' ' + kg.kullanici_soyad as guncelleyen_adi,
                        DATENAME(dw, p.puantaj_tarih) as gun_adi,
                        DATEPART(dw, p.puantaj_tarih) as gun_no,
                        DATEPART(year, p.puantaj_tarih) as yil,
                        DATEPART(month, p.puantaj_tarih) as ay,
                        CASE 
                            WHEN p.puantaj_izin_durum_id IS NOT NULL THEN izd.izin_durum_adi
                            ELSE 'Normal Çalışma'
                        END as durum_ozet
                    FROM Personel_Puantaj p
                    LEFT JOIN kullanicilar k ON p.puantaj_kullanici_id = k.kullanici_id
                    LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                    LEFT JOIN Departmanlar dep ON k.kullanici_calisma_departman_id = dep.departman_id
                    LEFT JOIN tanim_izin_durumlari izd ON p.puantaj_izin_durum_id = izd.izin_durum_id
                    LEFT JOIN kullanicilar ko ON p.puantaj_olusturan_kullanici_id = ko.kullanici_id
                    LEFT JOIN kullanicilar kg ON p.puantaj_guncelleyen_kullanici_id = kg.kullanici_id
                    WHERE p.puantaj_aktif = 1
                    AND (dep.departman_personel = 1 OR dep.departman_personel IS NULL)
                ";
                $params = [];
                
                // Tarih filtresi
                if ($startDate) {
                    $sql .= " AND CONVERT(DATE, p.puantaj_tarih) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND CONVERT(DATE, p.puantaj_tarih) <= ?";
                    $params[] = $endDate;
                }
                
                // Personel filtresi
                if ($personelId) {
                    $sql .= " AND p.puantaj_kullanici_id = ?";
                    $params[] = $personelId;
                }
                
                // İzin durumu filtresi
                if ($izinDurumId) {
                    $sql .= " AND p.puantaj_izin_durum_id = ?";
                    $params[] = $izinDurumId;
                } elseif (isset($_POST['izin_durum_id']) && $_POST['izin_durum_id'] === '0') {
                    // Normal çalışma (izin yok)
                    $sql .= " AND p.puantaj_izin_durum_id IS NULL";
                }
                
                // Firma filtresi
                if ($firmaId) {
                    $sql .= " AND k.kullanici_firma_id = ?";
                    $params[] = $firmaId;
                }
                
                // Şube filtresi
                if ($subeId) {
                    $sql .= " AND k.kullanici_sube_id = ?";
                    $params[] = $subeId;
                }
                
                // Departman filtresi
                if ($departmanId) {
                    $sql .= " AND k.kullanici_calisma_departman_id = ?";
                    $params[] = $departmanId;
                }
                
                $sql .= " ORDER BY p.puantaj_tarih DESC, personel_adi";
                
                $data = $db->fetchAll($sql, $params);
                
                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("
                    SELECT 
                        puantaj_id,
                        puantaj_kullanici_id,
                        CONVERT(VARCHAR(10), puantaj_tarih, 120) as puantaj_tarih,
                        puantaj_izin_durum_id,
                        puantaj_olusturan_kullanici_id,
                        CONVERT(VARCHAR(19), puantaj_olusturma_tarihi, 120) as puantaj_olusturma_tarihi,
                        puantaj_guncelleyen_kullanici_id,
                        CONVERT(VARCHAR(19), puantaj_guncelleme_tarihi, 120) as puantaj_guncelleme_tarihi,
                        puantaj_aktif
                    FROM Personel_Puantaj 
                    WHERE puantaj_id = ?
                ", [$id]);
                
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'save':
                // Kaydet/Güncelle
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $kullaniciId = $_POST['kullanici_id'] ?? 0;
                $tarih = $_POST['tarih'] ?? '';
                $izinDurumId = !empty($_POST['izin_durum_id']) ? intval($_POST['izin_durum_id']) : null;
                
                // Aynı personel ve tarihte kayıt var mı kontrol et
                $mevcutKayit = $db->fetchOne("
                    SELECT puantaj_id 
                    FROM Personel_Puantaj 
                    WHERE puantaj_kullanici_id = ? 
                    AND puantaj_tarih = ? 
                    AND puantaj_aktif = 1
                    " . ($id > 0 ? "AND puantaj_id != ?" : ""),
                    $id > 0 ? [$kullaniciId, $tarih, $id] : [$kullaniciId, $tarih]
                );
                
                if ($mevcutKayit) {
                    echo json_encode(['success' => false, 'message' => 'Bu personel için seçilen tarihte zaten puantaj kaydı mevcut!']);
                    break;
                }
                
                // Çıkış tarihi kontrolü: Çıkış tarihinden sonrasına puantaj kaydı eklenemez
                $personelBilgi = $db->fetchOne("
                    SELECT CONVERT(VARCHAR(10), kullanici_ise_cikis_tarihi, 120) as cikis_tarihi
                    FROM kullanicilar WHERE kullanici_id = ?
                ", [$kullaniciId]);
                
                if ($personelBilgi && $personelBilgi['cikis_tarihi'] && $tarih > $personelBilgi['cikis_tarihi']) {
                    echo json_encode(['success' => false, 'message' => 'Bu personelin çıkış tarihi (' . date('d.m.Y', strtotime($personelBilgi['cikis_tarihi'])) . ') sonrasına puantaj kaydı eklenemez!']);
                    break;
                }
                
                $data = [
                    'puantaj_kullanici_id' => $kullaniciId,
                    'puantaj_tarih' => $tarih,
                    'puantaj_izin_durum_id' => $izinDurumId
                ];
                
                if ($id > 0) {
                    // Güncelleme
                    if (!$pagePermissions['can_edit']) {
                        echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['puantaj_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    $data['puantaj_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $db->update('Personel_Puantaj', $data, ['puantaj_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Puantaj kaydı güncellendi!']);
                } else {
                    // Yeni kayıt
                    if (!$pagePermissions['can_add']) {
                        echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                        break;
                    }
                    
                    $data['puantaj_olusturan_kullanici_id'] = $user['kullanici_id'];
                    $data['puantaj_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['puantaj_aktif'] = 1;
                    $db->insert('Personel_Puantaj', $data);
                    echo json_encode(['success' => true, 'message' => 'Puantaj kaydı eklendi!']);
                }
                break;
                
            case 'save_bulk':
                // Toplu puantaj kaydetme
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                $puantajlar = json_decode($_POST['puantajlar'] ?? '[]', true);
                
                if (empty($puantajlar)) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı!']);
                    break;
                }
                
                $eklenenSayisi = 0;
                $hatalilar = [];
                
                foreach ($puantajlar as $puantaj) {
                    $kullaniciId = $puantaj['kullanici_id'] ?? 0;
                    $tarih = $puantaj['tarih'] ?? '';
                    $izinDurumId = !empty($puantaj['izin_durum_id']) ? intval($puantaj['izin_durum_id']) : null;
                    
                    // Aynı tarih ve personelde kayıt var mı?
                    $mevcutKayit = $db->fetchOne("
                        SELECT puantaj_id 
                        FROM Personel_Puantaj 
                        WHERE puantaj_kullanici_id = ? 
                        AND puantaj_tarih = ? 
                        AND puantaj_aktif = 1
                    ", [$kullaniciId, $tarih]);
                    
                    if ($mevcutKayit) {
                        $hatalilar[] = "Tarih: $tarih - Personel ID: $kullaniciId (Zaten mevcut)";
                        continue;
                    }
                    
                    $data = [
                        'puantaj_kullanici_id' => $kullaniciId,
                        'puantaj_tarih' => $tarih,
                        'puantaj_izin_durum_id' => $izinDurumId,
                        'puantaj_olusturan_kullanici_id' => $user['kullanici_id'],
                        'puantaj_olusturma_tarihi' => date('Y-m-d H:i:s'),
                        'puantaj_aktif' => 1
                    ];
                    
                    try {
                        $db->insert('Personel_Puantaj', $data);
                        $eklenenSayisi++;
                    } catch (Exception $e) {
                        $hatalilar[] = "Tarih: $tarih - Personel ID: $kullaniciId (Hata: " . $e->getMessage() . ")";
                    }
                }
                
                $mesaj = "$eklenenSayisi puantaj kaydı eklendi.";
                if (!empty($hatalilar)) {
                    $mesaj .= " " . count($hatalilar) . " kayıt eklenemedi.";
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => $mesaj,
                    'eklenen' => $eklenenSayisi,
                    'hatalilar' => $hatalilar
                ]);
                break;
                
            case 'delete':
                // Sil (Soft Delete)
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->update('Personel_Puantaj', ['puantaj_aktif' => 0], ['puantaj_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Puantaj kaydı silindi!']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        /* Tablo işlem butonları */
        .btn-group-sm > .btn, .btn-sm {
            padding: 0.25rem 0.5rem;
            margin: 0 2px;
        }
        
        /* Badge stilleri */
        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        
        /* İzin durumu badge'leri için dinamik renkler */
        .izin-badge {
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Info box hover */
        .info-box {
            transition: transform 0.2s;
        }
        
        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        /* Toplu işlem formu */
        #bulkFormContainer {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .personel-row {
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .personel-row:hover {
            background-color: #f8f9fa;
        }
        
        /* Haftalık Puantaj Tablosu Stilleri */
        #puantajTable {
            font-size: 0.9rem;
        }
        
        #puantajTable thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            vertical-align: middle;
            position: sticky;
            top: 0;
            z-index: 20;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        }
        
        #puantajTable .sticky-col {
            position: sticky;
            left: 0;
            background-color: #fff;
            z-index: 10;
            font-weight: 500;
            box-shadow: 2px 0 2px -1px rgba(0, 0, 0, 0.1);
        }
        
        #puantajTable thead .sticky-col {
            background-color: #f8f9fa;
            z-index: 30;
            box-shadow: 2px 2px 2px -1px rgba(0, 0, 0, 0.1);
        }
        
        #puantajTable .day-header {
            min-width: 120px;
            font-size: 0.85rem;
        }
        
        #puantajTable .day-header.weekend {
            background-color: #fff3cd !important;
        }
        
        #puantajTable .day-cell {
            text-align: center;
            vertical-align: middle;
            padding: 5px;
            min-height: 50px;
        }
        
        #puantajTable .day-cell.weekend-day {
            background-color: #fff9e6;
        }
        
        #puantajTable .personel-name {
            font-weight: 500;
        }
        
        #puantajTable .personel-dept {
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        .day-date {
            display: block;
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        /* Puantaj dropdown stilleri */
        .puantaj-select {
            width: 100%;
            font-size: 0.8rem;
            padding: 4px 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background-color: #fff;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .puantaj-select:hover {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
        }
        
        .puantaj-select:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        
        .puantaj-select.status-izin {
            color: white;
            font-weight: 500;
        }
        
        .puantaj-select.status-empty {
            background-color: #f8f9fa;
            color: #6c757d;
        }
        
        /* Select değişikliği animasyonu */
        .puantaj-select.saving {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .puantaj-select.saved {
            animation: pulse 0.5s;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        /* Table responsive container için sticky header desteği */
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başlığı -->
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
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
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes (Dinamik) -->
                    <div class="row mb-3" id="info-boxes-container"></div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtreler
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="collapse" id="filterCollapse">
                            <div class="card-body">
                                <form id="filterForm">
                                    <div class="row g-3">
                                        <!-- Personel -->
                                        <div class="col-md-4">
                                            <label class="form-label">Personel</label>
                                            <select class="form-select" id="filter_personel_id" name="personel_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($personeller as $personel): ?>
                                                    <option value="<?= $personel['kullanici_id'] ?>">
                                                        <?= htmlspecialchars($personel['personel_adi']) ?>
                                                        <?= $personel['departman_adi'] ? ' (' . htmlspecialchars($personel['departman_adi']) . ')' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Firma -->
                                        <div class="col-md-3">
                                            <label class="form-label">Firma</label>
                                            <select class="form-select" id="filter_firma_id" name="firma_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($firmalar as $firma): ?>
                                                    <option value="<?= $firma['firma_id'] ?>">
                                                        <?= htmlspecialchars($firma['firma_adi']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Şube -->
                                        <div class="col-md-3">
                                            <label class="form-label">Şube</label>
                                            <select class="form-select" id="filter_sube_id" name="sube_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($subeler as $sube): ?>
                                                    <option value="<?= $sube['sube_id'] ?>">
                                                        <?= htmlspecialchars($sube['sube_adi']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Departman -->
                                        <div class="col-md-3">
                                            <label class="form-label">Çalıştığı Departman</label>
                                            <select class="form-select" id="filter_departman_id" name="departman_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($departmanlar as $departman): ?>
                                                    <option value="<?= $departman['departman_id'] ?>">
                                                        <?= htmlspecialchars($departman['departman_adi']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- İzin Durumu -->
                                        <div class="col-md-3">
                                            <label class="form-label">Durum Filtresi</label>
                                            <select class="form-select" id="filter_izin_durum_id" name="izin_durum_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($izinDurumlari as $izin): ?>
                                                    <option value="<?= $izin['izin_durum_id'] ?>">
                                                        <?= htmlspecialchars($izin['izin_durum_adi']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Butonlar -->
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-search"></i> Filtrele
                                            </button>
                                            <button type="button" class="btn btn-secondary" id="clearFilters">
                                                <i class="bi bi-x-circle"></i> Temizle
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Haftalık Puantaj Tablosu -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-calendar-week"></i> Haftalık Puantaj Tablosu
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm me-2" id="excelIndir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                                <div class="btn-group me-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnPrevWeek">
                                        <i class="bi bi-chevron-left"></i> Önceki Hafta
                                    </button>
                                    <button type="button" class="btn btn-sm btn-primary" id="btnThisWeek">
                                        Bu Hafta
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnNextWeek">
                                        Sonraki Hafta <i class="bi bi-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle"></i> 
                                <strong id="weekRangeText">Bu Hafta</strong>
                                <span class="float-end">
                                    <small>Dropdown'dan durum seçerek otomatik kaydedebilirsiniz</small>
                                </span>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="puantajTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="200" class="sticky-col">Personel</th>
                                            <th class="text-center day-header">Pzt</th>
                                            <th class="text-center day-header">Sal</th>
                                            <th class="text-center day-header">Çar</th>
                                            <th class="text-center day-header">Per</th>
                                            <th class="text-center day-header">Cum</th>
                                            <th class="text-center day-header weekend">Cmt</th>
                                            <th class="text-center day-header weekend">Paz</th>
                                        </tr>
                                    </thead>
                                    <tbody id="puantajTableBody">
                                        <tr>
                                            <td colspan="8" class="text-center">Yükleniyor...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        
        // İzin durumları (JavaScript için)
        const izinDurumlari = <?= json_encode($izinDurumlari) ?>;
        
        // Personel listesi
        const personelList = <?= json_encode($personeller) ?>;
        
        // Mevcut hafta
        let currentWeekStart = getMonday(new Date());
        
        // Pazartesi gününü bul
        function getMonday(date) {
            const d = new Date(date);
            const day = d.getDay();
            const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Pazar ise -6, diğer günler +1
            return new Date(d.setDate(diff));
        }
        
        // Hafta aralığını formatla
        function formatWeekRange(startDate) {
            const endDate = new Date(startDate);
            endDate.setDate(endDate.getDate() + 6);
            
            const startStr = startDate.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' });
            const endStr = endDate.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' });
            
            return `${startStr} - ${endStr}`;
        }
        
        // Haftanın günlerini al
        function getWeekDays(startDate) {
            const days = [];
            for (let i = 0; i < 7; i++) {
                const date = new Date(startDate);
                date.setDate(date.getDate() + i);
                days.push(date);
            }
            return days;
        }
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    const stats = response.data;
                    const izinDurumlari = stats.izin_durumlari || [];
                    
                    // İzin durumu sayısına göre kolon genişliğini hesapla
                    let colClass = 'col-md-3'; // Varsayılan: 4 kolon
                    if (izinDurumlari.length === 1) {
                        colClass = 'col-md-12';
                    } else if (izinDurumlari.length === 2) {
                        colClass = 'col-md-6';
                    } else if (izinDurumlari.length === 3) {
                        colClass = 'col-md-4';
                    } else if (izinDurumlari.length >= 4) {
                        colClass = 'col-md-3';
                    }
                    
                    // Tüm info box'ları dinamik oluştur
                    let boxesHtml = '';
                    izinDurumlari.forEach(izin => {
                        boxesHtml += `
                            <div class="col-12 col-sm-6 ${colClass}">
                                <div class="info-box">
                                    <span class="info-box-icon shadow-sm" style="background-color: ${izin.izin_durum_renk || '#6c757d'};">
                                        <i class="bi bi-calendar-event"></i>
                                    </span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">${izin.izin_durum_adi}</span>
                                        <span class="info-box-number">${izin.sayi}</span>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    $('#info-boxes-container').html(boxesHtml);
                }
            });
        }
        
        // Filtre işlemleri
        let currentFilters = {};
        
        // Haftalık puantaj tablosunu yükle
        function loadWeeklyTable() {
            const weekDays = getWeekDays(currentWeekStart);
            const startDate = weekDays[0].toISOString().split('T')[0];
            const endDate = weekDays[6].toISOString().split('T')[0];
            
            // Hafta aralığını güncelle
            $('#weekRangeText').text(formatWeekRange(currentWeekStart));
            
            // Gün başlıklarını güncelle
            const dayHeaders = $('.day-header');
            const dayNames = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
            weekDays.forEach((date, index) => {
                const dayNum = date.getDate();
                const monthNum = date.getMonth() + 1;
                $(dayHeaders[index]).html(`${dayNames[index]}<br><small class="day-date">${dayNum}/${monthNum}</small>`);
            });
            
            // Filtreye göre personelleri belirle
            let filteredPersonel = [...personelList];
            
            if (currentFilters.firma_id) {
                filteredPersonel = filteredPersonel.filter(p => p.kullanici_firma_id == currentFilters.firma_id);
            }
            
            if (currentFilters.sube_id) {
                filteredPersonel = filteredPersonel.filter(p => p.kullanici_sube_id == currentFilters.sube_id);
            }
            
            if (currentFilters.departman_id) {
                filteredPersonel = filteredPersonel.filter(p => p.kullanici_calisma_departman_id == currentFilters.departman_id);
            }
            
            if (currentFilters.personel_id) {
                filteredPersonel = filteredPersonel.filter(p => p.kullanici_id == currentFilters.personel_id);
            }
            
            // Haftalık puantaj verilerini çek
            console.log('AJAX isteği gönderiliyor...', {
                action: 'list',
                start_date: startDate,
                end_date: endDate,
                filters: currentFilters
            });
            
            $.post('', {
                action: 'list',
                start_date: startDate,
                end_date: endDate,
                ...currentFilters
            }, response => {
                if (response.success) {
                    renderWeeklyTable(filteredPersonel, weekDays, response.data);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(function(xhr, status, error) {
                console.error('AJAX hatası:', {xhr, status, error});
                console.error('Response Text:', xhr.responseText);
                showToast('Veri yüklenirken hata oluştu: ' + error, 'error');
                $('#puantajTableBody').html('<tr><td colspan="8" class="text-center text-danger">Hata: ' + error + '</td></tr>');
            });
        }
        
        // Haftalık tabloyu render et
        function renderWeeklyTable(personelList, weekDays, puantajData) {
            console.log('renderWeeklyTable çağrıldı:', {
                personelSayisi: personelList.length,
                gunSayisi: weekDays.length,
                puantajSayisi: puantajData.length
            });
            
            const tbody = $('#puantajTableBody');
            tbody.empty();
            
            if (personelList.length === 0) {
                tbody.html('<tr><td colspan="8" class="text-center">Personel bulunamadı</td></tr>');
                return;
            }
            
            // Puantaj verisini map'e çevir (hızlı erişim için)
            const puantajMap = {};
            puantajData.forEach(p => {
                const key = `${p.puantaj_kullanici_id}_${p.puantaj_tarih}`;
                puantajMap[key] = p;
            });
            
            // Her personel için satır oluştur
            personelList.forEach(personel => {
                let row = `<tr>
                    <td class="sticky-col">
                        <div class="personel-name">${personel.personel_adi}</div>
                    </td>`;
                
                // Her gün için hücre oluştur
                weekDays.forEach((date, index) => {
                    const dateStr = date.toISOString().split('T')[0];
                    const key = `${personel.kullanici_id}_${dateStr}`;
                    const puantaj = puantajMap[key];
                    const isWeekend = index >= 5; // Cumartesi ve Pazar
                    
                    let cellClass = 'day-cell';
                    if (isWeekend) {
                        cellClass += ' weekend-day';
                    }
                    
                    // Çıkış tarihi kontrolü (çıkış günü dahil sonrası gizlenir)
                    const cikisTarihi = personel.kullanici_ise_cikis_tarihi || null;
                    if (cikisTarihi && dateStr > cikisTarihi) {
                        row += `<td class="${cellClass}">
                            <span class="badge bg-secondary" title="Çıkış: ${cikisTarihi}">Çıkış</span>
                        </td>`;
                        return; // Bu gün için dropdown oluşturma
                    }
                    
                    // Dropdown oluştur
                    let selectClass = 'puantaj-select';
                    let selectStyle = '';
                    let selectedValue = '';
                    
                    if (puantaj) {
                        selectedValue = puantaj.puantaj_izin_durum_id;
                        
                        if (selectedValue && puantaj.izin_durum_renk) {
                            // İzinli (puantaj_izin_durum_id dolu)
                            selectClass += ' status-izin';
                            const renk = puantaj.izin_durum_renk;
                            selectStyle = `background-color: ${renk}; border-color: ${renk};`;
                        } else {
                            // Normal çalışma (puantaj_izin_durum_id NULL)
                            selectClass += ' status-geldi';
                        }
                    } else {
                        // Kayıt yok - Varsayılan olarak izin_durum_id=1 seçili gelsin
                        selectClass += ' status-izin';
                        selectedValue = '1';
                        // İlk izin durumunun rengini al
                        const ilkIzin = izinDurumlari.find(i => i.izin_durum_id == 1);
                        if (ilkIzin && ilkIzin.izin_durum_renk) {
                            selectStyle = `background-color: ${ilkIzin.izin_durum_renk}; border-color: ${ilkIzin.izin_durum_renk};`;
                        }
                    }
                    
                    // Dropdown options (selected attribute ile)
                    let options = `<option value="" ${selectedValue === '' ? 'selected' : ''}>-</option>`;
                    
                    izinDurumlari.forEach(izin => {
                        const isSelected = selectedValue == izin.izin_durum_id ? 'selected' : '';
                        options += `<option value="${izin.izin_durum_id}" data-renk="${izin.izin_durum_renk}" ${isSelected}>${izin.izin_durum_adi}</option>`;
                    });
                    
                    row += `<td class="${cellClass}">
                        <select class="${selectClass}" 
                                style="${selectStyle}"
                                data-kullanici-id="${personel.kullanici_id}"
                                data-tarih="${dateStr}"
                                data-puantaj-id="${puantaj ? puantaj.puantaj_id : ''}"
                                onchange="savePuantajFromSelect(this)">
                            ${options}
                        </select>
                    </td>`;
                });
                
                row += '</tr>';
                tbody.append(row);
            });
            
            // Select değerlerini ayarla (HTML render sonrası)
            setTimeout(() => {
                personelList.forEach(personel => {
                    weekDays.forEach(date => {
                        const dateStr = date.toISOString().split('T')[0];
                        const key = `${personel.kullanici_id}_${dateStr}`;
                        const puantaj = puantajMap[key];
                        
                        const $select = $(`.puantaj-select[data-kullanici-id="${personel.kullanici_id}"][data-tarih="${dateStr}"]`);
                        
                        if (puantaj) {
                            // puantaj_izin_durum_id null veya boş değilse set et
                            const value = puantaj.puantaj_izin_durum_id;
                            if (value !== null && value !== undefined && value !== '') {
                                $select.val(value);
                            } else {
                                // Kayıt var ama izin_durum_id NULL (normal çalışma)
                                $select.val('');
                            }
                            
                            // Renk set et
                            if (value && puantaj.izin_durum_renk) {
                                $select.css({
                                    'background-color': puantaj.izin_durum_renk,
                                    'border-color': puantaj.izin_durum_renk
                                });
                                $select.removeClass('status-geldi').addClass('status-izin');
                            } else {
                                // Normal çalışma (Geldi)
                                $select.css({
                                    'background-color': '',
                                    'border-color': ''
                                });
                                $select.removeClass('status-izin').addClass('status-geldi');
                            }
                        } else {
                            // Kayıt yok - Varsayılan olarak izin_durum_id=1 seçili
                            $select.val('1');
                            const ilkIzin = izinDurumlari.find(i => i.izin_durum_id == 1);
                            if (ilkIzin && ilkIzin.izin_durum_renk) {
                                $select.css({
                                    'background-color': ilkIzin.izin_durum_renk,
                                    'border-color': ilkIzin.izin_durum_renk
                                });
                            }
                        }
                    });
                });
            }, 50);
        }
        
        // Select'ten puantaj kaydet
        function savePuantajFromSelect(selectElement) {
            if (!permissions.can_add && !permissions.can_edit) {
                showToast('Bu işlem için yetkiniz yok!', 'warning');
                $(selectElement).val($(selectElement).data('previous-value') || '');
                return;
            }
            
            const $select = $(selectElement);
            const kullaniciId = $select.data('kullanici-id');
            const tarih = $select.data('tarih');
            const puantajId = $select.data('puantaj-id');
            const yeniDurum = $select.val();
            
            // Boş seçilirse kayıt sil
            if (yeniDurum === '' && puantajId) {
                confirmAction(
                    'Bu puantaj kaydını silmek istiyor musunuz?',
                    null,
                    function() {
                        deletePuantajFromSelect(selectElement, puantajId);
                    },
                    function() {
                        // İptal edilirse eski değere geri dön
                        $select.val($select.data('previous-value') || '');
                    }
                );
                return;
            }
            
            // Kayıt yoksa ve boş/varsayılan seçilmişse hiçbir şey yapma
            if (!puantajId && (yeniDurum === '' || yeniDurum === '1')) {
                return;
            }
            
            // Kaydetme animasyonu
            $select.addClass('saving');
            
            // Kaydet
            $.post('', {
                action: 'save',
                id: puantajId || 0,
                kullanici_id: kullaniciId,
                tarih: tarih,
                izin_durum_id: yeniDurum
            }, response => {
                $select.removeClass('saving');
                
                if (response.success) {
                    // Başarılı animasyon
                    $select.addClass('saved');
                    setTimeout(() => $select.removeClass('saved'), 500);
                    
                    // Önceki değeri güncelle
                    $select.data('previous-value', yeniDurum);
                    
                    // Select stilini güncelle
                    updateSelectStyle($select, yeniDurum);
                    
                    // İstatistikleri güncelle
                    loadStats();
                    
                    // Haftalık tabloyu yeniden yükle (puantaj_id güncellemesi için)
                    setTimeout(() => loadWeeklyTable(), 500);
                } else {
                    showToast(response.message, 'error');
                    // Eski değere geri dön
                    $select.val($select.data('previous-value') || '');
                }
            }).fail(function() {
                $select.removeClass('saving');
                showToast('Kayıt işlemi başarısız!', 'error');
                // Eski değere geri dön
                $select.val($select.data('previous-value') || '');
            });
        }
        
        // Select'ten kayıt sil
        function deletePuantajFromSelect(selectElement, puantajId) {
            const $select = $(selectElement);
            $select.addClass('saving');
            
            $.post('', { action: 'delete', id: puantajId }, response => {
                $select.removeClass('saving');
                
                if (response.success) {
                    showToast(response.message, 'success');
                    $select.val('');
                    $select.data('previous-value', '');
                    $select.data('puantaj-id', '');
                    updateSelectStyle($select, '');
                    loadStats();
                } else {
                    showToast(response.message, 'error');
                    $select.val($select.data('previous-value') || '');
                }
            }).fail(function() {
                $select.removeClass('saving');
                showToast('Silme işlemi başarısız!', 'error');
                $select.val($select.data('previous-value') || '');
            });
        }
        
        // Select stilini güncelle
        function updateSelectStyle($select, value) {
            // Önce tüm sınıfları kaldır
            $select.removeClass('status-izin status-empty');
            $select.attr('style', '');
            
            if (value === '' || !value) {
                // Boş
                $select.addClass('status-empty');
            } else {
                // İzin durumu
                $select.addClass('status-izin');
                
                // Rengi bul
                const izin = izinDurumlari.find(i => i.izin_durum_id == value);
                if (izin && izin.izin_durum_renk) {
                    $select.css({
                        'background-color': izin.izin_durum_renk,
                        'border-color': izin.izin_durum_renk,
                        'color': 'white'
                    });
                }
            }
        }
        
        // Hafta navigasyonu
        $('#btnPrevWeek').on('click', function() {
            currentWeekStart.setDate(currentWeekStart.getDate() - 7);
            loadWeeklyTable();
        });
        
        $('#btnThisWeek').on('click', function() {
            currentWeekStart = getMonday(new Date());
            loadWeeklyTable();
        });
        
        $('#btnNextWeek').on('click', function() {
            currentWeekStart.setDate(currentWeekStart.getDate() + 7);
            loadWeeklyTable();
        });
        
        // Firma seçildiğinde şubeleri filtrele
        $('#filter_firma_id').on('change', function() {
            const firmaId = $(this).val();
            const $subeSelect = $('#filter_sube_id');
            
            if (firmaId) {
                // Şubeleri firmaya göre filtrele
                $subeSelect.find('option').each(function() {
                    const optionFirmaId = $(this).data('firma-id');
                    if ($(this).val() === '' || optionFirmaId == firmaId) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            } else {
                // Tüm şubeleri göster
                $subeSelect.find('option').show();
            }
            
            // Şube seçimini sıfırla
            $subeSelect.val('').trigger('change.select2');
        });
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            currentFilters = {
                personel_id: $('#filter_personel_id').val(),
                firma_id: $('#filter_firma_id').val(),
                sube_id: $('#filter_sube_id').val(),
                departman_id: $('#filter_departman_id').val(),
                izin_durum_id: $('#filter_izin_durum_id').val()
            };
            
            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) delete currentFilters[key];
            });
            
            loadWeeklyTable();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_personel_id').val('').trigger('change.select2');
            $('#filter_firma_id').val('').trigger('change.select2');
            $('#filter_sube_id').val('').trigger('change.select2');
            $('#filter_departman_id').val('').trigger('change.select2');
            $('#filter_izin_durum_id').val('').trigger('change.select2');
            
            // Tüm şubeleri göster
            $('#filter_sube_id option').show();
            
            currentFilters = {};
            loadWeeklyTable();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Sayfa yüklendiğinde
        // Görüntülenen hafta ve ekrandaki filtrelerle Excel aktarımı
        $('#excelIndir').on('click', function() {
            const weekDays = getWeekDays(currentWeekStart);

            const form = $('<form>', { method: 'POST', action: window.location.href });
            form.append($('<input>', { type: 'hidden', name: 'action', value: 'excel_indir' }));
            form.append($('<input>', { type: 'hidden', name: 'start_date', value: weekDays[0].toISOString().split('T')[0] }));
            form.append($('<input>', { type: 'hidden', name: 'end_date', value: weekDays[6].toISOString().split('T')[0] }));

            Object.keys(currentFilters).forEach(key => {
                if (currentFilters[key]) {
                    form.append($('<input>', { type: 'hidden', name: key, value: currentFilters[key] }));
                }
            });

            $('body').append(form);
            form.submit();
            form.remove();

            showToast('Excel dosyası indiriliyor...', 'info');
        });

        $(document).ready(function() {
            loadStats();
            loadWeeklyTable();
        });
    </script>
</body>
</html>
