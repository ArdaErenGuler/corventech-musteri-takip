<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = input_int('id');
$customer = $id ? q_one('SELECT * FROM customers WHERE id = ?', [$id]) : null;
if ($id && !$customer) {
    not_found();
}

$errors = [];

if (is_post()) {
    verify_csrf();

    if (input('action') === 'delete' && $customer) {
        if ((int) q_val('SELECT COUNT(*) FROM payments WHERE customer_id = ?', [$id]) > 0) {
            flash('error', 'Bu müşterinin ödeme kayıtları var, silinemez. Durumunu "Pasif" yapabilirsiniz.');
            redirect('musteri-form.php?id=' . $id);
        }
        q('DELETE FROM customers WHERE id = ?', [$id]);
        flash('success', customer_display_name($customer) . ' silindi.');
        redirect('musteriler.php');
    }

    $data = [
        'company_name'  => input('company_name'),
        'contact_name'  => input('contact_name'),
        'phone'         => input('phone'),
        'email'         => mb_strtolower(input('email')),
        'sector'        => input('sector'),
        'city'          => input('city'),
        'status'        => input('status'),
        'owner'         => input('owner'),
        'notes'         => input('notes'),
        'registered_at' => input_date('registered_at'),
    ];

    if ($data['company_name'] === '' && $data['contact_name'] === '') {
        $errors['contact_name'] = 'Ad soyad veya firma adından en az birini yazın.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'E-posta adresi geçersiz.';
    }
    if ($data['phone'] !== '' && strlen(phone_digits($data['phone'])) < 10) {
        $errors['phone'] = 'Telefon numarası eksik görünüyor.';
    }
    if (!isset(CUSTOMER_STATUS_LABELS[$data['status']])) {
        $errors['status'] = 'Durum seçin.';
    }
    if (!isset(OWNER_LABELS[$data['owner']])) {
        $errors['owner'] = 'Müşterinin kime ait olduğunu seçin.';
    }
    if (!$data['registered_at']) {
        $errors['registered_at'] = 'Geçerli bir tarih seçin.';
    }

    // Yeni müşteriyle birlikte ilk hizmet (paket veya tutar girildiyse)
    $withOrder = !$customer && (input('service_id') !== '' || input('title') !== '' || input('price') !== '');
    $order = null;
    if ($withOrder) {
        [$order, $orderErrors] = validate_order_input();
        $errors += $orderErrors;
    }

    if (!$errors) {
        $nullable = fn($v) => $v === '' ? null : $v;
        $values = [
            $nullable($data['company_name']), $data['contact_name'], $nullable($data['phone']), $nullable($data['email']),
            $nullable($data['sector']), $nullable($data['city']), $data['status'], $data['owner'], $nullable($data['notes']), $data['registered_at'],
        ];
        if ($customer) {
            q('UPDATE customers SET company_name = ?, contact_name = ?, phone = ?, email = ?, sector = ?, city = ?, status = ?, owner = ?, notes = ?, registered_at = ? WHERE id = ?', [...$values, $id]);
            flash('success', 'Müşteri bilgileri güncellendi.');
        } else {
            q('INSERT INTO customers (company_name, contact_name, phone, email, sector, city, status, owner, notes, registered_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $user['id']]);
            $id = (int) db()->lastInsertId();
            if ($order) {
                create_order(['customer_id' => $id, 'notes' => null] + $order, (int) $user['id']);
                flash('success', 'Müşteri ve hizmeti eklendi; ödeme planı oluşturuldu.');
            } else {
                flash('success', 'Müşteri eklendi.');
            }
        }
        redirect('musteri.php?id=' . $id);
    }
}

$c = $customer ?? ['status' => 'active', 'owner' => 'ortak', 'registered_at' => today()];
$o = ['billing_cycle' => 'monthly', 'start_date' => today(), 'first_due_date' => today()];
$services = q_all('SELECT id, name FROM services WHERE is_active = 1 ORDER BY kind, sort_order, name');
$val = fn($k) => e(old($k, $c[$k] ?? ''));
$sectors = q("SELECT DISTINCT sector FROM customers WHERE sector IS NOT NULL AND sector <> '' ORDER BY sector")->fetchAll(PDO::FETCH_COLUMN);
$cities = q("SELECT DISTINCT city FROM customers WHERE city IS NOT NULL AND city <> '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);


layout_start($customer ? 'Müşteriyi düzenle' : 'Yeni müşteri', 'customers', [
    'back' => $customer ? 'musteri.php?id=' . $id : 'musteriler.php',
    'fab'  => false,
]);
?>
<form method="post" class="form" data-order-form novalidate>
    <div class="card">
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field">
            <label for="company_name">Firma adı</label>
            <input id="company_name" name="company_name" value="<?= $val('company_name') ?>" autocomplete="organization">
        </div>
        <div class="field<?= isset($errors['contact_name']) ? ' has-error' : '' ?>">
            <label for="contact_name">Ad soyad</label>
            <input id="contact_name" name="contact_name" value="<?= $val('contact_name') ?>" autocomplete="name">
            <?= field_error($errors, 'contact_name') ?>
        </div>
        <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
            <label for="phone">Telefon</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" value="<?= $val('phone') ?>" placeholder="05xx xxx xx xx" autocomplete="tel">
            <?= field_error($errors, 'phone') ?>
        </div>
        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
            <label for="email">E-posta <span class="dim">(opsiyonel)</span></label>
            <input id="email" name="email" type="email" inputmode="email" value="<?= $val('email') ?>" autocomplete="email">
            <?= field_error($errors, 'email') ?>
        </div>
        <div class="field">
            <label for="sector">Sektör</label>
            <input id="sector" name="sector" list="sector-list" value="<?= $val('sector') ?>" placeholder="Örn. Kafe & Restoran">
            <datalist id="sector-list"><?php foreach ($sectors as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="field">
            <label for="city">Şehir</label>
            <input id="city" name="city" list="city-list" value="<?= $val('city') ?>">
            <datalist id="city-list"><?php foreach ($cities as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        </div>
        <fieldset class="field span-2<?= isset($errors['owner']) ? ' has-error' : '' ?>">
            <legend class="label">Kimin müşterisi?</legend>
            <div class="segmented">
                <?php foreach (OWNER_LABELS as $k => $label): ?>
                    <label><input type="radio" name="owner" value="<?= $k ?>"<?= old('owner', $c['owner']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
            <?= field_error($errors, 'owner') ?>
        </fieldset>
        <fieldset class="field<?= isset($errors['status']) ? ' has-error' : '' ?>">
            <legend class="label">Durum</legend>
            <div class="segmented">
                <?php foreach (CUSTOMER_STATUS_LABELS as $k => $label): ?>
                    <label><input type="radio" name="status" value="<?= $k ?>"<?= old('status', $c['status']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
            <?= field_error($errors, 'status') ?>
        </fieldset>
        <div class="field<?= isset($errors['registered_at']) ? ' has-error' : '' ?>">
            <label for="registered_at">Kayıt tarihi</label>
            <input id="registered_at" name="registered_at" type="date" value="<?= $val('registered_at') ?>">
            <?= field_error($errors, 'registered_at') ?>
        </div>
        <div class="field span-2">
            <label for="notes">Notlar</label>
            <textarea id="notes" name="notes" rows="4" placeholder="Müşteriyle ilgili genel bilgiler"><?= $val('notes') ?></textarea>
        </div>
    </div>
    </div>

    <?php if (!$customer): ?>
        <section class="card">
            <div class="card-head">
                <h2>Hizmet ve fiyat</h2>
                <span class="muted small">Opsiyonel</span>
            </div>
            <p class="muted small">Müşterinin aldığı hizmeti ve anlaştığınız tutarı yazın; ödeme tarihleri buna göre takip edilir. Sonradan da ekleyebilirsiniz.</p>
            <div class="form-grid">
                <?php require __DIR__ . '/includes/order-fields.php'; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="form-actions">
        <?php if ($customer): ?>
            <button class="btn danger" type="submit" name="action" value="delete" formnovalidate data-confirm="Bu müşteri ve tüm hizmet kayıtları silinecek. Emin misiniz?"><?= icon('trash') ?>Sil</button>
        <?php endif; ?>
        <span class="spacer"></span>
        <a class="btn ghost" href="<?= $customer ? 'musteri.php?id=' . $id : 'musteriler.php' ?>">Vazgeç</a>
        <button class="btn" type="submit" name="action" value="save"><?= icon('check') ?>Kaydet</button>
    </div>
</form>
<?php layout_end();
