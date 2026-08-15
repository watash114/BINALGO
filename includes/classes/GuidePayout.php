<?php

require_once __DIR__ . '/../../config/database.php';

class GuidePayout
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT gp.*, u.name as guide_name, u.email as guide_email,
                    e.title as event_title, d.name as destination_name,
                    s.start_date, b.num_participants,
                    p.total_amount as payment_total, p.reference_number,
                    admin.name as approved_by_name
             FROM guide_payouts gp
             LEFT JOIN users u ON gp.guide_id = u.id
             LEFT JOIN bookings b ON gp.booking_id = b.id
             LEFT JOIN payments p ON gp.payment_id = p.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN users admin ON gp.approved_by = admin.id
             WHERE gp.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findByGuide(int $guide_id, ?string $status = null, int $page = 1, int $per_page = 20): array
    {
        $where  = ["gp.guide_id = :gid"];
        $params = [':gid' => $guide_id];

        if ($status) {
            $where[] = "gp.payout_status = :status";
            $params[':status'] = $status;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where);

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM guide_payouts gp {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT gp.*, e.title as event_title, d.name as destination_name,
                    s.start_date, b.num_participants, p.reference_number
             FROM guide_payouts gp
             LEFT JOIN bookings b ON gp.booking_id = b.id
             LEFT JOIN payments p ON gp.payment_id = p.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             {$where_clause}
             ORDER BY gp.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'data'  => $stmt->fetchAll(),
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
            'page'  => $page,
        ];
    }

    public function findAll(array $filters = [], int $page = 1, int $per_page = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "gp.payout_status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['guide_id'])) {
            $where[] = "gp.guide_id = :gid";
            $params[':gid'] = $filters['guide_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(u.name LIKE :search OR u.email LIKE :search2 OR e.title LIKE :search3)";
            $params[':search']  = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM guide_payouts gp
             LEFT JOIN users u ON gp.guide_id = u.id
             LEFT JOIN bookings b ON gp.booking_id = b.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT gp.*, u.name as guide_name, u.email as guide_email,
                    e.title as event_title, d.name as destination_name,
                    s.start_date, p.reference_number
             FROM guide_payouts gp
             LEFT JOIN users u ON gp.guide_id = u.id
             LEFT JOIN bookings b ON gp.booking_id = b.id
             LEFT JOIN payments p ON gp.payment_id = p.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             {$where_clause}
             ORDER BY gp.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'data'  => $stmt->fetchAll(),
            'total' => $total,
            'pages' => (int) ceil($total / $per_page),
            'page'  => $page,
        ];
    }

    public function getGuideStats(int $guide_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) as total_payouts,
                SUM(CASE WHEN payout_status = 'paid' THEN net_earning ELSE 0 END) as total_earned,
                SUM(CASE WHEN payout_status = 'pending' THEN net_earning ELSE 0 END) as pending_amount,
                SUM(CASE WHEN payout_status = 'approved' THEN net_earning ELSE 0 END) as approved_amount,
                SUM(commission_amount) as total_commission,
                SUM(CASE WHEN payout_status = 'paid' THEN tour_amount ELSE 0 END) as total_tour_value,
                COUNT(CASE WHEN payout_status = 'paid' THEN 1 END) as completed_payouts
             FROM guide_payouts
             WHERE guide_id = :gid"
        );
        $stmt->execute([':gid' => $guide_id]);
        return $stmt->fetch() ?: [];
    }

    public function getMonthlyEarnings(int $guide_id, int $months = 6): array
    {
        $stmt = $this->db->prepare(
            "SELECT strftime('%Y-%m', gp.created_at) as month,
                    SUM(CASE WHEN gp.payout_status = 'paid' THEN gp.net_earning ELSE 0 END) as earned,
                    SUM(CASE WHEN gp.payout_status IN ('pending','approved') THEN gp.net_earning ELSE 0 END) as pending,
                    COUNT(*) as tours
             FROM guide_payouts gp
             WHERE gp.guide_id = :gid
               AND gp.created_at >= date('now', '-' || :months || ' months')
             GROUP BY strftime('%Y-%m', gp.created_at)
             ORDER BY month ASC"
        );
        $stmt->execute([':gid' => $guide_id, ':months' => $months]);
        return $stmt->fetchAll();
    }

    public function approve(int $payout_id, int $admin_id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE guide_payouts SET payout_status = 'approved', approved_by = :admin, approved_at = datetime('now'), updated_at = datetime('now')
             WHERE id = :id AND payout_status = 'pending'"
        );
        return $stmt->execute([':admin' => $admin_id, ':id' => $payout_id]);
    }

    public function bulkApprove(array $ids, int $admin_id): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if ($this->approve((int) $id, $admin_id)) {
                $count++;
            }
        }
        return $count;
    }

    public function markPaid(int $payout_id, string $reference): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE guide_payouts SET payout_status = 'paid', paid_at = datetime('now'), payout_reference = :ref, updated_at = datetime('now')
             WHERE id = :id AND payout_status = 'approved'"
        );
        return $stmt->execute([':ref' => $reference, ':id' => $payout_id]);
    }

    public function getPayoutStats(): array
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN payout_status = 'pending' THEN net_earning ELSE 0 END) as pending_total,
                SUM(CASE WHEN payout_status = 'approved' THEN net_earning ELSE 0 END) as approved_total,
                SUM(CASE WHEN payout_status = 'paid' THEN net_earning ELSE 0 END) as paid_total,
                SUM(CASE WHEN payout_status = 'paid' THEN commission_amount ELSE 0 END) as total_commission
             FROM guide_payouts"
        );
        return $stmt->fetch() ?: [];
    }

    public function generatePayoutReference(): string
    {
        return 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
}
