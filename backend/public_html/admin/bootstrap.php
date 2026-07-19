<?php
/**
 * Admin panel ortak başlangıç: config, autoload, session, kimlik doğrulama ve layout.
 */

// autoload.php'yi yukarı doğru ara (farklı dizin düzenlerine dayanıklı)
(function () {
    $dir = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        if (is_file($dir . '/src/autoload.php')) {
            require_once $dir . '/src/autoload.php';
            return;
        }
        $dir = dirname($dir);
    }
    http_response_code(500);
    exit('autoload.php bulunamadı.');
})();

use MacRadar\Core\Config;
use MacRadar\Core\Database;

Config::load();
date_default_timezone_set(Config::get('app.timezone', 'Europe/Istanbul'));
// AI analizi/scrape uzun sürebilir; admin işlemlerinde zaman aşımını yükselt
@set_time_limit(150);

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
        'prompt_logs.php' => ['Prompt Kayıtları', 'journal-text'],
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
        .sidebar { background:#1b263b; }
        .nav-links .nav-link { color:#cbd5e1; border-radius:.6rem; margin:.15rem .5rem; padding:.6rem .9rem; }
        .nav-links .nav-link.active { background:#00e676; color:#0d1b2a; font-weight:600; }
        .nav-links .nav-link:hover:not(.active){ background:#22314a; color:#fff; }
        .brand { color:#00e676; font-weight:800; letter-spacing:.5px; }
        .card { background:#1b263b; border:1px solid #22314a; }
        .stat { font-size:1.9rem; font-weight:700; }
        .table { --bs-table-bg:transparent; color:#e2e8f0; }
        .offcanvas { background:#1b263b; }
        a { color:#4dd0e1; }
        @media (max-width: 767.98px){
            .stat{ font-size:1.5rem; } h2{ font-size:1.4rem; }
            .table{ display:block; overflow-x:auto; white-space:nowrap; }
            .btn{ font-size:.85rem; }
        }
    </style>
</head>
<body>
<?php
    // Ortak navigasyon linkleri (hem sidebar hem offcanvas kullanır)
    $renderNav = function () use ($nav, $active) {
        echo '<ul class="nav nav-links flex-column">';
        foreach ($nav as $file => [$label, $icon]) {
            $cls = $active === $file ? 'active' : '';
            echo '<li class="nav-item"><a class="nav-link ' . $cls . '" href="' . $file . '"><i class="bi bi-' . $icon . '"></i> ' . $label . '</a></li>';
        }
        echo '<li class="nav-item mt-2"><a class="nav-link text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Çıkış</a></li>';
        echo '</ul>';
    };
?>
<!-- Mobil üst çubuk -->
<nav class="navbar d-md-none sticky-top" style="background:#1b263b;">
    <div class="container-fluid">
        <span class="brand"><i class="bi bi-graph-up-arrow"></i> MaçRadar</span>
        <button class="btn btn-outline-light border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav">
            <i class="bi bi-list fs-3"></i>
        </button>
    </div>
</nav>
<!-- Mobil offcanvas menü -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileNav">
    <div class="offcanvas-header">
        <h5 class="brand mb-0"><i class="bi bi-graph-up-arrow"></i> MaçRadar</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0"><?php $renderNav(); ?></div>
</div>

<div class="container-fluid"><div class="row">
    <nav class="col-md-2 d-none d-md-block sidebar py-3" style="min-height:100vh;">
        <h4 class="brand px-3 mb-4"><i class="bi bi-graph-up-arrow"></i> MaçRadar</h4>
        <?php $renderNav(); ?>
    </nav>
    <main class="col-12 col-md-10 ms-sm-auto px-3 px-md-4 py-4">
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
