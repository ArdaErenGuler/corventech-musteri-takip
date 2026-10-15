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
    $wallet = input('wallet');
    $category = input('category');
    $amount = input_money('amount');
    $date = input_date('txn_date');
    $cycle = input('cycle');
    $allowed = $type === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;

    if (!isset(WALLETS[$wallet])) $errors['wallet'] = 'Kasa seçin.';
    if (!in_array($category, $allowed, true)) $errors['category'] = 'Kategori seçin.';
    if ($amount === null || $amount <= 0) $errors['amount'] = 'Tutarı yazın.';
    if (!$date) $errors['txn_date'] = 'Tarih seçin.';
    if (!isset(TXN_CYCLES[$cycle])) $cycle = 'once';

    if (!$errors) {
        $label = ($type === 'income' ? 'Gelir' : 'Gider');
        if ($cycle === 'once') {
            q('INSERT INTO transactions (type, wallet, category, amount, txn_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$type, $wallet, $category, $amount, $date, input('description') ?: null, $user['id']]);
            flash('success', "$label eklendi: " . money($amount));
        } else {
            q('INSERT INTO recurring_transactions (type, wallet, category, amount, cycle, start_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$type, $wallet, $category, $amount, $cycle, $date, input('description') ?: null, $user['id']]);
            sync_recurring_transactions(true);
            flash('success', "$label " . mb_strtolower(TXN_CYCLES[$cycle]) . ' tekrarlanacak: ' . money($amount));
        }
        redirect('gelir-gider.php?ay=' . substr($date, 0, 7));
    }
}

$month = DateTimeImmutable::createFromFormat('!Y-m', input('ay')) ?: new DateTimeImmutable(date('Y-m-01'));
$rows = q_all(
    'SELECT t.*, r.cycle FROM transactions t LEFT JOIN recurring_transactions r ON r.id = t.recurring_id
     WHERE t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC, t.id DESC',
    [$month->format('Y-m-01'), $month->format('Y-m-t')]
);
$recurring = q_all('SELECT * FROM recurring_transactions ORDER BY is_active DESC, type, category');
$totals = ['income' => 0.0, 'expense' => 0.0];
foreach ($rows as $r) {
    $totals[$r['type']] += (float) $r['amount'];
}
$type = old('type', 'expense');

layout_start('Gelir / Gider', 'finance', ['fab' => false]);
?>
<form method="post" class="card form mb" novalidate>
    <?= csrf_field() ?>
    <div class="segmented" role="radiogroup" aria-label="Kayıt türü">
        <label><input type="radio" name="type" value="expense" data-txn-type<?= $type === 'expense' ? ' checked' : '' ?>><span>Gider</span></label>
        <label><input type="radio" name="type" value="income" data-txn-type<?= $type === 'income' ? ' checked' : '' ?>><span>Gelir</span></label>
    </div>
    <div class="form-grid">
        <fieldset class="field span-2<?= isset($errors['wallet']) ? ' has-error' : '' ?>">
            <legend class="label" data-wallet-label><?= $type === 'income' ? 'Hangi kasaya girdi?' : 'Hangi kasadan ödendi?' ?></legend>
            <div class="segmented">
                <?php foreach (['company' => 'Şirket', 'arda' => 'Arda', 'melih' => 'Melih'] as $k => $label): ?>
                    <label><input type="radio" name="wallet" value="<?= $k ?>"<?= old('wallet', 'company') === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
            <?= field_error($errors, 'wallet') ?>
        </fieldset>
        <div class="field<?= isset($errors['category']) ? ' has-error' : '' ?>">
            <label for="category">Kategori</label>
            <select id="category" name="category">
                <optgroup label="Gider" data-for="expense"<?= $type === 'income' ? ' disabled hidden' : '' ?>>
                    <?php foreach (EXPENSE_CATEGORIES as $cat): ?><option<?= old('category') === $cat ? ' selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?>
                </optgroup>
                <optgroup label="Gelir" data-for="income"<?= $type === 'expense' ? ' disabled hidden' : '' ?>>
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
    <div class="form-actions"><span class="spacer"></span><button class="btn" type="submit"><?= icon('plus') ?>Ekle</button></div>
</form>

<div class="cal-toolbar">
    <div class="cal-nav">
        <a class="icon-btn soft" href="gelir-gider.php?ay=<?= $month->modify('-1 month')->format('Y-m') ?>" aria-label="Önceki ay"><?= icon('chevron-left') ?></a>
        <h2 class="cal-title"><?= TR_MONTHS[(int) $month->format('n')] ?> <span><?= $month->format('Y') ?></span></h2>
        <a class="icon-btn soft" href="gelir-gider.php?ay=<?= $month->modify('+1 month')->format('Y-m') ?>" aria-label="Sonraki ay"><?= icon('chevron-right') ?></a>
    </div>
</div>

<div class="stats">
    <div class="stat"><div class="stat-label"><?= icon('trend') ?>Gelir</div><div class="stat-value text-good"><?= money($totals['income']) ?></div></div>
    <div class="stat"><div class="stat-label"><?= icon('wallet') ?>Gider</div><div class="stat-value text-critical"><?= money($totals['expense']) ?></div></div>
</div>

<?php if ($rows): ?>
    <div class="list">
        <?php foreach ($rows as $r): $in = $r['type'] === 'income'; ?>
            <div class="item">
                <span class="avatar sm"><?= icon($in ? 'trend' : 'wallet') ?></span>
                <div class="item-main">
                    <span class="item-title"><?= e($r['category']) ?></span>
                    <div class="item-sub">
                        <span><?= date_tr($r['txn_date']) ?></span>
                        <?= badge(WALLETS[$r['wallet']] . ($in ? ' kasasına' : ' kasasından'), 'neutral', 'briefcase') ?>
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
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?= empty_state('Bu ay gelir / gider kaydı yok.') ?>
<?php endif; ?>

<div class="section-head" id="duzenli"><h2>Düzenli gelir / giderler</h2></div>
<?php if ($recurring): ?>
    <div class="list">
        <?php foreach ($recurring as $r): $in = $r['type'] === 'income'; ?>
            <div class="item<?= $r['is_active'] ? '' : ' is-done' ?>">
                <span class="avatar sm"><?= icon('refresh') ?></span>
                <div class="item-main">
                    <span class="item-title"><?= e($r['category']) ?><?= $r['description'] ? ' · ' . e($r['description']) : '' ?></span>
                    <div class="item-sub">
                        <?= badge(TXN_CYCLES[$r['cycle']], 'accent') ?>
                        <span><?= e(WALLETS[$r['wallet']]) ?> kasası</span>
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
