import { Router } from 'express';
import { getPool, sql } from '../db';

export const ayarlarRouter = Router();

/**
 * Marka ayarlarini tanim_site_ayarlari tablosundan (en guncel kayit) okur.
 * PHP tarafiyla ayni mantik: TOP 1 ... ORDER BY site_ayarlari_id DESC.
 * Deger yoksa makul varsayilanlara duser.
 */
async function ayarlariGetir() {
  const pool = await getPool();
  const r = await pool.request().query(`
    SELECT TOP 1
      site_ayarlari_site_title  AS baslik,
      site_ayarlari_logo_url    AS logoUrl,
      site_ayarlari_favicon_url AS faviconUrl,
      site_ayarlari_site_url    AS siteUrl
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
  `);
  const a = r.recordset[0] ?? {};
  return {
    baslik: a.baslik ?? 'Portal',
    logoUrl: a.logoUrl ?? null,
    faviconUrl: a.faviconUrl ?? null,
    siteUrl: a.siteUrl ?? null,
  };
}

/**
 * GET /api/ayarlar
 * PWA acilista cagirir; sekme basligi ve header logosu buradan gelir.
 */
ayarlarRouter.get('/ayarlar', async (_req, res) => {
  try {
    res.json(await ayarlariGetir());
  } catch (err: any) {
    console.error('ayarlar hatasi:', err);
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});

/**
 * GET /api/manifest
 * PWA manifest'ini DB'deki marka degerleriyle DINAMIK uretir.
 * HTML'de: <link rel="manifest" href="/pdks-api/api/manifest">
 */
ayarlarRouter.get('/manifest', async (_req, res) => {
  try {
    const a = await ayarlariGetir();
    const ikon = a.logoUrl ?? a.faviconUrl;

    const manifest: Record<string, unknown> = {
      name: a.baslik,
      short_name: a.baslik,
      start_url: '/app/',
      scope: '/app/',
      display: 'standalone',
      background_color: '#ffffff',
      theme_color: '#0d6efd',
      lang: 'tr-TR',
      icons: ikon
        ? [
            { src: ikon, sizes: '192x192', type: 'image/png', purpose: 'any' },
            { src: ikon, sizes: '512x512', type: 'image/png', purpose: 'any' },
          ]
        : [],
    };

    res.setHeader('Content-Type', 'application/manifest+json; charset=utf-8');
    res.send(JSON.stringify(manifest));
  } catch (err: any) {
    console.error('manifest hatasi:', err);
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});
