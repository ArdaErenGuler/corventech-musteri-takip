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
require __DIR__ . '/finance.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/session.php';
require __DIR__ . '/migrate.php';
require __DIR__ . '/backup.php';

function config(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

// Yeni sürüm yüklendiyse veritabanı güncellemelerini uygula (oturum tablosu dahil), sonra oturumu aç
auto_migrate();

// Oturum (veritabanında, 30 gün)
start_app_session();
// Kullanıldıkça çerez süresini uzat: her gün en az bir kez açılan panelde oturum hiç düşmez
if (!empty($_SESSION['user_id']) && ($_SESSION['cookie_refreshed'] ?? '') !== date('Y-m-d')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + SESSION_LIFETIME,
        'path'     => $p['path'],
        'secure'   => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_SESSION['cookie_refreshed'] = date('Y-m-d');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
