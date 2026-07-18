<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Config;
use MacRadar\Core\Database;
use MacRadar\Core\Plans;
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Core\Settings;
use MacRadar\Core\Tokens;
use MacRadar\Services\MackolikScraper;

class MatchController
{
    /** Throttle'lı tazeleme: son çekimden bu kadar sn geçtiyse kaynak yeniden çekilir. */
    private function refreshIfStale(string $key, int $ttlSeconds, callable $fetcher): void
    {
        $last = (int) Settings::get($key, 0);
        if (time() - $last < $ttlSeconds) {
            return;
        }
        // Yarış koşullarını azaltmak için damgayı çekimden ÖNCE yaz
        Settings::set($key, (string) time());
        try {
            $fetcher();
        } catch (\Throwable $e) {
            // Çekim başarısızsa akışı bozma; mevcut DB verisiyle devam et
        }
    }

    /** GET /matches?date=YYYY-MM-DD&league_id= */
    public function index(Request $req): void
    {
        $date = (string) $req->input('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $leagueId = $req->input('league_id');

        // Cron'suz mimari: bülten bayatsa (10 dk) kullanıcı isteğiyle tazele
        $this->refreshIfStale('last_fixtures_fetch', 600, function () use ($date) {
            (new MackolikScraper())->fetchFixtures($date);
        });

        $sql = "SELECT m.*, l.name AS league_name, l.country AS league_country, l.priority AS league_priority,
                       ht.name AS home_name, ht.logo_url AS home_logo,
                       at.name AS away_name, at.logo_url AS away_logo,
                       (SELECT COUNT(*) FROM analyses a WHERE a.match_id = m.id AND a.status='done') AS has_analysis
                FROM matches m
                LEFT JOIN leagues l ON l.id = m.league_id
                LEFT JOIN teams ht ON ht.id = m.home_team_id
                LEFT JOIN teams at ON at.id = m.away_team_id
                WHERE DATE(m.start_time) = ?
                  AND m.start_time >= ?";
        // Başlama saati geçmiş maçları gösterme. Karşılaştırmayı, maç saatlerinin
        // saklandığı zaman dilimiyle (Europe/Istanbul) AÇIKÇA yap — sunucu UTC olsa bile doğru.
        $tz = new \DateTimeZone(Config::get('app.timezone', 'Europe/Istanbul'));
        $nowLocal = (new \DateTime('now', $tz))->format('Y-m-d H:i:s');
        $params = [$date, $nowLocal];
        if ($leagueId) {
            $sql .= ' AND m.league_id = ?';
            $params[] = $leagueId;
        }
        $sql .= ' ORDER BY l.priority ASC, l.name ASC, m.start_time ASC';

        $rows = Database::fetchAll($sql, $params);
        $this->loadSignals(array_map(fn($r) => (int) $r['id'], $rows));
        $matches = array_map([$this, 'presentListItem'], $rows);

        // Lige göre grupla
        $grouped = [];
        foreach ($matches as $mt) {
            $key = $mt['league']['id'] ?? 0;
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['league' => $mt['league'], 'matches' => []];
            }
            unset($mt['league']);
            $grouped[$key]['matches'][] = $mt;
        }
        Response::ok([
            'date' => $date,
            'leagues' => array_values($grouped),
        ]);
    }

    /** GET /matches/live — canlı maçlar (canlı oranlarla) */
    public function live(Request $req): void
    {
        // Canlı veri hızlı bayatlar: 75 sn'de bir tazele (kullanıcı isteğiyle)
        $this->refreshIfStale('last_live_fetch', 75, function () {
            (new MackolikScraper())->fetchLive();
        });

        $tz = new \DateTimeZone(Config::get('app.timezone', 'Europe/Istanbul'));
        $nowLocal = (new \DateTime('now', $tz));
        // 4 saatten eski "canlı" kayıtları bitmiş say (takılı kalmasınlar)
        Database::execute(
            "UPDATE matches SET status='finished' WHERE status='live' AND start_time < ?",
            [(clone $nowLocal)->modify('-4 hours')->format('Y-m-d H:i:s')]
        );
        // Canlı işaretli ve son 4 saat içinde başlamış maçlar
        $rows = Database::fetchAll(
            "SELECT m.*, l.name AS league_name, l.country AS league_country, l.priority AS league_priority,
                    ht.name AS home_name, ht.logo_url AS home_logo,
                    at.name AS away_name, at.logo_url AS away_logo,
                    (SELECT COUNT(*) FROM analyses a WHERE a.match_id = m.id AND a.status='done') AS has_analysis
             FROM matches m
             LEFT JOIN leagues l ON l.id = m.league_id
             LEFT JOIN teams ht ON ht.id = m.home_team_id
             LEFT JOIN teams at ON at.id = m.away_team_id
             WHERE m.status = 'live'
               AND m.start_time >= ?
             ORDER BY m.start_time ASC",
            [$nowLocal->modify('-4 hours')->format('Y-m-d H:i:s')]
        );
        $this->loadSignals(array_map(fn($r) => (int) $r['id'], $rows));
        Response::ok(['matches' => array_map([$this, 'presentListItem'], $rows)]);
    }

    /** GET /matches/{id} */
    public function show(Request $req): void
    {
        $id = (int) $req->params['id'];
        $row = Database::fetch(
            "SELECT m.*, l.name AS league_name, l.country AS league_country,
                    ht.name AS home_name, ht.logo_url AS home_logo,
                    at.name AS away_name, at.logo_url AS away_logo
             FROM matches m
             LEFT JOIN leagues l ON l.id = m.league_id
             LEFT JOIN teams ht ON ht.id = m.home_team_id
             LEFT JOIN teams at ON at.id = m.away_team_id
             WHERE m.id = ?",
            [$id]
        );
        if (!$row) {
            Response::error('not_found', 'Maç bulunamadı.', 404);
        }

        // Giriş yapmış kullanıcının maç görüntüleme geçmişini kaydet ("Analizlerim")
        $viewer = Auth::optional($req);
        if ($viewer) {
            try {
                Database::execute(
                    "INSERT INTO user_analysis_history (user_id, match_id, first_viewed_at, last_viewed_at)
                     VALUES (?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE last_viewed_at = NOW()",
                    [$viewer['id'], $id]
                );
            } catch (\Throwable $e) {
                // geçmiş tablosu yoksa akışı bozma
            }
        }

        $odds = $this->latestOdds($id);
        $stats = [];
        foreach (Database::fetchAll('SELECT type, data FROM match_stats WHERE match_id = ?', [$id]) as $s) {
            $stats[$s['type']] = json_decode($s['data'], true);
        }
        $analysis = Database::fetch(
            "SELECT * FROM analyses WHERE match_id = ? AND status='done' ORDER BY id DESC LIMIT 1",
            [$id]
        );

        $markets = $stats['markets'] ?? [];
        unset($stats['markets']);
        $markets = array_values(is_array($markets) ? $markets : []);

        // Token sistemi: marketler gruplara ayrılır; yalnızca kullanıcının
        // token harcayarak açtığı grupların marketleri gönderilir.
        $byGroup = [];
        foreach ($markets as $m) {
            if (!is_array($m)) {
                continue;
            }
            $key = Tokens::groupKeyForMarketName((string) ($m['ad'] ?? ''));
            $m['grup'] = $key;
            $byGroup[$key][] = $m;
        }
        $unlockedKeys = $viewer ? Tokens::unlockedGroups((int) $viewer['id'], $id) : [];
        $groupsOut = [];
        $visibleMarkets = [];
        foreach (Tokens::GROUP_KEYS as $key) {
            $items = $byGroup[$key] ?? [];
            $unlocked = in_array($key, $unlockedKeys, true);
            $groupsOut[] = [
                'key' => $key,
                'name' => Tokens::GROUP_NAMES[$key],
                'cost' => Tokens::groupCost($key),
                'unlocked' => $unlocked,
                'count' => count($items),
            ];
            if ($unlocked) {
                foreach ($items as $it) {
                    $visibleMarkets[] = $it;
                }
            }
        }

        // AI analizi de token ile açılır: yalnızca açan kullanıcıya gönderilir
        $analysisUnlocked = $viewer && Tokens::isUnlocked((int) $viewer['id'], $id, 'analysis');

        Response::ok([
            'match' => $this->presentDetail($row),
            // Boş dizi PHP'de JSON [] üretir; istemci Map beklediği için obje olarak gönder.
            'odds' => (object) $odds,
            'markets' => $visibleMarkets,
            'market_groups' => $groupsOut,
            'stats' => (object) $stats,
            'analysis' => ($analysis && $analysisUnlocked) ? $this->presentAnalysis($analysis) : null,
            'analysis_exists' => (bool) $analysis,
            'token_costs' => Tokens::costs(),
            'tokens_left' => $viewer ? Tokens::remaining($viewer) : null,
        ]);
    }

    /**
     * POST /matches/{id}/unlock-group {group}
     * Bir market grubunu token harcayarak açar (maç başına bir kez).
     */
    public function unlockGroup(Request $req): void
    {
        $user = Auth::require($req);
        $matchId = (int) $req->params['id'];
        $group = (string) $req->input('group');

        if (!in_array($group, Tokens::GROUP_KEYS, true)) {
            Response::error('invalid_group', 'Geçersiz market grubu.', 422);
        }
        $match = Database::fetch('SELECT id FROM matches WHERE id = ?', [$matchId]);
        if (!$match) {
            Response::error('not_found', 'Maç bulunamadı.', 404);
        }

        // Daha önce açıldıysa tekrar ücret alınmaz
        if (Tokens::isUnlocked((int) $user['id'], $matchId, 'market_group', $group)) {
            Response::ok([
                'group' => $group,
                'already_unlocked' => true,
                'tokens_left' => Tokens::remaining($user),
            ], 'Bu grup zaten açık.');
        }

        $cost = Tokens::groupCost($group);
        $remaining = Tokens::remaining($user);
        if ($remaining < $cost) {
            Response::error(
                'insufficient_tokens',
                "Token hakkınız yetersiz (gereken: $cost, kalan: $remaining). Token hakları her gün yenilenir; daha yüksek paketle günlük token hakkınız artar.",
                429,
                ['cost' => $cost, 'remaining' => $remaining, 'tier' => Plans::tierOf($user)]
            );
        }
        Tokens::spend($user, $cost);
        Tokens::recordUnlock((int) $user['id'], $matchId, 'market_group', $group, $cost);
        Response::ok([
            'group' => $group,
            'already_unlocked' => false,
            'tokens_left' => max(0, $remaining - $cost),
        ], Tokens::GROUP_NAMES[$group] . ' açıldı.');
    }

    /** GET /leagues */
    public function leagues(Request $req): void
    {
        $rows = Database::fetchAll(
            'SELECT id, name, country, logo_url, priority FROM leagues WHERE is_active = 1 ORDER BY priority ASC, name ASC'
        );
        Response::ok(['leagues' => array_map(fn($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'country' => $r['country'],
            'logo_url' => $r['logo_url'],
        ], $rows)]);
    }

    /** GET /favorites */
    public function favorites(Request $req): void
    {
        $user = Auth::require($req);
        $rows = Database::fetchAll(
            "SELECT m.*, l.name AS league_name, l.country AS league_country, l.priority AS league_priority,
                    ht.name AS home_name, ht.logo_url AS home_logo,
                    at.name AS away_name, at.logo_url AS away_logo,
                    (SELECT COUNT(*) FROM analyses a WHERE a.match_id = m.id AND a.status='done') AS has_analysis
             FROM user_favorites f
             JOIN matches m ON m.id = f.match_id
             LEFT JOIN leagues l ON l.id = m.league_id
             LEFT JOIN teams ht ON ht.id = m.home_team_id
             LEFT JOIN teams at ON at.id = m.away_team_id
             WHERE f.user_id = ?
             ORDER BY m.start_time ASC",
            [$user['id']]
        );
        $this->loadSignals(array_map(fn($r) => (int) $r['id'], $rows));
        $items = array_map([$this, 'presentListItem'], $rows);
        foreach ($items as &$it) { unset($it['league']); }
        Response::ok(['matches' => $items]);
    }

    /** POST /favorites {match_id} */
    public function addFavorite(Request $req): void
    {
        $user = Auth::require($req);
        $matchId = (int) $req->input('match_id');
        $exists = Database::fetch('SELECT id FROM matches WHERE id = ?', [$matchId]);
        if (!$exists) {
            Response::error('not_found', 'Maç bulunamadı.', 404);
        }
        Database::execute(
            'INSERT IGNORE INTO user_favorites (user_id, match_id) VALUES (?, ?)',
            [$user['id'], $matchId]
        );
        Response::ok(null, 'Favorilere eklendi.');
    }

    /** DELETE /favorites/{id} */
    public function removeFavorite(Request $req): void
    {
        $user = Auth::require($req);
        $matchId = (int) $req->params['id'];
        Database::execute('DELETE FROM user_favorites WHERE user_id = ? AND match_id = ?', [$user['id'], $matchId]);
        Response::ok(null, 'Favorilerden çıkarıldı.');
    }

    /** GET /stats/success-rate */
    public function successRate(Request $req): void
    {
        $overall = Database::fetch(
            "SELECT COUNT(*) AS total, SUM(was_correct = 1) AS correct
             FROM analysis_results WHERE was_correct IS NOT NULL"
        );
        $byMarket = Database::fetchAll(
            "SELECT market, COUNT(*) AS total, SUM(was_correct = 1) AS correct
             FROM analysis_results WHERE was_correct IS NOT NULL
             GROUP BY market ORDER BY total DESC"
        );
        $total = (int) ($overall['total'] ?? 0);
        $correct = (int) ($overall['correct'] ?? 0);
        Response::ok([
            'overall' => [
                'total' => $total,
                'correct' => $correct,
                'rate' => $total > 0 ? round($correct / $total * 100, 1) : null,
            ],
            'by_market' => array_map(fn($r) => [
                'market' => $r['market'],
                'total' => (int) $r['total'],
                'correct' => (int) $r['correct'],
                'rate' => (int) $r['total'] > 0 ? round((int) $r['correct'] / (int) $r['total'] * 100, 1) : null,
            ], $byMarket),
        ]);
    }

    // ---------- Sunum yardımcıları ----------

    public function latestOdds(int $matchId): array
    {
        $rows = Database::fetchAll(
            'SELECT market, value FROM odds WHERE match_id = ? AND is_latest = 1',
            [$matchId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['market']] = (float) $r['value'];
        }
        return $out;
    }

    private function presentListItem(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'iddaa_code' => $r['iddaa_code'],
            'start_time' => $r['start_time'],
            'status' => $r['status'],
            'minute' => $r['minute'],
            'home' => ['name' => $r['home_name'], 'logo' => $r['home_logo']],
            'away' => ['name' => $r['away_name'], 'logo' => $r['away_logo']],
            'score' => $this->score($r),
            'odds' => [
                'MS1' => $this->oddOf($r['id'], 'MS1'),
                'MSX' => $this->oddOf($r['id'], 'MSX'),
                'MS2' => $this->oddOf($r['id'], 'MS2'),
            ],
            'has_analysis' => (int) ($r['has_analysis'] ?? 0) > 0,
            'signal' => $this->signalCache[(int) $r['id']] ?? null,
            'league' => [
                'id' => $r['league_id'] ? (int) $r['league_id'] : null,
                'name' => $r['league_name'],
                'country' => $r['league_country'],
            ],
        ];
    }

    /**
     * Verilen maçlar için model sinyalini (en iyi MS seçimi + değer marjı) toplu yükle.
     * Bülten/canlı listesinde "DEĞER" rozeti ve favori oran vurgusu için kullanılır.
     */
    private array $signalCache = [];
    private function loadSignals(array $matchIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $matchIds))));
        if (!$ids) {
            return;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT a.match_id, a.result
             FROM analyses a
             JOIN (SELECT match_id, MAX(id) AS mid FROM analyses
                   WHERE status='done' AND match_id IN ($place) GROUP BY match_id) t
               ON t.mid = a.id",
            $ids
        );
        foreach ($rows as $r) {
            $res = $r['result'] ? json_decode($r['result'], true) : null;
            if (!is_array($res) || empty($res['markets'])) {
                continue;
            }
            $sig = $this->msSignal($res['markets']);
            if ($sig) {
                $this->signalCache[(int) $r['match_id']] = $sig;
            }
        }
    }

    /** MS marketlerinden en yüksek olasılıklı seçimi + değer marjını çıkar. */
    private function msSignal(array $markets): ?array
    {
        $best = null;
        $bestProb = -1;
        $impliedOfBest = null;
        $hasValue = false;
        foreach ($markets as $m) {
            if (!is_array($m)) {
                continue;
            }
            $code = $m['market'] ?? '';
            if (!in_array($code, ['MS1', 'MSX', 'MS2'], true)) {
                continue;
            }
            if (!isset($m['olasilik'])) {
                continue;
            }
            $prob = (float) $m['olasilik'];
            if (!empty($m['deger_var_mi'])) {
                $hasValue = true;
            }
            if ($prob > $bestProb) {
                $bestProb = $prob;
                $best = $code;
                $impliedOfBest = isset($m['implied_olasilik']) ? (float) $m['implied_olasilik'] : null;
            }
        }
        if ($best === null) {
            return null;
        }
        return [
            'pick' => $best,
            'model_pct' => (int) round($bestProb),
            'implied_pct' => $impliedOfBest !== null ? (int) round($impliedOfBest) : null,
            'edge' => $impliedOfBest !== null ? (int) round($bestProb - $impliedOfBest) : null,
            'has_value' => $hasValue,
        ];
    }

    /** GET /me/analyses — kullanıcının incelediği maçlar + AI sonuçları ("Analizlerim") */
    public function myAnalyses(Request $req): void
    {
        $user = Auth::require($req);
        $rows = Database::fetchAll(
            "SELECT m.id, m.start_time, m.status, m.ms_home, m.ms_away,
                    l.name AS league_name,
                    ht.name AS home_name, at.name AS away_name,
                    a.id AS analysis_id, a.result AS a_result, a.created_at AS analyzed_at
             FROM user_analysis_history h
             JOIN matches m ON m.id = h.match_id
             LEFT JOIN leagues l ON l.id = m.league_id
             LEFT JOIN teams ht ON ht.id = m.home_team_id
             LEFT JOIN teams at ON at.id = m.away_team_id
             LEFT JOIN analyses a ON a.id = (SELECT MAX(id) FROM analyses WHERE match_id = m.id AND status='done')
             WHERE h.user_id = ?
             ORDER BY h.last_viewed_at DESC
             LIMIT 100",
            [$user['id']]
        );

        $items = [];
        $settled = 0;
        $won = 0;
        $oddsSum = 0.0;
        $oddsCount = 0;
        foreach ($rows as $r) {
            $res = $r['a_result'] ? json_decode($r['a_result'], true) : null;
            $sig = ($res && !empty($res['markets'])) ? $this->msSignal($res['markets']) : null;
            $pick = $sig['pick'] ?? null;
            $odd = null;
            if ($pick) {
                foreach (($res['markets'] ?? []) as $m) {
                    if (($m['market'] ?? '') === $pick && isset($m['oran'])) {
                        $odd = (float) $m['oran'];
                    }
                }
            }
            // Sonuç durumu
            $status = 'open';
            $scoreStr = null;
            $finished = $r['status'] === 'finished' && $r['ms_home'] !== null && $r['ms_away'] !== null;
            if ($finished) {
                $scoreStr = ((int) $r['ms_home']) . ' - ' . ((int) $r['ms_away']);
                if ($pick) {
                    $actual = $r['ms_home'] > $r['ms_away'] ? 'MS1' : ($r['ms_home'] == $r['ms_away'] ? 'MSX' : 'MS2');
                    $status = $pick === $actual ? 'won' : 'lost';
                    $settled++;
                    if ($status === 'won') {
                        $won++;
                    }
                }
            }
            if ($odd) {
                $oddsSum += $odd;
                $oddsCount++;
            }
            $items[] = [
                'match_id' => (int) $r['id'],
                'league' => $r['league_name'],
                'match' => $r['home_name'] . ' — ' . $r['away_name'],
                'date' => $r['start_time'],
                'has_analysis' => $sig !== null,
                'pick' => $pick,
                'model_pct' => $sig['model_pct'] ?? null,
                'odds' => $odd,
                'status' => $status,
                'score' => $scoreStr,
            ];
        }
        Response::ok([
            'items' => $items,
            'stats' => [
                'count' => count($items),
                'hit_pct' => $settled > 0 ? (int) round($won / $settled * 100) : null,
                'avg_odds' => $oddsCount > 0 ? round($oddsSum / $oddsCount, 2) : null,
            ],
        ]);
    }

    /** GET /coupon/daily — modelin bugünkü en yüksek değer marjlı seçimlerinden kupon (Gümüş ve Altın) */
    public function dailyCoupon(Request $req): void
    {
        $tz = new \DateTimeZone(Config::get('app.timezone', 'Europe/Istanbul'));
        $today = (new \DateTime('now', $tz))->format('Y-m-d');

        // Günün AI Kuponu Gümüş ve Altın paketlerde görülebilir
        $viewer = Auth::optional($req);
        if (Plans::tierOf($viewer) < 2) {
            Response::ok([
                'date' => $today,
                'locked' => true,
                'required_plan' => 'gumus',
                'picks' => [],
                'summary' => (object) [],
            ]);
        }
        $rows = Database::fetchAll(
            "SELECT m.id, m.start_time, m.status, l.name AS league_name,
                    ht.name AS home_name, at.name AS away_name, a.result AS a_result
             FROM analyses a
             JOIN matches m ON m.id = a.match_id
             LEFT JOIN leagues l ON l.id = m.league_id
             LEFT JOIN teams ht ON ht.id = m.home_team_id
             LEFT JOIN teams at ON at.id = m.away_team_id
             WHERE a.status='done'
               AND a.id = (SELECT MAX(id) FROM analyses WHERE match_id = m.id AND status='done')
               AND DATE(m.start_time) >= ?
             ORDER BY m.start_time ASC",
            [$today]
        );

        $names = ['MS1' => 'Ev sahibi kazanır', 'MSX' => 'Beraberlik', 'MS2' => 'Deplasman kazanır'];
        $labels = ['MS1' => '1', 'MSX' => 'X', 'MS2' => '2'];
        $candidates = [];
        foreach ($rows as $r) {
            $res = $r['a_result'] ? json_decode($r['a_result'], true) : null;
            if (!$res || empty($res['markets'])) {
                continue;
            }
            $sig = $this->msSignal($res['markets']);
            if (!$sig || ($sig['edge'] ?? 0) === null) {
                continue;
            }
            // seçilen marketin oranı
            $odd = null;
            foreach ($res['markets'] as $m) {
                if (($m['market'] ?? '') === $sig['pick'] && isset($m['oran'])) {
                    $odd = (float) $m['oran'];
                }
            }
            if (!$odd || ($sig['edge'] ?? 0) <= 0) {
                continue;
            }
            // gerekçe: seçilen markete ait gerekçe ya da genel analiz
            $reason = $res['genel_analiz'] ?? '';
            foreach ($res['markets'] as $m) {
                if (($m['market'] ?? '') === $sig['pick'] && !empty($m['gerekce'])) {
                    $reason = $m['gerekce'];
                }
            }
            $candidates[] = [
                'match_id' => (int) $r['id'],
                'league' => $r['league_name'],
                'time' => $r['start_time'],
                'match' => $r['home_name'] . ' — ' . $r['away_name'],
                'pick_label' => $labels[$sig['pick']] ?? $sig['pick'],
                'pick_name' => $names[$sig['pick']] ?? $sig['pick'],
                'odds' => $odd,
                'model_pct' => $sig['model_pct'],
                'edge' => $sig['edge'],
                'confidence' => isset($res['guven']) ? (int) $res['guven'] : null,
                'reason' => mb_substr((string) $reason, 0, 180),
            ];
        }
        // en yüksek değer marjına göre sırala, ilk 3
        usort($candidates, fn($a, $b) => $b['edge'] <=> $a['edge']);
        $picks = array_slice($candidates, 0, 3);

        $totalOdds = 1.0;
        $modelProd = 1.0;
        $confSum = 0;
        $confCount = 0;
        foreach ($picks as $p) {
            $totalOdds *= $p['odds'];
            $modelProd *= max(0.01, $p['model_pct'] / 100);
            if ($p['confidence'] !== null) {
                $confSum += $p['confidence'];
                $confCount++;
            }
        }
        $impliedProd = $totalOdds > 0 ? (1 / $totalOdds) : 0;
        Response::ok([
            'date' => $today,
            'picks' => $picks,
            'summary' => [
                'total_odds' => count($picks) ? round($totalOdds, 2) : null,
                'model_pct' => count($picks) ? round($modelProd * 100, 1) : null,
                'implied_pct' => count($picks) ? round($impliedProd * 100, 1) : null,
                'edge' => count($picks) ? round(($modelProd - $impliedProd) * 100, 1) : null,
                'confidence' => $confCount > 0 ? round($confSum / $confCount, 1) : null,
            ],
        ]);
    }

    private array $oddsCache = [];
    private function oddOf($matchId, string $market): ?float
    {
        $matchId = (int) $matchId;
        if (!isset($this->oddsCache[$matchId])) {
            $this->oddsCache[$matchId] = $this->latestOdds($matchId);
        }
        return $this->oddsCache[$matchId][$market] ?? null;
    }

    private function presentDetail(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'iddaa_code' => $r['iddaa_code'],
            'start_time' => $r['start_time'],
            'status' => $r['status'],
            'minute' => $r['minute'],
            'home' => ['name' => $r['home_name'], 'logo' => $r['home_logo']],
            'away' => ['name' => $r['away_name'], 'logo' => $r['away_logo']],
            'score' => $this->score($r),
            'league' => ['name' => $r['league_name'], 'country' => $r['league_country']],
        ];
    }

    private function score(array $r): ?array
    {
        if ($r['ms_home'] === null || $r['ms_away'] === null) {
            return null;
        }
        return [
            'home' => (int) $r['ms_home'],
            'away' => (int) $r['ms_away'],
            'ht_home' => $r['ht_home'] !== null ? (int) $r['ht_home'] : null,
            'ht_away' => $r['ht_away'] !== null ? (int) $r['ht_away'] : null,
        ];
    }

    private function presentAnalysis(array $a): array
    {
        return [
            'id' => (int) $a['id'],
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
