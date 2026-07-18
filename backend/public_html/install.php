<?php
/**
 * Tek seferlik kurulum: veritabanı şemasını yükler.
 * Kullanım: config.php'yi hazırladıktan sonra tarayıcıdan /install.php adresini açın.
 * GÜVENLİK: Kurulum bitince BU DOSYAYI SUNUCUDAN SİLİN.
 */

require_once dirname(__DIR__) . '/src/autoload.php';

use MacRadar\Core\Config;
use MacRadar\Core\Database;

Config::load();
header('Content-Type: text/html; charset=utf-8');

echo '<html><head><meta charset="utf-8"><title>MaçRadar Kurulum</title>'
   . '<style>body{font-family:sans-serif;background:#0d1b2a;color:#e2e8f0;max-width:700px;margin:40px auto;padding:20px}'
   . '.ok{color:#00e676}.err{color:#ef5350}code{background:#1b263b;padding:2px 6px;border-radius:4px}</style></head><body>';
echo '<h1>📊 MaçRadar Kurulum</h1>';

$schemaPath = dirname(__DIR__) . '/database/schema.sql';
if (!is_file($schemaPath)) {
    echo '<p class="err">schema.sql bulunamadı: ' . htmlspecialchars($schemaPath) . '</p></body></html>';
    exit;
}

try {
    $pdo = Database::pdo();
    $sql = file_get_contents($schemaPath);
    // Basit çoklu-ifade çalıştırma
    $pdo->exec($sql);
    echo '<p class="ok">✓ Veritabanı şeması başarıyla yüklendi.</p>';

    $tables = Database::fetchAll('SHOW TABLES');
    echo '<p>Oluşturulan tablolar: <strong>' . count($tables) . '</strong></p>';
    echo '<hr><p class="ok"><strong>Kurulum tamamlandı!</strong></p>';
    echo '<ul>';
    echo '<li>Admin paneli: <code>/admin/</code> — kullanıcı <code>admin</code>, şifre <code>admin123</code> (hemen değiştirin!)</li>';
    echo '<li>API sağlık kontrolü: <code>/api/v1/health</code></li>';
    echo '<li>AI ayarlarını admin panelden girin ve "Test Et" ile doğrulayın.</li>';
    echo '<li>Cron job\'larını cPanel\'de tanımlayın (fetch_fixtures, fetch_results, cleanup).</li>';
    echo '</ul>';
    echo '<p class="err"><strong>⚠ GÜVENLİK: Bu install.php dosyasını şimdi sunucudan silin.</strong></p>';
} catch (\Throwable $e) {
    echo '<p class="err">✗ Hata: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>config.php içindeki veritabanı bilgilerini kontrol edin.</p>';
}
echo '</body></html>';
