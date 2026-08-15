<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

if (is_post() && verify_token($_POST['csrf_token'] ?? null)) {
    $name = sanitize(trim($_POST['name'] ?? ''));
    $phone = sanitize(trim($_POST['phone'] ?? ''));
    $email = sanitize(trim($_POST['email'] ?? ''));

    if (empty($name) || empty($email)) {
        $error = 'Name and email are required.';
    } else {
        $stmt = $db->prepare("UPDATE users SET name = :name, phone = :phone, email = :email, updated_at = datetime('now') WHERE id = :id");
        $stmt->execute([':name' => $name, ':phone' => $phone, ':email' => $email, ':id' => $user_id]);

            if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $upload = upload_file($_FILES['avatar'], 'avatars', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                if ($upload['success']) {
                    $stmt = $db->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
                    $stmt->execute([':avatar' => 'avatars/' . $upload['filename'], ':id' => $user_id]);
                }
            }

        $success = 'Profile updated successfully.';
        $_SESSION['name'] = $name;
    }
}

if (is_post() && isset($_POST['toggle_status']) && verify_token($_POST['csrf_token'] ?? null)) {
    $new_status = $user['status'] === 'active' ? 'inactive' : 'active';
    $db->prepare("UPDATE users SET status = :status WHERE id = :uid")
        ->execute([':status' => $new_status, ':uid' => $user_id]);
    $user = current_user();
    $success = 'Account ' . ($new_status === 'active' ? 'activated' : 'deactivated') . ' successfully.';
}

$page_title = 'My Profile';
render_page('admin', 'profile.php', $page_title, function () use ($user, $success, $error) {
?>
<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-4">
                <div class="position-relative d-inline-block mb-3">
                    <div style="width:120px;height:120px;border-radius:50%;overflow:hidden;border:3px solid #e2e8f0;margin:0 auto;cursor:pointer;" onclick="document.getElementById('adminAvatarInput').click();">
                        <img src="<?= get_avatar_url($user) ?>" id="adminAvatarImg" style="width:100%;height:100%;object-fit:cover;" alt="<?= sanitize($user['name']) ?>">
                    </div>
                    <label for="adminAvatarInput" style="width:36px;height:36px;border-radius:50%;background:#0c6e5e;color:#fff;display:flex;align-items:center;justify-content:center;position:absolute;bottom:4px;right:4px;cursor:pointer;border:3px solid var(--card-bg, #fff);box-shadow:0 2px 6px rgba(0,0,0,0.3);z-index:2;">
                        <i class="fas fa-camera" style="font-size:0.85rem;"></i>
                    </label>
                </div>
                <h5 class="mb-1"><?= sanitize($user['name']) ?></h5>
                <span class="badge bg-primary"><?= sanitize(ucfirst($user['role'])) ?></span>
                <?php if ($user['status'] === 'active'): ?>
                    <form method="POST" onsubmit="return confirm('Deactivate your account?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" class="badge bg-success text-decoration-none border-0 mt-1" style="cursor:pointer;font-size:0.78rem;">
                            <i class="fas fa-check-circle me-1"></i>Active — Click to Deactivate
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" onsubmit="return confirm('Reactivate your account?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="toggle_status" value="1">
                        <button type="submit" class="badge bg-secondary text-decoration-none border-0 mt-1" style="cursor:pointer;font-size:0.78rem;">
                            <i class="fas fa-pause-circle me-1"></i>Inactive — Click to Activate
                        </button>
                    </form>
                <?php endif; ?>
                <p class="text-muted small mt-2 mb-0"><?= sanitize($user['email']) ?></p>
                <p class="text-muted small">Member since <?= format_date($user['created_at']) ?></p>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-bottom">
                <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile</h5>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= sanitize($user['name']) ?>" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= sanitize($user['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Profile Picture</label>
                        <input type="file" name="avatar" id="adminAvatarInput" class="form-control" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
});
?>
