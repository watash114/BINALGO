<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/classes/Call.php';
require_role('tourist');

$user_id = $_SESSION['user_id'];
$callModel = new Call();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_call'])) {
    $callModel->delete((int)$_POST['call_id'], $user_id);
    set_flash_message('success', 'Call removed from history.');
    redirect(BASE_URL . '/tourist/call_history.php');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$result = $callModel->getHistory($user_id, $page);
$calls = $result['data'];
$totalPages = $result['pages'];
$currentPage = $result['page'];

render_page('tourist', 'call_history.php', 'Call History', function () use ($calls, $totalPages, $currentPage) {
?>
<style>
.call-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.call-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.call-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.call-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:16px;display:flex;align-items:center;gap:14px;transition:all 0.2s;margin-bottom:10px;}
.call-card:hover{box-shadow:0 4px 12px rgba(0,0,0,0.06);transform:translateY(-1px);}
.call-avatar{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color,#e2e8f0);}
.call-info{flex:1;min-width:0;}
.call-name{font-weight:700;font-size:0.95rem;color:var(--text-primary,#1e293b);}
.call-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:4px;}
.call-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:50px;font-size:0.72rem;font-weight:600;}
.call-badge.completed{background:#d1fae5;color:#065f46;}
.call-badge.missed{background:#fee2e2;color:#991b1b;}
.call-badge.declined{background:#fef3c7;color:#92400e;}
.call-badge.default{background:#e2e8f0;color:#475569;}
.call-actions{display:flex;gap:6px;}
.call-action-btn{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);transition:all 0.2s;font-size:0.85rem;text-decoration:none;}
.call-action-btn:hover{background:var(--primary,#0c6e5e);color:#fff;border-color:var(--primary,#0c6e5e);}
.call-action-btn.danger:hover{background:#ef4444;border-color:#ef4444;}
.call-type-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;flex-shrink:0;}
.call-type-icon.voice{background:#dbeafe;color:#2563eb;}
.call-type-icon.video{background:#ede9fe;color:#7c3aed;}
.pagination .page-link{border-radius:8px;margin:0 2px;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);font-size:0.85rem;padding:6px 12px;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-item.disabled .page-link{opacity:0.4;}
</style>

<div class="call-hero">
    <div class="position-relative" style="z-index:1;">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h3 class="fw-bold mb-1"><i class="fas fa-phone-alt me-2"></i>Call History</h3>
                <p class="mb-0 opacity-75" style="font-size:0.9rem;">View your past voice and video calls</p>
            </div>
            <a href="<?= BASE_URL ?>/tourist/messages.php" class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border-radius:8px;font-weight:600;text-decoration:none;">
                <i class="fas fa-arrow-left me-1"></i>Back to Messages
            </a>
        </div>
    </div>
</div>

    <?php if (empty($calls)): ?>
    <div style="background:var(--card-bg,#fff);border-radius:14px;border:1px solid var(--border-color,#e2e8f0);padding:48px 24px;text-align:center;">
        <div style="width:80px;height:80px;border-radius:50%;background:var(--bg-secondary,#f1f5f9);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-phone-slash text-muted" style="font-size:2rem;opacity:0.4;"></i>
        </div>
        <h5 class="fw-bold mb-1" style="color:var(--text-primary,#1e293b);">No call history yet</h5>
        <p class="text-muted small mb-3">Start a voice or video call from your messages.</p>
        <a href="<?= BASE_URL ?>/tourist/messages.php" class="btn" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;border-radius:10px;padding:10px 24px;font-weight:600;">
            <i class="fas fa-comments me-1"></i>Go to Messages
        </a>
    </div>
<?php else: ?>
    <?php foreach ($calls as $call):
        $statusClass = $call['status'] === 'completed' ? 'completed' : ($call['status'] === 'missed' ? 'missed' : ($call['status'] === 'declined' ? 'declined' : 'default'));
    ?>
        <div class="call-card">
            <img src="<?= get_avatar_url(['id'=>$call['other_user_id'],'name'=>$call['other_user_name'],'email'=>'', 'avatar'=>$call['other_user_avatar'] ?? '']) ?>"
                 class="call-avatar" width="48" height="48" alt="">
            <div class="call-info">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="call-name"><?= sanitize($call['other_user_name']) ?></div>
                    <small class="text-muted" style="font-size:0.72rem;"><?= date('M d, Y', strtotime($call['created_at'])) ?></small>
                </div>
                <div class="call-meta">
                    <span class="call-type-icon <?= $call['call_type'] ?>">
                        <i class="fas fa-<?= $call['call_type'] === 'video' ? 'video' : 'phone' ?>"></i>
                    </span>
                    <small class="text-muted"><?= $call['call_type'] === 'video' ? 'Video' : 'Voice' ?> Call</small>
                    <small class="text-muted">&middot;</small>
                    <small class="text-muted">
                        <?= $call['direction'] === 'outgoing' ? '<i class="fas fa-arrow-up me-1" style="color:#3b82f6;"></i>Outgoing' : '<i class="fas fa-arrow-down me-1" style="color:#10b981;"></i>Incoming' ?>
                    </small>
                    <span class="call-badge <?= $statusClass ?>"><?= ucfirst($call['status']) ?></span>
                    <?php if ($call['duration'] > 0): ?>
                        <small class="text-muted"><i class="fas fa-clock me-1"></i><?= gmdate('i:s', $call['duration']) ?></small>
                    <?php endif; ?>
                    <small class="text-muted"><?= date('h:i A', strtotime($call['created_at'])) ?></small>
                </div>
            </div>
            <div class="call-actions">
                <a href="<?= BASE_URL ?>/tourist/messages.php?chat=<?= $call['other_user_id'] ?>" class="call-action-btn" title="Call Again">
                    <i class="fas fa-phone"></i>
                </a>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this call from history?')">
                    <input type="hidden" name="csrf_token" value="<?= generate_token() ?>">
                    <input type="hidden" name="delete_call" value="1">
                    <input type="hidden" name="call_id" value="<?= $call['id'] ?>">
                    <button type="submit" class="call-action-btn danger" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $currentPage - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                </li>
                <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                    <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $currentPage + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php }); ?>
