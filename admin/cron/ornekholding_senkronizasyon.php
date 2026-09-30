<?php
set_time_limit(0);
ini_set('memory_limit', '256M');

/**
 * Örnek Holding Fatura Senkronizasyon Servisi
 * 
 * Tüm aktif ÖRNEK HOLDİNG kanallarından E-Fatura ve E-Arşiv faturalarını çekip DB'ye kaydeder.
 * Plesk Cron ile çalıştırılır.
 * 
 * Kullanım:
 *   php ornekholding_senkronizasyon.php [--gun=30] [--tur=EFATURA|EARSIV] [--yon=GELEN|GIDEN]
 */

require_once __DIR__ . '/../../admin/db.php';

/**
 * Teşhis: cron tarafından yakalanamayan fatal hataları loglamak için.
 */
(function () {
    $logFile = dirname(__DIR__, 1) . '/logs/ornekholding_senkronizasyon_cron_fatal.log';
    if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0755, true);

    $fingerprint = sprintf(
        "PHP_VERSION=%s | PHP_SAPI=%s | extensions: zip=%s mbstring=%s | str_ends_with=%s | date_default_timezone=%s\n",
        PHP_VERSION,
        PHP_SAPI,
        extension_loaded('zip') ? '1' : '0',
        extension_loaded('mbstring') ? '1' : '0',
        function_exists('str_ends_with') ? '1' : '0',
        (string)ini_get('date.timezone')
    );

    $write = function (string $line) use ($logFile, $fingerprint) {
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] " . $line . "\n" . $fingerprint, FILE_APPEND);
    };

    set_exception_handler(function ($e) use ($write) {
        $write("UNCAUGHT_EXCEPTION: " . get_class($e) . " : " . $e->getMessage());
        $write("STACK: " . $e->getTraceAsString());
    });

    set_error_handler(function ($severity, $message, $file, $line) use ($write) {
        $write("PHP_ERROR: severity={$severity} message={$message} at {$file}:{$line}");
        // Fatal olmayanlar için PHP'nin normal akışını bozma
        return false;
    });

    register_shutdown_function(function () use ($write) {
        $err = error_get_last();
        if ($err) {
            $write("SHUTDOWN_ERROR: " . ($err['type'] ?? 'unknown') . " : " . ($err['message'] ?? '') . " at " . ($err['file'] ?? '') . ":" . ($err['line'] ?? ''));
        }
    });
})();

class OrnekHoldingService
{
    private $db;
    private int $gun;
    private ?string $filtreTur;
    private ?string $filtreYon;

    // SOAP Namespace
    const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const NS_INV = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public function __construct(int $gun = 30, ?string $filtreTur = null, ?string $filtreYon = null)
    {
        $this->db        = Database::getInstance();
        $this->gun       = $gun;
        $this->filtreTur = $filtreTur;
        $this->filtreYon = $filtreYon;
    }

    public function calistir(): void
    {
        $this->log("=== Örnek Holding Senkronizasyon Başlıyor ===");
        $this->log("Tarih Aralığı: Son {$this->gun} gün");

        $kanallar = $this->db->fetchAll("
            SELECT k.*, e.Entegrasyonlar_AyarJSON
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyonlar_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Ad = 'ÖRNEK HOLDİNG'
            AND k.EntegrasyonKanallari_Durum = 1
        ");

        if (empty($kanallar)) {
            $this->log("Aktif ÖRNEK HOLDİNG kanalı bulunamadı.");
            return;
        }

        foreach ($kanallar as $kanal) {
            $this->log("\n--- Kanal: " . $kanal['EntegrasyonKanallari_Ad'] . " ---");
            $this->kanalSenkronize($kanal);
        }

        $this->log("\n=== Senkronizasyon Tamamlandı ===");
    }

    private function kanalSenkronize(array $kanal): void
    {
        $ayarlar = json_decode($kanal['Entegrasyonlar_AyarJSON'], true);
        $authWsdl = $ayarlar['KimlikDogrulama'];

        // Login
        $loginData = $this->login($authWsdl, $kanal);
        if (!$loginData) {
            return;
        }
        $sessionId        = $loginData['SESSION_ID'];
        $webValidationKey = $loginData['WEB_VALIDATION_KEY'];

        $turler = ['EFATURA', 'EARSIV'];
        $yonler = ['GELEN', 'GIDEN'];

        if ($this->filtreTur) $turler = [$this->filtreTur];
        if ($this->filtreYon) $yonler = [$this->filtreYon];

        foreach ($turler as $tur) {
            $wsdl = $this->getWsdl($ayarlar, $tur);
            if (!$wsdl) continue;

            foreach ($yonler as $yon) {
                $this->log("  > $tur / $yon çekiliyor...");
                $this->faturalariCek($kanal, $sessionId, $webValidationKey, $wsdl, $tur, $yon);
            }
        }

        // Logout
        $this->logout($authWsdl, $sessionId);
    }

    private function login(string $authWsdl, array $kanal): ?array
    {
        try {
            $client = new SoapClient($authWsdl, ['trace' => 1, 'exceptions' => 1]);

            $header = new stdClass();
            $header->SESSION_ID = '?';
            $req = new stdClass();
            $req->REQUEST_HEADER = $header;
            $req->USER_NAME = $kanal['EntegrasyonKanallari_KullaniciAdi'];
            $req->PASSWORD  = $kanal['EntegrasyonKanallari_Sifre'];

            $res = $client->Login($req);

            if (isset($res->SESSION_ID)) {
                $this->log("  [+] Login ba\u015far\u0131l\u0131: " . $kanal['EntegrasyonKanallari_Ad']);
                $this->logKaydet($kanal['EntegrasyonKanallari_id'], 'Login', null, 'Ba\u015far\u0131l\u0131', true);
                return [
                    'SESSION_ID'         => $res->SESSION_ID,
                    'WEB_VALIDATION_KEY' => $res->WEB_VALIDATION_KEY ?? null,
                ];
            }

            $hata = $res->ERROR_TYPE->ERROR_SHORT_DES ?? 'Bilinmeyen hata';
            $this->log("  [-] Login ba\u015far\u0131s\u0131z: $hata");
            $this->logKaydet($kanal['EntegrasyonKanallari_id'], 'Login', null, $hata, false, $hata);
            return null;

        } catch (Exception $e) {
            $this->log("  [-] Login HATA: " . $e->getMessage());
            $this->logKaydet($kanal['EntegrasyonKanallari_id'], 'Login', null, null, false, $e->getMessage());
            return null;
        }
    }

    private function logout(string $authWsdl, string $sessionId): void
    {
        try {
            $client = new SoapClient($authWsdl, ['exceptions' => 1]);
            $header = new stdClass();
            $header->SESSION_ID = $sessionId;
            $req = new stdClass();
            $req->REQUEST_HEADER = $header;
            $client->Logout($req);
        } catch (Exception $e) {
            // Logout hatası kritik değil
        }
    }

    private function getWsdl(array $ayarlar, string $tur): ?string
    {
        if ($tur === 'EFATURA') {
            $wsdl = $ayarlar['EFatura'] ?? null;
            // Test → Canlı dönüşümü
            return $wsdl ? str_replace('efaturawstest', 'efaturaws', $wsdl) : null;
        } elseif ($tur === 'EARSIV') {
            $wsdl = $ayarlar['EArsiv'] ?? null;
            return $wsdl ? str_replace('earsivwstest', 'earsivws', $wsdl) : null;
        }
        return null;
    }

    private function faturalariCek(array $kanal, string $sessionId, ?string $webValidationKey, string $wsdl, string $tur, string $yon): void
    {
        $kanalId = $kanal['EntegrasyonKanallari_id'];
        $toplamKaydedilen = 0;
        $toplamAtlanan    = 0;
        $limit = 100;

        try {
            $client = new SoapClient($wsdl, ['trace' => 1, 'exceptions' => 1]);

            $header = new stdClass();
            $header->SESSION_ID = $sessionId;

            $searchKey = new stdClass();
            $searchKey->LIMIT         = $limit;
            $searchKey->DIRECTION     = ($yon === 'GELEN') ? 'IN' : 'OUT';
            $searchKey->READ_INCLUDED = true;
            $searchKey->START_DATE    = date('Y-m-d', strtotime("-{$this->gun} days"));
            $searchKey->END_DATE      = date('Y-m-d');

            $req = new stdClass();
            $req->REQUEST_HEADER     = $header;
            $req->INVOICE_SEARCH_KEY = $searchKey;
            $req->HEADER_ONLY        = 'N';

            // E-Fatura ve E-Arşiv farklı metodlar kullanır
            if ($tur === 'EARSIV') {
                // E-Arşiv: GetEArchiveInvoice metodu
                $earsivReq = new stdClass();
                $earsivReq->REQUEST_HEADER     = $header;
                $earsivReq->WEB_VALIDATION_KEY = $webValidationKey ?? $ayarlar['EArsivWebKey'] ?? '';
                $earsivSearchKey = new stdClass();
                $earsivSearchKey->LIMIT         = $limit;
                $earsivSearchKey->DIRECTION     = ($yon === 'GELEN') ? 'IN' : 'OUT';
                $earsivSearchKey->READ_INCLUDED = true;
                $earsivSearchKey->START_DATE    = date('Y-m-d', strtotime("-{$this->gun} days"));
                $earsivSearchKey->END_DATE      = date('Y-m-d');
                $earsivReq->INVOICE_SEARCH_KEY  = $earsivSearchKey;
                $earsivReq->HEADER_ONLY         = 'N';
                $res = $client->GetEArchiveInvoice($earsivReq);
                if (!isset($res->INVOICE) && isset($res->ARCHIVE_INVOICE)) {
                    $res->INVOICE = $res->ARCHIVE_INVOICE;
                }
            } else {
                $res = $client->GetInvoice($req);
            }

            if (!isset($res->INVOICE)) {
                $this->log("    Fatura bulunamadı.");
                return;
            }

            $faturalar = $res->INVOICE;
            if (!is_array($faturalar)) $faturalar = [$faturalar];

            foreach ($faturalar as $inv) {
                $sonuc = $this->faturaKaydet($inv, $kanal, $tur, $yon);
                if ($sonuc === 'kaydedildi') $toplamKaydedilen++;
                elseif ($sonuc === 'atlandı') $toplamAtlanan++;
            }

            $mesaj = "Toplam: " . count($faturalar) . " | Kaydedilen: $toplamKaydedilen | Güncellenen/Atlanan: $toplamAtlanan";
            $this->log("    $mesaj");
            $this->logKaydet($kanalId, "$tur/$yon Çekme", null, $mesaj, true);

        } catch (Exception $e) {
            $hata = $e->getMessage();
            $this->log("    [HATA] $hata");
            $this->logKaydet($kanalId, "$tur/$yon Çekme", null, null, false, $hata);
        }
    }

    private function faturaKaydet(object $inv, array $kanal, string $tur, string $yon): string
    {
        try {
            // ZIP açıp XML parse et
            $rawContent = $inv->CONTENT ?? null;
            if (!$rawContent) return 'atlandı';

            // CLI ortamında SOAP binary base64 encode gelebilir, her ikisini dene
            $rawBytes = isset($rawContent->_) ? $rawContent->_ : (string)$rawContent;
            $zipData  = $this->zipVeriCoz($rawBytes);

            if (!$zipData) {
                $this->log("    [UYARI] ZIP verisi çözülemedi.");
                return 'atlandı';
            }

            // Proje temp/ dizini kullan (Plesk CLI'de C:\Windows\Temp yazılamaz)
            $tmpDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'temp';
            if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
            $tmpZip = $tmpDir . DIRECTORY_SEPARATOR . 'tb_' . uniqid() . '.zip';
            $yazildi = file_put_contents($tmpZip, $zipData);
            if ($yazildi === false) {
                $this->log("    [UYARI] Temp dosyası yazılamadı: $tmpZip");
                return 'atlandı';
            }

            $xml = null;
            $zip = new ZipArchive();
            if ($zip->open($tmpZip) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($filename), '.xml')) {
                        $xmlStr = $zip->getFromIndex($i);
                        $xmlLen = strlen($xmlStr ?? '');

                        // BOM temizle (UTF-8/UTF-16 LE/BE)
                        if (substr($xmlStr, 0, 3) === "\xEF\xBB\xBF") {
                            $xmlStr = substr($xmlStr, 3);
                        } elseif (substr($xmlStr, 0, 2) === "\xFF\xFE") {
                            $xmlStr = mb_convert_encoding($xmlStr, 'UTF-8', 'UTF-16LE');
                        } elseif (substr($xmlStr, 0, 2) === "\xFE\xFF") {
                            $xmlStr = mb_convert_encoding($xmlStr, 'UTF-8', 'UTF-16BE');
                        }

                        // Parse'ı boğan büyük blokları strpos/substr ile temizle
                        // 1) Digital imza bloğu (en büyük: sertifika + XSLT içeriyor)
                        $sigStart = strpos($xmlStr, '<ds:Signature');
                        $sigEnd   = strpos($xmlStr, '</ds:Signature>');
                        if ($sigStart !== false && $sigEnd !== false) {
                            $xmlStr = substr($xmlStr, 0, $sigStart)
                                    . substr($xmlStr, $sigEnd + strlen('</ds:Signature>'));
                        }
                        // 2) Gömülü binary objeler (XSLT template, logo JPEG vb.)
                        foreach (['cbc:EmbeddedDocumentBinaryObject', 'ext:ExtensionContent'] as $tag) {
                            $open  = "<$tag";
                            $close = "</$tag>";
                            while (($s = strpos($xmlStr, $open)) !== false) {
                                $e = strpos($xmlStr, $close, $s);
                                if ($e === false) break;
                                $xmlStr = substr($xmlStr, 0, $s) . substr($xmlStr, $e + strlen($close));
                            }
                        }
                        // 3) cbc:Note içindeki Variable_Picture base64 JPEG'leri temizle
                        $noteOpen  = '<cbc:Note>';
                        $noteClose = '</cbc:Note>';
                        $offset = 0;
                        while (($ns = strpos($xmlStr, $noteOpen, $offset)) !== false) {
                            $ne = strpos($xmlStr, $noteClose, $ns);
                            if ($ne === false) break;
                            $noteContent = substr($xmlStr, $ns + strlen($noteOpen), $ne - $ns - strlen($noteOpen));
                            if (strpos($noteContent, 'Variable_Picture:') !== false || strlen($noteContent) > 5000) {
                                // Bu Note'u boş bırak
                                $xmlStr = substr($xmlStr, 0, $ns) . $noteOpen . $noteClose
                                        . substr($xmlStr, $ne + strlen($noteClose));
                                // offset'i güncelle, aynı pozisyondan devam et
                                $offset = $ns + strlen($noteOpen) + strlen($noteClose);
                            } else {
                                $offset = $ne + strlen($noteClose);
                            }
                        }

                        // DOMDocument ile parse et
                        libxml_use_internal_errors(true);
                        $dom = new DOMDocument();
                        $dom->recover = true;
                        $parseOk = $dom->loadXML($xmlStr, LIBXML_PARSEHUGE | LIBXML_NOCDATA);

                        if (!$parseOk) {
                            $xmlErrors = libxml_get_errors();
                            $xmlHatalar = array_map(fn($e) => 'L' . $e->line . ': ' . trim($e->message), $xmlErrors);
                            libxml_clear_errors();
                            $this->log("    [XML HATA] " . implode(' | ', array_slice($xmlHatalar, 0, 3)));
                        }
                        libxml_clear_errors();

                        // simplexml_import_dom default namespace ile çalışmıyor → DOMXPath kullan
                        if ($parseOk && $dom->documentElement) {
                            $xpath = new DOMXPath($dom);
                            $xpath->registerNamespace('cbc', self::NS_CBC);
                            $xpath->registerNamespace('cac', self::NS_CAC);
                            $xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
                        } else {
                            $xpath = false;
                        }
                        break;
                    }
                }
                $zip->close();
            }
            unlink($tmpZip);

            if (!$xpath) {
                $this->log("    [UYARI] XML parse başarısız.");
                return 'atlandı';
            }

            // XPath yardımcı fonksiyonu
            $xval = fn($q, $ctx = null) => ($ctx
                ? $xpath->evaluate('string(' . $q . ')', $ctx)
                : $xpath->evaluate('string(/inv:Invoice/' . $q . ')'));
            $xnodes = fn($q, $ctx = null) => iterator_to_array(
                $ctx ? $xpath->query($q, $ctx) : $xpath->query('/inv:Invoice/' . $q)
            );

            // Ana Fatura Alanları
            $faturaNo   = $xval('cbc:ID');
            $ettn       = $xval('cbc:UUID');
            $tarih      = $xval('cbc:IssueDate');
            $tipi       = $xval('cbc:InvoiceTypeCode');
            $senaryo    = $xval('cbc:ProfileID');
            $paraBirimi = $xval('cbc:DocumentCurrencyCode') ?: 'TRY';

            // Notlar (birden fazla gelebilir)
            $notlar = [];
            foreach ($xnodes('cbc:Note') as $noteNode) {
                $n = trim($noteNode->textContent);
                if (!empty($n)) $notlar[] = $n;
            }
            $notlarStr = implode("\n", $notlar);

            // Kur Bilgisi (Notlardan regex ile çek)
            $dolarKur = $this->kurCek($notlarStr, ['USD', 'Dolar', '\$']);
            $euroKur  = $this->kurCek($notlarStr, ['EUR', 'Euro', '€']);

            // Karşı Taraf (VKN için PartyIdentification/@schemeID=VKN ara)
            if ($yon === 'GELEN') {
                $cariUnvan = $xval('cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name');
                $cariVkn   = $xval('cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification[cbc:ID[@schemeID="VKN"]]/cbc:ID');
                $cariVergi = $xval('cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cac:TaxScheme/cbc:Name');
            } else {
                $cariUnvan = $xval('cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name');
                $cariVkn   = $xval('cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification[cbc:ID[@schemeID="VKN"]]/cbc:ID');
                $cariVergi = $xval('cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cac:TaxScheme/cbc:Name');
            }

            // Tutarlar
            $malHizmet = (float)$xval('cac:LegalMonetaryTotal/cbc:LineExtensionAmount');
            $indirim   = (float)$xval('cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount');
            $vergi     = (float)$xval('cac:TaxTotal/cbc:TaxAmount');
            $odenecek  = (float)$xval('cac:LegalMonetaryTotal/cbc:PayableAmount');

            // GİB Durumu (ör: ONAYLANDI, REDDEDILDI)
            $gibDurum   = (string)($inv->STATUS ?? null);

            // UPSERT: ETTN + KanalID unique
            $mevcut = $this->db->fetchOne(
                "SELECT Faturalar_id FROM Faturalar WHERE Faturalar_ETTN = ? AND Faturalar_EntegrasyonKanallari_id = ?",
                [$ettn, $kanal['EntegrasyonKanallari_id']]
            );

            $data = [
                'Faturalar_EntegrasyonKanallari_id' => $kanal['EntegrasyonKanallari_id'],
                'Faturalar_Yon'                     => $yon,
                'Faturalar_Tur'                     => $tur,
                'Faturalar_Senaryo'                 => $senaryo ?: null,
                'Faturalar_FaturaNo'                => $faturaNo,
                'Faturalar_ETTN'                    => $ettn ?: null,
                'Faturalar_FaturaTipi'              => $tipi ?: null,
                'Faturalar_Tarih'                   => $tarih ?: null,
                'Faturalar_Notlar'                  => $notlarStr ?: null,
                'Faturalar_CariUnvan'               => $cariUnvan ?: null,
                'Faturalar_CariVKN'                 => $cariVkn ?: null,
                'Faturalar_CariVergiDairesi'        => $cariVergi ?: null,
                'Faturalar_MalHizmetTutari'         => $malHizmet,
                'Faturalar_IndirimTutari'           => $indirim,
                'Faturalar_VergiTutari'             => $vergi,
                'Faturalar_OdenecekTutar'           => $odenecek,
                'Faturalar_ParaBirimi'              => $paraBirimi,
                'Faturalar_DolarKur'                => $dolarKur,
                'Faturalar_EuroKur'                 => $euroKur,
                'Faturalar_GibDurum'                => $gibDurum ?: null,
                'Faturalar_Durum'                   => 1,
            ];


            if ($mevcut) {
                // Güncelle (GIB Durumu değişmiş olabilir)
                $data['Faturalar_GuncelleyenKullanici'] = 1;
                $data['Faturalar_GuncellemeTarihi']     = date('Y-m-d H:i:s');
                $this->db->update('Faturalar', $data, ['Faturalar_id' => $mevcut['Faturalar_id']]);
                $faturaId = $mevcut['Faturalar_id'];
            } else {
                // Yeni kayıt
                $data['Faturalar_OlusturanKullanici'] = 1;
                $data['Faturalar_OlusturmaTarihi']    = date('Y-m-d H:i:s');
                $this->db->insert('Faturalar', $data);
                $faturaId = $this->db->getLastInsertId();

                // Kalemleri kaydet (sadece yeni faturada)
                $this->kalemleriKaydet($xpath, $faturaId);
            }

            return $mevcut ? 'atlandı' : 'kaydedildi';

        } catch (Exception $e) {
            $this->log("    [FATURA HATA] " . $e->getMessage());
            return 'hata';
        }
    }

    private function kalemleriKaydet(DOMXPath $xpath, int $faturaId): void
    {
        $lines = $xpath->query('/inv:Invoice/cac:InvoiceLine');
        foreach ($lines as $line) {
            $lv = fn($q) => $xpath->evaluate('string(' . $q . ')', $line);

            $siraNo     = (int)$lv('cbc:ID');
            $urunAdi    = $lv('cac:Item/cbc:Name');
            $aciklama   = $lv('cac:Item/cbc:Description') ?: null;
            $miktar     = (float)$lv('cbc:InvoicedQuantity');
            $birimNodes = $xpath->query('cbc:InvoicedQuantity', $line);
            $birim      = $birimNodes->length ? $birimNodes->item(0)->getAttribute('unitCode') : null;
            $birimFiyat = (float)$lv('cac:Price/cbc:PriceAmount');
            $kdvOrani   = (float)$lv('cac:TaxTotal/cac:TaxSubtotal/cbc:Percent');
            $kdvTutari  = (float)$lv('cac:TaxTotal/cbc:TaxAmount');
            $toplam     = (float)$lv('cbc:LineExtensionAmount');

            $this->db->insert('FaturaKalemleri', [
                'FaturaKalemleri_Faturalar_id'  => $faturaId,
                'FaturaKalemleri_SiraNo'        => $siraNo,
                'FaturaKalemleri_UrunAdi'       => $urunAdi,
                'FaturaKalemleri_Aciklama'      => $aciklama,
                'FaturaKalemleri_Miktar'        => $miktar,
                'FaturaKalemleri_Birim'         => $birim ?: null,
                'FaturaKalemleri_BirimFiyati'   => $birimFiyat,
                'FaturaKalemleri_KdvOrani'      => $kdvOrani,
                'FaturaKalemleri_KdvTutari'     => $kdvTutari,
                'FaturaKalemleri_ToplamTutar'   => $toplam,
                'FaturaKalemleri_OlusturanKullanici' => 1,
                'FaturaKalemleri_OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                'FaturaKalemleri_GuncelleyenKullanici' => 1,
                'FaturaKalemleri_GuncellemeTarihi'   => date('Y-m-d H:i:s'),
                'FaturaKalemleri_Durum'              => 1,
            ]);
        }
    }

    /**
     * ZIP verisini çöz: önce raw binary dene, başarısız olursa base64 decode dene
     */
    private function zipVeriCoz(string $rawBytes): ?string
    {
        // PK magic byte kontrolü: ZIP dosyası PK\x03\x04 ile başlar
        if (substr($rawBytes, 0, 2) === 'PK') {
            return $rawBytes; // zaten binary ZIP
        }

        // Base64 decode dene
        $decoded = base64_decode($rawBytes, true);
        if ($decoded !== false && substr($decoded, 0, 2) === 'PK') {
            return $decoded;
        }

        return null;
    }

    private function kurCek(string $metin, array $kisaltmalar): ?float
    {
        foreach ($kisaltmalar as $kisaltma) {
            // Örn: "1 USD = 38.45" veya "USD: 38,45" veya "38.45 USD"
            if (preg_match('/[\d]+[.,][\d]+\s*' . $kisaltma . '|' . $kisaltma . '\s*[=:]\s*([\d]+[.,][\d]+)|([\d]+[.,][\d]+)\s*' . $kisaltma . '/ui', $metin, $m)) {
                $deger = end($m);
                if ($deger) {
                    return (float)str_replace(',', '.', $deger);
                }
            }
        }
        return null;
    }

    private function logKaydet(int $kanalId, string $islemTipi, ?string $istek, ?string $cevap, bool $basarili, ?string $hata = null): void
    {
        try {
            $this->db->insert('EntegrasyonLoglari', [
                'EntegrasyonLoglari_EntegrasyonKanallari_id' => $kanalId,
                'EntegrasyonLoglari_IslemTipi'   => $islemTipi,
                'EntegrasyonLoglari_Istek'        => $istek,
                'EntegrasyonLoglari_Cevap'        => $cevap,
                'EntegrasyonLoglari_BasariliMi'   => $basarili ? 1 : 0,
                'EntegrasyonLoglari_HataMesaji'   => $hata,
                'EntegrasyonLoglari_OlusturanKullanici' => 1,
                'EntegrasyonLoglari_Durum'        => 1,
            ]);
        } catch (Exception $e) {
            // Log yazma hatası kritik değil
        }
    }

    private function log(string $mesaj): void
    {
        echo $mesaj . "\n";
    }
}

// ===== CLI Parametreleri =====
$gun      = 30;
$filtreTur = null;
$filtreYon = null;

foreach ($argv ?? [] as $arg) {
    if (preg_match('/--gun=(\d+)/', $arg, $m))       $gun = (int)$m[1];
    if (preg_match('/--tur=(EFATURA|EARSIV)/', $arg, $m)) $filtreTur = $m[1];
    if (preg_match('/--yon=(GELEN|GIDEN)/', $arg, $m))    $filtreYon = $m[1];
}

$servis = new OrnekHoldingService($gun, $filtreTur, $filtreYon);
$servis->calistir();
