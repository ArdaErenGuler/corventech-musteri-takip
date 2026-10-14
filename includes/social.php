<?php
/**
 * Sosyal medya takvimi: güne müşteri eklenir, o gün gelince hatırlatılır.
 * (posts tablosundaki platform/tür/saat alanları şimdilik kullanılmıyor.)
 */

const TR_WEEKDAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
const TR_WEEKDAYS_SHORT = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];

const POSTS_SELECT = "
    SELECT p.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name, p.title) AS customer_name
    FROM posts p
    LEFT JOIN customers c ON c.id = p.customer_id
";

/** "20 Ekim Salı" */
function date_long_tr(string $date): string
{
    $ts = strtotime($date);
    return (int) date('j', $ts) . ' ' . TR_MONTHS[(int) date('n', $ts)] . ' ' . TR_WEEKDAYS[(int) date('N', $ts) - 1];
}

/** Bugün ve gecikmiş, henüz paylaşılmamış gönderiler */
function due_posts(): array
{
    return q_all(POSTS_SELECT . " WHERE p.status <> 'published' AND p.scheduled_date <= CURDATE() ORDER BY p.scheduled_date, customer_name");
}

/** Takvimdeki satır: müşteri adı, paylaşıldı işareti, sil */
function post_item(array $p, bool $showDate = false): string
{
    $done = $p['status'] === 'published';
    $late = !$done && $p['scheduled_date'] < today();
    $return = $_SERVER['REQUEST_URI'] ?? 'takvim.php';
    ob_start(); ?>
    <div class="item post-item<?= $done ? ' is-done' : '' ?>">
        <form method="post" action="takvim.php" class="item-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="return" value="<?= e($return) ?>">
            <button class="icon-btn soft" type="submit" aria-label="<?= $done ? 'Geri al' : 'Paylaşıldı olarak işaretle' ?>" title="<?= $done ? 'Geri al' : 'Paylaşıldı' ?>">
                <?= icon($done ? 'check' : 'circle', $done ? 'text-good' : 'dim') ?>
            </button>
        </form>
        <div class="item-main">
            <?php if ($p['customer_id']): ?>
                <a class="item-title" href="musteri.php?id=<?= (int) $p['customer_id'] ?>"><?= e($p['customer_name']) ?></a>
            <?php else: ?>
                <span class="item-title"><?= e($p['customer_name']) ?></span>
            <?php endif; ?>
            <div class="item-sub">
                <?php if ($showDate): ?><span class="<?= $late ? 'text-critical' : '' ?>"><?= icon('calendar') ?><?= e(date_long_tr($p['scheduled_date'])) ?></span><?php endif; ?>
                <?php if ($done): ?><span class="text-good">Paylaşıldı</span><?php elseif ($late): ?><span class="text-critical">Gecikti</span><?php else: ?><span>Paylaşılacak</span><?php endif; ?>
            </div>
        </div>
        <form method="post" action="takvim.php" class="item-actions" data-confirm="Bu paylaşım takvimden silinsin mi?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="return" value="<?= e($return) ?>">
            <button class="icon-btn" type="submit" aria-label="Sil"><?= icon('trash', 'dim') ?></button>
        </form>
    </div>
    <?php return ob_get_clean();
}
