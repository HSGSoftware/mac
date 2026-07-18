<?php

namespace MacRadar\Core;

/**
 * Gelen HTTP isteğini sarmalar.
 */
class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $headers;
    /** Rota parametreleri (ör. {id}) */
    public array $params = [];
    /** Auth ile doldurulan aktif kullanıcı */
    public ?array $user = null;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->query = $_GET;
        $this->headers = self::parseHeaders();

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // /api/v1 önekini kaldır (rewrite'a göre)
        $uri = preg_replace('#^.*/api/v1#', '', $uri);
        $this->path = '/' . trim($uri, '/');

        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        $this->body = is_array($decoded) ? $decoded : $_POST;
    }

    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers['Authorization'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
