<?php
/**
 * Garanti BBVA API Store - OAuth Callback URL
 *
 * Portal uygulama tanımında Callback URL zorunlu ve token isteğindeki redirect_uri ile aynı olmalı.
 * client_credentials akışında banka bu adresi çağırmaz; yalnız erişilebilir olması yeterli.
 * Hiçbir parametre işlenmez, kayıt tutulmaz.
 *
 * @author ÖRNEK Soft
 * @version 1.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(200);
echo json_encode(['durum' => 'ok']);
