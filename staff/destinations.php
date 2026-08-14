<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');

$destination = new Destination();

if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $data = [
            'name'                    => sanitize($_POST['name'] ?? ''),
            'description'             => sanitize($_POST['description'] ?? ''),
            'location'                => sanitize($_POST['location'] ?? ''),
            'category'                => $_POST['category'] ?? 'other',
            'difficulty'              => $_POST['difficulty'] ?? 'easy',
            'entrance_fee'            => (float)($_POST['price'] ?? 0),
            'capacity_limit'          => (int)($_POST['capacity_limit'] ?? 20),
            'max_guests_per_booking'  => (int)($_POST['max_guests_per_booking'] ?? 10),
            'contact_phone'           => sanitize($_POST['contact_phone'] ?? ''),
            'contact_email'           => sanitize($_POST['contact_email'] ?? ''),
            'facilities'              => sanitize($_POST['facilities'] ?? ''),
            'status'                  => $_POST['status'] ?? 'active',
            'created_by'             => $_SESSION['user_id'] ?? null,
        ];

        try {
            if (!empty($data['name'])) {
                $image = $_FILES['image'] ?? null;
                if ($image && $image['error'] === UPLOAD_ERR_OK) {
                    $uploadResult = upload_file($image, 'destinations', ['jpg', 'jpeg', 'png', 'webp']);
                    if ($uploadResult['success']) {
                        $data['image'] = $uploadResult['filename'];
                    }
                }
                $newId = $destination->create($data);
                if ($newId) {
                    flash_message('success', 'Destination created successfully.');
                    redirect('/staff/destinations.php');
                }
            }
        } catch (\Exception $e) {
            error_log('Staff Dest Create Error: ' . $e->getMessage());
        }
        flash_message('error', 'Failed to create destination.');
        redirect('/staff/destinations.php');
    }

    if ($action === 'update') {
        $id = (int)($_POST['destination_id'] ?? 0);
        $data = [
            'name'                    => sanitize($_POST['name'] ?? ''),
            'description'             => sanitize($_POST['description'] ?? ''),
            'location'                => sanitize($_POST['location'] ?? ''),
            'category'                => $_POST['category'] ?? 'other',
            'difficulty'              => $_POST['difficulty'] ?? 'easy',
            'entrance_fee'            => (float)($_POST['price'] ?? 0),
            'capacity_limit'          => (int)($_POST['capacity_limit'] ?? 20),
            'max_guests_per_booking'  => (int)($_POST['max_guests_per_booking'] ?? 10),
            'contact_phone'           => sanitize($_POST['contact_phone'] ?? ''),
            'contact_email'           => sanitize($_POST['contact_email'] ?? ''),
            'facilities'              => sanitize($_POST['facilities'] ?? ''),
            'status'                  => $_POST['status'] ?? 'active',
        ];

        if ($id && !empty($data['name'])) {
            $image = $_FILES['image'] ?? null;
            if ($image && $image['error'] === UPLOAD_ERR_OK) {
                $uploadResult = upload_file($image, 'destinations', ['jpg', 'jpeg', 'png', 'webp']);
                if ($uploadResult['success']) {
                    $data['image'] = $uploadResult['filename'];
                }
            }
            $destination->update($id, $data);
            flash_message('success', 'Destination updated successfully.');
            redirect('/staff/destinations.php');
        }
        flash_message('error', 'Failed to update destination.');
        redirect('/staff/destinations.php');
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['destination_id'] ?? 0);
        $dest = $destination->findById($id);
        if ($dest) {
            $newStatus = $dest['status'] === 'active' ? 'inactive' : 'active';
            $destination->update($id, ['status' => $newStatus]);
            flash_message('success', 'Destination status updated.');
        }
        redirect('/staff/destinations.php');
    }
}

$viewAction = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = [];
if ($search) $filters['search'] = $search;

$destinations = $destination->findAll($filters, $page, 12);

$editDest = null;
if ($viewAction === 'edit' && $editId) {
    $editDest = $destination->findWithSeasons($editId);
}

render_page('staff', 'destinations.php', 'Destination Management', function () use ($destinations, $search, $editDest, $viewAction, $destination) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.section-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;}
.filter-card .form-control{border-radius:10px;border:1px solid var(--border-color,#e2e8f0);padding:10px 14px;font-size:0.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);}
.filter-card .form-control:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.dest-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;transition:all 0.2s;}
.dest-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.08);}
.dest-card .dest-img{height:180px;object-fit:cover;width:100%;}
.dest-card .dest-img-placeholder{height:180px;background:linear-gradient(135deg,rgba(12,110,94,0.08),rgba(26,138,122,0.08));display:flex;align-items:center;justify-content:center;}
.dest-card .dest-body{padding:16px;}
.dest-card .dest-footer{padding:12px 16px;border-top:1px solid var(--border-color,#f1f5f9);display:flex;gap:8px;}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:0.78rem;font-weight:600;}
.status-chip.active{background:rgba(34,197,94,0.12);color:#16a34a;}
.status-chip.inactive{background:rgba(100,116,139,0.12);color:#475569;}
.difficulty-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:8px;font-size:0.75rem;font-weight:600;background:rgba(59,130,246,0.1);color:#3b82f6;}
.action-btn{height:32px;border-radius:8px;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;font-size:0.8rem;padding:0 12px;gap:4px;}
.action-btn:hover{border-color:var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.05);}
.action-btn.warning:hover{border-color:#d97706;color:#d97706;background:rgba(217,119,6,0.05);}
.action-btn.success:hover{border-color:#16a34a;color:#16a34a;background:rgba(22,163,74,0.05);}
.profile-input{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 14px;color:var(--text-primary,#1e293b);width:100%;font-size:0.9rem;transition:all 0.2s;}
.profile-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.1);}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.drop-zone{border:2px dashed var(--border-color,#cbd5e1);border-radius:12px;padding:24px 16px;text-align:center;cursor:pointer;transition:all 0.2s;background:var(--bg-secondary,#f8fafc);}
.drop-zone:hover{border-color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.03);}
.pagination .page-item .page-link{border-radius:8px;margin:0 3px;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);font-size:0.85rem;padding:6px 12px;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-item .page-link:hover:not(.active){background:rgba(12,110,94,0.05);color:var(--primary,#0c6e5e);}
</style>

<?php if ($viewAction === 'edit' && $editDest): ?>
<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-edit me-2"></i>Edit Destination: <?= sanitize($editDest['name']) ?></h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Update destination details and seasonal information</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <a href="destinations.php" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;padding:8px 20px;border:none;"><i class="fas fa-arrow-left me-1"></i>Back to Destinations</a>
        </div>
    </div>
</div>

<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="destination_id" value="<?= $editDest['id'] ?>">

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-circle-info" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i>
                    </div>
                    <h6>Basic Information</h6>
                </div>
                <div style="padding:20px;">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="name" class="profile-input" value="<?= sanitize($editDest['name']) ?>" required placeholder="Enter destination name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Category</label>
                            <select name="category" class="profile-input">
                                <?php foreach ($destination->getCategories() as $ck => $cv): ?>
                                    <option value="<?= $ck ?>" <?= ($editDest['category'] ?? '') === $ck ? 'selected' : '' ?>><?= $cv ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Location</label>
                            <input type="text" name="location" class="profile-input" value="<?= sanitize($editDest['location'] ?? '') ?>" placeholder="Enter full address">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Description</label>
                            <textarea name="description" class="profile-input" rows="4" style="resize:vertical;" placeholder="Describe this destination..."><?= sanitize($editDest['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-map-marker-alt" style="color:#3b82f6;font-size:0.7rem;"></i>
                    </div>
                    <h6>Contact & Location</h6>
                </div>
                <div style="padding:20px;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Phone</label>
                            <div class="profile-input" style="padding-left:14px;">
                                <i class="fas fa-phone" style="color:var(--text-muted,#94a3b8);font-size:.82rem;margin-right:8px;"></i>
                                <input type="text" name="contact_phone" value="<?= sanitize($editDest['contact_phone'] ?? '') ?>" placeholder="09XX XXX XXXX" style="border:none;background:transparent;outline:none;width:calc(100% - 24px);color:var(--text-primary,#1e293b);font-size:.9rem;">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Email</label>
                            <div class="profile-input" style="padding-left:14px;">
                                <i class="fas fa-envelope" style="color:var(--text-muted,#94a3b8);font-size:.82rem;margin-right:8px;"></i>
                                <input type="email" name="contact_email" value="<?= sanitize($editDest['contact_email'] ?? '') ?>" placeholder="email@example.com" style="border:none;background:transparent;outline:none;width:calc(100% - 24px);color:var(--text-primary,#1e293b);font-size:.9rem;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Latitude</label>
                            <input type="text" name="latitude" class="profile-input" value="<?= sanitize($editDest['latitude'] ?? '') ?>" placeholder="e.g., 10.1234">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Longitude</label>
                            <input type="text" name="longitude" class="profile-input" value="<?= sanitize($editDest['longitude'] ?? '') ?>" placeholder="e.g., 122.5678">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Status</label>
                            <select name="status" class="profile-input">
                                <option value="active" <?= ($editDest['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($editDest['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-clock" style="color:#f59e0b;font-size:0.7rem;"></i>
                    </div>
                    <h6>Operating Hours</h6>
                </div>
                <div style="padding:20px;">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Opens</label>
                            <input type="time" name="operating_hours_open" class="profile-input" value="<?= $editDest['operating_hours_open'] ?? '' ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Closes</label>
                            <input type="time" name="operating_hours_close" class="profile-input" value="<?= $editDest['operating_hours_close'] ?? '' ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(16,185,129,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-list" style="color:#10b981;font-size:0.7rem;"></i>
                    </div>
                    <h6>Facilities & Rules</h6>
                </div>
                <div style="padding:20px;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Facilities</label>
                            <textarea name="facilities" class="profile-input" rows="4" style="resize:vertical;" placeholder="e.g., Parking, Restrooms, Canteen, Wifi..."><?= sanitize($editDest['facilities'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Rules & Regulations</label>
                            <textarea name="rules_regulations" class="profile-input" rows="4" style="resize:vertical;" placeholder="e.g., No littering, No swimming beyond marker..."><?= sanitize($editDest['rules_regulations'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(139,92,246,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-image" style="color:#8b5cf6;font-size:0.7rem;"></i>
                    </div>
                    <h6>Cover Image</h6>
                </div>
                <div style="padding:20px;">
                    <?php if (!empty($editDest['image'])): ?>
                        <div class="mb-3 text-center">
                            <img src="<?= dest_image_url($editDest['image']) ?>" class="rounded" style="max-height:140px;width:100%;object-fit:cover;border:1px solid var(--border-color,#e2e8f0);" alt="">
                        </div>
                    <?php endif; ?>
                    <input type="hidden" name="existing_image" value="<?= sanitize($editDest['image'] ?? '') ?>">
                    <input type="file" name="image" class="profile-input" accept="image/*">
                    <small class="text-muted mt-1 d-block" style="font-size:.72rem;">Recommended: 800x400px, JPG/PNG</small>

                    <?php $gallery = $editDest['gallery_images'] ? json_decode($editDest['gallery_images'], true) : []; ?>
                    <?php if (!empty($gallery)): ?>
                        <div class="mt-3">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Gallery</label>
                            <div class="d-flex flex-wrap gap-1"><?php foreach ($gallery as $gi): ?><img src="<?= dest_image_url($gi) ?>" class="rounded" style="max-height:48px;width:auto;border:1px solid var(--border-color,#e2e8f0);" alt=""><?php endforeach; ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="mt-2">
                        <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Add Gallery Images</label>
                        <input type="file" name="gallery[]" class="profile-input" accept="image/*" multiple>
                    </div>
                </div>
            </div>

            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(239,68,68,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-tag" style="color:#ef4444;font-size:0.7rem;"></i>
                    </div>
                    <h6>Pricing</h6>
                </div>
                <div style="padding:20px;">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Entrance Fee (₱)</label>
                        <input type="number" name="price" class="profile-input" step="0.01" min="0" value="<?= $editDest['entrance_fee'] ?? 0 ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Package Price (₱) <small class="text-muted">(optional)</small></label>
                        <input type="number" name="package_price" class="profile-input" step="0.01" min="0" value="<?= $editDest['package_price'] ?? '' ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Booking Price/Person (₱)</label>
                        <input type="number" name="booking_price" class="profile-input" step="0.01" min="0" value="<?= $editDest['booking_price'] ?? 0 ?>">
                    </div>
                </div>
            </div>

            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-users" style="color:#3b82f6;font-size:0.7rem;"></i>
                    </div>
                    <h6>Visitor Settings</h6>
                </div>
                <div style="padding:20px;">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max/Day</label>
                            <input type="number" name="capacity_limit" class="profile-input" min="1" value="<?= $editDest['capacity_limit'] ?? 20 ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max/Booking</label>
                            <input type="number" name="max_guests_per_booking" class="profile-input" min="1" value="<?= $editDest['max_guests_per_booking'] ?? 10 ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Difficulty</label>
                            <select name="difficulty" class="profile-input">
                                <?php foreach (['easy' => 'Easy', 'moderate' => 'Moderate', 'difficult' => 'Difficult', 'extreme' => 'Extreme'] as $dk => $dv): ?>
                                    <option value="<?= $dk ?>" <?= ($editDest['difficulty'] ?? '') === $dk ? 'selected' : '' ?>><?= $dv ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-card mb-4">
                <div class="section-header">
                    <div style="width:28px;height:28px;border-radius:6px;background:rgba(16,185,129,0.12);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-toggle-on" style="color:#10b981;font-size:0.7rem;"></i>
                    </div>
                    <h6>Booking Settings</h6>
                </div>
                <div style="padding:20px;">
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="booking_enabled" class="form-check-input" id="editBookingEnabled" <?= $editDest['booking_enabled'] ? 'checked' : '' ?> style="cursor:pointer;">
                        <label class="form-check-label fw-semibold small" for="editBookingEnabled" style="font-size:.85rem;">Enable Online Booking</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="guide_required" class="form-check-input" id="editGuideRequired" <?= $editDest['guide_required'] ? 'checked' : '' ?> style="cursor:pointer;">
                        <label class="form-check-label fw-semibold small" for="editGuideRequired" style="font-size:.85rem;">Require Tour Guide</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="featured" class="form-check-input" id="editFeatured" <?= $editDest['featured'] ? 'checked' : '' ?> style="cursor:pointer;">
                        <label class="form-check-label fw-semibold small" for="editFeatured" style="font-size:.85rem;">Featured Destination</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-2 mb-4">
        <button type="submit" class="btn-brand"><i class="fas fa-save me-1"></i>Update Destination</button>
        <a href="destinations.php" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 24px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);">Cancel</a>
    </div>
</form>

<?php if (!empty($editDest['seasons'])): ?>
<div class="section-card">
    <div class="section-header">
        <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-cloud-sun" style="color:#3b82f6;font-size:0.7rem;"></i>
        </div>
        <h6>Seasonal Information</h6>
    </div>
    <div class="table-responsive">
        <table class="table align-middle" style="margin-bottom:0;">
            <thead>
                <tr>
                    <th>Season</th>
                    <th>Months</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($editDest['seasons'] as $season): ?>
                <tr>
                    <td class="fw-semibold"><?= sanitize($season['season_name'] ?? 'N/A') ?></td>
                    <td><?= sanitize($season['months'] ?? '') ?></td>
                    <td><?= sanitize($season['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-map-marked-alt me-2"></i>Destination Management</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Manage tourist destinations, trails, and points of interest</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <button type="button" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;padding:8px 20px;border:none;" data-bs-toggle="modal" data-bs-target="#createModal">
                <i class="fas fa-plus me-1"></i>Add Destination
            </button>
        </div>
    </div>
</div>

<div class="filter-card mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-8">
            <input type="text" name="search" class="form-control" placeholder="Search destinations by name or location..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm" style="background:var(--primary,#0c6e5e);color:#fff;border-radius:8px;padding:8px 16px;"><i class="fas fa-search me-1"></i>Search</button>
            <a href="destinations.php" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:8px;padding:8px 16px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);">Reset</a>
        </div>
    </form>
</div>

<div class="row g-3">
    <?php if (empty($destinations['data'])): ?>
        <div class="col-12">
            <div class="empty-state">
                <div class="empty-illustration">
                    <i class="fas fa-map-marked-alt"></i>
                    <span class="empty-ring"></span>
                </div>
                <div class="empty-title">No Destinations Found</div>
                <p class="empty-text"><?= $search ? 'No destinations match your search "' . sanitize($search) . '".' : 'Add your first destination to start showcasing tours and attractions.' ?></p>
                <div class="empty-actions">
                    <?php if ($search): ?>
                        <a href="destinations.php" class="btn-cta ghost"><i class="fas fa-redo me-1"></i>Reset Search</a>
                    <?php else: ?>
                        <button type="button" class="btn-cta" data-bs-toggle="modal" data-bs-target="#createModal"><i class="fas fa-plus me-1"></i>Add Destination</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($destinations['data'] as $d): ?>
        <div class="col-md-6 col-xl-4">
            <div class="dest-card h-100">
                <?php if (!empty($d['image'])): ?>
                    <img src="<?= dest_image_url($d['image']) ?>" class="dest-img" alt="<?= sanitize($d['name']) ?>">
                <?php else: ?>
                    <div class="dest-img-placeholder">
                        <i class="fas fa-image" style="font-size:2rem;color:var(--text-muted,#94a3b8);"></i>
                    </div>
                <?php endif; ?>
                <div class="dest-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-bold mb-0" style="font-size:0.95rem;"><?= sanitize($d['name']) ?></h6>
                        <span class="status-chip <?= ($d['status'] ?? '') === 'active' ? 'active' : 'inactive' ?>"><?= ucfirst($d['status'] ?? '') ?></span>
                    </div>
                    <p class="text-muted small mb-2"><i class="fas fa-map-marker-alt me-1"></i><?= sanitize($d['location'] ?? 'N/A') ?></p>
                    <p class="small mb-2" style="color:var(--text-muted,#64748b);"><?= sanitize(truncate($d['description'] ?? '', 100)) ?></p>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="difficulty-badge"><i class="fas fa-signal"></i><?= ucfirst($d['difficulty'] ?? 'easy') ?></span>
                        <span class="difficulty-badge" style="background:rgba(12,110,94,0.1);color:var(--primary,#0c6e5e);">₱<?= number_format($d['price'] ?? 0, 2) ?></span>
                        <span class="difficulty-badge" style="background:rgba(245,158,11,0.1);color:#d97706;"><i class="fas fa-users"></i><?= $d['capacity_limit'] ?? 20 ?></span>
                    </div>
                </div>
                <div class="dest-footer">
                    <button type="button" class="action-btn" data-bs-toggle="modal" data-bs-target="#editModal<?= $d['id'] ?>"><i class="fas fa-edit"></i>Edit</button>
                    <button type="button" class="action-btn <?= ($d['status'] ?? '') === 'active' ? 'warning' : 'success' ?>" data-bs-toggle="modal" data-bs-target="#toggleStatusModal" data-dest-id="<?= $d['id'] ?>" data-dest-name="<?= sanitize($d['name']) ?>" data-dest-status="<?= ($d['status'] ?? '') === 'active' ? 'active' : 'inactive' ?>">
                        <i class="fas <?= ($d['status'] ?? '') === 'active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i><?= ($d['status'] ?? '') === 'active' ? 'Deactivate' : 'Activate' ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editModal<?= $d['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);">
                    <div class="modal-header" style="border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;">
                        <h5 class="modal-title" style="font-weight:700;color:#fff;"><i class="fas fa-edit me-2"></i>Edit Destination</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="destination_id" value="<?= $d['id'] ?>">
                        <div class="modal-body" style="padding:24px;">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Name <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="name" class="profile-input" value="<?= sanitize($d['name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Location</label>
                                    <input type="text" name="location" class="profile-input" value="<?= sanitize($d['location'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Description</label>
                                    <textarea name="description" class="profile-input" rows="3" style="resize:vertical;"><?= sanitize($d['description'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Category</label>
                                    <select name="category" class="profile-input">
                                        <?php foreach ($destination->getCategories() as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= ($d['category'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Difficulty</label>
                                    <select name="difficulty" class="profile-input">
                                        <option value="easy" <?= ($d['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Easy</option>
                                        <option value="moderate" <?= ($d['difficulty'] ?? '') === 'moderate' ? 'selected' : '' ?>>Moderate</option>
                                        <option value="difficult" <?= ($d['difficulty'] ?? '') === 'difficult' ? 'selected' : '' ?>>Difficult</option>
                                        <option value="extreme" <?= ($d['difficulty'] ?? '') === 'extreme' ? 'selected' : '' ?>>Extreme</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Status</label>
                                    <select name="status" class="profile-input">
                                        <option value="active" <?= ($d['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= ($d['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                        <option value="maintenance" <?= ($d['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Entrance Fee (₱)</label>
                                    <input type="number" name="price" class="profile-input" step="0.01" min="0" value="<?= $d['entrance_fee'] ?? $d['price'] ?? 0 ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Participants</label>
                                    <input type="number" name="capacity_limit" class="profile-input" min="1" value="<?= $d['capacity_limit'] ?? 20 ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Guests/Booking</label>
                                    <input type="number" name="max_guests_per_booking" class="profile-input" min="1" value="<?= $d['max_guests_per_booking'] ?? 10 ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Phone</label>
                                    <input type="text" name="contact_phone" class="profile-input" value="<?= sanitize($d['contact_phone'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Email</label>
                                    <input type="email" name="contact_email" class="profile-input" value="<?= sanitize($d['contact_email'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Facilities</label>
                                    <textarea name="facilities" class="profile-input" rows="2" style="resize:vertical;"><?= sanitize($d['facilities'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Photo</label>
                                    <div class="drop-zone" onclick="document.getElementById('editStaffDestImage<?= $d['id'] ?>').click()">
                                        <div id="editStaffDestPlaceholder<?= $d['id'] ?>">
                                            <?php if (!empty($d['image'])): ?>
                                                <img id="editStaffDestPreview<?= $d['id'] ?>" class="rounded" style="max-height:120px;object-fit:cover;" alt="Preview" src="<?= dest_image_url($d['image']) ?>">
                                            <?php else: ?>
                                                <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem;color:var(--text-muted,#94a3b8);margin-bottom:6px;display:block;"></i>
                                                <div class="small fw-semibold">Click to change photo</div>
                                                <div style="font-size:0.75rem;color:var(--text-muted,#94a3b8);">JPG, PNG, WebP — Max 5MB</div>
                                                <img id="editStaffDestPreview<?= $d['id'] ?>" class="d-none rounded" style="max-height:120px;object-fit:cover;" alt="Preview">
                                            <?php endif; ?>
                                        </div>
                                        <input type="file" name="image" id="editStaffDestImage<?= $d['id'] ?>" class="d-none" accept="image/*" onchange="previewEditStaffDestImage(this, <?= $d['id'] ?>)">
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
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($destinations['pages'] > 1): ?>
<div class="d-flex justify-content-center mt-4">
    <nav>
        <ul class="pagination mb-0">
            <?php if ($destinations['page'] > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $destinations['page'] - 1 ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-left"></i></a></li>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $destinations['pages']; $i++): ?>
                <li class="page-item <?= $i == $destinations['page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <?php if ($destinations['page'] < $destinations['pages']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $destinations['page'] + 1 ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-right"></i></a></li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);">
            <div class="modal-header" style="border-bottom:1px solid var(--border-color,#f1f5f9);padding:18px 24px;background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;">
                <h5 class="modal-title" style="font-weight:700;color:#fff;"><i class="fas fa-plus-circle me-2"></i>Add New Destination</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create">
                <div class="modal-body" style="padding:24px;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="name" class="profile-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Location</label>
                            <input type="text" name="location" class="profile-input">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Description</label>
                            <textarea name="description" class="profile-input" rows="3" style="resize:vertical;"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Category <span style="color:#ef4444;">*</span></label>
                            <select name="category" class="profile-input" required>
                                <?php foreach ($destination->getCategories() as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Difficulty</label>
                            <select name="difficulty" class="profile-input">
                                <option value="easy">Easy</option>
                                <option value="moderate">Moderate</option>
                                <option value="difficult">Difficult</option>
                                <option value="extreme">Extreme</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Status</label>
                            <select name="status" class="profile-input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Entrance Fee (₱)</label>
                            <input type="number" name="price" class="profile-input" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Participants</label>
                            <input type="number" name="capacity_limit" class="profile-input" min="1" value="20">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Max Guests/Booking</label>
                            <input type="number" name="max_guests_per_booking" class="profile-input" min="1" value="10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Phone</label>
                            <input type="text" name="contact_phone" class="profile-input">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Contact Email</label>
                            <input type="email" name="contact_email" class="profile-input">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Facilities</label>
                            <textarea name="facilities" class="profile-input" rows="2" style="resize:vertical;" placeholder="e.g. Restrooms, Parking, Canteen"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Photo</label>
                            <div class="drop-zone" onclick="document.getElementById('staffDestImage').click()">
                                <div id="staffDestPlaceholder">
                                    <i class="fas fa-cloud-upload-alt" style="font-size:1.5rem;color:var(--text-muted,#94a3b8);margin-bottom:6px;display:block;"></i>
                                    <div class="small fw-semibold">Click to upload a photo</div>
                                    <div style="font-size:0.75rem;color:var(--text-muted,#94a3b8);">JPG, PNG, WebP — Max 5MB</div>
                                </div>
                                <img id="staffDestPreview" class="d-none rounded" style="max-height:120px;object-fit:cover;" alt="Preview">
                                <input type="file" name="image" id="staffDestImage" class="d-none" accept="image/*" onchange="previewStaffDestImage(this)">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border-color,#f1f5f9);padding:16px 24px;">
                    <button type="button" class="btn" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#475569);" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-save me-1"></i>Create Destination</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="toggleStatusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;background:var(--card-bg,#fff);">
            <div class="modal-header" style="border:none;padding:24px 24px 0;text-align:center;flex-direction:column;align-items:center;">
                <div id="toggleStatusIcon" style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:12px;"></div>
                <h5 class="modal-title fw-bold" style="font-size:1.05rem;color:var(--text-primary,#1e293b);" id="toggleStatusTitle">Toggle Status</h5>
                <button type="button" class="btn-close position-absolute" style="top:12px;right:12px;" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" style="padding:8px 24px 0;">
                <p class="mb-0" style="color:var(--text-muted,#64748b);font-size:0.9rem;" id="toggleStatusMessage"></p>
            </div>
            <div class="modal-footer" style="border-top:none;padding:16px 24px 24px;justify-content:center;gap:10px;">
                <button type="button" class="btn" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 28px;color:var(--text-primary,#475569);background:var(--card-bg,#fff);font-weight:500;" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn" id="toggleStatusConfirmBtn" style="border-radius:10px;padding:10px 28px;font-weight:600;border:none;color:#fff;">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggleModal = document.getElementById('toggleStatusModal');
    if (toggleModal) {
        toggleModal.addEventListener('show.bs.modal', function(event) {
            var btn = event.relatedTarget;
            if (!btn) return;
            var destId = btn.getAttribute('data-dest-id');
            var destName = btn.getAttribute('data-dest-name');
            var destStatus = btn.getAttribute('data-dest-status');
            var isActivating = destStatus === 'inactive';

            var iconWrap = document.getElementById('toggleStatusIcon');
            iconWrap.style.background = isActivating ? 'rgba(34,197,94,0.12)' : 'rgba(245,158,11,0.12)';
            iconWrap.innerHTML = '<i class="fas ' + (isActivating ? 'fa-toggle-on' : 'fa-toggle-off') + '" style="font-size:1.3rem;color:' + (isActivating ? '#16a34a' : '#d97706') + ';"></i>';

            document.getElementById('toggleStatusTitle').textContent = isActivating ? 'Activate Destination' : 'Deactivate Destination';
            document.getElementById('toggleStatusMessage').textContent = 'Are you sure you want to ' + (isActivating ? 'activate' : 'deactivate') + ' "' + destName + '"? This will ' + (isActivating ? 'make it visible to tourists' : 'hide it from tourists') + '.';

            var confirmBtn = document.getElementById('toggleStatusConfirmBtn');
            confirmBtn.style.background = isActivating ? 'linear-gradient(135deg,#059669,#10b981)' : 'linear-gradient(135deg,#d97706,#f59e0b)';
            confirmBtn.textContent = isActivating ? 'Yes, Activate' : 'Yes, Deactivate';
            confirmBtn.onclick = function() {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="toggle_status"><input type="hidden" name="destination_id" value="' + destId + '">';
                document.body.appendChild(form);
                form.submit();
            };
        });
    }
});
function previewStaffDestImage(input) {
    var preview = document.getElementById('staffDestPreview');
    var placeholder = document.getElementById('staffDestPlaceholder');
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
function previewEditStaffDestImage(input, id) {
    var preview = document.getElementById('editStaffDestPreview' + id);
    var placeholder = document.getElementById('editStaffDestPlaceholder' + id);
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
