<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../config/paymongo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

$checkout_id = $_SESSION['paymongo_checkout_id'] ?? null;
$homeowner_id = $_SESSION['paymongo_homeowner_id'] ?? null;

if (!$checkout_id || !$homeowner_id) {
    header("Location: payments.php");
    exit;
}

// Verify the checkout session status directly with PayMongo
$ch = curl_init(PAYMONGO_API_URL . "/checkout_sessions/" . $checkout_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "accept: application/json",
    "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":")
]);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$payment_intent = $result['data']['attributes']['payment_intent']['attributes'] ?? [];
$payment_status = $payment_intent['status'] ?? null;
$is_paid = ($payment_status === 'succeeded');

// Extract the actual payment method used (gcash, paymaya, card)
$payment_method_raw = $payment_intent['payments'][0]['data']['attributes']['source']['type']
    ?? $payment_intent['payments'][0]['data']['attributes']['payment_method']['type']
    ?? 'online';

$method_labels = [
    'gcash' => 'GCash',
    'paymaya' => 'Maya',
    'card' => 'Credit/Debit Card',
];
$payment_method = $method_labels[$payment_method_raw] ?? ucfirst($payment_method_raw);

if ($is_paid) {
    $amount = $_SESSION['paymongo_amount'] ?? 0;
    $period = $_SESSION['paymongo_period'] ?? date('Y-m');
    $receipt_no = 'PM-' . strtoupper(substr($checkout_id, -8));
    $notes = 'Paid online via PayMongo (' . $payment_method . ')';

    try {
        $stmt = $conn->prepare("INSERT INTO payments (homeowner_id, amount_paid, payment_date, period_covered, receipt_no, payment_method, notes) VALUES (?, ?, NOW(), ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("idssss", $homeowner_id, $amount, $period, $receipt_no, $payment_method, $notes);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = "Payment successful via {$payment_method}! Your dues have been recorded.";
        } else {
            $_SESSION['flash_error'] = "Payment succeeded but recording failed: " . $conn->error;
        }
    } catch (Exception $e) {
        $_SESSION['flash_error'] = "Payment succeeded but recording failed: " . $e->getMessage();
    }

    unset($_SESSION['paymongo_checkout_id'], $_SESSION['paymongo_amount'], $_SESSION['paymongo_description'], $_SESSION['paymongo_period'], $_SESSION['paymongo_homeowner_id']);
} else {
    $_SESSION['flash_error'] = "Payment could not be verified. Please contact the admin if you were charged.";
}

header("Location: payments.php");
exit;