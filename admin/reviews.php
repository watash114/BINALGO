<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');
require_once __DIR__ . '/../includes/classes/DestinationReview.php';

$db = Database::getInstance()->getConnection();
$reviewModel = new DestinationReview();

$stats = $db->query("SELECT
    COUNT(*) as total,
    COALESCE(SUM(CASE WHEN is_hidden=0 THEN 1 ELSE 0 END),0) as visible,
    COALESCE(SUM(CASE WHEN is_hidden=1 THEN 1 ELSE 0 END),0) as hidden_count,
    COALESCE(AVG(CASE WHEN is_hidden=0 THEN rating END),0) as avg_rating
    FROM destination_reviews")->fetch();

$page = max(1, (int)($_GET['page'] ?? 1));
$destFilter = $_GET['destination'] ?? '';
$hiddenFilter = $_GET['hidden'] ?? '';
$search = $_GET['search'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();

// ── AJAX data endpoint (GET ?ajax=1) ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 15;
    $qDest = trim($_GET['destination'] ?? '');
    $qHidden = $_GET['hidden'] ?? '';
    $qSearch = trim($_GET['search'] ?? '');
    $qSort = $_GET['sort'] ?? 'date';
    $qDir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $sortMap = [
        'id' => 'dr.id',
        'user' => 'u.name',
        'destination' => 'd.name',
        'rating' => 'dr.rating',
        'date' => 'dr.created_at',
    ];
    $orderBy = ($sortMap[$qSort] ?? 'dr.created_at') . ' ' . $qDir . ', dr.id DESC';

    $where = [];
    $params = [];
    if ($qDest !== '') { $where[] = 'dr.destination_id = :dest'; $params[':dest'] = (int)$qDest; }
    if ($qHidden === '0') { $where[] = 'dr.is_hidden = 0'; }
    elseif ($qHidden === '1') { $where[] = 'dr.is_hidden = 1'; }
    if ($qSearch !== '') {
        $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR dr.review LIKE :q3)';
        $params[':q1'] = "%{$qSearch}%"; $params[':q2'] = "%{$qSearch}%"; $params[':q3'] = "%{$qSearch}%";
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $base = 'FROM destination_reviews dr LEFT JOIN users u ON dr.user_id = u.id LEFT JOIN destinations d ON dr.destination_id = d.id';

    $countStmt = $db->prepare("SELECT COUNT(*) as c {$base} {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) { $qPage = $pages; }
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare("SELECT dr.id, dr.rating, dr.review, dr.is_hidden, dr.created_at, u.name as user_name, u.email as user_email, d.name as dest_name {$base} {$whereClause} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'rows'      => $rows,
        'total'     => $total,
        'pages'     => $pages,
        'page'      => $qPage,
        'per_page'  => $perPage,
        'stats'     => [
            'total'         => (int)($stats['total'] ?? 0),
            'visible'       => (int)($stats['visible'] ?? 0),
            'hidden_count'  => (int)($stats['hidden_count'] ?? 0),
            'avg_rating'    => round((float)($stats['avg_rating'] ?? 0), 1),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || (($_POST['ajax'] ?? '') === '1');
    $sendJson = function (array $payload): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!verify_token($_POST['csrf_token'] ?? '')) {
        if ($isAjax) $sendJson(['ok' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        flash_message('error', 'Invalid security token.');
        redirect('/admin/reviews.php');
    }

    $action = $_POST['action'] ?? '';
    $reviewId = (int)($_POST['review_id'] ?? 0);
    $done = null;

    if ($action === 'hide_review' && $reviewId) {
        $reviewModel->hide($reviewId);
        ActivityLog::log($_SESSION['user_id'], 'review_hide', "Hidden review #{$reviewId}");
        $done = 'Review hidden.';
    } elseif ($action === 'unhide_review' && $reviewId) {
        $reviewModel->unhide($reviewId);
        ActivityLog::log($_SESSION['user_id'], 'review_unhide', "Unhid review #{$reviewId}");
        $done = 'Review restored.';
    } elseif ($action === 'delete_review' && $reviewId) {
        $reviewModel->delete($reviewId);
        ActivityLog::log($_SESSION['user_id'], 'review_delete', "Deleted review #{$reviewId}");
        $done = 'Review deleted permanently.';
    }

    if ($done !== null) {
        if ($isAjax) $sendJson(['ok' => true, 'message' => $done]);
        flash_message('success', $done);
    }
    redirect('/admin/reviews.php?' . http_build_query($_GET));
}

$destinations = $db->query("SELECT id, name FROM destinations WHERE status='active' ORDER BY name ASC")->fetchAll();

render_page('admin', 'reviews.php', 'Review Moderation', function () use ($destinations, $stats, $destFilter, $hiddenFilter, $search, $csrf) {
?>

<style>
.page-hero{background:linear-gradient(135deg,rgba(12,110,94,.9) 0%,rgba(6,95,70,.95) 100%);color:#fff;border-radius:20px;padding:32px 36px;margin-bottom:1.5rem;position:relative;overflow:hidden}.page-hero::before{content:'';position:absolute;top:-50%;right:-15%;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.page-hero h4{font-weight:800;margin-bottom:4px;position:relative;z-index:1}.page-hero p{opacity:.85;font-size:.9rem;position:relative;z-index:1;margin-bottom:0}
.stat-card{border:none;border-radius:16px;overflow:hidden;transition:all .3s;background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9)}.stat-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.stat-card .stat-bar{height:4px;width:100%}.stat-card .stat-body{padding:18px 16px;text-align:center}.stat-card .stat-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}.stat-card .stat-value{font-size:1.6rem;font-weight:800;line-height:1;margin-bottom:4px}.stat-card .stat-label{font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px;margin-bottom:1rem}.filter-card .form-control,.filter-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}.filter-card .form-label{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.sticky-filter{position:sticky;top:74px;z-index:1015;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.filter-input-wrap{position:relative}.filter-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted,#94a3b8);font-size:.82rem;pointer-events:none}.filter-input{padding-left:34px}
.qtab-group{display:inline-flex;background:var(--bg-tertiary,#f1f5f9);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:4px;gap:2px}.qtab{border:none;background:transparent;color:var(--text-muted,#64748b);font-size:.8rem;font-weight:600;padding:7px 16px;border-radius:9px;transition:all .2s;cursor:pointer}.qtab:hover{color:var(--text-primary,#1e293b)}.qtab.active{background:#0c6e5e;color:#fff;box-shadow:0 2px 8px rgba(12,110,94,.3)}
.filter-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(12,110,94,.08);border:1px solid rgba(12,110,94,.25);color:#0c6e5e;font-size:.78rem;font-weight:600;padding:4px 10px;border-radius:50px}[data-theme="dark"] .filter-chip{background:rgba(16,185,129,.12);color:#5eead4;border-color:rgba(16,185,129,.3)}.filter-chip .chip-x{border:none;background:none;color:inherit;font-size:1rem;line-height:1;padding:0 0 0 2px;cursor:pointer;opacity:.7}.filter-chip .chip-x:hover{opacity:1}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden}.logs-table{border-collapse:separate;border-spacing:0;min-width:860px}.logs-table thead th{background:var(--card-bg,#f8fafc);border-bottom:2px solid var(--border-color,#e2e8f0);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#64748b);padding:14px 16px}.logs-table tbody tr{transition:all .15s}.logs-table tbody tr:hover{background:rgba(12,110,94,.02)}.logs-table tbody td{padding:14px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);vertical-align:middle;font-size:.88rem;color:var(--text-primary,#1e293b)}
.logs-table th.sortable{cursor:pointer;user-select:none;white-space:nowrap;transition:color .2s}.logs-table th.sortable:hover{color:#0c6e5e}.logs-table th.sortable.active{color:#0c6e5e}.logs-table th.sortable .th-arrow{margin-left:6px;font-size:.7rem;color:var(--text-muted,#94a3b8)}.logs-table th.sortable.active .th-arrow{color:#0c6e5e}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
.row-id{font-family:'SF Mono',Consolas,monospace;font-size:.78rem;padding:3px 10px;border-radius:6px;background:var(--border-color,#f1f5f9);color:var(--text-muted,#64748b)}.cell-main{font-weight:600;font-size:.88rem}.cell-sub{font-size:.75rem;color:var(--text-muted,#94a3b8)}.cell-review{font-size:.85rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#475569);transition:all .2s;padding:0}.action-btn:hover{border-color:#0c6e5e;color:#0c6e5e;background:rgba(12,110,94,.05)}
.act-menu{border-radius:12px;border:1px solid var(--border-color,#e2e8f0);box-shadow:0 12px 32px rgba(0,0,0,.12);padding:6px;min-width:190px;z-index:1050}.act-menu .dropdown-item{border-radius:8px;font-size:.85rem;font-weight:500;padding:8px 12px;color:var(--text-primary,#1e293b)}.act-menu .dropdown-item:hover{background:rgba(12,110,94,.06)}.act-menu .dropdown-divider{margin:4px 0;border-color:var(--border-color,#e2e8f0)}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted,#94a3b8)}.empty-state .empty-icon{width:56px;height:56px;border-radius:14px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}.empty-state h6{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b);margin-bottom:4px}.empty-state p{font-size:.82rem;margin:0}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px;cursor:pointer}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}.pagination .page-item.disabled .page-link{cursor:default}
.skel{position:relative;overflow:hidden;height:14px;border-radius:6px;background:var(--border-color,#e2e8f0)}.skel::after{content:'';position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);animation:shimmer 1.3s infinite}@keyframes shimmer{to{transform:translateX(100%)}}
.modal-content{border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff)}.modal-header{border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px}.modal-header .modal-title{font-weight:700;font-size:1rem;color:var(--text-primary,#1e293b)}.modal-body{padding:24px}.modal-footer{border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px}
.detail-card{background:var(--card-bg,#f8fafc);border:1px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px;transition:all .2s}.detail-card:hover{border-color:rgba(12,110,94,.3)}.detail-card .label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#94a3b8);margin-bottom:4px}.detail-card .value{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b)}
.app-toast{position:fixed;top:calc(var(--topbar-height) + 14px);right:24px;z-index:9999;display:flex;align-items:center;gap:8px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-left:4px solid #10b981;border-radius:12px;padding:12px 18px;font-size:.88rem;font-weight:600;color:var(--text-primary,#1e293b);box-shadow:0 12px 32px rgba(0,0,0,.15);opacity:0;transform:translateY(-8px);pointer-events:none;transition:all .3s}.app-toast.show{opacity:1;transform:translateY(0)}.app-toast.danger{border-left-color:#ef4444}
@media (max-width: 991.98px){.sticky-filter{top:12px}}
</style>

<div class="page-hero">
    <h4><i class="fas fa-comments me-2"></i>Review Moderation</h4>
    <p id="reviewsHeroInfo"><?= $stats['total'] ?? 0 ?> review<?= ($stats['total'] ?? 0) !== 1 ? 's' : '' ?> · <?= $stats['visible'] ?? 0 ?> visible · Average rating: <?= number_format($stats['avg_rating'] ?? 0, 1) ?>/5</p>
</div>

<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['id'=>'kpiTotal','val'=>$stats['total']??0, 'label'=>'Total Reviews','icon'=>'fa-comments','color'=>'#3b82f6','bg'=>'#dbeafe'],
        ['id'=>'kpiVisible','val'=>$stats['visible']??0, 'label'=>'Visible','icon'=>'fa-eye','color'=>'#10b981','bg'=>'#d1fae5'],
        ['id'=>'kpiHidden','val'=>$stats['hidden_count']??0, 'label'=>'Hidden','icon'=>'fa-eye-slash','color'=>'#f59e0b','bg'=>'#fef3c7'],
        ['id'=>'kpiAvg','val'=>number_format($stats['avg_rating']??0,1), 'label'=>'Avg Rating','icon'=>'fa-star','color'=>'#f59e0b','bg'=>'#fef3c7','stars'=>true],
    ];
    foreach ($statCards as $sc): ?>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card"><div class="stat-bar" style="background:<?= $sc['color'] ?>;"></div>
            <div class="stat-body">
                <div class="stat-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;"><i class="fas <?= $sc['icon'] ?>"></i></div>
                <div class="stat-value" style="color:<?= $sc['color'] ?>;" id="<?= $sc['id'] ?>"><?= $sc['val'] ?></div>
                <div class="stat-label"><?= $sc['label'] ?></div>
                <?php if (!empty($sc['stars'])): ?><div class="mt-1" id="kpiAvgStars"><?php for ($i = 1; $i <= 5; $i++): ?><i class="fas fa-star" style="font-size:.7rem;color:<?= $i <= round($stats['avg_rating'] ?? 0) ? '#f59e0b' : 'var(--text-muted,#d1d5db)' ?>;"></i><?php endfor; ?></div><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="filter-card sticky-filter">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label">Search</label>
            <div class="filter-input-wrap">
                <i class="fas fa-search filter-input-icon"></i>
                <input type="text" id="filterSearch" class="form-control filter-input" placeholder="Search name, email, review..." value="<?= sanitize($search) ?>">
            </div>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label">Destination</label>
            <select id="filterDestination" class="form-select">
                <option value="">All Destinations</option>
                <?php foreach ($destinations as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $destFilter == $d['id'] ? 'selected' : '' ?>><?= sanitize($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end justify-content-md-end">
            <div class="qtab-group">
                <button type="button" class="qtab" data-tab="" data-default>All</button>
                <button type="button" class="qtab" data-tab="0">Visible</button>
                <button type="button" class="qtab" data-tab="1">Hidden</button>
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-3" id="reviewsChips" style="display:none;"></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div><span class="small fw-semibold" style="color:var(--text-muted,#64748b);" id="reviewsCount"></span></div>
    <button type="button" class="btn btn-sm action-btn" id="reviewsRefresh" title="Refresh"><i class="fas fa-rotate"></i></button>
</div>

<div class="table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table logs-table align-middle mb-0">
                <thead><tr>
                    <th class="sortable" data-sort="id">ID <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="user">User <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="destination">Destination <i class="fas fa-sort th-arrow"></i></th>
                    <th class="sortable" data-sort="rating">Rating <i class="fas fa-sort th-arrow"></i></th>
                    <th>Review</th>
                    <th>Status</th>
                    <th class="sortable" data-sort="date">Date <i class="fas fa-sort th-arrow"></i></th>
                    <th class="text-center">Actions</th>
                </tr></thead>
                <tbody id="reviewsBody">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <tr><?php for ($c = 0; $c < 8; $c++): ?><td><div class="skel"></div></td><?php endfor; ?></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<nav class="mt-3" id="reviewsPager"></nav>

<div class="modal fade" id="reviewQuickViewModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-comments me-2" style="color:#f59e0b;"></i>Review Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-2">
            <div class="col-6"><div class="detail-card"><div class="label">Tourist</div><div class="value" id="qvUser"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Destination</div><div class="value" id="qvDest"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Rating</div><div class="value" id="qvStars"></div></div></div>
            <div class="col-6"><div class="detail-card"><div class="label">Status</div><div class="value" id="qvStatus"></div></div></div>
            <div class="col-12"><div class="detail-card"><div class="label">Email</div><div class="value" id="qvEmail" style="font-weight:400;font-size:.85rem;"></div></div></div>
            <div class="col-12"><div class="detail-card"><div class="label">Review</div><div class="value" id="qvText" style="font-weight:400;font-size:.88rem;line-height:1.6;white-space:pre-wrap;"></div></div></div>
            <div class="col-12"><div class="detail-card"><div class="label">Date</div><div class="value" id="qvDate" style="font-weight:400;font-size:.85rem;"></div></div></div>
        </div>
    </div>
    <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-sm" id="qvDelete" style="color:#ef4444;border:1px solid rgba(239,68,68,.3);border-radius:10px;font-weight:600;"><i class="fas fa-trash me-1"></i>Delete</button>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm" id="qvToggle" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"></button>
            <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);">Close</button>
        </div>
    </div>
</div></div></div>

<div class="app-toast" id="appToast"></div>

<script>
(function () {
    var CSRF = <?= json_encode($csrf) ?>;
    var INIT = <?= json_encode(['destination' => $destFilter, 'hidden' => $hiddenFilter, 'search' => $search], JSON_UNESCAPED_UNICODE) ?>;

    var state = {
        page: 1,
        sort: 'date',
        dir: 'desc',
        destination: INIT.destination || '',
        hidden: INIT.hidden === '' ? '' : String(INIT.hidden),
        search: INIT.search || ''
    };
    var timer = null;

    var $body = document.getElementById('reviewsBody');
    var $pager = document.getElementById('reviewsPager');
    var $count = document.getElementById('reviewsCount');
    var $chips = document.getElementById('reviewsChips');

    function esc(s) { s = (s == null) ? '' : String(s); var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function trunc(s, n) { s = s || ''; return s.length > n ? s.slice(0, n) + '\u2026' : s; }
    function fmtDate(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
    function starsHtml(n) { var h = ''; for (var i = 1; i <= 5; i++) { h += '<i class="fas fa-star" style="font-size:.75rem;color:' + (i <= n ? '#f59e0b' : 'var(--text-muted,#d1d5db)') + ';"></i>'; } return h; }
    function statusChip(label, bg, color, icon) { return '<span class="status-chip" style="background:' + bg + ';color:' + color + ';"><i class="fas ' + icon + ' me-1"></i>' + label + '</span>'; }

    function qs() {
        var p = new URLSearchParams();
        p.set('ajax', '1');
        p.set('page', state.page);
        p.set('sort', state.sort);
        p.set('dir', state.dir);
        if (state.destination) p.set('destination', state.destination);
        if (state.hidden !== '') p.set('hidden', state.hidden);
        if (state.search) p.set('search', state.search);
        return p.toString();
    }

    function skeletonRows(n) {
        var h = '';
        for (var i = 0; i < n; i++) { h += '<tr>'; for (var c = 0; c < 8; c++) { h += '<td><div class="skel"></div></td>'; } h += '</tr>'; }
        return h;
    }

    function renderRows(rows) {
        window.__rv = {};
        if (!rows || !rows.length) {
            $body.innerHTML = '<tr><td colspan="8" class="empty-state"><div class="empty-icon" style="background:rgba(245,158,11,.1);color:#f59e0b;"><i class="fas fa-comments"></i></div><h6>No reviews found</h6><p>Try adjusting your filters.</p></td></tr>';
            return;
        }
        var h = '';
        for (var k = 0; k < rows.length; k++) {
            var r = rows[k];
            window.__rv[r.id] = r;
            var stars = starsHtml(r.rating);
            var status = r.is_hidden == 1
                ? statusChip('Hidden', '#fef3c7', '#d97706', 'fa-eye-slash')
                : statusChip('Visible', '#d1fae5', '#059669', 'fa-eye');
            var showHide = r.is_hidden == 1
                ? '<li><button class="dropdown-item" data-act="unhide" data-id="' + r.id + '"><i class="fas fa-eye me-2 text-success"></i>Show review</button></li>'
                : '<li><button class="dropdown-item" data-act="hide" data-id="' + r.id + '"><i class="fas fa-eye-slash me-2" style="color:#f59e0b;"></i>Hide review</button></li>';
            var menu = '<div class="dropdown">' +
                '<button class="action-btn" data-bs-toggle="dropdown" title="Actions"><i class="fas fa-ellipsis-vertical"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end act-menu">' +
                '<li><button class="dropdown-item" data-act="view" data-id="' + r.id + '"><i class="fas fa-eye me-2"></i>View details</button></li>' +
                showHide +
                '<li><hr class="dropdown-divider"></li>' +
                '<li><button class="dropdown-item text-danger" data-act="delete" data-id="' + r.id + '"><i class="fas fa-trash me-2"></i>Delete</button></li>' +
                '</ul></div>';
            h += '<tr>' +
                '<td><span class="row-id">#' + r.id + '</span></td>' +
                '<td><div class="cell-main">' + (esc(r.user_name) || 'N/A') + '</div><div class="cell-sub">' + esc(r.user_email) + '</div></td>' +
                '<td><div class="cell-main">' + (esc(r.dest_name) || 'N/A') + '</div></td>' +
                '<td>' + stars + '</td>' +
                '<td><div class="cell-review" title="' + esc(r.review) + '">' + (r.review ? esc(trunc(r.review, 80)) : '<span style="color:var(--text-muted,#94a3b8);">No text</span>') + '</div></td>' +
                '<td>' + status + '</td>' +
                '<td><div class="cell-sub">' + esc(fmtDate(r.created_at)) + '</div></td>' +
                '<td class="text-center">' + menu + '</td>' +
                '</tr>';
        }
        $body.innerHTML = h;
        updateSortIndicators();
    }

    function pageItem(p, pages, enabled, label) {
        return '<li class="page-item ' + (enabled ? '' : 'disabled') + '"><a class="page-link" href="#" data-page="' + (enabled ? p : '') + '" tabindex="-1">' + label + '</a></li>';
    }

    function renderPager(pages, cur) {
        if (pages <= 1) { $pager.innerHTML = ''; return; }
        var h = '<ul class="pagination justify-content-center mb-0">';
        h += pageItem(cur - 1, pages, cur > 1, '<i class="fas fa-chevron-left"></i>');
        var start = Math.max(1, cur - 2), end = Math.min(pages, cur + 2);
        for (var i = start; i <= end; i++) {
            h += '<li class="page-item ' + (i === cur ? 'active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }
        h += pageItem(cur + 1, pages, cur < pages, '<i class="fas fa-chevron-right"></i>');
        h += '</ul>';
        $pager.innerHTML = h;
    }

    function renderCount(total, shown) {
        $count.textContent = shown === undefined
            ? total + ' review' + (total !== 1 ? 's' : '') + ' found'
            : 'Showing ' + shown + ' of ' + total + ' review' + (total !== 1 ? 's' : '');
    }

    function updateStats(s) {
        if (!s) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
        set('kpiTotal', s.total);
        set('kpiVisible', s.visible);
        set('kpiHidden', s.hidden_count);
        set('kpiAvg', (parseFloat(s.avg_rating) || 0).toFixed(1));
        var sr = document.getElementById('kpiAvgStars');
        if (sr) sr.innerHTML = starsHtml(Math.round(parseFloat(s.avg_rating) || 0));
        set('reviewsHeroInfo', s.total + ' review' + (s.total !== 1 ? 's' : '') + ' \u00b7 ' + s.visible + ' visible \u00b7 Average rating: ' + (parseFloat(s.avg_rating) || 0).toFixed(1) + '/5');
    }

    function updateChips() {
        $chips.innerHTML = '';
        var add = function (label, clearFn) {
            var c = document.createElement('span');
            c.className = 'filter-chip';
            c.innerHTML = '<span>' + esc(label) + '</span><button type="button" class="chip-x" aria-label="Clear">&times;</button>';
            c.querySelector('.chip-x').addEventListener('click', function () { clearFn(); load(); });
            $chips.appendChild(c);
        };
        if (state.hidden !== '') add(state.hidden === '1' ? 'Status: Hidden' : 'Status: Visible', function () { state.hidden = ''; setTabActive(''); });
        if (state.destination) {
            var sel = document.getElementById('filterDestination');
            add('Destination: ' + (sel.selectedOptions[0] ? sel.selectedOptions[0].text : state.destination), function () { state.destination = ''; sel.value = ''; });
        }
        if (state.search) add('Search: ' + state.search, function () { state.search = ''; document.getElementById('filterSearch').value = ''; });
        $chips.style.display = $chips.children.length ? 'flex' : 'none';
    }

    function updateSortIndicators() {
        var ths = document.querySelectorAll('th.sortable');
        for (var i = 0; i < ths.length; i++) {
            var th = ths[i], col = th.getAttribute('data-sort'), ic = th.querySelector('.th-arrow');
            if (!ic) continue;
            if (col === state.sort) {
                ic.className = 'fas ' + (state.dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                th.classList.add('active');
            } else {
                ic.className = 'fas fa-sort';
                th.classList.remove('active');
            }
        }
    }

    function setTabActive(val) {
        var tabs = document.querySelectorAll('.qtab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === val);
        }
    }

    function toast(msg, type) {
        var box = document.getElementById('appToast');
        box.className = 'app-toast show' + (type === 'danger' ? ' danger' : '');
        box.innerHTML = '<i class="fas ' + (type === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i><span>' + esc(msg) + '</span>';
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { box.classList.remove('show'); }, 3000);
    }

    function load() {
        $body.innerHTML = skeletonRows(8);
        fetch('reviews.php?' + qs())
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                if (data.rows === undefined) throw new Error('bad payload');
                state.page = data.page || 1;
                renderRows(data.rows);
                renderPager(data.pages, data.page);
                renderCount(data.total, data.rows.length);
                updateStats(data.stats);
                updateChips();
                updateSortIndicators();
            })
            .catch(function () {
                $body.innerHTML = '<tr><td colspan="8" class="empty-state"><div class="empty-icon" style="background:rgba(239,68,68,.1);color:#ef4444;"><i class="fas fa-triangle-exclamation"></i></div><h6>Could not load reviews</h6><p>Please try again.</p></td></tr>';
            });
    }

    function closeMenu(btn) {
        var dd = btn.closest('.dropdown');
        if (dd) {
            var t = dd.querySelector('[data-bs-toggle="dropdown"]');
            if (t) t.setAttribute('aria-expanded', 'false');
            var m = dd.querySelector('.dropdown-menu');
            if (m) m.classList.remove('show');
            dd.classList.remove('show');
        }
    }

    function doAction(id, action, confirmMsg) {
        if (!window.confirm(confirmMsg)) return;
        var fd = new FormData();
        fd.append('ajax', '1');
        fd.append('csrf_token', CSRF);
        fd.append('action', action);
        fd.append('review_id', id);
        fetch('reviews.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) { toast(d.message || 'Done.', 'success'); load(); }
                else { toast((d && d.message) || 'Action failed.', 'danger'); }
            })
            .catch(function () { toast('Request failed. Check your connection.', 'danger'); });
    }

    function openView(id) {
        var r = window.__rv[id];
        if (!r) return;
        document.getElementById('qvUser').textContent = r.user_name || 'N/A';
        document.getElementById('qvEmail').textContent = r.user_email || '\u2014';
        document.getElementById('qvDest').textContent = r.dest_name || 'N/A';
        document.getElementById('qvStars').innerHTML = starsHtml(r.rating) + ' <span style="font-weight:600;color:var(--text-muted,#64748b);font-size:.8rem;">' + r.rating + '/5</span>';
        document.getElementById('qvText').textContent = r.review || 'No review text.';
        document.getElementById('qvStatus').innerHTML = r.is_hidden == 1
            ? statusChip('Hidden', '#fef3c7', '#d97706', 'fa-eye-slash')
            : statusChip('Visible', '#d1fae5', '#059669', 'fa-eye');
        document.getElementById('qvDate').textContent = fmtDate(r.created_at);
        var t = document.getElementById('qvToggle');
        t.setAttribute('data-id', id);
        t.setAttribute('data-act', r.is_hidden == 1 ? 'unhide' : 'hide');
        t.innerHTML = r.is_hidden == 1 ? '<i class="fas fa-eye me-1"></i>Show review' : '<i class="fas fa-eye-slash me-1"></i>Hide review';
        document.getElementById('qvDelete').setAttribute('data-id', id);
        if (window.bootstrap) new bootstrap.Modal(document.getElementById('reviewQuickViewModal')).show();
    }

    $pager.addEventListener('click', function (e) {
        var a = e.target.closest('a.page-link');
        if (!a) return;
        e.preventDefault();
        var p = parseInt(a.getAttribute('data-page'), 10);
        if (!p) return;
        state.page = p;
        load();
    });

    $body.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        e.preventDefault();
        var id = parseInt(btn.getAttribute('data-id'), 10);
        var act = btn.getAttribute('data-act');
        closeMenu(btn);
        if (act === 'view') openView(id);
        else if (act === 'hide') doAction(id, 'hide_review', 'Hide this review from public view?');
        else if (act === 'unhide') doAction(id, 'unhide_review', 'Restore this review to public view?');
        else if (act === 'delete') doAction(id, 'delete_review', 'Delete this review permanently?');
    });

    document.getElementById('qvToggle').addEventListener('click', function (e) {
        var b = e.currentTarget;
        doAction(b.getAttribute('data-id'), b.getAttribute('data-act'), b.getAttribute('data-act') === 'unhide' ? 'Restore this review to public view?' : 'Hide this review from public view?');
        var m = bootstrap.Modal.getInstance(document.getElementById('reviewQuickViewModal'));
        if (m) m.hide();
    });

    document.getElementById('qvDelete').addEventListener('click', function (e) {
        var b = e.currentTarget;
        doAction(b.getAttribute('data-id'), 'delete_review', 'Delete this review permanently?');
        var m = bootstrap.Modal.getInstance(document.getElementById('reviewQuickViewModal'));
        if (m) m.hide();
    });

    document.querySelectorAll('th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = th.getAttribute('data-sort');
            if (state.sort === col) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
            else { state.sort = col; state.dir = (col === 'user' || col === 'destination') ? 'asc' : 'desc'; }
            state.page = 1;
            load();
        });
    });

    document.querySelectorAll('.qtab').forEach(function (b) {
        b.addEventListener('click', function () {
            state.hidden = b.getAttribute('data-tab');
            state.page = 1;
            setTabActive(state.hidden);
            load();
        });
    });

    document.getElementById('filterDestination').addEventListener('change', function () {
        state.destination = this.value;
        state.page = 1;
        load();
    });

    document.getElementById('filterSearch').addEventListener('input', function () {
        var v = this.value;
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (state.search !== v) { state.search = v; state.page = 1; load(); }
        }, 400);
    });

    document.getElementById('reviewsRefresh').addEventListener('click', function () { load(); });

    setTabActive(state.hidden);
    updateSortIndicators();
    load();
})();
</script>

<?php }); ?>
