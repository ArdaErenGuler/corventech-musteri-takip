<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$errors = [];

if (is_post()) {
    verify_csrf();

    if (input('action') === 'delete') {
        q('DELETE FROM transactions WHERE id = ?', [input_int('id') ?? 0]);
        flash('success', 'Kayıt silindi.');
        redirect(safe_return(input('return'), 'gelir-gider.php'));
    }

    $type = input('type') === 'income' ? 'income' : 'expense';
    $category = input('category');
    $amount = input_money('amount');
    $date = input_date('txn_date');
    $allowed = $type === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;

    if (!in_array($category, $allowed, true)) $errors['category'] = 'Kategori seçin.';
    if ($amount === null || $amount <= 0) $errors['amount'] = 'Tutarı yazın.';
    if (!$date) $errors['txn_date'] = 'Tarih seçin.';

    if (!$errors) {
        q('INSERT INTO transactions (type, category, amount, txn_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$type, $category, $amount, $date, input('description') ?: null, $user['id']]);
        flash('success', ($type === 'income' ? 'Gelir' : 'Gider') . ' eklendi: ' . money($amount));
        redirect('gelir-gider.php?ay=' . substr($date, 0, 7));
    }
}

$month = DateTimeImmutable::createFromFormat('!Y-m', input('ay')) ?: new DateTimeImmutable(date('Y-m-01'));
$from = $month->format('Y-m-01');
$to = $month->format('Y-m-t');
$rows = q_all('SELECT * FROM transactions WHERE txn_date BETWEEN ? AND ? ORDER BY txn_date DESC, id DESC', [$from, $to]);
$income = array_sum(array_map(fn($r) => $r['type'] === 'income' ? (float) $r['amount'] : 0, $rows));
$expense = array_sum(array_map(fn($r) => $r['type'] === 'expense' ? (float) $r['amount'] : 0, $rows));
$type = old('type', 'expense');

layout_start('Gelir / Gider', 'finance', ['fab' => false]);
?>
<p class="muted small">Şirket kasasının müşteri tahsilatı dışındaki hareketleri: hosting, domain, Claude gibi giderler ve diğer gelirler. Raporlarda şirket payından düşülür / eklenir.</p>

<form method="post" class="card form mb" novalidate>
    <?= csrf_field() ?>
    <div class="segmented" role="radiogroup" aria-label="Kayıt türü">
        <label><input type="radio" name="type" value="expense" data-txn-type<?= $type === 'expense' ? ' checked' : '' ?>><span>Gider</span></label>
        <label><input type="radio" name="type" value="income" data-txn-type<?= $type === 'income' ? ' checked' : '' ?>><span>Gelir</span></label>
    </div>
    <div class="form-grid">
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
        <div class="field<?= isset($errors['txn_date']) ? ' has-error' : '' ?>">
            <label for="txn_date">Tarih</label>
            <input id="txn_date" name="txn_date" type="date" value="<?= e(old('txn_date', today())) ?>">
            <?= field_error($errors, 'txn_date') ?>
        </div>
        <div class="field">
            <label for="description">Açıklama <span class="dim">(opsiyonel)</span></label>
            <input id="description" name="description" value="<?= e(old('description')) ?>" maxlength="255" placeholder="Örn. Claude aylık abonelik">
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
    <div class="stat">
        <div class="stat-label"><?= icon('trend') ?>Diğer gelir</div>
        <div class="stat-value text-good"><?= money($income) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('wallet') ?>Gider</div>
        <div class="stat-value text-critical"><?= money($expense) ?></div>
    </div>
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
<?php layout_end();
