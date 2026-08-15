<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');

require_once __DIR__ . '/../includes/classes/User.php';
require_once __DIR__ . '/../includes/classes/Booking.php';
require_once __DIR__ . '/../includes/classes/ActivityLog.php';
require_once __DIR__ . '/../includes/classes/Payment.php';

$userModel = new User();
$bookingModel = new Booking();
$activityLogModel = new ActivityLog();
$paymentModel = new Payment();

$admin = current_user();
$stats = $userModel->getStats();
$bookingStats = $bookingModel->getStats();
$recentActivity = $activityLogModel->getRecent(10);
$paymentStats = $paymentModel->getStats();
$monthlyRevenue = $paymentModel->getMonthlyRevenue(6);

$db = Database::getInstance()->getConnection();

$stmt = $db->prepare(
    "SELECT b.*, u.name as tourist_name, e.title as event_title, d.name as destination_name, s.start_date
     FROM bookings b
     LEFT JOIN users u ON b.tourist_id = u.id
     LEFT JOIN schedules s ON b.schedule_id = s.id
     LEFT JOIN events e ON s.event_id = e.id
     LEFT JOIN destinations d ON e.destination_id = d.id
     ORDER BY b.created_at DESC LIMIT 5"
);
$stmt->execute();
$recentBookings = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT iv.*, u.name as user_name, u.email as user_email
     FROM id_verifications iv
     LEFT JOIN users u ON iv.user_id = u.id
     WHERE iv.status = 'pending'
     ORDER BY iv.created_at ASC
     LIMIT 5"
);
$stmt->execute();
$pendingVerifications = $stmt->fetchAll();

$bookingByStatus = $db->query(
    "SELECT status, COUNT(*) as cnt FROM bookings GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$bookingByMonth = $db->query(
    "SELECT db_date_format(, '') as month, COUNT(*) as cnt
     FROM bookings
     WHERE created_at >= db_date_sub(, 'INTERVAL  ')
     GROUP BY db_date_format(, '')
     ORDER BY month ASC"
)->fetchAll();

$popularDestinations = $db->query(
    "SELECT d.name, COUNT(b.id) as booking_count
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.id
     JOIN events e ON s.event_id = e.id
     JOIN destinations d ON e.destination_id = d.id
     WHERE b.status IN ('confirmed','completed')
     GROUP BY d.id, d.name
     ORDER BY booking_count DESC
     LIMIT 5"
)->fetchAll();

$paymentByMethod = $db->query(
    "SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total
     FROM payments WHERE payment_status = 'paid'
     GROUP BY payment_method"
)->fetchAll();

$revenueByMonth = $db->query(
    "SELECT db_date_format(, '') as month,
            SUM(total_amount) as revenue
     FROM payments
     WHERE payment_status = 'paid' AND created_at >= db_date_sub(, 'INTERVAL  ')
     GROUP BY db_date_format(, '')
     ORDER BY month ASC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_verification' && isset($_POST['verification_id'])) {
        $vid = (int) $_POST['verification_id'];
        $stmt = $db->prepare("UPDATE id_verifications SET status = 'approved', verified_at = db_now(), verified_by = :uid WHERE id = :id");
        $stmt->execute([':id' => $vid, ':uid' => $_SESSION['user_id']]);
        flash_message('success', 'Verification approved.');
        redirect('/admin/index.php');
    }

    if ($action === 'reject_verification' && isset($_POST['verification_id'])) {
        $vid = (int) $_POST['verification_id'];
        $stmt = $db->prepare("UPDATE id_verifications SET status = 'rejected', verified_at = db_now(), verified_by = :uid WHERE id = :id");
        $stmt->execute([':id' => $vid, ':uid' => $_SESSION['user_id']]);
        flash_message('success', 'Verification rejected.');
        redirect('/admin/index.php');
    }
}

render_page('admin', 'index.php', 'Admin Dashboard', function () use (
    $admin, $stats, $bookingStats, $paymentStats, $recentActivity,
    $recentBookings, $pendingVerifications, $monthlyRevenue,
    $bookingByStatus, $bookingByMonth, $popularDestinations,
    $paymentByMethod, $revenueByMonth
) {
?>

<style>
.stat-card {
    border: none;
    border-radius: 16px;
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    position: relative;
    overflow: hidden;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #f1f5f9);
}
.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.08);
}
.stat-card .card-body { padding: 22px; }
.stat-card .stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; transition: transform 0.3s;
}
.stat-card:hover .stat-icon { transform: scale(1.1); }
.stat-card .stat-value {
    font-size: 1.75rem; font-weight: 800; line-height: 1.2;
    letter-spacing: -0.5px;
}
.stat-card .stat-label {
    font-size: 0.78rem; color: var(--text-muted, #64748b);
    font-weight: 500; margin-top: 2px;
}
.stat-card .stat-change {
    font-size: 0.72rem; font-weight: 600; display: inline-flex;
    align-items: center; gap: 3px; padding: 2px 7px;
    border-radius: 50px; margin-top: 6px;
}
.stat-card .stat-change.up { background: rgba(16,185,129,0.1); color: #10b981; }
.stat-card .stat-change.down { background: rgba(239,68,68,0.1); color: #ef4444; }
.stat-card .accent-bar {
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
}
.stat-card canvas.sparkline { height: 30px !important; margin-top: 8px; }

.dash-section { margin-bottom: 28px; }
.dash-section-title {
    font-size: 0.85rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.8px; color: var(--text-muted, #64748b);
    margin-bottom: 14px; padding-left: 4px;
}

.chart-card {
    border: none; border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    border: 1px solid var(--border-color, #f1f5f9);
    background: var(--card-bg, #fff);
}
.chart-card .card-header {
    background: transparent; border-bottom: 1px solid var(--border-color, #f1f5f9);
    padding: 16px 20px; color: var(--text-primary, #1e293b);
}
.chart-card canvas { max-height: 280px; }

.top-list-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 0; border-bottom: 1px solid var(--border-color, #f1f5f9);
    transition: background 0.15s;
}
.top-list-item:last-child { border-bottom: none; }
.top-list-item .rank {
    width: 30px; height: 30px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
}

.greeting-banner {
    background: linear-gradient(135deg, var(--card-bg, #fff) 0%, rgba(12,110,94,0.03) 100%);
    border-radius: 16px; padding: 24px 28px;
    border: 1px solid var(--border-color, #e2e8f0);
    margin-bottom: 24px; position: relative; overflow: hidden;
}
.greeting-banner::before {
    content: ''; position: absolute; top: -40px; right: -20px;
    width: 120px; height: 120px; border-radius: 50%;
    background: radial-gradient(circle, rgba(12,110,94,0.06) 0%, transparent 70%);
    pointer-events: none;
}

.quick-actions {
    display: flex; gap: 8px; flex-wrap: wrap;
}
.quick-action-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px; border-radius: 10px;
    font-size: 0.8rem; font-weight: 600;
    text-decoration: none; transition: all 0.25s cubic-bezier(0.4,0,0.2,1);
    border: 1.5px solid var(--border-color, #e2e8f0);
    color: var(--text-primary, #1e293b);
    background: var(--card-bg, #fff);
    position: relative; overflow: hidden;
}
.quick-action-btn::before {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(12,110,94,0.08), transparent);
    opacity: 0; transition: opacity 0.25s;
}
.quick-action-btn:hover {
    border-color: #0c6e5e; color: #0c6e5e;
    transform: translateY(-2px); box-shadow: 0 6px 16px rgba(12,110,94,0.12);
}
.quick-action-btn:hover::before { opacity: 1; }
.quick-action-btn i { font-size: 0.85rem; position: relative; z-index: 1; }
.quick-action-btn span { position: relative; z-index: 1; }
.quick-action-btn.primary {
    background: linear-gradient(135deg, #0c6e5e, #10b981);
    border-color: transparent; color: #fff;
}
.quick-action-btn.primary:hover {
    color: #fff; box-shadow: 0 6px 20px rgba(12,110,94,0.3);
}

.empty-state {
    text-align: center; padding: 40px 20px; color: var(--text-muted, #94a3b8);
}
.empty-state .empty-icon {
    width: 64px; height: 64px; border-radius: 16px; margin: 0 auto 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; background: var(--bg-secondary, #f1f5f9);
}
.empty-state h6 { font-weight: 700; font-size: .9rem; color: var(--text-primary, #1e293b); margin-bottom: 4px; }
.empty-state p { font-size: .82rem; margin: 0; }
</style>

<?php
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$dashDate = date('l, F j, Y');
?>

<div class="greeting-banner">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="fw-bold mb-1" style="font-size:1.25rem;color:var(--text-primary,#1e293b);"><?= $greeting ?>, <?= sanitize(explode(' ', $admin['name'])[0]) ?>! <span style="font-size:.85rem;font-weight:500;color:var(--text-muted,#64748b);"><?= $dashDate ?></span></h4>
            <p class="mb-0" style="font-size:.85rem;color:var(--text-muted,#64748b);">Here's what's happening with BINALGO today.</p>
        </div>
        <div class="quick-actions">
            <a href="<?= BASE_URL ?>/admin/users.php" class="quick-action-btn primary"><i class="fas fa-user-plus"></i> <span>Add User</span></a>
            <a href="<?= BASE_URL ?>/admin/destinations.php" class="quick-action-btn"><i class="fas fa-plus-circle"></i> <span>New Destination</span></a>
            <a href="<?= BASE_URL ?>/admin/reports.php" class="quick-action-btn"><i class="fas fa-chart-bar"></i> <span>Reports</span></a>
        </div>
    </div>
</div>

<!-- Overview KPIs -->
<div class="dash-section-title">Overview</div>
<div class="row g-3 mb-4">
    <?php
    $kpiCards = [
        ['label' => 'Total Revenue', 'value' => '₱' . number_format($paymentStats['total_revenue'] ?? 0), 'icon' => 'fa-dollar-sign', 'color' => '#0c6e5e', 'bg' => 'rgba(12,110,94,0.1)', 'gradient' => 'linear-gradient(135deg,#0c6e5e,#14b8a6)', 'sub' => '₱' . number_format($paymentStats['monthly_revenue'] ?? 0) . ' this month', 'change' => '+0%', 'changeDir' => 'up'],
        ['label' => 'Total Bookings', 'value' => $bookingStats['total'] ?? 0, 'icon' => 'fa-ticket', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.1)', 'gradient' => 'linear-gradient(135deg,#3b82f6,#60a5fa)', 'sub' => ($bookingStats['confirmed'] ?? 0) . ' confirmed · ' . ($bookingStats['pending'] ?? 0) . ' pending', 'change' => '+0%', 'changeDir' => 'up'],
        ['label' => 'Active Users', 'value' => $stats['total_users'], 'icon' => 'fa-users', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.1)', 'gradient' => 'linear-gradient(135deg,#f59e0b,#fbbf24)', 'sub' => $stats['total_guides'] . ' guides · ' . $stats['pending_verifications'] . ' pending', 'change' => '+0%', 'changeDir' => 'up'],
        ['label' => 'Pending Actions', 'value' => ($stats['pending_verifications'] ?? 0) + ($bookingStats['pending'] ?? 0), 'icon' => 'fa-bell', 'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.1)', 'gradient' => 'linear-gradient(135deg,#ef4444,#f87171)', 'sub' => ($stats['pending_verifications'] ?? 0) . ' verifications · ' . ($bookingStats['pending'] ?? 0) . ' bookings', 'change' => '0', 'changeDir' => 'neutral'],
    ];
    foreach ($kpiCards as $kpi): ?>
    <div class="col-6 col-lg-3">
        <div class="stat-card card shadow-sm">
            <div class="accent-bar" style="background:<?= $kpi['gradient'] ?>;"></div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value" style="color:<?= $kpi['color'] ?>;"><?= $kpi['value'] ?></div>
                        <div class="stat-label"><?= $kpi['label'] ?></div>
                        <?php if (!empty($kpi['change'])): ?>
                            <span class="stat-change <?= $kpi['changeDir'] ?? 'up' ?>">
                                <i class="fas fa-<?= ($kpi['changeDir'] ?? 'up') === 'up' ? 'arrow-up' : (($kpi['changeDir'] ?? '') === 'down' ? 'arrow-down' : 'minus') ?>"></i>
                                <?= $kpi['change'] ?> vs last month
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($kpi['sub'])): ?>
                            <div style="font-size:.72rem;color:var(--text-muted,#94a3b8);margin-top:4px;"><?= $kpi['sub'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="stat-icon" style="background:<?= $kpi['bg'] ?>;color:<?= $kpi['color'] ?>;">
                        <i class="fas <?= $kpi['icon'] ?>"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row 1 -->
<div class="dash-section-title">Analytics</div>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-line me-2" style="color:#0c6e5e;"></i>Revenue Trend</h6>
                <span class="badge" style="background:rgba(12,110,94,0.1);color:#0c6e5e;font-weight:600;">Last 6 Months</span>
            </div>
            <div class="card-body">
                <?php if (empty($revenueByMonth)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-chart-line"></i></div>
                        <h6>No Revenue Data Yet</h6>
                        <p>Revenue will appear here once bookings are paid.<br>
                        <a href="<?= BASE_URL ?>/admin/bookings.php" style="color:#0c6e5e;font-weight:600;font-size:.8rem;">View Bookings <i class="fas fa-arrow-right" style="font-size:.6rem;"></i></a></p>
                    </div>
                <?php else: ?>
                    <canvas id="revenueChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-credit-card me-2" style="color:#3b82f6;"></i>Payment Methods</h6>
            </div>
            <div class="card-body">
                <?php if (empty($paymentByMethod)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:rgba(59,130,246,0.1);color:#3b82f6;"><i class="fas fa-credit-card"></i></div>
                        <h6>No Payment Data Yet</h6>
                        <p>Payment methods will show once transactions are processed.</p>
                    </div>
                <?php else: ?>
                    <canvas id="paymentMethodChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2" style="color:#0c6e5e;"></i>Bookings by Month</h6>
                <span class="badge" style="background:rgba(12,110,94,0.1);color:#0c6e5e;font-weight:600;">Last 6 Months</span>
            </div>
            <div class="card-body">
                <?php if (empty($bookingByMonth)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-calendar-check"></i></div>
                        <h6>No Booking Data Yet</h6>
                        <p>Monthly booking trends will appear here once tours are booked.</p>
                    </div>
                <?php else: ?>
                    <canvas id="bookingTrendChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-pie me-2" style="color:#8b5cf6;"></i>Booking Status Distribution</h6>
            </div>
            <div class="card-body">
                <?php if (empty($bookingByStatus)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:rgba(139,92,246,0.1);color:#8b5cf6;"><i class="fas fa-chart-pie"></i></div>
                        <h6>No Status Data Yet</h6>
                        <p>Booking status breakdown will appear here once bookings exist.</p>
                    </div>
                <?php else: ?>
                    <canvas id="bookingStatusChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top Lists + Activity -->
<div class="dash-section-title">Rankings & Activity</div>
<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-map-marker-alt me-2" style="color:#0c6e5e;"></i>Top Destinations</h6>
            </div>
            <div class="card-body">
                <?php if (empty($popularDestinations)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-map-marked-alt"></i></div>
                        <h6>No Destinations Yet</h6>
                        <p>Popular destinations will be ranked here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($popularDestinations as $i => $d):
                        $colors = [['#dbeafe','#3b82f6'],['#d1fae5','#10b981'],['#fef3c7','#f59e0b'],['#e0e7ff','#6366f1'],['#fce7f3','#ec4899']];
                        $max = $popularDestinations[0]['booking_count'] ?? 1;
                        $pct = round(($d['booking_count'] / $max) * 100);
                    ?>
                        <div class="top-list-item">
                            <div class="rank" style="background:<?= $colors[$i][0] ?>;color:<?= $colors[$i][1] ?>;"><?= $i + 1 ?></div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold small"><?= sanitize($d['name']) ?></span>
                                    <span class="fw-bold small" style="color:<?= $colors[$i][1] ?>;"><?= $d['booking_count'] ?></span>
                                </div>
                                <div class="mt-1" style="height:4px;border-radius:4px;background:var(--border-color,#f1f5f9);overflow:hidden;">
                                    <div style="width:<?= $pct ?>%;height:100%;border-radius:4px;background:<?= $colors[$i][1] ?>;transition:width 0.6s ease;"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-bolt me-2" style="color:#f59e0b;"></i>Recent Activity</h6>
                <a href="<?= BASE_URL ?>/admin/activity_logs.php" class="btn btn-sm" style="background:rgba(12,110,94,0.1);color:#0c6e5e;font-weight:600;">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentActivity)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-bolt"></i></div>
                        <h6>No Activity Yet</h6>
                        <p>Recent actions will appear here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($recentActivity, 0, 6) as $log):
                        $actionIcons = ['login' => ['fa-sign-in-alt','success'], 'booking_created' => ['fa-ticket','primary'], 'register' => ['fa-user-plus','info']];
                        $iconData = $actionIcons[$log['action']] ?? ['fa-circle', str_contains($log['action'] ?? '', 'delete') ? 'danger' : 'secondary'];
                    ?>
                        <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mt-1" style="width:32px;height:32px;background:var(--border-color,#f1f5f9);flex-shrink:0;">
                                <i class="fas <?= $iconData[0] ?> text-<?= $iconData[1] ?>" style="font-size:0.6rem;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="small fw-semibold"><?= sanitize($log['user_name'] ?? 'System') ?></div>
                                <small class="text-muted"><?= sanitize(truncate($log['details'] ?? $log['action'], 45)) ?></small>
                            </div>
                            <small class="text-muted text-nowrap" style="font-size:0.7rem;"><?= time_ago($log['created_at']) ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Bookings + Pending Verifications -->
<div class="dash-section-title">Management</div>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-ticket me-2" style="color:#0c6e5e;"></i>Recent Bookings</h6>
                <a href="<?= BASE_URL ?>/admin/bookings.php" class="btn btn-sm" style="background:rgba(12,110,94,0.1);color:#0c6e5e;font-weight:600;">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr style="background:var(--border-color,#f8fafc);">
                                <th class="ps-3" style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Tourist</th>
                                <th style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Event</th>
                                <th style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Destination</th>
                                <th style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Date</th>
                                <th style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentBookings)): ?>
                                <tr><td colspan="5"><div class="empty-state py-4">
                                    <div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;width:48px;height:48px;font-size:1.1rem;"><i class="fas fa-ticket"></i></div>
                                    <h6>No Bookings Yet</h6>
                                    <p>Recent bookings will appear here.</p>
                                </div></td></tr>
                            <?php else: ?>
                                <?php foreach ($recentBookings as $b):
                                    $statusMap = [
                                        'confirmed' => ['success','fa-check-circle'],
                                        'pending' => ['warning','fa-clock'],
                                        'cancelled' => ['danger','fa-times-circle'],
                                        'completed' => ['primary','fa-flag-checkered'],
                                    ];
                                    $s = $statusMap[$b['status']] ?? ['secondary','fa-circle'];
                                ?>
                                    <tr>
                                        <td class="ps-3"><span class="fw-semibold small"><?= sanitize($b['tourist_name'] ?? 'N/A') ?></span></td>
                                        <td><span class="small"><?= sanitize(truncate($b['event_title'] ?? '', 28)) ?></span></td>
                                        <td><small class="text-muted"><?= sanitize($b['destination_name'] ?? '') ?></small></td>
                                        <td><small class="text-muted"><?= format_date($b['start_date'] ?? '') ?></small></td>
                                        <td><span class="badge rounded-pill bg-<?= $s[0] ?> d-inline-flex align-items-center gap-1"><i class="fas <?= $s[1] ?>" style="font-size:0.6rem;"></i><?= ucfirst($b['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-shield-alt me-2 text-danger"></i>ID Verifications</h6>
                <?php if (!empty($pendingVerifications)): ?>
                    <span class="badge bg-danger rounded-pill"><?= count($pendingVerifications) ?> pending</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pendingVerifications)): ?>
                    <div class="empty-state py-4">
                        <div class="empty-icon" style="background:rgba(16,185,129,0.1);color:#10b981;width:48px;height:48px;font-size:1.1rem;"><i class="fas fa-check-double"></i></div>
                        <h6>All Caught Up!</h6>
                        <p>No pending ID verifications.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pendingVerifications as $v): ?>
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:34px;height:34px;background:rgba(239,68,68,0.1);color:#ef4444;flex-shrink:0;font-size:0.75rem;font-weight:700;">
                                    <?= strtoupper(substr($v['user_name'] ?? 'U', 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-semibold small"><?= sanitize($v['user_name'] ?? 'N/A') ?></div>
                                    <small class="text-muted"><?= sanitize($v['user_email'] ?? '') ?></small>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="approve_verification">
                                    <input type="hidden" name="verification_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="btn btn-sm rounded-pill" style="background:rgba(16,185,129,0.1);color:#10b981;" title="Approve"><i class="fas fa-check"></i></button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="reject_verification">
                                    <input type="hidden" name="verification_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="btn btn-sm rounded-pill" style="background:rgba(239,68,68,0.1);color:#ef4444;" title="Reject"><i class="fas fa-times"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const teal = '#0c6e5e';
    const tealLight = '#14b8a6';
    const blue = '#3b82f6';
    const green = '#10b981';
    const amber = '#f59e0b';
    const red = '#ef4444';
    const purple = '#8b5cf6';

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    Chart.defaults.color = textColor;
    Chart.defaults.font.family = "'Inter', sans-serif";

    // Revenue Trend
    const revData = <?= json_encode($revenueByMonth) ?>;
    if (revData.length > 0) {
        const revCanvas = document.getElementById('revenueChart');
        const revCtx = revCanvas.getContext('2d');
        const revGrad = revCtx.createLinearGradient(0, 0, 0, 280);
        revGrad.addColorStop(0, 'rgba(12,110,94,0.25)');
        revGrad.addColorStop(1, 'rgba(12,110,94,0.01)');
        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: revData.map(r => {
                    const [y, m] = r.month.split('-');
                    return new Date(y, m - 1).toLocaleDateString('en', { month: 'short' });
                }),
                datasets: [{
                    label: 'Revenue',
                    data: revData.map(r => parseFloat(r.revenue)),
                    borderColor: teal,
                    backgroundColor: revGrad,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: teal,
                    pointBorderWidth: 2,
                    borderWidth: 2.5,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#fff',
                        titleColor: isDark ? '#f1f5f9' : '#1e293b',
                        bodyColor: isDark ? '#cbd5e1' : '#475569',
                        borderColor: isDark ? '#334155' : '#e2e8f0',
                        borderWidth: 1,
                        cornerRadius: 10,
                        padding: 12,
                        displayColors: false,
                        callbacks: { label: ctx => '₱' + ctx.parsed.y.toLocaleString() }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { callback: v => '₱' + v.toLocaleString(), font: { size: 11 } } },
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    }

    // Payment Methods
    const pmData = <?= json_encode($paymentByMethod) ?>;
    if (pmData.length > 0) {
        const pmLabels = pmData.map(p => p.payment_method === 'gcash' ? 'GCash' : (p.payment_method === 'maya' ? 'Maya' : (p.payment_method === 'card' ? 'Card' : p.payment_method)));
        const pmColors = pmData.map(p => {
            if (p.payment_method === 'gcash') return '#007dfe';
            if (p.payment_method === 'maya') return '#00c853';
            if (p.payment_method === 'card') return purple;
            return blue;
        });
        new Chart(document.getElementById('paymentMethodChart'), {
            type: 'doughnut',
            data: {
                labels: pmLabels,
                datasets: [{
                    data: pmData.map(p => parseInt(p.cnt)),
                    backgroundColor: pmColors,
                    borderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12 } } },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#fff',
                        titleColor: isDark ? '#f1f5f9' : '#1e293b',
                        bodyColor: isDark ? '#cbd5e1' : '#475569',
                        borderColor: isDark ? '#334155' : '#e2e8f0',
                        borderWidth: 1, cornerRadius: 10, padding: 12,
                    }
                }
            }
        });
    }

    // Booking Trend
    const btData = <?= json_encode($bookingByMonth) ?>;
    if (btData.length > 0) {
        const btCanvas = document.getElementById('bookingTrendChart');
        const btCtx = btCanvas.getContext('2d');
        const btGrad = btCtx.createLinearGradient(0, 0, 0, 280);
        btGrad.addColorStop(0, 'rgba(12,110,94,0.8)');
        btGrad.addColorStop(1, 'rgba(20,184,166,0.4)');
        new Chart(btCtx, {
            type: 'bar',
            data: {
                labels: btData.map(b => {
                    const [y, m] = b.month.split('-');
                    return new Date(y, m - 1).toLocaleDateString('en', { month: 'short' });
                }),
                datasets: [{
                    label: 'Bookings',
                    data: btData.map(b => parseInt(b.cnt)),
                    backgroundColor: btGrad,
                    borderRadius: 8,
                    borderSkipped: false,
                    barThickness: 32,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#fff',
                        titleColor: isDark ? '#f1f5f9' : '#1e293b',
                        bodyColor: isDark ? '#cbd5e1' : '#475569',
                        borderColor: isDark ? '#334155' : '#e2e8f0',
                        borderWidth: 1, cornerRadius: 10, padding: 12,
                        displayColors: false,
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { stepSize: 1, font: { size: 11 } } },
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    }

    // Booking Status
    const bsData = <?= json_encode($bookingByStatus) ?>;
    const bsLabels = Object.keys(bsData);
    const bsValues = Object.values(bsData);
    if (bsLabels.length > 0) {
        const bsColors = bsLabels.map(s => ({ confirmed: green, pending: amber, completed: blue, cancelled: red, in_progress: teal }[s] || '#94a3b8'));
        new Chart(document.getElementById('bookingStatusChart'), {
            type: 'doughnut',
            data: {
                labels: bsLabels.map(s => s.charAt(0).toUpperCase() + s.slice(1)),
                datasets: [{
                    data: bsValues,
                    backgroundColor: bsColors,
                    borderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12 } } },
                    tooltip: {
                        backgroundColor: isDark ? '#1e293b' : '#fff',
                        titleColor: isDark ? '#f1f5f9' : '#1e293b',
                        bodyColor: isDark ? '#cbd5e1' : '#475569',
                        borderColor: isDark ? '#334155' : '#e2e8f0',
                        borderWidth: 1, cornerRadius: 10, padding: 12,
                    }
                }
            }
        });
    }
});
</script>

<?php }); ?>
