<?php

namespace MacRadar\Services;

use MacRadar\Core\Database;

/**
 * Biten maçlarda AI tahminlerinin isabetini hesaplar (analysis_results tablosu).
 */
class ResultEvaluator
{
    /**
     * Henüz değerlendirilmemiş, biten maçların analizlerini işler.
     * @return int değerlendirilen tahmin sayısı
     */
    public static function evaluatePending(): int
    {
        $rows = Database::fetchAll(
            "SELECT a.id AS analysis_id, a.match_id, a.result, m.ms_home, m.ms_away
             FROM analyses a
             JOIN matches m ON m.id = a.match_id
             WHERE a.status = 'done' AND m.status = 'finished'
               AND m.ms_home IS NOT NULL AND m.ms_away IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM analysis_results ar WHERE ar.analysis_id = a.id)"
        );

        $count = 0;
        foreach ($rows as $r) {
            $result = json_decode($r['result'], true);
            $markets = $result['markets'] ?? [];
            $home = (int) $r['ms_home'];
            $away = (int) $r['ms_away'];
            foreach ($markets as $mk) {
                $market = $mk['market'] ?? null;
                if (!$market) {
                    continue;
                }
                $correct = self::marketOutcome($market, $home, $away);
                Database::insert(
                    'INSERT INTO analysis_results (analysis_id, match_id, market, predicted_prob, was_correct) VALUES (?, ?, ?, ?, ?)',
                    [$r['analysis_id'], $r['match_id'], $market, $mk['olasilik'] ?? null, $correct === null ? null : (int) $correct]
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * Verilen market skora göre gerçekleşti mi?
     * @return bool|null null: bilinmeyen market
     */
    public static function marketOutcome(string $market, int $home, int $away): ?bool
    {
        $total = $home + $away;
        switch (strtoupper($market)) {
            case 'MS1': return $home > $away;
            case 'MSX': return $home === $away;
            case 'MS2': return $home < $away;
            case 'CS1X': return $home >= $away;
            case 'CS12': return $home !== $away;
            case 'CSX2': return $home <= $away;
            case 'ALT25': return $total < 3;      // 0-2 gol
            case 'UST25': return $total >= 3;     // 3+ gol
            case 'ALT15': return $total < 2;
            case 'UST15': return $total >= 2;
            case 'ALT35': return $total < 4;
            case 'UST35': return $total >= 4;
            case 'KGVAR': return $home > 0 && $away > 0;
            case 'KGYOK': return $home === 0 || $away === 0;
            default: return null;
        }
    }
}
