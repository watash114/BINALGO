<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
start_session();

if (is_logged_in()) {
    $role = $_SESSION['role'] ?? 'tourist';
    header("Location: " . BASE_URL . "/{$role}/index.php");
    exit;
}

$is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$errors = [];
$email = '';

if (is_post()) {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid or expired token. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $errors[] = 'Email and password are required.';
        } else {
            $result = login($email, $password);
            if ($result['success']) {
                $user_role = $result['user']['role'];

                if (!empty($_POST['remember'])) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), session_id(), time() + 60 * 60 * 24 * 30, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                }

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'redirect' => BASE_URL . "/{$user_role}/index.php"]);
                    exit;
                }
                header("Location: " . BASE_URL . "/{$user_role}/index.php");
                exit;
            } else {
                $errors[] = $result['message'];
            }
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => $errors[0] ?? 'Unable to sign you in. Please try again.', 'errors' => $errors]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title>Login | BINALGO</title>
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
                <img src="<?= BASE_URL ?>/assets/images/binalgo-logo.svg" alt="BINALGO" style="width:48px;height:48px;border-radius:8px;">
            </div>
            <h4>Welcome Back</h4>
            <p>Sign in to your account</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="auth-banner error show" role="alert">
                <i class="auth-banner-icon fas fa-circle-exclamation"></i>
                <span class="auth-banner-text"><?= sanitize($errors[0]) ?></span>
                <button type="button" class="auth-banner-close" aria-label="Dismiss"><i class="fas fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <div class="auth-banner error" id="loginBanner" role="alert" aria-live="polite">
            <i class="auth-banner-icon fas fa-circle-exclamation"></i>
            <span class="auth-banner-text"></span>
            <button type="button" class="auth-banner-close" aria-label="Dismiss"><i class="fas fa-xmark"></i></button>
        </div>

        <form method="POST" action="" id="loginForm" novalidate>
            <?= csrf_field() ?>

            <div class="auth-input-group">
                <label for="email" class="auth-input-label"><i class="fas fa-envelope"></i>Email Address</label>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="auth-input" id="email" name="email"
                           value="<?= sanitize($email) ?>" placeholder="you@example.com" autocomplete="email"
                           aria-describedby="emailMsg">
                    <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="auth-field-msg" id="emailMsg" aria-live="polite">
                    <i class="fas fa-circle-exclamation"></i><span></span>
                </div>
            </div>

            <div class="auth-input-group">
                <div class="d-flex justify-content-between align-items-center">
                    <label for="password" class="auth-input-label mb-0"><i class="fas fa-lock"></i>Password</label>
                    <a href="<?= BASE_URL ?>/auth/forgot_password.php" class="auth-forgot">Forgot password?</a>
                </div>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" class="auth-input has-toggle" id="password" name="password"
                           placeholder="Enter your password" autocomplete="current-password"
                           aria-describedby="passwordMsg">
                    <button class="auth-input-action" type="button" id="togglePassword" tabindex="-1" aria-label="Show password" aria-pressed="false">
                        <i class="fas fa-eye"></i>
                    </button>
                    <span class="auth-validity" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="auth-field-msg" id="passwordMsg" aria-live="polite">
                    <i class="fas fa-circle-exclamation"></i><span></span>
                </div>
            </div>

            <div class="auth-remember">
                <label class="auth-check">
                    <input type="checkbox" id="remember" name="remember">
                    <span class="auth-check-mark"><i class="fas fa-check"></i></span>
                    <span class="auth-check-text">Remember me for 30 days</span>
                </label>
            </div>

            <button type="submit" class="auth-submit-btn auth-ripple" id="loginBtn">
                <span class="auth-submit-text"><i class="fas fa-arrow-right-to-bracket"></i> Log In</span>
                <span class="auth-submit-loading"><span class="auth-spinner"></span> Signing in...</span>
            </button>
        </form>

        <div class="auth-divider">
            <span>or continue with</span>
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
            Don't have an account?
            <a href="<?= BASE_URL ?>/auth/register.php">Register</a>
        </div>

        <div class="auth-version"><span class="dot"></span>SECURE CORE ACCESS V1.2.0</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/auth.js"></script>
<script>
(function () {
    var BASE = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    var form = document.getElementById('loginForm');
    var banner = document.getElementById('loginBanner');
    var btn = document.getElementById('loginBtn');
    var emailInput = document.getElementById('email');
    var passwordInput = document.getElementById('password');

    function msgFor(id) {
        return document.getElementById(id);
    }

    /* Email: real-time (debounced) + blur */
    var checkEmail = function () {
        var v = emailInput.value.trim();
        if (!v) {
            Auth.clearFieldState(emailInput);
            return false;
        }
        var ok = Auth.isValidEmail(v);
        Auth.setFieldState(emailInput, ok, {
            msgEl: msgFor('emailMsg'),
            badText: 'Please enter a valid email address.',
        });
        return ok;
    };
    emailInput.addEventListener('input', Auth.debounce(checkEmail, 250));
    emailInput.addEventListener('blur', checkEmail);

    /* Password: required only */
    var checkPassword = function () {
        var v = passwordInput.value;
        if (!v) {
            Auth.clearFieldState(passwordInput);
            return false;
        }
        Auth.setFieldState(passwordInput, true, {
            msgEl: msgFor('passwordMsg'),
            badText: 'Password is required.',
        });
        return true;
    };
    passwordInput.addEventListener('input', Auth.debounce(checkPassword, 250));
    passwordInput.addEventListener('blur', checkPassword);

    Auth.setupPasswordToggle(passwordInput, document.getElementById('togglePassword'));

    /* Banner dismiss + PHP-rendered error banner */
    document.querySelectorAll('.auth-banner .auth-banner-close').forEach(function (b) {
        b.addEventListener('click', function () { Auth.hideBanner(banner); });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        Auth.hideBanner(banner);
        var okEmail = checkEmail();
        var okPass = checkPassword();
        if (!okEmail || !okPass) {
            form.classList.remove('auth-shake');
            void form.offsetWidth;
            form.classList.add('auth-shake');
            Auth.focusInvalid(form);
            return;
        }

        Auth.setButtonLoading(btn, true);
        var fd = new FormData(form);
        fetch(BASE + '/auth/login.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function (res) {
            var ct = res.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) return res.json();
            return Promise.resolve({ ok: false, message: 'Session already active. Redirecting you...', redirect: res.url });
        }).then(function (data) {
            if (data && data.ok && data.redirect) {
                window.location.assign(data.redirect);
            } else {
                Auth.showBanner(banner, (data && data.message) || 'Sign-in failed. Please try again.');
                Auth.setButtonLoading(btn, false);
            }
        }).catch(function () {
            Auth.showBanner(banner, 'Unable to reach the server. Please check your connection and try again.');
            Auth.setButtonLoading(btn, false);
        });
    });
})();
</script>
</body>
</html>
