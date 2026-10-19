<?php
/**
 * Oturumlar veritabanında tutulur.
 *  - Telefonda uygulama gibi kullanırken 1 yıl boyunca (her kullanımda uzar) tekrar giriş istenmez
 *    (PHP'nin varsayılan dosya oturumu 24 dakikada silinir, tarayıcı kapanınca biter).
 *  - Birden çok sunucu örneği olan ortamlarda (Vercel vb.) oturum kaybolmaz.
 */

const SESSION_LIFETIME = 60 * 60 * 24 * 365;   // 1 yıl (her kullanımda yenilenir; panel kapalı kalmadıkça giriş istemez)

class DbSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = q_val('SELECT data FROM sessions WHERE id = ? AND last_activity > ?', [$id, time() - SESSION_LIFETIME]);
        return $data === null ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        q('INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)', [$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        q('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return q('DELETE FROM sessions WHERE last_activity < ?', [time() - SESSION_LIFETIME])->rowCount();
    }
}

function start_app_session(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_strict_mode', '1');
    session_set_save_handler(new DbSessionHandler(), true);
    session_name('ctpanel');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
