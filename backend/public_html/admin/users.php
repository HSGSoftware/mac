<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Credits;
use MacRadar\Core\Database;
use MacRadar\Core\Plans;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) $_POST['user_id'];
    $action = $_POST['action'] ?? '';
    if ($action === 'set_plan') {
        $plan = $_POST['plan'] ?? 'free';
        if (!in_array($plan, ['free', 'bronz', 'gumus', 'altin'], true)) {
            $plan = 'free';
        }
        $days = max(1, min(3650, (int) ($_POST['days'] ?? 30)));
        if ($plan === 'free') {
            Database::execute("UPDATE users SET plan='free', premium_until=NULL WHERE id=?", [$id]);
            flash('Kullanıcı Ücretsiz pakete alındı.');
        } else {
            $until = date('Y-m-d H:i:s', strtotime("+$days day"));
            Database::execute('UPDATE users SET plan=?, premium_until=? WHERE id=?', [$plan, $until, $id]);
            flash('Paket güncellendi: ' . $plan . " ($days gün).");
        }
    } elseif ($action === 'add_credits') {
        // Bonus kredi: günlük hak bittikten sonra harcanır, günlük SIFIRLANMAZ
        $amount = (int) ($_POST['credits'] ?? 0);
        if ($amount > 0 && $amount <= 100000) {
            Database::execute('UPDATE users SET bonus_credits = bonus_credits + ? WHERE id=?', [$amount, $id]);
            flash("$amount bonus kredi eklendi.");
        } elseif ($amount < 0) {
            Database::execute('UPDATE users SET bonus_credits = GREATEST(0, bonus_credits + ?) WHERE id=?', [$amount, $id]);
            flash(abs($amount) . ' bonus kredi silindi.', 'warning');
        } else {
            flash('Geçersiz kredi miktarı.', 'danger');
        }
    } elseif ($action === 'ban') {
        Database::execute('UPDATE users SET is_banned=1 WHERE id=?', [$id]);
        flash('Kullanıcı engellendi.', 'warning');
    } elseif ($action === 'unban') {
        Database::execute('UPDATE users SET is_banned=0 WHERE id=?', [$id]);
        flash('Engel kaldırıldı.');
    }
    header('Location: users.php' . (($_POST['q'] ?? '') !== '' ? '?q=' . urlencode($_POST['q']) : ''));
    exit;
}

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM users';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE email LIKE ? OR name LIKE ?';
    $params = ["%$q%", "%$q%"];
}
$sql .= ' ORDER BY id DESC LIMIT 200';
$users = Database::fetchAll($sql, $params);

$planBadge = function (int $tier): string {
    return [
        0 => '<span class="badge bg-secondary">Ücretsiz</span>',
        1 => '<span class="badge" style="background:#CD9B6A;color:#1E1608">Bronz</span>',
        2 => '<span class="badge" style="background:#B8C4CE;color:#1E1608">Gümüş</span>',
        3 => '<span class="badge bg-warning text-dark">Altın</span>',
    ][$tier];
};

admin_header('Kullanıcılar', 'users.php');
render_flash();
?>
<form method="get" class="row g-2 mb-3">
    <div class="col-auto"><input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="E-posta / isim ara"></div>
    <div class="col-auto"><button class="btn btn-outline-info">Ara</button></div>
</form>
<div class="card p-3">
    <p class="text-secondary mb-2" style="font-size:12px">
        <strong>Bugün kredi:</strong> kullanılan / günlük hak (+bonus). Bonus krediler günlük hak bitince
        harcanır ve günlük sıfırlanmaz. Krediler her gün otomatik yenilenir.
    </p>
    <table class="table table-sm align-middle">
        <thead><tr>
            <th>#</th><th>E-posta</th><th>İsim</th><th>Paket</th><th>Bitiş</th>
            <th>Bugün kredi</th><th>Durum</th><th>Paket işlemi</th><th>Kredi ekle</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $u):
            $tier = Plans::tierOf($u);
            $daily = Credits::dailyAllowance($tier);
            $used = Credits::usedToday($u);
            $bonusC = Credits::bonus($u);
        ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e($u['name']) ?></td>
                <td><?= $planBadge($tier) ?></td>
                <td><small><?= e($u['premium_until'] ? substr($u['premium_until'], 0, 10) : '-') ?></small></td>
                <td>
                    <span class="text-light"><?= $used ?> / <?= $daily ?></span>
                    <?php if ($bonusC > 0): ?>
                        <span class="badge bg-info text-dark" title="Bonus kredi">+<?= $bonusC ?></span>
                    <?php endif; ?>
                </td>
                <td><?= (int)$u['is_banned'] ? '<span class="badge bg-danger">Engelli</span>' : '<span class="badge bg-success">Aktif</span>' ?></td>
                <td>
                    <form method="post" class="d-flex gap-1 align-items-center">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="q" value="<?= e($q) ?>">
                        <select name="plan" class="form-select form-select-sm" style="width:100px">
                            <?php foreach (['free' => 'Ücretsiz', 'bronz' => 'Bronz', 'gumus' => 'Gümüş', 'altin' => 'Altın'] as $pv => $pl): ?>
                                <option value="<?= $pv ?>" <?= (Plans::TIERS[$u['plan']] ?? 0) === (Plans::TIERS[$pv] ?? -1) ? 'selected' : '' ?>><?= $pl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="days" value="30" min="1" class="form-control form-control-sm" style="width:70px" title="Gün">
                        <button name="action" value="set_plan" class="btn btn-sm btn-outline-warning">Uygula</button>
                    </form>
                </td>
                <td>
                    <form method="post" class="d-flex gap-1 align-items-center">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="q" value="<?= e($q) ?>">
                        <input type="number" name="credits" value="10" class="form-control form-control-sm" style="width:80px" title="Negatif değer bonus siler">
                        <button name="action" value="add_credits" class="btn btn-sm btn-outline-info">Ekle</button>
                    </form>
                </td>
                <td>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="q" value="<?= e($q) ?>">
                        <?php if ((int)$u['is_banned']): ?>
                            <button name="action" value="unban" class="btn btn-sm btn-outline-success">Engeli kaldır</button>
                        <?php else: ?>
                            <button name="action" value="ban" class="btn btn-sm btn-outline-danger">Engelle</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="10" class="text-center text-secondary py-3">Kullanıcı yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php admin_footer();
