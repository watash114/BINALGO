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
                    $sql = file_get_contents(BASE_PATH . '/database/tourism_sqlite.sql');
                    if ($sql) {
                        $this->connection->exec($sql);
                    }
                }
                $this->migrate();
                $this->seed();
            }
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed: " . $e->getMessage());
        }
    }

    private function migrate(): void
    {
        $migFile = BASE_PATH . '/database/migration_add_missing_columns.sql';
        if (!file_exists($migFile)) return;

        $tables = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $existingCols = [];
        foreach ($tables as $t) {
            $rows = $this->connection->query("PRAGMA table_info(\"{$t}\")")->fetchAll();
            $existingCols[$t] = array_column($rows, 'name');
        }

        $lines = file($migFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $pending = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) continue;
            $pending .= ' ' . $line;
                if (str_ends_with($line, ';')) {
                    $pending = trim($pending);
                    if (preg_match('/ALTER TABLE (\w+) ADD COLUMN (\w+)/i', $pending, $m)) {
                        $table = $m[1];
                        $col = $m[2];
                        if (!isset($existingCols[$table]) || !in_array($col, $existingCols[$table])) {
                            try {
                                $this->connection->exec($pending);
                                $existingCols[$table][] = $col;
                            } catch (PDOException $e) {
                                error_log("Migration skip: {$table}.{$col} - " . $e->getMessage());
                            }
                        }
                    } elseif (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $pending, $m)) {
                        $table = $m[1];
                        if (!in_array($table, $tables)) {
                            try {
                                $this->connection->exec($pending);
                                $tables[] = $table;
                                $existingCols[$table] = [];
                            } catch (PDOException $e) {
                                error_log("Migration skip create table: {$table} - " . $e->getMessage());
                            }
                        }
                    }
                    $pending = '';
                }
        }
    }

    private function seed(): void
    {
        $adminEmail = 'admin@tourism.com';
        $stmt = $this->connection->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
        $stmt->execute([':email' => $adminEmail]);
        if ((int)$stmt->fetchColumn() === 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $this->connection->prepare(
                "INSERT INTO users (username, email, password, role, name, gender, age, phone, status, created_at)
                 VALUES (:uname, :email, :pw, 'admin', 'Administrator', 'male', 30, '09123456789', 'active', datetime('now'))"
            )->execute([':uname' => 'admin', ':email' => $adminEmail, ':pw' => $hash]);
            error_log("Seeded admin user: {$adminEmail}");
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
