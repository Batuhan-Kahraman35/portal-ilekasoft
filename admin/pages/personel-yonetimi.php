<?php
/**
 * Admin Panel - Kullanıcı Yönetimi
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Kullanıcı Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        // KULLANICI İŞLEMLERİ
        if ($action === 'kullanici_listele') {
            // Filtreleri al
            $firmaId = $_POST['firma_id'] ?? '';
            $departmanId = $_POST['departman_id'] ?? '';
            $durum = $_POST['durum'] ?? '';
            $search = $_POST['search'] ?? '';

            // WHERE koşulları
            $whereConditions = ["1=1"];
            $params = [];
            
            if ($firmaId) {
                $whereConditions[] = "k.kullanici_firma_id = ?";
                $params[] = $firmaId;
            }
            
            if ($departmanId) {
                $whereConditions[] = "k.kullanici_calisma_departman_id = ?";
                $params[] = $departmanId;
            }
            
            if ($durum !== '') {
                if ($durum === 'NULL') {
                    $whereConditions[] = "k.kullanici_durum IS NULL";
                } else {
                    $whereConditions[] = "k.kullanici_durum = ?";
                    $params[] = $durum;
                }
            }
            
            if ($search) {
                $whereConditions[] = "(k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ? OR k.kullanici_email LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            // T.C. Kimlik No filtresi
            $tcKimlikNo = $_POST['tc_kimlik_no'] ?? '';
            if ($tcKimlikNo) {
                $whereConditions[] = "k.kullanici_tc_kimlik_no LIKE ?";
                $params[] = "%$tcKimlikNo%";
            }
            
            // Taşeron filtresi kaldırıldı: Personel + Taşeron tümü listelenir
            // (Taşeron olanlar DataTable'da ikon ile işaretlenir)

            // Maaş filtreleri
            $maasMin = $_POST['maas_min'] ?? '';
            $maasMax = $_POST['maas_max'] ?? '';
            
            if ($maasMin !== '') {
                $whereConditions[] = "k.kullanici_maas >= ?";
                $params[] = intval($maasMin);
            }
            
            if ($maasMax !== '') {
                $whereConditions[] = "k.kullanici_maas <= ?";
                $params[] = intval($maasMax);
            }
            
            $whereClause = implode(" AND ", $whereConditions);
            
            $kullanicilar = $db->fetchAll("
                SELECT 
                    k.kullanici_id,
                    k.kullanici_email,
                    k.kullanici_ad,
                    k.kullanici_soyad,
                    k.kullanici_telefon,
                    k.kullanici_durum,
                    k.kullanici_firma_id,
                    k.kullanici_calisma_departman_id,
                    k.kullanici_tc_kimlik_no,
                    k.kullanici_iban,
                    k.kullanici_maas,
                    CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 120) as kullanici_dogum_tarihi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 120) as kullanici_ise_giris_tarihi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) as kullanici_ise_cikis_tarihi,
                    k.kullanici_banka_id,
                    k.kullanici_sehir_id,
                    k.kullanici_bes_durumu,
                    k.kullanici_puantaj_donem_id,
                    f.firma_adi,
                    d.departman_adi,
                    d.departman_personel,
                    s.SehirAdi as sehir_adi,
                    p.donem_adi as puantaj_donem_adi,
                    b.banka_adi,
                    h.bankaHesap_iban,
                    h.bankaHesap_no,
                    CONVERT(VARCHAR(19), k.kullanici_olusturma_tarihi, 120) as kullanici_olusturma_tarihi,
                    CONVERT(VARCHAR(19), k.kullanici_son_giris_tarihi, 120) as kullanici_son_giris_tarihi
                FROM kullanicilar k
                LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
                LEFT JOIN Sehirler s ON k.kullanici_sehir_id = s.SehirId
                LEFT JOIN tanim_personel_puantaj_donem p ON k.kullanici_puantaj_donem_id = p.donem_id
                LEFT JOIN Banka_Hesap h ON k.kullanici_banka_id = h.bankaHesap_id
                LEFT JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                WHERE $whereClause
                ORDER BY k.kullanici_ad, k.kullanici_soyad
            ", $params);
            
            echo json_encode(['success' => true, 'data' => $kullanicilar]);
            exit;
        }
        
        if ($action === 'stats') {
            // İstatistikler
            $stats = [
                'toplam' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar")['sayi'] ?? 0,
                'aktif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum = 1")['sayi'] ?? 0,
                'pasif' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum = 0")['sayi'] ?? 0
            ];
            
            // Son kayıt
            $sonKayit = $db->fetchOne("
                SELECT TOP 1 
                    kullanici_ad, 
                    kullanici_soyad,
                    CONVERT(VARCHAR(10), kullanici_olusturma_tarihi, 104) as tarih
                FROM kullanicilar 
                ORDER BY kullanici_olusturma_tarihi DESC
            ");
            
            if ($sonKayit) {
                $adSoyad = trim(($sonKayit['kullanici_ad'] ?? '') . ' ' . ($sonKayit['kullanici_soyad'] ?? ''));
                $stats['son_kayit'] = ($adSoyad ?: 'Anonim') . ' (' . $sonKayit['tarih'] . ')';
            } else {
                $stats['son_kayit'] = '-';
            }
            
            echo json_encode(['success' => true, 'data' => $stats]);
            exit;
        }
        
        if ($action === 'get_firmalar') {
            $firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
            echo json_encode(['success' => true, 'data' => $firmalar]);
            exit;
        }
        
        if ($action === 'get_departmanlar') {
            $departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");
            echo json_encode(['success' => true, 'data' => $departmanlar]);
            exit;
        }
        
        if ($action === 'get_sehirler') {
            $sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Sehirler ORDER BY SehirAdi");
            echo json_encode(['success' => true, 'data' => $sehirler]);
            exit;
        }
        
        if ($action === 'get_puantaj_donemler') {
            $donemler = $db->fetchAll("SELECT donem_id, donem_adi FROM tanim_personel_puantaj_donem WHERE donem_durum = 1 ORDER BY donem_adi");
            echo json_encode(['success' => true, 'data' => $donemler]);
            exit;
        }
        
        if ($action === 'get_bankalar') {
            $bankalar = $db->fetchAll("
                SELECT 
                    h.bankaHesap_id,
                    h.bankaHesap_iban,
                    h.bankaHesap_no,
                    b.banka_adi,
                    b.banka_kodu
                FROM Banka_Hesap h
                INNER JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                WHERE h.bankaHesap_durum = 1 AND b.banka_personel = 1
                ORDER BY b.banka_adi, h.bankaHesap_iban
            ");
            echo json_encode(['success' => true, 'data' => $bankalar]);
            exit;
        }
        
        if ($action === 'kullanici_ekle') {
            // Yetki kontrolü
            if (!$pagePermissions['can_add']) {
                throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
            }
            
            $email = trim($_POST['email'] ?? '');
            $sifre = trim($_POST['sifre'] ?? '');
            $ad = trim($_POST['ad'] ?? '');
            $soyad = trim($_POST['soyad'] ?? '');
            $telefon = trim($_POST['telefon'] ?? '');
            $firma_id = !empty($_POST['firma_id']) ? intval($_POST['firma_id']) : null;
            $departman_id = !empty($_POST['departman_id']) ? intval($_POST['departman_id']) : null;
            $tc_kimlik = trim($_POST['tc_kimlik_no'] ?? '');
            $iban = trim($_POST['iban'] ?? '');
            $maas = !empty($_POST['maas']) ? intval($_POST['maas']) : null;
            $dogum_tarihi = !empty($_POST['dogum_tarihi']) ? $_POST['dogum_tarihi'] : null;
            $ise_giris = !empty($_POST['ise_giris_tarihi']) ? $_POST['ise_giris_tarihi'] : null;
            $banka_id = !empty($_POST['banka_id']) ? intval($_POST['banka_id']) : null;
            $sehir_id = !empty($_POST['sehir_id']) ? intval($_POST['sehir_id']) : null;
            $bes_durumu = isset($_POST['bes_durumu']) ? intval($_POST['bes_durumu']) : 0;
            $puantaj_donem_id = !empty($_POST['puantaj_donem_id']) ? intval($_POST['puantaj_donem_id']) : null;
            
            if (empty($email) || empty($sifre)) {
                throw new Exception('Email ve şifre boş olamaz');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Geçersiz email adresi');
            }
            
            // Email kontrolü
            $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ?", [$email]);
            if ($emailKontrol) {
                throw new Exception('Bu email adresi zaten kayıtlı');
            }
            
            $id = $db->insert('kullanicilar', [
                'kullanici_email' => $email,
                'kullanici_sifre_hash' => password_hash($sifre, PASSWORD_DEFAULT),
                'kullanici_ad' => $ad,
                'kullanici_soyad' => $soyad,
                'kullanici_telefon' => $telefon,
                'kullanici_firma_id' => $firma_id,
                'kullanici_departman_id' => $departman_id,
                'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                'kullanici_iban' => $iban ?: null,
                'kullanici_maas' => $maas,
                'kullanici_dogum_tarihi' => $dogum_tarihi,
                'kullanici_ise_giris_tarihi' => $ise_giris,
                'kullanici_banka_id' => $banka_id,
                'kullanici_sehir_id' => $sehir_id,
                'kullanici_bes_durumu' => $bes_durumu,
                'kullanici_puantaj_donem_id' => $puantaj_donem_id
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Kullanıcı eklendi', 'id' => $id]);
            exit;
        }
        
        if ($action === 'kullanici_guncelle') {
            // Yetki kontrolü
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $ad = trim($_POST['ad'] ?? '');
            $soyad = trim($_POST['soyad'] ?? '');
            $telefon = trim($_POST['telefon'] ?? '');
            $durum = intval($_POST['durum'] ?? 1);
            $sifre = trim($_POST['sifre'] ?? '');
            $firma_id = !empty($_POST['firma_id']) ? intval($_POST['firma_id']) : null;
            $departman_id = !empty($_POST['departman_id']) ? intval($_POST['departman_id']) : null;
            $tc_kimlik = trim($_POST['tc_kimlik_no'] ?? '');
            $iban = trim($_POST['iban'] ?? '');
            $maas = !empty($_POST['maas']) ? intval($_POST['maas']) : null;
            $dogum_tarihi = !empty($_POST['dogum_tarihi']) ? $_POST['dogum_tarihi'] : null;
            $ise_giris = !empty($_POST['ise_giris_tarihi']) ? $_POST['ise_giris_tarihi'] : null;
            $banka_id = !empty($_POST['banka_id']) ? intval($_POST['banka_id']) : null;
            $sehir_id = !empty($_POST['sehir_id']) ? intval($_POST['sehir_id']) : null;
            $bes_durumu = isset($_POST['bes_durumu']) ? intval($_POST['bes_durumu']) : 0;
            $puantaj_donem_id = !empty($_POST['puantaj_donem_id']) ? intval($_POST['puantaj_donem_id']) : null;
            
            if (empty($email) || $id <= 0) {
                throw new Exception('Geçersiz veri');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Geçersiz email adresi');
            }
            
            // Email kontrolü (kendisi hariç)
            $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ? AND kullanici_id != ?", [$email, $id]);
            if ($emailKontrol) {
                throw new Exception('Bu email adresi başka bir kullanıcıda kayıtlı');
            }
            
            $updateData = [
                'kullanici_email' => $email,
                'kullanici_ad' => $ad,
                'kullanici_soyad' => $soyad,
                'kullanici_telefon' => $telefon,
                'kullanici_durum' => $durum,
                'kullanici_firma_id' => $firma_id,
                'kullanici_departman_id' => $departman_id,
                'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                'kullanici_iban' => $iban ?: null,
                'kullanici_maas' => $maas,
                'kullanici_dogum_tarihi' => $dogum_tarihi,
                'kullanici_ise_giris_tarihi' => $ise_giris,
                'kullanici_banka_id' => $banka_id,
                'kullanici_sehir_id' => $sehir_id,
                'kullanici_bes_durumu' => $bes_durumu,
                'kullanici_puantaj_donem_id' => $puantaj_donem_id
            ];
            
            // Şifre değiştirilecekse
            if (!empty($sifre)) {
                $updateData['kullanici_sifre_hash'] = password_hash($sifre, PASSWORD_DEFAULT);
            }
            
            $db->update('kullanicilar', $updateData, ['kullanici_id' => $id]);
            
            echo json_encode(['success' => true, 'message' => 'Kullanıcı güncellendi']);
            exit;
        }
        
        if ($action === 'kullanici_olarak_giris') {
            // Sadece yöneticiler
            if (!$pagePermissions['is_admin']) {
                throw new Exception('Yalnızca yöneticiler bu işlemi yapabilir.');
            }

            $hedefId = intval($_POST['kullanici_id'] ?? 0);

            if ($hedefId <= 0) {
                throw new Exception('Geçersiz kullanıcı.');
            }
            if ($hedefId == $user['kullanici_id']) {
                throw new Exception('Kendi hesabınıza zaten giriş yaptınız.');
            }

            if (!Auth::impersonate($hedefId)) {
                throw new Exception('Kullanıcı bulunamadı veya pasif durumda.');
            }

            echo json_encode(['success' => true, 'redirect' => '/admin/anasayfa']);
            exit;
        }

        if ($action === 'durum_degistir') {
            // Yetki kontrolu
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            }

            $id = intval($_POST['id'] ?? 0);
            $durum = intval($_POST['durum'] ?? 0) === 1 ? 1 : 0;

            if ($id <= 0) {
                throw new Exception('Geçersiz kullanıcı');
            }

            $db->update('kullanicilar', [
                'kullanici_durum' => $durum,
            ], ['kullanici_id' => $id]);

            echo json_encode([
                'success' => true,
                'durum' => $durum,
                'message' => $durum === 1 ? 'Personel aktif edildi' : 'Personel pasife alındı'
            ]);
            exit;
        }

        if ($action === 'kullanici_sil') {
            // Yetki kontrolü
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('Geçersiz ID');
            }
            
            // Kendi hesabını silmeyi engelle
            if ($id == $user['kullanici_id']) {
                throw new Exception('Kendi hesabınızı silemezsiniz');
            }
            
            // İlişkili kayıt kontrolü (Puantaj)
            $puantajKayit = $db->fetchOne("
                SELECT COUNT(*) as puantaj_sayisi 
                FROM Personel_Puantaj 
                WHERE puantaj_kullanici_id = ?
            ", [$id]);
            
            $puantajSayisi = intval($puantajKayit['puantaj_sayisi'] ?? 0);
            
            if ($puantajSayisi > 0) {
                // İlişkili kayıt varsa soft delete yap (pasif yap)
                $db->update('kullanicilar', [
                    'kullanici_durum' => 0,
                    'kullanici_ise_cikis_tarihi' => date('Y-m-d')
                ], ['kullanici_id' => $id]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Kullanıcının ' . $puantajSayisi . ' adet puantaj kaydı bulunduğu için pasif yapıldı (işten çıkış tarihi: ' . date('d.m.Y') . ')'
                ]);
            } else {
                // İlişkili kayıt yoksa tamamen sil
                $db->delete('kullanicilar', ['kullanici_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Kullanıcı kalıcı olarak silindi']);
            }
            exit;
        }
        
        if ($action === 'excel_indir') {
            // Yetki kontrolü
            if (!$pagePermissions['can_view']) {
                throw new Exception('Görüntüleme yetkiniz bulunmamaktadır.');
            }
            
            // Filtreleri al
            $firmaId = $_POST['firma_id'] ?? '';
            $departmanId = $_POST['departman_id'] ?? '';
            $durum = $_POST['durum'] ?? '';
            $search = $_POST['search'] ?? '';
            $tcKimlikNo = $_POST['tc_kimlik_no'] ?? '';
            $maasMin = $_POST['maas_min'] ?? '';
            $maasMax = $_POST['maas_max'] ?? '';
            
            // WHERE koşulları
            $whereConditions = ["1=1"];
            $params = [];
            
            if ($firmaId) {
                $whereConditions[] = "k.kullanici_firma_id = ?";
                $params[] = $firmaId;
            }
            
            if ($departmanId) {
                $whereConditions[] = "k.kullanici_calisma_departman_id = ?";
                $params[] = $departmanId;
            }
            
            if ($durum !== '') {
                if ($durum === 'NULL') {
                    $whereConditions[] = "k.kullanici_durum IS NULL";
                } else {
                    $whereConditions[] = "k.kullanici_durum = ?";
                    $params[] = $durum;
                }
            }
            
            if ($search) {
                $whereConditions[] = "(k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ? OR k.kullanici_email LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            // T.C. Kimlik No filtresi
            if ($tcKimlikNo) {
                $whereConditions[] = "k.kullanici_tc_kimlik_no LIKE ?";
                $params[] = "%$tcKimlikNo%";
            }
            
            // Taşeron filtresi kaldırıldı: Personel + Taşeron tümü aktarılır

            if ($maasMin !== '') {
                $whereConditions[] = "k.kullanici_maas >= ?";
                $params[] = intval($maasMin);
            }
            
            if ($maasMax !== '') {
                $whereConditions[] = "k.kullanici_maas <= ?";
                $params[] = intval($maasMax);
            }
            
            $whereClause = implode(" AND ", $whereConditions);
            
            $kullanicilar = $db->fetchAll("
                SELECT 
                    k.kullanici_id,
                    k.kullanici_email,
                    k.kullanici_ad,
                    k.kullanici_soyad,
                    k.kullanici_telefon,
                    k.kullanici_tc_kimlik_no,
                    k.kullanici_iban,
                    k.kullanici_maas,
                    CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 104) as kullanici_dogum_tarihi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 104) as kullanici_ise_giris_tarihi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 104) as kullanici_ise_cikis_tarihi,
                    CASE WHEN k.kullanici_durum = 1 THEN 'Aktif' WHEN k.kullanici_durum = 0 THEN 'Pasif' ELSE 'Belirsiz' END as durum_text,
                    CASE WHEN k.kullanici_bes_durumu = 1 THEN 'Evet' ELSE 'Hayır' END as bes_text,
                    f.firma_adi,
                    d.departman_adi,
                    s.SehirAdi as sehir_adi,
                    p.donem_adi as puantaj_donem_adi,
                    b.banka_adi,
                    h.bankaHesap_iban,
                    h.bankaHesap_no
                FROM kullanicilar k
                LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
                LEFT JOIN Sehirler s ON k.kullanici_sehir_id = s.SehirId
                LEFT JOIN tanim_personel_puantaj_donem p ON k.kullanici_puantaj_donem_id = p.donem_id
                LEFT JOIN Banka_Hesap h ON k.kullanici_banka_id = h.bankaHesap_id
                LEFT JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                WHERE $whereClause
                ORDER BY k.kullanici_ad, k.kullanici_soyad
            ", $params);
            
            // Excel dosyası oluştur
            $filename = 'personel_listesi_' . date('Y-m-d_H-i-s') . '.xls';
            
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // UTF-8 BOM ekle (Türkçe karakterler için)
            echo "\xEF\xBB\xBF";
            
            // Excel tablosu
            echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
            echo '<head>';
            echo '<meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
            echo '<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
            echo '<x:Name>Personel Listesi</x:Name>';
            echo '<x:WorksheetOptions><x:Print><x:ValidPrinterInfo/></x:Print></x:WorksheetOptions>';
            echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>';
            echo '</head>';
            echo '<body>';
            echo '<table border="1">';
            
            // Başlıklar
            echo '<thead>';
            echo '<tr style="background-color: #0d6efd; color: white; font-weight: bold;">';
            echo '<th>ID</th>';
            echo '<th>Ad</th>';
            echo '<th>Soyad</th>';
            echo '<th>E-posta</th>';
            echo '<th>Telefon</th>';
            echo '<th>TC Kimlik No</th>';
            echo '<th>IBAN</th>';
            echo '<th>Banka Adı</th>';
            echo '<th>Banka Hesap No</th>';
            echo '<th>Maaş</th>';
            echo '<th>Firma</th>';
            echo '<th>Çalıştığı Departman</th>';
            echo '<th>Şehir</th>';
            echo '<th>Puantaj Dönemi</th>';
            echo '<th>Doğum Tarihi</th>';
            echo '<th>İşe Giriş Tarihi</th>';
            echo '<th>İşten Çıkış Tarihi</th>';
            echo '<th>BES Durumu</th>';
            echo '<th>Durum</th>';
            echo '</tr>';
            echo '</thead>';
            
            // Veriler
            echo '<tbody>';
            foreach ($kullanicilar as $k) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($k['kullanici_id'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_ad'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_soyad'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_email'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_telefon'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_tc_kimlik_no'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_iban'] ?: ($k['bankaHesap_iban'] ?? '')) . '</td>';
                echo '<td>' . htmlspecialchars($k['banka_adi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['bankaHesap_no'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_maas'] ? number_format($k['kullanici_maas'], 2, ',', '.') . ' ₺' : '') . '</td>';
                echo '<td>' . htmlspecialchars($k['firma_adi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['departman_adi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['sehir_adi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['puantaj_donem_adi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_dogum_tarihi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_ise_giris_tarihi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_ise_cikis_tarihi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['bes_text'] ?? 'Hayır') . '</td>';
                echo '<td>' . htmlspecialchars($k['durum_text'] ?? '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</body>';
            echo '</html>';
            exit;
        }
        
        // ==========================================
        // PERSONEL BİRLEŞTİRME İŞLEMLERİ
        // ==========================================
        
        if ($action === 'merge_search') {
            if (!$pagePermissions['can_edit'] || !$pagePermissions['can_delete']) {
                throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
            }
            
            $q = trim($_POST['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }
            
            $results = $db->fetchAll("
                SELECT TOP 20
                    k.kullanici_id,
                    k.kullanici_ad,
                    k.kullanici_soyad,
                    k.kullanici_tc_kimlik_no,
                    k.kullanici_durum,
                    d.departman_adi
                FROM kullanicilar k
                LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
                WHERE (k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ?
                    OR k.kullanici_tc_kimlik_no LIKE ?
                    OR (ISNULL(k.kullanici_ad,'') + ' ' + ISNULL(k.kullanici_soyad,'')) LIKE ?)
                ORDER BY k.kullanici_ad, k.kullanici_soyad
            ", ["%$q%", "%$q%", "%$q%", "%$q%"]);
            
            $data = [];
            foreach ($results as $r) {
                $durum = $r['kullanici_durum'] == 1 ? 'Aktif' : ($r['kullanici_durum'] == 0 ? 'Pasif' : 'Belirsiz');
                $tc = $r['kullanici_tc_kimlik_no'] ? ' - TC: ' . $r['kullanici_tc_kimlik_no'] : '';
                $dept = $r['departman_adi'] ? ' | ' . $r['departman_adi'] : '';
                $text = trim(($r['kullanici_ad'] ?? '') . ' ' . ($r['kullanici_soyad'] ?? '')) . $tc . ' (ID: ' . $r['kullanici_id'] . ') - ' . $durum . $dept;
                $data[] = ['id' => $r['kullanici_id'], 'text' => $text];
            }
            
            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }
        
        if ($action === 'merge_compare') {
            if (!$pagePermissions['can_edit'] || !$pagePermissions['can_delete']) {
                throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
            }
            
            $id1 = intval($_POST['id1'] ?? 0);
            $id2 = intval($_POST['id2'] ?? 0);
            
            if ($id1 <= 0 || $id2 <= 0 || $id1 === $id2) {
                throw new Exception('Geçersiz veya aynı personel seçildi.');
            }
            
            $sqlFields = "
                SELECT 
                    k.kullanici_id, k.kullanici_ad, k.kullanici_soyad, k.kullanici_email,
                    k.kullanici_telefon, k.kullanici_tc_kimlik_no, k.kullanici_iban,
                    k.kullanici_maas, k.kullanici_durum, k.kullanici_bes_durumu,
                    f.firma_adi, d.departman_adi, s.SehirAdi as sehir_adi,
                    b.banka_adi, p.donem_adi as puantaj_donem_adi,
                    CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 120) as dogum_tarihi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 120) as ise_giris,
                    CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) as ise_cikis,
                    CONVERT(VARCHAR(19), k.kullanici_olusturma_tarihi, 120) as olusturma_tarihi,
                    CONVERT(VARCHAR(19), k.kullanici_son_giris_tarihi, 120) as son_giris
                FROM kullanicilar k
                LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                LEFT JOIN Departmanlar d ON k.kullanici_calisma_departman_id = d.departman_id
                LEFT JOIN Sehirler s ON k.kullanici_sehir_id = s.SehirId
                LEFT JOIN Banka_Hesap h ON k.kullanici_banka_id = h.bankaHesap_id
                LEFT JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                LEFT JOIN tanim_personel_puantaj_donem p ON k.kullanici_puantaj_donem_id = p.donem_id
                WHERE k.kullanici_id = ?
            ";
            
            $personel1 = $db->fetchOne($sqlFields, [$id1]);
            $personel2 = $db->fetchOne($sqlFields, [$id2]);
            
            if (!$personel1 || !$personel2) {
                throw new Exception('Personel kayıtları bulunamadı.');
            }
            
            $related = [];
            foreach ([$id1, $id2] as $pid) {
                $r = [];
                $r['puantaj'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM Personel_Puantaj WHERE puantaj_kullanici_id = ?", [$pid])['c'] ?? 0);
                $r['odeme'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM Odeme_Hareketleri WHERE odeme_hareket_kullanici_id = ?", [$pid])['c'] ?? 0);
                $r['stok'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM Stok_Hareket WHERE stok_hareket_personel_id = ?", [$pid])['c'] ?? 0);
                $r['talep'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talepler WHERE talep_kullanici_id = ?", [$pid])['c'] ?? 0);
                $r['talep_atanan'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talepler WHERE talep_atanan_kullanici_id = ?", [$pid])['c'] ?? 0);
                $r['yorum'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talep_Yorumlar WHERE yorum_kullanici_id = ?", [$pid])['c'] ?? 0);
                $r['dosya'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talep_Dosyalar WHERE dosya_kullanici_id = ?", [$pid])['c'] ?? 0);
                $r['toplam'] = array_sum($r);
                $related[$pid] = $r;
            }
            
            $conflicts = intval($db->fetchOne("
                SELECT COUNT(*) as c
                FROM Personel_Puantaj a
                INNER JOIN Personel_Puantaj b ON a.puantaj_tarih = b.puantaj_tarih
                WHERE a.puantaj_kullanici_id = ? AND b.puantaj_kullanici_id = ?
            ", [$id1, $id2])['c'] ?? 0);
            
            echo json_encode([
                'success' => true,
                'personel1' => $personel1,
                'personel2' => $personel2,
                'related' => $related,
                'puantaj_conflicts' => $conflicts
            ]);
            exit;
        }
        
        if ($action === 'merge_execute') {
            if (!$pagePermissions['can_edit'] || !$pagePermissions['can_delete']) {
                throw new Exception('Bu işlem için yetkiniz bulunmamaktadır.');
            }
            
            $sourceId = intval($_POST['source_id'] ?? 0);
            $targetId = intval($_POST['target_id'] ?? 0);
            
            if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
                throw new Exception('Geçersiz personel seçimi.');
            }
            
            if ($sourceId == $user['kullanici_id']) {
                throw new Exception('Kendi hesabınızı kaynak olarak seçemezsiniz.');
            }
            
            $source = $db->fetchOne("SELECT * FROM kullanicilar WHERE kullanici_id = ?", [$sourceId]);
            $target = $db->fetchOne("SELECT * FROM kullanicilar WHERE kullanici_id = ?", [$targetId]);
            
            if (!$source || !$target) {
                throw new Exception('Personel kayıtları bulunamadı.');
            }
            
            // İlişkili kayıt sayıları
            $counts = [];
            $counts['puantaj'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM Personel_Puantaj WHERE puantaj_kullanici_id = ?", [$sourceId])['c'] ?? 0);
            $counts['odeme'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM Odeme_Hareketleri WHERE odeme_hareket_kullanici_id = ?", [$sourceId])['c'] ?? 0);
            $counts['stok'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM Stok_Hareket WHERE stok_hareket_personel_id = ?", [$sourceId])['c'] ?? 0);
            $counts['talep'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talepler WHERE talep_kullanici_id = ?", [$sourceId])['c'] ?? 0);
            $counts['talep_atanan'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talepler WHERE talep_atanan_kullanici_id = ?", [$sourceId])['c'] ?? 0);
            $counts['yorum'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talep_Yorumlar WHERE yorum_kullanici_id = ?", [$sourceId])['c'] ?? 0);
            $counts['dosya'] = intval($db->fetchOne("SELECT COUNT(*) as c FROM IT_Talep_Dosyalar WHERE dosya_kullanici_id = ?", [$sourceId])['c'] ?? 0);
            
            $puantajConflicts = intval($db->fetchOne("
                SELECT COUNT(*) as c FROM Personel_Puantaj a
                INNER JOIN Personel_Puantaj b ON a.puantaj_tarih = b.puantaj_tarih
                WHERE a.puantaj_kullanici_id = ? AND b.puantaj_kullanici_id = ?
            ", [$sourceId, $targetId])['c'] ?? 0);
            
            $conn = $db->getConnection();
            sqlsrv_begin_transaction($conn);
            
            try {
                $sourceAdSoyad = trim(($source['kullanici_ad'] ?? '') . ' ' . ($source['kullanici_soyad'] ?? ''));
                $targetAdSoyad = trim(($target['kullanici_ad'] ?? '') . ' ' . ($target['kullanici_soyad'] ?? ''));
                
                // Source bilgilerini logla (DateTime → string)
                $sourceForLog = [];
                foreach ($source as $key => $val) {
                    if ($val instanceof \DateTime) {
                        $sourceForLog[$key] = $val->format('Y-m-d H:i:s');
                    } else {
                        $sourceForLog[$key] = $val;
                    }
                }
                unset($sourceForLog['kullanici_sifre_hash']);
                
                $logJson = json_encode([
                    'birlesik_kayit_id' => ['eski' => $sourceId, 'yeni' => 'SİLİNDİ'],
                    'kaynak_bilgileri' => $sourceForLog,
                    'tasinan_kayitlar' => [
                        'Personel_Puantaj' => $counts['puantaj'] . ' kayıt (' . $puantajConflicts . ' çakışan silindi)',
                        'Odeme_Hareketleri' => $counts['odeme'] . ' kayıt',
                        'Stok_Hareket' => $counts['stok'] . ' kayıt',

                    ]
                ], JSON_UNESCAPED_UNICODE);
                
                $logAciklama = "Mükerrer personel birleştirmesi: $sourceAdSoyad (ID: $sourceId) silindi, kayıtları $targetAdSoyad (ID: $targetId) üzerine taşındı.";
                
                // 1. Log kaydı
                if (!$db->execute("
                    INSERT INTO Sistem_DegisiklikLog (log_sayfa, log_tablo, log_kayit_id, log_islem_tipi, log_degisiklikler, log_aciklama, log_kullanici_id, log_tarih, Durum)
                    VALUES ('personel-yonetimi', 'kullanicilar', ?, 'MERGE', ?, ?, ?, GETDATE(), 1)
                ", [$targetId, $logJson, $logAciklama, $user['kullanici_id']])) {
                    throw new Exception('Log kaydı oluşturulamadı.');
                }
                
                // 2. Çakışan puantaj kayıtlarını sil (source'dan)
                if ($puantajConflicts > 0) {
                    if (!$db->execute("
                        DELETE FROM Personel_Puantaj WHERE puantaj_kullanici_id = ? 
                        AND puantaj_tarih IN (SELECT b.puantaj_tarih FROM Personel_Puantaj b WHERE b.puantaj_kullanici_id = ?)
                    ", [$sourceId, $targetId])) {
                        throw new Exception('Çakışan puantaj kayıtları silinemedi.');
                    }
                }
                
                // 3. Kalan puantaj kayıtlarını taşı
                if ($counts['puantaj'] > 0) {
                    if (!$db->execute("UPDATE Personel_Puantaj SET puantaj_kullanici_id = ?, puantaj_guncelleyen_kullanici_id = ?, puantaj_guncelleme_tarihi = GETDATE() WHERE puantaj_kullanici_id = ?", [$targetId, $user['kullanici_id'], $sourceId])) {
                        throw new Exception('Puantaj kayıtları taşınamadı.');
                    }
                }
                
                // 4. Ödeme hareketleri
                if ($counts['odeme'] > 0) {
                    if (!$db->execute("UPDATE Odeme_Hareketleri SET odeme_hareket_kullanici_id = ? WHERE odeme_hareket_kullanici_id = ?", [$targetId, $sourceId])) {
                        throw new Exception('Ödeme hareketleri taşınamadı.');
                    }
                }
                
                // 5. Stok hareketleri
                if ($counts['stok'] > 0) {
                    if (!$db->execute("UPDATE Stok_Hareket SET stok_hareket_personel_id = ? WHERE stok_hareket_personel_id = ?", [$targetId, $sourceId])) {
                        throw new Exception('Stok hareketleri taşınamadı.');
                    }
                }
                // 10. Kaynak kaydı sil
                if (!$db->execute("DELETE FROM kullanicilar WHERE kullanici_id = ?", [$sourceId])) {
                    throw new Exception('Kaynak personel silinemedi.');
                }
                
                sqlsrv_commit($conn);
                
                $totalMoved = array_sum($counts);
                echo json_encode([
                    'success' => true,
                    'message' => "$sourceAdSoyad (ID: $sourceId) silindi ve $totalMoved ilişkili kayıt $targetAdSoyad (ID: $targetId) kaydına taşındı."
                ]);
                
            } catch (Exception $e) {
                sqlsrv_rollback($conn);
                throw new Exception('Birleştirme hatası: ' . $e->getMessage());
            }
            
            exit;
        }
        
        throw new Exception('Geçersiz işlem');
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <style>
        .badge-status {
            font-size: 0.85rem;
            padding: 0.35em 0.65em;
        }
        .table-actions {
            white-space: nowrap;
        }
        .btn-group-xs > .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        /* Sidebar geçiş animasyonu */
        .app-main {
            transition: margin-left 0.3s ease-in-out;
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
                                    <i class="bi bi-people"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kullanıcı</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-x-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-clock-history"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Son Kayıt</span>
                                    <span class="info-box-number" id="stat-son" style="font-size: 0.9rem;">-</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- Firma -->
                                    <div class="col-md-3">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select" name="firma_id" id="filter_firma_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Departman -->
                                    <div class="col-md-3">
                                        <label class="form-label">Çalıştığı Departman</label>
                                        <select class="form-select" name="departman_id" id="filter_departman_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Durum -->
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                            <option value="NULL">Başvuru Bekliyor</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Arama -->
                                    <div class="col-md-3">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad, soyad, email...">
                                    </div>
                                    
                                    <!-- T.C. Kimlik No -->
                                    <div class="col-md-2">
                                        <label class="form-label">T.C. Kimlik No</label>
                                        <input type="text" class="form-control" name="tc_kimlik_no" id="filter_tc_kimlik_no" placeholder="TC No..." maxlength="11">
                                    </div>
                                    
                                    <!-- Maaş Aralığı -->
                                    <div class="col-md-2">
                                        <label class="form-label">Min Maaş (₺)</label>
                                        <input type="number" class="form-control" name="maas_min" id="filter_maas_min" placeholder="0" min="0">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Max Maaş (₺)</label>
                                        <input type="number" class="form-control" name="maas_max" id="filter_maas_max" placeholder="100000" min="0">
                                    </div>
                                    
                                    <!-- Butonlar -->
                                    <div class="col-md-12">
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
                    
                    <!-- Kullanıcılar -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Kullanıcılar</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_edit'] && $pagePermissions['can_delete']): ?>
                                <button type="button" class="btn btn-info btn-sm me-1" onclick="openMergeModal()">
                                    <i class="bi bi-arrow-left-right"></i> Birleştir
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-success btn-sm me-1" id="excelIndir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/admin/personel-form" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle"></i> Yeni Kullanıcı
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="kullaniciTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Ad Soyad</th>
                                        <th>Telefon</th>
                                        <th>T.C. Kimlik No</th>
                                        <th>Firma</th>
                                        <th>Çalıştığı Departman</th>
                                        <th>Şehir</th>
                                        <th>Puantaj Dönemi</th>
                                        <th>Banka</th>
                                        <th>Maaş</th>
                                        <th>İşe Giriş</th>
                                        <th>Çıkış Tarihi</th>
                                        <th>Durum</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Personel Birleştirme Modal -->
                    <div class="modal fade" id="mergeModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-xl">
                            <div class="modal-content">
                                <div class="modal-header bg-info text-white">
                                    <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Personel Birleştir</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    
                                    <!-- Adım 1: Personel Seçimi -->
                                    <div id="mergeStep1">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle"></i> Birleştirilecek iki personeli seçin. En az 2 karakter yazarak ad soyad veya TC kimlik no ile arayabilirsiniz.
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Personel 1</label>
                                                <select class="form-select" id="merge_personel1"></select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Personel 2</label>
                                                <select class="form-select" id="merge_personel2"></select>
                                            </div>
                                        </div>
                                        <div class="mt-3 text-end">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                            <button type="button" class="btn btn-info" id="btnCompare" disabled onclick="comparePersonnel()">
                                                <i class="bi bi-arrow-left-right"></i> Karşılaştır
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Adım 2: Karşılaştırma & Birleştirme -->
                                    <div id="mergeStep2" style="display:none;">
                                        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="$('#mergeStep2').hide();$('#mergeStep1').show();">
                                            <i class="bi bi-arrow-left"></i> Geri
                                        </button>
                                        
                                        <!-- Kalacak kayıt seçimi -->
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i> <strong>Kalacak kaydı seçin:</strong> Diğer kayıt silinecek ve ilişkili tüm veriler seçilen kayda taşınacaktır.
                                            <div class="row mt-2">
                                                <div class="col-md-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="keepRecord" id="keepRecord1" value="">
                                                        <label class="form-check-label" for="keepRecord1" id="radioLabel1"></label>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="keepRecord" id="keepRecord2" value="">
                                                        <label class="form-check-label" for="keepRecord2" id="radioLabel2"></label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div id="conflictWarning"></div>
                                        
                                        <!-- Karşılaştırma Tablosu -->
                                        <h6><i class="bi bi-table"></i> Kayıt Karşılaştırması <small class="text-muted">(farklı alanlar sarı ile işaretli)</small></h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-bordered table-sm">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th style="width:20%">Alan</th>
                                                        <th style="width:40%" id="compareHeader1">Personel 1</th>
                                                        <th style="width:40%" id="compareHeader2">Personel 2</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="compareTableBody"></tbody>
                                            </table>
                                        </div>
                                        
                                        <!-- İlişkili Kayıtlar -->
                                        <h6><i class="bi bi-link-45deg"></i> İlişkili Kayıt Sayıları</h6>
                                        <div class="table-responsive mb-3">
                                            <table class="table table-bordered table-sm">
                                                <thead class="table-secondary">
                                                    <tr>
                                                        <th>Tablo</th>
                                                        <th id="relatedHeader1">Personel 1</th>
                                                        <th id="relatedHeader2">Personel 2</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="relatedTableBody"></tbody>
                                            </table>
                                        </div>
                                        
                                        <div class="text-end">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                                            <button type="button" class="btn btn-danger" id="btnMergeExecute" disabled onclick="executeMerge()">
                                                <i class="bi bi-arrow-left-right"></i> Birleştir
                                            </button>
                                        </div>
                                    </div>
                                    
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <?php include __DIR__ . '/../includes/scripts.php'; ?>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentFilters = {};
        const FILTER_STORAGE_KEY = 'personel_yonetimi_filters';
        
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        const CURRENT_USER_ID = <?= intval($user['kullanici_id']) ?>;
        console.log('Sayfa Yetkileri:', permissions);
        
        // Türkçe karakter normalize fonksiyonu
        function turkishToLower(str) {
            if (!str) return '';
            return str.toString()
                .replace(/İ/g, 'i')
                .replace(/I/g, 'ı')
                .replace(/Ş/g, 'ş')
                .replace(/Ğ/g, 'ğ')
                .replace(/Ü/g, 'ü')
                .replace(/Ö/g, 'ö')
                .replace(/Ç/g, 'ç')
                .toLowerCase();
        }
        
        // DataTables için Türkçe karakter destekli arama
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            // Sadece bu tablo için çalış
            if (settings.nTable.id !== 'kullaniciTable') return true;
            
            const searchTerm = turkishToLower($('#kullaniciTable_filter input').val());
            if (!searchTerm) return true;
            
            // Tüm kolonlarda ara
            for (let i = 0; i < data.length; i++) {
                if (turkishToLower(data[i]).includes(searchTerm)) {
                    return true;
                }
            }
            return false;
        });
        
        // Filtreleri localStorage'a kaydet
        function saveFiltersToStorage() {
            localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(currentFilters));
        }
        
        // Filtreleri localStorage'dan yükle
        function loadFiltersFromStorage() {
            const saved = localStorage.getItem(FILTER_STORAGE_KEY);
            if (saved) {
                try {
                    return JSON.parse(saved);
                } catch (e) {
                    return {};
                }
            }
            return {};
        }
        
        // Filtreleri form alanlarına uygula
        function applyFiltersToForm(filters) {
            if (filters.firma_id) {
                $('#filter_firma_id').val(filters.firma_id);
            }
            if (filters.departman_id) {
                $('#filter_departman_id').val(filters.departman_id);
            }
            if (filters.durum) {
                $('#filter_durum').val(filters.durum);
            }
            if (filters.search) {
                $('#filter_search').val(filters.search);
            }
            if (filters.tc_kimlik_no) {
                $('#filter_tc_kimlik_no').val(filters.tc_kimlik_no);
            }
            if (filters.maas_min) {
                $('#filter_maas_min').val(filters.maas_min);
            }
            if (filters.maas_max) {
                $('#filter_maas_max').val(filters.maas_max);
            }
            
            // Select2 güncelle
            setTimeout(() => {
                $('#filter_firma_id').trigger('change.select2');
                $('#filter_departman_id').trigger('change.select2');
                $('#filter_durum').trigger('change.select2');
            }, 100);
        }

        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-son').text(response.data.son_kayit);
                }
            });
        }
        
        function initDataTable() {
            table = $('#kullaniciTable').DataTable({
                processing: true,
                scrollX: true,
                autoWidth: false,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'kullanici_listele', ...currentFilters };
                    },
                    dataSrc: json => json.success ? json.data : []
                },
                columns: [
                    { data: 'kullanici_id' },
                    {
                        data: null,
                        render: data => {
                            const adSoyad = [data.kullanici_ad, data.kullanici_soyad].filter(x => x).join(' ') || '-';
                            const taseronBadge = (data.departman_personel == 0)
                                ? ' <span class="badge bg-warning text-dark" title="Taşeron personel"><i class="bi bi-person-vcard"></i> Taşeron</span>'
                                : '';
                            return `<strong>${adSoyad}</strong>${taseronBadge}`;
                        }
                    },
                    { data: 'kullanici_telefon', defaultContent: '-' },
                    { 
                        data: 'kullanici_tc_kimlik_no', 
                        defaultContent: '<span class="text-muted">-</span>',
                        render: data => data ? data : '<span class="text-muted">-</span>'
                    },
                    { data: 'firma_adi', defaultContent: '<span class="text-muted">-</span>' },
                    { data: 'departman_adi', defaultContent: '<span class="text-muted">-</span>' },
                    { data: 'sehir_adi', defaultContent: '<span class="text-muted">-</span>' },
                    { data: 'puantaj_donem_adi', defaultContent: '<span class="text-muted">-</span>' },
                    { data: 'banka_adi', defaultContent: '<span class="text-muted">-</span>' },
                    { 
                        data: 'kullanici_maas',
                        defaultContent: '<span class="text-muted">-</span>',
                        render: data => {
                            if (!data || data == 0) return '<span class="text-muted">-</span>';
                            return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 0 }).format(data);
                        }
                    },
                    { 
                        data: 'kullanici_ise_giris_tarihi', 
                        defaultContent: '<span class="text-muted">-</span>',
                        render: data => {
                            if (!data) return '<span class="text-muted">-</span>';
                            try {
                                const tarih = new Date(data);
                                return tarih.toLocaleDateString('tr-TR');
                            } catch(e) {
                                return '<span class="text-muted">-</span>';
                            }
                        }
                    },
                    { 
                        data: 'kullanici_ise_cikis_tarihi', 
                        defaultContent: '<span class="text-muted">-</span>',
                        render: data => {
                            if (!data) return '<span class="text-muted">-</span>';
                            try {
                                const tarih = new Date(data);
                                return `<span class="text-danger">${tarih.toLocaleDateString('tr-TR')}</span>`;
                            } catch(e) {
                                return '<span class="text-muted">-</span>';
                            }
                        }
                    },
                    { 
                        data: 'kullanici_durum',
                        className: 'text-center',
                        render: (data, type, row) => {
                            if (type !== 'display') return data;

                            // Duzenleme yetkisi yoksa salt okunur rozet
                            if (!permissions.can_edit) {
                                if (data == 1) return '<span class="badge bg-success">Aktif</span>';
                                if (data == 0) return '<span class="badge bg-danger">Pasif</span>';
                                return '<span class="badge bg-warning">Başvuru Bekliyor</span>';
                            }

                            const baslik = data == 1 ? 'Aktif' : (data == 0 ? 'Pasif' : 'Başvuru Bekliyor');
                            return `<div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input durum-switch" type="checkbox" role="switch"
                                       id="durum_${row.kullanici_id}" value="1"
                                       data-id="${row.kullanici_id}" title="${baslik}"
                                       ${data == 1 ? 'checked' : ''}>
                                <label class="form-check-label" for="durum_${row.kullanici_id}"></label>
                            </div>`;
                        }
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: data => {
                            const adSoyad = [data.kullanici_ad, data.kullanici_soyad].filter(x => x).join(' ') || 'Kullanıcı';
                            let buttons = '';
                            
                            if (permissions.can_edit) {
                                buttons += `<a href="/admin/personel-form?id=${data.kullanici_id}" class="btn btn-sm btn-warning" title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </a> `;
                            }
                            
                            if (permissions.can_delete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="kullaniciSil(${data.kullanici_id}, '${adSoyad}')" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button> `;
                            }

                            if (permissions.is_admin && data.kullanici_id != CURRENT_USER_ID && data.kullanici_durum == 1) {
                                buttons += `<button class="btn btn-sm btn-info text-white" onclick="kullaniciOlarakGirisYap(${data.kullanici_id}, '${adSoyad}')" title="Bu kullanıcı olarak giriş yap">
                                    <i class="bi bi-person-fill-check"></i>
                                </button>`;
                            }

                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[1, 'asc']]
            });
        }
        
        function loadFirmalar(targetSelect = '#filter_firma_id', callback = null) {
            $.post('', { action: 'get_firmalar' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(f => select.append(`<option value="${f.firma_id}">${f.firma_adi}</option>`));
                }
                if (callback) callback();
            });
        }
        
        function loadDepartmanlar(targetSelect = '#filter_departman_id', callback = null) {
            $.post('', { action: 'get_departmanlar' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(d => select.append(`<option value="${d.departman_id}">${d.departman_adi}</option>`));
                }
                if (callback) callback();
            });
        }
        
        $(document).ready(() => {
            loadStats();
            
            // Kaydedilmiş filtreleri yükle
            currentFilters = loadFiltersFromStorage();
            
            // DataTable başlat
            initDataTable();
            
            // Filtre dropdown'larını doldur ve sonra filtreleri uygula
            Promise.all([
                new Promise(resolve => loadFirmalar('#filter_firma_id', resolve)),
                new Promise(resolve => loadDepartmanlar('#filter_departman_id', resolve))
            ]).then(() => {
                // Kaydedilmiş filtreleri form alanlarına uygula
                applyFiltersToForm(currentFilters);
                
                // Filtre varsa filtre kartını aç
                if (Object.keys(currentFilters).length > 0) {
                    $('#filterCard').addClass('show');
                }
            });
            
            // Sidebar toggle - DataTable genişliğini yeniden hesapla
            // Sidebar butonuna click event ekle
            $('[data-lte-toggle="sidebar"]').on('click', function() {
                setTimeout(function() {
                    if (table) {
                        $(window).trigger('resize');
                        table.columns.adjust().draw();
                    }
                }, 350);
            });
            
            // Body class değişimini izle (sidebar-collapse eklendiğinde/kaldırıldığında)
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        setTimeout(function() {
                            if (table) {
                                $(window).trigger('resize');
                                table.columns.adjust().draw();
                            }
                        }, 350);
                    }
                });
            });
            
            const bodyElement = document.querySelector('body');
            if (bodyElement) {
                observer.observe(bodyElement, { attributes: true });
            }
            
            // Filtre dropdown'larını doldur (Promise destekli)
            // Not: Artık document.ready içinde Promise.all ile yönetiliyor
            
            // Filtre dropdown'ları için Select2 başlat
            setTimeout(() => {
                $('.form-select').select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: 'Seçiniz...',
                    allowClear: true,
                    language: {
                        noResults: function() { return "Sonuç bulunamadı"; },
                        searching: function() { return "Aranıyor..."; }
                    }
                });
            }, 300);
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                currentFilters = {
                    firma_id: $('#filter_firma_id').val(),
                    departman_id: $('#filter_departman_id').val(),
                    tc_kimlik_no: $('#filter_tc_kimlik_no').val(),
                    durum: $('#filter_durum').val(),
                    search: $('#filter_search').val(),
                    maas_min: $('#filter_maas_min').val(),
                    maas_max: $('#filter_maas_max').val()
                };
                
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) delete currentFilters[key];
                });
                
                // Filtreleri localStorage'a kaydet
                saveFiltersToStorage();
                
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_firma_id').val('').trigger('change.select2');
                $('#filter_departman_id').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                $('#filter_tc_kimlik_no').val('');
                $('#filter_maas_min').val('');
                $('#filter_maas_max').val('');
                currentFilters = {};
                
                // localStorage'dan sil
                localStorage.removeItem(FILTER_STORAGE_KEY);
                
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
            
            // Excel İndir butonu
            $('#excelIndir').on('click', function() {
                const form = $('<form>', {
                    method: 'POST',
                    action: window.location.href
                });
                
                // Action ekle
                form.append($('<input>', { type: 'hidden', name: 'action', value: 'excel_indir' }));
                
                // Mevcut filtreleri ekle
                Object.keys(currentFilters).forEach(key => {
                    if (currentFilters[key]) {
                        form.append($('<input>', { type: 'hidden', name: key, value: currentFilters[key] }));
                    }
                });
                
                // Form gönder ve kaldır
                $('body').append(form);
                form.submit();
                form.remove();
                
                showToast('Excel dosyası indiriliyor...', 'info');
            });
        });
        
        // ==========================================
        // PERSONEL BİRLEŞTİRME FONKSİYONLARI
        // ==========================================
        let mergeSelect2Init = false;
        
        function openMergeModal() {
            $('#mergeStep1').show();
            $('#mergeStep2').hide();
            $('#btnCompare').prop('disabled', true);
            const mergeModal = new bootstrap.Modal(document.getElementById('mergeModal'));
            mergeModal.show();
        }
        
        // Modal gösterildiğinde Select2 başlat
        $('#mergeModal').on('shown.bs.modal', function() {
            if (!mergeSelect2Init) {
                const s2Config = {
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: 'Ad soyad veya TC kimlik no yazın...',
                    allowClear: true,
                    minimumInputLength: 2,
                    dropdownParent: $('#mergeModal'),
                    language: {
                        noResults: function() { return "Sonuç bulunamadı"; },
                        searching: function() { return "Aranıyor..."; },
                        inputTooShort: function() { return "En az 2 karakter yazın..."; }
                    },
                    ajax: {
                        url: '',
                        type: 'POST',
                        delay: 300,
                        data: function(params) {
                            return { action: 'merge_search', q: params.term };
                        },
                        processResults: function(data) {
                            return { results: data.success ? data.data : [] };
                        }
                    }
                };
                $('#merge_personel1').select2(s2Config);
                $('#merge_personel2').select2($.extend(true, {}, s2Config));
                mergeSelect2Init = true;
            } else {
                $('#merge_personel1').val(null).trigger('change.select2');
                $('#merge_personel2').val(null).trigger('change.select2');
            }
        });
        
        // Personel seçim kontrolü
        $(document).on('change', '#merge_personel1, #merge_personel2', function() {
            const id1 = $('#merge_personel1').val();
            const id2 = $('#merge_personel2').val();
            $('#btnCompare').prop('disabled', !(id1 && id2 && id1 !== id2));
            if (id1 && id2 && id1 === id2) {
                showToast('Aynı personeli iki kez seçemezsiniz!', 'warning');
            }
        });
        
        function comparePersonnel() {
            const id1 = $('#merge_personel1').val();
            const id2 = $('#merge_personel2').val();
            if (!id1 || !id2) return;
            
            showLoading();
            $.post('', { action: 'merge_compare', id1: id1, id2: id2 }, function(response) {
                hideLoading();
                if (!response.success) {
                    showToast(response.message, 'error');
                    return;
                }
                renderComparison(response);
                $('#mergeStep1').hide();
                $('#mergeStep2').show();
            }).fail(function() {
                hideLoading();
                showToast('Karşılaştırma hatası', 'error');
            });
        }
        
        function renderComparison(data) {
            const p1 = data.personel1;
            const p2 = data.personel2;
            const r1 = data.related[p1.kullanici_id];
            const r2 = data.related[p2.kullanici_id];
            const conflicts = data.puantaj_conflicts;
            
            const adSoyad1 = ((p1.kullanici_ad || '') + ' ' + (p1.kullanici_soyad || '')).trim();
            const adSoyad2 = ((p2.kullanici_ad || '') + ' ' + (p2.kullanici_soyad || '')).trim();
            
            // Başlıkları güncelle
            $('#compareHeader1').html(adSoyad1 + ' <small class="text-muted">(ID: ' + p1.kullanici_id + ')</small>');
            $('#compareHeader2').html(adSoyad2 + ' <small class="text-muted">(ID: ' + p2.kullanici_id + ')</small>');
            $('#relatedHeader1').html(adSoyad1);
            $('#relatedHeader2').html(adSoyad2);
            
            const fields = [
                { label: 'ID', key: 'kullanici_id' },
                { label: 'Ad', key: 'kullanici_ad' },
                { label: 'Soyad', key: 'kullanici_soyad' },
                { label: 'Email', key: 'kullanici_email' },
                { label: 'Telefon', key: 'kullanici_telefon' },
                { label: 'TC Kimlik No', key: 'kullanici_tc_kimlik_no' },
                { label: 'Firma', key: 'firma_adi' },
                { label: 'Çalıştığı Departman', key: 'departman_adi' },
                { label: 'IBAN', key: 'kullanici_iban' },
                { label: 'Maaş', key: 'kullanici_maas', format: 'currency' },
                { label: 'Banka', key: 'banka_adi' },
                { label: 'Şehir', key: 'sehir_adi' },
                { label: 'Doğum Tarihi', key: 'dogum_tarihi' },
                { label: 'İşe Giriş', key: 'ise_giris' },
                { label: 'İşe Çıkış', key: 'ise_cikis' },
                { label: 'BES', key: 'kullanici_bes_durumu', format: 'yesno' },
                { label: 'Puantaj Dönemi', key: 'puantaj_donem_adi' },
                { label: 'Durum', key: 'kullanici_durum', format: 'status' },
                { label: 'Oluşturma Tarihi', key: 'olusturma_tarihi' }
            ];
            
            let html = '';
            fields.forEach(function(f) {
                let v1 = p1[f.key] != null ? p1[f.key] : '';
                let v2 = p2[f.key] != null ? p2[f.key] : '';
                const isDiff = String(v1) !== String(v2);
                
                if (f.format === 'currency') {
                    v1 = v1 ? new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 0 }).format(v1) : '';
                    v2 = v2 ? new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 0 }).format(v2) : '';
                }
                if (f.format === 'yesno') { v1 = v1 == 1 ? 'Evet' : 'Hayır'; v2 = v2 == 1 ? 'Evet' : 'Hayır'; }
                if (f.format === 'status') {
                    v1 = p1[f.key] == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>';
                    v2 = p2[f.key] == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-danger">Pasif</span>';
                }
                
                html += '<tr class="' + (isDiff ? 'table-warning' : '') + '">' +
                    '<td><strong>' + f.label + '</strong></td>' +
                    '<td>' + (v1 || '<span class="text-muted">-</span>') + '</td>' +
                    '<td>' + (v2 || '<span class="text-muted">-</span>') + '</td></tr>';
            });
            $('#compareTableBody').html(html);
            
            // İlişkili kayıtlar
            const relLabels = { puantaj: 'Puantaj', odeme: 'Ödeme Hareketleri', stok: 'Stok Hareket', talep: 'IT Talep (sahip)', talep_atanan: 'IT Talep (atanan)', yorum: 'IT Talep Yorum', dosya: 'IT Talep Dosya' };
            let relHtml = '';
            Object.keys(relLabels).forEach(function(key) {
                const c1 = r1[key] || 0;
                const c2 = r2[key] || 0;
                if (c1 > 0 || c2 > 0) {
                    relHtml += '<tr><td>' + relLabels[key] + '</td>' +
                        '<td>' + (c1 > 0 ? '<span class="badge bg-info">' + c1 + '</span>' : '-') + '</td>' +
                        '<td>' + (c2 > 0 ? '<span class="badge bg-info">' + c2 + '</span>' : '-') + '</td></tr>';
                }
            });
            $('#relatedTableBody').html(relHtml || '<tr><td colspan="3" class="text-center text-muted">İlişkili kayıt yok</td></tr>');
            
            // Çakışma uyarısı
            if (conflicts > 0) {
                $('#conflictWarning').html('<div class="alert alert-warning mb-2"><i class="bi bi-exclamation-triangle"></i> <strong>' + conflicts + '</strong> adet puantaj tarih çakışması var. Kaynak kaydın çakışan puantaj kayıtları silinecek.</div>').show();
            } else {
                $('#conflictWarning').hide();
            }
            
            // Radio butonlar
            $('#keepRecord1').val(p1.kullanici_id);
            $('#keepRecord2').val(p2.kullanici_id);
            $('#radioLabel1').html('<strong>' + adSoyad1 + '</strong> (ID: ' + p1.kullanici_id + ') - Bu kayıt kalacak');
            $('#radioLabel2').html('<strong>' + adSoyad2 + '</strong> (ID: ' + p2.kullanici_id + ') - Bu kayıt kalacak');
            $('input[name="keepRecord"]').prop('checked', false);
            $('#btnMergeExecute').prop('disabled', true);
            
            $('input[name="keepRecord"]').off('change').on('change', function() {
                $('#btnMergeExecute').prop('disabled', false);
            });
            
            // Karşılaştırma verisini sakla
            $('#mergeStep2').data('p1', p1).data('p2', p2);
        }
        
        function executeMerge() {
            const keepId = $('input[name="keepRecord"]:checked').val();
            if (!keepId) { showToast('Kalacak kaydı seçin!', 'warning'); return; }
            
            const p1 = $('#mergeStep2').data('p1');
            const p2 = $('#mergeStep2').data('p2');
            const targetId = parseInt(keepId);
            const sourceId = targetId === p1.kullanici_id ? p2.kullanici_id : p1.kullanici_id;
            const sourceAd = targetId === p1.kullanici_id
                ? ((p2.kullanici_ad || '') + ' ' + (p2.kullanici_soyad || '')).trim()
                : ((p1.kullanici_ad || '') + ' ' + (p1.kullanici_soyad || '')).trim();
            const targetAd = targetId === p1.kullanici_id
                ? ((p1.kullanici_ad || '') + ' ' + (p1.kullanici_soyad || '')).trim()
                : ((p2.kullanici_ad || '') + ' ' + (p2.kullanici_soyad || '')).trim();
            
            confirmAction(
                '"' + sourceAd + '" (ID: ' + sourceId + ') silinecek ve tüm kayıtları "' + targetAd + '" (ID: ' + targetId + ') üzerine taşınacak.',
                'Bu işlem geri alınamaz!',
                function() {
                    showLoading();
                    $.post('', { action: 'merge_execute', source_id: sourceId, target_id: targetId }, function(response) {
                        hideLoading();
                        if (response.success) {
                            bootstrap.Modal.getInstance(document.getElementById('mergeModal')).hide();
                            showSuccess('Birleştirme Tamamlandı!', response.message);
                            table.ajax.reload();
                            loadStats();
                        } else {
                            showError('Hata!', response.message);
                        }
                    }).fail(function() {
                        hideLoading();
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
        
        function kullaniciSil(id, adSoyad) {
            confirmAction(
                `"${adSoyad}" kullanıcısını silmek istediğinize emin misiniz?`,
                'Bu işlem geri alınamaz!',
                function() {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=kullanici_sil&id=${id}`
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            table.ajax.reload();
                            loadStats();
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }

        function kullaniciOlarakGirisYap(id, adSoyad) {
            Swal.fire({
                title: 'Kullanıcı Olarak Giriş',
                html: `<strong>${adSoyad}</strong> adlı kullanıcı olarak giriş yapılacak.<br>
                       <small class="text-muted">Orijinal hesabınıza geri dönebilirsiniz.</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0dcaf0',
                cancelButtonText: 'İptal',
                confirmButtonText: '<i class="bi bi-person-fill-check"></i> Giriş Yap'
            }).then(result => {
                if (!result.isConfirmed) return;

                fetch(window.location.href, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=kullanici_olarak_giris&kullanici_id=${id}`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(`${adSoyad} olarak giriş yapıldı. Yönlendiriliyorsunuz...`, 'success');
                        setTimeout(() => { window.location.href = data.redirect; }, 1200);
                    } else {
                        showError('Hata!', data.message);
                    }
                })
                .catch(error => {
                    showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                });
            });
        }
    </script>
<!-- Personel listesi: tablo ici durum switch'i -->
<script>
(function ($) {
    'use strict';

    $(function () {
        $('#kullaniciTable').on('change', '.durum-switch', function () {
            var $sw = $(this);
            var id = $sw.data('id');
            var durum = $sw.is(':checked') ? 1 : 0;

            $sw.prop('disabled', true);

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=durum_degistir&id=' + id + '&durum=' + durum
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    $sw.attr('title', data.durum == 1 ? 'Aktif' : 'Pasif');
                    if (typeof showToast === 'function') showToast(data.message, 'success');
                    if (typeof loadStats === 'function') loadStats();
                } else {
                    $sw.prop('checked', durum !== 1);
                    if (typeof showError === 'function') showError('Hata!', data.message);
                    else alert(data.message);
                }
            })
            .catch(function () {
                $sw.prop('checked', durum !== 1);
                if (typeof showError === 'function') showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
            })
            .finally(function () { $sw.prop('disabled', false); });
        });
    });
})(jQuery);
</script>
</body>
</html>
