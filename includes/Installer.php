<?php
/**
 * Installation state / lock helpers + SQL file runner.
 * Shared by install.php, migrate.php and the Docker entrypoint.
 *
 * Lock semantics
 * --------------
 *  - config/install.lock is written as the LAST step of a successful install.
 *  - While it exists, install.php refuses every request (GET and POST).
 *  - Installs that pre-date the lock (config present + an admin user in the
 *    DB) are detected and locked automatically the first time install.php is
 *    hit, so upgrading does not leave the installer open.
 *  - Recovery / deliberate reinstall: an operator with filesystem access
 *    deletes config/install.lock AND points the installer at an EMPTY database
 *    (the installer never overwrites a database that already has users).
 *
 * config/ is denied to web clients by .htaccess / router.php / the vhost.
 */

class Installer
{
    public const LOCK_FILE = '/config/install.lock';

    public static function lockPath(): string
    {
        return ROOT . self::LOCK_FILE;
    }

    public static function isLocked(): bool
    {
        return is_file(self::lockPath());
    }

    public static function writeLock(array $meta = []): bool
    {
        $data = json_encode(array_merge([
            'installed_at' => date('c'),
            'php_version'  => PHP_VERSION,
        ], $meta), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return self::atomicWrite(self::lockPath(), $data . "\n");
    }

    /**
     * True when this looks like a finished installation that simply has no
     * lock file yet (installed before the lock existed, or the lock write
     * failed after the admin account was created).
     */
    public static function detectExistingInstall(): bool
    {
        $dbConfig = ROOT . '/config/database.php';
        if (!is_file($dbConfig) || !is_file(ROOT . '/config/app.php')) {
            return false;
        }
        try {
            $cfg = require $dbConfig;
            if (!is_array($cfg)) return false;
            $pdo = self::connect($cfg, true);
            return self::databaseHasUsers($pdo);
        } catch (Throwable $_) {
            return false; // unreachable / not initialised -> not installed
        }
    }

    public static function connect(array $cfg, bool $withDb): PDO
    {
        $dsn = "mysql:host={$cfg['host']};port=" . (int)($cfg['port'] ?? 3306)
             . ($withDb ? ";dbname={$cfg['name']}" : '') . ';charset=utf8mb4';
        return new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    }

    public static function databaseHasUsers(PDO $pdo): bool
    {
        $exists = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE table_schema = DATABASE() AND table_name = 'users'"
        )->fetchColumn();
        if (!$exists) return false;
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    /**
     * Execute a .sql file statement by statement. Strips "--" line comments
     * and splits on ";" at end of line (the bundled schema/migration files
     * never contain ";" inside string literals).
     */
    public static function runSqlFile(PDO $pdo, string $path): int
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Cannot read SQL file: {$path}");
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $count = 0;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            // query() + drain: some statements (EXECUTE of a "SELECT 1"
            // placeholder in the migrations) return result sets, and exec()
            // left them pending → "2014 Cannot execute queries while other
            // unbuffered queries are active" on the next statement.
            $res = $pdo->query($stmt);
            if ($res instanceof PDOStatement) {
                do {
                    try { $res->fetchAll(); } catch (PDOException $_) {}
                } while ($res->nextRowset());
                $res->closeCursor();
            }
            $count++;
        }
        return $count;
    }

    /** Write via temp file + rename so a crash never leaves a half-written file. */
    public static function atomicWrite(string $path, string $content): bool
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Replace a top-level `'key' => '...'` scalar in a PHP config file.
     * Uses a callback so the replacement isn't treated as a regex template
     * (a site name containing "$1" or a trailing backslash used to corrupt
     * config/app.php and take the whole site down with a parse error).
     */
    public static function setConfigValue(string $php, string $key, string $value): string
    {
        $export = var_export($value, true);
        $pattern = "/'" . preg_quote($key, '/') . "'\\s*=>\\s*'(?:[^'\\\\]|\\\\.)*'/";
        return preg_replace_callback($pattern, fn() => "'{$key}' => {$export}", $php, 1);
    }
}
