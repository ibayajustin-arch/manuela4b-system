<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

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
$payment_history = [];

if ($homeowner) {
    $homeowner_id = $homeowner['id'];
    $current_period = date('Y-m');

    // A row existing in `payments` for this period means it's already paid
    // (this table only stores completed payments — there is no status column)
    $already_paid = false;
    try {
        $stmt = $conn->prepare("SELECT id FROM payments WHERE homeowner_id = ? AND period_covered = ? LIMIT 1");
        $stmt->bind_param("is", $homeowner_id, $current_period);
        $stmt->execute();
        $res = $stmt->get_result();
        $already_paid = $res->num_rows > 0;
        $stmt->close();
    } catch (Exception $e) {
        $already_paid = false;
    }

    $outstanding_balance = $already_paid ? 0 : (float) $homeowner['monthly_due'];

    // Fetch payment history
    try {
        $stmt = $conn->prepare("SELECT * FROM payments WHERE homeowner_id = ? ORDER BY payment_date DESC, id DESC");
        $stmt->bind_param("i", $homeowner_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $payment_history = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Exception $e) {
        $payment_history = [];
    }
}
?>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0">

            <h2 class="fw-bold mb-4">Online Payments</h2>

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

            <?php if (!$homeowner): ?>
                <div class="status-box mb-4" style="background-color: var(--warning-soft); border-color: rgba(217,119,6,0.15);">
                    <i class="bi bi-exclamation-triangle-fill status-box-icon" style="color: var(--warning-color);"></i>
                    <div>
                        <p class="status-box-title" style="color: var(--warning-color);">Account not linked</p>
                        <p class="status-box-subtitle">Your login is not yet linked to a homeowner record. Please contact the admin to enable online payments.</p>
                    </div>
                </div>
            <?php elseif ($outstanding_balance > 0): ?>
                <div class="row mb-4">
                    <div class="col-md-6 col-xl-4">
                        <div class="card p-4 text-white" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-active) 100%);">
                            <h6 class="text-white-50 text-uppercase fw-semibold mb-1">Outstanding Balance</h6>
                            <h1 class="fw-bold mb-1">₱ <?= number_format($outstanding_balance, 2) ?></h1>
                            <p class="mb-4 text-white-50 small">Monthly Dues (<?= htmlspecialchars($balance_period) ?>)</p>
                            <a href="create_checkout.php" class="btn btn-light text-primary fw-bold w-100">
                                <i class="bi bi-wallet2 me-2"></i> Pay Now Online
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="status-box status-box-success mb-4">
                    <i class="bi bi-check-circle-fill status-box-icon"></i>
                    <div>
                        <p class="status-box-title">All paid up!</p>
                        <p class="status-box-subtitle">You have no outstanding balance.</p>
                    </div>
                </div>
            <?php endif; ?>

            <h5 class="fw-bold mb-3 mt-5">My Payment History</h5>
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive border-0 shadow-none p-0">
                        <table class="table table-hover align-middle mb-0 payment-history-table">
                            <thead class="table-light">
                                <tr><th>Date</th><th>Description</th><th>Reference No.</th><th>Amount</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_history as $p): ?>
                                <tr>
                                    <td><?= !empty($p['payment_date']) && $p['payment_date'] !== '0000-00-00' ? date('M d, Y', strtotime($p['payment_date'])) : '-' ?></td>
                                    <td>Monthly Dues (<?= date('F Y', strtotime($p['period_covered'] . '-01')) ?>)</td>
                                    <td class="text-muted small"><?= htmlspecialchars($p['receipt_no'] ?? '-') ?></td>
                                    <td class="fw-semibold">₱ <?= number_format($p['amount_paid'], 2) ?></td>
                                    <td><span class="badge badge-success">Paid</span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payment_history)): ?>
                                <tr><td colspan="5" class="table-empty-state">No payment history yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>