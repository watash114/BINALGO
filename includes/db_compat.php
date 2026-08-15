<?php

class DBCompat
{
    private static ?self $instance = null;
    private string $driver;

    private function __construct()
    {
        $this->driver = Database::getInstance()->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function now(): string
    {
        return $this->driver === 'sqlite' ? "datetime('now')" : "NOW()";
    }

    public function curdate(): string
    {
        return $this->driver === 'sqlite' ? "date('now')" : "CURDATE()";
    }

    public function dateSub(string $col, string $interval): string
    {
        if ($this->driver === 'sqlite') {
            $interval = str_replace(['INTERVAL ', ' DAY'], ['', ' days'], $interval);
            $interval = str_replace(' MONTH', ' months', $interval);
            $interval = str_replace(' YEAR', ' years', $interval);
            $interval = str_replace(' HOUR', ' hours', $interval);
            $interval = str_replace(' MINUTE', ' minutes', $interval);
            return "date({$col}, '-{$interval}')";
        }
        return "DATE_SUB({$col}, {$interval})";
    }

    public function dateAdd(string $col, string $interval): string
    {
        if ($this->driver === 'sqlite') {
            $interval = str_replace(['INTERVAL ', ' DAY'], ['', ' days'], $interval);
            $interval = str_replace(' MONTH', ' months', $interval);
            $interval = str_replace(' HOUR', ' hours', $interval);
            $interval = str_replace(' MINUTE', ' minutes', $interval);
            return "date({$col}, '+{$interval}')";
        }
        return "DATE_ADD({$col}, {$interval})";
    }

    public function dateFormat(string $col, string $format): string
    {
        if ($this->driver === 'sqlite') {
            $map = [
                '%Y' => '%Y', '%m' => '%m', '%d' => '%d',
                '%H' => '%H', '%i' => '%M', '%s' => '%S',
                '%y' => '%y', '%M' => '%m', '%Y-%m' => '%Y-%m',
                '%Y-%m-%d' => '%Y-%m-%d',
            ];
            $fmt = $format;
            foreach ($map as $mysql => $sqlite) {
                $fmt = str_replace($mysql, $sqlite, $fmt);
            }
            return "strftime('{$fmt}', {$col})";
        }
        return "DATE_FORMAT({$col}, '{$format}')";
    }

    public function month(string $col): string
    {
        if ($this->driver === 'sqlite') {
            return "strftime('%m', {$col})";
        }
        return "MONTH({$col})";
    }

    public function isSQLite(): bool
    {
        return $this->driver === 'sqlite';
    }

    public function upsert(string $table, string $keyCol, array $data, string $updateCols): string
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ":{$c}", $cols);
        $colList = implode(', ', $cols);
        $valList = implode(', ', $placeholders);

        if ($this->driver === 'sqlite') {
            return "INSERT INTO {$table} ({$colList}) VALUES ({$valList}) ON CONFLICT({$keyCol}) DO UPDATE SET {$updateCols}";
        }
        return "INSERT INTO {$table} ({$colList}) VALUES ({$valList}) ON DUPLICATE KEY UPDATE {$updateCols}";
    }
}

function db_now(): string
{
    return DBCompat::getInstance()->now();
}

function db_curdate(): string
{
    return DBCompat::getInstance()->curdate();
}

function db_date_sub(string $col, string $interval): string
{
    return DBCompat::getInstance()->dateSub($col, $interval);
}

function db_date_add(string $col, string $interval): string
{
    return DBCompat::getInstance()->dateAdd($col, $interval);
}

function db_date_format(string $col, string $format): string
{
    return DBCompat::getInstance()->dateFormat($col, $format);
}

function db_month(string $col): string
{
    return DBCompat::getInstance()->month($col);
}
