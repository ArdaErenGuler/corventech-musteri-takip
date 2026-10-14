<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$window = max(1, (int) (setting('upcoming_days') ?: 30));

$activeCustomers = (int) q_val("SELECT COUNT(*) FROM customers WHERE status = 'active'");
$potentialCustomers = (int) q_val("SELECT COUNT(*) FROM customers WHERE status = 'potential'");
$monthIncome = (float) q_val("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND paid_at <= CURDATE()");
$lastMonthIncome = (float) q_val("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paid_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND paid_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$overdue = q_one('SELECT COUNT(*) AS n, COALESCE(SUM(remaining), 0) AS total FROM (' . DUES_SELECT . ' WHERE ' . DUES_UNPAID . ' AND d.due_date < CURDATE()) x');
$upcoming = q_one('SELECT COUNT(*) AS n, COALESCE(SUM(remaining), 0) AS total FROM (' . DUES_SELECT . ' WHERE ' . DUES_UNPAID . ' AND d.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY) x', [$window]);
$mrr = monthly_recurring_revenue();

$dues = q_all(DUES_SELECT . ' WHERE ' . DUES_UNPAID . ' AND d.due_date <= CURDATE() + INTERVAL ? DAY ORDER BY d.due_date LIMIT 6', [$window]);
$tasks = q_all(TASKS_SELECT . " WHERE t.status <> 'done' ORDER BY t.due_date IS NULL, t.due_date, FIELD(t.priority, 'high', 'normal', 'low') LIMIT 5");
$payments = q_all(PAYMENTS_SELECT . ' ORDER BY p.paid_at DESC, p.id DESC LIMIT 5');
$postsToday = due_posts();
$postsUpcoming = q_all(POSTS_SELECT . " WHERE p.status <> 'published' AND p.scheduled_date BETWEEN CURDATE() + INTERVAL 1 DAY AND CURDATE() + INTERVAL 3 DAY ORDER BY p.scheduled_date, p.scheduled_time IS NULL, p.scheduled_time LIMIT 4");
$newCustomers = q_all('SELECT * FROM customers ORDER BY registered_at DESC, id DESC LIMIT 5');

$firstName = explode(' ', $user['name'])[0];
$diff = $lastMonthIncome > 0 ? ($monthIncome - $lastMonthIncome) / $lastMonthIncome * 100 : null;

layout_start('Dashboard', 'dashboard');
?>
<div class="page-head">
    <div>
        <h2>Merhaba, <?= e($firstName) ?></h2>
        <p class="muted small"><?= (int) date('j') . ' ' . TR_MONTHS[(int) date('n')] . ' ' . date('Y') ?></p>
    </div>
</div>

<div class="stats">
    <a class="stat" href="musteriler.php?status=active">
        <div class="stat-label"><?= icon('users') ?>Aktif müşteri</div>
        <div class="stat-value"><?= $activeCustomers ?></div>
        <div class="stat-sub"><?= $potentialCustomers ?> potansiyel</div>
    </a>
    <a class="stat is-good" href="odemeler.php">
        <div class="stat-label"><?= icon('wallet') ?>Bu ay tahsilat</div>
        <div class="stat-value"><?= money_short($monthIncome) ?></div>
        <div class="stat-sub">
            <?php if ($diff !== null): ?>
                Geçen aya göre <?= $diff >= 0 ? '+' : '−' ?>%<?= number_format(abs($diff), 0, ',', '.') ?>
            <?php else: ?>
                Aylık düzenli gelir <?= money_short($mrr) ?>
            <?php endif; ?>
        </div>
    </a>
    <a class="stat<?= $overdue['n'] > 0 ? ' is-critical' : '' ?>" href="yenilemeler.php?f=overdue">
        <div class="stat-label"><?= icon('alert') ?>Gecikmiş ödeme</div>
        <div class="stat-value"><?= money_short($overdue['total']) ?></div>
        <div class="stat-sub"><?= (int) $overdue['n'] ?> ödeme gecikti</div>
    </a>
    <a class="stat is-warning" href="yenilemeler.php?f=30">
        <div class="stat-label"><?= icon('clock') ?><?= $window ?> gün içinde</div>
        <div class="stat-value"><?= money_short($upcoming['total']) ?></div>
        <div class="stat-sub"><?= (int) $upcoming['n'] ?> ödeme bekleniyor</div>
    </a>
</div>

<section class="card posts-today mb">
    <div class="card-head">
        <h2><?= icon('calendar') ?> Bugün paylaşılacaklar</h2>
        <a href="takvim.php">Takvim</a>
    </div>
    <?php if ($postsToday): ?>
        <div class="list"><?php foreach ($postsToday as $p) echo post_item($p, $p['scheduled_date'] !== today()); ?></div>
    <?php else: ?>
        <p class="muted small mt-0">Bugün için bekleyen paylaşım yok.</p>
    <?php endif; ?>
    <?php if ($postsUpcoming): ?>
        <div class="section-head" style="margin-top:16px"><h3>Önümüzdeki 3 gün</h3></div>
        <div class="list"><?php foreach ($postsUpcoming as $p) echo post_item($p, true); ?></div>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Ödemesi yaklaşanlar</h2>
            <a href="yenilemeler.php">Tümü</a>
        </div>
        <?php if ($dues): ?>
            <div class="list">
                <?php foreach ($dues as $d) echo due_item($d); ?>
            </div>
        <?php else: ?>
            <?= empty_state("Önümüzdeki $window günde bekleyen ödeme yok.") ?>
        <?php endif; ?>
    </section>

    <div>
        <section class="card">
            <div class="card-head">
                <h2>Açık görevler</h2>
                <a href="gorev-form.php">+ Görev</a>
            </div>
            <?php if ($tasks): ?>
                <div class="list"><?php foreach ($tasks as $t) echo task_item($t); ?></div>
            <?php else: ?>
                <?= empty_state('Açık görev yok.') ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head">
                <h2>Son ödemeler</h2>
                <a href="odemeler.php">Tümü</a>
            </div>
            <?php if ($payments): ?>
                <div class="list"><?php foreach ($payments as $p) echo payment_item($p); ?></div>
            <?php else: ?>
                <?= empty_state('Henüz ödeme kaydı yok.') ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head">
                <h2>Son eklenen müşteriler</h2>
                <a href="musteriler.php">Tümü</a>
            </div>
            <div class="list flush">
                <?php foreach ($newCustomers as $c): ?>
                    <a class="item flush" href="musteri.php?id=<?= (int) $c['id'] ?>">
                        <span class="avatar sm"><?= e(initials(customer_display_name($c))) ?></span>
                        <div class="item-main">
                            <span class="item-title"><?= e(customer_display_name($c)) ?></span>
                            <div class="item-sub"><span><?= e($c['sector'] ?: '—') ?></span><span><?= date_tr($c['registered_at']) ?></span></div>
                        </div>
                        <?= customer_status_badge($c['status']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<?php layout_end();
