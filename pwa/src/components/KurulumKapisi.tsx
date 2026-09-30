import { useEffect, useState, type ReactNode } from 'react';

/** Chrome'un beforeinstallprompt olayi (tip tanimi TS'te standart degil). */
interface KurulumOlayi extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const ATLA_ANAHTAR = 'pdks_kurulum_atla';

function standaloneMi(): boolean {
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    // iOS Safari'de ana ekrandan acilinca
    (navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

function iosMu(): boolean {
  const ua = navigator.userAgent;
  return /iphone|ipad|ipod/i.test(ua) && !(window as unknown as { MSStream?: unknown }).MSStream;
}

interface Props {
  children: ReactNode;
}

/**
 * Kurulum kapisi: uygulama kurulu degilse tam ekran "Yukle" ekrani gosterir.
 * - Android/Chrome: tek tik yukleme (beforeinstallprompt)
 * - iOS/Safari: elle "Ana Ekrana Ekle" talimati
 * - Alt kismda "Tarayicida devam et" cikisi (iOS zorunlulugu)
 * Kuruluysa veya kullanici atladiysa: children (uygulama) render edilir.
 */
export default function KurulumKapisi({ children }: Props) {
  const [kurulu, setKurulu] = useState(standaloneMi());
  const [atla, setAtla] = useState(() => localStorage.getItem(ATLA_ANAHTAR) === '1');
  const [olay, setOlay] = useState<KurulumOlayi | null>(null);

  useEffect(() => {
    function yakala(e: Event) {
      e.preventDefault(); // otomatik mini-banner'i engelle, kendi butonumuzu goster
      setOlay(e as KurulumOlayi);
    }
    function kuruldu() {
      setKurulu(true);
    }
    window.addEventListener('beforeinstallprompt', yakala);
    window.addEventListener('appinstalled', kuruldu);
    return () => {
      window.removeEventListener('beforeinstallprompt', yakala);
      window.removeEventListener('appinstalled', kuruldu);
    };
  }, []);

  // Kuruluysa veya atlandiysa: uygulamayi goster
  if (kurulu || atla) {
    return <>{children}</>;
  }

  async function yukle() {
    if (!olay) return;
    await olay.prompt();
    const secim = await olay.userChoice;
    if (secim.outcome === 'accepted') setKurulu(true);
    setOlay(null);
  }

  function tarayicidaDevam() {
    localStorage.setItem(ATLA_ANAHTAR, '1');
    setAtla(true);
  }

  return (
    <div className="kurulum-kapi">
      <div className="kurulum-kart">
        <div className="kurulum-ikon">📲</div>
        <h1>Uygulamayı Yükle</h1>
        <p className="kurulum-aciklama">
          Daha hızlı ve tam ekran deneyim için uygulamayı cihazınıza ekleyin.
        </p>

        {olay ? (
          // Android / destekleyen tarayicilar: tek tik
          <button className="btn btn-birincil" onClick={yukle}>
            Uygulamayı Yükle
          </button>
        ) : iosMu() ? (
          // iOS: elle talimat
          <ol className="kurulum-adimlar">
            <li>
              Alttaki <strong>Paylaş</strong> düğmesine dokunun{' '}
              <span className="ios-ikon">⎋</span>
            </li>
            <li>
              <strong>Ana Ekrana Ekle</strong> seçeneğine dokunun
            </li>
            <li>
              Sağ üstte <strong>Ekle</strong>’ye dokunun
            </li>
          </ol>
        ) : (
          // Masaustu / diger: tarayici menusunden yukleme
          <p className="kurulum-aciklama">
            Tarayıcınızın adres çubuğundaki <strong>yükle</strong> simgesini
            kullanın ya da menüden “Uygulamayı yükle”yi seçin.
          </p>
        )}

        <button className="btn-metin kurulum-atla" onClick={tarayicidaDevam}>
          Tarayıcıda devam et
        </button>
      </div>
    </div>
  );
}
