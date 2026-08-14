<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');

$db = Database::getInstance()->getConnection();

if (is_post()) {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/staff/feedback.php');
    }

    if (isset($_POST['delete_feedback'])) {
        $fid = (int)($_POST['feedback_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM feedback WHERE id = :id");
        $stmt->execute([':id' => $fid]);
        if ($stmt->rowCount() > 0) {
            flash_message('success', 'Feedback deleted successfully.');
        } else {
            flash_message('error', 'Could not delete feedback.');
        }
        redirect('/staff/feedback.php');
    }
}

$guideFilter = $_GET['guide_id'] ?? '';
$minRating = $_GET['min_rating'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = [];
$params = [];

if ($guideFilter !== '') {
    $where[] = "f.guide_id = :guide_id";
    $params[':guide_id'] = (int)$guideFilter;
}
if ($minRating !== '') {
    $where[] = "f.overall_rating >= :min_rating";
    $params[':min_rating'] = (int)$minRating;
}
if ($dateFrom !== '') {
    $where[] = "f.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = "f.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$statsStmt = $db->query(
    "SELECT
        COALESCE(AVG(f.guide_rating), 0) as avg_guide_rating,
        COALESCE(AVG(f.overall_rating), 0) as avg_overall_rating,
        COUNT(*) as total_reviews,
        COALESCE(AVG(f.communication_rating), 0) as avg_communication,
        COALESCE(AVG(f.safety_rating), 0) as avg_safety,
        COALESCE(AVG(f.organization_rating), 0) as avg_organization,
        COALESCE(SUM(f.overall_rating = 5), 0) as r5,
        COALESCE(SUM(f.overall_rating = 4), 0) as r4,
        COALESCE(SUM(f.overall_rating = 3), 0) as r3,
        COALESCE(SUM(f.overall_rating = 2), 0) as r2,
        COALESCE(SUM(f.overall_rating = 1), 0) as r1
     FROM feedback f"
);
$stats = $statsStmt->fetch();

$feedbackStmt = $db->prepare(
    "SELECT f.*,
            u1.name as tourist_name,
            u2.name as guide_name
     FROM feedback f
     LEFT JOIN users u1 ON f.tourist_id = u1.id
     LEFT JOIN users u2 ON f.guide_id = u2.id
     {$whereClause}
     ORDER BY f.created_at DESC"
);
$feedbackStmt->execute($params);
$feedbacks = $feedbackStmt->fetchAll();

$guidesStmt = $db->query("SELECT id, name FROM users WHERE role = 'guide' ORDER BY name ASC");
$guides = $guidesStmt->fetchAll();

$totalReviews = max(1, (int)$stats['total_reviews']);

function renderStars(int $rating): string
{
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star" style="color:#f59e0b;font-size:0.75rem;"></i>';
        } else {
            $html .= '<i class="fas fa-star" style="color:var(--border-color,#cbd5e1);font-size:0.75rem;"></i>';
        }
    }
    return $html;
}

render_page('staff', 'feedback.php', 'Feedback Review', function () use ($stats, $feedbacks, $guides, $guideFilter, $minRating, $dateFrom, $dateTo, $totalReviews) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;}
.filter-card .form-control,.filter-card .form-select{border-radius:10px;border:1px solid var(--border-color,#e2e8f0);padding:10px 14px;font-size:0.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);}
.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.table-card .table{margin-bottom:0;color:var(--text-primary,#1e293b);}
.table-card .table thead th{background:var(--bg-secondary,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0);font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:0.5px;padding:12px 16px;}
.table-card .table tbody td{padding:12px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);font-size:0.88rem;vertical-align:middle;}
.table-card .table tbody tr:last-child td{border-bottom:none;}
.table-card .table tbody tr:hover{background:rgba(12,110,94,0.02);}
.action-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;font-size:0.8rem;}
.action-btn:hover{border-color:var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.05);}
.action-btn.danger:hover{border-color:#dc2626;color:#dc2626;background:rgba(220,38,38,0.05);}
.fb-avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color,#e2e8f0);flex-shrink:0;}
.rating-label{font-size:0.82rem;font-weight:600;color:var(--text-primary,#1e293b);}
.rating-sub{font-size:0.78rem;color:var(--text-muted,#64748b);}
</style>

<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-star me-2"></i>Feedback Review</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Review tourist feedback and guide performance ratings</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.12);padding:6px 14px;border-radius:8px;font-size:0.85rem;"><i class="fas fa-star"></i>Avg Overall <?= number_format((float)$stats['avg_overall_rating'], 1) ?>/5</span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="mini-stat">
            <div class="mini-stat-icon" style="background:rgba(245,158,11,0.12);"><i class="fas fa-user-tie" style="color:#f59e0b;"></i></div>
            <div>
                <div class="mini-stat-value"><?= number_format((float)$stats['avg_guide_rating'], 1) ?><span style="font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);">/5</span></div>
                <div class="mini-stat-label">Average Guide Rating</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="mini-stat">
            <div class="mini-stat-icon" style="background:rgba(34,197,94,0.12);"><i class="fas fa-thumbs-up" style="color:#22c55e;"></i></div>
            <div>
                <div class="mini-stat-value"><?= number_format((float)$stats['avg_overall_rating'], 1) ?><span style="font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);">/5</span></div>
                <div class="mini-stat-label">Average Overall Rating</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="mini-stat">
            <div class="mini-stat-icon" style="background:rgba(59,130,246,0.12);"><i class="fas fa-comments" style="color:#3b82f6;"></i></div>
            <div>
                <div class="mini-stat-value"><?= (int)$stats['total_reviews'] ?></div>
                <div class="mini-stat-label">Total Reviews</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="mini-stat">
            <div class="mini-stat-icon" style="background:rgba(6,182,212,0.12);"><i class="fas fa-shield-halved" style="color:#06b6d4;"></i></div>
            <div>
                <div class="mini-stat-value"><?= number_format((float)$stats['avg_safety'], 1) ?><span style="font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);">/5</span></div>
                <div class="mini-stat-label">Avg Safety</div>
            </div>
        </div>
    </div>
</div>

<div class="filter-card mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">Guide</label>
            <select name="guide_id" class="form-select">
                <option value="">All Guides</option>
                <?php foreach ($guides as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $guideFilter == $g['id'] ? 'selected' : '' ?>><?= sanitize($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">Min Rating</label>
            <select name="min_rating" class="form-select">
                <option value="">Any</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= $minRating == $i ? 'selected' : '' ?>><?= $i ?>+ Stars</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">From Date</label>
            <input type="date" name="date_from" class="form-control" value="<?= sanitize($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">To Date</label>
            <input type="date" name="date_to" class="form-control" value="<?= sanitize($dateTo) ?>">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm" style="background:var(--primary,#0c6e5e);color:#fff;border-radius:8px;padding:8px 16px;"><i class="fas fa-search me-1"></i>Filter</button>
            <a href="feedback.php" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:8px;padding:8px 16px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);"><i class="fas fa-redo me-1"></i>Reset</a>
        </div>
    </form>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="table-card">
            <?php if (empty($feedbacks)): ?>
                <div class="empty-state">
                    <div class="empty-illustration">
                        <i class="fas fa-star"></i>
                        <span class="empty-ring"></span>
                    </div>
                    <div class="empty-title">No feedback found</div>
                    <p class="empty-text">No feedback matches your current filters. Try widening the date range or clearing the guide filter.</p>
                    <div class="empty-actions">
                        <a href="feedback.php" class="btn-cta ghost"><i class="fas fa-redo me-1"></i>Reset Filters</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Tourist</th>
                                <th>Guide</th>
                                <th>Guide Rating</th>
                                <th>Overall</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feedbacks as $f): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="<?= get_avatar_url(['id' => $f['tourist_id'], 'name' => $f['tourist_name'], 'avatar' => null]) ?>" class="fb-avatar" alt="">
                                        <div>
                                            <div class="rating-label"><?= sanitize($f['tourist_name'] ?? 'N/A') ?></div>
                                            <div class="rating-sub"><?= format_date($f['created_at']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="rating-label"><?= sanitize($f['guide_name'] ?? 'N/A') ?></td>
                                <td><?= renderStars((int)$f['guide_rating']) ?></td>
                                <td>
                                    <?= renderStars((int)$f['overall_rating']) ?>
                                    <span class="ms-1 fw-bold" style="color:var(--text-primary,#1e293b);font-size:0.82rem;"><?= (int)$f['overall_rating'] ?></span>
                                </td>
                                <td class="text-muted small"><?= time_ago($f['created_at']) ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="action-btn" data-bs-toggle="modal" data-bs-target="#detailModal<?= $f['id'] ?>" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this feedback?');">
                                            <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                                            <input type="hidden" name="delete_feedback" value="1">
                                            <input type="hidden" name="feedback_id" value="<?= $f['id'] ?>">
                                            <button type="submit" class="action-btn danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="section-card" style="background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;">
            <div class="section-header" style="padding:16px 20px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-chart-simple" style="color:#f59e0b;font-size:0.7rem;"></i>
                </div>
                <h6 class="mb-0 fw-bold" style="color:var(--text-primary,#1e293b);">Rating Breakdown</h6>
            </div>
            <div style="padding:20px;">
                <div class="rating-breakdown mb-4">
                    <?php for ($star = 5; $star >= 1; $star--): ?>
                    <?php $cnt = (int)($stats['r' . $star] ?? 0); $pct = round(($cnt / $totalReviews) * 100); ?>
                    <div class="rb-row">
                        <span class="rb-label"><?= $star ?> <i class="fas fa-star" style="color:#f59e0b;font-size:0.6rem;"></i></span>
                        <div class="rb-track"><div class="rb-fill" style="width:<?= $pct ?>%;"></div></div>
                        <span style="width:36px;text-align:right;color:var(--text-muted,#64748b);font-weight:600;"><?= $cnt ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="d-flex flex-column gap-2">
                    <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:var(--bg-secondary,#f8fafc);border-radius:10px;">
                        <span class="small" style="color:var(--text-muted,#64748b);">Communication</span>
                        <span class="fw-bold" style="color:var(--text-primary,#1e293b);font-size:0.9rem;"><?= number_format((float)$stats['avg_communication'], 1) ?>/5</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:var(--bg-secondary,#f8fafc);border-radius:10px;">
                        <span class="small" style="color:var(--text-muted,#64748b);">Safety</span>
                        <span class="fw-bold" style="color:var(--text-primary,#1e293b);font-size:0.9rem;"><?= number_format((float)$stats['avg_safety'], 1) ?>/5</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:var(--bg-secondary,#f8fafc);border-radius:10px;">
                        <span class="small" style="color:var(--text-muted,#64748b);">Organization</span>
                        <span class="fw-bold" style="color:var(--text-primary,#1e293b);font-size:0.9rem;"><?= number_format((float)$stats['avg_organization'], 1) ?>/5</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($feedbacks as $f): ?>
<div class="modal fade" id="detailModal<?= $f['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);">
            <div class="modal-header" style="border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;">
                <h6 class="modal-title fw-bold" style="color:#fff;"><i class="fas fa-star me-2"></i>Feedback Details</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px 24px;">
                <div class="booking-info-grid">
                    <div class="bi-item"><div class="bi-label">Tourist</div><div class="bi-value"><?= sanitize($f['tourist_name'] ?? 'N/A') ?></div></div>
                    <div class="bi-item"><div class="bi-label">Guide</div><div class="bi-value"><?= sanitize($f['guide_name'] ?? 'N/A') ?></div></div>
                    <div class="bi-item"><div class="bi-label">Booking ID</div><div class="bi-value">#<?= (int)$f['booking_id'] ?></div></div>
                    <div class="bi-item"><div class="bi-label">Submitted</div><div class="bi-value"><?= format_datetime($f['created_at']) ?></div></div>
                </div>
                <h6 class="fw-bold mb-3" style="color:var(--text-primary,#1e293b);font-size:0.9rem;">Ratings</h6>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:var(--bg-secondary,#f8fafc);border-radius:10px;">
                            <span class="small" style="color:var(--text-muted,#64748b);">Guide</span>
                            <span class="fw-bold" style="color:var(--text-primary,#1e293b);"><?= renderStars((int)$f['guide_rating']) ?></span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:var(--bg-secondary,#f8fafc);border-radius:10px;">
                            <span class="small" style="color:var(--text-muted,#64748b);">Communication</span>
                            <span class="fw-bold" style="color:var(--text-primary,#1e293b);"><?= renderStars((int)$f['communication_rating']) ?></span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:var(--bg-secondary,#f8fafc);border-radius:10px;">
                            <span class="small" style="color:var(--text-muted,#64748b);">Safety</span>
                            <span class="fw-bold" style="color:var(--text-primary,#1e293b);"><?= renderStars((int)$f['safety_rating']) ?></span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:var(--bg-secondary,#f8fafc);border-radius:10px;">
                            <span class="small" style="color:var(--text-muted,#64748b);">Organization</span>
                            <span class="fw-bold" style="color:var(--text-primary,#1e293b);"><?= renderStars((int)$f['organization_rating']) ?></span>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center" style="padding:10px 12px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2);border-radius:10px;">
                            <span class="small fw-semibold" style="color:#d97706;">Overall Experience</span>
                            <span class="fw-bold" style="color:#d97706;font-size:1rem;"><?= renderStars((int)$f['overall_rating']) ?> <?= (int)$f['overall_rating'] ?>/5</span>
                        </div>
                    </div>
                </div>
                <?php if (!empty($f['comment'])): ?>
                    <h6 class="fw-bold mb-1" style="color:var(--text-primary,#1e293b);font-size:0.85rem;">Comment</h6>
                    <p style="color:var(--text-secondary,#475569);font-size:0.88rem;line-height:1.6;"><?= nl2br(sanitize($f['comment'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($f['suggestions'])): ?>
                    <h6 class="fw-bold mb-1" style="color:var(--text-primary,#1e293b);font-size:0.85rem;">Suggestions</h6>
                    <p style="color:var(--text-secondary,#475569);font-size:0.88rem;line-height:1.6;"><?= nl2br(sanitize($f['suggestions'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($f['complaints'])): ?>
                    <h6 class="fw-bold mb-1" style="color:var(--text-primary,#1e293b);font-size:0.85rem;">Complaints</h6>
                    <p style="color:#dc2626;font-size:0.88rem;line-height:1.6;"><?= nl2br(sanitize($f['complaints'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border-color,#f1f5f9);padding:14px 24px;">
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this feedback?');">
                    <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                    <input type="hidden" name="delete_feedback" value="1">
                    <input type="hidden" name="feedback_id" value="<?= $f['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="border:1px solid #dc2626;color:#dc2626;background:rgba(220,38,38,0.05);border-radius:8px;"><i class="fas fa-trash me-1"></i>Delete</button>
                </form>
                <button type="button" class="btn btn-sm" style="background:var(--primary,#0c6e5e);color:#fff;border-radius:8px;" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php }); ?>