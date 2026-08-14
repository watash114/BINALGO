<?php

define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', getenv('BASE_URL') ?: '/Tourism');

if (date_default_timezone_get() === 'UTC') {
    date_default_timezone_set('Asia/Manila');
}

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    private string $host;
    private string $dbname;
    private string $username;
    private string $password;

    private function __construct()
    {
        $dbUrl = getenv('DATABASE_URL');
        if ($dbUrl) {
            $parsed = parse_url($dbUrl);
            $this->host = $parsed['host'] ?? 'localhost';
            $this->dbname = ltrim($parsed['path'] ?? '/tourism_db', '/');
            $this->username = $parsed['user'] ?? 'root';
            $this->password = $parsed['pass'] ?? '';
            $port = $parsed['port'] ?? '3306';
        } else {
            $this->host = getenv('DB_HOST') ?: 'localhost';
            $this->dbname = getenv('DB_NAME') ?: 'tourism_db';
            $this->username = getenv('DB_USER') ?: 'root';
            $this->password = getenv('DB_PASS') ?: '';
            $port = getenv('DB_PORT') ?: '3306';
        }

        try {
            $dsn = "mysql:host={$this->host};port={$port};dbname={$this->dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed: " . $e->getMessage());
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
