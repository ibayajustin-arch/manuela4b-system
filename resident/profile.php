<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}
require_once '../includes/header.php';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Fetch latest announcements
try {
    $announcements = [];
    $result = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5");
    if ($result) {
        $announcements = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    $announcements = [];
}
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0">

            <h2 class="fw-bold mb-4">My Account Profile</h2>

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

            <div class="row">
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="avatar-circle">
                                <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                            </div>
                            <h4 class="fw-bold mb-1"><?= htmlspecialchars($_SESSION['full_name']) ?></h4>
                            <p class="mb-4">
                                <span class="status-pill status-active">
                                    <i class="bi bi-check-circle"></i> Active Resident
                                </span>
                            </p>

                            <div class="text-start">
                                <div class="info-row">
                                    <span class="info-label"><i class="bi bi-person"></i> Role</span>
                                    <span class="info-value text-capitalize"><?= htmlspecialchars($_SESSION['role']) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="bi bi-house"></i> Property</span>
                                    <span class="info-value">Block 4B, Lot 12</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="bi bi-calendar"></i> Member Since</span>
                                    <span class="info-value">2026</span>
                                </div>
                            </div>

                            <a href="#" class="btn btn-light w-100 mt-3 fw-semibold text-primary" style="background-color: var(--primary-soft);" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                Change Password
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-8 mt-4 mt-md-0">
                    <div class="card h-100">
                        <div class="card-body">
                            <?php if (empty($announcements)): ?>
                                <div class="d-flex flex-column justify-content-center align-items-center text-center py-5">
                                    <i class="bi bi-megaphone text-primary mb-3" style="font-size: 3rem;"></i>
                                    <h5 class="fw-bold">Community Announcements</h5>
                                    <p class="text-muted">There are currently no new announcements from the Homeowners Association.</p>
                                </div>
                            <?php else: ?>
                                <h5 class="fw-bold mb-4"><i class="bi bi-megaphone-fill text-primary me-2"></i>Community Announcements</h5>
                                <?php foreach ($announcements as $index => $a): ?>
                                    <div class="mb-3 pb-3 <?= $index < count($announcements) - 1 ? 'border-bottom' : '' ?>">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <h6 class="fw-bold mb-0"><?= htmlspecialchars($a['title']) ?></h6>
                                            <span class="text-muted" style="font-size: 0.75rem; white-space: nowrap;">
                                                <?= date('M d, Y', strtotime($a['created_at'])) ?>
                                            </span>
                                        </div>
                                        <p class="text-muted mb-0" style="font-size: 0.9rem;"><?= nl2br(htmlspecialchars($a['message'])) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="change_password.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>