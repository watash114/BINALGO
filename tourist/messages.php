<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/Message.php';
require_once __DIR__ . '/../includes/classes/Notification.php';
require_once __DIR__ . '/../includes/classes/MessageSettings.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();
$user_id = $_SESSION['user_id'];
$msgModel = new Message();
$notif = new Notification();
$msgSettings = new MessageSettings();
$mySettings = $msgSettings->get($user_id);

// ─── POST Handling ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? null)) {
        flash_message('error', 'Invalid security token.');
        redirect('/tourist/messages.php');
    }

    if (isset($_POST['send_message'])) {
        $receiver_id = (int)($_POST['receiver_id'] ?? 0);
        $message_text = trim($_POST['message'] ?? '');
        $reply_to = !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;

        if ($receiver_id > 0 && $msgSettings->isBlocked($receiver_id, $user_id)) {
            flash_message('error', 'You cannot message this user.');
            redirect('/tourist/messages.php?chat=' . $receiver_id);
        }

        if ($receiver_id > 0 && $message_text !== '') {
            $msgId = $msgModel->sendMessage($user_id, $receiver_id, $message_text, null, $reply_to);
            if ($msgId) {
                $notif->notifyNewMessage($receiver_id, $user['name'], substr($message_text, 0, 60), $msgId);
            }
        }
        redirect('/tourist/messages.php?chat=' . $receiver_id);
    }

    if (isset($_POST['delete_message'])) {
        $mid = (int)($_POST['message_id'] ?? 0);
        $msgModel->softDelete($mid, $user_id);
        $chat = (int)($_POST['chat_with'] ?? 0);
        redirect('/tourist/messages.php?chat=' . $chat);
    }

    if (isset($_POST['delete_conversation'])) {
        $other_id = (int)($_POST['other_user_id'] ?? 0);
        $mode = $_POST['delete_mode'] ?? 'me';
        $msgModel->deleteConversation($user_id, $other_id, $mode);
        flash_message('success', 'Conversation deleted.');
        redirect('/tourist/messages.php');
    }
}

// ─── Data Loading ────────────────────────────────────────────────

if (isset($_GET['mark_read']) && verify_token($_GET['csrf'] ?? '')) {
    $sender_id = (int) $_GET['mark_read'];
    $msgModel->markConversationAsRead($sender_id, $user_id);
    redirect('/tourist/messages.php' . (!empty($_GET['chat']) ? '?chat=' . (int)$_GET['chat'] : ''));
}

$chat_user_id = isset($_GET['chat']) ? (int) $_GET['chat'] : 0;
$conversations = $msgModel->getConversations($user_id);

$active_chat = null;
$messages = [];
if ($chat_user_id > 0) {
    foreach ($conversations as $c) {
        if ($c['other_user_id'] == $chat_user_id) {
            $active_chat = $c;
            break;
        }
    }
    if (!$active_chat) {
        $ac_stmt = $db->prepare("SELECT id, name, avatar, email FROM users WHERE id = :id AND role IN ('staff','admin')");
        $ac_stmt->execute([':id' => $chat_user_id]);
        $ac = $ac_stmt->fetch();
        if ($ac) {
            $active_chat = ['other_user_id' => $ac['id'], 'other_user_name' => $ac['name'], 'other_user_avatar' => $ac['avatar']];
            $msgModel->syncConversation($user_id, $chat_user_id, '');
        }
    }
    if ($active_chat) {
        $msgModel->markConversationAsRead($chat_user_id, $user_id);
        $messages = $msgModel->getConversation($user_id, $chat_user_id);
    }
}

$total_unread = $msgModel->getUnreadCount($user_id);

render_page('tourist', 'messages.php', 'Messages', function() use ($conversations, $active_chat, $messages, $chat_user_id, $total_unread, $user_id, $msgModel, $mySettings) {
?>
<style>
#contextMenu { position:fixed; z-index:9999; min-width:160px; display:none; }
#contextMenu .dropdown-menu { display:block; position:static !important; }
.reply-preview {
    background: var(--card-bg, #f1f5f9); border-left:3px solid #3b82f6; padding:8px 14px;
    font-size:0.8rem; border-radius:8px; display:none; align-items:center; gap:8px;
    margin: 0 16px 4px; border: 1px solid var(--border-color, #e2e8f0);
}
.reply-preview .reply-info { flex-grow:1; overflow:hidden; }
.reply-preview .reply-name { font-weight:600; color:#3b82f6; font-size:0.78rem; }
.reply-preview .reply-text { color:var(--text-muted, #64748b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.replied-msg {
    background:var(--replied-bg, rgba(0,0,0,0.04)); border-left:3px solid #3b82f6;
    padding:5px 10px; border-radius:4px; margin-bottom:4px; font-size:0.72rem; cursor:pointer;
}
.replied-msg .replied-name { font-weight:600; color:#3b82f6; }
.conv-menu { position:relative; }
.conv-menu .dropdown-menu { position:absolute; right:0; left:auto !important; top:100%; }
@keyframes fadeIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
.message-bubble { animation:fadeIn 0.2s ease; }
.max-width-75 { max-width:75%; }

.msg-layout {
    display: flex; flex-direction: column; height: calc(100vh - 160px); min-height: 500px;
    background: var(--card-bg, #fff); border-radius: 16px; overflow: hidden;
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 4px 24px rgba(0,0,0,0.04);
}
.msg-sidebar {
    display: flex; flex-direction: column; border-right: 1px solid var(--border-color, #e2e8f0);
    background: var(--card-bg, #fff); width: 340px; flex-shrink: 0;
}
.msg-sidebar-header {
    padding: 16px 18px; border-bottom: 1px solid var(--border-color, #e2e8f0);
    display: flex; align-items: center; justify-content: space-between;
}
.msg-sidebar-header h6 { margin: 0; font-weight: 700; font-size: 0.95rem; color: var(--text-primary, #1e293b); }
.msg-search {
    padding: 10px 14px; border-bottom: 1px solid var(--border-color, #f1f5f9);
}
.msg-search input {
    width: 100%; border: 1px solid var(--border-color, #e2e8f0); border-radius: 10px;
    padding: 9px 14px 9px 36px; font-size: 0.85rem; background: var(--bg-secondary, #f8fafc);
    color: var(--text-primary, #1e293b); outline: none; transition: all 0.2s;
}
.msg-search input:focus { border-color: var(--primary, #0c6e5e); box-shadow: 0 0 0 3px rgba(12,110,94,0.08); }
.msg-search { position: relative; }
.msg-search i { position: absolute; left: 26px; top: 50%; transform: translateY(-50%); color: var(--text-muted, #94a3b8); font-size: 0.82rem; }

.conv-list { flex: 1; overflow-y: auto; }
.conv-list::-webkit-scrollbar { width: 4px; }
.conv-list::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 4px; }

.conv-item {
    display: flex; align-items: center; gap: 12px; padding: 12px 18px;
    cursor: pointer; transition: all 0.15s; border-bottom: 1px solid var(--border-color, #f8fafc);
    position: relative; text-decoration: none; color: inherit;
}
.conv-item:hover { background: var(--hover-bg, #f1f5f9); }
.conv-item.active {
    background: linear-gradient(135deg, rgba(12,110,94,0.08), rgba(12,110,94,0.03));
    border-left: 3px solid var(--primary, #0c6e5e);
}
.conv-avatar { position: relative; flex-shrink: 0; }
.conv-avatar img { width: 46px; height: 46px; border-radius: 14px; object-fit: cover; }
.conv-avatar .online-badge {
    position: absolute; bottom: 0; right: 0; width: 12px; height: 12px;
    background: #22c55e; border: 2px solid var(--card-bg, #fff); border-radius: 50%;
}
.conv-info { flex: 1; min-width: 0; }
.conv-info-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px; }
.conv-name { font-weight: 600; font-size: 0.88rem; color: var(--text-primary, #1e293b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-time { font-size: 0.68rem; color: var(--text-muted, #94a3b8); white-space: nowrap; flex-shrink: 0; }
.conv-preview { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.conv-last-msg { font-size: 0.8rem; color: var(--text-muted, #64748b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.conv-unread {
    width: 20px; height: 20px; border-radius: 50%; background: var(--primary, #0c6e5e);
    color: #fff; font-size: 0.65rem; font-weight: 700; display: flex; align-items: center;
    justify-content: center; flex-shrink: 0;
}
.conv-delete {
    position: absolute; top: 8px; right: 8px; width: 22px; height: 22px;
    border-radius: 6px; border: none; background: transparent; color: var(--text-muted, #94a3b8);
    display: none; align-items: center; justify-content: center; cursor: pointer;
    font-size: 0.7rem; transition: all 0.15s;
}
.conv-item:hover .conv-delete { display: flex; }
.conv-delete:hover { background: rgba(239,68,68,0.1); color: #ef4444; }

.msg-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.msg-header {
    padding: 14px 20px; border-bottom: 1px solid var(--border-color, #e2e8f0);
    display: flex; align-items: center; justify-content: space-between;
    background: var(--card-bg, #fff);
}
.msg-header-info { display: flex; align-items: center; gap: 12px; }
.msg-header-info img { width: 40px; height: 40px; border-radius: 12px; object-fit: cover; }
.msg-header-name { font-weight: 600; font-size: 0.92rem; color: var(--text-primary, #1e293b); }
.msg-header-status { font-size: 0.75rem; color: var(--text-muted, #94a3b8); display: flex; align-items: center; gap: 4px; }
.msg-header-status .online-dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; display: inline-block; }
.msg-header-actions { display: flex; gap: 6px; }
.msg-header-actions .btn {
    width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center;
    justify-content: center; border: 1px solid var(--border-color, #e2e8f0); background: transparent;
    color: var(--text-muted, #64748b); font-size: 0.85rem; transition: all 0.2s;
}
.msg-header-actions .btn:hover { background: var(--hover-bg, #f1f5f9); color: var(--text-primary, #1e293b); border-color: var(--border-color, #cbd5e1); }

.msg-body {
    flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 4px;
    background: var(--bg-secondary, #f8fafc);
}
.msg-body::-webkit-scrollbar { width: 4px; }
.msg-body::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 4px; }

.msg-date-divider {
    text-align: center; margin: 12px 0; position: relative;
}
.msg-date-divider span {
    background: var(--bg-secondary, #f8fafc); padding: 4px 12px;
    font-size: 0.7rem; color: var(--text-muted, #94a3b8); font-weight: 500;
    border-radius: 20px; border: 1px solid var(--border-color, #e2e8f0); position: relative; z-index: 1;
}

.msg-bubble-row { display: flex; gap: 8px; max-width: 70%; align-items: flex-end; }
.msg-bubble-row.sent { align-self: flex-end; flex-direction: row-reverse; }
.msg-bubble-row.received { align-self: flex-start; }

.msg-bubble {
    padding: 10px 14px; border-radius: 16px; font-size: 0.88rem; line-height: 1.45;
    word-wrap: break-word; position: relative; transition: background 0.3s;
}
.msg-bubble.sent {
    background: linear-gradient(135deg, var(--primary, #0c6e5e), #10b981);
    color: #fff; border-bottom-right-radius: 4px;
}
.msg-bubble.received {
    background: var(--card-bg, #fff); color: var(--text-primary, #1e293b);
    border: 1px solid var(--border-color, #e2e8f0); border-bottom-left-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.msg-time {
    font-size: 0.62rem; color: rgba(255,255,255,0.6); margin-top: 4px;
    display: flex; align-items: center; gap: 4px;
}
.msg-bubble.received .msg-time { color: var(--text-muted, #94a3b8); }

.msg-input-area {
    padding: 14px 20px; border-top: 1px solid var(--border-color, #e2e8f0);
    display: flex; align-items: flex-end; gap: 10px; background: var(--card-bg, #fff);
}
.msg-input-wrap {
    flex: 1; display: flex; align-items: flex-end; background: var(--bg-secondary, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0); border-radius: 14px; padding: 4px;
    transition: border-color 0.2s;
}
.msg-input-wrap:focus-within { border-color: var(--primary, #0c6e5e); }
.msg-input-wrap textarea {
    flex: 1; border: none; background: transparent; padding: 8px 12px;
    font-size: 0.88rem; color: var(--text-primary, #1e293b); resize: none;
    outline: none; max-height: 100px; line-height: 1.4; font-family: inherit;
}
.msg-input-wrap textarea::placeholder { color: var(--text-muted, #94a3b8); }
.msg-send-btn {
    width: 44px; height: 44px; border-radius: 12px; border: none;
    background: linear-gradient(135deg, var(--primary, #0c6e5e), #10b981);
    color: #fff; display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.2s; font-size: 1rem; flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(12,110,94,0.2);
}
.msg-send-btn:hover { transform: scale(1.05); box-shadow: 0 4px 12px rgba(12,110,94,0.3); }

.empty-state {
    flex: 1; display: flex; align-items: center; justify-content: center;
    background: var(--bg-secondary, #f8fafc);
}
.empty-state-inner { text-align: center; }
.empty-state-icon {
    width: 80px; height: 80px; border-radius: 50%; margin: 0 auto 20px;
    background: linear-gradient(135deg, rgba(12,110,94,0.08), rgba(12,110,94,0.03));
    display: flex; align-items: center; justify-content: center;
}
.empty-state-icon i { font-size: 2rem; color: var(--primary, #0c6e5e); opacity: 0.5; }
.empty-state h5 { font-weight: 700; font-size: 1.05rem; color: var(--text-primary, #1e293b); margin-bottom: 6px; }
.empty-state p { font-size: 0.85rem; color: var(--text-muted, #94a3b8); margin: 0; }

.empty-conv {
    padding: 40px 20px; text-align: center; flex: 1; display: flex;
    flex-direction: column; align-items: center; justify-content: center;
}
.empty-conv-icon {
    width: 64px; height: 64px; border-radius: 16px; margin: 0 auto 14px;
    background: linear-gradient(135deg, rgba(12,110,94,0.1), rgba(12,110,94,0.04));
    display: flex; align-items: center; justify-content: center;
}
.empty-conv-icon i { font-size: 1.4rem; color: var(--primary, #0c6e5e); opacity: 0.5; }
.empty-conv h6 { font-weight: 600; color: var(--text-primary, #1e293b); margin-bottom: 4px; }
.empty-conv p { font-size: 0.8rem; color: var(--text-muted, #94a3b8); margin: 0; }

@media (max-width: 767px) {
    .msg-sidebar { width: 100%; position: absolute; inset: 0; z-index: 10; }
    .msg-sidebar.hidden-mobile { display: none; }
    .msg-main.hidden-mobile { display: none; }
}
</style>

<div class="msg-layout">
    <!-- Sidebar -->
    <div class="msg-sidebar">
        <div class="msg-sidebar-header">
            <h6><i class="fas fa-comments me-2" style="color:var(--primary, #0c6e5e);"></i>Messages
                <?php if ($total_unread > 0): ?>
                    <span class="conv-unread" style="display:inline-flex;width:auto;height:auto;padding:0 6px;margin-left:4px;font-size:0.65rem;"><?= $total_unread ?></span>
                <?php endif; ?>
            </h6>
            <a href="<?= BASE_URL ?>/tourist/message_settings.php" class="btn" style="width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border-color, #e2e8f0);background:transparent;color:var(--text-muted, #64748b);font-size:0.75rem;" title="Settings"><i class="fas fa-cog"></i></a>
        </div>
        <div class="msg-search">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search conversations..." id="convSearch" oninput="filterConversations(this.value)">
        </div>
        <div class="conv-list" id="convList">
            <?php if (empty($conversations)): ?>
                <div class="empty-conv">
                    <div class="empty-conv-icon"><i class="fas fa-comments"></i></div>
                    <h6>No conversations yet</h6>
                    <p>Book a tour to start chatting.</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $c): ?>
                    <?php
                    $is_active = $c['other_user_id'] == $chat_user_id;
                    $initial = strtoupper(substr($c['other_user_name'], 0, 1));
                    $avatar_color = ['#0c6e5e','#3b82f6','#8b5cf6','#f59e0b','#ef4444'][($c['other_user_id'] % 5)];
                    ?>
                    <a href="?chat=<?= $c['other_user_id'] ?>" class="conv-item <?= $is_active ? 'active' : '' ?>" data-name="<?= strtolower($c['other_user_name']) ?>">
                        <div class="conv-avatar">
                            <?php if (!empty($c['other_user_avatar'])): ?>
                                <img src="<?= get_avatar_url(['id'=>$c['other_user_id'],'name'=>$c['other_user_name'],'email'=>'', 'avatar'=>$c['other_user_avatar']]) ?>" alt="">
                            <?php else: ?>
                                <div style="width:46px;height:46px;border-radius:14px;background:<?= $avatar_color ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;"><?= $initial ?></div>
                            <?php endif; ?>
                            <div class="online-badge"></div>
                        </div>
                        <div class="conv-info">
                            <div class="conv-info-top">
                                <span class="conv-name"><?= sanitize($c['other_user_name']) ?></span>
                                <span class="conv-time"><?= $c['last_activity'] ? time_ago($c['last_activity']) : '' ?></span>
                            </div>
                            <div class="conv-preview">
                                <span class="conv-last-msg"><?= sanitize(truncate($c['last_message'] ?? 'No messages yet', 40)) ?></span>
                                <?php if ($c['unread_count'] > 0 && !$is_active): ?>
                                    <span class="conv-unread"><?= $c['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="conv-delete" title="Delete" onclick="event.preventDefault();event.stopPropagation();document.getElementById('deleteOtherName').textContent='<?= sanitize($c['other_user_name']) ?>';document.getElementById('deleteOtherId').value='<?= $c['other_user_id'] ?>';new bootstrap.Modal(document.getElementById('deleteConvModal')).show();"><i class="fas fa-times"></i></button>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Chat Area -->
    <div class="msg-main">
        <?php if ($active_chat): ?>
            <?php
            $active_initial = strtoupper(substr($active_chat['other_user_name'], 0, 1));
            $active_color = ['#0c6e5e','#3b82f6','#8b5cf6','#f59e0b','#ef4444'][($active_chat['other_user_id'] % 5)];
            ?>
            <div class="msg-header">
                <div class="msg-header-info">
                    <?php if (!empty($active_chat['other_user_avatar'])): ?>
                        <img src="<?= get_avatar_url(['id'=>$active_chat['other_user_id'],'name'=>$active_chat['other_user_name'],'email'=>'', 'avatar'=>$active_chat['other_user_avatar']]) ?>" alt="">
                    <?php else: ?>
                        <div style="width:40px;height:40px;border-radius:12px;background:<?= $active_color ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.9rem;"><?= $active_initial ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="msg-header-name"><?= sanitize($active_chat['other_user_name']) ?></div>
                        <div class="msg-header-status"><span class="online-dot"></span> Online</div>
                    </div>
                </div>
                <div class="msg-header-actions">
                    <button class="btn" title="Voice Call" onclick="startCall('voice')"><i class="fas fa-phone"></i></button>
                    <button class="btn" title="Video Call" onclick="startCall('video')"><i class="fas fa-video"></i></button>
                    <button type="button" class="btn" title="Delete"
                        data-bs-toggle="modal" data-bs-target="#deleteConvModal"
                        data-other-id="<?= $active_chat['other_user_id'] ?>"
                        data-other-name="<?= sanitize($active_chat['other_user_name']) ?>">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>

            <div class="msg-body" id="chatMessages">
                <?php if (empty($messages)): ?>
                    <div class="empty-state">
                        <div class="empty-state-inner">
                            <div class="empty-state-icon"><i class="fas fa-paper-plane"></i></div>
                            <h5>Start a conversation</h5>
                            <p>Send a message to <?= sanitize($active_chat['other_user_name']) ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    $last_date = '';
                    foreach ($messages as $m):
                        $is_me = ((int)$m['sender_id'] === (int)$user_id);
                        if ($m['is_deleted']) continue;
                        $msg_date = date('M d, Y', strtotime($m['created_at']));
                        if ($msg_date !== $last_date):
                            $last_date = $msg_date;
                    ?>
                        <div class="msg-date-divider"><span><?= $msg_date == date('M d, Y') ? 'Today' : $msg_date ?></span></div>
                    <?php endif; ?>
                    <div class="msg-bubble-row <?= $is_me ? 'sent' : 'received' ?>" data-msg-id="<?= $m['id'] ?>">
                        <div class="msg-bubble <?= $is_me ? 'sent' : 'received' ?>"
                             oncontextmenu="showContextMenu(event, <?= $m['id'] ?>, '<?= addslashes(sanitize($m['message'])) ?>', <?= $is_me ? 'true' : 'false' ?>)">
                            <?php if ($m['reply_id']): ?>
                                <div class="replied-msg <?= $is_me ? 'bg-white bg-opacity-25' : '' ?>" onclick="scrollToMessage(<?= $m['reply_id'] ?>)">
                                    <span class="replied-name"><?= sanitize($m['reply_sender_name'] ?? 'Unknown') ?></span>
                                    <div class="text-truncate"><?= $m['reply_deleted'] ? '[deleted]' : sanitize(truncate($m['reply_message'] ?? '', 60)) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($m['message']): ?>
                                <div style="word-wrap:break-word;"><?= nl2br(sanitize($m['message'])) ?></div>
                            <?php endif; ?>
                            <div class="msg-time">
                                <?= date('g:i A', strtotime($m['created_at'])) ?>
                                <?php if ($is_me && $m['is_read'] && $mySettings['show_read_receipts']): ?>
                                    <i class="fas fa-check-double"></i>
                                <?php elseif ($is_me): ?>
                                    <i class="fas fa-check"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Reply Preview -->
            <div class="reply-preview" id="replyPreview">
                <div class="reply-info">
                    <div class="reply-name" id="replyName"></div>
                    <div class="reply-text" id="replyText"></div>
                </div>
                <button type="button" class="btn btn-sm p-0" style="color:var(--text-muted, #94a3b8);" onclick="cancelReply()"><i class="fas fa-times"></i></button>
            </div>

            <!-- Input Area -->
            <div class="msg-input-area">
                <form method="POST" class="d-flex gap-2 align-items-end" id="messageForm" style="width:100%;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="send_message" value="1">
                    <input type="hidden" name="receiver_id" value="<?= $active_chat['other_user_id'] ?>">
                    <input type="hidden" name="reply_to" id="replyToInput" value="">
                    <div class="msg-input-wrap">
                        <textarea name="message" rows="1" placeholder="Type a message..."
                                  id="msgInput" oninput="autoResize(this)" onkeydown="handleKey(event)"></textarea>
                    </div>
                    <button type="submit" class="msg-send-btn" title="Send"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-inner">
                    <div class="empty-state-icon"><i class="fas fa-comments"></i></div>
                    <h5>Welcome to Messages</h5>
                    <p>Select a conversation to start chatting</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Context Menu -->
<div id="contextMenu">
    <div class="dropdown-menu show">
        <button class="dropdown-item" onclick="replyToMessage()"><i class="fas fa-reply me-2"></i>Reply</button>
        <button class="dropdown-item" onclick="copyMessage()"><i class="fas fa-copy me-2"></i>Copy</button>
        <div class="dropdown-divider"></div>
        <button class="dropdown-item text-danger" id="ctxDeleteBtn" onclick="deleteMessage()"><i class="fas fa-trash me-2"></i>Delete</button>
    </div>
</div>

<script>
const chatEl = document.getElementById('chatMessages');
let lastMsgId = 0;
let pollInterval = null;

if (chatEl) {
    chatEl.scrollTop = chatEl.scrollHeight;
    const msgs = chatEl.querySelectorAll('[data-msg-id]');
    if (msgs.length) {
        lastMsgId = parseInt(msgs[msgs.length - 1].dataset.msgId);
    }
}

function filterConversations(query) {
    const items = document.querySelectorAll('.conv-item');
    const q = query.toLowerCase();
    items.forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = name.includes(q) ? '' : 'none';
    });
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 100) + 'px';
}

function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    const chatWith = <?= $chat_user_id ?: 0 ?>;
    if (!chatWith) return;
    pollInterval = setInterval(function() {
        fetch('<?= BASE_URL ?>/includes/ajax/poll_messages.php?chat_with=' + chatWith + '&last_id=' + lastMsgId + '&t=' + Date.datetime('now'))
            .then(r => r.json())
            .then(data => {
                if (data.new_messages && data.new_messages.length) {
                    data.new_messages.forEach(m => { appendMessage(m); if (m.id > lastMsgId) lastMsgId = m.id; });
                    if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;
                }
            }).catch(() => {});
    }, 3000);
}

function appendMessage(m) {
    const isMe = m.sender_id == <?= $user_id ?>;
    const div = document.createElement('div');
    div.className = 'msg-bubble-row ' + (isMe ? 'sent' : 'received');
    div.dataset.msgId = m.id;
    let replyHtml = '';
    if (m.reply_id) {
        replyHtml = '<div class="replied-msg ' + (isMe ? 'bg-white bg-opacity-25' : '') + '" onclick="scrollToMessage(' + m.reply_id + ')">' +
            '<span class="replied-name">' + escapeHtml(m.reply_sender || 'Unknown') + '</span>' +
            '<div class="text-truncate">' + (m.reply_deleted ? '[deleted]' : escapeHtml((m.reply_message || '').substring(0, 60))) + '</div></div>';
    }
    div.innerHTML = '<div class="msg-bubble ' + (isMe ? 'sent' : 'received') + '" oncontextmenu="showContextMenu(event,' + m.id + ',\'' + escapeHtml(m.message || '').replace(/'/g, "\\'") + '\',' + (isMe ? 'true' : 'false') + ')">' +
        replyHtml + (m.message ? '<div style="word-wrap:break-word;">' + escapeHtml(m.message) + '</div>' : '') +
        '<div class="msg-time">' + formatTime(m.created_at) + (isMe && m.is_read ? ' <i class="fas fa-check-double"></i>' : (isMe ? ' <i class="fas fa-check"></i>' : '')) + '</div></div>';
    if (chatEl) chatEl.appendChild(div);
}

function escapeHtml(text) { const d = document.createElement('div'); d.textContent = text; return d.innerHTML; }
function formatTime(ts) {
    const d = new Date(ts.replace(' ', 'T') + 'Z');
    let h = d.getUTCHours(), m = d.getUTCMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12; h = h ? h : 12;
    return h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}

<?php if ($chat_user_id > 0): ?>
startPolling();
<?php endif; ?>

let ctxMsgId = null, ctxMsgText = '', ctxIsMine = false;
function showContextMenu(e, msgId, msgText, isMine) {
    e.preventDefault();
    ctxMsgId = msgId; ctxMsgText = msgText; ctxIsMine = isMine;
    const menu = document.getElementById('contextMenu');
    document.getElementById('ctxDeleteBtn').style.display = isMine ? '' : 'none';
    menu.style.display = 'block';
    menu.style.left = Math.min(e.clientX, window.innerWidth - 180) + 'px';
    menu.style.top = Math.min(e.clientY, window.innerHeight - 120) + 'px';
}
document.addEventListener('click', function(e) { if (!e.target.closest('#contextMenu')) document.getElementById('contextMenu').style.display = 'none'; });

function replyToMessage() {
    document.getElementById('replyPreview').style.display = 'flex';
    document.getElementById('replyName').textContent = ctxIsMine ? 'You' : '<?= addslashes(sanitize($active_chat['other_user_name'] ?? '')) ?>';
    document.getElementById('replyText').textContent = ctxMsgText.substring(0, 80) + (ctxMsgText.length > 80 ? '...' : '');
    document.getElementById('replyToInput').value = ctxMsgId;
    document.getElementById('msgInput').focus();
    document.getElementById('contextMenu').style.display = 'none';
}
function cancelReply() { document.getElementById('replyPreview').style.display = 'none'; document.getElementById('replyToInput').value = ''; }
function copyMessage() { navigator.clipboard.writeText(ctxMsgText).catch(() => {}); document.getElementById('contextMenu').style.display = 'none'; }
function deleteMessage() {
    if (confirm('Delete this message?')) {
        const form = document.createElement('form'); form.method = 'POST'; form.style.display = 'none';
        form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>"><input type="hidden" name="delete_message" value="1"><input type="hidden" name="message_id" value="' + ctxMsgId + '"><input type="hidden" name="chat_with" value="<?= $chat_user_id ?>">';
        document.body.appendChild(form); form.submit();
    }
    document.getElementById('contextMenu').style.display = 'none';
}
function scrollToMessage(msgId) {
    const el = document.querySelector('[data-msg-id="' + msgId + '"]');
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.style.transition = 'background 0.5s'; el.style.background = '#fef3c7'; setTimeout(() => el.style.background = '', 1500); }
}

function handleKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.target.closest('form').submit(); } }

document.getElementById('deleteConvModal')?.addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('deleteOtherId').value = btn.dataset.otherId;
    document.getElementById('deleteOtherName').textContent = btn.dataset.otherName;
});
</script>

<!-- Delete Conversation Modal -->
<div class="modal fade" id="deleteConvModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="delete_conversation" value="1">
                <input type="hidden" name="other_user_id" id="deleteOtherId" value="">
                <input type="hidden" name="delete_mode" value="me">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i>Delete Conversation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this conversation with <strong id="deleteOtherName"></strong>?</p>
                    <p class="text-muted small mb-0">This action cannot be undone. The conversation will be removed from your inbox only.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-1"></i>Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Call Modal -->
<div class="modal fade" id="callModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">
            <div id="callIcon"><i class="fas fa-phone fa-3x mb-2" style="color:#f59e0b;"></i></div>
            <h5 id="callTitle">Voice Call</h5>
            <p class="text-muted mb-1" id="callStatus">Calling...</p>
            <div class="mb-3" id="callTimer" style="font-size:1.5rem;font-weight:bold;"></div>
            <div id="callBtnGroup" class="d-flex justify-content-center gap-3"></div>
        </div>
    </div>
</div>

<?php }); ?>
