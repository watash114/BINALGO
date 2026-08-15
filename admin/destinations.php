<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');

$db = Database::getInstance()->getConnection();
$destModel = new Destination();

$search = $_GET['search'] ?? '';
$catFilter = $_GET['category'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$csrf = $_SESSION['csrf_token'] ?? generate_token();

$allCategories = $destModel->getCategories();
$allGuides = [];

function dest_stats(PDO $db): array
{
    return [
        'total'        => (int)$db->query("SELECT COUNT(*) FROM destinations")->fetchColumn(),
        'active'       => (int)$db->query("SELECT COUNT(*) FROM destinations WHERE status='active'")->fetchColumn(),
        'featured'     => (int)$db->query("SELECT COUNT(*) FROM destinations WHERE featured=1")->fetchColumn(),
        'booking_open' => (int)$db->query("SELECT COUNT(*) FROM destinations WHERE booking_enabled=1")->fetchColumn(),
    ];
}

function dest_edit_form_html(array $d, array $categories, $csrf): string
{
    $d = $d + ['name' => '', 'description' => '', 'location' => '', 'category' => 'other', 'difficulty' => 'easy',
        'capacity_limit' => 0, 'max_guests_per_booking' => 10, 'available_booking_days' => 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
        'recommended_age_min' => 1, 'recommended_age_max' => 100, 'accessibility_info' => '', 'rules_regulations' => '',
        'facilities' => '', 'entrance_fee' => 0, 'package_price' => '', 'booking_price' => 0, 'image' => '', 'gallery_images' => '',
        'video_url' => '', 'booking_enabled' => 0, 'guide_required' => 0, 'booking_cutoff_hours' => 2, 'advance_booking_days' => 1,
        'cancellation_policy' => '', 'featured' => 0, 'contact_phone' => '', 'contact_email' => '', 'latitude' => '', 'longitude' => '', 'operating_hours_open' => '', 'operating_hours_close' => ''];
    $catOpts = '';
    foreach ($categories as $ck => $cv) {
        $sel = $d['category'] === $ck ? ' selected' : '';
        $catOpts .= "<option value=\"$ck\"$sel>" . htmlspecialchars($cv) . '</option>';
    }
    $days = explode(',', $d['available_booking_days']);
    $dayCb = '';
    foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dk) {
        $chk = in_array($dk, $days) ? ' checked' : '';
        $dayCb .= '<div class="form-check"><input type="checkbox" name="booking_days[]" value="' . $dk . '" class="form-check-input" id="ed_' . $d['id'] . '_' . $dk . '"' . $chk . '><label class="form-check-label" for="ed_' . $d['id'] . '_' . $dk . '">' . $dk . '</label></div>';
    }
    $diffOpts = '';
    foreach (['easy' => 'Easy', 'moderate' => 'Moderate', 'difficult' => 'Difficult', 'extreme' => 'Extreme'] as $vk => $vl) {
        $diffOpts .= '<option value="' . $vk . '"' . ($d['difficulty'] === $vk ? ' selected' : '') . '>' . $vl . '</option>';
    }
    $galleryHtml = '';
    $gallery = $d['gallery_images'] ? json_decode($d['gallery_images'], true) : [];
    if (!empty($gallery)) {
        $galleryHtml = '<div class="d-flex flex-wrap gap-1 mb-2">' . implode('', array_map(fn($gi) => '<img src="' . dest_image_url($gi) . '" class="rounded" style="max-height:50px;width:auto;" alt="">', $gallery)) . '</div>';
    }
    $imgHtml = !empty($d['image']) ? '<div class="mb-2"><img src="' . dest_image_url($d['image']) . '" class="rounded" style="max-height:80px;object-fit:cover;" alt=""></div>' : '';
    $videoHtml = (!empty($d['video_url']) && !str_starts_with($d['video_url'], 'http')) ? '<div class="mb-2"><video controls style="max-height:120px;border-radius:8px;" preload="metadata"><source src="' . dest_image_url($d['video_url']) . '"></video></div>' : '';

    return '
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">
    <input type="hidden" name="action" value="edit_destination">
    <input type="hidden" name="dest_id" value="' . (int)$d['id'] . '">
    <input type="hidden" name="existing_image" value="' . htmlspecialchars($d['image']) . '">
    <ul class="nav nav-tabs mb-3" id="editDestTabs">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#edBasic">Basic Info</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#edLocation">Location &amp; Hours</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#edPricing">Pricing</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#edVisitor">Visitor Info</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#edBooking">Booking Settings</a></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="edBasic">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="' . htmlspecialchars($d['name']) . '" required style="border-radius:10px;"></div>
                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Category</label><select name="category" class="form-select" style="border-radius:10px;">' . $catOpts . '</select></div>
                <div class="col-12"><label class="form-label fw-semibold" style="font-size:.82rem;">Description</label><textarea name="description" class="form-control" rows="4" style="border-radius:10px;">' . htmlspecialchars($d['description']) . '</textarea></div>
                <div class="col-12">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Video <small class="text-muted fw-normal">URL or upload a file</small></label>
                    <input type="url" name="video_url" class="form-control mb-2" value="' . htmlspecialchars($d['video_url']) . '" placeholder="https://www.youtube.com/watch?v=..." style="border-radius:10px;">' . $videoHtml . '
                    <input type="file" name="video_file" class="form-control" accept="video/mp4,video/webm,video/quicktime" style="border-radius:10px;font-size:.85rem;">
                    <small class="text-muted" style="font-size:.72rem;">MP4, WebM, or MOV (max 50MB). File overrides URL if both provided.</small>
                </div>
                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Cover Image</label>' . $imgHtml . '<input type="file" name="image" class="form-control" accept="image/*" style="border-radius:10px;"></div>
                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Gallery Images (multiple)</label>' . $galleryHtml . '<input type="file" name="gallery[]" class="form-control" accept="image/*" multiple style="border-radius:10px;"></div>
                <div class="col-12"><div class="form-check"><input type="checkbox" name="featured" class="form-check-input" id="edFeat"' . ($d['featured'] ? ' checked' : '') . '><label class="form-check-label" for="edFeat">Mark as Featured Destination</label></div></div>
            </div>
        </div>
        <div class="tab-pane fade" id="edLocation">
            <div class="row g-3">
                <div class="col-12"><label class="form-label fw-semibold" style="font-size:.82rem;">Address <span class="text-danger">*</span></label><input type="text" name="location" class="form-control" value="' . htmlspecialchars($d['location']) . '" required style="border-radius:10px;"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Latitude</label><input type="text" name="latitude" class="form-control" value="' . htmlspecialchars($d['latitude']) . '" placeholder="e.g., 10.1234567" style="border-radius:10px;"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Longitude</label><input type="text" name="longitude" class="form-control" value="' . htmlspecialchars($d['longitude']) . '" placeholder="e.g., 122.1234567" style="border-radius:10px;"></div>
                <div class="col-md-4"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Contact Phone</label><input type="text" name="contact_phone" class="form-control" value="' . htmlspecialchars($d['contact_phone']) . '" style="border-radius:10px;"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Contact Email</label><input type="email" name="contact_email" class="form-control" value="' . htmlspecialchars($d['contact_email']) . '" style="border-radius:10px;"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Operating Hours Open</label><input type="time" name="operating_hours_open" class="form-control" value="' . htmlspecialchars($d['operating_hours_open']) . '" style="border-radius:10px;"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Operating Hours Close</label><input type="time" name="operating_hours_close" class="form-control" value="' . htmlspecialchars($d['operating_hours_close']) . '" style="border-radius:10px;"></div>
            </div>
        </div>
        <div class="tab-pane fade" id="edPricing">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Entrance Fee (₱)</label><input type="number" name="entrance_fee" class="form-control" value="' . (float)$d['entrance_fee'] . '" min="0" step="0.01" style="border-radius:10px;"></div>
                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Package Price (₱) <small class="text-muted">Optional</small></label><input type="number" name="package_price" class="form-control" value="' . htmlspecialchars($d['package_price']) . '" min="0" step="0.01" style="border-radius:10px;"></div>
            </div>
        </div>
        <div class="tab-pane fade" id="edVisitor">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Max Visitors/Day</label><input type="number" name="capacity" class="form-control" value="' . (int)$d['capacity_limit'] . '" min="0" style="border-radius:10px;"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Max Guests Per Booking</label><input type="number" name="max_guests_per_booking" class="form-control" value="' . (int)$d['max_guests_per_booking'] . '" min="1" style="border-radius:10px;"></div>
                <div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Difficulty</label><select name="difficulty" class="form-select" style="border-radius:10px;">' . $diffOpts . '</select></div>
                <div class="col-md-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Min Age</label><input type="number" name="age_min" class="form-control" value="' . max(1, (int)$d['recommended_age_min']) . '" min="1" style="border-radius:10px;"></div>
                <div class="col-md-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Max Age</label><input type="number" name="age_max" class="form-control" value="' . (int)$d['recommended_age_max'] . '" min="1" style="border-radius:10px;"></div>
                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Available Booking Days</label><div class="d-flex flex-wrap gap-2">' . $dayCb . '</div></div>
                <div class="col-12"><label class="form-label fw-semibold" style="font-size:.82rem;">Accessibility Info</label><textarea name="accessibility" class="form-control" rows="2" style="border-radius:10px;">' . htmlspecialchars($d['accessibility_info']) . '</textarea></div>
                <div class="col-12"><label class="form-label fw-semibold" style="font-size:.82rem;">Rules &amp; Regulations</label><textarea name="rules_regulations" class="form-control" rows="3" style="border-radius:10px;">' . htmlspecialchars($d['rules_regulations']) . '</textarea></div>
                <div class="col-12"><label class="form-label fw-semibold" style="font-size:.82rem;">Facilities</label><textarea name="facilities" class="form-control" rows="2" style="border-radius:10px;">' . htmlspecialchars($d['facilities']) . '</textarea></div>
            </div>
        </div>
        <div class="tab-pane fade" id="edBooking">
            <div class="row g-3">
                <div class="col-12">
                    <div class="p-3 rounded-3 mb-3" style="background:var(--bg-secondary,#f8fafc);border:1px solid var(--border-color,#e2e8f0);">
                        <h6 class="fw-bold mb-3" style="font-size:.88rem;"><i class="fa-solid fa-toggle-on me-2" style="color:#10b981;"></i>Booking Availability</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch mb-2"><input type="checkbox" name="booking_enabled" class="form-check-input" id="edBkEn"' . ($d['booking_enabled'] ? ' checked' : '') . '><label class="form-check-label fw-semibold" for="edBkEn" style="font-size:.85rem;">Enable Online Booking</label></div>
                                <div class="form-check form-switch"><input type="checkbox" name="guide_required" class="form-check-input" id="edGdReq"' . ($d['guide_required'] ? ' checked' : '') . '><label class="form-check-label fw-semibold" for="edGdReq" style="font-size:.85rem;">Require Tour Guide</label></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="font-size:.82rem;">Booking Price per Person (₱)</label>
                                <div class="input-group"><span class="input-group-text" style="border-radius:10px 0 0 10px;background:var(--card-bg,#fff);border-color:var(--border-color,#dee2e6);">₱</span><input type="number" name="booking_price" class="form-control" value="' . (float)$d['booking_price'] . '" min="0" step="0.01" placeholder="0.00" style="border-radius:0 10px 10px 0;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="p-3 rounded-3 mb-3" style="background:var(--bg-secondary,#f8fafc);border:1px solid var(--border-color,#e2e8f0);">
                        <h6 class="fw-bold mb-3" style="font-size:.88rem;"><i class="fa-solid fa-clock me-2" style="color:#3b82f6;"></i>Time Restrictions</h6>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Booking Cut-off</label><div class="input-group"><input type="number" name="booking_cutoff_hours" class="form-control" value="' . (int)$d['booking_cutoff_hours'] . '" min="0" style="border-radius:10px 0 0 10px;"><span class="input-group-text" style="border-radius:0 10px 10px 0;background:var(--card-bg,#fff);border-color:var(--border-color,#dee2e6);">hours before visit</span></div></div>
                            <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Advance Booking Requirement</label><div class="input-group"><input type="number" name="advance_booking_days" class="form-control" value="' . (int)$d['advance_booking_days'] . '" min="0" style="border-radius:10px 0 0 10px;"><span class="input-group-text" style="border-radius:0 10px 10px 0;background:var(--card-bg,#fff);border-color:var(--border-color,#dee2e6);">day(s) in advance</span></div></div>
                        </div>
                    </div>
                </div>
                <div class="col-12"><div class="p-3 rounded-3" style="background:var(--bg-secondary,#f8fafc);border:1px solid var(--border-color,#e2e8f0);"><h6 class="fw-bold mb-3" style="font-size:.88rem;"><i class="fa-solid fa-file-contract me-2" style="color:#f59e0b;"></i>Cancellation Policy</h6><textarea name="cancellation_policy" class="form-control" rows="3" placeholder="e.g., Free cancellation up to 24 hours before the visit. No refund for no-shows." style="border-radius:10px;">' . htmlspecialchars($d['cancellation_policy']) . '</textarea></div></div>
            </div>
        </div>
    </div>
    <div class="modal-footer px-0 pb-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:10px;">Cancel</button>
        <button type="submit" class="btn btn-brand" style="border-radius:10px;font-weight:600;"><i class="fa-solid fa-save me-1"></i>Save Changes</button>
    </div>
</form>';
}

// ── AJAX GET: edit form endpoint ────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && ($_GET['mode'] ?? '') === 'form') {
    header('Content-Type: application/json; charset=utf-8');
    $did = (int)($_GET['id'] ?? 0);
    $d = $did ? $destModel->findById($did) : null;
    if (!$d) {
        echo json_encode(['ok' => false, 'message' => 'Destination not found.']);
        exit;
    }
    echo json_encode(['ok' => true, 'html' => dest_edit_form_html($d, $allCategories, $csrf)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX GET: list ──────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $qPage = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 15);
    if (!in_array($perPage, [10, 15, 25, 50], true)) $perPage = 15;
    $qSearch = trim($_GET['search'] ?? '');
    $qCat = $_GET['category'] ?? '';
    $qStatus = $_GET['status'] ?? '';

    $where = [];
    $params = [];
    if ($qSearch) { $where[] = "(d.name LIKE :s1 OR d.location LIKE :s2)"; $params[':s1'] = "%$qSearch%"; $params[':s2'] = "%$qSearch%"; }
    if ($qCat) { $where[] = "d.category = :cat"; $params[':cat'] = $qCat; }
    if ($qStatus) { $where[] = "d.status = :status"; $params[':status'] = $qStatus; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) as c FROM destinations d $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['c'];
    $pages = max(1, ceil($total / $perPage));
    if ($qPage > $pages) $qPage = $pages;
    $offset = ($qPage - 1) * $perPage;

    $stmt = $db->prepare("SELECT d.* FROM destinations d $whereClause ORDER BY d.featured DESC, d.name ASC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = array_map(function ($d) {
        return [
            'id'             => (int)$d['id'],
            'name'           => $d['name'] ?? '',
            'category'       => $d['category'] ?? 'other',
            'location'       => $d['location'] ?? '',
            'entrance_fee'   => (float)($d['entrance_fee'] ?? 0),
            'booking_enabled'=> (int)($d['booking_enabled'] ?? 0),
            'featured'       => (int)($d['featured'] ?? 0),
            'status'         => $d['status'] ?? 'inactive',
            'image_url'      => dest_image_url($d['image'] ?? ''),
        ];
    }, $stmt->fetchAll());

    echo json_encode([
        'rows'     => $rows,
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $qPage,
        'per_page' => $perPage,
        'stats'    => dest_stats($db),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || (($_POST['ajax'] ?? '') === '1');
    $sendJson = function (array $payload): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };
    $respond = function (bool $ok, string $message) use ($isAjax, $sendJson) {
        if ($isAjax) $sendJson(['ok' => $ok, 'message' => $message]);
        $ok ? flash_message('success', $message) : flash_message('error', $message);
        redirect('/admin/destinations.php?' . http_build_query($_GET));
    };

    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $respond(false, 'Invalid security token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_featured' && isset($_POST['dest_id'])) {
        $did = (int)$_POST['dest_id'];
        $destModel->toggleFeatured($did);
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', "Toggled featured for destination #{$did}");
        $respond(true, 'Featured status updated.');
    }

    if ($action === 'toggle_status' && isset($_POST['dest_id'])) {
        $did = (int)$_POST['dest_id'];
        $destModel->toggleStatus($did);
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', "Toggled status for destination #{$did}");
        $respond(true, 'Destination status updated.');
    }

    if ($action === 'set_status' && isset($_POST['dest_id'], $_POST['new_status'])) {
        $did = (int)$_POST['dest_id'];
        $newStatus = in_array($_POST['new_status'], ['active', 'inactive', 'closed', 'maintenance'], true) ? $_POST['new_status'] : '';
        if (!$newStatus) $respond(false, 'Invalid status.');
        $db->prepare("UPDATE destinations SET status = :s WHERE id = :id")->execute([':s' => $newStatus, ':id' => $did]);
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', "Set destination #{$did} status to {$newStatus}");
        $respond(true, 'Destination status updated to ' . ucfirst($newStatus) . '.');
    }

    if ($action === 'toggle_booking' && isset($_POST['dest_id'])) {
        $did = (int)$_POST['dest_id'];
        $destModel->toggleBooking($did);
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', "Toggled booking for destination #{$did}");
        $respond(true, 'Booking availability updated.');
    }

    if ($action === 'delete_destination' && isset($_POST['dest_id'])) {
        $did = (int)$_POST['dest_id'];
        $destModel->delete($did);
        ActivityLog::log($_SESSION['user_id'], 'destination_delete', 'Deleted destination #' . $did);
        $respond(true, 'Destination deleted.');
    }

    if ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', (array)($_POST['dest_ids'] ?? [])));
        if (empty($ids)) $respond(false, 'No destinations selected.');
        foreach ($ids as $id) $destModel->delete($id);
        ActivityLog::log($_SESSION['user_id'], 'destination_delete', "Bulk deleted " . count($ids) . " destinations");
        $respond(true, count($ids) . ' destination(s) deleted.');
    }

    if ($action === 'bulk_featured' && isset($_POST['value'])) {
        $ids = array_filter(array_map('intval', (array)($_POST['dest_ids'] ?? [])));
        $val = $_POST['value'] === '1' ? 1 : 0;
        if (empty($ids)) $respond(false, 'No destinations selected.');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE destinations SET featured = ? WHERE id IN ($ph)")->execute(array_merge([$val], $ids));
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', "Bulk set featured={$val} for " . count($ids) . " destinations");
        $respond(true, count($ids) . ' destination(s) ' . ($val ? 'featured' : 'unfeatured') . '.');
    }

    if ($action === 'bulk_booking' && isset($_POST['value'])) {
        $ids = array_filter(array_map('intval', (array)($_POST['dest_ids'] ?? [])));
        $val = $_POST['value'] === '1' ? 1 : 0;
        if (empty($ids)) $respond(false, 'No destinations selected.');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE destinations SET booking_enabled = ? WHERE id IN ($ph)")->execute(array_merge([$val], $ids));
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', "Bulk set booking={$val} for " . count($ids) . " destinations");
        $respond(true, 'Booking ' . ($val ? 'opened' : 'closed') . ' for ' . count($ids) . ' destination(s).');
    }

    if ($action === 'add_destination') {
        // Full add form (non-AJAX) — original logic
        $imagePath = '';
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = upload_file($_FILES['image'], 'destinations', ['jpg', 'jpeg', 'png', 'webp']);
            if ($upload['success']) $imagePath = $upload['filename'];
            else { $respond(false, 'Image upload failed: ' . $upload['message']); }
        }
        $videoPath = '';
        if (!empty($_FILES['video_file']['name']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $vExt = strtolower(pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($vExt, ['mp4', 'webm', 'mov'])) $respond(false, 'Video type not allowed. Use MP4, WebM, or MOV.');
            if ($_FILES['video_file']['size'] > 50 * 1024 * 1024) $respond(false, 'Video file exceeds 50MB limit.');
            $vDir = __DIR__ . '/../uploads/destinations';
            if (!is_dir($vDir)) mkdir($vDir, 0755, true);
            $vFilename = uniqid() . '_video_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['video_file']['name']));
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $vDir . '/' . $vFilename)) $videoPath = $vFilename;
            else $respond(false, 'Failed to upload video file.');
        }
        $gallery = [];
        if (!empty($_FILES['gallery']['name'][0])) {
            foreach ($_FILES['gallery']['tmp_name'] as $i => $tmp) {
                if ($_FILES['gallery']['error'][$i] === UPLOAD_ERR_OK) {
                    $up = upload_file(['name' => $_FILES['gallery']['name'][$i], 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], 'destinations', ['jpg', 'jpeg', 'png', 'webp']);
                    if ($up['success']) $gallery[] = $up['filename'];
                }
            }
        }
        $stmt = $db->prepare(
            "INSERT INTO destinations (name, description, location, contact_phone, contact_email, latitude, longitude,
                operating_hours_open, operating_hours_close, category, difficulty, capacity_limit, max_guests_per_booking,
                available_booking_days, recommended_age_min, recommended_age_max, accessibility_info, rules_regulations,
                facilities, entrance_fee, package_price, booking_price, image, gallery_images, video_url, status, booking_enabled, guide_required,
                booking_cutoff_hours, advance_booking_days, cancellation_policy, featured, created_by, created_at)
             VALUES (:name, :desc, :loc, :phone, :email, :lat, :lng, :open_hr, :close_hr, :cat, :diff, :cap, :maxg,
                :days, :age_min, :age_max, :access, :rules, :fac, :fee, :pkg, :bk_price, :img, :gallery, :video_url, 'active', :bk_en, 0,
                :cutoff, :advance, :cancel, 0, :uid, datetime('now'))"
        );
        $stmt->execute([
            ':name' => sanitize($_POST['name'] ?? ''), ':desc' => sanitize($_POST['description'] ?? ''),
            ':loc' => sanitize($_POST['location'] ?? ''), ':phone' => sanitize($_POST['contact_phone'] ?? ''),
            ':email' => sanitize($_POST['contact_email'] ?? ''), ':lat' => $_POST['latitude'] ?? null,
            ':lng' => $_POST['longitude'] ?? null, ':open_hr' => $_POST['operating_hours_open'] ?: null,
            ':close_hr' => $_POST['operating_hours_close'] ?: null, ':cat' => $_POST['category'] ?? 'other',
            ':diff' => $_POST['difficulty'] ?? 'easy', ':cap' => (int)($_POST['capacity'] ?? 0),
            ':maxg' => (int)($_POST['max_guests_per_booking'] ?? 10),
            ':days' => !empty($_POST['booking_days']) ? implode(',', $_POST['booking_days']) : 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            ':age_min' => max(1, (int)($_POST['age_min'] ?? 1)), ':age_max' => (int)($_POST['age_max'] ?? 100),
            ':access' => sanitize($_POST['accessibility'] ?? ''), ':rules' => sanitize($_POST['rules_regulations'] ?? ''),
            ':fac' => sanitize($_POST['facilities'] ?? ''), ':fee' => (float)($_POST['entrance_fee'] ?? 0),
            ':pkg' => $_POST['package_price'] ? (float)$_POST['package_price'] : null,
            ':bk_price' => (float)($_POST['booking_price'] ?? 0), ':img' => $imagePath,
            ':gallery' => !empty($gallery) ? json_encode($gallery) : null,
            ':video_url' => $videoPath ?: sanitize($_POST['video_url'] ?? ''),
            ':bk_en' => isset($_POST['booking_enabled']) ? 1 : 0,
            ':cutoff' => (int)($_POST['booking_cutoff_hours'] ?? 2), ':advance' => (int)($_POST['advance_booking_days'] ?? 1),
            ':cancel' => sanitize($_POST['cancellation_policy'] ?? ''), ':uid' => $_SESSION['user_id'],
        ]);
        ActivityLog::log($_SESSION['user_id'], 'destination_add', 'Added destination: ' . ($_POST['name'] ?? ''));
        $respond(true, 'Destination added successfully.');
    }

    if ($action === 'edit_destination' && isset($_POST['dest_id'])) {
        // Full edit form (non-AJAX) — original logic
        $did = (int)$_POST['dest_id'];
        $existing = $destModel->findById($did);
        if (!$existing) $respond(false, 'Destination not found.');
        $imagePath = $_POST['existing_image'] ?? '';
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = upload_file($_FILES['image'], 'destinations', ['jpg', 'jpeg', 'png', 'webp']);
            if ($upload['success']) $imagePath = $upload['filename'];
            else $respond(false, 'Image upload failed: ' . $upload['message']);
        }
        $gallery = $existing['gallery_images'] ? json_decode($existing['gallery_images'], true) : [];
        if (!empty($_FILES['gallery']['name'][0])) {
            foreach ($_FILES['gallery']['tmp_name'] as $i => $tmp) {
                if ($_FILES['gallery']['error'][$i] === UPLOAD_ERR_OK) {
                    $up = upload_file(['name' => $_FILES['gallery']['name'][$i], 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], 'destinations', ['jpg', 'jpeg', 'png', 'webp']);
                    if ($up['success']) $gallery[] = $up['filename'];
                }
            }
        }
        $videoPath = '';
        if (!empty($_FILES['video_file']['name']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $vExt = strtolower(pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($vExt, ['mp4', 'webm', 'mov'])) $respond(false, 'Video type not allowed. Use MP4, WebM, or MOV.');
            if ($_FILES['video_file']['size'] > 50 * 1024 * 1024) $respond(false, 'Video file exceeds 50MB limit.');
            $vDir = __DIR__ . '/../uploads/destinations';
            if (!is_dir($vDir)) mkdir($vDir, 0755, true);
            $vFilename = uniqid() . '_video_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['video_file']['name']));
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $vDir . '/' . $vFilename)) $videoPath = $vFilename;
            else $respond(false, 'Failed to upload video file.');
        }
        $stmt = $db->prepare(
            "UPDATE destinations SET name=:name, description=:desc, location=:loc, contact_phone=:phone, contact_email=:email,
                latitude=:lat, longitude=:lng, operating_hours_open=:open_hr, operating_hours_close=:close_hr,
                category=:cat, difficulty=:diff, capacity_limit=:cap, max_guests_per_booking=:maxg,
                available_booking_days=:days, recommended_age_min=:age_min, recommended_age_max=:age_max,
                accessibility_info=:access, rules_regulations=:rules, facilities=:fac,
                entrance_fee=:fee, package_price=:pkg, booking_price=:bk_price, image=:img, gallery_images=:gallery,
                video_url=:video_url, booking_enabled=:bk_en, guide_required=0, booking_cutoff_hours=:cutoff,
                advance_booking_days=:advance, cancellation_policy=:cancel, featured=:featured, updated_at=datetime('now')
             WHERE id=:id"
        );
        $stmt->execute([
            ':id' => $did, ':name' => sanitize($_POST['name'] ?? ''), ':desc' => sanitize($_POST['description'] ?? ''),
            ':loc' => sanitize($_POST['location'] ?? ''), ':phone' => sanitize($_POST['contact_phone'] ?? ''),
            ':email' => sanitize($_POST['contact_email'] ?? ''), ':lat' => $_POST['latitude'] ?? null,
            ':lng' => $_POST['longitude'] ?? null, ':open_hr' => $_POST['operating_hours_open'] ?: null,
            ':close_hr' => $_POST['operating_hours_close'] ?: null, ':cat' => $_POST['category'] ?? 'other',
            ':diff' => $_POST['difficulty'] ?? 'easy', ':cap' => (int)($_POST['capacity'] ?? 0),
            ':maxg' => (int)($_POST['max_guests_per_booking'] ?? 10),
            ':days' => !empty($_POST['booking_days']) ? implode(',', $_POST['booking_days']) : 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            ':age_min' => max(1, (int)($_POST['age_min'] ?? 1)), ':age_max' => (int)($_POST['age_max'] ?? 100),
            ':access' => sanitize($_POST['accessibility'] ?? ''), ':rules' => sanitize($_POST['rules_regulations'] ?? ''),
            ':fac' => sanitize($_POST['facilities'] ?? ''), ':fee' => (float)($_POST['entrance_fee'] ?? 0),
            ':pkg' => $_POST['package_price'] ? (float)$_POST['package_price'] : null,
            ':bk_price' => (float)($_POST['booking_price'] ?? 0), ':img' => $imagePath,
            ':gallery' => !empty($gallery) ? json_encode($gallery) : null,
            ':video_url' => $videoPath ?: sanitize($_POST['video_url'] ?? ''),
            ':bk_en' => isset($_POST['booking_enabled']) ? 1 : 0,
            ':cutoff' => (int)($_POST['booking_cutoff_hours'] ?? 2), ':advance' => (int)($_POST['advance_booking_days'] ?? 1),
            ':cancel' => sanitize($_POST['cancellation_policy'] ?? ''),
            ':featured' => isset($_POST['featured']) ? 1 : 0,
        ]);
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', 'Edited destination #' . $did);
        $respond(true, 'Destination updated successfully.');
    }

    // Season + guide management (non-AJAX forms)
    if ($action === 'add_season' && isset($_POST['dest_id'])) {
        $did = (int)$_POST['dest_id'];
        $startMonth = (int)($_POST['start_month'] ?? 1);
        $endMonth = (int)($_POST['end_month'] ?? 12);
        $months = $startMonth === $endMonth ? (string)$startMonth : $startMonth . '-' . $endMonth;
        $db->prepare("INSERT INTO destination_seasons (destination_id, season_type, months, description, created_at) VALUES (:dest_id, :season_type, :months, :description, datetime('now'))")
            ->execute([':dest_id' => $did, ':season_type' => $_POST['season_type'] ?? 'peak', ':months' => $months, ':description' => sanitize($_POST['season_description'] ?? '')]);
        ActivityLog::log($_SESSION['user_id'], 'destination_edit', "Added season for destination #{$did}");
        $respond(true, 'Season added.');
    }

    if ($action === 'delete_season' && isset($_POST['season_id'], $_POST['dest_id'])) {
        $sid = (int)$_POST['season_id'];
        $db->prepare("DELETE FROM destination_seasons WHERE id = :id")->execute([':id' => $sid]);
        $respond(true, 'Season removed.');
    }

    if ($action === 'assign_guide' && isset($_POST['dest_id'], $_POST['guide_id'])) {
        $destModel->assignGuide((int)$_POST['dest_id'], (int)$_POST['guide_id'], isset($_POST['is_primary']));
        $respond(true, 'Guide assigned.');
    }

    if ($action === 'remove_guide' && isset($_POST['dest_id'], $_POST['guide_id'])) {
        $destModel->removeGuide((int)$_POST['dest_id'], (int)$_POST['guide_id']);
        $respond(true, 'Guide removed.');
    }

    if ($action === 'set_primary_guide' && isset($_POST['dest_id'], $_POST['guide_id'])) {
        $destModel->setPrimaryGuide((int)$_POST['dest_id'], (int)$_POST['guide_id']);
        $respond(true, 'Primary guide updated.');
    }

    $respond(false, 'Unknown action.');
}

$stats = dest_stats($db);
$manageSeasons = isset($_GET['manage_seasons']) ? (int)$_GET['manage_seasons'] : null;
$seasonDest = $manageSeasons ? $destModel->findWithSeasons($manageSeasons) : null;
$manageGuides = isset($_GET['manage_guides']) ? (int)$_GET['manage_guides'] : null;
$guideDest = $manageGuides ? $destModel->findById($manageGuides) : null;
$assignedGuides = $manageGuides ? $destModel->getAssignedGuides($manageGuides) : [];

render_page('admin', 'destinations.php', 'Destination Management', function () use ($stats, $search, $catFilter, $statusFilter, $csrf, $allCategories, $allGuides, $manageSeasons, $seasonDest, $manageGuides, $guideDest, $assignedGuides, $db, $destModel) {

$monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
?>
<style>
    .kpi-card { border: 1px solid var(--border-color); border-radius: 14px; background: var(--card-bg); cursor: pointer; transition: transform .15s, box-shadow .15s; }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.06); }
    .kpi-card.active { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(12,110,94,.15); }
    .kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; }
    .sticky-filter { position: sticky; top: 70px; z-index: 30; }
    .search-wrap { position: relative; }
    .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: .85rem; }
    .search-wrap input { padding-left: 34px; }
    .filter-chip { font-size: .75rem; background: rgba(12,110,94,.1); color: var(--brand); border-radius: 20px; padding: 2px 10px; display: inline-flex; align-items: center; gap: 6px; }
    .table thead th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
    .dest-thumb { width: 46px; height: 40px; border-radius: 8px; object-fit: cover; flex-shrink: 0; border: 1px solid var(--border-color); }
    .skeleton { background: linear-gradient(90deg, rgba(130,130,130,.08) 25%, rgba(130,130,130,.18) 37%, rgba(130,130,130,.08) 63%); background-size: 400% 100%; animation: shimmer 1.4s ease infinite; border-radius: 8px; }
    @keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
    .status-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; }
    .star-toggle { background: none; border: none; font-size: 1rem; cursor: pointer; transition: transform .15s; padding: 2px; }
    .star-toggle:hover { transform: scale(1.2); }
    .booking-pill { cursor: pointer; }
    .toast-container { z-index: 9999; }
    .pager { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .bulk-bar { display: none; align-items: center; gap: 10px; border: 1px solid var(--brand); background: rgba(12,110,94,.08); border-radius: 12px; padding: 8px 14px; }
    .bulk-bar.show { display: flex; }
    .modal-xl .modal-content { border: none; border-radius: 16px; }
</style>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
        <h4 class="mb-1 fw-bold"><i class="fa-solid fa-map-location-dot me-2 text-brand"></i>Destination Management</h4>
        <div class="text-muted small">Manage listings, featured destinations and booking availability.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="refreshBtn" title="Refresh"><i class="fa-regular fa-rotate-right"></i></button>
        <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#addDestModal"><i class="fa-solid fa-plus me-1"></i>Add Destination</button>
    </div>
</div>

<div class="row g-3 mb-3" id="kpiRow">
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-status="">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-primary-subtle text-primary"><i class="fa-solid fa-map-marked-alt"></i></div><div><div class="fs-4 fw-bold" id="kpi-total"><?= $stats['total'] ?></div><div class="text-muted small">Destinations</div></div></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-status="active">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-success-subtle text-success"><i class="fa-solid fa-circle-check"></i></div><div><div class="fs-4 fw-bold" id="kpi-active"><?= $stats['active'] ?></div><div class="text-muted small">Active</div></div></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-feat="1">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-warning-subtle text-warning"><i class="fa-solid fa-star"></i></div><div><div class="fs-4 fw-bold" id="kpi-featured"><?= $stats['featured'] ?></div><div class="text-muted small">Featured</div></div></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="kpi-card p-3" data-bk="1">
        <div class="d-flex align-items-center gap-3"><div class="kpi-icon bg-info-subtle text-info"><i class="fa-solid fa-ticket"></i></div><div><div class="fs-4 fw-bold" id="kpi-booking"><?= $stats['booking_open'] ?></div><div class="text-muted small">Booking Open</div></div></div>
    </div></div>
</div>

<div class="sticky-filter mb-3">
    <div class="card shadow-sm">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search name or location..." value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"></div>
                </div>
                <div class="col-md-2"><select id="catFilter" class="form-select form-select-sm"><option value="">All Categories</option></select></div>
                <div class="col-md-2"><select id="statusFilter" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
                    <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                </select></div>
                <div class="col-md-2"><select id="perPage" class="form-select form-select-sm"><option value="10">10 / page</option><option value="15" selected>15 / page</option><option value="25">25 / page</option><option value="50">50 / page</option></select></div>
                <div class="col-md-2 d-flex gap-1 justify-content-end">
                    <button class="btn btn-outline-secondary btn-sm" id="clearFilters">Clear</button>
                    <button class="btn btn-brand btn-sm" id="applyFilters"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                </div>
            </div>
            <div id="chipRow" class="mt-2 d-flex gap-1 flex-wrap"></div>
        </div>
    </div>
</div>

<div class="bulk-bar mb-3" id="bulkBar">
    <i class="fa-solid fa-check-double text-brand"></i>
    <span class="fw-semibold" id="bulkCount">0 selected</span>
    <div class="ms-auto d-flex gap-1 flex-wrap">
        <button class="btn btn-sm btn-outline-warning" onclick="bulk('bulk_featured','1')"><i class="fa-solid fa-star me-1"></i>Feature</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="bulk('bulk_featured','0')"><i class="fa-regular fa-star me-1"></i>Unfeature</button>
        <button class="btn btn-sm btn-outline-success" onclick="bulk('bulk_booking','1')"><i class="fa-solid fa-ticket me-1"></i>Open Booking</button>
        <button class="btn btn-sm btn-outline-danger" onclick="bulk('bulk_booking','0')"><i class="fa-solid fa-ban me-1"></i>Close Booking</button>
        <button class="btn btn-sm btn-outline-danger" onclick="bulkDelete()"><i class="fa-solid fa-trash me-1"></i>Delete</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()"><i class="fa-solid fa-xmark me-1"></i></button>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:38px"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                    <th style="width:55px">ID</th>
                    <th>Destination</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Fee</th>
                    <th>Booking</th>
                    <th>Featured</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="destBody"></tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="text-muted small" id="footerInfo">Loading...</div>
        <div class="pager" id="pager"></div>
    </div>
</div>

<?php if ($manageSeasons && $seasonDest): ?>
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a)">
        <h6 class="mb-0 fw-bold text-white"><i class="fa-solid fa-calendar-days me-2"></i>Manage Seasons — <?= htmlspecialchars($seasonDest['name']) ?></h6>
        <a href="<?= BASE_URL ?>/admin/destinations.php" class="btn btn-sm btn-light"><i class="fa-solid fa-xmark me-1"></i>Close</a>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6">
                <h6 class="fw-bold mb-3" style="font-size:.9rem;">Current Seasons</h6>
                <?php if (empty($seasonDest['seasons'])): ?>
                    <p class="text-muted small">No seasons configured yet.</p>
                <?php else: foreach ($seasonDest['seasons'] as $season):
                    $isPeak = $season['season_type'] === 'peak';
                    $parts = explode('-', $season['months'] ?? '');
                    $startM = (int)($parts[0] ?? 0);
                    $endM = (int)(end($parts) ?: $startM);
                    $monthLabel = ($startM >= 1 && $startM <= 12) ? ($startM === $endM ? $monthNames[$startM] : $monthNames[$startM] . ' – ' . $monthNames[$endM]) : ('Months ' . ($season['months'] ?? ''));
                ?>
                    <div class="d-flex align-items-center gap-2 border rounded-3 p-2 mb-2" style="background:<?= $isPeak ? 'rgba(239,68,68,.05)' : 'rgba(16,185,129,.05)' ?>;border-color:<?= $isPeak ? 'rgba(239,68,68,.15)' : 'rgba(16,185,129,.15)' ?>!important;">
                        <i class="fa-solid <?= $isPeak ? 'fa-fire text-danger' : 'fa-snowflake text-success' ?>"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= $isPeak ? 'Peak Season' : 'Off-Peak Season' ?></div>
                            <div class="small"><?= htmlspecialchars($monthLabel) ?></div>
                            <?php if (!empty($season['description'])): ?><div class="small text-muted"><?= htmlspecialchars($season['description']) ?></div><?php endif; ?>
                        </div>
                        <form method="POST" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="delete_season"><input type="hidden" name="season_id" value="<?= (int)$season['id'] ?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this season?')"><i class="fa-solid fa-trash"></i></button></form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="col-md-6">
                <h6 class="fw-bold mb-3" style="font-size:.9rem;">Add New Season</h6>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_season">
                    <input type="hidden" name="dest_id" value="<?= (int)$seasonDest['id'] ?>">
                    <div class="mb-3"><label class="form-label small">Season Type</label><select name="season_type" class="form-select"><option value="peak">Peak (Busy)</option><option value="off_peak">Off-Peak (Quiet)</option></select></div>
                    <div class="row g-3 mb-3">
                        <div class="col-6"><label class="form-label small">Start Month</label><select name="start_month" class="form-select"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= $monthNames[$m] ?></option><?php endfor; ?></select></div>
                        <div class="col-6"><label class="form-label small">End Month</label><select name="end_month" class="form-select"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"><?= $monthNames[$m] ?></option><?php endfor; ?></select></div>
                    </div>
                    <div class="mb-3"><label class="form-label small">Description</label><input type="text" name="season_description" class="form-control" placeholder="e.g., Best time for beach activities..."></div>
                    <button class="btn btn-brand w-100"><i class="fa-solid fa-plus me-1"></i>Add Season</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($manageGuides && $guideDest): ?>
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fa-solid fa-user-tie me-2 text-brand"></i>Manage Guides — <?= htmlspecialchars($guideDest['name']) ?></h6>
        <a href="<?= BASE_URL ?>/admin/destinations.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-xmark me-1"></i>Close</a>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-0">Guide assignment is managed inside the destination edit form (Guides section).</p>
        <?php if (!empty($assignedGuides)): ?>
            <?php foreach ($assignedGuides as $ag): ?>
                <div class="d-flex justify-content-between align-items-center border rounded-3 p-2 mb-2">
                    <div class="d-flex align-items-center gap-2"><img src="<?= get_avatar_url($ag) ?>" class="rounded-circle" width="36" height="36" alt=""><div><strong class="small"><?= htmlspecialchars($ag['name']) ?></strong> <?php if ($ag['is_primary']): ?><span class="badge bg-warning-subtle text-warning">Primary</span><?php endif; ?><div class="small text-muted"><?= (int)($ag['years_of_experience'] ?? 0) ?> yrs · Rating <?= number_format((float)($ag['avg_rating'] ?? 0), 1) ?></div></div></div>
                    <div class="d-flex gap-1">
                        <?php if (!$ag['is_primary']): ?>
                            <form method="POST" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="set_primary_guide"><input type="hidden" name="dest_id" value="<?= (int)$guideDest['id'] ?>"><input type="hidden" name="guide_id" value="<?= (int)$ag['id'] ?>"><button class="btn btn-sm btn-outline-warning" title="Set as Primary"><i class="fa-solid fa-star"></i></button></form>
                        <?php endif; ?>
                        <form method="POST" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="remove_guide"><input type="hidden" name="dest_id" value="<?= (int)$guideDest['id'] ?>"><input type="hidden" name="guide_id" value="<?= (int)$ag['id'] ?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this guide?')"><i class="fa-solid fa-times"></i></button></form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted small mb-0">No guides assigned.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Add modal -->
<div class="modal fade" id="addDestModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0c6e5e,#10b981)">
                <h5 class="modal-title fw-bold text-white mb-0"><i class="fa-solid fa-plus me-2"></i>Add New Destination</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="modal-body" style="max-height:68vh;overflow-y:auto">
                    <input type="hidden" name="action" value="add_destination">
                    <ul class="nav nav-tabs mb-3" id="addDestTabs">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#adBasic">Basic Info</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adLocation">Location &amp; Hours</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adPricing">Pricing</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adVisitor">Visitor Info</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adBooking">Booking</a></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="adBasic">
                            <div class="mb-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required placeholder="Destination name"></div>
                            <div class="mb-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Category</label><select name="category" class="form-select"><?php foreach ($allCategories as $ck => $cv): ?><option value="<?= $ck ?>"><?= htmlspecialchars($cv) ?></option><?php endforeach; ?></select></div>
                            <div class="mb-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Description</label><textarea name="description" class="form-control" rows="3" placeholder="Describe this destination..."></textarea></div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold" style="font-size:.82rem;">Video <small class="text-muted fw-normal">URL or upload a file</small></label>
                                <input type="url" name="video_url" class="form-control mb-2" placeholder="https://www.youtube.com/watch?v=...">
                                <input type="file" name="video_file" class="form-control" accept="video/mp4,video/webm,video/quicktime">
                                <small class="text-muted" style="font-size:.72rem;">MP4, WebM, or MOV (max 50MB).</small>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Cover Image</label><input type="file" name="image" class="form-control" accept="image/*"></div>
                                <div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Gallery Images</label><input type="file" name="gallery[]" class="form-control" accept="image/*" multiple></div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="adLocation">
                            <div class="mb-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Address <span class="text-danger">*</span></label><input type="text" name="location" class="form-control" required placeholder="Full address"></div>
                            <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Latitude</label><input type="text" name="latitude" class="form-control" placeholder="e.g., 10.1234567"></div><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Longitude</label><input type="text" name="longitude" class="form-control" placeholder="e.g., 122.1234567"></div></div>
                            <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Contact Phone</label><input type="text" name="contact_phone" class="form-control"></div><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Contact Email</label><input type="email" name="contact_email" class="form-control"></div></div>
                            <div class="row g-3"><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Opening Time</label><input type="time" name="operating_hours_open" class="form-control"></div><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Closing Time</label><input type="time" name="operating_hours_close" class="form-control"></div></div>
                        </div>
                        <div class="tab-pane fade" id="adPricing">
                            <div class="row g-3"><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Entrance Fee (₱)</label><input type="number" name="entrance_fee" class="form-control" value="0" min="0" step="0.01"></div><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Package Price (₱) <small class="text-muted fw-normal">Optional</small></label><input type="number" name="package_price" class="form-control" min="0" step="0.01"></div></div>
                        </div>
                        <div class="tab-pane fade" id="adVisitor">
                            <div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Max Visitors/Day</label><input type="number" name="capacity" class="form-control" value="0" min="0"></div><div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Max Guests/Booking</label><input type="number" name="max_guests_per_booking" class="form-control" value="10" min="1"></div><div class="col-md-4"><label class="form-label fw-semibold" style="font-size:.82rem;">Difficulty</label><select name="difficulty" class="form-select"><option value="easy">Easy</option><option value="moderate">Moderate</option><option value="difficult">Difficult</option><option value="extreme">Extreme</option></select></div></div>
                            <div class="row g-3 mb-3"><div class="col-md-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Min Age</label><input type="number" name="age_min" class="form-control" value="1" min="1"></div><div class="col-md-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Max Age</label><input type="number" name="age_max" class="form-control" value="100" min="1"></div><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Booking Days</label><div class="d-flex flex-wrap gap-2 mt-1"><?php foreach (['Mon' => 'Mon', 'Tue' => 'Tue', 'Wed' => 'Wed', 'Thu' => 'Thu', 'Fri' => 'Fri', 'Sat' => 'Sat', 'Sun' => 'Sun'] as $dk => $dv): ?><div class="form-check"><input type="checkbox" name="booking_days[]" value="<?= $dk ?>" class="form-check-input" id="ad_<?= $dk ?>" checked><label class="form-check-label" for="ad_<?= $dk ?>"><?= $dv ?></label></div><?php endforeach; ?></div></div></div>
                            <div class="mb-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Facilities</label><input type="text" name="facilities" class="form-control" placeholder="e.g., Parking, Restrooms, Gift Shop"></div>
                            <div class="mb-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Rules &amp; Regulations</label><textarea name="rules_regulations" class="form-control" rows="2"></textarea></div>
                            <div class="mb-0"><label class="form-label fw-semibold" style="font-size:.82rem;">Accessibility Info</label><input type="text" name="accessibility" class="form-control" placeholder="Wheelchair access, ramps, etc."></div>
                        </div>
                        <div class="tab-pane fade" id="adBooking">
                            <div class="form-check form-switch mb-3"><input type="checkbox" name="booking_enabled" class="form-check-input" id="adBkEn" checked><label class="form-check-label fw-semibold" for="adBkEn">Enable Online Booking</label></div>
                            <div class="mb-3"><label class="form-label fw-semibold" style="font-size:.82rem;">Booking Price per Person (₱)</label><input type="number" name="booking_price" class="form-control" value="0" min="0" step="0.01"><small class="text-muted" style="font-size:.72rem;">Leave 0 if included in entrance fee.</small></div>
                            <div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Booking Cut-off</label><div class="input-group"><input type="number" name="booking_cutoff_hours" class="form-control" value="2" min="0"><span class="input-group-text">hrs before</span></div></div><div class="col-md-6"><label class="form-label fw-semibold" style="font-size:.82rem;">Advance Booking</label><div class="input-group"><input type="number" name="advance_booking_days" class="form-control" value="1" min="0"><span class="input-group-text">day(s) ahead</span></div></div></div>
                            <div class="mb-0"><label class="form-label fw-semibold" style="font-size:.82rem;">Cancellation Policy</label><textarea name="cancellation_policy" class="form-control" rows="2"></textarea></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-plus me-1"></i>Add Destination</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit modal (body injected via AJAX) -->
<div class="modal fade" id="editDestModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="editDestTitle">Edit Destination</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editDestBody" style="max-height:68vh;overflow-y:auto">
                <div class="text-center py-5"><div class="spinner-border text-brand"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Confirm modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="fs-1 text-danger mb-2"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <h6 class="fw-bold mb-1" id="confirmTitle">Are you sure?</h6>
                <div class="text-muted small" id="confirmMsg"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="confirmOk"><i class="fa-solid fa-check me-1"></i>Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const CATEGORIES = <?= json_encode(array_map(fn($k, $v) => ['key' => $k, 'label' => $v], array_keys($allCategories), array_values($allCategories))) ?>;
const state = { page: 1, per_page: 15, search: <?= json_encode($search) ?>, cat: <?= json_encode($catFilter) ?>, status: <?= json_encode($statusFilter) ?>, feat: '', bk: '', total: 0, pages: 1, loading: false };
const __dest = {};
let selected = new Set();
let pendingConfirm = null;
let debounceTimer = null;

const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);

function esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function money(v) { return '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (type === 'error' ? 'danger' : type) + ' border-0 show';
    el.innerHTML = '<div class="d-flex"><div class="toast-body">' + esc(msg) + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    document.querySelector('.toast-container').appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3200 }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
function statusBadge(st) {
    const m = { active: ['bg-success-subtle text-success', 'fa-circle-check'], inactive: ['bg-secondary-subtle text-secondary', 'fa-circle'], closed: ['bg-danger-subtle text-danger', 'fa-circle-xmark'], maintenance: ['bg-warning-subtle text-warning', 'fa-screwdriver-wrench'] };
    const c = m[st] || m.inactive;
    return '<span class="badge ' + c[0] + '"><i class="fa-solid ' + c[1] + ' me-1"></i>' + esc(st) + '</span>';
}
function catLabel(k) { const c = CATEGORIES.find(x => x.key === k); return c ? c.label : k; }
function qs() {
    const p = new URLSearchParams();
    if (state.search) p.set('search', state.search);
    if (state.cat) p.set('category', state.cat);
    if (state.status) p.set('status', state.status);
    p.set('page', state.page); p.set('per_page', state.per_page);
    return p.toString();
}
function skeletonRows(n) {
    let h = '';
    for (let i = 0; i < n; i++) h += '<tr><td><span class="form-check-input d-block"></span></td><td><span class="skeleton" style="width:44px;height:16px;display:inline-block"></span></td><td><div class="d-flex align-items-center gap-2"><span class="skeleton dest-thumb"></span><span class="skeleton" style="width:140px;height:10px"></span></div></td><td><span class="skeleton" style="width:80px;height:18px;display:inline-block"></span></td><td><span class="skeleton" style="width:120px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:60px;height:10px;display:inline-block"></span></td><td><span class="skeleton" style="width:60px;height:18px;display:inline-block"></span></td><td><span class="skeleton" style="width:30px;height:18px;display:inline-block"></span></td><td><span class="skeleton" style="width:70px;height:18px;display:inline-block"></span></td><td class="text-end"><span class="skeleton" style="width:130px;height:18px;display:inline-block"></span></td></tr>';
    return h;
}
function applyStats(s) {
    if (!s) return;
    $('#kpi-total').textContent = s.total; $('#kpi-active').textContent = s.active;
    $('#kpi-featured').textContent = s.featured; $('#kpi-booking').textContent = s.booking_open;
    $$('.kpi-card').forEach(k => k.classList.remove('active'));
    if (state.status) { const k = document.querySelector('.kpi-card[data-status="' + state.status + '"]'); if (k) k.classList.add('active'); }
    else if (state.feat) { const k = document.querySelector('.kpi-card[data-feat="1"]'); if (k) k.classList.add('active'); }
    else if (state.bk) { const k = document.querySelector('.kpi-card[data-bk="1"]'); if (k) k.classList.add('active'); }
}
function render(rows) {
    const body = $('#destBody');
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-5"><i class="fa-solid fa-map-marked-alt fa-2x d-block mb-2"></i>No destinations found.</td></tr>';
        renderFooter(); return;
    }
    let h = '';
    rows.forEach(d => {
        __dest[d.id] = d;
        const sel = selected.has(d.id) ? 'checked' : '';
        const thumb = d.image_url ? '<img src="' + esc(d.image_url) + '" class="dest-thumb" alt="" onerror="this.style.visibility=\'hidden\'">' : '<div class="dest-thumb d-flex align-items-center justify-content-center" style="background:var(--border-color)"><i class="fa-regular fa-image text-muted"></i></div>';
        h += '<tr data-id="' + d.id + '">'
            + '<td><input type="checkbox" class="form-check-input row-check" data-id="' + d.id + '" ' + sel + '></td>'
            + '<td><span class="small font-monospace text-muted" style="background:var(--border-color);padding:3px 9px;border-radius:6px">#' + d.id + '</span></td>'
            + '<td><div class="d-flex align-items-center gap-2">' + thumb + '<span class="fw-semibold">' + esc(d.name) + (d.featured ? ' <i class="fa-solid fa-star text-warning" title="Featured"></i>' : '') + '</span></div></td>'
            + '<td><span class="badge bg-primary-subtle text-primary">' + esc(catLabel(d.category)) + '</span></td>'
            + '<td class="small">' + esc(d.location) + '</td>'
            + '<td class="small fw-semibold">' + money(d.entrance_fee) + '</td>'
            + '<td><span class="status-chip booking-pill" data-id="' + d.id + '" data-val="' + d.booking_enabled + '" style="background:' + (d.booking_enabled ? '#d1fae5' : '#f3f4f6') + ';color:' + (d.booking_enabled ? '#059669' : '#6b7280') + '"><i class="fa-solid fa-circle" style="font-size:6px"></i>' + (d.booking_enabled ? 'Open' : 'Closed') + '</span></td>'
            + '<td><button class="star-toggle" data-id="' + d.id + '" data-val="' + d.featured + '" title="' + (d.featured ? 'Unfeature' : 'Feature') + '" style="color:' + (d.featured ? '#f59e0b' : 'var(--text-muted)') + '"><i class="fa-solid fa-star"></i></button></td>'
            + '<td><select class="form-select form-select-sm status-sel" data-id="' + d.id + '" style="min-width:130px">'
            + ['active', 'inactive', 'closed', 'maintenance'].map(s => '<option value="' + s + '" ' + (s === d.status ? 'selected' : '') + '>' + s + '</option>').join('')
            + '</select></td>'
            + '<td class="text-end"><div class="btn-group btn-group-sm">'
            + '<button class="btn btn-outline-secondary" title="Edit" onclick="openEdit(' + d.id + ')"><i class="fa-solid fa-pen"></i></button>'
            + '<button class="btn btn-outline-secondary" title="Seasons" onclick="location.href=\'destinations.php?manage_seasons=' + d.id + '\'"><i class="fa-solid fa-calendar-days"></i></button>'
            + '<button class="btn btn-outline-secondary" title="Guides" onclick="location.href=\'destinations.php?manage_guides=' + d.id + '\'"><i class="fa-solid fa-user-tie"></i></button>'
            + '<button class="btn btn-outline-danger" title="Delete" onclick="askDelete(' + d.id + ')"><i class="fa-solid fa-trash"></i></button>'
            + '</div></td></tr>';
    });
    body.innerHTML = h;
    renderFooter();
}
function renderFooter() {
    const from = state.total === 0 ? 0 : (state.page - 1) * state.per_page + 1;
    const to = Math.min(state.page * state.per_page, state.total);
    $('#footerInfo').textContent = 'Showing ' + from + '–' + to + ' of ' + state.total + ' destinations';
    const p = $('#pager'); p.innerHTML = '';
    const mk = (label, page, disabled, active) => {
        const b = document.createElement('button');
        b.className = 'btn btn-sm ' + (active ? 'btn-brand' : 'btn-outline-secondary') + (disabled ? ' disabled' : '');
        b.innerHTML = label;
        if (!disabled) b.onclick = () => { state.page = page; load(); };
        p.appendChild(b);
    };
    mk('<i class="fa-solid fa-angles-left"></i>', 1, state.page === 1);
    mk('<i class="fa-solid fa-chevron-left"></i>', state.page - 1, state.page === 1);
    for (let i = 1; i <= state.pages; i++) {
        if (i === 1 || i === state.pages || Math.abs(i - state.page) <= 1) mk(String(i), i, false, i === state.page);
        else if (Math.abs(i - state.page) === 2) mk('…', i, true);
    }
    mk('<i class="fa-solid fa-chevron-right"></i>', state.page + 1, state.page === state.pages);
    mk('<i class="fa-solid fa-angles-right"></i>', state.pages, state.page === state.pages);
}
async function load() {
    if (state.loading) return;
    $('#destBody').innerHTML = skeletonRows(5);
    state.loading = true;
    try {
        const r = await fetch('/Tourism/admin/destinations.php?ajax=1&' + qs());
        const d = await r.json();
        state.total = d.total; state.pages = d.pages; state.page = d.page; state.per_page = d.per_page;
        applyStats(d.stats);
        render(d.rows);
        const sa = $('#selectAll'); if (sa) sa.checked = false;
        clearSelection();
    } catch {
        $('#destBody').innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4">Failed to load destinations.</td></tr>';
    } finally { state.loading = false; }
}
function onSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { state.search = $('#searchInput').value.trim(); state.page = 1; load(); }, 400);
}
function applyFilters() {
    state.cat = $('#catFilter').value; state.status = $('#statusFilter').value; state.search = $('#searchInput').value.trim();
    state.page = 1;
    renderChips(); load();
}
function clearFilters() {
    state.cat = state.status = state.search = state.feat = state.bk = '';
    $('#catFilter').value = $('#statusFilter').value = ''; $('#searchInput').value = '';
    renderChips(); load();
}
function renderChips() {
    const w = $('#chipRow');
    const parts = [];
    if (state.cat) parts.push('<span class="filter-chip">Category: ' + esc(catLabel(state.cat)) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    if (state.status) parts.push('<span class="filter-chip">Status: ' + esc(state.status) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    if (state.feat) parts.push('<span class="filter-chip">Featured <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    if (state.bk) parts.push('<span class="filter-chip">Booking Open <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    if (state.search) parts.push('<span class="filter-chip">Search: ' + esc(state.search) + ' <a href="#" onclick="clearFilters();return false;"><i class="fa-solid fa-xmark"></i></a></span>');
    w.innerHTML = parts.join('');
}
function post(data, cb) {
    const fd = new FormData();
    Object.keys(data).forEach(k => {
        if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k, v));
        else fd.append(k, data[k]);
    });
    fetch('/Tourism/admin/destinations.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json()).then(d => cb(d.ok, d.message)).catch(() => cb(false, 'Request failed.'));
}
function askConfirm(title, msg, fn) {
    $('#confirmTitle').textContent = title;
    $('#confirmMsg').textContent = msg;
    pendingConfirm = fn;
    bootstrap.Modal.getOrCreateInstance($('#confirmModal')).show();
}
$('#confirmOk').addEventListener('click', () => { if (pendingConfirm) { pendingConfirm(); pendingConfirm = null; } bootstrap.Modal.getInstance($('#confirmModal')).hide(); });
function askDelete(id) {
    const d = __dest[id];
    askConfirm('Delete destination "' + (d ? d.name : '#' + id) + '"?', 'This permanently removes the destination and its related data.', () => {
        post({ action: 'delete_destination', dest_id: id, csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (ok) load(); });
    });
}
// toggles
document.addEventListener('click', (e) => {
    const star = e.target.closest('.star-toggle');
    if (star) {
        const id = parseInt(star.dataset.id);
        const val = star.dataset.val === '1' ? 0 : 1;
        const d = __dest[id]; if (!d) return;
        d.featured = val; star.dataset.val = val;
        star.style.color = val ? '#f59e0b' : 'var(--text-muted)';
        star.title = val ? 'Unfeature' : 'Feature';
        toast('Updating...', 'info');
        post({ action: 'toggle_featured', dest_id: id, csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); load(); });
        return;
    }
    const pill = e.target.closest('.booking-pill');
    if (pill) {
        const id = parseInt(pill.dataset.id);
        const val = pill.dataset.val === '1' ? 0 : 1;
        const d = __dest[id]; if (!d) return;
        d.booking_enabled = val; pill.dataset.val = val;
        pill.style.background = val ? '#d1fae5' : '#f3f4f6';
        pill.style.color = val ? '#059669' : '#6b7280';
        pill.innerHTML = '<i class="fa-solid fa-circle" style="font-size:6px"></i>' + (val ? 'Open' : 'Closed');
        post({ action: 'toggle_booking', dest_id: id, csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (!ok) load(); });
    }
});
document.addEventListener('change', (e) => {
    if (e.target.classList.contains('status-sel')) {
        const id = parseInt(e.target.dataset.id);
        const st = e.target.value;
        post({ action: 'set_status', dest_id: id, new_status: st, csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); load(); });
    }
    if (e.target.classList.contains('row-check')) onSelectChange();
});
// edit
async function openEdit(id) {
    const d = __dest[id]; if (!d) return;
    $('#editDestTitle').textContent = 'Edit Destination — ' + d.name;
    $('#editDestBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-brand"></div></div>';
    bootstrap.Modal.getOrCreateInstance($('#editDestModal')).show();
    try {
        const r = await fetch('/Tourism/admin/destinations.php?ajax=1&mode=form&id=' + id);
        const j = await r.json();
        if (j.ok) $('#editDestBody').innerHTML = j.html;
        else { $('#editDestBody').innerHTML = '<div class="text-center text-danger py-5">' + esc(j.message || 'Failed to load form.') + '</div>'; }
    } catch {
        $('#editDestBody').innerHTML = '<div class="text-center text-danger py-5">Failed to load the edit form.</div>';
    }
}
// select/bulk
function onSelectChange() {
    selected.clear();
    $$('.row-check:checked').forEach(cb => selected.add(parseInt(cb.dataset.id)));
    const bar = $('#bulkBar');
    $('#bulkCount').textContent = selected.size + ' selected';
    bar.classList.toggle('show', selected.size > 0);
}
function clearSelection() {
    selected.clear();
    $$('.row-check').forEach(cb => cb.checked = false);
    const sa = $('#selectAll'); if (sa) sa.checked = false;
    $('#bulkBar').classList.remove('show');
}
function bulk(action, value) {
    if (!selected.size) return;
    const label = { bulk_featured: value === '1' ? 'feature' : 'unfeature', bulk_booking: value === '1' ? 'open booking for' : 'close booking for' }[action] || '';
    askConfirm(label ? ('Set ' + selected.size + ' destination(s) to ' + label + '?') : 'Apply to ' + selected.size + ' destination(s)?', 'This will update the selected destinations.', () => {
        post({ action, value, dest_ids: Array.from(selected), csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (ok) { clearSelection(); load(); } });
    });
}
function bulkDelete() {
    if (!selected.size) return;
    askConfirm('Delete ' + selected.size + ' destination(s)?', 'This permanently removes the selected destinations.', () => {
        post({ action: 'bulk_delete', dest_ids: Array.from(selected), csrf_token: CSRF }, (ok, msg) => { toast(msg, ok ? 'success' : 'error'); if (ok) { clearSelection(); load(); } });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const cat = $('#catFilter');
    CATEGORIES.forEach(c => cat.insertAdjacentHTML('beforeend', '<option value="' + esc(c.key) + '">' + esc(c.label) + '</option>'));
    cat.value = state.cat;
    $('#searchInput').addEventListener('input', onSearch);
    $('#applyFilters').addEventListener('click', applyFilters);
    $('#clearFilters').addEventListener('click', clearFilters);
    $('#refreshBtn').addEventListener('click', load);
    $('#perPage').addEventListener('change', () => { state.per_page = parseInt($('#perPage').value); state.page = 1; load(); });
    $('#selectAll').addEventListener('change', (e) => { $$('.row-check').forEach(cb => cb.checked = e.target.checked); onSelectChange(); });
    $$('.kpi-card').forEach(k => k.addEventListener('click', () => {
        state.feat = state.bk = '';
        if (k.dataset.status) { state.status = state.status === k.dataset.status ? '' : k.dataset.status; state.cat = ''; }
        else if (k.dataset.feat) state.feat = state.feat ? '' : '1';
        else if (k.dataset.bk) state.bk = state.bk ? '' : '1';
        state.page = 1;
        $('#statusFilter').value = state.status;
        renderChips(); load();
    }));
    renderChips();
    load();
});
</script>
<?php }); ?>