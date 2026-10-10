import { Router } from 'express';
import { getPool, sql } from '../db';
import { girisGerekli, AuthRequest } from '../auth';
import { logYaz, hataDetayi } from '../log';

/**
 * Windows masaustu uygulamasi (pdks-masaustu) uc noktalari.
 *
 * QR ve geofence yoktur; kimlik yalniz token ile dogrulanir. Hareketsizlikten
 * dogan kayitlar istemci tarafindan geriye donuk saatle gonderilir ve
 * Personel_GirisCikis.otomatik = 1 olarak isaretlenir.
 */
export const masaustuRouter = Router();
masaustuRouter.use(girisGerekli);

/** Ekip kapsami web panelindeki bu sayfanin yetkisinden okunur. */
const KAPSAM_SAYFASI = 'pdks-giris-cikis.php';
/** Administrator departmani: tum yetkiler acik (PageAuth.php ile ayni). */
const ADMIN_DEPARTMAN_ID = 1;
/** Geriye donuk saat icin istemci saat sapmasi toleransi. */
const SAAT_TOLERANS_MS = 60 * 1000;

type HareketTip = 'giris' | 'cikis' | 'mola_giris' | 'mola_cikis';
const HAREKET_TIPLERI: HareketTip[] = ['giris', 'cikis', 'mola_giris', 'mola_cikis'];
/** Otomatik (hareketsizlik) kaydi yalniz bu tiplerde olabilir. */
const OTOMATIK_TIPLER: HareketTip[] = ['mola_giris', 'cikis'];

const TIP_METIN: Record<string, string> = {
  giris: 'Giris yapildi',
  cikis: 'Cikis yapildi',
  mola_giris: 'Mola baslatildi',
  mola_cikis: 'Mola bitirildi',
};

function istekIp(req: AuthRequest): string | null {
  const bilesik = (req.headers['x-forwarded-for'] as string) || req.socket.remoteAddress || null;
  if (!bilesik) return null;
  return bilesik.split(',')[0].trim().slice(0, 50);
}

function kirp(deger: unknown, uzunluk: number): string | null {
  if (deger == null || deger === '') return null;
  return String(deger).slice(0, uzunluk);
}

/** tanim_pdks_ayarlari'ni anahtar => deger olarak okur, eksikleri varsayilanla doldurur. */
async function pdksAyarlari(): Promise<{ hareketsizMolaDk: number; hareketsizCikisDk: number }> {
  const pool = await getPool();
  const r = await pool.request().query(`
    SELECT pdks_ayar_anahtar, pdks_ayar_deger
    FROM dbo.tanim_pdks_ayarlari
    WHERE pdks_ayar_durum = 1`);
  const harita = new Map<string, string>();
  for (const s of r.recordset) harita.set(s.pdks_ayar_anahtar, s.pdks_ayar_deger);

  const sayi = (anahtar: string, varsayilan: number) => {
    const d = parseInt(harita.get(anahtar) ?? '', 10);
    return Number.isFinite(d) && d > 0 ? d : varsayilan;
  };
  const mola = sayi('hareketsiz_mola_dk', 15);
  let cikis = sayi('hareketsiz_cikis_dk', 120);
  // Cikis esigi mola esiginden kucuk olamaz; hatali ayarda mola esiginin 2 kati alinir.
  if (cikis <= mola) cikis = mola * 2;
  return { hareketsizMolaDk: mola, hareketsizCikisDk: cikis };
}

/**
 * Kullanicinin ekip kapsami. PHP tarafiyla (pdks-giris-cikis.php + PageAuth.php)
 * ayni kural: Administrator veya sube_gor yetkisi -> tum aktif personel;
 * aksi halde kendisi + kullanici_ust_id hiyerarsisindeki tum alt kademe.
 */
async function ekipKapsami(kullaniciId: number): Promise<{ tumu: boolean; idler: number[] }> {
  const pool = await getPool();
  const yetki = await pool
    .request()
    .input('kullaniciId', sql.Int, kullaniciId)
    .input('sayfa', sql.NVarChar, KAPSAM_SAYFASI)
    .query(`
      SELECT k.kullanici_departman_id AS departman_id,
             CAST(ISNULL(y.sube_gor, 0) AS BIT) AS sube_gor
      FROM dbo.kullanicilar k
      OUTER APPLY (
        SELECT TOP 1 my.sube_gor
        FROM dbo.menu_sayfa_yetkiler my
        INNER JOIN dbo.tanim_sayfalar s ON s.sayfalar_id = my.sayfa_id
        WHERE my.departman_id = k.kullanici_departman_id
          AND my.durum = 1
          AND s.sayfalar_durum = 1
          AND RTRIM(LTRIM(s.sayfalar_sayfa_url)) = @sayfa
      ) y
      WHERE k.kullanici_id = @kullaniciId`);
  const y = yetki.recordset[0];
  if (y && (y.departman_id === ADMIN_DEPARTMAN_ID || y.sube_gor === true)) {
    return { tumu: true, idler: [] };
  }

  // MAXRECURSION hatali veriyle olusabilecek donguyu (A -> B -> A) keser.
  const ekip = await pool
    .request()
    .input('kullaniciId', sql.Int, kullaniciId)
    .query(`
      WITH Ekip AS (
        SELECT kullanici_id FROM dbo.kullanicilar WHERE kullanici_id = @kullaniciId
        UNION ALL
        SELECT k.kullanici_id
        FROM dbo.kullanicilar k
        INNER JOIN Ekip e ON k.kullanici_ust_id = e.kullanici_id
      )
      SELECT DISTINCT kullanici_id FROM Ekip
      OPTION (MAXRECURSION 20)`);
  return { tumu: false, idler: ekip.recordset.map((r: any) => r.kullanici_id) };
}

/** Kapsamdaki kullanici id'lerini IN (...) icin parametreler; tumu ise bos kosul. */
function kapsamKosulu(
  istek: sql.Request,
  kapsam: { tumu: boolean; idler: number[] },
  kolon: string,
): string {
  if (kapsam.tumu) return '';
  if (kapsam.idler.length === 0) return ' AND 1 = 0';
  const adlar = kapsam.idler.map((id, i) => {
    istek.input(`k${i}`, sql.Int, id);
    return `@k${i}`;
  });
  return ` AND ${kolon} IN (${adlar.join(',')})`;
}

/**
 * Personelin durumunu son kayda gore ozetler.
 * Gun sonunda kapatilmamis kayit ertesi gune tasinmaz; "bugun" disindaki
 * kayitlar durumu etkilemez (attendance.routes.ts ile ayni gun kurali).
 */
function durumBul(sonTip: string | null | undefined): 'gelmedi' | 'iceride' | 'molada' | 'cikti' {
  if (!sonTip) return 'gelmedi';
  if (sonTip === 'mola_giris') return 'molada';
  if (sonTip === 'cikis') return 'cikti';
  return 'iceride';
}

/**
 * GET /api/masaustu/durum
 * Uygulama acilisinda ve periyodik olarak cagrilir: personelin bugunku
 * kayitlari, anlik durumu, hareketsizlik esikleri ve Ekibim sekmesinin
 * gorunup gorunmeyecegi.
 */
masaustuRouter.get('/durum', async (req: AuthRequest, res) => {
  const kullaniciId = req.auth!.kullaniciId;
  try {
    const pool = await getPool();
    const kayitlar = await pool
      .request()
      .input('kullaniciId', sql.Int, kullaniciId)
      .query(`
        SELECT g.kayit_id AS id, g.tip, g.zaman, g.otomatik, s.sube_adi AS sube
        FROM dbo.Personel_GirisCikis g
        LEFT JOIN dbo.Subeler s ON s.sube_id = g.sube_id
        WHERE g.kullanici_id = @kullaniciId
          AND CAST(g.zaman AS DATE) = CAST(SYSDATETIME() AS DATE)
        ORDER BY g.zaman, g.kayit_id`);

    const [ayarlar, kapsam] = await Promise.all([pdksAyarlari(), ekipKapsami(kullaniciId)]);
    const son = kayitlar.recordset[kayitlar.recordset.length - 1];

    res.json({
      durum: durumBul(son?.tip),
      kayitlar: kayitlar.recordset,
      ayarlar,
      // Ekibim sekmesi: tum personeli goren ya da en az bir bagli personeli olan.
      ekipGorebilir: kapsam.tumu || kapsam.idler.length > 1,
      sunucuZamani: new Date(),
    });
  } catch (err: any) {
    console.error('masaustu durum hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'masaustu_durum', kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err) }, ip: istekIp(req),
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});

/**
 * POST /api/masaustu/hareket
 * Govde: { tip, otomatik?, zaman?, cihazModeli?, cihazId? }
 *
 * - Elle yapilan islemde zaman yok sayilir, sunucu saati yazilir.
 * - otomatik = true yalniz mola_giris / cikis icin gecerlidir; zaman (son
 *   klavye/fare hareketi) zorunludur ve sunucuda dogrulanir: gelecekte olamaz,
 *   otomatik cikis esiginden (+tolerans) daha eski olamaz, gunun son kaydindan
 *   once olamaz.
 * - Mola acikken cikis istenirse once mola kapatilir, ardindan cikis yazilir
 *   (tek transaction).
 *
 * Gecerli akis: giris -> (mola_giris -> mola_cikis)* -> cikis
 */
masaustuRouter.post('/hareket', async (req: AuthRequest, res) => {
  const { tip, otomatik, zaman, cihazModeli, cihazId } = req.body ?? {};
  const kullaniciId = req.auth!.kullaniciId;
  const ip = istekIp(req);
  const cihaz = kirp(cihazModeli, 200);
  const cihazKimlik = kirp(cihazId, 200);
  const otoMu = otomatik === true;

  const reddet = async (durum: number, hata: string, detay?: Record<string, unknown>) => {
    await logYaz({
      modul: 'PDKS', seviye: 'uyari', islem: 'masaustu_hareket', kullaniciId,
      basarili: false, mesaj: hata, detay: { tip, otomatik: otoMu, zaman, ...detay }, ip, cihaz,
    });
    res.status(durum).json({ hata });
  };

  if (!HAREKET_TIPLERI.includes(tip)) {
    await reddet(400, 'Gecersiz hareket tipi');
    return;
  }
  if (otoMu && !OTOMATIK_TIPLER.includes(tip)) {
    await reddet(400, 'Bu hareket otomatik olarak yazilamaz');
    return;
  }

  try {
    const pool = await getPool();
    const simdi = new Date();

    // 1) Kayit zamani: elle islemde sunucu saati, otomatikte son hareket saati.
    let kayitZamani = simdi;
    if (otoMu) {
      const z = zaman ? new Date(zaman) : null;
      if (!z || isNaN(z.getTime())) {
        await reddet(400, 'Otomatik kayitta gecerli zaman gerekli');
        return;
      }
      const { hareketsizCikisDk } = await pdksAyarlari();
      const enEski = simdi.getTime() - (hareketsizCikisDk + 10) * 60 * 1000;
      if (z.getTime() > simdi.getTime() + SAAT_TOLERANS_MS || z.getTime() < enEski) {
        await reddet(400, 'Otomatik kayit zamani izin verilen aralikta degil', {
          sunucuZamani: simdi.toISOString(),
        });
        return;
      }
      kayitZamani = z > simdi ? simdi : z;
    }

    // 2) Kayit zamaninin gunundeki son kayit. Gece yarisini gecen otomatik
    //    cikis, girisin yapildigi gune yazilir.
    const son = await pool
      .request()
      .input('kullaniciId', sql.Int, kullaniciId)
      .input('gun', sql.DateTime, kayitZamani)
      .query(`
        SELECT TOP 1 tip, zaman, sube_id
        FROM dbo.Personel_GirisCikis
        WHERE kullanici_id = @kullaniciId
          AND CAST(zaman AS DATE) = CAST(@gun AS DATE)
        ORDER BY zaman DESC, kayit_id DESC`);
    const sonKayit = son.recordset[0];
    const sonTip: string | undefined = sonKayit?.tip;
    const acikGiris = sonTip === 'giris' || sonTip === 'mola_cikis';

    // 3) Sira kontrolu
    let sira: string | null = null;
    if (tip === 'giris' && sonTip && sonTip !== 'cikis') sira = 'Zaten giris yapilmis';
    if (tip === 'mola_giris' && !acikGiris) {
      sira = sonTip === 'mola_giris' ? 'Mola zaten devam ediyor' : 'Acik giris kaydiniz yok';
    }
    if (tip === 'mola_cikis' && sonTip !== 'mola_giris') sira = 'Devam eden mola yok';
    if (tip === 'cikis' && !acikGiris && sonTip !== 'mola_giris') sira = 'Acik giris kaydiniz yok';
    if (sira) {
      await reddet(400, sira, { sonTip: sonTip ?? null });
      return;
    }

    // Otomatik kayit gunun son kaydindan once olamaz; esitse ona hizalanir.
    if (otoMu && sonKayit && kayitZamani < new Date(sonKayit.zaman)) {
      kayitZamani = new Date(sonKayit.zaman);
    }

    // 4) Sube: acik kayit varsa onun subesi, yeni giriste personelin subesi.
    let subeId: number | null = sonKayit && tip !== 'giris' ? sonKayit.sube_id : null;
    if (subeId == null) {
      const k = await pool
        .request()
        .input('kullaniciId', sql.Int, kullaniciId)
        .query(`SELECT kullanici_sube_id FROM dbo.kullanicilar WHERE kullanici_id = @kullaniciId`);
      subeId = k.recordset[0]?.kullanici_sube_id ?? null;
    }
    if (subeId == null) {
      await reddet(400, 'Personele sube tanimlanmamis. Yoneticinize basvurun.');
      return;
    }

    // 5) Kayitlar: mola acikken cikis -> mola_cikis + cikis.
    const yazilacak: HareketTip[] =
      tip === 'cikis' && sonTip === 'mola_giris' ? ['mola_cikis', 'cikis'] : [tip];

    const tx = new sql.Transaction(pool);
    await tx.begin();
    const eklenen: any[] = [];
    try {
      for (const t of yazilacak) {
        const ins = await new sql.Request(tx)
          .input('kullaniciId', sql.Int, kullaniciId)
          .input('subeId', sql.Int, subeId)
          .input('tip', sql.NVarChar(20), t)
          .input('zaman', sql.DateTime, kayitZamani)
          .input('otomatik', sql.Bit, otoMu)
          .input('cihazModeli', sql.NVarChar(200), cihaz)
          .input('cihazId', sql.NVarChar(200), cihazKimlik)
          .input('ip', sql.NVarChar(50), ip)
          .query(`INSERT INTO dbo.Personel_GirisCikis
                    (kullanici_id, sube_id, tip, zaman, otomatik, cihaz_modeli, cihaz_id, ip_adresi)
                  OUTPUT INSERTED.kayit_id, INSERTED.tip, INSERTED.zaman, INSERTED.otomatik
                  VALUES (@kullaniciId, @subeId, @tip, @zaman, @otomatik, @cihazModeli, @cihazId, @ip)`);
        eklenen.push(ins.recordset[0]);
      }
      await tx.commit();
    } catch (e) {
      await tx.rollback();
      throw e;
    }

    const kayit = eklenen[eklenen.length - 1];
    await logYaz({
      modul: 'PDKS', seviye: 'bilgi', islem: 'masaustu_hareket', kullaniciId,
      basarili: true,
      mesaj: `${TIP_METIN[tip]}${otoMu ? ' (otomatik)' : ''} - masaustu`,
      detay: { subeId, tipler: yazilacak, otomatik: otoMu, istenenZaman: zaman ?? null },
      referansTablo: 'Personel_GirisCikis', referansId: kayit.kayit_id,
      ip, cihaz,
    });
    res.json({
      mesaj: TIP_METIN[tip] + (otoMu ? ' (otomatik)' : ''),
      tip: kayit.tip,
      zaman: kayit.zaman,
      otomatik: kayit.otomatik,
      durum: durumBul(kayit.tip),
    });
  } catch (err: any) {
    console.error('masaustu hareket hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'masaustu_hareket', kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err), tip, otomatik: otoMu, zaman }, ip, cihaz,
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});

/**
 * GET /api/masaustu/ekip/durum
 * Kapsamdaki personelin bugunku anlik durumu (kendisi haric).
 */
masaustuRouter.get('/ekip/durum', async (req: AuthRequest, res) => {
  const kullaniciId = req.auth!.kullaniciId;
  try {
    const kapsam = await ekipKapsami(kullaniciId);
    const pool = await getPool();
    const istek = pool.request().input('ben', sql.Int, kullaniciId);
    const kosul = kapsamKosulu(istek, kapsam, 'k.kullanici_id');

    const r = await istek.query(`
      SELECT k.kullanici_id AS id,
             LTRIM(RTRIM(CONCAT(k.kullanici_ad, ' ', k.kullanici_soyad))) AS adSoyad,
             d.departman_adi AS departman,
             s.sube_adi AS sube,
             son.tip AS sonTip, son.zaman AS sonZaman, son.otomatik AS sonOtomatik,
             ilk.zaman AS ilkGiris
      FROM dbo.kullanicilar k
      LEFT JOIN dbo.Departmanlar d ON d.departman_id = k.kullanici_calisma_departman_id
      LEFT JOIN dbo.Subeler s ON s.sube_id = k.kullanici_sube_id
      OUTER APPLY (
        SELECT TOP 1 g.tip, g.zaman, g.otomatik
        FROM dbo.Personel_GirisCikis g
        WHERE g.kullanici_id = k.kullanici_id
          AND CAST(g.zaman AS DATE) = CAST(SYSDATETIME() AS DATE)
        ORDER BY g.zaman DESC, g.kayit_id DESC
      ) son
      OUTER APPLY (
        SELECT MIN(g.zaman) AS zaman
        FROM dbo.Personel_GirisCikis g
        WHERE g.kullanici_id = k.kullanici_id
          AND g.tip = 'giris'
          AND CAST(g.zaman AS DATE) = CAST(SYSDATETIME() AS DATE)
      ) ilk
      WHERE k.kullanici_durum = 1
        AND k.kullanici_id <> @ben${kosul}
      ORDER BY adSoyad`);

    const personel = r.recordset.map((p: any) => ({ ...p, durum: durumBul(p.sonTip) }));
    const ozet = { iceride: 0, molada: 0, cikti: 0, gelmedi: 0 };
    for (const p of personel) ozet[p.durum as keyof typeof ozet]++;

    res.json({ ozet, personel });
  } catch (err: any) {
    console.error('ekip durum hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'masaustu_ekip_durum', kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err) }, ip: istekIp(req),
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});

/**
 * GET /api/masaustu/ekip/gecmis?kullaniciId=NN&tarih=YYYY-MM-DD
 * Kapsamdaki bir personelin secilen gundeki tum hareketleri.
 * Kapsam disindaki id icin veri donmez (403).
 */
masaustuRouter.get('/ekip/gecmis', async (req: AuthRequest, res) => {
  const kullaniciId = req.auth!.kullaniciId;
  const hedefId = parseInt(String(req.query.kullaniciId ?? ''), 10);
  const tarih = String(req.query.tarih ?? '');

  if (!Number.isFinite(hedefId) || !/^\d{4}-\d{2}-\d{2}$/.test(tarih)) {
    res.status(400).json({ hata: 'kullaniciId ve tarih (YYYY-MM-DD) gerekli' });
    return;
  }

  try {
    const kapsam = await ekipKapsami(kullaniciId);
    if (!kapsam.tumu && !kapsam.idler.includes(hedefId)) {
      await logYaz({
        modul: 'PDKS', seviye: 'uyari', islem: 'masaustu_ekip_gecmis', kullaniciId,
        basarili: false, mesaj: 'Kapsam disi personel sorgusu',
        detay: { hedefId, tarih }, ip: istekIp(req),
      });
      res.status(403).json({ hata: 'Bu personelin kayitlarini goruntuleme yetkiniz yok' });
      return;
    }

    const pool = await getPool();
    const r = await pool
      .request()
      .input('hedefId', sql.Int, hedefId)
      .input('tarih', sql.Date, tarih)
      .query(`
        SELECT g.kayit_id AS id, g.tip, g.zaman, g.otomatik, s.sube_adi AS sube,
               CASE WHEN g.qr_kod IS NULL THEN 0 ELSE 1 END AS qrIle
        FROM dbo.Personel_GirisCikis g
        LEFT JOIN dbo.Subeler s ON s.sube_id = g.sube_id
        WHERE g.kullanici_id = @hedefId
          AND CAST(g.zaman AS DATE) = @tarih
        ORDER BY g.zaman, g.kayit_id`);

    res.json({ kayitlar: r.recordset });
  } catch (err: any) {
    console.error('ekip gecmis hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'masaustu_ekip_gecmis', kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err), hedefId, tarih }, ip: istekIp(req),
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});

/** Uzak yonetim (destek) dis API'si: adres ve anahtar tanim_pdks_ayarlari'nda, yalniz sunucuda durur. */
async function uzakYonetimAyarlari(): Promise<{ url: string; anahtar: string } | null> {
  const pool = await getPool();
  const r = await pool.request().query(`
    SELECT pdks_ayar_anahtar, pdks_ayar_deger
    FROM dbo.tanim_pdks_ayarlari
    WHERE pdks_ayar_durum = 1
      AND pdks_ayar_anahtar IN ('uzak_yonetim_api_url', 'uzak_yonetim_api_anahtar')`);
  const harita = new Map<string, string>();
  for (const s of r.recordset) harita.set(s.pdks_ayar_anahtar, String(s.pdks_ayar_deger ?? '').trim());
  const url = harita.get('uzak_yonetim_api_url');
  const anahtar = harita.get('uzak_yonetim_api_anahtar');
  return url && anahtar ? { url, anahtar } : null;
}

/** Destek API'si saati DB saatiyle (Europe/Istanbul) 'YYYY-MM-DD HH:mm:ss' doner; ISO'ya cevrilir. */
function istanbulSaati(deger: unknown): string | null {
  if (typeof deger !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(deger)) return null;
  return `${deger.replace(' ', 'T')}+03:00`;
}

/**
 * PUT /api/masaustu/cihaz
 * Govde: { anakartUuid, biosSeri? }
 *
 * Uygulamanin calistigi bilgisayari uzak yonetimdeki cihaz kaydiyla eslestirir:
 * cihaz aciklamasina giris yapan personelin ad soyadi yazilir (deger ayniysa
 * destek tarafinda yazilmaz) ve cihaz ozeti doner. Aciklama istemciden alinmaz.
 * Ajan kurulu degilse 404.
 */
masaustuRouter.put('/cihaz', async (req: AuthRequest, res) => {
  const kullaniciId = req.auth!.kullaniciId;
  const ip = istekIp(req);
  const anakartUuid = kirp(req.body?.anakartUuid, 64);
  const biosSeri = kirp(req.body?.biosSeri, 100);

  if (!anakartUuid) {
    res.status(400).json({ hata: 'anakartUuid gerekli' });
    return;
  }

  try {
    const ayar = await uzakYonetimAyarlari();
    if (!ayar) {
      res.status(503).json({ hata: 'Uzak yonetim baglantisi tanimli degil' });
      return;
    }

    const pool = await getPool();
    const k = await pool
      .request()
      .input('kullaniciId', sql.Int, kullaniciId)
      .query(`SELECT LTRIM(RTRIM(CONCAT(kullanici_ad, ' ', kullanici_soyad))) AS adSoyad
              FROM dbo.kullanicilar WHERE kullanici_id = @kullaniciId`);
    const adSoyad: string | undefined = k.recordset[0]?.adSoyad;
    if (!adSoyad) {
      res.status(404).json({ hata: 'Personel bulunamadi' });
      return;
    }

    const yanit = await fetch(ayar.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-API-KEY': ayar.anahtar },
      body: JSON.stringify({
        action: 'aciklama_guncelle',
        anakartUuid,
        biosSeri,
        aciklama: adSoyad,
        personel: `${adSoyad} (#${kullaniciId})`,
      }),
      signal: AbortSignal.timeout(10_000),
    });
    const govde: any = await yanit.json().catch(() => null);

    if (!yanit.ok || !govde?.basarili) {
      // Ajan kurulu degil / UUID gecersiz: istemcinin bilmesi gereken durumlar aynen iletilir.
      if (yanit.status === 404 || yanit.status === 400) {
        res.status(yanit.status).json({ hata: govde?.mesaj ?? 'Cihaz bulunamadi' });
        return;
      }
      await logYaz({
        modul: 'PDKS', seviye: 'hata', islem: 'masaustu_cihaz', kullaniciId,
        basarili: false, mesaj: `Uzak yonetim API hatasi (${yanit.status})`,
        detay: { durum: yanit.status, hata: govde?.hata ?? null, mesaj: govde?.mesaj ?? null }, ip,
      });
      res.status(502).json({ hata: 'Uzak yonetim servisine ulasilamadi' });
      return;
    }

    const v = govde.veri;
    if (govde.degisti) {
      await logYaz({
        modul: 'PDKS', seviye: 'bilgi', islem: 'masaustu_cihaz', kullaniciId,
        basarili: true, mesaj: `Cihaz aciklamasi guncellendi: ${v.bilgisayarAdi}`,
        detay: { cihazId: v.cihazId, aciklama: v.aciklama }, ip, cihaz: v.bilgisayarAdi,
      });
    }

    res.json({
      bilgisayarAdi: v.bilgisayarAdi,
      aciklama: v.aciklama,
      grup: v.grup,
      anyDeskId: v.anyDeskId,
      rustDeskId: v.rustDeskId,
      sonGorulme: istanbulSaati(v.sonGorulme),
      cevrimici: v.cevrimici === true,
    });
  } catch (err: any) {
    console.error('masaustu cihaz hatasi:', err);
    await logYaz({
      modul: 'PDKS', seviye: 'hata', islem: 'masaustu_cihaz', kullaniciId,
      basarili: false, mesaj: err?.message ?? 'Bilinmeyen hata',
      detay: { hata: hataDetayi(err) }, ip,
    });
    res.status(500).json({ hata: 'Sunucu hatasi', detay: err.message });
  }
});
