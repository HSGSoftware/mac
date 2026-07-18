<?php

namespace MacRadar\Services;

use DOMDocument;
use DOMXPath;
use MacRadar\Core\Database;
use MacRadar\Core\Settings;

/**
 * Mackolik veri çekici.
 *
 * NOT: Mackolik'in HTML yapısı ve JSON uçları zamanla değişebilir. Bu sınıf iki
 * stratejiyi destekler:
 *   1) JSON feed (birincil) — settings.scraper_fixtures_json_url tanımlıysa kullanılır.
 *   2) HTML parsing (yedek) — DOMXPath ile; XPath seçicileri settings'ten okunur,
 *      böylece site değişince KOD DEĞİŞTİRMEDEN admin panelden düzeltilebilir.
 *
 * İlk kurulumda canlı ağ trafiği incelenerek güncel uç/seçiciler admin panele girilmelidir.
 */
class MackolikScraper
{
    private string $baseUrl;
    private string $userAgent;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Settings::get('scraper_base_url', 'https://www.mackolik.com'), '/');
        $this->userAgent = (string) Settings::get('scraper_user_agent', 'Mozilla/5.0');
    }

    private function headers(): array
    {
        return [
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/json,application/xhtml+xml',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
        ];
    }

    /**
     * Belirtilen tarih için maç programını + oranları çeker ve DB'ye yazar.
     * @return array{count:int, source:string}
     */
    public function fetchFixtures(string $date): array
    {
        $jsonUrl = Settings::get('scraper_fixtures_json_url');
        if ($jsonUrl) {
            $url = str_replace('{date}', $date, $jsonUrl);
            $res = HttpClient::get($url, $this->headers(), 30);
            if ($res['status'] === 200 && $res['body']) {
                $data = json_decode($res['body'], true);
                if (is_array($data)) {
                    $count = $this->ingestFixturesJson($data);
                    return ['count' => $count, 'source' => 'json'];
                }
            }
        }

        // HTML yedek
        $htmlUrlTpl = Settings::get('scraper_fixtures_html_url', $this->baseUrl . '/canli-sonuclar/futbol?date={date}');
        $url = str_replace('{date}', $date, $htmlUrlTpl);
        $res = HttpClient::get($url, $this->headers(), 30);
        if ($res['status'] !== 200 || !$res['body']) {
            throw new \RuntimeException('Program sayfası alınamadı (HTTP ' . $res['status'] . ($res['error'] ? ', ' . $res['error'] : '') . ')');
        }
        $count = $this->ingestFixturesHtml($res['body'], $date);
        return ['count' => $count, 'source' => 'html'];
    }

    /**
     * JSON feed'i beklenen yapıya map eder. Alan adları settings ile eşleştirilebilir.
     * Beklenen esnek yapı: [{id, league:{id,name,country}, home:{id,name}, away:{id,name},
     *   start_time, iddaa_code, odds:{MS1,MSX,MS2,...}}]
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
                (string) ($it['league']['name'] ?? ($it['league_name'] ?? 'Bilinmeyen Lig')),
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
     * HTML program tablosunu XPath ile ayrıştırır.
     * XPath seçicileri settings'ten okunur (varsayılanlar genel bir tablo yapısını hedefler).
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
            // Yapı tanınamadı; hata yerine 0 döndürüp log'a bırakıyoruz.
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
            $time = $get('xpath_time', ".//*[contains(@class,'time')]");
            $ms1 = $get('xpath_ms1', ".//*[contains(@class,'odd-1')]");
            $msx = $get('xpath_msx', ".//*[contains(@class,'odd-x')]");
            $ms2 = $get('xpath_ms2', ".//*[contains(@class,'odd-2')]");

            if ($home === '' || $away === '') {
                continue;
            }
            $homeId = $this->upsertTeam('', $home);
            $awayId = $this->upsertTeam('', $away);
            $leagueId = $this->upsertLeague('', 'Genel', null);
            $startTime = $date . ' ' . ($time ?: '00:00') . ':00';

            $matchId = $this->upsertMatch([
                'mackolik_id' => '',
                'league_id' => $leagueId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'iddaa_code' => null,
                'start_time' => $startTime,
            ]);
            if ($matchId) {
                $odds = array_filter([
                    'MS1' => $this->num($ms1),
                    'MSX' => $this->num($msx),
                    'MS2' => $this->num($ms2),
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
     * Tek bir maçın analiz istatistiklerini (H2H, form, puan durumu) çeker ve kaydeder.
     */
    public function fetchMatchStats(int $matchId): void
    {
        $match = Database::fetch('SELECT * FROM matches WHERE id = ?', [$matchId]);
        if (!$match || !$match['mackolik_id']) {
            return;
        }
        $urlTpl = Settings::get('scraper_match_json_url');
        if (!$urlTpl) {
            return; // Uç tanımlı değilse istatistik çekilmez (AI yine mevcut oran/skorla çalışır).
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
     * Biten maçların skorlarını günceller.
     * @return int güncellenen maç sayısı
     */
    public function fetchResults(string $date): array
    {
        $jsonUrl = Settings::get('scraper_results_json_url');
        if (!$jsonUrl) {
            // Sonuç için ayrı uç yoksa fixtures çekimi zaten skorları içerebilir.
            return ['count' => 0, 'source' => 'none'];
        }
        $url = str_replace('{date}', $date, $jsonUrl);
        $res = HttpClient::get($url, $this->headers(), 30);
        if ($res['status'] !== 200 || !$res['body']) {
            throw new \RuntimeException('Sonuç sayfası alınamadı (HTTP ' . $res['status'] . ')');
        }
        $data = json_decode($res['body'], true);
        $items = $data['matches'] ?? $data['data'] ?? (is_array($data) ? $data : []);
        $count = 0;
        foreach ($items as $it) {
            $mkId = (string) ($it['id'] ?? '');
            if ($mkId === '' || !isset($it['score'])) {
                continue;
            }
            $status = ($it['status'] ?? '') === 'finished' ? 'finished' : ($it['status'] ?? 'scheduled');
            Database::execute(
                'UPDATE matches SET status=?, ms_home=?, ms_away=?, ht_home=?, ht_away=? WHERE mackolik_id=?',
                [
                    $status,
                    $it['score']['home'] ?? null,
                    $it['score']['away'] ?? null,
                    $it['score']['ht_home'] ?? null,
                    $it['score']['ht_away'] ?? null,
                    $mkId,
                ]
            );
            $count++;
        }
        return ['count' => $count, 'source' => 'json'];
    }

    // ---------- DB upsert yardımcıları ----------

    private function upsertLeague(string $mackolikId, string $name, ?string $country): ?int
    {
        if ($mackolikId !== '') {
            $row = Database::fetch('SELECT id FROM leagues WHERE mackolik_id = ?', [$mackolikId]);
            if ($row) {
                return (int) $row['id'];
            }
            return Database::insert(
                'INSERT INTO leagues (mackolik_id, name, country) VALUES (?, ?, ?)',
                [$mackolikId, $name, $country]
            );
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
        // mackolik_id yoksa takım+zaman ile eşle
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
        // Önce mevcut en güncel oranları pasifle
        Database::execute('UPDATE odds SET is_latest = 0 WHERE match_id = ? AND is_latest = 1', [$matchId]);
        foreach ($odds as $market => $value) {
            $v = $this->num((string) $value);
            if ($v === null) {
                continue;
            }
            Database::insert(
                'INSERT INTO odds (match_id, market, value, is_latest) VALUES (?, ?, ?, 1)',
                [$matchId, strtoupper((string) $market), $v]
            );
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

    private function num(string $s): ?float
    {
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
