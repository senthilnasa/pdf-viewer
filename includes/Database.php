<?php
/**
 * Database singleton wrapper
 * PDF Viewer Platform
 */

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = require ROOT . '/config/database.php';
            $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
            self::$instance = new PDO($dsn, $cfg['username'], $cfg['password'], $cfg['options']);
            // Keep MySQL's NOW()/CURDATE() in the same zone as PHP's date().
            // Many queries mix the two (expiry checks, "today" stats, date
            // ranges); with differing zones reset tokens were born expired
            // and the login rate-limit window was hours off.
            try {
                self::$instance->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
            } catch (Throwable $_) {}
        }
        return self::$instance;
    }

    /**
     * Execute a prepared statement and return the statement object.
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $pdo  = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch all rows. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Fetch a single row. */
    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::query($sql, $params)->fetch();
    }

    /** Fetch a single scalar value. */
    public static function fetchScalar(string $sql, array $params = []): mixed
    {
        return self::query($sql, $params)->fetchColumn();
    }

    /** Insert a row and return the last insert ID. */
    public static function insert(string $sql, array $params = []): string
    {
        self::query($sql, $params);
        return self::getInstance()->lastInsertId();
    }

    /** True for a UNIQUE / PRIMARY KEY violation (SQLSTATE 23000, MySQL 1062). */
    public static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof PDOException
            && ($e->getCode() === '23000' || $e->getCode() === 23000)
            && (int)($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * Run $fn inside a transaction; retries on deadlock / lock-wait timeout
     * (MySQL 1213 / 1205), which InnoDB can raise under concurrent writes.
     */
    public static function transaction(callable $fn, int $retries = 3): mixed
    {
        $pdo = self::getInstance();
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $result = $fn($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
                if (in_array($code, [1213, 1205], true) && $attempt < $retries) {
                    usleep(random_int(20000, 120000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    private function __construct() {}
    private function __clone() {}
}
