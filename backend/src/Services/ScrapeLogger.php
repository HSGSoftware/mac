<?php

namespace MacRadar\Services;

use MacRadar\Core\Database;

class ScrapeLogger
{
    public static function log(string $job, string $status, ?string $message, int $items = 0, ?int $durationMs = null): void
    {
        Database::insert(
            'INSERT INTO scrape_logs (job, status, message, items_count, duration_ms) VALUES (?, ?, ?, ?, ?)',
            [$job, $status, $message ? mb_substr($message, 0, 2000) : null, $items, $durationMs]
        );
    }
}
