<?php
/**
 * UBL-TR 1.2 fatura XML üretici (e-Fatura / e-Arşiv)
 *
 * Kaynaktan (pazaryeri siparişi, sipariş vb.) bağımsızdır; FaturaKesim'in
 * hazırladığı nötr belge dizisini XML'e çevirir.
 *
 * Yapı, Örnek Holding portalında (ebelge.ornekholding.com.tr) kesilmiş bir e-Arşiv
 * faturasının XML'inden birebir alınmıştır (DGT2026000000038, 2026-09-17).
 * Portal ile aynı görüntüyü vermesi için:
 *   - Boş kalan alanlar da (BuildingName, Telefax, Region...) boş eleman olarak yazılır
 *   - Sipariş referansı ve not yazılmaz (şablon bunları "Sipariş No" / not olarak basar)
 *   - "İrsaliye yerine geçer." şablon tarafından DespatchDocumentReference yoksa eklenir
 *   - IBAN tablosu PaymentMeans/PayeeFinancialAccount'tan çizilir
 * Eleman sırası UBL 2.1 Invoice şemasına uyar; sıra bozulursa Örnek Holding 10003 döner.
 *
 * Numara: Örnek Holding seri öneki ile atar. cbc:ID boş gönderilemez (10003), bu yüzden
 * seri + yıl + 000000000 yer tutucusu yazılır.
 */
class FaturaUbl
{
    const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    const NS_CAC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const NS_CBC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    const NS_EXT     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    /** Birim fiyat hassasiyeti (portal uzun ondalık yazar; görüntüde 8 hane görünür) */
    const FIYAT_HANE = 8;

    /** Seri önekinden yer tutucu fatura numarası (16 hane) */
    public static function yerTutucuNo($seri, $yil = null)
    {
        return strtoupper($seri) . ($yil ?: date('Y')) . '000000000';
    }

    /** RFC 4122 v4 ETTN */
    public static function ettn()
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        // Örnek Holding listeleri ETTN'yi küçük harfle döndürür; cron eşleşmesi için aynı biçim
        return (substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
             . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12));
    }

    /**
     * @param array $b Nötr belge:
     *   ettn, no, earsiv(bool), senaryo, tip, tarih(Y-m-d), saat(H:i:s), para,
     *   gonderim_sekli (e-Arşiv: ELEKTRONIK | KAGIT),
     *   xslt_ad, xslt_icerik (isteğe bağlı; boşsa XSLT gömülmez),
     *   satici  [vkn, unvan, vergi_dairesi, adres, il, ilce, telefon, eposta],
     *   alici   [kimlik, kimlik_tur VKN|TCKN, unvan, ad, soyad, vergi_dairesi, adres, il, ilce, ulke, telefon, eposta],
     *   odeme   [kod, hesaplar[ [iban, banka, sube, para] ]]  (boşsa PaymentMeans yazılmaz)
     *   kalemler[ [ad, miktar, birim, matrah, kdv_oran, kdv, brut] ],
     *   toplam  [matrah, kdv, genel, kdv_gruplari{oran => [matrah, kdv]}]
     */
    public static function olustur(array $b)
    {
        $para = $b['para'] ?: 'TRY';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_XML1, 'UTF-8');
        $t = fn($v) => self::tutar($v);
        $st = $b['satici'];
        $al = $b['alici'];

        $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $x .= '<Invoice xmlns="' . self::NS_INVOICE . '" xmlns:cac="' . self::NS_CAC . '"'
            . ' xmlns:cbc="' . self::NS_CBC . '" xmlns:ext="' . self::NS_EXT . '">' . "\n";

        // İmza bloğu Örnek Holding tarafında doldurulur
        $x .= '<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>' . "\n";
        $x .= '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>' . "\n";
        $x .= '<cbc:CustomizationID>TR1.2</cbc:CustomizationID>' . "\n";
        $x .= '<cbc:ProfileID>' . $e($b['senaryo']) . '</cbc:ProfileID>' . "\n";
        $x .= '<cbc:ID>' . $e($b['no']) . '</cbc:ID>' . "\n";
        $x .= '<cbc:CopyIndicator>false</cbc:CopyIndicator>' . "\n";
        $x .= '<cbc:UUID>' . $e($b['ettn']) . '</cbc:UUID>' . "\n";
        $x .= '<cbc:IssueDate>' . $e($b['tarih']) . '</cbc:IssueDate>' . "\n";
        $x .= '<cbc:IssueTime>' . $e($b['saat']) . '</cbc:IssueTime>' . "\n";
        $x .= '<cbc:InvoiceTypeCode>' . $e($b['tip']) . '</cbc:InvoiceTypeCode>' . "\n";
        foreach ($b['notlar'] ?? [] as $not) {
            $x .= '<cbc:Note>' . $e($not) . '</cbc:Note>' . "\n";
        }
        $x .= '<cbc:DocumentCurrencyCode>' . $e($para) . '</cbc:DocumentCurrencyCode>' . "\n";
        $x .= '<cbc:LineCountNumeric>' . count($b['kalemler']) . '</cbc:LineCountNumeric>' . "\n";

        // Görüntüleme şablonu (GİB: XSLT belge içine gömülür).
        // İsteğe bağlı: şablon verilmezse Örnek Holding'nin varsayılan görüntüsü kullanılır.
        if (!empty($b['xslt_icerik'])) {
            $x .= '<cac:AdditionalDocumentReference><cbc:ID>' . $e($b['ettn']) . '</cbc:ID>'
                . '<cbc:IssueDate>' . $e($b['tarih']) . '</cbc:IssueDate>'
                . '<cbc:DocumentType>XSLT</cbc:DocumentType><cac:Attachment>'
                . '<cbc:EmbeddedDocumentBinaryObject characterSetCode="UTF-8" encodingCode="Base64"'
                . ' filename="' . $e($b['xslt_ad'] ?? 'fatura.xslt') . '" mimeCode="application/xml">'
                . base64_encode($b['xslt_icerik'])
                . '</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>' . "\n";
        }

        if (!empty($b['earsiv'])) {
            // Gönderim şekli (ELEKTRONIK / KAGIT) — şablon ve GİB raporu bu referanstan okur
            $x .= '<cac:AdditionalDocumentReference><cbc:ID>1</cbc:ID>'
                . '<cbc:IssueDate>' . $e($b['tarih']) . '</cbc:IssueDate>'
                . '<cbc:DocumentTypeCode>SendingType</cbc:DocumentTypeCode>'
                . '<cbc:DocumentType>' . $e($b['gonderim_sekli'] ?: 'ELEKTRONIK') . '</cbc:DocumentType>'
                . '</cac:AdditionalDocumentReference>' . "\n";
        }

        $x .= '<cac:Signature><cbc:ID schemeID="VKN_TCKN">' . $e($st['vkn']) . '</cbc:ID><cac:SignatoryParty>'
            . '<cac:PartyIdentification><cbc:ID schemeID="' . self::kimlikTur($st['vkn']) . '">' . $e($st['vkn']) . '</cbc:ID></cac:PartyIdentification>'
            . self::adres($st, $e)
            . '</cac:SignatoryParty><cac:DigitalSignatureAttachment><cac:ExternalReference>'
            . '<cbc:URI>#Signature_' . $e($b['no']) . '</cbc:URI></cac:ExternalReference></cac:DigitalSignatureAttachment></cac:Signature>' . "\n";

        // Satıcı
        $x .= '<cac:AccountingSupplierParty><cac:Party>'
            . '<cbc:WebsiteURI/>'
            . '<cac:PartyIdentification><cbc:ID schemeID="' . self::kimlikTur($st['vkn']) . '">' . $e($st['vkn']) . '</cbc:ID></cac:PartyIdentification>'
            . '<cac:PartyName><cbc:Name>' . $e($st['unvan']) . '</cbc:Name></cac:PartyName>'
            . self::adres($st, $e)
            . '<cac:PartyTaxScheme><cac:TaxScheme><cbc:Name>' . $e($st['vergi_dairesi']) . '</cbc:Name></cac:TaxScheme></cac:PartyTaxScheme>'
            . self::iletisim($st, $e)
            . '</cac:Party></cac:AccountingSupplierParty>' . "\n";

        // Alıcı — portal: TCKN'de PartyName yok, Person var; VKN'de PartyName var
        $x .= '<cac:AccountingCustomerParty><cac:Party>'
            . '<cac:PartyIdentification><cbc:ID schemeID="' . $e($al['kimlik_tur']) . '">' . $e($al['kimlik']) . '</cbc:ID></cac:PartyIdentification>';
        if ($al['kimlik_tur'] === 'VKN') {
            $x .= '<cac:PartyName><cbc:Name>' . $e($al['unvan']) . '</cbc:Name></cac:PartyName>';
        }
        $x .= self::adres($al, $e);
        // VKN'li alıcıda vergi dairesi GİB şematronunca beklenir (boş da olsa yazılır).
        // TCKN'li alıcıda zorunlu değil; vergi dairesi yoksa blok hiç yazılmaz.
        if ($al['kimlik_tur'] === 'VKN' || trim((string)$al['vergi_dairesi']) !== '') {
            $x .= '<cac:PartyTaxScheme><cac:TaxScheme><cbc:Name>' . $e($al['vergi_dairesi']) . '</cbc:Name></cac:TaxScheme></cac:PartyTaxScheme>';
        }
        $x .= self::iletisim($al, $e);
        if ($al['kimlik_tur'] === 'TCKN') {
            // GİB şematronu: TCKN'de cac:Person zorunlu, Contact'tan sonra gelir
            $x .= '<cac:Person><cbc:FirstName>' . $e($al['ad']) . '</cbc:FirstName>'
                . '<cbc:FamilyName>' . $e($al['soyad']) . '</cbc:FamilyName></cac:Person>';
        }
        $x .= '</cac:Party></cac:AccountingCustomerParty>' . "\n";

        // Ödeme: IBAN tablosu şablonda buradan çizilir
        foreach ($b['odeme']['hesaplar'] ?? [] as $h) {
            $x .= '<cac:PaymentMeans><cbc:PaymentMeansCode>' . $e($b['odeme']['kod']) . '</cbc:PaymentMeansCode>'
                . '<cac:PayeeFinancialAccount>'
                . '<cbc:ID>' . $e(preg_replace('/\s+/', '', $h['iban'])) . '</cbc:ID>'
                . '<cbc:CurrencyCode>' . $e($h['para'] ?? $para) . '</cbc:CurrencyCode>'
                . '<cac:FinancialInstitutionBranch><cbc:Name>' . $e($h['sube'] ?? '') . '</cbc:Name>'
                . '<cac:FinancialInstitution><cbc:Name>' . $e($h['banka'] ?? '') . '</cbc:Name></cac:FinancialInstitution>'
                . '</cac:FinancialInstitutionBranch>'
                . '</cac:PayeeFinancialAccount></cac:PaymentMeans>' . "\n";
        }

        // Vergi toplamı
        $x .= '<cac:TaxTotal><cbc:TaxAmount currencyID="' . $para . '">' . $t($b['toplam']['kdv']) . '</cbc:TaxAmount>';
        $sira = 1;
        foreach ($b['toplam']['kdv_gruplari'] as $oran => $g) {
            $x .= self::vergiAlt($g['matrah'], $g['kdv'], $oran, $para, $sira++);
        }
        $x .= '</cac:TaxTotal>' . "\n";

        $x .= '<cac:LegalMonetaryTotal>'
            . '<cbc:LineExtensionAmount currencyID="' . $para . '">' . $t($b['toplam']['matrah']) . '</cbc:LineExtensionAmount>'
            . '<cbc:TaxExclusiveAmount currencyID="' . $para . '">' . $t($b['toplam']['matrah']) . '</cbc:TaxExclusiveAmount>'
            . '<cbc:TaxInclusiveAmount currencyID="' . $para . '">' . $t($b['toplam']['genel']) . '</cbc:TaxInclusiveAmount>'
            . '<cbc:AllowanceTotalAmount currencyID="' . $para . '">0.00</cbc:AllowanceTotalAmount>'
            . '<cbc:ChargeTotalAmount currencyID="' . $para . '">0.00</cbc:ChargeTotalAmount>'
            . '<cbc:PayableAmount currencyID="' . $para . '">' . $t($b['toplam']['genel']) . '</cbc:PayableAmount>'
            . '</cac:LegalMonetaryTotal>' . "\n";

        foreach ($b['kalemler'] as $i => $k) {
            $miktar = (float)$k['miktar'] ?: 1;
            // Portal birim fiyatı yuvarlanmamış matrahtan hesaplar (KDV dahil tutar / (1+oran) / adet)
            $birimFiyat = isset($k['brut'])
                ? $k['brut'] / (1 + $k['kdv_oran'] / 100) / $miktar
                : $k['matrah'] / $miktar;

            $x .= '<cac:InvoiceLine><cbc:ID>' . ($i + 1) . '</cbc:ID><cbc:Note/>'
                . '<cbc:InvoicedQuantity unitCode="' . $e($k['birim'] ?? 'C62') . '">' . self::sayi($miktar) . '</cbc:InvoicedQuantity>'
                . '<cbc:LineExtensionAmount currencyID="' . $para . '">' . $t($k['matrah']) . '</cbc:LineExtensionAmount>'
                . '<cac:TaxTotal><cbc:TaxAmount currencyID="' . $para . '">' . $t($k['kdv']) . '</cbc:TaxAmount>'
                . self::vergiAlt($k['matrah'], $k['kdv'], $k['kdv_oran'], $para, 1)
                . '</cac:TaxTotal>'
                . '<cac:Item><cbc:Name>' . $e($k['ad']) . '</cbc:Name></cac:Item>'
                . '<cac:Price><cbc:PriceAmount currencyID="' . $para . '">'
                . number_format($birimFiyat, self::FIYAT_HANE, '.', '') . '</cbc:PriceAmount></cac:Price>'
                . '</cac:InvoiceLine>' . "\n";
        }

        $x .= '</Invoice>';
        return $x;
    }

    private static function vergiAlt($matrah, $kdv, $oran, $para, $sira)
    {
        return '<cac:TaxSubtotal>'
            . '<cbc:TaxableAmount currencyID="' . $para . '">' . self::tutar($matrah) . '</cbc:TaxableAmount>'
            . '<cbc:TaxAmount currencyID="' . $para . '">' . self::tutar($kdv) . '</cbc:TaxAmount>'
            . '<cbc:CalculationSequenceNumeric>' . (int)$sira . '</cbc:CalculationSequenceNumeric>'
            . '<cbc:Percent>' . number_format((float)$oran, 2, '.', '') . '</cbc:Percent>'
            . '<cac:TaxCategory><cac:TaxScheme><cbc:Name>KDV</cbc:Name><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory>'
            . '</cac:TaxSubtotal>';
    }

    /** Portal boş alanları da boş eleman olarak yazar */
    private static function adres(array $p, callable $e)
    {
        return '<cac:PostalAddress>'
            . '<cbc:StreetName>' . $e($p['adres'] ?? '') . '</cbc:StreetName>'
            . '<cbc:BuildingName/><cbc:BuildingNumber/>'
            . '<cbc:CitySubdivisionName>' . $e($p['ilce'] ?? '') . '</cbc:CitySubdivisionName>'
            . '<cbc:CityName>' . $e($p['il'] ?? '') . '</cbc:CityName>'
            . '<cbc:PostalZone>' . $e($p['posta_kodu'] ?? '') . '</cbc:PostalZone><cbc:Region/>'
            . '<cac:Country><cbc:Name>' . $e($p['ulke'] ?? 'Türkiye') . '</cbc:Name></cac:Country>'
            . '</cac:PostalAddress>';
    }

    private static function iletisim(array $p, callable $e)
    {
        return '<cac:Contact>'
            . (!empty($p['telefon']) ? '<cbc:Telephone>' . $e($p['telefon']) . '</cbc:Telephone>' : '<cbc:Telephone/>')
            . '<cbc:Telefax/>'
            . (!empty($p['eposta']) ? '<cbc:ElectronicMail>' . $e($p['eposta']) . '</cbc:ElectronicMail>' : '<cbc:ElectronicMail/>')
            . '</cac:Contact>';
    }

    private static function kimlikTur($no)
    {
        return strlen(preg_replace('/\D/', '', (string)$no)) === 11 ? 'TCKN' : 'VKN';
    }

    /** -0.00 çıktısı şematronda geçersiz sayılır */
    private static function tutar($v)
    {
        $v = round((float)$v, 2);
        if (abs($v) < 0.005) $v = 0.0;
        return number_format($v, 2, '.', '');
    }

    private static function sayi($v)
    {
        return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
    }
}
