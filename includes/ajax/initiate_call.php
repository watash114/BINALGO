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

$user_id = $_SESSION['user_id'];
$receiver_id = (int)($input['receiver_id'] ?? 0);
$call_type = $input['call_type'] ?? 'voice';

if ($receiver_id <= 0) {
    echo json_encode(['error' => 'Invalid recipient']);
    exit;
}

if (!in_array($call_type, ['voice', 'video'])) {
    $call_type = 'voice';
}

try {
    $call = new Call();
    $callId = $call->create([
        'caller_id'   => $user_id,
        'receiver_id' => $receiver_id,
        'call_type'   => $call_type,
        'status'      => 'ongoing',
        'started_at'  => date('Y-m-d H:i:s'),
    ]);

    if ($callId) {
        // Try to notify receiver
        try {
            $notif = new Notification();
            $notif->create([
                'user_id'      => $receiver_id,
                'from_user_id' => $user_id,
                'type'         => $call_type === 'video' ? 'video_call' : 'voice_call',
                'message'      => 'Incoming ' . $call_type . ' call',
                'link'         => BASE_URL . '/tourist/messages.php',
            ]);
        } catch (\Throwable $e) {
            // Notification is optional
        }

        echo json_encode(['success' => true, 'call_id' => $callId]);
    } else {
        echo json_encode(['error' => 'Failed to create call']);
    }
} catch (\Throwable $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
