<?php
require __DIR__ . '/bootstrap.php';
$admin = admin_require_login();

use MacRadar\Core\Database;
use MacRadar\Core\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'general') {
        Settings::set('free_daily_limit', (int) ($_POST['free_daily_limit'] ?? 3));
        Settings::set('announcement', trim($_POST['announcement'] ?? ''));
        flash('Genel ayarlar kaydedildi.');
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
        <label class="form-label">Günlük ücretsiz analiz limiti</label>
        <input type="number" name="free_daily_limit" class="form-control mb-3" value="<?= e($s['free_daily_limit'] ?? '3') ?>" min="0">
        <label class="form-label">Duyuru mesajı (uygulamada gösterilir, boş = kapalı)</label>
        <textarea name="announcement" class="form-control mb-3" rows="2"><?= e($s['announcement'] ?? '') ?></textarea>
        <button name="action" value="general" class="btn btn-success"><i class="bi bi-save"></i> Kaydet</button>
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
