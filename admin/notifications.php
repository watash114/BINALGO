<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');

$db = Database::getInstance()->getConnection();

// Auto-promote due scheduled broadcasts to delivered when the page is opened.
try {
    $db->exec("UPDATE notifications SET status = 'delivered' WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= datetime('now')");
} catch (Throwable $e) { /* best effort */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        redirect('/admin/notifications.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'send_notification') {
        $recipient  = $_POST['recipient'] ?? 'all';
        $title      = trim($_POST['title'] ?? '');
        $message    = trim($_POST['message'] ?? '');
        $type       = $_POST['notif_type'] ?? 'announcement';
        $priority   = in_array($_POST['priority'] ?? 'normal', ['low', 'normal', 'urgent'], true) ? $_POST['priority'] : 'normal';
        $link       = !empty($_POST['link']) ? trim($_POST['link']) : null;

        $scheduled_at = null;
        if (!empty($_POST['schedule_enabled']) && !empty($_POST['scheduled_at'])) {
            $scheduled_at = date('Y-m-d H:i:s', strtotime($_POST['scheduled_at']));
        }

        if (empty($title) || empty($message)) {
            flash_message('error', 'Title and message are required.');
            redirect('/admin/notifications.php');
        }

        $user_ids = [];
        $audience = 'All Active Users';
        $segLabel = 'all';

        switch ($recipient) {
            case 'tourists':
                $stmt = $db->query("SELECT id FROM users WHERE role = 'tourist' AND status = 'active'");
                $user_ids = array_column($stmt->fetchAll(), 'id');
                $audience = 'Tourists Only';
                $segLabel = 'tourists';
                break;
            case 'staff':
                $stmt = $db->query("SELECT id FROM users WHERE role = 'staff' AND status = 'active'");
                $user_ids = array_column($stmt->fetchAll(), 'id');
                $audience = 'Local Staff';
                $segLabel = 'staff';
                break;
            case 'role':
                $role = $_POST['target_role'] ?? 'tourist';
                $stmt = $db->prepare("SELECT id FROM users WHERE role = :r AND status = 'active'");
                $stmt->execute([':r' => $role]);
                $user_ids = array_column($stmt->fetchAll(), 'id');
                $audience = ucfirst($role) . 's Only';
                $segLabel = 'role:' . $role;
                break;
            case 'active_bookings':
                $stmt = $db->query(
                    "SELECT DISTINCT tourist_id FROM bookings WHERE status IN ('pending','confirmed')"
                );
                $user_ids = array_map('intval', array_column($stmt->fetchAll(), 'tourist_id'));
                $audience = 'Users with Active Bookings';
                $segLabel = 'active_bookings';
                break;
            case 'users':
                $user_ids = array_map('intval', $_POST['user_ids'] ?? []);
                $audience = 'Specific Users';
                $segLabel = 'users';
                break;
            case 'all':
            default:
                $stmt = $db->query("SELECT id FROM users WHERE status = 'active'");
                $user_ids = array_column($stmt->fetchAll(), 'id');
                $audience = 'All Active Users';
                $segLabel = 'all';
                break;
        }

        $status = ($scheduled_at && strtotime($scheduled_at) > time()) ? 'scheduled' : 'delivered';

        $notif = new Notification();
        $result = $notif->sendBroadcast($user_ids, $title, $message, $type, $link, $priority, $scheduled_at, $status, $audience);
        $count = $result['count'];

        ActivityLog::log($_SESSION['user_id'], 'notification_sent', "Sent \"{$title}\" to {$segLabel} ({$count} recipients)");
        if ($status === 'scheduled') {
            flash_message('success', "Notification scheduled for " . date('M d, Y g:i A', strtotime($scheduled_at)) . " — {$count} user(s) targeted.");
        } else {
            flash_message('success', "Notification sent to {$count} user(s).");
        }
        redirect('/admin/notifications.php');
    }

    if ($action === 'resend_notification') {
        $batch_id = $_POST['batch_id'] ?? '';
        if ($batch_id === '') {
            flash_message('error', 'Invalid notification batch.');
            redirect('/admin/notifications.php');
        }

        $stmt = $db->prepare("SELECT * FROM notifications WHERE batch_id = :b ORDER BY id ASC");
        $stmt->execute([':b' => $batch_id]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            flash_message('error', 'Original notification batch not found.');
            redirect('/admin/notifications.php');
        }

        $first = $rows[0];
        $user_ids = array_column($rows, 'user_id');

        $notif = new Notification();
        $result = $notif->sendBroadcast($user_ids, $first['title'], $first['message'], $first['type'], $first['link'], $first['priority'], null, 'delivered', $first['audience']);

        ActivityLog::log($_SESSION['user_id'], 'notification_resent', "Re-sent \"{$first['title']}\" to {$result['count']} user(s)");
        flash_message('success', "Notification re-sent to {$result['count']} user(s).");
        redirect('/admin/notifications.php');
    }

    if ($action === 'edit_scheduled') {
        $batch_id = $_POST['batch_id'] ?? '';
        $scheduled_at = !empty($_POST['scheduled_at']) ? date('Y-m-d H:i:s', strtotime($_POST['scheduled_at'])) : null;

        if ($batch_id !== '' && $scheduled_at) {
            $stmt = $db->prepare("UPDATE notifications SET scheduled_at = :s, status = 'scheduled' WHERE batch_id = :b");
            $stmt->execute([':s' => $scheduled_at, ':b' => $batch_id]);
            flash_message('success', 'Schedule updated to ' . date('M d, Y g:i A', strtotime($scheduled_at)) . '.');
        } else {
            flash_message('error', 'A valid date/time is required.');
        }
        redirect('/admin/notifications.php');
    }

    if ($action === 'delete_notification') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $batch_id = $_POST['batch_id'] ?? '';

        if ($batch_id !== '') {
            $db->prepare("DELETE FROM notifications WHERE batch_id = :b")->execute([':b' => $batch_id]);
            flash_message('success', 'Broadcast recalled and deleted.');
        } elseif ($nid > 0) {
            $db->prepare("DELETE FROM notifications WHERE id = :id")->execute([':id' => $nid]);
            flash_message('success', 'Notification deleted.');
        }
        redirect('/admin/notifications.php');
    }

    if ($action === 'clear_old') {
        $days = max(7, (int)($_POST['days'] ?? 30));
        $stmt = $db->prepare("DELETE FROM notifications WHERE created_at < datetime('now', '-' || :days || ' days')");
        $stmt->execute([':days' => $days]);
        $deleted = $stmt->rowCount();
        flash_message('success', "Deleted {$deleted} notifications older than {$days} days.");
        redirect('/admin/notifications.php');
    }
}

$filter_type = $_GET['type'] ?? '';
$filter_search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;

$where = [];
$params = [];

if ($filter_type !== '') {
    $where[] = "n.type = :type";
    $params[':type'] = $filter_type;
}
if ($filter_search !== '') {
    $where[] = "(n.title LIKE :search OR n.message LIKE :search2)";
    $params[':search'] = '%' . $filter_search . '%';
    $params[':search2'] = '%' . $filter_search . '%';
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Group history by broadcast batch so multi-recipient sends show as one item.
$group_expr = "COALESCE(n.batch_id, CONCAT('single_', n.id))";

$count_stmt = $db->prepare("SELECT COUNT(DISTINCT {$group_expr}) as cnt FROM notifications n {$where_clause}");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetch()['cnt'];
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$notif_stmt = $db->prepare(
    "SELECT {$group_expr} AS gid,
            MAX(n.id) AS last_id,
            MAX(n.title) AS title,
            MAX(n.message) AS message,
            MAX(n.type) AS type,
            MAX(n.link) AS link,
            MAX(n.priority) AS priority,
            MAX(n.status) AS status,
            MAX(n.scheduled_at) AS scheduled_at,
            MAX(n.audience) AS audience,
            MAX(n.recipient_count) AS recipient_count,
            MAX(n.created_at) AS created_at,
            COUNT(*) AS recipients
     FROM notifications n
     {$where_clause}
     GROUP BY gid
     ORDER BY MAX(n.created_at) DESC
     LIMIT {$per_page} OFFSET {$offset}"
);
$notif_stmt->execute($params);
$notifications = $notif_stmt->fetchAll();

$total_all = $db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
$total_unread = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
$total_today = $db->query("SELECT COUNT(*) FROM notifications WHERE DATE(created_at) = date('now')")->fetchColumn();
$total_scheduled = $db->query("SELECT COUNT(DISTINCT batch_id) FROM notifications WHERE status = 'scheduled'")->fetchColumn();

$users_stmt = $db->query("SELECT id, name, email, role FROM users WHERE status = 'active' ORDER BY name");
$all_users = $users_stmt->fetchAll();

$type_configs = [
    'booking'           => ['icon' => 'fa-ticket-alt',       'color' => '#3b82f6', 'bg' => '#dbeafe', 'label' => 'Booking'],
    'cancellation'      => ['icon' => 'fa-times-circle',     'color' => '#ef4444', 'bg' => '#fee2e2', 'label' => 'Cancellation'],
    'payment_success'   => ['icon' => 'fa-check-circle',     'color' => '#10b981', 'bg' => '#d1fae5', 'label' => 'Payment'],
    'payment_failed'    => ['icon' => 'fa-exclamation-triangle','color' => '#ef4444','bg' => '#fee2e2','label' => 'Payment'],
    'feedback'          => ['icon' => 'fa-star',              'color' => '#f59e0b', 'bg' => '#fef3c7', 'label' => 'Feedback'],
    'event_published'   => ['icon' => 'fa-calendar-check',   'color' => '#8b5cf6', 'bg' => '#ede9fe', 'label' => 'Event Promo'],
    'event_cancelled'   => ['icon' => 'fa-calendar-xmark',   'color' => '#ef4444', 'bg' => '#fee2e2', 'label' => 'Event'],
    'announcement'      => ['icon' => 'fa-bullhorn',          'color' => '#06b6d4', 'bg' => '#cffafe', 'label' => 'Announcement'],
    'system'            => ['icon' => 'fa-cog',               'color' => '#6b7280', 'bg' => '#f3f4f6', 'label' => 'System Update'],
    'verification'      => ['icon' => 'fa-id-card',           'color' => '#10b981', 'bg' => '#d1fae5', 'label' => 'Verify'],
    'registration'      => ['icon' => 'fa-user-plus',         'color' => '#8b5cf6', 'bg' => '#ede9fe', 'label' => 'Register'],
    'assignment'        => ['icon' => 'fa-user-check',        'color' => '#3b82f6', 'bg' => '#dbeafe', 'label' => 'Assign'],
    'general'           => ['icon' => 'fa-bell',              'color' => '#6b7280', 'bg' => '#f3f4f6', 'label' => 'General'],
];

$priority_configs = [
    'low'    => ['color' => '#64748b', 'bg' => '#f1f5f9', 'label' => 'Low'],
    'normal' => ['color' => '#3b82f6', 'bg' => '#dbeafe', 'label' => 'Normal'],
    'urgent' => ['color' => '#ef4444', 'bg' => '#fee2e2', 'label' => 'Urgent'],
];

render_page('admin', 'notifications.php', 'Notification Management', function () use ($notifications, $total, $total_pages, $page, $filter_type, $filter_search, $total_all, $total_unread, $total_today, $total_scheduled, $all_users, $type_configs, $priority_configs) {
?>

<style>
    .notif-hero { background: transparent; color: inherit; border-radius: 0; padding: 0; margin-bottom: 0; position: relative; overflow: hidden; }
    .notif-hero::before { display: none; }
    .notif-hero h4 { font-weight: 800; margin-bottom: 2px; font-size: 1.15rem; }
    .notif-hero p { opacity: 0.7; font-size: 0.82rem; margin-bottom: 0; }

    .page-header-card {
        background: var(--card-bg, #fff); border-radius: 14px;
        border: 1px solid var(--border-color, #f1f5f9);
        box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-bottom: 20px;
    }

    /* ── Stat cards ─────────────────────────────────────── */
    .stat-card {
        border: none; border-radius: 16px; overflow: hidden; transition: all 0.3s;
        background: var(--card-bg, #fff); border: 1px solid var(--border-color, #f1f5f9);
        position: relative;
    }
    .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
    .stat-card .stat-bar { height: 4px; width: 100%; }
    .stat-card .stat-body { padding: 18px 16px; text-align: center; }
    .stat-card .stat-icon {
        width: 42px; height: 42px; border-radius: 12px; display: inline-flex;
        align-items: center; justify-content: center; margin-bottom: 10px;
    }
    .stat-card .stat-value { font-size: 1.6rem; font-weight: 800; line-height: 1; margin-bottom: 4px; }
    .stat-card .stat-label { font-size: 0.78rem; font-weight: 600; color: var(--text-muted, #64748b); text-transform: uppercase; letter-spacing: 0.5px; }

    /* ── Compose card ───────────────────────────────────── */
    .compose-card, .history-card {
        background: var(--card-bg, #fff); border: 1px solid var(--border-color, #f1f5f9);
        border-radius: 16px; overflow: hidden;
    }
    .compose-header, .history-header {
        padding: 18px 24px; border-bottom: 1px solid var(--border-color, #f1f5f9);
        display: flex; align-items: center; gap: 10px;
    }
    .compose-header h6, .history-header h6 { font-weight: 700; margin: 0; font-size: 0.95rem; color: var(--text-primary, #1e293b); }
    .compose-body { padding: 24px; }

    .form-group label {
        font-size: 0.82rem; font-weight: 700; color: var(--text-primary, #1e293b);
        margin-bottom: 6px; display: block;
    }
    .form-group .form-control, .form-group .form-select {
        border-radius: 10px; border-color: var(--border-color, #e2e8f0); font-size: 0.88rem;
        background: var(--card-bg, #fff); color: var(--text-primary, #1e293b);
        padding: 10px 14px; transition: border-color 0.2s, box-shadow 0.2s;
    }
    .form-group .form-control:focus, .form-group .form-select:focus {
        border-color: #0c6e5e; box-shadow: 0 0 0 3px rgba(12,110,94,0.12);
    }
    .form-group .form-control::placeholder { color: #94a3b8; opacity: 1; }
    .form-group .form-text { font-size: 0.75rem; color: var(--text-muted, #94a3b8); margin-top: 4px; }

    /* ── Segment pills ──────────────────────────────────── */
    .segment-pills { display: flex; flex-wrap: wrap; gap: 8px; }
    .segment-pill {
        display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px;
        border-radius: 50px; font-size: 0.76rem; font-weight: 600; cursor: pointer;
        border: 1px solid var(--border-color, #e2e8f0);
        background: var(--card-bg, #fff); color: var(--text-primary, #475569);
        transition: all 0.2s cubic-bezier(.4,0,.2,1);
        user-select: none;
    }
    .segment-pill:hover { border-color: #0c6e5e; color: #0c6e5e; transform: translateY(-1px); }
    .segment-pill.active { background: #0c6e5e; color: #fff; border-color: #0c6e5e; box-shadow: 0 4px 12px rgba(12,110,94,0.25); }
    .segment-pill.active i { color: #fff; }

    /* ── Priority toggle ────────────────────────────────── */
    .priority-toggle { display: flex; gap: 8px; }
    .priority-opt {
        flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        padding: 9px 10px; border-radius: 10px; border: 1px solid var(--border-color, #e2e8f0);
        background: var(--card-bg, #fff); color: var(--text-primary, #475569);
        font-size: 0.8rem; font-weight: 600; cursor: pointer;
        transition: all 0.2s cubic-bezier(.4,0,.2,1);
    }
    .priority-opt:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.06); }
    .priority-opt.active { border-width: 1.5px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .priority-opt.low.active { background: #f1f5f9; border-color: #94a3b8; color: #475569; }
    .priority-opt.normal.active { background: #dbeafe; border-color: #3b82f6; color: #2563eb; }
    .priority-opt.urgent.active { background: #fee2e2; border-color: #ef4444; color: #dc2626; }

    /* ── Schedule toggle ────────────────────────────────── */
    .schedule-toggle {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        padding: 12px 14px; border: 1px dashed var(--border-color, #cbd5e1);
        border-radius: 12px; background: var(--card-bg, #f8fafc);
        cursor: pointer; transition: all 0.2s;
    }
    .schedule-toggle:hover { border-color: #0c6e5e; }
    .schedule-toggle.active { border-color: #0c6e5e; background: rgba(12,110,94,0.04); }
    .schedule-toggle-info { display: flex; align-items: center; gap: 10px; }
    .schedule-toggle-info .st-icon {
        width: 34px; height: 34px; border-radius: 9px; background: rgba(12,110,94,0.1);
        display: flex; align-items: center; justify-content: center; color: #0c6e5e;
    }
    .schedule-toggle-title { font-size: 0.84rem; font-weight: 700; color: var(--text-primary, #1e293b); }
    .schedule-toggle-sub { font-size: 0.72rem; color: var(--text-muted, #64748b); }
    .switch { position: relative; width: 42px; height: 24px; flex-shrink: 0; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch .slider {
        position: absolute; inset: 0; border-radius: 24px; cursor: pointer;
        background: var(--border-color, #cbd5e1); transition: all 0.25s cubic-bezier(.4,0,.2,1);
    }
    .switch .slider::before {
        content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%;
        left: 3px; top: 3px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        transition: transform 0.25s cubic-bezier(.4,0,.2,1);
    }
    .switch input:checked + .slider { background: #0c6e5e; }
    .switch input:checked + .slider::before { transform: translateX(18px); }

    /* ── Send button ────────────────────────────────────── */
    .btn-send {
        background: linear-gradient(135deg, #0c6e5e, #14b8a6); color: #fff; border: none;
        border-radius: 12px; font-weight: 700; padding: 13px; font-size: 0.9rem; width: 100%;
        box-shadow: 0 6px 18px rgba(12,110,94,0.3); transition: all 0.25s cubic-bezier(.4,0,.2,1);
        position: relative; overflow: hidden;
    }
    .btn-send::after {
        content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
        background: linear-gradient(120deg, transparent, rgba(255,255,255,0.25), transparent);
        transition: left 0.5s;
    }
    .btn-send:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(12,110,94,0.4); }
    .btn-send:hover::after { left: 100%; }
    .btn-send:disabled { opacity: 0.75; transform: none; cursor: wait; }

    /* ── Live preview ───────────────────────────────────── */
    .notif-preview-card {
        background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 14px; overflow: hidden; margin-top: 20px;
    }
    .notif-preview-header {
        padding: 10px 14px; border-bottom: 1px solid var(--border-color, #f1f5f9);
        font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        color: var(--text-muted, #64748b); display: flex; align-items: center; gap: 6px;
        cursor: pointer; user-select: none;
    }
    .notif-preview-header .chevron { transition: transform 0.25s; font-size: 0.7rem; }
    .notif-preview-card.minimized .notif-preview-body { display: none; }
    .notif-preview-card.minimized .chevron { transform: rotate(-90deg); }
    .notif-preview-body { padding: 16px; }
    .notif-preview-toast {
        display: flex; align-items: flex-start; gap: 12px; padding: 14px;
        background: var(--card-bg, #f8fafc); border-radius: 12px;
        border: 1px solid var(--border-color, #e2e8f0); position: relative; overflow: hidden;
    }
    .notif-preview-toast::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
        background: var(--prio-color, #3b82f6);
    }
    .notif-preview-toast .toast-icon {
        width: 38px; height: 38px; border-radius: 10px; display: flex;
        align-items: center; justify-content: center; flex-shrink: 0;
        background: rgba(12,110,94,0.1); color: #0c6e5e;
    }
    .notif-preview-toast .toast-title { font-weight: 700; font-size: 0.85rem; color: var(--text-primary, #1e293b); margin-bottom: 2px; }
    .notif-preview-toast .toast-msg { font-size: 0.78rem; color: var(--text-muted, #64748b); line-height: 1.4; }
    .notif-preview-toast .toast-time { font-size: 0.68rem; color: var(--text-muted, #94a3b8); margin-top: 4px; }
    .toast-prio {
        display: inline-block; font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.5px; padding: 2px 8px; border-radius: 50px; margin-left: 6px;
        vertical-align: middle;
    }

    /* ── Filter controls ────────────────────────────────── */
    .filter-pill {
        display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px;
        border-radius: 8px; font-size: 0.78rem; font-weight: 600; border: 1px solid var(--border-color, #e2e8f0);
        background: var(--card-bg, #fff); color: var(--text-primary, #475569); cursor: pointer;
        transition: all 0.2s; text-decoration: none;
    }
    .filter-pill:hover { border-color: #0c6e5e; color: #0c6e5e; }
    .filter-pill.active { background: #0c6e5e; color: #fff; border-color: #0c6e5e; }

    /* ── History items ──────────────────────────────────── */
    .notif-item {
        display: flex; align-items: flex-start; gap: 14px; padding: 16px 24px;
        border-bottom: 1px solid var(--border-color, #f1f5f9); transition: background 0.15s;
        position: relative;
    }
    .notif-item:hover { background: rgba(12,110,94,0.02); }
    .notif-item:last-child { border-bottom: none; }
    .notif-item .prio-ribbon {
        position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    }
    .notif-icon-wrap {
        width: 40px; height: 40px; border-radius: 12px; display: flex;
        align-items: center; justify-content: center; flex-shrink: 0;
    }
    .notif-content { flex: 1; min-width: 0; }
    .notif-title { font-weight: 700; font-size: 0.88rem; color: var(--text-primary, #1e293b); margin-bottom: 2px; }
    .notif-message {
        font-size: 0.8rem; color: var(--text-muted, #64748b);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 400px;
    }
    .notif-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .notif-meta .meta-tag {
        font-size: 0.68rem; padding: 2px 9px; border-radius: 50px;
        font-weight: 600; display: inline-flex; align-items: center; gap: 4px;
    }
    .status-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }

    /* ── Action menu ────────────────────────────────────── */
    .notif-actions { position: relative; flex-shrink: 0; }
    .menu-trigger {
        width: 32px; height: 32px; border-radius: 8px; display: flex;
        align-items: center; justify-content: center; border: none;
        background: transparent; color: var(--text-muted, #94a3b8); cursor: pointer;
        transition: all 0.2s; font-size: 0.9rem;
    }
    .menu-trigger:hover { background: var(--hover-bg, #f1f5f9); color: var(--text-primary, #1e293b); }
    .action-menu {
        position: absolute; top: calc(100% + 6px); right: 0; min-width: 190px;
        background: var(--dropdown-bg, #fff); border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 12px; box-shadow: 0 12px 32px rgba(0,0,0,0.14); z-index: 50;
        padding: 6px; opacity: 0; visibility: hidden; transform: translateY(-6px);
        transition: all 0.2s cubic-bezier(.4,0,.2,1);
    }
    .notif-actions.open .action-menu { opacity: 1; visibility: visible; transform: translateY(0); }
    .action-menu .am-item {
        display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px;
        border-radius: 8px; font-size: 0.8rem; font-weight: 500; color: var(--text-primary, #1e293b);
        cursor: pointer; border: none; background: transparent; text-align: left;
        transition: all 0.15s;
    }
    .action-menu .am-item i { width: 16px; text-align: center; color: var(--text-muted, #94a3b8); }
    .action-menu .am-item:hover { background: var(--hover-bg, #f1f5f9); color: #0c6e5e; }
    .action-menu .am-item:hover i { color: #0c6e5e; }
    .action-menu .am-item.danger:hover { background: rgba(239,68,68,0.08); color: #dc2626; }
    .action-menu .am-item.danger:hover i { color: #dc2626; }
    .action-menu .am-sep { height: 1px; background: var(--border-color, #e2e8f0); margin: 5px 8px; }

    /* ── Empty state ────────────────────────────────────── */
    .empty-state { text-align: center; padding: 48px 20px; color: var(--text-muted, #94a3b8); }
    .empty-state .empty-icon {
        width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 14px;
        display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
    }
    .empty-state h6 { font-weight: 700; font-size: 0.9rem; color: var(--text-primary, #1e293b); margin-bottom: 4px; }
    .empty-state p { font-size: 0.82rem; margin: 0; }

    .pagination .page-link {
        border-radius: 10px; margin: 0 3px; font-size: 0.85rem; font-weight: 600;
        border: 1px solid var(--border-color, #e2e8f0); color: var(--text-primary, #1e293b);
        padding: 6px 14px;
    }
    .pagination .page-item.active .page-link { background: #0c6e5e; border-color: #0c6e5e; color: #fff; }

    /* ── User select box ────────────────────────────────── */
    .user-select-box {
        max-height: 180px; overflow-y: auto; border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 10px; padding: 8px; background: var(--card-bg, #f8fafc);
    }
    .user-select-box::-webkit-scrollbar { width: 6px; }
    .user-select-box::-webkit-scrollbar-track { background: transparent; }
    .user-select-box::-webkit-scrollbar-thumb { background: var(--border-color, #cbd5e1); border-radius: 3px; }
    .user-check-item {
        display: flex; align-items: center; gap: 8px; padding: 6px 8px;
        border-radius: 8px; transition: background 0.15s; cursor: pointer;
    }
    .user-check-item:hover { background: rgba(12,110,94,0.05); }
    .user-check-item .avatar-sm { width: 28px; height: 28px; border-radius: 8px; object-fit: cover; }
    .user-check-item .check-name { font-size: 0.82rem; font-weight: 600; color: var(--text-primary, #1e293b); }
    .user-check-item .check-role {
        font-size: 0.68rem; padding: 2px 8px; border-radius: 6px;
        font-weight: 600; margin-left: auto;
    }

    .cleanup-card {
        background: var(--card-bg, #fff); border: 1px solid var(--border-color, #f1f5f9);
        border-radius: 16px; padding: 20px; margin-top: 20px;
    }
    .cleanup-card .input-group .form-control {
        border-radius: 10px 0 0 10px; border-color: var(--border-color, #e2e8f0);
        background: var(--card-bg, #fff); color: var(--text-primary, #1e293b);
    }
    .cleanup-card .input-group-text {
        border-color: var(--border-color, #e2e8f0); background: var(--card-bg, #f8fafc);
        color: var(--text-muted, #64748b); font-size: 0.82rem;
    }

    /* ── Schedule modal ─────────────────────────────────── */
    .sb-modal-backdrop {
        display: none; position: fixed; inset: 0; background: rgba(2,6,23,0.5);
        backdrop-filter: blur(3px); z-index: 9998;
    }
    .sb-modal-backdrop.show { display: block; }
    .sb-modal {
        display: none; position: fixed; inset: 0; z-index: 9999; align-items: center;
        justify-content: center; padding: 20px;
    }
    .sb-modal.show { display: flex; }
    .sb-modal-dialog {
        background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 18px; width: 420px; max-width: 100%; padding: 24px;
        box-shadow: 0 24px 64px rgba(0,0,0,0.25); animation: sbModalIn 0.25s cubic-bezier(.4,0,.2,1);
    }
    @keyframes sbModalIn { from { transform: translateY(14px) scale(0.97); opacity: 0; } to { transform: none; opacity: 1; } }
    .sb-modal-title { font-size: 1rem; font-weight: 800; color: var(--text-primary, #1e293b); margin-bottom: 4px; }
    .sb-modal-sub { font-size: 0.8rem; color: var(--text-muted, #64748b); margin-bottom: 18px; }
</style>

<div class="page-header-card p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="mb-1" style="font-weight:800;color:var(--text-primary,#1e293b);">
                <i class="fas fa-bell me-2" style="color:#0c6e5e;"></i>Notification Management
                <span class="badge ms-2" style="background:rgba(12,110,94,0.1);color:#0c6e5e;font-size:0.72rem;vertical-align:middle;"><?= count($all_users) ?> active users</span>
                <?php if ($total_scheduled > 0): ?>
                    <span class="badge ms-1" style="background:rgba(245,158,11,0.12);color:#d97706;font-size:0.72rem;vertical-align:middle;"><i class="fas fa-clock me-1"></i><?= $total_scheduled ?> scheduled</span>
                <?php endif; ?>
            </h4>
            <p class="mb-0" style="font-size:0.82rem;color:var(--text-muted,#64748b);">
                Send and manage notifications across your user base
            </p>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['val'=>$total_all,      'label'=>'Total Notifications', 'icon'=>'fa-bell',         'color'=>'#3b82f6', 'bg'=>'#dbeafe'],
        ['val'=>$total_unread,   'label'=>'Unread Count',        'icon'=>'fa-envelope-open', 'color'=>'#ef4444', 'bg'=>'#fee2e2'],
        ['val'=>$total_today,    'label'=>'Sent Today',          'icon'=>'fa-paper-plane',   'color'=>'#10b981', 'bg'=>'#d1fae5'],
        ['val'=>count($all_users), 'label'=>'Target Users',      'icon'=>'fa-users',         'color'=>'#8b5cf6', 'bg'=>'#ede9fe'],
    ];
    foreach ($statCards as $sc):
    ?>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card">
            <div class="stat-bar" style="background:<?= $sc['color'] ?>;"></div>
            <div class="stat-body">
                <div class="stat-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;">
                    <i class="fas <?= $sc['icon'] ?>"></i>
                </div>
                <div class="stat-value" style="color:<?= $sc['color'] ?>;"><?= $sc['val'] ?></div>
                <div class="stat-label"><?= $sc['label'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <!-- Compose -->
    <div class="col-lg-5">
        <div class="compose-card">
            <div class="compose-header">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(12,110,94,0.1);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-paper-plane" style="color:#0c6e5e;"></i>
                </div>
                <h6>Send Notification</h6>
            </div>
            <div class="compose-body">
                <form method="POST" id="notifForm" onsubmit="return handleSend(event)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_notification">
                    <input type="hidden" name="priority" id="priorityInput" value="normal">

                    <div class="form-group mb-3">
                        <label>Audience <span class="text-danger">*</span></label>
                        <div class="segment-pills" id="segmentPills">
                            <label class="segment-pill active" data-seg="all"><i class="fas fa-globe"></i>All Users</label>
                            <label class="segment-pill" data-seg="tourists"><i class="fas fa-user"></i>Tourists Only</label>
                            <label class="segment-pill" data-seg="staff"><i class="fas fa-user-tie"></i>Local Staff</label>
                            <label class="segment-pill" data-seg="active_bookings"><i class="fas fa-ticket"></i>Active Bookings</label>
                            <label class="segment-pill" data-seg="users"><i class="fas fa-list-check"></i>Select Users</label>
                        </div>
                        <input type="hidden" name="recipient" id="recipientInput" value="all">
                    </div>

                    <div class="form-group mb-3" id="roleGroup" style="display:none;">
                        <label>Target Role</label>
                        <select name="target_role" class="form-select">
                            <option value="tourist">Tourists</option>
                            <option value="guide">Tour Guides</option>
                            <option value="staff">Staff</option>
                            <option value="admin">Admins</option>
                        </select>
                    </div>

                    <div class="form-group mb-3" id="usersGroup" style="display:none;">
                        <label>Select Users</label>
                        <div class="user-select-box">
                            <?php foreach ($all_users as $u):
                                $roleColor = match($u['role']) { 'admin'=>'#ef4444', 'staff'=>'#f59e0b', 'guide'=>'#10b981', 'tourist'=>'#3b82f6', default=>'#6b7280' };
                            ?>
                                <label class="user-check-item" for="user_<?= $u['id'] ?>">
                                    <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?= $u['id'] ?>" id="user_<?= $u['id'] ?>">
                                    <span class="check-name"><?= sanitize($u['name']) ?></span>
                                    <span class="check-role" style="background:<?= $roleColor ?>15;color:<?= $roleColor ?>;"><?= ucfirst($u['role']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label>Type <span class="text-danger">*</span></label>
                        <select name="notif_type" class="form-select" id="typeSelect">
                            <option value="announcement">Announcement</option>
                            <option value="system">System Update</option>
                            <option value="event_published">Event Promo</option>
                            <option value="event_cancelled">Event Cancelled</option>
                            <option value="booking">Booking</option>
                            <option value="general">General</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label>Priority</label>
                        <div class="priority-toggle" id="priorityToggle">
                            <button type="button" class="priority-opt low" data-prio="low"><i class="fas fa-arrow-down-long"></i>Low</button>
                            <button type="button" class="priority-opt normal active" data-prio="normal"><i class="fas fa-bell"></i>Normal</button>
                            <button type="button" class="priority-opt urgent" data-prio="urgent"><i class="fas fa-triangle-exclamation"></i>Urgent</button>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label>Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="Notification title...">
                    </div>

                    <div class="form-group mb-3">
                        <label>Message <span class="text-danger">*</span></label>
                        <textarea name="message" class="form-control" rows="3" required placeholder="Write your message..."></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <label>Action Link <small class="text-muted">(optional)</small></label>
                        <input type="text" name="link" class="form-control" placeholder="/tourist/events.php">
                        <div class="form-text">Relative URL to navigate when clicked.</div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="schedule-toggle" id="scheduleToggle">
                            <span class="schedule-toggle-info">
                                <span class="st-icon"><i class="fas fa-clock"></i></span>
                                <span>
                                    <span class="schedule-toggle-title">Schedule for later</span><br>
                                    <span class="schedule-toggle-sub" id="scheduleSub">Broadcast instantly now, or pick a future date.</span>
                                </span>
                            </span>
                            <span class="switch">
                                <input type="checkbox" name="schedule_enabled" id="scheduleEnabled" value="1">
                                <span class="slider"></span>
                            </span>
                        </label>
                        <div id="scheduleInputWrap" style="display:none;margin-top:10px;">
                            <input type="datetime-local" name="scheduled_at" id="scheduledAt" class="form-control" min="<?= date('Y-m-d\TH:i') ?>">
                            <div class="form-text">The broadcast will be marked "Scheduled" until this time.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-send" id="sendBtn">
                        <i class="fas fa-paper-plane me-2" id="sendIcon"></i><span id="sendLabel">Send Notification</span>
                    </button>
                </form>

                <div class="notif-preview-card" id="previewCard">
                    <div class="notif-preview-header" onclick="togglePreview()">
                        <i class="fas fa-eye"></i> Live Preview
                        <i class="fas fa-chevron-down chevron ms-auto"></i>
                    </div>
                    <div class="notif-preview-body">
                        <div class="notif-preview-toast" id="previewToast">
                            <div class="toast-icon" id="previewIcon"><i class="fas fa-bell"></i></div>
                            <div style="flex:1;min-width:0;">
                                <div class="toast-title">
                                    <span id="previewTitle">Notification Title</span>
                                    <span class="toast-prio" id="previewPrio">Normal</span>
                                </div>
                                <div class="toast-msg" id="previewMsg">Your message will appear here as you type.</div>
                                <div class="toast-time" id="previewTime">Just now</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cleanup -->
        <div class="cleanup-card">
            <div class="d-flex align-items-center gap-2 mb-3">
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-broom" style="color:#ef4444;font-size:0.85rem;"></i>
                </div>
                <span class="fw-bold small" style="color:var(--text-primary,#1e293b);">Cleanup Old Notifications</span>
            </div>
            <form method="POST" onsubmit="return confirm('Delete all notifications older than the specified days?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_old">
                <div class="input-group">
                    <input type="number" name="days" class="form-control" value="30" min="7" max="365">
                    <span class="input-group-text">days</span>
                    <button type="submit" class="btn" style="background:#ef4444;color:#fff;border-radius:0 10px 10px 0;font-weight:600;font-size:0.82rem;">
                        <i class="fas fa-trash me-1"></i>Clean
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- History -->
    <div class="col-lg-7">
        <div class="history-card">
            <div class="history-header justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(12,110,94,0.1);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-history" style="color:#0c6e5e;"></i>
                    </div>
                    <h6>History <span class="text-muted" style="font-weight:400;font-size:0.82rem;">(<?= $total ?>)</span></h6>
                </div>
                <form method="GET" class="d-flex gap-1 align-items-center">
                    <select name="type" class="form-select form-select-sm" style="width:auto;border-radius:8px;font-size:0.78rem;" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <?php foreach ($type_configs as $tk => $tv): ?>
                            <option value="<?= $tk ?>" <?= $filter_type === $tk ? 'selected' : '' ?>><?= $tv['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="position-relative">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="<?= sanitize($filter_search) ?>" style="width:130px;border-radius:8px;font-size:0.78rem;padding-right:32px;">
                        <button class="position-absolute" type="submit" style="right:6px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--text-muted,#94a3b8);padding:0;">
                            <i class="fas fa-search" style="font-size:0.78rem;"></i>
                        </button>
                    </div>
                </form>
            </div>

            <div>
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;"><i class="fas fa-bell-slash"></i></div>
                        <h6>No notifications found</h6>
                        <p>Send your first notification using the compose form.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $n):
                        $tc = $type_configs[$n['type']] ?? $type_configs['general'];
                        $pc = $priority_configs[$n['priority']] ?? $priority_configs['normal'];

                        $created = new DateTime($n['created_at']);
                        $now = new DateTime();
                        $diff = $now->diff($created);
                        if ($diff->days === 0 && $diff->h < 1 && $diff->i < 1) $time_label = 'Just now';
                        elseif ($diff->days === 0 && $diff->h < 1) $time_label = $diff->i . 'm ago';
                        elseif ($diff->days === 0) $time_label = $diff->h . 'h ago';
                        elseif ($diff->days === 1) $time_label = 'Yesterday';
                        elseif ($diff->days < 7) $time_label = $diff->days . 'd ago';
                        else $time_label = date('M d', strtotime($n['created_at']));

                        $is_scheduled = ($n['status'] === 'scheduled');
                        $is_failed = ($n['status'] === 'failed');
                        $recipient_count = (int)($n['recipient_count'] ?: $n['recipients']);
                        $audience_label = $n['audience'] ?: 'Single recipient';
                    ?>
                        <div class="notif-item">
                            <div class="prio-ribbon" style="background:<?= $pc['color'] ?>;"></div>
                            <div class="notif-icon-wrap" style="background:<?= $tc['bg'] ?>;">
                                <i class="fas <?= $tc['icon'] ?>" style="color:<?= $tc['color'] ?>;font-size:1rem;"></i>
                            </div>
                            <div class="notif-content">
                                <div class="notif-title">
                                    <?= sanitize($n['title']) ?>
                                    <span class="meta-tag" style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>;margin-left:4px;">
                                        <i class="fas <?= $n['priority'] === 'urgent' ? 'fa-triangle-exclamation' : ($n['priority'] === 'low' ? 'fa-arrow-down-long' : 'fa-bell') ?>" style="font-size:0.62rem;"></i>
                                        <?= $pc['label'] ?>
                                    </span>
                                </div>
                                <div class="notif-message"><?= sanitize($n['message']) ?></div>
                                <div class="notif-meta">
                                    <span class="meta-tag" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;">
                                        <?= $tc['label'] ?>
                                    </span>
                                    <span class="meta-tag" style="background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b);">
                                        <i class="fas fa-users"></i><?= $recipient_count ?> recipient<?= $recipient_count !== 1 ? 's' : '' ?> · <?= sanitize($audience_label) ?>
                                    </span>
                                    <span class="meta-tag" style="background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b);">
                                        <i class="fas fa-clock"></i><?= $time_label ?>
                                    </span>
                                    <?php if ($is_scheduled): ?>
                                        <span class="meta-tag" style="background:rgba(245,158,11,0.12);color:#d97706;">
                                            <span class="status-dot" style="background:#f59e0b;"></span>Scheduled · <?= date('M d, g:i A', strtotime($n['scheduled_at'])) ?>
                                        </span>
                                    <?php elseif ($is_failed): ?>
                                        <span class="meta-tag" style="background:rgba(239,68,68,0.1);color:#dc2626;">
                                            <span class="status-dot" style="background:#ef4444;"></span>Failed
                                        </span>
                                    <?php else: ?>
                                        <span class="meta-tag" style="background:rgba(16,185,129,0.1);color:#059669;">
                                            <span class="status-dot" style="background:#10b981;"></span>Delivered
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="notif-actions">
                                <button type="button" class="menu-trigger" onclick="toggleMenu(this)" title="Actions"><i class="fas fa-ellipsis-vertical"></i></button>
                                <div class="action-menu">
                                    <form method="POST" action="">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="resend_notification">
                                        <input type="hidden" name="batch_id" value="<?= $n['gid'] ?>">
                                        <button type="submit" class="am-item"><i class="fas fa-rotate-right"></i>Resend Notification</button>
                                    </form>
                                    <?php if ($is_scheduled): ?>
                                        <button type="button" class="am-item" onclick="openScheduleModal('<?= $n['gid'] ?>', '<?= date('Y-m-d\TH:i', strtotime($n['scheduled_at'])) ?>')">
                                            <i class="fas fa-pen"></i>Edit Scheduled
                                        </button>
                                    <?php endif; ?>
                                    <div class="am-sep"></div>
                                    <form method="POST" action="" onsubmit="return confirm('Recall and delete this notification for all recipients?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_notification">
                                        <input type="hidden" name="batch_id" value="<?= $n['gid'] ?>">
                                        <button type="submit" class="am-item danger"><i class="fas fa-trash"></i>Delete / Recall</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
            <div style="padding:16px 24px;border-top:1px solid var(--border-color,#f1f5f9);">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?type=<?= $filter_type ?>&search=<?= urlencode($filter_search) ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                        </li>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?type=<?= $filter_type ?>&search=<?= urlencode($filter_search) ?>&page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?type=<?= $filter_type ?>&search=<?= urlencode($filter_search) ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Schedule edit modal -->
<div class="sb-modal-backdrop" id="sbModalBackdrop"></div>
<div class="sb-modal" id="sbModal">
    <div class="sb-modal-dialog">
        <div class="sb-modal-title"><i class="fas fa-clock me-2" style="color:#0c6e5e;"></i>Edit Scheduled Time</div>
        <p class="sb-modal-sub">Pick a new broadcast time for this notification.</p>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_scheduled">
            <input type="hidden" name="batch_id" id="sbModalBatch">
            <input type="datetime-local" name="scheduled_at" id="sbModalDatetime" class="form-control mb-3" style="border-radius:10px;border-color:var(--border-color,#e2e8f0);padding:10px 14px;">
            <div class="d-flex gap-2">
                <button type="button" class="btn flex-fill" onclick="closeScheduleModal()" style="background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#475569);border-radius:10px;font-weight:600;font-size:0.85rem;">Cancel</button>
                <button type="submit" class="btn flex-fill" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;font-size:0.85rem;">
                    <i class="fas fa-check me-1"></i>Save Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── Segment pills ─────────────────────────────────────── */
const segmentPills = document.getElementById('segmentPills');
const recipientInput = document.getElementById('recipientInput');
const roleGroup = document.getElementById('roleGroup');
const usersGroup = document.getElementById('usersGroup');

segmentPills.querySelectorAll('.segment-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        segmentPills.querySelectorAll('.segment-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        recipientInput.value = pill.dataset.seg;
        roleGroup.style.display = pill.dataset.seg === 'role' ? 'block' : 'none';
        usersGroup.style.display = pill.dataset.seg === 'users' ? 'block' : 'none';
        updatePreview();
    });
});

/* ── Priority toggle ───────────────────────────────────── */
const priorityToggle = document.getElementById('priorityToggle');
const priorityInput = document.getElementById('priorityInput');

priorityToggle.querySelectorAll('.priority-opt').forEach(function(opt) {
    opt.addEventListener('click', function() {
        priorityToggle.querySelectorAll('.priority-opt').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        priorityInput.value = opt.dataset.prio;
        updatePreview();
    });
});

/* ── Schedule toggle ───────────────────────────────────── */
const scheduleToggle = document.getElementById('scheduleToggle');
const scheduleEnabled = document.getElementById('scheduleEnabled');
const scheduleInputWrap = document.getElementById('scheduleInputWrap');
const scheduledAt = document.getElementById('scheduledAt');
const scheduleSub = document.getElementById('scheduleSub');

scheduleToggle.addEventListener('click', function(e) {
    if (e.target.closest('.switch')) return;
    scheduleEnabled.checked = !scheduleEnabled.checked;
    syncSchedule();
});
scheduleEnabled.addEventListener('change', syncSchedule);

function syncSchedule() {
    scheduleToggle.classList.toggle('active', scheduleEnabled.checked);
    scheduleInputWrap.style.display = scheduleEnabled.checked ? 'block' : 'none';
    if (scheduleEnabled.checked && !scheduledAt.value) {
        var d = new Date(Date.datetime('now') + 3600000);
        var pad = function(n) { return n < 10 ? '0' + n : n; };
        scheduledAt.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    scheduleSub.textContent = scheduleEnabled.checked
        ? 'This broadcast will be delivered at the scheduled time.'
        : 'Broadcast instantly now, or pick a future date.';
    updatePreview();
}

/* ── Live preview ──────────────────────────────────────── */
const typeIcons = {
    'announcement': 'fa-bullhorn', 'system': 'fa-cog', 'booking': 'fa-ticket-alt',
    'event_published': 'fa-calendar-check', 'event_cancelled': 'fa-calendar-xmark',
    'general': 'fa-bell'
};
const prioColors = { 'low': '#64748b', 'normal': '#3b82f6', 'urgent': '#ef4444' };
const prioBg = { 'low': '#f1f5f9', 'normal': '#dbeafe', 'urgent': '#fee2e2' };

const titleInput = document.querySelector('input[name="title"]');
const msgInput = document.querySelector('textarea[name="message"]');
const typeSelect = document.getElementById('typeSelect');
const previewCard = document.getElementById('previewCard');
const previewToast = document.getElementById('previewToast');

function updatePreview() {
    document.getElementById('previewTitle').textContent = titleInput.value || 'Notification Title';
    document.getElementById('previewMsg').textContent = msgInput.value || 'Your message will appear here as you type.';

    const icon = typeIcons[typeSelect.value] || 'fa-bell';
    document.getElementById('previewIcon').innerHTML = '<i class="fas ' + icon + '"></i>';

    const prio = priorityInput.value || 'normal';
    const prioLabel = document.getElementById('previewPrio');
    prioLabel.textContent = prio.charAt(0).toUpperCase() + prio.slice(1);
    prioLabel.style.background = prioBg[prio];
    prioLabel.style.color = prioColors[prio];
    previewToast.style.setProperty('--prio-color', prioColors[prio]);

    const timeEl = document.getElementById('previewTime');
    if (scheduleEnabled.checked && scheduledAt.value) {
        var d = new Date(scheduledAt.value);
        timeEl.textContent = 'Scheduled · ' + d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    } else {
        timeEl.textContent = 'Delivered just now';
    }
}

titleInput.addEventListener('input', updatePreview);
msgInput.addEventListener('input', updatePreview);
typeSelect.addEventListener('change', updatePreview);

function togglePreview() {
    previewCard.classList.toggle('minimized');
}

/* ── Send button loading state ─────────────────────────── */
function handleSend(e) {
    const form = e.target;
    const btn = document.getElementById('sendBtn');
    const label = document.getElementById('sendLabel');
    const icon = document.getElementById('sendIcon');
    const prio = priorityInput.value;

    if (scheduleEnabled.checked && scheduledAt.value) {
        var when = new Date(scheduledAt.value);
        if (when.getTime() <= Date.datetime('now')) {
            alert('Scheduled time must be in the future, or turn off scheduling to send now.');
            e.preventDefault();
            return false;
        }
    }
    if (prio === 'urgent' && !confirm('This is an URGENT notification. Continue?')) {
        e.preventDefault();
        return false;
    }

    btn.disabled = true;
    label.textContent = scheduleEnabled.checked ? 'Scheduling…' : 'Sending…';
    icon.className = 'fas fa-spinner fa-spin me-2';
    return true;
}

/* ── Action menus ──────────────────────────────────────── */
function toggleMenu(btn) {
    var wrap = btn.closest('.notif-actions');
    var open = wrap.classList.contains('open');
    document.querySelectorAll('.notif-actions.open').forEach(function(w) { w.classList.remove('open'); });
    if (!open) wrap.classList.add('open');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.notif-actions')) {
        document.querySelectorAll('.notif-actions.open').forEach(function(w) { w.classList.remove('open'); });
    }
});

/* ── Schedule modal ────────────────────────────────────── */
function openScheduleModal(batchId, current) {
    document.getElementById('sbModalBatch').value = batchId;
    document.getElementById('sbModalDatetime').value = current;
    document.getElementById('sbModal').classList.add('show');
    document.getElementById('sbModalBackdrop').classList.add('show');
}
function closeScheduleModal() {
    document.getElementById('sbModal').classList.remove('show');
    document.getElementById('sbModalBackdrop').classList.remove('show');
}
document.getElementById('sbModalBackdrop').addEventListener('click', closeScheduleModal);
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeScheduleModal();
});

updatePreview();
</script>

<?php }); ?>
