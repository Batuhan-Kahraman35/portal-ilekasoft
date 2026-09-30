<?php
// Dogrudan erisim engellenir; bu dosya yalnizca router uzerinden yuklenir.
if (!defined('ROTA_GIRIS')) { http_response_code(404); exit; }
/**
 * GET /hesaplar - Banka hesaplari ve guncel bakiyeleri
 *
 * Filtreler: firma_id, banka_id
 */

$db     = Database::getInstance();
$kapsam = Kimlik::kapsamJoin($istemci);

$sql = "SELECT
            bh.bankaHesap_id,
            bh.bankaHesap_no,
            bh.bankaHesap_iban,
            bh.bankaHesap_aciklama,
            bh.bankaHesap_sube_adi,
            bh.bankaHesap_bakiye,
            bh.bankaHesap_kullanilabilirBakiye,
            bh.bankaHesap_bloke,
            CONVERT(VARCHAR(19), bh.bankaHesap_sonSenkronTarihi, 126) AS son_senkron,
            f.firma_id, f.firma_adi,
            b.banka_id, b.banka_adi
        FROM banka_Hesap bh
        INNER JOIN Firmalar f ON f.firma_id = bh.bankaHesap_firma_id
        INNER JOIN bankalar b ON b.banka_id = bh.bankaHesap_banka_id"
     . $kapsam['sql'] .
       " WHERE bh.bankaHesap_durum = 1";

$params = $kapsam['params'];

if (isset($_GET['firma_id']) && ctype_digit((string) $_GET['firma_id'])) {
    $sql .= " AND bh.bankaHesap_firma_id = ?";
    $params[] = (int) $_GET['firma_id'];
}

if (isset($_GET['banka_id']) && ctype_digit((string) $_GET['banka_id'])) {
    $sql .= " AND bh.bankaHesap_banka_id = ?";
    $params[] = (int) $_GET['banka_id'];
}

$sql .= " ORDER BY f.firma_adi, b.banka_adi, bh.bankaHesap_no";

$satirlar = $db->fetchAll($sql, $params);

Yanit::basarili(array_map(fn($s) => [
    'id'                   => (int) $s['bankaHesap_id'],
    'hesap_no'             => $s['bankaHesap_no'],
    'iban'                 => $s['bankaHesap_iban'],
    'aciklama'             => $s['bankaHesap_aciklama'],
    'sube_adi'             => $s['bankaHesap_sube_adi'],
    'bakiye'               => $s['bankaHesap_bakiye'] !== null ? (float) $s['bankaHesap_bakiye'] : null,
    'kullanilabilir_bakiye'=> $s['bankaHesap_kullanilabilirBakiye'] !== null ? (float) $s['bankaHesap_kullanilabilirBakiye'] : null,
    'bloke'                => $s['bankaHesap_bloke'] !== null ? (float) $s['bankaHesap_bloke'] : null,
    'son_senkron'          => $s['son_senkron'],
    'firma'                => ['id' => (int) $s['firma_id'], 'ad' => $s['firma_adi']],
    'banka'                => ['id' => (int) $s['banka_id'], 'ad' => $s['banka_adi']],
], $satirlar));
