<?php
/**
 * Admin Panel - SMS Gönder
 * 
 * Tekli veya toplu SMS gönderme sayfası
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'SMS Gönder';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Ortak SMS gönderim helper'ı (ornek_sms_kanal / ornek_sms_send_bulk)
require_once __DIR__ . '/../includes/SmsSender.php';

/**
 * Numara listesine tek istekte (destekliyorsa) SMS gönderir, EntegrasyonLoglari kaydını toplu atar.
 *
 * @return array ['success'=>bool, 'message'=>string, 'results'=>array]
 */
function smsGonder(array $kanal, array $phones, $message, $userId, $db, $userName = 'Admin Panel', $zorlaTekil = false, $commercial = false) {
    try {
        // Tekil şablon testi: kanalın toplu desteğini geçici olarak kapat
        if ($zorlaTekil) {
            $kanal['toplu_destek'] = false;
        }

        $sonuc = ornek_sms_send_bulk($phones, $message, $kanal, 'Portal - ' . $userName, $commercial);

        // EntegrasyonLoglari - numara başına bir satır, tek seferde toplu insert
        $simdi    = date('Y-m-d H:i:s');
        $ip       = $_SERVER['REMOTE_ADDR'] ?? null;
        $satirlar = [];
        $detay    = [];

        // Ticari iletiler ayri islem tipiyle loglanir (denetlenebilirlik)
        $islemTipi = $commercial ? 'SMS_TICARI' : 'SMS_MANUEL';

        foreach ($sonuc['numara_sonuc'] as $phone => $r) {
            $satirlar[] = [
                'kanal_id'     => $kanal['id'],
                'islem_tipi'   => $islemTipi,
                'istek'        => $message,
                'cevap'        => $r['response'] !== '' ? $r['response'] : 'Yanit yok',
                'durum'        => $r['success'],
                'hata'         => $r['success'] ? null : $r['error'],
                'hedef'        => $phone,
                'kullanici_id' => null,
                'ip'           => $ip,
                'kaynak'       => $userName,
                'olusturan'    => $userId,
                'tarih'        => $simdi,
            ];

            $detay[] = [
                'phone'   => $phone,
                'success' => $r['success'],
                'message' => $r['success'] ? 'Gönderildi' : $r['error'],
            ];
        }

        // Geçersiz formatlı numaralar gönderilmez, sadece sonuç listesinde gösterilir
        foreach ($sonuc['gecersiz'] as $ham) {
            $detay[] = [
                'phone'   => $ham,
                'success' => false,
                'message' => 'Geçersiz numara formatı',
            ];
        }

        ornek_entegrasyon_log_toplu($db, $satirlar);

        $mesajParcalari = ["Toplam: " . $sonuc['toplam'], "Başarılı: " . $sonuc['basarili'], "Başarısız: " . $sonuc['basarisiz']];
        if (!empty($sonuc['gecersiz'])) {
            $mesajParcalari[] = "Geçersiz: " . count($sonuc['gecersiz']);
        }

        return [
            'success' => true,
            'message' => implode(' | ', $mesajParcalari),
            'results' => [
                'success' => $sonuc['basarili'],
                'failed'  => $sonuc['basarisiz'] + count($sonuc['gecersiz']),
                'details' => $detay,
            ],
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Hata: ' . $e->getMessage(),
        ];
    }
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'get_providers') {
            $kanallar = ornek_sms_kanallar($db, true);

            $data = array_map(function ($k) {
                return [
                    'id'           => $k['id'],
                    'ad'           => $k['ad'],
                    'sender'       => $k['sender'],
                    'varsayilan'   => $k['varsayilan'] ? 1 : 0,
                    'toplu_destek' => $k['toplu_destek'] ? 1 : 0,
                    'toplu_limit'  => $k['toplu_limit'],
                ];
            }, $kanallar);

            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }

        if ($action === 'get_credit') {
            $kanal = ornek_sms_kanal($db, intval($_POST['provider_id'] ?? 0));

            if (!$kanal) {
                echo json_encode(['success' => false, 'message' => 'Aktif SMS kanalı bulunamadı']);
                exit;
            }

            $sonuc = ornek_sms_kredi($kanal);

            echo json_encode([
                'success' => $sonuc['success'],
                'kredi'   => $sonuc['kredi'],
                'ham'     => substr($sonuc['ham'], 0, 200),
                'message' => $sonuc['error'],
                'kanal'   => $kanal['ad'],
            ]);
            exit;
        }

        // Tekli ve toplu gönderim aynı akışı kullanır
        if ($action === 'send_sms' || $action === 'send_bulk_sms') {
            $phones      = $_POST['phones'] ?? [];
            $message     = trim($_POST['message'] ?? '');
            $provider_id = intval($_POST['provider_id'] ?? 0);

            // Numara listesi JSON string olarak gelir (max_input_vars sınırına takılmamak için)
            if (is_string($phones)) {
                $decoded = json_decode($phones, true);
                $phones  = is_array($decoded) ? $decoded : [$phones];
            }
            if (!is_array($phones)) {
                $phones = [$phones];
            }

            // Boş olmayan numaraları filtrele
            $phones = array_values(array_filter(array_map('trim', $phones)));

            if (empty($phones) || empty($message)) {
                echo json_encode(['success' => false, 'message' => 'En az bir telefon numarası ve mesaj zorunludur']);
                exit;
            }

            $kanal = ornek_sms_kanal($db, $provider_id);

            if (!$kanal) {
                echo json_encode(['success' => false, 'message' => 'Aktif SMS kanalı bulunamadı']);
                exit;
            }

            // Coklu numarada istek sayisi artar; PHP zaman asimini kaldir
            if (count($phones) > 20) {
                set_time_limit(0);
                ignore_user_abort(true);
            }

            $userName   = $user['kullanici_ad_soyad'] ?? 'Admin Panel';
            $zorlaTekil = !empty($_POST['zorla_tekil']);
            $commercial = !empty($_POST['commercial']);
            $result     = smsGonder($kanal, $phones, $message, $user['kullanici_id'], $db, $userName, $zorlaTekil, $commercial);

            echo json_encode($result);
            exit;
        }
        
        if ($action === 'get_personel') {
            $firma_id = $_POST['firma_id'] ?? '';
            
            $whereConditions = ["kullanici_durum = 1", "kullanici_telefon IS NOT NULL", "kullanici_telefon != ''"];
            $params = [];
            
            if ($firma_id) {
                $whereConditions[] = "kullanici_firma_id = ?";
                $params[] = $firma_id;
            }
            
            $whereClause = implode(" AND ", $whereConditions);
            
            $personel = $db->fetchAll("
                SELECT 
                    kullanici_id,
                    kullanici_ad + ' ' + kullanici_soyad as ad_soyad,
                    kullanici_telefon,
                    f.firma_adi
                FROM kullanicilar k
                LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                WHERE $whereClause
                ORDER BY kullanici_ad, kullanici_soyad
            ", $params);
            
            echo json_encode(['success' => true, 'data' => $personel]);
            exit;
        }
        
        throw new Exception('Geçersiz işlem');
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Firmalar
$firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= $siteTitle ?></title>
    
    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= $pageTitle ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?>
                                    <li class="breadcrumb-item"><a href="#"><?= htmlspecialchars($menuAdi) ?></a></li>
                                <?php endif; ?>
                                <li class="breadcrumb-item active" aria-current="page"><?= $pageTitle ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    
                    <div class="row">
                        <!-- SMS Gönder -->
                        <div class="col-md-6">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="bi bi-phone"></i> SMS Gönder
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <form id="singleSmsForm">
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label for="provider_id" class="form-label mb-0">Gönderen Seç</label>
                                                <span class="badge bg-secondary" id="krediRozet" title="Sağlayıcıdaki kalan SMS bakiyesi">
                                                    <i class="bi bi-wallet2"></i> Bakiye: <span id="krediDeger">-</span>
                                                    <a href="#" id="krediYenile" class="text-white ms-1 text-decoration-none" title="Bakiyeyi yenile">
                                                        <i class="bi bi-arrow-clockwise"></i>
                                                    </a>
                                                </span>
                                            </div>
                                            <select class="form-select" id="provider_id" name="provider_id">
                                                <option value="">Varsayılan Provider</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Telefon Numarası(ları) <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="phoneNumbers" rows="8" placeholder="Her satıra bir numara yazın veya yapıştırın...
05001234567
05009876543
05001112233"></textarea>
                                            <div class="d-flex justify-content-between align-items-center mt-2">
                                                <small class="text-muted">Her satıra bir numara yazın (500+ numara desteklenir)</small>
                                                <span class="badge bg-primary" id="phoneCount">0 numara</span>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="message" class="form-label">Mesaj <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="message" name="message" rows="5" required maxlength="500" placeholder="SMS metninizi buraya yazın..."></textarea>
                                            <small class="text-muted">Kalan: <span id="charCount">500</span> karakter | <span id="smsCount">1 SMS</span></small>
                                        </div>

                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" id="commercial" name="commercial" value="1">
                                                <label class="form-check-label" for="commercial">
                                                    Ticari ileti
                                                </label>
                                            </div>
                                            <small class="text-muted">
                                                Kampanya, tanıtım ve indirim mesajları için açın; alıcıların
                                                <strong>İYS onayı</strong> bulunmalıdır. Devamsızlık, zimmet onayı gibi
                                                bilgilendirme mesajlarında kapalı kalmalıdır.
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" id="zorla_tekil" name="zorla_tekil" value="1">
                                                <label class="form-check-label" for="zorla_tekil">
                                                    Tekil şablonla gönder
                                                </label>
                                            </div>
                                            <small class="text-muted">
                                                Kapalıyken kanal destekliyorsa tüm numaralar tek istekte gider.
                                                Açıkken her numaraya ayrı istek atılır — cron ve zimmet SMS'lerinin
                                                kullandığı tekil şablonu test etmek için.
                                            </small>
                                        </div>

                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-send"></i> SMS Gönder
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Toplu SMS -->
                        <div class="col-md-6">
                            <div class="card card-success card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="bi bi-people"></i> Toplu SMS Gönder
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <form id="bulkSmsForm">
                                        <div class="mb-3">
                                            <label for="bulk_provider_id" class="form-label">Gönderen Seç</label>
                                            <select class="form-select" id="bulk_provider_id" name="provider_id">
                                                <option value="">Varsayılan Provider</option>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="firma_filter" class="form-label">Firma Filtrele</label>
                                            <select class="form-select" id="firma_filter">
                                                <option value="">Tüm Firmalar</option>
                                                <?php foreach ($firmalar as $firma): ?>
                                                    <option value="<?= $firma['firma_id'] ?>"><?= htmlspecialchars($firma['firma_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="personel_list" class="form-label">Personel Seç <span class="text-danger">*</span></label>
                                            <select class="form-select" id="personel_list" multiple size="8">
                                                <option value="">Önce personelleri yükleyin...</option>
                                            </select>
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-sm btn-secondary" id="loadPersonel">
                                                    <i class="bi bi-arrow-clockwise"></i> Personel Listesini Yükle
                                                </button>
                                                <button type="button" class="btn btn-sm btn-info" id="selectAll">
                                                    <i class="bi bi-check-all"></i> Tümünü Seç
                                                </button>
                                                <button type="button" class="btn btn-sm btn-warning" id="clearSelection">
                                                    <i class="bi bi-x"></i> Seçimi Temizle
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="bulk_message" class="form-label">Mesaj <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="bulk_message" name="bulk_message" rows="4" required maxlength="500" placeholder="Toplu SMS metninizi buraya yazın..."></textarea>
                                            <small class="text-muted">Kalan: <span id="bulkCharCount">500</span> karakter | <span id="bulkSmsCount">1 SMS</span></small>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="bi bi-send-fill"></i> Toplu SMS Gönder
                                        </button>
                                    </form>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        $(document).ready(function() {
            // Aranabilir dropdown'lar
            $('#provider_id, #bulk_provider_id, #firma_filter').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });
            $('#personel_list').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Personel arayın veya seçin...',
                closeOnSelect: false
            });

            // Provider'ları yükle
            function loadProviders() {
                $.post('', { action: 'get_providers' }, function(response) {
                    if (response.success) {
                        const singleSelect = $('#provider_id');
                        const bulkSelect = $('#bulk_provider_id');
                        
                        singleSelect.find('option:not(:first)').remove();
                        bulkSelect.find('option:not(:first)').remove();
                        
                        response.data.forEach(p => {
                            const defaultBadge = p.varsayilan ? ' ⭐' : '';
                            const topluBadge = p.toplu_destek ? ' [toplu]' : '';
                            const optionText = `${p.ad} (${p.sender})${defaultBadge}${topluBadge}`;
                            singleSelect.append(`<option value="${p.id}">${optionText}</option>`);
                            bulkSelect.append(`<option value="${p.id}">${optionText}</option>`);
                        });
                    }
                });
            }
            
            // Kalan SMS bakiyesini yükle
            function loadCredit() {
                $('#krediDeger').text('...');
                $('#krediRozet').removeClass('bg-danger bg-success bg-warning').addClass('bg-secondary');

                $.post('', {
                    action: 'get_credit',
                    provider_id: $('#provider_id').val()
                }, function(response) {
                    if (!response.success) {
                        $('#krediDeger').text('alınamadı');
                        $('#krediRozet').removeClass('bg-secondary').addClass('bg-danger')
                            .attr('title', response.message || 'Bakiye sorgulanamadı');
                        return;
                    }

                    if (response.kredi === null) {
                        // Sayısal değer ayrıştırılamadı, ham yanıtı ipucu olarak göster
                        $('#krediDeger').text('?');
                        $('#krediRozet').removeClass('bg-secondary').addClass('bg-warning')
                            .attr('title', 'Yanıt çözümlenemedi: ' + (response.ham || '-'));
                        return;
                    }

                    const k = Number(response.kredi);
                    $('#krediDeger').text(k.toLocaleString('tr-TR') + ' SMS');
                    $('#krediRozet').removeClass('bg-secondary')
                        .addClass(k < 1000 ? 'bg-danger' : 'bg-success')
                        .attr('title', response.kanal + ' - kalan bakiye');
                }).fail(function() {
                    $('#krediDeger').text('alınamadı');
                    $('#krediRozet').removeClass('bg-secondary').addClass('bg-danger');
                });
            }

            $('#krediYenile').on('click', function(e) {
                e.preventDefault();
                loadCredit();
            });

            // Kanal değişince bakiye de değişir
            $('#provider_id').on('change', loadCredit);

            loadProviders();
            loadCredit();
            
            // Karakter sayacı - SMS
            $('#message').on('input', function() {
                const len = $(this).val().length;
                const remaining = 500 - len;
                $('#charCount').text(remaining);
                
                // SMS sayısını hesapla (160 karakter = 1 SMS, sonrası 153'er)
                let smsCount = 1;
                if (len > 160) {
                    smsCount = Math.ceil(len / 153);
                }
                $('#smsCount').text(smsCount + ' SMS');
            });
            
            // Karakter sayacı - Toplu SMS
            $('#bulk_message').on('input', function() {
                const len = $(this).val().length;
                const remaining = 500 - len;
                $('#bulkCharCount').text(remaining);
                
                // SMS sayısını hesapla
                let smsCount = 1;
                if (len > 160) {
                    smsCount = Math.ceil(len / 153);
                }
                $('#bulkSmsCount').text(smsCount + ' SMS');
            });
            
            // Telefon numarası sayacı
            $('#phoneNumbers').on('input', function() {
                const phones = getPhoneNumbers();
                $('#phoneCount').text(phones.length + ' numara');
            });
            
            // Telefon numaralarını parse et
            function getPhoneNumbers() {
                const text = $('#phoneNumbers').val();
                if (!text.trim()) return [];
                
                // Satırlara böl, boş olanları ve geçersizleri filtrele
                const lines = text.split(/[\n\r]+/);
                const phones = [];
                
                lines.forEach(line => {
                    // Sadece rakamları al
                    const cleaned = line.replace(/[^0-9]/g, '').trim();
                    if (cleaned.length >= 10) {
                        phones.push(cleaned);
                    }
                });
                
                return phones;
            }
            
            // SMS Gönder Form
            $('#singleSmsForm').on('submit', function(e) {
                e.preventDefault();
                
                const phones = getPhoneNumbers();
                const message = $('#message').val();
                const provider_id = $('#provider_id').val();
                
                if (phones.length === 0) {
                    showWarning('Uyarı!', 'En az bir geçerli telefon numarası girin');
                    return;
                }
                
                if (!message.trim()) {
                    showWarning('Uyarı!', 'Mesaj alanı boş olamaz');
                    return;
                }
                
                const ticari = $('#commercial').is(':checked');

                let confirmMsg = phones.length === 1
                    ? `${phones[0]} numarasına mesaj gönderilecek.`
                    : `${phones.length} numaraya mesaj gönderilecek.`;

                if (ticari) {
                    confirmMsg += '\n\nTİCARİ İLETİ olarak gönderilecek. Alıcıların İYS onayı bulunmalıdır.';
                }
                
                confirmAction(
                    'SMS göndermek istediğinize emin misiniz?',
                    confirmMsg,
                    function() {
                        showLoading();
                        
                        $.post('', {
                            action: 'send_sms',
                            phones: JSON.stringify(phones),
                            message: message,
                            provider_id: provider_id,
                            zorla_tekil: $('#zorla_tekil').is(':checked') ? 1 : 0,
                            commercial: ticari ? 1 : 0
                        }, function(response) {
                            hideLoading();
                            
                            if (response.success) {
                                showSuccess('Gönderildi!', response.message);
                                $('#phoneNumbers').val('');
                                $('#message').val('');
                                $('#charCount').text('500');
                                $('#smsCount').text('1 SMS');
                                $('#phoneCount').text('0 numara');
                                // Ticari isareti kalici olmasin, her gonderimde bilincli secilsin
                                $('#commercial').prop('checked', false);
                                loadCredit();
                            } else {
                                showError('Hata!', response.message);
                            }
                        }).fail(function() {
                            hideLoading();
                            showError('Hata!', 'SMS gönderimi başarısız');
                        });
                    }
                );
            });
            
            // Personel listesini yükle
            $('#loadPersonel').on('click', function() {
                const firma_id = $('#firma_filter').val();
                
                $.post('', {
                    action: 'get_personel',
                    firma_id: firma_id
                }, function(response) {
                    if (response.success) {
                        const select = $('#personel_list');
                        select.empty();
                        
                        if (response.data.length === 0) {
                            select.append('<option value="">Personel bulunamadı</option>');
                        } else {
                            response.data.forEach(p => {
                                select.append(`<option value="${p.kullanici_telefon}">${p.ad_soyad} (${p.kullanici_telefon}) - ${p.firma_adi || '-'}</option>`);
                            });
                            showToast(`${response.data.length} personel yüklendi`, 'success');
                        }
                        select.trigger('change.select2');
                    } else {
                        showToast(response.message, 'error');
                    }
                });
            });
            
            // Tümünü seç
            $('#selectAll').on('click', function() {
                $('#personel_list option').prop('selected', true);
                $('#personel_list').trigger('change.select2');
            });

            // Seçimi temizle
            $('#clearSelection').on('click', function() {
                $('#personel_list option').prop('selected', false);
                $('#personel_list').trigger('change.select2');
            });
            
            // Toplu SMS Form
            $('#bulkSmsForm').on('submit', function(e) {
                e.preventDefault();
                
                const selectedPhones = $('#personel_list').val();
                const message = $('#bulk_message').val();
                const provider_id = $('#bulk_provider_id').val();
                
                if (!selectedPhones || selectedPhones.length === 0) {
                    showWarning('Uyarı!', 'Lütfen en az bir personel seçin');
                    return;
                }
                
                confirmAction(
                    `${selectedPhones.length} kişiye SMS göndermek istediğinize emin misiniz?`,
                    'Bu işlem geri alınamaz!',
                    function() {
                        showLoading();
                        
                        $.post('', {
                            action: 'send_bulk_sms',
                            phones: JSON.stringify(selectedPhones),
                            message: message,
                            provider_id: provider_id
                        }, function(response) {
                            hideLoading();
                            
                            if (response.success) {
                                showSuccess('Tamamlandı!', response.message);
                                $('#bulkSmsForm')[0].reset();
                                $('#bulkCharCount').text('500');
                                $('#bulkSmsCount').text('1 SMS');
                                $('#personel_list').empty()
                                    .append('<option value="">Önce personelleri yükleyin...</option>')
                                    .trigger('change.select2');
                            } else {
                                showError('Hata!', response.message);
                            }
                        }).fail(function() {
                            hideLoading();
                            showError('Hata!', 'SMS gönderimi başarısız');
                        });
                    }
                );
            });
        });
    </script>
</body>
</html>
