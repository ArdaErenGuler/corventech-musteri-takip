<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$periods = [
    'bu-ay'    => ['Bu ay', date('Y-m-01'), today()],
    'gecen-ay' => ['Geçen ay', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    '3-ay'     => ['Son 3 ay', date('Y-m-d', strtotime('-3 months')), today()],
    'yil'      => [date('Y') . ' yılı', date('Y-01-01'), date('Y-12-31')],
    'tumu'     => ['Tümü', null, null],
];
$period = isset($periods[input('p')]) ? input('p') : 'bu-ay';
[, $from, $to] = $periods[$period];
$method = input('method');
$search = input('q');
$perPage = 40;
$page = max(1, input_int('page') ?? 1);

$where = [];
$params = [];
if ($from) {
    $where[] = 'p.paid_at BETWEEN ? AND ?';
    array_push($params, $from, $to);
}
if (isset(PAYMENT_METHODS[$method])) {
    $where[] = 'p.method = ?';
    $params[] = $method;
}
if ($search !== '') {
    $where[] = '(c.company_name LIKE ? OR c.contact_name LIKE ? OR p.note LIKE ?)';
    array_push($params, ...array_fill(0, 3, '%' . $search . '%'));
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$summary = q_one("SELECT COUNT(*) n, COALESCE(SUM(p.amount), 0) total FROM payments p JOIN customers c ON c.id = p.customer_id $whereSql", $params);
$byMethod = q_all("SELECT p.method, SUM(p.amount) total FROM payments p JOIN customers c ON c.id = p.customer_id $whereSql GROUP BY p.method ORDER BY total DESC", $params);
$payments = q_all(PAYMENTS_SELECT . " $whereSql ORDER BY p.paid_at DESC, p.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);

layout_start('Ödemeler', 'payments', ['fab' => ['href' => 'odeme-form.php', 'label' => 'Ödeme al']]);
?>
<nav class="chips" aria-label="Dönem">
    <?php foreach ($periods as $k => [$label]): ?>
        <a class="chip<?= $period === $k ? ' is-active' : '' ?>" href="<?= e(url_with(['p' => $k, 'page' => null])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<div class="stats">
    <div class="stat is-good">
        <div class="stat-label"><?= icon('wallet') ?>Toplam tahsilat</div>
        <div class="stat-value"><?= money($summary['total']) ?></div>
        <div class="stat-sub"><?= (int) $summary['n'] ?> ödeme</div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('chart') ?>Ödeme yöntemi</div>
        <?php if ($byMethod): ?>
            <?php foreach (array_slice($byMethod, 0, 3) as $m): ?>
                <div class="row between small"><span class="muted"><?= e(PAYMENT_METHODS[$m['method']]) ?></span><b class="num"><?= money($m['total']) ?></b></div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="stat-sub">—</div>
        <?php endif; ?>
    </div>
</div>

<form class="toolbar" method="get" role="search">
    <input type="hidden" name="p" value="<?= e($period) ?>">
    <div class="search">
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Müşteri veya not ara…" aria-label="Ödeme ara" enterkeyhint="search">
    </div>
    <select name="method" data-autosubmit aria-label="Ödeme yöntemi" style="max-width:220px">
        <option value="">Tüm yöntemler</option>
        <?php foreach (PAYMENT_METHODS as $k => $label): ?>
            <option value="<?= $k ?>"<?= $k === $method ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($payments): ?>
    <div class="list"><?php foreach ($payments as $p) echo payment_item($p); ?></div>
    <?= pagination((int) $summary['n'], $perPage, $page) ?>
<?php else: ?>
    <?= empty_state('Bu dönemde ödeme kaydı yok.', 'odeme-form.php', 'Ödeme al') ?>
<?php endif; ?>
<?php layout_end();
