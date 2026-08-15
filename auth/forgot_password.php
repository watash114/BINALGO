<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/classes/User.php';
start_session();

if (is_logged_in()) {
    $role = $_SESSION['role'] ?? 'tourist';
    redirect("/{$role}/index.php");
}

$step = 1;
$errors = [];
$email = '';
$code = '';
$message = '';

if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_code') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $user = new User();
            $found = $user->findByEmail($email);
            if (!$found) {
                $errors[] = 'No account found with that email address.';
            } else {
                $reset_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("UPDATE users SET reset_token = :token, reset_expires = db_date_add(, 'INTERVAL  ') WHERE email = :email");
                $stmt->execute([':token' => password_hash($reset_code, PASSWORD_DEFAULT), ':email' => $email]);

                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_code'] = $reset_code;
                $message = 'A 6-digit code has been sent to your email.';
                $step = 2;
            }
        }
        if (!empty($errors)) $step = 1;
    }

    if ($action === 'verify_code') {
        $email = $_SESSION['reset_email'] ?? '';
        $code = trim($_POST['code'] ?? '');
        $stored_code = $_SESSION['reset_code'] ?? '';

        if (empty($email) || $code !== $stored_code) {
            $errors[] = 'Invalid verification code.';
            $step = 2;
        } else {
            $step = 3;
        }
    }

    if ($action === 'reset_password') {
        $email = $_SESSION['reset_email'] ?? '';
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($email)) {
            $errors[] = 'Session expired. Please start over.';
            $step = 1;
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
            $step = 3;
        } elseif ($password !== $password_confirm) {
            $errors[] = 'Passwords do not match.';
            $step = 3;
        } else {
            $user = new User();
            $found = $user->findByEmail($email);
            if ($found) {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("UPDATE users SET password = :pw, reset_token = NULL, reset_expires = NULL WHERE email = :email");
                $stmt->execute([':pw' => password_hash($password, PASSWORD_DEFAULT), ':email' => $email]);
                unset($_SESSION['reset_email'], $_SESSION['reset_code']);
                flash_message('success', 'Password reset successful! You can now log in.');
                redirect('/auth/login.php');
            } else {
                $errors[] = 'Account not found.';
                $step = 1;
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
    <title>Forgot Password | BINALGO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
    .auth-input-group { margin-bottom: 20px; }
    .auth-input-label {
        display: block; font-size: 0.72rem; font-weight: 600; letter-spacing: 1.2px;
        text-transform: uppercase; color: rgba(26,138,122,0.7); margin-bottom: 8px;
    }
    .auth-input-wrap { position: relative; display: flex; align-items: center; }
    .auth-input {
        width: 100%; background: rgba(255,255,255,0.06) !important;
        border: 1.5px solid rgba(255,255,255,0.1) !important; border-radius: 12px;
        padding: 13px 16px 13px 46px; font-size: 0.92rem; color: #f1f5f9 !important;
        transition: all 0.3s cubic-bezier(.4,0,.2,1); outline: none;
        -webkit-appearance: none; -moz-appearance: none; appearance: none;
    }
    .auth-input::placeholder { color: rgba(255,255,255,0.3); }
    .auth-input:focus {
        border-color: rgba(26,138,122,0.5) !important; background: rgba(255,255,255,0.08) !important;
        box-shadow: 0 0 0 4px rgba(26,138,122,0.08), 0 0 20px rgba(26,138,122,0.06) !important;
        color: #fff !important;
    }
    .auth-input-icon {
        position: absolute; left: 16px; color: rgba(26,138,122,0.5); font-size: 0.9rem;
        transition: color 0.3s; pointer-events: none; z-index: 1;
    }
    .auth-input-wrap:focus-within .auth-input-icon { color: #2dd4bf; }
    .auth-input-action {
        position: absolute; right: 6px; width: 36px; height: 36px; border-radius: 8px;
        border: none; background: transparent; color: rgba(255,255,255,0.35);
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: all 0.2s; font-size: 0.9rem;
    }
    .auth-input-action:hover { background: rgba(255,255,255,0.08); color: #2dd4bf; }
    .auth-submit-btn {
        width: 100%; padding: 14px; border-radius: 12px; border: none;
        background: linear-gradient(135deg, #0c6e5e, #1a8a7a); color: #fff;
        font-weight: 700; font-size: 0.9rem; letter-spacing: 0.8px; cursor: pointer;
        transition: all 0.3s cubic-bezier(.4,0,.2,1);
        box-shadow: 0 4px 20px rgba(12,110,94,0.35); position: relative;
        overflow: hidden; margin-top: 4px;
    }
    .auth-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(12,110,94,0.45); }
    .auth-submit-btn:active { transform: translateY(0); }
    .auth-submit-text, .auth-submit-loading { display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .auth-spinner {
        width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3);
        border-top-color: #fff; border-radius: 50%; animation: spin 0.7s linear infinite;
        display: inline-block;
    }
    .auth-back-link {
        display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem;
        color: rgba(255,255,255,0.4); text-decoration: none; margin-top: 16px;
        transition: color 0.2s;
    }
    .auth-back-link:hover { color: #2dd4bf; text-decoration: none; }
    .step-indicator {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        margin-bottom: 24px;
    }
    .step-dot {
        width: 10px; height: 10px; border-radius: 50%;
        background: rgba(255,255,255,0.12); transition: all 0.3s;
    }
    .step-dot.active { background: #2dd4bf; box-shadow: 0 0 8px rgba(45,212,191,0.4); }
    .step-dot.done { background: #0c6e5e; }
    .code-inputs {
        display: flex; gap: 8px; justify-content: center;
    }
    .code-inputs input {
        width: 50px; height: 56px; text-align: center; font-size: 1.3rem; font-weight: 700;
        background: rgba(255,255,255,0.06) !important; border: 1.5px solid rgba(255,255,255,0.1) !important;
        border-radius: 12px; color: #f1f5f9 !important; outline: none;
        transition: all 0.3s; -webkit-appearance: none;
    }
    .code-inputs input:focus {
        border-color: rgba(26,138,122,0.5) !important;
        box-shadow: 0 0 0 4px rgba(26,138,122,0.08) !important;
        background: rgba(255,255,255,0.08) !important;
    }
    .auth-msg {
        background: rgba(45,212,191,0.08); border: 1px solid rgba(45,212,191,0.15);
        border-radius: 10px; padding: 10px 14px; font-size: 0.85rem;
        color: #2dd4bf; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
    }
    </style>
</head>
<body>
<div class="auth-container">
    <a href="<?= BASE_URL ?>/auth/login.php" class="btn position-absolute" style="top:20px;left:20px;color:rgba(255,255,255,0.85);border:1px solid rgba(255,255,255,0.25);border-radius:10px;backdrop-filter:blur(8px);background:rgba(0,0,0,0.2);z-index:10;">
        <i class="fas fa-arrow-left me-2"></i>Back to Login
    </a>
    <div class="auth-card" style="max-width:420px;">
        <div class="auth-header">
            <div class="logo"><i class="fas fa-key"></i></div>
            <h4>Reset Password</h4>
            <p>Follow the steps to reset your password</p>
        </div>

        <div class="step-indicator">
            <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></div>
            <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>"></div>
            <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>"></div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" style="border-radius:10px;font-size:0.85rem;background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.25);color:#fca5a5;">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= implode('<br>', array_map('sanitize', $errors)) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <form method="POST" id="forgotForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_code">
            <div class="auth-input-group">
                <label class="auth-input-label">Email Address</label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="auth-input" name="email" value="<?= sanitize($email) ?>" placeholder="you@example.com" required>
                </div>
            </div>
            <button type="submit" class="auth-submit-btn">
                <span class="auth-submit-text"><i class="fas fa-paper-plane"></i> Send Verification Code</span>
            </button>
        </form>

        <?php elseif ($step === 2): ?>
        <?php if ($message): ?><div class="auth-msg"><i class="fas fa-check-circle"></i> <?= sanitize($message) ?></div><?php endif; ?>
        <form method="POST" id="forgotForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify_code">
            <p style="text-align:center;color:rgba(255,255,255,0.5);font-size:0.85rem;margin-bottom:16px;">Enter the 6-digit code sent to<br><strong style="color:#2dd4bf;"><?= sanitize($email) ?></strong></p>
            <div class="auth-input-group">
                <div class="code-inputs">
                    <input type="text" maxlength="1" class="code-digit" data-next="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                    <input type="text" maxlength="1" class="code-digit" data-next="2" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="code-digit" data-next="3" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="code-digit" data-next="4" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="code-digit" data-next="5" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="code-digit" data-next="6" inputmode="numeric" pattern="[0-9]">
                </div>
                <input type="hidden" name="code" id="codeValue">
            </div>
            <button type="submit" class="auth-submit-btn">
                <span class="auth-submit-text"><i class="fas fa-check-circle"></i> Verify Code</span>
            </button>
        </form>
        <div style="text-align:center;">
            <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="auth-back-link" style="margin-top:12px;"><i class="fas fa-redo"></i> Resend code</a>
        </div>

        <?php elseif ($step === 3): ?>
        <form method="POST" id="forgotForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <p style="text-align:center;color:rgba(255,255,255,0.5);font-size:0.85rem;margin-bottom:16px;">Create a new password for your account</p>
            <div class="auth-input-group">
                <label class="auth-input-label">New Password</label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" class="auth-input" name="password" id="newPassword" placeholder="Min 8 characters" required minlength="8">
                    <button class="auth-input-action" type="button" id="toggleNewPw" tabindex="-1"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="auth-input-group">
                <label class="auth-input-label">Confirm Password</label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" class="auth-input" name="password_confirm" id="confirmPassword" placeholder="Re-enter password" required minlength="8">
                    <button class="auth-input-action" type="button" id="toggleConfirmPw" tabindex="-1"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="auth-submit-btn">
                <span class="auth-submit-text"><i class="fas fa-save"></i> Reset Password</span>
            </button>
        </form>
        <?php endif; ?>

        <div style="text-align:center;">
            <a href="<?= BASE_URL ?>/auth/login.php" class="auth-back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.code-digit').forEach(function(input, i, all) {
    input.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value && this.nextElementSibling) this.nextElementSibling.focus();
        updateCode();
    });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !this.value && this.previousElementSibling) {
            this.previousElementSibling.focus();
            this.previousElementSibling.value = '';
            updateCode();
        }
    });
    input.addEventListener('paste', function(e) {
        e.preventDefault();
        var paste = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
        for (var j = 0; j < paste.length && j < all.length; j++) { all[j].value = paste[j]; }
        if (paste.length > 0) all[Math.min(paste.length, all.length) - 1].focus();
        updateCode();
    });
});
function updateCode() {
    var code = '';
    document.querySelectorAll('.code-digit').forEach(function(d) { code += d.value; });
    document.getElementById('codeValue').value = code;
}

function setupToggle(btnId, inputId) {
    document.getElementById(btnId).addEventListener('click', function() {
        var inp = document.getElementById(inputId);
        var icon = this.querySelector('i');
        if (inp.type === 'password') { inp.type = 'text'; icon.classList.replace('fa-eye', 'fa-eye-slash'); }
        else { inp.type = 'password'; icon.classList.replace('fa-eye-slash', 'fa-eye'); }
    });
}
if (document.getElementById('toggleNewPw')) setupToggle('toggleNewPw', 'newPassword');
if (document.getElementById('toggleConfirmPw')) setupToggle('toggleConfirmPw', 'confirmPassword');
</script>
</body>
</html>