<?php
/**
 * Sayfa Yetki Kontrolü
 * 
 * Kullanıcının mevcut sayfaya erişim yetkisini kontrol eder.
 * departman_id=1 (Administrator) için tüm yetkilere izin verir.
 * Diğer departmanlar için menu_sayfa_yetkiler tablosunu kontrol eder.
 */

class PageAuth {
    
    /**
     * Sayfa yetkilerini kontrol et
     * 
     * @param int $userId Kullanıcı ID
     * @param int $departmanId Departman ID
     * @param string $pageFile Sayfa dosya adı (örn: stok-yonetimi.php)
     * @return array Yetki bilgileri
     */
    public static function checkPagePermissions($userId, $departmanId, $pageFile) {
        // Administrator departmanı için tüm yetkiler açık
        if ($departmanId == 1) {
            return [
                'has_access' => true,
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_view_firma' => true,
                'can_view_sube' => true,
                'can_view_own_records' => false, // Admin tüm kayıtları görür
                'is_admin' => true
            ];
        }
        
        $db = Database::getInstance();
        
        // Sayfa ID'sini bul (RTRIM ile trailing space kontrolü)
        $page = $db->fetchOne("
            SELECT sayfalar_id 
            FROM tanim_sayfalar 
            WHERE RTRIM(LTRIM(sayfalar_sayfa_url)) = RTRIM(LTRIM(?)) AND sayfalar_durum = 1
        ", [trim($pageFile)]);
        
        if (!$page) {
            // Sayfa tanımı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_firma' => false,
                'can_view_sube' => false,
                'is_admin' => false,
                'error' => 'Sayfa tanımı bulunamadı'
            ];
        }
        
        // Departman yetkilerini kontrol et
        $permissions = $db->fetchOne("
            SELECT 
                gor,
                ekle,
                duzenle,
                sil,
                firma_gor,
                sube_gor,
                kendi_kullanicini_gor
            FROM menu_sayfa_yetkiler
            WHERE departman_id = ? 
              AND sayfa_id = ? 
              AND durum = 1
        ", [$departmanId, $page['sayfalar_id']]);
        
        if (!$permissions) {
            // Yetki kaydı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_firma' => false,
                'can_view_sube' => false,
                'can_view_own_records' => false,
                'is_admin' => false,
                'error' => 'Bu sayfaya erişim yetkiniz yok'
            ];
        }
        
        return [
            'has_access' => (bool)$permissions['gor'],
            'can_view' => (bool)$permissions['gor'],
            'can_add' => (bool)$permissions['ekle'],
            'can_edit' => (bool)$permissions['duzenle'],
            'can_delete' => (bool)$permissions['sil'],
            'can_view_firma' => (bool)$permissions['firma_gor'],
            'can_view_sube' => (bool)$permissions['sube_gor'],
            'can_view_own_records' => (bool)$permissions['kendi_kullanicini_gor'],
            'is_admin' => false
        ];
    }
    
    /**
     * Firma Gör (firma_gor) kısıtını çözer
     *
     * Admin değilse ve sayfada firma_gor açıksa kullanıcı kendi firmasının
     * kayıtlarıyla sınırlanır. Firma önce şubenin firmasından, yoksa
     * kullanıcının firma alanından alınır.
     *
     * @param array $pagePermissions checkPagePermissions() sonucu
     * @param int $userId Kullanıcı ID
     * @return int|null null: kısıt yok · >0: firma_id · 0: firma çözülemedi (hiç kayıt gösterilmez)
     */
    public static function firmaKisiti(array $pagePermissions, $userId) {
        if (!empty($pagePermissions['is_admin']) || empty($pagePermissions['can_view_firma'])) {
            return null;
        }

        $db = Database::getInstance();
        $kayit = $db->fetchOne("
            SELECT COALESCE(s.sube_firma_id, k.kullanici_firma_id) AS firma_id
            FROM kullanicilar k
            LEFT JOIN Subeler s ON k.kullanici_sube_id = s.sube_id
            WHERE k.kullanici_id = ?
        ", [$userId]);

        return (int)($kayit['firma_id'] ?? 0);
    }

    /**
     * Firma kısıtı için SQL koşulu üretir
     *
     * Örnek: [$sql, $params] = PageAuth::firmaKisitSql($kisitFirmaId, 'k.kullanici_firma_id');
     *
     * @param int|null $kisitFirmaId firmaKisiti() sonucu
     * @param string $kolon Filtrelenecek firma kolonu (sabit değer verilmeli, kullanıcı girdisi değil)
     * @return array [' AND ...' veya '', parametreler]
     */
    public static function firmaKisitSql($kisitFirmaId, $kolon) {
        if ($kisitFirmaId === null) {
            return ['', []];
        }
        if ($kisitFirmaId > 0) {
            return [" AND $kolon = ?", [$kisitFirmaId]];
        }
        return [" AND 1 = 0", []];
    }

    /**
     * Personel(ler) kısıtlı firmaya mı ait? Kısıt yoksa her zaman true.
     *
     * @param int|null $kisitFirmaId firmaKisiti() sonucu
     * @param int|int[] $kullaniciIdler Kontrol edilecek personel ID(leri)
     * @return bool
     */
    public static function personelFirmadaMi($kisitFirmaId, $kullaniciIdler) {
        if ($kisitFirmaId === null) {
            return true;
        }
        $idler = array_values(array_unique(array_filter(array_map('intval', (array)$kullaniciIdler))));
        if (!$idler || $kisitFirmaId <= 0) {
            return false;
        }

        $db = Database::getInstance();
        $yer = implode(',', array_fill(0, count($idler), '?'));
        $sonuc = $db->fetchOne("
            SELECT COUNT(*) AS sayi
            FROM kullanicilar
            WHERE kullanici_id IN ($yer) AND kullanici_firma_id = ?
        ", array_merge($idler, [$kisitFirmaId]));

        return (int)($sonuc['sayi'] ?? 0) === count($idler);
    }

    /**
     * Puantaj/izin kaydının personeli kısıtlı firmada mı? Kısıt yoksa her zaman true.
     *
     * @param int|null $kisitFirmaId firmaKisiti() sonucu
     * @param int $puantajId Personel_Puantaj.puantaj_id
     * @return bool
     */
    public static function puantajFirmadaMi($kisitFirmaId, $puantajId) {
        if ($kisitFirmaId === null) {
            return true;
        }
        $db = Database::getInstance();
        $kayit = $db->fetchOne("SELECT puantaj_kullanici_id FROM Personel_Puantaj WHERE puantaj_id = ?", [intval($puantajId)]);
        return $kayit && self::personelFirmadaMi($kisitFirmaId, $kayit['puantaj_kullanici_id']);
    }

    /**
     * Yetkisiz erişim durumunda hata sayfası göster
     */
    public static function accessDenied($message = 'Bu sayfaya erişim yetkiniz yok!') {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Erişim Engellendi</title>
            <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        </head>
        <body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="text-center text-white">
                <i class="bi bi-shield-x" style="font-size: 120px; opacity: 0.8;"></i>
                <h1 class="display-4 mt-4">403 - Erişim Engellendi</h1>
                <p class="lead mb-4"><?= htmlspecialchars($message) ?></p>
                <a href="/admin/anasayfa" class="btn btn-light btn-lg">
                    <i class="bi bi-house-door"></i> Ana Sayfaya Dön
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
