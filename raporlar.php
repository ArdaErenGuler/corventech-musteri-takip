<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$currentYear = (int) date('Y');
$firstYear = (int) (q_val('SELECT YEAR(MIN(paid_at)) FROM payments') ?: $currentYear);
$year = input_int('yil') ?? $currentYear;
$year = max($firstYear, min($currentYear, $year));

// Aylık tahsilat
$monthly = array_fill(1, 12, 0.0);
foreach (q_all('SELECT MONTH(paid_at) m, SUM(amount) total FROM payments WHERE YEAR(paid_at) = ? GROUP BY m', [$year]) as $r) {
    $monthly[(int) $r['m']] = (float) $r['total'];
}
$yearTotal = array_sum($monthly);
$prevYearTotal = (float) q_val('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE YEAR(paid_at) = ?', [$year - 1]);
$max = max($monthly) ?: 1;
$maxMonth = array_search(max($monthly), $monthly, true);
$lastMonth = $year === $currentYear ? (int) date('n') : 12;

// Hizmet bazında tahsilat
$byService = q_all(
    "SELECT COALESCE(s.name, NULLIF(o.title, ''), 'Genel ödeme') AS name, SUM(p.amount) AS total
     FROM payments p
     LEFT JOIN dues d ON d.id = p.due_id
     LEFT JOIN orders o ON o.id = d.order_id
     LEFT JOIN services s ON s.id = o.service_id
     WHERE YEAR(p.paid_at) = ?
     GROUP BY name ORDER BY total DESC",
    [$year]
);
$serviceMax = $byService ? (float) $byService[0]['total'] : 1;

// Tahsilat oranı: yılın vadesi gelmiş dönemlerinin ne kadarı ödendi
$rate = q_one(
    "SELECT COALESCE(SUM(d.amount), 0) AS due, COALESCE(SUM(LEAST(COALESCE(pp.paid, 0), d.amount)), 0) AS paid
     FROM dues d
     LEFT JOIN (SELECT due_id, SUM(amount) paid FROM payments WHERE due_id IS NOT NULL GROUP BY due_id) pp ON pp.due_id = d.id
     WHERE d.status = 'open' AND YEAR(d.due_date) = ? AND d.due_date <= CURDATE()",
    [$year]
);
$collectionRate = $rate['due'] > 0 ? $rate['paid'] / $rate['due'] * 100 : null;

$mrr = monthly_recurring_revenue();
$payingCustomers = (int) q_val("SELECT COUNT(DISTINCT customer_id) FROM orders WHERE status = 'active' AND billing_cycle <> 'one_time'");

// En çok borcu olanlar
$balances = array_filter(all_customer_balances(), fn($b) => $b > 0.009);
arsort($balances);
$debtors = [];
if ($balances) {
    $ids = array_slice(array_keys($balances), 0, 5);
    $names = [];
    foreach (q_all('SELECT id, company_name, contact_name, phone FROM customers WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $c) {
        $names[(int) $c['id']] = $c;
    }
    foreach ($ids as $cid) {
        $debtors[] = $names[$cid] + ['balance' => $balances[$cid]];
    }
}

// Aktif müşterilerin sektörleri
$sectors = q_all("SELECT COALESCE(NULLIF(sector, ''), 'Belirtilmemiş') AS name, COUNT(*) AS n FROM customers WHERE status = 'active' GROUP BY name ORDER BY n DESC LIMIT 8");
$sectorMax = $sectors ? (int) $sectors[0]['n'] : 1;

layout_start('Raporlar', 'reports', ['fab' => false]);
?>
<nav class="chips" aria-label="Yıl">
    <?php for ($y = $currentYear; $y >= $firstYear; $y--): ?>
        <a class="chip<?= $y === $year ? ' is-active' : '' ?>" href="raporlar.php?yil=<?= $y ?>"><?= $y ?></a>
    <?php endfor; ?>
</nav>

<section class="card">
    <div class="stat-label"><?= icon('wallet') ?><?= $year ?> toplam tahsilat</div>
    <div class="hero mt-0" style="margin:6px 0 2px"><?= money($yearTotal) ?></div>
    <p class="muted small">
        <?php if ($prevYearTotal > 0): $d = ($yearTotal - $prevYearTotal) / $prevYearTotal * 100; ?>
            <?= $year - 1 ?> yılına göre <?= $d >= 0 ? '+' : '−' ?>%<?= number_format(abs($d), 0, ',', '.') ?>
        <?php else: ?>
            Aylara göre tahsil edilen ödemeler
        <?php endif; ?>
    </p>

    <div class="chart mt" role="img" aria-label="<?= $year ?> aylık tahsilat grafiği">
        <div class="chart-cols">
            <?php foreach ($monthly as $m => $v):
                $h = $v > 0 ? max(2, $v / $max * 100) : 0;
                $future = $m > $lastMonth; ?>
                <div class="chart-col" tabindex="0" data-tip="<?= e('<small>' . TR_MONTHS[$m] . ' ' . $year . '</small>' . ($future ? 'Henüz gelmedi' : money($v))) ?>">
                    <?php if ($m === $maxMonth && $v > 0): ?><span class="val" style="bottom:<?= $h ?>%"><?= e(money_short($v)) ?></span><?php endif; ?>
                    <span class="bar" style="height:<?= $h ?>%"></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="chart-labels" aria-hidden="true">
            <?php foreach (TR_MONTHS_SHORT as $i => $label): if ($i === 0) continue; ?>
                <span class="<?= $i > $lastMonth ? 'dim' : '' ?>"><?= e($label) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <details class="table-toggle">
        <summary>Tablo olarak göster</summary>
        <div class="table-wrap">
            <table class="data mt">
                <thead><tr><th>Ay</th><th class="num">Tahsilat</th></tr></thead>
                <tbody>
                    <?php foreach ($monthly as $m => $v): ?>
                        <tr><td><?= TR_MONTHS[$m] ?></td><td class="num"><?= money($v) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</section>

<div class="stats mt">
    <div class="stat">
        <div class="stat-label"><?= icon('trend') ?>Aylık düzenli gelir</div>
        <div class="stat-value"><?= money_short($mrr) ?></div>
        <div class="stat-sub">Yıllık karşılığı <?= money_short($mrr * 12) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('users') ?>Müşteri başı aylık</div>
        <div class="stat-value"><?= money_short($payingCustomers ? $mrr / $payingCustomers : 0) ?></div>
        <div class="stat-sub"><?= $payingCustomers ?> düzenli ödeyen müşteri</div>
    </div>
    <div class="stat<?= $collectionRate !== null && $collectionRate < 80 ? ' is-warning' : ' is-good' ?>">
        <div class="stat-label"><?= icon('check') ?>Tahsilat oranı</div>
        <div class="stat-value"><?= $collectionRate === null ? '—' : '%' . number_format($collectionRate, 0, ',', '.') ?></div>
        <?php if ($collectionRate !== null): ?>
            <div class="meter mt" style="margin-top:8px" aria-hidden="true"><span style="width:<?= min(100, $collectionRate) ?>%"></span></div>
        <?php endif; ?>
        <div class="stat-sub"><?= money_short($rate['paid']) ?> / <?= money_short($rate['due']) ?></div>
    </div>
    <div class="stat<?= $balances ? ' is-critical' : '' ?>">
        <div class="stat-label"><?= icon('alert') ?>Toplam alacak</div>
        <div class="stat-value"><?= money_short(array_sum($balances)) ?></div>
        <div class="stat-sub"><?= count($balances) ?> müşteride borç var</div>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Hizmet bazında tahsilat · <?= $year ?></h2></div>
        <?php if ($byService): ?>
            <div class="hbars">
                <?php foreach ($byService as $s): ?>
                    <div class="hbar-row">
                        <div class="hbar-top"><span><?= e($s['name']) ?></span><b class="num"><?= money($s['total']) ?></b></div>
                        <div class="hbar-track"><div class="hbar-fill" style="width:<?= max(1, $s['total'] / $serviceMax * 100) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?= empty_state('Bu yıl ödeme yok.') ?>
        <?php endif; ?>
    </section>

    <div>
        <section class="card">
            <div class="card-head"><h2>En çok borcu olanlar</h2><a href="musteriler.php?borc=1">Tümü</a></div>
            <?php if ($debtors): ?>
                <div class="list flush">
                    <?php foreach ($debtors as $c): ?>
                        <a class="item flush" href="musteri.php?id=<?= (int) $c['id'] ?>#odemeler">
                            <span class="avatar sm"><?= e(initials(customer_display_name($c))) ?></span>
                            <div class="item-main"><span class="item-title"><?= e(customer_display_name($c)) ?></span></div>
                            <span class="item-amount text-critical"><?= money($c['balance']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?= empty_state('Borcu olan müşteri yok.') ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head"><h2>Aktif müşteriler · sektör</h2></div>
            <?php if ($sectors): ?>
                <div class="hbars">
                    <?php foreach ($sectors as $s): ?>
                        <div class="hbar-row">
                            <div class="hbar-top"><span><?= e($s['name']) ?></span><b class="num"><?= (int) $s['n'] ?></b></div>
                            <div class="hbar-track"><div class="hbar-fill" style="width:<?= $s['n'] / $sectorMax * 100 ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?= empty_state('Aktif müşteri yok.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php layout_end();
