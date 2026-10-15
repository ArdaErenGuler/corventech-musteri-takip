<?php
/**
 * Kasalar: Arda, Melih ve Şirket.
 *
 * - Her iş (sipariş) eklenirken parasının hangi kasaya gideceği seçilir (orders.share_*, yüzde).
 *   Ödemeler bağlı oldukları işin payına göre kasalara dağılır. İşe bağlanmamış ödemeler
 *   müşterinin sahibine göre varsayılan payla dağılır.
 * - Gelir/giderlerde hangi kasa olduğu seçilir. Düzenli olanlar (aylık / yıllık) her dönem kendiliğinden yazılır.
 * - Kasa net = işlerden gelen pay + diğer gelirler − giderler
 */

const WALLETS = ['arda' => 'Arda', 'melih' => 'Melih', 'company' => 'Şirket'];

/** Kişisel paylar bu tutara yuvarlanır (100 → 1.666,67 ₺ yerine 1.700 ₺); fark şirket kasasına kalır */
const SHARE_ROUNDING = 100;

/** Hazır pay seçenekleri: [etiket, [arda, melih, şirket] yüzde] */
const SHARE_PRESETS = [
    'arda'  => ["Arda'nın işi", [66.6667, 0, 33.3333]],
    'melih' => ["Melih'in işi", [0, 66.6667, 33.3333]],
    'ortak' => ['Ortak iş', [0, 0, 100]],
];

const EXPENSE_CATEGORIES = ['Hosting', 'Domain', 'Claude / Yazılım', 'Reklam', 'Donanım', 'Vergi / Muhasebe', 'Diğer gider'];
const INCOME_CATEGORIES = ['Diğer gelir', 'Danışmanlık', 'Faiz / İade'];
const TXN_CYCLES = ['once' => 'Tek sefer', 'monthly' => 'Her ay', 'yearly' => 'Her yıl'];

/** Müşteri sahibine göre önerilen pay seçeneği */
function share_preset_for_owner(?string $owner): string
{
    return isset(SHARE_PRESETS[$owner]) ? $owner : 'ortak';
}

/** İşin paylarına uyan hazır seçenek ya da "custom" */
function order_share_preset(array $o): string
{
    foreach (SHARE_PRESETS as $key => [, $p]) {
        if (abs($o['share_arda'] - $p[0]) < 0.01 && abs($o['share_melih'] - $p[1]) < 0.01 && abs($o['share_company'] - $p[2]) < 0.01) {
            return $key;
        }
    }
    return 'custom';
}

/** 66.6667 → "2/3", 100 → "tamamı", 25 → "%25" */
function share_fraction(float $pct): string
{
    // (Dizi anahtarı ondalıklı olamayacağı için çiftler halinde)
    $known = [[100, 'tamamı'], [66.6667, '2/3'], [33.3333, '1/3'], [50, '1/2'], [25, '1/4'], [75, '3/4']];
    foreach ($known as [$k, $label]) {
        if (abs($pct - $k) < 0.01) {
            return $label;
        }
    }
    return '%' . rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',');
}

/** "Arda 2/3 · Şirket 1/3" */
function share_text(array $o): string
{
    $parts = [];
    foreach (['arda' => 'share_arda', 'melih' => 'share_melih', 'company' => 'share_company'] as $w => $col) {
        if ((float) $o[$col] > 0.001) {
            $parts[] = WALLETS[$w] . ' ' . share_fraction((float) $o[$col]);
        }
    }
    return implode(' · ', $parts);
}

/** Formdaki "Para kime gidecek?" alanlarını okur. Dönen: [[arda, melih, şirket], hata|null] */
function validate_share_input(): array
{
    $preset = input('split');
    if (isset(SHARE_PRESETS[$preset])) {
        return [SHARE_PRESETS[$preset][1], null];
    }
    $vals = array_map(fn($k) => (float) str_replace(',', '.', input($k) ?: '0'), ['share_arda', 'share_melih', 'share_company']);
    if (min($vals) < 0 || abs(array_sum($vals) - 100) > 0.05) {
        return [$vals, 'Yüzdelerin toplamı 100 olmalı.'];
    }
    return [$vals, null];
}

/** Düzenli gelir/giderlerin vadesi gelen dönemlerini kayda geçirir (günde bir kez). */
function sync_recurring_transactions(bool $force = false): void
{
    if (!$force && setting('last_rtx_sync') === today()) {
        return;
    }
    $insert = db()->prepare('INSERT INTO transactions (type, wallet, category, amount, txn_date, description, recurring_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (q_all('SELECT * FROM recurring_transactions WHERE is_active = 1') as $r) {
        $step = $r['cycle'] === 'yearly' ? 12 : 1;
        $n = (int) $r['last_period'];
        while ($n < 1200) {
            $date = add_months_clamped($r['start_date'], $n * $step);   // n. dönemin tarihi (0'dan)
            if ($date > today()) {
                break;
            }
            $insert->execute([$r['type'], $r['wallet'], $r['category'], $r['amount'], $date, $r['description'], $r['id'], $r['created_by']]);
            $n++;
        }
        if ($n !== (int) $r['last_period']) {
            q('UPDATE recurring_transactions SET last_period = ? WHERE id = ?', [$n, $r['id']]);
        }
    }
    set_setting('last_rtx_sync', today());
}

/** Düzenli kaydın bir sonraki tarihi */
function recurring_next_date(array $r): string
{
    return add_months_clamped($r['start_date'], (int) $r['last_period'] * ($r['cycle'] === 'yearly' ? 12 : 1));
}

/**
 * Tarih aralığında kasaların durumu.
 * Dönen: ['total' => tahsilat, 'wallets' => [arda|melih|company => ['jobs', 'income', 'expense', 'net']]]
 */
function wallet_breakdown(string $from, string $to): array
{
    // Kişisel pay her ödemede en yakın SHARE_ROUNDING'e yuvarlanır (1.666,67 → 1.700); kalan şirkete yazılır.
    // %66,6667 / %33,3333 tam kesir (2/3, 1/3) olarak hesaplanır.
    $digits = -(int) round(log10(SHARE_ROUNDING));
    $part = fn(string $pct) => "LEAST(p.amount, ROUND(CASE WHEN ABS($pct - 66.6667) < 0.001 THEN p.amount * 2 / 3
                                                         WHEN ABS($pct - 33.3333) < 0.001 THEN p.amount / 3
                                                         ELSE p.amount * $pct / 100 END, $digits))";
    $row = q_one(
        "SELECT COALESCE(SUM(p.amount), 0) AS total,
                COALESCE(SUM(" . $part("COALESCE(o.share_arda,  IF(c.owner = 'arda',  66.6667, 0))") . "), 0) AS arda,
                COALESCE(SUM(" . $part("COALESCE(o.share_melih, IF(c.owner = 'melih', 66.6667, 0))") . "), 0) AS melih
         FROM payments p
         JOIN customers c ON c.id = p.customer_id
         LEFT JOIN dues d ON d.id = p.due_id
         LEFT JOIN orders o ON o.id = d.order_id
         WHERE p.paid_at BETWEEN ? AND ?",
        [$from, $to]
    );
    $total = (float) $row['total'];
    $jobs = ['arda' => round((float) $row['arda'], 2), 'melih' => round((float) $row['melih'], 2)];
    $jobs['company'] = round($total - $jobs['arda'] - $jobs['melih'], 2);

    $wallets = [];
    foreach (WALLETS as $w => $label) {
        $wallets[$w] = ['jobs' => $jobs[$w], 'income' => 0.0, 'expense' => 0.0];
    }
    foreach (q_all('SELECT wallet, type, SUM(amount) AS total FROM transactions WHERE txn_date BETWEEN ? AND ? GROUP BY wallet, type', [$from, $to]) as $t) {
        $wallets[$t['wallet']][$t['type']] = (float) $t['total'];
    }
    foreach ($wallets as $w => $v) {
        $wallets[$w]['net'] = round($v['jobs'] + $v['income'] - $v['expense'], 2);
    }
    return ['total' => $total, 'wallets' => $wallets];
}
