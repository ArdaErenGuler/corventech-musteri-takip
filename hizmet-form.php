<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$id = input_int('id');
$service = $id ? q_one('SELECT * FROM services WHERE id = ?', [$id]) : null;
if ($id && !$service) {
    not_found();
}

$errors = [];

if (is_post()) {
    verify_csrf();

    if (input('action') === 'delete' && $service) {
        if ((int) q_val('SELECT COUNT(*) FROM orders WHERE service_id = ?', [$id]) > 0) {
            flash('error', 'Bu paket müşterilere satılmış, silinemez. "Satışta" seçeneğini kapatabilirsiniz.');
            redirect('hizmet-form.php?id=' . $id);
        }
        q('DELETE FROM services WHERE id = ?', [$id]);
        flash('success', 'Paket silindi.');
        redirect('hizmetler.php');
    }

    $name = input('name');
    $kind = input('kind');
    $cycle = input('default_cycle');
    $price = input_money('default_price');
    $setup = input_money('default_setup_fee') ?? 0.0;

    if ($name === '') $errors['name'] = 'Paket adını yazın.';
    if (!in_array($kind, ['single', 'combo'], true)) $errors['kind'] = 'Paket türünü seçin.';
    if (!isset(CYCLE_LABELS[$cycle])) $errors['default_cycle'] = 'Ödeme tipini seçin.';
    if ($price === null || $price < 0) $errors['default_price'] = 'Geçerli bir fiyat yazın.';
    if ($setup < 0) $errors['default_setup_fee'] = 'Geçerli bir tutar yazın.';

    if (!$errors) {
        $values = [$name, $kind, input('description') ?: null, $cycle, $price, $cycle === 'one_time' ? 0 : $setup, isset($_POST['is_active']) ? 1 : 0, input_int('sort_order') ?? 0];
        if ($service) {
            q('UPDATE services SET name = ?, kind = ?, description = ?, default_cycle = ?, default_price = ?, default_setup_fee = ?, is_active = ?, sort_order = ? WHERE id = ?', [...$values, $id]);
        } else {
            q('INSERT INTO services (name, kind, description, default_cycle, default_price, default_setup_fee, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $values);
        }
        flash('success', 'Paket kaydedildi.');
        redirect('hizmetler.php');
    }
}

$s = $service ?? ['kind' => 'single', 'default_cycle' => 'monthly', 'is_active' => 1, 'sort_order' => (int) q_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM services')];
$val = fn($k) => e(old($k, $s[$k] ?? ''));

layout_start($service ? 'Paketi düzenle' : 'Yeni paket', 'services', ['back' => 'hizmetler.php', 'fab' => false]);
?>
<form method="post" class="card form" data-order-form novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field span-2<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <label for="name">Paket adı <span class="req">*</span></label>
            <input id="name" name="name" value="<?= $val('name') ?>" required>
            <?= field_error($errors, 'name') ?>
        </div>
        <fieldset class="field">
            <legend class="label">Tür</legend>
            <div class="segmented">
                <label><input type="radio" name="kind" value="single"<?= old('kind', $s['kind']) === 'single' ? ' checked' : '' ?>><span>Tekli</span></label>
                <label><input type="radio" name="kind" value="combo"<?= old('kind', $s['kind']) === 'combo' ? ' checked' : '' ?>><span>Kombo</span></label>
            </div>
        </fieldset>
        <fieldset class="field">
            <legend class="label">Varsayılan ödeme tipi</legend>
            <div class="segmented">
                <?php foreach (CYCLE_LABELS as $k => $label): ?>
                    <label><input type="radio" name="default_cycle" value="<?= $k ?>"<?= old('default_cycle', $s['default_cycle']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>

        </fieldset>
        <div class="field<?= isset($errors['default_price']) ? ' has-error' : '' ?>">
            <label for="default_price" data-price-label>Fiyat</label>
            <div class="input-money"><input id="default_price" name="default_price" inputmode="decimal" value="<?= e(old('default_price', money_input($s['default_price'] ?? ''))) ?>"></div>
            <?= field_error($errors, 'default_price') ?>
        </div>
        <div class="field" data-recurring-only>
            <label for="default_setup_fee">Kurulum ücreti</label>
            <div class="input-money"><input id="default_setup_fee" name="default_setup_fee" inputmode="decimal" value="<?= e(old('default_setup_fee', money_input($s['default_setup_fee'] ?? 0))) ?>"></div>
            <span class="hint">İlk başta bir kez alınır (yoksa 0).</span>
        </div>
        <div class="field span-2">
            <label for="description">Açıklama</label>
            <textarea id="description" name="description" rows="3"><?= $val('description') ?></textarea>
        </div>
        <div class="field">
            <label for="sort_order">Sıra</label>
            <input id="sort_order" name="sort_order" type="number" inputmode="numeric" value="<?= $val('sort_order') ?>">
        </div>
        <label class="check"><input type="checkbox" name="is_active" value="1"<?= old('is_active', $s['is_active']) ? ' checked' : '' ?>> Satışta (yeni siparişlerde listelensin)</label>
    </div>
    <div class="form-actions">
        <?php if ($service): ?>
            <button class="btn danger" type="submit" name="action" value="delete" formnovalidate data-confirm="Paket silinsin mi?"><?= icon('trash') ?>Sil</button>
        <?php endif; ?>
        <span class="spacer"></span>
        <a class="btn ghost" href="hizmetler.php">Vazgeç</a>
        <button class="btn" type="submit" name="action" value="save"><?= icon('check') ?>Kaydet</button>
    </div>
</form>

<?php layout_end();
