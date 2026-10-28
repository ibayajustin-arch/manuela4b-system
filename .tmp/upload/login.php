<?php
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/auth_helpers.php';

$showGoogle = (bool) oauth_provider('google');
$error = $_SESSION['login_error'] ?? "";
unset($_SESSION['login_error']);
$credentialFailureField = null;
$ip = client_ip();
$ipKey = 'loginip:' . $ip;
$ipLockout = throttle_left($conn, $ipKey, 15, 15); // max 15 failed attempts per IP in 15 mins

$selected_role = $_POST['selected_role'] ?? 'resident';
if (!in_array($selected_role, ['admin', 'resident'], true)) {
    $selected_role = 'resident';
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $userKey = 'loginusr:' . strtolower($username);
    $userLockout = $username !== '' ? throttle_left($conn, $userKey, 5, 5) : 0; // max 5 attempts per user in 5 mins

    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid request. Please refresh the page and try again.";
    } elseif ($ipLockout > 0 || $userLockout > 0) {
        $remaining = max($ipLockout, $userLockout);
        $error = "Too many failed attempts. Please wait {$remaining} seconds before trying again.";
    } else {

        if (!empty($username) && !empty($password)) {
            $users = [];
            $stmt = null;
            if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower($username);
                $stmt = $conn->prepare(
                    "SELECT u.* FROM homeowners h
                     JOIN users u ON u.id = h.user_id
                     WHERE LOWER(h.email) = ?
                     UNION
                     SELECT u.* FROM user_identities i
                     JOIN users u ON u.id = i.user_id
                     WHERE LOWER(i.email) = ?"
                );
                if ($stmt) {
                    $stmt->bind_param("ss", $email, $email);
                }
            } elseif (looks_like_phone($username)) {
                // A phone number is a login identifier for the existing account password.
                // This uses the contact number already stored on the homeowner record; no SMS is sent.
                $users = find_users_by_phone($conn, $username);
            } else {
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $username);
                }
            }

            if ($stmt) {
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($candidate = $result->fetch_assoc()) {
                        $users[] = $candidate;
                    }
                } else {
                    $error = "Database error. Please try again later.";
                }
                $stmt->close();

                $user = null;
                foreach ($users as $candidate) {
                    if (password_verify($password, $candidate['password'])) {
                        $user = $candidate;
                        break;
                    }
                }
                if ($user) {
                    $account_role = ($user['role'] === 'admin') ? 'admin' : 'resident';

                    if ($account_role !== $selected_role) {
                        $error = "This account is registered as " . ucfirst($account_role) . ". Please select the correct tab above and try again.";
                    } else {
                        $residentStatus = 'pending';
                        if ($user['role'] !== 'admin') {
                            $statusStmt = $conn->prepare('SELECT status FROM homeowners WHERE user_id = ? LIMIT 1');
                            if ($statusStmt) {
                                $userId = (int) $user['id'];
                                $statusStmt->bind_param('i', $userId);
                                $statusStmt->execute();
                                $statusRow = $statusStmt->get_result()->fetch_assoc();
                                $statusStmt->close();
                                $residentStatus = strtolower((string) ($statusRow['status'] ?? 'pending'));
                            }
                        }

                        if ($user['role'] !== 'admin' && $residentStatus !== 'active') {
                            $error = $residentStatus === 'pending'
                                ? 'Your registration is awaiting admin verification. You can log in after your resident status is approved.'
                                : 'This resident account is not active. Please contact the subdivision administrator.';
                        } else {
                            throttle_clear($conn, $userKey);
                            throttle_clear($conn, $ipKey);
                            session_regenerate_id(true);

                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['full_name'] = $user['full_name'];

                            if ($user['role'] === 'admin') {
                                header("Location: " . BASE_PATH . "/admin/dashboard.php");
                            } else {
                                header("Location: " . BASE_PATH . "/resident/profile.php");
                            }
                            exit;
                        }
                    }
                } else {
                    if ($error === '') {
                        throttle_hit($conn, $userKey);
                        throttle_hit($conn, $ipKey);
                        $error = "Invalid username/email or password!";
                        $credentialFailureField = $users ? 'password' : 'username';
                    }
                }
            } elseif (looks_like_phone($username)) {
                $user = null;
                foreach ($users as $candidate) {
                    if (password_verify($password, $candidate['password'])) {
                        $user = $candidate;
                        break;
                    }
                }
                if ($user) {
                    $account_role = ($user['role'] === 'admin') ? 'admin' : 'resident';

                    if ($account_role !== $selected_role) {
                        $error = "This account is registered as " . ucfirst($account_role) . ". Please select the correct tab above and try again.";
                    } else {
                        $residentStatus = 'pending';
                        if ($user['role'] !== 'admin') {
                            $statusStmt = $conn->prepare('SELECT status FROM homeowners WHERE user_id = ? LIMIT 1');
                            if ($statusStmt) {
                                $userId = (int) $user['id'];
                                $statusStmt->bind_param('i', $userId);
                                $statusStmt->execute();
                                $statusRow = $statusStmt->get_result()->fetch_assoc();
                                $statusStmt->close();
                                $residentStatus = strtolower((string) ($statusRow['status'] ?? 'pending'));
                            }
                        }

                        if ($user['role'] !== 'admin' && $residentStatus !== 'active') {
                            $error = $residentStatus === 'pending'
                                ? 'Your registration is awaiting admin verification. You can log in after your resident status is approved.'
                                : 'This resident account is not active. Please contact the subdivision administrator.';
                        } else {
                            throttle_clear($conn, $userKey);
                            throttle_clear($conn, $ipKey);
                            session_regenerate_id(true);
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['full_name'] = $user['full_name'];
                            header('Location: ' . ($user['role'] === 'admin' ? BASE_PATH . '/admin/dashboard.php' : BASE_PATH . '/resident/profile.php'));
                            exit;
                        }
                    }
                } else {
                    throttle_hit($conn, $userKey);
                    throttle_hit($conn, $ipKey);
                    $error = 'Invalid username/email or password!';
                    $credentialFailureField = $users ? 'password' : 'username';
                }
            } else {
                $error = "Database error. Please try again later.";
            }
        } else {
            $error = "Please fill in all fields.";
        }
    }
}

$csrf_token = generateCsrfToken();
$loginSubmitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$usernameBlank = $loginSubmitted && trim((string) ($_POST['username'] ?? '')) === '';
$passwordBlank = $loginSubmitted && trim((string) ($_POST['password'] ?? '')) === '';
$requiredFieldsMissing = $error === 'Please fill in all fields.';
$usernameInvalid = $credentialFailureField === 'username' || ($requiredFieldsMissing && $usernameBlank);
$passwordInvalid = $credentialFailureField === 'password' || ($requiredFieldsMissing && $passwordBlank);
$roleInvalid = strpos((string) $error, 'select the correct tab') !== false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Manuela 4B MIS</title>
    <link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css?v=21" rel="stylesheet">
    <link href="Assets/css/components.css?v=22" rel="stylesheet">
    <link href="Assets/css/resident.css?v=23" rel="stylesheet">
    <link href="Assets/css/admin.css?v=27" rel="stylesheet">
    <link href="Assets/css/reference-theme.css?v=25" rel="stylesheet">
    <link href="Assets/css/ui-refresh.css?v=1" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-shell">
        <div class="login-shell-card">

            <!-- LEFT: Form -->
            <div class="login-left-panel">
                <div class="login-brand-row">
                    <div class="login-logo-box">
                        <img src="Assets/css/images/logo2.png" alt="Manuela 4B MIS Logo">
                    </div>
                    <div>
                        <span class="brand-name d-block">Manuela 4B</span>
                        <span class="brand-tag d-block">HOA Management</span>
                    </div>
                </div>

                <div class="login-left-inner">
                    <h1 class="fw-bold">Welcome Back</h1>
                    <p class="login-subtitle">Enter your username, email, or registered phone number with your password.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-3">
                            <i class="bi bi-exclamation-circle-fill me-2"></i><div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="role-toggle mb-4 <?= $roleInvalid ? 'is-invalid' : '' ?>">
                        <button type="button" class="role-toggle-btn <?= $selected_role === 'resident' ? 'active' : '' ?>" data-role="resident">
                            <i class="bi bi-person-fill"></i> Resident
                        </button>
                        <button type="button" class="role-toggle-btn <?= $selected_role === 'admin' ? 'active' : '' ?>" data-role="admin">
                            <i class="bi bi-shield-lock-fill"></i> Admin
                        </button>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="selected_role" id="selectedRoleInput" value="<?= htmlspecialchars($selected_role) ?>">

                        <div class="mb-3">
                            <label class="form-label">Username, Email, or Phone Number</label>
                            <div class="auth-input-group <?= $usernameInvalid ? 'has-validation-error' : '' ?>">
                                <i class="bi bi-person auth-input-icon" aria-hidden="true"></i>
                                <input type="text" name="username" class="form-control auth-input <?= $usernameInvalid ? 'is-invalid' : '' ?>" autocomplete="username" placeholder="Username, email or contact" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= $usernameInvalid ? 'true' : 'false' ?>" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">Password</label>
                                <a href="forgot_password.php" class="small fw-semibold">Forgot?</a>
                            </div>
                            <div class="password-visibility-field password-visibility-field--inline auth-input-group <?= $passwordInvalid ? 'has-validation-error' : '' ?>">
                                <i class="bi bi-lock auth-input-icon" aria-hidden="true"></i>
                                <input type="password" name="password" id="passwordInput" class="form-control auth-input <?= $passwordInvalid ? 'is-invalid' : '' ?>" placeholder="Enter your password" aria-invalid="<?= $passwordInvalid ? 'true' : 'false' ?>" required>
                                <button type="button" class="password-visibility-toggle password-visibility-toggle--icon" data-password-target="passwordInput" data-password-label="password" aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Log In
                        </button>
                    </form>

                    <?php if ($showGoogle): ?>
                        <div class="d-flex align-items-center gap-3 my-4">
                            <hr class="flex-grow-1 m-0"><span class="small text-muted">or continue with</span><hr class="flex-grow-1 m-0">
                        </div>
                        <a href="oauth_start.php?provider=google" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-google me-2"></i> Continue with Google
                        </a>
                    <?php endif; ?>

                    <p class="text-center mt-4 mb-0 small text-muted">
                        Don't have an account? <a href="register.php" class="fw-semibold">Register Now.</a>
                    </p>
                    <p class="text-center mt-2 mb-0" style="font-size: 0.78rem;">
                        <a href="terms.php" class="text-secondary text-decoration-none">Terms and Conditions</a>
                    </p>
                </div>
            </div>

            <!-- RIGHT: Branding / Preview -->
            <div class="login-right-panel">
                <div class="login-hero-pill mb-3">
                    <i class="bi bi-shield-check"></i> Secure Subdivision Portal
                </div>
                <h2 class="fw-bold">Manage your subdivision, effortlessly.</h2>
                <p class="login-hero-subtitle">Access your dashboard to manage monthly dues, facility bookings, and resident communications in one unified platform.</p>

                <div class="login-preview-card mt-2">
                    <div class="login-preview-stats">
                        <div class="login-preview-stat">
                            <span class="label">Monthly Dues</span>
                            <div class="value text-emerald"><i class="bi bi-check-circle-fill me-1"></i>Up to date</div>
                        </div>
                        <div class="login-preview-stat">
                            <span class="label">Facilities</span>
                            <div class="value text-orange"><i class="bi bi-calendar-check me-1"></i>Clubhouse</div>
                        </div>
                    </div>
                    <div class="login-preview-donut-row">
                        <div class="login-preview-donut">
                            <div class="login-preview-donut-inner">100%</div>
                        </div>
                        <div class="login-preview-caption">
                            <span class="label">Payment Verification</span>
                            <span class="value">Official Receipts Issued</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        document.querySelectorAll('.role-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.role-toggle-btn').forEach(function (b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                document.getElementById('selectedRoleInput').value = this.getAttribute('data-role');
            });
        });
    </script>
<script src="Assets/js/password-visibility.js?v=1"></script>
</body>
</html>
