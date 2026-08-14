<?php
require_once __DIR__ . '/layout.php';
require_login();

$user = current_user();
$role = $_SESSION['role'];

if ($role === 'admin') {
    redirect('/admin/notifications.php');
}

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        redirect(BASE_URL . "/{$role}/notifications.php");
    }
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0");
        $stmt->execute([':uid' => $user['id']]);
        flash_message('success', 'All notifications marked as read.');
        redirect(BASE_URL . "/{$role}/notifications.php");
    }
    if (isset($_POST['mark_read'])) {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")
           ->execute([':id' => $nid, ':uid' => $user['id']]);
        redirect(BASE_URL . "/{$role}/notifications.php");
    }
    if (isset($_POST['delete_notification'])) {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid")
           ->execute([':id' => $nid, ':uid' => $user['id']]);
        redirect(BASE_URL . "/{$role}/notifications.php");
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :uid");
$count_stmt->execute([':uid' => $user['id']]);
$total = (int) $count_stmt->fetch()['cnt'];
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$unread_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :uid AND is_read = 0");
$unread_stmt->execute([':uid' => $user['id']]);
$unread_count = (int) $unread_stmt->fetch()['cnt'];

$notif_stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}");
$notif_stmt->execute([':uid' => $user['id']]);
$notifications = $notif_stmt->fetchAll();

$page_title = 'Notifications';
render_page($role, 'notifications.php', $page_title, function () use ($notifications, $role, $user, $total, $total_pages, $page, $unread_count) {
    $type_icons = [
        'booking' => ['fa-ticket', 'primary'],
        'cancellation' => ['fa-times-circle', 'danger'],
        'payment_success' => ['fa-check-circle', 'success'],
        'payment_failed' => ['fa-exclamation-triangle', 'danger'],
        'feedback' => ['fa-star', 'warning'],
        'event_published' => ['fa-calendar-check', 'primary'],
        'event_cancelled' => ['fa-calendar-xmark', 'danger'],
        'announcement' => ['fa-bullhorn', 'info'],
        'system' => ['fa-cog', 'secondary'],
        'verification' => ['fa-id-card', 'success'],
        'assignment' => ['fa-user-check', 'primary'],
        'registration' => ['fa-user-plus', 'primary'],
        'general' => ['fa-bell', 'secondary'],
    ];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Notifications</h4>
        <small class="text-muted"><?= $total ?> notification<?= $total !== 1 ? 's' : '' ?> · <?= $unread_count ?> unread</small>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-outline-primary btn-sm" <?= $unread_count === 0 ? 'disabled' : '' ?>>
            <i class="fas fa-check-double me-1"></i>Mark All Read
        </button>
    </form>
</div>

<?php if (empty($notifications)): ?>
    <div class="text-center py-5">
        <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">No notifications yet</h5>
        <p class="text-muted">You'll see updates about your bookings, tours, and account here.</p>
    </div>
<?php else: ?>
    <?php foreach ($notifications as $n):
        $icon_info = $type_icons[$n['type']] ?? ['fa-bell', 'secondary'];
        $time_diff = (new DateTime())->diff(new DateTime($n['created_at']));
        if ($time_diff->days > 0) $time_str = $time_diff->days . 'd ago';
        elseif ($time_diff->h > 0) $time_str = $time_diff->h . 'h ago';
        elseif ($time_diff->i > 0) $time_str = $time_diff->i . 'm ago';
        else $time_str = 'Just now';
    ?>
        <div class="card border-0 shadow-sm mb-2" style="background:var(--card-bg); border-left: 3px solid <?= $n['is_read'] ? 'var(--border-color)' : 'var(--primary)' ?> !important;">
            <div class="card-body py-3 px-4 d-flex gap-3 align-items-start">
                <div class="flex-shrink-0 mt-1">
                    <i class="fas <?= $icon_info[0] ?> text-<?= $icon_info[1] ?>"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <h6 class="mb-1 <?= !$n['is_read'] ? 'fw-bold' : '' ?>"><?= sanitize($n['title']) ?></h6>
                        <?php if (!$n['is_read']): ?>
                            <span class="badge bg-primary rounded-pill" style="font-size:0.65rem;">New</span>
                        <?php endif; ?>
                    </div>
                    <p class="mb-1 small" style="color:var(--text-secondary);"><?= sanitize($n['message']) ?></p>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted"><?= $time_str ?> · <?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></small>
                        <div class="d-flex gap-1">
                            <?php if (!$n['is_read']): ?>
                                <form method="POST" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="mark_read" value="1">
                                    <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" style="font-size:0.7rem;padding:2px 8px;">Read</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_notification" value="1">
                                <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:0.7rem;padding:2px 8px;"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php if (!empty($n['link'])): ?>
                                <a href="<?= BASE_URL . $n['link'] ?>" class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem;padding:2px 8px;"><i class="fas fa-arrow-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($total_pages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a>
            </li>
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= $page + 1 ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<?php }); ?>
