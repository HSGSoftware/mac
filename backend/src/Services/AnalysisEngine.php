<?php

namespace MacRadar\Services;

use MacRadar\Core\Database;
use MacRadar\Core\Settings;
use MacRadar\Services\Llm\LlmFactory;

/**
 * Maç verilerini AI analizine dönüştüren motor.
 * - match_stats + oranları yapılandırılmış prompt'a çevirir
 * - LLM'den katı JSON şema ister
 * - Değer bahsi (value bet) hesaplar: AI olasılığı vs oranın ima ettiği olasılık
 */
class AnalysisEngine
{
    /**
     * Bir maç için analiz üretir ve DB'ye kaydeder. Var olan 'done' analizi döndürür (önbellek).
     * @return array analizin DB satırı
     */
    public function analyze(int $matchId, ?int $userId = null): array
    {
        // Önbellek: tamamlanmış analiz varsa onu döndür
        $existing = Database::fetch(
            "SELECT * FROM analyses WHERE match_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1",
            [$matchId]
        );
        if ($existing) {
            return $existing;
        }

        $match = Database::fetch(
            "SELECT m.*, ht.name AS home_name, at.name AS away_name, l.name AS league_name
             FROM matches m
             LEFT JOIN teams ht ON ht.id = m.home_team_id
             LEFT JOIN teams at ON at.id = m.away_team_id
             LEFT JOIN leagues l ON l.id = m.league_id
             WHERE m.id = ?",
            [$matchId]
        );
        if (!$match) {
            throw new \RuntimeException('Maç bulunamadı.');
        }

        // İstatistik eksikse anlık çekmeyi dene (uç tanımlıysa)
        $statsCount = (int) (Database::fetch('SELECT COUNT(*) c FROM match_stats WHERE match_id = ?', [$matchId])['c'] ?? 0);
        if ($statsCount === 0) {
            try {
                (new MackolikScraper())->fetchMatchStats($matchId);
            } catch (\Throwable $e) {
                // istatistik alınamazsa mevcut oran/verilerle devam
            }
        }

        $odds = $this->latestOdds($matchId);
        $stats = $this->stats($matchId);

        // Analiz kaydını 'pending' oluştur
        $provider = LlmFactory::providerName();
        $analysisId = Database::insert(
            'INSERT INTO analyses (match_id, provider, status, created_by) VALUES (?, ?, ?, ?)',
            [$matchId, $provider, 'pending', $userId]
        );

        try {
            $client = LlmFactory::make();
            $systemPrompt = $this->systemPrompt();
            $userPrompt = $this->buildUserPrompt($match, $odds, $stats);

            $result = $client->complete($systemPrompt, $userPrompt);
            $parsed = $this->parseResult($result['text']);
            if ($parsed === null) {
                // Bir kez daha dene (JSON düzeltme talebiyle)
                $result = $client->complete($systemPrompt, $userPrompt . "\n\nSADECE geçerli JSON döndür, açıklama ekleme.");
                $parsed = $this->parseResult($result['text']);
            }
            if ($parsed === null) {
                throw new \RuntimeException('AI yanıtı geçerli JSON değil.');
            }

            // Value bet hesabı: her market için oranın ima ettiği olasılık (1/oran) ile karşılaştır
            $parsed['markets'] = $this->annotateValueBets($parsed['markets'] ?? [], $odds);

            Database::execute(
                "UPDATE analyses SET status='done', model_name=?, result=?, general_note=?, safest_pick=?, surprise_level=?, is_risky=?, token_usage=? WHERE id=?",
                [
                    $result['model'],
                    json_encode($parsed, JSON_UNESCAPED_UNICODE),
                    $parsed['genel_analiz'] ?? null,
                    $parsed['en_guvenli_tahmin'] ?? null,
                    $parsed['surpriz_potansiyeli'] ?? null,
                    !empty($parsed['riskli']) ? 1 : 0,
                    $result['tokens'],
                    $analysisId,
                ]
            );
            return Database::fetch('SELECT * FROM analyses WHERE id = ?', [$analysisId]);
        } catch (\Throwable $e) {
            Database::execute(
                "UPDATE analyses SET status='failed', error_message=? WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $analysisId]
            );
            throw $e;
        }
    }

    private function annotateValueBets(array $markets, array $odds): array
    {
        foreach ($markets as &$m) {
            $market = $m['market'] ?? null;
            $oran = $m['oran'] ?? ($market ? ($odds[$market] ?? null) : null);
            $aiProb = isset($m['olasilik']) ? (float) $m['olasilik'] : null;
            if ($oran && $oran > 0 && $aiProb !== null) {
                $impliedProb = round(100 / $oran, 1);       // oranın ima ettiği olasılık
                $m['oran'] = (float) $oran;
                $m['implied_olasilik'] = $impliedProb;
                // AI olasılığı, oranın ima ettiğinden belirgin yüksekse değer var
                $m['deger_var_mi'] = $aiProb > $impliedProb + 3;
                $m['deger_farki'] = round($aiProb - $impliedProb, 1);
            }
        }
        return $markets;
    }

    private function systemPrompt(): string
    {
        return "Sen profesyonel bir futbol bahis analistisin. Görevin, verilen maç verilerini (takım formları, "
            . "aralarındaki geçmiş maçlar, puan durumu ve güncel bahis oranları) inceleyip her bahis seçeneği için "
            . "gerçekçi bir kazanma olasılığı (yüzde) ve kısa, veriye dayalı bir gerekçe üretmek. Abartma, "
            . "veriye sadık kal. Yanıtını YALNIZCA aşağıdaki JSON şemasında ver:\n"
            . '{"markets":[{"market":"MS1","oran":2.10,"olasilik":45,"gerekce":"..."}],'
            . '"genel_analiz":"...","en_guvenli_tahmin":"MS1","surpriz_potansiyeli":"dusuk|orta|yuksek","riskli":false,'
            . '"guven":7,"nedenler":[{"etiket":"Form farkı","metin":"Ev sahibi son 5 maçta 13 puan topladı."}]}'
            . "\nmarket kodları: MS1,MSX,MS2,CS1X,CS12,CSX2,ALT25,UST25,ALT15,UST15,ALT35,UST35,KGVAR,KGYOK. "
            . "MS1, MSX, MS2 (maç sonucu) için MUTLAKA olasılık ver ve toplamları ~100 olsun. "
            . "olasilik 0-100 arası tam sayı. Sadece verilerde oranı bulunan marketleri değerlendir. "
            . "'guven': analizine olan güvenin 1-10 arası tam sayı. "
            . "'nedenler': modelin bu maçı neden böyle değerlendirdiğini açıklayan 3-4 maddelik liste; "
            . "her madde {etiket, metin} — etiket kısa başlık (ör. 'Form farkı', 'İç saha gücü', 'Oran farkı'), "
            . "metin 1-2 cümlelik veriye dayalı açıklama.";
    }

    private function buildUserPrompt(array $match, array $odds, array $stats): string
    {
        $custom = Settings::get('analysis_prompt');
        $header = $custom ?: "Aşağıdaki maçı analiz et:";

        $lines = [$header, ''];
        $lines[] = "Maç: {$match['home_name']} vs {$match['away_name']}";
        $lines[] = "Lig: " . ($match['league_name'] ?? '-');
        $lines[] = "Tarih: " . ($match['start_time'] ?? '-');
        // Canlı maç bağlamı: skor + dakika (canlı analiz)
        if (($match['status'] ?? '') === 'live') {
            $skor = ($match['ms_home'] !== null && $match['ms_away'] !== null)
                ? $match['ms_home'] . '-' . $match['ms_away'] : 'bilinmiyor';
            $lines[] = 'DURUM: Maç ŞU AN CANLI OYNANIYOR. Güncel skor: ' . $skor
                . ($match['minute'] ? ', dakika: ' . $match['minute'] : '') . '.';
            $lines[] = 'Analizini mevcut skoru ve kalan süreyi dikkate alarak yap (canlı bahis analizi).';
        }
        $lines[] = '';
        $lines[] = 'Güncel oranlar:';
        foreach ($odds as $market => $val) {
            $lines[] = "  - $market: $val";
        }
        // Tüm marketlerden kısa özet (ilk 12 market) — AI'ya geniş bağlam
        if (!empty($stats['markets']) && is_array($stats['markets'])) {
            $lines[] = '';
            $lines[] = 'Diğer marketler (özet):';
            foreach (array_slice($stats['markets'], 0, 12) as $mk) {
                if (!is_array($mk)) {
                    continue;
                }
                $ops = [];
                foreach (($mk['secenekler'] ?? []) as $o) {
                    $ops[] = ($o['ad'] ?? '?') . '=' . ($o['oran'] ?? '?');
                }
                $lines[] = '  - ' . ($mk['ad'] ?? 'Market') . ': ' . implode(', ', $ops);
            }
        }
        if (!empty($stats['form_home'])) {
            $lines[] = '';
            $lines[] = 'Ev sahibi son maçlar: ' . json_encode($stats['form_home'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($stats['form_away'])) {
            $lines[] = 'Deplasman son maçlar: ' . json_encode($stats['form_away'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($stats['h2h'])) {
            $lines[] = 'Aralarındaki maçlar (H2H): ' . json_encode($stats['h2h'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($stats['standings'])) {
            $lines[] = 'Puan durumu: ' . json_encode($stats['standings'], JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '';
        $lines[] = 'Her market için olasılık ve gerekçe içeren JSON döndür.';
        return implode("\n", $lines);
    }

    private function parseResult(string $text): ?array
    {
        $text = trim($text);
        // ```json ... ``` bloklarını temizle
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $data = json_decode($text, true);
        if (is_array($data) && isset($data['markets'])) {
            return $data;
        }
        // Metin içinde ilk { ... } bloğunu yakalamayı dene
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    private function latestOdds(int $matchId): array
    {
        $out = [];
        foreach (Database::fetchAll('SELECT market, value FROM odds WHERE match_id = ? AND is_latest = 1', [$matchId]) as $r) {
            $out[$r['market']] = (float) $r['value'];
        }
        return $out;
    }

    private function stats(int $matchId): array
    {
        $out = [];
        foreach (Database::fetchAll('SELECT type, data FROM match_stats WHERE match_id = ?', [$matchId]) as $r) {
            $out[$r['type']] = json_decode($r['data'], true);
        }
        return $out;
    }
}
