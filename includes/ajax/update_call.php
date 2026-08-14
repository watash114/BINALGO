<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../classes/Call.php';
require_once __DIR__ . '/../classes/Notification.php';

start_session();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$token = $input['csrf_token'] ?? '';
if (!verify_token($token)) {
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$call_id = (int)($input['call_id'] ?? 0);
$status = $input['status'] ?? '';
$duration = (int)($input['duration'] ?? 0);

if ($call_id <= 0 || !in_array($status, ['completed', 'missed', 'declined', 'cancelled', 'ongoing'])) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    $callModel = new Call();
    $call = $callModel->findById($call_id);

    if (!$call) {
        echo json_encode(['error' => 'Call not found']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    if ($call['caller_id'] != $user_id && $call['receiver_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $endedAt = ($status !== 'ongoing') ? date('Y-m-d H:i:s') : null;

    $stmt = $db->prepare(
        "UPDATE calls SET status = :status, ended_at = :ended_at, duration = GREATEST(duration, :duration) WHERE id = :id"
    );
    $stmt->execute([
        ':status'   => $status,
        ':ended_at' => $endedAt,
        ':duration' => $duration,
        ':id'       => $call_id,
    ]);

    // Notify the other participant about missed/ended calls
    if (in_array($status, ['missed', 'declined', 'completed'])) {
        $otherUser = ($call['caller_id'] == $user_id) ? $call['receiver_id'] : $call['caller_id'];
        try {
            $notif = new Notification();
            $notif->create([
                'user_id'      => $otherUser,
                'from_user_id' => $user_id,
                'type'         => $status === 'missed' ? 'missed_call' : 'call_ended',
                'message'      => $status === 'missed' ? 'Missed ' . $call['call_type'] . ' call' : $call['call_type'] . ' call ended',
                'link'         => BASE_URL . '/tourist/messages.php',
            ]);
        } catch (\Throwable $e) {
            // Notification is optional
        }
    }

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
