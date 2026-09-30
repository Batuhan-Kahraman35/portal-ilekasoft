import jwt from 'jsonwebtoken';
import { Request, Response, NextFunction } from 'express';
import { config } from './config';

export interface AuthPayload {
  kullaniciId: number;
  email: string;
}

/** Giris basarili oldugunda personele verilecek token'i uretir. */
export function tokenOlustur(payload: AuthPayload): string {
  return jwt.sign(payload, config.jwtSecret, {
    expiresIn: config.jwtExpiresIn,
  } as jwt.SignOptions);
}

/** Token'i tasiyan istek tipine auth alanini ekler. */
export interface AuthRequest extends Request {
  auth?: AuthPayload;
}

/**
 * Korumali endpoint'ler icin middleware.
 * Authorization: Bearer <token> bekler; gecerliyse req.auth doldurur.
 */
export function girisGerekli(req: AuthRequest, res: Response, next: NextFunction): void {
  const header = req.headers.authorization;
  if (!header || !header.startsWith('Bearer ')) {
    res.status(401).json({ hata: 'Yetkilendirme tokeni gerekli' });
    return;
  }
  const token = header.slice(7);
  try {
    req.auth = jwt.verify(token, config.jwtSecret) as AuthPayload;
    next();
  } catch {
    res.status(401).json({ hata: 'Gecersiz veya suresi dolmus token' });
  }
}
