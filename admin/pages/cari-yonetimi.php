<?php
/**
 * Admin Panel - Cari Yönetimi
 * 
 * Cari (Müşteri/Tedarikçi) kayıtlarının yönetimi
 */

require_once __DIR__ . '/../auth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Mevcut sayfanın bilgilerini al
$currentPageFile = basename($_SERVER['PHP_SELF']);
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
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cari Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// Şehir ve İlçe Listelerini Getir
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Sehirler ORDER BY SehirAdi");
$ilceler = $db->fetchAll("SELECT ilceId, SehirId, IlceAdi, SehirAdi FROM Ilceler ORDER BY SehirAdi, IlceAdi");

// Cari tablosunun kolon şeması (zorunluluk ve uzunluk bilgisi buradan türetilir)
$cariKolonlari = [];
foreach ($db->fetchAll("
    SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'Cari'
") as $kolon) {
    $cariKolonlari[$kolon['COLUMN_NAME']] = $kolon;
}

/**
 * Kolon formda zorunlu mu? (NOT NULL ve varsayılan değeri yok)
 */
function cariZorunlu(string $kolon): bool
{
    global $cariKolonlari;
    $bilgi = $cariKolonlari[$kolon] ?? null;
    return $bilgi && $bilgi['IS_NULLABLE'] === 'NO' && $bilgi['COLUMN_DEFAULT'] === null;
}

/**
 * Label metni + zorunluysa yıldız
 */
function cariLabel(string $kolon, string $metin): string
{
    return htmlspecialchars($metin) . (cariZorunlu($kolon) ? ' <span class="text-danger">*</span>' : '');
}

/**
 * Input için required + maxlength nitelikleri
 */
function cariNitelik(string $kolon): string
{
    global $cariKolonlari;
    $nitelik = cariZorunlu($kolon) ? ' required' : '';
    $uzunluk = $cariKolonlari[$kolon]['CHARACTER_MAXIMUM_LENGTH'] ?? null;
    if ($uzunluk && $uzunluk > 0) {
        $nitelik .= ' maxlength="' . (int)$uzunluk . '"';
    }
    return $nitelik;
}

/**
 * Telefonu 905001234567 biçimine normalize eder.
 * Rakam dışı karakterler atılır, ülke kodu 90 olarak sabitlenir.
 */
function telefonNormalize(?string $telefon): ?string
{
    $rakam = preg_replace('/\D+/', '', (string)$telefon);
    if ($rakam === '') {
        return null;
    }
    $rakam = ltrim($rakam, '0');            // 00.. / 0.. baştaki sıfırlar
    if (strlen($rakam) === 10) {            // 5001234567
        $rakam = '90' . $rakam;
    } elseif (strlen($rakam) === 12 && strpos($rakam, '90') === 0) {
        // 905001234567 - zaten uygun
    } elseif (strlen($rakam) > 12 && substr($rakam, -12, 2) === '90') {
        $rakam = substr($rakam, -12);       // fazladan önek varsa son 12 hane
    }
    return $rakam;
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'getSehirler':
                // Şehir listesini getir
                echo json_encode(['success' => true, 'data' => $sehirler]);
                break;
                
            case 'getIlceler':
                // Belirli bir şehre ait ilçeleri getir
                $sehirId = $_POST['sehir_id'] ?? 0;
                $filteredIlceler = array_filter($ilceler, function($ilce) use ($sehirId) {
                    return $ilce['SehirId'] == $sehirId;
                });
                echo json_encode(['success' => true, 'data' => array_values($filteredIlceler)]);
                break;
                
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam_cari' => $db->fetchOne("SELECT COUNT(*) as total FROM Cari")['total'] ?? 0,
                    'aktif_cari' => $db->fetchOne("SELECT COUNT(*) as total FROM Cari WHERE cari_aktif = 1")['total'] ?? 0,
                    'musteri_sayisi' => $db->fetchOne("SELECT COUNT(*) as total FROM Cari WHERE cari_musteri = 1")['total'] ?? 0,
                    'tedarikci_sayisi' => $db->fetchOne("SELECT COUNT(*) as total FROM Cari WHERE cari_tedarikci = 1")['total'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Cari listesini filtrelerle getir
                $kosullar = [];
                $parametreler = [];

                // Genel arama: ad, ünvan, vergi no/TC, telefon, e-posta, yetkili adı
                $arama = trim($_POST['arama'] ?? '');
                if ($arama !== '') {
                    $a = '%' . $arama . '%';
                    $kosullar[] = "(cari_adi LIKE ? OR cari_unvan LIKE ? OR cari_vergi_no LIKE ? OR cari_telefon LIKE ? OR cari_email LIKE ? OR cari_yetkili_adi LIKE ?)";
                    array_push($parametreler, $a, $a, $a, $a, $a, $a);
                }

                // Vergi No / TC: rakam dışı karakterler temizlenerek aranır
                $vergiNo = preg_replace('/\D+/', '', $_POST['vergi_no'] ?? '');
                if ($vergiNo !== '') {
                    $kosullar[] = "REPLACE(REPLACE(ISNULL(cari_vergi_no,''),' ',''),'-','') LIKE ?";
                    $parametreler[] = '%' . $vergiNo . '%';
                }

                // Tip: musteri / tedarikci
                $tip = $_POST['tip'] ?? '';
                if ($tip === 'musteri') {
                    $kosullar[] = "cari_musteri = 1";
                } elseif ($tip === 'tedarikci') {
                    $kosullar[] = "cari_tedarikci = 1";
                }

                // Durum: 1 aktif / 0 pasif
                if (isset($_POST['durum']) && $_POST['durum'] !== '') {
                    $kosullar[] = "ISNULL(cari_aktif,0) = ?";
                    $parametreler[] = intval($_POST['durum']);
                }

                // Şehir
                if (!empty($_POST['sehir_id'])) {
                    $kosullar[] = "cari_sehirler = ?";
                    $parametreler[] = intval($_POST['sehir_id']);
                }

                $where = $kosullar ? 'WHERE ' . implode(' AND ', $kosullar) : '';

                $cariList = $db->fetchAll("
                    SELECT
                        cari_id,
                        cari_adi,
                        cari_unvan,
                        cari_vergi_no,
                        cari_telefon,
                        cari_email,
                        cari_musteri,
                        cari_tedarikci,
                        cari_aktif,
                        cari_olusturma_tarihi
                    FROM Cari
                    $where
                    ORDER BY cari_olusturma_tarihi DESC
                ", $parametreler);
                echo json_encode(['success' => true, 'data' => $cariList, 'count' => count($cariList)]);
                break;
                
            case 'get':
                // Tek bir cari kaydını getir
                $cariId = $_POST['cari_id'] ?? 0;
                $cari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariId]);
                echo json_encode(['success' => true, 'data' => $cari]);
                break;
                
            case 'save':
                // Yeni cari kaydet veya güncelle
                $cariId = $_POST['cari_id'] ?? 0;
                $data = [
                    'cari_adi' => $_POST['cari_adi'] ?? '',
                    'cari_unvan' => $_POST['cari_unvan'] ?? '',
                    'cari_vergi_dairesi' => $_POST['cari_vergi_dairesi'] ?? '',
                    'cari_vergi_no' => $_POST['cari_vergi_no'] ?? '',
                    'cari_mersis_no' => $_POST['cari_mersis_no'] ?? '',
                    'cari_ticaret_sicil_no' => $_POST['cari_ticaret_sicil_no'] ?? '',
                    'cari_telefon' => telefonNormalize($_POST['cari_telefon'] ?? ''),
                    'cari_email' => $_POST['cari_email'] ?? '',
                    'cari_adres' => $_POST['cari_adres'] ?? '',
                    'cari_posta_kodu' => $_POST['cari_posta_kodu'] ?? '',
                    'cari_yetkili_adi' => $_POST['cari_yetkili_adi'] ?? '',
                    'cari_yetkili_telefon' => telefonNormalize($_POST['cari_yetkili_telefon'] ?? ''),
                    'cari_yetkili_email' => $_POST['cari_yetkili_email'] ?? '',
                    'cari_sehirler' => $_POST['cari_sehirler'] ?? '',
                    'cari_ilceler' => $_POST['cari_ilceler'] ?? '',
                    'cari_musteri' => isset($_POST['cari_musteri']) ? 1 : 0,
                    'cari_tedarikci' => isset($_POST['cari_tedarikci']) ? 1 : 0,
                    'cari_aktif' => isset($_POST['cari_aktif']) ? 1 : 0
                ];

                // Boş gönderilen alanları NULL yaz (nullable kolonlarda '' yerine NULL)
                foreach ($data as $kolon => $deger) {
                    if ($deger === '') {
                        $data[$kolon] = null;
                    }
                }

                // Şemadan zorunlu olan alanların dolu geldiğini doğrula
                foreach (array_keys($data) as $kolon) {
                    if (cariZorunlu($kolon) && ($data[$kolon] === null || $data[$kolon] === '')) {
                        throw new Exception('Zorunlu alan boş bırakılamaz: ' . $kolon);
                    }
                }

                if ($cariId > 0) {
                    // Güncelleme
                    $data['cari_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['cari_guncelleyen_kullanici'] = $user['kullanici_id'];
                    
                    $db->update('Cari', $data, ['cari_id' => $cariId]);
                    echo json_encode(['success' => true, 'message' => 'Cari başarıyla güncellendi']);
                } else {
                    // Yeni kayıt
                    $data['cari_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['cari_olusturan_kullanici'] = $user['kullanici_id'];
                    
                    $db->insert('Cari', $data);
                    echo json_encode(['success' => true, 'message' => 'Cari başarıyla eklendi']);
                }
                break;
                
            case 'delete':
                // Cari sil
                $cariId = $_POST['cari_id'] ?? 0;
                $db->delete('Cari', ['cari_id' => $cariId]);
                echo json_encode(['success' => true, 'message' => 'Cari başarıyla silindi']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
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
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .badge-aktif {
            background-color: #28a745;
        }
        .badge-pasif {
            background-color: #dc3545;
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
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon">
                                    <i class="bi bi-people-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Cari</span>
                                    <span class="info-box-number" id="stat-toplam-cari">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon">
                                    <i class="bi bi-check-circle-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Cari</span>
                                    <span class="info-box-number" id="stat-aktif-cari">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon">
                                    <i class="bi bi-cart-check-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Müşteri Sayısı</span>
                                    <span class="info-box-number" id="stat-musteri">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon">
                                    <i class="bi bi-truck"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tedarikçi Sayısı</span>
                                    <span class="info-box-number" id="stat-tedarikci">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Paneli -->
                    <div class="card card-primary card-outline mb-3 collapse show" id="filterCard">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Cari Arama</h3>
                        </div>
                        <div class="card-body">
                            <form id="filterForm">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label" for="filter_arama">Ara</label>
                                        <input type="text" class="form-control" id="filter_arama" name="arama" placeholder="Cari adı / ünvan / vergi no / telefon / e-posta...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="filter_vergi_no">Vergi No / TC</label>
                                        <input type="text" class="form-control" id="filter_vergi_no" name="vergi_no" placeholder="1234567890">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="filter_tip">Tip</label>
                                        <select class="form-select" id="filter_tip" name="tip">
                                            <option value="">Tümü</option>
                                            <option value="musteri">Müşteri</option>
                                            <option value="tedarikci">Tedarikçi</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="filter_durum">Durum</label>
                                        <select class="form-select" id="filter_durum" name="durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="filter_sehir">Şehir</label>
                                        <select class="form-select" id="filter_sehir" name="sehir_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sehirler as $sehir): ?>
                                            <option value="<?= $sehir['SehirId'] ?>"><?= htmlspecialchars($sehir['SehirAdi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Ara</button>
                                        <button type="button" class="btn btn-secondary btn-sm" id="clearFilters"><i class="bi bi-x"></i> Temizle</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Cari Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Cari Listesi</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openCariModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Cari Ekle
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div>
                                <table class="table table-bordered table-striped table-hover w-100" id="cariTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Cari Adı</th>
                                            <th>Ünvan</th>
                                            <th>Vergi No</th>
                                            <th>Telefon</th>
                                            <th>Email</th>
                                            <th>Tip</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Dinamik içerik -->
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
    
    <!-- Cari Modal -->
    <div class="modal fade" id="cariModal" tabindex="-1" aria-labelledby="cariModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cariModalLabel">Yeni Cari Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="cariForm" class="needs-validation" novalidate>
                    <input type="hidden" id="cari_id" name="cari_id" value="0">
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Genel Bilgiler -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3">Genel Bilgiler</h6>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="cari_adi" class="form-label"><?= cariLabel('cari_adi', 'Cari Adı') ?></label>
                                <input type="text" class="form-control" id="cari_adi" name="cari_adi"<?= cariNitelik('cari_adi') ?>>
                                <div class="invalid-feedback">Cari adı zorunludur.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="cari_unvan" class="form-label"><?= cariLabel('cari_unvan', 'Ünvan') ?></label>
                                <input type="text" class="form-control" id="cari_unvan" name="cari_unvan"<?= cariNitelik('cari_unvan') ?>>
                                <div class="invalid-feedback">Ünvan zorunludur.</div>
                            </div>

                            <!-- Vergi Bilgileri -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">Vergi Bilgileri</h6>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_vergi_dairesi" class="form-label"><?= cariLabel('cari_vergi_dairesi', 'Vergi Dairesi') ?></label>
                                <input type="text" class="form-control" id="cari_vergi_dairesi" name="cari_vergi_dairesi"<?= cariNitelik('cari_vergi_dairesi') ?>>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_vergi_no" class="form-label"><?= cariLabel('cari_vergi_no', 'Vergi No') ?></label>
                                <input type="text" class="form-control" id="cari_vergi_no" name="cari_vergi_no"<?= cariNitelik('cari_vergi_no') ?>>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_mersis_no" class="form-label"><?= cariLabel('cari_mersis_no', 'Mersis No') ?></label>
                                <input type="text" class="form-control" id="cari_mersis_no" name="cari_mersis_no"<?= cariNitelik('cari_mersis_no') ?>>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_ticaret_sicil_no" class="form-label"><?= cariLabel('cari_ticaret_sicil_no', 'Ticaret Sicil No') ?></label>
                                <input type="text" class="form-control" id="cari_ticaret_sicil_no" name="cari_ticaret_sicil_no"<?= cariNitelik('cari_ticaret_sicil_no') ?>>
                            </div>

                            <!-- İletişim Bilgileri -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">İletişim Bilgileri</h6>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_telefon" class="form-label"><?= cariLabel('cari_telefon', 'Telefon') ?></label>
                                <input type="tel" class="form-control telefon-alani" id="cari_telefon" name="cari_telefon" placeholder="905001234567"<?= cariNitelik('cari_telefon') ?>>
                                <div class="form-text">Nasıl yazılırsa yazılsın 905001234567 biçiminde kaydedilir.</div>
                                <div class="invalid-feedback">Telefon zorunludur.</div>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_email" class="form-label"><?= cariLabel('cari_email', 'Email') ?></label>
                                <input type="email" class="form-control" id="cari_email" name="cari_email"<?= cariNitelik('cari_email') ?>>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_posta_kodu" class="form-label"><?= cariLabel('cari_posta_kodu', 'Posta Kodu') ?></label>
                                <input type="text" class="form-control" id="cari_posta_kodu" name="cari_posta_kodu"<?= cariNitelik('cari_posta_kodu') ?>>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="cari_sehirler" class="form-label"><?= cariLabel('cari_sehirler', 'Şehir') ?></label>
                                <select class="form-select" id="cari_sehirler" name="cari_sehirler" onchange="loadIlceler()"<?= cariZorunlu('cari_sehirler') ? ' required' : '' ?>>
                                    <option value="">Şehir Seçiniz...</option>
                                    <?php foreach ($sehirler as $sehir): ?>
                                        <option value="<?= $sehir['SehirId'] ?>"><?= htmlspecialchars($sehir['SehirAdi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="cari_ilceler" class="form-label"><?= cariLabel('cari_ilceler', 'İlçe') ?></label>
                                <select class="form-select" id="cari_ilceler" name="cari_ilceler" disabled<?= cariZorunlu('cari_ilceler') ? ' required' : '' ?>>
                                    <option value="">Önce şehir seçiniz...</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label for="cari_adres" class="form-label"><?= cariLabel('cari_adres', 'Adres') ?></label>
                                <textarea class="form-control" id="cari_adres" name="cari_adres" rows="3"<?= cariNitelik('cari_adres') ?>></textarea>
                            </div>
                            
                            <!-- Yetkili Bilgileri -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">Yetkili Bilgileri</h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="cari_yetkili_adi" class="form-label"><?= cariLabel('cari_yetkili_adi', 'Yetkili Adı') ?></label>
                                <input type="text" class="form-control" id="cari_yetkili_adi" name="cari_yetkili_adi"<?= cariNitelik('cari_yetkili_adi') ?>>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_yetkili_telefon" class="form-label"><?= cariLabel('cari_yetkili_telefon', 'Yetkili Telefon') ?></label>
                                <input type="tel" class="form-control telefon-alani" id="cari_yetkili_telefon" name="cari_yetkili_telefon" placeholder="905001234567"<?= cariNitelik('cari_yetkili_telefon') ?>>
                            </div>

                            <div class="col-md-4">
                                <label for="cari_yetkili_email" class="form-label"><?= cariLabel('cari_yetkili_email', 'Yetkili Email') ?></label>
                                <input type="email" class="form-control" id="cari_yetkili_email" name="cari_yetkili_email"<?= cariNitelik('cari_yetkili_email') ?>>
                            </div>
                            
                            <!-- Cari Tip ve Durum -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2 mb-3 mt-3">Cari Tipi ve Durum</h6>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cari_musteri" name="cari_musteri" value="1">
                                    <label class="form-check-label" for="cari_musteri">
                                        Müşteri
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cari_tedarikci" name="cari_tedarikci" value="1">
                                    <label class="form-check-label" for="cari_tedarikci">
                                        Tedarikçi
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="cari_aktif" name="cari_aktif" value="1" checked>
                                    <label class="form-check-label" for="cari_aktif">
                                        Aktif
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    
    <script>
        let cariModal;
        let cariTable;              // DataTables örneği
        let aktifFiltreler = {};    // Uygulanan filtreler
        let allIlceler = <?= json_encode($ilceler) ?>;

        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            cariModal = new bootstrap.Modal(document.getElementById('cariModal'));
            initCariTable();
            initFiltreler();
            loadStats();
            loadCariList();

            // Form validation
            const forms = document.querySelectorAll('.needs-validation');
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    if (form.checkValidity()) {
                        saveCari();
                    }
                    
                    form.classList.add('was-validated');
                }, false);
            });

            // Telefon alanları: alandan çıkınca 905001234567 biçimine çevir
            document.querySelectorAll('.telefon-alani').forEach(alan => {
                alan.addEventListener('blur', () => {
                    alan.value = telefonNormalize(alan.value);
                });
            });
        });

        // Telefonu 905001234567 biçimine normalize eder (sunucu tarafıyla aynı kural)
        function telefonNormalize(deger) {
            let rakam = (deger || '').replace(/\D+/g, '');
            if (!rakam) return '';
            rakam = rakam.replace(/^0+/, '');
            if (rakam.length === 10) {
                rakam = '90' + rakam;
            } else if (rakam.length > 12 && rakam.substr(-12, 2) === '90') {
                rakam = rakam.slice(-12);
            }
            return rakam;
        }
        
        // İstatistikleri yükle
        function loadStats() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=stats'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('stat-toplam-cari').textContent = data.data.toplam_cari;
                    document.getElementById('stat-aktif-cari').textContent = data.data.aktif_cari;
                    document.getElementById('stat-musteri').textContent = data.data.musteri_sayisi;
                    document.getElementById('stat-tedarikci').textContent = data.data.tedarikci_sayisi;
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // HTML kaçışı (XSS koruması)
        function esc(deger) {
            if (deger === null || deger === undefined) return '';
            return String(deger).replace(/[&<>"']/g, k => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[k]));
        }

        // DataTables kurulumu
        function initCariTable() {
            cariTable = $('#cariTable').DataTable({
                data: [],
                scrollX: true,
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                order: [[0, 'desc']],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                columns: [
                    { data: 'cari_id' },
                    { data: 'cari_adi', render: d => esc(d) },
                    { data: 'cari_unvan', render: d => esc(d) },
                    { data: 'cari_vergi_no', render: d => esc(d) },
                    { data: 'cari_telefon', render: d => esc(d) },
                    { data: 'cari_email', render: d => esc(d) },
                    {
                        data: null,
                        orderable: false,
                        render: function(cari) {
                            let tipBadges = '';
                            if (cari.cari_musteri == 1) tipBadges += '<span class="badge bg-primary me-1">Müşteri</span>';
                            if (cari.cari_tedarikci == 1) tipBadges += '<span class="badge bg-success">Tedarikçi</span>';
                            return tipBadges || '<span class="badge bg-secondary">Belirtilmemiş</span>';
                        }
                    },
                    {
                        data: 'cari_aktif',
                        render: d => d == 1
                            ? '<span class="badge badge-aktif">Aktif</span>'
                            : '<span class="badge badge-pasif">Pasif</span>'
                    },
                    {
                        data: 'cari_id',
                        orderable: false,
                        searchable: false,
                        render: function(id) {
                            return `<a class="btn btn-sm btn-primary" href="/admin/pages/cari-hareketleri.php?cari_id=${id}" title="Cari Hareketleri"><i class="bi bi-journal-text"></i></a>
                                    <a class="btn btn-sm btn-success" href="/admin/pages/odeme-hareket-form.php?cari_id=${id}" title="Tahsilat / Ödeme Gir"><i class="bi bi-cash-coin"></i></a>
                                    <button class="btn btn-sm btn-info" onclick="editCari(${id})" title="Düzenle"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteCari(${id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                        }
                    }
                ]
            });
        }

        // Filtre olayları
        function initFiltreler() {
            $('#filter_tip, #filter_durum, #filter_sehir').select2({ theme: 'bootstrap-5', width: '100%' });

            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                aktifFiltreler = {
                    arama: $('#filter_arama').val(),
                    vergi_no: $('#filter_vergi_no').val(),
                    tip: $('#filter_tip').val(),
                    durum: $('#filter_durum').val(),
                    sehir_id: $('#filter_sehir').val()
                };
                loadCariList();
            });

            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip, #filter_durum, #filter_sehir').val('').trigger('change.select2');
                aktifFiltreler = {};
                loadCariList();
            });
        }

        // Cari listesini yükle
        function loadCariList() {
            const govde = new URLSearchParams({ action: 'list', ...aktifFiltreler });
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: govde.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderCariTable(data.data);
                }
            })
            .catch(error => console.error('Error:', error));
        }

        // Tabloyu render et
        function renderCariTable(cariList) {
            cariTable.clear();
            cariTable.rows.add(cariList || []);
            cariTable.draw();
        }
        
        // Şehir seçildiğinde ilçeleri yükle
        function loadIlceler() {
            const sehirId = document.getElementById('cari_sehirler').value;
            const ilceSelect = document.getElementById('cari_ilceler');
            
            ilceSelect.innerHTML = '<option value="">İlçe Seçiniz...</option>';
            
            if (sehirId) {
                const filteredIlceler = allIlceler.filter(ilce => ilce.SehirId == sehirId);
                filteredIlceler.forEach(ilce => {
                    const option = document.createElement('option');
                    option.value = ilce.ilceId;
                    option.textContent = ilce.IlceAdi;
                    ilceSelect.appendChild(option);
                });
                ilceSelect.disabled = false;
            } else {
                ilceSelect.disabled = true;
                ilceSelect.innerHTML = '<option value="">Önce şehir seçiniz...</option>';
            }
        }
        
        // Modal aç (Yeni)
        function openCariModal() {
            document.getElementById('cariModalLabel').textContent = 'Yeni Cari Ekle';
            document.getElementById('cariForm').reset();
            document.getElementById('cari_id').value = '0';
            document.getElementById('cari_musteri').checked = false;
            document.getElementById('cari_tedarikci').checked = false;
            document.getElementById('cari_aktif').checked = true;
            document.getElementById('cariForm').classList.remove('was-validated');
            document.getElementById('cari_ilceler').disabled = true;
            document.getElementById('cari_ilceler').innerHTML = '<option value="">Önce şehir seçiniz...</option>';
            cariModal.show();
        }
        
        // Cari düzenle
        function editCari(cariId) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get&cari_id=' + cariId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const cari = data.data;
                    document.getElementById('cariModalLabel').textContent = 'Cari Düzenle';
                    document.getElementById('cari_id').value = cari.cari_id;
                    document.getElementById('cari_adi').value = cari.cari_adi || '';
                    document.getElementById('cari_unvan').value = cari.cari_unvan || '';
                    document.getElementById('cari_vergi_dairesi').value = cari.cari_vergi_dairesi || '';
                    document.getElementById('cari_vergi_no').value = cari.cari_vergi_no || '';
                    document.getElementById('cari_mersis_no').value = cari.cari_mersis_no || '';
                    document.getElementById('cari_ticaret_sicil_no').value = cari.cari_ticaret_sicil_no || '';
                    document.getElementById('cari_telefon').value = cari.cari_telefon || '';
                    document.getElementById('cari_email').value = cari.cari_email || '';
                    document.getElementById('cari_adres').value = cari.cari_adres || '';
                    document.getElementById('cari_posta_kodu').value = cari.cari_posta_kodu || '';
                    document.getElementById('cari_yetkili_adi').value = cari.cari_yetkili_adi || '';
                    document.getElementById('cari_yetkili_telefon').value = cari.cari_yetkili_telefon || '';
                    document.getElementById('cari_yetkili_email').value = cari.cari_yetkili_email || '';
                    
                    // Şehir ve İlçe seçimlerini ayarla
                    if (cari.cari_sehirler) {
                        document.getElementById('cari_sehirler').value = cari.cari_sehirler;
                        loadIlceler(); // İlçeleri yükle
                        
                        // İlçe seçimini ayarla
                        setTimeout(() => {
                            if (cari.cari_ilceler) {
                                document.getElementById('cari_ilceler').value = cari.cari_ilceler;
                            }
                        }, 100);
                    }
                    
                    // Cari tipi ve durum
                    document.getElementById('cari_musteri').checked = cari.cari_musteri == 1;
                    document.getElementById('cari_tedarikci').checked = cari.cari_tedarikci == 1;
                    document.getElementById('cari_aktif').checked = cari.cari_aktif == 1;
                    document.getElementById('cariForm').classList.remove('was-validated');
                    cariModal.show();
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // Cari kaydet
        function saveCari() {
            const formData = new FormData(document.getElementById('cariForm'));
            formData.append('action', 'save');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    cariModal.hide();
                    loadStats();
                    loadCariList();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Bir hata oluştu!', 'error');
            });
        }
        
        // Cari sil
        function deleteCari(cariId) {
            confirmAction(
                'Bu cariyi silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    fetch('', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=delete&cari_id=' + cariId
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            loadStats();
                            loadCariList();
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
    </script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- AdminLTE JS -->
    <script src="/admin/assets/js/adminlte.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/admin/assets/js/custom.js"></script>
</body>
</html>
