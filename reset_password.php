<?php
// reset_password.php  (step 3: choose a new password after the code was verified)
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/auth_helpers.php';

header('Referrer-Policy: no-referrer');

$uid   = (int) ($_SESSION['reset_uid'] ?? 0);
$valid = $uid > 0 && time() < (int) ($_SESSION['reset_until'] ?? 0);
$error = '';

if (!$valid) {
    unset($_SESSION['reset_uid'], $_SESSION['reset_until']);
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['confirm'] ?? '');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $new = password_hash($pw, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $new, $uid);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            $error = 'Something went wrong. Please try again.';
        } else {
            // Clean up: unused codes, old reset links, lockout counters
            $stmt = $conn->prepare("DELETE FROM otp_codes WHERE user_id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $urow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($urow) login_clear_failures($conn, strtolower($urow['username']));

            unset($_SESSION['reset_uid'], $_SESSION['reset_until']);
            session_regenerate_id(true);
            $_SESSION['login_success'] = 'Your password was changed. You can now log in.';
            header("Location: " . BASE_PATH . "/login.php");
            exit;
        }
    }
}
$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - Manuela 4B MIS</title>
    <link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css?v=22" rel="stylesheet">
    <link href="Assets/css/components.css?v=21" rel="stylesheet">
    <link href="Assets/css/admin.css?v=27" rel="stylesheet">
    <link href="Assets/css/resident.css?v=23" rel="stylesheet">
    <link href="Assets/css/reference-theme.css?v=50" rel="stylesheet">
    <link href="Assets/css/responsive-fix.css?v=2" rel="stylesheet">
    <link href="Assets/css/fluid-layout.css?v=1" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card">
    <div class="text-center mb-4">
        <img src="Assets/css/images/logo2.png" alt="Manuela 4B Logo" class="login-logo mb-3">
        <?php if (!$valid): ?>
            <h1 class="h4 fw-bold mb-1">Session Expired</h1>
            <p class="login-subtitle mb-0">Please verify your code again to set a new password.</p>
        <?php else: ?>
            <h1 class="h4 fw-bold mb-1">Set New Password</h1>
            <p class="login-subtitle mb-0">Create a secure password with at least 8 characters.</p>
        <?php endif; ?>
    </div>

    <?php if (!$valid): ?>
        <a href="<?= BASE_PATH ?>/forgot_password.php" class="btn btn-primary w-100">
            <i class="bi bi-arrow-repeat me-1"></i> Start Again
        </a>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center mb-3">
                <i class="bi bi-exclamation-circle-fill me-2"></i>
                <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3 text-start">
                <label class="form-label" for="password">New Password</label>
                <div class="password-visibility-field"><input type="text" id="password" name="password" class="form-control" minlength="8" required autofocus autocomplete="new-password" placeholder="At least 8 characters"><button type="button" class="password-visibility-toggle" data-password-target="password" data-password-label="new password" aria-label="Hide new password" aria-pressed="true"><i class="bi bi-eye-slash-fill" aria-hidden="true"></i><span>Hide</span></button></div>
            </div>

            <div class="mb-4 text-start">
                <label class="form-label" for="confirm">Confirm New Password</label>
                <div class="password-visibility-field"><input type="text" id="confirm" name="confirm" class="form-control" minlength="8" required autocomplete="new-password" placeholder="Repeat your new password"><button type="button" class="password-visibility-toggle" data-password-target="confirm" data-password-label="confirmation password" aria-label="Hide confirmation password" aria-pressed="true"><i class="bi bi-eye-slash-fill" aria-hidden="true"></i><span>Hide</span></button></div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-key-fill me-1"></i> Change Password
            </button>
        </form>
    <?php endif; ?>

    <div class="text-center mt-4 pt-2">
        <a href="<?= BASE_PATH ?>/login.php" class="small fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Back to Login
        </a>
    </div>
</div>
<script src="Assets/js/password-visibility.js?v=1"></script>
</body>
</html>
