<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');

$event = new Event();
$destination = new Destination();
$schedule = new Schedule();

if (is_post()) {
    $action = $_POST['action'] ?? '';

        if ($action === 'create') {
        $data = [
            'destination_id'   => (int)($_POST['destination_id'] ?? 0),
            'title'            => sanitize($_POST['title'] ?? ''),
            'description'      => sanitize($_POST['description'] ?? ''),
            'category'         => $_POST['category'] ?? 'tourism_event',
            'event_start_date' => $_POST['event_start_date'] ?? null,
            'event_end_date'   => $_POST['event_end_date'] ?? null,
            'event_start_time' => $_POST['event_start_time'] ?? null,
            'event_end_time'   => $_POST['event_end_time'] ?? null,
            'price'            => (float)($_POST['price'] ?? 0),
            'max_participants' => (int)($_POST['max_participants'] ?? 20),
            'min_participants' => (int)($_POST['min_participants'] ?? 1),
            'duration_hours'   => (float)($_POST['duration_hours'] ?? 1),
            'organizer'        => sanitize($_POST['organizer'] ?? ''),
            'contact_info'     => sanitize($_POST['contact_info'] ?? ''),
            'event_location'   => sanitize($_POST['event_location'] ?? ''),
            'min_age'          => (int)($_POST['min_age'] ?? 1),
            'max_age'          => !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null,
            'health_restrictions' => sanitize($_POST['health_restrictions'] ?? ''),
            'requires_guide'   => isset($_POST['requires_guide']) ? 1 : 0,
            'status'           => $_POST['status'] ?? 'draft',
            'created_by'       => $_SESSION['user_id'] ?? null,
        ];

        if (!empty($data['title']) && $data['destination_id']) {
            $image = $_FILES['event_image'] ?? null;
            if ($image && $image['error'] === UPLOAD_ERR_OK) {
                $uploadResult = upload_file($image, 'events', ['jpg', 'jpeg', 'png', 'webp']);
                if ($uploadResult['success']) {
                    $data['event_image'] = $uploadResult['filename'];
                }
            }
            $newId = $event->create($data);
            if ($newId) {
                flash_message('success', 'Event created successfully.');
                redirect('/staff/events.php');
            }
        }
        flash_message('error', 'Failed to create event. Title and destination are required.');
        redirect('/staff/events.php');
    }

    if ($action === 'update') {
        $id = (int)($_POST['event_id'] ?? 0);
        $data = [
            'destination_id'   => (int)($_POST['destination_id'] ?? 0),
            'title'            => sanitize($_POST['title'] ?? ''),
            'description'      => sanitize($_POST['description'] ?? ''),
            'category'         => $_POST['category'] ?? 'tourism_event',
            'event_start_date' => $_POST['event_start_date'] ?? null,
            'event_end_date'   => $_POST['event_end_date'] ?? null,
            'event_start_time' => $_POST['event_start_time'] ?? null,
            'event_end_time'   => $_POST['event_end_time'] ?? null,
            'price'            => (float)($_POST['price'] ?? 0),
            'max_participants' => (int)($_POST['max_participants'] ?? 20),
            'min_participants' => (int)($_POST['min_participants'] ?? 1),
            'duration_hours'   => (float)($_POST['duration_hours'] ?? 1),
            'organizer'        => sanitize($_POST['organizer'] ?? ''),
            'contact_info'     => sanitize($_POST['contact_info'] ?? ''),
            'event_location'   => sanitize($_POST['event_location'] ?? ''),
            'min_age'          => (int)($_POST['min_age'] ?? 1),
            'max_age'          => !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null,
            'health_restrictions' => sanitize($_POST['health_restrictions'] ?? ''),
            'requires_guide'   => isset($_POST['requires_guide']) ? 1 : 0,
            'status'           => $_POST['status'] ?? 'draft',
        ];

        if ($id && !empty($data['title'])) {
            $image = $_FILES['event_image'] ?? null;
            if ($image && $image['error'] === UPLOAD_ERR_OK) {
                $uploadResult = upload_file($image, 'events', ['jpg', 'jpeg', 'png', 'webp']);
                if ($uploadResult['success']) {
                    $data['event_image'] = $uploadResult['filename'];
                }
            }
            $event->update($id, $data);
            flash_message('success', 'Event updated successfully.');
            redirect('/staff/events.php');
        }
        flash_message('error', 'Failed to update event.');
        redirect('/staff/events.php');
    }
}

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = [];
if ($search) $filters['search'] = $search;
if ($statusFilter) $filters['status'] = $statusFilter;

$events = $event->findAll($filters, $page, 12);
$allDestinations = $destination->findAll([], 1, 200)['data'];

render_page('staff', 'events.php', 'Event Management', function () use ($events, $allDestinations, $search, $statusFilter, $event, $schedule) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;}
.filter-card .form-control,.filter-card .form-select{border-radius:10px;border:1px solid var(--border-color,#e2e8f0);padding:10px 14px;font-size:0.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);}
.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.section-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);}
.event-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;transition:all 0.2s;}
.event-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.08);}
.event-card .event-body{padding:16px;}
.event-card .event-footer{padding:12px 16px;border-top:1px solid var(--border-color,#f1f5f9);display:flex;gap:8px;}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:0.78rem;font-weight:600;}
.status-chip.active{background:rgba(34,197,94,0.12);color:#16a34a;}
.status-chip.inactive{background:rgba(100,116,139,0.12);color:#475569;}
.status-chip.draft{background:rgba(234,179,8,0.12);color:#ca8a04;}
.status-chip.published{background:rgba(34,197,94,0.12);color:#16a34a;}
.status-chip.cancelled{background:rgba(239,68,68,0.12);color:#dc2626;}
.status-chip.completed{background:rgba(59,130,246,0.12);color:#2563eb;}
.status-chip.completed{background:rgba(59,130,246,0.12);color:#2563eb;}
.action-btn{height:32px;border-radius:8px;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;font-size:0.8rem;padding:0 12px;gap:4px;}
.action-btn:hover{border-color:var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.05);}
.profile-input{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 14px;color:var(--text-primary,#1e293b);width:100%;font-size:0.9rem;transition:all 0.2s;}
.profile-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.1);}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.table-card .table{margin-bottom:0;color:var(--text-primary,#1e293b);}
.table-card .table thead th{background:var(--bg-secondary,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0);font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:0.5px;padding:12px 16px;}
.table-card .table tbody td{padding:12px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);font-size:0.88rem;}
.table-card .table tbody tr:last-child td{border-bottom:none;}
.pagination .page-item .page-link{border-radius:8px;margin:0 3px;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);font-size:0.85rem;padding:6px 12px;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-item .page-link:hover:not(.active){background:rgba(12,110,94,0.05);color:var(--primary,#0c6e5e);}
.drop-zone{border:2px dashed var(--border-color,#cbd5e1);border-radius:12px;padding:24px 16px;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--bg-secondary,#f8fafc);}
.drop-zone:hover{border-color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.03);}
</style>

<?php
$viewAction = $_GET['action'] ?? '';
$viewId = (int)($_GET['id'] ?? 0);
if ($viewAction === 'view' && $viewId):
    $ev = $event->findById($viewId);
    $evSchedules = $schedule->findAll(['event_id' => $viewId], 1, 50);
?>
<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-calendar me-2"></i><?= sanitize($ev['title'] ?? 'N/A') ?></h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Event details and associated schedules</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <a href="events.php" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;padding:8px 20px;border:none;"><i class="fas fa-arrow-left me-1"></i>Back to Events</a>
        </div>
    </div>
</div>

<div class="section-card mb-4">
    <div class="section-header">
        <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.12);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-info-circle" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i>
        </div>
        <h6>Event Information</h6>
    </div>
    <div style="padding:20px;">
        <div class="row g-3">
            <?php if (!empty($ev['event_image'])): ?>
            <div class="col-12 mb-2">
                <img src="<?= event_image_url($ev['event_image']) ?>" alt="<?= sanitize($ev['title']) ?>" style="max-height:200px;border-radius:12px;object-fit:cover;border:1px solid var(--border-color,#e2e8f0);">
            </div>
            <?php endif; ?>
            <div class="col-md-6">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Title</span><div class="fw-semibold" style="color:var(--text-primary,#1e293b);"><?= sanitize($ev['title'] ?? 'N/A') ?></div></div>
            </div>
            <div class="col-md-6">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Destination</span><div class="fw-semibold" style="color:var(--text-primary,#1e293b);"><?= sanitize($ev['destination_name'] ?? 'N/A') ?></div></div>
            </div>
            <div class="col-md-6">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Category</span><div style="color:var(--text-primary,#1e293b);"><?= ucfirst(str_replace('_',' ', $ev['category'] ?? 'N/A')) ?></div></div>
            </div>
            <div class="col-md-6">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Status</span><div><span class="status-chip <?= $ev['status'] ?? 'draft' ?>"><?= ucfirst($ev['status'] ?? 'Draft') ?></span></div></div>
            </div>
            <div class="col-md-4">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Start Date</span><div style="color:var(--text-primary,#1e293b);"><?= format_date($ev['event_start_date'] ?? '') ?: 'N/A' ?></div></div>
            </div>
            <div class="col-md-4">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">End Date</span><div style="color:var(--text-primary,#1e293b);"><?= format_date($ev['event_end_date'] ?? '') ?: 'N/A' ?></div></div>
            </div>
            <div class="col-md-4">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Duration</span><div style="color:var(--text-primary,#1e293b);"><?= $ev['duration_hours'] ?? 'N/A' ?> hrs</div></div>
            </div>
            <div class="col-md-3">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Price</span><div style="color:var(--text-primary,#1e293b);">₱<?= number_format($ev['price'] ?? 0, 2) ?></div></div>
            </div>
            <div class="col-md-3">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Min/Max Participants</span><div style="color:var(--text-primary,#1e293b);"><?= $ev['min_participants'] ?? 1 ?> — <?= $ev['max_participants'] ?? 20 ?></div></div>
            </div>
            <div class="col-md-3">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Age Range</span><div style="color:var(--text-primary,#1e293b);"><?= $ev['min_age'] ?? 1 ?> — <?= $ev['max_age'] ?? 'Any' ?></div></div>
            </div>
            <div class="col-md-3">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Requires Guide</span><div style="color:var(--text-primary,#1e293b);"><?= ($ev['requires_guide'] ?? 1) ? 'Yes' : 'No' ?></div></div>
            </div>
            <?php if (!empty($ev['organizer'])): ?>
            <div class="col-md-4">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Organizer</span><div style="color:var(--text-primary,#1e293b);"><?= sanitize($ev['organizer']) ?></div></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($ev['contact_info'])): ?>
            <div class="col-md-4">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Contact Info</span><div style="color:var(--text-primary,#1e293b);"><?= sanitize($ev['contact_info']) ?></div></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($ev['event_location'])): ?>
            <div class="col-md-4">
                <div class="mb-3"><span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Event Location</span><div style="color:var(--text-primary,#1e293b);"><?= sanitize($ev['event_location']) ?></div></div>
            </div>
            <?php endif; ?>
            <div class="col-12">
                <span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Description</span>
                <div class="mt-1" style="color:var(--text-primary,#1e293b);line-height:1.6;"><?= nl2br(sanitize($ev['description'] ?? 'No description.')) ?></div>
            </div>
            <?php if (!empty($ev['health_restrictions'])): ?>
            <div class="col-12">
                <span class="small fw-semibold" style="color:var(--text-muted,#64748b);">Health Restrictions</span>
                <div class="mt-1" style="color:var(--text-primary,#1e293b);line-height:1.6;"><?= nl2br(sanitize($ev['health_restrictions'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="section-card">
    <div class="section-header">
        <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-calendar-alt" style="color:#3b82f6;font-size:0.7rem;"></i>
        </div>
        <h6>Event Schedules</h6>
    </div>
    <?php if (empty($evSchedules['data'])): ?>
        <div class="text-center py-4">
            <p class="text-muted small mb-0">No schedules for this event.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Guide</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Max Participants</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($evSchedules['data'] as $s): ?>
                    <tr>
                        <td class="text-muted">#<?= $s['id'] ?></td>
                        <td><?= sanitize($s['guide_name'] ?? 'Unassigned') ?></td>
                        <td><?= format_date($s['start_date'] ?? '') ?> <?= sanitize($s['start_time'] ?? '') ?></td>
                        <td><?= format_date($s['end_date'] ?? '') ?> <?= sanitize($s['end_time'] ?? '') ?></td>
                        <td><?= $s['available_spots'] ?? 20 ?></td>
                        <td><span class="status-chip <?= match($s['status'] ?? '') { 'scheduled' => 'active', 'completed' => 'completed', 'cancelled' => 'inactive', default => 'inactive' } ?>"><?= ucfirst($s['status'] ?? '') ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-calendar me-2"></i>Event Management</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Manage tourism events and tie them to destinations</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <button type="button" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;padding:8px 20px;border:none;" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="fas fa-plus me-1"></i>Add Event
            </button>
        </div>
    </div>
</div>

<div class="filter-card mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small fw-semibold">Search</label>
            <input type="text" name="search" class="form-control" placeholder="Event title..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Status</label>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm" style="background:var(--primary,#0c6e5e);color:#fff;border-radius:8px;padding:8px 16px;"><i class="fas fa-search me-1"></i>Filter</button>
            <a href="events.php" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:8px;padding:8px 16px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);">Reset</a>
        </div>
    </form>
</div>

<div class="row g-3">
    <?php if (empty($events['data'])): ?>
        <div class="col-12">
            <div class="empty-state">
                <div class="empty-illustration">
                    <i class="fas fa-calendar"></i>
                    <span class="empty-ring"></span>
                </div>
                <div class="empty-title">No Events Found</div>
                <p class="empty-text"><?= $search || $statusFilter ? 'No events match your current filters. Try adjusting your search or filters.' : 'Create your first event to start planning tours and experiences.' ?></p>
                <div class="empty-actions">
                    <?php if ($search || $statusFilter): ?>
                        <a href="events.php" class="btn-cta ghost"><i class="fas fa-redo me-1"></i>Reset Filters</a>
                    <?php else: ?>
                        <button type="button" class="btn-cta" data-bs-toggle="modal" data-bs-target="#createModal"><i class="fas fa-plus me-1"></i>Add Event</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($events['data'] as $e): ?>
        <div class="col-md-6 col-xl-4">
            <div class="event-card h-100">
                <div class="event-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-bold mb-0" style="font-size:0.95rem;"><?= sanitize($e['title']) ?></h6>
                        <span class="status-chip <?= $e['status'] ?? 'draft' ?>"><?= ucfirst($e['status'] ?? 'Draft') ?></span>
                    </div>
                    <p class="text-muted small mb-2"><i class="fas fa-map-marker-alt me-1"></i><?= sanitize($e['destination_name'] ?? 'N/A') ?></p>
                    <p class="small mb-2" style="color:var(--text-muted,#64748b);"><?= sanitize(truncate($e['description'] ?? '', 120)) ?></p>
                    <div class="text-muted" style="font-size:0.75rem;">
                        <i class="fas fa-clock me-1"></i>Created: <?= format_datetime($e['created_at'] ?? '') ?>
                    </div>
                </div>
                <div class="event-footer">
                    <a href="events.php?action=view&id=<?= $e['id'] ?>" class="action-btn"><i class="fas fa-eye"></i>View</a>
                    <button type="button" class="action-btn" data-bs-toggle="modal" data-bs-target="#editModal<?= $e['id'] ?>"><i class="fas fa-edit"></i>Edit</button>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editModal<?= $e['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);">
                    <div class="modal-header" style="border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;">
                        <h5 class="modal-title" style="font-weight:700;color:#fff;"><i class="fas fa-edit me-2"></i>Edit Event</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="event_id" value="<?= $e['id'] ?>">
                        <div class="modal-body" style="padding:24px;max-height:70vh;overflow-y:auto;">

                            <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                                <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-info-circle me-1"></i>Basic Information</span>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Title <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="title" class="profile-input" value="<?= sanitize($e['title']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Destination <span style="color:#ef4444;">*</span></label>
                                    <select name="destination_id" class="profile-input" required>
                                        <?php foreach ($allDestinations as $d): ?>
                                            <option value="<?= $d['id'] ?>" <?= $e['destination_id'] == $d['id'] ? 'selected' : '' ?>><?= sanitize($d['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Category</label>
                                    <select name="category" class="profile-input">
                                        <?php foreach (['tourism_event'=>'Tourism Event','community_event'=>'Community Event','cultural_festival'=>'Cultural Festival','nature_tour'=>'Nature Tour','heritage_walk'=>'Heritage Walk','adventure_activity'=>'Adventure Activity','workshop'=>'Workshop','other'=>'Other'] as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= ($e['category'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Description</label>
                                    <textarea name="description" class="profile-input" rows="3" style="resize:vertical;"><?= sanitize($e['description'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Event Location</label>
                                    <input type="text" name="event_location" class="profile-input" value="<?= sanitize($e['event_location'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                                <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-calendar-alt me-1"></i>Schedule & Pricing</span>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Start Date</label>
                                    <input type="date" name="event_start_date" class="profile-input" value="<?= $e['event_start_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">End Date</label>
                                    <input type="date" name="event_end_date" class="profile-input" value="<?= $e['event_end_date'] ?? '' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Start Time</label>
                                    <input type="time" name="event_start_time" class="profile-input" value="<?= $e['event_start_time'] ?? '08:00' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">End Time</label>
                                    <input type="time" name="event_end_time" class="profile-input" value="<?= $e['event_end_time'] ?? '17:00' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Price (₱)</label>
                                    <input type="number" name="price" class="profile-input" step="0.01" min="0" value="<?= $e['price'] ?? 0 ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Duration (hrs)</label>
                                    <input type="number" name="duration_hours" class="profile-input" step="0.5" min="0.5" value="<?= $e['duration_hours'] ?? 1 ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Status</label>
                                    <select name="status" class="profile-input">
                                        <option value="draft" <?= ($e['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                        <option value="published" <?= ($e['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                                        <option value="cancelled" <?= ($e['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                        <option value="completed" <?= ($e['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                                <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-users me-1"></i>Participants</span>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Participants</label>
                                    <input type="number" name="max_participants" class="profile-input" min="1" value="<?= $e['max_participants'] ?? 20 ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Min Participants</label>
                                    <input type="number" name="min_participants" class="profile-input" min="1" value="<?= $e['min_participants'] ?? 1 ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Min Age</label>
                                    <input type="number" name="min_age" class="profile-input" min="0" value="<?= $e['min_age'] ?? 1 ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Age</label>
                                    <input type="number" name="max_age" class="profile-input" min="0" value="<?= $e['max_age'] ?? '' ?>" placeholder="No limit">
                                </div>
                            </div>

                            <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                                <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-address-card me-1"></i>Organizer & Details</span>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Organizer</label>
                                    <input type="text" name="organizer" class="profile-input" value="<?= sanitize($e['organizer'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Info</label>
                                    <input type="text" name="contact_info" class="profile-input" value="<?= sanitize($e['contact_info'] ?? '') ?>">
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="requires_guide" value="1" id="editRequiresGuide<?= $e['id'] ?>" <?= ($e['requires_guide'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold small" style="color:var(--text-muted,#64748b);" for="editRequiresGuide<?= $e['id'] ?>">Requires Guide</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Health Restrictions</label>
                                    <textarea name="health_restrictions" class="profile-input" rows="2" style="resize:vertical;"><?= sanitize($e['health_restrictions'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                                <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-camera me-1"></i>Event Photo</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="drop-zone" onclick="document.getElementById('editStaffEventImage<?= $e['id'] ?>').click()">
                                        <div id="editStaffEventPlaceholder<?= $e['id'] ?>">
                                            <?php if (!empty($e['event_image'])): ?>
                                                <img id="editStaffEventPreview<?= $e['id'] ?>" class="rounded" style="max-height:100px;object-fit:cover;" alt="Preview" src="<?= event_image_url($e['event_image']) ?>">
                                            <?php else: ?>
                                                <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem;color:var(--text-muted,#94a3b8);margin-bottom:6px;display:block;"></i>
                                                <div class="small fw-semibold">Click to change photo</div>
                                                <div style="font-size:0.75rem;color:var(--text-muted,#94a3b8);">JPG, PNG, WebP — Max 5MB</div>
                                                <img id="editStaffEventPreview<?= $e['id'] ?>" class="d-none rounded" style="max-height:100px;object-fit:cover;" alt="Preview">
                                            <?php endif; ?>
                                        </div>
                                        <input type="file" name="event_image" id="editStaffEventImage<?= $e['id'] ?>" class="d-none" accept="image/*" onchange="previewEditStaffEventImage(this, <?= $e['id'] ?>)">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px;">
                            <button type="button" class="btn" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-save me-1"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($events['pages'] > 1): ?>
<div class="d-flex justify-content-center mt-4">
    <nav>
        <ul class="pagination mb-0">
            <?php if ($events['page'] > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $events['page'] - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>"><i class="fas fa-chevron-left"></i></a></li>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $events['pages']; $i++): ?>
                <li class="page-item <?= $i == $events['page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <?php if ($events['page'] < $events['pages']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $events['page'] + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>"><i class="fas fa-chevron-right"></i></a></li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);">
            <div class="modal-header" style="border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;">
                <h5 class="modal-title" style="font-weight:700;color:#fff;"><i class="fas fa-plus-circle me-2"></i>Add New Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body" style="padding:24px;max-height:70vh;overflow-y:auto;">
                    <input type="hidden" name="action" value="create">

                    <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                        <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-info-circle me-1"></i>Basic Information</span>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Title <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="title" class="profile-input" placeholder="e.g. Binalbagan Heritage Walk" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Destination <span style="color:#ef4444;">*</span></label>
                            <select name="destination_id" class="profile-input" required>
                                <option value="">Select Destination</option>
                                <?php foreach ($allDestinations as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= sanitize($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Category</label>
                            <select name="category" class="profile-input">
                                <option value="tourism_event">Tourism Event</option>
                                <option value="community_event">Community Event</option>
                                <option value="cultural_festival">Cultural Festival</option>
                                <option value="nature_tour">Nature Tour</option>
                                <option value="heritage_walk">Heritage Walk</option>
                                <option value="adventure_activity">Adventure Activity</option>
                                <option value="workshop">Workshop</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Description</label>
                            <textarea name="description" class="profile-input" rows="3" style="resize:vertical;" placeholder="Describe the event..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Event Location</label>
                            <input type="text" name="event_location" class="profile-input" placeholder="Specific location within the destination">
                        </div>
                    </div>

                    <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                        <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-calendar-alt me-1"></i>Schedule & Pricing</span>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Start Date <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="event_start_date" class="profile-input" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">End Date</label>
                            <input type="date" name="event_end_date" class="profile-input">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Start Time</label>
                            <input type="time" name="event_start_time" class="profile-input" value="08:00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">End Time</label>
                            <input type="time" name="event_end_time" class="profile-input" value="17:00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Price (₱)</label>
                            <input type="number" name="price" class="profile-input" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Duration (hrs)</label>
                            <input type="number" name="duration_hours" class="profile-input" step="0.5" min="0.5" value="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Status</label>
                            <select name="status" class="profile-input">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                        <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-users me-1"></i>Participants</span>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Participants</label>
                            <input type="number" name="max_participants" class="profile-input" min="1" value="20">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Min Participants</label>
                            <input type="number" name="min_participants" class="profile-input" min="1" value="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Min Age</label>
                            <input type="number" name="min_age" class="profile-input" min="0" value="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Age</label>
                            <input type="number" name="max_age" class="profile-input" min="0" value="" placeholder="No limit">
                        </div>
                    </div>

                    <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                        <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-address-card me-1"></i>Organizer & Details</span>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Organizer</label>
                            <input type="text" name="organizer" class="profile-input" placeholder="e.g. LGU Binalbagan">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Info</label>
                            <input type="text" name="contact_info" class="profile-input" placeholder="e.g. 0917-123-4567">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="requires_guide" value="1" id="createRequiresGuide" checked>
                                <label class="form-check-label fw-semibold small" style="color:var(--text-muted,#64748b);" for="createRequiresGuide">Requires Guide</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Health Restrictions</label>
                            <textarea name="health_restrictions" class="profile-input" rows="2" style="resize:vertical;" placeholder="e.g. Not recommended for persons with heart conditions"></textarea>
                        </div>
                    </div>

                    <div class="mb-2 pb-2" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                        <span class="fw-bold small text-uppercase" style="color:var(--primary,#0c6e5e);letter-spacing:0.5px;"><i class="fas fa-camera me-1"></i>Event Photo</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="drop-zone" onclick="document.getElementById('staffEventImage').click()">
                                <div id="staffEventPlaceholder">
                                    <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem;color:var(--text-muted,#94a3b8);margin-bottom:6px;display:block;"></i>
                                    <div class="small fw-semibold">Click to upload event photo</div>
                                    <div style="font-size:0.75rem;color:var(--text-muted,#94a3b8);">JPG, PNG, WebP — Max 5MB</div>
                                </div>
                                <img id="staffEventPreview" class="d-none rounded" style="max-height:100px;object-fit:cover;" alt="Preview">
                                <input type="file" name="event_image" id="staffEventImage" class="d-none" accept="image/*" onchange="previewStaffEventImage(this)">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px;">
                    <button type="button" class="btn" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-save me-1"></i>Create Event</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
});
function previewStaffEventImage(input) {
    var preview = document.getElementById('staffEventPreview');
    var placeholder = document.getElementById('staffEventPlaceholder');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            placeholder.classList.add('d-none');
        }
        reader.readAsDataURL(input.files[0]);
    }
}
function previewEditStaffEventImage(input, id) {
    var preview = document.getElementById('editStaffEventPreview' + id);
    var placeholder = document.getElementById('editStaffEventPlaceholder' + id);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            placeholder.classList.add('d-none');
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
<?php }); ?>
