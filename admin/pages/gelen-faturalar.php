<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();
$user = Auth::user(); $db = Database::getInstance();
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) PageAuth::accessDenied();
$pageInfo = $db->fetchOne("SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi as menu_adi FROM tanim_sayfalar s LEFT JOIN tanim_menuler m ON s.sayfalar_menu_id = m.menuler_id WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1", ['%' . $currentPageFile]);
$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Gelen Faturalar';
$menuAdi = $pageInfo['menu_adi'] ?? 'Faturalar';
$siteTitle = ($db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC"))['site_ayarlari_site_title'] ?? 'Portal';
$VON = 'GELEN';
$ENTEGRASYON_AD = 'ÖRNEK HOLDİNG';
$kanalSub = "SELECT k2.EntegrasyonKanallari_id FROM EntegrasyonKanallari k2 INNER JOIN Entegrasyonlar e2 ON k2.EntegrasyonKanallari_Entegrasyonlar_id=e2.Entegrasyonlar_id WHERE e2.Entegrasyonlar_Ad=?";

// Fatura görselini PDF olarak servis et (yeni sekmede açılır)
if (($_GET['action'] ?? '') === 'pdf') {
    require_once __DIR__ . '/../includes/OrnekHoldingApi.php';
    $id = intval($_GET['id'] ?? 0);
    $f  = $db->fetchOne("SELECT Faturalar_ETTN,Faturalar_FaturaNo,Faturalar_Tur,Faturalar_Yon,Faturalar_EntegrasyonKanallari_id FROM Faturalar WHERE Faturalar_id=? AND Faturalar_Yon='$VON' AND Faturalar_EntegrasyonKanallari_id IN ($kanalSub)", [$id, $ENTEGRASYON_AD]);
    if (!$f || empty($f['Faturalar_ETTN'])) { http_response_code(404); exit('Fatura bulunamadı.'); }

    $kanal = null;
    foreach (OrnekHoldingApi::aktifKanallar($db) as $k) {
        if ((int)$k['EntegrasyonKanallari_id'] === (int)$f['Faturalar_EntegrasyonKanallari_id']) { $kanal = $k; break; }
    }
    if (!$kanal) { http_response_code(404); exit('Faturanın firma kanalı aktif değil.'); }

    $api    = new OrnekHoldingApi($kanal, $user['kullanici_id']);
    $sonuc  = $f['Faturalar_Tur'] === 'EARSIV'
        ? $api->earsivPdf($f['Faturalar_ETTN'])
        : $api->faturaPdf($f['Faturalar_ETTN'], $f['Faturalar_Yon'] === 'GIDEN' ? 'OUT' : 'IN');

    if (!$sonuc['success'] || empty($sonuc['pdf'])) {
        http_response_code(502);
        exit('PDF alınamadı: ' . ($sonuc['message'] ?: 'bilinmeyen hata'));
    }

    while (ob_get_level()) { ob_end_clean(); }
    $ad = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $f['Faturalar_FaturaNo'] ?: $f['Faturalar_ETTN']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $ad . '"');
    header('Content-Length: ' . strlen($sonuc['pdf']));
    echo $sonuc['pdf'];
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'stats') {
            $b = "FROM Faturalar WHERE Faturalar_Yon = '$VON' AND Faturalar_Durum = 1 AND Faturalar_EntegrasyonKanallari_id IN ($kanalSub)";
            $bp = [$ENTEGRASYON_AD];
            echo json_encode(['success'=>true,'data'=>['toplam'=>$db->fetchOne("SELECT COUNT(*) as s $b",$bp)['s']??0,'efatura'=>$db->fetchOne("SELECT COUNT(*) as s $b AND Faturalar_Tur='EFATURA'",$bp)['s']??0,'earsiv'=>$db->fetchOne("SELECT COUNT(*) as s $b AND Faturalar_Tur='EARSIV'",$bp)['s']??0,'iptal'=>$db->fetchOne("SELECT COUNT(*) as s $b AND Faturalar_IptalDurumu=1",$bp)['s']??0,'tutar'=>$db->fetchOne("SELECT ISNULL(SUM(Faturalar_OdenecekTutar),0) as s $b AND Faturalar_IptalDurumu=0",$bp)['s']??0]]);
        } elseif ($action === 'list') {
            $w = ["f.Faturalar_Yon='$VON'","f.Faturalar_Durum=1","f.Faturalar_EntegrasyonKanallari_id IN ($kanalSub)"]; $p = [$ENTEGRASYON_AD];
            if (!empty($_POST['search'])) { $s='%'.$_POST['search'].'%'; $w[]="(f.Faturalar_FaturaNo LIKE ? OR f.Faturalar_CariUnvan LIKE ? OR f.Faturalar_ETTN LIKE ? OR f.Faturalar_CariVKN LIKE ?)"; array_push($p,$s,$s,$s,$s); }
            if (!empty($_POST['tur']))       { $w[]="f.Faturalar_Tur=?"; $p[]=$_POST['tur']; }
            if (!empty($_POST['gib_durum'])) { $w[]="f.Faturalar_GibDurum=?"; $p[]=$_POST['gib_durum']; }
            if (($_POST['iptal'] ?? '') === 'IPTAL')   { $w[]="f.Faturalar_IptalDurumu=1"; }
            if (($_POST['iptal'] ?? '') === 'GECERLI') { $w[]="f.Faturalar_IptalDurumu=0"; }
            if (!empty($_POST['tarih_bas'])) { $w[]="f.Faturalar_Tarih>=?"; $p[]=$_POST['tarih_bas']; }
            if (!empty($_POST['tarih_bit'])) { $w[]="f.Faturalar_Tarih<=?"; $p[]=$_POST['tarih_bit']; }
            if (!empty($_POST['kanal_id']))  { $w[]="f.Faturalar_EntegrasyonKanallari_id=?"; $p[]=$_POST['kanal_id']; }
            if (($_POST['cari_bag'] ?? '') === 'BAGLI')  { $w[]="f.Faturalar_Cari_id IS NOT NULL"; }
            if (($_POST['cari_bag'] ?? '') === 'BAGSIZ') { $w[]="f.Faturalar_Cari_id IS NULL"; }
            if (!empty($_POST['cari_id'])) { $w[]="f.Faturalar_Cari_id=?"; $p[]=intval($_POST['cari_id']); }
            $wc = implode(' AND ',$w);
            $data = $db->fetchAll("SELECT f.Faturalar_id,f.Faturalar_FaturaNo,f.Faturalar_ETTN,CONVERT(VARCHAR(10),f.Faturalar_Tarih,23) as Faturalar_Tarih,f.Faturalar_Tur,f.Faturalar_CariUnvan,f.Faturalar_CariVKN,f.Faturalar_OdenecekTutar,f.Faturalar_ParaBirimi,f.Faturalar_GibDurum,f.Faturalar_IptalDurumu,f.Faturalar_YanitAciklama,k.EntegrasyonKanallari_Ad as KanalAd,f.Faturalar_Cari_id,c.cari_adi as CariKartAdi FROM Faturalar f LEFT JOIN EntegrasyonKanallari k ON f.Faturalar_EntegrasyonKanallari_id=k.EntegrasyonKanallari_id LEFT JOIN Cari c ON f.Faturalar_Cari_id=c.cari_id WHERE $wc ORDER BY f.Faturalar_Tarih DESC,f.Faturalar_id DESC",$p);
            echo json_encode(['success'=>true,'data'=>$data,'count'=>count($data)]);
        } elseif ($action === 'cari_ata') {
            $faturaId = intval($_POST['fatura_id'] ?? 0);
            $cariId   = intval($_POST['cari_id'] ?? 0);
            if (!$faturaId) { echo json_encode(['success'=>false,'message'=>'Fatura bulunamadı.']); exit; }
            if ($cariId && !$db->fetchOne("SELECT cari_id FROM Cari WHERE cari_id=?", [$cariId])) {
                echo json_encode(['success'=>false,'message'=>'Cari bulunamadı.']); exit;
            }
            $db->execute("UPDATE Faturalar SET Faturalar_Cari_id=?, Faturalar_GuncelleyenKullanici=?, Faturalar_GuncellemeTarihi=? WHERE Faturalar_id=?",
                [$cariId ?: null, $user['kullanici_id'], date('Y-m-d H:i:s'), $faturaId]);
            echo json_encode(['success'=>true,'message'=>$cariId ? 'Fatura cariye bağlandı.' : 'Cari bağı kaldırıldı.']);
        } elseif ($action === 'guncelle') {
            // Yalnız son 24 saatin faturaları çekilir
            set_time_limit(0);
            require_once __DIR__ . '/../cron/ornekholding-senkron.php';
            $senkron = new OrnekHoldingSenkron(['gun'=>1,'dilim'=>1,'yon'=>$VON,'sessiz'=>1]);
            $senkron->calistir();
            $o = $senkron->getOzet();
            echo json_encode(['success'=>true,'message'=>"Son 24 saat: {$o['yeni']} yeni, {$o['guncellenen']} güncellenen fatura."]);
        } elseif ($action === 'get') {
            $id = intval($_POST['id']??0);
            $data = $db->fetchOne("SELECT f.*,CONVERT(VARCHAR(10),f.Faturalar_Tarih,23) as Faturalar_Tarih,k.EntegrasyonKanallari_Ad as KanalAd FROM Faturalar f LEFT JOIN EntegrasyonKanallari k ON f.Faturalar_EntegrasyonKanallari_id=k.EntegrasyonKanallari_id WHERE f.Faturalar_id=? AND f.Faturalar_EntegrasyonKanallari_id IN ($kanalSub)",[$id,$ENTEGRASYON_AD]);
            $kalemler = $db->fetchAll("SELECT * FROM FaturaKalemleri WHERE FaturaKalemleri_Faturalar_id=? ORDER BY FaturaKalemleri_SiraNo",[$id]);
            echo json_encode(['success'=>true,'data'=>$data,'kalemler'=>$kalemler]);
        }
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}
$kanallar = $db->fetchAll("SELECT k.EntegrasyonKanallari_id,k.EntegrasyonKanallari_Ad FROM EntegrasyonKanallari k INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id=e.Entegrasyonlar_id WHERE k.EntegrasyonKanallari_Durum=1 AND e.Entegrasyonlar_Ad=? ORDER BY k.EntegrasyonKanallari_Ad",[$ENTEGRASYON_AD]);
$cariListesi = $db->fetchAll("SELECT cari_id,cari_adi,cari_vergi_no FROM Cari WHERE cari_aktif=1 ORDER BY cari_adi");
$gibDurumlar = $db->fetchAll("SELECT DISTINCT Faturalar_GibDurum FROM Faturalar WHERE Faturalar_GibDurum IS NOT NULL AND Faturalar_Yon='$VON' AND Faturalar_EntegrasyonKanallari_id IN ($kanalSub) ORDER BY Faturalar_GibDurum",[$ENTEGRASYON_AD]);
?>
<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?=$pageTitle?> - <?=$siteTitle?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
<link rel="stylesheet" href="/admin/assets/css/custom.css">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary"><div class="app-wrapper">
<?php include __DIR__.'/../includes/header.php'; ?>
<?php include __DIR__.'/../includes/sidebar.php'; ?>
<main class="app-main">
<div class="app-content-header"><div class="container-fluid"><div class="row">
<div class="col-sm-6"><h1 class="mb-0"><?=htmlspecialchars($pageTitle)?></h1></div>
<div class="col-sm-6"><ol class="breadcrumb float-sm-end"><li class="breadcrumb-item"><?=htmlspecialchars($menuAdi)?></li><li class="breadcrumb-item active"><?=htmlspecialchars($pageTitle)?></li></ol></div>
</div></div></div>
<div class="app-content"><div class="container-fluid">
<div class="row mb-3">
<div class="col-12 col-sm-6 col-xl"><div class="info-box"><span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-receipt-cutoff"></i></span><div class="info-box-content"><span class="info-box-text">Toplam</span><span class="info-box-number" id="stat-toplam">0</span></div></div></div>
<div class="col-12 col-sm-6 col-xl"><div class="info-box"><span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-file-earmark-check"></i></span><div class="info-box-content"><span class="info-box-text">E-Fatura</span><span class="info-box-number" id="stat-efatura">0</span></div></div></div>
<div class="col-12 col-sm-6 col-xl"><div class="info-box"><span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-archive"></i></span><div class="info-box-content"><span class="info-box-text">E-Arşiv</span><span class="info-box-number" id="stat-earsiv">0</span></div></div></div>
<div class="col-12 col-sm-6 col-xl"><div class="info-box"><span class="info-box-icon text-bg-dark shadow-sm"><i class="bi bi-x-octagon"></i></span><div class="info-box-content"><span class="info-box-text">İptal Edilmiş</span><span class="info-box-number" id="stat-iptal">0</span></div></div></div>
<div class="col-12 col-sm-6 col-xl"><div class="info-box"><span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-currency-dollar"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Tutar <small class="text-muted">(iptal hariç)</small></span><span class="info-box-number" id="stat-tutar">₺0</span></div></div></div>
</div>
<div class="card card-primary card-outline mb-3 collapse show" id="filterCard"><div class="card-header"><h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3></div><div class="card-body">
<form id="filterForm"><div class="row g-2">
<div class="col-md-3"><label class="form-label">Ara</label><input type="text" class="form-control" name="search" id="filter_search" placeholder="Fatura No / Cari / VKN-TC / ETTN..."></div>
<div class="col-md-2"><label class="form-label">Cari Bağı</label><select class="form-select" name="cari_bag" id="filter_cari_bag"><option value="">Tümü</option><option value="BAGLI">Cariye bağlı</option><option value="BAGSIZ">Bağlanmamış</option></select></div>
<div class="col-md-3"><label class="form-label">Cari Kart</label><select class="form-select" name="cari_id" id="filter_cari_id"><option value="">Tümü</option><?php foreach($cariListesi as $c): ?><option value="<?=$c['cari_id']?>"><?=htmlspecialchars($c['cari_adi'])?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Tür</label><select class="form-select" name="tur" id="filter_tur"><option value="">Tümü</option><option value="EFATURA">E-Fatura</option><option value="EARSIV">E-Arşiv</option></select></div>
<div class="col-md-2"><label class="form-label">İptal Durumu</label><select class="form-select" name="iptal" id="filter_iptal"><option value="">Tümü</option><option value="GECERLI">Geçerli</option><option value="IPTAL">İptal edilmiş</option></select></div>
<div class="col-md-2"><label class="form-label">GİB Durum</label><select class="form-select" name="gib_durum" id="filter_gib_durum"><option value="">Tümü</option><?php foreach($gibDurumlar as $g): ?><option value="<?=htmlspecialchars($g['Faturalar_GibDurum'])?>"><?=htmlspecialchars($g['Faturalar_GibDurum'])?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Firma</label><select class="form-select" name="kanal_id" id="filter_kanal"><option value="">Tümü</option><?php foreach($kanallar as $k): ?><option value="<?=$k['EntegrasyonKanallari_id']?>"><?=htmlspecialchars($k['EntegrasyonKanallari_Ad'])?></option><?php endforeach; ?></select></div>
<div class="col-md-1"><label class="form-label">Baş.</label><input type="date" class="form-control" name="tarih_bas" id="filter_tarih_bas"></div>
<div class="col-md-1"><label class="form-label">Bit.</label><input type="date" class="form-control" name="tarih_bit" id="filter_tarih_bit"></div>
<div class="col-12"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filtrele</button> <button type="button" class="btn btn-secondary btn-sm" id="clearFilters"><i class="bi bi-x"></i> Temizle</button></div>
</div></form>
</div></div>
<div class="card card-primary card-outline"><div class="card-header"><h3 class="card-title"><i class="bi bi-box-arrow-in-down"></i> Gelen Faturalar</h3><div class="card-tools"><button class="btn btn-sm btn-success me-1" id="btnGuncelle" title="Son 24 saatin faturalarını Örnek Holding'den çeker"><i class="bi bi-arrow-repeat"></i> Güncelle</button><button class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard"><i class="bi bi-funnel"></i> Filtrele</button></div></div>
<div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped table-hover table-sm" id="dataTable">
<thead><tr><th>Fatura No</th><th>Tarih</th><th>Tür</th><th>Cari Ünvan</th><th>VKN</th><th class="text-end">Tutar</th><th>Birim</th><th>Cari Kart</th><th>GİB</th><th>Firma</th><th>İşlem</th></tr></thead>
<tbody id="tableBody"><tr><td colspan="11" class="text-center">Yükleniyor...</td></tr></tbody>
</table></div><div id="listInfo" class="text-muted small mt-1"></div></div></div>
</div></div></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
</div>
<div class="modal fade" id="detayModal" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title"><i class="bi bi-receipt"></i> Fatura Detayı</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="detayIcerik"><div class="text-center p-4"><div class="spinner-border"></div></div></div>
</div></div></div>
<div class="modal fade" id="cariAtaModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title"><i class="bi bi-link-45deg"></i> Cari Kart Ata</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><input type="hidden" id="ca_fatura_id">
<div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Fatura bu cari karta baglanir ve Cari Hareketleri ekstresinde gorunur. Bos birakilirsa mevcut bag kaldirilir.</div>
<label class="form-label">Cari Kart</label>
<select class="form-select" id="ca_cari_id"><option value="">Bag yok</option><?php foreach($cariListesi as $c): ?><option value="<?=$c['cari_id']?>"><?=htmlspecialchars($c['cari_adi'])?><?=$c['cari_vergi_no']?' ('.htmlspecialchars($c['cari_vergi_no']).')':''?></option><?php endforeach; ?></select>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Iptal</button><button class="btn btn-primary" onclick="cariAtaKaydet()"><i class="bi bi-check2"></i> Kaydet</button></div>
</div></div></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script>
let cf={}, detayModal=new bootstrap.Modal(document.getElementById('detayModal')), cariAtaModal=new bootstrap.Modal(document.getElementById('cariAtaModal'));
$(function(){
    $('#filter_tur,#filter_iptal,#filter_gib_durum,#filter_kanal,#filter_cari_bag,#filter_cari_id').select2({theme:'bootstrap-5',allowClear:true});
    // Modal içindeki liste, pencerenin kendi içinde açılmalı (arama kutusu odağı için)
    $('#ca_cari_id').select2({theme:'bootstrap-5',width:'100%',dropdownParent:$('#cariAtaModal')});
    loadStats(); loadList();
    $('#btnGuncelle').on('click',guncelle);
    $('#filterForm').on('submit',function(e){e.preventDefault();cf={search:$('#filter_search').val(),cari_bag:$('#filter_cari_bag').val(),cari_id:$('#filter_cari_id').val(),tur:$('#filter_tur').val(),iptal:$('#filter_iptal').val(),gib_durum:$('#filter_gib_durum').val(),kanal_id:$('#filter_kanal').val(),tarih_bas:$('#filter_tarih_bas').val(),tarih_bit:$('#filter_tarih_bit').val()};loadList();});
    $('#clearFilters').on('click',function(){$('#filterForm')[0].reset();$('#filter_tur,#filter_iptal,#filter_gib_durum,#filter_kanal,#filter_cari_bag,#filter_cari_id').val('').trigger('change.select2');cf={};loadList();});
});
function loadStats(){$.post('',{action:'stats'},function(r){if(r.success){$('#stat-toplam').text(r.data.toplam);$('#stat-efatura').text(r.data.efatura);$('#stat-earsiv').text(r.data.earsiv);$('#stat-iptal').text(r.data.iptal);$('#stat-tutar').text(fmt(r.data.tutar));}})}
function loadList(){
    $('#tableBody').html('<tr><td colspan="11" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>');
    $.post('',{action:'list',...cf},function(r){
        const tb=$('#tableBody'); tb.empty(); $('#listInfo').text(r.success&&r.count?'Toplam '+r.count+' kayıt':'');
        if(r.success&&r.data.length){r.data.forEach(f=>{
            const tur=f.Faturalar_Tur==='EFATURA'?'<span class="badge bg-primary">E-Fatura</span>':'<span class="badge bg-info">E-Arşiv</span>';
            const iptal=Number(f.Faturalar_IptalDurumu)===1;
            // İptal faturada GİB durumu "BAŞARIYLA TAMAMLANDI" kalabildiği için
            // iptal rozeti ayrıca gösterilir, satır soluk ve üstü çizili yazılır.
            const gib=iptal
                ?`<span class="badge bg-dark" title="${esc(f.Faturalar_YanitAciklama||'İptal edilmiş fatura')}"><i class="bi bi-x-octagon"></i> İPTAL</span>`
                :(f.Faturalar_GibDurum?`<span class="badge bg-${gc(f.Faturalar_GibDurum)}">${f.Faturalar_GibDurum}</span>`:'<span class="badge bg-secondary">-</span>');
            const trCls=iptal?' class="table-secondary text-decoration-line-through"':'';
            tb.append(`<tr${trCls}><td><small class="fw-bold">${f.Faturalar_FaturaNo||'-'}</small></td><td>${f.Faturalar_Tarih||'-'}</td><td>${tur}</td>${unvanHucre(f.Faturalar_CariUnvan)}<td><small>${f.Faturalar_CariVKN||'-'}</small></td><td class="text-end fw-bold">${fmt(f.Faturalar_OdenecekTutar)}</td><td>${f.Faturalar_ParaBirimi||'TRY'}</td><td>${cariHucre(f)}</td><td>${gib}</td><td><small>${f.KanalAd||'-'}</small></td><td class="text-nowrap text-decoration-none"><button class="btn btn-sm btn-outline-primary" title="Detay" onclick="showDetay(${f.Faturalar_id})"><i class="bi bi-eye"></i></button> <a class="btn btn-sm btn-outline-danger" title="PDF Fatura" href="?action=pdf&id=${f.Faturalar_id}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i></a></td></tr>`);
        });}else{tb.html('<tr><td colspan="11" class="text-center text-muted">Kayıt bulunamadı</td></tr>');}
    });
}
function showDetay(id){
    $('#detayIcerik').html('<div class="text-center p-4"><div class="spinner-border"></div></div>');
    detayModal.show();
    $.post('',{action:'get',id:id},function(r){
        if(!r.success||!r.data){$('#detayIcerik').html('<p class="text-danger">Kayıt bulunamadı!</p>');return;}
        const f=r.data;
        let kh='<p class="text-muted small">Kalem bilgisi bulunamadı.</p>';
        if(r.kalemler&&r.kalemler.length){kh=`<table class="table table-sm table-bordered"><thead class="table-light"><tr><th>#</th><th>Ürün</th><th>Miktar</th><th>Birim</th><th class="text-end">B.Fiyat</th><th class="text-end">KDV%</th><th class="text-end">KDV</th><th class="text-end">Toplam</th></tr></thead><tbody>${r.kalemler.map(k=>`<tr><td>${k.FaturaKalemleri_SiraNo||'-'}</td><td>${k.FaturaKalemleri_UrunAdi||'-'}</td><td>${k.FaturaKalemleri_Miktar||0}</td><td>${k.FaturaKalemleri_Birim||'-'}</td><td class="text-end">${fmt(k.FaturaKalemleri_BirimFiyati)}</td><td class="text-end">%${k.FaturaKalemleri_KdvOrani||0}</td><td class="text-end">${fmt(k.FaturaKalemleri_KdvTutari)}</td><td class="text-end fw-bold">${fmt(k.FaturaKalemleri_ToplamTutar)}</td></tr>`).join('')}</tbody></table>`;}
        const iptalUyari=Number(f.Faturalar_IptalDurumu)===1
            ?`<div class="alert alert-dark py-2 mb-0"><i class="bi bi-x-octagon"></i> <strong>Bu fatura iptal edilmiştir.</strong>${f.Faturalar_YanitAciklama?' '+esc(f.Faturalar_YanitAciklama):''}</div>`
            :'';
        $('#detayIcerik').html(`<div class="row g-3">${iptalUyari?'<div class="col-12">'+iptalUyari+'</div>':''}<div class="col-md-6"><table class="table table-sm table-borderless"><tr><th width="140">Fatura No</th><td><strong>${f.Faturalar_FaturaNo||'-'}</strong></td></tr><tr><th>ETTN</th><td><small>${f.Faturalar_ETTN||'-'}</small></td></tr><tr><th>Tarih</th><td>${f.Faturalar_Tarih||'-'}</td></tr><tr><th>Tür</th><td>${f.Faturalar_Tur||'-'}</td></tr><tr><th>Senaryo</th><td>${f.Faturalar_Senaryo||'-'}</td></tr><tr><th>Firma</th><td>${f.KanalAd||'-'}</td></tr><tr><th>GİB Durum</th><td>${f.Faturalar_GibDurum||'-'}${f.Faturalar_GibDurumKodu?' <small class="text-muted">('+esc(f.Faturalar_GibDurumKodu)+')</small>':''}</td></tr><tr><th>Zarf No</th><td><small>${f.Faturalar_ZarfId||'-'}</small></td></tr></table></div><div class="col-md-6"><table class="table table-sm table-borderless"><tr><th width="140">Cari Ünvan</th><td>${f.Faturalar_CariUnvan||'-'}</td></tr><tr><th>VKN/TCKN</th><td>${f.Faturalar_CariVKN||'-'}</td></tr><tr><th>Vergi Dairesi</th><td>${f.Faturalar_CariVergiDairesi||'-'}</td></tr><tr><th>Mal/Hizmet</th><td>${fmt(f.Faturalar_MalHizmetTutari)}</td></tr><tr><th>İndirim</th><td>${fmt(f.Faturalar_IndirimTutari)}</td></tr><tr><th>KDV</th><td>${fmt(f.Faturalar_VergiTutari)}</td></tr><tr><th>Ödenecek</th><td><strong class="text-success">${fmt(f.Faturalar_OdenecekTutar)} ${f.Faturalar_ParaBirimi||'TRY'}</strong></td></tr></table></div><div class="col-12"><h6 class="border-bottom pb-1"><i class="bi bi-list-ul"></i> Fatura Kalemleri</h6>${kh}</div></div>`);
    });
}
function cariHucre(f){
    if(f.Faturalar_Cari_id) return `<button class="btn btn-sm btn-outline-success text-nowrap" title="${esc(f.CariKartAdi||'')} — cari kartını değiştir" onclick="cariAtaAc(${f.Faturalar_id},${f.Faturalar_Cari_id})"><i class="bi bi-link-45deg"></i> Cari Bağlandı</button>`;
    return `<button class="btn btn-sm btn-outline-warning text-nowrap" onclick="cariAtaAc(${f.Faturalar_id},0)"><i class="bi bi-link-45deg"></i> Cari Ata</button>`;
}
// Cari Ünvan: tek satır, en fazla 20 karakter; tamamı tooltip'te
function unvanHucre(unvan){
    const t=(unvan||'').trim();
    if(!t) return '<td>-</td>';
    const kisa=t.length>20?t.substring(0,20)+'…':t;
    return `<td class="text-nowrap" title="${esc(t)}">${esc(kisa)}</td>`;
}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function guncelle(){
    const b=$('#btnGuncelle'), eski=b.html();
    b.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span> Güncelleniyor...');
    $.post('',{action:'guncelle'},function(r){
        if(r.success){showToast(r.message,'success');loadStats();loadList();}
        else showError('Hata',r.message||'Güncelleme başarısız.');
    },'json').fail(function(){showError('Hata','Güncelleme sırasında bağlantı hatası oluştu.');})
     .always(function(){b.prop('disabled',false).html(eski);});
}
function cariAtaAc(faturaId,cariId){
    $('#ca_fatura_id').val(faturaId);
    $('#ca_cari_id').val(cariId||'').trigger('change');
    cariAtaModal.show();
}
function cariAtaKaydet(){
    $.post('',{action:'cari_ata',fatura_id:$('#ca_fatura_id').val(),cari_id:$('#ca_cari_id').val()||0},function(r){
        if(!r.success){showError('Hata',r.message);return;}
        showToast(r.message,'success'); cariAtaModal.hide(); loadList();
    },'json');
}
function gc(d){if(!d)return'secondary';d=d.toUpperCase();if(d.includes('ONAY'))return'success';if(d.includes('RED')||d.includes('IPTAL'))return'danger';if(d.includes('BEKLE'))return'warning';return'secondary';}
function fmt(v){return new Intl.NumberFormat('tr-TR',{style:'currency',currency:'TRY',minimumFractionDigits:2}).format(v||0);}
</script></body></html>