<?php
/**
 * Bildirim API
 * Tüm bildirimler Bildirimler tablosundan gelir
 * bildirim-yonetimi.php ile aynı veri kaynağı
 */

require_once __DIR__ . '/../auth.php';
requireAuth();

header('Content-Type: application/json');

$db = Database::getInstance();
$user = Auth::user();
$userId = $user['kullanici_id'];

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if ($action === 'get_notifications') {
        $bildirimler = [];
        
        // Bildirimler tablosundan kullanıcının görebildiği okunmamış bildirimleri çek
        try {
            $tabloBildirimleri = $db->fetchAll("
                SELECT TOP 10
                    bildirim_id as id,
                    bildirim_hedef_tip,
                    bildirim_tip as tip,
                    bildirim_baslik as baslik,
                    bildirim_mesaj as mesaj,
                    bildirim_link as link,
                    bildirim_icon as icon,
                    bildirim_renk as renk,
                    DATEDIFF(MINUTE, bildirim_olusturma_tarihi, GETDATE()) as dakika_once
                FROM vw_kullanici_bildirimleri
                WHERE kullanici_id = ?
                  AND okundu = 0
                  AND silindi = 0
                ORDER BY bildirim_olusturma_tarihi DESC
            ", [$userId]);
            
            foreach ($tabloBildirimleri as $b) {
                $dakika = (int)$b['dakika_once'];
                if ($dakika < 1) {
                    $zaman = 'Az önce';
                } elseif ($dakika < 60) {
                    $zaman = $dakika . ' dakika önce';
                } elseif ($dakika < 1440) {
                    $zaman = floor($dakika / 60) . ' saat önce';
                } else {
                    $zaman = floor($dakika / 1440) . ' gün önce';
                }
                
                $bildirimler[] = [
                    'id' => $b['id'],
                    'tip' => $b['tip'],
                    'baslik' => $b['baslik'],
                    'mesaj' => $b['mesaj'],
                    'zaman' => $zaman,
                    'link' => $b['link'] ?: '/admin/bildirim-yonetimi',
                    'icon' => $b['icon'] ?: 'bi-bell',
                    'renk' => $b['renk'] ?: 'primary'
                ];
            }
        } catch (Exception $e) {
            // View yoksa boş döndür
            error_log("Bildirim API hatası: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'data' => $bildirimler,
            'count' => count($bildirimler)
        ]);
        exit;
    }
    
    if ($action === 'get_count') {
        $count = 0;
        
        // Bildirimler tablosundan okunmamış sayısı
        try {
            $count = $db->fetchOne("
                SELECT COUNT(*) as sayi FROM vw_kullanici_bildirimleri
                WHERE kullanici_id = ? AND okundu = 0 AND silindi = 0
            ", [$userId])['sayi'] ?? 0;
        } catch (Exception $e) {
            // View yoksa 0 döndür
        }
        
        echo json_encode([
            'success' => true,
            'count' => $count
        ]);
        exit;
    }
    
    if ($action === 'mark_read') {
        // Bildirimi okundu işaretle
        $id = $_POST['id'] ?? 0;
        
        // Önce kayıt var mı kontrol et
        $existing = $db->fetchOne("SELECT okuma_id FROM Bildirim_Okumalari WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
        
        if ($existing) {
            $db->execute("UPDATE Bildirim_Okumalari SET okundu = 1, okunma_tarihi = GETDATE() WHERE bildirim_id = ? AND kullanici_id = ?", [$id, $userId]);
        } else {
            $db->execute("INSERT INTO Bildirim_Okumalari (bildirim_id, kullanici_id, okundu, okunma_tarihi) VALUES (?, ?, 1, GETDATE())", [$id, $userId]);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    throw new Exception('Geçersiz işlem');
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
