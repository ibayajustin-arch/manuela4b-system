<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../config/paymongo.php';
require_once '../includes/payment_guard.php';
require_once '../includes/availability.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

// Facility names and hourly rate periods come from config/facilities.php
$facilities = fac_config()['facilities'];
$fac_cfg    = fac_config();

// =====================================================
// HANDLE NEW BOOKING
// =====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'book') {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_msg'] = "Your session expired. Please refresh the page and try again.";
        header("Location: reservations.php");
        exit;
    }

    // Email OTP required before booking / paying
    if (!pay_verified()) {
        header("Location: verify_payment.php?next=" . urlencode('reservations.php?open=book'));
        exit;
    }

    $user_id = $_SESSION['user_id'];

    $facility   = trim($_POST['facility'] ?? '');
    $res_date   = trim($_POST['res_date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time   = trim($_POST['end_time'] ?? '');
    $payment_option = $_POST['payment_option'] ?? '';

    $fail = function ($msg) {
        $_SESSION['error_msg'] = $msg;
        header("Location: reservations.php");
        exit;
    };

    if ($facility === '' || $res_date === '' || $start_time === '' || $end_time === '') {
        $fail("Please fill in all booking fields.");
    }
    if (!array_key_exists($facility, $facilities)) {
        $fail("Invalid facility selected.");
    }
    if (!in_array($payment_option, ['downpayment', 'full'], true)) {
        $fail("Please select a valid payment option.");
    }

    // Date must be a real date, today or later
    $d = DateTime::createFromFormat('Y-m-d', $res_date);
    if (!$d || $d->format('Y-m-d') !== $res_date || $res_date < date('Y-m-d')) {
        $fail("Please choose today or a future date.");
    }

    $sm = fac_minutes($start_time);
    $em = fac_minutes($end_time);
    if ($sm === null || $em === null) {
        $fail("Invalid time selected.");
    }
    if ($em <= $sm) {
        $fail("End time must be later than start time.");
    }

    // Per-hour bookings: start and end on the hour, at least 1 hour
    if ($sm % 60 !== 0 || $em % 60 !== 0) {
        $fail("Reservations are per hour. Please choose a start and end time on the hour (e.g. 2:00 PM to 4:00 PM).");
    }

    // Operating hours
    $openMin  = fac_minutes($fac_cfg['open']);
    $closeMin = fac_minutes($fac_cfg['close']);
    if ($sm < $openMin || $em > $closeMin) {
        $fail("Facilities are open from " . date('g:i A', strtotime($fac_cfg['open'])) . " to " . date('g:i A', strtotime($fac_cfg['close'])) . ".");
    }

    // Can't book a time that already started today
    if ($res_date === date('Y-m-d') && $sm <= ((int) date('G')) * 60 + (int) date('i')) {
        $fail("That time has already started. Please choose a later time.");
    }

    $start_db = sprintf('%02d:%02d:00', intdiv($sm, 60), $sm % 60);
    $end_db   = sprintf('%02d:%02d:00', intdiv($em, 60), $em % 60);

    $total_amount   = fac_reservation_total($sm, $em);
    $payment_amount = ($payment_option === 'downpayment') ? $total_amount * 0.50 : $total_amount;

    $total_amount   = round($total_amount, 2);
    $payment_amount = round($payment_amount, 2);

    $reference_number = 'RES-' . date('YmdHis') . '-' . $user_id . '-' . random_int(100, 999);

    // One booking at a time per venue (prevents two people grabbing the same slot together)
    $lockName = 'manuela_res_' . facility_venue($facility);
    $lockRes  = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lockName) . "', 5)");
    $gotLock  = $lockRes && (int) $lockRes->fetch_row()[0] === 1;
    $release  = function () use ($conn, $lockName, &$gotLock) {
        if ($gotLock) {
            $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lockName) . "')");
            $gotLock = false;
        }
    };

    if (!$gotLock) {
        $fail("The system is busy. Please try again in a moment.");
    }

    try {
        // Is the slot still free? All court bookings use the same court.
        if (reservation_conflict($conn, $facility, $res_date, $start_db, $end_db)) {
            throw new Exception("Sorry, that time is no longer available. Please check the calendar and pick another slot.");
        }

        $conn->begin_transaction();

        $reservation_status = 'pending';
        $payment_status = 'unpaid';

        $stmt = $conn->prepare("
            INSERT INTO reservations
            (user_id, facility, res_date, start_time, end_time, total_amount, payment_amount, payment_status, payment_option, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception("Reservation database error: " . $conn->error);

        $stmt->bind_param(
            "issssddsss",
            $user_id, $facility, $res_date, $start_db, $end_db,
            $total_amount, $payment_amount, $payment_status, $payment_option, $reservation_status
        );
        if (!$stmt->execute()) throw new Exception("Unable to create reservation: " . $stmt->error);

        $reservation_id = $conn->insert_id;
        $stmt->close();

        $payment_status_db = 'pending';

        $stmt = $conn->prepare("
            INSERT INTO reservation_payments (reservation_id, amount, payment_status, reference_number)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception("Payment database error: " . $conn->error);

        $stmt->bind_param("idss", $reservation_id, $payment_amount, $payment_status_db, $reference_number);
        if (!$stmt->execute()) throw new Exception("Unable to create payment record: " . $stmt->error);

        $payment_record_id = $conn->insert_id;
        $stmt->close();

        // PayMongo checkout (PHP peso -> centavos)
        $amount_in_centavos = (int) round($payment_amount * 100);

        $success_url = BASE_URL . '/resident/payment_success.php?reservation_id=' . urlencode($reservation_id);
        $cancel_url  = BASE_URL . '/resident/payment_cancel.php?reservation_id=' . urlencode($reservation_id);

        $checkout_data = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'name'        => $facility . ' Reservation',
                        'amount'      => $amount_in_centavos,
                        'currency'    => 'PHP',
                        'quantity'    => 1,
                        'description' => $facility . ' reservation on ' . date('M d, Y', strtotime($res_date))
                                       . ' (' . date('h:i A', strtotime($start_db)) . ' - ' . date('h:i A', strtotime($end_db)) . ')'
                    ]],
                    'payment_method_types' => ['gcash', 'paymaya'],
                    'success_url'      => $success_url,
                    'cancel_url'       => $cancel_url,
                    'reference_number' => $reference_number,
                    'description'      => 'Facility Reservation #' . $reservation_id,
                    'show_description' => true,
                    'show_line_items'  => true,
                    'metadata' => [
                        'reservation_id'    => (string) $reservation_id,
                        'payment_record_id' => (string) $payment_record_id,
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
            CURLOPT_POSTFIELDS     => json_encode($checkout_data),
            CURLOPT_TIMEOUT        => 30
        ]);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curl_error)) {
            throw new Exception("PayMongo connection error: " . $curl_error);
        }

        $response_data = json_decode($response, true);

        if ($http_code < 200 || $http_code >= 300) {
            $error_message = $response_data['errors'][0]['detail'] ?? 'PayMongo returned an error.';
            throw new Exception("PayMongo Error: " . $error_message);
        }

        $checkout_session_id = $response_data['data']['id'] ?? null;
        $checkout_url        = $response_data['data']['attributes']['checkout_url'] ?? null;

        if (empty($checkout_session_id) || empty($checkout_url)) {
            throw new Exception("PayMongo did not return a checkout URL.");
        }

        $stmt = $conn->prepare("UPDATE reservation_payments SET checkout_session_id = ? WHERE id = ?");
        if (!$stmt) throw new Exception("Unable to save PayMongo session.");

        $stmt->bind_param("si", $checkout_session_id, $payment_record_id);
        if (!$stmt->execute()) throw new Exception("Unable to save PayMongo checkout session.");
        $stmt->close();

        $conn->commit();
        $release();

        pay_consume();   // this verification is used up

        header("Location: " . $checkout_url);
        exit;

    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Exception $rollback_error) {}
        $release();

        $_SESSION['error_msg'] = $e->getMessage();
        header("Location: reservations.php");
        exit;
    }
}

// =====================================================
// FETCH THIS RESIDENT'S RESERVATIONS
// =====================================================
$reservations = [];

try {
    $stmt = $conn->prepare("
        SELECT r.*, rp.reference_number, rp.payment_method, rp.paid_at
        FROM reservations r
        LEFT JOIN reservation_payments rp ON rp.reservation_id = r.id
        WHERE r.user_id = ?
        ORDER BY r.res_date DESC, r.start_time DESC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) $reservations = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Exception $e) {
    $reservations = [];
}

$today        = date('Y-m-d');
$is_verified  = pay_verified();
$open_booking = $is_verified && (($_GET['open'] ?? '') === 'book');

// Values to pre-fill in the booking form (coming from the calendar, after OTP)
$prefill = null;
if ($open_booking) {
    $pf = [];
    $f = (string) ($_GET['facility'] ?? '');
    if (isset($facilities[$f])) $pf['facility'] = $f;
    $dt = (string) ($_GET['date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) && $dt >= $today) $pf['date'] = $dt;
    foreach (['start', 'end'] as $k) {
        $v = (string) ($_GET[$k] ?? '');
        if (preg_match('/^([01]\d|2[0-3]):00$/', $v)) $pf[$k] = $v;
    }
    $prefill = $pf ?: null;
}

require_once '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">

<style>
    /* ---------- Payment option cards ---------- */
    .payment-choice { position: relative; }

    .payment-choice-radio {
        position: absolute;
        top: 1.15rem;
        left: 1rem;
        z-index: 1;
        margin: 0;
        opacity: 0;
    }

    .payment-choice-label {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-height: 5rem;
        padding: 0.9rem 1rem 0.9rem 2.9rem;
        border: 1px solid var(--bs-border-color, #dee2e6);
        border-radius: 0.65rem;
        cursor: pointer;
        transition: border-color 150ms ease, background-color 150ms ease, box-shadow 150ms ease;
    }

    .payment-choice-label:hover { border-color: var(--primary-color); }

    .payment-choice-radio:checked + .payment-choice-label {
        border-color: var(--primary-color);
        background-color: var(--primary-soft);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary-color) 16%, transparent);
    }

    .payment-choice-radio:focus-visible + .payment-choice-label {
        outline: 3px solid color-mix(in srgb, var(--primary-color) 35%, transparent);
        outline-offset: 2px;
    }

    .payment-choice-icon {
        display: grid;
        flex: 0 0 2.5rem;
        width: 2.5rem;
        height: 2.5rem;
        place-items: center;
        border-radius: 0.5rem;
        background: #f1f3f5;
        color: #495057;
        font-size: 1.15rem;
    }

    .payment-choice-radio:checked + .payment-choice-label .payment-choice-icon {
        background: #fff;
        color: var(--primary-color);
    }

    .payment-choice-copy {
        display: flex;
        flex: 1;
        flex-direction: column;
        gap: 0.15rem;
    }

    .payment-choice-tag {
        color: var(--primary-color);
        font-size: 0.75rem;
        font-weight: 700;
        white-space: nowrap;
    }

    /* ---------- Scrollable booking modal ---------- */
    #bookFacilityModal .modal-content > form {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }

    #bookFacilityModal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
    }

    #bookFacilityModal .modal-footer { flex-shrink: 0; }

    /* ---------- Availability calendar ---------- */
    .avail-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .avail-grid.avail-loading { opacity: .45; pointer-events: none; }
    .avail-grid.avail-loading::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 34px;
        height: 34px;
        margin: -17px 0 0 -17px;
        border: 3px solid var(--primary-border);
        border-top-color: var(--primary-color);
        border-radius: 50%;
        animation: loading-spin .7s linear infinite;
        z-index: 1;
    }
    .avail-grid { position: relative; min-height: 210px; }
    .avail-dow { text-align: center; font-size: .75rem; font-weight: 600; color: var(--text-muted); padding-bottom: 2px; }
    .avail-day { border: 2px solid transparent; border-radius: 10px; padding: 10px 0; font-weight: 600; cursor: pointer; }
    .avail-available { background: #dcf3e6; color: #14532d; }
    .avail-partial   { background: #fff1c9; color: #92400e; }
    .avail-full      { background: #fbd9d9; color: #991b1b; }
    .avail-past      { background: #f1f1f1; color: #b5b5b5; cursor: not-allowed; }
    .avail-day.selected { border-color: var(--primary-color); }
    .avail-legend span { display: inline-flex; align-items: center; gap: 6px; margin-right: 14px; font-size: .8rem; }
    .avail-legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
    .avail-slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
    .slot-chip { border-radius: 10px; padding: 8px 10px; font-size: .85rem; font-weight: 600; text-align: center; border: 1px solid transparent; display: flex; flex-direction: column; }
    .slot-chip small { font-weight: 500; font-size: .7rem; }
    .slot-free   { background: #fff; border-color: #b7dfc7; color: #14532d; cursor: pointer; }
    .slot-free:hover { background: #dcf3e6; }
    .slot-booked { background: #fbd9d9; color: #991b1b; }
    .slot-mine   { background: #dbeafe; color: #1e40af; }
    .slot-past   { background: #f1f1f1; color: #b5b5b5; }
</style>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content flex-grow-1">
        <div class="container-fluid p-0">

            <!-- SUCCESS MESSAGE -->
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show border-0 rounded-3">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_msg']); ?>
            <?php endif; ?>

            <!-- ERROR MESSAGE -->
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3">
                    <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error_msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>

            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h1>Reservations</h1>
                    <p>Book a community facility and track every request.</p>
                </div>

                <?php if ($is_verified): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookFacilityModal">
                        <i class="bi bi-calendar-plus me-2"></i> Book Facility
                    </button>
                <?php else: ?>
                    <a class="btn btn-primary" href="verify_payment.php?next=<?= urlencode('reservations.php?open=book') ?>">
                        <i class="bi bi-calendar-plus me-2"></i> Book Facility
                    </a>
                <?php endif; ?>
            </div>

            <!-- ============ AVAILABILITY CALENDAR ============ -->
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h5 class="fw-bold mb-0"><i class="bi bi-calendar3 me-2"></i>Check Availability</h5>
                        <select id="availFacility" class="form-select form-select-sm" style="max-width: 220px;" aria-label="Facility"></select>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="availPrev" aria-label="Previous month">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <strong id="availTitle"></strong>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="availNext" aria-label="Next month">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>

                    <div class="alert alert-danger border-0 rounded-3 py-2 d-none" id="availError">
                        Could not load the schedule. Please refresh the page.
                    </div>

                    <div class="avail-grid" id="availGrid"></div>

                    <div class="avail-legend mt-3">
                        <span><i style="background:#dcf3e6"></i> Available</span>
                        <span><i style="background:#fff1c9"></i> Partially booked</span>
                        <span><i style="background:#fbd9d9"></i> Fully booked</span>
                        <span><i style="background:#f1f1f1"></i> Past</span>
                    </div>
                    <div class="small text-muted mt-1">Open <?= date('g:i A', strtotime($fac_cfg['open'])) ?> to <?= date('g:i A', strtotime($fac_cfg['close'])) ?>. Court and Tennis Court bookings share availability; Clubhouse bookings are scheduled separately.</div>

                    <div id="availDetail" class="mt-3"></div>
                </div>
            </div>

            <!-- ============ RESERVATION TABLE ============ -->
            <div class="card">
                <div class="card-body p-4">
                    <div class="table-responsive border-0 shadow-none p-0">
                        <table class="table table-hover align-middle mb-0 resident-reservations-table">
                            <thead>
                                <tr>
                                    <th>Facility</th>
                                    <th>Date</th>
                                    <th>Time Slot</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="text-center">Receipt</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($reservations as $row):
                                    $total          = (float) ($row['total_amount'] ?? 0);
                                    $payment_status = $row['payment_status'] ?? 'unpaid';
                                    $status         = $row['status'] ?? 'pending';
                                    $is_past        = !empty($row['res_date']) && $row['res_date'] < $today;
                                    $pay_amount     = (float) ($row['payment_amount'] ?? 0);
                                    $is_down        = ($row['payment_option'] ?? '') === 'downpayment';
                                ?>
                                    <tr>
                                        <!-- FACILITY -->
                                        <td class="fw-semibold"><?= htmlspecialchars($row['facility'] ?? '') ?></td>

                                        <!-- DATE -->
                                        <td><?= !empty($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '' ?></td>

                                        <!-- TIME -->
                                        <td class="text-muted">
                                            <?= !empty($row['start_time']) ? date('h:i A', strtotime($row['start_time'])) : '' ?>
                                            -
                                            <?= !empty($row['end_time']) ? date('h:i A', strtotime($row['end_time'])) : '' ?>
                                        </td>

                                        <!-- TOTAL -->
                                        <td><?= $total > 0 ? '₱' . number_format($total, 2) : 'Free' ?></td>

                                        <!-- PAYMENT STATUS -->
                                        <td>
                                            <?php if ($total <= 0): ?>
                                                <span class="badge badge-success">Free</span>
                                                <div class="small text-muted mt-1">No payment needed</div>

                                            <?php elseif ($payment_status === 'paid'): ?>
                                                <span class="badge badge-success"><i class="bi bi-check-circle me-1"></i> Paid</span>
                                                <div class="small text-muted mt-1">
                                                    ₱<?= number_format($pay_amount, 2) ?> <?= $is_down ? '(50% downpayment)' : '(Full payment)' ?>
                                                </div>

                                            <?php elseif ($status === 'rejected'): ?>
                                                <span class="text-muted small">Not required</span>

                                            <?php else: ?>
                                                <?php if ($payment_status === 'pending'): ?>
                                                    <span class="badge badge-warning">Payment Pending</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Unpaid</span>
                                                <?php endif; ?>

                                                <div class="small text-muted mt-1">
                                                    ₱<?= number_format($pay_amount, 2) ?> <?= $is_down ? '(50% downpayment)' : '(Full payment)' ?>
                                                </div>

                                                <?php if ($is_past): ?>
                                                    <div class="small fw-semibold text-danger mt-1">
                                                        <i class="bi bi-exclamation-triangle me-1"></i> Overdue
                                                    </div>
                                                <?php else: ?>
                                                    <a href="pay_reservation.php?id=<?= (int) $row['id'] ?>"
                                                       data-payment-confirm="reservation"
                                                       class="btn btn-sm btn-primary mt-2">
                                                        <i class="bi bi-credit-card me-1"></i> Pay Now
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>

                                        <!-- RESERVATION STATUS -->
                                        <td>
                                            <?php if ($status === 'approved'): ?>
                                                <span class="badge badge-success">Confirmed</span>
                                            <?php elseif ($status === 'pending' && $payment_status === 'paid'): ?>
                                                <span class="badge badge-warning">Being reviewed</span>
                                            <?php elseif ($status === 'pending'): ?>
                                                <span class="badge badge-warning">Awaiting payment</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Cancelled</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- RECEIPT: opens the compact printable receipt in a new tab -->
                                        <td class="text-center">
                                            <?php if ($payment_status === 'paid'): ?>
                                                <button type="button" class="btn btn-sm"
                                                    style="background-color: var(--primary-soft); color: var(--primary-color);"
                                                    onclick="openReceipt('reservation', <?= (int) $row['id'] ?>)">
                                                    <i class="bi bi-receipt me-1"></i> Receipt
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($reservations)): ?>
                                    <tr>
                                        <td colspan="7" class="table-empty-state">
                                            <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>
                                            No bookings yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>


<!-- =====================================================
     BOOK FACILITY MODAL
====================================================== -->
<div class="modal fade" id="bookFacilityModal" tabindex="-1" aria-labelledby="bookFacilityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">

            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title" id="bookFacilityModalLabel">Reservation Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" data-payment-confirm="reservation" data-fullscreen-loading="Submitting your reservation...">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="book">

                <div class="modal-body">

                    <!-- FACILITY -->
                    <div class="mb-3">
                        <label class="form-label">Select Facility</label>
                        <select class="form-select" name="facility" id="facility" required>
                            <option value="" selected disabled>Choose a facility...</option>
                            <?php foreach (array_keys($facilities) as $fname): ?>
                                <option value="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- DATE -->
                    <div class="mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="res_date" id="res_date" required min="<?= date('Y-m-d') ?>">
                    </div>

                    <!-- TIME -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control" name="start_time" id="start_time"
                                   placeholder="Select start time" autocomplete="off" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-control" name="end_time" id="end_time"
                                   placeholder="Select end time" autocomplete="off" required>
                        </div>
                    </div>
                    <div class="small text-muted mb-3" style="margin-top:-8px;">
                        ₱<?= number_format($fac_cfg['rate_periods'][0]['rate'], 2) ?>/hour from
                        <?= date('g:i A', strtotime($fac_cfg['rate_periods'][0]['start'])) ?> to
                        <?= date('g:i A', strtotime($fac_cfg['rate_periods'][0]['end'])) ?>;
                        ₱<?= number_format($fac_cfg['rate_periods'][1]['rate'], 2) ?>/hour from
                        <?= date('g:i A', strtotime($fac_cfg['rate_periods'][1]['start'])) ?> to
                        <?= date('g:i A', strtotime($fac_cfg['rate_periods'][1]['end'])) ?>.
                    </div>

                    <!-- PRICE PREVIEW -->
                    <div class="alert alert-light border rounded-3" id="pricePreview">
                        <div class="d-flex justify-content-between">
                            <span>Rate:</span>
                            <strong id="rateText">₱0.00 / hour</strong>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <span>Duration:</span>
                            <strong id="durationText">0 hour</strong>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span>Total:</span>
                            <strong id="totalText">₱0.00</strong>
                        </div>
                    </div>

                    <!-- PAYMENT OPTION -->
                    <div class="mb-3">
                        <label class="form-label">Payment Option</label>

                        <div class="payment-choice mb-2">
                            <input class="form-check-input payment-choice-radio" type="radio"
                                   name="payment_option" id="downpayment" value="downpayment" required>
                            <label class="payment-choice-label" for="downpayment">
                                <span class="payment-choice-icon" aria-hidden="true">
                                    <i class="bi bi-pie-chart"></i>
                                </span>
                                <span class="payment-choice-copy">
                                    <strong>50% Downpayment</strong>
                                    <span class="small text-muted">Balance is due upon arrival.</span>
                                </span>
                            </label>
                        </div>

                        <div class="payment-choice">
                            <input class="form-check-input payment-choice-radio" type="radio"
                                   name="payment_option" id="fullpayment" value="full">
                            <label class="payment-choice-label" for="fullpayment">
                                <span class="payment-choice-icon" aria-hidden="true">
                                    <i class="bi bi-credit-card-2-front"></i>
                                </span>
                                <span class="payment-choice-copy">
                                    <strong>Full Payment</strong>
                                    <span class="small text-muted">No balance remains after payment.</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- PAYMENT AMOUNT -->
                    <div class="alert alert-primary" id="paymentPreview">
                        <div class="d-flex justify-content-between">
                            <span>Amount to Pay:</span>
                            <strong id="paymentAmountText">₱0.00</strong>
                        </div>
                    </div>

                    <!-- INFO -->
                    <div class="modal-info-note">
                        <i class="bi bi-info-circle"></i>
                        <span>
                            You will be redirected to PayMongo to complete your payment.
                            Your schedule is reserved once your payment is completed.
                        </span>
                    </div>

                </div>

                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-credit-card me-1"></i> Proceed to Payment
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13"></script>
<script>
window.AVAIL_CONFIG = {
    endpoint:   <?= json_encode(BASE_PATH . '/resident/availability.php') ?>,
    facilities: <?= json_encode(array_keys($facilities)) ?>,
    ratePeriods: <?= json_encode($fac_cfg['rate_periods']) ?>,
    open:       <?= json_encode($fac_cfg['open']) ?>,
    close:      <?= json_encode($fac_cfg['close']) ?>,
    verified:   <?= $is_verified ? 'true' : 'false' ?>,
    verifyUrl:  <?= json_encode(BASE_PATH . '/resident/verify_payment.php?next=') ?>
};
window.AVAIL_PREFILL = <?= json_encode($prefill) ?>;
</script>
<script src="<?= BASE_PATH ?>/Assets/js/availability.js?v=2"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const ratePeriods = <?= json_encode($fac_cfg['rate_periods']) ?>;

    const facility  = document.getElementById('facility');
    const startTime = document.getElementById('start_time');
    const endTime   = document.getElementById('end_time');

    const timePickerOptions = {
        enableTime: true,
        noCalendar: true,
        dateFormat: 'H:i',
        altInput: true,
        altFormat: 'h:i K',
        altInputClass: 'form-control',
        time_24hr: false,
        minuteIncrement: 15,
        allowInput: true,
        disableMobile: true,
        minTime: <?= json_encode($fac_cfg['open']) ?>,
        maxTime: <?= json_encode($fac_cfg['close']) ?>,
        onChange: function (selectedDates, dateStr, instance) {
            // Reservations are per hour: snap to the start of the hour
            if (selectedDates[0] && selectedDates[0].getMinutes() !== 0) {
                instance.setDate(String(selectedDates[0].getHours()).padStart(2, '0') + ':00', true);
                return;
            }
            calculatePrice();
        }
    };

    flatpickr(startTime, timePickerOptions);
    flatpickr(endTime, timePickerOptions);

    const rateText          = document.getElementById('rateText');
    const durationText      = document.getElementById('durationText');
    const totalText         = document.getElementById('totalText');
    const paymentAmountText = document.getElementById('paymentAmountText');
    const paymentOptions    = document.querySelectorAll('input[name="payment_option"]');

    // ==========================================
    // CALCULATE PRICE
    // ==========================================
    function calculatePrice() {
        const start = startTime.value;
        const end   = endTime.value;

        rateText.textContent = ratePeriods.map(function (period) {
            return '₱' + Number(period.rate).toFixed(2) + ' / hour (' + period.start + '–' + period.end + ')';
        }).join('; ');

        if (!start || !end || !facility.value) {
            durationText.textContent = '0 hour';
            totalText.textContent = '₱0.00';
            paymentAmountText.textContent = '₱0.00';
            return;
        }

        const s = start.split(':');
        const e = end.split(':');
        const startMinutes = parseInt(s[0]) * 60 + parseInt(s[1]);
        const endMinutes   = parseInt(e[0]) * 60 + parseInt(e[1]);

        if (endMinutes <= startMinutes) {
            durationText.textContent = 'Invalid time';
            totalText.textContent = '₱0.00';
            paymentAmountText.textContent = '₱0.00';
            return;
        }

        const duration = (endMinutes - startMinutes) / 60;
        let total = 0;
        for (let minute = startMinutes; minute < endMinutes; minute += 60) {
            const period = ratePeriods.find(function (item) {
                const periodStart = parseInt(item.start.split(':')[0]) * 60 + parseInt(item.start.split(':')[1]);
                const periodEnd = parseInt(item.end.split(':')[0]) * 60 + parseInt(item.end.split(':')[1]);
                return minute >= periodStart && minute + 60 <= periodEnd;
            });
            if (!period) {
                durationText.textContent = 'Invalid time';
                totalText.textContent = '₱0.00';
                paymentAmountText.textContent = '₱0.00';
                return;
            }
            total += Number(period.rate);
        }

        durationText.textContent = duration + ' hour' + (duration !== 1 ? 's' : '');
        totalText.textContent = '₱' + total.toFixed(2);

        // PAYMENT AMOUNT
        let paymentAmount = total;
        const selectedOption = document.querySelector('input[name="payment_option"]:checked');
        if (selectedOption && selectedOption.value === 'downpayment') {
            paymentAmount = total * 0.50;
        }
        paymentAmountText.textContent = '₱' + paymentAmount.toFixed(2);
    }

    facility.addEventListener('change', calculatePrice);
    paymentOptions.forEach(function (option) {
        option.addEventListener('change', calculatePrice);
    });

    <?php if ($open_booking): ?>
    // Came back from OTP verification: open the booking form automatically
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bookFacilityModal')).show();
    if (window.AVAIL_PREFILL && window.AvailCalendar) {
        window.AvailCalendar.fill(window.AVAIL_PREFILL);
    }
    <?php endif; ?>
});
</script>

<?php
require_once '../includes/receipt_modal.php';
require_once '../includes/footer.php';
?>
