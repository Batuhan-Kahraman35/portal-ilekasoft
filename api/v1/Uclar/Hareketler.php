<?php
// Dogrudan erisim engellenir; bu dosya yalnizca router uzerinden yuklenir.
if (!defined('ROTA_GIRIS')) { http_response_code(404); exit; }
/**
 * GET /hareketler             - Hareket listesi (cursor sayfalama)
 * GET /hareketler/{id}        - Tek hareket
 * GET /hareketler/{id}/dekont - Dekont dosyasi
 */

$db          = Database::getInstance();
$kapsam      = Kimlik::kapsamJoin($istemci);
$hareketTipi = Kimlik::hareketTipiKosulu($istemci);

$hareketId = isset($parcalar[1]) && ctype_digit($parcalar[1]) ? (int) $parcalar[1] : null;
$altKaynak = $parcalar[2] ?? null;

if ($hareketId === null && isset($parcalar[1])) {
    Yanit::hata(400, 'Gecersiz hareket kimligi');
}

// ---------------------------------------------------------------
// Ortak SELECT ve JOIN
// ---------------------------------------------------------------
$secim = "SELECT
            h.banka_HesapHareketleriID,
            h.banka_HesapId,
            h.banka_HesapHareketleriIBAN,
            h.banka_HesapHareketleriName,
            CONVERT(VARCHAR(19), h.banka_HesapHareketleriDateTime, 126) AS islem_tarihi,
            CONVERT(VARCHAR(19), h.banka_HesapHareketleriAddedDateTime, 126) AS kayit_tarihi,
            h.banka_HesapHareketleriAmount,
            h.banka_HesapHareketleriDescription,
            h.banka_HesapHareketleriBorcAlacak,
            h.banka_HesapHareketleriCurrencyType,
            h.banka_HesapHareketleriRemainingBalance,
            h.banka_HesapHareketleriIdentifier,
            h.banka_HesapHareketleriCost,
            h.banka_HesapHareketleriDekontYolu,
            h.VknOrTc,
            bh.bankaHesap_no,
            bh.bankaHesap_aciklama,
            f.firma_id, f.firma_adi,
            b.banka_id, b.banka_adi";

$kaynaklar = " FROM banka_HesapHareketleri h
               INNER JOIN banka_Hesap bh ON bh.bankaHesap_id = h.banka_HesapId
               INNER JOIN Firmalar f ON f.firma_id = bh.bankaHesap_firma_id
               INNER JOIN bankalar b ON b.banka_id = bh.bankaHesap_banka_id"
             . $kapsam['sql'];

$bicimle = function (array $s): array {
    return [
        'id'           => (int) $s['banka_HesapHareketleriID'],
        'hesap_id'     => (int) $s['banka_HesapId'],
        'iban'         => $s['banka_HesapHareketleriIBAN'],
        'karsi_taraf'  => $s['banka_HesapHareketleriName'],
        'islem_tarihi' => $s['islem_tarihi'],
        'kayit_tarihi' => $s['kayit_tarihi'],
        'tutar'        => $s['banka_HesapHareketleriAmount'] !== null
                            ? (float) $s['banka_HesapHareketleriAmount'] : null,
        'tip'          => $s['banka_HesapHareketleriBorcAlacak'],
        'para_birimi'  => $s['banka_HesapHareketleriCurrencyType'],
        'aciklama'     => $s['banka_HesapHareketleriDescription'],
        'kalan_bakiye' => $s['banka_HesapHareketleriRemainingBalance'] !== null
                            ? (float) $s['banka_HesapHareketleriRemainingBalance'] : null,
        'referans_no'  => $s['banka_HesapHareketleriIdentifier'],
        'vkn_tckn'     => $s['VknOrTc'] !== null && trim((string) $s['VknOrTc']) !== ''
                            ? trim((string) $s['VknOrTc']) : null,
        'masraf_mi'    => (bool) $s['banka_HesapHareketleriCost'],
        'dekont_var'   => !empty($s['banka_HesapHareketleriDekontYolu']),
        'hesap'        => [
            'no'       => $s['bankaHesap_no'],
            'aciklama' => $s['bankaHesap_aciklama'],
        ],
        'firma'        => ['id' => (int) $s['firma_id'], 'ad' => $s['firma_adi']],
        'banka'        => ['id' => (int) $s['banka_id'], 'ad' => $s['banka_adi']],
    ];
};

// ---------------------------------------------------------------
// Tekil hareket / dekont
// ---------------------------------------------------------------
if ($hareketId !== null) {
    $params   = $kapsam['params'];
    $params[] = $hareketId;
    $params   = array_merge($params, $hareketTipi['params']);

    $satir = $db->fetchOne(
        $secim . $kaynaklar . " WHERE h.banka_HesapHareketleriID = ?" . $hareketTipi['sql'],
        $params
    );

    if (!$satir) {
        Yanit::hata(404, 'Hareket bulunamadi veya erisim yetkiniz yok');
    }

    if ($altKaynak === null) {
        Yanit::tekil($bicimle($satir));
    }

    if ($altKaynak !== 'dekont') {
        Yanit::hata(404, 'Bilinmeyen alt kaynak', 'Kullanilabilir: /dekont');
    }

    if (!$istemci['apiIstemci_dekont_erisim']) {
        Yanit::hata(403, 'Dekont erisim yetkiniz yok');
    }

    $yol = $satir['banka_HesapHareketleriDekontYolu'];
    if (empty($yol)) {
        Yanit::hata(404, 'Bu harekete ait dekont bulunmuyor');
    }

    // Dizin gecisini engelle: dosya proje kokunun altinda kalmali
    $kok = realpath(__DIR__ . '/../../..');
    $tam = realpath($kok . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $yol), DIRECTORY_SEPARATOR));

    if ($tam === false || strncmp($tam, $kok, strlen($kok)) !== 0 || !is_file($tam)) {
        Yanit::hata(404, 'Dekont dosyasi sunucuda bulunamadi');
    }

    ApiLog::yaz(200, 1, null);

    $uzanti = strtolower(pathinfo($tam, PATHINFO_EXTENSION));
    $tipler = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    header('Content-Type: ' . ($tipler[$uzanti] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($tam));
    header('Content-Disposition: attachment; filename="dekont-' . $hareketId . '.' . $uzanti . '"');
    header('Cache-Control: no-store');
    readfile($tam);
    exit;
}

// ---------------------------------------------------------------
// Liste
// ---------------------------------------------------------------
// Yetki kisiti istemci filtresinden once uygulanir; istemci kendi kapsamini genisletemez.
$params = array_merge($kapsam['params'], $hareketTipi['params']);
$kosul  = " WHERE 1 = 1" . $hareketTipi['sql'];

$tamsayiFiltre = [
    'firma_id' => 'bh.bankaHesap_firma_id',
    'banka_id' => 'bh.bankaHesap_banka_id',
    'hesap_id' => 'h.banka_HesapId',
];

foreach ($tamsayiFiltre as $anahtar => $kolon) {
    if (isset($_GET[$anahtar]) && ctype_digit((string) $_GET[$anahtar])) {
        $kosul   .= " AND {$kolon} = ?";
        $params[] = (int) $_GET[$anahtar];
    }
}

if (!empty($_GET['iban'])) {
    $kosul   .= " AND h.banka_HesapHareketleriIBAN = ?";
    $params[] = trim((string) $_GET['iban']);
}

if (!empty($_GET['baslangic_tarih'])) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $_GET['baslangic_tarih']);
    if (!$d) {
        Yanit::hata(400, 'baslangic_tarih formati gecersiz', 'Beklenen: YYYY-MM-DD');
    }
    $kosul   .= " AND h.banka_HesapHareketleriDateTime >= ?";
    $params[] = $d->format('Y-m-d') . ' 00:00:00';
}

if (!empty($_GET['bitis_tarih'])) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $_GET['bitis_tarih']);
    if (!$d) {
        Yanit::hata(400, 'bitis_tarih formati gecersiz', 'Beklenen: YYYY-MM-DD');
    }
    $kosul   .= " AND h.banka_HesapHareketleriDateTime < DATEADD(DAY, 1, ?)";
    $params[] = $d->format('Y-m-d') . ' 00:00:00';
}

if (!empty($_GET['tip'])) {
    $tip = strtoupper(trim((string) $_GET['tip']));
    if (!in_array($tip, ['A', 'B'], true)) {
        Yanit::hata(400, 'tip degeri gecersiz', 'Beklenen: A (alacak) veya B (borc)');
    }
    $kosul   .= " AND h.banka_HesapHareketleriBorcAlacak = ?";
    $params[] = $tip;
}

if (!empty($_GET['para_birimi'])) {
    $kosul   .= " AND h.banka_HesapHareketleriCurrencyType = ?";
    $params[] = strtoupper(trim((string) $_GET['para_birimi']));
}

foreach (['min_tutar' => '>=', 'max_tutar' => '<='] as $anahtar => $operator) {
    if (isset($_GET[$anahtar]) && is_numeric($_GET[$anahtar])) {
        $kosul   .= " AND CAST(h.banka_HesapHareketleriAmount AS DECIMAL(18,2)) {$operator} ?";
        $params[] = (float) $_GET[$anahtar];
    }
}

if (!empty($_GET['arama'])) {
    $terim  = '%' . trim((string) $_GET['arama']) . '%';
    $kosul .= " AND (h.banka_HesapHareketleriDescription LIKE ?
                  OR h.banka_HesapHareketleriName LIKE ?
                  OR h.banka_HesapHareketleriIdentifier LIKE ?)";
    array_push($params, $terim, $terim, $terim);
}

// Cursor: artimli cekimde sonraki_id (ASC), geriye gezinmede onceki_id (DESC)
$artimli = isset($_GET['sonraki_id']) && ctype_digit((string) $_GET['sonraki_id']);

if ($artimli) {
    $kosul   .= " AND h.banka_HesapHareketleriID > ?";
    $params[] = (int) $_GET['sonraki_id'];
    $yon = 'ASC';
} else {
    if (isset($_GET['onceki_id']) && ctype_digit((string) $_GET['onceki_id'])) {
        $kosul   .= " AND h.banka_HesapHareketleriID < ?";
        $params[] = (int) $_GET['onceki_id'];
    }
    $yon = 'DESC';
}

$limit = isset($_GET['limit']) && ctype_digit((string) $_GET['limit'])
    ? min(500, max(1, (int) $_GET['limit']))
    : 100;

$sql = $secim . $kaynaklar . $kosul
     . " ORDER BY h.banka_HesapHareketleriID {$yon}
         OFFSET 0 ROWS FETCH NEXT " . ($limit + 1) . " ROWS ONLY";

$satirlar = $db->fetchAll($sql, $params);

$devamVar = count($satirlar) > $limit;
if ($devamVar) {
    array_pop($satirlar);
}

$veri  = array_map($bicimle, $satirlar);
$sonId = $veri ? end($veri)['id'] : null;

Yanit::basarili($veri, [
    'meta' => [
        'limit'          => $limit,
        'donen_kayit'    => count($veri),
        'devam_var'      => $devamVar,
        'siralama'       => $yon === 'ASC' ? 'id_artan' : 'id_azalan',
        'sonraki_cursor' => $devamVar && $sonId !== null
            ? ($artimli ? ['sonraki_id' => $sonId] : ['onceki_id' => $sonId])
            : null,
    ],
]);
