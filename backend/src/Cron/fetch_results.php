<?php
/**
 * Cron: Biten maç sonuçlarını çeker ve AI tahmin isabetini işaretler.
 * cPanel cron örneği (15 dakikada bir):
 *   [dakika-alani: yildiz-bolu-15] * * * * /usr/local/bin/php /home/KULLANICI/backend/src/Cron/fetch_results.php
 */

require_once dirname(__DIR__) . '/autoload.php';

use MacRadar\Core\Config;
use MacRadar\Core\Database;
use MacRadar\Services\MackolikScraper;
use MacRadar\Services\ScrapeLogger;
use MacRadar\Services\ResultEvaluator;

Config::load();
date_default_timezone_set(Config::get('app.timezone', 'Europe/Istanbul'));

$start = microtime(true);
$scraper = new MackolikScraper();
$total = 0;
$errors = [];

foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $date) {
    try {
        $res = $scraper->fetchResults($date);
        $total += $res['count'];
    } catch (\Throwable $e) {
        $errors[] = "$date: " . $e->getMessage();
    }
}

// Biten ve henüz değerlendirilmemiş analizlerin isabetini işaretle
$evaluated = ResultEvaluator::evaluatePending();

$duration = (int) round((microtime(true) - $start) * 1000);
$status = $errors ? 'partial' : 'success';
ScrapeLogger::log('fetch_results', $status, $errors ? implode(' | ', $errors) : "OK (isabet: $evaluated)", $total, $duration);
echo "Güncellenen sonuç: $total, değerlendirilen tahmin: $evaluated\n";
