<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

$archiveView = ($_GET['view'] ?? '') === 'archive';
$archiveReady = true;
try {
    ensure_archive_columns($conn, 'payments');
} catch (Throwable $e) {
    error_log('Resident payment archive setup failed: ' . $e->getMessage());
    $archiveReady = false;
}
$csrf_token = generateCsrfToken();

require_once '../includes/header.php';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? $_SESSION['error_msg'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['error_msg']);

// Find the homeowner record linked to this account
$user_id = $_SESSION['user_id'];
$homeowner = null;
try {
    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $homeowner = $result->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    $homeowner = null;
}

$outstanding_balance = 0;
$balance_period = date('F Y');
$next_payment_label = null;
$payment_history = [];
$all_payment_history = [];
$unpaid_months = [];
$unpaid_count = 0;
$earliest_unpaid = null;
$monthly_due_rate = MONTHLY_DUES_AMOUNT;

if ($homeowner) {
    $homeowner_id = $homeowner['id'];
    $summary = homeowner_account_summary($conn, $homeowner);
    $unpaid_months = $summary['unpaid'] ?? [];
    $unpaid_count = count($unpaid_months);
    $outstanding_balance = (float) ($summary['outstanding'] ?? 0);
    $monthly_due_rate = (float) ($summary['monthly_due'] ?? MONTHLY_DUES_AMOUNT);
    $earliest_unpaid = $unpaid_months[0] ?? null;

    if ($unpaid_count === 0) {
        $next_payment_label = date('F Y', strtotime('first day of next month'));
    } else {
        $balance_period = date('F Y', strtotime($earliest_unpaid . '-01'));
    }

    $all_payment_history = $summary['all_payments'] ?? ($summary['payments'] ?? []);
    $payment_history = $archiveView
        ? array_values(array_filter($all_payment_history, static function ($payment) {
            return !empty($payment['archived_at']);
        }))
        : ($summary['payments'] ?? []);
}

// Summary figures for the stat row — computed from the history we already have,
// no extra queries needed.
$current_year = date('Y');
$total_paid_this_year = 0;
foreach ($all_payment_history as $p) {
    if (!empty($p['payment_date']) && $p['payment_date'] !== '0000-00-00' && date('Y', strtotime($p['payment_date'])) === $current_year) {
        $total_paid_this_year += (float) $p['amount_paid'];
    }
}

$last_payment = $all_payment_history[0] ?? null;
$last_payment_label = ($last_payment && !empty($last_payment['payment_date']) && $last_payment['payment_date'] !== '0000-00-00')
    ? date('M d, Y', strtotime($last_payment['payment_date']))
    : '—';
?>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0 resident-payments-page">

            <!-- Right padding keeps the Archives button clear of the floating notification bell -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 payment-page-header" style="padding-right: 76px;">
                <h2 class="fw-bold mb-0"><?= $archiveView ? 'Archived Receipts' : 'Online Payments' ?></h2>
                <a href="payments.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-<?= $archiveView ? 'cash-stack' : 'archive' ?> me-1"></i><?= $archiveView ? 'Back to Payments' : 'Archives' ?>
                </a>
            </div>

            <?php if ($flash_success): ?>
                <div class="alert alert-success border-0 rounded-3 d-flex align-items-center mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i><div><?= htmlspecialchars($flash_success) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="alert alert-danger border-0 rounded-3 d-flex align-items-center mb-4">
                    <i class="bi bi-exclamation-circle-fill me-2"></i><div><?= htmlspecialchars($flash_error) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!$archiveReady): ?>
                <div class="alert alert-danger">Receipt archives are unavailable. Please contact the administrator.</div>
            <?php endif; ?>

            <?php if (!$archiveView): ?>
            <?php if (!$homeowner): ?>
                <div class="status-box mb-4" style="background-color: var(--warning-soft); border-color: rgba(217,119,6,0.15);">
                    <i class="bi bi-exclamation-triangle-fill status-box-icon" style="color: var(--warning-color);"></i>
                    <div>
                        <p class="status-box-title" style="color: var(--warning-color);">Resident profile unavailable</p>
                        <p class="status-box-subtitle">We could not find your homeowner record. Please contact the admin to enable online payments.</p>
                    </div>
                </div>
            <?php else: ?>

                <div class="row g-3 mb-4">
                    <!-- Balance card: spans half the row, the two stat cards take a quarter each -->
                    <div class="col-12 col-lg-6">
                        <?php if ($outstanding_balance > 0): ?>
                            <div class="card balance-card balance-card-pending payment-balance-card h-100">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="balance-card-icon" style="background: rgba(255, 255, 255, 0.2); color: #fff;"><i class="bi bi-wallet2"></i></span>
                                    <div>
                                        <p class="mb-0 fw-semibold" style="color: #fff;">Outstanding balance</p>
                                        <p class="mb-0 small" style="color: rgba(255, 255, 255, 0.8);"><?= $unpaid_count > 1 ? 'Unpaid since ' : 'Dues for ' ?><?= htmlspecialchars($balance_period) ?></p>
                                    </div>
                                </div>
                                <h1 class="payment-balance-value">₱ <?= number_format($outstanding_balance, 2) ?></h1>
                                <?php if ($unpaid_count > 1): ?>
                                    <p class="payment-balance-note"><?= $unpaid_count ?> unpaid months · ₱<?= number_format($monthly_due_rate, 2) ?>/mo</p>
                                <?php endif; ?>
                                <a href="create_checkout.php<?= $earliest_unpaid ? '?period=' . urlencode($earliest_unpaid) : '' ?>"
                                   data-payment-confirm="dues" class="payment-pay-cta">
                                    <i class="bi bi-wallet2"></i> Pay <?= $unpaid_count > 1 ? htmlspecialchars($balance_period) : 'Now Online' ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="card balance-card balance-card-success payment-balance-card h-100">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="balance-card-icon" style="background: var(--success-soft); color: var(--success-color);"><i class="bi bi-check2-circle"></i></span>
                                    <div>
                                        <p class="mb-0 fw-semibold" style="color: var(--text-heading);">Account status</p>
                                        <p class="mb-0 small" style="color: var(--text-muted);">All dues settled</p>
                                    </div>
                                </div>
                                <h1 class="payment-balance-value">All Paid Up</h1>
                                <p class="payment-balance-note">No outstanding balance for <?= htmlspecialchars($balance_period) ?>.</p>
                                <?php if ($next_payment_label): ?>
                                    <div class="payment-next-due">
                                        <span><i class="bi bi-calendar-event me-2"></i>Next monthly dues</span>
                                        <strong><?= htmlspecialchars($next_payment_label) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="card stat-card p-4 h-100">
                            <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                            <div>
                                <div class="stat-value">₱ <?= number_format($total_paid_this_year, 2) ?></div>
                                <div class="stat-label">Paid This Year</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <div class="card stat-card p-4 h-100">
                            <div class="stat-icon"><i class="bi bi-calendar2-check"></i></div>
                            <div>
                                <div class="stat-value"><?= htmlspecialchars($last_payment_label) ?></div>
                                <div class="stat-label">Last Payment</div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
            <?php endif; ?>

            <h5 class="fw-bold mb-3 mt-5 payment-history-heading"><?= $archiveView ? 'Archived Payment History' : 'Payment History' ?></h5>
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive border-0 shadow-none p-0 rounded">
                        <table class="table table-hover align-middle mb-0 payment-history-table resident-payments-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Reference No.</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Receipt</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_history as $p): ?>
                                <tr>
                                    <td><?= !empty($p['payment_date']) && $p['payment_date'] !== '0000-00-00' ? date('M d, Y', strtotime($p['payment_date'])) : '-' ?></td>
                                    <td>Monthly Dues (<?= date('F Y', strtotime($p['period_covered'] . '-01')) ?>)</td>
                                    <td class="text-muted small"><?= htmlspecialchars($p['receipt_no'] ?? '-') ?></td>
                                    <td class="fw-semibold">₱ <?= number_format($p['amount_paid'], 2) ?></td>
                                    <td>
                                        <span class="badge badge-success"><i class="bi bi-check-circle-fill me-1"></i>Paid</span>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['payment_date']) && $p['payment_date'] !== '0000-00-00'): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    onclick="openReceipt('payment', <?= (int) $p['id'] ?>)">
                                                <i class="bi bi-receipt me-1"></i> Receipt
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="payment_archive.php" class="d-inline" onsubmit="return confirm('<?= $archiveView ? 'Restore' : 'Archive' ?> this receipt?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $archiveView ? 'restore' : 'archive' ?>">
                                            <input type="hidden" name="return_view" value="<?= $archiveView ? 'archive' : 'active' ?>">
                                            <button type="submit" class="btn btn-sm <?= $archiveView ? 'btn-outline-success' : 'btn-outline-secondary' ?>" <?= !$archiveReady ? 'disabled' : '' ?>>
                                                <i class="bi bi-<?= $archiveView ? 'arrow-counterclockwise' : 'archive' ?> me-1"></i><?= $archiveView ? 'Restore' : 'Archive' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payment_history)): ?>
                                <tr><td colspan="7" class="table-empty-state text-muted" style="border: none;"><?= $archiveView ? 'No archived payment history.' : 'No payment history yet.' ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/receipt_modal.php';

require_once '../includes/footer.php'; ?>
