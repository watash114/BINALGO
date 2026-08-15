<?php

require_once __DIR__ . '/../../config/database.php';

class Call
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO calls (caller_id, receiver_id, call_type, status, started_at, ended_at, duration, created_at)
             VALUES (:caller_id, :receiver_id, :call_type, :status, :started_at, :ended_at, :duration, datetime('now'))"
        );
        $stmt->execute([
            ':caller_id'   => $data['caller_id'],
            ':receiver_id' => $data['receiver_id'],
            ':call_type'   => $data['call_type'] ?? 'voice',
            ':status'      => $data['status'] ?? 'completed',
            ':started_at'  => $data['started_at'] ?? date('Y-m-d H:i:s'),
            ':ended_at'    => $data['ended_at'] ?? null,
            ':duration'    => $data['duration'] ?? 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, cl.name as caller_name, r.name as receiver_name
             FROM calls c
             LEFT JOIN users cl ON c.caller_id = cl.id
             LEFT JOIN users r ON c.receiver_id = r.id
             WHERE c.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getHistory(int $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM calls WHERE caller_id = :cid OR receiver_id = :rid"
        );
        $countStmt->execute([':cid' => $userId, ':rid' => $userId]);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $this->db->prepare(
            "SELECT c.*,
                    CASE WHEN c.caller_id = c.caller_id THEN c.receiver_id ELSE c.caller_id END as other_user_id,
                    CASE WHEN c.caller_id = c.caller_id THEN 'outgoing' ELSE 'incoming' END as direction,
                    u.name as other_user_name, u.avatar as other_user_avatar
             FROM calls c
             LEFT JOIN users u ON u.id = CASE WHEN c.caller_id = :ucid THEN c.receiver_id ELSE c.caller_id END
             WHERE c.caller_id = :cid2 OR c.receiver_id = :rid2
             ORDER BY c.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute([':ucid' => $userId, ':cid2' => $userId, ':rid2' => $userId]);
        $data = $stmt->fetchAll();

        foreach ($data as &$row) {
            $row['direction'] = ($row['caller_id'] == $userId) ? 'outgoing' : 'incoming';
        }

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM calls WHERE id = :id AND (caller_id = :uid OR receiver_id = :uid2)");
        return $stmt->execute([':id' => $id, ':uid' => $userId, ':uid2' => $userId]);
    }

    public function getUnreadCallCount(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM calls WHERE receiver_id = :uid AND status IN ('missed', 'ongoing')"
        );
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetch()['cnt'];
    }

    public function getMissedCalls(int $userId, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.name as caller_name
             FROM calls c
             LEFT JOIN users u ON c.caller_id = u.id
             WHERE c.receiver_id = :uid AND c.status = 'missed'
             ORDER BY c.created_at DESC LIMIT {$limit}"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }
}
