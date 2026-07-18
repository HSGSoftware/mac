<?php

namespace MacRadar\Core;

/**
 * Günlük TOKEN sistemi.
 *
 * Her paketin günlük token hakkı vardır; token hakkı her gün sıfırlanır,
 * kullanılmayan tokenlar ERTESİ GÜNE DEVRETMEZ.
 *
 * Token harcanan içerikler:
 *   - Market grubu açma (maç başına, grup başına bir kez)
 *   - AI maç analizi (maç başına bir kez; canlı maçta daha yüksek maliyet)
 *
 * Açılan içerik user_unlocks tablosuna yazılır; tekrar görüntüleme ücretsizdir.
 */
class Tokens
{
    public const GROUP_KEYS = ['ana', 'gol', 'handikap', 'ozel'];

    public const GROUP_NAMES = [
        'ana' => 'Ana Marketler',
        'gol' => 'Gol Marketleri',
        'handikap' => 'Handikap & Kombine',
        'ozel' => 'Özel Marketler',
    ];

    private const ALLOWANCE_DEFAULTS = [0 => 10, 1 => 100, 2 => 250, 3 => 600];
    private const ALLOWANCE_KEYS = [
        0 => 'free_daily_tokens',
        1 => 'bronz_daily_tokens',
        2 => 'gumus_daily_tokens',
        3 => 'altin_daily_tokens',
    ];
    private const GROUP_COST_DEFAULTS = ['ana' => 10, 'gol' => 15, 'handikap' => 20, 'ozel' => 25];

    /** Kademeye göre günlük token hakkı. */
    public static function dailyAllowance(int $tier): int
    {
        $key = self::ALLOWANCE_KEYS[$tier] ?? self::ALLOWANCE_KEYS[0];
        $def = self::ALLOWANCE_DEFAULTS[$tier] ?? self::ALLOWANCE_DEFAULTS[0];
        return max(0, (int) Settings::get($key, $def));
    }

    /** Bir market grubunu açmanın token maliyeti. */
    public static function groupCost(string $groupKey): int
    {
        $def = self::GROUP_COST_DEFAULTS[$groupKey] ?? 25;
        return max(0, (int) Settings::get('token_cost_group_' . $groupKey, $def));
    }

    /** AI analizinin token maliyeti (canlı maçta daha yüksek). */
    public static function analysisCost(bool $live = false): int
    {
        return $live
            ? max(0, (int) Settings::get('token_cost_live_analysis', 40))
            : max(0, (int) Settings::get('token_cost_analysis', 25));
    }

    /** İstemciye gönderilen maliyet tablosu. */
    public static function costs(): array
    {
        $groups = [];
        foreach (self::GROUP_KEYS as $k) {
            $groups[$k] = self::groupCost($k);
        }
        return [
            'groups' => $groups,
            'analysis' => self::analysisCost(false),
            'live_analysis' => self::analysisCost(true),
        ];
    }

    /** Bugün harcanan token (tarih değiştiyse 0 — günlük sıfırlama). */
    public static function usedToday(array $user): int
    {
        if (($user['tokens_date'] ?? null) !== date('Y-m-d')) {
            return 0;
        }
        return (int) ($user['tokens_used'] ?? 0);
    }

    /** Bugün kalan token hakkı. */
    public static function remaining(?array $user): int
    {
        if (!$user) {
            return 0;
        }
        return max(0, self::dailyAllowance(Plans::tierOf($user)) - self::usedToday($user));
    }

    /**
     * Token düşer. Yetersizse false döner (harcama yapılmaz).
     * Tarih değiştiyse sayaç bugünden başlar — dünden kalan devretmez.
     */
    public static function spend(array $user, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }
        if (self::remaining($user) < $amount) {
            return false;
        }
        $today = date('Y-m-d');
        if (($user['tokens_date'] ?? null) !== $today) {
            Database::execute(
                'UPDATE users SET tokens_used = ?, tokens_date = ? WHERE id = ?',
                [$amount, $today, $user['id']]
            );
        } else {
            Database::execute(
                'UPDATE users SET tokens_used = tokens_used + ? WHERE id = ?',
                [$amount, $user['id']]
            );
        }
        return true;
    }

    /** İçerik daha önce token harcanarak açılmış mı? */
    public static function isUnlocked(int $userId, int $matchId, string $type, string $key = ''): bool
    {
        try {
            $row = Database::fetch(
                'SELECT id FROM user_unlocks WHERE user_id = ? AND match_id = ? AND item_type = ? AND item_key = ?',
                [$userId, $matchId, $type, $key]
            );
            return (bool) $row;
        } catch (\Throwable $e) {
            return false; // tablo henüz yoksa (migration uygulanmadıysa) akışı bozma
        }
    }

    /** Kullanıcının bu maçta açtığı market grubu anahtarları. */
    public static function unlockedGroups(int $userId, int $matchId): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT item_key FROM user_unlocks WHERE user_id = ? AND match_id = ? AND item_type = 'market_group'",
                [$userId, $matchId]
            );
            return array_map(fn($r) => (string) $r['item_key'], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Açılan içeriği kaydet (tekrar kayıt zararsız). */
    public static function recordUnlock(int $userId, int $matchId, string $type, string $key, int $spent): void
    {
        try {
            Database::execute(
                'INSERT IGNORE INTO user_unlocks (user_id, match_id, item_type, item_key, tokens_spent) VALUES (?, ?, ?, ?, ?)',
                [$userId, $matchId, $type, $key, $spent]
            );
        } catch (\Throwable $e) {
            // tablo yoksa sessiz geç
        }
    }

    /**
     * Market adına göre grup anahtarı.
     * Flutter tarafındaki marketGroupKeyFor() ile AYNI mantık — değişirse
     * iki taraf birlikte güncellenmeli.
     */
    public static function groupKeyForMarketName(string $name): string
    {
        $n = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (str_contains($n, 'handikap') || str_contains($n, 'maç sonucu ve') || str_contains($n, 'y/ms')) {
            return 'handikap';
        }
        if (str_contains($n, 'gol')) {
            return 'gol';
        }
        if (str_contains($n, 'maç sonucu') || str_contains($n, 'çifte şans') || str_contains($n, 'yarı sonucu')) {
            return 'ana';
        }
        return 'ozel';
    }
}
