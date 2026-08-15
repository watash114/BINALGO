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
        $this->migrate();
    }

    private function migrate(): void
    {
        $cols = function (string $table): array {
            $rows = $this->connection->query("PRAGMA table_info({$table})")->fetchAll();
            return array_column($rows, 'name');
        };

        $addCol = function (string $table, string $col, string $type) use ($cols) {
            if (!in_array($col, $cols($table))) {
                $this->connection->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
            }
        };

        $addCol('payments', 'tourist_id', 'INTEGER');
        $addCol('payments', 'tax', 'REAL DEFAULT 0');
        $addCol('payments', 'service_fee', 'REAL DEFAULT 0');
        $addCol('payments', 'total_amount', 'REAL DEFAULT 0');
        $addCol('payments', 'payment_method', "VARCHAR(50) DEFAULT 'card'");
        $addCol('payments', 'card_last_four', 'VARCHAR(4)');
        $addCol('payments', 'card_brand', 'VARCHAR(50)');
        $addCol('payments', 'reference_number', 'VARCHAR(100)');
        $addCol('payments', 'transaction_id', 'VARCHAR(255)');
        $addCol('payments', 'payment_status', "VARCHAR(20) DEFAULT 'pending'");
        $addCol('payments', 'payment_date', 'DATETIME');

        $addCol('bookings', 'destination_id', 'INTEGER');
        $addCol('bookings', 'guide_id', 'INTEGER');
        $addCol('bookings', 'visit_date', 'DATE');
        $addCol('bookings', 'payment_status', "VARCHAR(20) DEFAULT 'pending'");

        $addCol('events', 'event_start_date', 'DATE');
        $addCol('events', 'event_start_time', 'TIME');
        $addCol('events', 'event_end_date', 'DATE');
        $addCol('events', 'event_end_time', 'TIME');
        $addCol('events', 'image', 'VARCHAR(500)');

        $addCol('notifications', 'priority', "VARCHAR(20) DEFAULT 'normal'");
        $addCol('notifications', 'scheduled_at', 'DATETIME');
        $addCol('notifications', 'status', "VARCHAR(20) DEFAULT 'sent'");
        $addCol('notifications', 'audience', "VARCHAR(50) DEFAULT 'user'");
        $addCol('notifications', 'recipient_count', 'INTEGER DEFAULT 1');
        $addCol('notifications', 'batch_id', 'VARCHAR(100)');

        $addCol('guide_payouts', 'booking_id', 'INTEGER');
        $addCol('guide_payouts', 'payment_id', 'INTEGER');
        $addCol('guide_payouts', 'commission_amount', 'REAL DEFAULT 0');
        $addCol('guide_payouts', 'net_earning', 'REAL DEFAULT 0');
        $addCol('guide_payouts', 'payout_status', "VARCHAR(20) DEFAULT 'pending'");
        $addCol('guide_payouts', 'reference_number', 'VARCHAR(100)');
        $addCol('guide_payouts', 'approved_by', 'INTEGER');
        $addCol('guide_payouts', 'approved_at', 'DATETIME');
        $addCol('guide_payouts', 'paid_at', 'DATETIME');

        $addCol('calls', 'caller_id', 'INTEGER');
        $addCol('calls', 'receiver_id', 'INTEGER');
        $addCol('calls', 'status', "VARCHAR(20) DEFAULT 'ringing'");
        $addCol('calls', 'started_at', 'DATETIME');
        $addCol('calls', 'ended_at', 'DATETIME');
        $addCol('calls', 'duration', 'INTEGER DEFAULT 0');

        $addCol('message_settings', 'who_can_message', "VARCHAR(50) DEFAULT 'everyone'");
        $addCol('message_settings', 'blocked_users', "TEXT DEFAULT '[]'");
        $addCol('message_settings', 'show_online', 'INTEGER DEFAULT 1');
        $addCol('message_settings', 'allow_messages', "VARCHAR(50) DEFAULT 'everyone'");
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
