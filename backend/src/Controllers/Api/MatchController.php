<?php

namespace MacRadar\Controllers\Api;

use MacRadar\Core\Auth;
use MacRadar\Core\Config;
use MacRadar\Core\Database;
use MacRadar\Core\Plans;
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Core\Credits;
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

        $markets = $stats['markets'] ?? [];
        unset($stats['markets']);
        $markets = array_values(is_array($markets) ? $markets : []);

        // Oran grupları paket kademesine göre açılır (minimum kademe admin
        // panelinden ayarlanır). Her markete kararlı bir 'key' verilir; AI
        // analizi bu key ile market başına ayrı ayrı istenir (kredi sistemi).
        $tier = Plans::tierOf($viewer);
        $isLive = ($row['status'] ?? '') === 'live';
        $byGroup = [];
        foreach ($markets as $m) {
            if (!is_array($m)) {
                continue;
            }
            $name = (string) ($m['ad'] ?? '');
            // Anahtar ve grup ORİJİNAL ada göre (kararlılık); görünen ad
            // admin panelindeki isim eşlemesinden gelebilir.
            $key = Credits::groupKeyForMarketName($name);
            $mtid = isset($m['mtid']) ? (int) $m['mtid'] : null;
            $m['grup'] = $key;
            $m['key'] = Credits::marketKeyFor($name, $m['sov'] ?? null);
            $m['ad'] = Credits::displayMarketName($name);
            // Bu marketin analizinin kredi maliyeti (0 = ücretsiz)
            $m['credit_cost'] = Credits::marketCostFor($key, $mtid, $isLive);
            $byGroup[$key][] = $m;
        }
        $groupsOut = [];
        $visibleMarkets = [];
        foreach (Credits::GROUP_KEYS as $key) {
            $items = $byGroup[$key] ?? [];
            if (!$items) {
                continue;
            }
            $minTier = Credits::groupMinTier($key);
            $unlocked = $tier >= $minTier;
            $groupsOut[] = [
                'key' => $key,
                'name' => Credits::groupName($key),
                'min_tier' => $minTier,
                'unlocked' => $unlocked,
                'count' => count($items),
            ];
            if ($unlocked) {
                foreach ($items as $it) {
                    $visibleMarkets[] = $it;
                }
            }
        }

        // Gösterilecek analizler:
        //  - Ana (MS/Maç Sonucu) marketler ÜCRETSİZ: hazırsa her zaman gösterilir.
        //  - Diğer marketler: kullanıcının kredi ile açtıkları.
        $marketAnalyses = [];
        $keys = ['MS'];
        foreach ($visibleMarkets as $it) {
            if (($it['grup'] ?? '') === 'ana' && !empty($it['key'])) {
                $keys[] = $it['key'];
            }
        }
        if ($viewer) {
            $keys = array_merge($keys, Credits::unlockedMarkets((int) $viewer['id'], $id));
        }
        $keys = array_values(array_unique(array_filter($keys)));
        if ($keys) {
            $place = implode(',', array_fill(0, count($keys), '?'));
            $rows = Database::fetchAll(
                "SELECT * FROM market_analyses
                 WHERE match_id = ? AND status='done' AND market_key IN ($place)",
                array_merge([$id], $keys)
            );
            foreach ($rows as $r) {
                $marketAnalyses[] = AnalysisController::presentMarketAnalysis($r);
            }
        }

        // Arka planda analiz üretiliyor mu? (uygulama "hazırlanıyor" gösterir)
        $pending = false;
        try {
            $p = Database::fetch(
                "SELECT created_at FROM market_analyses
                 WHERE match_id = ? AND status = 'pending'
                 ORDER BY created_at DESC LIMIT 1",
                [$id]
            );
            // 5 dakikadan eski 'pending' takılı kalmış sayılır
            $pending = $p && (time() - strtotime($p['created_at'])) < 300;
        } catch (\Throwable $e) {
            // tablo yoksa akışı bozma
        }

        Response::ok([
            'match' => $this->presentDetail($row),
            // Boş dizi PHP'de JSON [] üretir; istemci Map beklediği için obje olarak gönder.
            'odds' => (object) $odds,
            'markets' => $visibleMarkets,
            'market_groups' => $groupsOut,
            'stats' => (object) $stats,
            'market_analyses' => $marketAnalyses,
            'analysis_pending' => $pending,
            'credit_costs' => Credits::costs(),
            'credits_left' => $viewer ? Credits::remaining($viewer) : null,
            'live_analysis_ttl' => Credits::liveTtl(),
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

        // 1) Yeni sistem: market başına analizler (Maç Sonucu = 'MS')
        try {
            $rows = Database::fetchAll(
                "SELECT match_id, result FROM market_analyses
                 WHERE status='done' AND market_key='MS' AND match_id IN ($place)",
                $ids
            );
            foreach ($rows as $r) {
                $res = $r['result'] ? json_decode($r['result'], true) : null;
                $sig = is_array($res) ? $this->msSignalFromOptions($res['secenekler'] ?? []) : null;
                if ($sig) {
                    $this->signalCache[(int) $r['match_id']] = $sig;
                }
            }
        } catch (\Throwable $e) {
            // market_analyses tablosu yoksa (migration uygulanmadıysa) eski sisteme düş
        }

        // 2) Eski bütünsel analizler (geriye uyumluluk)
        $rows = Database::fetchAll(
            "SELECT a.match_id, a.result
             FROM analyses a
             JOIN (SELECT match_id, MAX(id) AS mid FROM analyses
                   WHERE status='done' AND match_id IN ($place) GROUP BY match_id) t
               ON t.mid = a.id",
            $ids
        );
        foreach ($rows as $r) {
            $mid = (int) $r['match_id'];
            if (isset($this->signalCache[$mid])) {
                continue;
            }
            $res = $r['result'] ? json_decode($r['result'], true) : null;
            if (!is_array($res) || empty($res['markets'])) {
                continue;
            }
            $sig = $this->msSignal($res['markets']);
            if ($sig) {
                $this->signalCache[$mid] = $sig;
            }
        }
    }

    /** Yeni sistem: MS market analizinin seçeneklerinden sinyal çıkar. */
    private function msSignalFromOptions(array $opts): ?array
    {
        $best = null;
        $bestProb = -1;
        $impliedOfBest = null;
        $hasValue = false;
        foreach ($opts as $o) {
            if (!is_array($o)) {
                continue;
            }
            $kod = $o['kod'] ?? '';
            if (!in_array($kod, ['MS1', 'MSX', 'MS2'], true) || !isset($o['olasilik'])) {
                continue;
            }
            $prob = (float) $o['olasilik'];
            if (!empty($o['deger_var_mi'])) {
                $hasValue = true;
            }
            if ($prob > $bestProb) {
                $bestProb = $prob;
                $best = $kod;
                $impliedOfBest = isset($o['implied_olasilik']) ? (float) $o['implied_olasilik'] : null;
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
                    ht.name AS home_name, ht.logo_url AS home_logo,
                    at.name AS away_name, at.logo_url AS away_logo,
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

        // Yeni sistem: bu maçların MS market analizleri (varsa legacy'ye tercih edilir)
        $msMap = [];
        $historyIds = array_map(fn($r) => (int) $r['id'], $rows);
        if ($historyIds) {
            try {
                $place = implode(',', array_fill(0, count($historyIds), '?'));
                foreach (Database::fetchAll(
                    "SELECT match_id, result FROM market_analyses
                     WHERE status='done' AND market_key='MS' AND match_id IN ($place)",
                    $historyIds
                ) as $mr) {
                    $msMap[(int) $mr['match_id']] = $mr['result'] ? json_decode($mr['result'], true) : null;
                }
            } catch (\Throwable $e) {
                // tablo yoksa legacy ile devam
            }
        }

        // Arka planda hazırlanan analizler ("hazırlanıyor" rozeti için)
        $pendingMap = [];
        if ($historyIds) {
            try {
                $place = implode(',', array_fill(0, count($historyIds), '?'));
                foreach (Database::fetchAll(
                    "SELECT match_id, MAX(created_at) AS started
                     FROM market_analyses
                     WHERE status='pending' AND match_id IN ($place)
                     GROUP BY match_id",
                    $historyIds
                ) as $pr) {
                    // 5 dakikadan eski pending takılı kalmış sayılır
                    if ((time() - strtotime((string) $pr['started'])) < 300) {
                        $pendingMap[(int) $pr['match_id']] = true;
                    }
                }
            } catch (\Throwable $e) {
                // tablo yoksa sessiz geç
            }
        }

        $items = [];
        $settled = 0;
        $won = 0;
        $oddsSum = 0.0;
        $oddsCount = 0;
        foreach ($rows as $r) {
            $sig = null;
            $pick = null;
            $odd = null;
            $msRes = $msMap[(int) $r['id']] ?? null;
            if (is_array($msRes)) {
                $sig = $this->msSignalFromOptions($msRes['secenekler'] ?? []);
                $pick = $sig['pick'] ?? null;
                if ($pick) {
                    foreach (($msRes['secenekler'] ?? []) as $o) {
                        if (($o['kod'] ?? '') === $pick && isset($o['oran'])) {
                            $odd = (float) $o['oran'];
                        }
                    }
                }
            }
            if ($sig === null) {
                $res = $r['a_result'] ? json_decode($r['a_result'], true) : null;
                $sig = ($res && !empty($res['markets'])) ? $this->msSignal($res['markets']) : null;
                $pick = $sig['pick'] ?? null;
                if ($pick) {
                    foreach (($res['markets'] ?? []) as $m) {
                        if (($m['market'] ?? '') === $pick && isset($m['oran'])) {
                            $odd = (float) $m['oran'];
                        }
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
                'home' => ['name' => $r['home_name'], 'logo' => $r['home_logo']],
                'away' => ['name' => $r['away_name'], 'logo' => $r['away_logo']],
                'date' => $r['start_time'],
                'has_analysis' => $sig !== null,
                // Analiz henüz yokken arka planda üretiliyorsa uygulama
                // "hazırlanıyor" gösterir ("henüz analiz yok" değil).
                'analysis_pending' => $sig === null && isset($pendingMap[(int) $r['id']]),
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
        $names = ['MS1' => 'Ev sahibi kazanır', 'MSX' => 'Beraberlik', 'MS2' => 'Deplasman kazanır'];
        $labels = ['MS1' => '1', 'MSX' => 'X', 'MS2' => '2'];
        $candidates = [];
        $seenMatches = [];

        // 1) Yeni sistem: market başına MS analizleri
        try {
            $rows = Database::fetchAll(
                "SELECT m.id, m.start_time, l.name AS league_name,
                        ht.name AS home_name, at.name AS away_name, ma.result AS a_result
                 FROM market_analyses ma
                 JOIN matches m ON m.id = ma.match_id
                 LEFT JOIN leagues l ON l.id = m.league_id
                 LEFT JOIN teams ht ON ht.id = m.home_team_id
                 LEFT JOIN teams at ON at.id = m.away_team_id
                 WHERE ma.status='done' AND ma.market_key='MS'
                   AND DATE(m.start_time) >= ?
                 ORDER BY m.start_time ASC",
                [$today]
            );
            foreach ($rows as $r) {
                $res = $r['a_result'] ? json_decode($r['a_result'], true) : null;
                if (!is_array($res)) {
                    continue;
                }
                $opts = $res['secenekler'] ?? [];
                $sig = $this->msSignalFromOptions($opts);
                if (!$sig || ($sig['edge'] ?? 0) === null || ($sig['edge'] ?? 0) <= 0) {
                    continue;
                }
                $odd = null;
                $reason = (string) ($res['ozet'] ?? '');
                foreach ($opts as $o) {
                    if (($o['kod'] ?? '') === $sig['pick']) {
                        $odd = isset($o['oran']) ? (float) $o['oran'] : null;
                        if (!empty($o['gerekce'])) {
                            $reason = (string) $o['gerekce'];
                        }
                    }
                }
                if (!$odd) {
                    continue;
                }
                $seenMatches[(int) $r['id']] = true;
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
                    'reason' => mb_substr($reason, 0, 180),
                ];
            }
        } catch (\Throwable $e) {
            // market_analyses tablosu yoksa eski sistemle devam
        }

        // 2) Eski bütünsel analizler (geriye uyumluluk)
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
        foreach ($rows as $r) {
            if (isset($seenMatches[(int) $r['id']])) {
                continue;
            }
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

}
