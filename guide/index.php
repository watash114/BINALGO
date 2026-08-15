<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('guide');

require_once __DIR__ . '/../includes/classes/Schedule.php';
require_once __DIR__ . '/../includes/classes/Booking.php';
require_once __DIR__ . '/../includes/classes/Feedback.php';
require_once __DIR__ . '/../includes/classes/Message.php';
require_once __DIR__ . '/../includes/classes/Event.php';

$user = current_user();
$db = Database::getInstance()->getConnection();
$guide_id = $user['id'];

$scheduleModel = new Schedule();
$bookingModel = new Booking();
$feedbackModel = new Feedback();
$messageModel = new Message();

$upcoming_schedules = $scheduleModel->findAll(['guide_id' => $guide_id, 'status' => 'scheduled'], 1, 5);

$completed_count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE guide_id = :gid AND status = 'completed'");
$completed_count_stmt->execute([':gid' => $guide_id]);
$completed_tours = (int) $completed_count_stmt->fetch()['cnt'];

$upcoming_count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE guide_id = :gid AND status = 'scheduled' AND start_date >= db_curdate()");
$upcoming_count_stmt->execute([':gid' => $guide_id]);
$upcoming_count = (int) $upcoming_count_stmt->fetch()['cnt'];

$feedback_stats = $feedbackModel->getStats($guide_id);
$avg_rating = round((float) ($feedback_stats['average_rating'] ?? 0), 1);
$total_feedback = (int) ($feedback_stats['total_feedbacks'] ?? 0);

$unread_messages = $messageModel->getUnreadCount($guide_id);

$gp_stmt = $db->prepare("SELECT availability_status FROM guide_profiles WHERE user_id = :uid LIMIT 1");
$gp_stmt->execute([':uid' => $guide_id]);
$availability = ($gp_stmt->fetch()['availability_status'] ?? 'available');
$availability_badges = [
    'available' => 'bg-success',
    'on_tour'   => 'bg-primary',
    'off_duty'  => 'bg-secondary',
    'on_leave'  => 'bg-warning text-dark',
];

$recent_feedback = $feedbackModel->findByGuide($guide_id);
$recent_feedback = array_slice($recent_feedback, 0, 5);

render_page('guide', 'index.php', 'Guide Dashboard', function () use ($user, $upcoming_schedules, $completed_tours, $upcoming_count, $avg_rating, $total_feedback, $unread_messages, $availability, $availability_badges, $recent_feedback) {
?>
<style>
.guide-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.guide-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.guide-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.guide-stat{background:var(--card-bg,#1a1f2e);border-radius:14px;padding:20px;border:1px solid var(--border-color,#2a3042);transition:all 0.25s;}
.guide-stat:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2);}
.guide-stat .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.guide-stat .stat-value{font-size:1.6rem;font-weight:800;line-height:1;}
.guide-stat .stat-label{font-size:0.78rem;color:var(--text-muted,#94a3b8);margin-top:4px;font-weight:500;}
.section-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;justify-content:space-between;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#e2e8f0);}
.quick-action-btn{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;border:1px solid var(--border-color,#2a3042);background:var(--card-bg,#1a1f2e);color:var(--text-primary,#e2e8f0);text-decoration:none;transition:all 0.2s;font-weight:600;font-size:0.9rem;}
.quick-action-btn:hover{background:rgba(12,110,94,0.15);border-color:var(--primary,#0c6e5e);color:var(--text-primary,#e2e8f0);text-decoration:none;}
.quick-action-btn .action-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.feedback-item{padding:12px 0;border-bottom:1px solid var(--border-color,#2a3042);}
.feedback-item:last-child{border-bottom:none;}
.availability-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:50px;font-size:0.82rem;font-weight:600;}
.availability-badge.available{background:#d1fae5;color:#065f46;}
.availability-badge.on_tour{background:#dbeafe;color:#1e40af;}
.availability-badge.off_duty{background:#e2e8f0;color:#475569;}
.availability-badge.on_leave{background:#fef3c7;color:#92400e;}
.table-card{background:var(--card-bg,#1a1f2e);border-radius:14px;border:1px solid var(--border-color,#2a3042);overflow:hidden;}
.table-card .table{margin-bottom:0;}
.table-card .table thead th{background:rgba(255,255,255,0.03);border-bottom:1px solid var(--border-color,#2a3042);font-size:0.78rem;font-weight:700;color:var(--text-muted,#94a3b8);text-transform:uppercase;letter-spacing:0.5px;padding:12px 16px;}
.table-card .table td{padding:14px 16px;vertical-align:middle;border-color:var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);}
.table-card .table tbody tr{transition:background 0.15s;}
.table-card .table tbody tr:hover{background:rgba(255,255,255,0.03);}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:0.75rem;font-weight:600;}
.status-chip.scheduled{background:#dbeafe;color:#1e40af;}
.status-chip.in_progress{background:#fef3c7;color:#92400e;}
.status-chip.completed{background:#d1fae5;color:#065f46;}
.status-chip.cancelled{background:#fee2e2;color:#991b1b;}
</style>

<div class="guide-hero">
    <div class="position-relative" style="z-index:1;">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h3 class="fw-bold mb-1">Welcome back, <?= sanitize($user['name']) ?>!</h3>
                <p class="mb-0 opacity-75" style="font-size:0.9rem;">Here's an overview of your guide activities</p>
            </div>
            <div class="availability-badge <?= $availability ?>">
                <i class="fas fa-circle" style="font-size:0.5rem;"></i>
                <?= ucfirst(str_replace('_', ' ', $availability)) ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="guide-stat">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(59,130,246,0.15);">
                    <i class="fas fa-calendar-check" style="color:#3b82f6;"></i>
                </div>
                <div>
                    <div class="stat-value" style="color:#3b82f6;"><?= $upcoming_count ?></div>
                    <div class="stat-label">Upcoming Tours</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="guide-stat">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(16,185,129,0.15);">
                    <i class="fas fa-check-circle" style="color:#10b981;"></i>
                </div>
                <div>
                    <div class="stat-value" style="color:#10b981;"><?= $completed_tours ?></div>
                    <div class="stat-label">Completed Tours</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="guide-stat">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(245,158,11,0.15);">
                    <i class="fas fa-star" style="color:#f59e0b;"></i>
                </div>
                <div>
                    <div class="stat-value" style="color:#f59e0b;"><?= $avg_rating > 0 ? $avg_rating . '/5' : 'N/A' ?></div>
                    <div class="stat-label">Average Rating</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="guide-stat">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon" style="background:rgba(139,92,246,0.15);">
                    <i class="fas fa-comments" style="color:#8b5cf6;"></i>
                </div>
                <div>
                    <div class="stat-value" style="color:#8b5cf6;"><?= $total_feedback ?></div>
                    <div class="stat-label">Total Feedback</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="section-card">
            <div class="section-header">
                <h6><i class="fas fa-calendar-alt me-2" style="color:#3b82f6;"></i>Upcoming Assigned Tours</h6>
                <a href="<?= BASE_URL ?>/guide/tours.php" class="btn btn-sm" style="background:rgba(12,110,94,0.15);color:#0c6e5e;border-radius:8px;font-weight:600;">View All</a>
            </div>
            <?php if (empty($upcoming_schedules['data'])): ?>
                <div class="text-center py-5">
                    <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                        <i class="fas fa-calendar-times" style="font-size:1.5rem;color:var(--text-muted,#64748b);opacity:0.5;"></i>
                    </div>
                    <p class="mb-0" style="color:var(--text-muted,#94a3b8);">No upcoming tours assigned.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Destination</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th class="text-center">Spots</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_schedules['data'] as $schedule): ?>
                                <tr>
                                    <td class="fw-semibold"><?= sanitize($schedule['event_title'] ?? 'N/A') ?></td>
                                    <td style="color:var(--text-muted,#94a3b8);"><?= sanitize($schedule['destination_name'] ?? 'N/A') ?></td>
                                    <td><?= format_date($schedule['start_date']) ?></td>
                                    <td style="color:var(--text-muted,#94a3b8);"><?= sanitize($schedule['start_time'] ?? '') ?> - <?= sanitize($schedule['end_time'] ?? '') ?></td>
                                    <td class="text-center"><span style="background:rgba(255,255,255,0.08);padding:4px 10px;border-radius:6px;font-size:0.82rem;font-weight:600;"><?= $schedule['available_spots'] ?? '-' ?></span></td>
                                    <td><span class="status-chip scheduled"><?= ucfirst($schedule['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="section-card mb-3">
            <div class="section-header">
                <h6><i class="fas fa-bolt me-2" style="color:#f59e0b;"></i>Quick Actions</h6>
            </div>
            <div style="padding:16px;">
                <div class="d-flex flex-column gap-2">
                    <a href="<?= BASE_URL ?>/guide/schedule.php" class="quick-action-btn">
                        <div class="action-icon" style="background:rgba(59,130,246,0.15);"><i class="fas fa-calendar" style="color:#3b82f6;"></i></div>
                        View Schedule
                    </a>
                    <a href="<?= BASE_URL ?>/guide/profile.php" class="quick-action-btn">
                        <div class="action-icon" style="background:rgba(16,185,129,0.15);"><i class="fas fa-user-edit" style="color:#10b981;"></i></div>
                        Update Profile
                    </a>
                    <a href="<?= BASE_URL ?>/guide/messages.php" class="quick-action-btn">
                        <div class="action-icon" style="background:rgba(139,92,246,0.15);"><i class="fas fa-envelope" style="color:#8b5cf6;"></i></div>
                        Messages
                        <?php if ($unread_messages > 0): ?>
                            <span class="badge bg-danger ms-auto"><?= $unread_messages ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>

        <div class="section-card">
            <div class="section-header">
                <h6><i class="fas fa-star me-2" style="color:#f59e0b;"></i>Recent Feedback</h6>
            </div>
            <div style="padding:16px;">
                <?php if (empty($recent_feedback)): ?>
                    <p class="text-center mb-0" style="color:var(--text-muted,#94a3b8);font-size:0.9rem;">No feedback yet.</p>
                <?php else: ?>
                    <?php foreach ($recent_feedback as $fb): ?>
                        <div class="feedback-item">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <div class="text-warning">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star" style="font-size:0.65rem;<?= $i <= $fb['rating'] ? '' : 'color:var(--text-muted,#4a5568);' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="small fw-semibold" style="color:var(--text-primary,#e2e8f0);"><?= sanitize($fb['tourist_name'] ?? 'Anonymous') ?></span>
                            </div>
                            <?php if (!empty($fb['comment'])): ?>
                                <div class="small" style="color:var(--text-muted,#94a3b8);"><?= sanitize(truncate($fb['comment'], 80)) ?></div>
                            <?php endif; ?>
                            <div style="font-size:0.7rem;color:var(--text-muted,#64748b);"><?= time_ago($fb['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php }); ?>
