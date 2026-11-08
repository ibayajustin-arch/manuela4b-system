<?php
// forgot_password.php  (step 1: ask for email, send the code)
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/otp.php';

header('Referrer-Policy: no-referrer');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $idKey = 'reset:' . $email;
            $ipKey = 'resetip:' . client_ip();

            if (throttle_left($conn, $idKey, 3, 15) > 0 || throttle_left($conn, $ipKey, 10, 15) > 0) {
                $error = 'Too many requests. Please try again in a few minutes.';
            } else {
                throttle_hit($conn, $idKey);
                throttle_hit($conn, $ipKey);

                $uids = otp_request_for_identifier($conn, $email, 'reset');

                // Same result whether or not an account exists
                $_SESSION['fp_uids'] = $uids;
                $_SESSION['fp_raw']  = $email;
                $_SESSION['fp_time'] = time();
                header("Location: " . BASE_PATH . "/verify_otp.php");
                exit;
            }
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
    <title>Forgot Password - Manuela 4B MIS</title>
    <link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css?v=22" rel="stylesheet">
    <link href="Assets/css/components.css" rel="stylesheet">
    <link href="Assets/css/resident.css" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card">
    <div class="text-center mb-4">
        <img src="Assets/css/images/logo2.png" alt="Manuela 4B Logo" class="login-logo mb-3">
        <h1 class="h4 fw-bold mb-1">Forgot your password?</h1>
        <p class="login-subtitle mb-0">Enter your account email. We'll send a 6-digit code to verify your identity.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center mb-3">
            <i class="bi bi-exclamation-circle-fill me-2"></i>
            <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-3">
            <label class="form-label" for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control" required autofocus maxlength="100" placeholder="name@example.com">
        </div>
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-send-fill me-1"></i> Send Verification Code
        </button>
    </form>
    <div class="text-center mt-4 pt-2">
        <a href="<?= BASE_PATH ?>/login.php" class="fw-semibold small">
            <i class="bi bi-arrow-left me-1"></i> Back to Login
        </a>
    </div>
</div>
</body>
</html>
