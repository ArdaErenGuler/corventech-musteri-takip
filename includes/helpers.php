<?php
/**
 * Genel yardımcılar: çıktı kaçışı, biçimlendirme, yönlendirme, CSRF, form verisi.
 */

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 1250.5 → "1.250,50 ₺"; küsuratsız tutarlar "1.250 ₺" */
function money($amount, bool $symbol = true): string
{
    $amount = (float) $amount;
    $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
    return number_format($amount, $decimals, ',', '.') . ($symbol ? ' ₺' : '');
}

/** Form alanı için tutar: 6000.00 → "6000", 1250.50 → "1250.5" */
function money_input($amount): string
{
    if ($amount === null || $amount === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
}

/** Milyon ve üstünü kısaltır (4.200.000 → "4,2 Mn ₺"), küsuratı atar; altı tam yazılır */
function money_short($amount): string
{
    $amount = (float) $amount;
    if (abs($amount) >= 1000000) {
        return rtrim(rtrim(number_format($amount / 1000000, 1, ',', '.'), '0'), ',') . ' Mn ₺';
    }
    return number_format(round($amount), 0, ',', '.') . ' ₺';
}

/** "2026-10-13" → "13.10.2026" */
function date_tr(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : '—';
}

function datetime_tr(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d.m.Y H:i', $ts) : '—';
}

const TR_MONTHS = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
const TR_MONTHS_SHORT = ['', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

function today(): string
{
    return date('Y-m-d');
}

/** Bugüne göre gün farkı: negatif = geçmiş */
function days_until(string $date): int
{
    $a = new DateTimeImmutable(today());
    $b = new DateTimeImmutable($date);
    return (int) $a->diff($b)->format('%r%a');
}

/** "3 gün kaldı", "Bugün", "5 gün gecikti" */
function relative_due(string $date): string
{
    $d = days_until($date);
    if ($d === 0) {
        return 'Bugün';
    }
    if ($d === 1) {
        return 'Yarın';
    }
    return $d > 0 ? "$d gün kaldı" : abs($d) . ' gün gecikti';
}

/** Statik dosya adresi; sürüm parametresi dosya değiştikçe yenilenir (önbellek kırma) */
function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    return 'assets/' . $path . '?v=' . (is_file($file) ? filemtime($file) : APP_VERSION);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Oturum süresi doldu veya form geçersiz. Sayfayı yenileyip tekrar deneyin.');
    }
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/* ---------- Girdi ---------- */

function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function input_int(string $key): ?int
{
    $v = input($key);
    return $v === '' || !ctype_digit($v) ? null : (int) $v;
}

/** "1.250,50" / "1250.50" / "1250" → 1250.5 */
function input_money(string $key): ?float
{
    $v = str_replace([' ', '₺'], '', input($key));
    if ($v === '') {
        return null;
    }
    if (str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    }
    return is_numeric($v) ? round((float) $v, 2) : null;
}

/** YYYY-MM-DD doğrulaması; geçersizse null */
function input_date(string $key): ?string
{
    $v = input($key);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : null;
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="error-text">' . e($errors[$key]) . '</span>' : '';
}

/** Form tekrar gösterildiğinde eski değeri getirir */
function old(string $key, $fallback = ''): string
{
    return (string) ($_POST[$key] ?? $fallback ?? '');
}

/* ---------- Telefon / WhatsApp ---------- */

function phone_digits(?string $phone): string
{
    return preg_replace('/\D+/', '', (string) $phone);
}

/** 0555 123 45 67 → 905551234567 */
function wa_number(?string $phone): string
{
    $d = phone_digits($phone);
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if (str_starts_with($d, '0')) {
        $d = '90' . substr($d, 1);
    } elseif (strlen($d) === 10 && $d[0] === '5') {
        $d = '90' . $d;
    }
    return $d;
}

function wa_link(?string $phone, string $text = ''): string
{
    $n = wa_number($phone);
    if ($n === '') {
        return '';
    }
    return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function tel_link(?string $phone): string
{
    $d = phone_digits($phone);
    return $d === '' ? '' : 'tel:' . (str_starts_with((string) $phone, '+') ? '+' : '') . $d;
}

/* ---------- Diğer ---------- */

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

function customer_display_name(array $c): string
{
    return $c['company_name'] !== null && $c['company_name'] !== '' ? $c['company_name'] : $c['contact_name'];
}

/** Sorgu dizesini mevcut parametrelerle birleştirir */
function url_with(array $params): string
{
    $q = array_merge($_GET, $params);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return basename($path) . ($q ? '?' . http_build_query($q) : '');
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function not_found(): void
{
    http_response_code(404);
    layout_start('Bulunamadı', '');
    echo '<div class="empty"><p>Aradığınız kayıt bulunamadı.</p><a class="btn" href="dashboard.php">Ana sayfaya dön</a></div>';
    layout_end();
    exit;
}
