<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Notification.php';

class Payment
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, u.name as tourist_name, u.email as tourist_email,
                    b.num_participants, b.total_price as booking_total,
                    e.title as event_title, e.price as event_price,
                    s.start_date, s.start_time, s.end_time,
                    COALESCE(d2.name, d.name) as destination_name, COALESCE(d2.location, d.location) as destination_location,
                    COALESCE(g2.name, g.name) as guide_name
             FROM payments p
             LEFT JOIN users u ON p.tourist_id = u.id
             LEFT JOIN bookings b ON p.booking_id = b.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             LEFT JOIN users g ON s.guide_id = g.id
             LEFT JOIN users g2 ON b.guide_id = g2.id
             WHERE p.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findByBookingId(int $booking_id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payments WHERE booking_id = :bid ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([':bid' => $booking_id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payments WHERE reference_number = :ref LIMIT 1"
        );
        $stmt->execute([':ref' => $reference]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findByTourist(int $tourist_id, int $page = 1, int $per_page = 15): array
    {
        $offset = ($page - 1) * $per_page;

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM payments WHERE tourist_id = :uid"
        );
        $count_stmt->execute([':uid' => $tourist_id]);
        $total = (int) $count_stmt->fetch()['total'];

        $stmt = $this->db->prepare(
            "SELECT p.*, COALESCE(e.title, d2.name) as event_title, COALESCE(d2.name, d.name) as destination_name, COALESCE(b.visit_date, s.start_date) as start_date
             FROM payments p
             LEFT JOIN bookings b ON p.booking_id = b.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             WHERE p.tourist_id = :uid
             ORDER BY p.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );
        $stmt->execute([':uid' => $tourist_id]);

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
            $where[] = "p.payment_status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['method'])) {
            $where[] = "p.payment_method = :method";
            $params[':method'] = $filters['method'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(p.reference_number LIKE :search OR u.name LIKE :search2 OR p.transaction_id LIKE :search3)";
            $params[':search']  = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
            $params[':search3'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[] = "p.created_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "p.created_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM payments p
             LEFT JOIN users u ON p.tourist_id = u.id
             {$where_clause}"
        );
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetch()['total'];

        $offset = ($page - 1) * $per_page;

        $stmt = $this->db->prepare(
            "SELECT p.*, u.name as tourist_name, u.email as tourist_email,
                    COALESCE(e.title, d2.name) as event_title, COALESCE(d2.name, d.name) as destination_name,
                    COALESCE(b.visit_date, s.start_date) as start_date, b.num_participants
             FROM payments p
             LEFT JOIN users u ON p.tourist_id = u.id
             LEFT JOIN bookings b ON p.booking_id = b.id
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON e.destination_id = d.id
             LEFT JOIN destinations d2 ON b.destination_id = d2.id
             {$where_clause}
             ORDER BY p.created_at DESC
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

    public function create(array $data): ?int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO payments (booking_id, tourist_id, amount, tax, service_fee, total_amount,
                    payment_method, card_last_four, card_brand, transaction_id, reference_number,
                    payment_status, payment_date, created_at)
             VALUES (:booking_id, :tourist_id, :amount, :tax, :service_fee, :total_amount,
                    :payment_method, :card_last_four, :card_brand, :transaction_id, :reference_number,
                    :payment_status, :payment_date, db_now())"
        );

        $stmt->execute([
            ':booking_id'       => $data['booking_id'],
            ':tourist_id'       => $data['tourist_id'],
            ':amount'           => $data['amount'],
            ':tax'              => $data['tax'] ?? 0,
            ':service_fee'      => $data['service_fee'] ?? 0,
            ':total_amount'     => $data['total_amount'],
            ':payment_method'   => $data['payment_method'] ?? 'card',
            ':card_last_four'   => $data['card_last_four'] ?? null,
            ':card_brand'       => $data['card_brand'] ?? null,
            ':transaction_id'   => $data['transaction_id'] ?? null,
            ':reference_number' => $data['reference_number'],
            ':payment_status'   => $data['payment_status'] ?? 'pending',
            ':payment_date'     => $data['payment_date'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status, ?string $transaction_id = null): bool
    {
        $fields = "payment_status = :status";
        $params = [':id' => $id, ':status' => $status];

        if ($status === 'paid') {
            $fields .= ", payment_date = db_now(), updated_at = db_now()";
        } else {
            $fields .= ", updated_at = db_now()";
        }

        if ($transaction_id !== null) {
            $fields .= ", transaction_id = :txn";
            $params[':txn'] = $transaction_id;
        }

        $stmt = $this->db->prepare("UPDATE payments SET {$fields} WHERE id = :id");
        return $stmt->execute($params);
    }

    public function getStats(): array
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_revenue,
                SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN payment_status = 'refunded' THEN 1 ELSE 0 END) as refunded_count,
                SUM(CASE WHEN payment_status = 'paid' AND db_month() = db_month()) THEN total_amount ELSE 0 END) as monthly_revenue
             FROM payments"
        );
        return $stmt->fetch() ?: [];
    }

    public function getMonthlyRevenue(int $months = 6): array
    {
        $stmt = $this->db->prepare(
            "SELECT db_date_format(, '') as month,
                    SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as revenue,
                    COUNT(*) as count
             FROM payments
             WHERE created_at >= DATE_SUB(db_curdate(), INTERVAL :months MONTH)
             GROUP BY db_date_format(, '')
             ORDER BY month ASC"
        );
        $stmt->execute([':months' => $months]);
        return $stmt->fetchAll();
    }

    public function generateReferenceNumber(): string
    {
        return 'BGO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }

    public function processCardPayment(int $payment_id, string $transaction_id, string $card_number, string $card_holder, string $expiry, string $cvv): array
    {
        $payment = $this->findById($payment_id);
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found.'];
        }

        if ($payment['payment_status'] === 'paid') {
            return ['success' => false, 'message' => 'Payment already completed.'];
        }

        $card_number = preg_replace('/\s/', '', $card_number);
        $card_last_four = substr($card_number, -4);
        $card_brand = $this->detectCardBrand($card_number);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE payments SET payment_status = 'paid', payment_date = db_now(), transaction_id = :txn, card_last_four = :four, card_brand = :brand, updated_at = db_now() WHERE id = :id")
                ->execute([':txn' => $transaction_id, ':four' => $card_last_four, ':brand' => $card_brand, ':id' => $payment_id]);

            $this->db->prepare("UPDATE bookings SET payment_status = 'paid', status = 'confirmed', updated_at = db_now() WHERE id = :id")
                ->execute([':id' => $payment['booking_id']]);

            $this->db->prepare("UPDATE schedules SET available_spots = available_spots - :num WHERE id = (SELECT schedule_id FROM bookings WHERE id = :bid)")
                ->execute([':num' => $payment['num_participants'], ':bid' => $payment['booking_id']]);

            $this->db->commit();

            $this->createGuidePayout($payment['booking_id'], $payment_id, $payment['total_amount']);

            $notif = new Notification();
            $notif->notifyPaymentSuccess($payment['booking_id'], $payment_id);

            return [
                'success'       => true,
                'message'       => 'Payment processed successfully.',
                'transaction_id'=> $transaction_id,
                'card_brand'    => $card_brand,
                'card_last_four'=> $card_last_four,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->updateStatus($payment_id, 'failed');

            $notif = new Notification();
            $notif->notifyPaymentFailed($payment['booking_id'], $e->getMessage());

            return ['success' => false, 'message' => 'Payment processing failed: ' . $e->getMessage()];
        }
    }

    public function processGcashPayment(int $payment_id, string $transaction_id, string $gcash_number, string $gcash_name): array
    {
        $payment = $this->findById($payment_id);
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found.'];
        }

        if ($payment['payment_status'] === 'paid') {
            return ['success' => false, 'message' => 'Payment already completed.'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE payments SET payment_status = 'paid', payment_date = db_now(), transaction_id = :txn, payment_method = 'gcash', updated_at = db_now() WHERE id = :id")
                ->execute([':txn' => $transaction_id, ':id' => $payment_id]);

            $this->db->prepare("UPDATE bookings SET payment_status = 'paid', status = 'confirmed', updated_at = db_now() WHERE id = :id")
                ->execute([':id' => $payment['booking_id']]);

            $this->db->prepare("UPDATE schedules SET available_spots = available_spots - :num WHERE id = (SELECT schedule_id FROM bookings WHERE id = :bid)")
                ->execute([':num' => $payment['num_participants'], ':bid' => $payment['booking_id']]);

            $this->db->commit();

            $this->createGuidePayout($payment['booking_id'], $payment_id, $payment['total_amount']);

            $notif = new Notification();
            $notif->notifyPaymentSuccess($payment['booking_id'], $payment_id);

            return [
                'success'       => true,
                'message'       => 'GCash payment processed successfully.',
                'transaction_id'=> $transaction_id,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->updateStatus($payment_id, 'failed');

            $notif = new Notification();
            $notif->notifyPaymentFailed($payment['booking_id'], $e->getMessage());

            return ['success' => false, 'message' => 'GCash payment failed: ' . $e->getMessage()];
        }
    }

    public function processMayaPayment(int $payment_id, string $transaction_id, string $maya_number, string $maya_name): array
    {
        $payment = $this->findById($payment_id);
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found.'];
        }

        if ($payment['payment_status'] === 'paid') {
            return ['success' => false, 'message' => 'Payment already completed.'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE payments SET payment_status = 'paid', payment_date = db_now(), transaction_id = :txn, payment_method = 'maya', updated_at = db_now() WHERE id = :id")
                ->execute([':txn' => $transaction_id, ':id' => $payment_id]);

            $this->db->prepare("UPDATE bookings SET payment_status = 'paid', status = 'confirmed', updated_at = db_now() WHERE id = :id")
                ->execute([':id' => $payment['booking_id']]);

            $this->db->prepare("UPDATE schedules SET available_spots = available_spots - :num WHERE id = (SELECT schedule_id FROM bookings WHERE id = :bid)")
                ->execute([':num' => $payment['num_participants'], ':bid' => $payment['booking_id']]);

            $this->db->commit();

            $this->createGuidePayout($payment['booking_id'], $payment_id, $payment['total_amount']);

            $notif = new Notification();
            $notif->notifyPaymentSuccess($payment['booking_id'], $payment_id);

            return [
                'success'       => true,
                'message'       => 'Maya payment processed successfully.',
                'transaction_id'=> $transaction_id,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->updateStatus($payment_id, 'failed');

            $notif = new Notification();
            $notif->notifyPaymentFailed($payment['booking_id'], $e->getMessage());

            return ['success' => false, 'message' => 'Maya payment failed: ' . $e->getMessage()];
        }
    }

    private function createGuidePayout(int $booking_id, int $payment_id, float $total_amount): void
    {
        $booking_stmt = $this->db->prepare(
            "SELECT b.*, COALESCE(b.guide_id, s.guide_id) as guide_id, COALESCE(e.price, d.entrance_fee) as event_price
             FROM bookings b
             LEFT JOIN schedules s ON b.schedule_id = s.id
             LEFT JOIN events e ON s.event_id = e.id
             LEFT JOIN destinations d ON COALESCE(b.destination_id, e.destination_id) = d.id
             WHERE b.id = :bid"
        );
        $booking_stmt->execute([':bid' => $booking_id]);
        $booking_data = $booking_stmt->fetch();

        if ($booking_data && $booking_data['guide_id']) {
            $commission_rate = 15.00;
            $commission_amount = round($total_amount * ($commission_rate / 100), 2);
            $net_earning = $total_amount - $commission_amount;

            $payout_stmt = $this->db->prepare(
                "INSERT INTO guide_payouts (guide_id, booking_id, payment_id, tour_amount, commission_rate, commission_amount, net_earning, payout_status, created_at)
                 VALUES (:gid, :bid, :pid, :amount, :rate, :commission, :net, 'pending', db_now())"
            );
            $payout_stmt->execute([
                ':gid'        => $booking_data['guide_id'],
                ':bid'        => $booking_id,
                ':pid'        => $payment_id,
                ':amount'     => $total_amount,
                ':rate'       => $commission_rate,
                ':commission' => $commission_amount,
                ':net'        => $net_earning,
            ]);
        }
    }

    public function detectCardBrand(string $number): string
    {
        $number = preg_replace('/\D/', '', $number);
        if (preg_match('/^4/', $number)) return 'visa';
        if (preg_match('/^5[1-5]/', $number) || preg_match('/^2[2-7]/', $number)) return 'mastercard';
        if (preg_match('/^3[47]/', $number)) return 'amex';
        if (preg_match('/^6(?:011|5)/', $number)) return 'discover';
        if (preg_match('/^63[7-9]/', $number)) return 'jcb';
        return 'unknown';
    }
}
