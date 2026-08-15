<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/ActivityLog.php';
require_role('admin');

$db = Database::getInstance()->getConnection();
$activityLogModel = new ActivityLog();

$userFilter = $_GET['user'] ?? '';
$actionFilter = $_GET['action'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');
$csrf = $_SESSION['csrf_token'] ?? generate_token();

$actionConfigs = [
    'login'                  => ['icon' => 'fa-right-to-bracket',   'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'auth'],
    'logout'                 => ['icon' => 'fa-right-from-bracket', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'cat' => 'auth'],
    'register'               => ['icon' => 'fa-user-plus',          'color' => '#8b5cf6', 'bg' => '#ede9fe', 'cat' => 'user'],
    'profile_updated'        => ['icon' => 'fa-user-edit',          'color' => '#3b82f6', 'bg' => '#dbeafe', 'cat' => 'user'],
    'avatar_updated'         => ['icon' => 'fa-camera',             'color' => '#06b6d4', 'bg' => '#cffafe', 'cat' => 'user'],
    'password_changed'       => ['icon' => 'fa-key',                'color' => '#f59e0b', 'bg' => '#fef3c7', 'cat' => 'user'],
    'status_changed'         => ['icon' => 'fa-toggle-on',          'color' => '#8b5cf6', 'bg' => '#ede9fe', 'cat' => 'user'],
    'user_update'            => ['icon' => 'fa-user-cog',           'color' => '#3b82f6', 'bg' => '#dbeafe', 'cat' => 'admin'],
    'user_add'               => ['icon' => 'fa-user-plus',          'color' => '#3b82f6', 'bg' => '#dbeafe', 'cat' => 'admin'],
    'user_status_change'     => ['icon' => 'fa-user-shield',        'color' => '#f59e0b', 'bg' => '#fef3c7', 'cat' => 'admin'],
    'user_delete'            => ['icon' => 'fa-user-minus',         'color' => '#ef4444', 'bg' => '#fee2e2', 'cat' => 'admin'],
    'user_sessions_revoked'  => ['icon' => 'fa-right-from-bracket', 'color' => '#8b5cf6', 'bg' => '#ede9fe', 'cat' => 'admin'],
    'id_verification_reviewed' => ['icon' => 'fa-id-card',          'color' => '#8b5cf6', 'bg' => '#ede9fe', 'cat' => 'verify'],
    'id_verification_submitted' => ['icon' => 'fa-id-card',         'color' => '#8b5cf6', 'bg' => '#ede9fe', 'cat' => 'verify'],
    'notification_sent'      => ['icon' => 'fa-paper-plane',        'color' => '#06b6d4', 'bg' => '#cffafe', 'cat' => 'system'],
    'notification_resent'    => ['icon' => 'fa-paper-plane',        'color' => '#06b6d4', 'bg' => '#cffafe', 'cat' => 'system'],
    'logs_clear'             => ['icon' => 'fa-broom',              'color' => '#f59e0b', 'bg' => '#fef3c7', 'cat' => 'system'],
    'logs_export'            => ['icon' => 'fa-download',           'color' => '#06b6d4', 'bg' => '#cffafe', 'cat' => 'system'],
    'staff_add'              => ['icon' => 'fa-user-tie',           'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'staff'],
    'staff_edit'             => ['icon' => 'fa-user-pen',           'color' => '#3b82f6', 'bg' => '#dbeafe', 'cat' => 'staff'],
    'staff_status_change'    => ['icon' => 'fa-user-gear',          'color' => '#f59e0b', 'bg' => '#fef3c7', 'cat' => 'staff'],
    'staff_remove'           => ['icon' => 'fa-user-minus',         'color' => '#ef4444', 'bg' => '#fee2e2', 'cat' => 'staff'],
    'destination_add'        => ['icon' => 'fa-map-location-dot',   'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'content'],
    'destination_edit'       => ['icon' => 'fa-map-location-dot',   'color' => '#3b82f6', 'bg' => '#dbeafe', 'cat' => 'content'],
    'destination_delete'     => ['icon' => 'fa-map-location-dot',   'color' => '#ef4444', 'bg' => '#fee2e2', 'cat' => 'content'],
    'event_add'              => ['icon' => 'fa-calendar-plus',      'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'content'],
    'event_publish'          => ['icon' => 'fa-calendar-check',     'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'content'],
    'event_unpublish'        => ['icon' => 'fa-calendar-xmark',     'color' => '#f59e0b', 'bg' => '#fef3c7', 'cat' => 'content'],
    'guide_add'              => ['icon' => 'fa-person-hiking',      'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'content'],
    'review_hide'            => ['icon' => 'fa-eye-slash',          'color' => '#f59e0b', 'bg' => '#fef3c7', 'cat' => 'moderation'],
    'review_unhide'          => ['icon' => 'fa-eye',                'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'moderation'],
    'booking_created'        => ['icon' => 'fa-ticket',             'color' => '#3b82f6', 'bg' => '#dbeafe', 'cat' => 'booking'],
    'booking_cancelled'      => ['icon' => 'fa-circle-xmark',       'color' => '#ef4444', 'bg' => '#fee2e2', 'cat' => 'booking'],
    'payment_completed'      => ['icon' => 'fa-credit-card',        'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'payment'],
];

function log_action_config(string $action, array $configs): array
{
    foreach ($configs as $key => $cfg) {
        if (str_contains($action, $key)) return $cfg;
    }
    if (str_contains($action, 'delete') || str_contains($action, 'remove')) return ['icon' => 'fa-trash', 'color' => '#ef4444', 'bg' => '#fee2e2', 'cat' => 'danger'];
    if (str_contains($action, 'create') || str_contains($action, 'add')) return ['icon' => 'fa-plus-circle', 'color' => '#10b981', 'bg' => '#d1fae5', 'cat' => 'create'];
    if (str_contains($action, 'update') || str_contains($action, 'edit') || str_contains($action, 'change')) return ['icon' => 'fa-pen', 'color' => '#f59e0b', 'bg' => '#fef3c7', 'cat' => 'update'];
    return ['icon' => 'fa-circle', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'cat' => 'other'];
}

function logs_build_where(array $f): array
{
    $where = [];
    $params = [];
    if (!empty($f['user'])) { $where[] = "al.user_id = :user_id"; $params[':user_id'] = (int)$f['user']; }
    if (!empty($f['action'])) { $where[] = "al.action = :action"; $params[':action'] = $f['action']; }
    if (!empty($f['date_from'])) { $where[] = "al.created_at >= :date_from"; $params[':date_from'] = $f['date_from'] . ' 00:00:00'; }
    if (!empty($f['date_to'])) { $where[] = "al.created_at <= :date_to"; $params[':date_to'] = $f['date_to'] . ' 23:59:59'; }
    if (!empty($f['search'])) {
        $where[] = "(al.details LIKE :s1 OR al.action LIKE :s2 OR u.name LIKE :s3 OR u.email LIKE :s4 OR al.ip_address LIKE :s5)";
        $params[':s1'] = "%{$f['search']}%"; $params[':s2'] = "%{$f['search']}%"; $params[':s3'] = "%{$f['search']}%"; $params[':s4'] = "%{$f['search']}%"; $params[':s5'] = "%{$f['search']}%";
    }
    return [$where, $params];
}

function logs_stats(PDO $db): array
{
    return [
        'total'   => (int)$db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn(),
        'today'   => (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = db_curdate()")->fetchColumn(),
        'week'    => (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= db_date_sub(, 'INTERVAL  ')")->fetchColumn(),
        'users'   => (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs")->fetchColumn(),
        'actions' => (int)$db->query("SELECT COUNT(DISTINCT action) FROM activity_logs")->fetchColumn(),
        'old'     => (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE created_at < db_date_sub(, 'INTERVAL  ')")->fetchColumn(),
    ];
}

// ── Export (GET ?export=csv|json) ───────────────────────────
if (isset($_GET['export'])) {
    $fmt = $_GET['export'] === 'json' ? 'json' : 'csv';
    [$where, $params] = logs_build_where(['user' => $userFilter, 'action' => $actionFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'search' => $search]);
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare("SELECT al.id, al.action, al.details, al.ip_address, al.created_at, u.name as user_name, u.email as user_email FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id $whereClause ORDER BY al.created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    ActivityLog::log($_SESSION['user_id'], 'logs_export', "Exported activity logs as " . strtoupper($fmt) . " (" . count($rows) . " rows)");

    if ($fmt === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="activity_logs.json"');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'User', 'Email', 'Action', 'Details', 'IP', 'Timestamp']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['user_name'], $r['user_email'], $r['action'], $r['details'], $r['ip_address'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

// ── AJAX GET (?ajax=1) ──────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 20);
    if (!in_array($perPage, [10, 20, 50, 100], true)) $perPage = 20;

    [$where, $params] = logs_build_where(['user' => $userFilter, 'action' => $actionFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'search' => $search]);
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) as c FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) $qPage = $pages;
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare(
        "SELECT al.id, al.user_id, al.action, al.details, al.ip_address, al.created_at,
                u.name as user_name, u.email as user_email, u.role as user_role, u.avatar as user_avatar
         FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id
         $whereClause ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = array_map(function ($l) use ($actionConfigs) {
        $ac = log_action_config($l['action'], $actionConfigs);
        $ipInfo = null;
        if (!empty($l['ip_address'])) {
            $g = get_ip_info($l['ip_address']);
            if ($g) $ipInfo = ['ip' => $g['ip'], 'color' => $g['color'], 'icon' => $g['icon'], 'label' => $g['label']];
        }
        return [
            'id'         => (int)$l['id'],
            'user_id'    => $l['user_id'] !== null ? (int)$l['user_id'] : null,
            'user_name'  => $l['user_name'] ?? 'System',
            'user_email' => $l['user_email'] ?? '',
            'user_role'  => $l['user_role'] ?? '',
            'avatar_url' => get_avatar_url(['name' => $l['user_name'] ?? 'System', 'email' => $l['user_email'] ?? '', 'avatar' => $l['user_avatar'] ?? '']),
            'action'     => $l['action'],
            'action_label' => ucwords(str_replace('_', ' ', $l['action'])),
            'cfg'        => $ac,
            'details'    => $l['details'] ?? '',
            'ip'         => $l['ip_address'] ?? '',
            'ip_info'    => $ipInfo,
            'created_at' => $l['created_at'],
        ];
    }, $stmt->fetchAll());

    echo json_encode([
        'rows'     => $rows,
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $qPage,
        'per_page' => $perPage,
        'stats'    => logs_stats($db),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST actions ────────────────────────────────────────────
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
        redirect('/admin/activity_logs.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_log') {
        $logId = (int)($_POST['log_id'] ?? 0);
        if (!$logId) $respond(false, 'Invalid log ID.');
        $db->prepare("DELETE FROM activity_logs WHERE id = :id")->execute([':id' => $logId]);
        ActivityLog::log($_SESSION['user_id'], 'logs_clear', "Deleted log #{$logId}");
        $respond(true, 'Log entry deleted.');
    }

    if ($action === 'clear_old_logs') {
        $stmt = $db->prepare("DELETE FROM activity_logs WHERE created_at < db_date_sub(, 'INTERVAL  ')");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        ActivityLog::log($_SESSION['user_id'], 'logs_clear', "Cleared {$deleted} logs older than 90 days");
        $respond(true, "Cleared {$deleted} logs older than 90 days.");
    }

    if ($action === 'delete_all_logs') {
        $db->exec("DELETE FROM activity_logs");
        $respond(true, 'All activity logs have been cleared.');
    }

    $respond(false, 'Unknown action.');
}

$stats = logs_stats($db);
$users = $db->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll();
$actionTypes = $db->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC")->fetchAll();

render_page('admin', 'activity_logs.php', 'Activity Logs', function () use ($stats, $users, $actionTypes, $actionConfigs, $userFilter, $actionFilter, $dateFrom, $dateTo, $search, $csrf) {
?>
<style>
    .kpi-card { border: 1px solid var(--border-color); border-radius: 14px; background: var(--card-bg); cursor: default; }
    .kpi-card .kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; }
    .sticky-filter { position: sticky; top: 70px; z-index: 30; }
    .search-wrap { position: relative; }
    .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .85rem; }
    .search-wrap input { padding-left: 34px; }
    .filter-chip { font-size: .75rem; background: rgba(12,110,94,.1); color: var(--brand); border-radius: 20px; padding: 2px 10px; display: inline-flex; align-items: center; gap: 6px; }
    .table thead th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
    .avatar { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; flex-shrink: 0; }
    .skeleton { background: linear-gradient(90deg, rgba(130,130,130,.08) 25%, rgba(130,130,130,.18) 37%, rgba(130,130,130,.08) 63%); background-size: 400% 100%; animation: shimmer 1.4s ease infinite; border-radius: 8px; }
    @keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
    .action-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; white-space: nowrap; }
    .ip-tag { font-family: Consolas, 'SF Mono', monospace; font-size: .78rem; padding: 3px 10px; border-radius: 6px; background: var(--border-color); color: var(--text-muted); white-space: nowrap; }
    .detail-text { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .toast-container { z-index: 9999; }
    .pager { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .retention-note { font-size: .75rem; color: var(--text-muted); }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
        <h4 class="mb-1 fw-bold">Activity Logs <span class="badge bg-brand-subtle text-brand align-middle ms-1" id="totalBadge"><?= number_format($stats['total']) ?></span></h4>
        <div class="text-muted small">Track system activities across <strong id="usersBadge"><?= number_format($stats['users']) ?></strong> unique users · <strong id="actionsBadge"><?= number_format($stats['actions']) ?></strong> action types.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="refreshBtn" title="Refresh"><i class="fa-regular fa-rotate-right"></i></button>
        <div class="btn-group">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="fa-solid fa-download me-1"></i>Export</button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="#" onclick="exportLogs('csv');return false;"><i class="fa-solid fa-file-csv me-2 text-success"></i>CSV</a></li>
                <li><a class="dropdown-item" href="#" onclick="exportLogs('json');return false;"><i class="fa-solid fa-file-code me-2 text-primary"></i>JSON</a></li>
            </ul>
        </div>
        <div class="btn-group">
            <button class="btn btn-outline-danger btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="fa-solid fa-shield-halved me-1"></i>Maintenance</button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="#" onclick="clearOld();return false;"><i class="fa-solid fa-broom me-2 text-warning"></i>Clear Old (90+ days)</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="#" onclick="clearAll();return false;"><i class="fa-solid fa-trash me-2 text-danger"></i>Delete All Logs</a></li>
            </ul>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="kpi-card p-3"><div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-primary-subtle text-primary"><i class="fa-solid fa-list-ul"></i></div><div><div class="fs-4 fw-bold" id="kpi-total"><?= number_format($stats['total']) ?></div><div class="text-muted small">Total Logs</div></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3"><div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-success-subtle text-success"><i class="fa-solid fa-calendar-day"></i></div><div><div class="fs-4 fw-bold" id="kpi-today"><?= number_format($stats['today']) ?></div><div class="text-muted small">Today</div></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3"><div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-warning-subtle text-warning"><i class="fa-solid fa-calendar-week"></i></div><div><div class="fs-4 fw-bold" id="kpi-week"><?= number_format($stats['week']) ?></div><div class="text-muted small">This Week</div></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3"><div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-info-subtle text-info"><i class="fa-solid fa-users"></i></div><div><div class="fs-4 fw-bold" id="kpi-users"><?= number_format($stats['users']) ?></div><div class="text-muted small">Users Active</div></div></div></div></div>
</div>

<div class="sticky-filter mb-3">
    <div class="card shadow-sm">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search details, action, user, IP..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"></div>
                </div>
                <div class="col-md-2"><select id="userFilter" class="form-select form-select-sm"><option value="">All Users</option></select></div>
                <div class="col-md-2"><select id="actionFilter" class="form-select form-select-sm"><option value="">All Actions</option></select></div>
                <div class="col-md-1"><input type="date" id="dateFrom" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>"></div>
                <div class="col-md-1"><input type="date" id="dateTo" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>"></div>
                <div class="col-md-1"><select id="perPage" class="form-select form-select-sm"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></div>
                <div class="col-md-2 d-flex gap-1 justify-content-end">
                    <button class="btn btn-outline-secondary btn-sm" id="clearFilters">Clear</button>
                    <button class="btn btn-brand btn-sm" id="applyFilters"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                </div>
            </div>
            <div id="chipRow" class="mt-2 d-flex gap-1 flex-wrap"></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:55px">ID</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>IP</th>
                    <th>Timestamp</th>
                    <th class="text-center" style="width:50px"></th>
                </tr>
            </thead>
            <tbody id="logsBody"></tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="text-muted small" id="footerInfo">Loading...</div>
        <div class="pager" id="pager"></div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-3">
        <span class="retention-note"><i class="fa-solid fa-database me-1 text-brand"></i><strong>Log retention:</strong> logs older than 90 days are deletable via Maintenance.</span>
        <span class="badge bg-warning-subtle text-warning" id="oldBadge"><?= number_format($stats['old']) ?> logs older than 90 days</span>
        <span class="retention-note ms-auto"><i class="fa-solid fa-bolt me-1 text-success"></i><span id="weekInline"><?= number_format($stats['week']) ?></span> in last 7 days</span>
    </div>
</div>

<!-- Confirm modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="fs-1 text-danger mb-2"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <h6 class="fw-bold mb-1" id="confirmTitle">Are you sure?</h6>
                <div class="text-muted small" id="confirmMsg"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="confirmOk"><i class="fa-solid fa-check me-1"></i>Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const USERS = <?= json_encode(array_map(fn($u) => ['id' => (int)$u['id'], 'name' => $u['name']], $users)) ?>;
const ACTIONS = <?= json_encode(array_map(fn($a) => $a['action'], $actionTypes)) ?>;
const CFG = <?= json_encode($actionConfigs) ?>;
const state = { page: 1, per_page: 20, search: <?= json_encode($search) ?>, user: <?= json_encode($userFilter) ?>, action: <?= json_encode($actionFilter) ?>, date_from: <?= json_encode($dateFrom) ?>, date_to: <?= json_encode($dateTo) ?>, total: 0, pages: 1, loading: false };
let pendingConfirm = null;
let debounceTimer = null;

const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);

function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function timeAgo(d) {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    if (isNaN(dt)) return d;
    const s = Math.floor((Date.db_now() - dt) / 1000);
    if (s < 60) return 'just now';
    const m = Math.floor(s / 60); if (m < 60) return m + 'm ago';
    const h = Math.floor(m / 60); if (h < 24) return h + 'h ago';
    const day = Math.floor(h / 24); if (day < 7) return day + 'd ago';
    return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}
function fmtStamp(d) {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) + ' · ' + dt.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
}
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (type === 'error' ? 'danger' : type) + ' border-0 show';
    el.innerHTML = '<div class="d-flex"><div class="toast-body">' + esc(msg) + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    document.querySelector('.toast-container').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3200 }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
function pickCfg(action) {
    for (const k in CFG) if (action.includes(k)) return CFG[k];
    if (action.includes('delete') || action.includes('remove')) return { icon: 'fa-trash', color: '#ef4444', bg: '#fee2e2' };
    if (action.includes('create') || action.includes('add')) return { icon: 'fa-plus-circle', color: '#10b981', bg: '#d1fae5' };
    if (action.includes('update') || action.includes('edit') || action.includes('change')) return { icon: 'fa-pen', color: '#f59e0b', bg: '#fef3c7' };
    return { icon: 'fa-circle', color: '#6b7280', bg: '#f3f4f6' };
}
function qs() {
    const p = new URLSearchParams();
    if (state.search) p.set('search', state.search);
    if (state.user) p.set('user', state.user);
    if (state.action) p.set('action', state.action);
    if (state.date_from) p.set('date_from', state.date_from);
    if (state.date_to) p.set('date_to', state.date_to);
    p.set('page', state.page);
    p.set('per_page', state.per_page);
    return p.toString();
}
function exportLogs(fmt) {
    window.location.href = '/Tourism/admin/activity_logs.php?export=' + fmt + '&' + qs();
}
function skeletonRows(n) {
    let h = '';
    for (let i = 0; i < n; i++) h += '<tr><td><span class="skeleton" style="width:40px;height:14px;display:inline-block"></span></td><td><div class="d-flex align-items-center gap-2"><span class="skeleton avatar"></span><div><div class="skeleton" style="width:100px;height:10px"></div><div class="skeleton mt-1" style="width:130px;height:8px"></div></div></div></td><td><span class="skeleton" style="width:120px;height:22px;display:inline-block"></span></td><td><span class="skeleton" style="width:200px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:90px;height:16px;display:inline-block"></span></td><td><span class="skeleton" style="width:110px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:28px;height:28px;display:inline-block"></span></td></tr>';
    return h;
}
function applyStats(s) {
    if (!s) return;
    const fmt = n => n.toLocaleString('en-US');
    $('#kpi-total').textContent = fmt(s.total); $('#kpi-today').textContent = fmt(s.today);
    $('#kpi-week').textContent = fmt(s.week); $('#kpi-users').textContent = fmt(s.users);
    $('#totalBadge').textContent = fmt(s.total); $('#usersBadge').textContent = fmt(s.users); $('#actionsBadge').textContent = fmt(s.actions);
    $('#oldBadge').textContent = fmt(s.old) + ' logs older than 90 days';
    $('#weekInline').textContent = fmt(s.week);
}
function render(rows) {
    const body = $('#logsBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-5"><i class="fa-solid fa-clipboard-list fa-2x d-block mb-2"></i>No activity logs found. Try adjusting your filters.</td></tr>';
        renderFooter(); return;
    }
    let h = '';
    rows.forEach(l => {
        const ip = l.ip_info || null;
        h += '<tr>'
            + '<td class="small text-muted fw-bold">#' + l.id + '</td>'
            + '<td><div class="d-flex align-items-center gap-2"><img src="' + esc(l.avatar_url) + '" class="avatar" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'"><div><div class="fw-semibold small">' + esc(l.user_name) + '</div><div class="small text-muted">' + esc(l.user_email || '') + '</div></div></div></td>'
            + '<td><span class="action-chip" style="background:' + l.cfg.bg + ';color:' + l.cfg.color + '"><i class="fa-solid ' + l.cfg.icon + '"></i>' + esc(l.action_label) + '</span></td>'
            + '<td><div class="detail-text small" title="' + esc(l.details) + '">' + esc(l.details || '-') + '</div></td>'
            + '<td>' + (ip ? '<div class="d-flex align-items-center gap-2"><span class="ip-tag">' + esc(ip.ip) + '</span><span class="badge" style="background:' + ip.color + '18;color:' + ip.color + '"><i class="fa-solid ' + ip.icon + ' me-1"></i>' + esc(ip.label) + '</span></div>' : '<span class="ip-tag">N/A</span>') + '</td>'
            + '<td class="small" title="' + esc(l.created_at) + '">' + fmtStamp(l.created_at) + '<div class="small text-muted">' + timeAgo(l.created_at) + '</div></td>'
            + '<td class="text-center"><button class="btn btn-sm btn-outline-danger" title="Delete log" onclick="askDelete(' + l.id + ')"><i class="fa-solid fa-trash"></i></button></td></tr>';
    });
    body.innerHTML = h;
    renderFooter();
}
function renderFooter() {
    const from = state.total === 0 ? 0 : (state.page - 1) * state.per_page + 1;
    const to = Math.min(state.page * state.per_page, state.total);
    $('#footerInfo').textContent = 'Showing ' + from + '–' + to + ' of ' + state.total + ' logs';
    const p = $('#pager'); p.innerHTML = '';
    const mk = (label, page, disabled, active) => {
        const b = document.createElement('button');
        b.className = 'btn btn-sm ' + (active ? 'btn-brand' : 'btn-outline-secondary') + (disabled ? ' disabled' : '');
        b.innerHTML = label;
        if (!disabled) b.onclick = () => { state.page = page; load(); };
        p.appendChild(b);
    };
    mk('<i class="fa-solid fa-angles-left"></i>', 1, state.page === 1);
    mk('<i class="fa-solid fa-chevron-left"></i>', state.page - 1, state.page === 1);
    for (let i = 1; i <= state.pages; i++) {
        if (i === 1 || i === state.pages || Math.abs(i - state.page) <= 1) mk(String(i), i, false, i === state.page);
        else if (Math.abs(i - state.page) === 2) mk('…', i, true);
    }
    mk('<i class="fa-solid fa-chevron-right"></i>', state.page + 1, state.page === state.pages);
    mk('<i class="fa-solid fa-angles-right"></i>', state.pages, state.page === state.pages);
}
async function load() {
    if (state.loading) return;
    $('#logsBody').innerHTML = skeletonRows(10);
    state.loading = true;
    try {
        const r = await fetch('/Tourism/admin/activity_logs.php?ajax=1&' + qs());
        const d = await r.json();
        state.total = d.total; state.pages = d.pages; state.page = d.page; state.per_page = d.per_page;
        applyStats(d.stats);
        render(d.rows);
    } catch {
        $('#logsBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Failed to load logs.</td></tr>';
    } finally { state.loading = false; }
}
function onSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { state.search = $('#searchInput').value.trim(); state.page = 1; load(); }, 400);
}
function applyFilters() {
    state.user = $('#userFilter').value; state.action = $('#actionFilter').value;
    state.date_from = $('#dateFrom').value; state.date_to = $('#dateTo').value;
    state.search = $('#searchInput').value.trim();
    state.page = 1;
    renderChips(); load();
}
function clearFilters() {
    state.user = state.action = state.date_from = state.date_to = state.search = '';
    $('#userFilter').value = $('#actionFilter').value = ''; $('#dateFrom').value = $('#dateTo').value = ''; $('#searchInput').value = '';
    renderChips(); load();
}
function renderChips() {
    const w = $('#chipRow');
    const parts = [];
    if (state.user) { const u = USERS.find(x => String(x.id) === String(state.user)); parts.push('<span class="filter-chip">User: ' + esc(u ? u.name : state.user) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>'); }
    if (state.action) parts.push('<span class="filter-chip">Action: ' + esc(state.action.replace(/_/g, ' ')) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    if (state.date_from) parts.push('<span class="filter-chip">From: ' + esc(state.date_from) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    if (state.date_to) parts.push('<span class="filter-chip">To: ' + esc(state.date_to) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    if (state.search) parts.push('<span class="filter-chip">Search: ' + esc(state.search) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    w.innerHTML = parts.join('');
}
function post(data, cb) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    fetch('/Tourism/admin/activity_logs.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json()).then(d => cb(d.ok, d.message)).catch(() => cb(false, 'Request failed.'));
}
function askConfirm(title, msg, fn) {
    $('#confirmTitle').textContent = title;
    $('#confirmMsg').textContent = msg;
    pendingConfirm = fn;
    bootstrap.Modal.getOrCreateInstance($('#confirmModal')).show();
}
$('#confirmOk').addEventListener('click', () => { if (pendingConfirm) { pendingConfirm(); pendingConfirm = null; } bootstrap.Modal.getInstance($('#confirmModal')).hide(); });
function askDelete(id) {
    askConfirm('Delete this log entry?', 'This removes the entry permanently.', () => {
        post({ action: 'delete_log', log_id: id, csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (ok) load(); });
    });
}
function clearOld() {
    askConfirm('Clear logs older than 90 days?', 'This action cannot be undone.', () => {
        post({ action: 'clear_old_logs', csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (ok) load(); });
    });
}
function clearAll() {
    askConfirm('Delete ALL activity logs?', 'Every entry will be permanently removed. This cannot be undone.', () => {
        post({ action: 'delete_all_logs', csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (ok) load(); });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const uSel = $('#userFilter');
    USERS.forEach(u => uSel.insertAdjacentHTML('beforeend', '<option value="' + u.id + '">' + esc(u.name) + '</option>'));
    uSel.value = state.user;
    const aSel = $('#actionFilter');
    ACTIONS.forEach(a => aSel.insertAdjacentHTML('beforeend', '<option value="' + esc(a) + '">' + esc(a.replace(/_/g, ' ')) + '</option>'));
    aSel.value = state.action;
    $('#perPage').value = state.per_page;
    $('#searchInput').addEventListener('input', onSearch);
    $('#applyFilters').addEventListener('click', applyFilters);
    $('#clearFilters').addEventListener('click', clearFilters);
    $('#refreshBtn').addEventListener('click', load);
    $('#perPage').addEventListener('change', () => { state.per_page = parseInt($('#perPage').value); state.page = 1; load(); });
    renderChips();
    load();
});
</script>
<?php }); ?>