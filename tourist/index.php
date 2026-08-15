<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();

$cat_stmt = $db->query("SELECT category, COUNT(*) as cnt FROM destinations WHERE status='active' GROUP BY category ORDER BY category");
$categories = $cat_stmt->fetchAll();

$cat_labels = [
    'beaches'              => ['label' => 'Beaches',               'icon' => 'fas fa-umbrella-beach',    'color' => '#3b82f6'],
    'nature_adventure'     => ['label' => 'Nature & Adventure',    'icon' => 'fas fa-mountain',           'color' => '#10b981'],
    'heritage_culture'     => ['label' => 'Heritage & Culture',    'icon' => 'fas fa-landmark',           'color' => '#f59e0b'],
    'food_local'           => ['label' => 'Food & Local Cuisine',  'icon' => 'fas fa-utensils',           'color' => '#ef4444'],
    'religious_sites'      => ['label' => 'Religious Sites',       'icon' => 'fas fa-church',             'color' => '#8b5cf6'],
];

$all_cats = [];
foreach ($categories as $c) {
    $cat = $c['category'];
    if (in_array($cat, ['historical_sites', 'cultural_attractions'])) {
        $all_cats['heritage_culture'] = ($all_cats['heritage_culture'] ?? 0) + $c['cnt'];
    } elseif (in_array($cat, ['local_experience', 'food_local'])) {
        $all_cats['food_local'] = ($all_cats['food_local'] ?? 0) + $c['cnt'];
    } else {
        $all_cats[$cat] = ($all_cats[$cat] ?? 0) + $c['cnt'];
    }
}

$upcoming_stmt = $db->prepare(
    "SELECT b.*, s.start_date, s.start_time, e.title as event_name, d.name as destination_name
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.id
     JOIN events e ON s.event_id = e.id
     JOIN destinations d ON e.destination_id = d.id
     WHERE b.tourist_id = :uid AND b.status IN ('confirmed','pending') AND s.start_date >= date('now')
     ORDER BY s.start_date ASC, s.start_time ASC
     LIMIT 4"
);
$upcoming_stmt->execute([':uid' => $_SESSION['user_id']]);
$upcoming_bookings = $upcoming_stmt->fetchAll();

$total_bookings_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings WHERE tourist_id = :uid");
$total_bookings_stmt->execute([':uid' => $_SESSION['user_id']]);
$total_bookings = (int) $total_bookings_stmt->fetch()['cnt'];

$upcoming_count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings b JOIN schedules s ON b.schedule_id = s.id WHERE b.tourist_id = :uid AND b.status IN ('confirmed','pending') AND s.start_date >= date('now')");
$upcoming_count_stmt->execute([':uid' => $_SESSION['user_id']]);
$upcoming_count = (int) $upcoming_count_stmt->fetch()['cnt'];

$completed_count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings WHERE tourist_id = :uid AND status = 'completed'");
$completed_count_stmt->execute([':uid' => $_SESSION['user_id']]);
$completed_count = (int) $completed_count_stmt->fetch()['cnt'];

$featured_destinations = $db->query(
    "SELECT d.id, d.name, d.location, d.image, d.difficulty, d.entrance_fee,
            (SELECT COUNT(*) FROM events e WHERE e.destination_id = d.id AND e.status = 'published') as event_count
     FROM destinations d
     WHERE d.status = 'active'
     ORDER BY d.featured DESC, d.created_at DESC
     LIMIT 4"
)->fetchAll();

$upcoming_events = $db->query(
    "SELECT e.id, e.title, e.event_start_date, e.event_start_time, e.event_location, e.price AS event_fee, e.event_image,
            d.name as destination_name
     FROM events e
     LEFT JOIN destinations d ON e.destination_id = d.id
     WHERE e.status = 'published' AND e.event_start_date >= date('now')
     ORDER BY e.event_start_date ASC
     LIMIT 4"
)->fetchAll();

$total_destinations = (int) $db->query("SELECT COUNT(*) FROM destinations WHERE status = 'active'")->fetchColumn();

$recent_destinations = $db->query(
    "SELECT d.id, d.name, d.location, d.image, d.difficulty, d.entrance_fee,
            (SELECT COUNT(*) FROM events e WHERE e.destination_id = d.id AND e.status = 'published') as event_count
     FROM destinations d
     WHERE d.status = 'active'
     ORDER BY d.created_at DESC
     LIMIT 3"
)->fetchAll();

$all_destinations_for_search = $db->query(
    "SELECT d.id, d.name, d.location, d.category, d.entrance_fee
     FROM destinations d
     WHERE d.status = 'active'
     ORDER BY d.featured DESC, d.name ASC
     LIMIT 20"
)->fetchAll();

$all_events_for_search = $db->query(
    "SELECT e.id, e.title, e.event_start_date, e.event_location,
            d.name as destination_name
     FROM events e
     LEFT JOIN destinations d ON e.destination_id = d.id
     WHERE e.status = 'published'
     ORDER BY e.event_start_date ASC
     LIMIT 10"
)->fetchAll();

$hero_slides = [
    ['img' => BASE_URL . '/assets/images/images%20(12).jpg', 'alt' => 'Binalbagan Municipal Hall'],
    ['img' => BASE_URL . '/assets/images/bambi.jpg', 'alt' => 'Bambi Falls - Binalbagan Eco-Tourism'],
    ['img' => BASE_URL . '/assets/images/images%20(3).jpg', 'alt' => 'Pristine Beaches of Binalbagan'],
    ['img' => BASE_URL . '/assets/images/images%20(6).jpg', 'alt' => 'Heritage & Culture Sites'],
    ['img' => BASE_URL . '/assets/images/images%20(9).jpg', 'alt' => 'Historic Religious Sites'],
];

render_page('tourist', 'index.php', 'Home', function() use ($user, $all_cats, $cat_labels, $upcoming_bookings, $total_bookings, $upcoming_count, $completed_count, $featured_destinations, $upcoming_events, $total_destinations, $recent_destinations, $all_destinations_for_search, $all_events_for_search, $hero_slides) {
?>

<style>
.dashboard-wrap {
    --db-bg: #f1f5f9;
    --db-card: #ffffff;
    --db-card-alt: #f8fafc;
    --db-border: #e2e8f0;
    --db-border-hover: #cbd5e1;
    --db-text: #1e293b;
    --db-text-muted: #64748b;
    --db-text-light: #94a3b8;
    --db-text-bright: #f1f5f9;
    --db-shadow: 0 2px 12px rgba(0,0,0,0.06);
    --db-shadow-hover: 0 12px 32px rgba(0,0,0,0.1);
    color: var(--db-text);
    padding: 0 8px;
    margin: 0 -12px;
}

[data-theme="dark"] .dashboard-wrap {
    --db-bg: #0f172a;
    --db-card: #1e293b;
    --db-card-alt: #1a2332;
    --db-border: #334155;
    --db-border-hover: #475569;
    --db-text: #e2e8f0;
    --db-text-muted: #94a3b8;
    --db-text-light: #64748b;
    --db-text-bright: #f8fafc;
    --db-shadow: 0 2px 12px rgba(0,0,0,0.25);
    --db-shadow-hover: 0 12px 32px rgba(0,0,0,0.45);
}

.hero-section {
    border-radius: 24px;
    padding: 72px 52px 60px;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
    border: 1px solid var(--db-border);
    min-height: 420px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.hero-section::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(160deg, rgba(6,15,30,0.78) 0%, rgba(6,15,30,0.50) 35%, rgba(12,110,94,0.18) 65%, rgba(6,15,30,0.62) 100%);
    pointer-events: none;
    z-index: 0;
}

.hero-section::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 55%;
    background: linear-gradient(to top, rgba(6,15,30,0.75) 0%, rgba(6,15,30,0.15) 55%, transparent 100%);
    pointer-events: none;
    z-index: 0;
}

[data-theme="dark"] .hero-section::before {
    background: linear-gradient(160deg, rgba(6,10,18,0.82) 0%, rgba(6,10,18,0.55) 35%, rgba(12,110,94,0.12) 65%, rgba(6,10,18,0.65) 100%);
}

[data-theme="dark"] .hero-section::after {
    background: linear-gradient(to top, rgba(6,10,18,0.75) 0%, rgba(6,10,18,0.15) 55%, transparent 100%);
}

@keyframes heroGlow {
    0%,100% { transform: scale(1); opacity: 0.3; }
    50% { transform: scale(1.08); opacity: 0.8; }
}

.hero-typed-wrap {
    position: relative;
    display: inline-block;
}

.hero-typed-text {
    font-size: 3rem;
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 10px;
    color: #fff;
    text-shadow: 0 2px 20px rgba(0,0,0,0.35);
    position: relative;
    min-height: 2.5em;
}

.hero-typed-text .typed-line {
    display: block;
}

.hero-typed-text .typed-line .char {
    display: inline-block;
    opacity: 0;
    transform: translateY(18px) scale(0.85);
    filter: blur(4px);
    text-shadow: none;
    transition: none;
}

.hero-typed-text .typed-line .char.visible {
    opacity: 1;
    transform: translateY(0) scale(1);
    filter: blur(0);
    text-shadow:
        0 0 8px rgba(52,211,153,0.6),
        0 0 20px rgba(52,211,153,0.3),
        0 2px 4px rgba(0,0,0,0.5);
    animation: charGlow 0.6s ease-out forwards;
}

.hero-typed-text .typed-line .char.space-char {
    width: 0.3em;
}

.hero-typed-text .typed-line .char.glow-green {
    color: #34d399;
}

@keyframes charGlow {
    0% {
        text-shadow:
            0 0 12px rgba(52,211,153,0.9),
            0 0 30px rgba(52,211,153,0.5),
            0 2px 4px rgba(0,0,0,0.5);
        transform: translateY(-3px) scale(1.05);
    }
    100% {
        text-shadow:
            0 0 8px rgba(52,211,153,0.3),
            0 2px 20px rgba(0,0,0,0.35);
        transform: translateY(0) scale(1);
    }
}

.hero-particles {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    overflow: hidden;
    z-index: 0;
}

.hero-particle {
    position: absolute;
    width: 3px;
    height: 3px;
    background: rgba(52,211,153,0.5);
    border-radius: 50%;
    box-shadow: 0 0 6px rgba(52,211,153,0.4);
    animation: particleFloat linear infinite;
    opacity: 0;
}

@keyframes particleFloat {
    0% { transform: translateY(0) translateX(0); opacity: 0; }
    10% { opacity: 0.7; }
    90% { opacity: 0.7; }
    100% { transform: translateY(-120px) translateX(30px); opacity: 0; }
}

.hero-top {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
    position: relative;
    z-index: 1;
    max-width: 720px;
    margin: 0 auto;
}

.hero-greeting {
    text-align: center;
}

.hero-greeting h1 {
    font-size: 3rem;
    font-weight: 800;
    color: #fff;
    margin-bottom: 10px;
    line-height: 1.2;
    text-shadow: 0 2px 20px rgba(0,0,0,0.35);
}

.hero-greeting h1 .name-highlight {
    color: #34d399;
    position: relative;
}

.hero-greeting h1 .name-highlight::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #34d399, rgba(52,211,153,0.2));
    border-radius: 2px;
}

.hero-greeting p {
    color: rgba(203,213,225,0.92);
    font-size: 1rem;
    margin: 0;
    max-width: 520px;
    line-height: 1.7;
    text-shadow: 0 1px 8px rgba(0,0,0,0.25);
    margin-left: auto;
    margin-right: auto;
}

.hero-search {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 540px;
    margin: 0 auto;
}

.hero-search input {
    background: rgba(15,23,42,0.55);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1.5px solid rgba(100,116,139,0.2);
    border-radius: 16px;
    padding: 16px 52px 16px 22px;
    color: #e2e8f0;
    font-size: 0.95rem;
    width: 100%;
    outline: none;
    transition: all 0.35s cubic-bezier(0.4,0,0.2,1);
    box-shadow: 0 4px 24px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.04);
}

.hero-search input::placeholder {
    color: rgba(148,163,184,0.7);
    font-weight: 400;
}

.hero-search input:focus {
    border-color: rgba(52,211,153,0.55);
    box-shadow: 0 0 0 4px rgba(52,211,153,0.1), 0 8px 32px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.06);
    background: rgba(15,23,42,0.65);
}

.hero-search i {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(148,163,184,0.6);
    font-size: 0.95rem;
    transition: color 0.25s;
}

.hero-search input:focus ~ i {
    color: #34d399;
}

.hero-ctas {
    display: flex;
    gap: 14px;
    position: relative;
    z-index: 1;
    margin-top: 0;
    justify-content: center;
}

.hero-bottom {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: 28px;
    position: relative;
    z-index: 1;
    gap: 20px;
    flex-wrap: wrap;
}

.hero-weather {
    display: flex;
    align-items: center;
    gap: 12px;
    background: rgba(15,23,42,0.45);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 14px;
    padding: 10px 18px;
}

.hero-weather-icon {
    font-size: 1.6rem;
    line-height: 1;
}

.hero-weather-info {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.hero-weather-temp {
    font-size: 1.2rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}

.hero-weather-desc {
    font-size: 0.68rem;
    color: rgba(255,255,255,0.55);
    font-weight: 500;
}

.hero-weather-location {
    font-size: 0.6rem;
    color: rgba(255,255,255,0.35);
    display: flex;
    align-items: center;
    gap: 4px;
}

.hero-weather-details {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding-left: 12px;
    border-left: 1px solid rgba(255,255,255,0.08);
}

.hero-weather-detail {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.62rem;
    color: rgba(255,255,255,0.45);
}

.hero-weather-detail i {
    font-size: 0.55rem;
    color: rgba(255,255,255,0.35);
}

.hero-weather-detail span {
    color: rgba(255,255,255,0.65);
    font-weight: 500;
}

.hero-ctas .btn {
    padding: 13px 28px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    letter-spacing: 0.3px;
}

.hero-ctas .btn:hover {
    transform: translateY(-2px);
}

.hero-ctas .btn-primary-custom {
    background: linear-gradient(135deg, #0c6e5e, #10b981);
    border: none;
    color: #fff;
    box-shadow: 0 4px 16px rgba(12,110,94,0.35), inset 0 1px 0 rgba(255,255,255,0.1);
}

.hero-ctas .btn-primary-custom:hover {
    box-shadow: 0 8px 28px rgba(12,110,94,0.5), inset 0 1px 0 rgba(255,255,255,0.12);
}

.hero-ctas .btn-outline-custom {
    background: rgba(15,23,42,0.45);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(100,116,139,0.25);
    color: #e2e8f0;
}

.hero-ctas .btn-outline-custom:hover {
    border-color: rgba(52,211,153,0.4);
    color: #34d399;
    background: rgba(12,110,94,0.08);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    margin-top: 4px;
}

.section-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--db-text-muted);
    padding-left: 2px;
}

.section-header .view-all {
    font-size: 0.76rem;
    color: #0c6e5e;
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s;
    padding: 4px 10px;
    border-radius: 8px;
}

.section-header .view-all:hover {
    color: #34d399;
    background: rgba(12,110,94,0.08);
}

.hero-trust-stats {
    display: flex;
    align-items: center;
    gap: 28px;
    margin-top: 24px;
    position: relative;
    z-index: 1;
    justify-content: center;
}

.hero-trust-stat {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.78rem;
    color: rgba(203,213,225,0.75);
}

.hero-trust-stat i {
    color: #34d399;
    font-size: 0.7rem;
}

.hero-trust-stat strong {
    color: #fff;
    font-weight: 700;
}

.onboarding-wrap {
    background: var(--db-card);
    border: 1px solid var(--db-border);
    border-radius: 18px;
    padding: 28px 24px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.onboarding-wrap::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #0c6e5e, #34d399, #0c6e5e);
    opacity: 0.8;
}

.onboarding-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: rgba(12,110,94,0.1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: #0c6e5e;
    margin-bottom: 14px;
}

.onboarding-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--db-text);
    margin-bottom: 6px;
}

.onboarding-desc {
    font-size: 0.85rem;
    color: var(--db-text-muted);
    max-width: 480px;
    margin: 0 auto 16px;
    line-height: 1.6;
}

.onboarding-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}

.onboarding-actions .btn {
    padding: 10px 24px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.3s;
}

.onboarding-actions .btn-primary-custom {
    background: linear-gradient(135deg, #0c6e5e, #10b981);
    border: none;
    color: #fff;
    box-shadow: 0 4px 14px rgba(12,110,94,0.3);
}

.onboarding-actions .btn-primary-custom:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(12,110,94,0.45);
}

.onboarding-actions .btn-outline-custom {
    background: var(--db-card-alt);
    border: 1px solid var(--db-border);
    color: var(--db-text);
}

.onboarding-actions .btn-outline-custom:hover {
    border-color: #0c6e5e;
    color: #0c6e5e;
    background: rgba(12,110,94,0.05);
}

.stat-card {
    background: var(--db-card);
    border: 1px solid var(--db-border);
    border-radius: 16px;
    padding: 20px;
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
    position: relative;
    overflow: hidden;
    box-shadow: var(--db-shadow);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    border-radius: 18px 18px 0 0;
    opacity: 0;
    transition: opacity 0.35s;
}

.stat-card:hover::before {
    opacity: 1;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--db-shadow-hover);
    border-color: var(--db-border-hover);
}

.stat-card .stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.stat-card .stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    transition: all 0.35s;
}

.stat-card:hover .stat-icon {
    transform: scale(1.1);
}

.stat-card .stat-arrow {
    color: var(--db-text-muted);
    font-size: 0.8rem;
    transition: all 0.3s;
}

.stat-card:hover .stat-arrow {
    color: #34d399;
    transform: translateX(4px);
}

.stat-card .stat-value {
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 4px;
    background: linear-gradient(135deg, #1e293b, #334155);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

[data-theme="dark"] .stat-card .stat-value {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-card .stat-label {
    font-size: 0.78rem;
    color: var(--db-text);
    font-weight: 600;
}

.stat-card .stat-link {
    font-size: 0.72rem;
    color: #0c6e5e;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.25s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 12px;
}

.stat-card .stat-link:hover {
    color: #34d399;
    gap: 8px;
}

.cat-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
}

.cat-card {
    border-radius: 16px;
    overflow: hidden;
    position: relative;
    aspect-ratio: 4 / 3;
    cursor: pointer;
    transition: all 0.35s cubic-bezier(0.4,0,0.2,1);
    text-decoration: none;
    display: block;
    border: 1.5px solid rgba(30,41,59,0.3);
}

.cat-card:hover {
    transform: translateY(-6px) scale(1.02);
    box-shadow: 0 16px 40px rgba(0,0,0,0.35), 0 0 0 1px rgba(52,211,153,0.12);
    border-color: rgba(52,211,153,0.2);
}

.cat-card .cat-bg {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    transition: transform 0.65s cubic-bezier(0.4,0,0.2,1);
}

.cat-card:hover .cat-bg {
    transform: scale(1.15);
}

.cat-card .cat-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.92) 0%, rgba(0,0,0,0.40) 40%, rgba(0,0,0,0.12) 70%, rgba(0,0,0,0.05) 100%);
    z-index: 1;
    transition: all 0.4s;
}

.cat-card:hover .cat-overlay {
    background: linear-gradient(to top, rgba(0,0,0,0.95) 0%, rgba(0,0,0,0.45) 45%, rgba(0,0,0,0.08) 75%, rgba(0,0,0,0.02) 100%);
}

.cat-card .cat-content {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 16px 16px;
    z-index: 2;
    color: #fff;
}

.cat-card .cat-content .cat-icon-wrap {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255,255,255,0.12);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    margin-bottom: 10px;
    transition: all 0.35s;
}

.cat-card:hover .cat-content .cat-icon-wrap {
    background: rgba(52,211,153,0.2);
    border-color: rgba(52,211,153,0.3);
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.25);
}

.cat-card .cat-content h6 {
    font-weight: 700;
    font-size: 0.88rem;
    margin-bottom: 2px;
    color: #fff;
    transition: color 0.3s;
}

.cat-card:hover .cat-content h6 {
    color: #34d399;
}

.cat-card .cat-content span {
    font-size: 0.68rem;
    color: rgba(148,163,184,0.75);
}

.event-card-dark {
    background: var(--db-card);
    border: 1px solid var(--db-border);
    border-radius: 18px;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
    text-decoration: none;
    color: inherit;
    display: block;
    box-shadow: var(--db-shadow);
}

.event-card-dark:hover {
    transform: translateY(-6px);
    box-shadow: var(--db-shadow-hover);
    border-color: var(--db-border-hover);
    text-decoration: none;
    color: inherit;
}

.event-card-dark .event-img {
    height: 180px;
    background-size: cover;
    background-position: center;
    position: relative;
    overflow: hidden;
}

.event-card-dark .event-img::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.55) 0%, rgba(0,0,0,0.05) 50%, transparent 100%);
}

.event-card-dark .event-img .event-date-badge {
    position: absolute;
    top: 14px;
    left: 14px;
    background: rgba(17,24,39,0.88);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
    padding: 8px 12px;
    text-align: center;
    z-index: 2;
}

.event-card-dark .event-img .event-date-badge .day {
    font-size: 1.2rem;
    font-weight: 800;
    color: #34d399;
    line-height: 1;
}

.event-card-dark .event-img .event-date-badge .month {
    font-size: 0.55rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.event-card-dark .event-img .featured-badge {
    position: absolute;
    top: 14px;
    right: 14px;
    background: linear-gradient(135deg, #0c6e5e, #10b981);
    color: #fff;
    padding: 5px 11px;
    border-radius: 8px;
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    z-index: 2;
    box-shadow: 0 3px 10px rgba(12,110,94,0.4);
}

.event-card-dark .event-body {
    padding: 14px 16px 16px;
}

.event-card-dark .event-body h6 {
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--db-text-bright);
    margin-bottom: 6px;
    line-height: 1.35;
}

.event-card-dark .event-body .event-meta {
    font-size: 0.73rem;
    color: var(--db-text-muted);
    line-height: 1.7;
}

.event-card-dark .event-body .event-meta i {
    width: 14px;
    text-align: center;
    margin-right: 2px;
}

.event-card-dark .event-body .event-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid rgba(30,41,59,0.7);
}

.event-card-dark .event-body .event-fee {
    font-size: 0.88rem;
    font-weight: 700;
    color: #34d399;
}

.event-card-dark .event-body .event-time {
    font-size: 0.72rem;
    color: #64748b;
}

.tour-row {
    background: linear-gradient(145deg, #111827 0%, #0d1117 100%);
    border: 1px solid rgba(30,41,59,0.7);
    border-radius: 18px;
    overflow: hidden;
    transition: all 0.3s;
}

.tour-row:hover {
    border-color: rgba(51,65,85,0.5);
}

.tour-row .tour-item {
    display: flex;
    align-items: center;
    padding: 18px 22px;
    gap: 18px;
    transition: background 0.25s;
    border-bottom: 1px solid rgba(30,41,59,0.6);
}

.tour-row .tour-item:last-child {
    border-bottom: none;
}

.tour-row .tour-item:hover {
    background: rgba(17,24,39,0.4);
}

.tour-row .tour-date-box {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    background: rgba(12,110,94,0.1);
    border: 1px solid rgba(12,110,94,0.15);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.tour-row .tour-date-box .t-day {
    font-size: 1.15rem;
    font-weight: 800;
    color: #34d399;
    line-height: 1;
}

.tour-row .tour-date-box .t-month {
    font-size: 0.55rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
}

.tour-row .tour-info {
    flex: 1;
    min-width: 0;
}

.tour-row .tour-info .tour-name {
    font-weight: 700;
    font-size: 0.9rem;
    color: #f1f5f9;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.tour-row .tour-info .tour-dest {
    font-size: 0.73rem;
    color: #64748b;
    margin-top: 2px;
}

.tour-row .tour-status {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.tour-status.status-confirmed {
    background: rgba(16,185,129,0.12);
    color: #34d399;
    border: 1px solid rgba(16,185,129,0.15);
}

.tour-status.status-pending {
    background: rgba(245,158,11,0.12);
    color: #fbbf24;
    border: 1px solid rgba(245,158,11,0.15);
}

.empty-hero {
    text-align: center;
    padding: 40px 24px;
    color: #475569;
    background: linear-gradient(145deg, #111827 0%, #0d1117 100%);
    border: 1px dashed rgba(30,41,59,0.7);
    border-radius: 18px;
}

.empty-hero i {
    font-size: 2.4rem;
    margin-bottom: 12px;
    opacity: 0.35;
}

.empty-hero p {
    font-size: 0.88rem;
    margin: 0;
}

.empty-hero .btn {
    margin-top: 16px;
    padding: 9px 22px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.82rem;
    background: rgba(12,110,94,0.12);
    color: #0c6e5e;
    border: 1px solid rgba(12,110,94,0.2);
    text-decoration: none;
    transition: all 0.25s;
}

.empty-hero .btn:hover {
    background: rgba(12,110,94,0.2);
    border-color: rgba(12,110,94,0.35);
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.quick-action-item {
    background: var(--db-card, linear-gradient(145deg, #111827 0%, #0d1117 100%));
    border: 1px solid var(--db-border, rgba(30,41,59,0.7));
    border-radius: 14px;
    padding: 18px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    transition: all 0.35s cubic-bezier(0.4,0,0.2,1);
    position: relative;
    overflow: hidden;
}

.quick-action-item::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 16px;
    opacity: 0;
    transition: opacity 0.35s;
    pointer-events: none;
}

.quick-action-item:hover {
    transform: translateY(-4px);
    border-color: rgba(12,110,94,0.4);
    box-shadow: 0 12px 32px rgba(0,0,0,0.2);
    text-decoration: none;
}

.quick-action-item:hover::before {
    opacity: 1;
}

.quick-action-item .qa-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
    transition: all 0.35s;
}

.quick-action-item:hover .qa-icon {
    transform: scale(1.08);
}

.quick-action-item .qa-text .qa-title {
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--db-text, #e2e8f0);
    margin-bottom: 3px;
}

.quick-action-item .qa-text .qa-desc {
    font-size: 0.7rem;
    color: var(--db-text-muted, #64748b);
}

.dest-featured-card {
    background: var(--db-card, linear-gradient(145deg, #111827 0%, #0d1117 100%));
    border: 1px solid var(--db-border, rgba(30,41,59,0.7));
    border-radius: 18px;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
    text-decoration: none;
    color: inherit;
    display: block;
    position: relative;
}

.dest-featured-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 48px rgba(0,0,0,0.25);
    border-color: rgba(12,110,94,0.5);
    text-decoration: none;
    color: inherit;
}

.dest-featured-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 18px;
    opacity: 0;
    transition: opacity 0.4s;
    background: linear-gradient(135deg, rgba(12,110,94,0.06) 0%, transparent 50%);
    pointer-events: none;
}

.dest-featured-card:hover::after {
    opacity: 1;
}

.dest-featured-card .dest-img {
    height: 200px;
    background-size: cover;
    background-position: center;
    position: relative;
    overflow: hidden;
    transition: transform 0.5s cubic-bezier(0.4,0,0.2,1);
}

.dest-featured-card:hover .dest-img {
    transform: scale(1.04);
}

.dest-featured-card .dest-img::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.6) 0%, rgba(0,0,0,0.1) 40%, transparent 60%);
}

.dest-featured-card .dest-img .dest-diff {
    position: absolute;
    top: 14px;
    left: 14px;
    padding: 5px 12px;
    border-radius: 10px;
    font-size: 0.6rem;
    font-weight: 700;
    color: #fff;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

.dest-featured-card .dest-img .dest-price {
    position: absolute;
    bottom: 14px;
    right: 14px;
    padding: 5px 12px;
    border-radius: 10px;
    font-size: 0.75rem;
    font-weight: 700;
    color: #fff;
    background: rgba(12,110,94,0.85);
    backdrop-filter: blur(8px);
    z-index: 2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

.dest-featured-card .dest-body {
    padding: 16px 18px 18px;
}

.dest-featured-card .dest-body h6 {
    font-weight: 700;
    font-size: 0.9rem;
    color: var(--db-text, #f1f5f9);
    margin-bottom: 4px;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.dest-featured-card .dest-body .dest-location {
    font-size: 0.72rem;
    color: var(--db-text-muted, #64748b);
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
    max-width: 100%;
}

.dest-featured-card .dest-body .dest-location i {
    width: 12px;
}

.dest-featured-card .dest-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--db-border, rgba(30,41,59,0.6));
}

.dest-featured-card .dest-body .dest-events {
    font-size: 0.72rem;
    color: #0c6e5e;
    font-weight: 600;
}

.dest-featured-card .dest-body .dest-rating {
    font-size: 0.72rem;
    color: #f59e0b;
    font-weight: 600;
}

.dest-featured-card .dest-view {
    font-size: 0.7rem;
    color: #0c6e5e;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.25s;
}

.dest-featured-card:hover .dest-view {
    color: #34d399;
    gap: 7px;
}

@media (max-width: 768px) {
    .hero-section {
        padding: 48px 24px 40px;
        min-height: auto;
    }
    .hero-typed-text {
        font-size: 2rem;
    }
    .hero-greeting h1 {
        font-size: 2rem;
    }
    .hero-greeting p {
        font-size: 0.88rem;
    }
    .hero-search input {
        width: 100%;
        padding: 14px 46px 14px 18px;
    }
    .hero-trust-stats {
        gap: 16px;
        flex-wrap: wrap;
        justify-content: center;
    }
    .hero-trust-stat {
        font-size: 0.72rem;
    }
    .hero-bottom {
        flex-direction: column;
        gap: 14px;
        align-items: center;
    }
    .hero-weather {
        width: 100%;
        justify-content: center;
    }
    .hero-location-badge {
        font-size: 0.68rem;
    }
    .quick-actions-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .cat-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .onboarding-wrap {
        padding: 24px 16px;
    }
    .onboarding-title {
        font-size: 1rem;
    }
}

@media (min-width: 769px) and (max-width: 1199px) {
    .cat-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }
}

/* ── Floating Glass Stats Card ── */
.hero-glass-stats {
    display: flex;
    gap: 0;
    background: rgba(15,23,42,0.45);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 20px;
    padding: 0;
    margin-top: 20px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
}

.hero-glass-stat {
    flex: 1;
    text-align: center;
    padding: 18px 16px;
    border-right: 1px solid rgba(255,255,255,0.06);
    transition: background 0.3s;
}

.hero-glass-stat:last-child {
    border-right: none;
}

.hero-glass-stat:hover {
    background: rgba(255,255,255,0.04);
}

.hero-glass-stat .gstat-icon {
    font-size: 1.2rem;
    color: #34d399;
    margin-bottom: 6px;
}

.hero-glass-stat .gstat-value {
    font-size: 1.4rem;
    font-weight: 800;
    color: #fff;
    line-height: 1.1;
}

.hero-glass-stat .gstat-label {
    font-size: 0.65rem;
    color: rgba(148,163,184,0.7);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
    margin-top: 2px;
}

/* ── Why Choose BINALGO ── */
.why-section {
    padding: 32px 0 28px;
}

.why-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.why-card {
    background: var(--db-card);
    border: 1px solid var(--db-border);
    border-radius: 20px;
    padding: 32px 24px;
    text-align: center;
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
    position: relative;
    overflow: hidden;
}

.why-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--w-color, #0c6e5e), transparent);
    opacity: 0;
    transition: opacity 0.35s;
}

.why-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--db-shadow-hover);
    border-color: var(--db-border-hover);
}

.why-card:hover::before {
    opacity: 1;
}

.why-icon {
    width: 60px;
    height: 60px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    margin-bottom: 16px;
    transition: transform 0.35s;
}

.why-card:hover .why-icon {
    transform: scale(1.1) rotate(-3deg);
}

.why-card h6 {
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--db-text);
    margin-bottom: 8px;
}

.why-card p {
    font-size: 0.8rem;
    color: var(--db-text-muted);
    line-height: 1.6;
    margin: 0;
}

/* ── How It Works ── */
.how-section {
    padding: 28px 0 32px;
}

.how-steps {
    display: flex;
    align-items: flex-start;
    gap: 0;
    position: relative;
    justify-content: center;
}

.how-step {
    flex: 1;
    max-width: 300px;
    text-align: center;
    padding: 0 24px;
    position: relative;
}

.how-step-num {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: linear-gradient(135deg, #0c6e5e, #10b981);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    font-weight: 800;
    margin-bottom: 16px;
    box-shadow: 0 6px 20px rgba(12,110,94,0.3);
    position: relative;
    z-index: 1;
}

.how-step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 28px;
    left: calc(50% + 36px);
    width: calc(100% - 72px);
    height: 2px;
    background: linear-gradient(90deg, rgba(12,110,94,0.3), rgba(12,110,94,0.1));
    z-index: 0;
}

.how-step h6 {
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--db-text);
    margin-bottom: 6px;
}

.how-step p {
    font-size: 0.8rem;
    color: var(--db-text-muted);
    line-height: 1.6;
    margin: 0;
}

/* ── CTA Banner ── */
.cta-banner {
    background: linear-gradient(135deg, #0c6e5e 0%, #065f46 50%, #0c6e5e 100%);
    border-radius: 24px;
    padding: 44px 48px;
    text-align: center;
    position: relative;
    overflow: hidden;
    margin: 12px 0 24px;
}

.cta-banner::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(52,211,153,0.15) 0%, transparent 70%);
    pointer-events: none;
}

.cta-banner::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 300px;
    height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
    pointer-events: none;
}

.cta-banner h4 {
    font-size: 1.6rem;
    font-weight: 800;
    color: #fff;
    margin-bottom: 10px;
    position: relative;
    z-index: 1;
}

.cta-banner p {
    color: rgba(255,255,255,0.8);
    font-size: 0.95rem;
    max-width: 500px;
    margin: 0 auto 24px;
    position: relative;
    z-index: 1;
}

.cta-banner .btn {
    padding: 14px 36px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 0.95rem;
    position: relative;
    z-index: 1;
}

.cta-banner .btn-white {
    background: #fff;
    color: #0c6e5e;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.cta-banner .btn-white:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(0,0,0,0.25);
}

.cta-banner .btn-outline-white {
    background: transparent;
    border: 1.5px solid rgba(255,255,255,0.35);
    color: #fff;
}

.cta-banner .btn-outline-white:hover {
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.6);
    color: #fff;
}

/* ── Star Rating Display ── */
.star-display {
    display: inline-flex;
    gap: 2px;
    margin-bottom: 6px;
}

.star-display .star {
    color: #f59e0b;
    font-size: 0.7rem;
}

.star-display .star.empty {
    color: #334155;
}

/* ── Responsive for new sections ── */
@media (max-width: 991px) {
    .why-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .how-steps {
        flex-direction: column;
        align-items: center;
        gap: 32px;
    }
    .how-step:not(:last-child)::after {
        display: none;
    }
}

@media (max-width: 768px) {
    .hero-glass-stats {
        flex-wrap: wrap;
        border-radius: 16px;
    }
    .hero-glass-stat {
        flex: 1 1 45%;
        border-right: none;
        border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .why-grid {
        grid-template-columns: 1fr;
    }
    .cta-banner {
        padding: 36px 24px;
        border-radius: 18px;
    }
    .cta-banner h4 {
        font-size: 1.3rem;
    }
}
/* ── Hero Background Slider ── */
.hero-slider {
    position: absolute;
    inset: 0;
    z-index: 0;
}
.hero-slide {
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity 1.2s ease-in-out;
    background-size: cover;
    background-position: center 30%;
}
.hero-slide.active {
    opacity: 1;
}
.hero-slide-nav {
    position: absolute;
    bottom: 18px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 8px;
    z-index: 5;
}
.hero-slide-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.3);
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    padding: 0;
}
.hero-slide-dot.active {
    background: #34d399;
    width: 24px;
    border-radius: 4px;
}
.hero-slide-dot:hover {
    background: rgba(255,255,255,0.6);
}

/* ── Search Autocomplete ── */
.hero-search-wrap {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 540px;
    margin: 0 auto;
}
.hero-search-wrap .hero-search {
    width: 100%;
    margin: 0;
}
.search-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    background: rgba(15,23,42,0.92);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border: 1px solid rgba(100,116,139,0.2);
    border-radius: 16px;
    max-height: 380px;
    overflow-y: auto;
    display: none;
    box-shadow: 0 16px 48px rgba(0,0,0,0.4);
    z-index: 100;
}
.search-dropdown.show {
    display: block;
}
.search-dropdown-group {
    padding: 10px 14px 4px;
}
.search-dropdown-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: rgba(148,163,184,0.6);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.search-dropdown-label i {
    font-size: 0.6rem;
}
.search-dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    color: inherit;
}
.search-dropdown-item:hover {
    background: rgba(52,211,153,0.1);
}
.search-dropdown-item .sdi-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.search-dropdown-item .sdi-info {
    flex: 1;
    min-width: 0;
}
.search-dropdown-item .sdi-name {
    font-size: 0.82rem;
    font-weight: 600;
    color: #e2e8f0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.search-dropdown-item .sdi-meta {
    font-size: 0.68rem;
    color: rgba(148,163,184,0.6);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.search-dropdown-item .sdi-badge {
    font-size: 0.6rem;
    padding: 2px 8px;
    border-radius: 6px;
    font-weight: 600;
    flex-shrink: 0;
}
.search-dropdown-empty {
    padding: 24px;
    text-align: center;
    color: rgba(148,163,184,0.5);
    font-size: 0.82rem;
}
.search-dropdown-empty i {
    display: block;
    font-size: 1.4rem;
    margin-bottom: 8px;
    color: rgba(148,163,184,0.3);
}

/* ── Floating Weather Glassmorphism ── */
.hero-weather-float {
    position: absolute;
    top: 20px;
    right: 20px;
    z-index: 5;
    background: rgba(15,23,42,0.4);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 16px;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.25);
    transition: all 0.3s;
}
.hero-weather-float:hover {
    background: rgba(15,23,42,0.55);
    border-color: rgba(255,255,255,0.12);
}
.hero-weather-float .hwf-icon {
    font-size: 1.5rem;
    line-height: 1;
}
.hero-weather-float .hwf-info {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.hero-weather-float .hwf-temp {
    font-size: 1.1rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}
.hero-weather-float .hwf-desc {
    font-size: 0.65rem;
    color: rgba(255,255,255,0.5);
    font-weight: 500;
}
.hero-weather-float .hwf-details {
    display: flex;
    gap: 10px;
    padding-left: 10px;
    border-left: 1px solid rgba(255,255,255,0.08);
}
.hero-weather-float .hwf-detail {
    font-size: 0.58rem;
    color: rgba(255,255,255,0.4);
    display: flex;
    align-items: center;
    gap: 3px;
}
.hero-weather-float .hwf-detail span {
    color: rgba(255,255,255,0.65);
    font-weight: 500;
}

/* ── Quick Access Grid ── */
.quick-access-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
.quick-access-card {
    background: var(--db-card);
    border: 1px solid var(--db-border);
    border-radius: 14px;
    padding: 18px 16px;
    text-decoration: none;
    color: inherit;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.35s cubic-bezier(0.4,0,0.2,1);
    position: relative;
    overflow: hidden;
}
.quick-access-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 14px;
    opacity: 0;
    transition: opacity 0.35s;
    pointer-events: none;
}
.quick-access-card:hover {
    transform: translateY(-4px);
    border-color: rgba(12,110,94,0.4);
    box-shadow: 0 12px 32px rgba(0,0,0,0.2);
    text-decoration: none;
    color: inherit;
}
.quick-access-card .qa-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
    transition: all 0.35s;
}
.quick-access-card:hover .qa-icon {
    transform: scale(1.08);
}
.quick-access-card .qa-text .qa-title {
    font-weight: 700;
    font-size: 0.85rem;
    color: var(--db-text);
    margin-bottom: 3px;
}
.quick-access-card .qa-text .qa-desc {
    font-size: 0.7rem;
    color: var(--db-text-muted);
}
@media (max-width: 768px) {
    .quick-access-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .hero-weather-float {
        position: relative;
        top: auto;
        right: auto;
        margin-top: 14px;
    }
}
</style>

<?php
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>

<div class="dashboard-wrap">

    <!-- Hero Section -->
    <div class="hero-section">
        <!-- Background Slider -->
        <div class="hero-slider" id="heroSlider">
            <?php foreach ($hero_slides as $i => $slide): ?>
                <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>" style="background-image:url('<?= $slide['img'] ?>');" data-alt="<?= $slide['alt'] ?>"></div>
            <?php endforeach; ?>
        </div>
        <div class="hero-slide-nav" id="heroSlideNav">
            <?php foreach ($hero_slides as $i => $slide): ?>
                <button class="hero-slide-dot<?= $i === 0 ? ' active' : '' ?>" data-index="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>

        <!-- Floating Weather -->
        <div class="hero-weather-float">
            <div class="hwf-icon"><i class="fas fa-cloud-sun"></i></div>
            <div class="hwf-info">
                <div class="hwf-temp">32°C</div>
                <div class="hwf-desc">Partly Cloudy</div>
            </div>
            <div class="hwf-details">
                <div class="hwf-detail"><i class="fas fa-droplet"></i> <span>78%</span></div>
                <div class="hwf-detail"><i class="fas fa-wind"></i> <span>12 km/h</span></div>
                <div class="hwf-detail"><i class="fas fa-sun"></i> UV <span>6</span></div>
            </div>
        </div>

        <div class="hero-particles" id="heroParticles"></div>
        <div class="hero-top">
            <div class="hero-greeting">
                <div class="hero-typed-wrap">
                    <div class="hero-typed-text" id="heroTyped">
                        <span class="typed-line" id="typedLine1"></span>
                        <span class="typed-line" id="typedLine2"></span>
                    </div>
                </div>
                <p>Discover pristine beaches, lush mangroves, and breathtaking tropical landscapes in Binalbagan, Negros Occidental.</p>
            </div>
            <div class="hero-search-wrap">
                <div class="hero-search">
                    <input type="text" placeholder="Search destinations, events, tours..." id="heroSearch" autocomplete="off">
                    <i class="fas fa-search"></i>
                </div>
                <div class="search-dropdown" id="searchDropdown"></div>
            </div>
            <div class="hero-trust-stats">
                <div class="hero-trust-stat"><i class="fas fa-map-marked-alt"></i> <strong><?= $total_destinations ?></strong> Destinations</div>
                <div class="hero-trust-stat"><i class="fas fa-calendar-star"></i> <strong><?= count($upcoming_events) ?></strong> Upcoming Events</div>
                <div class="hero-trust-stat"><i class="fas fa-shield-halved"></i> Secure Booking</div>
                <div class="hero-trust-stat"><i class="fas fa-star"></i> Trusted by Locals</div>
            </div>
        </div>
        <div class="hero-bottom">
            <div class="hero-ctas">
                <a href="destinations.php" class="btn btn-primary-custom">
                    <i class="fas fa-compass me-1"></i>Explore Destinations
                </a>
                <a href="events.php" class="btn btn-outline-custom">
                    <i class="fas fa-calendar me-1"></i>View Events
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Access -->
    <div class="section-header">
        <div class="section-label">Quick Access</div>
    </div>

    <?php
    $hasActivity = $total_bookings > 0 || $completed_count > 0;
    ?>

    <?php if (!$hasActivity): ?>
    <!-- Quick Access for New Users -->
    <div class="quick-access-grid mb-4">
        <a href="destinations.php" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-compass"></i></div>
            <div class="qa-text">
                <div class="qa-title">Explore Destinations</div>
                <div class="qa-desc"><?= $total_destinations ?> spots to discover</div>
            </div>
        </a>
        <a href="events.php" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-calendar-star"></i></div>
            <div class="qa-text">
                <div class="qa-title">Browse Events</div>
                <div class="qa-desc"><?= count($upcoming_events) ?> upcoming</div>
            </div>
        </a>
        <a href="destinations.php?category=nature_adventure" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fas fa-mountain"></i></div>
            <div class="qa-text">
                <div class="qa-title">Nature & Adventure</div>
                <div class="qa-desc"><?= $all_cats['nature_adventure'] ?? 0 ?> spots</div>
            </div>
        </a>
        <a href="about.php" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(139,92,246,0.1);color:#8b5cf6;"><i class="fas fa-info-circle"></i></div>
            <div class="qa-text">
                <div class="qa-title">About Binalbagan</div>
                <div class="qa-desc">Learn more</div>
            </div>
        </a>
    </div>
    <?php else: ?>
    <!-- Quick Access for Active Users -->
    <div class="quick-access-grid mb-4">
        <a href="bookings.php" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(59,130,246,0.1);color:#3b82f6;"><i class="fas fa-ticket"></i></div>
            <div class="qa-text">
                <div class="qa-title">My Bookings</div>
                <div class="qa-desc"><?= $total_bookings ?> total</div>
            </div>
        </a>
        <a href="destinations.php" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;"><i class="fas fa-compass"></i></div>
            <div class="qa-text">
                <div class="qa-title">Explore More</div>
                <div class="qa-desc"><?= $total_destinations ?> destinations</div>
            </div>
        </a>
        <a href="events.php" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-calendar-star"></i></div>
            <div class="qa-text">
                <div class="qa-title">Upcoming Events</div>
                <div class="qa-desc"><?= count($upcoming_events) ?> events</div>
            </div>
        </a>
        <a href="feedback.php" class="quick-access-card">
            <div class="qa-icon" style="background:rgba(236,72,153,0.1);color:#ec4899;"><i class="fas fa-comment-dots"></i></div>
            <div class="qa-text">
                <div class="qa-title">Give Feedback</div>
                <div class="qa-desc">Share your experience</div>
            </div>
        </a>
    </div>
    <?php endif; ?>

    <!-- Explore by Category -->
    <div class="section-header">
        <div class="section-label">Explore by Category</div>
        <a href="destinations.php" class="view-all">View all <i class="fas fa-chevron-right" style="font-size:0.55rem;"></i></a>
    </div>
    <div class="cat-grid mb-4">
        <?php
        $cat_images = [
            'beaches'            => 'assets/images/images%20(3).jpg',
            'nature_adventure'   => 'assets/images/bambi.jpg',
            'heritage_culture'   => 'assets/images/images%20(6).jpg',
            'food_local'         => 'assets/images/image%20(10).jpg',
            'religious_sites'    => 'assets/images/images%20(9).jpg',
        ];
        foreach ($cat_labels as $key => $info):
            $count = $all_cats[$key] ?? 0;
            $imgPath = $cat_images[$key] ?? 'assets/images/bambi.jpg';
        ?>
            <a href="destinations.php?category=<?= urlencode($key) ?>" class="cat-card">
                <div class="cat-bg" style="background-image:url('<?= BASE_URL ?>/<?= $imgPath ?>');"></div>
                <div class="cat-overlay"></div>
                <div class="cat-content">
                    <div class="cat-icon-wrap"><i class="<?= $info['icon'] ?>"></i></div>
                    <h6><?= $info['label'] ?></h6>
                    <span><?= $count > 0 ? $count . ' place' . ($count !== 1 ? 's' : '') : 'Explore' ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Upcoming Events -->
    <?php if (!empty($upcoming_events)): ?>
    <div class="section-header">
        <div class="section-label">Upcoming Events</div>
        <a href="events.php" class="view-all">View all <i class="fas fa-chevron-right" style="font-size:0.55rem;"></i></a>
    </div>
    <div class="row g-3 mb-4">
        <?php foreach ($upcoming_events as $ev):
            $evDate = $ev['event_start_date'] ? date('M', strtotime($ev['event_start_date'])) : 'TBA';
            $evDay = $ev['event_start_date'] ? date('d', strtotime($ev['event_start_date'])) : '—';
            $evImg = $ev['event_image'] ? BASE_URL . '/uploads/events/' . $ev['event_image'] : BASE_URL . '/assets/images/bambi.jpg';
            $evTime = $ev['event_start_time'] ? date('h:i A', strtotime($ev['event_start_time'])) : '';
        ?>
        <div class="col-sm-6 col-lg-3">
            <a href="event_detail.php?id=<?= $ev['id'] ?>" class="event-card-dark">
                <div class="event-img" style="background-image:url('<?= $evImg ?>');">
                    <div class="featured-badge"><i class="fas fa-star me-1" style="font-size:0.5rem;"></i>Featured</div>
                    <div class="event-date-badge">
                        <div class="day"><?= $evDay ?></div>
                        <div class="month"><?= $evDate ?></div>
                    </div>
                </div>
                <div class="event-body">
                    <h6><?= sanitize(truncate($ev['title'], 42)) ?></h6>
                    <div class="event-meta">
                        <?php if ($ev['destination_name']): ?><div><i class="fas fa-map-pin me-1"></i><?= sanitize($ev['destination_name']) ?></div><?php endif; ?>
                        <?php if ($ev['event_location']): ?><div><i class="fas fa-location-dot me-1"></i><?= sanitize($ev['event_location']) ?></div><?php endif; ?>
                    </div>
                    <div class="event-bottom">
                        <div class="event-fee"><?= $ev['event_fee'] > 0 ? '₱' . number_format($ev['event_fee'], 0) : 'Free' ?></div>
                        <?php if ($evTime): ?><div class="event-time"><i class="fas fa-clock me-1"></i><?= $evTime ?></div><?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Upcoming Tours -->
    <?php if (!empty($upcoming_bookings)): ?>
    <div class="section-header">
        <div class="section-label">Your Upcoming Tours</div>
        <a href="bookings.php" class="view-all">View all <i class="fas fa-chevron-right" style="font-size:0.55rem;"></i></a>
    </div>
    <div class="tour-row mb-4">
        <?php foreach ($upcoming_bookings as $b):
            $bDate = $b['start_date'] ? date('M', strtotime($b['start_date'])) : 'TBA';
            $bDay = $b['start_date'] ? date('d', strtotime($b['start_date'])) : '—';
            $bTime = $b['start_time'] ? date('h:i A', strtotime($b['start_time'])) : '';
            $statusClass = $b['status'] === 'confirmed' ? 'status-confirmed' : 'status-pending';
        ?>
        <div class="tour-item">
            <div class="tour-date-box">
                <div class="t-day"><?= $bDay ?></div>
                <div class="t-month"><?= $bDate ?></div>
            </div>
            <div class="tour-info">
                <div class="tour-name"><?= sanitize($b['event_name']) ?></div>
                <div class="tour-dest"><i class="fas fa-map-pin me-1"></i><?= sanitize($b['destination_name']) ?><?= $bTime ? ' &middot; ' . $bTime : '' ?></div>
            </div>
            <div class="tour-status <?= $statusClass ?>"><?= ucfirst($b['status']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Featured Destinations -->
    <?php if (!empty($recent_destinations)): ?>
    <div class="section-header">
        <div class="section-label">Featured Destinations</div>
        <a href="destinations.php" class="view-all">View all <i class="fas fa-chevron-right" style="font-size:0.55rem;"></i></a>
    </div>
    <div class="row g-3 mb-4">
        <?php foreach ($recent_destinations as $rd):
            $diffColors = ['easy'=>'#10b981','moderate'=>'#f59e0b','difficult'=>'#ef4444','extreme'=>'#dc2626'];
            $diffColor = $diffColors[$rd['difficulty']] ?? '#64748b';
            $rdImg = $rd['image'] ? BASE_URL . '/uploads/destinations/' . $rd['image'] : BASE_URL . '/assets/images/bambi.jpg';
        ?>
        <div class="col-sm-6 col-lg-4">
            <a href="destination_detail.php?id=<?= $rd['id'] ?>" class="dest-featured-card">
                <div class="dest-img" style="background-image:url('<?= $rdImg ?>');">
                    <span class="dest-diff" style="background:<?= $diffColor ?>;"><?= ucfirst($rd['difficulty']) ?></span>
                    <?php if ($rd['entrance_fee'] > 0): ?>
                        <span class="dest-price">₱<?= number_format($rd['entrance_fee'], 0) ?></span>
                    <?php else: ?>
                        <span class="dest-price">Free</span>
                    <?php endif; ?>
                </div>
                <div class="dest-body">
                    <h6><?= sanitize($rd['name']) ?></h6>
                    <div class="dest-location"><i class="fas fa-map-pin me-1"></i><?= sanitize(truncate($rd['location'], 50)) ?></div>
                    <div style="font-size:.78rem;color:var(--text-muted,#64748b);margin:6px 0 4px;">
                        <i class="fas fa-star" style="color:#f59e0b;"></i>
                        <span style="font-weight:700;color:var(--text-primary,#1e293b);">4.9</span>
                        <span>(248)</span>
                    </div>
                    <div class="dest-bottom">
                        <div class="dest-events"><i class="fas fa-calendar me-1"></i><?= $rd['event_count'] ?> event<?= $rd['event_count'] !== 1 ? 's' : '' ?></div>
                        <div class="dest-view">View <i class="fas fa-chevron-right" style="font-size:0.55rem;"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Hero Background Slider ──
    (function() {
        var slides = document.querySelectorAll('.hero-slide');
        var dots = document.querySelectorAll('.hero-slide-dot');
        if (slides.length === 0) return;
        var current = 0;
        var interval = null;

        function goTo(index) {
            slides[current].classList.remove('active');
            dots[current].classList.remove('active');
            current = index;
            slides[current].classList.add('active');
            dots[current].classList.add('active');
        }

        function next() {
            goTo((current + 1) % slides.length);
        }

        function startAuto() {
            interval = setInterval(next, 4500);
        }

        dots.forEach(function(dot) {
            dot.addEventListener('click', function() {
                clearInterval(interval);
                goTo(parseInt(this.dataset.index));
                startAuto();
            });
        });

        startAuto();
    })();

    // ── Search Autocomplete ──
    (function() {
        var input = document.getElementById('heroSearch');
        var dropdown = document.getElementById('searchDropdown');
        if (!input || !dropdown) return;

        var destinations = <?= json_encode(array_map(function($d) {
            return [
                'id' => $d['id'],
                'name' => $d['name'],
                'location' => $d['location'],
                'category' => $d['category'],
                'fee' => $d['entrance_fee'],
                'type' => 'destination'
            ];
        }, $all_destinations_for_search)) ?>;

        var events = <?= json_encode(array_map(function($e) {
            return [
                'id' => $e['id'],
                'title' => $e['title'],
                'date' => $e['event_start_date'],
                'location' => $e['event_location'],
                'dest' => $e['destination_name'],
                'type' => 'event'
            ];
        }, $all_events_for_search)) ?>;

        var catColors = <?= json_encode(array_map(function($c) { return $c['color']; }, $cat_labels)) ?>;

        function renderDropdown(query) {
            if (!query || query.length < 2) {
                dropdown.classList.remove('show');
                return;
            }
            var q = query.toLowerCase();
            var matchedDests = destinations.filter(function(d) {
                return d.name.toLowerCase().indexOf(q) !== -1 || (d.location && d.location.toLowerCase().indexOf(q) !== -1);
            }).slice(0, 5);
            var matchedEvents = events.filter(function(e) {
                return e.title.toLowerCase().indexOf(q) !== -1 || (e.dest && e.dest.toLowerCase().indexOf(q) !== -1);
            }).slice(0, 3);

            if (matchedDests.length === 0 && matchedEvents.length === 0) {
                dropdown.innerHTML = '<div class="search-dropdown-empty"><i class="fas fa-search"></i>No results for "' + query + '"</div>';
                dropdown.classList.add('show');
                return;
            }

            var html = '';
            if (matchedDests.length > 0) {
                html += '<div class="search-dropdown-group"><div class="search-dropdown-label"><i class="fas fa-map-marked-alt"></i> Destinations</div></div>';
                matchedDests.forEach(function(d) {
                    var color = catColors[d.category] || '#6b7280';
                    var fee = d.fee > 0 ? '₱' + d.fee : 'Free';
                    html += '<a href="destination_detail.php?id=' + d.id + '" class="search-dropdown-item">' +
                        '<div class="sdi-icon" style="background:' + color + '22;color:' + color + ';"><i class="fas fa-map-marker-alt"></i></div>' +
                        '<div class="sdi-info"><div class="sdi-name">' + d.name + '</div><div class="sdi-meta">' + (d.location || '') + '</div></div>' +
                        '<span class="sdi-badge" style="background:' + color + '22;color:' + color + ';">' + fee + '</span>' +
                        '</a>';
                });
            }
            if (matchedEvents.length > 0) {
                html += '<div class="search-dropdown-group"><div class="search-dropdown-label"><i class="fas fa-calendar"></i> Events</div></div>';
                matchedEvents.forEach(function(e) {
                    var dateStr = e.date ? new Date(e.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '';
                    html += '<a href="event_detail.php?id=' + e.id + '" class="search-dropdown-item">' +
                        '<div class="sdi-icon" style="background:rgba(245,158,11,0.12);color:#f59e0b;"><i class="fas fa-calendar-star"></i></div>' +
                        '<div class="sdi-info"><div class="sdi-name">' + e.title + '</div><div class="sdi-meta">' + (e.dest || '') + (dateStr ? ' · ' + dateStr : '') + '</div></div>' +
                        '<span class="sdi-badge" style="background:rgba(245,158,11,0.12);color:#f59e0b;">Event</span>' +
                        '</a>';
                });
            }

            html += '<a href="destinations.php?search=' + encodeURIComponent(query) + '" class="search-dropdown-item" style="border-top:1px solid rgba(255,255,255,0.06);margin-top:4px;padding-top:12px;">' +
                '<div class="sdi-icon" style="background:rgba(12,110,94,0.12);color:#0c6e5e;"><i class="fas fa-arrow-right"></i></div>' +
                '<div class="sdi-info"><div class="sdi-name">View all results for "' + query + '"</div><div class="sdi-meta">Search destinations & events</div></div>' +
                '</a>';

            dropdown.innerHTML = html;
            dropdown.classList.add('show');
        }

        var debounce = null;
        input.addEventListener('input', function() {
            var val = this.value.trim();
            clearTimeout(debounce);
            debounce = setTimeout(function() { renderDropdown(val); }, 200);
        });

        input.addEventListener('focus', function() {
            var val = this.value.trim();
            if (val.length >= 2) renderDropdown(val);
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.hero-search-wrap')) {
                dropdown.classList.remove('show');
            }
        });

        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                var q = this.value.trim();
                if (q) {
                    window.location.href = 'destinations.php?search=' + encodeURIComponent(q);
                }
            }
        });
    })();

    // ── Hero Typing Animation ──
    (function() {
        var line1 = document.getElementById('typedLine1');
        var line2 = document.getElementById('typedLine2');
        if (!line1 || !line2) return;

        var text1 = '<?= $greeting ?>,';
        var text2 = '<?= explode(' ', $user['name'])[0] ?>';

        function buildChars(el, text) {
            el.innerHTML = '';
            for (var i = 0; i < text.length; i++) {
                var span = document.createElement('span');
                span.className = 'char' + (text[i] === ' ' ? ' space-char' : '');
                span.textContent = text[i] === ' ' ? '\u00A0' : text[i];
                el.appendChild(span);
            }
        }

        buildChars(line1, text1);
        buildChars(line2, text2);

        var allChars1 = line1.querySelectorAll('.char');
        var allChars2 = line2.querySelectorAll('.char');
        var allChars = Array.prototype.slice.call(allChars1).concat(Array.prototype.slice.call(allChars2));

        allChars2.forEach(function(c) { c.classList.add('glow-green'); });

        function sleep(ms) { return new Promise(function(r) { setTimeout(r, ms); }); }

        async function typeLoop() {
            while (true) {
                for (var j = 0; j < allChars.length; j++) {
                    allChars[j].classList.remove('visible');
                }
                await sleep(600);

                for (var j = 0; j < allChars.length; j++) {
                    allChars[j].classList.add('visible');
                    var delay = allChars[j].textContent === '\u00A0' ? 30 : (55 + Math.random() * 55);
                    if (j > 0 && j === allChars1.length) delay = 350;
                    await sleep(delay);
                }
                await sleep(2000);
            }
        }

        typeLoop();
    })();

    // ── Hero Particles ──
    (function() {
        var container = document.getElementById('heroParticles');
        if (!container) return;
        for (var i = 0; i < 15; i++) {
            var p = document.createElement('div');
            p.className = 'hero-particle';
            p.style.left = Math.random() * 100 + '%';
            p.style.top = (60 + Math.random() * 40) + '%';
            p.style.width = (2 + Math.random() * 3) + 'px';
            p.style.height = p.style.width;
            p.style.animationDuration = (3 + Math.random() * 4) + 's';
            p.style.animationDelay = Math.random() * 5 + 's';
            container.appendChild(p);
        }
    })();
});
</script>

<?php }); ?>
