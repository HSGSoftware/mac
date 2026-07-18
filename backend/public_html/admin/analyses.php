<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Database;
use MacRadar\Services\AnalysisEngine;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mid = (int) $_POST['match_id'];
    // Başarısız/eksik analizi yeniden çalıştır: önce eski failed kayıtları temizle
    Database::execute("DELETE FROM analyses WHERE match_id=? AND status IN ('failed','pending')", [$mid]);
    try {
        (new AnalysisEngine())->analyze($mid, null);
        flash("Maç #$mid analizi yeniden üretildi.");
    } catch (\Throwable $ex) {
        flash('Hata: ' . $ex->getMessage(), 'danger');
    }
    header('Location: analyses.php' . (isset($_GET['match_id']) ? '?match_id=' . (int)$_GET['match_id'] : ''));
    exit;
}

$matchId = isset($_GET['match_id']) ? (int) $_GET['match_id'] : null;

admin_header('AI Analizleri', 'analyses.php');
render_flash();

if ($matchId) {
    $m = Database::fetch("SELECT m.*, ht.name h, at.name a FROM matches m LEFT JOIN teams ht ON ht.id=m.home_team_id LEFT JOIN teams at ON at.id=m.away_team_id WHERE m.id=?", [$matchId]);
    $analyses = Database::fetchAll('SELECT * FROM analyses WHERE match_id=? ORDER BY id DESC', [$matchId]);
    ?>
    <a href="analyses.php" class="btn btn-sm btn-outline-secondary mb-3">← Tüm analizler</a>
    <h4 class="text-light"><?= e($m['h'] ?? '') ?> - <?= e($m['a'] ?? '') ?></h4>
    <form method="post" class="mb-3">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="match_id" value="<?= $matchId ?>">
        <button class="btn btn-warning btn-sm"><i class="bi bi-arrow-repeat"></i> Analizi (yeniden) çalıştır</button>
    </form>
    <?php foreach ($analyses as $a):
        $result = $a['result'] ? json_decode($a['result'], true) : null; ?>
        <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between">
                <span><span class="badge bg-info"><?= e($a['provider']) ?></span> <?= e($a['model_name']) ?></span>
                <span class="badge bg-<?= $a['status']==='done'?'success':($a['status']==='failed'?'danger':'secondary') ?>"><?= e($a['status']) ?></span>
            </div>
            <?php if ($a['status'] === 'failed'): ?>
                <p class="text-danger mt-2"><?= e($a['error_message']) ?></p>
            <?php elseif ($result): ?>
                <p class="mt-2 text-light"><?= e($a['general_note']) ?></p>
                <table class="table table-sm">
                    <thead><tr><th>Market</th><th>Oran</th><th>AI Olasılık</th><th>Değer</th><th>Gerekçe</th></tr></thead>
                    <tbody>
                    <?php foreach (($result['markets'] ?? []) as $mk): ?>
                        <tr>
                            <td><strong><?= e($mk['market'] ?? '') ?></strong></td>
                            <td><?= e($mk['oran'] ?? '-') ?></td>
                            <td><?= e($mk['olasilik'] ?? '-') ?>%</td>
                            <td><?= !empty($mk['deger_var_mi']) ? '<span class="badge bg-warning text-dark">💎 Değerli</span>' : '' ?></td>
                            <td><small><?= e($mk['gerekce'] ?? '') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <small class="text-secondary">En güvenli: <strong><?= e($a['safest_pick']) ?></strong> · Sürpriz: <?= e($a['surprise_level']) ?> · Token: <?= (int)$a['token_usage'] ?> · <?= e($a['created_at']) ?></small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$analyses): ?><p class="text-secondary">Bu maç için analiz yok.</p><?php endif; ?>
<?php } else {
    $all = Database::fetchAll(
        "SELECT a.*, ht.name h, at.name a2 FROM analyses a
         JOIN matches m ON m.id=a.match_id
         LEFT JOIN teams ht ON ht.id=m.home_team_id
         LEFT JOIN teams at ON at.id=m.away_team_id
         ORDER BY a.id DESC LIMIT 100"
    );
    ?>
    <div class="card p-3">
        <table class="table table-sm align-middle">
            <thead><tr><th>#</th><th>Maç</th><th>Sağlayıcı</th><th>Durum</th><th>En güvenli</th><th>Token</th><th>Tarih</th></tr></thead>
            <tbody>
            <?php foreach ($all as $a): ?>
                <tr>
                    <td><?= (int)$a['id'] ?></td>
                    <td><a href="analyses.php?match_id=<?= (int)$a['match_id'] ?>"><?= e($a['h']) ?> - <?= e($a['a2']) ?></a></td>
                    <td><?= e($a['provider']) ?></td>
                    <td><span class="badge bg-<?= $a['status']==='done'?'success':($a['status']==='failed'?'danger':'secondary') ?>"><?= e($a['status']) ?></span></td>
                    <td><?= e($a['safest_pick']) ?></td>
                    <td><?= (int)$a['token_usage'] ?></td>
                    <td><small><?= e($a['created_at']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$all): ?><tr><td colspan="7" class="text-center text-secondary py-3">Henüz analiz yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
<?php }
admin_footer();
