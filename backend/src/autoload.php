<?php
/**
 * Basit PSR-4 autoloader (composer kurulu değilse de çalışır).
 * Composer vendor/autoload.php varsa onu tercih eder.
 */

$composer = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
    return;
}

spl_autoload_register(function (string $class) {
    $prefix = 'MacRadar\\';
    $baseDir = __DIR__ . '/';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
