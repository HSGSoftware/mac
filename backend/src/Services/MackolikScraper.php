<?php

namespace MacRadar\Services;

use DOMDocument;
use DOMXPath;
use MacRadar\Core\Database;
use MacRadar\Core\Settings;

/**
 * Mackolik veri çekici.
 *
 * Birincil kaynak: goapi.mackolik.com/livedata?date=GG/AA/YYYY
 *   Yanıt: { "m": [ [maç dizisi], ... ] }
 *   Onaylı indeksler (topluluk kaynakları): 0=id, 2=ev, 4=deplasman, 14=iddaa kodu,
 *   16=saat, 35=tarih, 36=[.. lig bilgisi ..], 29/30=MS skor, 7=İY skor, 6=durum/dakika,
 *   1=oran listesi (sıra: MS1,MSX,MS2, handikap x5, İY1.5 x2, A/Ü1.5 x2, A/Ü2.5 x2,
 *   A/Ü3.5 x2, KG x2, İY sonucu x3, çifte şans x3, toplam gol x4).
 *
 * İndeksler ve uç adresi settings'ten override edilebilir; site formatı değişirse
 * admin panelden kod değiştirmeden düzeltilebilir. Her çekimde ilk maçın ham dizisi
 * scrape_logs'a (job=mackolik_debug) yazılır — böylece indeksler canlı doğrulanabilir.
 */
class MackolikScraper
{
    private string $userAgent;

    /** Oran listesindeki (row[1]) sıralı konum -> market kodu */
    private array $oddsMap = [
        0 => 'MS1', 1 => 'MSX', 2 => 'MS2',
        10 => 'ALT15', 11 => 'UST15',
        12 => 'ALT25', 13 => 'UST25',
        14 => 'ALT35', 15 => 'UST35',
        16 => 'KGVAR', 17 => 'KGYOK',
        18 => 'IY1', 19 => 'IYX', 20 => 'IY2',
        21 => 'CS1X', 22 => 'CS12', 23 => 'CSX2',
    ];

    public function __construct()
    {
        $this->userAgent = (string) Settings::get('scraper_user_agent', 'Mozilla/5.0');
    }

    private function headers(): array
    {
        return [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json,text/html',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
        ];
    }

    private function idx(string $key, int $default): int
    {
        $v = Settings::get('mk_idx_' . $key);
        return ($v !== null && $v !== '') ? (int) $v : $default;
    }

    /**
     * Belirtilen tarih (Y-m-d) için maç programını + oranları çeker ve DB'ye yazar.
     * @return array{count:int, source:string}
     */
    public function fetchFixtures(string $date): array
    {
        $dmy = date('d/m/Y', strtotime($date));

        // 1) Birincil: goapi.mackolik.com/livedata
        $goapiTpl = (string) Settings::get('scraper_goapi_url', 'http://goapi.mackolik.com/livedata?date={date_dmy}');
        $url = str_replace(['{date_dmy}', '{date}'], [$dmy, $date], $goapiTpl);
        $res = HttpClient::get($url, $this->headers(), 30);
        if ($res['status'] === 200 && $res['body']) {
            $data = json_decode($res['body'], true);
            if (is_array($data) && !empty($data['m']) && is_array($data['m'])) {
                $count = $this->ingestGoApi($data['m'], $date);
                return ['count' => $count, 'source' => 'goapi'];
            }
        }

        // 2) İsteğe bağlı özel JSON uç
        $jsonUrl = Settings::get('scraper_fixtures_json_url');
        if ($jsonUrl) {
            $u = str_replace(['{date_dmy}', '{date}'], [$dmy, $date], $jsonUrl);
            $r = HttpClient::get($u, $this->headers(), 30);
            if ($r['status'] === 200 && $r['body']) {
                $d = json_decode($r['body'], true);
                if (is_array($d)) {
                    return ['count' => $this->ingestFixturesJson($d), 'source' => 'json'];
                }
            }
        }

        // 3) HTML yedek
        $htmlUrlTpl = Settings::get('scraper_fixtures_html_url');
        if ($htmlUrlTpl) {
            $u = str_replace(['{date_dmy}', '{date}'], [$dmy, $date], $htmlUrlTpl);
            $r = HttpClient::get($u, $this->headers(), 30);
            if ($r['status'] === 200 && $r['body']) {
                return ['count' => $this->ingestFixturesHtml($r['body'], $date), 'source' => 'html'];
            }
        }

        throw new \RuntimeException('Veri alınamadı (goapi HTTP ' . $res['status'] . ($res['error'] ? ', ' . $res['error'] : '') . '). Uç adresini/erişimi kontrol edin.');
    }

    /**
     * goapi.mackolik.com/livedata "m" dizisini işler.
     */
    private function ingestGoApi(array $rows, string $date, bool $filterFootball = true): int
    {
        // Doğrulama için ilk maçın ham dizisini logla
        if (!empty($rows[0])) {
            ScrapeLogger::log('mackolik_debug', 'success',
                'Örnek ham maç dizisi: ' . mb_substr(json_encode($rows[0], JSON_UNESCAPED_UNICODE), 0, 1800), 0, null);
        }

        $iId = $this->idx('id', 0);
        $iHome = $this->idx('home', 2);
        $iAway = $this->idx('away', 4);
        $iCode = $this->idx('code', 14);
        $iTime = $this->idx('time', 16);
        $iDate = $this->idx('date', 35);
        $iLeague = $this->idx('league', 36);
        $iLeagueName = $this->idx('league_name', 9);
        $iMsHome = $this->idx('ms_home', 29);
        $iMsAway = $this->idx('ms_away', 30);
        $iSport = $this->idx('sport', 23);
        $iOdds = $this->idx('odds', 1);

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Sadece futbol (row[sport]==1). İndeks yanlışsa aşağıda otomatik geri alınır.
            if ($filterFootball && isset($row[$iSport]) && (string) $row[$iSport] !== '1'
                && !is_array($row[$iSport])) {
                continue;
            }
            $home = trim((string) ($row[$iHome] ?? ''));
            $away = trim((string) ($row[$iAway] ?? ''));
            if ($home === '' || $away === '') {
                continue;
            }

            $leagueName = 'Diğer';
            if (isset($row[$iLeague]) && is_array($row[$iLeague])) {
                $leagueName = trim((string) ($row[$iLeague][$iLeagueName] ?? ($row[$iLeague][2] ?? 'Diğer')));
            } elseif (isset($row[$iLeague]) && is_string($row[$iLeague]) && $row[$iLeague] !== '') {
                $leagueName = trim((string) $row[$iLeague]);
            }

            $leagueId = $this->upsertLeague('', $leagueName ?: 'Diğer', null);
            $homeId = $this->upsertTeam('', $home);
            $awayId = $this->upsertTeam('', $away);

            $matchId = $this->upsertMatch([
                'mackolik_id' => (string) ($row[$iId] ?? ''),
                'league_id' => $leagueId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'iddaa_code' => $this->cleanCode($row[$iCode] ?? null),
                'start_time' => $this->composeDateTime((string) ($row[$iDate] ?? ''), (string) ($row[$iTime] ?? ''), $date),
            ]);

            // Skor (bitmiş maçlar için)
            $msHome = $this->intOrNull($row[$iMsHome] ?? null);
            $msAway = $this->intOrNull($row[$iMsAway] ?? null);
            if ($matchId && $msHome !== null && $msAway !== null) {
                Database::execute('UPDATE matches SET ms_home=?, ms_away=?, status=? WHERE id=?',
                    [$msHome, $msAway, 'finished', $matchId]);
            }

            // Oranlar (row[odds] sıralı liste)
            if ($matchId && isset($row[$iOdds]) && is_array($row[$iOdds])) {
                $odds = $this->mapOdds($row[$iOdds]);
                if ($odds) {
                    $this->saveOdds($matchId, $odds);
                }
            }
            $count++;
        }

        // Futbol filtresi hiç maç bırakmadıysa (indeks yanlış olabilir) filtresiz dene
        if ($count === 0 && $filterFootball && !empty($rows)) {
            return $this->ingestGoApi($rows, $date, false);
        }
        return $count;
    }

    /** row[1] sıralı oran listesini market koduna eşler */
    private function mapOdds(array $list): array
    {
        $out = [];
        foreach ($this->oddsMap as $pos => $market) {
            if (!array_key_exists($pos, $list)) {
                continue;
            }
            $v = $this->num((string) $list[$pos]);
            if ($v !== null && $v >= 1.0) {
                $out[$market] = $v;
            }
        }
        return $out;
    }

    private function cleanCode($v): ?string
    {
        $s = trim((string) $v);
        return ($s === '' || $s === '0') ? null : $s;
    }

    private function composeDateTime(string $dateStr, string $timeStr, string $fallbackYmd): string
    {
        $time = preg_match('/^\d{1,2}:\d{2}$/', trim($timeStr)) ? trim($timeStr) : '00:00';
        $ymd = $fallbackYmd;
        $dateStr = trim($dateStr);
        if (preg_match('#^(\d{1,2})[./](\d{1,2})[./](\d{4})$#', $dateStr, $m)) {
            $ymd = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            $ymd = $dateStr;
        }
        return $ymd . ' ' . $time . ':00';
    }

    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '' || !is_numeric((string) $v)) {
            return null;
        }
        return (int) $v;
    }

    /**
     * Genel JSON feed (esnek yapı) — özel uç için.
     */
    private function ingestFixturesJson(array $data): int
    {
        $items = $data['matches'] ?? $data['data'] ?? $data;
        $count = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $leagueId = $this->upsertLeague(
                (string) ($it['league']['id'] ?? ($it['league_id'] ?? '')),
                (string) ($it['league']['name'] ?? ($it['league_name'] ?? 'Diğer')),
                $it['league']['country'] ?? ($it['country'] ?? null)
            );
            $homeId = $this->upsertTeam((string) ($it['home']['id'] ?? ''), (string) ($it['home']['name'] ?? ($it['home'] ?? '')));
            $awayId = $this->upsertTeam((string) ($it['away']['id'] ?? ''), (string) ($it['away']['name'] ?? ($it['away'] ?? '')));
            $matchId = $this->upsertMatch([
                'mackolik_id' => (string) ($it['id'] ?? ''),
                'league_id' => $leagueId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'iddaa_code' => $it['iddaa_code'] ?? ($it['code'] ?? null),
                'start_time' => $this->normalizeDate($it['start_time'] ?? ($it['date'] ?? null)),
            ]);
            if ($matchId && !empty($it['odds']) && is_array($it['odds'])) {
                $this->saveOdds($matchId, $it['odds']);
            }
            $count++;
        }
        return $count;
    }

    /**
     * HTML program tablosunu XPath ile ayrıştırır (son çare).
     */
    private function ingestFixturesHtml(string $html, string $date): int
    {
        $rowXpath = Settings::get('xpath_fixture_row', "//table//tr[contains(@class,'match')]");
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $rows = $xp->query($rowXpath);
        if ($rows === false || $rows->length === 0) {
            return 0;
        }
        $count = 0;
        foreach ($rows as $row) {
            $get = function (string $key, string $default) use ($xp, $row) {
                $sel = Settings::get($key, $default);
                $node = $xp->query($sel, $row);
                return ($node && $node->length) ? trim($node->item(0)->textContent) : '';
            };
            $home = $get('xpath_home', ".//*[contains(@class,'home')]");
            $away = $get('xpath_away', ".//*[contains(@class,'away')]");
            if ($home === '' || $away === '') {
                continue;
            }
            $time = $get('xpath_time', ".//*[contains(@class,'time')]");
            $homeId = $this->upsertTeam('', $home);
            $awayId = $this->upsertTeam('', $away);
            $leagueId = $this->upsertLeague('', 'Diğer', null);
            $matchId = $this->upsertMatch([
                'mackolik_id' => '',
                'league_id' => $leagueId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'iddaa_code' => null,
                'start_time' => $date . ' ' . ($time ?: '00:00') . ':00',
            ]);
            if ($matchId) {
                $odds = array_filter([
                    'MS1' => $this->num($get('xpath_ms1', ".//*[contains(@class,'odd-1')]")),
                    'MSX' => $this->num($get('xpath_msx', ".//*[contains(@class,'odd-x')]")),
                    'MS2' => $this->num($get('xpath_ms2', ".//*[contains(@class,'odd-2')]")),
                ], fn($v) => $v !== null);
                if ($odds) {
                    $this->saveOdds($matchId, $odds);
                }
            }
            $count++;
        }
        return $count;
    }

    /**
     * Tek bir maçın analiz istatistiklerini çeker (opsiyonel uç tanımlıysa).
     */
    public function fetchMatchStats(int $matchId): void
    {
        $match = Database::fetch('SELECT * FROM matches WHERE id = ?', [$matchId]);
        if (!$match || !$match['mackolik_id']) {
            return;
        }
        $urlTpl = Settings::get('scraper_match_json_url');
        if (!$urlTpl) {
            return;
        }
        $url = str_replace('{id}', $match['mackolik_id'], $urlTpl);
        $res = HttpClient::get($url, $this->headers(), 30);
        if ($res['status'] !== 200 || !$res['body']) {
            return;
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return;
        }
        foreach (['h2h', 'form_home', 'form_away', 'standings'] as $type) {
            if (isset($data[$type])) {
                $this->saveStats($matchId, $type, $data[$type]);
            }
        }
    }

    /**
     * Biten maç sonuçlarını günceller (goapi ile fixtures çekimi zaten skorları içerir,
     * bu yüzden aynı endpoint yeniden çekilir).
     * @return array{count:int, source:string}
     */
    public function fetchResults(string $date): array
    {
        try {
            $res = $this->fetchFixtures($date);
            return ['count' => $res['count'], 'source' => $res['source']];
        } catch (\Throwable $e) {
            return ['count' => 0, 'source' => 'none'];
        }
    }

    // ---------- DB upsert yardımcıları ----------

    private function upsertLeague(string $mackolikId, string $name, ?string $country): ?int
    {
        $name = trim($name) ?: 'Diğer';
        if ($mackolikId !== '') {
            $row = Database::fetch('SELECT id FROM leagues WHERE mackolik_id = ?', [$mackolikId]);
            if ($row) {
                return (int) $row['id'];
            }
            return Database::insert('INSERT INTO leagues (mackolik_id, name, country) VALUES (?, ?, ?)', [$mackolikId, $name, $country]);
        }
        $row = Database::fetch('SELECT id FROM leagues WHERE name = ? LIMIT 1', [$name]);
        if ($row) {
            return (int) $row['id'];
        }
        return Database::insert('INSERT INTO leagues (name, country) VALUES (?, ?)', [$name, $country]);
    }

    private function upsertTeam(string $mackolikId, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if ($mackolikId !== '') {
            $row = Database::fetch('SELECT id FROM teams WHERE mackolik_id = ?', [$mackolikId]);
            if ($row) {
                return (int) $row['id'];
            }
            return Database::insert('INSERT INTO teams (mackolik_id, name) VALUES (?, ?)', [$mackolikId, $name]);
        }
        $row = Database::fetch('SELECT id FROM teams WHERE name = ? LIMIT 1', [$name]);
        if ($row) {
            return (int) $row['id'];
        }
        return Database::insert('INSERT INTO teams (name) VALUES (?)', [$name]);
    }

    private function upsertMatch(array $m): ?int
    {
        if ($m['mackolik_id'] !== '') {
            $row = Database::fetch('SELECT id FROM matches WHERE mackolik_id = ?', [$m['mackolik_id']]);
            if ($row) {
                Database::execute(
                    'UPDATE matches SET league_id=?, home_team_id=?, away_team_id=?, iddaa_code=?, start_time=? WHERE id=?',
                    [$m['league_id'], $m['home_team_id'], $m['away_team_id'], $m['iddaa_code'], $m['start_time'], $row['id']]
                );
                return (int) $row['id'];
            }
            return Database::insert(
                'INSERT INTO matches (mackolik_id, league_id, home_team_id, away_team_id, iddaa_code, start_time) VALUES (?, ?, ?, ?, ?, ?)',
                [$m['mackolik_id'], $m['league_id'], $m['home_team_id'], $m['away_team_id'], $m['iddaa_code'], $m['start_time']]
            );
        }
        $row = Database::fetch(
            'SELECT id FROM matches WHERE home_team_id = ? AND away_team_id = ? AND DATE(start_time) = DATE(?) LIMIT 1',
            [$m['home_team_id'], $m['away_team_id'], $m['start_time']]
        );
        if ($row) {
            return (int) $row['id'];
        }
        return Database::insert(
            'INSERT INTO matches (league_id, home_team_id, away_team_id, iddaa_code, start_time) VALUES (?, ?, ?, ?, ?)',
            [$m['league_id'], $m['home_team_id'], $m['away_team_id'], $m['iddaa_code'], $m['start_time']]
        );
    }

    private function saveOdds(int $matchId, array $odds): void
    {
        Database::execute('UPDATE odds SET is_latest = 0 WHERE match_id = ? AND is_latest = 1', [$matchId]);
        foreach ($odds as $market => $value) {
            $v = $this->num((string) $value);
            if ($v === null) {
                continue;
            }
            Database::insert('INSERT INTO odds (match_id, market, value, is_latest) VALUES (?, ?, ?, 1)',
                [$matchId, strtoupper((string) $market), $v]);
        }
    }

    private function saveStats(int $matchId, string $type, $data): void
    {
        Database::execute(
            'INSERT INTO match_stats (match_id, type, data) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), fetched_at = CURRENT_TIMESTAMP',
            [$matchId, $type, json_encode($data, JSON_UNESCAPED_UNICODE)]
        );
    }

    private function num(?string $s): ?float
    {
        if ($s === null) {
            return null;
        }
        $s = str_replace(',', '.', trim($s));
        return is_numeric($s) ? (float) $s : null;
    }

    private function normalizeDate($v): ?string
    {
        if (!$v) {
            return null;
        }
        $ts = is_numeric($v) ? (int) $v : strtotime((string) $v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
