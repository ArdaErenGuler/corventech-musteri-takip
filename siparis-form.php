<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = input_int('id');
$order = $id ? q_one('SELECT * FROM orders WHERE id = ?', [$id]) : null;
if ($id && !$order) {
    not_found();
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action', 'save');

    // Tek bir dönemi iptal et / geri al (ödemesi yoksa)
    if ($order && in_array($action, ['waive', 'restore'], true)) {
        $dueId = input_int('due_id') ?? 0;
        $hasPayment = (int) q_val('SELECT COUNT(*) FROM payments WHERE due_id = ?', [$dueId]) > 0;
        if ($action === 'waive' && $hasPayment) {
            flash('error', 'Ödemesi alınmış dönem iptal edilemez.');
        } else {
            q('UPDATE dues SET status = ? WHERE id = ? AND order_id = ?', [$action === 'waive' ? 'waived' : 'open', $dueId, $id]);
            flash('success', $action === 'waive' ? 'Dönem iptal edildi; borca sayılmayacak.' : 'Dönem geri alındı.');
        }
        redirect('siparis-form.php?id=' . $id . '#plan');
    }

    if ($action === 'delete' && $order) {
        if ((int) q_val('SELECT COUNT(*) FROM payments p JOIN dues d ON d.id = p.due_id WHERE d.order_id = ?', [$id]) > 0) {
            flash('error', 'Bu siparişe ödeme alınmış, silinemez. Durumunu "İptal" yapabilirsiniz.');
            redirect('siparis-form.php?id=' . $id);
        }
        q('DELETE FROM orders WHERE id = ?', [$id]);
        flash('success', 'Sipariş silindi.');
        redirect('musteri.php?id=' . $order['customer_id']);
    }

    $customerId = input_int('customer_id');
    $serviceId = input_int('service_id');
    $cycle = input('billing_cycle');
    $price = input_money('price');
    $setup = input_money('setup_fee') ?? 0.0;
    $start = input_date('start_date');
    $firstDue = input_date('first_due_date');
    $end = input_date('end_date');
    $status = $order ? input('status') : 'active';

    if (!$customerId || !q_val('SELECT id FROM customers WHERE id = ?', [$customerId])) $errors['customer_id'] = 'Müşteri seçin.';
    if ($serviceId && !q_val('SELECT id FROM services WHERE id = ?', [$serviceId])) $errors['service_id'] = 'Geçersiz paket.';
    if (!$serviceId && input('title') === '') $errors['service_id'] = 'Paket seçin ya da hizmete bir ad yazın.';
    if (!isset(CYCLE_LABELS[$cycle])) $errors['billing_cycle'] = 'Ödeme tipini seçin.';
    if ($price === null || $price < 0) $errors['price'] = 'Geçerli bir tutar yazın.';
    if ($setup < 0) $errors['setup_fee'] = 'Geçerli bir tutar yazın.';
    if (!$start) $errors['start_date'] = 'Tarih seçin.';
    if (!isset(ORDER_STATUS_LABELS[$status])) $errors['status'] = 'Durum seçin.';

    if ($cycle === 'one_time') {
        $firstDue = $start;
        $setup = 0.0;
        $end = null;
    } else {
        $firstDue = $firstDue ?: $start;
        if ($end && $firstDue && $end < $firstDue) $errors['end_date'] = 'Bitiş tarihi ilk ödemeden önce olamaz.';
    }

    if (!$errors) {
        $syncFrom = $order['sync_from'] ?? null;
        $wasActive = $order && $order['status'] === 'active';
        if ($order && !$wasActive && $status === 'active') {
            $syncFrom = today();   // Devam ettirilen sipariş: aradaki dönemler borç yazılmaz
        }
        $values = [$customerId, $serviceId, input('title') ?: null, $cycle, $price, $setup, $start, $firstDue, $end, $syncFrom, $status, input('notes') ?: null];

        if ($order) {
            q('UPDATE orders SET customer_id = ?, service_id = ?, title = ?, billing_cycle = ?, price = ?, setup_fee = ?, start_date = ?, first_due_date = ?, end_date = ?, sync_from = ?, status = ?, notes = ? WHERE id = ?', [...$values, $id]);
            if ($customerId !== (int) $order['customer_id']) {
                q('UPDATE dues SET customer_id = ? WHERE order_id = ?', [$customerId, $id]);
                q('UPDATE payments p JOIN dues d ON d.id = p.due_id SET p.customer_id = ? WHERE d.order_id = ?', [$customerId, $id]);
            }
            if ($status === 'active') {
                rebuild_order_dues($id);
            } else {
                trim_future_dues($id);
            }
            flash('success', 'Sipariş güncellendi; ödeme planı yeniden hesaplandı.');
        } else {
            q('INSERT INTO orders (customer_id, service_id, title, billing_cycle, price, setup_fee, start_date, first_due_date, end_date, sync_from, status, notes, created_by)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $user['id']]);
            $id = (int) db()->lastInsertId();
            sync_order_dues(q_one('SELECT * FROM orders WHERE id = ?', [$id]));
            if (q_val('SELECT status FROM customers WHERE id = ?', [$customerId]) === 'potential') {
                q("UPDATE customers SET status = 'active' WHERE id = ?", [$customerId]);
            }
            flash('success', 'Hizmet eklendi ve ödeme planı oluşturuldu.');
        }
        redirect('siparis-form.php?id=' . $id . '#plan');
    }
}

$o = $order ?? [
    'customer_id'    => input_int('customer_id'),
    'billing_cycle'  => 'monthly',
    'start_date'     => today(),
    'first_due_date' => today(),
    'status'         => 'active',
    'setup_fee'      => 0,
];
$val = fn($k) => e(old($k, $o[$k] ?? ''));

$customers = q_all("SELECT id, company_name, contact_name FROM customers ORDER BY status = 'passive', COALESCE(NULLIF(company_name, ''), contact_name)");
$services = q_all('SELECT * FROM services WHERE is_active = 1 OR id = ? ORDER BY kind, sort_order, name', [$o['service_id'] ?? 0]);
$dues = $order ? q_all(DUES_SELECT . ' WHERE d.order_id = ? ORDER BY d.period_no', [$id]) : [];
$selectedCustomer = (int) old('customer_id', $o['customer_id'] ?? '');
$back = $order ? 'musteri.php?id=' . $order['customer_id'] : ($selectedCustomer ? 'musteri.php?id=' . $selectedCustomer : 'siparisler.php');

layout_start($order ? 'Sipariş' : 'Hizmet ekle', 'orders', ['back' => $back, 'fab' => false]);
?>
<form method="post" class="card form" data-order-form novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field span-2<?= isset($errors['customer_id']) ? ' has-error' : '' ?>">
            <label for="customer_id">Müşteri <span class="req">*</span></label>
            <select id="customer_id" name="customer_id" required>
                <option value="">Müşteri seçin</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $selectedCustomer ? ' selected' : '' ?>><?= e(customer_display_name($c)) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'customer_id') ?>
        </div>
        <div class="field<?= isset($errors['service_id']) ? ' has-error' : '' ?>">
            <label for="service_id">Paket</label>
            <select id="service_id" name="service_id">
                <option value="">Özel hizmet (paketsiz)</option>
                <?php foreach ($services as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" data-price="<?= e(money_input($s['default_price'])) ?>" data-setup="<?= e(money_input($s['default_setup_fee'])) ?>" data-cycle="<?= e($s['default_cycle']) ?>"<?= (int) old('service_id', $o['service_id'] ?? '') === (int) $s['id'] ? ' selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'service_id') ?>
        </div>
        <div class="field">
            <label for="title">Hizmet adı <span class="dim">(opsiyonel)</span></label>
            <input id="title" name="title" value="<?= $val('title') ?>" placeholder="Boşsa paket adı kullanılır">
        </div>
        <fieldset class="field span-2<?= isset($errors['billing_cycle']) ? ' has-error' : '' ?>">
            <legend class="label">Ödeme tipi</legend>
            <div class="segmented">
                <?php foreach (CYCLE_LABELS as $k => $label): ?>
                    <label><input type="radio" name="billing_cycle" value="<?= $k ?>"<?= old('billing_cycle', $o['billing_cycle']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
            <?= field_error($errors, 'billing_cycle') ?>
        </fieldset>
        <div class="field<?= isset($errors['price']) ? ' has-error' : '' ?>">
            <label for="price" data-price-label>Aylık tutar</label>
            <div class="input-money"><input id="price" name="price" inputmode="decimal" value="<?= e(old('price', money_input($o['price'] ?? ''))) ?>" required></div>
            <?= field_error($errors, 'price') ?>
        </div>
        <div class="field<?= isset($errors['setup_fee']) ? ' has-error' : '' ?>" data-recurring-only>
            <label for="setup_fee">Kurulum ücreti</label>
            <div class="input-money"><input id="setup_fee" name="setup_fee" inputmode="decimal" value="<?= e(old('setup_fee', money_input($o['setup_fee'] ?? ''))) ?>"></div>
            <span class="hint">Başlangıç tarihinde bir kez alınır (yoksa 0).</span>
            <?= field_error($errors, 'setup_fee') ?>
        </div>
        <div class="field<?= isset($errors['start_date']) ? ' has-error' : '' ?>">
            <label for="start_date">Başlangıç tarihi</label>
            <input id="start_date" name="start_date" type="date" value="<?= $val('start_date') ?>" required>
            <?= field_error($errors, 'start_date') ?>
        </div>
        <div class="field" data-recurring-only>
            <label for="first_due_date">İlk ödeme tarihi</label>
            <input id="first_due_date" name="first_due_date" type="date" value="<?= $val('first_due_date') ?>">
            <span class="hint">Sonraki ödemeler bu tarihten itibaren her ay / her yıl aynı güne yazılır.</span>
        </div>
        <div class="field<?= isset($errors['end_date']) ? ' has-error' : '' ?>" data-recurring-only>
            <label for="end_date">Bitiş tarihi <span class="dim">(opsiyonel)</span></label>
            <input id="end_date" name="end_date" type="date" value="<?= $val('end_date') ?>">
            <span class="hint">Sözleşme belli bir tarihte bitiyorsa.</span>
            <?= field_error($errors, 'end_date') ?>
        </div>
        <?php if ($order): ?>
            <div class="field">
                <label for="status">Durum</label>
                <select id="status" name="status">
                    <?php foreach (ORDER_STATUS_LABELS as $k => $label): ?>
                        <option value="<?= $k ?>"<?= old('status', $o['status']) === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Durdurulan / iptal edilen siparişin ileri tarihli ödemeleri kaldırılır.</span>
            </div>
        <?php endif; ?>
        <div class="field span-2">
            <label for="notes">Not</label>
            <textarea id="notes" name="notes" rows="2"><?= $val('notes') ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <?php if ($order): ?>
            <button class="btn danger" type="submit" name="action" value="delete" formnovalidate data-confirm="Sipariş ve ödeme planı silinsin mi?"><?= icon('trash') ?>Sil</button>
        <?php endif; ?>
        <span class="spacer"></span>
        <a class="btn ghost" href="<?= e($back) ?>">Vazgeç</a>
        <button class="btn" type="submit" name="action" value="save"><?= icon('check') ?>Kaydet</button>
    </div>
</form>

<?php if ($order): ?>
    <div class="section-head" id="plan">
        <h2>Ödeme planı</h2>
        <span class="muted small"><?= count($dues) ?> dönem</span>
    </div>
    <?php if ($dues): ?>
        <div class="list">
            <?php foreach ($dues as $d):
                $state = due_state($d); ?>
                <div class="item">
                    <div class="item-main">
                        <span class="item-title"><?= e(due_label($d)) ?></span>
                        <div class="item-sub">
                            <span class="<?= $state === 'overdue' ? 'text-critical' : '' ?>"><?= icon('calendar') ?><?= date_tr($d['due_date']) ?></span>
                            <?php if ((float) $d['paid'] > 0): ?><span>Ödenen <?= money($d['paid']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="item-side">
                        <span class="item-amount"><?= money($d['amount']) ?></span>
                        <?= due_state_badge($d) ?>
                    </div>
                    <div class="item-actions">
                        <?php if (!in_array($state, ['paid', 'waived'], true)): ?>
                            <a class="icon-btn soft" href="odeme-form.php?due_id=<?= (int) $d['id'] ?>" aria-label="Ödeme al"><?= icon('wallet') ?></a>
                        <?php endif; ?>
                        <?php if ($state === 'waived' || (float) $d['paid'] <= 0): ?>
                            <form method="post" data-confirm="<?= $state === 'waived' ? 'Dönem geri alınsın mı?' : 'Bu dönem iptal edilsin mi? Borca sayılmayacak.' ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="due_id" value="<?= (int) $d['id'] ?>">
                                <button class="icon-btn" type="submit" name="action" value="<?= $state === 'waived' ? 'restore' : 'waive' ?>" aria-label="<?= $state === 'waived' ? 'Geri al' : 'Dönemi iptal et' ?>">
                                    <?= icon($state === 'waived' ? 'refresh' : 'x', 'dim') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted small mt">Gelecek dönemler, tarihi yaklaştıkça (<?= (int) config('due_horizon_days', 60) ?> gün önceden) otomatik eklenir.</p>
    <?php else: ?>
        <?= empty_state('Bu sipariş için ödeme dönemi yok.') ?>
    <?php endif; ?>
<?php endif; ?>
<?php layout_end();
