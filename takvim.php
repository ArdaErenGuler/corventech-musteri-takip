<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

// Paylaşıldı / geri al
if (is_post()) {
    verify_csrf();
    if (input('action') === 'toggle') {
        $p = q_one('SELECT id, status FROM posts WHERE id = ?', [input_int('id') ?? 0]);
        if ($p) {
            $done = $p['status'] !== 'published';
            q('UPDATE posts SET status = ?, published_at = ? WHERE id = ?', [$done ? 'published' : 'planned', $done ? date('Y-m-d H:i:s') : null, $p['id']]);
            flash('success', $done ? 'Paylaşıldı olarak işaretlendi.' : 'Paylaşım yeniden planlandı.');
        }
    }
    redirect(safe_return(input('return'), 'takvim.php'));
}

// Gösterilen ay ve seçili gün
$month = DateTimeImmutable::createFromFormat('!Y-m', input('ay')) ?: new DateTimeImmutable(date('Y-m-01'));
$monthStart = $month->format('Y-m-01');
$monthEnd = $month->format('Y-m-t');
$selected = input_date('gun') ?? (today() >= $monthStart && today() <= $monthEnd ? today() : $monthStart);
$customerFilter = input_int('musteri');

// Takvim ızgarası: ayın ilk gününün haftasının pazartesisinden, son gününün haftasının pazarına
$gridStart = (new DateTimeImmutable($monthStart))->modify('-' . ((int) date('N', strtotime($monthStart)) - 1) . ' days');
$gridEnd = (new DateTimeImmutable($monthEnd))->modify('+' . (7 - (int) date('N', strtotime($monthEnd))) . ' days');

$where = 'p.scheduled_date BETWEEN ? AND ?';
$params = [$gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d')];
if ($customerFilter) {
    $where .= ' AND p.customer_id = ?';
    $params[] = $customerFilter;
}
$byDay = [];
foreach (q_all(POSTS_SELECT . " WHERE $where ORDER BY p.scheduled_date, p.scheduled_time IS NULL, p.scheduled_time, p.id", $params) as $p) {
    $byDay[$p['scheduled_date']][] = $p;
}

// Ay özeti
$monthPosts = array_merge(...array_values(array_filter($byDay, fn($k) => $k >= $monthStart && $k <= $monthEnd, ARRAY_FILTER_USE_KEY)) ?: [[]]);
$published = count(array_filter($monthPosts, fn($p) => $p['status'] === 'published'));
$overdue = count(array_filter($monthPosts, fn($p) => $p['status'] !== 'published' && $p['scheduled_date'] < today()));

$prev = $month->modify('-1 month')->format('Y-m');
$next = $month->modify('+1 month')->format('Y-m');
$customers = q_all("SELECT id, company_name, contact_name FROM customers WHERE status <> 'passive' ORDER BY COALESCE(NULLIF(company_name, ''), contact_name)");
$q = fn(array $extra) => 'takvim.php?' . http_build_query(array_filter(['ay' => $month->format('Y-m'), 'musteri' => $customerFilter] + $extra));

layout_start('Sosyal Medya Takvimi', 'calendar', [
    'fab' => ['href' => 'paylasim-form.php?tarih=' . $selected, 'label' => 'Paylaşım'],
]);
?>
<div class="cal-toolbar">
    <div class="cal-nav">
        <a class="icon-btn soft" href="<?= e($q(['ay' => $prev])) ?>" aria-label="Önceki ay"><?= icon('chevron-left') ?></a>
        <h2 class="cal-title"><?= TR_MONTHS[(int) $month->format('n')] ?> <span><?= $month->format('Y') ?></span></h2>
        <a class="icon-btn soft" href="<?= e($q(['ay' => $next])) ?>" aria-label="Sonraki ay"><?= icon('chevron-right') ?></a>
        <a class="btn sm ghost" href="<?= e($q(['ay' => date('Y-m'), 'gun' => today()])) ?>">Bugün</a>
    </div>
    <form method="get" class="cal-filter">
        <input type="hidden" name="ay" value="<?= e($month->format('Y-m')) ?>">
        <select name="musteri" data-autosubmit aria-label="Müşteriye göre filtrele">
            <option value="">Tüm müşteriler</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= $customerFilter === (int) $c['id'] ? ' selected' : '' ?>><?= e(customer_display_name($c)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="cal-summary">
    <span><b><?= count($monthPosts) ?></b> paylaşım</span>
    <span><i class="dot tone-good-bg"></i><b><?= $published ?></b> paylaşıldı</span>
    <span><i class="dot"></i><b><?= count($monthPosts) - $published ?></b> bekliyor</span>
    <?php if ($overdue): ?><span class="text-critical"><?= icon('alert') ?><b><?= $overdue ?></b> gecikti</span><?php endif; ?>
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
                $late = array_filter($posts, fn($p) => $p['status'] !== 'published' && $key < today());
                $cls = ['cal-day'];
                if ($key < $monthStart || $key > $monthEnd) $cls[] = 'is-out';
                if ($key === today()) $cls[] = 'is-today';
                if ($key === $selected) $cls[] = 'is-selected';
                if ((int) $d->format('N') >= 6) $cls[] = 'is-weekend';
                if ($late) $cls[] = 'has-late';
                $label = date_long_tr($key) . ($posts ? ', ' . count($posts) . ' paylaşım' : ''); ?>
                <a class="<?= implode(' ', $cls) ?>" href="<?= e($q(['ay' => $d->format('Y-m'), 'gun' => $key])) ?>" data-day="<?= $key ?>" aria-label="<?= e($label) ?>"<?= $key === $selected ? ' aria-current="date"' : '' ?>>
                    <span class="cal-num"><?= (int) $d->format('j') ?></span>
                    <?php if ($posts): ?>
                        <span class="cal-dots" aria-hidden="true">
                            <?php foreach (array_slice($posts, 0, 4) as $p): ?><i class="<?= $p['status'] === 'published' ? 'is-done' : '' ?>" style="background:<?= e(post_color($p)) ?>"></i><?php endforeach; ?>
                        </span>
                        <span class="cal-chips" aria-hidden="true">
                            <?php foreach (array_slice($posts, 0, 3) as $p): ?>
                                <span class="cal-chip<?= $p['status'] === 'published' ? ' is-done' : '' ?>" style="--pf:<?= e(post_color($p)) ?>"><?= $p['scheduled_time'] ? substr($p['scheduled_time'], 0, 5) . ' ' : '' ?><?= e($p['customer_name'] ?: $p['title']) ?></span>
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
                <div class="card-head">
                    <div>
                        <h2><?= e(date_long_tr($key)) ?></h2>
                        <p class="muted small mt-0" style="margin:2px 0 0"><?= $key === today() ? 'Bugün · ' : '' ?><?= $posts ? count($posts) . ' paylaşım' : 'Paylaşım yok' ?></p>
                    </div>
                    <a class="btn sm" href="paylasim-form.php?tarih=<?= $key ?><?= $customerFilter ? '&musteri=' . $customerFilter : '' ?>"><?= icon('plus') ?>Ekle</a>
                </div>
                <?php if ($posts): ?>
                    <div class="list"><?php foreach ($posts as $p) echo post_item($p); ?></div>
                <?php else: ?>
                    <div class="empty"><p>Bu güne planlanmış paylaşım yok.</p></div>
                <?php endif; ?>
            </section>
        <?php endfor; ?>
    </div>
</div>
<?php layout_end();
