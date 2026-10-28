<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../PHPMailer/Exception.php';
require __DIR__ . '/../PHPMailer/PHPMailer.php';
require __DIR__ . '/../PHPMailer/SMTP.php';

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/homeowner_helpers.php';
require_once __DIR__ . '/../includes/archive_helpers.php';

header('Content-Type: application/json');

function respond($status, $message, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(
        ['status' => $status, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    respond('error', 'Not authorized.', 403);
}

try {
    ensure_archive_columns($conn, 'homeowners');
} catch (Throwable $e) {
    error_log('Reminder homeowner archive check failed: ' . $e->getMessage());
    respond('error', 'Homeowner records are temporarily unavailable.', 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'Invalid request.', 405);
}

if (empty($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null)
    || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    respond('error', 'Your session expired. Please refresh the page.', 403);
}

try {
    $mailCfg = require __DIR__ . '/../includes/mail.php';
    if (!is_array($mailCfg)) {
        respond('error', 'Email service is not configured.', 500);
    }

    foreach (['host', 'username', 'password', 'from_name'] as $key) {
        if (!isset($mailCfg[$key]) || !is_string($mailCfg[$key]) || trim($mailCfg[$key]) === '') {
            respond('error', 'Email service is not configured.', 500);
        }
    }
    $mailPort = filter_var($mailCfg['port'] ?? null, FILTER_VALIDATE_INT);
    if ($mailPort === false || $mailPort < 1 || $mailPort > 65535) {
        respond('error', 'Email service is not configured.', 500);
    }

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$email_address = '';
$homeowner_name = '';
$subject = '';
$htmlBody = '';
$altBody = '';
$logHomeowner = 0;

/* ------------------------------------------------------------
   MODE 1: per homeowner (all unpaid months)  -> homeowner_id
   ------------------------------------------------------------ */
if (isset($_POST['homeowner_id'])) {
    $hid = filter_var($_POST['homeowner_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($hid === false) respond('error', 'Invalid homeowner.', 400);

    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id = ? AND archived_at IS NULL");
    if (!$stmt) throw new RuntimeException('Unable to prepare homeowner lookup.');
    $stmt->bind_param("i", $hid);
    $stmt->execute();
    $h = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$h) respond('error', 'Homeowner not found.', 404);
    if (strtolower((string) ($h['status'] ?? '')) !== 'active') {
        respond('error', 'Only active homeowners can receive dues reminders.', 400);
    }
    if (empty($h['email'])) respond('error', 'No email address saved for this homeowner.', 400);
    if (!filter_var($h['email'], FILTER_VALIDATE_EMAIL)) {
        respond('error', 'The homeowner email address is invalid.', 400);
    }

    $sum = homeowner_account_summary($conn, $h);
    if ($sum['unpaid_count'] < 1) respond('error', 'This homeowner has no unpaid months.', 400);

    $labels = array_map(fn($ym) => date('F Y', strtotime($ym . '-01')), $sum['unpaid']);
    $total = number_format($sum['outstanding'], 2);

    $email_address = $h['email'];
    $homeowner_name = $h['full_name'];
    $logHomeowner = $hid;
    $subject = "Payment Reminder - Manuela 4B HOA";

    $items = '';
    foreach ($labels as $l) $items .= '<li>' . $e($l) . '</li>';

    $htmlBody = "
        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
            <h2>Payment Reminder</h2>
            <p>Hello <b>" . $e($homeowner_name) . "</b>,</p>
            <p>This is a friendly reminder that our records show unpaid monthly dues for:</p>
            <ul>$items</ul>
            <p>Total outstanding: <b style='color: #dc2626;'>PHP $total</b></p>
            <p>Please settle this at your earliest convenience.</p>
            <br>
            <p>Thank you,<br><b>Manuela 4B Admin</b></p>
        </div>";
    $altBody = "Hello $homeowner_name, our records show unpaid monthly dues for: " . implode(', ', $labels)
             . ". Total outstanding: PHP $total. Please settle at your earliest convenience. Thank you, Manuela 4B Admin.";

/* ------------------------------------------------------------
   MODE 2: legacy, single payment record -> payment_id
   ------------------------------------------------------------ */
} elseif (isset($_POST['payment_id'])) {
    $payment_id = filter_var($_POST['payment_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($payment_id === false) respond('error', 'Invalid payment record.', 400);

    $stmt = $conn->prepare("SELECT p.amount_paid, p.period_covered, p.homeowner_id, p.payment_date, h.full_name, h.email
                            FROM payments p
                            LEFT JOIN homeowners h ON p.homeowner_id = h.id
                            WHERE p.id = ?");
    if (!$stmt) throw new RuntimeException('Unable to prepare payment lookup.');
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) respond('error', 'Payment record not found.', 404);
    $paymentDate = trim((string) ($data['payment_date'] ?? ''));
    if ($paymentDate !== '' && substr($paymentDate, 0, 10) !== '0000-00-00') {
        respond('error', 'This payment is already marked as paid.', 400);
    }
    if (empty($data['email'])) respond('error', 'No email address saved for this homeowner.', 400);
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        respond('error', 'The homeowner email address is invalid.', 400);
    }

    $email_address = $data['email'];
    $homeowner_name = $data['full_name'];
    $logHomeowner = (int) $data['homeowner_id'];
    $amount = number_format($data['amount_paid'], 2);
    $period = $data['period_covered'];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        respond('error', 'The payment period is invalid.', 400);
    }
    $subject = "Overdue Payment Reminder: $period";

    $htmlBody = "
        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
            <h2>Payment Reminder</h2>
            <p>Hello <b>" . $e($homeowner_name) . "</b>,</p>
            <p>This is a friendly reminder that your payment for <b>" . $e($period) . "</b> amounting to <b style='color: #dc2626;'>P$amount</b> is currently overdue.</p>
            <p>Please settle this as soon as possible.</p>
            <br>
            <p>Thank you,<br><b>Manuela 4B Admin</b></p>
        </div>";
    $altBody = "Hello $homeowner_name, your payment for $period amounting to P$amount is overdue. Please settle as soon as possible. Thank you, Manuela 4B Admin.";

} else {
    respond('error', 'No homeowner or payment ID provided.');
}

/* ------------------------------------------------------------
   Send
   ------------------------------------------------------------ */
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = $mailCfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $mailCfg['username'];
    $mail->Password   = $mailCfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $mailPort;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 20;

    $mail->setFrom($mailCfg['username'], $mailCfg['from_name']);
    $mail->addAddress($email_address, $homeowner_name);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = $altBody;

    $mail->send();

    if ($logHomeowner > 0) {
        log_activity($conn, $logHomeowner, 'Sent payment reminder', "Emailed to $email_address");
    }

    respond('success', 'Email reminder sent successfully to ' . $email_address);
} catch (Exception $ex) {
    error_log('Payment reminder mail failed: ' . $mail->ErrorInfo);
    respond('error', 'The reminder email could not be sent. Please try again later.', 500);
}
} catch (Throwable $ex) {
    error_log('Payment reminder request failed: ' . $ex->getMessage());
    respond('error', 'The reminder request could not be processed. Please try again later.', 500);
}