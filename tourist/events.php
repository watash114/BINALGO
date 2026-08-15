<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];

$search = trim($_GET['search'] ?? '');
$catFilter = $_GET['category'] ?? '';
$when = $_GET['when'] ?? '';
$view = $_GET['view'] ?? 'grid';
if (!in_array($view, ['grid', 'list', 'calendar'], true)) $view = 'grid';
$pastMode = isset($_GET['past']) && $_GET['past'] === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = $view === 'list' ? 10 : 9;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bookmark'])) {
    if (!verify_token($_POST['csrf_token'] ?? '')) {
        redirect('/tourist/events.php');
    }
    $eid = (int)($_POST['event_id'] ?? 0);
    $check = $db->prepare("SELECT id FROM event_bookmarks WHERE event_id = :eid AND user_id = :uid");
    $check->execute([':eid' => $eid, ':uid' => $user_id]);
    if ($check->fetch()) {
        $db->prepare("DELETE FROM event_bookmarks WHERE event_id = :eid AND user_id = :uid")->execute([':eid' => $eid, ':uid' => $user_id]);
    } else {
        $db->prepare("INSERT INTO event_bookmarks (event_id, user_id, created_at) VALUES (:eid, :uid, db_now())")->execute([':eid' => $eid, ':uid' => $user_id]);
    }
    $qs = $_GET;
    unset($qs['page']);
    redirect('/tourist/events.php' . ($qs ? '?' . http_build_query($qs) : ''));
}

$catLabels = [
    'festival' => ['label' => 'Festival', 'icon' => 'fa-masks-theater', 'color' => '#ec4899'],
    'cultural_event' => ['label' => 'Cultural', 'icon' => 'fa-landmark', 'color' => '#f97316'],
    'tourism_event' => ['label' => 'Tourism', 'icon' => 'fa-plane', 'color' => '#3b82f6'],
    'workshop' => ['label' => 'Workshop', 'icon' => 'fa-chalkboard-user', 'color' => '#8b5cf6'],
    'community_event' => ['label' => 'Community', 'icon' => 'fa-people-group', 'color' => '#10b981'],
    'sports' => ['label' => 'Sports', 'icon' => 'fa-trophy', 'color' => '#ef4444'],
    'arts' => ['label' => 'Arts', 'icon' => 'fa-palette', 'color' => '#06b6d4'],
    'other' => ['label' => 'Other', 'icon' => 'fa-ellipsis', 'color' => '#64748b'],
];

$where = ["e.status = 'published'"];
$params = [];

if ($pastMode) {
    $where[] = "e.event_start_date IS NOT NULL AND e.event_start_date < db_curdate()";
} else {
    $where[] = "(e.event_start_date >= db_curdate() OR e.event_start_date IS NULL)";
}

if ($search) {
    $where[] = "(e.title LIKE :search OR e.description LIKE :search2 OR e.event_location LIKE :search3)";
    $params[':search'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
    $params[':search3'] = '%' . $search . '%';
}
if ($catFilter) {
    $where[] = "e.category = :cat";
    $params[':cat'] = $catFilter;
}

$calMonth = '';
if ($when === 'this_month') {
    $where[] = "db_date_format(, '') = :month";
    $params[':month'] = date('Y-m');
} elseif ($when === 'this_weekend') {
    $dow = (int) date('w');
    if ($dow === 0) {
        $wkStart = date('Y-m-d');
        $wkEnd = date('Y-m-d');
    } elseif ($dow === 6) {
        $wkStart = date('Y-m-d');
        $wkEnd = date('Y-m-d', strtotime('+1 day'));
    } else {
        $wkStart = date('Y-m-d', strtotime('next saturday'));
        $wkEnd = date('Y-m-d', strtotime('next saturday +1 day'));
    }
    $where[] = "(e.event_start_date BETWEEN :wk_start AND :wk_end)";
    $params[':wk_start'] = $wkStart;
    $params[':wk_end'] = $wkEnd;
} elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $when)) {
    $calMonth = $when;
    $where[] = "db_date_format(, '') = :month";
    $params[':month'] = $when;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) as total FROM events e {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetch()['total'];
$totalPages = max(1, (int) ceil($total / $per_page));
$page = min($page, $totalPages);
$offset = ($page - 1) * $per_page;

$orderBy = $pastMode ? 'e.event_start_date DESC' : 'e.event_start_date ASC, e.event_start_time ASC';

$eventsStmt = $db->prepare(
    "SELECT e.*, d.name as destination_name, d.location as destination_location, d.image as dest_image,
            (SELECT COUNT(*) FROM bookings b JOIN schedules s2 ON b.schedule_id = s2.id WHERE s2.event_id = e.id AND b.status IN ('confirmed','completed')) as attendee_count,
            (SELECT COUNT(*) FROM event_bookmarks WHERE event_id = e.id) as bookmark_count
     FROM events e
     LEFT JOIN destinations d ON e.destination_id = d.id
     {$whereClause}
     ORDER BY {$orderBy}
     LIMIT {$per_page} OFFSET {$offset}"
);
$eventsStmt->execute($params);
$events = $eventsStmt->fetchAll();

$bookmarkedIds = [];
$bmStmt = $db->prepare("SELECT event_id FROM event_bookmarks WHERE user_id = :uid");
$bmStmt->execute([':uid' => $user_id]);
foreach ($bmStmt->fetchAll() as $bm) $bookmarkedIds[] = $bm['event_id'];

$featured = [];
if (!$pastMode && !$search && !$catFilter && !$when) {
    $featured = $db->query(
        "SELECT e.*, d.name as destination_name, d.location as destination_location, d.image as dest_image,
                (SELECT COUNT(*) FROM bookings b JOIN schedules s2 ON b.schedule_id = s2.id WHERE s2.event_id = e.id AND b.status IN ('confirmed','completed')) as attendee_count
         FROM events e
         LEFT JOIN destinations d ON e.destination_id = d.id
         WHERE e.status = 'published' AND e.event_start_date >= db_curdate()
         ORDER BY e.event_start_date ASC
         LIMIT 3"
    )->fetchAll();
}

/* --- Calendar month grid data --- */
$calEventsByDay = [];
$calBlankStart = 0;
$calDaysInMonth = 0;
$calLabel = '';
$calPrevMonth = '';
$calNextMonth = '';
if ($view === 'calendar') {
    $calMonth = $calMonth ?: date('Y-m');
    $calLabel = date('F Y', strtotime($calMonth . '-01'));
    $calBlankStart = (int) date('w', strtotime($calMonth . '-01'));
    $calDaysInMonth = (int) date('t', strtotime($calMonth . '-01'));
    $calPrevMonth = date('Y-m', strtotime($calMonth . '-01 -1 month'));
    $calNextMonth = date('Y-m', strtotime($calMonth . '-01 +1 month'));

    $calStmt = $db->prepare(
        "SELECT e.*, d.name as destination_name, d.location as destination_location, d.image as dest_image,
                (SELECT COUNT(*) FROM bookings b JOIN schedules s2 ON b.schedule_id = s2.id WHERE s2.event_id = e.id AND b.status IN ('confirmed','completed')) as attendee_count
         FROM events e
         LEFT JOIN destinations d ON e.destination_id = d.id
         WHERE e.status = 'published' AND db_date_format(, '') = :month
         ORDER BY e.event_start_date ASC, e.event_start_time ASC"
    );
    $calStmt->execute([':month' => $calMonth]);
    foreach ($calStmt->fetchAll() as $cev) {
        $day = (int) date('j', strtotime($cev['event_start_date']));
        $calEventsByDay[$day][] = $cev;
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

$monthOptions = [];
for ($i = 0; $i < 6; $i++) {
    $ts = strtotime("+{$i} month", strtotime(date('Y-m-01')));
    $monthOptions[date('Y-m', $ts)] = date('F Y', $ts);
}

$placeholderImg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='600' height='360'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%230c6e5e'/%3E%3Cstop offset='1' stop-color='%2310b981'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='600' height='360' fill='url(%23g)'/%3E%3Crect x='230' y='100' width='140' height='150' rx='18' fill='rgba(255,255,255,0.14)'/%3E%3Crect x='230' y='100' width='140' height='34' rx='18' fill='rgba(255,255,255,0.25)'/%3E%3Ctext x='300' y='175' font-size='24' text-anchor='middle' fill='rgba(255,255,255,0.9)' font-family='Arial'%3E15%3C/text%3E%3Ctext x='300' y='200' font-size='12' text-anchor='middle' fill='rgba(255,255,255,0.7)' font-family='Arial'%3EMAY%3C/text%3E%3C/svg%3E";

if (!function_exists('empty_svg')) {
function empty_svg(): string
{
    return <<<SVG
<svg viewBox="0 0 220 150" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <defs>
        <linearGradient id="eg" x1="0" y1="0" x2="220" y2="150">
            <stop offset="0" stop-color="#0c6e5e"/><stop offset="1" stop-color="#10b981"/>
        </linearGradient>
    </defs>
    <rect x="40" y="28" width="120" height="104" rx="18" fill="url(#eg)" opacity="0.18"/>
    <rect x="56" y="44" width="88" height="18" rx="9" fill="url(#eg)"/>
    <rect x="56" y="72" width="64" height="10" rx="5" fill="url(#eg)" opacity="0.5"/>
    <rect x="56" y="90" width="76" height="10" rx="5" fill="url(#eg)" opacity="0.5"/>
    <rect x="56" y="108" width="40" height="10" rx="5" fill="url(#eg)" opacity="0.5"/>
    <circle cx="166" cy="40" r="14" fill="#fff" stroke="#e2e8f0" stroke-width="2"/>
    <path d="M161 35l10 10M170 31l7 7" stroke="#64748b" stroke-width="2.4" stroke-linecap="round"/>
    <circle cx="46" cy="40" r="5" fill="#34d399"/>
    <circle cx="184" cy="104" r="4" fill="#34d399" opacity="0.7"/>
    <circle cx="40" cy="118" r="3" fill="#34d399" opacity="0.5"/>
    <path d="M156 118l10-8" stroke="#34d399" stroke-width="2" stroke-linecap="round" opacity="0.6"/>
    <path d="M30 78l8-6" stroke="#34d399" stroke-width="2" stroke-linecap="round" opacity="0.6"/>
</svg>
SVG;
}
}

if (!function_exists('event_card')) {
function event_card(array $ev, bool $isBookmarked, bool $featured, bool $pastMode, string $placeholderImg, string $view = 'grid'): void
{
    global $catLabelsGlobal;
    $catInfo = $catLabelsGlobal[$ev['category']] ?? $catLabelsGlobal['other'];
    $img = !empty($ev['event_image']) ? event_image_url($ev['event_image'])
        : (!empty($ev['dest_image']) ? dest_image_url($ev['dest_image']) : $placeholderImg);
    $startTs = strtotime($ev['event_start_date']);
    $loc = $ev['event_location'] ?: ($ev['destination_name'] ?? 'Binalbagan');
    $price = (float) ($ev['price'] ?? 0);
    $dateStr = $ev['event_start_date'] ? $ev['event_start_date'] . ' ' . ($ev['event_start_time'] ?? '00:00:00') : '';
    $detailUrl = BASE_URL . '/tourist/event_detail.php?id=' . $ev['id'];
    $startIcs = $ev['event_start_date'] . 'T' . ($ev['event_start_time'] ?: '00:00:00');
    $endIcs = $ev['event_end_date']
        ? $ev['event_end_date'] . 'T' . ($ev['event_end_time'] ?: '00:00:00')
        : $ev['event_start_date'] . 'T' . ($ev['event_end_time'] ?: $ev['event_start_time'] ?: '00:00:00');
    $escTitle = sanitize($ev['title']);
    $escLoc = sanitize($loc);
    $escDesc = sanitize(mb_strimwidth($ev['description'] ?? '', 0, 180, '…'));
    ?>
    <div class="ev-card <?= $view === 'list' ? 'ev-card-list' : '' ?> h-100">
        <div class="ev-thumb">
            <img src="<?= $img ?>" alt="<?= $escTitle ?>" onerror="setFallbackImg(this)">
            <?php if ($startTs && $ev['event_start_date']): ?>
                <div class="date-badge"><span class="db-month"><?= strtoupper(date('M', $startTs)) ?></span><span class="db-day"><?= date('j', $startTs) ?></span></div>
            <?php endif; ?>
            <?php if ($featured): ?>
                <span class="ev-corner-badge"><i class="fas fa-fire"></i></span>
            <?php else: ?>
                <form method="POST" class="ev-save-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="toggle_bookmark" value="1">
                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                    <button type="submit" class="ev-save <?= $isBookmarked ? 'active' : '' ?>" title="<?= $isBookmarked ? 'Remove from saved' : 'Save event' ?>">
                        <i class="fas fa-heart<?= $isBookmarked ? '' : '-slash' ?>"></i>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="ev-body">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="cat-badge" style="background:<?= $catInfo['color'] ?>;"><i class="fas <?= $catInfo['icon'] ?>"></i><?= $catInfo['label'] ?></span>
                <?php if ($pastMode): ?><span class="past-chip"><i class="fas fa-check"></i>Ended</span><?php endif; ?>
            </div>
            <h5 class="ev-title"><?= $escTitle ?></h5>
            <div class="ev-meta"><i class="fas fa-map-marker-alt"></i><?= $escLoc ?></div>
            <div class="ev-meta"><i class="fas fa-clock"></i>
                <?= $ev['event_start_date'] ? date('M d, Y', $startTs) : 'TBA' ?>
                <?php if ($ev['event_start_time']): ?> · <?= date('h:i A', strtotime($ev['event_start_time'])) ?>
                    <?php if ($ev['event_end_time']): ?> – <?= date('h:i A', strtotime($ev['event_end_time'])) ?><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!$pastMode && $dateStr): ?>
                <div class="mb-2" data-countdown="<?= $dateStr ?>">
                    <span class="countdown-box"><span class="num cd-days">--</span>d</span>
                    <span class="countdown-box"><span class="num cd-hours">--</span>h</span>
                    <span class="countdown-box"><span class="num cd-mins">--</span>m</span>
                    <span class="countdown-box"><span class="num cd-secs">--</span>s</span>
                </div>
            <?php endif; ?>
            <?php if ($ev['description']): ?>
                <p class="ev-desc"><?= $escDesc ?></p>
            <?php endif; ?>
            <div class="ev-footer">
                <div class="ev-price">
                    <?php if ($price > 0): ?>
                        <span class="ev-price-amt">₱<?= number_format($price, 2) ?></span><span class="ev-price-per">/person</span>
                    <?php else: ?>
                        <span class="ev-price-free"><i class="fas fa-tag"></i>Free Entry</span>
                    <?php endif; ?>
                </div>
                <div class="ev-actions">
                    <button type="button" class="icon-btn" title="Add to Calendar"
                            onclick="addToCalendar(this)"
                            data-title="<?= $escTitle ?>"
                            data-location="<?= $escLoc ?>"
                            data-start="<?= $startIcs ?>"
                            data-end="<?= $endIcs ?>">
                        <i class="fas fa-calendar-plus"></i>
                    </button>
                    <button type="button" class="icon-btn" title="Share event" onclick="shareEvent('<?= $detailUrl ?>')">
                        <i class="fas fa-share-nodes"></i>
                    </button>
                    <?php if (!empty($ev['registration_link'])): ?>
                        <a href="<?= sanitize($ev['registration_link']) ?>" target="_blank" rel="noopener" class="btn-brand ev-cta"><i class="fas fa-external-link-alt me-1"></i>Register</a>
                    <?php elseif ($pastMode): ?>
                        <a href="<?= $detailUrl ?>" class="btn-brand ev-cta"><i class="fas fa-eye me-1"></i>View Details</a>
                    <?php elseif ($price > 0 || $ev['attendee_count'] > 0): ?>
                        <a href="browse.php?event_id=<?= $ev['id'] ?>" class="btn-brand ev-cta"><i class="fas fa-ticket me-1"></i>Book Tickets</a>
                    <?php else: ?>
                        <a href="<?= $detailUrl ?>" class="btn-brand ev-cta"><i class="fas fa-eye me-1"></i>View Details</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
}
$GLOBALS['catLabelsGlobal'] = $catLabels;

render_page('tourist', 'events.php', 'Upcoming Events', function () use ($events, $featured, $catLabels, $search, $catFilter, $when, $view, $pastMode, $page, $totalPages, $total, $bookmarkedIds, $buildUrl, $monthOptions, $placeholderImg, $calEventsByDay, $calBlankStart, $calDaysInMonth, $calLabel, $calMonth, $calPrevMonth, $calNextMonth) {
    $activeWhen = ($when === 'this_weekend' || $when === 'this_month' || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $when)) ? $when : '';
?>

<style>
.events-hero{background:linear-gradient(135deg,rgba(12,110,94,.92) 0%,rgba(6,95,70,.95) 50%,rgba(4,78,60,1) 100%);color:#fff;border-radius:20px;padding:36px 40px;margin-bottom:1.5rem;position:relative;overflow:hidden}.events-hero::before{content:'';position:absolute;top:-60%;right:-10%;width:450px;height:450px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite}.events-hero::after{content:'';position:absolute;bottom:-40%;left:-5%;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.05) 0%,transparent 70%);animation:hFloat 10s ease-in-out infinite reverse}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.events-hero h2{font-weight:800;margin-bottom:6px;position:relative;z-index:1}.events-hero p{opacity:.85;font-size:.95rem;position:relative;z-index:1;margin-bottom:0}
.search-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px 24px;margin-bottom:1.25rem;box-shadow:0 2px 12px rgba(0,0,0,.04)}.search-card .form-control,.search-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.search-card .form-control:focus,.search-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}
.filter-label{font-size:.72rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.4px;margin:0 0 6px 2px}
.quick-tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:14px;border-top:1px dashed var(--border-color,#e2e8f0)}
.quick-tag{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:.78rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);transition:all .2s;text-decoration:none}.quick-tag:hover{border-color:#0c6e5e;color:#0c6e5e;transform:translateY(-1px)}.quick-tag.active{background:#0c6e5e;border-color:#0c6e5e;color:#fff;box-shadow:0 4px 12px rgba(12,110,94,.28)}
.view-toggle{display:flex;gap:4px;background:var(--border-color,#eef2f7);padding:4px;border-radius:10px}.view-toggle .vt-btn{width:34px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:none;background:transparent;color:var(--text-muted,#64748b);border-radius:8px;font-size:.85rem;text-decoration:none;transition:all .2s}.view-toggle .vt-btn:hover{color:#0c6e5e}.view-toggle .vt-btn.active{background:#fff;color:#0c6e5e;box-shadow:0 2px 6px rgba(0,0,0,.08)}
.results-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:1rem;padding-left:4px}
.results-count{font-size:.82rem;color:var(--text-muted,#64748b);font-weight:500}
.featured-label{font-size:.82rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px;margin-bottom:1rem;display:flex;align-items:center;gap:8px}.featured-label::after{content:'';flex:1;height:1px;background:var(--border-color,#e2e8f0)}
.ev-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);transition:all .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column}.ev-card:hover{transform:translateY(-6px);box-shadow:0 14px 36px rgba(0,0,0,.12)}
.ev-thumb{position:relative;aspect-ratio:16/9;overflow:hidden;background:linear-gradient(135deg,#0c6e5e,#10b981)}.ev-thumb img{width:100%;height:100%;object-fit:cover;transition:transform .5s cubic-bezier(.4,0,.2,1)}.ev-card:hover .ev-thumb img{transform:scale(1.06)}
.date-badge{position:absolute;top:12px;left:12px;z-index:2;background:rgba(255,255,255,.94);backdrop-filter:blur(8px);border-radius:12px;padding:6px 10px;text-align:center;line-height:1.1;box-shadow:0 4px 14px rgba(0,0,0,.18);min-width:52px}.db-month{display:block;font-size:.62rem;font-weight:800;letter-spacing:.12em;color:#0c6e5e}.db-day{display:block;font-size:1.3rem;font-weight:800;color:#0f172a}
.ev-corner-badge{position:absolute;top:12px;right:12px;z-index:2;width:34px;height:34px;border-radius:10px;background:rgba(239,68,68,.92);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;box-shadow:0 4px 14px rgba(239,68,68,.35)}
.ev-save-form{position:absolute;top:12px;right:12px;z-index:2;margin:0}.ev-save{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.15);backdrop-filter:blur(8px);color:#fff;transition:all .2s;padding:0}.ev-save:hover{background:rgba(255,255,255,.3);transform:scale(1.1)}.ev-save.active{background:rgba(239,68,68,.9);border-color:rgba(239,68,68,.9)}
.ev-body{padding:18px;display:flex;flex-direction:column;flex:1}
.ev-title{font-weight:700;font-size:1rem;margin-bottom:6px;color:var(--text-primary,#1e293b);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ev-meta{font-size:.8rem;color:var(--text-muted,#64748b);margin-bottom:5px}.ev-meta i{width:16px;color:#0c6e5e;margin-right:4px}
.ev-desc{font-size:.84rem;color:var(--text-muted,#64748b);margin-bottom:10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ev-footer{margin-top:auto;padding-top:12px;border-top:1px solid var(--border-color,#eef2f7);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ev-price{display:flex;align-items:baseline;gap:4px}.ev-price-amt{font-size:1.05rem;font-weight:800;color:#0c6e5e}.ev-price-per{font-size:.72rem;color:var(--text-muted,#94a3b8)}.ev-price-free{font-size:.85rem;font-weight:800;color:#059669;background:rgba(16,185,129,.1);padding:3px 10px;border-radius:20px}
.ev-actions{display:flex;align-items:center;gap:6px}
.icon-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);font-size:.8rem;transition:all .2s;padding:0}.icon-btn:hover{color:#0c6e5e;border-color:#0c6e5e;transform:translateY(-1px)}
.ev-cta{font-size:.78rem;padding:7px 16px;border-radius:10px;white-space:nowrap}
.cat-badge{font-size:.68rem;padding:3px 10px;border-radius:20px;color:#fff;font-weight:600;letter-spacing:.3px;display:inline-flex;align-items:center;gap:4px}
.past-chip{font-size:.68rem;padding:3px 10px;border-radius:20px;background:rgba(148,163,184,.15);color:var(--text-muted,#64748b);font-weight:700;display:inline-flex;align-items:center;gap:4px}
.countdown-box{display:inline-flex;align-items:center;gap:3px;background:var(--border-color,#f1f5f9);padding:4px 10px;border-radius:8px;font-size:.72rem;font-weight:700;color:var(--text-primary,#1e293b)}.countdown-box .num{font-size:.95rem;line-height:1;color:#0c6e5e}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border:none;border-radius:10px;font-weight:600;padding:8px 24px;transition:all .3s;text-decoration:none}.btn-brand:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(12,110,94,.3);color:#fff}
/* List view */
.ev-card-list{flex-direction:row}.ev-card-list .ev-thumb{aspect-ratio:auto;width:240px;min-width:240px}.ev-card-list .ev-body{padding:18px 20px}
@media (max-width:575.98px){.ev-card-list{flex-direction:column}.ev-card-list .ev-thumb{width:100%;min-width:100%;aspect-ratio:16/9}}
/* Calendar view */
.cal-wrap{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.cal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border-color,#eef2f7)}
.cal-head h5{margin:0;font-weight:800;color:var(--text-primary,#1e293b)}
.cal-nav-btn{width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);text-decoration:none;transition:all .2s}.cal-nav-btn:hover{color:#0c6e5e;border-color:#0c6e5e}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr)}
.cal-dow{padding:10px 6px;text-align:center;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#64748b);border-bottom:1px solid var(--border-color,#eef2f7);background:var(--border-color,#f8fafc)}
.cal-cell{min-height:110px;border-right:1px solid var(--border-color,#f1f5f9);border-bottom:1px solid var(--border-color,#f1f5f9);padding:8px;background:var(--card-bg,#fff)}.cal-cell:nth-child(7n){border-right:none}.cal-cell.blank{background:var(--border-color,#f8fafc)}
.cal-day-num{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}.cal-day-num.today{color:#0c6e5e}.cal-day-num .today-pill{font-size:.6rem;font-weight:800;background:#0c6e5e;color:#fff;padding:2px 7px;border-radius:10px}
.cal-evt{display:block;font-size:.68rem;font-weight:600;color:#fff;background:var(--cal-color,#0c6e5e);border-radius:6px;padding:3px 7px;margin-bottom:4px;text-decoration:none;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;transition:transform .15s}.cal-evt:hover{transform:translateY(-1px);filter:brightness(1.08)}
.cal-more{font-size:.66rem;font-weight:700;color:var(--text-muted,#64748b);padding-left:7px}
.cal-hint{display:flex;gap:16px;flex-wrap:wrap;align-items:center;padding:14px 20px;border-top:1px solid var(--border-color,#eef2f7);font-size:.76rem;color:var(--text-muted,#64748b)}
.cal-hint .hint-item{display:inline-flex;align-items:center;gap:6px}
.cal-hint .swatch{width:10px;height:10px;border-radius:3px;background:var(--cal-color,#0c6e5e)}
/* Empty state */
.empty-wrap{text-align:center;padding:48px 20px;color:var(--text-muted,#94a3b8);border:1px dashed var(--border-color,#e2e8f0);border-radius:20px;background:var(--card-bg,#fff)}.empty-wrap svg{max-width:260px;width:100%;height:auto;margin-bottom:8px}.empty-wrap h5{color:var(--text-primary,#1e293b);font-weight:700;margin-bottom:6px}.empty-wrap p{max-width:420px;margin:0 auto 18px}
.empty-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.btn-soft{border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);border-radius:10px;font-weight:600;padding:8px 20px;transition:all .2s;text-decoration:none;font-size:.85rem}.btn-soft:hover{border-color:#0c6e5e;color:#0c6e5e;transform:translateY(-1px)}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}
/* Dark theme */
[data-theme="dark"] .search-card,[data-theme="dark"] .ev-card,[data-theme="dark"] .cal-wrap,[data-theme="dark"] .empty-wrap{background:#1e293b;border-color:#334155}
[data-theme="dark"] .search-card .form-control,[data-theme="dark"] .search-card .form-select{background:#0f172a;border-color:#334155;color:#f1f5f9}
[data-theme="dark"] .quick-tag{background:#1e293b;border-color:#334155;color:#e2e8f0}.quick-tag.active{background:#0c6e5e;border-color:#0c6e5e;color:#fff}
[data-theme="dark"] .view-toggle{background:#0f172a}.view-toggle .vt-btn.active{background:#1e293b;color:#34d399;box-shadow:none}
[data-theme="dark"] .ev-title{color:#f1f5f9}[data-theme="dark"] .ev-meta,[data-theme="dark"] .ev-desc{color:#94a3b8}
[data-theme="dark"] .icon-btn{background:#1e293b;border-color:#334155;color:#94a3b8}
[data-theme="dark"] .countdown-box{background:#0f172a;color:#e2e8f0}
[data-theme="dark"] .btn-soft{background:#1e293b;border-color:#334155;color:#e2e8f0}
[data-theme="dark"] .ev-footer,[data-theme="dark"] .cal-head,[data-theme="dark"] .cal-hint,[data-theme="dark"] .cal-dow{border-color:#334155}
[data-theme="dark"] .cal-dow{background:#0f172a;color:#94a3b8}
[data-theme="dark"] .cal-cell{background:#1e293b;border-color:#334155}.cal-cell.blank{background:#0f172a}
[data-theme="dark"] .cal-day-num{color:#94a3b8}
[data-theme="dark"] .date-badge{background:rgba(15,23,42,.92)}[data-theme="dark"] .db-day{color:#f1f5f9}
</style>

<!-- Hero -->
<div class="events-hero">
    <h2><i class="fas fa-calendar-star me-2"></i><?= $pastMode ? 'Past Highlights' : 'Upcoming Events' ?></h2>
    <p><?php
        $plural = $total !== 1;
        $noun = $plural ? 'festivals' : 'festival';
        $noun2 = $plural ? 'events' : 'event';
        $act = $plural ? 'activities' : 'activity';
        if ($pastMode) {
            echo "Relive {$total} past {$noun}, cultural {$noun2}, and {$act} in Binalbagan.";
            ?><a href="?view=<?= $view ?>" class="ms-2 btn-brand" style="font-size:.75rem;padding:4px 14px;background:rgba(255,255,255,.15)"><i class="fas fa-arrow-left me-1"></i>Back to Upcoming</a><?php
        } else {
            echo "Discover {$total} upcoming {$noun}, cultural {$noun2}, and {$act} in Binalbagan.";
        }
    ?></p>
</div>

<!-- Featured Events -->
<?php if (!empty($featured)): ?>
<div class="featured-label"><i class="fas fa-fire" style="color:#ef4444;"></i>Featured Events</div>
<div class="row g-4 mb-4">
    <?php foreach ($featured as $f): ?>
    <div class="col-md-6 col-lg-4">
        <?php event_card($f, false, true, false, $placeholderImg); ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Search & Filter -->
<div class="search-card">
    <form method="GET" class="row g-3 align-items-end" id="eventsFilterForm">
        <div class="col-md-5">
            <label class="filter-label">Search events</label>
            <div class="input-group">
                <span class="input-group-text" style="background:var(--card-bg,#fff);border-color:var(--border-color,#e2e8f0);border-radius:10px 0 0 10px;"><i class="fas fa-search" style="color:var(--text-muted,#94a3b8);"></i></span>
                <input type="text" name="search" class="form-control" id="eventsSearch" placeholder="Search events by name, location..." value="<?= sanitize($search) ?>" style="border-radius:0 10px 10px 0;">
            </div>
        </div>
        <div class="col-md-3">
            <label class="filter-label">Category</label>
            <select name="category" class="form-select" id="categorySelect">
                <option value="">All Categories</option>
                <?php foreach ($catLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $catFilter === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="filter-label">When</label>
            <select name="when" class="form-select" id="whenSelect">
                <option value="">All Dates</option>
                <option value="this_weekend" <?= $when === 'this_weekend' ? 'selected' : '' ?>>This Weekend</option>
                <option value="this_month" <?= $when === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <?php foreach ($monthOptions as $ym => $label): ?>
                    <option value="<?= $ym ?>" <?= $when === $ym ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 d-flex gap-2">
            <button type="submit" class="btn-brand w-100" title="Search"><i class="fas fa-filter"></i></button>
        </div>
        <div class="col-12">
            <div class="quick-tags">
                <a href="<?= $buildUrl(['category' => null]) ?>" class="quick-tag <?= !$catFilter ? 'active' : '' ?>"><i class="fas fa-border-all"></i>All Events</a>
                <?php foreach ($catLabels as $k => $v): ?>
                    <a href="<?= $buildUrl(['category' => $k]) ?>" class="quick-tag <?= $catFilter === $k ? 'active' : '' ?>"><i class="fas <?= $v['icon'] ?>"></i><?= $v['label'] ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <input type="hidden" name="view" value="<?= $view ?>">
    </form>
</div>

<!-- Results bar -->
<div class="results-bar">
    <span class="results-count">
        <?php if ($total > 0): ?>
            <i class="fas fa-calendar-check me-1" style="color:#0c6e5e;"></i><?= $total ?> event<?= $total !== 1 ? 's' : '' ?> found
            <?php if ($search || $catFilter || $activeWhen): ?>
                <a href="<?= $buildUrl(['search' => null, 'category' => null, 'when' => null, 'past' => null]) ?>" class="ms-2" style="font-size:.75rem;color:#0c6e5e;font-weight:700;text-decoration:none;"><i class="fas fa-rotate-left me-1"></i>Clear filters</a>
            <?php endif; ?>
        <?php else: ?>
            <i class="fas fa-magnifying-glass me-1" style="color:#0c6e5e;"></i>No matches for the current filters
        <?php endif; ?>
    </span>
    <div class="view-toggle">
        <a href="<?= $buildUrl(['view' => 'grid']) ?>" class="vt-btn <?= $view === 'grid' ? 'active' : '' ?>" title="Grid view"><i class="fas fa-table-cells-large"></i></a>
        <a href="<?= $buildUrl(['view' => 'list']) ?>" class="vt-btn <?= $view === 'list' ? 'active' : '' ?>" title="List view"><i class="fas fa-list"></i></a>
        <a href="<?= $buildUrl(['view' => 'calendar']) ?>" class="vt-btn <?= $view === 'calendar' ? 'active' : '' ?>" title="Calendar view"><i class="fas fa-calendar-days"></i></a>
    </div>
</div>

<?php if ($view === 'calendar'): ?>
    <!-- Calendar -->
    <div class="cal-wrap">
        <div class="cal-head">
            <a href="<?= $buildUrl(['when' => $calPrevMonth]) ?>" class="cal-nav-btn" title="Previous month"><i class="fas fa-chevron-left"></i></a>
            <h5><?= $calLabel ?></h5>
            <a href="<?= $buildUrl(['when' => $calNextMonth]) ?>" class="cal-nav-btn" title="Next month"><i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="cal-grid">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?>
            <?php for ($i = 0; $i < $calBlankStart; $i++): ?><div class="cal-cell blank"></div><?php endfor; ?>
            <?php for ($d = 1; $d <= $calDaysInMonth; $d++): ?>
                <?php $isToday = date('Y-m-d') === $calMonth . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT); ?>
                <div class="cal-cell">
                    <div class="cal-day-num <?= $isToday ? 'today' : '' ?>">
                        <?= $d ?><?php if ($isToday): ?><span class="today-pill">TODAY</span><?php endif; ?>
                    </div>
                    <?php $dayEvents = $calEventsByDay[$d] ?? []; ?>
                    <?php foreach (array_slice($dayEvents, 0, 3) as $cev): ?>
                        <?php $cInfo = $catLabels[$cev['category']] ?? $catLabels['other']; ?>
                        <a href="<?= BASE_URL ?>/tourist/event_detail.php?id=<?= $cev['id'] ?>" class="cal-evt" title="<?= sanitize($cev['title']) ?>" style="--cal-color:<?= $cInfo['color'] ?>;">
                            <?php if ($cev['event_start_time']): ?><?= date('h:i A', strtotime($cev['event_start_time'])) ?> — <?php endif; ?><?= sanitize(mb_strimwidth($cev['title'], 0, 26, '…')) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($dayEvents) > 3): ?><span class="cal-more">+<?= count($dayEvents) - 3 ?> more</span><?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
        <div class="cal-hint">
            <span class="hint-item"><span class="swatch" style="--cal-color:#ec4899"></span>Festival</span>
            <span class="hint-item"><span class="swatch" style="--cal-color:#f97316"></span>Cultural</span>
            <span class="hint-item"><span class="swatch" style="--cal-color:#3b82f6"></span>Tourism</span>
            <span class="hint-item"><span class="swatch" style="--cal-color:#8b5cf6"></span>Workshop</span>
            <span class="hint-item"><span class="swatch" style="--cal-color:#10b981"></span>Community</span>
            <span class="hint-item"><span class="swatch" style="--cal-color:#ef4444"></span>Sports</span>
            <span class="hint-item"><span class="swatch" style="--cal-color:#06b6d4"></span>Arts</span>
            <span class="hint-item" style="margin-left:auto;"><i class="fas fa-circle me-1" style="color:#0c6e5e;font-size:8px;"></i>Click an event to view details</span>
        </div>
    </div>

    <?php if (empty($calEventsByDay)): ?>
    <div class="empty-wrap mt-4">
        <?= empty_svg() ?>
        <h5>No events in <?= $calLabel ?></h5>
        <p>Nothing scheduled for this month yet. Try the next month or check upcoming events below.</p>
        <div class="empty-actions">
            <a href="<?= $buildUrl(['when' => null, 'view' => 'calendar']) ?>" class="btn-brand"><i class="fas fa-rotate-left me-1"></i>Reset Filters</a>
            <a href="?past=1&view=grid" class="btn-soft"><i class="fas fa-clock-rotate-left me-1"></i>View Past Highlights</a>
        </div>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- Grid / List results -->
    <?php if (empty($events)): ?>
        <div class="empty-wrap">
            <?= empty_svg() ?>
            <h5><?= $pastMode ? 'No past highlights yet' : 'No upcoming events found' ?></h5>
            <p><?= $pastMode
                ? 'Past events will show up here once they are completed. Check back soon.'
                : 'Check back later for new events or try a different search.' ?></p>
            <div class="empty-actions">
                <a href="<?= $buildUrl(['search' => null, 'category' => null, 'when' => null, 'past' => null, 'view' => null]) ?>" class="btn-brand"><i class="fas fa-rotate-left me-1"></i>Reset Filters</a>
                <a href="?past=1&view=grid" class="btn-soft"><i class="fas fa-clock-rotate-left me-1"></i>View Past Highlights</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4 mb-4 <?= $view === 'list' ? 'g-3' : '' ?>">
            <?php foreach ($events as $ev):
                $isBookmarked = in_array($ev['id'], $bookmarkedIds);
                if ($view === 'list'): ?>
                    <div class="col-12">
                        <?php event_card($ev, $isBookmarked, false, $pastMode, $placeholderImg, 'list'); ?>
                    </div>
                <?php else: ?>
                    <div class="col-md-6 col-lg-4">
                        <?php event_card($ev, $isBookmarked, false, $pastMode, $placeholderImg); ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav><ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $buildUrl(['page' => $page - 1]) ?>"><i class="fas fa-chevron-left"></i></a></li>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $buildUrl(['page' => $i]) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $buildUrl(['page' => $page + 1]) ?>"><i class="fas fa-chevron-right"></i></a></li>
        </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<script>
const PLACEHOLDER = "<?= $placeholderImg ?>";
function setFallbackImg(el) { el.onerror = null; el.src = PLACEHOLDER; }

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

function shareEvent(url) {
    var link = window.location.origin + url;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function () { showToast('Event link copied to clipboard.', 'success'); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = link; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); showToast('Event link copied to clipboard.', 'success'); } catch (e) { window.open(url, '_blank'); }
        document.body.removeChild(ta);
    }
}

function addToCalendar(btn) {
    var fmt = function (s) { var n = s.replace(/[^\d]/g, ''); return n.slice(0, 8) + 'T' + n.slice(8, 14); };
    var title = btn.dataset.title, loc = btn.dataset.location, start = btn.dataset.start, end = btn.dataset.end;
    var lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//BINALGO//Events//EN', 'BEGIN:VEVENT',
        'UID:' + Date.db_now() + '@binalgo.local',
        'DTSTART:' + fmt(start), 'DTEND:' + fmt(end),
        'SUMMARY:' + title, 'LOCATION:' + loc, 'END:VEVENT', 'END:VCALENDAR'];
    var blob = new Blob([lines.join('\r\n')], { type: 'text/calendar' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = (title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'event') + '.ics';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
    showToast('Event added to your calendar (.ics downloaded).', 'success');
}

(function () {
    var form = document.getElementById('eventsFilterForm');
    if (!form) return;
    var input = document.getElementById('eventsSearch');
    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
    document.getElementById('categorySelect').addEventListener('change', function () { form.submit(); });
    document.getElementById('whenSelect').addEventListener('change', function () { form.submit(); });
})();

document.querySelectorAll('[data-countdown]').forEach(function (el) {
    var raw = el.dataset.countdown.replace(' ', 'T'); if (raw.length <= 10) raw += 'T00:00:00'; var target = new Date(raw).getTime();
    function update() {
        var now = Date.db_now(), diff = target - now;
        if (diff <= 0) { el.innerHTML = '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:8px;font-size:.72rem;font-weight:700;background:#d1fae5;color:#059669;"><i class="fas fa-circle" style="font-size:6px;"></i>Happening Now</span>'; return; }
        var d = Math.floor(diff / 86400000), h = Math.floor((diff % 86400000) / 3600000), m = Math.floor((diff % 3600000) / 60000), s = Math.floor((diff % 60000) / 1000);
        var days = el.querySelector('.cd-days'), hours = el.querySelector('.cd-hours'), mins = el.querySelector('.cd-mins'), secs = el.querySelector('.cd-secs');
        if (days) days.textContent = d;
        if (hours) hours.textContent = h;
        if (mins) mins.textContent = m;
        if (secs) secs.textContent = s;
    }
    update(); setInterval(update, 1000);
});
</script>

<?php }); ?>
