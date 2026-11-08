<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

$csrf_token = generateCsrfToken();
$user_id = (int) $_SESSION['user_id'];
$profile_error = null;
$announcement_error = false;
$active_modal = null;
$details_values = [];

$profile_photo = null;
try {
    $stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $userRow = $res->fetch_assoc();
    $stmt->close();
    $profile_photo = $userRow['profile_photo'] ?? null;
} catch (Exception $e) {
    $profile_photo = null;
}

// Confirm the file actually exists on disk before trying to show it
$has_photo = $profile_photo && file_exists('../' . $profile_photo);

// Find the homeowner record linked to this account
$homeowner = null;
try {
    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $homeowner = $result->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    $homeowner = null;
}

$can_edit_details = $homeowner && strtolower((string) ($homeowner['status'] ?? '')) === 'active';
$can_edit_profile = (bool) $homeowner;
$resident_status = $homeowner ? strtolower((string) ($homeowner['status'] ?? 'pending')) : 'unavailable';
$profile_values = [
    'full_name' => $homeowner['full_name'] ?? ($_SESSION['full_name'] ?? ''),
    'email' => $homeowner['email'] ?? '',
    'contact' => $homeowner['contact'] ?? '',
    'address' => $homeowner['address'] ?? '',
];

require_once '../includes/auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $active_modal = 'residentProfileModal';
    foreach ($profile_values as $field => $_value) {
        $profile_values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $textLength = static fn($value) => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $profile_error = 'Your session expired. Please refresh and try again.';
    } elseif (!$can_edit_profile) {
        $profile_error = 'Your resident profile is not available to edit.';
    } elseif ($profile_values['full_name'] === '' || $textLength($profile_values['full_name']) > 100) {
        $profile_error = 'Please enter a name up to 100 characters.';
    } elseif (!filter_var($profile_values['email'], FILTER_VALIDATE_EMAIL) || $textLength($profile_values['email']) > 100) {
        $profile_error = 'Please enter a valid email address up to 100 characters.';
    } elseif (normalize_email($profile_values['email']) !== normalize_email($homeowner['email'] ?? '')
        && email_in_use($conn, $profile_values['email'], $user_id, (int) $homeowner['id'])) {
        $profile_error = 'This email is already used by another account. Please use a different email.';
    } elseif ($profile_values['contact'] === '' || $textLength($profile_values['contact']) > 20
        || !preg_match('/^[0-9+().\s-]{7,20}$/', $profile_values['contact'])) {
        $profile_error = 'Please enter a valid contact number (7–20 characters).';
    } elseif ($textLength($profile_values['address']) > 150) {
        $profile_error = 'Address must be 150 characters or fewer.';
    } else {
        $transactionStarted = false;
        try {
            $changedFields = [];
            foreach (['full_name' => 'name', 'email' => 'email', 'contact' => 'contact number', 'address' => 'address'] as $field => $label) {
                if ((string) ($homeowner[$field] ?? '') !== $profile_values[$field]) $changedFields[] = $label;
            }

            $conn->begin_transaction();
            $transactionStarted = true;
            $stmt = $conn->prepare('UPDATE homeowners SET full_name = ?, email = ?, contact = ?, address = ? WHERE user_id = ?');
            if (!$stmt) throw new RuntimeException('Unable to prepare resident profile update.');
            $stmt->bind_param('ssssi', $profile_values['full_name'], $profile_values['email'], $profile_values['contact'], $profile_values['address'], $user_id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();

            $stmt = $conn->prepare('UPDATE users SET full_name = ? WHERE id = ?');
            if (!$stmt) throw new RuntimeException('Unable to prepare account name update.');
            $stmt->bind_param('si', $profile_values['full_name'], $user_id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();

            if ($changedFields) {
                log_activity($conn, (int) $homeowner['id'], 'Updated resident profile', 'Updated: ' . implode(', ', $changedFields));
            }
            $conn->commit();
            $transactionStarted = false;
            $_SESSION['full_name'] = $profile_values['full_name'];
            $_SESSION['flash_success'] = $changedFields ? 'Your profile information was saved.' : 'Your profile information is already up to date.';
            header('Location: profile.php');
            exit;
        } catch (Throwable $e) {
            if ($transactionStarted) $conn->rollback();
            error_log('Resident profile update failed for user ' . $user_id . ': ' . $e->getMessage());
            $profile_error = 'We could not save your profile information. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_resident_details') {
    $active_modal = 'residentDetailsModal';
    $details_values = [
        'emergency_contact_name' => trim((string) ($_POST['emergency_contact_name'] ?? '')),
        'emergency_contact_number' => trim((string) ($_POST['emergency_contact_number'] ?? '')),
        'household_members' => trim((string) ($_POST['household_members'] ?? '')),
    ];
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $profile_error = 'Your session expired. Please refresh and try again.';
    } elseif (!$can_edit_details) {
        $profile_error = 'These details can be updated after your resident application is approved.';
    } else {
        $emergency_name = $details_values['emergency_contact_name'];
        $emergency_number = $details_values['emergency_contact_number'];
        $household_members = $details_values['household_members'];
        $textLength = static fn($value) => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if ($textLength($emergency_name) > 100 || $textLength($emergency_number) > 30 || $textLength($household_members) > 5000) {
            $profile_error = 'One or more details exceed the allowed length.';
        } elseif ($emergency_number !== '' && !preg_match('/^[0-9+().\s-]{7,30}$/', $emergency_number)) {
            $profile_error = 'Please enter a valid emergency contact number.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE homeowners
                                        SET emergency_contact_name = ?, emergency_contact_number = ?, household_members = ?
                                        WHERE user_id = ? AND LOWER(status) = 'active'");
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param('sssi', $emergency_name, $emergency_number, $household_members, $user_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();
                $_SESSION['flash_success'] = 'Your additional details were saved.';
                header('Location: profile.php');
                exit;
            } catch (Throwable $e) {
                error_log('Resident profile detail update failed: ' . $e->getMessage());
                $profile_error = 'We could not save your details. Please try again.';
            }
        }
    }
}

$property_label = $homeowner
    ? htmlspecialchars(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''), ENT_QUOTES, 'UTF-8')
    : 'No homeowner record';

$member_since_label = ($homeowner && !empty($homeowner['created_at']))
    ? date('Y', strtotime($homeowner['created_at']))
    : '—';

$email_label = ($homeowner && !empty($homeowner['email'])) ? $homeowner['email'] : '—';
$contact_label = ($homeowner && !empty($homeowner['contact'])) ? $homeowner['contact'] : '—';

$role_label = !empty($_SESSION['role']) ? $_SESSION['role'] : 'Resident';

try {
    $announcements = [];
    ensure_archive_columns($conn, 'announcements');
    $result = $conn->query("SELECT * FROM announcements WHERE archived_at IS NULL ORDER BY created_at DESC LIMIT 5");
    if ($result) {
        $announcements = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Resident announcements could not be loaded: ' . $e->getMessage());
    $profile_error = 'Community announcements are temporarily unavailable.';
    $announcement_error = true;
    $announcements = [];
}

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $profile_error ?? ($_SESSION['flash_error'] ?? null);
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
require_once '../includes/header.php';
?>
<style>
    .resident-profile-page,
    .resident-profile-page .card,
    .resident-profile-page .card-body {
        min-width: 0;
    }

    html body .main-content .resident-profile-page .profile-dashboard-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: auto;
        min-height: 48px !important;
        padding: 0.5rem 1.25rem;
        font-size: 1rem !important;
    }

    .resident-profile-page .info-row {
        display: grid;
        grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
        align-items: start;
        gap: 0.75rem;
    }

    .resident-profile-page .info-label,
    .resident-profile-page .info-value {
        min-width: 0;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .resident-profile-page .info-value {
        text-align: right;
        word-break: normal;
        letter-spacing: normal;
    }

    .resident-profile-page .fw-semibold,
    .resident-profile-page .info-value,
    .resident-profile-page h4,
    .resident-profile-page .announcement-item h6,
    .resident-profile-page .announcement-item p {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .resident-profile-page .announcement-item > .d-flex > h6 {
        flex: 1 1 auto;
    }

    @media (max-width: 575.98px) {
        .resident-profile-page .info-row {
            grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
            gap: 0.5rem;
        }
    }

    @media (max-width: 767.98px) {
        body:has(.resident-profile-page) .mobile-topbar-title {
            position: absolute;
            left: 50%;
            width: max-content;
            max-width: calc(100% - 112px);
            transform: translateX(-50%);
        }
    }
</style>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0 resident-profile-page">

            <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
                <h2 class="fw-bold mb-0">Profile</h2>
                <a href="dashboard.php" class="btn btn-primary profile-dashboard-btn d-md-none">
                    <i class="bi bi-speedometer2" aria-hidden="true"></i>Dashboard
                </a>
            </div>

            <?php if ($flash_success): ?>
                <div class="alert alert-success border-0 rounded-3 d-flex align-items-center mb-4 shadow-sm">
                    <i class="bi bi-check-circle-fill me-2"></i><div><?= htmlspecialchars($flash_success) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="alert alert-danger border-0 rounded-3 d-flex align-items-center mb-4 shadow-sm">
                    <i class="bi bi-exclamation-circle-fill me-2"></i><div><?= htmlspecialchars($flash_error) ?></div>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-body text-center">

                            <form action="upload_photo.php" method="POST" enctype="multipart/form-data" id="photoUploadForm">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <div class="avatar-upload-wrapper">
                                    <?php if ($has_photo): ?>
                                        <div class="avatar-circle avatar-circle-photo border shadow-sm">
                                            <img src="../<?= htmlspecialchars($profile_photo) ?>?v=<?= (int) filemtime('../' . $profile_photo) ?>" alt="Profile photo">
                                        </div>
                                    <?php else: ?>
                                        <div class="avatar-circle border shadow-sm text-primary bg-primary-subtle">
                                            <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <label for="photoInput" class="avatar-edit-btn" title="Change photo" aria-label="Change profile photo" tabindex="0">
                                        <i class="bi bi-camera-fill" aria-hidden="true"></i>
                                    </label>
                                    <input type="file" name="profile_photo" id="photoInput" accept="image/png, image/jpeg, image/webp" class="visually-hidden">
                                </div>
                            </form>

                            <h4 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($_SESSION['full_name']) ?></h4>
                            <p class="mb-4">
                                <?php if ($resident_status === 'active'): ?>
                                    <span class="status-pill status-active"><i class="bi bi-check-circle-fill me-1"></i> Active Resident</span>
                                <?php elseif ($resident_status === 'pending'): ?>
                                    <span class="badge badge-warning"><i class="bi bi-clock-history me-1"></i> Pending Verification</span>
                                <?php elseif ($resident_status === 'inactive'): ?>
                                    <span class="badge badge-danger"><i class="bi bi-person-x me-1"></i> Inactive</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Resident Profile Unavailable</span>
                                <?php endif; ?>
                            </p>

                            <div class="text-start">
                                <div class="info-row border-bottom-0 pb-2">
                                    <span class="info-label text-muted"><i class="bi bi-person"></i> Role</span>
                                    <span class="info-value text-dark text-capitalize"><?= htmlspecialchars($role_label) ?></span>
                                </div>
                                <div class="info-row border-bottom-0 pb-2 pt-2">
                                    <span class="info-label text-muted"><i class="bi bi-house"></i> Block / Lot</span>
                                    <span class="info-value text-dark"><?= $property_label ?></span>
                                </div>
                                <div class="info-row border-bottom-0 pb-2 pt-2">
                                    <span class="info-label text-muted"><i class="bi bi-envelope"></i> Email</span>
                                    <span class="info-value text-dark"><?= htmlspecialchars($email_label) ?></span>
                                </div>
                                <div class="info-row border-bottom-0 pb-2 pt-2">
                                    <span class="info-label text-muted"><i class="bi bi-telephone"></i> Contact No.</span>
                                    <span class="info-value text-dark"><?= htmlspecialchars($contact_label) ?></span>
                                </div>
                                <div class="info-row border-bottom-0 pt-2">
                                    <span class="info-label text-muted"><i class="bi bi-calendar"></i> Member Since</span>
                                    <span class="info-value text-dark"><?= htmlspecialchars($member_since_label) ?></span>
                                </div>
                            </div>

                            <?php if ($can_edit_profile): ?>
                            <button type="button" class="btn btn-outline-primary w-100 mt-4" data-bs-toggle="modal" data-bs-target="#residentProfileModal">
                                <i class="bi bi-pencil-square me-1"></i> Edit Profile Information
                            </button>
                            <?php endif; ?>
                            <a href="../settings.php" class="btn btn-light w-100 mt-2">
                                <i class="bi bi-gear me-1"></i> Account Settings
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 mt-4 mt-lg-0">
                    <div class="card h-100">
                        <div class="card-body">
                            <?php if ($announcement_error): ?>
                                <div class="d-flex flex-column justify-content-center align-items-center text-center py-5 h-100" role="status">
                                    <i class="bi bi-cloud-slash text-muted mb-3 fs-1 opacity-50" aria-hidden="true"></i>
                                    <h5 class="fw-bold text-dark">Announcements unavailable</h5>
                                    <p class="text-muted mb-0">Please try again later.</p>
                                </div>
                            <?php elseif (empty($announcements)): ?>
                                <div class="d-flex flex-column justify-content-center align-items-center text-center py-5 h-100">
                                    <i class="bi bi-megaphone text-muted mb-3 fs-1 opacity-50"></i>
                                    <h5 class="fw-bold text-dark">Community Announcements</h5>
                                    <p class="text-muted">There are currently no new announcements from the Homeowners Association.</p>
                                </div>
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-megaphone-fill text-primary me-2"></i>Community Announcements</h5>
                                    <span class="badge badge-info"><?= count($announcements) ?> latest</span>
                                </div>
                                <div class="announcements-list pe-1" style="max-height: 500px; overflow-y: auto;">
                                    <?php foreach ($announcements as $index => $a): ?>
                                        <button type="button" class="announcement-item w-100 text-start mb-2 p-3 rounded-3 border bg-white"
                                             data-bs-toggle="modal" data-bs-target="#viewAnnouncementModal"
                                             data-title="<?= htmlspecialchars($a['title']) ?>"
                                             data-date="<?= date('M d, Y h:i A', strtotime($a['created_at'])) ?>"
                                             data-message="<?= htmlspecialchars($a['message']) ?>">

                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($a['title']) ?></h6>
                                                <span class="text-muted small" style="white-space: nowrap;">
                                                    <i class="bi bi-calendar-event me-1"></i><?= date('M d, Y', strtotime($a['created_at'])) ?>
                                                </span>
                                            </div>
                                            <p class="text-muted mb-0 small"><?= htmlspecialchars(mb_strimwidth(strip_tags($a['message']), 0, 140, '…', 'UTF-8')) ?></p>
                                            <span class="visually-hidden">Open announcement details</span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Connected accounts (Google / phone login) -->
            <div class="row mt-4" id="connectedAccounts">
                <div class="col-12">
                    <?php require_once '../includes/connected_accounts.php'; ?>
                </div>
            </div>

            <?php if ($homeowner): ?>
                <section class="card mt-4">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                            <h2 class="h5 mb-0">Emergency &amp; Household Details</h2>
                            <?php if ($can_edit_details): ?>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#residentDetailsModal">
                                    <i class="bi bi-pencil me-1"></i>Edit details
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if ($can_edit_details): ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="small text-muted">Emergency Person</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($homeowner['emergency_contact_name'] ?: '—') ?></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-muted">Emergency contact number</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($homeowner['emergency_contact_number'] ?: '—') ?></div>
                                </div>
                                <div class="col-12">
                                    <div class="small text-muted">Household members</div>
                                    <div class="fw-semibold" style="white-space: pre-wrap;"><?= htmlspecialchars($homeowner['household_members'] ?: '—') ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">These details can be added after admin verification.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if ($can_edit_details): ?>
<div class="modal fade" id="residentDetailsModal" tabindex="-1" aria-labelledby="residentDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" data-fullscreen-loading="Saving household details...">
                <input type="hidden" name="action" value="update_resident_details">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="residentDetailsModalLabel">Emergency &amp; Household Details</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="emergencyContactName" class="form-label">Emergency Person</label>
                        <input type="text" id="emergencyContactName" name="emergency_contact_name" class="form-control" maxlength="100" value="<?= htmlspecialchars($details_values['emergency_contact_name'] ?? ($homeowner['emergency_contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="emergencyContactNumber" class="form-label">Emergency contact number</label>
                        <input type="tel" id="emergencyContactNumber" name="emergency_contact_number" class="form-control" maxlength="30" value="<?= htmlspecialchars($details_values['emergency_contact_number'] ?? ($homeowner['emergency_contact_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="householdMembers" class="form-label">Household members</label>
                        <textarea id="householdMembers" name="household_members" class="form-control" rows="5" maxlength="5000"><?= htmlspecialchars($details_values['household_members'] ?? ($homeowner['household_members'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-2"></i>Save details</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_edit_profile): ?>
<div class="modal fade" id="residentProfileModal" tabindex="-1" aria-labelledby="residentProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" data-fullscreen-loading="Saving profile changes...">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="residentProfileModalLabel">Edit Profile Information</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Keep your contact information current so the HOA can reach you.</p>
                    <div class="mb-3">
                        <label for="residentProfileName" class="form-label">Full name</label>
                        <input type="text" id="residentProfileName" name="full_name" class="form-control" maxlength="100" autocomplete="name" value="<?= htmlspecialchars($profile_values['full_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="residentProfileEmail" class="form-label">Email address</label>
                        <input type="email" id="residentProfileEmail" name="email" class="form-control" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($profile_values['email'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="residentProfileContact" class="form-label">Contact number</label>
                        <input type="tel" id="residentProfileContact" name="contact" class="form-control" maxlength="20" autocomplete="tel" value="<?= htmlspecialchars($profile_values['contact'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div>
                        <label for="residentProfileAddress" class="form-label">Address</label>
                        <input type="text" id="residentProfileAddress" name="address" class="form-control" maxlength="150" autocomplete="street-address" value="<?= htmlspecialchars($profile_values['address'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-2"></i>Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View Announcement Modal -->
<div class="modal fade" id="viewAnnouncementModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
        <h4 class="modal-title fw-bold fs-4" id="modalAnnounceTitle" style="color: var(--primary-color) !important;"></h4>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2 pb-4 px-4">
        <p class="text-muted mb-4 pb-2 border-bottom small">
            <i class="bi bi-clock-history me-1"></i>
            <span id="modalAnnounceDate"></span>
        </p>
        <div id="modalAnnounceMessage" class="text-dark" style="white-space: pre-wrap; font-size: 1.05rem; line-height: 1.6;">
            <!-- Message will load here -->
        </div>
      </div>
      <div class="modal-footer border-top-0 pt-0 mb-2 px-4">
        <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
    <?php if ($profile_error && $active_modal): ?>
    document.addEventListener('DOMContentLoaded', function () {
        var editModal = document.getElementById(<?= json_encode($active_modal) ?>);
        if (editModal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(editModal).show();
    });
    <?php endif; ?>

    // Auto-submit the upload form as soon as a photo is chosen
    document.getElementById('photoInput')?.addEventListener('change', function () {
        if (this.files && this.files.length > 0) {
            window.showPageLoading('Uploading profile photo...');
            document.getElementById('photoUploadForm').submit();
        }
    });

    // Populate View Announcement Modal with Data
    document.addEventListener('DOMContentLoaded', function() {
        var viewAnnouncementModal = document.getElementById('viewAnnouncementModal');
        if (viewAnnouncementModal) {
            viewAnnouncementModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;

                var title = button.getAttribute('data-title');
                var date = button.getAttribute('data-date');
                var message = button.getAttribute('data-message');

                viewAnnouncementModal.querySelector('#modalAnnounceTitle').textContent = title;
                viewAnnouncementModal.querySelector('#modalAnnounceDate').textContent = date;
                viewAnnouncementModal.querySelector('#modalAnnounceMessage').textContent = message;
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
