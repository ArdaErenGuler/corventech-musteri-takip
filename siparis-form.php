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
    $status = $order ? input('status') : 'active';
    [$d, $errors] = validate_order_input($order['billing_cycle'] ?? null);
    if (!$customerId || !q_val('SELECT id FROM customers WHERE id = ?', [$customerId])) $errors['customer_id'] = 'Müşteri seçin.';
    if (!isset(ORDER_STATUS_LABELS[$status])) $errors['status'] = 'Durum seçin.';

    if (!$errors) {
        if ($order) {
            $syncFrom = $order['sync_from'];
            if ($order['status'] !== 'active' && $status === 'active') {
                $syncFrom = today();   // Devam ettirilen sipariş: aradaki dönemler borç yazılmaz
            }
            q('UPDATE orders SET customer_id = ?, service_id = ?, title = ?, billing_cycle = ?, price = ?, installments = ?, setup_fee = ?, start_date = ?, first_due_date = ?, end_date = ?, sync_from = ?, status = ?, notes = ? WHERE id = ?', [
                $customerId, $d['service_id'], $d['title'] ?: null, $d['billing_cycle'], $d['price'], $d['installments'], $d['setup_fee'],
                $d['start_date'], $d['first_due_date'], $d['end_date'], $syncFrom, $status, $d['notes'] ?: null, $id,
            ]);
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
            $id = create_order(['customer_id' => $customerId] + $d, (int) $user['id']);
            $renewOf = input_int('renew_of');
            $old = $renewOf ? q_one('SELECT * FROM orders WHERE id = ? AND customer_id = ? AND id <> ?', [$renewOf, $customerId, $id]) : null;
            if ($old && $old['status'] === 'active') {
                q("UPDATE orders SET status = 'completed' WHERE id = ?", [$old['id']]);
                // Taksitli / tek seferlik işte kalan taksitler borç olarak durur; aylık/yıllıkta ileri tarihli ödenmemiş dönemler kalkar
                if (!in_array($old['billing_cycle'], ['installment', 'one_time'], true)) {
                    trim_future_dues((int) $old['id']);
                }
            }
            flash('success', $old ? 'Yeni dönem oluşturuldu; önceki iş tamamlandı olarak işaretlendi.' : 'Hizmet eklendi ve ödeme planı oluşturuldu.');
        }
        redirect('siparis-form.php?id=' . $id . '#plan');
    }
}

// Yenileme (?yenile=ID): aynı müşteri / paket / ödeme tipi önceden dolu gelir; yeni fiyatı siz yazarsınız
$renewFrom = !$order && input_int('yenile') ? q_one('SELECT * FROM orders WHERE id = ?', [input_int('yenile')]) : null;
$o = $order ?? ($renewFrom ? [
    'customer_id'    => $renewFrom['customer_id'],
    'service_id'     => $renewFrom['service_id'],
    'title'          => $renewFrom['title'],
    'billing_cycle'  => $renewFrom['billing_cycle'],
    'installments'   => $renewFrom['installments'],
    'start_date'     => today(),
    'first_due_date' => today(),
    'status'         => 'active',
    'setup_fee'      => 0,
] : [
    'customer_id'    => input_int('customer_id'),
    'billing_cycle'  => 'installment',
    'start_date'     => today(),
    'first_due_date' => today(),
    'status'         => 'active',
    'setup_fee'      => 0,
]);
$val = fn($k) => e(old($k, $o[$k] ?? ''));

$customers = q_all("SELECT id, company_name, contact_name FROM customers ORDER BY status = 'passive', COALESCE(NULLIF(company_name, ''), contact_name)");
$services = q_all('SELECT * FROM services WHERE is_active = 1 OR id = ? ORDER BY kind, sort_order, name', [$o['service_id'] ?? 0]);
$dues = $order ? q_all(DUES_SELECT . ' WHERE d.order_id = ? ORDER BY d.period_no', [$id]) : [];
$selectedCustomer = (int) old('customer_id', $o['customer_id'] ?? '');
$back = $renewFrom ? 'siparis-form.php?id=' . $renewFrom['id'] : ($order ? 'musteri.php?id=' . $order['customer_id'] : ($selectedCustomer ? 'musteri.php?id=' . $selectedCustomer : 'siparisler.php'));

layout_start($order ? 'Sipariş' : ($renewFrom ? 'Yeni dönem' : 'Hizmet ekle'), 'orders', ['back' => $back, 'fab' => false]);
?>
<?php if ($renewFrom): ?>
    <div class="callout mb"><?= icon('refresh') ?>
        <div><b>Yeni dönem</b> · önceki fiyat <?= money($renewFrom['price']) ?><?= $renewFrom['billing_cycle'] === 'installment' ? ' · ' . (int) $renewFrom['installments'] . ' taksit' : '' ?><br>
            <span class="muted">Yeni fiyatı ve taksit sayısını aşağıya yazın. Kaydedince önceki iş tamamlandı olur.</span></div>
    </div>
<?php endif; ?>
<form method="post" class="card form" data-order-form novalidate>
    <?= csrf_field() ?>
    <?php if ($renewFrom): ?><input type="hidden" name="renew_of" value="<?= (int) $renewFrom['id'] ?>"><?php endif; ?>
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
        <?php require __DIR__ . '/includes/order-fields.php'; ?>
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
        <div class="row">
            <span class="muted small"><?= count($dues) ?> dönem</span>
            <a class="btn sm soft" href="siparis-form.php?yenile=<?= (int) $order['id'] ?>"><?= icon('refresh') ?>Yeni dönem için yenile</a>
        </div>
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
