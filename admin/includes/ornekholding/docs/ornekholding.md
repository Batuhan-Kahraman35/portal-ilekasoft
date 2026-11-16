# Örnek Holding Servis Rehberi

Alan testleriyle doğrulanmış davranışlar. Yeni bir bulgu kodla **aynı sürümde** buraya yazılır.

## Servisler (canlı)

| Servis | WSDL |
|---|---|
| Kimlik + e-Fatura | `https://efaturaws.ornekholding.com.tr/EFaturaOIB?wsdl` |
| e-Arşiv | `https://earsivws.ornekholding.com.tr/EIArchiveWS/EFaturaArchive?wsdl` |
| Test | `efaturawstest.` / `earsivwstest.` alt alan adları |

i2i altyapısı. Şemalar **eleman sırasına duyarlıdır**; istekler string XML ile kurulur,
`SoapClient` kullanılmaz.

### Operasyonlar

- e-Fatura: `Login`, `Logout`, `CheckUser`, `SendInvoice`, `LoadInvoice` (taslak), `GetInvoice`,
  `GetInvoiceWithType` (XML/PDF), `GetInvoiceStatus(All)`, `MarkInvoice`, `CancelDraftInvoice`,
  `GetUserList(Binary)`, zarf ve uygulama yanıtı işlemleri.
- e-Arşiv: `WriteToArchive(Extended)`, `GetEArchiveInvoiceList`, `GetEArchiveInvoiceStatus`
  (WEB_KEY döner), `CancelEArchiveInvoice`, `ReadFromArchive`, rapor işlemleri.
- **Yok:** XSLT/şablon indirme ya da listeleme, seri / kullanıcı / GB etiketi listeleme.
  Bunlar yalnız Örnek Holding web panelinde görünür.

## Görüntüleme şablonu (XSLT)

| Durum | Sonuç |
|---|---|
| UBL'de XSLT gömülü | Gömülü XSLT kullanılır (Örnek Holding'deki imza/logo değişikliği **yansımaz**) |
| XSLT yok, `XSLT_NAME` yok (e-Arşiv) | **Red:** `[10013] Belge içerisinde şablon bulunamamıştır` |
| XSLT yok, `XSLT_NAME=DEFAULT` | Kabul; Örnek Holding portal şablonunu panelde yüklü **imza, logo ve IBAN tablosuyla** belgeye gömer |

- e-Arşiv: `EARSIV_PROPERTIES` sırası:
  `EARSIV_TYPE, EARSIV_EMAIL_FLAG, EARSIV_EMAIL, SUB_STATUS, ARCH_INVOICE_ID, SERI, EARCHIVE_TEST_FLAG,
  EARSIV_SMS_FLAG, SMS_PHONE_NUMBER, VALIDATION_FLAG, XSLT_NAME, ECOMMERCE_UPLOAD, INCLUDE_IBANS`.
  PDF için ayrıca `PDF_PROPERTIES/EARCHIVE_PDF_XSLT_FILENAME` var.
- e-Fatura `SendInvoice`: `SENDER, RECEIVER, XSLT_NAME, SERI_PREFIX, DISABLE_DATE_CONTROL, INVOICE`.
  `LoadInvoice` (taslak) `XSLT_NAME` **almaz** → e-Fatura taslağında yerel XSLT gömülür.
- Örnek Holding'nin `DEFAULT` e-Fatura şablonunda **imza alanı yoktur**; imza yalnız e-Arşiv'de çıkar.
- Logo yüklenmemiş hesapta "Company Logo" yerine ÖRNEK HOLDİNG logosu konur.
- Panelden elle indirilen `DEFAULT` şablon, indirildiği anki imza/IBAN'ı içine gömer
  (`%XSLT_DOWNLOAD_IBANS_TABLE%` yer tutucusu doldurulur); sonraki değişiklik yansımaz.
- Örnek Holding gömülü XSLT'nin dosya adını her faturada değiştirir (`DMY<zaman>.xslt`,
  `X01<zaman>.xslt`) ve bazı satır sonlarını CRLF yapar; içerik karşılaştırmasında satır sonu
  normalize edilmelidir.

## Taslaklar

- e-Arşiv taslak (`SUB_STATUS=DRAFT`) numara almaz; listelerde yer tutucu numarayla
  (`SERİ + YIL + 000000000`) ya da numarasız döner. Senkronda **fatura gibi alınmamalı**.
- Taslağın listedeki `STATUS` / `STATUS_CODE` alanları **boştur** (gerçek faturada ör.
  `RAPORLANDI` / `130`); numarası elle verilmiş taslak ancak bu yolla ayırt edilir.
  `GetEArchiveInvoiceStatus` taslak için `105 — TASLAK NUMARASI OLARAK EKLENDİ` döner.
- Aynı yer tutucu numarayla birden fazla taslak yüklenebilir ama bir noktadan sonra
  `[10009] Gönderilen belge daha önce gönderilmiş bir belge ile eşleşmektedir` verebilir.
- Servisten yüklenen taslak, panelin "Taslak e-Arşiv Faturalar" ekranında görünmeyebilir.

## Seri tipleri ve panel sayacı

- Panelde e-Arşiv serilerinin tipi vardır (NORMAL / INTERNET). `SERI` alanı gönderilirse tip
  kontrol edilir: `[10013] Gönderilen seri ön eki ile belge tipi farklı`. Numara `cbc:ID`'ye
  yazılıp `SERI` gönderilmezse (sıralı yöntem) kontrol yapılmaz.
- Numarası entegrasyondan verilen faturalar panelin seri sayacını **ilerletmez** (ör. TLN:
  sayaç 20, gerçek son numara 979). Aynı seriden panelden de kesiliyorsa panel sayacı
  elle güncellenmelidir; aksi hâlde panel kullanılmış numarayı vermeye çalışır.

## e-Arşiv internet satışı (EARSIV_TYPE = INTERNET)

Zorunlu UBL alanları ve ret mesajları: `pazaryeri-modul/docs/10-fatura-kesim.md` §2.
Kısaca: `WebsiteURI`, `PaymentMeans` (kod 1 ise `InstructionNote` dolu),
`Delivery/CarrierParty` (PartyIdentification VKN/TCKN + PartyName + PostalAddress) ve
`Despatch/ActualDespatchDate`.

## Bilinen hata mesajları

| Mesaj | Anlam |
|---|---|
| `[10013] Belge içerisinde şablon bulunamamıştır` | XSLT ve `XSLT_NAME` yok |
| `[10003] Belge kontrolden geçemedi: {PAKET AYRIŞTIRILAMADI}` | `INVOICE_CONTENT` boş ya da bozuk (ör. UBL dosyası okunamadı) |
| `[10008]` | Kayıt bulunamadı → boş liste sayılır |

## Listeler

- İstek başına en fazla 100 belge döner; tarih aralığı dilimlenmeli, gün içi 100'ü aşarsa
  karşı taraf VKN'siyle bölünmeli.
- e-Arşiv `START_DATE/END_DATE` tipi `xsd:dateTime`'dır (düz `Y-m-d` reddedilir).
- Aynı VKN altındaki ikinci bir Örnek Holding kullanıcısı (ör. `ornekprofilo`) ana kullanıcının
  listesinde de görünebilir; ETTN + belge türü anahtar olmalı, kanal değil.

## Girişsiz fatura linki (pazaryeri bildirimi)

- e-Arşiv: `GetEArchiveInvoiceStatus` → `WEB_KEY` herkese açık adres.
- e-Fatura: Örnek Holding'de karşılığı yok; proje kendi girişsiz sayfasını sunar
  (`/fatura.php?k=<ETTN>`, n11 için `/fatura/<ETTN>.pdf`).
