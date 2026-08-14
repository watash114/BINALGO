<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

require_once __DIR__ . '/../includes/classes/DestinationReview.php';
require_once __DIR__ . '/../includes/classes/Notification.php';
require_once __DIR__ . '/../includes/classes/Message.php';

$db = Database::getInstance()->getConnection();
$reviewModel = new DestinationReview();
$destModel = new Destination();
$user = current_user();
$destId = (int)($_GET['id'] ?? 0);

if (!$destId) redirect('/tourist/destinations.php');

$dest = $destModel->findById($destId);
if (!$dest || $dest['status'] !== 'active') {
    flash_message('error', 'Destination not found.');
    redirect('/tourist/destinations.php');
}

$cat_labels = [
    'beaches'              => ['label' => 'Beaches',              'icon' => 'fas fa-umbrella-beach',  'color' => '#3b82f6'],
    'heritage_culture'     => ['label' => 'Heritage & Culture',   'icon' => 'fas fa-landmark',        'color' => '#f97316'],
    'religious_sites'      => ['label' => 'Religious Sites',      'icon' => 'fas fa-church',          'color' => '#8b5cf6'],
    'nature_adventure'     => ['label' => 'Nature & Adventure',   'icon' => 'fas fa-mountain',        'color' => '#10b981'],
    'food_local'           => ['label' => 'Food & Local',         'icon' => 'fas fa-utensils',        'color' => '#ec4899'],
    'other'                => ['label' => 'Tourist Spot',         'icon' => 'fas fa-map-pin',         'color' => '#6b7280'],
];
$catInfo = $cat_labels[$dest['category']] ?? $cat_labels['other'];
$catIcon = $catInfo['icon'];
$catColor = $catInfo['color'];

$galleryImages = !empty($dest['gallery_images']) ? json_decode($dest['gallery_images'], true) : [];
$season_stmt = $db->prepare("SELECT * FROM destination_seasons WHERE destination_id = :id ORDER BY season_type");
$season_stmt->execute([':id' => $destId]);
$seasons = $season_stmt->fetchAll();

$events_stmt = $db->prepare(
    "SELECT e.*, s.id as schedule_id, s.start_date, s.start_time, s.end_date, s.end_time, s.available_spots, d.name as dest_name
     FROM events e
     JOIN schedules s ON s.event_id = e.id
     LEFT JOIN destinations d ON e.destination_id = d.id
     WHERE e.destination_id = :dest_id AND e.status = 'published' AND s.status = 'scheduled' AND s.start_date >= CURDATE()
     ORDER BY s.start_date ASC"
);
$events_stmt->execute([':dest_id' => $destId]);
$events = $events_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/tourist/destination_detail.php?id=' . $destId);
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_review') {
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
        $review = sanitize(trim($_POST['review'] ?? ''));
        if ($rating > 0 && $review) {
            $reviewModel->create([
                'destination_id' => $destId,
                'user_id' => $_SESSION['user_id'],
                'rating' => $rating,
                'review' => $review,
            ]);
            flash_message('success', 'Review submitted!');
        } else {
            flash_message('error', 'Please provide a rating and review.');
        }
        redirect('/tourist/destination_detail.php?id=' . $destId);
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit_review' && isset($_POST['review_id'])) {
        $rid = (int)$_POST['review_id'];
        $existing = $reviewModel->getUserReview($destId, $_SESSION['user_id']);
        if ($existing && $existing['id'] === $rid) {
            $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
            $review = sanitize(trim($_POST['review'] ?? ''));
            $reviewModel->update($rid, ['rating' => $rating, 'review' => $review]);
            flash_message('success', 'Review updated.');
        }
        redirect('/tourist/destination_detail.php?id=' . $destId);
    }

    if (isset($_POST['toggle_bookmark'])) {
        $did = (int)($_POST['dest_id'] ?? 0);
        if ($did > 0) {
            $chk = $db->prepare("SELECT id FROM dest_bookmarks WHERE destination_id = :did AND user_id = :uid");
            $chk->execute([':did' => $did, ':uid' => $_SESSION['user_id']]);
            if ($chk->fetch()) {
                $db->prepare("DELETE FROM dest_bookmarks WHERE destination_id = :did AND user_id = :uid")->execute([':did' => $did, ':uid' => $_SESSION['user_id']]);
            } else {
                $db->prepare("INSERT INTO dest_bookmarks (destination_id, user_id, created_at) VALUES (:did, :uid, NOW())")->execute([':did' => $did, ':uid' => $_SESSION['user_id']]);
            }
        }
        redirect('/tourist/destination_detail.php?id=' . $destId);
    }
}

$stats = $reviewModel->getStats($destId);
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['review_page'] ?? 1));
$reviewData = $reviewModel->getByDestination($destId, $sort, $page);
$userReview = $reviewModel->getUserReview($destId, $_SESSION['user_id']);
$hasCoords = !empty($dest['latitude']) && !empty($dest['longitude']);
$mapUrl = $hasCoords ? "https://www.google.com/maps/dir/?api=1&destination={$dest['latitude']},{$dest['longitude']}&travelmode=driving" : '#';
$feePrice = $dest['booking_price'] > 0 ? $dest['booking_price'] : $dest['entrance_fee'];

$isBookmarked = false;
$bm = $db->prepare("SELECT id FROM dest_bookmarks WHERE destination_id = :did AND user_id = :uid");
$bm->execute([':did' => $destId, ':uid' => $_SESSION['user_id']]);
$isBookmarked = (bool) $bm->fetchColumn();

// Weather
$weatherData = null;
$weatherAdvisory = null;
if ($hasCoords) {
    require_once __DIR__ . '/../includes/classes/WeatherService.php';
    $weatherSvc = new WeatherService();
    $weatherData = $weatherSvc->getWeather((float)$dest['latitude'], (float)$dest['longitude']);
    if ($weatherData) {
        $weatherAdvisory = $weatherSvc->getAdvisory($weatherData);
    }
}

render_page('tourist', 'destination_detail.php', $dest['name'], function() use ($dest, $destId, $catInfo, $catIcon, $catColor, $galleryImages, $seasons, $events, $stats, $sort, $page, $reviewData, $userReview, $mapUrl, $hasCoords, $db, $reviewModel, $weatherData, $weatherAdvisory, $feePrice, $isBookmarked) {
?>

<style>
:root { --cat-color: <?= $catColor ?>; }

/* Hero */
.dd-hero { position:relative; }
.dd-hero-img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.dd-hero-overlay { position:absolute; inset:0; background:linear-gradient(180deg, rgba(15,23,42,0.3) 0%, rgba(15,23,42,0.1) 40%, rgba(15,23,42,0.6) 70%, rgba(15,23,42,0.85) 100%); }
.dd-hero-bottom { z-index:2; padding-bottom:20px !important; background:linear-gradient(transparent, rgba(15,23,42,0.5)); }
.dd-hero-badge { display:inline-flex; align-items:center; padding:4px 12px; border-radius:50px; font-size:.72rem; font-weight:600; color:#fff; backdrop-filter:blur(4px); }

/* Highlight Tags */
.dd-highlight-tag { display:inline-flex; align-items:center; padding:5px 12px; border-radius:50px; font-size:.75rem; font-weight:600; background:var(--cat-highlight-bg, rgba(12,110,94,0.08)); color:var(--cat-highlight-text, var(--cat-color)); border:1px solid var(--cat-highlight-border, rgba(12,110,94,0.15)); }

/* Gallery Grid */
.dd-gallery-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; }
.dd-gallery-grid .dd-gallery-item { position:relative; border-radius:12px; overflow:hidden; cursor:pointer; aspect-ratio:1; transition:all .3s; }
.dd-gallery-grid .dd-gallery-item:hover { transform:scale(1.03); box-shadow:0 8px 24px rgba(0,0,0,0.15); }
.dd-gallery-grid .dd-gallery-item img { width:100%; height:100%; object-fit:cover; }
.dd-gallery-item:first-child { grid-column:span 2; grid-row:span 2; aspect-ratio:auto; }
.dd-gallery-count { position:absolute; bottom:8px; right:8px; background:rgba(15,23,42,0.8); backdrop-filter:blur(4px); color:#fff; padding:4px 10px; border-radius:8px; font-size:.7rem; font-weight:600; display:flex; align-items:center; }

/* Lightbox */
.dd-lightbox { display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.92); align-items:center; justify-content:center; }
.dd-lightbox.active { display:flex; }
.dd-lightbox img { max-width:90vw; max-height:85vh; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.5); }
.dd-lightbox-close { position:absolute; top:20px; right:20px; width:44px; height:44px; border-radius:50%; background:rgba(255,255,255,0.15); border:none; color:#fff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; }
.dd-lightbox-close:hover { background:rgba(255,255,255,0.3); }
.dd-lightbox-prev, .dd-lightbox-next { position:absolute; top:50%; transform:translateY(-50%); width:48px; height:48px; border-radius:50%; background:rgba(255,255,255,0.15); border:none; color:#fff; font-size:1.2rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; }
.dd-lightbox-prev { left:20px; }
.dd-lightbox-next { right:20px; }
.dd-lightbox-prev:hover, .dd-lightbox-next:hover { background:rgba(255,255,255,0.3); }
.dd-lightbox-counter { position:absolute; bottom:24px; left:50%; transform:translateX(-50%); color:rgba(255,255,255,0.7); font-size:.85rem; font-weight:500; }

/* Info Grid */
.dd-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border-color,#e2e8f0); border-radius:12px; overflow:hidden; }
.dd-info-cell { background:var(--card-bg,#fff); padding:14px 16px; display:flex; flex-direction:column; gap:4px; }
.dd-info-label { font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:var(--text-muted,#94a3b8); }
.dd-info-value { font-size:.92rem; font-weight:700; color:var(--text-primary,#1e293b); }
.dd-info-badge { display:inline-flex; align-self:flex-start; padding:3px 10px; border-radius:50px; font-size:.75rem; font-weight:700; color:#fff; }

/* Directions Button */
.dd-btn-directions { background:#0c6e5e !important; color:#fff !important; border:none !important; border-radius:12px !important; font-weight:600 !important; padding:12px !important; font-size:.9rem !important; transition:all .2s !important; box-shadow:0 4px 14px rgba(12,110,94,0.3) !important; }
.dd-btn-directions:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(12,110,94,0.4) !important; color:#fff !important; }
.dd-btn-nav-disabled { border:1.5px solid #fca5a5 !important; background:rgba(239,68,68,0.05) !important; color:#dc2626 !important; border-radius:12px !important; cursor:not-allowed !important; opacity:.7; font-size:.88rem; font-weight:600; }

/* Map Preview */
.dd-map-preview { position:relative; border-radius:12px; overflow:hidden; cursor:pointer; }
.dd-map-fallback { width:100%; height:180px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; background:var(--border-color,#f1f5f9); border-radius:12px; }
.dd-map-overlay { position:absolute; inset:0; background:rgba(15,23,42,0.4); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:.88rem; opacity:0; transition:opacity .3s; }
.dd-map-preview:hover .dd-map-overlay { opacity:1; }

/* Empty State */
.dd-empty-state { text-align:center; padding:36px 20px; background:var(--card-bg,#fff); border:1.5px dashed var(--border-color,#e2e8f0); border-radius:14px; }
.dd-empty-icon { width:56px; height:56px; border-radius:50%; background:var(--border-color,#f1f5f9); display:flex; align-items:center; justify-content:center; margin:0 auto 12px; color:var(--border-color,#cbd5e1); font-size:1.3rem; }
.dd-empty-state h6 { font-weight:700; color:var(--text-primary,#1e293b); margin-bottom:4px; }
.dd-empty-state p { color:var(--text-muted,#94a3b8); font-size:.85rem; margin:0; }

/* Hero Actions */
.dd-hero-actions { display:flex; gap:8px; margin-top:12px; }
.dd-hero-action { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:10px; font-size:.78rem; font-weight:600; border:1.5px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.1); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); color:#fff; cursor:pointer; transition:all .25s; text-decoration:none; }
.dd-hero-action:hover { background:rgba(255,255,255,0.2); border-color:rgba(255,255,255,0.4); color:#fff; transform:translateY(-1px); }
.dd-hero-action.bookmarked { background:rgba(239,68,68,0.2); border-color:rgba(239,68,68,0.4); }
.dd-hero-rating { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:10px; font-size:.82rem; font-weight:700; color:#fff; background:rgba(245,158,11,0.2); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); border:1px solid rgba(245,158,11,0.3); }
.dd-hero-rating i { color:#f59e0b; }

/* Leaflet Map */
.dd-leaflet-map { width:100%; height:220px; border-radius:12px; z-index:1; }

/* Booking Enhanced */
.dd-booking-counter { display:flex; align-items:center; gap:0; border:1.5px solid var(--border-color,#e2e8f0); border-radius:10px; overflow:hidden; }
.dd-booking-counter button { width:36px; height:36px; border:none; background:var(--card-bg,#fff); color:var(--text-primary,#1e293b); font-size:1rem; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; }
.dd-booking-counter button:hover { background:rgba(12,110,94,0.08); color:#0c6e5e; }
.dd-booking-counter .counter-val { flex:1; text-align:center; font-weight:700; font-size:.95rem; color:var(--text-primary,#1e293b); border-left:1px solid var(--border-color,#e2e8f0); border-right:1px solid var(--border-color,#e2e8f0); padding:6px 0; min-width:40px; }
.dd-booking-total { display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-top:1.5px solid var(--border-color,#e2e8f0); margin-top:12px; }
.dd-booking-total .label { font-size:.85rem; color:var(--text-muted,#64748b); font-weight:500; }
.dd-booking-total .amount { font-size:1.3rem; font-weight:800; color:#0c6e5e; }

/* Tabs */
.dd-tabs { display:flex; gap:4px; background:var(--border-color,#f1f5f9); border-radius:12px; padding:4px; margin-bottom:20px; }
.dd-tab-btn { flex:1; padding:10px 16px; border-radius:10px; border:none; background:transparent; color:var(--text-muted,#64748b); font-size:.82rem; font-weight:600; cursor:pointer; transition:all .25s; display:flex; align-items:center; justify-content:center; gap:6px; }
.dd-tab-btn:hover { color:var(--text-primary,#1e293b); background:rgba(255,255,255,0.5); }
.dd-tab-btn.active { background:var(--card-bg,#fff); color:#0c6e5e; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.dd-tab-panel { display:none; }
.dd-tab-panel.active { display:block; }

/* Existing styles */
.card:not([class*="bg-"]) { background: var(--card-bg, #fff); }
.dd-section-header { display:flex; align-items:center; gap:10px; margin-bottom:16px; }
.dd-section-header .dd-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; }
.dd-section-header h5 { margin:0; font-weight:700; color:var(--text-primary,#1e293b); }
.dd-description { color:var(--text-muted,#64748b); line-height:1.7; font-size:.9rem; }
.dd-description.collapsed { max-height:80px; overflow:hidden; position:relative; }
.dd-description.collapsed::after { content:''; position:absolute; bottom:0; left:0; right:0; height:40px; background:linear-gradient(transparent, var(--card-bg,#fff)); }
.dd-toggle-btn { background:none; border:none; color:var(--cat-color); font-size:.82rem; font-weight:600; cursor:pointer; padding:0; margin-top:4px; }
.dd-toggle-btn:hover { text-decoration:underline; }
.dd-rating-overview { display:flex; align-items:center; gap:20px; padding:16px 20px; border-radius:14px; background:linear-gradient(135deg, <?= $catColor ?>10, <?= $catColor ?>05); border:1px solid <?= $catColor ?>20; }
.dd-rating-big { text-align:center; min-width:80px; }
.dd-rating-big .num { font-size:2.2rem; font-weight:800; color:var(--cat-color); line-height:1; }
.dd-rating-big .label { font-size:.72rem; color:var(--text-muted,#94a3b8); margin-top:2px; }
.dd-rating-bars { flex:1; }
.dd-rating-bar { display:flex; align-items:center; gap:8px; margin-bottom:3px; }
.dd-rating-bar .stars { width:28px; font-size:.75rem; font-weight:600; color:var(--text-primary,#1e293b); display:flex; align-items:center; gap:2px; }
.dd-rating-bar .stars i { color:#f59e0b; font-size:.6rem; }
.dd-rating-bar .bar-track { flex:1; height:7px; border-radius:4px; background:var(--border-color,#e2e8f0); overflow:hidden; }
.dd-rating-bar .bar-fill { height:100%; border-radius:4px; background:linear-gradient(90deg, #f59e0b, #f97316); transition:width .6s ease; }
.dd-rating-bar .pct { width:30px; text-align:right; font-size:.72rem; color:var(--text-muted,#94a3b8); font-weight:600; }
.dd-review-form { border:1.5px solid var(--border-color,#e2e8f0); border-radius:14px; padding:20px; background:var(--card-bg,#fff); transition:border-color .3s; }
.dd-review-form:focus-within { border-color:var(--cat-color); }
.dd-review-textarea { border:1.5px solid var(--border-color,#e2e8f0); border-radius:10px; padding:12px 14px; width:100%; resize:vertical; min-height:80px; font-size:.88rem; color:var(--text-primary,#1e293b); background:var(--card-bg,#fff); transition:all .2s; }
.dd-review-textarea:focus { border-color:var(--cat-color); outline:none; box-shadow:0 0 0 3px rgba(<?= hexdec(substr($catColor,1,2)) ?>,<?= hexdec(substr($catColor,3,2)) ?>,<?= hexdec(substr($catColor,5,2)) ?>,0.1); }
.btn-review-submit { background:#0c6e5e !important; color:#fff !important; border:none !important; border-radius:10px !important; padding:10px 28px; font-size:.88rem; transition:all .2s; }
.btn-review-submit:hover { background:#0a5c4f !important; transform:translateY(-1px); box-shadow:0 4px 12px rgba(12,110,94,0.3); }
.dd-review-item { padding:14px 0; border-bottom:1px solid var(--border-color,#e2e8f0); transition:background .2s; }
.dd-review-item:last-child { border-bottom:none; }
.dd-review-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.75rem; color:#fff; flex-shrink:0; }
.dd-review-header { display:flex; justify-content:space-between; align-items:flex-start; }
.dd-review-name { font-weight:700; font-size:.88rem; color:var(--text-primary,#1e293b); }
.dd-review-date { font-size:.72rem; color:var(--text-muted,#94a3b8); }
.dd-review-stars { font-size:.8rem; }
.dd-review-text { color:var(--text-muted,#64748b); font-size:.85rem; line-height:1.6; margin-top:4px; }
.weather-card { border-radius:14px; overflow:hidden; border:1.5px solid var(--border-color,#e2e8f0); background:var(--card-bg,#fff); }
.weather-card-header { padding:16px 18px 12px; position:relative; overflow:hidden; }
.weather-card-header.success { background:linear-gradient(135deg, rgba(16,185,129,0.12), rgba(16,185,129,0.04)); }
.weather-card-header.warning { background:linear-gradient(135deg, rgba(245,158,11,0.12), rgba(245,158,11,0.04)); }
.weather-card-header.danger { background:linear-gradient(135deg, rgba(239,68,68,0.12), rgba(239,68,68,0.04)); }
.weather-card-header.unavailable { background:linear-gradient(135deg, rgba(148,163,184,0.12), rgba(148,163,184,0.04)); }
.weather-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:50px; font-size:.72rem; font-weight:700; }
.weather-badge.success { background:rgba(16,185,129,0.15); color:#059669; }
.weather-badge.warning { background:rgba(245,158,11,0.15); color:#d97706; }
.weather-badge.danger { background:rgba(239,68,68,0.15); color:#dc2626; }
.weather-badge.unavailable { background:rgba(148,163,184,0.15); color:#64748b; }
.weather-main { display:flex; align-items:center; gap:12px; margin-top:10px; }
.weather-temp { font-size:2rem; font-weight:800; color:var(--text-primary,#1e293b); line-height:1; }
.weather-desc { font-size:.82rem; color:var(--text-muted,#64748b); font-weight:600; }
.weather-msg { padding:12px 18px; font-size:.82rem; line-height:1.6; color:var(--text-muted,#64748b); border-top:1px solid var(--border-color,#e2e8f0); }
.weather-stats { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border-color,#e2e8f0); }
.weather-stat { background:var(--card-bg,#fff); padding:10px 14px; display:flex; align-items:center; gap:8px; }
.weather-stat i { font-size:.75rem; width:16px; text-align:center; }
.weather-stat .ws-label { font-size:.68rem; color:var(--text-muted,#94a3b8); text-transform:uppercase; letter-spacing:.3px; }
.weather-stat .ws-value { font-size:.82rem; font-weight:700; color:var(--text-primary,#1e293b); }

@media (max-width:767px) {
    .dd-hero { min-height:240px !important; }
    .dd-gallery-grid { grid-template-columns:repeat(3, 1fr); }
    .dd-gallery-item:first-child { grid-column:span 1; grid-row:span 1; aspect-ratio:1; }
    .dd-info-grid { grid-template-columns:1fr; }
    .dd-rating-overview { flex-direction:column; text-align:center; }
    .dd-review-header { flex-direction:column; gap:4px; }
}

/* ── Amenities Chips ── */
.dd-amenities { display:flex; flex-wrap:wrap; gap:8px; }
.dd-amenity-chip { display:inline-flex; align-items:center; gap:7px; padding:7px 13px; border-radius:50px; font-size:.78rem; font-weight:600; background:var(--border-color,#f1f5f9); color:var(--text-primary,#1e293b); border:1px solid var(--border-color,#e2e8f0); transition:all .25s; }
.dd-amenity-chip:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,0.08); border-color:#0c6e5e40; }
.dd-amenity-chip i { color:<?= $catColor ?>; font-size:.85rem; }
.dd-amenity-chip.dim { opacity:.65; text-decoration:line-through; }

/* ── Travel Tips / Safety ── */
.dd-tip-item { display:flex; gap:12px; padding:11px 0; border-bottom:1px dashed var(--border-color,#e2e8f0); }
.dd-tip-item:last-child { border-bottom:none; }
.dd-tip-item .tip-icon { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.85rem; }
.dd-tip-item .tip-title { font-size:.85rem; font-weight:700; color:var(--text-primary,#1e293b); }
.dd-tip-item .tip-desc { font-size:.78rem; color:var(--text-muted,#64748b); line-height:1.5; margin-top:2px; }

/* ── Booking Widget ── */
.dd-booking-date { border:1.5px solid var(--border-color,#e2e8f0); border-radius:10px; padding:10px 12px; width:100%; font-size:.85rem; color:var(--text-primary,#1e293b); background:var(--card-bg,#fff); }
.dd-booking-date:focus { border-color:#0c6e5e; outline:none; box-shadow:0 0 0 3px rgba(12,110,94,.1); }
.dd-total-line { display:flex; justify-content:space-between;}
.dd-total-line .tl-label { font-size:.82rem; color:var(--text-muted,#64748b); }
.dd-total-line .tl-value { font-weight:700; color:var(--text-primary,#1e293b); }
.dd-btn-book { background:linear-gradient(135deg,#0c6e5e,#14b8a6) !important; color:#fff !important; border:none !important; border-radius:12px !important; font-weight:700 !important; padding:13px !important; font-size:.92rem !important; transition:all .2s !important; box-shadow:0 4px 14px rgba(12,110,94,.3) !important; }
.dd-btn-book:hover { transform:translateY(-1px); box-shadow:0 8px 24px rgba(12,110,94,.4) !important; }
.dd-btn-directions-outline { background:transparent !important; border:1.5px solid #0c6e5e !important; color:#0c6e5e !important; border-radius:12px !important; font-weight:600 !important; padding:12px !important; font-size:.88rem !important; transition:all .2s !important; }
.dd-btn-directions-outline:hover { background:rgba(12,110,94,.08) !important; }
.booking-form-label { font-size:.72rem; font-weight:700; color:var(--text-muted,#64748b); text-transform:uppercase; letter-spacing:.4px; display:block; }
</style>

<div class="container-fluid px-0">
    <!-- Hero Header -->
    <div class="dd-hero position-relative overflow-hidden" style="min-height:320px;border-radius:0 0 20px 20px;margin:0 -12px;">
        <?php if (!empty($dest['image'])): ?>
            <img src="<?= dest_image_url($dest['image']) ?>" class="dd-hero-img" alt="<?= sanitize($dest['name']) ?>" onclick='openLightbox(0)' style="cursor:zoom-in;">
        <?php else: ?>
            <div style="position:absolute;inset:0;width:100%;height:100%;background:linear-gradient(135deg, <?= $catColor ?>30, <?= $catColor ?>10, #0f172a);"></div>
        <?php endif; ?>
        <div class="dd-hero-overlay"></div>

        <!-- Badges Row -->
        <div class="position-absolute top-0 start-0 w-100 p-3 pb-0" style="z-index:2;">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="dd-hero-badge" style="background:<?= $catColor ?>;"><i class="<?= $catIcon ?> me-1"></i><?= $catInfo['label'] ?></span>
                <?php if ($dest['featured']): ?>
                    <span class="dd-hero-badge" style="background:rgba(245,158,11,0.95);"><i class="fas fa-star me-1"></i>Featured</span>
                <?php endif; ?>
                <span class="dd-hero-badge" style="background:<?= $dest['booking_enabled'] ? 'rgba(16,185,129,0.9)' : 'rgba(100,116,139,0.8)' ?>;">
                    <i class="fas fa-<?= $dest['booking_enabled'] ? 'ticket' : 'lock' ?> me-1"></i><?= $dest['booking_enabled'] ? 'Bookings Open' : 'Bookings Closed' ?>
                </span>
                <?php if (!empty($weatherData)): ?>
                    <span class="dd-hero-badge" style="background:rgba(255,255,255,0.15);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);">
                        <img src="<?= $weatherData['icon_url'] ?>" alt="" style="width:16px;height:16px;margin:-2px 2px -2px 0;">
                        <?= $weatherData['temperature'] ?>°C
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bottom Info -->
        <div class="dd-hero-bottom position-absolute bottom-0 start-0 w-100 p-4">
            <div class="container-fluid">
                <h2 class="text-white mb-1 fw-bold" style="font-size:1.6rem;letter-spacing:-0.3px;"><?= sanitize($dest['name']) ?></h2>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <a href="<?= $hasCoords ? $mapUrl : '#' ?>" target="_blank" class="text-white-75 text-decoration-none" style="font-size:.88rem;">
                        <i class="fas fa-map-pin me-1" style="color:<?= $catColor ?>;"></i><?= sanitize($dest['location']) ?>
                        <?php if ($hasCoords): ?><i class="fas fa-external-link-alt ms-1" style="font-size:.65rem;opacity:.6;"></i><?php endif; ?>
                    </a>
                    <?php if (!empty($stats) && ($stats['total_reviews'] ?? 0) > 0): ?>
                        <span class="dd-hero-rating">
                            <i class="fas fa-star"></i><?= number_format((float)($stats['avg_rating'] ?? 0), 1) ?>
                            <span style="opacity:.7;font-weight:500;">(<?= $stats['total_reviews'] ?>)</span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="dd-hero-actions">
                    <button type="button" class="dd-hero-action <?= $isBookmarked ? 'bookmarked' : '' ?>" id="bookmarkBtn" onclick="toggleBookmark(this)" title="<?= $isBookmarked ? 'Remove from favorites' : 'Save to favorites' ?>">
                        <i class="<?= $isBookmarked ? 'fas' : 'far' ?> fa-heart" id="bookmarkIcon"></i> <span id="bookmarkLabel"><?= $isBookmarked ? 'Saved' : 'Save' ?></span>
                    </button>
                    <button type="button" class="dd-hero-action" onclick="shareDest()" title="Share destination">
                        <i class="fas fa-share-nodes"></i> Share
                    </button>
                    <?php if ($hasCoords): ?>
                        <a href="<?= $mapUrl ?>" target="_blank" class="dd-hero-action">
                            <i class="fas fa-diamond-turn-right"></i> Directions
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
                <div class="card-body p-4">
                    <div class="dd-section-header">
                        <div class="dd-icon" style="background:<?= $catColor ?>15;color:<?= $catColor ?>;"><i class="fas fa-circle-info"></i></div>
                        <h5>About This Destination</h5>
                    </div>

                    <?php
                    $desc = $dest['description'] ?? '';
                    if (empty(trim($desc))) {
                        $desc = 'Explore the beauty and charm of ' . $dest['name'] . ', located in ' . $dest['location'] . '. This ' . strtolower($catInfo['label']) . ' destination offers a unique experience for visitors seeking to discover the natural and cultural wonders of Binalbagan.';
                    }
                    $needsCollapse = strlen($desc) > 300;
                    ?>
                    <div class="dd-description <?= $needsCollapse ? 'collapsed' : '' ?>" id="aboutDesc"><?= nl2br(sanitize($desc)) ?></div>
                    <?php if ($needsCollapse): ?>
                        <button class="dd-toggle-btn" id="aboutToggle" onclick="toggleAbout()">Read more <i class="fas fa-chevron-down ms-1" style="font-size:.65rem;"></i></button>
                    <?php endif; ?>

                    <!-- Photo Gallery -->
                    <?php
                    $allImages = [];
                    if (!empty($dest['image'])) $allImages[] = $dest['image'];
                    if (!empty($galleryImages)) $allImages = array_merge($allImages, $galleryImages);
                    $allImages = array_unique($allImages);
                    ?>
                    <?php if (count($allImages) > 0): ?>
                        <h6 class="mt-4 mb-3" style="font-weight:700;color:var(--text-primary,#1e293b);font-size:.95rem;"><i class="fas fa-images me-2" style="color:<?= $catColor ?>;"></i>Photos</h6>
                        <div class="dd-gallery-grid">
                            <?php foreach ($allImages as $giIdx => $gi): ?>
                                <div class="dd-gallery-item" onclick="openLightbox(<?= $giIdx ?>)">
                                    <img src="<?= dest_image_url($gi) ?>" alt="<?= sanitize($dest['name']) ?> photo <?= $giIdx + 1 ?>">
                                    <?php if ($giIdx === 0 && count($allImages) > 1): ?>
                                        <span class="dd-gallery-count"><i class="fas fa-images me-1"></i><?= count($allImages) ?> photos</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($dest['video_url'])):
                        $videoUrl = $dest['video_url'];
                        $isUploaded = !str_starts_with($videoUrl, 'http');
                        $isYouTube = str_contains($videoUrl, 'youtube.com') || str_contains($videoUrl, 'youtu.be');
                        $isVimeo = str_contains($videoUrl, 'vimeo.com');
                        if ($isYouTube) {
                            preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/', $videoUrl, $m);
                            $embedUrl = !empty($m[1]) ? "https://www.youtube.com/embed/{$m[1]}" : $videoUrl;
                        } elseif ($isVimeo) {
                            preg_match('/vimeo\.com\/(\d+)/', $videoUrl, $m);
                            $embedUrl = !empty($m[1]) ? "https://player.vimeo.com/video/{$m[1]}" : $videoUrl;
                        } else {
                            $embedUrl = $videoUrl;
                        }
                    ?>
                        <h6 class="mt-4 mb-3" style="font-weight:700;color:var(--text-primary,#1e293b);font-size:.95rem;"><i class="fas fa-video me-2" style="color:<?= $catColor ?>;"></i>Video</h6>
                        <div style="border-radius:12px;overflow:hidden;aspect-ratio:16/9;background:#000;">
                            <?php if ($isYouTube || $isVimeo): ?>
                                <iframe src="<?= sanitize($embedUrl) ?>" style="width:100%;height:100%;border:none;" allowfullscreen loading="lazy"></iframe>
                            <?php else: ?>
                                <video controls style="width:100%;height:100%;object-fit:cover;" preload="metadata">
                                    <source src="<?= dest_image_url($videoUrl) ?>" type="video/<?= pathinfo($videoUrl, PATHINFO_EXTENSION) === 'mp4' ? 'mp4' : (pathinfo($videoUrl, PATHINFO_EXTENSION) === 'webm' ? 'webm' : 'quicktime') ?>">
                                    Your browser does not support the video tag.
                                </video>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Lightbox Modal -->
            <?php if (count($allImages) > 0): ?>
            <div class="dd-lightbox" id="ddLightbox" onclick="closeLightbox(event)">
                <button class="dd-lightbox-close" onclick="closeLightbox(event)"><i class="fas fa-times"></i></button>
                <button class="dd-lightbox-prev" onclick="navLightbox(-1,event)"><i class="fas fa-chevron-left"></i></button>
                <img id="ddLightboxImg" src="" alt="">
                <button class="dd-lightbox-next" onclick="navLightbox(1,event)"><i class="fas fa-chevron-right"></i></button>
                <div class="dd-lightbox-counter" id="ddLightboxCounter">1 / <?= count($allImages) ?></div>
            </div>
            <script>
            const galleryImages = <?= json_encode(array_map(fn($img) => dest_image_url($img), $allImages)) ?>;
            let currentSlide = 0;
            function openLightbox(idx) {
                currentSlide = idx;
                document.getElementById('ddLightboxImg').src = galleryImages[currentSlide];
                document.getElementById('ddLightboxCounter').textContent = (currentSlide + 1) + ' / ' + galleryImages.length;
                document.getElementById('ddLightbox').classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            function closeLightbox(e) {
                if (e.target === document.getElementById('ddLightbox') || e.target.closest('.dd-lightbox-close')) {
                    document.getElementById('ddLightbox').classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
            function navLightbox(dir, e) {
                e.stopPropagation();
                currentSlide = (currentSlide + dir + galleryImages.length) % galleryImages.length;
                document.getElementById('ddLightboxImg').src = galleryImages[currentSlide];
                document.getElementById('ddLightboxCounter').textContent = (currentSlide + 1) + ' / ' + galleryImages.length;
            }
            </script>
            <?php endif; ?>

            <?php if (!empty($dest['facilities']) || !empty($dest['rules_regulations']) || $dest['difficulty']): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <!-- Amenities -->
                    <?php
                    $amenities = [];
                    $facList = trim($dest['facilities'] ?? '');
                    if ($facList !== '') {
                        foreach (preg_split('/[\n,]+/', $facList) as $item) {
                            $item = trim($item);
                            if ($item !== '') $amenities[] = ['label' => $item, 'icon' => 'fa-circle-check', 'present' => true];
                        }
                    } else {
                        $amenities = [
                            ['label' => 'Parking', 'icon' => 'fa-square-parking', 'present' => true],
                            ['label' => 'Restrooms', 'icon' => 'fa-restroom', 'present' => true],
                            ['label' => 'Cottages', 'icon' => 'fa-house-chimney', 'present' => true],
                            ['label' => 'Concession Stands', 'icon' => 'fa-mug-hot', 'present' => true],
                            ['label' => 'Guided Tours', 'icon' => 'fa-user-shield', 'present' => true],
                        ];
                    }
                    ?>
                    <div class="dd-section-header">
                        <div class="dd-icon" style="background:rgba(34,197,94,0.1);color:#16a34a;"><i class="fas fa-concierge-bell"></i></div>
                        <h5 style="font-size:1rem;">Amenities</h5>
                    </div>
                    <div class="dd-amenities mb-4">
                        <?php foreach ($amenities as $am): ?>
                            <span class="dd-amenity-chip"><i class="fas <?= $am['icon'] ?>"></i><?= sanitize($am['label']) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="row g-4">
                        <?php if (!empty($dest['facilities'])): ?>
                        <div class="col-md-6">
                            <div class="dd-section-header">
                                <div class="dd-icon" style="background:rgba(34,197,94,0.1);color:#16a34a;"><i class="fas fa-concierge-bell"></i></div>
                                <h5 style="font-size:1rem;">Facilities</h5>
                            </div>
                            <div class="dd-description"><?= nl2br(sanitize($dest['facilities'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dest['rules_regulations'])): ?>
                        <div class="col-md-6">
                            <div class="dd-section-header">
                                <div class="dd-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;"><i class="fas fa-gavel"></i></div>
                                <h5 style="font-size:1rem;">Rules & Regulations</h5>
                            </div>
                            <div class="dd-description"><?= nl2br(sanitize($dest['rules_regulations'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Travel Tips / Safety -->
                    <div class="dd-section-header mt-4">
                        <div class="dd-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fas fa-shield-halved"></i></div>
                        <h5 style="font-size:1rem;">Travel Tips &amp; Safety</h5>
                    </div>
                    <div>
                        <?php
                        $difficultyLabel = ucfirst($dest['difficulty'] ?? 'easy');
                        $tips = [
                            ['icon' => 'fa-person-hiking', 'color' => '#10b981', 'title' => $difficultyLabel . ' Difficulty', 'desc' => 'This spot is rated ' . $difficultyLabel . '. Wear comfortable footwear and pace yourself on trails.'],
                            ['icon' => 'fa-umbrella-beach', 'color' => '#f59e0b', 'title' => 'Best Time to Visit', 'desc' => 'Early mornings are ideal for fewer crowds and cooler weather for an enjoyable visit.'],
                            ['icon' => 'fa-water', 'color' => '#3b82f6', 'title' => 'Water Safety', 'desc' => 'Follow posted safety signs near water areas. Never swim alone, especially after heavy rain.'],
                            ['icon' => 'fa-trash-can', 'color' => '#059669', 'title' => 'Leave No Trace', 'desc' => 'Please bring back what you bring in. Help keep Binalbagan pure and clean.'],
                        ];
                        foreach ($tips as $t): ?>
                            <div class="dd-tip-item">
                                <div class="tip-icon" style="background:<?= $t['color'] ?>15;color:<?= $t['color'] ?>;"><i class="fas <?= $t['icon'] ?>"></i></div>
                                <div>
                                    <div class="tip-title"><?= $t['title'] ?></div>
                                    <div class="tip-desc"><?= $t['desc'] ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
                <div class="card-body">
                    <div class="dd-section-header">
                        <div class="dd-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-star"></i></div>
                        <h5>Reviews & Ratings</h5>
                    </div>

                    <?php if (!empty($stats) && ($stats['total_reviews'] ?? 0) > 0): ?>
                    <div class="dd-rating-overview mb-4">
                        <div class="dd-rating-big">
                            <div class="num"><?= number_format((float)($stats['avg_rating'] ?? 0), 1) ?></div>
                            <div class="dd-review-stars my-1"><?= str_repeat('<i class="fas fa-star"></i>', round((float)($stats['avg_rating'] ?? 0))) ?></div>
                            <div class="label"><?= (int)($stats['total_reviews'] ?? 0) ?> review<?= ($stats['total_reviews'] ?? 0) != 1 ? 's' : '' ?></div>
                        </div>
                        <div class="dd-rating-bars">
                            <?php $totalReviews = $stats['total_reviews'] ?? 1; for ($i = 5; $i >= 1; $i--): $pct = $totalReviews > 0 ? round(($stats["star{$i}"] ?? 0) / $totalReviews * 100) : 0; ?>
                                <div class="dd-rating-bar">
                                    <div class="stars"><?= $i ?><i class="fas fa-star"></i></div>
                                    <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                                    <div class="pct"><?= $pct ?>%</div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="dd-empty-state">
                        <div class="dd-empty-icon"><i class="fas fa-star"></i></div>
                        <h6>No ratings yet</h6>
                        <p>Be the first to share your experience!</p>
                    </div>
                    <?php endif; ?>

                    <?php if (!$userReview): ?>
                        <div class="dd-review-form mb-4">
                            <form method="POST" action="<?= BASE_URL ?>/tourist/destination_detail.php?id=<?= $destId ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_review">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <div style="width:32px;height:32px;border-radius:8px;background:<?= $catColor ?>15;display:flex;align-items:center;justify-content:center;"><i class="fas fa-pen-to-square" style="color:<?= $catColor ?>;font-size:.8rem;"></i></div>
                                    <h6 class="mb-0" style="font-weight:700;color:var(--text-primary,#1e293b);">Write a Review</h6>
                                </div>
                                <div class="mb-3">
                                    <label class="booking-form-label">Your Rating</label>
                                    <div class="d-flex align-items-center gap-1 star-rating" data-target="review_rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="far fa-star" style="cursor:pointer;font-size:1.4rem;color:#f59e0b;transition:transform .15s;" data-value="<?= $i ?>" onmouseenter="this.style.transform='scale(1.2)'" onmouseleave="this.style.transform='scale(1)'" onclick="setRating(this, 'review_rating')"></i>
                                        <?php endfor; ?>
                                        <input type="hidden" name="rating" id="review_rating" value="0">
                                        <span class="ms-2 small" id="ratingLabel" style="color:var(--text-muted,#94a3b8);">Select a rating</span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="booking-form-label">Your Review</label>
                                    <textarea name="review" class="dd-review-textarea" rows="3" placeholder="Tell others about your experience..." required></textarea>
                                </div>
                                <button type="submit" class="btn btn-review-submit fw-semibold"><i class="fas fa-paper-plane me-1"></i> Submit Review</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="dd-review-form mb-4" style="border-color:<?= $catColor ?>30;">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <div style="width:32px;height:32px;border-radius:8px;background:rgba(245,158,11,0.1);display:flex;align-items:center;justify-content:center;"><i class="fas fa-pen-to-square" style="color:#f59e0b;font-size:.8rem;"></i></div>
                                <h6 class="mb-0" style="font-weight:700;color:var(--text-primary,#1e293b);">Your Review</h6>
                            </div>
                            <div class="small mb-2" style="color:var(--text-muted,#64748b);">You reviewed this destination. <a href="#" onclick="document.getElementById('editReviewForm').classList.toggle('d-none');return false;" style="color:var(--cat-color);font-weight:600;">Edit</a></div>
                            <form method="POST" id="editReviewForm" class="d-none">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="edit_review">
                                <input type="hidden" name="review_id" value="<?= $userReview['id'] ?>">
                                <div class="mb-3">
                                    <label class="booking-form-label">Your Rating</label>
                                    <div class="d-flex align-items-center gap-1 star-rating" data-target="edit_rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="<?= $i <= (int)($userReview['rating'] ?? 0) ? 'fas' : 'far' ?> fa-star" style="cursor:pointer;font-size:1.4rem;color:#f59e0b;transition:transform .15s;" data-value="<?= $i ?>" onmouseenter="this.style.transform='scale(1.2)'" onmouseleave="this.style.transform='scale(1)'" onclick="setRating(this, 'edit_rating')"></i>
                                        <?php endfor; ?>
                                        <input type="hidden" name="rating" id="edit_rating" value="<?= (int)($userReview['rating'] ?? 0) ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="booking-form-label">Your Review</label>
                                    <textarea name="review" class="dd-review-textarea" rows="3"><?= sanitize($userReview['review'] ?? '') ?></textarea>
                                </div>
                                <button type="submit" class="btn btn-review-submit fw-semibold"><i class="fas fa-save me-1"></i> Update Review</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($reviewData['data'])): ?>
                        <?php foreach ($reviewData['data'] as $rv): ?>
                            <?php
                            $rvColors = ['#0c6e5e','#7c3aed','#2563eb','#d97706','#dc2626'];
                            $rvColor = $rvColors[array_sum(str_split($rv['user_name'] ?? 'A')) % count($rvColors)];
                            ?>
                            <div class="dd-review-item">
                                <div class="dd-review-header">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="dd-review-avatar" style="background:linear-gradient(135deg, <?= $rvColor ?>, <?= $rvColor ?>bb);">
                                            <?= strtoupper(substr($rv['user_name'] ?? 'A',0,1)) ?>
                                        </div>
                                        <div>
                                            <div class="dd-review-name"><?= sanitize($rv['user_name'] ?? 'Anonymous') ?></div>
                                            <div class="dd-review-stars">
                                                <?php for ($si = 1; $si <= 5; $si++): ?>
                                                    <i class="fas fa-star" style="color:<?= $si <= (int)($rv['rating'] ?? 0) ? '#f59e0b' : 'var(--border-color,#e2e8f0)' ?>;"></i>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dd-review-date"><i class="fas fa-clock me-1" style="font-size:.6rem;"></i><?= format_date($rv['created_at']) ?></div>
                                </div>
                                <div class="dd-review-text mt-2"><?= sanitize($rv['review'] ?? '') ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($reviewData['pages'] > 1): ?>
                            <nav><ul class="pagination pagination-sm justify-content-center mt-3">
                                <?php for ($i = 1; $i <= $reviewData['pages']; $i++): ?>
                                    <li class="page-item <?= $i === $reviewData['page'] ? 'active' : '' ?>">
                                        <a class="page-link" href="?id=<?= $destId ?>&review_page=<?= $i ?>&sort=<?= $sort ?>" style="<?= $i === $reviewData['page'] ? 'background:' . $catColor . ';border-color:' . $catColor : '' ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul></nav>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="dd-empty-state" style="padding:24px 20px;">
                            <div class="dd-empty-icon" style="width:48px;height:48px;"><i class="fas fa-comments" style="font-size:1.1rem;"></i></div>
                            <h6 style="font-size:.9rem;">No reviews yet</h6>
                            <p style="font-size:.82rem;">Be the first to share your experience!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if ($weatherData && $weatherAdvisory): ?>
            <div class="weather-card mb-4">
                <div class="weather-card-header <?= $weatherAdvisory['level'] ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="weather-badge <?= $weatherAdvisory['level'] ?>">
                                <i class="fas <?= $weatherAdvisory['level'] === 'success' ? 'fa-circle-check' : ($weatherAdvisory['level'] === 'warning' ? 'fa-circle-exclamation' : 'fa-circle-xmark') ?>"></i>
                                <?= $weatherAdvisory['badge'] ?>
                            </div>
                            <div class="weather-main mt-2">
                                <img src="<?= $weatherData['icon_url'] ?>" alt="" style="width:52px;height:52px;margin:-8px -4px -8px 0;">
                                <div>
                                    <div class="weather-temp"><?= $weatherData['temperature'] ?>°C</div>
                                    <div class="weather-desc"><?= $weatherData['description'] ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="text-end" style="min-width:80px;">
                            <div style="font-size:.72rem;color:var(--text-muted,#94a3b8);">Feels like</div>
                            <div style="font-size:1rem;font-weight:700;color:var(--text-primary,#1e293b);"><?= $weatherData['feels_like'] ?>°C</div>
                        </div>
                    </div>
                </div>
                <div class="weather-msg">
                    <i class="fas <?= $weatherAdvisory['icon'] ?> me-1" style="color:<?= $weatherAdvisory['color'] ?>;"></i>
                    <?= $weatherAdvisory['message'] ?>
                </div>
                <div class="weather-stats">
                    <div class="weather-stat">
                        <i class="fas fa-droplet" style="color:#3b82f6;"></i>
                        <div>
                            <div class="ws-label">Humidity</div>
                            <div class="ws-value"><?= $weatherData['humidity'] ?>%</div>
                        </div>
                    </div>
                    <div class="weather-stat">
                        <i class="fas fa-wind" style="color:#6366f1;"></i>
                        <div>
                            <div class="ws-label">Wind</div>
                            <div class="ws-value"><?= $weatherData['wind_speed'] ?> km/h <?= WeatherService::getWindDirection($weatherData['wind_deg']) ?></div>
                        </div>
                    </div>
                    <div class="weather-stat">
                        <i class="fas fa-cloud-rain" style="color:#0ea5e9;"></i>
                        <div>
                            <div class="ws-label">Rain Chance</div>
                            <div class="ws-value"><?= $weatherData['rain_chance'] ?>%</div>
                        </div>
                    </div>
                    <div class="weather-stat">
                        <i class="fas fa-eye" style="color:#8b5cf6;"></i>
                        <div>
                            <div class="ws-label">Visibility</div>
                            <div class="ws-value"><?= $weatherData['visibility'] ?> km</div>
                        </div>
                    </div>
                    <div class="weather-stat">
                        <i class="fas fa-temperature-half" style="color:#f97316;"></i>
                        <div>
                            <div class="ws-label">High / Low</div>
                            <div class="ws-value"><?= $weatherData['temp_max'] ?>° / <?= $weatherData['temp_min'] ?>°</div>
                        </div>
                    </div>
                    <div class="weather-stat">
                        <i class="fas fa-gauge-high" style="color:#64748b;"></i>
                        <div>
                            <div class="ws-label">Pressure</div>
                            <div class="ws-value"><?= $weatherData['pressure'] ?> hPa</div>
                        </div>
                    </div>
                </div>
                <?php if ($weatherData['sunrise'] || $weatherData['sunset']): ?>
                <div class="d-flex justify-content-between px-3 py-2" style="font-size:.75rem;color:var(--text-muted,#94a3b8);border-top:1px solid var(--border-color,#e2e8f0);">
                    <?php if ($weatherData['sunrise']): ?><span><i class="fas fa-sunrise me-1" style="color:#f59e0b;"></i><?= $weatherData['sunrise'] ?></span><?php endif; ?>
                    <?php if ($weatherData['sunset']): ?><span><i class="fas fa-sunset me-1" style="color:#f97316;"></i><?= $weatherData['sunset'] ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php elseif (!$hasCoords): ?>
            <div class="weather-card mb-4">
                <div class="weather-card-header unavailable">
                    <div class="weather-badge unavailable"><i class="fas fa-circle-question"></i> Weather Unavailable</div>
                    <div class="weather-main mt-2">
                        <div class="weather-desc">Location coordinates not set for this destination.</div>
                    </div>
                </div>
                <div class="weather-msg">Weather information is currently unavailable. Please check again later.</div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;">
                <div class="card-body p-4">
                    <h5 class="mb-3" style="font-weight:700;color:var(--text-primary,#1e293b);font-size:1rem;"><i class="fas fa-info-circle me-2" style="color:<?= $catColor ?>;"></i>Quick Info</h5>
                    <div class="dd-info-grid">
                        <div class="dd-info-cell">
                            <div class="dd-info-label">Entrance Fee</div>
                            <div class="dd-info-value" style="color:<?= $catColor ?>;">₱<?= number_format((float)$dest['entrance_fee'], 2) ?></div>
                        </div>
                        <div class="dd-info-cell">
                            <div class="dd-info-label">Difficulty</div>
                            <span class="dd-info-badge bg-<?= $dest['difficulty'] === 'easy' ? 'success' : ($dest['difficulty'] === 'moderate' ? 'warning' : 'danger') ?>"><?= ucfirst($dest['difficulty']) ?></span>
                        </div>
                        <?php if ($dest['package_price']): ?>
                        <div class="dd-info-cell">
                            <div class="dd-info-label">Package Price</div>
                            <div class="dd-info-value">₱<?= number_format((float)$dest['package_price'], 2) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="dd-info-cell">
                            <div class="dd-info-label">Capacity</div>
                            <div class="dd-info-value"><?= $dest['capacity_limit'] ? $dest['capacity_limit'] . '/day' : 'Unlimited' ?></div>
                        </div>
                        <?php if ($dest['operating_hours_open']): ?>
                        <div class="dd-info-cell">
                            <div class="dd-info-label">Operating Hours</div>
                            <div class="dd-info-value" style="font-size:.82rem;"><?= date('h:i A', strtotime($dest['operating_hours_open'])) ?> – <?= date('h:i A', strtotime($dest['operating_hours_close'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($dest['contact_phone']): ?>
                        <div class="dd-info-cell">
                            <div class="dd-info-label">Contact</div>
                            <div class="dd-info-value" style="font-size:.82rem;"><?= sanitize($dest['contact_phone']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasCoords): ?>
                        <?php if (!empty($weatherAdvisory['nav_disabled'])): ?>
                            <button type="button" class="btn w-100 mt-3 dd-btn-nav-disabled" title="Navigation disabled due to weather">
                                <i class="fas fa-ban me-1"></i>Navigation Unavailable
                            </button>
                            <small class="text-center d-block mt-1" style="font-size:.7rem;color:#ef4444;">Weather conditions unsafe for travel</small>
                        <?php else: ?>
                            <a href="<?= $mapUrl ?>" target="_blank" class="btn w-100 mt-3 dd-btn-directions">
                                <i class="fas fa-compass me-2"></i>Get Directions
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($hasCoords): ?>
            <!-- Interactive Leaflet Map -->
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
            <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;overflow:hidden;">
                <div class="card-body p-4">
                    <div class="dd-section-header">
                        <div class="dd-icon" style="background:<?= $catColor ?>15;color:<?= $catColor ?>;"><i class="fas fa-map-location-dot"></i></div>
                        <h5 style="font-size:1rem;">Location</h5>
                    </div>
                    <div id="ddLeafletMap" class="dd-leaflet-map" style="border-radius:12px;"></div>
                    <div class="d-flex align-items-center justify-content-between mt-2" style="font-size:.75rem;color:var(--text-muted,#94a3b8);">
                        <span><i class="fas fa-location-crosshairs me-1" style="color:<?= $catColor ?>;"></i><?= $dest['latitude'] ?>, <?= $dest['longitude'] ?></span>
                        <a href="<?= $mapUrl ?>" target="_blank" style="color:<?= $catColor ?>;font-weight:600;text-decoration:none;">Open in Google Maps <i class="fas fa-external-link-alt" style="font-size:.6rem;"></i></a>
                    </div>
                </div>
            </div>
            <script>
            (function() {
                var map = L.map('ddLeafletMap').setView([<?= $dest['latitude'] ?>, <?= $dest['longitude'] ?>], 14);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                }).addTo(map);
                var marker = L.marker([<?= $dest['latitude'] ?>, <?= $dest['longitude'] ?>]).addTo(map);
                marker.bindPopup('<div style="min-width:160px;"><strong><?= addslashes(sanitize($dest['name'])) ?></strong><br><small style="color:#64748b;"><?= addslashes(sanitize($dest['location'])) ?></small></div>').openPopup();
            })();
            </script>
            <?php endif; ?>

            <?php if ($dest['booking_enabled']): ?>
            <div class="card border-0 shadow-sm mb-4" style="border-radius:16px;border-top:3px solid <?= $catColor ?>;">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="dd-icon" style="background:<?= $catColor ?>15;color:<?= $catColor ?>;"><i class="fas fa-calendar-check"></i></div>
                        <h5 class="mb-0 fw-bold" style="color:var(--text-primary,#1e293b);font-size:1rem;">Book Your Visit</h5>
                    </div>
                    <p class="small mb-3" style="color:var(--text-muted,#64748b);">Reserve your spot and get a local guide ready for you.</p>
                    <?php if ($feePrice > 0): ?>
                        <div class="d-flex align-items-baseline gap-2 mb-3">
                            <span style="font-size:1.4rem;font-weight:800;color:#0c6e5e;">₱<?= number_format($feePrice, 2) ?></span>
                            <span style="font-size:.82rem;color:var(--text-muted,#94a3b8);">per person</span>
                        </div>
                    <?php endif; ?>

                    <label class="booking-form-label mb-1">Travel Date</label>
                    <input type="date" class="dd-booking-date mb-3" id="bookDate" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">

                    <label class="booking-form-label mb-1">Guests</label>
                    <div class="dd-booking-counter mb-3">
                        <button type="button" onclick="adjustGuests(-1)"><i class="fas fa-minus"></i></button>
                        <span class="counter-val" id="guestCount">1</span>
                        <button type="button" onclick="adjustGuests(1)"><i class="fas fa-plus"></i></button>
                    </div>

                    <div class="dd-booking-total">
                        <div class="dd-total-line" style="width:100%;">
                            <span class="tl-label">Total (<span id="guestCountLabel">1</span> guest<?= 's' ?>)</span>
                            <span class="tl-value" style="color:#0c6e5e;font-size:1.15rem;" id="totalPrice">₱<?= number_format($feePrice, 2) ?></span>
                        </div>
                    </div>

                    <a href="<?= BASE_URL ?>/tourist/book_now.php?id=<?= $destId ?>" class="btn w-100 dd-btn-book" id="bookNowBtn">
                        <i class="fas fa-ticket me-2"></i>Book Now
                    </a>
                    <?php if ($hasCoords): ?>
                        <a href="<?= $mapUrl ?>" target="_blank" class="btn w-100 mt-2 dd-btn-directions-outline">
                            <i class="fas fa-compass me-2"></i>Get Directions
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($seasons)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="dd-section-header">
                        <div class="dd-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-calendar-alt"></i></div>
                        <h5>Seasonal Info</h5>
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <?php
                        $monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
                        foreach ($seasons as $s):
                            $parts = explode('-', $s['months']);
                            $startM = (int)($parts[0] ?? 0);
                            $endM = (int)(end($parts) ?: $startM);
                            if ($startM >= 1 && $startM <= 12 && $endM >= 1 && $endM <= 12) {
                                $monthLabel = $startM === $endM ? $monthNames[$startM] : $monthNames[$startM] . ' – ' . $monthNames[$endM];
                            } else {
                                $monthLabel = 'Months ' . $s['months'];
                            }
                            $isPeak = $s['season_type'] === 'peak';
                        ?>
                            <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;background:<?= $isPeak ? 'rgba(239,68,68,0.05)' : 'rgba(16,185,129,0.05)' ?>;border:1px solid <?= $isPeak ? 'rgba(239,68,68,0.12)' : 'rgba(16,185,129,0.12)' ?>;">
                                <div style="width:36px;height:36px;border-radius:8px;background:<?= $isPeak ? 'rgba(239,68,68,0.12)' : 'rgba(16,185,129,0.12)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas <?= $isPeak ? 'fa-fire' : 'fa-snowflake' ?>" style="color:<?= $isPeak ? '#ef4444' : '#10b981' ?>;font-size:.8rem;"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div style="font-size:.82rem;font-weight:700;color:var(--text-primary,#1e293b);"><?= $isPeak ? 'Peak Season' : 'Off-Peak Season' ?></div>
                                    <div style="font-size:.75rem;color:var(--text-muted,#94a3b8);"><?= $monthLabel ?></div>
                                </div>
                                <span style="font-size:.65rem;padding:3px 10px;border-radius:50px;background:<?= $isPeak ? 'rgba(239,68,68,0.12)' : 'rgba(16,185,129,0.12)' ?>;color:<?= $isPeak ? '#dc2626' : '#059669' ?>;font-weight:600;"><?= $isPeak ? 'Busy' : 'Quiet' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
    toast.style.cssText = 'position:fixed;top:24px;right:24px;z-index:10000;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 20px;box-shadow:0 12px 40px rgba(0,0,0,0.12);transform:translateX(120%);transition:transform .35s cubic-bezier(.4,0,.2,1),opacity .35s;max-width:360px;';
    document.body.appendChild(toast);
    requestAnimationFrame(function() { toast.style.transform = 'translateX(0)'; });
    setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        setTimeout(function() { toast.remove(); }, 350);
    }, 3000);
}

function setRating(el, targetId) {
    const parent = el.closest('.star-rating');
    const stars = parent.querySelectorAll('i');
    const value = parseInt(el.dataset.value);
    const ratingLabels = ['', 'Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];
    stars.forEach((s, i) => {
        s.className = (i < value ? 'fas' : 'far') + ' fa-star';
        s.style.color = '#f59e0b';
    });
    document.getElementById(targetId).value = value;
    const label = document.getElementById('ratingLabel');
    if (label) label.textContent = ratingLabels[value] || '';
}

function toggleAbout() {
    const desc = document.getElementById('aboutDesc');
    const btn = document.getElementById('aboutToggle');
    if (desc.classList.contains('collapsed')) {
        desc.classList.remove('collapsed');
        btn.innerHTML = 'Show less <i class="fas fa-chevron-up ms-1" style="font-size:.65rem;"></i>';
    } else {
        desc.classList.add('collapsed');
        btn.innerHTML = 'Read more <i class="fas fa-chevron-down ms-1" style="font-size:.65rem;"></i>';
    }
}

/* ── Bookmark Toggle ── */
var ddBookmarkActive = <?= $isBookmarked ? 'true' : 'false' ?>;

function toggleBookmark(btn) {
    var fd = new FormData();
    fd.append('toggle_bookmark', '1');
    fd.append('dest_id', <?= $destId ?>);
    fetch('<?= BASE_URL ?>/tourist/destination_detail.php?id=<?= $destId ?>', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).then(function(r) { return r.text(); }).then(function() {
        ddBookmarkActive = !ddBookmarkActive;
        updateBookmarkUI();
        showToast(ddBookmarkActive ? 'Saved to favorites' : 'Removed from favorites', ddBookmarkActive ? 'success' : 'warning');
    }).catch(function() {
        showToast('Could not update favorites. Please try again.', 'error');
    });
}

function updateBookmarkUI() {
    var btn = document.getElementById('bookmarkBtn');
    var icon = document.getElementById('bookmarkIcon');
    var label = document.getElementById('bookmarkLabel');
    if (!btn) return;
    if (ddBookmarkActive) {
        btn.classList.add('bookmarked');
        btn.title = 'Remove from favorites';
        icon.className = 'fas fa-heart';
        label.textContent = 'Saved';
    } else {
        btn.classList.remove('bookmarked');
        btn.title = 'Save to favorites';
        icon.className = 'far fa-heart';
        label.textContent = 'Save';
    }
}

/* ── Share Destination ── */
function shareDest() {
    var url = window.location.href;
    var title = '<?= addslashes($dest['name']) ?>';
    if (navigator.share) {
        navigator.share({ title: title, text: 'Check out ' + title + ' on BINALGO Tourism', url: url })
            .catch(function() { /* cancelled */ });
    } else {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function() {
                showToast('Link copied to clipboard', 'success');
            }).catch(function() {
                showToast('Could not copy link', 'error');
            });
        } else {
            window.prompt('Copy this link:', url);
        }
    }
}

/* ── Guest Counter + Booking Total ── */
var ddFeePrice = <?= (float)$feePrice ?>;
var ddGuests = 1;

function adjustGuests(delta) {
    ddGuests = Math.max(1, Math.min(12, ddGuests + delta));
    document.getElementById('guestCount').textContent = ddGuests;
    document.getElementById('guestCountLabel').textContent = ddGuests;
    document.getElementById('totalPrice').textContent = '₱' + (ddFeePrice * ddGuests).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

document.addEventListener('DOMContentLoaded', function() {
    var dateInput = document.getElementById('bookDate');
    var bookBtn = document.getElementById('bookNowBtn');
    if (dateInput && bookBtn) {
        dateInput.addEventListener('change', updateBookingLink);
        updateBookingLink();
    }
    function updateBookingLink() {
        var d = document.getElementById('bookDate').value;
        bookBtn.href = '<?= BASE_URL ?>/tourist/book_now.php?id=<?= $destId ?>&date=' + encodeURIComponent(d) + '&guests=' + ddGuests;
    }
    document.addEventListener('click', function(e) {
        var cb = e.target.closest('#bookNowBtn');
        if (cb) updateBookingLink();
    });
});
</script>

<?php }); ?>
