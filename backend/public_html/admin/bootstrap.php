<?php
/**
 * Admin panel ortak başlangıç: config, autoload, session, kimlik doğrulama ve layout.
 */

require_once dirname(__DIR__, 2) . '/src/autoload.php';

use MacRadar\Core\Config;
use MacRadar\Core\Database;

Config::load();
date_default_timezone_set(Config::get('app.timezone', 'Europe/Istanbul'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('macradar_admin');
    session_start();
}

/** Giriş yapılmış mı? Yapılmadıysa login'e yönlendir. */
function admin_require_login(): array
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'] ?? '',
        'name' => $_SESSION['admin_name'] ?? '',
    ];
}

/** CSRF token üret/al */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF doğrulaması başarısız. Sayfayı yenileyin.');
    }
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Sayfa başlığı ve navigasyon ile HTML layout başlığı */
function admin_header(string $title, string $active = ''): void
{
    $nav = [
        'index.php' => ['Panel', 'speedometer2'],
        'matches.php' => ['Maçlar', 'calendar-event'],
        'analyses.php' => ['Analizler', 'robot'],
        'ai_settings.php' => ['AI Ayarları', 'cpu'],
        'users.php' => ['Kullanıcılar', 'people'],
        'scraper.php' => ['Scraper', 'cloud-download'],
        'settings.php' => ['Genel Ayarlar', 'gear'],
    ];
    ?>
<!doctype html>
<html lang="tr" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — MaçRadar Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background:#0d1b2a; }
        .sidebar { background:#1b263b; min-height:100vh; }
        .sidebar .nav-link { color:#cbd5e1; border-radius:.5rem; margin:.15rem .5rem; }
        .sidebar .nav-link.active { background:#00e676; color:#0d1b2a; font-weight:600; }
        .sidebar .nav-link:hover:not(.active){ background:#22314a; color:#fff; }
        .brand { color:#00e676; font-weight:800; letter-spacing:.5px; }
        .card { background:#1b263b; border:1px solid #22314a; }
        .stat { font-size:2rem; font-weight:700; }
        .table { --bs-table-bg:transparent; color:#e2e8f0; }
        a { color:#4dd0e1; }
    </style>
</head>
<body>
<div class="container-fluid"><div class="row">
    <nav class="col-md-2 d-none d-md-block sidebar py-3">
        <h4 class="brand px-3 mb-4"><i class="bi bi-graph-up-arrow"></i> MaçRadar</h4>
        <ul class="nav flex-column">
            <?php foreach ($nav as $file => [$label, $icon]): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active === $file ? 'active' : '' ?>" href="<?= $file ?>">
                        <i class="bi bi-<?= $icon ?>"></i> <?= $label ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <li class="nav-item mt-3"><a class="nav-link text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Çıkış</a></li>
        </ul>
    </nav>
    <main class="col-md-10 ms-sm-auto px-md-4 py-4">
        <h2 class="mb-4 text-light"><?= e($title) ?></h2>
    <?php
}

function admin_footer(): void
{
    ?>
    </main>
</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function render_flash(): void
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show">' . e($f['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}
