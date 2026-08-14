<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../classes/Message.php';

start_session();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$chat_with = (int)($_GET['chat_with'] ?? 0);
$last_id = (int)($_GET['last_id'] ?? 0);
$msgModel = new Message();

if ($chat_with > 0) {
    $messages = $msgModel->getConversation($user_id, $chat_with);
    $newMessages = [];
    foreach ($messages as $m) {
        if ($m['id'] > $last_id && !$m['is_deleted']) {
            $newMessages[] = [
                'id'           => $m['id'],
                'sender_id'    => $m['sender_id'],
                'message'      => $m['message'],
                'is_read'      => $m['is_read'],
                'created_at'   => $m['created_at'],
                'sender_name'  => $m['sender_name'],
                'reply_id'     => $m['reply_id'],
                'reply_message'=> $m['reply_message'],
                'reply_sender' => $m['reply_sender_name'],
                'reply_deleted'=> $m['reply_deleted'],
            ];
        }
    }

    $msgModel->markConversationAsRead($chat_with, $user_id);

    $unread = $msgModel->getUnreadCount($user_id);
    $convos = $msgModel->getConversations($user_id);
    $conversations = [];
    foreach ($convos as $c) {
        $conversations[] = [
            'other_user_id' => $c['other_user_id'],
            'unread_count'  => $c['unread_count'],
            'last_message'  => $c['last_message'],
            'last_activity' => $c['last_activity'],
        ];
    }

    echo json_encode([
        'new_messages'  => $newMessages,
        'unread_total'  => $unread,
        'conversations' => $conversations,
    ]);
} else {
    $unread = $msgModel->getUnreadCount($user_id);
    $convos = $msgModel->getConversations($user_id);
    $conversations = [];
    foreach ($convos as $c) {
        $conversations[] = [
            'other_user_id' => $c['other_user_id'],
            'unread_count'  => $c['unread_count'],
            'last_message'  => $c['last_message'],
            'last_activity' => $c['last_activity'],
        ];
    }
    echo json_encode([
        'new_messages'  => [],
        'unread_total'  => $unread,
        'conversations' => $conversations,
    ]);
}
