<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/Notification.php';
require_role('admin');

$userModel = new User();
$db = Database::getInstance()->getConnection();
$maxStaff = 3;

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();

function staff_stats(PDO $db, int $maxStaff): array
{
    $s = ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0];
    foreach ($db->query("SELECT status, COUNT(*) as cnt FROM users WHERE role='staff' GROUP BY status") as $r) {
        $s['total'] += (int)$r['cnt'];
        if (isset($s[$r['status']])) $s[$r['status']] = (int)$r['cnt'];
    }
    $s['slots'] = max(0, $maxStaff - $s['total']);
    $s['capacity'] = $s['total'];
    return $s;
}

// ── AJAX GET (?ajax=1) ──────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;
    $qSearch = trim($_GET['search'] ?? '');
    $qStatus = $_GET['status'] ?? '';

    $where = ["u.role = 'staff'"];
    $params = [];
    if ($qSearch) {
        $where[] = "(u.name LIKE :s1 OR u.email LIKE :s2 OR u.phone LIKE :s3)";
        $params[':s1'] = "%$qSearch%"; $params[':s2'] = "%$qSearch%"; $params[':s3'] = "%$qSearch%";
    }
    if ($qStatus) { $where[] = "u.status = :status"; $params[':status'] = $qStatus; }
    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) as c FROM users u $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) $qPage = $pages;
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare(
        "SELECT u.*, COALESCE(a.cnt, 0) as activity_7d
         FROM users u
         LEFT JOIN (SELECT user_id, COUNT(*) cnt FROM activity_logs WHERE created_at >= db_date_sub(, 'INTERVAL  ') GROUP BY user_id) a ON a.user_id = u.id
         $whereClause ORDER BY u.name ASC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $rows = array_map(function ($s) {
        return [
            'id'          => (int)$s['id'],
            'username'    => $s['username'] ?? '',
            'name'        => $s['name'] ?? '',
            'email'       => $s['email'] ?? '',
            'phone'       => $s['phone'] ?? '',
            'gender'      => $s['gender'] ?? '',
            'age'         => $s['age'] ?? '',
            'status'      => $s['status'] ?? 'pending',
            'avatar_url'  => get_avatar_url($s),
            'created_at'  => $s['created_at'] ?? '',
            'activity_7d' => (int)$s['activity_7d'],
        ];
    }, $stmt->fetchAll());

    echo json_encode([
        'rows'  => $rows,
        'total' => $total,
        'pages' => $pages,
        'page'  => $qPage,
        'stats' => staff_stats($db, $GLOBALS['maxStaff']),
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
        redirect('/admin/staff.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_staff') {
        $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='staff'")->fetchColumn();
        if ($count >= $maxStaff) $respond(false, 'Staff limit reached (max 3). Remove a staff member to free a slot.');

        $result = register([
            'name'     => sanitize(trim($_POST['name'] ?? '')),
            'email'    => sanitize(trim($_POST['email'] ?? '')),
            'password' => $_POST['password'] ?? '',
            'role'     => 'staff',
            'phone'    => sanitize(trim($_POST['phone'] ?? '')),
            'gender'   => $_POST['gender'] === 'female' ? 'female' : 'male',
            'age'      => max(1, min(120, (int)($_POST['age'] ?? 18))),
        ]);
        if (!$result['success']) $respond(false, $result['message']);

        $newStaff = $userModel->findByEmail(sanitize(trim($_POST['email'] ?? '')));
        $newId = (int)$newStaff['id'];
        if (($_POST['status'] ?? 'pending') === 'active') $userModel->updateStatus($newId, 'active');

        ActivityLog::log($_SESSION['user_id'], 'staff_add', 'Added new staff member: ' . ($_POST['email'] ?? ''));
        $respond(true, 'Staff member added successfully.');
    }

    if ($action === 'edit_staff') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $existing = $staffId ? $userModel->findById($staffId) : null;
        if (!$existing || $existing['role'] !== 'staff') $respond(false, 'Staff member not found.');

        $name = sanitize(trim($_POST['name'] ?? ''));
        $email = sanitize(trim($_POST['email'] ?? ''));
        $phone = sanitize(trim($_POST['phone'] ?? ''));
        $gender = $_POST['gender'] === 'female' ? 'female' : 'male';
        $age = max(1, min(120, (int)($_POST['age'] ?? $existing['age'])));
        $status = in_array($_POST['status'] ?? 'active', ['active', 'pending', 'suspended', 'inactive'], true) ? $_POST['status'] : 'active';

        if (empty($name) || empty($email)) $respond(false, 'Name and email are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $respond(false, 'Invalid email address.');
        $dup = $userModel->findByEmail($email);
        if ($dup && (int)$dup['id'] !== $staffId) $respond(false, 'Email address is already taken by another user.');

        $data = ['name' => $name, 'email' => $email, 'phone' => $phone, 'gender' => $gender, 'age' => $age, 'status' => $status];
        $newPassword = trim($_POST['new_password'] ?? '');
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) $respond(false, 'Password must be at least 8 characters.');
            $data['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        $userModel->update($staffId, $data);
        ActivityLog::log($_SESSION['user_id'], 'staff_edit', "Updated staff member #{$staffId}: {$name}");
        $respond(true, 'Staff member updated successfully.');
    }

    if ($action === 'update_status' && isset($_POST['staff_id'], $_POST['new_status'])) {
        $staffId = (int)$_POST['staff_id'];
        $newStatus = in_array($_POST['new_status'], ['active', 'pending', 'suspended', 'inactive'], true) ? $_POST['new_status'] : '';
        if (!$newStatus) $respond(false, 'Invalid status.');
        $staff = $userModel->findById($staffId);
        if (!$staff || $staff['role'] !== 'staff') $respond(false, 'Staff member not found.');
        if ((int)$staffId === (int)$_SESSION['user_id'] && $newStatus === 'suspended') $respond(false, 'You cannot suspend your own account.');
        $userModel->updateStatus($staffId, $newStatus);
        (new Notification())->notifyRegistrationStatus($staffId, $newStatus);
        ActivityLog::log($_SESSION['user_id'], 'staff_status_change', "Changed staff #{$staffId} status to {$newStatus}");
        $respond(true, 'Staff status updated to ' . ucfirst($newStatus) . '.');
    }

    if ($action === 'remove_staff' && isset($_POST['staff_id'])) {
        $staffId = (int)$_POST['staff_id'];
        if ((int)$staffId === (int)$_SESSION['user_id']) $respond(false, 'You cannot remove your own account.');
        $staff = $userModel->findById($staffId);
        if (!$staff || $staff['role'] !== 'staff') $respond(false, 'Staff member not found.');
        $userModel->delete($staffId);
        ActivityLog::log($_SESSION['user_id'], 'staff_remove', "Removed staff member: {$staff['name']} ({$staff['email']})");
        $respond(true, 'Staff member removed.');
    }

    $respond(false, 'Unknown action.');
}

$stats = staff_stats($db, $maxStaff);

render_page('admin', 'staff.php', 'Staff Management', function () use ($stats, $maxStaff, $search, $statusFilter, $csrf) {

$statusBadges = ['active' => 'bg-success-subtle text-success', 'pending' => 'bg-warning-subtle text-warning', 'suspended' => 'bg-danger-subtle text-danger', 'inactive' => 'bg-secondary-subtle text-secondary'];
?>
<style>
    .page-hero { background: linear-gradient(135deg, rgba(12,110,94,.92), rgba(6,95,70,.96)); color: #fff; border-radius: 20px; padding: 26px 30px; margin-bottom: 1.25rem; position: relative; overflow: hidden; }
    .page-hero::after { content: ''; position: absolute; right: -60px; top: -60px; width: 240px; height: 240px; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,.1), transparent 70%); }
    .kpi-card { border: 1px solid var(--border-color); border-radius: 14px; background: var(--card-bg); cursor: pointer; transition: transform .15s, box-shadow .15s; }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.06); }
    .kpi-card.active { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(12,110,94,.15); }
    .kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; }
    .capacity-bar { height: 8px; border-radius: 10px; background: rgba(255,255,255,.25); overflow: hidden; }
    .capacity-bar .fill { height: 100%; background: #fff; border-radius: 10px; transition: width .4s ease; }
    .skeleton { background: linear-gradient(90deg, rgba(130,130,130,.08) 25%, rgba(130,130,130,.18) 37%, rgba(130,130,130,.08) 63%); background-size: 400% 100%; animation: shimmer 1.4s ease infinite; border-radius: 8px; }
    @keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
    .avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
    .sticky-filter { position: sticky; top: 70px; z-index: 30; }
    .search-wrap { position: relative; }
    .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .85rem; }
    .search-wrap input { padding-left: 34px; }
    .filter-chip { font-size: .75rem; background: rgba(12,110,94,.1); color: var(--brand); border-radius: 20px; padding: 2px 10px; display: inline-flex; align-items: center; gap: 6px; }
    .toast-container { z-index: 9999; }
    .offcanvas { --bs-offcanvas-width: 460px; }
    .activity-badge { font-size: .72rem; border-radius: 20px; padding: 3px 10px; background: rgba(25,135,84,.12); color: #198754; border: 1px solid rgba(25,135,84,.2); }
    .pager { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
</style>

<div class="page-hero">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 position-relative" style="z-index:1">
        <div>
            <h4 class="fw-bold mb-1"><i class="fa-solid fa-user-shield me-2"></i>Staff Management</h4>
            <p class="mb-0 small opacity-75">Manage your team, capacity and staff accounts.</p>
        </div>
        <div style="min-width:260px;max-width:360px">
            <div class="d-flex justify-content-between small mb-1">
                <span class="opacity-75">Staff capacity</span>
                <span class="fw-bold"><?= $stats['capacity'] ?>/<?= $maxStaff ?> slots used</span>
            </div>
            <div class="capacity-bar"><div class="fill" id="capFill" style="width:<?= min(100, ($stats['capacity'] / $maxStaff) * 100) ?>%"></div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3" id="kpiRow">
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-primary-subtle text-primary"><i class="fa-solid fa-users"></i></div><div><div class="fs-4 fw-bold" id="kpi-total"><?= $stats['total'] ?></div><div class="text-muted small">Total Staff</div></div></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-status="active">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-success-subtle text-success"><i class="fa-solid fa-user-check"></i></div><div><div class="fs-4 fw-bold" id="kpi-active"><?= $stats['active'] ?></div><div class="text-muted small">Active</div></div></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-status="pending">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-warning-subtle text-warning"><i class="fa-solid fa-clock"></i></div><div><div class="fs-4 fw-bold" id="kpi-pending"><?= $stats['pending'] ?></div><div class="text-muted small">Pending</div></div></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-secondary-subtle text-secondary"><i class="fa-solid fa-user-plus"></i></div><div><div class="fs-4 fw-bold" id="kpi-slots"><?= $stats['slots'] ?></div><div class="text-muted small">Slots Available</div></div></div>
    </div></div>
</div>

<?php if ($stats['capacity'] >= $maxStaff): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3"><i class="fa-solid fa-triangle-exclamation"></i><span class="small fw-semibold">Staff limit reached (<?= $stats['capacity'] ?>/<?= $maxStaff ?>). Remove an existing staff member to free a slot.</span></div>
<?php elseif ($stats['capacity'] >= $maxStaff - 1): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3"><i class="fa-solid fa-triangle-exclamation"></i><span class="small fw-semibold">Approaching staff limit (<?= $stats['capacity'] ?>/<?= $maxStaff ?>). Only <?= $maxStaff - $stats['capacity'] ?> slot remaining.</span></div>
<?php endif; ?>

<div class="sticky-filter mb-3">
    <div class="card shadow-sm">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search name, email or phone..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"></div>
                </div>
                <div class="col-md-3"><select id="statusFilter" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select></div>
                <div class="col-md-4 d-flex gap-1 justify-content-end">
                    <button class="btn btn-outline-secondary btn-sm" id="refreshBtn" title="Refresh"><i class="fa-regular fa-rotate-right"></i></button>
                    <button class="btn btn-outline-secondary btn-sm" id="clearFilters">Clear</button>
                    <button class="btn btn-brand btn-sm" id="addStaffBtn" <?= $stats['capacity'] >= $maxStaff ? 'disabled' : '' ?>><i class="fa-solid fa-user-plus me-1"></i>Add Staff</button>
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
                    <th style="width:70px">ID</th>
                    <th>Staff Member</th>
                    <th>Phone</th>
                    <th>Gender / Age</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Activity (7d)</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="staffBody"></tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="text-muted small" id="footerInfo">Loading...</div>
        <div class="pager" id="pager"></div>
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

<!-- Staff drawer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="staffDrawer">
    <div class="offcanvas-header border-bottom">
        <h6 class="offcanvas-title fw-bold" id="drawerTitle">Add Staff</h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <form id="staffForm">
            <input type="hidden" id="f_staff_id" value="">
            <div class="mb-3">
                <label class="form-label small">Full Name *</label>
                <input type="text" id="f_name" class="form-control" placeholder="Maria Santos">
            </div>
            <div class="mb-3">
                <label class="form-label small">Email *</label>
                <input type="email" id="f_email" class="form-control" placeholder="staff@example.com">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small">Gender</label>
                    <select id="f_gender" class="form-select"><option value="male">Male</option><option value="female">Female</option></select>
                </div>
                <div class="col-6">
                    <label class="form-label small">Age</label>
                    <input type="number" id="f_age" class="form-control" min="1" max="120" value="18">
                </div>
                <div class="col-6">
                    <label class="form-label small">Phone</label>
                    <input type="text" id="f_phone" class="form-control" placeholder="+639XXXXXXXXX">
                </div>
                <div class="col-6" id="f_status_wrap">
                    <label class="form-label small">Status</label>
                    <select id="f_status" class="form-select">
                        <option value="active">Active</option>
                        <option value="pending" selected>Pending</option>
                        <option value="suspended">Suspended</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small" id="f_pass_label">Password * <span class="text-muted fw-normal">(min 8 chars)</span></label>
                <div class="input-group">
                    <input type="text" id="f_password" class="form-control" placeholder="Enter password" autocomplete="new-password">
                    <button type="button" class="btn btn-outline-secondary" onclick="genPassword()" title="Generate strong password"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
                </div>
                <div class="form-text text-brand small" id="genPassMsg"></div>
            </div>
        </form>
    </div>
    <div class="offcanvas-footer border-top p-3 d-flex gap-2">
        <button class="btn btn-outline-secondary flex-fill" data-bs-dismiss="offcanvas">Cancel</button>
        <button class="btn btn-brand flex-fill" onclick="saveStaff()"><i class="fa-solid fa-floppy-disk me-1"></i>Save Staff</button>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const MAX_STAFF = <?= $maxStaff ?>;
const STATUS_BADGES = <?= json_encode($statusBadges) ?>;
const state = { page: 1, search: '', status: '', total: 0, pages: 1, loading: false };
const __staff = {};
let pendingConfirm = null;
let debounceTimer = null;

const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);

function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function fmtDate(d) { if (!d) return '—'; const dt = new Date(d.replace(' ', 'T')); return isNaN(dt) ? d : dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }); }
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (type === 'error' ? 'danger' : type) + ' border-0 show';
    el.innerHTML = '<div class="d-flex"><div class="toast-body">' + esc(msg) + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    document.querySelector('.toast-container').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3200 }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
function statusBadge(st) {
    const m = { active: ['bg-success-subtle text-success', 'fa-circle-check'], suspended: ['bg-danger-subtle text-danger', 'fa-circle-minus'], pending: ['bg-warning-subtle text-warning', 'fa-clock'], inactive: ['bg-secondary-subtle text-secondary', 'fa-circle'] };
    const c = m[st] || m.inactive;
    return '<span class="badge ' + c[0] + '"><i class="fa-solid ' + c[1] + ' me-1"></i>' + esc(st) + '</span>';
}
function qs() {
    const p = new URLSearchParams();
    if (state.search) p.set('search', state.search);
    if (state.status) p.set('status', state.status);
    p.set('page', state.page);
    return p.toString();
}
function skeletonRows(n) {
    let h = '';
    for (let i = 0; i < n; i++) h += '<tr><td><span class="skeleton" style="width:44px;height:16px;display:inline-block"></span></td><td><div class="d-flex align-items-center gap-2"><span class="skeleton avatar"></span><div><div class="skeleton" style="width:120px;height:10px"></div><div class="skeleton mt-1" style="width:150px;height:8px"></div></div></div></td><td><span class="skeleton" style="width:110px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:70px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:70px;height:18px;display:inline-block"></span></td><td><span class="skeleton" style="width:90px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:80px;height:18px;display:inline-block"></span></td><td class="text-end"><span class="skeleton" style="width:90px;height:18px;display:inline-block"></span></td></tr>';
    return h;
}
function applyStats(s) {
    if (!s) return;
    $('#kpi-total').textContent = s.total; $('#kpi-active').textContent = s.active;
    $('#kpi-pending').textContent = s.pending; $('#kpi-slots').textContent = s.slots;
    $('#capFill').style.width = Math.min(100, (s.capacity / MAX_STAFF) * 100) + '%';
    $$('.kpi-card').forEach(k => k.classList.remove('active'));
    if (state.status) { const k = document.querySelector('.kpi-card[data-status="' + state.status + '"]'); if (k) k.classList.add('active'); }
    const full = s.capacity >= MAX_STAFF;
    const addBtn = $('#addStaffBtn'); if (addBtn) addBtn.disabled = full;
}
function render(rows) {
    const body = $('#staffBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5"><i class="fa-solid fa-user-slash fa-2x d-block mb-2"></i>No staff members found.</td></tr>';
        renderFooter(); return;
    }
    let h = '';
    rows.forEach(s => {
        __staff[s.id] = s;
        h += '<tr data-id="' + s.id + '">'
            + '<td><span class="small font-monospace text-muted" style="background:var(--border-color);padding:3px 9px;border-radius:6px">#' + s.id + '</span></td>'
            + '<td><div class="d-flex align-items-center gap-2"><img src="' + esc(s.avatar_url) + '" class="avatar" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'"><div><div class="fw-semibold">' + esc(s.name) + '</div><div class="small text-muted">@' + esc(s.username || '—') + ' · ' + esc(s.email) + '</div></div></div></td>'
            + '<td class="small">' + esc(s.phone || 'N/A') + '</td>'
            + '<td class="small">' + esc(s.gender ? s.gender.charAt(0).toUpperCase() + s.gender.slice(1) : '—') + ' / ' + esc(s.age || '—') + '</td>'
            + '<td><select class="form-select form-select-sm status-sel" data-id="' + s.id + '" style="min-width:110px">'
            + ['active', 'pending', 'suspended', 'inactive'].map(x => '<option value="' + x + '" ' + (x === s.status ? 'selected' : '') + '>' + x + '</option>').join('')
            + '</select></td>'
            + '<td class="small">' + fmtDate(s.created_at) + '</td>'
            + '<td>' + (s.activity_7d > 0 ? '<span class="activity-badge"><i class="fa-solid fa-bolt me-1"></i>' + s.activity_7d + ' actions</span>' : '<span class="small text-muted">No activity</span>') + '</td>'
            + '<td class="text-end"><div class="btn-group btn-group-sm row-actions">'
            + '<button class="btn btn-outline-secondary" title="Edit staff" onclick="openDrawer(' + s.id + ')"><i class="fa-solid fa-pen"></i></button>'
            + '<button class="btn btn-outline-danger" title="Remove staff" onclick="askRemove(' + s.id + ')"><i class="fa-solid fa-trash"></i></button>'
            + '</div></td></tr>';
    });
    body.innerHTML = h;
    renderFooter();
}
function renderFooter() {
    const from = state.total === 0 ? 0 : (state.page - 1) * 15 + 1;
    const to = Math.min(state.page * 15, state.total);
    $('#footerInfo').textContent = 'Showing ' + from + '–' + to + ' of ' + state.total + ' staff';
    const p = $('#pager'); p.innerHTML = '';
    const mk = (label, page, disabled, active) => {
        const b = document.createElement('button');
        b.className = 'btn btn-sm ' + (active ? 'btn-brand' : 'btn-outline-secondary') + (disabled ? ' disabled' : '');
        b.innerHTML = label;
        if (!disabled) b.onclick = () => { state.page = page; load(); };
        p.appendChild(b);
    };
    mk('<i class="fa-solid fa-chevron-left"></i>', state.page - 1, state.page === 1);
    for (let i = 1; i <= state.pages; i++) {
        if (i === 1 || i === state.pages || Math.abs(i - state.page) <= 1) mk(String(i), i, false, i === state.page);
        else if (Math.abs(i - state.page) === 2) mk('…', i, true);
    }
    mk('<i class="fa-solid fa-chevron-right"></i>', state.page + 1, state.page === state.pages);
}
async function load() {
    if (state.loading) return;
    $('#staffBody').innerHTML = skeletonRows(5);
    state.loading = true;
    try {
        const r = await fetch('/Tourism/admin/staff.php?ajax=1&' + qs());
        const d = await r.json();
        state.total = d.total; state.pages = d.pages; state.page = d.page;
        applyStats(d.stats);
        render(d.rows);
    } catch {
        $('#staffBody').innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Failed to load staff.</td></tr>';
    } finally { state.loading = false; }
}
function onSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { state.search = $('#searchInput').value.trim(); state.page = 1; load(); }, 400);
}
function applyFilters() {
    state.status = $('#statusFilter').value;
    state.search = $('#searchInput').value.trim();
    state.page = 1;
    renderChips(); load();
}
function clearFilters() {
    state.status = state.search = '';
    $('#statusFilter').value = ''; $('#searchInput').value = '';
    renderChips(); load();
}
function renderChips() {
    const w = $('#chipRow');
    if (!state.status && !state.search) { w.innerHTML = ''; return; }
    let h = '';
    if (state.status) h += '<span class="filter-chip">Status: ' + esc(state.status) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>';
    if (state.search) h += '<span class="filter-chip">Search: ' + esc(state.search) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>';
    w.innerHTML = h;
}
function post(data, cb) {
    const fd = new FormData();
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    fetch('/Tourism/admin/staff.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json()).then(d => cb(d.ok, d.message, d)).catch(() => cb(false, 'Request failed.'));
}
function askConfirm(title, msg, fn) {
    $('#confirmTitle').textContent = title;
    $('#confirmMsg').textContent = msg;
    pendingConfirm = fn;
    bootstrap.Modal.getOrCreateInstance($('#confirmModal')).show();
}
$('#confirmOk').addEventListener('click', () => { if (pendingConfirm) { pendingConfirm(); pendingConfirm = null; } bootstrap.Modal.getInstance($('#confirmModal')).hide(); });

document.addEventListener('change', (e) => {
    if (e.target.classList.contains('status-sel')) {
        const id = parseInt(e.target.dataset.id);
        const st = e.target.value;
        const s = __staff[id];
        askConfirm('Set ' + (s ? s.name : '#' + id) + ' to ' + st + '?', 'Status change will notify the staff member.', () => {
            post({ action: 'update_status', staff_id: id, new_status: st, csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); load(); });
        });
    }
});
function askRemove(id) {
    const s = __staff[id];
    askConfirm('Remove staff "' + (s ? s.name : '#' + id) + '"?', 'This permanently removes the staff account and frees a slot.', () => {
        post({ action: 'remove_staff', staff_id: id, csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (ok) load(); });
    });
}
function genPassword() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    let p = '';
    for (let i = 0; i < 12; i++) p += chars[Math.floor(Math.random() * chars.length)];
    $('#f_password').value = p;
    $('#genPassMsg').textContent = 'Generated strong password. Click save to apply.';
}
function openDrawer(id) {
    const f = $('#staffForm'); f.reset();
    $('#f_staff_id').value = ''; $('#f_status').value = 'pending'; $('#f_age').value = '18';
    $('#drawerTitle').textContent = 'Add Staff';
    $('#f_pass_label').innerHTML = 'Password * <span class="text-muted fw-normal">(min 8 chars)</span>';
    $('#f_password').placeholder = 'Enter password';
    $('#genPassMsg').textContent = '';
    $('#f_status_wrap').classList.remove('d-none');
    if (id) {
        const s = __staff[id]; if (!s) return;
        $('#f_staff_id').value = s.id;
        $('#drawerTitle').textContent = 'Edit Staff — ' + s.name;
        $('#f_name').value = s.name; $('#f_email').value = s.email;
        $('#f_phone').value = s.phone || ''; $('#f_gender').value = s.gender;
        $('#f_age').value = s.age || 18; $('#f_status').value = s.status;
        $('#f_pass_label').innerHTML = 'New Password <span class="text-muted fw-normal">(leave blank to keep)</span>';
        $('#f_password').placeholder = 'Leave blank to keep current password';
    }
    bootstrap.Offcanvas.getOrCreateInstance($('#staffDrawer')).show();
}
function saveStaff() {
    const id = $('#f_staff_id').value;
    const name = $('#f_name').value.trim(), email = $('#f_email').value.trim(), pass = $('#f_password').value;
    if (!name || !email) { toast('Name and email are required.', 'error'); return; }
    if (!id && pass.length < 8) { toast('Password must be at least 8 characters.', 'error'); return; }
    if (id && pass.length > 0 && pass.length < 8) { toast('Password must be at least 8 characters.', 'error'); return; }
    const data = { csrf_token: CSRF, action: id ? 'edit_staff' : 'add_staff' };
    if (id) data.staff_id = id;
    data.name = name; data.email = email; data.password = pass;
    data.phone = $('#f_phone').value.trim(); data.gender = $('#f_gender').value;
    data.age = $('#f_age').value; data.status = $('#f_status').value;
    post(data, (ok, msg) => {
        toast(msg, ok ? 'success' : 'error');
        if (ok) { bootstrap.Offcanvas.getInstance($('#staffDrawer')).hide(); load(); }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    $('#searchInput').addEventListener('input', onSearch);
    $('#statusFilter').addEventListener('change', applyFilters);
    $('#clearFilters').addEventListener('click', clearFilters);
    $('#refreshBtn').addEventListener('click', load);
    $('#addStaffBtn').addEventListener('click', () => openDrawer());
    $$('.kpi-card').forEach(k => k.addEventListener('click', () => {
        const st = k.dataset.status;
        if (!st) return;
        state.status = state.status === st ? '' : st;
        state.page = 1;
        $('#statusFilter').value = state.status;
        renderChips(); load();
    }));
    state.search = <?= json_encode($search) ?>;
    state.status = <?= json_encode($statusFilter) ?>;
    renderChips();
    load();
});
</script>
<?php }); ?>