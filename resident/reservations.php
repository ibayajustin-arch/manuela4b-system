<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle New Booking Submission directly in this file
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'book') {
    $user_id = $_SESSION['user_id'];
    $facility = trim($_POST['facility'] ?? '');
    $res_date = trim($_POST['res_date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');

    if (!empty($facility) && !empty($res_date) && !empty($start_time) && !empty($end_time)) {
        try {
            $stmt = $conn->prepare("INSERT INTO reservations (user_id, facility, res_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            if ($stmt) {
                $stmt->bind_param("issss", $user_id, $facility, $res_date, $start_time, $end_time);
                $stmt->execute();
                $stmt->close();
                $_SESSION['success_msg'] = "Reservation submitted successfully! Please wait for admin approval.";
            } else {
                $_SESSION['error_msg'] = "Database Error: " . $conn->error;
            }
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Database Error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Please fill in all booking fields.";
    }

    header("Location: reservations.php");
    exit;
}

// Fetch ONLY this specific resident's reservations
try {
    $reservations = [];
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE user_id = ? ORDER BY res_date DESC, start_time DESC");
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            $reservations = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
} catch (Exception $e) {
    $reservations = [];
}

require_once '../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content flex-grow-1 p-0">
        <div class="container-fluid p-4">

            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 rounded-3">
                    <i class="bi bi-check-circle me-2"></i><?= $_SESSION['success_msg'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3">
                    <i class="bi bi-exclamation-circle me-2"></i><?= $_SESSION['error_msg'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>

            <div class="page-header">
                <h2 class="fw-bold">My Facility Reservations</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookFacilityModal">
                    <i class="bi bi-calendar-plus me-2"></i> Book Facility
                </button>
            </div>

            <div class="card">
                <div class="card-body p-4">
                    <div class="table-responsive border-0 shadow-none p-0">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Facility</th><th>Date</th><th>Time Slot</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reservations as $row): ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($row['facility'] ?? '') ?></td>
                                    <td><?= isset($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '' ?></td>
                                    <td class="text-muted">
                                        <?= isset($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '' ?> - <?= isset($row['end_time']) ? date('h:i A', strtotime($row['end_time'])) : '' ?>
                                    </td>
                                    <td>
                                        <?php if (($row['status'] ?? '') === 'pending'): ?>
                                            <span class="badge badge-warning">Pending Approval</span>
                                        <?php elseif (($row['status'] ?? '') === 'approved'): ?>
                                            <span class="badge badge-success">Approved</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <?php if (empty($reservations)): ?>
                                <tr>
                                    <td colspan="4" class="table-empty-state">
                                        <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>
                                        You have no reservations yet. Click "Book Facility" to get started.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bookFacilityModal" tabindex="-1" aria-labelledby="bookFacilityModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title" id="bookFacilityModalLabel">New Booking Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
          <input type="hidden" name="action" value="book">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label">Select Facility</label>
                  <select class="form-select" name="facility" required>
                      <option value="" selected disabled>Choose a facility...</option>
                      <option value="Basketball Court">Basketball Court</option>
                      <option value="Badminton Court">Badminton Court</option>
                      <option value="Clubhouse">Clubhouse</option>
                      <option value="Tennis Court">Tennis Court</option>
                  </select>
              </div>
              <div class="mb-3">
                  <label class="form-label">Reservation Date</label>
                  <input type="date" class="form-control" name="res_date" required min="<?= date('Y-m-d') ?>">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label">Start Time</label>
                      <input type="time" class="form-control" name="start_time" required>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label">End Time</label>
                      <input type="time" class="form-control" name="end_time" required>
                  </div>
              </div>
              <div class="modal-info-note">
                  <i class="bi bi-info-circle"></i>
                  <span>Your booking request will be reviewed by the admin. You will see the status update in your reservations list.</span>
              </div>
          </div>
          <div class="modal-footer border-top-0">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Submit Request</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>