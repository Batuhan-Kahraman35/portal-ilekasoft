/**
 * API adres yapilandirmasi.
 * - Gelistirmede (npm run dev): yerel Node API
 * - Uretimde (build): portal.ornekfirma.com/pdks-api
 */
// Not: Canlida reverse-proxy '/pdks-api' -> localhost:3000/api eslemesi yapar.
// Bu yuzden prod tabani '/pdks-api' (sonuna '/api' EKLENMEZ, proxy zaten ekliyor).
export const API_URL = import.meta.env.PROD
  ? 'https://portal.ornekfirma.com/pdks-api'
  : '/api'; // dev: Vite proxy'si localhost:3000'e yonlendirir (tunel icin tek origin)
