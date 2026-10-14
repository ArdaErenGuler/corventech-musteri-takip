<?php
/**
 * Her sayfanın başında yüklenir: ayarlar, oturum, veritabanı ve yardımcılar.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

$GLOBALS['config'] = require APP_ROOT . '/config.php';

date_default_timezone_set(config('timezone', 'Europe/Istanbul'));
mb_internal_encoding('UTF-8');

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/billing.php';
require __DIR__ . '/social.php';
require __DIR__ . '/layout.php';

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

// Oturum
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('ctpanel');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
