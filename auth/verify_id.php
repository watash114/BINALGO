<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/classes/User.php';
require_once __DIR__ . '/../config/database.php';

require_login();

$page_title = 'ID Verification';
$active_page = 'verify_id';
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$db = Database::getInstance()->getConnection();
$errors = [];
$success = '';

$user = current_user();

$stmt = $db->prepare("SELECT * FROM id_verifications WHERE user_id = :uid ORDER BY created_at DESC");
$stmt->execute([':uid' => $user_id]);
$verifications = $stmt->fetchAll();

$pending = null;
foreach ($verifications as $v) {
    if ($v['status'] === 'pending') {
        $pending = $v;
        break;
    }
}

if (is_post()) {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid or expired token. Please try again.';
    } else {
        $id_type = $_POST['id_type'] ?? 'national_id';
        $valid_id_types = ['passport', 'drivers_license', 'national_id', 'voters_id', 'senior_citizen', 'other'];
        if (!in_array($id_type, $valid_id_types)) {
            $id_type = 'national_id';
        }

        if (empty($_FILES['government_id']['name'])) {
            $errors[] = 'Please select a government ID file to upload.';
        } else {
            $file = $_FILES['government_id'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'File upload error. Please try again.';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
                if (!in_array($ext, $allowed)) {
                    $errors[] = 'File type not allowed. Accepted: JPG, JPEG, PNG, PDF.';
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'File size must be under 5MB.';
                } else {
                    $upload_result = upload_file($file, 'ids', $allowed);
                    if ($upload_result['success']) {
                        $stmt = $db->prepare(
                            "INSERT INTO id_verifications (user_id, id_type, id_file_path, status, created_at)
                             VALUES (:uid, :idt, :fp, 'pending', db_now())"
                        );
                        $stmt->execute([
                            ':uid' => $user_id,
                            ':idt' => $id_type,
                            ':fp'  => $upload_result['path'],
                        ]);

                        $stmt = $db->prepare("SELECT * FROM id_verifications WHERE user_id = :uid ORDER BY created_at DESC");
                        $stmt->execute([':uid' => $user_id]);
                        $verifications = $stmt->fetchAll();

                        $pending = null;
                        foreach ($verifications as $v) {
                            if ($v['status'] === 'pending') {
                                $pending = $v;
                                break;
                            }
                        }

                        $success = 'Government ID uploaded successfully. Your submission is pending review.';
                    } else {
                        $errors[] = $upload_result['message'];
                    }
                }
            }
        }
    }
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="content-wrapper">
    <div class="container-fluid py-4">
        <div class="page-header">
            <h2><i class="fas fa-id-card me-2 text-primary"></i>ID Verification</h2>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger d-flex align-items-center" style="border-radius: var(--radius);">
                <i class="fas fa-exclamation-circle me-2"></i>
                <div>
                    <?php foreach ($errors as $err): ?>
                        <div><?= sanitize($err) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success d-flex align-items-center" style="border-radius: var(--radius);">
                <i class="fas fa-check-circle me-2"></i>
                <span><?= sanitize($success) ?></span>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Upload Form -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-upload me-2"></i>Upload Government ID</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($pending): ?>
                            <div class="alert alert-warning d-flex align-items-center mb-3" style="border-radius: var(--radius); font-size: 0.9rem;">
                                <i class="fas fa-clock me-2"></i>
                                <span>You already have a pending verification submitted on <?= format_datetime($pending['created_at']) ?>. You may submit a new one which will replace the pending request.</span>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
                            <?= csrf_field() ?>

                            <div class="form-group mb-3">
                                <label for="id_type" class="form-label">ID Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="id_type" name="id_type" required>
                                    <option value="national_id">National ID</option>
                                    <option value="passport">Passport</option>
                                    <option value="drivers_license">Driver's License</option>
                                    <option value="voters_id">Voter's ID</option>
                                    <option value="senior_citizen">Senior Citizen ID</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="form-group mb-4">
                                <label for="government_id" class="form-label">Upload ID Image <span class="text-danger">*</span></label>
                                <div class="file-upload">
                                    <input type="file" class="form-control" id="government_id" name="government_id"
                                           accept=".jpg,.jpeg,.png,.pdf" required
                                           style="padding: 10px 14px; height: auto;">
                                </div>
                                <div class="form-text">
                                    Accepted: JPG, JPEG, PNG, PDF. Max 5MB.
                                </div>
                                <div class="file-preview mt-2" id="idPreview"></div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-cloud-upload-alt me-2"></i>Upload ID
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Verification Status & History -->
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Verification Status</h6>
                    </div>
                    <div class="card-body">
                        <?php
                        $latest = $verifications[0] ?? null;
                        if ($latest):
                            $status_badge = '';
                            switch ($latest['status']) {
                                case 'pending':
                                    $status_badge = '<span class="badge badge-status badge-pending"><i class="fas fa-clock me-1"></i>Pending Review</span>';
                                    break;
                                case 'approved':
                                    $status_badge = '<span class="badge badge-status badge-approved"><i class="fas fa-check me-1"></i>Approved</span>';
                                    break;
                                case 'rejected':
                                    $status_badge = '<span class="badge badge-status badge-rejected"><i class="fas fa-times me-1"></i>Rejected</span>';
                                    break;
                            }
                        ?>
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <p class="mb-1 fw-semibold">Current Status</p>
                                <?= $status_badge ?>
                            </div>
                            <div class="text-end">
                                <small class="text-muted">Submitted: <?= format_datetime($latest['created_at']) ?></small>
                            </div>
                        </div>
                        <?php if ($latest['status'] === 'rejected' && !empty($latest['admin_notes'])): ?>
                            <div class="alert alert-danger mt-3 mb-0" style="border-radius: var(--radius); font-size: 0.9rem;">
                                <strong>Rejection Reason:</strong> <?= sanitize($latest['admin_notes']) ?>
                            </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-id-card text-muted" style="font-size: 2.5rem;"></i>
                            <p class="mt-2 text-muted mb-0">No verification submissions yet. Upload your government ID to get started.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($verifications)): ?>
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-history me-2"></i>Verification History</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="data-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>ID Type</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($verifications as $v): ?>
                                    <tr>
                                        <td><?= format_datetime($v['created_at']) ?></td>
                                        <td>
                                            <?php
                                            $type_labels = [
                                                'passport' => 'Passport',
                                                'drivers_license' => "Driver's License",
                                                'national_id' => 'National ID',
                                                'voters_id' => "Voter's ID",
                                                'senior_citizen' => 'Senior Citizen ID',
                                                'other' => 'Other',
                                            ];
                                            echo $type_labels[$v['id_type']] ?? ucfirst(str_replace('_', ' ', $v['id_type']));
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            switch ($v['status']) {
                                                case 'pending':
                                                    echo '<span class="badge badge-status badge-pending">Pending</span>';
                                                    break;
                                                case 'approved':
                                                    echo '<span class="badge badge-status badge-approved">Approved</span>';
                                                    break;
                                                case 'rejected':
                                                    echo '<span class="badge badge-status badge-rejected">Rejected</span>';
                                                    break;
                                            }
                                            ?>
                                        </td>
                                        <td><?= sanitize($v['admin_notes'] ?? '-') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
document.getElementById('government_id').addEventListener('change', function() {
    const preview = document.getElementById('idPreview');
    if (this.files[0]) {
        const file = this.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png'].includes(ext)) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = '<img src="' + e.target.result + '" class="img-thumbnail" style="max-height:150px;">';
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = '<div class="d-flex align-items-center gap-2 p-2 rounded" style="background: var(--light);"><i class="fas fa-file-pdf text-danger fs-4"></i><span class="text-muted">' + file.name + '</span></div>';
        }
    } else {
        preview.innerHTML = '';
    }
});
</script>
