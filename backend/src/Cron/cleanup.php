<?php
/**
 * Cron: Eski log ve pasif oran kayıtlarını temizler.
 * cPanel cron örneği (günlük 04:00):
 *   0 4 * * * /usr/local/bin/php /home/KULLANICI/backend/src/Cron/cleanup.php
 */

require_once dirname(__DIR__) . '/autoload.php';

use MacRadar\Core\Config;
use MacRadar\Core\Database;

Config::load();

// 30 günden eski scraper logları
$logs = Database::execute('DELETE FROM scrape_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
// 7 günden eski pasif oran geçmişi
$odds = Database::execute('DELETE FROM odds WHERE is_latest = 0 AND fetched_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');

echo "Temizlendi: $logs log, $odds pasif oran kaydı\n";
