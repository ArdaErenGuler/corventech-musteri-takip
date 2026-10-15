<?php
/**
 * Oturum açma, yetki kontrolü ve giriş denemesi sınırı.
 */

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $user = q_one('SELECT id, name, email, role FROM users WHERE id = ? AND is_active = 1', [$_SESSION['user_id']]);
            if (!$user) {
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            json_response(['error' => 'Oturum kapalı'], 401);
        }
        redirect('login.php');
    }
    sync_all_dues();
    sync_recurring_transactions();
    return $user;
}

function require_admin(): void
{
    if (!is_admin()) {
        flash('error', 'Bu işlem için yönetici yetkisi gerekiyor.');
        redirect('dashboard.php');
    }
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function login_locked(): bool
{
    $count = (int) q_val(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)',
        [client_ip(), LOGIN_LOCK_MINUTES]
    );
    return $count >= LOGIN_MAX_ATTEMPTS;
}

function attempt_login(string $email, string $password): bool
{
    $user = q_one('SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1', [$email]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        q('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())', [client_ip()]);
        return false;
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    q('DELETE FROM login_attempts WHERE ip = ? OR attempted_at < (NOW() - INTERVAL 1 DAY)', [client_ip()]);
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function users_exist(): bool
{
    return (int) q_val('SELECT COUNT(*) FROM users') > 0;
}
