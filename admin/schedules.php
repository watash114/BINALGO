<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');
require_once __DIR__ . '/../includes/classes/Schedule.php';

$db = Database::getInstance()->getConnection();
$scheduleModel = new Schedule();

$eventFilter = $_GET['event'] ?? '';
$guideFilter = $_GET['guide'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();

$events = $db->query("SELECT id, title FROM events ORDER BY title ASC")->fetchAll();
$guides = $db->query("SELECT id, name FROM users WHERE role = 'guide' AND status = 'active' ORDER BY name ASC")->fetchAll();
$stats = $db->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status='scheduled' THEN 1 ELSE 0 END) as scheduled,
    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM schedules")->fetch();

// ── AJAX data endpoint (GET ?ajax=1) ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 15;
    $qSearch = trim($_GET['search'] ?? '');
    $qEvent = $_GET['event'] ?? '';
    $qGuide = $_GET['guide'] ?? '';
    $qStatus = $_GET['status'] ?? '';
    $qFrom = $_GET['date_from'] ?? '';
    $qTo = $_GET['date_to'] ?? '';
    $qSort = $_GET['sort'] ?? 'start';
    $qDir = (($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

    $sortMap = [
        'id' => 's.id',
        'event' => 'e.title',
        'guide' => 'u.name',
        'start' => 's.start_date, s.start_time',
        'end' => 's.end_date, s.end_time',
        'status' => 's.status',
    ];
    $orderBy = ($sortMap[$qSort] ?? 's.start_date') . ' ' . $qDir . ', s.id DESC';

    $where = [];
    $params = [];
    if ($qSearch !== '') {
        $where[] = '(e.title LIKE :q1 OR u.name LIKE :q2 OR d.name LIKE :q3)';
        $params[':q1'] = "%{$qSearch}%"; $params[':q2'] = "%{$qSearch}%"; $params[':q3'] = "%{$qSearch}%";
    }
    if ($qEvent !== '') { $where[] = 's.event_id = :event'; $params[':event'] = (int)$qEvent; }
    if ($qGuide !== '') { $where[] = 's.guide_id = :guide'; $params[':guide'] = (int)$qGuide; }
    if ($qStatus !== '') { $where[] = 's.status = :status'; $params[':status'] = $qStatus; }
    if ($qFrom !== '') { $where[] = 's.start_date >= :from'; $params[':from'] = $qFrom; }
    if ($qTo !== '') { $where[] = 's.end_date <= :to'; $params[':to'] = $qTo; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $base = 'FROM schedules s
        LEFT JOIN events e ON s.event_id = e.id
        LEFT JOIN destinations d ON e.destination_id = d.id
        LEFT JOIN users u ON s.guide_id = u.id';

    $countStmt = $db->prepare("SELECT COUNT(*) as c {$base} {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) { $qPage = $pages; }
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare("SELECT s.id, s.event_id, s.guide_id, s.start_date, s.end_date, s.start_time, s.end_time,
        s.available_spots, s.status, e.title as event_title, d.name as destination_name, u.name as guide_name
        {$base} {$whereClause} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'rows'      => $rows,
        'total'     => $total,
        'pages'     => $pages,
        'page'      => $qPage,
        'per_page'  => $perPage,
        'stats'     => [
            'total'          => (int)($stats['total'] ?? 0),
            'scheduled'      => (int)($stats['scheduled'] ?? 0),
            'in_progress'    => (int)($stats['in_progress'] ?? 0),
            'completed'      => (int)($stats['completed'] ?? 0),
            'cancelled'      => (int)($stats['cancelled'] ?? 0),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || (($_POST['ajax'] ?? '') === '1');
    $sendJson = function (array $payload): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };
    $respond = function (bool $ok, string $message) use ($isAjax, $sendJson) {
        if ($isAjax) $sendJson(['ok' => $ok, 'message' => $message]);
        $ok ? flash_message('success', $message) : flash_message('error', $message);
        redirect('/admin/schedules.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';
    $sid = (int)($_POST['schedule_id'] ?? 0);

    if ($action === 'add_schedule' || $action === 'edit_schedule') {
        $guideId = (int)($_POST['guide_id'] ?? 0);
        $eventId = (int)($_POST['event_id'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $availableSpots = (int)($_POST['available_spots'] ?? 0);
        $excludeId = $action === 'edit_schedule' ? $sid : null;

        if (!$eventId || !$guideId || !$startDate || !$endDate || !$startTime || !$endTime) {
            $respond(false, 'Please fill in all required fields.');
        }
        if ($scheduleModel->hasGuideConflict($guideId, $startDate, $endDate, $startTime, $endTime, $excludeId)) {
            $respond(false, 'Guide is already assigned to another tour during this time.');
        }
        if ($scheduleModel->hasEventConflict($eventId, $startDate, $startTime, $excludeId)) {
            $respond(false, 'This event already has a schedule on the same date.');
        }

        if ($action === 'add_schedule') {
            $db->prepare("INSERT INTO schedules (event_id, guide_id, start_date, end_date, start_time, end_time, available_spots, status, created_at)
                VALUES (:e,:g,:sd,:ed,:st,:et,:asp,'scheduled',datetime('now'))")->execute([
                ':e' => $eventId, ':g' => $guideId, ':sd' => $startDate, ':ed' => $endDate,
                ':st' => $startTime, ':et' => $endTime, ':asp' => $availableSpots,
            ]);
            $newId = (int)$db->lastInsertId();
            ActivityLog::log($_SESSION['user_id'], 'schedule_add', 'Added schedule #' . $newId);
            $respond(true, 'Schedule created.');
        } else {
            $db->prepare("UPDATE schedules SET event_id=:e, guide_id=:g, start_date=:sd, end_date=:ed,
                start_time=:st, end_time=:et, available_spots=:asp WHERE id=:id")->execute([
                ':id' => $sid, ':e' => $eventId, ':g' => $guideId, ':sd' => $startDate, ':ed' => $endDate,
                ':st' => $startTime, ':et' => $endTime, ':asp' => $availableSpots,
            ]);
            ActivityLog::log($_SESSION['user_id'], 'schedule_edit', 'Edited schedule #' . $sid);
            $respond(true, 'Schedule updated.');
        }
    }

    if ($action === 'start_schedule' && $sid) {
        $db->prepare("UPDATE schedules SET status = 'in_progress' WHERE id = :id AND status = 'scheduled'")->execute([':id' => $sid]);
        ActivityLog::log($_SESSION['user_id'], 'schedule_start', 'Started schedule #' . $sid);
        $respond(true, 'Schedule started.');
    }

    if ($action === 'complete_schedule' && $sid) {
        $db->prepare("UPDATE schedules SET status = 'completed' WHERE id = :id AND status = 'in_progress'")->execute([':id' => $sid]);
        ActivityLog::log($_SESSION['user_id'], 'schedule_complete', 'Completed schedule #' . $sid);
        $respond(true, 'Schedule completed.');
    }

    if ($action === 'cancel_schedule' && $sid) {
        $db->prepare("UPDATE schedules SET status = 'cancelled' WHERE id = :id AND status IN ('scheduled','in_progress')")->execute([':id' => $sid]);
        ActivityLog::log($_SESSION['user_id'], 'schedule_cancel', 'Cancelled schedule #' . $sid);
        $respond(true, 'Schedule cancelled.');
    }

    if ($action === 'delete_schedule' && $sid) {
        $deps = (int)$db->query("SELECT (SELECT COUNT(*) FROM bookings WHERE schedule_id=$sid) + (SELECT COUNT(*) FROM feedback WHERE schedule_id=$sid)")->fetchColumn();
        if ($deps > 0) {
            $respond(false, 'Cannot delete: this schedule has ' . $deps . ' linked booking(s)/feedback record(s).');
        }
        $db->prepare("DELETE FROM schedules WHERE id = :id")->execute([':id' => $sid]);
        ActivityLog::log($_SESSION['user_id'], 'schedule_delete', 'Deleted schedule #' . $sid);
        $respond(true, 'Schedule deleted.');
    }

    $respond(false, 'Unknown action.');
}

render_page('admin', 'schedules.php', 'Schedule Management', function () use ($eventFilter, $guideFilter, $statusFilter, $dateFrom, $dateTo, $search, $stats, $events, $guides, $csrf, $db) {
?>

<style>
.page-hero{background:linear-gradient(135deg,rgba(12,110,94,.9) 0%,rgba(6,95,70,.95) 100%);color:#fff;border-radius:20px;padding:32px 36px;margin-bottom:1.5rem;position:relative;overflow:hidden}.page-hero::before{content:'';position:absolute;top:-50%;right:-15%;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.page-hero h4{font-weight:800;margin-bottom:4px;position:relative;z-index:1}.page-hero p{opacity:.85;font-size:.9rem;position:relative;z-index:1;margin-bottom:0}
.stat-card{border:none;border-radius:16px;overflow:hidden;transition:all .3s;background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9)}.stat-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.stat-card .stat-bar{height:4px;width:100%}.stat-card .stat-body{padding:18px 16px;text-align:center}.stat-card .stat-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}.stat-card .stat-value{font-size:1.6rem;font-weight:800;line-height:1;margin-bottom:4px}.stat-card .stat-label{font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px;margin-bottom:1rem}.filter-card .form-control,.filter-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}.filter-card .form-label{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.sticky-filter{position:sticky;top:74px;z-index:1015;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.filter-input-wrap{position:relative}.filter-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted,#94a3b8);font-size:.82rem;pointer-events:none}.filter-input{padding-left:34px}
.filter-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(12,110,94,.08);border:1px solid rgba(12,110,94,.25);color:#0c6e5e;font-size:.78rem;font-weight:600;padding:4px 10px;border-radius:50px}[data-theme="dark"] .filter-chip{background:rgba(16,185,129,.12);color:#5eead4;border-color:rgba(16,185,129,.3)}.filter-chip .chip-x{border:none;background:none;color:inherit;font-size:1rem;line-height:1;padding:0 0 0 2px;cursor:pointer;opacity:.7}.filter-chip .chip-x:hover{opacity:1}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden}.logs-table{border-collapse:separate;border-spacing:0;min-width:1000px}.logs-table thead th{background:var(--card-bg,#f8fafc);border-bottom:2px solid var(--border-color,#e2e8f0);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#64748b);padding:14px 16px}.logs-table tbody tr{transition:all .15s}.logs-table tbody tr:hover{background:rgba(12,110,94,.02)}.logs-table tbody td{padding:14px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);vertical-align:middle;font-size:.88rem;color:var(--text-primary,#1e293b)}
.logs-table th.sortable{cursor:pointer;user-select:none;white-space:nowrap;transition:color .2s}.logs-table th.sortable:hover{color:#0c6e5e}.logs-table th.sortable.active{color:#0c6e5e}.logs-table th.sortable .th-arrow{margin-left:6px;font-size:.7rem;color:var(--text-muted,#94a3b8)}.logs-table th.sortable.active .th-arrow{color:#0c6e5e}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
.row-id{font-family:'SF Mono',Consolas,monospace;font-size:.78rem;padding:3px 10px;border-radius:6px;background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b)}.cell-main{font-weight:600;font-size:.88rem}.cell-sub{font-size:.75rem;color:var(--text-muted,#94a3b8)}
.action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#475569);transition:all .2s;padding:0}.action-btn:hover{border-color:#0c6e5e;color:#0c6e5e;background:rgba(12,110,94,.05)}.action-btn.danger:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.05)}.action-btn.success:hover{border-color:#10b981;color:#10b981;background:rgba(16,185,129,.05)}.action-btn.primary:hover{border-color:#3b82f6;color:#3b82f6;background:rgba(59,130,246,.05)}.action-btn.warning:hover{border-color:#f59e0b;color:#f59e0b;background:rgba(245,158,11,.05)}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted,#94a3b8)}.empty-state .empty-icon{width:56px;height:56px;border-radius:14px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}.empty-state h6{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b);margin-bottom:4px}.empty-state p{font-size:.82rem;margin:0}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px;cursor:pointer}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}.pagination .page-item.disabled .page-link{cursor:default}
.skel{position:relative;overflow:hidden;height:14px;border-radius:6px;background:var(--border-color,#e2e8f0)}.skel::after{content:'';position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);animation:shimmer 1.3s infinite}@keyframes shimmer{to{transform:translateX(100%)}}
.modal-content{border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff)}.modal-header{border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px}.modal-header .modal-title{font-weight:700;font-size:1rem;color:var(--text-primary,#1e293b)}.modal-body{padding:24px}.modal-footer{border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px}.detail-card{background:var(--card-bg,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px}.detail-card .label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#94a3b8);margin-bottom:4px}.detail-card .value{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b)}
.app-toast{position:fixed;top:calc(var(--topbar-height) + 14px);right:24px;z-index:9999;display:flex;align-items:center;gap:8px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-left:4px solid #10b981;border-radius:12px;padding:12px 18px;font-size:.88rem;font-weight:600;color:var(--text-primary,#1e293b);box-shadow:0 12px 32px rgba(0,0,0,.15);opacity:0;transform:translateY(-8px);pointer-events:none;transition:all .3s}.app-toast.show{opacity:1;transform:translateY(0)}.app-toast.danger{border-left-color:#ef4444}
@media (max-width: 991.98px){.sticky-filter{top:12px}}
</style>

<div class="page-hero">
    <h4><i class="fas fa-calendar-alt me-2"></i>Schedule Management</h4>
    <p id="schedulesHeroInfo">Manage <?= (int)($stats['total'] ?? 0) ?> schedule<?= (int)($stats['total'] ?? 0) !== 1 ? 's' : '' ?> across all events.</p>
</div>

<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['id'=>'kpiTotal','val'=>$stats['total']??0, 'label'=>'Total Schedules','icon'=>'fa-calendar-alt','color'=>'#3b82f6','bg'=>'#dbeafe'],
        ['id'=>'kpiScheduled','val'=>$stats['scheduled']??0, 'label'=>'Scheduled','icon'=>'fa-clock','color'=>'#f59e0b','bg'=>'#fef3c7'],
        ['id'=>'kpiProgress','val'=>$stats['in_progress']??0, 'label'=>'In Progress','icon'=>'fa-spinner','color'=>'#06b6d4','bg'=>'#cffafe'],
        ['id'=>'kpiCompleted','val'=>$stats['completed']??0, 'label'=>'Completed','icon'=>'fa-check-circle','color'=>'#10b981','bg'=>'#d1fae5'],
        ['id'=>'kpiCancelled','val'=>$stats['cancelled']??0, 'label'=>'Cancelled','icon'=>'fa-times-circle','color'=>'#ef4444','bg'=>'#fee2e2'],
    ];
    foreach ($statCards as $sc): ?>
    <div class="col-xl col-md-4 col-6">
        <div class="card stat-card"><div class="stat-bar" style="background:<?= $sc['color'] ?>;"></div>
            <div class="stat-body">
                <div class="stat-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;"><i class="fas <?= $sc['icon'] ?>"></i></div>
                <div class="stat-value" style="color:<?= $sc['color'] ?>;" id="<?= $sc['id'] ?>"><?= $sc['val'] ?></div>
                <div class="stat-label"><?= $sc['label'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="filter-card sticky-filter">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label">Search</label>
            <div class="filter-input-wrap">
                <i class="fas fa-search filter-input-icon"></i>
                <input type="text" id="filterSearch" class="form-control filter-input" placeholder="Event, guide, destination..." value="<?= sanitize($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Event</label>
            <select id="filterEvent" class="form-select">
                <option value="">All Events</option>
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $eventFilter == $ev['id'] ? 'selected' : '' ?>><?= sanitize($ev['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Guide</label>
            <select id="filterGuide" class="form-select">
                <option value="">All Guides</option>
                <?php foreach ($guides as $g): ?>
                <option value="<?= $g['id'] ?>" <?= $guideFilter == $g['id'] ? 'selected' : '' ?>><?= sanitize($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Status</label>
            <select id="filterStatus" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (['scheduled','in_progress','completed','cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">From</label>
            <input type="date" id="filterFrom" class="form-control" value="<?= sanitize($dateFrom) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">To</label>
            <input type="date" id="filterTo" class="form-control" value="<?= sanitize($dateTo) ?>">
        </div>
        <div class="col-12 d-flex justify-content-end">
            <button type="button" class="btn btn-sm" id="clearFilters" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-muted,#64748b);"><i class="fas fa-times me-1"></i>Clear</button>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-3" id="schedulesChips" style="display:none;"></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div><span class="small fw-semibold" style="color:var(--text-muted,#64748b);" id="schedulesCount"></span></div>
    <div class="d-flex gap-2 align-items-center">
        <select id="perPage" class="form-select form-select-sm" style="width:auto;border-radius:10px;border-color:var(--border-color,#e2e8f0);">
            <option value="10">10 / page</option>
            <option value="15" selected>15 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
        </select>
        <button type="button" class="btn btn-sm action-btn" id="schedulesRefresh" title="Refresh"><i class="fas fa-rotate"></i></button>
        <button type="button" class="btn btn-sm" id="addScheduleBtn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-plus me-1"></i>Add Schedule</button>
    </div>
</div>

<div class="table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table logs-table align-middle mb-0">
                <thead><tr>
                    <th class="sortable" data-sort="id"># <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="event">Event <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="guide">Guide <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="start">Start <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="end">End <i class="fas fa-sort th-arrow"></i></th>
                    <th>Spots</th>
                    <th class="sortable" data-sort="status">Status <i class="fas fa-sort th-arrow"></i></th>
                    <th class="text-center">Actions</th>
                </tr></thead>
                <tbody id="schedulesBody">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <tr><?php for ($c = 0; $c < 8; $c++): ?><td><div class="skel"></div></td><?php endfor; ?></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<nav class="mt-3" id="schedulesPager"></nav>

<div class="modal fade" id="scheduleModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-alt me-2" style="color:#0c6e5e;"></i><span id="scheduleModalTitle">Add Schedule</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="scheduleForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" id="scheduleAction" value="add_schedule">
        <input type="hidden" name="schedule_id" id="scheduleId" value="">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="detail-card"><div class="label">Event <span class="text-danger">*</span></div>
                        <select name="event_id" id="schEvent" class="form-select" required></select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-card"><div class="label">Guide <span class="text-danger">*</span></div>
                        <select name="guide_id" id="schGuide" class="form-select" required></select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-card"><div class="label">Start Date <span class="text-danger">*</span></div>
                        <input type="date" name="start_date" id="schStartDate" class="form-control" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-card"><div class="label">End Date <span class="text-danger">*</span></div>
                        <input type="date" name="end_date" id="schEndDate" class="form-control" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-card"><div class="label">Start Time <span class="text-danger">*</span></div>
                        <input type="time" name="start_time" id="schStartTime" class="form-control" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="detail-card"><div class="label">End Time <span class="text-danger">*</span></div>
                        <input type="time" name="end_time" id="schEndTime" class="form-control" required>
                    </div>
                </div>
                <div class="col-12">
                    <div class="detail-card"><div class="label">Available Spots</div>
                        <input type="number" name="available_spots" id="schSpots" class="form-control" min="0" value="20">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);">Cancel</button>
            <button type="submit" class="btn btn-sm" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-save me-1"></i>Save Schedule</button>
        </div>
    </form>
</div></div></div>

<div class="app-toast" id="appToast"></div>

<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var EVENTS = <?= json_encode(array_map(fn($e) => ['id' => (int)$e['id'], 'title' => $e['title']], $events), JSON_UNESCAPED_UNICODE) ?>;
    var GUIDES = <?= json_encode(array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name']], $guides), JSON_UNESCAPED_UNICODE) ?>;
    var INIT = <?= json_encode(['event' => $eventFilter, 'guide' => $guideFilter, 'status' => $statusFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'search' => $search], JSON_UNESCAPED_UNICODE) ?>;

    var state = {
        page: 1,
        per_page: 15,
        sort: 'start',
        dir: 'asc',
        event: INIT.event || '',
        guide: INIT.guide || '',
        status: INIT.status || '',
        search: INIT.search || '',
        date_from: INIT.date_from || '',
        date_to: INIT.date_to || ''
    };
    var timer = null;

    var $body = document.getElementById('schedulesBody');
    var $pager = document.getElementById('schedulesPager');
    var $count = document.getElementById('schedulesCount');
    var $chips = document.getElementById('schedulesChips');

    var SCC = { scheduled: ['#fef3c7', '#d97706', 'fa-clock'], in_progress: ['#cffafe', '#0891b2', 'fa-spinner'], completed: ['#d1fae5', '#059669', 'fa-check-circle'], cancelled: ['#fee2e2', '#dc2626', 'fa-times-circle'] };

    function esc(s) { s = (s == null) ? '' : String(s); var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function fmtDate(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
    function fmtTime(s) { if (!s) return ''; var p = String(s).split(':'); var h = parseInt(p[0], 10), m = p[1] || '00'; var am = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + m + ' ' + am; }
    function statusChip(st) { var c = SCC[st] || ['#e2e8f0', '#475569', 'fa-circle']; return '<span class="status-chip" style="background:' + c[0] + ';color:' + c[1] + ';"><i class="fas ' + c[2] + ' me-1"></i>' + (st ? st.replace('_', ' ').replace(/\b\w/g, function (x) { return x.toUpperCase(); }) : 'N/A') + '</span>'; }

    function qs() {
        var p = new URLSearchParams();
        p.set('ajax', '1');
        p.set('page', state.page);
        p.set('per_page', state.per_page);
        p.set('sort', state.sort);
        p.set('dir', state.dir);
        if (state.event) p.set('event', state.event);
        if (state.guide) p.set('guide', state.guide);
        if (state.status) p.set('status', state.status);
        if (state.search) p.set('search', state.search);
        if (state.date_from) p.set('date_from', state.date_from);
        if (state.date_to) p.set('date_to', state.date_to);
        return p.toString();
    }

    function skeletonRows(n) {
        var h = '';
        for (var i = 0; i < n; i++) { h += '<tr>'; for (var c = 0; c < 8; c++) { h += '<td><div class="skel"></div></td>'; } h += '</tr>'; }
        return h;
    }

    function actionButtons(r) {
        var h = '';
        if (r.status === 'scheduled') h += '<button class="action-btn success" data-act="start" data-id="' + r.id + '" title="Start"><i class="fas fa-play"></i></button>';
        if (r.status === 'in_progress') h += '<button class="action-btn primary" data-act="complete" data-id="' + r.id + '" title="Complete"><i class="fas fa-flag-checkered"></i></button>';
        if (r.status === 'scheduled' || r.status === 'in_progress') h += '<button class="action-btn warning" data-act="cancel" data-id="' + r.id + '" title="Cancel"><i class="fas fa-ban"></i></button>';
        if (r.status === 'scheduled') h += '<button class="action-btn primary" data-act="edit" data-id="' + r.id + '" title="Edit"><i class="fas fa-pen"></i></button>';
        h += '<button class="action-btn danger" data-act="delete" data-id="' + r.id + '" title="Delete"><i class="fas fa-trash"></i></button>';
        return h;
    }

    function renderRows(rows) {
        window.__schedules = {};
        if (!rows || !rows.length) {
            $body.innerHTML = '<tr><td colspan="8" class="empty-state"><div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-calendar-alt"></i></div><h6>No schedules found</h6><p>Try adjusting your filters or create a new schedule.</p></td></tr>';
            return;
        }
        var h = '';
        for (var k = 0; k < rows.length; k++) {
            var r = rows[k];
            window.__schedules[r.id] = r;
            h += '<tr>' +
                '<td><span class="row-id">#' + r.id + '</span></td>' +
                '<td><div class="cell-main">' + (esc(r.event_title) || 'N/A') + '</div><div class="cell-sub">' + (esc(r.destination_name) || '') + '</div></td>' +
                '<td><div class="cell-main">' + (esc(r.guide_name) || 'N/A') + '</div></td>' +
                '<td><div class="cell-main">' + esc(fmtDate(r.start_date)) + '</div><div class="cell-sub">' + esc(fmtTime(r.start_time)) + '</div></td>' +
                '<td><div class="cell-main">' + esc(fmtDate(r.end_date)) + '</div><div class="cell-sub">' + esc(fmtTime(r.end_time)) + '</div></td>' +
                '<td><span class="fw-semibold" style="font-size:.88rem;">' + (r.available_spots == null ? '—' : r.available_spots) + '</span></td>' +
                '<td>' + statusChip(r.status) + '</td>' +
                '<td class="text-center"><div class="d-flex gap-1 justify-content-center">' + actionButtons(r) + '</div></td>' +
                '</tr>';
        }
        $body.innerHTML = h;
        updateSortIndicators();
    }

    function pageItem(p, pages, enabled, label) {
        return '<li class="page-item ' + (enabled ? '' : 'disabled') + '"><a class="page-link" href="#" data-page="' + (enabled ? p : '') + '" tabindex="-1">' + label + '</a></li>';
    }

    function renderPager(pages, cur) {
        if (pages <= 1) { $pager.innerHTML = ''; return; }
        var h = '<ul class="pagination justify-content-center mb-0">';
        h += pageItem(cur - 1, pages, cur > 1, '<i class="fas fa-chevron-left"></i>');
        var start = Math.max(1, cur - 2), end = Math.min(pages, cur + 2);
        for (var i = start; i <= end; i++) {
            h += '<li class="page-item ' + (i === cur ? 'active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }
        h += pageItem(cur + 1, pages, cur < pages, '<i class="fas fa-chevron-right"></i>');
        h += '</ul>';
        $pager.innerHTML = h;
    }

    function renderCount(total, shown) {
        $count.textContent = shown === undefined
            ? total + ' schedule' + (total !== 1 ? 's' : '') + ' found'
            : 'Showing ' + shown + ' of ' + total + ' schedule' + (total !== 1 ? 's' : '');
    }

    function updateStats(s) {
        if (!s) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
        set('kpiTotal', s.total);
        set('kpiScheduled', s.scheduled);
        set('kpiProgress', s.in_progress);
        set('kpiCompleted', s.completed);
        set('kpiCancelled', s.cancelled);
        set('schedulesHeroInfo', 'Manage ' + s.total + ' schedule' + (s.total !== 1 ? 's' : '') + ' across all events.');
    }

    function updateChips() {
        $chips.innerHTML = '';
        var add = function (label, clearFn) {
            var c = document.createElement('span');
            c.className = 'filter-chip';
            c.innerHTML = '<span>' + esc(label) + '</span><button type="button" class="chip-x" aria-label="Clear">&times;</button>';
            c.querySelector('.chip-x').addEventListener('click', function () { clearFn(); load(); });
            $chips.appendChild(c);
        };
        if (state.event) add('Event: ' + state.event, function () { state.event = ''; document.getElementById('filterEvent').value = ''; });
        if (state.guide) add('Guide: ' + state.guide, function () { state.guide = ''; document.getElementById('filterGuide').value = ''; });
        if (state.status) add('Status: ' + state.status, function () { state.status = ''; document.getElementById('filterStatus').value = ''; });
        if (state.date_from) add('From: ' + state.date_from, function () { state.date_from = ''; document.getElementById('filterFrom').value = ''; });
        if (state.date_to) add('To: ' + state.date_to, function () { state.date_to = ''; document.getElementById('filterTo').value = ''; });
        if (state.search) add('Search: ' + state.search, function () { state.search = ''; document.getElementById('filterSearch').value = ''; });
        $chips.style.display = $chips.children.length ? 'flex' : 'none';
    }

    function updateSortIndicators() {
        var ths = document.querySelectorAll('th.sortable');
        for (var i = 0; i < ths.length; i++) {
            var th = ths[i], col = th.getAttribute('data-sort'), ic = th.querySelector('.th-arrow');
            if (!ic) continue;
            if (col === state.sort) {
                ic.className = 'fas ' + (state.dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                th.classList.add('active');
            } else {
                ic.className = 'fas fa-sort';
                th.classList.remove('active');
            }
        }
    }

    function toast(msg, type) {
        var box = document.getElementById('appToast');
        box.className = 'app-toast show' + (type === 'danger' ? ' danger' : '');
        box.innerHTML = '<i class="fas ' + (type === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i><span>' + esc(msg) + '</span>';
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { box.classList.remove('show'); }, 3000);
    }

    function load() {
        $body.innerHTML = skeletonRows(8);
        fetch('schedules.php?' + qs())
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                if (data.rows === undefined) throw new Error('bad payload');
                state.page = data.page || 1;
                renderRows(data.rows);
                renderPager(data.pages, data.page);
                renderCount(data.total, data.rows.length);
                updateStats(data.stats);
                updateChips();
                updateSortIndicators();
            })
            .catch(function () {
                $body.innerHTML = '<tr><td colspan="8" class="empty-state"><div class="empty-icon" style="background:rgba(239,68,68,.1);color:#ef4444;"><i class="fas fa-triangle-exclamation"></i></div><h6>Could not load schedules</h6><p>Please try again.</p></td></tr>';
            });
    }

    function populateSelects() {
        var ev = document.getElementById('schEvent');
        ev.innerHTML = '<option value="">Select Event</option>' + EVENTS.map(function (e) { return '<option value="' + e.id + '">' + esc(e.title) + '</option>'; }).join('');
        var gu = document.getElementById('schGuide');
        gu.innerHTML = '<option value="">Select Guide</option>' + GUIDES.map(function (g) { return '<option value="' + g.id + '">' + esc(g.name) + '</option>'; }).join('');
    }

    function openAdd() {
        document.getElementById('scheduleModalTitle').textContent = 'Add Schedule';
        document.getElementById('scheduleAction').value = 'add_schedule';
        document.getElementById('scheduleId').value = '';
        document.getElementById('schStartDate').value = '';
        document.getElementById('schEndDate').value = '';
        document.getElementById('schStartTime').value = '';
        document.getElementById('schEndTime').value = '';
        document.getElementById('schSpots').value = 20;
        populateSelects();
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('scheduleModal')).show();
    }

    function openEdit(id) {
        var r = window.__schedules[id];
        if (!r) return;
        document.getElementById('scheduleModalTitle').textContent = 'Edit Schedule #' + id;
        document.getElementById('scheduleAction').value = 'edit_schedule';
        document.getElementById('scheduleId').value = id;
        populateSelects();
        document.getElementById('schEvent').value = r.event_id || '';
        document.getElementById('schGuide').value = r.guide_id || '';
        document.getElementById('schStartDate').value = r.start_date || '';
        document.getElementById('schEndDate').value = r.end_date || '';
        document.getElementById('schStartTime').value = r.start_time || '';
        document.getElementById('schEndTime').value = r.end_time || '';
        document.getElementById('schSpots').value = r.available_spots == null ? 20 : r.available_spots;
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('scheduleModal')).show();
    }

    function postForm(fd) {
        return fetch('schedules.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    }

    function doAction(id, action, confirmMsg) {
        if (!window.confirm(confirmMsg)) return;
        var fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        fd.append('schedule_id', id);
        postForm(fd).then(function (d) {
            if (d && d.ok) { toast(d.message || 'Done.', 'success'); load(); }
            else { toast((d && d.message) || 'Action failed.', 'danger'); }
        }).catch(function () { toast('Request failed. Check your connection.', 'danger'); });
    }

    document.getElementById('scheduleForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(this);
        fd.append('ajax', '1');
        postForm(fd).then(function (d) {
            if (d && d.ok) {
                toast(d.message || 'Saved.', 'success');
                var m = bootstrap.Modal.getInstance(document.getElementById('scheduleModal'));
                if (m) m.hide();
                load();
            } else {
                toast((d && d.message) || 'Save failed.', 'danger');
            }
        }).catch(function () { toast('Request failed. Check your connection.', 'danger'); });
    });

    document.getElementById('addScheduleBtn').addEventListener('click', openAdd);

    $pager.addEventListener('click', function (e) {
        var a = e.target.closest('a.page-link');
        if (!a) return;
        e.preventDefault();
        var p = parseInt(a.getAttribute('data-page'), 10);
        if (!p) return;
        state.page = p;
        load();
    });

    $body.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        e.preventDefault();
        var id = parseInt(btn.getAttribute('data-id'), 10);
        var act = btn.getAttribute('data-act');
        if (act === 'edit') { openEdit(id); return; }
        var msg = act === 'start' ? 'Start this schedule?' : (act === 'complete' ? 'Mark this schedule as completed?' : (act === 'cancel' ? 'Cancel this schedule?' : 'Permanently delete this schedule?'));
        doAction(id, act + '_schedule', msg);
    });

    document.querySelectorAll('th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = th.getAttribute('data-sort');
            if (state.sort === col) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
            else { state.sort = col; state.dir = (col === 'event' || col === 'guide') ? 'asc' : 'desc'; }
            state.page = 1;
            load();
        });
    });

    function resetPage() { state.page = 1; load(); }

    document.getElementById('filterEvent').addEventListener('change', function () { state.event = this.value; resetPage(); });
    document.getElementById('filterGuide').addEventListener('change', function () { state.guide = this.value; resetPage(); });
    document.getElementById('filterStatus').addEventListener('change', function () { state.status = this.value; resetPage(); });
    document.getElementById('filterFrom').addEventListener('change', function () { state.date_from = this.value; resetPage(); });
    document.getElementById('filterTo').addEventListener('change', function () { state.date_to = this.value; resetPage(); });
    document.getElementById('filterSearch').addEventListener('input', function () {
        var v = this.value;
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (state.search !== v) { state.search = v; state.page = 1; load(); }
        }, 400);
    });

    document.getElementById('clearFilters').addEventListener('click', function () {
        state.event = ''; state.guide = ''; state.status = ''; state.search = ''; state.date_from = ''; state.date_to = '';
        document.getElementById('filterEvent').value = '';
        document.getElementById('filterGuide').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterSearch').value = '';
        document.getElementById('filterFrom').value = '';
        document.getElementById('filterTo').value = '';
        resetPage();
    });

    document.getElementById('perPage').addEventListener('change', function () {
        state.per_page = parseInt(this.value, 10);
        state.page = 1;
        load();
    });

    document.getElementById('schedulesRefresh').addEventListener('click', function () { load(); });

    populateSelects();
    updateSortIndicators();
    load();
})();
</script>

<?php }); ?>
