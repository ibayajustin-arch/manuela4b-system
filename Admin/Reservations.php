<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../login.php"); 
    exit; 
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $reservation_id = (int)$_POST['reservation_id'];
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';

    try {
        $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $new_status, $reservation_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success_msg'] = "Reservation status updated to " . ucfirst($new_status) . ".";
        } else {
            $_SESSION['error_msg'] = "Database Error: " . $conn->error;
        }
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Database Error: " . $e->getMessage();
    }
    header("Location: reservations.php");
    exit;
}

try {
    $reservations = [];
    $query = "SELECT r.*, u.full_name as reserved_by FROM reservations r JOIN users u ON r.user_id = u.id ORDER BY r.res_date DESC, r.start_time DESC";
    $result = $conn->query($query);
    
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
            <div class="alert alert-success border-0 rounded-3"><i class="bi bi-check-circle me-2"></i><?= $_SESSION['success_msg'] ?></div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger border-0 rounded-3"><i class="bi bi-exclamation-circle me-2"></i><?= $_SESSION['error_msg'] ?></div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>

        <div class="page-header">
            <h1>Facility Reservations Manager</h1>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive border-0 shadow-none p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr><th>Facility</th><th>Reserved By</th><th>Date</th><th>Time Slot</th><th>Status</th><th class="text-center">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($row['facility'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['reserved_by'] ?? '') ?></td>
                                <td><?= isset($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '' ?></td>
                                <td class="text-muted"><?= isset($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '' ?> - <?= isset($row['end_time']) ? date('h:i A', strtotime($row['end_time'])) : '' ?></td>
                                <td>
                                    <?php if (($row['status'] ?? '') === 'pending'): ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php elseif (($row['status'] ?? '') === 'approved'): ?>
                                        <span class="badge badge-success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (($row['status'] ?? '') === 'pending'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" 
                                            data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-facility="<?= htmlspecialchars($row['facility'] ?? '') ?>"
                                            data-reserved-by="<?= htmlspecialchars($row['reserved_by'] ?? '') ?>"
                                            data-date="<?= isset($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '' ?>"
                                            style="display:none;" id="rejectTrigger-<?= $row['id'] ?>"></button>

                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="reservation_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-1"
                                            onclick="openRejectModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['facility'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($row['reserved_by'] ?? '')) ?>', '<?= isset($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '' ?>')">
                                            Reject
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small"><i class="bi bi-check2-all"></i> Processed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($reservations)): ?>
                            <tr><td colspan="6" class="table-empty-state">No reservations found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Confirmation Modal (matches "Reject Reservation" from the video) -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reject Reservation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" id="rejectForm">
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

<?php require_once '../includes/footer.php'; ?>