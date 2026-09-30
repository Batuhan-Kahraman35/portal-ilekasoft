<?php
/**
 * Destek Bildirim Proxy Endpoint
 * Kaynak: Portal
 * Bu dosya otomatik oluşturulmuştur.
 *
 * ═══════════════════════════════════════════════════════════════
 * AMAÇ:
 * ─────────────────────────────────────────────────────────────
 * Bu dosya, destek-widget.php tarafından AJAX ile çağrılır.
 * API key'i client-side'da açığa çıkarmamak için server-side
 * proxy olarak çalışır.
 *
 * list_tickets API çağrısı yaparak, son kontrol zamanından
 * sonra gelen yeni yanıtları filtreler ve sayısını döndürür.
 * ═══════════════════════════════════════════════════════════════
 *
 * ÇAĞRI ŞEKLİ:
 *   GET destek-bildirim.php?son_kontrol=2026-05-07T12:00:00
 *
 * DÖNDÜRDÜĞÜ JSON:
 *   {
 *     "success": true,
 *     "yeni_sayisi": 2,        ← Badge'de gösterilecek sayı
 *     "toplam": 5,             ← Toplam ticket sayısı
 *     "ticketler": [           ← Sadece yeni yanıtı olan ticket'lar
 *       { "id": 16, "no": "TKT-...", "konu": "...", "son_yanit": "..." }
 *     ]
 *   }
 *
 * API KEY GÜVENLİĞİ:
 *   API key bu dosyada server-side tutulur.
 *   Client-side JavaScript'te asla görünmez.
 * ═══════════════════════════════════════════════════════════════
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

// ═══════════════════════════════════════════════════════════
// YAPILANDIRMA
// API adresi ve anahtari Entegrasyonlar tablosundan gelir; koda gomulmez.
// ═══════════════════════════════════════════════════════════
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/TicketApi.php';
// AJAX isteği olduğu için requireAuth() yönlendirme yapabilir, kendimiz kontrol edelim.
if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user = Auth::user();
$eposta     = $user['email'] ?? null;
$kulAd      = $user['name'] ?? null;
$kulSoyad   = '';
$kulTelefon = ''; 

// ─── Oturum kontrolü ───
if (!$eposta) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

// ═══════════════════════════════════════════════════════════
// API ÇAĞRISI
// ═══════════════════════════════════════════════════════════
$api = new TicketApi();
if (!$api->hazirMi()) {
    echo json_encode(['success' => false, 'message' => $api->getHata()]);
    exit;
}

// ─── list_tickets API çağrısı ───
$result = $api->call(['action' => 'list_tickets', 'eposta' => $eposta]);

if (!($result['success'] ?? false)) {
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'API hatası.']);
    exit;
}

$tickets = $result['data'] ?? [];
$toplam  = count($tickets);

// ═══════════════════════════════════════════════════════════
// YENİ YANIT FİLTRESİ
// ═══════════════════════════════════════════════════════════
// son_kontrol: Kullanıcının destek sayfasını en son ziyaret ettiği zaman
// Bu değer client-side'da localStorage'da tutulur ve AJAX ile gönderilir
// son_yanit_tarihi > son_kontrol → yeni yanıt var demektir
$sonKontrol     = trim($_GET['son_kontrol'] ?? '');
$sonKontrolTime = $sonKontrol !== '' ? strtotime($sonKontrol) : 0;

$yeniSayisi  = 0;
$yeniTicketler = [];

foreach ($tickets as $t) {
    $sonYanitStr = $t['son_yanit_tarihi'] ?? null;
    if (!$sonYanitStr) continue;

    $sonYanitTime = strtotime($sonYanitStr);
    if ($sonKontrolTime > 0 && $sonYanitTime > $sonKontrolTime) {
        $yeniSayisi++;
        $yeniTicketler[] = [
            'id'        => (int)($t['Tickets_id'] ?? 0),
            'no'        => $t['Tickets_no'] ?? '',
            'konu'      => $t['Tickets_konu'] ?? '',
            'son_yanit' => $sonYanitStr,
        ];
    }
}

// ═══════════════════════════════════════════════════════════
// YANIT
// ═══════════════════════════════════════════════════════════
echo json_encode([
    'success'     => true,
    'yeni_sayisi' => $yeniSayisi,   // Badge'de gösterilecek sayı
    'toplam'      => $toplam,       // Toplam ticket sayısı
    'ticketler'   => $yeniTicketler, // Yeni yanıtı olan ticket'lar
], JSON_UNESCAPED_UNICODE);
