<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/archive_helpers.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/payment_methods.php';
require_once '../includes/payment_recording.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

ensure_archive_columns($conn, 'payments');

$paymentId = (int) ($_POST['payment_id'] ?? $_GET['id'] ?? 0);
$today = date('Y-m-d');
$error = '';

$loadPayment = static function () use ($conn, $paymentId): ?array {
    $stmt = $conn->prepare('SELECT p.*, h.full_name, h.block, h.lot
                            FROM payments p
                            LEFT JOIN homeowners h ON h.id = p.homeowner_id
                            WHERE p.id = ? AND p.archived_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

$payment = $loadPayment();

// PayMongo receipts were confirmed by the payment gateway, so their details stay as they are.
if (!$payment || strpos((string) $payment['receipt_no'], 'PM-') === 0) {
    $_SESSION['flash_error'] = $payment ? 'Online PayMongo payments cannot be edited.' : 'The selected receipt could not be found.';
    header('Location: payments.php');
    exit;
}

$methods = recorded_payment_methods();
$currentMethod = (string) ($payment['payment_method'] ?? '');
// Older records may use a method that can no longer be chosen. Keep it selectable so saving does not change it.
if ($currentMethod !== '' && !isset($methods[$currentMethod])) {
    $methods[$currentMethod] = payment_method_label($currentMethod, $payment['notes'] ?? null);
}

$values = [
    'period_covered' => (string) $payment['period_covered'],
    'payment_date' => substr((string) $payment['payment_date'], 0, 10),
    'amount_paid' => number_format((float) $payment['amount_paid'], 2, '.', ''),
    'payment_method' => $currentMethod,
    'notes' => (string) ($payment['notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $_value) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $date = DateTime::createFromFormat('!Y-m-d', $values['payment_date']);

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $values['period_covered'])) {
        $error = 'Please choose a valid month.';
    } elseif (!$date || $date->format('Y-m-d') !== $values['payment_date'] || $values['payment_date'] > $today) {
        $error = 'Please enter a valid payment date that is not in the future.';
    } elseif (!is_numeric($values['amount_paid']) || (float) $values['amount_paid'] <= 0 || (float) $values['amount_paid'] > 100000) {
        $error = 'Please enter an amount greater than zero and up to ₱100,000.';
    } elseif (!isset($methods[$values['payment_method']])) {
        $error = 'Please select a valid payment method.';
    } elseif (strlen($values['notes']) > 500) {
        $error = 'Notes must be 500 characters or fewer.';
    } else {
        $transactionStarted = false;
        try {
            $conn->begin_transaction();
            $transactionStarted = true;
            $homeownerId = (int) $payment['homeowner_id'];

            // Lock the homeowner so two edits cannot both claim the same month.
            $stmt = $conn->prepare('SELECT id FROM homeowners WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $homeownerId);
            $stmt->execute();
            $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare('SELECT id FROM payments WHERE homeowner_id = ? AND period_covered = ? AND id <> ? AND archived_at IS NULL LIMIT 1');
            $stmt->bind_param('isi', $homeownerId, $values['period_covered'], $paymentId);
            $stmt->execute();
            $monthTaken = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($monthTaken) {
                throw new InvalidArgumentException('This homeowner already has a payment for that month.');
            }

            $amount = round((float) $values['amount_paid'], 2);
            $stmt = $conn->prepare('UPDATE payments SET period_covered = ?, payment_date = ?, amount_paid = ?, payment_method = ?, notes = ? WHERE id = ? AND archived_at IS NULL');
            $stmt->bind_param('ssdssi', $values['period_covered'], $values['payment_date'], $amount, $values['payment_method'], $values['notes'], $paymentId);
            $stmt->execute();
            $stmt->close();

            // Keep a record of exactly what changed.
            $labels = ['period_covered' => 'month', 'payment_date' => 'date', 'amount_paid' => 'amount', 'payment_method' => 'method', 'notes' => 'notes'];
            $before = [
                'period_covered' => (string) $payment['period_covered'],
                'payment_date' => substr((string) $payment['payment_date'], 0, 10),
                'amount_paid' => number_format((float) $payment['amount_paid'], 2, '.', ''),
                'payment_method' => $currentMethod,
                'notes' => (string) ($payment['notes'] ?? ''),
            ];
            $after = $values;
            $after['amount_paid'] = number_format($amount, 2, '.', '');
            $changes = [];
            foreach ($labels as $field => $label) {
                if ($before[$field] !== $after[$field]) {
                    $changes[] = $field === 'notes' ? 'notes updated' : "{$label}: {$before[$field]} to {$after[$field]}";
                }
            }

            $conn->commit();
            $transactionStarted = false;

            if ($changes) {
                log_activity($conn, $homeownerId, 'Edited payment', 'Receipt ' . $payment['receipt_no'] . ' (' . implode(', ', $changes) . ')');
            }
            $_SESSION['flash_success'] = $changes ? 'Payment updated.' : 'No changes were made.';
            header('Location: payments.php');
            exit;
        } catch (InvalidArgumentException $e) {
            if ($transactionStarted) $conn->rollback();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($transactionStarted) $conn->rollback();
            error_log('Payment edit failed for payment ' . $paymentId . ': ' . $e->getMessage());
            $error = 'We could not update this payment. Please try again.';
        }
    }
}

$csrf = generateCsrfToken();
$e = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <div>
                <a href="payments.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Back</a>
                <h1>Edit Payment</h1>
                <p class="mb-0">Changes are saved to the homeowner's activity history.</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger border-0 rounded-3"><?= $e($error) ?></div>
        <?php endif; ?>

        <div class="card" style="max-width: 760px;">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-1"><?= $e($payment['full_name'] ?? ('Homeowner #' . $payment['homeowner_id'])) ?></h2>
                <p class="text-muted mb-4">Receipt <?= $e($payment['receipt_no']) ?> · <?= $e(homeowner_address_label($payment['block'] ?? '', $payment['lot'] ?? '')) ?></p>

                <form method="POST" data-fullscreen-loading="Saving payment..." data-loading-duration="900">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="payment_id" value="<?= (int) $paymentId ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="editPeriod" class="form-label">Month covered</label>
                            <input type="month" id="editPeriod" name="period_covered" class="form-control" value="<?= $e($values['period_covered']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="editDate" class="form-label">Date paid</label>
                            <input type="date" id="editDate" name="payment_date" class="form-control" max="<?= $e($today) ?>" value="<?= $e($values['payment_date']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="editAmount" class="form-label">Amount (₱)</label>
                            <input type="number" id="editAmount" name="amount_paid" class="form-control" min="0.01" max="100000" step="0.01" value="<?= $e($values['amount_paid']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="editMethod" class="form-label">Payment method</label>
                            <select id="editMethod" name="payment_method" class="form-select" required>
                                <?php foreach ($methods as $value => $label): ?>
                                    <option value="<?= $e($value) ?>" <?= $values['payment_method'] === (string) $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="editNotes" class="form-label">Reference and notes</label>
                            <textarea id="editNotes" name="notes" class="form-control" rows="3" maxlength="500"><?= $e($values['notes']) ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-4" style="min-height: 48px;"><i class="bi bi-check-circle me-1" aria-hidden="true"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once '../includes/footer.php'; ?>
