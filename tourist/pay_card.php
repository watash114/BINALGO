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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_card'])) {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        flash_message('error', 'Invalid security token.');
        redirect("/tourist/pay_card.php?booking_id={$booking_id}");
    }

    $card_number = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
    $card_holder = trim($_POST['card_holder'] ?? '');
    $expiry = trim($_POST['expiry'] ?? '');
    $cvv = trim($_POST['cvv'] ?? '');

    $errors = [];
    if (strlen($card_number) < 13) $errors[] = 'Invalid card number.';
    if (empty($card_holder)) $errors[] = 'Cardholder name is required.';
    if (!preg_match('/^\d{2}\/\d{2}$/', $expiry)) $errors[] = 'Invalid expiry date (MM/YY).';
    if (strlen($cvv) < 3) $errors[] = 'Invalid CVV.';

    if ($errors) {
        foreach ($errors as $e) flash_message('error', $e);
        redirect("/tourist/pay_card.php?booking_id={$booking_id}");
    }

    $ref = $paymentModel->generateReferenceNumber();
    $transaction_id = 'card_' . bin2hex(random_bytes(12));
    $card_last_four = substr($card_number, -4);
    $card_brand = $paymentModel->detectCardBrand($card_number);

    if ($existing_payment) {
        $payment_id = $existing_payment['id'];
        $db->prepare("UPDATE payments SET reference_number = :ref, payment_method = 'card', card_last_four = :last4, card_brand = :brand, payment_status = 'pending', updated_at = NOW() WHERE id = :id")
            ->execute([':ref' => $ref, ':last4' => $card_last_four, ':brand' => $card_brand, ':id' => $payment_id]);
    } else {
        $payment_id = $paymentModel->create([
            'booking_id'       => $booking_id,
            'tourist_id'       => $user['id'],
            'amount'           => $amount,
            'tax'              => $tax,
            'service_fee'      => $service_fee,
            'total_amount'     => $total,
            'payment_method'   => 'card',
            'card_last_four'   => $card_last_four,
            'card_brand'       => $card_brand,
            'reference_number' => $ref,
            'payment_status'   => 'pending',
        ]);
    }

    $result = $paymentModel->processCardPayment($payment_id, $transaction_id, $card_number, $card_holder, $expiry, $cvv);

    if ($result['success']) {
        ActivityLog::log($user['id'], 'payment_success', "Card ({$card_brand}) payment of ₱" . number_format($total, 2) . " for booking #{$booking_id}");

        $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, link, is_read, created_at) VALUES (:uid, :title, :msg, :link, 0, NOW())");
        $notif_stmt->execute([
            ':uid'   => $user['id'],
            ':title' => 'Payment Confirmed',
            ':msg'   => "Your {$card_brand} card payment of ₱" . number_format($total, 2) . " for {$booking['event_title']} has been confirmed. Reference: {$ref}",
            ':link'  => '/tourist/confirmation.php?payment_id=' . $payment_id,
        ]);

        redirect("/tourist/confirmation.php?payment_id={$payment_id}");
    } else {
        ActivityLog::log($user['id'], 'payment_failed', "Card payment failed for booking #{$booking_id}: {$result['message']}");
        flash_message('error', $result['message']);
        redirect("/tourist/pay_card.php?booking_id={$booking_id}");
    }
}

render_page('tourist', 'pay_card.php', 'Pay with Card', function () use ($booking, $amount, $tax, $service_fee, $total, $booking_id) {
?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom d-flex align-items-center gap-2">
                    <i class="fas fa-credit-card text-primary"></i>
                    <h5 class="mb-0 fw-semibold">Pay with Card</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="cardForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="process_card" value="1">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Card Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fas fa-credit-card text-muted"></i></span>
                                <input type="text" name="card_number" id="cardNumber" class="form-control" placeholder="1234 5678 9012 3456" maxlength="19" required
                                       oninput="formatCardNumber(this); updateBrand()">
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <img src="https://img.icons8.com/color/24/visa.png" alt="Visa" class="brand-img" style="height:20px;opacity:0.4" id="brandVisa">
                                <img src="https://img.icons8.com/color/24/mastercard.png" alt="Mastercard" class="brand-img" style="height:20px;opacity:0.4" id="brandMC">
                                <img src="https://img.icons8.com/color/24/jcb.png" alt="JCB" class="brand-img" style="height:20px;opacity:0.4" id="brandJCB">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Cardholder Name <span class="text-danger">*</span></label>
                            <input type="text" name="card_holder" class="form-control" placeholder="Name as it appears on your card" required>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Expiry Date <span class="text-danger">*</span></label>
                                <input type="text" name="expiry" class="form-control" placeholder="MM/YY" maxlength="5" required
                                       oninput="formatExpiry(this)">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">CVV <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="cvv" class="form-control" placeholder="***" maxlength="4" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="toggleCvv()">
                                        <i class="fas fa-eye" id="cvvEye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Amount to Pay</label>
                            <div class="form-control bg-light fw-bold text-primary">₱<?= number_format($total, 2) ?></div>
                        </div>

                        <div class="alert alert-success small mb-3">
                            <i class="fas fa-shield-alt me-1"></i>
                            Your card information is encrypted and processed securely. We never store your full card number.
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100" id="payBtn">
                            <i class="fas fa-lock me-2"></i>Pay ₱<?= number_format($total, 2) ?>
                        </button>
                    </form>
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
function formatCardNumber(input) {
    let val = input.value.replace(/\D/g, '');
    let formatted = val.match(/.{1,4}/g)?.join(' ') || val;
    input.value = formatted;
}

function formatExpiry(input) {
    let val = input.value.replace(/\D/g, '');
    if (val.length >= 2) val = val.substring(0,2) + '/' + val.substring(2);
    input.value = val;
}

function toggleCvv() {
    const input = document.querySelector('[name="cvv"]');
    const eye = document.getElementById('cvvEye');
    if (input.type === 'password') {
        input.type = 'text';
        eye.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        eye.className = 'fas fa-eye';
    }
}

function detectBrand(num) {
    const n = num.replace(/\D/g, '');
    if (/^4/.test(n)) return 'visa';
    if (/^5[1-5]/.test(n) || /^2[2-7]/.test(n)) return 'mastercard';
    if (/^35(?:2[89]|[3-8])/.test(n)) return 'jcb';
    return '';
}

function updateBrand() {
    const num = document.getElementById('cardNumber').value;
    const brand = detectBrand(num);
    document.querySelectorAll('.brand-img').forEach(el => el.style.opacity = '0.4');
    if (brand === 'visa') document.getElementById('brandVisa').style.opacity = '1';
    else if (brand === 'mastercard') document.getElementById('brandMC').style.opacity = '1';
    else if (brand === 'jcb') document.getElementById('brandJCB').style.opacity = '1';
}

document.getElementById('cardForm').addEventListener('submit', function() {
    const btn = document.getElementById('payBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
});
</script>
<?php }); ?>
