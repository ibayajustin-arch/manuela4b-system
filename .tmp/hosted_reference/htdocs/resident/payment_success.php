<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../config/paymongo.php';
require_once '../includes/availability.php';
require_once '../includes/payment_methods.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$reservation_id = (int) ($_GET['reservation_id'] ?? 0);

// =====================================================
// BRANCH A: RESERVATION PAYMENT (has reservation_id in URL)
// =====================================================
if ($reservation_id > 0) {

    $stmt = $conn->prepare("
        SELECT
            r.*,
            rp.id AS payment_record_id,
            rp.checkout_session_id,
            rp.payment_status AS db_payment_status
        FROM reservations r
        LEFT JOIN reservation_payments rp
            ON rp.reservation_id = r.id
        WHERE r.id = ?
        AND r.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $reservation_id, $user_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reservation) {
        $_SESSION['error_msg'] = "Reservation not found.";
        header("Location: reservations.php");
        exit;
    }

    $checkout_session_id = $reservation['checkout_session_id'] ?? null;

    if (empty($checkout_session_id)) {
        $_SESSION['error_msg'] = "PayMongo checkout session not found.";
        header("Location: reservations.php");
        exit;
    }

    // Retrieve the checkout session
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . urlencode($checkout_session_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code < 200 || $http_code >= 300) {
        $_SESSION['error_msg'] = "Unable to verify payment with PayMongo.";
        header("Location: reservations.php");
        exit;
    }

    $data = json_decode($response, true);
    $session = $data['data']['attributes'] ?? null;

    if (!$session) {
        $_SESSION['error_msg'] = "Invalid PayMongo response.";
        header("Location: reservations.php");
        exit;
    }

    $paid = false;
    $payment_id = null;
    $payment_method = null;
    $paid_at = null;

    $payment_intent_id = $session['payment_intent']['id'] ?? null;

    if ($payment_intent_id) {
        $pi_ch = curl_init('https://api.paymongo.com/v1/payment_intents/' . urlencode($payment_intent_id));
        curl_setopt_array($pi_ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        $pi_response = curl_exec($pi_ch);
        $pi_http_code = curl_getinfo($pi_ch, CURLINFO_HTTP_CODE);
        curl_close($pi_ch);

        if ($pi_response !== false && $pi_http_code >= 200 && $pi_http_code < 300) {
            $pi_data = json_decode($pi_response, true);
            $pi_attributes = $pi_data['data']['attributes'] ?? null;

            if ($pi_attributes && $pi_attributes['status'] === 'succeeded') {
                $paid = true;

                if (!empty($pi_attributes['payments'])) {
                    $last_payment = end($pi_attributes['payments']);
                    $payment_id = $last_payment['id'] ?? null;
                    $payment_method = $last_payment['attributes']['source']['type'] ?? null;

                    $paid_at = !empty($last_payment['attributes']['paid_at'])
                        ? date('Y-m-d H:i:s', $last_payment['attributes']['paid_at'])
                        : date('Y-m-d H:i:s');
                }
            }
        }
    }

    if ($paid) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE reservations SET payment_status = 'paid' WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $reservation_id, $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE reservation_payments SET payment_status = 'paid', payment_method = ?, payment_id = ?, paid_at = ? WHERE reservation_id = ?");
            $stmt->bind_param("sssi", $payment_method, $payment_id, $paid_at, $reservation_id);
            $stmt->execute();
            $stmt->close();

            $autoApproved = reservation_auto_approve($conn, $reservation_id);

            $conn->commit();
            $_SESSION['success_msg'] = $autoApproved
                ? "Payment successful! Your reservation was auto-approved by the system."
                : "Payment successful! Your reservation is awaiting admin review.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_msg'] = "Payment was successful, but the reservation record could not be updated automatically. Please contact the administrator.";
        }
    } else {
        $_SESSION['error_msg'] = "We could not confirm your payment yet. Please check your reservation status again in a moment.";
    }

    header("Location: reservations.php");
    exit;
}

// =====================================================
// BRANCH B: MONTHLY DUES PAYMENT (uses session data set by create_checkout.php or URL fallback)
// =====================================================
$checkout_id = $_SESSION['paymongo_checkout_id'] ?? $_GET['checkout_id'] ?? $_GET['session_id'] ?? null;

if (!$checkout_id) {
    header("Location: payments.php");
    exit;
}

$ch = curl_init(PAYMONGO_API_URL . "/checkout_sessions/" . $checkout_id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "accept: application/json",
    "Authorization: Basic " . base64_encode(PAYMONGO_SECRET_KEY . ":")
]);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$sessionData = $result['data'] ?? [];
$sessionAttrs = $sessionData['attributes'] ?? [];
$metadata = $sessionAttrs['metadata'] ?? [];

$payment_intent = $sessionAttrs['payment_intent']['attributes'] ?? [];
$payment_status = $payment_intent['status'] ?? null;
$is_paid = ($payment_status === 'succeeded');

$firstPaymentAttrs = $payment_intent['payments'][0]['attributes'] ?? [];
$payment_method_raw = $firstPaymentAttrs['source']['type']
    ?? ($payment_intent['payment_method']['type'] ?? 'unknown');
$payment_method = payment_method_label($payment_method_raw);
if ($payment_method === 'Unknown') {
    error_log('PayMongo payment completed without a recognizable GCash or Maya method.');
}

if ($is_paid) {
    $homeowner_id = (int) ($_SESSION['paymongo_homeowner_id'] ?? ($metadata['homeowner_id'] ?? 0));
    if ($homeowner_id <= 0) {
        $stmt = $conn->prepare("SELECT id FROM homeowners WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $hRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $homeowner_id = (int) ($hRow['id'] ?? 0);
    }

    $period = $_SESSION['paymongo_period'] ?? ($metadata['period_covered'] ?? date('Y-m'));
    $amount = (float) ($_SESSION['paymongo_amount'] ?? (isset($firstPaymentAttrs['amount']) ? $firstPaymentAttrs['amount'] / 100 : MONTHLY_DUES_AMOUNT));
    $receipt_no = 'PM-' . strtoupper(substr($checkout_id, -8));
    $notes = 'Paid online via PayMongo (' . $payment_method . ')';

    try {
        $stmt = $conn->prepare("INSERT INTO payments (homeowner_id, amount_paid, payment_date, period_covered, receipt_no, payment_method, notes) VALUES (?, ?, NOW(), ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("idssss", $homeowner_id, $amount, $period, $receipt_no, $payment_method, $notes);
            $stmt->execute();
            $stmt->close();
        }
        $_SESSION['flash_success'] = "Payment successful via {$payment_method}! Your dues have been recorded.";
    } catch (Exception $e) {
        if ($conn->errno === 1062) {
            $_SESSION['flash_success'] = "Payment successful via {$payment_method}! Your dues have been recorded.";
        } else {
            $_SESSION['flash_error'] = "Payment succeeded but recording failed: " . $e->getMessage();
        }
    }

    unset($_SESSION['paymongo_checkout_id'], $_SESSION['paymongo_amount'], $_SESSION['paymongo_description'], $_SESSION['paymongo_period'], $_SESSION['paymongo_homeowner_id']);
} else {
    $_SESSION['flash_error'] = "Payment could not be verified. Please contact the admin if you were charged.";
}

header("Location: payments.php");
exit;