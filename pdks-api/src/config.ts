import 'dotenv/config';
import { readFileSync } from 'fs';
import { resolve } from 'path';

// DB bilgileri merkezi config/database.json'dan okunur
const dbJson = JSON.parse(
  readFileSync(resolve(__dirname, '../../config/database.json'), 'utf-8'),
);

export const config = {
  port: Number(process.env.PORT ?? 3000),
  jwtSecret: process.env.JWT_SECRET ?? 'gelistirme-gizli-anahtari-mutlaka-degistir',
  jwtExpiresIn: process.env.JWT_EXPIRES_IN ?? '12h',
  db: {
    server: dbJson.host,
    instance: dbJson.instance ?? '',
    database: dbJson.database,
    user: dbJson.username,
    password: dbJson.password,
    port: dbJson.port as number,
    options: {
      encrypt: dbJson.encrypt as boolean,
      trustServerCertificate: dbJson.trustServerCertificate as boolean,
    },
  },
};
