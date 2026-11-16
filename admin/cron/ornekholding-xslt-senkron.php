<?php
/**
 * Örnek Holding XSLT Eşitleme — Portal Örnek Soft
 *
 * Not: Kanal ayarında XsltAdi (ör. "DEFAULT") varsa şablon gönderimde adıyla istenir
 * (XSLT_NAME) ve yerel kopya kullanılmaz; bu kanallar atlanır. Bu betik yalnız
 * XSLT gömen (XsltKlasoru) kanallar içindir.
 *
 * Örnek Holding servisinde XSLT indirme işlemi yoktur; portalda tanımlı güncel şablon,
 * Örnek Holding portalından kesilen her faturanın UBL'sine gömülü gelir. Bu betik her
 * kanalın son giden faturalarından şablonu alır ve yerel kopyayı günceller:
 *   admin/assets/xslt/<XsltKlasoru>/0_fatura.xslt      (e-Fatura)
 *   admin/assets/xslt/<XsltKlasoru>/0_arsivfatura.xslt (e-Arşiv)
 *
 * Aday fatura kuralları (yanlış/eski şablona dönmemek için):
 *   - Bizim API ile kestiğimiz faturalar (Faturalar_KesimDurumu dolu) atlanır
 *   - İptal faturalar atlanır
 *   - Yerel dosyadan SONRA kesilmiş olmalı (e-Fatura: CDATE > dosya zamanı,
 *     e-Arşiv: fatura günü > dosya günü). --tumu bu sınırı kaldırır.
 *   - Şablonu yerel dosyayla aynı olanlar ve daha önce değiştirilmiş (_yedek/)
 *     şablonu taşıyanlar aday sayılmaz
 *   - Adaylar arasında birden fazla farklı şablon varsa (seriye özel şablon olabilir)
 *     güncelleme yapılmaz, uyarı verilir
 * Eski dosya admin/assets/xslt/<klasor>/_yedek/ altına zaman damgasıyla alınır.
 *
 * Kullanım (varsayılan yalnız rapor, dosyaya dokunmaz):
 *   php admin/cron/ornekholding-xslt-senkron.php [--uygula] [--kanal=25] [--gun=30]
 *                                             [--tur=EFATURA|EARSIV] [--tumu] [--aday=5]
 * Zamanlama: ayrı cron yok; ornekholding_senkron görevi (Cron Yönetimi) sonunda
 * tasks.php → cronXsltEsitle() ile --uygula çalıştırılır.
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/OrnekHoldingApi.php';

class OrnekHoldingXsltSenkron
{
    const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const NS_XSL = 'http://www.w3.org/1999/XSL/Transform';

    private $db;
    private $kok;
    private $uygula;
    private $gun;
    private $tumu;
    private $adayLimit;
    private $turFiltre;
    private $ozet = ['guncellenen' => 0, 'guncel' => 0, 'uyari' => 0, 'hata' => 0];

    public function __construct(array $sec)
    {
        $this->db        = Database::getInstance();
        $this->kok       = realpath(__DIR__ . '/../assets/xslt') ?: (__DIR__ . '/../assets/xslt');
        $this->uygula    = isset($sec['uygula']);
        $this->gun       = max(1, min(365, (int)($sec['gun'] ?? 30)));
        $this->tumu      = isset($sec['tumu']);
        $this->adayLimit = max(1, min(20, (int)($sec['aday'] ?? 5)));
        $this->turFiltre = strtoupper((string)($sec['tur'] ?? ''));
    }

    public function calistir(?int $kanalId): int
    {
        $this->yaz('Örnek Holding XSLT eşitleme — ' . date('Y-m-d H:i:s')
            . ($this->uygula ? ' [UYGULA]' : ' [RAPOR: dosyaya dokunulmaz]'));

        $kanallar = OrnekHoldingApi::aktifKanallar($this->db);
        foreach ($kanallar as $k) {
            if ($kanalId && (int)$k['EntegrasyonKanallari_id'] !== $kanalId) continue;

            $api = new OrnekHoldingApi($k);
            $klasor = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$api->ayar('XsltKlasoru', ''));
            $this->yaz('');
            $this->yaz('=== Kanal ' . $api->getKanalId() . ' ' . $api->getKanalAdi()
                . ' (XsltKlasoru: ' . ($klasor ?: '-') . ')');

            if (!$api->hazirMi()) {
                $this->yaz('  HATA: ' . $api->getHata());
                $this->ozet['hata']++;
                continue;
            }

            // XsltAdi: şablon Örnek Holding'den adıyla isteniyor, yerel kopya kullanılmıyor
            $sablonAdi = trim((string)$api->ayar('XsltAdi', ''));
            if ($sablonAdi !== '') {
                $this->yaz('  XsltAdi=' . $sablonAdi . ' — şablon Örnek Holding\'den adıyla isteniyor, eşitleme gerekmez.');
                $this->ozet['guncel']++;
                continue;
            }

            $bizim = $this->bizimEttnler($api->getKanalId());

            foreach ([['EFATURA', '0_fatura.xslt'], ['EARSIV', '0_arsivfatura.xslt']] as [$tur, $dosya]) {
                if ($this->turFiltre && $this->turFiltre !== $tur) continue;
                if ($tur === 'EARSIV' && !$api->earsivVarMi()) continue;
                $this->turIsle($api, $tur, $klasor, $dosya, $bizim);
            }
        }

        $this->yaz('');
        $this->yaz(sprintf('Özet: güncellenen=%d, güncel=%d, uyarı=%d, hata=%d',
            $this->ozet['guncellenen'], $this->ozet['guncel'], $this->ozet['uyari'], $this->ozet['hata']));

        return $this->ozet['hata'] > 0 ? 1 : 0;
    }

    private function turIsle(OrnekHoldingApi $api, string $tur, string $klasor, string $dosya, array $bizim): void
    {
        $yol     = $klasor !== '' ? $this->kok . '/' . $klasor . '/' . $dosya : '';
        $yerel   = ($yol && is_file($yol)) ? self::ozet(file_get_contents($yol)) : '';
        $zaman   = ($yol && is_file($yol) && !$this->tumu) ? filemtime($yol) : 0;
        $eskiler = $klasor !== '' ? $this->yedekMd5ler($klasor, $dosya) : [];

        $etiket = '  ' . str_pad($tur, 7) . ' ';

        // Bugünden geriye gün gün; dosya gününden önceye inilmez
        $sinirGun = strtotime(date('Y-m-d', strtotime('-' . ($this->gun - 1) . ' days')));
        if ($zaman) {
            $sinirGun = max($sinirGun, strtotime(date('Y-m-d', $zaman)));
        }

        $adaylar = [];   // md5 => ['icerik', 'faturalar' => [...]]
        $incelenen = 0;
        for ($g = strtotime(date('Y-m-d')); $g >= $sinirGun && $incelenen < $this->adayLimit; $g -= 86400) {
            $gun   = date('Y-m-d', $g);
            $liste = $this->gunListesi($api, $tur, $gun);
            if ($liste === null) {
                $this->yaz($etiket . 'HATA: ' . $gun . ' listesi alınamadı.');
                $this->ozet['hata']++;
                return;
            }

            foreach ($liste as $f) {
                if ($incelenen >= $this->adayLimit) break;
                $ettn = strtolower((string)$f['ettn']);
                if ($ettn === '' || !empty($f['iptal']) || isset($bizim[$ettn])) continue;

                // e-Fatura: servis kayıt zamanı dosyadan sonra olmalı; e-Arşiv: gün sonra olmalı
                if ($zaman) {
                    if ($tur === 'EFATURA') {
                        $t = !empty($f['servis_tarihi']) ? strtotime($f['servis_tarihi']) : 0;
                        if ($t && $t <= $zaman) continue;
                    } elseif ($gun <= date('Y-m-d', $zaman)) {
                        continue;
                    }
                }

                $xml = $tur === 'EFATURA' ? $api->faturaXml($f['ettn'], 'OUT') : $api->earsivXml($f['ettn']);
                $incelenen++;
                if (!$xml['success']) continue;

                $sablon = $this->gomuluXslt($xml['xml']);
                if ($sablon === null) continue;

                $md5 = self::ozet($sablon);
                if ($md5 === $yerel || isset($eskiler[$md5])) continue;

                $adaylar[$md5]['icerik'] = $sablon;
                $adaylar[$md5]['faturalar'][] = $f['fatura_no'] . ' (' . substr((string)$f['fatura_tarihi'], 0, 10) . ')';
            }
        }

        if (!$adaylar) {
            $this->yaz($etiket . 'Yeni şablon yok' . ($yerel ? ' (yerel güncel)' : '') . '.');
            $this->ozet['guncel']++;
            return;
        }

        if (count($adaylar) > 1) {
            $this->yaz($etiket . 'UYARI: Son faturalarda ' . count($adaylar)
                . ' farklı şablon var (seriye özel şablon olabilir); güncelleme yapılmadı.');
            foreach ($adaylar as $md5 => $a) {
                $this->yaz('           ' . substr($md5, 0, 8) . ' (' . strlen($a['icerik']) . ' B): '
                    . implode(', ', $a['faturalar']));
            }
            $this->ozet['uyari']++;
            return;
        }

        $md5 = array_key_first($adaylar);
        $a   = $adaylar[$md5];
        $bilgi = 'Yeni şablon: ' . strlen($a['icerik']) . ' B, kaynak ' . implode(', ', $a['faturalar']);

        if ($klasor === '') {
            $this->yaz($etiket . $bilgi . ' — XsltKlasoru tanımlı değil, yazılmadı.');
            $this->ozet['uyari']++;
            return;
        }

        $dogrulama = $this->dogrula($a['icerik']);
        if ($dogrulama !== '') {
            $this->yaz($etiket . $bilgi . ' — GEÇERSİZ: ' . $dogrulama);
            $this->ozet['hata']++;
            return;
        }

        if (!$this->uygula) {
            $this->yaz($etiket . $bilgi . ' — güncellenecek (' . $klasor . '/' . $dosya . ').');
            $this->ozet['guncellenen']++;
            return;
        }

        $hata = $this->dosyaYaz($klasor, $dosya, $a['icerik']);
        if ($hata !== '') {
            $this->yaz($etiket . $bilgi . ' — YAZILAMADI: ' . $hata);
            $this->ozet['hata']++;
            return;
        }
        $this->yaz($etiket . $bilgi . ' — GÜNCELLENDİ (' . $klasor . '/' . $dosya . ').');
        $this->ozet['guncellenen']++;
    }

    /** Günün giden faturaları, en yeni önce. Hata → null */
    private function gunListesi(OrnekHoldingApi $api, string $tur, string $gun): ?array
    {
        $r = $tur === 'EFATURA'
            ? $api->faturaListesi('OUT', ['start_date' => $gun, 'end_date' => $gun, 'limit' => 100])
            : $api->earsivListesi(['start_date' => $gun, 'end_date' => $gun, 'limit' => 100]);
        if (!$r['success']) return null;

        $liste = $r['data'] ?? [];
        usort($liste, function ($x, $y) {
            $a = ($x['servis_tarihi'] ?? '') . '|' . ($x['fatura_no'] ?? '');
            $b = ($y['servis_tarihi'] ?? '') . '|' . ($y['fatura_no'] ?? '');
            return strcmp($b, $a);
        });
        return $liste;
    }

    /** Bizim kesim servisimizle kesilen faturaların ETTN'leri (küçük harf anahtar) */
    private function bizimEttnler(int $kanalId): array
    {
        $satirlar = $this->db->fetchAll("
            SELECT Faturalar_ETTN FROM dbo.Faturalar
            WHERE Faturalar_EntegrasyonKanallari_id = ? AND Faturalar_KesimDurumu IS NOT NULL
              AND Faturalar_ETTN IS NOT NULL
        ", [$kanalId]) ?: [];

        $s = [];
        foreach ($satirlar as $r) {
            $s[strtolower(trim((string)$r['Faturalar_ETTN']))] = true;
        }
        return $s;
    }

    /** _yedek/ altındaki eski sürümlerin md5'leri: bunlara geri dönülmez */
    private function yedekMd5ler(string $klasor, string $dosya): array
    {
        $s = [];
        $onEk = pathinfo($dosya, PATHINFO_FILENAME);
        foreach (glob($this->kok . '/' . $klasor . '/_yedek/' . $onEk . '_*.xslt') ?: [] as $f) {
            $s[self::ozet(file_get_contents($f))] = true;
        }
        return $s;
    }

    /** Karşılaştırma özeti: Örnek Holding satır sonlarını kısmen CRLF'ye çevirir, fark sayılmaz */
    private static function ozet(string $icerik): string
    {
        return md5(str_replace("\r\n", "\n", $icerik));
    }

    /** UBL'deki gömülü XSLT içeriği (DocumentType=XSLT veya .xsl* uzantılı ek) */
    private function gomuluXslt(string $xml): ?string
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) return null;

        $x = new DOMXPath($dom);
        $x->registerNamespace('cac', self::NS_CAC);
        $x->registerNamespace('cbc', self::NS_CBC);

        foreach ($x->query('//cac:AdditionalDocumentReference') as $ref) {
            $obj = $x->query('cac:Attachment/cbc:EmbeddedDocumentBinaryObject', $ref)->item(0);
            if (!$obj) continue;
            $tip = $x->query('cbc:DocumentType', $ref)->item(0);
            $xsltMi = ($tip && strtoupper(trim($tip->textContent)) === 'XSLT')
                   || stripos($obj->getAttribute('filename'), '.xsl') !== false;
            if (!$xsltMi) continue;

            $icerik = base64_decode(preg_replace('/\s+/', '', $obj->textContent), true);
            return ($icerik !== false && $icerik !== '') ? $icerik : null;
        }
        return null;
    }

    /** Geçerli XSL stylesheet mi? Boş dönerse geçerli */
    private function dogrula(string $icerik): string
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($icerik, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return 'XML ayrıştırılamadı.';
        }
        $kok = $dom->documentElement;
        if (!$kok || $kok->namespaceURI !== self::NS_XSL
            || !in_array($kok->localName, ['stylesheet', 'transform'], true)) {
            return 'Kök eleman xsl:stylesheet değil.';
        }
        if (class_exists('XSLTProcessor')) {
            $p = new XSLTProcessor();
            if (!@$p->importStylesheet($dom)) {
                return 'XSLTProcessor şablonu yükleyemedi.';
            }
        }
        return '';
    }

    /** Eskiyi _yedek/'e al, yeniyi geçici dosya + rename ile yaz */
    private function dosyaYaz(string $klasor, string $dosya, string $icerik): string
    {
        $dizin = $this->kok . '/' . $klasor;
        $yedek = $dizin . '/_yedek';
        if (!is_dir($dizin) && !@mkdir($dizin, 0775, true)) return 'Klasör oluşturulamadı: ' . $dizin;
        if (!is_dir($yedek) && !@mkdir($yedek, 0775, true)) return 'Yedek klasörü oluşturulamadı.';

        $hedef = $dizin . '/' . $dosya;
        if (is_file($hedef)) {
            $yedekAd = $yedek . '/' . pathinfo($dosya, PATHINFO_FILENAME) . '_' . date('Ymd_His') . '.xslt';
            if (!@copy($hedef, $yedekAd)) return 'Eski dosya yedeklenemedi.';
        }

        $gecici = $hedef . '.tmp';
        if (@file_put_contents($gecici, $icerik) !== strlen($icerik)) {
            @unlink($gecici);
            return 'Geçici dosya yazılamadı.';
        }
        if (!@rename($gecici, $hedef)) {
            @unlink($gecici);
            return 'Dosya değiştirilemedi.';
        }
        return '';
    }

    private function yaz(string $s): void
    {
        echo $s . PHP_EOL;
    }
}

// --- CLI ---
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$sec = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $sec[$m[1]] = $m[2] ?? true;
    }
}

$senkron = new OrnekHoldingXsltSenkron($sec);
exit($senkron->calistir(isset($sec['kanal']) ? (int)$sec['kanal'] : null));
