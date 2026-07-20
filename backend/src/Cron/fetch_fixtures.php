<?php
/**
 * Cron: Günün ve gelecek 3 günün maç programını + oranları çeker.
 * cPanel cron örneği (30 dakikada bir):
 *   [dakika-alani: yildiz-bolu-30] * * * * /usr/local/bin/php /home/KULLANICI/backend/src/Cron/fetch_fixtures.php
 */

require_once dirname(__DIR__) . '/autoload.php';

use MacRadar\Core\Config;
use MacRadar\Services\MackolikScraper;
use MacRadar\Services\ScrapeLogger;
use MacRadar\Services\TeamLogoService;

Config::load();
date_default_timezone_set(Config::get('app.timezone', 'Europe/Istanbul'));

$start = microtime(true);
$total = 0;
$errors = [];
$scraper = new MackolikScraper();

for ($i = 0; $i <= 3; $i++) {
    $date = date('Y-m-d', strtotime("+{$i} day"));
    try {
        $res = $scraper->fetchFixtures($date);
        $total += $res['count'];
        echo "[$date] {$res['count']} maç ({$res['source']})\n";
    } catch (\Throwable $e) {
        $errors[] = "$date: " . $e->getMessage();
        echo "[$date] HATA: " . $e->getMessage() . "\n";
    }
}

$duration = (int) round((microtime(true) - $start) * 1000);
$status = $errors ? ($total > 0 ? 'partial' : 'error') : 'success';
ScrapeLogger::log('fetch_fixtures', $status, $errors ? implode(' | ', $errors) : "OK", $total, $duration);
echo "Toplam: $total maç, süre {$duration}ms\n";

// Eksik takım amblemlerini tamamla (ayrı cron gerekmesin diye burada).
// Her çalışmada sınırlı sayıda takım denenir; bulunamayanlar işaretlenip
// team_logo_retry_days sonra tekrar denenir.
try {
    $logos = (new TeamLogoService())->fillMissing(40);
    echo "Amblem: {$logos['checked']} denendi, {$logos['found']} bulundu, {$logos['missing']} eksik kaldı\n";
} catch (\Throwable $e) {
    echo 'Amblem hatası: ' . $e->getMessage() . "\n";
}
