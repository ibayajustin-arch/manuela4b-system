<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/availability.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: login.php");
    exit; 
}
$archiveView = ($_GET['view'] ?? '') === 'archive';
$streets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];
$selectedStreet = trim((string) ($_GET['street'] ?? ''));
if (!in_array(strtolower($selectedStreet), array_map('strtolower', $streets), true)) $selectedStreet = '';
$facilityOptions = ['Court', 'Tennis Court', 'Clubhouse'];
$selectedFacility = trim((string) ($_GET['facility'] ?? ''));
if (!in_array($selectedFacility, $facilityOptions, true)) $selectedFacility = '';
$archiveReady = true;
try { ensure_archive_columns($conn, 'reservations'); } catch (Throwable $e) { $archiveReady = false; error_log('Reservation archive setup failed: ' . $e->getMessage()); }

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_msg'] = "Your session expired. Please refresh the page and try again.";
        header("Location: reservations.php");
        exit;
    }
    $action = $_POST['action'];
    $reservation_id = (int)$_POST['reservation_id'];
    if (in_array($action, ['archive_reservation', 'restore_reservation'], true)) {
        if (!$archiveReady) {
            $_SESSION['error_msg'] = 'Reservation archives are unavailable.';
        } else {
            try {
                if ($action === 'archive_reservation') {
                    $stmt = $conn->prepare("UPDATE reservations r LEFT JOIN users u ON u.id = r.user_id SET r.archived_resident_name = COALESCE(u.full_name, r.archived_resident_name), r.archived_at = NOW() WHERE r.id = ? AND r.archived_at IS NULL");
                    $success = 'Reservation archived.';
                } else {
                    $stmt = $conn->prepare('UPDATE reservations SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL');
                    $success = 'Reservation restored.';
                }
                $stmt->bind_param('i', $reservation_id);
                $stmt->execute();
                $_SESSION['success_msg'] = $success;
                $stmt->close();
            } catch (Throwable $e) { $_SESSION['error_msg'] = 'The reservation could not be updated.'; }
        }
        header('Location: reservations.php' . ($action === 'restore_reservation' ? '?view=archive' : ''));
        exit;
    }
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';

    try {
        $blocked = false;

        // Approving: make sure it doesn't overlap an already-approved reservation
        if ($action === 'approve') {
            $stmt = $conn->prepare("SELECT facility, res_date, start_time, end_time FROM reservations WHERE id = ?");
            $stmt->bind_param("i", $reservation_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$r) {
                $blocked = true;
                $_SESSION['error_msg'] = "Reservation not found.";
            } elseif (reservation_conflict($conn, $r['facility'], $r['res_date'], $r['start_time'], $r['end_time'], $reservation_id, true)) {
                $blocked = true;
                $_SESSION['error_msg'] = "Cannot approve: this time overlaps another approved reservation for the same venue.";
            }
        }

        if (!$blocked) {
            $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $new_status, $reservation_id);
                $stmt->execute();
                $stmt->close();

                // Log activity linked to homeowner if found
                $h_stmt = $conn->prepare("SELECT h.id, r.facility, r.res_date FROM reservations r LEFT JOIN homeowners h ON h.user_id = r.user_id WHERE r.id = ?");
                if ($h_stmt) {
                    $h_stmt->bind_param("i", $reservation_id);
                    $h_stmt->execute();
                    $h_info = $h_stmt->get_result()->fetch_assoc();
                    $h_stmt->close();
                    if ($h_info) {
                        $fac = $h_info['facility'] ?? 'Facility';
                        $dt = isset($h_info['res_date']) ? date('M d, Y', strtotime($h_info['res_date'])) : '';
                        log_activity($conn, $h_info['id'] ? (int)$h_info['id'] : null, ucfirst($new_status) . ' facility reservation', "Venue: {$fac} on {$dt}");
                    }
                }

                $_SESSION['success_msg'] = "Reservation status updated to " . ucfirst($new_status) . ".";
            } else {
                $_SESSION['error_msg'] = "Database Error: " . $conn->error;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Database Error: " . $e->getMessage();
    }
    header("Location: reservations.php");
    exit;
}

// Live search for the search modal. Read-only JSON, same scope as the table below.
if (($_GET['ajax'] ?? '') === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $term = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $where = $archiveView ? 'r.archived_at IS NOT NULL' : 'r.archived_at IS NULL';
        $types = '';
        $params = [];
        if ($selectedStreet !== '') {
            $where .= ' AND LOWER(h.block) = LOWER(?)';
            $types .= 's';
            $params[] = $selectedStreet;
        }
        if ($selectedFacility !== '') {
            $where .= ' AND r.facility = ?';
            $types .= 's';
            $params[] = $selectedFacility;
        }
        // Every word must match somewhere, so "court duhat" or "maria pending" both work.
        $words = $term === '' ? [] : array_slice(preg_split('/\s+/u', $term), 0, 6);
        // Compared column by column so a collation difference between tables cannot break the search.
        $searchColumns = [
            'r.facility LIKE ?', 'r.archived_resident_name LIKE ?', '(r.archived_resident_name IS NULL AND u.full_name LIKE ?)', 'h.block LIKE ?', 'h.lot LIKE ?', 'r.status LIKE ?', 'r.payment_status LIKE ?', 'rp.reference_number LIKE ?',
            "DATE_FORMAT(r.res_date, '%b %d, %Y') LIKE ?", "DATE_FORMAT(r.res_date, '%M %Y') LIKE ?", 'r.res_date LIKE ?',
        ];
        foreach ($words as $word) {
            $where .= ' AND (' . implode(' OR ', $searchColumns) . ')';
            $types .= str_repeat('s', count($searchColumns));
            array_push($params, ...array_fill(0, count($searchColumns), '%' . addcslashes($word, '\\%_') . '%'));
        }
        // One extra row tells us whether there are more than we show.
        $limit = 30;
        $stmt = $conn->prepare("SELECT r.id, r.facility, r.res_date, r.start_time, r.end_time, r.status, r.payment_status, r.payment_option, r.payment_amount,
                                       COALESCE(r.archived_resident_name, u.full_name, 'Former account') AS reserved_by, h.block, h.lot
                                FROM reservations r
                                LEFT JOIN users u ON r.user_id = u.id
                                LEFT JOIN homeowners h ON h.user_id = r.user_id
                                LEFT JOIN reservation_payments rp ON rp.id = (
                                    SELECT MAX(rp2.id) FROM reservation_payments rp2
                                    WHERE rp2.reservation_id = r.id AND rp2.payment_status = 'paid'
                                )
                                WHERE {$where}
                                ORDER BY r.res_date DESC, r.start_time DESC
                                LIMIT " . ($limit + 1));
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $results = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $paid = ($row['payment_status'] ?? '') === 'paid';
            $results[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['reserved_by'],
                'facility' => (string) ($row['facility'] ?? ''),
                'address' => trim((string) ($row['block'] ?? '')) !== '' ? homeowner_address_label($row['block'], $row['lot'] ?? '') : '',
                'date' => !empty($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '',
                'time' => (!empty($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '') . ' - ' . (!empty($row['end_time']) ? date('h:i A', strtotime($row['end_time'])) : ''),
                'status' => (string) ($row['status'] ?? ''),
                'payment' => $paid ? '₱' . number_format((float) ($row['payment_amount'] ?? 0), 2) . ' paid' : 'Unpaid',
            ];
        }
        echo json_encode(['ok' => true, 'results' => $results, 'more' => count($rows) > $limit], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('Reservation search failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false]);
    }
    exit;
}

try {
    $reservations = [];
    $archiveCondition = $archiveView ? 'r.archived_at IS NOT NULL' : 'r.archived_at IS NULL';
    $streetFilter = $selectedStreet !== '' ? ' AND LOWER(h.block) = LOWER(?)' : '';
    $facilityFilter = $selectedFacility !== '' ? ' AND r.facility = ?' : '';
    $query = "SELECT r.*, COALESCE(r.archived_resident_name, u.full_name, 'Former account') as reserved_by, rp.payment_method AS reservation_payment_method
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              LEFT JOIN homeowners h ON h.user_id = r.user_id
              LEFT JOIN reservation_payments rp ON rp.id = (
                  SELECT MAX(rp2.id) FROM reservation_payments rp2
                  WHERE rp2.reservation_id = r.id AND rp2.payment_status = 'paid'
              )
              WHERE {$archiveCondition}{$streetFilter}{$facilityFilter}
              ORDER BY r.res_date DESC, r.start_time DESC";
    $stmt = $conn->prepare($query);
    $filterParams = [];
    if ($selectedStreet !== '') $filterParams[] = $selectedStreet;
    if ($selectedFacility !== '') $filterParams[] = $selectedFacility;
    if ($filterParams) $stmt->bind_param(str_repeat('s', count($filterParams)), ...$filterParams);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        $reservations = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) { 
    $reservations = []; 
}

$csrf = generateCsrfToken();

require_once '../includes/header.php';
?>
<style>
    /* Facility line under the resident name: phones only (desktop has its own column) */
    .res-name-text { font-weight: 600; }
    .res-facility-sub { display: none; }
    @media (max-width: 767.98px) {
        html body .main-content .admin-reservations-table td.res-name .res-name-text {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.3;
            color: var(--text-heading);
        }
        html body .main-content .admin-reservations-table td.res-name .res-facility-sub {
            display: flex;
            align-items: center;
            gap: .3rem;
            margin-top: .1rem;
            font-size: .88rem;
            font-weight: 500;
            line-height: 1.35;
            color: var(--text-muted);
        }
        html body .main-content .admin-reservations-table td.res-status { margin-top: .2rem; }
    }
    /* Desktop: the bell is fixed at the top right, so start the card below it. */
    @media (min-width: 992px) {
        .app-wrapper:has(#adminTopNotifWrap) > .main-content.page-admin-reservations { padding-top: 5rem; }
    }
    .res-search-box .cir-search { display: flex; max-width: none; }
    #resSearchModal .modal-body { min-height: 45vh; padding: 0; }
    .res-search-status { padding: .5rem 1rem; font-size: .875rem; color: var(--text-muted); }
    .res-search-item { display: flex; align-items: center; justify-content: space-between; gap: .75rem; min-height: 48px; text-align: left; }
    .res-search-item__main { min-width: 0; overflow-wrap: anywhere; }
    .res-search-item__name { font-weight: 600; color: var(--text-heading); }
    .res-search-item__meta { font-size: .875rem; color: var(--text-muted); }
    .res-search-item__side { flex-shrink: 0; text-align: right; font-size: .875rem; color: var(--text-muted); }
    .res-filter-option { display: flex; align-items: center; gap: .75rem; min-height: 48px; font-size: 1rem; cursor: pointer; }
    .res-filter-option .form-check-input { flex-shrink: 0; margin: 0; }
    .res-row-focus { outline: 2px solid var(--primary-color); outline-offset: -2px; }
    .res-row-focus > td { background-color: var(--primary-soft) !important; }
</style>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content page-admin-reservations">
        
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success border-0 rounded-3"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_msg']) ?></div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger border-0 rounded-3"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error_msg']) ?></div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

        <div class="card">
            <div class="card-body border-bottom">
                <form method="GET" class="row g-2 align-items-end">
                    <?php if ($archiveView): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
                    <?php if ($selectedFacility !== ''): ?><input type="hidden" name="facility" value="<?= htmlspecialchars($selectedFacility, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                    <div class="col-sm-5 col-md-3">
                        <label for="reservationStreet" class="form-label">Filter by street</label>
                        <select id="reservationStreet" name="street" class="form-select" onchange="this.form.submit()">
                            <option value="">All streets</option>
                            <?php foreach ($streets as $streetOption): ?>
                                <option value="<?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($selectedStreet, $streetOption) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-auto d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#resSearchModal"><i class="bi bi-search me-1"></i>Search</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#resFilterModal"><i class="bi bi-funnel me-1"></i><?= $selectedFacility !== '' ? 'Filter: ' . htmlspecialchars($selectedFacility, ENT_QUOTES, 'UTF-8') : 'Filter' ?></button>
                        <a href="reservations.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-outline-secondary"><i class="bi bi-archive me-1"></i><?= $archiveView ? 'Back to Reservations' : 'Archives' ?></a>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive border-0 shadow-none p-0">
                    <table class="table table-hover align-middle mb-0 admin-reservations-table">
                        <thead>
                            <tr>
                                <th>Facility</th>
                                <th>Reserved By</th>
                                <th>Date</th>
                                <th>Time Slot</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $row):
                                $status = $row['status'] ?? '';
                                $facility = (string) ($row['facility'] ?? '');
                                $reservedBy = (string) ($row['reserved_by'] ?? '');
                                $resPaid = ($row['payment_status'] ?? '') === 'paid';
                                $resPayOption = ($row['payment_option'] ?? '') === 'downpayment' ? '50% Downpayment' : 'Full Payment';
                                $resDate = !empty($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '';
                                $resTime = (!empty($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '')
                                    . ' - ' . (!empty($row['end_time']) ? date('h:i A', strtotime($row['end_time'])) : '');
                            ?>
                            <tr data-res-id="<?= (int) $row['id'] ?>">
                                <td class="res-facility fw-semibold m-hide"><?= htmlspecialchars($facility) ?></td>
                                <td class="res-name">
                                    <div class="res-name-text"><?= htmlspecialchars($reservedBy) ?></div>
                                    <div class="res-facility-sub"><i class="bi bi-building" aria-hidden="true"></i><?= htmlspecialchars($facility !== '' ? $facility : 'Facility') ?></div>
                                </td>
                                <td class="res-date m-hide"><?= $resDate ?></td>
                                <td class="res-time text-muted m-hide"><?= $resTime ?></td>
                                <td class="res-pay m-hide">
                                    <?php if ($resPaid): ?>
                                        <span><?= $resPayOption ?></span>
                                        <div class="small text-muted">₱<?= number_format((float) ($row['payment_amount'] ?? 0), 2) ?> paid</div>
                                        <button type="button" class="btn btn-sm btn-outline-primary reservation-receipt-button" onclick="openReceipt('reservation', <?= (int) $row['id'] ?>)">
                                            <i class="bi bi-receipt me-1"></i> View Receipt
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="res-status">
                                    <?php if ($archiveView): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="restore_reservation">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success">Restore</button>
                                        </form>
                                    <?php elseif ($status === 'pending'): ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php elseif ($status === 'approved'): ?>
                                        <span class="badge badge-success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td class="res-actions text-center">
                                    <?php if ($resPaid): ?>
                                        <!-- Paid: the eye opens the digital receipt (same as the Payments page) -->
                                        <button type="button" class="btn btn-sm btn-outline-primary d-md-none act-icon"
                                            title="View receipt" aria-label="View receipt for <?= htmlspecialchars($reservedBy, ENT_QUOTES) ?>"
                                            onclick="openReceipt('reservation', <?= (int) $row['id'] ?>)"><i class="bi bi-eye"></i></button>
                                    <?php else: ?>
                                        <!-- Unpaid: no receipt exists yet, so show a short summary -->
                                        <button type="button" class="btn btn-sm btn-outline-primary d-md-none act-icon"
                                            title="View details" aria-label="View details for <?= htmlspecialchars($reservedBy, ENT_QUOTES) ?>"
                                            data-facility="<?= htmlspecialchars($facility, ENT_QUOTES) ?>"
                                            data-name="<?= htmlspecialchars($reservedBy, ENT_QUOTES) ?>"
                                            data-date="<?= htmlspecialchars($resDate, ENT_QUOTES) ?>"
                                            data-time="<?= htmlspecialchars($resTime, ENT_QUOTES) ?>"
                                            data-status="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES) ?>"
                                            onclick="openResDetails(this)"><i class="bi bi-eye"></i></button>
                                    <?php endif; ?>

                                    <?php if (!$archiveView && $status === 'pending'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success act-icon" title="Approve" aria-label="Approve"><i class="bi bi-check-lg"></i><span class="btn-label ms-1">Approve</span></button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-1 act-icon" title="Reject" aria-label="Reject"
                                            data-id="<?= (int) $row['id'] ?>"
                                            data-facility="<?= htmlspecialchars($facility, ENT_QUOTES) ?>"
                                            data-name="<?= htmlspecialchars($reservedBy, ENT_QUOTES) ?>"
                                            data-date="<?= htmlspecialchars($resDate, ENT_QUOTES) ?>"
                                            onclick="openRejectModal(this)">
                                            <i class="bi bi-x-lg"></i><span class="btn-label ms-1">Reject</span>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!$archiveView): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Archive this reservation? You can restore it later.');">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="archive_reservation">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary act-icon" title="Archive" aria-label="Archive reservation"><i class="bi bi-archive"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($reservations)): ?>
                            <tr><td colspan="7" class="table-empty-state">No reservations found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Facility filter -->
<div class="modal fade" id="resFilterModal" tabindex="-1" aria-labelledby="resFilterTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="GET">
        <?php if ($archiveView): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
        <?php if ($selectedStreet !== ''): ?><input type="hidden" name="street" value="<?= htmlspecialchars($selectedStreet, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title" id="resFilterTitle">Filter by facility</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label class="res-filter-option">
            <input class="form-check-input" type="radio" name="facility" value="" onchange="this.form.submit()" <?= $selectedFacility === '' ? 'checked' : '' ?>>
            <span>All facilities</span>
          </label>
          <?php foreach ($facilityOptions as $facilityOption): ?>
            <label class="res-filter-option">
              <input class="form-check-input" type="radio" name="facility" value="<?= htmlspecialchars($facilityOption, ENT_QUOTES, 'UTF-8') ?>" onchange="this.form.submit()" <?= $selectedFacility === $facilityOption ? 'checked' : '' ?>>
              <span><?= htmlspecialchars($facilityOption, ENT_QUOTES, 'UTF-8') ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Live search -->
<div class="modal fade" id="resSearchModal" tabindex="-1" aria-labelledby="resSearchTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="resSearchTitle"><?= $archiveView ? 'Search archived reservations' : 'Search reservations' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="res-search-box px-3 pt-3">
        <div class="cir-search">
          <i class="bi bi-search cir-search__icon" aria-hidden="true"></i>
          <input type="search" id="resSearchInput" class="cir-search__field" placeholder="Name, facility, street, date, status, or reference" maxlength="100" autocomplete="off" enterkeyhint="search" aria-label="Search reservations" aria-controls="resSearchResults">
        </div>
        <?php if ($selectedStreet !== ''): ?>
          <div class="small text-muted mt-2">Street: <?= htmlspecialchars($selectedStreet, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
      <div class="res-search-status" id="resSearchStatus" role="status" aria-live="polite"></div>
      <div class="modal-body">
        <div class="list-group list-group-flush" id="resSearchResults"></div>
      </div>
    </div>
  </div>
</div>

<!-- Reject Confirmation Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reject Reservation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" id="rejectForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="reservation_id" id="rejectReservationId">
        <div class="modal-body">
            <div class="modal-confirm-icon icon-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <p class="modal-confirm-text">
                Are you sure you want to reject the <strong id="rejectFacilityName"></strong>
                reservation by <strong id="rejectReservedBy"></strong> on <strong id="rejectDate"></strong>?
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Yes, Reject</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Summary for unpaid reservations (phones) -->
<div class="modal fade" id="resDetailsModal" tabindex="-1" aria-labelledby="rdName" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="rdName"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <dl class="detail-list">
          <dt>Facility</dt><dd id="rdFacility"></dd>
          <dt>Date</dt><dd id="rdDate"></dd>
          <dt>Time</dt><dd id="rdTime"></dd>
          <dt>Payment</dt><dd>Unpaid — no receipt yet</dd>
          <dt>Status</dt><dd id="rdStatus"></dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    // Safety net: remove a leftover dark overlay so the page never stays frozen.
    function cleanupBackdrops() {
        if (document.querySelector('.modal.show')) return;
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }
    document.addEventListener('hidden.bs.modal', function () { window.setTimeout(cleanupBackdrops, 50); });
    window.addEventListener('pageshow', cleanupBackdrops);
    document.addEventListener('DOMContentLoaded', cleanupBackdrops);
})();

function openRejectModal(btn) {
    var d = btn.dataset;
    document.getElementById('rejectReservationId').value = d.id;
    document.getElementById('rejectFacilityName').textContent = d.facility || '';
    document.getElementById('rejectReservedBy').textContent = d.name || '';
    document.getElementById('rejectDate').textContent = d.date || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rejectModal')).show();
}

function openResDetails(btn) {
    var d = btn.dataset;
    document.getElementById('rdName').textContent = d.name || '';
    document.getElementById('rdFacility').textContent = d.facility || '—';
    document.getElementById('rdDate').textContent = d.date || '—';
    document.getElementById('rdTime').textContent = d.time || '—';
    document.getElementById('rdStatus').textContent = d.status || '—';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('resDetailsModal')).show();
}

// Live search: results update while typing, and refresh every 10s while the modal is open.
(function () {
    var modal = document.getElementById('resSearchModal');
    var input = document.getElementById('resSearchInput');
    var list = document.getElementById('resSearchResults');
    var status = document.getElementById('resSearchStatus');
    var badges = { pending: ['badge-warning', 'Pending'], approved: ['badge-success', 'Approved'], rejected: ['badge-danger', 'Rejected'] };
    var typingTimer = null, refreshTimer = null, request = null, lastResponse = '', chosenId = null;

    function pageParams() {
        var params = new URLSearchParams(window.location.search);
        params.delete('focus');
        return params;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function resultItem(item) {
        var button = el('button', 'list-group-item list-group-item-action res-search-item');
        button.type = 'button';
        var main = el('div', 'res-search-item__main');
        main.appendChild(el('div', 'res-search-item__name', item.name));
        main.appendChild(el('div', 'res-search-item__meta', [item.facility, item.date, item.time].filter(Boolean).join(' · ')));
        if (item.address) main.appendChild(el('div', 'res-search-item__meta', item.address));
        var side = el('div', 'res-search-item__side');
        var badge = badges[item.status] || badges.rejected;
        side.appendChild(el('span', 'badge ' + badge[0], badge[1]));
        side.appendChild(el('div', 'mt-1', item.payment));
        button.appendChild(main);
        button.appendChild(side);
        button.addEventListener('click', function () {
            chosenId = item.id;
            bootstrap.Modal.getOrCreateInstance(modal).hide();
        });
        return button;
    }

    function render(data, query) {
        list.replaceChildren.apply(list, data.results.map(resultItem));
        var count = data.results.length;
        if (count === 0) {
            status.textContent = query ? 'No reservations match your search.' : 'No reservations found.';
        } else if (data.more) {
            status.textContent = 'Showing the ' + count + ' latest matches. Type more to narrow it down.';
        } else {
            status.textContent = query ? count + (count === 1 ? ' result' : ' results') : 'Latest reservations';
        }
    }

    function runSearch(isRefresh) {
        if (isRefresh && request) return;
        if (request) request.abort();
        var current = request = new AbortController();
        var query = input.value.trim();
        var params = pageParams();
        params.set('ajax', 'search');
        params.set('q', query);
        if (!isRefresh) status.textContent = 'Searching…';

        fetch('reservations.php?' + params.toString(), { signal: current.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                // An expired session redirects to the login page instead of returning JSON.
                if (response.redirected) throw new Error('session');
                if (!response.ok) throw new Error('server');
                return response.text();
            })
            .then(function (body) {
                if (body === lastResponse && isRefresh) return;
                var data = JSON.parse(body);
                if (!data.ok) throw new Error('server');
                lastResponse = body;
                render(data, query);
            })
            .catch(function (error) {
                if (error.name === 'AbortError') return;
                lastResponse = '';
                list.replaceChildren();
                status.textContent = error.message === 'session'
                    ? 'Your session expired. Please refresh the page and log in again.'
                    : 'Search is unavailable right now. Please try again.';
            })
            .finally(function () {
                if (request === current) request = null;
            });
    }

    function focusRow(id, reloadIfMissing) {
        var row = document.querySelector('tr[data-res-id="' + id + '"]');
        if (!row) {
            // Booked after this page loaded: reload so the row is in the table.
            if (reloadIfMissing) {
                var params = pageParams();
                params.set('focus', id);
                window.location.href = 'reservations.php?' + params.toString();
            }
            return;
        }
        row.scrollIntoView({ block: 'center' });
        row.classList.add('res-row-focus');
        window.setTimeout(function () { row.classList.remove('res-row-focus'); }, 2500);
    }

    input.addEventListener('input', function () {
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(function () { runSearch(false); }, 200);
    });

    modal.addEventListener('show.bs.modal', function () {
        input.value = '';
        chosenId = null;
        lastResponse = '';
        list.replaceChildren();
        runSearch(false);
    });
    modal.addEventListener('shown.bs.modal', function () {
        input.focus();
        refreshTimer = window.setInterval(function () {
            if (!document.hidden) runSearch(true);
        }, 10000);
    });
    modal.addEventListener('hidden.bs.modal', function () {
        window.clearTimeout(typingTimer);
        window.clearInterval(refreshTimer);
        if (request) request.abort();
        if (chosenId !== null) focusRow(chosenId, true);
    });

    var focusId = parseInt(new URLSearchParams(window.location.search).get('focus'), 10);
    if (focusId > 0) focusRow(focusId, false);
})();
</script>
<?php require_once '../includes/receipt_modal.php'; ?>
<?php require_once '../includes/footer.php'; ?>