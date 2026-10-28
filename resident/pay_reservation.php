<?php
// resident/pay_reservation.php?id=123  (pay for an existing unpaid reservation)
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../config/paymongo.php';
require_once '../includes/payment_guard.php';
require_once '../includes/availability.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

$rid = (int) ($_GET['id'] ?? 0);
if ($rid <= 0) {
    header("Location: reservations.php");
    exit;
}

// Email OTP required before paying
require_pay_verified('pay_reservation.php?id=' . $rid);

function pr_back($msg) {
    $_SESSION['error_msg'] = $msg;
    header("Location: reservations.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Must be THIS resident's reservation
$stmt = $conn->prepare("SELECT * FROM reservations WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param("ii", $rid, $user_id);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$r) pr_back("Reservation not found.");

// The slot may have been taken by someone else (e.g. the 30-minute hold expired)
if (($r['status'] ?? '') !== 'approved' && ($r['payment_status'] ?? '') !== 'paid'
    && reservation_conflict($conn, $r['facility'], $r['res_date'], $r['start_time'], $r['end_time'], $rid)) {
    pr_back("Sorry, that time slot was taken by another reservation. Please book a different time.");
}

if (($r['payment_status'] ?? '') === 'paid') pr_back("This reservation is already paid.");
if (($r['status'] ?? '') === 'rejected') pr_back("This reservation was rejected, so no payment is needed.");

$amount = (float) ($r['payment_amount'] ?? 0);
if ($amount <= 0) pr_back("There is no amount to pay for this reservation.");

// Existing payment record (created when the reservation was booked)
$stmt = $conn->prepare("SELECT id, reference_number FROM reservation_payments WHERE reservation_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $rid);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pay) {
    $ref = 'RES-' . date('YmdHis') . '-' . $user_id . '-' . random_int(100, 999);
    $st  = 'pending';
    $stmt = $conn->prepare("INSERT INTO reservation_payments (reservation_id, amount, payment_status, reference_number) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("idss", $rid, $amount, $st, $ref);
    $stmt->execute();
    $pay = ['id' => $conn->insert_id, 'reference_number' => $ref];
    $stmt->close();
}

$facility = $r['facility'];
$payload = [
    'data' => [
        'attributes' => [
            'line_items' => [[
                'name'        => $facility . ' Reservation',
                'amount'      => (int) round($amount * 100),
                'currency'    => 'PHP',
                'quantity'    => 1,
                'description' => $facility . ' reservation on ' . date('M d, Y', strtotime($r['res_date']))
                               . ' (' . date('h:i A', strtotime($r['start_time'])) . ' - ' . date('h:i A', strtotime($r['end_time'])) . ')'
            ]],
            'payment_method_types' => ['gcash', 'paymaya'],
            'success_url'      => BASE_URL . '/resident/payment_success.php?reservation_id=' . urlencode((string) $rid),
            'cancel_url'       => BASE_URL . '/resident/payment_cancel.php?reservation_id=' . urlencode((string) $rid),
            'reference_number' => $pay['reference_number'],
            'description'      => 'Facility Reservation #' . $rid,
            'show_description' => true,
            'show_line_items'  => true,
            'metadata' => [
                'reservation_id'    => (string) $rid,
                'payment_record_id' => (string) $pay['id'],
                'user_id'           => (string) $user_id,
                'facility'          => $facility
            ]
        ]
    ]
];

$ch = curl_init(PAYMONGO_API_URL . '/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($response === false || $curl_err) pr_back("Connection error: " . $curl_err);

$data = json_decode($response, true);
if ($http_code < 200 || $http_code >= 300) {
    pr_back("PayMongo Error: " . ($data['errors'][0]['detail'] ?? 'PayMongo returned an error.'));
}

$session_id   = $data['data']['id'] ?? null;
$checkout_url = $data['data']['attributes']['checkout_url'] ?? null;
if (!$session_id || !$checkout_url) pr_back("PayMongo did not return a checkout URL.");

// Save the new checkout session on the same payment record
$stmt = $conn->prepare("UPDATE reservation_payments SET checkout_session_id = ? WHERE id = ?");
$stmt->bind_param("si", $session_id, $pay['id']);
$stmt->execute();
$stmt->close();

pay_consume();   // this verification is used up

header("Location: " . $checkout_url);
exit;