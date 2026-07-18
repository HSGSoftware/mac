<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Database;
use MacRadar\Core\Plans;
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Core\Tokens;
use MacRadar\Services\AnalysisEngine;

class AnalysisController
{
    /**
     * POST /matches/{id}/analyze
     *
     * Token sistemi: AI analizi maç başına BİR KEZ token harcayarak açılır
     * (user_unlocks). Aynı maça sonraki erişimler ücretsizdir.
     * Canlı maç analizi yalnızca Altın pakete açıktır ve daha yüksek
     * token maliyeti vardır.
     */
    public function analyze(Request $req): void
    {
        $user = Auth::require($req);
        $matchId = (int) $req->params['id'];

        $match = Database::fetch('SELECT id, status FROM matches WHERE id = ?', [$matchId]);
        if (!$match) {
            Response::error('not_found', 'Maç bulunamadı.', 404);
        }
        $isLive = ($match['status'] ?? '') === 'live';
        $tier = Plans::tierOf($user);

        // Canlı maç AI tahminleri yalnızca Altın pakette
        if ($isLive && $tier < 3) {
            Response::error(
                'live_locked',
                'Canlı maçlarda AI tahminleri Altın pakete özeldir.',
                403,
                ['required_plan' => 'altin', 'tier' => $tier]
            );
        }

        // Bu maçın analizi daha önce token harcanarak açıldıysa ücretsiz
        $already = Tokens::isUnlocked((int) $user['id'], $matchId, 'analysis');
        $cost = $already ? 0 : Tokens::analysisCost($isLive);
        $remaining = Tokens::remaining($user);
        if (!$already && $remaining < $cost) {
            Response::error(
                'insufficient_tokens',
                "Token hakkınız yetersiz (gereken: $cost, kalan: $remaining). Token hakları her gün yenilenir; daha yüksek paketle günlük token hakkınız artar.",
                429,
                ['cost' => $cost, 'remaining' => $remaining, 'tier' => $tier]
            );
        }

        // Önbellek: tamamlanmış analiz varsa yeniden üretme (token yine düşer,
        // çünkü kullanıcı bu maçın analizini ilk kez açıyor)
        $cached = Database::fetch(
            "SELECT * FROM analyses WHERE match_id = ? AND status='done' ORDER BY id DESC LIMIT 1",
            [$matchId]
        );
        if ($cached) {
            $this->charge($user, $matchId, $cost, $already);
            Response::ok([
                'analysis' => $this->present($cached),
                'cached' => true,
                'tokens_left' => max(0, $remaining - $cost),
            ]);
        }

        try {
            $analysis = (new AnalysisEngine())->analyze($matchId, (int) $user['id']);
        } catch (\Throwable $e) {
            // Üretim başarısızsa token DÜŞMEZ
            Response::error('analysis_failed', 'Analiz üretilemedi: ' . $e->getMessage(), 502);
        }

        $this->charge($user, $matchId, $cost, $already);
        Response::ok([
            'analysis' => $this->present($analysis),
            'cached' => false,
            'tokens_left' => max(0, $remaining - $cost),
        ]);
    }

    /** GET /matches/{id}/analysis — yalnızca analizi token ile açmış kullanıcıya */
    public function show(Request $req): void
    {
        $user = Auth::require($req);
        $matchId = (int) $req->params['id'];
        if (!Tokens::isUnlocked((int) $user['id'], $matchId, 'analysis')) {
            Response::error(
                'analysis_locked',
                'Bu maçın analizini görmek için önce token ile açmalısınız.',
                403,
                ['cost' => Tokens::analysisCost(false), 'remaining' => Tokens::remaining($user)]
            );
        }
        $analysis = Database::fetch(
            "SELECT * FROM analyses WHERE match_id = ? AND status='done' ORDER BY id DESC LIMIT 1",
            [$matchId]
        );
        if (!$analysis) {
            Response::error('not_found', 'Bu maç için henüz analiz yok.', 404);
        }
        Response::ok(['analysis' => $this->present($analysis)]);
    }

    /** Token düş ve açılışı kaydet (ilk açılışta). */
    private function charge(array $user, int $matchId, int $cost, bool $already): void
    {
        if ($already || $cost <= 0) {
            return;
        }
        Tokens::spend($user, $cost);
        Tokens::recordUnlock((int) $user['id'], $matchId, 'analysis', '', $cost);
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
