<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'delete') {
        q('DELETE FROM transactions WHERE id = ?', [input_int('id') ?? 0]);
        flash('success', 'Kayıt silindi.');
        redirect(safe_return(input('return'), 'gelir-gider.php'));
    }
    if ($action === 'stop') {
        q('UPDATE recurring_transactions SET is_active = 0 WHERE id = ?', [input_int('id') ?? 0]);
        flash('success', 'Düzenli kayıt durduruldu; geçmiş kayıtlar duruyor.');
        redirect('gelir-gider.php#duzenli');
    }
    if ($action === 'resume') {
        $r = q_one('SELECT * FROM recurring_transactions WHERE id = ?', [input_int('id') ?? 0]);
        if ($r) {
            // Durdurulduğu aralıktaki dönemler yazılmaz: bugünden sonraki ilk dönemden devam eder
            $n = (int) $r['last_period'];
            while ($n < 1200 && recurring_next_date(['last_period' => $n] + $r) < today()) {
                $n++;
            }
            q('UPDATE recurring_transactions SET is_active = 1, last_period = ? WHERE id = ?', [$n, $r['id']]);
            sync_recurring_transactions(true);
        }
        flash('success', 'Düzenli kayıt yeniden başlatıldı.');
        redirect('gelir-gider.php#duzenli');
    }

    $type = input('type') === 'income' ? 'income' : 'expense';
    $category = input('category');
    $amount = input_money('amount');
    $date = input_date('txn_date');
    $cycle = input('cycle');
    $allowed = $type === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;

    if (!in_array($category, $allowed, true)) $errors['category'] = 'Kategori seçin.';
    if ($amount === null || $amount <= 0) $errors['amount'] = 'Tutarı yazın.';
    if (!$date) $errors['txn_date'] = 'Tarih seçin.';
    if (!isset(TXN_CYCLES[$cycle])) $cycle = 'once';

    if (!$errors) {
        $label = ($type === 'income' ? 'Gelir' : 'Gider');
        if ($cycle === 'once') {
            q('INSERT INTO transactions (type, category, amount, txn_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$type, $category, $amount, $date, input('description') ?: null, $user['id']]);
            flash('success', "$label eklendi: " . money($amount));
        } else {
            q('INSERT INTO recurring_transactions (type, category, amount, cycle, start_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$type, $category, $amount, $cycle, $date, input('description') ?: null, $user['id']]);
            sync_recurring_transactions(true);
            flash('success', "$label " . mb_strtolower(TXN_CYCLES[$cycle]) . ' tekrarlanacak: ' . money($amount));
        }
        redirect('gelir-gider.php?ay=' . substr($date, 0, 7));
    }
}

$month = DateTimeImmutable::createFromFormat('!Y-m', input('ay')) ?: new DateTimeImmutable(date('Y-m-01'));
$from = $month->format('Y-m-01');
$to = $month->format('Y-m-t');
$show = in_array(input('goster'), ['gelir', 'gider'], true) ? input('goster') : 'hepsi';

$sum = company_breakdown($from, $to);

// Bu ayın hareketleri: müşteri ödemeleri (gelir, kendiliğinden) + elle girilen gelir/gider, tarihe göre
$entries = [];
foreach (q_all(PAYMENTS_SELECT . ' WHERE p.paid_at BETWEEN ? AND ? ORDER BY p.paid_at DESC, p.id DESC', [$from, $to]) as $p) {
    $entries[] = ['kind' => 'payment', 'date' => $p['paid_at'], 'id' => (int) $p['id'], 'row' => $p];
}
foreach (q_all('SELECT t.*, r.cycle FROM transactions t LEFT JOIN recurring_transactions r ON r.id = t.recurring_id WHERE t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC, t.id DESC', [$from, $to]) as $t) {
    $entries[] = ['kind' => 'txn', 'date' => $t['txn_date'], 'id' => (int) $t['id'], 'row' => $t];
}
usort($entries, fn($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
$entries = array_values(array_filter($entries, function ($e) use ($show) {
    $isIncome = $e['kind'] === 'payment' || $e['row']['type'] === 'income';
    return $show === 'hepsi' || ($show === 'gelir' ? $isIncome : !$isIncome);
}));

$recurring = q_all('SELECT * FROM recurring_transactions ORDER BY is_active DESC, type, category');
$type = old('type', 'expense');
$ym = $month->format('Y-m');
$q = fn(array $x) => 'gelir-gider.php?' . http_build_query(array_filter(['ay' => $ym, 'goster' => $show === 'hepsi' ? null : $show] + $x));

layout_start('Gelir / Gider', 'finance', ['fab' => false]);
?>
<div class="cal-toolbar">
    <div class="cal-nav">
        <a class="icon-btn soft" href="gelir-gider.php?ay=<?= $month->modify('-1 month')->format('Y-m') ?>" aria-label="Önceki ay"><?= icon('chevron-left') ?></a>
        <h2 class="cal-title"><?= TR_MONTHS[(int) $month->format('n')] ?> <span><?= $month->format('Y') ?></span></h2>
        <a class="icon-btn soft" href="gelir-gider.php?ay=<?= $month->modify('+1 month')->format('Y-m') ?>" aria-label="Sonraki ay"><?= icon('chevron-right') ?></a>
        <a class="btn sm ghost" href="gelir-gider.php">Bu ay</a>
    </div>
</div>

<div class="stats">
    <a class="stat is-good" href="<?= e($q(['goster' => 'gelir'])) ?>">
        <div class="stat-label"><?= icon('trend') ?>Gelir</div>
        <div class="stat-value text-good"><?= money($sum['revenue']) ?></div>
        <div class="stat-sub">Müşteri ödemeleri <?= money($sum['collected']) ?><?= $sum['income'] > 0 ? ' · diğer ' . money($sum['income']) : '' ?></div>
    </a>
    <a class="stat" href="<?= e($q(['goster' => 'gider'])) ?>">
        <div class="stat-label"><?= icon('wallet') ?>Gider</div>
        <div class="stat-value text-critical"><?= money($sum['expense']) ?></div>
    </a>
    <div class="stat<?= $sum['net'] < 0 ? ' is-critical' : '' ?>">
        <div class="stat-label"><?= icon('briefcase') ?>Net</div>
        <div class="stat-value"><?= money($sum['net']) ?></div>
        <div class="stat-sub">Gelir − gider</div>
    </div>
</div>

<details class="card add-expense" <?= $errors ? 'open' : '' ?>>
    <summary><span class="btn"><?= icon('plus') ?>Gider ekle</span><span class="muted small">hosting, domain, Claude, reklam…</span></summary>
    <form method="post" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="segmented" role="radiogroup" aria-label="Kayıt türü">
            <label><input type="radio" name="type" value="expense" data-txn-type<?= $type === 'expense' ? ' checked' : '' ?>><span>Gider</span></label>
            <label><input type="radio" name="type" value="income" data-txn-type<?= $type === 'income' ? ' checked' : '' ?>><span>Diğer gelir</span></label>
        </div>
        <p class="muted small mt-0" style="margin:0">Müşteri ödemeleri gelire <b>kendiliğinden</b> yazılır; buraya yalnızca müşteri dışı bir gelir varsa girin.</p>
        <div class="form-grid">
            <div class="field<?= isset($errors['category']) ? ' has-error' : '' ?>">
                <label for="category">Kategori</label>
                <select id="category" name="category">
                    <optgroup label="Gider" data-for="expense"<?= $type === 'income' ? ' disabled hidden' : '' ?>>
                        <?php foreach (EXPENSE_CATEGORIES as $cat): ?><option<?= old('category') === $cat ? ' selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Diğer gelir" data-for="income"<?= $type === 'expense' ? ' disabled hidden' : '' ?>>
                        <?php foreach (INCOME_CATEGORIES as $cat): ?><option<?= old('category') === $cat ? ' selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?>
                    </optgroup>
                </select>
                <?= field_error($errors, 'category') ?>
            </div>
            <div class="field<?= isset($errors['amount']) ? ' has-error' : '' ?>">
                <label for="amount">Tutar</label>
                <div class="input-money"><input id="amount" name="amount" inputmode="decimal" value="<?= e(old('amount')) ?>" placeholder="Örn. 3.400"></div>
                <?= field_error($errors, 'amount') ?>
            </div>
            <fieldset class="field">
                <legend class="label">Tekrar</legend>
                <div class="segmented">
                    <?php foreach (TXN_CYCLES as $k => $label): ?>
                        <label><input type="radio" name="cycle" value="<?= $k ?>"<?= old('cycle', 'once') === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="field<?= isset($errors['txn_date']) ? ' has-error' : '' ?>">
                <label for="txn_date">Tarih <span class="dim">(tekrarlıysa ilk tarih)</span></label>
                <input id="txn_date" name="txn_date" type="date" value="<?= e(old('txn_date', today())) ?>">
                <?= field_error($errors, 'txn_date') ?>
            </div>
            <div class="field span-2">
                <label for="description">Açıklama <span class="dim">(opsiyonel)</span></label>
                <input id="description" name="description" value="<?= e(old('description')) ?>" maxlength="255" placeholder="Örn. Claude aboneliği">
            </div>
        </div>
        <div class="form-actions"><span class="spacer"></span><button class="btn" type="submit"><?= icon('check') ?>Kaydet</button></div>
    </form>
</details>

<nav class="chips" aria-label="Göster">
    <a class="chip<?= $show === 'hepsi' ? ' is-active' : '' ?>" href="<?= e('gelir-gider.php?ay=' . $ym) ?>">Hepsi</a>
    <a class="chip<?= $show === 'gelir' ? ' is-active' : '' ?>" href="<?= e('gelir-gider.php?ay=' . $ym . '&goster=gelir') ?>">Gelir</a>
    <a class="chip<?= $show === 'gider' ? ' is-active' : '' ?>" href="<?= e('gelir-gider.php?ay=' . $ym . '&goster=gider') ?>">Gider</a>
</nav>

<?php if ($entries): ?>
    <div class="list">
        <?php foreach ($entries as $e):
            $r = $e['row'];
            if ($e['kind'] === 'payment'): ?>
                <a class="item" href="odeme-form.php?id=<?= (int) $r['id'] ?>">
                    <span class="avatar sm"><?= icon('trend') ?></span>
                    <div class="item-main">
                        <span class="item-title"><?= e(customer_display_name($r)) ?></span>
                        <div class="item-sub">
                            <span><?= date_tr($r['paid_at']) ?></span>
                            <?php if ($r['due_id']): ?><span><?= e(order_name($r)) ?> · <?= e(due_label($r)) ?></span><?php else: ?><span>Genel ödeme</span><?php endif; ?>
                            <?= badge('Müşteri ödemesi', 'good') ?>
                        </div>
                    </div>
                    <span class="item-amount text-good">+<?= money($r['amount']) ?></span>
                </a>
            <?php else: $in = $r['type'] === 'income'; ?>
                <div class="item">
                    <span class="avatar sm"><?= icon($in ? 'trend' : 'wallet') ?></span>
                    <div class="item-main">
                        <span class="item-title"><?= e($r['category']) ?></span>
                        <div class="item-sub">
                            <span><?= date_tr($r['txn_date']) ?></span>
                            <?php if ($r['cycle']): ?><?= badge(TXN_CYCLES[$r['cycle']], 'accent', 'refresh') ?><?php endif; ?>
                            <?php if ($r['description']): ?><span><?= e($r['description']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <span class="item-amount <?= $in ? 'text-good' : 'text-critical' ?>"><?= $in ? '+' : '−' ?><?= money($r['amount']) ?></span>
                    <form method="post" class="item-actions" data-confirm="Bu kayıt silinsin mi?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                        <button class="icon-btn" type="submit" aria-label="Sil"><?= icon('trash', 'dim') ?></button>
                    </form>
                </div>
            <?php endif;
        endforeach; ?>
    </div>
<?php else: ?>
    <?= empty_state($show === 'gider' ? 'Bu ay gider kaydı yok.' : ($show === 'gelir' ? 'Bu ay gelir yok.' : 'Bu ay hareket yok.')) ?>
<?php endif; ?>

<div class="section-head" id="duzenli"><h2>Düzenli giderler / gelirler</h2></div>
<?php if ($recurring): ?>
    <div class="list">
        <?php foreach ($recurring as $r): $in = $r['type'] === 'income'; ?>
            <div class="item<?= $r['is_active'] ? '' : ' is-done' ?>">
                <span class="avatar sm"><?= icon('refresh') ?></span>
                <div class="item-main">
                    <span class="item-title"><?= e($r['category']) ?><?= $r['description'] ? ' · ' . e($r['description']) : '' ?></span>
                    <div class="item-sub">
                        <?= badge(TXN_CYCLES[$r['cycle']], 'accent') ?>
                        <?php if ($r['is_active']): ?><span><?= icon('calendar') ?>Sonraki <?= date_tr(recurring_next_date($r)) ?></span>
                        <?php else: ?><span>Durduruldu</span><?php endif; ?>
                    </div>
                </div>
                <span class="item-amount <?= $in ? 'text-good' : 'text-critical' ?>"><?= $in ? '+' : '−' ?><?= money($r['amount']) ?></span>
                <form method="post" class="item-actions"<?= $r['is_active'] ? ' data-confirm="Bu düzenli kayıt durdurulsun mu? Geçmiş kayıtlar silinmez."' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn sm ghost" type="submit" name="action" value="<?= $r['is_active'] ? 'stop' : 'resume' ?>"><?= $r['is_active'] ? 'Durdur' : 'Devam et' ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?= empty_state('Henüz düzenli kayıt yok. Hosting gibi yıllık ya da Claude gibi aylık giderleri "Tekrar" seçeneğiyle ekleyin.') ?>
<?php endif; ?>
<?php layout_end();
