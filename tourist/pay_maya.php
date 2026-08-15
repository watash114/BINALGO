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
    "SELECT b.*, s.start_date, s.end_date, s.start_time, s.end_time,
            e.title as event_title, e.price as event_price, e.duration_hours,
            d.name as destination_name, d.location as destination_location, d.image as dest_image
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.id
     JOIN events e ON s.event_id = e.id
     JOIN destinations d ON e.destination_id = d.id
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

$amount = (float)$booking['event_price'] * (int)$booking['num_participants'];
$tax = round($amount * 0.12, 2);
$service_fee = round($amount * 0.05, 2);
$total = $amount + $tax + $service_fee;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_maya'])) {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        flash_message('error', 'Invalid security token.');
        redirect("/tourist/pay_maya.php?booking_id={$booking_id}");
    }

    $maya_number = trim($_POST['maya_number'] ?? '');
    $maya_name = trim($_POST['maya_name'] ?? '');

    if (empty($maya_number) || empty($maya_name)) {
        flash_message('error', 'Maya phone number and account name are required.');
        redirect("/tourist/pay_maya.php?booking_id={$booking_id}");
    }

    if (!preg_match('/^(09|\+639)\d{9}$/', $maya_number)) {
        flash_message('error', 'Invalid Maya phone number format.');
        redirect("/tourist/pay_maya.php?booking_id={$booking_id}");
    }

    $ref = $paymentModel->generateReferenceNumber();
    $transaction_id = 'maya_' . bin2hex(random_bytes(12));

    if ($existing_payment) {
        $payment_id = $existing_payment['id'];
        $db->prepare("UPDATE payments SET reference_number = :ref, payment_method = 'maya', payment_status = 'pending', updated_at = datetime('now') WHERE id = :id")
            ->execute([':ref' => $ref, ':id' => $payment_id]);
    } else {
        $payment_id = $paymentModel->create([
            'booking_id'       => $booking_id,
            'tourist_id'       => $user['id'],
            'amount'           => $amount,
            'tax'              => $tax,
            'service_fee'      => $service_fee,
            'total_amount'     => $total,
            'payment_method'   => 'maya',
            'reference_number' => $ref,
            'payment_status'   => 'pending',
        ]);
    }

    $result = $paymentModel->processMayaPayment($payment_id, $transaction_id, $maya_number, $maya_name);

    if ($result['success']) {
        ActivityLog::log($user['id'], 'payment_success', "Maya payment of ₱" . number_format($total, 2) . " for booking #{$booking_id}");

        $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, link, is_read, created_at) VALUES (:uid, :title, :msg, :link, 0, datetime('now'))");
        $notif_stmt->execute([
            ':uid'   => $user['id'],
            ':title' => 'Payment Confirmed (Maya)',
            ':msg'   => "Your Maya payment of ₱" . number_format($total, 2) . " for {$booking['event_title']} has been confirmed. Reference: {$ref}",
            ':link'  => '/tourist/confirmation.php?payment_id=' . $payment_id,
        ]);

        redirect("/tourist/confirmation.php?payment_id={$payment_id}");
    } else {
        ActivityLog::log($user['id'], 'payment_failed', "Maya payment failed for booking #{$booking_id}: {$result['message']}");
        flash_message('error', $result['message']);
        redirect("/tourist/pay_maya.php?booking_id={$booking_id}");
    }
}

render_page('tourist', 'pay_maya.php', 'Pay with Maya', function () use ($booking, $amount, $tax, $service_fee, $total, $booking_id) {
?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2">
                    <div class="d-flex align-items-center justify-content-center rounded" style="width:32px;height:32px;background:#00c853;">
                        <span class="text-white fw-bold" style="font-size:0.55rem;">Maya</span>
                    </div>
                    <h5 class="mb-0 fw-semibold">Pay with Maya</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border mb-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:#e8f5e9;">
                                <i class="fas fa-mobile-screen-button text-success" style="font-size:1.3rem;"></i>
                            </div>
                            <div>
                                <div class="fw-semibold">Pay via Maya Wallet</div>
                                <small class="text-muted">Enter your Maya details to complete payment</small>
                            </div>
                        </div>

                        <div class="text-center mb-4 p-4 bg-white rounded border" id="qrSection">
                            <div class="mb-2">
                                <div style="width:180px;height:180px;margin:0 auto;background:#f0f0f0;border:2px dashed #ccc;border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                                    <i class="fas fa-mobile-screen-button text-muted mb-1" style="font-size:4rem;opacity:0.3;"></i>
                                    <small class="text-muted">Maya QR</small>
                                </div>
                            </div>
                            <div class="fw-bold text-success fs-5">₱<?= number_format($total, 2) ?></div>
                            <small class="text-muted">Scan to pay via Maya app</small>
                        </div>

                        <div class="text-center mb-3">
                            <small class="text-muted">— OR pay manually —</small>
                        </div>

                        <form method="POST" id="mayaForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="process_maya" value="1">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Maya Phone Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fas fa-mobile-alt text-muted"></i></span>
                                    <input type="text" name="maya_number" class="form-control" placeholder="09XXXXXXXXX" maxlength="11" required
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                </div>
                                <small class="text-muted">Enter the mobile number linked to your Maya account</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Account Name <span class="text-danger">*</span></label>
                                <input type="text" name="maya_name" class="form-control" placeholder="Full name as registered in Maya" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Amount to Pay</label>
                                <div class="form-control bg-light fw-bold text-success">₱<?= number_format($total, 2) ?></div>
                            </div>

                            <div class="alert alert-warning small mb-3">
                                <i class="fas fa-info-circle me-1"></i>
                                You will receive a push notification on your Maya app. Approve the payment to complete.
                            </div>

                            <button type="submit" class="btn btn-success btn-lg w-100" id="payBtn" style="background:#00c853;border-color:#00c853;">
                                <i class="fas fa-lock me-2"></i>Pay ₱<?= number_format($total, 2) ?> via Maya
                            </button>
                        </form>
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
                            <h6 class="mb-1 fw-bold"><?= sanitize($booking['event_title']) ?></h6>
                            <small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= sanitize($booking['destination_name']) ?></small>
                        </div>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Date</span>
                        <span class="fw-semibold"><?= date('M d, Y', strtotime($booking['start_date'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Time</span>
                        <span class="fw-semibold"><?= date('h:i A', strtotime($booking['start_time'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Participants</span>
                        <span class="fw-semibold"><?= $booking['num_participants'] ?></span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between mb-1">
                        <span>Tour (<?= $booking['num_participants'] ?> x ₱<?= number_format($booking['event_price'], 2) ?>)</span>
                        <span>₱<?= number_format($amount, 2) ?></span>
                    </div>
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

            <a href="<?= BASE_URL ?>/tourist/checkout.php?booking_id=<?= $booking_id ?>" class="btn btn-outline-secondary w-100">
                <i class="fas fa-arrow-left me-1"></i>Back to Payment Methods
            </a>
        </div>
    </div>

<script>
document.getElementById('mayaForm').addEventListener('submit', function(e) {
    let btn = document.getElementById('payBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing Maya Payment...';
});
</script>
<?php }); ?>
