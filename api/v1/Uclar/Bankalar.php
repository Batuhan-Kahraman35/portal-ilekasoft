<?php
// Dogrudan erisim engellenir; bu dosya yalnizca router uzerinden yuklenir.
if (!defined('ROTA_GIRIS')) { http_response_code(404); exit; }
/**
 * GET /bankalar - Istemcinin erisebildigi bankalar
 */

$db     = Database::getInstance();
$kapsam = Kimlik::kapsamJoin($istemci);

$sql = "SELECT DISTINCT b.banka_id, b.banka_adi, b.banka_logo_url
        FROM banka_Hesap bh
        INNER JOIN bankalar b ON b.banka_id = bh.bankaHesap_banka_id"
     . $kapsam['sql'] .
       " WHERE bh.bankaHesap_durum = 1
        ORDER BY b.banka_adi";

$satirlar = $db->fetchAll($sql, $kapsam['params']);

Yanit::basarili(array_map(fn($s) => [
    'id'       => (int) $s['banka_id'],
    'ad'       => $s['banka_adi'],
    'logo_url' => $s['banka_logo_url'],
], $satirlar));
