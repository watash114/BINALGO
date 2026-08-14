<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');

$booking = new Booking();
$schedule = new Schedule();
$user = new User();
$feedback = new Feedback();
$event = new Event();
$destination = new Destination();

$today = date('Y-m-d');

$bookingStats = $booking->getStats();
$feedbackStats = $feedback->getStats();
$userStats = $user->getStats();
$todaySchedules = $schedule->getDailySchedules($today);
$pendingUsers = $user->findAll(['status' => 'pending', 'role' => 'tourist'], 1, 10);
$recentFeedback = $feedback->findAll([], 1, 5);

$todayBookingsCount = 0;
$allBookings = $booking->findAll([], 1, 1000);
foreach ($allBookings['data'] as $b) {
    if (isset($b['start_date']) && $b['start_date'] === $today && in_array($b['status'], ['confirmed', 'pending'])) {
        $todayBookingsCount++;
    }
}

$todayAssignmentsCount = count($todaySchedules);

render_page('staff', 'index.php', 'Staff Dashboard', function () use ($bookingStats, $feedbackStats, $userStats, $todaySchedules, $pendingUsers, $recentFeedback, $todayBookingsCount, $todayAssignmentsCount, $today) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.dash-stat{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:20px;transition:all 0.25s;height:100%;position:relative;overflow:hidden;}
.dash-stat::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;border-radius:4px 0 0 4px;}
.dash-stat.blue::before{background:#3b82f6;}
.dash-stat.green::before{background:#22c55e;}
.dash-stat.amber::before{background:#f59e0b;}
.dash-stat.cyan::before{background:#06b6d4;}
.dash-stat:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2);}
.dash-stat .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.dash-stat .stat-value{font-size:1.8rem;font-weight:800;color:var(--text-primary,#e2e8f0);}
.dash-stat .stat-label{font-size:0.8rem;color:var(--text-muted,#94a3b8);margin-top:2px;}
.section-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;justify-content:space-between;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#e2e8f0);}
.section-link{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;font-size:0.75rem;font-weight:600;border:1px solid var(--border-color,#2a3042);background:var(--card-bg,#1a1f2e);color:var(--text-primary,#e2e8f0);text-decoration:none;transition:all 0.2s;}
.section-link:hover{border-color:var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.08);}
.table-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.table-card .table{margin:0;}
.table-card .table thead th{background:rgba(255,255,255,0.03);border-bottom:1px solid var(--border-color,#2a3042);color:var(--text-muted,#94a3b8);font-size:0.8rem;font-weight:600;padding:12px 16px;}
.table-card .table tbody td{border-bottom:1px solid var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);font-size:0.85rem;padding:12px 16px;vertical-align:middle;}
.table-card .table tbody tr:last-child td{border-bottom:none;}
.table-card .table tbody tr:hover{background:rgba(255,255,255,0.02);}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:0.72rem;font-weight:600;}
.status-chip.scheduled{background:rgba(34,197,94,0.15);color:#22c55e;}
.status-chip.in_progress{background:rgba(59,130,246,0.15);color:#3b82f6;}
.status-chip.completed{background:rgba(100,116,139,0.15);color:#94a3b8;}
.status-chip.cancelled{background:rgba(239,68,68,0.15);color:#ef4444;}
.status-chip.pending{background:rgba(245,158,11,0.15);color:#f59e0b;}
.empty-state{text-align:center;padding:40px 20px;}
.empty-state-icon{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;}
.empty-state h6{font-weight:600;color:var(--text-primary,#e2e8f0);margin-bottom:4px;}
.empty-state p{color:var(--text-muted,#94a3b8);font-size:0.85rem;margin:0;}
.reg-item{display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border-color,#2a3042);transition:background 0.15s;}
.reg-item:last-child{border-bottom:none;}
.reg-item:hover{background:rgba(255,255,255,0.02);}
.reg-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color,#2a3042);}
.reg-name{font-weight:600;font-size:0.85rem;color:var(--text-primary,#e2e8f0);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.reg-email{font-size:0.78rem;color:var(--text-muted,#94a3b8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
.fb-item{padding:14px 16px;border-bottom:1px solid var(--border-color,#2a3042);transition:background 0.15s;}
.fb-item:last-child{border-bottom:none;}
.fb-item:hover{background:rgba(255,255,255,0.02);}
.fb-user{font-weight:600;font-size:0.85rem;color:var(--text-primary,#e2e8f0);}
.fb-comment{font-size:0.8rem;color:var(--text-muted,#94a3b8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.fb-time{font-size:0.7rem;color:var(--text-muted,#64748b);}
.rating-stars{color:#f59e0b;font-size:0.7rem;}
.quick-link{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 12px;border-radius:12px;border:1px solid var(--border-color,#2a3042);background:var(--card-bg,#1a1f2e);text-decoration:none;transition:all 0.25s;height:100%;}
.quick-link:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2);border-color:var(--primary,#0c6e5e);}
.quick-link .ql-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;}
.quick-link .ql-label{font-size:0.8rem;font-weight:600;color:var(--text-primary,#e2e8f0);}
</style>

<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1">Welcome back, <?= sanitize(current_user()['name'] ?? 'Staff') ?></h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Here's what's happening today.</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.1);padding:6px 14px;border-radius:8px;font-size:0.85rem;"><i class="fas fa-calendar-day"></i><?= date('l, M d, Y') ?></span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="dash-stat blue">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background:rgba(59,130,246,0.15);"><i class="fas fa-ticket" style="color:#3b82f6;"></i></div>
                <div style="margin-left:14px;">
                    <div class="stat-label">Today's Bookings</div>
                    <div class="stat-value"><?= $todayBookingsCount ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="dash-stat green">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background:rgba(34,197,94,0.15);"><i class="fas fa-route" style="color:#22c55e;"></i></div>
                <div style="margin-left:14px;">
                    <div class="stat-label">Tour Assignments Today</div>
                    <div class="stat-value"><?= $todayAssignmentsCount ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="dash-stat amber">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background:rgba(245,158,11,0.15);"><i class="fas fa-user-clock" style="color:#f59e0b;"></i></div>
                <div style="margin-left:14px;">
                    <div class="stat-label">Pending Registrations</div>
                    <div class="stat-value"><?= $userStats['pending_verifications'] ?? 0 ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="dash-stat cyan">
            <div class="d-flex align-items-center">
                <div class="stat-icon" style="background:rgba(6,182,212,0.15);"><i class="fas fa-star" style="color:#06b6d4;"></i></div>
                <div style="margin-left:14px;">
                    <div class="stat-label">Avg. Feedback</div>
                    <div class="stat-value"><?= number_format((float)($feedbackStats['average_rating'] ?? 0), 1) ?> <span style="font-size:0.9rem;font-weight:600;color:var(--text-muted,#94a3b8);">/5</span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="section-card" style="height:100%;">
            <div class="section-header">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-calendar" style="color:#3b82f6;font-size:0.7rem;"></i></div>
                    <h6>Today's Schedules</h6>
                </div>
                <a href="schedules.php" class="section-link"><i class="fas fa-arrow-right"></i>View All</a>
            </div>
            <?php if (empty($todaySchedules)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-calendar-times" style="font-size:1.5rem;color:var(--text-muted,#64748b);opacity:0.4;"></i></div>
                    <h6>No schedules for today</h6>
                    <p>No tour schedules are assigned for today.</p>
                    <div class="empty-actions">
                        <a href="schedules.php" class="btn-cta"><i class="fas fa-plus me-1"></i>Create Schedule</a>
                    </div>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="table">
                        <thead><tr><th>Event</th><th>Guide</th><th>Time</th><th>Destination</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($todaySchedules as $s): ?>
                            <tr>
                                <td class="fw-semibold"><?= sanitize($s['event_title'] ?? 'N/A') ?></td>
                                <td style="color:var(--text-primary,#e2e8f0);font-size:0.85rem;"><?= sanitize($s['guide_name'] ?? 'Unassigned') ?></td>
                                <td><i class="fas fa-clock me-1" style="color:var(--text-muted,#64748b);font-size:0.75rem;"></i><span style="color:var(--text-primary,#e2e8f0);font-size:0.85rem;"><?= sanitize($s['start_time'] ?? '') ?> - <?= sanitize($s['end_time'] ?? '') ?></span></td>
                                <td style="color:var(--text-primary,#e2e8f0);font-size:0.85rem;"><?= sanitize($s['destination_name'] ?? 'N/A') ?></td>
                                <td>
                                    <?php
                                    $chipClass = match($s['status'] ?? '') { 'scheduled' => 'scheduled', 'in_progress' => 'in_progress', 'completed' => 'completed', 'cancelled' => 'cancelled', default => 'completed' };
                                    ?>
                                    <span class="status-chip <?= $chipClass ?>"><?= sanitize(ucfirst($s['status'] ?? 'unknown')) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="section-card" style="height:100%;">
            <div class="section-header">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(245,158,11,0.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-user-plus" style="color:#f59e0b;font-size:0.7rem;"></i></div>
                    <h6>Pending Registrations</h6>
                </div>
                <a href="guide_availability.php" class="section-link"><i class="fas fa-cog"></i>Manage</a>
            </div>
            <?php if (empty($pendingUsers['data'])): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-check-circle" style="font-size:1.5rem;color:#22c55e;opacity:0.6;"></i></div>
                    <h6>No pending registrations</h6>
                    <p>All caught up! No users waiting for approval.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendingUsers['data'] as $u): ?>
                <div class="reg-item">
                    <img src="<?= get_avatar_url($u) ?>" class="reg-avatar" alt="">
                    <div style="margin-left:12px;flex-grow:1;overflow:hidden;">
                        <div class="reg-name"><?= sanitize($u['name']) ?></div>
                        <div class="reg-email"><?= sanitize($u['email']) ?></div>
                    </div>
                    <span class="status-chip pending">Pending</span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="section-card">
            <div class="section-header">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(6,182,212,0.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-star" style="color:#06b6d4;font-size:0.7rem;"></i></div>
                    <h6>Recent Feedback</h6>
                </div>
                <a href="feedback.php" class="section-link"><i class="fas fa-arrow-right"></i>View All</a>
            </div>
            <?php if (empty($recentFeedback['data'])): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-comment-dots" style="font-size:1.5rem;color:var(--text-muted,#64748b);opacity:0.4;"></i></div>
                    <h6>No feedback yet</h6>
                    <p>Feedback from tourists will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentFeedback['data'] as $f): ?>
                <div class="fb-item">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fb-user"><?= sanitize($f['tourist_name'] ?? 'Anonymous') ?></span>
                        <span class="rating-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star" style="color:<?= $i <= $f['overall_rating'] ? '#f59e0b' : 'var(--text-muted,#4a5568)' ?>;"></i>
                            <?php endfor; ?>
                        </span>
                    </div>
                    <div class="fb-comment"><?= sanitize(truncate($f['comment'] ?? '', 80)) ?></div>
                    <div class="fb-time"><?= time_ago($f['created_at'] ?? '') ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="section-card">
            <div class="section-header">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.15);display:flex;align-items:center;justify-content:center;"><i class="fas fa-link" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i></div>
                    <h6>Quick Links</h6>
                </div>
            </div>
            <div style="padding:16px;">
                <div class="row g-3">
                    <div class="col-4">
                        <a href="bookings.php" class="quick-link">
                            <div class="ql-icon" style="background:rgba(59,130,246,0.12);"><i class="fas fa-ticket" style="color:#3b82f6;font-size:1.2rem;"></i></div>
                            <span class="ql-label">Bookings</span>
                        </a>
                    </div>
                    <div class="col-4">
                        <a href="schedules.php" class="quick-link">
                            <div class="ql-icon" style="background:rgba(34,197,94,0.12);"><i class="fas fa-clock" style="color:#22c55e;font-size:1.2rem;"></i></div>
                            <span class="ql-label">Schedules</span>
                        </a>
                    </div>
                    <div class="col-4">
                        <a href="destinations.php" class="quick-link">
                            <div class="ql-icon" style="background:rgba(239,68,68,0.12);"><i class="fas fa-map-marker-alt" style="color:#ef4444;font-size:1.2rem;"></i></div>
                            <span class="ql-label">Destinations</span>
                        </a>
                    </div>
                    <div class="col-4">
                        <a href="events.php" class="quick-link">
                            <div class="ql-icon" style="background:rgba(245,158,11,0.12);"><i class="fas fa-calendar" style="color:#f59e0b;font-size:1.2rem;"></i></div>
                            <span class="ql-label">Events</span>
                        </a>
                    </div>
                    <div class="col-4">
                        <a href="guide_availability.php" class="quick-link">
                            <div class="ql-icon" style="background:rgba(6,182,212,0.12);"><i class="fas fa-user-clock" style="color:#06b6d4;font-size:1.2rem;"></i></div>
                            <span class="ql-label">Guide Availability</span>
                        </a>
                    </div>
                    <div class="col-4">
                        <a href="reports.php" class="quick-link">
                            <div class="ql-icon" style="background:rgba(139,92,246,0.12);"><i class="fas fa-chart-bar" style="color:#8b5cf6;font-size:1.2rem;"></i></div>
                            <span class="ql-label">Reports</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php }); ?>
