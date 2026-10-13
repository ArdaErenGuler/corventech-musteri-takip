<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$perPage = 30;
$page = max(1, input_int('page') ?? 1);
$status = input('status', 'active');
$cycle = input('cycle');
$search = input('q');

$where = [];
$params = [];
if (isset(ORDER_STATUS_LABELS[$status])) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if (isset(CYCLE_LABELS[$cycle])) {
    $where[] = 'o.billing_cycle = ?';
    $params[] = $cycle;
}
if ($search !== '') {
    $where[] = '(c.company_name LIKE ? OR c.contact_name LIKE ? OR s.name LIKE ? OR o.title LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from = 'FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN services s ON s.id = o.service_id';

$total = (int) q_val("SELECT COUNT(*) $from $whereSql", $params);
$orders = q_all(
    "SELECT o.*, c.company_name, c.contact_name, s.name AS service_name,
        (SELECT MIN(d.due_date) FROM dues d
          LEFT JOIN (SELECT due_id, SUM(amount) paid FROM payments GROUP BY due_id) pp ON pp.due_id = d.id
          WHERE d.order_id = o.id AND d.status = 'open' AND d.amount - COALESCE(pp.paid, 0) > 0.009) AS next_due
     $from $whereSql
     ORDER BY o.created_at DESC, o.id DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

$counts = ['all' => (int) q_val('SELECT COUNT(*) FROM orders')];
foreach (q_all('SELECT status, COUNT(*) n FROM orders GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$mrr = monthly_recurring_revenue();

layout_start('Siparişler', 'orders', ['fab' => ['href' => 'siparis-form.php', 'label' => 'Yeni sipariş']]);
?>
<div class="stats">
    <div class="stat">
        <div class="stat-label"><?= icon('bag') ?>Aktif sipariş</div>
        <div class="stat-value"><?= $counts['active'] ?? 0 ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('trend') ?>Aylık düzenli gelir</div>
        <div class="stat-value"><?= money_short($mrr) ?></div>
        <div class="stat-sub">Yıllık: <?= money_short($mrr * 12) ?></div>
    </div>
</div>

<form class="toolbar" method="get" role="search">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <div class="search">
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Müşteri veya hizmet ara…" aria-label="Sipariş ara" enterkeyhint="search">
    </div>
    <select name="cycle" data-autosubmit aria-label="Ödeme tipine göre filtrele" style="max-width:220px">
        <option value="">Tüm ödeme tipleri</option>
        <?php foreach (CYCLE_LABELS as $k => $label): ?>
            <option value="<?= $k ?>"<?= $k === $cycle ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<nav class="chips" aria-label="Duruma göre filtrele">
    <?php foreach (ORDER_STATUS_LABELS as $k => $label): ?>
        <a class="chip<?= $status === $k ? ' is-active' : '' ?>" href="<?= e(url_with(['status' => $k, 'page' => null])) ?>"><?= e($label) ?> <b><?= $counts[$k] ?? 0 ?></b></a>
    <?php endforeach; ?>
    <a class="chip<?= $status === 'all' ? ' is-active' : '' ?>" href="<?= e(url_with(['status' => 'all', 'page' => null])) ?>">Tümü <b><?= $counts['all'] ?></b></a>
</nav>

<?php if ($orders): ?>
    <div class="list">
        <?php foreach ($orders as $o): ?>
            <a class="item" href="siparis-form.php?id=<?= (int) $o['id'] ?>">
                <div class="item-main">
                    <span class="item-title"><?= e(customer_display_name($o)) ?></span>
                    <div class="item-sub"><span><?= e(order_name($o)) ?></span></div>
                    <div class="item-sub">
                        <span><?= e(CYCLE_LABELS[$o['billing_cycle']]) ?></span>
                        <span><?= icon('calendar') ?><?= date_tr($o['start_date']) ?></span>
                        <?php if ($o['next_due']): ?>
                            <span class="<?= $o['next_due'] < today() ? 'text-critical' : '' ?>">Sonraki ödeme <?= date_tr($o['next_due']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="item-side">
                    <span class="item-amount"><?= money($o['price']) ?><?= $o['billing_cycle'] === 'monthly' ? '<small>/ay</small>' : ($o['billing_cycle'] === 'yearly' ? '<small>/yıl</small>' : '') ?></span>
                    <?= order_status_badge($o['status']) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?= pagination($total, $perPage, $page) ?>
<?php else: ?>
    <?= empty_state('Bu filtrede sipariş yok.', 'siparis-form.php', 'Yeni sipariş') ?>
<?php endif; ?>
<?php layout_end();
