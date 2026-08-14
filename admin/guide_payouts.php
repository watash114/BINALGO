<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('admin');

require_once __DIR__ . '/../includes/classes/GuidePayout.php';

$db = Database::getInstance()->getConnection();
$payoutModel = new GuidePayout();
$current_user = current_user();

$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = [];
if ($status_filter) $filters['status'] = $status_filter;
if ($search) $filters['search'] = $search;

$result = $payoutModel->findAll($filters, $page, 15);
$payouts = $result['data'];
$total = $result['total'];
$pages = $result['pages'];
$stats = $payoutModel->getPayoutStats();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_token($_POST['csrf_token'] ?? '')) { flash_message('error', 'Invalid security token.'); redirect('/admin/guide_payouts.php'); }
    $action = $_POST['action'] ?? '';
    if ($action === 'approve' && !empty($_POST['payout_ids'])) {
        $ids = array_map('intval', (array)$_POST['payout_ids']);
        $count = $payoutModel->bulkApprove($ids, $current_user['id']);
        ActivityLog::log($current_user['id'], 'payout_approve', "Approved {$count} guide payouts");
        flash_message('success', "{$count} payout(s) approved."); redirect('/admin/guide_payouts.php?' . http_build_query($_GET));
    }
    if ($action === 'approve_single') {
        $pid = (int)($_POST['payout_id'] ?? 0);
        if ($payoutModel->approve($pid, $current_user['id'])) {
            ActivityLog::log($current_user['id'], 'payout_approve', "Approved payout #{$pid}");
            flash_message('success', 'Payout approved.');
        } else { flash_message('error', 'Could not approve payout.'); }
        redirect('/admin/guide_payouts.php?' . http_build_query($_GET));
    }
    if ($action === 'pay') {
        $pid = (int)($_POST['payout_id'] ?? 0);
        $ref = $payoutModel->generatePayoutReference();
        if ($payoutModel->markPaid($pid, $ref)) {
            ActivityLog::log($current_user['id'], 'payout_paid', "Marked payout #{$pid} as paid ({$ref})");
            flash_message('success', "Payout marked as paid. Reference: {$ref}");
        } else { flash_message('error', 'Could not process payout.'); }
        redirect('/admin/guide_payouts.php?' . http_build_query($_GET));
    }
}

render_page('admin', 'guide_payouts.php', 'Guide Payouts', function () use ($payouts, $total, $stats, $status_filter, $search, $page, $pages) {
$psc = ['pending'=>'#f59e0b','approved'=>'#3b82f6','paid'=>'#10b981'];
$psi = ['pending'=>'fa-clock','approved'=>'fa-check-circle','paid'=>'fa-money-bill-wave'];
?>

<style>
.page-hero{background:linear-gradient(135deg,rgba(12,110,94,.9) 0%,rgba(6,95,70,.95) 100%);color:#fff;border-radius:20px;padding:32px 36px;margin-bottom:1.5rem;position:relative;overflow:hidden}.page-hero::before{content:'';position:absolute;top:-50%;right:-15%;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);animation:hFloat 8s ease-in-out infinite}@keyframes hFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(-20px,15px)}}.page-hero h4{font-weight:800;margin-bottom:4px;position:relative;z-index:1}.page-hero p{opacity:.85;font-size:.9rem;position:relative;z-index:1;margin-bottom:0}
.stat-card{border:none;border-radius:16px;overflow:hidden;transition:all .3s;background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9)}.stat-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.08)}.stat-card .stat-bar{height:4px;width:100%}.stat-card .stat-body{padding:18px 16px;text-align:center}.stat-card .stat-icon{width:42px;height:42px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px}.stat-card .stat-value{font-size:1.6rem;font-weight:800;line-height:1;margin-bottom:4px}.stat-card .stat-label{font-size:.78rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;padding:20px;margin-bottom:1rem}.filter-card .form-control,.filter-card .form-select{border-radius:10px;border-color:var(--border-color,#e2e8f0);font-size:.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b)}.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:#0c6e5e;box-shadow:0 0 0 3px rgba(12,110,94,.1)}.filter-card .form-label{font-size:.78rem;font-weight:700;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:.5px}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#f1f5f9);border-radius:16px;overflow:hidden}.logs-table{border-collapse:separate;border-spacing:0}.logs-table thead th{background:var(--card-bg,#f8fafc);border-bottom:2px solid var(--border-color,#e2e8f0);font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#64748b);padding:14px 16px}.logs-table tbody tr{transition:all .15s}.logs-table tbody tr:hover{background:rgba(12,110,94,.02)}.logs-table tbody td{padding:14px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);vertical-align:middle;font-size:.88rem;color:var(--text-primary,#1e293b)}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:8px;font-size:.75rem;font-weight:700}
.action-btn{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:.82rem;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-primary,#475569);transition:all .2s;padding:0}.action-btn:hover{border-color:#0c6e5e;color:#0c6e5e;background:rgba(12,110,94,.05)}.action-btn.success:hover{border-color:#10b981;color:#10b981;background:rgba(16,185,129,.05)}.action-btn.primary:hover{border-color:#3b82f6;color:#3b82f6;background:rgba(59,130,246,.05)}
.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted,#94a3b8)}.empty-state .empty-icon{width:56px;height:56px;border-radius:14px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem}.empty-state h6{font-weight:700;font-size:.9rem;color:var(--text-primary,#1e293b);margin-bottom:4px}.empty-state p{font-size:.82rem;margin:0}
.pagination .page-link{border-radius:10px;margin:0 3px;font-size:.85rem;font-weight:600;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);padding:6px 14px}.pagination .page-item.active .page-link{background:#0c6e5e;border-color:#0c6e5e;color:#fff}
</style>

<div class="page-hero">
    <h4><i class="fas fa-money-check-alt me-2"></i>Guide Payouts</h4>
    <p><?= $total ?> payout record<?= $total !== 1 ? 's' : '' ?> · Total commission: ₱<?= number_format($stats['total_commission'] ?? 0, 2) ?></p>
</div>

<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['val'=>'₱'.number_format($stats['pending_total']??0,2), 'label'=>'Pending Payouts','icon'=>'fa-clock','color'=>'#f59e0b','bg'=>'#fef3c7'],
        ['val'=>'₱'.number_format($stats['approved_total']??0,2), 'label'=>'Approved','icon'=>'fa-check-circle','color'=>'#3b82f6','bg'=>'#dbeafe'],
        ['val'=>'₱'.number_format($stats['paid_total']??0,2), 'label'=>'Total Paid','icon'=>'fa-money-bill-wave','color'=>'#10b981','bg'=>'#d1fae5'],
        ['val'=>'₱'.number_format($stats['total_commission']??0,2), 'label'=>'Commission Earned','icon'=>'fa-percentage','color'=>'#ec4899','bg'=>'#fce7f3'],
    ];
    foreach ($statCards as $sc): ?>
    <div class="col-xl-3 col-md-6">
        <div class="card stat-card"><div class="stat-bar" style="background:<?= $sc['color'] ?>;"></div>
            <div class="stat-body">
                <div class="stat-icon" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;"><i class="fas <?= $sc['icon'] ?>"></i></div>
                <div class="stat-value" style="color:<?= $sc['color'] ?>;"><?= $sc['val'] ?></div>
                <div class="stat-label"><?= $sc['label'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label">Search Guide</label><input type="text" name="search" class="form-control" placeholder="Guide name..." value="<?= sanitize($search) ?>"></div>
        <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All Statuses</option><option value="pending" <?= $status_filter==='pending'?'selected':'' ?>>Pending</option><option value="approved" <?= $status_filter==='approved'?'selected':'' ?>>Approved</option><option value="paid" <?= $status_filter==='paid'?'selected':'' ?>>Paid</option></select></div>
        <div class="col-md-2"><button type="submit" class="btn" style="background:#0c6e5e;color:#fff;border-radius:10px;font-weight:600;"><i class="fas fa-filter me-1"></i>Filter</button></div>
    </form>
</div>

<form method="POST" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="approve" id="bulkAction">

    <div class="table-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table logs-table align-middle mb-0">
                    <thead><tr>
                        <th width="40"><input type="checkbox" id="selectAll" class="form-check-input" onclick="toggleAll(this)"></th>
                        <th>Guide</th><th>Event</th><th>Tour Amount</th><th>Commission</th><th>Net Earning</th><th>Status</th><th>Date</th><th class="text-center" width="100">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($payouts)): ?>
                            <tr><td colspan="9" class="empty-state"><div class="empty-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fas fa-money-check-alt"></i></div><h6>No payouts found</h6><p>Try adjusting your filters.</p></td></tr>
                        <?php else: foreach ($payouts as $p): ?>
                            <tr>
                                <td><?php if ($p['payout_status'] === 'pending'): ?><input type="checkbox" name="payout_ids[]" value="<?= $p['id'] ?>" class="form-check-input bulk-check"><?php endif; ?></td>
                                <td><div class="fw-semibold" style="font-size:.88rem;"><?= sanitize($p['guide_name'] ?? 'N/A') ?></div><div style="font-size:.75rem;color:var(--text-muted,#94a3b8);"><?= sanitize($p['guide_email'] ?? '') ?></div></td>
                                <td><span style="font-size:.85rem;color:var(--text-muted,#64748b);"><?= sanitize(truncate($p['event_title'] ?? '', 25)) ?></span></td>
                                <td><span style="font-size:.88rem;color:var(--text-primary,#1e293b);">₱<?= number_format($p['tour_amount'], 2) ?></span></td>
                                <td><span style="font-size:.88rem;color:#ef4444;">-₱<?= number_format($p['commission_amount'], 2) ?></span></td>
                                <td><span class="fw-bold" style="color:#0c6e5e;font-size:.9rem;">₱<?= number_format($p['net_earning'], 2) ?></span></td>
                                <td><span class="status-chip" style="background:<?= $psc[$p['payout_status']] ?? '#6b7280' ?>18;color:<?= $psc[$p['payout_status']] ?? '#6b7280' ?>;"><i class="fas <?= $psi[$p['payout_status']] ?? 'fa-circle' ?> me-1"></i><?= ucfirst($p['payout_status']) ?></span></td>
                                <td><span style="font-size:.85rem;color:var(--text-muted,#64748b);"><?= format_date($p['created_at']) ?></span></td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <?php if ($p['payout_status'] === 'pending'): ?>
                                            <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve_single"><input type="hidden" name="payout_id" value="<?= $p['id'] ?>"><button type="submit" class="action-btn success" title="Approve"><i class="fas fa-check"></i></button></form>
                                        <?php endif; ?>
                                        <?php if ($p['payout_status'] === 'approved'): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Mark this payout as paid?')"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="payout_id" value="<?= $p['id'] ?>"><button type="submit" class="action-btn primary" title="Mark Paid"><i class="fas fa-money-bill-wave"></i></button></form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn" style="background:#10b981;color:#fff;border-radius:10px;font-weight:600;" id="bulkApproveBtn" disabled>
            <i class="fas fa-check-double me-1"></i>Approve Selected
        </button>
    </div>
</form>

<?php if ($pages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center mb-0">
    <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>"><i class="fas fa-chevron-left"></i></a></li>
    <?php for ($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a></li><?php endfor; ?>
    <li class="page-item <?= $page>=$pages?'disabled':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>"><i class="fas fa-chevron-right"></i></a></li>
</ul></nav>
<?php endif; ?>

<script>
function toggleAll(source) { document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = source.checked); updateBulkBtn(); }
document.querySelectorAll('.bulk-check').forEach(cb => cb.addEventListener('change', updateBulkBtn));
function updateBulkBtn() { document.getElementById('bulkApproveBtn').disabled = document.querySelectorAll('.bulk-check:checked').length === 0; }
</script>

<?php }); ?>
