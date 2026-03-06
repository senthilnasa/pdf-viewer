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

    private function __construct() {}
    private function __clone() {}
}
