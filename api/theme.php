<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
start_session();

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$theme = $_POST['theme'] ?? 'light';

if (!in_array($theme, ['light', 'dark', 'system'])) {
    $theme = 'light';
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("UPDATE users SET theme = :theme WHERE id = :uid");
$stmt->execute([':theme' => $theme, ':uid' => $user_id]);

echo json_encode(['success' => true, 'theme' => $theme]);
