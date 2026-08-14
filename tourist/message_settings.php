<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/MessageSettings.php';
require_once __DIR__ . '/../includes/classes/User.php';

$role = $_SESSION['role'] ?? 'tourist';
require_role($role);

$user = current_user();
$user_id = $user['id'];
$settingsModel = new MessageSettings();
$settings = $settingsModel->get($user_id);

// ─── Save Settings ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_token($_POST['csrf_token'] ?? null)) {
    if (isset($_POST['save_settings'])) {
        $settingsModel->update($user_id, [
            'show_read_receipts'    => isset($_POST['show_read_receipts']) ? 1 : 0,
            'show_online_status'    => isset($_POST['show_online_status']) ? 1 : 0,
            'message_notifications' => isset($_POST['message_notifications']) ? 1 : 0,
            'sound_notifications'   => isset($_POST['sound_notifications']) ? 1 : 0,
            'message_preview'       => isset($_POST['message_preview']) ? 1 : 0,
            'who_can_message'       => $_POST['who_can_message'] ?? 'everyone',
        ]);
        flash_message('success', 'Message settings saved.');
        redirect("/{$role}/message_settings.php");
    }

    if (isset($_POST['block_user'])) {
        $blockId = (int)($_POST['block_user_id'] ?? 0);
        if ($blockId > 0 && $blockId !== $user_id) {
            $settingsModel->blockUser($user_id, $blockId);
            flash_message('success', 'User blocked.');
        }
        redirect("/{$role}/message_settings.php");
    }

    if (isset($_POST['unblock_user'])) {
        $unblockId = (int)($_POST['unblock_user_id'] ?? 0);
        if ($unblockId > 0) {
            $settingsModel->unblockUser($user_id, $unblockId);
            flash_message('success', 'User unblocked.');
        }
        redirect("/{$role}/message_settings.php");
    }
}

$blockedUsers = $settingsModel->getBlockedUsers($user_id);

// ─── Search users to block ─────────────────────────────────────
$searchBlock = trim($_GET['search_block'] ?? '');
$searchResults = [];
if ($searchBlock !== '' && mb_strlen($searchBlock) >= 2) {
    $db = Database::getInstance()->getConnection();
    $searchStmt = $db->prepare(
        "SELECT id, name, email, avatar FROM users WHERE id != :uid AND role != :role AND (name LIKE :q OR email LIKE :q2) LIMIT 10"
    );
    $searchStmt->execute([':uid' => $user_id, ':role' => $role, ':q' => "%{$searchBlock}%", ':q2' => "%{$searchBlock}%"]);
    $searchResults = $searchStmt->fetchAll();
}

$pageTitle = 'Message Settings';
$active_page = 'message_settings.php';

render_page($role, $active_page, $pageTitle, function () use ($settings, $blockedUsers, $searchResults, $searchBlock, $role, $user_id) {
?>

<style>
    .settings-section { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 24px; margin-bottom: 20px; }
    .settings-section h5 { font-size: 1rem; font-weight: 700; margin-bottom: 16px; color: var(--text-primary); }
    .setting-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border-color, #f1f5f9); }
    .setting-row:last-child { border-bottom: none; }
    .setting-label { font-weight: 500; font-size: 0.9rem; color: var(--text-primary); }
    .setting-desc { font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }
    .form-switch .form-check-input { width: 3em; height: 1.5em; cursor: pointer; }
    .form-switch .form-check-input:checked { background-color: #10b981; border-color: #10b981; }
    .blocked-user-item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-color, #f1f5f9); }
    .blocked-user-item:last-child { border-bottom: none; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-cog me-2"></i>Message Settings</h4>
    <a href="<?= BASE_URL . '/' . $role ?>/messages.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Messages</a>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">

    <!-- Notification Settings -->
    <div class="settings-section">
        <h5><i class="fas fa-bell me-2 text-primary"></i>Notifications</h5>

        <div class="setting-row">
            <div>
                <div class="setting-label">Message Notifications</div>
                <div class="setting-desc">Receive notifications when you get new messages</div>
            </div>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="message_notifications" value="1" <?= $settings['message_notifications'] ? 'checked' : '' ?>>
            </div>
        </div>

        <div class="setting-row">
            <div>
                <div class="setting-label">Sound Notifications</div>
                <div class="setting-desc">Play a sound when receiving new messages</div>
            </div>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="sound_notifications" value="1" <?= $settings['sound_notifications'] ? 'checked' : '' ?>>
            </div>
        </div>

        <div class="setting-row">
            <div>
                <div class="setting-label">Message Preview</div>
                <div class="setting-desc">Show message content in notification popups</div>
            </div>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="message_preview" value="1" <?= $settings['message_preview'] ? 'checked' : '' ?>>
            </div>
        </div>
    </div>

    <!-- Privacy Settings -->
    <div class="settings-section">
        <h5><i class="fas fa-shield-alt me-2 text-success"></i>Privacy</h5>

        <div class="setting-row">
            <div>
                <div class="setting-label">Read Receipts</div>
                <div class="setting-desc">Let others know when you've read their messages</div>
            </div>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="show_read_receipts" value="1" <?= $settings['show_read_receipts'] ? 'checked' : '' ?>>
            </div>
        </div>

        <div class="setting-row">
            <div>
                <div class="setting-label">Online Status</div>
                <div class="setting-desc">Show when you are online to others</div>
            </div>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="show_online_status" value="1" <?= $settings['show_online_status'] ? 'checked' : '' ?>>
            </div>
        </div>

        <div class="setting-row">
            <div>
                <div class="setting-label">Who Can Message You</div>
                <div class="setting-desc">Control who can start a conversation with you</div>
            </div>
            <select class="form-select form-select-sm w-auto" name="who_can_message">
                <option value="everyone" <?= $settings['who_can_message'] === 'everyone' ? 'selected' : '' ?>>Everyone</option>
                <option value="booked_only" <?= $settings['who_can_message'] === 'booked_only' ? 'selected' : '' ?>>Booked Users Only</option>
            </select>
        </div>
    </div>

    <div class="text-end mb-4">
        <button type="submit" name="save_settings" value="1" class="btn btn-primary px-4"><i class="fas fa-save me-1"></i>Save Settings</button>
    </div>
</form>

<!-- Blocked Users -->
<div class="settings-section">
    <h5><i class="fas fa-ban me-2 text-danger"></i>Blocked Users</h5>

    <?php if (empty($blockedUsers)): ?>
        <p class="text-muted mb-3" style="font-size:0.85rem;">You haven't blocked anyone.</p>
    <?php else: ?>
        <?php foreach ($blockedUsers as $bu): ?>
            <div class="blocked-user-item">
                <img src="<?= get_avatar_url($bu) ?>" class="rounded-circle" width="36" height="36" style="object-fit:cover;">
                <div class="flex-grow-1">
                    <div class="fw-semibold" style="font-size:0.85rem;"><?= sanitize($bu['name']) ?></div>
                    <small class="text-muted"><?= sanitize($bu['email']) ?></small>
                </div>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                    <input type="hidden" name="unblock_user" value="1">
                    <input type="hidden" name="unblock_user_id" value="<?= $bu['id'] ?>">
                    <button type="submit" class="btn btn-outline-success btn-sm">Unblock</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Search to block -->
    <form method="GET" class="mt-3">
        <label class="form-label fw-semibold" style="font-size:0.85rem;">Block a User</label>
        <div class="input-group">
            <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
            <input type="text" class="form-control border-0 bg-light" name="search_block" placeholder="Search by name or email..." value="<?= sanitize($searchBlock) ?>">
        </div>
    </form>

    <?php if (!empty($searchResults)): ?>
        <div class="list-group mt-2">
            <?php foreach ($searchResults as $sr): ?>
                <div class="list-group-item list-group-item-action d-flex align-items-center">
                    <img src="<?= get_avatar_url($sr) ?>" class="rounded-circle me-2" width="32" height="32" style="object-fit:cover;">
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="font-size:0.85rem;"><?= sanitize($sr['name']) ?></div>
                        <small class="text-muted"><?= sanitize($sr['email']) ?></small>
                    </div>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Block <?= sanitize(addslashes($sr['name'])) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                        <input type="hidden" name="block_user" value="1">
                        <input type="hidden" name="block_user_id" value="<?= $sr['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Block</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($searchBlock !== '' && mb_strlen($searchBlock) >= 2): ?>
        <p class="text-muted mt-2 mb-0" style="font-size:0.85rem;">No users found matching "<?= sanitize($searchBlock) ?>"</p>
    <?php endif; ?>
</div>

<?php }); ?>
