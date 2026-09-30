<?php
/**
 * Admin Panel - WhatsApp Toplu Mesaj Gönderimi
 * 
 * Chrome Eklentisi (ÖRNEK WhatsApp Bulk Sender v1.3.0) kullanarak toplu mesaj gönderme
 * Eklenti: Özel geliştirme - Sayfadan indirilebilir
 */

// Hata raporlamayı aç
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Sayfa bilgileri
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'WhatsApp Toplu Mesaj';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'ÖRNEK WhatsApp Bulk Sender ile toplu mesaj gönderimi';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'save_contacts':
                // Excel'den yüklenen kişileri kaydet
                $contacts = json_decode($_POST['contacts'], true);
                
                if (!$contacts || !is_array($contacts)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz kişi listesi']);
                    exit;
                }
                
                // Session'a kaydet (WAMS'ın kullanması için)
                $_SESSION['wams_contacts'] = $contacts;
                $_SESSION['wams_message'] = $_POST['message'] ?? '';
                $_SESSION['wams_settings'] = [
                    'delay_min' => $_POST['delay_min'] ?? 15,
                    'delay_max' => $_POST['delay_max'] ?? 30,
                    'frequency' => $_POST['frequency'] ?? 50,
                    'frequency_delay' => $_POST['frequency_delay'] ?? 60,
                    'timestamp' => $_POST['add_timestamp'] ?? false
                ];
                
                echo json_encode([
                    'success' => true, 
                    'message' => count($contacts) . ' kişi yüklendi',
                    'count' => count($contacts)
                ]);
                break;
                
            case 'get_contacts':
                // Yüklenen kişileri getir
                $contacts = $_SESSION['wams_contacts'] ?? [];
                echo json_encode(['success' => true, 'data' => $contacts]);
                break;
                
            case 'clear_contacts':
                // Yüklenen kişileri temizle
                unset($_SESSION['wams_contacts']);
                unset($_SESSION['wams_message']);
                unset($_SESSION['wams_settings']);
                echo json_encode(['success' => true, 'message' => 'Liste temizlendi']);
                break;
                
            case 'download_extension':
                // Chrome extension'ı ZIP olarak indir
                $extensionDir = __DIR__ . '/../chrome-extension';
                $zipFile = sys_get_temp_dir() . '/ornek-whatsapp-extension-v1.3.6.zip';
                
                // Önceki zip varsa sil
                if (file_exists($zipFile)) {
                    unlink($zipFile);
                }
                
                // Yeni ZIP oluştur
                $zip = new ZipArchive();
                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                    echo json_encode(['success' => false, 'message' => 'ZIP dosyası oluşturulamadı']);
                    exit;
                }
                
                // Extension dosyalarını ekle
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($extensionDir),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($extensionDir) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                
                $zip->close();
                
                // ZIP'i indir
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="ornek-whatsapp-extension-v1.3.6.zip"');
                header('Content-Length: ' . filesize($zipFile));
                readfile($zipFile);
                unlink($zipFile);
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= $siteTitle ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    

    
    <style>
        .nav-tabs .nav-link {
            color: #6c757d;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            font-weight: 500;
        }
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-area:hover {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
        .upload-area.dragover {
            border-color: #0d6efd;
            background: #cfe2ff;
        }
        .contact-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            margin: 4px;
            background: #e7f1ff;
            border-radius: 20px;
            font-size: 14px;
        }
        .contact-badge .remove-btn {
            cursor: pointer;
            color: #dc3545;
            font-weight: bold;
        }
        .wams-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .wams-info h5 {
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        .wams-info p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .wams-info .btn {
            margin-top: 15px;
            background: white;
            color: #667eea;
            font-weight: 500;
        }
        .wams-info .btn:hover {
            background: #f8f9fa;
        }
        .char-counter {
            font-size: 12px;
            color: #6c757d;
            text-align: right;
            margin-top: 5px;
        }
        .media-upload-box {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            margin-top: 10px;
        }
        .preview-image {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            margin-top: 10px;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>
    
    <main class="app-main">
        <!-- Sayfa Başlığı -->
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <h3 class="mb-0">
                            <i class="bi bi-whatsapp text-success"></i> <?= $pageTitle ?>
                        </h3>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= $menuAdi ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= $pageTitle ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ana İçerik -->
        <div class="app-content">
            <div class="container-fluid">
                
                <!-- Extension Bilgi Kartı -->
                <div class="wams-info">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <h5>
                                <i class="bi bi-emoji-smile"></i> Merhaba! 👋
                            </h5>
                            <p><strong>ÖRNEK WhatsApp Bulk Sender</strong> eklentisi ile WhatsApp üzerinden otomatik mesaj gönderebilirsin. Dosya Yükle seçeneği Değişkenleri kullanarak kişiye özel mesaj göndermen sağlar.</p>
                            <p style="margin-top: 8px;">Zaman Damgası ekleyerek spam yeme ihtimalini azaltabilirsin. Bir sorun yaşarsan iletişim butonundan bize ulaşabilirsin.</p>
                        </div>
                        <div class="col-md-3 text-end">
                            <button onclick="downloadExtension()" class="btn btn-light mb-2">
                                <i class="bi bi-download"></i> Eklenti İndir (v1.3.6)
                            </button>
                            <div style="font-size: 11px; color: #6c757d;">
                                <i class="bi bi-info-circle"></i> İndirip Chrome'a yükle
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ana İçerik Kartı -->
                <div class="card card-primary card-outline">
                    <div class="card-body">
                        
                        <!-- Kişi Ekleme Sekmeleri -->
                        <ul class="nav nav-tabs" id="contactTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual" type="button" role="tab">
                                    <i class="bi bi-person-plus"></i> Manuel Ekle
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="file-tab" data-bs-toggle="tab" data-bs-target="#file" type="button" role="tab">
                                    <i class="bi bi-file-earmark-arrow-up"></i> Dosya Yükle
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="contacts-tab" data-bs-toggle="tab" data-bs-target="#contacts" type="button" role="tab">
                                    <i class="bi bi-person-lines-fill"></i> Rehberi Bağla
                                </button>
                            </li>
                        </ul>
                        
                        <!-- Sekme İçerikleri -->
                        <div class="tab-content mt-3" id="contactTabsContent">
                            
                            <!-- Manuel Ekle -->
                            <div class="tab-pane fade show active" id="manual" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Telefon Numarası</label>
                                        <input type="text" class="form-control" id="manual_phone" placeholder="5XXXXXXXXX">
                                        <small class="text-muted">Başında 0 olmadan, 10 haneli numara girin</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">İsim (Opsiyonel)</label>
                                        <input type="text" class="form-control" id="manual_name" placeholder="Ahmet Yılmaz">
                                    </div>
                                    <div class="col-md-12 mt-3">
                                        <button type="button" class="btn btn-primary" id="addManualContact">
                                            <i class="bi bi-plus-circle"></i> Kişi Ekle
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Dosya Yükle -->
                            <div class="tab-pane fade" id="file" role="tabpanel">
                                <div class="upload-area" id="uploadArea">
                                    <i class="bi bi-file-earmark-arrow-up" style="font-size: 48px; color: #0d6efd;"></i>
                                    <h5 class="mt-3">Excel Yükle</h5>
                                    <p class="text-muted mb-3">Dosya Maximum 20MB. Max 10,000 satır</p>
                                    <input type="file" id="fileInput" accept=".xlsx,.xls" style="display: none;">
                                    <button type="button" class="btn btn-primary" id="selectFileBtn">
                                        Dosya seçmek için tıklayın
                                    </button>
                                </div>
                                <div class="alert alert-info mt-3">
                                    <strong>Excel Formatı:</strong> İlk sütun: Telefon (5XXXXXXXXX), İkinci sütun: İsim (opsiyonel)
                                </div>
                            </div>
                            
                            <!-- Rehberi Bağla -->
                            <div class="tab-pane fade" id="contacts" role="tabpanel">
                                <div class="alert alert-warning">
                                    <i class="bi bi-info-circle"></i> WhatsApp web rehberinizi kullanmak için WAMS eklentisini açın ve "Rehberi Bağla" butonuna tıklayın.
                                </div>
                                <button type="button" class="btn btn-success">
                                    <i class="bi bi-link-45deg"></i> WhatsApp Rehberinden Kişi Seç
                                </button>
                                <p class="text-muted mt-2">Bu özellik WAMS eklentisi ile çalışır. Eklentiyi chrome://extensions/ adresinden etkinleştirin.</p>
                            </div>
                            
                        </div>
                        
                        <hr class="my-4">
                        
                        <!-- Eklenen Kişiler -->
                        <div id="contactsList" class="mb-4">
                            <h6 class="mb-3">
                                <i class="bi bi-people-fill"></i> Alıcılar: <span id="contactCount" class="badge bg-primary">0</span>
                            </h6>
                            <div id="contactBadges"></div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <!-- Mesaj İçeriği Sekmeleri -->
                        <ul class="nav nav-tabs" id="messageTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="text-tab" data-bs-toggle="tab" data-bs-target="#textMessage" type="button" role="tab">
                                    <i class="bi bi-chat-text"></i> Metin Mesajı
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="image-tab" data-bs-toggle="tab" data-bs-target="#imageMessage" type="button" role="tab">
                                    <i class="bi bi-image"></i> Resim (jpg, png, gif)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="video-tab" data-bs-toggle="tab" data-bs-target="#videoMessage" type="button" role="tab">
                                    <i class="bi bi-camera-video"></i> Video (mp4, avi)
                                </button>
                            </li>
                        </ul>
                        
                        <!-- Mesaj İçeriği -->
                        <div class="tab-content mt-3" id="messageTabsContent">
                            
                            <!-- Metin Mesajı -->
                            <div class="tab-pane fade show active" id="textMessage" role="tabpanel">
                                <div class="accordion mb-3" id="messageAccordion">
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#messageCollapse">
                                                <i class="bi bi-envelope-open"></i> Metin Mesajı
                                            </button>
                                        </h2>
                                        <div id="messageCollapse" class="accordion-collapse collapse show" data-bs-parent="#messageAccordion">
                                            <div class="accordion-body">
                                                <label class="form-label">
                                                    <i class="bi bi-chat-square-text"></i> Zaman Damgası
                                                </label>
                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" id="addTimestamp">
                                                    <label class="form-check-label" for="addTimestamp">
                                                        Mesaja zaman damgası ekle (spam önleme)
                                                    </label>
                                                </div>
                                                
                                                <label class="form-label">Mesajınız</label>
                                                <textarea class="form-control" id="messageText" rows="6" maxlength="5000" placeholder="WhatsApp mesajınızı buraya yazın...">Merhaba! 👋

ÖRNEK WhatsApp Bulk Sender ile toplu mesaj gönderimi yapıyoruz. 

Kişiye özel mesajlar için Dosya Yükle sekmesinden Excel yükleyebilirsin. {ad}, {telefon}, {ek1}, {ek2}, {ek3} değişkenlerini kullanabilirsin.

İyi günler dileriz! 🌟</textarea>
                                                <div class="char-counter">
                                                    Karakter: <span id="charCount">287</span> / 5000 ve <span id="messageLines">30</span> arasında olsun.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Resim Mesajı -->
                            <div class="tab-pane fade" id="imageMessage" role="tabpanel">
                                <div class="media-upload-box">
                                    <i class="bi bi-image" style="font-size: 48px; color: #28a745;"></i>
                                    <p class="mt-2">Resim seçmek için tıklayın</p>
                                    <input type="file" id="imageInput" accept=".jpg,.jpeg,.png,.gif" style="display: none;">
                                    <button type="button" class="btn btn-success" id="selectImageBtn">
                                        <i class="bi bi-upload"></i> Resim Yükle
                                    </button>
                                </div>
                                <div id="imagePreview"></div>
                            </div>
                            
                            <!-- Video Mesajı -->
                            <div class="tab-pane fade" id="videoMessage" role="tabpanel">
                                <div class="media-upload-box">
                                    <i class="bi bi-camera-video" style="font-size: 48px; color: #17a2b8;"></i>
                                    <p class="mt-2">Video seçmek için tıklayın</p>
                                    <input type="file" id="videoInput" accept=".mp4,.avi" style="display: none;">
                                    <button type="button" class="btn btn-info" id="selectVideoBtn">
                                        <i class="bi bi-upload"></i> Video Yükle
                                    </button>
                                </div>
                                <div id="videoPreview"></div>
                            </div>
                            
                        </div>
                        
                        <hr class="my-4">
                        
                        <!-- Gönderim Ayarları -->
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <i class="bi bi-gear"></i> Gönderme süresi aralığı (sn):
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Minimum Süre (sn)</label>
                                        <input type="number" class="form-control" id="delayMin" value="15" min="1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Maksimum Süre (sn)</label>
                                        <input type="number" class="form-control" id="delayMax" value="30" min="1">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">ve</label>
                                        <p class="text-muted mb-0" style="font-size: 13px;">arasında olsun.</p>
                                    </div>
                                </div>
                                
                                <h6 class="card-title mt-4">
                                    <i class="bi bi-clock"></i> Her
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <input type="number" class="form-control" id="frequency" value="50" min="1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">mesajdan sonra</label>
                                        <input type="number" class="form-control" id="frequencyDelay" value="60" min="1">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">saniye bekle.</label>
                                        <p class="text-muted mb-0" style="font-size: 13px;">
                                            <i class="bi bi-exclamation-circle text-danger"></i> İpucu: WhatsApp kısıtlamalarını önlemek için göndermler arasındaki aralığı uzatın.
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning mt-3 mb-0">
                                    <small>
                                        <i class="bi bi-exclamation-triangle"></i> İpucu: WhatsApp kısıtlamalarını önlemek için gönderiler arasındaki aralığı uzatın.
                                        Bu uzun WhatsApp tarafından erişim engellemenizi önlemiş veya azaltmış uygulama olur.
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Gönder Butonu -->
                        <div class="text-center mt-4">
                            <div class="d-flex gap-2 justify-content-center align-items-center flex-wrap">
                                <button type="button" class="btn btn-secondary" id="previewBtn">
                                    <i class="bi bi-eye"></i> Önizleme
                                </button>
                                <button type="button" class="btn btn-success btn-lg" id="sendToExtensionBtn">
                                    <i class="bi bi-whatsapp"></i> Extension'a Gönder
                                </button>
                            </div>
                            
                            <div class="mt-3">
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Nasıl Kullanılır?</strong><br>
                                    1. Kişileri ekleyin ve mesajınızı yazın<br>
                                    2. "Extension'a Gönder" butonuna tıklayın<br>
                                    3. WhatsApp Web'i açın: <a href="https://web.whatsapp.com/" target="_blank">web.whatsapp.com</a><br>
                                    4. Chrome sağ üstteki Extension ikonuna tıklayın<br>
                                    5. "Başlat" butonuna tıklayın
                                </div>
                            </div>
                        </div>
                        
                    </div>
                </div>
                
            </div>
        </div>
    </main>
    
    <?php include '../includes/footer.php'; ?>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

<!-- Bootstrap Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<!-- SheetJS (Excel okuma için) -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<!-- AdminLTE -->
<script src="/admin/assets/js/adminlte.min.js"></script>

<!-- Custom JS -->
<script src="/admin/assets/js/custom.js"></script>

<script>
$(document).ready(function() {
    let contacts = [];
    
    // Karakter sayacı
    $('#messageText').on('input', function() {
        const text = $(this).val();
        $('#charCount').text(text.length);
        $('#messageLines').text(text.split('\n').length);
    });
    
    // Manuel kişi ekleme
    $('#addManualContact').on('click', function() {
        const phone = $('#manual_phone').val().trim();
        const name = $('#manual_name').val().trim();
        
        if (!phone) {
            showToast('Telefon numarası girin', 'error');
            return;
        }
        
        // Telefon format kontrolü
        if (!/^5\d{9}$/.test(phone)) {
            showToast('Geçersiz telefon formatı! 5XXXXXXXXX formatında girin', 'error');
            return;
        }
        
        // Aynı numara var mı kontrol
        if (contacts.find(c => c.phone === phone)) {
            showToast('Bu numara zaten listede!', 'warning');
            return;
        }
        
        contacts.push({ phone: phone, name: name || phone });
        updateContactsList();
        
        $('#manual_phone').val('');
        $('#manual_name').val('');
        showToast('Kişi eklendi', 'success');
    });
    
    // Excel yükleme
    $('#selectFileBtn').on('click', function() {
        $('#fileInput').click();
    });
    
    $('#uploadArea').on('click', function() {
        $('#fileInput').click();
    });
    
    // Drag & Drop
    $('#uploadArea').on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    });
    
    $('#uploadArea').on('dragleave', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
    });
    
    $('#uploadArea').on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            handleExcelFile(files[0]);
        }
    });
    
    $('#fileInput').on('change', function(e) {
        if (this.files.length > 0) {
            handleExcelFile(this.files[0]);
        }
    });
    
    // Excel dosyasını işle
    function handleExcelFile(file) {
        if (!file.name.match(/\.(xlsx|xls)$/)) {
            showToast('Sadece Excel dosyası yükleyebilirsiniz', 'error');
            return;
        }
        
        if (file.size > 20 * 1024 * 1024) {
            showToast('Dosya boyutu 20MB\'dan büyük olamaz', 'error');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                const rows = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
                
                if (rows.length > 10000) {
                    showToast('Maksimum 10,000 satır yükleyebilirsiniz', 'error');
                    return;
                }
                
                let added = 0;
                rows.forEach((row, index) => {
                    if (index === 0) return; // Başlık satırını atla
                    
                    const phone = String(row[0] || '').trim();
                    const name = String(row[1] || phone).trim();
                    
                    if (phone && /^5\d{9}$/.test(phone)) {
                        if (!contacts.find(c => c.phone === phone)) {
                            contacts.push({ phone: phone, name: name });
                            added++;
                        }
                    }
                });
                
                updateContactsList();
                showToast(`${added} kişi yüklendi`, 'success');
                
            } catch (error) {
                showToast('Excel dosyası okunamadı: ' + error.message, 'error');
            }
        };
        reader.readAsArrayBuffer(file);
    }
    
    // Kişi listesini güncelle
    function updateContactsList() {
        $('#contactCount').text(contacts.length);
        
        if (contacts.length === 0) {
            $('#contactBadges').html('<p class="text-muted">Henüz kişi eklenmedi</p>');
            return;
        }
        
        let html = '';
        contacts.forEach((contact, index) => {
            html += `
                <span class="contact-badge">
                    <i class="bi bi-person-circle"></i>
                    ${contact.name} (${contact.phone})
                    <span class="remove-btn" data-index="${index}">×</span>
                </span>
            `;
        });
        $('#contactBadges').html(html);
    }
    
    // Kişi silme
    $(document).on('click', '.remove-btn', function() {
        const index = $(this).data('index');
        contacts.splice(index, 1);
        updateContactsList();
        showToast('Kişi silindi', 'info');
    });
    
    // Resim yükleme
    $('#selectImageBtn').on('click', function() {
        $('#imageInput').click();
    });
    
    $('#imageInput').on('change', function(e) {
        if (this.files.length > 0) {
            const file = this.files[0];
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#imagePreview').html(`<img src="${e.target.result}" class="preview-image">`);
            };
            reader.readAsDataURL(file);
            showToast('Resim yüklendi', 'success');
        }
    });
    
    // Video yükleme
    $('#selectVideoBtn').on('click', function() {
        $('#videoInput').click();
    });
    
    $('#videoInput').on('change', function(e) {
        if (this.files.length > 0) {
            const file = this.files[0];
            $('#videoPreview').html(`<p class="text-success mt-2"><i class="bi bi-check-circle"></i> ${file.name} yüklendi</p>`);
            showToast('Video yüklendi', 'success');
        }
    });
    
    // Önizleme
    $('#previewBtn').on('click', function() {
        if (contacts.length === 0) {
            showToast('Önce alıcı ekleyin', 'warning');
            return;
        }
        
        const message = $('#messageText').val();
        if (!message.trim()) {
            showToast('Mesaj metni boş olamaz', 'warning');
            return;
        }
        
        showSuccess('Önizleme', `${contacts.length} kişiye mesaj gönderilecek:\n\n${message.substring(0, 100)}...`);
    });
    
    // Extension'a Gönder butonu
    $('#sendToExtensionBtn').on('click', function() {
        if (contacts.length === 0) {
            showToast('Önce alıcı ekleyin', 'warning');
            return;
        }
        
        const message = $('#messageText').val();
        if (!message.trim()) {
            showToast('Mesaj metni boş olamaz', 'warning');
            return;
        }
        
        // Ayarları topla
        const settings = {
            delay_min: parseInt($('#delayMin').val()) || 15,
            delay_max: parseInt($('#delayMax').val()) || 30,
            frequency: parseInt($('#frequency').val()) || 50,
            frequency_delay: parseInt($('#frequencyDelay').val()) || 60,
            add_timestamp: $('#addTimestamp').is(':checked')
        };
        
        // Extension ID (Chrome extensions sayfasından)
        const extensionId = 'doamlgmfnmbjcdccaihbhgiphbljipid';
        
        // Extension'a mesaj gönder
        if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage) {
            chrome.runtime.sendMessage(
                extensionId,
                {
                    action: 'startSending',
                    contacts: contacts,
                    message: message,
                    settings: settings
                },
                function(response) {
                    if (chrome.runtime.lastError) {
                        showError('Extension Hatası!', 
                            'Extension ile bağlantı kurulamadı. Extension yüklü ve aktif mi kontrol edin.<br><br>' +
                            'Extension ID: ' + extensionId);
                    } else if (response && response.success) {
                        showSuccess('Gönderim Başlatıldı!', 
                            `${contacts.length} kişiye mesaj gönderimi otomatik olarak başlatıldı!<br><br>` +
                            '🚀 Extension WhatsApp Web sekmesini açıp mesajları gönderiyor...<br>' +
                            '📊 İlerlemeyi görmek için extension ikonuna tıklayabilirsiniz.'
                        );
                        
                        // Extension otomatik başladı, WhatsApp Web'i kendisi açacak
                        // openOrFocusWhatsAppWeb() kaldırıldı - extension hallediyor
                    } else {
                        showError('Hata!', response?.message || 'Bilinmeyen bir hata oluştu');
                    }
                }
            );
        } else {
            // Chrome extension API yok - localStorage kullan
            try {
                const data = {
                    contacts: contacts,
                    message: message,
                    settings: settings,
                    timestamp: new Date().toISOString()
                };
                
                localStorage.setItem('ornek_whatsapp_bulk_data', JSON.stringify(data));
                
                showSuccess('Veriler Kaydedildi!', 
                    `${contacts.length} kişi ve mesaj kaydedildi!<br><br>` +
                    '1. Tarayıcı üst çubuğundaki ORNEK eklenti ikonuna tıklayın<br>' +
                    '2. "Başlat" butonuna basın<br><br>' +
                    'Extension WhatsApp Web\'i otomatik açacak ve mesajları gönderecek.'
                );
                
                // localStorage modunda kullanıcı manuel başlatır
                // openOrFocusWhatsAppWeb() kaldırıldı
                
            } catch (error) {
                showError('Hata!', 'Veriler kaydedilemedi: ' + error.message);
            }
        }
    });
    
    // Başlangıç
    updateContactsList();
});

// Extension indir (GLOBAL fonksiyon - onclick için)
function downloadExtension() {
    showToast('Eklenti indiriliyor...', 'info');
    window.location.href = '?action=download_extension';
}
</script>
</body>
</html>
