<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = input_int('id') ?? 0;
$c = q_one('SELECT * FROM customers WHERE id = ?', [$id]);
if (!$c) {
    not_found();
}

if (is_post()) {
    verify_csrf();
    $action = input('action');
    if ($action === 'add_note' && input('body') !== '') {
        q('INSERT INTO customer_notes (customer_id, user_id, body) VALUES (?, ?, ?)', [$id, $user['id'], input('body')]);
        flash('success', 'Not eklendi.');
    } elseif ($action === 'delete_note') {
        q('DELETE FROM customer_notes WHERE id = ? AND customer_id = ?', [input_int('note_id') ?? 0, $id]);
        flash('success', 'Not silindi.');
    }
    redirect('musteri.php?id=' . $id . '#notlar');
}

$name = customer_display_name($c);
$balance = customer_balance($id);

$orders = q_all(
    "SELECT o.*,
        (SELECT MIN(d.due_date) FROM dues d
          LEFT JOIN (SELECT due_id, SUM(amount) paid FROM payments GROUP BY due_id) pp ON pp.due_id = d.id
          WHERE d.order_id = o.id AND d.status = 'open' AND d.amount - COALESCE(pp.paid, 0) > 0.009) AS next_due,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN dues d ON d.id = p.due_id WHERE d.order_id = o.id) AS order_paid,
        (SELECT COALESCE(SUM(d.amount), 0) FROM dues d WHERE d.order_id = o.id AND d.status = 'open') AS order_total
     FROM orders o
     WHERE o.customer_id = ?
     ORDER BY FIELD(o.status, 'active', 'paused', 'completed', 'cancelled'), o.start_date DESC",
    [$id]
);
$openDues = q_all(DUES_SELECT . ' WHERE ' . DUES_UNPAID . ' AND d.customer_id = ? ORDER BY d.due_date', [$id]);
$payments = q_all(PAYMENTS_SELECT . ' WHERE p.customer_id = ? ORDER BY p.paid_at DESC, p.id DESC', [$id]);
$tasks = q_all(TASKS_SELECT . " WHERE t.customer_id = ? ORDER BY t.status = 'done', t.due_date IS NULL, t.due_date", [$id]);
$notes = q_all('SELECT n.*, u.name AS user_name FROM customer_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.customer_id = ? ORDER BY n.created_at DESC', [$id]);

$activeOrders = array_filter($orders, fn($o) => $o['status'] === 'active');
$monthly = array_sum(array_map(fn($o) => $o['billing_cycle'] === 'monthly' ? $o['price'] : ($o['billing_cycle'] === 'yearly' ? $o['price'] / 12 : 0), $activeOrders));
$overdueCount = count(array_filter($openDues, fn($d) => $d['due_date'] < today()));
$openTasks = count(array_filter($tasks, fn($t) => $t['status'] !== 'done'));

layout_start($name, 'customers', [
    'back'    => 'musteriler.php',
    'actions' => '<a class="icon-btn" href="musteri-form.php?id=' . $id . '" aria-label="Düzenle">' . icon('edit') . '</a>',
    'fab'     => ['href' => 'odeme-form.php?customer_id=' . $id, 'label' => 'Ödeme al'],
]);
?>
<section class="card">
    <div class="profile">
        <span class="avatar lg"><?= e(initials($name)) ?></span>
        <div class="item-main">
            <h2><?= e($name) ?></h2>
            <div class="item-sub">
                <?php if ($c['company_name'] && $c['contact_name']): ?><span><?= icon('user') ?><?= e($c['contact_name']) ?></span><?php endif; ?>
                <?= customer_status_badge($c['status']) ?>
            </div>
        </div>
    </div>
    <div class="profile-actions">
        <?php if ($tel = tel_link($c['phone'])): ?>
            <a class="btn soft" href="<?= e($tel) ?>"><?= icon('phone') ?>Ara</a>
        <?php endif; ?>
        <?php if ($wa = wa_link($c['phone'])): ?>
            <a class="btn wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?>WhatsApp</a>
        <?php endif; ?>
        <?php if ($c['email']): ?>
            <a class="btn soft" href="mailto:<?= e($c['email']) ?>"><?= icon('mail') ?>E-posta</a>
        <?php endif; ?>
    </div>
    <dl class="kv mt">
        <dt>Telefon</dt><dd><?= e($c['phone'] ?: '—') ?></dd>
        <dt>E-posta</dt><dd><?= e($c['email'] ?: '—') ?></dd>
        <dt>Sektör</dt><dd><?= e($c['sector'] ?: '—') ?></dd>
        <dt>Şehir</dt><dd><?= e($c['city'] ?: '—') ?></dd>
        <dt>Kayıt tarihi</dt><dd><?= date_tr($c['registered_at']) ?></dd>
    </dl>
</section>

<div class="stats mt">
    <div class="stat<?= $balance['balance'] > 0.009 ? ' is-critical' : ' is-good' ?>">
        <div class="stat-label"><?= icon($balance['balance'] > 0.009 ? 'alert' : 'check') ?><?= $balance['balance'] < -0.009 ? 'Avans (fazla ödeme)' : 'Kalan borç' ?></div>
        <div class="stat-value"><?= money(abs($balance['balance'])) ?></div>
        <div class="stat-sub"><?= $overdueCount ? $overdueCount . ' ödeme gecikti' : 'Gecikmiş ödeme yok' ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('wallet') ?>Toplam ödeme</div>
        <div class="stat-value"><?= money($balance['total_paid']) ?></div>
        <div class="stat-sub"><?= count($payments) ?> ödeme</div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('package') ?>Aktif hizmet</div>
        <div class="stat-value"><?= count($activeOrders) ?></div>
        <div class="stat-sub">Toplam <?= count($orders) ?> sipariş</div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= icon('trend') ?>Aylık değer</div>
        <div class="stat-value"><?= money($monthly) ?></div>
        <div class="stat-sub">Yıllıklar 12'ye bölünür</div>
    </div>
</div>

<div class="tabs" role="tablist" data-tabs>
    <button class="tab is-active" type="button" role="tab" data-tab="hizmetler"><?= icon('package') ?>Hizmetler</button>
    <button class="tab" type="button" role="tab" data-tab="odemeler"><?= icon('wallet') ?>Ödemeler<?php if ($openDues): ?> <span class="nav-badge<?= $overdueCount ? ' is-alert' : '' ?>"><?= count($openDues) ?></span><?php endif; ?></button>
    <button class="tab" type="button" role="tab" data-tab="gorevler"><?= icon('check-square') ?>Görevler<?php if ($openTasks): ?> <span class="nav-badge"><?= $openTasks ?></span><?php endif; ?></button>
    <button class="tab" type="button" role="tab" data-tab="notlar"><?= icon('note') ?>Notlar</button>
</div>

<!-- Hizmetler -->
<div class="tab-panel is-active" id="hizmetler" role="tabpanel">
    <div class="section-head">
        <h2>Aldığı hizmetler</h2>
        <a class="btn sm soft" href="siparis-form.php?customer_id=<?= $id ?>"><?= icon('plus') ?>Hizmet ekle</a>
    </div>
    <?php if ($orders): ?>
        <div class="list">
            <?php foreach ($orders as $o): ?>
                <a class="item" href="siparis-form.php?id=<?= (int) $o['id'] ?>">
                    <div class="item-main">
                        <span class="item-title"><?= e(order_name($o)) ?></span>
                        <div class="item-sub">
                            <span><?= e(CYCLE_LABELS[$o['billing_cycle']]) ?></span>
                            <span><?= icon('calendar') ?><?= date_tr($o['start_date']) ?></span>
                            <?php if ($o['next_due']): ?>
                                <span class="<?= $o['next_due'] < today() ? 'text-critical' : '' ?>">Sonraki ödeme <?= date_tr($o['next_due']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="item-sub">
                            <span class="text-good">Ödenen <?= money($o['order_paid']) ?></span>
                            <?php $rest = max(0, (float) $o['order_total'] - (float) $o['order_paid']); ?>
                            <span class="<?= $rest > 0.009 ? '' : 'text-good' ?>">Bekleyen <?= money($rest) ?></span>
                        </div>
                    </div>
                    <div class="item-side">
                        <span class="item-amount"><?= money($o['price']) ?><?= $o['billing_cycle'] === 'monthly' ? '<small>/ay</small>' : ($o['billing_cycle'] === 'yearly' ? '<small>/yıl</small>' : ($o['billing_cycle'] === 'installment' ? '<small>' . (int) $o['installments'] . ' taksit</small>' : '')) ?></span>
                        <?= order_status_badge($o['status']) ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?= empty_state('Bu müşteriye henüz hizmet eklenmedi.', 'siparis-form.php?customer_id=' . $id, 'Hizmet ekle') ?>
    <?php endif; ?>
</div>

<!-- Ödemeler -->
<div class="tab-panel" id="odemeler" role="tabpanel">
    <div class="section-head">
        <h2>Bekleyen ödemeler</h2>
        <a class="btn sm soft" href="odeme-form.php?customer_id=<?= $id ?>"><?= icon('plus') ?>Ödeme al</a>
    </div>
    <?php if ($openDues): ?>
        <div class="list"><?php foreach ($openDues as $d) echo due_item($d, false); ?></div>
    <?php else: ?>
        <?= empty_state('Bekleyen ödeme yok.') ?>
    <?php endif; ?>

    <div class="section-head"><h2>Ödeme geçmişi</h2></div>
    <?php if ($payments): ?>
        <div class="list"><?php foreach ($payments as $p) echo payment_item($p, false); ?></div>
    <?php else: ?>
        <?= empty_state('Henüz ödeme alınmadı.') ?>
    <?php endif; ?>
</div>

<!-- Görevler -->
<div class="tab-panel" id="gorevler" role="tabpanel">
    <div class="section-head">
        <h2>Görevler</h2>
        <a class="btn sm soft" href="gorev-form.php?customer_id=<?= $id ?>"><?= icon('plus') ?>Görev ekle</a>
    </div>
    <?php if ($tasks): ?>
        <div class="list"><?php foreach ($tasks as $t) echo task_item($t, false); ?></div>
    <?php else: ?>
        <?= empty_state('Bu müşteri için görev yok.') ?>
    <?php endif; ?>
</div>

<!-- Notlar -->
<div class="tab-panel" id="notlar" role="tabpanel">
    <?php if ($c['notes']): ?>
        <div class="callout mb"><?= icon('note') ?><div><b>Genel not</b><p class="mt-0" style="white-space:pre-wrap;margin:4px 0 0"><?= e($c['notes']) ?></p></div></div>
    <?php endif; ?>
    <form method="post" class="card form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_note">
        <div class="field">
            <label for="body">Görüşme notu ekle</label>
            <textarea id="body" name="body" rows="3" placeholder="Örn. Arandı, ay sonunda ödeyecek." required></textarea>
        </div>
        <div class="form-actions"><span class="spacer"></span><button class="btn" type="submit"><?= icon('plus') ?>Notu kaydet</button></div>
    </form>
    <?php if ($notes): ?>
        <section class="card">
            <?php foreach ($notes as $n): ?>
                <div class="note">
                    <div class="row between">
                        <div class="note-meta"><?= datetime_tr($n['created_at']) ?> · <?= e($n['user_name'] ?? 'Sistem') ?></div>
                        <form method="post" data-confirm="Not silinsin mi?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_note">
                            <input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                            <button class="icon-btn" type="submit" aria-label="Notu sil"><?= icon('trash', 'dim') ?></button>
                        </form>
                    </div>
                    <p><?= e($n['body']) ?></p>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>
<?php layout_end();
