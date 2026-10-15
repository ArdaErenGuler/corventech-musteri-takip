<?php
/**
 * Pay dağılımı ve şirket kasası.
 *
 * Tahsilatlar müşterinin sahibine göre bölünür:
 *   - Arda / Melih müşterisi → şirket payı (varsayılan 1/3) şirkete, kalanı müşteri sahibine
 *   - Ortak müşteri          → tamamı şirkete
 * Şirket kasası (net) = şirket payı + diğer gelirler − giderler
 */

const EXPENSE_CATEGORIES = ['Hosting', 'Domain', 'Claude / Yazılım', 'Reklam', 'Donanım', 'Vergi / Muhasebe', 'Diğer gider'];
const INCOME_CATEGORIES = ['Diğer gelir', 'Danışmanlık', 'Faiz / İade'];

/** Seçilebilir şirket payı oranları */
const COMPANY_SHARE_OPTIONS = ['1/4' => '1/4 (%25)', '1/3' => '1/3 (%33)', '2/5' => '2/5 (%40)', '1/2' => '1/2 (%50)'];

function company_share(): float
{
    $v = setting('company_share') ?: '1/3';
    if (preg_match('#^(\d+)/(\d+)$#', $v, $m) && (int) $m[2] > 0) {
        return (int) $m[1] / (int) $m[2];
    }
    return 1 / 3;
}

function company_share_label(): string
{
    $v = setting('company_share') ?: '1/3';
    return isset(COMPANY_SHARE_OPTIONS[$v]) ? $v : '1/3';
}

/**
 * Tarih aralığındaki tahsilatların dağılımı.
 * Dönen: ['total', 'arda', 'melih', 'company_share', 'other_income', 'expense', 'net', 'by_owner' => [owner => tahsilat]]
 */
function share_breakdown(string $from, string $to): array
{
    $share = company_share();
    $zero = ['arda' => 0.0, 'melih' => 0.0, 'ortak' => 0.0];
    $r = ['total' => 0.0, 'arda' => 0.0, 'melih' => 0.0, 'company_share' => 0.0, 'by_owner' => $zero, 'company_by_owner' => $zero];

    foreach (q_all(
        'SELECT c.owner, SUM(p.amount) AS total FROM payments p JOIN customers c ON c.id = p.customer_id
         WHERE p.paid_at BETWEEN ? AND ? GROUP BY c.owner',
        [$from, $to]
    ) as $row) {
        $amount = (float) $row['total'];
        $owner = $row['owner'];
        $r['total'] += $amount;
        $r['by_owner'][$owner] = $amount;
        $company = $owner === 'ortak' ? $amount : round($amount * $share, 2);
        $r['company_share'] += $company;
        $r['company_by_owner'][$owner] = $company;
        if ($owner !== 'ortak') {
            $r[$owner] += $amount - $company;
        }
    }

    $tx = q_one(
        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense
         FROM transactions WHERE txn_date BETWEEN ? AND ?",
        [$from, $to]
    );
    $r['other_income'] = (float) $tx['income'];
    $r['expense'] = (float) $tx['expense'];
    $r['net'] = $r['company_share'] + $r['other_income'] - $r['expense'];
    return $r;
}
