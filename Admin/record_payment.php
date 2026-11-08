<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';
require_once '../includes/payment_recording.php';

if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
    header('Location: login.php');
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
$paymentMethods = recorded_payment_methods();

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
        try {
            $saved = record_dues_payment(
                $conn,
                (int) $homeownerId,
                trim((string) ($_POST['period_covered'] ?? '')),
                trim((string) ($_POST['payment_date'] ?? '')),
                (string) ($_POST['payment_method'] ?? ''),
                trim((string) ($_POST['reference'] ?? '')),
                trim((string) ($_POST['notes'] ?? ''))
            );
            $_SESSION['flash_success'] = 'Payment recorded successfully. You can view or print the receipt from the homeowner profile.';
            header('Location: homeowner_view.php?id=' . (int) $homeownerId . '#payment-' . $saved['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Admin payment recording failed for homeowner ' . (int) $homeownerId . ': ' . $e->getMessage());
            $error = 'We could not record this payment. Please try again.';
        }
    }
}

$summary = $homeowner ? homeowner_account_summary($conn, $homeowner) : null;
$unpaidMonths = $summary['unpaid'] ?? [];
$selectedPeriod = (string) ($_POST['period_covered'] ?? ($unpaidMonths[0] ?? ''));
$selectedMethod = (string) ($_POST['payment_method'] ?? array_key_first($paymentMethods));
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
                            Record a payment only after the homeowner has paid. Complete and verify the GCash or Maya transaction in the wallet first.
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
                                    <label for="reference" class="form-label">Transaction/reference number</label>
                                    <input type="text" id="reference" name="reference" class="form-control" maxlength="100"
                                           value="<?= htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') ?>" required>
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
