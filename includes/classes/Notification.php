<?php

require_once __DIR__ . '/../../config/database.php';

class Notification
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "n.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }

        if (!empty($filters['type'])) {
            $where[] = "n.type = :type";
            $params[':type'] = $filters['type'];
        }

        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $where[] = "n.is_read = :is_read";
            $params[':is_read'] = (int)$filters['is_read'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM notifications n {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT n.* FROM notifications n
             {$where_clause}
             ORDER BY n.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        return [
            'data'     => $notifications,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (user_id, title, message, type, is_read, link, priority, scheduled_at, status, audience, recipient_count, batch_id, created_at)
             VALUES (:user_id, :title, :message, :type, :is_read, :link, :priority, :scheduled_at, :status, :audience, :recipient_count, :batch_id, :created_at)"
        );

        $stmt->execute([
            ':user_id'          => $data['user_id'] ?? null,
            ':title'            => $data['title'] ?? '',
            ':message'          => $data['message'] ?? '',
            ':type'             => $data['type'] ?? 'general',
            ':is_read'          => $data['is_read'] ?? 0,
            ':link'             => $data['link'] ?? null,
            ':priority'         => $data['priority'] ?? 'normal',
            ':scheduled_at'     => $data['scheduled_at'] ?? null,
            ':status'           => $data['status'] ?? 'delivered',
            ':audience'         => $data['audience'] ?? null,
            ':recipient_count'  => $data['recipient_count'] ?? 1,
            ':batch_id'         => $data['batch_id'] ?? null,
            ':created_at'       => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Send a notification broadcast to a list of user ids.
     * All rows share one batch_id so the admin history can group them.
     *
     * @return array{count:int, batch_id:string}
     */
    public function sendBroadcast(
        array $user_ids,
        string $title,
        string $message,
        string $type = 'announcement',
        ?string $link = null,
        string $priority = 'normal',
        ?string $scheduled_at = null,
        string $status = 'delivered',
        ?string $audience = null
    ): array {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (empty($user_ids)) {
            return ['count' => 0, 'batch_id' => ''];
        }

        $batch_id = 'b' . bin2hex(random_bytes(16));
        $count = 0;

        foreach ($user_ids as $uid) {
            if ($this->create([
                'user_id'         => $uid,
                'title'           => $title,
                'message'         => $message,
                'type'            => $type,
                'link'            => $link,
                'priority'        => $priority,
                'scheduled_at'    => $scheduled_at,
                'status'          => $status,
                'audience'        => $audience,
                'recipient_count' => count($user_ids),
                'batch_id'        => $batch_id,
            ])) {
                $count++;
            }
        }

        return ['count' => $count, 'batch_id' => $batch_id];
    }

    public function createBulk(int $user_id, string $title, string $message, string $type = 'general', ?string $link = null): ?int
    {
        return $this->create([
            'user_id' => $user_id,
            'title'   => $title,
            'message' => $message,
            'type'    => $type,
            'link'    => $link,
        ]);
    }

    public function sendToAllUsers(string $title, string $message, string $type = 'announcement', ?string $link = null): int
    {
        $users = $this->db->query("SELECT id FROM users WHERE status = 'active'")->fetchAll();
        $count = 0;
        foreach ($users as $user) {
            if ($this->create([
                'user_id' => $user['id'],
                'title'   => $title,
                'message' => $message,
                'type'    => $type,
                'link'    => $link,
            ])) {
                $count++;
            }
        }
        return $count;
    }

    public function sendToUsers(array $user_ids, string $title, string $message, string $type = 'general', ?string $link = null): int
    {
        $count = 0;
        foreach ($user_ids as $uid) {
            if ($this->create([
                'user_id' => (int)$uid,
                'title'   => $title,
                'message' => $message,
                'type'    => $type,
                'link'    => $link,
            ])) {
                $count++;
            }
        }
        return $count;
    }

    public function sendToRole(string $role, string $title, string $message, string $type = 'general', ?string $link = null): int
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role = :role AND status = 'active'");
        $stmt->execute([':role' => $role]);
        $users = $stmt->fetchAll();
        $count = 0;
        foreach ($users as $user) {
            if ($this->create([
                'user_id' => $user['id'],
                'title'   => $title,
                'message' => $message,
                'type'    => $type,
                'link'    => $link,
            ])) {
                $count++;
            }
        }
        return $count;
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

        $stmt = $this->db->prepare("UPDATE notifications SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM notifications WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function deleteForUser(int $user_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM notifications WHERE user_id = :user_id");
        return $stmt->execute([':user_id' => $user_id]);
    }

    public function getForUser(int $user_id, bool $unreadOnly = false, int $limit = 20): array
    {
        $where  = "WHERE n.user_id = :user_id";
        $params = [':user_id' => $user_id];

        if ($unreadOnly) {
            $where .= " AND n.is_read = 0";
        }

        $stmt = $this->db->prepare(
            "SELECT n.* FROM notifications n
             {$where}
             ORDER BY n.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getUnreadCount(int $user_id): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = 0"
        );
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch()['count'];
    }

    public function markAsRead(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function markAllAsRead(int $user_id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0"
        );
        return $stmt->execute([':user_id' => $user_id]);
    }

    public function deleteOld(int $days = 30): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM notifications WHERE created_at < DATE_SUB(db_now(), INTERVAL :days DAY)"
        );
        return $stmt->execute([':days' => $days]);
    }

    public function getStats(): array
    {
        $total = $this->db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
        $unread = $this->db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
        $today = $this->db->query("SELECT COUNT(*) FROM notifications WHERE DATE(created_at) = db_curdate()")->fetchColumn();
        return [
            'total'  => (int)$total,
            'unread' => (int)$unread,
            'today'  => (int)$today,
        ];
    }

    public function getByType(string $type, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT n.*, u.name as user_name, u.email as user_email
             FROM notifications n
             LEFT JOIN users u ON n.user_id = u.id
             WHERE n.type = :type
             ORDER BY n.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':type' => $type]);
        return $stmt->fetchAll();
    }

    // ─── Notification Hooks ──────────────────────────────────────

    public function notifyBookingCreated(int $booking_id): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, u.name as tourist_name, e.title as event_name, s.start_date, s.guide_id,
                    COALESCE(d2.name, d.name) as dest_name, b.visit_date
             FROM bookings b
             JOIN users u ON b.tourist_id = u.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE b.id = :bid LIMIT 1"
        );
        $stmt->execute([':bid' => $booking_id]);
        $b = $stmt->fetch();
        if (!$b) return null;

        $destName = $b['event_name'] ?? $b['dest_name'] ?? 'Destination';
        $visitDate = $b['visit_date'] ?? $b['start_date'] ?? '';

        $notify = $this->create([
            'user_id' => $b['tourist_id'],
            'title'   => 'Booking Created',
            'message' => "Your booking for {$destName} on " . date('M d, Y', strtotime($visitDate)) . " has been created. Please complete payment.",
            'type'    => 'booking',
            'link'    => "/tourist/bookings.php",
        ]);

        if ($b['guide_id']) {
            $this->create([
                'user_id' => $b['guide_id'],
                'title'   => 'New Booking',
                'message' => "{$b['tourist_name']} has booked {$destName} on " . date('M d, Y', strtotime($visitDate)) . ".",
                'type'    => 'booking',
                'link'    => "/guide/tours.php",
            ]);
        }

        return $notify;
    }

    public function notifyBookingConfirmed(int $booking_id): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, e.title as event_name, s.start_date, s.guide_id,
                    COALESCE(d2.name, d.name) as dest_name, b.visit_date
             FROM bookings b
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE b.id = :bid LIMIT 1"
        );
        $stmt->execute([':bid' => $booking_id]);
        $b = $stmt->fetch();
        if (!$b) return null;

        $destName = $b['event_name'] ?? $b['dest_name'] ?? 'Destination';
        $visitDate = $b['visit_date'] ?? $b['start_date'] ?? '';

        $notify = $this->create([
            'user_id' => $b['tourist_id'],
            'title'   => 'Booking Confirmed',
            'message' => "Your booking for {$destName} on " . date('M d, Y', strtotime($visitDate)) . " has been confirmed! Total: ₱" . number_format($b['total_price'], 2) . ".",
            'type'    => 'booking',
            'link'    => "/tourist/bookings.php",
        ]);

        if ($b['guide_id']) {
            $this->create([
                'user_id' => $b['guide_id'],
                'title'   => 'Booking Confirmed',
                'message' => "A booking for {$destName} has been confirmed.",
                'type'    => 'booking',
                'link'    => "/guide/tours.php",
            ]);
        }

        return $notify;
    }

    public function notifyBookingCancelled(int $booking_id): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, e.title as event_name, s.start_date, s.guide_id,
                    COALESCE(d2.name, d.name) as dest_name, b.visit_date
             FROM bookings b
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE b.id = :bid LIMIT 1"
        );
        $stmt->execute([':bid' => $booking_id]);
        $b = $stmt->fetch();
        if (!$b) return null;

        $destName = $b['event_name'] ?? $b['dest_name'] ?? 'Destination';
        $visitDate = $b['visit_date'] ?? $b['start_date'] ?? '';

        $notify = $this->create([
            'user_id' => $b['tourist_id'],
            'title'   => 'Booking Cancelled',
            'message' => "Your booking for {$destName} on " . date('M d, Y', strtotime($visitDate)) . " has been cancelled.",
            'type'    => 'cancellation',
            'link'    => "/tourist/bookings.php",
        ]);

        if ($b['guide_id']) {
            $this->create([
                'user_id' => $b['guide_id'],
                'title'   => 'Booking Cancelled',
                'message' => "A booking for {$destName} has been cancelled.",
                'type'    => 'cancellation',
                'link'    => "/guide/tours.php",
            ]);
        }

        return $notify;
    }

    public function notifyPaymentSuccess(int $booking_id, int $payment_id): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, e.title as event_name, s.start_date, s.guide_id,
                    COALESCE(d2.name, d.name) as dest_name, b.visit_date
             FROM bookings b
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE b.id = :bid LIMIT 1"
        );
        $stmt->execute([':bid' => $booking_id]);
        $b = $stmt->fetch();
        if (!$b) return null;

        $destName = $b['event_name'] ?? $b['dest_name'] ?? 'Destination';

        $notify = $this->create([
            'user_id' => $b['tourist_id'],
            'title'   => 'Payment Successful',
            'message' => "Your payment of ₱" . number_format($b['total_price'], 2) . " for {$destName} has been received. Your booking is confirmed!",
            'type'    => 'payment_success',
            'link'    => "/tourist/confirmation.php?payment_id={$payment_id}",
        ]);

        if ($b['guide_id']) {
            $this->create([
                'user_id' => $b['guide_id'],
                'title'   => 'Payment Received',
                'message' => "Payment has been received for {$destName}.",
                'type'    => 'payment_success',
                'link'    => "/guide/tours.php",
            ]);
        }

        return $notify;
    }

    public function notifyPaymentFailed(int $booking_id, string $reason = ''): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, e.title as event_name
             FROM bookings b
             JOIN schedules s ON b.schedule_id = s.id
             JOIN events e ON s.event_id = e.id
             WHERE b.id = :bid LIMIT 1"
        );
        $stmt->execute([':bid' => $booking_id]);
        $b = $stmt->fetch();
        if (!$b) return null;

        $msg = "Your payment for {$b['event_name']} could not be processed.";
        if ($reason) $msg .= " Reason: {$reason}";

        return $this->create([
            'user_id' => $b['tourist_id'],
            'title'   => 'Payment Failed',
            'message' => $msg,
            'type'    => 'payment_failed',
            'link'    => "/tourist/checkout.php?booking_id={$booking_id}",
        ]);
    }

    public function notifyGuideAssignment(int $schedule_id): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT s.guide_id, e.title as event_name, s.start_date, s.end_date
             FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             WHERE s.id = :schedule_id LIMIT 1"
        );
        $stmt->execute([':schedule_id' => $schedule_id]);
        $schedule = $stmt->fetch();

        if (!$schedule || !$schedule['guide_id']) {
            return null;
        }

        return $this->create([
            'user_id' => $schedule['guide_id'],
            'title'   => 'New Tour Assignment',
            'message' => "You have been assigned to guide {$schedule['event_name']} from " . date('M d, Y', strtotime($schedule['start_date'])) . " to " . date('M d, Y', strtotime($schedule['end_date'])) . ".",
            'type'    => 'assignment',
            'link'    => "/guide/tours.php",
        ]);
    }

    public function notifyRegistrationStatus(int $user_id, string $status): ?int
    {
        $messages = [
            'active'    => 'Your account has been approved and is now active. You can now log in and use the system.',
            'inactive'  => 'Your account has been deactivated. Please contact support for more information.',
            'suspended' => 'Your account has been suspended due to a violation of our policies.',
            'pending'   => 'Your account is currently pending verification. You will be notified once approved.',
        ];

        $title = ucfirst($status) . ' Account';
        $message = $messages[$status] ?? "Your account status has been updated to: {$status}.";

        return $this->create([
            'user_id' => $user_id,
            'title'   => $title,
            'message' => $message,
            'type'    => 'verification',
            'link'    => null,
        ]);
    }

    public function notifyNewFeedback(int $guide_id, int $rating): ?int
    {
        return $this->create([
            'user_id' => $guide_id,
            'title'   => 'New Feedback Received',
            'message' => "You have received a new {$rating}-star rating. Check your feedback dashboard for details.",
            'type'    => 'feedback',
            'link'    => "/guide/feedback.php",
        ]);
    }

    public function notifyEventPublished(int $event_id): int
    {
        $stmt = $this->db->prepare(
            "SELECT e.title, e.event_start_date, e.event_location FROM events e WHERE e.id = :eid LIMIT 1"
        );
        $stmt->execute([':eid' => $event_id]);
        $event = $stmt->fetch();
        if (!$event) return 0;

        $users = $this->db->query("SELECT id FROM users WHERE status = 'active'")->fetchAll();
        $count = 0;
        $dateStr = $event['event_start_date'] ? date('M d, Y', strtotime($event['event_start_date'])) : 'TBA';
        $locStr = $event['event_location'] ? " at {$event['event_location']}" : '';

        foreach ($users as $u) {
            if ($this->create([
                'user_id' => $u['id'],
                'title'   => 'New Event Published',
                'message' => "{$event['title']} is now available! Date: {$dateStr}{$locStr}. Book your spot now!",
                'type'    => 'event_published',
                'link'    => "/tourist/events.php",
            ])) {
                $count++;
            }
        }
        return $count;
    }

    public function notifyEventCancelled(int $event_id): int
    {
        $stmt = $this->db->prepare(
            "SELECT e.title, e.event_start_date FROM events e WHERE e.id = :eid LIMIT 1"
        );
        $stmt->execute([':eid' => $event_id]);
        $event = $stmt->fetch();
        if (!$event) return 0;

        $users = $this->db->query("SELECT id FROM users WHERE status = 'active'")->fetchAll();
        $count = 0;

        foreach ($users as $u) {
            if ($this->create([
                'user_id' => $u['id'],
                'title'   => 'Event Cancelled',
                'message' => "{$event['title']} has been cancelled.",
                'type'    => 'event_cancelled',
                'link'    => "/tourist/events.php",
            ])) {
                $count++;
            }
        }

        $affected = $this->db->prepare(
            "SELECT DISTINCT b.tourist_id FROM bookings b
             JOIN schedules s ON b.schedule_id = s.id
             JOIN events e ON s.event_id = e.id
             WHERE e.id = :eid AND b.status IN ('pending','confirmed')"
        );
        $affected->execute([':eid' => $event_id]);
        foreach ($affected->fetchAll() as $row) {
            $this->create([
                'user_id' => $row['tourist_id'],
                'title'   => 'Event Cancelled',
                'message' => "An event you booked ({$event['title']}) has been cancelled. Your booking will be refunded.",
                'type'    => 'cancellation',
                'link'    => "/tourist/bookings.php",
            ]);
        }

        return $count;
    }

    public function notifyBookingCompleted(int $booking_id): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, e.title as event_name, s.start_date, s.guide_id,
                    COALESCE(d2.name, d.name) as dest_name, b.visit_date
             FROM bookings b
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE b.id = :bid LIMIT 1"
        );
        $stmt->execute([':bid' => $booking_id]);
        $b = $stmt->fetch();
        if (!$b) return null;

        $destName = $b['event_name'] ?? $b['dest_name'] ?? 'Destination';
        $visitDate = $b['visit_date'] ?? $b['start_date'] ?? '';

        $notify = $this->create([
            'user_id' => $b['tourist_id'],
            'title'   => 'Booking Completed',
            'message' => "Your visit to {$destName} on " . date('M d, Y', strtotime($visitDate)) . " has been marked as completed. Thank you for visiting!",
            'type'    => 'booking',
            'link'    => "/tourist/bookings.php",
        ]);

        if ($b['guide_id']) {
            $this->create([
                'user_id' => $b['guide_id'],
                'title'   => 'Booking Completed',
                'message' => "Your tour for {$destName} has been completed.",
                'type'    => 'booking',
                'link'    => "/guide/tours.php",
            ]);
        }

        return $notify;
    }

    public function notifyReply(int $receiverId, string $senderName, string $preview, int $messageId): ?int
    {
        return $this->create([
            'user_id' => $receiverId,
            'title'   => 'New Reply',
            'message' => "{$senderName} replied: {$preview}",
            'type'    => 'message',
            'link'    => null,
        ]);
    }

    public function notifyNewMessage(int $receiverId, string $senderName, string $preview, int $messageId): ?int
    {
        return $this->create([
            'user_id' => $receiverId,
            'title'   => 'New Message',
            'message' => "{$senderName}: {$preview}",
            'type'    => 'message',
            'link'    => null,
        ]);
    }

    public function notifySystemUpdate(string $title, string $message): int
    {
        return $this->sendToAllUsers($title, $message, 'system', null);
    }

    public function notifyAnnouncement(string $title, string $message, ?string $link = null): int
    {
        return $this->sendToAllUsers($title, $message, 'announcement', $link);
    }
}
