<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$services = q_all(
    "SELECT s.*,
        (SELECT COUNT(*) FROM orders o WHERE o.service_id = s.id AND o.status = 'active') AS active_orders,
        (SELECT COALESCE(SUM(CASE o.billing_cycle WHEN 'monthly' THEN o.price WHEN 'yearly' THEN o.price / 12 ELSE 0 END), 0)
           FROM orders o WHERE o.service_id = s.id AND o.status = 'active') AS monthly_value
     FROM services s
     ORDER BY s.is_active DESC, s.kind = 'combo', s.sort_order, s.name"
);

$groups = ['single' => 'Tekli hizmetler', 'combo' => 'Kombo paketler'];

layout_start('Hizmetler / Paketler', 'services', ['fab' => ['href' => 'hizmet-form.php', 'label' => 'Yeni paket']]);
?>
<p class="muted small">Paketlerin varsayılan fiyatı ve ödeme tipi, müşteriye hizmet eklerken formu otomatik doldurur. Her müşteri için ayrıca değiştirilebilir.</p>

<?php foreach ($groups as $kind => $title):
    $list = array_filter($services, fn($s) => $s['kind'] === $kind);
    if (!$list) continue; ?>
    <div class="section-head"><h2><?= e($title) ?></h2></div>
    <div class="list">
        <?php foreach ($list as $s): ?>
            <a class="item" href="hizmet-form.php?id=<?= (int) $s['id'] ?>">
                <span class="avatar"><?= icon($kind === 'combo' ? 'package' : 'briefcase') ?></span>
                <div class="item-main">
                    <span class="item-title"><?= e($s['name']) ?></span>
                    <div class="item-sub">
                        <span><?= e(CYCLE_LABELS[$s['default_cycle']]) ?></span>
                        <?php if ((float) $s['default_setup_fee'] > 0): ?><span>Kurulum <?= money($s['default_setup_fee']) ?></span><?php endif; ?>
                        <span><?= (int) $s['active_orders'] ?> aktif müşteri</span>
                        <?php if (!$s['is_active']): ?><?= badge('Satışta değil', 'neutral') ?><?php endif; ?>
                    </div>
                </div>
                <div class="item-side">
                    <span class="item-amount"><?= money($s['default_price']) ?></span>
                    <?php if ($s['monthly_value'] > 0): ?><small class="nowrap"><?= money($s['monthly_value']) ?>/ay gelir</small><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php if (!$services): ?>
    <?= empty_state('Henüz paket tanımlanmadı.', 'hizmet-form.php', 'Paket ekle') ?>
<?php endif; ?>
<?php layout_end();
