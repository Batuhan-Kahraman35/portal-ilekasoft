<?php
/**
 * Örnek Holding E-Fatura / E-Arşiv API Katmanı — Portal Örnek Soft
 *
 * i2i altyapılı SOAP servisi. İstekler ham cURL + string XML ile üretilir;
 * PHP SoapClient KULLANILMAZ — i2i şemaları eleman sırasına duyarlıdır ve
 * SoapClient bu sırayı bozabilir.
 *
 * Ad alanları:
 *   - Kimlik doğrulama + E-Fatura : http://schemas.i2i.com/ei/wsdl
 *   - E-Arşiv                     : http://schemas.i2i.com/ei/wsdl/archive
 *
 * Ayarlar veritabanından okunur (kodda endpoint hardcode YOK):
 *   Entegrasyonlar.Entegrasyonlar_AyarJSON → KimlikDogrulama / EFatura / EArsiv
 *   EntegrasyonKanallari                   → KullaniciAdi / Sifre / Ad
 *
 * Bu sınıf yalnızca servisle konuşur; Faturalar tablosuna yazma işi
 * admin/cron/ornekholding-senkron.php dosyasındadır.
 *
 * Referans: Dökümantasyon/ornekholding.md, ornekmusteri.com.tr sürüm 1.18.0
 */

require_once __DIR__ . '/../db.php';

class OrnekHoldingApi
{
    const APPLICATION_NAME  = 'PortalOrnekSoft';
    const NS_WSDL           = 'http://schemas.i2i.com/ei/wsdl';
    const NS_WSDL_ARCHIVE   = 'http://schemas.i2i.com/ei/wsdl/archive';
    const SESSION_OMRU_SN   = 25200;   // 7 saat (servis 8 saat veriyor, öncesinde yenile)
    const LISTE_LIMIT       = 1000;

    private $db;
    private array $kanal;
    private array $config = [];
    private int $kanalId;
    private string $kanalAdi;
    private ?int $userId;
    private string $hata = '';

    /** Kanal bazlı oturum önbelleği: [kanalId => ['id' => JWT, 'zaman' => ts]] */
    private static array $oturumlar = [];

    /**
     * @param array    $kanal  EntegrasyonKanallari satırı + Entegrasyonlar_AyarJSON
     * @param int|null $userId Log kayıtlarına yazılacak kullanıcı
     */
    public function __construct(array $kanal, ?int $userId = 1)
    {
        $this->db       = Database::getInstance();
        $this->kanal    = $kanal;
        $this->userId   = $userId;
        $this->kanalId  = (int)($kanal['EntegrasyonKanallari_id'] ?? 0);
        $this->kanalAdi = (string)($kanal['EntegrasyonKanallari_Ad'] ?? '');

        $this->configYukle();
    }

    /**
     * Aktif ÖRNEK HOLDİNG kanallarını entegrasyon ayarlarıyla birlikte döner.
     */
    public static function aktifKanallar($db = null): array
    {
        $db = $db ?: Database::getInstance();

        return $db->fetchAll("
            SELECT k.*, e.Entegrasyonlar_AyarJSON, e.Entegrasyonlar_id
            FROM Entegrasyonlar e
            INNER JOIN EntegrasyonKanallari k
                    ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Ad = 'ÖRNEK HOLDİNG'
              AND k.EntegrasyonKanallari_Durum = 1
            ORDER BY k.EntegrasyonKanallari_id
        ") ?: [];
    }

    private function configYukle(): void
    {
        $ayar = json_decode((string)($this->kanal['Entegrasyonlar_AyarJSON'] ?? '{}'), true) ?: [];

        $this->config = array_merge($ayar, [
            'KullaniciAdi' => $this->kanal['EntegrasyonKanallari_KullaniciAdi'] ?? '',
            'Sifre'        => $this->kanal['EntegrasyonKanallari_Sifre'] ?? '',
        ]);

        if (empty($this->config['KimlikDogrulama']) || empty($this->config['EFatura'])) {
            $this->hata = 'Entegrasyon ayarlarında KimlikDogrulama / EFatura endpoint tanımlı değil.';
        } elseif ($this->config['KullaniciAdi'] === '' || $this->config['Sifre'] === '') {
            $this->hata = 'Kanal ayarlarında kullanıcı adı / şifre tanımlı değil: ' . $this->kanalAdi;
        }
    }

    public function hazirMi(): bool
    {
        return $this->hata === '';
    }

    public function getHata(): string
    {
        return $this->hata;
    }

    public function getKanalId(): int
    {
        return $this->kanalId;
    }

    public function getKanalAdi(): string
    {
        return $this->kanalAdi;
    }

    public function earsivVarMi(): bool
    {
        return !empty($this->config['EArsiv']);
    }

    // ================================================================
    // Transport
    // ================================================================

    /** WSDL adresinden servis endpoint'i üret (?wsdl kırpılır) */
    private function endpoint(string $anahtar): string
    {
        return preg_replace('/\?wsdl$/i', '', (string)($this->config[$anahtar] ?? ''));
    }

    private function requestHeaderXml(string $sessionId): string
    {
        return '<REQUEST_HEADER>'
             . '<SESSION_ID>' . htmlspecialchars($sessionId, ENT_XML1) . '</SESSION_ID>'
             . '<APPLICATION_NAME>' . self::APPLICATION_NAME . '</APPLICATION_NAME>'
             . '<CHANNEL_NAME>' . htmlspecialchars($this->kanalAdi, ENT_XML1) . '</CHANNEL_NAME>'
             . '<COMPRESSED>N</COMPRESSED>'
             . '</REQUEST_HEADER>';
    }

    /**
     * Ham SOAP çağrısı (document/literal, SOAPAction boş) + loglama.
     *
     * @return array [DOMDocument|null, string hataMesaji]
     */
    private function soapCall(string $url, string $rootElement, string $icXml, string $islem,
                             array $logIstek = [], string $ns = self::NS_WSDL): array
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body>'
            . '<ns:' . $rootElement . ' xmlns:ns="' . $ns . '">'
            . $icXml
            . '</ns:' . $rootElement . '>'
            . '</soapenv:Body>'
            . '</soapenv:Envelope>';

        $baslangic = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
            ],
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            // Plesk WAF (ModSecurity) User-Agent'sız istekleri 403 ile engelliyor
            CURLOPT_USERAGENT      => 'PortalOrnekSoft-OrnekHoldingApi/1.0',
        ]);
        $yanit   = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpKod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $sureMs = (int)round((microtime(true) - $baslangic) * 1000);

        $dom  = null;
        $hata = '';

        if ($yanit === false) {
            $hata = 'Bağlantı hatası: ' . ($curlErr ?: 'bilinmeyen');
        } else {
            $dom = new DOMDocument();
            if (!@$dom->loadXML($yanit, LIBXML_PARSEHUGE)) {
                $dom  = null;
                $hata = 'Geçersiz SOAP yanıtı (HTTP ' . $httpKod . '): '
                      . mb_substr(strip_tags($yanit), 0, 200);
            } else {
                $fault = $dom->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault');
                if ($fault->length > 0) {
                    $faultString = '';
                    foreach ($fault->item(0)->childNodes as $child) {
                        if (strtolower($child->localName ?? '') === 'faultstring') {
                            $faultString = trim($child->textContent);
                        }
                    }
                    $hata = 'SOAP Fault: ' . ($faultString ?: 'bilinmeyen');
                } else {
                    $err = $this->ilkElement($dom, 'ERROR_TYPE');
                    if ($err) {
                        $kod   = $this->altDeger($err, 'ERROR_CODE');
                        $mesaj = $this->altDeger($err, 'ERROR_SHORT_DES');
                        $hata  = 'Örnek Holding hatası [' . $kod . ']: ' . $mesaj;
                    }
                }
            }
        }

        $this->logla($islem, $logIstek, $hata, $httpKod, $sureMs, $yanit);

        return [$dom, $hata];
    }

    /**
     * Oturum aç (SESSION_ID = JWT, 8 saat geçerli).
     * CLI'de $_SESSION olmadığı için kanal bazlı statik önbellek kullanılır.
     */
    private function login(bool $zorla = false): ?string
    {
        if (!$zorla && isset(self::$oturumlar[$this->kanalId])) {
            $o = self::$oturumlar[$this->kanalId];
            if ((time() - $o['zaman']) < self::SESSION_OMRU_SN) {
                return $o['id'];
            }
        }

        $icXml = $this->requestHeaderXml('-1')
               . '<USER_NAME>' . htmlspecialchars((string)$this->config['KullaniciAdi'], ENT_XML1) . '</USER_NAME>'
               . '<PASSWORD>' . htmlspecialchars((string)$this->config['Sifre'], ENT_XML1) . '</PASSWORD>';

        [$dom, $hata] = $this->soapCall(
            $this->endpoint('KimlikDogrulama'),
            'LoginRequest',
            $icXml,
            'Login',
            ['KullaniciAdi' => $this->config['KullaniciAdi']]   // şifre loglanmaz
        );

        if ($hata !== '') {
            $this->hata = $hata;
            return null;
        }

        // SESSION_ID yanıtta xmlns="" ile gelir → localName ile okunur, regex kullanılmaz
        $sessionId = '';
        $el = $this->ilkElement($dom, 'SESSION_ID');
        if ($el) {
            $sessionId = trim($el->textContent);
        }

        if ($sessionId === '' || $sessionId === '-1') {
            $this->hata = 'Örnek Holding oturumu açılamadı (SESSION_ID alınamadı).';
            return null;
        }

        self::$oturumlar[$this->kanalId] = ['id' => $sessionId, 'zaman' => time()];

        return $sessionId;
    }

    /**
     * Oturumlu çağrı sarmalayıcısı: 10004 (geçersiz oturum) gelirse
     * yeniden login olup isteği BİR KEZ tekrarlar.
     */
    private function oturumluCagri(callable $islem): array
    {
        if (!$this->hazirMi()) {
            return ['success' => false, 'message' => $this->hata, 'data' => []];
        }

        $sessionId = $this->login();
        if ($sessionId === null) {
            return ['success' => false, 'message' => $this->hata, 'data' => []];
        }

        $sonuc = $islem($sessionId);

        if (!$sonuc['success'] && strpos($sonuc['message'], '[10004]') !== false) {
            $sessionId = $this->login(true);
            if ($sessionId === null) {
                return ['success' => false, 'message' => $this->hata, 'data' => []];
            }
            $sonuc = $islem($sessionId);
        }

        return $sonuc;
    }

    // ================================================================
    // E-Fatura
    // ================================================================

    /**
     * E-Fatura listesi (özet — HEADER_ONLY=Y).
     *
     * @param string $direction 'IN' = gelen, 'OUT' = giden
     * @param array  $filtre    [start_date, end_date, uuid, limit]
     */
    public function faturaListesi(string $direction, array $filtre = []): array
    {
        $direction = strtoupper($direction) === 'OUT' ? 'OUT' : 'IN';

        return $this->oturumluCagri(
            fn($sessionId) => $this->faturaListesiCek($sessionId, $direction, $filtre)
        );
    }

    /**
     * XSD eleman sırası zorunlu:
     * LIMIT, ID, UUID, EXTERNAL_ID, TYPE, FROM, TO, START_DATE, END_DATE,
     * READ_INCLUDED, DIRECTION, SENDER, RECEIVER, ...
     */
    private function faturaListesiCek(string $sessionId, string $direction, array $filtre): array
    {
        $searchKey  = '<INVOICE_SEARCH_KEY>';
        $searchKey .= '<LIMIT>' . (int)($filtre['limit'] ?? self::LISTE_LIMIT) . '</LIMIT>';
        if (!empty($filtre['uuid'])) {
            $searchKey .= '<UUID>' . htmlspecialchars($filtre['uuid'], ENT_XML1) . '</UUID>';
        }
        if (!empty($filtre['start_date'])) {
            $searchKey .= '<START_DATE>' . htmlspecialchars($filtre['start_date'], ENT_XML1) . '</START_DATE>';
        }
        if (!empty($filtre['end_date'])) {
            $searchKey .= '<END_DATE>' . htmlspecialchars($filtre['end_date'], ENT_XML1) . '</END_DATE>';
        }
        // READ_INCLUDED olmazsa okunmuş faturalar listeye gelmez
        $searchKey .= '<READ_INCLUDED>true</READ_INCLUDED>';
        $searchKey .= '<DIRECTION>' . $direction . '</DIRECTION>';
        // Servis istek başına en fazla 100 belge döner ve tarih alanı gün altına
        // bölünemez (tip: date). Tek gün 100'ü aşarsa karşı taraf VKN'siyle bölünür.
        if (!empty($filtre['sender'])) {
            $searchKey .= '<SENDER>' . htmlspecialchars($filtre['sender'], ENT_XML1) . '</SENDER>';
        }
        if (!empty($filtre['receiver'])) {
            $searchKey .= '<RECEIVER>' . htmlspecialchars($filtre['receiver'], ENT_XML1) . '</RECEIVER>';
        }
        $searchKey .= '</INVOICE_SEARCH_KEY>';

        $icXml = $this->requestHeaderXml($sessionId) . $searchKey . '<HEADER_ONLY>Y</HEADER_ONLY>';

        [$dom, $hata] = $this->soapCall(
            $this->endpoint('EFatura'),
            'GetInvoiceRequest',
            $icXml,
            'EFATURA/' . ($direction === 'IN' ? 'GELEN' : 'GIDEN') . ' Liste',
            array_filter([
                'direction'  => $direction,
                'start_date' => $filtre['start_date'] ?? null,
                'end_date'   => $filtre['end_date'] ?? null,
            ])
        );

        if ($hata !== '') {
            // 10008 = kayıt bulunamadı → hata değil, boş liste
            if (strpos($hata, '[10008]') !== false) {
                return ['success' => true, 'message' => '', 'data' => []];
            }
            return ['success' => false, 'message' => $hata, 'data' => []];
        }

        $faturalar = [];
        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node->localName !== 'INVOICE') continue;

            $header = $this->altElement($node, 'HEADER');
            if (!$header) continue;

            // Fatura no ve ETTN nitelik olarak gelir
            $faturalar[] = [
                'fatura_no'      => $node->getAttribute('ID'),
                'ettn'           => $node->getAttribute('UUID'),
                'gonderen_vkn'   => $this->altDeger($header, 'SENDER'),
                'gonderen_unvan' => $this->altDeger($header, 'SUPPLIER'),
                'alici_vkn'      => $this->altDeger($header, 'RECEIVER'),
                'alici_unvan'    => $this->altDeger($header, 'CUSTOMER'),
                'fatura_tarihi'  => $this->altDeger($header, 'ISSUE_DATE'),
                'tutar'          => $this->altDeger($header, 'PAYABLE_AMOUNT'),
                'para_birimi'    => $this->altAttr($header, 'PAYABLE_AMOUNT', 'currencyID'),
                'senaryo'        => $this->altDeger($header, 'PROFILEID'),
                'fatura_tipi'    => $this->altDeger($header, 'INVOICE_TYPE_CODE'),
                'durum'          => $this->altDeger($header, 'STATUS'),
                'gib_durum'      => $this->altDeger($header, 'GIB_STATUS_DESCRIPTION'),
                'gib_durum_kod'  => $this->altDeger($header, 'GIB_STATUS_CODE'),
                // GIB_STATUS_DESCRIPTION yalnız zarf işleme durumudur; iptal edilmiş
                // faturada bile "BAŞARIYLA TAMAMLANDI" der. İptali gösteren alanlar:
                'yanit_aciklama' => $this->altDeger($header, 'RESPONSE_DESCRIPTION'),
                'yanit_kodu'     => $this->altDeger($header, 'RESPONSE_CODE'),
                'iptal'          => self::iptalMi(
                    $this->altDeger($header, 'EXTERNAL_CANCEL_FLAG'),
                    $this->altDeger($header, 'RESPONSE_DESCRIPTION')
                ),
                'zarf_id'        => $this->altDeger($header, 'ENVELOPE_IDENTIFIER'),
                'servis_tarihi'  => self::servisTarihi($this->altDeger($header, 'CDATE')),
            ];
        }

        return ['success' => true, 'message' => '', 'data' => $faturalar];
    }

    /**
     * E-Fatura UBL XML'ini indir (GetInvoiceWithType, TYPE=XML → ZIP).
     *
     * @return array ['success' => bool, 'message' => string, 'xml' => string]
     */
    public function faturaXml(string $ettn, string $direction): array
    {
        $direction = strtoupper($direction) === 'OUT' ? 'OUT' : 'IN';

        return $this->oturumluCagri(
            fn($sessionId) => $this->faturaXmlCek($sessionId, $ettn, $direction)
        );
    }

    private function faturaXmlCek(string $sessionId, string $ettn, string $direction): array
    {
        // XSD sırası: LIMIT, ID, UUID, EXTERNAL_ID, TYPE, FROM, TO, START_DATE, END_DATE,
        //             READ_INCLUDED, DIRECTION, ...
        $icXml = $this->requestHeaderXml($sessionId)
               . '<INVOICE_SEARCH_KEY>'
               . '<UUID>' . htmlspecialchars($ettn, ENT_XML1) . '</UUID>'
               . '<TYPE>XML</TYPE>'
               . '<DIRECTION>' . $direction . '</DIRECTION>'
               . '</INVOICE_SEARCH_KEY>'
               . '<HEADER_ONLY>N</HEADER_ONLY>';

        [$dom, $hata] = $this->soapCall(
            $this->endpoint('EFatura'),
            'GetInvoiceWithTypeRequest',
            $icXml,
            'EFATURA Detay',
            ['ettn' => $ettn, 'direction' => $direction]
        );

        if ($hata !== '') {
            return ['success' => false, 'message' => $hata, 'data' => [], 'xml' => ''];
        }

        $content = $this->ilkElement($dom, 'CONTENT');
        if (!$content) {
            return ['success' => false, 'message' => 'Fatura içeriği bulunamadı.', 'data' => [], 'xml' => ''];
        }

        $xml = self::zipIcerikCoz(trim($content->textContent), '.xml');
        if ($xml === null) {
            return ['success' => false, 'message' => 'Fatura içeriği çözümlenemedi.', 'data' => [], 'xml' => ''];
        }

        return ['success' => true, 'message' => '', 'data' => [], 'xml' => $xml];
    }

    /**
     * E-Fatura görselini PDF olarak indir (GetInvoiceWithType, TYPE=PDF → ZIP).
     *
     * @return array ['success' => bool, 'message' => string, 'pdf' => string]
     */
    public function faturaPdf(string $ettn, string $direction): array
    {
        $direction = strtoupper($direction) === 'OUT' ? 'OUT' : 'IN';

        return $this->oturumluCagri(
            fn($sessionId) => $this->faturaPdfCek($sessionId, $ettn, $direction)
        );
    }

    private function faturaPdfCek(string $sessionId, string $ettn, string $direction): array
    {
        $icXml = $this->requestHeaderXml($sessionId)
               . '<INVOICE_SEARCH_KEY>'
               . '<UUID>' . htmlspecialchars($ettn, ENT_XML1) . '</UUID>'
               . '<TYPE>PDF</TYPE>'
               . '<DIRECTION>' . $direction . '</DIRECTION>'
               . '</INVOICE_SEARCH_KEY>'
               . '<HEADER_ONLY>N</HEADER_ONLY>';

        [$dom, $hata] = $this->soapCall(
            $this->endpoint('EFatura'),
            'GetInvoiceWithTypeRequest',
            $icXml,
            'EFATURA PDF',
            ['ettn' => $ettn, 'direction' => $direction]
        );

        if ($hata !== '') {
            return ['success' => false, 'message' => $hata, 'data' => [], 'pdf' => ''];
        }

        $content = $this->ilkElement($dom, 'CONTENT');
        if (!$content) {
            return ['success' => false, 'message' => 'Fatura görseli bulunamadı.', 'data' => [], 'pdf' => ''];
        }

        $pdf = self::zipIcerikCoz(trim($content->textContent), '.pdf');
        if ($pdf === null) {
            return ['success' => false, 'message' => 'Fatura görseli çözümlenemedi.', 'data' => [], 'pdf' => ''];
        }

        return ['success' => true, 'message' => '', 'data' => [], 'pdf' => $pdf];
    }

    // ================================================================
    // E-Arşiv (daima GİDEN yönlüdür)
    // ================================================================

    /**
     * E-Arşiv listesi (GetEArchiveInvoiceList).
     *
     * @param array       $filtre     [start_date, end_date, uuid, limit]
     * @param string|null $icerikTipi 'XML' / 'PDF' / 'HTML' verilirse CONTENT da döner
     */
    public function earsivListesi(array $filtre = [], ?string $icerikTipi = null): array
    {
        if (!$this->earsivVarMi()) {
            return ['success' => false, 'message' => 'Entegrasyon ayarlarında EArsiv endpoint tanımlı değil.', 'data' => []];
        }

        return $this->oturumluCagri(
            fn($sessionId) => $this->earsivListesiCek($sessionId, $filtre, $icerikTipi)
        );
    }

    /**
     * XSD eleman sırası zorunlu:
     * REQUEST_HEADER, LIMIT, ID, UUID, START_DATE, END_DATE, PERIOD, PREFIX,
     * REPORT_ID, REPORT_INCLUDED, REPORT_FLAG, HEADER_ONLY, CONTENT_TYPE, READ_INCLUDED
     *
     * START_DATE / END_DATE tipi xsd:dateTime — düz Y-m-d reddedilir.
     */
    private function earsivListesiCek(string $sessionId, array $filtre, ?string $icerikTipi): array
    {
        $icXml  = $this->requestHeaderXml($sessionId);
        $icXml .= '<LIMIT>' . (int)($filtre['limit'] ?? self::LISTE_LIMIT) . '</LIMIT>';

        if (!empty($filtre['uuid'])) {
            $icXml .= '<UUID>' . htmlspecialchars($filtre['uuid'], ENT_XML1) . '</UUID>';
        }
        if (!empty($filtre['start_date'])) {
            $icXml .= '<START_DATE>' . self::tarihSaat($filtre['start_date'], '00:00:00') . '</START_DATE>';
        }
        if (!empty($filtre['end_date'])) {
            $icXml .= '<END_DATE>' . self::tarihSaat($filtre['end_date'], '23:59:59') . '</END_DATE>';
        }

        $icXml .= '<HEADER_ONLY>' . ($icerikTipi ? 'N' : 'Y') . '</HEADER_ONLY>';
        if ($icerikTipi) {
            $icXml .= '<CONTENT_TYPE>' . $icerikTipi . '</CONTENT_TYPE>';
        }
        $icXml .= '<READ_INCLUDED>true</READ_INCLUDED>';

        [$dom, $hata] = $this->soapCall(
            $this->endpoint('EArsiv'),
            'GetEArchiveInvoiceListRequest',
            $icXml,
            $icerikTipi ? 'EARSIV Detay' : 'EARSIV/GIDEN Liste',
            array_filter([
                'start_date'   => $filtre['start_date'] ?? null,
                'end_date'     => $filtre['end_date'] ?? null,
                'uuid'         => $filtre['uuid'] ?? null,
                'content_type' => $icerikTipi,
            ]),
            self::NS_WSDL_ARCHIVE
        );

        if ($hata !== '') {
            if (strpos($hata, '[10008]') !== false) {
                return ['success' => true, 'message' => '', 'data' => []];
            }
            return ['success' => false, 'message' => $hata, 'data' => []];
        }

        $faturalar = [];
        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node->localName !== 'INVOICE') continue;

            $header = $this->altElement($node, 'HEADER');
            if (!$header) continue;

            $faturalar[] = [
                'fatura_no'      => $this->altDeger($header, 'INVOICE_ID'),
                'ettn'           => $this->altDeger($header, 'UUID'),
                'alici_vkn'      => $this->altDeger($header, 'CUSTOMER_IDENTIFIER'),
                'alici_unvan'    => $this->altDeger($header, 'CUSTOMER_NAME'),
                // ISSUE_DATE burada d-m-Y H:i:s biçiminde gelir
                'fatura_tarihi'  => self::earsivTarih($this->altDeger($header, 'ISSUE_DATE')),
                'tutar'          => $this->altDeger($header, 'PAYABLE_AMOUNT'),
                'para_birimi'    => $this->altDeger($header, 'CURRENCY_CODE'),
                'senaryo'        => $this->altDeger($header, 'PROFILE_ID'),
                'fatura_tipi'    => $this->altDeger($header, 'INVOICE_TYPE'),
                'durum'          => $this->altDeger($header, 'STATUS'),
                'gib_durum'      => $this->altDeger($header, 'STATUS_CODE'),
                // e-Arşiv'de iptal STATUS ("RAPORLANDI-IPTAL") ve PROFILE_ID ("IPTAL")
                // alanlarından okunur; EXTERNAL_CANCEL_FLAG bu serviste dönmez.
                'gib_durum_kod'  => $this->altDeger($header, 'STATUS_CODE'),
                'yanit_aciklama' => '',
                'yanit_kodu'     => '',
                'iptal'          => self::iptalMi(
                    '',
                    $this->altDeger($header, 'STATUS') . ' ' . $this->altDeger($header, 'PROFILE_ID')
                ),
                'zarf_id'        => '',
                'servis_tarihi'  => null,
                'icerik'         => $this->altDeger($node, 'CONTENT'),
            ];
        }

        return ['success' => true, 'message' => '', 'data' => $faturalar];
    }

    /**
     * E-Arşiv UBL XML'ini indir. E-Arşiv'de ayrı görsel servisi yoktur;
     * içerik CONTENT_TYPE ile liste çağrısından gelir.
     */
    public function earsivXml(string $ettn): array
    {
        $sonuc = $this->earsivListesi(['uuid' => $ettn, 'limit' => 1], 'XML');

        if (!$sonuc['success']) {
            return ['success' => false, 'message' => $sonuc['message'], 'xml' => ''];
        }

        $ham = '';
        foreach ($sonuc['data'] as $f) {
            if (($f['icerik'] ?? '') !== '') {
                $ham = $f['icerik'];
                break;
            }
        }

        if ($ham === '') {
            return ['success' => false, 'message' => 'Fatura içeriği bulunamadı.', 'xml' => ''];
        }

        $xml = self::zipIcerikCoz($ham, '.xml');
        if ($xml === null) {
            return ['success' => false, 'message' => 'Fatura içeriği çözümlenemedi.', 'xml' => ''];
        }

        return ['success' => true, 'message' => '', 'xml' => $xml];
    }

    /**
     * E-Arşiv fatura görselini PDF olarak indir.
     * İçerik, liste çağrısına CONTENT_TYPE=PDF verilerek alınır.
     *
     * @return array ['success' => bool, 'message' => string, 'pdf' => string]
     */
    public function earsivPdf(string $ettn): array
    {
        $sonuc = $this->earsivListesi(['uuid' => $ettn, 'limit' => 1], 'PDF');

        if (!$sonuc['success']) {
            return ['success' => false, 'message' => $sonuc['message'], 'pdf' => ''];
        }

        $ham = '';
        foreach ($sonuc['data'] as $f) {
            if (($f['icerik'] ?? '') !== '') {
                $ham = $f['icerik'];
                break;
            }
        }

        if ($ham === '') {
            return ['success' => false, 'message' => 'Fatura görseli bulunamadı.', 'pdf' => ''];
        }

        $pdf = self::zipIcerikCoz($ham, '.pdf');
        if ($pdf === null) {
            return ['success' => false, 'message' => 'Fatura görseli çözümlenemedi.', 'pdf' => ''];
        }

        return ['success' => true, 'message' => '', 'pdf' => $pdf];
    }

    // ================================================================
    // Yardımcılar
    // ================================================================

    /**
     * base64 (veya ham) ZIP verisinden istenen uzantılı ilk dosyayı çıkarır.
     * ZIP değilse içerik doğrudan döner.
     */
    private static function zipIcerikCoz(string $ham, string $uzanti): ?string
    {
        $veri = $ham;
        if (strncmp($veri, "PK\x03\x04", 4) !== 0) {
            $cozulmus = base64_decode($ham, true);
            if ($cozulmus === false || $cozulmus === '') {
                return null;
            }
            $veri = $cozulmus;
        }

        if (strncmp($veri, "PK\x03\x04", 4) !== 0) {
            return $veri;   // ZIP değil, içerik düz gelmiş
        }

        $tmp = self::geciciDosya('tb');
        if ($tmp === null) {
            return null;
        }

        file_put_contents($tmp, $veri);

        $icerik = null;
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $ad = (string)$zip->getNameIndex($i);
                if ($uzanti === '' || str_ends_with(strtolower($ad), $uzanti)) {
                    $icerik = $zip->getFromIndex($i);
                    break;
                }
            }
            if ($icerik === null && $zip->numFiles > 0) {
                $icerik = $zip->getFromIndex(0);
            }
            $zip->close();
        }
        @unlink($tmp);

        return ($icerik === false || $icerik === null || $icerik === '') ? null : $icerik;
    }

    /**
     * ZIP açmak için geçici dosya. sys_get_temp_dir() Plesk/Windows'ta
     * open_basedir dışında kalıp tempnam()'i false döndürebildiği için
     * önce proje içi dizin denenir.
     */
    private static function geciciDosya(string $onEk): ?string
    {
        $adaylar = [
            __DIR__ . '/../uploads/tmp',
            sys_get_temp_dir(),
            ini_get('upload_tmp_dir') ?: null,
        ];

        foreach ($adaylar as $dizin) {
            if (!$dizin) continue;
            if (!is_dir($dizin)) @mkdir($dizin, 0755, true);
            if (!is_dir($dizin) || !is_writable($dizin)) continue;

            $dosya = @tempnam($dizin, $onEk);
            if ($dosya !== false && $dosya !== '') {
                return $dosya;
            }
        }

        return null;
    }

    /** E-Arşiv ISSUE_DATE (d-m-Y H:i:s) → Y-m-d */
    private static function earsivTarih(string $deger): ?string
    {
        $deger = trim($deger);
        if ($deger === '') return null;

        foreach (['d-m-Y H:i:s', 'd-m-Y', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $dt = DateTime::createFromFormat($format, $deger);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        $zaman = strtotime($deger);
        return $zaman !== false ? date('Y-m-d', $zaman) : null;
    }

    /**
     * Fatura iptal mi? İki kaynağa birden bakılır:
     *   - EXTERNAL_CANCEL_FLAG = Y            (e-Fatura)
     *   - durum/yanıt metninde IPTAL veya RED (her iki servis)
     * GIB_STATUS_DESCRIPTION bu karara girmez; iptal faturada bile
     * "BAŞARIYLA TAMAMLANDI" döndüğü için yanıltıcıdır.
     */
    private static function iptalMi(string $bayrak, string $aciklama): bool
    {
        if (strtoupper(trim($bayrak)) === 'Y') {
            return true;
        }

        return preg_match('/\b(iptal|red)\b/iu', $aciklama) === 1;
    }

    /** CDATE (2026-06-12T14:46:48.000+03:00) → Y-m-d H:i:s */
    private static function servisTarihi(string $deger): ?string
    {
        $deger = trim($deger);
        if ($deger === '') return null;

        $zaman = strtotime($deger);
        return $zaman !== false ? date('Y-m-d H:i:s', $zaman) : null;
    }

    /** Y-m-d → xsd:dateTime (Y-m-d\TH:i:s) */
    private static function tarihSaat(string $tarih, string $varsayilanSaat): string
    {
        $zaman = strtotime($tarih);
        if ($zaman === false) {
            return date('Y-m-d') . 'T' . $varsayilanSaat;
        }

        return strlen(trim($tarih)) <= 10
            ? date('Y-m-d', $zaman) . 'T' . $varsayilanSaat
            : date('Y-m-d\TH:i:s', $zaman);
    }

    /** Ad alanı bağımsız: belgede localName ile ilk element */
    private function ilkElement(DOMDocument $dom, string $localName): ?DOMNode
    {
        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node->localName === $localName) {
                return $node;
            }
        }
        return null;
    }

    /** Doğrudan alt element (localName ile) */
    private function altElement(DOMNode $parent, string $localName): ?DOMNode
    {
        foreach ($parent->childNodes as $child) {
            if (($child->localName ?? '') === $localName) {
                return $child;
            }
        }
        return null;
    }

    private function altDeger(DOMNode $parent, string $localName): string
    {
        $el = $this->altElement($parent, $localName);
        return $el ? trim($el->textContent) : '';
    }

    private function altAttr(DOMNode $parent, string $localName, string $attr): string
    {
        $el = $this->altElement($parent, $localName);
        return ($el instanceof DOMElement) ? $el->getAttribute($attr) : '';
    }

    /**
     * EntegrasyonLoglari kaydı. Şifre ve base64 gövdeler loglanmaz.
     * CONTENT bloğu konum tabanlı kırpılır — MB boyutundaki gövdelerde
     * preg_replace PCRE yığınını taşırıp süreci sessizce sonlandırıyor.
     */
    private function logla(string $islem, array $logIstek, string $hata,
                           int $httpKod, int $sureMs, $hamYanit): void
    {
        try {
            $cevap = '';
            if ($hata === '' && is_string($hamYanit)) {
                $bas   = strpos($hamYanit, '<CONTENT>');
                $cevap = $bas === false
                    ? $hamYanit
                    : substr($hamYanit, 0, $bas) . '<CONTENT>...</CONTENT>';
                $cevap = mb_substr($cevap, 0, 4000);
            }

            $this->db->insert('EntegrasyonLoglari', [
                'EntegrasyonLoglari_EntegrasyonKanallari_id' => $this->kanalId,
                'EntegrasyonLoglari_IslemTipi'               => mb_substr($islem, 0, 100),
                'EntegrasyonLoglari_Istek'                   => json_encode(
                    $logIstek + ['http' => $httpKod, 'sure_ms' => $sureMs],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'EntegrasyonLoglari_Cevap'                   => $cevap ?: null,
                'EntegrasyonLoglari_BasariliMi'              => $hata === '' ? 1 : 0,
                'EntegrasyonLoglari_HataMesaji'              => $hata !== '' ? mb_substr($hata, 0, 1000) : null,
                'EntegrasyonLoglari_OlusturanKullanici'      => $this->userId ?? 1,
                'EntegrasyonLoglari_Kaynak'                  => 'OrnekHoldingApi',
                'EntegrasyonLoglari_Durum'                   => 1,
            ]);
        } catch (Exception $e) {
            error_log('OrnekHoldingApi log hatası: ' . $e->getMessage());
        }
    }
}
