<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');
require_once __DIR__ . '/../includes/classes/Payment.php';

$db = Database::getInstance()->getConnection();
$paymentModel = new Payment();

$statusFilter = $_GET['status'] ?? '';
$methodFilter = $_GET['method'] ?? '';
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();
$stats = $paymentModel->getStats();

// ── AJAX data endpoint (GET ?ajax=1) ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 15;
    $qSearch = trim($_GET['search'] ?? '');
    $qStatus = $_GET['status'] ?? '';
    $qMethod = $_GET['method'] ?? '';
    $qFrom = $_GET['date_from'] ?? '';
    $qTo = $_GET['date_to'] ?? '';
    $qSort = $_GET['sort'] ?? 'date';
    $qDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $sortMap = [
        'id' => 'p.id',
        'ref' => 'p.reference_number',
        'tourist' => 'u.name',
        'amount' => 'p.total_amount',
        'status' => 'p.payment_status',
        'date' => 'p.created_at',
    ];
    $orderBy = ($sortMap[$qSort] ?? 'p.created_at') . ' ' . $qDir . ', p.id DESC';

    $where = [];
    $params = [];
    if ($qSearch !== '') {
        $where[] = '(p.reference_number LIKE :q1 OR u.name LIKE :q2 OR u.email LIKE :q3 OR p.transaction_id LIKE :q4)';
        $params[':q1'] = "%{$qSearch}%"; $params[':q2'] = "%{$qSearch}%"; $params[':q3'] = "%{$qSearch}%"; $params[':q4'] = "%{$qSearch}%";
    }
    if ($qStatus !== '') { $where[] = 'p.payment_status = :status'; $params[':status'] = $qStatus; }
    if ($qMethod !== '') { $where[] = 'p.payment_method = :method'; $params[':method'] = $qMethod; }
    if ($qFrom !== '') { $where[] = 'p.created_at >= :from'; $params[':from'] = $qFrom . ' 00:00:00'; }
    if ($qTo !== '') { $where[] = 'p.created_at <= :to'; $params[':to'] = $qTo . ' 23:59:59'; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $base = 'FROM payments p LEFT JOIN users u ON p.tourist_id = u.id
        LEFT JOIN bookings b ON p.booking_id = b.id
        LEFT JOIN schedules s ON b.schedule_id = s.id
        LEFT JOIN events e ON s.event_id = e.id
        LEFT JOIN destinations d ON e.destination_id = d.id
        LEFT JOIN destinations d2 ON b.destination_id = d2.id';

    $countStmt = $db->prepare("SELECT COUNT(*) as c {$base} {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) { $qPage = $pages; }
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare("SELECT p.id, p.reference_number, p.total_amount, p.payment_method, p.payment_status,
        p.transaction_id, p.payment_date, p.created_at, u.name as tourist_name, u.email as tourist_email,
        COALESCE(e.title, d2.name) as event_title, COALESCE(d2.name, d.name) as destination_name, b.num_participants
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
            'total'           => (int)($stats['total'] ?? 0),
            'total_revenue'   => round((float)($stats['total_revenue'] ?? 0), 2),
            'pending_count'   => (int)($stats['pending_count'] ?? 0),
            'paid_count'      => (int)($stats['paid_count'] ?? 0),
            'failed_count'    => (int)($stats['failed_count'] ?? 0),
            'refunded_count'  => (int)($stats['refunded_count'] ?? 0),
            'monthly_revenue' => round((float)($stats['monthly_revenue'] ?? 0), 2),
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
        redirect('/admin/payments.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';
    $pid = (int)($_POST['payment_id'] ?? 0);

    if ($action === 'update_status' && $pid) {
        $newStatus = in_array($_POST['new_status'] ?? '', ['pending', 'paid', 'failed', 'refunded'], true) ? $_POST['new_status'] : '';
        if (!$newStatus) $respond(false, 'Invalid status.');
        $paymentModel->updateStatus($pid, $newStatus);
        ActivityLog::log($_SESSION['user_id'], 'payment_status', "Set payment #{$pid} status to {$newStatus}");
        $respond(true, 'Payment status updated to ' . ucfirst($newStatus) . '.');
    }

    $respond(false, 'Unknown action.');
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Reference', 'Tourist', 'Email', 'Event', 'Method', 'Status', 'Amount', 'Date']);
    $eWhere = [];
    $eParams = [];
    if ($search !== '') { $eWhere[] = '(p.reference_number LIKE :q1 OR u.name LIKE :q2 OR u.email LIKE :q3 OR p.transaction_id LIKE :q4)'; $eParams[':q1'] = "%$search%"; $eParams[':q2'] = "%$search%"; $eParams[':q3'] = "%$search%"; $eParams[':q4'] = "%$search%"; }
    if ($statusFilter !== '') { $eWhere[] = 'p.payment_status = :status'; $eParams[':status'] = $statusFilter; }
    if ($methodFilter !== '') { $eWhere[] = 'p.payment_method = :method'; $eParams[':method'] = $methodFilter; }
    if ($dateFrom !== '') { $eWhere[] = 'p.created_at >= :from'; $eParams[':from'] = $dateFrom . ' 00:00:00'; }
    if ($dateTo !== '') { $eWhere[] = 'p.created_at <= :to'; $eParams[':to'] = $dateTo . ' 23:59:59'; }
    $eWhereClause = $eWhere ? 'WHERE ' . implode(' AND ', $eWhere) : '';
    $stmt = $db->prepare("SELECT p.id, p.reference_number, u.name, u.email, COALESCE(e.title, d2.name) as evt, p.payment_method, p.payment_status, p.total_amount, p.created_at
        FROM payments p LEFT JOIN users u ON p.tourist_id = u.id
        LEFT JOIN bookings b ON p.booking_id = b.id LEFT JOIN schedules s ON b.schedule_id = s.id
        LEFT JOIN events e ON s.event_id = e.id LEFT JOIN destinations d ON e.destination_id = d.id
        LEFT JOIN destinations d2 ON b.destination_id = d2.id $eWhereClause ORDER BY p.created_at DESC");
    $stmt->execute($eParams);
    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, [$r['id'], $r['reference_number'], $r['name'], $r['email'], $r['evt'], $r['payment_method'], $r['payment_status'], $r['total_amount'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$statusTypes = $db->query("SELECT DISTINCT payment_status FROM payments ORDER BY payment_status")->fetchAll(PDO::FETCH_COLUMN);
$methodTypes = $db->query("SELECT DISTINCT payment_method FROM payments ORDER BY payment_method")->fetchAll(PDO::FETCH_COLUMN);

render_page('admin', 'payments.php', 'Payment Management', function () use ($statusFilter, $methodFilter, $search, $dateFrom, $dateTo, $stats, $statusTypes, $methodTypes, $csrf) {
?>

<style>
.page-hero{background:linear-gradient(135deg,rgba(12,110,94,.9) 0%,rgba(6,95,70,.95) 100%);color:#fff;border-radius:20px;padding:32px 36px;margin-bottom:1.5rem;position:relative;overflow:hidden}.page-hero::before{content:'';position:absolute;top:-50%;right:-15%;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.page-hero h4{font-weight:800;margin-bottom:4px;position:relative;z-index:1}.page-hero p{opacity:.85;font-size:.9rem;position:relative;z-index:1;margin-bottom:0}
.stat-card{border:none;border-radius:16px;overflow:hidden;transition:all .3s;background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9)}.stat-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.stat-card .stat-bar{height:4px;width:100%}.stat-card .stat-body{padding:18px 16px;text-align:center}.stat-card .stat-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}.stat-card .stat-value{font-size:1.6rem;font-weight:800;line-height:1;margin-bottom:4px}.stat-card .stat-label{font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px;margin-bottom:1rem}.filter-card .form-control,.filter-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}.filter-card .form-label{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.sticky-filter{position:sticky;top:74px;z-index:1015;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.filter-input-wrap{position:relative}.filter-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted,#94a3b8);font-size:.82rem;pointer-events:none}.filter-input{padding-left:34px}
.filter-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(12,110,94,.08);border:1px solid rgba(12,110,94,.25);color:#0c6e5e;font-size:.78rem;font-weight:600;padding:4px 10px;border-radius:50px}[data-theme="dark"] .filter-chip{background:rgba(16,185,129,.12);color:#5eead4;border-color:rgba(16,185,129,.3)}.filter-chip .chip-x{border:none;background:none;color:inherit;font-size:1rem;line-height:1;padding:0 0 0 2px;cursor:pointer;opacity:.7}.filter-chip .chip-x:hover{opacity:1}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden}.logs-table{border-collapse:separate;border-spacing:0;min-width:960px}.logs-table thead th{background:var(--card-bg,#f8fafc);border-bottom:2px solid var(--border-color,#e2e8f0);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#64748b);padding:14px 16px}.logs-table tbody tr{transition:all .15s}.logs-table tbody tr:hover{background:rgba(12,110,94,.02)}.logs-table tbody td{padding:14px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);vertical-align:middle;font-size:.88rem;color:var(--text-primary,#1e293b)}
.logs-table th.sortable{cursor:pointer;user-select:none;white-space:nowrap;transition:color .2s}.logs-table th.sortable:hover{color:#0c6e5e}.logs-table th.sortable.active{color:#0c6e5e}.logs-table th.sortable .th-arrow{margin-left:6px;font-size:.7rem;color:var(--text-muted,#94a3b8)}.logs-table th.sortable.active .th-arrow{color:#0c6e5e}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
.row-id{font-family:'SF Mono',Consolas,monospace;font-size:.78rem;padding:3px 10px;border-radius:6px;background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b)}.cell-main{font-weight:600;font-size:.88rem}.cell-sub{font-size:.75rem;color:var(--text-muted,#94a3b8)}
.action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#475569);transition:all .2s;padding:0}.action-btn:hover{border-color:#0c6e5e;color:#0c6e5e;background:rgba(12,110,94,.05)}.action-btn.danger:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.05)}
.act-menu{border-radius:12px;border:1px solid var(--border-color,#e2e8f0);box-shadow:0 12px 32px rgba(0,0,0,.12);padding:6px;min-width:200px;z-index:1050}.act-menu .dropdown-item{border-radius:8px;font-size:.85rem;font-weight:500;padding:8px 12px;color:var(--text-primary,#1e293b)}.act-menu .dropdown-item:hover{background:rgba(12,110,94,.06)}.act-menu .dropdown-divider{margin:4px 0;border-color:var(--border-color,#e2e8f0)}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted,#94a3b8)}.empty-state .empty-icon{width:56px;height:56px;border-radius:14px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}.empty-state h6{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b);margin-bottom:4px}.empty-state p{font-size:.82rem;margin:0}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px;cursor:pointer}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}.pagination .page-item.disabled .page-link{cursor:default}
.skel{position:relative;overflow:hidden;height:14px;border-radius:6px;background:var(--border-color,#e2e8f0)}.skel::after{content:'';position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);animation:shimmer 1.3s infinite}@keyframes shimmer{to{transform:translateX(100%)}}
.modal-content{border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff)}.modal-header{border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px}.modal-header .modal-title{font-weight:700;font-size:1rem;color:var(--text-primary,#1e293b)}.modal-body{padding:24px}.modal-footer{border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px}
.detail-card{background:var(--card-bg,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px}.detail-card .label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#94a3b8);margin-bottom:4px}.detail-card .value{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b)}
.app-toast{position:fixed;top:calc(var(--topbar-height) + 14px);right:24px;z-index:9999;display:flex;align-items:center;gap:8px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-left:4px solid #10b981;border-radius:12px;padding:12px 18px;font-size:.88rem;font-weight:600;color:var(--text-primary,#1e293b);box-shadow:0 12px 32px rgba(0,0,0,.15);opacity:0;transform:translateY(-8px);pointer-events:none;transition:all .3s}.app-toast.show{opacity:1;transform:translateY(0)}.app-toast.danger{border-left-color:#ef4444}
@media (max-width: 991.98px){.sticky-filter{top:12px}}
</style>

<div class="page-hero">
    <h4><i class="fas fa-credit-card me-2"></i>Payment Management</h4>
    <p id="paymentsHeroInfo"><?= (int)($stats['total'] ?? 0) ?> transaction<?= (int)($stats['total'] ?? 0) !== 1 ? 's' : '' ?> · Total revenue: ₱<?= number_format((float)($stats['total_revenue'] ?? 0), 2) ?></p>
</div>

<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['id'=>'kpiTotal','val'=>$stats['total']??0, 'label'=>'Total Transactions','icon'=>'fa-credit-card','color'=>'#3b82f6','bg'=>'#dbeafe'],
        ['id'=>'kpiRevenue','val'=>'₱'.number_format($stats['total_revenue']??0,2), 'label'=>'Total Revenue','icon'=>'fa-peso-sign','color'=>'#10b981','bg'=>'#d1fae5'],
        ['id'=>'kpiPending','val'=>$stats['pending_count']??0, 'label'=>'Pending','icon'=>'fa-clock','color'=>'#f59e0b','bg'=>'#fef3c7'],
        ['id'=>'kpiMonth','val'=>'₱'.number_format($stats['monthly_revenue']??0,2), 'label'=>'This Month','icon'=>'fa-calendar-check','color'=>'#8b5cf6','bg'=>'#ede9fe'],
    ];
    foreach ($statCards as $sc): ?>
    <div class="col-xl-3 col-md-6">
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
                <input type="text" id="filterSearch" class="form-control filter-input" placeholder="Ref #, name, txn ID..." value="<?= sanitize($search) ?>">
            </div>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Status</label>
            <select id="filterStatus" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (['paid','pending','failed','refunded'] as $st): ?>
                <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Method</label>
            <select id="filterMethod" class="form-select">
                <option value="">All Methods</option>
                <?php foreach (['gcash','maya','card','cash','other'] as $mt): ?>
                <option value="<?= $mt ?>" <?= $methodFilter === $mt ? 'selected' : '' ?>><?= ucfirst($mt) ?></option>
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
        <div class="col-12 col-md-1 d-flex justify-content-md-end">
            <button type="button" class="btn btn-sm w-100" id="clearFilters" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-muted,#64748b);"><i class="fas fa-times me-1"></i>Clear</button>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-3" id="paymentsChips" style="display:none;"></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div><span class="small fw-semibold" style="color:var(--text-muted,#64748b);" id="paymentsCount"></span></div>
    <div class="d-flex gap-2 align-items-center">
        <select id="perPage" class="form-select form-select-sm" style="width:auto;border-radius:10px;border-color:var(--border-color,#e2e8f0);">
            <option value="10">10 / page</option>
            <option value="15" selected>15 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
        </select>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="exportBtn" title="Export CSV"><i class="fas fa-download me-1"></i>Export</button>
        <button type="button" class="btn btn-sm action-btn" id="paymentsRefresh" title="Refresh"><i class="fas fa-rotate"></i></button>
    </div>
</div>

<div class="table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table logs-table align-middle mb-0">
                <thead><tr>
                    <th class="sortable" data-sort="ref">Reference <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="tourist">Tourist <i class="fas fa-sort th-arrow"></i></th>
                    <th>Event</th>
                    <th class="sortable" data-sort="amount">Amount <i class="fas fa-sort th-arrow"></i></th>
                    <th>Method</th>
                    <th class="sortable" data-sort="status">Status <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="date">Date <i class="fas fa-sort th-arrow"></i></th>
                    <th class="text-center">Actions</th>
                </tr></thead>
                <tbody id="paymentsBody">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <tr><?php for ($c = 0; $c < 8; $c++): ?><td><div class="skel"></div></td><?php endfor; ?></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<nav class="mt-3" id="paymentsPager"></nav>

<div class="modal fade" id="paymentModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-receipt me-2" style="color:#0c6e5e;"></i>Transaction Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-2">
            <div class="col-6"><div class="detail-card"><div class="label">Reference</div><div class="value" id="pmRef" style="font-family:'SF Mono',Consolas,monospace;font-size:.8rem;"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Status</div><div class="value" id="pmStatus"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Tourist</div><div class="value" id="pmTourist"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Event</div><div class="value" id="pmEvent" style="font-size:.85rem;"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Method</div><div class="value" id="pmMethod"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Date</div><div class="value" id="pmDate" style="font-size:.85rem;"></div></div></div>
            <div class="col-12"><div class="detail-card"><div class="label">Transaction ID</div><div class="value" id="pmTxn" style="font-family:'SF Mono',Consolas,monospace;font-size:.8rem;">—</div></div></div>
        </div>
        <div class="mt-3" id="pmTotal" style="display:flex;justify-content:space-between;background:var(--card-bg,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:16px;"><span class="fw-bold" style="font-size:1.05rem;">Total</span><span class="fw-bold" style="font-size:1.05rem;color:#0c6e5e;" id="pmAmount"></span></div>
    </div>
    <div class="modal-footer d-flex justify-content-between">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm" id="pmMarkRefund" style="border:1px solid rgba(6,182,212,.4);color:#06b6d4;border-radius:10px;font-weight:600;"><i class="fas fa-rotate-left me-1"></i>Mark Refunded</button>
        </div>
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);">Close</button>
    </div>
</div></div></div>

<div class="app-toast" id="appToast"></div>

<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var INIT = <?= json_encode(['status' => $statusFilter, 'method' => $methodFilter, 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo], JSON_UNESCAPED_UNICODE) ?>;

    var state = {
        page: 1,
        per_page: 15,
        sort: 'date',
        dir: 'desc',
        status: INIT.status || '',
        method: INIT.method || '',
        search: INIT.search || '',
        date_from: INIT.date_from || '',
        date_to: INIT.date_to || ''
    };
    var timer = null;

    var $body = document.getElementById('paymentsBody');
    var $pager = document.getElementById('paymentsPager');
    var $count = document.getElementById('paymentsCount');
    var $chips = document.getElementById('paymentsChips');

    var PSC = { paid: ['#d1fae5', '#059669', 'fa-circle-check'], pending: ['#fef3c7', '#d97706', 'fa-clock'], failed: ['#fee2e2', '#dc2626', 'fa-circle-xmark'], refunded: ['#cffafe', '#0891b2', 'fa-rotate-left'] };
    var PMC = { gcash: ['#007dfe', 'GCash'], maya: ['#00c853', 'Maya'], card: ['#6366f1', 'Card'], cash: ['#10b981', 'Cash'], other: ['#6b7280', 'Other'] };

    function esc(s) { s = (s == null) ? '' : String(s); var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function trunc(s, n) { s = s || ''; return s.length > n ? s.slice(0, n) + '\u2026' : s; }
    function fmtDate(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
    function money(v) { return '\u20b1' + (parseFloat(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function statusChip(st) { var c = PSC[st] || ['#e2e8f0', '#475569', 'fa-circle']; return '<span class="status-chip" style="background:' + c[0] + ';color:' + c[1] + ';"><i class="fas ' + c[2] + ' me-1"></i>' + (st ? st.charAt(0).toUpperCase() + st.slice(1) : 'N/A') + '</span>'; }
    function methodChip(m) { var c = PMC[m] || ['#6b7280', m]; return '<span class="status-chip" style="background:' + c[0] + '18;color:' + c[0] + ';"><i class="fas fa-wallet me-1"></i>' + c[1] + '</span>'; }

    function qs() {
        var p = new URLSearchParams();
        p.set('ajax', '1');
        p.set('page', state.page);
        p.set('per_page', state.per_page);
        p.set('sort', state.sort);
        p.set('dir', state.dir);
        if (state.status) p.set('status', state.status);
        if (state.method) p.set('method', state.method);
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

    function renderRows(rows) {
        window.__payments = {};
        if (!rows || !rows.length) {
            $body.innerHTML = '<tr><td colspan="8" class="empty-state"><div class="empty-icon" style="background:rgba(59,130,246,.1);color:#3b82f6;"><i class="fas fa-credit-card"></i></div><h6>No payments found</h6><p>Try adjusting your filters.</p></td></tr>';
            return;
        }
        var h = '';
        for (var k = 0; k < rows.length; k++) {
            var r = rows[k];
            window.__payments[r.id] = r;
            var menu = '<div class="dropdown">' +
                '<button class="action-btn" data-bs-toggle="dropdown" title="Actions"><i class="fas fa-ellipsis-vertical"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end act-menu">' +
                '<li><button class="dropdown-item" data-act="view" data-id="' + r.id + '"><i class="fas fa-eye me-2"></i>View details</button></li>' +
                (r.payment_status !== 'refunded' ? '<li><button class="dropdown-item" data-act="refund" data-id="' + r.id + '"><i class="fas fa-rotate-left me-2" style="color:#06b6d4;"></i>Mark refunded</button></li>' : '') +
                '</ul></div>';
            h += '<tr>' +
                '<td><span class="row-id">' + esc(r.reference_number) + '</span></td>' +
                '<td><div class="cell-main">' + (esc(r.tourist_name) || 'N/A') + '</div><div class="cell-sub">' + esc(r.tourist_email) + '</div></td>' +
                '<td><div class="cell-main">' + (esc(r.event_title) || 'N/A') + '</div></td>' +
                '<td><span class="fw-bold" style="color:#0c6e5e;font-size:.9rem;">' + money(r.total_amount) + '</span></td>' +
                '<td>' + methodChip(r.payment_method) + '</td>' +
                '<td>' + statusChip(r.payment_status) + '</td>' +
                '<td><div class="cell-sub">' + esc(fmtDate(r.created_at)) + '</div></td>' +
                '<td class="text-center">' + menu + '</td>' +
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
            ? total + ' transaction' + (total !== 1 ? 's' : '') + ' found'
            : 'Showing ' + shown + ' of ' + total + ' transaction' + (total !== 1 ? 's' : '');
    }

    function updateStats(s) {
        if (!s) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
        set('kpiTotal', s.total);
        set('kpiRevenue', money(s.total_revenue));
        set('kpiPending', s.pending_count);
        set('kpiMonth', money(s.monthly_revenue));
        set('paymentsHeroInfo', s.total + ' transaction' + (s.total !== 1 ? 's' : '') + ' \u00b7 Total revenue: ' + money(s.total_revenue));
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
        if (state.method) add('Method: ' + state.method.charAt(0).toUpperCase() + state.method.slice(1), function () { state.method = ''; document.getElementById('filterMethod').value = ''; });
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
        fetch('payments.php?' + qs())
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
                $body.innerHTML = '<tr><td colspan="8" class="empty-state"><div class="empty-icon" style="background:rgba(239,68,68,.1);color:#ef4444;"><i class="fas fa-triangle-exclamation"></i></div><h6>Could not load payments</h6><p>Please try again.</p></td></tr>';
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

    function doAction(id, action, newStatus, confirmMsg) {
        if (!window.confirm(confirmMsg)) return;
        var fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        fd.append('payment_id', id);
        if (newStatus) fd.append('new_status', newStatus);
        fetch('payments.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) { toast(d.message || 'Done.', 'success'); load(); }
                else { toast((d && d.message) || 'Action failed.', 'danger'); }
            })
            .catch(function () { toast('Request failed. Check your connection.', 'danger'); });
    }

    function openView(id) {
        var r = window.__payments[id];
        if (!r) return;
        document.getElementById('pmRef').textContent = r.reference_number || 'N/A';
        document.getElementById('pmStatus').innerHTML = statusChip(r.payment_status);
        document.getElementById('pmTourist').textContent = r.tourist_name || 'N/A';
        document.getElementById('pmEvent').textContent = r.event_title || 'N/A';
        document.getElementById('pmMethod').innerHTML = methodChip(r.payment_method);
        document.getElementById('pmDate').textContent = fmtDate(r.created_at);
        document.getElementById('pmTxn').textContent = r.transaction_id || '—';
        document.getElementById('pmAmount').textContent = money(r.total_amount);
        document.getElementById('pmMarkRefund').style.display = r.payment_status === 'refunded' ? 'none' : '';
        document.getElementById('pmMarkRefund').setAttribute('data-id', id);
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('paymentModal')).show();
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
        else if (act === 'refund') doAction(id, 'update_status', 'refunded', 'Mark this payment as refunded?');
    });

    document.getElementById('pmMarkRefund').addEventListener('click', function (e) {
        var b = e.currentTarget;
        doAction(b.getAttribute('data-id'), 'update_status', 'refunded', 'Mark this payment as refunded?');
        var m = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
        if (m) m.hide();
    });

    document.querySelectorAll('th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = th.getAttribute('data-sort');
            if (state.sort === col) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
            else { state.sort = col; state.dir = (col === 'tourist') ? 'asc' : 'desc'; }
            state.page = 1;
            load();
        });
    });

    function resetPage() { state.page = 1; load(); }

    document.getElementById('filterStatus').addEventListener('change', function () { state.status = this.value; resetPage(); });
    document.getElementById('filterMethod').addEventListener('change', function () { state.method = this.value; resetPage(); });
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
        state.status = ''; state.method = ''; state.search = ''; state.date_from = ''; state.date_to = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterMethod').value = '';
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

    document.getElementById('exportBtn').addEventListener('click', function () {
        var p = new URLSearchParams(qs());
        p.delete('ajax');
        p.set('export', 'csv');
        window.location = 'payments.php?' + p.toString();
    });

    document.getElementById('paymentsRefresh').addEventListener('click', function () { load(); });

    updateSortIndicators();
    load();
})();
</script>

<?php }); ?>
