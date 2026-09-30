import { useState } from 'react';
import { giris } from '../api';
import { tokenKaydet, type Personel } from '../auth';

interface Props {
  onGiris: (personel: Personel) => void;
}

export default function Giris({ onGiris }: Props) {
  const [email, setEmail] = useState('');
  const [sifre, setSifre] = useState('');
  const [hata, setHata] = useState('');
  const [yukleniyor, setYukleniyor] = useState(false);

  async function gonder(e: React.FormEvent) {
    e.preventDefault();
    setHata('');
    setYukleniyor(true);
    try {
      const sonuc = await giris(email.trim(), sifre);
      tokenKaydet(sonuc.token, sonuc.personel);
      onGiris(sonuc.personel);
    } catch (err) {
      setHata(err instanceof Error ? err.message : 'Giris basarisiz');
    } finally {
      setYukleniyor(false);
    }
  }

  return (
    <div className="giris-sayfa">
      <form className="kart giris-kart" onSubmit={gonder}>
        <h1 className="giris-baslik">Giriş</h1>

        {hata && <div className="uyari uyari-hata">{hata}</div>}

        <label className="alan">
          <span>E-posta veya TC Kimlik No</span>
          {/* type="text": TC girisi tarayicinin e-posta dogrulamasina takilmasin */}
          <input
            type="text"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            required
          />
        </label>

        <label className="alan">
          <span>Şifre</span>
          <input
            type="password"
            value={sifre}
            onChange={(e) => setSifre(e.target.value)}
            autoComplete="current-password"
            required
          />
        </label>

        <button className="btn btn-birincil" type="submit" disabled={yukleniyor}>
          {yukleniyor ? 'Giriş yapılıyor…' : 'Giriş Yap'}
        </button>
      </form>
    </div>
  );
}
