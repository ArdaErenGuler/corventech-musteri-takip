<?php
/**
 * Formların arka planda kullandığı küçük JSON uç noktaları.
 */
require __DIR__ . '/includes/bootstrap.php';
require_login();

switch (input('action')) {
    // Ödeme formu: müşterinin ödenmemiş dönemleri
    case 'open_dues':
        $rows = q_all(DUES_SELECT . ' WHERE ' . DUES_UNPAID . ' AND d.customer_id = ? ORDER BY d.due_date', [input_int('customer_id') ?? 0]);
        json_response(array_map(fn($d) => [
            'id'        => (int) $d['id'],
            'remaining' => money_input($d['remaining']),
            'label'     => order_name($d) . ' · ' . due_label($d) . ' · ' . date_tr($d['due_date']) . ' · ' . money($d['remaining']),
        ], $rows));

    // Bildirim: bugün ve gecikmiş, paylaşılmamış gönderiler
    case 'today_posts':
        json_response(array_map(fn($p) => [
            'title'    => $p['title'],
            'customer' => $p['customer_name'],
            'time'     => $p['scheduled_time'] ? substr($p['scheduled_time'], 0, 5) : null,
            'late'     => $p['scheduled_date'] < today(),
        ], due_posts()));

    default:
        json_response(['error' => 'Bilinmeyen işlem'], 400);
}
