<?php
require __DIR__ . '/bootstrap.php';
$admin = admin_require_login();

use MacRadar\Core\Database;
use MacRadar\Core\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'general') {
        Settings::set('announcement', trim($_POST['announcement'] ?? ''));
        flash('Genel ayarlar kaydedildi.');
    } elseif ($action === 'tokens') {
        $tokenKeys = [
            'free_daily_tokens' => 10, 'bronz_daily_tokens' => 100,
            'gumus_daily_tokens' => 250, 'altin_daily_tokens' => 600,
            'token_cost_group_ana' => 10, 'token_cost_group_gol' => 15,
            'token_cost_group_handikap' => 20, 'token_cost_group_ozel' => 25,
            'token_cost_analysis' => 25, 'token_cost_live_analysis' => 40,
        ];
        foreach ($tokenKeys as $key => $def) {
            Settings::set($key, max(0, (int) ($_POST[$key] ?? $def)));
        }
        flash('Token ayarları kaydedildi.');
    } elseif ($action === 'password') {
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 6) {
            flash('Şifre en az 6 karakter olmalı.', 'danger');
        } else {
            Database::execute('UPDATE admins SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            flash('Admin şifresi güncellendi.');
        }
    }
    header('Location: settings.php');
    exit;
}

$s = Settings::all();
admin_header('Genel Ayarlar', 'settings.php');
render_flash();
?>
<div class="card p-4 mb-3">
    <h5 class="text-light mb-3">Uygulama Ayarları</h5>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <label class="form-label">Duyuru mesajı (uygulamada gösterilir, boş = kapalı)</label>
        <textarea name="announcement" class="form-control mb-3" rows="2"><?= e($s['announcement'] ?? '') ?></textarea>
        <button name="action" value="general" class="btn btn-success"><i class="bi bi-save"></i> Kaydet</button>
    </form>
</div>
<div class="card p-4 mb-3">
    <h5 class="text-light mb-3">Günlük Token Ayarları</h5>
    <p class="text-secondary" style="font-size:13px">Token hakları her gün sıfırlanır; ertesi güne devretmez.
       Market grupları ve AI analizleri token harcayarak açılır.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="row">
            <div class="col-md-3"><label class="form-label">Ücretsiz — günlük token</label>
                <input type="number" name="free_daily_tokens" class="form-control mb-3" value="<?= e($s['free_daily_tokens'] ?? '10') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Bronz — günlük token</label>
                <input type="number" name="bronz_daily_tokens" class="form-control mb-3" value="<?= e($s['bronz_daily_tokens'] ?? '100') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Gümüş — günlük token</label>
                <input type="number" name="gumus_daily_tokens" class="form-control mb-3" value="<?= e($s['gumus_daily_tokens'] ?? '250') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Altın — günlük token</label>
                <input type="number" name="altin_daily_tokens" class="form-control mb-3" value="<?= e($s['altin_daily_tokens'] ?? '600') ?>" min="0"></div>
        </div>
        <div class="row">
            <div class="col-md-3"><label class="form-label">Ana Marketler maliyeti</label>
                <input type="number" name="token_cost_group_ana" class="form-control mb-3" value="<?= e($s['token_cost_group_ana'] ?? '10') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Gol Marketleri maliyeti</label>
                <input type="number" name="token_cost_group_gol" class="form-control mb-3" value="<?= e($s['token_cost_group_gol'] ?? '15') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Handikap &amp; Kombine maliyeti</label>
                <input type="number" name="token_cost_group_handikap" class="form-control mb-3" value="<?= e($s['token_cost_group_handikap'] ?? '20') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Özel Marketler maliyeti</label>
                <input type="number" name="token_cost_group_ozel" class="form-control mb-3" value="<?= e($s['token_cost_group_ozel'] ?? '25') ?>" min="0"></div>
        </div>
        <div class="row">
            <div class="col-md-3"><label class="form-label">AI analiz maliyeti</label>
                <input type="number" name="token_cost_analysis" class="form-control mb-3" value="<?= e($s['token_cost_analysis'] ?? '25') ?>" min="0"></div>
            <div class="col-md-3"><label class="form-label">Canlı AI analiz maliyeti (Altın)</label>
                <input type="number" name="token_cost_live_analysis" class="form-control mb-3" value="<?= e($s['token_cost_live_analysis'] ?? '40') ?>" min="0"></div>
        </div>
        <button name="action" value="tokens" class="btn btn-success"><i class="bi bi-save"></i> Token Ayarlarını Kaydet</button>
    </form>
</div>
<div class="card p-4">
    <h5 class="text-light mb-3">Admin Şifresi Değiştir</h5>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <label class="form-label">Yeni şifre</label>
        <input type="password" name="new_password" class="form-control mb-3" required>
        <button name="action" value="password" class="btn btn-outline-warning">Şifreyi Güncelle</button>
    </form>
</div>
<?php admin_footer();
