    <?php
    require_once 'config/init.php';
    require_once 'config/db.php';
    require_once 'includes/archive_helpers.php';
    require_once 'includes/auth_helpers.php';
    require_once 'includes/homeowner_helpers.php';

    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }

    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['settings_error'] = 'Your session expired. Please try again.';
        } elseif (($_POST['action'] ?? '') === 'update_dues' && $isAdmin) {
            $dues = $_POST['dues'] ?? null;
            if (!is_array($dues) || !$dues) {
                $_SESSION['settings_error'] = 'No dues adjustments were submitted.';
            } else {
                $updatedCount = 0;
                $transactionStarted = false;
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $stmt = $conn->prepare('UPDATE homeowners SET monthly_due = ? WHERE id = ?');
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare dues update.');
                    }
                    foreach ($dues as $homeownerId => $amount) {
                        if (!ctype_digit((string) $homeownerId) || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
                            throw new InvalidArgumentException('Please enter valid, non-negative dues for every homeowner.');
                        }
                        $homeownerId = (int) $homeownerId;
                        $amount = (float) $amount;
                        $stmt->bind_param('di', $amount, $homeownerId);
                        if (!$stmt->execute()) {
                            throw new RuntimeException('Unable to save all dues adjustments.');
                        }
                        $updatedCount += $stmt->affected_rows >= 0 ? 1 : 0;
                    }
                    $stmt->close();
                    $conn->commit();
                    $transactionStarted = false;
                    $_SESSION['settings_success'] = "Updated dues for {$updatedCount} homeowner(s).";
                } catch (Throwable $e) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    if ($e instanceof InvalidArgumentException) {
                        $_SESSION['settings_error'] = $e->getMessage();
                    } else {
                        error_log('Admin dues update failed for user ' . (int) $_SESSION['user_id'] . ': ' . $e->getMessage());
                        $_SESSION['settings_error'] = 'We could not save the dues adjustments. No changes were kept.';
                    }
                }
            }
            $duesReturnParams = ['section' => 'dues'];
            $filterSearch = trim((string) ($_POST['filter_search'] ?? ''));
            $filterStreet = trim((string) ($_POST['filter_street'] ?? ''));
            if ($filterSearch !== '') $duesReturnParams['search'] = $filterSearch;
            if ($filterStreet !== '') $duesReturnParams['street'] = $filterStreet;
            header('Location: ' . BASE_PATH . '/settings.php?' . http_build_query($duesReturnParams));
            exit;
        } elseif (($_POST['action'] ?? '') === 'update_photo' && $isAdmin) {
            $uid = (int) $_SESSION['user_id'];
            $file = $_FILES['profile_photo'] ?? null;
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = '';
            if (is_array($file) && $file['error'] === UPLOAD_ERR_OK && $file['size'] <= 2 * 1024 * 1024) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = (string) finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
            }

            if (!is_array($file) || $file['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['settings_error'] = 'Upload failed. Please try a different photo.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $_SESSION['settings_error'] = 'That photo is too large. Please choose one under 2MB.';
            } elseif (!isset($allowedMimes[$mime])) {
                $_SESSION['settings_error'] = 'Please upload a JPG, PNG, or WEBP image.';
            } else {
                $uploadDir = 'Assets/uploads/avatars/';
                $storedPath = $uploadDir . "user_{$uid}." . $allowedMimes[$mime];
                $temporaryPath = $storedPath . '.uploading';
                try {
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                        throw new RuntimeException('Unable to create the photo folder.');
                    }
                    if (!move_uploaded_file($file['tmp_name'], $temporaryPath)) {
                        throw new RuntimeException('Unable to save the uploaded photo.');
                    }

                    $stmt = $conn->prepare('SELECT profile_photo FROM users WHERE id = ?');
                    if (!$stmt) throw new RuntimeException('Unable to prepare photo lookup.');
                    $stmt->bind_param('i', $uid);
                    if (!$stmt->execute()) throw new RuntimeException('Unable to read the current photo.');
                    $oldPath = (string) ($stmt->get_result()->fetch_assoc()['profile_photo'] ?? '');
                    $stmt->close();

                    if (!rename($temporaryPath, $storedPath)) {
                        throw new RuntimeException('Unable to replace the saved photo.');
                    }

                    $stmt = $conn->prepare('UPDATE users SET profile_photo = ? WHERE id = ?');
                    if (!$stmt) throw new RuntimeException('Unable to prepare photo update.');
                    $stmt->bind_param('si', $storedPath, $uid);
                    if (!$stmt->execute()) throw new RuntimeException('Unable to update the photo record.');
                    $stmt->close();

                    if ($oldPath !== '' && $oldPath !== $storedPath && is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                    $_SESSION['settings_success'] = 'Profile photo updated.';
                } catch (Throwable $e) {
                    if (is_file($temporaryPath)) @unlink($temporaryPath);
                    error_log('Admin profile photo update failed for user ' . $uid . ': ' . $e->getMessage());
                    $_SESSION['settings_error'] = 'We could not save your photo. Please try again.';
                }
            }
        } elseif (($_POST['action'] ?? '') === 'change_password') {
            unset($_SESSION['settings_error_field']);
            $uid = (int) $_SESSION['user_id'];
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            $check = reauth_check($conn, $uid, $current);
            if ($check !== true) {
                $_SESSION['settings_error'] = $check === 'Incorrect password.'
                    ? 'Your current password is incorrect.'
                    : $check;
                $_SESSION['settings_error_field'] = 'current_password';
            } elseif (strlen($new) < 8) {
                $_SESSION['settings_error'] = 'New password must be at least 8 characters.';
                $_SESSION['settings_error_field'] = 'new_password';
            } elseif ($new !== $confirm) {
                $_SESSION['settings_error'] = 'New passwords do not match.';
                $_SESSION['settings_error_field'] = 'confirm_password';
            } elseif ($new === $current) {
                $_SESSION['settings_error'] = 'New password must be different from your current password.';
                $_SESSION['settings_error_field'] = 'new_password';
            } else {
                try {
                    $conn->begin_transaction();
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare password update.');
                    }
                    $stmt->bind_param('si', $hash, $uid);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to update password.');
                    }
                    $stmt->close();

                    $stmt = $conn->prepare('DELETE FROM password_resets WHERE user_id = ?');
                    if (!$stmt) {
                        throw new RuntimeException('Unable to invalidate password reset links.');
                    }
                    $stmt->bind_param('i', $uid);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to invalidate password reset links.');
                    }
                    $stmt->close();
                    $conn->commit();

                    session_regenerate_id(true);
                    $_SESSION['settings_success'] = 'Your password was updated.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('Settings password update failed for user ' . $uid . ': ' . $e->getMessage());
                    $_SESSION['settings_error'] = 'We could not update your password. Please try again.';
                    $_SESSION['settings_error_field'] = 'new_password';
                }
            }
        } else {
            $_SESSION['settings_error'] = 'Invalid settings action.';
        }

        header('Location: ' . BASE_PATH . '/settings.php');
        exit;
    }

    $success = $_SESSION['settings_success'] ?? '';
    $error = $_SESSION['settings_error'] ?? '';
    $settingsErrorField = $_SESSION['settings_error_field'] ?? '';
    unset($_SESSION['settings_success'], $_SESSION['settings_error']);
    unset($_SESSION['settings_error_field']);
    $csrfToken = generateCsrfToken();
    $roleLabel = $isAdmin ? 'Administrator' : 'Resident';
    $search = trim((string) ($_GET['search'] ?? ''));
    $streets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];
    $street = trim((string) ($_GET['street'] ?? ''));
    if (!in_array(strtolower($street), array_map('strtolower', $streets), true)) $street = '';
    $homeowners = [];
    $duesLoadError = '';
    if ($isAdmin) {
        try {
            ensure_archive_columns($conn, 'homeowners');
            $conditions = ['archived_at IS NULL'];
            $params = [];
            $types = '';
            if ($search !== '') {
                $conditions[] = '(block LIKE ? OR lot LIKE ? OR full_name LIKE ?)';
                $term = '%' . $search . '%';
                array_push($params, $term, $term, $term);
                $types .= 'sss';
            }
            if ($street !== '') {
                $conditions[] = 'LOWER(TRIM(block)) = LOWER(?)';
                $params[] = $street;
                $types .= 's';
            }
            $stmt = $conn->prepare('SELECT id, block, lot, full_name, status, monthly_due FROM homeowners WHERE ' . implode(' AND ', $conditions) . ' ORDER BY block, lot');
            if (!$stmt) throw new RuntimeException('Unable to prepare homeowner search.');
            if ($types !== '') $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) throw new RuntimeException('Unable to search homeowner records.');
            $result = $stmt->get_result();
            $homeowners = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Unable to load homeowner dues in Settings: ' . $e->getMessage());
            $duesLoadError = 'We could not load homeowner dues. Please try again later.';
        }
    }

    if ($isAdmin && ($_GET['ajax'] ?? '') === 'dues') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode([
            'success' => $duesLoadError === '',
            'message' => $duesLoadError,
            'homeowners' => array_map(static function ($homeowner) {
                return [
                    'id' => (int) $homeowner['id'],
                    'block' => (string) ($homeowner['block'] ?? ''),
                    'lot' => (string) ($homeowner['lot'] ?? ''),
                    'address' => homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''),
                    'full_name' => (string) ($homeowner['full_name'] ?? ''),
                    'status' => (string) ($homeowner['status'] ?? 'inactive'),
                    'monthly_due' => (string) ($homeowner['monthly_due'] ?? '0.00'),
                ];
            }, $homeowners),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>Settings - Manuela 4B MIS</title>
        <link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
        <meta name="theme-color" content="#0F4C3F">
        <script>
        (function () {
            try {
                var d = document.documentElement;
                if (localStorage.getItem('sb_collapsed') === '1') d.classList.add('sb-collapsed');
            } catch (e) {}
        })();
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
        <link href="Assets/css/global.css?v=22" rel="stylesheet">
        <link href="Assets/css/components.css?v=21" rel="stylesheet">
        <link href="Assets/css/admin.css?v=27" rel="stylesheet">
        <link href="Assets/css/resident.css?v=23" rel="stylesheet">
        <link href="Assets/css/reference-theme.css?v=50" rel="stylesheet">
        <link href="Assets/css/fluid-layout.css?v=1" rel="stylesheet">
        <link href="Assets/css/admin-compact.css?v=1" rel="stylesheet">
        <style>
            .settings-page--admin .settings-content {
                display: flex;
                flex-direction: column;
            }

            .settings-page--admin .settings-alert { order: 1; }
            .settings-page--admin .settings-profile-card { order: 1; }
            .dues-homeowner-address { display: none; }
            .settings-profile-card .avatar-circle { flex-shrink: 0; margin: 0; }
            .settings-photo-button { display: inline-flex; min-height: 48px; align-items: center; justify-content: center; }
            .settings-page--admin .settings-dues-section { order: 2; }
            .settings-page--admin .settings-signout-card { order: 3; }
            .settings-page--admin .settings-password-card { order: 4; }

            @media (max-width: 767.98px) {
                .settings-page--admin .settings-signout-card { display: none; }
                .settings-signout-card .btn { display: inline-flex; width: 100%; min-height: 48px; align-items: center; justify-content: center; }
            }

            @media (min-width: 768px) {
                .settings-signout-card { display: none; }
            }

            .settings-back-button {
                display: inline-flex;
                min-height: 48px;
                align-items: center;
                justify-content: center;
            }
        </style>
        <link href="Assets/css/responsive-fix.css?v=2" rel="stylesheet">
        <link href="Assets/css/mobile-refinements.css?v=1" rel="stylesheet">
        <link href="Assets/css/fluid-layout.css?v=1" rel="stylesheet">
        <link href="Assets/css/motion.css?v=2" rel="stylesheet">
    </head>
    <body>
        <div class="app-wrapper">
            <?php require 'includes/sidebar.php'; ?>
            <main class="main-content settings-page<?= $isAdmin ? ' settings-page--admin' : '' ?>">
                <div class="container-fluid p-0 settings-content">
                    <?php if ($success): ?>
                        <div class="alert alert-success d-flex align-items-center mb-4 settings-alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <div><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-4 settings-alert">
                            <i class="bi bi-exclamation-circle-fill me-2"></i>
                            <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                        <div class="card mb-4 settings-profile-card" style="max-width: 720px;">
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" id="adminPhotoForm" class="d-flex flex-wrap align-items-center gap-3">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_photo">
                                    <?php if ($sidebarPhotoUrl !== ''): ?>
                                        <div class="avatar-circle avatar-circle-photo">
                                            <img src="<?= htmlspecialchars($sidebarPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo">
                                        </div>
                                    <?php else: ?>
                                        <div class="avatar-circle" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($sidebarUserName, 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <h2 class="h5 fw-bold mb-1">Profile Photo</h2>
                                        <p class="text-muted mb-0">JPG, PNG, or WEBP, up to 2MB.</p>
                                    </div>
                                    <input type="file" name="profile_photo" id="adminPhotoInput" class="btn-check" accept="image/png, image/jpeg, image/webp">
                                    <label for="adminPhotoInput" class="btn btn-outline-primary settings-photo-button">
                                        <i class="bi bi-camera me-1" aria-hidden="true"></i><?= $sidebarPhotoUrl !== '' ? 'Change Photo' : 'Upload Photo' ?>
                                    </label>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card mb-4 settings-password-card" style="max-width: 720px;">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3 mb-4">
                                <div class="icon-box-soft icon-box-soft-primary">
                                    <i class="bi bi-shield-lock"></i>
                                </div>
                                <div>
                                    <h2 class="h5 fw-bold mb-1">Change Password</h2>
                                    <?php if ($isAdmin): ?><p class="text-muted mb-0">Confirm your current password before setting a new one.</p><?php endif; ?>
                                </div>
                            </div>

            <form method="POST" autocomplete="on" id="settingsPasswordForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="change_password">
                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label">Current Password</label>
                                <div class="password-visibility-field password-visibility-field--inline">
                                    <input type="password" id="currentPassword" name="current_password" class="form-control" autocomplete="current-password" required <?= $settingsErrorField === 'current_password' ? 'autofocus aria-invalid="true"' : '' ?>>
                                    <button type="button" class="password-visibility-toggle password-visibility-toggle--icon" data-password-target="currentPassword" data-password-label="current password" aria-label="Show current password" aria-pressed="false"><i class="bi bi-eye-fill" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="newPassword" class="form-label">New Password</label>
                                    <div class="password-visibility-field password-visibility-field--inline">
                                        <input type="password" id="newPassword" name="new_password" class="form-control" minlength="8" autocomplete="new-password" required <?= $settingsErrorField === 'new_password' ? 'autofocus aria-invalid="true"' : '' ?>>
                                        <button type="button" class="password-visibility-toggle password-visibility-toggle--icon" data-password-target="newPassword" data-password-label="new password" aria-label="Show new password" aria-pressed="false"><i class="bi bi-eye-fill" aria-hidden="true"></i></button>
                                    </div>
                                    <div class="form-text">Use at least 8 characters. <span id="passwordMatchStatus" class="ms-1" aria-live="polite"></span></div>
                                </div>
                                <div class="mb-4">
                                    <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                    <div class="password-visibility-field password-visibility-field--inline">
                                        <input type="password" id="confirmPassword" name="confirm_password" class="form-control" minlength="8" autocomplete="new-password" required <?= $settingsErrorField === 'confirm_password' ? 'autofocus aria-invalid="true"' : '' ?>>
                                        <button type="button" class="password-visibility-toggle password-visibility-toggle--icon" data-password-target="confirmPassword" data-password-label="confirmation password" aria-label="Show confirmation password" aria-pressed="false"><i class="bi bi-eye-fill" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-key me-1"></i> Update Password
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if (!$isAdmin): ?>
                        <div class="card mb-4" style="max-width: 720px;">
                            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                                <div>
                                    <h2 class="h6 fw-bold mb-1">Sign-in options</h2>
                                    <p class="text-muted mb-0">Manage your connected Google account from your profile.</p>
                                </div>
                                <a class="btn btn-outline-primary" href="<?= BASE_PATH ?>/resident/profile.php#connectedAccounts">Manage accounts</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card mb-4 settings-signout-card" style="max-width: 720px;">
                        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <h2 class="h6 fw-bold mb-1">Sign out</h2>
                                <p class="text-muted mb-0">Log out of your Manuela 4B account on this device.</p>
                            </div>
                            <a class="btn btn-outline-danger" href="<?= BASE_PATH ?>/logout.php">
                                <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i> Log out
                            </a>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
                        <section class="card mt-4 settings-dues-section" id="manageDues">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                                    <div class="d-flex align-items-start gap-3">
                                        <div class="icon-box-soft icon-box-soft-warning">
                                            <i class="bi bi-cash-stack"></i>
                                        </div>
                                        <div>
                                            <h2 class="h5 fw-bold mb-1">Manage Homeowner Dues</h2>
                                            <p class="text-muted mb-0">Adjust each homeowner's monthly due. Changes apply to future billing.</p>
                                        </div>
                                    </div>
                                    <a class="btn btn-outline-secondary settings-back-button" href="<?= BASE_PATH ?>/admin/dashboard.php">
                                        <i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Back to Dashboard
                                    </a>
                                </div>

                                <?php if ($duesLoadError): ?>
                                    <div class="alert alert-danger"><?= htmlspecialchars($duesLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>

                                <form method="GET" action="<?= BASE_PATH ?>/settings.php" class="row g-3 align-items-end mb-2" id="duesFilterForm">
                                    <input type="hidden" name="section" value="dues">
                                    <div class="col-12 col-md-8">
                                        <label for="duesSearch" class="form-label">Find a homeowner</label>
                                        <div class="cir-search">
                                            <i class="bi bi-search cir-search__icon" aria-hidden="true"></i>
                                            <input type="search" id="duesSearch" name="search" class="cir-search__field"
                                                placeholder="Search house number or name"
                                                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                                            <span class="cir-search__kbd" aria-hidden="true">Enter</span>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label for="duesStreet" class="form-label">Street</label>
                                        <select id="duesStreet" name="street" class="form-select">
                                            <option value="">All streets</option>
                                            <?php foreach ($streets as $streetOption): ?>
                                                <option value="<?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($street, $streetOption) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                                <div id="duesResultsStatus" class="small text-muted mb-3" role="status" aria-live="polite">
                                    Showing <?= count($homeowners) ?> homeowner<?= count($homeowners) === 1 ? '' : 's' ?>. Results update as you type or change street.
                                </div>

                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_dues">
                                    <input type="hidden" name="filter_search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="filter_street" value="<?= htmlspecialchars($street, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Property Address</th>
                                                    <th>Homeowner</th>
                                                    <th>Status</th>
                                                    <th style="min-width: 190px;">Monthly Due (₱)</th>
                                                </tr>
                                            </thead>
                                            <tbody id="duesRows">
                                                <?php foreach ($homeowners as $homeowner): ?>
                                                    <?php
                                                        $status = strtolower((string) ($homeowner['status'] ?? 'inactive'));
                                                        $badgeClass = $status === 'active' ? 'text-bg-success' : ($status === 'pending' ? 'text-bg-warning' : 'text-bg-secondary');
                                                    ?>
                                                    <tr>
                                                        <td class="m-hide"><?= htmlspecialchars(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td class="dues-name">
                                                            <div class="dues-homeowner-cell">
                                                                <div class="dues-homeowner-info">
                                                                    <span class="dues-homeowner-name"><?= htmlspecialchars((string) ($homeowner['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                                    <span class="dues-homeowner-address"><?= htmlspecialchars(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                                </div>
                                                                <button type="button" class="btn btn-sm btn-outline-secondary dues-homeowner-details"
                                                                    data-bs-toggle="modal" data-bs-target="#duesHomeownerModal"
                                                                    data-homeowner-name="<?= htmlspecialchars((string) ($homeowner['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-homeowner-address="<?= htmlspecialchars(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-homeowner-status="<?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-homeowner-due="<?= htmlspecialchars((string) ($homeowner['monthly_due'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?>"
                                                                    aria-label="View details for <?= htmlspecialchars((string) ($homeowner['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                        <td class="m-hide"><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span></td>
                                                        <td class="dues-amount">
                                                            <div class="input-group">
                                                                <span class="input-group-text">₱</span>
                                                                <input type="number" min="0" step="0.01"
                                                                    name="dues[<?= (int) $homeowner['id'] ?>]"
                                                                    class="form-control"
                                                                    value="<?= htmlspecialchars((string) ($homeowner['monthly_due'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?>"
                                                                    required>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (!$homeowners): ?>
                                                    <tr>
                                                    <td colspan="4" class="text-center text-muted py-4">
                                                            <?= ($search !== '' || $street !== '') ? 'No homeowners match your selected filters.' : 'No homeowner records found.' ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div id="duesSaveActions" class="d-flex justify-content-end mt-4" <?= $homeowners ? '' : 'hidden' ?>>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check2-circle me-1"></i> Save Dues
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </main>
        </div>
        <div class="modal fade" id="duesHomeownerModal" tabindex="-1" aria-labelledby="duesHomeownerModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title h5 mb-1" id="duesHomeownerModalTitle">Homeowner Details</h2>
                            <p class="mb-0 text-muted" id="duesHomeownerModalName"></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <dl class="dues-homeowner-modal-details">
                            <dt>Property Address</dt>
                            <dd id="duesHomeownerModalAddress"></dd>
                            <dt>Status</dt>
                            <dd id="duesHomeownerModalStatus"></dd>
                            <dt>Monthly Due</dt>
                            <dd>₱ <span id="duesHomeownerModalDue"></span></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.getElementById('duesHomeownerModal');
            if (!modal) return;

            modal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;

                document.getElementById('duesHomeownerModalName').textContent = button.dataset.homeownerName || '';
                document.getElementById('duesHomeownerModalAddress').textContent = button.dataset.homeownerAddress || '—';
                document.getElementById('duesHomeownerModalStatus').textContent = button.dataset.homeownerStatus || '—';
                var dueInput = button.closest('tr')?.querySelector('input[name^="dues["]');
                document.getElementById('duesHomeownerModalDue').textContent = dueInput ? dueInput.value : (button.dataset.homeownerDue || '0.00');
            });
        })();
        </script>
        <script>
        (function () {
            var filterForm = document.getElementById('duesFilterForm');
            var searchInput = document.getElementById('duesSearch');
            var streetSelect = document.getElementById('duesStreet');
            var rows = document.getElementById('duesRows');
            var status = document.getElementById('duesResultsStatus');
            var saveActions = document.getElementById('duesSaveActions');
            var duesForm = document.querySelector('input[name="action"][value="update_dues"]')?.form;
            if (!filterForm || !searchInput || !streetSelect || !rows || !status) return;

            var timer = null;
            var requestNumber = 0;
            var dirtyDues = new Map();

            function addCell(row, value) {
                var cell = document.createElement('td');
                cell.textContent = value;
                row.appendChild(cell);
                return cell;
            }

            function rememberDues() {
                rows.querySelectorAll('input[name^="dues["]').forEach(function (input) {
                    if (input.dataset.edited === '1') {
                        var match = input.name.match(/^dues\[(\d+)\]$/);
                        if (match) dirtyDues.set(match[1], input.value);
                    }
                });
            }

            function renderHomeowners(homeowners, search, street) {
                rows.replaceChildren();
                if (saveActions) saveActions.hidden = !homeowners.length;
                if (!homeowners.length) {
                    var emptyRow = document.createElement('tr');
                    var emptyCell = addCell(emptyRow, search || street ? 'No homeowners match your selected filters.' : 'No homeowner records found.');
                    emptyCell.colSpan = 4;
                    emptyCell.className = 'text-center text-muted py-4';
                    rows.appendChild(emptyRow);
                    return;
                }

                homeowners.forEach(function (homeowner) {
                    var row = document.createElement('tr');
                    addCell(row, homeowner.address).className = 'm-hide';
                    var homeownerStatus = (homeowner.status || 'inactive').toLowerCase();
                    var homeownerCell = document.createElement('td');
                    homeownerCell.className = 'dues-name';
                    var homeownerInfo = document.createElement('div');
                    homeownerInfo.className = 'dues-homeowner-cell';
                    var homeownerText = document.createElement('div');
                    homeownerText.className = 'dues-homeowner-info';
                    var homeownerName = document.createElement('span');
                    homeownerName.className = 'dues-homeowner-name';
                    homeownerName.textContent = homeowner.full_name || '';
                    var homeownerAddress = document.createElement('span');
                    homeownerAddress.className = 'dues-homeowner-address';
                    homeownerAddress.textContent = homeowner.address || '';
                    homeownerText.append(homeownerName, homeownerAddress);
                    var detailsButton = document.createElement('button');
                    detailsButton.type = 'button';
                    detailsButton.className = 'btn btn-sm btn-outline-secondary dues-homeowner-details';
                    detailsButton.setAttribute('data-bs-toggle', 'modal');
                    detailsButton.setAttribute('data-bs-target', '#duesHomeownerModal');
                    detailsButton.setAttribute('data-homeowner-name', homeowner.full_name || '');
                    detailsButton.setAttribute('data-homeowner-address', homeowner.address || '');
                    detailsButton.setAttribute('data-homeowner-status', homeownerStatus.charAt(0).toUpperCase() + homeownerStatus.slice(1));
                    detailsButton.setAttribute('data-homeowner-due', homeowner.monthly_due || '0.00');
                    detailsButton.setAttribute('aria-label', 'View details for ' + (homeowner.full_name || 'homeowner'));
                    var detailsIcon = document.createElement('i');
                    detailsIcon.className = 'bi bi-eye';
                    detailsIcon.setAttribute('aria-hidden', 'true');
                    detailsButton.appendChild(detailsIcon);
                    homeownerInfo.append(homeownerText, detailsButton);
                    homeownerCell.appendChild(homeownerInfo);
                    row.appendChild(homeownerCell);
                    var statusCell = document.createElement('td');
                    statusCell.className = 'm-hide';
                    var badge = document.createElement('span');
                    badge.className = 'badge ' + (homeownerStatus === 'active' ? 'text-bg-success' : (homeownerStatus === 'pending' ? 'text-bg-warning' : 'text-bg-secondary'));
                    badge.textContent = homeownerStatus.charAt(0).toUpperCase() + homeownerStatus.slice(1);
                    statusCell.appendChild(badge);
                    row.appendChild(statusCell);

                    var dueCell = document.createElement('td');
                    dueCell.className = 'dues-amount';
                    var group = document.createElement('div');
                    group.className = 'input-group';
                    var currency = document.createElement('span');
                    currency.className = 'input-group-text';
                    currency.textContent = '₱';
                    var dueInput = document.createElement('input');
                    dueInput.type = 'number';
                    dueInput.min = '0';
                    dueInput.step = '0.01';
                    dueInput.name = 'dues[' + homeowner.id + ']';
                    dueInput.className = 'form-control';
                    dueInput.value = dirtyDues.has(String(homeowner.id)) ? dirtyDues.get(String(homeowner.id)) : homeowner.monthly_due;
                    dueInput.required = true;
                    dueInput.addEventListener('input', function () {
                        dueInput.dataset.edited = '1';
                        dirtyDues.set(String(homeowner.id), dueInput.value);
                        detailsButton.setAttribute('data-homeowner-due', dueInput.value);
                    });
                    group.append(currency, dueInput);
                    dueCell.appendChild(group);
                    row.appendChild(dueCell);
                    rows.appendChild(row);
                });
            }

            function updateResults() {
                rememberDues();
                var thisRequest = ++requestNumber;
                var search = searchInput.value.trim();
                var street = streetSelect.value;
                var url = new URL(filterForm.action, window.location.href);
                url.searchParams.set('section', 'dues');
                url.searchParams.set('ajax', 'dues');
                if (search) url.searchParams.set('search', search); else url.searchParams.delete('search');
                if (street) url.searchParams.set('street', street); else url.searchParams.delete('street');

                status.textContent = 'Updating homeowner list…';
                filterForm.setAttribute('aria-busy', 'true');
                fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
                    .then(function (response) {
                        if (!response.ok) throw new Error('Could not update homeowner list.');
                        return response.json();
                    })
                    .then(function (data) {
                        if (thisRequest !== requestNumber) return;
                        if (!data.success) throw new Error(data.message || 'Could not update homeowner list.');
                        var homeowners = Array.isArray(data.homeowners) ? data.homeowners : [];
                        renderHomeowners(homeowners, search, street);
                        if (duesForm) {
                            var filterSearch = duesForm.querySelector('input[name="filter_search"]');
                            var filterStreet = duesForm.querySelector('input[name="filter_street"]');
                            if (filterSearch) filterSearch.value = search;
                            if (filterStreet) filterStreet.value = street;
                        }
                        status.textContent = 'Showing ' + homeowners.length + ' homeowner' + (homeowners.length === 1 ? '' : 's') + '. Results update as you type or change street.';
                        url.searchParams.delete('ajax');
                        window.history.replaceState({}, '', url.toString());
                    })
                    .catch(function (error) {
                        if (thisRequest !== requestNumber) return;
                        status.textContent = error.message || 'Could not update homeowner list. Please try again.';
                    })
                    .finally(function () {
                        if (thisRequest === requestNumber) filterForm.removeAttribute('aria-busy');
                    });
            }

            filterForm.addEventListener('submit', function (event) {
                event.preventDefault();
                window.clearTimeout(timer);
                updateResults();
            });
            searchInput.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(updateResults, 300);
            });
            streetSelect.addEventListener('change', function () {
                window.clearTimeout(timer);
                updateResults();
            });

            if (duesForm) duesForm.addEventListener('submit', function () {
                rememberDues();
                var visibleIds = new Set(Array.from(rows.querySelectorAll('input[name^="dues["]')).map(function (input) {
                    var match = input.name.match(/^dues\[(\d+)\]$/);
                    return match ? match[1] : '';
                }));
                dirtyDues.forEach(function (value, id) {
                    if (visibleIds.has(id)) return;
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'dues[' + id + ']';
                    hidden.value = value;
                    hidden.dataset.filteredDues = '1';
                    duesForm.appendChild(hidden);
                });
            });
        })();
        </script>
        <?php require_once 'includes/footer.php'; ?>
        <script>
        document.getElementById('adminPhotoInput')?.addEventListener('change', function () {
            if (!this.files || !this.files.length) return;
            if (window.showPageLoading) window.showPageLoading('Uploading profile photo...');
            this.form.submit();
        });
        </script>
        <script>
        (function () {
            var newPassword = document.getElementById('newPassword');
            var confirmPassword = document.getElementById('confirmPassword');
            var status = document.getElementById('passwordMatchStatus');
            var form = document.getElementById('settingsPasswordForm');
            if (!newPassword || !confirmPassword || !status || !form) return;

            function updateMatchStatus() {
                if (!confirmPassword.value) {
                    status.textContent = '';
                    status.className = 'ms-1';
                    confirmPassword.setCustomValidity('');
                    return;
                }
                var matches = newPassword.value === confirmPassword.value;
                status.textContent = matches ? 'Passwords match.' : 'Passwords do not match.';
                status.className = 'ms-1 ' + (matches ? 'text-success' : 'text-danger');
                confirmPassword.setCustomValidity(matches ? '' : 'Passwords do not match.');
            }

            newPassword.addEventListener('input', updateMatchStatus);
            confirmPassword.addEventListener('input', updateMatchStatus);
            form.addEventListener('submit', updateMatchStatus);
            updateMatchStatus();
        })();
        </script>
    </body>
    </html>
