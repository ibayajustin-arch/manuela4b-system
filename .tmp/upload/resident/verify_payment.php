<?php
// resident/verify_payment.php  (email OTP before a payment)
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/otp.php';
require_once '../includes/payment_guard.php';

header('Referrer-Policy: no-referrer');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header("Location: ../login.php");
    exit;
}
$uid = (int) $_SESSION['user_id'];

// Where to go after verifying (whitelisted)
$fresh = false;
if (isset($_GET['next']) && pay_next_allowed($_GET['next'])) {
    $_SESSION['pay_next'] = $_GET['next'];
    $fresh = true;
}
$next = $_SESSION['pay_next'] ?? 'payments.php';

if (pay_verified()) {
    unset($_SESSION['pay_next']);
    header("Location: " . $next);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$email = $user ? get_user_email($conn, $uid) : '';
$masked = '';
if ($email !== '') {
    [$local, $domain] = explode('@', $email, 2);
    $masked = substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 2)) . '@' . $domain;
}

$error = '';
$info  = '';

function pay_send($conn, $user, $uid, &$info, &$error) {
    if (throttle_left($conn, 'payotp:' . $uid, 5, 15) > 0) {
        $error = 'Too many code requests. Please try again in a few minutes.';
        return;
    }
    $wait = otp_cooldown_left($conn, $uid, 'payment');
    if ($wait > 0) {
        $info = "A code was sent recently. You can request a new one in {$wait} seconds.";
        return;
    }
    throttle_hit($conn, 'payotp:' . $uid);
    $code = otp_create($conn, $uid, 'payment');
    if (otp_deliver($conn, $user, $code, 'payment')) {
        $info = 'We sent a 6-digit code to your email.';
    } else {
        $error = 'We could not send the code. Please contact the admin to check your email address.';
    }
}

if ($email === '') {
    $error = 'There is no email address on your account, so we cannot send a code. Please ask the admin to add your email.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        pay_send($conn, $user, $uid, $info, $error);
    } else {
        $code = trim((string) ($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Please enter the 6-digit code.';
        } else {
            $r = otp_verify($conn, $uid, 'payment', $code);
            if ($r === true) {
                session_regenerate_id(true);
                $_SESSION['pay_verified_until'] = time() + 600;   // 10 minutes to start the payment
                unset($_SESSION['pay_next']);
                header("Location: " . $next);
                exit;
            }
            $error = $r === 'locked' ? 'Too many wrong attempts. Please request a new code.'
                   : ($r === 'none'  ? 'That code expired. Please request a new one.'
                                     : 'Incorrect code. Please try again.');
        }
    }
} elseif ($fresh) {
    // Arrived from a Pay / Book button: send the code automatically
    pay_send($conn, $user, $uid, $info, $error);
}

$csrf = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Payment - Manuela 4B MIS</title>
    <link rel="icon" type="image/png" href="../Assets/css/images/logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../Assets/css/global.css?v=21" rel="stylesheet">
    <link href="../Assets/css/components.css?v=22" rel="stylesheet">
    <link href="../Assets/css/resident.css?v=23" rel="stylesheet">
    <link href="../Assets/css/reference-theme.css?v=25" rel="stylesheet">
    <link href="../Assets/css/ui-refresh.css?v=1" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card">
    <div class="text-center mb-4">
        <img src="../Assets/css/images/logo2.png" alt="Manuela 4B Logo" class="login-logo mb-3">
        <h1 class="h4 fw-bold mb-1">Payment Verification</h1>
        <p class="login-subtitle mb-0">
            For security, enter the 6-digit code emailed
            <?= $masked ? 'to <strong>' . htmlspecialchars($masked, ENT_QUOTES, 'UTF-8') . '</strong>' : '' ?>.
        </p>
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

    <?php if ($email !== ''): ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-4">
            <label class="form-label text-center d-block">6-Digit Code</label>
            <input type="text" name="code" class="form-control text-center fw-bold fs-3"
                   style="letter-spacing: 8px;"
                   inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="••••••" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-shield-check me-1"></i> Verify &amp; Continue
        </button>
    </form>

    <div class="text-center mt-3">
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn btn-link btn-sm text-decoration-none">Didn't get it? Send a new code</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="text-center mt-3 pt-2">
        <a href="<?= BASE_PATH ?>/resident/payments.php" class="small fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Return to Payments
        </a>
    </div>
</div>
</body>
</html>
