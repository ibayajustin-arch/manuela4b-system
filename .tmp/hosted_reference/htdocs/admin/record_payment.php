<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';

if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

ensure_archive_columns($conn, 'homeowners');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
$homeownerId = filter_var(
    $_POST['homeowner_id'] ?? $_GET['homeowner_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$homeowner = null;
$error = '';
$today = date('Y-m-d');
$paymentMethods = [
    'Cash' => 'Cash',
    'GCash (admin-assisted)' => 'GCash (admin-assisted)',
    'Maya (admin-assisted)' => 'Maya (admin-assisted)',
    'Bank Transfer' => 'Bank Transfer',
    'Other' => 'Other',
];

if ($homeownerId !== false && $homeownerId !== null) {
    $stmt = $conn->prepare('SELECT * FROM homeowners WHERE id = ? AND archived_at IS NULL LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare homeowner lookup.');
    }
    $stmt->bind_param('i', $homeownerId);
    $stmt->execute();
    $homeowner = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (!$homeowner) {
        $error = 'Homeowner not found. Please select a valid homeowner.';
    } else {
        $period = trim((string) ($_POST['period_covered'] ?? ''));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $date = DateTime::createFromFormat('!Y-m-d', $paymentDate);
        $dateValid = $date && $date->format('Y-m-d') === $paymentDate && $paymentDate <= $today;

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $error = 'Please select a valid unpaid month.';
        } elseif (!$dateValid) {
            $error = 'Please enter a valid payment date that is not in the future.';
        } elseif (!isset($paymentMethods[$paymentMethod])) {
            $error = 'Please select a valid payment method.';
        } elseif (strlen($reference) > 100 || strlen($notes) > 500) {
            $error = 'The reference or notes exceed the allowed length.';
        } else {
            $transactionStarted = false;
            try {
                $conn->begin_transaction();
                $transactionStarted = true;

                $lockStmt = $conn->prepare('SELECT * FROM homeowners WHERE id = ? AND archived_at IS NULL FOR UPDATE');
                if (!$lockStmt) {
                    throw new RuntimeException('Unable to lock homeowner record.');
                }
                $lockStmt->bind_param('i', $homeownerId);
                $lockStmt->execute();
                $lockedHomeowner = $lockStmt->get_result()->fetch_assoc();
                $lockStmt->close();
                if (!$lockedHomeowner) {
                    throw new InvalidArgumentException('Homeowner not found. Please select a valid homeowner.');
                }

                $summary = homeowner_account_summary($conn, $lockedHomeowner);
                if (!in_array($period, $summary['unpaid'], true)) {
                    throw new InvalidArgumentException('That month is already paid or is not due for this homeowner.');
                }

                $receiptNo = 'ADM-' . strtoupper(bin2hex(random_bytes(5)));
                $recordNotes = 'Recorded by admin';
                if ($reference !== '') {
                    $recordNotes .= ' | Reference: ' . $reference;
                }
                if ($notes !== '') {
                    $recordNotes .= ' | ' . $notes;
                }
                $amount = $summary['monthly_due'];
                $collectorId = (int) $_SESSION['user_id'];
                $stmt = $conn->prepare(
                    'INSERT INTO payments (homeowner_id, amount_paid, payment_date, period_covered, receipt_no, payment_method, collector_id, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare payment record.');
                }
                $stmt->bind_param(
                    'idssssis',
                    $homeownerId,
                    $amount,
                    $paymentDate,
                    $period,
                    $receiptNo,
                    $paymentMethod,
                    $collectorId,
                    $recordNotes
                );
                $stmt->execute();
                $paymentId = $stmt->insert_id;
                $stmt->close();

                $conn->commit();
                $transactionStarted = false;

                log_activity(
                    $conn,
                    $homeownerId,
                    'Recorded payment',
                    'Receipt ' . $receiptNo . ' for ' . date('F Y', strtotime($period . '-01'))
                );
                $_SESSION['flash_success'] = 'Payment recorded successfully. You can view or print the receipt from the homeowner profile.';
                header('Location: homeowner_view.php?id=' . $homeownerId . '#payment-' . $paymentId);
                exit;
            } catch (InvalidArgumentException $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $error = $e->getMessage();
            } catch (Throwable $e) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('Admin payment recording failed for homeowner ' . (int) $homeownerId . ': ' . $e->getMessage());
                $error = 'We could not record this payment. Please try again.';
            }
        }
    }
}

$summary = $homeowner ? homeowner_account_summary($conn, $homeowner) : null;
$unpaidMonths = $summary['unpaid'] ?? [];
$selectedPeriod = (string) ($_POST['period_covered'] ?? ($unpaidMonths[0] ?? ''));
$selectedMethod = (string) ($_POST['payment_method'] ?? 'Cash');
$selectedDate = (string) ($_POST['payment_date'] ?? $today);
$reference = (string) ($_POST['reference'] ?? '');
$notes = (string) ($_POST['notes'] ?? '');

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <div>
                <a href="<?= $homeowner ? 'homeowner_view.php?id=' . (int) $homeownerId : 'homeowners.php' ?>" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <h1>Record Payment</h1>
                <p>Record a dues payment received with the homeowner’s assistance.</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger border-0 rounded-3"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!$homeowner): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="mb-3">Choose a homeowner to record a payment.</p>
                    <a href="homeowners.php" class="btn btn-primary">Go to Homeowners</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm" style="max-width: 760px;">
                <div class="card-body p-4">
                    <div class="mb-4">
                        <h2 class="h5 fw-bold mb-1"><?= htmlspecialchars($homeowner['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-muted mb-0">
                            <?= htmlspecialchars(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            &middot; Monthly dues: ₱<?= number_format((float) $summary['monthly_due'], 2) ?>
                        </p>
                    </div>

                    <?php if (!$unpaidMonths): ?>
                        <div class="alert alert-success mb-0">
                            <i class="bi bi-check-circle me-2"></i>This homeowner has no unpaid months to record.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info border-0">
                            Record a payment only after the homeowner has paid. For assisted GCash or Maya payments, complete and verify the transaction in the wallet first.
                        </div>
                        <form method="POST" data-fullscreen-loading="Recording payment..." data-loading-duration="900">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="homeowner_id" value="<?= (int) $homeownerId ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="periodCovered" class="form-label">Unpaid month</label>
                                    <select id="periodCovered" name="period_covered" class="form-select" required>
                                        <?php foreach ($unpaidMonths as $month): ?>
                                            <option value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedPeriod === $month ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(date('F Y', strtotime($month . '-01')), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="paymentDate" class="form-label">Date paid</label>
                                    <input type="date" id="paymentDate" name="payment_date" class="form-control"
                                           max="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>"
                                           value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="paymentMethod" class="form-label">Payment method</label>
                                    <select id="paymentMethod" name="payment_method" class="form-select" required>
                                        <?php foreach ($paymentMethods as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedMethod === $value ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="reference" class="form-label">Transaction/reference number <span class="text-muted">(optional)</span></label>
                                    <input type="text" id="reference" name="reference" class="form-control" maxlength="100"
                                           value="<?= htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-12">
                                    <label for="notes" class="form-label">Notes <span class="text-muted">(optional)</span></label>
                                    <textarea id="notes" name="notes" class="form-control" rows="3" maxlength="500"><?= htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-4">
                                <strong class="fs-5">Amount due: ₱<?= number_format((float) $summary['monthly_due'], 2) ?></strong>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-1"></i> Confirm and Record Payment
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php require_once '../includes/footer.php'; ?>
