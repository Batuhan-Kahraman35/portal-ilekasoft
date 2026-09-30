/**
 * Token ve oturum bilgisi yonetimi (localStorage).
 */
const TOKEN_ANAHTAR = 'pdks_token';
const PERSONEL_ANAHTAR = 'pdks_personel';

export interface Personel {
  id: number;
  email: string;
  adSoyad: string;
  subeId: number | null;
}

export function tokenKaydet(token: string, personel: Personel): void {
  localStorage.setItem(TOKEN_ANAHTAR, token);
  localStorage.setItem(PERSONEL_ANAHTAR, JSON.stringify(personel));
}

export function tokenGetir(): string | null {
  return localStorage.getItem(TOKEN_ANAHTAR);
}

export function personelGetir(): Personel | null {
  const veri = localStorage.getItem(PERSONEL_ANAHTAR);
  return veri ? (JSON.parse(veri) as Personel) : null;
}

export function cikisYap(): void {
  localStorage.removeItem(TOKEN_ANAHTAR);
  localStorage.removeItem(PERSONEL_ANAHTAR);
}
