<?php
/**
 * Bagimliliksiz XLSX (Excel 2007+) yazici.
 * Sadece ZipArchive kullanir, harici kutuphane gerektirmez.
 *
 * Kullanim:
 *   $x = new XlsxYazici('Odeme Hareketleri');
 *   $x->basliklar(['Tarih', 'Tutar']);
 *   $x->satir(['2026-09-07', ['deger' => 3500, 'tur' => 'para']]);
 *   $x->indir('odeme-hareketleri.xlsx');
 */
class XlsxYazici
{
    /** @var string Sayfa (worksheet) adi */
    private $sayfaAdi;

    /** @var array<int, array<int, array{deger: mixed, tur: string}>> Hucre matrisi */
    private $satirlar = [];

    /** @var int Baslik satiri var mi (dondurma icin) */
    private $baslikVar = 0;

    /** @var array<int, int> Kolon indeksi => en uzun metin uzunlugu */
    private $kolonGenislik = [];

    // Stil indeksleri (styles.xml icindeki cellXfs sirasi)
    const STIL_NORMAL  = 0;
    const STIL_BASLIK  = 1;
    const STIL_PARA    = 2;
    const STIL_TARIH   = 3;

    public function __construct($sayfaAdi = 'Sayfa1')
    {
        // Excel sayfa adi kisitlari: 31 karakter, bazi karakterler yasak
        $sayfaAdi = preg_replace('/[\\\\\/\?\*\[\]:]/u', '-', (string)$sayfaAdi);
        $this->sayfaAdi = mb_substr($sayfaAdi ?: 'Sayfa1', 0, 31, 'UTF-8');
    }

    /**
     * Baslik satirini ekler (kalin, gri zemin, dondurulmus).
     * @param array<int, string> $basliklar
     */
    public function basliklar(array $basliklar)
    {
        $hucreler = [];
        foreach ($basliklar as $b) {
            $hucreler[] = ['deger' => (string)$b, 'tur' => 'baslik'];
        }
        array_unshift($this->satirlar, $hucreler);
        $this->baslikVar = 1;
        $this->genislikGuncelle($hucreler);
    }

    /**
     * Veri satiri ekler.
     * Her hucre ya duz deger, ya da ['deger' => ..., 'tur' => 'metin|para|sayi|tarih'] dizisi.
     * @param array<int, mixed> $hucreler
     */
    public function satir(array $hucreler)
    {
        $normal = [];
        foreach ($hucreler as $h) {
            if (is_array($h)) {
                $normal[] = ['deger' => $h['deger'] ?? '', 'tur' => $h['tur'] ?? 'metin'];
            } else {
                $normal[] = ['deger' => $h, 'tur' => 'metin'];
            }
        }
        $this->satirlar[] = $normal;
        $this->genislikGuncelle($normal);
    }

    /**
     * Dosyayi uretip tarayiciya indirme olarak gonderir ve script'i sonlandirir.
     */
    public function indir($dosyaAdi = 'rapor.xlsx')
    {
        $icerik = $this->uret();

        if (ob_get_length()) {
            ob_end_clean();
        }

        $guvenliAd = preg_replace('/[^A-Za-z0-9._-]/', '-', $dosyaAdi);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $guvenliAd . '"; '
             . "filename*=UTF-8''" . rawurlencode($dosyaAdi));
        header('Content-Length: ' . strlen($icerik));
        header('Cache-Control: max-age=0, must-revalidate');
        header('Pragma: public');

        echo $icerik;
        exit;
    }

    /**
     * XLSX dosyasinin ham icerigini dondurur.
     * @return string
     */
    public function uret()
    {
        $gecici = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($gecici === false) {
            throw new RuntimeException('Gecici dosya olusturulamadi.');
        }

        $zip = new ZipArchive();
        if ($zip->open($gecici, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('XLSX arsivi olusturulamadi.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->kokRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();

        $icerik = file_get_contents($gecici);
        @unlink($gecici);

        return $icerik;
    }

    // ---------------------------------------------------------------- yardimci

    private function genislikGuncelle(array $hucreler)
    {
        foreach ($hucreler as $i => $h) {
            $uzunluk = mb_strlen((string)$h['deger'], 'UTF-8');
            if (!isset($this->kolonGenislik[$i]) || $uzunluk > $this->kolonGenislik[$i]) {
                $this->kolonGenislik[$i] = $uzunluk;
            }
        }
    }

    /** 0 => A, 25 => Z, 26 => AA */
    private static function kolonHarfi($indeks)
    {
        $harf = '';
        $indeks++;
        while ($indeks > 0) {
            $kalan = ($indeks - 1) % 26;
            $harf = chr(65 + $kalan) . $harf;
            $indeks = (int)(($indeks - $kalan - 1) / 26);
        }
        return $harf;
    }

    /** XML metin kacisi + gecersiz kontrol karakterlerini temizleme */
    private static function xml($deger)
    {
        $deger = (string)$deger;
        $deger = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $deger);
        return htmlspecialchars($deger, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ------------------------------------------------------------ xml parcalari

    private function contentTypes()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function kokRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($this->sayfaAdi) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="dd.mm.yyyy"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D6EFD"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFBBBBBB"/></left><right style="thin"><color rgb="FFBBBBBB"/></right>'
            . '<top style="thin"><color rgb="FFBBBBBB"/></top><bottom style="thin"><color rgb="FFBBBBBB"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            // 0: normal
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
            // 1: baslik
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 2: para
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            // 3: tarih (metin olarak yazilan tarihler icin de kullanilabilir)
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function cols()
    {
        if (!$this->kolonGenislik) {
            return '';
        }

        $xml = '<cols>';
        foreach ($this->kolonGenislik as $i => $uzunluk) {
            $genislik = min(max($uzunluk + 3, 10), 55);
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $genislik . '" customWidth="1"/>';
        }
        return $xml . '</cols>';
    }

    private function sheet()
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>';

        if ($this->baslikVar) {
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                 . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                 . '</sheetView></sheetViews>';
        }

        $xml .= '<sheetFormatPr defaultRowHeight="15"/>' . $this->cols() . '<sheetData>';

        foreach ($this->satirlar as $satirNo => $hucreler) {
            $r = $satirNo + 1;
            $xml .= '<row r="' . $r . '"' . ($satirNo === 0 && $this->baslikVar ? ' ht="24" customHeight="1"' : '') . '>';

            foreach ($hucreler as $kolonNo => $hucre) {
                $ref   = self::kolonHarfi($kolonNo) . $r;
                $deger = $hucre['deger'];
                $tur   = $hucre['tur'];

                if ($tur === 'tarih') {
                    $zaman = strtotime((string)$deger);
                    if ($zaman === false) {
                        continue;
                    }
                    // Excel seri tarihi: 1899-12-30 baslangicli gun sayisi
                    $seri = (int)floor($zaman / 86400) + 25569;
                    $xml .= '<c r="' . $ref . '" s="' . self::STIL_TARIH . '"><v>' . $seri . '</v></c>';
                    continue;
                }

                if ($tur === 'para' || $tur === 'sayi') {
                    if ($deger === null || $deger === '') {
                        continue; // bos hucre yazma
                    }
                    $stil = ($tur === 'para') ? self::STIL_PARA : self::STIL_NORMAL;
                    $xml .= '<c r="' . $ref . '" s="' . $stil . '"><v>' . (0 + $deger) . '</v></c>';
                    continue;
                }

                if ($deger === null || $deger === '') {
                    continue;
                }

                $stil = ($tur === 'baslik') ? self::STIL_BASLIK : self::STIL_NORMAL;
                $xml .= '<c r="' . $ref . '" s="' . $stil . '" t="inlineStr"><is><t xml:space="preserve">'
                      . self::xml($deger) . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }
}
