import { useCallback, useEffect, useState } from 'react';
import { scan, qrsizCikis, sonKayitlar, type Kayit, type HareketTipi } from '../api';
import { personelGetir, cikisYap } from '../auth';
import QrTarayici from '../components/QrTarayici';

interface Props {
  onCikis: () => void;
}

/** Kayit tipine karsilik gelen etiket metni ve rozet sinifi. */
const TIP_ETIKET: Record<HareketTipi, { ad: string; sinif: string }> = {
  giris: { ad: 'Giriş', sinif: 'etiket-giris' },
  cikis: { ad: 'Çıkış', sinif: 'etiket-cikis' },
  mola_giris: { ad: 'Mola', sinif: 'etiket-mola-giris' },
  mola_cikis: { ad: 'Mola Bitiş', sinif: 'etiket-mola-cikis' },
};

/** Cihazin konumunu yuksek dogrulukla ister (Promise sarmalayici). */
function konumAl(): Promise<GeolocationPosition> {
  return new Promise((coz, red) => {
    if (!navigator.geolocation) {
      red(new Error('Cihaz konum desteklemiyor'));
      return;
    }
    navigator.geolocation.getCurrentPosition(coz, red, {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0,
    });
  });
}

export default function Anasayfa({ onCikis }: Props) {
  const personel = personelGetir();
  const [tarayiciAcik, setTarayiciAcik] = useState(false);
  const [mesaj, setMesaj] = useState<{ tur: 'ok' | 'hata'; metin: string } | null>(null);
  const [islemde, setIslemde] = useState(false);
  const [kayitlar, setKayitlar] = useState<Kayit[]>([]);

  const kayitlariYukle = useCallback(async () => {
    try {
      const sonuc = await sonKayitlar();
      setKayitlar(sonuc.kayitlar);
    } catch {
      /* sessiz gec */
    }
  }, []);

  useEffect(() => {
    kayitlariYukle();
  }, [kayitlariYukle]);

  async function qrOkundu(metin: string) {
    setTarayiciAcik(false);
    setIslemde(true);
    setMesaj(null);
    try {
      // 1) Once konum al (geofence icin sunucuya gonderilecek)
      let enlem: number | undefined;
      let boylam: number | undefined;
      try {
        const konum = await konumAl();
        enlem = konum.coords.latitude;
        boylam = konum.coords.longitude;
      } catch {
        setMesaj({ tur: 'hata', metin: 'Konum alınamadı. Konum iznini açın.' });
        setIslemde(false);
        return;
      }

      // 2) Sunucuya gonder (mesafe/geofence kontrolu sunucuda)
      const sonuc = await scan({
        qrKod: metin,
        enlem,
        boylam,
        cihazModeli: navigator.userAgent,
      });
      setMesaj({
        tur: 'ok',
        metin: `${sonuc.mesaj} — ${sonuc.lokasyon}`,
      });
      kayitlariYukle();
    } catch (err) {
      setMesaj({ tur: 'hata', metin: err instanceof Error ? err.message : 'İşlem başarısız' });
    } finally {
      setIslemde(false);
    }
  }

  /** QR okutmadan cikis: konum izni yine alinir, sunucu geofence'i dogrular. */
  async function qrsizCikisYap() {
    setIslemde(true);
    setMesaj(null);
    try {
      let enlem: number | undefined;
      let boylam: number | undefined;
      try {
        const konum = await konumAl();
        enlem = konum.coords.latitude;
        boylam = konum.coords.longitude;
      } catch {
        setMesaj({ tur: 'hata', metin: 'Konum alınamadı. Konum iznini açın.' });
        setIslemde(false);
        return;
      }

      const sonuc = await qrsizCikis({
        enlem,
        boylam,
        cihazModeli: navigator.userAgent,
      });
      setMesaj({ tur: 'ok', metin: `${sonuc.mesaj} — ${sonuc.lokasyon}` });
      kayitlariYukle();
    } catch (err) {
      setMesaj({ tur: 'hata', metin: err instanceof Error ? err.message : 'İşlem başarısız' });
    } finally {
      setIslemde(false);
    }
  }

  function cikis() {
    cikisYap();
    onCikis();
  }

  /**
   * Son kayit bugunse ve mesai acik ise QR'siz cikis butonu gosterilir.
   * Mola devam ederken (mola_giris) cikis yapilamaz; once mola bitirilmelidir.
   */
  const sonKayit = kayitlar[0];
  const bugunMu = (zaman: string) =>
    new Date(zaman).toDateString() === new Date().toDateString();
  const acikGiris =
    !!sonKayit &&
    (sonKayit.Tip === 'giris' || sonKayit.Tip === 'mola_cikis') &&
    bugunMu(sonKayit.Zaman);

  if (tarayiciAcik) {
    return <QrTarayici onOkundu={qrOkundu} onKapat={() => setTarayiciAcik(false)} />;
  }

  return (
    <div className="anasayfa">
      <header className="ust-bar">
        <span className="ust-ad">{personel?.adSoyad ?? 'Personel'}</span>
        <button className="btn-metin" onClick={cikis}>
          Çıkış
        </button>
      </header>

      {mesaj && (
        <div className={`uyari ${mesaj.tur === 'ok' ? 'uyari-ok' : 'uyari-hata'}`}>
          {mesaj.metin}
        </div>
      )}

      <button
        className="btn btn-birincil btn-buyuk"
        disabled={islemde}
        onClick={() => setTarayiciAcik(true)}
      >
        {islemde ? 'İşleniyor…' : '📷 QR Okut'}
      </button>

      <section className="kayit-liste">
        <h2>Son Kayıtlar</h2>
        {kayitlar.length === 0 && <p className="bos">Henüz kayıt yok.</p>}
        {kayitlar.map((k) => (
          <div className="kayit-satir" key={k.Id}>
            <span className={`etiket ${TIP_ETIKET[k.Tip]?.sinif ?? 'etiket-cikis'}`}>
              {TIP_ETIKET[k.Tip]?.ad ?? k.Tip}
            </span>
            <span className="kayit-bilgi">
              <span className="kayit-zaman">
                {new Date(k.Zaman).toLocaleString('tr-TR')}
              </span>
              {k.Lokasyon && <span className="kayit-lokasyon">{k.Lokasyon}</span>}
            </span>
            {acikGiris && k.Id === sonKayit.Id && (
              <button
                className="btn-cikis-yap"
                disabled={islemde}
                onClick={qrsizCikisYap}
              >
                {islemde ? '…' : 'Çıkış Yap'}
              </button>
            )}
          </div>
        ))}
      </section>
    </div>
  );
}
