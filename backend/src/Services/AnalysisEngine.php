<?php

namespace MacRadar\Services;

use MacRadar\Core\Credits;
use MacRadar\Core\Database;
use MacRadar\Core\Settings;
use MacRadar\Services\Llm\LlmFactory;
use MacRadar\Services\NotificationService;

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
            $this->recordPromptLog('full', $matchId, null, $analysisId, $provider, $systemPrompt, $userPrompt, $result, 1, false, $userId);
            $parsed = $this->parseResult($result['text']);
            if ($parsed === null) {
                // Bir kez daha dene (JSON düzeltme talebiyle)
                $retryPrompt = $userPrompt . "\n\nSADECE geçerli JSON döndür, açıklama ekleme.";
                $result = $client->complete($systemPrompt, $retryPrompt);
                $this->recordPromptLog('full', $matchId, null, $analysisId, $provider, $systemPrompt, $retryPrompt, $result, 2, false, $userId);
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

    // ================== MARKET BAŞINA ANALİZ (kredi sistemi) ==================

    /**
     * TEK BİR marketi ayrı bir AI çağrısıyla analiz eder ve market_analyses
     * tablosuna kaydeder (maç+market başına tek satır; canlıda tazelenir).
     * İnternet araştırması (ai_web_search ayarı) açıksa Gemini web grounding
     * kullanılır.
     *
     * @param string $marketKey 'MS' (Maç Sonucu) veya scraped market anahtarı (m_<hash>)
     * @return array market_analyses DB satırı
     */
    public function analyzeMarket(int $matchId, string $marketKey, ?int $userId = null): array
    {
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
        $isLive = ($match['status'] ?? '') === 'live';

        // Önbellek: bitmiş analiz varsa ve tazeyse (canlıda TTL) yeniden üretme
        $existing = Database::fetch(
            "SELECT * FROM market_analyses WHERE match_id = ? AND market_key = ?",
            [$matchId, $marketKey]
        );
        if ($existing && $existing['status'] === 'done' && $this->isMarketAnalysisFresh($existing, $isLive)) {
            return $existing;
        }

        // İstatistik eksikse anlık çekmeyi dene
        $statsCount = (int) (Database::fetch('SELECT COUNT(*) c FROM match_stats WHERE match_id = ?', [$matchId])['c'] ?? 0);
        if ($statsCount === 0) {
            try {
                (new MackolikScraper())->fetchMatchStats($matchId);
            } catch (\Throwable $e) {
                // istatistik alınamazsa mevcut oran/verilerle devam
            }
        }

        $def = $this->resolveMarket($matchId, $marketKey);
        if (!$def) {
            throw new \RuntimeException('Bu maçta böyle bir market bulunamadı.');
        }
        $stats = $this->stats($matchId);
        $provider = LlmFactory::providerName();

        // pending satırı oluştur/tazele
        Database::execute(
            "INSERT INTO market_analyses (match_id, market_key, market_label, is_live, status, provider, created_by, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status='pending', market_label=VALUES(market_label),
                 is_live=VALUES(is_live), provider=VALUES(provider),
                 created_by=VALUES(created_by), error_message=NULL, created_at=NOW()",
            [$matchId, $marketKey, $def['label'], $isLive ? 1 : 0, $provider, $userId]
        );
        $rowId = (int) (Database::fetch(
            'SELECT id FROM market_analyses WHERE match_id = ? AND market_key = ?',
            [$matchId, $marketKey]
        )['id'] ?? 0);

        try {
            $client = LlmFactory::make();
            $web = Credits::webSearchEnabled();
            $systemPrompt = $this->marketSystemPrompt($web);
            $userPrompt = $this->buildMarketPrompt($match, $def, $stats, $web);

            $result = $client->complete($systemPrompt, $userPrompt, ['web_search' => $web]);
            $this->recordPromptLog('market', $matchId, $marketKey, $rowId, $provider, $systemPrompt, $userPrompt, $result, 1, $web, $userId);
            $parsed = $this->parseMarketResult($result['text']);
            if ($parsed === null) {
                $retryPrompt = $userPrompt . "\n\nSADECE geçerli JSON döndür, açıklama ekleme.";
                $result = $client->complete($systemPrompt, $retryPrompt, ['web_search' => $web]);
                $this->recordPromptLog('market', $matchId, $marketKey, $rowId, $provider, $systemPrompt, $retryPrompt, $result, 2, $web, $userId);
                $parsed = $this->parseMarketResult($result['text']);
            }
            if ($parsed === null) {
                throw new \RuntimeException('AI yanıtı geçerli JSON değil.');
            }
            $parsed = $this->annotateMarketResult($parsed, $def);

            Database::execute(
                "UPDATE market_analyses SET status='done', model_name=?, result=?, token_usage=?, created_at=NOW() WHERE id=?",
                [
                    $result['model'],
                    json_encode($parsed, JSON_UNESCAPED_UNICODE),
                    $result['tokens'],
                    $rowId,
                ]
            );
            return Database::fetch('SELECT * FROM market_analyses WHERE id = ?', [$rowId]);
        } catch (\Throwable $e) {
            Database::execute(
                "UPDATE market_analyses SET status='failed', error_message=? WHERE id=?",
                [mb_substr($e->getMessage(), 0, 500), $rowId]
            );
            throw $e;
        }
    }

    /** Analiz taze mi? Maç öncesi kalıcı; canlıda live_analysis_ttl saniye geçerli. */
    public function isMarketAnalysisFresh(array $row, bool $matchIsLive): bool
    {
        if (!$matchIsLive) {
            return true;
        }
        $age = time() - strtotime((string) $row['created_at']);
        return $age <= Credits::liveTtl();
    }

    /**
     * Market anahtarını maçın gerçek marketine çözer.
     * 'MS' → odds tablosundaki MS1/MSX/MS2; diğerleri scraped market listesinden.
     * @return array{key:string,label:string,group:string,options:array}|null
     */
    public function resolveMarket(int $matchId, string $marketKey): ?array
    {
        if ($marketKey === 'MS') {
            $odds = $this->latestOdds($matchId);
            $names = ['MS1' => 'Ev sahibi kazanır (1)', 'MSX' => 'Beraberlik (X)', 'MS2' => 'Deplasman kazanır (2)'];
            $opts = [];
            foreach ($names as $kod => $ad) {
                if (isset($odds[$kod])) {
                    $opts[] = ['kod' => $kod, 'ad' => $ad, 'oran' => (float) $odds[$kod]];
                }
            }
            if (!$opts) {
                return null;
            }
            return [
                'key' => 'MS',
                'label' => Credits::displayMarketName('Maç Sonucu'),
                'group' => 'ana',
                'mtid' => 1,
                'options' => $opts,
            ];
        }

        $stats = $this->stats($matchId);
        foreach (($stats['markets'] ?? []) as $mk) {
            if (!is_array($mk)) {
                continue;
            }
            $name = (string) ($mk['ad'] ?? '');
            $key = Credits::marketKeyFor($name, $mk['sov'] ?? null);
            if ($key !== $marketKey) {
                continue;
            }
            $opts = [];
            foreach (($mk['secenekler'] ?? []) as $o) {
                $ad = (string) ($o['ad'] ?? '?');
                $opts[] = [
                    'kod' => $ad,
                    'ad' => $ad,
                    'oran' => isset($o['oran']) ? (float) $o['oran'] : null,
                ];
            }
            if (!$opts) {
                return null;
            }
            // Market adı çizgiyi zaten içeriyorsa (ör. "Handikaplı Maç Sonucu (-1,0)")
            // tekrar eklemeyelim.
            $sov = $mk['sov'] ?? null;
            $display = Credits::displayMarketName($name);
            $sovTxt = ($sov !== null && $sov !== '') ? (string) $sov : '';
            $label = ($sovTxt !== '' && strpos($display, $sovTxt) === false)
                ? $display . " ($sovTxt)"
                : $display;
            return [
                'key' => $key,
                'label' => $label,
                'group' => Credits::groupKeyForMarketName($name),
                'mtid' => isset($mk['mtid']) ? (int) $mk['mtid'] : null,
                'options' => $opts,
            ];
        }
        return null;
    }

    private function marketSystemPrompt(bool $webSearch): string
    {
        $research = $webSearch
            ? "İNTERNET ARAŞTIRMASI: Elindeki arama aracıyla bu maç ve iki takım hakkında GÜNCEL bilgileri araştır: "
              . "sakat ve cezalı oyuncular, muhtemel 11'ler, teknik direktör durumu, son haberler, motivasyon, hava durumu. "
              . "Bulduğun somut bilgileri analizde kullan ve 'kaynaklar' alanında 1-3 kısa madde olarak özetle. "
              . "Doğrulayamadığın bilgiyi uydurma.\n\n"
            : "Güncel sakatlık/kadro bilgisinden emin değilsen tahmin uydurma; genel kadro gücü üzerinden değerlendir.\n\n";

        return "Sen deneyimli, profesyonel bir futbol bahis analistisin. Görevin, sana verilen maç için "
            . "TEK BİR bahis marketini derinlemesine analiz etmek: her seçeneğe gerçekçi olasılık ve kısa, "
            . "veriye dayalı gerekçe vermek, en mantıklı seçeneği önermek.\n\n"
            . $research
            . "Analizinde şunları hesaba kat: takım formları, H2H geçmişi, puan durumu, kadro/sakatlık durumu, "
            . "maçın bağlamı (derbi, küme/şampiyonluk baskısı, fikstür yoğunluğu), canlıysa güncel skor/dakika "
            . "ve oranların ima ettiği olasılıklarla kendi olasılıkların arasındaki fark (değer fırsatı).\n\n"
            . "Yanıtını YALNIZCA şu JSON şemasında ver:\n"
            . '{"secenekler":[{"kod":"MS1","olasilik":45,"gerekce":"tek cümlelik somut gerekçe"}],'
            . '"tavsiye":"MS1","guven":7,"ozet":"marketin 2-3 cümlelik değerlendirmesi","kaynaklar":["..."]}'
            . "\n\nKURALLAR:\n"
            . "- 'kod' alanına sana verilen seçenek kodunu AYNEN yaz; listede olmayan seçenek uydurma.\n"
            . "- Marketin TÜM seçeneklerine olasılık ver; olasılıkların toplamı ~100 olsun ('olasilik' 0-100 tam sayı).\n"
            . "- 'tavsiye': en mantıklı bulduğun seçeneğin kodu.\n"
            . "- 'guven': analizine güvenin, 1-10 tam sayı.\n"
            . "- 'gerekce' tek cümle, somut ve veriye dayalı olsun ('form iyi' gibi boş laf değil).\n"
            . "- 'kaynaklar': internetten bulduğun önemli güncel bilgiler (yoksa boş dizi).\n"
            . "- Abartma, veriye sadık kal; belirsizliği dürüstçe belirt.";
    }

    private function buildMarketPrompt(array $match, array $def, array $stats, bool $webSearch): string
    {
        $custom = Settings::get('analysis_prompt');
        $header = $custom ?: 'Aşağıdaki maçın belirtilen marketini analiz et:';

        $lines = [$header, ''];
        $lines[] = "Maç: {$match['home_name']} vs {$match['away_name']}";
        $lines[] = 'Lig: ' . ($match['league_name'] ?? '-');
        $lines[] = 'Tarih: ' . ($match['start_time'] ?? '-');
        if (($match['status'] ?? '') === 'live') {
            $skor = ($match['ms_home'] !== null && $match['ms_away'] !== null)
                ? $match['ms_home'] . '-' . $match['ms_away'] : 'bilinmiyor';
            $lines[] = 'DURUM: Maç ŞU AN CANLI OYNANIYOR. Güncel skor: ' . $skor
                . ($match['minute'] ? ', dakika: ' . $match['minute'] : '') . '.';
            $lines[] = 'Analizini mevcut skoru ve kalan süreyi dikkate alarak yap (canlı bahis analizi).';
        }
        $lines[] = '';
        $lines[] = 'ANALİZ EDİLECEK MARKET: ' . $def['label'];
        $lines[] = 'Seçenekler (kod → oran):';
        foreach ($def['options'] as $o) {
            $lines[] = '  - ' . $o['kod'] . ' (' . $o['ad'] . '): ' . ($o['oran'] ?? '?');
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
        if (!empty($stats['injuries'])) {
            $lines[] = 'Sakat/cezalı oyuncular (veri): ' . json_encode($stats['injuries'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($stats['live'])) {
            $lines[] = 'Canlı istatistikler: ' . json_encode($stats['live'], JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '';
        $lines[] = 'YALNIZCA bu marketi analiz et. '
            . ($webSearch ? 'Önce internette bu maçla ilgili güncel bilgileri araştır, sonra ' : '')
            . 'her seçenek için olasılık + gerekçe içeren JSON döndür.';
        return implode("\n", $lines);
    }

    /** Tek market yanıtını çözümle: {"secenekler":[...]} bekler. */
    private function parseMarketResult(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $data = json_decode($text, true);
        if (is_array($data) && isset($data['secenekler'])) {
            return $data;
        }
        // Metin içinde ilk { ... } bloğunu yakala (web aramalı yanıtlar düz metin gelebilir)
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data) && isset($data['secenekler'])) {
                return $data;
            }
        }
        return null;
    }

    /** Seçeneklere oran/ad ekle ve değer bahsi (value bet) hesapla. */
    private function annotateMarketResult(array $parsed, array $def): array
    {
        $byKod = [];
        foreach ($def['options'] as $o) {
            $byKod[$o['kod']] = $o;
        }
        $out = [];
        foreach (($parsed['secenekler'] ?? []) as $s) {
            if (!is_array($s) || !isset($s['kod'])) {
                continue;
            }
            $kod = (string) $s['kod'];
            $defOpt = $byKod[$kod] ?? null;
            if (!$defOpt) {
                continue; // uydurulan seçenekleri at
            }
            $oran = $defOpt['oran'];
            $s['ad'] = $defOpt['ad'];
            $s['oran'] = $oran;
            $aiProb = isset($s['olasilik']) ? (float) $s['olasilik'] : null;
            if ($oran && $oran > 0 && $aiProb !== null) {
                $implied = round(100 / $oran, 1);
                $s['implied_olasilik'] = $implied;
                $s['deger_var_mi'] = $aiProb > $implied + 3;
                $s['deger_farki'] = round($aiProb - $implied, 1);
            }
            $out[] = $s;
        }
        $parsed['secenekler'] = $out;
        $parsed['market_key'] = $def['key'];
        $parsed['market_label'] = $def['label'];
        return $parsed;
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
        return "Sen deneyimli, profesyonel bir futbol bahis analistisin. Görevin, sana verilen maç için "
            . "LİSTELENEN TÜM BAHİS MARKETLERİNİ tek tek analiz edip her seçenek için gerçekçi bir olasılık "
            . "ve kısa, veriye dayalı bir gerekçe üretmek.\n\n"
            . "Analizinde ŞUNLARIN TAMAMINI hesaba kat:\n"
            . "1) Verilen istatistikler: takım formları, aralarındaki geçmiş maçlar (H2H), puan durumu, canlı skor/dakika.\n"
            . "2) KENDİ BİLGİN dahilinde bu iki takım hakkında bildiklerin: kadro kalitesi, sakat ve cezalı oyuncular, "
            . "teknik direktör durumu, son dönem performansı, iç saha/deplasman karakteri. Güncel sakatlık bilgisinden "
            . "emin değilsen tahmin uydurma; genel kadro gücü üzerinden değerlendir.\n"
            . "3) Maçın bağlamı: lig sıralaması etkisi, şampiyonluk/küme düşme baskısı, derbi/rekabet, sezon dönemi, "
            . "fikstür yoğunluğu ve olası rotasyon.\n"
            . "4) Oranların ima ettiği olasılıklar (1/oran) ile kendi olasılıkların arasındaki fark (değer fırsatları).\n\n"
            . "Yanıtını YALNIZCA şu JSON şemasında ver:\n"
            . '{"markets":['
            . '{"market":"MS1","oran":2.10,"olasilik":45,"gerekce":"..."},'
            . '{"market":"Toplam Gol Aralığı","secenek":"2-3 Gol","oran":1.95,"olasilik":48,"gerekce":"..."}],'
            . '"genel_analiz":"maçın 3-5 cümlelik bütünsel değerlendirmesi",'
            . '"en_guvenli_tahmin":"MS1","surpriz_potansiyeli":"dusuk|orta|yuksek","riskli":false,'
            . '"guven":7,"nedenler":[{"etiket":"Form farkı","metin":"Ev sahibi son 5 maçta 13 puan topladı."}]}'
            . "\n\nKURALLAR:\n"
            . "- Standart marketlerde şu kodları kullan (secenek alanı gerekmez): MS1,MSX,MS2 (Maç Sonucu), "
            . "CS1X,CS12,CSX2 (Çifte Şans), ALT15,UST15,ALT25,UST25,ALT35,UST35 (Gol Alt/Üst), KGVAR,KGYOK (Karşılıklı Gol), "
            . "IY1,IYX,IY2 (İlk Yarı Sonucu).\n"
            . "- DİĞER TÜM marketlerde 'market' alanına marketin verilen adını AYNEN, 'secenek' alanına seçeneğin adını AYNEN yaz "
            . "ve verilen oranı 'oran' alanına koy.\n"
            . "- Sana listelenen HER market için değerlendirme yap; her marketin TÜM seçeneklerine olasılık ver.\n"
            . "- MS1+MSX+MS2 toplamı ~100 olsun; aynı marketin seçeneklerinin olasılık toplamı da ~100 olsun.\n"
            . "- 'olasilik' 0-100 arası tam sayı. Oranı verilmeyen market uydurma.\n"
            . "- HIZ İÇİN ÖNEMLİ: 'gerekce' alanını YALNIZCA şu seçeneklerde yaz: MS1/MSX/MS2, değer tespit ettiğin "
            . "seçenekler ve en güvenli tahminin. DİĞER tüm seçeneklerde 'gerekce' alanını HİÇ KOYMA "
            . "(sadece market/secenek/oran/olasilik). Yazdığın gerekçeler tek cümle, somut ve veriye dayalı olsun "
            . "('form iyi' gibi boş laf değil).\n"
            . "- 'guven': analizine olan güvenin (veri kalitesi + netlik), 1-10 arası tam sayı.\n"
            . "- 'nedenler': 4-6 madde; form, H2H, sakat/cezalı ve kadro durumu, motivasyon/bağlam ve oran değeri "
            . "başlıklarını kapsasın. Her madde {etiket, metin}; metin 1-2 cümlelik somut açıklama.\n"
            . "- Abartma, veriye sadık kal; belirsizliği dürüstçe belirt.";
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
        $lines[] = 'Ana marketler (kod: oran):';
        foreach ($odds as $market => $val) {
            $lines[] = "  - $market: $val";
        }
        // TÜM marketler — hepsi analiz edilecek
        if (!empty($stats['markets']) && is_array($stats['markets'])) {
            $lines[] = '';
            $lines[] = 'ANALİZ EDİLECEK TÜM MARKETLER (market adı → seçenek=oran):';
            foreach ($stats['markets'] as $mk) {
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
        if (!empty($stats['injuries'])) {
            $lines[] = 'Sakat/cezalı oyuncular (veri): ' . json_encode($stats['injuries'], JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '';
        $lines[] = 'Yukarıda listelenen TÜM marketleri analiz et. Takımlar hakkında kendi bilgini de kullan '
            . '(kadro gücü, sakat/cezalı oyuncular, teknik direktör, motivasyon); emin olmadığın güncel bilgiyi uydurma. '
            . 'Her market ve seçenek için olasılık + gerekçe içeren JSON döndür.';
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

    /**
     * Bir AI analiz çağrısını (gönderilen prompt + dönen yanıt) ai_prompt_logs
     * tablosuna kaydeder. Loglama hiçbir koşulda analizi bozmamalı; bu yüzden
     * tüm hatalar sessizce yutulur.
     *
     * @param string   $type       'full' | 'market'
     * @param array    $result      LLM istemcisinin döndürdüğü ['text','model','tokens']
     */
    private function recordPromptLog(
        string $type,
        ?int $matchId,
        ?string $marketKey,
        ?int $analysisId,
        string $provider,
        string $systemPrompt,
        string $userPrompt,
        array $result,
        int $attempt,
        bool $webSearch,
        ?int $userId
    ): void {
        try {
            Database::insert(
                'INSERT INTO ai_prompt_logs
                    (analysis_type, match_id, market_key, analysis_id, provider, model_name,
                     system_prompt, user_prompt, response_text, token_usage, attempt, web_search, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $type,
                    $matchId,
                    $marketKey,
                    $analysisId,
                    $provider,
                    $result['model'] ?? null,
                    $systemPrompt,
                    $userPrompt,
                    $result['text'] ?? null,
                    $result['tokens'] ?? null,
                    $attempt,
                    $webSearch ? 1 : 0,
                    $userId,
                ]
            );
        } catch (\Throwable $e) {
            // Loglama başarısız olsa bile analiz akışı etkilenmez.
        }
    }

    // ================== TOPLU ANALİZ (tek AI çağrısı, tüm marketler) ==================

    /** Maçın tüm market tanımları: MS + scraped marketler. [market_key => def] */
    public function allMarketDefs(int $matchId): array
    {
        $defs = [];
        $ms = $this->resolveMarket($matchId, 'MS');
        if ($ms) {
            $defs['MS'] = $ms;
        }
        $stats = $this->stats($matchId);
        foreach (($stats['markets'] ?? []) as $mk) {
            if (!is_array($mk)) {
                continue;
            }
            $key = Credits::marketKeyFor((string) ($mk['ad'] ?? ''), $mk['sov'] ?? null);
            if (isset($defs[$key])) {
                continue;
            }
            $def = $this->resolveMarket($matchId, $key);
            if ($def) {
                $defs[$def['key']] = $def;
            }
        }
        return $defs;
    }

    /** Bir marketin grup anahtarı ('ana'/'gol'/'handikap'/'ozel'). MS → ana. */
    public function groupOfMarket(int $matchId, string $marketKey): string
    {
        if ($marketKey === 'MS') {
            return 'ana';
        }
        $def = $this->resolveMarket($matchId, $marketKey);
        return $def['group'] ?? 'ozel';
    }

    /**
     * Marketin fiyatlandırma bilgisi: ['group' => ..., 'mtid' => ...].
     * Bilinmeyen markette 'ozel' grubu varsayılır.
     */
    public function marketMeta(int $matchId, string $marketKey): array
    {
        if ($marketKey === 'MS') {
            return ['group' => 'ana', 'mtid' => 1];
        }
        $def = $this->resolveMarket($matchId, $marketKey);
        return [
            'group' => $def['group'] ?? 'ozel',
            'mtid' => $def['mtid'] ?? null,
        ];
    }

    /**
     * Analize gönderilecek en fazla seçenek sayısı. Oyuncu bazlı marketler
     * (ör. "Oyuncu Şut Çeker" — 99 seçenek) prompt'u aşırı şişirir ve AI'ın
     * oyuncu bazında güvenilir verisi yoktur; bu marketler analiz dışı bırakılır,
     * oranları uygulamada yine görünür.
     */
    private function maxMarketOptions(): int
    {
        return max(2, (int) Settings::get('ai_max_market_options', 24));
    }

    /**
     * Maçın TÜM marketlerini TEK AI çağrısıyla analiz eder ve her marketi
     * market_analyses tablosuna 'done' olarak yazar (hız + maliyet için).
     * @return int üretilen (done) market sayısı
     */
    public function analyzeAllMarkets(int $matchId, ?int $userId = null): int
    {
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
        $isLive = ($match['status'] ?? '') === 'live';

        // İstatistik eksikse anlık çek
        $statsCount = (int) (Database::fetch('SELECT COUNT(*) c FROM match_stats WHERE match_id = ?', [$matchId])['c'] ?? 0);
        if ($statsCount === 0) {
            try {
                (new MackolikScraper())->fetchMatchStats($matchId);
            } catch (\Throwable $e) {
                // veri yoksa mevcutla devam
            }
        }

        $defs = $this->allMarketDefs($matchId);
        // Çok seçenekli marketleri (oyuncu bazlı vb.) analiz dışı bırak
        $maxOpts = $this->maxMarketOptions();
        $skipped = 0;
        foreach ($defs as $k => $d) {
            if (count($d['options']) > $maxOpts) {
                unset($defs[$k]);
                $skipped++;
            }
        }
        if (!$defs) {
            return 0;
        }
        if ($skipped > 0) {
            ScrapeLogger::log('analyze_markets', 'success',
                "Maç #$matchId: $skipped market seçenek sayısı sınırını ($maxOpts) aştığı için analiz dışı bırakıldı.",
                $skipped, null);
        }
        $stats = $this->stats($matchId);
        $provider = LlmFactory::providerName();

        // Tüm marketleri 'pending' işaretle (mevcut done olanları koru)
        foreach ($defs as $def) {
            Database::execute(
                "INSERT INTO market_analyses (match_id, market_key, market_label, is_live, status, provider, created_by, created_at)
                 VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE market_label=VALUES(market_label), is_live=VALUES(is_live),
                     provider=VALUES(provider), status=IF(status='done','done','pending'),
                     created_by=VALUES(created_by), error_message=NULL",
                [$matchId, $def['key'], $def['label'], $isLive ? 1 : 0, $provider, $userId]
            );
        }

        try {
            $client = LlmFactory::make();
            $web = Credits::webSearchEnabled();
            $systemPrompt = $this->multiSystemPrompt($web);
            $userPrompt = $this->buildMultiPrompt($match, $defs, $stats, $web, $isLive);

            $result = $client->complete($systemPrompt, $userPrompt, ['web_search' => $web]);
            $this->recordPromptLog('match', $matchId, null, null, $provider, $systemPrompt, $userPrompt, $result, 1, $web, $userId);
            $byKey = $this->parseMultiResult($result['text']);
            if ($byKey === null) {
                $retry = $userPrompt . "\n\nSADECE geçerli JSON döndür, açıklama ekleme.";
                $result = $client->complete($systemPrompt, $retry, ['web_search' => $web]);
                $this->recordPromptLog('match', $matchId, null, null, $provider, $systemPrompt, $retry, $result, 2, $web, $userId);
                $byKey = $this->parseMultiResult($result['text']);
            }
            if ($byKey === null) {
                throw new \RuntimeException('AI yanıtı geçerli JSON değil.');
            }

            $model = $result['model'];
            $tokens = $result['tokens'];
            $done = 0;
            $msParsed = null;
            foreach ($defs as $key => $def) {
                $mkIn = $byKey[$key] ?? null;
                if (!is_array($mkIn)) {
                    Database::execute(
                        "UPDATE market_analyses SET status='failed', error_message='AI bu marketi döndürmedi' WHERE match_id=? AND market_key=?",
                        [$matchId, $key]
                    );
                    continue;
                }
                $mkIn['secenekler'] = $mkIn['secenekler'] ?? [];
                $parsed = $this->annotateMarketResult($mkIn, $def);
                if ($key === 'MS') {
                    $msParsed = $parsed;
                }
                Database::execute(
                    "UPDATE market_analyses SET status='done', model_name=?, result=?, token_usage=?, error_message=NULL, created_at=NOW()
                     WHERE match_id=? AND market_key=?",
                    [$model, json_encode($parsed, JSON_UNESCAPED_UNICODE), $tokens, $matchId, $key]
                );
                $done++;
            }

            if ($done > 0) {
                $this->notifyWaiters($matchId, $match, $msParsed);
            }
            return $done;
        } catch (\Throwable $e) {
            Database::execute(
                "UPDATE market_analyses SET status='failed', error_message=? WHERE match_id=? AND status='pending'",
                [mb_substr($e->getMessage(), 0, 500), $matchId]
            );
            $this->notifyFailure($matchId, $match);
            throw $e;
        }
    }

    /**
     * Analiz hazır olduğunda maçı bekleyen kullanıcılara bildirim düşer.
     * "Bekleyen" = son 6 saat içinde bu maçı görüntülemiş kullanıcılar
     * (analiz talebi geçmişe kayıt düştüğü için talep edenler buraya dahildir).
     */
    private function notifyWaiters(int $matchId, array $match, $msResult): void
    {
        if ((string) Settings::get('notify_on_analysis_ready', '1') !== '1') {
            return;
        }
        $name = trim((string) ($match['home_name'] ?? '') . ' - ' . (string) ($match['away_name'] ?? ''), ' -');
        if ($name === '') {
            $name = 'Maç';
        }

        // MS analizinden kısa bir özet çıkar (bildirimde göstermek için)
        $summary = [];
        if (is_array($msResult)) {
            $tavsiye = (string) ($msResult['tavsiye'] ?? '');
            if ($tavsiye !== '') {
                $label = $tavsiye;
                $pct = null;
                foreach (($msResult['secenekler'] ?? []) as $s) {
                    if (is_array($s) && (string) ($s['kod'] ?? '') === $tavsiye) {
                        $label = (string) ($s['ad'] ?? $tavsiye);
                        $pct = isset($s['olasilik']) ? (int) $s['olasilik'] : null;
                        break;
                    }
                }
                $summary = ['tavsiye' => $label, 'olasilik' => $pct, 'kod' => $tavsiye];
            }
        }

        try {
            $rows = Database::fetchAll(
                'SELECT user_id FROM user_analysis_history
                 WHERE match_id = ? AND last_viewed_at >= NOW() - INTERVAL 6 HOUR',
                [$matchId]
            );
        } catch (\Throwable $e) {
            return;
        }
        foreach ($rows as $r) {
            NotificationService::analysisReady((int) $r['user_id'], $matchId, $name, $summary);
        }
    }

    /** Analiz üretilemediğinde bekleyenleri bilgilendirir (kredi düşmemiştir). */
    private function notifyFailure(int $matchId, array $match): void
    {
        if ((string) Settings::get('notify_on_analysis_ready', '1') !== '1') {
            return;
        }
        $name = trim((string) ($match['home_name'] ?? '') . ' - ' . (string) ($match['away_name'] ?? ''), ' -');
        try {
            $rows = Database::fetchAll(
                'SELECT user_id FROM user_analysis_history
                 WHERE match_id = ? AND last_viewed_at >= NOW() - INTERVAL 1 HOUR',
                [$matchId]
            );
        } catch (\Throwable $e) {
            return;
        }
        foreach ($rows as $r) {
            NotificationService::push(
                (int) $r['user_id'],
                NotificationService::TYPE_FAILED,
                ($name !== '' ? $name : 'Maç') . ' analizi tamamlanamadı',
                'Yapay zeka şu an yanıt veremedi. Krediniz düşmedi; birazdan tekrar deneyebilirsiniz.',
                $matchId
            );
        }
    }

    /** Çoklu-market yanıtını çözümle: {"marketler":[{market_key,...}]} → [key => data] */
    private function parseMultiResult(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $data = json_decode($text, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }
        if (!is_array($data)) {
            return null;
        }
        $markets = $data['marketler'] ?? $data['markets'] ?? ($data['analizler'][0]['marketler'] ?? null);
        if (!is_array($markets)) {
            return null;
        }
        $byKey = [];
        foreach ($markets as $mk) {
            if (is_array($mk) && !empty($mk['market_key'])) {
                $byKey[(string) $mk['market_key']] = $mk;
            }
        }
        return $byKey ?: null;
    }

    private function multiSystemPrompt(bool $webSearch): string
    {
        $research = $webSearch
            ? "İNTERNET ARAŞTIRMASI: Arama aracıyla bu maç ve iki takım hakkında GÜNCEL bilgileri araştır "
              . "(sakat/cezalı, muhtemel 11'ler, teknik direktör, son haberler, motivasyon, hava). Bulguları analizde "
              . "kullan ve 'kaynaklar' alanında özetle. Doğrulayamadığını uydurma.\n\n"
            : "Güncel sakatlık/kadro bilgisinden emin değilsen uydurma; genel kadro gücü üzerinden değerlendir.\n\n";

        return "Sen deneyimli, profesyonel bir futbol bahis analistisin. Sana BİR maç ve o maçın birden çok bahis "
            . "marketi (market_key, seçenek kodları ve oranlar) verilecek. Görevin, LİSTELENEN TÜM MARKETLERİ tek tek "
            . "DERİNLEMESİNE analiz edip her seçenek için gerçekçi olasılık ve somut, veriye dayalı gerekçe üretmek. "
            . "Hedef kitle iddaa oynayan deneyimli kullanıcılar; yüzeysel yorum istemiyorlar.\n\n"
            . $research
            . "Hesaba kat: form, H2H, puan durumu, kadro/sakatlık, iç saha/deplasman karakteri, maçın bağlamı, "
            . "canlıysa güncel skor/dakika ve oranların ima ettiği olasılık (1/oran) ile kendi olasılığın farkı (değer fırsatı).\n\n"
            . "Yanıtını YALNIZCA şu JSON şemasında ver (başka hiçbir metin ekleme):\n"
            . '{"marketler":[{"market_key":"MS","secenekler":[{"kod":"MS1","olasilik":45,"gerekce":"1-2 cümle somut gerekçe"}],'
            . '"tavsiye":"MS1","guven":7,"ozet":"3-5 cümlelik değerlendirme + değer fırsatı yorumu","kaynaklar":["kısa madde"]}]}'
            . "\n\nKURALLAR:\n"
            . "- 'market_key' değerini köşeli parantez içindeki anahtarla AYNEN yaz; HER market için bir nesne döndür.\n"
            . "- 'kod' alanına verilen seçenek kodunu AYNEN yaz; listede olmayan seçenek uydurma.\n"
            . "- Her marketin TÜM seçeneklerine olasılık ver; aynı marketin toplamı ~100 olsun ('olasilik' 0-100 tam sayı).\n"
            . "- 'tavsiye': o markette en mantıklı seçeneğin kodu. 'guven': 1-10 tam sayı.\n"
            . "- 'gerekce': 1-2 cümle, somut ve veriye dayalı ('form iyi' gibi boş laf yasak). 'ozet': 3-5 cümle; en olası "
            . "senaryoyu, riskleri ve oranın değerli olup olmadığını açıkça yaz. 'kaynaklar' bilmiyorsan boş dizi.\n"
            . "- Adı 'Market #...' olan pazarların türü belirsizdir; bunlarda yalnızca oran yapısına dayalı temkinli bir "
            . "değerlendirme yap ve ozet içinde bunu belirt.\n"
            . "- HER marketi analiz et; hiçbirini atlama.";
    }

    private function buildMultiPrompt(array $match, array $defs, array $stats, bool $webSearch, bool $isLive): string
    {
        $custom = Settings::get('analysis_prompt');
        $lines = [];
        $lines[] = $custom ?: 'Aşağıdaki maçın TÜM listelenen bahis marketlerini analiz et:';
        $lines[] = '';
        $lines[] = "Maç: {$match['home_name']} vs {$match['away_name']}";
        $lines[] = 'Lig: ' . ($match['league_name'] ?? '-') . ' | Tarih: ' . ($match['start_time'] ?? '-');
        if ($isLive) {
            $skor = ($match['ms_home'] !== null && $match['ms_away'] !== null)
                ? $match['ms_home'] . '-' . $match['ms_away'] : 'bilinmiyor';
            $lines[] = 'DURUM: Maç ŞU AN CANLI OYNANIYOR. Güncel skor: ' . $skor
                . ($match['minute'] ? ', dakika: ' . $match['minute'] : '') . '.';
        }
        $lines[] = '';
        $lines[] = 'ANALİZ EDİLECEK MARKETLER ([market_key] ad: kod (açıklama)=oran):';
        foreach ($defs as $def) {
            $ops = [];
            foreach ($def['options'] as $o) {
                $ad = ((string) $o['ad'] !== (string) $o['kod']) ? ' (' . $o['ad'] . ')' : '';
                $ops[] = $o['kod'] . $ad . '=' . ($o['oran'] ?? '?');
            }
            $lines[] = '- [' . $def['key'] . '] ' . $def['label'] . ': ' . implode(', ', $ops);
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
        if (!empty($stats['injuries'])) {
            $lines[] = 'Sakat/cezalı oyuncular: ' . json_encode($stats['injuries'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($stats['live'])) {
            $lines[] = 'Canlı istatistikler: ' . json_encode($stats['live'], JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '';
        $lines[] = 'HER marketi analiz et; hiçbirini atlama. '
            . ($webSearch ? 'Önce internetten güncel bilgi araştır, sonra ' : '')
            . 'her seçenek için olasılık + gerekçe içeren, istenen JSON şemasında yanıt ver. SADECE JSON döndür.';
        return implode("\n", $lines);
    }
}
