<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');
require_once __DIR__ . '/../includes/classes/Booking.php';

$db = Database::getInstance()->getConnection();
$bookingModel = new Booking();

$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();
$stats = $bookingModel->getStats();

// ── AJAX data endpoint (GET ?ajax=1) ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 15;
    $qSearch = trim($_GET['search'] ?? '');
    $qStatus = $_GET['status'] ?? '';
    $qFrom = $_GET['date_from'] ?? '';
    $qTo = $_GET['date_to'] ?? '';
    $qSort = $_GET['sort'] ?? 'date';
    $qDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $sortMap = [
        'id' => 'b.id',
        'ref' => 'b.booking_reference',
        'tourist' => 'u.name',
        'destination' => 'COALESCE(d2.name, d.name)',
        'date' => 'b.created_at',
        'amount' => 'b.total_price',
        'status' => 'b.status',
    ];
    $orderBy = ($sortMap[$qSort] ?? 'b.created_at') . ' ' . $qDir . ', b.id DESC';

    $where = [];
    $params = [];
    if ($qSearch !== '') {
        $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR b.booking_reference LIKE :q3)';
        $params[':q1'] = "%{$qSearch}%"; $params[':q2'] = "%{$qSearch}%"; $params[':q3'] = "%{$qSearch}%";
    }
    if ($qStatus !== '') { $where[] = 'b.status = :status'; $params[':status'] = $qStatus; }
    if ($qFrom !== '') { $where[] = 'b.created_at >= :from'; $params[':from'] = $qFrom . ' 00:00:00'; }
    if ($qTo !== '') { $where[] = 'b.created_at <= :to'; $params[':to'] = $qTo . ' 23:59:59'; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $base = 'FROM bookings b LEFT JOIN users u ON b.tourist_id = u.id
        LEFT JOIN schedules s ON b.schedule_id = s.id
        LEFT JOIN events e ON s.event_id = e.id
        LEFT JOIN destinations d ON e.destination_id = d.id
        LEFT JOIN destinations d2 ON b.destination_id = d2.id
        LEFT JOIN users gu ON b.guide_id = gu.id';

    $countStmt = $db->prepare("SELECT COUNT(*) as c {$base} {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) { $qPage = $pages; }
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare("SELECT b.id, b.booking_reference, b.num_participants, b.total_price, b.payment_method, b.status,
        b.visit_date, b.full_name, b.email, b.contact_number, b.special_requests, b.created_at,
        u.name as tourist_name, u.email as tourist_email, e.title as event_title, s.start_date, s.start_time,
        COALESCE(d2.name, d.name) as destination_name, gu.name as guide_name,
        (SELECT p.payment_status FROM payments p WHERE p.booking_id = b.id ORDER BY p.created_at DESC LIMIT 1) as pay_status,
        (SELECT p.payment_method FROM payments p WHERE p.booking_id = b.id ORDER BY p.created_at DESC LIMIT 1) as pay_method
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
            'pending'        => (int)($stats['pending'] ?? 0),
            'confirmed'      => (int)($stats['confirmed'] ?? 0),
            'completed'      => (int)($stats['completed'] ?? 0),
            'cancelled'      => (int)($stats['cancelled'] ?? 0),
            'total_revenue'  => round((float)($stats['total_revenue'] ?? 0), 2),
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
        redirect('/admin/bookings.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';
    $bid = (int)($_POST['booking_id'] ?? 0);

    if ($action === 'confirm' && $bid) {
        $db->prepare("UPDATE bookings SET status = 'confirmed', updated_at = db_now() WHERE id = :id AND status = 'pending'")->execute([':id' => $bid]);
        $notif = new Notification(); $notif->notifyBookingConfirmed($bid);
        ActivityLog::log($_SESSION['user_id'], 'booking_confirm', 'Confirmed booking #' . $bid);
        $respond(true, 'Booking confirmed.');
    }

    if ($action === 'cancel' && $bid) {
        $db->prepare("UPDATE bookings SET status = 'cancelled', updated_at = db_now() WHERE id = :id AND status IN ('pending','confirmed')")->execute([':id' => $bid]);
        $notif = new Notification(); $notif->notifyBookingCancelled($bid);
        ActivityLog::log($_SESSION['user_id'], 'booking_cancel', 'Cancelled booking #' . $bid);
        $respond(true, 'Booking cancelled.');
    }

    if ($action === 'complete' && $bid) {
        $db->prepare("UPDATE bookings SET status = 'completed', updated_at = db_now() WHERE id = :id AND status = 'confirmed'")->execute([':id' => $bid]);
        $notif = new Notification(); $notif->notifyBookingCompleted($bid);
        ActivityLog::log($_SESSION['user_id'], 'booking_complete', 'Completed booking #' . $bid);
        $respond(true, 'Booking marked as completed.');
    }

    $respond(false, 'Unknown action.');
}

render_page('admin', 'bookings.php', 'Booking Management', function () use ($statusFilter, $dateFrom, $dateTo, $search, $stats, $csrf) {
?>

<style>
.page-hero{background:linear-gradient(135deg,rgba(12,110,94,.9) 0%,rgba(6,95,70,.95) 100%);color:#fff;border-radius:20px;padding:32px 36px;margin-bottom:1.5rem;position:relative;overflow:hidden}.page-hero::before{content:'';position:absolute;top:-50%;right:-15%;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.page-hero h4{font-weight:800;margin-bottom:4px;position:relative;z-index:1}.page-hero p{opacity:.85;font-size:.9rem;position:relative;z-index:1;margin-bottom:0}
.stat-card{border:none;border-radius:16px;overflow:hidden;transition:all .3s;background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9)}.stat-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.stat-card .stat-bar{height:4px;width:100%}.stat-card .stat-body{padding:18px 16px;text-align:center}.stat-card .stat-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}.stat-card .stat-value{font-size:1.6rem;font-weight:800;line-height:1;margin-bottom:4px}.stat-card .stat-label{font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px;margin-bottom:1rem}.filter-card .form-control,.filter-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}.filter-card .form-label{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.sticky-filter{position:sticky;top:74px;z-index:1015;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.filter-input-wrap{position:relative}.filter-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted,#94a3b8);font-size:.82rem;pointer-events:none}.filter-input{padding-left:34px}
.filter-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(12,110,94,.08);border:1px solid rgba(12,110,94,.25);color:#0c6e5e;font-size:.78rem;font-weight:600;padding:4px 10px;border-radius:50px}[data-theme="dark"] .filter-chip{background:rgba(16,185,129,.12);color:#5eead4;border-color:rgba(16,185,129,.3)}.filter-chip .chip-x{border:none;background:none;color:inherit;font-size:1rem;line-height:1;padding:0 0 0 2px;cursor:pointer;opacity:.7}.filter-chip .chip-x:hover{opacity:1}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden}.logs-table{border-collapse:separate;border-spacing:0;min-width:1100px}.logs-table thead th{background:var(--card-bg,#f8fafc);border-bottom:2px solid var(--border-color,#e2e8f0);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#64748b);padding:14px 16px}.logs-table tbody tr{transition:all .15s}.logs-table tbody tr:hover{background:rgba(12,110,94,.02)}.logs-table tbody td{padding:14px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);vertical-align:middle;font-size:.88rem;color:var(--text-primary,#1e293b)}
.logs-table th.sortable{cursor:pointer;user-select:none;white-space:nowrap;transition:color .2s}.logs-table th.sortable:hover{color:#0c6e5e}.logs-table th.sortable.active{color:#0c6e5e}.logs-table th.sortable .th-arrow{margin-left:6px;font-size:.7rem;color:var(--text-muted,#94a3b8)}.logs-table th.sortable.active .th-arrow{color:#0c6e5e}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
.row-id{font-family:'SF Mono',Consolas,monospace;font-size:.78rem;padding:3px 10px;border-radius:6px;background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b)}.cell-main{font-weight:600;font-size:.88rem}.cell-sub{font-size:.75rem;color:var(--text-muted,#94a3b8)}
.action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#475569);transition:all .2s;padding:0}.action-btn:hover{border-color:#0c6e5e;color:#0c6e5e;background:rgba(12,110,94,.05)}.action-btn.danger:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.05)}.action-btn.success:hover{border-color:#10b981;color:#10b981;background:rgba(16,185,129,.05)}.action-btn.primary:hover{border-color:#3b82f6;color:#3b82f6;background:rgba(59,130,246,.05)}
.act-menu{border-radius:12px;border:1px solid var(--border-color,#e2e8f0);box-shadow:0 12px 32px rgba(0,0,0,.12);padding:6px;min-width:200px;z-index:1050}.act-menu .dropdown-item{border-radius:8px;font-size:.85rem;font-weight:500;padding:8px 12px;color:var(--text-primary,#1e293b)}.act-menu .dropdown-item:hover{background:rgba(12,110,94,.06)}.act-menu .dropdown-divider{margin:4px 0;border-color:var(--border-color,#e2e8f0)}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted,#94a3b8)}.empty-state .empty-icon{width:56px;height:56px;border-radius:14px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}.empty-state h6{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b);margin-bottom:4px}.empty-state p{font-size:.82rem;margin:0}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px;cursor:pointer}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}.pagination .page-item.disabled .page-link{cursor:default}
.skel{position:relative;overflow:hidden;height:14px;border-radius:6px;background:var(--border-color,#e2e8f0)}.skel::after{content:'';position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);animation:shimmer 1.3s infinite}@keyframes shimmer{to{transform:translateX(100%)}}
.modal-content{border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff)}.modal-header{border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px}.modal-header .modal-title{font-weight:700;font-size:1rem;color:var(--text-primary,#1e293b)}.modal-body{padding:24px}.modal-footer{border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px}
.detail-card{background:var(--card-bg,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px;transition:all .2s}.detail-card:hover{border-color:rgba(12,110,94,.3)}.detail-card .label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#94a3b8);margin-bottom:4px}.detail-card .value{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b)}
.app-toast{position:fixed;top:calc(var(--topbar-height) + 14px);right:24px;z-index:9999;display:flex;align-items:center;gap:8px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-left:4px solid #10b981;border-radius:12px;padding:12px 18px;font-size:.88rem;font-weight:600;color:var(--text-primary,#1e293b);box-shadow:0 12px 32px rgba(0,0,0,.15);opacity:0;transform:translateY(-8px);pointer-events:none;transition:all .3s}.app-toast.show{opacity:1;transform:translateY(0)}.app-toast.danger{border-left-color:#ef4444}
@media (max-width: 991.98px){.sticky-filter{top:12px}}
</style>

<div class="page-hero">
    <h4><i class="fas fa-ticket-alt me-2"></i>Booking Management</h4>
    <p id="bookingsHeroInfo">Manage <?= (int)($stats['total'] ?? 0) ?> total booking<?= (int)($stats['total'] ?? 0) !== 1 ? 's' : '' ?> across all tours.</p>
</div>

<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['id'=>'kpiTotal','val'=>$stats['total']??0, 'label'=>'Total Bookings','icon'=>'fa-ticket-alt','color'=>'#3b82f6','bg'=>'#dbeafe'],
        ['id'=>'kpiPending','val'=>$stats['pending']??0, 'label'=>'Pending','icon'=>'fa-clock','color'=>'#f59e0b','bg'=>'#fef3c7'],
        ['id'=>'kpiConfirmed','val'=>$stats['confirmed']??0, 'label'=>'Confirmed','icon'=>'fa-check-circle','color'=>'#10b981','bg'=>'#d1fae5'],
        ['id'=>'kpiCompleted','val'=>$stats['completed']??0, 'label'=>'Completed','icon'=>'fa-flag-checkered','color'=>'#06b6d4','bg'=>'#cffafe'],
        ['id'=>'kpiCancelled','val'=>$stats['cancelled']??0, 'label'=>'Cancelled','icon'=>'fa-ban','color'=>'#ef4444','bg'=>'#fee2e2'],
        ['id'=>'kpiRevenue','val'=>'₱'.number_format($stats['total_revenue']??0,2), 'label'=>'Revenue','icon'=>'fa-peso-sign','color'=>'#10b981','bg'=>'#d1fae5'],
    ];
    foreach ($statCards as $sc): ?>
    <div class="col-xl-2 col-md-4 col-6">
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
        <div class="col-12 col-md-4">
            <label class="form-label">Search</label>
            <div class="filter-input-wrap">
                <i class="fas fa-search filter-input-icon"></i>
                <input type="text" id="filterSearch" class="form-control filter-input" placeholder="Tourist name, email, ref #..." value="<?= sanitize($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Status</label>
            <select id="filterStatus" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (['pending','confirmed','cancelled','completed'] as $st): ?>
                <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
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
        <div class="col-6 col-md-2 d-flex justify-content-md-end">
            <button type="button" class="btn btn-sm w-100" id="clearFilters" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-muted,#64748b);"><i class="fas fa-times me-1"></i>Clear</button>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-3" id="bookingsChips" style="display:none;"></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div><span class="small fw-semibold" style="color:var(--text-muted,#64748b);" id="bookingsCount"></span></div>
    <div class="d-flex gap-2 align-items-center">
        <select id="perPage" class="form-select form-select-sm" style="width:auto;border-radius:10px;border-color:var(--border-color,#e2e8f0);">
            <option value="10">10 / page</option>
            <option value="15" selected>15 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
        </select>
        <button type="button" class="btn btn-sm action-btn" id="bookingsRefresh" title="Refresh"><i class="fas fa-rotate"></i></button>
    </div>
</div>

<div class="table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table logs-table align-middle mb-0">
                <thead><tr>
                    <th class="sortable" data-sort="id"># <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="ref">Ref <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="tourist">Tourist <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="destination">Destination <i class="fas fa-sort th-arrow"></i></th>
                    <th>Guide</th>
                    <th>Visit Date</th>
                    <th>Guests</th>
                    <th class="sortable" data-sort="amount">Amount <i class="fas fa-sort th-arrow"></i></th>
                    <th>Payment</th>
                    <th class="sortable" data-sort="status">Status <i class="fas fa-sort th-arrow"></i></th>
                    <th class="text-center">Actions</th>
                </tr></thead>
                <tbody id="bookingsBody">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <tr><?php for ($c = 0; $c < 11; $c++): ?><td><div class="skel"></div></td><?php endfor; ?></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<nav class="mt-3" id="bookingsPager"></nav>

<div class="modal fade" id="bookingModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-ticket-alt me-2" style="color:#0c6e5e;"></i>Booking Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-2">
            <div class="col-6"><div class="detail-card"><div class="label">Reference</div><div class="value" id="bmRef" style="font-family:'SF Mono',Consolas,monospace;font-size:.8rem;"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Status</div><div class="value" id="bmStatus"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Tourist</div><div class="value" id="bmTourist"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Email</div><div class="value" id="bmEmail" style="font-weight:400;font-size:.82rem;"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Contact</div><div class="value" id="bmContact" style="font-size:.85rem;"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Destination</div><div class="value" id="bmDest" style="font-size:.85rem;"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Guide</div><div class="value" id="bmGuide"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Visit Date</div><div class="value" id="bmDate" style="font-size:.85rem;"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Participants</div><div class="value" id="bmGuests"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Total Price</div><div class="value" id="bmAmount" style="color:#0c6e5e;"></div></div></div>
            <div class="col-12"><div class="detail-card"><div class="label">Special Requests</div><div class="value" id="bmSpecial" style="font-weight:400;font-size:.85rem;white-space:pre-wrap;">—</div></div></div>
        </div>
    </div>
    <div class="modal-footer d-flex justify-content-between">
        <div class="d-flex gap-2" id="bmActions"></div>
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);">Close</button>
    </div>
</div></div></div>

<div class="app-toast" id="appToast"></div>

<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var INIT = <?= json_encode(['status' => $statusFilter, 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo], JSON_UNESCAPED_UNICODE) ?>;

    var state = {
        page: 1,
        per_page: 15,
        sort: 'date',
        dir: 'desc',
        status: INIT.status || '',
        search: INIT.search || '',
        date_from: INIT.date_from || '',
        date_to: INIT.date_to || ''
    };
    var timer = null;

    var $body = document.getElementById('bookingsBody');
    var $pager = document.getElementById('bookingsPager');
    var $count = document.getElementById('bookingsCount');
    var $chips = document.getElementById('bookingsChips');

    var BSC = { pending: ['#fef3c7', '#d97706', 'fa-clock'], confirmed: ['#d1fae5', '#059669', 'fa-check-circle'], completed: ['#dbeafe', '#2563eb', 'fa-flag-checkered'], cancelled: ['#fee2e2', '#dc2626', 'fa-ban'] };
    var PSC = { paid: ['#d1fae5', '#059669'], pending: ['#fef3c7', '#d97706'], unpaid: ['#e2e8f0', '#64748b'], failed: ['#fee2e2', '#dc2626'], refunded: ['#cffafe', '#0891b2'] };

    function esc(s) { s = (s == null) ? '' : String(s); var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function trunc(s, n) { s = s || ''; return s.length > n ? s.slice(0, n) + '\u2026' : s; }
    function fmtDate(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
    function money(v) { return '\u20b1' + (parseFloat(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function statusChip(st) { var c = BSC[st] || ['#e2e8f0', '#475569', 'fa-circle']; return '<span class="status-chip" style="background:' + c[0] + ';color:' + c[1] + ';"><i class="fas ' + c[2] + ' me-1"></i>' + (st ? st.charAt(0).toUpperCase() + st.slice(1) : 'N/A') + '</span>'; }
    function payChip(ps, pm) { var c = PSC[ps] || ['#e2e8f0', '#64748b']; var ic = pm === 'gcash' ? 'fa-wallet' : (pm === 'maya' ? 'fa-mobile-alt' : (pm === 'card' ? 'fa-credit-card' : 'fa-circle')); return '<span class="status-chip" style="background:' + c[0] + ';color:' + c[1] + ';"><i class="fas ' + ic + ' me-1"></i>' + (ps ? ps.charAt(0).toUpperCase() + ps.slice(1) : 'N/A') + '</span>'; }

    function qs() {
        var p = new URLSearchParams();
        p.set('ajax', '1');
        p.set('page', state.page);
        p.set('per_page', state.per_page);
        p.set('sort', state.sort);
        p.set('dir', state.dir);
        if (state.status) p.set('status', state.status);
        if (state.search) p.set('search', state.search);
        if (state.date_from) p.set('date_from', state.date_from);
        if (state.date_to) p.set('date_to', state.date_to);
        return p.toString();
    }

    function skeletonRows(n) {
        var h = '';
        for (var i = 0; i < n; i++) { h += '<tr>'; for (var c = 0; c < 11; c++) { h += '<td><div class="skel"></div></td>'; } h += '</tr>'; }
        return h;
    }

    function actionButtons(r) {
        var h = '';
        if (r.status === 'pending') h += '<button class="action-btn success" data-act="confirm" data-id="' + r.id + '" title="Confirm"><i class="fas fa-check"></i></button>';
        if (r.status === 'pending' || r.status === 'confirmed') h += '<button class="action-btn danger" data-act="cancel" data-id="' + r.id + '" title="Cancel"><i class="fas fa-ban"></i></button>';
        if (r.status === 'confirmed') h += '<button class="action-btn primary" data-act="complete" data-id="' + r.id + '" title="Complete"><i class="fas fa-flag-checkered"></i></button>';
        return h;
    }

    function renderRows(rows) {
        window.__bookings = {};
        if (!rows || !rows.length) {
            $body.innerHTML = '<tr><td colspan="11" class="empty-state"><div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-ticket-alt"></i></div><h6>No bookings found</h6><p>Try adjusting your filters.</p></td></tr>';
            return;
        }
        var h = '';
        for (var k = 0; k < rows.length; k++) {
            var r = rows[k];
            window.__bookings[r.id] = r;
            var menu = '<div class="dropdown">' +
                '<button class="action-btn" data-bs-toggle="dropdown" title="Actions"><i class="fas fa-ellipsis-vertical"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end act-menu">' +
                '<li><button class="dropdown-item" data-act="view" data-id="' + r.id + '"><i class="fas fa-eye me-2"></i>View details</button></li>' +
                (r.status === 'pending' ? '<li><button class="dropdown-item" data-act="confirm" data-id="' + r.id + '"><i class="fas fa-check me-2 text-success"></i>Confirm</button></li>' : '') +
                ((r.status === 'pending' || r.status === 'confirmed') ? '<li><button class="dropdown-item" data-act="cancel" data-id="' + r.id + '"><i class="fas fa-ban me-2 text-danger"></i>Cancel</button></li>' : '') +
                (r.status === 'confirmed' ? '<li><button class="dropdown-item" data-act="complete" data-id="' + r.id + '"><i class="fas fa-flag-checkered me-2" style="color:#3b82f6;"></i>Complete</button></li>' : '') +
                '</ul></div>';
            h += '<tr>' +
                '<td><span class="small fw-bold" style="color:var(--text-muted,#94a3b8);">#' + r.id + '</span></td>' +
                '<td><span class="row-id">' + esc(r.booking_reference || 'N/A') + '</span></td>' +
                '<td><div class="cell-main">' + (esc(r.tourist_name) || 'N/A') + '</div><div class="cell-sub">' + esc(r.tourist_email) + '</div></td>' +
                '<td><span style="font-size:.85rem;color:var(--text-muted,#64748b);">' + (esc(r.destination_name) || 'N/A') + '</span></td>' +
                '<td><span style="font-size:.85rem;color:var(--text-muted,#64748b);">' + (esc(r.guide_name) || 'N/A') + '</span></td>' +
                '<td><span style="font-size:.85rem;color:var(--text-muted,#64748b);">' + esc(fmtDate(r.visit_date || r.start_date || r.created_at)) + '</span></td>' +
                '<td><span class="fw-semibold" style="font-size:.88rem;">' + (r.num_participants || 1) + '</span></td>' +
                '<td><span class="fw-bold" style="color:#0c6e5e;font-size:.9rem;">' + money(r.total_price) + '</span></td>' +
                '<td>' + payChip(r.pay_status || r.payment_status || 'unpaid', r.pay_method || r.payment_method) + '</td>' +
                '<td>' + statusChip(r.status) + '</td>' +
                '<td class="text-center"><div class="d-flex gap-1 justify-content-center">' + actionButtons(r) + menu + '</div></td>' +
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
            ? total + ' booking' + (total !== 1 ? 's' : '') + ' found'
            : 'Showing ' + shown + ' of ' + total + ' booking' + (total !== 1 ? 's' : '');
    }

    function updateStats(s) {
        if (!s) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
        set('kpiTotal', s.total);
        set('kpiPending', s.pending);
        set('kpiConfirmed', s.confirmed);
        set('kpiCompleted', s.completed);
        set('kpiCancelled', s.cancelled);
        set('kpiRevenue', money(s.total_revenue));
        set('bookingsHeroInfo', 'Manage ' + s.total + ' total booking' + (s.total !== 1 ? 's' : '') + ' across all tours.');
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
        if (state.status) add('Status: ' + state.status.charAt(0).toUpperCase() + state.status.slice(1), function () { state.status = ''; document.getElementById('filterStatus').value = ''; });
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
        fetch('bookings.php?' + qs())
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
                $body.innerHTML = '<tr><td colspan="11" class="empty-state"><div class="empty-icon" style="background:rgba(239,68,68,.1);color:#ef4444;"><i class="fas fa-triangle-exclamation"></i></div><h6>Could not load bookings</h6><p>Please try again.</p></td></tr>';
            });
    }

    function closeMenu(btn) {
        var dd = btn.closest('.dropdown');
        if (dd) {
            var t = dd.querySelector('[data-bs-toggle="dropdown"]');
            if (t) t.setAttribute('aria-expanded', 'false');
            var m = dd.querySelector('.dropdown-menu');
            if (m) m.classList.remove('show');
            dd.classList.remove('show');
        }
    }

    function doAction(id, action, confirmMsg) {
        if (!window.confirm(confirmMsg)) return;
        var fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        fd.append('booking_id', id);
        fetch('bookings.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) { toast(d.message || 'Done.', 'success'); load(); }
                else { toast((d && d.message) || 'Action failed.', 'danger'); }
            })
            .catch(function () { toast('Request failed. Check your connection.', 'danger'); });
    }

    function openView(id) {
        var r = window.__bookings[id];
        if (!r) return;
        document.getElementById('bmRef').textContent = r.booking_reference || 'N/A';
        document.getElementById('bmStatus').innerHTML = statusChip(r.status);
        document.getElementById('bmTourist').textContent = r.full_name || r.tourist_name || 'N/A';
        document.getElementById('bmEmail').textContent = r.email || r.tourist_email || '—';
        document.getElementById('bmContact').textContent = r.contact_number || '—';
        document.getElementById('bmDest').textContent = r.destination_name || 'N/A';
        document.getElementById('bmGuide').textContent = r.guide_name || 'N/A';
        document.getElementById('bmDate').textContent = fmtDate(r.visit_date || r.start_date || r.created_at);
        document.getElementById('bmGuests').textContent = r.num_participants || 1;
        document.getElementById('bmAmount').textContent = money(r.total_price);
        document.getElementById('bmSpecial').textContent = r.special_requests || '—';
        var ab = document.getElementById('bmActions');
        ab.innerHTML = actionButtons(r);
        ab.querySelectorAll('[data-act]').forEach(function (b) {
            b.addEventListener('click', function () {
                var act = b.getAttribute('data-act');
                var msg = act === 'confirm' ? 'Confirm this booking?' : (act === 'cancel' ? 'Cancel this booking?' : 'Mark this booking as completed?');
                doAction(id, act, msg);
                var m = bootstrap.Modal.getInstance(document.getElementById('bookingModal'));
                if (m) m.hide();
            });
        });
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('bookingModal')).show();
    }

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
        closeMenu(btn);
        if (act === 'view') openView(id);
        else if (act === 'confirm') doAction(id, 'confirm', 'Confirm this booking?');
        else if (act === 'cancel') doAction(id, 'cancel', 'Cancel this booking?');
        else if (act === 'complete') doAction(id, 'complete', 'Mark this booking as completed?');
    });

    document.querySelectorAll('th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = th.getAttribute('data-sort');
            if (state.sort === col) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
            else { state.sort = col; state.dir = (col === 'tourist' || col === 'destination') ? 'asc' : 'desc'; }
            state.page = 1;
            load();
        });
    });

    function resetPage() { state.page = 1; load(); }

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
        state.status = ''; state.search = ''; state.date_from = ''; state.date_to = '';
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

    document.getElementById('bookingsRefresh').addEventListener('click', function () { load(); });

    updateSortIndicators();
    load();
})();
</script>

<?php }); ?>
