<?php
/**
 * Veritabanı güncelleme (migration) sayfası.
 * phpMyAdmin'e girmeden, tarayıcıdan açarak bekleyen SQL güncellemelerini uygular.
 *
 * Kullanım:
 *   1) database/ altındaki migration_*.sql dosyaları sunucuda olsun.
 *   2) Tarayıcıdan  /migrate.php  adresini açın.
 *   3) "Güncellemeleri uygula" butonuna basın (veya  /migrate.php?run=1 ).
 *
 * Güvenli: tüm migration'lar "CREATE TABLE IF NOT EXISTS" gibi tekrar
 * çalıştırılabilir ifadelerdir; sayfayı birden çok kez açmak zarar vermez.
 * Yine de işiniz bitince bu dosyayı silmeniz önerilir.
 */

$autoloadDir = null;
(function () use (&$autoloadDir) {
    $dir = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        if (is_file($dir . '/src/autoload.php')) {
            require_once $dir . '/src/autoload.php';
            $GLOBALS['__base_dir'] = $dir;
            return;
        }
        $dir = dirname($dir);
    }
    http_response_code(500);
    exit('autoload.php bulunamadı.');
})();

use MacRadar\Core\Config;
use MacRadar\Core\Database;

Config::load();
header('Content-Type: text/html; charset=utf-8');

$baseDir = $GLOBALS['__base_dir'] ?? dirname(__DIR__);
$dbDir = $baseDir . '/database';

echo '<html><head><meta charset="utf-8"><title>Maç Analiz — Güncelleme</title>'
   . '<meta name="viewport" content="width=device-width, initial-scale=1">'
   . '<style>body{font-family:system-ui,sans-serif;background:#080c12;color:#e9ebee;max-width:720px;margin:32px auto;padding:20px}'
   . 'h1{font-size:20px}.ok{color:#7cdf81}.err{color:#ff5251}.muted{color:#7a8189}'
   . 'code{background:#161c22;padding:2px 6px;border-radius:5px}'
   . '.card{background:#13181e;border:1px solid #20262d;border-radius:12px;padding:14px 16px;margin:10px 0}'
   . 'a.btn,button{display:inline-block;background:linear-gradient(135deg,#7cdf81,#4fb874);color:#0a1410;'
   . 'font-weight:800;padding:12px 20px;border:none;border-radius:10px;text-decoration:none;cursor:pointer;font-size:15px}'
   . '</style></head><body>';
echo '<h1>📊 Maç Analiz — Veritabanı Güncelleme</h1>';

// Bekleyen migration dosyaları
$files = [];
if (is_dir($dbDir)) {
    foreach (glob($dbDir . '/migration_*.sql') ?: [] as $f) {
        $files[] = $f;
    }
    sort($files);
}

if (!$files) {
    echo '<p class="err">database/ klasöründe migration_*.sql dosyası bulunamadı.</p>';
    echo '<p class="muted">Aranan konum: <code>' . htmlspecialchars($dbDir) . '</code></p>';
    echo '</body></html>';
    exit;
}

$run = isset($_GET['run']) && $_GET['run'] === '1';

if (!$run) {
    echo '<div class="card"><p>Uygulanacak güncelleme dosyaları:</p><ul>';
    foreach ($files as $f) {
        echo '<li><code>' . htmlspecialchars(basename($f)) . '</code></li>';
    }
    echo '</ul></div>';
    echo '<p><a class="btn" href="?run=1">Güncellemeleri uygula →</a></p>';
    echo '<p class="muted">Bu işlem güvenlidir ve tekrar çalıştırılabilir.</p>';
    echo '</body></html>';
    exit;
}

// Uygula
$pdo = Database::pdo();
$okCount = 0;
foreach ($files as $f) {
    $name = htmlspecialchars(basename($f));
    try {
        $sql = file_get_contents($f);
        if ($sql === false || trim($sql) === '') {
            echo '<div class="card"><span class="muted">— ' . $name . ' (boş, atlandı)</span></div>';
            continue;
        }
        $pdo->exec($sql);
        echo '<div class="card"><span class="ok">✓ ' . $name . ' uygulandı.</span></div>';
        $okCount++;
    } catch (\Throwable $e) {
        echo '<div class="card"><span class="err">✗ ' . $name . ' — hata: '
            . htmlspecialchars($e->getMessage()) . '</span></div>';
    }
}

// Doğrulama: yeni tablo var mı?
try {
    $exists = Database::fetch("SHOW TABLES LIKE 'user_analysis_history'");
    echo '<hr>';
    if ($exists) {
        echo '<p class="ok"><strong>✓ Güncelleme tamam.</strong> '
            . '“Analizlerim” tablosu (<code>user_analysis_history</code>) hazır.</p>';
    } else {
        echo '<p class="err">Tablo bulunamadı; yukarıdaki hataları kontrol edin.</p>';
    }
} catch (\Throwable $e) {
    echo '<p class="err">Doğrulama hatası: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<p class="muted">Toplam ' . $okCount . '/' . count($files) . ' dosya uygulandı. '
    . 'İşiniz bittiyse bu <code>migrate.php</code> dosyasını sunucudan silebilirsiniz.</p>';
echo '</body></html>';
