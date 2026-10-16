<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$window = max(1, (int) (setting('upcoming_days') ?: 30));
$filters = [
    'all'     => 'Tümü',
    'overdue' => 'Gecikmiş',
    '7'       => '7 gün',
    '30'      => $window . ' gün',
];
$f = isset($filters[input('f')]) ? input('f') : 'all';
$cycle = input('cycle');

$where = DUES_UNPAID;
$params = [];
switch ($f) {
    case 'overdue':
        $where .= ' AND d.due_date < CURDATE()';
        break;
    case '7':
        $where .= ' AND d.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY';
        break;
    case '30':
        $where .= ' AND d.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY';
        $params[] = $window;
        break;
    default:
        $where .= ' AND d.due_date <= CURDATE() + INTERVAL ? DAY';
        $params[] = $window;
}
if (isset(CYCLE_LABELS[$cycle])) {
    $where .= ' AND o.billing_cycle = ?';
    $params[] = $cycle;
}
$dues = q_all(DUES_SELECT . " WHERE $where ORDER BY d.due_date, c.company_name", $params);

// Gruplar
$groups = ['overdue' => [], 'today' => [], 'week' => [], 'later' => []];
foreach ($dues as $d) {
    $days = days_until($d['due_date']);
    $key = $days < 0 ? 'overdue' : ($days === 0 ? 'today' : ($days <= 7 ? 'week' : 'later'));
    $groups[$key][] = $d;
}
$groupTitles = ['overdue' => 'Gecikmiş', 'today' => 'Bugün', 'week' => 'Bu hafta', 'later' => 'Sonraki ' . $window . ' gün'];

$totals = q_one(
    'SELECT
        COALESCE(SUM(CASE WHEN x.due_date < CURDATE() THEN x.remaining END), 0) AS overdue,
        COUNT(CASE WHEN x.due_date < CURDATE() THEN 1 END) AS overdue_n,
        COALESCE(SUM(CASE WHEN x.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY THEN x.remaining END), 0) AS week,
        COUNT(CASE WHEN x.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY THEN 1 END) AS week_n,
        COALESCE(SUM(CASE WHEN x.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY THEN x.remaining END), 0) AS month,
        COUNT(CASE WHEN x.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY THEN 1 END) AS month_n
     FROM (' . DUES_SELECT . ' WHERE ' . DUES_UNPAID . ') x',
    [$window, $window]
);

// Yıllık yenileme takvimi: aktif yıllık siparişlerin bir sonraki yenileme tarihi (dönem henüz oluşmamış olsa da)
$yearly = [];
foreach (q_all("SELECT o.*, c.company_name, c.contact_name, c.phone, s.name AS service_name
                FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN services s ON s.id = o.service_id
                WHERE o.status = 'active' AND o.billing_cycle = 'yearly'") as $o) {
    for ($n = 1; $n < 200; $n++) {
        $date = period_due_date($o['first_due_date'], 'yearly', $n);
        if ($date >= today()) {
            break;
        }
    }
    if ($o['end_date'] && $date > $o['end_date']) {
        continue;
    }
    $o['next_renewal'] = $date;
    $yearly[] = $o;
}
usort($yearly, fn($a, $b) => strcmp($a['next_renewal'], $b['next_renewal']));

layout_start('Yenilemeler', 'renewals', ['fab' => ['href' => 'odeme-form.php', 'label' => 'Ödeme al']]);
?>
<div class="stats">
    <a class="stat<?= $totals['overdue_n'] ? ' is-critical' : '' ?>" href="<?= e(url_with(['f' => 'overdue'])) ?>">
        <div class="stat-label"><?= icon('alert') ?>Gecikmiş</div>
        <div class="stat-value"><?= money_short($totals['overdue']) ?></div>
        <div class="stat-sub"><?= (int) $totals['overdue_n'] ?> ödeme</div>
    </a>
    <a class="stat is-warning" href="<?= e(url_with(['f' => '7'])) ?>">
        <div class="stat-label"><?= icon('clock') ?>7 gün içinde</div>
        <div class="stat-value"><?= money_short($totals['week']) ?></div>
        <div class="stat-sub"><?= (int) $totals['week_n'] ?> ödeme</div>
    </a>
    <a class="stat" href="<?= e(url_with(['f' => '30'])) ?>">
        <div class="stat-label"><?= icon('calendar') ?><?= $window ?> gün içinde</div>
        <div class="stat-value"><?= money_short($totals['month']) ?></div>
        <div class="stat-sub"><?= (int) $totals['month_n'] ?> ödeme</div>
    </a>
    <a class="stat" href="#yillik">
        <div class="stat-label"><?= icon('refresh') ?>Yıllık yenileme</div>
        <div class="stat-value"><?= count($yearly) ?></div>
        <div class="stat-sub"><?= $yearly ? 'İlki ' . date_tr($yearly[0]['next_renewal']) : 'Yok' ?></div>
    </a>
</div>

<div class="toolbar">
    <nav class="chips" aria-label="Zaman aralığı">
        <?php foreach ($filters as $k => $label): ?>
            <a class="chip<?= $f === $k ? ' is-active' : '' ?>" href="<?= e(url_with(['f' => $k])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="get" style="margin-left:auto">
        <input type="hidden" name="f" value="<?= e($f) ?>">
        <select name="cycle" data-autosubmit aria-label="Ödeme tipi">
            <option value="">Aylık + yıllık + tek seferlik</option>
            <?php foreach (CYCLE_LABELS as $k => $label): ?>
                <option value="<?= $k ?>"<?= $k === $cycle ? ' selected' : '' ?>>Yalnızca <?= e(mb_strtolower($label)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$dues): ?>
    <?= empty_state('Bu aralıkta bekleyen ödeme yok.') ?>
<?php endif; ?>

<?php foreach ($groups as $key => $list):
    if (!$list) continue;
    $sum = array_sum(array_column($list, 'remaining')); ?>
    <div class="section-head">
        <h2 class="<?= $key === 'overdue' ? 'text-critical' : '' ?>"><?= e($groupTitles[$key]) ?> <span class="muted small">· <?= count($list) ?></span></h2>
        <b class="num"><?= money($sum) ?></b>
    </div>
    <div class="list"><?php foreach ($list as $d) echo due_item($d); ?></div>
<?php endforeach; ?>

<div class="section-head" id="yillik">
    <h2>Yıllık yenileme takvimi</h2>
</div>
<?php if ($yearly): ?>
    <div class="list">
        <?php foreach ($yearly as $o):
            $days = days_until($o['next_renewal']); ?>
            <a class="item" href="siparis-form.php?id=<?= (int) $o['id'] ?>#plan">
                <span class="avatar"><?= icon('refresh') ?></span>
                <div class="item-main">
                    <span class="item-title"><?= e(customer_display_name($o)) ?></span>
                    <div class="item-sub"><span><?= e(order_name($o)) ?></span></div>
                    <div class="item-sub"><span class="<?= $days <= 7 ? 'text-warning' : '' ?>"><?= icon('calendar') ?><?= date_tr($o['next_renewal']) ?> · <?= e(relative_due($o['next_renewal'])) ?></span></div>
                </div>
                <div class="item-side">
                    <span class="item-amount"><?= money($o['price']) ?><small>/yıl</small></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?= empty_state('Aktif yıllık hizmet yok.') ?>
<?php endif; ?>
<?php layout_end();
