<?php
/**
 * Corventech Panel — ayarlar.
 *
 * Değerler sırasıyla şuradan okunur (sonraki öncekini ezer):
 *   1. Bu dosyadaki varsayılanlar (yerel XAMPP için)
 *   2. config.local.php (aynı klasörde; git'e gönderilmez) — paylaşımlı hosting için
 *   3. Ortam değişkenleri (DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT, DB_SSL, SETUP_KEY, TRUST_PROXY) — Vercel vb. için
 */

$config = [
    // Veritabanı
    'db_host'    => 'localhost',
    'db_port'    => 3306,
    'db_name'    => 'corventech_panel',
    'db_user'    => 'root',
    'db_pass'    => '',
    'db_ssl'     => false,   // Uzak/bulut MySQL için true (TLS ile bağlanır)

    // Uygulama
    'app_name'   => 'Corventech',
    'timezone'   => 'Europe/Istanbul',
    'debug'      => false,   // Canlıda false kalmalı; true iken hatalar ekranda görünür

    // İlk yönetici hesabı oluşturulurken sorulacak anahtar. Canlıda MUTLAKA doldurun;
    // boşsa (yalnızca yerelde) siteyi ilk açan kişi yönetici hesabı oluşturabilir.
    'setup_key'  => '',

    // Vercel / Cloudflare gibi vekil sunucu arkasındaysa true (gerçek ziyaretçi IP'si başlıktan okunur)
    'trust_proxy' => false,

    // Kaç gün sonrasına kadar ödeme kayıtları önceden oluşturulsun
    'due_horizon_days' => 60,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}

$env = [
    'DB_HOST' => 'db_host', 'DB_PORT' => 'db_port', 'DB_NAME' => 'db_name',
    'DB_USER' => 'db_user', 'DB_PASS' => 'db_pass', 'DB_SSL' => 'db_ssl', 'SETUP_KEY' => 'setup_key',
    'TRUST_PROXY' => 'trust_proxy',
];
foreach ($env as $var => $key) {
    $value = getenv($var);
    if ($value !== false && $value !== '') {
        $config[$key] = in_array($key, ['db_ssl', 'trust_proxy'], true) ? in_array(strtolower($value), ['1', 'true', 'yes'], true) : $value;
    }
}

return $config;
