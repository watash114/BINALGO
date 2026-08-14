<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');

$db = Database::getInstance()->getConnection();

$totalBookings = (int) $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$totalRevenue = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE payment_status = 'paid'")->fetchColumn();
$activeTours = (int) $db->query("SELECT COUNT(*) FROM schedules WHERE status IN ('scheduled', 'in_progress')")->fetchColumn();
$completedTours = (int) $db->query("SELECT COUNT(*) FROM schedules WHERE status = 'completed'")->fetchColumn();
$totalUsers = (int) $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$totalEvents = (int) $db->query("SELECT COUNT(*) FROM events WHERE status = 'published'")->fetchColumn();
$avgRating = (float) $db->query("SELECT COALESCE(AVG(overall_rating), 0) FROM feedback")->fetchColumn();
$pendingVerifications = (int) $db->query("SELECT COUNT(*) FROM id_verifications WHERE status = 'pending'")->fetchColumn();
$totalRevenuePaid = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE payment_status = 'paid'")->fetchColumn();
$monthlyRevenue = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE payment_status = 'paid' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

$bookingsByMonth = $db->query(
    "SELECT DATE_FORMAT(b.created_at, '%Y-%m') as month, COUNT(*) as count,
            SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_price ELSE 0 END) as revenue
     FROM bookings b
     WHERE b.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
     ORDER BY month ASC"
)->fetchAll();

$revenueByMonth = $db->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
            SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as revenue,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as count
     FROM payments
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY month ASC"
)->fetchAll();

$popularDestinations = $db->query(
    "SELECT d.name, COUNT(b.id) as booking_count
     FROM destinations d
     LEFT JOIN events e ON e.destination_id = d.id
     LEFT JOIN schedules s ON s.event_id = e.id
     LEFT JOIN bookings b ON b.schedule_id = s.id AND b.status IN ('confirmed', 'completed')
     GROUP BY d.id, d.name
     ORDER BY booking_count DESC
     LIMIT 8"
)->fetchAll();

$usersByMonth = $db->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
     FROM users
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY month ASC"
)->fetchAll();

$bookingByStatus = $db->query(
    "SELECT status, COUNT(*) as cnt FROM bookings GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$paymentByMethod = $db->query(
    "SELECT payment_method, COUNT(*) as cnt, SUM(total_amount) as total
     FROM payments WHERE payment_status = 'paid'
     GROUP BY payment_method"
)->fetchAll();

$topEvents = $db->query(
    "SELECT e.title, COUNT(b.id) as booking_count, SUM(b.total_price) as revenue
     FROM events e
     JOIN schedules s ON s.event_id = e.id
     JOIN bookings b ON b.schedule_id = s.id AND b.status IN ('confirmed','completed')
     GROUP BY e.id, e.title
     ORDER BY booking_count DESC
     LIMIT 5"
)->fetchAll();

$recentFeedback = $db->query(
    "SELECT f.overall_rating, f.comment, u.name as tourist_name, e.title as event_title
     FROM feedback f
     JOIN users u ON f.tourist_id = u.id
     LEFT JOIN bookings b ON f.booking_id = b.id
     LEFT JOIN schedules s ON b.schedule_id = s.id
     LEFT JOIN events e ON s.event_id = e.id
     ORDER BY f.created_at DESC
     LIMIT 5"
)->fetchAll();

$recentBookings = $db->query(
    "SELECT b.*, u.name as tourist_name, e.title as event_title, d.name as destination_name, s.start_date
     FROM bookings b
     LEFT JOIN users u ON b.tourist_id = u.id
     LEFT JOIN schedules s ON b.schedule_id = s.id
     LEFT JOIN events e ON s.event_id = e.id
     LEFT JOIN destinations d ON e.destination_id = d.id
     ORDER BY b.created_at DESC LIMIT 10"
)->fetchAll();

$allBookings = $db->query(
    "SELECT b.id, b.booking_reference, u.name as tourist_name, e.title as event_title,
            d.name as destination_name, s.start_date, b.num_participants, b.total_price, b.status, b.created_at
     FROM bookings b
     LEFT JOIN users u ON b.tourist_id = u.id
     LEFT JOIN schedules s ON b.schedule_id = s.id
     LEFT JOIN events e ON s.event_id = e.id
     LEFT JOIN destinations d ON e.destination_id = d.id
     ORDER BY b.created_at DESC"
)->fetchAll();

$allPayments = $db->query(
    "SELECT p.*, u.name as tourist_name
     FROM payments p
     LEFT JOIN users u ON p.tourist_id = u.id
     ORDER BY p.created_at DESC"
)->fetchAll();

render_page('admin', 'reports.php', 'Reports & Analytics', function () use (
    $totalBookings, $totalRevenue, $totalRevenuePaid, $activeTours, $completedTours, $totalUsers,
    $totalEvents, $avgRating, $pendingVerifications, $monthlyRevenue,
    $bookingsByMonth, $revenueByMonth, $popularDestinations,
    $usersByMonth, $bookingByStatus, $paymentByMethod, $topEvents, $recentFeedback,
    $recentBookings, $allBookings, $allPayments
) {
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
.report-stat {
    border: none; border-radius: 16px; transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    position: relative; overflow: hidden; background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #f1f5f9);
}
.report-stat:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.08); }
.report-stat .card-body { padding: 22px; }
.report-stat .stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; transition: transform 0.3s;
}
.report-stat:hover .stat-icon { transform: scale(1.1); }
.report-stat .stat-value { font-size: 1.65rem; font-weight: 800; line-height: 1.2; letter-spacing: -0.5px; }
.report-stat .stat-label { font-size: 0.78rem; color: var(--text-muted, #64748b); font-weight: 500; margin-top: 2px; }
.report-stat .accent-bar { position: absolute; top: 0; left: 0; right: 0; height: 3px; }

.dash-section-title {
    font-size: 0.85rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.8px; color: var(--text-muted, #64748b);
    margin-bottom: 14px; padding-left: 4px;
}
.chart-card {
    border: none; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    border: 1px solid var(--border-color, #f1f5f9); background: var(--card-bg, #fff);
}
.chart-card .card-header {
    background: transparent; border-bottom: 1px solid var(--border-color, #f1f5f9);
    padding: 16px 20px; color: var(--text-primary, #1e293b);
}
.chart-card canvas { max-height: 280px; }

.greeting-banner {
    background: transparent; border-radius: 14px; padding: 0; color: inherit;
    position: relative; overflow: hidden; margin-bottom: 0; border: none;
}
.greeting-banner::before, .greeting-banner::after { display: none; }
.greeting-banner h4 { font-weight: 800; font-size: 1.15rem; margin-bottom: 0; }
.greeting-banner p { opacity: 0.7; font-size: 0.82rem; margin: 0; }
.greeting-banner .btn { position: relative; z-index: 1; }

.export-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; font-size: 0.8rem;
    font-weight: 600; border: 1.5px solid var(--border-color, #e2e8f0); cursor: pointer;
    transition: all 0.2s; text-decoration: none; position: relative; z-index: 2;
    background: var(--card-bg, #fff); color: var(--text-primary, #1e293b);
}
.export-btn:hover { background: rgba(12,110,94,0.06); border-color: #0c6e5e; color: #0c6e5e; transform: translateY(-1px); }
.export-btn i { font-size: 0.85rem; }
.export-btn.pdf i { color: #ef4444; }
.export-btn.pdf:hover { border-color: #ef4444; color: #ef4444; background: rgba(239,68,68,0.06); }
.export-btn.excel i { color: #10b981; }
.export-btn.excel:hover { border-color: #10b981; color: #10b981; background: rgba(16,185,129,0.06); }
.export-btn.print i { color: #0c6e5e; }
.export-btn.print:hover { border-color: #0c6e5e; color: #0c6e5e; background: rgba(12,110,94,0.06); }

.page-header-card {
    background: var(--card-bg, #fff); border-radius: 14px;
    border: 1px solid var(--border-color, #f1f5f9);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-bottom: 20px;
}
.filter-bar {
    background: var(--card-bg, #fff); border-radius: 14px;
    border: 1px solid var(--border-color, #f1f5f9);
    box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-bottom: 20px; padding: 14px 20px;
}
.filter-bar select, .filter-bar input[type="date"] {
    border: 1.5px solid var(--border-color, #e2e8f0); border-radius: 8px;
    padding: 8px 12px; font-size: 0.82rem; font-weight: 500;
    background: var(--card-bg, #fff); color: var(--text-primary, #1e293b);
    transition: border-color 0.2s;
}
.filter-bar select:focus, .filter-bar input[type="date"]:focus {
    border-color: #0c6e5e; box-shadow: 0 0 0 3px rgba(12,110,94,0.1);
    outline: none;
}

/* Segmented Pill Filter */
.pill-filter {
    display: inline-flex; gap: 0; background: var(--bg-secondary, #f1f5f9);
    border-radius: 12px; padding: 4px; border: 1px solid var(--border-color, #e2e8f0);
}
.pill-filter .pill-btn {
    padding: 8px 18px; border-radius: 8px; border: none; cursor: pointer;
    font-size: 0.8rem; font-weight: 600; transition: all 0.25s cubic-bezier(0.4,0,0.2,1);
    background: transparent; color: var(--text-muted, #64748b);
    display: inline-flex; align-items: center; gap: 6px;
}
.pill-filter .pill-btn:hover {
    color: var(--text-primary, #1e293b); background: rgba(255,255,255,0.5);
}
.pill-filter .pill-btn.active {
    background: var(--card-bg, #fff); color: #0c6e5e;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.pill-filter .pill-btn i { font-size: 0.72rem; }

/* Export Button Loading */
.export-btn.loading {
    pointer-events: none; opacity: 0.7;
}
.export-btn.loading i {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Report Stat Change Indicator */
.report-stat .stat-change {
    font-size: 0.7rem; font-weight: 600; display: inline-flex;
    align-items: center; gap: 3px; padding: 2px 7px;
    border-radius: 50px; margin-top: 4px;
}
.report-stat .stat-change.up { background: rgba(16,185,129,0.1); color: #10b981; }
.report-stat .stat-change.down { background: rgba(239,68,68,0.1); color: #ef4444; }
.report-stat .stat-change.neutral { background: rgba(100,116,139,0.1); color: #64748b; }

@media print {
    .app-sidebar, .sidebar-overlay, #sidebarToggle,
    .topbar, .topbar-wrapper, #appSidebar,
    .notification-wrapper, .notification-dropdown,
    .export-btn, .greeting-banner .d-flex:last-child,
    .btn, form { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 0 !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd !important; }
    body { background: #fff !important; color: #000 !important; }
    .stat-card, .report-stat, .chart-card { background: #fff !important; border-color: #ddd !important; }
    .page-header-card { background: #fff !important; border-color: #ddd !important; }
    .accent-bar { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    canvas { max-width: 100% !important; }
}

.top-list-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 0; border-bottom: 1px solid var(--border-color, #f1f5f9);
}
.top-list-item:last-child { border-bottom: none; }
.top-list-item .rank {
    width: 30px; height: 30px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
}

.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted,#94a3b8)}.empty-state .empty-icon{width:56px;height:56px;border-radius:14px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}.empty-state h6{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b);margin-bottom:4px}.empty-state p{font-size:.82rem;margin:0}

.export-dropdown { position: relative; display: inline-block; }
.export-dropdown .dropdown-menu {
    min-width: 200px; border-radius: 12px; border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 10px 40px rgba(0,0,0,0.12); padding: 8px;
}
.export-dropdown .dropdown-item {
    border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; font-weight: 500;
    display: flex; align-items: center; gap: 10px;
}
.export-dropdown .dropdown-item:hover { background: rgba(12,110,94,0.08); color: #0c6e5e; }
.export-dropdown .dropdown-item i { width: 20px; text-align: center; }
</style>

<?php
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$dashDate = date('l, F j, Y');
?>

<div class="page-header-card p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="mb-1" style="font-weight:800;color:var(--text-primary,#1e293b);">
                <i class="fas fa-chart-line me-2" style="color:#0c6e5e;"></i>Reports & Analytics
            </h4>
            <p class="mb-0" style="font-size:0.82rem;color:var(--text-muted,#64748b);">
                <?= $dashDate ?> &mdash; Comprehensive insights for BINALGO Tourism
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" onclick="exportPDF(this)" class="export-btn pdf" id="btnPDF">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
            <button type="button" onclick="exportExcel(this)" class="export-btn excel" id="btnExcel">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
            <button type="button" onclick="window.print()" class="export-btn print" id="btnPrint">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<div class="filter-bar d-flex align-items-center flex-wrap gap-3">
    <div class="d-flex align-items-center gap-2">
        <label class="fw-semibold mb-0" style="font-size:0.8rem;color:var(--text-muted,#64748b);">Period:</label>
        <div class="pill-filter" id="periodPills">
            <button type="button" class="pill-btn" data-period="today" onclick="selectPeriod(this)"><i class="fas fa-calendar-day"></i> Today</button>
            <button type="button" class="pill-btn" data-period="7d" onclick="selectPeriod(this)"><i class="fas fa-calendar-week"></i> 7D</button>
            <button type="button" class="pill-btn active" data-period="month" onclick="selectPeriod(this)"><i class="fas fa-calendar"></i> 30D</button>
            <button type="button" class="pill-btn" data-period="year" onclick="selectPeriod(this)"><i class="fas fa-calendar-days"></i> YTD</button>
            <button type="button" class="pill-btn" data-period="custom" onclick="selectPeriod(this)"><i class="fas fa-sliders"></i> Custom</button>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 custom-dates" id="customDates" style="display:none !important;">
        <label class="fw-semibold mb-0" style="font-size:0.8rem;color:var(--text-muted,#64748b);">From:</label>
        <input type="date" class="form-control form-control-sm" style="width:auto;" id="dateFrom">
        <label class="fw-semibold mb-0" style="font-size:0.8rem;color:var(--text-muted,#64748b);">To:</label>
        <input type="date" class="form-control form-control-sm" style="width:auto;" id="dateTo">
    </div>
    <div class="ms-auto d-flex align-items-center gap-2">
        <button class="btn btn-sm" style="border:1.5px solid var(--border-color,#e2e8f0);border-radius:8px;font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);" onclick="resetFilters()">
            <i class="fas fa-rotate-right me-1"></i> Reset
        </button>
    </div>
</div>

<!-- Primary KPIs -->
<div class="dash-section-title">Key Metrics</div>
<div class="row g-3 mb-4">
    <?php
    $kpiCards = [
        ['label' => 'Total Bookings', 'value' => $totalBookings, 'icon' => 'fa-ticket', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.1)', 'gradient' => 'linear-gradient(135deg,#3b82f6,#60a5fa)', 'change' => '+0%', 'changeDir' => 'up'],
        ['label' => 'Total Revenue', 'value' => '₱' . number_format($totalRevenuePaid), 'icon' => 'fa-dollar-sign', 'color' => '#0c6e5e', 'bg' => 'rgba(12,110,94,0.1)', 'gradient' => 'linear-gradient(135deg,#0c6e5e,#14b8a6)', 'change' => '+0%', 'changeDir' => 'up'],
        ['label' => 'Active Users', 'value' => $totalUsers, 'icon' => 'fa-users', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.1)', 'gradient' => 'linear-gradient(135deg,#f59e0b,#fbbf24)', 'change' => '+0%', 'changeDir' => 'up'],
        ['label' => 'Avg Rating', 'value' => number_format($avgRating, 1), 'icon' => 'fa-star', 'color' => '#8b5cf6', 'bg' => 'rgba(139,92,246,0.1)', 'gradient' => 'linear-gradient(135deg,#8b5cf6,#a78bfa)', 'change' => '—', 'changeDir' => 'neutral'],
    ];
    foreach ($kpiCards as $kpi): ?>
    <div class="col-6 col-lg-3">
        <div class="report-stat card shadow-sm">
            <div class="accent-bar" style="background:<?= $kpi['gradient'] ?>;"></div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value" style="color:<?= $kpi['color'] ?>;"><?= $kpi['value'] ?></div>
                        <div class="stat-label"><?= $kpi['label'] ?></div>
                        <?php if (!empty($kpi['change'])): ?>
                            <span class="stat-change <?= $kpi['changeDir'] ?? 'up' ?>">
                                <i class="fas fa-<?= ($kpi['changeDir'] ?? 'up') === 'up' ? 'arrow-up' : (($kpi['changeDir'] ?? '') === 'down' ? 'arrow-down' : 'minus') ?>"></i>
                                <?= $kpi['change'] ?> vs last month
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-icon" style="background:<?= $kpi['bg'] ?>;color:<?= $kpi['color'] ?>;">
                        <i class="fas <?= $kpi['icon'] ?>"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Secondary KPIs -->
<div class="row g-3 mb-4">
    <?php
    $secKpis = [
        ['label' => 'Active Tours', 'value' => $activeTours, 'icon' => 'fa-route', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.1)'],
        ['label' => 'Completed Tours', 'value' => $completedTours, 'icon' => 'fa-flag-checkered', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.1)'],
        ['label' => 'Published Events', 'value' => $totalEvents, 'icon' => 'fa-calendar', 'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.1)'],
    ];
    foreach ($secKpis as $kpi): ?>
    <div class="col-6 col-lg-3">
        <div class="report-stat card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value" style="font-size:1.35rem;color:<?= $kpi['color'] ?>;"><?= $kpi['value'] ?></div>
                        <div class="stat-label"><?= $kpi['label'] ?></div>
                    </div>
                    <div class="stat-icon" style="background:<?= $kpi['bg'] ?>;color:<?= $kpi['color'] ?>;">
                        <i class="fas <?= $kpi['icon'] ?>"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row 1 -->
<div class="dash-section-title">Trends & Distribution</div>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-line me-2" style="color:#0c6e5e;"></i>Revenue Trend</h6>
                <span class="badge" style="background:rgba(12,110,94,0.1);color:#0c6e5e;font-weight:600;">Last 6 Months</span>
            </div>
            <div class="card-body">
                <?php if (empty($revenueByMonth)): ?>
                    <div class="empty-state"><div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-chart-line"></i></div><h6>No Revenue Data</h6><p>No revenue recorded for this period. Try adjusting your date filter.</p></div>
                <?php else: ?>
                    <canvas id="revenueChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-pie me-2" style="color:#8b5cf6;"></i>Booking Status</h6>
            </div>
            <div class="card-body">
                <?php if (empty($bookingByStatus)): ?>
                    <div class="empty-state"><div class="empty-icon" style="background:rgba(139,92,246,0.1);color:#8b5cf6;"><i class="fas fa-chart-pie"></i></div><h6>No Status Data</h6><p>No bookings found. Bookings will appear here once created.</p></div>
                <?php else: ?>
                    <canvas id="bookingStatusChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2" style="color:#0c6e5e;"></i>Bookings by Month</h6>
                <span class="badge" style="background:rgba(12,110,94,0.1);color:#0c6e5e;font-weight:600;">Last 6 Months</span>
            </div>
            <div class="card-body">
                <?php if (empty($bookingsByMonth)): ?>
                    <div class="empty-state"><div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-calendar"></i></div><h6>No Booking Data</h6><p>No bookings for this period. Try expanding your date range.</p></div>
                <?php else: ?>
                    <canvas id="bookingTrendChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-user-plus me-2" style="color:#10b981;"></i>User Registrations</h6>
                <span class="badge" style="background:rgba(16,185,129,0.1);color:#10b981;font-weight:600;">Last 6 Months</span>
            </div>
            <div class="card-body">
                <?php if (empty($usersByMonth)): ?>
                    <div class="empty-state"><div class="empty-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fas fa-users"></i></div><h6>No User Data</h6><p>No registrations found for this period.</p></div>
                <?php else: ?>
                    <canvas id="userRegChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 3 -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-credit-card me-2" style="color:#3b82f6;"></i>Payment Methods</h6>
            </div>
            <div class="card-body">
                <?php if (empty($paymentByMethod)): ?>
                    <div class="empty-state"><div class="empty-icon" style="background:rgba(59,130,246,0.1);color:#3b82f6;"><i class="fas fa-credit-card"></i></div><h6>No Payment Data</h6><p>No payments recorded yet. Payments will appear after bookings.</p></div>
                <?php else: ?>
                    <canvas id="paymentMethodChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-calendar-star me-2" style="color:#f59e0b;"></i>Top Events</h6>
            </div>
            <div class="card-body">
                <?php if (empty($topEvents)): ?>
                    <div class="empty-state"><div class="empty-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-calendar-check"></i></div><h6>No Event Data</h6><p>No events with bookings yet. Create events to see analytics.</p></div>
                <?php else: ?>
                    <canvas id="topEventsChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Data Tables -->
<div class="dash-section-title">Rankings & Insights</div>
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold"><i class="fas fa-map-marker-alt me-2" style="color:#0c6e5e;"></i>Popular Destinations</h6>
            </div>
            <div class="card-body">
                <?php if (empty($popularDestinations)): ?>
                    <div class="empty-state"><div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-map-marked-alt"></i></div><h6>No Destination Data</h6><p>Destination popularity will appear as bookings come in.</p></div>
                <?php else: ?>
                    <?php foreach ($popularDestinations as $i => $pd):
                        $barWidth = $popularDestinations[0]['booking_count'] > 0 ? ($pd['booking_count'] / $popularDestinations[0]['booking_count']) * 100 : 0;
                        $colors = [['#3b82f6','#dbeafe'],['#10b981','#d1fae5'],['#f59e0b','#fef3c7'],['#ef4444','#fee2e2'],['#8b5cf6','#ede9fe'],['#06b6d4','#cffafe'],['#ec4899','#fce7f3'],['#64748b','#f1f5f9']];
                    ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold small"><?= $i+1 ?>. <?= sanitize($pd['name']) ?></span>
                                <span class="fw-bold small" style="color:<?= $colors[$i][0] ?>;"><?= $pd['booking_count'] ?></span>
                            </div>
                            <div class="progress" style="height:6px;border-radius:6px;">
                                <div class="progress-bar" style="width:<?= $barWidth ?>%;background:<?= $colors[$i][0] ?>;border-radius:6px;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Feedback -->
<div class="card chart-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-comments me-2" style="color:#0c6e5e;"></i>Recent Feedback</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentFeedback)): ?>
            <div class="empty-state"><div class="empty-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-comments"></i></div><h6>No Feedback Data</h6><p>Reviews from tourists will show up here after completed tours.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr style="background:var(--border-color,#f8fafc);">
                            <th class="ps-3" style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Tourist</th>
                            <th style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Event</th>
                            <th style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Rating</th>
                            <th style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted,#64748b);">Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentFeedback as $fb): ?>
                            <tr>
                                <td class="ps-3 fw-semibold small"><?= sanitize($fb['tourist_name']) ?></td>
                                <td class="small"><?= sanitize($fb['event_title'] ?? 'N/A') ?></td>
                                <td>
                                    <?php for ($s=1; $s<=5; $s++): ?>
                                        <i class="fas fa-star" style="font-size:.65rem;color:<?= $s <= round($fb['overall_rating']) ? '#f59e0b' : '#e2e8f0' ?>"></i>
                                    <?php endfor; ?>
                                </td>
                                <td class="small text-muted" style="max-width:300px;"><?= sanitize(truncate($fb['comment'] ?? '', 80)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden data for exports -->
<script>
const exportData = {
    bookings: <?= json_encode($allBookings) ?>,
    payments: <?= json_encode($allPayments) ?>,
    summary: {
        totalBookings: <?= $totalBookings ?>,
        totalRevenue: <?= $totalRevenuePaid ?>,
        monthlyRevenue: <?= $monthlyRevenue ?>,
        activeUsers: <?= $totalUsers ?>,
        avgRating: <?= number_format($avgRating, 2) ?>,
        activeTours: <?= $activeTours ?>,
        completedTours: <?= $completedTours ?>,
        publishedEvents: <?= $totalEvents ?>,
    }
};
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const teal = '#0c6e5e';
    const blue = '#3b82f6';
    const green = '#10b981';
    const amber = '#f59e0b';
    const red = '#ef4444';
    const purple = '#8b5cf6';

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    Chart.defaults.color = textColor;
    Chart.defaults.font.family = "'Inter', sans-serif";

    const revData = <?= json_encode($revenueByMonth) ?>;
    if (revData.length > 0) {
        const ctx = document.getElementById('revenueChart').getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 280);
        grad.addColorStop(0, 'rgba(12,110,94,0.25)');
        grad.addColorStop(1, 'rgba(12,110,94,0.01)');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: revData.map(r => { const [y,m] = r.month.split('-'); return new Date(y,m-1).toLocaleDateString('en',{month:'short'}); }),
                datasets: [{ label:'Revenue', data: revData.map(r => parseFloat(r.revenue)), borderColor: teal, backgroundColor: grad, fill:true, tension:0.4, pointRadius:5, pointHoverRadius:7, pointBackgroundColor:'#fff', pointBorderColor:teal, pointBorderWidth:2, borderWidth:2.5 }]
            },
            options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:{backgroundColor:isDark?'#1e293b':'#fff',titleColor:isDark?'#f1f5f9':'#1e293b',bodyColor:isDark?'#cbd5e1':'#475569',borderColor:isDark?'#334155':'#e2e8f0',borderWidth:1,cornerRadius:10,padding:12,displayColors:false,callbacks:{label:c=>'₱'+c.parsed.y.toLocaleString()}} }, scales:{ y:{beginAtZero:true,grid:{color:gridColor},ticks:{callback:v=>'₱'+v.toLocaleString(),font:{size:11}}}, x:{grid:{display:false},ticks:{font:{size:11}}} } }
        });
    }

    const bsData = <?= json_encode($bookingByStatus) ?>;
    if (Object.keys(bsData).length > 0) {
        const bsColors = Object.keys(bsData).map(s => ({confirmed:green,pending:amber,completed:blue,cancelled:red,in_progress:teal}[s]||'#94a3b8'));
        new Chart(document.getElementById('bookingStatusChart'), {
            type:'doughnut',
            data:{ labels:Object.keys(bsData).map(s=>s.charAt(0).toUpperCase()+s.slice(1)), datasets:[{data:Object.values(bsData),backgroundColor:bsColors,borderWidth:0,hoverOffset:6}] },
            options:{ responsive:true, maintainAspectRatio:false, cutout:'68%', plugins:{legend:{position:'bottom',labels:{usePointStyle:true,pointStyle:'circle',padding:16,font:{size:12}}}} }
        });
    }

    const btData = <?= json_encode($bookingsByMonth) ?>;
    if (btData.length > 0) {
        const btCtx = document.getElementById('bookingTrendChart').getContext('2d');
        const btGrad = btCtx.createLinearGradient(0, 0, 0, 280);
        btGrad.addColorStop(0, 'rgba(12,110,94,0.8)');
        btGrad.addColorStop(1, 'rgba(20,184,166,0.4)');
        new Chart(btCtx, {
            type:'bar',
            data:{ labels:btData.map(b=>{const[y,m]=b.month.split('-');return new Date(y,m-1).toLocaleDateString('en',{month:'short'});}), datasets:[{label:'Bookings',data:btData.map(b=>parseInt(b.count)),backgroundColor:btGrad,borderRadius:8,borderSkipped:false,barThickness:32}] },
            options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,grid:{color:gridColor},ticks:{stepSize:1,font:{size:11}}},x:{grid:{display:false},ticks:{font:{size:11}}}} }
        });
    }

    const urData = <?= json_encode($usersByMonth) ?>;
    if (urData.length > 0) {
        const urCtx = document.getElementById('userRegChart').getContext('2d');
        const urGrad = urCtx.createLinearGradient(0, 0, 0, 280);
        urGrad.addColorStop(0, 'rgba(16,185,129,0.8)');
        urGrad.addColorStop(1, 'rgba(16,185,129,0.3)');
        new Chart(urCtx, {
            type:'bar',
            data:{ labels:urData.map(u=>{const[y,m]=u.month.split('-');return new Date(y,m-1).toLocaleDateString('en',{month:'short'});}), datasets:[{label:'Users',data:urData.map(u=>parseInt(u.count)),backgroundColor:urGrad,borderRadius:8,borderSkipped:false,barThickness:32}] },
            options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,grid:{color:gridColor},ticks:{stepSize:1,font:{size:11}}},x:{grid:{display:false},ticks:{font:{size:11}}}} }
        });
    }

    const pmData = <?= json_encode($paymentByMethod) ?>;
    if (pmData.length > 0) {
        const pmLabels = pmData.map(p => p.payment_method==='gcash'?'GCash':(p.payment_method==='maya'?'Maya':(p.payment_method==='card'?'Card':p.payment_method)));
        const pmColors = pmData.map(p => { if(p.payment_method==='gcash') return '#007dfe'; if(p.payment_method==='maya') return '#00c853'; if(p.payment_method==='card') return purple; return blue; });
        new Chart(document.getElementById('paymentMethodChart'), {
            type:'doughnut',
            data:{ labels:pmLabels, datasets:[{data:pmData.map(p=>parseInt(p.cnt)),backgroundColor:pmColors,borderWidth:0,hoverOffset:6}] },
            options:{ responsive:true, maintainAspectRatio:false, cutout:'68%', plugins:{legend:{position:'bottom',labels:{usePointStyle:true,pointStyle:'circle',padding:16,font:{size:12}}}} }
        });
    }

    const teData = <?= json_encode($topEvents) ?>;
    if (teData.length > 0) {
        new Chart(document.getElementById('topEventsChart'), {
            type:'bar',
            data:{ labels:teData.map(e=>e.title.length>20?e.title.substring(0,20)+'...':e.title), datasets:[{label:'Bookings',data:teData.map(e=>e.booking_count),backgroundColor:['#0c6e5e','#3b82f6','#10b981','#f59e0b','#8b5cf6'],borderRadius:8,barThickness:24}] },
            options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,grid:{color:gridColor},ticks:{stepSize:1,font:{size:11}}},y:{grid:{display:false},ticks:{font:{size:11}}}} }
        });
    }
});

function selectPeriod(btn) {
    document.querySelectorAll('#periodPills .pill-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    var period = btn.dataset.period;
    var customDates = document.getElementById('customDates');
    var from = document.getElementById('dateFrom');
    var to = document.getElementById('dateTo');
    var now = new Date();

    if (period === 'custom') {
        customDates.style.display = 'flex';
        return;
    }
    customDates.style.display = 'none';

    to.value = now.toISOString().slice(0,10);
    if (period === 'today') {
        from.value = now.toISOString().slice(0,10);
    } else if (period === '7d') {
        var d = new Date(now);
        d.setDate(d.getDate() - 7);
        from.value = d.toISOString().slice(0,10);
    } else if (period === 'month') {
        from.value = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0,10);
    } else if (period === 'year') {
        from.value = new Date(now.getFullYear(), 0, 1).toISOString().slice(0,10);
    }
}

function resetFilters() {
    document.querySelectorAll('#periodPills .pill-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('#periodPills .pill-btn[data-period="month"]').classList.add('active');
    document.getElementById('customDates').style.display = 'none';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
}

function exportPDF(btn) {
    btn.classList.add('loading');
    var origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner"></i> Generating...';
    setTimeout(function() {
        try {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'mm', 'a4');
            const pageWidth = doc.internal.pageSize.getWidth();

            doc.setFontSize(18);
            doc.setTextColor(12, 110, 94);
            doc.text('BINALGO Tourism - Reports & Analytics', pageWidth / 2, 20, { align: 'center' });
            doc.setFontSize(10);
            doc.setTextColor(100);
            doc.text('Generated: ' + new Date().toLocaleDateString('en', {year:'numeric',month:'long',day:'numeric'}), pageWidth / 2, 28, { align: 'center' });

            let y = 38;
            doc.setFontSize(12);
            doc.setTextColor(30, 41, 59);
            doc.text('Key Metrics', 14, y);
            y += 8;

            doc.setFontSize(10);
            const metrics = [
                ['Total Bookings', exportData.summary.totalBookings],
                ['Total Revenue', 'P' + exportData.summary.totalRevenue.toLocaleString()],
                ['Monthly Revenue', 'P' + exportData.summary.monthlyRevenue.toLocaleString()],
                ['Active Users', exportData.summary.activeUsers],
                ['Avg Rating', exportData.summary.avgRating],
                ['Active Tours', exportData.summary.activeTours],
                ['Completed Tours', exportData.summary.completedTours],
                ['Published Events', exportData.summary.publishedEvents],
            ];
            metrics.forEach(function(pair) {
                doc.setTextColor(100);
                doc.text(pair[0] + ':', 14, y);
                doc.setTextColor(30, 41, 59);
                doc.text(String(pair[1]), 80, y);
                y += 7;
            });

            y += 5;
            doc.setFontSize(12);
            doc.setTextColor(30, 41, 59);
            doc.text('Bookings List', 14, y);
            y += 3;

            if (exportData.bookings.length > 0) {
                doc.autoTable({
                    startY: y,
                    head: [['Tourist', 'Event', 'Destination', 'Date', 'Participants', 'Total', 'Status']],
                    body: exportData.bookings.map(function(b) {
                        return [b.tourist_name || 'N/A', (b.event_title || '').substring(0, 25), b.destination_name || '', b.start_date || '', b.num_participants, 'P' + Number(b.total_price).toLocaleString(), b.status];
                    }),
                    theme: 'grid',
                    headStyles: { fillColor: [12, 110, 94], fontSize: 8 },
                    bodyStyles: { fontSize: 7 },
                    margin: { left: 14, right: 14 },
                });
                y = doc.lastAutoTable.finalY + 10;
            }

            if (y > 240) { doc.addPage(); y = 20; }
            doc.setFontSize(12);
            doc.setTextColor(30, 41, 59);
            doc.text('Payments List', 14, y);
            y += 3;

            if (exportData.payments.length > 0) {
                doc.autoTable({
                    startY: y,
                    head: [['Tourist', 'Amount', 'Method', 'Status', 'Date']],
                    body: exportData.payments.map(function(p) {
                        return [p.tourist_name || 'N/A', 'P' + Number(p.total_amount).toLocaleString(), p.payment_method || '', p.payment_status || '', p.payment_date || p.created_at || ''];
                    }),
                    theme: 'grid',
                    headStyles: { fillColor: [12, 110, 94], fontSize: 8 },
                    bodyStyles: { fontSize: 7 },
                    margin: { left: 14, right: 14 },
                });
            }

            doc.save('BINALGO_Reports_' + new Date().toISOString().slice(0,10) + '.pdf');
        } catch(e) { console.error(e); }
        btn.innerHTML = origHTML;
        btn.classList.remove('loading');
    }, 600);
}

function exportExcel(btn) {
    btn.classList.add('loading');
    var origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner"></i> Generating...';
    setTimeout(function() {
        try {
            var wb = XLSX.utils.book_new();
            var summaryRows = [
                ['BINALGO Tourism - Reports & Analytics'],
                ['Generated', new Date().toLocaleDateString()],
                [],
                ['Metric', 'Value'],
                ['Total Bookings', exportData.summary.totalBookings],
                ['Total Revenue', exportData.summary.totalRevenue],
                ['Monthly Revenue', exportData.summary.monthlyRevenue],
                ['Active Users', exportData.summary.activeUsers],
                ['Avg Rating', exportData.summary.avgRating],
                ['Active Tours', exportData.summary.activeTours],
                ['Completed Tours', exportData.summary.completedTours],
                ['Published Events', exportData.summary.publishedEvents],
            ];
            var wsSummary = XLSX.utils.aoa_to_sheet(summaryRows);
            wsSummary['!cols'] = [{ wch: 20 }, { wch: 15 }];
            XLSX.utils.book_append_sheet(wb, wsSummary, 'Summary');

            if (exportData.bookings.length > 0) {
                var bHeaders = ['ID', 'Reference', 'Tourist', 'Event', 'Destination', 'Date', 'Participants', 'Total Price', 'Status', 'Created At'];
                var bRows = exportData.bookings.map(function(b) {
                    return [b.id, b.booking_reference || '', b.tourist_name || '', b.event_title || '', b.destination_name || '', b.start_date || '', b.num_participants, b.total_price, b.status, b.created_at || ''];
                });
                var wsBookings = XLSX.utils.aoa_to_sheet([bHeaders].concat(bRows));
                wsBookings['!cols'] = bHeaders.map(function() { return { wch: 15 }; });
                XLSX.utils.book_append_sheet(wb, wsBookings, 'Bookings');
            }

            if (exportData.payments.length > 0) {
                var pHeaders = ['ID', 'Tourist', 'Amount', 'Tax', 'Service Fee', 'Total', 'Method', 'Status', 'Reference', 'Date'];
                var pRows = exportData.payments.map(function(p) {
                    return [p.id, p.tourist_name || '', p.amount, p.tax, p.service_fee, p.total_amount, p.payment_method || '', p.payment_status || '', p.reference_number || '', p.payment_date || p.created_at || ''];
                });
                var wsPayments = XLSX.utils.aoa_to_sheet([pHeaders].concat(pRows));
                wsPayments['!cols'] = pHeaders.map(function() { return { wch: 15 }; });
                XLSX.utils.book_append_sheet(wb, wsPayments, 'Payments');
            }

            XLSX.writeFile(wb, 'BINALGO_Reports_' + new Date().toISOString().slice(0,10) + '.xlsx');
        } catch(e) { console.error(e); }
        btn.innerHTML = origHTML;
        btn.classList.remove('loading');
    }, 600);
}
</script>

<?php }); ?>
