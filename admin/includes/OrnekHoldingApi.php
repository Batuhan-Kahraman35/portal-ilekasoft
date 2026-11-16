<?php
/**
 * Örnek Holding API — Portal Örnek Soft açılış dosyası
 *
 * Sınıf ornekholding-modul'dedir (admin/includes/ornekholding/src/OrnekHoldingApi.php);
 * bu dosya modülü yükler ve portalın adaptörünü (PortalOrnekHoldingDepo) kaydeder.
 * Projede require_once __DIR__ . '/OrnekHoldingApi.php' yapan her yer değişmeden çalışır.
 *
 * Modül: D:\Inetpub\moduller\ornekholding-modul  (rehber: ornekholding/docs/ornekholding.md)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/ornekholding/src/OrnekHoldingApi.php';
require_once __DIR__ . '/PortalOrnekHoldingDepo.php';

OrnekHoldingApi::depoAyarla(new PortalOrnekHoldingDepo(Database::getInstance()));
