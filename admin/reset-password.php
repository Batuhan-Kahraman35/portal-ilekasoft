<?php
/**
 * Şifre Sıfırlama Sayfası
 * Portal Örnek Soft
 */

require_once __DIR__ . '/db.php';

// Türkiye timezone'u
date_default_timezone_set('Europe/Istanbul');

session_start();

$db = Database::getInstance();

// Site ayarlarını veritabanından çek
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

$error = '';
$success = '';
$tokenValid = false;
$token = $_GET['token'] ?? '';

// Token kontrolü
if (empty($token)) {
    $error = 'Geçersiz şifre sıfırlama linki!';
} else {
    // Token'ı veritabanında ara
    $user = $db->fetchOne(
        "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_sifre_token_tarih
         FROM kullanicilar 
         WHERE kullanici_sifre_token = ? AND kullanici_durum = 1",
        [$token]
    );
    
    if (!$user) {
        $error = 'Geçersiz veya kullanılmış şifre sıfırlama linki!';
    } else {
        // Token süresini kontrol et
        $tokenExpiry = $user['kullanici_sifre_token_tarih'];
        
        if ($tokenExpiry instanceof DateTime) {
            $expiryTime = $tokenExpiry->getTimestamp();
        } else {
            $expiryTime = strtotime($tokenExpiry);
        }
        
        if ($expiryTime < time()) {
            $error = 'Şifre sıfırlama linkinin süresi dolmuş. Lütfen yeni bir talep oluşturun.';
        } else {
            $tokenValid = true;
        }
    }
}

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    if (empty($password) || empty($passwordConfirm)) {
        $error = 'Lütfen tüm alanları doldurun!';
    } elseif (strlen($password) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır!';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Şifreler eşleşmiyor!';
    } else {
        // Şifreyi güncelle
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        
        $result = $db->execute(
            "UPDATE kullanicilar 
             SET kullanici_sifre_hash = ?, 
                 kullanici_sifre_token = NULL, 
                 kullanici_sifre_token_tarih = NULL 
             WHERE kullanici_id = ?",
            [$hashedPassword, $user['kullanici_id']]
        );
        
        if ($result) {
            $success = 'Şifreniz başarıyla güncellendi! Şimdi giriş yapabilirsiniz.';
            $tokenValid = false; // Formu gizle
        } else {
            $error = 'Şifre güncellenirken bir hata oluştu. Lütfen tekrar deneyin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifre Sıfırla - <?= $siteTitle ?></title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    
    <!-- AdminLTE -->
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="login.php"><b><?= $siteTitle ?></b></a>
        </div>
        
        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Yeni şifrenizi belirleyin</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if ($tokenValid): ?>
                    <form method="POST" action="">
                        <div class="input-group mb-3">
                            <input 
                                type="password" 
                                class="form-control" 
                                name="password"
                                placeholder="Yeni şifre" 
                                required
                                minlength="6"
                                autofocus
                            >
                            <div class="input-group-text">
                                <span class="bi bi-lock"></span>
                            </div>
                        </div>
                        
                        <div class="input-group mb-3">
                            <input 
                                type="password" 
                                class="form-control" 
                                name="password_confirm"
                                placeholder="Yeni şifre (tekrar)" 
                                required
                                minlength="6"
                            >
                            <div class="input-group-text">
                                <span class="bi bi-lock-fill"></span>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-12">
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-lg"></i> Şifreyi Güncelle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php elseif (!$success): ?>
                    <div class="d-grid gap-2 mt-3">
                        <a href="forgot-password.php" class="btn btn-warning">
                            <i class="bi bi-arrow-repeat"></i> Yeni Sıfırlama Talebi Oluştur
                        </a>
                    </div>
                <?php endif; ?>
                
                <p class="mt-3 mb-0 text-center">
                    <a href="login.php" class="text-center">
                        <i class="bi bi-arrow-left"></i> Giriş sayfasına dön
                    </a>
                </p>
                
                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- AdminLTE -->
    <script src="assets/js/adminlte.min.js"></script>
</body>
</html>
