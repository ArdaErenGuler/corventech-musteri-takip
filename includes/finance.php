<?php
/**
 * Şirket kasası.
 *
 * Bütün tahsilatlar şirkete yazılır (kişiye pay ayrımı yoktur).
 *   Net = tahsilat + diğer gelirler − giderler
 * Gelir/giderler elle girilir; düzenli olanlar (aylık / yıllık) her dönem kendiliğinden kayda geçer.
 */

const EXPENSE_CATEGORIES = ['Hosting', 'Domain', 'Claude / Yazılım', 'Reklam', 'Donanım', 'Vergi / Muhasebe', 'Diğer gider'];
const INCOME_CATEGORIES = ['Diğer gelir', 'Danışmanlık', 'Faiz / İade'];
const TXN_CYCLES = ['once' => 'Tek sefer', 'monthly' => 'Her ay', 'yearly' => 'Her yıl'];

/** Düzenli gelir/giderlerin vadesi gelen dönemlerini kayda geçirir (günde bir kez). */
function sync_recurring_transactions(bool $force = false): void
{
    if (!$force && setting('last_rtx_sync') === today()) {
        return;
    }
    $insert = db()->prepare('INSERT INTO transactions (type, category, amount, txn_date, description, recurring_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach (q_all('SELECT * FROM recurring_transactions WHERE is_active = 1') as $r) {
        $step = $r['cycle'] === 'yearly' ? 12 : 1;
        $n = (int) $r['last_period'];
        while ($n < 1200) {
            $date = add_months_clamped($r['start_date'], $n * $step);   // n. dönemin tarihi (0'dan)
            if ($date > today()) {
                break;
            }
            $insert->execute([$r['type'], $r['category'], $r['amount'], $date, $r['description'], $r['id'], $r['created_by']]);
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
 * Tarih aralığında şirketin durumu.
 * Dönen: ['collected' => tahsilat, 'income' => diğer gelir, 'expense' => gider, 'net' => net]
 */
function company_breakdown(string $from, string $to): array
{
    $collected = (float) q_val('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paid_at BETWEEN ? AND ?', [$from, $to]);
    $tx = q_one(
        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
         FROM transactions WHERE txn_date BETWEEN ? AND ?",
        [$from, $to]
    );
    $income = (float) $tx['income'];
    $expense = (float) $tx['expense'];
    return [
        'collected' => round($collected, 2),
        'income'    => round($income, 2),
        'expense'   => round($expense, 2),
        'net'       => round($collected + $income - $expense, 2),
    ];
}
