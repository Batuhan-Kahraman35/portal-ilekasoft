import { useEffect, useState } from 'react';
import { ayarlariGetir, OTURUM_BITTI } from './api';
import { personelGetir, tokenGetir, type Personel } from './auth';
import Giris from './ekranlar/Giris';
import Anasayfa from './ekranlar/Anasayfa';
import KurulumKapisi from './components/KurulumKapisi';

export default function App() {
  const [personel, setPersonel] = useState<Personel | null>(
    tokenGetir() ? personelGetir() : null,
  );

  // Token gecersizlestiginde otomatik giris ekranina don
  useEffect(() => {
    const dinleyici = () => setPersonel(null);
    window.addEventListener(OTURUM_BITTI, dinleyici);
    return () => window.removeEventListener(OTURUM_BITTI, dinleyici);
  }, []);

  // Marka bilgisini DB'den cek: sekme basligi + favicon dinamik
  useEffect(() => {
    ayarlariGetir()
      .then((a) => {
        if (a.baslik) document.title = a.baslik;
        if (a.faviconUrl) {
          let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
          if (!link) {
            link = document.createElement('link');
            link.rel = 'icon';
            document.head.appendChild(link);
          }
          link.href = a.faviconUrl;
        }
      })
      .catch(() => {
        /* ayar cekilemezse varsayilan baslik kalir */
      });
  }, []);

  return (
    <KurulumKapisi>
      {!personel ? (
        <Giris onGiris={setPersonel} />
      ) : (
        <Anasayfa onCikis={() => setPersonel(null)} />
      )}
    </KurulumKapisi>
  );
}
