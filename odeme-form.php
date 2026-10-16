<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = input_int('id');
$payment = $id ? q_one('SELECT * FROM payments WHERE id = ?', [$id]) : null;
if ($id && !$payment) {
    not_found();
}

// Yenilemeler / müşteri sayfasından gelindiyse dönem ve müşteri önceden seçilir
$presetDue = !$payment && input_int('due_id') ? q_one(DUES_SELECT . ' WHERE d.id = ?', [input_int('due_id')]) : null;
$errors = [];

if (is_post()) {
    verify_csrf();

    if (input('action') === 'delete' && $payment) {
        q('DELETE FROM payments WHERE id = ?', [$id]);
        flash('success', 'Ödeme kaydı silindi.');
        redirect('musteri.php?id=' . $payment['customer_id'] . '#odemeler');
    }

    $customerId = input_int('customer_id');
    $dueId = input_int('due_id');
    $amount = input_money('amount');
    $paidAt = input_date('paid_at');
    $method = input('method');

    if (!$customerId || !q_val('SELECT id FROM customers WHERE id = ?', [$customerId])) $errors['customer_id'] = 'Müşteri seçin.';
    if ($dueId && (int) q_val('SELECT customer_id FROM dues WHERE id = ?', [$dueId]) !== $customerId) $errors['due_id'] = 'Seçilen dönem bu müşteriye ait değil.';
    if ($amount === null || $amount <= 0) $errors['amount'] = 'Tutar yazın.';
    if (!$paidAt) $errors['paid_at'] = 'Tarih seçin.';
    if ($paidAt && $paidAt > today()) $errors['paid_at'] = 'İleri tarihli ödeme girilemez.';
    if (!isset(PAYMENT_METHODS[$method])) $errors['method'] = 'Ödeme yöntemi seçin.';

    if (!$errors) {
        $values = [$customerId, $dueId ?: null, $amount, $paidAt, $method, input('note') ?: null];
        if ($payment) {
            q('UPDATE payments SET customer_id = ?, due_id = ?, amount = ?, paid_at = ?, method = ?, note = ? WHERE id = ?', [...$values, $id]);
            flash('success', 'Ödeme güncellendi.');
        } else {
            $parts = record_payment($customerId, $dueId, $amount, $paidAt, $method, input('note') ?: null, (int) $user['id']);
            flash('success', money($amount) . ' ödeme kaydedildi' . ($parts > 1 ? " ve $parts döneme dağıtıldı." : '.'));
        }
        redirect(safe_return(input('return'), 'musteri.php?id=' . $customerId . '#odemeler'));
    }
}

$p = $payment ?? [
    'customer_id' => $presetDue['customer_id'] ?? input_int('customer_id'),
    'due_id'      => $presetDue['id'] ?? null,
    'amount'      => $presetDue ? money_input($presetDue['remaining']) : '',
    'paid_at'     => today(),
    'method'      => 'transfer',
];
$selectedCustomer = (int) old('customer_id', $p['customer_id'] ?? '');
$selectedDue = (int) old('due_id', $p['due_id'] ?? '');

$customers = q_all("SELECT id, company_name, contact_name FROM customers ORDER BY status = 'passive', COALESCE(NULLIF(company_name, ''), contact_name)");
$dues = [];
if ($selectedCustomer) {
    // Ödenmemiş dönemler + (düzenlemede) bu ödemenin bağlı olduğu dönem
    $dues = q_all(DUES_SELECT . ' WHERE d.customer_id = ? AND ((' . DUES_UNPAID . ') OR d.id = ?) ORDER BY d.due_date', [$selectedCustomer, $selectedDue]);
}
if (!$payment && !$presetDue && $dues && !is_post()) {
    $selectedDue = (int) $dues[0]['id'];        // varsayılan: en eski ödenmemiş dönem
    $p['amount'] = money_input($dues[0]['remaining']);
}
$val = fn($k) => e(old($k, $p[$k] ?? ''));
$back = $selectedCustomer ? 'musteri.php?id=' . $selectedCustomer . '#odemeler' : 'odemeler.php';
// Yenilemeler'den gelindiyse kayıttan sonra oraya dönülür
$return = safe_return(input('return') ?: ($presetDue ? ($_SERVER['HTTP_REFERER'] ?? '') : ''), '');
if ($return !== '' && str_starts_with($return, 'odeme-form.php')) {
    $return = '';
}

layout_start($payment ? 'Ödemeyi düzenle' : 'Ödeme al', 'payments', ['back' => $back, 'fab' => false]);
?>
<?php if ($presetDue): ?>
    <div class="callout mb"><?= icon('wallet') ?>
        <div><b><?= e(customer_display_name($presetDue)) ?></b> · <?= e(order_name($presetDue)) ?> · <?= e(due_label($presetDue)) ?><br>
            <span class="muted">Son ödeme tarihi <?= date_tr($presetDue['due_date']) ?> · Kalan <?= money($presetDue['remaining']) ?></span></div>
    </div>
<?php endif; ?>

<form method="post" class="card form" data-payment-form novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e($return) ?>">
    <div class="form-grid">
        <div class="field<?= isset($errors['customer_id']) ? ' has-error' : '' ?>">
            <label for="customer_id">Müşteri <span class="req">*</span></label>
            <select id="customer_id" name="customer_id" required>
                <option value="">Müşteri seçin</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $selectedCustomer ? ' selected' : '' ?>><?= e(customer_display_name($c)) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'customer_id') ?>
        </div>
        <div class="field<?= isset($errors['due_id']) ? ' has-error' : '' ?>">
            <label for="due_id">Hangi ödeme için?</label>
            <select id="due_id" name="due_id">
                <option value="">Döneme bağlama (genel ödeme)</option>
                <?php foreach ($dues as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" data-remaining="<?= e(money_input($d['remaining'])) ?>"<?= (int) $d['id'] === $selectedDue ? ' selected' : '' ?>>
                        <?= e(order_name($d) . ' · ' . due_label($d) . ' · ' . date_tr($d['due_date']) . ' · ' . money($d['remaining'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="hint">Kalan tutar otomatik yazılır. Daha fazlası girilirse fazlası sonraki aylara dağıtılır.</span>
            <?= field_error($errors, 'due_id') ?>
        </div>
        <div class="field<?= isset($errors['amount']) ? ' has-error' : '' ?>">
            <label for="amount">Tutar <span class="req">*</span></label>
            <div class="input-money"><input id="amount" name="amount" inputmode="decimal" value="<?= $val('amount') ?>" required></div>
            <?= field_error($errors, 'amount') ?>
        </div>
        <div class="field<?= isset($errors['paid_at']) ? ' has-error' : '' ?>">
            <label for="paid_at">Ödeme tarihi</label>
            <input id="paid_at" name="paid_at" type="date" value="<?= $val('paid_at') ?>" max="<?= today() ?>" required>
            <?= field_error($errors, 'paid_at') ?>
        </div>
        <fieldset class="field span-2<?= isset($errors['method']) ? ' has-error' : '' ?>">
            <legend class="label">Ödeme yöntemi</legend>
            <div class="segmented">
                <?php foreach (PAYMENT_METHODS as $k => $label): ?>
                    <label><input type="radio" name="method" value="<?= $k ?>"<?= old('method', $p['method']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
            <?= field_error($errors, 'method') ?>
        </fieldset>
        <div class="field span-2">
            <label for="note">Not <span class="dim">(opsiyonel)</span></label>
            <input id="note" name="note" value="<?= $val('note') ?>" maxlength="255" placeholder="Örn. dekont no, kalanı ay sonunda">
        </div>
    </div>
    <div class="form-actions">
        <?php if ($payment): ?>
            <button class="btn danger" type="submit" name="action" value="delete" formnovalidate data-confirm="Ödeme kaydı silinsin mi?"><?= icon('trash') ?>Sil</button>
        <?php endif; ?>
        <span class="spacer"></span>
        <a class="btn ghost" href="<?= e($back) ?>">Vazgeç</a>
        <button class="btn" type="submit" name="action" value="save"><?= icon('check') ?><?= $payment ? 'Kaydet' : 'Ödemeyi kaydet' ?></button>
    </div>
</form>
<?php layout_end();
