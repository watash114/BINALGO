<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');

$schedule = new Schedule();
$event = new Event();
$user = new User();

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);

    if ($action === 'create' && !verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/staff/schedules.php');
    }

    if ($action === 'create') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $guideId = (int)($_POST['guide_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $availableSpots = (int)($_POST['available_spots'] ?? 20);
        $status = $_POST['status'] ?? 'scheduled';

        if ($eventId && $guideId && $startDate && $startTime) {
            if (empty($endDate)) $endDate = $startDate;
            if (empty($endTime)) $endTime = $startTime;

            if ($schedule->hasGuideConflict($guideId, $startDate, $endDate, $startTime, $endTime)) {
                flash_message('error', 'Schedule conflict detected for this guide. Please choose a different time or guide.');
                redirect('/staff/schedules.php');
            }

            $schedule->create([
                'event_id' => $eventId,
                'guide_id' => $guideId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'available_spots' => $availableSpots,
                'status' => $status,
            ]);
            ActivityLog::log((int)($_SESSION['user_id'] ?? 0), 'schedule_created', "Created a new tour schedule for event #{$eventId} guide #{$guideId}");
            flash_message('success', 'Schedule created successfully.');
            redirect('/staff/schedules.php');
        } else {
            flash_message('error', 'Please fill in all required fields.');
            redirect('/staff/schedules.php');
        }
    }

    if ($action === 'cancel' && $scheduleId) {
        $schedule->update($scheduleId, ['status' => 'cancelled']);
        flash_message('success', 'Schedule cancelled successfully.');
        redirect('/staff/schedules.php');
    }

    if ($action === 'update' && $scheduleId) {
        $guideId = (int)($_POST['guide_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $availableSpots = (int)($_POST['available_spots'] ?? 20);
        $status = $_POST['status'] ?? 'scheduled';

        if ($guideId && $startDate && $startTime) {
            $hasConflict = $schedule->hasGuideConflict($guideId, $startDate, $endDate, $startTime, $endTime, $scheduleId);
            if ($hasConflict) {
                flash_message('error', 'Schedule conflict detected for this guide. Please choose a different time or guide.');
                redirect('/staff/schedules.php?action=edit&id=' . $scheduleId);
            }

            $schedule->update($scheduleId, [
                'guide_id' => $guideId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'available_spots' => $availableSpots,
                'status' => $status,
            ]);
            flash_message('success', 'Schedule updated successfully.');
            redirect('/staff/schedules.php');
        } else {
            flash_message('error', 'Please fill in all required fields.');
            redirect('/staff/schedules.php?action=edit&id=' . $scheduleId);
        }
    }
}

$viewAction = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$dateFilter = $_GET['date'] ?? '';
$guideFilter = $_GET['guide_id'] ?? '';
$eventFilter = $_GET['event_id'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = [];
if ($dateFilter) {
    $filters['date_from'] = $dateFilter;
    $filters['date_to'] = $dateFilter;
}
if ($guideFilter) $filters['guide_id'] = (int)$guideFilter;
if ($eventFilter) $filters['event_id'] = (int)$eventFilter;

$schedules = $schedule->findAll($filters, $page, 15);
$allEvents = $event->findAll([], 1, 100)['data'];
$allGuides = $user->findAll(['role' => 'guide'], 1, 100)['data'];

$editSchedule = null;
if ($viewAction === 'edit' && $editId) {
    $editSchedule = $schedule->findById($editId);
}

$today = date('Y-m-d');
$todaySchedules = $schedule->getDailySchedules($today);

$allSchedulesForConflict = [];
$scheduleConflictStmt = Database::getInstance()->getConnection()->prepare(
    "SELECT s.id, s.guide_id, s.start_date, s.end_date, s.start_time, s.end_time, s.status,
            e.title as event_title
     FROM schedules s
     LEFT JOIN events e ON e.id = s.event_id
     WHERE s.status != 'cancelled'
     ORDER BY s.start_date ASC"
);
$scheduleConflictStmt->execute();
$allSchedulesForConflict = $scheduleConflictStmt->fetchAll();

render_page('staff', 'schedules.php', 'Schedule Management', function () use ($schedules, $allEvents, $allGuides, $dateFilter, $guideFilter, $eventFilter, $editSchedule, $todaySchedules, $today, $viewAction, $allSchedulesForConflict) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.dash-stat{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;position:relative;overflow:hidden;}
.dash-stat .accent-bar{position:absolute;top:0;left:0;width:4px;height:100%;border-radius:4px 0 0 4px;}
.dash-stat .stat-value{font-size:1.6rem;font-weight:800;color:var(--text-primary,#1e293b);margin-bottom:2px;}
.dash-stat .stat-label{font-size:0.8rem;color:var(--text-muted,#64748b);font-weight:500;}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;}
.filter-card .form-control,.filter-card .form-select{border-radius:10px;border:1px solid var(--border-color,#e2e8f0);padding:10px 14px;font-size:0.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);}
.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.table-card .table{margin-bottom:0;color:var(--text-primary,#1e293b);}
.table-card .table thead th{background:var(--bg-secondary,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0);font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:0.5px;padding:12px 16px;}
.table-card .table tbody td{padding:12px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);font-size:0.88rem;}
.table-card .table tbody tr:last-child td{border-bottom:none;}
.table-card .table tbody tr:hover{background:rgba(12,110,94,0.02);}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:0.78rem;font-weight:600;}
.status-chip.scheduled{background:rgba(34,197,94,0.12);color:#16a34a;}
.status-chip.in_progress{background:rgba(59,130,246,0.12);color:#2563eb;}
.status-chip.completed{background:rgba(100,116,139,0.12);color:#475569;}
.status-chip.cancelled{background:rgba(239,68,68,0.12);color:#dc2626;}
.action-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;font-size:0.8rem;}
.action-btn:hover{border-color:var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.05);}
.action-btn.danger:hover{border-color:#dc2626;color:#dc2626;background:rgba(220,38,38,0.05);}
.section-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);}
.profile-input{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 14px;color:var(--text-primary,#1e293b);width:100%;font-size:0.9rem;transition:all 0.2s;}
.profile-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.1);}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.conflict-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;border-left:4px solid var(--primary,#0c6e5e);overflow:hidden;}
.pagination .page-item .page-link{border-radius:8px;margin:0 3px;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);font-size:0.85rem;padding:6px 12px;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-item .page-link:hover:not(.active){background:rgba(12,110,94,0.05);color:var(--primary,#0c6e5e);}
</style>

<?php if ($viewAction === 'edit' && $editSchedule): ?>
<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-edit me-2"></i>Edit Schedule #<?= $editSchedule['id'] ?></h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Update schedule details and manage guide assignment</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <a href="schedules.php" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;padding:8px 20px;border:none;"><i class="fas fa-arrow-left me-1"></i>Back to Schedules</a>
        </div>
    </div>
</div>

<div class="section-card">
    <div class="section-header">
        <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.12);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-calendar-edit" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i>
        </div>
        <h6>Schedule Details</h6>
    </div>
    <div style="padding:20px;">
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="schedule_id" value="<?= $editSchedule['id'] ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Guide <span style="color:#ef4444;">*</span></label>
                    <select name="guide_id" class="profile-input" required>
                        <option value="">Select Guide</option>
                        <?php foreach ($allGuides as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= ($editSchedule['guide_id'] ?? '') == $g['id'] ? 'selected' : '' ?>><?= sanitize($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Status</label>
                    <select name="status" class="profile-input">
                        <?php foreach (['scheduled', 'in_progress', 'completed', 'cancelled'] as $st): ?>
                            <option value="<?= $st ?>" <?= ($editSchedule['status'] ?? '') === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Start Date <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="start_date" class="profile-input" value="<?= sanitize($editSchedule['start_date'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">End Date <span style="color:#ef4444;">*</span></label>
                    <input type="date" name="end_date" class="profile-input" value="<?= sanitize($editSchedule['end_date'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Start Time <span style="color:#ef4444;">*</span></label>
                    <input type="time" name="start_time" class="profile-input" value="<?= sanitize($editSchedule['start_time'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">End Time <span style="color:#ef4444;">*</span></label>
                    <input type="time" name="end_time" class="profile-input" value="<?= sanitize($editSchedule['end_time'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Participants</label>
                    <input type="number" name="available_spots" class="profile-input" value="<?= $editSchedule['available_spots'] ?? 20 ?>" min="1">
                </div>
                <div class="col-12 d-flex gap-2 mt-3">
                    <button type="submit" class="btn-brand"><i class="fas fa-save me-1"></i>Update Schedule</button>
                    <button type="button" class="btn btn-sm" style="border:1px solid var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);background:var(--card-bg,#fff);border-radius:10px;padding:10px 24px;" data-drawer-target="conflictDrawer" onclick="BINALGO_CONFLICT.scan('<?= $editSchedule['id'] ?>')"><i class="fas fa-shield-alt me-1"></i>Check Conflicts</button>
                    <a href="schedules.php" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 24px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="drawer-backdrop" id="conflictDrawer-backdrop"></div>
<div class="drawer" id="conflictDrawer" aria-hidden="true">
    <div class="drawer-header">
        <h6><i class="fas fa-shield-alt me-2" style="color:var(--primary,#0c6e5e);"></i>Schedule Conflict Check</h6>
        <button type="button" class="drawer-close-btn" data-drawer-close="conflictDrawer"><i class="fas fa-times"></i></button>
    </div>
    <div class="drawer-body">
        <div id="conflictScanSummary" class="mb-3"></div>
        <div class="conflict-list" id="conflictScanList"></div>
    </div>
    <div class="drawer-footer">
        <button type="button" class="btn-brand" style="flex:1;" data-drawer-close="conflictDrawer">Done</button>
    </div>
</div>

<script>
window.BINALGO_CONFLICT = (function () {
    var all = <?= json_encode(array_map(function ($s) {
        return [
            'id'          => $s['id'],
            'guide_id'    => (int)$s['guide_id'],
            'start_date'  => $s['start_date'],
            'end_date'    => $s['end_date'],
            'start_time'  => $s['start_time'],
            'end_time'    => $s['end_time'],
            'event_title' => $s['event_title'],
        ];
    }, $allSchedulesForConflict)) ?>;

    function toMin(t) {
        if (!t) return 0;
        var p = t.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10);
    }
    function overlap(s1, s2) {
        var s1s = toMin(s1.start_time), s1e = toMin(s1.end_time);
        var s2s = toMin(s2.start_time), s2e = toMin(s2.end_time);
        var dateOverlap = !(s1.end_date < s2.start_date || s2.end_date < s1.start_date);
        var timeOverlap = s1s < s2e && s2s < s1e;
        return dateOverlap && timeOverlap;
    }
    function esc(v) {
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }
    return {
        scan: function (currentId) {
            var guide = document.querySelector('select[name="guide_id"]');
            var sDate = document.querySelector('input[name="start_date"]');
            var eDate = document.querySelector('input[name="end_date"]');
            var sTime = document.querySelector('input[name="start_time"]');
            var eTime = document.querySelector('input[name="end_time"]');
            if (!guide || !sDate || !sTime) return;

            var draft = {
                guide_id: parseInt(guide.value, 10) || 0,
                start_date: sDate.value,
                end_date: eDate.value || sDate.value,
                start_time: sTime.value,
                end_time: eTime.value || sTime.value
            };
            if (!draft.guide_id || !draft.start_date || !draft.start_time) {
                document.getElementById('conflictScanSummary').innerHTML =
                    '<div class="alert alert-warning py-2 mb-0" style="font-size:0.85rem;">Select a guide, start date, and start time to check for conflicts.</div>';
                document.getElementById('conflictScanList').innerHTML = '';
                return;
            }
            var hits = all.filter(function (s) {
                return s.guide_id === draft.guide_id && s.id != currentId && overlap(draft, s);
            });
            var summary = document.getElementById('conflictScanSummary');
            var list = document.getElementById('conflictScanList');
            if (hits.length === 0) {
                summary.innerHTML =
                    '<div class="alert alert-success py-2 mb-0" style="font-size:0.85rem;"><i class="fas fa-check-circle me-1"></i>No conflicts detected for this guide and time range.</div>';
                list.innerHTML =
                    '<div class="conflict-item conflict-clear"><div class="conflict-icon"><i class="fas fa-check"></i></div><div><div class="conflict-title">All clear</div><div class="conflict-meta">This schedule does not overlap with any other schedule.</div></div></div>';
                return;
            }
            summary.innerHTML =
                '<div class="alert alert-danger py-2 mb-0" style="font-size:0.85rem;"><i class="fas fa-exclamation-triangle me-1"></i>' + hits.length + ' overlapping schedule(s) found for this guide.</div>';
            list.innerHTML = hits.map(function (s) {
                return '<div class="conflict-item"><div class="conflict-icon"><i class="fas fa-calendar-times"></i></div>' +
                    '<div><div class="conflict-title">' + esc(s.event_title || 'Schedule #' + s.id) + '</div>' +
                    '<div class="conflict-meta">' + esc(s.start_date) + ' ' + esc(s.start_time) + ' - ' + esc(s.end_time) + '</div></div></div>';
            }).join('');
        }
    };
})();
</script>

<?php else: ?>
<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-calendar-alt me-2"></i>Schedule Management</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Manage tour schedules, assign guides, and track conflicts</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <div class="hero-actions">
                <button type="button" class="btn-hero solid" data-drawer-target="createDrawer" data-drawer-reset="1">
                    <i class="fas fa-plus me-1"></i>Create Schedule
                </button>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info d-flex align-items-start gap-2 mb-4" role="alert" style="border-radius:14px;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);">
    <i class="fas fa-shield-alt mt-1" style="color:var(--primary,#0c6e5e);"></i>
    <div>
        <strong style="font-size:0.85rem;">Conflict Detection</strong>
        <p class="mb-0 small text-muted">Schedule conflicts are automatically detected when editing or creating. A warning will appear if a guide is already assigned to overlapping schedules.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="section-card">
            <div class="section-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-calendar-day" style="color:#3b82f6;font-size:0.7rem;"></i>
                </div>
                <h6>Today's Schedules (<?= $today ?>)</h6>
            </div>
            <?php if (empty($todaySchedules)): ?>
                <div class="empty-state">
                    <div class="empty-illustration">
                        <i class="fas fa-calendar-times"></i>
                        <span class="empty-ring"></span>
                    </div>
                    <div class="empty-title">No schedules for today</div>
                    <p class="empty-text">No tour schedules are assigned for today. Create one to get started.</p>
                    <div class="empty-actions">
                        <button type="button" class="btn-cta" data-drawer-target="createDrawer" data-drawer-reset="1"><i class="fas fa-plus me-1"></i>Create First Schedule</button>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Guide</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todaySchedules as $s): ?>
                            <tr>
                                <td class="fw-semibold" style="font-size:0.88rem;"><?= sanitize($s['event_title'] ?? 'N/A') ?></td>
                                <td><?= sanitize($s['guide_name'] ?? 'Unassigned') ?></td>
                                <td><i class="fas fa-clock me-1 text-muted" style="font-size:0.8rem;"></i><?= sanitize($s['start_time'] ?? '') ?> - <?= sanitize($s['end_time'] ?? '') ?></td>
                                <td><span class="status-chip <?= match($s['status'] ?? '') { 'scheduled' => 'scheduled', 'in_progress' => 'in_progress', 'completed' => 'completed', 'cancelled' => 'cancelled', default => 'completed' } ?>"><?= ucfirst($s['status'] ?? '') ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="filter-card mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Date</label>
            <input type="date" name="date" class="form-control" value="<?= sanitize($dateFilter) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Guide</label>
            <select name="guide_id" class="form-select">
                <option value="">All Guides</option>
                <?php foreach ($allGuides as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $guideFilter == $g['id'] ? 'selected' : '' ?>><?= sanitize($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Event</label>
            <select name="event_id" class="form-select">
                <option value="">All Events</option>
                <?php foreach ($allEvents as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $eventFilter == $e['id'] ? 'selected' : '' ?>><?= sanitize($e['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm" style="background:var(--primary,#0c6e5e);color:#fff;border-radius:8px;padding:8px 16px;"><i class="fas fa-search me-1"></i>Filter</button>
            <a href="schedules.php" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:8px;padding:8px 16px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);">Reset</a>
        </div>
    </form>
</div>

<div class="table-card">
    <?php if (empty($schedules['data'])): ?>
        <div class="empty-state">
            <div class="empty-illustration">
                <i class="fas fa-calendar-times"></i>
                <span class="empty-ring"></span>
            </div>
            <div class="empty-title">No Schedules Found</div>
            <p class="empty-text">No schedules match your current filters. Adjust your filters or create a new schedule.</p>
            <div class="empty-actions">
                <button type="button" class="btn-cta" data-drawer-target="createDrawer" data-drawer-reset="1"><i class="fas fa-plus me-1"></i>Create Schedule</button>
                <a href="schedules.php" class="btn-cta ghost"><i class="fas fa-redo me-1"></i>Reset Filters</a>
            </div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Event</th>
                        <th>Guide</th>
                        <th>Destination</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Participants</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedules['data'] as $s): ?>
                    <tr>
                        <td class="text-muted">#<?= $s['id'] ?></td>
                        <td class="fw-semibold" style="font-size:0.88rem;"><?= sanitize($s['event_title'] ?? 'N/A') ?></td>
                        <td><?= sanitize($s['guide_name'] ?? 'Unassigned') ?></td>
                        <td><?= sanitize($s['destination_name'] ?? 'N/A') ?></td>
                        <td>
                            <div style="font-size:0.88rem;"><?= format_date($s['start_date'] ?? '') ?></div>
                            <div class="text-muted small"><?= sanitize($s['start_time'] ?? '') ?></div>
                        </td>
                        <td>
                            <div style="font-size:0.88rem;"><?= format_date($s['end_date'] ?? '') ?></div>
                            <div class="text-muted small"><?= sanitize($s['end_time'] ?? '') ?></div>
                        </td>
                        <td><?= $s['available_spots'] ?? 20 ?></td>
                        <td><span class="status-chip <?= match($s['status'] ?? '') { 'scheduled' => 'scheduled', 'in_progress' => 'in_progress', 'completed' => 'completed', 'cancelled' => 'cancelled', default => 'completed' } ?>"><?= ucfirst(str_replace('_', ' ', $s['status'] ?? '')) ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="schedules.php?action=edit&id=<?= $s['id'] ?>" class="action-btn" title="Edit"><i class="fas fa-edit"></i></a>
                                <?php if ($s['status'] !== 'cancelled'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="schedule_id" value="<?= $s['id'] ?>">
                                        <button class="action-btn danger" title="Cancel" onclick="return confirm('Cancel this schedule?')"><i class="fas fa-times"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($schedules['pages'] > 1): ?>
<div class="d-flex justify-content-center mt-4">
    <nav>
        <ul class="pagination mb-0">
            <?php if ($schedules['page'] > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $schedules['page'] - 1 ?>&date=<?= urlencode($dateFilter) ?>&guide_id=<?= urlencode($guideFilter) ?>&event_id=<?= urlencode($eventFilter) ?>"><i class="fas fa-chevron-left"></i></a></li>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $schedules['pages']; $i++): ?>
                <li class="page-item <?= $i == $schedules['page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&date=<?= urlencode($dateFilter) ?>&guide_id=<?= urlencode($guideFilter) ?>&event_id=<?= urlencode($eventFilter) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <?php if ($schedules['page'] < $schedules['pages']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $schedules['page'] + 1 ?>&date=<?= urlencode($dateFilter) ?>&guide_id=<?= urlencode($guideFilter) ?>&event_id=<?= urlencode($eventFilter) ?>"><i class="fas fa-chevron-right"></i></a></li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

<div class="drawer-backdrop" id="createDrawer-backdrop"></div>
<div class="drawer" id="createDrawer" aria-hidden="true">
    <div class="drawer-header">
        <h6><i class="fas fa-calendar-plus me-2" style="color:var(--primary,#0c6e5e);"></i>Create Tour Schedule</h6>
        <button type="button" class="drawer-close-btn" data-drawer-close="createDrawer"><i class="fas fa-times"></i></button>
    </div>
    <div class="drawer-body">
        <div class="drawer-summary">
            <div class="ds-item">
                <div class="ds-label">Selected Event</div>
                <div class="ds-value" id="createDrawerEventName">—</div>
            </div>
            <div class="ds-item">
                <div class="ds-label">Assigned Guide</div>
                <div class="ds-value" id="createDrawerGuideName">—</div>
            </div>
        </div>

        <div id="createConflictSummary" class="mb-3"></div>
        <div class="conflict-list" id="createConflictList"></div>

        <form method="POST" action="schedules.php" id="createScheduleForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-field">
                <label for="create_event_id">Event / Tour <span style="color:#ef4444;">*</span></label>
                <select name="event_id" id="create_event_id" class="field-input" required>
                    <option value="">Select an event</option>
                    <?php foreach ($allEvents as $e): ?>
                        <option value="<?= $e['id'] ?>" data-dest="<?= sanitize($e['destination_name'] ?? '') ?>"><?= sanitize($e['title']) ?><?= !empty($e['destination_name']) ? ' — ' . sanitize($e['destination_name']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="create_guide_id">Assign Guide <span style="color:#ef4444;">*</span></label>
                <select name="guide_id" id="create_guide_id" class="field-input" required>
                    <option value="">Select a guide</option>
                    <?php foreach ($allGuides as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= sanitize($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="form-field">
                        <label for="create_start_date">Start Date <span style="color:#ef4444;">*</span></label>
                        <input type="date" name="start_date" id="create_start_date" class="field-input" required>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-field">
                        <label for="create_end_date">End Date</label>
                        <input type="date" name="end_date" id="create_end_date" class="field-input">
                    </div>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="form-field">
                        <label for="create_start_time">Start Time <span style="color:#ef4444;">*</span></label>
                        <input type="time" name="start_time" id="create_start_time" class="field-input" required>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-field">
                        <label for="create_end_time">End Time</label>
                        <input type="time" name="end_time" id="create_end_time" class="field-input">
                    </div>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="form-field">
                        <label for="create_available_spots">Max Participants</label>
                        <input type="number" name="available_spots" id="create_available_spots" class="field-input" value="20" min="1">
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-field">
                        <label for="create_status">Status</label>
                        <select name="status" id="create_status" class="field-input">
                            <option value="scheduled">Scheduled</option>
                            <option value="in_progress">In Progress</option>
                        </select>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="drawer-footer">
        <button type="button" class="btn-cta ghost" style="flex:1;" data-drawer-close="createDrawer">Cancel</button>
        <button type="submit" form="createScheduleForm" class="btn-cta" id="createScheduleSubmit" style="flex:1;"><i class="fas fa-plus me-1"></i>Create Schedule</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var drawer = document.getElementById('createDrawer');
    if (!drawer) return;

    var eventSel = document.getElementById('create_event_id');
    var guideSel = document.getElementById('create_guide_id');
    var sDate = document.getElementById('create_start_date');
    var eDate = document.getElementById('create_end_date');
    var sTime = document.getElementById('create_start_time');
    var eTime = document.getElementById('create_end_time');
    var summary = document.getElementById('createConflictSummary');
    var list = document.getElementById('createConflictList');

    var all = <?= json_encode(array_map(function ($s) {
        return [
            'id'          => $s['id'],
            'guide_id'    => (int)$s['guide_id'],
            'start_date'  => $s['start_date'],
            'end_date'    => $s['end_date'],
            'start_time'  => $s['start_time'],
            'end_time'    => $s['end_time'],
            'event_title' => $s['event_title'],
        ];
    }, $allSchedulesForConflict)) ?>;

    function toMin(t) {
        if (!t) return 0;
        var p = t.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10);
    }
    function overlap(s1, s2) {
        var s1s = toMin(s1.start_time), s1e = toMin(s1.end_time);
        var s2s = toMin(s2.start_time), s2e = toMin(s2.end_time);
        var dateOverlap = !(s1.end_date < s2.start_date || s2.end_date < s1.start_date);
        var timeOverlap = s1s < s2e && s2s < s1e;
        return dateOverlap && timeOverlap;
    }
    function esc(v) {
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }
    function scan() {
        var guideId = parseInt(guideSel.value, 10) || 0;
        var startDate = sDate.value;
        var endDate = eDate.value || startDate;
        var startTime = sTime.value;
        var endTime = eTime.value || startTime;

        if (!guideId || !startDate || !startTime) {
            summary.innerHTML = '';
            list.innerHTML = '';
            return;
        }
        var draft = { guide_id: guideId, start_date: startDate, end_date: endDate, start_time: startTime, end_time: endTime };
        var hits = all.filter(function (s) {
            return s.guide_id === draft.guide_id && overlap(draft, s);
        });
        if (hits.length === 0) {
            summary.innerHTML = '<div class="alert alert-success py-2 mb-0" style="font-size:0.85rem;"><i class="fas fa-check-circle me-1"></i>No conflicts detected for this guide and time range.</div>';
            list.innerHTML = '<div class="conflict-item conflict-clear"><div class="conflict-icon"><i class="fas fa-check"></i></div><div><div class="conflict-title">All clear</div><div class="conflict-meta">This schedule does not overlap with any other schedule.</div></div></div>';
            return;
        }
        summary.innerHTML = '<div class="alert alert-danger py-2 mb-0" style="font-size:0.85rem;"><i class="fas fa-exclamation-triangle me-1"></i>' + hits.length + ' overlapping schedule(s) found for this guide.</div>';
        list.innerHTML = hits.map(function (s) {
            return '<div class="conflict-item"><div class="conflict-icon"><i class="fas fa-calendar-times"></i></div>' +
                '<div><div class="conflict-title">' + esc(s.event_title || 'Schedule #' + s.id) + '</div>' +
                '<div class="conflict-meta">' + esc(s.start_date) + ' ' + esc(s.start_time) + ' - ' + esc(s.end_time) + '</div></div></div>';
        }).join('');
    }

    [guideSel, sDate, eDate, sTime, eTime].forEach(function (el) {
        if (el) el.addEventListener('change', scan);
    });

    function updateSummary() {
        var evName = '—', gName = '—';
        if (eventSel && eventSel.selectedIndex > 0) evName = eventSel.options[eventSel.selectedIndex].text.split(' — ')[0];
        if (guideSel && guideSel.selectedIndex > 0) gName = guideSel.options[guideSel.selectedIndex].text;
        document.getElementById('createDrawerEventName').textContent = evName;
        document.getElementById('createDrawerGuideName').textContent = gName;
    }
    eventSel.addEventListener('change', updateSummary);
    guideSel.addEventListener('change', function () { updateSummary(); scan(); });

    var openBtn = document.querySelector('[data-drawer-target="createDrawer"]');
    if (openBtn && openBtn.hasAttribute('data-drawer-reset')) {
        openBtn.addEventListener('click', function () {
            var form = document.getElementById('createScheduleForm');
            if (form) form.reset();
            if (sDate && !sDate.value) sDate.value = new Date().toISOString().split('T')[0];
            summary.innerHTML = '';
            list.innerHTML = '';
            updateSummary();
        });
    }
});
</script>
<?php endif; ?>
<?php }); ?>
