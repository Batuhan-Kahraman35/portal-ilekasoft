import { Router } from 'express';
import { getPool, sql } from '../db';
import { girisGerekli, AuthRequest } from '../auth';
import { logYaz, hataDetayi } from '../log';

export const attendanceRouter = Router();

// Bu router'daki tum endpoint'ler giris (token) gerektirir.
attendanceRouter.use(girisGerekli);

/**
 * Istek IP'sini kolon sinirina (nvarchar(50)) uygun sekilde cozer.
 * x-forwarded-for proxy zincirinde "ip1, ip2, ip3" gelebilir; yalniz ilki alinir.
 */
function istekIp(req: AuthRequest): string | null {
  const bilesik = (req.headers['x-forwarded-for'] as string) || req.socket.remoteAddress || null;
  if (!bilesik) return null;
  return bilesik.split(',')[0].trim().slice(0, 50);
}

/**
 * Cihaz modeli (user-agent) kolon siniri nvarchar(200). Uzun WebView UA
 * metinleri INSERT'i komple dusurmesin diye kirpilir.
 */
function kirpCihaz(deger: unknown): string | null {
  if (deger == null) return null;
  return String(deger).slice(0, 200);
}

/**
 * Iki koordinat arasi mesafeyi metre cinsinden hesaplar (Haversine).
 * Geofence kontrolu tamamen sunucuda yapilir; istemciye guvenilmez.
 */
function mesafeMetre(
  enlem1: number,
  boylam1: number,
  enlem2: number,
  boylam2: number
): number {
  const R = 6371000; // dunya yaricapi (m)
  const rad = (d: number) => (d * Math.PI) / 180;
  const dLat = rad(enlem2 - enlem1);
  const dLon = rad(boylam2 - boylam1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(rad(enlem1)) * Math.cos(rad(enlem2)) * Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/**
 * Sube icin geofence tanimliysa konumu dogrular.
 * Uygunsa null, degilse istemciye donulecek hata nesnesini uretir.
 */
function geofenceKontrol(
  sube: any,
  enlem: number | null | undefined,
  boylam: number | null | undefined
): { durum: number; govde: any } | null {
  const geofenceVar =
    sube.sube_enlem != null &&
    sube.sube_boylam != null &&
    sube.sube_yaricap_metre != null;
  if (!geofenceVar) return null;

  if (enlem == null || boylam == null) {
    return { durum: 400, govde: { hata: 'Konum bilgisi gerekli' } };
  }
  const uzaklik = mesafeMetre(sube.sube_enlem, sube.sube_boylam, enlem, boylam);
  if (uzaklik > sube.sube_yaricap_metre) {
    return {
      durum: 403,
      govde: {
        hata: 'Sube konumunun disindasiniz',
        uzaklikMetre: Math.round(uzaklik),
        izinliMetre: sube.sube_yaricap_metre,
      },
    };
  }
  return null;
}

/** Kayit tipine gore istemciye gosterilecek metin. */
const TIP_METIN: Record<string, string> = {
  giris: 'Giris yapildi',
  cikis: 'Cikis yapildi',
  mola_giris: 'Mola baslatildi',
  mola_cikis: 'Mola bitirildi',
};

/**
 * Okutulan QR'in turune (mesai panosu / mola panosu) ve o gunku son kayda gore
 * yazilacak tipi belirler. Sirasi bozan okutmalar hata olarak doner.
 *
 * Gecerli akis: giris -> (mola_giris -> mola_cikis)* -> cikis
 */
function tipBelirle(
  qrTuru: 'mesai' | 'mola',
  sonTip: string | undefined
): { tip: string } | { hata: string } {
  const acikGiris = sonTip === 'giris' || sonTip === 'mola_cikis';

  if (qrTuru === 'mesai') {
    if (sonTip === 'mola_giris') {
      return { hata: 'Molaniz devam ediyor. Once mola panosundan molayi bitirin.' };
    }
    return { tip: acikGiris ? 'cikis' : 'giris' };
  }

  if (sonTip === 'mola_giris') return { tip: 'mola_cikis' };
  if (acikGiris) return { tip: 'mola_giris' };
  return { hata: 'Acik giris kaydiniz yok. Once giris yapmalisiniz.' };
}

/**
 * POST /api/attendance/scan
 * QR okutuldugunda cagrilir. Tip, okutulan QR'in hangi kolona (sube_qr /
 * sube_mola_qr) ait oldugu ve o gunku son kayit birlikte degerlendirilerek
 * belirlenir; istemci tip gonderemez.
 *
 * Govde: { qrKod, enlem?, boylam?, cihazModeli?, cihazId? }
 */
attendanceRouter.post('/scan', async (req: AuthRequest, res) => {
  const { qrKod, enlem, boylam, cihazModeli, cihazId } = req.body ?? {};
  const kullaniciId = req.auth!.kullaniciId;

  const ip = istekIp(req);
  const cihaz = kirpCihaz(cihazModeli);

  if (!qrKod) {
    await logYaz({
      modul: 'PDKS', seviye: 'uyari', islem: 'qr_okut', kullaniciId,
      basarili: false, mesaj: 'QR kod gonderilmedi', ip, cihaz,
    });
    res.status(400).json({ hata: 'QR kod gerekli' });
    return;
  }

  try {
    const pool = await getPool();

    // 1) QR kod gecerli ve aktif bir subeye mi ait? Mesai panosu (sube_qr) ile
    //    mola panosu (sube_mola_qr) ayni sorguda cozulur; eslesen kolon hareket
    //    turunu belirler.
    const lok = await pool
      .request()
      .input('qrKod', sql.NVarChar, qrKod)
      .query(`SELECT TOP 1 sube_id, sube_adi, sube_enlem, sube_boylam, sube_yaricap_metre,
                     CASE WHEN sube_qr = @qrKod THEN 'mesai' ELSE 'mola' END AS qr_turu
              FROM dbo.Subeler
              WHERE (sube_qr = @qrKod OR sube_mola_qr = @qrKod) AND sube_durum = 1`);
    if (lok.recordset.length === 0) {
      await logYaz({
        modul: 'PDKS', seviye: 'uyari', islem: 'qr_okut', kullaniciId,
        basarili: false, mesaj: 'Gecersiz QR kod',
        detay: { qrKod, enlem, boylam }, ip, cihaz,
      });
      res.status(400).json({ hata: 'Gecersiz QR kod' });
      return;
    }
    const sube = lok.recordset[0];
    const subeId = sube.sube_id;
    const subeAdi = sube.sube_adi;
    const qrTuru: 'mesai' | 'mola' = sube.qr_turu === 'mola' ? 'mola' : 'mesai';

    // 1b) Geofence: sube icin konum tanimliysa mesafeyi SUNUCUDA dogrula.
    const geoHata = geofenceKontrol(sube, enlem, boylam);
    if (geoHata) {
      await logYaz({
        modul: 'PDKS', seviye: 'uyari', islem: 'qr_okut', kullaniciId,
        basarili: false, mesaj: geoHata.govde.hata,
        detay: { qrKod, subeId, enlem, boylam, ...geoHata.govde },
        referansTablo: 'Subeler', referansId: subeId, ip, cihaz,
      });
      res.status(geoHata.durum).json(geoHata.govde);
      return;
    }

    // 2) Bugunku son kayda ve QR turune gore tipi belirle
    const son = await pool
      .request()
      .input('kullaniciId', sql.Int, kullaniciId)
      .query(`SELECT TOP 1 tip FROM dbo.Personel_GirisCikis
              WHERE kullanici_id = @kullaniciId
                AND CAST(zaman AS DATE) = CAST(SYSDATETIME() AS DATE)
              ORDER BY zaman DESC, kayit_id DESC`);
    const sonTip: string | undefined = son.recordset[0]?.tip;

    const karar = tipBelirle(qrTuru, sonTip);
    if ('hata' in karar) {
      await logYaz({
        modul: 'PDKS', seviye: 'uyari', islem: 'qr_okut', kullaniciId,
        basarili: false, mesaj: karar.hata,
        detay: { qrKod, subeId, qrTuru, sonTip: sonTip ?? null },
        referansTablo: 'Subeler', referansId: subeId, ip, cihaz,
      });
      res.status(400).json({ hata: karar.hata });
      return;
    }
    const yeniTip = karar.tip;

    // 3) Kaydi ekle
    const ins = await pool
      .request()
      .input('kullaniciId', sql.Int, kullaniciId)
      .input('subeId', sql.Int, subeId)
      .input('tip', sql.NVarChar(20), yeniTip)
      .input('enlem', sql.Float, enlem ?? null)
      .input('boylam', sql.Float, boylam ?? null)
      .input('qrKod', sql.NVarChar(200), String(qrKod).slice(0, 200))
      .input('cihazModeli', sql.NVarChar(200), cihaz)
      .input('cihazId', sql.NVarChar(200), cihazId ? String(cihazId).slice(0, 200) : null)
      .input('ip', sql.NVarChar(50), ip)
      .query(`INSERT INTO dbo.Personel_GirisCikis
                (kullanici_id, sube_id, tip, enlem, boylam, qr_kod, cihaz_modeli, cihaz_id, ip_adresi)
              OUTPUT INSERTED.kayit_id, INSERTED.tip, INSERTED.zaman
              VALUES (@kullaniciId, @subeId, @tip, @enlem, @boylam, @qrKod, @cihazModeli, @cihazId, @ip)`);

    const kayit = ins.recordset[0];
    await logYaz({
      modul: 'PDKS', seviye: 'bilgi', islem: 'qr_okut', kullaniciId,
      basarili: true,
      mesaj: `${TIP_METIN[yeniTip] ?? yeniTip} - ${subeAdi}`,
      detay: { subeId, qrTuru, tip: kayit.tip, enlem, boylam },
      referansTablo: 'Personel_GirisCikis', referansId: kayit.kayit_id,
      ip, cihaz,
    });
    res.json({
      mesaj: TIP_METIN[yeniTip] ?? yeniTip,
      tip: kayit.tip,
      zaman: kayit.zaman,
      lokasyon: subeAdi,
    });
  } catch (err: any) {
    console.error('scan hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'qr_okut', kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err), qrKod, enlem, boylam },
      ip, cihaz,
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});

/**
 * POST /api/attendance/cikis
 * QR okutmadan cikis. Personelin acik giris kaydi (bugunku son kayit 'giris')
 * varsa, ayni sube/QR bilgisiyle 'cikis' kaydi olusturur.
 * Geofence (sube konumu + yaricap) tanimliysa yine SUNUCUDA dogrulanir.
 *
 * Govde: { enlem?, boylam?, cihazModeli?, cihazId? }
 */
attendanceRouter.post('/cikis', async (req: AuthRequest, res) => {
  const { enlem, boylam, cihazModeli, cihazId } = req.body ?? {};
  const kullaniciId = req.auth!.kullaniciId;
  const ip = istekIp(req);
  const cihaz = kirpCihaz(cihazModeli);

  try {
    const pool = await getPool();

    // 1) Bugunku son kayit 'giris' mi? Degilse cikis yapilamaz.
    const son = await pool
      .request()
      .input('kullaniciId', sql.Int, kullaniciId)
      .query(`SELECT TOP 1 tip, sube_id, qr_kod FROM dbo.Personel_GirisCikis
              WHERE kullanici_id = @kullaniciId
                AND CAST(zaman AS DATE) = CAST(SYSDATETIME() AS DATE)
              ORDER BY zaman DESC, kayit_id DESC`);
    const sonKayit = son.recordset[0];
    const karar = tipBelirle('mesai', sonKayit?.tip);
    if ('hata' in karar || karar.tip !== 'cikis') {
      const hata = 'hata' in karar ? karar.hata : 'Acik giris kaydiniz yok';
      await logYaz({
        modul: 'PDKS', seviye: 'uyari', islem: 'qrsiz_cikis', kullaniciId,
        basarili: false, mesaj: hata,
        detay: { sonTip: sonKayit?.tip ?? null }, ip, cihaz,
      });
      res.status(400).json({ hata });
      return;
    }

    // 2) Giris yapilan subeyi al (geofence ve isim icin)
    const lok = await pool
      .request()
      .input('subeId', sql.Int, sonKayit.sube_id)
      .query(`SELECT TOP 1 sube_id, sube_adi, sube_enlem, sube_boylam, sube_yaricap_metre
              FROM dbo.Subeler
              WHERE sube_id = @subeId AND sube_durum = 1`);
    if (lok.recordset.length === 0) {
      await logYaz({
        modul: 'PDKS', seviye: 'uyari', islem: 'qrsiz_cikis', kullaniciId,
        basarili: false, mesaj: 'Giris yapilan sube bulunamadi',
        detay: { subeId: sonKayit.sube_id }, ip, cihaz,
      });
      res.status(400).json({ hata: 'Giris yapilan sube bulunamadi' });
      return;
    }
    const sube = lok.recordset[0];

    // 3) Geofence dogrulamasi (QR okutulmus gibi ayni kural)
    const geoHata = geofenceKontrol(sube, enlem, boylam);
    if (geoHata) {
      await logYaz({
        modul: 'PDKS', seviye: 'uyari', islem: 'qrsiz_cikis', kullaniciId,
        basarili: false, mesaj: geoHata.govde.hata,
        detay: { subeId: sube.sube_id, enlem, boylam, ...geoHata.govde },
        referansTablo: 'Subeler', referansId: sube.sube_id, ip, cihaz,
      });
      res.status(geoHata.durum).json(geoHata.govde);
      return;
    }

    // 4) Cikis kaydini ekle (giris ile ayni QR kodu ile)
    const ins = await pool
      .request()
      .input('kullaniciId', sql.Int, kullaniciId)
      .input('subeId', sql.Int, sube.sube_id)
      .input('enlem', sql.Float, enlem ?? null)
      .input('boylam', sql.Float, boylam ?? null)
      .input('qrKod', sql.NVarChar(200), sonKayit.qr_kod ? String(sonKayit.qr_kod).slice(0, 200) : null)
      .input('cihazModeli', sql.NVarChar(200), cihaz)
      .input('cihazId', sql.NVarChar(200), cihazId ? String(cihazId).slice(0, 200) : null)
      .input('ip', sql.NVarChar(50), ip)
      .query(`INSERT INTO dbo.Personel_GirisCikis
                (kullanici_id, sube_id, tip, enlem, boylam, qr_kod, cihaz_modeli, cihaz_id, ip_adresi)
              OUTPUT INSERTED.kayit_id, INSERTED.tip, INSERTED.zaman
              VALUES (@kullaniciId, @subeId, 'cikis', @enlem, @boylam, @qrKod, @cihazModeli, @cihazId, @ip)`);

    const kayit = ins.recordset[0];
    await logYaz({
      modul: 'PDKS', seviye: 'bilgi', islem: 'qrsiz_cikis', kullaniciId,
      basarili: true, mesaj: `Cikis kaydi olusturuldu - ${sube.sube_adi}`,
      detay: { subeId: sube.sube_id, enlem, boylam },
      referansTablo: 'Personel_GirisCikis', referansId: kayit.kayit_id,
      ip, cihaz,
    });
    res.json({
      mesaj: 'Cikis yapildi',
      tip: kayit.tip,
      zaman: kayit.zaman,
      lokasyon: sube.sube_adi,
    });
  } catch (err: any) {
    console.error('cikis hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'qrsiz_cikis', kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err), enlem, boylam }, ip, cihaz,
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});

/**
 * GET /api/attendance/me
 * Giris yapan kullanicinin son 20 kaydini doner.
 * (Kolonlar mobil tarafla uyumlu olsun diye Id/Tip/Zaman/... olarak adlandirilir.)
 */
attendanceRouter.get('/me', async (req: AuthRequest, res) => {
  try {
    const pool = await getPool();
    const result = await pool
      .request()
      .input('kullaniciId', sql.Int, req.auth!.kullaniciId)
      .query(`SELECT TOP 20
                g.kayit_id AS Id, g.tip AS Tip, g.zaman AS Zaman,
                g.enlem AS Enlem, g.boylam AS Boylam,
                s.sube_adi AS Lokasyon
              FROM dbo.Personel_GirisCikis g
              LEFT JOIN dbo.Subeler s ON s.sube_id = g.sube_id
              WHERE g.kullanici_id = @kullaniciId
              ORDER BY g.zaman DESC`);
    res.json({ kayitlar: result.recordset });
  } catch (err: any) {
    console.error('me hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'son_kayitlar',
      kullaniciId: req.auth!.kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err) },
      ip: istekIp(req), cihaz: kirpCihaz(req.headers['user-agent']),
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});
