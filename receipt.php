<?php
// Place this file in the project ROOT (same level as login.php, config/, admin/, resident/)
//
// Usage:
//   receipt.php?id=12                         -> dues payment receipt
//   receipt.php?type=reservation&id=7         -> facility reservation receipt
//   add &layout=half for a single half-sheet copy (default: 2 copies on one bond paper)
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/payment_methods.php';
require_once 'includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$type = ($_GET['type'] ?? 'payment') === 'reservation' ? 'reservation' : 'payment';
$recordId = (int) ($_GET['id'] ?? 0);
$layout = ($_GET['layout'] ?? 'duplicate') === 'half' ? 'half' : 'duplicate';
$embed = !empty($_GET['embed']);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// ---------- Fetch the record ----------
$row = null;
$ownerUserId = null;
$isPaid = false;

if ($recordId > 0) {
    if ($type === 'payment') {
        $stmt = $conn->prepare(
            "SELECT p.*, h.full_name, h.block, h.lot, h.user_id AS owner_user_id
             FROM payments p
             LEFT JOIN homeowners h ON p.homeowner_id = h.id
             WHERE p.id = ? LIMIT 1"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT r.*, rp.reference_number, rp.payment_method, rp.paid_at,
                    h.full_name, h.block, h.lot, r.user_id AS owner_user_id
             FROM reservations r
             LEFT JOIN reservation_payments rp ON rp.id = (
                 SELECT MAX(rp2.id)
                 FROM reservation_payments rp2
                 WHERE rp2.reservation_id = r.id
                   AND rp2.payment_status = 'paid'
             )
             LEFT JOIN homeowners h ON h.user_id = r.user_id
             WHERE r.id = ?
             LIMIT 1"
        );
    }
    if ($stmt) {
        $stmt->bind_param("i", $recordId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if ($row) {
    $ownerUserId = $row['owner_user_id'];
    $isPaid = $type === 'payment'
        ? (!empty($row['payment_date']) && $row['payment_date'] !== '0000-00-00')
        : (($row['payment_status'] ?? '') === 'paid');
}

// Admin = any receipt, resident = own receipts only
$allowed = $row && ($isAdmin || (int) $ownerUserId === (int) $_SESSION['user_id']);

if (!$allowed || !$isPaid) {
    http_response_code($allowed ? 404 : 403);
    echo '<!DOCTYPE html><html><body style="font-family:Arial;padding:40px;text-align:center;color:#444;">
          <h2>Receipt not available</h2>
          <p>This receipt does not exist, is not yet paid, or you do not have access to it.</p>
          <p><a href="javascript:history.back()">Go back</a></p></body></html>';
    exit;
}

// ---------- Helpers ----------
function int_to_words($n) {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
             'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $scales = ['', ' Thousand', ' Million', ' Billion'];
    if ($n === 0) return 'Zero';
    $parts = [];
    $i = 0;
    while ($n > 0) {
        $chunk = $n % 1000;
        if ($chunk) {
            $w = '';
            if ($chunk >= 100) {
                $w .= $ones[intdiv($chunk, 100)] . ' Hundred';
                $chunk %= 100;
                if ($chunk) $w .= ' ';
            }
            if ($chunk >= 20) {
                $w .= $tens[intdiv($chunk, 10)];
                if ($chunk % 10) $w .= '-' . $ones[$chunk % 10];
            } elseif ($chunk > 0) {
                $w .= $ones[$chunk];
            }
            array_unshift($parts, $w . $scales[$i]);
        }
        $n = intdiv($n, 1000);
        $i++;
    }
    return implode(' ', $parts);
}

function amount_in_words($amount) {
    $amount = round((float) $amount, 2);
    $pesos = (int) floor($amount);
    $centavos = (int) round(($amount - $pesos) * 100);
    $words = int_to_words($pesos) . ' Pesos';
    if ($centavos > 0) $words .= ' and ' . str_pad($centavos, 2, '0', STR_PAD_LEFT) . '/100';
    return $words;
}

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$methodLabels = [
    'gcash' => 'GCash', 'paymaya' => 'Maya', 'grab_pay' => 'GrabPay',
    'card' => 'Credit/Debit Card', 'qrph' => 'QR Ph',
];

// ---------- Build the receipt content ----------
$homeowner = !empty($row['full_name']) ? $row['full_name'] : 'Resident';
$residentAddress = homeowner_address_label($row['block'] ?? '', $row['lot'] ?? '');

$fields = [];        // [label, value, fullWidth]
$summaryRows = [];   // [label, value] shown above the amount paid
$balanceNote = '';   // remaining balance line (downpayments)

if ($type === 'payment') {
    $docTitle   = 'Official Receipt';
    $refLabel   = 'Receipt No.';
    $refValue   = !empty($row['receipt_no']) ? $row['receipt_no'] : '-';
    $homeowner  = !empty($row['full_name']) ? $row['full_name'] : ('Homeowner #' . $row['homeowner_id']);
    $method     = payment_method_label($row['payment_method'] ?? null, $row['notes'] ?? null);
    $methodIndicator = payment_method_indicator_html($row['payment_method'] ?? null, $row['notes'] ?? null);
    $period     = preg_match('/^\d{4}-\d{2}$/', $row['period_covered'])
                    ? 'Monthly Dues — ' . date('F Y', strtotime($row['period_covered'] . '-01'))
                    : $row['period_covered'];
    $amount     = (float) $row['amount_paid'];

    $fields = [
        ['Received From', $homeowner, false],
        ['Date Paid', date('F j, Y', strtotime($row['payment_date'])), false],
        ['Address', $residentAddress, false],
        ['Payment Method', $method, false],
        ['Payment For', $period, true],
    ];
} else {
    $docTitle   = 'Facility Reservation Receipt';
    $refLabel   = 'Reference No.';
    $refValue   = !empty($row['reference_number']) ? $row['reference_number'] : ('RES-' . $row['id']);
    $rawMethod  = trim((string) ($row['payment_method'] ?? ''));
    $method     = $rawMethod === '' ? 'GCash' : payment_method_label($rawMethod);
    $methodIndicator = payment_method_indicator_html($rawMethod === '' ? 'gcash' : $rawMethod);
    $amount     = (float) $row['payment_amount'];
    $total      = (float) $row['total_amount'];
    $isDown     = ($row['payment_option'] ?? '') === 'downpayment';
    $paidAtText = !empty($row['paid_at'])
                    ? date('F j, Y g:i A', strtotime($row['paid_at']))
                    : date('F j, Y', strtotime($row['res_date']));
    $schedule   = date('M j, Y', strtotime($row['res_date'])) . ' · '
                . date('g:i A', strtotime($row['start_time'])) . ' – '
                . date('g:i A', strtotime($row['end_time']));

    $fields = [
        ['Received From', $homeowner, false],
        ['Date Paid', $paidAtText, false],
        ['Address', $residentAddress, false],
        ['Payment Method', $method, false],
        ['Facility', $row['facility'], false],
        ['Payment Type', $isDown ? '50% Downpayment' : 'Full Payment', false],
        ['Reservation Schedule', $schedule, true],
    ];
    $summaryRows[] = ['Total Reservation Cost', '₱ ' . number_format($total, 2)];
    if ($isDown) {
        $balance = max(0, $total - $amount);
        $balanceNote = 'Remaining balance of ₱ ' . number_format($balance, 2) . ' is due upon arrival at the facility.';
    }
}

$amountWords = amount_in_words($amount);
$printedAt   = date('M j, Y g:i A');

$copies = $layout === 'duplicate' ? ["Homeowner's Copy", "Office Copy"] : ["Homeowner's Copy"];
$pageSize = $layout === 'duplicate' ? 'letter portrait' : '8.5in 5.5in';
$baseQuery = 'type=' . $type . '&id=' . $recordId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($docTitle) ?> <?= $e($refValue) ?> - Manuela 4B</title>
<link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
    @page { size: <?= $pageSize ?>; margin: 8mm; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #F1F5F9; font-family: 'Poppins', Arial, sans-serif; color: #1E293B; font-size: 12px; }

    .toolbar { max-width: 190mm; margin: 16px auto 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding: 0 8px; }
    .toolbar a, .toolbar button { padding: 7px 14px; border-radius: 8px; border: 1px solid #CBD5E1; background: #fff; color: #334155; text-decoration: none; cursor: pointer; font-size: 12.5px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .toolbar .primary { background: #E87024; border-color: #D05E16; color: #fff; font-weight: 600; }
    .toolbar .active { background: #FFF5ED; border-color: #FCD8BF; color: #E87024; font-weight: 600; }
    .toolbar .spacer { flex: 1; }

    .sheet { max-width: 190mm; margin: 12px auto 24px; background: #fff; padding: 8mm; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,.06); border: 1px solid #E2E8F0; }

    .receipt { border: 1.5px solid #000; border-radius: 8px; padding: 7mm 8mm; position: relative; page-break-inside: avoid; background: #fff; color: #000; }
    .copy-tag { position: absolute; top: 0; right: 0; background: #fff; color: #000; border-left: 1px solid #000; border-bottom: 1px solid #000; font-size: 8.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: 3px 10px; border-bottom-left-radius: 6px; }

    .head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px; }
    .org { font-size: 16px; font-weight: 700; color: #000; letter-spacing: -0.01em; }
    .doc-title { font-size: 9.5px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #000; margin-top: 2px; }
    .rno { text-align: right; }
    .rno .lbl { font-size: 9px; text-transform: uppercase; color: #000; display: block; font-weight: 600; }
    .rno .val { font-size: 14px; font-weight: 700; color: #000; }

    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; margin-bottom: 10px; }
    .field .lbl { font-size: 9px; text-transform: uppercase; color: #000; display: block; font-weight: 600; letter-spacing: .04em; }
    .field .val { font-size: 12px; font-weight: 600; color: #000; }
    .field.full { grid-column: 1 / -1; }
    .payment-method-indicator { display: inline-flex; align-items: center; justify-content: center; min-height: 25px; padding: 2px 8px; border: 1px solid #CBD5E1; border-radius: 999px; background: #fff; vertical-align: middle; }
    .payment-method-indicator img { display: block; width: auto; max-width: 70px; height: 18px; object-fit: contain; }
    .payment-method-indicator.method-gcash { background: #F2F7FF; border-color: #B8D0FF; }
    .payment-method-indicator.method-maya { background: #F0FBF6; border-color: #A8E2C7; }
    .payment-method-indicator.method-other { font-size: 10px; }

    .amount-box { background: #fff; border: 1px solid #000; border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; }
    .sum-row { display: flex; justify-content: space-between; font-size: 11px; color: #000; padding-bottom: 5px; margin-bottom: 5px; border-bottom: 1px dashed #000; }
    .amount-row { display: flex; justify-content: space-between; align-items: center; }
    .amount-row .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #000; font-weight: 700; }
    .amount-row .amt { font-size: 20px; font-weight: 700; color: #000; }
    .words { font-size: 11px; font-style: italic; color: #000; margin-top: 4px; }
    .balance { font-size: 10.5px; color: #000; margin-top: 6px; padding-top: 5px; border-top: 1px dashed #000; font-weight: 500; }

    .sign-row { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 16px; gap: 20px; }
    .note { font-size: 9.5px; color: #000; flex: 1; line-height: 1.4; }
    .sign { width: 60mm; border-top: 1px solid #000; text-align: center; font-size: 10px; font-weight: 600; padding-top: 4px; color: #000; }

    .cut { border-top: 1px dashed #000; margin: 6mm 0; text-align: center; font-size: 9px; color: #000; line-height: 0; }
    .cut span { background: #fff; padding: 0 8px; }


    /* Embedded preview (inside the modal): no toolbar, no page chrome */
    body.embed { background: transparent; }
    body.embed .sheet { max-width: none; margin: 0; padding: 10px; box-shadow: none; border-radius: 8px; }

    @media screen and (max-width: 575.98px) {
        body.embed .sheet { padding: 4px; }
        body.embed .receipt { padding: 4mm; }
        body.embed .head { align-items: flex-start; gap: 6px; padding-bottom: 6px; margin-bottom: 7px; }
        body.embed .org { font-size: 13px; }
        body.embed .doc-title { font-size: 7px; }
        body.embed .rno { max-width: 45%; }
        body.embed .rno .lbl { font-size: 7px; }
        body.embed .rno .val { font-size: 10px; overflow-wrap: anywhere; }
        body.embed .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 5px 8px; margin-bottom: 7px; }
        body.embed .field.full { grid-column: 1 / -1; }
        body.embed .field .lbl { font-size: 7px; }
        body.embed .field .val { font-size: 10px; overflow-wrap: anywhere; }
        body.embed .amount-box { padding: 7px 9px; margin-bottom: 6px; }
        body.embed .sum-row { font-size: 9px; }
        body.embed .amount-row .lbl { font-size: 8px; }
        body.embed .amount-row .amt { font-size: 16px; }
        body.embed .words { font-size: 9px; }
        body.embed .balance { font-size: 9px; }
        body.embed .sign-row { align-items: flex-end; gap: 8px; margin-top: 10px; }
        body.embed .note { font-size: 8px; }
        body.embed .sign { width: 45%; font-size: 8px; }
    }

    @media print {
        html, body { background: #fff !important; color: #000 !important; }
        body * {
            color: #000 !important;
            background-color: transparent !important;
            border-color: #000 !important;
            box-shadow: none !important;
            text-shadow: none !important;
            -webkit-print-color-adjust: economy !important;
            print-color-adjust: economy !important;
        }
        .sheet, .receipt, .amount-box, .copy-tag, .head, .cut span {
            background: #fff !important;
        }
        .toolbar { display: none; }
        .sheet, body.embed .sheet { max-width: none; margin: 0; padding: 0; box-shadow: none; border-radius: 0; }
    }
</style>
</head>
<body class="<?= $embed ? 'embed' : '' ?>">

<?php if (!$embed): ?>
<div class="toolbar">
    <a class="<?= $layout === 'duplicate' ? 'active' : '' ?>" href="?<?= $baseQuery ?>&layout=duplicate">2 copies / bond paper</a>
    <a class="<?= $layout === 'half' ? 'active' : '' ?>" href="?<?= $baseQuery ?>&layout=half">Half-sheet (1 copy)</a>
    <span class="spacer"></span>
    <button class="primary" onclick="window.print()">Print</button>
    <button onclick="window.close()">Close</button>
</div>
<?php endif; ?>

<div class="sheet">
    <?php foreach ($copies as $idx => $copyLabel): ?>
        <?php if ($idx > 0): ?>
            <div class="cut"><span>✂ cut here</span></div>
        <?php endif; ?>

        <div class="receipt">
            <div class="copy-tag"><?= $e($copyLabel) ?></div>

            <div class="head">
                <div>
                    <div class="org">Manuela 4B</div>
                    <div class="doc-title">Homeowners Association &middot; <?= $e($docTitle) ?></div>
                </div>
                <div class="rno">
                    <span class="lbl"><?= $e($refLabel) ?></span>
                    <span class="val"><?= $e($refValue) ?></span>
                </div>
            </div>

            <div class="grid">
                <?php foreach ($fields as [$label, $value, $full]): ?>
                    <div class="field <?= $full ? 'full' : '' ?>">
                        <span class="lbl"><?= $e($label) ?></span>
                        <span class="val"><?= $label === 'Payment Method' ? $methodIndicator : $e($value) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="amount-box">
                <?php foreach ($summaryRows as [$label, $value]): ?>
                    <div class="sum-row"><span><?= $e($label) ?></span><span><?= $e($value) ?></span></div>
                <?php endforeach; ?>
                <div class="amount-row">
                    <span class="lbl">Amount Paid</span>
                    <span class="amt">₱ <?= number_format($amount, 2) ?></span>
                </div>
                <div class="words"><?= $e($amountWords) ?></div>
                <?php if ($balanceNote): ?>
                    <div class="balance"><?= $e($balanceNote) ?></div>
                <?php endif; ?>
            </div>

            <div class="sign-row">
                <div class="note">
                    This serves as your official receipt. Please keep it for your records.<br>
                    Printed <?= $e($printedAt) ?>
                </div>
                <div class="sign">Authorized Signature</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>
