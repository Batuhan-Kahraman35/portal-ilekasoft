import express from 'express';
import cors from 'cors';
import { config } from './config';
import { authRouter } from './routes/auth.routes';
import { attendanceRouter } from './routes/attendance.routes';
import { ayarlarRouter } from './routes/ayarlar.routes';
import { masaustuRouter } from './routes/masaustu.routes';

const app = express();
app.use(cors());
app.use(express.json());

// Saglik kontrolu (veritabani gerektirmez) - sunucu ayakta mi diye bakmak icin
app.get('/api/health', (_req, res) => {
  res.json({ durum: 'ok', zaman: new Date().toISOString() });
});

app.use('/api', ayarlarRouter);
app.use('/api/auth', authRouter);
app.use('/api/attendance', attendanceRouter);
app.use('/api/masaustu', masaustuRouter);

app.listen(config.port, () => {
  console.log(`API calisiyor: http://localhost:${config.port}`);
  console.log(`Saglik kontrolu: http://localhost:${config.port}/api/health`);
});
