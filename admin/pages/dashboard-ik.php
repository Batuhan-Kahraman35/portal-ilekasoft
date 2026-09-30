<?php
/**
 * Admin Panel - İnsan Kaynakları Dashboard
 * Departman ID: 7 (İnsan Kaynakları)
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 */

// $user ve $db değişkenleri anasayfa.php'den geliyor

// İK kontrolü (ekstra güvenlik)
if ($user['departman_id'] != 7) {
    // Yanlış departmansa default dashboard'u include et
    include __DIR__ . '/dashboard.php';
    return;
}

// Site ayarlarını çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// İK istatistikleri (personel, puantaj, izin)
$stats = [
    'toplam_personel' => $db->fetchOne("SELECT COUNT(*) as total FROM kullanicilar WHERE kullanici_durum = 1")['total'] ?? 0,
    'aktif_personel' => $db->fetchOne("SELECT COUNT(*) as total FROM kullanicilar WHERE kullanici_durum = 1")['total'] ?? 0,
    'puantaj_bugun' => $db->fetchOne("
        SELECT COUNT(*) as total 
        FROM personel_gelmeyenler 
        WHERE CONVERT(date, prsg_tarih) = CONVERT(date, GETDATE())
    ")['total'] ?? 0,
    'izin_durumlari' => $db->fetchOne("SELECT COUNT(DISTINCT izin_durum_id) as total FROM personel_izin_durumlari")['total'] ?? 0,
];

// Bugün gelmeyenler
$bugunGelmeyenler = $db->fetchAll("
    SELECT TOP 10
        k.kullanici_ad + ' ' + k.kullanici_soyad as ad_soyad,
        id.izin_durum_adi,
        pg.prsg_tarih
    FROM personel_gelmeyenler pg
    INNER JOIN kullanicilar k ON k.kullanici_id = pg.prsg_kullanici_id
    INNER JOIN personel_izin_durumlari id ON id.izin_durum_id = pg.prsg_izin_durum_id
    WHERE CONVERT(date, pg.prsg_tarih) = CONVERT(date, GETDATE())
    ORDER BY pg.prsg_id DESC
");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İnsan Kaynakları Dashboard - <?= htmlspecialchars($siteTitle) ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
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
                            <h3 class="mb-0"><i class="bi bi-people-fill me-2"></i>İnsan Kaynakları Dashboard</h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item active">Ana Sayfa</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    <!-- Welcome Card -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card text-white" style="background: linear-gradient(135deg, #28a745 0%, #218838 100%);">
                                <div class="card-body">
                                    <h4 class="card-title mb-2"><i class="bi bi-person-badge-fill me-2"></i>Hoş Geldiniz, İK Departmanı!</h4>
                                    <p class="card-text mb-0">Personel yönetimi ve puantaj takibi. <?= htmlspecialchars($user['name']) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="row mb-3">
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-primary">
                                <div class="inner">
                                    <h3><?= $stats['toplam_personel'] ?></h3>
                                    <p>Toplam Personel</p>
                                </div>
                                <div class="small-box-icon">
                                    <i class="bi bi-people-fill"></i>
                                </div>
                                <a href="/admin/personel-yonetimi" class="small-box-footer">
                                    Detaylar <i class="bi bi-arrow-right-circle-fill"></i>
                                </a>
                            </div>
                        </div>
                        
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3><?= $stats['aktif_personel'] ?></h3>
                                    <p>Aktif Personel</p>
                                </div>
                                <div class="small-box-icon">
                                    <i class="bi bi-person-check-fill"></i>
                                </div>
                                <a href="/admin/personel-yonetimi" class="small-box-footer">
                                    Detaylar <i class="bi bi-arrow-right-circle-fill"></i>
                                </a>
                            </div>
                        </div>
                        
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-warning">
                                <div class="inner">
                                    <h3><?= $stats['puantaj_bugun'] ?></h3>
                                    <p>Bugün İzinli/Gelmedi</p>
                                </div>
                                <div class="small-box-icon">
                                    <i class="bi bi-calendar-x"></i>
                                </div>
                                <a href="/admin/personel-puantaj" class="small-box-footer">
                                    Detaylar <i class="bi bi-arrow-right-circle-fill"></i>
                                </a>
                            </div>
                        </div>
                        
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-info">
                                <div class="inner">
                                    <h3><?= $stats['izin_durumlari'] ?></h3>
                                    <p>İzin Durumu Tipleri</p>
                                </div>
                                <div class="small-box-icon">
                                    <i class="bi bi-clipboard-check"></i>
                                </div>
                                <a href="/admin/personel-izin-durumlari" class="small-box-footer">
                                    Detaylar <i class="bi bi-arrow-right-circle-fill"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <?php include __DIR__ . '/../includes/dogum-gunu-karti.php'; ?>

                    <!-- Bugün Gelmeyenler -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-warning">
                                    <h3 class="card-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Bugün Gelmeyenler / İzinli Olanlar</h3>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($bugunGelmeyenler)): ?>
                                        <div class="p-3 text-center text-muted">
                                            <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                                            <p class="mt-2">Bugün tüm personel işbaşında!</p>
                                        </div>
                                    <?php else: ?>
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Ad Soyad</th>
                                                    <th>Durum</th>
                                                    <th>Tarih</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($bugunGelmeyenler as $gelmeyen): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($gelmeyen['ad_soyad']) ?></td>
                                                    <td>
                                                        <span class="badge bg-warning">
                                                            <?= htmlspecialchars($gelmeyen['izin_durum_adi']) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= date('d.m.Y', strtotime($gelmeyen['prsg_tarih'])) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
</body>
</html>
