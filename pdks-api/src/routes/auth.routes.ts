import { Router } from 'express';
import bcrypt from 'bcryptjs';
import { getPool, sql } from '../db';
import { tokenOlustur } from '../auth';
import { logYaz, hataDetayi } from '../log';

export const authRouter = Router();

/** x-forwarded-for zincirinden yalniz ilk IP; kolon siniri 64. */
function istekIp(req: any): string | null {
  const bilesik = (req.headers['x-forwarded-for'] as string) || req.socket?.remoteAddress || null;
  return bilesik ? bilesik.split(',')[0].trim().slice(0, 64) : null;
}

/** Sifresi tanimsiz personelin ilk giriste kullanacagi varsayilan sifre. */
const VARSAYILAN_SIFRE = '123456';

/**
 * POST /api/auth/login
 * Govde: { email, sifre }  ("email" alani e-posta veya TC kimlik no olabilir)
 * Donus: { token, personel }
 *
 * Mevcut dbo.kullanicilar tablosuna gore dogrulama yapar (sadece okur).
 */
authRouter.post('/login', async (req, res) => {
  const { email, sifre } = req.body ?? {};
  const kimlik = String(email ?? '').trim();
  if (!kimlik || !sifre) {
    res.status(400).json({ hata: 'E-posta / TC kimlik no ve sifre gerekli' });
    return;
  }

  try {
    const pool = await getPool();
    // Kimlik hem e-posta hem TC kimlik no olabilir. Ayni deger birinin e-postasi
    // digerinin TC'si ise e-posta eslesmesi onceliklidir. Bos TC alanlari
    // NULLIF ile eslesme disinda tutulur.
    const result = await pool
      .request()
      .input('kimlik', sql.NVarChar, kimlik)
      .query(
        `SELECT TOP 1 kullanici_id, kullanici_email, kullanici_sifre_hash,
                kullanici_ad, kullanici_soyad, kullanici_durum, kullanici_sube_id
         FROM dbo.kullanicilar
         WHERE kullanici_email = @kimlik
            OR NULLIF(LTRIM(RTRIM(kullanici_tc_kimlik_no)), '') = @kimlik
         ORDER BY CASE WHEN kullanici_email = @kimlik THEN 0 ELSE 1 END`,
      );

    const user = result.recordset[0];
    // kullanici_durum bir 'bit' (true/false). Sadece aktif (true) kullanicilar
    // giris yapabilir; false/null pasif kabul edilir.
    if (!user || user.kullanici_durum !== true) {
      await logYaz({
        modul: 'KULLANICI', seviye: 'uyari', islem: 'giris',
        kullaniciId: user?.kullanici_id ?? null, basarili: false,
        mesaj: user ? 'Hesap pasif' : 'Kullanici bulunamadi',
        detay: { kimlik }, ip: istekIp(req),
        cihaz: String(req.headers['user-agent'] ?? '').slice(0, 400) || null,
      });
      res.status(401).json({ hata: 'Kimlik veya sifre hatali ya da hesap pasif' });
      return;
    }

    // PHP password_hash() $2y$ oneki uretir; bcryptjs ile uyum icin $2b$'ye cevir
    // ($2y ve $2b ayni algoritmadir, sadece surum etiketi farkli).
    const hash = String(user.kullanici_sifre_hash ?? '').replace(/^\$2y\$/, '$2b$');

    // Sifresi tanimsiz personel varsayilan sifre ile girer; hash varsa normal
    // bcrypt dogrulamasi yapilir.
    const eslesti = hash === ''
      ? sifre === VARSAYILAN_SIFRE
      : await bcrypt.compare(sifre, hash);

    if (!eslesti) {
      await logYaz({
        modul: 'KULLANICI', seviye: 'uyari', islem: 'giris',
        kullaniciId: user.kullanici_id, basarili: false,
        mesaj: 'Sifre hatali', detay: { kimlik }, ip: istekIp(req),
        cihaz: String(req.headers['user-agent'] ?? '').slice(0, 400) || null,
      });
      res.status(401).json({ hata: 'Kimlik veya sifre hatali' });
      return;
    }

    const adSoyad =
      `${user.kullanici_ad ?? ''} ${user.kullanici_soyad ?? ''}`.trim() ||
      user.kullanici_email;

    const token = tokenOlustur({
      kullaniciId: user.kullanici_id,
      email: user.kullanici_email,
    });

    await logYaz({
      modul: 'KULLANICI', seviye: 'bilgi', islem: 'giris',
      kullaniciId: user.kullanici_id, basarili: true,
      mesaj: 'Giris basarili',
      detay: { subeId: user.kullanici_sube_id ?? null },
      referansTablo: 'kullanicilar', referansId: user.kullanici_id,
      ip: istekIp(req),
      cihaz: String(req.headers['user-agent'] ?? '').slice(0, 400) || null,
    });

    res.json({
      token,
      personel: {
        id: user.kullanici_id,
        email: user.kullanici_email,
        adSoyad,
        subeId: user.kullanici_sube_id ?? null,
      },
    });
  } catch (err: any) {
    console.error('login hatasi:', err);
    await logYaz({
      modul: 'KULLANICI', seviye: 'hata', islem: 'giris',
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err), kimlik }, ip: istekIp(req),
      cihaz: String(req.headers['user-agent'] ?? '').slice(0, 400) || null,
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});
