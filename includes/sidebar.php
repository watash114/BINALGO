<?php
$sidebar_role   = $role ?? ($_SESSION['role'] ?? 'tourist');
$sidebar_active = $active_page ?? '';
$_user_data     = current_user();
$_sidebar_links = [];

$_link_defs = [
    'admin' => [
        ['label' => 'Dashboard',      'icon' => 'fa-tachometer-alt', 'url' => BASE_URL . '/admin/index.php'],
        ['label' => 'Users',          'icon' => 'fa-users',          'url' => BASE_URL . '/admin/users.php'],
        ['label' => 'Staff',          'icon' => 'fa-user-shield',    'url' => BASE_URL . '/admin/staff.php'],
        ['label' => 'Destinations',   'icon' => 'fa-map-marker-alt', 'url' => BASE_URL . '/admin/destinations.php'],
        ['label' => 'Events',         'icon' => 'fa-calendar',   'url' => BASE_URL . '/admin/events.php'],
        ['label' => 'Schedules',      'icon' => 'fa-clock',          'url' => BASE_URL . '/admin/schedules.php'],
        ['label' => 'Bookings',       'icon' => 'fa-ticket',         'url' => BASE_URL . '/admin/bookings.php'],
        ['label' => 'Feedback',       'icon' => 'fa-star',           'url' => BASE_URL . '/admin/feedback.php'],
        ['label' => 'Notifications',  'icon' => 'fa-bell',           'url' => BASE_URL . '/admin/notifications.php'],
        ['label' => 'Reports',        'icon' => 'fa-chart-bar',      'url' => BASE_URL . '/admin/reports.php'],
        ['label' => 'Activity Logs',  'icon' => 'fa-history',        'url' => BASE_URL . '/admin/activity_logs.php'],
        ['label' => 'Payments',       'icon' => 'fa-credit-card',    'url' => BASE_URL . '/admin/payments.php'],
        ['label' => 'Reviews',        'icon' => 'fa-comments',       'url' => BASE_URL . '/admin/reviews.php'],
    ],
    'staff' => [
        ['label' => 'Dashboard',           'icon' => 'fa-tachometer-alt', 'url' => BASE_URL . '/staff/index.php'],
        ['label' => 'My Profile',          'icon' => 'fa-user',           'url' => BASE_URL . '/staff/profile.php'],
        ['label' => 'Bookings',            'icon' => 'fa-ticket',         'url' => BASE_URL . '/staff/bookings.php'],
        ['label' => 'Schedules',           'icon' => 'fa-clock',          'url' => BASE_URL . '/staff/schedules.php'],
        ['label' => 'Destinations',        'icon' => 'fa-map-marker-alt', 'url' => BASE_URL . '/staff/destinations.php'],
        ['label' => 'Events',              'icon' => 'fa-calendar',   'url' => BASE_URL . '/staff/events.php'],
        ['label' => 'Feedback',            'icon' => 'fa-star',           'url' => BASE_URL . '/staff/feedback.php'],
        ['label' => 'Reports',             'icon' => 'fa-chart-bar',      'url' => BASE_URL . '/staff/reports.php'],
    ],
    'tourist' => [
        ['label' => 'Home',              'icon' => 'fa-house',          'url' => BASE_URL . '/tourist/index.php', 'section' => 'main'],
        ['label' => 'Destinations',      'icon' => 'fa-map-marked-alt', 'url' => BASE_URL . '/tourist/destinations.php', 'section' => 'main'],
        ['label' => 'Upcoming Events',   'icon' => 'fa-calendar',       'url' => BASE_URL . '/tourist/events.php', 'section' => 'main'],
        ['label' => 'My Bookings',       'icon' => 'fa-ticket',         'url' => BASE_URL . '/tourist/bookings.php', 'section' => 'main'],
        ['label' => 'Notifications',     'icon' => 'fa-bell',           'url' => BASE_URL . '/tourist/notifications.php', 'section' => 'main'],
        ['label' => 'My Feedback',       'icon' => 'fa-star',           'url' => BASE_URL . '/tourist/feedback.php', 'section' => 'communication'],
        ['label' => 'About Binalbagan',  'icon' => 'fa-circle-info',    'url' => BASE_URL . '/tourist/about.php', 'section' => 'info'],
        ['label' => 'My Profile',        'icon' => 'fa-user',           'url' => BASE_URL . '/tourist/profile.php', 'section' => 'info'],
    ],
];

$_sidebar_links = $_link_defs[$sidebar_role] ?? $_link_defs['tourist'];
$_current_url = $_SERVER['REQUEST_URI'] ?? '';

/* ── Dynamic Badge Counts ─────────────────────────────── */
$_sidebar_badges = [];
if (is_logged_in()) {
    try {
        $db = Database::getInstance()->getConnection();
        $uid = (int) ($_SESSION['user_id'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
        $stmt->execute([':uid' => $uid]);
        $_sidebar_badges['Notifications'] = (int) $stmt->fetchColumn();

        if ($sidebar_role === 'tourist') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE tourist_id = :uid AND status IN ('pending','confirmed')");
            $stmt->execute([':uid' => $uid]);
            $_sidebar_badges['My Bookings'] = (int) $stmt->fetchColumn();
        } elseif ($sidebar_role === 'staff') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN schedules s ON s.id = b.schedule_id WHERE s.guide_id = :uid AND b.status IN ('pending','confirmed')");
            $stmt->execute([':uid' => $uid]);
            $_sidebar_badges['Bookings'] = (int) $stmt->fetchColumn();
        } elseif ($sidebar_role === 'admin') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('pending','confirmed')");
            $stmt->execute();
            $_sidebar_badges['Bookings'] = (int) $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        // badges are cosmetic; fail silently
    }
}

$_profile_url = BASE_URL . '/' . $sidebar_role . '/profile.php';
?>
<style>
    /* ── Sidebar Theme Tokens ─────────────────────────────── */
    .app-sidebar {
        --sb-bg: linear-gradient(180deg, #0d1322 0%, #101b2e 55%, #131f33 100%);
        --sb-border: rgba(255, 255, 255, 0.06);
        --sb-text: #8896a8;
        --sb-text-hover: #e8eef6;
        --sb-icon: #5a6a7e;
        --sb-icon-hover: #2dd4bf;
        --sb-label: #475569;
        --sb-card-bg: rgba(255, 255, 255, 0.03);
        --sb-card-border: rgba(255, 255, 255, 0.06);
        --sb-card-hover: rgba(255, 255, 255, 0.06);
        --sb-hover-bg: rgba(255, 255, 255, 0.045);
        --sb-active-bg: rgba(20, 184, 166, 0.12);
        --sb-active-text: #ffffff;
        --sb-active-icon: #2dd4bf;
        --sb-logout-bg: rgba(239, 68, 68, 0.05);
        --sb-logout-hover: rgba(239, 68, 68, 0.12);
        --sb-badge-bg: linear-gradient(135deg, #f43f5e, #e11d48);
        --sb-badge-booking: linear-gradient(135deg, #10b981, #059669);
        --sb-tooltip-bg: #0b1220;
        --sb-online: #22c55e;
        --sb-shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
    }
    [data-theme="light"] .app-sidebar {
        --sb-bg: linear-gradient(180deg, #ffffff 0%, #f4f7fb 55%, #eef2f8 100%);
        --sb-border: rgba(15, 23, 42, 0.07);
        --sb-text: #64748b;
        --sb-text-hover: #0f172a;
        --sb-icon: #94a3b8;
        --sb-icon-hover: #0c6e5e;
        --sb-label: #94a3b8;
        --sb-card-bg: rgba(15, 23, 42, 0.03);
        --sb-card-border: rgba(15, 23, 42, 0.07);
        --sb-card-hover: rgba(15, 23, 42, 0.05);
        --sb-hover-bg: rgba(15, 23, 42, 0.045);
        --sb-active-bg: rgba(12, 110, 94, 0.10);
        --sb-active-text: #0b3f36;
        --sb-active-icon: #0c6e5e;
        --sb-logout-bg: rgba(239, 68, 68, 0.05);
        --sb-logout-hover: rgba(239, 68, 68, 0.10);
        --sb-tooltip-bg: #0b1220;
        --sb-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    }

    .app-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 260px;
        height: 100vh;
        background: var(--sb-bg);
        z-index: 1040;
        overflow-x: hidden;
        overflow-y: auto;
        transition: width 0.3s cubic-bezier(.4,0,.2,1), transform 0.3s cubic-bezier(.4,0,.2,1);
        display: flex;
        flex-direction: column;
        border-right: 1px solid var(--sb-border);
        box-shadow: var(--sb-shadow);
    }
    .app-sidebar::-webkit-scrollbar { width: 4px; }
    .app-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 4px; }

    /* ── Brand ─────────────────────────────────────────────── */
    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 20px 20px;
        text-decoration: none;
        color: var(--sb-active-text);
        font-size: 1.05rem;
        font-weight: 800;
        letter-spacing: 0.5px;
        border-bottom: 1px solid var(--sb-border);
        position: relative;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .sidebar-brand::after {
        content: '';
        position: absolute;
        bottom: -1px;
        left: 20px;
        right: 20px;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(45,212,191,0.30), transparent);
    }
    .sidebar-brand-icon {
        width: 38px;
        height: 38px;
        background: linear-gradient(135deg, #0c6e5e, #14b8a6);
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        color: #fff;
        box-shadow: 0 4px 14px rgba(12,110,94,0.35);
        flex-shrink: 0;
        position: relative;
    }
    .sidebar-brand-icon::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 11px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.25);
        pointer-events: none;
    }
    .sidebar-brand-text { transition: opacity 0.25s ease; }

    /* ── User Card ─────────────────────────────────────────── */
    .sidebar-user-wrap { position: relative; flex-shrink: 0; }
    .sidebar-user {
        width: 100%;
        margin: 14px 0 6px;
        padding: 12px 14px;
        border: 1px solid var(--sb-card-border);
        background: var(--sb-card-bg);
        border-radius: 14px;
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        text-align: left;
        color: var(--sb-text);
        transition: all 0.25s cubic-bezier(.4,0,.2,1);
        position: relative;
    }
    .sidebar-user:hover { background: var(--sb-card-hover); border-color: rgba(45,212,191,0.28); }
    .sidebar-user-avatar-wrap { position: relative; flex-shrink: 0; }
    .sidebar-user-avatar {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        object-fit: cover;
        border: 2px solid rgba(45,212,191,0.28);
        transition: border-color 0.25s ease;
    }
    .sidebar-user:hover .sidebar-user-avatar { border-color: rgba(45,212,191,0.55); }
    .sidebar-user-online {
        position: absolute;
        right: -3px;
        bottom: -3px;
        width: 11px;
        height: 11px;
        border-radius: 50%;
        background: var(--sb-online);
        border: 2px solid #0d1322;
        box-shadow: 0 0 0 2px rgba(34,197,94,0.25);
        animation: sbPulse 2.4s ease-in-out infinite;
    }
    [data-theme="light"] .sidebar-user-online { border-color: #ffffff; }
    @keyframes sbPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0.35); }
        50% { box-shadow: 0 0 0 5px rgba(34,197,94,0); }
    }
    .sidebar-user-info {
        overflow: hidden;
        flex: 1;
        min-width: 0;
        transition: opacity 0.25s ease;
    }
    .sidebar-user-name {
        color: var(--sb-text-hover);
        font-size: 0.85rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
        line-height: 1.25;
    }
    .sidebar-user-role {
        display: inline-block;
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.9px;
        padding: 2px 9px;
        border-radius: 50px;
        background: rgba(20,184,166,0.14);
        color: #2dd4bf;
        margin-top: 5px;
    }
    .sidebar-user-caret {
        font-size: 0.7rem;
        color: var(--sb-icon);
        transition: transform 0.3s cubic-bezier(.4,0,.2,1);
        flex-shrink: 0;
    }
    .sidebar-user-wrap.open .sidebar-user-caret { transform: rotate(180deg); }

    /* User quick dropdown */
    .sidebar-user-menu {
        position: absolute;
        top: calc(100% - 4px);
        left: 0;
        right: 0;
        background: var(--sb-tooltip-bg);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 12px;
        padding: 6px;
        z-index: 1060;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-6px);
        transition: all 0.25s cubic-bezier(.4,0,.2,1);
        box-shadow: 0 16px 40px rgba(0,0,0,0.45);
        margin: 0 6px;
    }
    .sidebar-user-wrap.open .sidebar-user-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    .sidebar-user-menu a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 12px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 0.8rem;
        font-weight: 500;
        color: #dbe4ef;
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    .sidebar-user-menu a i { width: 16px; text-align: center; font-size: 0.82rem; color: #64748b; transition: color 0.2s ease; }
    .sidebar-user-menu a:hover { background: rgba(255,255,255,0.07); color: #fff; }
    .sidebar-user-menu a:hover i { color: #2dd4bf; }
    .sidebar-user-menu a.sb-menu-danger { color: #fca5a5; }
    .sidebar-user-menu a.sb-menu-danger:hover { background: rgba(239,68,68,0.12); color: #fecaca; }
    .sidebar-user-menu a.sb-menu-danger:hover i { color: #f87171; }

    /* ── Nav ───────────────────────────────────────────────── */
    .sidebar-nav {
        flex: 1;
        padding: 8px 10px 12px;
        list-style: none;
        margin: 0;
    }
    .sidebar-nav-label {
        padding: 18px 16px 8px;
        font-size: 0.62rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1.6px;
        color: var(--sb-label);
        border-top: 1px solid var(--sb-border);
        margin-top: 4px;
        white-space: nowrap;
        transition: opacity 0.2s ease;
    }
    .sidebar-nav-label:first-child { border-top: none; margin-top: 0; }
    .sidebar-nav li { margin-bottom: 2px; }
    .sidebar-nav li a {
        display: flex;
        align-items: center;
        gap: 13px;
        padding: 10px 13px;
        color: var(--sb-text);
        text-decoration: none;
        font-size: 0.84rem;
        font-weight: 500;
        border-radius: 10px;
        transition: background 0.22s cubic-bezier(.4,0,.2,1), color 0.22s ease, transform 0.22s ease;
        position: relative;
        margin: 0 3px;
        white-space: nowrap;
    }
    .sidebar-nav li a i {
        width: 20px;
        text-align: center;
        font-size: 0.9rem;
        color: var(--sb-icon);
        transition: color 0.25s ease, transform 0.25s cubic-bezier(.4,0,.2,1);
        flex-shrink: 0;
    }
    .sidebar-nav li a .nav-text { transition: opacity 0.25s ease; }
    .sidebar-nav li a:hover {
        background: var(--sb-hover-bg);
        color: var(--sb-text-hover);
        transform: translateX(2px);
    }
    .sidebar-nav li a:hover i { color: var(--sb-icon-hover); transform: translateX(1px); }
    .sidebar-nav li a.active-page {
        background: var(--sb-active-bg);
        color: var(--sb-active-text);
        font-weight: 600;
        box-shadow: inset 0 0 0 1px rgba(45,212,191,0.10), 0 4px 14px rgba(20,184,166,0.10);
        animation: sbActiveIn 0.4s cubic-bezier(.4,0,.2,1);
    }
    @keyframes sbActiveIn {
        from { transform: translateX(-8px); opacity: 0.35; }
        to   { transform: translateX(0);     opacity: 1; }
    }
    .sidebar-nav li a.active-page::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 60%;
        background: linear-gradient(180deg, #2dd4bf, #0c6e5e);
        border-radius: 0 4px 4px 0;
        box-shadow: 0 0 12px rgba(45,212,191,0.6);
        animation: sbBarIn 0.4s cubic-bezier(.4,0,.2,1);
    }
    @keyframes sbBarIn {
        from { height: 8px; opacity: 0; }
        to   { height: 60%; opacity: 1; }
    }
    .sidebar-nav li a.active-page i { color: var(--sb-active-icon); }

    /* Badges */
    .sidebar-badge {
        margin-left: auto;
        min-width: 20px;
        height: 20px;
        padding: 0 7px;
        border-radius: 50px;
        background: var(--sb-badge-bg);
        color: #fff;
        font-size: 0.64rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        box-shadow: 0 2px 6px rgba(225,29,72,0.35);
        flex-shrink: 0;
        animation: sbBadgeIn 0.45s cubic-bezier(.4,0,.2,1);
    }
    .sidebar-badge.booking {
        background: var(--sb-badge-booking);
        box-shadow: 0 2px 6px rgba(5,150,105,0.35);
    }
    @keyframes sbBadgeIn {
        from { transform: scale(0.4); opacity: 0; }
        to   { transform: scale(1);   opacity: 1; }
    }

    /* ── Logout ────────────────────────────────────────────── */
    .sidebar-logout {
        margin: 6px 14px 16px;
        padding: 9px 13px;
        border-radius: 10px;
        border: 1px solid var(--sb-border);
        background: var(--sb-logout-bg);
        transition: all 0.22s cubic-bezier(.4,0,.2,1);
        flex-shrink: 0;
        white-space: nowrap;
    }
    .sidebar-logout:hover {
        background: var(--sb-logout-hover);
        border-color: rgba(239,68,68,0.22);
        transform: translateX(2px);
    }
    .sidebar-logout a { color: var(--sb-text); }
    .sidebar-logout:hover a,
    .sidebar-logout:hover i,
    .sidebar-logout:hover span { color: #f87171; }
    .sidebar-logout i,
    .sidebar-logout span {
        color: var(--sb-text);
        font-size: 0.84rem;
        transition: color 0.22s ease;
    }
    .sidebar-logout i { width: 20px; text-align: center; font-size: 0.9rem; }

    /* ── Overlay (mobile) ──────────────────────────────────── */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(2,6,23,0.55);
        backdrop-filter: blur(3px);
        z-index: 1035;
    }
    .sidebar-overlay.show { display: block; }

    /* ── Tooltips (collapsed) ──────────────────────────────── */
    body.sidebar-collapsed .app-sidebar a[data-tip] { position: relative; }
    body.sidebar-collapsed .app-sidebar a[data-tip]:hover::after {
        content: attr(data-tip);
        position: absolute;
        left: calc(100% + 14px);
        top: 50%;
        transform: translateY(-50%);
        background: var(--sb-tooltip-bg);
        color: #e2e8f0;
        font-size: 0.72rem;
        font-weight: 500;
        padding: 7px 11px;
        border-radius: 8px;
        white-space: nowrap;
        z-index: 1100;
        box-shadow: 0 8px 24px rgba(0,0,0,0.45);
        pointer-events: none;
        border: 1px solid rgba(255,255,255,0.08);
    }
    body.sidebar-collapsed .app-sidebar a[data-tip]:hover::before {
        content: '';
        position: absolute;
        left: calc(100% + 9px);
        top: 50%;
        transform: translateY(-50%);
        border: 5px solid transparent;
        border-right-color: var(--sb-tooltip-bg);
        z-index: 1101;
    }

    /* ── Collapsed (icon-only) mode ────────────────────────── */
    @media (min-width: 992px) {
        body.sidebar-collapsed { --sidebar-width: 80px; }
        body.sidebar-collapsed .app-sidebar { width: 80px; }
        body.sidebar-collapsed .app-sidebar .sidebar-brand { padding: 20px 21px; justify-content: center; }
        body.sidebar-collapsed .app-sidebar .sidebar-brand-text { opacity: 0; width: 0; overflow: hidden; }
        body.sidebar-collapsed .app-sidebar .sidebar-user { justify-content: center; padding: 12px 10px; margin: 14px 8px 6px; }
        body.sidebar-collapsed .app-sidebar .sidebar-user-info,
        body.sidebar-collapsed .app-sidebar .sidebar-user-caret { opacity: 0; width: 0; overflow: hidden; }
        body.sidebar-collapsed .app-sidebar .sidebar-nav { padding: 8px 8px 12px; }
        body.sidebar-collapsed .app-sidebar .sidebar-nav-label {
            font-size: 0; padding: 16px 14px 8px;
        }
        body.sidebar-collapsed .app-sidebar .sidebar-nav-label::after {
            content: '';
            display: block;
            width: 24px;
            height: 1px;
            background: var(--sb-border);
            margin: 0 auto;
        }
        body.sidebar-collapsed .app-sidebar .sidebar-nav li a { justify-content: center; padding: 11px 0; margin: 0 6px; }
        body.sidebar-collapsed .app-sidebar .sidebar-nav li a .nav-text,
        body.sidebar-collapsed .app-sidebar .sidebar-nav li a .sidebar-badge { opacity: 0; width: 0; overflow: hidden; padding: 0; margin: 0; min-width: 0; height: 0; }
        body.sidebar-collapsed .app-sidebar .sidebar-logout { margin: 6px 8px 16px; display: flex; justify-content: center; }
        body.sidebar-collapsed .app-sidebar .sidebar-logout span { opacity: 0; width: 0; overflow: hidden; }
        body.sidebar-collapsed .app-sidebar .sidebar-logout a { gap: 0; }
        body.sidebar-collapsed .sidebar-user-menu { left: 74px; right: auto; min-width: 180px; margin: 0; top: 8px; }
        body.sidebar-collapsed .sidebar-user-menu a { white-space: nowrap; }
    }

    /* ── Mobile drawer ─────────────────────────────────────── */
    @media (max-width: 991.98px) {
        .app-sidebar {
            transform: translateX(-100%);
            box-shadow: none;
        }
        .app-sidebar.show {
            transform: translateX(0);
            box-shadow: 4px 0 24px rgba(0,0,0,0.35);
        }
    }
</style>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="app-sidebar" id="appSidebar">
    <a href="<?= $_sidebar_links[0]['url'] ?>" class="sidebar-brand" data-tip="BINALGO">
        <div class="sidebar-brand-icon">
            <img src="<?= BASE_URL ?>/assets/images/binalgo-logo.svg" alt="BINALGO" style="width:32px;height:32px;border-radius:6px;">
        </div>
        <span class="sidebar-brand-text">BINALGO</span>
    </a>

    <?php if ($_user_data): ?>
    <div class="sidebar-user-wrap" id="sidebarUserWrap">
        <button type="button" class="sidebar-user" id="sidebarUserBtn" data-tip="<?= sanitize($_user_data['name']) ?>" aria-haspopup="true" aria-expanded="false">
            <span class="sidebar-user-avatar-wrap">
                <img src="<?= get_avatar_url($_user_data) ?>" alt="<?= sanitize($_user_data['name']) ?>" class="sidebar-user-avatar">
                <span class="sidebar-user-online"></span>
            </span>
            <span class="sidebar-user-info">
                <span class="sidebar-user-name"><?= sanitize($_user_data['name']) ?></span>
                <span class="sidebar-user-role"><?= sanitize(ucfirst($_user_data['role'])) ?></span>
            </span>
            <i class="fas fa-chevron-down sidebar-user-caret"></i>
        </button>
        <div class="sidebar-user-menu" id="sidebarUserMenu">
            <a href="<?= $_profile_url ?>"><i class="fas fa-user"></i>View Profile</a>
            <a href="javascript:void(0)" onclick="confirmLogout('<?= BASE_URL ?>/auth/logout.php')" class="sb-menu-danger"><i class="fas fa-right-from-bracket"></i>Logout</a>
        </div>
    </div>
    <?php endif; ?>

    <ul class="sidebar-nav">
        <?php
        $section_labels = [
            ''             => '',
            'main'         => '',
            'communication'=> 'More',
            'info'         => '',
        ];
        $prev_section = '';
        foreach ($_sidebar_links as $link):
            $section = $link['section'] ?? 'main';
            $is_active = (strpos($_current_url, $link['url']) !== false) || ($sidebar_active === basename($link['url'], '.php'));

            if ($section !== $prev_section && !empty($section_labels[$section])):
        ?>
            <li class="sidebar-nav-label"><?= $section_labels[$section] ?></li>
        <?php
                $prev_section = $section;
            endif;

            $badge = $_sidebar_badges[$link['label']] ?? 0;
            $badge_class = ($link['label'] === 'Notifications') ? '' : 'booking';
        ?>
            <li>
                <a href="<?= $link['url'] ?>" class="<?= $is_active ? 'active-page' : '' ?>" data-tip="<?= $link['label'] ?>">
                    <i class="fas <?= $link['icon'] ?>"></i>
                    <span class="nav-text"><?= $link['label'] ?></span>
                    <?php if ($badge > 0): ?>
                        <span class="sidebar-badge <?= $badge_class ?>"><?= $badge > 99 ? '99+' : $badge ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="sidebar-logout">
        <a href="javascript:void(0)" onclick="confirmLogout('<?= BASE_URL ?>/auth/logout.php')" class="d-flex align-items-center gap-2 text-decoration-none" data-tip="Logout">
            <i class="fas fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('appSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggle  = document.getElementById('sidebarToggle');
    var collapseBtn = document.getElementById('sidebarCollapseToggle');

    // ── Mobile drawer ───────────────────────────────────────
    if (toggle) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // ── Collapsible icon-only mode (desktop) ────────────────
    var mqDesktop = window.matchMedia('(min-width: 992px)');
    var storedCollapsed = localStorage.getItem('binalgo.sidebarCollapsed') === '1';
    if (storedCollapsed && mqDesktop.matches) {
        document.body.classList.add('sidebar-collapsed');
        updateCollapseIcon();
    }

    function updateCollapseIcon() {
        if (!collapseBtn) return;
        var isCollapsed = document.body.classList.contains('sidebar-collapsed');
        collapseBtn.innerHTML = isCollapsed ? '<i class="fas fa-angles-right"></i>' : '<i class="fas fa-angles-left"></i>';
        collapseBtn.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }

    function applyCollapsed(collapsed) {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        localStorage.setItem('binalgo.sidebarCollapsed', collapsed ? '1' : '0');
        updateCollapseIcon();
    }

    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            applyCollapsed(!document.body.classList.contains('sidebar-collapsed'));
        });
    }

    mqDesktop.addEventListener('change', function(e) {
        if (!e.matches) {
            document.body.classList.remove('sidebar-collapsed');
            var sb = document.getElementById('appSidebar');
            if (sb) sb.classList.remove('show');
            var ov = document.getElementById('sidebarOverlay');
            if (ov) ov.classList.remove('show');
        }
    });

    // ── User card dropdown ──────────────────────────────────
    var userBtn = document.getElementById('sidebarUserBtn');
    var userWrap = document.getElementById('sidebarUserWrap');
    var userMenu = document.getElementById('sidebarUserMenu');

    function closeUserMenu() {
        if (userWrap) userWrap.classList.remove('open');
        if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
    }

    if (userBtn && userWrap) {
        userBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var isOpen = userWrap.classList.contains('open');
            closeUserMenu();
            if (!isOpen) {
                userWrap.classList.add('open');
                userBtn.setAttribute('aria-expanded', 'true');
            }
        });
        document.addEventListener('click', function(e) {
            if (!userWrap.contains(e.target)) closeUserMenu();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeUserMenu();
        });
    }
});
</script>
