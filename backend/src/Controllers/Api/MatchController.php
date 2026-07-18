<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Config;
use MacRadar\Core\Database;
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Core\Settings;
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

        $odds = $this->latestOdds($id);
        $stats = [];
        foreach (Database::fetchAll('SELECT type, data FROM match_stats WHERE match_id = ?', [$id]) as $s) {
            $stats[$s['type']] = json_decode($s['data'], true);
        }
        $analysis = Database::fetch(
            "SELECT * FROM analyses WHERE match_id = ? AND status='done' ORDER BY id DESC LIMIT 1",
            [$id]
        );

        // Tüm marketler ayrı bir liste alanında döner (detay ekranı)
        $markets = $stats['markets'] ?? [];
        unset($stats['markets']);

        Response::ok([
            'match' => $this->presentDetail($row),
            // Boş dizi PHP'de JSON [] üretir; istemci Map beklediği için obje olarak gönder.
            'odds' => (object) $odds,
            'markets' => array_values(is_array($markets) ? $markets : []),
            'stats' => (object) $stats,
            'analysis' => $analysis ? $this->presentAnalysis($analysis) : null,
        ]);
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
            'league' => [
                'id' => $r['league_id'] ? (int) $r['league_id'] : null,
                'name' => $r['league_name'],
                'country' => $r['league_country'],
            ],
        ];
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
