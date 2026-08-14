<?php

require_once __DIR__ . '/../../config/database.php';

class Booking
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, u.name as tourist_name, u.email as tourist_email,
                    COALESCE(b.visit_date, s.start_date) as start_date,
                    COALESCE(b.visit_date, s.end_date) as end_date,
                    b.visit_time as start_time, b.visit_time as end_time,
                    e.title as event_title, COALESCE(d2.name, d.name) as destination_name, COALESCE(d2.location, d.location) as destination_location
             FROM bookings b
             LEFT JOIN users u ON b.tourist_id = u.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE b.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "b.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['tourist_id'])) {
            $where[] = "b.tourist_id = :tourist_id";
            $params[':tourist_id'] = $filters['tourist_id'];
        }

        if (!empty($filters['schedule_id'])) {
            $where[] = "b.schedule_id = :schedule_id";
            $params[':schedule_id'] = $filters['schedule_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(u.name LIKE :search OR u.email LIKE :search2)";
            $params[':search']  = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM bookings b
             LEFT JOIN users u ON b.tourist_id = u.id
             {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT b.*, u.name as tourist_name, u.email as tourist_email,
                    COALESCE(b.visit_date, s.start_date) as start_date,
                    COALESCE(b.visit_date, s.end_date) as end_date,
                    b.visit_time as start_time, b.visit_time as end_time,
                    e.title as event_title, COALESCE(d2.name, d.name) as destination_name
             FROM bookings b
             LEFT JOIN users u ON b.tourist_id = u.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             {$where_clause}
             ORDER BY b.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();

        return [
            'data'     => $bookings,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO bookings (tourist_id, schedule_id, status, num_participants, total_price, special_requests, created_at)
             VALUES (:tourist_id, :schedule_id, :status, :num_participants, :total_price, :special_requests, :created_at)"
        );

        $stmt->execute([
            ':tourist_id'       => $data['tourist_id'] ?? null,
            ':schedule_id'      => $data['schedule_id'] ?? null,
            ':status'           => $data['status'] ?? 'pending',
            ':num_participants' => $data['num_participants'] ?? $data['participants'] ?? 1,
            ':total_price'      => $data['total_price'] ?? 0,
            ':special_requests' => $data['special_requests'] ?? $data['notes'] ?? '',
            ':created_at'       => $data['created_at'] ?? date('Y-m-d H:i:s'),
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

        $fields[] = "updated_at = NOW()";
        $set_clause = implode(', ', $fields);

        $stmt = $this->db->prepare("UPDATE bookings SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM bookings WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function findByTourist(int $tourist_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, COALESCE(b.visit_date, s.start_date) as start_date,
                    COALESCE(b.visit_date, s.end_date) as end_date,
                    b.visit_time as start_time, b.visit_time as end_time,
                    e.title as event_title, COALESCE(d2.name, d.name) as destination_name, COALESCE(d2.location, d.location) as destination_location
             FROM bookings b
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE b.tourist_id = :tourist_id
             ORDER BY COALESCE(b.visit_date, s.start_date) DESC"
        );
        $stmt->execute([':tourist_id' => $tourist_id]);
        return $stmt->fetchAll();
    }

    public function findBySchedule(int $schedule_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, u.name as tourist_name, u.email as tourist_email, u.phone as tourist_phone
             FROM bookings b
             LEFT JOIN users u ON b.tourist_id = u.id
             WHERE b.schedule_id = :schedule_id
             ORDER BY b.created_at DESC"
        );
        $stmt->execute([':schedule_id' => $schedule_id]);
        return $stmt->fetchAll();
    }

    public function hasConflict(int $tourist_id, int $schedule_id): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM bookings b
             JOIN schedules s1 ON b.schedule_id = s1.id
             JOIN schedules s2 ON s2.id = :schedule_id2
             JOIN events e1 ON s1.event_id = e1.id
             JOIN events e2 ON s2.event_id = e2.id
             WHERE b.tourist_id = :tourist_id
               AND b.status IN ('confirmed', 'pending')
               AND s1.start_date <= s2.end_date
               AND s1.end_date >= s2.start_date"
        );
        $stmt->execute([
            ':tourist_id'   => $tourist_id,
            ':schedule_id2' => $schedule_id,
        ]);
        return (int) $stmt->fetch()['count'] > 0;
    }

    public function cancel(int $id): bool
    {
        return $this->update($id, ['status' => 'cancelled']);
    }

    public function confirm(int $id): bool
    {
        return $this->update($id, ['status' => 'confirmed']);
    }

    public function complete(int $id): bool
    {
        return $this->update($id, ['status' => 'completed']);
    }

    public function getStats(): array
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status IN ('confirmed','completed') THEN total_price ELSE 0 END) as total_revenue
             FROM bookings"
        );
        return $stmt->fetch();
    }
}
