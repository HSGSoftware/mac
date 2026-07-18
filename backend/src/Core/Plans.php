<?php

namespace MacRadar\Core;

/**
 * Üyelik paketleri (3 kademeli premium) + günlük TOKEN sistemi.
 *   0 = free (Ücretsiz)  — günlük küçük token hakkı (bir maçın bir market grubu kadar)
 *   1 = bronz            — daha fazla günlük token
 *   2 = gumus            — + Günün AI Kuponu, daha fazla günlük token
 *   3 = altin            — + Canlı maç AI tahminleri, en yüksek günlük token
 * Token hakları her gün sıfırlanır (devretmez); bkz. Core\Tokens.
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

    /**
     * ESKİ sistem: kademeye göre günlük analiz limiti (Altın sınırsız => null).
     * Token sistemine geçildi (Core\Tokens); geriye uyumluluk için duruyor.
     */
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
