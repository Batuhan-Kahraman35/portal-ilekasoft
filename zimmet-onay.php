<?php
/**
 * Zimmet Onay - Public Sayfa
 *
 * Personel, mesajla gelen linke tiklayip TC kimlik no girerek
 * uzerindeki sirket hattini dogrular. Giris (login) gerektirmez.
 *
 * Guvenlik:
 *  - Erisim yalnizca 32 haneli token ile
 *  - Zimmetin kime ait oldugu ASLA gosterilmez
 *  - Hatali TC denemesi sayilir, limitte kayit kilitlenir
 */

date_default_timezone_set('Europe/Istanbul');
require_once __DIR__ . '/admin/includes/ZimmetOnay.php';

$zimmetOnay = new ZimmetOnay();

$token  = $_GET['t'] ?? '';
$kayit  = $zimmetOnay->tokenIleBul($token);

$sonuc  = null;
$mesaj  = null;
$tip    = 'info';

// Ziyaretci IP'si
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}
$ip = substr($ip, 0, 45);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $kayit) {
    $islem = $_POST['islem'] ?? 'onayla';

    if ($islem === 'kullanmiyorum') {
        $yanit = $zimmetOnay->kullanilmiyorIsaretle($token, $ip);
    } else {
        $yanit = $zimmetOnay->yanitIsle($token, $_POST['tc'] ?? '', $ip);
    }

    $sonuc = $yanit['sonuc'];
    $mesaj = $yanit['mesaj'];

    switch ($sonuc) {
        case 'onaylandi':
            $tip = 'success';
            break;
        case 'farkli_personel':
        case 'kullanilmiyor':
        case 'zaten_yanitlandi':
            $tip = 'warning';
            break;
        default:
            $tip = 'danger';
    }

    // Durum degistiyse guncel kaydi tekrar oku (form gosterilsin mi karari icin)
    $kayit = $zimmetOnay->tokenIleBul($token);
}

// Form gosterilecek mi?
$formGoster = $kayit
    && (int)$kayit['zimmet_onay_kilit'] === 0
    && !in_array($kayit['zimmet_onay_durum_kodu'], ['ONAYLANDI', 'FARKLI_PERSONEL', 'KULLANILMIYOR'], true);

$hatMaskeli = null;
if ($kayit) {
    // 5xx *** ** 85 seklinde maskele - dogrulama oncesi tam numara gosterilmez
    $h = $kayit['zimmet_onay_hat_no'];
    $hatMaskeli = substr($h, 0, 3) . ' *** ** ' . substr($h, -2);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Zimmet Onay</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <style>
        body {
            background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        .kart {
            width: 100%;
            max-width: 440px;
            border: none;
            border-radius: 18px;
            box-shadow: 0 12px 40px rgba(0,0,0,.25);
        }
        .kart-baslik {
            border-radius: 18px 18px 0 0;
            background: #fff;
            text-align: center;
            padding: 28px 20px 12px;
        }
        .ikon-daire {
            width: 64px; height: 64px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin-bottom: 12px;
        }
        #tc { letter-spacing: 4px; font-size: 1.25rem; text-align: center; }
    </style>
</head>
<body>

<div class="card kart">
    <?php if (!$kayit): ?>
        <!-- Gecersiz token -->
        <div class="kart-baslik">
            <div class="ikon-daire bg-danger-subtle text-danger"><i class="bi bi-x-lg"></i></div>
            <h5 class="mb-1">Bağlantı Geçersiz</h5>
        </div>
        <div class="card-body text-center pt-0">
            <p class="text-muted mb-0">
                Bu bağlantı geçersiz veya süresi dolmuş. Lütfen size gönderilen mesajdaki adresi kontrol edin.
            </p>
        </div>

    <?php else: ?>
        <div class="kart-baslik">
            <div class="ikon-daire bg-primary-subtle text-primary"><i class="bi bi-sim"></i></div>
            <h5 class="mb-1">Zimmet Onayı</h5>
            <div class="text-muted small">
                Hat: <strong><?= htmlspecialchars($hatMaskeli) ?></strong> &middot;
                Dönem: <?= htmlspecialchars($kayit['zimmet_onay_donem']) ?>
            </div>
        </div>

        <div class="card-body pt-2">

            <?php if ($mesaj): ?>
                <div class="alert alert-<?= $tip ?> d-flex align-items-start" role="alert">
                    <i class="bi bi-info-circle-fill me-2 mt-1"></i>
                    <div><?= htmlspecialchars($mesaj) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($formGoster): ?>
                <p class="text-muted small">
                    Bu hatta tanımlı zimmeti onaylamak için <strong>T.C. Kimlik Numaranızı</strong> giriniz.
                </p>

                <form method="post" id="onayForm" autocomplete="off">
                    <input type="hidden" name="islem" value="onayla">
                    <div class="mb-3">
                        <label for="tc" class="form-label">T.C. Kimlik No</label>
                        <input type="text" class="form-control form-control-lg" id="tc" name="tc"
                               inputmode="numeric" pattern="[0-9]{11}" maxlength="11" required
                               placeholder="___________">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-check2-circle"></i> Zimmetimi Onayla
                    </button>
                </form>

                <hr class="my-3">

                <form method="post" id="kullanmiyorumForm">
                    <input type="hidden" name="islem" value="kullanmiyorum">
                    <button type="submit" class="btn btn-outline-secondary w-100 btn-sm">
                        Bu hattı kullanmıyorum
                    </button>
                </form>

            <?php elseif ((int)$kayit['zimmet_onay_kilit'] === 1): ?>
                <div class="text-center">
                    <div class="ikon-daire bg-danger-subtle text-danger"><i class="bi bi-lock"></i></div>
                    <p class="text-muted mb-0">
                        Çok fazla hatalı deneme yapıldığı için bu bağlantı kilitlendi.
                        Lütfen İnsan Kaynakları ile iletişime geçin.
                    </p>
                </div>

            <?php elseif (!$mesaj): ?>
                <div class="text-center">
                    <div class="ikon-daire bg-success-subtle text-success"><i class="bi bi-check-lg"></i></div>
                    <p class="text-muted mb-0">
                        Bu hat için yanıtınız <?= htmlspecialchars($kayit['yanit_tarihi'] ?? '') ?> tarihinde alınmıştır.
                        Tekrar işlem yapmanıza gerek yoktur.
                    </p>
                </div>
            <?php endif; ?>

        </div>
        <div class="card-footer bg-white text-center border-0 pb-3" style="border-radius: 0 0 18px 18px;">
            <small class="text-muted">Bilgileriniz yalnızca zimmet doğrulaması için kullanılır.</small>
        </div>
    <?php endif; ?>
</div>

<script>
    // Sadece rakam girisi
    var tcInput = document.getElementById('tc');
    if (tcInput) {
        tcInput.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
        });
        tcInput.focus();
    }

    // Cift gonderim engeli
    document.querySelectorAll('form').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button[type=submit]');
            if (b) { b.disabled = true; b.innerHTML = 'Gönderiliyor...'; }
        });
    });
</script>

</body>
</html>
