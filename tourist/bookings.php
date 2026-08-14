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
        redirect('/tourist/bookings.php');
    }

    if (isset($_POST['cancel_booking'])) {
        $bid = (int) $_POST['booking_id'];
        $cancel_stmt = $db->prepare(
            "UPDATE bookings SET status = 'cancelled', updated_at = NOW()
             WHERE id = :id AND tourist_id = :uid AND status IN ('pending','confirmed')"
        );
        $cancel_stmt->execute([':id' => $bid, ':uid' => $user_id]);
        if ($cancel_stmt->rowCount() > 0) {
            $bs = $db->prepare("SELECT b.schedule_id, b.num_participants FROM bookings b WHERE b.id = :id");
            $bs->execute([':id' => $bid]);
            $bk = $bs->fetch();
            if ($bk && $bk['schedule_id']) {
                $db->prepare("UPDATE schedules SET available_spots = available_spots + :n WHERE id = :sid")
                    ->execute([':n' => $bk['num_participants'], ':sid' => $bk['schedule_id']]);
            }

            $notif = new Notification();
            $notif->notifyBookingCancelled($bid);

            ActivityLog::log($user_id, 'booking_cancelled', "Cancelled booking #{$bid}");
            flash_message('success', 'Booking cancelled successfully.');
        } else {
            flash_message('error', 'Could not cancel booking.');
        }
        redirect('/tourist/bookings.php');
    }

    if (isset($_POST['delete_booking'])) {
        $bid = (int) $_POST['booking_id'];
        $ds = $db->prepare("SELECT b.schedule_id, b.num_participants, b.status, b.payment_status FROM bookings b WHERE b.id = :id AND b.tourist_id = :uid");
        $ds->execute([':id' => $bid, ':uid' => $user_id]);
        $del = $ds->fetch();

        if ($del) {
            if ($del['schedule_id']) {
                $db->prepare("UPDATE schedules SET available_spots = available_spots + :n WHERE id = :sid")
                    ->execute([':n' => $del['num_participants'], ':sid' => $del['schedule_id']]);
            }
            $db->prepare("DELETE FROM payments WHERE booking_id = :bid")
                ->execute([':bid' => $bid]);
            $db->prepare("DELETE FROM bookings WHERE id = :id AND tourist_id = :uid")
                ->execute([':id' => $bid, ':uid' => $user_id]);
            $db->prepare("DELETE FROM feedback WHERE booking_id = :bid")
                ->execute([':bid' => $bid]);
            ActivityLog::log($user_id, 'booking_deleted', "Deleted booking #{$bid} (status: {$del['status']})");
            flash_message('success', 'Booking deleted successfully.');
        } else {
            flash_message('error', 'Could not delete booking.');
        }
        redirect('/tourist/bookings.php');
    }
}

$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

$where = ["b.tourist_id = :uid"];
$params = [':uid' => $user_id];

if ($filter_status !== '') {
    $where[] = "b.status = :status";
    $params[':status'] = $filter_status;
}

if ($search !== '') {
    $where[] = "(COALESCE(e.title, d2.name) LIKE :search OR b.booking_reference LIKE :search2 OR CAST(b.id AS CHAR) LIKE :search3)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
}

if ($date_from !== '') {
    $where[] = "COALESCE(b.visit_date, s.start_date) >= :date_from";
    $params[':date_from'] = $date_from;
}
if ($date_to !== '') {
    $where[] = "COALESCE(b.visit_date, s.start_date) <= :date_to";
    $params[':date_to'] = $date_to;
}

$order_by = "b.created_at DESC";
if ($sort === 'date_upcoming') {
    $order_by = "COALESCE(b.visit_date, s.start_date) ASC, COALESCE(b.visit_time, s.start_time) ASC";
} elseif ($sort === 'date_desc') {
    $order_by = "COALESCE(b.visit_date, s.start_date) DESC";
} elseif ($sort === 'price_high') {
    $order_by = "b.total_price DESC";
} elseif ($sort === 'price_low') {
    $order_by = "b.total_price ASC";
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$count_stmt = $db->prepare("SELECT COUNT(*) as total FROM bookings b {$where_clause}");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetch()['total'];
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$bookings_stmt = $db->prepare(
    "SELECT b.*, s.start_date, s.end_date, s.start_time, s.end_time,
            COALESCE(e.title, d2.name) as event_name, COALESCE(d2.name, d.name) as destination_name, COALESCE(d2.location, d.location) as destination_location,
            COALESCE(d2.image, d.image) as destination_image,
            (SELECT COUNT(*) FROM feedback f WHERE f.booking_id = b.id) as has_feedback,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = b.id ORDER BY p.created_at DESC LIMIT 1) as pay_status,
            (SELECT p.payment_method FROM payments p WHERE p.booking_id = b.id ORDER BY p.created_at DESC LIMIT 1) as pay_method,
            (SELECT p.card_brand FROM payments p WHERE p.booking_id = b.id ORDER BY p.created_at DESC LIMIT 1) as pay_brand,
            (SELECT p.card_last_four FROM payments p WHERE p.booking_id = b.id ORDER BY p.created_at DESC LIMIT 1) as pay_last4
     FROM bookings b
     LEFT JOIN schedules s ON b.schedule_id = s.id
     LEFT JOIN events e ON s.event_id = e.id
     LEFT JOIN destinations d ON e.destination_id = d.id
     LEFT JOIN destinations d2 ON b.destination_id = d2.id
     {$where_clause}
     ORDER BY {$order_by}
     LIMIT {$per_page} OFFSET {$offset}"
);
$bookings_stmt->execute($params);
$bookings = $bookings_stmt->fetchAll();

$stats_stmt = $db->prepare(
    "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_price ELSE 0 END), 0) as total_spent
     FROM bookings b
     WHERE b.tourist_id = :uid"
);
$stats_stmt->execute([':uid' => $user_id]);
$stats = $stats_stmt->fetch();

render_page('tourist', 'bookings.php', 'My Bookings', function() use ($bookings, $filter_status, $page, $total_pages, $total, $stats, $user_id, $search, $date_from, $date_to, $sort) {
?>

<style>
.booking-hero{background:linear-gradient(135deg,#0c6e5e 0%,#0a5c4f 55%,#0e7490 100%);border-radius:20px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:0 16px 48px rgba(12,110,94,0.25);}
.booking-hero::before{content:'';position:absolute;top:-50px;right:-30px;width:200px;height:200px;background:rgba(255,255,255,0.07);border-radius:50%;}
.booking-hero::after{content:'';position:absolute;bottom:-40px;left:40px;width:140px;height:140px;background:rgba(255,255,255,0.04);border-radius:50%;}
.booking-hero .hero-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);backdrop-filter:blur(6px);border-radius:12px;padding:9px 18px;font-size:0.85rem;font-weight:600;}
.booking-hero .hero-badge .hero-amount{font-size:1.15rem;font-weight:800;}

/* KPI cards */
.kpi-card{position:relative;background:var(--card-bg,#fff);border-radius:16px;padding:18px 20px 16px;border:1px solid var(--border-color,#e2e8f0);transition:all 0.3s cubic-bezier(.4,0,.2,1);cursor:pointer;text-decoration:none;display:block;overflow:hidden;}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;}
.kpi-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,0.08);text-decoration:none;border-color:transparent;}
.kpi-card.active{border-color:transparent;box-shadow:0 12px 32px rgba(0,0,0,0.1);transform:translateY(-4px);}
.kpi-card.active::after{content:'';position:absolute;inset:0;border-radius:16px;box-shadow:inset 0 0 0 2px rgba(12,110,94,0.2);pointer-events:none;}
.kpi-card .kpi-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;}
.kpi-card .kpi-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:0.95rem;}
.kpi-card .kpi-count{font-size:1.7rem;font-weight:800;line-height:1;margin-bottom:6px;}
.kpi-card .kpi-label{font-size:0.78rem;color:var(--text-muted,#64748b);font-weight:600;margin-bottom:10px;}
.kpi-card .kpi-mini{display:inline-flex;align-items:center;gap:6px;font-size:0.72rem;font-weight:600;padding:4px 10px;border-radius:20px;}

/* Filter toolbar */
.filter-card{background:var(--card-bg,#fff);border-radius:16px;padding:16px;border:1px solid var(--border-color,#e2e8f0);margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,0.02);}
.filter-input{border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 14px;font-size:0.85rem;transition:all 0.2s;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);width:100%;}
.filter-input:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.filter-select{border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 34px 10px 14px;font-size:0.85rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);-webkit-appearance:none;-moz-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;}
.filter-select:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.filter-btn{background:var(--primary,#0c6e5e);color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:0.85rem;font-weight:600;transition:all 0.25s;display:inline-flex;align-items:center;gap:8px;}
.filter-btn:hover{background:#0a5c4f;box-shadow:0 4px 14px rgba(12,110,94,0.3);}

/* Status chips */
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:50px;font-size:0.75rem;font-weight:600;white-space:nowrap;}
.status-chip.pending{background:#fef3c7;color:#92400e;}
.status-chip.confirmed{background:#d1fae5;color:#065f46;}
.status-chip.completed{background:#dbeafe;color:#1e40af;}
.status-chip.cancelled{background:#fee2e2;color:#991b1b;}
.status-chip.paid{background:#d1fae5;color:#065f46;}
.status-chip.unpaid,.status-chip.pending_payment{background:#fee2e2;color:#991b1b;}
.status-chip.refunded{background:#dbeafe;color:#1e40af;}
.status-chip.partial{background:#fef3c7;color:#92400e;}

/* Booking cards */
.booking-list-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,0.02);}
.booking-list-header{padding:16px 22px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;}
.booking-list-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);font-size:0.95rem;}
.booking-item{display:flex;gap:20px;padding:22px;border-bottom:1px solid var(--border-color,#f1f5f9);transition:background 0.25s;}
.booking-item:last-child{border-bottom:none;}
.booking-item:hover{background:var(--bg-secondary,#fafafa);}
.booking-item .b-img-wrap{width:220px;min-width:220px;height:150px;border-radius:14px;overflow:hidden;position:relative;flex-shrink:0;}
.booking-item .b-img-wrap img{width:100%;height:100%;object-fit:cover;transition:transform 0.5s;}
.booking-item:hover .b-img-wrap img{transform:scale(1.06);}
.booking-item .b-img-overlay{position:absolute;top:10px;left:10px;display:inline-flex;align-items:center;gap:5px;background:rgba(15,23,42,0.75);backdrop-filter:blur(4px);border-radius:8px;padding:4px 10px;font-size:0.66rem;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:0.4px;}
.booking-item .b-ref{font-size:0.72rem;font-weight:600;color:var(--text-muted,#94a3b8);display:inline-flex;align-items:center;gap:6px;margin-bottom:4px;}
.booking-item .b-title{font-weight:800;font-size:1.05rem;color:var(--text-primary,#1e293b);margin-bottom:2px;}
.booking-item .b-loc{font-size:0.8rem;color:var(--text-secondary,#64748b);margin-bottom:14px;}
.booking-item .b-loc i{color:var(--primary,#0c6e5e);}
.b-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-bottom:16px;}
.b-detail-item .bd-label{font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted,#94a3b8);margin-bottom:4px;display:flex;align-items:center;gap:5px;}
.b-detail-item .bd-value{font-size:0.88rem;font-weight:600;color:var(--text-primary,#1e293b);}
.b-detail-item .bd-value.price{color:var(--primary,#0c6e5e);font-size:1rem;font-weight:800;}
.booking-item .b-actions{display:flex;gap:8px;flex-wrap:wrap;}
.booking-item .b-actions .btn{border-radius:10px;font-size:0.78rem;font-weight:600;padding:8px 16px;display:inline-flex;align-items:center;gap:6px;transition:all 0.25s;}
.b-btn-primary{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border:none;}
.b-btn-primary:hover{box-shadow:0 5px 16px rgba(12,110,94,0.35);transform:translateY(-1px);color:#fff;}
.b-btn-soft{border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);}
.b-btn-soft:hover{background:var(--bg-secondary,#f8fafc);border-color:#cbd5e1;}
.b-btn-danger{border:1px solid #fecaca;background:#fef2f2;color:#dc2626;}
.b-btn-danger:hover{background:#dc2626;color:#fff;border-color:#dc2626;}
.b-btn-star{border:1px solid #fde68a;background:#fffbeb;color:#d97706;}
.b-btn-star:hover{background:#f59e0b;color:#fff;border-color:#f59e0b;}

/* Empty state */
.booking-empty{position:relative;text-align:center;padding:64px 24px;overflow:hidden;}
.booking-empty .ambient-glow{position:absolute;top:-80px;left:50%;transform:translateX(-50%);width:420px;height:420px;border-radius:50%;background:radial-gradient(circle,rgba(12,110,94,0.08) 0%,rgba(52,211,153,0.05) 40%,transparent 70%);pointer-events:none;}
.booking-empty .empty-art{width:130px;height:130px;margin:0 auto 22px;position:relative;}
.booking-empty .empty-art .ticket{position:absolute;inset:0;transform:rotate(-12deg);}
.booking-empty .empty-art .ticket-body{position:absolute;inset:12px;background:linear-gradient(135deg,var(--card-bg,#fff),var(--bg-secondary,#f1f5f9));border:1.5px dashed var(--border-color,#cbd5e1);border-radius:14px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(0,0,0,0.06);}
.booking-empty .empty-art .ticket-body i{font-size:2.4rem;color:var(--primary,#0c6e5e);opacity:0.5;}
.booking-empty .empty-art .float-icon{position:absolute;width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;box-shadow:0 4px 12px rgba(0,0,0,0.08);}
.booking-empty h5{font-weight:800;color:var(--text-primary,#1e293b);margin-bottom:6px;font-size:1.15rem;}
.booking-empty p{color:var(--text-muted,#94a3b8);font-size:0.88rem;margin-bottom:24px;max-width:380px;margin-left:auto;margin-right:auto;}
.booking-empty .empty-cta{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
.booking-empty .empty-cta .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;font-weight:600;font-size:0.85rem;text-decoration:none;transition:all 0.25s;}
.booking-empty .empty-cta .btn-primary{background:linear-gradient(135deg,#0c6e5e,#10b981);color:#fff;box-shadow:0 4px 14px rgba(12,110,94,0.3);}
.booking-empty .empty-cta .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(12,110,94,0.4);color:#fff;}
.booking-empty .empty-cta .btn-outline{border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);}
.booking-empty .empty-cta .btn-outline:hover{background:var(--bg-secondary,#f8fafc);}

/* Modal */
.detail-card{background:var(--card-bg,#fff);border-radius:16px;overflow:hidden;}
.detail-card .detail-header{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;padding:24px;}
.detail-card .detail-body{padding:24px;}
.detail-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color,#f1f5f9);}
.detail-row:last-child{border-bottom:none;}
.detail-row .label{color:var(--text-muted,#64748b);font-size:0.85rem;}
.detail-row .value{font-weight:600;color:var(--text-primary,#1e293b);font-size:0.9rem;}
.pagination .page-link{border-radius:8px;margin:0 2px;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);font-size:0.85rem;padding:6px 12px;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-item.disabled .page-link{opacity:0.4;}
</style>

<div class="booking-hero">
    <div class="position-relative" style="z-index:1;">
        <h3 class="fw-bold mb-1"><i class="fas fa-ticket-alt me-2"></i>My Bookings</h3>
        <p class="mb-3 opacity-75" style="font-size:0.9rem;">Track and manage your tour reservations</p>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="hero-badge">
                <i class="fas fa-wallet" style="color:#34d399;"></i>
                <span class="small">Total Spent</span>
                <span class="hero-amount">₱<?= number_format((float)$stats['total_spent'], 2) ?></span>
            </div>
            <div class="hero-badge">
                <i class="fas fa-receipt" style="color:#34d399;"></i>
                <span class="small"><?= $stats['total'] ?> Booking<?= (int)$stats['total'] !== 1 ? 's' : '' ?></span>
            </div>
            <?php if ($filter_status !== ''): ?>
            <div class="hero-badge" style="background:rgba(255,255,255,0.08);">
                <i class="fas fa-filter"></i>
                <span class="small">Filtered: <?= ucfirst($filter_status) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<?php
$kpis = [
    'all' => ['label' => 'All Bookings', 'count' => (int)$stats['total'], 'color' => '#0c6e5e', 'bg' => 'rgba(12,110,94,0.1)', 'icon' => 'fa-ticket-alt', 'link' => 'bookings.php', 'filter' => '', 'desc' => 'Every reservation'],
    'pending' => ['label' => 'Pending', 'count' => (int)$stats['pending'], 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.1)', 'icon' => 'fa-clock', 'link' => '?status=pending', 'filter' => 'pending', 'desc' => 'Awaiting confirmation'],
    'confirmed' => ['label' => 'Confirmed', 'count' => (int)$stats['confirmed'], 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.1)', 'icon' => 'fa-check-circle', 'link' => '?status=confirmed', 'filter' => 'confirmed', 'desc' => 'Ready to go'],
    'completed' => ['label' => 'Completed', 'count' => (int)$stats['completed'], 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.1)', 'icon' => 'fa-flag-checkered', 'link' => '?status=completed', 'filter' => 'completed', 'desc' => 'Tours finished'],
    'cancelled' => ['label' => 'Cancelled', 'count' => (int)$stats['cancelled'], 'color' => '#ef4444', 'bg' => 'rgba(239,68,68,0.1)', 'icon' => 'fa-ban', 'link' => '?status=cancelled', 'filter' => 'cancelled', 'desc' => 'No longer active'],
];
?>
<div class="row g-3 mb-4">
    <?php foreach ($kpis as $k): ?>
    <div class="col-6 col-md-4 col-xl">
        <a href="<?= $k['link'] ?>" class="kpi-card <?= $filter_status === $k['filter'] ? 'active' : '' ?>" style="<?= $filter_status === $k['filter'] ? 'border-color:' . $k['color'] . ';' : '' ?>">
            <span style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,<?= $k['color'] ?>,<?= $k['color'] ?>88);"></span>
            <div class="kpi-top">
                <div class="kpi-icon" style="background:<?= $k['bg'] ?>;color:<?= $k['color'] ?>;"><i class="fas <?= $k['icon'] ?>"></i></div>
                <div class="kpi-mini" style="background:<?= $k['bg'] ?>;color:<?= $k['color'] ?>;">
                    <?= $k['filter'] === '' ? 'All' : ucfirst($k['filter']) ?>
                </div>
            </div>
            <div class="kpi-count" style="color:<?= $k['color'] ?>;"><?= $k['count'] ?></div>
            <div class="kpi-label"><?= $k['label'] ?></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter Toolbar -->
<div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="status" value="<?= $filter_status ?>">
        <div class="col-md-4">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">Search</label>
            <div class="position-relative">
                <i class="fas fa-search" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-muted,#94a3b8);font-size:0.8rem;"></i>
                <input type="text" name="search" class="filter-input" style="padding-left:36px;" value="<?= sanitize($search) ?>" placeholder="Search by tour name or reference ID...">
            </div>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">From</label>
            <input type="date" name="date_from" class="filter-input" value="<?= sanitize($date_from) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">To</label>
            <input type="date" name="date_to" class="filter-input" value="<?= sanitize($date_to) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#64748b);">Sort By</label>
            <select name="sort" class="filter-select">
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                <option value="date_upcoming" <?= $sort === 'date_upcoming' ? 'selected' : '' ?>>Upcoming Date</option>
                <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Date: Newest</option>
                <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
            </select>
        </div>
        <div class="col-md-2">
            <div class="d-flex gap-2">
                <button type="submit" class="filter-btn flex-grow-1"><i class="fas fa-filter"></i> Apply</button>
                <?php if ($search !== '' || $date_from !== '' || $date_to !== '' || $sort !== 'newest'): ?>
                    <a href="?status=<?= $filter_status ?>" class="btn" style="border:1px solid var(--border-color,#e2e8f0);color:var(--text-muted,#64748b);border-radius:10px;font-size:0.85rem;"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<div class="booking-list-card">
    <div class="booking-list-header">
        <div style="width:32px;height:32px;border-radius:9px;background:<?= $filter_status === '' ? 'linear-gradient(135deg,#0c6e5e,#1a8a7a)' : 'var(--bg-secondary,#f1f5f9)' ?>;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-list-ul" style="color:<?= $filter_status === '' ? '#fff' : 'var(--text-muted,#64748b)' ?>;font-size:0.8rem;"></i>
        </div>
        <h6><?= $filter_status === '' ? 'All Bookings' : ucfirst($filter_status) . ' Bookings' ?></h6>
        <span class="ms-auto small" style="color:var(--text-muted,#94a3b8);"><i class="fas fa-layer-group me-1"></i><?= $total ?> result<?= $total !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($bookings)): ?>
        <div class="booking-empty">
            <div class="ambient-glow"></div>
            <div class="empty-art">
                <div class="ticket">
                    <div class="ticket-body"><i class="fas fa-ticket-alt"></i></div>
                </div>
                <div class="float-icon" style="background:#d1fae5;top:-2px;right:6px;color:#10b981;"><i class="fas fa-check"></i></div>
                <div class="float-icon" style="background:#dbeafe;bottom:8px;left:0;color:#3b82f6;"><i class="fas fa-map-pin"></i></div>
            </div>
            <h5><?= $filter_status !== '' || $search !== '' || $date_from !== '' || $date_to !== '' ? 'No matching bookings' : 'No bookings yet' ?></h5>
            <p><?= $filter_status !== '' || $search !== '' || $date_from !== '' || $date_to !== '' ? 'Try adjusting your search or filters to find what you\'re looking for.' : "Start by browsing available tours and events. Your reservations will appear here." ?></p>
            <div class="empty-cta">
                <a href="browse.php" class="btn btn-primary"><i class="fas fa-compass"></i> Browse Tours</a>
                <a href="<?= BASE_URL ?>/index.php#featured" class="btn btn-outline"><i class="fas fa-star"></i> View Featured Destinations</a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($bookings as $b): ?>
            <?php
            $ps = $b['pay_status'] ?? $b['payment_status'] ?? 'unpaid';
            $pm = $b['pay_method'] ?? '';
            $pb = $b['pay_brand'] ?? '';
            $pl4 = $b['pay_last4'] ?? '';
            $ref = $b['booking_reference'] ?: 'BNL-' . date('Y') . '-' . str_pad($b['id'], 4, '0', STR_PAD_LEFT);
            $img = $b['destination_image'] ? BASE_URL . '/uploads/destinations/' . $b['destination_image'] : BASE_URL . '/assets/images/bambi.jpg';
            $bdate = $b['visit_date'] ?? $b['start_date'] ?? null;
            $btime = $b['visit_time'] ?? $b['start_time'] ?? null;
            ?>
            <div class="booking-item">
                <div class="b-img-wrap">
                    <img src="<?= $img ?>" alt="<?= sanitize($b['event_name'] ?? 'Tour') ?>" loading="lazy">
                    <span class="b-img-overlay"><i class="fas fa-map-marker-alt"></i> <?= sanitize($b['destination_name']) ?></span>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <div class="b-ref"><i class="fas fa-hashtag"></i><?= sanitize($ref) ?></div>
                            <div class="b-title"><?= sanitize($b['event_name'] ?? 'Destination Booking') ?></div>
                            <div class="b-loc"><i class="fas fa-map-marker-alt me-1"></i><?= sanitize($b['destination_location']) ?></div>
                        </div>
                        <span class="status-chip <?= $b['status'] ?>">
                            <i class="fas <?= $b['status'] === 'pending' ? 'fa-clock' : ($b['status'] === 'confirmed' ? 'fa-check-circle' : ($b['status'] === 'completed' ? 'fa-flag-checkered' : 'fa-ban')) ?>" style="font-size:0.6rem;"></i>
                            <?= ucfirst($b['status']) ?>
                        </span>
                    </div>

                    <div class="b-detail-grid">
                        <div class="b-detail-item">
                            <div class="bd-label"><i class="fas fa-calendar-day"></i> Schedule</div>
                            <div class="bd-value"><?= format_date($bdate) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-muted,#94a3b8);"><?= $btime ? date('h:i A', strtotime($btime)) : '—' ?></div>
                        </div>
                        <div class="b-detail-item">
                            <div class="bd-label"><i class="fas fa-users"></i> Guests</div>
                            <div class="bd-value"><?= $b['num_participants'] ?> <?= (int)$b['num_participants'] !== 1 ? 'pax' : 'pax' ?></div>
                        </div>
                        <div class="b-detail-item">
                            <div class="bd-label"><i class="fas fa-peso-sign"></i> Total Price</div>
                            <div class="bd-value price">₱<?= number_format((float)$b['total_price'], 2) ?></div>
                        </div>
                        <div class="b-detail-item">
                            <div class="bd-label"><i class="fas fa-credit-card"></i> Payment</div>
                            <div class="status-chip <?= $ps ?>">
                                <?php if ($pm === 'gcash'): ?>
                                    <span style="font-size:0.6rem;background:#007dfe;color:#fff;padding:1px 4px;border-radius:3px;">GC</span>GCash · <?= ucfirst($ps) ?>
                                <?php elseif ($pm === 'maya'): ?>
                                    <span style="font-size:0.6rem;background:#00c853;color:#fff;padding:1px 4px;border-radius:3px;">My</span>Maya · <?= ucfirst($ps) ?>
                                <?php elseif ($pb): ?>
                                    <i class="fab fa-cc-<?= $pb ?>"></i><?= ucfirst($pb) ?><?= $pl4 ? ' · ' . $pl4 : '' ?> · <?= ucfirst($ps) ?>
                                <?php else: ?>
                                    <?= ucfirst($ps) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="b-actions">
                        <?php if ($ps !== 'paid' && in_array($b['status'], ['pending', 'confirmed'])): ?>
                            <a href="<?= BASE_URL ?>/tourist/checkout.php?booking_id=<?= $b['id'] ?>" class="btn b-btn-primary"><i class="fas fa-credit-card"></i> Pay Now</a>
                        <?php endif; ?>
                        <button class="btn b-btn-soft" data-bs-toggle="modal" data-bs-target="#detailModal"
                            data-id="<?= $b['id'] ?>"
                            data-event="<?= sanitize($b['event_name']) ?>"
                            data-dest="<?= sanitize($b['destination_name']) ?>"
                            data-loc="<?= sanitize($b['destination_location']) ?>"
                            data-date="<?= format_date($bdate) ?>"
                            data-end="<?= format_date($b['end_date'] ?? $bdate ?? '') ?>"
                            data-time="<?= $btime ? date('h:i A', strtotime($btime)) : '—' ?>"
                            data-participants="<?= $b['num_participants'] ?>"
                            data-price="<?= number_format((float)$b['total_price'], 2) ?>"
                            data-status="<?= $b['status'] ?>"
                            data-payment="<?= $ps ?>"
                            data-requests="<?= sanitize($b['special_requests'] ?? '') ?>"
                            data-booked="<?= format_datetime($b['created_at']) ?>"
                            data-ref="<?= sanitize($ref) ?>"
                            onclick="showDetail(this)">
                            <i class="fas fa-qrcode"></i> View E-Ticket / QR
                        </button>
                        <button class="btn b-btn-soft" onclick="showToast('Invoice download is being prepared...', 'success');">
                            <i class="fas fa-file-invoice"></i> Invoice
                        </button>
                        <?php if (in_array($b['status'], ['pending', 'confirmed'])): ?>
                            <button class="btn b-btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"
                                data-id="<?= $b['id'] ?>"
                                data-event="<?= sanitize($b['event_name']) ?>"
                                onclick="showCancelConfirm(this)">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        <?php endif; ?>
                        <?php if ($b['status'] === 'completed' && !$b['has_feedback']): ?>
                            <a href="feedback.php?booking_id=<?= $b['id'] ?>" class="btn b-btn-star"><i class="fas fa-star"></i> Leave Review</a>
                        <?php endif; ?>
                        <button class="btn b-btn-soft" data-bs-toggle="modal" data-bs-target="#deleteModal"
                            data-id="<?= $b['id'] ?>"
                            data-event="<?= sanitize($b['event_name']) ?>"
                            onclick="showDeleteConfirm(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-4 py-3 border-top" style="border-color:var(--border-color,#f1f5f9) !important;">
            <small class="text-muted">Showing <?= ($page-1)*$per_page+1 ?>–<?= min($page*$per_page, $total) ?> of <?= $total ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="?status=<?= $filter_status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&sort=<?= $sort ?>&page=<?= $page-1 ?>"><i class="fas fa-chevron-left"></i></a>
                    </li>
                    <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
                        <li class="page-item <?= $i===$page?'active':'' ?>">
                            <a class="page-link" href="?status=<?= $filter_status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&sort=<?= $sort ?>&page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>">
                        <a class="page-link" href="?status=<?= $filter_status ?>&search=<?= urlencode($search) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&sort=<?= $sort ?>&page=<?= $page+1 ?>"><i class="fas fa-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
            <div class="detail-header" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;padding:24px;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="fas fa-ticket-alt me-2"></i>Booking #<span id="det_id"></span></h5>
                        <span class="small opacity-75"><i class="fas fa-hashtag me-1"></i><span id="det_ref"></span> · Booked on <span id="det_booked"></span></span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="detail-body" style="padding:24px;">
                <div class="detail-row">
                    <span class="label"><i class="fas fa-calendar me-2 text-primary"></i>Event</span>
                    <span class="value" id="det_event"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-map-marker-alt me-2 text-danger"></i>Destination</span>
                    <span class="value" id="det_dest"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-location-dot me-2 text-warning"></i>Location</span>
                    <span class="value" id="det_loc"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-calendar-day me-2 text-info"></i>Start Date</span>
                    <span class="value" id="det_date"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-calendar-check me-2 text-info"></i>End Date</span>
                    <span class="value" id="det_end"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-clock me-2 text-secondary"></i>Time</span>
                    <span class="value" id="det_time"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-users me-2 text-primary"></i>Participants</span>
                    <span class="value" id="det_participants"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-peso-sign me-2" style="color:var(--primary);"></i>Total Price</span>
                    <span class="value" style="color:var(--primary);font-size:1.05rem;">₱<span id="det_price"></span></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-credit-card me-2 text-warning"></i>Payment</span>
                    <span id="det_payment" class="status-chip"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-flag me-2 text-secondary"></i>Status</span>
                    <span id="det_status" class="status-chip"></span>
                </div>
                <div class="detail-row">
                    <span class="label"><i class="fas fa-comment me-2 text-muted"></i>Special Requests</span>
                    <span class="value" id="det_requests" style="font-weight:400;max-width:60%;text-align:right;"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                <input type="hidden" name="cancel_booking" value="1">
                <input type="hidden" name="booking_id" id="cancel_booking_id">
                <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);padding:24px;text-align:center;">
                    <div style="width:60px;height:60px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                        <i class="fas fa-exclamation-triangle" style="font-size:1.5rem;color:#f59e0b;"></i>
                    </div>
                    <h5 class="fw-bold mb-1" style="color:#92400e;">Cancel Booking</h5>
                </div>
                <div class="p-4 text-center">
                    <p class="mb-2">Are you sure you want to cancel your booking for</p>
                    <p class="fw-bold mb-3" style="color:var(--text-primary,#1e293b);font-size:1.05rem;" id="cancel_event_name"></p>
                    <p class="text-muted small mb-0">This action cannot be undone. Available spots will be released.</p>
                </div>
                <div class="d-flex gap-2 px-4 pb-4">
                    <button type="button" class="btn flex-grow-1" style="background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#1e293b);border-radius:10px;font-weight:600;" data-bs-dismiss="modal">Keep Booking</button>
                    <button type="submit" class="btn flex-grow-1" style="background:#ef4444;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-times me-1"></i>Cancel Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showToast(message, type) {
    type = type || 'warning';
    var existing = document.querySelector('.custom-toast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.className = 'custom-toast';
    var icon = type === 'error' ? 'fa-circle-exclamation' : (type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation');
    var colors = { error: '#ef4444', success: '#10b981', warning: '#f59e0b' };
    toast.innerHTML = '<div style="display:flex;align-items:center;gap:12px;"><div style="width:36px;height:36px;border-radius:10px;background:' + colors[type] + '15;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas ' + icon + '" style="color:' + colors[type] + ';font-size:0.95rem;"></i></div><span style="font-size:0.88rem;font-weight:500;color:#1e293b;">' + message + '</span></div>';
    toast.style.cssText = 'position:fixed;top:24px;right:24px;z-index:10000;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 20px;box-shadow:0 12px 40px rgba(0,0,0,0.12);transform:translateX(120%);transition:transform .35s cubic-bezier(.4,0,.2,1),opacity .35s;max-width:380px;';
    document.body.appendChild(toast);
    requestAnimationFrame(function() { toast.style.transform = 'translateX(0)'; });
    setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        setTimeout(function() { toast.remove(); }, 350);
    }, 3200);
}

function showDetail(btn) {
    document.getElementById('det_id').textContent = btn.dataset.id;
    document.getElementById('det_ref').textContent = btn.dataset.ref || ('BNL-' + new Date().getFullYear() + '-' + String(btn.dataset.id).padStart(4, '0'));
    document.getElementById('det_event').textContent = btn.dataset.event;
    document.getElementById('det_dest').textContent = btn.dataset.dest;
    document.getElementById('det_loc').textContent = btn.dataset.loc;
    document.getElementById('det_date').textContent = btn.dataset.date;
    document.getElementById('det_end').textContent = btn.dataset.end;
    document.getElementById('det_time').textContent = btn.dataset.time;
    document.getElementById('det_participants').textContent = btn.dataset.participants;
    document.getElementById('det_price').textContent = btn.dataset.price;
    document.getElementById('det_booked').textContent = btn.dataset.booked;
    document.getElementById('det_requests').textContent = btn.dataset.requests || 'None';

    const statusEl = document.getElementById('det_status');
    statusEl.textContent = btn.dataset.status;
    statusEl.className = 'status-chip ' + btn.dataset.status;

    const payEl = document.getElementById('det_payment');
    payEl.textContent = btn.dataset.payment || 'unpaid';
    payEl.className = 'status-chip ' + (btn.dataset.payment || 'unpaid');
}

function showCancelConfirm(btn) {
    document.getElementById('cancel_booking_id').value = btn.dataset.id;
    document.getElementById('cancel_event_name').textContent = btn.dataset.event;
}

function showDeleteConfirm(btn) {
    document.getElementById('delete_booking_id').value = btn.dataset.id;
    document.getElementById('delete_event_name').textContent = btn.dataset.event;
}
</script>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                <input type="hidden" name="delete_booking" value="1">
                <input type="hidden" name="booking_id" id="delete_booking_id">
                <div style="background:linear-gradient(135deg,#fee2e2,#fecaca);padding:24px;text-align:center;">
                    <div style="width:60px;height:60px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                        <i class="fas fa-trash-alt" style="font-size:1.5rem;color:#ef4444;"></i>
                    </div>
                    <h5 class="fw-bold mb-1" style="color:#991b1b;">Delete Booking</h5>
                </div>
                <div class="p-4 text-center">
                    <p class="mb-2">Are you sure you want to permanently delete your booking for</p>
                    <p class="fw-bold mb-3" style="color:var(--text-primary,#1e293b);font-size:1.05rem;" id="delete_event_name"></p>
                    <p class="text-muted small mb-0">This action cannot be undone. The booking will be permanently removed from your account.</p>
                </div>
                <div class="d-flex gap-2 px-4 pb-4">
                    <button type="button" class="btn flex-grow-1" style="background:var(--bg-secondary,#f1f5f9);color:var(--text-primary,#1e293b);border-radius:10px;font-weight:600;" data-bs-dismiss="modal">Keep Booking</button>
                    <button type="submit" class="btn flex-grow-1" style="background:#ef4444;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-trash me-1"></i>Delete Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php }); ?>
