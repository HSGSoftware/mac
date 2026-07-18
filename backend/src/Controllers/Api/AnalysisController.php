<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Database;
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Core\Settings;
use MacRadar\Services\AnalysisEngine;

class AnalysisController
{
    /** POST /matches/{id}/analyze */
    public function analyze(Request $req): void
    {
        $user = Auth::require($req);
        $matchId = (int) $req->params['id'];

        $match = Database::fetch('SELECT id FROM matches WHERE id = ?', [$matchId]);
        if (!$match) {
            Response::error('not_found', 'Maç bulunamadı.', 404);
        }

        // Önbellek: tamamlanmış analiz varsa limit tüketmeden döndür
        $cached = Database::fetch(
            "SELECT * FROM analyses WHERE match_id = ? AND status='done' ORDER BY id DESC LIMIT 1",
            [$matchId]
        );
        if ($cached) {
            Response::ok(['analysis' => $this->present($cached), 'cached' => true]);
        }

        // Limit kontrolü (premium sınırsız)
        if (!$this->isPremium($user)) {
            $limit = (int) Settings::get('free_daily_limit', 3);
            $count = $this->todaysCount($user);
            if ($count >= $limit) {
                Response::error('limit_reached', "Günlük ücretsiz analiz hakkınız doldu ($limit). Premium'a geçerek sınırsız analiz yapabilirsiniz.", 429, [
                    'limit' => $limit,
                    'used' => $count,
                ]);
            }
        }

        try {
            $analysis = (new AnalysisEngine())->analyze($matchId, (int) $user['id']);
        } catch (\Throwable $e) {
            Response::error('analysis_failed', 'Analiz üretilemedi: ' . $e->getMessage(), 502);
        }

        // Yeni analiz üretildiyse limit sayacını artır
        if (!$this->isPremium($user)) {
            $this->incrementCount($user);
        }

        Response::ok(['analysis' => $this->present($analysis), 'cached' => false]);
    }

    /** GET /matches/{id}/analysis */
    public function show(Request $req): void
    {
        $matchId = (int) $req->params['id'];
        $analysis = Database::fetch(
            "SELECT * FROM analyses WHERE match_id = ? AND status='done' ORDER BY id DESC LIMIT 1",
            [$matchId]
        );
        if (!$analysis) {
            Response::error('not_found', 'Bu maç için henüz analiz yok.', 404);
        }
        Response::ok(['analysis' => $this->present($analysis)]);
    }

    private function isPremium(array $user): bool
    {
        return $user['plan'] === 'premium'
            && (!$user['premium_until'] || strtotime($user['premium_until']) > time());
    }

    private function todaysCount(array $user): int
    {
        $today = date('Y-m-d');
        if (($user['counter_date'] ?? null) !== $today) {
            return 0;
        }
        return (int) $user['daily_analysis_count'];
    }

    private function incrementCount(array $user): void
    {
        $today = date('Y-m-d');
        if (($user['counter_date'] ?? null) !== $today) {
            Database::execute('UPDATE users SET daily_analysis_count = 1, counter_date = ? WHERE id = ?', [$today, $user['id']]);
        } else {
            Database::execute('UPDATE users SET daily_analysis_count = daily_analysis_count + 1 WHERE id = ?', [$user['id']]);
        }
    }

    private function present(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'match_id' => (int) $a['match_id'],
            'provider' => $a['provider'],
            'model_name' => $a['model_name'],
            'result' => $a['result'] ? json_decode($a['result'], true) : null,
            'general_note' => $a['general_note'],
            'safest_pick' => $a['safest_pick'],
            'surprise_level' => $a['surprise_level'],
            'is_risky' => (bool) $a['is_risky'],
            'created_at' => $a['created_at'],
        ];
    }
}
