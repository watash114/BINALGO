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
$action = $_GET['action'] ?? 'count';
$db = Database::getInstance()->getConnection();

if ($action === 'count') {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = :uid AND is_read = 0");
    $stmt->execute([':uid' => $user_id]);
    $count = (int) $stmt->fetch()['count'];
    echo json_encode(['count' => $count]);
} elseif ($action === 'list') {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 15");
    $stmt->execute([':uid' => $user_id]);
    $notifications = $stmt->fetchAll();
    echo json_encode($notifications);
} elseif ($action === 'mark_all_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0");
    $stmt->execute([':uid' => $user_id]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Invalid action']);
}
