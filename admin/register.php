<?php
/**
 * Admin Panel - Kayıt Sayfası
 * Portal Örnek Soft
 */

require_once __DIR__ . '/auth.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (Auth::check()) {
    redirect('index.php');
}

// Site ayarlarını veritabanından çek
$db = Database::getInstance();
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title 
    FROM dbo.tanim_site_ayarlari 
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = '';
$siteTitle = 'Örnek Soft Portal';

if ($siteAyarlari) {
    if (!empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
        $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
    } else {
        $footerYazi = '© ' . date('Y') . ' Örnek Soft. Tüm hakları saklıdır.';
    }
    
    if (!empty($siteAyarlari['site_ayarlari_site_title'])) {
        $siteTitle = htmlspecialchars($siteAyarlari['site_ayarlari_site_title']);
    }
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Soft. Tüm hakları saklıdır.';
}

// Şehirleri çek
$sehirler = $db->fetchAll("
    SELECT SehirId, SehirAdi 
    FROM Sehirler 
    ORDER BY SehirAdi
");

$error = '';
$success = '';

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad = trim($_POST['ad'] ?? '');
    $soyad = trim($_POST['soyad'] ?? '');
    $dogumTarihi = $_POST['dogum_tarihi'] ?? '';
    $telefon = trim($_POST['telefon'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $sehirId = $_POST['sehir_id'] ?? '';
    $sifre = $_POST['sifre'] ?? '';
    $sifreTekrar = $_POST['sifre_tekrar'] ?? '';
    
    // Validasyon
    if (empty($ad) || empty($soyad) || empty($email) || empty($sifre) || empty($sifreTekrar)) {
        $error = 'Lütfen tüm zorunlu alanları doldurun!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz e-posta adresi!';
    } elseif ($sifre !== $sifreTekrar) {
        $error = 'Şifreler eşleşmiyor!';
    } elseif (strlen($sifre) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır!';
    } else {
        // Email benzersizlik kontrolü
        $existingUser = $db->fetchOne("
            SELECT kullanici_id 
            FROM kullanicilar 
            WHERE kullanici_email = ?
        ", [$email]);
        
        if ($existingUser) {
            $error = 'Bu e-posta adresi zaten kullanılmaktadır!';
        } else {
            // Kayıt oluştur
            try {
                $sifreHash = password_hash($sifre, PASSWORD_DEFAULT);
                
                $result = $db->insert('kullanicilar', [
                    'kullanici_email' => $email,
                    'kullanici_sifre_hash' => $sifreHash,
                    'kullanici_ad' => $ad,
                    'kullanici_soyad' => $soyad,
                    'kullanici_telefon' => $telefon ?: null,
                    'kullanici_dogum_tarihi' => $dogumTarihi ?: null,
                    'kullanici_sehir_id' => $sehirId ?: null,
                    'kullanici_durum' => null,
                    'kullanici_olusturma_tarihi' => date('Y-m-d H:i:s'),
                    'kullanici_bes_durumu' => 0
                ]);
                
                if ($result) {
                    // IT Talebi oluştur - Yeni üyelik başvurusu
                    try {
                        // Yeni talep no oluştur
                        $talep_no = $db->fetchOne("SELECT dbo.fn_IT_YeniTalepNo() as talep_no")['talep_no'];
                        
                        // SLA hesapla (aciliyet_id=1 için)
                        $aciliyetDetay = $db->fetchOne("SELECT aciliyet_sla_saat FROM IT_Talep_Aciliyetler WHERE aciliyet_id = 1");
                        $sla_saat = $aciliyetDetay['aciliyet_sla_saat'] ?? 24;
                        
                        // Kategori varsayılan grup ataması (kategori_id=4)
                        $kategoriDetay = $db->fetchOne("SELECT kategori_varsayilan_grup_id FROM IT_Talep_Kategoriler WHERE kategori_id = 4");
                        $atanan_grup_id = $kategoriDetay['kategori_varsayilan_grup_id'] ?? null;
                        
                        // Şehir adını al
                        $sehirAdi = '';
                        if ($sehirId) {
                            $sehir = $db->fetchOne("SELECT SehirAdi FROM Sehirler WHERE SehirId = ?", [$sehirId]);
                            $sehirAdi = $sehir['SehirAdi'] ?? '';
                        }
                        
                        // Talep başlık ve açıklama
                        $talepBaslik = 'Yeni Üyelik Başvurusu - ' . $ad . ' ' . $soyad;
                        $talepAciklama = "Yeni üyelik başvurusu formu dolduruldu.\n\n" .
                            "Ad Soyad: {$ad} {$soyad}\n" .
                            "E-posta: {$email}\n" .
                            "Telefon: " . ($telefon ?: '-') . "\n" .
                            "Doğum Tarihi: " . ($dogumTarihi ?: '-') . "\n" .
                            "Şehir: " . ($sehirAdi ?: '-') . "\n\n" .
                            "Başvuru onay bekliyor.";
                        
                        // IT Talebi kaydet
                        $sql = "INSERT INTO IT_Talepler (
                            talep_no, talep_baslik, talep_aciklama,
                            talep_kaynak_tipi, talep_kullanici_id, talep_cari_id,
                            talep_dis_ad, talep_dis_email, talep_dis_telefon,
                            talep_kanal_id, talep_kategori_id, talep_aciliyet_id,
                            talep_durum_id, talep_link, talep_atanan_grup_id,
                            talep_sla_bitis, talep_olusturan_id
                        ) VALUES (
                            ?, ?, ?,
                            3, NULL, NULL,
                            ?, ?, ?,
                            3, 4, 1,
                            1, NULL, ?,
                            DATEADD(HOUR, ?, GETDATE()), 1
                        )";
                        
                        $db->execute($sql, [
                            $talep_no, $talepBaslik, $talepAciklama,
                            $ad . ' ' . $soyad, $email, $telefon ?: null,
                            $atanan_grup_id,
                            $sla_saat
                        ]);
                        
                    } catch (Exception $talepEx) {
                        // IT talebi oluşturulamazsa sadece log'a yaz, kullanıcıya hata gösterme
                        error_log("Üyelik IT Talebi oluşturma hatası: " . $talepEx->getMessage());
                    }
                    
                    $success = 'Başvurunuz alındı! Yönetici onayından sonra giriş yapabileceksiniz.';
                    // Formu temizle
                    $_POST = [];
                } else {
                    $error = 'Kayıt oluşturulurken bir hata oluştu!';
                }
            } catch (Exception $e) {
                $error = 'Bir hata oluştu: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt Ol - <?= $siteTitle ?></title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- AdminLTE -->
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
    
    <style>
        .register-box {
            width: 450px;
        }
        @media (max-width: 576px) {
            .register-box {
                width: 90%;
            }
        }
    </style>
</head>
<body class="register-page bg-body-secondary">
    <div class="register-box">
        <div class="register-logo">
            <a href="login.php"><b><?= $siteTitle ?></b></a>
        </div>
        
        <div class="card">
            <div class="card-body register-card-body">
                <p class="login-box-msg">Yeni Üyelik Başvurusu</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                        </a>
                    </div>
                <?php else: ?>
                
                <form method="POST" action="" id="registerForm">
                    <div class="row">
                        <!-- Ad -->
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    name="ad"
                                    placeholder="Ad *" 
                                    required
                                    value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>"
                                >
                                <div class="input-group-text">
                                    <span class="bi bi-person"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Soyad -->
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input 
                                    type="text" 
                                    class="form-control" 
                                    name="soyad"
                                    placeholder="Soyad *" 
                                    required
                                    value="<?= htmlspecialchars($_POST['soyad'] ?? '') ?>"
                                >
                                <div class="input-group-text">
                                    <span class="bi bi-person-fill"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Email -->
                    <div class="input-group mb-3">
                        <input 
                            type="email" 
                            class="form-control" 
                            name="email"
                            placeholder="E-posta *" 
                            required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope"></span>
                        </div>
                    </div>
                    
                    <!-- Telefon -->
                    <div class="input-group mb-3">
                        <input 
                            type="tel" 
                            class="form-control" 
                            name="telefon"
                            placeholder="Telefon (5XX XXX XX XX)"
                            pattern="[0-9]{10}"
                            maxlength="10"
                            value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-telephone"></span>
                        </div>
                    </div>
                    
                    <!-- Doğum Tarihi -->
                    <div class="input-group mb-3">
                        <input 
                            type="date" 
                            class="form-control" 
                            name="dogum_tarihi"
                            placeholder="Doğum Tarihi"
                            value="<?= htmlspecialchars($_POST['dogum_tarihi'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-calendar"></span>
                        </div>
                    </div>
                    
                    <!-- Şehir -->
                    <div class="input-group mb-3">
                        <select class="form-select" name="sehir_id" id="sehir_id">
                            <option value="">Şehir Seçiniz</option>
                            <?php foreach ($sehirler as $sehir): ?>
                                <option value="<?= $sehir['SehirId'] ?>" <?= (($_POST['sehir_id'] ?? '') == $sehir['SehirId']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sehir['SehirAdi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-group-text">
                            <span class="bi bi-geo-alt"></span>
                        </div>
                    </div>
                    
                    <!-- Şifre -->
                    <div class="input-group mb-3">
                        <input 
                            type="password" 
                            class="form-control" 
                            name="sifre"
                            placeholder="Şifre (En az 6 karakter) *" 
                            required
                            minlength="6"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-lock-fill"></span>
                        </div>
                    </div>
                    
                    <!-- Şifre Tekrar -->
                    <div class="input-group mb-3">
                        <input 
                            type="password" 
                            class="form-control" 
                            name="sifre_tekrar"
                            placeholder="Şifre Tekrar *" 
                            required
                            minlength="6"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-lock"></span>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-person-plus"></i> Başvuru Yap
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                
                <p class="mt-3 mb-0 text-center">
                    <a href="login.php" class="text-center">
                        <i class="bi bi-box-arrow-in-right"></i> Zaten hesabım var
                    </a>
                </p>
                
                <?php endif; ?>
                
                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- AdminLTE -->
    <script src="assets/js/adminlte.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Select2 başlat
            $('#sehir_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Şehir Seçiniz',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Şifre eşleşme kontrolü
            $('form').on('submit', function(e) {
                const sifre = $('[name="sifre"]').val();
                const sifreTekrar = $('[name="sifre_tekrar"]').val();
                
                if (sifre !== sifreTekrar) {
                    e.preventDefault();
                    alert('Şifreler eşleşmiyor!');
                    return false;
                }
            });
        });
    </script>
</body>
</html>
