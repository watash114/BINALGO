<?php

require_once __DIR__ . '/../../config/database.php';

class DestinationReview
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT dr.*, u.name as user_name, u.email as user_email, u.avatar
             FROM destination_reviews dr
             LEFT JOIN users u ON dr.user_id = u.id
             WHERE dr.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getByDestination(int $destinationId, string $sort = 'newest', int $page = 1, int $perPage = 10): array
    {
        $where = ["dr.destination_id = :dest_id", "dr.is_hidden = 0"];
        $params = [':dest_id' => $destinationId];

        $orderBy = match($sort) {
            'highest' => 'dr.rating DESC, dr.created_at DESC',
            'lowest'  => 'dr.rating ASC, dr.created_at DESC',
            default   => 'dr.created_at DESC',
        };

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM destination_reviews dr WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT dr.*, u.name as user_name, u.email as user_email, u.avatar
             FROM destination_reviews dr
             LEFT JOIN users u ON dr.user_id = u.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'reviews' => $stmt->fetchAll(),
            'total'   => $total,
            'pages'   => max(1, ceil($total / $perPage)),
        ];
    }

    public function getStats(int $destinationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) as total_reviews,
                COALESCE(AVG(rating), 0) as avg_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as star5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as star4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as star3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as star2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as star1
             FROM destination_reviews
             WHERE destination_id = :dest_id AND is_hidden = 0"
        );
        $stmt->execute([':dest_id' => $destinationId]);
        $stats = $stmt->fetch();

        return [
            'total_reviews' => (int) $stats['total_reviews'],
            'avg_rating'    => round((float) $stats['avg_rating'], 1),
            'star5'         => (int) ($stats['star5'] ?? 0),
            'star4'         => (int) ($stats['star4'] ?? 0),
            'star3'         => (int) ($stats['star3'] ?? 0),
            'star2'         => (int) ($stats['star2'] ?? 0),
            'star1'         => (int) ($stats['star1'] ?? 0),
        ];
    }

    public function getUserReview(int $destinationId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM destination_reviews WHERE destination_id = :dest_id AND user_id = :user_id LIMIT 1"
        );
        $stmt->execute([':dest_id' => $destinationId, ':user_id' => $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO destination_reviews (destination_id, user_id, rating, review)
             VALUES (:dest_id, :user_id, :rating, :review)"
        );
        $stmt->execute([
            ':dest_id' => $data['destination_id'],
            ':user_id' => $data['user_id'],
            ':rating'  => $data['rating'],
            ':review'  => $data['review'],
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

        $fields[] = "updated_at = datetime('now')";
        $setClause = implode(', ', $fields);

        $stmt = $this->db->prepare("UPDATE destination_reviews SET {$setClause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM destination_reviews WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function hide(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE destination_reviews SET is_hidden = 1 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function unhide(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE destination_reviews SET is_hidden = 0 WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function getAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['destination_id'])) {
            $where[] = "dr.destination_id = :dest_id";
            $params[':dest_id'] = $filters['destination_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = "dr.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }
        if (isset($filters['is_hidden'])) {
            $where[] = "dr.is_hidden = :is_hidden";
            $params[':is_hidden'] = $filters['is_hidden'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM destination_reviews dr {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];

        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT dr.*, u.name as user_name, u.email as user_email, d.name as dest_name
             FROM destination_reviews dr
             LEFT JOIN users u ON dr.user_id = u.id
             LEFT JOIN destinations d ON dr.destination_id = d.id
             {$whereClause}
             ORDER BY dr.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'reviews' => $stmt->fetchAll(),
            'total'   => $total,
            'pages'   => max(1, ceil($total / $perPage)),
        ];
    }
}
