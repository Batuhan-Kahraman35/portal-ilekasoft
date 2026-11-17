# Değişiklik Geçmişi

## 1.0.1 — 14.11.2026

Yalnız rehber (`docs/ornekholding.md`), kod değişikliği yok.

- e-Arşiv taslağı listede boş `STATUS` / `STATUS_CODE` ile ayırt edilir; aynı yer tutucu
  numarayla tekrar yüklemede `[10009]` hatası.
- Seri tipleri (NORMAL / INTERNET) ve `[10013]`; entegrasyondan verilen numara panel
  sayacını ilerletmez.
- e-Arşiv internet satışı (`EARSIV_TYPE = INTERNET`) zorunlu alanları için pazaryeri-modul
  rehberine yönlendirme.

## 1.0.0 — 14.11.2026

İlk sürüm. `OrnekHoldingApi` portal.ornekfirma.com sürümünden (v2.20.3 sonrası) ayrıldı.

- Projeden bağımsız: kanal okuma ve log yazma `OrnekHoldingDepo` adaptörüyle (`depoAyarla()`).
  Kurucu projenin ham kanal satırını alır, adaptör normalize eder; `aktifKanallar()` /
  `kanaldan()` imzaları korunur, `hamKanal()` eklendi.
- `APPLICATION_NAME` sabiti yerine `OrnekHoldingDepo::uygulamaAdi()`.
- `XSLT_NAME` desteği: `earsivGonder(..., $earsivTipi, $xsltAdi)` ve
  `efaturaGonder(..., $taslak, $xsltAdi)` (yalnız SendInvoice; LoadInvoice almaz).
- Rehber: `docs/ornekholding.md`.
