<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/classes/User.php';
require_once __DIR__ . '/../config/database.php';
start_session();

if (is_logged_in()) {
    $role = $_SESSION['role'] ?? 'tourist';
    redirect("/{$role}/index.php");
}

$errors = [];
$old = [
    'name' => '', 'gender' => '', 'age' => '', 'phone' => '', 'email' => '',
    'role' => 'tourist', 'emergency_contact' => '', 'emergency_contact_number' => '',
    'disability' => 'none', 'id_type' => 'national_id',
];

if (is_post()) {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid or expired token. Please try again.';
    } else {
        $old['name'] = trim($_POST['name'] ?? '');
        $old['gender'] = $_POST['gender'] ?? '';
        $old['age'] = trim($_POST['age'] ?? '');
        $old['phone'] = trim($_POST['phone'] ?? '');
        $old['email'] = trim($_POST['email'] ?? '');
        $old['role'] = $_POST['role'] ?? 'tourist';
        $old['emergency_contact'] = trim($_POST['emergency_contact'] ?? '');
        $old['emergency_contact_number'] = trim($_POST['emergency_contact_number'] ?? '');
        $old['disability'] = $_POST['disability'] ?? 'none';
        $old['id_type'] = $_POST['id_type'] ?? 'national_id';
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($old['name'])) $errors[] = 'Full name is required.';
        if (empty($old['gender']) || !in_array($old['gender'], ['male', 'female'])) $errors[] = 'Please select a valid gender.';
        if (empty($old['age']) || !is_numeric($old['age']) || (int)$old['age'] < 12) $errors[] = 'You must be at least 12 years old to register.';
        if (empty($old['phone'])) $errors[] = 'Contact number is required.';
        if (empty($old['email']) || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        if (empty($password)) $errors[] = 'Password is required.';
        elseif (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $password_confirm) $errors[] = 'Passwords do not match.';
        $valid_roles = ['tourist', 'staff'];
        if (!in_array($old['role'], $valid_roles)) $errors[] = 'Invalid role selected.';
        if ($old['role'] === 'staff' && !can_register_staff()) $errors[] = 'Staff registration limit reached. Please contact an administrator.';
        if ($old['role'] === 'tourist') {
            if (empty($old['emergency_contact'])) $errors[] = 'Emergency contact name is required for tourists.';
            if (empty($old['emergency_contact_number'])) $errors[] = 'Emergency contact number is required for tourists.';
        }
        if (!empty($_FILES['government_id']['name'])) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $ext = strtolower(pathinfo($_FILES['government_id']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) $errors[] = 'Government ID must be a JPG, JPEG, PNG, or PDF file.';
            if ($_FILES['government_id']['size'] > 5 * 1024 * 1024) $errors[] = 'Government ID file must be under 5MB.';
        }

        if (empty($errors)) {
            $register_data = [
                'name'  => $old['name'], 'email' => $old['email'], 'password' => $password,
                'gender' => $old['gender'], 'age' => (int)$old['age'], 'phone' => $old['phone'],
                'role' => $old['role'], 'status' => 'pending',
            ];
            $result = register($register_data);
            if ($result['success']) {
                $uid = $result['user_id'];
                $db = Database::getInstance()->getConnection();
                if ($old['role'] === 'tourist') {
                    $db->prepare("INSERT INTO tourist_profiles (user_id, emergency_contact, emergency_contact_number, disability) VALUES (:uid, :ec, :ecn, :d)")
                        ->execute([':uid' => $uid, ':ec' => $old['emergency_contact'], ':ecn' => $old['emergency_contact_number'], ':d' => $old['disability']]);
                }
                if (!empty($_FILES['government_id']['name'])) {
                    $upload = upload_file($_FILES['government_id'], 'ids', ['jpg', 'jpeg', 'png', 'pdf']);
                    if ($upload['success']) {
                        $db->prepare("INSERT INTO id_verifications (user_id, id_type, id_file_path, status, created_at) VALUES (:uid, :it, :dp, 'pending', db_now())")
                            ->execute([':uid' => $uid, ':it' => $old['id_type'], ':dp' => $upload['filename']]);
                    }
                }
                flash_message('success', 'Registration successful! Your account is pending verification. Please check your email.');
                redirect('/auth/login.php');
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title>Register | BINALGO</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/auth.css" rel="stylesheet">
</head>
<body>
<div class="auth-container">
    <a href="<?= BASE_URL ?>/" class="auth-back">
        <i class="fas fa-arrow-left"></i>Back
    </a>

    <div class="auth-card">
        <div class="auth-header">
            <div class="logo">
                <i class="fas fa-map-location-dot"></i>
            </div>
            <h4>Create Account</h4>
            <p>Join BINALGO today</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="auth-banner error show" role="alert">
                <i class="auth-banner-icon fas fa-circle-exclamation"></i>
                <span class="auth-banner-text"><?= sanitize(implode(' ', $errors)) ?></span>
                <button type="button" class="auth-banner-close" aria-label="Dismiss"><i class="fas fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" id="registerForm" novalidate>
            <?= csrf_field() ?>

            <div class="auth-section-title"><i class="fas fa-user"></i>Personal Information</div>

            <div class="auth-input-group">
                <label for="name" class="auth-input-label"><i class="fas fa-user"></i>Full Name <span class="text-danger">*</span></label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-user"></i></span>
                    <input type="text" class="auth-input" id="name" name="name"
                           value="<?= sanitize($old['name']) ?>" placeholder="Juan Dela Cruz"
                           autocomplete="name" aria-describedby="nameMsg">
                    <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="auth-field-msg" id="nameMsg" aria-live="polite">
                    <i class="fas fa-circle-exclamation"></i><span></span>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="auth-input-group">
                        <label for="gender" class="auth-input-label"><i class="fas fa-venus-mars"></i>Gender <span class="text-danger">*</span></label>
                        <div class="auth-select-wrap">
                            <span class="auth-input-icon"><i class="fas fa-venus-mars"></i></span>
                            <select class="auth-select" id="gender" name="gender" aria-describedby="genderMsg">
                                <option value="" disabled <?= empty($old['gender']) ? 'selected' : '' ?>>Select gender</option>
                                <option value="male" <?= $old['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                            </select>
                            <span class="auth-select-arrow"><i class="fas fa-chevron-down"></i></span>
                        </div>
                        <div class="auth-field-msg" id="genderMsg" aria-live="polite">
                            <i class="fas fa-circle-exclamation"></i><span></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="auth-input-group">
                        <label for="age" class="auth-input-label"><i class="fas fa-birthday-cake"></i>Age <span class="text-danger">*</span></label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon"><i class="fas fa-birthday-cake"></i></span>
                            <input type="number" class="auth-input" id="age" name="age"
                                   value="<?= sanitize($old['age']) ?>" min="12" max="120" placeholder="e.g. 25"
                                   inputmode="numeric" aria-describedby="ageMsg">
                            <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                        </div>
                        <div class="auth-field-msg" id="ageMsg" aria-live="polite">
                            <i class="fas fa-circle-exclamation"></i><span></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="auth-input-group">
                <label for="phone" class="auth-input-label"><i class="fas fa-phone"></i>Contact Number <span class="text-danger">*</span></label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-phone"></i></span>
                    <input type="tel" class="auth-input" id="phone" name="phone"
                           value="<?= sanitize($old['phone']) ?>" placeholder="+63 9XX XXX XXXX"
                           autocomplete="tel" aria-describedby="phoneMsg">
                    <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="auth-field-msg" id="phoneMsg" aria-live="polite">
                    <i class="fas fa-circle-exclamation"></i><span></span>
                </div>
            </div>

            <div class="auth-section-title"><i class="fas fa-user-lock"></i>Account Details</div>

            <div class="auth-input-group">
                <label for="email" class="auth-input-label"><i class="fas fa-envelope"></i>Email <span class="text-danger">*</span></label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="auth-input" id="email" name="email"
                           value="<?= sanitize($old['email']) ?>" placeholder="you@example.com"
                           autocomplete="email" aria-describedby="emailMsg">
                    <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="auth-field-msg" id="emailMsg" aria-live="polite">
                    <i class="fas fa-circle-exclamation"></i><span></span>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="auth-input-group">
                        <label for="password" class="auth-input-label"><i class="fas fa-lock"></i>Password <span class="text-danger">*</span></label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon"><i class="fas fa-lock"></i></span>
                            <input type="password" class="auth-input has-toggle" id="password" name="password"
                                   placeholder="Min 8 characters" minlength="8"
                                   autocomplete="new-password" aria-describedby="passwordMsg">
                            <button class="auth-input-action" type="button" id="togglePassword" tabindex="-1" aria-label="Show password" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                            <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                        </div>
                        <div class="auth-field-msg" id="passwordMsg" aria-live="polite">
                            <i class="fas fa-circle-exclamation"></i><span></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="auth-input-group">
                        <label for="password_confirm" class="auth-input-label"><i class="fas fa-lock"></i>Confirm Password <span class="text-danger">*</span></label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon"><i class="fas fa-lock"></i></span>
                            <input type="password" class="auth-input has-toggle" id="password_confirm" name="password_confirm"
                                   placeholder="Re-enter password" minlength="8"
                                   autocomplete="new-password" aria-describedby="confirmMsg">
                            <button class="auth-input-action" type="button" id="toggleConfirmPassword" tabindex="-1" aria-label="Show password" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                            <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                        </div>
                        <div class="auth-field-msg" id="confirmMsg" aria-live="polite">
                            <i class="fas fa-circle-exclamation"></i><span></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="auth-strength" id="pwStrength" data-level="0" aria-hidden="true">
                <div class="auth-strength-bar"><span></span><span></span><span></span><span></span></div>
                <span class="auth-strength-label">Password strength</span>
            </div>
            <div class="auth-criteria" id="pwCriteria">
                <div class="auth-criteria-item" data-crit="len"><i class="fas fa-circle-check"></i><span>Minimum 8 characters</span></div>
                <div class="auth-criteria-item" data-crit="upper"><i class="fas fa-circle-check"></i><span>One uppercase letter</span></div>
                <div class="auth-criteria-item" data-crit="num"><i class="fas fa-circle-check"></i><span>One number</span></div>
                <div class="auth-criteria-item" data-crit="special"><i class="fas fa-circle-check"></i><span>One special character</span></div>
            </div>

            <div class="auth-input-group">
                <label for="role" class="auth-input-label"><i class="fas fa-id-badge"></i>Register as <span class="text-danger">*</span></label>
                <div class="auth-select-wrap">
                    <span class="auth-input-icon"><i class="fas fa-id-badge"></i></span>
                    <select class="auth-select" id="role" name="role">
                        <option value="tourist" <?= $old['role'] === 'tourist' ? 'selected' : '' ?>>Tourist</option>
                        <option value="staff" <?= $old['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                    </select>
                    <span class="auth-select-arrow"><i class="fas fa-chevron-down"></i></span>
                </div>
                <div class="auth-file-hint"><i class="fas fa-info-circle"></i>Staff accounts are limited and require admin approval.</div>
            </div>

            <div id="touristFields" <?= $old['role'] !== 'tourist' ? 'style="display:none;"' : '' ?>>
                <div class="auth-section-title"><i class="fas fa-shield-halved"></i>Emergency Information</div>

                <div class="auth-input-group">
                    <label for="emergency_contact" class="auth-input-label"><i class="fas fa-user-shield"></i>Emergency Contact Name <span class="text-danger">*</span></label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="fas fa-user-shield"></i></span>
                        <input type="text" class="auth-input" id="emergency_contact" name="emergency_contact"
                               value="<?= sanitize($old['emergency_contact']) ?>" placeholder="Contact person's full name"
                               aria-describedby="emergencyContactMsg">
                        <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <div class="auth-field-msg" id="emergencyContactMsg" aria-live="polite">
                        <i class="fas fa-circle-exclamation"></i><span></span>
                    </div>
                </div>

                <div class="auth-input-group">
                    <label for="emergency_contact_number" class="auth-input-label"><i class="fas fa-phone-volume"></i>Emergency Contact Number <span class="text-danger">*</span></label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="fas fa-phone-volume"></i></span>
                        <input type="tel" class="auth-input" id="emergency_contact_number" name="emergency_contact_number"
                               value="<?= sanitize($old['emergency_contact_number']) ?>" placeholder="+63 9XX XXX XXXX"
                               aria-describedby="emergencyNumberMsg">
                        <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <div class="auth-field-msg" id="emergencyNumberMsg" aria-live="polite">
                        <i class="fas fa-circle-exclamation"></i><span></span>
                    </div>
                </div>

                <div class="auth-input-group">
                    <label for="disability" class="auth-input-label"><i class="fas fa-wheelchair"></i>Disability</label>
                    <div class="auth-select-wrap">
                        <span class="auth-input-icon"><i class="fas fa-wheelchair"></i></span>
                        <select class="auth-select" id="disability" name="disability">
                            <option value="none" <?= $old['disability'] === 'none' ? 'selected' : '' ?>>None</option>
                            <option value="physical" <?= $old['disability'] === 'physical' ? 'selected' : '' ?>>Physical</option>
                            <option value="visual" <?= $old['disability'] === 'visual' ? 'selected' : '' ?>>Visual</option>
                            <option value="hearing" <?= $old['disability'] === 'hearing' ? 'selected' : '' ?>>Hearing</option>
                            <option value="other" <?= $old['disability'] === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                        <span class="auth-select-arrow"><i class="fas fa-chevron-down"></i></span>
                    </div>
                </div>
            </div>

            <div class="auth-section-title"><i class="fas fa-id-card"></i>ID Verification</div>

            <div class="auth-input-group">
                <label for="id_type" class="auth-input-label"><i class="fas fa-id-card"></i>ID Type</label>
                <div class="auth-select-wrap">
                    <span class="auth-input-icon"><i class="fas fa-id-card"></i></span>
                    <select class="auth-select" id="id_type" name="id_type">
                        <option value="national_id" <?= $old['id_type'] === 'national_id' ? 'selected' : '' ?>>National ID</option>
                        <option value="passport" <?= $old['id_type'] === 'passport' ? 'selected' : '' ?>>Passport</option>
                        <option value="drivers_license" <?= $old['id_type'] === 'drivers_license' ? 'selected' : '' ?>>Driver's License</option>
                        <option value="voters_id" <?= $old['id_type'] === 'voters_id' ? 'selected' : '' ?>>Voter's ID</option>
                        <option value="senior_citizen" <?= $old['id_type'] === 'senior_citizen' ? 'selected' : '' ?>>Senior Citizen ID</option>
                        <option value="other" <?= $old['id_type'] === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                    <span class="auth-select-arrow"><i class="fas fa-chevron-down"></i></span>
                </div>
            </div>

            <div class="auth-input-group">
                <label class="auth-input-label"><i class="fas fa-cloud-arrow-up"></i>Upload Government ID</label>
                <div class="auth-file-wrap">
                    <input type="file" id="government_id" name="government_id" accept=".jpg,.jpeg,.png,.pdf">
                    <div class="auth-file-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                    <div class="auth-file-text" id="idFileName">Click to upload or drag &amp; drop</div>
                    <div class="auth-file-hint"><i class="fas fa-circle-info"></i>JPG, JPEG, PNG, PDF — Max 5MB</div>
                </div>
                <div class="id-preview" id="idPreview"></div>
            </div>

            <button type="submit" class="auth-submit-btn auth-ripple" id="registerBtn">
                <span class="auth-submit-text"><i class="fas fa-user-plus"></i> Create Account</span>
                <span class="auth-submit-loading"><span class="auth-spinner"></span> Creating account...</span>
            </button>
        </form>

        <div class="auth-divider">
            <span>or sign up with</span>
        </div>

        <div class="auth-social-row">
            <a href="<?= BASE_URL ?>/auth/google.php" class="auth-social-btn google auth-ripple" aria-label="Continue with Google">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#EA4335" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#4285F4" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                Google
            </a>
            <a href="<?= BASE_URL ?>/auth/facebook.php" class="auth-social-btn facebook auth-ripple" aria-label="Continue with Facebook">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="#1877F2" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                Facebook
            </a>
        </div>

        <div class="auth-footer">
            Already have an account? <a href="<?= BASE_URL ?>/auth/login.php">Login</a>
        </div>

        <div class="auth-version"><span class="dot"></span>BINALBAGAN SECURE ACCESS v2.0</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/auth.js"></script>
<script>
(function () {
    var form = document.getElementById('registerForm');
    var btn = document.getElementById('registerBtn');
    var roleSelect = document.getElementById('role');
    var touristFields = document.getElementById('touristFields');

    var fields = {
        name: document.getElementById('name'),
        gender: document.getElementById('gender'),
        age: document.getElementById('age'),
        phone: document.getElementById('phone'),
        email: document.getElementById('email'),
        password: document.getElementById('password'),
        confirm: document.getElementById('password_confirm'),
        emergency_contact: document.getElementById('emergency_contact'),
        emergency_contact_number: document.getElementById('emergency_contact_number'),
    };
    var strengthEl = document.getElementById('pwStrength');
    var strengthLabel = strengthEl.querySelector('.auth-strength-label');
    var criteriaEl = document.getElementById('pwCriteria');

    function msgFor(id) { return document.getElementById(id); }

    function isTourist() { return roleSelect.value === 'tourist'; }

    /* Individual validators — return boolean */
    function vName() {
        var v = fields.name.value.trim();
        if (!v) { Auth.clearFieldState(fields.name); return false; }
        var ok = Auth.isValidName(v);
        Auth.setFieldState(fields.name, ok, {
            msgEl: msgFor('nameMsg'), badText: 'Please enter your full name (2-100 characters).',
        });
        return ok;
    }
    function vGender() {
        var valid = !!fields.gender.value;
        fields.gender.classList.toggle('is-bad', !valid);
        return valid;
    }
    function vAge() {
        var v = fields.age.value.trim();
        if (!v) { Auth.clearFieldState(fields.age); return false; }
        var ok = Auth.isValidAge(v);
        Auth.setFieldState(fields.age, ok, {
            msgEl: msgFor('ageMsg'), badText: 'You must be at least 12 years old.',
        });
        return ok;
    }
    function vPhone(input, msgId, badText) {
        var v = input.value.trim();
        if (!v) { Auth.clearFieldState(input); return false; }
        var ok = Auth.isValidPhone(v);
        Auth.setFieldState(input, ok, {
            msgEl: msgFor(msgId), badText: badText,
        });
        return ok;
    }
    function vEmail() {
        var v = fields.email.value.trim();
        if (!v) { Auth.clearFieldState(fields.email); return false; }
        var ok = Auth.isValidEmail(v);
        Auth.setFieldState(fields.email, ok, {
            msgEl: msgFor('emailMsg'), badText: 'Please enter a valid email address.',
        });
        return ok;
    }

    /* Password strength + criteria */
    function renderStrength(pw) {
        var s = Auth.strengthOf(pw);
        strengthEl.setAttribute('data-level', s.score);
        strengthLabel.textContent = pw ? s.label : 'Password strength';
    }
    function renderCriteria(pw) {
        var items = criteriaEl.querySelectorAll('.auth-criteria-item');
        items.forEach(function (item) {
            var key = item.getAttribute('data-crit');
            item.classList.toggle('met', Auth.CRITERIA_TESTS[key](pw));
        });
    }
    function vPassword() {
        var pw = fields.password.value;
        var v = fields.password.value.trim();
        if (!v) { Auth.clearFieldState(fields.password); return false; }
        var okLen = pw.length >= 8;
        var valid = okLen;
        Auth.setFieldState(fields.password, valid, {
            msgEl: msgFor('passwordMsg'),
            okText: 'Great password!',
            badText: 'Password must be at least 8 characters.',
        });
        return valid;
    }
    function vConfirm() {
        var v = fields.confirm.value;
        if (!v) { Auth.clearFieldState(fields.confirm); return false; }
        var match = v === fields.password.value && fields.password.value.length > 0;
        Auth.setFieldState(fields.confirm, match, {
            msgEl: msgFor('confirmMsg'),
            okText: 'Passwords match.',
            badText: 'Passwords do not match.',
        });
        return match;
    }
    function vEmergency() {
        if (!isTourist()) return true;
        var okName = vEmergencyName();
        var okNum = vEmergencyNumber();
        return okName && okNum;
    }
    function vEmergencyName() {
        var v = fields.emergency_contact.value.trim();
        if (!v) { Auth.clearFieldState(fields.emergency_contact); return false; }
        var ok = Auth.isValidName(v);
        Auth.setFieldState(fields.emergency_contact, ok, {
            msgEl: msgFor('emergencyContactMsg'), badText: 'Emergency contact name is required.',
        });
        return ok;
    }
    function vEmergencyNumber() {
        return vPhone(fields.emergency_contact_number, 'emergencyNumberMsg', 'Please enter a valid emergency contact number.');
    }

    /* Field wiring: show meter/criteria on password focus/input */
    fields.password.addEventListener('focus', function () {
        strengthEl.classList.add('show');
        criteriaEl.classList.add('show');
    });
    fields.password.addEventListener('blur', function () {
        if (!fields.password.value) {
            strengthEl.classList.remove('show');
            criteriaEl.classList.remove('show');
        }
    });
    fields.password.addEventListener('input', function () {
        renderStrength(fields.password.value);
        renderCriteria(fields.password.value);
        if (fields.confirm.value) vConfirm();
        vPassword();
    });
    fields.confirm.addEventListener('input', Auth.debounce(vConfirm, 200));
    fields.confirm.addEventListener('blur', vConfirm);

    fields.name.addEventListener('input', Auth.debounce(vName, 250));
    fields.name.addEventListener('blur', vName);
    fields.gender.addEventListener('change', vGender);
    fields.age.addEventListener('input', Auth.debounce(vAge, 250));
    fields.age.addEventListener('blur', vAge);
    fields.phone.addEventListener('input', Auth.debounce(function () {
        vPhone(fields.phone, 'phoneMsg', 'Please enter a valid PH mobile number (e.g. +63 9XX XXX XXXX).');
    }, 250));
    fields.phone.addEventListener('blur', function () {
        vPhone(fields.phone, 'phoneMsg', 'Please enter a valid PH mobile number (e.g. +63 9XX XXX XXXX).');
    });
    fields.email.addEventListener('input', Auth.debounce(vEmail, 250));
    fields.email.addEventListener('blur', vEmail);
    fields.emergency_contact.addEventListener('input', Auth.debounce(vEmergencyName, 250));
    fields.emergency_contact.addEventListener('blur', vEmergencyName);
    fields.emergency_contact_number.addEventListener('input', Auth.debounce(vEmergencyNumber, 250));
    fields.emergency_contact_number.addEventListener('blur', vEmergencyNumber);

    Auth.setupPasswordToggle(fields.password, document.getElementById('togglePassword'));
    Auth.setupPasswordToggle(fields.confirm, document.getElementById('toggleConfirmPassword'));

    /* Role toggle: show/hide tourist emergency block */
    roleSelect.addEventListener('change', function () {
        touristFields.style.display = isTourist() ? '' : 'none';
    });

    /* ID file preview */
    var idPreview = document.getElementById('idPreview');
    var idFileName = document.getElementById('idFileName');
    document.getElementById('government_id').addEventListener('change', function () {
        var file = this.files[0];
        if (!file) { idPreview.innerHTML = ''; idFileName.textContent = 'Click to upload or drag & drop'; return; }
        idFileName.textContent = file.name;
        var ext = file.name.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png'].indexOf(ext) !== -1) {
            var reader = new FileReader();
            reader.onload = function (e) {
                idPreview.innerHTML = '<img src="' + e.target.result + '" alt="Government ID preview">';
            };
            reader.readAsDataURL(file);
        } else {
            idPreview.innerHTML =
                '<div class="id-file-card"><i class="fas fa-file-pdf"></i><span>' + file.name + '</span></div>';
        }
    });

    /* Submit: validate → spinner → native submit (multipart + server redirect) */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var okName = vName();
        var okGender = vGender();
        var okAge = vAge();
        var okPhone = vPhone(fields.phone, 'phoneMsg', 'Please enter a valid PH mobile number (e.g. +63 9XX XXX XXXX).');
        var okEmail = vEmail();
        var okPass = vPassword();
        var okConfirm = vConfirm();
        var okEmergency = vEmergency();

        if (!(okName && okGender && okAge && okPhone && okEmail && okPass && okConfirm && okEmergency)) {
            form.classList.remove('auth-shake');
            void form.offsetWidth;
            form.classList.add('auth-shake');
            Auth.focusInvalid(form);
            return;
        }

        Auth.setButtonLoading(btn, true);
        setTimeout(function () { form.submit(); }, 250);
    });
})();
</script>
</body>
</html>
