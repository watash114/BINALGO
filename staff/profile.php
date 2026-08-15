<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

if (is_post() && !empty($_FILES['avatar']['name']) && verify_token($_POST['csrf_token'] ?? null)) {
    $upload = upload_file($_FILES['avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($upload['success']) {
        $db->prepare("UPDATE users SET avatar = :avatar WHERE id = :uid")
            ->execute([':avatar' => 'avatars/' . $upload['filename'], ':uid' => $user_id]);
        flash_message('success', 'Profile photo updated successfully.');
    } else {
        flash_message('error', $upload['message'] ?? 'Could not upload photo.');
    }
    redirect('/staff/profile.php');
}

if (is_post() && verify_token($_POST['csrf_token'] ?? null)) {
    $name = sanitize(trim($_POST['name'] ?? ''));
    $phone = sanitize(trim($_POST['phone'] ?? ''));
    $email = sanitize(trim($_POST['email'] ?? ''));

    if (empty($name) || empty($email)) {
        $error = 'Name and email are required.';
    } else {
        $existingEmail = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
        $existingEmail->execute([':email' => $email, ':id' => $user_id]);
        if ($existingEmail->fetch()) {
            $error = 'Email address is already in use.';
        } else {
            $stmt = $db->prepare("UPDATE users SET name = :name, phone = :phone, email = :email, updated_at = db_now() WHERE id = :id");
            $stmt->execute([':name' => $name, ':phone' => $phone, ':email' => $email, ':id' => $user_id]);

            if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $upload = upload_file($_FILES['avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                if ($upload['success']) {
                    $db->prepare("UPDATE users SET avatar = :avatar WHERE id = :id")->execute([':avatar' => 'avatars/' . $upload['filename'], ':id' => $user_id]);
                }
            }

            if (!empty($_POST['new_password']) && strlen($_POST['new_password']) >= 8) {
                $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET password = :pw WHERE id = :id")->execute([':pw' => $hashed, ':id' => $user_id]);
            }

            $success = 'Profile updated successfully.';
            $_SESSION['name'] = $name;
            $user = current_user();
        }
    }
}

if (is_post() && isset($_POST['toggle_status']) && verify_token($_POST['csrf_token'] ?? null)) {
    $new_status = $user['status'] === 'active' ? 'inactive' : 'active';
    $db->prepare("UPDATE users SET status = :status WHERE id = :uid")
        ->execute([':status' => $new_status, ':uid' => $user_id]);
    $user = current_user();
    $success = 'Account ' . ($new_status === 'active' ? 'activated' : 'deactivated') . ' successfully.';
}

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM activity_logs WHERE user_id = :uid");
$stmt->execute([':uid' => $user_id]);
$totalActivity = (int) $stmt->fetch()['cnt'];

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM activity_logs WHERE user_id = :uid AND created_at >= db_date_sub(, 'INTERVAL  ')");
$stmt->execute([':uid' => $user_id]);
$weekActivity = (int) $stmt->fetch()['cnt'];

$fields_filled = 0;
$total_fields = 4;
if (!empty($user['name'])) $fields_filled++;
if (!empty($user['phone'])) $fields_filled++;
if (!empty($user['email'])) $fields_filled++;
if (!empty($user['avatar'])) $fields_filled++;
$completion_pct = round(($fields_filled / $total_fields) * 100);

render_page('staff', 'profile.php', 'My Profile', function () use ($user, $success, $error, $totalActivity, $weekActivity, $completion_pct) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.profile-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.profile-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:10px;}
.profile-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#1e293b);}
.profile-input{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:10px 14px;color:var(--text-primary,#1e293b);width:100%;font-size:0.9rem;transition:all 0.2s;}
.profile-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.1);}
.profile-input:disabled{opacity:0.5;cursor:not-allowed;background:var(--bg-secondary,#f8fafc);}
.profile-avatar-wrap{width:130px;height:130px;border-radius:50%;overflow:hidden;border:4px solid var(--border-color,#e2e8f0);margin:0 auto 16px;position:relative;transition:border-color 0.3s;}
.profile-avatar-wrap:hover{border-color:var(--primary,#0c6e5e);}
.profile-avatar-wrap img{width:100%;height:100%;object-fit:cover;}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;font-size:0.78rem;font-weight:600;cursor:pointer;border:none;transition:all 0.2s;}
.status-badge.active{background:rgba(34,197,94,0.15);color:#22c55e;}
.status-badge.inactive{background:rgba(239,68,68,0.15);color:#ef4444;}
.preview-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color,#f1f5f9);}
.preview-item:last-child{border-bottom:none;}
.preview-item .label{font-weight:600;font-size:0.85rem;color:var(--text-primary,#1e293b);}
.preview-item .value{font-size:0.85rem;color:var(--text-muted,#64748b);}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
</style>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:12px;">
        <i class="fas fa-check-circle me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:12px;">
        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="staff-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-user-tie me-2"></i>My Profile</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Manage your staff profile and account settings</p>
        </div>
        <div class="col-md-4 text-md-end position-relative" style="z-index:1;">
            <span style="font-size:2.5rem;font-weight:800;"><?= $completion_pct ?>%</span>
            <div class="opacity-75" style="font-size:0.8rem;">Profile Complete</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="profile-card mb-4">
            <div class="text-center" style="padding:28px 20px;">
                <div class="position-relative d-inline-block mb-3">
                    <div class="profile-avatar-wrap" onclick="document.getElementById('staffAvatarInput').click();">
                        <img src="<?= get_avatar_url($user) ?>" id="staffAvatarImg" alt="<?= sanitize($user['name']) ?>">
                    </div>
                    <label for="staffAvatarInput" style="width:36px;height:36px;border-radius:50%;background:#0c6e5e;color:#fff;display:flex;align-items:center;justify-content:center;position:absolute;bottom:8px;right:-4px;cursor:pointer;border:3px solid var(--card-bg,#fff);box-shadow:0 2px 8px rgba(0,0,0,0.3);z-index:2;">
                        <i class="fas fa-camera" style="font-size:0.85rem;"></i>
                    </label>
                    <form method="POST" enctype="multipart/form-data" id="staffAvatarForm">
                        <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                        <input type="file" name="avatar" id="staffAvatarInput" class="d-none" accept="image/*">
                    </form>
                </div>
                <h5 class="fw-bold mb-1" style="color:var(--text-primary,#1e293b);"><?= sanitize($user['name']) ?></h5>
                <p style="font-size:0.85rem;color:var(--text-muted,#64748b);margin-bottom:12px;"><?= sanitize($user['email']) ?></p>

                <div style="width:100%;height:8px;background:var(--bg-secondary,#f1f5f9);border-radius:4px;overflow:hidden;margin-bottom:8px;">
                    <div style="width:<?= $completion_pct ?>%;height:100%;background:linear-gradient(90deg,#0c6e5e,#1a8a7a);border-radius:4px;transition:width 0.3s;"></div>
                </div>
                <small style="font-size:0.78rem;color:var(--text-muted,#64748b);">Profile Completion: <?= $completion_pct ?>%</small>

                <div style="margin-top:16px;">
                    <?php if ($user['status'] === 'active'): ?>
                        <form method="POST" onsubmit="return confirm('Deactivate your account?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="toggle_status" value="1">
                            <button type="submit" class="status-badge active">
                                <i class="fas fa-check-circle"></i>Active — Click to Deactivate
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Reactivate your account?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="toggle_status" value="1">
                            <button type="submit" class="status-badge inactive">
                                <i class="fas fa-pause-circle"></i>Inactive — Click to Activate
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-card">
            <div class="section-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-chart-bar" style="color:#3b82f6;font-size:0.7rem;"></i>
                </div>
                <h6>Activity Summary</h6>
            </div>
            <div style="padding:16px 20px;">
                <div class="preview-item">
                    <span class="label">Total Actions</span>
                    <span class="value fw-bold" style="color:#3b82f6;"><?= $totalActivity ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">This Week</span>
                    <span class="value fw-bold" style="color:#22c55e;"><?= $weekActivity ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Member Since</span>
                    <span class="value"><?= format_date($user['created_at']) ?></span>
                </div>
                <div class="preview-item">
                    <span class="label">Last Login</span>
                    <span class="value"><?= !empty($user['last_login_at']) ? format_date($user['last_login_at']) : 'N/A' ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="profile-card mb-4">
            <div class="section-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-user-edit" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i>
                </div>
                <h6>Edit Profile</h6>
            </div>
            <div style="padding:20px;">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Full Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" class="profile-input" name="name" value="<?= sanitize($user['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Email <span style="color:#ef4444;">*</span></label>
                            <input type="email" class="profile-input" name="email" value="<?= sanitize($user['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Phone</label>
                            <input type="text" class="profile-input" name="phone" value="<?= sanitize($user['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">Role</label>
                            <input type="text" class="profile-input" value="<?= ucfirst($user['role']) ?>" disabled>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#64748b);">New Password <small>(leave blank to keep current)</small></label>
                            <input type="password" class="profile-input" name="new_password" minlength="8" placeholder="Min 8 characters">
                        </div>
                    </div>
                    <div style="margin-top:20px;">
                        <button type="submit" class="btn-brand"><i class="fas fa-save me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="profile-card">
            <div class="section-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-eye" style="color:#3b82f6;font-size:0.7rem;"></i>
                </div>
                <h6>Account Preview</h6>
            </div>
            <div style="padding:16px 20px;">
                <div class="preview-item"><span class="label">Name</span><span class="value"><?= sanitize($user['name']) ?></span></div>
                <div class="preview-item"><span class="label">Email</span><span class="value"><?= sanitize($user['email']) ?></span></div>
                <div class="preview-item"><span class="label">Phone</span><span class="value"><?= sanitize($user['phone'] ?? 'Not set') ?></span></div>
                <div class="preview-item"><span class="label">Role</span><span class="value"><?= ucfirst($user['role']) ?></span></div>
                <div class="preview-item"><span class="label">Status</span><span class="value"><?= ucfirst($user['status']) ?></span></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="avatarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="border-bottom:1px solid var(--border-color,#f1f5f9);">
                <h6 class="modal-title fw-bold" style="color:var(--text-primary,#1e293b);"><i class="fas fa-camera me-2" style="color:var(--primary,#0c6e5e);"></i>Update Profile Photo</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background:var(--bg-secondary,#f8fafc);">
                <div class="avatar-crop-preview">
                    <img src="" id="avatarModalPreview" alt="Preview">
                </div>
                <div class="avatar-zoom-row">
                    <i class="fas fa-magnifying-glass-minus"></i>
                    <input type="range" id="avatarZoom" min="1" max="3" step="0.05" value="1">
                    <i class="fas fa-magnifying-glass-plus"></i>
                    <span class="avatar-zoom-value" id="avatarZoomValue">1.0x</span>
                </div>
                <label class="btn btn-sm w-100 mb-2" style="background:var(--primary,#0c6e5e);color:#fff;border-radius:10px;" for="staffAvatarModalInput">
                    <i class="fas fa-images me-1"></i>Choose a different image
                </label>
                <input type="file" id="staffAvatarModalInput" class="d-none" accept="image/*">
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border-color,#f1f5f9);">
                <button type="button" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:10px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm" id="avatarModalSave" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;"><i class="fas fa-upload me-1"></i>Upload Photo</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('staffAvatarInput');
    var modalInput = document.getElementById('staffAvatarModalInput');
    var preview = document.getElementById('avatarModalPreview');
    var zoom = document.getElementById('avatarZoom');
    var zoomValue = document.getElementById('avatarZoomValue');
    var modal = new bootstrap.Modal(document.getElementById('avatarModal'));

    function applyZoom() {
        preview.style.transform = 'scale(' + zoom.value + ')';
        zoomValue.textContent = Number(zoom.value).toFixed(1) + 'x';
    }

    function readFile(file, cb) {
        if (!file) return;
        if (!file.type.startsWith('image/')) { alert('Please select an image file.'); return; }
        if (file.size > 5 * 1024 * 1024) { alert('Image must be under 5MB.'); return; }
        var reader = new FileReader();
        reader.onload = function (ev) { cb(ev.target.result); };
        reader.readAsDataURL(file);
    }

    input.addEventListener('change', function (e) {
        var file = e.target.files[0];
        readFile(file, function (src) {
            zoom.value = 1;
            preview.setAttribute('src', src);
            applyZoom();
            var dt = new DataTransfer();
            dt.items.add(file);
            modalInput.files = dt.files;
            modal.show();
        });
        input.value = '';
    });

    modalInput.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        readFile(file, function (src) {
            zoom.value = 1;
            preview.setAttribute('src', src);
            applyZoom();
        });
    });

    zoom.addEventListener('input', applyZoom);

    document.getElementById('avatarModalSave').addEventListener('click', function () {
        if (!modalInput.files[0]) {
            alert('Please choose an image first.');
            return;
        }
        var form = document.getElementById('staffAvatarForm');
        var dt = new DataTransfer();
        dt.items.add(modalInput.files[0]);
        document.getElementById('staffAvatarInput').files = dt.files;
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading...';
        form.submit();
    });
})();
</script>
<?php }); ?>
