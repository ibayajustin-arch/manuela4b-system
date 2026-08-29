<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../config/paymongo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

// Find the homeowner record linked to this login account
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

$amount_php = (float) $homeowner['monthly_due'];
$period_label = date('F Y');
$description = "Monthly Dues ({$period_label})";

// PayMongo requires amount in centavos (smallest currency unit)
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
            "payment_method_types" => ["gcash", "paymaya", "card"],
            "success_url" => "http://localhost/MANUELA/resident/payment_success.php",
            "cancel_url" => "http://localhost/MANUELA/resident/payments.php",
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
    $_SESSION['paymongo_period'] = date('Y-m');
    $_SESSION['paymongo_homeowner_id'] = $homeowner['id'];

    header("Location: " . $result['data']['attributes']['checkout_url']);
    exit;
} else {
    $error_detail = $result['errors'][0]['detail'] ?? 'Unknown error occurred.';
    $_SESSION['error_msg'] = "Failed to create checkout session: " . $error_detail;
    header("Location: payments.php");
    exit;
}