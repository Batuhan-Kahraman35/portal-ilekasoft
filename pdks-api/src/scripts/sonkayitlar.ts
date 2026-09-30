import { getPool } from '../db';

/** Son giris/cikis kayitlarini gosterir (kullanici adi + sube ile birlikte). */
async function main() {
  const pool = await getPool();
  const r = await pool.request().query(`
    SELECT TOP 10
      k.kayit_id,
      u.kullanici_email,
      (u.kullanici_ad + ' ' + u.kullanici_soyad) AS ad_soyad,
      k.tip, k.zaman, s.sube_adi, k.enlem, k.boylam, k.cihaz_modeli
    FROM dbo.Personel_GirisCikis k
    JOIN dbo.kullanicilar u ON u.kullanici_id = k.kullanici_id
    LEFT JOIN dbo.Subeler s ON s.sube_id = k.sube_id
    ORDER BY k.zaman DESC`);
  console.table(r.recordset);
  process.exit(0);
}

main().catch((e) => {
  console.error('Hata:', e.message);
  process.exit(1);
});
