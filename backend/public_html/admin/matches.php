<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Database;
use MacRadar\Services\MackolikScraper;
use MacRadar\Services\AnalysisEngine;

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'scrape') {
            $res = (new MackolikScraper())->fetchFixtures($date);
            flash("Scrape tamamlandı: {$res['count']} maç ({$res['source']})");
        } elseif ($action === 'analyze') {
            $mid = (int) $_POST['match_id'];
            set_time_limit(240);
            $count = (new AnalysisEngine())->analyzeAllMarkets($mid, null);
            flash("Maç #$mid için $count market analizi üretildi.");
        }
    } catch (\Throwable $ex) {
        flash('Hata: ' . $ex->getMessage(), 'danger');
    }
    header('Location: matches.php?date=' . urlencode($date));
    exit;
}

$rows = Database::fetchAll(
    "SELECT m.*, l.name league_name, ht.name home_name, at.name away_name,
            (SELECT COUNT(*) FROM market_analyses ma WHERE ma.match_id=m.id AND ma.status='done') has_analysis
     FROM matches m
     LEFT JOIN leagues l ON l.id=m.league_id
     LEFT JOIN teams ht ON ht.id=m.home_team_id
     LEFT JOIN teams at ON at.id=m.away_team_id
     WHERE DATE(m.start_time)=?
     ORDER BY l.priority, m.start_time",
    [$date]
);

admin_header('Maçlar', 'matches.php');
render_flash();
?>
<form method="get" class="row g-2 mb-3">
    <div class="col-auto"><input type="date" name="date" value="<?= e($date) ?>" class="form-control"></div>
    <div class="col-auto"><button class="btn btn-outline-info">Filtrele</button></div>
</form>
<form method="post" class="mb-3">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="scrape">
    <button class="btn btn-success"><i class="bi bi-cloud-download"></i> Bu tarihi Mackolik'ten çek</button>
</form>
<div class="card p-3">
    <table class="table table-sm align-middle">
        <thead><tr><th>Saat</th><th>Lig</th><th>Maç</th><th>MS 1/X/2</th><th>Durum</th><th>Analiz</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $odds = [];
            foreach (Database::fetchAll('SELECT market,value FROM odds WHERE match_id=? AND is_latest=1', [$r['id']]) as $o) { $odds[$o['market']] = $o['value']; }
        ?>
            <tr>
                <td><?= e(date('H:i', strtotime($r['start_time']))) ?></td>
                <td><small><?= e($r['league_name']) ?></small></td>
                <td><?= e($r['home_name']) ?> - <?= e($r['away_name']) ?>
                    <?php if ($r['ms_home'] !== null): ?><span class="badge bg-secondary"><?= (int)$r['ms_home'] ?>-<?= (int)$r['ms_away'] ?></span><?php endif; ?>
                </td>
                <td><small><?= e($odds['MS1'] ?? '-') ?> / <?= e($odds['MSX'] ?? '-') ?> / <?= e($odds['MS2'] ?? '-') ?></small></td>
                <td><span class="badge bg-<?= $r['status']==='finished'?'secondary':'info' ?>"><?= e($r['status']) ?></span></td>
                <td><?= (int)$r['has_analysis'] > 0 ? '<span class="text-success">✓</span>' : '<span class="text-secondary">—</span>' ?></td>
                <td>
                    <a href="analyses.php?match_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-info">Detay</a>
                    <?php if ((int)$r['has_analysis'] === 0): ?>
                    <button form="analyze-<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-success">Analiz Et</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-secondary text-center py-3">Bu tarihte maç yok. Yukarıdan çekmeyi deneyin.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php foreach ($rows as $r): if ((int)$r['has_analysis'] === 0): ?>
<form method="post" id="analyze-<?= (int)$r['id'] ?>">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="analyze">
    <input type="hidden" name="match_id" value="<?= (int)$r['id'] ?>">
</form>
<?php endif; endforeach; ?>
<?php admin_footer();
