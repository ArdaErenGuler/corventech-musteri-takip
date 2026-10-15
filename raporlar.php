<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$month = DateTimeImmutable::createFromFormat('!Y-m', input('ay')) ?: new DateTimeImmutable(date('Y-m-01'));
$year = (int) $month->format('Y');
$m = share_breakdown($month->format('Y-m-01'), $month->format('Y-m-t'));
$share = company_share_label();
[$num, $den] = array_map('intval', explode('/', $share));
$personal = ($den - $num) . '/' . $den;

// Yıl içi aylık tablo
$rows = [];
$lastMonth = $year === (int) date('Y') ? (int) date('n') : 12;
for ($i = 1; $i <= $lastMonth; $i++) {
    $start = sprintf('%04d-%02d-01', $year, $i);
    $rows[$i] = share_breakdown($start, date('Y-m-t', strtotime($start)));
}
$yearTotal = share_breakdown("$year-01-01", "$year-12-31");

// Kişi bazında alacaklar (vadesi gelmiş, ödenmemiş)
$balances = array_filter(all_customer_balances(), fn($b) => $b > 0.009);
$owners = q_all('SELECT id, company_name, contact_name, owner FROM customers' . ($balances ? ' WHERE id IN (' . implode(',', array_map('intval', array_keys($balances))) . ')' : ' WHERE 0'));
$debtByOwner = ['arda' => 0.0, 'melih' => 0.0, 'ortak' => 0.0];
$debtors = [];
foreach ($owners as $c) {
    $debtByOwner[$c['owner']] += $balances[(int) $c['id']];
    $debtors[] = $c + ['balance' => $balances[(int) $c['id']]];
}
usort($debtors, fn($a, $b) => $b['balance'] <=> $a['balance']);

$monthLabel = TR_MONTHS[(int) $month->format('n')] . ' ' . $year;

layout_start('Raporlar', 'reports', ['fab' => false]);
?>
<div class="cal-toolbar">
    <div class="cal-nav">
        <a class="icon-btn soft" href="raporlar.php?ay=<?= $month->modify('-1 month')->format('Y-m') ?>" aria-label="Önceki ay"><?= icon('chevron-left') ?></a>
        <h2 class="cal-title"><?= TR_MONTHS[(int) $month->format('n')] ?> <span><?= $year ?></span></h2>
        <a class="icon-btn soft" href="raporlar.php?ay=<?= $month->modify('+1 month')->format('Y-m') ?>" aria-label="Sonraki ay"><?= icon('chevron-right') ?></a>
        <a class="btn sm ghost" href="raporlar.php">Bu ay</a>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-label"><?= icon('user') ?>Arda'nın payı</div>
        <div class="stat-value"><?= money($m['arda']) ?></div>
        <div class="stat-sub">Müşterilerinden <?= money($m['by_owner']['arda']) ?> · <?= $personal ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('user') ?>Melih'in payı</div>
        <div class="stat-value"><?= money($m['melih']) ?></div>
        <div class="stat-sub">Müşterilerinden <?= money($m['by_owner']['melih']) ?> · <?= $personal ?></div>
    </div>
    <div class="stat<?= $m['net'] < 0 ? ' is-critical' : ' is-good' ?>">
        <div class="stat-label"><?= icon('briefcase') ?>Şirket kasası (net)</div>
        <div class="stat-value"><?= money($m['net']) ?></div>
        <div class="stat-sub">Giderler düşülmüş</div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('wallet') ?>Toplam tahsilat</div>
        <div class="stat-value"><?= money($m['total']) ?></div>
        <div class="stat-sub"><?= e($monthLabel) ?></div>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head"><h2>Şirket kasası · <?= e($monthLabel) ?></h2><a href="gelir-gider.php?ay=<?= $month->format('Y-m') ?>">Gelir / Gider</a></div>
        <dl class="kv ledger">
            <dt>Arda'nın müşterilerinden (<?= $share ?>)</dt><dd><?= money($m['company_by_owner']['arda']) ?></dd>
            <dt>Melih'in müşterilerinden (<?= $share ?>)</dt><dd><?= money($m['company_by_owner']['melih']) ?></dd>
            <dt>Ortak müşterilerden (tamamı)</dt><dd><?= money($m['company_by_owner']['ortak']) ?></dd>
            <dt>Diğer gelirler</dt><dd class="text-good">+<?= money($m['other_income']) ?></dd>
            <dt>Giderler</dt><dd class="text-critical">−<?= money($m['expense']) ?></dd>
            <dt class="ledger-total">Net</dt><dd class="ledger-total<?= $m['net'] < 0 ? ' text-critical' : '' ?>"><?= money($m['net']) ?></dd>
        </dl>
        <p class="muted small mt">Şirket payı oranı Ayarlar → Uygulama'dan değiştirilebilir.</p>
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
        <?php endif; ?>
    </section>
</div>

<section class="card mt">
    <div class="card-head"><h2><?= $year ?> aylık döküm</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr><th>Ay</th><th class="num col-total">Tahsilat</th><th class="num">Arda</th><th class="num">Melih</th><th class="num">Şirket (net)</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($rows, true) as $i => $r): ?>
                    <tr<?= $i === (int) $month->format('n') ? ' class="is-current"' : '' ?>>
                        <td><a href="raporlar.php?ay=<?= sprintf('%04d-%02d', $year, $i) ?>"><?= TR_MONTHS[$i] ?></a></td>
                        <td class="num col-total"><?= money($r['total']) ?></td>
                        <td class="num"><?= money($r['arda']) ?></td>
                        <td class="num"><?= money($r['melih']) ?></td>
                        <td class="num<?= $r['net'] < 0 ? ' text-critical' : '' ?>"><?= money($r['net']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Toplam</th>
                    <th class="num col-total"><?= money($yearTotal['total']) ?></th>
                    <th class="num"><?= money($yearTotal['arda']) ?></th>
                    <th class="num"><?= money($yearTotal['melih']) ?></th>
                    <th class="num"><?= money($yearTotal['net']) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
<?php layout_end();
