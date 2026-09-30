import { API_URL } from './config';
import { tokenGetir, cikisYap, type Personel } from './auth';

/** Token gecersizlestiginde App'in giris ekranina donmesi icin yayilan olay. */
export const OTURUM_BITTI = 'pdks:oturum-bitti';

/** Ortak fetch sarmalayicisi: JSON gonderir, token ekler, hatayi firlatir. */
async function istek<T>(
  yol: string,
  secenekler: { method?: string; body?: unknown; tokenli?: boolean } = {},
): Promise<T> {
  const { method = 'GET', body, tokenli = true } = secenekler;
  const basliklar: Record<string, string> = { 'Content-Type': 'application/json' };

  if (tokenli) {
    const token = tokenGetir();
    if (token) basliklar.Authorization = `Bearer ${token}`;
  }

  const cevap = await fetch(`${API_URL}${yol}`, {
    method,
    headers: basliklar,
    body: body ? JSON.stringify(body) : undefined,
  });

  const veri = await cevap.json().catch(() => ({}));
  if (!cevap.ok) {
    // Token gecersiz/suresi dolmus: oturumu temizle, giris ekranina don
    if (cevap.status === 401 && tokenli) {
      cikisYap();
      window.dispatchEvent(new Event(OTURUM_BITTI));
      throw new Error('Oturumunuz sona erdi. Lütfen tekrar giriş yapın.');
    }
    throw new Error(veri?.hata ?? `Istek basarisiz (${cevap.status})`);
  }
  return veri as T;
}

// ---- Marka ayarlari ----
export interface Ayarlar {
  baslik: string;
  logoUrl: string | null;
  faviconUrl: string | null;
  siteUrl: string | null;
}
export function ayarlariGetir(): Promise<Ayarlar> {
  return istek<Ayarlar>('/ayarlar', { tokenli: false });
}

// ---- Giris ----
export interface GirisSonuc {
  token: string;
  personel: Personel;
}
export function giris(email: string, sifre: string): Promise<GirisSonuc> {
  return istek<GirisSonuc>('/auth/login', {
    method: 'POST',
    body: { email, sifre },
    tokenli: false,
  });
}

/** Personel_GirisCikis.tip degerleri (mesai panosu + mola panosu). */
export type HareketTipi = 'giris' | 'cikis' | 'mola_giris' | 'mola_cikis';

// ---- QR okutma (scan) ----
export interface ScanGovde {
  qrKod: string;
  enlem?: number;
  boylam?: number;
  cihazModeli?: string;
}
export interface ScanSonuc {
  mesaj: string;
  tip: HareketTipi;
  zaman: string;
  lokasyon: string;
}
export function scan(govde: ScanGovde): Promise<ScanSonuc> {
  return istek<ScanSonuc>('/attendance/scan', { method: 'POST', body: govde });
}

// ---- QR'siz cikis (son giris yapilan sube ile) ----
export interface CikisGovde {
  enlem?: number;
  boylam?: number;
  cihazModeli?: string;
}
export function qrsizCikis(govde: CikisGovde): Promise<ScanSonuc> {
  return istek<ScanSonuc>('/attendance/cikis', { method: 'POST', body: govde });
}

// ---- Son kayitlar ----
export interface Kayit {
  Id: number;
  Tip: HareketTipi;
  Zaman: string;
  Enlem: number | null;
  Boylam: number | null;
  Lokasyon: string | null;
}
export function sonKayitlar(): Promise<{ kayitlar: Kayit[] }> {
  return istek<{ kayitlar: Kayit[] }>('/attendance/me');
}
