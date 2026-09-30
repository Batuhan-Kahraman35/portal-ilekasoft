<?php
/**
 * Admin Panel - Personel İzin Yönetimi
 * Personel izinlerinin takibi ve yönetimi
 * Personel_Puantaj tablosunu kullanır (izin kayıtları için)
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Personel İzin Yönetimi';
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

// Personel listesini çek
$personeller = $db->fetchAll("
    SELECT 
        k.kullanici_id,
        k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
        k.kullanici_calisma_departman_id,
        k.kullanici_firma_id,
        k.kullanici_durum,
        d.departman_adi,
        f.firma_adi
    FROM kullanicilar k
    LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
    LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
    ORDER BY k.kullanici_durum DESC, k.kullanici_ad, k.kullanici_soyad
");

// Departmanları çek
$departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");

// Firmaları çek
$firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");

// Excel Export (GET isteği)
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $startDate   = $_GET['start_date']    ?? '';
    $endDate     = $_GET['end_date']      ?? '';
    $personelId  = $_GET['personel_id']   ?? '';
    $izinDurumId = $_GET['izin_durum_id'] ?? '';
    $departmanId = $_GET['departman_id']  ?? '';
    $firmaId     = $_GET['firma_id']      ?? '';
    $searchValue = $_GET['search_value']  ?? '';

    $whereConditions = [
        "p.puantaj_aktif = 1",
        "p.puantaj_izin_durum_id IS NOT NULL",
        "(k.kullanici_ise_cikis_tarihi IS NULL OR CONVERT(DATE, p.puantaj_tarih) <= CONVERT(DATE, k.kullanici_ise_cikis_tarihi))"
    ];
    $params = [];

    if ($startDate)   { $whereConditions[] = "CONVERT(DATE, p.puantaj_tarih) >= ?"; $params[] = $startDate;   }
    if ($endDate)     { $whereConditions[] = "CONVERT(DATE, p.puantaj_tarih) <= ?"; $params[] = $endDate;     }
    if ($personelId)  { $whereConditions[] = "p.puantaj_kullanici_id = ?";          $params[] = $personelId;  }
    if ($izinDurumId) { $whereConditions[] = "p.puantaj_izin_durum_id = ?";         $params[] = $izinDurumId; }
    if ($firmaId)     { $whereConditions[] = "k.kullanici_firma_id = ?";             $params[] = $firmaId;     }
    if ($departmanId) { $whereConditions[] = "k.kullanici_calisma_departman_id = ?";         $params[] = $departmanId; }
    if ($searchValue) {
        $whereConditions[] = "((k.kullanici_ad + ' ' + k.kullanici_soyad) LIKE ? OR izd.izin_durum_adi LIKE ? OR dep.departman_adi LIKE ? OR f.firma_adi LIKE ?)";
        $params[] = "%$searchValue%"; $params[] = "%$searchValue%";
        $params[] = "%$searchValue%"; $params[] = "%$searchValue%";
    }

    $whereClause = implode(" AND ", $whereConditions);

    $rows = $db->fetchAll("
        SELECT
            p.puantaj_id,
            p.puantaj_kullanici_id,
            k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi,
            dep.departman_adi,
            f.firma_adi,
            izd.izin_durum_adi,
            CONVERT(VARCHAR(10), p.puantaj_tarih, 120) AS puantaj_tarih,
            ko.kullanici_ad + ' ' + ko.kullanici_soyad AS olusturan_adi,
            CONVERT(VARCHAR(19), p.puantaj_olusturma_tarihi, 120) AS puantaj_olusturma_tarihi
        FROM Personel_Puantaj p
        LEFT JOIN kullanicilar k   ON p.puantaj_kullanici_id = k.kullanici_id
        LEFT JOIN Firmalar f       ON k.kullanici_firma_id = f.firma_id
        LEFT JOIN Departmanlar dep ON k.kullanici_calisma_departman_id = dep.departman_id
        LEFT JOIN tanim_izin_durumlari izd ON p.puantaj_izin_durum_id = izd.izin_durum_id
        LEFT JOIN kullanicilar ko  ON p.puantaj_olusturan_kullanici_id = ko.kullanici_id
        WHERE $whereClause AND (dep.departman_personel = 1 OR dep.departman_personel IS NULL)
        ORDER BY p.puantaj_tarih DESC
    ", $params);

    // === Eksik günleri GELDI olarak ekle (sadece Excel'de, DB'ye yazılmaz) ===
    // Koşul: Tarih aralığı verilmiş ve belirli bir izin türü filtresi YOKSA
    $geldiRows = [];
    if ($startDate && $endDate && !$izinDurumId) {
        // GELDI durumunu bul (koda veya ada göre)
        $geldiDurum = $db->fetchOne("
            SELECT TOP 1 izin_durum_id, izin_durum_adi
            FROM tanim_izin_durumlari
            WHERE izin_durum_durum = 1
            AND (UPPER(izin_durum_kod) = 'GELDI' OR UPPER(izin_durum_adi) = 'GELDI')
            ORDER BY izin_durum_id
        ");

        if ($geldiDurum) {
            // Filtreye uyan personelleri çek
            $pWhere = ["(dep.departman_personel = 1 OR dep.departman_personel IS NULL)"];
            $pParams = [];
            if ($personelId)  { $pWhere[] = "k.kullanici_id = ?";           $pParams[] = $personelId; }
            if ($firmaId)     { $pWhere[] = "k.kullanici_firma_id = ?";     $pParams[] = $firmaId;    }
            if ($departmanId) { $pWhere[] = "k.kullanici_calisma_departman_id = ?"; $pParams[] = $departmanId;}
            $pWhereClause = implode(" AND ", $pWhere);

            $personelListesi = $db->fetchAll("
                SELECT
                    k.kullanici_id,
                    k.kullanici_ad + ' ' + k.kullanici_soyad AS personel_adi,
                    dep.departman_adi,
                    f.firma_adi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) AS cikis_tarihi
                FROM kullanicilar k
                LEFT JOIN Firmalar f       ON k.kullanici_firma_id = f.firma_id
                LEFT JOIN Departmanlar dep ON k.kullanici_calisma_departman_id = dep.departman_id
                WHERE $pWhereClause
            ", $pParams);

            // Mevcut kayıtları indexle: kullanici_id|tarih
            $mevcutMap = [];
            foreach ($rows as $r) {
                $mevcutMap[$r['puantaj_kullanici_id'] . '|' . $r['puantaj_tarih']] = true;
            }

            // Tarih aralığı (bitiş dahil, hafta sonu dahil tüm günler)
            $start = new DateTime($startDate);
            $end   = new DateTime($endDate);
            $end->modify('+1 day');
            $dateRange = new DatePeriod($start, new DateInterval('P1D'), $end);

            foreach ($personelListesi as $per) {
                foreach ($dateRange as $date) {
                    $tarih = $date->format('Y-m-d');
                    // Çıkış tarihinden sonrasına GELDI eklenmez (çıkış günü dahil)
                    if ($per['cikis_tarihi'] && $tarih > $per['cikis_tarihi']) continue;
                    // O gün zaten kayıt varsa atla
                    if (isset($mevcutMap[$per['kullanici_id'] . '|' . $tarih])) continue;

                    $geldiRows[] = [
                        'puantaj_id'               => '',
                        'personel_adi'             => $per['personel_adi'],
                        'departman_adi'            => $per['departman_adi'],
                        'firma_adi'                => $per['firma_adi'],
                        'izin_durum_adi'           => $geldiDurum['izin_durum_adi'],
                        'puantaj_tarih'            => $tarih,
                        'olusturan_adi'            => 'Sistem (Otomatik)',
                        'puantaj_olusturma_tarihi' => '',
                    ];
                }
            }
        }
    }

    // Mevcut izinler + GELDI kayıtlarını birleştir, personel ve tarihe göre sırala
    $rows = array_merge($rows, $geldiRows);
    usort($rows, function ($a, $b) {
        return [$a['personel_adi'], $a['puantaj_tarih']] <=> [$b['personel_adi'], $b['puantaj_tarih']];
    });

    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="izin-kayitlari-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($out, ['ID', 'Personel', 'Çalıştığı Departman', 'Firma', 'İzin Türü', 'Tarih', 'Oluşturan', 'Kayıt Tarihi'], ';');

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['puantaj_id']               ?? '',
            $row['personel_adi']             ?? '',
            $row['departman_adi']            ?? '',
            $row['firma_adi']                ?? '',
            $row['izin_durum_adi']           ?? '',
            $row['puantaj_tarih']            ?? '',
            $row['olusturan_adi']            ?? '',
            $row['puantaj_olusturma_tarihi'] ?? '',
        ], ';');
    }

    fclose($out);
    exit;
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir - Sadece izin kayıtları için
                $baseSql = "SELECT COUNT(*) as sayi FROM Personel_Puantaj WHERE puantaj_aktif = 1 AND puantaj_izin_durum_id IS NOT NULL";
                
                // Bu ay filtresi
                $buAySql = $baseSql . " AND YEAR(puantaj_tarih) = YEAR(GETDATE()) AND MONTH(puantaj_tarih) = MONTH(GETDATE())";
                
                // Bu hafta filtresi
                $buHaftaSql = $baseSql . " AND puantaj_tarih >= DATEADD(day, 1 - DATEPART(weekday, GETDATE()), CAST(GETDATE() AS DATE)) 
                                          AND puantaj_tarih < DATEADD(day, 8 - DATEPART(weekday, GETDATE()), CAST(GETDATE() AS DATE))";
                
                // İzin durumlarına göre sayılar (Bu ay)
                $izinIstatistikleri = $db->fetchAll("
                    SELECT 
                        id.izin_durum_id,
                        id.izin_durum_adi,
                        id.izin_durum_renk,
                        id.izin_durum_ucretli,
                        ISNULL(id.izin_durum_sira_no, 999) as sira_no,
                        COUNT(pp.puantaj_id) as sayi
                    FROM tanim_izin_durumlari id
                    LEFT JOIN Personel_Puantaj pp ON id.izin_durum_id = pp.puantaj_izin_durum_id 
                        AND pp.puantaj_aktif = 1
                        AND YEAR(pp.puantaj_tarih) = YEAR(GETDATE()) 
                        AND MONTH(pp.puantaj_tarih) = MONTH(GETDATE())
                    WHERE id.izin_durum_durum = 1
                    GROUP BY id.izin_durum_id, id.izin_durum_adi, id.izin_durum_renk, id.izin_durum_ucretli, id.izin_durum_sira_no
                    ORDER BY ISNULL(id.izin_durum_sira_no, 999), id.izin_durum_adi
                ");
                
                // Ücretli ve ücretsiz izin sayıları
                $ucretliSayi = $db->fetchOne("
                    SELECT COUNT(*) as sayi 
                    FROM Personel_Puantaj pp
                    INNER JOIN tanim_izin_durumlari id ON pp.puantaj_izin_durum_id = id.izin_durum_id
                    WHERE pp.puantaj_aktif = 1 
                    AND pp.puantaj_izin_durum_id IS NOT NULL
                    AND id.izin_durum_ucretli = 1
                    AND YEAR(pp.puantaj_tarih) = YEAR(GETDATE()) 
                    AND MONTH(pp.puantaj_tarih) = MONTH(GETDATE())
                ")['sayi'] ?? 0;
                
                $ucretsizSayi = $db->fetchOne("
                    SELECT COUNT(*) as sayi 
                    FROM Personel_Puantaj pp
                    INNER JOIN tanim_izin_durumlari id ON pp.puantaj_izin_durum_id = id.izin_durum_id
                    WHERE pp.puantaj_aktif = 1 
                    AND pp.puantaj_izin_durum_id IS NOT NULL
                    AND id.izin_durum_ucretli = 0
                    AND YEAR(pp.puantaj_tarih) = YEAR(GETDATE()) 
                    AND MONTH(pp.puantaj_tarih) = MONTH(GETDATE())
                ")['sayi'] ?? 0;
                
                $stats = [
                    'toplam' => $db->fetchOne($baseSql)['sayi'] ?? 0,
                    'bu_ay' => $db->fetchOne($buAySql)['sayi'] ?? 0,
                    'bu_hafta' => $db->fetchOne($buHaftaSql)['sayi'] ?? 0,
                    'ucretli' => $ucretliSayi,
                    'ucretsiz' => $ucretsizSayi,
                    'izin_istatistikleri' => $izinIstatistikleri
                ];
                
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // DataTables server-side processing
                $draw = intval($_POST['draw'] ?? 1);
                $start = intval($_POST['start'] ?? 0);
                $length = intval($_POST['length'] ?? 10);
                $searchValue = $_POST['search']['value'] ?? '';
                
                // Özel filtreler
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $izinDurumId = $_POST['izin_durum_id'] ?? '';
                $departmanId = $_POST['departman_id'] ?? '';
                $firmaId = $_POST['firma_id'] ?? '';
                
                // Temel WHERE koşulu - Sadece izin kayıtları
                // Çıkış tarihinden sonraki günleri gizle (çıkış günü dahil gösterilir)
                $whereConditions = [
                    "p.puantaj_aktif = 1", 
                    "p.puantaj_izin_durum_id IS NOT NULL",
                    "(k.kullanici_ise_cikis_tarihi IS NULL OR CONVERT(DATE, p.puantaj_tarih) <= CONVERT(DATE, k.kullanici_ise_cikis_tarihi))"
                ];
                $params = [];
                
                // Tarih filtresi
                if ($startDate) {
                    $whereConditions[] = "CONVERT(DATE, p.puantaj_tarih) >= ?";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $whereConditions[] = "CONVERT(DATE, p.puantaj_tarih) <= ?";
                    $params[] = $endDate;
                }
                
                // Personel filtresi
                if ($personelId) {
                    $whereConditions[] = "p.puantaj_kullanici_id = ?";
                    $params[] = $personelId;
                }
                
                // İzin durumu filtresi
                if ($izinDurumId) {
                    $whereConditions[] = "p.puantaj_izin_durum_id = ?";
                    $params[] = $izinDurumId;
                }
                
                // Firma filtresi
                if ($firmaId) {
                    $whereConditions[] = "k.kullanici_firma_id = ?";
                    $params[] = $firmaId;
                }
                
                // Departman filtresi
                if ($departmanId) {
                    $whereConditions[] = "k.kullanici_calisma_departman_id = ?";
                    $params[] = $departmanId;
                }
                
                // Arama filtresi
                if ($searchValue) {
                    $whereConditions[] = "((k.kullanici_ad + ' ' + k.kullanici_soyad) LIKE ? OR izd.izin_durum_adi LIKE ? OR dep.departman_adi LIKE ? OR f.firma_adi LIKE ?)";
                    $params[] = "%$searchValue%";
                    $params[] = "%$searchValue%";
                    $params[] = "%$searchValue%";
                    $params[] = "%$searchValue%";
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Toplam kayıt sayısı (filtresiz - sadece izin kayıtları, çıkış sonrası hariç)
                $totalRecords = $db->fetchOne("
                    SELECT COUNT(*) as total 
                    FROM Personel_Puantaj p
                    LEFT JOIN kullanicilar k ON p.puantaj_kullanici_id = k.kullanici_id
                    LEFT JOIN Departmanlar dep ON k.kullanici_calisma_departman_id = dep.departman_id
                    WHERE p.puantaj_aktif = 1 
                    AND p.puantaj_izin_durum_id IS NOT NULL
                    AND (dep.departman_personel = 1 OR dep.departman_personel IS NULL)
                    AND (k.kullanici_ise_cikis_tarihi IS NULL OR CONVERT(DATE, p.puantaj_tarih) <= CONVERT(DATE, k.kullanici_ise_cikis_tarihi))
                ")['total'] ?? 0;
                
                // Filtrelenmiş kayıt sayısı
                $countSql = "
                    SELECT COUNT(*) as total 
                    FROM Personel_Puantaj p
                    LEFT JOIN kullanicilar k ON p.puantaj_kullanici_id = k.kullanici_id
                    LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                    LEFT JOIN Departmanlar dep ON k.kullanici_calisma_departman_id = dep.departman_id
                    LEFT JOIN tanim_izin_durumlari izd ON p.puantaj_izin_durum_id = izd.izin_durum_id
                    WHERE $whereClause AND (dep.departman_personel = 1 OR dep.departman_personel IS NULL)
                ";
                $filteredRecords = $db->fetchOne($countSql, $params)['total'] ?? 0;
                
                // Sıralama
                $orderColumn = intval($_POST['order'][0]['column'] ?? 0);
                $orderDir = $_POST['order'][0]['dir'] ?? 'desc';
                $orderDir = strtolower($orderDir) === 'asc' ? 'ASC' : 'DESC';
                
                $columns = ['p.puantaj_id', 'personel_adi', 'dep.departman_adi', 'f.firma_adi', 'izd.izin_durum_adi', 'p.puantaj_tarih', 'olusturan_adi', 'p.puantaj_olusturma_tarihi'];
                $orderBy = isset($columns[$orderColumn]) ? $columns[$orderColumn] : 'p.puantaj_tarih';
                
                // Veri çek (doğrudan tablodan, performanslı)
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
                        kg.kullanici_ad + ' ' + kg.kullanici_soyad as guncelleyen_adi
                    FROM Personel_Puantaj p
                    LEFT JOIN kullanicilar k ON p.puantaj_kullanici_id = k.kullanici_id
                    LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                    LEFT JOIN Departmanlar dep ON k.kullanici_calisma_departman_id = dep.departman_id
                    LEFT JOIN tanim_izin_durumlari izd ON p.puantaj_izin_durum_id = izd.izin_durum_id
                    LEFT JOIN kullanicilar ko ON p.puantaj_olusturan_kullanici_id = ko.kullanici_id
                    LEFT JOIN kullanicilar kg ON p.puantaj_guncelleyen_kullanici_id = kg.kullanici_id
                    WHERE $whereClause AND (dep.departman_personel = 1 OR dep.departman_personel IS NULL)
                    ORDER BY $orderBy $orderDir 
                    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                ";
                $params[] = $start;
                $params[] = $length;
                
                $data = $db->fetchAll($sql, $params);
                
                echo json_encode([
                    'draw' => $draw,
                    'recordsTotal' => $totalRecords,
                    'recordsFiltered' => $filteredRecords,
                    'data' => $data
                ]);
                break;
                
            case 'get':
                // Tek kayıt getir
                $id = $_POST['id'] ?? 0;
                $data = $db->fetchOne("SELECT * FROM Personel_Puantaj WHERE puantaj_id = ?", [$id]);
                
                if ($data && isset($data['puantaj_tarih'])) {
                    if ($data['puantaj_tarih'] instanceof DateTime) {
                        $data['puantaj_tarih'] = $data['puantaj_tarih']->format('Y-m-d');
                    }
                }
                
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
                
                if (!$izinDurumId) {
                    echo json_encode(['success' => false, 'message' => 'İzin türü seçmelisiniz!']);
                    break;
                }
                
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
                    echo json_encode(['success' => false, 'message' => 'Bu personel için seçilen tarihte zaten kayıt mevcut!']);
                    break;
                }
                
                // Çıkış tarihi kontrolü: Çıkış tarihinden sonrasına izin kaydı eklenemez
                $personelBilgi = $db->fetchOne("
                    SELECT CONVERT(VARCHAR(10), kullanici_ise_cikis_tarihi, 120) as cikis_tarihi
                    FROM kullanicilar WHERE kullanici_id = ?
                ", [$kullaniciId]);
                
                if ($personelBilgi && $personelBilgi['cikis_tarihi'] && $tarih > $personelBilgi['cikis_tarihi']) {
                    echo json_encode(['success' => false, 'message' => 'Bu personelin çıkış tarihi (' . date('d.m.Y', strtotime($personelBilgi['cikis_tarihi'])) . ') sonrasına izin kaydı eklenemez!']);
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
                    echo json_encode(['success' => true, 'message' => 'İzin kaydı güncellendi!']);
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
                    echo json_encode(['success' => true, 'message' => 'İzin kaydı eklendi!']);
                }
                break;
                
            case 'save_bulk':
                // Toplu izin kaydı (tarih aralığı ile)
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                $kullaniciId = $_POST['kullanici_id'] ?? 0;
                $izinDurumId = $_POST['izin_durum_id'] ?? 0;
                $baslangicTarihi = $_POST['baslangic_tarihi'] ?? '';
                $bitisTarihi = $_POST['bitis_tarihi'] ?? '';
                
                if (!$kullaniciId || !$izinDurumId || !$baslangicTarihi || !$bitisTarihi) {
                    echo json_encode(['success' => false, 'message' => 'Tüm alanları doldurun!']);
                    break;
                }
                
                // Tarih aralığındaki günleri hesapla
                $start = new DateTime($baslangicTarihi);
                $end = new DateTime($bitisTarihi);
                $end->modify('+1 day'); // Bitiş gününü dahil et
                
                $interval = new DateInterval('P1D');
                $dateRange = new DatePeriod($start, $interval, $end);
                
                // Çıkış tarihi kontrolü
                $personelBilgi = $db->fetchOne("
                    SELECT CONVERT(VARCHAR(10), kullanici_ise_cikis_tarihi, 120) as cikis_tarihi
                    FROM kullanicilar WHERE kullanici_id = ?
                ", [$kullaniciId]);
                $cikisTarihi = ($personelBilgi && $personelBilgi['cikis_tarihi']) ? $personelBilgi['cikis_tarihi'] : null;
                
                $eklenenSayisi = 0;
                $mevcutSayisi = 0;
                $cikisSonrasiAtlanan = 0;
                
                foreach ($dateRange as $date) {
                    $tarih = $date->format('Y-m-d');
                    
                    // Çıkış tarihinden sonrasını atla (çıkış günü dahil)
                    if ($cikisTarihi && $tarih > $cikisTarihi) {
                        $cikisSonrasiAtlanan++;
                        continue;
                    }
                    
                    // Aynı tarihte kayıt var mı?
                    $mevcutKayit = $db->fetchOne("
                        SELECT puantaj_id 
                        FROM Personel_Puantaj 
                        WHERE puantaj_kullanici_id = ? 
                        AND puantaj_tarih = ? 
                        AND puantaj_aktif = 1
                    ", [$kullaniciId, $tarih]);
                    
                    if ($mevcutKayit) {
                        $mevcutSayisi++;
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
                    
                    $db->insert('Personel_Puantaj', $data);
                    $eklenenSayisi++;
                }
                
                $mesaj = "$eklenenSayisi gün izin kaydı eklendi.";
                if ($mevcutSayisi > 0) {
                    $mesaj .= " $mevcutSayisi gün zaten kayıtlı olduğu için atlandı.";
                }
                if ($cikisSonrasiAtlanan > 0) {
                    $mesaj .= " $cikisSonrasiAtlanan gün çıkış tarihinden sonrası olduğu için atlandı.";
                }
                
                echo json_encode(['success' => true, 'message' => $mesaj, 'eklenen' => $eklenenSayisi, 'mevcut' => $mevcutSayisi]);
                break;
                
            case 'delete':
                // Sil (Soft Delete)
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = $_POST['id'] ?? 0;
                $db->update('Personel_Puantaj', ['puantaj_aktif' => 0], ['puantaj_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'İzin kaydı silindi!']);
                break;

                
            case 'get_personel_izin_tarihleri':
                // Personelin belirli izin türündeki tüm tarihlerini getir
                $personelId = $_POST['personel_id'] ?? 0;
                $izinDurumId = $_POST['izin_durum_id'] ?? 0;
                
                if (!$personelId || !$izinDurumId) {
                    echo json_encode(['success' => false, 'message' => 'Eksik parametre!']);
                    break;
                }
                
                // Personel bilgisi
                $personelBilgi = $db->fetchOne("
                    SELECT 
                        k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                        d.departman_adi,
                        f.firma_adi
                    FROM kullanicilar k
                    LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
                    LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                    WHERE k.kullanici_id = ?
                ", [$personelId]);
                
                // İzin türü bilgisi
                $izinTuruBilgi = $db->fetchOne("
                    SELECT izin_durum_adi, izin_durum_renk, izin_durum_ucretli
                    FROM tanim_izin_durumlari 
                    WHERE izin_durum_id = ?
                ", [$izinDurumId]);
                
                // Tarih listesi
                $tarihler = $db->fetchAll("
                    SELECT 
                        pp.puantaj_id,
                        CONVERT(VARCHAR(10), pp.puantaj_tarih, 120) as puantaj_tarih,
                        k2.kullanici_ad + ' ' + k2.kullanici_soyad as olusturan_adi,
                        CONVERT(VARCHAR(19), pp.puantaj_olusturma_tarihi, 120) as puantaj_olusturma_tarihi
                    FROM Personel_Puantaj pp
                    LEFT JOIN kullanicilar k2 ON pp.puantaj_olusturan_kullanici_id = k2.kullanici_id
                    WHERE pp.puantaj_kullanici_id = ?
                    AND pp.puantaj_izin_durum_id = ?
                    AND pp.puantaj_aktif = 1
                    ORDER BY pp.puantaj_tarih DESC
                ", [$personelId, $izinDurumId]);
                
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'personel' => $personelBilgi,
                        'izin_turu' => $izinTuruBilgi,
                        'tarihler' => $tarihler,
                        'toplam' => count($tarihler)
                    ]
                ]);
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
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        /* İzin durumu badge'leri için dinamik renkler */
        .izin-badge {
            border-radius: 4px;
            padding: 4px 10px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }
        
        /* Info box hover */
        .info-box {
            transition: transform 0.2s;
        }
        
        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        /* DataTables özelleştirmeleri */
        .dataTables_wrapper .dataTables_length select {
            padding: 0.375rem 2rem 0.375rem 0.75rem;
        }
        
        /* Tablo responsive */
        .table-responsive {
            overflow-x: auto;
        }
        
        /* Modal form düzenlemeleri */
        .modal-body .form-label {
            font-weight: 500;
            margin-bottom: 0.3rem;
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
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-calendar-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam İzin</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-calendar-month"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay</span>
                                    <span class="info-box-number" id="stat-bu-ay">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-cash-coin"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ücretli İzin (Ay)</span>
                                    <span class="info-box-number" id="stat-ucretli">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-calendar-x"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ücretsiz İzin (Ay)</span>
                                    <span class="info-box-number" id="stat-ucretsiz">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
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
                                        <!-- Tarih Aralığı -->
                                        <div class="col-md-2">
                                            <label class="form-label">Başlangıç Tarihi</label>
                                            <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bitiş Tarihi</label>
                                            <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                        </div>
                                        
                                        <!-- Personel -->
                                        <div class="col-md-3">
                                            <label class="form-label">Personel</label>
                                            <select class="form-select" id="filter_personel_id" name="personel_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($personeller as $p): ?>
                                                    <option value="<?= $p['kullanici_id'] ?>">
                                                        <?= htmlspecialchars($p['personel_adi']) ?>
                                                        <?= $p['kullanici_durum'] == 0 ? ' - [PASİF]' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- İzin Türü -->
                                        <div class="col-md-2">
                                            <label class="form-label">İzin Türü</label>
                                            <select class="form-select" id="filter_izin_durum_id" name="izin_durum_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($izinDurumlari as $izin): ?>
                                                    <option value="<?= $izin['izin_durum_id'] ?>"><?= htmlspecialchars($izin['izin_durum_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Departman -->
                                        <div class="col-md-2">
                                            <label class="form-label">Çalıştığı Departman</label>
                                            <select class="form-select" id="filter_departman_id" name="departman_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($departmanlar as $d): ?>
                                                    <option value="<?= $d['departman_id'] ?>"><?= htmlspecialchars($d['departman_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Firma -->
                                        <div class="col-md-2">
                                            <label class="form-label">Firma</label>
                                            <select class="form-select" id="filter_firma_id" name="firma_id">
                                                <option value="">Tümü</option>
                                                <?php foreach ($firmalar as $f): ?>
                                                    <option value="<?= $f['firma_id'] ?>"><?= htmlspecialchars($f['firma_adi']) ?></option>
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
                    
                    <!-- Liste Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-calendar-check"></i> İzin Kayıtları</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm me-1" id="btnExcelIndir" title="Filtrelenmiş kayıtları Excel olarak indir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-teal btn-sm me-1" id="btnTopluIzin">
                                    <i class="bi bi-calendar-range"></i> Toplu İzin Ekle
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" id="btnYeniEkle">
                                    <i class="bi bi-plus-circle"></i> Yeni İzin Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="dataTable" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Personel</th>
                                            <th>Çalıştığı Departman</th>
                                            <th>Firma</th>
                                            <th>İzin Türü</th>
                                            <th>Tarih</th>
                                            <th>Oluşturan</th>
                                            <th>Kayıt Tarihi</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
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
    
    <!-- Modal: Tek Günlük İzin Ekle/Düzenle -->
    <div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni İzin Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="saveForm">
                    <div class="modal-body">
                        <input type="hidden" id="puantaj_id" name="id">
                        
                        <div class="row g-3">
                            <!-- Personel -->
                            <div class="col-md-12">
                                <label class="form-label">Personel <span class="text-danger">*</span></label>
                                <select class="form-select" id="kullanici_id" name="kullanici_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($personeller as $p): ?>
                                        <option value="<?= $p['kullanici_id'] ?>">
                                            <?= htmlspecialchars($p['personel_adi']) ?> (<?= htmlspecialchars($p['departman_adi'] ?? '-') ?>)
                                            <?= $p['kullanici_durum'] == 0 ? ' - [PASİF]' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- İzin Türü -->
                            <div class="col-md-12">
                                <label class="form-label">İzin Türü <span class="text-danger">*</span></label>
                                <select class="form-select" id="izin_durum_id" name="izin_durum_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($izinDurumlari as $izin): ?>
                                        <option value="<?= $izin['izin_durum_id'] ?>" data-renk="<?= $izin['izin_durum_renk'] ?>">
                                            <?= htmlspecialchars($izin['izin_durum_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Tarih -->
                            <div class="col-md-12">
                                <label class="form-label">Tarih <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="tarih" name="tarih" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal: Toplu İzin Ekle -->
    <div class="modal fade" id="modalTopluIzin" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-range"></i> Toplu İzin Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="topluIzinForm">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Seçilen tarih aralığındaki her gün için izin kaydı oluşturulur.
                        </div>
                        
                        <div class="row g-3">
                            <!-- Personel -->
                            <div class="col-md-12">
                                <label class="form-label">Personel <span class="text-danger">*</span></label>
                                <select class="form-select" id="toplu_kullanici_id" name="kullanici_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($personeller as $p): ?>
                                        <option value="<?= $p['kullanici_id'] ?>">
                                            <?= htmlspecialchars($p['personel_adi']) ?> (<?= htmlspecialchars($p['departman_adi'] ?? '-') ?>)
                                            <?= $p['kullanici_durum'] == 0 ? ' - [PASİF]' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- İzin Türü -->
                            <div class="col-md-12">
                                <label class="form-label">İzin Türü <span class="text-danger">*</span></label>
                                <select class="form-select" id="toplu_izin_durum_id" name="izin_durum_id" required>
                                    <option value="">Seçiniz...</option>
                                    <?php foreach ($izinDurumlari as $izin): ?>
                                        <option value="<?= $izin['izin_durum_id'] ?>">
                                            <?= htmlspecialchars($izin['izin_durum_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Başlangıç Tarihi -->
                            <div class="col-md-6">
                                <label class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="toplu_baslangic_tarihi" name="baslangic_tarihi" required>
                            </div>
                            
                            <!-- Bitiş Tarihi -->
                            <div class="col-md-6">
                                <label class="form-label">Bitiş Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="toplu_bitis_tarihi" name="bitis_tarihi" required>
                            </div>
                            
                            <!-- Gün Sayısı Göstergesi -->
                            <div class="col-md-12">
                                <div class="alert alert-secondary mb-0" id="gunSayisiAlert">
                                    <strong>Toplam:</strong> <span id="gunSayisi">0</span> gün izin kaydı oluşturulacak
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-calendar-plus"></i> Toplu Kaydet
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal: Personel İzin Tarihleri -->
    <div class="modal fade" id="modalIzinTarihleri" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-calendar-check"></i> <span id="izinTarihleriBaslik">Personel İzin Tarihleri</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Personel Bilgisi -->
                    <div class="alert alert-info mb-3" id="izinTarihleriPersonelInfo">
                        <div class="row">
                            <div class="col-md-4">
                                <strong><i class="bi bi-person"></i> Personel:</strong>
                                <span id="izinTarihleriPersonelAdi">-</span>
                            </div>
                            <div class="col-md-4">
                                <strong><i class="bi bi-building"></i> Departman:</strong>
                                <span id="izinTarihleriDepartman">-</span>
                            </div>
                            <div class="col-md-4">
                                <strong><i class="bi bi-briefcase"></i> Firma:</strong>
                                <span id="izinTarihleriFirma">-</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- İzin Türü ve Toplam -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="izin-badge" id="izinTarihleriTur" style="font-size: 1rem;">-</span>
                        <span class="badge bg-secondary fs-6">Toplam: <strong id="izinTarihleriToplam">0</strong> gün</span>
                    </div>
                    
                    <!-- Tarih Tablosu -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-sm" id="izinTarihleriTable">
                            <thead class="table-dark">
                                <tr>
                                    <th width="50">#</th>
                                    <th>Tarih</th>
                                    <th>Gün</th>
                                    <th>Oluşturan</th>
                                    <th>Kayıt Tarihi</th>
                                </tr>
                            </thead>
                            <tbody id="izinTarihleriBody">
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Kapat
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        
        // İzin durumları (renk için)
        const izinDurumlari = <?= json_encode($izinDurumlari) ?>;
        
        // Modallar
        const modal = new bootstrap.Modal('#modalForm');
        const modalToplu = new bootstrap.Modal('#modalTopluIzin');
        const modalIzinTarihleri = new bootstrap.Modal('#modalIzinTarihleri');
        
        // Gün isimlerini döndür
        function getGunAdi(dateString) {
            const gunler = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
            try {
                const date = new Date(dateString);
                return gunler[date.getDay()];
            } catch (e) {
                return '-';
            }
        }
        
        // Personel izin tarihlerini göster
        function showPersonelIzinTarihleri(personelId, izinDurumId) {
            // Yükleniyoru göster
            $('#izinTarihleriBody').html('<tr><td colspan="5" class="text-center"><i class="bi bi-hourglass-split"></i> Yükleniyor...</td></tr>');
            $('#izinTarihleriPersonelAdi').text('-');
            $('#izinTarihleriDepartman').text('-');
            $('#izinTarihleriFirma').text('-');
            $('#izinTarihleriTur').text('-').attr('style', 'background-color: #6c757d; color: #fff; font-size: 1rem;');
            $('#izinTarihleriToplam').text('0');
            
            modalIzinTarihleri.show();
            
            $.post('', { 
                action: 'get_personel_izin_tarihleri', 
                personel_id: personelId, 
                izin_durum_id: izinDurumId 
            }, response => {
                if (response.success) {
                    const data = response.data;
                    
                    // Personel bilgisi
                    $('#izinTarihleriPersonelAdi').text(data.personel?.personel_adi || '-');
                    $('#izinTarihleriDepartman').text(data.personel?.departman_adi || '-');
                    $('#izinTarihleriFirma').text(data.personel?.firma_adi || '-');
                    
                    // İzin türü
                    const izinRenk = data.izin_turu?.izin_durum_renk || '#6c757d';
                    const izinAdi = data.izin_turu?.izin_durum_adi || '-';
                    $('#izinTarihleriTur').text(izinAdi).attr('style', `background-color: ${izinRenk}; color: #fff; font-size: 1rem;`);
                    $('#izinTarihleriToplam').text(data.toplam);
                    
                    // Başlık
                    $('#izinTarihleriBaslik').text(`${data.personel?.personel_adi || 'Personel'} - ${izinAdi} Tarihleri`);
                    
                    // Tarih tablosu
                    if (data.tarihler && data.tarihler.length > 0) {
                        let html = '';
                        data.tarihler.forEach((tarih, index) => {
                            const formattedDate = formatDate(tarih.puantaj_tarih);
                            const gunAdi = getGunAdi(tarih.puantaj_tarih);
                            const kayitTarihi = formatDateTime(tarih.puantaj_olusturma_tarihi);
                            
                            html += `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td><strong>${formattedDate}</strong></td>
                                    <td>${gunAdi}</td>
                                    <td>${tarih.olusturan_adi || '-'}</td>
                                    <td><small class="text-muted">${kayitTarihi}</small></td>
                                </tr>
                            `;
                        });
                        $('#izinTarihleriBody').html(html);
                    } else {
                        $('#izinTarihleriBody').html('<tr><td colspan="5" class="text-center text-muted">Kayıt bulunamadı</td></tr>');
                    }
                } else {
                    showToast(response.message, 'error');
                    modalIzinTarihleri.hide();
                }
            }).fail(function() {
                showToast('Veriler yüklenirken hata oluştu!', 'error');
                modalIzinTarihleri.hide();
            });
        }
        
        // Filtre değişkenleri
        let currentFilters = {};
        
        // DataTable
        let table;
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-bu-ay').text(response.data.bu_ay);
                    $('#stat-ucretli').text(response.data.ucretli);
                    $('#stat-ucretsiz').text(response.data.ucretsiz);
                }
            });
        }
        
        // İzin rengini bul
        function getIzinRenk(izinDurumId) {
            const izin = izinDurumlari.find(i => i.izin_durum_id == izinDurumId);
            return izin ? izin.izin_durum_renk : '#6c757d';
        }
        
        // Tarih formatlama
        function formatDate(dateString) {
            if (!dateString) return '-';
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }
        
        // Tarih-saat formatlama
        function formatDateTime(dateString) {
            if (!dateString) return '-';
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }
        
        // DataTable başlat
        function initDataTable() {
            table = $('#dataTable').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        d.action = 'list';
                        d.start_date = currentFilters.start_date || '';
                        d.end_date = currentFilters.end_date || '';
                        d.personel_id = currentFilters.personel_id || '';
                        d.izin_durum_id = currentFilters.izin_durum_id || '';
                        d.departman_id = currentFilters.departman_id || '';
                        d.firma_id = currentFilters.firma_id || '';
                    }
                },
                columns: [
                    { data: 'puantaj_id', width: '50px' },
                    { data: 'personel_adi' },
                    { data: 'departman_adi', defaultContent: '-' },
                    { data: 'firma_adi', defaultContent: '-' },
                    { 
                        data: 'izin_durum_adi',
                        render: function(data, type, row) {
                            if (!data) return '-';
                            const renk = row.izin_durum_renk || '#6c757d';
                            return `<span class="izin-badge" style="background-color: ${renk}; color: #fff; cursor: pointer;" 
                                onclick="showPersonelIzinTarihleri(${row.puantaj_kullanici_id}, ${row.puantaj_izin_durum_id})" 
                                title="Tüm tarihleri görmek için tıklayın">${data} <i class="bi bi-box-arrow-up-right" style="font-size: 0.7rem;"></i></span>`;
                        }
                    },
                    { 
                        data: 'puantaj_tarih',
                        render: function(data) {
                            return formatDate(data);
                        }
                    },
                    { data: 'olusturan_adi', defaultContent: '-' },
                    { 
                        data: 'puantaj_olusturma_tarihi',
                        render: function(data) {
                            return formatDateTime(data);
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        width: '100px',
                        render: function(data, type, row) {
                            let buttons = '';
                            if (permissions.can_edit) {
                                buttons += `<button class="btn btn-sm btn-warning" onclick="editRecord(${row.puantaj_id})"><i class="bi bi-pencil"></i></button> `;
                            }
                            if (permissions.can_delete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.puantaj_id})"><i class="bi bi-trash"></i></button>`;
                            }
                            return buttons || '-';
                        }
                    }
                ],
                order: [[5, 'desc']], // Tarihe göre azalan sıralama
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]]
            });
        }
        
        // Yeni kayıt ekle
        $('#btnYeniEkle').on('click', function() {
            $('#modalTitle').text('Yeni İzin Ekle');
            $('#saveForm')[0].reset();
            $('#puantaj_id').val('');
            $('#tarih').val(new Date().toISOString().split('T')[0]);
            initModalSelect2();
            modal.show();
        });
        
        // Toplu izin ekle
        $('#btnTopluIzin').on('click', function() {
            $('#topluIzinForm')[0].reset();
            $('#gunSayisi').text('0');
            initTopluModalSelect2();
            modalToplu.show();
        });
        
        // Kayıt düzenle
        function editRecord(id) {
            $.post('', { action: 'get', id: id }, response => {
                if (response.success) {
                    const data = response.data;
                    $('#modalTitle').text('İzin Düzenle');
                    $('#puantaj_id').val(data.puantaj_id);
                    $('#kullanici_id').val(data.puantaj_kullanici_id);
                    $('#izin_durum_id').val(data.puantaj_izin_durum_id);
                    $('#tarih').val(data.puantaj_tarih);
                    
                    initModalSelect2();
                    modal.show();
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        // Kayıt sil
        function deleteRecord(id) {
            confirmAction(
                'Bu izin kaydını silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, response => {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            loadStats();
                            table.ajax.reload();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            );
        }
        
        // Tek günlük form kaydet
        $('#saveForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = $(this).serialize() + '&action=save';
            
            $.post('', formData, response => {
                if (response.success) {
                    showToast(response.message, 'success');
                    modal.hide();
                    loadStats();
                    table.ajax.reload();
                } else {
                    showToast(response.message, 'error');
                }
            });
        });
        
        // Toplu izin form kaydet
        $('#topluIzinForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = $(this).serialize() + '&action=save_bulk';
            
            $.post('', formData, response => {
                if (response.success) {
                    showSuccess('Başarılı!', response.message);
                    modalToplu.hide();
                    loadStats();
                    table.ajax.reload();
                } else {
                    showError('Hata!', response.message);
                }
            });
        });
        
        // Gün sayısı hesapla
        function hesaplaGunSayisi() {
            const baslangic = $('#toplu_baslangic_tarihi').val();
            const bitis = $('#toplu_bitis_tarihi').val();
            
            if (baslangic && bitis) {
                const start = new Date(baslangic);
                const end = new Date(bitis);
                const diffTime = Math.abs(end - start);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                $('#gunSayisi').text(diffDays > 0 ? diffDays : 0);
            } else {
                $('#gunSayisi').text('0');
            }
        }
        
        // Tarih değişikliklerini dinle
        $('#toplu_baslangic_tarihi, #toplu_bitis_tarihi').on('change', hesaplaGunSayisi);
        
        // Modal Select2 başlat (tek günlük)
        function initModalSelect2() {
            $('#kullanici_id, #izin_durum_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#modalForm'),
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
        }
        
        // Modal Select2 başlat (toplu)
        function initTopluModalSelect2() {
            $('#toplu_kullanici_id, #toplu_izin_durum_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#modalTopluIzin'),
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
        }
        
        // Filtre Select2 başlat
        function initFilterSelect2() {
            $('#filter_personel_id, #filter_izin_durum_id, #filter_departman_id, #filter_firma_id').select2({
                theme: 'bootstrap-5',
                placeholder: 'Tümü',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            }).on('change', function() {
                // Dropdown değişince otomatik filtrele
                $('#filterForm').submit();
            });
        }
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            
            currentFilters = {
                start_date: $('#filter_start_date').val(),
                end_date: $('#filter_end_date').val(),
                personel_id: $('#filter_personel_id').val(),
                izin_durum_id: $('#filter_izin_durum_id').val(),
                departman_id: $('#filter_departman_id').val(),
                firma_id: $('#filter_firma_id').val()
            };
            
            table.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_personel_id').val('').trigger('change.select2');
            $('#filter_izin_durum_id').val('').trigger('change.select2');
            $('#filter_departman_id').val('').trigger('change.select2');
            $('#filter_firma_id').val('').trigger('change.select2');
            currentFilters = {};
            table.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Excel indirme
        $('#btnExcelIndir').on('click', function() {
            const $btn = $(this);

            // GELDI kayıtları için tarih aralığı gerekli; yoksa sadece izinler inecek
            if (!currentFilters.start_date || !currentFilters.end_date) {
                showToast('Tarih aralığı seçili değil: dosyaya yalnızca izin kayıtları eklenecek (GELDI eklenmez).', 'warning');
            } else if (currentFilters.izin_durum_id) {
                showToast('Belirli izin türü filtresi seçili: dosyaya yalnızca bu tür eklenecek (GELDI eklenmez).', 'warning');
            }

            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Hazırlanıyor...');

            const search = table ? (table.search() || '') : '';
            const params = new URLSearchParams({
                action:        'export',
                start_date:    currentFilters.start_date    || '',
                end_date:      currentFilters.end_date      || '',
                personel_id:   currentFilters.personel_id   || '',
                izin_durum_id: currentFilters.izin_durum_id || '',
                departman_id:  currentFilters.departman_id  || '',
                firma_id:      currentFilters.firma_id      || '',
                search_value:  search
            });

            window.location.href = '?' + params.toString();

            setTimeout(() => {
                $btn.prop('disabled', false).html('<i class="bi bi-file-earmark-excel"></i> Excel İndir');
            }, 2000);
        });

        // Sayfa yüklendiğinde
        $(document).ready(function() {
            loadStats();
            initDataTable();
            initFilterSelect2();
        });
    </script>
</body>
</html>
