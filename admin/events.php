<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');
require_once __DIR__ . '/../includes/classes/Event.php';
require_once __DIR__ . '/../includes/classes/Notification.php';
require_once __DIR__ . '/../includes/classes/Destination.php';

$db = Database::getInstance()->getConnection();
$db->exec("UPDATE events SET status = 'completed', updated_at = NOW() WHERE status = 'published' AND event_end_date IS NOT NULL AND event_end_date < CURDATE()");

$eventModel = new Event();

$search = $_GET['search'] ?? '';
$destFilter = $_GET['destination'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();

$destinations = $db->query("SELECT id, name FROM destinations ORDER BY name ASC")->fetchAll();
$categories = ['festival'=>'Festival','cultural_event'=>'Cultural Event','tourism_event'=>'Tourism Event','workshop'=>'Workshop','community_event'=>'Community Event','sports'=>'Sports','arts'=>'Arts','other'=>'Other'];

$allStats = $db->query("SELECT status, COUNT(*) as cnt FROM events GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$eventStats = [
    'total' => (int)$db->query("SELECT COUNT(*) FROM events")->fetchColumn(),
    'draft' => (int)($allStats['draft'] ?? 0),
    'published' => (int)($allStats['published'] ?? 0),
    'completed' => (int)($allStats['completed'] ?? 0),
    'cancelled' => (int)($allStats['cancelled'] ?? 0),
];

// ── AJAX data endpoint (GET ?ajax=1) ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 15;
    $qSearch = trim($_GET['search'] ?? '');
    $qDest = $_GET['destination'] ?? '';
    $qStatus = $_GET['status'] ?? '';
    $qSort = $_GET['sort'] ?? 'date';
    $qDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $sortMap = [
        'id' => 'e.id',
        'title' => 'e.title',
        'destination' => 'd.name',
        'date' => 'e.event_start_date',
        'price' => 'e.price',
        'status' => 'e.status',
    ];
    $orderBy = ($sortMap[$qSort] ?? 'e.created_at') . ' ' . $qDir . ', e.id DESC';

    $where = [];
    $params = [];
    if ($qSearch !== '') {
        $where[] = '(e.title LIKE :q1 OR e.description LIKE :q2)';
        $params[':q1'] = "%{$qSearch}%"; $params[':q2'] = "%{$qSearch}%";
    }
    if ($qDest !== '') { $where[] = 'e.destination_id = :dest'; $params[':dest'] = (int)$qDest; }
    if ($qStatus !== '') { $where[] = 'e.status = :status'; $params[':status'] = $qStatus; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $base = 'FROM events e LEFT JOIN destinations d ON e.destination_id = d.id';

    $countStmt = $db->prepare("SELECT COUNT(*) as c {$base} {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) { $qPage = $pages; }
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare("SELECT e.id, e.title, e.description, e.category, e.event_image, e.event_location,
        e.event_start_date, e.event_end_date, e.event_start_time, e.event_end_time, e.organizer, e.contact_info,
        e.destination_id, e.max_participants, e.min_participants, e.min_age, e.duration_hours, e.price, e.status,
        d.name as destination_name,
        (SELECT COUNT(*) FROM bookings b JOIN schedules s2 ON b.schedule_id = s2.id WHERE s2.event_id = e.id AND b.status IN ('confirmed','completed')) as attendee_count
        {$base} {$whereClause} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'rows'      => $rows,
        'total'     => $total,
        'pages'     => $pages,
        'page'      => $qPage,
        'per_page'  => $perPage,
        'stats'     => $eventStats,
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
        redirect('/admin/events.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';
    $eid = (int)($_POST['event_id'] ?? 0);

    if ($action === 'add_event' || $action === 'edit_event') {
        $title = trim($_POST['title'] ?? '');
        $destId = (int)($_POST['destination_id'] ?? 0);
        if ($title === '' || !$destId) { $respond(false, 'Title and destination are required.'); }

        $image_path = null;
        if ($action === 'edit_event') {
            $image_path = $db->query("SELECT event_image FROM events WHERE id=$eid")->fetchColumn() ?: null;
        }
        if (isset($_FILES['event_image']) && (($_FILES['event_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)) {
            $upload = upload_file($_FILES['event_image'], 'events', ['jpg', 'jpeg', 'png', 'webp']);
            if ($upload['success']) $image_path = $upload['path'];
        }

        $data = [
            'destination_id'   => $destId,
            'title'            => $title,
            'description'      => trim($_POST['description'] ?? ''),
            'category'         => $_POST['category'] ?? 'tourism_event',
            'event_image'      => $image_path,
            'event_location'   => trim($_POST['event_location'] ?? '') ?: null,
            'event_start_date' => ($_POST['event_start_date'] ?? '') ?: null,
            'event_end_date'   => ($_POST['event_end_date'] ?? '') ?: null,
            'event_start_time' => ($_POST['event_start_time'] ?? '') ?: null,
            'event_end_time'   => ($_POST['event_end_time'] ?? '') ?: null,
            'organizer'        => trim($_POST['organizer'] ?? ''),
            'contact_info'     => trim($_POST['contact_info'] ?? ''),
            'max_participants' => max(1, (int)($_POST['max_participants'] ?? 20)),
            'min_participants' => max(1, (int)($_POST['min_participants'] ?? 1)),
            'min_age'          => max(1, (int)($_POST['min_age'] ?? 1)),
            'max_age'          => ($_POST['max_age'] ?? '') ? (int)$_POST['max_age'] : null,
            'duration_hours'   => (float)($_POST['duration_hours'] ?? 1),
            'price'            => (float)($_POST['price'] ?? 0),
        ];

        if ($action === 'add_event') {
            $data['status'] = 'draft';
            $data['created_by'] = (int)$_SESSION['user_id'];
            $newId = $eventModel->create($data);
            ActivityLog::log($_SESSION['user_id'], 'event_add', 'Created event: ' . $title);
            $respond(true, 'Event created as draft.');
        } else {
            if (!$eid) { $respond(false, 'Invalid event.'); }
            $eventModel->update($eid, $data);
            ActivityLog::log($_SESSION['user_id'], 'event_edit', 'Edited event #' . $eid);
            $respond(true, 'Event updated.');
        }
    }

    if ($action === 'publish_event' && $eid) {
        $db->prepare("UPDATE events SET status = 'published', updated_at = NOW() WHERE id = :id")->execute([':id' => $eid]);
        $notif = new Notification(); $notif->notifyEventPublished($eid);
        ActivityLog::log($_SESSION['user_id'], 'event_publish', 'Published event #' . $eid);
        $respond(true, 'Event published!');
    }

    if ($action === 'unpublish_event' && $eid) {
        $db->prepare("UPDATE events SET status = 'draft', updated_at = NOW() WHERE id = :id")->execute([':id' => $eid]);
        ActivityLog::log($_SESSION['user_id'], 'event_unpublish', 'Unpublished event #' . $eid);
        $respond(true, 'Event unpublished.');
    }

    if ($action === 'cancel_event' && $eid) {
        $db->prepare("UPDATE events SET status = 'cancelled', updated_at = NOW() WHERE id = :id")->execute([':id' => $eid]);
        $notif = new Notification(); $notif->notifyEventCancelled($eid);
        ActivityLog::log($_SESSION['user_id'], 'event_cancel', 'Cancelled event #' . $eid);
        $respond(true, 'Event cancelled.');
    }

    if ($action === 'delete_event' && $eid) {
        $deps = (int)$db->query("SELECT COUNT(*) FROM schedules WHERE event_id=$eid")->fetchColumn();
        if ($deps > 0) {
            $respond(false, 'Cannot delete: this event has ' . $deps . ' linked schedule(s). Cancel or delete them first.');
        }
        $eventModel->delete($eid);
        ActivityLog::log($_SESSION['user_id'], 'event_delete', 'Deleted event #' . $eid);
        $respond(true, 'Event deleted.');
    }

    $respond(false, 'Unknown action.');
}

render_page('admin', 'events.php', 'Event Management', function () use ($search, $destFilter, $statusFilter, $eventStats, $destinations, $categories, $csrf, $db) {
$statusColors = ['draft'=>'#6b7280','published'=>'#10b981','cancelled'=>'#ef4444','completed'=>'#06b6d4'];
$statusIcons = ['draft'=>'fa-file','published'=>'fa-globe','cancelled'=>'fa-ban','completed'=>'fa-check-circle'];
$catColors = ['festival'=>'#ec4899','cultural_event'=>'#f59e0b','tourism_event'=>'#3b82f6','workshop'=>'#8b5cf6','community_event'=>'#10b981','sports'=>'#ef4444','arts'=>'#06b6d4','other'=>'#6b7280'];
?>

<style>
.page-hero{background:linear-gradient(135deg,rgba(12,110,94,.9) 0%,rgba(6,95,70,.95) 100%);color:#fff;border-radius:20px;padding:32px 36px;margin-bottom:1.5rem;position:relative;overflow:hidden}.page-hero::before{content:'';position:absolute;top:-50%;right:-15%;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite;pointer-events:none}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.page-hero h4{font-weight:800;margin-bottom:4px;position:relative;z-index:1}.page-hero p{opacity:.85;font-size:.9rem;position:relative;z-index:1;margin-bottom:0}.page-hero .btn{position:relative;z-index:1}
.stat-card{border:none;border-radius:16px;overflow:hidden;transition:all .3s;background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9)}.stat-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.stat-card .stat-bar{height:4px;width:100%}.stat-card .stat-body{padding:18px 16px;text-align:center}.stat-card .stat-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}.stat-card .stat-value{font-size:1.6rem;font-weight:800;line-height:1;margin-bottom:4px}.stat-card .stat-label{font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px;margin-bottom:1rem}.filter-card .form-control,.filter-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}.filter-card .form-label{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.sticky-filter{position:sticky;top:74px;z-index:1015;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.filter-input-wrap{position:relative}.filter-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted,#94a3b8);font-size:.82rem;pointer-events:none}.filter-input{padding-left:34px}
.filter-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(12,110,94,.08);border:1px solid rgba(12,110,94,.25);color:#0c6e5e;font-size:.78rem;font-weight:600;padding:4px 10px;border-radius:50px}[data-theme="dark"] .filter-chip{background:rgba(16,185,129,.12);color:#5eead4;border-color:rgba(16,185,129,.3)}.filter-chip .chip-x{border:none;background:none;color:inherit;font-size:1rem;line-height:1;padding:0 0 0 2px;cursor:pointer;opacity:.7}.filter-chip .chip-x:hover{opacity:1}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden}.logs-table{border-collapse:separate;border-spacing:0;min-width:1080px}.logs-table thead th{background:var(--card-bg,#f8fafc);border-bottom:2px solid var(--border-color,#e2e8f0);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#64748b);padding:14px 16px}.logs-table tbody tr{transition:all .15s}.logs-table tbody tr:hover{background:rgba(12,110,94,.02)}.logs-table tbody td{padding:14px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);vertical-align:middle;font-size:.88rem;color:var(--text-primary,#1e293b)}
.logs-table th.sortable{cursor:pointer;user-select:none;white-space:nowrap;transition:color .2s}.logs-table th.sortable:hover{color:#0c6e5e}.logs-table th.sortable.active{color:#0c6e5e}.logs-table th.sortable .th-arrow{margin-left:6px;font-size:.7rem;color:var(--text-muted,#94a3b8)}.logs-table th.sortable.active .th-arrow{color:#0c6e5e}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
.row-id{font-family:'SF Mono',Consolas,monospace;font-size:.78rem;padding:3px 10px;border-radius:6px;background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b)}.cell-main{font-weight:600;font-size:.88rem}.cell-sub{font-size:.75rem;color:var(--text-muted,#94a3b8)}
.action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#475569);transition:all .2s;padding:0}.action-btn:hover{border-color:#0c6e5e;color:#0c6e5e;background:rgba(12,110,94,.05)}.action-btn.danger:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.05)}.action-btn.success:hover{border-color:#10b981;color:#10b981;background:rgba(16,185,129,.05)}.action-btn.primary:hover{border-color:#3b82f6;color:#3b82f6;background:rgba(59,130,246,.05)}.action-btn.warning:hover{border-color:#f59e0b;color:#f59e0b;background:rgba(245,158,11,.05)}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted,#94a3b8)}.empty-state .empty-icon{width:56px;height:56px;border-radius:14px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}.empty-state h6{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b);margin-bottom:4px}.empty-state p{font-size:.82rem;margin:0}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px;cursor:pointer}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}.pagination .page-item.disabled .page-link{cursor:default}
.skel{position:relative;overflow:hidden;height:14px;border-radius:6px;background:var(--border-color,#e2e8f0)}.skel::after{content:'';position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);animation:shimmer 1.3s infinite}@keyframes shimmer{to{transform:translateX(100%)}}
.modal-content{border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff)}.modal-header{border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px}.modal-header .modal-title{font-weight:700;font-size:1rem;color:var(--text-primary,#1e293b)}.modal-body{padding:24px}.modal-footer{border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px}.detail-card{background:var(--card-bg,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px}.detail-card .label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#94a3b8);margin-bottom:4px}.detail-card .value{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b)}
.event-thumb{width:44px;height:44px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color,#e2e8f0)}
.event-thumb-placeholder{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff}
.app-toast{position:fixed;top:calc(var(--topbar-height) + 14px);right:24px;z-index:9999;display:flex;align-items:center;gap:8px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-left:4px solid #10b981;border-radius:12px;padding:12px 18px;font-size:.88rem;font-weight:600;color:var(--text-primary,#1e293b);box-shadow:0 12px 32px rgba(0,0,0,.15);opacity:0;transform:translateY(-8px);pointer-events:none;transition:all .3s}.app-toast.show{opacity:1;transform:translateY(0)}.app-toast.danger{border-left-color:#ef4444}
@media (max-width: 991.98px){.sticky-filter{top:12px}}
</style>

<div class="page-hero">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h4><i class="fas fa-calendar-alt me-2"></i>Event Management</h4>
            <p id="eventsHeroInfo"><?= (int)$eventStats['total'] ?> total event<?= (int)$eventStats['total'] !== 1 ? 's' : '' ?> · <?= (int)$eventStats['published'] ?> published</p>
        </div>
        <button class="btn" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:12px;font-weight:600;padding:10px 20px;" id="addEventBtn"><i class="fas fa-plus me-1"></i>Create Event</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['id'=>'kpiTotal','val'=>$eventStats['total']??0, 'label'=>'Total Events','icon'=>'fa-calendar-alt','color'=>'#3b82f6','bg'=>'#dbeafe'],
        ['id'=>'kpiDraft','val'=>$eventStats['draft']??0, 'label'=>'Draft','icon'=>'fa-file','color'=>'#6b7280','bg'=>'#f3f4f6'],
        ['id'=>'kpiPublished','val'=>$eventStats['published']??0, 'label'=>'Published','icon'=>'fa-globe','color'=>'#10b981','bg'=>'#d1fae5'],
        ['id'=>'kpiCompleted','val'=>$eventStats['completed']??0, 'label'=>'Completed','icon'=>'fa-check-circle','color'=>'#06b6d4','bg'=>'#cffafe'],
        ['id'=>'kpiCancelled','val'=>$eventStats['cancelled']??0, 'label'=>'Cancelled','icon'=>'fa-ban','color'=>'#ef4444','bg'=>'#fee2e2'],
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
        <div class="col-12 col-md-5">
            <label class="form-label">Search</label>
            <div class="filter-input-wrap">
                <i class="fas fa-search filter-input-icon"></i>
                <input type="text" id="filterSearch" class="form-control filter-input" placeholder="Event name or description..." value="<?= sanitize($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Destination</label>
            <select id="filterDest" class="form-select">
                <option value="">All Destinations</option>
                <?php foreach ($destinations as $dest): ?>
                <option value="<?= $dest['id'] ?>" <?= $destFilter == $dest['id'] ? 'selected' : '' ?>><?= sanitize($dest['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Status</label>
            <select id="filterStatus" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (['draft','published','completed','cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-1 d-flex justify-content-md-end">
            <button type="button" class="btn btn-sm w-100" id="clearFilters" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-muted,#64748b);"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-3" id="eventsChips" style="display:none;"></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div><span class="small fw-semibold" style="color:var(--text-muted,#64748b);" id="eventsCount"></span></div>
    <div class="d-flex gap-2 align-items-center">
        <select id="perPage" class="form-select form-select-sm" style="width:auto;border-radius:10px;border-color:var(--border-color,#e2e8f0);">
            <option value="10">10 / page</option>
            <option value="15" selected>15 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
        </select>
        <button type="button" class="btn btn-sm action-btn" id="eventsRefresh" title="Refresh"><i class="fas fa-rotate"></i></button>
    </div>
</div>

<div class="table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table logs-table align-middle mb-0">
                <thead><tr>
                    <th class="sortable" data-sort="id"># <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="title">Event <i class="fas fa-sort th-arrow"></i></th>
                    <th>Category</th>
                    <th class="sortable" data-sort="destination">Destination <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="date">Date <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="price">Price <i class="fas fa-sort th-arrow"></i></th>
                    <th>Attendees</th>
                    <th class="sortable" data-sort="status">Status <i class="fas fa-sort th-arrow"></i></th>
                    <th class="text-center">Actions</th>
                </tr></thead>
                <tbody id="eventsBody">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <tr><?php for ($c = 0; $c < 9; $c++): ?><td><div class="skel"></div></td><?php endfor; ?></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<nav class="mt-3" id="eventsPager"></nav>

<div class="modal fade ev-modal" id="eventModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header" style="background:linear-gradient(135deg, #0c6e5e, #10b981);color:#fff;padding:20px 24px;border:none;">
        <div class="d-flex align-items-center gap-2">
            <div style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;"><i class="fas fa-calendar-plus" style="font-size:1rem;"></i></div>
            <div>
                <h6 class="mb-0 fw-bold" id="eventModalTitle" style="font-size:1.05rem;">Create New Event</h6>
                <p class="mb-0" style="font-size:.72rem;opacity:.8;">Fill in the details to create an event</p>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:brightness(0) invert(1);opacity:.8;"></button>
    </div>
    <form id="eventForm" enctype="multipart/form-data"><div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:0;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" id="eventAction" value="add_event">
        <input type="hidden" name="event_id" id="eventId" value="">

        <div class="ev-section" style="padding:20px 24px;border-bottom:1px solid var(--border-color,#f1f5f9);">
            <div class="d-flex align-items-center gap-2 mb-3"><i class="fas fa-info-circle" style="color:#0c6e5e;font-size:.7rem;"></i><span style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted,#94a3b8);">Event Information</span><span style="flex:1;height:1px;background:var(--border-color,#e2e8f0);"></span></div>
            <div class="row g-3">
                <div class="col-md-4"><div class="detail-card"><div class="label">Title <span class="text-danger">*</span></div><input type="text" name="title" id="evTitle" class="form-control" required></div></div>
                <div class="col-md-4"><div class="detail-card"><div class="label">Category</div><select name="category" id="evCategory" class="form-select"><?php foreach ($categories as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div></div>
                <div class="col-md-4"><div class="detail-card"><div class="label">Destination <span class="text-danger">*</span></div><select name="destination_id" id="evDest" class="form-select" required><option value="">Select destination</option><?php foreach ($destinations as $d): ?><option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option><?php endforeach; ?></select></div></div>
                <div class="col-12"><div class="detail-card"><div class="label">Description</div><textarea name="description" id="evDesc" class="form-control" rows="2"></textarea></div></div>
                <div class="col-md-6"><div class="detail-card"><div class="label">Banner Image</div><input type="file" name="event_image" id="evImage" class="form-control" accept="image/*"></div></div>
                <div class="col-md-6"><div class="detail-card"><div class="label">Location</div><input type="text" name="event_location" id="evLocation" class="form-control" placeholder="Event venue or address"></div></div>
            </div>
        </div>

        <div class="ev-section" style="padding:20px 24px;border-bottom:1px solid var(--border-color,#f1f5f9);">
            <div class="d-flex align-items-center gap-2 mb-3"><i class="fas fa-clock" style="color:#0c6e5e;font-size:.7rem;"></i><span style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted,#94a3b8);">Schedule</span><span style="flex:1;height:1px;background:var(--border-color,#e2e8f0);"></span></div>
            <div class="row g-3">
                <div class="col-md-3"><div class="detail-card"><div class="label">Start Date</div><input type="date" name="event_start_date" id="evStartDate" class="form-control"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">End Date</div><input type="date" name="event_end_date" id="evEndDate" class="form-control"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Start Time</div><input type="time" name="event_start_time" id="evStartTime" class="form-control"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">End Time</div><input type="time" name="event_end_time" id="evEndTime" class="form-control"></div></div>
            </div>
        </div>

        <div class="ev-section" style="padding:20px 24px;">
            <div class="d-flex align-items-center gap-2 mb-3"><i class="fas fa-users" style="color:#0c6e5e;font-size:.7rem;"></i><span style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted,#94a3b8);">Details &amp; Pricing</span><span style="flex:1;height:1px;background:var(--border-color,#e2e8f0);"></span></div>
            <div class="row g-3">
                <div class="col-md-3"><div class="detail-card"><div class="label">Organizer</div><input type="text" name="organizer" id="evOrganizer" class="form-control"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Contact</div><input type="text" name="contact_info" id="evContact" class="form-control"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Price (₱)</div><input type="number" name="price" id="evPrice" class="form-control" min="0" step="0.01" value="0"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Max Participants</div><input type="number" name="max_participants" id="evMax" class="form-control" min="1" value="20"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Min Participants</div><input type="number" name="min_participants" id="evMin" class="form-control" min="1" value="1"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Duration (hrs)</div><input type="number" name="duration_hours" id="evDuration" class="form-control" min="0.5" step="0.5" value="1"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Min Age</div><input type="number" name="min_age" id="evMinAge" class="form-control" min="1" value="1"></div></div>
                <div class="col-md-3"><div class="detail-card"><div class="label">Max Age</div><input type="number" name="max_age" id="evMaxAge" class="form-control" min="1" placeholder="Optional"></div></div>
            </div>
        </div>

    </div>
    <div class="modal-footer" style="padding:16px 24px;border-top:1px solid var(--border-color,#f1f5f9);display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);">Cancel</button>
        <button type="submit" class="btn btn-sm" style="background:linear-gradient(135deg,#0c6e5e,#10b981);color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-plus me-1"></i>Save Event</button>
    </div>
    </form>
</div></div></div>

<div class="app-toast" id="appToast"></div>

<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var CATS = <?= json_encode(array_map(fn($k, $v) => ['k' => $k, 'v' => $v], array_keys($categories), array_values($categories)), JSON_UNESCAPED_UNICODE) ?>;
    var INIT = <?= json_encode(['search' => $search, 'destination' => $destFilter, 'status' => $statusFilter], JSON_UNESCAPED_UNICODE) ?>;

    var state = { page: 1, per_page: 15, sort: 'date', dir: 'desc', search: INIT.search || '', destination: INIT.destination || '', status: INIT.status || '' };
    var timer = null;

    var $body = document.getElementById('eventsBody');
    var $pager = document.getElementById('eventsPager');
    var $count = document.getElementById('eventsCount');
    var $chips = document.getElementById('eventsChips');

    var SCC = { draft: ['#f3f4f6', '#6b7280', 'fa-file'], published: ['#d1fae5', '#059669', 'fa-globe'], completed: ['#cffafe', '#0891b2', 'fa-check-circle'], cancelled: ['#fee2e2', '#dc2626', 'fa-ban'] };
    var CATCOLORS = { festival: '#ec4899', cultural_event: '#f59e0b', tourism_event: '#3b82f6', workshop: '#8b5cf6', community_event: '#10b981', sports: '#ef4444', arts: '#06b6d4', other: '#6b7280' };

    function esc(s) { s = (s == null) ? '' : String(s); var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function fmtDate(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
    function money(v) { v = parseFloat(v) || 0; return v > 0 ? '\u20b1' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Free'; }
    function statusChip(st) { var c = SCC[st] || ['#e2e8f0', '#475569', 'fa-circle']; return '<span class="status-chip" style="background:' + c[0] + ';color:' + c[1] + ';"><i class="fas ' + c[2] + ' me-1"></i>' + (st ? st.charAt(0).toUpperCase() + st.slice(1) : 'N/A') + '</span>'; }
    function catChip(cat) { var cc = CATCOLORS[cat] || '#6b7280'; var lbl = (cat || '').replace(/_/g, ' '); lbl = lbl.replace(/\b\w/g, function (x) { return x.toUpperCase(); }); return '<span class="status-chip" style="background:' + cc + '18;color:' + cc + ';">' + esc(lbl) + '</span>'; }

    function qs() {
        var p = new URLSearchParams();
        p.set('ajax', '1');
        p.set('page', state.page);
        p.set('per_page', state.per_page);
        p.set('sort', state.sort);
        p.set('dir', state.dir);
        if (state.destination) p.set('destination', state.destination);
        if (state.status) p.set('status', state.status);
        if (state.search) p.set('search', state.search);
        return p.toString();
    }

    function skeletonRows(n) {
        var h = '';
        for (var i = 0; i < n; i++) { h += '<tr>'; for (var c = 0; c < 9; c++) { h += '<td><div class="skel"></div></td>'; } h += '</tr>'; }
        return h;
    }

    function actionButtons(r) {
        var h = '';
        if (r.status === 'draft') h += '<button class="action-btn success" data-act="publish" data-id="' + r.id + '" title="Publish"><i class="fas fa-globe"></i></button>';
        if (r.status === 'published') h += '<button class="action-btn warning" data-act="unpublish" data-id="' + r.id + '" title="Unpublish"><i class="fas fa-eye-slash"></i></button>';
        if (r.status === 'published') h += '<button class="action-btn danger" data-act="cancel" data-id="' + r.id + '" title="Cancel"><i class="fas fa-ban"></i></button>';
        if (r.status !== 'cancelled' && r.status !== 'completed') h += '<button class="action-btn primary" data-act="edit" data-id="' + r.id + '" title="Edit"><i class="fas fa-pen"></i></button>';
        h += '<button class="action-btn danger" data-act="delete" data-id="' + r.id + '" title="Delete"><i class="fas fa-trash"></i></button>';
        return h;
    }

    function thumbHtml(r) {
        var cc = CATCOLORS[r.category] || '#6b7280';
        if (r.event_image) return '<img src="' + esc(r.event_image) + '" class="event-thumb" alt="">';
        return '<div class="event-thumb-placeholder" style="background:' + cc + ';"><i class="fas fa-calendar"></i></div>';
    }

    function renderRows(rows) {
        window.__events = {};
        if (!rows || !rows.length) {
            $body.innerHTML = '<tr><td colspan="9" class="empty-state"><div class="empty-icon" style="background:rgba(59,130,246,0.1);color:#3b82f6;"><i class="fas fa-calendar-alt"></i></div><h6>No events found</h6><p>Try adjusting your filters or create a new event.</p></td></tr>';
            return;
        }
        var h = '';
        for (var k = 0; k < rows.length; k++) {
            var r = rows[k];
            window.__events[r.id] = r;
            h += '<tr>' +
                '<td><span class="row-id">#' + r.id + '</span></td>' +
                '<td><div class="d-flex align-items-center gap-2">' + thumbHtml(r) + '<div><div class="cell-main">' + esc(r.title) + '</div><div class="cell-sub">' + (esc(r.organizer) || '') + '</div></div></div></td>' +
                '<td>' + catChip(r.category) + '</td>' +
                '<td><span style="font-size:.85rem;color:var(--text-muted,#64748b);">' + (esc(r.destination_name) || 'N/A') + '</span></td>' +
                '<td><span style="font-size:.85rem;color:var(--text-muted,#64748b);">' + (r.event_start_date ? esc(fmtDate(r.event_start_date)) : 'TBA') + '</span></td>' +
                '<td><span class="fw-bold" style="color:#0c6e5e;font-size:.9rem;">' + money(r.price) + '</span></td>' +
                '<td><span class="fw-semibold" style="font-size:.88rem;">' + (r.attendee_count || 0) + '</span></td>' +
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
            ? total + ' event' + (total !== 1 ? 's' : '') + ' found'
            : 'Showing ' + shown + ' of ' + total + ' event' + (total !== 1 ? 's' : '');
    }

    function updateStats(s) {
        if (!s) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
        set('kpiTotal', s.total);
        set('kpiDraft', s.draft);
        set('kpiPublished', s.published);
        set('kpiCompleted', s.completed);
        set('kpiCancelled', s.cancelled);
        set('eventsHeroInfo', s.total + ' total event' + (s.total !== 1 ? 's' : '') + ' \u00b7 ' + s.published + ' published');
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
        if (state.destination) add('Destination: ' + state.destination, function () { state.destination = ''; document.getElementById('filterDest').value = ''; });
        if (state.status) add('Status: ' + state.status, function () { state.status = ''; document.getElementById('filterStatus').value = ''; });
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
        fetch('events.php?' + qs())
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
                $body.innerHTML = '<tr><td colspan="9" class="empty-state"><div class="empty-icon" style="background:rgba(239,68,68,.1);color:#ef4444;"><i class="fas fa-triangle-exclamation"></i></div><h6>Could not load events</h6><p>Please try again.</p></td></tr>';
            });
    }

    function resetForm() {
        document.getElementById('eventModalTitle').textContent = 'Create New Event';
        document.getElementById('eventAction').value = 'add_event';
        document.getElementById('eventId').value = '';
        document.getElementById('evTitle').value = '';
        document.getElementById('evDesc').value = '';
        document.getElementById('evLocation').value = '';
        document.getElementById('evStartDate').value = '';
        document.getElementById('evEndDate').value = '';
        document.getElementById('evStartTime').value = '';
        document.getElementById('evEndTime').value = '';
        document.getElementById('evOrganizer').value = '';
        document.getElementById('evContact').value = '';
        document.getElementById('evPrice').value = 0;
        document.getElementById('evMax').value = 20;
        document.getElementById('evMin').value = 1;
        document.getElementById('evDuration').value = 1;
        document.getElementById('evMinAge').value = 1;
        document.getElementById('evMaxAge').value = '';
        document.getElementById('evImage').value = '';
        document.getElementById('evDest').value = '';
        document.getElementById('evCategory').selectedIndex = 0;
    }

    function openAdd() {
        resetForm();
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('eventModal')).show();
    }

    function openEdit(id) {
        var r = window.__events[id];
        if (!r) return;
        resetForm();
        document.getElementById('eventModalTitle').textContent = 'Edit Event #' + id;
        document.getElementById('eventAction').value = 'edit_event';
        document.getElementById('eventId').value = id;
        document.getElementById('evTitle').value = r.title || '';
        document.getElementById('evDesc').value = r.description || '';
        document.getElementById('evLocation').value = r.event_location || '';
        document.getElementById('evStartDate').value = r.event_start_date || '';
        document.getElementById('evEndDate').value = r.event_end_date || '';
        document.getElementById('evStartTime').value = r.event_start_time || '';
        document.getElementById('evEndTime').value = r.event_end_time || '';
        document.getElementById('evOrganizer').value = r.organizer || '';
        document.getElementById('evContact').value = r.contact_info || '';
        document.getElementById('evPrice').value = r.price || 0;
        document.getElementById('evMax').value = r.max_participants || 20;
        document.getElementById('evMin').value = r.min_participants || 1;
        document.getElementById('evDuration').value = r.duration_hours || 1;
        document.getElementById('evMinAge').value = r.min_age || 1;
        document.getElementById('evMaxAge').value = r.max_age || '';
        document.getElementById('evDest').value = r.destination_id || '';
        for (var i = 0; i < CATS.length; i++) { if (CATS[i].k === r.category) { document.getElementById('evCategory').selectedIndex = i; break; } }
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('eventModal')).show();
    }

    function postForm(fd) {
        return fetch('events.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
    }

    function doAction(id, action, confirmMsg) {
        if (!window.confirm(confirmMsg)) return;
        var fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        fd.append('event_id', id);
        postForm(fd).then(function (d) {
            if (d && d.ok) { toast(d.message || 'Done.', 'success'); load(); }
            else { toast((d && d.message) || 'Action failed.', 'danger'); }
        }).catch(function () { toast('Request failed. Check your connection.', 'danger'); });
    }

    document.getElementById('eventForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(this);
        fd.append('ajax', '1');
        postForm(fd).then(function (d) {
            if (d && d.ok) {
                toast(d.message || 'Saved.', 'success');
                var m = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                if (m) m.hide();
                load();
            } else {
                toast((d && d.message) || 'Save failed.', 'danger');
            }
        }).catch(function () { toast('Request failed. Check your connection.', 'danger'); });
    });

    document.getElementById('addEventBtn').addEventListener('click', openAdd);

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
        var msg = act === 'publish' ? 'Publish this event?' : (act === 'unpublish' ? 'Unpublish this event?' : (act === 'cancel' ? 'Cancel this event?' : 'Permanently delete this event?'));
        doAction(id, act + '_event', msg);
    });

    document.querySelectorAll('th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = th.getAttribute('data-sort');
            if (state.sort === col) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
            else { state.sort = col; state.dir = (col === 'title' || col === 'destination') ? 'asc' : 'desc'; }
            state.page = 1;
            load();
        });
    });

    function resetPage() { state.page = 1; load(); }

    document.getElementById('filterDest').addEventListener('change', function () { state.destination = this.value; resetPage(); });
    document.getElementById('filterStatus').addEventListener('change', function () { state.status = this.value; resetPage(); });
    document.getElementById('filterSearch').addEventListener('input', function () {
        var v = this.value;
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (state.search !== v) { state.search = v; state.page = 1; load(); }
        }, 400);
    });

    document.getElementById('clearFilters').addEventListener('click', function () {
        state.destination = ''; state.status = ''; state.search = '';
        document.getElementById('filterDest').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterSearch').value = '';
        resetPage();
    });

    document.getElementById('perPage').addEventListener('change', function () {
        state.per_page = parseInt(this.value, 10);
        state.page = 1;
        load();
    });

    document.getElementById('eventsRefresh').addEventListener('click', function () { load(); });

    updateSortIndicators();
    load();
})();
</script>

<?php }); ?>
