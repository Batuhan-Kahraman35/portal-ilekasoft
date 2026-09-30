<?php
/**
 * Admin Panel - Personel Ekleme/Düzenleme Formu
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/DegisiklikLog.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü - Ana sayfanın yetkilerini kullan
$parentPageFile = 'personel-yonetimi.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $parentPageFile
);

// Yetki departmani (kullanici_departman_id) yalnizca Administrator tarafindan degistirilebilir
$isAdmin = !empty($pagePermissions['is_admin']);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

/**
 * Telefonu 10 haneye indirger (5XXXXXXXXX).
 * Rakam dışı karakterler, baştaki 0'lar ve 90 ülke kodu atılır; fazlası varsa son 10 hane alınır.
 */
function telefon10Hane(?string $tel): string
{
    $t = ltrim(preg_replace('/\D/', '', (string)$tel), '0');
    if (strlen($t) > 10 && str_starts_with($t, '90')) {
        $t = ltrim(substr($t, 2), '0');
    }
    return strlen($t) > 10 ? substr($t, -10) : $t;
}

// ID varsa düzenleme modu
$editMode = isset($_GET['id']) && intval($_GET['id']) > 0;
$kullaniciId = $editMode ? intval($_GET['id']) : 0;
$kullanici = null;

// Düzenleme modunda yetki kontrolü
if ($editMode && !$pagePermissions['can_edit']) {
    PageAuth::accessDenied('Düzenleme yetkiniz bulunmamaktadır.');
}

// Ekleme modunda yetki kontrolü
if (!$editMode && !$pagePermissions['can_add']) {
    PageAuth::accessDenied('Ekleme yetkiniz bulunmamaktadır.');
}

// Mevcut kullanıcı bilgilerini çek
if ($editMode) {
    $kullanici = $db->fetchOne("
        SELECT 
            k.*,
            CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 120) as kullanici_dogum_tarihi,
            CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 120) as kullanici_ise_giris_tarihi,
            CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) as kullanici_ise_cikis_tarihi,
            CONVERT(VARCHAR(19), k.kullanici_olusturma_tarihi, 120) as kullanici_olusturma_tarihi,
            CONVERT(VARCHAR(19), k.kullanici_guncelleme_tarihi, 120) as kullanici_guncelleme_tarihi,
            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
        FROM kullanicilar k
        LEFT JOIN kullanicilar olusturan ON k.kullanici_olusturan_id = olusturan.kullanici_id
        LEFT JOIN kullanicilar guncelleyen ON k.kullanici_guncelleyen_id = guncelleyen.kullanici_id
        WHERE k.kullanici_id = ?
    ", [$kullaniciId]);
    
    if (!$kullanici) {
        header('Location: /admin/personel-yonetimi?error=notfound');
        exit;
    }
}

// Sayfa başlığı
$pageTitle = $editMode ? 'Personel Düzenle' : 'Yeni Personel Ekle';

// Mevcut sayfanın bilgilerini al (Ana sayfa bilgileri)
$pageInfo = $db->fetchOne("
    SELECT 
        m.menuler_menu_adi as menu_adi
    FROM tanim_sayfalar s
    LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%personel-yonetimi.php']);

$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Dropdown verileri
$firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
$subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");
$departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");
$birimler = $db->fetchAll("SELECT birim_id, birim_adi FROM Departman_Birim WHERE birim_durum = 1 ORDER BY birim_sira_no, birim_adi");

// Admin olmayan kullanicida yetki departmani salt okunur gosterilir (pasif departmanlar da okunabilsin diye ayri sorgu)
$mevcutDepartmanAdi = null;
if (!$isAdmin && $editMode && !empty($kullanici['kullanici_departman_id'])) {
    $mevcutDepartman = $db->fetchOne(
        "SELECT departman_adi FROM Departmanlar WHERE departman_id = ?",
        [$kullanici['kullanici_departman_id']]
    );
    $mevcutDepartmanAdi = $mevcutDepartman['departman_adi'] ?? null;
}

// Üst yönetici listesi (düzenlemede kişinin kendisi hariç tutulur)
$ustKullanicilar = $db->fetchAll("
    SELECT kullanici_id, kullanici_ad + ' ' + kullanici_soyad as ad_soyad
    FROM kullanicilar
    WHERE kullanici_durum = 1 AND kullanici_id <> ?
    ORDER BY kullanici_ad, kullanici_soyad
", [$kullaniciId]);
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Sehirler ORDER BY SehirAdi");
$puantajDonemler = $db->fetchAll("SELECT donem_id, donem_adi FROM tanim_personel_puantaj_donem WHERE donem_durum = 1 ORDER BY donem_adi");
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

// Varsayılan banka dropdown için bankalar listesi
$bankaListesi = $db->fetchAll("
    SELECT banka_id, banka_adi
    FROM bankalar
    WHERE banka_durum = 1 AND banka_personel = 1
    ORDER BY banka_adi
");

// Personele ait zimmetler (Stok_Hareket / belge_tipi = 'ZIMMET')
// Ürün + seri no bazında net miktar: teslim (tipi=1) artı, iade (tipi=0) eksi
$zimmetler = [];
$aktifZimmetSayisi = 0;
if ($editMode) {
    $zimmetler = $db->fetchAll("
        SELECT
            uh.urun_hizmet_adi,
            uh.urun_hizmet_kodu,
            kat.kategori_adi,
            ISNULL(sh.stok_hareket_seri_no, '') as seri_no,
            SUM(CASE WHEN sh.stok_hareket_tipi = 1 THEN sh.stok_hareket_miktar ELSE -sh.stok_hareket_miktar END) as net_miktar,
            COUNT(*) as hareket_sayisi,
            CONVERT(VARCHAR(19), MAX(sh.stok_hareket_tarihi), 120) as son_tarih_str
        FROM Stok_Hareket sh
        INNER JOIN Urun_Hizmet uh ON sh.urun_hizmet_id = uh.urun_hizmet_id
        LEFT JOIN Kategoriler kat ON uh.urun_hizmet_kategori_id = kat.kategori_id
        WHERE sh.stok_hareket_belge_tipi = 'ZIMMET'
          AND sh.stok_hareket_durum = 1
          AND sh.stok_hareket_personel_id = ?
        GROUP BY uh.urun_hizmet_adi, uh.urun_hizmet_kodu, kat.kategori_adi, ISNULL(sh.stok_hareket_seri_no, '')
        ORDER BY MAX(sh.stok_hareket_tarihi) DESC
    ", [$kullaniciId]);

    foreach ($zimmetler as $z) {
        if (floatval($z['net_miktar']) > 0) {
            $aktifZimmetSayisi++;
        }
    }
}

// AJAX İşlemleri
/**
 * Telefonu ne girilirse girilsin 90XXXXXXXXXX formatina cevirir.
 * Ornek: "0500 123 45 67" / "+90 500 123 4567" / "5001234567" -> "905001234567"
 */
if (!function_exists('personelTelefonNormalize')) {
    function personelTelefonNormalize(?string $telefon): string
    {
        $t = preg_replace('/\D/', '', (string) $telefon);
        if ($t === '') return '';

        // Bastaki 0'lari temizle (00 uluslararasi onek ve 0532... dahil)
        $t = ltrim($t, '0');

        // 90 ulke kodu varsa ayir
        if (strlen($t) > 10 && strpos($t, '90') === 0) {
            $t = substr($t, 2);
        }

        // Hala fazlaysa son 10 haneyi al
        if (strlen($t) > 10) {
            $t = substr($t, -10);
        }

        return '90' . $t;
    }
}

/**
 * Ad ve soyaddan kurumsal e-posta uretir: isim.soyisim@ornekfirma.com.tr
 */
if (!function_exists('personelEpostaUret')) {
    function personelEpostaUret(string $ad, string $soyad, string $alan = 'ornekfirma.com.tr'): string
    {
        $tr = ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','I'=>'i','İ'=>'i','ö'=>'o','Ö'=>'o','ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u'];
        $sadelestir = static function (string $metin) use ($tr): string {
            $metin = strtr($metin, $tr);
            $metin = mb_strtolower($metin, 'UTF-8');
            return preg_replace('/[^a-z0-9]+/', '', $metin);
        };

        $adPart    = $sadelestir($ad);
        $soyadPart = $sadelestir($soyad);

        if ($adPart === '' && $soyadPart === '') return '';
        if ($soyadPart === '') return $adPart . '@' . $alan;
        if ($adPart === '')    return $soyadPart . '@' . $alan;

        return $adPart . '.' . $soyadPart . '@' . $alan;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Telefon ve e-posta on normalizasyonu (tum action'lar icin gecerli)
    if (isset($_POST['telefon'])) {
        $_POST['telefon'] = personelTelefonNormalize($_POST['telefon']);
    }
    if (isset($_POST['email']) && trim((string) $_POST['email']) === '') {
        $_POST['email'] = personelEpostaUret((string) ($_POST['ad'] ?? ''), (string) ($_POST['soyad'] ?? ''));
    }
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'kaydet') {
            $id = intval($_POST['id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $sifre = trim($_POST['sifre'] ?? '');
            $ad = trim($_POST['ad'] ?? '');
            $soyad = trim($_POST['soyad'] ?? '');
            $telefon = telefon10Hane($_POST['telefon'] ?? '');
            $telefon_2 = telefon10Hane($_POST['telefon_2'] ?? '');
            $firma_id = !empty($_POST['firma_id']) ? intval($_POST['firma_id']) : null;
            $sube_id = !empty($_POST['sube_id']) ? intval($_POST['sube_id']) : null;
            $departman_id = !empty($_POST['departman_id']) ? intval($_POST['departman_id']) : null;
            $calisma_departman_id = !empty($_POST['calisma_departman_id']) ? intval($_POST['calisma_departman_id']) : null;

            // Yetki departmani sadece Administrator tarafindan degistirilebilir.
            // Admin degilse istekten gelen deger yok sayilir: duzenlemede mevcut deger korunur,
            // yeni kayitta ekleyen kullanicinin departmani atanir.
            if (!$isAdmin) {
                if ($id > 0) {
                    $mevcutYetkiDepartman = $db->fetchOne(
                        "SELECT kullanici_departman_id FROM kullanicilar WHERE kullanici_id = ?",
                        [$id]
                    );
                    $departman_id = $mevcutYetkiDepartman['kullanici_departman_id'] ?? null;
                } else {
                    $departman_id = !empty($user['departman_id']) ? intval($user['departman_id']) : null;
                }
            }
            $birim_id = !empty($_POST['birim_id']) ? intval($_POST['birim_id']) : null;
            $ust_id = !empty($_POST['ust_id']) ? intval($_POST['ust_id']) : null;
            // Kişi kendi üstü olamaz
            if ($ust_id !== null && $id > 0 && $ust_id === $id) {
                $ust_id = null;
            }
            $tc_kimlik = trim($_POST['tc_kimlik_no'] ?? '');
            $iban = trim($_POST['iban'] ?? '');
            $banka_id_2 = !empty($_POST['banka_id_2']) ? intval($_POST['banka_id_2']) : null;
            $iban_2 = trim($_POST['iban_2'] ?? '');
            $banka_id_3 = !empty($_POST['banka_id_3']) ? intval($_POST['banka_id_3']) : null;
            $iban_3 = trim($_POST['iban_3'] ?? '');
            $maasInput = trim($_POST['maas'] ?? '');
            $maas = null;
            if ($maasInput !== '') {
                $normalizedMaas = preg_replace('/\s+/', '', $maasInput);

                if (strpos($normalizedMaas, ',') !== false && strpos($normalizedMaas, '.') !== false) {
                    // 28.075,50 -> 28075.50
                    $normalizedMaas = str_replace('.', '', $normalizedMaas);
                    $normalizedMaas = str_replace(',', '.', $normalizedMaas);
                } elseif (strpos($normalizedMaas, ',') !== false) {
                    // 28075,50 -> 28075.50
                    $normalizedMaas = str_replace(',', '.', $normalizedMaas);
                }

                if (!is_numeric($normalizedMaas)) {
                    throw new Exception('Maaş alanı için geçerli bir sayı giriniz (örn: 28075,50)');
                }

                $maas = round((float)$normalizedMaas, 2);
            }
            $dogum_tarihi = !empty($_POST['dogum_tarihi']) ? $_POST['dogum_tarihi'] : null;
            $ise_giris = !empty($_POST['ise_giris_tarihi']) ? $_POST['ise_giris_tarihi'] : null;
            $ise_cikis = !empty($_POST['ise_cikis_tarihi']) ? $_POST['ise_cikis_tarihi'] : null;
            $banka_id = !empty($_POST['banka_id']) ? intval($_POST['banka_id']) : null;
            $sehir_id = !empty($_POST['sehir_id']) ? intval($_POST['sehir_id']) : null;
            $bes_durumu = isset($_POST['bes_durumu']) ? intval($_POST['bes_durumu']) : 0;
            $puantaj_donem_id = !empty($_POST['puantaj_donem_id']) ? intval($_POST['puantaj_donem_id']) : null;
            $varsayilan_banka = !empty($_POST['varsayilan_banka']) ? intval($_POST['varsayilan_banka']) : 1;
            $durum = intval($_POST['durum'] ?? 1);
            
            // Validasyon - Departman zorunlu
            if (empty($departman_id)) {
                throw new Exception($isAdmin
                    ? 'Departman seçimi zorunludur'
                    : 'Bu kaydin yetki departmani tanimli degil, yoneticinize basvurun');
            }
            
            // Telefon validasyonu - 10 haneli olmalı (dolu ise)
            if (!empty($telefon) && strlen($telefon) !== 10) {
                throw new Exception('Telefon numarası 10 haneli olmalıdır');
            }
            if (!empty($telefon_2) && strlen($telefon_2) !== 10) {
                throw new Exception('Telefon İş numarası 10 haneli olmalıdır');
            }
            
            // Validasyon - Email opsiyonel
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Geçersiz email adresi');
            }
            
            // TC Kimlik No validasyonu
            if (!empty($tc_kimlik)) {
                // Uzunluk kontrolü
                if (strlen($tc_kimlik) !== 11) {
                    throw new Exception('TC Kimlik No 11 haneli olmalıdır');
                }
                
                // Sadece rakam kontrolü
                if (!ctype_digit($tc_kimlik)) {
                    throw new Exception('TC Kimlik No sadece rakamlardan oluşmalıdır');
                }
                
                // İlk hane 0 olamaz
                if ($tc_kimlik[0] === '0') {
                    throw new Exception('TC Kimlik No sıfır ile başlayamaz');
                }
                
                // Algoritma kontrolü
                $digits = array_map('intval', str_split($tc_kimlik));
                $sum1 = ($digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8]) * 7;
                $sum2 = $digits[1] + $digits[3] + $digits[5] + $digits[7];
                // PHP'de negatif modulo negatif sonuç verebilir, düzeltme yapıyoruz
                $check10 = (($sum1 - $sum2) % 10 + 10) % 10;
                
                if ($check10 !== $digits[9]) {
                    throw new Exception('Geçersiz TC Kimlik No');
                }
                
                $sum11 = array_sum(array_slice($digits, 0, 10));
                if ($sum11 % 10 !== $digits[10]) {
                    throw new Exception('Geçersiz TC Kimlik No');
                }
            }
            
            // Yeni kayıt için şifre zorunlu
            if ($id === 0 && empty($sifre)) {
                throw new Exception('Şifre boş olamaz');
            }
            
            if ($id === 0) {
                // EKLEME
                if (!$pagePermissions['can_add']) {
                    throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
                }
                
                // Force parametresi kontrolü (kullanıcı uyarıyı geçmek istedi)
                $forceAdd = isset($_POST['force_add']) && $_POST['force_add'] === '1';

                // TC Kimlik No mükerrer kontrolü (force ile GEÇİLEMEZ - TC yasal olarak benzersizdir)
                if (!empty($tc_kimlik)) {
                    // Önce AKTİF personel kontrolü
                    $tcAktifKontrol = $db->fetchOne(
                        "SELECT kullanici_id, kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_tc_kimlik_no = ? AND kullanici_durum = 1",
                        [$tc_kimlik]
                    );
                    if ($tcAktifKontrol) {
                        echo json_encode([
                            'success' => false,
                            'warning' => true,
                            'type' => 'duplicate_tc_active',
                            'existing_id' => $tcAktifKontrol['kullanici_id'],
                            'existing_name' => $tcAktifKontrol['kullanici_ad'] . ' ' . $tcAktifKontrol['kullanici_soyad'],
                            'message' => 'Bu TC Kimlik No aktif bir personelde kayıtlı'
                        ]);
                        exit;
                    }

                    // Sonra PASİF personel kontrolü (işten çıkmış, tekrar giriyor olabilir)
                    $tcPasifKontrol = $db->fetchOne(
                        "SELECT kullanici_id, kullanici_ad, kullanici_soyad,
                                CONVERT(VARCHAR(10), kullanici_ise_cikis_tarihi, 104) as cikis_tarihi
                         FROM kullanicilar
                         WHERE kullanici_tc_kimlik_no = ? AND kullanici_durum = 0",
                        [$tc_kimlik]
                    );
                    if ($tcPasifKontrol) {
                        echo json_encode([
                            'success' => false,
                            'warning' => true,
                            'type' => 'duplicate_tc_inactive',
                            'existing_id' => $tcPasifKontrol['kullanici_id'],
                            'existing_name' => $tcPasifKontrol['kullanici_ad'] . ' ' . $tcPasifKontrol['kullanici_soyad'],
                            'exit_date' => $tcPasifKontrol['cikis_tarihi'],
                            'message' => 'Bu TC Kimlik No daha önce çalışmış ve şu an pasif olan bir personelde kayıtlı'
                        ]);
                        exit;
                    }
                }

                // Ad + Soyad kontrolü (force değilse)
                if (!$forceAdd && !empty($ad) && !empty($soyad)) {
                    // Önce AKTİF personel kontrolü
                    $aktifKontrol = $db->fetchOne(
                        "SELECT kullanici_id, kullanici_ad, kullanici_soyad FROM kullanicilar WHERE LOWER(kullanici_ad) = LOWER(?) AND LOWER(kullanici_soyad) = LOWER(?) AND kullanici_durum = 1", 
                        [$ad, $soyad]
                    );
                    if ($aktifKontrol) {
                        echo json_encode([
                            'success' => false, 
                            'warning' => true,
                            'type' => 'duplicate_name_active',
                            'existing_id' => $aktifKontrol['kullanici_id'],
                            'existing_name' => $aktifKontrol['kullanici_ad'] . ' ' . $aktifKontrol['kullanici_soyad'],
                            'message' => 'Bu ad ve soyad kombinasyonu aktif bir personelde kayıtlı'
                        ]);
                        exit;
                    }
                    
                    // Sonra PASİF personel kontrolü (işten çıkmış olabilir)
                    $pasifKontrol = $db->fetchOne(
                        "SELECT kullanici_id, kullanici_ad, kullanici_soyad, 
                                CONVERT(VARCHAR(10), kullanici_ise_cikis_tarihi, 104) as cikis_tarihi
                         FROM kullanicilar 
                         WHERE LOWER(kullanici_ad) = LOWER(?) AND LOWER(kullanici_soyad) = LOWER(?) AND kullanici_durum = 0", 
                        [$ad, $soyad]
                    );
                    if ($pasifKontrol) {
                        echo json_encode([
                            'success' => false, 
                            'warning' => true,
                            'type' => 'duplicate_name_inactive',
                            'existing_id' => $pasifKontrol['kullanici_id'],
                            'existing_name' => $pasifKontrol['kullanici_ad'] . ' ' . $pasifKontrol['kullanici_soyad'],
                            'exit_date' => $pasifKontrol['cikis_tarihi'],
                            'message' => 'Bu kişi daha önce çalışmış ve şu an pasif durumda'
                        ]);
                        exit;
                    }
                }
                
                // Email kontrolü (sadece email doluysa)
                if (!empty($email)) {
                    $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ?", [$email]);
                    if ($emailKontrol) {
                        throw new Exception('Bu email adresi zaten kayıtlı');
                    }
                }
                
                $insertData = [
                    'kullanici_email' => $email,
                    'kullanici_sifre_hash' => password_hash($sifre, PASSWORD_DEFAULT),
                    'kullanici_ad' => $ad,
                    'kullanici_soyad' => $soyad,
                    'kullanici_telefon' => $telefon ?: null,
                    'kullanici_telefon_2' => $telefon_2 ?: null,
                    'kullanici_firma_id' => $firma_id,
                    'kullanici_sube_id' => $sube_id,
                    'kullanici_departman_id' => $departman_id,
                    'kullanici_calisma_departman_id' => $calisma_departman_id,
                    'kullanici_birim_id' => $birim_id,
                    'kullanici_ust_id' => $ust_id,
                    'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                    'kullanici_iban' => $iban ?: null,
                    'kullanici_banka_id_2' => $banka_id_2,
                    'kullanici_iban_2' => $iban_2 ?: null,
                    'kullanici_banka_id_3' => $banka_id_3,
                    'kullanici_iban_3' => $iban_3 ?: null,
                    'kullanici_maas' => $maas,
                    'kullanici_dogum_tarihi' => $dogum_tarihi,
                    'kullanici_ise_giris_tarihi' => $ise_giris,
                    'kullanici_ise_cikis_tarihi' => $ise_cikis,
                    'kullanici_banka_id' => $banka_id,
                    'kullanici_sehir_id' => $sehir_id,
                    'kullanici_bes_durumu' => $bes_durumu,
                    'kullanici_puantaj_donem_id' => $puantaj_donem_id,
                    'kullanici_varsayilan_banka' => $varsayilan_banka,
                    'kullanici_durum' => $durum,
                    'kullanici_olusturma_tarihi' => date('Y-m-d H:i:s'),
                    'kullanici_olusturan_id' => $user['kullanici_id']
                ];
                
                $newId = $db->insert('kullanicilar', $insertData);
                
                // Log: Yeni personel eklendi (şifre hash loglanmaz)
                $logData = $insertData;
                unset($logData['kullanici_sifre_hash']);
                $log = new DegisiklikLog($db, $user['kullanici_id'], 'personel-form');
                $log->logInsert('kullanicilar', $newId, $logData, 'Yeni personel eklendi: ' . $ad . ' ' . $soyad);
                
                echo json_encode(['success' => true, 'message' => 'Personel başarıyla eklendi', 'id' => $newId]);
                
            } else {
                // GÜNCELLEME
                if (!$pagePermissions['can_edit']) {
                    throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
                }
                
                // Force parametresi kontrolü (kullanıcı uyarıyı geçmek istedi)
                $forceAdd = isset($_POST['force_add']) && $_POST['force_add'] === '1';

                // TC Kimlik No mükerrer kontrolü (kendisi hariç, force ile GEÇİLEMEZ)
                // Aktif/pasif ayrımı yapılmaz; aynı TC hiçbir başka kayıtta olamaz
                if (!empty($tc_kimlik)) {
                    $tcKontrol = $db->fetchOne(
                        "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_durum
                         FROM kullanicilar
                         WHERE kullanici_tc_kimlik_no = ? AND kullanici_id != ?",
                        [$tc_kimlik, $id]
                    );
                    if ($tcKontrol) {
                        echo json_encode([
                            'success' => false,
                            'warning' => true,
                            'type' => 'duplicate_tc_update',
                            'existing_id' => $tcKontrol['kullanici_id'],
                            'existing_name' => $tcKontrol['kullanici_ad'] . ' ' . $tcKontrol['kullanici_soyad'],
                            'existing_active' => (int)$tcKontrol['kullanici_durum'] === 1,
                            'message' => 'Bu TC Kimlik No başka bir personelde kayıtlı'
                        ]);
                        exit;
                    }
                }

                // Ad + Soyad kontrolü (kendisi hariç, force değilse)
                // Güncelleme modunda sadece AKTİF personellerle çakışma kontrolü yap
                // Pasif personellerle aynı isim olması sorun değil
                if (!$forceAdd && !empty($ad) && !empty($soyad)) {
                    $adSoyadKontrol = $db->fetchOne(
                        "SELECT kullanici_id, kullanici_ad, kullanici_soyad FROM kullanicilar WHERE LOWER(kullanici_ad) = LOWER(?) AND LOWER(kullanici_soyad) = LOWER(?) AND kullanici_id != ? AND kullanici_durum = 1", 
                        [$ad, $soyad, $id]
                    );
                    if ($adSoyadKontrol) {
                        echo json_encode([
                            'success' => false, 
                            'warning' => true,
                            'type' => 'duplicate_name_active',
                            'existing_id' => $adSoyadKontrol['kullanici_id'],
                            'existing_name' => $adSoyadKontrol['kullanici_ad'] . ' ' . $adSoyadKontrol['kullanici_soyad'],
                            'message' => 'Bu ad ve soyad kombinasyonu başka bir aktif personelde kayıtlı'
                        ]);
                        exit;
                    }
                }
                
                // Email kontrolü (sadece email doluysa ve kendisi hariç)
                if (!empty($email)) {
                    $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ? AND kullanici_id != ?", [$email, $id]);
                    if ($emailKontrol) {
                        throw new Exception('Bu email adresi başka bir kullanıcıda kayıtlı');
                    }
                }
                
                // Log: Güncelleme öncesi eski veriyi al
                $log = new DegisiklikLog($db, $user['kullanici_id'], 'personel-form');
                $eskiVeri = $log->getMevcutKayit('kullanicilar', 'kullanici_id', $id);
                
                $updateData = [
                    'kullanici_email' => $email,
                    'kullanici_ad' => $ad,
                    'kullanici_soyad' => $soyad,
                    'kullanici_telefon' => $telefon ?: null,
                    'kullanici_telefon_2' => $telefon_2 ?: null,
                    'kullanici_durum' => $durum,
                    'kullanici_firma_id' => $firma_id,
                    'kullanici_sube_id' => $sube_id,
                    'kullanici_departman_id' => $departman_id,
                    'kullanici_calisma_departman_id' => $calisma_departman_id,
                    'kullanici_birim_id' => $birim_id,
                    'kullanici_ust_id' => $ust_id,
                    'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                    'kullanici_iban' => $iban ?: null,
                    'kullanici_banka_id_2' => $banka_id_2,
                    'kullanici_iban_2' => $iban_2 ?: null,
                    'kullanici_banka_id_3' => $banka_id_3,
                    'kullanici_iban_3' => $iban_3 ?: null,
                    'kullanici_maas' => $maas,
                    'kullanici_dogum_tarihi' => $dogum_tarihi,
                    'kullanici_ise_giris_tarihi' => $ise_giris,
                    'kullanici_ise_cikis_tarihi' => $ise_cikis,
                    'kullanici_banka_id' => $banka_id,
                    'kullanici_sehir_id' => $sehir_id,
                    'kullanici_bes_durumu' => $bes_durumu,
                    'kullanici_puantaj_donem_id' => $puantaj_donem_id,
                    'kullanici_varsayilan_banka' => $varsayilan_banka,
                    'kullanici_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                    'kullanici_guncelleyen_id' => $user['kullanici_id']
                ];
                
                // Şifre değiştirilecekse
                if (!empty($sifre)) {
                    $updateData['kullanici_sifre_hash'] = password_hash($sifre, PASSWORD_DEFAULT);
                }
                
                $db->update('kullanicilar', $updateData, ['kullanici_id' => $id]);
                
                // Log: Sadece değişen alanları logla (şifre hash loglanmaz)
                $logData = $updateData;
                unset($logData['kullanici_sifre_hash']);
                if ($eskiVeri) {
                    $log->logUpdate('kullanicilar', $id, $eskiVeri, $logData, 'Personel güncellendi: ' . $ad . ' ' . $soyad);
                }
                
                echo json_encode(['success' => true, 'message' => 'Personel başarıyla güncellendi']);
            }
            exit;
        }
        
        if ($action === 'reactivate') {
            // Pasif personeli aktif et
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            }
            
            $reactivateId = intval($_POST['id'] ?? 0);
            if ($reactivateId <= 0) {
                throw new Exception('Geçersiz personel ID');
            }
            
            // Personelin pasif olduğunu kontrol et
            $personel = $db->fetchOne("SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_durum FROM kullanicilar WHERE kullanici_id = ?", [$reactivateId]);
            if (!$personel) {
                throw new Exception('Personel bulunamadı');
            }
            if ($personel['kullanici_durum'] == 1) {
                throw new Exception('Bu personel zaten aktif durumda');
            }
            
            // Log: Reactivate öncesi eski veriyi al
            $log = new DegisiklikLog($db, $user['kullanici_id'], 'personel-form');
            $eskiVeri = $log->getMevcutKayit('kullanicilar', 'kullanici_id', $reactivateId);
            
            // Personeli aktif et ve işe giriş tarihini güncelle
            $reactivateData = [
                'kullanici_durum' => 1,
                'kullanici_ise_giris_tarihi' => date('Y-m-d'),
                'kullanici_ise_cikis_tarihi' => null,
                'kullanici_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                'kullanici_guncelleyen_id' => $user['kullanici_id']
            ];
            $db->update('kullanicilar', $reactivateData, 'kullanici_id = ?', [$reactivateId]);
            
            // Log: Tekrar aktif edilme
            if ($eskiVeri) {
                $log->logUpdate('kullanicilar', $reactivateId, $eskiVeri, $reactivateData, 'Personel tekrar aktif edildi: ' . $personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad']);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $personel['kullanici_ad'] . ' ' . $personel['kullanici_soyad'] . ' tekrar aktif edildi',
                'redirect_id' => $reactivateId
            ]);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
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
                                <li class="breadcrumb-item"><a href="personel-yonetimi.php">Personel Yönetimi</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Form Kartı -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-person-<?= $editMode ? 'gear' : 'plus' ?>"></i> 
                                <?= $editMode ? 'Personel Bilgilerini Düzenle' : 'Yeni Personel Bilgileri' ?>
                            </h3>
                        </div>
                        <form id="personelForm" autocomplete="off">
                        <!-- Tarayicinin kayitli kullanici/sifre bilgilerini gercek alanlara doldurmasini engelleyen tuzak alanlar -->
                        <input type="text" name="_tuzak_kullanici" autocomplete="username" tabindex="-1" aria-hidden="true" style="position:absolute;opacity:0;height:0;width:0;pointer-events:none;">
                        <input type="password" name="_tuzak_sifre" autocomplete="current-password" tabindex="-1" aria-hidden="true" style="position:absolute;opacity:0;height:0;width:0;pointer-events:none;">
                            <input type="hidden" id="kullanici_id" name="id" value="<?= $kullaniciId ?>">
                            <div class="card-body">
                                
                                <!-- Kişisel Bilgiler -->
                                <h5 class="border-bottom pb-2 mb-3 text-primary">
                                    <i class="bi bi-person-fill"></i> Kişisel Bilgiler
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_ad" class="form-label">Ad</label>
                                        <input type="text" class="form-control" id="kullanici_ad" name="ad" 
                                               value="<?= htmlspecialchars($kullanici['kullanici_ad'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_soyad" class="form-label">Soyad</label>
                                        <input type="text" class="form-control" id="kullanici_soyad" name="soyad"
                                               value="<?= htmlspecialchars($kullanici['kullanici_soyad'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_tc_kimlik_no" class="form-label">T.C. Kimlik No</label>
                                        <input type="text" class="form-control" id="kullanici_tc_kimlik_no" name="tc_kimlik_no" 
                                               maxlength="11" placeholder="XXXXXXXXXXX" pattern="[0-9]{11}"
                                               value="<?= htmlspecialchars($kullanici['kullanici_tc_kimlik_no'] ?? '') ?>">
                                        <div class="invalid-feedback" id="tc_kimlik_error"></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_dogum_tarihi" class="form-label">Doğum Tarihi</label>
                                        <input type="date" class="form-control" id="kullanici_dogum_tarihi" name="dogum_tarihi"
                                               value="<?= htmlspecialchars($kullanici['kullanici_dogum_tarihi'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label for="kullanici_telefon" class="form-label">Telefon</label>
                                        <input type="text" class="form-control telefon-mask" id="kullanici_telefon" name="telefon" 
                                               placeholder="5XX XXX XX XX" maxlength="20"
                                               value="<?= htmlspecialchars(telefon10Hane($kullanici['kullanici_telefon'] ?? '')) ?>">
                                        <div class="form-text">Sadece 10 haneli numara</div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="kullanici_telefon_2" class="form-label">Telefon İş</label>
                                        <input type="text" class="form-control telefon-mask" id="kullanici_telefon_2" name="telefon_2" 
                                               placeholder="5XX XXX XX XX" maxlength="20"
                                               value="<?= htmlspecialchars(telefon10Hane($kullanici['kullanici_telefon_2'] ?? '')) ?>">
                                        <div class="form-text">Sadece 10 haneli numara</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="kullanici_email" name="email" autocomplete="off" data-lpignore="true"
                                               value="<?= htmlspecialchars($kullanici['kullanici_email'] ?? '') ?>">
                                    </div>
                                </div>
                                
                                <!-- İş Bilgileri -->
                                <h5 class="border-bottom pb-2 mb-3 mt-4 text-primary">
                                    <i class="bi bi-briefcase-fill"></i> İş Bilgileri
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_firma_id" class="form-label">Firma</label>
                                        <select class="form-select" id="kullanici_firma_id" name="firma_id">
                                            <option value="">Firma Seçiniz</option>
                                            <?php foreach ($firmalar as $f): ?>
                                            <option value="<?= $f['firma_id'] ?>" <?= ($kullanici['kullanici_firma_id'] ?? '') == $f['firma_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($f['firma_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_sube_id" class="form-label">Şube</label>
                                        <select class="form-select" id="kullanici_sube_id" name="sube_id">
                                            <option value="">Şube Seçiniz</option>
                                            <?php foreach ($subeler as $sb): ?>
                                            <option value="<?= $sb['sube_id'] ?>" <?= ($kullanici['kullanici_sube_id'] ?? '') == $sb['sube_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sb['sube_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <?php if ($isAdmin): ?>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_departman_id" class="form-label">Departman <span class="text-danger">*</span></label>
                                        <select class="form-select" id="kullanici_departman_id" name="departman_id" required>
                                            <option value="">Departman Seçiniz</option>
                                            <?php foreach ($departmanlar as $d): ?>
                                            <option value="<?= $d['departman_id'] ?>" <?= ($kullanici['kullanici_departman_id'] ?? '') == $d['departman_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($d['departman_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Yetkilendirme departmani. Menu ve sayfa yetkileri bu alana gore belirlenir.</small>
                                    </div>
                                    <?php elseif ($editMode): ?>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Departman</label>
                                        <input type="text" class="form-control" readonly
                                               value="<?= htmlspecialchars($mevcutDepartmanAdi ?: 'Tanimli degil') ?>">
                                        <small class="text-muted">Yetkilendirme departmani yalnizca yonetici tarafindan degistirilebilir.</small>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_birim_id" class="form-label">Birim</label>
                                        <select class="form-select" id="kullanici_birim_id" name="birim_id">
                                            <option value="">Birim Seçiniz</option>
                                            <?php foreach ($birimler as $b): ?>
                                            <option value="<?= $b['birim_id'] ?>" <?= ($kullanici['kullanici_birim_id'] ?? '') == $b['birim_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['birim_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_calisma_departman_id" class="form-label">Çalıştığı Departman</label>
                                        <select class="form-select" id="kullanici_calisma_departman_id" name="calisma_departman_id">
                                            <option value="">Departman Seçiniz</option>
                                            <?php foreach ($departmanlar as $d): ?>
                                            <option value="<?= $d['departman_id'] ?>" <?= ($kullanici['kullanici_calisma_departman_id'] ?? '') == $d['departman_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($d['departman_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Personelin fiilen çalıştığı departman. Üstteki Departman alanı yetkilendirme içindir.</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_ust_id" class="form-label">Üst Yönetici</label>
                                        <select class="form-select" id="kullanici_ust_id" name="ust_id">
                                            <option value="">Üst Yönetici Seçiniz</option>
                                            <?php foreach ($ustKullanicilar as $u): ?>
                                            <option value="<?= $u['kullanici_id'] ?>" <?= ($kullanici['kullanici_ust_id'] ?? '') == $u['kullanici_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($u['ad_soyad']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Boş bırakılırsa bağlı olduğu üst yönetici tanımlanmaz.</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_ise_giris_tarihi" class="form-label">İşe Giriş Tarihi</label>
                                        <input type="date" class="form-control" id="kullanici_ise_giris_tarihi" name="ise_giris_tarihi"
                                               value="<?= htmlspecialchars($kullanici['kullanici_ise_giris_tarihi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_ise_cikis_tarihi" class="form-label">İşten Çıkış Tarihi</label>
                                        <input type="date" class="form-control" id="kullanici_ise_cikis_tarihi" name="ise_cikis_tarihi"
                                               value="<?= htmlspecialchars($kullanici['kullanici_ise_cikis_tarihi'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_sehir_id" class="form-label">Bulunduğu Şehir</label>
                                        <select class="form-select" id="kullanici_sehir_id" name="sehir_id">
                                            <option value="">Şehir Seçiniz</option>
                                            <?php foreach ($sehirler as $s): ?>
                                            <option value="<?= $s['SehirId'] ?>" <?= ($kullanici['kullanici_sehir_id'] ?? '') == $s['SehirId'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($s['SehirAdi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_puantaj_donem_id" class="form-label">Puantaj Dönemi</label>
                                        <select class="form-select" id="kullanici_puantaj_donem_id" name="puantaj_donem_id">
                                            <option value="">Dönem Seçiniz</option>
                                            <?php foreach ($puantajDonemler as $p): ?>
                                            <option value="<?= $p['donem_id'] ?>" <?= ($kullanici['kullanici_puantaj_donem_id'] ?? '') == $p['donem_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($p['donem_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_maas" class="form-label">Maaş (₺)</label>
                                        <input type="text" inputmode="decimal" class="form-control" id="kullanici_maas" name="maas" 
                                               placeholder="0,00"
                                               value="<?= isset($kullanici['kullanici_maas']) && $kullanici['kullanici_maas'] !== null ? htmlspecialchars(number_format((float)$kullanici['kullanici_maas'], 2, ',', '')) : '' ?>">
                                        <small class="form-text text-muted">Örnek giriş: 28075,50</small>
                                    </div>
                                </div>
                                
                                <!-- Banka Bilgileri -->
                                <h5 class="border-bottom pb-2 mb-3 mt-4 text-primary">
                                    <i class="bi bi-bank"></i> Banka Bilgileri
                                </h5>
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="kullanici_varsayilan_banka" class="form-label">Varsayılan Banka</label>
                                        <select class="form-select" id="kullanici_varsayilan_banka" name="varsayilan_banka">
                                            <option value="">Banka Seçiniz</option>
                                            <?php foreach ($bankaListesi as $b): ?>
                                            <option value="<?= $b['banka_id'] ?>" <?= ($kullanici['kullanici_varsayilan_banka'] ?? '') == $b['banka_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['banka_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Maaş ödemelerinde kullanılacak banka</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_banka_id" class="form-label">Banka</label>
                                        <select class="form-select" id="kullanici_banka_id" name="banka_id">
                                            <option value="">Banka Seçiniz</option>
                                            <?php foreach ($bankaListesi as $b): ?>
                                            <option value="<?= $b['banka_id'] ?>" <?= ($kullanici['kullanici_banka_id'] ?? '') == $b['banka_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['banka_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_iban" class="form-label">IBAN</label>
                                        <input type="text" class="form-control" id="kullanici_iban" name="iban" 
                                               maxlength="34" placeholder="TR00 0000 0000 0000 0000 0000 00"
                                               value="<?= htmlspecialchars($kullanici['kullanici_iban'] ?? '') ?>">
                                    </div>
                                </div>
                                
                                <!-- 2. Banka Bilgileri -->
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_banka_id_2" class="form-label">2. Banka</label>
                                        <select class="form-select" id="kullanici_banka_id_2" name="banka_id_2">
                                            <option value="">Banka Seçiniz</option>
                                            <?php foreach ($bankaListesi as $b): ?>
                                            <option value="<?= $b['banka_id'] ?>" <?= ($kullanici['kullanici_banka_id_2'] ?? '') == $b['banka_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['banka_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_iban_2" class="form-label">2. IBAN</label>
                                        <input type="text" class="form-control" id="kullanici_iban_2" name="iban_2" 
                                               maxlength="34" placeholder="TR00 0000 0000 0000 0000 0000 00"
                                               value="<?= htmlspecialchars($kullanici['kullanici_iban_2'] ?? '') ?>">
                                    </div>
                                </div>
                                
                                <!-- 3. Banka Bilgileri -->
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_banka_id_3" class="form-label">3. Banka</label>
                                        <select class="form-select" id="kullanici_banka_id_3" name="banka_id_3">
                                            <option value="">Banka Seçiniz</option>
                                            <?php foreach ($bankaListesi as $b): ?>
                                            <option value="<?= $b['banka_id'] ?>" <?= ($kullanici['kullanici_banka_id_3'] ?? '') == $b['banka_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($b['banka_adi']) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_iban_3" class="form-label">3. IBAN</label>
                                        <input type="text" class="form-control" id="kullanici_iban_3" name="iban_3" 
                                               maxlength="34" placeholder="TR00 0000 0000 0000 0000 0000 00"
                                               value="<?= htmlspecialchars($kullanici['kullanici_iban_3'] ?? '') ?>">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">BES Durumu</label>
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" id="kullanici_bes_durumu" name="bes_durumu" value="1"
                                                   <?= ($kullanici['kullanici_bes_durumu'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="kullanici_bes_durumu">
                                                Bireysel Emeklilik Sistemi Var
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Güvenlik -->
                                <h5 class="border-bottom pb-2 mb-3 mt-4 text-primary">
                                    <i class="bi bi-shield-lock-fill"></i> Güvenlik
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_sifre" class="form-label">
                                            Şifre <?php if (!$editMode): ?><span class="text-danger">*</span><?php endif; ?>
                                        </label>
                                        <input type="password" class="form-control" id="kullanici_sifre" name="sifre" autocomplete="new-password" data-lpignore="true"
                                               <?= !$editMode ? 'required' : '' ?>>
                                        <?php if ($editMode): ?>
                                        <small class="form-text text-muted">Boş bırakırsanız şifre değiştirilmez</small>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($editMode): ?>
                                    <div class="col-md-6 mb-3">
                                        <label for="kullanici_durum" class="form-label">Durum</label>
                                        <select class="form-select" id="kullanici_durum" name="durum">
                                            <option value="1" <?= ($kullanici['kullanici_durum'] ?? 1) == 1 ? 'selected' : '' ?>>Aktif</option>
                                            <option value="0" <?= ($kullanici['kullanici_durum'] ?? 1) == 0 ? 'selected' : '' ?>>Pasif</option>
                                        </select>
                                    </div>
                                    <?php else: ?>
                                    <input type="hidden" name="durum" value="1">
                                    <?php endif; ?>
                                </div>
                                
                            </div>
                            <?php if ($editMode): ?>
                            <!-- Kayıt Bilgileri -->
                            <div class="card-footer bg-light">
                                <div class="row text-muted small">
                                    <div class="col-md-6">
                                        <i class="bi bi-person-plus text-success"></i>
                                        <strong>Oluşturan:</strong> 
                                        <?= htmlspecialchars($kullanici['olusturan_adi'] ?? '-') ?>
                                        <?php if (!empty($kullanici['kullanici_olusturma_tarihi'])): ?>
                                            <span class="ms-2">
                                                <i class="bi bi-calendar-event"></i>
                                                <?= date('d.m.Y H:i', strtotime($kullanici['kullanici_olusturma_tarihi'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6 text-md-end">
                                        <?php if (!empty($kullanici['kullanici_guncelleme_tarihi'])): ?>
                                            <i class="bi bi-pencil-square text-primary"></i>
                                            <strong>Son Güncelleyen:</strong> 
                                            <?= htmlspecialchars($kullanici['guncelleyen_adi'] ?? '-') ?>
                                            <span class="ms-2">
                                                <i class="bi bi-calendar-event"></i>
                                                <?= date('d.m.Y H:i', strtotime($kullanici['kullanici_guncelleme_tarihi'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <i class="bi bi-pencil-square text-muted"></i>
                                            <strong>Son Güncelleyen:</strong> Henüz güncellenmedi
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between">
                                    <a href="/admin/personel-yonetimi" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Geri Dön
                                    </a>
                                    <div>
                                        <?php if ($editMode): ?>
                                        <a href="/admin/degisiklik-log?tablo=kullanicilar&kayit_id=<?= $kullaniciId ?>" class="btn btn-outline-info me-2" target="_blank">
                                            <i class="bi bi-clock-history"></i> Log Geçmişi
                                        </a>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save"></i> <?= $editMode ? 'Güncelle' : 'Kaydet' ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <?php if ($editMode): ?>
                    <!-- Zimmetler -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-box-seam"></i> Zimmetler
                                <?php if ($aktifZimmetSayisi > 0): ?>
                                    <span class="badge text-bg-danger ms-2"><?= $aktifZimmetSayisi ?> aktif zimmet</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success ms-2">Aktif zimmet yok</span>
                                <?php endif; ?>
                            </h3>
                            <div class="card-tools">
                                <a href="/admin/zimmet-yonetimi" class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="bi bi-box-arrow-up-right"></i> Zimmet Yönetimi
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($zimmetler)): ?>
                                <div class="text-muted text-center py-3">
                                    <i class="bi bi-inbox"></i> Bu personele ait zimmet kaydı bulunmuyor.
                                </div>
                            <?php else: ?>
                                <?php if ($aktifZimmetSayisi > 0 && ($kullanici['kullanici_durum'] ?? 1) == 0): ?>
                                <div class="alert alert-danger py-2">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    Personel <strong>pasif</strong> durumda fakat iade alınmamış zimmeti bulunuyor.
                                </div>
                                <?php endif; ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Ürün</th>
                                                <th>Kategori</th>
                                                <th>Seri No</th>
                                                <th class="text-end" width="90">Miktar</th>
                                                <th width="140">Son İşlem</th>
                                                <th width="110">Durum</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($zimmetler as $z): ?>
                                            <?php $aktif = floatval($z['net_miktar']) > 0; ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($z['urun_hizmet_adi']) ?>
                                                    <?php if (!empty($z['urun_hizmet_kodu'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($z['urun_hizmet_kodu']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($z['kategori_adi'] ?? '-') ?></td>
                                                <td>
                                                    <?php if ($z['seri_no'] !== ''): ?>
                                                        <code><?= htmlspecialchars($z['seri_no']) ?></code>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?= $aktif ? rtrim(rtrim(number_format(floatval($z['net_miktar']), 2, ',', '.'), '0'), ',') : '0' ?>
                                                </td>
                                                <td>
                                                    <?= !empty($z['son_tarih_str']) ? date('d.m.Y H:i', strtotime($z['son_tarih_str'])) : '-' ?>
                                                </td>
                                                <td>
                                                    <?php if ($aktif): ?>
                                                        <span class="badge text-bg-danger">Zimmetli</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-success">İade Edildi</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        const editMode = <?= $editMode ? 'true' : 'false' ?>;
        
        $(document).ready(function() {
            // IBAN Mask - TR00 0000 0000 0000 0000 0000 00 formatı
            $('#kullanici_iban, #kullanici_iban_2, #kullanici_iban_3').mask('SS00 0000 0000 0000 0000 0000 00', {
                placeholder: 'TR__ ____ ____ ____ ____ ____ __',
                translation: {
                    'S': { pattern: /[A-Za-z]/, optional: false }
                },
                onKeyPress: function(val, e, field, options) {
                    // Küçük harfleri büyük harfe çevir
                    field.val(val.toUpperCase());
                }
            });
            
            // Telefon - nasil yazilirsa yazilsin (0532..., +90 532..., 90532...) 10 haneye indirilir.
            // Maske kullanilmaz: fazla haneyi PHP'ye ulasmadan kirpip numarayi bozuyordu.
            function telefon10Hane(deger) {
                let t = (deger || '').replace(/\D/g, '').replace(/^0+/, '');
                if (t.length > 10 && t.indexOf('90') === 0) t = t.substring(2).replace(/^0+/, '');
                return t.length > 10 ? t.slice(-10) : t;
            }
            function telefonBicimle(t) {
                return t.length === 10 ? t.replace(/^(\d{3})(\d{3})(\d{2})(\d{2})$/, '$1 $2 $3 $4') : t;
            }
            $('.telefon-mask').each(function() {
                $(this).val(telefonBicimle(telefon10Hane($(this).val())));
            });

            // Telefon validasyon - blur event
            $('.telefon-mask').on('blur', function() {
                const $input = $(this);
                const tel = telefon10Hane($input.val());
                $input.val(telefonBicimle(tel));

                // Boş ise hata gösterme (zorunlu değil)
                if (tel === '') {
                    $input.removeClass('is-invalid').removeClass('is-valid');
                    return;
                }
                
                // 10 hane kontrolü
                if (tel.length === 10 && /^[0-9]+$/.test(tel)) {
                    $input.removeClass('is-invalid').addClass('is-valid');
                } else {
                    $input.removeClass('is-valid').addClass('is-invalid');
                }
            });
            
            // Select2 custom.js'de otomatik başlatılıyor, tekrar başlatmaya gerek yok
            
            // TC Kimlik No doğrulama
            $('#kullanici_tc_kimlik_no').on('blur', function() {
                const tckn = $(this).val().trim();
                const $input = $(this);
                const $error = $('#tc_kimlik_error');
                
                // Boş ise hata gösterme (zorunlu değil)
                if (tckn === '') {
                    $input.removeClass('is-invalid').removeClass('is-valid');
                    $error.text('');
                    return;
                }
                
                // Algoritma ile doğrula
                if (validateTCKN(tckn)) {
                    $input.removeClass('is-invalid').addClass('is-valid');
                    $error.text('');
                } else {
                    $input.removeClass('is-valid').addClass('is-invalid');
                    $error.text('Geçersiz TC Kimlik No');
                }
            });
            
            // Sadece rakam girişine izin ver
            $('#kullanici_tc_kimlik_no').on('keypress', function(e) {
                const charCode = e.which || e.keyCode;
                // 0-9 arası rakamlar ve backspace/delete
                if (charCode < 48 || charCode > 57) {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Form submit
            $('#personelForm').on('submit', function(e) {
                e.preventDefault();
                
                // TC Kimlik No varsa ve geçersizse submit etme
                const tckn = $('#kullanici_tc_kimlik_no').val().trim();
                if (tckn !== '' && !validateTCKN(tckn)) {
                    showWarning('Geçersiz TC Kimlik No!', 'Lütfen geçerli bir TC Kimlik No giriniz.');
                    $('#kullanici_tc_kimlik_no').focus();
                    return false;
                }
                
                submitForm(false);
            });
        });
        
        // Form gönderme fonksiyonu
        function submitForm(forceAdd) {
            // Form verilerini serialize et (FormData yerine)
            let formData = $('#personelForm').serialize() + '&action=kaydet';
            
            // BES checkbox kontrolü
            if (!$('#kullanici_bes_durumu').is(':checked')) {
                formData += '&bes_durumu=0';
            }
            
            // Force parametresi
            if (forceAdd) {
                formData += '&force_add=1';
            }
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Başarılı!', response.message);
                        setTimeout(function() {
                            // Reactivate sonrası düzenleme sayfasına git
                            if (response.redirect_id) {
                                window.location.href = '/admin/personel-form?id=' + response.redirect_id;
                            } else {
                                window.location.href = '/admin/personel-yonetimi';
                            }
                        }, 1500);
                    } else if (response.warning && response.type === 'duplicate_tc_active') {
                        // TC AKTİF personelde kayıtlı - yeni kayıt oluşturulamaz
                        Swal.fire({
                            icon: 'error',
                            title: 'Bu TC Kimlik No Kullanımda!',
                            html: `Girdiğiniz T.C. Kimlik No <strong>${response.existing_name}</strong> isimli <span class="badge bg-success">aktif</span> personele ait.<br><br>Aynı T.C. Kimlik No ile ikinci bir kayıt açılamaz.`,
                            showCancelButton: true,
                            confirmButtonText: '<i class="bi bi-pencil"></i> Mevcut Kaydı Aç',
                            cancelButtonText: '<i class="bi bi-x-circle"></i> İptal',
                            confirmButtonColor: '#0d6efd',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = '/admin/personel-form?id=' + response.existing_id;
                            }
                        });
                    } else if (response.warning && response.type === 'duplicate_tc_inactive') {
                        // TC PASİF personelde kayıtlı - aynı kişi, tekrar aktif edilmeli
                        let tcExitInfo = response.exit_date ? `<br><small class="text-muted">İşten çıkış: ${response.exit_date}</small>` : '';
                        Swal.fire({
                            icon: 'info',
                            title: 'Bu Kişi Daha Önce Kayıtlı!',
                            html: `Girdiğiniz T.C. Kimlik No <strong>${response.existing_name}</strong> isimli <span class="badge bg-secondary">pasif</span> personele ait.${tcExitInfo}<br><br>Aynı T.C. Kimlik No ile yeni kayıt açılamaz; mevcut kaydı tekrar aktif etmelisiniz.`,
                            showCancelButton: true,
                            showDenyButton: true,
                            confirmButtonText: '<i class="bi bi-arrow-repeat"></i> Tekrar Aktif Et',
                            denyButtonText: '<i class="bi bi-pencil"></i> Mevcut Kaydı Aç',
                            cancelButtonText: '<i class="bi bi-x-circle"></i> İptal',
                            confirmButtonColor: '#198754',
                            denyButtonColor: '#0d6efd',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                reactivatePersonel(response.existing_id);
                            } else if (result.isDenied) {
                                window.location.href = '/admin/personel-form?id=' + response.existing_id;
                            }
                        });
                    } else if (response.warning && response.type === 'duplicate_tc_update') {
                        // Güncellemede TC başka bir kayıtta
                        const tcDurumBadge = response.existing_active
                            ? '<span class="badge bg-success">aktif</span>'
                            : '<span class="badge bg-secondary">pasif</span>';
                        Swal.fire({
                            icon: 'error',
                            title: 'Bu TC Kimlik No Başkasına Ait!',
                            html: `Girdiğiniz T.C. Kimlik No <strong>${response.existing_name}</strong> isimli ${tcDurumBadge} personelde kayıtlı.<br><br>Kayıt güncellenmedi. Doğru T.C. Kimlik No&#39;yu giriniz veya diğer kaydı düzeltiniz.`,
                            showCancelButton: true,
                            confirmButtonText: '<i class="bi bi-pencil"></i> Diğer Kaydı Aç',
                            cancelButtonText: '<i class="bi bi-x-circle"></i> Kapat',
                            confirmButtonColor: '#0d6efd',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = '/admin/personel-form?id=' + response.existing_id;
                            }
                        });
                    } else if (response.warning && response.type === 'duplicate_name_active') {
                        // AKTİF personel çakışması
                        Swal.fire({
                            icon: 'warning',
                            title: 'Aktif Personel Mevcut!',
                            html: `<strong>${response.existing_name}</strong> isimli <span class="badge bg-success">aktif</span> bir personel zaten kayıtlı.<br><br>Ne yapmak istersiniz?`,
                            showCancelButton: true,
                            showDenyButton: true,
                            confirmButtonText: '<i class="bi bi-person-plus"></i> Yeni Kayıt Olarak Ekle',
                            denyButtonText: '<i class="bi bi-pencil"></i> Mevcut Kaydı Aç',
                            cancelButtonText: '<i class="bi bi-x-circle"></i> İptal',
                            confirmButtonColor: '#198754',
                            denyButtonColor: '#0d6efd',
                            cancelButtonColor: '#6c757d',
                            reverseButtons: false
                        }).then((result) => {
                            if (result.isConfirmed) {
                                submitForm(true);
                            } else if (result.isDenied) {
                                window.location.href = '/admin/personel-form?id=' + response.existing_id;
                            }
                        });
                    } else if (response.warning && response.type === 'duplicate_name_inactive') {
                        // PASİF personel çakışması - eski çalışan tekrar işe giriyor olabilir
                        let exitInfo = response.exit_date ? `<br><small class="text-muted">İşten çıkış: ${response.exit_date}</small>` : '';
                        Swal.fire({
                            icon: 'info',
                            title: 'Eski Çalışan Bulundu!',
                            html: `<strong>${response.existing_name}</strong> isimli <span class="badge bg-secondary">pasif</span> bir personel mevcut.${exitInfo}<br><br>Bu kişi daha önce çalışmış olabilir. Ne yapmak istersiniz?`,
                            showCancelButton: true,
                            showDenyButton: true,
                            confirmButtonText: '<i class="bi bi-arrow-repeat"></i> Tekrar Aktif Et',
                            denyButtonText: '<i class="bi bi-person-plus"></i> Yeni Kayıt Oluştur',
                            cancelButtonText: '<i class="bi bi-x-circle"></i> İptal',
                            confirmButtonColor: '#198754',
                            denyButtonColor: '#0d6efd',
                            cancelButtonColor: '#6c757d',
                            reverseButtons: false
                        }).then((result) => {
                            if (result.isConfirmed) {
                                // Mevcut pasif kaydı aktif et
                                reactivatePersonel(response.existing_id);
                            } else if (result.isDenied) {
                                // Yeni kayıt oluştur
                                submitForm(true);
                            }
                        });
                    } else {
                        showError('Hata!', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    console.error('Response:', xhr.responseText);
                    showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                }
            });
        }
        
        // Pasif personeli tekrar aktif et
        function reactivatePersonel(personelId) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: { action: 'reactivate', id: personelId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Başarılı!', response.message);
                        setTimeout(function() {
                            // Aktif edilen personelin düzenleme sayfasına git
                            window.location.href = '/admin/personel-form?id=' + response.redirect_id;
                        }, 1500);
                    } else {
                        showError('Hata!', response.message);
                    }
                },
                error: function() {
                    showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                }
            });
        }
    </script>
<!-- Personel formu: telefon normalizasyonu ve otomatik kurumsal e-posta -->
<script>
(function ($) {
    'use strict';

    var ALAN_ADI = 'ornekfirma.com.tr';

    // Turkce karakterleri sadelestirip e-posta parcasi uretir
    function epostaSadelestir(metin) {
        var tr = { 'ç':'c','Ç':'c','ğ':'g','Ğ':'g','ı':'i','I':'i','İ':'i','ö':'o','Ö':'o','ş':'s','Ş':'s','ü':'u','Ü':'u' };
        return (metin || '')
            .replace(/[çÇğĞıIİöÖşŞüÜ]/g, function (c) { return tr[c]; })
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '');
    }

    // Telefonu 90XXXXXXXXXX bicimine cevirir
    function telefonNormalize(deger) {
        var t = (deger || '').replace(/\D/g, '');
        if (!t) return '';
        t = t.replace(/^0+/, '');
        if (t.length > 10 && t.indexOf('90') === 0) t = t.substring(2);
        if (t.length > 10) t = t.slice(-10);
        return '90' + t;
    }

    $(function () {
        var $form = $('#personelForm');
        if (!$form.length) return;

        var $email  = $form.find('[name="email"]');
        var $tel    = $form.find('[name="telefon"]');
        var $ad     = $form.find('[name="ad"]');
        var $soyad  = $form.find('[name="soyad"]');

        function epostaUret() {
            var ad = epostaSadelestir($ad.val());
            var soyad = epostaSadelestir($soyad.val());
            if (!ad && !soyad) return '';
            if (!soyad) return ad + '@' + ALAN_ADI;
            if (!ad)    return soyad + '@' + ALAN_ADI;
            return ad + '.' + soyad + '@' + ALAN_ADI;
        }

        // --- Otomatik e-posta: ad/soyad yazildikca doldurur, elle mudahalede durur
        if ($email.length && $ad.length && $soyad.length) {
            var epostaManuel = $.trim($email.val()) !== '';

            $email.on('input', function () {
                var deger = $.trim($(this).val());
                epostaManuel = deger !== '' && deger !== epostaUret();
            });

            $ad.add($soyad).on('input', function () {
                if (!epostaManuel) $email.val(epostaUret());
            });
        }

        // --- Telefon: alandan cikinca 90XXXXXXXXXX gosterilir
        // Maskeli alanlarda maske bozulmasin diye atlanir; kayit sirasinda PHP tarafi normalize eder.
        if ($tel.length && !$tel.hasClass('telefon-mask')) {
            $tel.on('blur', function () {
                var n = telefonNormalize($(this).val());
                if (n) $(this).val(n);
            });
        }
    });
})(jQuery);
</script>
</body>
</html>
