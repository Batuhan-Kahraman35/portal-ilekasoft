import { useEffect, useRef } from 'react';
import jsQR from 'jsqr';

interface Props {
  onOkundu: (metin: string) => void;
  onKapat: () => void;
}

/**
 * Kamera ile QR tarar. iOS Safari BarcodeDetector'i desteklemedigi icin
 * saf JS (jsQR) kullanilir. Arka kamera (environment) tercih edilir.
 */
export default function QrTarayici({ onOkundu, onKapat }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const okunduRef = useRef(false);

  useEffect(() => {
    let animId = 0;

    async function basla() {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' },
          audio: false,
        });
        streamRef.current = stream;
        const video = videoRef.current!;
        video.srcObject = stream;
        await video.play();
        animId = requestAnimationFrame(tara);
      } catch {
        alert('Kameraya erisilemedi. Izin verildiginden ve HTTPS oldugundan emin olun.');
        onKapat();
      }
    }

    function tara() {
      const video = videoRef.current;
      const canvas = canvasRef.current;
      if (!video || !canvas || video.readyState !== video.HAVE_ENOUGH_DATA) {
        animId = requestAnimationFrame(tara);
        return;
      }
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const kod = jsQR(img.data, img.width, img.height, {
        inversionAttempts: 'dontInvert',
      });
      if (kod && !okunduRef.current) {
        okunduRef.current = true;
        onOkundu(kod.data);
        return;
      }
      animId = requestAnimationFrame(tara);
    }

    basla();
    return () => {
      cancelAnimationFrame(animId);
      streamRef.current?.getTracks().forEach((t) => t.stop());
    };
  }, [onOkundu, onKapat]);

  return (
    <div className="tarayici">
      <video ref={videoRef} playsInline muted className="tarayici-video" />
      <canvas ref={canvasRef} style={{ display: 'none' }} />
      <div className="tarayici-cerceve" />
      <button className="btn btn-ikincil tarayici-kapat" onClick={onKapat}>
        İptal
      </button>
    </div>
  );
}
