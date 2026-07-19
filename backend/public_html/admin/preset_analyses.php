<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Credits;
use MacRadar\Core\Database;
use MacRadar\Core\Settings;
use MacRadar\Services\AnalysisEngine;
use MacRadar\Services\Llm\LlmFactory;

$engine = new AnalysisEngine();

/** Ayarlardaki aktif sağlayıcının model adı (kayıtlar gerçek AI üretimi gibi görünsün). */
function preset_model_name(): string
{
    $provider = LlmFactory::providerName();
    return $provider === 'gemini'
        ? (string) Settings::get('gemini_model', 'gemini-1.5-flash')
        : (string) Settings::get('openai_model', 'gpt-4o-mini');
}

/** Bir maçın tüm market tanımları: MS + scraped marketler (market_key => def). */
function preset_match_markets(AnalysisEngine $engine, int $matchId): array
{
    $defs = [];
    $ms = $engine->resolveMarket($matchId, 'MS');
    if ($ms) {
        $defs['MS'] = $ms;
    }
    $row = Database::fetch("SELECT data FROM match_stats WHERE match_id = ? AND type = 'markets'", [$matchId]);
    $markets = $row ? json_decode($row['data'], true) : [];
    foreach ((array) $markets as $mk) {
        if (!is_array($mk)) {
            continue;
        }
        $key = Credits::marketKeyFor((string) ($mk['ad'] ?? ''), $mk['sov'] ?? null);
        if (isset($defs[$key])) {
            continue;
        }
        $def = $engine->resolveMarket($matchId, $key);
        if ($def) {
            $defs[$def['key']] = $def;
        }
    }
    return $defs;
}

// ==================== POST İŞLEMLERİ ====================
$report = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // ---- Manuel mod aç/kapat ----
    if (isset($_POST['manual_mode'])) {
        Settings::set('manual_analysis_mode', $_POST['manual_mode'] === '1' ? '1' : '0');
        flash('Manuel mod ' . ($_POST['manual_mode'] === '1' ? 'AÇILDI: AI sağlayıcısına artık istek gitmeyecek.' : 'kapatıldı.'));
        header('Location: preset_analyses.php');
        exit;
    }

    // ---- Bir maçın hazır analizlerini sil ----
    if (isset($_POST['del_mid'])) {
        $n = Database::execute('DELETE FROM market_analyses WHERE match_id = ?', [(int) $_POST['del_mid']]);
        flash("Maç #" . (int) $_POST['del_mid'] . " için $n market analizi silindi.");
        header('Location: preset_analyses.php');
        exit;
    }

    // ---- JSON kaydet ----
    $raw = '';
    if (!empty($_POST['json_b64'])) {
        $dec = base64_decode((string) $_POST['json_b64'], false);
        if ($dec !== false) {
            $raw = $dec;
        }
    }
    if ($raw === '' && isset($_POST['json'])) {
        $raw = (string) $_POST['json'];
    }
    $raw = trim($raw);
    // Temizlik: BOM, bölünmez boşluk, akıllı tırnaklar, ```json çitleri
    $raw = str_replace("\xEF\xBB\xBF", '', $raw);
    $raw = str_replace("\xC2\xA0", ' ', $raw);
    $raw = str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9E"], '"', $raw);
    $raw = str_replace(["\xE2\x80\x98", "\xE2\x80\x99"], "'", $raw);
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw = preg_replace('/\s*```$/', '', $raw);

    $data = json_decode($raw, true);
    $list = null;
    if (is_array($data)) {
        $list = $data['analizler'] ?? (isset($data[0]) ? $data : null);
    }
    if (!is_array($list)) {
        $report[] = ['danger', 'JSON çözümlenemedi (' . json_last_error_msg() . '). Gelen verinin ilk 150 karakteri: '
            . mb_substr($raw, 0, 150)];
    } else {
        $provider = LlmFactory::providerName();
        $model = preset_model_name();
        $okTotal = 0;
        foreach ($list as $item) {
            if (!is_array($item) || empty($item['match_id'])) {
                $report[] = ['warning', 'match_id içermeyen bir öğe atlandı.'];
                continue;
            }
            $mid = (int) $item['match_id'];
            $match = Database::fetch('SELECT id, status FROM matches WHERE id = ?', [$mid]);
            if (!$match) {
                $report[] = ['warning', "Maç #$mid bulunamadı, atlandı."];
                continue;
            }
            $isLive = ($match['status'] ?? '') === 'live';
            $markets = $item['marketler'] ?? $item['markets'] ?? [];
            $okMarket = 0;
            foreach ((array) $markets as $mkIn) {
                if (!is_array($mkIn) || empty($mkIn['market_key'])) {
                    continue;
                }
                $key = (string) $mkIn['market_key'];
                try {
                    $def = $engine->resolveMarket($mid, $key);
                    if (!$def) {
                        $report[] = ['warning', "Maç #$mid: '$key' marketi maçta bulunamadı, atlandı."];
                        continue;
                    }
                    // Seçeneklere oran/ad ekle + değer bahsi hesabı (AI akışıyla birebir aynı biçim)
                    $byKod = [];
                    foreach ($def['options'] as $o) {
                        $byKod[$o['kod']] = $o;
                    }
                    $out = [];
                    foreach ((array) ($mkIn['secenekler'] ?? []) as $s) {
                        if (!is_array($s) || !isset($s['kod']) || !isset($byKod[(string) $s['kod']])) {
                            continue;
                        }
                        $defOpt = $byKod[(string) $s['kod']];
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
                    if (!$out) {
                        $report[] = ['warning', "Maç #$mid / {$def['label']}: geçerli seçenek yok, atlandı."];
                        continue;
                    }
                    $result = [
                        'secenekler' => $out,
                        'tavsiye' => $mkIn['tavsiye'] ?? null,
                        'guven' => isset($mkIn['guven']) ? (int) $mkIn['guven'] : null,
                        'ozet' => $mkIn['ozet'] ?? null,
                        'kaynaklar' => is_array($mkIn['kaynaklar'] ?? null) ? array_values($mkIn['kaynaklar']) : [],
                        'market_key' => $def['key'],
                        'market_label' => $def['label'],
                    ];
                    Database::execute(
                        "INSERT INTO market_analyses (match_id, market_key, market_label, is_live, status, provider, model_name, result, error_message, created_by, created_at)
                         VALUES (?, ?, ?, ?, 'done', ?, ?, ?, NULL, NULL, NOW())
                         ON DUPLICATE KEY UPDATE status='done', market_label=VALUES(market_label), is_live=VALUES(is_live),
                             provider=VALUES(provider), model_name=VALUES(model_name), result=VALUES(result),
                             error_message=NULL, created_at=NOW()",
                        [$mid, $def['key'], $def['label'], $isLive ? 1 : 0, $provider, $model, json_encode($result, JSON_UNESCAPED_UNICODE)]
                    );
                    $okMarket++;
                    $okTotal++;
                } catch (\Throwable $ex) {
                    $report[] = ['danger', "Maç #$mid / '$key': kayıt hatası — " . $ex->getMessage()];
                }
            }
            $report[] = ['success', "Maç #$mid: $okMarket market analizi kaydedildi."];
        }
        $report[] = [$okTotal ? 'success' : 'danger', "TOPLAM: $okTotal market analizi kaydedildi."];
    }
}

// ==================== PROMPT OLUŞTUR ====================
$mids = array_values(array_unique(array_map('intval', (array) ($_GET['mids'] ?? []))));
$sysPrompt = '';
$userPrompt = '';
if ($mids) {
    $sysPrompt = "Sen deneyimli, profesyonel bir futbol bahis analistisin. Sana birden fazla maç ve her maçın "
        . "bahis marketleri (market_key, seçenek kodları ve oranlar) verilecek. Görevin, HER MAÇIN LİSTELENEN TÜM "
        . "MARKETLERİNİ tek tek DERİNLEMESİNE analiz edip her seçenek için gerçekçi bir olasılık ve somut, veriye "
        . "dayalı bir gerekçe üretmek. Hedef kitle iddaa oynayan deneyimli kullanıcılar; yüzeysel yorum istemiyorlar.\n\n"
        . "Analizinde şunları hesaba kat: takım formları, H2H geçmişi, puan durumu, kadro/sakatlık durumu, iç saha/deplasman "
        . "karakteri, maçın bağlamı (derbi, küme/şampiyonluk baskısı, fikstür yoğunluğu, rotasyon), canlıysa güncel "
        . "skor/dakika ve oranların ima ettiği olasılıklarla (1/oran) kendi olasılıkların arasındaki fark. "
        . "Güncel bilgiden emin değilsen uydurma; genel kadro gücü üzerinden değerlendir.\n\n"
        . "Yanıtını YALNIZCA şu JSON şemasında ver (başka hiçbir metin ekleme):\n"
        . '{"analizler":[{"match_id":123,"marketler":[{"market_key":"MS","secenekler":[{"kod":"MS1","olasilik":45,'
        . '"gerekce":"1-2 cümlelik somut gerekçe"}],"tavsiye":"MS1","guven":7,"ozet":"3-5 cümlelik derin değerlendirme","kaynaklar":["kısa madde"]}]}]}'
        . "\n\nKURALLAR:\n"
        . "- 'match_id' değerini maç başlığında verilen sayıyla AYNEN kullan.\n"
        . "- 'market_key' değerini köşeli parantez içinde verilen anahtarla AYNEN kullan (ör. MS veya m_ab12...); uydurma.\n"
        . "- 'kod' alanına verilen seçenek kodunu AYNEN yaz; listede olmayan seçenek uydurma.\n"
        . "- Her marketin TÜM seçeneklerine olasılık ver; aynı marketin olasılık toplamı ~100 olsun ('olasilik' 0-100 tam sayı).\n"
        . "- 'gerekce': 1-2 cümle; skor senaryosu, form/H2H verisi veya taktik gözlem içersin ('form iyi' gibi boş laf YASAK).\n"
        . "- 'ozet': 3-5 cümle; en olası maç/market senaryosunu, temel riskleri ve oranın DEĞERLİ olup olmadığını "
        . "(kendi olasılığın 1/oran'dan belirgin yüksekse 'değer fırsatı var' diyerek) açıkça yaz.\n"
        . "- 'tavsiye': o markette en mantıklı bulduğun seçeneğin kodu. 'guven': 1-10 tam sayı (veri kalitesi + netlik).\n"
        . "- 'kaynaklar': analizde kullandığın 1-3 önemli somut faktörü kısa maddeler halinde yaz (ör. 'Ev sahibi son 5 iç saha maçında 2+ gol yedi'); bilmiyorsan boş dizi.\n"
        . "- Adı 'Market #...' şeklinde olan pazarların türü bilinmiyor; bunlarda yalnızca oran yapısına dayalı temkinli bir değerlendirme yap ve ozet içinde bunu belirt.\n"
        . "- HER maçın HER marketini analiz et; hiçbirini atlama.";

    $custom = trim((string) Settings::get('analysis_prompt', ''));
    $lines = [];
    $lines[] = $custom !== '' ? $custom : 'Aşağıdaki ' . count($mids) . ' maçın TÜM listelenen marketlerini analiz et ve istenen JSON çıktısını üret.';
    foreach ($mids as $mid) {
        $m = Database::fetch(
            "SELECT m.*, ht.name AS home_name, at.name AS away_name, l.name AS league_name
             FROM matches m
             LEFT JOIN teams ht ON ht.id = m.home_team_id
             LEFT JOIN teams at ON at.id = m.away_team_id
             LEFT JOIN leagues l ON l.id = m.league_id
             WHERE m.id = ?",
            [$mid]
        );
        if (!$m) {
            continue;
        }
        $lines[] = '';
        $lines[] = "=== MAÇ match_id={$mid} | {$m['home_name']} vs {$m['away_name']} ===";
        $lines[] = 'Lig: ' . ($m['league_name'] ?? '-') . ' | Tarih: ' . ($m['start_time'] ?? '-');
        if (($m['status'] ?? '') === 'live') {
            $skor = ($m['ms_home'] !== null && $m['ms_away'] !== null) ? $m['ms_home'] . '-' . $m['ms_away'] : 'bilinmiyor';
            $lines[] = 'DURUM: Maç ŞU AN CANLI. Skor: ' . $skor . ($m['minute'] ? ', dakika: ' . $m['minute'] : '') . '.';
        }
        $defs = preset_match_markets($engine, $mid);
        if ($defs) {
            $lines[] = 'Marketler ([market_key] ad: kod=oran):';
            foreach ($defs as $def) {
                $ops = [];
                foreach ($def['options'] as $o) {
                    $op = $o['kod'] . '=' . ($o['oran'] ?? '?');
                    if ((string) $o['ad'] !== (string) $o['kod']) {
                        $op = $o['kod'] . ' (' . $o['ad'] . ')=' . ($o['oran'] ?? '?');
                    }
                    $ops[] = $op;
                }
                $lines[] = '- [' . $def['key'] . '] ' . $def['label'] . ': ' . implode(', ', $ops);
            }
        } else {
            $lines[] = 'Marketler: (oran verisi yok — bu maçı atla)';
        }
        $stats = [];
        foreach (Database::fetchAll("SELECT type, data FROM match_stats WHERE match_id = ? AND type IN ('form_home','form_away','h2h','standings','injuries')", [$mid]) as $r) {
            $stats[$r['type']] = json_decode($r['data'], true);
        }
        $etiket = ['form_home' => 'Ev sahibi son maçlar', 'form_away' => 'Deplasman son maçlar', 'h2h' => 'H2H', 'standings' => 'Puan durumu', 'injuries' => 'Sakat/cezalı'];
        foreach ($etiket as $t => $ad) {
            if (!empty($stats[$t])) {
                $lines[] = $ad . ': ' . json_encode($stats[$t], JSON_UNESCAPED_UNICODE);
            }
        }
    }
    $lines[] = '';
    $lines[] = 'Tüm maçların tüm marketleri için istenen JSON şemasında yanıt ver. SADECE JSON döndür.';
    $userPrompt = implode("\n", $lines);
}

// ==================== GÖRÜNÜM ====================
$manualOn = (string) Settings::get('manual_analysis_mode', '1') === '1';

admin_header('Hazır Analizler', 'preset_analyses.php');
render_flash();

foreach ($report as [$tip, $msg]) {
    echo '<div class="alert alert-' . e($tip) . ' py-2 mb-2">' . e($msg) . '</div>';
}

// Kayıtlı hazır analizler (maç bazında özet)
$saved = Database::fetchAll(
    "SELECT ma.match_id, COUNT(*) c, MAX(ma.created_at) t, ht.name h, at.name a
     FROM market_analyses ma
     JOIN matches m ON m.id = ma.match_id
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     WHERE ma.status = 'done'
     GROUP BY ma.match_id, ht.name, at.name
     ORDER BY t DESC LIMIT 50"
);

// Maç listesi (seçim formu)
$matches = Database::fetchAll(
    "SELECT m.id, m.start_time, m.status, ht.name h, at.name a, l.name lg
     FROM matches m
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     LEFT JOIN leagues l ON l.id = m.league_id
     WHERE m.status IN ('scheduled','live') AND (m.start_time IS NULL OR m.start_time >= DATE_SUB(NOW(), INTERVAL 3 HOUR))
     ORDER BY m.start_time ASC LIMIT 300"
);
?>
<div class="card p-3 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div>
            <strong class="text-light">Manuel mod:</strong>
            <?= $manualOn
                ? '<span class="badge bg-success">AÇIK</span> <small class="text-secondary">AI sağlayıcısına istek gitmez; yalnızca buradan kaydettiğiniz analizler sunulur. Hazır analizi olmayan markette kullanıcıya "analiz hazırlanıyor" denir, kredi düşmez.</small>'
                : '<span class="badge bg-secondary">KAPALI</span> <small class="text-secondary">Hazır analiz yoksa gerçek AI çağrısı yapılır (API anahtarı gerekir).</small>' ?>
        </div>
        <form method="post" class="ms-auto">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="manual_mode" value="<?= $manualOn ? '0' : '1' ?>">
            <button class="btn btn-sm btn-outline-<?= $manualOn ? 'secondary' : 'success' ?>"><?= $manualOn ? 'Manuel modu kapat' : 'Manuel modu aç' ?></button>
        </form>
    </div>
</div>

<div class="card p-3 mb-4">
    <h5 class="text-light">1) Maçları seç</h5>
    <p class="text-secondary mb-2">Tüm bülteni ya da belirli maçları seçin; prompt seçiminize göre oluşturulur.</p>
    <form method="get">
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="selAll" onclick="document.querySelectorAll('.mchk').forEach(c=>c.checked=this.checked)">
            <label class="form-check-label text-light" for="selAll"><strong>Tümünü seç (bülten)</strong></label>
        </div>
        <div style="max-height:320px;overflow-y:auto;" class="border rounded p-2 mb-2">
            <?php foreach ($matches as $mm): ?>
                <div class="form-check">
                    <input class="form-check-input mchk" type="checkbox" name="mids[]" value="<?= (int) $mm['id'] ?>"
                        <?= in_array((int) $mm['id'], $mids, true) ? 'checked' : '' ?> id="m<?= (int) $mm['id'] ?>">
                    <label class="form-check-label text-light" for="m<?= (int) $mm['id'] ?>">
                        #<?= (int) $mm['id'] ?> — <?= e($mm['h']) ?> vs <?= e($mm['a']) ?>
                        <small class="text-secondary"><?= e($mm['lg']) ?> · <?= e($mm['start_time']) ?></small>
                        <?= $mm['status'] === 'live' ? '<span class="badge bg-danger">CANLI</span>' : '' ?>
                    </label>
                </div>
            <?php endforeach; ?>
            <?php if (!$matches): ?><span class="text-secondary">Yaklaşan maç bulunamadı.</span><?php endif; ?>
        </div>
        <button class="btn btn-success"><i class="bi bi-magic"></i> Prompt Oluştur</button>
    </form>
</div>

<?php if ($sysPrompt !== ''): ?>
<div class="card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="text-light mb-0">2a) Sistem promptu</h5>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="copyBox('sysBox', this)">📋 Kopyala</button>
    </div>
    <textarea id="sysBox" class="form-control" rows="8" readonly style="background:#0d1b2a;color:#e2e8f0;font-family:monospace;font-size:.85rem;"><?= e($sysPrompt) ?></textarea>
</div>
<div class="card p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="text-light mb-0">2b) Kullanıcı promptu (<?= count($mids) ?> maç)</h5>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="copyBox('usrBox', this)">📋 Kopyala</button>
    </div>
    <textarea id="usrBox" class="form-control" rows="14" readonly style="background:#0d1b2a;color:#e2e8f0;font-family:monospace;font-size:.85rem;"><?= e($userPrompt) ?></textarea>
</div>
<?php endif; ?>

<div class="card p-3 mb-4">
    <h5 class="text-light">3) LLM yanıtını (JSON) yapıştır ve kaydet</h5>
    <p class="text-secondary mb-2">Yanıt kaydedilince kullanıcılara gerçek AI analizi gibi sunulur (görünen sağlayıcı: <strong><?= e(LlmFactory::providerName()) ?> / <?= e(preset_model_name()) ?></strong>).</p>
    <form method="post" onsubmit="return prepJson()">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="json_b64" id="jsonB64">
        <textarea id="jsonBox" class="form-control mb-2" rows="10" placeholder='{"analizler":[{"match_id":123,"marketler":[...]}]}' style="background:#0d1b2a;color:#e2e8f0;font-family:monospace;font-size:.85rem;"></textarea>
        <button class="btn btn-warning"><i class="bi bi-save"></i> Analizleri Kaydet</button>
    </form>
</div>

<div class="card p-3 mb-4">
    <h5 class="text-light">Kayıtlı hazır analizler</h5>
    <table class="table table-sm align-middle">
        <thead><tr><th>Maç</th><th>Market sayısı</th><th>Son güncelleme</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($saved as $sv): ?>
            <tr>
                <td>#<?= (int) $sv['match_id'] ?> — <?= e($sv['h']) ?> vs <?= e($sv['a']) ?></td>
                <td><span class="badge bg-info"><?= (int) $sv['c'] ?></span></td>
                <td><small><?= e($sv['t']) ?></small></td>
                <td>
                    <form method="post" onsubmit="return confirm('Bu maçın tüm hazır analizleri silinsin mi?')">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="del_mid" value="<?= (int) $sv['match_id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Sil</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$saved): ?><tr><td colspan="4" class="text-center text-secondary py-3">Henüz kayıtlı hazır analiz yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function copyBox(id, btn){
    const t = document.getElementById(id);
    const done = () => { btn.innerHTML = '✓ Kopyalandı'; setTimeout(() => btn.innerHTML = '📋 Kopyala', 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t.value).then(done).catch(() => { t.select(); document.execCommand('copy'); done(); });
    } else { t.select(); document.execCommand('copy'); done(); }
}
// JSON'u base64 olarak gönder: sunucu güvenlik filtrelerinin (WAF) ham JSON POST'unu
// engellemesini önler.
function prepJson(){
    const t = document.getElementById('jsonBox').value;
    document.getElementById('jsonB64').value = btoa(unescape(encodeURIComponent(t)));
    return true;
}
</script>
<?php
admin_footer();
