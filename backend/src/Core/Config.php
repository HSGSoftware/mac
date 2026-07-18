<?php

namespace MacRadar\Core;

/**
 * Yapılandırma yükleyici. config/config.php dosyasını okur.
 */
class Config
{
    private static ?array $data = null;

    public static function load(): void
    {
        if (self::$data !== null) {
            return;
        }
        $path = dirname(__DIR__, 2) . '/config/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'config_missing',
                'message' => 'config/config.php bulunamadı. config.example.php dosyasını kopyalayın.',
            ]);
            exit;
        }
        self::$data = require $path;
    }

    /**
     * Nokta gösterimiyle değer al: Config::get('db.host')
     */
    public static function get(string $key, $default = null)
    {
        self::load();
        $segments = explode('.', $key);
        $value = self::$data;
        foreach ($segments as $seg) {
            if (is_array($value) && array_key_exists($seg, $value)) {
                $value = $value[$seg];
            } else {
                return $default;
            }
        }
        return $value;
    }
}
