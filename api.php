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
            'remaining' => number_format((float) $d['remaining'], 2, '.', ''),
            'label'     => order_name($d) . ' · ' . due_label($d) . ' · ' . date_tr($d['due_date']) . ' · ' . money($d['remaining']),
        ], $rows));

    default:
        json_response(['error' => 'Bilinmeyen işlem'], 400);
}
