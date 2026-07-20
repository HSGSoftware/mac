<?php
/**
 * Admin > Analizler
 *
 * Market bazlı AI analizlerini (market_analyses) gösterir. Bir maç açıldığında
 * tüm marketleri tek AI çağrısıyla üretilir; buradan bir maçın analizini
 * yeniden çalıştırabilir, hataları görebilirsiniz.
 */
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Database;
use MacRadar\Services\AnalysisEngine;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mid = (int) ($_POST['match_id'] ?? 0);
    if ($mid > 0) {
        // Yeniden üretim: mevcut satırları temizle ki önbelleğe takılmasın
        Database::execute('DELETE FROM market_analyses WHERE match_id = ?', [$mid]);
        try {
            set_time_limit(240);
            $count = (new AnalysisEngine())->analyzeAllMarkets($mid, null);
            flash("Maç #$mid için $count market analizi üretildi.");
        } catch (\Throwable $ex) {
            flash('Hata: ' . $ex->getMessage(), 'danger');
        }
    }
    header('Location: analyses.php' . ($mid ? '?match_id=' . $mid : ''));
    exit;
}

$matchId = isset($_GET['match_id']) ? (int) $_GET['match_id'] : null;

admin_header('AI Analizleri', 'analyses.php');
render_flash();

if ($matchId) {
    $m = Database::fetch(
        'SELECT m.*, ht.name h, at.name a
         FROM matches m
         LEFT JOIN teams ht ON ht.id = m.home_team_id
         LEFT JOIN teams at ON at.id = m.away_team_id
         WHERE m.id = ?',
        [$matchId]
    );
    $rows = Database::fetchAll(
        'SELECT * FROM market_analyses WHERE match_id = ? ORDER BY
            CASE market_key WHEN \'MS\' THEN 0 ELSE 1 END, market_label',
        [$matchId]
    );
    ?>
    <a href="analyses.php" class="btn btn-sm btn-outline-secondary mb-3">← Tüm analizler</a>
    <h4 class="text-light"><?= e($m['h'] ?? '') ?> — <?= e($m['a'] ?? '') ?></h4>
    <form method="post" class="mb-3">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="match_id" value="<?= $matchId ?>">
        <button class="btn btn-warning btn-sm"
                onclick="this.disabled=true;this.form.submit();this.innerHTML='Üretiliyor…'">
            <i class="bi bi-arrow-repeat"></i> Tüm marketleri yeniden analiz et
        </button>
        <small class="text-secondary ms-2">Tek AI çağrısı; 30–60 saniye sürebilir.</small>
    </form>

    <?php if (!$rows): ?>
        <p class="text-secondary">Bu maç için henüz market analizi yok.</p>
    <?php endif; ?>

    <?php foreach ($rows as $r):
        $result = $r['result'] ? json_decode($r['result'], true) : null; ?>
        <div class="card p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong class="text-light"><?= e($r['market_label']) ?></strong>
                <span>
                    <?php if ($r['is_live']): ?><span class="badge bg-danger">CANLI</span><?php endif; ?>
                    <span class="badge bg-info"><?= e($r['provider']) ?></span>
                    <span class="badge bg-<?= $r['status'] === 'done' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'secondary') ?>">
                        <?= e($r['status']) ?>
                    </span>
                </span>
            </div>

            <?php if ($r['status'] === 'failed'): ?>
                <p class="text-danger mt-2 mb-0"><?= e($r['error_message']) ?></p>
            <?php elseif ($result): ?>
                <?php if (!empty($result['ozet'])): ?>
                    <p class="mt-2 mb-2 text-light small"><?= e($result['ozet']) ?></p>
                <?php endif; ?>
                <table class="table table-sm table-dark align-middle mb-2">
                    <thead><tr>
                        <th>Seçenek</th><th>Oran</th><th>AI</th><th>Oranın ima ettiği</th><th>Değer</th><th>Gerekçe</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (($result['secenekler'] ?? []) as $o): ?>
                        <tr<?= (($result['tavsiye'] ?? null) === ($o['kod'] ?? '')) ? ' class="table-success"' : '' ?>>
                            <td><strong><?= e($o['ad'] ?? ($o['kod'] ?? '')) ?></strong></td>
                            <td><?= e((string) ($o['oran'] ?? '-')) ?></td>
                            <td><?= isset($o['olasilik']) ? (int) $o['olasilik'] . '%' : '-' ?></td>
                            <td class="text-secondary"><?= isset($o['implied_olasilik']) ? e((string) $o['implied_olasilik']) . '%' : '-' ?></td>
                            <td><?= !empty($o['deger_var_mi']) ? '<span class="badge bg-warning text-dark">💎 +' . e((string) ($o['deger_farki'] ?? '')) . '</span>' : '' ?></td>
                            <td><small class="text-secondary"><?= e($o['gerekce'] ?? '') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <small class="text-secondary">
                    Tavsiye: <strong><?= e((string) ($result['tavsiye'] ?? '—')) ?></strong>
                    · Güven: <?= isset($result['guven']) ? (int) $result['guven'] . '/10' : '—' ?>
                    · Model: <?= e((string) $r['model_name']) ?>
                    · Token: <?= (int) $r['token_usage'] ?>
                    · <?= e($r['created_at']) ?>
                </small>
            <?php else: ?>
                <p class="text-secondary mt-2 mb-0">Sonuç bekleniyor…</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php } else {
    // Maç bazında özet: kaç market analiz edildi, kaçı hatalı
    $all = Database::fetchAll(
        "SELECT ma.match_id,
                ht.name h, at.name a2,
                m.start_time,
                COUNT(*) total,
                SUM(ma.status = 'done') done,
                SUM(ma.status = 'failed') failed,
                SUM(ma.status = 'pending') pending,
                SUM(COALESCE(ma.token_usage, 0)) tokens,
                MAX(ma.created_at) last_at
         FROM market_analyses ma
         JOIN matches m ON m.id = ma.match_id
         LEFT JOIN teams ht ON ht.id = m.home_team_id
         LEFT JOIN teams at ON at.id = m.away_team_id
         GROUP BY ma.match_id, ht.name, at.name, m.start_time
         ORDER BY last_at DESC
         LIMIT 100"
    );
    ?>
    <div class="card p-3">
        <table class="table table-sm table-dark align-middle mb-0">
            <thead><tr>
                <th>Maç</th><th class="text-center">Market</th><th class="text-center">Hazır</th>
                <th class="text-center">Hata</th><th class="text-center">Bekleyen</th>
                <th class="text-center">Token</th><th>Son üretim</th>
            </tr></thead>
            <tbody>
            <?php foreach ($all as $a): ?>
                <tr>
                    <td><a href="analyses.php?match_id=<?= (int) $a['match_id'] ?>"><?= e($a['h']) ?> — <?= e($a['a2']) ?></a></td>
                    <td class="text-center"><?= (int) $a['total'] ?></td>
                    <td class="text-center text-success"><?= (int) $a['done'] ?></td>
                    <td class="text-center <?= (int) $a['failed'] ? 'text-danger' : 'text-secondary' ?>"><?= (int) $a['failed'] ?></td>
                    <td class="text-center text-secondary"><?= (int) $a['pending'] ?></td>
                    <td class="text-center text-secondary"><?= number_format((int) $a['tokens']) ?></td>
                    <td><small class="text-secondary"><?= e($a['last_at']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$all): ?>
                <tr><td colspan="7" class="text-center text-secondary py-3">Henüz analiz yok.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php }
admin_footer();
