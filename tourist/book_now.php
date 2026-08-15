<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$destModel = new Destination();
$user = current_user();
$destId = (int)($_GET['id'] ?? 0);

if (!$destId) redirect('/tourist/destinations.php');

$dest = $destModel->findById($destId);
if (!$dest || $dest['status'] !== 'active' || !$dest['booking_enabled']) {
    flash_message('error', 'Booking is not available for this destination.');
    redirect('/tourist/destinations.php');
}

$cat_labels = [
    'beaches'              => ['label' => 'Beaches',              'icon' => 'fas fa-umbrella-beach',  'color' => '#3b82f6'],
    'heritage_culture'     => ['label' => 'Heritage & Culture',   'icon' => 'fas fa-landmark',        'color' => '#f97316'],
    'religious_sites'      => ['label' => 'Religious Sites',      'icon' => 'fas fa-church',          'color' => '#8b5cf6'],
    'nature_adventure'     => ['label' => 'Nature & Adventure',   'icon' => 'fas fa-mountain',        'color' => '#10b981'],
    'food_local'           => ['label' => 'Food & Local',         'icon' => 'fas fa-utensils',        'color' => '#ec4899'],
    'other'                => ['label' => 'Tourist Spot',         'icon' => 'fas fa-map-pin',         'color' => '#6b7280'],
];
$catInfo = $cat_labels[$dest['category']] ?? $cat_labels['other'];
$catColor = $catInfo['color'];

$feePrice = $dest['booking_price'] > 0 ? $dest['booking_price'] : $dest['entrance_fee'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/tourist/book_now.php?id=' . $destId);
    }

    if (isset($_POST['confirm_booking'])) {
        try {
            $visitDate = $_POST['visit_date'] ?? '';
            $visitTime = $_POST['visit_time'] ?? '';
            $numGuests = max(1, (int)($_POST['num_guests'] ?? 1));
            $specialRequests = sanitize(trim($_POST['special_requests'] ?? ''));
            $fullName = sanitize(trim($_POST['full_name'] ?? ''));
            $email = sanitize(trim($_POST['email'] ?? ''));
            $contactNumber = sanitize(trim($_POST['contact_number'] ?? ''));
            $paymentMethod = sanitize(trim($_POST['payment_method'] ?? ''));

            if (!$visitDate || !$visitTime || !$fullName || !$email || !$contactNumber || !$paymentMethod) {
                flash_message('error', 'Please fill in all required fields.');
                redirect('/tourist/book_now.php?id=' . $destId);
            }

            if (!strtotime($visitDate) || $visitDate < date('Y-m-d')) {
                flash_message('error', 'Please select a valid future date.');
                redirect('/tourist/book_now.php?id=' . $destId);
            }

            $bookedToday = $db->prepare(
                "SELECT COALESCE(SUM(num_participants), 0) as total FROM bookings WHERE destination_id = :did AND visit_date = :dt AND status NOT IN ('cancelled')"
            );
            $bookedToday->execute([':did' => $destId, ':dt' => $visitDate]);
            $currentBooked = (int)$bookedToday->fetch()['total'];
            if ($dest['capacity_limit'] > 0 && ($currentBooked + $numGuests) > $dest['capacity_limit']) {
                flash_message('error', 'Not enough capacity available for this date. Only ' . max(0, $dest['capacity_limit'] - $currentBooked) . ' spots left.');
                redirect('/tourist/book_now.php?id=' . $destId);
            }

            $baseFee = (float)$feePrice;
            $serviceFee = ($baseFee * $numGuests) * 0.05;
            $totalPrice = ($baseFee * $numGuests) + $serviceFee;

            $ref = 'BK-' . strtoupper(substr(uniqid(), -8));

            $insert = $db->prepare(
                "INSERT INTO bookings (booking_reference, tourist_id, destination_id, schedule_id, full_name, email, contact_number, visit_date, visit_time, num_participants, total_price, service_fee, payment_method, status, payment_status, special_requests, created_at)
                 VALUES (:ref, :uid, :did, NULL, :fname, :email, :phone, :vdate, :vtime, :num, :total, :sfee, :pmethod, 'pending', 'unpaid', :req, datetime('now'))"
            );
            $insert->execute([
                ':ref'   => $ref,
                ':uid'   => $_SESSION['user_id'],
                ':did'   => $destId,
                ':fname' => $fullName,
                ':email' => $email,
                ':phone' => $contactNumber,
                ':vdate' => $visitDate,
                ':vtime' => $visitTime,
                ':num'   => $numGuests,
                ':total' => $totalPrice,
                ':sfee'  => $serviceFee,
                ':pmethod' => $paymentMethod,
                ':req'   => $specialRequests,
            ]);
            $newBookingId = (int)$db->lastInsertId();

            $notif = new Notification();
            $notif->notifyBookingCreated($newBookingId);

            ActivityLog::log($_SESSION['user_id'], 'booking_created', "Created booking #{$ref} for {$dest['name']} on {$visitDate}");
            flash_message('success', "Booking #{$ref} created! Proceed to payment.");
            redirect('/tourist/checkout.php?booking_id=' . $newBookingId);
        } catch (Exception $e) {
            flash_message('error', 'Booking failed. Please try again.');
            redirect('/tourist/book_now.php?id=' . $destId);
        }
    }
}

render_page('tourist', 'book_now.php', 'Book ' . $dest['name'], function() use ($dest, $destId, $catInfo, $catColor, $feePrice, $user) {
?>

<style>
.bn-hero { display:flex; align-items:center; gap:20px; padding:24px; border-radius:16px; margin-bottom:24px; background:linear-gradient(135deg, <?= $catColor ?>15, <?= $catColor ?>05); border:1px solid <?= $catColor ?>20; }
.bn-hero-img { width:100px; height:100px; border-radius:14px; object-fit:cover; flex-shrink:0; }
.bn-hero-name { font-weight:700; font-size:1.15rem; color:var(--text-primary,#1e293b); margin-bottom:4px; }
.bn-hero-loc { font-size:.85rem; color:var(--text-muted,#64748b); }
.bn-hero-fee { font-size:1.3rem; font-weight:800; color:#0c6e5e; }

.bn-card { background:var(--card-bg,#fff); border-radius:16px; border:none; box-shadow:0 1px 3px rgba(0,0,0,0.06); overflow:hidden; }
.bn-card-body { padding:28px; }

.bn-progress { display:flex; align-items:center; justify-content:center; gap:0; margin-bottom:28px; }
.bn-p-step { display:flex; align-items:center; gap:8px; }
.bn-p-dot { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; border:2px solid var(--border-color,#e2e8f0); background:var(--card-bg,#fff); color:var(--text-muted,#94a3b8); transition:all .3s; }
.bn-p-step.active .bn-p-dot { background:#0c6e5e; border-color:#0c6e5e; color:#fff; box-shadow:0 2px 8px rgba(12,110,94,0.3); }
.bn-p-step.done .bn-p-dot { background:#0c6e5e; border-color:#0c6e5e; color:#fff; }
.bn-p-label { font-size:.78rem; font-weight:600; color:var(--text-muted,#94a3b8); }
.bn-p-step.active .bn-p-label { color:var(--text-primary,#1e293b); }
.bn-p-step.done .bn-p-label { color:#0c6e5e; }
.bn-p-line { width:48px; height:2px; background:var(--border-color,#e2e8f0); margin:0 6px; border-radius:2px; transition:background .3s; }
.bn-p-line.done { background:#0c6e5e; }

.bn-field { margin-bottom:18px; }
.bn-label { display:block; font-size:.8rem; font-weight:700; color:var(--text-primary,#1e293b); margin-bottom:6px; }
.bn-input-wrap { position:relative; }
.bn-input-wrap .bn-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted,#94a3b8); font-size:.85rem; pointer-events:none; transition:color .2s; }
.bn-input-wrap:focus-within .bn-icon { color:#0c6e5e; }
.bn-input { background:var(--card-bg,#fff); border:1.5px solid var(--border-color,#e2e8f0); border-radius:12px; padding:13px 14px 13px 42px; color:var(--text-primary,#1e293b); width:100%; font-size:.9rem; transition:all .2s; }
.bn-input:focus { border-color:#0c6e5e; outline:none; box-shadow:0 0 0 3px rgba(12,110,94,0.1); }
.bn-input::placeholder { color:var(--text-muted,#94a3b8); }

.bn-guest { display:flex; align-items:center; border:1.5px solid var(--border-color,#e2e8f0); border-radius:12px; overflow:hidden; background:var(--card-bg,#fff); }
.bn-guest button { width:44px; height:48px; border:none; background:transparent; color:var(--text-primary,#1e293b); font-size:1.1rem; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; }
.bn-guest button:hover { background:rgba(12,110,94,0.08); color:#0c6e5e; }
.bn-guest button:disabled { opacity:.3; cursor:not-allowed; }
.bn-guest .bn-count { width:52px; text-align:center; font-weight:700; font-size:1.05rem; color:var(--text-primary,#1e293b); border-left:1.5px solid var(--border-color,#e2e8f0); border-right:1.5px solid var(--border-color,#e2e8f0); padding:10px 0; }

.bn-summary { background:linear-gradient(135deg, rgba(12,110,94,0.06), rgba(12,110,94,0.02)); border:1px solid rgba(12,110,94,0.15); border-radius:12px; padding:18px 20px; margin-top:20px; }
.bn-summary-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; }
.bn-summary-row.total { border-top:1.5px solid rgba(12,110,94,0.15); margin-top:8px; padding-top:10px; }
.bn-summary-row .label { color:var(--text-muted,#64748b); font-size:.82rem; }
.bn-summary-row .value { color:var(--text-primary,#1e293b); font-size:.88rem; font-weight:600; }
.bn-summary-row.total .label { font-weight:700; color:var(--text-primary,#1e293b); }
.bn-summary-row.total .value { color:#0c6e5e; font-size:1.15rem; font-weight:800; }

.bn-btn { background:#0c6e5e; color:#fff; border:none; border-radius:12px; padding:14px 24px; font-weight:600; font-size:.92rem; cursor:pointer; transition:all .2s; width:100%; display:flex; align-items:center; justify-content:center; gap:8px; }
.bn-btn:hover { background:#0a5c4f; transform:translateY(-1px); box-shadow:0 4px 14px rgba(12,110,94,0.35); }
.bn-btn:disabled { opacity:.5; cursor:not-allowed; transform:none; box-shadow:none; }
.bn-btn-outline { background:var(--card-bg,#fff); border:1.5px solid var(--border-color,#e2e8f0); color:var(--text-primary,#475569); border-radius:12px; padding:12px 20px; font-weight:600; font-size:.88rem; cursor:pointer; transition:all .2s; }
.bn-btn-outline:hover { background:var(--border-color,#f1f5f9); }

.bn-step { display:none; animation:bnFadeIn .3s ease; }
.bn-step.active { display:block; }
@keyframes bnFadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

.bn-back-link { display:inline-flex; align-items:center; gap:6px; color:var(--text-muted,#64748b); text-decoration:none; font-size:.88rem; font-weight:500; transition:color .2s; margin-bottom:20px; }
.bn-back-link:hover { color:#0c6e5e; }

@media (max-width:767px) {
    .bn-hero { flex-direction:column; text-align:center; padding:20px; }
    .bn-hero-img { width:80px; height:80px; }
    .bn-card-body { padding:20px; }
}
</style>

<a href="destination_detail.php?id=<?= $destId ?>" class="bn-back-link"><i class="fas fa-arrow-left"></i> Back to destination</a>

<!-- Destination Summary -->
<div class="bn-hero">
    <?php if (!empty($dest['image'])): ?>
        <img src="<?= dest_image_url($dest['image']) ?>" class="bn-hero-img" alt="<?= sanitize($dest['name']) ?>">
    <?php else: ?>
        <div class="bn-hero-img" style="background:linear-gradient(135deg, <?= $catColor ?>30, <?= $catColor ?>10);display:flex;align-items:center;justify-content:center;">
            <i class="<?= $catInfo['icon'] ?>" style="font-size:1.8rem;color:<?= $catColor ?>;"></i>
        </div>
    <?php endif; ?>
    <div class="flex-grow-1">
        <div class="bn-hero-name"><?= sanitize($dest['name']) ?></div>
        <div class="bn-hero-loc"><i class="fas fa-map-pin me-1" style="color:<?= $catColor ?>;"></i><?= sanitize($dest['location']) ?></div>
    </div>
    <div class="bn-hero-fee">₱<?= number_format($feePrice, 2) ?><span style="font-size:.72rem;font-weight:500;color:var(--text-muted,#94a3b8);display:block;">per person</span></div>
</div>

<!-- Booking Card -->
<div class="bn-card">
    <div class="bn-card-body">
        <!-- Progress -->
        <div class="bn-progress">
            <div class="bn-p-step active" id="bnp1"><div class="bn-p-dot">1</div><div class="bn-p-label">Details</div></div>
            <div class="bn-p-line" id="bnpl1"></div>
            <div class="bn-p-step" id="bnp2"><div class="bn-p-dot">2</div><div class="bn-p-label">Confirm</div></div>
        </div>

        <form method="POST" id="bookingForm">
            <?= csrf_field() ?>
            <input type="hidden" name="confirm_booking" value="1">

            <!-- Step 1: Details -->
            <div class="bn-step active" id="bns1">
                <div class="bn-field">
                    <label class="bn-label">Full Name</label>
                    <div class="bn-input-wrap">
                        <i class="fas fa-user bn-icon"></i>
                        <input type="text" name="full_name" class="bn-input" value="<?= sanitize($user['name'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="bn-field">
                            <label class="bn-label">Email Address</label>
                            <div class="bn-input-wrap">
                                <i class="fas fa-envelope bn-icon"></i>
                                <input type="email" name="email" class="bn-input" value="<?= sanitize($user['email'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="bn-field">
                            <label class="bn-label">Contact Number</label>
                            <div class="bn-input-wrap">
                                <i class="fas fa-phone bn-icon"></i>
                                <input type="tel" name="contact_number" class="bn-input" value="<?= sanitize($user['phone'] ?? '') ?>" placeholder="09XX XXX XXXX" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="bn-field">
                            <label class="bn-label">Visit Date</label>
                            <div class="bn-input-wrap">
                                <i class="fas fa-calendar-day bn-icon"></i>
                                <input type="date" name="visit_date" class="bn-input" id="visit_date" min="<?= date('Y-m-d', strtotime('+' . max(1, (int)$dest['advance_booking_days']) . ' day')) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="bn-field">
                            <label class="bn-label">Preferred Time</label>
                            <div class="bn-input-wrap">
                                <i class="fas fa-clock bn-icon"></i>
                                <input type="time" name="visit_time" class="bn-input" id="visit_time" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="bn-field">
                            <label class="bn-label">Number of Guests</label>
                            <div class="bn-guest">
                                <button type="button" id="guestMinus" onclick="adjustGuests(-1)"><i class="fas fa-minus"></i></button>
                                <div class="bn-count" id="guestDisplay">1</div>
                                <button type="button" id="guestPlus" onclick="adjustGuests(1)"><i class="fas fa-plus"></i></button>
                                <input type="hidden" name="num_guests" id="num_guests" value="1">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="bn-field">
                            <label class="bn-label">Payment Method</label>
                            <div class="bn-input-wrap">
                                <i class="fas fa-credit-card bn-icon"></i>
                                <select name="payment_method" class="bn-input" required>
                                    <option value="">Select payment</option>
                                    <option value="gcash">GCash</option>
                                    <option value="maya">Maya</option>
                                    <option value="cash">Cash on Arrival</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($feePrice > 0): ?>
                <div class="bn-summary">
                    <div class="bn-summary-row">
                        <span class="label">Entrance/Booking Fee</span>
                        <span class="value">₱<span id="priceUnit"><?= number_format($feePrice, 2) ?></span></span>
                    </div>
                    <div class="bn-summary-row">
                        <span class="label">Guests</span>
                        <span class="value">× <span id="priceQty">1</span></span>
                    </div>
                    <div class="bn-summary-row">
                        <span class="label">Subtotal</span>
                        <span class="value" id="priceSub">₱<?= number_format($feePrice, 2) ?></span>
                    </div>
                    <div class="bn-summary-row">
                        <span class="label">Service Fee (5%)</span>
                        <span class="value" id="priceFee">₱<?= number_format($feePrice * 0.05, 2) ?></span>
                    </div>
                    <div class="bn-summary-row total">
                        <span class="label">Total</span>
                        <span class="value" id="priceTotal">₱<?= number_format($feePrice * 1.05, 2) ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <button type="button" class="bn-btn mt-4" onclick="nextStep(2)">
                    Continue <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <!-- Step 2: Confirm -->
            <div class="bn-step" id="bns2">
                <p class="text-center mb-3" style="color:var(--text-muted,#64748b);font-size:.88rem;">Review your booking details below</p>

                <div id="bookingSummary" style="background:var(--card-bg,#fff);border:1.5px solid var(--border-color,#e2e8f0);border-radius:12px;padding:18px 20px;margin-bottom:18px;"></div>

                <div class="bn-field">
                    <label class="bn-label"><i class="fas fa-comment-dots me-1" style="color:#0c6e5e;"></i>Special Requests <span class="fw-normal" style="color:var(--text-muted,#94a3b8);">(optional)</span></label>
                    <textarea name="special_requests" class="bn-input" rows="3" style="padding-left:14px;resize:vertical;min-height:80px;" placeholder="Dietary needs, accessibility requirements, or special occasions..."></textarea>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="button" class="bn-btn-outline" onclick="nextStep(1)"><i class="fas fa-arrow-left me-1"></i>Back</button>
                    <button type="submit" class="bn-btn flex-grow-1"><i class="fas fa-lock me-1"></i>Confirm Booking</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let currentStep = 1;
const unitPrice = <?= (float)$feePrice ?>;
const maxGuests = <?= $dest['max_guests_per_booking'] ?: 10 ?>;

function showToast(message, type) {
    type = type || 'warning';
    var existing = document.querySelector('.custom-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.className = 'custom-toast';
    var icon = type === 'error' ? 'fa-circle-exclamation' : (type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation');
    var colors = { error: '#ef4444', success: '#10b981', warning: '#f59e0b' };
    toast.innerHTML = '<div style="display:flex;align-items:center;gap:12px;"><div style="width:36px;height:36px;border-radius:10px;background:' + colors[type] + '15;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas ' + icon + '" style="color:' + colors[type] + ';font-size:0.95rem;"></i></div><span style="font-size:0.88rem;font-weight:500;color:#1e293b;">' + message + '</span></div>';
    toast.style.cssText = 'position:fixed;top:24px;right:24px;z-index:10000;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 20px;box-shadow:0 12px 40px rgba(0,0,0,0.12);transform:translateX(120%);transition:transform .35s cubic-bezier(.4,0,.2,1),opacity .35s;max-width:360px;';
    document.body.appendChild(toast);
    requestAnimationFrame(function() { toast.style.transform = 'translateX(0)'; });
    setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        setTimeout(function() { toast.remove(); }, 350);
    }, 3000);
}

function adjustGuests(delta) {
    const input = document.getElementById('num_guests');
    const display = document.getElementById('guestDisplay');
    let val = parseInt(input.value) || 1;
    val = Math.max(1, Math.min(maxGuests, val + delta));
    input.value = val;
    display.textContent = val;
    document.getElementById('guestMinus').disabled = (val <= 1);
    document.getElementById('guestPlus').disabled = (val >= maxGuests);
    updatePriceDisplay(val);
}

function updatePriceDisplay(qty) {
    const subtotal = unitPrice * qty;
    const fee = subtotal * 0.05;
    const total = subtotal + fee;
    const qEl = document.getElementById('priceQty');
    const sEl = document.getElementById('priceSub');
    const fEl = document.getElementById('priceFee');
    const tEl = document.getElementById('priceTotal');
    if (qEl) qEl.textContent = qty;
    if (sEl) sEl.textContent = '₱' + subtotal.toFixed(2);
    if (fEl) fEl.textContent = '₱' + fee.toFixed(2);
    if (tEl) tEl.textContent = '₱' + total.toFixed(2);
}

function nextStep(step) {
    if (step === 2 && currentStep === 1) {
        const date = document.getElementById('visit_date').value;
        const time = document.getElementById('visit_time').value;
        const guests = document.getElementById('num_guests').value;
        if (!date || !time || !guests) {
            showToast('Please fill in date, time, and number of guests.', 'warning');
            return;
        }
        updateSummary();
    }
    document.querySelectorAll('.bn-step').forEach(el => el.classList.remove('active'));
    document.getElementById('bns' + step).classList.add('active');

    document.querySelectorAll('.bn-p-step').forEach((el, i) => {
        el.classList.remove('active', 'done');
        if (i + 1 < step) el.classList.add('done');
        if (i + 1 === step) el.classList.add('active');
    });
    document.querySelectorAll('.bn-p-line').forEach((el, i) => {
        el.classList.toggle('done', i + 1 < step);
    });

    currentStep = step;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateSummary() {
    const date = document.getElementById('visit_date').value;
    const time = document.getElementById('visit_time').value;
    const guests = parseInt(document.getElementById('num_guests').value) || 1;
    const name = document.querySelector('[name="full_name"]').value;
    const email = document.querySelector('[name="email"]').value;
    const phone = document.querySelector('[name="contact_number"]').value;
    const payment = document.querySelector('[name="payment_method"]').value;

    const subtotal = unitPrice * guests;
    const serviceFee = subtotal * 0.05;
    const total = subtotal + serviceFee;

    let html = '<div style="display:flex;flex-direction:column;gap:10px;">';
    html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Destination</span><span style="font-weight:600;font-size:.88rem;color:var(--text-primary,#1e293b);"><?= sanitize($dest["name"]) ?></span></div>';
    if (date) {
        const d = new Date(date + 'T12:00:00');
        html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Date</span><span style="font-weight:600;font-size:.88rem;color:var(--text-primary,#1e293b);">' + d.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' }) + '</span></div>';
    }
    if (time) html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Time</span><span style="font-weight:600;font-size:.88rem;color:var(--text-primary,#1e293b);">' + time + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Guests</span><span style="font-weight:600;font-size:.88rem;color:var(--text-primary,#1e293b);">' + guests + '</span></div>';
    if (name) html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Name</span><span style="font-weight:600;font-size:.88rem;color:var(--text-primary,#1e293b);">' + name + '</span></div>';
    if (payment) html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Payment</span><span style="font-weight:600;font-size:.88rem;color:var(--text-primary,#1e293b);">' + payment.charAt(0).toUpperCase() + payment.slice(1) + '</span></div>';
    html += '<hr style="border:none;border-top:1px solid var(--border-color,#e2e8f0);margin:4px 0;">';
    html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Subtotal</span><span style="font-weight:600;font-size:.88rem;">₱' + subtotal.toFixed(2) + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;"><span style="color:var(--text-muted,#64748b);font-size:.82rem;">Service Fee (5%)</span><span style="font-weight:600;font-size:.88rem;">₱' + serviceFee.toFixed(2) + '</span></div>';
    html += '<div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1.5px solid rgba(12,110,94,0.15);"><span style="font-weight:700;color:var(--text-primary,#1e293b);">Total</span><span style="font-weight:800;font-size:1.1rem;color:#0c6e5e;">₱' + total.toFixed(2) + '</span></div>';
    html += '</div>';

    document.getElementById('bookingSummary').innerHTML = html;
}

const today = new Date();
const minDate = new Date(today);
minDate.setDate(minDate.getDate() + <?= max(1, (int)$dest['advance_booking_days']) ?>);
document.getElementById('visit_date').min = minDate.toISOString().split('T')[0];

<?php if ($dest['operating_hours_open']): ?>
document.getElementById('visit_time').value = '<?= $dest['operating_hours_open'] ?>';
<?php endif; ?>

document.getElementById('guestMinus').disabled = true;
</script>

<?php }); ?>
