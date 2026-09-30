<?php
/**
 * Destek Widget — Yüzen Buton + Badge + Polling
 * Kaynak: Portal
 * Bu dosya otomatik oluşturulmuştur.
 *
 * ═══════════════════════════════════════════════════════════════
 * KULLANIM:
 * ─────────────────────────────────────────────────────────────
 * Bu dosya, kaynak sitenin footer.php veya layout dosyasına
 * include edilerek kullanılır:
 *
 *   <?php include 'destek-widget.php'; ?>
 *
 * ⚠️ ÖNEMLİ KURALLAR:
 * ─────────────────────────────────────────────────────────────
 * 1. SADECE login sonrası sayfalarda include edin.
 *    Giriş sayfası, kayıt sayfası gibi yerlerde OLMAMALI.
 *
 * 2. destek.php ve destek-detay.php sayfalarında OLMAMALI.
 *    Bu sayfalarda zaten destek arayüzü var, buton gereksiz olur.
 *
 * 3. Örnek footer.php kullanımı:
 *    <?php
 *    $currentPage = basename($_SERVER['PHP_SELF']);
 *    $widgetHaricSayfalar = ['destek.php', 'destek-detay.php', 'giris.php', 'kayit.php'];
 *    if (isset($_SESSION['kullanici_email']) && !in_array($currentPage, $widgetHaricSayfalar)) {
 *        include 'destek-widget.php';
 *    }
 *    ?>
 * ═══════════════════════════════════════════════════════════════
 *
 * ÇALIŞMA MANTIĞI:
 * ─────────────────────────────────────────────────────────────
 * 1. Sayfa yüklendiğinde yüzen buton oluşturulur (sağ alt köşe)
 * 2. Her 60 saniyede destek-bildirim.php'ye AJAX isteği gider
 * 3. Yeni yanıt varsa badge (kırmızı sayaç) gösterilir
 * 4. localStorage ile son kontrol zamanı yönetilir:
 *    - destek_son_kontrol: kullanıcının destek sayfasını son açtığı an
 *    - destek.php'ye girdiğinde bu değer güncellenir
 *    - Polling'de bu değer gönderilip karşılaştırılır
 * ═══════════════════════════════════════════════════════════════
 */
?>

<!-- ═══ YÜZEN DESTEK BUTONU ═══ -->
<a href="/admin/destek" id="destekWidget" title="Destek Talepleri">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 1a5 5 0 0 0-5 5v1h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a6 6 0 1 1 12 0v6a2.5 2.5 0 0 1-2.5 2.5H9.366a1 1 0 0 1-.866.5h-1a1 1 0 1 1 0-2h1a1 1 0 0 1 .866.5H11.5A1.5 1.5 0 0 0 13 12h-1a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h1V6a5 5 0 0 0-5-5"/>
    </svg>
    <!-- yeni_sayisi: Badge'de gösterilecek yeni yanıt sayısı -->
    <span id="destekBadge"></span>
</a>

<style>
/* ─── Yüzen Buton Stilleri ─── */
#destekWidget {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    box-shadow: 0 4px 15px rgba(0,0,0,.25);
    z-index: 9999;
    transition: transform .2s, box-shadow .2s;
}
#destekWidget:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(0,0,0,.35);
    color: #fff;
}

/* ─── Badge (Kırmızı Sayaç) ─── */
/* yeni_sayisi > 0 olduğunda gösterilir */
#destekBadge {
    display: none; /* JS ile gösterilir */
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 10px;
    background: #dc3545;
    color: #fff;
    font-size: .7rem;
    font-weight: 700;
    line-height: 20px;
    text-align: center;
}

/* ─── Shake Animasyonu (Yeni yanıt geldiğinde) ─── */
@keyframes destekShake {
    0%, 100% { transform: rotate(0deg); }
    15% { transform: rotate(12deg); }
    30% { transform: rotate(-10deg); }
    45% { transform: rotate(8deg); }
    60% { transform: rotate(-6deg); }
    75% { transform: rotate(3deg); }
}
#destekWidget.shake {
    animation: destekShake .6s ease-in-out;
}
</style>

<script>
/**
 * ═══════════════════════════════════════════════════════════════
 * DESTEK WIDGET POLLING SİSTEMİ
 * ═══════════════════════════════════════════════════════════════
 *
 * localStorage key'leri:
 *   destek_son_kontrol → ISO 8601 formatında son ziyaret zamanı
 *                        destek.php açıldığında güncellenir
 *
 * Polling sıklığı: 60 saniye (60000 ms)
 * Endpoint: destek-bildirim.php?son_kontrol=...
 *
 * Response key'leri:
 *   success     → boolean
 *   yeni_sayisi → int (badge sayısı)
 *   toplam      → int (toplam ticket)
 *   ticketler[] → yeni yanıtı olan ticket'lar
 * ═══════════════════════════════════════════════════════════════
 */
(function() {
    var POLLING_INTERVAL = 60000; // 60 saniye
    var BILDIRIM_URL     = '/admin/pages/destek-bildirim.php';
    var LS_KEY           = 'destek_son_kontrol';

    var badge = document.getElementById('destekBadge');
    var widget = document.getElementById('destekWidget');

    /**
     * Son kontrol zamanını localStorage'dan al
     * Yoksa şimdiki zamanı set et (ilk kullanım)
     */
    function getSonKontrol() {
        var val = localStorage.getItem(LS_KEY);
        if (!val) {
            val = new Date().toISOString();
            localStorage.setItem(LS_KEY, val);
        }
        return val;
    }

    /**
     * Badge'i güncelle
     * yeni_sayisi > 0 → kırmızı badge göster + shake animasyonu
     * yeni_sayisi = 0 → badge gizle
     */
    function badgeGuncelle(sayi) {
        if (sayi > 0) {
            badge.textContent = sayi > 99 ? '99+' : sayi;
            badge.style.display = 'block';

            // Shake animasyonu
            widget.classList.remove('shake');
            void widget.offsetWidth; // reflow
            widget.classList.add('shake');
        } else {
            badge.style.display = 'none';
            badge.textContent = '';
        }
    }

    /**
     * destek-bildirim.php'ye AJAX isteği gönder
     * son_kontrol parametresi ile yeni yanıtları filtrele
     */
    function bildirimKontrol() {
        var sonKontrol = getSonKontrol();
        var url = BILDIRIM_URL + '?son_kontrol=' + encodeURIComponent(sonKontrol);

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.timeout = 15000;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status !== 200) return;

            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    // yeni_sayisi: Badge'de gösterilecek değer
                    badgeGuncelle(res.yeni_sayisi || 0);
                }
            } catch(e) {
                // JSON parse hatası — sessizce geç
            }
        };
        xhr.send();
    }

    // ─── İlk kontrol + Polling başlat ───
    bildirimKontrol();
    setInterval(bildirimKontrol, POLLING_INTERVAL);
})();
</script>
