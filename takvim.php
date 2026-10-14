<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'add') {
        $customerId = input_int('customer_id');
        $date = input_date('date');
        if (!$customerId || !$date || !q_val('SELECT id FROM customers WHERE id = ?', [$customerId])) {
            flash('error', 'Müşteri seçin.');
        } elseif (q_val('SELECT id FROM posts WHERE customer_id = ? AND scheduled_date = ?', [$customerId, $date])) {
            flash('error', 'Bu müşteri o güne zaten ekli.');
        } else {
            q("INSERT INTO posts (customer_id, title, scheduled_date, created_by) VALUES (?, 'Paylaşım', ?, ?)", [$customerId, $date, $user['id']]);
            flash('success', 'Takvime eklendi.');
        }
        redirect('takvim.php?ay=' . substr((string) $date, 0, 7) . '&gun=' . $date);
    }

    $p = q_one('SELECT id, status FROM posts WHERE id = ?', [input_int('id') ?? 0]);
    if ($p && $action === 'toggle') {
        $done = $p['status'] !== 'published';
        q('UPDATE posts SET status = ?, published_at = ? WHERE id = ?', [$done ? 'published' : 'planned', $done ? date('Y-m-d H:i:s') : null, $p['id']]);
        flash('success', $done ? 'Paylaşıldı olarak işaretlendi.' : 'Geri alındı.');
    } elseif ($p && $action === 'delete') {
        q('DELETE FROM posts WHERE id = ?', [$p['id']]);
        flash('success', 'Takvimden silindi.');
    }
    redirect(safe_return(input('return'), 'takvim.php'));
}

// Gösterilen ay ve seçili gün
$month = DateTimeImmutable::createFromFormat('!Y-m', input('ay')) ?: new DateTimeImmutable(date('Y-m-01'));
$monthStart = $month->format('Y-m-01');
$monthEnd = $month->format('Y-m-t');
$selected = input_date('gun') ?? (today() >= $monthStart && today() <= $monthEnd ? today() : $monthStart);

// Izgara: ayın ilk haftasının pazartesisinden son haftasının pazarına
$gridStart = (new DateTimeImmutable($monthStart))->modify('-' . ((int) date('N', strtotime($monthStart)) - 1) . ' days');
$gridEnd = (new DateTimeImmutable($monthEnd))->modify('+' . (7 - (int) date('N', strtotime($monthEnd))) . ' days');

$byDay = [];
foreach (q_all(POSTS_SELECT . ' WHERE p.scheduled_date BETWEEN ? AND ? ORDER BY customer_name', [$gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d')]) as $p) {
    $byDay[$p['scheduled_date']][] = $p;
}

$customers = q_all("SELECT id, company_name, contact_name FROM customers WHERE status <> 'passive' ORDER BY COALESCE(NULLIF(company_name, ''), contact_name)");
$prev = $month->modify('-1 month')->format('Y-m');
$next = $month->modify('+1 month')->format('Y-m');

layout_start('Sosyal Medya Takvimi', 'calendar', ['fab' => false]);
?>
<div class="cal-toolbar">
    <div class="cal-nav">
        <a class="icon-btn soft" href="takvim.php?ay=<?= $prev ?>" aria-label="Önceki ay"><?= icon('chevron-left') ?></a>
        <h2 class="cal-title"><?= TR_MONTHS[(int) $month->format('n')] ?> <span><?= $month->format('Y') ?></span></h2>
        <a class="icon-btn soft" href="takvim.php?ay=<?= $next ?>" aria-label="Sonraki ay"><?= icon('chevron-right') ?></a>
        <a class="btn sm ghost" href="takvim.php?ay=<?= date('Y-m') ?>&gun=<?= today() ?>">Bugün</a>
    </div>
    <button class="btn sm soft" type="button" data-enable-notifications hidden><?= icon('alert') ?>Bildirimleri aç</button>
</div>

<div class="cal-layout">
    <section class="cal" aria-label="<?= TR_MONTHS[(int) $month->format('n')] ?> takvimi">
        <div class="cal-weekdays" aria-hidden="true">
            <?php foreach (TR_WEEKDAYS_SHORT as $w): ?><span><?= $w ?></span><?php endforeach; ?>
        </div>
        <div class="cal-grid" data-calendar>
            <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
                $key = $d->format('Y-m-d');
                $posts = $byDay[$key] ?? [];
                $cls = ['cal-day'];
                if ($key < $monthStart || $key > $monthEnd) $cls[] = 'is-out';
                if ($key === today()) $cls[] = 'is-today';
                if ($key === $selected) $cls[] = 'is-selected';
                if ((int) $d->format('N') >= 6) $cls[] = 'is-weekend';
                if (array_filter($posts, fn($p) => $p['status'] !== 'published' && $key < today())) $cls[] = 'has-late'; ?>
                <a class="<?= implode(' ', $cls) ?>" href="takvim.php?ay=<?= $d->format('Y-m') ?>&gun=<?= $key ?>" data-day="<?= $key ?>"
                   aria-label="<?= e(date_long_tr($key) . ($posts ? ', ' . count($posts) . ' müşteri' : '')) ?>"<?= $key === $selected ? ' aria-current="date"' : '' ?>>
                    <span class="cal-num"><?= (int) $d->format('j') ?></span>
                    <?php if ($posts): ?>
                        <span class="cal-dots" aria-hidden="true">
                            <?php foreach (array_slice($posts, 0, 4) as $p): ?><i class="<?= $p['status'] === 'published' ? 'is-done' : '' ?>"></i><?php endforeach; ?>
                        </span>
                        <span class="cal-chips" aria-hidden="true">
                            <?php foreach (array_slice($posts, 0, 3) as $p): ?>
                                <span class="cal-chip<?= $p['status'] === 'published' ? ' is-done' : '' ?>"><?= e($p['customer_name']) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($posts) > 3): ?><span class="cal-more">+<?= count($posts) - 3 ?> daha</span><?php endif; ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endfor; ?>
        </div>
    </section>

    <div class="cal-side" id="gun">
        <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
            $key = $d->format('Y-m-d');
            $posts = $byDay[$key] ?? []; ?>
            <section class="cal-agenda card" data-agenda="<?= $key ?>"<?= $key === $selected ? '' : ' hidden' ?>>
                <h2><?= e(date_long_tr($key)) ?></h2>
                <p class="muted small" style="margin:2px 0 12px"><?= $key === today() ? 'Bugün · ' : '' ?><?= $posts ? count($posts) . ' müşteriye paylaşım' : 'Paylaşım yok' ?></p>

                <?php if ($posts): ?>
                    <div class="list mb"><?php foreach ($posts as $p) echo post_item($p); ?></div>
                <?php endif; ?>

                <form method="post" class="quick-add">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="date" value="<?= $key ?>">
                    <select name="customer_id" required aria-label="Müşteri seçin">
                        <option value="">Müşteri seçin…</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= e(customer_display_name($c)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn" type="submit"><?= icon('plus') ?>Ekle</button>
                </form>
            </section>
        <?php endfor; ?>
    </div>
</div>
<?php layout_end();
