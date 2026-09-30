import { getPool, sql } from '../db';

/**
 * Mevcut veritabanini inceler: kolon tipleri, durum degerleri ve sifre hash
 * onekleri. Boylece schema/kod varsayimlarimizi gercek veriyle dogrulari.
 *
 * Calistirmak icin: npm run incele
 * (api/.env icindeki MSSQL bilgileri dolu olmali.)
 */
async function main() {
  const pool = await getPool();

  console.log('\n===== 1) KOLON TIPLERI =====');
  const tipler = await pool.request().query(`
    SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE (TABLE_NAME = 'kullanicilar'
           AND COLUMN_NAME IN ('kullanici_id','kullanici_durum','kullanici_sube_id',
                               'kullanici_sifre_hash','kullanici_email'))
       OR (TABLE_NAME = 'Subeler'
           AND COLUMN_NAME IN ('sube_id','sube_durum','sube_adi'))
    ORDER BY TABLE_NAME, COLUMN_NAME`);
  console.table(tipler.recordset);

  console.log('\n===== 2) kullanici_durum DEGER DAGILIMI =====');
  const durum = await pool.request().query(`
    SELECT kullanici_durum, COUNT(*) AS adet
    FROM dbo.kullanicilar
    GROUP BY kullanici_durum
    ORDER BY adet DESC`);
  console.table(durum.recordset);

  console.log('\n===== 3) SIFRE HASH ONEKLERI (ilk 4 karakter) =====');
  const hash = await pool.request().query(`
    SELECT LEFT(kullanici_sifre_hash, 4) AS onek, COUNT(*) AS adet
    FROM dbo.kullanicilar
    WHERE kullanici_sifre_hash IS NOT NULL AND kullanici_sifre_hash <> ''
    GROUP BY LEFT(kullanici_sifre_hash, 4)
    ORDER BY adet DESC`);
  console.table(hash.recordset);

  console.log('\n===== 4) sube_durum DEGER DAGILIMI =====');
  try {
    const subeDurum = await pool.request().query(`
      SELECT sube_durum, COUNT(*) AS adet
      FROM dbo.Subeler
      GROUP BY sube_durum
      ORDER BY adet DESC`);
    console.table(subeDurum.recordset);
  } catch {
    console.log('(sube_durum okunamadi)');
  }

  console.log('\n===== 5) KAYIT SAYILARI =====');
  const sayilar = await pool.request().query(`
    SELECT
      (SELECT COUNT(*) FROM dbo.kullanicilar) AS kullanici_sayisi,
      (SELECT COUNT(*) FROM dbo.Subeler)      AS sube_sayisi`);
  console.table(sayilar.recordset);

  process.exit(0);
}

main().catch((e) => {
  console.error('Inceleme hatasi:', e.message);
  process.exit(1);
});
