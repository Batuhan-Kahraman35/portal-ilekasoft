<?php
// Dogrudan erisim engellenir; bu dosya yalnizca router uzerinden yuklenir.
if (!defined('ROTA_GIRIS')) { http_response_code(404); exit; }
/**
 * GET /firmalar - Istemcinin erisebildigi firmalar
 */

$db     = Database::getInstance();
$kapsam = Kimlik::kapsamJoin($istemci);

$sql = "SELECT DISTINCT f.firma_id, f.firma_adi
        FROM banka_Hesap bh
        INNER JOIN Firmalar f ON f.firma_id = bh.bankaHesap_firma_id"
     . $kapsam['sql'] .
       " WHERE bh.bankaHesap_durum = 1
        ORDER BY f.firma_adi";

$satirlar = $db->fetchAll($sql, $kapsam['params']);

Yanit::basarili(array_map(fn($s) => [
    'id'  => (int) $s['firma_id'],
    'ad'  => $s['firma_adi'],
], $satirlar));
