<?php
/**
 * Koçtaş Pazaryeri API (Mirakl altyapısı)
 *
 * Tüm iş mantığı MiraklApi'dedir; bu sınıf yalnız pazaryeri kodunu taşır.
 * Sunucu: Entegrasyonlar.AyarJSON → BaseURL (https://koctas.mirakl.net/api)
 *
 * Koçtaş'a özgü sipariş ek alanları (order_additional_fields):
 *   tax-number / tax-office / invoice-title / is-corporate → fatura bilgisi
 *   shipment-status / shipment-contract-type / total-deci   → kargo
 */

require_once __DIR__ . '/MiraklApi.php';

class KoctasApi extends MiraklApi
{
    public function __construct($userId = null, $kanalId = null)
    {
        parent::__construct('koctas', $userId, $kanalId);
    }
}
