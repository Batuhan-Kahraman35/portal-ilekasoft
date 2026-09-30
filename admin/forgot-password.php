<?php
/**
 * Şifremi Unuttum Sayfası
 * Portal Örnek Soft
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/Mailer.php';

// Türkiye timezone'u
date_default_timezone_set('Europe/Istanbul');

session_start();

$db = Database::getInstance();

// Site ayarlarını veritabanından çek
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title, site_ayarlari_site_url
    FROM dbo.tanim_site_ayarlari 
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = '';
$siteTitle = 'Örnek Soft Portal';
$siteUrl = 'https://portal.ornekfirma.com';

if ($siteAyarlari) {
    if (!empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
        $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
    } else {
        $footerYazi = '© ' . date('Y') . ' Örnek Soft. Tüm hakları saklıdır.';
    }
    
    if (!empty($siteAyarlari['site_ayarlari_site_title'])) {
        $siteTitle = htmlspecialchars($siteAyarlari['site_ayarlari_site_title']);
    }
    
    if (!empty($siteAyarlari['site_ayarlari_site_url'])) {
        $siteUrl = rtrim($siteAyarlari['site_ayarlari_site_url'], '/');
    }
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Soft. Tüm hakları saklıdır.';
}

$error = '';
$success = '';

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Lütfen e-posta adresinizi girin!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir e-posta adresi girin!';
    } else {
        // Kullanıcıyı bul
        $user = $db->fetchOne(
            "SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email 
             FROM kullanicilar 
             WHERE kullanici_email = ? AND kullanici_durum = 1",
            [$email]
        );
        
        if ($user) {
            // Token oluştur (64 karakter)
            $token = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Token'ı veritabanına kaydet
            $db->execute(
                "UPDATE kullanicilar 
                 SET kullanici_sifre_token = ?, kullanici_sifre_token_tarih = ? 
                 WHERE kullanici_id = ?",
                [$token, $tokenExpiry, $user['kullanici_id']]
            );
            
            // Şifre sıfırlama linki (site URL'den /admin varsa kaldır)
            $baseUrl = preg_replace('#/admin/?$#', '', $siteUrl);
            $resetLink = $baseUrl . '/admin/reset-password.php?token=' . $token;
            
            // E-posta içeriği
            $emailBody = '
            <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background: #0d6efd; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;">
                    <h1 style="margin: 0;">' . $siteTitle . '</h1>
                </div>
                <div style="background: #f8f9fa; padding: 30px; border: 1px solid #dee2e6;">
                    <h2 style="color: #333; margin-top: 0;">Şifre Sıfırlama Talebi</h2>
                    <p>Merhaba <strong>' . htmlspecialchars($user['kullanici_ad'] . ' ' . $user['kullanici_soyad']) . '</strong>,</p>
                    <p>Hesabınız için şifre sıfırlama talebinde bulundunuz. Aşağıdaki butona tıklayarak yeni şifrenizi belirleyebilirsiniz:</p>
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="' . $resetLink . '" style="background: #0d6efd; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Şifremi Sıfırla</a>
                    </div>
                    <p style="color: #666; font-size: 14px;">Bu link <strong>1 saat</strong> geçerlidir. Eğer bu talebi siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz.</p>
                    <hr style="border: none; border-top: 1px solid #dee2e6; margin: 20px 0;">
                    <p style="color: #999; font-size: 12px;">Link çalışmıyorsa aşağıdaki adresi tarayıcınıza kopyalayın:<br><a href="' . $resetLink . '" style="color: #0d6efd; word-break: break-all;">' . $resetLink . '</a></p>
                </div>
                <div style="background: #343a40; color: #adb5bd; padding: 15px; text-align: center; border-radius: 0 0 10px 10px; font-size: 12px;">
                    ' . $footerYazi . '
                </div>
            </div>';
            
            // E-posta gönder
            try {
                $mailer = new Mailer();
                $result = $mailer->send(
                    $user['kullanici_email'],
                    'Şifre Sıfırlama - ' . $siteTitle,
                    $emailBody
                );
                
                if ($result['success']) {
                    $success = 'Şifre sıfırlama linki e-posta adresinize gönderildi. Lütfen gelen kutunuzu kontrol edin.';
                } else {
                    $error = 'E-posta gönderilirken bir hata oluştu. Lütfen daha sonra tekrar deneyin.';
                    error_log('Şifre sıfırlama e-posta hatası: ' . $result['message']);
                }
            } catch (Exception $e) {
                $error = 'E-posta gönderilirken bir hata oluştu. Lütfen daha sonra tekrar deneyin.';
                error_log('Şifre sıfırlama exception: ' . $e->getMessage());
            }
        } else {
            // Kullanıcı bulunamadı
            $error = 'Bu e-posta adresi sistemde kayıtlı değil!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum - <?= $siteTitle ?></title>
    
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
                <p class="login-box-msg">Şifrenizi sıfırlamak için e-posta adresinizi girin</p>
                
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
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="input-group mb-3">
                        <input 
                            type="email" 
                            class="form-control" 
                            name="email"
                            placeholder="E-posta adresiniz" 
                            required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            autofocus
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope"></span>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-send"></i> Sıfırlama Linki Gönder
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                
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
