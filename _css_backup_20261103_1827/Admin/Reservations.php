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

try {
    $reservations = [];
    $archiveCondition = $archiveView ? 'r.archived_at IS NOT NULL' : 'r.archived_at IS NULL';
    $streetFilter = $selectedStreet !== '' ? ' AND LOWER(h.block) = LOWER(?)' : '';
    $query = "SELECT r.*, COALESCE(r.archived_resident_name, u.full_name, 'Former account') as reserved_by, rp.payment_method AS reservation_payment_method
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              LEFT JOIN homeowners h ON h.user_id = r.user_id
              LEFT JOIN reservation_payments rp ON rp.id = (
                  SELECT MAX(rp2.id) FROM reservation_payments rp2
                  WHERE rp2.reservation_id = r.id AND rp2.payment_status = 'paid'
              )
              WHERE {$archiveCondition}{$streetFilter}
              ORDER BY r.res_date DESC, r.start_time DESC";
    $stmt = $conn->prepare($query);
    if ($selectedStreet !== '') $stmt->bind_param('s', $selectedStreet);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        $reservations = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) { 
    $reservations = []; 
}

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success border-0 rounded-3"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_msg']) ?></div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger border-0 rounded-3"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error_msg']) ?></div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-end gap-2 mb-4">
            <a href="reservations.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-outline-secondary"><i class="bi bi-archive me-1"></i><?= $archiveView ? 'Back to Reservations' : 'Archives' ?></a>
        </div>
        
        <div class="card">
            <div class="card-body border-bottom">
                <form method="GET" class="row g-2 align-items-end">
                    <?php if ($archiveView): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
                    <div class="col-sm-5 col-md-3">
                        <label for="reservationStreet" class="form-label">Filter by street</label>
                        <select id="reservationStreet" name="street" class="form-select" onchange="this.form.submit()">
                            <option value="">All streets</option>
                            <?php foreach ($streets as $streetOption): ?>
                                <option value="<?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($selectedStreet, $streetOption) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
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
                            <?php foreach ($reservations as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($row['facility'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['reserved_by'] ?? '') ?></td>
                                <td><?= isset($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '' ?></td>
                                <td class="text-muted"><?= isset($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '' ?> - <?= isset($row['end_time']) ? date('h:i A', strtotime($row['end_time'])) : '' ?></td>
                                <td>
                                    <?php if (($row['payment_status'] ?? '') === 'paid'): ?>
                                        <span><?= ($row['payment_option'] ?? '') === 'downpayment' ? '50% Downpayment' : 'Full Payment' ?></span>
                                        <div class="small text-muted">₱<?= number_format((float) ($row['payment_amount'] ?? 0), 2) ?> paid</div>
                                        <button type="button" class="btn btn-sm btn-outline-primary reservation-receipt-button" onclick="openReceipt('reservation', <?= (int) $row['id'] ?>)">
                                            <i class="bi bi-receipt me-1"></i> View Receipt
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($archiveView): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                            <input type="hidden" name="action" value="restore_reservation">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success">Restore</button>
                                        </form>
                                    <?php elseif (($row['status'] ?? '') === 'pending'): ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php elseif (($row['status'] ?? '') === 'approved'): ?>
                                        <span class="badge badge-success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (($row['status'] ?? '') === 'pending'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="reservation_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-1"
                                            onclick="openRejectModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['facility'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($row['reserved_by'] ?? '')) ?>', '<?= isset($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '' ?>')">
                                            Reject
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!$archiveView): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Archive this reservation? You can restore it later.');">
                                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                            <input type="hidden" name="action" value="archive_reservation">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="Archive reservation"><i class="bi bi-archive"></i></button>
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

<!-- Reject Confirmation Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reject Reservation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" id="rejectForm">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
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

<script>
function openRejectModal(id, facility, reservedBy, date) {
    document.getElementById('rejectReservationId').value = id;
    document.getElementById('rejectFacilityName').innerText = facility;
    document.getElementById('rejectReservedBy').innerText = reservedBy;
    document.getElementById('rejectDate').innerText = date;

    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
}
</script>

<?php require_once '../includes/receipt_modal.php'; ?>
<?php require_once '../includes/footer.php'; ?>
