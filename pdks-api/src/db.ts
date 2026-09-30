import sql from 'mssql';
import { config } from './config';

/**
 * MSSQL baglanti havuzu (connection pool).
 * Ilk istekte tembel (lazy) olarak baglanir; boylece .env doldurulmadan
 * sunucu yine de ayaga kalkar ve /api/health calisir.
 */
let poolPromise: Promise<sql.ConnectionPool> | null = null;

export function getPool(): Promise<sql.ConnectionPool> {
  if (poolPromise) return poolPromise;

  const { server, database, user, password } = config.db;
  if (!server || !database || !user || !password) {
    return Promise.reject(
      new Error('Veritabani baglanti bilgileri eksik. api/.env dosyasini doldurun.'),
    );
  }

  // Named instance (orn. SQLEXPRESS) varsa instanceName kullanilir ve port
  // GONDERILMEZ (tedious ikisini birden kabul etmez; portu SQL Browser bulur).
  // Instance yoksa port (varsayilan 1433) kullanilir.
  // useUTC: false -> datetime kolonlari sunucu yerel saati (Europe/Istanbul)
  // olarak yorumlanir. Varsayilan true birakilirsa yerel saat UTC sanilip
  // istemciye 3 saat ileri gonderilir.
  const options: Record<string, unknown> = { ...config.db.options, useUTC: false };
  const poolConfig: sql.config = {
    server: config.db.server,
    database: config.db.database,
    user: config.db.user,
    password: config.db.password,
    options,
    pool: { max: 10, min: 0, idleTimeoutMillis: 30000 },
    connectionTimeout: 20000,
    requestTimeout: 20000,
  };
  if (config.db.instance) {
    options.instanceName = config.db.instance;
  } else {
    poolConfig.port = config.db.port ?? 1433;
  }

  const p = new sql.ConnectionPool(poolConfig)
    .connect()
    .then((pool: sql.ConnectionPool) => {
      console.log('MSSQL baglantisi kuruldu.');
      return pool;
    })
    .catch((err: unknown) => {
      poolPromise = null; // bir sonraki istekte tekrar denesin
      throw err;
    });

  poolPromise = p;
  return p;
}

export { sql };
