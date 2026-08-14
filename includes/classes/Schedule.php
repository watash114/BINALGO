<?php

require_once __DIR__ . '/../../config/database.php';

class Schedule
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, e.title as event_title, e.destination_id, e.max_participants,
                    d.name as destination_name, d.location as destination_location,
                    u.name as guide_name, u.email as guide_email
             FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN users u ON s.guide_id = u.id
             WHERE s.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['event_id'])) {
            $where[] = "s.event_id = :event_id";
            $params[':event_id'] = $filters['event_id'];
        }

        if (!empty($filters['guide_id'])) {
            $where[] = "s.guide_id = :guide_id";
            $params[':guide_id'] = $filters['guide_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "s.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "s.start_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "s.end_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(e.title LIKE :search OR d.name LIKE :search2)";
            $params[':search']  = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT s.*, e.title as event_title, e.max_participants, d.name as destination_name, d.location as destination_location,
                    u.name as guide_name, u.email as guide_email
             FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN users u ON s.guide_id = u.id
             {$where_clause}
             ORDER BY s.start_date ASC, s.start_time ASC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $schedules = $stmt->fetchAll();

        return [
            'data'     => $schedules,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO schedules (event_id, guide_id, start_date, end_date, start_time, end_time, available_spots, status, created_at)
             VALUES (:event_id, :guide_id, :start_date, :end_date, :start_time, :end_time, :available_spots, :status, :created_at)"
        );

        $stmt->execute([
            ':event_id'       => $data['event_id'] ?? null,
            ':guide_id'       => $data['guide_id'] ?? null,
            ':start_date'     => $data['start_date'] ?? '',
            ':end_date'       => $data['end_date'] ?? '',
            ':start_time'     => $data['start_time'] ?? '',
            ':end_time'       => $data['end_time'] ?? '',
            ':available_spots'=> $data['available_spots'] ?? $data['max_participants'] ?? 20,
            ':status'         => $data['status'] ?? 'scheduled',
            ':created_at'     => $data['created_at'] ?? date('Y-m-d H:i:s'),
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

        $stmt = $this->db->prepare("UPDATE schedules SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM schedules WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function findAvailable(array $filters = []): array
    {
        $params = [
            ':today' => date('Y-m-d'),
        ];

        $extra_where = '';
        if (!empty($filters['date'])) {
            $extra_where = "AND s.start_date = :filter_date";
            $params[':filter_date'] = $filters['date'];
        }

        if (!empty($filters['destination_id'])) {
            $extra_where .= " AND d.id = :dest_id";
            $params[':dest_id'] = $filters['destination_id'];
        }

        $stmt = $this->db->prepare(
            "SELECT s.*, e.title as event_title, d.name as destination_name, d.location as destination_location,
                    d.entrance_fee as destination_price, u.name as guide_name
             FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN users u ON s.guide_id = u.id
             WHERE s.start_date >= :today
               AND s.status = 'scheduled'
               AND (s.available_spots - (SELECT COUNT(*) FROM bookings b WHERE b.schedule_id = s.id AND b.status IN ('confirmed','pending'))) > 0
               {$extra_where}
             ORDER BY s.start_date ASC, s.start_time ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function hasGuideConflict(
        int $guide_id,
        string $start_date,
        string $end_date,
        string $start_time,
        string $end_time,
        ?int $exclude_id = null
    ): bool {
        $where  = "guide_id = :guide_id AND status != 'cancelled'";
        $params = [':guide_id' => $guide_id];

        if ($exclude_id !== null) {
            $where .= " AND id != :exclude_id";
            $params[':exclude_id'] = $exclude_id;
        }

        $where .= " AND start_date <= :end_date AND end_date >= :start_date";
        $params[':start_date'] = $start_date;
        $params[':end_date']   = $end_date;

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM schedules WHERE {$where}"
        );
        $stmt->execute($params);
        $overlapping = (int) $stmt->fetch()['count'];

        if ($overlapping === 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM schedules
             WHERE {$where}
               AND start_time < :end_time AND end_time > :start_time"
        );
        $params[':start_time'] = $start_time;
        $params[':end_time']   = $end_time;
        $stmt->execute($params);

        return (int) $stmt->fetch()['count'] > 0;
    }

    public function hasEventConflict(
        int $event_id,
        string $start_date,
        string $start_time,
        ?int $exclude_id = null
    ): bool {
        $where  = "event_id = :event_id AND start_date = :start_date AND status != 'cancelled'";
        $params = [':event_id' => $event_id, ':start_date' => $start_date];

        if ($exclude_id !== null) {
            $where .= " AND id != :exclude_id";
            $params[':exclude_id'] = $exclude_id;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM schedules WHERE {$where}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetch()['count'] > 0;
    }

    public function getGuideSchedule(int $guide_id, string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, e.title as event_title, d.name as destination_name, d.location as destination_location
             FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             WHERE s.guide_id = :guide_id
               AND s.start_date <= :date_start
               AND s.end_date >= :date_end
               AND s.status != 'cancelled'
             ORDER BY s.start_time ASC"
        );
        $stmt->execute([':guide_id' => $guide_id, ':date_start' => $date, ':date_end' => $date]);
        return $stmt->fetchAll();
    }

    public function getDailySchedules(string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, e.title as event_title, d.name as destination_name, d.location as destination_location,
                    u.name as guide_name
             FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN users u ON s.guide_id = u.id
             WHERE s.start_date <= :date_start AND s.end_date >= :date_end
               AND s.status != 'cancelled'
             ORDER BY s.start_time ASC"
        );
        $stmt->execute([':date_start' => $date, ':date_end' => $date]);
        return $stmt->fetchAll();
    }
}
