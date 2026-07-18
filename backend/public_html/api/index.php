<?php
/**
 * MaçRadar REST API giriş noktası.
 * Tüm /api/v1/* istekleri buraya yönlenir (.htaccess ile).
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
use MacRadar\Core\Request;
use MacRadar\Core\Response;
use MacRadar\Core\Router;
use MacRadar\Controllers\Api\AuthController;
use MacRadar\Controllers\Api\MatchController;
use MacRadar\Controllers\Api\AnalysisController;

Config::load();
date_default_timezone_set(Config::get('app.timezone', 'Europe/Istanbul'));
if (Config::get('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
set_time_limit(120);

Response::applyCors();

$request = new Request();
$router = new Router();

$auth = new AuthController();
$matches = new MatchController();
$analysis = new AnalysisController();

// ---- Sağlık kontrolü ----
$router->get('/health', fn() => Response::ok(['status' => 'ok', 'time' => date('c')]));

// ---- Auth ----
$router->post('/auth/register', [$auth, 'register']);
$router->post('/auth/login', [$auth, 'login']);
$router->post('/auth/refresh', [$auth, 'refresh']);
$router->get('/me', [$auth, 'me']);
$router->post('/me/fcm', [$auth, 'updateFcm']);

// ---- Maçlar / Ligler ----
$router->get('/leagues', [$matches, 'leagues']);
$router->get('/matches', [$matches, 'index']);
// NOT: /matches/live, /matches/{id} kalıbından ÖNCE kayıtlı olmalı
$router->get('/matches/live', [$matches, 'live']);
$router->get('/matches/{id}', [$matches, 'show']);

// ---- Analiz ----
$router->post('/matches/{id}/analyze', [$analysis, 'analyze']);
$router->get('/matches/{id}/analysis', [$analysis, 'show']);

// ---- Favoriler ----
$router->get('/favorites', [$matches, 'favorites']);
$router->post('/favorites', [$matches, 'addFavorite']);
$router->delete('/favorites/{id}', [$matches, 'removeFavorite']);

// ---- Analizlerim / Günün Kuponu ----
$router->get('/me/analyses', [$matches, 'myAnalyses']);
$router->get('/coupon/daily', [$matches, 'dailyCoupon']);

// ---- İstatistik ----
$router->get('/stats/success-rate', [$matches, 'successRate']);

try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    Response::error(
        'server_error',
        Config::get('app.debug') ? $e->getMessage() : 'Sunucu hatası oluştu.',
        500
    );
}
