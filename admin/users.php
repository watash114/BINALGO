<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/Notification.php';
require_role('admin');

$userModel = new User();
$db = Database::getInstance()->getConnection();

$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();

$id_type_labels = [
    'passport' => 'Passport',
    'drivers_license' => "Driver's License",
    'national_id' => 'National ID',
    'voters_id' => "Voter's ID",
    'senior_citizen' => 'Senior Citizen ID',
    'other' => 'Other',
];

function users_page_stats(PDO $db): array
{
    $stats = ['total' => 0, 'admin' => 0, 'staff' => 0, 'guide' => 0, 'tourist' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0];
    foreach ($db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role") as $r) {
        $stats['total'] += (int)$r['cnt'];
        if (isset($stats[$r['role']])) $stats[$r['role']] = (int)$r['cnt'];
    }
    foreach ($db->query("SELECT status, COUNT(*) as cnt FROM users GROUP BY status") as $r) {
        if (isset($stats[$r['status']])) $stats[$r['status']] = (int)$r['cnt'];
    }
    $pv = $db->query("SELECT COUNT(*) FROM id_verifications WHERE status = 'pending'")->fetchColumn();
    return $stats + ['pending_verifications' => (int)$pv];
}

function verif_doc_url(?string $p): ?string
{
    if (empty($p)) return null;
    if (preg_match('~^https?://~', $p)) return $p;
    if ($p[0] === '/') return $p;
    return BASE_URL . '/uploads/' . $p;
}

function users_verif_map(PDO $db, array $userIds, array $labels): array
{
    $map = [];
    if (empty($userIds)) return $map;
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $db->prepare("SELECT user_id, status, id_type, admin_notes, created_at, id_file_path FROM id_verifications WHERE user_id IN ({$ph}) ORDER BY created_at DESC");
    $stmt->execute(array_values($userIds));
    foreach ($stmt->fetchAll() as $v) {
        if (!isset($map[$v['user_id']])) {
            $map[$v['user_id']] = [
                'status'        => $v['status'],
                'id_type'       => $v['id_type'],
                'id_type_label' => $labels[$v['id_type']] ?? $v['id_type'],
                'created_at'    => $v['created_at'],
                'admin_notes'   => $v['admin_notes'],
                'doc_url'       => verif_doc_url($v['id_file_path']),
            ];
        }
    }
    return $map;
}

function users_row_payload(array $u, ?array $verif, int $selfId): array
{
    return [
        'id'             => (int)$u['id'],
        'username'       => $u['username'] ?? '',
        'name'           => $u['name'] ?? '',
        'email'          => $u['email'] ?? '',
        'phone'          => $u['phone'] ?? '',
        'gender'         => $u['gender'] ?? '',
        'age'            => $u['age'] ?? '',
        'role'           => $u['role'] ?? 'tourist',
        'status'         => $u['status'] ?? 'pending',
        'avatar_url'     => get_avatar_url($u),
        'created_at'     => $u['created_at'] ?? '',
        'last_login_at'  => $u['last_login_at'] ?? null,
        'last_active_ip' => $u['last_active_ip'] ?? null,
        'last_login_ip'  => $u['last_login_ip'] ?? null,
        'login_count'    => (int)($u['login_count'] ?? 0),
        'last_user_agent'=> $u['last_user_agent'] ?? null,
        'verif'          => $verif,
        'is_self'        => (int)$u['id'] === (int)$selfId,
    ];
}

// ── Export (GET ?export=csv|json) ───────────────────────────
if (isset($_GET['export'])) {
    $fmt = $_GET['export'] === 'json' ? 'json' : 'csv';
    $q = "SELECT id, username, name, email, role, status, phone, gender, age, created_at, last_login_at FROM users";
    $where = [];
    $params = [];
    if ($roleFilter)   { $where[] = "role = :role"; $params[':role'] = $roleFilter; }
    if ($statusFilter) { $where[] = "status = :status"; $params[':status'] = $statusFilter; }
    if ($search)       { $where[] = "(name LIKE :s1 OR email LIKE :s2 OR phone LIKE :s3)"; $params[':s1'] = "%$search%"; $params[':s2'] = "%$search%"; $params[':s3'] = "%$search%"; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare("$q $whereClause ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    ActivityLog::log($_SESSION['user_id'], 'logs_export', "Exported user list as " . strtoupper($fmt) . " (" . count($rows) . " rows)");

    if ($fmt === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="users.json"');
        echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Username', 'Name', 'Email', 'Role', 'Status', 'Phone', 'Gender', 'Age', 'Joined', 'Last Login']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['username'], $r['name'], $r['email'], $r['role'], $r['status'], $r['phone'], $r['gender'], $r['age'], $r['created_at'], $r['last_login_at']]);
    }
    fclose($out);
    exit;
}

// ── AJAX GET endpoint (?ajax=1) ─────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    // Profile activity history
    if (($_GET['mode'] ?? '') === 'activity') {
        $uid = (int)($_GET['user_id'] ?? 0);
        $user = $userModel->findById($uid);
        $verif = $user ? (users_verif_map($db, [$uid], $id_type_labels)[$uid] ?? null) : null;
        $activity = [];
        if ($user) {
            $stmt = $db->prepare("SELECT al.id, al.action, al.details, al.ip_address, al.created_at FROM activity_logs al WHERE al.user_id = :uid ORDER BY al.created_at DESC LIMIT 15");
            $stmt->execute([':uid' => $uid]);
            $activity = $stmt->fetchAll();
        }
        echo json_encode([
            'user'     => $user ? users_row_payload($user, $verif, $_SESSION['user_id']) : null,
            'activity' => $activity,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 15;
    $qRole = $_GET['role'] ?? '';
    $qStatus = $_GET['status'] ?? '';
    $qSearch = trim($_GET['search'] ?? '');
    $qSort = $_GET['sort'] ?? 'created';
    $qDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $sortMap = ['id' => 'u.id', 'name' => 'u.name', 'role' => 'u.role', 'status' => 'u.status', 'created' => 'u.created_at'];
    $orderBy = ($sortMap[$qSort] ?? 'u.created_at') . ' ' . $qDir . ', u.id DESC';

    $where = [];
    $params = [];
    if ($qRole)   { $where[] = "u.role = :role"; $params[':role'] = $qRole; }
    if ($qStatus) { $where[] = "u.status = :status"; $params[':status'] = $qStatus; }
    if ($qSearch) { $where[] = "(u.name LIKE :s1 OR u.email LIKE :s2 OR u.phone LIKE :s3)"; $params[':s1'] = "%$qSearch%"; $params[':s2'] = "%$qSearch%"; $params[':s3'] = "%$qSearch%"; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) as c FROM users u $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) $qPage = $pages;
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare("SELECT u.* FROM users u $whereClause ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $verifMap = users_verif_map($db, array_column($rows, 'id'), $id_type_labels);
    $payloadRows = array_map(function ($r) use ($verifMap) {
        return users_row_payload($r, $verifMap[$r['id']] ?? null, $_SESSION['user_id']);
    }, $rows);

    echo json_encode([
        'rows'     => $payloadRows,
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $qPage,
        'per_page' => $perPage,
        'stats'    => users_page_stats($db),
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
        redirect('/admin/users.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token. Please refresh and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name = sanitize(trim($_POST['name'] ?? ''));
        $email = sanitize(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $phone = sanitize(trim($_POST['phone'] ?? ''));
        $role = $_POST['role'] ?? 'tourist';
        $status = in_array($_POST['status'] ?? 'pending', ['active', 'pending', 'suspended', 'inactive'], true) ? $_POST['status'] : 'pending';
        $gender = $_POST['gender'] === 'female' ? 'female' : 'male';
        $age = max(1, min(120, (int)($_POST['age'] ?? 18)));

        if (empty($name) || empty($email) || empty($password)) $respond(false, 'Name, email, and password are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $respond(false, 'Invalid email address.');
        if (strlen($password) < 8) $respond(false, 'Password must be at least 8 characters.');
        if (!in_array($role, ['admin', 'staff', 'guide', 'tourist'], true)) $respond(false, 'Invalid role.');
        if ($userModel->findByEmail($email)) $respond(false, 'Email already registered.');
        if (($role === 'staff' || $role === 'admin') && !can_register_staff()) $respond(false, 'Staff/Admin registration limit reached.');

        $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0])) . substr(bin2hex(random_bytes(2)), 0, 4);
        $newId = $userModel->create([
            'username' => $username,
            'name'     => $name,
            'email'    => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role'     => $role,
            'gender'   => $gender,
            'age'      => $age,
            'phone'    => $phone,
            'status'   => $status,
            'avatar'   => null,
        ]);

        if (!empty($_FILES['user_avatar']['name']) && $_FILES['user_avatar']['error'] === UPLOAD_ERR_OK) {
            $up = upload_file($_FILES['user_avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'webp']);
            if ($up['success']) $userModel->update($newId, ['avatar' => 'avatars/' . $up['filename']]);
        }

        ActivityLog::log($_SESSION['user_id'], 'user_add', "Created user #{$newId}: {$name} ({$role})");
        $respond(true, 'User created successfully.');
    }

    if ($action === 'update_user' && isset($_POST['user_id'])) {
        $uid = (int)$_POST['user_id'];
        $existing = $userModel->findById($uid);
        if (!$existing) $respond(false, 'User not found.');

        $name = sanitize(trim($_POST['name'] ?? ''));
        $email = sanitize(trim($_POST['email'] ?? ''));
        $phone = sanitize(trim($_POST['phone'] ?? ''));
        $role = $_POST['role'] ?? 'tourist';
        $status = in_array($_POST['status'] ?? 'active', ['active', 'pending', 'suspended', 'inactive'], true) ? $_POST['status'] : 'active';
        $gender = $_POST['gender'] === 'female' ? 'female' : 'male';
        $age = max(1, min(120, (int)($_POST['age'] ?? 18)));

        if (empty($name) || empty($email)) $respond(false, 'Name and email are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $respond(false, 'Invalid email address.');
        if (!in_array($role, ['admin', 'staff', 'guide', 'tourist'], true)) $respond(false, 'Invalid role.');

        $dup = $userModel->findByEmail($email);
        if ($dup && (int)$dup['id'] !== $uid) $respond(false, 'Email already in use by another user.');

        $data = ['name' => $name, 'email' => $email, 'phone' => $phone, 'role' => $role, 'status' => $status, 'gender' => $gender, 'age' => $age];
        $password = trim($_POST['password'] ?? '');
        if (!empty($password)) {
            if (strlen($password) < 8) $respond(false, 'Password must be at least 8 characters.');
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if (!empty($_FILES['user_avatar']['name']) && $_FILES['user_avatar']['error'] === UPLOAD_ERR_OK) {
            $up = upload_file($_FILES['user_avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'webp']);
            if ($up['success']) $data['avatar'] = 'avatars/' . $up['filename'];
        }

        $userModel->update($uid, $data);
        ActivityLog::log($_SESSION['user_id'], 'user_update', "Updated user #{$uid}: {$name}");
        $respond(true, 'User updated successfully.');
    }

    if ($action === 'update_status' && isset($_POST['user_id'], $_POST['new_status'])) {
        $uid = (int)$_POST['user_id'];
        $newStatus = in_array($_POST['new_status'], ['active', 'pending', 'suspended', 'inactive'], true) ? $_POST['new_status'] : '';
        if (!$newStatus) $respond(false, 'Invalid status.');
        if ((int)$uid === (int)$_SESSION['user_id'] && $newStatus === 'suspended') $respond(false, 'You cannot suspend your own account.');
        $userModel->updateStatus($uid, $newStatus);
        (new Notification())->notifyRegistrationStatus($uid, $newStatus);
        ActivityLog::log($_SESSION['user_id'], 'user_status_change', "Changed user #{$uid} status to {$newStatus}");
        $respond(true, 'User status updated to ' . ucfirst($newStatus) . '.');
    }

    if ($action === 'delete_user' && isset($_POST['user_id'])) {
        $uid = (int)$_POST['user_id'];
        if ((int)$uid === (int)$_SESSION['user_id']) $respond(false, 'You cannot delete your own account.');
        $userModel->delete($uid);
        ActivityLog::log($_SESSION['user_id'], 'user_delete', "Deleted user #{$uid}");
        $respond(true, 'User deleted.');
    }

    if ($action === 'bulk_status') {
        $ids = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])));
        $newStatus = in_array($_POST['new_status'] ?? '', ['active', 'pending', 'suspended', 'inactive'], true) ? $_POST['new_status'] : '';
        if (empty($ids)) $respond(false, 'No users selected.');
        if (!$newStatus) $respond(false, 'Invalid status.');
        $ids = array_diff($ids, [(int)$_SESSION['user_id']]);
        foreach ($ids as $id) {
            $userModel->updateStatus($id, $newStatus);
            (new Notification())->notifyRegistrationStatus($id, $newStatus);
        }
        ActivityLog::log($_SESSION['user_id'], 'user_status_change', "Bulk set " . count($ids) . " users to {$newStatus}");
        $respond(true, count($ids) . ' user(s) set to ' . ucfirst($newStatus) . '.');
    }

    if ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])));
        $ids = array_diff($ids, [(int)$_SESSION['user_id']]);
        if (empty($ids)) $respond(false, 'No users to delete (self deletion is not allowed).');
        foreach ($ids as $id) $userModel->delete($id);
        ActivityLog::log($_SESSION['user_id'], 'user_delete', "Bulk deleted " . count($ids) . " users");
        $respond(true, count($ids) . ' user(s) deleted.');
    }

    if ($action === 'reset_password' && isset($_POST['user_id'])) {
        $uid = (int)$_POST['user_id'];
        if (!$userModel->findById($uid)) $respond(false, 'User not found.');
        $newPassword = trim($_POST['password'] ?? '');
        if (strlen($newPassword) < 8) $newPassword = bin2hex(random_bytes(6));
        $userModel->update($uid, ['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'reset_token' => null, 'reset_expires' => null]);
        (new Notification())->create(['user_id' => $uid, 'title' => 'Password Reset', 'message' => 'Your password was reset by an administrator. Please log in again.', 'type' => 'system']);
        ActivityLog::log($_SESSION['user_id'], 'password_changed', "Reset password for user #{$uid}");
        $sendJson(['ok' => true, 'message' => 'Password reset.', 'password' => $newPassword]);
    }

    if ($action === 'revoke_sessions' && isset($_POST['user_id'])) {
        $uid = (int)$_POST['user_id'];
        if (!$userModel->findById($uid)) $respond(false, 'User not found.');
        try {
            $userModel->update($uid, ['last_login_at' => null, 'last_login_ip' => null, 'last_user_agent' => null]);
        } catch (\PDOException $e) {
            error_log("Revoke sessions failed: " . $e->getMessage());
        }
        (new Notification())->create(['user_id' => $uid, 'title' => 'Sessions Revoked', 'message' => 'All active sessions were revoked by an administrator. Please log in again.', 'type' => 'system']);
        ActivityLog::log($_SESSION['user_id'], 'user_sessions_revoked', "Revoked all sessions for user #{$uid}");
        $respond(true, 'All sessions for this user were revoked.');
    }

    if ($action === 'verify_action' && isset($_POST['user_id'])) {
        $uid = (int)$_POST['user_id'];
        $verdict = $_POST['verdict'] === 'approved' ? 'approved' : 'rejected';
        $notes = sanitize(trim($_POST['notes'] ?? ''));
        $stmt = $db->prepare("SELECT id FROM id_verifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $verifId = $stmt->fetchColumn();
        if (!$verifId) $respond(false, 'No ID verification submission found for this user.');
        $db->prepare("UPDATE id_verifications SET status = :s, verified_by = :vb, verified_at = datetime('now'), admin_notes = :notes WHERE id = :id")
            ->execute([':s' => $verdict, ':vb' => $_SESSION['user_id'], ':notes' => $notes, ':id' => $verifId]);
        (new Notification())->create([
            'user_id' => $uid,
            'title'   => $verdict === 'approved' ? 'ID Verification Approved' : 'ID Verification Rejected',
            'message' => $verdict === 'approved' ? 'Your government ID has been verified successfully.' : ($notes ? "Your ID verification was rejected: {$notes}" : 'Your ID verification was rejected. Please resubmit a valid document.'),
            'type'    => 'verification',
        ]);
        ActivityLog::log($_SESSION['user_id'], 'id_verification_reviewed', ucfirst($verdict) . " ID verification for user #{$uid}");
        $respond(true, 'ID verification ' . $verdict . '.');
    }

    $respond(false, 'Unknown action.');
}

$stats = users_page_stats($db);
$exportUrl = '/Tourism/admin/users.php?export=csv' . ($roleFilter ? '&role=' . urlencode($roleFilter) : '') . ($statusFilter ? '&status=' . urlencode($statusFilter) : '') . ($search ? '&search=' . urlencode($search) : '');

render_page('admin', 'users.php', 'User Management', function () use ($stats, $search, $roleFilter, $statusFilter, $csrf, $exportUrl) {

$roleBadges = ['admin' => 'bg-danger-subtle text-danger', 'staff' => 'bg-success-subtle text-success', 'guide' => 'bg-info-subtle text-info', 'tourist' => 'bg-primary-subtle text-primary'];
?>
<style>
    .kpi-card { border: 1px solid var(--border-color); border-radius: 14px; background: var(--card-bg); transition: transform .15s ease, box-shadow .15s ease; cursor: pointer; }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.06); }
    .kpi-card .kpi-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .kpi-card.active { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(12,110,94,.15); }
    .table thead th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
    .th-sort { cursor: pointer; user-select: none; }
    .th-sort i { font-size: .65rem; margin-left: 3px; opacity: .6; }
    .user-cell { display: flex; align-items: center; gap: 12px; }
    .avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
    .skeleton { background: linear-gradient(90deg, rgba(130,130,130,.08) 25%, rgba(130,130,130,.18) 37%, rgba(130,130,130,.08) 63%); background-size: 400% 100%; animation: shimmer 1.4s ease infinite; border-radius: 8px; }
    @keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
    .verif-pill { cursor: pointer; font-size: .72rem; border: 1px solid var(--border-color); border-radius: 20px; padding: 2px 10px; transition: .15s; display: inline-flex; align-items: center; gap: 5px; }
    .verif-pill:hover { border-color: var(--brand); }
    .verif-pill.pending { background: rgba(255,193,7,.12); border-color: #ffc107; color: #b78b00; }
    .verif-pill.approved { background: rgba(25,135,84,.12); border-color: #198754; color: #198754; }
    .verif-pill.rejected { background: rgba(220,53,69,.12); border-color: #dc3545; color: #dc3545; }
    .row-actions .btn { padding: 4px 8px; }
    .bulk-bar { display: none; align-items: center; gap: 10px; border: 1px solid var(--brand); background: rgba(12,110,94,.08); border-radius: 12px; padding: 8px 14px; }
    .bulk-bar.show { display: flex; }
    .sticky-filter { position: sticky; top: 70px; z-index: 30; }
    .search-wrap { position: relative; }
    .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .85rem; }
    .search-wrap input { padding-left: 34px; }
    .filter-chip { font-size: .75rem; background: rgba(12,110,94,.1); color: var(--brand); border-radius: 20px; padding: 2px 10px; display: inline-flex; align-items: center; gap: 6px; }
    .toast-container { z-index: 9999; }
    .score-tag { font-size: .7rem; border-radius: 10px; padding: 1px 8px; background: rgba(108,117,125,.12); color: var(--text-muted); }
    .profile-hero { background: linear-gradient(135deg, #0c6e5e, #0a4b41); border-radius: 16px; color: #fff; padding: 22px; }
    .profile-hero .avatar-lg { width: 72px; height: 72px; border-radius: 50%; border: 3px solid rgba(255,255,255,.4); object-fit: cover; }
    .activity-item { display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px dashed var(--border-color); }
    .activity-item:last-child { border-bottom: 0; }
    .stat bx { }
    .verif-doc { max-height: 300px; border-radius: 12px; border: 1px solid var(--border-color); background: #f6f8f9; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .verif-doc img { max-width: 100%; max-height: 300px; object-fit: contain; }
    .security-btn { width: 100%; text-align: left; display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 14px; margin-bottom: 8px; background: var(--card-bg); }
    .security-btn:hover { border-color: var(--brand); }
    .offcanvas { --bs-offcanvas-width: 460px; }
    .avatar-preview { width: 84px; height: 84px; border-radius: 50%; object-fit: cover; border: 2px dashed var(--border-color); cursor: pointer; }
    .pager { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
        <h4 class="mb-1 fw-bold">User Management</h4>
        <div class="text-muted small">Manage accounts, roles, identity verification and security.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="refreshBtn" title="Refresh"><i class="fa-regular fa-rotate-right"></i></button>
        <div class="btn-group">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="fa-solid fa-download me-1"></i>Export</button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="<?= $exportUrl ?>"><i class="fa-solid fa-file-csv me-2 text-success"></i>CSV</a></li>
                <li><a class="dropdown-item" href="<?= str_replace('export=csv', 'export=json', $exportUrl) ?>"><i class="fa-solid fa-file-code me-2 text-primary"></i>JSON</a></li>
            </ul>
        </div>
        <button class="btn btn-brand btn-sm" id="newUserBtn"><i class="fa-solid fa-user-plus me-1"></i>New User</button>
    </div>
</div>

<div class="row g-3 mb-3" id="kpiRow">
    <div class="col-6 col-lg-2"><div class="kpi-card p-3" data-kpi="total" data-role="" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-primary-subtle text-primary"><i class="fa-solid fa-users"></i></div><div><div class="fs-4 fw-bold" id="kpi-total"><?= $stats['total'] ?></div><div class="text-muted small">Users</div></div></div>
    </div></div>
    <div class="col-6 col-lg-2"><div class="kpi-card p-3" data-kpi="admin" data-role="admin" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-danger-subtle text-danger"><i class="fa-solid fa-shield-halved"></i></div><div><div class="fs-4 fw-bold" id="kpi-admin"><?= $stats['admin'] ?></div><div class="text-muted small">Admins</div></div></div>
    </div></div>
    <div class="col-6 col-lg-2"><div class="kpi-card p-3" data-kpi="staff" data-role="staff" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-success-subtle text-success"><i class="fa-solid fa-user-tie"></i></div><div><div class="fs-4 fw-bold" id="kpi-staff"><?= $stats['staff'] ?></div><div class="text-muted small">Staff</div></div></div>
    </div></div>
    <div class="col-6 col-lg-2"><div class="kpi-card p-3" data-kpi="guide" data-role="guide" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-info-subtle text-info"><i class="fa-solid fa-person-hiking"></i></div><div><div class="fs-4 fw-bold" id="kpi-guide"><?= $stats['guide'] ?></div><div class="text-muted small">Guides</div></div></div>
    </div></div>
    <div class="col-6 col-lg-2"><div class="kpi-card p-3" data-kpi="tourist" data-role="tourist" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-primary-subtle text-primary"><i class="fa-solid fa-suitcase-rolling"></i></div><div><div class="fs-4 fw-bold" id="kpi-tourist"><?= $stats['tourist'] ?></div><div class="text-muted small">Tourists</div></div></div>
    </div></div>
    <div class="col-6 col-lg-2"><div class="kpi-card p-3" data-kpi="pending" data-role="" data-status="" title="Users awaiting ID verification">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-warning-subtle text-warning"><i class="fa-solid fa-id-card"></i></div><div><div class="fs-4 fw-bold" id="kpi-pending"><?= $stats['pending_verifications'] ?></div><div class="text-muted small">Pending IDs</div></div></div>
    </div></div>
</div>

<div class="sticky-filter mb-3">
    <div class="card shadow-sm">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search name, email or phone..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"></div>
                </div>
                <div class="col-md-2"><select id="roleFilter" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option>
                    <option value="guide" <?= $roleFilter === 'guide' ? 'selected' : '' ?>>Guide</option>
                    <option value="tourist" <?= $roleFilter === 'tourist' ? 'selected' : '' ?>>Tourist</option>
                </select></div>
                <div class="col-md-2"><select id="statusFilter" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select></div>
                <div class="col-md-2"><select id="perPage" class="form-select form-select-sm">
                    <option value="10">10 / page</option>
                    <option value="15" selected>15 / page</option>
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                </select></div>
                <div class="col-md-2 d-flex gap-1 justify-content-end">
                    <button class="btn btn-outline-secondary btn-sm" id="clearFilters">Clear</button>
                    <button class="btn btn-brand btn-sm" id="applyFilters"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                </div>
            </div>
            <div id="chipRow" class="mt-2 d-flex gap-1 flex-wrap"></div>
        </div>
    </div>
</div>

<div class="bulk-bar mb-3" id="bulkBar">
    <i class="fa-solid fa-check-double text-brand"></i>
    <span class="fw-semibold" id="bulkCount">0 selected</span>
    <div class="ms-auto d-flex gap-1 flex-wrap">
        <button class="btn btn-sm btn-outline-success" onclick="bulkSet('active')"><i class="fa-solid fa-check me-1"></i>Set Active</button>
        <button class="btn btn-sm btn-outline-warning" onclick="bulkSet('suspended')"><i class="fa-solid fa-ban me-1"></i>Suspend</button>
        <button class="btn btn-sm btn-outline-danger" onclick="bulkDelete()"><i class="fa-solid fa-trash me-1"></i>Delete</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()"><i class="fa-solid fa-xmark me-1"></i></button>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:38px"><input type="checkbox" class="form-check-input" id="selectAll" title="Select all"></th>
                    <th class="th-sort" data-sort="name" id="th-name">User <i class="fa-solid fa-sort"></i></th>
                    <th class="th-sort" data-sort="role" id="th-role">Role <i class="fa-solid fa-sort"></i></th>
                    <th>Status</th>
                    <th class="th-sort" data-sort="created" id="th-created">Joined <i class="fa-solid fa-sort"></i></th>
                    <th>Last Active</th>
                    <th>ID Verification</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="usersBody"></tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="text-muted small" id="footerInfo">Loading...</div>
        <div class="pager" id="pager"></div>
    </div>
</div>

<!-- Profile modal -->
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-body p-0">
                <div class="profile-hero d-flex gap-3">
                    <img src="" id="profAvatar" class="avatar-lg" alt="">
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <div><h5 class="mb-0 fw-bold" id="profName">...</h5>
                                <div class="small opacity-75" id="profEmail"></div>
                            </div>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="d-flex gap-2 mt-2 flex-wrap" id="profMetaTop"></div>
                    </div>
                </div>
                <div class="p-3">
                    <div class="row g-3 mb-3" id="profStats"></div>
                    <ul class="nav nav-pills nav-fill mb-3 small">
                        <li class="nav-item"><button class="nav-link active" data-prof-tab="activity"><i class="fa-solid fa-clock-rotate-left me-1"></i>Activity History</button></li>
                        <li class="nav-item"><button class="nav-link" data-prof-tab="security"><i class="fa-solid fa-shield-halved me-1"></i>Security</button></li>
                    </ul>
                    <div id="profActivity" class="prof-tab"></div>
                    <div id="profSecurity" class="prof-tab d-none"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Verify modal -->
<div class="modal fade" id="verifyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fa-solid fa-id-card me-2 text-brand"></i>ID Verification</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <img src="" id="verifAvatar" class="avatar" alt="">
                    <div><div class="fw-semibold" id="verifName"></div><div class="small text-muted" id="verifUserInfo"></div></div>
                </div>
                <div class="verif-doc mb-3" id="verifDocWrap">
                    <span class="text-muted small"><i class="fa-solid fa-id-card me-1"></i>No document image stored.</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="small text-muted">Type</label><div class="fw-semibold" id="verifType"></div></div>
                    <div class="col-md-4"><label class="small text-muted">Submitted</label><div class="fw-semibold" id="verifDate"></div></div>
                    <div class="col-md-4"><label class="small text-muted">Status</label><div id="verifStatus"></div></div>
                </div>
                <div class="mt-3">
                    <label class="small text-muted">Admin notes</label>
                    <textarea id="verifNotes" class="form-control form-control-sm" rows="2" placeholder="Optional notes for the user"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-outline-danger btn-sm" onclick="verifyAction('rejected')"><i class="fa-solid fa-ban me-1"></i>Reject</button>
                <button class="btn btn-brand btn-sm" onclick="verifyAction('approved')"><i class="fa-solid fa-check me-1"></i>Approve</button>
            </div>
        </div>
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

<!-- User drawer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="userDrawer">
    <div class="offcanvas-header border-bottom">
        <h6 class="offcanvas-title fw-bold" id="drawerTitle">New User</h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <form id="userForm" enctype="multipart/form-data">
            <input type="hidden" id="f_user_id" value="">
            <div class="text-center mb-3">
                <input type="file" id="f_avatar" accept="image/*" class="d-none">
                <img src="" id="f_avatar_preview" class="avatar-preview" alt="Avatar" title="Click to upload avatar">
                <div class="small text-muted mt-1">Click avatar to upload</div>
            </div>
            <div class="mb-3">
                <label class="form-label small">Full Name *</label>
                <input type="text" id="f_name" class="form-control" placeholder="Juan Dela Cruz">
            </div>
            <div class="mb-3">
                <label class="form-label small">Email *</label>
                <input type="email" id="f_email" class="form-control" placeholder="user@example.com">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small">Role *</label>
                    <select id="f_role" class="form-select">
                        <option value="tourist">Tourist</option>
                        <option value="guide">Guide</option>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small">Status</label>
                    <select id="f_status" class="form-select">
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small">Gender</label>
                    <select id="f_gender" class="form-select">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small">Age</label>
                    <input type="number" id="f_age" class="form-control" min="1" max="120" placeholder="18">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small">Phone</label>
                <input type="text" id="f_phone" class="form-control" placeholder="09xx xxx xxxx">
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
        <button class="btn btn-brand flex-fill" onclick="saveUser()"><i class="fa-solid fa-floppy-disk me-1"></i>Save User</button>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const ROLE_BADGES = <?= json_encode($roleBadges) ?>;
const VERIF_LABELS = <?= json_encode(['passport' => 'Passport', 'drivers_license' => "Driver's License", 'national_id' => 'National ID', 'voters_id' => "Voter's ID", 'senior_citizen' => 'Senior Citizen ID', 'other' => 'Other']) ?>;
const state = { page: 1, per_page: 15, search: '', role: '', status: '', sort: 'created', dir: 'desc', total: 0, pages: 1, loading: false };
const __users = {};
let selected = new Set();
let profUserId = null;
let verifUserId = null;
let pendingConfirm = null;
let debounceTimer = null;

const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);

function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d.replace(' ', 'T'));
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}
function timeAgo(d) {
    if (!d) return 'Never';
    const dt = new Date(d.replace(' ', 'T'));
    if (isNaN(dt)) return d;
    const s = Math.floor((Date.datetime('now') - dt) / 1000);
    if (s < 60) return 'just now';
    const m = Math.floor(s / 60); if (m < 60) return m + 'm ago';
    const h = Math.floor(m / 60); if (h < 24) return h + 'h ago';
    const day = Math.floor(h / 24); if (day < 30) return day + 'd ago';
    const mo = Math.floor(day / 30); if (mo < 12) return mo + 'mo ago';
    return Math.floor(day / 365) + 'y ago';
}
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (type === 'error' ? 'danger' : type) + ' border-0 show';
    el.innerHTML = '<div class="d-flex"><div class="toast-body">' + esc(msg) + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    document.querySelector('.toast-container').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3200 }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
function roleBadge(role) {
    const cls = ROLE_BADGES[role] || ROLE_BADGES.tourist;
    const icon = { admin: 'fa-shield-halved', staff: 'fa-user-tie', guide: 'fa-person-hiking', tourist: 'fa-suitcase-rolling' }[role] || 'fa-user';
    return '<span class="badge ' + cls + '"><i class="fa-solid ' + icon + ' me-1"></i>' + esc(role) + '</span>';
}
function statusBadge(st) {
    const m = { active: ['bg-success-subtle text-success', 'fa-circle-check'], suspended: ['bg-danger-subtle text-danger', 'fa-circle-minus'], pending: ['bg-warning-subtle text-warning', 'fa-clock'], inactive: ['bg-secondary-subtle text-secondary', 'fa-circle'] };
    const c = m[st] || m.inactive;
    return '<span class="badge ' + c[0] + '"><i class="fa-solid ' + c[1] + ' me-1"></i>' + esc(st) + '</span>';
}
function verifPill(v) {
    if (!v) return '<span class="text-muted small">None</span>';
    const c = v.status;
    const cls = { pending: 'pending', approved: 'approved', rejected: 'rejected' }[c] || 'pending';
    const icon = c === 'approved' ? 'fa-circle-check' : c === 'rejected' ? 'fa-circle-xmark' : 'fa-clock';
    return '<span class="verif-pill ' + cls + '" role="button" onclick="openVerif(' + v.uid + ')"><i class="fa-solid ' + icon + '"></i>' + (VERIF_LABELS[v.id_type] || v.id_type) + ' · ' + esc(c) + '</span>';
}

function qs() {
    const p = new URLSearchParams();
    if (state.search) p.set('search', state.search);
    if (state.role) p.set('role', state.role);
    if (state.status) p.set('status', state.status);
    p.set('page', state.page);
    p.set('per_page', state.per_page);
    p.set('sort', state.sort);
    p.set('dir', state.dir);
    return p.toString();
}

function skeletonRows(n) {
    let h = '';
    for (let i = 0; i < n; i++) {
        h += '<tr><td><span class="form-check-input d-block"></span></td><td><div class="user-cell"><span class="skeleton avatar"></span><div><div class="skeleton" style="width:120px;height:10px"></div><div class="skeleton mt-1" style="width:160px;height:8px"></div></div></div></td>'
            + '<td><span class="skeleton" style="width:70px;height:18px;display:inline-block"></span></td><td><span class="skeleton" style="width:70px;height:18px;display:inline-block"></span></td>'
            + '<td><span class="skeleton" style="width:90px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:80px;height:10px;display:inline-block"></span></td>'
            + '<td><span class="skeleton" style="width:90px;height:18px;display:inline-block"></span></td><td class="text-end"><span class="skeleton" style="width:90px;height:18px;display:inline-block"></span></td></tr>';
    }
    return h;
}

async function load() {
    if (state.loading) return;
    const body = $('#usersBody');
    body.innerHTML = skeletonRows(state.per_page);
    state.loading = true;
    try {
        const res = await fetch('/Tourism/admin/users.php?ajax=1&' + qs());
        const d = await res.json();
        state.total = d.total; state.pages = d.pages; state.page = d.page; state.per_page = d.per_page;
        applyStats(d.stats);
        render(d.rows);
        const se = $('#selectAll'); if (se) se.checked = false;
        clearSelection(false);
    } catch (e) {
        body.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Failed to load users.</td></tr>';
    } finally {
        state.loading = false;
    }
}

function applyStats(s) {
    if (!s) return;
    const map = { total: 'kpi-total', admin: 'kpi-admin', staff: 'kpi-staff', guide: 'kpi-guide', tourist: 'kpi-tourist', pending_verifications: 'kpi-pending' };
    for (const k in map) { const el = document.getElementById(map[k]); if (el) el.textContent = s[k] ?? 0; }
    var kpis = document.querySelectorAll('.kpi-card'); kpis.forEach(k => k.classList.remove('active'));
    if (state.role) { const k = document.querySelector('.kpi-card[data-role="' + state.role + '"]'); if (k) k.classList.add('active'); }
    else if (state.status) { const k = document.querySelector('.kpi-card[data-kpi="' + state.status + '"]'); if (k) k.classList.add('active'); }
}

function render(rows) {
    const body = $('#usersBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5"><i class="fa-solid fa-user-slash fa-2x d-block mb-2"></i>No users match your filters.</td></tr>';
        renderFooter();
        return;
    }
    let h = '';
    rows.forEach(u => {
        __users[u.id] = u;
        const sel = selected.has(u.id) ? 'checked' : '';
        const act = timeAgo(u.last_login_at);
        h += '<tr data-id="' + u.id + '" class="user-row"><td><input type="checkbox" class="form-check-input row-check" data-id="' + u.id + '" ' + sel + '></td>'
            + '<td><div class="user-cell"><img src="' + esc(u.avatar_url) + '" class="avatar" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'"><div><div class="fw-semibold">' + esc(u.name) + (u.is_self ? ' <span class="badge text-bg-brand" style="font-size:.6rem">You</span>' : '') + '</div><div class="small text-muted">' + esc(u.email) + '</div></div></div></td>'
            + '<td>' + roleBadge(u.role) + '</td>'
            + '<td><select class="form-select form-select-sm status-sel" data-id="' + u.id + '" style="min-width:110px" ' + (u.is_self ? 'disabled' : '') + '>'
            + ['active', 'pending', 'suspended', 'inactive'].map(s => '<option value="' + s + '" ' + (s === u.status ? 'selected' : '') + '>' + s + '</option>').join('')
            + '</select></td>'
            + '<td class="small">' + fmtDate(u.created_at) + '</td>'
            + '<td class="small">' + act + '</td>'
            + '<td>' + verifPill(u.verif && { status: u.verif.status, id_type: u.verif.id_type, uid: u.id }) + '</td>'
            + '<td class="text-end"><div class="btn-group btn-group-sm row-actions">'
            + '<button class="btn btn-outline-secondary" title="View profile" onclick="openProfile(' + u.id + ')"><i class="fa-solid fa-eye"></i></button>'
            + '<button class="btn btn-outline-secondary" title="Edit user" onclick="openDrawer(' + u.id + ')"><i class="fa-solid fa-pen"></i></button>'
            + (u.verif && u.verif.status === 'pending' ? '<button class="btn btn-outline-warning" title="Review ID" onclick="openVerif(' + u.id + ')"><i class="fa-solid fa-id-card"></i></button>' : '')
            + (!u.is_self ? '<button class="btn btn-outline-danger" onclick="askDelete(' + u.id + ')"><i class="fa-solid fa-trash"></i></button>' : '')
            + '</div></td></tr>';
    });
    body.innerHTML = h;
    renderFooter();
}

function renderFooter() {
    const from = state.total === 0 ? 0 : (state.page - 1) * state.per_page + 1;
    const to = Math.min(state.page * state.per_page, state.total);
    $('#footerInfo').textContent = 'Showing ' + from + '–' + to + ' of ' + state.total + ' users';
    const p = $('#pager');
    p.innerHTML = '';
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

function onSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { state.search = $('#searchInput').value.trim(); state.page = 1; load(); }, 400);
}
function updateFiltersUI() {
    $('#roleFilter').value = state.role;
    $('#statusFilter').value = state.status;
    $('#searchInput').value = state.search;
    renderChips();
    renderSortIndicators();
}
function applyFilters() {
    state.role = $('#roleFilter').value;
    state.status = $('#statusFilter').value;
    state.search = $('#searchInput').value.trim();
    state.page = 1;
    renderChips(); load();
}
function clearFilters() {
    state.role = state.status = state.search = '';
    $('#roleFilter').value = $('#statusFilter').value = '';
    $('#searchInput').value = '';
    renderChips(); load();
}
function renderChips() {
    const wrap = $('#chipRow');
    if (!state.role && !state.status && !state.search) { wrap.innerHTML = ''; return; }
    let h = '';
    if (state.role) h += '<span class="filter-chip">Role: ' + esc(state.role) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>';
    if (state.status) h += '<span class="filter-chip">Status: ' + esc(state.status) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>';
    if (state.search) h += '<span class="filter-chip">Search: ' + esc(state.search) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>';
    wrap.innerHTML = h;
}
function renderSortIndicators() {
    ['name', 'role', 'created'].forEach(k => {
        const th = $('#th-' + k);
        if (th) th.querySelector('i').className = 'fa-solid ' + (state.sort === k ? (state.dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort');
    });
}
function setSort(k) {
    if (state.sort === k) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
    else { state.sort = k; state.dir = 'desc'; }
    renderSortIndicators();
    load();
}

// Select / bulk
function onSelectChange() {
    selected.clear();
    $$('.row-check:checked').forEach(cb => selected.add(parseInt(cb.dataset.id)));
    const bar = $('#bulkBar');
    $('#bulkCount').textContent = selected.size + ' selected';
    bar.classList.toggle('show', selected.size > 0 || false);
}
function clearSelection(showBar) {
    selected.clear();
    $$('.row-check').forEach(cb => cb.checked = false);
    $('#selectAll').checked = false;
    $('#bulkBar').classList.remove('show');
}
function bulkSet(st) {
    if (!selected.size) return;
    askConfirm('Set ' + selected.size + ' user(s) to ' + st + '?', 'This will update their status and send a notification.', () => {
        post({ action: 'bulk_status', user_ids: Array.from(selected), new_status: st, csrf_token: CSRF }, (ok, msg) => {
            toast(msg, ok ? 'success' : 'error');
            if (ok) { clearSelection(); load(); }
        });
    });
}
function bulkDelete() {
    if (!selected.size) return;
    askConfirm('Delete ' + selected.size + ' user(s)?', 'This permanently removes the selected accounts (excluding your own).', () => {
        post({ action: 'bulk_delete', user_ids: Array.from(selected), csrf_token: CSRF }, (ok, msg) => {
            toast(msg, ok ? 'success' : 'error');
            if (ok) { clearSelection(); load(); }
        });
    });
}

// POST helper
function post(data, cb) {
    const fd = new FormData();
    Object.keys(data).forEach(k => {
        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k, v));
        else fd.append(k, data[k]);
    });
    fetch('/Tourism/admin/users.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json()).then(d => cb(d.ok, d.message, d)).catch(() => cb(false, 'Request failed.'));
}

// Confirm dialog
function askConfirm(title, msg, fn) {
    $('#confirmTitle').textContent = title;
    $('#confirmMsg').textContent = msg;
    pendingConfirm = fn;
    bootstrap.Modal.getOrCreateInstance($('#confirmModal')).show();
}
$('#confirmOk').addEventListener('click', () => {
    if (pendingConfirm) { pendingConfirm(); pendingConfirm = null; }
    bootstrap.Modal.getInstance($('#confirmModal')).hide();
});

// Status select
document.addEventListener('change', (e) => {
    if (e.target.classList.contains('status-sel')) {
        const id = parseInt(e.target.dataset.id);
        const st = e.target.value;
        const u = __users[id];
        askConfirm('Set ' + (u ? u.name : '#' + id) + ' to ' + st + '?', u && u.is_self && st === 'suspended' ? 'You cannot suspend your own account.' : 'Status change will notify the user.', () => {
            post({ action: 'update_status', user_id: id, new_status: st, csrf_token: CSRF }, (ok, msg) => {
                toast(msg, ok ? 'success' : 'error');
                load();
                if (st === 'suspended' && u && u.is_self) { /* blocked server-side */ }
            });
        });
    }
});

// Delete
function askDelete(id) {
    const u = __users[id];
    askConfirm('Delete user "' + (u ? u.name : '#' + id) + '"?', 'This permanently removes the user and their account data.', () => {
        post({ action: 'delete_user', user_id: id, csrf_token: CSRF }, (ok, msg) => {
            toast(msg, ok ? 'success' : 'error');
            if (ok) load();
        });
    });
}

// Profile modal
function openProfile(id) {
    const u = __users[id];
    if (!u) return;
    profUserId = id;
    $('#profAvatar').src = u.avatar_url;
    $('#profName').textContent = u.name;
    $('#profEmail').textContent = u.email + ' · @' + u.username;
    $('#profMetaTop').innerHTML = roleBadge(u.role) + statusBadge(u.status) + (u.verif ? '<span class="badge ' + (u.verif.status === 'approved' ? 'bg-success-subtle text-success' : u.verif.status === 'rejected' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning') + '">ID: ' + esc(u.verif.status) + '</span>' : '');
    $('#profStats').innerHTML = '<div class="col-3"><div class="small text-muted">Joined</div><div class="fw-semibold">' + fmtDate(u.created_at) + '</div></div>'
        + '<div class="col-3"><div class="small text-muted">Phone</div><div class="fw-semibold">' + esc(u.phone || '—') + '</div></div>'
        + '<div class="col-3"><div class="small text-muted">Last login</div><div class="fw-semibold">' + timeAgo(u.last_login_at) + '</div></div>'
        + '<div class="col-3"><div class="small text-muted">Logins</div><div class="fw-semibold">' + (u.login_count || 0) + '</div></div>';
    $('#profSecurity').innerHTML = buildSecurity(u);
    $('#profActivity').innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-brand"></div></div>';
    showTab('activity');
    bootstrap.Modal.getOrCreateInstance($('#profileModal')).show();
    fetchProfileActivity(id);
}
function showTab(t) {
    $$('[data-prof-tab]').forEach(b => b.classList.toggle('active', b.dataset.profTab === t));
    $('#profActivity').classList.toggle('d-none', t !== 'activity');
    $('#profSecurity').classList.toggle('d-none', t !== 'security');
}
function buildSecurity(u) {
    const ip = u.last_login_ip || u.last_active_ip || '—';
    return '<div class="mb-3 small">'
        + '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Last login IP</span><span class="fw-semibold"><i class="fa-solid fa-globe me-1"></i>' + esc(ip) + '</span></div>'
        + '<div class="d-flex justify-content-between mb-1"><span class="text-muted">Device</span><span class="fw-semibold text-truncate" style="max-width:220px">' + esc(u.last_user_agent || '—') + '</span></div>'
        + '</div>'
        + '<button class="security-btn" onclick="promptReset(' + u.id + ')"><span><i class="fa-solid fa-key me-2 text-warning"></i><span class="small fw-semibold">Reset Password</span><div class="small text-muted">Generate a new temporary password</div></span><i class="fa-solid fa-chevron-right text-muted small"></i></button>'
        + '<button class="security-btn" onclick="promptRevoke(' + u.id + ')"><span><i class="fa-solid fa-right-from-bracket me-2 text-info"></i><span class="small fw-semibold">Revoke Sessions</span><div class="small text-muted">Sign the user out everywhere</div></span><i class="fa-solid fa-chevron-right text-muted small"></i></button>';
}
async function fetchProfileActivity(id) {
    try {
        const r = await fetch('/Tourism/admin/users.php?ajax=1&mode=activity&user_id=' + id);
        const d = await r.json();
        if (!d.activity || !d.activity.length) {
            $('#profActivity').innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-clock-rotate-left d-block mb-2"></i>No recent activity.</div>';
            return;
        }
        $('#profActivity').innerHTML = d.activity.map(a =>
            '<div class="activity-item"><i class="fa-solid fa-circle text-brand mt-1" style="font-size:.5rem"></i>'
            + '<div class="flex-grow-1"><div class="fw-semibold small">' + esc(a.action.replace(/_/g, ' ')) + '</div>'
            + '<div class="small text-muted">' + esc(a.details || '') + '</div></div>'
            + '<div class="small text-muted text-nowrap">' + timeAgo(a.created_at) + '</div></div>'
        ).join('');
    } catch {
        $('#profActivity').innerHTML = '<div class="text-center text-muted py-4">Failed to load activity.</div>';
    }
}
function promptReset(id) {
    askConfirm('Reset password for this user?', 'A new secure password will be generated and shown to you.', () => {
        post({ action: 'reset_password', user_id: id, csrf_token: CSRF }, (ok, msg, d) => {
            if (ok) {
                toast(msg + ' New password: <b>' + esc(d.password) + '</b>', 'success');
            } else toast(msg, 'error');
        });
    });
}
function promptRevoke(id) {
    askConfirm('Revoke all sessions?', 'The user will need to log in again.', () => {
        post({ action: 'revoke_sessions', user_id: id, csrf_token: CSRF }, (ok, msg) => {
            toast(msg, ok ? 'success' : 'error');
            if (ok) load();
        });
    });
}

// Verify
function openVerif(id) {
    const u = __users[id];
    if (!u || !u.verif) return;
    verifUserId = id;
    $('#verifAvatar').src = u.avatar_url;
    $('#verifName').textContent = u.name;
    $('#verifUserInfo').textContent = u.email + ' · ' + roleBadge(u.role);
    $('#verifType').textContent = VERIF_LABELS[u.verif.id_type] || u.verif.id_type;
    $('#verifDate').textContent = fmtDate(u.verif.created_at);
    $('#verifStatus').innerHTML = u.verif.status === 'approved' ? statusBadge('active') : u.verif.status === 'rejected' ? '<span class="badge bg-danger-subtle text-danger"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected</span>' : statusBadge('pending');
    $('#verifNotes').value = u.verif.admin_notes || '';
    const doc = $('#verifDocWrap');
    if (u.verif.doc_url) doc.innerHTML = '<a href="' + esc(u.verif.doc_url) + '" target="_blank"><img src="' + esc(u.verif.doc_url) + '" alt="ID document" onerror="this.parentNode.outerHTML=\'<span class=&quot;text-muted small&quot;>Unsupported document type. <a href=\\\'' + esc(u.verif.doc_url) + '\\\'>Open original</a>.</span>\'"></a>';
    else doc.innerHTML = '<span class="text-muted small"><i class="fa-solid fa-id-card me-1"></i>No document image stored.</span>';
    bootstrap.Modal.getOrCreateInstance($('#verifyModal')).show();
}
function verifyAction(verdict) {
    if (!verifUserId) return;
    const notes = $('#verifNotes').value.trim();
    post({ action: 'verify_action', user_id: verifUserId, verdict, notes, csrf_token: CSRF }, (ok, msg) => {
        toast(msg, ok ? 'success' : 'error');
        if (ok) {
            bootstrap.Modal.getInstance($('#verifyModal')).hide();
            if (profUserId === verifUserId) { openProfile(verifUserId); }
            verifUserId = null;
            load();
        }
    });
}

// Drawer
function genPassword() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    let p = '';
    for (let i = 0; i < 12; i++) p += chars[Math.floor(Math.random() * chars.length)];
    $('#f_password').value = p;
    $('#genPassMsg').textContent = 'Generated strong password. Click save to apply.';
}
function openDrawer(id) {
    const f = $('#userForm');
    f.reset();
    $('#f_user_id').value = '';
    $('#f_avatar_preview').src = 'https://ui-avatars.com/api/?name=New&background=0c6e5e&color=fff&size=128';
    $('#drawerTitle').textContent = 'New User';
    $('#f_pass_label').innerHTML = 'Password * <span class="text-muted fw-normal">(min 8 chars)</span>';
    $('#genPassMsg').textContent = '';
    $('#f_role').value = 'tourist'; $('#f_status').value = 'pending';
    if (id) {
        const u = __users[id];
        if (!u) return;
        $('#f_user_id').value = u.id;
        $('#drawerTitle').textContent = 'Edit User — ' + u.name;
        $('#f_name').value = u.name; $('#f_email').value = u.email;
        $('#f_role').value = u.role; $('#f_status').value = u.status;
        $('#f_gender').value = u.gender; $('#f_age').value = u.age ? u.age : '';
        $('#f_phone').value = u.phone || '';
        $('#f_password').value = ''; $('#f_password').placeholder = 'Leave blank to keep current password';
        $('#f_pass_label').innerHTML = 'New Password <span class="text-muted fw-normal">(leave blank to keep)</span>';
        $('#f_avatar_preview').src = u.avatar_url;
    }
    bootstrap.Offcanvas.getOrCreateInstance($('#userDrawer')).show();
}
$('#f_avatar').addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    if (!/^image\//.test(file.type)) { toast('Please choose an image file.', 'error'); return; }
    const r = new FileReader();
    r.onload = () => $('#f_avatar_preview').src = r.result;
    r.readAsDataURL(file);
});
document.addEventListener('click', (e) => {
    if (e.target.closest('#f_avatar_preview')) $('#f_avatar').click();
});
function saveUser() {
    const id = $('#f_user_id').value;
    const name = $('#f_name').value.trim(), email = $('#f_email').value.trim(), pass = $('#f_password').value;
    if (!name || !email) { toast('Name and email are required.', 'error'); return; }
    if (!id && pass.length < 8) { toast('Password must be at least 8 characters.', 'error'); return; }
    if (id && pass.length > 0 && pass.length < 8) { toast('Password must be at least 8 characters.', 'error'); return; }
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', id ? 'update_user' : 'add_user');
    if (id) fd.append('user_id', id);
    fd.append('name', name); fd.append('email', email); fd.append('password', pass);
    fd.append('role', $('#f_role').value); fd.append('status', $('#f_status').value);
    fd.append('gender', $('#f_gender').value); fd.append('age', $('#f_age').value);
    fd.append('phone', $('#f_phone').value.trim());
    if ($('#f_avatar').files[0]) fd.append('user_avatar', $('#f_avatar').files[0]);
    fetch('/Tourism/admin/users.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json()).then(d => {
            toast(d.message, d.ok ? 'success' : 'error');
            if (d.ok) {
                bootstrap.Offcanvas.getInstance($('#userDrawer')).hide();
                load();
            }
        }).catch(() => toast('Request failed.', 'error'));
}

// Init
document.addEventListener('DOMContentLoaded', () => {
    $('#searchInput').addEventListener('input', onSearch);
    $('#roleFilter').change = () => applyFilters();
    $('#statusFilter').change = () => applyFilters();
    $('#roleFilter').addEventListener('change', applyFilters);
    $('#statusFilter').addEventListener('change', applyFilters);
    $('#applyFilters').addEventListener('click', applyFilters);
    $('#clearFilters').addEventListener('click', clearFilters);
    $('#perPage').addEventListener('change', () => { state.per_page = parseInt($('#perPage').value); state.page = 1; load(); });
    $('#refreshBtn').addEventListener('click', load);
    $('#newUserBtn').addEventListener('click', () => openDrawer());
    $('#selectAll').addEventListener('change', (e) => {
        $$('.row-check').forEach(cb => cb.checked = e.target.checked);
        onSelectChange();
    });
    document.addEventListener('change', (e) => { if (e.target.classList.contains('row-check')) onSelectChange(); });
    $$('.th-sort').forEach(th => th.addEventListener('click', () => setSort(th.dataset.sort)));
    $$('.kpi-card').forEach(k => k.addEventListener('click', () => {
        const role = k.dataset.role, status = k.dataset.status;
        if (!role && !status) return;
        if (role) { state.role = state.role === role ? '' : role; state.status = ''; }
        else { state.status = state.status === status ? '' : status; state.role = ''; }
        state.page = 1;
        updateFiltersUI();
        load();
    }));
    state.search = <?= json_encode($search) ?>;
    state.role = <?= json_encode($roleFilter) ?>;
    state.status = <?= json_encode($statusFilter) ?>;
    updateFiltersUI();
    load();
});
</script>
<?php }); ?>