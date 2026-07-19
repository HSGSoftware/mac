<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Credits;
use MacRadar\Core\Database;
use MacRadar\Core\Plans;
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Services\AnalysisEngine;

class AnalysisController
{
    /**
     * POST /matches/{id}/analyze-market  {market_key}
     *
     * KREDİ sistemi: her market AYRI analiz edilir ve AYRI kredi tüketir.
     * - Maç öncesi: market başına bir kez kredi düşer; tekrar görüntüleme ücretsiz.
     * - Canlı maç: yalnızca Altın paket; daha yüksek kredi; analiz
     *   live_analysis_ttl saniye tazedir, süre dolunca yeni istek yeni kredi ister.
     * - AI üretimi başarısız olursa kredi DÜŞMEZ.
     */
    public function analyzeMarket(Request $req): void
    {
        $user = Auth::require($req);
        $matchId = (int) $req->params['id'];
        $marketKey = trim((string) $req->input('market_key'));
        if ($marketKey === '' || strlen($marketKey) > 64) {
            Response::error('invalid_market', 'Geçersiz market anahtarı.', 422);
        }

        $match = Database::fetch('SELECT id, status FROM matches WHERE id = ?', [$matchId]);
        if (!$match) {
            Response::error('not_found', 'Maç bulunamadı.', 404);
        }
        $isLive = ($match['status'] ?? '') === 'live';
        $tier = Plans::tierOf($user);

        // Canlı maç anlık analizleri yalnızca Altın pakette
        if ($isLive && $tier < 3) {
            Response::error(
                'live_locked',
                'Canlı maçlarda anlık AI analizleri Altın pakete özeldir.',
                403,
                ['required_plan' => 'altin', 'tier' => $tier]
            );
        }

        $engine = new AnalysisEngine();
        $cost = Credits::marketCost($isLive);

        // Kullanıcı bu marketi daha önce açtı mı? (canlıda açılış TTL süresince geçerli)
        $unlockAt = Credits::unlockAt((int) $user['id'], $matchId, $marketKey);
        $entitled = $unlockAt !== null
            && (!$isLive || (time() - strtotime($unlockAt)) <= Credits::liveTtl());

        // Analiz zaten hazır mı? (önceden yüklenmiş/önbellekli)
        $cachedRow = Database::fetch(
            'SELECT status, created_at FROM market_analyses WHERE match_id = ? AND market_key = ?',
            [$matchId, $marketKey]
        );
        $wasCached = $cachedRow && $cachedRow['status'] === 'done'
            && $engine->isMarketAnalysisFresh($cachedRow, $isLive);

        $remaining = Credits::remaining($user);
        if (!$entitled && $remaining < $cost) {
            Response::error(
                'insufficient_credits',
                "Kredi hakkınız yetersiz (gereken: $cost, kalan: $remaining). Krediler her gün yenilenir; daha yüksek paketle günlük krediniz artar.",
                429,
                ['cost' => $cost, 'remaining' => $remaining, 'tier' => $tier]
            );
        }

        try {
            $row = $engine->analyzeMarket($matchId, $marketKey, (int) $user['id']);
        } catch (\Throwable $e) {
            // Üretim başarısızsa kredi DÜŞMEZ
            Response::error('analysis_failed', 'Analiz üretilemedi: ' . $e->getMessage(), 502);
        }

        // Hazır (önceden yüklenmiş) analiz ilk kez açılıyorsa kısa bir üretim
        // beklemesi uygula; kullanıcı deneyimi gerçek AI çağrısıyla tutarlı kalır.
        if ($wasCached && !$entitled) {
            usleep(random_int(1200, 2400) * 1000);
        }

        if (!$entitled) {
            Credits::spend($user, $cost);
            Credits::recordUnlock((int) $user['id'], $matchId, $marketKey, $cost);
            $remaining = max(0, $remaining - $cost);
        }

        Response::ok([
            'analysis' => self::presentMarketAnalysis($row),
            'credits_left' => $remaining,
        ]);
    }

    /** market_analyses satırını istemci formatına çevirir. */
    public static function presentMarketAnalysis(array $r): array
    {
        $result = $r['result'] ? json_decode($r['result'], true) : null;
        return [
            'id' => (int) $r['id'],
            'match_id' => (int) $r['match_id'],
            'market_key' => $r['market_key'],
            'market_label' => $r['market_label'],
            'is_live' => (bool) $r['is_live'],
            'provider' => $r['provider'],
            'model_name' => $r['model_name'],
            'secenekler' => is_array($result['secenekler'] ?? null) ? $result['secenekler'] : [],
            'tavsiye' => $result['tavsiye'] ?? null,
            'guven' => isset($result['guven']) ? (int) $result['guven'] : null,
            'ozet' => $result['ozet'] ?? null,
            'kaynaklar' => is_array($result['kaynaklar'] ?? null) ? array_values($result['kaynaklar']) : [],
            'created_at' => $r['created_at'],
        ];
    }
}
