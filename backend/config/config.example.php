<?php
/**
 * MaçRadar Backend Yapılandırması — ÖRNEK
 * ---------------------------------------
 * Bu dosyayı "config.php" olarak kopyalayın ve sunucu bilgilerinizle doldurun.
 * config.php ASLA repoya gönderilmez (.gitignore).
 */

return [
    // ---- Veritabanı (cPanel MySQL) ----
    'db' => [
        'host'    => 'localhost',
        'name'    => 'CPANELKULLANICI_macradar',
        'user'    => 'CPANELKULLANICI_macuser',
        'pass'    => 'GUCLU_BIR_SIFRE',
        'charset' => 'utf8mb4',
    ],

    // ---- JWT (uygulama token'ları) ----
    'jwt' => [
        // Rastgele uzun bir dize üretin, ör: bin2hex(random_bytes(32))
        'secret'         => 'BURAYA_RASTGELE_UZUN_BIR_ANAHTAR',
        'access_ttl'     => 3600,        // saniye (1 saat)
        'refresh_ttl'    => 60 * 60 * 24 * 30, // 30 gün
        'issuer'         => 'macradar',
    ],

    // ---- Genel ----
    'app' => [
        'debug'    => false,             // canlıda false
        'timezone' => 'Europe/Istanbul',
        // Flutter uygulamasının erişeceği izinli origin'ler (CORS). '*' geliştirme için.
        'cors_origins' => ['*'],
    ],
];
