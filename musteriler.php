<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$perPage = 30;
$page = max(1, input_int('page') ?? 1);
$search = input('q');
$status = input('status');
$sector = input('sector');
$debtOnly = input('borc') === '1';

$where = [];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $digits = phone_digits($search);
    $where[] = '(c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.city LIKE ? OR c.sector LIKE ?'
        . ($digits !== '' ? " OR REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '(', '') LIKE ?" : '') . ')';
    array_push($params, $like, $like, $like, $like, $like);
    if ($digits !== '') {
        $params[] = '%' . ltrim($digits, '0') . '%';
    }
}
if (isset(CUSTOMER_STATUS_LABELS[$status])) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}
if ($sector !== '') {
    $where[] = 'c.sector = ?';
    $params[] = $sector;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$balances = all_customer_balances();
if ($debtOnly) {
    $debtors = array_keys(array_filter($balances, fn($b) => $b > 0.009));
    $whereSql .= ($whereSql ? ' AND ' : 'WHERE ') . ($debtors ? 'c.id IN (' . implode(',', array_map('intval', $debtors)) . ')' : '0');
}

$total = (int) q_val("SELECT COUNT(*) FROM customers c $whereSql", $params);
$customers = q_all(
    "SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status = 'active') AS active_orders
     FROM customers c $whereSql
     ORDER BY FIELD(c.status, 'active', 'potential', 'passive'), COALESCE(NULLIF(c.company_name, ''), c.contact_name)
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

$counts = ['' => (int) q_val('SELECT COUNT(*) FROM customers')];
foreach (q_all('SELECT status, COUNT(*) n FROM customers GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
$sectors = q("SELECT DISTINCT sector FROM customers WHERE sector IS NOT NULL AND sector <> '' ORDER BY sector")->fetchAll(PDO::FETCH_COLUMN);

layout_start('Müşteriler', 'customers');
?>
<form class="toolbar" method="get" role="search">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="search">
        <?= icon('search') ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="İsim, firma, telefon, şehir ara…" aria-label="Müşteri ara" enterkeyhint="search">
    </div>
    <?php if ($sectors): ?>
        <select name="sector" data-autosubmit aria-label="Sektöre göre filtrele" style="max-width:240px">
            <option value="">Tüm sektörler</option>
            <?php foreach ($sectors as $s): ?>
                <option value="<?= e($s) ?>"<?= $s === $sector ? ' selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
</form>

<nav class="chips" aria-label="Duruma göre filtrele">
    <a class="chip<?= $status === '' && !$debtOnly ? ' is-active' : '' ?>" href="<?= e(url_with(['status' => null, 'borc' => null, 'page' => null])) ?>">Tümü <b><?= $counts[''] ?></b></a>
    <?php foreach (CUSTOMER_STATUS_LABELS as $key => $label): ?>
        <a class="chip<?= $status === $key ? ' is-active' : '' ?>" href="<?= e(url_with(['status' => $key, 'borc' => null, 'page' => null])) ?>"><?= e($label) ?> <b><?= $counts[$key] ?? 0 ?></b></a>
    <?php endforeach; ?>
    <a class="chip<?= $debtOnly ? ' is-active' : '' ?>" href="<?= e(url_with(['borc' => $debtOnly ? null : '1', 'status' => null, 'page' => null])) ?>"><?= icon('alert') ?>Borçlular</a>
</nav>

<?php if (!$customers): ?>
    <?= $search !== '' || $status !== '' || $sector !== '' || $debtOnly
        ? empty_state('Aramanıza uyan müşteri bulunamadı.')
        : empty_state('Henüz müşteri eklenmedi.', 'musteri-form.php', 'İlk müşteriyi ekle') ?>
<?php else: ?>
    <p class="muted small"><?= $total ?> müşteri</p>
    <div class="list">
        <?php foreach ($customers as $c):
            $name = customer_display_name($c);
            $bal = $balances[(int) $c['id']] ?? 0; ?>
            <div class="item is-link">
                <span class="avatar"><?= e(initials($name)) ?></span>
                <div class="item-main">
                    <a class="item-title stretched" href="musteri.php?id=<?= (int) $c['id'] ?>"><?= e($name) ?></a>
                    <div class="item-sub">
                        <?php if ($c['company_name'] && $c['contact_name']): ?><span><?= icon('user') ?><?= e($c['contact_name']) ?></span><?php endif; ?>
                        <?php if ($c['sector']): ?><span><?= icon('briefcase') ?><?= e($c['sector']) ?></span><?php endif; ?>
                        <?php if ($c['city']): ?><span><?= icon('pin') ?><?= e($c['city']) ?></span><?php endif; ?>
                    </div>
                    <div class="item-sub">
                        <?= customer_status_badge($c['status']) ?>
                        <?php if ($bal > 0.009): ?><?= badge('Borç ' . money($bal), 'critical') ?><?php endif; ?>
                        <?php if ($c['active_orders'] > 0): ?><?= badge($c['active_orders'] . ' aktif hizmet', 'neutral') ?><?php endif; ?>
                    </div>
                </div>
                <div class="item-actions"><?= contact_buttons($c) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?= pagination($total, $perPage, $page) ?>
<?php endif; ?>
<?php layout_end();
