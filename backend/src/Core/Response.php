<?php

namespace MacRadar\Core;

/**
 * JSON yanıt yardımcıları.
 */
class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null, string $message = null): void
    {
        $payload = ['success' => true];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }
        self::json($payload, 200);
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge([
            'success' => false,
            'error' => $code,
            'message' => $message,
        ], $extra), $status);
    }

    /** CORS başlıklarını uygula */
    public static function applyCors(): void
    {
        $origins = Config::get('app.cors_origins', ['*']);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array('*', $origins, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin && in_array($origin, $origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
