<?php
/**
 * Yedekleme: tek butonla ZIP iner. İçinde
 *   - veritabani-(tarih).sql : asıl yedek (geri yükleme / başka sunucuya taşıma için)
 *   - excel/*.csv            : aynı kayıtların Excel'de açılabilen okunur kopyası
 *   - BENI-OKU.txt           : geri yükleme adımları
 * ZIP bellekte oluşturulur (sunucuya dosya yazılmaz; ZipArchive eklentisi gerekmez, yalnızca zlib).
 */

const BACKUP_REMIND_DAYS = 7;   // bu kadar gündür yedek alınmadıysa panelde ve menüde hatırlat

/** Yalnızca yapısı yedeklenen, verisi geçici tablolar */
const BACKUP_SKIP_DATA = ['sessions', 'login_attempts'];

function last_backup_at(): ?string
{
    return setting('last_backup_at');
}

/** Son yedekten bu yana geçen gün (hiç alınmadıysa null) */
function days_since_backup(): ?int
{
    $at = last_backup_at();
    return $at ? (int) (new DateTimeImmutable(substr($at, 0, 10)))->diff(new DateTimeImmutable(today()))->days : null;
}

function backup_due(): bool
{
    $days = days_since_backup();
    if ($days === null) {
        // Hiç yedek yoksa: yedeklenecek bir şey (müşteri) eklendiğinde hatırlat; boş yeni panelde değil
        return (int) q_val('SELECT COUNT(*) FROM customers') > 0;
    }
    return $days >= BACKUP_REMIND_DAYS;
}

/** Tüm tabloların yapısı ve verisi; boş bir veritabanına içe aktarılarak geri yüklenir */
function backup_sql_dump(array $user): string
{
    $pdo = db();
    $out = "-- Corventech Panel — veritabanı yedeği\n"
        . '-- Tarih: ' . date('d.m.Y H:i') . ' · Alan: ' . str_replace(["\r", "\n"], ' ', $user['name']) . "\n"
        . "-- Geri yükleme: BOŞ bir veritabanına phpMyAdmin > İçe Aktar ile yükleyin.\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_NUM)[1];
        $out .= "DROP TABLE IF EXISTS $quoted;\n$create;\n";
        if (in_array($table, BACKUP_SKIP_DATA, true)) {
            $out .= "\n";
            continue;
        }
        $stmt = $pdo->query("SELECT * FROM $quoted");
        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $rows[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
            if (count($rows) === 200) {
                $out .= "INSERT INTO $quoted VALUES\n" . implode(",\n", $rows) . ";\n";
                $rows = [];
            }
        }
        if ($rows) {
            $out .= "INSERT INTO $quoted VALUES\n" . implode(",\n", $rows) . ";\n";
        }
        $out .= "\n";
    }
    return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
}

/** Excel (Türkçe) uyumlu CSV: UTF-8 BOM + noktalı virgül ayırıcı */
function backup_csv(array $headers, array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $headers, ';');
    foreach ($rows as $r) {
        fputcsv($fh, array_map(fn($v) => $v === null ? '' : (string) $v, array_values($r)), ';');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/** Okunur Excel kopyaları: [dosya adı => csv] */
function backup_readable_sheets(): array
{
    $tr = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '';
    $num = fn($v) => $v === null ? '' : str_replace('.', ',', (string) (float) $v);

    $customers = array_map(fn($c) => [
        $c['company_name'], $c['contact_name'], $c['phone'], $c['email'], $c['sector'], $c['city'],
        CUSTOMER_STATUS_LABELS[$c['status']] ?? $c['status'], $tr($c['registered_at']), $c['notes'],
    ], q_all('SELECT * FROM customers ORDER BY id'));

    $orders = array_map(fn($o) => [
        $o['customer_name'], order_name($o), CYCLE_LABELS[$o['billing_cycle']] ?? '', $num($o['price']), $num($o['setup_fee']),
        $tr($o['start_date']), $tr($o['first_due_date']), $tr($o['end_date']), ORDER_STATUS_LABELS[$o['status']] ?? $o['status'],
    ], q_all("SELECT o.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS customer_name
              FROM orders o JOIN customers c ON c.id = o.customer_id ORDER BY o.id"));

    $payments = array_map(fn($p) => [
        $tr($p['paid_at']), customer_display_name($p), $p['due_id'] ? order_name($p) . ' · ' . due_label($p) : 'Genel ödeme',
        $num($p['amount']), PAYMENT_METHODS[$p['method']] ?? $p['method'], $p['note'],
    ], q_all(PAYMENTS_SELECT . ' ORDER BY p.paid_at, p.id'));

    $txns = array_map(fn($t) => [
        $tr($t['txn_date']), $t['type'] === 'income' ? 'Gelir' : 'Gider', $t['category'], $num($t['amount']), $t['description'],
    ], q_all('SELECT * FROM transactions ORDER BY txn_date, id'));

    $posts = array_map(fn($p) => [
        $tr($p['scheduled_date']), $p['customer_name'], $p['status'] === 'published' ? 'Paylaşıldı' : 'Paylaşılacak',
    ], q_all(POSTS_SELECT . ' ORDER BY p.scheduled_date, p.id'));

    return [
        'excel/musteriler.csv'  => backup_csv(['Firma', 'Ad soyad', 'Telefon', 'E-posta', 'Sektör', 'Şehir', 'Durum', 'Kayıt tarihi', 'Notlar'], $customers),
        'excel/isler.csv'       => backup_csv(['Müşteri', 'Hizmet', 'Ödeme tipi', 'Tutar', 'Kurulum', 'Başlangıç', 'İlk ödeme', 'Bitiş', 'Durum'], $orders),
        'excel/odemeler.csv'    => backup_csv(['Tarih', 'Müşteri', 'Ne için', 'Tutar', 'Yöntem', 'Not'], $payments),
        'excel/gelir-gider.csv' => backup_csv(['Tarih', 'Tür', 'Kategori', 'Tutar', 'Açıklama'], $txns),
        'excel/takvim.csv'      => backup_csv(['Tarih', 'Müşteri', 'Durum'], $posts),
    ];
}

/** Yedek ZIP'ini oluşturur ve içeriğini döndürür; son yedek tarihini kaydeder */
function backup_create_zip(array $user): string
{
    // Tarih önce yazılır ki yedeğin içinde de yer alsın (geri yüklenen panel son yedeği bilsin)
    set_setting('last_backup_at', date('Y-m-d H:i:s'));
    set_setting('last_backup_by', $user['name']);
    $date = date('Y-m-d');
    $zip = new SimpleZip();
    $zip->add("veritabani-$date.sql", backup_sql_dump($user));
    foreach (backup_readable_sheets() as $name => $csv) {
        $zip->add($name, $csv);
    }
    $zip->add('BENI-OKU.txt', implode("\r\n", [
        'Corventech Panel — yedek (' . date('d.m.Y H:i') . ')',
        '',
        "veritabani-$date.sql : tüm kayıtlar (müşteri, iş, ödeme, takvim, gelir/gider, kullanıcılar)",
        'excel/                  : aynı kayıtlar Excel tablosu olarak (okumak / yazdırmak için)',
        '',
        'Geri yükleme / başka sunucuya taşıma:',
        '1. Boş bir veritabanı oluşturun (karakter seti utf8mb4, karşılaştırma utf8mb4_turkish_ci).',
        "2. veritabani-$date.sql dosyasını phpMyAdmin > İçe Aktar ile yükleyin.",
        '3. Paneli bu veritabanına bağlayıp açın; eksik güncellemeler kendiliğinden uygulanır.',
        '',
        'Bu dosyada müşteri bilgileri vardır; güvenli bir yerde saklayın, kimseyle paylaşmayın.',
    ]));
    return $zip->finish();
}

/**
 * Bellekte ZIP yazıcı (ZipArchive eklentisi gerektirmez). Dosya adları UTF-8.
 */
final class SimpleZip
{
    private string $data = '';
    private array $entries = [];

    public function add(string $name, string $content): void
    {
        $compressed = gzdeflate($content, 6);
        $method = 8;
        if ($compressed === false || strlen($compressed) >= strlen($content)) {
            $compressed = $content;
            $method = 0;
        }
        [$time, $date] = self::dosTime(time());
        $entry = [
            'name' => $name, 'method' => $method, 'time' => $time, 'date' => $date,
            'crc' => crc32($content), 'csize' => strlen($compressed), 'size' => strlen($content), 'offset' => strlen($this->data),
        ];
        // Yerel dosya başlığı; 0x0800 = dosya adı UTF-8
        $this->data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $time, $date,
            $entry['crc'], $entry['csize'], $entry['size'], strlen($name), 0) . $name . $compressed;
        $this->entries[] = $entry;
    }

    public function finish(): string
    {
        $dir = '';
        foreach ($this->entries as $e) {
            $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $e['method'], $e['time'], $e['date'],
                $e['crc'], $e['csize'], $e['size'], strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']) . $e['name'];
        }
        return $this->data . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->entries), count($this->entries), strlen($dir), strlen($this->data), 0);
    }

    /** ZIP'in kullandığı MS-DOS tarih/saat biçimi */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
