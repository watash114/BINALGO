<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('guide');

$user = current_user();
$db = Database::getInstance()->getConnection();
$guide_id = $user['id'];

$success_msg = '';
$error_msg = '';

if (is_post() && verify_token($_POST['csrf_token'] ?? null) && isset($_POST['name'])) {
    $name = sanitize($_POST['name'] ?? $user['name']);
    $phone = sanitize($_POST['phone'] ?? '');
    $bio = sanitize($_POST['bio'] ?? '');
    $languages = sanitize($_POST['languages'] ?? '');
    $specializations = sanitize($_POST['specializations'] ?? '');
    $years_of_experience = (int) ($_POST['years_of_experience'] ?? 0);
    $availability_status = $_POST['availability_status'] ?? 'available';

    $valid_availability = ['available', 'on_tour', 'off_duty', 'on_leave'];
    if (!in_array($availability_status, $valid_availability)) {
        $availability_status = 'available';
    }

    $stmt = $db->prepare("UPDATE users SET name = :name, phone = :phone, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':name' => $name, ':phone' => $phone, ':id' => $guide_id]);

    $guide_check = $db->prepare("SELECT id FROM guide_profiles WHERE user_id = :uid LIMIT 1");
    $guide_check->execute([':uid' => $guide_id]);

    if ($guide_check->fetch()) {
        $stmt = $db->prepare("UPDATE guide_profiles SET bio = :bio, languages = :languages, specializations = :specializations, years_of_experience = :years_of_experience, availability_status = :availability_status WHERE user_id = :uid");
        $stmt->execute([
            ':bio' => $bio,
            ':languages' => $languages,
            ':specializations' => $specializations,
            ':years_of_experience' => $years_of_experience,
            ':availability_status' => $availability_status,
            ':uid' => $guide_id,
        ]);
    } else {
        $stmt = $db->prepare("INSERT INTO guide_profiles (user_id, bio, languages, specializations, years_of_experience, availability_status) VALUES (:uid, :bio, :languages, :specializations, :years_of_experience, :availability_status)");
        $stmt->execute([
            ':uid' => $guide_id,
            ':bio' => $bio,
            ':languages' => $languages,
            ':specializations' => $specializations,
            ':years_of_experience' => $years_of_experience,
            ':availability_status' => $availability_status,
        ]);
    }

    $success_msg = 'Profile updated successfully.';
    $user = current_user();
}

$guide_profile = null;
$guide_check = $db->prepare("SELECT * FROM guide_profiles WHERE user_id = :uid LIMIT 1");
$guide_check->execute([':uid' => $guide_id]);
$guide_profile = $guide_check->fetch();

if (is_post() && isset($_POST['change_avatar']) && verify_token($_POST['csrf_token'] ?? null)) {
    if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $upload = upload_file($_FILES['avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if ($upload['success']) {
            $stmt = $db->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
            $stmt->execute([':avatar' => 'avatars/' . $upload['filename'], ':id' => $guide_id]);
            $user = current_user();
            $success_msg = 'Profile image updated.';
        } else {
            $error_msg = $upload['message'];
        }
    } else {
        $error_msg = 'Please select an image to upload.';
    }
}

if (is_post() && isset($_POST['toggle_status']) && verify_token($_POST['csrf_token'] ?? null)) {
    $new_status = $user['status'] === 'active' ? 'inactive' : 'active';
    $db->prepare("UPDATE users SET status = :status WHERE id = :uid")
        ->execute([':status' => $new_status, ':uid' => $guide_id]);
    ActivityLog::log($guide_id, 'status_changed', "Account status changed to {$new_status}");
    $user = current_user();
    $success_msg = 'Account ' . ($new_status === 'active' ? 'activated' : 'deactivated') . ' successfully.';
}

$fields_filled = 0;
$total_fields = 7;
if (!empty($user['name'])) $fields_filled++;
if (!empty($user['phone'])) $fields_filled++;
if (!empty($user['email'])) $fields_filled++;
if (!empty($guide_profile['bio'])) $fields_filled++;
if (!empty($guide_profile['languages'])) $fields_filled++;
if (!empty($guide_profile['specializations'])) $fields_filled++;
if (($guide_profile['years_of_experience'] ?? 0) > 0) $fields_filled++;
$completion_pct = round(($fields_filled / $total_fields) * 100);

$availability_badges = [
    'available' => 'bg-success',
    'on_tour'   => 'bg-primary',
    'off_duty'  => 'bg-secondary',
    'on_leave'  => 'bg-warning text-dark',
];

render_page('guide', 'profile.php', 'My Profile', function () use ($user, $guide_profile, $success_msg, $error_msg, $completion_pct, $availability_badges) {
?>
<style>
.guide-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.guide-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.guide-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.profile-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.profile-card .profile-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;gap:10px;}
.profile-card .profile-header h6{margin:0;font-weight:700;color:var(--text-primary,#e2e8f0);}
.profile-input{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 14px;color:var(--text-primary,#e2e8f0);width:100%;font-size:0.9rem;}
.profile-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.2);}
.profile-input:disabled{opacity:0.5;cursor:not-allowed;}
.profile-input option{background:var(--card-bg,#1a1f2e);color:var(--text-primary,#e2e8f0);}
.profile-avatar-wrap{width:130px;height:130px;border-radius:50%;overflow:hidden;border:4px solid var(--border-color,#2a3042);margin:0 auto 16px;position:relative;transition:border-color 0.3s;}
.profile-avatar-wrap:hover{border-color:var(--primary,#0c6e5e);}
.profile-avatar-wrap img{width:100%;height:100%;object-fit:cover;}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;font-size:0.72rem;font-weight:600;}
.status-badge.active{background:rgba(34,197,94,0.15);color:#22c55e;}
.status-badge.inactive{background:rgba(239,68,68,0.15);color:#ef4444;}
.preview-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-color,#2a3042);}
.preview-item:last-child{border-bottom:none;}
.preview-item .label{font-weight:600;font-size:0.85rem;color:var(--text-primary,#e2e8f0);}
.preview-item .value{font-size:0.85rem;color:var(--text-muted,#94a3b8);}
.btn-brand{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;border:none;}
.btn-brand:hover{opacity:0.9;color:#fff;}
.btn-reset{background:rgba(255,255,255,0.08);color:var(--text-primary,#e2e8f0);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 20px;font-weight:600;}
.btn-reset:hover{background:rgba(255,255,255,0.12);color:var(--text-primary,#e2e8f0);}
</style>

<?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:12px;">
        <i class="fas fa-check-circle me-2"></i><?= sanitize($success_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:12px;">
        <i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error_msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="guide-hero">
    <div class="row align-items-center">
        <div class="col-md-8 position-relative" style="z-index:1;">
            <h3 class="fw-bold mb-1"><i class="fas fa-user-tie me-2"></i>My Profile</h3>
            <p class="mb-0 opacity-75" style="font-size:0.9rem;">Manage your guide profile and public information</p>
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
                    <div class="profile-avatar-wrap" onclick="document.getElementById('guideAvatarInput').click();">
                        <img src="<?= get_avatar_url($user) ?>" id="guideAvatarImg" alt="<?= sanitize($user['name']) ?>">
                    </div>
                    <label for="guideAvatarInput" style="width:36px;height:36px;border-radius:50%;background:#0c6e5e;color:#fff;display:flex;align-items:center;justify-content:center;position:absolute;bottom:8px;right:-4px;cursor:pointer;border:3px solid var(--card-bg,#1a1f2e);box-shadow:0 2px 8px rgba(0,0,0,0.3);z-index:2;">
                        <i class="fas fa-camera" style="font-size:0.85rem;"></i>
                    </label>
                    <form method="POST" enctype="multipart/form-data" id="guideAvatarForm">
                        <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                        <input type="hidden" name="change_avatar" value="1">
                        <input type="file" name="avatar" id="guideAvatarInput" class="d-none" accept="image/*">
                    </form>
                </div>
                <h5 class="fw-bold mb-1" style="color:var(--text-primary,#e2e8f0);"><?= sanitize($user['name']) ?></h5>
                <p style="font-size:0.85rem;color:var(--text-muted,#94a3b8);margin-bottom:12px;"><?= sanitize($user['email']) ?></p>

                <div style="width:100%;height:8px;background:rgba(255,255,255,0.06);border-radius:4px;overflow:hidden;margin-bottom:8px;">
                    <div style="width:<?= $completion_pct ?>%;height:100%;background:linear-gradient(90deg,#0c6e5e,#1a8a7a);border-radius:4px;transition:width 0.3s;"></div>
                </div>
                <small style="font-size:0.78rem;color:var(--text-muted,#94a3b8);">Profile Completion: <?= $completion_pct ?>%</small>

                <div style="margin-top:16px;">
                    <?php if ($user['status'] === 'active'): ?>
                        <form method="POST" onsubmit="return confirm('Deactivate your account? You will not receive new tour assignments.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="toggle_status" value="1">
                            <button type="submit" class="status-badge active" style="cursor:pointer;border:none;">
                                <i class="fas fa-check-circle"></i>Active — Click to Deactivate
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Reactivate your account?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="toggle_status" value="1">
                            <button type="submit" class="status-badge inactive" style="cursor:pointer;border:none;">
                                <i class="fas fa-pause-circle"></i>Inactive — Click to Activate
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php
                $avail = $guide_profile['availability_status'] ?? 'available';
                $avail_colors = ['available' => '#22c55e', 'on_tour' => '#3b82f6', 'off_duty' => '#64748b', 'on_leave' => '#f59e0b'];
                $avail_color = $avail_colors[$avail] ?? '#64748b';
                ?>
                <span class="status-badge mt-2" style="background:<?= $avail_color ?>20;color:<?= $avail_color ?>;border:none;">
                    <i class="fas fa-circle" style="font-size:0.5rem;"></i>
                    <?= ucfirst(str_replace('_', ' ', $avail)) ?>
                </span>
            </div>
        </div>

        <div class="profile-card">
            <div class="profile-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-eye" style="color:#3b82f6;font-size:0.7rem;"></i>
                </div>
                <h6>Public Profile Preview</h6>
            </div>
            <div style="padding:16px 20px;">
                <div class="preview-item"><span class="label">Name</span><span class="value"><?= sanitize($user['name']) ?></span></div>
                <div class="preview-item"><span class="label">Email</span><span class="value"><?= sanitize($user['email']) ?></span></div>
                <div class="preview-item"><span class="label">Phone</span><span class="value"><?= sanitize($user['phone'] ?? 'Not set') ?></span></div>
                <div class="preview-item"><span class="label">Experience</span><span class="value"><?= ($guide_profile['years_of_experience'] ?? 0) ?> years</span></div>
                <div class="preview-item"><span class="label">Languages</span><span class="value"><?= sanitize($guide_profile['languages'] ?? 'Not set') ?></span></div>
                <div class="preview-item"><span class="label">Specializations</span><span class="value"><?= sanitize($guide_profile['specializations'] ?? 'Not set') ?></span></div>
                <div class="preview-item"><span class="label">Status</span><span class="value"><?= ucfirst(str_replace('_', ' ', $avail)) ?></span></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="profile-card">
            <div class="profile-header">
                <div style="width:28px;height:28px;border-radius:6px;background:rgba(12,110,94,0.15);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-edit" style="color:var(--primary,#0c6e5e);font-size:0.7rem;"></i>
                </div>
                <h6>Edit Profile</h6>
            </div>
            <div style="padding:20px;">
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Full Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" class="profile-input" name="name" value="<?= sanitize($user['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Email</label>
                            <input type="email" class="profile-input" value="<?= sanitize($user['email']) ?>" disabled>
                            <small style="color:var(--text-muted,#64748b);font-size:0.75rem;">Email cannot be changed here.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Phone</label>
                            <input type="text" class="profile-input" name="phone" value="<?= sanitize($user['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Gender</label>
                            <select class="profile-input" disabled>
                                <option value=""><?= ucfirst($user['gender'] ?? 'Not specified') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Years of Experience</label>
                            <input type="number" class="profile-input" name="years_of_experience" value="<?= ($guide_profile['years_of_experience'] ?? 0) ?>" min="0" max="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Availability Status</label>
                            <select class="profile-input" name="availability_status">
                                <?php
                                $statuses = ['available' => 'Available', 'on_tour' => 'On Tour', 'off_duty' => 'Off Duty', 'on_leave' => 'On Leave'];
                                $current = $guide_profile['availability_status'] ?? 'available';
                                foreach ($statuses as $val => $label):
                                ?>
                                    <option value="<?= $val ?>" <?= $val === $current ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Languages Spoken</label>
                            <input type="text" class="profile-input" name="languages" value="<?= sanitize($guide_profile['languages'] ?? '') ?>" placeholder="e.g. English, Spanish, French">
                            <small style="color:var(--text-muted,#64748b);font-size:0.75rem;">Comma-separated list of languages.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Specializations</label>
                            <input type="text" class="profile-input" name="specializations" value="<?= sanitize($guide_profile['specializations'] ?? '') ?>" placeholder="e.g. Hiking, Cultural Tours, Wildlife">
                            <small style="color:var(--text-muted,#64748b);font-size:0.75rem;">Comma-separated list of specializations.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small" style="color:var(--text-muted,#94a3b8);">Bio / About</label>
                            <textarea class="profile-input" name="bio" rows="4" placeholder="Tell tourists about yourself..." style="resize:vertical;"><?= sanitize($guide_profile['bio'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div style="margin-top:20px;">
                        <button type="submit" class="btn-brand"><i class="fas fa-save me-1"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('guideAvatarInput').addEventListener('change', function(e) {
    var file = e.target.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) { alert('Please select an image file.'); return; }
    if (file.size > 5 * 1024 * 1024) { alert('Image must be under 5MB.'); return; }
    var reader = new FileReader();
    reader.onload = function(ev) { document.getElementById('guideAvatarImg').src = ev.target.result; };
    reader.readAsDataURL(file);
    setTimeout(function() { document.getElementById('guideAvatarForm').submit(); }, 300);
});
</script>
<?php }); ?>
