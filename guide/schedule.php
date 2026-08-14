<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('guide');

require_once __DIR__ . '/../includes/classes/Schedule.php';

$user = current_user();
$guide_id = $user['id'];
$db = Database::getInstance()->getConnection();
$scheduleModel = new Schedule();

$view_mode = $_GET['view'] ?? 'calendar';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

$filters = ['guide_id' => $guide_id];
if ($date_from) $filters['date_from'] = $date_from;
if ($date_to) $filters['date_to'] = $date_to;

$page = (int) ($_GET['page'] ?? 1);
$schedules = $scheduleModel->findAll($filters, $page, 20);

$conflict_check = $db->prepare(
    "SELECT s1.id as id1, s2.id as id2, s1.start_date, s1.end_date, s1.start_time, s1.end_time
     FROM schedules s1
     INNER JOIN schedules s2 ON s1.id != s2.id
        AND s1.guide_id = :gid1 AND s2.guide_id = :gid2
        AND s1.start_date <= s2.end_date AND s1.end_date >= s2.start_date
        AND s1.start_time < s2.end_time AND s1.end_time > s2.start_time
        AND s1.status != 'cancelled' AND s2.status != 'cancelled'
     WHERE s1.start_date BETWEEN :df AND :dt"
);
$conflict_check->execute([':gid1' => $guide_id, ':gid2' => $guide_id, ':df' => $date_from, ':dt' => $date_to]);
$conflicts = $conflict_check->fetchAll();

$selected_date = $_GET['date'] ?? date('Y-m-d');
$day_schedules = $scheduleModel->getGuideSchedule($guide_id, $selected_date);

render_page('guide', 'schedule.php', 'My Schedule', function () use ($schedules, $view_mode, $date_from, $date_to, $conflicts, $selected_date, $day_schedules, $guide_id, $db) {
?>
<style>
.guide-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.guide-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.guide-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.filter-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:16px 20px;margin-bottom:20px;}
.filter-input{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 14px;color:var(--text-primary,#e2e8f0);width:100%;font-size:0.9rem;}
.filter-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.2);}
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
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.btn-reset{background:rgba(255,255,255,0.08);color:var(--text-primary,#e2e8f0);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 20px;font-weight:600;}
.btn-reset:hover{background:rgba(255,255,255,0.12);color:var(--text-primary,#e2e8f0);}
.calendar-day{display:block;text-decoration:none;text-align:center;padding:8px 4px;border-radius:8px;font-size:0.75rem;transition:all 0.2s;border:1px solid transparent;}
.calendar-day:hover{background:rgba(12,110,94,0.08);}
.calendar-day.today{border-color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.08);}
.calendar-day.selected{background:var(--primary,#0c6e5e);color:#fff;}
.calendar-day.has-schedule{background:rgba(34,197,94,0.08);}
.calendar-day .day-num{font-weight:700;font-size:1.1rem;}
.calendar-day .day-name{font-size:0.6rem;opacity:0.7;}
.day-detail-card{border-left:3px solid var(--primary,#0c6e5e);padding:12px 16px;margin-bottom:12px;background:rgba(255,255,255,0.02);border-radius:0 8px 8px 0;}
.day-detail-card:last-child{margin-bottom:0;}
.day-detail-card .event-title{font-weight:600;font-size:0.9rem;color:var(--text-primary,#e2e8f0);margin-bottom:4px;}
.day-detail-card .event-meta{font-size:0.8rem;color:var(--text-muted,#94a3b8);display:flex;align-items:center;gap:4px;margin-bottom:2px;}
.alert-warning-custom{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:12px;margin-bottom:20px;}
.pagination .page-link{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);border-radius:8px;margin:0 2px;font-size:0.85rem;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-link:hover{background:rgba(12,110,94,0.1);color:var(--primary,#0c6e5e);}
</style>

<div class="guide-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-calendar-alt me-2"></i>My Schedule</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">View and manage your tour schedule</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <a href="<?= BASE_URL ?>/guide/schedule.php?view=calendar&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="btn btn-sm <?= $view_mode === 'calendar' ? '' : 'btn-outline-light' ?>" style="<?= $view_mode === 'calendar' ? 'background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);' : '' ?>"><i class="fas fa-calendar me-1"></i>Calendar</a>
            <a href="<?= BASE_URL ?>/guide/schedule.php?view=table&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="btn btn-sm <?= $view_mode === 'table' ? '' : 'btn-outline-light' ?>" style="<?= $view_mode === 'table' ? 'background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);' : '' ?>"><i class="fas fa-list me-1"></i>Table</a>
        </div>
    </div>
</div>

<?php if (!empty($conflicts)): ?>
    <div class="alert-warning-custom">
        <div style="width:36px;height:36px;border-radius:50%;background:rgba(245,158,11,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>
        </div>
        <div>
            <strong style="color:#f59e0b;">Schedule Conflict Detected!</strong>
            <span style="color:var(--text-muted,#94a3b8);font-size:0.85rem;"> You have overlapping schedules. Please review your assignments.</span>
        </div>
    </div>
<?php endif; ?>

<div class="filter-card">
    <form method="GET" class="row g-3 align-items-end">
        <input type="hidden" name="view" value="<?= $view_mode ?>">
        <div class="col-md-4">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">From Date</label>
            <input type="date" class="filter-input" name="date_from" value="<?= sanitize($date_from) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">To Date</label>
            <input type="date" class="filter-input" name="date_to" value="<?= sanitize($date_to) ?>">
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn-brand"><i class="fas fa-filter me-1"></i>Filter</button>
            <a href="<?= BASE_URL ?>/guide/schedule.php" class="btn-reset"><i class="fas fa-redo me-1"></i>Reset</a>
        </div>
    </form>
</div>

<?php if ($view_mode === 'calendar'): ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="section-card">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-calendar" style="color:#3b82f6;font-size:0.7rem;"></i>
                    </div>
                    <h6>Schedule View</h6>
                </div>
                <div style="padding:16px 20px;">
                    <div class="row g-2 mb-3">
                        <?php
                        $start = new DateTime($date_from);
                        $end = new DateTime($date_to);
                        $end->modify('+1 day');
                        $interval = new DateInterval('P1D');
                        $period = new DatePeriod($start, $interval, $end);

                        foreach ($period as $day):
                            $day_str = $day->format('Y-m-d');
                            $day_label = $day->format('D, M d');
                            $day_schedule_check = $db->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE guide_id = :gid AND start_date <= :d1 AND end_date >= :d2 AND status != 'cancelled'");
                            $day_schedule_check->execute([':gid' => $guide_id, ':d1' => $day_str, ':d2' => $day_str]);
                            $has_schedule = (int) $day_schedule_check->fetch()['cnt'] > 0;
                            $is_today = $day_str === date('Y-m-d');
                            $is_selected = $day_str === $selected_date;
                        ?>
                            <div class="col">
                                <a href="<?= BASE_URL ?>/guide/schedule.php?view=calendar&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&date=<?= $day_str ?>"
                                   class="calendar-day <?= $is_selected ? 'selected' : ($is_today ? 'today' : ($has_schedule ? 'has-schedule' : '')) ?>">
                                    <div class="day-num"><?= $day->format('d') ?></div>
                                    <div class="day-name"><?= $day->format('D') ?></div>
                                    <?php if ($has_schedule && !$is_selected): ?>
                                        <div><i class="fas fa-circle" style="font-size:0.3rem;color:#22c55e;margin-top:2px;"></i></div>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="section-card">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.15);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-list" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i>
                    </div>
                    <h6><?= format_date($selected_date) ?> Details</h6>
                </div>
                <div style="padding:16px 20px;">
                    <?php if (empty($day_schedules)): ?>
                        <div class="text-center" style="padding:24px 0;">
                            <div style="width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                                <i class="fas fa-calendar-times" style="font-size:1rem;color:var(--text-muted,#64748b);opacity:0.4;"></i>
                            </div>
                            <p style="color:var(--text-muted,#94a3b8);margin:0;font-size:0.85rem;">No schedules for this day.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($day_schedules as $ds): ?>
                            <div class="day-detail-card">
                                <div class="event-title"><?= sanitize($ds['event_title'] ?? 'N/A') ?></div>
                                <div class="event-meta"><i class="fas fa-map-marker-alt"></i><?= sanitize($ds['destination_name'] ?? 'N/A') ?></div>
                                <div class="event-meta"><i class="fas fa-clock"></i><?= sanitize($ds['start_time'] ?? '') ?> - <?= sanitize($ds['end_time'] ?? '') ?></div>
                                <div class="event-meta"><i class="fas fa-users"></i>Max: <?= $ds['available_spots'] ?? '-' ?> participants</div>
                                <span class="status-chip <?= $ds['status'] ?>" style="margin-top:6px;"><?= ucfirst($ds['status']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="table-card">
        <?php if (empty($schedules['data'])): ?>
            <div class="text-center" style="padding:48px 24px;">
                <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="fas fa-calendar-times" style="font-size:2rem;color:var(--text-muted,#64748b);opacity:0.4;"></i>
                </div>
                <h5 class="fw-bold" style="color:var(--text-primary,#e2e8f0);">No schedules found</h5>
                <p style="color:var(--text-muted,#94a3b8);">No schedules found for the selected period.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Destination</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Time</th>
                            <th>Max</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules['data'] as $sch): ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($sch['event_title'] ?? 'N/A') ?></td>
                            <td><?= sanitize($sch['destination_name'] ?? 'N/A') ?></td>
                            <td><?= format_date($sch['start_date']) ?></td>
                            <td><?= format_date($sch['end_date']) ?></td>
                            <td><?= sanitize($sch['start_time'] ?? '') ?> - <?= sanitize($sch['end_time'] ?? '') ?></td>
                            <td><?= $sch['available_spots'] ?? '-' ?></td>
                            <td><span class="status-chip <?= $sch['status'] ?>"><?= ucfirst($sch['status']) ?></span></td>
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
                                <li class="page-item"><a class="page-link" href="?view=table&page=<?= $schedules['page'] - 1 ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"><i class="fas fa-chevron-left"></i></a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $schedules['pages']; $i++): ?>
                                <li class="page-item <?= $i === $schedules['page'] ? 'active' : '' ?>"><a class="page-link" href="?view=table&page=<?= $i ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                            <?php if ($schedules['page'] < $schedules['pages']): ?>
                                <li class="page-item"><a class="page-link" href="?view=table&page=<?= $schedules['page'] + 1 ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"><i class="fas fa-chevron-right"></i></a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php }); ?>
