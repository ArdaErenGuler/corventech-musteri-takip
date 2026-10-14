<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = input_int('id');
$post = $id ? q_one('SELECT * FROM posts WHERE id = ?', [$id]) : null;
if ($id && !$post) {
    not_found();
}

$errors = [];

if (is_post()) {
    verify_csrf();

    if (input('action') === 'delete' && $post) {
        q('DELETE FROM posts WHERE id = ?', [$id]);
        flash('success', 'Paylaşım silindi.');
        redirect('takvim.php?ay=' . substr($post['scheduled_date'], 0, 7) . '&gun=' . $post['scheduled_date']);
    }

    $title = input('title');
    $customerId = input_int('customer_id');
    $platforms = array_values(array_intersect(array_keys(PLATFORMS), (array) ($_POST['platforms'] ?? [])));
    $type = input('post_type');
    $date = input_date('scheduled_date');
    $time = input('scheduled_time');
    $status = input('status');
    $assignee = input_int('assigned_to');
    $repeat = input('repeat');
    $repeatCount = max(1, min(12, input_int('repeat_count') ?? 1));

    if ($title === '') $errors['title'] = 'Paylaşım için kısa bir başlık yazın.';
    if (!$platforms) $errors['platforms'] = 'En az bir platform seçin.';
    if (!isset(POST_TYPES[$type])) $type = 'post';
    if (!$date) $errors['scheduled_date'] = 'Tarih seçin.';
    if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $errors['scheduled_time'] = 'Saat geçersiz.';
    if (!isset(POST_STATUS[$status])) $status = 'planned';
    if ($customerId && !q_val('SELECT id FROM customers WHERE id = ?', [$customerId])) $customerId = null;
    if ($assignee && !q_val('SELECT id FROM users WHERE id = ?', [$assignee])) $assignee = null;

    if (!$errors) {
        $publishedAt = $status === 'published' ? ($post['published_at'] ?? date('Y-m-d H:i:s')) : null;
        $values = [$customerId, $title, implode(',', $platforms), $type, $time !== '' ? $time : null, input('caption') ?: null, input('notes') ?: null, $status, $assignee, $publishedAt];

        if ($post) {
            q('UPDATE posts SET customer_id = ?, title = ?, platforms = ?, post_type = ?, scheduled_time = ?, caption = ?, notes = ?, status = ?, assigned_to = ?, published_at = ?, scheduled_date = ? WHERE id = ?', [...$values, $date, $id]);
            flash('success', 'Paylaşım güncellendi.');
        } else {
            // Tekrarlayan paylaşım: her hafta / her ay aynı gün
            $dates = [$date];
            if (in_array($repeat, ['weekly', 'monthly'], true)) {
                for ($i = 1; $i < $repeatCount; $i++) {
                    $dates[] = $repeat === 'weekly' ? date('Y-m-d', strtotime("$date +$i week")) : add_months_clamped($date, $i);
                }
            }
            $stmt = db()->prepare('INSERT INTO posts (customer_id, title, platforms, post_type, scheduled_time, caption, notes, status, assigned_to, published_at, scheduled_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($dates as $i => $d) {
                $stmt->execute([...array_slice($values, 0, 7), $i === 0 ? $status : 'planned', $assignee, $i === 0 ? $publishedAt : null, $d, $user['id']]);
            }
            flash('success', count($dates) > 1 ? count($dates) . ' paylaşım takvime eklendi.' : 'Paylaşım takvime eklendi.');
        }
        redirect('takvim.php?ay=' . substr($date, 0, 7) . '&gun=' . $date);
    }
}

$p = $post ?? [
    'customer_id'    => input_int('musteri'),
    'platforms'      => 'instagram',
    'post_type'      => 'post',
    'scheduled_date' => input_date('tarih') ?? today(),
    'status'         => 'planned',
    'assigned_to'    => $user['id'],
];
$val = fn($k) => e(old($k, $p[$k] ?? ''));
$selectedPlatforms = is_post() ? (array) ($_POST['platforms'] ?? []) : explode(',', (string) $p['platforms']);
$customers = q_all("SELECT id, company_name, contact_name FROM customers WHERE status <> 'passive' OR id = ? ORDER BY COALESCE(NULLIF(company_name, ''), contact_name)", [$p['customer_id'] ?? 0]);
$users = q_all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');
$back = 'takvim.php?ay=' . substr($p['scheduled_date'], 0, 7) . '&gun=' . $p['scheduled_date'];

layout_start($post ? 'Paylaşımı düzenle' : 'Yeni paylaşım', 'calendar', ['back' => $back, 'fab' => false]);
?>
<form method="post" class="card form" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field">
            <label for="customer_id">Müşteri</label>
            <select id="customer_id" name="customer_id">
                <option value="">— Corventech / müşterisiz —</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) old('customer_id', $p['customer_id'] ?? '') === (int) $c['id'] ? ' selected' : '' ?>><?= e(customer_display_name($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field<?= isset($errors['title']) ? ' has-error' : '' ?>">
            <label for="title">Başlık <span class="req">*</span></label>
            <input id="title" name="title" value="<?= $val('title') ?>" placeholder="Örn. Hafta sonu kampanyası">
            <?= field_error($errors, 'title') ?>
        </div>

        <fieldset class="field span-2<?= isset($errors['platforms']) ? ' has-error' : '' ?>">
            <legend class="label">Platform</legend>
            <div class="pick-chips">
                <?php foreach (PLATFORMS as $k => [$label, $color]): ?>
                    <label class="pick" style="--pf:<?= $color ?>"><input type="checkbox" name="platforms[]" value="<?= $k ?>"<?= in_array($k, $selectedPlatforms, true) ? ' checked' : '' ?>><i></i><?= e($label) ?></label>
                <?php endforeach; ?>
            </div>
            <?= field_error($errors, 'platforms') ?>
        </fieldset>

        <fieldset class="field span-2">
            <legend class="label">İçerik türü</legend>
            <div class="segmented">
                <?php foreach (POST_TYPES as $k => $label): ?>
                    <label><input type="radio" name="post_type" value="<?= $k ?>"<?= old('post_type', $p['post_type']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="field<?= isset($errors['scheduled_date']) ? ' has-error' : '' ?>">
            <label for="scheduled_date">Tarih <span class="req">*</span></label>
            <input id="scheduled_date" name="scheduled_date" type="date" value="<?= $val('scheduled_date') ?>">
            <?= field_error($errors, 'scheduled_date') ?>
        </div>
        <div class="field<?= isset($errors['scheduled_time']) ? ' has-error' : '' ?>">
            <label for="scheduled_time">Saat <span class="dim">(opsiyonel)</span></label>
            <input id="scheduled_time" name="scheduled_time" type="time" value="<?= e(old('scheduled_time', isset($p['scheduled_time']) ? substr((string) $p['scheduled_time'], 0, 5) : '')) ?>">
            <?= field_error($errors, 'scheduled_time') ?>
        </div>

        <?php if (!$post): ?>
            <fieldset class="field">
                <legend class="label">Tekrarla</legend>
                <div class="segmented">
                    <?php foreach (['none' => 'Hayır', 'weekly' => 'Her hafta', 'monthly' => 'Her ay'] as $k => $label): ?>
                        <label><input type="radio" name="repeat" value="<?= $k ?>"<?= old('repeat', 'none') === $k ? ' checked' : '' ?>><span><?= $label ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="field">
                <label for="repeat_count">Kaç kez?</label>
                <input id="repeat_count" name="repeat_count" type="number" inputmode="numeric" min="1" max="12" value="<?= e(old('repeat_count', '4')) ?>">
                <span class="hint">Tekrar seçiliyse ilk tarih dahil (en fazla 12).</span>
            </div>
        <?php endif; ?>

        <div class="field span-2">
            <label for="caption">Paylaşım metni</label>
            <textarea id="caption" name="caption" rows="5" placeholder="Gönderi açıklaması, hashtag'ler…"><?= $val('caption') ?></textarea>
            <span class="hint">Takvimde tek dokunuşla kopyalanabilir.</span>
        </div>

        <fieldset class="field">
            <legend class="label">Durum</legend>
            <div class="segmented">
                <?php foreach (POST_STATUS as $k => [$label]): ?>
                    <label><input type="radio" name="status" value="<?= $k ?>"<?= old('status', $p['status']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="field">
            <label for="assigned_to">Sorumlu</label>
            <select id="assigned_to" name="assigned_to">
                <option value="">— Herkes —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"<?= (int) old('assigned_to', $p['assigned_to'] ?? '') === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field span-2">
            <label for="notes">Not <span class="dim">(opsiyonel)</span></label>
            <textarea id="notes" name="notes" rows="2" placeholder="Görsel hazır mı, onay bekleniyor mu…"><?= $val('notes') ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <?php if ($post): ?>
            <button class="btn danger" type="submit" name="action" value="delete" formnovalidate data-confirm="Paylaşım silinsin mi?"><?= icon('trash') ?>Sil</button>
        <?php endif; ?>
        <span class="spacer"></span>
        <a class="btn ghost" href="<?= e($back) ?>">Vazgeç</a>
        <button class="btn" type="submit" name="action" value="save"><?= icon('check') ?>Kaydet</button>
    </div>
</form>
<?php layout_end();
