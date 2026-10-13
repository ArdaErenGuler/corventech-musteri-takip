<?php
/**
 * Corventech Panel — ayarlar.
 *
 * Sunucuya yüklerken veritabanı bilgilerini burada düzenleyin
 * ya da aynı klasöre config.local.php koyup yalnızca değişen anahtarları yazın
 * (config.local.php git'e gönderilmez).
 */

$config = [
    // Veritabanı
    'db_host'    => 'localhost',
    'db_port'    => 3306,
    'db_name'    => 'corventech_panel',
    'db_user'    => 'root',
    'db_pass'    => '',

    // Uygulama
    'app_name'   => 'Corventech',
    'timezone'   => 'Europe/Istanbul',
    'debug'      => false,   // Canlıda false kalmalı; true iken hatalar ekranda görünür

    // Kaç gün sonrasına kadar ödeme kayıtları önceden oluşturulsun
    'due_horizon_days' => 60,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}

return $config;
