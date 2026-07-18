<?php

namespace MacRadar\Services;

use DOMDocument;
use DOMXPath;
use MacRadar\Core\Database;
use MacRadar\Core\Settings;

/**
 * İddaa bülteni + oran çekici.
 *
 * Birincil kaynak: Nesine public iddaa bülteni JSON'u
 *   https://bulten.nesine.com/api/bulten/getprebultenfull
 *   Yapı: { sg: { EA: [ {C, GT, HN, AN, LN, D, T, MA:[{N, OCA:[{N,O}]}]}, ... ] } }
 *     C  = iddaa/nesine kodu, GT = oyun türü (1=Futbol), HN/AN = ev/deplasman,
 *     LN = lig, D = tarih (gg.aa.yyyy), T = saat (SS:dd),
 *     MA = market listesi, her marketin OCA = seçenek listesi (N=ad, O=oran).
 *   Market eşleştirme isim tabanlıdır (kod değişse de çalışır).
 *
 * Yedekler: özel JSON uç, HTML/XPath. Her çekimde ham yanıtın başı scrape_logs'a
 * (job=bulten_debug) yazılır; yapı değişirse buradan doğrulanır.
 */
class MackolikScraper
{
    private string $userAgent;

    public function __construct()
    {
        $this->userAgent = (string) Settings::get('scraper_user_agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36');
    }

    private function headers(): array
    {
        return [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
            'Referer' => 'https://www.nesine.com/',
            'Origin' => 'https://www.nesine.com',
        ];
    }

    /**
     * İddaa bültenini (tüm yaklaşan maçlar + oranlar) çeker ve DB'ye yazar.
     * Nesine bülteni tarih bağımsız tüm programı döndürür; $date yalnızca yedekler için.
     * @return array{count:int, source:string}
     */
    public function fetchFixtures(string $date): array
    {
        $dmy = date('d/m/Y', strtotime($date));

        // 1) Birincil: Nesine iddaa bülteni
        $bultenUrl = (string) Settings::get('scraper_bulten_url', 'https://bulten.nesine.com/api/bulten/getprebultenfull');
        $res = HttpClient::get($bultenUrl, $this->headers(), 40);
        if ($res['status'] === 200 && $res['body']) {
            $data = json_decode($res['body'], true);
            if (is_array($data)) {
                $events = $this->findNesineEvents($data);
                if (!empty($events)) {
                    return ['count' => $this->ingestNesine($events), 'source' => 'nesine'];
                }
            }
        } else {
            ScrapeLogger::log('bulten_debug', 'error',
                'Nesine erişimi başarısız: HTTP ' . $res['status'] . ($res['error'] ? ' ' . $res['error'] : ''), 0, null);
        }

        // 2) İsteğe bağlı özel JSON uç
        $jsonUrl = Settings::get('scraper_fixtures_json_url');
        if ($jsonUrl) {
            $u = str_replace(['{date_dmy}', '{date}'], [$dmy, $date], $jsonUrl);
            $r = HttpClient::get($u, $this->headers(), 30);
            if ($r['status'] === 200 && $r['body']) {
                ScrapeLogger::log('bulten_debug', 'success', 'Özel JSON ham yanıt (baş): ' . mb_substr($r['body'], 0, 1500), 0, null);
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

        throw new \RuntimeException('Bülten alınamadı (Nesine HTTP ' . $res['status'] . ($res['error'] ? ', ' . $res['error'] : '') . '). Scraper ayarlarından kaynağı kontrol edin.');
    }

    /**
     * Teşhis: Nesine yanıtının yapısını döndürür (admin panelde gösterilir).
     * İlk maçın tam JSON'u sayesinde market/oran alan adları doğrulanır.
     */
    public function debugSample(): array
    {
        $bultenUrl = (string) Settings::get('scraper_bulten_url', 'https://bulten.nesine.com/api/bulten/getprebultenfull');
        $res = HttpClient::get($bultenUrl, $this->headers(), 40);
        if ($res['status'] !== 200 || !$res['body']) {
            return ['error' => 'HTTP ' . $res['status'] . ' ' . ($res['error'] ?? ''), 'url' => $bultenUrl];
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return ['error' => 'JSON çözülemedi', 'head' => mb_substr($res['body'], 0, 800)];
        }
        $events = $this->findNesineEvents($data);
        // Oranlı ilk futbol maçını seç (MA veya MSA dolu)
        $first = null;
        foreach ($events as $e) {
            if (is_array($e) && (string) ($e['GT'] ?? '') === '1'
                && (!empty($e['MA']) || !empty($e['MSA']))) {
                $first = $e;
                break;
            }
        }
        if ($first === null) {
            foreach ($events as $e) {
                if (is_array($e) && (string) ($e['GT'] ?? '') === '1') {
                    $first = $e;
                    break;
                }
            }
        }
        if ($first === null) {
            $first = $events[0] ?? null;
        }
        return [
            'url' => $bultenUrl,
            'top_keys' => array_keys($data),
            'event_count' => count($events),
            'first_event_keys' => is_array($first) ? array_keys($first) : [],
            'first_event_ma_count' => is_array($first) ? count($first['MA'] ?? []) : 0,
            'first_event_msa_count' => is_array($first) ? count($first['MSA'] ?? []) : 0,
            'first_event' => $first,
        ];
    }

    /** Nesine yanıtında olay (event) dizisini bulur (yapı sürümüne dayanıklı). */
    private function findNesineEvents(array $data): array
    {
        // Bilinen konumlar
        $candidates = [
            $data['sg']['EA'] ?? null,
            $data['sg']['ea'] ?? null,
            $data['EA'] ?? null,
            $data['Data']['sg']['EA'] ?? null,
            $data['data']['sg']['EA'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_array($c) && !empty($c)) {
                return $c;
            }
        }
        // Genel arama: içinde HN/AN olan ilk büyük diziyi bul
        $found = [];
        $walker = function ($node) use (&$walker, &$found) {
            if (!empty($found) || !is_array($node)) {
                return;
            }
            if (isset($node[0]) && is_array($node[0]) &&
                (isset($node[0]['HN']) || isset($node[0]['AN']) || isset($node[0]['hn']))) {
                $found = $node;
                return;
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $walker($v);
                }
            }
        };
        $walker($data);
        return $found;
    }

    /**
     * Nesine event dizisini işler (futbol + oranlar).
     */
    private function ingestNesine(array $events): int
    {
        $count = 0;
        $logged = false;
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            // Oyun türü: 1 = Futbol. Alan yoksa dahil et.
            $gt = $ev['GT'] ?? ($ev['gt'] ?? ($ev['TYPE'] ?? null));
            if ($gt !== null && (string) $gt !== '1') {
                continue;
            }
            $home = trim((string) ($ev['HN'] ?? ($ev['hn'] ?? '')));
            $away = trim((string) ($ev['AN'] ?? ($ev['an'] ?? '')));
            if ($home === '' || $away === '') {
                continue;
            }
            if (!$logged && (!empty($ev['MSA']) || !empty($ev['MA']))) {
                ScrapeLogger::log('bulten_debug', 'success',
                    'İlk oranlı maç (MA=' . count($ev['MA'] ?? []) . ' MSA=' . count($ev['MSA'] ?? []) . '): '
                    . mb_substr(json_encode($ev, JSON_UNESCAPED_UNICODE), 0, 1700), 0, null);
                $logged = true;
            }
            $code = trim((string) ($ev['C'] ?? ($ev['c'] ?? '')));
            $leagueName = trim((string) ($ev['LN'] ?? ($ev['ln'] ?? 'Diğer'))) ?: 'Diğer';
            $dateStr = (string) ($ev['D'] ?? ($ev['d'] ?? ''));
            $timeStr = (string) ($ev['T'] ?? ($ev['t'] ?? ''));

            $leagueId = $this->upsertLeague('', $leagueName, null);
            $homeId = $this->upsertTeam('', $home);
            $awayId = $this->upsertTeam('', $away);

            $matchId = $this->upsertMatch([
                'mackolik_id' => $code !== '' ? 'nesine_' . $code : '',
                'league_id' => $leagueId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'iddaa_code' => ($code === '' || $code === '0') ? null : $code,
                'start_time' => $this->composeDateTime($dateStr, $timeStr, date('Y-m-d')),
            ]);

            if ($matchId) {
                // Marketler bazı olaylarda MA, bazılarında MSA dizisinde; ikisini birleştir.
                $ma = (isset($ev['MA']) && is_array($ev['MA'])) ? $ev['MA'] : [];
                $msa = (isset($ev['MSA']) && is_array($ev['MSA'])) ? $ev['MSA'] : [];
                $odds = $this->parseNesineMarkets(array_merge($ma, $msa));
                if ($odds) {
                    $this->saveOdds($matchId, $odds);
                }
            }
            $count++;
        }
        return $count;
    }

    /**
     * Nesine MSA market listesini market koduna eşler.
     * Market adı = MN, seçenek adı = ON, seçenek sırası = N, oran = O, gol çizgisi = SOV.
     * İsim + pozisyon tabanlı; dayanıklı.
     */
    private function parseNesineMarkets(array $markets): array
    {
        $out = [];
        foreach ($markets as $m) {
            if (!is_array($m)) {
                continue;
            }
            $mn = mb_strtolower(trim((string) ($m['MN'] ?? ($m['N'] ?? ''))), 'UTF-8');
            $sov = $m['SOV'] ?? 0;
            $oca = $m['OCA'] ?? ($m['oca'] ?? []);
            if (!is_array($oca) || empty($oca)) {
                continue;
            }

            // Seçenekleri hem ada (ON) hem pozisyona (N) göre indeksle
            $byName = [];
            $byPos = [];
            foreach ($oca as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $odd = $this->num((string) ($o['O'] ?? ($o['odd'] ?? '')));
                if ($odd === null) {
                    continue;
                }
                $on = mb_strtolower(trim((string) ($o['ON'] ?? '')), 'UTF-8');
                if ($on !== '') {
                    $byName[$on] = $odd;
                }
                if (isset($o['N'])) {
                    $byPos[(string) $o['N']] = $odd;
                }
            }
            if (empty($byName) && empty($byPos)) {
                continue;
            }
            $nm = function (array $keys) use ($byName) {
                foreach ($keys as $k) {
                    if (isset($byName[$k])) {
                        return $byName[$k];
                    }
                }
                return null;
            };
            $pos = fn($p) => $byPos[(string) $p] ?? null;

            $isMs = strpos($mn, 'maç sonucu') !== false || strpos($mn, 'mac sonucu') !== false;
            $isIy = (strpos($mn, 'ilk yarı') !== false || strpos($mn, 'i̇lk yarı') !== false || strpos($mn, '1. yarı') !== false)
                && strpos($mn, 'sonuc') !== false;
            $isCs = strpos($mn, 'çifte') !== false || strpos($mn, 'cifte') !== false;
            $isKg = strpos($mn, 'karşılıklı') !== false || strpos($mn, 'karsilikli') !== false;
            $isAu = (strpos($mn, 'alt') !== false && (strpos($mn, 'üst') !== false || strpos($mn, 'ust') !== false))
                || strpos($mn, 'alt/üst') !== false
                || (strpos($mn, 'gol') !== false && strpos($mn, 'toplam') === false && count($oca) === 2);

            if ($isIy) {
                $this->put($out, 'IY1', $nm(['1']) ?? $pos(1));
                $this->put($out, 'IYX', $nm(['0', 'x']) ?? $pos(2));
                $this->put($out, 'IY2', $nm(['2']) ?? $pos(3));
            } elseif ($isCs) {
                $this->put($out, 'CS1X', $nm(['1-x', '1 veya x', '1 ve x', '1x']) ?? $pos(1));
                $this->put($out, 'CS12', $nm(['1-2', '1 veya 2', '12']) ?? $pos(2));
                $this->put($out, 'CSX2', $nm(['x-2', 'x veya 2', 'x2']) ?? $pos(3));
            } elseif ($isMs) {
                $this->put($out, 'MS1', $nm(['1']) ?? $pos(1));
                $this->put($out, 'MSX', $nm(['0', 'x']) ?? $pos(2));
                $this->put($out, 'MS2', $nm(['2']) ?? $pos(3));
            } elseif ($isKg) {
                $this->put($out, 'KGVAR', $nm(['var', 'evet', 'e']) ?? $pos(1));
                $this->put($out, 'KGYOK', $nm(['yok', 'hayır', 'hayir', 'h']) ?? $pos(2));
            } elseif ($isAu) {
                $alt = $nm(['alt', 'a', 'under']) ?? $pos(1);
                $ust = $nm(['üst', 'ust', 'ü', 'u', 'over']) ?? $pos(2);
                $line = $this->goalLine($sov, $mn);
                if ($line !== null) {
                    $this->put($out, 'ALT' . $line, $alt);
                    $this->put($out, 'UST' . $line, $ust);
                }
            }
        }
        return $out;
    }

    /** SOV veya market adından gol çizgisini bulur: 1.5->'15', 2.5->'25', 3.5->'35'. */
    private function goalLine($sov, string $mn): ?string
    {
        $val = (float) str_replace(',', '.', (string) $sov);
        $cands = [1.5 => '15', 2.5 => '25', 3.5 => '35'];
        foreach ($cands as $num => $code) {
            if (abs($val - $num) < 0.01) {
                return $code;
            }
        }
        if (strpos($mn, '1,5') !== false || strpos($mn, '1.5') !== false) return '15';
        if (strpos($mn, '2,5') !== false || strpos($mn, '2.5') !== false) return '25';
        if (strpos($mn, '3,5') !== false || strpos($mn, '3.5') !== false) return '35';
        return null;
    }

    private function put(array &$arr, string $key, $val): void
    {
        // Oran 1.00 = "oynanmıyor" (placeholder); gerçek oranlar > 1.00
        if ($val !== null && (float) $val > 1.001) {
            $arr[$key] = round((float) $val, 2);
        }
    }

    private function composeDateTime(string $dateStr, string $timeStr, string $fallbackYmd): string
    {
        $time = preg_match('/^\d{1,2}:\d{2}$/', trim($timeStr)) ? trim($timeStr) : '00:00';
        $ymd = $fallbackYmd;
        $dateStr = trim($dateStr);
        if (preg_match('#^(\d{1,2})[./](\d{1,2})[./](\d{4})$#', $dateStr, $m)) {
            $ymd = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) {
            $ymd = substr($dateStr, 0, 10);
        }
        return $ymd . ' ' . $time . ':00';
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
     * Sonuç/skor güncelleme — Nesine ön-bülten skor içermez; opsiyonel sonuç ucu tanımlıysa kullanılır.
     * @return array{count:int, source:string}
     */
    public function fetchResults(string $date): array
    {
        $resUrl = Settings::get('scraper_results_json_url');
        if (!$resUrl) {
            return ['count' => 0, 'source' => 'none'];
        }
        $u = str_replace(['{date_dmy}', '{date}'], [date('d/m/Y', strtotime($date)), $date], $resUrl);
        $r = HttpClient::get($u, $this->headers(), 30);
        if ($r['status'] !== 200 || !$r['body']) {
            return ['count' => 0, 'source' => 'none'];
        }
        $data = json_decode($r['body'], true);
        $items = $data['matches'] ?? $data['data'] ?? (is_array($data) ? $data : []);
        $count = 0;
        foreach ($items as $it) {
            $mkId = (string) ($it['id'] ?? '');
            if ($mkId === '' || !isset($it['score'])) {
                continue;
            }
            Database::execute(
                'UPDATE matches SET status=?, ms_home=?, ms_away=? WHERE mackolik_id=?',
                ['finished', $it['score']['home'] ?? null, $it['score']['away'] ?? null, $mkId]
            );
            $count++;
        }
        return ['count' => $count, 'source' => 'json'];
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
