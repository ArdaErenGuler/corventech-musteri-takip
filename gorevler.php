<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    verify_csrf();
    if (input('action') === 'toggle') {
        $t = q_one('SELECT id, status FROM tasks WHERE id = ?', [input_int('id') ?? 0]);
        if ($t) {
            $done = $t['status'] !== 'done';
            q('UPDATE tasks SET status = ?, completed_at = ? WHERE id = ?', [$done ? 'done' : 'todo', $done ? date('Y-m-d H:i:s') : null, $t['id']]);
            flash('success', $done ? 'Görev tamamlandı.' : 'Görev yeniden açıldı.');
        }
    }
    redirect(safe_return(input('return'), 'gorevler.php'));
}

$views = ['open' => 'Açık', 'mine' => 'Bana atanan', 'done' => 'Tamamlanan'];
$view = isset($views[input('v')]) ? input('v') : 'open';

$where = match ($view) {
    'mine' => "t.status <> 'done' AND t.assigned_to = " . (int) $user['id'],
    'done' => "t.status = 'done'",
    default => "t.status <> 'done'",
};
$order = $view === 'done' ? 't.completed_at DESC' : 't.due_date IS NULL, t.due_date, FIELD(t.priority, \'high\', \'normal\', \'low\')';
$tasks = q_all(TASKS_SELECT . " WHERE $where ORDER BY $order LIMIT 200");

$counts = q_one(
    "SELECT SUM(status <> 'done') AS open, SUM(status <> 'done' AND assigned_to = ?) AS mine, SUM(status = 'done') AS done FROM tasks",
    [$user['id']]
);

// Açık görevleri tarihe göre grupla
$groups = [];
if ($view !== 'done') {
    foreach ($tasks as $t) {
        $key = !$t['due_date'] ? 'none' : ($t['due_date'] < today() ? 'overdue' : ($t['due_date'] === today() ? 'today' : 'later'));
        $groups[$key][] = $t;
    }
    $groups = array_merge(array_intersect_key(['overdue' => [], 'today' => [], 'later' => [], 'none' => []], $groups), $groups);
}
$groupTitles = ['overdue' => 'Gecikmiş', 'today' => 'Bugün', 'later' => 'Yaklaşan', 'none' => 'Tarihsiz'];

layout_start('Görevler', 'tasks', ['fab' => ['href' => 'gorev-form.php', 'label' => 'Yeni görev']]);
?>
<nav class="chips" aria-label="Görev görünümü">
    <?php foreach ($views as $k => $label): ?>
        <a class="chip<?= $view === $k ? ' is-active' : '' ?>" href="gorevler.php?v=<?= $k ?>"><?= e($label) ?> <b><?= (int) ($counts[$k] ?? 0) ?></b></a>
    <?php endforeach; ?>
</nav>

<?php if (!$tasks): ?>
    <?= empty_state($view === 'done' ? 'Tamamlanan görev yok.' : 'Açık görev yok. Harika!', $view === 'done' ? null : 'gorev-form.php', 'Görev ekle') ?>
<?php elseif ($view === 'done'): ?>
    <div class="list"><?php foreach ($tasks as $t) echo task_item($t); ?></div>
<?php else: ?>
    <?php foreach ($groups as $key => $list): ?>
        <div class="section-head"><h2 class="<?= $key === 'overdue' ? 'text-critical' : '' ?>"><?= e($groupTitles[$key]) ?> <span class="muted small">· <?= count($list) ?></span></h2></div>
        <div class="list"><?php foreach ($list as $t) echo task_item($t); ?></div>
    <?php endforeach; ?>
<?php endif; ?>
<?php layout_end();
