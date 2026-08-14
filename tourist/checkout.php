<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');


require_once __DIR__ . '/../includes/classes/Payment.php';

$db = Database::getInstance()->getConnection();
$user = current_user();
$paymentModel = new Payment();

$booking_id = (int)($_GET['booking_id'] ?? 0);
if (!$booking_id) {
    flash_message('error', 'No booking specified.');
    redirect('/tourist/browse.php');
}

$bk_stmt = $db->prepare(
    "SELECT b.*,
            s.id as schedule_id, s.start_date, s.end_date, s.start_time, s.end_time, s.available_spots,
            e.title as event_title, e.price as event_price, e.duration_hours, e.description as event_desc,
            d.name as destination_name, d.location as destination_location, d.image as dest_image, d.entrance_fee
     FROM bookings b
     JOIN destinations d ON COALESCE(b.destination_id, (SELECT e2.destination_id FROM events e2 JOIN schedules s2 ON s2.event_id = e2.id WHERE s2.id = b.schedule_id LIMIT 1)) = d.id
     LEFT JOIN schedules s ON b.schedule_id = s.id
     LEFT JOIN events e ON s.event_id = e.id
     WHERE b.id = :bid AND b.tourist_id = :uid"
);
$bk_stmt->execute([':bid' => $booking_id, ':uid' => $user['id']]);
$booking = $bk_stmt->fetch();

if (!$booking) {
    flash_message('error', 'Booking not found.');
    redirect('/tourist/bookings.php');
}

$existing_payment = $paymentModel->findByBookingId($booking_id);
if ($existing_payment && $existing_payment['payment_status'] === 'paid') {
    flash_message('success', 'This booking has already been paid.');
    redirect('/tourist/confirmation.php?payment_id=' . $existing_payment['id']);
}

$amount = (float)$booking['total_price'];
$tax = round($amount * 0.12, 2);
$service_fee = round($amount * 0.05, 2);
$total = $amount + $tax + $service_fee;

render_page('tourist', 'checkout.php', 'Checkout', function () use ($booking, $amount, $tax, $service_fee, $total, $booking_id) {
?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold"><i class="fas fa-credit-card me-2 text-primary"></i>Choose Payment Method</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">Select how you'd like to pay for this booking.</p>

                    <div class="d-flex flex-column gap-3">
                        <a href="<?= BASE_URL ?>/tourist/pay_gcash.php?booking_id=<?= $booking_id ?>" class="payment-method-card d-flex align-items-center p-3 border rounded-3 text-decoration-none text-dark" style="transition: all 0.2s; border-color: #e2e8f0 !important;">
                            <div class="d-flex align-items-center gap-3 flex-grow-1">
                                <div class="d-flex align-items-center justify-content-center rounded" style="width:56px;height:56px;background:linear-gradient(135deg,#007dfe,#00b4ee);">
                                    <span class="text-white fw-bold" style="font-size:0.75rem;letter-spacing:-0.5px;">GCash</span>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">GCash</h6>
                                    <small class="text-muted">Pay with your GCash mobile wallet</small>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-muted"></i>
                        </a>

                        <a href="<?= BASE_URL ?>/tourist/pay_maya.php?booking_id=<?= $booking_id ?>" class="payment-method-card d-flex align-items-center p-3 border rounded-3 text-decoration-none text-dark" style="transition: all 0.2s; border-color: #e2e8f0 !important;">
                            <div class="d-flex align-items-center gap-3 flex-grow-1">
                                <div class="d-flex align-items-center justify-content-center rounded" style="width:56px;height:56px;background:linear-gradient(135deg,#00c853,#00bfa5);">
                                    <span class="text-white fw-bold" style="font-size:0.75rem;">Maya</span>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Maya (PayMaya)</h6>
                                    <small class="text-muted">Pay with Maya wallet or QR</small>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-muted"></i>
                        </a>

                        <a href="<?= BASE_URL ?>/tourist/pay_card.php?booking_id=<?= $booking_id ?>" class="payment-method-card d-flex align-items-center p-3 border rounded-3 text-decoration-none text-dark" style="transition: all 0.2s; border-color: #e2e8f0 !important;">
                            <div class="d-flex align-items-center gap-3 flex-grow-1">
                                <div class="d-flex align-items-center justify-content-center rounded" style="width:56px;height:56px;background:linear-gradient(135deg,#1a1a2e,#16213e);">
                                    <i class="fas fa-credit-card text-white" style="font-size:1.2rem;"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Credit / Debit Card</h6>
                                    <small class="text-muted">Visa, Mastercard, JCB, AMEX</small>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-muted"></i>
                        </a>
                    </div>

                    <div class="alert alert-info mt-4 mb-0 small">
                        <i class="fas fa-shield-alt me-1"></i>
                        All transactions are secured with 256-bit SSL encryption. We never store your sensitive payment information.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold"><i class="fas fa-receipt me-2 text-primary"></i>Booking Summary</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-3 mb-3">
                        <img src="<?= dest_image_url($booking['dest_image'], $booking['destination_name']) ?>" alt="" class="rounded" style="width:80px;height:80px;object-fit:cover;">
                        <div>
                            <h6 class="mb-1 fw-bold"><?= sanitize($booking['event_title'] ?? $booking['destination_name']) ?></h6>
                            <small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= sanitize($booking['destination_name']) ?></small>
                            <?php if ($booking['booking_reference']): ?>
                                <div><span class="badge bg-secondary"><?= $booking['booking_reference'] ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Date</span>
                        <span class="fw-semibold"><?= date('M d, Y', strtotime($booking['visit_date'] ?? $booking['start_date'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Time</span>
                        <span class="fw-semibold"><?= date('h:i A', strtotime($booking['visit_time'] ?? $booking['start_time'])) ?></span>
                    </div>
                    <?php if (!empty($booking['duration_hours'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Duration</span>
                        <span class="fw-semibold"><?= $booking['duration_hours'] ?> hr(s)</span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Participants</span>
                        <span class="fw-semibold"><?= $booking['num_participants'] ?></span>
                    </div>

                    <hr>

                    <?php if ((float)$booking['service_fee'] > 0): ?>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Service Fee</span>
                        <span>₱<?= number_format((float)$booking['service_fee'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Tax (12%)</span>
                        <span>₱<?= number_format($tax, 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Service Fee (5%)</span>
                        <span>₱<?= number_format($service_fee, 2) ?></span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <span class="fw-bold fs-5">Total</span>
                        <span class="fw-bold fs-5 text-primary">₱<?= number_format($total, 2) ?></span>
                    </div>
                </div>
            </div>

            <a href="<?= BASE_URL ?>/tourist/browse.php" class="btn btn-outline-secondary w-100">
                <i class="fas fa-arrow-left me-1"></i>Back to Tours
            </a>
        </div>
    </div>

<style>
.payment-method-card:hover {
    border-color: #1a73e8 !important;
    background: #f8faff;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
</style>
<?php }); ?>
