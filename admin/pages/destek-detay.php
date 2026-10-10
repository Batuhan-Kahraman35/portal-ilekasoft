<?php
/**
 * Destek Talebi Detay / Yeni Talep
 * Kaynak: Portal
 * Bu dosya otomatik oluşturulmuştur.
 *
 * Kullanım:
 *   destek-detay.php?id=5    → Ticket detayı + mesajlar + yanıt formu
 *   destek-detay.php?yeni=1  → Yeni ticket oluşturma formu
 *
 * ═══════════════════════════════════════════════════════════════
 * API RESPONSE KEY REFERANSLARI
 * ═══════════════════════════════════════════════════════════════
 *
 * ticket_meta → data.kategoriler[] → { id, ad }
 *             → data.oncelikler[]  → { id, ad }
 *             → data.durumlar[]    → { id, ad, renk, ikon }
 *
 * ticket_detail → data:
 *   Tickets_id, Tickets_no, Tickets_konu
 *   durum_ad, durum_renk, durum_ikon
 *   oncelik_ad, oncelik_renk
 *   kategori_ad, kategori_renk
 *   acilis_tarihi, son_yanit_tarihi
 *   Ticket_Durumlar_kapatma_durumu  → 1 ise ticket kapalı
 *   atanan_ad           → Atanan kişi adı (null = henüz atanmadı)
 *   atanan_soyad        → Atanan kişi soyadı
 *   mesajlar[] → (aşağıya bakınız)
 *
 * mesajlar[] → her mesaj objesi:
 *   mesaj_id           → Mesaj ID
 *   mesaj               → Mesaj içeriği (text)
 *   mesaj_kaynak         → 'api' = kullanıcı (sağ/mavi), 'panel' = admin (sol/yeşil)
 *   yazan_ad, yazan_soyad → Yazan kişi adı
 *   tarih                → Mesaj tarihi (YYYY-MM-DD HH:MM)
 *   ekler[]              → Dosya ekleri (aşağıya bakınız)
 *
 * ekler[] → her ek objesi:
 *   dosya_adi   → Orijinal dosya adı
 *   dosya_url   → İndirme/görüntüleme linki (tam URL)
 *   dosya_boyutu → Byte cinsinden boyut
 *   mime_turu    → MIME tipi
 *
 * create_ticket → gönderilen payload:
 *   action, konu, mesaj, kategori_id, oncelik_id
 *   kullanici: { ad, soyad, eposta, telefon }
 *   ekler[]: { dosya_adi, mime_turu, icerik_base64 }
 *
 * reply_ticket → gönderilen payload:
 *   action, ticket_id, mesaj, eposta
 *   ekler[]: { dosya_adi, mime_turu, icerik_base64 }
 *
 * list_tickets → data[] her ticket:
 *   Tickets_id, Tickets_no, Tickets_konu
 *   durum_ad, durum_renk, oncelik_ad, oncelik_renk
 *   acilis_tarihi, son_yanit_tarihi, mesaj_sayisi
 *   atanan_ad, atanan_soyad
 * ═══════════════════════════════════════════════════════════════
 */
session_start();

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketApi.php';
requireAuth();
$user = Auth::user();
$db = Database::getInstance();

$eposta     = $user['email'] ?? null;
$kulAd      = $user['name'] ?? null;
$kulSoyad   = '';
$kulTelefon = '';
if (!$eposta) {
    header('Location: ../login.php');
    exit;
}

// API adresi ve anahtari Entegrasyonlar tablosundan gelir; koda gomulmez.
$api = new TicketApi();

/**
 * TicketApi uzerinden istek gonderir. Entegrasyon tanimli degilse
 * cagri yapilmadan ayni bicimde hata dizisi doner.
 */
function apiCall(array $payload): array {
    global $api;

    if (!$api->hazirMi()) {
        return ['success' => false, 'message' => $api->getHata()];
    }

    return $api->call($payload);
}

function dosyalariBase64Yap() {
    $ekler = [];
    if (!empty($_FILES['dosyalar']) && is_array($_FILES['dosyalar']['name'])) {
        $count = count($_FILES['dosyalar']['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($_FILES['dosyalar']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $tmpName = $_FILES['dosyalar']['tmp_name'][$i];
            $dosyaAdi = $_FILES['dosyalar']['name'][$i];
            $mime = $_FILES['dosyalar']['type'][$i] ?: 'application/octet-stream';
            $icerik = file_get_contents($tmpName);
            if ($icerik === false) continue;
            $ekler[] = [
                'dosya_adi'      => $dosyaAdi,
                'mime_turu'      => $mime,
                'icerik_base64'  => base64_encode($icerik),
            ];
        }
    }
    return $ekler;
}

// Düz metni güvenli HTML'e çevirir: önce kaçış, sonra URL'leri link yapar, satır sonlarını korur
function metinHtml($metin) {
    $html = htmlspecialchars((string)$metin, ENT_QUOTES, 'UTF-8');
    $html = preg_replace_callback('~\b((?:https?://|www\.)[^\s<]+)~iu', function ($m) {
        $url = $m[1];
        $kuyruk = '';
        // Cümle sonundaki noktalama linke dahil edilmez
        if (preg_match('~[.,;:!?)\]]+$~', $url, $s)) {
            $kuyruk = $s[0];
            $url = substr($url, 0, -strlen($kuyruk));
        }
        $href = preg_match('~^www\.~i', $url) ? 'https://' . $url : $url;
        return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $kuyruk;
    }, $html);
    return nl2br($html);
}

// Mükerrer gönderim koruması: form anahtarı yalnız bir kez kullanılabilir.
// PHP session kilidi aynı oturumdaki eşzamanlı istekleri sıraya sokar;
// ikinci istek, ilki bittikten sonra anahtarı dolu bulur.
function formAnahtarKaydi($anahtar) {
    return $_SESSION['destek_form_anahtarlar'][$anahtar] ?? null;
}
function formAnahtarKaydet($anahtar, $yonlendirme) {
    $liste = $_SESSION['destek_form_anahtarlar'] ?? [];
    $liste[$anahtar] = $yonlendirme;
    $_SESSION['destek_form_anahtarlar'] = array_slice($liste, -20, null, true);
}

// Meta bilgilerini her zaman çek
$metaResult = apiCall(['action' => 'ticket_meta']);
$meta = ($metaResult['success'] ?? false) ? ($metaResult['data'] ?? []) : [];
$kategoriler = $meta['kategoriler'] ?? [];
$oncelikler  = $meta['oncelikler'] ?? [];

$ticketId = (int)($_GET['id'] ?? 0);
$yeniMi   = isset($_GET['yeni']);
$ticket   = null;
$mesajlar = [];
$hata     = '';
$basarili = '';

// ─── POST İşlemleri ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction  = $_POST['post_action'] ?? '';
    $formAnahtar = (string)($_POST['form_anahtar'] ?? '');

    if (in_array($postAction, ['create_ticket', 'reply_ticket'], true)) {
        if (!preg_match('/^[a-f0-9]{32}$/', $formAnahtar)) {
            $hata = 'Geçersiz form anahtarı. Sayfayı yenileyip tekrar deneyin.';
            $postAction = '';
        } elseif ($oncekiYonlendirme = formAnahtarKaydi($formAnahtar)) {
            // Aynı form ikinci kez gönderildi: yeni kayıt açmadan ilk sonucun sayfasına dön
            header('Location: ' . $oncekiYonlendirme);
            exit;
        }
    }

    if ($postAction === 'create_ticket') {
        $kullanici = [];
        if ($eposta) {
            $kullanici = [
                'ad'     => $kulAd ?? '',
                'soyad'  => $kulSoyad ?? '',
                'eposta' => $eposta,
            ];
            if ($kulTelefon) {
                $kullanici['telefon'] = $kulTelefon;
            }
        }

        $payload = [
            'action'      => 'create_ticket',
            'konu'        => trim($_POST['konu'] ?? ''),
            'mesaj'       => trim($_POST['mesaj'] ?? ''),
            'kategori_id' => (int)($_POST['kategori_id'] ?? 0),
            'oncelik_id'  => (int)($_POST['oncelik_id'] ?? 0),
        ];
        if (!empty($kullanici)) {
            $payload['kullanici'] = $kullanici;
        }
        $ekler = dosyalariBase64Yap();
        if (!empty($ekler)) {
            $payload['ekler'] = $ekler;
        }

        $result = apiCall($payload);
        if ($result['success'] ?? false) {
            $yeniTicketId = (int)($result['data']['ticket_id'] ?? 0);
            formAnahtarKaydet($formAnahtar, '/admin/destek-detay?id=' . $yeniTicketId);
            header('Location: /admin/destek-detay?id=' . $yeniTicketId . '&ok=1');
            exit;
        } else {
            $hata = $result['message'] ?? 'Bir hata oluştu.';
            $yeniMi = true;
        }
    }

    if ($postAction === 'reply_ticket' && $ticketId > 0) {
        $payload = [
            'action'    => 'reply_ticket',
            'ticket_id' => $ticketId,
            'mesaj'     => trim($_POST['mesaj'] ?? ''),
        ];
        if ($eposta) $payload['eposta'] = $eposta;
        $ekler = dosyalariBase64Yap();
        if (!empty($ekler)) {
            $payload['ekler'] = $ekler;
        }

        $result = apiCall($payload);
        if ($result['success'] ?? false) {
            formAnahtarKaydet($formAnahtar, '/admin/destek-detay?id=' . $ticketId);
            header('Location: /admin/destek-detay?id=' . $ticketId . '&ok=2');
            exit;
        } else {
            $hata = $result['message'] ?? 'Yanıt gönderilemedi.';
        }
    }
}

if (isset($_GET['ok'])) {
    $basarili = ($_GET['ok'] == '1') ? 'Talep başarıyla oluşturuldu.' : 'Yanıtınız gönderildi.';
}

// ─── Ticket Detay ───
if ($ticketId > 0 && !$yeniMi) {
    $payload = ['action' => 'ticket_detail', 'ticket_id' => $ticketId];
    if ($eposta) $payload['eposta'] = $eposta;
    $detayResult = apiCall($payload);

    if ($detayResult['success'] ?? false) {
        $ticket   = $detayResult['data'] ?? [];
        $mesajlar = $ticket['mesajlar'] ?? [];
    } else {
        $hata = $detayResult['message'] ?? 'Ticket bulunamadı.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $yeniMi ? 'Yeni Talep' : ('Talep #' . $ticketId) ?> - Portal</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <style>
        .mesaj-listesi { min-height: 300px; max-height: 500px; overflow-y: auto; }
        .mesaj-kutu {
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
            padding: .75rem 1rem;
            margin-bottom: .75rem;
        }
        .mesaj-kutu:last-child { margin-bottom: 0; }
        .mesaj-metni { overflow-wrap: anywhere; }
        .mesaj-metni a { text-decoration: underline; }
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
                            <h3 class="mb-0"><?= $yeniMi ? 'Yeni Destek Talebi' : 'Talep Detayı' ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="/admin/dashboard">Ana Sayfa</a></li>
                                <li class="breadcrumb-item"><a href="/admin/destek">Destek Talepleri</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Detay</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">

<?php if ($yeniMi || !$ticket): ?>
<div class="mb-3">
    <a href="/admin/destek" class="btn btn-primary">
        <i class="bi bi-arrow-left me-1"></i>Taleplere Dön
    </a>
</div>
<?php endif; ?>

    <?php if ($hata): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($hata) ?></div>
    <?php endif; ?>
    <?php if ($basarili): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($basarili) ?></div>
    <?php endif; ?>

    <?php if ($yeniMi): ?>
    <!-- ═══ YENİ TALEP FORMU ═══ -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-pencil-square me-1"></i> Yeni Talep Oluştur</h5></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="post_action" value="create_ticket">
                <input type="hidden" name="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">

                <div class="mb-3">
                    <label class="form-label">Konu <span class="text-danger">*</span></label>
                    <input type="text" name="konu" class="form-control" required maxlength="300" value="<?= htmlspecialchars($_POST['konu'] ?? '') ?>">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Kategori <span class="text-danger">*</span></label>
                        <select name="kategori_id" class="form-select select2" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($kategoriler as $k): ?>
                                <option value="<?= (int)$k['id'] ?>" <?= (($_POST['kategori_id'] ?? '') == $k['id']) ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Öncelik <span class="text-danger">*</span></label>
                        <select name="oncelik_id" class="form-select select2" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($oncelikler as $o): ?>
                                <option value="<?= (int)$o['id'] ?>" <?= (($_POST['oncelik_id'] ?? '') == $o['id']) ? 'selected' : '' ?>><?= htmlspecialchars($o['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Açıklama <span class="text-danger">*</span></label>
                    <textarea name="mesaj" class="form-control" rows="5" required><?= htmlspecialchars($_POST['mesaj'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Dosya Ekle</label>
                    <input type="file" class="form-control" name="dosyalar[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                    <div class="form-text">Maks. 10MB per dosya. Birden fazla dosya seçebilirsiniz.</div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i> Gönder</button>
                    <a href="/admin/destek" class="btn btn-secondary">Vazgeç</a>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($ticket): ?>
    <!--
    ═══════════════════════════════════════════════════════════════
    MESAJ GÖRÜNÜM KURALLARI:
    ─────────────────────────────────────────────────────────────
    Mesajlar gönderen adı ve tarihi üstte olacak şekilde düz kutular
    halinde listelenir; tüm portallarda aynı düzen kullanılır.
    ─────────────────────────────────────────────────────────────
    İç notlar (ic_not=1) API'den dönmez, sadece admin panelde görünür.
    ═══════════════════════════════════════════════════════════════
    -->
    <div class="row">

        <!-- Sol: Mesajlar -->
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-chat-dots me-1"></i>
                    <strong><?= htmlspecialchars($ticket['Tickets_konu'] ?? '') ?></strong>
                </div>
                <div class="card-body mesaj-listesi" id="mesajListesi">
                    <?php if (empty($mesajlar)): ?>
                    <div class="text-center text-muted py-4">Henüz mesaj yok.</div>
                    <?php else: ?>
                        <?php foreach ($mesajlar as $m): ?>
                        <div class="mesaj-kutu">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <strong><?= htmlspecialchars(trim(($m['yazan_ad'] ?? '') . ' ' . ($m['yazan_soyad'] ?? ''))) ?></strong>
                                <small class="text-muted ms-3 flex-shrink-0"><?= htmlspecialchars($m['tarih'] ?? '') ?></small>
                            </div>
                            <div class="mesaj-metni"><?= metinHtml($m['mesaj'] ?? '') ?></div>
                            <?php if (!empty($m['ekler'])): ?>
                            <div class="mt-2">
                                <?php foreach ($m['ekler'] as $ek): ?>
                                <a href="<?= htmlspecialchars($ek['dosya_url'] ?? '#') ?>" target="_blank" class="badge bg-light text-dark border me-1">
                                    <i class="bi bi-paperclip"></i> <?= htmlspecialchars($ek['dosya_adi'] ?? 'Dosya') ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Yanıt Formu -->
            <?php if (empty($ticket['Ticket_Durumlar_kapatma_durumu'])): ?>
            <div class="card">
                <div class="card-header"><i class="bi bi-reply me-1"></i>Yanıt Yaz</div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="post_action" value="reply_ticket">
                        <input type="hidden" name="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">
                        <div class="mb-3">
                            <textarea name="mesaj" class="form-control" rows="4" required placeholder="Mesajınızı yazın..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Dosya Ekle (opsiyonel)</label>
                            <input type="file" class="form-control" name="dosyalar[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Gönder</button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-lock me-1"></i> Bu talep kapatılmıştır. Yanıt gönderemezsiniz.
            </div>
            <?php endif; ?>
        </div>

        <!-- Sağ: Talep Bilgileri -->
        <div class="col-lg-4">
            <div class="mb-3">
                <a href="/admin/destek" class="btn btn-primary">
                    <i class="bi bi-arrow-left me-1"></i>Taleplere Dön
                </a>
            </div>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-info-circle me-1"></i>Talep Bilgileri</div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <th class="text-muted" style="width:40%">Talep No</th>
                            <td><code><?= htmlspecialchars($ticket['Tickets_no'] ?? '-') ?></code></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Kategori</th>
                            <td><?= htmlspecialchars($ticket['kategori_ad'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Öncelik</th>
                            <td><span class="badge" style="background:<?= htmlspecialchars($ticket['oncelik_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($ticket['oncelik_ad'] ?? '-') ?></span></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Durum</th>
                            <td><span class="badge" style="background:<?= htmlspecialchars($ticket['durum_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($ticket['durum_ad'] ?? '-') ?></span></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Kullanıcı</th>
                            <td>
                                <?php $olusturan = trim(($ticket['olusturan_ad'] ?? '') . ' ' . ($ticket['olusturan_soyad'] ?? '')); ?>
                                <?= $olusturan !== '' ? htmlspecialchars($olusturan) : '-' ?>
                                <?php if (!empty($ticket['cc_mi'])): ?>
                                <i class="bi bi-people-fill text-secondary ms-1" title="Bu talebe bilgilendirme (CC) amacıyla eklendiniz"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Atanan</th>
                            <td>
                                <?php $atanan = trim(($ticket['atanan_ad'] ?? '') . ' ' . ($ticket['atanan_soyad'] ?? '')); ?>
                                <?= $atanan !== '' ? htmlspecialchars($atanan) : '-' ?>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Açılma</th>
                            <td><?= htmlspecialchars($ticket['acilis_tarihi'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">Son Yanıt</th>
                            <td><?= htmlspecialchars($ticket['son_yanit_tarihi'] ?? '-') ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <?php else: ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Gösterilecek içerik bulunamadı.</div>
    <?php endif; ?>

                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/scripts.php'; ?>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
    $(function () {
        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

        // Çift tıklamada formun ikinci kez gönderilmesini engelle
        $('form[method="post"]').on('submit', function (e) {
            var $form = $(this);
            if ($form.data('gonderiliyor')) { e.preventDefault(); return; }
            $form.data('gonderiliyor', true);
            $form.find('button[type="submit"]').prop('disabled', true)
                 .html('<i class="bi bi-hourglass-split me-1"></i> Gönderiliyor...');
        });

        // Mesaj listesini en alta kaydır
        var mesajListesi = document.getElementById('mesajListesi');
        if (mesajListesi) mesajListesi.scrollTop = mesajListesi.scrollHeight;
    });
    </script>
</body>
</html>
