import { getPool, sql } from '../db';

/**
 * Test verisi ekler. Artik KULLANICI olusturmaz (kullanicilar tablosu zaten dolu).
 * Sadece ilk subeye baglı bir test QR kodu (PDKS-TEST-001) ekler.
 *
 * Calistirmak icin: npm run seed
 * Giris icin mevcut bir kullanicinin email + sifresini kullanin.
 */
async function main() {
  const pool = await getPool();

  const sube = await pool
    .request()
    .query('SELECT TOP 1 sube_id, sube_adi FROM dbo.Subeler ORDER BY sube_id');

  if (sube.recordset.length === 0) {
    console.log('Hic sube bulunamadi (dbo.Subeler bos). QR olusturulamadi.');
    process.exit(0);
  }

  const { sube_id, sube_adi } = sube.recordset[0];
  const qr = 'PDKS-TEST-001';

  await pool
    .request()
    .input('subeId', sql.Int, sube_id)
    .input('qr', sql.NVarChar, qr)
    .query(`UPDATE dbo.Subeler SET sube_qr = @qr
            WHERE sube_id = @subeId AND (sube_qr IS NULL OR sube_qr = '')`);

  console.log('Seed tamamlandi.');
  console.log(`  Test QR kod : ${qr}  ->  sube: ${sube_adi} (id ${sube_id})`);
  console.log('  Giris icin  : mevcut bir kullanicinin email + sifresi');
  process.exit(0);
}

main().catch((e) => {
  console.error('Seed hatasi:', e);
  process.exit(1);
});
