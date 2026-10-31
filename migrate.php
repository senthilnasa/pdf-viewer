<?php
/**
 * Apply database-migrations.sql to an existing installation (idempotent).
 *
 *   php migrate.php
 *
 * CLI only. Used by the Docker entrypoint on every start so upgraded images
 * get new tables/columns (previously database.sql only ran on an empty volume
 * and nothing ever applied the migrations, so upgrades hit SQL errors).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT', __DIR__);
require ROOT . '/includes/Installer.php';

$dbConfig = ROOT . '/config/database.php';
if (!is_file($dbConfig)) {
    fwrite(STDERR, "[migrate] config/database.php not found — nothing to migrate.\n");
    exit(0);
}

$cfg = require $dbConfig;
$attempts = (int)(getenv('MIGRATE_RETRIES') ?: 10);

for ($i = 1; $i <= $attempts; $i++) {
    try {
        $pdo = Installer::connect($cfg, true);
        break;
    } catch (Throwable $e) {
        if ($i === $attempts) {
            fwrite(STDERR, "[migrate] Cannot connect to database: {$e->getMessage()}\n");
            exit(1);
        }
        sleep(min(2 * $i, 10));
    }
}

$hasSchema = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = 'users'"
)->fetchColumn();
if (!$hasSchema) {
    echo "[migrate] Database not initialised yet (run install.php) — skipping.\n";
    exit(0);
}

try {
    $n = Installer::runSqlFile($pdo, ROOT . '/database-migrations.sql');
    echo "[migrate] Applied database-migrations.sql ({$n} statements).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[migrate] Migration failed: {$e->getMessage()}\n");
    exit(1);
}
