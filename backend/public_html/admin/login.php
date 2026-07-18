<?php
require __DIR__ . '/bootstrap.php';

use MacRadar\Core\Database;

if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $admin = Database::fetch('SELECT * FROM admins WHERE username = ?', [$username]);
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_name'] = $admin['name'];
        header('Location: index.php');
        exit;
    }
    $error = 'Kullanıcı adı veya şifre hatalı.';
}
?>
<!doctype html>
<html lang="tr" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş — MaçRadar Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#0d1b2a;} .card{background:#1b263b;border:1px solid #22314a;} .brand{color:#00e676;font-weight:800;}</style>
</head>
<body>
<div class="container" style="max-width:400px;margin-top:12vh;">
    <div class="text-center mb-4"><h2 class="brand">📊 MaçRadar</h2><p class="text-secondary">Yönetim Paneli</p></div>
    <div class="card p-4">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <div class="mb-3">
                <label class="form-label">Kullanıcı adı</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Şifre</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button class="btn btn-success w-100">Giriş Yap</button>
        </form>
    </div>
</div>
</body>
</html>
