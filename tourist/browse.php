<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/Notification.php';
require_once __DIR__ . '/../includes/classes/Message.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'book') {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token. Please try again.');
        redirect('/tourist/browse.php');
    }

    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $num_participants = max(1, (int)($_POST['num_participants'] ?? 1));
    $special_requests = trim($_POST['special_requests'] ?? '');

    if ($schedule_id <= 0) {
        flash_message('error', 'Invalid booking parameters.');
        redirect('/tourist/browse.php');
    }

    $schedule_stmt = $db->prepare(
        "SELECT s.*, e.title as event_name, e.price as event_price, d.name as destination_name, d.capacity_limit
         FROM schedules s
         JOIN events e ON s.event_id = e.id
         JOIN destinations d ON e.destination_id = d.id
         WHERE s.id = :id AND s.status = 'scheduled'"
    );
    $schedule_stmt->execute([':id' => $schedule_id]);
    $schedule = $schedule_stmt->fetch();

    if (!$schedule) {
        flash_message('error', 'Schedule not found or no longer available.');
        redirect('/tourist/browse.php');
    }

    if ($schedule['start_date'] < date('Y-m-d')) {
        flash_message('error', 'Cannot book for past dates.');
        redirect('/tourist/browse.php');
    }

    if ($schedule['available_spots'] < $num_participants) {
        flash_message('error', 'Not enough spots available. Only ' . $schedule['available_spots'] . ' spots left.');
        redirect('/tourist/browse.php');
    }

    $conflict_stmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM bookings b
         JOIN schedules s ON b.schedule_id = s.id
         WHERE b.tourist_id = :uid AND b.status IN ('confirmed','pending')
         AND s.start_date <= :end AND s.end_date >= :start"
    );
    $conflict_stmt->execute([
        ':uid' => $user_id,
        ':start' => $schedule['start_date'],
        ':end' => $schedule['end_date'],
    ]);
    $has_conflict = (int) $conflict_stmt->fetch()['cnt'] > 0;

    if ($has_conflict) {
        flash_message('error', 'You have a booking conflict with the selected dates.');
        redirect('/tourist/browse.php');
    }

    $total_price = (float)$schedule['event_price'] * $num_participants;
    $service_fee = $total_price * 0.05;
    $total_with_fee = $total_price + $service_fee;
    $ref = 'BK-' . strtoupper(substr(uniqid(), -8));

    $insert_stmt = $db->prepare(
        "INSERT INTO bookings (booking_reference, tourist_id, schedule_id, full_name, email, contact_number, num_participants, total_price, service_fee, payment_method, status, payment_status, special_requests, created_at)
         VALUES (:ref, :tourist_id, :schedule_id, :full_name, :email, :contact_number, :num_participants, :total_price, :service_fee, :payment_method, 'pending', 'unpaid', :special_requests, NOW())"
    );
    $insert_stmt->execute([
        ':ref' => $ref,
        ':tourist_id' => $user_id,
        ':schedule_id' => $schedule_id,
        ':full_name' => sanitize($_POST['full_name'] ?? $user['name'] ?? ''),
        ':email' => sanitize($_POST['email'] ?? $user['email'] ?? ''),
        ':contact_number' => sanitize($_POST['contact_number'] ?? ''),
        ':num_participants' => $num_participants,
        ':total_price' => $total_with_fee,
        ':service_fee' => $service_fee,
        ':payment_method' => sanitize($_POST['payment_method'] ?? ''),
        ':special_requests' => $special_requests,
    ]);

    $new_booking_id = (int)$db->lastInsertId();

    $notif = new Notification();
    $notif->notifyBookingCreated($new_booking_id);

    ActivityLog::log($user_id, 'booking_created', "Created booking #{$ref} for {$schedule['event_name']} at {$schedule['destination_name']} for {$num_participants} participants");

    flash_message('success', "Booking #{$ref} created! Please complete payment.");
    redirect("/tourist/checkout.php?booking_id={$new_booking_id}");
}

$search = $_GET['search'] ?? '';
$filter_difficulty = $_GET['difficulty'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_price_min = $_GET['price_min'] ?? '';
$filter_price_max = $_GET['price_max'] ?? '';
$filter_accessible = isset($_GET['accessible']) ? 1 : '';
$filter_availability = $_GET['availability'] ?? '';
$filter_season = $_GET['season'] ?? '';
$filter_category = $_GET['category'] ?? '';
$filter_destination = $_GET['destination'] ?? '';
$sort = $_GET['sort'] ?? 'date_asc';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 9;

$where = ["s.status = 'scheduled'", "s.start_date >= CURDATE()", "e.status = 'published'", "d.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(e.title LIKE :search OR d.name LIKE :search2 OR d.location LIKE :search3)";
    $params[':search'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
}

if ($filter_difficulty !== '') {
    $where[] = "d.difficulty = :difficulty";
    $params[':difficulty'] = $filter_difficulty;
}

if ($filter_date_from !== '') {
    $where[] = "s.start_date >= :date_from";
    $params[':date_from'] = $filter_date_from;
}

if ($filter_date_to !== '') {
    $where[] = "s.end_date <= :date_to";
    $params[':date_to'] = $filter_date_to;
}

if ($filter_price_min !== '') {
    $where[] = "e.price >= :price_min";
    $params[':price_min'] = $filter_price_min;
}

if ($filter_price_max !== '') {
    $where[] = "e.price <= :price_max";
    $params[':price_max'] = $filter_price_max;
}

if ($filter_accessible !== '') {
    $where[] = "(d.accessibility_info IS NOT NULL AND d.accessibility_info != '')";
}

if ($filter_availability === 'available') {
    $where[] = "s.available_spots > 0";
} elseif ($filter_availability === 'full') {
    $where[] = "s.available_spots <= 0";
}

if ($filter_season !== '') {
    $current_month = (int) date('m');
    $where[] = "EXISTS (SELECT 1 FROM destination_seasons ds WHERE ds.destination_id = d.id AND ds.season_type = :season AND ds.months LIKE :month_pattern)";
    $params[':season'] = $filter_season;
    $params[':month_pattern'] = '%' . $current_month . '%';
}

if ($filter_category !== '') {
    $where[] = "d.category = :category";
    $params[':category'] = $filter_category;
}

if ($filter_destination !== '') {
    $where[] = "d.id = :destination";
    $params[':destination'] = $filter_destination;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$order_map = [
    'price_asc'  => 'e.price ASC',
    'price_desc' => 'e.price DESC',
    'date_asc'   => 's.start_date ASC, s.start_time ASC',
    'date_desc'  => 's.start_date DESC, s.start_time DESC',
    'rating_desc'=> 'avg_rating DESC',
    'spots_desc' => 's.available_spots DESC',
];
$order_sql = $order_map[$sort] ?? 's.start_date ASC, s.start_time ASC';

$count_sql = "SELECT COUNT(*) as total FROM schedules s
    JOIN events e ON s.event_id = e.id
    JOIN destinations d ON e.destination_id = d.id
    {$where_clause}";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total = (int) $count_stmt->fetch()['total'];
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$query_sql = "SELECT s.*, e.title as event_name, e.description as event_desc, e.price as event_price,
    e.duration_hours, e.max_participants as event_max, e.accessibility_info as event_accessibility,
    d.name as destination_name, d.location as destination_location, d.difficulty, d.image as dest_image,
    d.accessibility_info as dest_accessibility, d.capacity_limit
    FROM schedules s
    JOIN events e ON s.event_id = e.id
    JOIN destinations d ON e.destination_id = d.id
    {$where_clause}
    ORDER BY {$order_sql}
    LIMIT {$per_page} OFFSET {$offset}";

$stmt = $db->prepare($query_sql);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

$booking_msg = $_GET['booking_msg'] ?? '';
$booking_err = $_GET['booking_err'] ?? '';

$dest_stmt = $db->query("SELECT id, name, location FROM destinations WHERE status = 'active' ORDER BY name");
$destinations = $dest_stmt->fetchAll();

render_page('tourist', 'browse.php', 'Browse Tours', function() use ($search, $filter_difficulty, $filter_date_from, $filter_date_to, $filter_price_min, $filter_price_max, $filter_accessible, $filter_availability, $filter_season, $filter_category, $filter_destination, $destinations, $sort, $page, $total_pages, $total, $schedules, $booking_msg, $booking_err, $user_id) {
?>

<?php if ($booking_msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?= sanitize($booking_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($booking_err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?= sanitize($booking_err) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-3 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-bottom">
                <h6 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h6>
            </div>
            <div class="card-body">
                <form method="GET" id="filterForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Tour name, destination..." value="<?= sanitize($search) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Destination Type</label>
                        <select name="difficulty" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="easy" <?= $filter_difficulty === 'easy' ? 'selected' : '' ?>>Easy / Beach</option>
                            <option value="moderate" <?= $filter_difficulty === 'moderate' ? 'selected' : '' ?>>Moderate / Mountain</option>
                            <option value="difficult" <?= $filter_difficulty === 'difficult' ? 'selected' : '' ?>>Difficult / Historical</option>
                            <option value="extreme" <?= $filter_difficulty === 'extreme' ? 'selected' : '' ?>>Extreme / Adventure</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Category</label>
                        <select name="category" class="form-select form-select-sm">
                            <option value="">All Categories</option>
                            <option value="beaches" <?= $filter_category === 'beaches' ? 'selected' : '' ?>>Beaches</option>
                            <option value="historical_sites" <?= $filter_category === 'historical_sites' ? 'selected' : '' ?>>Historical Sites</option>
                            <option value="cultural_attractions" <?= $filter_category === 'cultural_attractions' ? 'selected' : '' ?>>Cultural Attractions</option>
                            <option value="religious_sites" <?= $filter_category === 'religious_sites' ? 'selected' : '' ?>>Religious Sites</option>
                            <option value="nature_adventure" <?= $filter_category === 'nature_adventure' ? 'selected' : '' ?>>Nature & Adventure</option>

                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Destination</label>
                        <select name="destination" class="form-select form-select-sm">
                            <option value="">All Destinations</option>
                            <?php foreach ($destinations as $dest): ?>
                                <option value="<?= $dest['id'] ?>" <?= $filter_destination == $dest['id'] ? 'selected' : '' ?>><?= sanitize($dest['name']) ?> — <?= sanitize($dest['location']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Date Range</label>
                        <input type="date" name="date_from" class="form-control form-control-sm mb-2" value="<?= sanitize($filter_date_from) ?>" placeholder="From">
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($filter_date_to) ?>" placeholder="To">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Price Range</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">₱</span>
                            <input type="number" name="price_min" class="form-control" placeholder="Min" value="<?= sanitize($filter_price_min) ?>" min="0">
                            <span class="input-group-text">-</span>
                            <input type="number" name="price_max" class="form-control" placeholder="Max" value="<?= sanitize($filter_price_max) ?>" min="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Availability</label>
                        <select name="availability" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="available" <?= $filter_availability === 'available' ? 'selected' : '' ?>>Available</option>
                            <option value="full" <?= $filter_availability === 'full' ? 'selected' : '' ?>>Fully Booked</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Season</label>
                        <select name="season" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="peak" <?= $filter_season === 'peak' ? 'selected' : '' ?>>Peak Season</option>
                            <option value="off_peak" <?= $filter_season === 'off_peak' ? 'selected' : '' ?>>Off-Peak</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="accessible" value="1" id="accessibleCheck" <?= $filter_accessible ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="accessibleCheck">Disability-Friendly Only</label>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Apply Filters</button>
                        <a href="browse.php" class="btn btn-outline-secondary btn-sm">Clear Filters</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0">Available Tours</h5>
                <small class="text-muted"><?= $total ?> tour<?= $total !== 1 ? 's' : '' ?> found</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label class="small text-muted mb-0">Sort:</label>
                <select class="form-select form-select-sm" style="width:auto;" id="sortSelect" onchange="updateSort(this.value)">
                    <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Date (Nearest)</option>
                    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Date (Farthest)</option>
                    <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price (Low to High)</option>
                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price (High to Low)</option>
                    <option value="rating_desc" <?= $sort === 'rating_desc' ? 'selected' : '' ?>>Top Rated</option>
                    <option value="spots_desc" <?= $sort === 'spots_desc' ? 'selected' : '' ?>>Most Spots Available</option>
                </select>
            </div>
        </div>

        <?php if (empty($schedules)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-search text-muted" style="font-size:3rem;"></i>
                    <h5 class="mt-3">No tours found</h5>
                    <p class="text-muted">Try adjusting your filters or search criteria.</p>
                    <a href="browse.php" class="btn btn-primary btn-sm">Clear Filters</a>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($schedules as $s): ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card border-0 shadow-sm h-100 tour-card">
                            <?php if ($s['dest_image']): ?>
                                <img src="<?= dest_image_url($s['dest_image']) ?>" class="card-img-top" alt="<?= sanitize($s['destination_name']) ?>" style="height:160px;object-fit:cover;">
                            <?php else: ?>
                                <div class="card-img-top d-flex align-items-center justify-content-center" style="height:160px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);">
                                    <i class="fas fa-mountain-sun text-white" style="font-size:3rem;opacity:0.6;"></i>
                                </div>
                            <?php endif; ?>
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title mb-0 fw-bold"><?= sanitize($s['event_name']) ?></h6>
                                    <?php
                                    $diff_badges = [
                                        'easy'      => 'bg-success',
                                        'moderate'  => 'bg-warning text-dark',
                                        'difficult' => 'bg-danger',
                                        'extreme'   => 'bg-dark',
                                    ];
                                    $diff_cls = $diff_badges[$s['difficulty']] ?? 'bg-secondary';
                                    ?>
                                    <span class="badge <?= $diff_cls ?>"><?= ucfirst($s['difficulty']) ?></span>
                                </div>
                                <p class="text-muted small mb-2">
                                    <i class="fas fa-map-marker-alt me-1"></i><?= sanitize($s['destination_name']) ?>, <?= sanitize($s['destination_location']) ?>
                                </p>
                                <p class="small text-muted mb-2">
                                    <i class="fas fa-calendar me-1"></i><?= format_date($s['start_date']) ?>
                                    &mdash; <?= format_date($s['end_date']) ?>
                                </p>
                                <p class="small text-muted mb-2">
                                    <i class="fas fa-clock me-1"></i><?= date('h:i A', strtotime($s['start_time'])) ?> - <?= date('h:i A', strtotime($s['end_time'])) ?>
                                    <?php if ($s['duration_hours']): ?>
                                        <span class="ms-1">(<?= $s['duration_hours'] ?>h)</span>
                                    <?php endif; ?>
                                </p>

                                <?php if ($s['dest_accessibility']): ?>
                                    <p class="small text-info mb-2">
                                        <i class="fas fa-wheelchair me-1"></i>Accessible
                                    </p>
                                <?php endif; ?>

                                <div class="mt-auto">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="fw-bold fs-5 text-primary">₱<?= number_format((float)$s['event_price'], 2) ?></span>
                                        <?php if ($s['available_spots'] > 0): ?>
                                            <span class="small text-success"><i class="fas fa-users me-1"></i><?= $s['available_spots'] ?> spot<?= $s['available_spots'] != 1 ? 's' : '' ?> left</span>
                                        <?php else: ?>
                                            <span class="small text-danger"><i class="fas fa-ban me-1"></i>Fully Booked</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($s['available_spots'] > 0): ?>
                                        <button class="btn btn-primary btn-sm w-100" data-bs-toggle="modal" data-bs-target="#bookingModal"
                                            data-schedule-id="<?= $s['id'] ?>"
                                            data-event-name="<?= sanitize($s['event_name']) ?>"
                                            data-destination="<?= sanitize($s['destination_name']) ?>"
                                            data-date="<?= format_date($s['start_date']) ?>"
                                            data-price="<?= $s['event_price'] ?>"
                                            data-max-participants="<?= $s['available_spots'] ?>"
                                            onclick="openBookingModal(this)">
                                            <i class="fas fa-ticket me-1"></i>Book Now
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm w-100" disabled>
                                            <i class="fas fa-ban me-1"></i>Fully Booked
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" onclick="goToPage(<?= $page - 1 ?>);return false;">&laquo;</a>
                    </li>
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p = min($total_pages, $page + 2);
                    for ($i = $start_p; $i <= $end_p; $i++):
                    ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="#" onclick="goToPage(<?= $i ?>);return false;"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="#" onclick="goToPage(<?= $page + 1 ?>);return false;">&raquo;</a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="browse.php?action=book">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-ticket me-2"></i>Book Tour</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                    <input type="hidden" name="schedule_id" id="modal_schedule_id">

                    <div class="alert alert-info small">
                        <strong id="modal_event_name"></strong><br>
                        <span id="modal_destination"></span> &mdash; <span id="modal_date"></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Number of Participants</label>
                        <input type="number" name="num_participants" id="modal_num_participants" class="form-control" min="1" max="10" value="1" required>
                        <div class="form-text" id="modal_spots_info"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Price per Person</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="text" class="form-control" id="modal_price_display" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Total Price</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="text" class="form-control fw-bold text-primary" id="modal_total_display" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Special Requests</label>
                        <textarea name="special_requests" class="form-control" rows="3" placeholder="Any dietary requirements, accessibility needs, or special requests..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i>Confirm Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateSort(val) {
    const url = new URL(window.location);
    url.searchParams.set('sort', val);
    url.searchParams.set('page', '1');
    window.location = url;
}

function goToPage(p) {
    const url = new URL(window.location);
    url.searchParams.set('page', p);
    window.location = url;
}

function openBookingModal(btn) {
    document.getElementById('modal_schedule_id').value = btn.dataset.scheduleId;
    document.getElementById('modal_event_name').textContent = btn.dataset.eventName;
    document.getElementById('modal_destination').textContent = btn.dataset.destination;
    document.getElementById('modal_date').textContent = btn.dataset.date;
    document.getElementById('modal_price_display').value = parseFloat(btn.dataset.price).toFixed(2);
    document.getElementById('modal_num_participants').max = btn.dataset.maxParticipants;
    document.getElementById('modal_spots_info').textContent = btn.dataset.maxParticipants + ' spots available';
    updateTotal();

    document.getElementById('modal_num_participants').oninput = updateTotal;
}

function updateTotal() {
    const price = parseFloat(document.getElementById('modal_price_display').value) || 0;
    const qty = parseInt(document.getElementById('modal_num_participants').value) || 1;
    document.getElementById('modal_total_display').value = (price * qty).toFixed(2);
}
</script>

<?php }); ?>
