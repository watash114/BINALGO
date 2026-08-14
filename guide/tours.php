<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('guide');

require_once __DIR__ . '/../includes/classes/Schedule.php';
require_once __DIR__ . '/../includes/classes/Booking.php';

$user = current_user();
$guide_id = $user['id'];
$db = Database::getInstance()->getConnection();
$scheduleModel = new Schedule();
$bookingModel = new Booking();

$status_filter = $_GET['status'] ?? 'all';
$search = sanitize($_GET['search'] ?? '');

$action = $_GET['action'] ?? '';
$schedule_id = (int) ($_GET['schedule_id'] ?? 0);

if ($action === 'start' && $schedule_id && isset($_GET['csrf']) && verify_token($_GET['csrf'])) {
    $stmt = $db->prepare("UPDATE schedules SET status = 'in_progress' WHERE id = :id AND guide_id = :gid");
    $stmt->execute([':id' => $schedule_id, ':gid' => $guide_id]);
    header("Location: " . BASE_URL . "/guide/tours.php?status=in_progress");
    exit;
}

if ($action === 'complete' && $schedule_id && isset($_GET['csrf']) && verify_token($_GET['csrf'])) {
    $stmt = $db->prepare("UPDATE schedules SET status = 'completed' WHERE id = :id AND guide_id = :gid");
    $stmt->execute([':id' => $schedule_id, ':gid' => $guide_id]);
    header("Location: " . BASE_URL . "/guide/tours.php?status=completed");
    exit;
}

$filters = ['guide_id' => $guide_id];
if ($status_filter !== 'all') {
    $filters['status'] = $status_filter;
}
if ($search) {
    $filters['search'] = $search;
}

$page = (int) ($_GET['page'] ?? 1);
$schedules = $scheduleModel->findAll($filters, $page, 10);

$detail_id = (int) ($_GET['detail'] ?? 0);
$detail_schedule = null;
$detail_bookings = [];
if ($detail_id) {
    $detail_schedule = $scheduleModel->findById($detail_id);
    if ($detail_schedule && $detail_schedule['guide_id'] == $guide_id) {
        $detail_bookings = $bookingModel->findBySchedule($detail_id);
    }
}

$action_csrf = generate_token();

render_page('guide', 'tours.php', 'My Tours', function () use ($schedules, $status_filter, $search, $detail_schedule, $detail_bookings, $guide_id, $db, $action_csrf) {
?>
<style>
.guide-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.guide-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.guide-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.filter-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:16px 20px;margin-bottom:20px;}
.filter-input{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 14px;color:var(--text-primary,#e2e8f0);width:100%;font-size:0.9rem;}
.filter-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.2);}
.filter-input option{background:var(--card-bg,#1a1f2e);color:var(--text-primary,#e2e8f0);}
.section-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;gap:10px;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#e2e8f0);}
.table-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.table-card .table{margin:0;}
.table-card .table thead th{background:rgba(255,255,255,0.03);border-bottom:1px solid var(--border-color,#2a3042);color:var(--text-muted,#94a3b8);font-size:0.8rem;font-weight:600;padding:12px 16px;}
.table-card .table tbody td{border-bottom:1px solid var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);font-size:0.85rem;padding:12px 16px;vertical-align:middle;}
.table-card .table tbody tr:last-child td{border-bottom:none;}
.table-card .table tbody tr:hover{background:rgba(255,255,255,0.02);}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:0.72rem;font-weight:600;}
.status-chip.scheduled{background:rgba(59,130,246,0.15);color:#3b82f6;}
.status-chip.in_progress{background:rgba(245,158,11,0.15);color:#f59e0b;}
.status-chip.completed{background:rgba(34,197,94,0.15);color:#22c55e;}
.status-chip.cancelled{background:rgba(239,68,68,0.15);color:#ef4444;}
.status-chip.confirmed{background:rgba(34,197,94,0.15);color:#22c55e;}
.status-chip.pending{background:rgba(245,158,11,0.15);color:#f59e0b;}
.action-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;border:1px solid var(--border-color,#2a3042);background:var(--card-bg,#1a1f2e);color:var(--text-muted,#94a3b8);text-decoration:none;transition:all 0.2s;font-size:0.75rem;}
.action-btn:hover{background:rgba(12,110,94,0.1);color:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.btn-reset{background:rgba(255,255,255,0.08);color:var(--text-primary,#e2e8f0);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 20px;font-weight:600;}
.btn-reset:hover{background:rgba(255,255,255,0.12);color:var(--text-primary,#e2e8f0);}
.detail-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.detail-card .detail-header{padding:16px 20px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;display:flex;align-items:center;gap:10px;}
.detail-card .detail-body{padding:20px;}
.detail-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color,#2a3042);}
.detail-item:last-child{border-bottom:none;}
.detail-item .label{font-weight:600;font-size:0.85rem;color:var(--text-primary,#e2e8f0);}
.detail-item .value{font-size:0.85rem;color:var(--text-muted,#94a3b8);}
.pagination .page-link{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);border-radius:8px;margin:0 2px;font-size:0.85rem;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-link:hover{background:rgba(12,110,94,0.1);color:var(--primary,#0c6e5e);}
</style>

<?php if ($detail_schedule): ?>
<div class="guide-hero">
    <div class="position-relative" style="z-index:1;">
        <a href="<?= BASE_URL ?>/guide/tours.php" style="color:rgba(255,255,255,0.8);text-decoration:none;font-size:0.85rem;"><i class="fas fa-arrow-left me-1"></i>Back to Tours</a>
        <h3 class="fw-bold mb-1 mt-2"><i class="fas fa-route me-2"></i>Tour Details</h3>
        <p class="mb-0 opacity-75" style="font-size:0.9rem;">View detailed information about this tour</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="detail-card">
            <div class="detail-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-calendar-alt" style="font-size:0.7rem;"></i>
                </div>
                <h6 class="mb-0 fw-bold">Event Information</h6>
            </div>
            <div class="detail-body">
                <div class="detail-item"><span class="label">Event</span><span class="value"><?= sanitize($detail_schedule['event_title'] ?? 'N/A') ?></span></div>
                <div class="detail-item"><span class="label">Destination</span><span class="value"><?= sanitize($detail_schedule['destination_name'] ?? 'N/A') ?></span></div>
                <div class="detail-item"><span class="label">Location</span><span class="value"><?= sanitize($detail_schedule['destination_location'] ?? 'N/A') ?></span></div>
                <div class="detail-item"><span class="label">Start Date</span><span class="value"><?= format_date($detail_schedule['start_date']) ?></span></div>
                <div class="detail-item"><span class="label">End Date</span><span class="value"><?= format_date($detail_schedule['end_date']) ?></span></div>
                <div class="detail-item"><span class="label">Time</span><span class="value"><?= sanitize($detail_schedule['start_time'] ?? '') ?> - <?= sanitize($detail_schedule['end_time'] ?? '') ?></span></div>
                <div class="detail-item"><span class="label">Available Spots</span><span class="value"><?= $detail_schedule['available_spots'] ?? '-' ?> / <?= $detail_schedule['max_participants'] ?? '-' ?></span></div>
                <div class="detail-item"><span class="label">Status</span><span class="value"><span class="status-chip <?= $detail_schedule['status'] ?>"><?= ucfirst($detail_schedule['status']) ?></span></span></div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="section-card">
            <div class="section-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.15);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-users" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i>
                </div>
                <h6>Booked Tourists (<?= count($detail_bookings) ?>)</h6>
            </div>
            <?php if (empty($detail_bookings)): ?>
                <div class="text-center" style="padding:40px 20px;">
                    <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                        <i class="fas fa-users" style="font-size:1.5rem;color:var(--text-muted,#64748b);opacity:0.4;"></i>
                    </div>
                    <p style="color:var(--text-muted,#94a3b8);margin:0;">No bookings for this tour.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="table" style="margin:0;">
                        <thead><tr><th>Tourist</th><th>Email</th><th>Guests</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($detail_bookings as $bk): ?>
                            <tr>
                                <td class="fw-semibold"><?= sanitize($bk['tourist_name'] ?? 'N/A') ?></td>
                                <td><?= sanitize($bk['tourist_email'] ?? '') ?></td>
                                <td><?= $bk['num_participants'] ?? 1 ?></td>
                                <td><span class="status-chip <?= $bk['status'] ?>"><?= ucfirst($bk['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: ?>
<div class="guide-hero">
    <div class="position-relative" style="z-index:1;">
        <h3 class="fw-bold mb-1"><i class="fas fa-route me-2"></i>My Tours</h3>
        <p class="mb-0 opacity-75" style="font-size:0.9rem;">Manage your assigned tour schedules</p>
    </div>
</div>

<div class="filter-card">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">Filter by Status</label>
            <select class="filter-input" name="status" onchange="this.form.submit()">
                <?php
                $statuses = ['all' => 'All Tours', 'scheduled' => 'Upcoming', 'in_progress' => 'In Progress', 'completed' => 'Completed'];
                foreach ($statuses as $val => $label):
                ?>
                    <option value="<?= $val ?>" <?= $val === $status_filter ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">Search</label>
            <input type="text" class="filter-input" name="search" value="<?= sanitize($search) ?>" placeholder="Search by event or destination...">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn-brand w-100"><i class="fas fa-search me-1"></i>Search</button>
        </div>
    </form>
</div>

<div class="table-card">
    <?php if (empty($schedules['data'])): ?>
        <div class="text-center" style="padding:48px 24px;">
            <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <i class="fas fa-route" style="font-size:2rem;color:var(--text-muted,#64748b);opacity:0.4;"></i>
            </div>
            <h5 class="fw-bold" style="color:var(--text-primary,#e2e8f0);">No tours found</h5>
            <p style="color:var(--text-muted,#94a3b8);">You don't have any assigned tours matching your criteria.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Destination</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Participants</th>
                        <th>Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules['data'] as $sch): ?>
                        <?php
                        $bk_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings WHERE schedule_id = :sid AND status IN ('confirmed','pending')");
                        $bk_stmt->execute([':sid' => $sch['id']]);
                        $booked = (int) $bk_stmt->fetch()['cnt'];
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($sch['event_title'] ?? 'N/A') ?></td>
                            <td><?= sanitize($sch['destination_name'] ?? 'N/A') ?></td>
                            <td><?= format_date($sch['start_date']) ?></td>
                            <td><?= sanitize($sch['start_time'] ?? '') ?> - <?= sanitize($sch['end_time'] ?? '') ?></td>
                            <td><span class="fw-semibold"><?= $booked ?></span> / <?= $sch['max_participants'] ?? '-' ?></td>
                            <td><span class="status-chip <?= $sch['status'] ?>"><?= ucfirst($sch['status']) ?></span></td>
                            <td style="text-align:center;">
                                <div style="display:flex;gap:6px;justify-content:center;">
                                    <a href="<?= BASE_URL ?>/guide/tours.php?detail=<?= $sch['id'] ?>" class="action-btn" title="View Details"><i class="fas fa-eye"></i></a>
                                    <?php if ($sch['status'] === 'scheduled'): ?>
                                        <a href="<?= BASE_URL ?>/guide/tours.php?action=start&schedule_id=<?= $sch['id'] ?>&csrf=<?= $action_csrf ?>" class="action-btn" title="Start Tour" onclick="return confirm('Start this tour?')" style="color:#22c55e;border-color:#22c55e;"><i class="fas fa-play"></i></a>
                                    <?php elseif ($sch['status'] === 'in_progress'): ?>
                                        <a href="<?= BASE_URL ?>/guide/tours.php?action=complete&schedule_id=<?= $sch['id'] ?>&csrf=<?= $action_csrf ?>" class="action-btn" title="Complete Tour" onclick="return confirm('Mark this tour as completed?')" style="color:#f59e0b;border-color:#f59e0b;"><i class="fas fa-check"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($schedules['pages'] > 1): ?>
            <div style="padding:16px;display:flex;justify-content:center;">
                <nav>
                    <ul class="pagination mb-0">
                        <?php if ($schedules['page'] > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $schedules['page'] - 1 ?>&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-left"></i></a></li>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $schedules['pages']; $i++): ?>
                            <li class="page-item <?= $i === $schedules['page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                        <?php if ($schedules['page'] < $schedules['pages']): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $schedules['page'] + 1 ?>&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-right"></i></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$destBookings = $db->prepare(
    "SELECT b.*, d.name as dest_name, d.location as dest_location, u.name as tourist_name
     FROM bookings b
     JOIN destinations d ON b.destination_id = d.id
     JOIN users u ON b.tourist_id = u.id
     WHERE b.guide_id = :gid AND b.destination_id IS NOT NULL
     ORDER BY b.visit_date DESC, b.created_at DESC"
);
$destBookings->execute([':gid' => $guide_id]);
$assignedBookings = $destBookings->fetchAll();
if (!empty($assignedBookings)): ?>
<div class="section-card mt-4">
    <div class="section-header">
        <div style="width:28px;height:28px;border-radius:6px;background:rgba(245,158,11,0.15);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-map-marked-alt" style="color:#f59e0b;font-size:0.7rem;"></i>
        </div>
        <h6>Destination Bookings</h6>
    </div>
    <div style="overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Ref</th>
                    <th>Tourist</th>
                    <th>Destination</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Guests</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignedBookings as $ab): ?>
                <tr>
                    <td><?= $ab['id'] ?></td>
                    <td><span class="status-chip" style="background:rgba(255,255,255,0.06);color:var(--text-primary,#e2e8f0);"><?= sanitize($ab['booking_reference'] ?? 'N/A') ?></span></td>
                    <td><?= sanitize($ab['tourist_name']) ?></td>
                    <td><?= sanitize($ab['dest_name']) ?></td>
                    <td><?= format_date($ab['visit_date']) ?></td>
                    <td><?= date('h:i A', strtotime($ab['visit_time'])) ?></td>
                    <td><?= $ab['num_participants'] ?></td>
                    <td><span class="status-chip <?= $ab['status'] ?>"><?= ucfirst($ab['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php }); ?>
