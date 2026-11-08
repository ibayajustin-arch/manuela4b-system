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

<style>
    /* Payment history: full table on desktop, one compact card per payment on phones.
       Details (receipt no., date, status) are on the receipt itself. */
    .resident-payments-page .rp-paid-on { display: none; }
    .resident-payments-page .rp-action-label { display: none; }

    @media (max-width: 767.98px) {
        html body .resident-payments-page .table-responsive:has(.resident-payments-table) {
            overflow: visible !important;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }
        html body .resident-payments-page .card:has(.resident-payments-table) {
            border: 0 !important;
            background: transparent !important;
        }
        html body .resident-payments-page .resident-payments-table {
            display: block !important;
            width: 100% !important;
            min-width: 0 !important;
            max-width: 100% !important;
        }
        html body .resident-payments-page .resident-payments-table thead { display: none !important; }
        html body .resident-payments-page .resident-payments-table tbody {
            display: grid !important;
            gap: .6rem;
            width: 100%;
        }

        html body .resident-payments-page .resident-payments-table tbody tr.rp-row {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) auto auto;
            grid-template-areas:
                "month receipt archive"
                "amount receipt archive";
            align-items: center;
            gap: .15rem .45rem;
            width: 100%;
            padding: .75rem .8rem;
            border: 1px solid var(--border-color-soft, #CBE3DC);
            border-radius: 12px;
            background: #FFFFFF;
        }
        html body .resident-payments-page .resident-payments-table tbody tr.rp-row > td:not(.m-hide) {
            display: block !important;
            min-width: 0;
            padding: 0 !important;
            border: 0 !important;
            background: transparent !important;
            white-space: normal !important;
            text-align: left !important;
        }
        html body .resident-payments-page .resident-payments-table tbody tr.rp-row > td::before {
            content: none !important;
            display: none !important;
        }

        html body .resident-payments-page .resident-payments-table td.rp-desc { grid-area: month; }
        html body .resident-payments-page .resident-payments-table td.rp-amount { grid-area: amount; }
        html body .resident-payments-page .resident-payments-table td.rp-receipt { grid-area: receipt; }
        html body .resident-payments-page .resident-payments-table td.rp-actions { grid-area: archive; }

        html body .resident-payments-page .rp-kind { display: none; }
        html body .resident-payments-page .rp-month {
            color: var(--text-heading, #0F1F1B);
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.3;
        }
        html body .resident-payments-page .resident-payments-table td.rp-amount {
            color: var(--text-muted, #4A5C57);
            font-size: .9rem !important;
            font-weight: 600;
        }
        html body .resident-payments-page .rp-paid-on { display: inline; font-weight: 500; }
        html body .resident-payments-page .resident-payments-table td form { display: inline-flex; margin: 0; }

        html body .resident-payments-page .resident-payments-table tbody tr.rp-row > td.m-hide { display: none !important; }

        /* Action name above each icon button */
        html body .resident-payments-page .resident-payments-table tbody tr.rp-row > td.rp-receipt,
        html body .resident-payments-page .resident-payments-table tbody tr.rp-row > td.rp-actions {
            display: flex !important;
            flex-direction: column;
            align-items: center;
            gap: .2rem;
            text-align: center !important;
        }
        html body .resident-payments-page .rp-action-label {
            display: block;
            color: var(--text-muted, #4A5C57);
            font-size: .75rem;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
        }
        html body .resident-payments-page .resident-payments-table td .btn-label { display: none !important; }

        html body .resident-payments-page .resident-payments-table tbody tr.rp-empty,
        html body .resident-payments-page .resident-payments-table tbody tr.rp-empty > td {
            display: block !important;
            padding: 1.5rem 1rem !important;
            text-align: center !important;
        }

        html body .resident-payments-page .payment-balance-card.balance-card-success {
            justify-content: flex-start;
            gap: .35rem;
        }
        html body .resident-payments-page .payment-balance-card.balance-card-success .payment-balance-value {
            margin: .35rem 0 0;
            line-height: 1.15;
        }
        html body .resident-payments-page .payment-balance-card.balance-card-success .payment-balance-note {
            margin: 0;
            line-height: 1.4;
        }
        html body .resident-payments-page .payment-balance-card.balance-card-success .payment-next-due {
            margin-top: auto;
        }
    }
</style>

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
                                <?php foreach ($payment_history as $p):
                                    $hasDate = !empty($p['payment_date']) && $p['payment_date'] !== '0000-00-00';
                                    $periodRaw = (string) ($p['period_covered'] ?? '');
                                    $monthLabel = preg_match('/^\d{4}-\d{2}$/', $periodRaw)
                                        ? date('F Y', strtotime($periodRaw . '-01'))
                                        : $periodRaw;
                                    $paidOn = $hasDate ? date('M d, Y', strtotime($p['payment_date'])) : '';
                                ?>
                                <tr class="rp-row">
                                    <td class="rp-date m-hide"><?= $hasDate ? $paidOn : '-' ?></td>
                                    <td class="rp-desc">
                                        <span class="rp-kind">Monthly Dues · </span><span class="rp-month"><?= htmlspecialchars($monthLabel) ?></span>
                                    </td>
                                    <td class="rp-ref text-muted small m-hide"><?= htmlspecialchars($p['receipt_no'] ?? '-') ?></td>
                                    <td class="rp-amount fw-semibold">
                                        ₱ <?= number_format((float) $p['amount_paid'], 2) ?><?php if ($paidOn): ?><span class="rp-paid-on"> · Paid <?= $paidOn ?></span><?php endif; ?>
                                    </td>
                                    <td class="rp-status m-hide">
                                        <span class="badge badge-success"><i class="bi bi-check-circle-fill me-1"></i>Paid</span>
                                    </td>
                                    <td class="rp-receipt">
                                        <?php if ($hasDate): ?>
                                            <span class="rp-action-label" aria-hidden="true">Receipt</span>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary act-icon"
                                                    title="View receipt"
                                                    aria-label="View receipt for <?= htmlspecialchars($monthLabel) ?>"
                                                    onclick="openReceipt('payment', <?= (int) $p['id'] ?>)">
                                                <i class="bi bi-receipt"></i><span class="btn-label ms-1">Receipt</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="rp-actions">
                                        <span class="rp-action-label" aria-hidden="true"><?= $archiveView ? 'Restore' : 'Archive' ?></span>
                                        <form method="POST" action="payment_archive.php" class="d-inline" onsubmit="return confirm('<?= $archiveView ? 'Restore' : 'Archive' ?> this receipt?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $archiveView ? 'restore' : 'archive' ?>">
                                            <input type="hidden" name="return_view" value="<?= $archiveView ? 'archive' : 'active' ?>">
                                            <button type="submit"
                                                    class="btn btn-sm act-icon <?= $archiveView ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                                    title="<?= $archiveView ? 'Restore receipt' : 'Archive receipt' ?>"
                                                    aria-label="<?= $archiveView ? 'Restore' : 'Archive' ?> receipt for <?= htmlspecialchars($monthLabel) ?>"
                                                    <?= !$archiveReady ? 'disabled' : '' ?>>
                                                <i class="bi bi-<?= $archiveView ? 'arrow-counterclockwise' : 'archive' ?>"></i><span class="btn-label ms-1"><?= $archiveView ? 'Restore' : 'Archive' ?></span>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payment_history)): ?>
                                <tr class="rp-empty"><td colspan="7" class="table-empty-state text-muted" style="border: none;"><?= $archiveView ? 'No archived payment history.' : 'No payment history yet.' ?></td></tr>
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