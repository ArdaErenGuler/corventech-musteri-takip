<?php
/**
 * Sayfa iskeleti: <head>, yan menü / çekmece, üst bar, + butonu (FAB).
 * Kullanım: layout_start('Başlık', 'menu-anahtari', [...]); ...içerik... layout_end();
 */

const NAV_ITEMS = [
    'dashboard'  => ['dashboard.php', 'Dashboard', 'grid'],
    'customers'  => ['musteriler.php', 'Müşteriler', 'users'],
    'calendar'   => ['takvim.php', 'Sosyal Medya Takvimi', 'calendar'],
    'orders'     => ['siparisler.php', 'Siparişler', 'bag'],
    'payments'   => ['odemeler.php', 'Ödemeler', 'wallet'],
    'renewals'   => ['yenilemeler.php', 'Yenilemeler', 'refresh'],
    'tasks'      => ['gorevler.php', 'Görevler', 'check-square'],
    'finance'    => ['gelir-gider.php', 'Gelir / Gider', 'briefcase'],
    'reports'    => ['raporlar.php', 'Raporlar', 'chart'],
    'backup'     => ['yedekleme.php', 'Yedekleme', 'download'],
    'settings'   => ['ayarlar.php', 'Ayarlar', 'sliders'],
];

function icon(string $name, string $class = ''): string
{
    return '<svg class="i ' . e($class) . '" aria-hidden="true"><use href="#i-' . e($name) . '"/></svg>';
}

function logo_svg(string $class = 'logo-mark'): string
{
    return '<svg class="' . e($class) . '" viewBox="0 0 64 64" aria-hidden="true">'
        . '<defs><linearGradient id="lg-grad" gradientUnits="userSpaceOnUse" x1="8" y1="58" x2="56" y2="8">'
        . '<stop offset="0" stop-color="#2a1160"/><stop offset=".55" stop-color="#6330cc"/><stop offset="1" stop-color="#8f5cf7"/></linearGradient>'
        . '<mask id="lg-mask"><rect width="64" height="64" fill="#000"/><circle cx="32" cy="32" r="21.2" fill="none" stroke="#fff" stroke-width="7.6"/>'
        . '<rect x="24" y="20.4" width="40" height="13.2" fill="#000"/><path d="M27.1 31H40.9L39.8 64H28.2Z" fill="#000"/></mask></defs>'
        . '<circle cx="32" cy="32" r="21.2" fill="none" stroke="url(#lg-grad)" stroke-width="7.6" mask="url(#lg-mask)"/>'
        . '<path d="M20.25 23H56L53.6 31H38.3L37.4 58H30.6L29.7 31H17.23A14.8 14.8 0 0 1 20.25 23Z" fill="url(#lg-grad)"/></svg>';
}

function head_tags(string $title): void
{
    $app = setting('company_name') ?: config('app_name');
    ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e($app) ?></title>
<meta name="theme-color" content="#f6f5fb" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0d0b17" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e($app) ?>">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="assets/icons/favicon.svg" type="image/svg+xml">
<link rel="icon" href="assets/icons/icon-192.png" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<script>
(function () {
    try {
        var t = localStorage.getItem('theme');
        if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}
})();
</script>
</head>
    <?php
}

/**
 * @param array $opts back => geri dönüş adresi (hamburger yerine geri oku gösterir)
 *                    fab  => ['href' => ..., 'label' => ...] veya false
 *                    actions => üst bar sağına eklenecek HTML
 */
function layout_start(string $title, string $active, array $opts = []): void
{
    $user = current_user();
    $GLOBALS['layout_opts'] = $opts;
    head_tags($title);
    $soon = (int) q_val('SELECT COUNT(*) FROM (' . DUES_SELECT . ' WHERE ' . DUES_UNPAID . ' AND d.due_date <= CURDATE() + INTERVAL 7 DAY) x');
    $openTasks = (int) q_val("SELECT COUNT(*) FROM tasks WHERE status <> 'done' AND (assigned_to = ? OR assigned_to IS NULL)", [$user['id']]);
    $duePosts = $active === 'calendar' || $active === 'dashboard' ? [] : due_posts();
    $postCount = (int) q_val("SELECT COUNT(*) FROM posts WHERE status <> 'published' AND scheduled_date <= CURDATE()");
    $badges = ['renewals' => $soon, 'tasks' => $openTasks, 'calendar' => $postCount, 'backup' => backup_due() ? '!' : 0];
    ?>
<body>
<?php require __DIR__ . '/icons.php'; ?>
<a class="skip" href="#main">İçeriğe geç</a>
<div class="app">
    <aside class="drawer" id="drawer" aria-label="Ana menü">
        <div class="drawer-head">
            <a class="brand" href="dashboard.php"><?= logo_svg() ?><span><?= e(setting('company_name') ?: config('app_name')) ?></span></a>
            <button class="icon-btn drawer-close" type="button" data-drawer-close aria-label="Menüyü kapat"><?= icon('x') ?></button>
        </div>
        <nav class="nav">
            <?php foreach (NAV_ITEMS as $key => [$href, $label, $ic]): ?>
                <a href="<?= $href ?>" class="nav-link<?= $key === $active ? ' is-active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                    <?= icon($ic) ?><span><?= e($label) ?></span>
                    <?php if (!empty($badges[$key])): ?><b class="nav-badge<?= in_array($key, ['renewals', 'calendar'], true) ? ' is-alert' : ($key === 'backup' ? ' is-warning' : '') ?>"><?= $badges[$key] ?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="drawer-foot">
            <div class="me">
                <span class="avatar sm"><?= e(initials($user['name'])) ?></span>
                <span class="me-text"><b><?= e($user['name']) ?></b><small><?= $user['role'] === 'admin' ? 'Yönetici' : 'Kullanıcı' ?></small></span>
            </div>
            <form method="post" action="logout.php">
                <?= csrf_field() ?>
                <button class="nav-link logout" type="submit"><?= icon('logout') ?><span>Çıkış</span></button>
            </form>
        </div>
    </aside>
    <div class="scrim" data-drawer-close hidden></div>

    <div class="main">
        <header class="topbar">
            <?php if (!empty($opts['back'])): ?>
                <a class="icon-btn" href="<?= e($opts['back']) ?>" aria-label="Geri"><?= icon('chevron-left') ?></a>
            <?php else: ?>
                <button class="icon-btn menu-btn" type="button" data-drawer-open aria-label="Menüyü aç" aria-controls="drawer" aria-expanded="false"><?= icon('menu') ?></button>
            <?php endif; ?>
            <h1 class="topbar-title"><?= e($title) ?></h1>
            <div class="topbar-actions">
                <?= $opts['actions'] ?? '' ?>
                <button class="icon-btn" type="button" data-theme-toggle aria-label="Temayı değiştir">
                    <?= icon('moon', 'theme-dark-icon') ?><?= icon('sun', 'theme-light-icon') ?>
                </button>
            </div>
        </header>
        <main class="content" id="main">
            <?php if ($duePosts):
                $todayCount = count(array_filter($duePosts, fn($p) => $p['scheduled_date'] === today()));
                $lateCount = count($duePosts) - $todayCount;
                $names = array_unique(array_filter(array_map(fn($p) => $p['customer_name'] ?: $p['title'], $duePosts))); ?>
                <div class="today-banner" data-banner="<?= today() ?>">
                <a class="today-banner-link" href="takvim.php?gun=<?= today() ?>">
                    <span class="today-banner-icon"><?= icon('calendar') ?></span>
                    <span class="today-banner-text">
                        <b><?= $todayCount ? "Bugün $todayCount paylaşım var" : '' ?><?= $todayCount && $lateCount ? ' · ' : '' ?><?= $lateCount ? "$lateCount paylaşım gecikti" : '' ?></b>
                        <small><?= e(implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '…' : '')) ?></small>
                    </span>
                </a>
                <button class="today-banner-close" type="button" data-banner-close aria-label="Bugün için gizle"><?= icon('x') ?></button>
                </div>
                <script>try { if (localStorage.getItem('banner-hidden') === '<?= today() ?>') document.querySelector('[data-banner]').hidden = true; } catch (e) {}</script>
            <?php endif; ?>
            <?php if (backup_due() && $active !== 'backup'):
                $bd = days_since_backup(); ?>
                <a class="flash flash-warning" href="yedekleme.php">
                    <?= icon('alert') ?><span><?= $bd === null ? 'Henüz yedek alınmadı.' : "Son yedek $bd gün önce alındı." ?> <u>Şimdi yedek alın</u></span>
                </a>
            <?php endif; ?>
            <?php foreach (take_flashes() as $f): ?>
                <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= icon($f['type'] === 'error' ? 'alert' : 'check') ?><span><?= e($f['message']) ?></span></div>
            <?php endforeach; ?>
    <?php
}

function layout_end(): void
{
    $opts = $GLOBALS['layout_opts'] ?? [];
    $fab = $opts['fab'] ?? ['href' => 'musteri-form.php', 'label' => 'Yeni müşteri'];
    ?>
        </main>
    </div>
</div>
<?php if ($fab): ?>
    <a class="fab" href="<?= e($fab['href']) ?>"><?= icon('plus') ?><span><?= e($fab['label']) ?></span></a>
<?php endif; ?>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="<?= asset('js/app.js') ?>" defer></script>
</body>
</html>
    <?php
}

/** Giriş ekranı gibi menüsüz sayfalar için */
function bare_start(string $title): void
{
    head_tags($title);
    echo '<body class="bare">';
    require __DIR__ . '/icons.php';
    echo '<main class="auth-wrap">';
}

function bare_end(): void
{
    echo '</main><script src="' . asset('js/app.js') . '" defer></script></body></html>';
}

/* ---------- Küçük bileşenler ---------- */

function badge(string $text, string $tone = 'neutral', ?string $ic = null): string
{
    return '<span class="badge tone-' . e($tone) . '">' . ($ic ? icon($ic) : '') . e($text) . '</span>';
}

function customer_status_badge(string $status): string
{
    $tone = ['active' => 'good', 'potential' => 'accent', 'passive' => 'neutral'][$status] ?? 'neutral';
    return badge(CUSTOMER_STATUS_LABELS[$status] ?? $status, $tone);
}

function order_status_badge(string $status): string
{
    $tone = ['active' => 'good', 'paused' => 'warning', 'cancelled' => 'neutral', 'completed' => 'accent'][$status] ?? 'neutral';
    return badge(ORDER_STATUS_LABELS[$status] ?? $status, $tone);
}

function due_state_badge(array $d): string
{
    [$label, $tone, $ic] = DUE_STATE_LABELS[due_state($d)];
    return badge($label, $tone, $ic);
}

function contact_buttons(array $c, string $waText = ''): string
{
    $out = '';
    if ($tel = tel_link($c['phone'] ?? '')) {
        $out .= '<a class="icon-btn soft" href="' . e($tel) . '" aria-label="Ara">' . icon('phone') . '</a>';
    }
    if ($wa = wa_link($c['phone'] ?? '', $waText)) {
        $out .= '<a class="icon-btn soft wa" href="' . e($wa) . '" target="_blank" rel="noopener" aria-label="WhatsApp\'tan yaz">' . icon('whatsapp') . '</a>';
    }
    return $out;
}

/** Sayfalama: toplam kayıt, sayfa başı, mevcut sayfa */
function pagination(int $total, int $perPage, int $page): string
{
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="pager" aria-label="Sayfalar">';
    if ($page > 1) {
        $out .= '<a class="btn ghost" href="' . e(url_with(['page' => $page - 1])) . '">' . icon('chevron-left') . 'Önceki</a>';
    }
    $out .= '<span class="muted">' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) {
        $out .= '<a class="btn ghost" href="' . e(url_with(['page' => $page + 1])) . '">Sonraki' . icon('chevron-right') . '</a>';
    }
    return $out . '</nav>';
}

/** Beklenen ödeme kartı (Dashboard, Yenilemeler, müşteri detayı) */
function due_item(array $d, bool $showCustomer = true): string
{
    $state = due_state($d);
    $name = customer_display_name($d);
    $open = !in_array($state, ['paid', 'waived'], true);
    $dateClass = $state === 'overdue' ? ' text-critical' : '';
    ob_start(); ?>
    <div class="item due-card">
        <div class="item-main">
            <?php if ($showCustomer): ?>
                <a class="item-title" href="musteri.php?id=<?= (int) $d['customer_id'] ?>#odemeler"><?= e($name) ?></a>
                <div class="item-sub"><span><?= e(order_name($d)) ?> · <?= e(due_label($d)) ?></span></div>
            <?php else: ?>
                <span class="item-title"><?= e(order_name($d)) ?></span>
                <div class="item-sub"><span><?= e(due_label($d)) ?></span></div>
            <?php endif; ?>
            <div class="item-sub">
                <span class="<?= $dateClass ?>"><?= icon('calendar') ?><?= date_tr($d['due_date']) ?><?= $open ? ' · ' . e(relative_due($d['due_date'])) : '' ?></span>
                <span><?= e(CYCLE_LABELS[$d['billing_cycle']] ?? '') ?></span>
                <?php if ((float) $d['paid'] > 0 && $open): ?><span>Ödenen <?= money($d['paid']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="item-side">
            <span class="item-amount"><?= money($open ? $d['remaining'] : $d['amount']) ?></span>
            <?= due_state_badge($d) ?>
        </div>
        <?php if ($open): ?>
            <div class="item-actions">
                <?php if ($wa = wa_link($d['phone'] ?? '', reminder_text($d))): ?>
                    <a class="btn sm ghost" href="<?= e($wa) ?>" target="_blank" rel="noopener" title="WhatsApp'tan hatırlat"><?= icon('whatsapp') ?><span>Hatırlat</span></a>
                <?php endif; ?>
                <a class="btn sm" href="odeme-form.php?due_id=<?= (int) $d['id'] ?>"><?= icon('wallet') ?><span>Ödeme al</span></a>
            </div>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

const TASK_PRIORITY = ['low' => ['Düşük', 'neutral'], 'normal' => ['Normal', 'accent'], 'high' => ['Acil', 'critical']];
const TASK_STATUS = ['todo' => 'Yapılacak', 'doing' => 'Devam ediyor', 'done' => 'Tamamlandı'];

/** Görev kartı: soldaki onay kutusu görevi tamamlar / geri alır */
function task_item(array $t, bool $showCustomer = true): string
{
    $done = $t['status'] === 'done';
    $overdue = !$done && $t['due_date'] && $t['due_date'] < today();
    ob_start(); ?>
    <div class="item<?= $done ? ' is-done' : '' ?>">
        <form method="post" action="gorevler.php" class="item-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI']) ?>">
            <button class="icon-btn soft" type="submit" aria-label="<?= $done ? 'Geri al' : 'Tamamlandı olarak işaretle' ?>">
                <?= icon($done ? 'check' : 'circle', $done ? 'text-good' : 'dim') ?>
            </button>
        </form>
        <div class="item-main">
            <a class="item-title stretched" href="gorev-form.php?id=<?= (int) $t['id'] ?>"><?= e($t['title']) ?></a>
            <div class="item-sub">
                <?php if ($showCustomer && !empty($t['customer_name'])): ?><span><?= icon('briefcase') ?><?= e($t['customer_name']) ?></span><?php endif; ?>
                <?php if ($t['due_date']): ?><span class="<?= $overdue ? 'text-critical' : '' ?>"><?= icon('calendar') ?><?= date_tr($t['due_date']) ?></span><?php endif; ?>
                <?php if (!empty($t['assignee_name'])): ?><span><?= icon('user') ?><?= e($t['assignee_name']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="item-side">
            <?php if ($t['priority'] === 'high' && !$done): ?><?= badge('Acil', 'critical', 'alert') ?><?php endif; ?>
            <?php if ($t['status'] === 'doing'): ?><?= badge('Devam ediyor', 'accent') ?><?php endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

/** Görev sorgusu için ortak SELECT */
const TASKS_SELECT = "
    SELECT t.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS customer_name, u.name AS assignee_name
    FROM tasks t
    LEFT JOIN customers c ON c.id = t.customer_id
    LEFT JOIN users u ON u.id = t.assigned_to
";

/** Tahsilat satırı */
function payment_item(array $p, bool $showCustomer = true): string
{
    ob_start(); ?>
    <a class="item" href="odeme-form.php?id=<?= (int) $p['id'] ?>">
        <span class="avatar sm"><?= icon('wallet') ?></span>
        <div class="item-main">
            <span class="item-title"><?= e($showCustomer ? customer_display_name($p) : ($p['due_id'] ? order_name($p) : 'Genel ödeme')) ?></span>
            <div class="item-sub">
                <span><?= date_tr($p['paid_at']) ?></span>
                <?php if ($p['due_id'] && $p['billing_cycle']): ?><span><?= e(due_label($p)) ?></span><?php endif; ?>
                <span><?= e(PAYMENT_METHODS[$p['method']] ?? '') ?></span>
                <?php if ($showCustomer && $p['due_id']): ?><span><?= e(order_name($p)) ?></span><?php endif; ?>
                <?php if ($p['note']): ?><span class="dim"><?= e($p['note']) ?></span><?php endif; ?>
            </div>
        </div>
        <span class="item-amount text-good">+<?= money($p['amount']) ?></span>
    </a>
    <?php return ob_get_clean();
}

const PAYMENTS_SELECT = "
    SELECT p.*, c.company_name, c.contact_name, o.title AS order_title, o.billing_cycle, s.name AS service_name, d.period_no, d.due_date
    FROM payments p
    JOIN customers c ON c.id = p.customer_id
    LEFT JOIN dues d ON d.id = p.due_id
    LEFT JOIN orders o ON o.id = d.order_id
    LEFT JOIN services s ON s.id = o.service_id
";

function empty_state(string $text, ?string $href = null, string $label = ''): string
{
    $out = '<div class="empty"><p>' . e($text) . '</p>';
    if ($href) {
        $out .= '<a class="btn" href="' . e($href) . '">' . icon('plus') . e($label) . '</a>';
    }
    return $out . '</div>';
}
