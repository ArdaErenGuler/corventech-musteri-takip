<?php
/**
 * Vercel giriş noktası: bütün sayfa istekleri buraya gelir (vercel.json → routes).
 * İstenen sayfayı (dashboard.php, musteriler.php …) proje kökünden çalıştırır.
 * Paylaşımlı hostingte bu dosya kullanılmaz; sayfalar doğrudan açılır.
 *
 * Yerelde Vercel'i taklit etmek için:  php -S localhost:8091 api/index.php
 */

$root = dirname(__DIR__);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Yerel test sunucusu: statik dosyaları (CSS, ikon, manifest) olduğu gibi ver
if (PHP_SAPI === 'cli-server' && preg_match('#^/(assets/|manifest\.json$|sw\.js$|offline\.html$)#', $path) && is_file($root . $path)) {
    return false;
}

$page = trim($path, '/');
if ($page === '') {
    $page = 'index.php';
}
if (!str_ends_with($page, '.php')) {
    $page .= '.php';
}

// Yalnızca kökteki panel sayfaları; ayar, kod ve test dosyaları asla
$blocked = ['config.php', 'config.local.php'];
if (!preg_match('/^[a-z\-]+\.php$/', $page) || in_array($page, $blocked, true) || !is_file("$root/$page")) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Bulunamadı</title><div style="font-family:system-ui;max-width:480px;margin:15vh auto;padding:24px"><h1 style="font-size:20px">Sayfa bulunamadı</h1><p><a href="/">Panele dön</a></p></div>');
}

chdir($root);
$_SERVER['SCRIPT_NAME'] = '/' . $page;
$_SERVER['PHP_SELF'] = '/' . $page;
$_SERVER['SCRIPT_FILENAME'] = "$root/$page";
require "$root/$page";
