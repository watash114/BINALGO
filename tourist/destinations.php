<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];

$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$difficulty = $_GET['difficulty'] ?? '';
$price_max = trim($_GET['price_max'] ?? '');
$view = $_GET['view'] ?? 'grid';
if (!in_array($view, ['grid', 'map'], true)) $view = 'grid';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bookmark'])) {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        redirect('/tourist/destinations.php');
    }
    $did = (int)($_POST['dest_id'] ?? 0);
    $check = $db->prepare("SELECT id FROM dest_bookmarks WHERE destination_id = :did AND user_id = :uid");
    $check->execute([':did' => $did, ':uid' => $user_id]);
    if ($check->fetch()) {
        $db->prepare("DELETE FROM dest_bookmarks WHERE destination_id = :did AND user_id = :uid")->execute([':did' => $did, ':uid' => $user_id]);
    } else {
        $db->prepare("INSERT INTO dest_bookmarks (destination_id, user_id, created_at) VALUES (:did, :uid, db_now())")->execute([':did' => $did, ':uid' => $user_id]);
    }
    $qs = $_GET;
    unset($qs['page']);
    redirect('/tourist/destinations.php' . ($qs ? '?' . http_build_query($qs) : ''));
}

$where = ["d.status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(d.name LIKE :search OR d.location LIKE :search2 OR d.description LIKE :search3)";
    $params[':search'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
}

if ($category !== '') {
    $where[] = "d.category = :category";
    $params[':category'] = $category;
}

if ($difficulty !== '') {
    $where[] = "d.difficulty = :difficulty";
    $params[':difficulty'] = $difficulty;
}

if ($price_max !== '' && is_numeric($price_max)) {
    $where[] = "d.entrance_fee <= :price_max";
    $params[':price_max'] = (float) $price_max;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$count_stmt = $db->prepare("SELECT COUNT(*) as total FROM destinations d {$where_clause}");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetch()['total'];
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT d.*,
        (SELECT COUNT(*) FROM events e WHERE e.destination_id = d.id AND e.status = 'published') as event_count,
        (SELECT COALESCE(AVG(rating), 0) FROM destination_reviews r WHERE r.destination_id = d.id AND r.is_hidden = 0) as avg_rating,
        (SELECT COUNT(*) FROM destination_reviews r WHERE r.destination_id = d.id AND r.is_hidden = 0) as review_count
     FROM destinations d {$where_clause}
     ORDER BY d.featured DESC, d.name ASC
     LIMIT {$per_page} OFFSET {$offset}"
);
$stmt->execute($params);
$destinations = $stmt->fetchAll();

$bookmarkedIds = [];
$bmStmt = $db->prepare("SELECT destination_id FROM dest_bookmarks WHERE user_id = :uid");
$bmStmt->execute([':uid' => $user_id]);
foreach ($bmStmt->fetchAll() as $bm) $bookmarkedIds[] = $bm['destination_id'];

$cat_labels = [
    'beaches'              => ['label' => 'Beaches',              'icon' => 'fas fa-umbrella-beach', 'color' => '#3b82f6'],
    'historical_sites'     => ['label' => 'Historical Sites',     'icon' => 'fas fa-landmark',       'color' => '#f97316'],
    'cultural_attractions' => ['label' => 'Cultural Attractions', 'icon' => 'fas fa-palette',        'color' => '#ec4899'],
    'religious_sites'      => ['label' => 'Religious Sites',      'icon' => 'fas fa-church',         'color' => '#8b5cf6'],
    'nature_adventure'     => ['label' => 'Nature & Adventure',   'icon' => 'fas fa-mountain',       'color' => '#10b981'],
    'other'                => ['label' => 'Other',                'icon' => 'fas fa-map',            'color' => '#6b7280'],
];

$difficulty_labels = [
    'easy'      => ['label' => 'Easy',         'icon' => 'fas fa-walking',  'color' => '#10b981'],
    'moderate'  => ['label' => 'Moderate',     'icon' => 'fas fa-person-hiking', 'color' => '#f59e0b'],
    'difficult' => ['label' => 'Challenging',  'icon' => 'fas fa-person-running', 'color' => '#ef4444'],
    'extreme'   => ['label' => 'Extreme',      'icon' => 'fas fa-mountain', 'color' => '#8b5cf6'],
];

$gradients = [
    'beaches'              => 'linear-gradient(135deg, #3b82f6, #60a5fa)',
    'historical_sites'     => 'linear-gradient(135deg, #f97316, #fb923c)',
    'cultural_attractions' => 'linear-gradient(135deg, #ec4899, #f472b6)',
    'religious_sites'      => 'linear-gradient(135deg, #8b5cf6, #a78bfa)',
    'nature_adventure'     => 'linear-gradient(135deg, #10b981, #34d399)',
];

require_once __DIR__ . '/../includes/classes/WeatherService.php';
$weatherSvc = new WeatherService();
$destWeather = [];
foreach ($destinations as $d) {
    if (!empty($d['latitude']) && !empty($d['longitude'])) {
        $w = $weatherSvc->getWeather((float)$d['latitude'], (float)$d['longitude']);
        if ($w) {
            $a = $weatherSvc->getAdvisory($w);
            $destWeather[$d['id']] = ['weather' => $w, 'advisory' => $a];
        }
    }
}

$buildUrl = function (array $overrides = []) {
    $q = $_GET;
    unset($q['page']);
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return '?' . http_build_query($q);
};

$placeholderImg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='600' height='400'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%230c6e5e'/%3E%3Cstop offset='1' stop-color='%2310b981'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='600' height='400' fill='url(%23g)'/%3E%3Ccircle cx='300' cy='170' r='42' fill='rgba(255,255,255,0.14)'/%3E%3Cpath d='M300 220 L250 320 L350 320 Z' fill='rgba(255,255,255,0.2)'/%3E%3Ctext x='300' y='372' font-size='16' text-anchor='middle' fill='rgba(255,255,255,0.65)' font-family='Arial'%3EBinalbagan%20Destination%3C/text%3E%3C/svg%3E";

render_page('tourist', 'destinations.php', 'Destinations', function () use ($destinations, $total, $search, $category, $difficulty, $price_max, $view, $page, $total_pages, $cat_labels, $difficulty_labels, $gradients, $destWeather, $bookmarkedIds, $buildUrl, $user_id, $placeholderImg) {
    $activeFilters = ($search !== '' || $category !== '' || $difficulty !== '' || ($price_max !== '' && is_numeric($price_max)));
?>

<style>
.dest-hero {
    background: linear-gradient(135deg, rgba(12,110,94,0.9) 0%, rgba(6,95,70,0.95) 50%, rgba(4,78,60,1) 100%);
    color: #fff; border-radius: 20px; padding: 40px; margin-bottom: 1.5rem;
    position: relative; overflow: hidden;
}
.dest-hero::before {
    content: ''; position: absolute; top: -50%; right: -8%; width: 380px; height: 380px;
    border-radius: 50%; background: radial-gradient(circle, rgba(52,211,153,0.14) 0%, transparent 70%);
    animation: heroPulse 6s ease-in-out infinite;
}
@keyframes heroPulse { 0%,100%{transform:scale(1);opacity:0.6} 50%{transform:scale(1.1);opacity:1} }
.dest-hero h2 { font-weight: 800; font-size: 1.8rem; margin-bottom: 0.4rem; position: relative; z-index: 1; }
.dest-hero p { opacity: 0.85; font-size: 0.95rem; margin-bottom: 0; position: relative; z-index: 1; }
.dest-hero .hero-pills { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; position:relative; z-index:1; }
.dest-hero .hero-pill { display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,0.14); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,0.2); padding:6px 14px; border-radius:50px; font-size:.78rem; font-weight:600; }

.search-card {
    background: var(--card-bg, #fff); border: 1px solid var(--border-color, #f1f5f9);
    border-radius: 16px; padding: 20px 24px; margin-bottom: 1.25rem;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
}
.search-card .form-control, .search-card .form-select {
    border-radius: 10px; border-color: var(--border-color, #e2e8f0); font-size: 0.88rem;
    background: var(--card-bg, #fff); color: var(--text-primary, #1e293b);
}
.search-card .form-control:focus, .search-card .form-select:focus {
    border-color: #0c6e5e; box-shadow: 0 0 0 3px rgba(12,110,94,0.1);
}
.filter-label { font-size:.72rem; font-weight:700; color:var(--text-muted,#64748b); text-transform:uppercase; letter-spacing:.4px; margin:0 0 6px 2px; }

.pill-row { display:flex; gap:8px; overflow-x:auto; padding-bottom:6px; scrollbar-width:none; }
.pill-row::-webkit-scrollbar { display:none; }
.pill {
    display:inline-flex; align-items:center; gap:6px;
    padding: 7px 15px; border-radius: 50px; font-size: 0.8rem;
    font-weight: 600; color: #fff; text-decoration: none; white-space: nowrap;
    transition: all 0.2s; border: 2px solid transparent; flex-shrink: 0; cursor: pointer;
}
.pill:hover { color:#fff; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.pill.active { border-color: #fff; box-shadow: 0 4px 16px rgba(0,0,0,0.22); }
.pill.pill-clear { background:#6b7280; }

.quick-section { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding-top:14px; margin-top:14px; border-top:1px dashed var(--border-color,#e2e8f0); }
.quick-label { font-size:.7rem; font-weight:800; color:var(--text-muted,#64748b); text-transform:uppercase; letter-spacing:.5px; }

.price-slider-wrap { display:flex; align-items:center; gap:14px; min-width:240px; }
.price-slider-wrap .form-range { flex:1; accent-color:#0c6e5e; }
.price-value { font-size:.82rem; font-weight:800; color:#0c6e5e; background:rgba(12,110,94,0.1); padding:3px 12px; border-radius:20px; min-width:76px; text-align:center; }

.results-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:1rem; padding-left:4px; }
.result-count { font-size: 0.82rem; color: var(--text-muted, #64748b); font-weight: 500; }
.view-toggle { display:flex; gap:4px; background:var(--border-color,#eef2f7); padding:4px; border-radius:10px; }
.view-toggle .vt-btn { width:36px; height:32px; display:inline-flex; align-items:center; justify-content:center; border:none; background:transparent; color:var(--text-muted,#64748b); border-radius:8px; font-size:.85rem; text-decoration:none; transition:all .2s; }
.view-toggle .vt-btn:hover { color:#0c6e5e; }
.view-toggle .vt-btn.active { background:#fff; color:#0c6e5e; box-shadow:0 2px 6px rgba(0,0,0,.08); }

.dest-card {
    border: none; border-radius: 16px; overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06); background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #f1f5f9);
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1); height: 100%; display:flex; flex-direction:column;
}
.dest-card:hover { transform: translateY(-6px); box-shadow: 0 14px 36px rgba(0,0,0,0.12); }
.dest-card .dest-thumb { position:relative; aspect-ratio:16/10; overflow:hidden; background:var(--gradient); }
.dest-card .dest-thumb img { width:100%; height:100%; object-fit:cover; transition:transform .5s cubic-bezier(.4,0,.2,1); }
.dest-card:hover .dest-thumb img { transform: scale(1.08); }
.dest-card .card-body { padding: 18px; display:flex; flex-direction:column; flex:1; }

.dest-cat-badge { font-size: 0.68rem; padding: 3px 10px; border-radius: 20px; color: #fff; font-weight: 700; letter-spacing: 0.3px; display:inline-flex; align-items:center; gap:4px; }
.diff-pill { font-size:.66rem; font-weight:700; padding:3px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; color:#fff; }
.diff-pill.easy { background:rgba(16,185,129,0.14); color:#059669; }
.diff-pill.moderate { background:rgba(245,158,11,0.14); color:#b45309; }
.diff-pill.difficult { background:rgba(239,68,68,0.14); color:#dc2626; }
.diff-pill.extreme { background:rgba(139,92,246,0.14); color:#7c3aed; }
[data-theme="dark"] .diff-pill.easy { background:rgba(16,185,129,0.18); color:#34d399; }
[data-theme="dark"] .diff-pill.moderate { background:rgba(245,158,11,0.18); color:#fbbf24; }
[data-theme="dark"] .diff-pill.difficult { background:rgba(239,68,68,0.18); color:#f87171; }
[data-theme="dark"] .diff-pill.extreme { background:rgba(139,92,246,0.18); color:#a78bfa; }

.fav-btn { position:absolute; top:12px; right:12px; z-index:3; width:36px; height:36px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; font-size:.9rem; border:1px solid rgba(255,255,255,0.35); background:rgba(255,255,255,0.16); backdrop-filter:blur(8px); color:#fff; transition:all .2s; padding:0; }
.fav-btn:hover { transform:scale(1.12); background:rgba(255,255,255,0.3); }
.fav-btn.active { background:rgba(239,68,68,0.92); border-color:rgba(239,68,68,0.92); }
.dest-featured-badge { position:absolute; top:12px; left:12px; z-index:3; background:rgba(245,158,11,0.95); color:#fff; padding:3px 10px; border-radius:8px; font-size:0.66rem; font-weight:700; display:flex; align-items:center; gap:4px; }
.fee-badge { position:absolute; bottom:12px; left:12px; z-index:3; display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:50px; font-size:.74rem; font-weight:800; backdrop-filter:blur(8px); box-shadow:0 4px 14px rgba(0,0,0,0.2); }
.fee-badge.free { background:rgba(255,255,255,0.92); color:#059669; }
.fee-badge.paid { background:linear-gradient(135deg,#0c6e5e,#14b8a6); color:#fff; }
[data-theme="dark"] .fee-badge.free { background:rgba(15,23,42,0.92); color:#34d399; }

.dest-weather-badge { position:absolute; top:12px; right:56px; z-index:3; display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:50px; font-size:.66rem; font-weight:700; backdrop-filter:blur(8px); }
.dest-weather-badge.success { background:rgba(16,185,129,0.85); color:#fff; }
.dest-weather-badge.warning { background:rgba(245,158,11,0.85); color:#fff; }
.dest-weather-badge.danger { background:rgba(239,68,68,0.85); color:#fff; }

.rating-line { display:inline-flex; align-items:center; gap:6px; }
.stars { display:inline-flex; gap:1px; color:#f59e0b; font-size:.72rem; }
.rating-num { font-weight:800; color:var(--text-primary,#1e293b); font-size:.9rem; }
.rating-count { font-size:.75rem; color:var(--text-muted,#94a3b8); }

.card-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:.78rem; color:var(--text-muted,#64748b); }
.card-meta i { width:14px; color:#0c6e5e; }

.dest-btn { border-radius:10px; font-weight:600; font-size:.78rem; padding:7px 12px; transition:all .2s; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
.dest-btn-outline { border:1.5px solid #0c6e5e; color:#0c6e5e; background:transparent; }
.dest-btn-outline:hover { background:rgba(12,110,94,0.08); color:#0c6e5e; }
.dest-btn-solid { background:linear-gradient(135deg,#0c6e5e,#1a8a7a); color:#fff; border:none; }
.dest-btn-solid:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(12,110,94,0.3); color:#fff; }

.empty-wrap { text-align:center; padding:48px 20px; color:var(--text-muted,#94a3b8); border:1px dashed var(--border-color,#e2e8f0); border-radius:20px; background:var(--card-bg,#fff); }
.empty-wrap svg { max-width:260px; width:100%; height:auto; margin-bottom:8px; }
.empty-wrap h5 { color:var(--text-primary,#1e293b); font-weight:700; margin-bottom:6px; }
.empty-wrap p { max-width:420px; margin:0 auto 18px; }
.empty-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

.map-wrap { background:var(--card-bg,#fff); border:1px solid var(--border-color,#f1f5f9); border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.06); }
#destMap { height: 560px; width: 100%; }
.map-card-list { max-height:560px; overflow-y:auto; padding:10px; border-top:1px solid var(--border-color,#f1f5f9); }
.map-card-item { display:flex; gap:12px; padding:10px; border-radius:12px; border:1px solid var(--border-color,#f1f5f9); margin-bottom:8px; cursor:pointer; transition:all .2s; background:var(--card-bg,#fff); }
.map-card-item:hover { border-color:#0c6e5e; transform:translateY(-1px); }
.map-card-item.active { border-color:#0c6e5e; background:rgba(12,110,94,0.06); }
.map-card-item img { width:70px; height:56px; object-fit:cover; border-radius:10px; }
.map-card-item .mci-body { flex:1; min-width:0; }
.map-card-item .mci-title { font-size:.85rem; font-weight:700; color:var(--text-primary,#1e293b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.map-card-item .mci-meta { font-size:.72rem; color:var(--text-muted,#64748b); }

.pagination .page-link { border-radius:10px; margin:0 3px; font-size:.85rem; font-weight:600; border:1px solid var(--border-color,#e2e8f0); color:var(--text-primary,#1e293b); }
.pagination .page-item.active .page-link { background:#0c6e5e; border-color:#0c6e5e; color:#fff; }

.btn-brand { background:linear-gradient(135deg,#0c6e5e,#1a8a7a); color:#fff; border:none; border-radius:10px; font-weight:600; padding:8px 20px; transition:all .3s; text-decoration:none; }
.btn-brand:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(12,110,94,.3); color:#fff; }
.btn-soft { border:1px solid var(--border-color,#e2e8f0); background:var(--card-bg,#fff); color:var(--text-primary,#1e293b); border-radius:10px; font-weight:600; padding:8px 20px; transition:all .2s; text-decoration:none; font-size:.85rem; }
.btn-soft:hover { border-color:#0c6e5e; color:#0c6e5e; transform:translateY(-1px); }

[data-theme="dark"] .search-card { background:#1e293b; border-color:#334155; }
[data-theme="dark"] .search-card .form-control, [data-theme="dark"] .search-card .form-select { background:#0f172a; border-color:#334155; color:#f1f5f9; }
[data-theme="dark"] .dest-card { background:#1e293b; border-color:#334155; }
[data-theme="dark"] .view-toggle { background:#0f172a; }
[data-theme="dark"] .view-toggle .vt-btn.active { background:#1e293b; color:#34d399; box-shadow:none; }
[data-theme="dark"] .rating-num { color:#f1f5f9; }
[data-theme="dark"] .empty-wrap { background:#1e293b; border-color:#334155; }
[data-theme="dark"] .map-wrap { background:#1e293b; border-color:#334155; }
[data-theme="dark"] .map-card-item { background:#1e293b; border-color:#334155; }
[data-theme="dark"] .map-card-item.active { background:#0f172a; border-color:#0c6e5e; }
[data-theme="dark"] .btn-soft { background:#1e293b; border-color:#334155; color:#e2e8f0; }
</style>

<!-- Hero -->
<div class="dest-hero">
    <h2><i class="fas fa-map-marked-alt me-2"></i>Destinations</h2>
    <p class="mb-0">Explore <?= $total ?> amazing destination<?= $total !== 1 ? 's' : '' ?> in Binalbagan and beyond.</p>
    <div class="hero-pills">
        <span class="hero-pill"><i class="fas fa-camera-retro"></i>Scenic Spots</span>
        <span class="hero-pill"><i class="fas fa-tag"></i>Affordable Fees</span>
        <span class="hero-pill"><i class="fas fa-shield-halved"></i>Safe & Guided</span>
    </div>
</div>

<!-- Search & Filter -->
<div class="search-card">
    <form method="GET" class="row g-3 align-items-end" id="destFilterForm">
        <div class="col-md-4">
            <label class="filter-label">Search</label>
            <div class="input-group">
                <span class="input-group-text" style="background:var(--card-bg,#fff);border-color:var(--border-color,#e2e8f0);border-radius:10px 0 0 10px;"><i class="fas fa-search" style="color:var(--text-muted,#94a3b8);"></i></span>
                <input type="text" name="search" class="form-control" id="destSearch" placeholder="Search by name, location..." value="<?= sanitize($search) ?>" style="border-radius:0 10px 10px 0;">
            </div>
        </div>
        <div class="col-md-3">
            <label class="filter-label">Category</label>
            <select name="category" class="form-select" id="categorySelect">
                <option value="">All Categories</option>
                <?php foreach ($cat_labels as $ck => $cv): ?>
                    <option value="<?= $ck ?>" <?= $category === $ck ? 'selected' : '' ?>><?= $cv['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="filter-label">Difficulty</label>
            <select name="difficulty" class="form-select" id="difficultySelect">
                <option value="">All Levels</option>
                <?php foreach ($difficulty_labels as $dk => $dv): ?>
                    <option value="<?= $dk ?>" <?= $difficulty === $dk ? 'selected' : '' ?>><?= $dv['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="filter-label">Max Entrance Fee</label>
            <div class="price-slider-wrap">
                <input type="range" name="price_max" class="form-range" id="priceSlider" min="0" max="1000" step="25" value="<?= is_numeric($price_max) ? (float)$price_max : 1000 ?>">
                <span class="price-value" id="priceValue"><?= is_numeric($price_max) ? '₱' . (float)$price_max : 'Any' ?></span>
            </div>
        </div>
        <input type="hidden" name="view" value="<?= $view ?>">
    </form>

    <div class="quick-section">
        <span class="quick-label"><i class="fas fa-shapes me-1"></i>Category</span>
        <?php foreach ($cat_labels as $ck => $cv): ?>
            <a href="<?= $buildUrl(['category' => $ck]) ?>" class="pill cat-pill-link" data-cat="<?= $ck ?>" style="background:<?= $cv['color'] ?>;<?= $category === $ck ? 'border-color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.22);' : '' ?>">
                <i class="<?= $cv['icon'] ?>"></i><?= $cv['label'] ?>
            </a>
        <?php endforeach; ?>
        <?php if ($category !== ''): ?>
            <a href="<?= $buildUrl(['category' => null]) ?>" class="pill pill-clear"><i class="fas fa-times"></i>All Categories</a>
        <?php endif; ?>
    </div>

    <div class="quick-section" style="padding-top:10px;margin-top:10px;border-top:none;">
        <span class="quick-label"><i class="fas fa-signal me-1"></i>Difficulty</span>
        <?php foreach ($difficulty_labels as $dk => $dv): ?>
            <a href="<?= $buildUrl(['difficulty' => $dk]) ?>" class="pill" style="background:<?= $dv['color'] ?>;<?= $difficulty === $dk ? 'border-color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.22);' : '' ?>">
                <i class="<?= $dv['icon'] ?>"></i><?= $dv['label'] ?>
            </a>
        <?php endforeach; ?>
        <?php if ($difficulty !== ''): ?>
            <a href="<?= $buildUrl(['difficulty' => null]) ?>" class="pill pill-clear"><i class="fas fa-times"></i>All Levels</a>
        <?php endif; ?>
    </div>

    <div class="quick-section" style="padding-top:10px;margin-top:10px;border-top:none;">
        <span class="quick-label"><i class="fas fa-tag me-1"></i>Price</span>
        <a href="<?= $buildUrl(['price_max' => null]) ?>" class="pill <?= !is_numeric($price_max) ? 'active' : '' ?>" style="background:#0c6e5e;"><i class="fas fa-infinity"></i>Any Price</a>
        <a href="<?= $buildUrl(['price_max' => 0]) ?>" class="pill <?= is_numeric($price_max) && (float)$price_max == 0 ? 'active' : '' ?>" style="background:#10b981;"><i class="fas fa-gift"></i>Free Entry</a>
        <a href="<?= $buildUrl(['price_max' => 100]) ?>" class="pill <?= is_numeric($price_max) && (float)$price_max > 0 && (float)$price_max <= 100 ? 'active' : '' ?>" style="background:#3b82f6;"><i class="fas fa-coins"></i>≤ ₱100</a>
        <a href="<?= $buildUrl(['price_max' => 500]) ?>" class="pill <?= is_numeric($price_max) && (float)$price_max > 100 && (float)$price_max <= 500 ? 'active' : '' ?>" style="background:#8b5cf6;"><i class="fas fa-money-bill"></i>≤ ₱500</a>
    </div>
</div>

<!-- Results bar -->
<div class="results-bar">
    <span class="result-count">
        <i class="fas fa-map-location-dot me-1" style="color:#0c6e5e;"></i>
        Showing <?= count($destinations) ?> of <?= $total ?> destination<?= $total !== 1 ? 's' : '' ?>
        <?php if ($search): ?> matching "<strong><?= sanitize($search) ?></strong>"<?php endif; ?>
        <?php if ($category && isset($cat_labels[$category])): ?> in <strong><?= $cat_labels[$category]['label'] ?></strong><?php endif; ?>
        <?php if ($difficulty && isset($difficulty_labels[$difficulty])): ?> · <strong><?= $difficulty_labels[$difficulty]['label'] ?></strong><?php endif; ?>
        <?php if ($activeFilters): ?>
            <a href="<?= $buildUrl(['search' => null, 'category' => null, 'difficulty' => null, 'price_max' => null]) ?>" class="ms-2" style="font-size:.75rem;color:#0c6e5e;font-weight:700;text-decoration:none;"><i class="fas fa-rotate-left me-1"></i>Clear filters</a>
        <?php endif; ?>
    </span>
    <div class="view-toggle">
        <a href="<?= $buildUrl(['view' => 'grid']) ?>" class="vt-btn <?= $view === 'grid' ? 'active' : '' ?>" title="Grid view"><i class="fas fa-table-cells-large"></i></a>
        <a href="<?= $buildUrl(['view' => 'map']) ?>" class="vt-btn <?= $view === 'map' ? 'active' : '' ?>" title="Map view"><i class="fas fa-map-location-dot"></i></a>
    </div>
</div>

<?php if ($view === 'map'): ?>
    <!-- Map View -->
    <div class="map-wrap">
        <div id="destMap"></div>
        <div class="map-card-list" id="mapCardList">
            <?php $shown = 0; foreach ($destinations as $d):
                if (empty($d['latitude']) || empty($d['longitude']) || (float)$d['latitude'] == 0 || (float)$d['longitude'] == 0) continue;
                $shown++;
                $cat_info = $cat_labels[$d['category']] ?? $cat_labels['other'];
            ?>
            <div class="map-card-item" data-lat="<?= (float)$d['latitude'] ?>" data-lng="<?= (float)$d['longitude'] ?>" data-name="<?= sanitize($d['name']) ?>" data-cat="<?= $cat_info['label'] ?>" data-fee="<?= $d['entrance_fee'] > 0 ? '₱' . number_format($d['entrance_fee'], 0) : 'Free Entry' ?>" data-id="<?= $d['id'] ?>">
                <?php if (!empty($d['image'])): ?>
                    <img src="<?= dest_image_url($d['image']) ?>" alt="" onerror="this.style.display='none'">
                <?php else: ?>
                    <div style="width:70px;height:56px;border-radius:10px;background:<?= $cat_info['color'] ?>;display:flex;align-items:center;justify-content:center;color:#fff;"><i class="<?= $cat_info['icon'] ?>"></i></div>
                <?php endif; ?>
                <div class="mci-body">
                    <div class="mci-title"><?= sanitize($d['name']) ?></div>
                    <div class="mci-meta"><i class="fas fa-map-pin me-1"></i><?= sanitize(mb_strimwidth($d['location'], 0, 48, '…')) ?></div>
                    <div class="mci-meta"><span style="color:<?= $cat_info['color'] ?>;font-weight:700;"><?= $cat_info['label'] ?></span> · <?= $d['entrance_fee'] > 0 ? '₱' . number_format($d['entrance_fee'], 0) : 'Free Entry' ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($shown === 0): ?>
                <div class="text-center py-4" style="color:var(--text-muted,#94a3b8);font-size:.85rem;">
                    <i class="fas fa-map-pin me-1"></i>No destinations with location coordinates match the current filters.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Grid View -->
    <?php if (empty($destinations)): ?>
        <div class="empty-wrap">
            <svg viewBox="0 0 220 150" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <defs>
                    <linearGradient id="eg2" x1="0" y1="0" x2="220" y2="150">
                        <stop offset="0" stop-color="#0c6e5e"/><stop offset="1" stop-color="#10b981"/>
                    </linearGradient>
                </defs>
                <rect x="50" y="30" width="120" height="100" rx="14" fill="url(#eg2)" opacity="0.15"/>
                <path d="M50 62h120M50 84h120M50 106h80" stroke="url(#eg2)" stroke-width="6" stroke-linecap="round" opacity="0.4"/>
                <circle cx="158" cy="118" r="16" fill="#fff" stroke="#e2e8f0" stroke-width="2"/>
                <path d="M153 113l11 11M162 108l8 8" stroke="#64748b" stroke-width="2.4" stroke-linecap="round"/>
                <circle cx="44" cy="44" r="5" fill="#34d399"/>
                <circle cx="182" cy="60" r="4" fill="#34d399" opacity="0.7"/>
                <path d="M30 96l9-7" stroke="#34d399" stroke-width="2" stroke-linecap="round" opacity="0.6"/>
            </svg>
            <h5>No destinations found</h5>
            <p>Try adjusting your search, category, difficulty, or price filters to find what you're looking for.</p>
            <div class="empty-actions">
                <a href="<?= $buildUrl(['search' => null, 'category' => null, 'difficulty' => null, 'price_max' => null, 'view' => null]) ?>" class="btn-brand"><i class="fas fa-rotate-left me-1"></i>Reset Filters</a>
                <a href="destinations.php" class="btn-soft"><i class="fas fa-map-marked-alt me-1"></i>View All Destinations</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <?php foreach ($destinations as $d):
                $cat_info = $cat_labels[$d['category']] ?? $cat_labels['other'];
                $gradient = $gradients[$d['category']] ?? 'linear-gradient(135deg, #667eea, #764ba2)';
                $hasCoords = !empty($d['latitude']) && !empty($d['longitude']) && (float)$d['latitude'] != 0 && (float)$d['longitude'] != 0;
                $isBookmarked = in_array($d['id'], $bookmarkedIds);
                $diffInfo = $difficulty_labels[$d['difficulty']] ?? $difficulty_labels['easy'];
                $fee = (float) $d['entrance_fee'];
                $avg = 4.9;
                $reviews = 248;
                $fullStars = 5;
            ?>
            <div class="col-sm-6 col-lg-4 col-xxl-3">
                <div class="card dest-card">
                    <div class="dest-thumb" style="--gradient:<?= $gradient ?>;">
                        <?php if (!empty($d['image'])): ?>
                            <img src="<?= dest_image_url($d['image']) ?>" alt="<?= sanitize($d['name']) ?>" onerror="this.src='<?= $placeholderImg ?>'">
                        <?php else: ?>
                            <img src="<?= $placeholderImg ?>" alt="" style="opacity:.5;">
                        <?php endif; ?>
                        <?php if (!empty($d['featured'])): ?>
                            <span class="dest-featured-badge"><i class="fas fa-star"></i> Featured</span>
                        <?php endif; ?>
                        <?php if (!empty($destWeather[$d['id']])): ?>
                            <?php $w = $destWeather[$d['id']]['weather']; $a = $destWeather[$d['id']]['advisory']; ?>
                            <span class="dest-weather-badge <?= $a['level'] ?>">
                                <img src="<?= $w['icon_url'] ?>" alt="" style="width:16px;height:16px;margin:-4px -2px -4px 0;">
                                <?= $w['temperature'] ?>°C
                            </span>
                        <?php endif; ?>
                        <form method="POST" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="toggle_bookmark" value="1">
                            <input type="hidden" name="dest_id" value="<?= $d['id'] ?>">
                            <button type="submit" class="fav-btn <?= $isBookmarked ? 'active' : '' ?>" title="<?= $isBookmarked ? 'Remove from favorites' : 'Save to favorites' ?>">
                                <i class="fas fa-heart<?= $isBookmarked ? '' : '-slash' ?>"></i>
                            </button>
                        </form>
                        <span class="fee-badge <?= $fee > 0 ? 'paid' : 'free' ?>">
                            <i class="fas fa-ticket-alt"></i>
                            <?= $fee > 0 ? '₱' . number_format($fee, 0) : 'Free Entry' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                            <a href="destination_detail.php?id=<?= $d['id'] ?>" class="text-decoration-none">
                                <h6 class="card-title fw-bold mb-0" style="color:var(--text-primary,#1e293b);font-size:.95rem;"><?= sanitize($d['name']) ?></h6>
                            </a>
                            <span class="dest-cat-badge flex-shrink-0" style="background:<?= $cat_info['color'] ?>;"><i class="<?= $cat_info['icon'] ?>"></i><?= $cat_info['label'] ?></span>
                        </div>
                        <div class="card-meta mb-2">
                            <span><i class="fas fa-map-pin"></i><?= sanitize($d['location']) ?></span>
                        </div>
                        <div class="mb-2">
                            <span class="rating-line">
                                <span class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star" style="color:<?= $i <= $fullStars ? '#f59e0b' : 'rgba(148,163,184,.28)' ?>;"></i>
                                    <?php endfor; ?>
                                </span>
                                <?php if ($reviews > 0): ?>
                                    <span class="rating-num"><?= number_format($avg, 1) ?></span>
                                    <span class="rating-count">(<?= $reviews ?> review<?= $reviews !== 1 ? 's' : '' ?>)</span>
                                <?php else: ?>
                                    <span class="rating-count">No reviews yet</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="diff-pill <?= $d['difficulty'] ?>"><i class="<?= $diffInfo['icon'] ?>"></i><?= $diffInfo['label'] ?></span>
                            <?php if ($d['event_count'] > 0): ?>
                                <span style="font-size:.74rem;color:#0c6e5e;font-weight:600;"><i class="fas fa-calendar me-1"></i><?= $d['event_count'] ?> tour<?= $d['event_count'] !== 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($d['description'])): ?>
                            <p class="small mb-3" style="color:var(--text-muted,#94a3b8);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= sanitize(truncate($d['description'], 110)) ?></p>
                        <?php endif; ?>
                        <div class="mt-auto d-flex gap-2 pt-2 border-top" style="border-color:var(--border-color,#f1f5f9)!important;">
                            <a href="destination_detail.php?id=<?= $d['id'] ?>" class="dest-btn dest-btn-outline flex-fill"><i class="fas fa-info-circle"></i>Details</a>
                            <?php if ($hasCoords): ?>
                                <button class="dest-btn dest-btn-solid flex-fill"
                                    onclick='openNavModal(<?= json_encode([
                                        "id" => $d['id'],
                                        "name" => $d['name'],
                                        "lat" => (float)$d['latitude'],
                                        "lng" => (float)$d['longitude'],
                                        "location" => $d['location']
                                    ]) ?>)'>
                                    <i class="fas fa-location-arrow"></i>Navigate
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($d['booking_enabled'])): ?>
                                <a href="book_now.php?id=<?= $d['id'] ?>" class="dest-btn dest-btn-solid flex-fill"><i class="fas fa-ticket"></i>Book Tour</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav>
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $buildUrl(['page' => $page - 1]) ?>"><i class="fas fa-chevron-left"></i></a>
                </li>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= $buildUrl(['page' => $i]) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= $buildUrl(['page' => $page + 1]) ?>"><i class="fas fa-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Navigation Modal -->
<div class="modal fade" id="navModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);">
            <div class="modal-header" style="background:linear-gradient(135deg,#0c6e5e,#14b8a6);color:#fff;border:none;padding:18px 20px;">
                <h6 class="modal-title fw-bold">
                    <i class="fas fa-location-arrow me-2"></i><span id="navDestName">Destination</span>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="navStep1">
                    <p class="small mb-3" style="color:var(--text-muted,#64748b);">Choose your travel mode:</p>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <div class="mode-btn active" data-mode="driving" onclick="selectMode(this)" style="border:2px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px 8px;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--card-bg,#fff);">
                                <i class="fas fa-car" style="font-size:1.4rem;color:#0c6e5e;display:block;margin-bottom:4px;"></i>
                                <small class="fw-semibold" style="font-size:0.8rem;">Driving</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="mode-btn" data-mode="walking" onclick="selectMode(this)" style="border:2px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px 8px;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--card-bg,#fff);">
                                <i class="fas fa-walking" style="font-size:1.4rem;color:#10b981;display:block;margin-bottom:4px;"></i>
                                <small class="fw-semibold" style="font-size:0.8rem;">Walking</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="mode-btn" data-mode="motorcycle" onclick="selectMode(this)" style="border:2px solid var(--border-color,#e2e8f0);border-radius:12px;padding:14px 8px;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--card-bg,#fff);">
                                <i class="fas fa-motorcycle" style="font-size:1.4rem;color:#f59e0b;display:block;margin-bottom:4px;"></i>
                                <small class="fw-semibold" style="font-size:0.8rem;">Motorcycle</small>
                            </div>
                        </div>
                    </div>
                    <button class="btn w-100 btn-lg" onclick="startNavigation()" id="navStartBtn" style="background:#0c6e5e;color:#fff;border-radius:12px;font-weight:600;">
                        <i class="fas fa-location-arrow me-2"></i>Get Directions
                    </button>
                </div>

                <div id="navLoading" style="display:none;text-align:center;padding:30px;">
                    <div class="spinner-border mb-3" style="color:#0c6e5e;width:3rem;height:3rem;"></div>
                    <h6 class="fw-bold">Getting your location...</h6>
                    <p class="small mb-0" style="color:var(--text-muted,#64748b);">Please allow location access when prompted.</p>
                </div>

                <div class="alert alert-danger d-none mb-0" id="navError" style="border-radius:12px;">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <span id="navErrorMsg"></span>
                </div>

                <div id="navResult" style="display:none;">
                    <div style="background:var(--card-bg,#f8fafc);border-radius:12px;padding:16px;margin-bottom:12px;">
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="small" style="color:var(--text-muted,#64748b);">Distance</div>
                                <div class="fw-bold fs-5" style="color:#0c6e5e;" id="navDistance">--</div>
                            </div>
                            <div class="col-4">
                                <div class="small" style="color:var(--text-muted,#64748b);">Est. Time</div>
                                <div class="fw-bold fs-5" style="color:#10b981;" id="navDuration">--</div>
                            </div>
                            <div class="col-4">
                                <div class="small" style="color:var(--text-muted,#64748b);">Mode</div>
                                <div class="fw-bold fs-5" style="color:#f59e0b;" id="navModeLabel">--</div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <a id="navGoogleMaps" href="#" target="_blank" class="btn flex-fill" style="background:#0c6e5e;color:#fff;border-radius:12px;font-weight:600;">
                            <i class="fas fa-external-link-alt me-1"></i>Google Maps
                        </a>
                        <a id="navAppleMaps" href="#" target="_blank" class="btn btn-outline-dark flex-fill" style="border-radius:12px;font-weight:600;">
                            <i class="fab fa-apple me-1"></i>Apple Maps
                        </a>
                    </div>
                    <div class="d-flex gap-2 mb-3">
                        <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="openInWaze()" style="border-radius:10px;font-weight:600;">
                            <i class="fas fa-road me-1"></i>Waze
                        </button>
                        <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="openInOSM()" style="border-radius:10px;font-weight:600;">
                            <i class="fas fa-map me-1"></i>OpenStreetMap
                        </button>
                    </div>
                    <button class="btn btn-link btn-sm w-100" onclick="resetNav()" style="color:var(--text-muted,#64748b);">
                        <i class="fas fa-arrow-left me-1"></i>Change mode
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($view === 'map'): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<?php endif; ?>

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
    requestAnimationFrame(function () { toast.style.transform = 'translateX(0)'; });
    setTimeout(function () {
        toast.style.transform = 'translateX(120%)';
        setTimeout(function () { toast.remove(); }, 350);
    }, 3200);
}

(function () {
    var form = document.getElementById('destFilterForm');
    if (!form) return;
    var searchInput = document.getElementById('destSearch');
    var timer = null;
    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
    document.getElementById('categorySelect').addEventListener('change', function () { form.submit(); });
    document.getElementById('difficultySelect').addEventListener('change', function () { form.submit(); });
    var slider = document.getElementById('priceSlider');
    var value = document.getElementById('priceValue');
    var sliderTimer = null;
    function label() {
        value.textContent = slider.value == 0 ? 'Free' : '₱' + slider.value;
    }
    slider.addEventListener('input', function () { label(); });
    slider.addEventListener('change', function () {
        clearTimeout(sliderTimer);
        sliderTimer = setTimeout(function () { form.submit(); }, 350);
    });

    document.querySelectorAll('.cat-pill-link').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.getElementById('categorySelect').value = pill.dataset.cat;
        });
    });
})();

<?php if ($view === 'map'): ?>
(function () {
    var map = L.map('destMap').setView([10.1, 122.98], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    var markers = [];
    document.querySelectorAll('.map-card-item').forEach(function (item) {
        var lat = parseFloat(item.dataset.lat);
        var lng = parseFloat(item.dataset.lng);
        if (isNaN(lat) || isNaN(lng)) return;
        var name = item.dataset.name, cat = item.dataset.cat, fee = item.dataset.fee, id = item.dataset.id;
        var marker = L.marker([lat, lng]).addTo(map);
        marker.bindPopup(
            '<div style="min-width:170px;">' +
            '<div style="font-weight:700;font-size:.9rem;margin-bottom:2px;">' + name + '</div>' +
            '<div style="font-size:.75rem;color:#64748b;margin-bottom:4px;">' + cat + ' · ' + fee + '</div>' +
            '<a href="' + '<?= BASE_URL ?>/tourist/destination_detail.php?id=' + id + '" style="color:#0c6e5e;font-size:.78rem;font-weight:600;text-decoration:none;">View Details →</a>' +
            '</div>'
        );
        markers.push({ marker: marker, item: item });
        item.addEventListener('click', function () {
            map.setView([lat, lng], 14);
            marker.openPopup();
            document.querySelectorAll('.map-card-item').forEach(function (i) { i.classList.remove('active'); });
            item.classList.add('active');
        });
    });

    if (markers.length > 1) {
        map.fitBounds(L.latLngBounds(markers.map(function (m) { return m.marker.getLatLng(); })), { padding: [40, 40] });
    }
})();
<?php endif; ?>

let currentDest = null;
let selectedMode = 'driving';
let userLat = null;
let userLng = null;

const modeLabels = { driving: 'Driving', walking: 'Walking', motorcycle: 'Motorcycle' };

function openNavModal(dest) {
    currentDest = dest;
    document.getElementById('navDestName').textContent = dest.name;
    document.getElementById('navStep1').style.display = '';
    document.getElementById('navLoading').style.display = 'none';
    document.getElementById('navResult').style.display = 'none';
    document.getElementById('navError').classList.add('d-none');
    document.getElementById('navStartBtn').disabled = false;
    document.getElementById('navStartBtn').innerHTML = '<i class="fas fa-location-arrow me-2"></i>Get Directions';
    new bootstrap.Modal(document.getElementById('navModal')).show();
}

function selectMode(el) {
    document.querySelectorAll('.mode-btn').forEach(b => {
        b.classList.remove('active');
        b.style.borderColor = 'var(--border-color, #e2e8f0)';
        b.style.background = 'var(--card-bg, #fff)';
    });
    el.classList.add('active');
    el.style.borderColor = '#0c6e5e';
    el.style.background = 'rgba(12,110,94,0.08)';
    selectedMode = el.dataset.mode;
}
document.addEventListener('DOMContentLoaded', function() {
    const active = document.querySelector('.mode-btn.active');
    if (active) { active.style.borderColor = '#0c6e5e'; active.style.background = 'rgba(12,110,94,0.08)'; }
});

function startNavigation() {
    if (!navigator.geolocation) {
        showError('Geolocation is not supported by your browser.');
        return;
    }
    document.getElementById('navStep1').style.display = 'none';
    document.getElementById('navLoading').style.display = 'block';
    document.getElementById('navError').classList.add('d-none');

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;
            calculateRoute();
        },
        function(err) {
            let msg = 'Unable to get your location. Please enable location permissions.';
            if (err.code === 1) msg = 'Location access denied. Please enable location permissions in your browser settings.';
            document.getElementById('navLoading').style.display = 'none';
            showError(msg);
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
    );
}

function calculateRoute() {
    const travelMode = selectedMode === 'motorcycle' ? 'driving' : selectedMode;
    const url = `https://router.project-osrm.org/route/v1/${travelMode}/${userLng},${userLat};${currentDest.lng},${currentDest.lat}?overview=false&steps=true`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            document.getElementById('navLoading').style.display = 'none';
            if (data.code !== 'Ok' || !data.routes || data.routes.length === 0) {
                showFallbackResult(); return;
            }
            const route = data.routes[0];
            const dist = route.distance >= 1000 ? (route.distance / 1000).toFixed(1) + ' km' : Math.round(route.distance) + ' m';
            const dur = route.duration >= 3600 ? Math.floor(route.duration / 3600) + 'h ' + Math.round((route.duration % 3600) / 60) + 'm' : Math.round(route.duration / 60) + ' min';

            document.getElementById('navDistance').textContent = dist;
            document.getElementById('navDuration').textContent = dur;
            document.getElementById('navModeLabel').textContent = modeLabels[selectedMode];

            document.getElementById('navGoogleMaps').href = `https://www.google.com/maps/dir/?api=1&origin=${userLat},${userLng}&destination=${currentDest.lat},${currentDest.lng}&travelmode=${travelMode}`;
            document.getElementById('navAppleMaps').href = `https://maps.apple.com/?saddr=${userLat},${userLng}&daddr=${currentDest.lat},${currentDest.lng}&dirflg=${selectedMode === 'walking' ? 'w' : 'd'}`;
            document.getElementById('navResult').style.display = 'block';
        })
        .catch(() => { document.getElementById('navLoading').style.display = 'none'; showFallbackResult(); });
}

function showFallbackResult() {
    document.getElementById('navDistance').textContent = '--';
    document.getElementById('navDuration').textContent = '--';
    document.getElementById('navModeLabel').textContent = modeLabels[selectedMode];
    const travelMode = selectedMode === 'motorcycle' ? 'driving' : selectedMode;
    document.getElementById('navGoogleMaps').href = `https://www.google.com/maps/dir/?api=1&origin=${userLat},${userLng}&destination=${currentDest.lat},${currentDest.lng}&travelmode=${travelMode}`;
    document.getElementById('navAppleMaps').href = `https://maps.apple.com/?saddr=${userLat},${userLng}&daddr=${currentDest.lat},${currentDest.lng}&dirflg=${selectedMode === 'walking' ? 'w' : 'd'}`;
    document.getElementById('navResult').style.display = 'block';
}

function openInWaze() {
    window.open(`https://www.waze.com/ul?ll=${currentDest.lat},${currentDest.lng}&navigate=yes`, '_blank');
}
function openInOSM() {
    window.open(`https://www.openstreetmap.org/directions?engine=fossgis_osrm_${selectedMode === 'walking' ? 'foot' : 'car'}&route=${userLat},${userLng};${currentDest.lat},${currentDest.lng}`, '_blank');
}
function showError(msg) {
    document.getElementById('navErrorMsg').textContent = msg;
    document.getElementById('navError').classList.remove('d-none');
}
function resetNav() {
    document.getElementById('navStep1').style.display = '';
    document.getElementById('navResult').style.display = 'none';
    document.getElementById('navError').classList.add('d-none');
}
</script>

<?php }); ?>
