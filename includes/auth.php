<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/db_compat.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/ActivityLog.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function is_logged_in(): bool
{
    start_session();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function is_admin(): bool
{
    start_session();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_staff(): bool
{
    start_session();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

function is_tourist(): bool
{
    start_session();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'tourist';
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash_message('error', 'Please log in to access this page.');
        redirect('/auth/login.php');
    }
}

function require_role(string $role): void
{
    require_login();
    start_session();
    if ($_SESSION['role'] !== $role) {
        flash_message('error', 'You do not have permission to access this page.');
        redirect('/');
    }
}

function require_any_role(array $roles): void
{
    require_login();
    start_session();
    if (!in_array($_SESSION['role'], $roles)) {
        flash_message('error', 'You do not have permission to access this page.');
        redirect('/');
    }
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    $user = new User();
    return $user->findById($_SESSION['user_id']);
}

function login(string $email, string $password): array
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    if (isset($user['status']) && $user['status'] !== 'active') {
        if ($user['status'] === 'pending') {
            return ['success' => false, 'message' => 'Your account is pending verification.'];
        }
        return ['success' => false, 'message' => 'Your account is ' . $user['status'] . '.'];
    }

    start_session();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['email'] = $user['email'];

    if (session_regenerate_id(true)) {
        // Session regenerated
    }

    $login_ip = get_user_ip();
    $browser = get_client_browser();
    $os = get_client_os();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    try {
        $db->prepare("UPDATE users SET last_login_ip = :ip, last_active_ip = :ip2, last_login_at = db_now(), login_count = login_count + 1, last_user_agent = :ua WHERE id = :uid")
            ->execute([':ip' => $login_ip, ':ip2' => $login_ip, ':ua' => $ua, ':uid' => $user['id']]);
    } catch (\PDOException $e) {
        error_log("Login audit update failed: " . $e->getMessage());
    }

    ActivityLog::log($user['id'], 'login', "User logged in from {$login_ip} ({$browser}/{$os})");

    return ['success' => true, 'user' => $user];
}

function logout(): void
{
    start_session();

    if (isset($_SESSION['user_id'])) {
        $logout_ip = get_user_ip();
        $db = Database::getInstance()->getConnection();
        try {
            $db->prepare("UPDATE users SET last_active_ip = :ip WHERE id = :uid")
                ->execute([':ip' => $logout_ip, ':uid' => $_SESSION['user_id']]);
        } catch (\PDOException $e) {
            error_log("Logout audit update failed: " . $e->getMessage());
        }
        ActivityLog::log($_SESSION['user_id'], 'logout', 'User logged out from ' . $logout_ip);
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
    redirect('/auth/login.php');
}

function register(array $data): array
{
    if (empty($data['name']) || empty($data['email']) || empty($data['password']) || empty($data['role'])) {
        return ['success' => false, 'message' => 'All fields are required.'];
    }

    $valid_roles = ['admin', 'staff', 'tourist'];
    if (!in_array($data['role'], $valid_roles)) {
        return ['success' => false, 'message' => 'Invalid role specified.'];
    }

    if ($data['role'] === 'staff' || $data['role'] === 'admin') {
        if (!can_register_staff()) {
            return ['success' => false, 'message' => 'Staff registration limit reached.'];
        }
    }

    $user = new User();
    $existing = $user->findByEmail($data['email']);
    if ($existing) {
        return ['success' => false, 'message' => 'Email already registered.'];
    }

    if (strlen($data['password']) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
    }

    $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $data['email'])[0]));

    $user_data = [
        'username'        => $username,
        'name'            => sanitize($data['name']),
        'email'           => sanitize($data['email']),
        'password'        => $hashed_password,
        'role'            => $data['role'],
        'gender'          => $data['gender'] ?? 'male',
        'age'             => $data['age'] ?? 18,
        'phone'           => sanitize($data['phone'] ?? ''),
        'status'          => ($data['role'] === 'admin') ? 'active' : 'pending',
        'created_at'      => date('Y-m-d H:i:s'),
    ];

    $new_id = $user->create($user_data);

    if ($new_id) {
        ActivityLog::log($new_id, 'register', 'New user registered as ' . $data['role']);

        require_once __DIR__ . '/classes/Notification.php';
        $notif = new Notification();
        $dbConn = Database::getInstance()->getConnection();
        $adminIds = array_column(
            $dbConn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(),
            'id'
        );
        foreach ($adminIds as $adminId) {
            $notif->create([
                'user_id' => $adminId,
                'title'   => 'New User Registration',
                'message' => "{$data['name']} ({$data['email']}) has registered as {$data['role']}. Please review and activate their account.",
                'type'    => 'registration',
                'link'    => '/admin/users.php',
            ]);
        }

        return ['success' => true, 'user_id' => $new_id];
    }

    return ['success' => false, 'message' => 'Registration failed. Please try again.'];
}

function can_register_staff(): bool
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'staff'");
    $result = $stmt->fetch();
    return $result['count'] < 3;
}

function has_pending_verification(int $user_id): bool
{
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id FROM id_verifications WHERE user_id = :user_id AND status = 'pending' LIMIT 1");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetch() !== false;
}
