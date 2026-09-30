# ÖRNEK WhatsApp Bulk Sender - Chrome Extension

WhatsApp Web üzerinden toplu mesaj gönderme Chrome eklentisi.

## 📦 Kurulum

### 1. Extension Yükleme
1. Chrome'da `chrome://extensions/` adresine gidin
2. Sağ üst köşede "Geliştirici modu"nu açın
3. "Paketlenmemiş uzantı yükle" butonuna tıklayın
4. Bu klasörü (`chrome-extension`) seçin

### 2. İkon Ekleme
Extension'ın çalışması için `icons` klasörüne şu dosyaları ekleyin:
- `icon16.png` (16x16 px)
- `icon48.png` (48x48 px)
- `icon128.png` (128x128 px)

**Hızlı çözüm:** Online icon generator kullanın:
- https://www.favicon-generator.org/
- WhatsApp logosu veya yeşil bir ikon yükleyin
- 16, 48, 128 boyutlarında indirin

## 🚀 Kullanım

### Portal Tarafı
1. https://portal.ornekfirma.com/admin/pages/whatsapp-toplu-mesaj.php adresine gidin
2. Kişileri ekleyin (Manuel veya Excel)
3. Mesajınızı yazın
4. "Verileri Extension'a Gönder" butonuna tıklayın

### Extension Tarafı
1. WhatsApp Web'i açın: https://web.whatsapp.com/
2. Giriş yapın
3. Chrome sağ üstteki extension ikonuna tıklayın
4. "Başlat" butonuna tıklayın

## ⚙️ Ayarlar

Gönderim ayarları:
- **delay_min / delay_max**: Her mesaj arası bekleme (saniye)
- **frequency**: Kaç mesajda bir uzun bekleme
- **frequency_delay**: Uzun bekleme süresi (saniye)

## 🔧 Dosya Yapısı

```
chrome-extension/
├── manifest.json       # Extension yapılandırma
├── background.js       # Arkaplan servisi
├── content.js          # WhatsApp Web inject scripti
├── popup.html          # Extension arayüzü
├── popup.js            # Popup script
├── icons/              # Extension ikonları
│   ├── icon16.png
│   ├── icon48.png
│   └── icon128.png
└── README.md          # Bu dosya
```

## 📝 Özellikler

✅ Otomatik mesaj gönderme
✅ Rastgele bekleme süreleri
✅ İlerleme takibi
✅ Duraklat/Devam et
✅ Portal entegrasyonu
✅ Spam önleme mekanizması

## ⚠️ Uyarılar

- WhatsApp spam politikalarına dikkat edin
- Çok hızlı gönderim yapmayın (banlanabilirsiniz)
- Mesajlar arası minimum 15-30 saniye bekleyin
- Her 50 mesajda 1-2 dakika ara verin

## 🐛 Sorun Giderme

**Extension çalışmıyor:**
- Chrome'u yeniden başlatın
- Extension'ı kaldırıp tekrar yükleyin
- Console'da (F12) hata mesajlarını kontrol edin

**Mesajlar gönderilmiyor:**
- WhatsApp Web'de giriş yaptığınızdan emin olun
- Sayfayı yenileyin (F5)
- Extension ikonuna tıklayıp durumu kontrol edin

## 📞 Destek

Sorun yaşarsanız:
- E-posta: gelistirici@ornekfirma.com.tr
- Telefon: +90 500 123 45 67

## 📜 Lisans

© 2026 ÖRNEK Soft - Tüm hakları saklıdır.
