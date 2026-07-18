<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Database;
use MacRadar\Core\Request;
use MacRadar\Core\Response;

class AuthController
{
    public function register(Request $req): void
    {
        $email = strtolower(trim((string) $req->input('email')));
        $password = (string) $req->input('password');
        $name = trim((string) $req->input('name', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('invalid_email', 'Geçerli bir e-posta girin.', 422);
        }
        if (strlen($password) < 6) {
            Response::error('weak_password', 'Şifre en az 6 karakter olmalı.', 422);
        }
        $exists = Database::fetch('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists) {
            Response::error('email_taken', 'Bu e-posta zaten kayıtlı.', 409);
        }
        $userId = Database::insert(
            'INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)',
            [$email, password_hash($password, PASSWORD_DEFAULT), $name ?: null]
        );
        $tokens = Auth::issueTokens($userId);
        Response::ok([
            'user' => $this->publicUser(Database::fetch('SELECT * FROM users WHERE id = ?', [$userId])),
            'tokens' => $tokens,
        ], 'Kayıt başarılı.');
    }

    public function login(Request $req): void
    {
        $email = strtolower(trim((string) $req->input('email')));
        $password = (string) $req->input('password');

        $user = Database::fetch('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('invalid_credentials', 'E-posta veya şifre hatalı.', 401);
        }
        if ((int) $user['is_banned'] === 1) {
            Response::error('banned', 'Hesabınız askıya alındı.', 403);
        }
        Response::ok([
            'user' => $this->publicUser($user),
            'tokens' => Auth::issueTokens((int) $user['id']),
        ], 'Giriş başarılı.');
    }

    public function refresh(Request $req): void
    {
        $refresh = (string) $req->input('refresh_token');
        $user = Auth::userFromRefresh($refresh);
        if (!$user) {
            Response::error('invalid_token', 'Geçersiz veya süresi dolmuş oturum.', 401);
        }
        Response::ok(['tokens' => Auth::issueTokens((int) $user['id'])]);
    }

    public function me(Request $req): void
    {
        $user = Auth::require($req);
        Response::ok(['user' => $this->publicUser($user)]);
    }

    public function updateFcm(Request $req): void
    {
        $user = Auth::require($req);
        $token = trim((string) $req->input('fcm_token'));
        Database::execute('UPDATE users SET fcm_token = ? WHERE id = ?', [$token ?: null, $user['id']]);
        Response::ok(null, 'Bildirim token güncellendi.');
    }

    private function publicUser(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'plan' => $u['plan'],
            'premium_until' => $u['premium_until'],
            'daily_analysis_count' => (int) $u['daily_analysis_count'],
            'created_at' => $u['created_at'],
        ];
    }
}
