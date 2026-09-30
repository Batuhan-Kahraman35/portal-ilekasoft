# OrnekSoft Portal

Portal yönetim sistemi - PHP + MSSQL

## 📋 Gereksinimler

- PHP 7.4+
- MSSQL Server
- IIS / Apache
- PHP SQLSRV Extension

## 🚀 Kurulum

### 1. Hassas Dosyaları Yapılandırın

```bash
# Config dosyalarını kopyalayın
copy config\database.example.php config\database.php
copy config\mail.example.php config\mail.php
copy config\sms.example.php config\sms.php
```

### 2. Veritabanını Oluşturun

```sql
-- config/portal_ornekfirma_DB.sql dosyasını MSSQL'de çalıştırın
```

### 3. Config Dosyalarını Düzenleyin

**config/database.php:**
- Veritabanı bağlantı bilgilerinizi girin

**config/mail.php:**
- SMTP ayarlarınızı yapılandırın

**config/sms.php:**
- SMS provider bilgilerinizi girin

## 📁 Proje Yapısı

```
portal.ornekfirma.com/
├── admin/              # Yönetim paneli
│   ├── assets/        # CSS, JS, resimler
│   ├── includes/      # Header, sidebar
│   └── pages/         # Admin sayfaları
├── config/            # Yapılandırma dosyaları
├── logs/              # Log dosyaları
└── index.php          # Ana sayfa
```

## 🔐 Güvenlik

- `.gitignore` ile hassas dosyalar korunur
- Config dosyaları repo'da paylaşılmaz
- Şifreler hash'lenir (password_hash)

## 👨‍💻 Geliştirici

**Batuhan Kahraman**
- 📧 gelistirici@ornekfirma.com.tr
- 📞 +90 500 123 45 67
- 🔗 [GitHub](https://github.com/Batuhan-Kahraman/)

## 📝 Lisans

OrnekSoft © 2025
