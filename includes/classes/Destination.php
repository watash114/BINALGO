<?php

require_once __DIR__ . '/../../config/database.php';

class Destination
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM destinations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findWithGuides(int $id): ?array
    {
        $dest = $this->findById($id);
        if (!$dest) return null;
        $stmt = $this->db->prepare(
            "SELECT dg.*, u.name, u.avatar, u.email, u.phone,
                    gp.years_of_experience, gp.languages, gp.specializations, gp.bio, gp.availability_status,
                    COALESCE((SELECT AVG(f.overall_rating) FROM feedback f WHERE f.guide_id = u.id), 0) as avg_rating,
                    (SELECT COUNT(*) FROM feedback f WHERE f.guide_id = u.id) as review_count,
                    (SELECT COUNT(*) FROM bookings b WHERE b.guide_id = u.id AND b.status IN ('confirmed','completed')) as tours_completed
             FROM destination_guides dg
             JOIN users u ON dg.guide_id = u.id
             LEFT JOIN guide_profiles gp ON gp.user_id = u.id
             WHERE dg.destination_id = :id AND dg.status = 'active' AND u.status = 'active'
             ORDER BY dg.is_primary DESC, u.name ASC"
        );
        $stmt->execute([':id' => $id]);
        $dest['guides'] = $stmt->fetchAll();
        return $dest;
    }

    public function getAssignedGuides(int $destination_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.*, dg.is_primary, dg.status as assignment_status, dg.id as dg_id,
                    gp.years_of_experience, gp.languages, gp.specializations, gp.bio, gp.availability_status,
                    COALESCE((SELECT AVG(f.overall_rating) FROM feedback f WHERE f.guide_id = u.id), 0) as avg_rating,
                    (SELECT COUNT(*) FROM feedback f WHERE f.guide_id = u.id) as review_count
             FROM destination_guides dg
             JOIN users u ON dg.guide_id = u.id
             LEFT JOIN guide_profiles gp ON gp.user_id = u.id
             WHERE dg.destination_id = :id
             ORDER BY dg.is_primary DESC, u.name ASC"
        );
        $stmt->execute([':id' => $destination_id]);
        return $stmt->fetchAll();
    }

    public function getAvailableGuides(): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.avatar, u.email,
                    gp.years_of_experience, gp.languages, gp.specializations, gp.bio, gp.availability_status,
                    COALESCE((SELECT AVG(f.overall_rating) FROM feedback f WHERE f.guide_id = u.id), 0) as avg_rating,
                    (SELECT COUNT(*) FROM feedback f WHERE f.guide_id = u.id) as review_count
             FROM users u
             LEFT JOIN guide_profiles gp ON gp.user_id = u.id
             WHERE u.role = 'guide' AND u.status = 'active'
             ORDER BY u.name ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function assignGuide(int $destination_id, int $guide_id, bool $is_primary = false): bool
    {
        if ($is_primary) {
            $this->db->prepare("UPDATE destination_guides SET is_primary = 0 WHERE destination_id = :did")
                ->execute([':did' => $destination_id]);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO destination_guides (destination_id, guide_id, is_primary)
             VALUES (:did, :gid, :is_primary)
             ON CONFLICT(destination_id, guide_id) DO UPDATE SET status = 'active', is_primary = :is_primary"
        );
        return $stmt->execute([':did' => $destination_id, ':gid' => $guide_id, ':is_primary' => $is_primary ? 1 : 0]);
    }

    public function removeGuide(int $destination_id, int $guide_id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM destination_guides WHERE destination_id = :did AND guide_id = :gid");
        return $stmt->execute([':did' => $destination_id, ':gid' => $guide_id]);
    }

    public function toggleGuideStatus(int $destination_id, int $guide_id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE destination_guides SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END
             WHERE destination_id = :did AND guide_id = :gid"
        );
        return $stmt->execute([':did' => $destination_id, ':gid' => $guide_id]);
    }

    public function setPrimaryGuide(int $destination_id, int $guide_id): bool
    {
        $this->db->prepare("UPDATE destination_guides SET is_primary = 0 WHERE destination_id = :did")
            ->execute([':did' => $destination_id]);
        $stmt = $this->db->prepare("UPDATE destination_guides SET is_primary = 1 WHERE destination_id = :did AND guide_id = :gid");
        return $stmt->execute([':did' => $destination_id, ':gid' => $guide_id]);
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(name LIKE :search OR description LIKE :search2 OR location LIKE :search3)";
            $params[':search']  = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['category'])) {
            $where[] = "category = :category";
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['featured'])) {
            $where[] = "featured = 1";
        }

        if (!empty($filters['booking_enabled'])) {
            $where[] = "booking_enabled = 1";
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare("SELECT COUNT(*) as total FROM destinations {$where_clause}");
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT * FROM destinations {$where_clause} ORDER BY featured DESC, name ASC LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $destinations = $stmt->fetchAll();

        return [
            'data'     => $destinations,
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO destinations (name, description, location, contact_phone, contact_email, latitude, longitude,
                operating_hours_open, operating_hours_close, category, difficulty, capacity_limit, max_guests_per_booking,
                available_booking_days, recommended_age_min, recommended_age_max, accessibility_info, rules_regulations,
                facilities, entrance_fee, package_price, image, gallery_images, status, booking_enabled, guide_required,
                booking_cutoff_hours, advance_booking_days, cancellation_policy, featured, created_by, created_at)
             VALUES (:name, :description, :location, :contact_phone, :contact_email, :latitude, :longitude,
                :opening_hours, :closing_hours, :category, :difficulty, :capacity_limit, :max_guests,
                :booking_days, :age_min, :age_max, :accessibility, :rules, :facilities,
                :entrance_fee, :package_price, :image, :gallery_images, :status, :booking_enabled, :guide_required,
                :cutoff_hours, :advance_days, :cancellation_policy, :featured, :created_by, db_now())"
        );

        $stmt->execute([
            ':name'               => $data['name'] ?? '',
            ':description'        => $data['description'] ?? '',
            ':location'           => $data['location'] ?? '',
            ':contact_phone'      => $data['contact_phone'] ?? null,
            ':contact_email'      => $data['contact_email'] ?? null,
            ':latitude'           => $data['latitude'] ?? null,
            ':longitude'          => $data['longitude'] ?? null,
            ':opening_hours'      => $data['operating_hours_open'] ?? null,
            ':closing_hours'      => $data['operating_hours_close'] ?? null,
            ':category'           => $data['category'] ?? 'other',
            ':difficulty'         => $data['difficulty'] ?? 'easy',
            ':capacity_limit'     => $data['capacity_limit'] ?? 0,
            ':max_guests'         => $data['max_guests_per_booking'] ?? 10,
            ':booking_days'       => $data['available_booking_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            ':age_min'            => $data['recommended_age_min'] ?? 1,
            ':age_max'            => $data['recommended_age_max'] ?? 100,
            ':accessibility'      => $data['accessibility_info'] ?? '',
            ':rules'              => $data['rules_regulations'] ?? '',
            ':facilities'         => $data['facilities'] ?? '',
            ':entrance_fee'       => $data['entrance_fee'] ?? $data['price'] ?? 0,
            ':package_price'      => $data['package_price'] ?? null,
            ':image'              => $data['image'] ?? null,
            ':gallery_images'     => $data['gallery_images'] ?? null,
            ':status'             => $data['status'] ?? 'active',
            ':booking_enabled'    => $data['booking_enabled'] ?? 1,
            ':guide_required'     => $data['guide_required'] ?? 1,
            ':cutoff_hours'       => $data['booking_cutoff_hours'] ?? 2,
            ':advance_days'       => $data['advance_booking_days'] ?? 1,
            ':cancellation_policy'=> $data['cancellation_policy'] ?? '',
            ':featured'           => $data['featured'] ?? 0,
            ':created_by'         => $data['created_by'] ?? null,
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

        if (empty($fields)) return false;

        $fields[] = "updated_at = db_now()";
        $set_clause = implode(', ', $fields);

        $stmt = $this->db->prepare("UPDATE destinations SET {$set_clause} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM destinations WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function findWithSeasons(int $id): ?array
    {
        $destination = $this->findById($id);
        if (!$destination) return null;

        $stmt = $this->db->prepare(
            "SELECT * FROM destination_seasons WHERE destination_id = :id ORDER BY FIELD(season_type, 'peak', 'off_peak'), months ASC"
        );
        $stmt->execute([':id' => $id]);
        $destination['seasons'] = $stmt->fetchAll();

        return $destination;
    }

    public function getPopularDestinations(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, COUNT(b.id) as booking_count
             FROM destinations d
             LEFT JOIN bookings b ON b.destination_id = d.id AND b.status IN ('confirmed', 'completed')
             WHERE d.status = 'active'
             GROUP BY d.id
             ORDER BY booking_count DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function search(array $filters): array
    {
        return $this->findAll($filters, $filters['page'] ?? 1, $filters['per_page'] ?? 20);
    }

    public function isAvailable(int $id, string $date): bool
    {
        $destination = $this->findById($id);
        if (!$destination || $destination['status'] !== 'active') return false;

        $month = (int) date('m', strtotime($date));

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM destination_seasons
             WHERE destination_id = :id
             AND :month BETWEEN CAST(SUBSTRING_INDEX(months, '-', 1) AS UNSIGNED)
                           AND CAST(SUBSTRING_INDEX(months, '-', -1) AS UNSIGNED)"
        );
        $stmt->execute([':id' => $id, ':month' => $month]);
        $result = $stmt->fetch();

        return $result['count'] > 0;
    }

    public function toggleFeatured(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE destinations SET featured = NOT featured, updated_at = db_now() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function toggleStatus(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE destinations SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END, updated_at = db_now() WHERE id = :id"
        );
        return $stmt->execute([':id' => $id]);
    }

    public function toggleBooking(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE destinations SET booking_enabled = NOT booking_enabled, updated_at = db_now() WHERE id = :id"
        );
        return $stmt->execute([':id' => $id]);
    }

    public function getCategories(): array
    {
        return [
            'beaches'            => 'Beaches',
            'falls'              => 'Falls',
            'mountain'           => 'Mountain',
            'historical_site'    => 'Historical Site',
            'cultural_site'      => 'Cultural Site',
            'eco_tourism'        => 'Eco-Tourism',
            'festival'           => 'Festival',
            'religious_site'     => 'Religious Site',
            'nature_adventure'   => 'Nature & Adventure',
            'local_experience'   => 'Local Experience',
            'other'              => 'Other',
        ];
    }
}
