<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$currentYear = (int) date('Y');
$firstYear = (int) (q_val('SELECT YEAR(MIN(paid_at)) FROM payments') ?: $currentYear);
$year = max($firstYear, min($currentYear, input_int('yil') ?? $currentYear));
$lastMonth = $year === $currentYear ? (int) date('n') : 12;
// Kasa bölümü için seçili ay (0 = tüm yıl); grafikteki aya basınca değişir
$sel = isset($_GET['ay']) ? max(0, min($lastMonth, (int) $_GET['ay'])) : ($year === $currentYear ? $lastMonth : 0);

// Aylık tahsilat ve kasalar
$monthly = [];
for ($m = 1; $m <= 12; $m++) {
    $start = sprintf('%04d-%02d-01', $year, $m);
    $monthly[$m] = $m <= $lastMonth ? wallet_breakdown($start, date('Y-m-t', strtotime($start))) : null;
}
$values = array_map(fn($b) => $b ? $b['total'] : 0.0, $monthly);
$yearTotal = array_sum($values);
$prevYearTotal = (float) q_val('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE YEAR(paid_at) = ?', [$year - 1]);
$max = max($values) ?: 1;
$maxMonth = array_search(max($values), $values, true);

$period = $sel ? wallet_breakdown(sprintf('%04d-%02d-01', $year, $sel), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $sel))))
               : wallet_breakdown("$year-01-01", "$year-12-31");
$periodLabel = $sel ? TR_MONTHS[$sel] . ' ' . $year : $year . ' (tüm yıl)';

// Hizmet bazında tahsilat
$byService = q_all(
    "SELECT COALESCE(s.name, NULLIF(o.title, ''), 'Genel ödeme') AS name, SUM(p.amount) AS total
     FROM payments p LEFT JOIN dues d ON d.id = p.due_id LEFT JOIN orders o ON o.id = d.order_id LEFT JOIN services s ON s.id = o.service_id
     WHERE YEAR(p.paid_at) = ? GROUP BY name ORDER BY total DESC",
    [$year]
);
$serviceMax = $byService ? (float) $byService[0]['total'] : 1;

// Tahsilat oranı: yılın vadesi gelmiş dönemlerinin ne kadarı ödendi
$rate = q_one(
    "SELECT COALESCE(SUM(d.amount), 0) AS due, COALESCE(SUM(LEAST(COALESCE(pp.paid, 0), d.amount)), 0) AS paid
     FROM dues d LEFT JOIN (SELECT due_id, SUM(amount) paid FROM payments WHERE due_id IS NOT NULL GROUP BY due_id) pp ON pp.due_id = d.id
     WHERE d.status = 'open' AND YEAR(d.due_date) = ? AND d.due_date <= CURDATE()",
    [$year]
);
$collectionRate = $rate['due'] > 0 ? $rate['paid'] / $rate['due'] * 100 : null;
$mrr = monthly_recurring_revenue();
$payingCustomers = (int) q_val("SELECT COUNT(DISTINCT customer_id) FROM orders WHERE status = 'active' AND billing_cycle <> 'one_time'");

// Bekleyen alacaklar, müşteri sahibine göre
$balances = array_filter(all_customer_balances(), fn($b) => $b > 0.009);
$debtByOwner = ['arda' => 0.0, 'melih' => 0.0, 'ortak' => 0.0];
$debtors = [];
if ($balances) {
    foreach (q_all('SELECT id, company_name, contact_name, owner FROM customers WHERE id IN (' . implode(',', array_map('intval', array_keys($balances))) . ')') as $c) {
        $debtByOwner[$c['owner']] += $balances[(int) $c['id']];
        $debtors[] = $c + ['balance' => $balances[(int) $c['id']]];
    }
    usort($debtors, fn($a, $b) => $b['balance'] <=> $a['balance']);
}

layout_start('Raporlar', 'reports', ['fab' => false]);
?>
<nav class="chips" aria-label="Yıl">
    <?php for ($y = $currentYear; $y >= $firstYear; $y--): ?>
        <a class="chip<?= $y === $year ? ' is-active' : '' ?>" href="raporlar.php?yil=<?= $y ?>"><?= $y ?></a>
    <?php endfor; ?>
</nav>

<section class="card">
    <div class="stat-label"><?= icon('wallet') ?><?= $year ?> toplam tahsilat</div>
    <div class="hero" style="margin:6px 0 2px"><?= money($yearTotal) ?></div>
    <p class="muted small">
        <?php if ($prevYearTotal > 0): $d = ($yearTotal - $prevYearTotal) / $prevYearTotal * 100; ?>
            <?= $year - 1 ?> yılına göre <?= $d >= 0 ? '+' : '−' ?>%<?= number_format(abs($d), 0, ',', '.') ?> ·
        <?php endif; ?>
        Kasaları görmek için bir aya dokunun
    </p>

    <div class="chart mt" role="img" aria-label="<?= $year ?> aylık tahsilat grafiği">
        <div class="chart-cols">
            <?php foreach ($values as $m => $v):
                $h = $v > 0 ? max(2, $v / $max * 100) : 0;
                $future = $m > $lastMonth;
                $cls = 'chart-col' . ($sel && $m !== $sel ? ' is-dim' : '') . ($m === $sel ? ' is-selected' : ''); ?>
                <a class="<?= $cls ?>" href="<?= $future ? '#' : 'raporlar.php?yil=' . $year . '&ay=' . $m . '#kasalar' ?>"
                   data-tip="<?= e('<small>' . TR_MONTHS[$m] . ' ' . $year . '</small>' . ($future ? 'Henüz gelmedi' : money($v))) ?>">
                    <?php if ($m === $maxMonth && $v > 0): ?><span class="val" style="bottom:<?= $h ?>%"><?= e(money_short($v)) ?></span><?php endif; ?>
                    <span class="bar" style="height:<?= $h ?>%"></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="chart-labels" aria-hidden="true">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <span class="<?= $m > $lastMonth ? 'dim' : '' ?><?= $m === $sel ? ' is-selected' : '' ?>"><?= TR_MONTHS_SHORT[$m] ?></span>
            <?php endfor; ?>
        </div>
    </div>
    <details class="table-toggle">
        <summary>Aylık dökümü tablo olarak göster</summary>
        <div class="table-wrap">
            <table class="data mt">
                <thead><tr><th>Ay</th><th class="num col-total">Tahsilat</th><th class="num">Arda</th><th class="num">Melih</th><th class="num">Şirket</th></tr></thead>
                <tbody>
                    <?php foreach ($monthly as $m => $b): if (!$b) continue; ?>
                        <tr<?= $m === $sel ? ' class="is-current"' : '' ?>>
                            <td><a href="raporlar.php?yil=<?= $year ?>&ay=<?= $m ?>#kasalar"><?= TR_MONTHS[$m] ?></a></td>
                            <td class="num col-total"><?= money($b['total']) ?></td>
                            <?php foreach (WALLETS as $w => $label): ?>
                                <td class="num<?= $b['wallets'][$w]['net'] < 0 ? ' text-critical' : '' ?>"><?= money($b['wallets'][$w]['net']) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small">Kasa sütunları net tutardır (işlerden gelen pay + diğer gelir − gider).</p>
    </details>
</section>

<div class="section-head" id="kasalar">
    <h2>Kasalar · <?= e($periodLabel) ?></h2>
    <?php if ($sel): ?><a class="small" href="raporlar.php?yil=<?= $year ?>&ay=0#kasalar">Tüm yıl</a>
    <?php else: ?><a class="small" href="raporlar.php?yil=<?= $year ?>#kasalar">Bu ay</a><?php endif; ?>
</div>
<div class="stats wallets">
    <?php foreach (WALLETS as $w => $label): $b = $period['wallets'][$w]; ?>
        <div class="stat<?= $b['net'] < 0 ? ' is-critical' : '' ?>">
            <div class="stat-label"><?= icon($w === 'company' ? 'briefcase' : 'user') ?><?= e($label) ?> kasası</div>
            <div class="stat-value"><?= money($b['net']) ?></div>
            <div class="stat-sub">İşlerden <?= money($b['jobs']) ?></div>
            <?php if ($b['income'] > 0): ?><div class="stat-sub text-good">Diğer gelir +<?= money($b['income']) ?></div><?php endif; ?>
            <?php if ($b['expense'] > 0): ?><div class="stat-sub text-critical">Gider −<?= money($b['expense']) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<p class="muted small" style="margin-top:-6px">Her işin parası, iş eklenirken seçilen orana göre kasalara yazılır. Gelir ve giderler <a href="gelir-gider.php">Gelir / Gider</a> sayfasından girilir.</p>

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
            <div class="meter" style="margin-top:8px" aria-hidden="true"><span style="width:<?= min(100, $collectionRate) ?>%"></span></div>
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

    <section class="card">
        <div class="card-head"><h2>Bekleyen alacaklar</h2><a href="musteriler.php?borc=1">Tümü</a></div>
        <dl class="kv ledger">
            <?php foreach (OWNER_LABELS as $k => $label): ?>
                <dt><?= e($label) ?> müşterileri</dt><dd class="<?= $debtByOwner[$k] > 0 ? 'text-critical' : '' ?>"><?= money($debtByOwner[$k]) ?></dd>
            <?php endforeach; ?>
        </dl>
        <?php if ($debtors): ?>
            <div class="list flush mt">
                <?php foreach (array_slice($debtors, 0, 5) as $c): ?>
                    <a class="item flush" href="musteri.php?id=<?= (int) $c['id'] ?>#odemeler">
                        <div class="item-main">
                            <span class="item-title"><?= e(customer_display_name($c)) ?></span>
                            <div class="item-sub"><?= owner_badge($c['owner']) ?></div>
                        </div>
                        <span class="item-amount text-critical"><?= money($c['balance']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted small mt">Borcu olan müşteri yok.</p>
        <?php endif; ?>
    </section>
</div>
<?php layout_end();
