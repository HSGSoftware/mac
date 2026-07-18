<?php
require __DIR__ . '/bootstrap.php';
admin_require_login();

use MacRadar\Core\Database;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) $_POST['user_id'];
    $action = $_POST['action'] ?? '';
    if ($action === 'make_premium') {
        $until = date('Y-m-d H:i:s', strtotime('+30 day'));
        Database::execute("UPDATE users SET plan='premium', premium_until=? WHERE id=?", [$until, $id]);
        flash('Kullanıcı 30 gün premium yapıldı.');
    } elseif ($action === 'remove_premium') {
        Database::execute("UPDATE users SET plan='free', premium_until=NULL WHERE id=?", [$id]);
        flash('Premium kaldırıldı.');
    } elseif ($action === 'ban') {
        Database::execute('UPDATE users SET is_banned=1 WHERE id=?', [$id]);
        flash('Kullanıcı engellendi.', 'warning');
    } elseif ($action === 'unban') {
        Database::execute('UPDATE users SET is_banned=0 WHERE id=?', [$id]);
        flash('Engel kaldırıldı.');
    }
    header('Location: users.php');
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

admin_header('Kullanıcılar', 'users.php');
render_flash();
?>
<form method="get" class="row g-2 mb-3">
    <div class="col-auto"><input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="E-posta / isim ara"></div>
    <div class="col-auto"><button class="btn btn-outline-info">Ara</button></div>
</form>
<div class="card p-3">
    <table class="table table-sm align-middle">
        <thead><tr><th>#</th><th>E-posta</th><th>İsim</th><th>Plan</th><th>Premium bitiş</th><th>Durum</th><th>İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e($u['name']) ?></td>
                <td><span class="badge bg-<?= $u['plan']==='premium'?'warning text-dark':'secondary' ?>"><?= e($u['plan']) ?></span></td>
                <td><small><?= e($u['premium_until']) ?></small></td>
                <td><?= (int)$u['is_banned'] ? '<span class="badge bg-danger">Engelli</span>' : '<span class="badge bg-success">Aktif</span>' ?></td>
                <td class="d-flex gap-1">
                    <?php $mk = function($action,$label,$cls) use ($u){ ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button name="action" value="<?= $action ?>" class="btn btn-sm <?= $cls ?>"><?= $label ?></button>
                        </form>
                    <?php };
                    if ($u['plan'] === 'premium') $mk('remove_premium','Premium kaldır','btn-outline-secondary');
                    else $mk('make_premium','Premium yap','btn-outline-warning');
                    if ((int)$u['is_banned']) $mk('unban','Engeli kaldır','btn-outline-success');
                    else $mk('ban','Engelle','btn-outline-danger');
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?><tr><td colspan="7" class="text-center text-secondary py-3">Kullanıcı yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php admin_footer();
