<?php

require_once __DIR__ . '/../../config/database.php';

class MessageSettings
{
    private PDO $db;

    private const DEFAULTS = [
        'show_read_receipts'    => 1,
        'show_online_status'    => 1,
        'message_notifications' => 1,
        'sound_notifications'   => 1,
        'message_preview'       => 1,
        'who_can_message'       => 'everyone',
        'blocked_users'         => [],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function get(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM user_message_settings WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::DEFAULTS;
        }

        $row['blocked_users'] = !empty($row['blocked_users']) ? json_decode($row['blocked_users'], true) : [];
        return $row;
    }

    public function update(int $userId, array $data): bool
    {
        $existing = $this->get($userId);
        $isInsert = empty($existing) || !isset($existing['user_id']);

        $fields = [
            'show_read_receipts'    => (int)($data['show_read_receipts'] ?? self::DEFAULTS['show_read_receipts']),
            'show_online_status'    => (int)($data['show_online_status'] ?? self::DEFAULTS['show_online_status']),
            'message_notifications' => (int)($data['message_notifications'] ?? self::DEFAULTS['message_notifications']),
            'sound_notifications'   => (int)($data['sound_notifications'] ?? self::DEFAULTS['sound_notifications']),
            'message_preview'       => (int)($data['message_preview'] ?? self::DEFAULTS['message_preview']),
            'who_can_message'       => $data['who_can_message'] ?? self::DEFAULTS['who_can_message'],
        ];

        if ($isInsert) {
            $fields['user_id'] = $userId;
            $fields['blocked_users'] = '[]';
            $cols = implode(', ', array_keys($fields));
            $placeholders = ':' . implode(', :', array_keys($fields));
            $stmt = $this->db->prepare("INSERT INTO user_message_settings ({$cols}) VALUES ({$placeholders})");
            return $stmt->execute($fields);
        }

        $setParts = [];
        $params = [':uid' => $userId];
        foreach ($fields as $key => $value) {
            $setParts[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }
        $setClause = implode(', ', $setParts);
        $stmt = $this->db->prepare("UPDATE user_message_settings SET {$setClause} WHERE user_id = :uid");
        return $stmt->execute($params);
    }

    public function isBlocked(int $userId, int $otherUserId): bool
    {
        $settings = $this->get($userId);
        $blocked = $settings['blocked_users'] ?? [];
        return in_array($otherUserId, $blocked);
    }

    public function blockUser(int $userId, int $otherUserId): bool
    {
        $settings = $this->get($userId);
        $blocked = $settings['blocked_users'] ?? [];
        if (!in_array($otherUserId, $blocked)) {
            $blocked[] = $otherUserId;
        }
        return $this->updateBlockedUsers($userId, $blocked);
    }

    public function unblockUser(int $userId, int $otherUserId): bool
    {
        $settings = $this->get($userId);
        $blocked = $settings['blocked_users'] ?? [];
        $blocked = array_filter($blocked, fn($id) => $id != $otherUserId);
        return $this->updateBlockedUsers($userId, array_values($blocked));
    }

    private function updateBlockedUsers(int $userId, array $blocked): bool
    {
        $json = json_encode($blocked);
        $stmt = $this->db->prepare(
            "INSERT INTO user_message_settings (user_id, blocked_users) VALUES (:uid, :blocked)
             ON CONFLICT(user_id) DO UPDATE SET blocked_users = :blocked2"
        );
        return $stmt->execute([':uid' => $userId, ':blocked' => $json, ':blocked2' => $json]);
    }

    public function canMessage(int $senderId, int $receiverId): bool
    {
        if ($this->isBlocked($receiverId, $senderId)) {
            return false;
        }

        $settings = $this->get($receiverId);
        if ($settings['who_can_message'] === 'everyone') {
            return true;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) as cnt FROM bookings
             WHERE ((tourist_id = :a AND guide_id = :b) OR (tourist_id = :b AND guide_id = :a))
             AND status NOT IN ('cancelled')"
        );
        $stmt->execute([':a' => $senderId, ':b' => $receiverId]);
        return (int)$stmt->fetch()['cnt'] > 0;
    }

    public function getBlockedUsers(int $userId): array
    {
        $settings = $this->get($userId);
        $blocked = $settings['blocked_users'] ?? [];
        if (empty($blocked)) return [];

        $placeholders = implode(',', array_fill(0, count($blocked), '?'));
        $stmt = $this->db->prepare("SELECT id, name, email, avatar FROM users WHERE id IN ({$placeholders})");
        $stmt->execute($blocked);
        return $stmt->fetchAll();
    }
}
