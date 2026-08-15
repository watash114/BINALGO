<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

require_once __DIR__ . '/../includes/classes/Notification.php';

$db = Database::getInstance()->getConnection();
$user = current_user();
$event_id = (int)($_GET['id'] ?? 0);

if (!$event_id) redirect('/tourist/events.php');

$event = $db->prepare(
    "SELECT e.*, d.name as destination_name, d.location as destination_location, d.image as dest_image, d.entrance_fee as dest_fee,
            (SELECT COUNT(*) FROM bookings b JOIN schedules s2 ON b.schedule_id = s2.id WHERE s2.event_id = e.id AND b.status IN ('confirmed','completed')) as attendee_count
     FROM events e
     LEFT JOIN destinations d ON e.destination_id = d.id
     WHERE e.id = :id"
);
$event->execute([':id' => $event_id]);
$ev = $event->fetch();

if (!$ev || $ev['status'] !== 'published') {
    flash_message('error', 'Event not found.');
    redirect('/tourist/events.php');
}

$schedules = $db->prepare(
    "SELECT s.*,
            (SELECT COUNT(*) FROM bookings b WHERE b.schedule_id = s.id AND b.status IN ('confirmed','pending')) as booked
     FROM schedules s
     WHERE s.event_id = :eid AND s.status = 'scheduled' AND s.start_date >= date('now')
     ORDER BY s.start_date ASC, s.start_time ASC"
);
$schedules->execute([':eid' => $event_id]);
$available_schedules = $schedules->fetchAll();

$reviews = $db->prepare(
    "SELECT r.*, u.name as reviewer_name
     FROM destination_reviews r
     JOIN users u ON r.user_id = u.id
     JOIN events ev ON ev.destination_id = r.destination_id
     WHERE ev.id = :eid
     ORDER BY r.created_at DESC
     LIMIT 5"
);
$reviews->execute([':eid' => $event_id]);
$event_reviews = $reviews->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_event'])) {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/tourist/event_detail.php?id=' . $event_id);
    }

    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $num_guests = max(1, (int)($_POST['num_guests'] ?? 1));
    $fullName = sanitize(trim($_POST['full_name'] ?? ''));
    $email = sanitize(trim($_POST['email'] ?? ''));
    $contactNumber = sanitize(trim($_POST['contact_number'] ?? ''));
    $paymentMethod = sanitize(trim($_POST['payment_method'] ?? ''));
    $specialRequests = sanitize(trim($_POST['special_requests'] ?? ''));

    if (!$schedule_id || !$fullName || !$email || !$contactNumber || !$paymentMethod) {
        flash_message('error', 'Please fill in all required fields.');
        redirect('/tourist/event_detail.php?id=' . $event_id);
    }

    $sched = $db->prepare("SELECT * FROM schedules WHERE id = :sid AND event_id = :eid AND status = 'scheduled'");
    $sched->execute([':sid' => $schedule_id, ':eid' => $event_id]);
    $schedule = $sched->fetch();

    if (!$schedule) {
        flash_message('error', 'Invalid schedule selected.');
        redirect('/tourist/event_detail.php?id=' . $event_id);
    }

    if ($schedule['start_date'] < date('Y-m-d')) {
        flash_message('error', 'This schedule has already passed.');
        redirect('/tourist/event_detail.php?id=' . $event_id);
    }

    $booked = (int)$schedule['booked'] ?? 0;
    if ($schedule['available_spots'] > 0 && ($booked + $num_guests) > $schedule['available_spots']) {
        flash_message('error', 'Not enough spots available. Only ' . max(0, $schedule['available_spots'] - $booked) . ' spots left.');
        redirect('/tourist/event_detail.php?id=' . $event_id);
    }

    $event_fee = (float)$ev['price'];
    $serviceFee = ($event_fee * $num_guests) * 0.05;
    $totalPrice = ($event_fee * $num_guests) + $serviceFee;
    $ref = 'BK-' . strtoupper(substr(uniqid(), -8));

    $insert = $db->prepare(
        "INSERT INTO bookings (booking_reference, tourist_id, schedule_id, destination_id, full_name, email, contact_number, num_participants, total_price, service_fee, payment_method, status, payment_status, special_requests, created_at)
         VALUES (:ref, :uid, :sid, :did, :fname, :email, :phone, :num, :total, :sfee, :pmethod, 'pending', 'unpaid', :req, datetime('now'))"
    );
    $insert->execute([
        ':ref' => $ref, ':uid' => $_SESSION['user_id'], ':sid' => $schedule_id,
        ':did' => $ev['destination_id'], ':fname' => $fullName, ':email' => $email,
        ':phone' => $contactNumber, ':num' => $num_guests, ':total' => $totalPrice,
        ':sfee' => $serviceFee, ':pmethod' => $paymentMethod, ':req' => $specialRequests
    ]);

    $notif = new Notification();
    $notif->create($ev['created_by'], 'New Booking', "A new booking ($ref) has been made for {$ev['title']}.", '/admin/bookings.php');

    flash_message('success', 'Booking submitted! Reference: ' . $ref);
    redirect('/tourist/checkout.php?ref=' . $ref);
}

$evImg = $ev['event_image'] ? BASE_URL . '/uploads/events/' . $ev['event_image'] : ($ev['dest_image'] ? BASE_URL . '/uploads/destinations/' . $ev['dest_image'] : BASE_URL . '/assets/images/bambi.jpg');

render_page('tourist', 'event_detail.php', sanitize($ev['title']), function() use ($ev, $evImg, $available_schedules, $event_reviews, $event_id, $db) {
?>

<style>
.event-detail-wrap {
    --db-bg: #f1f5f9;
    --db-card: #ffffff;
    --db-border: #e2e8f0;
    --db-text: #1e293b;
    --db-text-muted: #64748b;
    color: var(--db-text);
}
[data-theme="dark"] .event-detail-wrap {
    --db-bg: #0f172a;
    --db-card: #1e293b;
    --db-border: #334155;
    --db-text: #e2e8f0;
    --db-text-muted: #94a3b8;
}
.ev-hero {
    border-radius: 20px;
    overflow: hidden;
    position: relative;
    height: 380px;
    background-size: cover;
    background-position: center;
    margin-bottom: 24px;
}
.ev-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.1) 50%, transparent 100%);
}
.ev-hero-content {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 32px;
    z-index: 2;
    color: #fff;
}
.ev-hero-content h1 {
    font-size: 2rem;
    font-weight: 800;
    margin-bottom: 8px;
    line-height: 1.2;
}
.ev-meta-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}
.ev-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    background: rgba(255,255,255,0.12);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.1);
    color: #fff;
}
.ev-pill i { font-size: 0.7rem; }
.ev-body { display: grid; grid-template-columns: 1fr 380px; gap: 24px; }
.ev-main { min-width: 0; }
.ev-sidebar { min-width: 0; }
.ev-card {
    background: var(--db-card);
    border: 1px solid var(--db-border);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
}
.ev-card h5 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 14px;
    color: var(--db-text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.ev-card h5 i { color: #0c6e5e; font-size: 0.9rem; }
.ev-card p, .ev-card li {
    font-size: 0.88rem;
    color: var(--db-text-muted);
    line-height: 1.7;
}
.ev-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.ev-info-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.ev-info-item .ev-info-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(12,110,94,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0c6e5e;
    font-size: 0.82rem;
    flex-shrink: 0;
}
.ev-info-item .ev-info-text .ev-info-label {
    font-size: 0.68rem;
    color: var(--db-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}
.ev-info-item .ev-info-text .ev-info-value {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--db-text);
}
.schedule-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px;
    border: 1px solid var(--db-border);
    border-radius: 12px;
    margin-bottom: 10px;
    transition: all 0.2s;
    cursor: pointer;
}
.schedule-item:hover {
    border-color: #0c6e5e;
    background: rgba(12,110,94,0.04);
}
.schedule-item.selected {
    border-color: #0c6e5e;
    background: rgba(12,110,94,0.08);
}
.schedule-date-box {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: rgba(12,110,94,0.1);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.schedule-date-box .s-day {
    font-size: 1.2rem;
    font-weight: 800;
    color: #0c6e5e;
    line-height: 1;
}
.schedule-date-box .s-month {
    font-size: 0.55rem;
    font-weight: 600;
    color: var(--db-text-muted);
    text-transform: uppercase;
}
.schedule-info { flex: 1; min-width: 0; }
.schedule-info .s-time {
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--db-text);
}

.schedule-spots {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
}
.spots-available {
    background: rgba(16,185,129,0.12);
    color: #10b981;
}
.spots-limited {
    background: rgba(245,158,11,0.12);
    color: #f59e0b;
}
.spots-full {
    background: rgba(239,68,68,0.12);
    color: #ef4444;
}
.ev-booking-btn {
    width: 100%;
    padding: 14px;
    border-radius: 12px;
    border: none;
    background: linear-gradient(135deg, #0c6e5e, #10b981);
    color: #fff;
    font-weight: 700;
    font-size: 0.92rem;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 4px 16px rgba(12,110,94,0.3);
}
.ev-booking-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(12,110,94,0.45);
}
.ev-booking-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}
.review-item {
    padding: 14px 0;
    border-bottom: 1px solid var(--db-border);
}
.review-item:last-child { border-bottom: none; }
.review-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
}
.review-avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(12,110,94,0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    color: #0c6e5e;
    font-weight: 700;
}
.review-name { font-weight: 600; font-size: 0.85rem; color: var(--db-text); }
.review-stars { font-size: 0.72rem; color: #f59e0b; margin-left: auto; }
.review-text { font-size: 0.82rem; color: var(--db-text-muted); line-height: 1.6; }
@media (max-width: 991px) {
    .ev-body { grid-template-columns: 1fr; }
    .ev-hero { height: 280px; }
    .ev-hero-content h1 { font-size: 1.5rem; }
    .ev-info-grid { grid-template-columns: 1fr; }
}
</style>

<div class="event-detail-wrap">

    <div class="ev-hero" style="background-image:url('<?= $evImg ?>');">
        <div class="ev-hero-content">
            <h1><?= sanitize($ev['title']) ?></h1>
            <div class="ev-meta-pills">
                <span class="ev-pill"><i class="fas fa-calendar"></i> <?= $ev['event_start_date'] ? date('M d, Y', strtotime($ev['event_start_date'])) : 'TBA' ?></span>
                <?php if ($ev['event_start_time']): ?>
                    <span class="ev-pill"><i class="fas fa-clock"></i> <?= date('h:i A', strtotime($ev['event_start_time'])) ?><?= $ev['event_end_time'] ? ' - ' . date('h:i A', strtotime($ev['event_end_time'])) : '' ?></span>
                <?php endif; ?>
                <?php if ($ev['destination_name']): ?>
                    <span class="ev-pill"><i class="fas fa-map-pin"></i> <?= sanitize($ev['destination_name']) ?></span>
                <?php endif; ?>
                <?php if ($ev['price'] > 0): ?>
                    <span class="ev-pill"><i class="fas fa-tag"></i> ₱<?= number_format($ev['price'], 2) ?></span>
                <?php else: ?>
                    <span class="ev-pill"><i class="fas fa-tag"></i> Free</span>
                <?php endif; ?>
                <span class="ev-pill"><i class="fas fa-users"></i> <?= $ev['attendee_count'] ?> joined</span>
            </div>
        </div>
    </div>

    <div class="ev-body">
        <div class="ev-main">
            <div class="ev-card">
                <h5><i class="fas fa-info-circle"></i> About This Event</h5>
                <p><?= nl2br(sanitize($ev['description'])) ?></p>
            </div>

            <div class="ev-card">
                <h5><i class="fas fa-list-check"></i> Event Details</h5>
                <div class="ev-info-grid">
                    <div class="ev-info-item">
                        <div class="ev-info-icon"><i class="fas fa-calendar"></i></div>
                        <div class="ev-info-text">
                            <div class="ev-info-label">Date</div>
                            <div class="ev-info-value"><?= $ev['event_start_date'] ? date('M d, Y', strtotime($ev['event_start_date'])) : 'TBA' ?><?= $ev['event_end_date'] ? ' - ' . date('M d, Y', strtotime($ev['event_end_date'])) : '' ?></div>
                        </div>
                    </div>
                    <div class="ev-info-item">
                        <div class="ev-info-icon"><i class="fas fa-clock"></i></div>
                        <div class="ev-info-text">
                            <div class="ev-info-label">Time</div>
                            <div class="ev-info-value"><?= $ev['event_start_time'] ? date('h:i A', strtotime($ev['event_start_time'])) : 'TBA' ?><?= $ev['event_end_time'] ? ' - ' . date('h:i A', strtotime($ev['event_end_time'])) : '' ?></div>
                        </div>
                    </div>
                    <div class="ev-info-item">
                        <div class="ev-info-icon"><i class="fas fa-location-dot"></i></div>
                        <div class="ev-info-text">
                            <div class="ev-info-label">Location</div>
                            <div class="ev-info-value"><?= sanitize($ev['event_location'] ?: ($ev['destination_location'] ?: 'Binalbagan')) ?></div>
                        </div>
                    </div>
                    <div class="ev-info-item">
                        <div class="ev-info-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="ev-info-text">
                            <div class="ev-info-label">Duration</div>
                            <div class="ev-info-value"><?= number_format($ev['duration_hours'], 1) ?> hour<?= $ev['duration_hours'] != 1 ? 's' : '' ?></div>
                        </div>
                    </div>
                    <div class="ev-info-item">
                        <div class="ev-info-icon"><i class="fas fa-users"></i></div>
                        <div class="ev-info-text">
                            <div class="ev-info-label">Capacity</div>
                            <div class="ev-info-value"><?= $ev['max_participants'] > 0 ? $ev['max_participants'] . ' pax' : 'Unlimited' ?></div>
                        </div>
                    </div>
                    <?php if ($ev['organizer']): ?>
                    <div class="ev-info-item">
                        <div class="ev-info-icon"><i class="fas fa-building"></i></div>
                        <div class="ev-info-text">
                            <div class="ev-info-label">Organizer</div>
                            <div class="ev-info-value"><?= sanitize($ev['organizer']) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($ev['contact_info']): ?>
                    <div class="ev-info-item">
                        <div class="ev-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="ev-info-text">
                            <div class="ev-info-label">Contact</div>
                            <div class="ev-info-value"><?= sanitize($ev['contact_info']) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($ev['health_restrictions']): ?>
            <div class="ev-card">
                <h5><i class="fas fa-heart-pulse"></i> Health & Safety</h5>
                <p><?= nl2br(sanitize($ev['health_restrictions'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($ev['accessibility_info']): ?>
            <div class="ev-card">
                <h5><i class="fas fa-universal-access"></i> Accessibility</h5>
                <p><?= nl2br(sanitize($ev['accessibility_info'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($event_reviews)): ?>
            <div class="ev-card">
                <h5><i class="fas fa-star"></i> Visitor Reviews</h5>
                <?php foreach ($event_reviews as $rv): ?>
                <div class="review-item">
                    <div class="review-header">
                        <div class="review-avatar"><?= strtoupper(substr($rv['reviewer_name'], 0, 1)) ?></div>
                        <div class="review-name"><?= sanitize($rv['reviewer_name']) ?></div>
                        <div class="review-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?= $i > $rv['rating'] ? ' text-muted' : '' ?>" style="color: <?= $i <= $rv['rating'] ? '#f59e0b' : '#475569' ?>;"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="review-text"><?= sanitize($rv['review']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="ev-sidebar">
            <div class="ev-card" style="position:sticky;top:20px;">
                <h5><i class="fas fa-ticket"></i> Book This Event</h5>

                <?php if ($ev['price'] > 0): ?>
                <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:16px;">
                    <span style="font-size:1.5rem;font-weight:800;color:#0c6e5e;">₱<?= number_format($ev['price'], 2) ?></span>
                    <span style="font-size:0.82rem;color:var(--db-text-muted);">per person</span>
                </div>
                <?php else: ?>
                <div style="margin-bottom:16px;">
                    <span style="font-size:1.2rem;font-weight:800;color:#10b981;">Free Event</span>
                </div>
                <?php endif; ?>

                <?php if (empty($available_schedules)): ?>
                    <div style="text-align:center;padding:24px 16px;color:var(--db-text-muted);background:var(--db-bg);border-radius:12px;">
                        <i class="fas fa-calendar-xmark" style="font-size:1.5rem;margin-bottom:8px;opacity:0.4;display:block;"></i>
                        <p style="font-size:0.85rem;margin:0;">No upcoming schedules available.</p>
                    </div>
                <?php else: ?>
                    <div style="font-size:0.75rem;font-weight:600;color:var(--db-text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">Select Schedule</div>
                    <?php foreach ($available_schedules as $sc):
                        $spotsLeft = $sc['available_spots'] > 0 ? $sc['available_spots'] - ($sc['booked'] ?? 0) : 999;
                        $spotClass = $spotsLeft <= 0 ? 'spots-full' : ($spotsLeft <= 5 ? 'spots-limited' : 'spots-available');
                        $spotText = $spotsLeft <= 0 ? 'Full' : ($spotsLeft <= 5 ? "$spotsLeft left" : 'Available');
                    ?>
                    <label class="schedule-item" onclick="selectSchedule(this, <?= $sc['id'] ?>)">
                        <input type="radio" name="selected_schedule" value="<?= $sc['id'] ?>" style="display:none;" <?= $spotsLeft <= 0 ? 'disabled' : '' ?>>
                        <div class="schedule-date-box">
                            <div class="s-day"><?= date('d', strtotime($sc['start_date'])) ?></div>
                            <div class="s-month"><?= date('M', strtotime($sc['start_date'])) ?></div>
                        </div>
                        <div class="schedule-info">
                            <div class="s-time"><?= date('h:i A', strtotime($sc['start_time'])) ?><?= $sc['end_time'] ? ' - ' . date('h:i A', strtotime($sc['end_time'])) : '' ?></div>
                        </div>
                        <span class="schedule-spots <?= $spotClass ?>"><?= $spotText ?></span>
                    </label>
                    <?php endforeach; ?>

                    <form method="POST" id="bookingForm" style="margin-top:16px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="schedule_id" id="selectedScheduleId" value="">
                        <input type="hidden" name="book_event" value="1">

                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--db-text-muted);display:block;margin-bottom:6px;">Number of Guests *</label>
                            <input type="number" name="num_guests" id="numGuests" min="1" max="20" value="1" style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--db-border);background:var(--db-card);color:var(--db-text);font-size:0.88rem;" onchange="updateTotal()">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--db-text-muted);display:block;margin-bottom:6px;">Full Name *</label>
                            <input type="text" name="full_name" value="<?= sanitize($user['name']) ?>" required style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--db-border);background:var(--db-card);color:var(--db-text);font-size:0.88rem;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--db-text-muted);display:block;margin-bottom:6px;">Email *</label>
                            <input type="email" name="email" value="<?= sanitize($user['email']) ?>" required style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--db-border);background:var(--db-card);color:var(--db-text);font-size:0.88rem;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--db-text-muted);display:block;margin-bottom:6px;">Contact Number *</label>
                            <input type="tel" name="contact_number" placeholder="+63 9XX XXX XXXX" required style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--db-border);background:var(--db-card);color:var(--db-text);font-size:0.88rem;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--db-text-muted);display:block;margin-bottom:6px;">Payment Method *</label>
                            <select name="payment_method" required style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--db-border);background:var(--db-card);color:var(--db-text);font-size:0.88rem;">
                                <option value="">Select payment</option>
                                <option value="gcash">GCash</option>
                                <option value="maya">Maya</option>
                                <option value="card">Credit/Debit Card</option>
                                <option value="cash">Cash on Arrival</option>
                            </select>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label style="font-size:0.75rem;font-weight:600;color:var(--db-text-muted);display:block;margin-bottom:6px;">Special Requests</label>
                            <textarea name="special_requests" rows="2" placeholder="Any special requirements..." style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--db-border);background:var(--db-card);color:var(--db-text);font-size:0.88rem;resize:vertical;"></textarea>
                        </div>

                        <div id="priceSummary" style="background:var(--db-bg);border-radius:12px;padding:14px;margin-bottom:16px;display:none;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:0.82rem;color:var(--db-text-muted);">
                                <span>Event fee (×<span id="summaryQty">1</span>)</span>
                                <span id="summaryFee">₱0.00</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:0.82rem;color:var(--db-text-muted);">
                                <span>Service fee (5%)</span>
                                <span id="summaryService">₱0.00</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-weight:700;font-size:0.95rem;color:var(--db-text);padding-top:8px;border-top:1px solid var(--db-border);">
                                <span>Total</span>
                                <span id="summaryTotal">₱0.00</span>
                            </div>
                        </div>

                        <button type="submit" class="ev-booking-btn" id="bookBtn" disabled>
                            <i class="fas fa-ticket me-1"></i> Book Now
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($ev['registration_link']): ?>
                <a href="<?= sanitize($ev['registration_link']) ?>" target="_blank" class="ev-booking-btn" style="display:block;text-align:center;text-decoration:none;margin-top:10px;background:var(--db-card);color:var(--db-text);border:1px solid var(--db-border);box-shadow:none;">
                    <i class="fas fa-external-link-alt me-1"></i> External Registration
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
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

var eventPrice = <?= (float)$ev['price'] ?>;

function selectSchedule(el, scheduleId) {
    document.querySelectorAll('.schedule-item').forEach(function(item) {
        item.classList.remove('selected');
    });
    el.classList.add('selected');
    document.getElementById('selectedScheduleId').value = scheduleId;
    document.getElementById('bookBtn').disabled = false;
    updateTotal();
}

function updateTotal() {
    var qty = parseInt(document.getElementById('numGuests').value) || 1;
    var fee = eventPrice * qty;
    var service = fee * 0.05;
    var total = fee + service;
    document.getElementById('summaryQty').textContent = qty;
    document.getElementById('summaryFee').textContent = '₱' + fee.toFixed(2);
    document.getElementById('summaryService').textContent = '₱' + service.toFixed(2);
    document.getElementById('summaryTotal').textContent = '₱' + total.toFixed(2);
    document.getElementById('priceSummary').style.display = eventPrice > 0 ? 'block' : 'none';
}

document.getElementById('bookingForm').addEventListener('submit', function(e) {
    var sid = document.getElementById('selectedScheduleId').value;
    if (!sid) {
        e.preventDefault();
        showToast('Please select a schedule.', 'warning');
        return;
    }
    var btn = document.getElementById('bookBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';
    btn.disabled = true;
});
</script>

<?php }); ?>
