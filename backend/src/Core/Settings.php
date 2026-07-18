<?php

namespace MacRadar\Core;

/**
 * settings tablosuna okuma/yazma için yardımcı (istek başı önbellekli).
 */
class Settings
{
    private static ?array $cache = null;

    private static function loadAll(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::fetchAll('SELECT skey, svalue FROM settings') as $row) {
                self::$cache[$row['skey']] = $row['svalue'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::loadAll();
        $val = $all[$key] ?? null;
        return ($val === null || $val === '') ? $default : $val;
    }

    public static function set(string $key, $value): void
    {
        Database::execute(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [$key, (string) $value]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = (string) $value;
        }
    }

    public static function all(): array
    {
        return self::loadAll();
    }
}
