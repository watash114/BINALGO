<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
start_session();
$page_title = $page_title ?? 'Dashboard';
$_user = current_user();
$_is_logged_in = is_logged_in();
$_role = $_SESSION['role'] ?? null;
$_unread_notifications = 0;
$_user_theme = 'light';
if ($_is_logged_in) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = :uid AND is_read = 0");
    $stmt->execute([':uid' => $_SESSION['user_id']]);
    $_unread_notifications = (int) $stmt->fetch()['cnt'];
    try {
        $theme_stmt = $db->prepare("SELECT theme FROM users WHERE id = :uid LIMIT 1");
        $theme_stmt->execute([':uid' => $_SESSION['user_id']]);
        $_user_theme = $theme_stmt->fetch()['theme'] ?? 'light';
    } catch (\PDOException $e) {
        error_log("Theme column missing: " . $e->getMessage());
    }
}
$_base_path_map = [
    'admin'   => BASE_URL . '/admin',
    'staff'   => BASE_URL . '/staff',
    'tourist' => BASE_URL . '/tourist',
];
$_base = $_base_path_map[$_role] ?? BASE_URL . '/';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($_user_theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title><?= sanitize($page_title) ?> | BINALGO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <?php if (($_role ?? null) === 'staff'): ?>
    <link href="<?= BASE_URL ?>/assets/css/staff.css" rel="stylesheet">
    <?php endif; ?>
    <script>
    (function(){
        var t = localStorage.getItem('theme') || '<?= $_user_theme ?>';
        if(t === 'system') t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <style>
        :root {
            --sidebar-width: 260px;
            --topbar-height: 60px;
            --primary: #1a73e8;
            --primary-dark: #1557b0;
            --sidebar-bg: #1e293b;
            --sidebar-hover: #334155;
            --sidebar-active: rgba(26,115,232,0.15);
            --sidebar-text: #94a3b8;
            --sidebar-text-active: #ffffff;
        }
        [data-theme="light"] {
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --card-bg: #ffffff;
            --dropdown-bg: #ffffff;
            --input-bg: #ffffff;
            --sidebar-card-bg: #ffffff;
            --hover-bg: #f1f5f9;
            --table-bg: #ffffff;
            --table-stripe: #f8fafc;
            --badge-bg: #e2e8f0;
            --shadow: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-lg: 0 4px 12px rgba(0,0,0,0.08);
        }
        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-tertiary: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --dropdown-bg: #1e293b;
            --input-bg: #334155;
            --sidebar-card-bg: #1e293b;
            --hover-bg: #334155;
            --table-bg: #1e293b;
            --table-stripe: #0f172a;
            --badge-bg: #334155;
            --shadow: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-lg: 0 4px 12px rgba(0,0,0,0.4);
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--topbar-height);
            background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            z-index: 1030;
            box-shadow: var(--shadow);
            transition: background 0.3s, border-color 0.3s, left 0.3s cubic-bezier(.4,0,.2,1);
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
        }
        .topbar-brand i { font-size: 1.5rem; }
        .topbar-right { display: flex; align-items: center; gap: 8px; }
        .notification-wrapper {
            position: relative;
        }
        .notification-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 1.2rem;
            color: rgba(255,255,255,0.85);
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            transition: background 0.2s, color 0.2s;
        }
        .notification-btn:hover { background: rgba(255,255,255,0.15); color: #ffffff; }
        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #ef4444;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            line-height: 1;
        }
        .notification-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 380px;
            max-height: 480px;
            background: var(--dropdown-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            z-index: 9999;
            display: none;
            overflow: hidden;
        }
        .notification-dropdown.show { display: block; }
        .notif-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .notif-header h6 { margin: 0; font-weight: 700; font-size: 0.95rem; color: var(--text-primary); }
        .notif-body {
            max-height: 360px;
            overflow-y: auto;
        }
        .notif-empty {
            padding: 32px 16px;
            text-align: center;
            color: var(--text-muted);
        }
        .notif-empty i { font-size: 2rem; margin-bottom: 8px; display: block; }
        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            gap: 12px;
            cursor: pointer;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
        }
        .notif-item:hover { background: var(--hover-bg); }
        .notif-item.unread { background: rgba(26,115,232,0.04); }
        .notif-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.85rem;
        }
        .notif-icon.booking { background: #dbeafe; color: #2563eb; }
        .notif-icon.cancellation { background: #fee2e2; color: #dc2626; }
        .notif-icon.payment_success { background: #d1fae5; color: #059669; }
        .notif-icon.payment_failed { background: #fee2e2; color: #dc2626; }
        .notif-icon.feedback { background: #fef3c7; color: #d97706; }
        .notif-icon.event_published { background: #dbeafe; color: #2563eb; }
        .notif-icon.event_cancelled { background: #fee2e2; color: #dc2626; }
        .notif-icon.announcement { background: #e0e7ff; color: #4f46e5; }
        .notif-icon.system { background: #e2e8f0; color: #475569; }
        .notif-icon.verification { background: #d1fae5; color: #059669; }
        .notif-icon.assignment { background: #dbeafe; color: #2563eb; }
        .notif-icon.general { background: #e2e8f0; color: #475569; }
        .notif-icon.registration { background: #ede9fe; color: #7c3aed; }
        [data-theme="dark"] .notif-icon.booking { background: #1e3a5f; }
        [data-theme="dark"] .notif-icon.cancellation { background: #5f1e1e; }
        [data-theme="dark"] .notif-icon.payment_success { background: #1e5f3a; }
        [data-theme="dark"] .notif-icon.payment_failed { background: #5f1e1e; }
        [data-theme="dark"] .notif-icon.feedback { background: #5f4b1e; }
        [data-theme="dark"] .notif-icon.event_published { background: #1e3a5f; }
        [data-theme="dark"] .notif-icon.event_cancelled { background: #5f1e1e; }
        [data-theme="dark"] .notif-icon.announcement { background: #2d2b6b; }
        [data-theme="dark"] .notif-icon.system { background: #334155; }
        [data-theme="dark"] .notif-icon.verification { background: #1e5f3a; }
        [data-theme="dark"] .notif-icon.assignment { background: #1e3a5f; }
        [data-theme="dark"] .notif-icon.registration { background: #2d1b69; }
        .notif-content { flex: 1; min-width: 0; }
        .notif-title { font-size: 0.82rem; font-weight: 600; margin-bottom: 2px; color: var(--text-primary); }
        .notif-msg { font-size: 0.78rem; color: var(--text-muted); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .notif-time { font-size: 0.7rem; color: var(--text-muted); margin-top: 2px; }
        .notif-footer {
            padding: 10px 16px;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }
        .notif-footer a { font-size: 0.82rem; font-weight: 600; text-decoration: none; }
        [data-theme="dark"] .notif-footer a { color: var(--primary); }

        .theme-toggle {
            background: none;
            border: 1px solid rgba(255,255,255,0.25);
            color: rgba(255,255,255,0.85);
            cursor: pointer;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .theme-toggle:hover { background: rgba(255,255,255,0.15); color: #ffffff; border-color: rgba(255,255,255,0.4); }

        .user-dropdown {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 4px 12px 4px 4px;
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.25);
            background: rgba(255,255,255,0.1);
            text-decoration: none;
            color: #ffffff;
            transition: box-shadow 0.2s, background 0.3s;
        }
        .user-dropdown:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.15); text-decoration: none; color: #ffffff; background: rgba(255,255,255,0.2); }
        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.3);
        }
        .user-info-line { display: flex; flex-direction: column; line-height: 1.2; }
        .user-info-name { font-size: 0.85rem; font-weight: 600; color: #ffffff; }
        .role-badge {
            display: inline-block;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 8px;
            border-radius: 50px;
            background: rgba(255,255,255,0.2);
            color: #ffffff;
        }
        .content-wrapper {
            margin-left: var(--sidebar-width);
            margin-top: var(--topbar-height);
            min-height: calc(100vh - var(--topbar-height));
            transition: margin-left 0.3s cubic-bezier(.4,0,.2,1);
        }
        .main-footer {
            background: var(--bg-primary);
            border-top: 1px solid var(--border-color);
            padding: 16px 24px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        @media (max-width: 991.98px) {
            :root { --sidebar-width: 0px; }
            .topbar { left: 0; }
            .content-wrapper { margin-left: 0; }
            .notification-dropdown { width: 320px; right: -60px; }
        }
    </style>
</head>
<body>

<?php if ($_is_logged_in): ?>
<div class="topbar">
    <div class="d-flex align-items-center">
        <button class="btn btn-sm d-lg-none me-2" id="sidebarToggle" style="border:1px solid rgba(255,255,255,0.25); border-radius:8px; color:rgba(255,255,255,0.85); background:rgba(255,255,255,0.1);">
            <i class="fas fa-bars"></i>
        </button>
        <button class="btn btn-sm d-none d-lg-flex me-2" id="sidebarCollapseToggle" title="Collapse sidebar" style="border:1px solid rgba(255,255,255,0.25); border-radius:8px; color:rgba(255,255,255,0.85); background:rgba(255,255,255,0.1); width:38px; height:38px; align-items:center; justify-content:center; transition:background 0.2s, color 0.2s;">
            <i class="fas fa-angles-left"></i>
        </button>
        <a href="<?= $_base ?>/index.php" class="topbar-brand">
            <i class="fas fa-map-marked-alt"></i>
BINALGO
        </a>
    </div>
    <div class="topbar-right">
        <button class="theme-toggle" id="themeToggle" title="Toggle Theme" type="button">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>

        <div class="notification-wrapper">
            <button class="notification-btn" id="notifBtn" title="Notifications" type="button">
                <i class="fas fa-bell"></i>
                <?php if ($_unread_notifications > 0): ?>
                    <span class="notification-badge" id="notifBadge"><?= $_unread_notifications > 99 ? '99+' : $_unread_notifications ?></span>
                <?php else: ?>
                    <span class="notification-badge" id="notifBadge" style="display:none;">0</span>
                <?php endif; ?>
            </button>
            <div class="notification-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <h6><i class="fas fa-bell me-2"></i>Notifications</h6>
                    <div>
                        <button class="btn btn-sm btn-link text-decoration-none p-0 me-2" id="markAllReadBtn" title="Mark all read" style="font-size:0.78rem;">Mark all read</button>
                        <a href="<?= $_base ?>/notifications.php" class="btn btn-sm btn-link text-decoration-none p-0" style="font-size:0.78rem;">View all</a>
                    </div>
                </div>
                <div class="notif-body" id="notifBody">
                    <div class="notif-empty"><i class="fas fa-bell-slash"></i>Loading...</div>
                </div>
                <div class="notif-footer" id="notifFooter" style="display:none;">
                    <a href="<?= $_base ?>/notifications.php">View all notifications</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="topbar">
    <a href="<?= BASE_URL ?>/" class="topbar-brand">
        <i class="fas fa-map-marked-alt"></i>
BINALGO
    </a>
    <div class="topbar-right">
        <button class="theme-toggle" id="themeToggle" title="Toggle Theme" type="button">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
        <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-outline-primary btn-sm px-3">Login</a>
        <a href="<?= BASE_URL ?>/auth/register.php" class="btn btn-primary btn-sm px-3">Register</a>
    </div>
</div>
<?php endif; ?>

<?php
$_flash = get_flash_message();
if ($_flash):
    $_alert_type = 'info';
    $_icon = 'fa-info-circle';
    switch ($_flash['type']) {
        case 'success':
            $_alert_type = 'alert-success';
            $_icon = 'fa-check-circle';
            break;
        case 'error':
            $_alert_type = 'alert-danger';
            $_icon = 'fa-exclamation-circle';
            break;
        case 'warning':
            $_alert_type = 'alert-warning';
            $_icon = 'fa-exclamation-triangle';
            break;
        default:
            $_alert_type = 'alert-info';
            $_icon = 'fa-info-circle';
            break;
    }
?>
<div class="position-fixed" style="top: calc(var(--topbar-height) + 12px); right: 24px; z-index: 9999; max-width: 420px; width: 100%;">
    <div class="alert <?= $_alert_type ?> alert-dismissible fade show shadow-sm d-flex align-items-center" role="alert" style="border-radius: 10px;">
        <i class="fas <?= $_icon ?> me-2 fs-5"></i>
        <span><?= sanitize($_flash['message']) ?></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php endif; ?>

<script>
function confirmLogout(url) {
    var modal = document.getElementById('logoutModal');
    var confirmBtn = document.getElementById('logoutConfirmBtn');
    var closeBtn = document.getElementById('logoutCloseBtn');
    var cancelBtn = document.getElementById('logoutCancelBtn');
    var backdrop = document.getElementById('logoutBackdrop');

    function openModal() {
        backdrop.style.display = 'block';
        modal.style.display = 'flex';
        requestAnimationFrame(function() {
            backdrop.style.opacity = '1';
            modal.style.opacity = '1';
            modal.querySelector('.logout-dialog').style.transform = 'scale(1) translateY(0)';
        });
    }

    function closeModal() {
        backdrop.style.opacity = '0';
        modal.style.opacity = '0';
        modal.querySelector('.logout-dialog').style.transform = 'scale(0.95) translateY(10px)';
        setTimeout(function() {
            backdrop.style.display = 'none';
            modal.style.display = 'none';
        }, 250);
    }

    openModal();

    confirmBtn.onclick = function() {
        closeModal();
        setTimeout(function() { window.location.href = url; }, 280);
    };
    closeBtn.onclick = closeModal;
    cancelBtn.onclick = closeModal;
    backdrop.onclick = closeModal;
}
window.__is_logged_in = <?= $_is_logged_in ? 'true' : 'false' ?>;
window.__user_role = <?= json_encode($_role) ?>;
(function(){
    var baseUrl = document.querySelector('meta[name="base-url"]').content;

    // ─── Theme Toggle ─────────────────────────────────────
    var themeToggle = document.getElementById('themeToggle');
    var themeIcon = document.getElementById('themeIcon');

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        if (window.__is_logged_in) {
            fetch(baseUrl + '/api/theme.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'theme=' + theme
            }).catch(function(){});
        }
    }

    var currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
    themeIcon.className = currentTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
        });
    }

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        if (!localStorage.getItem('theme') || localStorage.getItem('theme') === 'system') {
            applyTheme(e.matches ? 'dark' : 'light');
        }
    });

    // ─── Notification Dropdown ────────────────────────────
    var notifBtn = document.getElementById('notifBtn');
    var notifDropdown = document.getElementById('notifDropdown');
    var notifBody = document.getElementById('notifBody');
    var notifBadge = document.getElementById('notifBadge');
    var notifFooter = document.getElementById('notifFooter');
    var markAllReadBtn = document.getElementById('markAllReadBtn');

    var notifTypeIcons = {
        booking: 'fa-ticket',
        cancellation: 'fa-times-circle',
        payment_success: 'fa-check-circle',
        payment_failed: 'fa-exclamation-triangle',
        feedback: 'fa-star',
        event_published: 'fa-calendar-check',
        event_cancelled: 'fa-calendar-xmark',
        event_updated: 'fa-calendar-day',
        announcement: 'fa-bullhorn',
        system: 'fa-cog',
        verification: 'fa-id-card',
        assignment: 'fa-user-check',
        registration: 'fa-user-plus',
        schedule: 'fa-clock',
        general: 'fa-bell'
    };

    function timeAgo(dateStr) {
        var now = new Date();
        var then = new Date(dateStr);
        var diff = Math.floor((now - then) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return then.toLocaleDateString();
    }

    function renderNotifs(notifs) {
        if (!notifs || notifs.length === 0) {
            notifBody.innerHTML = '<div class="notif-empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>';
            notifFooter.style.display = 'none';
            return;
        }
        var html = '';
        for (var i = 0; i < notifs.length; i++) {
            var n = notifs[i];
            var icon = notifTypeIcons[n.type] || 'fa-bell';
            var rawLink = n.link || '';
            if (window.__user_role && window.__user_role !== 'admin') {
                var rolePages = {
                    'tourist': ['index.php','destinations.php','events.php','bookings.php','notifications.php','messages.php','call_history.php','feedback.php','about.php','profile.php','destination_detail.php','browse.php','event_detail.php'],
                    'staff': ['index.php','profile.php','bookings.php','schedules.php','destinations.php','events.php','messages.php','notifications.php','feedback.php']
                };
                var validPages = rolePages[window.__user_role] || [];
                if (rawLink.startsWith('/admin/')) {
                    var adminPage = rawLink.substring(7);
                    if (validPages.indexOf(adminPage) !== -1) {
                        rawLink = '/' + window.__user_role + '/' + adminPage;
                    } else {
                        rawLink = '/' + window.__user_role + '/index.php';
                    }
                }
                if (!rawLink.startsWith('/' + window.__user_role)) {
                    rawLink = '/' + window.__user_role + '/index.php';
                }
            }
            var link = rawLink ? (baseUrl + rawLink) : 'javascript:void(0)';
            html += '<a class="notif-item ' + (n.is_read == 0 ? 'unread' : '') + '" href="' + link + '" data-id="' + n.id + '">' +
                '<div class="notif-icon ' + n.type + '"><i class="fas ' + icon + '"></i></div>' +
                '<div class="notif-content">' +
                '<div class="notif-title">' + escapeHtml(n.title) + '</div>' +
                '<div class="notif-msg">' + escapeHtml(n.message) + '</div>' +
                '<div class="notif-time">' + timeAgo(n.created_at) + '</div>' +
                '</div></a>';
        }
        notifBody.innerHTML = html;
        notifFooter.style.display = notifs.length >= 5 ? 'block' : 'none';

        notifBody.querySelectorAll('.notif-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                var href = this.getAttribute('href');
                if (href && href !== 'javascript:void(0)') {
                    notifDropdown.classList.remove('show');
                    window.location.href = href;
                }
            });
        });

        var unreadCount = 0;
        for (var j = 0; j < notifs.length; j++) {
            if (notifs[j].is_read == 0) unreadCount++;
        }
        if (unreadCount > 0) {
            notifBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
            notifBadge.style.display = 'flex';
        } else {
            notifBadge.style.display = 'none';
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function fetchNotifs() {
        fetch(baseUrl + '/api/notifications.php?action=list')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (Array.isArray(data)) renderNotifs(data);
            })
            .catch(function() {});
    }

    function fetchCount() {
        fetch(baseUrl + '/api/notifications.php?action=count')
            .then(function(r) { return r.text(); })
            .then(function(count) {
                count = parseInt(count) || 0;
                if (count > 0) {
                    notifBadge.textContent = count > 99 ? '99+' : count;
                    notifBadge.style.display = 'flex';
                } else {
                    notifBadge.style.display = 'none';
                }
            })
            .catch(function() {});
    }

    if (notifBtn) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var isOpen = notifDropdown.classList.contains('show');
            if (!isOpen) {
                fetchNotifs();
            }
            notifDropdown.classList.toggle('show');
        });

        document.addEventListener('click', function(e) {
            if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
                notifDropdown.classList.remove('show');
            }
        });

        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function() {
                fetch(baseUrl + '/api/notifications.php?action=mark_all_read', { method: 'POST' })
                    .then(function() { fetchNotifs(); fetchCount(); });
            });
        }

        setInterval(function() {
            fetchCount();
            if (notifDropdown.classList.contains('show')) {
                fetchNotifs();
            }
        }, 30000);
    }
})();
</script>

<style>
#logoutBackdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    z-index: 9998;
    opacity: 0;
    transition: opacity 0.25s ease;
}
#logoutModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s cubic-bezier(.4,0,.2,1);
    pointer-events: none;
}
#logoutModal .logout-dialog {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 24px;
    width: 400px;
    max-width: 90vw;
    padding: 0;
    box-shadow: 0 32px 64px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.05) inset;
    transform: scale(0.9) translateY(20px);
    transition: transform 0.4s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
    pointer-events: auto;
    position: relative;
}
#logoutModal .logout-dialog::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 140px;
    background: linear-gradient(135deg, rgba(12,110,94,0.06) 0%, rgba(12,110,94,0.02) 100%);
    pointer-events: none;
}
#logoutModal .logout-header {
    padding: 36px 32px 0;
    text-align: center;
    position: relative;
    z-index: 1;
}
#logoutModal .logout-icon-wrap {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(12,110,94,0.15), rgba(12,110,94,0.05)) !important;
    border: 2px solid rgba(12,110,94,0.15) !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    position: relative;
    animation: logoutPulse 2.5s ease-in-out infinite;
}
@keyframes logoutPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(12,110,94,0.15); }
    50% { box-shadow: 0 0 0 12px rgba(12,110,94,0); }
}
#logoutModal .logout-icon-wrap i {
    font-size: 1.6rem;
    color: #0c6e5e !important;
    transition: transform 0.3s;
}
#logoutModal .logout-dialog:hover .logout-icon-wrap i {
    transform: translateX(3px);
}
#logoutModal .logout-title {
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--text-primary, #1e293b);
    margin-bottom: 8px;
    letter-spacing: -0.3px;
}
#logoutModal .logout-msg {
    font-size: 0.88rem;
    color: var(--text-muted, #64748b);
    margin: 0;
    line-height: 1.6;
    padding: 0 8px;
}
#logoutModal .logout-body {
    padding: 24px 32px 32px;
    display: flex;
    gap: 12px;
}
#logoutModal .logout-btn {
    flex: 1;
    padding: 13px 20px;
    border-radius: 14px;
    font-weight: 600;
    font-size: 0.9rem;
    border: none;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(.4,0,.2,1);
    letter-spacing: 0.2px;
}
#logoutModal .logout-btn-cancel {
    background: var(--bg-secondary, #f1f5f9);
    color: var(--text-primary, #475569);
    border: 1.5px solid var(--border-color, #e2e8f0);
}
#logoutModal .logout-btn-cancel:hover {
    background: var(--bg-tertiary, #e2e8f0);
    border-color: var(--border-color, #cbd5e1);
    transform: translateY(-1px);
}
#logoutModal .logout-btn-confirm {
    background: linear-gradient(135deg, #0c6e5e, #0a5c4f) !important;
    color: #fff !important;
    box-shadow: 0 4px 16px rgba(12,110,94,0.3), inset 0 1px 0 rgba(255,255,255,0.1);
}
#logoutModal .logout-btn-confirm:hover {
    background: linear-gradient(135deg, #0a5c4f, #084a3f) !important;
    box-shadow: 0 8px 24px rgba(12,110,94,0.45), inset 0 1px 0 rgba(255,255,255,0.12);
    transform: translateY(-2px);
}
</style>

<div id="logoutBackdrop"></div>
<div id="logoutModal">
    <div class="logout-dialog">
        <div class="logout-header">
            <div class="logout-icon-wrap">
                <i class="fas fa-right-from-bracket"></i>
            </div>
            <div class="logout-title">Confirm Logout</div>
            <p class="logout-msg">You'll be signed out of your account and redirected to the login page.</p>
        </div>
        <div class="logout-body">
            <button class="logout-btn logout-btn-cancel" id="logoutCloseBtn">Stay</button>
            <button class="logout-btn logout-btn-confirm" id="logoutConfirmBtn">
                <i class="fas fa-right-from-bracket me-1"></i>Logout
            </button>
        </div>
    </div>
</div>
