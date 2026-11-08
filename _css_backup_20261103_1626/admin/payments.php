<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/archive_helpers.php';
require_once '../includes/payment_methods.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: login.php");
    exit; 
}

$csrf = generateCsrfToken();
$archiveView = ($_GET['view'] ?? '') === 'archive';
$archiveReady = true;
$archiveError = null;
try {
    ensure_archive_columns($conn, 'payments');
} catch (Throwable $e) {
    error_log('Payment archive setup failed: ' . $e->getMessage());
    $archiveReady = false;
    $archiveError = 'Receipt archives are unavailable. Please contact the administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive_payment', 'restore_payment'], true)) {
    $action = $_POST['action'];
    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_error'] = 'Your session expired. Please refresh the page and try again.';
    } elseif (!$archiveReady) {
        $_SESSION['flash_error'] = $archiveError;
    } elseif ($paymentId <= 0) {
        $_SESSION['flash_error'] = 'The selected receipt could not be found.';
    } else {
        try {
            if ($action === 'archive_payment') {
                $stmt = $conn->prepare('UPDATE payments SET archived_at = NOW() WHERE id = ? AND archived_at IS NULL');
                $successMessage = 'Receipt archived. You can restore it from Payment Records → Archives.';
            } else {
                $stmt = $conn->prepare('UPDATE payments SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL');
                $successMessage = 'Receipt restored successfully.';
            }
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('i', $paymentId);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            $_SESSION[$changed ? 'flash_success' : 'flash_error'] = $changed ? $successMessage : 'Receipt not found or already updated.';
        } catch (Throwable $e) {
            error_log('Payment archive action failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'The receipt could not be updated.';
        }
    }
    header('Location: payments.php' . ($action === 'restore_payment' ? '?view=archive' : ''));
    exit;
}

$search = trim($_GET['search'] ?? '');
$street = trim($_GET['street'] ?? '');
$method = trim($_GET['method'] ?? '');
$from_date = trim($_GET['from'] ?? '');
$to_date = trim($_GET['to'] ?? '');
$streets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];
if (!in_array(strtolower($street), array_map('strtolower', $streets), true)) $street = '';

$where = [];
$params = [];
$types = '';

if (!$archiveView) {
    $where[] = 'payments.archived_at IS NULL';
} else {
    $where[] = 'payments.archived_at IS NOT NULL';
}

if ($search !== '') {
    $where[] = "(payments.receipt_no LIKE ? OR homeowners.full_name LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($street !== '') {
    $where[] = 'LOWER(TRIM(homeowners.block)) = LOWER(?)';
    $params[] = $street;
    $types .= 's';
}

if ($method !== '') {
    $where[] = payment_method_sql_expression('payments') . ' = ?';
    $params[] = $method;
    $types .= 's';
}

if ($from_date !== '') {
    $where[] = "DATE(payments.payment_date) >= ?";
    $params[] = $from_date;
    $types .= 's';
}

if ($to_date !== '') {
    $where[] = "DATE(payments.payment_date) <= ?";
    $params[] = $to_date;
    $types .= 's';
}

$whereSql = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!$archiveReady) {
        http_response_code(503);
        exit('Receipt archives are unavailable. Please contact the administrator.');
    }
    $exportSql = "SELECT payments.*, homeowners.full_name AS homeowner_name, homeowners.block, homeowners.lot 
                  FROM payments 
                  LEFT JOIN homeowners ON payments.homeowner_id = homeowners.id" . $whereSql . " ORDER BY payments.id DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($exportSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($exportSql);
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Receipt No', 'Homeowner Name', 'Property Address', 'Date Paid', 'Period Covered', 'Amount (PHP)', 'Payment Method', 'Notes']);
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [
            $r['receipt_no'],
            $r['homeowner_name'] ?? 'Homeowner #' . $r['homeowner_id'],
            homeowner_address_label($r['block'] ?? '', $r['lot'] ?? ''),
            $r['payment_date'],
            $r['period_covered'],
            number_format((float) $r['amount_paid'], 2, '.', ''),
            payment_method_label($r['payment_method'] ?? null, $r['notes'] ?? null),
            $r['notes']
        ]);
    }
    fclose($out);
    exit;
}

$totalRows = 0;
$result = false;
if ($archiveReady) {
    $countSql = "SELECT COUNT(*) AS total FROM payments LEFT JOIN homeowners ON payments.homeowner_id = homeowners.id" . $whereSql;
    if (!empty($params)) {
        $stmt = $conn->prepare($countSql);
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $totalRows = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $countResult = $conn->query($countSql);
        if (!$countResult) throw new RuntimeException($conn->error);
        $totalRows = (int) $countResult->fetch_assoc()['total'];
    }
}

$perPage = 20;
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

if ($archiveReady) {
    $query = "SELECT payments.*, homeowners.full_name AS homeowner_name, homeowners.block, homeowners.lot 
              FROM payments 
              LEFT JOIN homeowners ON payments.homeowner_id = homeowners.id" . $whereSql . " 
              ORDER BY payments.id DESC LIMIT $perPage OFFSET $offset";

    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
        if (!$result) throw new RuntimeException($conn->error);
    }
}

$availableMethods = [];
try {
    if (!$archiveReady) throw new RuntimeException($archiveError);
    $archiveFilter = $archiveView ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
    $mRes = $conn->query("SELECT payment_method, notes FROM payments WHERE {$archiveFilter} ORDER BY payment_method ASC");
    if ($mRes) {
        while ($mr = $mRes->fetch_assoc()) {
            $label = payment_method_label($mr['payment_method'] ?? null, $mr['notes'] ?? null);
            $availableMethods[$label] = $label;
        }
    }
    // Keep wallet providers available as filters even when no matching receipt exists yet.
    $availableMethods['GCash'] = 'GCash';
    $availableMethods['Maya'] = 'Maya';
    sort($availableMethods, SORT_NATURAL | SORT_FLAG_CASE);
} catch (Exception $e) {}

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-end gap-2 mb-4">
                <a href="payments.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-<?= $archiveView ? 'cash-stack' : 'archive' ?> me-1"></i><?= $archiveView ? 'Back to Payments' : 'Archives' ?>
                </a>
                <?php if (!$archiveView): ?>
                    <a href="payments.php?export=csv<?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($street) ? '&street=' . urlencode($street) : '' ?><?= !empty($method) ? '&method=' . urlencode($method) : '' ?><?= !empty($from_date) ? '&from=' . urlencode($from_date) : '' ?><?= !empty($to_date) ? '&to=' . urlencode($to_date) : '' ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                    </a>
                <?php endif; ?>
        </div>
        <?php if ($archiveError): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($archiveError) ?></div>
        <?php endif; ?>
        <?php foreach (['flash_success' => 'success', 'flash_error' => 'danger'] as $flashKey => $alertType): ?>
            <?php if (!empty($_SESSION[$flashKey])): ?>
                <div class="alert alert-<?= $alertType ?>"><?= htmlspecialchars($_SESSION[$flashKey]) ?></div>
                <?php unset($_SESSION[$flashKey]); ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="payments.php" class="row g-3 align-items-end">
                    <?php if ($archiveView): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label" for="paymentsSearch">Search</label>
                        <div class="cir-search">
                            <i class="bi bi-search cir-search__icon" aria-hidden="true"></i>
                            <input type="search" id="paymentsSearch" name="search" class="cir-search__field" placeholder="Receipt no. or homeowner name" value="<?= htmlspecialchars($search) ?>">
                            <span class="cir-search__kbd" aria-hidden="true">Enter</span>
                        </div>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label">Street</label>
                        <select name="street" class="form-select">
                            <option value="">All Streets</option>
                            <?php foreach ($streets as $streetOption): ?>
                                <option value="<?= htmlspecialchars($streetOption) ?>" <?= strcasecmp($street, $streetOption) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($streetOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label">Payment Method</label>
                        <select name="method" class="form-select">
                            <option value="">All Methods</option>
                            <?php foreach ($availableMethods as $mOption): ?>
                                <option value="<?= htmlspecialchars($mOption) ?>" <?= $method === $mOption ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($mOption, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                    </div>
                    <div class="col-md-4 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1" title="Search">
                            <i class="bi bi-search me-1"></i>Search
                        </button>
                        <?php if ($search !== '' || $street !== '' || $method !== '' || $from_date !== '' || $to_date !== ''): ?>
                            <a href="payments.php<?= $archiveView ? '?view=archive' : '' ?>" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive border-0 shadow-none p-0">
                    <table class="table table-hover align-middle mb-0 admin-payments-table">
                        <thead>
                            <tr>
                                <th>Receipt No.</th>
                                <th>Homeowner</th>
                                <th>Date Paid</th>
                                <th>Period Covered</th>
                                <th>Method</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($result && mysqli_num_rows($result) > 0) {
                                while ($row = mysqli_fetch_assoc($result)) { 
                                    $isPaid = !empty($row['payment_date']) && $row['payment_date'] !== '0000-00-00';
                                    $paymentStatus = $isPaid ? 'Paid' : 'Pending';
                                    $formattedDate = $isPaid ? date("M d, Y", strtotime($row['payment_date'])) : "-";
                                    $displayName = !empty($row['homeowner_name']) ? $row['homeowner_name'] : 'Homeowner #' . $row['homeowner_id'];
                                    $paymentMethodLabel = payment_method_label($row['payment_method'] ?? null, $row['notes'] ?? null);
                                    $residentAddress = homeowner_address_label($row['block'] ?? '', $row['lot'] ?? '');
                            ?>
                                <tr id="payment-record-<?= (int) $row['id'] ?>">
                                    <td class="fw-bold">
                                        <?= !empty($row['receipt_no']) ? htmlspecialchars($row['receipt_no']) : '-' ?>
                                    </td>
                                    
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($displayName) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($residentAddress, ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    
                                    <td class="text-muted"><?= $formattedDate ?></td>
                                    
                                    <td>
                                        <span class="badge" style="background: var(--bg-color); color: var(--text-heading); border: 1px solid var(--border-color);">
                                            <?= htmlspecialchars($row['period_covered']) ?>
                                        </span>
                                    </td>

                                    <td><?= payment_method_indicator_html($row['payment_method'] ?? null, $row['notes'] ?? null) ?></td>
                                    
                                    <td class="text-end fw-bold text-dark">₱ <?= number_format($row['amount_paid'], 2) ?></td>
                                    
                                    <td class="text-center">
                                        <?php if($paymentStatus === 'Paid'): ?>
                                            <span class="badge badge-success"><i class="bi bi-check-circle me-1"></i>Paid</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="text-center payment-record-actions-cell">
                                        <div class="payment-record-actions">
                                            <?php if($paymentStatus === 'Paid'): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        onclick="openReceipt('payment', <?= (int) $row['id'] ?>)">
                                                    <i class="bi bi-receipt me-1"></i> View Receipt
                                                </button>
                                            <?php elseif (!$archiveView): ?>
                                                <button type="button"
                                                        class="btn btn-sm"
                                                        style="background-color: var(--warning-soft); color: var(--warning-color);"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#reminderModal"
                                                        onclick="openReminderModal('<?= $row['id'] ?>', '<?= htmlspecialchars(addslashes($displayName)) ?>', '<?= htmlspecialchars(addslashes($row['period_covered'])) ?>', '<?= $row['amount_paid'] ?>')">
                                                    <i class="bi bi-envelope-paper-fill me-1"></i> Send Reminder
                                                </button>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return <?= $archiveView ? 'confirmReceiptRestore(this)' : "confirm('Archive this payment receipt?')" ?>;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="payment_id" value="<?= (int) $row['id'] ?>">
                                                <input type="hidden" name="action" value="<?= $archiveView ? 'restore_payment' : 'archive_payment' ?>">
                                                <button type="submit" class="btn btn-sm <?= $archiveView ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                                    title="<?= $archiveView ? 'Restore receipt' : 'Archive receipt' ?>"
                                                    aria-label="<?= $archiveView ? 'Restore' : 'Archive' ?> receipt <?= htmlspecialchars($row['receipt_no'] ?? '') ?>">
                                                    <i class="bi bi-<?= $archiveView ? 'arrow-counterclockwise' : 'archive' ?> me-1"></i><?= $archiveView ? 'Restore' : 'Archive' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php 
                                } 
                            } else {
                                echo "<tr><td colspan='8' class='table-empty-state py-5 text-muted'>" . ($archiveView ? 'No archived receipts found.' : 'No payment records found matching your filters.') . "</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-top" style="border-color: var(--border-color-soft) !important;">
                        <div class="text-muted small">
                            Showing <?= min($totalRows, $offset + 1) ?> to <?= min($totalRows, $offset + $perPage) ?> of <?= number_format($totalRows) ?> records
                        </div>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="payments.php?<?= $archiveView ? 'view=archive&' : '' ?>page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($street) ? '&street=' . urlencode($street) : '' ?><?= !empty($method) ? '&method=' . urlencode($method) : '' ?><?= !empty($from_date) ? '&from=' . urlencode($from_date) : '' ?><?= !empty($to_date) ? '&to=' . urlencode($to_date) : '' ?>">Previous</a>
                                </li>
                            <?php endif; ?>

                            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="payments.php?<?= $archiveView ? 'view=archive&' : '' ?>page=<?= $p ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($street) ? '&street=' . urlencode($street) : '' ?><?= !empty($method) ? '&method=' . urlencode($method) : '' ?><?= !empty($from_date) ? '&from=' . urlencode($from_date) : '' ?><?= !empty($to_date) ? '&to=' . urlencode($to_date) : '' ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="payments.php?<?= $archiveView ? 'view=archive&' : '' ?>page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($street) ? '&street=' . urlencode($street) : '' ?><?= !empty($method) ? '&method=' . urlencode($method) : '' ?><?= !empty($from_date) ? '&from=' . urlencode($from_date) : '' ?><?= !empty($to_date) ? '&to=' . urlencode($to_date) : '' ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php if ($archiveView): ?>
<div class="modal fade" id="receiptRestoreConfirmModal" tabindex="-1" aria-labelledby="receiptRestoreConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-4 p-sm-5 text-center">
                <div class="payment-confirm-icon" aria-hidden="true">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </div>
                <h2 class="h4 fw-bold mb-3" id="receiptRestoreConfirmTitle">Restore this receipt?</h2>
                <p class="text-muted mb-4">
                    This will move the receipt back to the active Payment Records list, where it can be viewed with current receipts.
                </p>
                <div class="d-flex flex-column flex-sm-row justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmReceiptRestoreButton">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Restore receipt
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
let receiptRestoreForm = null;

function confirmReceiptRestore(form) {
    if (form.dataset.restoreConfirmed === 'true') {
        delete form.dataset.restoreConfirmed;
        return true;
    }

    receiptRestoreForm = form;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('receiptRestoreConfirmModal')).show();
    return false;
}

document.getElementById('confirmReceiptRestoreButton').addEventListener('click', function () {
    if (!receiptRestoreForm) return;
    const form = receiptRestoreForm;
    receiptRestoreForm = null;
    form.dataset.restoreConfirmed = 'true';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('receiptRestoreConfirmModal')).hide();
    form.requestSubmit();
});
</script>
<?php endif; ?>

<div class="modal fade" id="reminderModal" tabindex="-1" aria-labelledby="reminderModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0" style="background-color: #f8f9fa;">
      <div class="modal-header border-0 pb-0 justify-content-center position-relative">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 pt-2">
        
        <div class="bg-white receipt-light-surface p-4 p-md-5 rounded-3 shadow-sm border-start" style="border-left-width: 6px !important; border-left-color: var(--warning-color) !important;">
            
            <div class="row mb-4 align-items-center border-bottom pb-4">
                <div class="col-sm-7 d-flex align-items-center gap-3">
                    <div class="bg-warning rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px;">
                        <i class="bi bi-envelope-paper-fill text-white fs-3"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark">Payment Reminder</h4>
                        <p class="text-muted small mb-0 text-uppercase tracking-wide">Email Notification</p>
                    </div>
                </div>
                <div class="col-sm-5 text-sm-end mt-3 mt-sm-0">
                    <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Status</span>
                    <span class="badge badge-warning px-3 py-2 fs-6">Pending / Overdue</span>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-sm-7">
                    <div class="mb-4">
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Send To</span>
                        <span id="remHomeownerName" class="fw-bold text-dark d-block fs-5"></span>
                    </div>
                </div>
                <div class="col-sm-5 text-sm-end mt-4 mt-sm-0">
                    <div class="mb-4">
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Payment For</span>
                        <span id="remDescription" class="fw-semibold text-dark d-block fs-6"></span>
                    </div>
                </div>
            </div>

            <div class="receipt-total-box rounded-3 p-4 d-flex justify-content-between align-items-center mt-2" style="background-color: var(--bg-color);">
                <span class="text-muted fw-bold text-uppercase" style="font-size: 0.9rem; letter-spacing: 0.5px;">Amount Due</span>
                <span class="fs-2 fw-bold text-warning mb-0">₱ <span id="remAmount"></span></span>
            </div>


            <div class="mt-4 pt-2 text-center">
                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle-fill text-warning me-1"></i> 
                    Magpapadala ang system ng <b>official email reminder</b> sa homeowner na ito.
                </p>
            </div>

        </div>

      </div>
      <div class="modal-footer border-0 pt-0 pb-4 px-4">
        <button type="button" class="btn btn-outline-secondary px-4 rounded-pill me-auto" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn px-4 rounded-pill fw-bold shadow-sm" style="background-color: var(--warning-color); color: #fff; border: none;" onclick="executeReminder()">
            <i class="bi bi-send-fill me-2"></i> Send Email
        </button>
      </div>
    </div>
  </div>
</div>

<script>
let currentPaymentIdToRemind = null;
const REMINDER_CSRF_TOKEN = <?= json_encode($csrf) ?>;

function openReminderModal(paymentId, homeownerName, description, amount) {
    currentPaymentIdToRemind = paymentId;
    
    document.getElementById('remHomeownerName').innerText = homeownerName;
    document.getElementById('remDescription').innerText = description;
    
    let formattedAmount = parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('remAmount').innerText = formattedAmount;
}

function executeReminder() {
    if (!currentPaymentIdToRemind) return;

    fetch('send_reminder.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            payment_id: currentPaymentIdToRemind,
            csrf: REMINDER_CSRF_TOKEN
        })
    })
    .then(response => response.json())
    .then(data => {
        let reminderModal = bootstrap.Modal.getInstance(document.getElementById('reminderModal'));
        reminderModal.hide();

        if(data.status === 'success') {
            alert("Success: " + data.message);
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("An error occurred while sending the reminder. Please check the network or console.");
    });
}
</script>

<?php require_once '../includes/receipt_modal.php';
require_once '../includes/footer.php'; ?>
