<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');


require_once __DIR__ . '/../includes/classes/User.php';
require_once __DIR__ . '/../includes/classes/Feedback.php';
require_once __DIR__ . '/../includes/classes/Schedule.php';

$userModel = new User();
$feedbackModel = new Feedback();
$db = Database::getInstance()->getConnection();

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$availFilter = $_GET['availability'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/admin/guides.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_guide') {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = sanitize($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? 'male';
        $age = (int)($_POST['age'] ?? 25);
        $experience = (int)($_POST['years_of_experience'] ?? 0);
        $langArr = $_POST['languages'] ?? [];
        $specArr = $_POST['specializations'] ?? [];
        $languages = sanitize(implode(', ', array_filter($langArr)));
        $specializations = sanitize(implode(', ', array_filter($specArr)));
        $bio = sanitize($_POST['bio'] ?? '');

        if (empty($name) || empty($email) || empty($password)) {
            flash_message('error', 'Name, email, and password are required.');
            redirect('/admin/guides.php');
        }

        if (strlen($password) < 8) {
            flash_message('error', 'Password must be at least 8 characters.');
            redirect('/admin/guides.php');
        }

        $existing = $userModel->findByEmail($email);
        if ($existing) {
            flash_message('error', 'Email already registered.');
            redirect('/admin/guides.php');
        }

        $userId = $userModel->create([
            'username'   => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0])),
            'name'       => $name,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'role'       => 'guide',
            'gender'     => $gender,
            'age'        => $age,
            'phone'      => $phone,
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($userId) {
            $db->prepare(
                "INSERT INTO guide_profiles (user_id, years_of_experience, languages, specializations, availability_status, bio)
                 VALUES (:uid, :exp, :lang, :spec, 'available', :bio)"
            )->execute([
                ':uid'   => $userId,
                ':exp'   => $experience,
                ':lang'  => $languages,
                ':spec'  => $specializations,
                ':bio'   => $bio,
            ]);
            ActivityLog::log($_SESSION['user_id'], 'guide_add', "Added new guide: {$name}");
            flash_message('success', "Guide \"{$name}\" added successfully.");
        } else {
            flash_message('error', 'Failed to create guide account.');
        }
        redirect('/admin/guides.php');
    }

    if ($action === 'edit_guide') {
        $guideId = (int)($_POST['guide_id'] ?? 0);
        if (!$guideId) { flash_message('error', 'Invalid guide.'); redirect('/admin/guides.php'); }

        $existingGuide = $userModel->findById($guideId);
        if (!$existingGuide || $existingGuide['role'] !== 'guide') {
            flash_message('error', 'Guide not found.');
            redirect('/admin/guides.php');
        }

        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? $existingGuide['gender'];
        $age = (int)($_POST['age'] ?? $existingGuide['age']);
        $status = $_POST['status'] ?? $existingGuide['status'];

        if (empty($name) || empty($email)) {
            flash_message('error', 'Name and email are required.');
            redirect('/admin/guides.php');
        }

        $emailCheck = $userModel->findByEmail($email);
        if ($emailCheck && $emailCheck['id'] !== $guideId) {
            flash_message('error', 'Email is already taken.');
            redirect('/admin/guides.php');
        }

        $updateData = [
            'name'   => $name,
            'email'  => $email,
            'phone'  => $phone,
            'gender' => $gender,
            'age'    => $age,
            'status' => $status,
        ];

        $newPassword = $_POST['new_password'] ?? '';
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) {
                flash_message('error', 'Password must be at least 8 characters.');
                redirect('/admin/guides.php');
            }
            $updateData['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        $userModel->update($guideId, $updateData);

        $experience = (int)($_POST['years_of_experience'] ?? 0);
        $langArr = $_POST['languages'] ?? [];
        $specArr = $_POST['specializations'] ?? [];
        $languages = sanitize(implode(', ', array_filter($langArr)));
        $specializations = sanitize(implode(', ', array_filter($specArr)));
        $bio = sanitize($_POST['bio'] ?? '');

        $db->prepare(
            "INSERT INTO guide_profiles (user_id, years_of_experience, languages, specializations, bio)
             VALUES (:uid, :exp, :lang, :spec, :bio)
             ON CONFLICT(user_id) DO UPDATE SET
                years_of_experience = :exp,
                languages = :lang,
                specializations = :spec,
                bio = :bio"
        )->execute([
            ':uid'  => $guideId,
            ':exp'  => $experience,
            ':lang' => $languages,
            ':spec' => $specializations,
            ':bio'  => $bio,
        ]);

        ActivityLog::log($_SESSION['user_id'], 'guide_edit', "Updated guide #{$guideId}: {$name}");
        flash_message('success', "Guide \"{$name}\" updated successfully.");
        redirect('/admin/guides.php');
    }

    if ($action === 'update_availability' && isset($_POST['guide_id'], $_POST['availability'])) {
        $guideId = (int) $_POST['guide_id'];
        $availability = $_POST['availability'];
        $validStatuses = ['available', 'on_tour', 'off_duty', 'on_leave', 'suspended'];
        if (in_array($availability, $validStatuses)) {
            $db->prepare(
                "INSERT INTO guide_profiles (user_id, availability_status) VALUES (:uid, :avail)
                 ON CONFLICT(user_id) DO UPDATE SET availability_status = :avail2"
            )->execute([':uid' => $guideId, ':avail' => $availability, ':avail2' => $availability]);
            flash_message('success', 'Guide availability updated.');
        }
        redirect('/admin/guides.php?' . http_build_query($_GET));
    }

    if ($action === 'delete_guide') {
        $guideId = (int)($_POST['guide_id'] ?? 0);
        if ($guideId) {
            $guideUser = $userModel->findById($guideId);
            if ($guideUser && $guideUser['role'] === 'guide') {
                $db->prepare("DELETE FROM guide_profiles WHERE user_id = :uid")->execute([':uid' => $guideId]);
                $userModel->delete($guideId);
                ActivityLog::log($_SESSION['user_id'], 'guide_delete', "Deleted guide #{$guideId}: " . $guideUser['name']);
                flash_message('success', 'Guide deleted successfully.');
            }
        }
        redirect('/admin/guides.php');
    }
}

$where = ["u.role = 'guide'"];
$params = [];

if ($search) {
    $where[] = "(u.name LIKE :search OR u.email LIKE :search2 OR u.phone LIKE :search3)";
    $params[':search'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
}

if ($statusFilter) {
    $where[] = "u.status = :status";
    $params[':status'] = $statusFilter;
}

if ($availFilter) {
    $where[] = "COALESCE(gs.availability_status, 'available') = :avail";
    $params[':avail'] = $availFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) as total FROM users u LEFT JOIN guide_profiles gs ON gs.user_id = u.id {$whereClause}");
$countStmt->execute($params);
$total = $countStmt->fetch()['total'];

$perPage = 15;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT u.*,
            COALESCE(gs.availability_status, 'available') as availability_status,
            COALESCE(gs.languages, '') as languages,
            COALESCE(gs.years_of_experience, 0) as experience_years,
            COALESCE(gs.specializations, '') as specializations,
            gs.bio as guide_bio
     FROM users u
     LEFT JOIN guide_profiles gs ON gs.user_id = u.id
     {$whereClause}
     ORDER BY u.name ASC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$guides = $stmt->fetchAll();
$pages = max(1, ceil($total / $perPage));

$guideStats = [
    'total'     => 0,
    'active'    => 0,
    'pending'   => 0,
    'available' => 0,
    'on_tour'   => 0,
];
$statsStmt = $db->query("SELECT status, COUNT(*) as cnt FROM users WHERE role='guide' GROUP BY status");
while ($row = $statsStmt->fetch()) {
    $guideStats[$row['status']] = (int) $row['cnt'];
    $guideStats['total'] += (int) $row['cnt'];
}
$statsStmt2 = $db->query("SELECT COALESCE(availability_status,'available') as avail, COUNT(*) as cnt FROM guide_profiles gp JOIN users u ON u.id=gp.user_id WHERE u.role='guide' GROUP BY avail");
while ($row = $statsStmt2->fetch()) {
    if ($row['avail'] === 'available') $guideStats['available'] = (int) $row['cnt'];
    if ($row['avail'] === 'on_tour') $guideStats['on_tour'] = (int) $row['cnt'];
}

$guideIds = array_column($guides, 'id');
$feedbackData = [];
$tourData = [];
if (!empty($guideIds)) {
    $placeholders = implode(',', array_fill(0, count($guideIds), '?'));
    $fbStmt = $db->prepare("SELECT guide_id, AVG(overall_rating) as avg_rating, COUNT(*) as total FROM feedback WHERE guide_id IN ({$placeholders}) GROUP BY guide_id");
    $fbStmt->execute($guideIds);
    while ($row = $fbStmt->fetch()) {
        $feedbackData[$row['guide_id']] = $row;
    }
    $tourStmt = $db->prepare(
        "SELECT s.guide_id, COUNT(*) as cnt FROM schedules s
         JOIN bookings b ON b.schedule_id = s.id
         WHERE s.guide_id IN ({$placeholders}) AND b.status IN ('confirmed','completed')
         GROUP BY s.guide_id"
    );
    $tourStmt->execute($guideIds);
    while ($row = $tourStmt->fetch()) {
        $tourData[$row['guide_id']] = (int) $row['cnt'];
    }
}

render_page('admin', 'guides.php', 'Tour Guide Management', function () use ($guides, $total, $search, $statusFilter, $availFilter, $page, $pages, $guideStats, $feedbackData, $tourData) {
?>

<style>
.page-hero{background:linear-gradient(135deg,rgba(12,110,94,.9) 0%,rgba(6,95,70,.95) 100%);color:#fff;border-radius:20px;padding:32px 36px;margin-bottom:1.5rem;position:relative;overflow:hidden}.page-hero::before{content:'';position:absolute;top:-50%;right:-15%;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.page-hero h4{font-weight:800;margin-bottom:4px;position:relative;z-index:1}.page-hero p{opacity:.85;font-size:.9rem;position:relative;z-index:1;margin-bottom:0}
.stat-card{border:none;border-radius:16px;overflow:hidden;transition:all .3s;background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9)}.stat-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.stat-card .stat-bar{height:4px;width:100%}.stat-card .stat-body{padding:18px 16px;text-align:center}.stat-card .stat-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}.stat-card .stat-value{font-size:1.6rem;font-weight:800;line-height:1;margin-bottom:4px}.stat-card .stat-label{font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px;margin-bottom:1rem}.filter-card .form-control,.filter-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}.filter-card .form-label{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden}.logs-table{border-collapse:separate;border-spacing:0}.logs-table thead th{background:var(--card-bg,#f8fafc);border-bottom:2px solid var(--border-color,#e2e8f0);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#64748b);padding:14px 16px}.logs-table tbody tr{transition:all .15s}.logs-table tbody tr:hover{background:rgba(12,110,94,.02)}.logs-table tbody td{padding:14px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);vertical-align:middle;font-size:.88rem;color:var(--text-primary,#1e293b)}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
.action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#475569);transition:all .2s;padding:0}.action-btn:hover{border-color:#0c6e5e;color:#0c6e5e;background:rgba(12,110,94,.05)}.action-btn.danger:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.05)}.action-btn.warning:hover{border-color:#f59e0b;color:#f59e0b;background:rgba(245,158,11,.05)}.action-btn.info:hover{border-color:#06b6d4;color:#06b6d4;background:rgba(6,182,212,.05)}
.empty-state{text-align:center;padding:48px 20px;color:var(--text-muted,#94a3b8)}.empty-state i{font-size:3rem;margin-bottom:16px;opacity:.2}.empty-state h5{color:var(--text-primary,#1e293b);font-weight:700}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}
.modal-content{border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff)}.modal-header{border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px}.modal-body{padding:24px}
.detail-card{background:var(--card-bg,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px}.detail-card .label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#94a3b8);margin-bottom:4px}.detail-card .value{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b)}
</style>

<div class="page-hero">
    <h4><i class="fas fa-user-tie me-2"></i>Tour Guide Management</h4>
    <p><?= $total ?> guide<?= $total !== 1 ? 's' : '' ?> · <?= $guideStats['available'] ?? 0 ?> available · <?= $guideStats['on_tour'] ?? 0 ?> on tour · <?= $guideStats['pending'] ?? 0 ?> pending</p>
</div>

<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['val'=>$guideStats['total']??0, 'label'=>'Total Guides','icon'=>'fa-users','color'=>'#3b82f6','bg'=>'#dbeafe'],
        ['val'=>$guideStats['available']??0, 'label'=>'Available Now','icon'=>'fa-check-circle','color'=>'#10b981','bg'=>'#d1fae5'],
        ['val'=>$guideStats['on_tour']??0, 'label'=>'Currently On Tour','icon'=>'fa-route','color'=>'#06b6d4','bg'=>'#cffafe'],
        ['val'=>$guideStats['pending']??0, 'label'=>'Pending Approval','icon'=>'fa-clock','color'=>'#f59e0b','bg'=>'#fef3c7'],
    ];
    foreach ($statCards as $sc): ?>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card"><div class="stat-bar" style="background:<?= $sc['color'] ?>;"></div>
            <div class="stat-body">
                <div class="stat-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;"><i class="fas <?= $sc['icon'] ?>"></i></div>
                <div class="stat-value" style="color:<?= $sc['color'] ?>;"><?= $sc['val'] ?></div>
                <div class="stat-label"><?= $sc['label'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <button class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;padding:8px 20px;" data-bs-toggle="modal" data-bs-target="#addGuideModal">
        <i class="fas fa-plus me-1"></i>Add Guide
    </button>
</div>

<div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label">Search Guide</label><input type="text" name="search" class="form-control" placeholder="Name, email, or phone..." value="<?= sanitize($search) ?>"></div>
        <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All Statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option><option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></div>
        <div class="col-md-2"><label class="form-label">Availability</label><select name="availability" class="form-select"><option value="">All</option><option value="available" <?= $availFilter === 'available' ? 'selected' : '' ?>>Available</option><option value="on_tour" <?= $availFilter === 'on_tour' ? 'selected' : '' ?>>On Tour</option><option value="off_duty" <?= $availFilter === 'off_duty' ? 'selected' : '' ?>>Off Duty</option><option value="on_leave" <?= $availFilter === 'on_leave' ? 'selected' : '' ?>>On Leave</option><option value="suspended" <?= $availFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn w-100" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-search me-1"></i>Search</button>
            <?php if ($search || $statusFilter || $availFilter): ?><a href="guides.php" class="btn btn-outline-secondary w-100" style="border-radius:10px;">Clear</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table logs-table align-middle mb-0">
                <thead><tr><th>ID</th><th>Guide</th><th>Contact</th><th>Experience</th><th>Languages</th><th>Availability</th><th>Status</th><th class="text-center" width="120">Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($guides)): ?>
                        <tr><td colspan="8" class="empty-state"><i class="fas fa-user-slash d-block"></i><h5>No guides found</h5><p><?php if ($search || $statusFilter || $availFilter): ?>Try adjusting your filters. <a href="guides.php" style="color:#0c6e5e;">Clear</a><?php else: ?>Click "Add Guide" to create one.<?php endif; ?></p></td></tr>
                    <?php else: ?>
                        <?php foreach ($guides as $g):
                            $availConfig = match($g['availability_status'] ?? 'available') {
                                'available' => ['bg'=>'#d1fae5','color'=>'#059669','label'=>'Available'],
                                'on_tour' => ['bg'=>'#cffafe','color'=>'#0891b2','label'=>'On Tour'],
                                'off_duty' => ['bg'=>'#f3f4f6','color'=>'#6b7280','label'=>'Off Duty'],
                                'on_leave' => ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'On Leave'],
                                'suspended' => ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Suspended'],
                                default => ['bg'=>'#f3f4f6','color'=>'#6b7280','label'=>ucfirst($g['availability_status'] ?? 'available')]
                            };
                            $statusConfig = match($g['status']) {
                                'active' => ['bg'=>'#d1fae5','color'=>'#059669','label'=>'Active'],
                                'pending' => ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'Pending'],
                                'suspended' => ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Suspended'],
                                default => ['bg'=>'#f3f4f6','color'=>'#6b7280','label'=>ucfirst($g['status'])]
                            };
                            $avgRating = isset($feedbackData[$g['id']]) ? round($feedbackData[$g['id']]['avg_rating'], 1) : 0;
                            $reviewCount = isset($feedbackData[$g['id']]) ? (int)$feedbackData[$g['id']]['total'] : 0;
                            $tourCount = $tourData[$g['id']] ?? 0;
                        ?>
                            <tr>
                                <td><span style="font-family:'SF Mono',Consolas,monospace;font-size:.78rem;padding:3px 10px;border-radius:6px;background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b);">#<?= $g['id'] ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <img src="<?= get_avatar_url($g) ?>" class="rounded-circle me-2" width="36" height="36" alt="<?= sanitize($g['name']) ?>" style="object-fit:cover;">
                                        <div>
                                            <div class="fw-semibold" style="font-size:.88rem;"><?= sanitize($g['name']) ?></div>
                                            <div style="font-size:.75rem;color:var(--text-muted,#94a3b8);"><?= ($g['experience_years'] ?? 0) ?> yrs exp</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size:.85rem;"><?= sanitize($g['email']) ?></div>
                                    <div style="font-size:.78rem;color:var(--text-muted,#94a3b8);"><?= sanitize($g['phone'] ?: 'N/A') ?></div>
                                </td>
                                <td>
                                    <?php if ($tourCount > 0): ?>
                                        <span class="status-chip" style="background:#d1fae5;color:#059669;"><i class="fas fa-circle" style="font-size:6px;"></i><?= $tourCount ?> tours</span>
                                    <?php else: ?>
                                        <span style="font-size:.82rem;color:var(--text-muted,#94a3b8);">No tours</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:.82rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($g['languages'] ?: 'N/A') ?></div>
                                </td>
                                <td><span class="status-chip" style="background:<?= $availConfig['bg'] ?>;color:<?= $availConfig['color'] ?>;"><i class="fas fa-circle" style="font-size:6px;"></i><?= $availConfig['label'] ?></span></td>
                                <td><span class="status-chip" style="background:<?= $statusConfig['bg'] ?>;color:<?= $statusConfig['color'] ?>;"><i class="fas fa-circle" style="font-size:6px;"></i><?= $statusConfig['label'] ?></span></td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <button class="action-btn info" data-bs-toggle="modal" data-bs-target="#viewGuideModal<?= $g['id'] ?>" title="View Profile"><i class="fas fa-eye"></i></button>
                                        <button class="action-btn" data-bs-toggle="modal" data-bs-target="#editGuideModal<?= $g['id'] ?>" title="Edit Guide"><i class="fas fa-pen"></i></button>
                                        <button class="action-btn warning" data-bs-toggle="modal" data-bs-target="#editAvailModal<?= $g['id'] ?>" title="Edit Availability"><i class="fas fa-clock"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this guide permanently?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_guide">
                                            <input type="hidden" name="guide_id" value="<?= $g['id'] ?>">
                                            <button type="submit" class="action-btn danger" title="Delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"><i class="fas fa-chevron-left"></i></a>
        </li>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"><i class="fas fa-chevron-right"></i></a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php foreach ($guides as $g):
    $availConfig2 = match($g['availability_status'] ?? 'available') {
        'available' => ['bg'=>'#d1fae5','color'=>'#059669','label'=>'Available'],
        'on_tour' => ['bg'=>'#cffafe','color'=>'#0891b2','label'=>'On Tour'],
        'off_duty' => ['bg'=>'#f3f4f6','color'=>'#6b7280','label'=>'Off Duty'],
        'on_leave' => ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'On Leave'],
        'suspended' => ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Suspended'],
        default => ['bg'=>'#f3f4f6','color'=>'#6b7280','label'=>ucfirst($g['availability_status'] ?? 'available')]
    };
    $statusConfig2 = match($g['status']) {
        'active' => ['bg'=>'#d1fae5','color'=>'#059669','label'=>'Active'],
        'pending' => ['bg'=>'#fef3c7','color'=>'#d97706','label'=>'Pending'],
        'suspended' => ['bg'=>'#fee2e2','color'=>'#dc2626','label'=>'Suspended'],
        default => ['bg'=>'#f3f4f6','color'=>'#6b7280','label'=>ucfirst($g['status'])]
    };
    $avgRating = isset($feedbackData[$g['id']]) ? round($feedbackData[$g['id']]['avg_rating'], 1) : 0;
    $reviewCount = isset($feedbackData[$g['id']]) ? (int)$feedbackData[$g['id']]['total'] : 0;
    $tourCount = $tourData[$g['id']] ?? 0;
?>

<!-- View Guide Modal -->
<div class="modal fade" id="viewGuideModal<?= $g['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-tie me-2" style="color:#0c6e5e;"><?= sanitize($g['name']) ?></i></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-md-4 text-center">
                        <img src="<?= get_avatar_url($g) ?>" class="rounded-circle mb-3" width="100" height="100" alt="<?= sanitize($g['name']) ?>" style="object-fit:cover;">
                        <h6 class="mb-1 fw-bold"><?= sanitize($g['name']) ?></h6>
                        <span class="status-chip mb-1" style="background:<?= $statusConfig2['bg'] ?>;color:<?= $statusConfig2['color'] ?>;"><?= $statusConfig2['label'] ?></span>
                        <span class="status-chip mb-1" style="background:<?= $availConfig2['bg'] ?>;color:<?= $availConfig2['color'] ?>;"><?= $availConfig2['label'] ?></span>
                        <?php if ($avgRating > 0): ?>
                            <div class="mt-2"><?php for ($i = 1; $i <= 5; $i++): ?><i class="fas fa-star" style="font-size:.75rem;color:<?= $i <= $avgRating ? '#f59e0b' : 'var(--text-muted,#d1d5db)' ?>;"></i><?php endfor; ?> <span style="font-size:.82rem;font-weight:600;"><?= $avgRating ?></span> <span style="font-size:.75rem;color:var(--text-muted,#94a3b8);">(<?= $reviewCount ?>)</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-8">
                        <div class="row g-3">
                            <div class="col-sm-6"><div class="detail-card"><div class="label">Email</div><div class="value"><?= sanitize($g['email']) ?></div></div></div>
                            <div class="col-sm-6"><div class="detail-card"><div class="label">Phone</div><div class="value"><?= sanitize($g['phone'] ?: 'N/A') ?></div></div></div>
                            <div class="col-sm-6"><div class="detail-card"><div class="label">Experience</div><div class="value"><?= ($g['experience_years'] ?? 0) ?> years</div></div></div>
                            <div class="col-sm-6"><div class="detail-card"><div class="label">Tours Completed</div><div class="value"><?= $tourCount ?></div></div></div>
                            <div class="col-12">
                                <div class="detail-card"><div class="label">Languages</div>
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <?php foreach (array_map('trim', explode(',', $g['languages'] ?? '')) as $lang): ?>
                                            <?php if ($lang): ?><span class="status-chip" style="background:rgba(12,110,94,.1);color:#0c6e5e;"><?= $lang ?></span><?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if (empty(trim($g['languages'] ?? ''))): ?><span style="color:var(--text-muted,#94a3b8);">N/A</span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="detail-card"><div class="label">Specializations</div>
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <?php foreach (array_map('trim', explode(',', $g['specializations'] ?? '')) as $spec): ?>
                                            <?php if ($spec): ?><span class="status-chip" style="background:rgba(245,158,11,.1);color:#d97706;"><?= $spec ?></span><?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if (empty(trim($g['specializations'] ?? ''))): ?><span style="color:var(--text-muted,#94a3b8);">N/A</span><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($g['guide_bio'])): ?>
                            <div class="col-12">
                                <div class="detail-card"><div class="label">Bio</div><div class="value" style="font-weight:400;font-size:.88rem;line-height:1.6;"><?= nl2br(sanitize($g['guide_bio'])) ?></div></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Guide Modal -->
<div class="modal fade" id="editGuideModal<?= $g['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Guide: <?= sanitize($g['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_guide">
                    <input type="hidden" name="guide_id" value="<?= $g['id'] ?>">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= sanitize($g['name']) ?>" required style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= sanitize($g['email']) ?>" required style="border-radius:10px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= sanitize($g['phone'] ?? '') ?>" style="border-radius:10px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Gender</label>
                            <select name="gender" class="form-select" style="border-radius:10px;">
                                <option value="male" <?= ($g['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= ($g['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Age</label>
                            <input type="number" name="age" class="form-control" value="<?= $g['age'] ?? 25 ?>" min="18" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Status</label>
                            <select name="status" class="form-select" style="border-radius:10px;">
                                <option value="active" <?= $g['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="pending" <?= $g['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="suspended" <?= $g['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="inactive" <?= $g['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">New Password <small class="text-muted">(leave blank to keep)</small></label>
                            <input type="password" name="new_password" class="form-control" minlength="8" style="border-radius:10px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Years of Experience</label>
                            <input type="number" name="years_of_experience" class="form-control" value="<?= $g['experience_years'] ?? 0 ?>" min="0" style="border-radius:10px;">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><i class="fas fa-language me-1" style="color:#0c6e5e"></i>Languages</label>
                            <?php
                            $langOptions = ['English', 'Filipino', 'Cebuano', 'Ilocano', 'Hiligaynon', 'Waray', 'Bicolano', 'Kapampangan', 'Tagalog', 'Spanish', 'Chinese', 'Japanese', 'Korean', 'French', 'German'];
                            $currentLangs = array_map('trim', explode(',', $g['languages'] ?? ''));
                            ?>
                            <div class="chip-grid">
                                <?php foreach ($langOptions as $lang): ?>
                                    <label class="chip-toggle lang-chip <?= in_array($lang, $currentLangs) ? 'active' : '' ?>">
                                        <input type="checkbox" name="languages[]" value="<?= $lang ?>" <?= in_array($lang, $currentLangs) ? 'checked' : '' ?>>
                                        <i class="fas fa-check chip-check-icon"></i>
                                        <span><?= $lang ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><i class="fas fa-star me-1 text-warning"></i>Specializations</label>
                            <?php
                            $specOptions = ['Eco-Tourism', 'Cultural Heritage', 'Adventure Tours', 'Historical Sites', 'Hiking & Trekking', 'Wildlife', 'Photography', 'Food & Culinary', 'Religious Tourism', 'Water Sports', 'City Tours', 'Mountain Tours'];
                            $currentSpecs = array_map('trim', explode(',', $g['specializations'] ?? ''));
                            ?>
                            <div class="chip-grid">
                                <?php foreach ($specOptions as $spec): ?>
                                    <label class="chip-toggle spec-chip <?= in_array($spec, $currentSpecs) ? 'active' : '' ?>">
                                        <input type="checkbox" name="specializations[]" value="<?= $spec ?>" <?= in_array($spec, $currentSpecs) ? 'checked' : '' ?>>
                                        <i class="fas fa-check chip-check-icon"></i>
                                        <span><?= $spec ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Bio</label>
                            <textarea name="bio" class="form-control" rows="3" style="border-radius:10px;"><?= sanitize($g['guide_bio'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Availability Modal -->
<div class="modal fade" id="editAvailModal<?= $g['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Availability: <?= sanitize($g['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_availability">
                    <input type="hidden" name="guide_id" value="<?= $g['id'] ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Availability Status</label>
                        <select name="availability" class="form-select" style="border-radius:10px;">
                            <option value="available" <?= ($g['availability_status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                            <option value="on_tour" <?= ($g['availability_status'] ?? '') === 'on_tour' ? 'selected' : '' ?>>On Tour</option>
                            <option value="off_duty" <?= ($g['availability_status'] ?? '') === 'off_duty' ? 'selected' : '' ?>>Off Duty</option>
                            <option value="on_leave" <?= ($g['availability_status'] ?? '') === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                            <option value="suspended" <?= ($g['availability_status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endforeach; ?>

<!-- Add Guide Modal -->
<div class="modal fade" id="addGuideModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2" style="color:#0c6e5e;"></i>Add New Tour Guide</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_guide">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Juan Dela Cruz" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required placeholder="guide@email.com" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required minlength="8" placeholder="Min 8 characters" style="border-radius:10px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="09XXXXXXXXX" style="border-radius:10px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Gender</label>
                            <select name="gender" class="form-select" style="border-radius:10px;">
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Age</label>
                            <input type="number" name="age" class="form-control" value="25" min="18" style="border-radius:10px;">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Years of Experience</label>
                            <input type="number" name="years_of_experience" class="form-control" value="0" min="0" style="border-radius:10px;">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><i class="fas fa-language me-1" style="color:#0c6e5e"></i>Languages</label>
                            <div class="chip-grid">
                                <?php
                                $langOptions = ['English', 'Filipino', 'Cebuano', 'Ilocano', 'Hiligaynon', 'Waray', 'Bicolano', 'Kapampangan', 'Tagalog', 'Spanish', 'Chinese', 'Japanese', 'Korean', 'French', 'German'];
                                foreach ($langOptions as $lang): ?>
                                    <label class="chip-toggle lang-chip">
                                        <input type="checkbox" name="languages[]" value="<?= $lang ?>">
                                        <i class="fas fa-check chip-check-icon"></i>
                                        <span><?= $lang ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><i class="fas fa-star me-1 text-warning"></i>Specializations</label>
                            <div class="chip-grid">
                                <?php
                                $specOptions = ['Eco-Tourism', 'Cultural Heritage', 'Adventure Tours', 'Historical Sites', 'Hiking & Trekking', 'Wildlife', 'Photography', 'Food & Culinary', 'Religious Tourism', 'Water Sports', 'City Tours', 'Mountain Tours'];
                                foreach ($specOptions as $spec): ?>
                                    <label class="chip-toggle spec-chip">
                                        <input type="checkbox" name="specializations[]" value="<?= $spec ?>">
                                        <i class="fas fa-check chip-check-icon"></i>
                                        <span><?= $spec ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:.82rem;">Bio</label>
                            <textarea name="bio" class="form-control" rows="3" placeholder="Brief introduction..." style="border-radius:10px;"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px;">Cancel</button>
                    <button type="submit" class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;">Add Guide</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.chip-grid{display:flex;flex-wrap:wrap;gap:8px;padding:12px;border:1px solid var(--border-color,#e2e8f0);border-radius:10px;background:var(--card-bg,#f8f9fa)}
.chip-toggle{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:50px;font-size:.8rem;font-weight:500;cursor:pointer;border:1.5px solid var(--border-color,#dee2e6);background:var(--card-bg,#fff);color:var(--text-muted,#6c757d);transition:all .2s ease;user-select:none}
.chip-toggle input{display:none}
.chip-toggle .chip-check-icon{font-size:.6rem;opacity:0;transition:opacity .2s}
.chip-toggle:hover{border-color:#0c6e5e;color:#0c6e5e;transform:translateY(-1px)}
.lang-chip.active,.chip-toggle:has(input:checked).lang-chip{background:rgba(12,110,94,.12);border-color:#0c6e5e;color:#0c6e5e;font-weight:600}
.spec-chip.active,.chip-toggle:has(input:checked).spec-chip{background:rgba(13,110,94,.12);border-color:#0c6e5e;color:#0c6e5e;font-weight:600}
.chip-toggle:has(input:checked) .chip-check-icon{opacity:1}
.chip-toggle:has(input:checked){box-shadow:0 0 0 1px #0c6e5e}
@media (prefers-color-scheme:dark),[data-theme="dark"]{.chip-grid{border-color:#334155;background:#1e293b}.chip-toggle{border-color:#475569;background:#1e293b;color:#94a3b8}.chip-toggle:hover{border-color:#0c6e5e;color:#2dd4bf}.lang-chip.active,.chip-toggle:has(input:checked).lang-chip{background:rgba(12,110,94,.25);border-color:#14b8a6;color:#2dd4bf}.spec-chip.active,.chip-toggle:has(input:checked).spec-chip{background:rgba(12,110,94,.25);border-color:#14b8a6;color:#2dd4bf}.chip-toggle:has(input:checked){box-shadow:0 0 0 1px #14b8a6}}
</style>
<script>
document.querySelectorAll('.chip-toggle input').forEach(function(cb){cb.addEventListener('change',function(){var label=this.closest('.chip-toggle');label.classList.toggle('active',this.checked)})});
</script>

<?php }); ?>
