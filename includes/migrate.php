<?php
/**
 * Veritabanı güncellemeleri (migrations/NNN_*.sql).
 *
 * Uygulanan son güncellemenin numarası settings.db_version'da tutulur. Kod, DB_VERSION'dan eski bir
 * veritabanıyla çalışırsa panel ilk açılışta bekleyen güncellemeleri kendiliğinden uygular (auto_migrate).
 */

const DB_VERSION = 6;

function db_version(): int
{
    try {
        return (int) (q_val("SELECT `value` FROM settings WHERE `key` = 'db_version'") ?? 0);
    } catch (PDOException $e) {
        return 0;
    }
}

/** Uygulanmamış güncelleme dosyaları: [numara => yol] */
function pending_migrations(): array
{
    $current = db_version();
    $out = [];
    foreach (glob(APP_ROOT . '/migrations/*.sql') ?: [] as $file) {
        $n = (int) basename($file);
        if ($n > $current) {
            $out[$n] = $file;
        }
    }
    ksort($out);
    return $out;
}

/** SQL dosyasını ifadelere böler (yorum satırlarını atar; ifadeler ';' ile satır sonunda biter) */
function split_sql(string $sql): array
{
    $lines = array_filter(preg_split('/\R/', $sql), fn($l) => !preg_match('/^\s*--/', $l));
    $statements = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines));
    return array_values(array_filter(array_map('trim', $statements), fn($s) => $s !== ''));
}

/**
 * Kod veritabanından yeniyse bekleyen güncellemeleri kendiliğinden uygular (her istekte tek ucuz sorgu).
 * Aynı anda gelen istekler MySQL kilidiyle sıraya girer; böylece bir güncelleme iki kez çalışmaz.
 */
function auto_migrate(): void
{
    if (db_version() >= DB_VERSION) {
        return;
    }
    // Hiç tablo yoksa (yeni ve boş veritabanı) database.sql ile kur: phpMyAdmin'e gerek kalmaz
    if (!q_val("SHOW TABLES LIKE 'settings'") && !q_val("SHOW TABLES LIKE 'customers'")) {
        if (q_val("SELECT GET_LOCK('ctpanel_migrate', 30)")) {
            try {
                if (!q_val("SHOW TABLES LIKE 'settings'")) {
                    foreach (split_sql((string) file_get_contents(APP_ROOT . '/database.sql')) as $stmt) {
                        db()->exec($stmt);
                    }
                }
            } finally {
                q("SELECT RELEASE_LOCK('ctpanel_migrate')");
            }
        }
        return;
    }
    if (!q_val("SELECT GET_LOCK('ctpanel_migrate', 30)")) {
        return;
    }
    try {
        if (db_version() < DB_VERSION) {
            run_migrations();
        }
    } catch (Throwable $e) {
        error_log('Corventech migration failed: ' . $e->getMessage());
        http_response_code(500);
        exit('<!doctype html><meta charset="utf-8"><title>Güncelleme hatası</title><div style="font-family:system-ui;max-width:560px;margin:15vh auto;padding:24px">'
            . '<h1 style="font-size:20px">Veritabanı güncellemesi tamamlanamadı</h1><p>Sunucu hata kaydına bakın'
            . (config('debug') ? ': ' . htmlspecialchars($e->getMessage()) : '.') . '</p></div>');
    } finally {
        q("SELECT RELEASE_LOCK('ctpanel_migrate')");
    }
}

/** Bekleyen güncellemeleri sırayla uygular. Dönen: uygulanan dosya adları */
function run_migrations(): array
{
    $done = [];
    // Zaten uygulanmış adımlar (tablo/sütun/anahtar zaten var ya da zaten silinmiş) atlanır
    $alreadyApplied = [1050, 1060, 1061, 1091, 1022, 1826];
    foreach (pending_migrations() as $n => $file) {
        foreach (split_sql((string) file_get_contents($file)) as $stmt) {
            try {
                db()->exec($stmt);
            } catch (PDOException $e) {
                if (!in_array((int) ($e->errorInfo[1] ?? 0), $alreadyApplied, true)) {
                    throw $e;
                }
            }
        }
        set_setting('db_version', (string) $n);
        $done[] = basename($file);
    }
    return $done;
}
