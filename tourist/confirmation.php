<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');


require_once __DIR__ . '/../includes/classes/Payment.php';

$db = Database::getInstance()->getConnection();
$user = current_user();
$paymentModel = new Payment();

$payment_id = (int)($_GET['payment_id'] ?? 0);
if (!$payment_id) {
    flash_message('error', 'No payment specified.');
    redirect('/tourist/bookings.php');
}

$payment = $paymentModel->findById($payment_id);
if (!$payment || $payment['tourist_id'] != $user['id']) {
    flash_message('error', 'Payment not found.');
    redirect('/tourist/bookings.php');
}

render_page('tourist', 'confirmation.php', 'Payment Confirmation', function () use ($payment) {
?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if ($payment['payment_status'] === 'paid'): ?>
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:80px;height:80px;background:#d1fae5;">
                    <i class="fas fa-check-circle text-success" style="font-size:3rem;"></i>
                </div>
                <h3 class="fw-bold text-success">Payment Successful!</h3>
                <p class="text-muted">Your booking has been confirmed. Thank you for your payment.</p>
            </div>
            <?php elseif ($payment['payment_status'] === 'pending'): ?>
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:80px;height:80px;background:#fef3c7;">
                    <i class="fas fa-clock text-warning" style="font-size:3rem;"></i>
                </div>
                <h3 class="fw-bold text-warning">Payment Pending</h3>
                <p class="text-muted">Your payment is being processed. You will be notified once confirmed.</p>
            </div>
            <?php else: ?>
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width:80px;height:80px;background:#fee2e2;">
                    <i class="fas fa-times-circle text-danger" style="font-size:3rem;"></i>
                </div>
                <h3 class="fw-bold text-danger">Payment <?= ucfirst($payment['payment_status']) ?></h3>
                <p class="text-muted">There was an issue with your payment. Please try again.</p>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0 fw-semibold"><i class="fas fa-receipt me-2 text-primary"></i>Payment Receipt</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Reference Number</small>
                                <span class="fw-bold fs-5 text-primary"><?= sanitize($payment['reference_number']) ?></span>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Transaction ID</small>
                                <span class="font-monospace small"><?= sanitize($payment['transaction_id'] ?? 'N/A') ?></span>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Payment Status</small>
                                <?php
                                $status_class = match($payment['payment_status']) {
                                    'paid' => 'success', 'pending' => 'warning text-dark',
                                    'failed' => 'danger', 'refunded' => 'info', default => 'secondary'
                                };
                                ?>
                                <span class="badge bg-<?= $status_class ?> fs-6"><?= ucfirst($payment['payment_status']) ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Event</small>
                                <span class="fw-semibold"><?= sanitize($payment['event_title'] ?? 'N/A') ?></span>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Destination</small>
                                <span class="fw-semibold"><?= sanitize($payment['destination_name'] ?? 'N/A') ?></span>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Tour Date</small>
                                <span class="fw-semibold"><?= format_date($payment['start_date'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <small class="text-muted d-block">Payment Method</small>
                                <span class="fw-semibold">
                                    <?php if ($payment['payment_method'] === 'gcash'): ?>
                                        <span class="rounded d-inline-flex align-items-center justify-content-center me-1" style="width:20px;height:20px;background:#007dfe;font-size:0.45rem;color:#fff;font-weight:700;">GC</span>
                                        GCash
                                    <?php elseif ($payment['payment_method'] === 'maya'): ?>
                                        <span class="rounded d-inline-flex align-items-center justify-content-center me-1" style="width:20px;height:20px;background:#00c853;font-size:0.45rem;color:#fff;font-weight:700;">My</span>
                                        Maya
                                    <?php elseif ($payment['card_brand']): ?>
                                        <i class="fab fa-cc-<?= $payment['card_brand'] ?>"></i>
                                        <?= ucfirst($payment['card_brand']) ?>
                                    <?php else: ?>
                                        <?= ucfirst($payment['payment_method']) ?>
                                    <?php endif; ?>
                                    <?php if ($payment['card_last_four']): ?>
                                        ending in <?= sanitize($payment['card_last_four']) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <small class="text-muted d-block">Payment Date</small>
                                <span class="fw-semibold"><?= format_datetime($payment['payment_date'] ?? $payment['created_at']) ?></span>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Subtotal</span>
                        <span>₱<?= number_format($payment['amount'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Tax (12%)</span>
                        <span>₱<?= number_format($payment['tax'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Service Fee (5%)</span>
                        <span>₱<?= number_format($payment['service_fee'], 2) ?></span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <span class="fw-bold fs-5">Total Paid</span>
                        <span class="fw-bold fs-5 text-primary">₱<?= number_format($payment['total_amount'], 2) ?></span>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 justify-content-center">
                <a href="<?= BASE_URL ?>/tourist/bookings.php" class="btn btn-primary">
                    <i class="fas fa-ticket me-1"></i>View My Bookings
                </a>
                <a href="<?= BASE_URL ?>/tourist/browse.php" class="btn btn-outline-secondary">
                    <i class="fas fa-search me-1"></i>Browse More Tours
                </a>
            </div>
        </div>
    </div>
<?php }); ?>
