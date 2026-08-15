<?php

require_once __DIR__ . '/../../config/database.php';

class ActivityLog
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function log(int $user_id, string $action, ?string $details = null): bool
    {
        try {
            $db = Database::getInstance()->getConnection();

            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $stmt = $db->prepare(
                "INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
                 VALUES (:user_id, :action, :details, :ip_address, db_now())"
            );

            return $stmt->execute([
                ':user_id'    => $user_id,
                ':action'     => $action,
                ':details'    => $details,
                ':ip_address' => $ip_address,
            ]);
        } catch (PDOException $e) {
            error_log("Activity log failed: " . $e->getMessage());
            return false;
        }
    }

    public function getRecent(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT al.*, u.name as user_name, u.email as user_email, u.role as user_role
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getByUser(int $user_id, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT al.*, u.name as user_name, u.email as user_email
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE al.user_id = :user_id
             ORDER BY al.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':user_id' => $user_id]);
        return $stmt->fetchAll();
    }

    public function getStats(): array
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total_logs,
                SUM(CASE WHEN DATE(created_at) = db_curdate() THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN created_at >= db_date_sub(, 'INTERVAL  ') THEN 1 ELSE 0 END) as this_week,
                SUM(CASE WHEN created_at >= db_date_sub(, 'INTERVAL  ') THEN 1 ELSE 0 END) as this_month
             FROM activity_logs"
        );
        $stats = $stmt->fetch();

        return [
            'total_logs' => (int) ($stats['total_logs'] ?? 0),
            'today'      => (int) ($stats['today'] ?? 0),
            'this_week'  => (int) ($stats['this_week'] ?? 0),
            'this_month' => (int) ($stats['this_month'] ?? 0),
        ];
    }

    public function clearOld(int $days = 90): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM activity_logs WHERE created_at < DATE_SUB(db_now(), INTERVAL :days DAY)"
        );
        return $stmt->execute([':days' => $days]);
    }
}
