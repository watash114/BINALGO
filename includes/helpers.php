<?php

function redirect(string $url): void
{
    if (strpos($url, 'http') === 0) {
        header("Location: {$url}");
    } else {
        header("Location: " . BASE_URL . $url);
    }
    exit();
}

function flash_message(string $type, string $message): void
{
    start_session();
    $_SESSION['flash_message'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function get_flash_message(): ?array
{
    start_session();
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

function sanitize(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generate_token(): string
{
    start_session();
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    return $token;
}

function verify_token(?string $token): bool
{
    start_session();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }

    if (isset($_SESSION['csrf_token_time'])) {
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
    }

    return true;
}

function format_date(string $date): string
{
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    if ($timestamp === false) return $date;
    return date('M d, Y', $timestamp);
}

function format_datetime(string $datetime): string
{
    if (empty($datetime)) return '';
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return $datetime;
    return date('M d, Y h:i A', $timestamp);
}

function time_ago(string $datetime): string
{
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) {
        return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    }
    if ($diff->m > 0) {
        return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    }
    if ($diff->d > 0) {
        if ($diff->d >= 7) {
            $weeks = floor($diff->d / 7);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        }
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    }
    if ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    }
    if ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    }
    return 'Just now';
}

function upload_file(array $file, string $directory, array $allowed_types = []): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error. Code: ' . $file['error']];
    }

    if (!empty($allowed_types)) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_types)) {
            return ['success' => false, 'message' => 'File type not allowed. Allowed: ' . implode(', ', $allowed_types)];
        }
    }

    $max_size = 10 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size exceeds maximum limit of 10MB.'];
    }

    $upload_dir = __DIR__ . '/../uploads/' . $directory;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $filepath = $upload_dir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'success'  => true,
            'filename' => $filename,
            'path'     => '/uploads/' . $directory . '/' . $filename,
        ];
    }

    return ['success' => false, 'message' => 'Failed to move uploaded file.'];
}

function get_avatar_url(array $user): string
{
    if (!empty($user['avatar']) && file_exists(__DIR__ . '/../uploads/' . $user['avatar'])) {
        return BASE_URL . '/uploads/' . $user['avatar'];
    }

    $name = urlencode($user['name'] ?? 'User');
    $colors = ['4A90A4', '50C878', 'E8A87C', 'D63384', '6F42C1'];
    $color = $colors[crc32($user['email'] ?? '') % count($colors)];

    return "https://ui-avatars.com/api/?name={$name}&background={$color}&color=fff&size=128";
}

function truncate(string $text, int $length = 100): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}

function csrf_field(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
    } else {
        $token = $_SESSION['csrf_token'];
    }
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function dest_image_url(?string $image): string
{
    if (empty($image)) return '';
    if (str_starts_with($image, 'http')) return $image;
    if (str_starts_with($image, '/')) return $image;
    return BASE_URL . '/uploads/destinations/' . $image;
}

function event_image_url(?string $image): string
{
    if (empty($image)) return '';
    if (str_starts_with($image, 'http')) return $image;
    if (str_starts_with($image, '/')) return $image;
    return BASE_URL . '/uploads/events/' . $image;
}

function get_page_title(string $title = ''): string
{
    $base = 'Tour Guide Management System';
    return $title ? $title . ' | ' . $base : $base;
}

function get_user_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = explode(',', $_SERVER[$header])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function get_ip_info(string $ip): array
{
    if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
        return [
            'ip'      => $ip,
            'type'    => 'Local',
            'icon'    => 'fa-home',
            'color'   => '#6b7280',
            'label'   => 'Localhost',
            'browser' => 'N/A',
        ];
    }

    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        $first = (int)$parts[0];
        $second = (int)$parts[1];

        if ($first === 10 || ($first === 172 && $second >= 16 && $second <= 31) || ($first === 192 && $second === 168)) {
            return [
                'ip'      => $ip,
                'type'    => 'Private',
                'icon'    => 'fa-network-wired',
                'color'   => '#f59e0b',
                'label'   => 'Private Network',
                'browser' => 'LAN',
            ];
        }

        if ($first === 100 && $second >= 64 && $second <= 127) {
            return [
                'ip'      => $ip,
                'type'    => 'Carrier',
                'icon'    => 'fa-mobile-alt',
                'color'   => '#06b6d4',
                'label'   => 'Carrier-Grade NAT',
                'browser' => 'Mobile',
            ];
        }

        if ($first >= 1 && $first <= 126) {
            return [
                'ip'      => $ip,
                'type'    => 'Public',
                'icon'    => 'fa-globe',
                'color'   => '#10b981',
                'label'   => 'Public IP',
                'browser' => 'Internet',
            ];
        }
        if ($first === 127) {
            return [
                'ip'      => $ip,
                'type'    => 'Loopback',
                'icon'    => 'fa-redo',
                'color'   => '#6b7280',
                'label'   => 'Loopback',
                'browser' => 'Local',
            ];
        }
        if ($first >= 128 && $first <= 191) {
            return [
                'ip'      => $ip,
                'type'    => 'Public',
                'icon'    => 'fa-globe',
                'color'   => '#10b981',
                'label'   => 'Public IP',
                'browser' => 'Internet',
            ];
        }
        if ($first >= 192 && $first <= 223) {
            return [
                'ip'      => $ip,
                'type'    => 'Public',
                'icon'    => 'fa-globe',
                'color'   => '#10b981',
                'label'   => 'Public IP',
                'browser' => 'Internet',
            ];
        }
    }

    if (strpos($ip, ':') !== false) {
        return [
            'ip'      => $ip,
            'type'    => 'IPv6',
            'icon'    => 'fa-globe',
            'color'   => '#8b5cf6',
            'label'   => 'IPv6 Address',
            'browser' => 'Internet',
        ];
    }

    return [
        'ip'      => $ip,
        'type'    => 'Unknown',
        'icon'    => 'fa-question-circle',
        'color'   => '#6b7280',
        'label'   => 'Unknown',
        'browser' => 'Unknown',
    ];
}

function get_client_browser(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (empty($ua)) return 'Unknown';

    if (strpos($ua, 'Firefox') !== false) return 'Firefox';
    if (strpos($ua, 'Edg') !== false) return 'Edge';
    if (strpos($ua, 'Chrome') !== false) return 'Chrome';
    if (strpos($ua, 'Safari') !== false && strpos($ua, 'Chrome') === false) return 'Safari';
    if (strpos($ua, 'Opera') !== false || strpos($ua, 'OPR') !== false) return 'Opera';
    if (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident') !== false) return 'IE';
    if (strpos($ua, 'Android') !== false) return 'Android Browser';
    if (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) return 'Safari Mobile';

    return 'Other';
}

function get_client_os(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (empty($ua)) return 'Unknown';

    if (strpos($ua, 'Windows') !== false) return 'Windows';
    if (strpos($ua, 'Mac OS X') !== false) return 'macOS';
    if (strpos($ua, 'Linux') !== false) return 'Linux';
    if (strpos($ua, 'Android') !== false) return 'Android';
    if (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) return 'iOS';
    if (strpos($ua, 'CrOS') !== false) return 'Chrome OS';

    return 'Other';
}
