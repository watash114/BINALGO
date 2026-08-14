<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('guide');

$db = Database::getInstance()->getConnection();
$user = current_user();
$guide_id = $user['id'];

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$minRating = $_GET['min_rating'] ?? '';

$where = ["f.guide_id = :guide_id"];
$params = [':guide_id' => $guide_id];

if ($dateFrom !== '') {
    $where[] = "f.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = "f.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($minRating !== '') {
    $where[] = "f.overall_rating >= :min_rating";
    $params[':min_rating'] = (int)$minRating;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$statsStmt = $db->prepare(
    "SELECT
        COALESCE(AVG(f.overall_rating), 0) as avg_overall,
        COALESCE(AVG(f.guide_rating), 0) as avg_guide,
        COALESCE(AVG(f.communication_rating), 0) as avg_communication,
        COALESCE(AVG(f.safety_rating), 0) as avg_safety,
        COALESCE(AVG(f.organization_rating), 0) as avg_organization,
        COUNT(*) as total_reviews
     FROM feedback f
     WHERE f.guide_id = :guide_id"
);
$statsStmt->execute([':guide_id' => $guide_id]);
$stats = $statsStmt->fetch();

$breakdownStmt = $db->prepare(
    "SELECT
        SUM(CASE WHEN f.overall_rating = 5 THEN 1 ELSE 0 END) as five_star,
        SUM(CASE WHEN f.overall_rating = 4 THEN 1 ELSE 0 END) as four_star,
        SUM(CASE WHEN f.overall_rating = 3 THEN 1 ELSE 0 END) as three_star,
        SUM(CASE WHEN f.overall_rating = 2 THEN 1 ELSE 0 END) as two_star,
        SUM(CASE WHEN f.overall_rating = 1 THEN 1 ELSE 0 END) as one_star
     FROM feedback f
     WHERE f.guide_id = :guide_id"
);
$breakdownStmt->execute([':guide_id' => $guide_id]);
$breakdown = $breakdownStmt->fetch();

$totalReviews = (int)$stats['total_reviews'];

$feedbackStmt = $db->prepare(
    "SELECT f.*, u.name as tourist_name
     FROM feedback f
     LEFT JOIN users u ON f.tourist_id = u.id
     {$whereClause}
     ORDER BY f.created_at DESC"
);
$feedbackStmt->execute($params);
$feedbacks = $feedbackStmt->fetchAll();

function renderStars(int $rating): string
{
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star text-warning"></i>';
        } else {
            $html .= '<i class="fas fa-star text-muted"></i>';
        }
    }
    return $html;
}

render_page('guide', 'feedback.php', 'My Feedback', function () use ($stats, $breakdown, $totalReviews, $feedbacks, $guide_id, $dateFrom, $dateTo, $minRating) {
?>
<style>
.guide-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.guide-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.guide-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.fb-stat{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:24px;text-align:center;transition:all 0.25s;height:100%;}
.fb-stat:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2);}
.section-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;gap:10px;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#e2e8f0);}
.rating-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
.rating-label{font-size:0.85rem;color:var(--text-muted,#94a3b8);width:60px;flex-shrink:0;}
.rating-bar{flex:1;height:10px;background:rgba(255,255,255,0.06);border-radius:5px;overflow:hidden;}
.rating-bar-fill{height:100%;border-radius:5px;transition:width 0.3s;}
.rating-count{font-size:0.8rem;color:var(--text-muted,#94a3b8);width:50px;text-align:right;}
.filter-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:16px 20px;margin-bottom:20px;}
.filter-input{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 14px;color:var(--text-primary,#e2e8f0);width:100%;font-size:0.9rem;}
.filter-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.2);}
.feedback-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:20px;margin-bottom:12px;transition:all 0.2s;}
.feedback-card:hover{box-shadow:0 4px 12px rgba(0,0,0,0.15);}
.rating-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:0.72rem;font-weight:600;background:rgba(255,255,255,0.05);color:var(--text-primary,#e2e8f0);}
.rating-badge i{color:#f59e0b;font-size:0.6rem;}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.btn-reset{background:rgba(255,255,255,0.08);color:var(--text-primary,#e2e8f0);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 20px;font-weight:600;}
.btn-reset:hover{background:rgba(255,255,255,0.12);color:var(--text-primary,#e2e8f0);}
</style>

<div class="guide-hero">
    <div class="position-relative" style="z-index:1;">
        <h3 class="fw-bold mb-1"><i class="fas fa-star me-2"></i>My Feedback</h3>
        <p class="mb-0 opacity-75" style="font-size:0.9rem;">View ratings and reviews from tourists</p>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="fb-stat">
            <div class="mb-2">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="fas fa-star" style="font-size:1.5rem;<?= $i <= round((float)$stats['avg_overall']) ? 'color:#f59e0b;' : 'color:var(--text-muted,#4a5568);' ?>"></i>
                <?php endfor; ?>
            </div>
            <div style="font-size:2.5rem;font-weight:800;color:var(--primary,#0c6e5e);"><?= number_format((float)$stats['avg_overall'], 1) ?></div>
            <div style="font-size:0.85rem;color:var(--text-muted,#94a3b8);margin-top:4px;">Average Overall Rating</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fb-stat">
            <div style="font-size:2.5rem;font-weight:800;color:var(--text-primary,#e2e8f0);"><?= $totalReviews ?></div>
            <div style="font-size:0.85rem;color:var(--text-muted,#94a3b8);margin-top:4px;">Total Reviews</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="fb-stat">
            <h6 class="fw-bold mb-3" style="color:var(--text-primary,#e2e8f0);text-align:left;">Rating Averages</h6>
            <div class="d-flex justify-content-between mb-2" style="text-align:left;">
                <span style="font-size:0.85rem;color:var(--text-muted,#94a3b8);">Guide Performance</span>
                <span class="fw-semibold" style="color:var(--text-primary,#e2e8f0);"><?= number_format((float)$stats['avg_guide'], 1) ?>/5</span>
            </div>
            <div class="d-flex justify-content-between mb-2" style="text-align:left;">
                <span style="font-size:0.85rem;color:var(--text-muted,#94a3b8);">Communication</span>
                <span class="fw-semibold" style="color:var(--text-primary,#e2e8f0);"><?= number_format((float)$stats['avg_communication'], 1) ?>/5</span>
            </div>
            <div class="d-flex justify-content-between mb-2" style="text-align:left;">
                <span style="font-size:0.85rem;color:var(--text-muted,#94a3b8);">Safety</span>
                <span class="fw-semibold" style="color:var(--text-primary,#e2e8f0);"><?= number_format((float)$stats['avg_safety'], 1) ?>/5</span>
            </div>
            <div class="d-flex justify-content-between" style="text-align:left;">
                <span style="font-size:0.85rem;color:var(--text-muted,#94a3b8);">Organization</span>
                <span class="fw-semibold" style="color:var(--text-primary,#e2e8f0);"><?= number_format((float)$stats['avg_organization'], 1) ?>/5</span>
            </div>
        </div>
    </div>
</div>

<div class="section-card mb-4">
    <div class="section-header">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-chart-bar" style="color:#3b82f6;font-size:0.8rem;"></i>
        </div>
        <h6>Rating Breakdown</h6>
    </div>
    <div style="padding:20px;">
        <?php
        $levels = [
            5 => ['label' => '5 Stars', 'count' => (int)($breakdown['five_star'] ?? 0), 'color' => '#22c55e'],
            4 => ['label' => '4 Stars', 'count' => (int)($breakdown['four_star'] ?? 0), 'color' => '#84cc16'],
            3 => ['label' => '3 Stars', 'count' => (int)($breakdown['three_star'] ?? 0), 'color' => '#f59e0b'],
            2 => ['label' => '2 Stars', 'count' => (int)($breakdown['two_star'] ?? 0), 'color' => '#f97316'],
            1 => ['label' => '1 Star',  'count' => (int)($breakdown['one_star'] ?? 0),  'color' => '#ef4444'],
        ];
        foreach ($levels as $level => $data):
            $pct = $totalReviews > 0 ? ($data['count'] / $totalReviews) * 100 : 0;
        ?>
            <div class="rating-row">
                <div class="rating-label"><?= $data['label'] ?></div>
                <div class="rating-bar">
                    <div class="rating-bar-fill" style="width:<?= $pct ?>%;background:<?= $data['color'] ?>;"></div>
                </div>
                <div class="rating-count"><?= number_format($pct, 0) ?>% (<?= $data['count'] ?>)</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">From Date</label>
            <input type="date" name="date_from" class="filter-input" value="<?= sanitize($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">To Date</label>
            <input type="date" name="date_to" class="filter-input" value="<?= sanitize($dateTo) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">Min Rating</label>
            <select name="min_rating" class="filter-input">
                <option value="">Any</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= $minRating == $i ? 'selected' : '' ?>><?= $i ?>+ Stars</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn-brand"><i class="fas fa-search me-1"></i>Filter</button>
            <a href="feedback.php" class="btn-reset"><i class="fas fa-redo me-1"></i>Reset</a>
        </div>
    </form>
</div>

<?php if (empty($feedbacks)): ?>
    <div style="background:var(--card-bg,#1a1f2e);border-radius:14px;border:1px solid var(--border-color,#2a3042);padding:48px 24px;text-align:center;">
        <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-star" style="font-size:2rem;color:var(--text-muted,#64748b);opacity:0.4;"></i>
        </div>
        <h5 class="fw-bold mb-1" style="color:var(--text-primary,#e2e8f0);">No feedback found</h5>
        <p class="small" style="color:var(--text-muted,#94a3b8);">Complete tours to start receiving feedback from tourists.</p>
    </div>
<?php else: ?>
    <?php foreach ($feedbacks as $f): ?>
    <div class="feedback-card">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h6 class="fw-bold mb-0" style="color:var(--text-primary,#e2e8f0);"><?= sanitize($f['tourist_name'] ?? 'Anonymous') ?></h6>
                <small style="color:var(--text-muted,#94a3b8);"><?= format_date($f['created_at']) ?></small>
            </div>
            <div class="text-warning">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="fas fa-star" style="font-size:0.8rem;<?= $i <= (int)$f['overall_rating'] ? '' : 'color:var(--text-muted,#4a5568);' ?>"></i>
                <?php endfor; ?>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-sm-6 col-lg-3">
                <div class="rating-badge w-100 justify-content-center py-2">
                    <i class="fas fa-user-tie"></i>
                    <span>Guide: <?= $f['guide_rating'] ?>/5</span>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="rating-badge w-100 justify-content-center py-2">
                    <i class="fas fa-comments"></i>
                    <span>Comm: <?= $f['communication_rating'] ?>/5</span>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="rating-badge w-100 justify-content-center py-2">
                    <i class="fas fa-shield-alt"></i>
                    <span>Safety: <?= $f['safety_rating'] ?>/5</span>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="rating-badge w-100 justify-content-center py-2">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Org: <?= $f['organization_rating'] ?>/5</span>
                </div>
            </div>
        </div>

        <?php if (!empty($f['comment'])): ?>
            <div class="mb-2">
                <span class="fw-semibold small" style="color:var(--text-primary,#e2e8f0);"><i class="fas fa-comment me-1" style="color:var(--primary);"></i>Comment:</span>
                <span style="color:var(--text-muted,#94a3b8);font-size:0.9rem;"><?= nl2br(sanitize($f['comment'])) ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($f['suggestions'])): ?>
            <div class="mb-2">
                <span class="fw-semibold small" style="color:#10b981;"><i class="fas fa-lightbulb me-1"></i>Suggestions:</span>
                <span style="color:var(--text-muted,#94a3b8);font-size:0.9rem;"><?= nl2br(sanitize($f['suggestions'])) ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($f['complaints'])): ?>
            <div class="mb-0">
                <span class="fw-semibold small" style="color:#ef4444;"><i class="fas fa-exclamation-triangle me-1"></i>Complaints:</span>
                <span style="color:var(--text-muted,#94a3b8);font-size:0.9rem;"><?= nl2br(sanitize($f['complaints'])) ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php }); ?>
