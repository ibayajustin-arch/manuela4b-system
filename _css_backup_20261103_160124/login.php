<?php
require_once __DIR__ . '/config/init.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth_helpers.php';

$loginRole = isset($login_role) && $login_role === 'admin' ? 'admin' : 'resident';
$showGoogle = $loginRole === 'resident' && (bool) oauth_provider('google');
$error = $_SESSION['login_error'] ?? "";
unset($_SESSION['login_error']);
$credentialFailureField = null;
$ip = client_ip();
$ipKey = 'loginip:' . $ip;
$ipLockout = throttle_left($conn, $ipKey, 15, 15); // max 15 failed attempts per IP in 15 mins

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

                    if ($account_role !== $loginRole) {
                        $error = "This account is registered as " . ucfirst($account_role) . ". Please use the " . ucfirst($account_role) . " login.";
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

                    if ($account_role !== $loginRole) {
                        $error = "This account is registered as " . ucfirst($account_role) . ". Please use the " . ucfirst($account_role) . " login.";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $loginRole === 'admin' ? 'Admin Login' : 'Resident Login' ?> - Manuela 4B MIS</title>
    <link rel="icon" type="image/png" href="<?= BASE_PATH ?>/Assets/css/images/logo2.png">
    <meta name="theme-color" content="#0F4C3F">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/Assets/css/global.css?v=27" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/Assets/css/components.css?v=25" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/Assets/css/resident.css?v=26" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/Assets/css/admin.css?v=28" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/Assets/css/reference-theme.css?v=35" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0F6B5A;
            --primary-hover: #0B5748;
            --primary-active: #084238;
            --primary-soft: #E1F1EC;
            --primary-border: #9CCFC2;
            --brand-color: #0F6B5A;
            --brand-hover: #0B5748;
            --accent-color: #D97706;
            --accent-hover: #B45309;
            --accent-soft: #FEF3C7;
            --warning-color: #B45309;
            --bg-color: #E9F0EE;
            --surface-color: #FFFFFF;
            --surface-color-alt: #F3F7F6;
            --border-color: #8CC4B6;
            --border-color-soft: #CBE3DC;
            --text-main: #1F2E2A;
            --text-heading: #0F1F1B;
            --text-muted: #4A5C57;
            --text-subtle: #62756F;
            --shadow-lg: 0 12px 28px rgba(15, 60, 50, 0.14);
        }
        body.login-page {
            background-color: var(--bg-color) !important;
            color: var(--text-main);
            font-family: 'Inter', 'Poppins', sans-serif;
            min-height: 100vh;
            min-height: 100dvh;
        }
        .login-shell {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-color) !important;
            padding: clamp(16px, 3vw, 40px);
        }
        .login-shell-card {
            display: flex;
            width: 100%;
            max-width: 1280px;
            min-height: min(720px, calc(100vh - 48px));
            min-height: min(720px, calc(100dvh - 48px));
            background: #FFFFFF !important;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid var(--border-color) !important;
            box-shadow: var(--shadow-lg) !important;
        }
        .login-left-panel {
            flex: 1.1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: clamp(28px, 4vw, 48px);
            background: #FFFFFF !important;
        }
        .login-brand-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .login-logo-box {
            width: 44px;
            height: 44px;
            background: var(--primary-color) !important;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 0 !important;
            box-shadow: 0 3px 10px rgba(15, 107, 90, 0.3) !important;
            flex-shrink: 0;
        }
        .login-logo-box img {
            display: none !important;
        }
        .login-logo-box::after {
            content: "4B";
            font-weight: 800;
            font-size: 1.15rem;
            color: #FFFFFF;
            line-height: 1;
        }
        .login-brand-row .brand-name {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-heading) !important;
            line-height: 1.2;
        }
        .login-brand-row .brand-tag {
            font-size: .875rem;
            font-weight: 600;
            color: var(--text-muted) !important;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .login-left-inner {
            max-width: 400px;
            margin: 0 auto;
            width: 100%;
        }
        .login-left-inner h1 {
            font-size: 1.9rem;
            font-weight: 800;
            color: var(--text-heading) !important;
            margin-bottom: 6px;
        }
        .login-left-inner .login-subtitle {
            color: var(--text-muted) !important;
            font-size: 1rem;
            margin-bottom: 24px;
        }
        .login-left-inner .form-label {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-heading) !important;
        }
        .login-left-inner .auth-input-group {
            display: flex;
            align-items: center;
            position: relative;
            width: 100%;
        }
        .login-left-inner .auth-input-group > .auth-input-icon {
            position: absolute;
            z-index: 2;
            left: 1rem;
            width: 1rem;
            color: var(--text-subtle);
            font-size: 1rem;
            line-height: 1;
            pointer-events: none;
        }
        .login-left-inner .auth-input-group > .auth-input {
            width: 100%;
            min-width: 0;
            height: 50px;
            padding: .5rem 3.25rem .5rem 2.85rem;
            border: 1.5px solid #9DB0AA !important;
            border-radius: 10px;
            outline: 0;
            background-color: #FFFFFF !important;
            color: var(--text-heading) !important;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .login-left-inner .auth-input-group > .auth-input::placeholder {
            color: #6B7D77 !important;
        }
        .login-left-inner .auth-input-group > .auth-input:hover,
        .login-left-inner .auth-input-group > .auth-input:focus {
            border-color: var(--primary-color) !important;
            background-color: #FFFFFF !important;
            color: var(--text-heading) !important;
            box-shadow: 0 0 0 4px rgba(15, 107, 90, 0.2) !important;
        }
        .login-left-inner a {
            color: var(--primary-color) !important;
            font-weight: 600;
            text-decoration: none;
        }
        .login-left-inner a:hover {
            color: var(--primary-active) !important;
            text-decoration: underline;
        }
        .login-left-inner .btn-primary {
            min-height: 52px;
            background: var(--primary-color) !important;
            border: 1px solid var(--primary-hover) !important;
            border-radius: 12px !important;
            font-size: 1.05rem !important;
            font-weight: 700 !important;
            color: #FFFFFF !important;
            box-shadow: 0 4px 14px rgba(15, 107, 90, 0.3) !important;
        }
        .login-left-inner .btn-primary:hover {
            background: var(--primary-hover) !important;
            transform: translateY(-2px);
        }
        .login-left-inner .btn-outline-secondary {
            min-height: 48px;
            background: #FFFFFF !important;
            border: 1.5px solid #9DB0AA !important;
            color: var(--text-main) !important;
            font-weight: 600;
            border-radius: 12px;
        }
        .login-left-inner .btn-outline-secondary:hover {
            background: var(--surface-color-alt) !important;
            border-color: var(--primary-color) !important;
            color: var(--text-heading) !important;
            transform: translateY(-1px);
        }
        .login-right-panel {
            flex: 1;
            position: relative;
            overflow: hidden;
            background: radial-gradient(circle at 85% 15%, rgba(255, 255, 255, 0.12) 0%, transparent 45%), linear-gradient(150deg, #0F4C3F 0%, #0F6B5A 100%) !important;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: clamp(32px, 4.5vw, 60px);
            color: #FFFFFF;
            text-align: center;
        }
        .login-hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #FFFFFF;
            font-size: .9rem;
            font-weight: 600;
            letter-spacing: .04em;
        }
        .login-hero-pill i {
            color: #FCD34D;
        }
        .login-right-panel h2 {
            color: #FFFFFF !important;
            font-size: clamp(1.6rem, 2.3vw, 2.1rem);
            font-weight: 800;
            line-height: 1.25;
            max-width: 420px;
            margin-bottom: 12px;
        }
        .login-right-panel .login-hero-subtitle {
            color: #D5EAE3 !important;
            font-size: 1rem;
            line-height: 1.6;
            max-width: 400px;
            margin-bottom: 24px;
        }
        .login-preview-card {
            position: relative;
            z-index: 1;
            background: #FFFFFF;
            border: 0;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.28);
            max-width: 380px;
            width: 100%;
            text-align: left;
        }
        .login-preview-stats {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }
        .login-preview-stat {
            flex: 1;
            background: var(--surface-color-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 10px 12px;
        }
        .login-preview-stat .label {
            font-size: .875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 600;
            display: block;
            margin-bottom: 2px;
        }
        .login-preview-stat .value {
            font-size: .95rem;
            font-weight: 700;
            color: var(--text-heading);
        }
        .login-preview-stat .text-emerald {
            color: var(--primary-color) !important;
        }
        .login-preview-stat .text-orange {
            color: var(--warning-color) !important;
        }
        .login-preview-donut-row {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--surface-color-alt);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 12px 14px;
        }
        .login-preview-donut {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            border-radius: 50%;
            background: conic-gradient(var(--primary-color) 0% 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-preview-donut-inner {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .875rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .login-preview-caption .label {
            font-size: .875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 600;
        }
        .login-preview-caption .value {
            font-size: .95rem;
            font-weight: 600;
            color: var(--text-heading);
        }
        @media (max-width: 900px) {
            .login-right-panel {
                display: none !important;
            }
            .login-shell-card {
                max-width: 600px;
                min-height: 0;
            }
        }
        @media (max-width: 767.98px) {
            .login-shell {
                box-sizing: border-box;
                min-height: 100vh;
                min-height: 100dvh;
                align-items: flex-start;
                padding: max(16px, env(safe-area-inset-top)) 8px max(16px, env(safe-area-inset-bottom));
            }
            .login-shell-card {
                display: block;
                width: 100%;
                max-width: 520px;
                min-height: 0;
                margin: auto;
                border-radius: 20px;
                box-shadow: none;
                border: 1px solid var(--border-color) !important;
            }
            .login-left-panel {
                justify-content: flex-start;
                padding: 22px clamp(16px, 5vw, 24px);
            }
            .login-brand-row {
                gap: 10px;
                margin-bottom: clamp(24px, 5vh, 38px);
            }
            .login-logo-box {
                width: 40px;
                height: 40px;
                border-radius: 11px;
            }
            .login-brand-row .brand-name { font-size: 1.05rem; }
            .login-brand-row .brand-tag { font-size: .68rem; }
            .login-left-inner { max-width: none; }
            .login-left-inner h1 {
                margin-bottom: 1rem;
                font-size: clamp(1.45rem, 6.5vw, 1.75rem);
                line-height: 1.2;
            }
            .login-left-inner .form-label { font-size: .88rem; }
            .login-left-inner form > .mb-3 { margin-bottom: .85rem !important; }
            .login-left-inner form > .mb-4 { margin-bottom: 1rem !important; }
            .login-left-inner .auth-input-group > .auth-input {
                height: 52px;
                min-height: 52px;
                padding-left: 2.55rem;
                padding-right: 3rem;
                font-size: .92rem;
            }
            body.login-page .login-left-inner .auth-input-group > .auth-input {
                min-height: 52px;
                padding: .5rem 3rem .5rem 2.55rem;
                font-size: .92rem;
            }
            .login-left-inner .auth-input-group > .auth-input-icon { left: .85rem; }
            .login-left-inner .password-visibility-toggle--icon {
                top: 50%;
                right: .45rem;
                display: inline-flex;
                width: 2.5rem;
                height: 2.5rem;
                min-width: 2.5rem;
                min-height: 2.5rem;
                align-items: center;
                justify-content: center;
                padding: 0;
                line-height: 1;
                transform: translateY(-50%);
            }
            .login-left-inner .password-visibility-toggle--icon i {
                display: block;
                line-height: 1;
            }
            .login-left-inner .btn-primary {
                min-height: 52px;
                font-size: .95rem !important;
            }
            .login-left-inner .small { font-size: .8rem; }
            .login-left-inner > p { margin-top: 1rem !important; }
            .login-left-inner > p:last-child { margin-top: .5rem !important; }
            .login-left-inner .alert {
                padding: .7rem .8rem;
                font-size: .85rem;
            }
        }
    </style>
</head>
<body class="login-page">
    <div class="login-shell">
        <div class="login-shell-card">

            <!-- LEFT: Form -->
            <div class="login-left-panel">
                <div class="login-brand-row">
                    <div class="login-logo-box">
                        <img src="<?= BASE_PATH ?>/Assets/css/images/logo2.png" alt="Manuela 4B MIS Logo">
                    </div>
                    <div>
                        <span class="brand-name d-block">Manuela 4B</span>
                        <span class="brand-tag d-block">HOA Management</span>
                    </div>
                </div>

                <div class="login-left-inner">
                    <h1 class="fw-bold"><?= $loginRole === 'admin' ? 'Administrator Login' : 'Resident Login' ?></h1>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-3">
                            <i class="bi bi-exclamation-circle-fill me-2"></i><div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                        <div class="mb-3">
                            <label class="form-label"><?= $loginRole === 'admin' ? 'Administrator username, email, or phone number' : 'Username, email, or phone number' ?></label>
                            <div class="auth-input-group <?= $usernameInvalid ? 'has-validation-error' : '' ?>">
                                <i class="bi bi-person auth-input-icon" aria-hidden="true"></i>
                                <input type="text" name="username" class="form-control auth-input <?= $usernameInvalid ? 'is-invalid' : '' ?>" autocomplete="username" placeholder="Username, email or contact" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-invalid="<?= $usernameInvalid ? 'true' : 'false' ?>" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">Password</label>
                                <a href="<?= BASE_PATH ?>/forgot_password.php" class="small fw-semibold">Forgot password?</a>
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
                        <a href="<?= BASE_PATH ?>/oauth_start.php?provider=google" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-google me-2"></i> Continue with Google
                        </a>
                    <?php endif; ?>

                    <?php if ($loginRole === 'resident'): ?>
                        <p class="text-center mt-4 mb-0 small text-muted">
                            Don't have an account? <a href="<?= BASE_PATH ?>/register.php" class="fw-semibold">Register Now.</a>
                        </p>
                        <p class="text-center mt-2 mb-0" style="font-size: .9rem;">
                            <a href="<?= BASE_PATH ?>/terms.php" class="text-secondary text-decoration-none">Terms and Conditions</a>
                        </p>
                        <p class="text-center mt-3 mb-0">Administrator? <a href="<?= BASE_PATH ?>/admin/login.php" class="fw-semibold">Admin login</a></p>
                    <?php else: ?>
                        <p class="text-center mt-4 mb-0">Resident? <a href="<?= BASE_PATH ?>/login.php" class="fw-semibold">Resident login</a></p>
                    <?php endif; ?>
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

<script src="<?= BASE_PATH ?>/Assets/js/password-visibility.js?v=1"></script>
</body>
</html>
