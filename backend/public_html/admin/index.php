<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Database;

$today = date('Y-m-d');
$matchCount = (int) (Database::fetch('SELECT COUNT(*) c FROM matches WHERE DATE(start_time) = ?', [$today])['c'] ?? 0);
$analysisToday = (int) (Database::fetch("SELECT COUNT(*) c FROM analyses WHERE DATE(created_at) = ? AND status='done'", [$today])['c'] ?? 0);
$userCount = (int) (Database::fetch('SELECT COUNT(*) c FROM users')['c'] ?? 0);
$premiumCount = (int) (Database::fetch("SELECT COUNT(*) c FROM users WHERE plan='premium'")['c'] ?? 0);
$tokenTotal = (int) (Database::fetch('SELECT COALESCE(SUM(token_usage),0) s FROM analyses WHERE DATE(created_at) = ?', [$today])['s'] ?? 0);

$successRow = Database::fetch("SELECT COUNT(*) total, SUM(was_correct=1) correct FROM analysis_results WHERE was_correct IS NOT NULL");
$successRate = ($successRow && (int)$successRow['total'] > 0)
    ? round((int)$successRow['correct'] / (int)$successRow['total'] * 100, 1) : null;

$lastScrape = Database::fetch('SELECT * FROM scrape_logs ORDER BY id DESC LIMIT 1');

admin_header('Kontrol Paneli', 'index.php');
render_flash();
?>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3"><div class="text-secondary">Bugünkü Maç</div><div class="stat text-info"><?= $matchCount ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-secondary">Bugünkü Analiz</div><div class="stat text-success"><?= $analysisToday ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-secondary">Kullanıcı (Premium)</div><div class="stat"><?= $userCount ?> <small class="text-warning">(<?= $premiumCount ?>)</small></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-secondary">AI İsabet Oranı</div><div class="stat text-warning"><?= $successRate !== null ? $successRate . '%' : '—' ?></div></div></div>
</div>
<div class="row g-3">
    <div class="col-md-6"><div class="card p-3">
        <h5 class="text-light">Bugünkü Token Kullanımı</h5>
        <div class="stat text-info"><?= number_format($tokenTotal) ?></div>
        <small class="text-secondary">Tamamlanan analizlerin toplam token tüketimi</small>
    </div></div>
    <div class="col-md-6"><div class="card p-3">
        <h5 class="text-light">Son Scraper Çalışması</h5>
        <?php if ($lastScrape): ?>
            <p class="mb-1"><strong><?= e($lastScrape['job']) ?></strong>
                <span class="badge bg-<?= $lastScrape['status']==='success'?'success':($lastScrape['status']==='partial'?'warning':'danger') ?>"><?= e($lastScrape['status']) ?></span>
            </p>
            <p class="text-secondary mb-1"><?= e($lastScrape['message']) ?></p>
            <small class="text-secondary"><?= e($lastScrape['created_at']) ?> — <?= (int)$lastScrape['items_count'] ?> öğe</small>
        <?php else: ?>
            <p class="text-secondary">Henüz scraper çalışmadı.</p>
        <?php endif; ?>
    </div></div>
</div>
<?php admin_footer();
