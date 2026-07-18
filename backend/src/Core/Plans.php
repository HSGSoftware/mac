<?php

namespace MacRadar\Core;

/**
 * Üyelik paketleri (3 kademeli premium).
 *   0 = free (Ücretsiz)  — Ana Marketler
 *   1 = bronz            — + Gol Marketleri
 *   2 = gumus            — + Handikap & Kombine, bülten DEĞER sinyalleri
 *   3 = altin            — + Özel Marketler, Günün Kuponu, sınırsız analiz
 * Eski 'premium' değeri Altın'a eşdeğer sayılır (geriye uyumluluk).
 */
class Plans
{
    public const TIERS = [
        'free' => 0,
        'bronz' => 1,
        'gumus' => 2,
        'altin' => 3,
        'premium' => 3, // eski kayıtlar
    ];

    public const NAMES = [0 => 'Ücretsiz', 1 => 'Bronz', 2 => 'Gümüş', 3 => 'Altın'];

    /** Kullanıcının etkin kademesi (süresi dolmuşsa 0). */
    public static function tierOf(?array $user): int
    {
        if (!$user) {
            return 0;
        }
        $tier = self::TIERS[$user['plan'] ?? 'free'] ?? 0;
        if ($tier > 0 && !empty($user['premium_until']) && strtotime($user['premium_until']) < time()) {
            return 0;
        }
        return $tier;
    }

    /** Kademeye göre günlük analiz limiti (Altın sınırsız => null). */
    public static function dailyLimit(int $tier): ?int
    {
        switch ($tier) {
            case 3:
                return null; // sınırsız
            case 2:
                return (int) Settings::get('gumus_daily_limit', 40);
            case 1:
                return (int) Settings::get('bronz_daily_limit', 15);
            default:
                return (int) Settings::get('free_daily_limit', 3);
        }
    }
}
