<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Database;

// Tablo yoksa uyarı göster (migration çalıştırılmamış olabilir)
$tableExists = (bool) Database::fetch("SHOW TABLES LIKE 'ai_prompt_logs'");

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

admin_header('AI Prompt Kayıtları', 'prompt_logs.php');
render_flash();

if (!$tableExists) {
    echo '<div class="alert alert-warning">'
        . '<strong>ai_prompt_logs</strong> tablosu bulunamadı. Önce <code>migrate.php</code> çalıştırılmalı.'
        . '</div>';
    admin_footer();
    return;
}

if ($id) {
    // ----- Tek kayıt detayı: tam prompt + yanıt -----
    $log = Database::fetch('SELECT * FROM ai_prompt_logs WHERE id = ?', [$id]);
    if (!$log) {
        echo '<div class="alert alert-danger">Kayıt bulunamadı.</div>';
        admin_footer();
        return;
    }
    ?>
    <a href="prompt_logs.php" class="btn btn-sm btn-outline-secondary mb-3">← Tüm kayıtlar</a>
    <div class="card p-3 mb-3">
        <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="badge bg-secondary">#<?= (int) $log['id'] ?></span>
            <span class="badge bg-<?= $log['analysis_type'] === 'market' ? 'primary' : 'info' ?>"><?= e($log['analysis_type']) ?></span>
            <span class="badge bg-dark"><?= e($log['provider']) ?> · <?= e($log['model_name']) ?></span>
            <?php if ($log['match_id']): ?><span class="badge bg-dark">maç #<?= (int) $log['match_id'] ?></span><?php endif; ?>
            <?php if ($log['market_key']): ?><span class="badge bg-dark">market: <?= e($log['market_key']) ?></span><?php endif; ?>
            <span class="badge bg-dark">deneme <?= (int) $log['attempt'] ?></span>
            <?php if ($log['web_search']): ?><span class="badge bg-success">web araması</span><?php endif; ?>
            <span class="badge bg-dark">token: <?= (int) $log['token_usage'] ?></span>
            <span class="badge bg-dark"><?= e($log['created_at']) ?></span>
        </div>
    </div>

    <h5 class="text-light">Sistem promptu</h5>
    <div class="card p-3 mb-3"><pre class="mb-0 text-light" style="white-space:pre-wrap;word-break:break-word;"><?= e($log['system_prompt']) ?></pre></div>

    <h5 class="text-light">Kullanıcı promptu (gönderilen)</h5>
    <div class="card p-3 mb-3"><pre class="mb-0 text-light" style="white-space:pre-wrap;word-break:break-word;"><?= e($log['user_prompt']) ?></pre></div>

    <h5 class="text-light">AI yanıtı (dönen)</h5>
    <div class="card p-3 mb-3"><pre class="mb-0 text-light" style="white-space:pre-wrap;word-break:break-word;"><?= e($log['response_text']) ?></pre></div>
    <?php
    admin_footer();
    return;
}

// ----- Liste -----
$type = $_GET['type'] ?? '';
$matchId = isset($_GET['match_id']) ? (int) $_GET['match_id'] : 0;

$where = [];
$params = [];
if ($type === 'full' || $type === 'market') {
    $where[] = 'analysis_type = ?';
    $params[] = $type;
}
if ($matchId > 0) {
    $where[] = 'match_id = ?';
    $params[] = $matchId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int) (Database::fetch("SELECT COUNT(*) c FROM ai_prompt_logs $whereSql", $params)['c'] ?? 0);
$rows = Database::fetchAll(
    "SELECT id, analysis_type, match_id, market_key, provider, model_name, attempt, web_search, token_usage,
            CHAR_LENGTH(user_prompt) up_len, CHAR_LENGTH(response_text) rp_len, created_at
     FROM ai_prompt_logs $whereSql ORDER BY id DESC LIMIT 200",
    $params
);
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <span class="text-secondary">Toplam <strong><?= $total ?></strong> kayıt (son 200 gösteriliyor)</span>
    <div class="btn-group btn-group-sm ms-auto">
        <a href="prompt_logs.php" class="btn btn-outline-light <?= $type === '' ? 'active' : '' ?>">Tümü</a>
        <a href="prompt_logs.php?type=full" class="btn btn-outline-light <?= $type === 'full' ? 'active' : '' ?>">Tüm maç</a>
        <a href="prompt_logs.php?type=market" class="btn btn-outline-light <?= $type === 'market' ? 'active' : '' ?>">Market</a>
    </div>
</div>
<?php if ($matchId > 0): ?>
    <p><span class="badge bg-info">maç #<?= $matchId ?> filtresi</span> <a href="prompt_logs.php">temizle</a></p>
<?php endif; ?>
<div class="card p-3">
    <table class="table table-sm align-middle">
        <thead><tr><th>#</th><th>Tip</th><th>Maç</th><th>Market</th><th>Model</th><th>Dnm</th><th>Token</th><th>Prompt/Yanıt</th><th>Tarih</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int) $r['id'] ?></td>
                <td><span class="badge bg-<?= $r['analysis_type'] === 'market' ? 'primary' : 'info' ?>"><?= e($r['analysis_type']) ?></span></td>
                <td><?= $r['match_id'] ? '#' . (int) $r['match_id'] : '-' ?></td>
                <td><small><?= e($r['market_key'] ?? '-') ?></small></td>
                <td><small><?= e($r['model_name']) ?><?= $r['web_search'] ? ' 🌐' : '' ?></small></td>
                <td><?= (int) $r['attempt'] ?></td>
                <td><?= (int) $r['token_usage'] ?></td>
                <td><small class="text-secondary"><?= (int) $r['up_len'] ?> / <?= (int) $r['rp_len'] ?> krk</small></td>
                <td><small><?= e($r['created_at']) ?></small></td>
                <td><a class="btn btn-sm btn-outline-info" href="prompt_logs.php?id=<?= (int) $r['id'] ?>">Gör</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-secondary py-3">Henüz kayıt yok. Bir analiz çalıştırıldığında burada görünecek.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php
admin_footer();
