<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('guide');

$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        redirect('/guide/notifications.php');
    }
    if (isset($_POST['mark_read'])) {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")
           ->execute([':id' => $nid, ':uid' => $user_id]);
        redirect('/guide/notifications.php');
    }
    if (isset($_POST['mark_all_read'])) {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0")
           ->execute([':uid' => $user_id]);
        flash_message('success', 'All notifications marked as read.');
        redirect('/guide/notifications.php');
    }
    if (isset($_POST['delete_notification'])) {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid")
           ->execute([':id' => $nid, ':uid' => $user_id]);
        redirect('/guide/notifications.php');
    }
    if (isset($_POST['clear_all'])) {
        $db->prepare("DELETE FROM notifications WHERE user_id = :uid")
           ->execute([':uid' => $user_id]);
        flash_message('success', 'All notifications cleared.');
        redirect('/guide/notifications.php');
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

render_page('guide', 'notifications.php', 'Notifications', function () use ($notifications, $total, $total_pages, $page, $filter_type, $filter_read, $unread_count, $user_id, $db) {
?>

<style>
.notif-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.notif-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.notif-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.notif-card {
    border: 1px solid var(--border-color,#e2e8f0);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 10px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    transition: all 0.2s;
    background: var(--card-bg,#fff);
}
.notif-card.unread {
    border-left: 3px solid var(--primary,#0c6e5e);
    background: rgba(12,110,94,0.03);
}
.notif-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    transform: translateY(-1px);
}
.notif-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1rem;
}
.notif-card-icon.booking { background: #dbeafe; color: #2563eb; }
.notif-card-icon.cancellation { background: #fee2e2; color: #dc2626; }
.notif-card-icon.payment_success { background: #d1fae5; color: #059669; }
.notif-card-icon.payment_failed { background: #fee2e2; color: #dc2626; }
.notif-card-icon.feedback { background: #fef3c7; color: #d97706; }
.notif-card-icon.event_published { background: #dbeafe; color: #2563eb; }
.notif-card-icon.event_cancelled { background: #fee2e2; color: #dc2626; }
.notif-card-icon.announcement { background: #e0e7ff; color: #4f46e5; }
.notif-card-icon.system { background: #e2e8f0; color: #475569; }
.notif-card-icon.verification { background: #d1fae5; color: #059669; }
.notif-card-icon.assignment { background: #dbeafe; color: #2563eb; }
.notif-card-icon.registration { background: #ede9fe; color: #7c3aed; }
.notif-card-icon.general { background: #e2e8f0; color: #475569; }
[data-theme="dark"] .notif-card-icon.booking { background: #1e3a5f; }
[data-theme="dark"] .notif-card-icon.cancellation { background: #5f1e1e; }
[data-theme="dark"] .notif-card-icon.payment_success { background: #1e5f3a; }
[data-theme="dark"] .notif-card-icon.payment_failed { background: #5f1e1e; }
[data-theme="dark"] .notif-card-icon.feedback { background: #5f4b1e; }
[data-theme="dark"] .notif-card-icon.event_published { background: #1e3a5f; }
[data-theme="dark"] .notif-card-icon.event_cancelled { background: #5f1e1e; }
[data-theme="dark"] .notif-card-icon.announcement { background: #2d2b6b; }
[data-theme="dark"] .notif-card-icon.system { background: #334155; }
[data-theme="dark"] .notif-card-icon.verification { background: #1e5f3a; }
[data-theme="dark"] .notif-card-icon.assignment { background: #1e3a5f; }
[data-theme="dark"] .notif-card-icon.registration { background: #3b1e5f; }
[data-theme="dark"] .notif-card-icon.general { background: #334155; }
.notif-card-body { flex: 1; min-width: 0; }
.notif-card-title { font-weight: 700; font-size: 0.9rem; margin-bottom: 2px; color: var(--text-primary,#1e293b); }
.notif-card-msg { font-size: 0.83rem; color: var(--text-secondary,#64748b); line-height: 1.4; }
.notif-card-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; }
.notif-card-time { font-size: 0.75rem; color: var(--text-muted,#94a3b8); }
.filter-pill {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 600;
    border: 1px solid var(--border-color,#e2e8f0);
    background: var(--card-bg,#fff);
    color: var(--text-secondary,#64748b);
    text-decoration: none;
    transition: all 0.2s;
}
.filter-pill:hover { border-color: var(--primary,#0c6e5e); color: var(--primary,#0c6e5e); }
.filter-pill.active { background: var(--primary,#0c6e5e); color: #fff; border-color: var(--primary,#0c6e5e); }
.notif-action-btn{padding:4px 10px;border-radius:6px;font-size:0.72rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);transition:all 0.2s;}
.notif-action-btn:hover{background:var(--primary,#0c6e5e);color:#fff;border-color:var(--primary,#0c6e5e);}
.notif-action-btn.danger:hover{background:#ef4444;border-color:#ef4444;}
</style>

<div class="notif-hero">
    <div class="position-relative" style="z-index:1;">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-bell me-2"></i>Notifications</h3>
                <p class="mb-0 opacity-75" style="font-size:0.9rem;">Stay updated on your bookings and activities</p>
            </div>
            <div class="d-flex gap-2">
                <form method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mark_all_read" value="1">
                    <button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;font-weight:600;" <?= $unread_count === 0 ? 'disabled style="opacity:0.5;background:rgba(255,255,255,0.1);color:#fff;border-radius:8px;font-weight:600;"' : '' ?>>
                        <i class="fas fa-check-double me-1"></i>Mark All Read
                    </button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('Clear all notifications?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="clear_all" value="1">
                    <button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;font-weight:600;" <?= $total === 0 ? 'disabled style="opacity:0.5;background:rgba(255,255,255,0.1);color:#fff;border-radius:8px;font-weight:600;"' : '' ?>>
                        <i class="fas fa-trash me-1"></i>Clear All
                    </button>
                </form>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap mt-3">
            <div class="d-flex align-items-center gap-2" style="background:rgba(255,255,255,0.15);border-radius:10px;padding:8px 16px;">
                <i class="fas fa-envelope"></i>
                <span class="small"><?= $total ?> Total</span>
            </div>
            <?php if ($unread_count > 0): ?>
            <div class="d-flex align-items-center gap-2" style="background:rgba(239,68,68,0.3);border-radius:10px;padding:8px 16px;">
                <i class="fas fa-circle" style="font-size:0.5rem;"></i>
                <span class="small fw-bold"><?= $unread_count ?> Unread</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="?type=&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === '' ? 'active' : '' ?>">All</a>
    <a href="?type=booking&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'booking' ? 'active' : '' ?>"><i class="fas fa-ticket me-1"></i>Bookings</a>
    <a href="?type=payment_success&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'payment_success' ? 'active' : '' ?>"><i class="fas fa-check-circle me-1"></i>Payments</a>
    <a href="?type=event_published&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'event_published' ? 'active' : '' ?>"><i class="fas fa-calendar me-1"></i>Events</a>
    <a href="?type=announcement&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'announcement' ? 'active' : '' ?>"><i class="fas fa-bullhorn me-1"></i>Announcements</a>
    <a href="?type=system&read=<?= $filter_read ?>" class="filter-pill <?= $filter_type === 'system' ? 'active' : '' ?>"><i class="fas fa-cog me-1"></i>System</a>
</div>

<div class="d-flex gap-2 mb-3">
    <a href="?type=<?= $filter_type ?>&read=" class="filter-pill <?= $filter_read === '' ? 'active' : '' ?>">All</a>
    <a href="?type=<?= $filter_type ?>&read=unread" class="filter-pill <?= $filter_read === 'unread' ? 'active' : '' ?>">Unread</a>
    <a href="?type=<?= $filter_type ?>&read=read" class="filter-pill <?= $filter_read === 'read' ? 'active' : '' ?>">Read</a>
</div>

<?php if (empty($notifications)): ?>
    <div style="background:var(--card-bg,#fff);border-radius:14px;border:1px solid var(--border-color,#e2e8f0);padding:48px 24px;text-align:center;">
        <div style="width:80px;height:80px;border-radius:50%;background:var(--bg-secondary,#f1f5f9);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-bell-slash text-muted" style="font-size:2rem;opacity:0.4;"></i>
        </div>
        <h5 class="fw-bold mb-1" style="color:var(--text-primary,#1e293b);">No notifications</h5>
        <p class="text-muted small mb-0">You're all caught up!</p>
    </div>
<?php else: ?>
    <?php
    $type_icons = [
        'booking' => 'fa-ticket',
        'cancellation' => 'fa-times-circle',
        'payment_success' => 'fa-check-circle',
        'payment_failed' => 'fa-exclamation-triangle',
        'feedback' => 'fa-star',
        'event_published' => 'fa-calendar-check',
        'event_cancelled' => 'fa-calendar-xmark',
        'announcement' => 'fa-bullhorn',
        'system' => 'fa-cog',
        'verification' => 'fa-id-card',
        'assignment' => 'fa-user-check',
        'registration' => 'fa-user-plus',
        'general' => 'fa-bell',
    ];
    ?>
    <?php foreach ($notifications as $n):
        $icon = $type_icons[$n['type']] ?? 'fa-bell';
        $link = $n['link'] ? BASE_URL . $n['link'] : '#';
        $time_diff = (new DateTime())->diff(new DateTime($n['created_at']));
        if ($time_diff->days > 0) $time_str = $time_diff->days . 'd ago';
        elseif ($time_diff->h > 0) $time_str = $time_diff->h . 'h ago';
        elseif ($time_diff->i > 0) $time_str = $time_diff->i . 'm ago';
        else $time_str = 'Just now';
    ?>
        <div class="notif-card <?= $n['is_read'] ? '' : 'unread' ?>">
            <div class="notif-card-icon <?= $n['type'] ?>">
                <i class="fas <?= $icon ?>"></i>
            </div>
            <div class="notif-card-body">
                <div class="notif-card-title"><?= sanitize($n['title']) ?></div>
                <div class="notif-card-msg"><?= sanitize($n['message']) ?></div>
                <div class="notif-card-meta">
                    <span class="notif-card-time"><i class="fas fa-clock me-1"></i><?= $time_str ?> · <?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></span>
                    <div class="d-flex gap-1">
                        <?php if (!$n['is_read']): ?>
                            <form method="POST" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="mark_read" value="1">
                                <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                <button type="submit" class="notif-action-btn" title="Mark as read">
                                    <i class="fas fa-check me-1"></i>Read
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this notification?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_notification" value="1">
                            <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="notif-action-btn danger" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php if ($n['link']): ?>
                            <a href="<?= $link ?>" class="notif-action-btn" title="View">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
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

<?php }); ?>
