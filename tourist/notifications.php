<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        redirect('/tourist/notifications.php');
    }
    if (isset($_POST['mark_read'])) {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")
           ->execute([':id' => $nid, ':uid' => $user_id]);
        redirect('/tourist/notifications.php');
    }
    if (isset($_POST['mark_all_read'])) {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0")
           ->execute([':uid' => $user_id]);
        flash_message('success', 'All notifications marked as read.');
        redirect('/tourist/notifications.php');
    }
    if (isset($_POST['delete_notification'])) {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid")
           ->execute([':id' => $nid, ':uid' => $user_id]);
        redirect('/tourist/notifications.php');
    }
    if (isset($_POST['clear_all'])) {
        $db->prepare("DELETE FROM notifications WHERE user_id = :uid")
           ->execute([':uid' => $user_id]);
        flash_message('success', 'All notifications cleared.');
        redirect('/tourist/notifications.php');
    }
}

$filter_type = $_GET['type'] ?? '';
$filter_read = $_GET['read'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

$where = ["n.user_id = :uid"];
$params = [':uid' => $user_id];

if ($filter_type !== '') {
    $where[] = "n.type = :type";
    $params[':type'] = $filter_type;
}
if ($filter_read === 'unread') {
    $where[] = "n.is_read = 0";
} elseif ($filter_read === 'read') {
    $where[] = "n.is_read = 1";
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications n {$where_clause}");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetch()['cnt'];
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$unread_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications n WHERE n.user_id = :uid AND n.is_read = 0");
$unread_stmt->execute([':uid' => $user_id]);
$unread_count = (int) $unread_stmt->fetch()['cnt'];

$notif_stmt = $db->prepare(
    "SELECT n.* FROM notifications n {$where_clause} ORDER BY n.created_at DESC LIMIT {$per_page} OFFSET {$offset}"
);
$notif_stmt->execute($params);
$notifications = $notif_stmt->fetchAll();

render_page('tourist', 'notifications.php', 'Notifications', function () use ($notifications, $total, $total_pages, $page, $filter_type, $filter_read, $unread_count, $user_id, $db) {
?>
<style>
/* Hero */
.notif-hero{background:linear-gradient(135deg,#0c6e5e 0%,#0a5c4f 55%,#0e7490 100%);border-radius:20px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:0 16px 48px rgba(12,110,94,0.25);}
.notif-hero::before{content:'';position:absolute;top:-50px;right:-30px;width:200px;height:200px;background:rgba(255,255,255,0.07);border-radius:50%;}
.notif-hero::after{content:'';position:absolute;bottom:-40px;left:40px;width:140px;height:140px;background:rgba(255,255,255,0.04);border-radius:50%;}
.notif-hero .hero-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.16);border:1px solid rgba(255,255,255,0.25);backdrop-filter:blur(6px);border-radius:50px;padding:7px 16px;font-size:0.82rem;font-weight:600;}
.notif-hero .hero-badge.red{background:rgba(239,68,68,0.3);border-color:rgba(239,68,68,0.4);}
.notif-hero .hero-badge .dot{width:8px;height:8px;border-radius:50%;display:inline-block;}

/* Toolbar */
.notif-toolbar{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;padding:14px 16px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(0,0,0,0.02);}
.notif-toolbar .toolbar-section{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.toolbar-label{font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--text-muted,#94a3b8);margin-right:2px;}
.filter-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:0.8rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-secondary,#64748b);text-decoration:none;transition:all 0.2s;}
.filter-pill:hover{border-color:var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);transform:translateY(-1px);}
.filter-pill.active{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-color:var(--primary,#0c6e5e);box-shadow:0 3px 10px rgba(12,110,94,0.25);}
.filter-pill .pill-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 5px;border-radius:20px;background:rgba(0,0,0,0.08);font-size:0.68rem;font-weight:700;}
.filter-pill.active .pill-count{background:rgba(255,255,255,0.25);}

/* Read status segmented */
.seg-group{display:inline-flex;background:var(--bg-secondary,#f1f5f9);border-radius:10px;padding:4px;gap:2px;}
.seg-btn{padding:7px 16px;border-radius:8px;font-size:0.8rem;font-weight:600;border:none;background:transparent;color:var(--text-muted,#64748b);cursor:pointer;transition:all 0.2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.seg-btn:hover{color:var(--text-primary,#1e293b);}
.seg-btn.active{background:var(--card-bg,#fff);color:var(--primary,#0c6e5e);box-shadow:0 1px 4px rgba(0,0,0,0.08);}
.seg-btn .seg-dot{width:6px;height:6px;border-radius:50%;}
.seg-btn.unread-dot .seg-dot{background:#3b82f6;}
.seg-btn.read-dot .seg-dot{background:#94a3b8;}

/* Notification cards */
.notif-item{position:relative;display:flex;gap:16px;padding:18px 22px;border-bottom:1px solid var(--border-color,#f1f5f9);transition:background 0.25s,transform 0.3s,opacity 0.3s;}
.notif-item:last-child{border-bottom:none;}
.notif-item:hover{background:var(--bg-secondary,#fafafa);}
.notif-item.unread{background:rgba(12,110,94,0.03);}
.notif-item.unread:hover{background:rgba(12,110,94,0.05);}
.notif-item.exiting{opacity:0;transform:translateX(60px);}
.notif-item .unread-dot{position:absolute;left:10px;top:26px;width:8px;height:8px;border-radius:50%;background:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,0.15);}
.notif-icon{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.05rem;transition:transform 0.25s;}
.notif-item:hover .notif-icon{transform:scale(1.08) rotate(-4deg);}
.notif-icon.booking{background:#dbeafe;color:#2563eb;}
.notif-icon.cancellation{background:#fee2e2;color:#dc2626;}
.notif-icon.payment_success{background:#d1fae5;color:#059669;}
.notif-icon.payment_failed{background:#fee2e2;color:#dc2626;}
.notif-icon.feedback{background:#fef3c7;color:#d97706;}
.notif-icon.event_published{background:#dbeafe;color:#2563eb;}
.notif-icon.event_cancelled{background:#fee2e2;color:#dc2626;}
.notif-icon.announcement{background:#e0e7ff;color:#4f46e5;}
.notif-icon.system{background:#e2e8f0;color:#475569;}
.notif-icon.verification{background:#d1fae5;color:#059669;}
.notif-icon.assignment{background:#dbeafe;color:#2563eb;}
.notif-icon.registration{background:#ede9fe;color:#7c3aed;}
.notif-icon.general{background:#e2e8f0;color:#475569;}
[data-theme="dark"] .notif-icon.booking{background:#1e3a5f;}
[data-theme="dark"] .notif-icon.cancellation{background:#5f1e1e;}
[data-theme="dark"] .notif-icon.payment_success{background:#1e5f3a;}
[data-theme="dark"] .notif-icon.payment_failed{background:#5f1e1e;}
[data-theme="dark"] .notif-icon.feedback{background:#5f4b1e;}
[data-theme="dark"] .notif-icon.event_published{background:#1e3a5f;}
[data-theme="dark"] .notif-icon.event_cancelled{background:#5f1e1e;}
[data-theme="dark"] .notif-icon.announcement{background:#2d2b6b;}
[data-theme="dark"] .notif-icon.system{background:#334155;}
[data-theme="dark"] .notif-icon.verification{background:#1e5f3a;}
[data-theme="dark"] .notif-icon.assignment{background:#1e3a5f;}
[data-theme="dark"] .notif-icon.registration{background:#3b1e5f;}
[data-theme="dark"] .notif-icon.general{background:#334155;}
.notif-body{flex:1;min-width:0;}
.notif-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.notif-title{font-weight:700;font-size:0.9rem;margin-bottom:3px;color:var(--text-primary,#1e293b);}
.notif-msg{font-size:0.83rem;color:var(--text-secondary,#64748b);line-height:1.5;}
.notif-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;flex-wrap:wrap;}
.notif-time{font-size:0.74rem;color:var(--text-muted,#94a3b8);display:inline-flex;align-items:center;gap:6px;}
.notif-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.notif-action-btn{padding:6px 12px;border-radius:8px;font-size:0.72rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);text-decoration:none;transition:all 0.2s;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}
.notif-action-btn:hover{background:var(--primary,#0c6e5e);color:#fff;border-color:var(--primary,#0c6e5e);transform:translateY(-1px);}
.notif-action-btn.danger:hover{background:#ef4444;border-color:#ef4444;}
.notif-action-btn.ghost{background:transparent;border-color:transparent;color:var(--text-muted,#94a3b8);}
.notif-action-btn.ghost:hover{background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#1e293b);}

/* Empty state */
.notif-empty{position:relative;text-align:center;padding:64px 24px;overflow:hidden;}
.notif-empty .ambient-glow{position:absolute;top:-80px;left:50%;transform:translateX(-50%);width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(12,110,94,0.08) 0%,rgba(52,211,153,0.05) 40%,transparent 70%);pointer-events:none;}
.notif-empty .empty-art{width:130px;height:130px;margin:0 auto 22px;position:relative;}
.notif-empty .empty-art .ring{position:absolute;inset:0;border-radius:50%;border:2px dashed var(--border-color,#cbd5e1);animation:spinSlow 26s linear infinite;}
.notif-empty .empty-art .ring2{position:absolute;inset:12px;border-radius:50%;border:1.5px dashed rgba(12,110,94,0.25);animation:spinSlow 34s linear infinite reverse;}
.notif-empty .empty-art .core{position:absolute;inset:24px;border-radius:50%;background:linear-gradient(135deg,rgba(12,110,94,0.08),rgba(52,211,153,0.06));display:flex;align-items:center;justify-content:center;}
.notif-empty .empty-art .core i{font-size:2.4rem;color:var(--primary,#0c6e5e);opacity:0.55;}
.notif-empty .empty-art .float-icon{position:absolute;width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;box-shadow:0 4px 12px rgba(0,0,0,0.08);}
@keyframes spinSlow{to{transform:rotate(360deg);}}
.notif-empty h5{font-weight:800;color:var(--text-primary,#1e293b);margin-bottom:6px;font-size:1.15rem;}
.notif-empty p{color:var(--text-muted,#94a3b8);font-size:0.88rem;margin-bottom:24px;max-width:380px;margin-left:auto;margin-right:auto;}
.notif-empty .empty-cta{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
.notif-empty .empty-cta .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;font-weight:600;font-size:0.85rem;text-decoration:none;transition:all 0.25s;}
.notif-empty .empty-cta .btn-primary{background:linear-gradient(135deg,#0c6e5e,#10b981);color:#fff;box-shadow:0 4px 14px rgba(12,110,94,0.3);}
.notif-empty .empty-cta .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(12,110,94,0.4);color:#fff;}
.notif-empty .empty-cta .btn-outline{border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);}
.notif-empty .empty-cta .btn-outline:hover{background:var(--bg-secondary,#f8fafc);}

/* Cards container */
.notif-list-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,0.02);}
.notif-list-header{padding:16px 22px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;}
.notif-list-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);font-size:0.95rem;}

/* Confirm modal */
.custom-confirm-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:all .25s ease}
.custom-confirm-overlay.show{opacity:1;visibility:visible}
.custom-confirm-box{background:var(--card-bg,#fff);border-radius:18px;padding:28px 24px 20px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);transform:scale(0.9) translateY(10px);transition:transform .25s ease;text-align:center}
.custom-confirm-overlay.show .custom-confirm-box{transform:scale(1) translateY(0)}
.custom-confirm-icon{width:56px;height:56px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.custom-confirm-title{font-size:1.05rem;font-weight:700;color:var(--text-primary,#1e293b);margin-bottom:6px}
.custom-confirm-msg{font-size:.85rem;color:var(--text-muted,#64748b);margin-bottom:22px;line-height:1.5}
.custom-confirm-actions{display:flex;gap:10px;justify-content:center}
.custom-confirm-actions button{flex:1;padding:11px 16px;border-radius:10px;font-weight:600;font-size:.88rem;border:none;cursor:pointer;transition:all .2s}
.cc-btn-cancel{background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#475569);border:1.5px solid var(--border-color,#e2e8f0) !important}
.cc-btn-cancel:hover{background:var(--border-color,#e2e8f0)}
.cc-btn-danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff}
.cc-btn-danger:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(239,68,68,0.3)}
.cc-btn-primary{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff}
.cc-btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(12,110,94,0.3)}
</style>

<!-- Hero -->
<div class="notif-hero">
    <div class="position-relative" style="z-index:1;">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-bell me-2"></i>Notifications</h3>
                <p class="mb-0 opacity-75" style="font-size:0.9rem;">Stay updated on your bookings and activities</p>
            </div>
            <div class="d-flex gap-2">
                <form method="POST" class="d-inline" id="markAllReadForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mark_all_read" value="1">
                    <button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,0.16);border:1px solid rgba(255,255,255,0.25);color:#fff;border-radius:9px;font-weight:600;backdrop-filter:blur(4px);" <?= $unread_count === 0 ? 'disabled' : '' ?>>
                        <i class="fas fa-check-double me-1"></i>Mark All Read
                    </button>
                </form>
                <form method="POST" class="d-inline" id="clearAllForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="clear_all" value="1">
                    <button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,0.25);border:1px solid rgba(239,68,68,0.35);color:#fff;border-radius:9px;font-weight:600;" <?= $total === 0 ? 'disabled' : '' ?>>
                        <i class="fas fa-trash me-1"></i>Clear All
                    </button>
                </form>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap mt-3">
            <span class="hero-badge"><i class="fas fa-envelope"></i><?= $total ?> Total</span>
            <?php if ($unread_count > 0): ?>
            <span class="hero-badge red"><span class="dot" style="background:#fca5a5;"></span><?= $unread_count ?> Unread</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Unified Toolbar -->
<div class="notif-toolbar">
    <div class="toolbar-section">
        <span class="toolbar-label">Category</span>
        <a href="?type=&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === '' ? 'active' : '' ?>">All</a>
        <a href="?type=booking&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'booking' ? 'active' : '' ?>"><i class="fas fa-ticket"></i> Bookings</a>
        <a href="?type=payment_success&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'payment_success' ? 'active' : '' ?>"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="?type=event_published&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'event_published' ? 'active' : '' ?>"><i class="fas fa-calendar-check"></i> Events</a>
        <a href="?type=announcement&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'announcement' ? 'active' : '' ?>"><i class="fas fa-bullhorn"></i> Announcements</a>
        <a href="?type=system&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'system' ? 'active' : '' ?>"><i class="fas fa-cog"></i> System</a>
    </div>
    <div class="toolbar-section">
        <span class="toolbar-label">Status</span>
        <div class="seg-group">
            <a href="?type=<?= $filter_type ?>&read=" class="seg-btn <?= $filter_read === '' ? 'active' : '' ?>"><span class="seg-dot" style="background:#10b981;"></span>All</a>
            <a href="?type=<?= $filter_type ?>&read=unread" class="seg-btn unread-dot <?= $filter_read === 'unread' ? 'active' : '' ?>"><span class="seg-dot"></span>Unread</a>
            <a href="?type=<?= $filter_type ?>&read=read" class="seg-btn read-dot <?= $filter_read === 'read' ? 'active' : '' ?>"><span class="seg-dot"></span>Read</a>
        </div>
    </div>
</div>

<?php
$type_config = [
    'booking'          => ['icon' => 'fa-ticket', 'label' => 'View Booking Details'],
    'cancellation'     => ['icon' => 'fa-times-circle', 'label' => 'View Booking Details'],
    'payment_success'  => ['icon' => 'fa-credit-card', 'label' => 'Download Receipt'],
    'payment_failed'   => ['icon' => 'fa-exclamation-triangle', 'label' => 'Retry Payment'],
    'feedback'         => ['icon' => 'fa-star', 'label' => 'View Feedback'],
    'event_published'  => ['icon' => 'fa-calendar-check', 'label' => 'View Event'],
    'event_cancelled'  => ['icon' => 'fa-calendar-xmark', 'label' => 'View Event'],
    'announcement'     => ['icon' => 'fa-bullhorn', 'label' => 'Read Announcement'],
    'system'           => ['icon' => 'fa-cog', 'label' => 'View Details'],
    'verification'     => ['icon' => 'fa-id-card', 'label' => 'View Verification'],
    'assignment'       => ['icon' => 'fa-user-check', 'label' => 'View Details'],
    'registration'     => ['icon' => 'fa-user-plus', 'label' => 'View Account'],
    'general'          => ['icon' => 'fa-bell', 'label' => 'View Details'],
];
?>

<div class="notif-list-card">
    <div class="notif-list-header">
        <div style="width:32px;height:32px;border-radius:9px;background:<?= $filter_type === '' ? 'linear-gradient(135deg,#0c6e5e,#1a8a7a)' : 'var(--bg-secondary,#f1f5f9)' ?>;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-list-ul" style="color:<?= $filter_type === '' ? '#fff' : 'var(--text-muted,#64748b)' ?>;font-size:0.8rem;"></i>
        </div>
        <h6>Notification Feed</h6>
        <span class="ms-auto notif-time"><i class="fas fa-shield-halved"></i><?= $total ?> shown</span>
    </div>

    <?php if (empty($notifications)): ?>
    <div class="notif-empty">
        <div class="ambient-glow"></div>
        <div class="empty-art">
            <div class="ring"></div>
            <div class="ring2"></div>
            <div class="core"><i class="fas fa-bell-slash"></i></div>
            <div class="float-icon" style="background:#d1fae5;top:-2px;right:4px;color:#10b981;"><i class="fas fa-check"></i></div>
            <div class="float-icon" style="background:#dbeafe;bottom:6px;left:0;color:#3b82f6;"><i class="fas fa-ticket"></i></div>
        </div>
        <h5><?= $filter_type !== '' || $filter_read !== '' ? 'No matching notifications' : 'No notifications' ?></h5>
        <p><?= $filter_type !== '' || $filter_read !== '' ? 'Try adjusting your filters to see more.' : "You're all caught up! When something happens with your bookings, you'll see it here." ?></p>
        <div class="empty-cta">
            <a href="<?= BASE_URL ?>/tourist/events.php" class="btn btn-primary"><i class="fas fa-calendar-day"></i> Explore Upcoming Events</a>
            <a href="<?= BASE_URL ?>/tourist/bookings.php" class="btn btn-outline"><i class="fas fa-ticket-alt"></i> Check My Bookings</a>
        </div>
    </div>
    <?php else: ?>
        <?php foreach ($notifications as $n):
            $cfg = $type_config[$n['type']] ?? ['icon' => 'fa-bell', 'label' => 'View Details'];
            $icon = $cfg['icon'];
            $link = $n['link'] ? BASE_URL . $n['link'] : '#';
            $time_diff = (new DateTime())->diff(new DateTime($n['created_at']));
            if ($time_diff->days > 0) $time_str = $time_diff->days . 'd ago';
            elseif ($time_diff->h > 0) $time_str = $time_diff->h . 'h ago';
            elseif ($time_diff->i > 0) $time_str = $time_diff->i . 'm ago';
            else $time_str = 'Just now';
        ?>
        <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>" data-id="<?= $n['id'] ?>">
            <?php if (!$n['is_read']): ?><span class="unread-dot"></span><?php endif; ?>
            <div class="notif-icon <?= $n['type'] ?>"><i class="fas <?= $icon ?>"></i></div>
            <div class="notif-body">
                <div class="notif-title-row">
                    <div>
                        <div class="notif-title"><?= sanitize($n['title']) ?></div>
                        <div class="notif-msg"><?= sanitize($n['message']) ?></div>
                    </div>
                    <div class="notif-actions">
                        <?php if (!$n['is_read']): ?>
                            <form method="POST" class="d-inline mark-read-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="mark_read" value="1">
                                <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                <button type="submit" class="notif-action-btn" title="Mark as read"><i class="fas fa-check"></i> Read</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="d-inline delete-notif-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_notification" value="1">
                            <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="notif-action-btn danger" title="Dismiss"><i class="fas fa-xmark"></i></button>
                        </form>
                    </div>
                </div>
                <div class="notif-meta">
                    <span class="notif-time"><i class="fas fa-clock"></i><?= $time_str ?> · <?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></span>
                    <?php if ($n['link']): ?>
                        <a href="<?= $link ?>" class="notif-action-btn"><i class="fas fa-arrow-right"></i> <?= $cfg['label'] ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($total_pages > 1): ?>
        <nav class="p-3">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?type=<?= $filter_type ?>&read=<?= $filter_read ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                </li>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?type=<?= $filter_type ?>&read=<?= $filter_read ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?type=<?= $filter_type ?>&read=<?= $filter_read ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Custom Confirm Modal -->
<div class="custom-confirm-overlay" id="notifConfirmModal">
    <div class="custom-confirm-box">
        <div class="custom-confirm-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;">
            <i class="fas fa-trash-alt"></i>
        </div>
        <div class="custom-confirm-title" id="notifConfirmTitle">Delete Notification</div>
        <div class="custom-confirm-msg" id="notifConfirmMsg">Are you sure you want to delete this notification? This action cannot be undone.</div>
        <div class="custom-confirm-actions">
            <button type="button" class="cc-btn-cancel" onclick="closeNotifConfirm(false)">Cancel</button>
            <button type="button" class="cc-btn-danger" id="notifConfirmDeleteBtn"><i class="fas fa-trash me-1"></i>Delete</button>
        </div>
    </div>
</div>

<script>
let _notifConfirmForm = null;

function showNotifConfirm(form, title, msg) {
    _notifConfirmForm = form;
    document.getElementById('notifConfirmTitle').textContent = title || 'Delete Notification';
    document.getElementById('notifConfirmMsg').textContent = msg || 'Are you sure you want to delete this notification? This action cannot be undone.';
    document.getElementById('notifConfirmModal').classList.add('show');
}

function closeNotifConfirm(confirmed) {
    document.getElementById('notifConfirmModal').classList.remove('show');
    if (confirmed && _notifConfirmForm) {
        _notifConfirmForm.submit();
    }
    _notifConfirmForm = null;
}

document.getElementById('notifConfirmDeleteBtn').addEventListener('click', function() {
    closeNotifConfirm(true);
});

document.getElementById('notifConfirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeNotifConfirm(false);
});

// Delete notification: exit animation then confirm
document.querySelectorAll('.delete-notif-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var item = form.closest('.notif-item');
        var btn = form.querySelector('button');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        showNotifConfirm(form, 'Dismiss Notification', 'Remove this notification from your feed?');
    });
});

// Mark as read: optimistic update with animation
document.querySelectorAll('.mark-read-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var item = form.closest('.notif-item');
        var btn = form.querySelector('button');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        var dot = item.querySelector('.unread-dot');
        if (dot) dot.remove();
        item.classList.remove('unread');
        form.remove();
        form.submit();
    });
});

// Clear all: spinner + exit animation on all items
var clearAllForm = document.getElementById('clearAllForm');
if (clearAllForm) {
    clearAllForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = clearAllForm.querySelector('button');
        var items = document.querySelectorAll('.notif-item');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        items.forEach(function(item, i) {
            setTimeout(function() {
                item.classList.add('exiting');
                if (i === items.length - 1) {
                    setTimeout(function() { clearAllForm.submit(); }, 200);
                }
            }, i * 40);
        });
        if (items.length === 0) clearAllForm.submit();
    });
}

// Mark all read: spinner then submit
var markAllReadForm = document.getElementById('markAllReadForm');
if (markAllReadForm) {
    markAllReadForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = markAllReadForm.querySelector('button');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        setTimeout(function() { markAllReadForm.submit(); }, 300);
    });
}
</script>
<?php }); ?>
