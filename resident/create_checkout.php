<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../config/paymongo.php';
require_once '../includes/payment_guard.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

// Email OTP required before paying
require_pay_verified('create_checkout.php');

$user_id = $_SESSION['user_id'];
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

if (!$homeowner) {
    $_SESSION['error_msg'] = "Your account is not yet linked to a homeowner record. Please contact the admin.";
    header("Location: payments.php");
    exit;
}

$requested_period = trim($_GET['period'] ?? '');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested_period)) {
    $period_covered = $requested_period;
} else {
    $period_covered = date('Y-m');
}
$period_label = date('F Y', strtotime($period_covered . '-01'));
$description = "Monthly Dues ({$period_label})";
$monthly_due = (float) (!empty($homeowner['monthly_due']) && $homeowner['monthly_due'] > 0 ? $homeowner['monthly_due'] : MONTHLY_DUES_AMOUNT);

$duesStartYm = homeowner_dues_start_ym($homeowner);
$currentPeriod = date('Y-m');
if ($period_covered < $duesStartYm || $period_covered > $currentPeriod) {
    $_SESSION['error_msg'] = $period_covered < $duesStartYm
        ? 'Your monthly dues begin in ' . date('F Y', strtotime($duesStartYm . '-01')) . '.'
        : 'Payment for a future month is not available yet.';
    header('Location: payments.php');
    exit;
}

// Don't create a checkout if this month is already paid
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) AS paid_amount FROM payments WHERE homeowner_id = ? AND period_covered = ? AND archived_at IS NULL");
$stmt->bind_param("is", $homeowner['id'], $period_covered);
$stmt->execute();
$paid_amount = (float) ($stmt->get_result()->fetch_assoc()['paid_amount'] ?? 0);
$stmt->close();

if ($paid_amount >= $monthly_due) {
    $_SESSION['flash_success'] = "Your dues for {$period_label} are already paid.";
    header("Location: payments.php");
    exit;
}

$amount_php = max(0, $monthly_due - $paid_amount);
$amount_centavos = (int) round($amount_php * 100);

$payload = [
    "data" => [
        "attributes" => [
            "billing" => [
                "name" => $homeowner['full_name'] ?? ($_SESSION['full_name'] ?? 'Resident'),
            ],
            "send_email_receipt" => false,
            "show_description" => true,
            "show_line_items" => true,
            "description" => $description,
            "line_items" => [
                [
                    "currency" => "PHP",
                    "amount" => $amount_centavos,
                    "name" => $description,
                    "quantity" => 1
                ]
            ],
            "payment_method_types" => ["gcash", "paymaya"],
            "success_url" => BASE_URL . "/resident/payment_success.php",
            "cancel_url" => BASE_URL . "/resident/payments.php",
            "metadata" => [
                "homeowner_id" => (string) $homeowner['id'],
                "period_covered" => $period_covered,
            ],
        ]
    ]
];

$ch = curl_init(PAYMONGO_API_URL . "/checkout_sessions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "accept: application/json",
    "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":")
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    $_SESSION['error_msg'] = "Connection error: " . $curl_error;
    header("Location: payments.php");
    exit;
}

$result = json_decode($response, true);

if ($http_code === 200 && isset($result['data']['attributes']['checkout_url'])) {
    $_SESSION['paymongo_checkout_id'] = $result['data']['id'];
    $_SESSION['paymongo_amount'] = $amount_php;
    $_SESSION['paymongo_description'] = $description;
    $_SESSION['paymongo_period'] = $period_covered;
    $_SESSION['paymongo_homeowner_id'] = $homeowner['id'];

    pay_consume();   // this verification is used up; the next payment needs a new code

    header("Location: " . $result['data']['attributes']['checkout_url']);
    exit;
} else {
    $error_detail = $result['errors'][0]['detail'] ?? 'Unknown error occurred.';
    $_SESSION['error_msg'] = "Failed to create checkout session: " . $error_detail;
    header("Location: payments.php");
    exit;
}
