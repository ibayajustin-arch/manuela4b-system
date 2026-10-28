<?php
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/otp.php';

header('Referrer-Policy: no-referrer');

// Must come from forgot_password.php (and not be older than 30 minutes)
if (!isset($_SESSION['fp_uids']) || !is_array($_SESSION['fp_uids'])
    || (time() - (int) ($_SESSION['fp_time'] ?? 0)) > 1800) {
    unset($_SESSION['fp_uids'], $_SESSION['fp_raw'], $_SESSION['fp_time']);
    header("Location: " . BASE_PATH . "/forgot_password.php");
    exit;
}

$error = '';
$info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';

    } elseif (($_POST['action'] ?? '') === 'resend') {
        $raw   = (string) ($_SESSION['fp_raw'] ?? '');
        $idKey = 'reset:' . login_identifier($raw);
        $ipKey = 'resetip:' . client_ip();

        if (throttle_left($conn, $idKey, 3, 15) > 0 || throttle_left($conn, $ipKey, 10, 15) > 0) {
            $error = 'Too many requests. Please try again in a few minutes.';
        } else {
            throttle_hit($conn, $idKey);
            throttle_hit($conn, $ipKey);
            $_SESSION['fp_uids'] = otp_request_for_identifier($conn, $raw, 'reset');
            $_SESSION['fp_time'] = time();
            $info = 'If the account exists, a new code was sent. (You can request a new code once every 60 seconds.)';
        }

    } else {
        $code  = trim((string) ($_POST['code'] ?? ''));
        $ipKey = 'otpip:' . client_ip();

        if (throttle_left($conn, $ipKey, 10, 15) > 0) {
            $error = 'Too many wrong codes. Please try again in a few minutes.';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Please enter the 6-digit code.';
        } else {
            $matched = 0;
            foreach ($_SESSION['fp_uids'] as $uid) {
                if (otp_verify($conn, (int) $uid, 'reset', $code) === true) {
                    $matched = (int) $uid;
                    break;
                }
            }

            if ($matched > 0) {
                throttle_clear($conn, $ipKey);
                session_regenerate_id(true);
                unset($_SESSION['fp_uids'], $_SESSION['fp_raw'], $_SESSION['fp_time']);
                $_SESSION['reset_uid']   = $matched;
                $_SESSION['reset_until'] = time() + 600;   // 10 minutes to choose a new password
                header("Location: " . BASE_PATH . "/reset_password.php");
                exit;
            }

            throttle_hit($conn, $ipKey);
            $error = 'Incorrect or expired code. You can request a new one below.';
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
    <title>Enter Code - Manuela 4B MIS</title>
    <link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css?v=21" rel="stylesheet">
    <link href="Assets/css/components.css?v=22" rel="stylesheet">
    <link href="Assets/css/resident.css?v=23" rel="stylesheet">
    <link href="Assets/css/reference-theme.css?v=25" rel="stylesheet">
    <link href="Assets/css/ui-refresh.css?v=1" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card">
    <div class="text-center mb-4">
        <img src="Assets/css/images/logo2.png" alt="Manuela 4B Logo" class="login-logo mb-3">
        <h1 class="h4 fw-bold mb-1">Enter Verification Code</h1>
        <p class="login-subtitle mb-0">We sent a 6-digit code to your registered contact. The code expires in 10 minutes.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center mb-3">
            <i class="bi bi-exclamation-circle-fill me-2"></i>
            <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>
    <?php if ($info): ?>
        <div class="alert alert-success d-flex align-items-center mb-3">
            <i class="bi bi-check-circle-fill me-2"></i>
            <div><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-4">
            <label class="form-label text-center d-block">6-Digit Code</label>
            <input type="text" name="code" class="form-control text-center fw-bold fs-3"
                   style="letter-spacing: 8px;"
                   inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="••••••" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-shield-check me-1"></i> Verify Code
        </button>
    </form>

    <div class="text-center mt-3">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn btn-link btn-sm text-decoration-none">Didn't get it? Send a new code</button>
        </form>
    </div>
    <div class="text-center mt-2">
        <a href="<?= BASE_PATH ?>/login.php" class="small fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Back to Login
        </a>
    </div>
</div>
</body>
</html>
