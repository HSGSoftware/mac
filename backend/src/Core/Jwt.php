<?php

namespace MacRadar\Core;

/**
 * Bağımlılıksız (harici kütüphanesiz) HS256 JWT üretimi/doğrulaması.
 */
class Jwt
{
    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload, string $secret, int $ttl, string $issuer): string
    {
        $now = time();
        $payload = array_merge($payload, [
            'iss' => $issuer,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            self::b64url(json_encode($header)),
            self::b64url(json_encode($payload)),
        ];
        $signing = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing, $secret, true);
        $segments[] = self::b64url($signature);
        return implode('.', $segments);
    }

    /**
     * Token'ı doğrula ve payload'ı döndür. Geçersizse null.
     */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $signing = $h . '.' . $p;
        $expected = self::b64url(hash_hmac('sha256', $signing, $secret, true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $payload = json_decode(self::b64urlDecode($p), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && time() >= $payload['exp']) {
            return null;
        }
        return $payload;
    }
}
