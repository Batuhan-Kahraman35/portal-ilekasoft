import { getPool, sql } from './db';

/**
 * Merkezi kullanici log yazici.
 *
 * Tasarim kurallari:
 * - Log yazmak ANA ISLEMI ASLA BOZMAZ. Her hata yutulur, yalniz konsola dusulur.
 * - Modul/seviye tanim tablolarindan kod ile cozulur, id'ler kodda sabitlenmez.
 * - Sifre / hash / token gibi hassas alanlar detay JSON'undan temizlenir.
 */

export type LogSeviye = 'bilgi' | 'uyari' | 'hata' | 'kritik';

export interface LogGirdi {
  modul: string;                 // Tanim_LogModulleri.modul_kod (orn. 'PDKS')
  seviye: LogSeviye;
  islem: string;                 // 'qr_okut', 'giris', 'cikis'
  kullaniciId?: number | null;
  basarili?: boolean;
  mesaj?: string | null;
  detay?: unknown;               // JSON'a cevrilir
  referansTablo?: string | null;
  referansId?: number | null;
  ip?: string | null;
  cihaz?: string | null;
  kaynak?: string;               // varsayilan 'pdks-api'
}

/** Detay JSON'unda asla saklanmamasi gereken alan adlari. */
const HASSAS_ALANLAR = [
  'sifre', 'password', 'pass', 'hash', 'sifre_hash', 'kullanici_sifre_hash',
  'token', 'jwt', 'authorization', 'secret', 'apikey', 'api_key',
];

/** Tanim tablosu id onbellegi: 'modul:PDKS' -> 3 */
const idOnbellek = new Map<string, number>();

/** Metni kolon sinirina kirpar (null/undefined guvenli). */
function kirp(deger: unknown, uzunluk: number): string | null {
  if (deger == null) return null;
  const metin = String(deger);
  return metin.length > uzunluk ? metin.slice(0, uzunluk) : metin;
}

/** Nesne icindeki hassas alanlari ozyinelemeli olarak maskeler. */
function maskele(veri: unknown, derinlik = 0): unknown {
  if (derinlik > 5 || veri == null) return veri;
  if (Array.isArray(veri)) return veri.map((o) => maskele(o, derinlik + 1));
  if (typeof veri !== 'object') return veri;

  const sonuc: Record<string, unknown> = {};
  for (const [anahtar, deger] of Object.entries(veri as Record<string, unknown>)) {
    sonuc[anahtar] = HASSAS_ALANLAR.includes(anahtar.toLowerCase())
      ? '***'
      : maskele(deger, derinlik + 1);
  }
  return sonuc;
}

/** Tanim tablosundan kod karsiligi id'yi getirir (onbellekli). */
async function tanimId(tablo: 'modul' | 'seviye', kod: string): Promise<number | null> {
  const anahtar = `${tablo}:${kod}`;
  const onbellekli = idOnbellek.get(anahtar);
  if (onbellekli) return onbellekli;

  const tabloAdi = tablo === 'modul' ? 'Tanim_LogModulleri' : 'Tanim_LogSeviyeleri';
  const pool = await getPool();
  const sonuc = await pool
    .request()
    .input('kod', sql.NVarChar, kod)
    .query(`SELECT TOP 1 ${tablo}_id AS id FROM dbo.${tabloAdi} WHERE ${tablo}_kod = @kod`);

  const id: number | undefined = sonuc.recordset[0]?.id;
  if (id) idOnbellek.set(anahtar, id);
  return id ?? null;
}

/**
 * KullaniciLoglari tablosuna bir satir yazar.
 * Basarisiz olursa sessizce gecer; cagiran tarafin akisini kesmez.
 */
export async function logYaz(girdi: LogGirdi): Promise<void> {
  try {
    const [modulId, seviyeId] = await Promise.all([
      tanimId('modul', girdi.modul),
      tanimId('seviye', girdi.seviye),
    ]);

    if (!modulId || !seviyeId) {
      console.error('logYaz: tanim bulunamadi', girdi.modul, girdi.seviye);
      return;
    }

    const detayJson =
      girdi.detay == null ? null : JSON.stringify(maskele(girdi.detay));

    const pool = await getPool();
    await pool
      .request()
      .input('kullaniciId', sql.Int, girdi.kullaniciId ?? null)
      .input('modulId', sql.Int, modulId)
      .input('seviyeId', sql.Int, seviyeId)
      .input('islem', sql.NVarChar(100), kirp(girdi.islem, 100))
      .input('basarili', sql.Bit, girdi.basarili ?? true)
      .input('mesaj', sql.NVarChar(500), kirp(girdi.mesaj, 500))
      .input('detay', sql.NVarChar(sql.MAX), detayJson)
      .input('referansTablo', sql.NVarChar(100), kirp(girdi.referansTablo, 100))
      .input('referansId', sql.BigInt, girdi.referansId ?? null)
      .input('ip', sql.NVarChar(64), kirp(girdi.ip, 64))
      .input('cihaz', sql.NVarChar(400), kirp(girdi.cihaz, 400))
      .input('kaynak', sql.NVarChar(50), kirp(girdi.kaynak ?? 'pdks-api', 50))
      .query(`INSERT INTO dbo.KullaniciLoglari
                (log_kullanici_id, log_modul_id, log_seviye_id, log_islem, log_basarili,
                 log_mesaj, log_detay, log_referans_tablo, log_referans_id,
                 log_ip, log_cihaz, log_kaynak)
              VALUES
                (@kullaniciId, @modulId, @seviyeId, @islem, @basarili,
                 @mesaj, @detay, @referansTablo, @referansId,
                 @ip, @cihaz, @kaynak)`);
  } catch (err: any) {
    // Log yazilamamasi ana islemi etkilemez; yalniz konsola dusulur.
    console.error('logYaz basarisiz:', err?.message ?? err);
  }
}

/**
 * Hata nesnesini loglanabilir sade bir nesneye cevirir.
 * MSSQL hatalarindaki number/code/lineNumber alanlari teshis icin kritiktir.
 */
export function hataDetayi(err: any): Record<string, unknown> {
  return {
    mesaj: err?.message ?? String(err),
    ad: err?.name ?? null,
    kod: err?.code ?? null,
    numara: err?.number ?? null,        // MSSQL hata numarasi (orn. 8152 truncation)
    satir: err?.lineNumber ?? null,
    yordam: err?.procName ?? null,
    stack: typeof err?.stack === 'string' ? err.stack.split('\n').slice(0, 6).join('\n') : null,
  };
}
