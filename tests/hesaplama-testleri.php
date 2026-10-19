<?php
/**
 * Kritik hesaplama fonksiyonlarının testleri (veritabanı gerektirmez).
 * Çalıştırma:  php tests/hesaplama-testleri.php
 * Bir değişiklikten sonra hepsi "OK" vermeli; "HATA" varsa canlıya almayın.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', 'test');
$GLOBALS['config'] = require APP_ROOT . '/config.php';
function config(string $key, $default = null) { return $GLOBALS['config'][$key] ?? $default; }
date_default_timezone_set('Europe/Istanbul');
require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/helpers.php';
require APP_ROOT . '/includes/billing.php';
require APP_ROOT . '/includes/finance.php';
require APP_ROOT . '/includes/migrate.php';

$fail = 0;
function check(string $name, $got, $want): void
{
    global $fail;
    $ok = $got === $want;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '  OK    ' : '  HATA  ') . $name . ($ok ? '' : ' → beklenen ' . var_export($want, true) . ', gelen ' . var_export($got, true)) . PHP_EOL;
}

echo "Ay ekleme (ay sonu düzeltmesi)\n";
check('31 Ocak + 1 ay', add_months_clamped('2026-01-31', 1), '2026-02-28');
check('31 Ocak + 2 ay', add_months_clamped('2026-01-31', 2), '2026-03-31');
check('29 Şubat (artık yıl) + 12 ay', add_months_clamped('2028-02-29', 12), '2029-02-28');
check('15 Kasım + 3 ay (yıl atlar)', add_months_clamped('2026-11-15', 3), '2027-02-15');

echo "Dönem tarihleri\n";
check('aylık 1. dönem', period_due_date('2026-08-31', 'monthly', 1), '2026-08-31');
check('aylık 2. dönem', period_due_date('2026-08-31', 'monthly', 2), '2026-09-30');
check('aylık 3. dönem (kaymaz)', period_due_date('2026-08-31', 'monthly', 3), '2026-10-31');
check('yıllık 2. dönem', period_due_date('2026-10-18', 'yearly', 2), '2027-10-18');

echo "Taksit bölme\n";
check('10.500 / 3', installment_amounts(10500, 3), [3500.0, 3500.0, 3500.0]);
check('12.000 / 3', installment_amounts(12000, 3), [4000.0, 4000.0, 4000.0]);
check('10.000 / 3 (kuruş son taksitte)', installment_amounts(10000, 3), [3333.33, 3333.33, 3333.34]);
check('toplam korunur', round(array_sum(installment_amounts(9999.99, 7)), 2), 9999.99);
check('tek taksit', installment_amounts(500, 1), [500.0]);
check('taksit etiketi', due_label(['period_no' => 2, 'billing_cycle' => 'installment', 'installments' => 3, 'due_date' => '2026-11-19']), '2/3. taksit');

echo "Türkçe tutar okuma\n";
check('14.000', parse_money('14.000'), 14000.0);
check('1.250,50', parse_money('1.250,50'), 1250.5);
check('1250.5', parse_money('1250.5'), 1250.5);
check('₺ 7.500', parse_money('₺ 7.500'), 7500.0);
check('1.234.567', parse_money('1.234.567'), 1234567.0);
check('boş', parse_money(''), null);
check('harf', parse_money('abc'), null);

echo "Tutar yazma\n";
check('1250.5 → 1.250,50 ₺', money(1250.5), '1.250,50 ₺');
check('8300 → 8.300 ₺', money(8300), '8.300 ₺');
check('form alanı 6000.00 → 6000', money_input('6000.00'), '6000');

echo "WhatsApp numarası\n";
check('0555 123 45 67', wa_number('0555 123 45 67'), '905551234567');
check('+90 (555) 123 45 67', wa_number('+90 (555) 123 45 67'), '905551234567');
check('5551234567', wa_number('5551234567'), '905551234567');
check('boş', wa_number(''), '');

echo "Güvenli geri dönüş adresi\n";
check('panel sayfası', safe_return('/panel/musteri.php?id=4#odemeler', 'x.php'), 'musteri.php?id=4#odemeler');
check('dış site reddedilir', safe_return('https://kotu.site/x', 'dashboard.php'), 'dashboard.php');
check('javascript: reddedilir', safe_return('javascript:alert(1)', 'dashboard.php'), 'dashboard.php');

echo "SQL güncelleme dosyası bölme\n";
check('yorumlar atılır, 2 ifade', count(split_sql("-- yorum\nCREATE TABLE a (x INT);\n-- yorum 2\nINSERT INTO a VALUES (1);\n")), 2);

echo PHP_EOL . ($fail ? "$fail TEST BAŞARISIZ" : 'Tüm testler geçti.') . PHP_EOL;
exit($fail ? 1 : 0);
