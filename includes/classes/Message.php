<?php

require_once __DIR__ . '/../../config/database.php';

class Message
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, s.name as sender_name, r.name as receiver_name,
                    rm.message as reply_message, rs.name as reply_sender_name
             FROM messages m
             LEFT JOIN users s ON m.sender_id = s.id
             LEFT JOIN users r ON m.receiver_id = r.id
             LEFT JOIN messages rm ON m.reply_to_message_id = rm.id
             LEFT JOIN users rs ON rm.sender_id = rs.id
             WHERE m.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 50): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['sender_id'])) {
            $where[] = "m.sender_id = :sender_id";
            $params[':sender_id'] = $filters['sender_id'];
        }

        if (!empty($filters['receiver_id'])) {
            $where[] = "m.receiver_id = :receiver_id";
            $params[':receiver_id'] = $filters['receiver_id'];
        }

        if (!empty($filters['is_read'])) {
            $where[] = "m.is_read = :is_read";
            $params[':is_read'] = $filters['is_read'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM messages m {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT m.*, s.name as sender_name, r.name as receiver_name
             FROM messages m
             LEFT JOIN users s ON m.sender_id = s.id
             LEFT JOIN users r ON m.receiver_id = r.id
             {$where_clause}
             ORDER BY m.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        return [
            'data'     => $messages,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO messages (sender_id, receiver_id, message, reply_to_message_id, file_url, is_read, created_at)
             VALUES (:sender_id, :receiver_id, :message, :reply_to_message_id, :file_url, :is_read, :created_at)"
        );

        $stmt->execute([
            ':sender_id'           => $data['sender_id'] ?? null,
            ':receiver_id'         => $data['receiver_id'] ?? null,
            ':message'             => $data['message'] ?? '',
            ':reply_to_message_id' => $data['reply_to_message_id'] ?? null,
            ':file_url'            => $data['file_url'] ?? null,
            ':is_read'             => $data['is_read'] ?? 0,
            ':created_at'          => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $fields[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        $set_clause = implode(', ', $fields);

        $stmt = $this->db->prepare("UPDATE messages SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM messages WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function softDelete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE messages SET is_deleted = 1 WHERE id = :id AND sender_id = :uid"
        );
        return $stmt->execute([':id' => $id, ':uid' => $userId]);
    }

    public function getConversation(int $user1_id, int $user2_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, s.name as sender_name, r.name as receiver_name,
                    rm.id as reply_id, rm.message as reply_message, rm.is_deleted as reply_deleted,
                    rs.name as reply_sender_name
             FROM messages m
             LEFT JOIN users s ON m.sender_id = s.id
             LEFT JOIN users r ON m.receiver_id = r.id
             LEFT JOIN messages rm ON m.reply_to_message_id = rm.id
             LEFT JOIN users rs ON rm.sender_id = rs.id
             WHERE ((m.sender_id = :user1a AND m.receiver_id = :user2a)
                OR (m.sender_id = :user2b AND m.receiver_id = :user1b))
             ORDER BY m.created_at ASC"
        );
        $stmt->execute([':user1a' => $user1_id, ':user2a' => $user2_id, ':user2b' => $user2_id, ':user1b' => $user1_id]);
        return $stmt->fetchAll();
    }

    public function getUnreadCount(int $user_id): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM messages WHERE receiver_id = :user_id AND is_read = 0"
        );
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch()['count'];
    }

    public function markAsRead(int $message_id): bool
    {
        $stmt = $this->db->prepare("UPDATE messages SET is_read = 1 WHERE id = :id");
        return $stmt->execute([':id' => $message_id]);
    }

    public function markConversationAsRead(int $sender_id, int $receiver_id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE messages SET is_read = 1 WHERE sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0"
        );
        return $stmt->execute([':sender_id' => $sender_id, ':receiver_id' => $receiver_id]);
    }

    public function getRecentChatters(int $user_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.avatar,
                    last_msg.message as last_message,
                    last_msg.created_at as last_message_time,
                    (SELECT COUNT(*) FROM messages
                     WHERE sender_id = u.id AND receiver_id = :user_id2 AND is_read = 0) as unread_count
             FROM users u
             INNER JOIN (
                 SELECT
                     CASE
                         WHEN sender_id = :user_id3 THEN receiver_id
                         ELSE sender_id
                     END as other_user_id,
                     MAX(id) as max_id
                 FROM messages
                 WHERE sender_id = :user_id4 OR receiver_id = :user_id5
                 GROUP BY other_user_id
             ) chatters ON u.id = chatters.other_user_id
             INNER JOIN messages last_msg ON last_msg.id = chatters.max_id
             ORDER BY last_msg.created_at DESC"
        );
        $stmt->execute([
            ':user_id2' => $user_id,
            ':user_id3' => $user_id,
            ':user_id4' => $user_id,
            ':user_id5' => $user_id,
        ]);
        return $stmt->fetchAll();
    }

    public function sendMessage(int $sender_id, int $receiver_id, string $message, ?string $file_url = null, ?int $reply_to_message_id = null): ?int
    {
        $id = $this->create([
            'sender_id'           => $sender_id,
            'receiver_id'         => $receiver_id,
            'message'             => $message,
            'reply_to_message_id' => $reply_to_message_id,
            'file_url'            => $file_url,
            'is_read'             => 0,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        if ($id) {
            $this->syncConversation($sender_id, $receiver_id, $message);
        }

        return $id;
    }

    public function getMessages(int $user_id, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, s.name as sender_name, r.name as receiver_name
             FROM messages m
             LEFT JOIN users s ON m.sender_id = s.id
             LEFT JOIN users r ON m.receiver_id = r.id
             WHERE m.sender_id = :user_id OR m.receiver_id = :user_id2
             ORDER BY m.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':user_id' => $user_id, ':user_id2' => $user_id]);
        return $stmt->fetchAll();
    }

    // ─── Conversation Management ──────────────────────────────────

    public function syncConversation(int $user1_id, int $user2_id, string $lastMessage): void
    {
        $u1 = min($user1_id, $user2_id);
        $u2 = max($user1_id, $user2_id);

        $stmt = $this->db->prepare(
            "INSERT INTO conversations (user1_id, user2_id, last_message, last_activity, deleted_by_user1, deleted_by_user2)
             VALUES (:u1, :u2, :msg, NOW(), 0, 0)
             ON DUPLICATE KEY UPDATE last_message = :msg2, last_activity = NOW(),
                 deleted_by_user1 = 0, deleted_by_user2 = 0"
        );
        $stmt->execute([':u1' => $u1, ':u2' => $u2, ':msg' => $lastMessage, ':msg2' => $lastMessage]);
    }

    public function getConversations(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*,
                    CASE WHEN c.user1_id = :uid1 THEN c.user2_id ELSE c.user1_id END as other_user_id,
                    u.name as other_user_name, u.avatar as other_user_avatar,
                    (SELECT COUNT(*) FROM messages
                     WHERE sender_id = CASE WHEN c.user1_id = :uid2 THEN c.user2_id ELSE c.user1_id END
                       AND receiver_id = :uid3 AND is_read = 0) as unread_count
             FROM conversations c
             JOIN users u ON u.id = CASE WHEN c.user1_id = :uid4 THEN c.user2_id ELSE c.user1_id END
             WHERE (c.user1_id = :uid5 AND c.deleted_by_user1 = 0)
                OR (c.user2_id = :uid6 AND c.deleted_by_user2 = 0)
             ORDER BY c.last_activity DESC"
        );
        $stmt->execute([
            ':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId,
            ':uid4' => $userId, ':uid5' => $userId, ':uid6' => $userId,
        ]);
        return $stmt->fetchAll();
    }

    public function deleteConversation(int $userId, int $otherUserId, string $mode = 'me'): bool
    {
        $u1 = min($userId, $otherUserId);
        $u2 = max($userId, $otherUserId);

        if ($mode === 'everyone') {
            $stmt = $this->db->prepare("DELETE FROM conversations WHERE user1_id = :u1 AND user2_id = :u2");
            return $stmt->execute([':u1' => $u1, ':u2' => $u2]);
        }

        $col = $userId === $u1 ? 'deleted_by_user1' : 'deleted_by_user2';
        $stmt = $this->db->prepare(
            "UPDATE conversations SET {$col} = 1 WHERE user1_id = :u1 AND user2_id = :u2"
        );
        return $stmt->execute([':u1' => $u1, ':u2' => $u2]);
    }

    public function getReplyPreview(int $messageId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.message, m.is_deleted, u.name as sender_name
             FROM messages m
             LEFT JOIN users u ON m.sender_id = u.id
             WHERE m.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $messageId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
}
