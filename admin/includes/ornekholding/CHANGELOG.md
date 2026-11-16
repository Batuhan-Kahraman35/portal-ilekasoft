# Değişiklik Geçmişi

## 1.0.0 — 14.11.2026

İlk sürüm. `OrnekHoldingApi` portal.ornekfirma.com sürümünden (v2.20.3 sonrası) ayrıldı.

- Projeden bağımsız: kanal okuma ve log yazma `OrnekHoldingDepo` adaptörüyle (`depoAyarla()`).
  Kurucu projenin ham kanal satırını alır, adaptör normalize eder; `aktifKanallar()` /
  `kanaldan()` imzaları korunur, `hamKanal()` eklendi.
- `APPLICATION_NAME` sabiti yerine `OrnekHoldingDepo::uygulamaAdi()`.
- `XSLT_NAME` desteği: `earsivGonder(..., $earsivTipi, $xsltAdi)` ve
  `efaturaGonder(..., $taslak, $xsltAdi)` (yalnız SendInvoice; LoadInvoice almaz).
- Rehber: `docs/ornekholding.md`.
