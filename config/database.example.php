<?php
/**
 * Veritabanı Bağlantı Ayarları - ÖRNEK
 * Portal Örnek Soft
 * 
 * KULLANIM: Bu dosyayı database.php olarak kopyalayıp kendi bilgilerinizi girin
 */

return [
    'default' => 'sqlsrv',
    
    'connections' => [
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => 'localhost\SQLEXPRESS',
            'port' => '',
            'database' => 'veritabani_adi',
            'username' => 'kullanici_adi',
            'password' => 'sifre_buraya',
            'charset' => 'utf8',
            'prefix' => '',
            'options' => [
                'encrypt' => false,
                'trust_server_certificate' => true,
            ]
        ]
    ],
    
    // Sunucu Bilgileri
    'server_info' => [
        'url' => 'portal.example.com',
        'ip' => '0.0.0.0',
        'environment' => 'production'
    ]
];
