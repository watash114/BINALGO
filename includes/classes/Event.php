<?php

require_once __DIR__ . '/../../config/database.php';

class Event
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, d.name as destination_name, d.location as destination_location
             FROM events e
             LEFT JOIN destinations d ON e.destination_id = d.id
             WHERE e.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['destination_id'])) {
            $where[] = "e.destination_id = :destination_id";
            $params[':destination_id'] = $filters['destination_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = "e.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(e.title LIKE :search OR e.description LIKE :search2)";
            $params[':search']  = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM events e {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT e.*, d.name as destination_name
             FROM events e
             LEFT JOIN destinations d ON e.destination_id = d.id
             {$where_clause}
             ORDER BY e.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $events = $stmt->fetchAll();

        return [
            'data'     => $events,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO events (destination_id, title, description, category, event_image, event_location,
                event_start_date, event_end_date, event_start_time, event_end_time,
                organizer, contact_info, max_participants, min_participants, min_age, max_age,
                health_restrictions, requires_guide, duration_hours, price, status, created_by, created_at)
             VALUES (:destination_id, :title, :description, :category, :event_image, :event_location,
                :event_start_date, :event_end_date, :event_start_time, :event_end_time,
                :organizer, :contact_info, :max_participants, :min_participants, :min_age, :max_age,
                :health_restrictions, :requires_guide, :duration_hours, :price, :status, :created_by, NOW())"
        );

        $stmt->execute([
            ':destination_id'      => $data['destination_id'] ?? null,
            ':title'               => $data['title'] ?? '',
            ':description'         => $data['description'] ?? '',
            ':category'            => $data['category'] ?? 'tourism_event',
            ':event_image'         => $data['event_image'] ?? null,
            ':event_location'      => $data['event_location'] ?? null,
            ':event_start_date'    => $data['event_start_date'] ?? null,
            ':event_end_date'      => $data['event_end_date'] ?? null,
            ':event_start_time'    => $data['event_start_time'] ?? null,
            ':event_end_time'      => $data['event_end_time'] ?? null,
            ':organizer'           => $data['organizer'] ?? '',
            ':contact_info'        => $data['contact_info'] ?? '',
            ':max_participants'    => $data['max_participants'] ?? 20,
            ':min_participants'    => $data['min_participants'] ?? 1,
            ':min_age'             => $data['min_age'] ?? 1,
            ':max_age'             => $data['max_age'] ?? null,
            ':health_restrictions' => $data['health_restrictions'] ?? '',
            ':requires_guide'      => $data['requires_guide'] ?? 1,
            ':duration_hours'      => $data['duration_hours'] ?? 1,
            ':price'               => $data['price'] ?? 0,
            ':status'              => $data['status'] ?? 'draft',
            ':created_by'          => $data['created_by'] ?? null,
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

        $stmt = $this->db->prepare("UPDATE events SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM events WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function findByDestination(int $dest_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM events WHERE destination_id = :dest_id ORDER BY created_at DESC"
        );
        $stmt->execute([':dest_id' => $dest_id]);
        return $stmt->fetchAll();
    }

    public function getScheduledEvents(): array
    {
        $stmt = $this->db->query(
            "SELECT e.*, d.name as destination_name, d.location as destination_location
             FROM events e
             LEFT JOIN destinations d ON e.destination_id = d.id
             WHERE e.status = 'published'
             ORDER BY e.created_at DESC"
        );
        return $stmt->fetchAll();
    }

    public function getUpcomingEvents(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, d.name as destination_name, d.location as destination_location
             FROM events e
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN schedules s ON s.event_id = e.id
             WHERE s.start_date >= CURDATE() AND e.status = 'published'
             GROUP BY e.id
             ORDER BY s.start_date ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function checkAvailability(int $schedule_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, e.title as event_title, d.name as destination_name, d.capacity_limit as dest_capacity
             FROM schedules s
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             WHERE s.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $schedule_id]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            return ['available' => false, 'message' => 'Schedule not found.'];
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM bookings
             WHERE schedule_id = :schedule_id AND status IN ('confirmed', 'pending')"
        );
        $stmt->execute([':schedule_id' => $schedule_id]);
        $booked = (int) $stmt->fetch()['count'];

        $max = (int) ($schedule['available_spots'] ?? 20);
        $remaining = $max - $booked;

        return [
            'available' => $remaining > 0,
            'booked'    => $booked,
            'max'       => $max,
            'remaining' => $remaining,
        ];
    }

    public function isFullyBooked(int $schedule_id): bool
    {
        $availability = $this->checkAvailability($schedule_id);
        return !$availability['available'];
    }
}
