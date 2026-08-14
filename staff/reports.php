<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');

$db = Database::getInstance()->getConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

$bookingsThisMonth = $db->prepare(
    "SELECT COUNT(*) as count FROM bookings
     WHERE created_at >= :start AND created_at <= :end"
);
$bookingsThisMonth->execute([':start' => $dateFrom . ' 00:00:00', ':end' => $dateTo . ' 23:59:59']);
$totalBookingsMonth = (int)$bookingsThisMonth->fetch()['count'];

$revenueThisMonth = $db->prepare(
    "SELECT COALESCE(SUM(total_price), 0) as revenue FROM bookings
     WHERE status IN ('confirmed', 'completed')
       AND created_at >= :start AND created_at <= :end"
);
$revenueThisMonth->execute([':start' => $dateFrom . ' 00:00:00', ':end' => $dateTo . ' 23:59:59']);
$totalRevenueMonth = (float)$revenueThisMonth->fetch()['revenue'];

$activeSchedules = $db->prepare(
    "SELECT COUNT(*) as count FROM schedules
     WHERE status IN ('scheduled', 'in_progress')
       AND start_date >= :start AND start_date <= :end"
);
$activeSchedules->execute([':start' => $dateFrom, ':end' => $dateTo]);
$activeSchedulesCount = (int)$activeSchedules->fetch()['count'];

$pendingBookings = $db->prepare(
    "SELECT COUNT(*) as count FROM bookings
     WHERE status = 'pending'
       AND created_at >= :start AND created_at <= :end"
);
$pendingBookings->execute([':start' => $dateFrom . ' 00:00:00', ':end' => $dateTo . ' 23:59:59']);
$pendingBookingsCount = (int)$pendingBookings->fetch()['count'];

$buildDateSeries = function (int $days, string $today) use ($db): array {
    $stmt = $db->prepare(
        "SELECT DATE(b.created_at) as booking_date,
                COUNT(*) as count,
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_price END), 0) as revenue
         FROM bookings b
         WHERE b.created_at >= :start AND b.created_at <= :end
         GROUP BY DATE(b.created_at)"
    );
    $stmt->execute([':start' => date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00', ':end' => $today . ' 23:59:59']);
    $rows = $stmt->fetchAll();
    $map = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $map[date('Y-m-d', strtotime("-{$i} days"))] = ['count' => 0, 'revenue' => 0];
    }
    foreach ($rows as $row) {
        if (isset($map[$row['booking_date']])) {
            $map[$row['booking_date']] = [
                'count' => (int)$row['count'],
                'revenue' => (float)$row['revenue']
            ];
        }
    }
    return $map;
};

$today = date('Y-m-d');
$map7 = $buildDateSeries(7, $today);
$map30 = $buildDateSeries(30, $today);

$ytd = $db->prepare(
    "SELECT DATE(b.created_at) as booking_date,
            COUNT(*) as count,
            COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_price END), 0) as revenue
     FROM bookings b
     WHERE b.created_at >= :start AND b.created_at <= :end
     GROUP BY DATE(b.created_at)"
);
$ytd->execute([':start' => date('Y') . '-01-01 00:00:00', ':end' => $today . ' 23:59:59']);
$ytdRows = $ytd->fetchAll();
$mapYTD = [];
for ($d = strtotime(date('Y') . '-01-01'); $d <= strtotime('today'); $d = strtotime('+1 day', $d)) {
    $mapYTD[date('Y-m-d', $d)] = ['count' => 0, 'revenue' => 0];
}
foreach ($ytdRows as $row) {
    if (isset($mapYTD[$row['booking_date']])) {
        $mapYTD[$row['booking_date']] = [
            'count' => (int)$row['count'],
            'revenue' => (float)$row['revenue']
        ];
    }
}

$series7 = [
    'labels' => array_keys($map7),
    'counts' => array_map(fn($v) => $v['count'], $map7),
    'revs' => array_map(fn($v) => $v['revenue'], $map7),
];
$series30 = [
    'labels' => array_keys($map30),
    'counts' => array_map(fn($v) => $v['count'], $map30),
    'revs' => array_map(fn($v) => $v['revenue'], $map30),
];
$seriesYTD = [
    'labels' => array_keys($mapYTD),
    'counts' => array_map(fn($v) => $v['count'], $mapYTD),
    'revs' => array_map(fn($v) => $v['revenue'], $mapYTD),
];

$guidePerformance = $db->query(
    "SELECT u.id, u.name as guide_name, u.avatar,
            (SELECT COUNT(*) FROM schedules s WHERE s.guide_id = u.id AND s.status = 'completed') as tours_completed,
            (SELECT COALESCE(AVG(f.overall_rating), 0) FROM feedback f WHERE f.guide_id = u.id) as avg_guide_rating,
            (SELECT COALESCE(AVG(f.overall_rating), 0) FROM feedback f JOIN schedules s ON f.schedule_id = s.id WHERE s.guide_id = u.id) as avg_rating,
            (SELECT COUNT(*) FROM feedback f WHERE f.guide_id = u.id) as total_feedback
     FROM users u
     WHERE u.role = 'guide'
     ORDER BY avg_rating DESC, tours_completed DESC"
);
$guidePerformanceData = $guidePerformance->fetchAll();

$maxGuideTours = 0;
foreach ($guidePerformanceData as $gp) {
    $maxGuideTours = max($maxGuideTours, (int)$gp['tours_completed']);
}
$maxGuideTours = $maxGuideTours ?: 1;

$destinationPopularity = $db->query(
    "SELECT d.id, d.name as destination_name, d.location, d.image,
            COUNT(DISTINCT b.id) as booking_count,
            COALESCE(SUM(b.total_price), 0) as revenue,
            COALESCE((SELECT AVG(f2.overall_rating)
                      FROM feedback f2
                      JOIN schedules s2 ON f2.schedule_id = s2.id
                      JOIN events e2 ON s2.event_id = e2.id
                      WHERE e2.destination_id = d.id), 0) as avg_rating
     FROM destinations d
     LEFT JOIN events e ON e.destination_id = d.id
     LEFT JOIN schedules s ON s.event_id = e.id
     LEFT JOIN bookings b ON b.schedule_id = s.id AND b.status IN ('confirmed', 'completed')
     WHERE d.status = 'active'
     GROUP BY d.id, d.name, d.location, d.image
     ORDER BY booking_count DESC, revenue DESC
     LIMIT 10"
);
$destinationPopularityData = $destinationPopularity->fetchAll();

$maxDestBookings = 0;
$totalDestRevenue = 0;
foreach ($destinationPopularityData as $dp) {
    $maxDestBookings = max($maxDestBookings, (int)$dp['booking_count']);
    $totalDestRevenue += (float)$dp['revenue'];
}
$maxDestBookings = $maxDestBookings ?: 1;

render_page('staff', 'reports.php', 'Reports', function () use ($totalBookingsMonth, $totalRevenueMonth, $activeSchedulesCount, $pendingBookingsCount, $series7, $series30, $seriesYTD, $guidePerformanceData, $destinationPopularityData, $dateFrom, $dateTo, $maxGuideTours, $maxDestBookings, $totalDestRevenue) {
?>
<style>
/* ---------- Tailwind-style utility layer (self-contained, offline-safe) ---------- */
.grid{display:grid;}
.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr));}
.gap-2{gap:8px;}.gap-3{gap:12px;}.gap-4{gap:16px;}.gap-6{gap:24px;}
.flex{display:flex;}.flex-col{flex-direction:column;}
.items-center{align-items:center;}.items-start{align-items:flex-start;}
.justify-between{justify-content:space-between;}.justify-end{justify-content:flex-end;}
.flex-1{flex:1 1 0%;}.shrink-0{flex-shrink:0;}.min-w-0{min-width:0;}
.w-full{width:100%;}.h-full{height:100%;}.w-11{width:44px;}.h-11{height:44px;}.w-12{width:48px;}.h-12{height:48px;}
.mb-1{margin-bottom:4px;}.mb-2{margin-bottom:8px;}.mb-3{margin-bottom:12px;}.mb-4{margin-bottom:16px;}.mb-6{margin-bottom:24px;}
.mt-1{margin-top:4px;}.mt-2{margin-top:8px;}.mt-3{margin-top:12px;}.mt-4{margin-top:16px;}
.p-4{padding:16px;}.p-5{padding:20px;}.p-6{padding:24px;}
.text-xs{font-size:.75rem;}.text-sm{font-size:.875rem;}.text-base{font-size:1rem;}
.font-medium{font-weight:500;}.font-semibold{font-weight:600;}.font-bold{font-weight:700;}
.truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.text-right{text-align:right;}
.border{border:1px solid #e2e8f0;}.border-slate-100{border-color:#f1f5f9;}.border-slate-200{border-color:#e2e8f0;}
.rounded-lg{border-radius:8px;}.rounded-xl{border-radius:12px;}.rounded-full{border-radius:9999px;}
.shadow-sm{box-shadow:0 1px 2px 0 rgba(15,23,42,.06);}
.hover\:shadow-md:hover{box-shadow:0 4px 6px -1px rgba(15,23,42,.10);}
.hover\:-translate-y-1:hover{transform:translateY(-2px);}
@media (min-width:640px){.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media (min-width:992px){.lg\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}.lg\:col-span-2{grid-column:span 2 / span 2;}.lg\:col-span-1{grid-column:span 1 / span 1;}}
@media (min-width:1200px){.xl\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr));}}

/* ---------- Hero ---------- */
.report-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.report-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.report-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}

/* ---------- Cards ---------- */
.report-stat{position:relative;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;transition:all .25s;height:100%;overflow:hidden;}
.report-stat::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent,#3b82f6);}
.report-stat:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(15,23,42,.10);}
.report-stat .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.report-stat .stat-value{font-size:1.7rem;font-weight:800;color:var(--text-primary,#0f172a);line-height:1.2;}
.report-stat .stat-label{font-size:.8rem;color:var(--text-muted,#64748b);margin-top:2px;}
.report-stat .stat-trend{font-size:.72rem;font-weight:600;color:var(--text-muted,#64748b);}

.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:16px 20px;margin-bottom:24px;}
.filter-input{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 14px;color:var(--text-primary,#0f172a);width:100%;font-size:.9rem;}
.filter-input:focus,.filter-select:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,.15);}
.filter-select{appearance:none;background:var(--card-bg,#fff) url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="%2364748b" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>') no-repeat right 14px center;border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 36px 10px 14px;color:var(--text-primary,#0f172a);width:100%;font-size:.9rem;cursor:pointer;}

.section-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;height:100%;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#e2e8f0);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#0f172a);}

.summary-item{padding:12px 0;border-bottom:1px solid var(--border-color,#e2e8f0);}
.summary-item:last-child{border-bottom:none;}
.summary-item .label{font-size:.8rem;color:var(--text-muted,#64748b);margin-bottom:2px;}
.summary-item .value{font-weight:700;font-size:.95rem;color:var(--text-primary,#0f172a);}

/* ---------- Buttons ---------- */
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;cursor:pointer;transition:opacity .2s;}
.btn-brand:hover{opacity:.9;color:#fff;}
.btn-reset{background:rgba(15,23,42,.05);color:var(--text-primary,#0f172a);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 20px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;transition:background .2s;}
.btn-reset:hover{background:rgba(15,23,42,.10);color:var(--text-primary,#0f172a);}
.export-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:96px;padding:8px 16px;border-radius:8px;font-size:.8rem;font-weight:600;border:1px solid;cursor:pointer;transition:all .2s;text-decoration:none;background:rgba(255,255,255,.12);}
.export-btn.pdf{border-color:rgba(255,255,255,.4);color:#fff;}
.export-btn.pdf:hover{background:rgba(255,255,255,.22);color:#fff;}
.export-btn.excel{border-color:rgba(255,255,255,.4);color:#fff;}
.export-btn.excel:hover{background:rgba(255,255,255,.22);color:#fff;}
.export-btn.print{border-color:rgba(255,255,255,.4);color:#fff;}
.export-btn.print:hover{background:rgba(255,255,255,.22);color:#fff;}
.export-btn:disabled{opacity:.75;cursor:wait;}

/* ---------- Leaderboard / progress ---------- */
.leader-item{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border-color,#e2e8f0);transition:background .2s;}
.leader-item:last-child{border-bottom:none;}
.leader-item:hover{background:rgba(15,23,42,.02);}
.leader-rank{width:26px;height:26px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:800;flex-shrink:0;}
.rank-1{background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#78350f;}
.rank-2{background:linear-gradient(135deg,#94a3b8,#cbd5e1);color:#334155;}
.rank-3{background:linear-gradient(135deg,#d97706,#f59e0b);color:#431407;}
.rank-n{background:rgba(15,23,42,.06);color:var(--text-muted,#64748b);}
.leader-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0;}
.leader-avatar-fallback{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;flex-shrink:0;}
.progress-track{width:100%;height:7px;border-radius:9999px;background:rgba(15,23,42,.08);overflow:hidden;}
.progress-fill{height:100%;border-radius:9999px;background:linear-gradient(90deg,#0d7a5f,#16a34a);transition:width .4s ease;}
.progress-fill.blue{background:linear-gradient(90deg,#3b82f6,#60a5fa);}
.chip{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:.72rem;font-weight:600;}
.chip.high{background:rgba(34,197,94,.14);color:#16a34a;}
.chip.medium{background:rgba(59,130,246,.14);color:#2563eb;}
.chip.low{background:rgba(245,158,11,.14);color:#d97706;}
.chip.poor{background:rgba(239,68,68,.14);color:#dc2626;}
.rating-stars{color:#f59e0b;font-size:.68rem;}

/* ---------- Destinations ---------- */
.dest-item{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border-color,#e2e8f0);transition:background .2s;}
.dest-item:last-child{border-bottom:none;}
.dest-item:hover{background:rgba(15,23,42,.02);}
.dest-thumb{width:52px;height:52px;border-radius:10px;object-fit:cover;flex-shrink:0;background:var(--bg-secondary,#f1f5f9);}
.dest-thumb-fallback{width:52px;height:52px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;color:var(--primary,#0c6e5e);}

/* ---------- Skeleton / empty chart ---------- */
.chart-wrap{position:relative;width:100%;height:300px;}
.chart-wrap canvas{max-height:300px;}
.chart-skeleton{display:none;position:absolute;inset:0;padding:8px;}
.skeleton-bar{background:linear-gradient(90deg,var(--bg-secondary,#f1f5f9) 25%,#e2e8f0 37%,var(--bg-secondary,#f1f5f9) 63%);background-size:400% 100%;animation:skel 1.4s ease infinite;border-radius:6px;}
@keyframes skel{0%{background-position:100% 50%;}100%{background-position:0 50%;}}
.chart-empty{display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;height:300px;color:var(--text-muted,#64748b);}
.chart-empty .chart-empty-icon{width:64px;height:64px;border-radius:16px;background:var(--bg-secondary,#f1f5f9);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#94a3b8;margin-bottom:14px;}
.legend-dot{width:10px;height:10px;border-radius:3px;display:inline-block;}
</style>

<div class="report-hero">
    <div class="row align-items-center">
        <div class="col-md-7 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-chart-bar me-2"></i>Reports</h3>
            <p class="mb-0 opacity-75" style="font-size:.9rem;">Analytics and performance insights for your tourism operations</p>
        </div>
        <div class="col-md-5 text-md-end position-relative" style="z-index:1;">
            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                <button class="export-btn pdf" id="btnExportPDF" onclick="runExport(this,'pdf')" title="Export as PDF"><i class="fas fa-file-pdf"></i><span>PDF</span></button>
                <button class="export-btn excel" id="btnExportExcel" onclick="runExport(this,'excel')" title="Export as Excel"><i class="fas fa-file-excel"></i><span>Excel</span></button>
                <button class="export-btn print" onclick="runExport(this,'print')" title="Print Report"><i class="fas fa-print"></i><span>Print</span></button>
            </div>
        </div>
    </div>
</div>

<div class="filter-card">
    <form id="reportFilterForm" method="GET" class="row g-2 align-items-end">
        <div class="col-md-2 col-lg-2">
            <label class="form-label small fw-semibold mb-1" style="color:var(--text-muted,#64748b);">Quick Presets</label>
            <select class="filter-select" onchange="applyReportPreset(this.value)">
                <option value="" disabled selected>Select range</option>
                <option value="today">Today</option>
                <option value="week">This Week</option>
                <option value="month">This Month</option>
                <option value="quarter">Quarter to Date</option>
                <option value="7d">Last 7 Days</option>
                <option value="30d">Last 30 Days</option>
                <option value="90d">Last 90 Days</option>
                <option value="ytd">Year to Date</option>
            </select>
        </div>
        <div class="col-md-3 col-lg-2">
            <label class="form-label small fw-semibold mb-1" style="color:var(--text-muted,#64748b);">From Date</label>
            <input type="date" name="date_from" id="dateFromInput" class="filter-input" value="<?= sanitize($dateFrom) ?>">
        </div>
        <div class="col-md-3 col-lg-2">
            <label class="form-label small fw-semibold mb-1" style="color:var(--text-muted,#64748b);">To Date</label>
            <input type="date" name="date_to" id="dateToInput" class="filter-input" value="<?= sanitize($dateTo) ?>">
        </div>
        <div class="col-md-4 col-lg-6 d-flex gap-2 flex-wrap">
            <button type="submit" class="btn-brand"><i class="fas fa-filter me-1"></i>Apply Filter</button>
            <a href="reports.php" class="btn-reset"><i class="fas fa-redo me-1"></i>Reset</a>
        </div>
    </form>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
    <div class="report-stat" style="--accent:#3b82f6;">
        <div class="flex items-center">
            <div class="stat-icon" style="background:rgba(59,130,246,.14);"><i class="fas fa-ticket" style="color:#3b82f6;"></i></div>
            <div class="flex-1 min-w-0" style="margin-left:14px;">
                <div class="stat-label">Bookings This Period</div>
                <div class="stat-value"><?= $totalBookingsMonth ?></div>
            </div>
        </div>
    </div>
    <div class="report-stat" style="--accent:#16a34a;">
        <div class="flex items-center">
            <div class="stat-icon" style="background:rgba(34,197,94,.14);"><span style="color:#16a34a;font-size:1.2rem;font-weight:800;">₱</span></div>
            <div class="flex-1 min-w-0" style="margin-left:14px;">
                <div class="stat-label">Revenue This Period</div>
                <div class="stat-value" style="font-size:1.35rem;">₱<?= number_format($totalRevenueMonth, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="report-stat" style="--accent:#06b6d4;">
        <div class="flex items-center">
            <div class="stat-icon" style="background:rgba(6,182,212,.14);"><i class="fas fa-calendar-check" style="color:#06b6d4;"></i></div>
            <div class="flex-1 min-w-0" style="margin-left:14px;">
                <div class="stat-label">Active Schedules</div>
                <div class="stat-value"><?= $activeSchedulesCount ?></div>
            </div>
        </div>
    </div>
    <div class="report-stat" style="--accent:#f59e0b;">
        <div class="flex items-center">
            <div class="stat-icon" style="background:rgba(245,158,11,.14);"><i class="fas fa-clock" style="color:#f59e0b;"></i></div>
            <div class="flex-1 min-w-0" style="margin-left:14px;">
                <div class="stat-label">Pending Bookings</div>
                <div class="stat-value"><?= $pendingBookingsCount ?></div>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-4">
    <div class="section-card lg:col-span-2">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(13,122,95,.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-chart-line" style="color:var(--primary,#0c6e5e);font-size:.7rem;"></i></div>
                <h6>Bookings &amp; Revenue by Date</h6>
            </div>
            <div class="chart-toolbar" data-chart-handler="BINALGO_REPORT.setRange">
                <button type="button" class="chart-range-btn active" data-range="7">Last 7 Days</button>
                <button type="button" class="chart-range-btn" data-range="30">Last 30 Days</button>
                <button type="button" class="chart-range-btn" data-range="ytd">YTD</button>
            </div>
        </div>
        <div style="padding:20px;">
            <div class="flex items-center gap-4 mb-3" style="font-size:.75rem;color:var(--text-muted,#64748b);font-weight:600;">
                <span class="flex items-center gap-1"><span class="legend-dot" style="background:#0d7a5f;"></span>Bookings</span>
                <span class="flex items-center gap-1"><span class="legend-dot" style="background:#3b82f6;"></span>Revenue (₱)</span>
            </div>
            <div class="chart-wrap" id="chartWrap" style="display:none;">
                <canvas id="bookingsChart"></canvas>
            </div>
            <div class="chart-skeleton" id="chartSkeleton" style="display:flex;flex-direction:column;gap:12px;">
                <div class="skeleton-bar" style="height:24px;width:45%;"></div>
                <div class="skeleton-bar" style="height:220px;"></div>
            </div>
            <div class="chart-empty" id="chartEmpty">
                <div class="chart-empty-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="font-semibold" style="color:var(--text-primary,#0f172a);">No chart data yet</div>
                <p class="mb-0 mt-1" style="font-size:.85rem;max-width:320px;">Bookings and revenue trends will appear here once reservations are recorded in the selected period.</p>
            </div>
        </div>
    </div>
    <div class="section-card lg:col-span-1">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(13,122,95,.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-info-circle" style="color:var(--primary,#0c6e5e);font-size:.7rem;"></i></div>
                <h6>Report Summary</h6>
            </div>
        </div>
        <div style="padding:4px 20px 16px;">
            <div class="summary-item"><div class="label">Date Range</div><div class="value"><?= format_date($dateFrom) ?> - <?= format_date($dateTo) ?></div></div>
            <div class="summary-item"><div class="label">Avg. Revenue per Booking</div><div class="value">₱<?= $totalBookingsMonth > 0 ? number_format($totalRevenueMonth / $totalBookingsMonth, 2) : '0.00' ?></div></div>
            <div class="summary-item"><div class="label">Booking Conversion Rate</div><div class="value"><?= $totalBookingsMonth > 0 ? number_format((($totalBookingsMonth - $pendingBookingsCount) / $totalBookingsMonth) * 100, 1) : '0' ?>%</div></div>
            <div class="summary-item"><div class="label">Total Destination Revenue</div><div class="value">₱<?= number_format($totalDestRevenue, 2) ?></div></div>
            <div class="summary-item"><div class="label">Active Guides</div><div class="value"><?= count(array_filter($guidePerformanceData, fn($g) => (int)$g['tours_completed'] > 0)) ?></div></div>
        </div>
    </div>
</div>

<?php if (!empty($guidePerformanceData) || !empty($destinationPopularityData)): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-2">
    <?php if (!empty($guidePerformanceData)): ?>
    <div class="section-card">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(34,197,94,.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-user-tie" style="color:#16a34a;font-size:.7rem;"></i></div>
                <h6>Guide Performance Leaderboard</h6>
            </div>
            <span class="chip medium"><?= count($guidePerformanceData) ?> guides</span>
        </div>
        <?php foreach ($guidePerformanceData as $i => $gp): ?>
            <?php $avgR = round((float)$gp['avg_rating'], 1); ?>
            <?php $tours = (int)$gp['tours_completed']; ?>
            <?php $pct = round(($tours / $maxGuideTours) * 100); ?>
            <?php $rankClass = $i === 0 ? 'rank-1' : ($i === 1 ? 'rank-2' : ($i === 2 ? 'rank-3' : 'rank-n')); ?>
            <div class="leader-item">
                <div class="leader-rank <?= $rankClass ?>"><?= $i + 1 ?></div>
                <div class="shrink-0">
                    <img src="<?= sanitize(get_avatar_url(['id' => $gp['id'], 'name' => $gp['guide_name'], 'avatar' => $gp['avatar']])) ?>" alt="<?= sanitize($gp['guide_name']) ?>" class="leader-avatar" onerror="this.style.display='none';var fb=this.nextElementSibling;if(fb){fb.style.display='flex';}">
                    <div class="leader-avatar-fallback" style="display:none;background:rgba(13,122,95,.12);color:var(--primary,#0c6e5e);"><?= strtoupper(substr($gp['guide_name'], 0, 1)) ?></div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-semibold truncate" style="font-size:.9rem;color:var(--text-primary,#0f172a);"><?= sanitize($gp['guide_name']) ?></span>
                        <span class="flex items-center gap-1 shrink-0" style="font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);">
                            <?php if ($avgR > 0): ?>
                                <i class="fas fa-star" style="color:#f59e0b;font-size:.65rem;"></i><?= $avgR ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="progress-track"><div class="progress-fill" style="width:<?= $pct ?>%;"></div></div>
                    <div class="flex items-center justify-between mt-1">
                        <span style="font-size:.72rem;color:var(--text-muted,#64748b);"><?= $tours ?> tours completed</span>
                        <span style="font-size:.72rem;color:var(--text-muted,#64748b);"><?= $gp['total_feedback'] ?> feedback</span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($destinationPopularityData)): ?>
    <div class="section-card">
        <div class="section-header">
            <div class="flex items-center gap-2">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(239,68,68,.12);display:flex;align-items:center;justify-content:center;"><i class="fas fa-map-marker-alt" style="color:#ef4444;font-size:.7rem;"></i></div>
                <h6>Destination Popularity</h6>
            </div>
            <span class="chip medium"><?= count($destinationPopularityData) ?> destinations</span>
        </div>
        <?php foreach ($destinationPopularityData as $dp): ?>
            <?php $bk = (int)$dp['booking_count']; ?>
            <?php $rev = (float)$dp['revenue']; ?>
            <?php $pct = round(($bk / $maxDestBookings) * 100); ?>
            <?php $revShare = $totalDestRevenue > 0 ? round(($rev / $totalDestRevenue) * 100) : 0; ?>
            <?php $avgR = round((float)$dp['avg_rating'], 1); ?>
            <?php $img = dest_image_url($dp['image']); ?>
            <div class="dest-item">
                <div class="shrink-0">
                    <img src="<?= sanitize($img) ?>" alt="<?= sanitize($dp['destination_name']) ?>" class="dest-thumb" onerror="this.style.display='none';var fb=this.nextElementSibling;if(fb){fb.style.display='flex';}">
                    <div class="dest-thumb-fallback" style="display:none;background:rgba(13,122,95,.10);"><i class="fas fa-map-marker-alt"></i></div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1">
                        <div class="min-w-0 mr-2">
                            <div class="font-semibold truncate" style="font-size:.9rem;color:var(--text-primary,#0f172a);"><?= sanitize($dp['destination_name']) ?></div>
                            <?php if (!empty($dp['location'])): ?>
                                <div class="truncate" style="font-size:.72rem;color:var(--text-muted,#64748b);"><i class="fas fa-map-pin me-1" style="font-size:.6rem;"></i><?= sanitize($dp['location']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="shrink-0" style="font-size:.78rem;font-weight:700;color:var(--text-primary,#0f172a);">₱<?= number_format($rev) ?></span>
                    </div>
                    <div class="progress-track"><div class="progress-fill blue" style="width:<?= $pct ?>%;"></div></div>
                    <div class="flex items-center justify-between mt-1">
                        <span style="font-size:.72rem;color:var(--text-muted,#64748b);"><?= $bk ?> bookings &middot; <?= $revShare ?>% of revenue</span>
                        <?php if ($avgR > 0): ?>
                            <span class="rating-stars shrink-0"><?php for ($s = 1; $s <= 5; $s++): ?><i class="fas fa-star" style="color:<?= $s <= round($avgR) ? '#f59e0b' : '#cbd5e1' ?>;"></i><?php endfor; ?></span>
                        <?php else: ?>
                            <span style="font-size:.72rem;color:var(--text-muted,#94a3b8);">No ratings</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var BINALGO_REPORT = (function () {
    var data = {
        '7': {
            labels: <?= json_encode($series7['labels']) ?>,
            counts: <?= json_encode($series7['counts']) ?>,
            revs: <?= json_encode($series7['revs']) ?>
        },
        '30': {
            labels: <?= json_encode($series30['labels']) ?>,
            counts: <?= json_encode($series30['counts']) ?>,
            revs: <?= json_encode($series30['revs']) ?>
        },
        'ytd': {
            labels: <?= json_encode($seriesYTD['labels']) ?>,
            counts: <?= json_encode($seriesYTD['counts']) ?>,
            revs: <?= json_encode($seriesYTD['revs']) ?>
        }
    };
    var chart = null;
    var wrap = null, skel = null, empty = null;
    var money = function (v) {
        return '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var fmt = function (d) {
        var p = d.split('-');
        return new Date(p[0], p[1] - 1, p[2]).toLocaleDateString('en', { month: 'short', day: 'numeric' });
    };
    function hasData(item) {
        for (var i = 0; i < item.counts.length; i++) {
            if (item.counts[i] > 0 || item.revs[i] > 0) return true;
        }
        return false;
    }
    function build(item) {
        var canvas = document.getElementById('bookingsChart');
        var ctx = canvas.getContext('2d');
        var grad = ctx.createLinearGradient(0, 0, 0, 300);
        grad.addColorStop(0, 'rgba(13,122,95,0.28)');
        grad.addColorStop(1, 'rgba(13,122,95,0.02)');
        var labels = item.labels.map(fmt);
        chart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'line',
                        label: 'Bookings',
                        data: item.counts,
                        borderColor: '#0d7a5f',
                        backgroundColor: grad,
                        fill: true,
                        tension: 0.45,
                        borderWidth: 2.5,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#0d7a5f',
                        yAxisID: 'y'
                    },
                    {
                        type: 'bar',
                        label: 'Revenue (₱)',
                        data: item.revs,
                        backgroundColor: 'rgba(59,130,246,0.55)',
                        hoverBackgroundColor: 'rgba(59,130,246,0.8)',
                        borderRadius: 4,
                        borderSkipped: false,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.92)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#f1f5f9',
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: true,
                        callbacks: {
                            label: function (ctxItem) {
                                if (ctxItem.dataset.type === 'line') {
                                    return ' Bookings: ' + ctxItem.parsed.y;
                                }
                                return ' Revenue: ' + money(ctxItem.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#94a3b8',
                            maxRotation: 45,
                            autoSkip: true,
                            maxTicksLimit: 14,
                            font: { size: 11 }
                        }
                    },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        ticks: { color: '#94a3b8', precision: 0, font: { size: 11 } },
                        grid: { color: 'rgba(148,163,184,0.14)' }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        ticks: {
                            color: '#94a3b8',
                            callback: function (val) {
                                if (val >= 1000) return '₱' + (val / 1000).toFixed(1) + 'k';
                                return '₱' + val;
                            },
                            font: { size: 11 }
                        },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }
    function draw(range) {
        var item = data[range] || data['7'];
        if (!skel) {
            wrap = document.getElementById('chartWrap');
            skel = document.getElementById('chartSkeleton');
            empty = document.getElementById('chartEmpty');
        }
        skel.style.display = 'flex';
        wrap.style.display = 'none';
        empty.style.display = 'none';
        setTimeout(function () {
            skel.style.display = 'none';
            if (chart) { chart.destroy(); chart = null; }
            if (!hasData(item)) {
                empty.style.display = 'flex';
                wrap.style.display = 'none';
                return;
            }
            empty.style.display = 'none';
            wrap.style.display = 'block';
            build(item);
        }, 300);
    }
    return {
        setRange: function (range) { draw(range); },
        init: function () { draw('7'); }
    };
})();
document.addEventListener('DOMContentLoaded', function () {
    if (window.BINALGO_STAFF && window.BINALGO_REPORT) BINALGO_REPORT.init();
});

function applyReportPreset(value) {
    if (!value) return;
    var now = new Date();
    var from;
    var to = now;
    function iso(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + dd;
    }
    function startOfWeek(d) { var x = new Date(d); var day = (x.getDay() + 6) % 7; x.setDate(x.getDate() - day); return x; }
    switch (value) {
        case 'today': from = now; break;
        case 'week': from = startOfWeek(now); break;
        case 'month': from = new Date(now.getFullYear(), now.getMonth(), 1); break;
        case 'quarter': from = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1); break;
        case '7d': from = new Date(now); from.setDate(from.getDate() - 6); break;
        case '30d': from = new Date(now); from.setDate(from.getDate() - 29); break;
        case '90d': from = new Date(now); from.setDate(from.getDate() - 89); break;
        case 'ytd': from = new Date(now.getFullYear(), 0, 1); break;
        default: return;
    }
    document.getElementById('dateFromInput').value = iso(from);
    document.getElementById('dateToInput').value = iso(to);
    document.getElementById('reportFilterForm').submit();
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
function runExport(btn, kind) {
    if (btn.disabled) return;
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span><span>Preparing...</span>';
    setTimeout(function () {
        try {
            if (kind === 'pdf') exportPDF();
            else if (kind === 'excel') exportExcel();
            else window.print();
        } catch (e) {
            console.error('Export failed', e);
        }
        btn.disabled = false;
        btn.innerHTML = original;
    }, 150);
}

function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'mm', 'a4');
    const brandColor = [12, 110, 94];

    doc.setFontSize(18);
    doc.setTextColor(...brandColor);
    doc.text('BINALGO Tourism Report', 14, 18);
    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.text('Date Range: <?= format_date($dateFrom) ?> - <?= format_date($dateTo) ?>', 14, 26);
    doc.text('Generated: ' + new Date().toLocaleDateString(), 14, 32);

    doc.setFontSize(12);
    doc.setTextColor(50, 50, 50);
    doc.text('Overview', 14, 44);
    doc.autoTable({
        startY: 48,
        head: [['Metric', 'Value']],
        body: [
            ['Bookings This Period', '<?= $totalBookingsMonth ?>'],
            ['Revenue This Period', '₱<?= number_format($totalRevenueMonth, 2) ?>'],
            ['Active Schedules', '<?= $activeSchedulesCount ?>'],
            ['Pending Bookings', '<?= $pendingBookingsCount ?>'],
            ['Avg. Revenue per Booking', '₱<?= $totalBookingsMonth > 0 ? number_format($totalRevenueMonth / $totalBookingsMonth, 2) : '0.00' ?>'],
            ['Booking Conversion Rate', '<?= $totalBookingsMonth > 0 ? number_format((($totalBookingsMonth - $pendingBookingsCount) / $totalBookingsMonth) * 100, 1) : '0' ?>%'],
            ['Total Destination Revenue', '₱<?= number_format($totalDestRevenue, 2) ?>'],
        ],
        theme: 'grid',
        headStyles: { fillColor: brandColor, textColor: [255, 255, 255], fontSize: 9 },
        styles: { fontSize: 9, textColor: [50, 50, 50] },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 14, right: 14 }
    });

    <?php if (!empty($guidePerformanceData)): ?>
    let y = doc.lastAutoTable.finalY + 12;
    doc.setFontSize(12);
    doc.text('Guide Performance Leaderboard', 14, y);
    doc.autoTable({
        startY: y + 4,
        head: [['#', 'Guide Name', 'Tours Completed', 'Avg. Rating', 'Total Feedback']],
        body: [
            <?php foreach ($guidePerformanceData as $i => $gp): ?>
            ['<?= $i + 1 ?>', '<?= addslashes($gp['guide_name']) ?>', '<?= $gp['tours_completed'] ?>', '<?= (float)$gp['avg_rating'] > 0 ? round((float)$gp['avg_rating'], 1) . '/5' : 'N/A' ?>', '<?= $gp['total_feedback'] ?>'],
            <?php endforeach; ?>
        ],
        theme: 'grid',
        headStyles: { fillColor: brandColor, textColor: [255, 255, 255], fontSize: 9 },
        styles: { fontSize: 9, textColor: [50, 50, 50] },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 14, right: 14 }
    });
    <?php endif; ?>

    <?php if (!empty($destinationPopularityData)): ?>
    let y2 = doc.lastAutoTable.finalY + 12;
    doc.setFontSize(12);
    doc.text('Destination Popularity', 14, y2);
    doc.autoTable({
        startY: y2 + 4,
        head: [['Destination', 'Bookings', 'Revenue', 'Avg. Rating']],
        body: [
            <?php foreach ($destinationPopularityData as $dp): ?>
            ['<?= addslashes($dp['destination_name']) ?>', '<?= (int)$dp['booking_count'] ?>', '₱<?= number_format((float)$dp['revenue'], 2) ?>', '<?= round((float)$dp['avg_rating'], 1) ?>'],
            <?php endforeach; ?>
        ],
        theme: 'grid',
        headStyles: { fillColor: brandColor, textColor: [255, 255, 255], fontSize: 9 },
        styles: { fontSize: 9, textColor: [50, 50, 50] },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 14, right: 14 }
    });
    <?php endif; ?>

    doc.save('BINALGO_Report_<?= $dateFrom ?>_to_<?= $dateTo ?>.pdf');
}

function exportExcel() {
    const wb = XLSX.utils.book_new();

    const overviewData = [
        ['BINALGO Tourism Report'],
        ['Date Range: <?= format_date($dateFrom) ?> - <?= format_date($dateTo) ?>'],
        [''],
        ['Metric', 'Value'],
        ['Bookings This Period', '<?= $totalBookingsMonth ?>'],
        ['Revenue This Period', '₱<?= number_format($totalRevenueMonth, 2) ?>'],
        ['Active Schedules', '<?= $activeSchedulesCount ?>'],
        ['Pending Bookings', '<?= $pendingBookingsCount ?>'],
        ['Avg. Revenue per Booking', '₱<?= $totalBookingsMonth > 0 ? number_format($totalRevenueMonth / $totalBookingsMonth, 2) : '0.00' ?>'],
        ['Booking Conversion Rate', '<?= $totalBookingsMonth > 0 ? number_format((($totalBookingsMonth - $pendingBookingsCount) / $totalBookingsMonth) * 100, 1) : '0' ?>%'],
        ['Total Destination Revenue', '₱<?= number_format($totalDestRevenue, 2) ?>'],
    ];
    const ws1 = XLSX.utils.aoa_to_sheet(overviewData);
    ws1['!cols'] = [{ wch: 30 }, { wch: 22 }];
    XLSX.utils.book_append_sheet(wb, ws1, 'Overview');

    <?php if (!empty($guidePerformanceData)): ?>
    const guideData = [
        ['Guide Performance Leaderboard'],
        [''],
        ['#', 'Guide Name', 'Tours Completed', 'Avg. Rating', 'Total Feedback'],
        <?php foreach ($guidePerformanceData as $i => $gp): ?>
        ['<?= $i + 1 ?>', '<?= addslashes($gp['guide_name']) ?>', '<?= $gp['tours_completed'] ?>', '<?= (float)$gp['avg_rating'] > 0 ? round((float)$gp['avg_rating'], 1) : 'N/A' ?>', '<?= $gp['total_feedback'] ?>'],
        <?php endforeach; ?>
    ];
    const ws2 = XLSX.utils.aoa_to_sheet(guideData);
    ws2['!cols'] = [{ wch: 5 }, { wch: 25 }, { wch: 18 }, { wch: 14 }, { wch: 16 }];
    XLSX.utils.book_append_sheet(wb, ws2, 'Guide Performance');
    <?php endif; ?>

    <?php if (!empty($destinationPopularityData)): ?>
    const destData = [
        ['Destination Popularity'],
        [''],
        ['Destination', 'Bookings', 'Revenue', 'Avg. Rating'],
        <?php foreach ($destinationPopularityData as $dp): ?>
        ['<?= addslashes($dp['destination_name']) ?>', '<?= (int)$dp['booking_count'] ?>', '₱<?= number_format((float)$dp['revenue'], 2) ?>', '<?= round((float)$dp['avg_rating'], 1) ?>'],
        <?php endforeach; ?>
    ];
    const ws3 = XLSX.utils.aoa_to_sheet(destData);
    ws3['!cols'] = [{ wch: 28 }, { wch: 14 }, { wch: 16 }, { wch: 14 }];
    XLSX.utils.book_append_sheet(wb, ws3, 'Destination Popularity');
    <?php endif; ?>

    XLSX.writeFile(wb, 'BINALGO_Report_<?= $dateFrom ?>_to_<?= $dateTo ?>.xlsx');
}
</script>
<style>
@media print {
    .report-hero,.filter-card,.export-btn,.btn-brand,.btn-reset,.chart-toolbar,.chart-skeleton,.chart-empty{display:none !important;}
    .section-card,.report-stat,.leader-item,.dest-item{break-inside:avoid;}
    .chart-wrap{display:block !important;height:auto !important;}
}
</style>
<?php }); ?>
