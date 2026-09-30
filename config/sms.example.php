<?php
/**
 * SMS Konfigürasyonu - ÖRNEK
 * Portal Örnek Soft
 * 
 * KULLANIM: Bu dosyayı sms.php olarak kopyalayıp kendi bilgilerinizi girin
 */

return [
    'default' => 'mobilisim',
    
    'providers' => [
        'mobilisim' => [
            'api_url' => 'http://api3.mobilisim.com/',
            'username' => 'kullanici_adi',
            'password' => 'sifre',
            'sender' => 'GONDERICI', // Originator
            'action' => '12', // API Action kodu
            'encoding' => 'UTF-8',
            'last_test' => '',
            'test_result' => '',
            'test_code' => ''
        ],
        
        'netgsm' => [
            'api_url' => 'https://api.netgsm.com.tr/sms/send/get',
            'username' => '', // NetGSM kullanıcı adı
            'password' => '', // NetGSM şifre
            'sender' => '', // Gönderen adı (maksimum 11 karakter)
            'encoding' => 'TR'
        ],
        
        'iletimerkezi' => [
            'api_url' => 'https://api.iletimerkezi.com/v1/send-sms',
            'username' => '', // İletim Merkezi kullanıcı adı
            'password' => '', // İletim Merkezi şifre
            'sender' => '', // Gönderen adı
            'encoding' => 'UTF-8'
        ],
        
        'twilio' => [
            'account_sid' => '', // Twilio Account SID
            'auth_token' => '', // Twilio Auth Token
            'from' => '', // Twilio telefon numarası
            'api_url' => 'https://api.twilio.com/2010-04-01/Accounts'
        ]
    ],
    
    // SMS gönderim ayarları
    'settings' => [
        'max_length' => 160, // Maksimum SMS uzunluğu
        'allow_unicode' => true, // Türkçe karakter desteği
        'delivery_report' => true, // Teslimat raporu
        'save_logs' => true // SMS loglarını kaydet
    ],
    
    // Test telefon numaraları
    'test_numbers' => [
        '+905001234567'
    ]
];
