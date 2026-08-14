<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('guide');

require_once __DIR__ . '/../includes/classes/GuidePayout.php';

$db = Database::getInstance()->getConnection();
$user = current_user();
$guide_id = $user['id'];
$payoutModel = new GuidePayout();

$page = max(1, (int)($_GET['page'] ?? 1));
$status_filter = $_GET['status'] ?? '';

$result = $payoutModel->findByGuide($guide_id, $status_filter ?: null, $page, 10);
$payouts = $result['data'];
$total = $result['total'];
$pages = $result['pages'];

$stats = $payoutModel->getGuideStats($guide_id);
$monthly = $payoutModel->getMonthlyEarnings($guide_id, 6);

render_page('guide', 'earnings.php', 'My Earnings', function () use ($user, $payouts, $stats, $monthly, $status_filter, $page, $pages, $total) {
?>
<style>
.guide-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.guide-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.guide-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.earn-stat{background:var(--card-bg,#1a1f2e);border-radius:14px;padding:20px;border:1px solid var(--border-color,#2a3042);text-align:center;transition:all 0.25s;}
.earn-stat:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,0.2);}
.earn-stat .stat-icon{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;}
.earn-stat .stat-value{font-size:1.4rem;font-weight:800;}
.earn-stat .stat-label{font-size:0.78rem;color:var(--text-muted,#94a3b8);margin-top:4px;font-weight:500;}
.section-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;overflow:hidden;}
.section-card .section-header{padding:16px 20px;border-bottom:1px solid var(--border-color,#2a3042);display:flex;align-items:center;gap:10px;}
.section-card .section-header h6{margin:0;font-weight:700;color:var(--text-primary,#e2e8f0);}
.monthly-card{background:rgba(255,255,255,0.03);border:1px solid var(--border-color,#2a3042);border-radius:12px;padding:16px;text-align:center;transition:all 0.2s;}
.monthly-card:hover{background:rgba(255,255,255,0.06);}
.filter-card{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:14px;padding:16px 20px;margin-bottom:20px;}
.filter-input{background:var(--card-bg,#1a1f2e);border:1px solid var(--border-color,#2a3042);border-radius:10px;padding:10px 14px;color:var(--text-primary,#e2e8f0);width:100%;font-size:0.9rem;}
.filter-input:focus{border-color:var(--primary,#0c6e5e);outline:none;box-shadow:0 0 0 3px rgba(12,110,94,0.2);}
.table-card{background:var(--card-bg,#1a1f2e);border-radius:14px;border:1px solid var(--border-color,#2a3042);overflow:hidden;}
.table-card .table{margin-bottom:0;}
.table-card .table thead th{background:rgba(255,255,255,0.03);border-bottom:1px solid var(--border-color,#2a3042);font-size:0.78rem;font-weight:700;color:var(--text-muted,#94a3b8);text-transform:uppercase;letter-spacing:0.5px;padding:12px 16px;}
.table-card .table td{padding:14px 16px;vertical-align:middle;border-color:var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);}
.table-card .table tbody tr{transition:background 0.15s;}
.table-card .table tbody tr:hover{background:rgba(255,255,255,0.03);}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:0.75rem;font-weight:600;}
.status-chip.pending{background:#fef3c7;color:#92400e;}
.status-chip.approved{background:#dbeafe;color:#1e40af;}
.status-chip.paid{background:#d1fae5;color:#065f46;}
.pagination .page-link{border-radius:8px;margin:0 2px;border:1px solid var(--border-color,#2a3042);color:var(--text-primary,#e2e8f0);font-size:0.85rem;padding:6px 12px;background:var(--card-bg,#1a1f2e);}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-item.disabled .page-link{opacity:0.4;}
</style>

<div class="guide-hero">
    <div class="position-relative" style="z-index:1;">
        <h3 class="fw-bold mb-1"><i class="fas fa-wallet me-2"></i>My Earnings</h3>
        <p class="mb-0 opacity-75" style="font-size:0.9rem;">Track your tour earnings and payouts</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="earn-stat">
            <div class="stat-icon" style="background:rgba(16,185,129,0.15);">
                <i class="fas fa-wallet" style="color:#10b981;"></i>
            </div>
            <div class="stat-value" style="color:#10b981;">₱<?= number_format($stats['total_earned'] ?? 0, 2) ?></div>
            <div class="stat-label">Total Earned</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="earn-stat">
            <div class="stat-icon" style="background:rgba(245,158,11,0.15);">
                <i class="fas fa-hourglass-half" style="color:#f59e0b;"></i>
            </div>
            <div class="stat-value" style="color:#f59e0b;">₱<?= number_format($stats['pending_amount'] ?? 0, 2) ?></div>
            <div class="stat-label">Pending</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="earn-stat">
            <div class="stat-icon" style="background:rgba(59,130,246,0.15);">
                <i class="fas fa-check-circle" style="color:#3b82f6;"></i>
            </div>
            <div class="stat-value" style="color:#3b82f6;">₱<?= number_format($stats['approved_amount'] ?? 0, 2) ?></div>
            <div class="stat-label">Approved</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="earn-stat">
            <div class="stat-icon" style="background:rgba(139,92,246,0.15);">
                <i class="fas fa-hiking" style="color:#8b5cf6;"></i>
            </div>
            <div class="stat-value" style="color:#8b5cf6;"><?= $stats['completed_payouts'] ?? 0 ?></div>
            <div class="stat-label">Completed Tours</div>
        </div>
    </div>
</div>

<?php if (!empty($monthly)): ?>
<div class="section-card mb-4">
    <div class="section-header">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-chart-bar" style="color:#3b82f6;font-size:0.8rem;"></i>
        </div>
        <h6>Monthly Earnings</h6>
    </div>
    <div style="padding:20px;">
        <div class="row g-2">
            <?php foreach ($monthly as $m): ?>
                <div class="col">
                    <div class="monthly-card">
                        <div class="small mb-1" style="color:var(--text-muted,#94a3b8);"><?= date('M Y', strtotime($m['month'] . '-01')) ?></div>
                        <div class="fw-bold" style="color:#10b981;">₱<?= number_format($m['earned'], 2) ?></div>
                        <div class="small" style="color:var(--text-muted,#94a3b8);"><?= $m['tours'] ?> tour(s)</div>
                        <?php if ($m['pending'] > 0): ?>
                            <div class="small" style="color:#f59e0b;">₱<?= number_format($m['pending'], 2) ?> pending</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="filter-card">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold" style="color:var(--text-muted,#94a3b8);">Filter by Status</label>
            <select name="status" class="filter-input" onchange="this.form.submit()">
                <option value="">All</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
            </select>
        </div>
    </form>
</div>

<div class="table-card">
    <?php if (empty($payouts)): ?>
        <div class="text-center py-5">
            <div style="width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <i class="fas fa-money-bill-wave" style="font-size:2rem;color:var(--text-muted,#64748b);opacity:0.4;"></i>
            </div>
            <h5 class="fw-bold mb-1" style="color:var(--text-primary,#e2e8f0);">No earnings yet</h5>
            <p class="small" style="color:var(--text-muted,#94a3b8);">Complete tours to start earning.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Destination</th>
                        <th>Tour Date</th>
                        <th>Tour Amount</th>
                        <th>Commission</th>
                        <th>Net Earning</th>
                        <th>Status</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payouts as $p): ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($p['event_title'] ?? 'N/A') ?></td>
                            <td style="color:var(--text-muted,#94a3b8);"><?= sanitize($p['destination_name'] ?? 'N/A') ?></td>
                            <td><?= format_date($p['start_date'] ?? '') ?></td>
                            <td>₱<?= number_format($p['tour_amount'], 2) ?></td>
                            <td style="color:#ef4444;">-₱<?= number_format($p['commission_amount'], 2) ?> <small style="color:var(--text-muted,#64748b);">(<?= $p['commission_rate'] ?>%)</small></td>
                            <td class="fw-bold" style="color:#10b981;">₱<?= number_format($p['net_earning'], 2) ?></td>
                            <td><span class="status-chip <?= $p['payout_status'] ?>"><?= ucfirst($p['payout_status']) ?></span></td>
                            <td class="small" style="font-family:monospace;color:var(--text-muted,#94a3b8);"><?= sanitize($p['payout_reference'] ?? ($p['reference_number'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination justify-content-center">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?status=<?= $status_filter ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
        </li>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?status=<?= $status_filter ?>&page=<?= $i ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link" href="?status=<?= $status_filter ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php }); ?>
