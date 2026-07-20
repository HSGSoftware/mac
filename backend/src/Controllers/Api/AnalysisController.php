<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Credits;
use MacRadar\Core\Database;
use MacRadar\Core\Plans;
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Core\Settings;
use MacRadar\Services\AnalysisEngine;

class AnalysisController
{
    /**
     * POST /matches/{id}/analyze-market  {market_key}
     *
     * Akış:
     * - Market analizi HAZIRSA (market_analyses.done + taze): hemen döner.
     *   Ana marketler (MS/Maç Sonucu) ÜCRETSİZ; diğerleri market başına kredi düşer
     *   (bir kez; tekrar görüntüleme ücretsiz). AI üretimi başarısızsa kredi düşmez.
     * - HAZIR DEĞİLSE: kullanıcı beklemez. Maç "Analizlerim"e eklenir, yanıt hemen
     *   "hazırlanıyor" olarak döner, bağlantı kapatılır ve ARKA PLANDA maçın TÜM
     *   marketleri tek AI çağrısıyla üretilir. Kredi bu aşamada DÜŞMEZ (market
     *   açıldığında düşer). Sonuç hazır olunca uygulama Analizlerim'de gösterir.
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

        // Bu market hazır & taze mi?
        $cachedRow = Database::fetch(
            'SELECT * FROM market_analyses WHERE match_id = ? AND market_key = ?',
            [$matchId, $marketKey]
        );
        $ready = $cachedRow && $cachedRow['status'] === 'done'
            && $engine->isMarketAnalysisFresh($cachedRow, $isLive);

        // Maliyet: market tipi (MTID) override'ı > grup varsayılanı.
        // Ana (MS/Maç Sonucu) grubu varsayılan olarak ÜCRETSİZDİR.
        $meta = $engine->marketMeta($matchId, $marketKey);
        $cost = Credits::marketCostFor($meta['group'], $meta['mtid'], $isLive);

        // Kullanıcı bu marketi daha önce açtı mı? (canlıda TTL süresince geçerli)
        $unlockAt = Credits::unlockAt((int) $user['id'], $matchId, $marketKey);
        $entitled = $cost === 0
            || ($unlockAt !== null && (!$isLive || (time() - strtotime($unlockAt)) <= Credits::liveTtl()));
        $remaining = Credits::remaining($user);

        // ---- HAZIR: hemen döndür (gerekirse kredi düş) ----
        if ($ready) {
            if (!$entitled && $remaining < $cost) {
                Response::error(
                    'insufficient_credits',
                    "Kredi hakkınız yetersiz (gereken: $cost, kalan: $remaining). Krediler her gün yenilenir; daha yüksek paketle günlük krediniz artar.",
                    429,
                    ['cost' => $cost, 'remaining' => $remaining, 'tier' => $tier]
                );
            }
            if (!$entitled) {
                Credits::spend($user, $cost);
                Credits::recordUnlock((int) $user['id'], $matchId, $marketKey, $cost);
                $remaining = max(0, $remaining - $cost);
            }
            Response::ok([
                'analysis' => self::presentMarketAnalysis($cachedRow),
                'credits_left' => $remaining,
            ]);
            return;
        }

        // ---- HAZIR DEĞİL: kullanıcı beklemesin ----
        // Maçı "Analizlerim"e ekle ki hazırlanırken orada görünsün.
        self::touchHistory((int) $user['id'], $matchId);

        // Aynı maç için son 3 dk içinde üretim başladıysa tekrar tetikleme.
        $pendingActive = false;
        $anyPending = Database::fetch(
            "SELECT created_at FROM market_analyses
             WHERE match_id = ? AND status = 'pending'
             ORDER BY created_at DESC LIMIT 1",
            [$matchId]
        );
        if ($anyPending && (time() - strtotime($anyPending['created_at'])) < 180) {
            $pendingActive = true;
        }

        // Yanıtı HEMEN ver ve bağlantıyı kapat; analiz arka planda sürsün.
        self::respondPreparing($matchId, $remaining, $pendingActive);

        if ($pendingActive) {
            return; // başka bir istek zaten üretiyor
        }

        @ignore_user_abort(true);
        @set_time_limit(180);
        try {
            $engine->analyzeAllMarkets($matchId, (int) $user['id']);
        } catch (\Throwable $e) {
            // Sessiz: ilgili satırlar 'failed' işaretlendi; kullanıcı tekrar deneyince yeniden üretilir.
        }
        exit;
    }

    /** Maçı kullanıcının "Analizlerim" geçmişine ekler/tazeler. */
    private static function touchHistory(int $userId, int $matchId): void
    {
        try {
            Database::execute(
                "INSERT INTO user_analysis_history (user_id, match_id, first_viewed_at, last_viewed_at)
                 VALUES (?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE last_viewed_at = NOW()",
                [$userId, $matchId]
            );
        } catch (\Throwable $e) {
            // geçmiş tablosu yoksa akışı bozma
        }
    }

    /**
     * "Analiz hazırlanıyor" yanıtını gönderir ve (mümkünse) bağlantıyı kapatır
     * ki arka plan işi kullanıcıyı bekletmesin. exit ÇAĞIRMAZ — çağıran devam eder.
     */
    private static function respondPreparing(int $matchId, int $remaining, bool $alreadyRunning = false): void
    {
        $msg = $alreadyRunning
            ? 'Bu maçın analizi şu anda hazırlanıyor. Son istatistikler, kadro haberleri ve '
              . 'oranlar işleniyor; hazır olduğunda bildirim göndereceğiz.'
            : 'Yapay zeka bu maçın TÜM marketlerini birlikte analiz ediyor — güncel form, '
              . 'H2H, sakat/cezalı ve oran hareketleri değerlendiriliyor. Beklemenize gerek yok: '
              . 'analiz hazır olduğunda bildirim göndereceğiz ve sonuç "Analizlerim" bölümünde olacak.';

        $body = json_encode([
            'success' => true,
            'message' => $msg,
            'data' => [
                'preparing' => true,
                'match_id' => $matchId,
                'credits_left' => $remaining,
                'eta_seconds' => 45,
                'message' => $msg,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!headers_sent()) {
            http_response_code(202);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($body));
            header('Connection: close');
        }
        echo $body;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }
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
