<?php
// Called directly by PayMongo's servers (server-to-server), NOT by the browser.
// No login/session check here — PayMongo has no PHP session cookie.

require_once '../config/init.php';
require_once '../config/db.php';
require_once '../config/paymongo.php';
require_once '../includes/payment_methods.php';

// 1. Read the RAW request body — must be unparsed for signature verification to work
$payload = @file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (empty($payload) || empty($signatureHeader)) {
    http_response_code(400);
    exit('Missing payload or signature');
}

// 2. Parse the header format: "t=<timestamp>,te=<test_signature>,li=<live_signature>"
$parts = [];
foreach (explode(',', $signatureHeader) as $pair) {
    $kv = explode('=', $pair, 2);
    if (count($kv) === 2) {
        $parts[$kv[0]] = $kv[1];
    }
}

$timestamp = $parts['t'] ?? null;
$testSignature = $parts['te'] ?? null; // we are in Test Mode

if (!$timestamp || !$testSignature) {
    http_response_code(400);
    exit('Malformed signature header');
}

// 3. Compute our own signature and compare using a timing-safe check
$signedPayload = $timestamp . '.' . $payload;
$computedSignature = hash_hmac('sha256', $signedPayload, PAYMONGO_WEBHOOK_SECRET);

if (!hash_equals($computedSignature, $testSignature)) {
    http_response_code(400);
    exit('Invalid signature');
}

// 4. Signature is valid — safe to process the event now
$event = json_decode($payload, true);
$eventType = $event['data']['attributes']['type'] ?? null;
$resource = $event['data']['attributes']['data'] ?? null;

if ($eventType === 'checkout_session.payment.paid' && $resource) {
    $attributes = $resource['attributes'] ?? [];
    $metadata = $attributes['metadata'] ?? [];
    $homeowner_id = isset($metadata['homeowner_id']) ? (int) $metadata['homeowner_id'] : null;
    $period = $metadata['period_covered'] ?? date('Y-m');

    $checkoutSessionId = $resource['id'] ?? null;
    $paymentsArr = $attributes['payments'] ?? [];
    $firstPayment = $paymentsArr[0]['attributes'] ?? [];

    $amount = isset($firstPayment['amount']) ? $firstPayment['amount'] / 100 : 0;
    $paymentMethodRaw = $firstPayment['source']['type'] ?? 'unknown';
    $paymentMethod = payment_method_label($paymentMethodRaw);
    if ($paymentMethod === 'Unknown') {
        error_log('PayMongo webhook payment had no recognizable GCash or Maya method.');
    }

    if ($homeowner_id && $checkoutSessionId) {
        $receipt_no = 'PM-' . strtoupper(substr($checkoutSessionId, -8));
        $notes = 'Paid online via PayMongo webhook (' . $paymentMethod . ')';

        try {
            $stmt = $conn->prepare("INSERT INTO payments (homeowner_id, amount_paid, payment_date, period_covered, receipt_no, payment_method, notes) VALUES (?, ?, NOW(), ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("idssss", $homeowner_id, $amount, $period, $receipt_no, $paymentMethod, $notes);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            // Duplicate receipt_no means payment_success.php (the browser redirect)
            // already recorded this. That's expected and safe to ignore.
        }
    }
}

// 5. Always respond 200 so PayMongo doesn't keep retrying this event
http_response_code(200);
echo json_encode(['received' => true]);