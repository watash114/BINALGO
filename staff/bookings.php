<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('staff');


$booking = new Booking();
$schedule = new Schedule();
$user = new User();
$payment = new Payment();

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $bookingId = (int)($_POST['booking_id'] ?? 0);

    if ($action && $bookingId) {
        switch ($action) {
            case 'confirm':
                $booking->confirm($bookingId);
                flash_message('success', 'Booking confirmed successfully.');
                break;
            case 'cancel':
                $booking->cancel($bookingId);
                flash_message('success', 'Booking cancelled successfully.');
                break;
            case 'complete':
                $booking->complete($bookingId);
                flash_message('success', 'Booking marked as completed.');
                break;
        }
        redirect('/staff/bookings.php');
    }
}

$statusFilter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = [];
if ($statusFilter) $filters['status'] = $statusFilter;
if ($search) $filters['search'] = $search;

$bookings = $booking->findAll($filters, $page, 15);
foreach ($bookings['data'] as &$row) {
    $row['payment'] = $payment->findByBookingId((int)$row['id']);
}
unset($row);
$stats = $booking->getStats();

render_page('staff', 'bookings.php', 'Booking Management', function () use ($bookings, $stats, $statusFilter, $search, $dateFrom, $dateTo) {
?>
<style>
.staff-hero{background:linear-gradient(135deg,#0c6e5e,#1a8a7a);border-radius:16px;padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
.staff-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:rgba(255,255,255,0.08);border-radius:50%;}
.staff-hero::after{content:'';position:absolute;bottom:-30px;left:30px;width:100px;height:100px;background:rgba(255,255,255,0.05);border-radius:50%;}
.dash-stat{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;position:relative;overflow:hidden;}
.dash-stat .accent-bar{position:absolute;top:0;left:0;width:4px;height:100%;border-radius:4px 0 0 4px;}
.dash-stat .stat-value{font-size:1.6rem;font-weight:800;color:var(--text-primary,#1e293b);margin-bottom:2px;}
.dash-stat .stat-label{font-size:0.8rem;color:var(--text-muted,#64748b);font-weight:500;}
.filter-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:20px;}
.filter-card .form-control,.filter-card .form-select{border-radius:10px;border:1px solid var(--border-color,#e2e8f0);padding:10px 14px;font-size:0.88rem;background:var(--card-bg,#fff);color:var(--text-primary,#1e293b);}
.filter-card .form-control:focus,.filter-card .form-select:focus{border-color:var(--primary,#0c6e5e);box-shadow:0 0 0 3px rgba(12,110,94,0.1);outline:none;}
.table-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.table-card .table{margin-bottom:0;color:var(--text-primary,#1e293b);}
.table-card .table thead th{background:var(--bg-secondary,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0);font-size:0.8rem;font-weight:600;color:var(--text-muted,#64748b);text-transform:uppercase;letter-spacing:0.5px;padding:12px 16px;}
.table-card .table tbody td{padding:12px 16px;border-bottom:1px solid var(--border-color,#f1f5f9);font-size:0.88rem;}
.table-card .table tbody tr:last-child td{border-bottom:none;}
.table-card .table tbody tr:hover{background:rgba(12,110,94,0.02);}
.status-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:50px;font-size:0.78rem;font-weight:600;}
.status-chip.pending{background:rgba(245,158,11,0.12);color:#d97706;}
.status-chip.confirmed{background:rgba(34,197,94,0.12);color:#16a34a;}
.status-chip.completed{background:rgba(59,130,246,0.12);color:#2563eb;}
.status-chip.cancelled{background:rgba(239,68,68,0.12);color:#dc2626;}
.action-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-color,#e2e8f0);background:var(--card-bg,#fff);color:var(--text-muted,#64748b);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;font-size:0.8rem;}
.action-btn:hover{border-color:var(--primary,#0c6e5e);color:var(--primary,#0c6e5e);background:rgba(12,110,94,0.05);}
.action-btn.danger:hover{border-color:#dc2626;color:#dc2626;background:rgba(220,38,38,0.05);}
.action-btn.success:hover{border-color:#16a34a;color:#16a34a;background:rgba(22,163,74,0.05);}
.action-btn.primary:hover{border-color:#2563eb;color:#2563eb;background:rgba(37,99,235,0.05);}
.detail-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;overflow:hidden;}
.detail-card .detail-header{padding:20px;border-bottom:1px solid var(--border-color,#f1f5f9);display:flex;align-items:center;gap:12px;}
.detail-card .detail-body{padding:20px;}
.detail-item{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color,#f1f5f9);}
.detail-item:last-child{border-bottom:none;}
.detail-item .d-label{font-weight:600;font-size:0.85rem;color:var(--text-primary,#1e293b);}
.detail-item .d-value{font-size:0.85rem;color:var(--text-muted,#64748b);}
.pagination .page-item .page-link{border-radius:8px;margin:0 3px;border:1px solid var(--border-color,#e2e8f0);color:var(--text-primary,#1e293b);font-size:0.85rem;padding:6px 12px;}
.pagination .page-item.active .page-link{background:var(--primary,#0c6e5e);border-color:var(--primary,#0c6e5e);color:#fff;}
.pagination .page-item .page-link:hover:not(.active){background:rgba(12,110,94,0.05);color:var(--primary,#0c6e5e);}
</style>

<div class="staff-hero">
    <div class="position-relative" style="z-index:1;">
        <h3 class="fw-bold mb-1"><i class="fas fa-ticket me-2"></i>Booking Management</h3>
        <p class="mb-0 opacity-75" style="font-size:0.9rem;">View and manage all tourist bookings</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="dash-stat">
            <div class="accent-bar" style="background:#3b82f6;"></div>
            <div style="padding-left:12px;">
                <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
                <div class="stat-label">Total Bookings</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dash-stat">
            <div class="accent-bar" style="background:#f59e0b;"></div>
            <div style="padding-left:12px;">
                <div class="stat-value" style="color:#d97706;"><?= $stats['pending'] ?? 0 ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dash-stat">
            <div class="accent-bar" style="background:#22c55e;"></div>
            <div style="padding-left:12px;">
                <div class="stat-value" style="color:#16a34a;"><?= $stats['confirmed'] ?? 0 ?></div>
                <div class="stat-label">Confirmed</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dash-stat">
            <div class="accent-bar" style="background:#0c6e5e;"></div>
            <div style="padding-left:12px;">
                <div class="stat-value">$<?= number_format($stats['total_revenue'] ?? 0, 2) ?></div>
                <div class="stat-label">Revenue</div>
            </div>
        </div>
    </div>
</div>

<div class="filter-card mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Search Tourist</label>
            <input type="text" name="search" class="form-control" placeholder="Name or email..." value="<?= sanitize($search) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold">Status</label>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold">From Date</label>
            <input type="date" name="date_from" class="form-control" value="<?= sanitize($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold">To Date</label>
            <input type="date" name="date_to" class="form-control" value="<?= sanitize($dateTo) ?>">
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm" style="background:var(--primary,#0c6e5e);color:#fff;border-radius:8px;padding:8px 16px;"><i class="fas fa-search me-1"></i>Filter</button>
            <a href="bookings.php" class="btn btn-sm" style="border:1px solid var(--border-color,#e2e8f0);border-radius:8px;padding:8px 16px;color:var(--text-primary,#1e293b);background:var(--card-bg,#fff);"><i class="fas fa-redo me-1"></i>Reset</a>
        </div>
    </form>
</div>

<div class="table-card">
    <?php if (empty($bookings['data'])): ?>
        <div class="text-center py-5">
            <div style="width:64px;height:64px;border-radius:50%;background:rgba(12,110,94,0.08);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <i class="fas fa-ticket" style="font-size:1.5rem;color:var(--primary,#0c6e5e);"></i>
            </div>
            <h6 class="fw-bold" style="color:var(--text-primary,#1e293b);">No Bookings Found</h6>
            <p class="text-muted small mb-0">No bookings match your current filters.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Tourist</th>
                        <th>Event</th>
                        <th>Destination</th>
                        <th>Date</th>
                        <th>Participants</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings['data'] as $b): ?>
                    <tr>
                        <td class="text-muted">#<?= $b['id'] ?></td>
                        <td>
                            <div class="fw-semibold" style="font-size:0.88rem;"><?= sanitize($b['tourist_name'] ?? 'N/A') ?></div>
                            <div class="text-muted small"><?= sanitize($b['tourist_email'] ?? '') ?></div>
                        </td>
                        <td><?= sanitize($b['event_title'] ?? 'N/A') ?></td>
                        <td><?= sanitize($b['destination_name'] ?? 'N/A') ?></td>
                        <td>
                            <div style="font-size:0.88rem;"><?= format_date($b['start_date'] ?? '') ?></div>
                            <div class="text-muted small"><?= sanitize($b['start_time'] ?? '') ?> - <?= sanitize($b['end_time'] ?? '') ?></div>
                        </td>
                        <td><?= $b['participants'] ?? 1 ?></td>
                        <td class="fw-semibold" style="color:var(--primary,#0c6e5e);">$<?= number_format($b['total_price'] ?? 0, 2) ?></td>
                        <td>
                            <?php
                            $statusClass = match($b['status'] ?? '') {
                                'pending' => 'pending',
                                'confirmed' => 'confirmed',
                                'completed' => 'completed',
                                'cancelled' => 'cancelled',
                                default => 'pending'
                            };
                            ?>
                            <span class="status-chip <?= $statusClass ?>"><?= ucfirst($b['status'] ?? 'unknown') ?></span>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <button type="button" class="action-btn" data-bs-toggle="modal" data-bs-target="#detailModal<?= $b['id'] ?>" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($b['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="confirm">
                                        <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                        <button class="action-btn success" title="Confirm" onclick="return confirm('Confirm this booking?')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($b['status'], ['pending', 'confirmed'])): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                        <button class="action-btn primary" title="Mark Completed" onclick="return confirm('Mark as completed?')">
                                            <i class="fas fa-flag-checkered"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                        <button class="action-btn danger" title="Cancel" onclick="return confirm('Cancel this booking?')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($bookings['data'] as $b): ?>
        <div class="modal fade" id="detailModal<?= $b['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="detail-card">
                    <div class="detail-header" style="background:linear-gradient(135deg,#0c6e5e,#1a8a7a);color:#fff;">
                        <div style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-ticket" style="font-size:0.9rem;"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold">Booking #<?= $b['id'] ?></h6>
                            <small class="opacity-75">Booking Details</small>
                        </div>
                        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="detail-body">
                        <div class="receipt mb-3">
                            <div class="receipt-head">
                                <span class="receipt-brand">BINALGO TOURS</span>
                                <span class="status-chip <?= match($b['status'] ?? '') { 'pending' => 'pending', 'confirmed' => 'confirmed', 'completed' => 'completed', 'cancelled' => 'cancelled', default => 'pending' } ?>"><?= ucfirst($b['status'] ?? '') ?></span>
                            </div>
                            <div class="receipt-row"><span class="receipt-label">Booking ID</span><span class="receipt-value">#<?= $b['id'] ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Tourist</span><span class="receipt-value"><?= sanitize($b['tourist_name'] ?? 'N/A') ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Contact</span><span class="receipt-value"><?= sanitize($b['contact_number'] ?? $b['tourist_email'] ?? 'N/A') ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Event</span><span class="receipt-value"><?= sanitize($b['event_title'] ?? 'N/A') ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Destination</span><span class="receipt-value"><?= sanitize($b['destination_name'] ?? 'N/A') ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Location</span><span class="receipt-value"><?= sanitize($b['destination_location'] ?? 'N/A') ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Schedule</span><span class="receipt-value"><?= format_date($b['start_date'] ?? '') ?> <?= sanitize($b['start_time'] ?? '') ?> - <?= sanitize($b['end_time'] ?? '') ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Participants</span><span class="receipt-value"><?= $b['participants'] ?? 1 ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Notes</span><span class="receipt-value"><?= sanitize($b['notes'] ?? 'None') ?></span></div>
                            <div class="receipt-row"><span class="receipt-label">Created</span><span class="receipt-value"><?= format_datetime($b['created_at'] ?? '') ?></span></div>
                            <div class="receipt-row receipt-total"><span class="receipt-label">TOTAL</span><span class="receipt-value" style="color:var(--primary,#0c6e5e);font-size:1.05rem;">$<?= number_format($b['total_price'] ?? 0, 2) ?></span></div>
                        </div>
                        <?php if (!empty($b['payment'])): $pay = $b['payment']; ?>
                        <div class="payment-strip block">
                            <div class="payment-strip-head">
                                <span class="payment-strip-title"><i class="fas fa-credit-card me-1"></i>Payment Verification</span>
                                <?php
                                $payClass = match($pay['payment_status'] ?? '') {
                                    'paid' => 'success',
                                    'pending' => 'warning',
                                    'failed' => 'danger',
                                    'refunded' => 'danger',
                                    default => 'warning'
                                };
                                ?>
                                <span class="status-chip <?= $payClass ?>"><?= ucfirst($pay['payment_status'] ?? 'pending') ?></span>
                            </div>
                            <div class="booking-info-grid">
                                <div class="bi-item">
                                    <div class="bi-label">Payment Method</div>
                                    <div class="bi-value">
                                        <?php
                                        $methodIcon = match($pay['payment_method'] ?? '') {
                                            'card' => 'fa-credit-card',
                                            'gcash' => 'fa-mobile-screen-button',
                                            'maya' => 'fa-mobile-screen-button',
                                            'cash' => 'fa-money-bill-wave',
                                            default => 'fa-wallet'
                                        };
                                        ?>
                                        <i class="fas <?= $methodIcon ?> me-1" style="color:var(--primary,#0c6e5e);"></i><?= strtoupper($pay['payment_method'] ?? 'N/A') ?>
                                    </div>
                                </div>
                                <?php if (!empty($pay['card_brand']) || !empty($pay['card_last_four'])): ?>
                                <div class="bi-item">
                                    <div class="bi-label">Card</div>
                                    <div class="bi-value"><?= sanitize($pay['card_brand'] ?? '') ?> •••• <?= sanitize($pay['card_last_four'] ?? '') ?></div>
                                </div>
                                <?php endif; ?>
                                <div class="bi-item">
                                    <div class="bi-label">Reference No.</div>
                                    <div class="bi-value"><?= sanitize($pay['reference_number'] ?? '—') ?></div>
                                </div>
                                <?php if (!empty($pay['transaction_id'])): ?>
                                <div class="bi-item">
                                    <div class="bi-label">Transaction ID</div>
                                    <div class="bi-value"><?= sanitize($pay['transaction_id']) ?></div>
                                </div>
                                <?php endif; ?>
                                <div class="bi-item">
                                    <div class="bi-label">Amount Paid</div>
                                    <div class="bi-value" style="color:var(--primary,#0c6e5e);font-weight:700;">$<?= number_format($pay['total_amount'] ?? 0, 2) ?></div>
                                </div>
                                <div class="bi-item">
                                    <div class="bi-label">Paid On</div>
                                    <div class="bi-value"><?= !empty($pay['payment_date']) ? format_datetime($pay['payment_date']) : '—' ?></div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="payment-strip block" style="border-color:rgba(245,158,11,0.3);">
                            <div class="payment-strip-head">
                                <span class="payment-strip-title"><i class="fas fa-credit-card me-1"></i>Payment Verification</span>
                                <span class="status-chip warning">No Payment Record</span>
                            </div>
                            <p class="small mb-0" style="color:var(--text-muted,#64748b);">No payment record linked to this booking. Confirm the tourist payment method before proceeding.</p>
                        </div>
                        <?php endif; ?>
                        <?php if (in_array($b['status'] ?? '', ['pending', 'confirmed'])): ?>
                        <div class="d-flex gap-2 justify-content-end">
                            <?php if (($b['status'] ?? '') === 'pending'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="confirm">
                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                <button class="action-btn success" style="width:auto;padding:0 14px;gap:6px;" data-confirm="Confirm this booking?"><i class="fas fa-check"></i>Confirm</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                <button class="action-btn primary" style="width:auto;padding:0 14px;gap:6px;" data-confirm="Mark this booking as completed?"><i class="fas fa-flag-checkered"></i>Complete</button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                                <button class="action-btn danger" style="width:auto;padding:0 14px;gap:6px;" data-confirm="Cancel this booking?"><i class="fas fa-times"></i>Cancel</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($bookings['pages'] > 1): ?>
<div class="d-flex justify-content-center mt-4">
    <nav>
        <ul class="pagination mb-0">
            <?php if ($bookings['page'] > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $bookings['page'] - 1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-left"></i></a></li>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $bookings['pages']; $i++): ?>
                <li class="page-item <?= $i == $bookings['page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <?php if ($bookings['page'] < $bookings['pages']): ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $bookings['page'] + 1 ?>&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>"><i class="fas fa-chevron-right"></i></a></li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>
<?php }); ?>
