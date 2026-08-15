<?php

define('BASE_PATH', dirname(__DIR__));
$baseUrl = getenv('BASE_URL') ?: '';
define('BASE_URL', rtrim($baseUrl, '/'));

if (date_default_timezone_get() === 'UTC') {
    date_default_timezone_set('Asia/Manila');
}

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    private function __construct()
    {
        try {
            $dbHost = getenv('DB_HOST');
            $dbName = getenv('DB_NAME');

            if ($dbHost && $dbName && defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $dbUser = getenv('DB_USER') ?: 'root';
                $dbPass = getenv('DB_PASS') ?: '';
                $dbPort = getenv('DB_PORT') ?: '3306';
                $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ];
                $this->connection = new PDO($dsn, $dbUser, $dbPass, $options);
            } else {
                $dbPath = BASE_PATH . '/database/tourism.db';
                $isNew = !file_exists($dbPath);
                $dsn = "sqlite:{$dbPath}";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                $this->connection = new PDO($dsn, null, null, $options);
                $this->connection->exec("PRAGMA journal_mode=WAL");
                $this->connection->exec("PRAGMA foreign_keys=ON");

                if ($isNew) {
                    $this->initSQLite();
                }
            }
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed: " . $e->getMessage());
        }
    }

    private function initSQLite(): void
    {
        $sql = file_get_contents(BASE_PATH . '/database/tourism_sqlite.sql');
        if ($sql) {
            $this->connection->exec($sql);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
