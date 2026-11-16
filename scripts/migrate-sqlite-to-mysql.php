<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
define('SQLITE_PATH', APP_ROOT . '/db.sqlite3');
require_once APP_ROOT . '/includes/functions.php';
loadAppEnvironment(APP_ROOT . '/.env');

if (!is_file(SQLITE_PATH)) {
    fwrite(STDERR, 'SQLite source database not found: ' . SQLITE_PATH . PHP_EOL);
    exit(1);
}

$source = new PDO('sqlite:' . SQLITE_PATH);
$source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$target = getDb();
ensureAppDatabase(false);

$tables = ['images', 'booking_requests', 'services', 'hero_slides', 'packages'];
$target->beginTransaction();

try {
    foreach ($tables as $table) {
        $count = (int) $target->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException("MySQL table '$table' is not empty. Migration requires an empty MySQL database.");
        }
    }

    $sourceTables = $source->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    $sourceTableSet = array_fill_keys($sourceTables, true);
    $migratedCounts = [];
    $seededDefaultPackages = false;

    foreach ($tables as $table) {
        if (!isset($sourceTableSet[$table])) {
            $migratedCounts[$table] = 0;
            continue;
        }

        $sourceColumns = $source->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $targetColumns = $target->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_values(array_intersect($targetColumns, $sourceColumns));
        if ($columns === []) {
            throw new RuntimeException("No shared columns found for table '$table'.");
        }

        $columnSql = implode(', ', array_map(static fn(string $column): string => "`$column`", $columns));
        $placeholders = implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns));
        $insert = $target->prepare("INSERT INTO `$table` ($columnSql) VALUES ($placeholders)");
        $rows = $source->query("SELECT * FROM `$table`")->fetchAll();

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[':' . $column] = $row[$column];
            }
            $insert->execute($values);
        }
        $migratedCounts[$table] = count($rows);
    }

    if (!isset($sourceTableSet['packages'])) {
        seedDefaultPackages($target);
        $seededDefaultPackages = true;
    }

    $target->commit();
    foreach ($migratedCounts as $table => $count) {
        fwrite(STDOUT, sprintf("%s: %d rows migrated\n", $table, $count));
    }
    if ($seededDefaultPackages) {
        fwrite(STDOUT, "packages: original three defaults seeded (SQLite table not found)\n");
    }
} catch (Throwable $error) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
