<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/Notification.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/tourist/feedback.php');
    }

    if (isset($_POST['submit_feedback'])) {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $communication_rating = max(1, min(5, (int)($_POST['communication_rating'] ?? 5)));
        $safety_rating = max(1, min(5, (int)($_POST['safety_rating'] ?? 5)));
        $organization_rating = max(1, min(5, (int)($_POST['organization_rating'] ?? 5)));
        $overall_rating = max(1, min(5, (int)($_POST['overall_rating'] ?? 5)));
        $comment = trim($_POST['comment'] ?? '');
        $suggestions = trim($_POST['suggestions'] ?? '');
        $complaints = trim($_POST['complaints'] ?? '');

        $check = $db->prepare("SELECT id FROM feedback WHERE booking_id = :bid AND tourist_id = :uid");
        $check->execute([':bid' => $booking_id, ':uid' => $user_id]);
        if ($check->fetch()) {
            flash_message('error', 'You have already submitted feedback for this booking.');
            redirect('/tourist/feedback.php');
        }

        $bk = $db->prepare(
            "SELECT b.schedule_id, s.guide_id FROM bookings b JOIN schedules s ON b.schedule_id = s.id WHERE b.id = :bid AND b.tourist_id = :uid AND b.status = 'completed'"
        );
        $bk->execute([':bid' => $booking_id, ':uid' => $user_id]);
        $booking = $bk->fetch();

        if (!$booking) {
            flash_message('error', 'Booking not found or not completed.');
            redirect('/tourist/feedback.php');
        }

        $guide_id = (int)($booking['guide_id'] ?? 0);
        $guide_rating = max(1, min(5, (int)($_POST['guide_rating'] ?? $overall_rating)));

        $insert = $db->prepare(
            "INSERT INTO feedback (booking_id, tourist_id, guide_id, schedule_id, guide_rating, communication_rating, safety_rating, organization_rating, overall_rating, comment, suggestions, complaints, created_at)
             VALUES (:booking_id, :tourist_id, :guide_id, :schedule_id, :guide_rating, :communication_rating, :safety_rating, :organization_rating, :overall_rating, :comment, :suggestions, :complaints, db_now())"
        );
        $insert->execute([
            ':booking_id' => $booking_id,
            ':tourist_id' => $user_id,
            ':guide_id' => $guide_id,
            ':schedule_id' => $booking['schedule_id'],
            ':guide_rating' => $guide_rating,
            ':communication_rating' => $communication_rating,
            ':safety_rating' => $safety_rating,
            ':organization_rating' => $organization_rating,
            ':overall_rating' => $overall_rating,
            ':comment' => $comment,
            ':suggestions' => $suggestions,
            ':complaints' => $complaints,
        ]);

        ActivityLog::log($user_id, 'feedback_submitted', "Submitted feedback for booking #{$booking_id}");
        flash_message('success', 'Feedback submitted successfully!');
        redirect('/tourist/feedback.php');
    }

    if (isset($_POST['delete_feedback'])) {
        $fid = (int)($_POST['feedback_id'] ?? 0);
        $del = $db->prepare("DELETE FROM feedback WHERE id = :id AND tourist_id = :uid");
        $del->execute([':id' => $fid, ':uid' => $user_id]);
        if ($del->rowCount() > 0) {
            flash_message('success', 'Feedback deleted.');
        } else {
            flash_message('error', 'Could not delete feedback.');
        }
        redirect('/tourist/feedback.php');
    }

    if (isset($_POST['submit_general_feedback'])) {
        $subject = trim($_POST['subject'] ?? '');
        $category = $_POST['category'] ?? 'general';
        $rating = isset($_POST['general_rating']) && $_POST['general_rating'] !== '' ? max(1, min(5, (int)$_POST['general_rating'])) : null;
        $message = trim($_POST['message'] ?? '');

        if ($subject === '') {
            flash_message('error', 'Please provide a subject.');
        } elseif ($message === '') {
            flash_message('error', 'Please write your feedback message.');
        } else {
            $valid_categories = ['general', 'suggestion', 'complaint', 'praise'];
            if (!in_array($category, $valid_categories)) $category = 'general';

            $ins = $db->prepare(
                "INSERT INTO general_feedback (tourist_id, subject, category, rating, message, status)
                 VALUES (:uid, :subject, :category, :rating, :message, 'pending')"
            );
            $ins->execute([
                ':uid' => $user_id,
                ':subject' => $subject,
                ':category' => $category,
                ':rating' => $rating,
                ':message' => $message,
            ]);
            ActivityLog::log($user_id, 'feedback_submitted', "Submitted general feedback: {$subject}");
            flash_message('success', 'General feedback submitted! Our team will review it soon.');
        }
        redirect('/tourist/feedback.php?tab=general');
    }

    if (isset($_POST['delete_general_feedback'])) {
        $fid = (int)($_POST['general_feedback_id'] ?? 0);
        $del = $db->prepare("DELETE FROM general_feedback WHERE id = :id AND tourist_id = :uid");
        $del->execute([':id' => $fid, ':uid' => $user_id]);
        if ($del->rowCount() > 0) {
            flash_message('success', 'General feedback deleted.');
        } else {
            flash_message('error', 'Could not delete feedback.');
        }
        redirect('/tourist/feedback.php?tab=general');
    }
}

$feedbacks_stmt = $db->prepare(
    "SELECT f.*,
            e.title as event_name, d.name as destination_name, s.start_date
     FROM feedback f
     LEFT JOIN bookings b ON f.booking_id = b.id
     LEFT JOIN schedules s ON b.schedule_id = s.id
     LEFT JOIN events e ON s.event_id = e.id
     LEFT JOIN destinations d ON e.destination_id = d.id
     WHERE f.tourist_id = :uid
     ORDER BY f.created_at DESC"
);
$feedbacks_stmt->execute([':uid' => $user_id]);
$feedbacks = $feedbacks_stmt->fetchAll();

$general_feedbacks_stmt = $db->prepare(
    "SELECT * FROM general_feedback WHERE tourist_id = :uid ORDER BY created_at DESC"
);
$general_feedbacks_stmt->execute([':uid' => $user_id]);
$general_feedbacks = $general_feedbacks_stmt->fetchAll();

$completed_no_feedback_stmt = $db->prepare(
    "SELECT b.id as booking_id, e.title as event_name, d.name as destination_name,
            s.start_date
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.id
     JOIN events e ON s.event_id = e.id
     JOIN destinations d ON e.destination_id = d.id
     WHERE b.tourist_id = :uid AND b.status = 'completed'
       AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.booking_id = b.id AND f.tourist_id = :uid2)
     ORDER BY s.start_date DESC"
);
$completed_no_feedback_stmt->execute([':uid' => $user_id, ':uid2' => $user_id]);
$pending_feedback = $completed_no_feedback_stmt->fetchAll();

$for_booking = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
$for_booking_info = null;
if ($for_booking > 0) {
    foreach ($pending_feedback as $pf) {
        if ((int)$pf['booking_id'] === $for_booking) {
            $for_booking_info = $pf;
            break;
        }
    }
}

$active_tab = $_GET['tab'] ?? 'submitted';
if (!in_array($active_tab, ['submitted', 'pending', 'general'])) $active_tab = 'submitted';
if ($for_booking_info) $active_tab = 'pending';

$category_badges = [
    'general'    => ['label' => 'General',    'color' => '#0c6e5e', 'bg' => 'rgba(12,110,94,0.1)', 'icon' => 'fa-message'],
    'suggestion' => ['label' => 'Suggestion', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.1)', 'icon' => 'fa-lightbulb'],
    'complaint'  => ['label' => 'Complaint',  'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.1)', 'icon' => 'fa-exclamation-circle'],
    'praise'     => ['label' => 'Praise',     'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.1)', 'icon' => 'fa-heart'],
];
$status_badges = [
    'pending'   => ['label' => 'Under Review', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
    'reviewed'  => ['label' => 'Reviewed',     'color' => '#3b82f6', 'bg' => '#dbeafe'],
    'published' => ['label' => 'Published',    'color' => '#10b981', 'bg' => '#d1fae5'],
];

render_page('tourist', 'feedback.php', 'My Feedback', function() use ($feedbacks, $pending_feedback, $for_booking_info, $general_feedbacks, $active_tab, $category_badges, $status_badges) {
?>
<style>
/* Banner */
.fb-hero{background:linear-gradient(135deg,#0c6e5e 0%,#0a5c4f 55%,#0e7490 100%);border-radius:20px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:0 16px 48px rgba(12,110,94,0.25);}
.fb-hero::before{content:'';position:absolute;top:-50px;right:-30px;width:200px;height:200px;background:rgba(255,255,255,0.07);border-radius:50%;}
.fb-hero::after{content:'';position:absolute;bottom:-40px;left:40px;width:140px;height:140px;background:rgba(255,255,255,0.04);border-radius:50%;}
.fb-hero .hero-icon-badge{width:56px;height:56px;border-radius:16px;background:rgba(255,255,255,0.14);border:1px solid rgba(255,255,255,0.2);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-right:16px;}

/* Tabs */
.fb-tabs{display:flex;gap:8px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:6px;margin-bottom:24px;overflow-x:auto;}
.fb-tab{flex:1;min-width:max-content;display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:10px;border:none;background:transparent;color:var(--text-muted,#64748b);font-weight:600;font-size:0.85rem;cursor:pointer;transition:all 0.25s;white-space:nowrap;}
.fb-tab:hover{background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#1e293b);}
.fb-tab.active{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;box-shadow:0 4px 14px rgba(12,110,94,0.3);}
.fb-tab .tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:20px;background:rgba(255,255,255,0.2);font-size:0.7rem;font-weight:700;}
.fb-tab:not(.active) .tab-count{background:var(--bg-secondary,#e2e8f0);color:var(--text-muted,#64748b);}
.fb-tab.active .tab-count{background:rgba(255,255,255,0.25);}

/* Panel */
.fb-panel{animation:fbFade 0.35s ease;}
@keyframes fbFade{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}

/* Cards */
.fb-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;overflow:hidden;}
.fb-card .fb-card-header{padding:20px 24px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;}
.fb-card .fb-card-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);}

/* Empty state */
.fb-empty{text-align:center;padding:56px 24px;}
.fb-empty .empty-art{width:120px;height:120px;margin:0 auto 20px;position:relative;}
.fb-empty .empty-art .ring{position:absolute;inset:0;border-radius:50%;border:2px dashed var(--border-color,#e2e8f0);animation:spinSlow 24s linear infinite;}
.fb-empty .empty-art .core{position:absolute;inset:18px;border-radius:50%;background:var(--bg-secondary,#f1f5f9);display:flex;align-items:center;justify-content:center;}
.fb-empty .empty-art .core i{font-size:2.2rem;color:var(--primary,#0c6e5e);opacity:0.5;}
.fb-empty .empty-art .float-icon{position:absolute;width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:0.85rem;}
@keyframes spinSlow{to{transform:rotate(360deg);}}
.fb-empty h5{font-weight:800;color:var(--text-primary,#1e293b);margin-bottom:6px;font-size:1.1rem;}
.fb-empty p{color:var(--text-muted,#94a3b8);font-size:0.88rem;margin-bottom:22px;}
.fb-empty .empty-cta{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
.fb-empty .empty-cta .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:10px;font-weight:600;font-size:0.85rem;}

/* Submitted feedback cards */
.fb-item{display:flex;gap:18px;padding:20px 24px;transition:background 0.2s;border-bottom:1px solid var(--border-color,#f1f5f9);}
.fb-item:last-child{border-bottom:none;}
.fb-item:hover{background:var(--bg-secondary,#fafafa);}
.fb-item .fb-avatar{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0c6e5e,#10b981);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.1rem;flex-shrink:0;}
.fb-item .fb-main{flex:1;min-width:0;}
.fb-item .fb-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.fb-item .fb-title{font-weight:700;font-size:0.95rem;color:var(--text-primary,#1e293b);margin-bottom:2px;}
.fb-item .fb-sub{font-size:0.78rem;color:var(--text-muted,#94a3b8);}
.fb-item .fb-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.fb-item .fb-stars{display:inline-flex;gap:2px;}
.fb-item .fb-stars i{font-size:0.72rem;color:#d1d5db;}
.fb-item .fb-stars i.filled{color:#f59e0b;}
.fb-item .fb-badges{display:flex;gap:8px;flex-wrap:wrap;}
.fb-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:8px;font-size:0.7rem;font-weight:600;}
.fb-comment{margin:10px 0 0;font-size:0.87rem;color:var(--text-secondary,#64748b);line-height:1.6;background:var(--bg-secondary,#f8fafc);border-radius:10px;padding:12px 16px;border-left:3px solid var(--primary,#0c6e5e);}
.fb-date-tag{display:inline-flex;align-items:center;gap:6px;font-size:0.72rem;color:var(--text-muted,#94a3b8);background:var(--bg-secondary,#f1f5f9);padding:4px 10px;border-radius:8px;font-weight:500;}
.fb-actions{display:flex;gap:6px;align-items:center;}
.fb-actions .action-btn{width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);transition:all 0.2s;font-size:0.8rem;}
.fb-actions .action-btn:hover{background:var(--primary,#0c6e5e);color:#fff;border-color:var(--primary,#0c6e5e);}
.fb-actions .action-btn.danger:hover{background:#ef4444;border-color:#ef4444;}

/* Rating bars */
.fb-rating-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:12px 0 0;}
.rating-bar-item .rb-label{font-size:0.7rem;color:var(--text-muted,#94a3b8);font-weight:600;text-transform:uppercase;letter-spacing:0.4px;margin-bottom:5px;display:flex;justify-content:space-between;}
.rating-bar-item .rb-track{height:6px;border-radius:6px;background:var(--bg-secondary,#e2e8f0);overflow:hidden;}
.rating-bar-item .rb-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,#f59e0b,#fbbf24);transition:width 0.6s ease;}

/* Pending cards */
.fb-pending-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;padding:20px;transition:all 0.25s;height:100%;display:flex;flex-direction:column;position:relative;overflow:hidden;}
.fb-pending-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#f59e0b,#fbbf24);}
.fb-pending-card:hover{box-shadow:0 10px 30px rgba(0,0,0,0.08);transform:translateY(-3px);}
.fb-pending-card .pend-icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#fef3c7,#fde68a);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.fb-pending-card h6{font-size:0.9rem;font-weight:700;color:var(--text-primary,#1e293b);margin-bottom:4px;}
.fb-pending-card .pend-date{font-size:0.75rem;color:var(--text-muted,#94a3b8);}
.fb-pending-card .btn-review{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;font-weight:600;font-size:0.82rem;padding:9px 18px;border:none;transition:all 0.25s;width:100%;}
.fb-pending-card .btn-review:hover{box-shadow:0 6px 18px rgba(12,110,94,0.35);transform:translateY(-1px);}

/* General feedback form */
.fb-form-input{border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:11px 14px;font-size:0.9rem;transition:all 0.2s;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);width:100%;}
.fb-form-input:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.fb-star-btn{font-size:1.5rem;background:none;border:none;padding:2px;cursor:pointer;transition:all 0.15s;color:#d1d5db;}
.fb-star-btn.active{color:#f59e0b;}
.fb-star-btn:hover{transform:scale(1.15);}
.gf-cat-select{display:flex;gap:8px;flex-wrap:wrap;}
.gf-cat-option{flex:1;min-width:130px;display:flex;align-items:center;gap:8px;padding:12px 14px;border-radius:12px;border:1.5px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);cursor:pointer;transition:all 0.2s;font-size:0.82rem;font-weight:600;color:var(--text-muted,#64748b);}
.gf-cat-option:hover{border-color:var(--primary,#0c6e5e);}
.gf-cat-option.selected{border-color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.06);color:var(--primary,#0c6e5e);}
.gf-cat-option input{position:absolute;opacity:0;pointer-events:none;}
.gf-cat-option i{font-size:1rem;}
</style>

<!-- Banner -->
<div class="fb-hero">
    <div class="position-relative d-flex align-items-center justify-content-between flex-wrap gap-3" style="z-index:1;">
        <div class="d-flex align-items-center">
            <div class="hero-icon-badge"><i class="fas fa-star"></i></div>
            <div>
                <h3 class="fw-bold mb-1">My Feedback</h3>
                <p class="mb-0 opacity-75" style="font-size:0.9rem;">Share your experiences and help us improve</p>
            </div>
        </div>
        <?php if (!empty($pending_feedback)): ?>
        <div class="d-flex align-items-center gap-2" style="background:rgba(245,158,11,0.25);border:1px solid rgba(245,158,11,0.35);border-radius:10px;padding:8px 16px;">
            <i class="fas fa-clock"></i>
            <span class="small fw-bold"><?= count($pending_feedback) ?> Pending Review<?= count($pending_feedback) !== 1 ? 's' : '' ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="fb-tabs">
    <button class="fb-tab <?= $active_tab === 'submitted' ? 'active' : '' ?>" data-tab="submitted">
        <i class="fas fa-check-circle"></i> Submitted Feedback
        <span class="tab-count"><?= count($feedbacks) ?></span>
    </button>
    <button class="fb-tab <?= $active_tab === 'pending' ? 'active' : '' ?>" data-tab="pending">
        <i class="fas fa-clock"></i> Pending Reviews
        <span class="tab-count"><?= count($pending_feedback) ?></span>
    </button>
    <button class="fb-tab <?= $active_tab === 'general' ? 'active' : '' ?>" data-tab="general">
        <i class="fas fa-message"></i> General Feedback
        <span class="tab-count"><?= count($general_feedbacks) ?></span>
    </button>
</div>

<!-- Tab: Submitted -->
<div class="fb-panel" id="panel-submitted" style="<?= $active_tab === 'submitted' ? '' : 'display:none;' ?>">
    <div class="fb-card">
        <?php if (empty($feedbacks)): ?>
        <div class="fb-empty">
            <div class="empty-art">
                <div class="ring"></div>
                <div class="core"><i class="fas fa-star"></i></div>
                <div class="float-icon" style="background:#d1fae5;top:0;right:6px;color:#10b981;"><i class="fas fa-thumbs-up"></i></div>
                <div class="float-icon" style="background:#fee2e2;bottom:4px;left:0;color:#ef4444;"><i class="fas fa-heart"></i></div>
            </div>
            <h5>No feedback submitted yet</h5>
            <p>Complete a tour to leave your feedback, or explore more destinations.</p>
            <div class="empty-cta">
                <a href="<?= BASE_URL ?>/tourist/destinations.php" class="btn" style="background:linear-gradient(135deg,#0c6e5e,#10b981);color:#fff;">
                    <i class="fas fa-compass"></i> Explore Available Tours
                </a>
                <a href="<?= BASE_URL ?>/tourist/bookings.php" class="btn" style="border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);">
                    <i class="fas fa-ticket-alt"></i> View My Bookings
                </a>
            </div>
        </div>
        <?php else: ?>
            <?php foreach ($feedbacks as $f):
                $avg = round(($f['overall_rating'] + $f['communication_rating'] + $f['safety_rating'] + $f['organization_rating']) / 4, 1);
            ?>
            <div class="fb-item">
                <div class="fb-avatar"><i class="fas fa-map-marked-alt"></i></div>
                <div class="fb-main">
                    <div class="fb-top">
                        <div>
                            <div class="fb-title"><?= sanitize($f['event_name'] ?? 'N/A') ?></div>
                            <div class="fb-sub"><i class="fas fa-map-marker-alt me-1" style="color:var(--primary);"></i><?= sanitize($f['destination_name'] ?? '') ?></div>
                        </div>
                        <div class="fb-actions">
                            <span class="fb-chip" style="background:#d1fae5;color:#059669;"><i class="fas fa-check-circle"></i> Published</span>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this feedback?');">
                                <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                                <input type="hidden" name="delete_feedback" value="1">
                                <input type="hidden" name="feedback_id" value="<?= $f['id'] ?>">
                                <button type="submit" class="action-btn danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="fb-meta mt-2">
                        <span class="fb-stars" title="Overall: <?= $f['overall_rating'] ?>/5">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?= $i <= $f['overall_rating'] ? 'filled' : '' ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <span class="fb-date-tag"><i class="fas fa-calendar-alt"></i><?= format_date($f['created_at']) ?></span>
                        <span class="fb-chip" style="background:#fef3c7;color:#b45309;"><i class="fas fa-star-half-alt"></i> <?= $avg ?> avg</span>
                    </div>
                    <div class="fb-rating-grid">
                        <?php $rating_bars = [
                            ['label' => 'Communication', 'val' => $f['communication_rating']],
                            ['label' => 'Safety', 'val' => $f['safety_rating']],
                            ['label' => 'Organization', 'val' => $f['organization_rating']],
                            ['label' => 'Overall', 'val' => $f['overall_rating']],
                        ]; ?>
                        <?php foreach ($rating_bars as $rb): ?>
                        <div class="rating-bar-item">
                            <div class="rb-label"><span><?= $rb['label'] ?></span><span><?= $rb['val'] ?>/5</span></div>
                            <div class="rb-track"><div class="rb-fill" style="width:<?= $rb['val'] * 20 ?>%;"></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($f['comment'])): ?>
                        <div class="fb-comment"><i class="fas fa-quote-left me-2" style="color:var(--primary);font-size:0.75rem;"></i><?= sanitize($f['comment']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Tab: Pending -->
<div class="fb-panel" id="panel-pending" style="<?= $active_tab === 'pending' ? '' : 'display:none;' ?>">
    <div class="fb-card">
        <div class="fb-card-header">
            <div style="width:36px;height:36px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-clock" style="color:#f59e0b;font-size:0.85rem;"></i>
            </div>
            <h6>Pending Reviews</h6>
            <span class="fb-chip ms-auto" style="background:#fef3c7;color:#b45309;"><i class="fas fa-hourglass-half"></i> <?= count($pending_feedback) ?> awaiting</span>
        </div>
        <?php if (empty($pending_feedback)): ?>
        <div class="fb-empty">
            <div class="empty-art">
                <div class="ring"></div>
                <div class="core"><i class="fas fa-clipboard-check"></i></div>
                <div class="float-icon" style="background:#d1fae5;top:0;right:6px;color:#10b981;"><i class="fas fa-check"></i></div>
            </div>
            <h5>All caught up!</h5>
            <p>You've reviewed all your completed bookings. Explore new adventures to review next.</p>
            <div class="empty-cta">
                <a href="<?= BASE_URL ?>/tourist/destinations.php" class="btn" style="background:linear-gradient(135deg,#0c6e5e,#10b981);color:#fff;">
                    <i class="fas fa-compass"></i> Explore Tours
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="p-3">
            <div class="row g-3">
                <?php foreach ($pending_feedback as $pf): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="fb-pending-card">
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <div class="pend-icon"><i class="fas fa-map-marker-alt" style="color:#f59e0b;"></i></div>
                            <div class="flex-grow-1">
                                <h6><?= sanitize($pf['event_name']) ?></h6>
                                <div class="pend-date"><i class="fas fa-location-dot me-1"></i><?= sanitize($pf['destination_name']) ?></div>
                                <div class="pend-date mt-1"><i class="fas fa-calendar-alt me-1"></i><?= format_date($pf['start_date'] ?? '') ?></div>
                            </div>
                        </div>
                        <a href="?booking_id=<?= $pf['booking_id'] ?>" class="btn-review">
                            <i class="fas fa-pen me-1"></i>Review This Tour
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tab: General -->
<div class="fb-panel" id="panel-general" style="<?= $active_tab === 'general' ? '' : 'display:none;' ?>">
    <!-- Form -->
    <div class="fb-card mb-4">
        <div class="fb-card-header">
            <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-message" style="color:#fff;font-size:0.85rem;"></i>
            </div>
            <h6>Share Your Thoughts</h6>
        </div>
        <div class="fb-card-body p-4">
            <form method="POST" id="generalForm">
                <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                <input type="hidden" name="submit_general_feedback" value="1">

                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Category</label>
                    <div class="gf-cat-select">
                        <?php foreach (['general' => ['label' => 'General', 'icon' => 'fa-message'], 'suggestion' => ['label' => 'Suggestion', 'icon' => 'fa-lightbulb'], 'complaint' => ['label' => 'Complaint', 'icon' => 'fa-exclamation-circle'], 'praise' => ['label' => 'Praise', 'icon' => 'fa-heart']] as $ck => $cv): ?>
                        <label class="gf-cat-option <?= $ck === 'general' ? 'selected' : '' ?>">
                            <input type="radio" name="category" value="<?= $ck ?>" <?= $ck === 'general' ? 'checked' : '' ?>>
                            <i class="fas <?= $cv['icon'] ?>"></i> <?= $cv['label'] ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;"><i class="fas fa-heading me-1" style="color:var(--primary);"></i>Subject</label>
                    <input type="text" name="subject" class="fb-form-input" placeholder="Brief summary of your feedback" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;"><i class="fas fa-star me-1" style="color:#f59e0b;"></i>Overall Rating <small class="text-muted">(optional)</small></label>
                    <div class="d-flex gap-1" data-field="general_rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" class="fb-star-btn" onclick="setRating('general_rating', <?= $i ?>)" data-value="<?= $i ?>"><i class="fas fa-star"></i></button>
                        <?php endfor; ?>
                        <input type="hidden" name="general_rating" id="rating_general_rating" value="">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;"><i class="fas fa-comment-dots me-1" style="color:var(--primary);"></i>Your Message</label>
                    <textarea name="message" class="fb-form-input" rows="4" placeholder="Tell us about your experience, suggestions, or concerns..." required></textarea>
                </div>

                <button type="submit" class="btn" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:11px 28px;font-weight:600;">
                    <i class="fas fa-paper-plane me-1"></i>Submit Feedback
                </button>
            </form>
        </div>
    </div>

    <!-- List -->
    <div class="fb-card">
        <div class="fb-card-header">
            <div style="width:36px;height:36px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-star" style="color:#3b82f6;font-size:0.85rem;"></i>
            </div>
            <h6>My General Feedback</h6>
            <span class="fb-chip ms-auto" style="background:#e2e8f0;color:#475569;"><i class="fas fa-layer-group"></i> <?= count($general_feedbacks) ?> item<?= count($general_feedbacks) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if (empty($general_feedbacks)): ?>
        <div class="fb-empty">
            <div class="empty-art">
                <div class="ring"></div>
                <div class="core"><i class="fas fa-message"></i></div>
                <div class="float-icon" style="background:#dbeafe;top:0;right:6px;color:#3b82f6;"><i class="fas fa-pen"></i></div>
            </div>
            <h5>No general feedback yet</h5>
            <p>Use the form above to share suggestions, praise, or concerns with our team.</p>
        </div>
        <?php else: ?>
            <?php foreach ($general_feedbacks as $gf):
                $cb = $category_badges[$gf['category']] ?? $category_badges['general'];
                $sb = $status_badges[$gf['status']] ?? $status_badges['pending'];
            ?>
            <div class="fb-item">
                <div class="fb-avatar" style="background:<?= $cb['bg'] ?>;color:<?= $cb['color'] ?>;font-size:1rem;"><i class="fas <?= $cb['icon'] ?>"></i></div>
                <div class="fb-main">
                    <div class="fb-top">
                        <div>
                            <div class="fb-title"><?= sanitize($gf['subject']) ?></div>
                            <div class="fb-sub"><?= ucfirst($gf['category']) ?> feedback</div>
                        </div>
                        <div class="fb-actions">
                            <span class="fb-chip" style="background:<?= $sb['bg'] ?>;color:<?= $sb['color'] ?>;"><i class="fas fa-circle" style="font-size:0.45rem;"></i> <?= $sb['label'] ?></span>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this feedback?');">
                                <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                                <input type="hidden" name="delete_general_feedback" value="1">
                                <input type="hidden" name="general_feedback_id" value="<?= $gf['id'] ?>">
                                <button type="submit" class="action-btn danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="fb-meta mt-2">
                        <?php if (!empty($gf['rating'])): ?>
                        <span class="fb-stars" title="Rating: <?= $gf['rating'] ?>/5">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?= $i <= $gf['rating'] ? 'filled' : '' ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <?php endif; ?>
                        <span class="fb-date-tag"><i class="fas fa-calendar-alt"></i><?= format_date($gf['created_at']) ?></span>
                        <span class="fb-chip" style="background:<?= $cb['bg'] ?>;color:<?= $cb['color'] ?>;"><i class="fas <?= $cb['icon'] ?>"></i> <?= $cb['label'] ?></span>
                    </div>
                    <?php if (!empty($gf['message'])): ?>
                        <div class="fb-comment"><i class="fas fa-quote-left me-2" style="color:var(--primary);font-size:0.75rem;"></i><?= sanitize($gf['message']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Inline review form for a pending booking -->
<?php if ($for_booking_info): ?>
<div class="fb-card mb-4">
    <div class="fb-card-header">
        <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-pen" style="color:#fff;font-size:0.85rem;"></i>
        </div>
        <h6>Leave Feedback for <?= sanitize($for_booking_info['event_name'] ?? 'Your Tour') ?></h6>
    </div>
    <div class="fb-card-body p-4">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
            <input type="hidden" name="submit_feedback" value="1">
            <input type="hidden" name="booking_id" value="<?= $for_booking_info['booking_id'] ?>">

            <div style="background:linear-gradient(135deg,rgba(12,110,94,0.08),rgba(26,138,122,0.04));border:1px solid rgba(12,110,94,0.15);border-radius:12px;padding:16px;margin-bottom:20px;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-route" style="color:#fff;"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="color:var(--text-primary,#1e293b);"><?= sanitize($for_booking_info['event_name'] ?? '') ?></div>
                        <small class="text-muted"><i class="fas fa-map-marker-alt me-1" style="color:var(--primary,#0c6e5e);"></i><?= sanitize($for_booking_info['destination_name'] ?? '') ?></small>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <?php
                $rating_fields = [
                    'communication_rating' => ['label' => 'Communication', 'icon' => 'fa-comments'],
                    'safety_rating' => ['label' => 'Safety', 'icon' => 'fa-shield-alt'],
                    'organization_rating' => ['label' => 'Organization', 'icon' => 'fa-clipboard-list'],
                    'overall_rating' => ['label' => 'Overall Experience', 'icon' => 'fa-star'],
                ];
                foreach ($rating_fields as $field => $info):
                ?>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;color:var(--text-primary,#1e293b);">
                        <i class="fas <?= $info['icon'] ?> me-1" style="color:var(--primary,#0c6e5e);"></i><?= $info['label'] ?>
                    </label>
                    <div class="d-flex gap-1" data-field="<?= $field ?>">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" class="fb-star-btn" onclick="setRating('<?= $field ?>', <?= $i ?>)" data-value="<?= $i ?>"><i class="fas fa-star"></i></button>
                        <?php endfor; ?>
                        <input type="hidden" name="<?= $field ?>" id="rating_<?= $field ?>" value="3">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size:0.85rem;"><i class="fas fa-comment me-1" style="color:var(--primary);"></i>Comments</label>
                <textarea name="comment" class="fb-form-input" rows="3" placeholder="Share your experience..."></textarea>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;"><i class="fas fa-lightbulb me-1" style="color:#f59e0b;"></i>Suggestions</label>
                    <textarea name="suggestions" class="fb-form-input" rows="3" placeholder="How can we improve?"></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;"><i class="fas fa-exclamation-circle me-1" style="color:#ef4444;"></i>Complaints</label>
                    <textarea name="complaints" class="fb-form-input" rows="3" placeholder="Any issues to report?"></textarea>
                </div>
            </div>

            <button type="submit" class="btn" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 28px;font-weight:600;">
                <i class="fas fa-paper-plane me-1"></i>Submit Feedback
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function setRating(field, value) {
    var hidden = document.getElementById('rating_' + field);
    if (hidden) hidden.value = value;
    var container = document.querySelector('[data-field="' + field + '"]');
    if (!container) return;
    var stars = container.querySelectorAll('.fb-star-btn');
    stars.forEach(function(star, index) {
        if (index < value) star.classList.add('active');
        else star.classList.remove('active');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-field]').forEach(function(container) {
        var field = container.dataset.field;
        var hidden = document.getElementById('rating_' + field);
        if (!hidden) return;
        var val = parseInt(hidden.value) || 0;
        if (val === 0 && field === 'general_rating') return;
        var stars = container.querySelectorAll('.fb-star-btn');
        stars.forEach(function(s, i) {
            if (i < val) s.classList.add('active');
        });
    });

    // Tabs
    document.querySelectorAll('.fb-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = this.dataset.tab;
            document.querySelectorAll('.fb-tab').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.fb-panel').forEach(function(p) { p.style.display = 'none'; });
            var panel = document.getElementById('panel-' + target);
            if (panel) panel.style.display = '';
        });
    });

    // Category selector
    document.querySelectorAll('.gf-cat-option').forEach(function(opt) {
        opt.addEventListener('click', function() {
            document.querySelectorAll('.gf-cat-option').forEach(function(o) { o.classList.remove('selected'); });
            this.classList.add('selected');
        });
    });
});
</script>

<?php }); ?>
