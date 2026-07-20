<?php

namespace MacRadar\Services;

use MacRadar\Core\Database;
use MacRadar\Core\Settings;

/**
 * Takım amblemlerini TheSportsDB üzerinden bulup teams.logo_url'e yazar.
 *
 * Nesine bülteni logo göndermediği için amblemler dışarıdan tamamlanır.
 * TheSportsDB ücretsiz uçtur ve API anahtarı gerektirmez (varsayılan test
 * anahtarı "3"); Nesine'nin kısaltılmış takım adlarını da ("Atl Mineiro",
 * "Bahia BA") büyük ölçüde doğru eşleştirir.
 *
 * Bulunamayan takımlar logo_checked_at ile işaretlenir; aynı takım için
 * her cron çalışmasında tekrar tekrar istek atılmaz (yeniden deneme aralığı
 * team_logo_retry_days ayarıyla belirlenir, varsayılan 30 gün).
 */
class TeamLogoService
{
    private const BASE = 'https://www.thesportsdb.com/api/v1/json';

    private function apiKey(): string
    {
        $k = trim((string) Settings::get('sportsdb_api_key', '3'));
        return $k !== '' ? $k : '3';
    }

    private function headers(): array
    {
        return [
            'User-Agent' => (string) Settings::get('scraper_user_agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0 Safari/537.36'),
            'Accept' => 'application/json',
        ];
    }

    /**
     * Amblemi eksik takımları tamamlar.
     *
     * @param int $limit Bu çalışmada en fazla kaç takım denenecek (rate limit).
     * @return array{checked:int, found:int, missing:int}
     */
    public function fillMissing(int $limit = 40): array
    {
        $retryDays = max(1, (int) Settings::get('team_logo_retry_days', 30));

        // Önce yaklaşan maçı olan takımlar (kullanıcı bunları görecek), sonra diğerleri
        $rows = Database::fetchAll(
            "SELECT t.id, t.name
             FROM teams t
             LEFT JOIN (
                 SELECT home_team_id AS tid, MIN(start_time) AS soonest FROM matches
                 WHERE start_time >= NOW() - INTERVAL 1 DAY GROUP BY home_team_id
                 UNION ALL
                 SELECT away_team_id AS tid, MIN(start_time) AS soonest FROM matches
                 WHERE start_time >= NOW() - INTERVAL 1 DAY GROUP BY away_team_id
             ) up ON up.tid = t.id
             WHERE (t.logo_url IS NULL OR t.logo_url = '')
               AND (t.logo_checked_at IS NULL OR t.logo_checked_at < NOW() - INTERVAL ? DAY)
             GROUP BY t.id, t.name
             ORDER BY MIN(up.soonest) IS NULL, MIN(up.soonest) ASC
             LIMIT " . (int) $limit,
            [$retryDays]
        );

        $found = 0;
        foreach ($rows as $r) {
            $url = $this->lookup((string) $r['name']);
            Database::execute(
                'UPDATE teams SET logo_url = ?, logo_checked_at = NOW() WHERE id = ?',
                [$url, $r['id']]
            );
            if ($url !== null) {
                $found++;
            }
            // TheSportsDB ücretsiz uç: nazik davran
            usleep(350000);
        }

        $missing = (int) (Database::fetch(
            "SELECT COUNT(*) c FROM teams WHERE logo_url IS NULL OR logo_url = ''"
        )['c'] ?? 0);

        if ($rows) {
            ScrapeLogger::log('team_logos', 'success',
                count($rows) . ' takım denendi, ' . $found . ' amblem bulundu. Kalan eksik: ' . $missing,
                $found, null);
        }

        return ['checked' => count($rows), 'found' => $found, 'missing' => $missing];
    }

    /**
     * Tek bir takım adı için amblem URL'si arar.
     * Nesine kısaltmalarını temizleyerek birkaç varyantla dener.
     */
    public function lookup(string $name): ?string
    {
        foreach ($this->nameVariants($name) as $variant) {
            $url = $this->searchOnce($variant);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    /** Arama için ad varyantları (orijinal + ülke/kısaltma ekleri temizlenmiş). */
    private function nameVariants(string $name): array
    {
        $name = trim($name);
        $out = [$name];

        // "Bahia BA", "Athletico PR" gibi 2 harfli bölge ekleri
        $stripped = preg_replace('/\s+[A-Z]{2}$/u', '', $name);
        if ($stripped && $stripped !== $name) {
            $out[] = $stripped;
        }

        // Yaygın kısaltmaları aç
        $expanded = strtr($name, [
            'Atl ' => 'Atletico ',
            'Ath ' => 'Athletic ',
            'Sp ' => 'Sporting ',
            'Utd' => 'United',
            'Bkn' => 'Brooklyn',
        ]);
        if ($expanded !== $name) {
            $out[] = $expanded;
        }

        // Parantezli ekleri at: "Oklahoma City Thunder (Amsterdam)"
        $noParen = trim(preg_replace('/\s*\([^)]*\)/', '', $name));
        if ($noParen !== '' && $noParen !== $name) {
            $out[] = $noParen;
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** Tek sorgu: eşleşen ilk takımın amblemini döndürür. */
    private function searchOnce(string $query): ?string
    {
        $url = self::BASE . '/' . rawurlencode($this->apiKey())
            . '/searchteams.php?t=' . rawurlencode($query);
        $res = HttpClient::get($url, $this->headers(), 20);
        if ($res['status'] !== 200 || !$res['body']) {
            return null;
        }
        $data = json_decode($res['body'], true);
        $teams = is_array($data) ? ($data['teams'] ?? null) : null;
        if (!is_array($teams) || !$teams) {
            return null;
        }
        foreach ($teams as $t) {
            if (!is_array($t)) {
                continue;
            }
            // Futbol dışı ligleri (basketbol vb.) ele: aynı ad farklı sporda olabilir
            $sport = (string) ($t['strSport'] ?? '');
            if ($sport !== '' && stripos($sport, 'Soccer') === false) {
                continue;
            }
            $badge = trim((string) ($t['strBadge'] ?? ($t['strTeamBadge'] ?? '')));
            if ($badge !== '' && filter_var($badge, FILTER_VALIDATE_URL)) {
                return mb_substr($badge, 0, 255);
            }
        }
        return null;
    }
}
