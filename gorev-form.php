<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = input_int('id');
$task = $id ? q_one('SELECT * FROM tasks WHERE id = ?', [$id]) : null;
if ($id && !$task) {
    not_found();
}

$errors = [];

if (is_post()) {
    verify_csrf();

    if (input('action') === 'delete' && $task) {
        q('DELETE FROM tasks WHERE id = ?', [$id]);
        flash('success', 'Görev silindi.');
        redirect('gorevler.php');
    }

    $title = input('title');
    $customerId = input_int('customer_id');
    $assignee = input_int('assigned_to');
    $due = input('due_date') === '' ? null : input_date('due_date');
    $priority = input('priority');
    $status = input('status');

    if ($title === '') $errors['title'] = 'Görev başlığını yazın.';
    if (input('due_date') !== '' && !$due) $errors['due_date'] = 'Geçerli bir tarih seçin.';
    if (!isset(TASK_PRIORITY[$priority])) $priority = 'normal';
    if (!isset(TASK_STATUS[$status])) $status = 'todo';
    if ($customerId && !q_val('SELECT id FROM customers WHERE id = ?', [$customerId])) $customerId = null;
    if ($assignee && !q_val('SELECT id FROM users WHERE id = ?', [$assignee])) $assignee = null;

    if (!$errors) {
        $completedAt = $status === 'done' ? ($task['completed_at'] ?? date('Y-m-d H:i:s')) : null;
        $values = [$title, input('description') ?: null, $customerId, $assignee, $due, $priority, $status, $completedAt];
        if ($task) {
            q('UPDATE tasks SET title = ?, description = ?, customer_id = ?, assigned_to = ?, due_date = ?, priority = ?, status = ?, completed_at = ? WHERE id = ?', [...$values, $id]);
        } else {
            q('INSERT INTO tasks (title, description, customer_id, assigned_to, due_date, priority, status, completed_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $user['id']]);
        }
        flash('success', 'Görev kaydedildi.');
        redirect($customerId && !$task ? 'musteri.php?id=' . $customerId . '#gorevler' : 'gorevler.php');
    }
}

$t = $task ?? ['customer_id' => input_int('customer_id'), 'assigned_to' => $user['id'], 'priority' => 'normal', 'status' => 'todo'];
$val = fn($k) => e(old($k, $t[$k] ?? ''));
$customers = q_all("SELECT id, company_name, contact_name FROM customers ORDER BY status = 'passive', COALESCE(NULLIF(company_name, ''), contact_name)");
$users = q_all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');

layout_start($task ? 'Görevi düzenle' : 'Yeni görev', 'tasks', ['back' => 'gorevler.php', 'fab' => false]);
?>
<form method="post" class="card form" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field span-2<?= isset($errors['title']) ? ' has-error' : '' ?>">
            <label for="title">Başlık <span class="req">*</span></label>
            <input id="title" name="title" value="<?= $val('title') ?>" placeholder="Örn. Ekim içerik planını gönder" required>
            <?= field_error($errors, 'title') ?>
        </div>
        <div class="field span-2">
            <label for="description">Açıklama</label>
            <textarea id="description" name="description" rows="3"><?= $val('description') ?></textarea>
        </div>
        <div class="field">
            <label for="customer_id">Müşteri</label>
            <select id="customer_id" name="customer_id">
                <option value="">— Müşteri yok —</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) old('customer_id', $t['customer_id'] ?? '') === (int) $c['id'] ? ' selected' : '' ?>><?= e(customer_display_name($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="assigned_to">Sorumlu</label>
            <select id="assigned_to" name="assigned_to">
                <option value="">— Herkes —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>"<?= (int) old('assigned_to', $t['assigned_to'] ?? '') === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field<?= isset($errors['due_date']) ? ' has-error' : '' ?>">
            <label for="due_date">Son tarih</label>
            <input id="due_date" name="due_date" type="date" value="<?= $val('due_date') ?>">
            <?= field_error($errors, 'due_date') ?>
        </div>
        <fieldset class="field">
            <legend class="label">Öncelik</legend>
            <div class="segmented">
                <?php foreach (TASK_PRIORITY as $k => [$label]): ?>
                    <label><input type="radio" name="priority" value="<?= $k ?>"<?= old('priority', $t['priority']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <fieldset class="field span-2">
            <legend class="label">Durum</legend>
            <div class="segmented">
                <?php foreach (TASK_STATUS as $k => $label): ?>
                    <label><input type="radio" name="status" value="<?= $k ?>"<?= old('status', $t['status']) === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
    </div>
    <div class="form-actions">
        <?php if ($task): ?>
            <button class="btn danger" type="submit" name="action" value="delete" formnovalidate data-confirm="Görev silinsin mi?"><?= icon('trash') ?>Sil</button>
        <?php endif; ?>
        <span class="spacer"></span>
        <a class="btn ghost" href="gorevler.php">Vazgeç</a>
        <button class="btn" type="submit" name="action" value="save"><?= icon('check') ?>Kaydet</button>
    </div>
</form>
<?php layout_end();
