<?php
/**
 * Admin Panel - Personel Puantaj Hesaplama
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Personel Puantaj Hesaplama';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Soft Portal';

// İzin durumlarını çek (dinamik kolonlar için)
$izinDurumlari = $db->fetchAll("
    SELECT izin_durum_id, izin_durum_adi, izin_durum_kod, izin_durum_renk, izin_durum_gun_carpani 
    FROM tanim_izin_durumlari 
    WHERE izin_durum_durum = 1 
    ORDER BY izin_durum_sira_no, izin_durum_adi
");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'stats') {
            $stats = [
                'toplam_personel' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum = 1")['sayi'] ?? 0,
                'puantaj_var' => $db->fetchOne("SELECT COUNT(DISTINCT puantaj_kullanici_id) as sayi FROM Personel_Puantaj WHERE puantaj_aktif = 1")['sayi'] ?? 0,
                'toplam_maas' => $db->fetchOne("SELECT ISNULL(SUM(kullanici_maas), 0) as toplam FROM kullanicilar WHERE kullanici_durum = 1 AND kullanici_maas IS NOT NULL")['toplam'] ?? 0,
                'bekleyen' => $db->fetchOne("SELECT COUNT(*) as sayi FROM kullanicilar WHERE kullanici_durum = 1 AND kullanici_puantaj_donem_id IS NULL")['sayi'] ?? 0
            ];
            
            echo json_encode(['success' => true, 'data' => $stats]);
            exit;
        }
        
        if ($action === 'save_bes') {
            $kullanici_id = intval($_POST['kullanici_id'] ?? 0);
            $tutar = floatval($_POST['tutar'] ?? 0);
            $tarih = $_POST['tarih'] ?? date('Y-m-d');
            
            if (!$kullanici_id || $tutar <= 0) {
                echo json_encode(['success' => false, 'message' => 'Geçersiz bilgiler']);
                exit;
            }
            
            // BES kaydı ekle (odeme_hareket_tip_id = 4)
            $data = [
                'odeme_hareket_tip_id' => 4, // BES
                'odeme_hareket_kullanici_id' => $kullanici_id,
                'odeme_hareket_tarih' => $tarih,
                'odeme_hareket_tutar' => $tutar,
                'odeme_hareket_aciklama' => 'BES Katkısı',
                'odeme_hareket_durum' => 1,
                'odeme_hareket_olusturan_kullanici_id' => $user['kullanici_id'],
                'odeme_hareket_olusturma_tarihi' => date('Y-m-d H:i:s')
            ];
            
            $result = $db->insert('Odeme_Hareketleri', $data);
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'BES kaydı başarıyla eklendi']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Kayıt eklenirken hata oluştu']);
            }
            exit;
        }
        
        if ($action === 'list') {
            // Filtreleri al
            $donem_id = $_POST['donem_id'] ?? '';
            $firma_id = $_POST['firma_id'] ?? '';
            $sube_id = $_POST['sube_id'] ?? '';
            $search = $_POST['search'] ?? '';
            $hesaplama_ay = $_POST['hesaplama_ay'] ?? '';
            $hesaplama_yil = $_POST['hesaplama_yil'] ?? date('Y');
            
            $whereConditions = [];
            $params = [];
            
            // Ay seçildiyse dönem bilgisini al ve tarih aralığı hesapla
            $baslangicTarihi = null;
            $bitisTarihi = null;
            $hesaplamaBitisTarihi = null; // Bugün kontrolü ile hesaplanan bitiş
            
            if ($donem_id && $hesaplama_ay && $hesaplama_yil) {
                // Dönem bilgilerini çek
                $donemBilgi = $db->fetchOne(
                    "SELECT donem_baslangic_gun, donem_bitis_gun FROM tanim_personel_puantaj_donem WHERE donem_id = ?",
                    [$donem_id]
                );
                
                if ($donemBilgi) {
                    $baslangicGun = $donemBilgi['donem_baslangic_gun'];
                    $bitisGun = $donemBilgi['donem_bitis_gun'];
                    
                    // Dönem hesaplama mantığı:
                    // 1-30 Arası Dönem: Başlangıç ve bitiş aynı ay (seçilen ay)
                    // 16-15 Arası Dönem: Başlangıç seçilen ay, bitiş bir sonraki ay
                    
                    if ($baslangicGun > $bitisGun) {
                        // Ay geçişli dönem (örn: 16-15): Başlangıç seçilen ay, bitiş sonraki ay
                        $sonrakiAy = $hesaplama_ay + 1;
                        $sonrakiYil = $hesaplama_yil;
                        if ($sonrakiAy > 12) {
                            $sonrakiAy = 1;
                            $sonrakiYil++;
                        }
                        
                        // Başlangıç ayının son gününü kontrol et
                        $baslangicAySonGun = cal_days_in_month(CAL_GREGORIAN, $hesaplama_ay, $hesaplama_yil);
                        $fiiliBaslangicGun = min($baslangicGun, $baslangicAySonGun);
                        
                        // Bitiş ayının son gününü kontrol et
                        $bitisAySonGun = cal_days_in_month(CAL_GREGORIAN, $sonrakiAy, $sonrakiYil);
                        $fiiliBitisGun = min($bitisGun, $bitisAySonGun);
                        
                        $baslangicTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $fiiliBaslangicGun);
                        $bitisTarihi = sprintf('%04d-%02d-%02d', $sonrakiYil, $sonrakiAy, $fiiliBitisGun);
                    } else {
                        // Normal dönem (örn: 1-30): Başlangıç ve bitiş aynı ay
                        // Ayın son gününü kontrol et (Şubat 28/29, diğer aylar 30/31)
                        $aySonGun = cal_days_in_month(CAL_GREGORIAN, $hesaplama_ay, $hesaplama_yil);
                        $fiiliBitisGun = min($bitisGun, $aySonGun); // Min: 30 veya ayın son günü
                        
                        $baslangicTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $baslangicGun);
                        $bitisTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $fiiliBitisGun);
                    }
                    
                    // BUGÜN KONTROLÜ: Bitiş tarihi bugünden ilerideyse, bugünü kullan
                    $bugun = date('Y-m-d');
                    $hesaplamaBitisTarihi = $bitisTarihi; // Varsayılan: dönem bitişi
                    
                    if ($bitisTarihi > $bugun && $baslangicTarihi <= $bugun) {
                        // Aktif dönem: Bugünü kullan
                        $hesaplamaBitisTarihi = $bugun;
                    } elseif ($baslangicTarihi > $bugun) {
                        // Gelecek dönem: Henüz başlamamış
                        $hesaplamaBitisTarihi = null;
                    }
                }
            }
            
            // Durum filtresi - tarih seçildiyse işten çıkmış personeller de dahil
            if ($baslangicTarihi) {
                // Aktif VEYA (Pasif VE işten çıkış tarihi dönem başlangıcından sonra veya aynı gün)
                $whereConditions[] = "(k.kullanici_durum = 1 OR (k.kullanici_durum = 0 AND k.kullanici_ise_cikis_tarihi >= ?))";
                $params[] = $baslangicTarihi;
            } else {
                // Tarih seçilmemişse sadece aktif personeller
                $whereConditions[] = "k.kullanici_durum = 1";
            }
            
            if ($donem_id) {
                $whereConditions[] = "k.kullanici_puantaj_donem_id = ?";
                $params[] = $donem_id;
            }
            
            if ($firma_id) {
                $whereConditions[] = "k.kullanici_firma_id = ?";
                $params[] = $firma_id;
            }

            if ($sube_id) {
                $whereConditions[] = "k.kullanici_sube_id = ?";
                $params[] = $sube_id;
            }

            if ($search) {
                $whereConditions[] = "(k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $whereClause = implode(" AND ", $whereConditions);
            
            // Her kullanıcı için izin durumlarına göre puantaj sayılarını al
            $sql = "
                SELECT 
                    k.kullanici_id,
                    k.kullanici_ad,
                    k.kullanici_soyad,
                    k.kullanici_maas,
                    sh.SehirAdi as sehir_adi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 120) as kullanici_ise_giris_tarihi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) as kullanici_ise_cikis_tarihi,
                    k.kullanici_puantaj_donem_id,
                    k.kullanici_firma_id,
                    k.kullanici_iban,
                    pd.donem_adi as puantaj_donem_adi,
                    b.banka_adi,
                    f.firma_adi
            ";
            
            // Tarih filtresi varsa toplam gün sayısını hesapla
            // $hesaplamaBitisTarihi: Bugün kontrolü yapılmış bitiş tarihi
            $toplamGunSql = '';
            $hesaplamaBitis = $hesaplamaBitisTarihi ?? $bitisTarihi; // Fallback
            
            if ($baslangicTarihi && $hesaplamaBitis) {
                $toplamGunSql = " , DATEDIFF(day, '$baslangicTarihi', '$hesaplamaBitis') + 1 as toplam_gun";
            } else {
                $toplamGunSql = " , 0 as toplam_gun";
            }
            $sql .= $toplamGunSql;
            
            // Kesinti toplamı ekle (BES hariç tüm kesintiler: Avans=2, Trafik Cezası=5, Kesinti=6, İcra=7)
            $kesintiTarihFiltre = '';
            if ($baslangicTarihi && $hesaplamaBitis) {
                $kesintiTarihFiltre = " AND oh.odeme_hareket_tarih >= '$baslangicTarihi' AND oh.odeme_hareket_tarih <= '$hesaplamaBitis'";
            }
            
            $sql .= ",
                (SELECT ISNULL(SUM(oh.odeme_hareket_tutar), 0)
                 FROM Odeme_Hareketleri oh
                 WHERE oh.odeme_hareket_kullanici_id = k.kullanici_id
                   AND oh.odeme_hareket_tip_id IN (2, 5, 6, 7)
                   AND oh.odeme_hareket_durum = 1
                   $kesintiTarihFiltre
                ) as kesinti_toplam,
                (SELECT ISNULL(SUM(oh.odeme_hareket_tutar), 0)
                 FROM Odeme_Hareketleri oh
                 WHERE oh.odeme_hareket_kullanici_id = k.kullanici_id
                   AND oh.odeme_hareket_tip_id = 4
                   AND oh.odeme_hareket_durum = 1
                   $kesintiTarihFiltre
                ) as bes_toplam";
            
            // Her izin durumu için ayrı kolon ekle
            // NOT: Çıkış tarihi olan personellerde, çıkış tarihinden sonraki izin kayıtları sayılmamalı
            foreach ($izinDurumlari as $izin) {
                $tarihFiltre = '';
                if ($baslangicTarihi && $hesaplamaBitis) {
                    // Bitiş tarihi: MIN(çıkış tarihi, hesaplamaBitis) - çıkış varsa çıkışa kadar say
                    $tarihFiltre = " AND p.puantaj_tarih >= '$baslangicTarihi' AND p.puantaj_tarih <= CASE WHEN k.kullanici_ise_cikis_tarihi IS NOT NULL AND CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) < '$hesaplamaBitis' THEN CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) ELSE '$hesaplamaBitis' END";
                }
                
                $sql .= ",
                    (SELECT COUNT(*) 
                     FROM Personel_Puantaj p 
                     WHERE p.puantaj_kullanici_id = k.kullanici_id 
                       AND p.puantaj_izin_durum_id = {$izin['izin_durum_id']} 
                       AND p.puantaj_aktif = 1
                       $tarihFiltre
                    ) as izin_{$izin['izin_durum_id']}";
            }
            
            $sql .= "
                FROM kullanicilar k
                LEFT JOIN tanim_personel_puantaj_donem pd ON k.kullanici_puantaj_donem_id = pd.donem_id
                LEFT JOIN Banka_Hesap h ON k.kullanici_banka_id = h.bankaHesap_id
                LEFT JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                LEFT JOIN Sehirler sh ON k.kullanici_sehir_id = sh.SehirId
                WHERE $whereClause
                ORDER BY k.kullanici_ad, k.kullanici_soyad
            ";
            
            $list = $db->fetchAll($sql, $params);
            
            // Debug log: Tarih kontrolü
            error_log("PUANTAJ DEBUG - Başlangıç: $baslangicTarihi, Bitiş: $bitisTarihi, Hesaplama Bitiş: $hesaplamaBitis, Kayıt Sayısı: " . count($list));
            
            // Debug: Geldi kolonunu yeniden hesapla (izin_durum_id = 1)
            // Mantık: Toplam gün - (Tüm izinler, "Geldi" durumu hariç)
            if ($baslangicTarihi && $hesaplamaBitis && !empty($list)) {
                error_log("PUANTAJ DEBUG - Hesaplama başlıyor...");
                
                // Geldi durumunun ID'sini bul (kod veya ada göre)
                $geldiDurumId = null;
                foreach ($izinDurumlari as $izin) {
                    if (strtoupper($izin['izin_durum_kod']) === 'GELDI' || 
                        strtoupper($izin['izin_durum_adi']) === 'GELDI' ||
                        $izin['izin_durum_id'] == 1) {
                        $geldiDurumId = $izin['izin_durum_id'];
                        error_log("PUANTAJ DEBUG - Geldi durumu bulundu: ID=$geldiDurumId, Ad={$izin['izin_durum_adi']}, Kod={$izin['izin_durum_kod']}");
                        break;
                    }
                }
                
                if (!$geldiDurumId) {
                    error_log("PUANTAJ DEBUG - UYARI: Geldi durumu bulunamadı!");
                }
                
                foreach ($list as &$item) {
                    $toplamGun = intval($item['toplam_gun'] ?? 0);
                    
                    // Kullanıcının işe giriş ve çıkış tarihi kontrolü
                    $iseGirisTarihi = $item['kullanici_ise_giris_tarihi'] ?? null;
                    $iseCikisTarihi = $item['kullanici_ise_cikis_tarihi'] ?? null;
                    
                    if ($iseGirisTarihi || $iseCikisTarihi) {
                        $donemBasDate = new DateTime($baslangicTarihi);
                        $hesaplamaBitDate = new DateTime($hesaplamaBitis);
                        
                        // Fiili başlangıç tarihi: İşe giriş veya dönem başlangıcından hangisi sonraysa
                        $fiiliBaslangic = $donemBasDate;
                        if ($iseGirisTarihi) {
                            $iseGirisDate = new DateTime($iseGirisTarihi);
                            if ($iseGirisDate > $donemBasDate) {
                                $fiiliBaslangic = $iseGirisDate;
                            }
                        }
                        
                        // Fiili bitiş tarihi: İşten çıkış veya dönem bitişinden hangisi önceyse
                        $fiiliBitis = $hesaplamaBitDate;
                        if ($iseCikisTarihi) {
                            $iseCikisDate = new DateTime($iseCikisTarihi);
                            if ($iseCikisDate < $hesaplamaBitDate) {
                                $fiiliBitis = $iseCikisDate;
                            }
                        }
                        
                        // Toplam gün = Fiili bitiş - Fiili başlangıç + 1
                        // Örnek: Başlangıç 16.12, Çıkış 11.01 → 27 gün
                        $toplamGun = $fiiliBitis->diff($fiiliBaslangic)->days + 1;
                        
                        // Eğer personel dönem başlamadan çıkmışsa veya dönem bitmeden işe başlamadıysa
                        if ($fiiliBitis < $fiiliBaslangic) {
                            $toplamGun = 0;
                        }
                    }
                    
                    // GELDİ HESAPLAMA MANTIĞI:
                    // Geldi = Toplam gün - (Gelmedi + Raporlu + Diğer izinler)
                    // Kayıt OLMAYAN günler varsayılan olarak "Geldi" sayılır
                    
                    // Toplam izin günleri (Geldi durumu HARİÇ)
                    $toplamIzinGunleri = 0;
                    foreach ($izinDurumlari as $izin) {
                        if ($geldiDurumId && $izin['izin_durum_id'] != $geldiDurumId) { // "Geldi" hariç
                            $gunSayisi = intval($item["izin_{$izin['izin_durum_id']}"] ?? 0);
                            $toplamIzinGunleri += $gunSayisi;
                        }
                    }
                    
                    // Geldi = Toplam gün - Tüm izinler (Geldi hariç)
                    $geldiGunleri = max(0, $toplamGun - $toplamIzinGunleri);
                    
                    // Geldi durumunu güncelle
                    if ($geldiDurumId) {
                        $item["izin_$geldiDurumId"] = $geldiGunleri;
                    }
                    
                    // Debug bilgisi ekle
                    $item['debug_toplam_gun'] = $toplamGun;
                    $item['debug_izin_toplam'] = $toplamIzinGunleri;
                    $item['debug_geldi'] = $geldiGunleri;
                    $item['debug_geldi_id'] = $geldiDurumId;
                }
                unset($item);
            } else {
                // Tarih filtresi yok - debug bilgisi ekle
                error_log("PUANTAJ DEBUG - Hesaplama YAPILMADI (tarih yok veya liste boş)");
                foreach ($list as &$item) {
                    $item['debug_toplam_gun'] = 'TARİH YOK';
                    $item['debug_izin_toplam'] = 'TARİH YOK';
                    $item['debug_geldi'] = 'TARİH YOK';
                    $item['debug_geldi_id'] = 'TARİH YOK';
                }
                unset($item);
            }
            
            // Maaş hesaplama
            if ($baslangicTarihi && $hesaplamaBitis && !empty($list)) {
                // Dönem toplam gün sayısını hesapla (sabit, tüm personel için aynı)
                $donemBasDate = new DateTime($baslangicTarihi);
                $donemBitDate = new DateTime($bitisTarihi); // Dönem bitişi (bugün kontrolü YOK)
                $donemToplamGun = $donemBitDate->diff($donemBasDate)->days + 1;
                
                foreach ($list as &$item) {
                    $temelMaas = floatval($item['kullanici_maas'] ?? 0);
                    
                    // Kullanıcının işe giriş ve çıkış tarihi
                    $iseGirisTarihi = $item['kullanici_ise_giris_tarihi'] ?? null;
                    $iseCikisTarihi = $item['kullanici_ise_cikis_tarihi'] ?? null;
                    
                    // Fiili başlangıç: İşe giriş veya dönem başlangıcından hangisi sonraysa
                    $hesaplamaBaslangic = $baslangicTarihi;
                    if ($iseGirisTarihi) {
                        $iseGirisDate = new DateTime($iseGirisTarihi);
                        $donemBasStartDate = new DateTime($baslangicTarihi);
                        
                        if ($iseGirisDate > $donemBasStartDate) {
                            $hesaplamaBaslangic = $iseGirisTarihi;
                        }
                    }
                    
                    // Fiili bitiş: İşten çıkış veya dönem bitişinden hangisi önceyse
                    $hesaplamaBitisTarih = $hesaplamaBitis; // Bugün kontrolü yapılmış bitiş
                    if ($iseCikisTarihi) {
                        $iseCikisDate = new DateTime($iseCikisTarihi);
                        $donemBitEndDate = new DateTime($hesaplamaBitis);
                        
                        if ($iseCikisDate < $donemBitEndDate) {
                            $hesaplamaBitisTarih = $iseCikisTarihi;
                        }
                    }
                    
                    // Fiili çalışma günü hesapla
                    $start = new DateTime($hesaplamaBaslangic);
                    $end = new DateTime($hesaplamaBitisTarih);
                    $fiiliCalismaGunu = $end->diff($start)->days + 1;
                    
                    // Toplam gün 0 veya negatifse (henüz işe başlamamış veya dönem öncesi çıkmış)
                    if ($fiiliCalismaGunu <= 0) {
                        $item['hesaplanan_maas'] = 0;
                        continue;
                    }
                    
                    // Günlük maaş: DÖNEM TOPLAM GÜN üzerinden hesapla (sabit)
                    $gunlukMaas = $temelMaas / $donemToplamGun;
                    
                    // Gün çarpanlarına göre ücretsiz günleri hesapla
                    // Negatif çarpan = Ücretsiz izin (maaştan kesilir)
                    $ucretsizGunler = 0;
                    foreach ($izinDurumlari as $izin) {
                        $gunCarpani = floatval($izin['izin_durum_gun_carpani'] ?? 0);
                        if ($gunCarpani < 0) { // Negatif çarpan = Ücretsiz
                            $gunSayisi = intval($item["izin_{$izin['izin_durum_id']}"] ?? 0);
                            // Çarpanın mutlak değerini kullan (örn: -0.5 → 0.5 gün kesilir)
                            $ucretsizGunler += ($gunSayisi * abs($gunCarpani));
                        }
                    }
                    
                    // Hesaplanan maaş = (Fiili çalışma - Ücretsiz izin) * Günlük maaş
                    $ucretliGunler = $fiiliCalismaGunu - $ucretsizGunler;
                    $hesaplananMaas = $ucretliGunler * $gunlukMaas;
                    
                    // Kesintileri düş
                    $kesinti = floatval($item['kesinti_toplam'] ?? 0);
                    $netMaas = $hesaplananMaas - $kesinti;
                    
                    $item['hesaplanan_maas'] = max(0, $netMaas); // Negatif olamaz
                }
                unset($item);
            } else {
                // Tarih filtresi yoksa temel maaşı göster (kesintiler düşülmüş)
                foreach ($list as &$item) {
                    $temelMaas = floatval($item['kullanici_maas'] ?? 0);
                    $kesinti = floatval($item['kesinti_toplam'] ?? 0);
                    $item['hesaplanan_maas'] = max(0, $temelMaas - $kesinti);
                }
                unset($item);
            }
            
            echo json_encode([
                'success' => true, 
                'data' => $list,
                'tarih_bilgisi' => [
                    'baslangic' => $baslangicTarihi,
                    'bitis' => $hesaplamaBitisTarihi ?? $bitisTarihi
                ]
            ]);
            exit;
        }
        
        if ($action === 'get_maas_detay') {
            $kullanici_id = intval($_POST['kullanici_id'] ?? 0);
            $donem_id = intval($_POST['donem_id'] ?? 0);
            $hesaplama_ay = intval($_POST['hesaplama_ay'] ?? 0);
            $hesaplama_yil = intval($_POST['hesaplama_yil'] ?? date('Y'));
            
            if (!$kullanici_id || !$donem_id || !$hesaplama_ay) {
                echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
                exit;
            }
            
            // Dönem bilgilerini çek
            $donemBilgi = $db->fetchOne(
                "SELECT donem_adi, donem_baslangic_gun, donem_bitis_gun FROM tanim_personel_puantaj_donem WHERE donem_id = ?",
                [$donem_id]
            );
            
            if (!$donemBilgi) {
                echo json_encode(['success' => false, 'message' => 'Dönem bulunamadı']);
                exit;
            }
            
            // Kullanıcı bilgileri
            $kullanici = $db->fetchOne("
                SELECT 
                    k.kullanici_ad, k.kullanici_soyad, k.kullanici_maas,
                    CONVERT(VARCHAR(10), k.kullanici_ise_giris_tarihi, 120) as kullanici_ise_giris_tarihi,
                    CONVERT(VARCHAR(10), k.kullanici_ise_cikis_tarihi, 120) as kullanici_ise_cikis_tarihi,
                    f.firma_adi
                FROM kullanicilar k
                LEFT JOIN Firmalar f ON k.kullanici_firma_id = f.firma_id
                WHERE k.kullanici_id = ?
            ", [$kullanici_id]);
            
            if (!$kullanici) {
                echo json_encode(['success' => false, 'message' => 'Kullanıcı bulunamadı']);
                exit;
            }
            
            // Tarih hesaplama
            $baslangicGun = $donemBilgi['donem_baslangic_gun'];
            $bitisGun = $donemBilgi['donem_bitis_gun'];
            
            if ($baslangicGun > $bitisGun) {
                // Ay geçişli dönem
                $sonrakiAy = $hesaplama_ay + 1;
                $sonrakiYil = $hesaplama_yil;
                if ($sonrakiAy > 12) {
                    $sonrakiAy = 1;
                    $sonrakiYil++;
                }
                $baslangicAySonGun = cal_days_in_month(CAL_GREGORIAN, $hesaplama_ay, $hesaplama_yil);
                $fiiliBaslangicGun = min($baslangicGun, $baslangicAySonGun);
                $bitisAySonGun = cal_days_in_month(CAL_GREGORIAN, $sonrakiAy, $sonrakiYil);
                $fiiliBitisGun = min($bitisGun, $bitisAySonGun);
                $baslangicTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $fiiliBaslangicGun);
                $bitisTarihi = sprintf('%04d-%02d-%02d', $sonrakiYil, $sonrakiAy, $fiiliBitisGun);
            } else {
                // Normal dönem
                $aySonGun = cal_days_in_month(CAL_GREGORIAN, $hesaplama_ay, $hesaplama_yil);
                $fiiliBitisGun = min($bitisGun, $aySonGun);
                $baslangicTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $baslangicGun);
                $bitisTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $fiiliBitisGun);
            }
            
            // Bugün kontrolü
            $bugun = date('Y-m-d');
            $hesaplamaBitisTarihi = $bitisTarihi;
            if ($bitisTarihi > $bugun && $baslangicTarihi <= $bugun) {
                $hesaplamaBitisTarihi = $bugun;
            }
            
            // Dönem toplam gün
            $donemBasDate = new DateTime($baslangicTarihi);
            $donemBitDate = new DateTime($bitisTarihi);
            $donemToplamGun = $donemBitDate->diff($donemBasDate)->days + 1;
            
            // Fiili çalışma günü hesapla (işe giriş/çıkış tarihleri dahil)
            $hesaplamaBaslangic = $baslangicTarihi;
            $iseGirisTarihi = $kullanici['kullanici_ise_giris_tarihi'];
            $iseCikisTarihi = $kullanici['kullanici_ise_cikis_tarihi'];
            
            if ($iseGirisTarihi) {
                $iseGirisDate = new DateTime($iseGirisTarihi);
                $donemBasStartDate = new DateTime($baslangicTarihi);
                if ($iseGirisDate > $donemBasStartDate) {
                    $hesaplamaBaslangic = $iseGirisTarihi;
                }
            }
            
            $hesaplamaBitis = $hesaplamaBitisTarihi;
            if ($iseCikisTarihi) {
                $iseCikisDate = new DateTime($iseCikisTarihi);
                $hesapBitDate = new DateTime($hesaplamaBitisTarihi);
                if ($iseCikisDate < $hesapBitDate) {
                    $hesaplamaBitis = $iseCikisTarihi;
                }
            }
            
            $startDate = new DateTime($hesaplamaBaslangic);
            $endDate = new DateTime($hesaplamaBitis);
            $fiiliCalismaGunu = $endDate->diff($startDate)->days + 1;
            if ($fiiliCalismaGunu < 0) $fiiliCalismaGunu = 0;
            
            // İzin durumlarını çek ve hesapla
            $izinDetaylar = [];
            $toplamIzinGunleri = 0;
            $ucretsizGunler = 0;
            $geldiDurumId = null;
            
            $izinDurumlariDb = $db->fetchAll("
                SELECT izin_durum_id, izin_durum_adi, izin_durum_kod, izin_durum_renk, izin_durum_gun_carpani 
                FROM tanim_izin_durumlari WHERE izin_durum_durum = 1 ORDER BY izin_durum_sira_no
            ");
            
            foreach ($izinDurumlariDb as $izin) {
                // Geldi durumunu bul
                if (strtoupper($izin['izin_durum_kod']) === 'GELDI' || 
                    strtoupper($izin['izin_durum_adi']) === 'GELDI' ||
                    $izin['izin_durum_id'] == 1) {
                    $geldiDurumId = $izin['izin_durum_id'];
                }
                
                // İzin sayısını çek
                $izinSayisi = $db->fetchOne("
                    SELECT COUNT(*) as sayi FROM Personel_Puantaj 
                    WHERE puantaj_kullanici_id = ? AND puantaj_izin_durum_id = ? 
                    AND puantaj_aktif = 1 AND puantaj_tarih >= ? AND puantaj_tarih <= ?
                ", [$kullanici_id, $izin['izin_durum_id'], $baslangicTarihi, $hesaplamaBitis])['sayi'] ?? 0;
                
                $gunCarpani = floatval($izin['izin_durum_gun_carpani'] ?? 0);
                
                // Geldi durumu hariç izin topla
                if ($izin['izin_durum_id'] != $geldiDurumId) {
                    $toplamIzinGunleri += $izinSayisi;
                }
                
                // Ücretsiz günleri topla (negatif çarpan)
                if ($gunCarpani < 0) {
                    $ucretsizGunler += ($izinSayisi * abs($gunCarpani));
                }
                
                $izinDetaylar[] = [
                    'id' => $izin['izin_durum_id'],
                    'ad' => $izin['izin_durum_adi'],
                    'kod' => $izin['izin_durum_kod'],
                    'renk' => $izin['izin_durum_renk'],
                    'gun_carpani' => $gunCarpani,
                    'sayi' => $izinSayisi,
                    'kesinti' => $gunCarpani < 0 ? ($izinSayisi * abs($gunCarpani)) : 0
                ];
            }
            
            // Geldi günlerini hesapla
            $geldiGunleri = max(0, $fiiliCalismaGunu - $toplamIzinGunleri);
            
            // Geldi durumunu güncelle
            foreach ($izinDetaylar as &$detay) {
                if ($detay['id'] == $geldiDurumId) {
                    $detay['sayi'] = $geldiGunleri;
                }
            }
            unset($detay);
            
            // Maaş hesaplama
            $temelMaas = floatval($kullanici['kullanici_maas'] ?? 0);
            $gunlukMaas = $donemToplamGun > 0 ? $temelMaas / $donemToplamGun : 0;
            $ucretliGunler = $fiiliCalismaGunu - $ucretsizGunler;
            $hesaplananBrutMaas = $ucretliGunler * $gunlukMaas;
            
            // Kesinti toplamı (BES hariç: Avans=2, Trafik Cezası=5, Kesinti=6, İcra=7)
            $kesintiToplam = $db->fetchOne("
                SELECT ISNULL(SUM(odeme_hareket_tutar), 0) as toplam FROM Odeme_Hareketleri 
                WHERE odeme_hareket_kullanici_id = ? AND odeme_hareket_tip_id IN (2, 5, 6, 7) 
                AND odeme_hareket_durum = 1 AND odeme_hareket_tarih >= ? AND odeme_hareket_tarih <= ?
            ", [$kullanici_id, $baslangicTarihi, $hesaplamaBitis])['toplam'] ?? 0;
            
            // BES toplamı
            $besToplam = $db->fetchOne("
                SELECT ISNULL(SUM(odeme_hareket_tutar), 0) as toplam FROM Odeme_Hareketleri 
                WHERE odeme_hareket_kullanici_id = ? AND odeme_hareket_tip_id = 4 
                AND odeme_hareket_durum = 1 AND odeme_hareket_tarih >= ? AND odeme_hareket_tarih <= ?
            ", [$kullanici_id, $baslangicTarihi, $hesaplamaBitis])['toplam'] ?? 0;
            
            // Net maaş
            $netMaas = max(0, $hesaplananBrutMaas - $kesintiToplam);
            
            // Ay adları
            $ayAdlari = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'personel' => [
                        'ad_soyad' => $kullanici['kullanici_ad'] . ' ' . $kullanici['kullanici_soyad'],
                        'firma' => $kullanici['firma_adi'] ?? '-',
                        'ise_giris' => $iseGirisTarihi,
                        'ise_cikis' => $iseCikisTarihi
                    ],
                    'donem' => [
                        'donem_adi' => $donemBilgi['donem_adi'],
                        'ay_adi' => $ayAdlari[$hesaplama_ay] . ' ' . $hesaplama_yil,
                        'baslangic' => $baslangicTarihi,
                        'bitis' => $bitisTarihi,
                        'hesaplama_bitis' => $hesaplamaBitis,
                        'toplam_gun' => $donemToplamGun
                    ],
                    'calisma' => [
                        'fiili_baslangic' => $hesaplamaBaslangic,
                        'fiili_bitis' => $hesaplamaBitis,
                        'fiili_gun' => $fiiliCalismaGunu,
                        'geldi_gun' => $geldiGunleri,
                        'izin_gun' => $toplamIzinGunleri,
                        'ucretsiz_gun' => $ucretsizGunler,
                        'ucretli_gun' => $ucretliGunler
                    ],
                    'izinler' => $izinDetaylar,
                    'maas' => [
                        'temel_maas' => $temelMaas,
                        'gunluk_maas' => $gunlukMaas,
                        'hesaplanan_brut' => $hesaplananBrutMaas,
                        'kesinti' => $kesintiToplam,
                        'bes' => $besToplam,
                        'net_maas' => $netMaas
                    ],
                    'formul' => [
                        'gunluk' => "Temel Maaş ({$temelMaas}) / Dönem Gün ({$donemToplamGun}) = " . number_format($gunlukMaas, 2, ',', '.') . ' TL/gün',
                        'brut' => "Ücretli Gün ({$ucretliGunler}) x Günlük Maaş (" . number_format($gunlukMaas, 2, ',', '.') . ") = " . number_format($hesaplananBrutMaas, 2, ',', '.') . ' TL',
                        'net' => "Brüt Maaş (" . number_format($hesaplananBrutMaas, 2, ',', '.') . ") - Kesintiler ({$kesintiToplam}) = " . number_format($netMaas, 2, ',', '.') . ' TL'
                    ]
                ]
            ]);
            exit;
        }
        
        if ($action === 'get_kesinti_detay') {
            $kullanici_id = $_POST['kullanici_id'] ?? '';
            $hesaplama_ay = $_POST['hesaplama_ay'] ?? '';
            $hesaplama_yil = $_POST['hesaplama_yil'] ?? '';
            $donem_id = $_POST['donem_id'] ?? '';
            
            if (!$kullanici_id || !$hesaplama_ay || !$hesaplama_yil) {
                echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
                exit;
            }
            
            // Tarih aralığını hesapla
            $baslangicTarihi = sprintf('%04d-%02d-01', $hesaplama_yil, $hesaplama_ay);
            $aySonGun = cal_days_in_month(CAL_GREGORIAN, $hesaplama_ay, $hesaplama_yil);
            $bitisTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $aySonGun);
            
            if ($donem_id) {
                $donemBilgi = $db->fetchOne(
                    "SELECT donem_baslangic_gun, donem_bitis_gun FROM tanim_personel_puantaj_donem WHERE donem_id = ?",
                    [$donem_id]
                );
                if ($donemBilgi) {
                    $baslangicGun = $donemBilgi['donem_baslangic_gun'];
                    $bitisGun = $donemBilgi['donem_bitis_gun'];
                    if ($baslangicGun > $bitisGun) {
                        $sonrakiAy = $hesaplama_ay + 1;
                        $sonrakiYil = $hesaplama_yil;
                        if ($sonrakiAy > 12) { $sonrakiAy = 1; $sonrakiYil++; }
                        $baslangicTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $baslangicGun);
                        $bitisAySonGun = cal_days_in_month(CAL_GREGORIAN, $sonrakiAy, $sonrakiYil);
                        $bitisTarihi = sprintf('%04d-%02d-%02d', $sonrakiYil, $sonrakiAy, min($bitisGun, $bitisAySonGun));
                    } else {
                        $baslangicTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, $baslangicGun);
                        $bitisTarihi = sprintf('%04d-%02d-%02d', $hesaplama_yil, $hesaplama_ay, min($bitisGun, $aySonGun));
                    }
                }
            }
            
            // Bugün kontrolü
            $bugun = date('Y-m-d');
            $hesaplamaBitis = $bitisTarihi;
            if ($bitisTarihi > $bugun && $baslangicTarihi <= $bugun) {
                $hesaplamaBitis = $bugun;
            }
            
            // Kesinti detaylarını çek (BES hariç: tip_id IN 2,5,6,7)
            $kesintiler = $db->fetchAll("
                SELECT 
                    oh.odeme_hareket_id,
                    oh.odeme_hareket_tutar,
                    CONVERT(VARCHAR(10), oh.odeme_hareket_tarih, 120) as odeme_hareket_tarih,
                    oh.odeme_hareket_aciklama,
                    ot.odeme_tip_adi,
                    ot.odeme_tip_id
                FROM Odeme_Hareketleri oh
                INNER JOIN Odeme_Tipleri ot ON oh.odeme_hareket_tip_id = ot.odeme_tip_id
                WHERE oh.odeme_hareket_kullanici_id = ?
                  AND oh.odeme_hareket_tip_id IN (2, 5, 6, 7)
                  AND oh.odeme_hareket_durum = 1
                  AND oh.odeme_hareket_tarih >= ?
                  AND oh.odeme_hareket_tarih <= ?
                ORDER BY oh.odeme_hareket_tarih DESC
            ", [$kullanici_id, $baslangicTarihi, $hesaplamaBitis]);
            
            // Tip bazlı özet
            $tipOzet = [];
            $genelToplam = 0;
            foreach ($kesintiler as $k) {
                $tipAdi = $k['odeme_tip_adi'];
                if (!isset($tipOzet[$tipAdi])) {
                    $tipOzet[$tipAdi] = ['toplam' => 0, 'adet' => 0];
                }
                $tipOzet[$tipAdi]['toplam'] += floatval($k['odeme_hareket_tutar']);
                $tipOzet[$tipAdi]['adet']++;
                $genelToplam += floatval($k['odeme_hareket_tutar']);
            }
            
            // Personel adı
            $personel = $db->fetchOne("SELECT kullanici_ad, kullanici_soyad FROM kullanicilar WHERE kullanici_id = ?", [$kullanici_id]);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'personel' => ($personel['kullanici_ad'] ?? '') . ' ' . ($personel['kullanici_soyad'] ?? ''),
                    'donem' => $baslangicTarihi . ' - ' . $hesaplamaBitis,
                    'kesintiler' => $kesintiler,
                    'tip_ozet' => $tipOzet,
                    'genel_toplam' => $genelToplam
                ]
            ]);
            exit;
        }
        
        if ($action === 'get_donemler') {
            $donemler = $db->fetchAll("SELECT donem_id, donem_adi, donem_baslangic_gun, donem_bitis_gun FROM tanim_personel_puantaj_donem WHERE donem_durum = 1 ORDER BY donem_baslangic_gun");
            error_log("PUANTAJ DEBUG - Dönem Sayısı: " . count($donemler));
            echo json_encode(['success' => true, 'data' => $donemler]);
            exit;
        }
        
        if ($action === 'get_firmalar') {
            $firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
            echo json_encode(['success' => true, 'data' => $firmalar]);
            exit;
        }

        if ($action === 'get_subeler') {
            $subeler = $db->fetchAll("SELECT sube_id, sube_adi FROM Subeler WHERE sube_durum = 1 ORDER BY sube_adi");
            echo json_encode(['success' => true, 'data' => $subeler]);
            exit;
        }
        
        throw new Exception('Geçersiz işlem');
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    
    <style>
        /* Sidebar geçiş animasyonu */
        .app-main {
            transition: margin-left 0.3s ease-in-out;
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
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-people"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Personel</span>
                                    <span class="info-box-number" id="stat-toplam-personel">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-calendar-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Puantajı Var</span>
                                    <span class="info-box-number" id="stat-puantaj-var">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-currency-dollar"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Maaş</span>
                                    <span class="info-box-number" id="stat-toplam-maas">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-clock-history"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bekleyen</span>
                                    <span class="info-box-number" id="stat-bekleyen">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- Puantaj Dönemi -->
                                    <div class="col-md-2">
                                        <label class="form-label">Puantaj Dönemi</label>
                                        <select class="form-select" name="donem_id" id="filter_donem_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Hesaplama Yılı -->
                                    <div class="col-md-2">
                                        <label class="form-label">Yıl <span class="text-danger">*</span></label>
                                        <select class="form-select" name="hesaplama_yil" id="filter_hesaplama_yil" required>
                                            <?php 
                                            $currentYear = date('Y');
                                            for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++): 
                                            ?>
                                                <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Hesaplama Ayı -->
                                    <div class="col-md-2">
                                        <label class="form-label">Ay <span class="text-danger">*</span></label>
                                        <select class="form-select" name="hesaplama_ay" id="filter_hesaplama_ay" required>
                                            <option value="">Seçiniz...</option>
                                            <option value="1">Ocak</option>
                                            <option value="2">Şubat</option>
                                            <option value="3">Mart</option>
                                            <option value="4">Nisan</option>
                                            <option value="5">Mayıs</option>
                                            <option value="6">Haziran</option>
                                            <option value="7">Temmuz</option>
                                            <option value="8">Ağustos</option>
                                            <option value="9">Eylül</option>
                                            <option value="10">Ekim</option>
                                            <option value="11" <?= date('n') == 11 ? 'selected' : '' ?>>Kasım</option>
                                            <option value="12">Aralık</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Firma -->
                                    <div class="col-md-2">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select" name="firma_id" id="filter_firma_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <!-- Şube -->
                                    <div class="col-md-2">
                                        <label class="form-label">Şube</label>
                                        <select class="form-select" name="sube_id" id="filter_sube_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <!-- Arama -->
                                    <div class="col-md-2">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad, soyad...">
                                    </div>
                                    
                                    <!-- Butonlar -->
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Puantaj Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-calendar3"></i> Personel Puantaj Listesi
                                <span id="tarihAraligi" class="text-muted ms-2"></span>
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm" id="excelBtn" title="Excel İndir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="puantajTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Ad Soyad</th>
                                            <th>Firma</th>
                                            <th>Şehir</th>
                                            <th>İşe Giriş</th>
                                            <th>İşten Çıkış</th>
                                            <th>Puantaj Dönemi</th>
                                            <th>Maaş</th>
                                            <th>Hesaplanan Maaş</th>
                                            <th>Banka</th>
                                            <th>IBAN</th>
                                            <th>Kesintiler</th>
                                            <th>BES</th>
                                            <?php foreach ($izinDurumlari as $izin): ?>
                                                <th style="background-color: <?= htmlspecialchars($izin['izin_durum_renk'] ?? '#ffffff') ?>;">
                                                    <?= htmlspecialchars($izin['izin_durum_adi']) ?>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Maaş Detay Modal -->
    <div class="modal fade" id="maasDetayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-calculator"></i> Maaş Hesaplama Detayı</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="maasDetayContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Yükleniyor...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Kesinti Detay Modal -->
    <div class="modal fade" id="kesintiDetayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-cash-stack"></i> Kesinti Detayları</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="kesintiDetayBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Yükleniyor...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- BES Modal -->
    <div class="modal fade" id="besModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">BES Katkısı Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="besForm">
                    <div class="modal-body">
                        <input type="hidden" id="bes_kullanici_id" name="kullanici_id">
                        <input type="hidden" id="bes_tarih" name="tarih">
                        
                        <div class="mb-3">
                            <label class="form-label">Personel</label>
                            <input type="text" class="form-control" id="bes_personel_adi" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="bes_tutar" class="form-label">BES Tutarı <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="bes_tutar" name="tutar" step="0.01" min="0" required placeholder="0.00">
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
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentFilters = {};
        
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        
        // İzin durumları (PHP'den JavaScript'e)
        const izinDurumlari = <?= json_encode($izinDurumlari) ?>;
        
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam-personel').text(response.data.toplam_personel);
                    $('#stat-puantaj-var').text(response.data.puantaj_var);
                    $('#stat-toplam-maas').text(formatCurrency(response.data.toplam_maas));
                    $('#stat-bekleyen').text(response.data.bekleyen);
                }
            });
        }
        
        function loadDonemler() {
            $.post('', { action: 'get_donemler' }, response => {
                console.log('Dönemler Response:', response);
                if (response.success) {
                    const select = $('#filter_donem_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(d => select.append(`<option value="${d.donem_id}">${d.donem_adi}</option>`));
                } else {
                    console.error('Dönem yükleme hatası:', response.message);
                }
            }).fail(function(error) {
                console.error('AJAX Hatası:', error);
            });
        }
        
        function loadFirmalar() {
            $.post('', { action: 'get_firmalar' }, response => {
                if (response.success) {
                    const select = $('#filter_firma_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(f => select.append(`<option value="${f.firma_id}">${f.firma_adi}</option>`));
                }
            });
        }

        function loadSubeler() {
            $.post('', { action: 'get_subeler' }, response => {
                if (response.success) {
                    const select = $('#filter_sube_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(s => select.append(`<option value="${s.sube_id}">${s.sube_adi}</option>`));
                }
            });
        }
        
        function initDataTable() {
            // Dinamik kolonlar oluştur
            const columns = [
                { 
                    data: null,
                    render: data => `<strong>${data.kullanici_ad} ${data.kullanici_soyad}</strong>`
                },
                { data: 'firma_adi', defaultContent: '-' },
                { 
                    data: 'sehir_adi',
                    render: data => data ? data : '<span class="text-muted">-</span>',
                    className: 'text-center'
                },
                { 
                    data: 'kullanici_ise_giris_tarihi',
                    render: data => data ? formatDateOnly(data) : '<span class="text-muted">-</span>',
                    className: 'text-center'
                },
                { 
                    data: 'kullanici_ise_cikis_tarihi',
                    render: data => data ? formatDateOnly(data) : '<span class="text-muted">-</span>',
                    className: 'text-center'
                },
                { data: 'puantaj_donem_adi', defaultContent: '<span class="text-muted">Atanmamış</span>' },
                { 
                    data: 'kullanici_maas',
                    render: data => data ? formatCurrency(data) : '-',
                    className: 'text-end'
                },
                { 
                    data: null,
                    render: (data, type, row) => {
                        const maas = data.hesaplanan_maas;
                        if (!maas) return '-';
                        return `<a href="javascript:void(0)" class="text-decoration-none fw-bold" onclick="openMaasDetayModal(${data.kullanici_id})" title="Hesaplama detayını görmek için tıklayın">
                            ${formatCurrency(maas)} <i class="bi bi-info-circle-fill text-primary ms-1"></i>
                        </a>`;
                    },
                    className: 'text-end'
                },
                { data: 'banka_adi', defaultContent: '-' },
                {
                    data: 'kullanici_iban',
                    render: function(data, type, row) {
                        if (!data) return type === 'display' ? '<span class="text-muted">-</span>' : '-';
                        
                        // Excel export için tam IBAN döndür
                        if (type === 'export') {
                            return data;
                        }
                        
                        // Ekran gösterimi için kısaltılmış format
                        // IBAN formatı: TR12 3456 7890 1234 5678 9012 34
                        // Gösterim: TR12...9034 (ilk 4 ve son 4 karakter)
                        const iban = data.replace(/\s/g, ''); // Boşlukları kaldır
                        if (iban.length > 8) {
                            const kisaltilmis = iban.substring(0, 4) + '...' + iban.substring(iban.length - 4);
                            return `<span class="iban-short" title="${data}" style="cursor: help; font-size: 0.85em;">${kisaltilmis}</span>`;
                        }
                        return `<span style="font-size: 0.85em;">${data}</span>`;
                    },
                    className: 'text-center'
                },
                {
                    data: null,
                    render: (data, type, row) => {
                        const tutar = parseFloat(row.kesinti_toplam || 0);
                        if (tutar > 0) {
                            return `<span class="badge bg-danger" style="cursor:pointer" onclick="openKesintiDetay(${row.kullanici_id}, '${row.kullanici_ad} ${row.kullanici_soyad}')">${formatCurrency(tutar)}</span>`;
                        }
                        return '<span class="text-muted">-</span>';
                    },
                    className: 'text-center'
                },
                {
                    data: null,
                    orderable: false,
                    render: data => {
                        const besToplam = parseFloat(data.bes_toplam || 0);
                        if (besToplam > 0) {
                            // BES varsa tutarı göster
                            return `<span class="badge bg-info">${formatCurrency(besToplam)}</span>`;
                        } else {
                            // BES yoksa buton göster
                            return `
                                <button class="btn btn-sm btn-success" onclick="openBesModal(${data.kullanici_id}, '${data.kullanici_ad} ${data.kullanici_soyad}')" title="BES Ekle">
                                    <i class="bi bi-plus-circle"></i>
                                </button>
                            `;
                        }
                    },
                    className: 'text-center'
                }
            ];
            
            // Her izin durumu için kolon ekle
            izinDurumlari.forEach(izin => {
                columns.push({
                    data: `izin_${izin.izin_durum_id}`,
                    render: data => {
                        const sayi = data || 0;
                        if (sayi > 0) {
                            return `<span class="badge" style="background-color: ${izin.izin_durum_renk};">${sayi}</span>`;
                        }
                        return '<span class="text-muted">0</span>';
                    }
                });
            });
            
            table = $('#puantajTable').DataTable({
                processing: true,
                scrollX: true,
                autoWidth: false,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        // Filtre yoksa veri çekme (sadece ay ve yıl zorunlu)
                        if (!currentFilters.hesaplama_ay || !currentFilters.hesaplama_yil) {
                            console.log('⚠️ Filtre eksik - veri çekilmiyor');
                            return null; // Veri çekme
                        }
                        console.log('✅ Filtre gönderiliyor:', currentFilters);
                        return { action: 'list', ...currentFilters };
                    },
                    dataSrc: json => {
                        // Filtre eksikse boş dön
                        if (!currentFilters.hesaplama_ay || !currentFilters.hesaplama_yil) {
                            return [];
                        }
                        
                        // Tarih aralığını başlığa ekle
                        if (json.tarih_bilgisi && json.tarih_bilgisi.baslangic && json.tarih_bilgisi.bitis) {
                            const baslangic = formatDateOnly(json.tarih_bilgisi.baslangic);
                            const bitis = formatDateOnly(json.tarih_bilgisi.bitis);
                            $('#tarihAraligi').text(`(${baslangic} - ${bitis})`);
                        } else {
                            $('#tarihAraligi').text('');
                        }
                        
                        if (json.success && json.data.length > 0) {
                            // Debug: İlk kayıt için hesaplama bilgisi
                            console.log('=== PUANTAJ DEBUG ===');
                            console.log('İzin Durumları:', izinDurumlari);
                            console.log('İlk Personel (TAM):', JSON.stringify(json.data[0], null, 2));
                            console.log('Toplam Gün:', json.data[0].debug_toplam_gun);
                            console.log('İzin Toplamı:', json.data[0].debug_izin_toplam);
                            console.log('Hesaplanan Geldi:', json.data[0].debug_geldi);
                            console.log('Geldi ID:', json.data[0].debug_geldi_id);
                            
                            // Tüm izin kolonlarını göster
                            console.log('--- İzin Kolonları ---');
                            izinDurumlari.forEach(izin => {
                                const kolonAdi = `izin_${izin.izin_durum_id}`;
                                console.log(`${izin.izin_durum_adi} (ID:${izin.izin_durum_id}):`, json.data[0][kolonAdi]);
                            });
                            console.log('====================');
                        }
                        return json.success ? json.data : [];
                    }
                },
                columns: columns,
                order: [[0, 'asc']],
                pageLength: 25,
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'excelHtml5',
                        text: '<i class="bi bi-file-earmark-excel"></i> Excel İndir',
                        className: 'btn btn-success btn-sm d-none',
                        filename: function() {
                            const date = new Date();
                            const dateStr = `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
                            return `Personel_Puantaj_${dateStr}`;
                        },
                        title: 'Personel Puantaj Hesaplama',
                        exportOptions: {
                            columns: ':visible',
                            format: {
                                body: function(data, row, column, node) {
                                    // IBAN kolonunu kontrol et (sıra numarasına göre - Banka'dan sonra)
                                    // Ad Soyad(0), Firma(1), İşe Giriş(2), Dönem(3), Maaş(4), Hesaplanan Maaş(5), Banka(6), IBAN(7)
                                    if (column === 7) {
                                        // IBAN kolonuysa orijinal datayı al
                                        const rowData = table.row(row).data();
                                        return rowData.kullanici_iban || '-';
                                    }
                                    // HTML etiketlerini temizle
                                    return data.replace(/<.*?>/g, '');
                                }
                            }
                        },
                        customize: function(xlsx) {
                            // Excel özelleştirmeleri (isteğe bağlı)
                        }
                    }
                ]
            });
        }
        
        $(document).ready(() => {
            loadStats();
            loadDonemler();
            loadFirmalar();
            loadSubeler();
            initDataTable();
            
            // Sidebar toggle - DataTable genişliğini yeniden hesapla
            // Sidebar butonuna click event ekle
            $('[data-lte-toggle="sidebar"]').on('click', function() {
                setTimeout(function() {
                    if (table) {
                        $(window).trigger('resize');
                        table.columns.adjust().draw();
                    }
                }, 350);
            });
            
            // Body class değişimini izle (sidebar-collapse eklendiğinde/kaldırıldığında)
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        setTimeout(function() {
                            if (table) {
                                $(window).trigger('resize');
                                table.columns.adjust().draw();
                            }
                        }, 350);
                    }
                });
            });
            
            const bodyElement = document.querySelector('body');
            if (bodyElement) {
                observer.observe(bodyElement, { attributes: true });
            }
            
            // Excel indir butonu
            $('#excelBtn').on('click', function() {
                table.button(0).trigger();
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                // Zorunlu alanlar kontrolü
                const donem = $('#filter_donem_id').val();
                const ay = $('#filter_hesaplama_ay').val();
                const yil = $('#filter_hesaplama_yil').val();
                
                if (!ay || !yil) {
                    showToast('Lütfen yıl ve ay seçiniz!', 'warning');
                    return;
                }
                
                currentFilters = {
                    donem_id: donem,
                    hesaplama_yil: yil,
                    hesaplama_ay: ay,
                    firma_id: $('#filter_firma_id').val(),
                    sube_id: $('#filter_sube_id').val(),
                    search: $('#filter_search').val()
                };
                
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) delete currentFilters[key];
                });
                
                console.log('Gönderilen Filtreler:', currentFilters);
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_donem_id').val('').trigger('change.select2');
                $('#filter_firma_id').val('').trigger('change.select2');
                $('#filter_sube_id').val('').trigger('change.select2');
                $('#filter_hesaplama_yil').val('<?= date('Y') ?>');
                $('#filter_hesaplama_ay').val('');
                currentFilters = {};
                $('#tarihAraligi').text(''); // Tarih aralığını temizle
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
        
        function formatCurrency(value) {
            return new Intl.NumberFormat('tr-TR', {
                style: 'currency',
                currency: 'TRY'
            }).format(value);
        }
        
        function formatDateOnly(dateString) {
            if (!dateString) return '';
            try {
                const date = new Date(dateString + 'T00:00:00'); // Yerel saat dilimi için
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                });
            } catch (e) {
                return dateString;
            }
        }
        
        // Kesinti Detay Modal Aç
        function openKesintiDetay(kullaniciId, personelAdi) {
            const ay = $('#filter_hesaplama_ay').val();
            const yil = $('#filter_hesaplama_yil').val();
            const donemId = $('#filter_donem_id').val();
            
            if (!ay || !yil) {
                showToast('Lütfen önce ay ve yıl seçiniz', 'warning');
                return;
            }
            
            // Modal aç, loading göster
            const modal = new bootstrap.Modal(document.getElementById('kesintiDetayModal'));
            $('#kesintiDetayBody').html(`
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Yükleniyor...</p>
                </div>
            `);
            modal.show();
            
            $.post('', {
                action: 'get_kesinti_detay',
                kullanici_id: kullaniciId,
                hesaplama_ay: ay,
                hesaplama_yil: yil,
                donem_id: donemId
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    
                    // Tip özet tablosu
                    let tipOzetHtml = '';
                    for (const [tipAdi, ozet] of Object.entries(d.tip_ozet)) {
                        tipOzetHtml += `
                            <tr>
                                <td>${tipAdi}</td>
                                <td class="text-center">${ozet.adet}</td>
                                <td class="text-end text-danger fw-bold">${formatCurrency(ozet.toplam)}</td>
                            </tr>`;
                    }
                    
                    // Detay tablo satırları
                    let detayHtml = '';
                    if (d.kesintiler.length > 0) {
                        d.kesintiler.forEach(k => {
                            detayHtml += `
                                <tr>
                                    <td>${formatDateOnly(k.odeme_hareket_tarih)}</td>
                                    <td><span class="badge bg-secondary">${k.odeme_tip_adi}</span></td>
                                    <td class="text-end text-danger fw-bold">${formatCurrency(k.odeme_hareket_tutar)}</td>
                                    <td>${k.odeme_hareket_aciklama || '-'}</td>
                                </tr>`;
                        });
                    } else {
                        detayHtml = '<tr><td colspan="4" class="text-center text-muted">Kesinti kaydı bulunamadı</td></tr>';
                    }
                    
                    $('#kesintiDetayBody').html(`
                        <div class="mb-3">
                            <h6><i class="bi bi-person"></i> ${d.personel}</h6>
                            <small class="text-muted"><i class="bi bi-calendar3"></i> Dönem: ${d.donem}</small>
                        </div>
                        
                        <!-- Tip Özet -->
                        <div class="card card-outline card-danger mb-3">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0"><i class="bi bi-bar-chart"></i> Kesinti Özeti</h6>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Kesinti Tipi</th>
                                            <th class="text-center">Adet</th>
                                            <th class="text-end">Toplam</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${tipOzetHtml}
                                        <tr class="table-danger fw-bold">
                                            <td>GENEL TOPLAM</td>
                                            <td class="text-center">${d.kesintiler.length}</td>
                                            <td class="text-end">${formatCurrency(d.genel_toplam)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Detay Liste -->
                        <div class="card card-outline card-secondary">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0"><i class="bi bi-list-ul"></i> Kesinti Detayları</h6>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>Tip</th>
                                            <th class="text-end">Tutar</th>
                                            <th>Açıklama</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${detayHtml}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `);
                } else {
                    $('#kesintiDetayBody').html(`<div class="alert alert-danger">${response.message}</div>`);
                }
            }).fail(function() {
                $('#kesintiDetayBody').html('<div class="alert alert-danger">Sunucuya bağlanılamadı</div>');
            });
        }
        
        // BES Modal Aç
        function openBesModal(kullaniciId, personelAdi) {
            // Filtredeki tarih bilgisini al
            const ay = $('#filter_hesaplama_ay').val();
            const yil = $('#filter_hesaplama_yil').val();
            
            if (!ay || !yil) {
                showToast('Lütfen önce ay ve yıl seçiniz', 'warning');
                return;
            }
            
            // Tarih hesapla (ayın son günü)
            const tarih = `${yil}-${ay.padStart(2, '0')}-${new Date(yil, ay, 0).getDate()}`;
            
            $('#bes_kullanici_id').val(kullaniciId);
            $('#bes_personel_adi').val(personelAdi);
            $('#bes_tarih').val(tarih);
            $('#bes_tutar').val('');
            
            const modal = new bootstrap.Modal(document.getElementById('besModal'));
            modal.show();
        }
        
        // Maaş Detay Modal Aç
        function openMaasDetayModal(kullaniciId) {
            const donemId = $('#filter_donem_id').val();
            const ay = $('#filter_hesaplama_ay').val();
            const yil = $('#filter_hesaplama_yil').val();
            
            if (!donemId || !ay || !yil) {
                showToast('Lütfen dönem, ay ve yıl seçiniz', 'warning');
                return;
            }
            
            // Modal aç ve loading göster
            const modal = new bootstrap.Modal(document.getElementById('maasDetayModal'));
            modal.show();
            
            $('#maasDetayContent').html(`
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Yükleniyor...</span>
                    </div>
                    <p class="mt-2 text-muted">Hesaplama detayları yükleniyor...</p>
                </div>
            `);
            
            // AJAX ile detay bilgilerini çek
            $.post('', {
                action: 'get_maas_detay',
                kullanici_id: kullaniciId,
                donem_id: donemId,
                hesaplama_ay: ay,
                hesaplama_yil: yil
            }, function(response) {
                if (response.success) {
                    renderMaasDetay(response.data);
                } else {
                    $('#maasDetayContent').html(`
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> ${response.message}
                        </div>
                    `);
                }
            }).fail(function() {
                $('#maasDetayContent').html(`
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Bir hata oluştu!
                    </div>
                `);
            });
        }
        
        // Maaş Detay Render
        function renderMaasDetay(data) {
            const personel = data.personel;
            const donem = data.donem;
            const calisma = data.calisma;
            const izinler = data.izinler;
            const maas = data.maas;
            const formul = data.formul;
            
            // İzin tablosu oluştur
            let izinRows = '';
            izinler.forEach(izin => {
                if (izin.sayi > 0 || izin.kesinti > 0) {
                    izinRows += `
                        <tr>
                            <td>
                                <span class="badge" style="background-color: ${izin.renk};">${izin.ad}</span>
                            </td>
                            <td class="text-center">${izin.sayi} gün</td>
                            <td class="text-center">
                                ${izin.gun_carpani < 0 ? '<span class="text-danger">-' + izin.kesinti.toFixed(1) + ' gün</span>' : 
                                  izin.gun_carpani > 0 ? '<span class="text-success">Ücretli</span>' : '-'}
                            </td>
                        </tr>
                    `;
                }
            });
            
            if (!izinRows) {
                izinRows = '<tr><td colspan="3" class="text-center text-muted">İzin kaydı yok</td></tr>';
            }
            
            const html = `
                <!-- Personel Bilgileri -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-2"><i class="bi bi-person"></i> Personel Bilgileri</h6>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><th width="40%">Ad Soyad:</th><td><strong>${personel.ad_soyad}</strong></td></tr>
                                    <tr><th>Firma:</th><td>${personel.firma}</td></tr>
                                    <tr><th>İşe Giriş:</th><td>${personel.ise_giris ? formatDateOnly(personel.ise_giris) : '-'}</td></tr>
                                    ${personel.ise_cikis ? `<tr><th>İşten Çıkış:</th><td class="text-danger">${formatDateOnly(personel.ise_cikis)}</td></tr>` : ''}
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-2"><i class="bi bi-calendar3"></i> Dönem Bilgileri</h6>
                                <table class="table table-sm table-borderless mb-0">
                                    <tr><th width="40%">Dönem:</th><td><strong>${donem.donem_adi}</strong></td></tr>
                                    <tr><th>Hesaplama Ayı:</th><td><strong>${donem.ay_adi}</strong></td></tr>
                                    <tr><th>Tarih Aralığı:</th><td>${formatDateOnly(donem.baslangic)} - ${formatDateOnly(donem.hesaplama_bitis)}</td></tr>
                                    <tr><th>Dönem Toplam:</th><td><strong>${donem.toplam_gun} gün</strong></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Çalışma Günleri -->
                <div class="card border-primary mb-3">
                    <div class="card-header bg-primary text-white py-2">
                        <i class="bi bi-calendar-check"></i> Çalışma Günleri Özeti
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col">
                                <div class="border rounded p-2">
                                    <div class="fs-4 fw-bold text-primary">${calisma.fiili_gun}</div>
                                    <small class="text-muted">Fiili Gün</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="border rounded p-2">
                                    <div class="fs-4 fw-bold text-success">${calisma.geldi_gun}</div>
                                    <small class="text-muted">Geldi</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="border rounded p-2">
                                    <div class="fs-4 fw-bold text-warning">${calisma.izin_gun}</div>
                                    <small class="text-muted">İzinli</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="border rounded p-2">
                                    <div class="fs-4 fw-bold text-danger">${calisma.ucretsiz_gun}</div>
                                    <small class="text-muted">Ücretsiz İzin</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="border rounded p-2 bg-success bg-opacity-10">
                                    <div class="fs-4 fw-bold text-success">${calisma.ucretli_gun}</div>
                                    <small class="text-muted">Ücretli Gün</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- İzin Detayları -->
                <div class="card mb-3">
                    <div class="card-header py-2">
                        <i class="bi bi-list-check"></i> İzin Detayları
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>İzin Türü</th>
                                    <th class="text-center">Gün Sayısı</th>
                                    <th class="text-center">Maaş Etkisi</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${izinRows}
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Maaş Hesaplama -->
                <div class="card border-success">
                    <div class="card-header bg-success text-white py-2">
                        <i class="bi bi-currency-dollar"></i> Maaş Hesaplama
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr>
                                    <th width="50%">Temel Maaş:</th>
                                    <td class="text-end">${formatCurrency(maas.temel_maas)}</td>
                                </tr>
                                <tr class="table-light">
                                    <th>Günlük Maaş Hesabı:</th>
                                    <td class="text-end small text-muted">${formul.gunluk}</td>
                                </tr>
                                <tr>
                                    <th>Brüt Maaş Hesabı:</th>
                                    <td class="text-end"><strong>${formatCurrency(maas.hesaplanan_brut)}</strong></td>
                                </tr>
                                <tr class="table-light">
                                    <th><i class="bi bi-arrow-right-short"></i> Formül:</th>
                                    <td class="text-end small text-muted">${formul.brut}</td>
                                </tr>
                                ${maas.kesinti > 0 ? `
                                <tr class="text-danger">
                                    <th>(-) Kesintiler:</th>
                                    <td class="text-end">-${formatCurrency(maas.kesinti)}</td>
                                </tr>
                                ` : ''}
                                ${maas.bes > 0 ? `
                                <tr class="text-info">
                                    <th><i class="bi bi-info-circle"></i> BES Katkısı:</th>
                                    <td class="text-end">${formatCurrency(maas.bes)}</td>
                                </tr>
                                ` : ''}
                                <tr class="table-success fs-5">
                                    <th><i class="bi bi-check-circle"></i> NET MAAŞ:</th>
                                    <td class="text-end"><strong>${formatCurrency(maas.net_maas)}</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Sağlama -->
                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="bi bi-info-circle"></i> <strong>Sağlama:</strong> ${formul.net}
                </div>
            `;
            
            $('#maasDetayContent').html(html);
        }
        
        // BES Form Submit
        $(document).ready(function() {
            $('#besForm').on('submit', function(e) {
                e.preventDefault();
                
                const formData = $(this).serialize() + '&action=save_bes';
                
                $.post('', formData, function(response) {
                    if (response.success) {
                        showToast(response.message, 'success');
                        bootstrap.Modal.getInstance(document.getElementById('besModal')).hide();
                    } else {
                        showToast(response.message, 'error');
                    }
                }).fail(function() {
                    showToast('Kayıt sırasında hata oluştu', 'error');
                });
            });
        });
    </script>
</body>
</html>
