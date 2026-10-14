<?php
/**
 * Ödeme planı mantığı.
 *
 * Her sipariş (müşteriye satılan hizmet) bir ödeme tipine sahiptir: tek seferlik, aylık veya yıllık.
 * Siparişin beklenen ödemeleri "dues" tablosunda dönem dönem tutulur:
 *   - period_no 0  → kurulum ücreti (varsa), başlangıç tarihinde
 *   - period_no 1+ → dönem ödemeleri; n. dönemin tarihi = ilk ödeme tarihi + (n-1) ay/yıl
 * Dönemler cron gerektirmeden, sayfa açıldıkça "ufuk" tarihine (bugün + N gün) kadar üretilir.
 * Ödemeler (payments) bir döneme bağlanabilir; bağlanmayanlar genel tahsilat sayılır.
 */

const CYCLE_LABELS = [
    'one_time' => 'Tek seferlik',
    'monthly'  => 'Aylık',
    'yearly'   => 'Yıllık',
];

const ORDER_STATUS_LABELS = [
    'active'    => 'Aktif',
    'paused'    => 'Durduruldu',
    'cancelled' => 'İptal',
    'completed' => 'Tamamlandı',
];

const PAYMENT_METHODS = [
    'transfer' => 'Havale / EFT',
    'cash'     => 'Nakit',
    'card'     => 'Kredi kartı',
    'other'    => 'Diğer',
];

const CUSTOMER_STATUS_LABELS = [
    'active'    => 'Aktif',
    'potential' => 'Potansiyel',
    'passive'   => 'Pasif',
];

/** Müşterinin sorumlusu (müşteri listesinde filtre) */
const OWNER_LABELS = [
    'arda'  => 'Arda',
    'melih' => 'Melih',
    'ortak' => 'Ortak',
];

/**
 * Yeni sipariş oluşturur ve ödeme planını üretir. Sipariş formu ve "yeni müşteri" formu kullanır.
 * $o: customer_id, service_id, title, billing_cycle, price, setup_fee, start_date, first_due_date, end_date, notes
 */
function create_order(array $o, int $userId): int
{
    q(
        "INSERT INTO orders (customer_id, service_id, title, billing_cycle, price, setup_fee, start_date, first_due_date, end_date, status, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)",
        [$o['customer_id'], $o['service_id'] ?: null, $o['title'] ?: null, $o['billing_cycle'], $o['price'], $o['setup_fee'],
         $o['start_date'], $o['first_due_date'], $o['end_date'] ?? null, $o['notes'] ?? null, $userId]
    );
    $id = (int) db()->lastInsertId();
    sync_order_dues(q_one('SELECT * FROM orders WHERE id = ?', [$id]));
    if (q_val('SELECT status FROM customers WHERE id = ?', [$o['customer_id']]) === 'potential') {
        q("UPDATE customers SET status = 'active' WHERE id = ?", [$o['customer_id']]);
    }
    return $id;
}

/**
 * Sipariş formu alanlarını doğrular ve normalleştirir (müşteri hariç).
 * Dönen dizi: [$data, $errors]
 */
function validate_order_input(): array
{
    $errors = [];
    $d = [
        'service_id'     => input_int('service_id'),
        'title'          => input('title'),
        'billing_cycle'  => input('billing_cycle'),
        'price'          => input_money('price'),
        'setup_fee'      => input_money('setup_fee') ?? 0.0,
        'start_date'     => input_date('start_date'),
        'first_due_date' => input_date('first_due_date'),
        'end_date'       => input_date('end_date'),
        'notes'          => input('notes'),
    ];
    if ($d['service_id'] && !q_val('SELECT id FROM services WHERE id = ?', [$d['service_id']])) $errors['service_id'] = 'Geçersiz paket.';
    if (!$d['service_id'] && $d['title'] === '') $errors['service_id'] = 'Paket seçin ya da hizmete bir ad yazın.';
    if (!isset(CYCLE_LABELS[$d['billing_cycle']])) $errors['billing_cycle'] = 'Ödeme tipini seçin.';
    if ($d['price'] === null || $d['price'] < 0) $errors['price'] = 'Tutarı yazın.';
    if ($d['setup_fee'] < 0) $errors['setup_fee'] = 'Geçerli bir tutar yazın.';
    if (!$d['start_date']) $errors['start_date'] = 'Tarih seçin.';

    if ($d['billing_cycle'] === 'one_time') {
        $d['first_due_date'] = $d['start_date'];
        $d['setup_fee'] = 0.0;
        $d['end_date'] = null;
    } else {
        $d['first_due_date'] = $d['first_due_date'] ?: $d['start_date'];
        if ($d['end_date'] && $d['first_due_date'] && $d['end_date'] < $d['first_due_date']) $errors['end_date'] = 'Bitiş tarihi ilk ödemeden önce olamaz.';
    }
    return [$d, $errors];
}

/** Ay ekler, ay sonuna taşarsa son güne sabitler (31 Ocak + 1 ay = 28/29 Şubat) */
function add_months_clamped(string $date, int $months): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    $total = $y * 12 + ($m - 1) + $months;
    $ny = intdiv($total, 12);
    $nm = $total % 12 + 1;
    $last = (int) date('t', mktime(0, 0, 0, $nm, 1, $ny));
    return sprintf('%04d-%02d-%02d', $ny, $nm, min($d, $last));
}

function period_due_date(string $firstDue, string $cycle, int $n): string
{
    $step = $cycle === 'yearly' ? 12 : 1;
    return add_months_clamped($firstDue, ($n - 1) * $step);
}

/** Siparişin eksik dönemlerini oluşturur (var olanlara dokunmaz). */
function sync_order_dues(array $o): void
{
    if ($o['status'] !== 'active') {
        return;
    }
    $horizon = date('Y-m-d', strtotime('+' . (int) config('due_horizon_days', 60) . ' days'));
    $existing = array_flip(q('SELECT period_no FROM dues WHERE order_id = ?', [$o['id']])->fetchAll(PDO::FETCH_COLUMN));
    $from = $o['sync_from'] ?: null;
    $end = $o['end_date'] ?: null;
    $rows = [];

    $allowed = function (string $date) use ($from, $end): bool {
        return (!$from || $date >= $from) && (!$end || $date <= $end);
    };

    if ($o['billing_cycle'] === 'one_time') {
        if (!isset($existing[1]) && $allowed($o['first_due_date'])) {
            $rows[] = [1, $o['first_due_date'], $o['price']];
        }
    } else {
        if ((float) $o['setup_fee'] > 0 && !isset($existing[0]) && $allowed($o['start_date'])) {
            $rows[] = [0, $o['start_date'], $o['setup_fee']];
        }
        for ($n = 1; $n <= 600; $n++) {
            $date = period_due_date($o['first_due_date'], $o['billing_cycle'], $n);
            if ($date > $horizon || ($end && $date > $end)) {
                break;
            }
            if (!isset($existing[$n]) && $allowed($date)) {
                $rows[] = [$n, $date, $o['price']];
            }
        }
    }

    if ($rows) {
        $stmt = db()->prepare('INSERT IGNORE INTO dues (order_id, customer_id, period_no, due_date, amount) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as [$n, $date, $amount]) {
            $stmt->execute([$o['id'], $o['customer_id'], $n, $date, $amount]);
        }
    }
}

/** Tüm aktif siparişlerin dönemlerini günde bir kez günceller. */
function sync_all_dues(bool $force = false): void
{
    if (!$force && setting('last_due_sync') === today()) {
        return;
    }
    foreach (q_all("SELECT * FROM orders WHERE status = 'active'") as $o) {
        sync_order_dues($o);
    }
    set_setting('last_due_sync', today());
}

/**
 * Sipariş düzenlendiğinde: ödemesi olmayan açık dönemleri silip yeni bilgilerle yeniden üretir.
 * Durdurma/iptal öncesinden kalan geçmiş borçlar (sync_from öncesi) korunur.
 */
function rebuild_order_dues(int $orderId): void
{
    $o = q_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$o) {
        return;
    }
    q(
        "DELETE d FROM dues d
         LEFT JOIN payments p ON p.due_id = d.id
         WHERE d.order_id = ? AND d.status = 'open' AND p.id IS NULL AND (? IS NULL OR d.due_date >= ?)",
        [$orderId, $o['sync_from'], $o['sync_from']]
    );
    sync_order_dues($o);
}

/** Durdurulan/iptal edilen siparişin ileri tarihli, ödenmemiş dönemlerini kaldırır. */
function trim_future_dues(int $orderId): void
{
    q(
        "DELETE d FROM dues d
         LEFT JOIN payments p ON p.due_id = d.id
         WHERE d.order_id = ? AND d.status = 'open' AND p.id IS NULL AND d.due_date > CURDATE()",
        [$orderId]
    );
}

/** Dönemleri ödenen tutarla birlikte getiren temel sorgu (sonuna WHERE eklenir). */
const DUES_SELECT = "
    SELECT d.*, COALESCE(pp.paid, 0) AS paid, d.amount - COALESCE(pp.paid, 0) AS remaining,
           o.billing_cycle, o.title AS order_title, s.name AS service_name,
           c.company_name, c.contact_name, c.phone
    FROM dues d
    JOIN orders o ON o.id = d.order_id
    JOIN customers c ON c.id = d.customer_id
    LEFT JOIN services s ON s.id = o.service_id
    LEFT JOIN (SELECT due_id, SUM(amount) AS paid FROM payments WHERE due_id IS NOT NULL GROUP BY due_id) pp ON pp.due_id = d.id
";

/** Ödenmemiş (kısmen ödenmiş dahil) açık dönemler için WHERE parçası */
const DUES_UNPAID = "d.status = 'open' AND d.amount - COALESCE(pp.paid, 0) > 0.009";

/** Dönemin durumu: paid | partial | overdue | today | soon | upcoming | waived */
function due_state(array $d): string
{
    if ($d['status'] === 'waived') {
        return 'waived';
    }
    if ((float) $d['remaining'] <= 0.009) {
        return 'paid';
    }
    $days = days_until($d['due_date']);
    if ($days < 0) {
        return 'overdue';
    }
    if ($days === 0) {
        return 'today';
    }
    if ((float) $d['paid'] > 0) {
        return 'partial';
    }
    return $days <= 7 ? 'soon' : 'upcoming';
}

const DUE_STATE_LABELS = [
    'paid'     => ['Ödendi', 'good', 'check'],
    'partial'  => ['Kısmi ödendi', 'warning', 'clock'],
    'overdue'  => ['Gecikti', 'critical', 'alert'],
    'today'    => ['Bugün', 'warning', 'clock'],
    'soon'     => ['Yaklaşıyor', 'warning', 'clock'],
    'upcoming' => ['Bekleniyor', 'neutral', 'calendar'],
    'waived'   => ['İptal edildi', 'neutral', 'x'],
];

function due_label(array $d): string
{
    if ((int) $d['period_no'] === 0) {
        return 'Kurulum ücreti';
    }
    if ($d['billing_cycle'] === 'monthly') {
        $ts = strtotime($d['due_date']);
        return TR_MONTHS[(int) date('n', $ts)] . ' ' . date('Y', $ts) . ' dönemi';
    }
    if ($d['billing_cycle'] === 'yearly') {
        return date('Y', strtotime($d['due_date'])) . ' yılı';
    }
    return 'Tek seferlik ödeme';
}

function order_name(array $row): string
{
    $title = (string) ($row['order_title'] ?? $row['title'] ?? '');
    return $title !== '' ? $title : ($row['service_name'] ?? 'Hizmet');
}

/**
 * Müşterinin bakiyesi.
 *  due      = vadesi gelmiş (bugün dahil) açık dönemlerin toplamı
 *  paid     = bu dönemlere yapılan + döneme bağlanmamış ödemeler
 *  balance  = due - paid (pozitif = borç, negatif = avans)
 */
function customer_balance(int $customerId): array
{
    $row = q_one(
        "SELECT
            (SELECT COALESCE(SUM(amount), 0) FROM dues WHERE customer_id = :c1 AND status = 'open' AND due_date <= CURDATE()) AS due,
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p LEFT JOIN dues d ON d.id = p.due_id
              WHERE p.customer_id = :c2 AND (p.due_id IS NULL OR (d.status = 'open' AND d.due_date <= CURDATE()))) AS paid,
            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE customer_id = :c3) AS total_paid",
        ['c1' => $customerId, 'c2' => $customerId, 'c3' => $customerId]
    );
    return [
        'due'        => (float) $row['due'],
        'paid'       => (float) $row['paid'],
        'balance'    => round((float) $row['due'] - (float) $row['paid'], 2),
        'total_paid' => (float) $row['total_paid'],
    ];
}

/** Tüm müşterilerin borç bakiyesi (liste ekranları için tek sorgu): [customer_id => balance] */
function all_customer_balances(): array
{
    $rows = q_all(
        "SELECT c.id,
            COALESCE(dd.due, 0) - COALESCE(pp.paid, 0) AS balance
         FROM customers c
         LEFT JOIN (SELECT customer_id, SUM(amount) AS due FROM dues
                    WHERE status = 'open' AND due_date <= CURDATE() GROUP BY customer_id) dd ON dd.customer_id = c.id
         LEFT JOIN (SELECT p.customer_id, SUM(p.amount) AS paid FROM payments p LEFT JOIN dues d ON d.id = p.due_id
                    WHERE p.due_id IS NULL OR (d.status = 'open' AND d.due_date <= CURDATE())
                    GROUP BY p.customer_id) pp ON pp.customer_id = c.id"
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = round((float) $r['balance'], 2);
    }
    return $out;
}

/** Aylık tekrarlayan gelir: aktif aylık siparişler + yıllıkların 1/12'si */
function monthly_recurring_revenue(): float
{
    return (float) q_val(
        "SELECT COALESCE(SUM(CASE billing_cycle WHEN 'monthly' THEN price WHEN 'yearly' THEN price / 12 ELSE 0 END), 0)
         FROM orders WHERE status = 'active'"
    );
}

/** WhatsApp hatırlatma mesajı (Ayarlar'daki şablondan) */
function reminder_text(array $d): string
{
    $tpl = setting('wa_template') ?: 'Merhaba {musteri}, {hizmet} hizmetinizin {tarih} tarihli {tutar} tutarındaki ödemesini hatırlatmak isteriz. İyi çalışmalar, Corventech';
    return strtr($tpl, [
        '{musteri}' => $d['contact_name'] ?: ($d['company_name'] ?? ''),
        '{firma}'   => $d['company_name'] ?: $d['contact_name'],
        '{hizmet}'  => order_name($d),
        '{tarih}'   => date_tr($d['due_date']),
        '{tutar}'   => money($d['remaining']),
        '{donem}'   => due_label($d),
    ]);
}
