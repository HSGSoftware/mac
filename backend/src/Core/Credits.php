<?php

namespace MacRadar\Core;

/**
 * Günlük KREDİ sistemi.
 *
 * Her paketin günlük kredi hakkı vardır; kredi hakkı her gün sıfırlanır,
 * kullanılmayan krediler ERTESİ GÜNE DEVRETMEZ.
 *
 * Her market analizi AYRI kredi tüketir (canlı maçta daha yüksek maliyet,
 * yalnızca Altın paket). Aynı maçın aynı marketini tekrar görüntülemek
 * ücretsizdir (user_unlocks); canlıda tazelik süresi dolunca yeni analiz
 * yeniden kredi ister.
 *
 * Oran gruplarının hangi pakete açık olduğu da buradan okunur
 * (group_min_tier_* ayarları — admin panelinden değiştirilebilir).
 */
class Credits
{
    public const GROUP_KEYS = ['ana', 'gol', 'handikap', 'ozel'];

    public const GROUP_NAMES = [
        'ana' => 'Ana Marketler',
        'gol' => 'Gol Marketleri',
        'handikap' => 'Handikap & Kombine',
        'ozel' => 'Özel Marketler',
    ];

    private const ALLOWANCE_DEFAULTS = [0 => 1, 1 => 20, 2 => 50, 3 => 120];
    private const ALLOWANCE_KEYS = [
        0 => 'free_daily_credits',
        1 => 'bronz_daily_credits',
        2 => 'gumus_daily_credits',
        3 => 'altin_daily_credits',
    ];
    private const GROUP_TIER_DEFAULTS = ['ana' => 0, 'gol' => 1, 'handikap' => 2, 'ozel' => 3];

    /** Kademeye göre günlük kredi hakkı. */
    public static function dailyAllowance(int $tier): int
    {
        $key = self::ALLOWANCE_KEYS[$tier] ?? self::ALLOWANCE_KEYS[0];
        $def = self::ALLOWANCE_DEFAULTS[$tier] ?? self::ALLOWANCE_DEFAULTS[0];
        return max(0, (int) Settings::get($key, $def));
    }

    /** Bir market analizinin kredi maliyeti (canlı maçta daha yüksek). */
    public static function marketCost(bool $live = false): int
    {
        return $live
            ? max(0, (int) Settings::get('credit_cost_live_market', 2))
            : max(0, (int) Settings::get('credit_cost_market', 1));
    }

    /** Canlı analizin tazelik süresi (sn): bu süre içinde önbellek geçerli. */
    public static function liveTtl(): int
    {
        return max(30, (int) Settings::get('live_analysis_ttl', 180));
    }

    /** İnternet araştırması (Gemini web grounding) açık mı? */
    public static function webSearchEnabled(): bool
    {
        return (string) Settings::get('ai_web_search', '1') === '1';
    }

    /** Bir oran grubunu GÖRMEK için gereken minimum paket kademesi. */
    public static function groupMinTier(string $groupKey): int
    {
        $def = self::GROUP_TIER_DEFAULTS[$groupKey] ?? 3;
        $val = (int) Settings::get('group_min_tier_' . $groupKey, $def);
        return max(0, min(3, $val));
    }

    /** Grubun görünen adı (admin panelinden değiştirilebilir). */
    public static function groupName(string $groupKey): string
    {
        $def = self::GROUP_NAMES[$groupKey] ?? $groupKey;
        $custom = trim((string) Settings::get('group_name_' . $groupKey, ''));
        return $custom !== '' ? $custom : $def;
    }

    /**
     * Market adı override haritası: {"Orijinal Ad":"Yeni Ad", ...}
     * settings.market_name_overrides içinde JSON olarak saklanır
     * (admin panelinden satır satır girilir).
     */
    public static function marketNameOverrides(): array
    {
        $raw = (string) Settings::get('market_name_overrides', '');
        if ($raw === '') {
            return [];
        }
        $map = json_decode($raw, true);
        return is_array($map) ? $map : [];
    }

    /**
     * Marketin GÖRÜNEN adı. Analiz anahtarı (marketKeyFor) ve grup ataması
     * her zaman ORİJİNAL ada göre yapılır; bu yalnızca gösterimi değiştirir.
     */
    public static function displayMarketName(string $originalName): string
    {
        $map = self::marketNameOverrides();
        $custom = trim((string) ($map[$originalName] ?? ''));
        return $custom !== '' ? $custom : $originalName;
    }

    /** İstemciye gönderilen maliyet tablosu. */
    public static function costs(): array
    {
        return [
            'market' => self::marketCost(false),
            'live_market' => self::marketCost(true),
        ];
    }

    /** Bugün harcanan kredi (tarih değiştiyse 0 — günlük sıfırlama). */
    public static function usedToday(array $user): int
    {
        if (($user['credits_date'] ?? null) !== date('Y-m-d')) {
            return 0;
        }
        return (int) ($user['credits_used'] ?? 0);
    }

    /** Admin'in eklediği bonus kredi (günlük sıfırlanmaz, bitene kadar durur). */
    public static function bonus(array $user): int
    {
        return max(0, (int) ($user['bonus_credits'] ?? 0));
    }

    /** Bugünkü günlük haktan kalan (bonus hariç). */
    public static function dailyRemaining(array $user): int
    {
        return max(0, self::dailyAllowance(Plans::tierOf($user)) - self::usedToday($user));
    }

    /** Toplam kalan kredi: günlük hak + bonus. */
    public static function remaining(?array $user): int
    {
        if (!$user) {
            return 0;
        }
        return self::dailyRemaining($user) + self::bonus($user);
    }

    /**
     * Kredi düşer: önce günlük haktan, bitince bonustan. Yetersizse false
     * döner (harcama yapılmaz). Tarih değiştiyse günlük sayaç bugünden
     * başlar — dünden kalan devretmez; bonus krediler devreder.
     */
    public static function spend(array $user, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }
        if (self::remaining($user) < $amount) {
            return false;
        }
        $fromDaily = min($amount, self::dailyRemaining($user));
        $fromBonus = $amount - $fromDaily;
        $today = date('Y-m-d');
        if (($user['credits_date'] ?? null) !== $today) {
            Database::execute(
                'UPDATE users SET credits_used = ?, credits_date = ?, bonus_credits = GREATEST(0, bonus_credits - ?) WHERE id = ?',
                [$fromDaily, $today, $fromBonus, $user['id']]
            );
        } else {
            Database::execute(
                'UPDATE users SET credits_used = credits_used + ?, bonus_credits = GREATEST(0, bonus_credits - ?) WHERE id = ?',
                [$fromDaily, $fromBonus, $user['id']]
            );
        }
        return true;
    }

    /** Kullanıcının bu maçta açtığı market analizi (varsa açılış zamanıyla). */
    public static function unlockAt(int $userId, int $matchId, string $marketKey): ?string
    {
        try {
            $row = Database::fetch(
                "SELECT created_at FROM user_unlocks
                 WHERE user_id = ? AND match_id = ? AND item_type = 'market' AND item_key = ?",
                [$userId, $matchId, $marketKey]
            );
            return $row ? (string) $row['created_at'] : null;
        } catch (\Throwable $e) {
            return null; // tablo henüz yoksa (migration uygulanmadıysa) akışı bozma
        }
    }

    /** Kullanıcının bu maçta açtığı tüm market anahtarları. */
    public static function unlockedMarkets(int $userId, int $matchId): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT item_key FROM user_unlocks
                 WHERE user_id = ? AND match_id = ? AND item_type = 'market'",
                [$userId, $matchId]
            );
            return array_map(fn($r) => (string) $r['item_key'], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Açılışı kaydet / tazele (canlıda yeniden ücretlendirme sonrası zamanı günceller). */
    public static function recordUnlock(int $userId, int $matchId, string $marketKey, int $spent): void
    {
        try {
            Database::execute(
                "INSERT INTO user_unlocks (user_id, match_id, item_type, item_key, credits_spent)
                 VALUES (?, ?, 'market', ?, ?)
                 ON DUPLICATE KEY UPDATE credits_spent = credits_spent + VALUES(credits_spent), created_at = NOW()",
                [$userId, $matchId, $marketKey, $spent]
            );
        } catch (\Throwable $e) {
            // tablo yoksa sessiz geç
        }
    }

    /** Scraped market adı + çizgi (sov) → kararlı market anahtarı. */
    public static function marketKeyFor(string $name, $sov = null): string
    {
        $base = trim($name) . ($sov !== null && $sov !== '' ? '|' . $sov : '');
        return 'm_' . substr(md5($base), 0, 16);
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
