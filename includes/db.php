<?php
/**
 * PDO bağlantısı (tek örnek) ve kısa sorgu yardımcıları.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db_host'),
            (int) config('db_port', 3306),
            config('db_name')
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (config('db_ssl')) {
            // Bulut MySQL (TLS zorunlu): sistemdeki sertifika paketini kullan
            foreach (['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'] as $ca) {
                if (is_file($ca)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                    break;
                }
            }
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        try {
            $pdo = new PDO($dsn, config('db_user'), config('db_pass'), $options);
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            $msg = config('debug') ? htmlspecialchars($e->getMessage()) : 'config.php içindeki veritabanı bilgilerini kontrol edin.';
            exit('<!doctype html><meta charset="utf-8"><title>Bağlantı hatası</title>'
                . '<div style="font-family:system-ui;max-width:520px;margin:15vh auto;padding:24px">'
                . '<h1 style="font-size:20px">Veritabanına bağlanılamadı</h1><p>' . $msg . '</p></div>');
        }
    }
    return $pdo;
}

/** Sorguyu çalıştırır, PDOStatement döner. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $val = q($sql, $params)->fetchColumn();
    return $val === false ? null : $val;
}

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT `key`, `value` FROM settings') as $r) {
            $cache[$r['key']] = $r['value'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    q('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
}
