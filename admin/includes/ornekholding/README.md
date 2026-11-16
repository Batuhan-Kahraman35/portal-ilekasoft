# Örnek Holding Modülü

> **EN:** A reusable PHP 8.3 module for the Örnek Holding (i2i based) e-Invoice / e-Archive SOAP service,
> plus field-tested notes on its behaviour. Written in Turkish.

Projeler arasında ortak kullanılan Örnek Holding e-Fatura / e-Arşiv kodu ve servis rehberi.

| | |
|---|---|
| Oluşturma | 14.11.2026 |
| Sürüm | 1.0.0 — bkz. [CHANGELOG.md](CHANGELOG.md) |
| Kullanan projeler | portal.ornekfirma.com (1.0.0) · ornekmusteri.com.tr (geçiş bekliyor) · osb.ornekmusteribmuhendislik.com.tr (geçiş bekliyor) |

## İçerik

```
src/OrnekHoldingApi.php   SOAP katmanı: oturum, mükellef sorgusu, e-Fatura/e-Arşiv gönderim ve taslak,
                       liste, UBL XML, PDF, e-Arşiv durum/WEB_KEY, serinin son sıra numarası
src/OrnekHoldingDepo.php  Projeye bağlanma arayüzü (kanal ayarı + log)
ornek/                 Örnek adaptör (portal uyarlaması)
docs/ornekholding.md      Servis davranışları, XSD sıraları, hata kodları ve tuzaklar
```

Fatura kesim akışı (sipariş → UBL → kesim → pazaryeri bildirimi) `pazaryeri-modul` içindeki
`FaturaKesim` / `FaturaUbl` sınıflarındadır ve bu modülün `OrnekHoldingApi`'sini kullanır.

## Projeye kurulum

1. Modül projeye `admin/includes/ornekholding/` olarak girer (subtree ya da kopya):

   ```powershell
   git subtree add --prefix=admin/includes/ornekholding ornekholding-modul main --squash
   ```

2. `ornek/OrnekOrnekHoldingDepo.php` örnek alınarak projenin adaptörü yazılır
   (kanal tablosu, ayar JSON'u, log tablosu projeye göre).
3. Projenin `admin/includes/OrnekHoldingApi.php` dosyası açılış dosyasına dönüşür:

   ```php
   require_once __DIR__ . '/../db.php';
   require_once __DIR__ . '/ornekholding/src/OrnekHoldingApi.php';
   require_once __DIR__ . '/ProjeOrnekHoldingDepo.php';
   OrnekHoldingApi::depoAyarla(new ProjeOrnekHoldingDepo(Database::getInstance()));
   ```

   Böylece `require_once 'OrnekHoldingApi.php'` yapan mevcut kod değişmeden çalışır.

> Not: Modül klasörü (`D:\Inetpub\moduller`) web sitelerinin open_basedir sınırı dışındadır;
> çalışma anında her zaman projedeki kopya kullanılır.

## Kanal ayarları (normalize, `OrnekHoldingDepo::normalize`)

| Anahtar | Açıklama |
|---|---|
| `KimlikDogrulama`, `EFatura`, `EArsiv` | WSDL adresleri (kodda hardcode yok) |
| `VKN` | Gönderici VKN; yoksa firmanın vergi no'su |
| `GbEtiket` | e-Fatura gönderici etiketi (`urn:mail:...`) |
| `EFaturaSeri`, `EArsivSeri` | 3 karakter seri öneki |
| `NumaraYontemi` | `sirali` (kesin kesimde zorunlu) / `seri` |
| `XsltAdi` | Örnek Holding portalındaki şablon adı (ör. `DEFAULT`) → `XSLT_NAME` |
| `XsltKlasoru` | Yerel XSLT klasörü (gömme; `XsltAdi` yoksa ya da e-Fatura taslağında) |
| `Ortam` | `TEST` → test ortamı (`EARCHIVE_TEST_FLAG=Y`) |

## Güncelleme

```powershell
git subtree pull --prefix=admin/includes/ornekholding ornekholding-modul main --squash
git subtree push --prefix=admin/includes/ornekholding ornekholding-modul main
```
