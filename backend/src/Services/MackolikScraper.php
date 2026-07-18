<?php

namespace MacRadar\Services;

use DOMDocument;
use DOMXPath;
use MacRadar\Core\Database;
use MacRadar\Core\Settings;

/**
 * İddaa bülteni + oran çekici (Nesine getprebultenfull / canlı bülten).
 *
 * Yapı: sg.EA[] olayları; marketler MA/MSA dizisinde.
 *   MTID=market tipi, MST=alt tip, NO=market numarası, SOV=çizgi,
 *   OCA=[{N=sıra, O=oran, ON=opsiyonel seçenek adı}], MN=opsiyonel market adı.
 *
 * İki katman:
 *  1) Kanonik oranlar (MS1/X/2, Alt/Üst, KG, ÇŞ, İY) -> odds tablosu (kartlar + AI)
 *  2) TÜM marketler ham olarak -> match_stats(type='markets') JSON (detay ekranı)
 * Market isimleri: MN > nsn sözlüğü > bilinen MTID adları > "Market #NO".
 */
class MackolikScraper
{
    private string $userAgent;
    /** nsn'den çıkarılan MTID/NO -> isim haritası (istek başına) */
    private array $nameMap = [];

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

    // ================== ÖN BÜLTEN ==================

    /**
     * İddaa ön bültenini (tüm yaklaşan maçlar + tüm oranlar) çeker ve DB'ye yazar.
     * @return array{count:int, source:string}
     */
    public function fetchFixtures(string $date): array
    {
        $dmy = date('d/m/Y', strtotime($date));

        $bultenUrl = (string) Settings::get('scraper_bulten_url', 'https://bulten.nesine.com/api/bulten/getprebultenfull');
        $res = HttpClient::get($bultenUrl, $this->headers(), 45);
        if ($res['status'] === 200 && $res['body']) {
            $data = json_decode($res['body'], true);
            if (is_array($data)) {
                $this->nameMap = $this->extractNameMap($data['nsn'] ?? null);
                $events = $this->findNesineEvents($data);
                if (!empty($events)) {
                    return ['count' => $this->ingestNesine($events, false), 'source' => 'nesine'];
                }
            }
        } else {
            ScrapeLogger::log('bulten_debug', 'error',
                'Nesine erişimi başarısız: HTTP ' . $res['status'] . ($res['error'] ? ' ' . $res['error'] : ''), 0, null);
        }

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

        throw new \RuntimeException('Bülten alınamadı (Nesine HTTP ' . $res['status'] . ($res['error'] ? ', ' . $res['error'] : '') . ').');
    }

    // ================== CANLI BÜLTEN ==================

    /**
     * Canlı bülteni çeker: canlı maçlar + canlı oranlar.
     * Uç adresi ayarlanabilir; varsayılan Nesine canlı bülten ucu.
     * @return array{count:int, source:string}
     */
    public function fetchLive(): array
    {
        $liveUrl = (string) Settings::get('scraper_live_url', 'https://bulten.nesine.com/api/bulten/getlivebultenfull');
        $res = HttpClient::get($liveUrl, $this->headers(), 30);
        if ($res['status'] !== 200 || !$res['body']) {
            ScrapeLogger::log('live_debug', 'error',
                'Canlı bülten alınamadı: HTTP ' . $res['status'] . ($res['error'] ? ' ' . $res['error'] : '') . ' — URL: ' . $liveUrl, 0, null);
            return ['count' => 0, 'source' => 'none'];
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            ScrapeLogger::log('live_debug', 'error', 'Canlı bülten JSON çözülemedi. Baş: ' . mb_substr($res['body'], 0, 500), 0, null);
            return ['count' => 0, 'source' => 'none'];
        }
        $this->nameMap = $this->extractNameMap($data['nsn'] ?? null);
        $events = $this->findNesineEvents($data);
        if (empty($events)) {
            ScrapeLogger::log('live_debug', 'error', 'Canlı bültende olay bulunamadı. Üst anahtarlar: ' . implode(',', array_keys($data)), 0, null);
            return ['count' => 0, 'source' => 'none'];
        }
        // İlk canlı olayın ham örneğini logla (skor/dakika alanlarını doğrulamak için)
        ScrapeLogger::log('live_debug', 'success',
            'İlk canlı olay: ' . mb_substr(json_encode($events[0], JSON_UNESCAPED_UNICODE), 0, 1700), 0, null);

        $count = $this->ingestNesine($events, true);
        return ['count' => $count, 'source' => 'nesine_live'];
    }

    // ================== TEŞHİS ==================

    /** Teşhis: oranlı ilk futbol maçının market özetini + nsn örneğini döndürür. */
    public function debugSample(): array
    {
        $bultenUrl = (string) Settings::get('scraper_bulten_url', 'https://bulten.nesine.com/api/bulten/getprebultenfull');
        $res = HttpClient::get($bultenUrl, $this->headers(), 45);
        if ($res['status'] !== 200 || !$res['body']) {
            return ['error' => 'HTTP ' . $res['status'] . ' ' . ($res['error'] ?? ''), 'url' => $bultenUrl];
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return ['error' => 'JSON çözülemedi', 'head' => mb_substr($res['body'], 0, 800)];
        }
        $this->nameMap = $this->extractNameMap($data['nsn'] ?? null);
        $events = $this->findNesineEvents($data);
        $first = null;
        foreach ($events as $e) {
            if (is_array($e) && (string) ($e['GT'] ?? '') === '1' && (!empty($e['MA']) || !empty($e['MSA']))) {
                $first = $e;
                break;
            }
        }
        if ($first === null) {
            $first = $events[0] ?? null;
        }
        $summary = [];
        $allMarkets = array_merge(
            (is_array($first) && !empty($first['MA'])) ? $first['MA'] : [],
            (is_array($first) && !empty($first['MSA'])) ? $first['MSA'] : []
        );
        foreach ($allMarkets as $mk) {
            if (!is_array($mk)) {
                continue;
            }
            $summary[] = [
                'MTID' => $mk['MTID'] ?? null,
                'NO' => $mk['NO'] ?? null,
                'MST' => $mk['MST'] ?? null,
                'SOV' => $mk['SOV'] ?? null,
                'ad' => $this->marketName($mk),
                'oc' => count($mk['OCA'] ?? []),
            ];
        }
        // nsn sözlüğünün tamamı (isim eşlemesi için; 12000 karaktere kadar)
        $nsnFull = isset($data['nsn'])
            ? mb_substr(json_encode($data['nsn'], JSON_UNESCAPED_UNICODE), 0, 12000)
            : null;
        return [
            'url' => $bultenUrl,
            'event_count' => count($events),
            'name_map_size' => count($this->nameMap),
            'first_event_teams' => is_array($first) ? (($first['HN'] ?? '') . ' - ' . ($first['AN'] ?? '')) : '',
            'markets_summary' => $summary,
            'nsn_full' => $nsnFull,
        ];
    }

    // ================== ÇEKİRDEK AYRIŞTIRMA ==================

    private function findNesineEvents(array $data): array
    {
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
     * nsn sözlüğünden id -> isim haritası çıkarır (yapıdan bağımsız yürüyüş).
     * İçinde sayısal bir kimlik ve string bir ad barındıran düğümleri toplar.
     */
    private function extractNameMap($nsn): array
    {
        if (!is_array($nsn)) {
            return [];
        }
        $map = [];
        $walker = function ($node, $key = null) use (&$walker, &$map) {
            if (!is_array($node)) {
                return;
            }
            // {MTID: x, N/MN/NAME: "..."} kalıbı
            $id = $node['MTID'] ?? ($node['ID'] ?? ($node['NO'] ?? null));
            $name = null;
            foreach (['N', 'MN', 'NAME', 'Name', 'name', 'MTN'] as $f) {
                if (isset($node[$f]) && is_string($node[$f]) && mb_strlen($node[$f]) > 1) {
                    $name = $node[$f];
                    break;
                }
            }
            if ($id !== null && is_numeric((string) $id) && $name !== null) {
                $map[(string) (int) $id] = $name;
            }
            // key => "isim" düz sözlük kalıbı (nsn: {"1":"Maç Sonucu",...})
            foreach ($node as $k => $v) {
                if (is_string($v) && is_numeric((string) $k) && mb_strlen($v) > 1) {
                    $map[(string) (int) $k] = $v;
                } elseif (is_array($v)) {
                    $walker($v, $k);
                }
            }
        };
        $walker($nsn);
        return $map;
    }

    /** Bilinen MTID adları (doğrulanmış/yaygın) — nsn bulunamazsa yedek. */
    private const KNOWN_MTID_NAMES = [
        1 => 'Maç Sonucu',
    ];

    /** Admin panelden yapıştırılabilen elle isim haritası (JSON {mtid: "ad"}). */
    private ?array $customNames = null;
    private function customNameMap(): array
    {
        if ($this->customNames === null) {
            $raw = (string) Settings::get('mk_market_names', '');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $this->customNames = is_array($decoded) ? $decoded : [];
        }
        return $this->customNames;
    }

    private function marketName(array $m): string
    {
        $mn = trim((string) ($m['MN'] ?? ''));
        if ($mn !== '') {
            return $mn;
        }
        $mtid = isset($m['MTID']) ? (int) $m['MTID'] : 0;
        $no = isset($m['NO']) ? (int) $m['NO'] : 0;
        $custom = $this->customNameMap();
        if ($mtid && isset($custom[(string) $mtid])) {
            return (string) $custom[(string) $mtid];
        }
        if ($mtid && isset($this->nameMap[(string) $mtid])) {
            return $this->nameMap[(string) $mtid];
        }
        if ($no && isset($this->nameMap[(string) $no])) {
            return $this->nameMap[(string) $no];
        }
        if (isset(self::KNOWN_MTID_NAMES[$mtid])) {
            return self::KNOWN_MTID_NAMES[$mtid];
        }
        $mst = isset($m['MST']) ? (int) $m['MST'] : 0;
        $sov = (float) str_replace(',', '.', (string) ($m['SOV'] ?? 0));
        if ($mst === 101 && $sov > 0) {
            return number_format($sov, 1, ',', '') . ' Gol Alt/Üst';
        }
        return 'Market #' . ($no ?: $mtid);
    }

    /** Seçenek etiketi: ON > bilinen kalıp > sıra numarası. */
    private function outcomeLabel(array $m, array $o, int $idx, int $total): string
    {
        $on = trim((string) ($o['ON'] ?? ''));
        if ($on !== '') {
            return $on;
        }
        $mtid = isset($m['MTID']) ? (int) $m['MTID'] : 0;
        $mst = isset($m['MST']) ? (int) $m['MST'] : 0;
        if ($mtid === 1 && $total >= 3) {
            return ['1', 'X', '2'][$idx] ?? (string) ($idx + 1);
        }
        if ($mst === 101 && $total === 2) {
            return $idx === 0 ? 'Alt' : 'Üst';
        }
        return (string) ($o['N'] ?? ($idx + 1));
    }

    /**
     * Nesine event dizisini işler. $isLive=true canlı bülten demektir.
     */
    private function ingestNesine(array $events, bool $isLive): int
    {
        $count = 0;
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $gt = $ev['GT'] ?? ($ev['gt'] ?? ($ev['TYPE'] ?? null));
            if ($gt !== null && (string) $gt !== '1') {
                continue;
            }
            $home = trim((string) ($ev['HN'] ?? ($ev['hn'] ?? '')));
            $away = trim((string) ($ev['AN'] ?? ($ev['an'] ?? '')));
            if ($home === '' || $away === '') {
                continue;
            }
            $code = trim((string) ($ev['C'] ?? ($ev['c'] ?? '')));
            $leagueName = trim((string) ($ev['LN'] ?? ($ev['ln'] ?? 'Diğer'))) ?: 'Diğer';
            $dateStr = (string) ($ev['D'] ?? ($ev['d'] ?? ''));
            $timeStr = (string) ($ev['T'] ?? ($ev['t'] ?? ''));

            $leagueId = $this->upsertLeague($leagueName);
            $homeId = $this->upsertTeam($home);
            $awayId = $this->upsertTeam($away);

            $matchId = $this->upsertMatch([
                'mackolik_id' => $code !== '' ? 'nesine_' . $code : '',
                'league_id' => $leagueId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'iddaa_code' => ($code === '' || $code === '0') ? null : $code,
                'start_time' => $this->composeDateTime($dateStr, $timeStr, date('Y-m-d')),
            ]);
            if (!$matchId) {
                continue;
            }

            $ma = (isset($ev['MA']) && is_array($ev['MA'])) ? $ev['MA'] : [];
            $msa = (isset($ev['MSA']) && is_array($ev['MSA'])) ? $ev['MSA'] : [];
            $allMarkets = array_merge($ma, $msa);

            // 1) Kanonik oranlar (kartlar + AI)
            $odds = $this->parseNesineMarkets($allMarkets);
            if ($odds) {
                $this->saveOdds($matchId, $odds);
            }

            // 2) TÜM marketler (detay ekranı)
            $this->saveAllMarkets($matchId, $allMarkets);

            // 3) Canlı durum: skor/dakika alanlarını yakala
            if ($isLive) {
                $this->applyLiveState($matchId, $ev);
            }
            $count++;
        }
        return $count;
    }

    /** Canlı olaydan skor/dakika bilgisini esnek alan adlarıyla yakalar. */
    private function applyLiveState(int $matchId, array $ev): void
    {
        $minute = null;
        foreach (['MIN', 'M', 'MINUTE', 'LT', 'ST'] as $f) {
            if (isset($ev[$f]) && $ev[$f] !== '' && !is_array($ev[$f])) {
                $minute = (string) $ev[$f];
                break;
            }
        }
        $hs = null;
        $as = null;
        // Yaygın kalıplar: SC:{HS,AS} veya HS/AS ya da S:"1-0"
        if (isset($ev['SC']) && is_array($ev['SC'])) {
            $hs = $this->intOrNull($ev['SC']['HS'] ?? ($ev['SC']['H'] ?? null));
            $as = $this->intOrNull($ev['SC']['AS'] ?? ($ev['SC']['A'] ?? null));
        }
        if ($hs === null && isset($ev['HS'])) {
            $hs = $this->intOrNull($ev['HS']);
            $as = $this->intOrNull($ev['AS'] ?? null);
        }
        if ($hs === null && isset($ev['S']) && is_string($ev['S']) && preg_match('/^(\d+)\s*-\s*(\d+)/', $ev['S'], $mm)) {
            $hs = (int) $mm[1];
            $as = (int) $mm[2];
        }

        Database::execute(
            'UPDATE matches SET status = ?, minute = ?, ms_home = COALESCE(?, ms_home), ms_away = COALESCE(?, ms_away) WHERE id = ?',
            ['live', $minute !== null ? mb_substr($minute, 0, 16) : null, $hs, $as, $matchId]
        );
    }

    /** Tüm marketleri isim + seçenek etiketleriyle match_stats(type=markets)'a yazar. */
    private function saveAllMarkets(int $matchId, array $markets): void
    {
        $outList = [];
        foreach ($markets as $m) {
            if (!is_array($m)) {
                continue;
            }
            $oca = $m['OCA'] ?? [];
            if (!is_array($oca) || empty($oca)) {
                continue;
            }
            $total = count($oca);
            $outcomes = [];
            $i = 0;
            foreach ($oca as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $odd = $this->num((string) ($o['O'] ?? ''));
                if ($odd === null || $odd <= 1.001) { // 1.00 = oynanmıyor
                    $i++;
                    continue;
                }
                $outcomes[] = [
                    'ad' => $this->outcomeLabel($m, $o, $i, $total),
                    'oran' => round($odd, 2),
                ];
                $i++;
            }
            if (empty($outcomes)) {
                continue;
            }
            $sov = (float) str_replace(',', '.', (string) ($m['SOV'] ?? 0));
            $outList[] = [
                'ad' => $this->marketName($m),
                'mtid' => (int) ($m['MTID'] ?? 0),
                'no' => (int) ($m['NO'] ?? 0),
                'sov' => $sov > 0 ? $sov : null,
                'secenekler' => $outcomes,
            ];
        }
        if (empty($outList)) {
            return;
        }
        $this->saveStats($matchId, 'markets', $outList);
    }

    /** Kanonik market ayrıştırma (MTID/MST + isim yedekli). */
    private function parseNesineMarkets(array $markets): array
    {
        $out = [];
        foreach ($markets as $m) {
            if (!is_array($m)) {
                continue;
            }
            $mn = mb_strtolower(trim((string) ($m['MN'] ?? '')), 'UTF-8');
            $sov = $m['SOV'] ?? 0;
            $oca = $m['OCA'] ?? [];
            if (!is_array($oca) || empty($oca)) {
                continue;
            }
            $byPos = [];
            foreach ($oca as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $odd = $this->num((string) ($o['O'] ?? ''));
                if ($odd !== null && isset($o['N'])) {
                    $byPos[(string) $o['N']] = $odd;
                }
            }
            if (empty($byPos)) {
                continue;
            }
            $pos = fn($p) => $byPos[(string) $p] ?? null;
            $mtid = isset($m['MTID']) ? (int) $m['MTID'] : 0;
            $mst = isset($m['MST']) ? (int) $m['MST'] : 0;
            $cnt = count($byPos);

            if ($mtid === 1 && $cnt >= 3) { // Maç Sonucu
                $this->put($out, 'MS1', $pos(1));
                $this->put($out, 'MSX', $pos(2));
                $this->put($out, 'MS2', $pos(3));
                continue;
            }
            if ($mst === 101 && $cnt === 2) { // Gol Alt/Üst ailesi
                $ln = $this->goalLine($sov, '');
                if ($ln !== null) {
                    $this->put($out, 'ALT' . $ln, $pos(1));
                    $this->put($out, 'UST' . $ln, $pos(2));
                }
                continue;
            }
            // İsimden yakala (nsn haritası isim verirse)
            $resolved = mb_strtolower($this->marketName($m), 'UTF-8');
            $name = $mn !== '' ? $mn : $resolved;
            if ($name === '') {
                continue;
            }
            if ((strpos($name, 'karşılıklı') !== false || strpos($name, 'karsilikli') !== false) && $cnt === 2) {
                $this->put($out, 'KGVAR', $pos(1));
                $this->put($out, 'KGYOK', $pos(2));
            } elseif ((strpos($name, 'çifte') !== false || strpos($name, 'cifte') !== false) && $cnt === 3) {
                $this->put($out, 'CS1X', $pos(1));
                $this->put($out, 'CS12', $pos(2));
                $this->put($out, 'CSX2', $pos(3));
            } elseif ((strpos($name, 'ilk yarı sonucu') !== false || strpos($name, 'i̇lk yarı sonucu') !== false) && $cnt === 3) {
                $this->put($out, 'IY1', $pos(1));
                $this->put($out, 'IYX', $pos(2));
                $this->put($out, 'IY2', $pos(3));
            }
        }
        return $out;
    }

    private function goalLine($sov, string $mn): ?string
    {
        $val = (float) str_replace(',', '.', (string) $sov);
        foreach ([[1.5, '15'], [2.5, '25'], [3.5, '35']] as $c) {
            if (abs($val - $c[0]) < 0.01) {
                return $c[1];
            }
        }
        if (strpos($mn, '1,5') !== false || strpos($mn, '1.5') !== false) return '15';
        if (strpos($mn, '2,5') !== false || strpos($mn, '2.5') !== false) return '25';
        if (strpos($mn, '3,5') !== false || strpos($mn, '3.5') !== false) return '35';
        return null;
    }

    private function put(array &$arr, string $key, $val): void
    {
        if ($val !== null && (float) $val > 1.001) {
            $arr[$key] = round((float) $val, 2);
        }
    }

    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '' || is_array($v) || !is_numeric((string) $v)) {
            return null;
        }
        return (int) $v;
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

    /** Genel JSON feed (özel yedek uç). */
    private function ingestFixturesJson(array $data): int
    {
        $items = $data['matches'] ?? $data['data'] ?? $data;
        $count = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $leagueId = $this->upsertLeague((string) ($it['league']['name'] ?? ($it['league_name'] ?? 'Diğer')));
            $homeId = $this->upsertTeam((string) ($it['home']['name'] ?? ($it['home'] ?? '')));
            $awayId = $this->upsertTeam((string) ($it['away']['name'] ?? ($it['away'] ?? '')));
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

    /** Maç istatistikleri (opsiyonel uç). */
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

    /** Sonuç/skor güncelleme (opsiyonel uç). */
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

    private function upsertLeague(string $name): ?int
    {
        $name = trim($name) ?: 'Diğer';
        $row = Database::fetch('SELECT id FROM leagues WHERE name = ? LIMIT 1', [$name]);
        if ($row) {
            return (int) $row['id'];
        }
        return Database::insert('INSERT INTO leagues (name) VALUES (?)', [$name]);
    }

    private function upsertTeam(string $name): ?int
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
