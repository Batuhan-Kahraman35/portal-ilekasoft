# ÖRNEK WhatsApp Bulk Sender - Kurulum Kılavuzu

## 📦 Versiyon: 1.3.6

### 🚀 Kurulum Adımları

#### 1. Chrome Geliştirici Modunu Aktif Et
1. Google Chrome tarayıcısını aç
2. Adres çubuğuna `chrome://extensions/` yaz ve Enter'a bas
3. Sağ üst köşede **"Geliştirici modu"** (Developer mode) anahtarını aç

#### 2. Eklentiyi Yükle
1. Sol üst köşede **"Paketlenmemiş öğe yükle"** (Load unpacked) butonuna tıkla
2. İndirdiğin ZIP dosyasını çıkarttığın klasörü seç
3. "Klasör seç" butonuna tıkla

#### 3. Eklenti Aktif!
- ✅ Eklenti Chrome toolbar'ına eklendi
- ✅ Yeşil WhatsApp ikonu görünüyor olmalı
- ✅ Artık Portal'dan toplu mesaj gönderebilirsin

### 📋 Kullanım

1. **Portal'a git:**
   - https://portal.ornekfirma.com/admin/pages/whatsapp-toplu-mesaj.php

2. **Kişileri ekle:**
   - Manuel veya Excel dosyasından kişi yükle
   - Mesajını yaz
   - Ayarları düzenle (gecikme süreleri vb.)

3. **Gönder:**
   - "Extension'a Gönder" butonuna bas
   - WhatsApp Web sekmesi açılacak
   - Mesajlar otomatik olarak gönderilmeye başlayacak!

### 🔧 Özellikler

- ✅ Otomatik mesaj gönderimi
- ✅ Kişiye özel değişkenler ({ad}, {telefon}, {ek1}, {ek2}, {ek3})
- ✅ **Zaman damgası (spam önleme)** - Her mesaja benzersiz tarih/saat ekler
- ✅ Gecikme ayarları (saniye bazında)
- ✅ Frekans kontrolü (her X mesajda uzun bekleme)
- ✅ İlerleme takibi (eklenti ikonundan)
- ✅ Tek sekme kullanımı (performans)

### 🕐 Zaman Damgası Nedir?

**Sorun:** Aynı mesajı 100 kişiye gönderirsen, WhatsApp bunu **spam** görebilir.

**Çözüm:** Her mesaja benzersiz zaman damgası ekleyerek, her mesajın farklı olmasını sağlarız.

**Örnek:**
```
Merhaba! Yeni ürünlerimizi inceleyebilirsiniz.

[15.01.2026 14:23:45]
```

Her mesajın sonunda farklı tarih/saat olacağı için WhatsApp spam algılamaz.

**Nasıl Kullanılır:**
- Portal'da "Mesaja zaman damgası ekle" checkbox'ını işaretle
- Gönderim başladığında otomatik eklenir
- Kullanıcı mesajın sonunda görmez (WhatsApp API ile eklenir)

### ⚙️ Gereksinimler

- Google Chrome veya Edge tarayıcı
- WhatsApp Web hesabı
- İnternet bağlantısı

### 🆘 Sorun Giderme

**Eklenti çalışmıyor:**
1. chrome://extensions/ sayfasını yenile
2. Eklentiyi kapat/aç
3. Chrome'u yeniden başlat

**Mesajlar gönderilmiyor:**
1. WhatsApp Web'de oturum açık mı kontrol et
2. Telefon numaraları başında +90 olmalı
3. Extension console'a bak (F12 → Console)

**Syntax hatası alıyorum:**
1. Eski eklentiyi kaldır
2. Chrome'u yeniden başlat
3. Eklentiyi tekrar yükle

### 📞 Destek

Sorun yaşarsan:
- 📧 E-posta: gelistirici@ornekfirma.com.tr
- 📱 Telefon: +90 500 123 45 67

### 📝 Notlar

- Eklenti sadece WhatsApp Web sayfasında çalışır
- Gönderim sırasında WhatsApp Web sekmesini kapatma
- Spam önlemek için gecikme sürelerini ayarla
- Her 50 mesajda 60 saniye bekleme önerilir

### 🔄 Güncelleme

Yeni versiyon çıktığında:
1. Portal'dan yeni versiyonu indir
2. chrome://extensions/ → Eklentiyi kaldır
3. Yeni versiyonu yükle

---

**© 2026 ÖRNEK Soft - Tüm hakları saklıdır.**
