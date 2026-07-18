<?php

namespace MacRadar\Core;

/**
 * Uygulama kullanıcısı kimlik doğrulama yardımcıları (JWT tabanlı).
 */
class Auth
{
    public static function issueTokens(int $userId): array
    {
        $secret = Config::get('jwt.secret');
        $issuer = Config::get('jwt.issuer', 'macradar');
        return [
            'access_token' => Jwt::encode(['sub' => $userId, 'type' => 'access'], $secret, (int) Config::get('jwt.access_ttl', 3600), $issuer),
            'refresh_token' => Jwt::encode(['sub' => $userId, 'type' => 'refresh'], $secret, (int) Config::get('jwt.refresh_ttl', 2592000), $issuer),
            'token_type' => 'Bearer',
            'expires_in' => (int) Config::get('jwt.access_ttl', 3600),
        ];
    }

    /**
     * İsteğin token'ını doğrular; kullanıcıyı $request->user'a koyar.
     * Başarısızsa 401 döndürerek çıkar.
     */
    public static function require(Request $request): array
    {
        $user = self::optional($request);
        if ($user === null) {
            Response::error('unauthorized', 'Giriş yapmalısınız.', 401);
        }
        return $user;
    }

    /**
     * Token varsa kullanıcıyı döndür, yoksa null (hata vermez).
     */
    public static function optional(Request $request): ?array
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }
        $payload = Jwt::decode($token, Config::get('jwt.secret'));
        if (!$payload || ($payload['type'] ?? '') !== 'access') {
            return null;
        }
        $user = Database::fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$payload['sub']]);
        if (!$user || (int) $user['is_banned'] === 1) {
            return null;
        }
        $request->user = $user;
        return $user;
    }

    public static function userFromRefresh(string $refreshToken): ?array
    {
        $payload = Jwt::decode($refreshToken, Config::get('jwt.secret'));
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            return null;
        }
        return Database::fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$payload['sub']]);
    }
}
