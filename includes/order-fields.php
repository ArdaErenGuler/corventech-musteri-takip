<?php
/**
 * Hizmet / ödeme planı alanları (sipariş formu ve yeni müşteri formu ortak kullanır).
 * Beklenen değişkenler: $o (varsayılan değerler), $errors, $services
 * Fiyat otomatik dolmaz; her müşteri için elle girilir.
 */
$ov = fn($k) => e(old($k, $o[$k] ?? ''));
?>
<div class="field<?= isset($errors['service_id']) ? ' has-error' : '' ?>">
    <label for="service_id">Paket</label>
    <select id="service_id" name="service_id">
        <option value="">Özel hizmet (paketsiz)</option>
        <?php foreach ($services as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= (int) old('service_id', $o['service_id'] ?? '') === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'service_id') ?>
</div>
<div class="field">
    <label for="title">Hizmet adı <span class="dim">(opsiyonel)</span></label>
    <input id="title" name="title" value="<?= $ov('title') ?>" placeholder="Boşsa paket adı kullanılır">
</div>
<fieldset class="field span-2<?= isset($errors['billing_cycle']) ? ' has-error' : '' ?>">
    <legend class="label">Ödeme tipi</legend>
    <div class="segmented">
        <?php foreach (CYCLE_LABELS as $k => $label): ?>
            <label><input type="radio" name="billing_cycle" value="<?= $k ?>"<?= old('billing_cycle', $o['billing_cycle'] ?? 'monthly') === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
    </div>
    <?= field_error($errors, 'billing_cycle') ?>
</fieldset>
<div class="field<?= isset($errors['price']) ? ' has-error' : '' ?>">
    <label for="price" data-price-label>Aylık tutar</label>
    <div class="input-money"><input id="price" name="price" inputmode="decimal" value="<?= e(old('price', money_input($o['price'] ?? ''))) ?>" placeholder="Örn. 7.500"></div>
    <?= field_error($errors, 'price') ?>
</div>
<div class="field<?= isset($errors['setup_fee']) ? ' has-error' : '' ?>" data-recurring-only>
    <label for="setup_fee">Kurulum ücreti <span class="dim">(opsiyonel)</span></label>
    <div class="input-money"><input id="setup_fee" name="setup_fee" inputmode="decimal" value="<?= e(old('setup_fee', money_input(($o['setup_fee'] ?? 0) ?: ''))) ?>" placeholder="0"></div>
    <span class="hint">Başlangıç tarihinde bir kez alınır.</span>
    <?= field_error($errors, 'setup_fee') ?>
</div>
<fieldset class="field span-2<?= isset($errors['split']) ? ' has-error' : '' ?>" data-split>
    <legend class="label">Para kime gidecek?</legend>
    <?php $split = old('split', $o['split'] ?? 'ortak'); ?>
    <div class="segmented split-options">
        <?php foreach (SHARE_PRESETS as $k => [$label, $p]): ?>
            <label><input type="radio" name="split" value="<?= $k ?>"<?= $split === $k ? ' checked' : '' ?>><span><?= e($label) ?><small><?= e(share_text(['share_arda' => $p[0], 'share_melih' => $p[1], 'share_company' => $p[2]])) ?></small></span></label>
        <?php endforeach; ?>
        <label><input type="radio" name="split" value="custom"<?= $split === 'custom' ? ' checked' : '' ?>><span>Özel<small>oranı yaz</small></span></label>
    </div>
    <div class="split-custom" data-split-custom<?= $split === 'custom' ? '' : ' hidden' ?>>
        <?php foreach (['share_arda' => 'Arda', 'share_melih' => 'Melih', 'share_company' => 'Şirket'] as $k => $label): ?>
            <label class="field"><span class="hint"><?= $label ?> %</span>
                <input name="<?= $k ?>" inputmode="decimal" value="<?= e(old($k, isset($o[$k]) ? rtrim(rtrim(number_format((float) $o[$k], 2, '.', ''), '0'), '.') : '0')) ?>">
            </label>
        <?php endforeach; ?>
    </div>
    <span class="hint">Bu işin her ödemesi bu orana göre kasalara yazılır.</span>
    <?= field_error($errors, 'split') ?>
</fieldset>
<div class="field<?= isset($errors['start_date']) ? ' has-error' : '' ?>">
    <label for="start_date">Başlangıç tarihi</label>
    <input id="start_date" name="start_date" type="date" value="<?= $ov('start_date') ?>">
    <?= field_error($errors, 'start_date') ?>
</div>
<div class="field" data-recurring-only>
    <label for="first_due_date">İlk ödeme tarihi</label>
    <input id="first_due_date" name="first_due_date" type="date" value="<?= $ov('first_due_date') ?>">
    <span class="hint">Sonraki ödemeler her ay / her yıl aynı güne yazılır.</span>
</div>
<div class="field<?= isset($errors['end_date']) ? ' has-error' : '' ?>" data-recurring-only>
    <label for="end_date">Bitiş tarihi <span class="dim">(opsiyonel)</span></label>
    <input id="end_date" name="end_date" type="date" value="<?= $ov('end_date') ?>">
    <?= field_error($errors, 'end_date') ?>
</div>
