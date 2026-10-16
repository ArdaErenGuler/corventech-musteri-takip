<?php
/**
 * Yedekleme: tek butonla ZIP iner; içinde veritabanı (SQL, geri yükleme için)
 * ve kayıtların Excel'de açılabilen kopyası bulunur.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    verify_csrf();
    @set_time_limit(120);
    $zip = backup_create_zip($user);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="corventech-yedek-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . strlen($zip));
    header('Cache-Control: no-store');
    echo $zip;
    exit;
}

$stats = q_one(
    'SELECT (SELECT COUNT(*) FROM customers) AS customers, (SELECT COUNT(*) FROM orders) AS orders,
            (SELECT COUNT(*) FROM payments) AS payments, (SELECT COUNT(*) FROM transactions) AS txns'
);
$days = days_since_backup();
$due = backup_due();

layout_start('Yedekleme', 'backup', ['fab' => false]);
?>
<section class="card form backup-card">
    <div class="row" style="gap:14px">
        <span class="avatar lg"><?= icon('download') ?></span>
        <div>
            <h2>Yedekleme</h2>
            <p class="muted small" style="margin:2px 0 0">Tüm kayıtlarınızı tek bir dosya olarak indirin.</p>
        </div>
    </div>

    <?php if ($days === null): ?>
        <div class="callout is-warning"><?= icon('alert') ?><div><b>Henüz hiç yedek alınmadı.</b></div></div>
    <?php elseif ($due): ?>
        <div class="callout is-warning"><?= icon('alert') ?><div>Son yedek <b><?= $days ?> gün önce</b> alındı (<?= date_tr(last_backup_at()) ?>). Yeni bir yedek almanız önerilir.</div></div>
    <?php else: ?>
        <div class="callout is-good"><?= icon('check') ?><div>Son yedek: <b><?= datetime_tr(last_backup_at()) ?></b> (<?= $days === 0 ? 'bugün' : $days . ' gün önce' ?><?= setting('last_backup_by') ? ' · ' . e(setting('last_backup_by')) : '' ?>)</div></div>
    <?php endif; ?>

    <div>
        <div class="label">Yedekte neler var</div>
        <div class="mini-stats">
            <div><b><?= (int) $stats['customers'] ?></b>müşteri</div>
            <div><b><?= (int) $stats['orders'] ?></b>iş</div>
            <div><b><?= (int) $stats['payments'] ?></b>ödeme</div>
            <div><b><?= (int) $stats['txns'] ?></b>gelir/gider</div>
        </div>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <button class="btn block backup-btn" type="submit"><?= icon('download') ?>Yedek al</button>
    </form>

    <div class="muted small">
        <p class="mt-0"><code>corventech-yedek-<?= date('Y-m-d') ?>.zip</code> iner. İçinde:</p>
        <ul class="tight">
            <li><b>veritabani-(tarih).sql</b>: asıl yedek; panele geri yüklemek ya da başka bir sunucuya taşımak için.</li>
            <li><b>excel</b> klasörü: müşteriler, işler, ödemeler, gelir/gider ve takvim Excel'de açılabilir tablolar olarak; okumak için.</li>
        </ul>
        <ul class="tight">
            <li>Haftada bir yedek almanız önerilir; <?= BACKUP_REMIND_DAYS ?> gün geçince panelde ve menüde hatırlatma çıkar.</li>
            <li>İndirilen dosyayı bilgisayarınızda ve ayrıca Google Drive gibi bir yerde saklayın.</li>
            <li>Yedekte müşteri bilgileri bulunur; dosyayı kimseyle paylaşmayın.</li>
        </ul>
        <details>
            <summary>Yedekten geri yükleme / taşıma nasıl yapılır?</summary>
            <ol class="tight">
                <li>ZIP dosyasını açın.</li>
                <li>Boş bir veritabanı oluşturup <code>veritabani-(tarih).sql</code> dosyasını phpMyAdmin → İçe Aktar ile yükleyin.</li>
                <li>Paneli bu veritabanına bağlayıp açın; eksik güncellemeler kendiliğinden uygulanır.</li>
            </ol>
        </details>
    </div>
</section>
<?php layout_end();
