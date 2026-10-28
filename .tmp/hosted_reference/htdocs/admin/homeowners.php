<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$archiveReady = true;
$archiveError = null;
try {
    ensure_archive_columns($conn, 'homeowners');
} catch (Throwable $e) {
    error_log('Homeowner archive setup failed: ' . $e->getMessage());
    $archiveReady = false;
    $archiveError = 'Archive storage is unavailable. Please contact the administrator.';
}

$archiveView = ($_GET['view'] ?? '') === 'archive';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive_homeowner', 'restore_homeowner'], true)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_msg'] = 'Your session expired. Please try again.';
        header('Location: homeowners.php' . (($_POST['action'] ?? '') === 'restore_homeowner' ? '?view=archive' : ''));
        exit;
    }
    $id = (int) ($_POST['homeowner_id'] ?? 0);
    $action = $_POST['action'];
    if (!$archiveReady) {
        $_SESSION['error_msg'] = $archiveError;
    } elseif ($id > 0) {
        try {
            if ($action === 'archive_homeowner') {
                $stmt = $conn->prepare("UPDATE homeowners SET archived_at = NOW(), archived_status = status, status = 'inactive' WHERE id = ? AND archived_at IS NULL");
                $successMessage = 'Homeowner archived. You can restore this account from the archives.';
            } else {
                $stmt = $conn->prepare('UPDATE homeowners SET status = COALESCE(archived_status, status), archived_status = NULL, archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL');
                $successMessage = 'Homeowner restored successfully.';
            }
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            $_SESSION[$changed ? 'success_msg' : 'error_msg'] = $changed ? $successMessage : 'Homeowner not found or already updated.';
        } catch (Throwable $e) {
            error_log('Homeowner archive action failed: ' . $e->getMessage());
            $_SESSION['error_msg'] = 'The homeowner account could not be updated.';
        }
    }
    header('Location: homeowners.php' . ($action === 'restore_homeowner' ? '?view=archive' : ''));
    exit;
}

$search    = $_GET['search'] ?? '';
$streets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];
$selectedStreet = trim((string) ($_GET['street'] ?? ''));
if (!in_array(strtolower($selectedStreet), array_map('strtolower', $streets), true)) $selectedStreet = '';
$onlyUnpaid = !$archiveView && isset($_GET['unpaid']) && $_GET['unpaid'] === '1';
$isCsvExport = ($_GET['export'] ?? '') === 'csv';
$sortBy    = $_GET['sort'] ?? 'newest';   // newest | balance
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 30;
$homeowners = [];
$totalRows = 0;
$streetCounts = array_fill_keys($streets, 0);

try {
    $archiveCondition = $archiveView ? 'h.archived_at IS NOT NULL' : 'h.archived_at IS NULL';
    if ($archiveReady) {
        $countSql = "SELECT h.block, COUNT(*) AS total FROM homeowners h WHERE {$archiveCondition} AND LOWER(COALESCE(h.status, '')) <> 'pending'";
        if (!$archiveView) {
            $counts = $conn->query("SELECT block, COUNT(*) AS total FROM homeowners WHERE archived_at IS NULL AND LOWER(COALESCE(status, '')) <> 'pending' GROUP BY block");
            if ($counts) while ($row = $counts->fetch_assoc()) foreach ($streets as $street) {
                if (strtolower(trim((string) $row['block'])) === strtolower($street)) $streetCounts[$street] = (int) $row['total'];
            }
        }
        $where = " WHERE {$archiveCondition} AND LOWER(COALESCE(h.status, '')) <> 'pending'";
        $params = [];
        $types = '';
        if ($selectedStreet !== '') { $where .= ' AND LOWER(h.block) = LOWER(?)'; $params[] = $selectedStreet; $types .= 's'; }
        if ($search !== '') {
            $where .= ' AND (h.block LIKE ? OR h.lot LIKE ? OR h.full_name LIKE ?)';
            $pattern = '%' . $search . '%';
            array_push($params, $pattern, $pattern, $pattern); $types .= 'sss';
        }
        $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM homeowners h{$where}");
        if ($countStmt) {
            if ($types !== '') $countStmt->bind_param($types, ...$params);
            $countStmt->execute();
            $totalRows = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();
        }
        $totalPages = max(1, (int) ceil($totalRows / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $listSql = "SELECT h.* FROM homeowners h{$where} ORDER BY h.created_at DESC";
        if (!$isCsvExport) $listSql .= ' LIMIT ? OFFSET ?';
        $listStmt = $conn->prepare($listSql);
        if ($listStmt) {
            if ($isCsvExport) {
                if ($types !== '') $listStmt->bind_param($types, ...$params);
            } else {
                $listTypes = $types . 'ii'; $listParams = array_merge($params, [$pageSize, $offset]);
                $listStmt->bind_param($listTypes, ...$listParams);
            }
            $listStmt->execute();
            $result = $listStmt->get_result();
            if ($result) $homeowners = $result->fetch_all(MYSQLI_ASSOC);
            $listStmt->close();
        }
    }
} catch (Exception $e) {
    error_log('Homeowner list could not be loaded: ' . $e->getMessage());
    $homeowners = [];
}
$totalPages = max(1, (int) ceil($totalRows / $pageSize));

// Load payments for this page in one query rather than one query per homeowner.
$paymentsByHomeowner = [];
$pageHomeownerIds = array_map(static fn($h) => (int) $h['id'], $homeowners);
if ($pageHomeownerIds) {
    $placeholders = implode(',', array_fill(0, count($pageHomeownerIds), '?'));
    $paymentStmt = $conn->prepare("SELECT * FROM payments WHERE homeowner_id IN ({$placeholders}) ORDER BY payment_date DESC, id DESC");
    if ($paymentStmt) {
        $paymentTypes = str_repeat('i', count($pageHomeownerIds));
        $paymentStmt->bind_param($paymentTypes, ...$pageHomeownerIds);
        $paymentStmt->execute();
        $paymentResult = $paymentStmt->get_result();
        while ($paymentRow = $paymentResult->fetch_assoc()) $paymentsByHomeowner[(int) $paymentRow['homeowner_id']][] = $paymentRow;
        $paymentStmt->close();
    }
}

// ---------- Payment standing per homeowner (active accounts only) ----------
foreach ($homeowners as $i => $h) {
    $homeowners[$i]['_sum'] = null;
    if (($h['status'] ?? '') === 'active') {
        $sum = homeowner_account_summary($conn, $h, $paymentsByHomeowner[(int) $h['id']] ?? []);
        $homeowners[$i]['_sum'] = $sum;
    }
}

if ($onlyUnpaid) {
    $homeowners = array_values(array_filter($homeowners, function ($h) {
        return $h['_sum'] && $h['_sum']['unpaid_count'] > 0;
    }));
}

if ($sortBy === 'balance') {
    usort($homeowners, function ($a, $b) {
        $ao = $a['_sum']['outstanding'] ?? -1;
        $bo = $b['_sum']['outstanding'] ?? -1;
        return $bo <=> $ao;
    });
}

if ($isCsvExport) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="homeowners_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Property Address', 'Full Name', 'Contact', 'Email', 'Status', 'Monthly Due (PHP)', 'Unpaid Months', 'Total Outstanding (PHP)']);
    foreach ($homeowners as $h) {
        $sum = $h['_sum'] ?? null;
        fputcsv($out, [
            homeowner_address_label($h['block'] ?? '', $h['lot'] ?? ''),
            $h['full_name'],
            $h['contact'] ?? '',
            $h['email'] ?? '',
            ucfirst($h['status'] ?? ''),
            number_format((float) ($h['monthly_due'] ?? 0), 2, '.', ''),
            $sum['unpaid_count'] ?? 0,
            number_format((float) ($sum['outstanding'] ?? 0), 2, '.', '')
        ]);
    }
    fclose($out);
    exit;
}

$pendingResidentsCount = 0;
try {
    $pendingResult = $conn->query("SELECT COUNT(*) AS c FROM homeowners WHERE LOWER(status) = 'pending' AND user_id IS NOT NULL");
    if ($pendingResult && $pendingRow = $pendingResult->fetch_assoc()) $pendingResidentsCount = (int) $pendingRow['c'];
} catch (Throwable $e) {
    $pendingResidentsCount = 0;
}

require_once '../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0">

            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 shadow-sm">
                    <i class="bi bi-check-circle me-2"></i><?= $_SESSION['success_msg'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3 shadow-sm">
                    <i class="bi bi-exclamation-circle me-2"></i><?= $_SESSION['error_msg'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>
            <?php if ($archiveError): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($archiveError) ?></div>
            <?php endif; ?>

            <div class="page-header homeowners-page-header">
                <div>
                    <h1><?= $archiveView ? 'Archived Homeowners' : 'Homeowners' ?></h1>
                    <p><?= $archiveView ? 'Restore archived homeowner accounts here.' : 'Manage verified resident accounts, contact details, and dues.' ?></p>
                </div>
                <div class="homeowners-page-actions">
                    <a href="homeowners.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-<?= $archiveView ? 'people' : 'archive' ?> me-1"></i><?= $archiveView ? 'Back to Homeowners' : 'Archives' ?>
                    </a>
                    <?php if (!$archiveView): ?>
                    <a href="pending_homeowners.php" class="btn btn-outline-warning pending-residents-action position-relative">
                        <i class="bi bi-person-exclamation me-1"></i> Pending Residents
                        <?php if ($pendingResidentsCount > 0): ?>
                            <span class="badge bg-danger ms-1"><?= $pendingResidentsCount ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="homeowners.php?export=csv<?= $onlyUnpaid ? '&unpaid=1' : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export CSV
                    </a>
                    <a href="homeowner_create.php" class="btn btn-primary shadow-sm">
                        <i class="bi bi-person-plus-fill me-2"></i> Add Homeowner
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <?php if (!$archiveView): ?>
                    <div class="row g-3 mb-4" aria-label="Homeowner streets">
                        <?php foreach ($streets as $street): ?>
                        <div class="col-6 col-md-4 col-xl">
                            <a class="card h-100 text-decoration-none <?= strtolower($selectedStreet) === strtolower($street) ? 'border-primary shadow-sm' : 'border-light' ?>" href="homeowners.php?street=<?= urlencode($street) ?>">
                                <div class="card-body py-3">
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($street) ?></div>
                                    <div class="small text-muted"><?= number_format($streetCounts[$street]) ?> homeowner<?= $streetCounts[$street] === 1 ? '' : 's' ?></div>
                                </div>
                            </a>
                        </div>
                        <?php endforeach; ?>
                        <div class="col-6 col-md-4 col-xl">
                            <a class="card h-100 text-decoration-none <?= $selectedStreet === '' ? 'border-primary shadow-sm' : 'border-light' ?>" href="homeowners.php">
                                <div class="card-body py-3"><div class="fw-semibold text-dark">All streets</div><div class="small text-muted"><?= number_format(array_sum($streetCounts)) ?> homeowners</div></div>
                            </a>
                        </div>
                    </div>
                    <form method="GET" class="homeowners-filter-form mb-4">
                        <?php if ($selectedStreet !== ''): ?><input type="hidden" name="street" value="<?= htmlspecialchars($selectedStreet) ?>"><?php endif; ?>
                        <div class="homeowners-search-input">
                            <div class="cir-search">
                                <i class="bi bi-search cir-search__icon" aria-hidden="true"></i>
                                <input type="search" name="search" class="cir-search__field" placeholder="Search street, house number, or name" value="<?= htmlspecialchars($search) ?>" aria-label="Search homeowners">
                                <span class="cir-search__kbd" aria-hidden="true">Enter</span>
                            </div>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i><span>Search</span></button>
                        </div>

                        <div class="form-check homeowners-unpaid-filter mb-0">
                            <input class="form-check-input" type="checkbox" name="unpaid" value="1" id="onlyUnpaid"
                                   <?= $onlyUnpaid ? 'checked' : '' ?> onchange="this.form.submit()">
                            <label class="form-check-label fw-semibold" for="onlyUnpaid" style="color: var(--text-heading);">
                                Unpaid dues only
                            </label>
                        </div>

                        <select name="sort" class="form-select homeowners-sort" aria-label="Sort homeowner accounts" onchange="this.form.submit()">
                            <option value="newest"  <?= $sortBy === 'newest'  ? 'selected' : '' ?>>Sort: Newest</option>
                            <option value="balance" <?= $sortBy === 'balance' ? 'selected' : '' ?>>Sort: Highest balance</option>
                        </select>
                    </form>
                    <?php endif; ?>

                    <?php if ($selectedStreet !== '' && !$archiveView): ?>
                    <div class="mb-3 d-flex align-items-center justify-content-between"><h2 class="h5 mb-0"><?= htmlspecialchars(ucfirst($selectedStreet)) ?> Street</h2><a class="small" href="homeowners.php">Show all streets</a></div>
                    <?php endif; ?>

                    <div class="table-responsive homeowners-table-wrap border-0 p-0">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Property Address</th>
                                    <th>Full Name</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Balance</th>
                                    <th class="text-center homeowner-actions-heading">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($homeowners as $h):
                                    $status = $archiveView ? 'archived' : ($h['status'] ?? 'inactive');
                                    $badgeClass = 'badge-info';
                                    if ($status === 'active') $badgeClass = 'badge-success';
                                    elseif ($status === 'pending') $badgeClass = 'badge-warning';

                                    $sum = $h['_sum'];
                                    $hasUnpaid = $sum && $sum['unpaid_count'] > 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars(homeowner_address_label($h['block'] ?? '', $h['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($h['full_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($h['contact'] ?? '-') ?></td>
                                    <td class="text-muted homeowner-email-cell"><?= htmlspecialchars($h['email'] ?? '-') ?></td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php if ($sum === null): ?>
                                            <span class="text-muted">—</span>
                                        <?php elseif ($hasUnpaid):
                                            $agingClass = $sum['streak'] <= 2 ? 'badge-warning' : 'badge-danger';
                                        ?>
                                            <div class="fw-bold" style="color: var(--danger-color);">₱ <?= number_format($sum['outstanding'], 2) ?></div>
                                            <?php if ($sum['unpaid_count'] === 1): ?>
                                                <div class="small text-muted mb-1">Unpaid for the month of <?= htmlspecialchars(date('F', strtotime($sum['unpaid'][0] . '-01'))) ?></div>
                                            <?php else: ?>
                                                <div class="small text-muted mb-1"><?= $sum['unpaid_count'] ?> months unpaid</div>
                                            <?php endif; ?>
                                            <span class="badge <?= $agingClass ?>"><?= htmlspecialchars(hv_aging_label($sum['streak'])) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-success"><i class="bi bi-check-circle me-1"></i>Paid up</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="homeowner-actions-cell">
                                        <div class="homeowner-actions">
                                            <a href="homeowner_view.php?id=<?= $h['id'] ?>" class="btn btn-sm homeowner-action-btn homeowner-action-view" title="View profile" aria-label="View <?= htmlspecialchars($h['full_name'] ?? 'homeowner') ?> profile">
                                                <i class="bi bi-eye"></i>
                                            </a>

                                            <?php if ($archiveView): ?>
                                                <form method="POST" class="homeowner-archive-form" onsubmit="return confirm('Restore this homeowner account?');">
                                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                    <input type="hidden" name="action" value="restore_homeowner">
                                                    <input type="hidden" name="homeowner_id" value="<?= (int) $h['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Restore homeowner" aria-label="Restore <?= htmlspecialchars($h['full_name'] ?? 'homeowner') ?> account">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                </form>
                                            <?php elseif ($status === 'pending'): ?>
                                                <a href="homeowner_view.php?id=<?= $h['id'] ?>" class="btn btn-sm homeowner-action-btn homeowner-action-review" title="Review application and ID" aria-label="Review homeowner application and ID">
                                                    <i class="bi bi-person-check"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="homeowner_edit.php?id=<?= $h['id'] ?>" class="btn btn-sm homeowner-action-btn homeowner-action-edit" title="Edit profile" aria-label="Edit <?= htmlspecialchars($h['full_name'] ?? 'homeowner') ?> profile">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!$archiveView): ?>
                                            <form method="POST" class="homeowner-archive-form" onsubmit="return confirm('Archive this homeowner account? You can restore it later from Archives.');">
                                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                <input type="hidden" name="action" value="archive_homeowner">
                                                <input type="hidden" name="homeowner_id" value="<?= (int) $h['id'] ?>">
                                                <button type="submit" class="btn btn-sm homeowner-action-btn homeowner-action-archive" title="Archive account" aria-label="Archive <?= htmlspecialchars($h['full_name'] ?? 'homeowner') ?> account">
                                                    <i class="bi bi-archive"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <?php if (empty($homeowners)): ?>
                                <tr><td colspan="7" class="table-empty-state py-5 text-muted">
                                    <?= $archiveView ? 'No archived homeowners.' : ($onlyUnpaid ? 'No homeowners with unpaid dues. 🎉' : 'No homeowners found matching your search.') ?>
                                </td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                    <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="Homeowner pages">
                        <span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($totalRows) ?> homeowners</span>
                        <div class="btn-group">
                            <?php foreach (['Previous' => max(1, $page - 1), 'Next' => min($totalPages, $page + 1)] as $label => $targetPage): ?>
                            <a class="btn btn-outline-secondary btn-sm <?= $targetPage === $page ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $targetPage])) ?>" aria-disabled="<?= $targetPage === $page ? 'true' : 'false' ?>"><?= $label ?></a>
                            <?php endforeach; ?>
                        </div>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
