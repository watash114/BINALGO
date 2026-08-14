<?php

require_once __DIR__ . '/../../config/database.php';

class Feedback
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, u.name as tourist_name, u.email as tourist_email
             FROM feedback f
             LEFT JOIN users u ON f.tourist_id = u.id
             WHERE f.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['guide_id'])) {
            $where[] = "f.guide_id = :guide_id";
            $params[':guide_id'] = $filters['guide_id'];
        }

        if (!empty($filters['tourist_id'])) {
            $where[] = "f.tourist_id = :tourist_id";
            $params[':tourist_id'] = $filters['tourist_id'];
        }

        if (!empty($filters['rating'])) {
            $where[] = "f.overall_rating = :rating";
            $params[':rating'] = $filters['rating'];
        }

        if (!empty($filters['booking_id'])) {
            $where[] = "f.booking_id = :booking_id";
            $params[':booking_id'] = $filters['booking_id'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM feedback f {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT f.*, u.name as tourist_name, u.email as tourist_email
             FROM feedback f
             LEFT JOIN users u ON f.tourist_id = u.id
             {$where_clause}
             ORDER BY f.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $feedback = $stmt->fetchAll();

        return [
            'data'     => $feedback,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO feedback (booking_id, tourist_id, guide_id, schedule_id, guide_rating, communication_rating, safety_rating, organization_rating, overall_rating, comment, suggestions, complaints, created_at)
             VALUES (:booking_id, :tourist_id, :guide_id, :schedule_id, :guide_rating, :communication_rating, :safety_rating, :organization_rating, :overall_rating, :comment, :suggestions, :complaints, :created_at)"
        );

        $stmt->execute([
            ':booking_id'           => $data['booking_id'] ?? null,
            ':tourist_id'           => $data['tourist_id'] ?? null,
            ':guide_id'             => $data['guide_id'] ?? null,
            ':schedule_id'          => $data['schedule_id'] ?? null,
            ':guide_rating'         => $data['guide_rating'] ?? 5,
            ':communication_rating' => $data['communication_rating'] ?? 5,
            ':safety_rating'        => $data['safety_rating'] ?? 5,
            ':organization_rating'  => $data['organization_rating'] ?? 5,
            ':overall_rating'       => $data['overall_rating'] ?? 5,
            ':comment'              => $data['comment'] ?? '',
            ':suggestions'          => $data['suggestions'] ?? '',
            ':complaints'           => $data['complaints'] ?? '',
            ':created_at'           => $data['created_at'] ?? date('Y-m-d H:i:s'),
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

        $stmt = $this->db->prepare("UPDATE feedback SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM feedback WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function findByGuide(int $guide_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, u.name as tourist_name, u.email as tourist_email,
                    b.schedule_id, e.title as event_title
             FROM feedback f
             LEFT JOIN users u ON f.tourist_id = u.id
             LEFT JOIN bookings b ON f.booking_id = b.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             WHERE f.guide_id = :guide_id
             ORDER BY f.created_at DESC"
        );
        $stmt->execute([':guide_id' => $guide_id]);
        return $stmt->fetchAll();
    }

    public function findByTourist(int $tourist_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, u.name as guide_name, u.email as guide_email,
                    b.schedule_id, e.title as event_title
             FROM feedback f
             LEFT JOIN users u ON f.guide_id = u.id
             LEFT JOIN bookings b ON f.booking_id = b.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             WHERE f.tourist_id = :tourist_id
             ORDER BY f.created_at DESC"
        );
        $stmt->execute([':tourist_id' => $tourist_id]);
        return $stmt->fetchAll();
    }

    public function getAverageRating(int $guide_id): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(AVG(overall_rating), 0) as avg_rating FROM feedback WHERE guide_id = :guide_id"
        );
        $stmt->execute([':guide_id' => $guide_id]);
        return (float) $stmt->fetch()['avg_rating'];
    }

    public function getStats(?int $guide_id = null): array
    {
        $where  = '';
        $params = [];

        if ($guide_id !== null) {
            $where  = 'WHERE guide_id = :guide_id';
            $params[':guide_id'] = $guide_id;
        }

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) as total_feedbacks,
                COALESCE(AVG(overall_rating), 0) as average_rating,
                SUM(CASE WHEN overall_rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN overall_rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN overall_rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN overall_rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN overall_rating = 1 THEN 1 ELSE 0 END) as one_star
             FROM feedback {$where}"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }
}
