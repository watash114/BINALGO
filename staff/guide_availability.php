<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');


$user = new User();
$schedule = new Schedule();
$feedback = new Feedback();

if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $guideId = (int)($_POST['guide_id'] ?? 0);
        $newStatus = $_POST['availability_status'] ?? '';
        $validStatuses = ['active', 'inactive', 'suspended'];
        if ($guideId && in_array($newStatus, $validStatuses)) {
            $user->updateStatus($guideId, $newStatus);
            flash_message('success', 'Guide status updated successfully.');
        } else {
            flash_message('error', 'Invalid status.');
        }
        redirect('/staff/guide_availability.php');
    }
}

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = ['role' => 'guide'];
if ($statusFilter) $filters['status'] = $statusFilter;
if ($search) $filters['search'] = $search;

$guides = $user->findAll($filters, $page, 15);
$today = date('Y-m-d');

$guideStatusCounts = [
    'active' => $user->countByRole('guide'),
    'all' => $user->countByRole('guide'),
];

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM users WHERE role = 'guide' GROUP BY status");
$stmt->execute();
$guideStatusCounts = $stmt->fetchAll();

render_page('staff', 'guide_availability.php', 'Guide Availability', function () use ($guides, $statusFilter, $search, $today, $guideStatusCounts) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.stat-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:20px;text-align:center;transition:all 0.25s;text-decoration:none;display:block;height:100%;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2);}
.stat-card.active-filter{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 2px rgba(12,110,94,0.3);}
.stat-card .stat-count{font-size:2rem;font-weight:800;}
.stat-card .stat-label{font-size:0.8rem;color:var(--text-muted,#94a3b8);margin-top:2px;}
.filter-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:16px 20px;margin-bottom:20px;}
.filter-input{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 14px;color:var(--text-primary,#e2e8f0);width:100%;font-size:0.9rem;}
.filter-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.2);}
.filter-input option{background:var(--card-bg,#1a1f2e);color:var(--text-primary,#e2e8f0);}
.table-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.table-card .table{margin:0;}
.table-card .table thead th{background:rgba(255,255,255,0.03);border-bottom:1px solid var(--border-color,#2a3042);color:var(--text-muted,#94a3b8);font-size:0.8rem;font-weight:600;padding:12px 16px;}
.table-card .table tbody td{border-bottom:1px solid var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);font-size:0.85rem;padding:14px 16px;vertical-align:middle;}
.table-card .table tbody tr:last-child td{border-bottom:none;}
.table-card .table tbody tr:hover{background:rgba(255,255,255,0.02);}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:0.72rem;font-weight:600;}
.status-chip.available{background:rgba(34,197,94,0.15);color:#22c55e;}
.status-chip.off-duty{background:rgba(100,116,139,0.15);color:#94a3b8;}
.status-chip.suspended{background:rgba(239,68,68,0.15);color:#ef4444;}
.status-chip.pending{background:rgba(245,158,11,0.15);color:#f59e0b;}
.action-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;border:1px solid var(--border-color,#2a3042);background:var(--card-bg,#1a1f2e);color:var(--text-muted,#94a3b8);cursor:pointer;transition:all 0.2s;font-size:0.8rem;text-decoration:none;}
.action-btn:hover{background:rgba(12,110,94,0.1);color:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);}
.action-btn.view{color:#3b82f6;border-color:rgba(59,130,246,0.3);background:rgba(59,130,246,0.08);}
.action-btn.view:hover{background:rgba(59,130,246,0.15);}
.action-btn.edit{color:var(--primary,#0c6e5e);border-color:rgba(12,110,94,0.3);background:rgba(12,110,94,0.08);}
.action-btn.edit:hover{background:rgba(12,110,94,0.15);}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.btn-reset{background:rgba(255,255,255,0.08);color:var(--text-primary,#e2e8f0);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 20px;font-weight:600;}
.btn-reset:hover{background:rgba(255,255,255,0.12);color:var(--text-primary,#e2e8f0);}
.guide-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color,#2a3042);}
.guide-name{font-weight:600;color:var(--text-primary,#e2e8f0);font-size:0.9rem;}
.guide-email{font-size:0.8rem;color:var(--text-muted,#94a3b8);}
.schedule-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:0.75rem;background:rgba(59,130,246,0.1);color:#3b82f6;font-weight:500;}
.schedule-badge.none{background:rgba(100,116,139,0.08);color:var(--text-muted,#64748b);}
.rating-display{display:flex;align-items:center;gap:4px;}
.rating-stars{color:#f59e0b;font-size:0.75rem;}
.rating-value{font-size:0.8rem;color:var(--text-muted,#94a3b8);font-weight:600;}
.pagination .page-link{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);border-radius:8px;margin:0 2px;font-size:0.85rem;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-link:hover{background:rgba(12,110,94,0.1);color:var(--primary,#0c6e5e);}
.modal-content{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;}
.modal-header{border-bottom:1px solid var(--border-color,#2a3042);padding:16px 24px;}
.modal-header .modal-title{color:var(--text-primary,#e2e8f0);font-weight:700;font-size:1rem;}
.modal-header .btn-close{filter:invert(1);}
.modal-body{padding:24px;}
.modal-footer{border-top:1px solid var(--border-color,#2a3042);padding:16px 24px;}
.profile-detail{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color,#2a3042);}
.profile-detail:last-child{border-bottom:none;}
.profile-detail .label{font-size:0.85rem;color:var(--text-muted,#94a3b8);font-weight:500;}
.profile-detail .value{font-size:0.9rem;color:var(--text-primary,#e2e8f0);font-weight:600;}
</style>

<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-user-clock me-2"></i>Guide Availability</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Monitor guide status and today's schedule assignments</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.1);padding:6px 14px;border-radius:8px;font-size:0.85rem;"><i class="fas fa-calendar-day"></i><?= date('l, M d, Y') ?></span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($guideStatusCounts as $sc):
        $st = $sc['status'] ?? '';
        $colors = ['active' => '#22c55e', 'inactive' => '#94a3b8', 'suspended' => '#ef4444'];
        $color = $colors[$st] ?? '#94a3b8';
        $labels = ['active' => 'Available', 'inactive' => 'Off Duty', 'suspended' => 'Suspended'];
        $label = $labels[$st] ?? ucfirst($st);
        $icons = ['active' => 'fa-check-circle', 'inactive' => 'fa-pause-circle', 'suspended' => 'fa-ban'];
        $icon = $icons[$st] ?? 'fa-question-circle';
    ?>
    <div class="col-xl-3 col-md-6">
        <a href="guide_availability.php?status=<?= urlencode($st) ?>&search=<?= urlencode($search) ?>" class="stat-card <?= $statusFilter === $st ? 'active-filter' : '' ?>">
            <div style="width:48px;height:48px;border-radius:12px;background:<?= $color ?>15;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <i class="fas <?= $icon ?>" style="color:<?= $color ?>;font-size:1.2rem;"></i>
            </div>
            <div class="stat-count" style="color:<?= $color ?>;"><?= $sc['cnt'] ?></div>
            <div class="stat-label"><?= $label ?></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">Search</label>
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted,#64748b);font-size:0.8rem;"></i>
                <input type="text" name="search" class="filter-input" placeholder="Guide name or email..." value="<?= sanitize($search) ?>" style="padding-left:38px;">
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">Status</label>
            <select name="status" class="filter-input">
                <option value="">All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Available (Active)</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Off Duty (Inactive)</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn-brand"><i class="fas fa-filter me-1"></i>Filter</button>
            <a href="guide_availability.php" class="btn-reset"><i class="fas fa-redo me-1"></i>Reset</a>
        </div>
    </form>
</div>

<div class="table-card">
    <?php if (empty($guides['data'])): ?>
        <div class="text-center" style="padding:48px 24px;">
            <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <i class="fas fa-user-clock" style="font-size:2rem;color:var(--text-muted,#64748b);opacity:0.4;"></i>
            </div>
            <h5 class="fw-bold" style="color:var(--text-primary,#e2e8f0);">No guides found</h5>
            <p style="color:var(--text-muted,#94a3b8);">No guides match your current filter criteria.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Guide</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Today's Schedule</th>
                        <th>Rating</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guides['data'] as $g):
                        $todaySchedules = (new Schedule())->getGuideSchedule($g['id'], $today);
                        $avgRating = (new Feedback())->getAverageRating($g['id']);
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <img src="<?= get_avatar_url($g) ?>" class="guide-avatar" alt="">
                                <div style="margin-left:12px;">
                                    <div class="guide-name"><?= sanitize($g['name']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="guide-email"><?= sanitize($g['email']) ?></span></td>
                        <td style="color:var(--text-primary,#e2e8f0);font-size:0.85rem;"><?= sanitize($g['phone'] ?? 'N/A') ?></td>
                        <td>
                            <?php
                            $chipClass = match($g['status'] ?? '') {
                                'active' => 'available',
                                'inactive' => 'off-duty',
                                'suspended' => 'suspended',
                                'pending' => 'pending',
                                default => 'off-duty'
                            };
                            $chipLabel = match($g['status'] ?? '') {
                                'active' => 'Available',
                                'inactive' => 'Off Duty',
                                'suspended' => 'Suspended',
                                'pending' => 'Pending',
                                default => ucfirst($g['status'] ?? 'Unknown')
                            };
                            ?>
                            <span class="status-chip <?= $chipClass ?>"><?= $chipLabel ?></span>
                        </td>
                        <td>
                            <?php if (empty($todaySchedules)): ?>
                                <span class="schedule-badge none"><i class="fas fa-calendar-times"></i>No schedule</span>
                            <?php else: ?>
                                <?php foreach ($todaySchedules as $ts): ?>
                                    <span class="schedule-badge"><i class="fas fa-calendar-check"></i><?= sanitize($ts['event_title'] ?? 'N/A') ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="rating-display">
                                <span class="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star" style="color:<?= $i <= round($avgRating) ? '#f59e0b' : 'var(--text-muted,#4a5568)' ?>;"></i>
                                    <?php endfor; ?>
                                </span>
                                <span class="rating-value">(<?= number_format($avgRating, 1) ?>)</span>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <div style="display:flex;gap:6px;justify-content:center;">
                                <button class="action-btn view" data-bs-toggle="modal" data-bs-target="#profileModal<?= $g['id'] ?>" title="View Profile"><i class="fas fa-user"></i></button>
                                <button class="action-btn edit" data-bs-toggle="modal" data-bs-target="#statusModal<?= $g['id'] ?>" title="Update Status"><i class="fas fa-pen"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($guides['data'] as $g):
            $todaySchedules = (new Schedule())->getGuideSchedule($g['id'], $today);
            $avgRating = (new Feedback())->getAverageRating($g['id']);
            $chipClass = match($g['status'] ?? '') { 'active' => 'available', 'inactive' => 'off-duty', 'suspended' => 'suspended', default => 'off-duty' };
            $chipLabel = match($g['status'] ?? '') { 'active' => 'Available', 'inactive' => 'Off Duty', 'suspended' => 'Suspended', default => ucfirst($g['status'] ?? 'Unknown') };
        ?>
        <div class="modal fade" id="profileModal<?= $g['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div style="padding:20px 24px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;justify-content:space-between;">
                        <h6 class="mb-0 fw-bold" style="color:var(--text-primary,#e2e8f0);"><i class="fas fa-user-circle me-2" style="color:var(--primary,#0c6e5e);"></i>Guide Profile</h6>
                        <button type="button" class="btn p-0 border-0 bg-transparent" style="color:var(--text-muted,#94a3b8);" data-bs-dismiss="modal"><i class="fas fa-times"></i></button>
                    </div>
                    <div style="padding:24px;">
                        <div class="text-center mb-4">
                            <img src="<?= get_avatar_url($g) ?>" class="guide-avatar" style="width:80px;height:80px;border:3px solid var(--primary,#0c6e5e);" alt="">
                            <h5 class="fw-bold mt-3 mb-1" style="color:var(--text-primary,#e2e8f0);"><?= sanitize($g['name']) ?></h5>
                            <span class="status-chip <?= $chipClass ?>"><?= $chipLabel ?></span>
                        </div>
                        <div class="profile-detail"><span class="label"><i class="fas fa-envelope me-2"></i>Email</span><span class="value"><?= sanitize($g['email']) ?></span></div>
                        <div class="profile-detail"><span class="label"><i class="fas fa-phone me-2"></i>Phone</span><span class="value"><?= sanitize($g['phone'] ?? 'N/A') ?></span></div>
                        <div class="profile-detail"><span class="label"><i class="fas fa-star me-2"></i>Average Rating</span><span class="value"><span class="rating-stars"><?php for ($i = 1; $i <= 5; $i++): ?><i class="fas fa-star" style="color:<?= $i <= round($avgRating) ? '#f59e0b' : 'var(--text-muted,#4a5568)' ?>;"></i><?php endfor; ?></span> <?= number_format($avgRating, 1) ?></span></div>
                        <div class="profile-detail"><span class="label"><i class="fas fa-calendar me-2"></i>Registered</span><span class="value"><?= format_datetime($g['created_at'] ?? '') ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="statusModal<?= $g['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="guide_id" value="<?= $g['id'] ?>">
                        <div style="padding:20px 24px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;justify-content:space-between;">
                            <h6 class="mb-0 fw-bold" style="color:var(--text-primary,#e2e8f0);"><i class="fas fa-pen me-2" style="color:var(--primary,#0c6e5e);"></i>Update Status: <?= sanitize($g['name']) ?></h6>
                            <button type="button" class="btn p-0 border-0 bg-transparent" style="color:var(--text-muted,#94a3b8);" data-bs-dismiss="modal"><i class="fas fa-times"></i></button>
                        </div>
                        <div style="padding:24px;">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Availability Status</label>
                            <select name="availability_status" class="filter-input">
                                <option value="active" <?= ($g['status'] ?? '') === 'active' ? 'selected' : '' ?>>Available (Active)</option>
                                <option value="inactive" <?= ($g['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Off Duty (Inactive)</option>
                                <option value="suspended" <?= ($g['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            </select>
                        </div>
                        <div style="padding:16px 24px;border-top:1px solid var(--border-color,#2a3042);display:flex;gap:10px;justify-content:flex-end;">
                            <button type="button" class="btn-reset" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn-brand"><i class="fas fa-save me-1"></i>Update Status</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($guides['pages'] > 1): ?>
            <div style="padding:16px;display:flex;justify-content:center;">
                <nav>
                    <ul class="pagination mb-0">
                        <?php if ($guides['page'] > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $guides['page'] - 1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-left"></i></a></li>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $guides['pages']; $i++): ?>
                            <li class="page-item <?= $i == $guides['page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                        <?php if ($guides['page'] < $guides['pages']): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $guides['page'] + 1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-right"></i></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php }); ?>
