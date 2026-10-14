<?php
/**
 * Sosyal medya paylaşım takvimi: sabitler ve ortak bileşenler.
 */

/** Platform adı ve tanıma rengi (yalnızca küçük işaretlerde kullanılır; metin her zaman nötr renkte) */
const PLATFORMS = [
    'instagram' => ['Instagram', '#e1306c'],
    'facebook'  => ['Facebook', '#1877f2'],
    'tiktok'    => ['TikTok', '#00b8b0'],
    'linkedin'  => ['LinkedIn', '#0a66c2'],
    'youtube'   => ['YouTube', '#e62117'],
    'x'         => ['X', '#8a83a0'],
];

const POST_TYPES = [
    'post'     => 'Gönderi',
    'reels'    => 'Reels',
    'story'    => 'Hikaye',
    'carousel' => 'Kaydırmalı',
    'video'    => 'Video',
];

const POST_STATUS = [
    'planned'   => ['Planlandı', 'neutral', 'calendar'],
    'ready'     => ['Hazır', 'accent', 'check-square'],
    'published' => ['Paylaşıldı', 'good', 'check'],
];

const TR_WEEKDAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
const TR_WEEKDAYS_SHORT = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];

const POSTS_SELECT = "
    SELECT p.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS customer_name, u.name AS assignee_name
    FROM posts p
    LEFT JOIN customers c ON c.id = p.customer_id
    LEFT JOIN users u ON u.id = p.assigned_to
";

/** "20 Ekim Salı" */
function date_long_tr(string $date): string
{
    $ts = strtotime($date);
    return (int) date('j', $ts) . ' ' . TR_MONTHS[(int) date('n', $ts)] . ' ' . TR_WEEKDAYS[(int) date('N', $ts) - 1];
}

function post_platforms(array $p): array
{
    return array_values(array_filter(explode(',', (string) $p['platforms']), fn($k) => isset(PLATFORMS[$k])));
}

function post_color(array $p): string
{
    $first = post_platforms($p)[0] ?? 'x';
    return PLATFORMS[$first][1];
}

/** Paylaşım durumu; tarihi geçmiş ve paylaşılmamışsa "Gecikti" */
function post_state_badge(array $p): string
{
    if ($p['status'] !== 'published' && $p['scheduled_date'] < today()) {
        return badge('Gecikti', 'critical', 'alert');
    }
    [$label, $tone, $ic] = POST_STATUS[$p['status']];
    return badge($label, $tone, $ic);
}

function platform_chips(array $p): string
{
    $out = '';
    foreach (post_platforms($p) as $k) {
        $out .= '<span class="pf"><i style="background:' . PLATFORMS[$k][1] . '"></i>' . e(PLATFORMS[$k][0]) . '</span>';
    }
    return $out;
}

/** Bugün ve gecikmiş, henüz paylaşılmamış gönderiler */
function due_posts(): array
{
    return q_all(POSTS_SELECT . " WHERE p.status <> 'published' AND p.scheduled_date <= CURDATE() ORDER BY p.scheduled_date, p.scheduled_time IS NULL, p.scheduled_time");
}

/** Paylaşım kartı */
function post_item(array $p, bool $showDate = false): string
{
    $done = $p['status'] === 'published';
    $return = $_SERVER['REQUEST_URI'] ?? 'takvim.php';
    ob_start(); ?>
    <div class="item post-item<?= $done ? ' is-done' : '' ?>" style="--pf:<?= e(post_color($p)) ?>">
        <div class="post-time">
            <?php if ($showDate): ?><b><?= (int) date('j', strtotime($p['scheduled_date'])) ?></b><small><?= TR_MONTHS_SHORT[(int) date('n', strtotime($p['scheduled_date']))] ?></small>
            <?php else: ?><b><?= $p['scheduled_time'] ? substr($p['scheduled_time'], 0, 5) : '—' ?></b><small><?= $p['scheduled_time'] ? '' : 'gün içi' ?></small><?php endif; ?>
        </div>
        <div class="item-main">
            <a class="item-title stretched" href="paylasim-form.php?id=<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
            <div class="item-sub">
                <?php if ($p['customer_name']): ?><span><?= icon('briefcase') ?><?= e($p['customer_name']) ?></span><?php endif; ?>
                <span><?= e(POST_TYPES[$p['post_type']] ?? '') ?></span>
                <?php if ($showDate && $p['scheduled_time']): ?><span><?= icon('clock') ?><?= substr($p['scheduled_time'], 0, 5) ?></span><?php endif; ?>
            </div>
            <div class="item-sub pf-row"><?= platform_chips($p) ?><?= post_state_badge($p) ?></div>
        </div>
        <div class="item-actions">
            <?php if ($p['caption']): ?>
                <button class="icon-btn" type="button" data-copy="<?= e($p['caption']) ?>" aria-label="Paylaşım metnini kopyala" title="Metni kopyala"><?= icon('note') ?></button>
            <?php endif; ?>
            <form method="post" action="takvim.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="return" value="<?= e($return) ?>">
                <button class="icon-btn soft" type="submit" aria-label="<?= $done ? 'Paylaşılmadı olarak işaretle' : 'Paylaşıldı olarak işaretle' ?>" title="<?= $done ? 'Geri al' : 'Paylaşıldı' ?>">
                    <?= icon($done ? 'check' : 'circle', $done ? 'text-good' : 'dim') ?>
                </button>
            </form>
        </div>
    </div>
    <?php return ob_get_clean();
}
