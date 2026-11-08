<?php
require_once '../config/init.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    exit('Administrator access is required.');
}
require_once '../config/db.php';
require_once '../includes/payment_methods.php';
date_default_timezone_set('Asia/Manila');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$zone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $zone);
$range = is_string($_GET['range'] ?? null) ? $_GET['range'] : 'month';
switch ($range) {
    case 'today': $from = $today; $to = $today; break;
    case 'week': $from = $today->modify('monday this week'); $to = $today; break;
    case 'month': $from = $today->modify('first day of this month'); $to = $today; break;
    case 'last_month': $from = $today->modify('first day of last month'); $to = $today->modify('last day of last month'); break;
    case 'year': $from = $today->setDate((int) $today->format('Y'), 1, 1); $to = $today; break;
    case 'custom':
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['from'] ?? ''), $zone);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['to'] ?? ''), $zone);
        if (!$from || !$to || $from->format('Y-m-d') !== (string) ($_GET['from'] ?? '') || $to->format('Y-m-d') !== (string) ($_GET['to'] ?? '')) { http_response_code(400); exit('Enter valid custom dates.'); }
        break;
    default: http_response_code(400); exit('Choose a valid date range.');
}
if ($from > $to || (int) $from->diff($to)->format('%a') > 3660) { http_response_code(400); exit('Choose a valid date range of ten years or less.'); }
$fromValue = $from->format('Y-m-d'); $endValue = $to->modify('+1 day')->format('Y-m-d');
$block = is_string($_GET['block'] ?? null) ? trim($_GET['block']) : '';
$facility = is_string($_GET['facility'] ?? null) ? trim($_GET['facility']) : '';
$reservationStatus = is_string($_GET['reservation_status'] ?? null) ? strtolower(trim($_GET['reservation_status'])) : '';
$feedbackCategory = is_string($_GET['feedback_category'] ?? null) ? strtolower(trim($_GET['feedback_category'])) : 'concern';
if (strlen($block) > 50 || strlen($facility) > 100 || !in_array($feedbackCategory, ['concern', 'suggestion', 'all'], true) || ($reservationStatus !== '' && !in_array($reservationStatus, ['approved', 'pending', 'rejected'], true))) { http_response_code(400); exit('One of the selected filters is invalid.'); }

function report_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Report query unavailable.');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Report query failed.');
    $result = $stmt->get_result(); $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : []; $stmt->close();
    return $rows;
}
function report_has_column(mysqli $conn, string $table, string $column): bool
{
    $rows = report_rows($conn, 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1', 'ss', [$table, $column]);
    return (bool) $rows;
}
function report_h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

try {
    $conn->set_charset('utf8mb4');
    $homeArchived = report_has_column($conn, 'homeowners', 'archived_at') ? 'archived_at IS NULL' : '1=1';
    $reservationArchived = report_has_column($conn, 'reservations', 'archived_at') ? 'r.archived_at IS NULL' : '1=1';
    $feedbackExists = (bool) report_rows($conn, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resident_feedback'");
    $feedbackArchived = $feedbackExists && report_has_column($conn, 'resident_feedback', 'archived_at') ? 'f.archived_at IS NULL' : '1=1';
    $homeWhere = $homeArchived . ($block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : '');
    $homeTypes = $block !== '' ? 's' : ''; $homeParams = $block !== '' ? [$block] : [];
    $homeStatusRows = report_rows($conn, "SELECT LOWER(status) AS label, COUNT(*) AS total FROM homeowners WHERE {$homeWhere} GROUP BY LOWER(status) ORDER BY label", $homeTypes, $homeParams);
    $homeStats = []; $homeTotal = $approved = $pending = 0;
    foreach ($homeStatusRows as $row) { $label = (string) $row['label']; $count = (int) $row['total']; $homeStats[$label] = $count; $homeTotal += $count; if ($label === 'active') $approved = $count; if ($label === 'pending') $pending = $count; }
    $reservationWhere = "{$reservationArchived} AND r.res_date >= ? AND r.res_date < ?";
    $reservationParams = [$fromValue, $endValue]; $reservationTypes = 'ss';
    if ($reservationStatus !== '') { $reservationWhere .= ' AND r.status = ?'; $reservationParams[] = $reservationStatus; $reservationTypes .= 's'; }
    if ($facility !== '') { $reservationWhere .= ' AND r.facility = ?'; $reservationParams[] = $facility; $reservationTypes .= 's'; }
    if ($block !== '') { $homeArchivedForReservation = report_has_column($conn, 'homeowners', 'archived_at') ? ' AND h.archived_at IS NULL' : ''; $reservationWhere .= " AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id{$homeArchivedForReservation} AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))"; $reservationParams[] = $block; $reservationTypes .= 's'; }
    $reservationRows = report_rows($conn, "SELECT r.facility AS label, COUNT(*) AS total FROM reservations r WHERE {$reservationWhere} GROUP BY r.facility ORDER BY total DESC, r.facility", $reservationTypes, $reservationParams);
    $reservationTotal = array_sum(array_map(static fn($row) => (int) $row['total'], $reservationRows));
    $concernUnresolved = $concernResolved = 0; $feedbackRows = $concernStreetRows = [];
    if ($feedbackExists) {
        $feedbackWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ? AND f.category = 'concern'";
        $feedbackParams = [$fromValue, $endValue]; $feedbackTypes = 'ss';
        $feedbackJoin = 'FROM resident_feedback f';
        if ($block !== '') {
            $homeFilter = $homeArchived !== '1=1' ? ' WHERE archived_at IS NULL' : '';
            $feedbackJoin .= ' LEFT JOIN (SELECT user_id, MAX(TRIM(block)) AS block FROM homeowners' . $homeFilter . ' GROUP BY user_id) h ON h.user_id = f.user_id';
            $feedbackWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $feedbackParams[] = $block; $feedbackTypes .= 's';
        }
        $concernRows = report_rows($conn, "SELECT f.status, COUNT(*) AS total {$feedbackJoin} WHERE {$feedbackWhere} GROUP BY f.status", $feedbackTypes, $feedbackParams);
        foreach ($concernRows as $row) { if ($row['status'] === 'resolved') $concernResolved += (int) $row['total']; else $concernUnresolved += (int) $row['total']; }
        $concernStreetWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ? AND f.category = 'concern'";
        $concernStreetParams = [$fromValue, $endValue]; $concernStreetTypes = 'ss';
        if ($block !== '') { $concernStreetWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $concernStreetParams[] = $block; $concernStreetTypes .= 's'; }
        $concernStreetJoin = $block !== '' ? $feedbackJoin : 'FROM resident_feedback f LEFT JOIN (SELECT user_id, MAX(TRIM(block)) AS block FROM homeowners' . ($homeArchived !== '1=1' ? ' WHERE archived_at IS NULL' : '') . ' GROUP BY user_id) h ON h.user_id = f.user_id';
        $concernStreetRows = report_rows($conn, "SELECT COALESCE(NULLIF(TRIM(h.block), ''), 'Unlinked') AS label, COUNT(*) AS total {$concernStreetJoin} WHERE {$concernStreetWhere} GROUP BY label ORDER BY total DESC, label", $concernStreetTypes, $concernStreetParams);
        $categoryWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ?"; $categoryParams = [$fromValue, $endValue]; $categoryTypes = 'ss';
        if ($feedbackCategory !== 'all') { $categoryWhere .= ' AND f.category = ?'; $categoryParams[] = $feedbackCategory; $categoryTypes .= 's'; }
        if ($block !== '') { $categoryWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $categoryParams[] = $block; $categoryTypes .= 's'; }
        $feedbackJoin = $block !== '' ? $feedbackJoin : 'FROM resident_feedback f';
        $feedbackRows = report_rows($conn, "SELECT f.category AS label, COUNT(*) AS total {$feedbackJoin} WHERE {$categoryWhere} GROUP BY f.category ORDER BY total DESC, f.category", $categoryTypes, $categoryParams);
    }
    $streetFilter = $block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : '';
    $streetSql = "SELECT TRIM(block) AS label, COUNT(*) AS total FROM homeowners WHERE {$homeArchived} AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned'{$streetFilter} GROUP BY TRIM(block) ORDER BY total DESC, label";
    $streetParams = $block !== '' ? [$block] : [];
    $streetRows = report_rows($conn, $streetSql, $block !== '' ? 's' : '', $streetParams);
    $reservationStatusWhere = "{$reservationArchived} AND r.res_date >= ? AND r.res_date < ?";
    $reservationStatusParams = [$fromValue, $endValue]; $reservationStatusTypes = 'ss';
    if ($facility !== '') { $reservationStatusWhere .= ' AND r.facility = ?'; $reservationStatusParams[] = $facility; $reservationStatusTypes .= 's'; }
    if ($block !== '') { $homeArchivedForReservation = report_has_column($conn, 'homeowners', 'archived_at') ? ' AND h.archived_at IS NULL' : ''; $reservationStatusWhere .= " AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id{$homeArchivedForReservation} AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))"; $reservationStatusParams[] = $block; $reservationStatusTypes .= 's'; }
    $reservationStatusRows = report_rows($conn, "SELECT r.status AS label, COUNT(*) AS total FROM reservations r WHERE {$reservationStatusWhere} GROUP BY r.status ORDER BY r.status", $reservationStatusTypes, $reservationStatusParams);
    $registrationWhere = "created_at >= ? AND created_at < ?" . ($block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : '');
    $registrationParams = array_merge([$fromValue, $endValue], $streetParams);
    $registrationRows = report_rows($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS total FROM homeowners WHERE {$registrationWhere} GROUP BY label ORDER BY label", 'ss' . $homeTypes, $registrationParams);
    $reservationTrendWhere = 'r.res_date >= ? AND r.res_date < ?';
    $reservationTrendParams = [$fromValue, $endValue];
    $reservationTrendTypes = 'ss';
    if ($reservationStatus !== '') { $reservationTrendWhere .= ' AND r.status = ?'; $reservationTrendParams[] = $reservationStatus; $reservationTrendTypes .= 's'; }
    if ($facility !== '') { $reservationTrendWhere .= ' AND r.facility = ?'; $reservationTrendParams[] = $facility; $reservationTrendTypes .= 's'; }
    if ($block !== '') {
        $homeArchivedForReservation = report_has_column($conn, 'homeowners', 'archived_at') ? ' AND h.archived_at IS NULL' : '';
        $reservationTrendWhere .= " AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id{$homeArchivedForReservation} AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))";
        $reservationTrendParams[] = $block;
        $reservationTrendTypes .= 's';
    }
    $reservationTrendRows = report_rows($conn, "SELECT DATE_FORMAT(r.res_date, '%Y-%m') AS label, COUNT(*) AS total FROM reservations r WHERE {$reservationTrendWhere} GROUP BY label ORDER BY label", $reservationTrendTypes, $reservationTrendParams);
    $paymentJoin = $block !== '' ? ' INNER JOIN homeowners h ON h.id = p.homeowner_id' . ($homeArchived !== '1=1' ? ' AND h.archived_at IS NULL' : '') : '';
    $paymentWhere = 'p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ?';
    $paymentParams = [$fromValue, $endValue]; $paymentTypes = 'ss';
    if ($block !== '') { $paymentWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $paymentParams[] = $block; $paymentTypes .= 's'; }
    $paymentRows = report_rows($conn, "SELECT " . payment_method_sql_expression('p') . " AS label, COUNT(*) AS transactions, COALESCE(SUM(p.amount_paid), 0) AS amount FROM payments p{$paymentJoin} WHERE {$paymentWhere} GROUP BY label ORDER BY amount DESC, label", $paymentTypes, $paymentParams);
    $paymentTotal = array_sum(array_map(static fn($row) => (float) $row['amount'], $paymentRows));
    $paymentCount = array_sum(array_map(static fn($row) => (int) $row['transactions'], $paymentRows));
    $billableHomeowners = report_rows($conn, "SELECT id, 250 AS monthly_due, COALESCE(DATE_FORMAT(dues_start_date, '%Y-%m'), DATE_FORMAT(created_at, '%Y-%m')) AS dues_start FROM homeowners WHERE {$homeArchived} AND LOWER(TRIM(status)) = 'active' AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned'" . ($block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : ''), $homeTypes, $homeParams);
    $rangeStartMonth = $from->format('Y-m'); $rangeEndMonth = $to->format('Y-m');
    $billingMonths = [];
    $monthCursor = new DateTimeImmutable($rangeStartMonth . '-01', $zone);
    $monthEnd = new DateTimeImmutable($rangeEndMonth . '-01', $zone);
    while ($monthCursor <= $monthEnd) { $billingMonths[] = $monthCursor->format('Y-m'); $monthCursor = $monthCursor->modify('+1 month'); }
    $expectedDues = 0.0;
    foreach ($billableHomeowners as $homeowner) foreach ($billingMonths as $billingMonth) if ($homeowner['dues_start'] <= $billingMonth) $expectedDues += (float) $homeowner['monthly_due'];
    $homeArchivedAlias = report_has_column($conn, 'homeowners', 'archived_at') ? 'h.archived_at IS NULL AND ' : '';
    $duesPayments = report_rows($conn, "SELECT p.homeowner_id, p.period_covered, SUM(p.amount_paid) AS paid FROM payments p INNER JOIN homeowners h ON h.id = p.homeowner_id WHERE p.archived_at IS NULL AND p.period_covered >= ? AND p.period_covered <= ? AND {$homeArchivedAlias}LOWER(TRIM(h.status)) = 'active' AND TRIM(h.block) <> '' AND LOWER(TRIM(h.block)) <> 'unassigned'" . ($block !== '' ? ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '') . ' GROUP BY p.homeowner_id, p.period_covered', $block !== '' ? 'sss' : 'ss', $block !== '' ? [$rangeStartMonth, $rangeEndMonth, $block] : [$rangeStartMonth, $rangeEndMonth]);
    $paidForDues = 0.0;
    $billableById = []; foreach ($billableHomeowners as $homeowner) $billableById[(int) $homeowner['id']] = (float) $homeowner['monthly_due'];
    foreach ($duesPayments as $payment) { $hid = (int) $payment['homeowner_id']; if (isset($billableById[$hid])) $paidForDues += min($billableById[$hid], (float) $payment['paid']); }
    $duesOutstandingEstimate = max(0, $expectedDues - $paidForDues);
    $feedbackTrendRows = $feedbackStatusRows = [];
    if ($feedbackExists) {
        $trendFeedbackWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ?";
        $trendFeedbackParams = [$fromValue, $endValue]; $trendFeedbackTypes = 'ss';
        if ($feedbackCategory !== 'all') { $trendFeedbackWhere .= ' AND f.category = ?'; $trendFeedbackParams[] = $feedbackCategory; $trendFeedbackTypes .= 's'; }
        if ($block !== '') { $trendFeedbackWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $trendFeedbackParams[] = $block; $trendFeedbackTypes .= 's'; }
        $trendFeedbackJoin = $block !== '' ? $feedbackJoin : 'FROM resident_feedback f';
        $feedbackTrendRows = report_rows($conn, "SELECT DATE_FORMAT(f.created_at, '%Y-%m') AS label, COUNT(*) AS total {$trendFeedbackJoin} WHERE {$trendFeedbackWhere} GROUP BY label ORDER BY label", $trendFeedbackTypes, $trendFeedbackParams);
        $concernStatusWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ? AND f.category = 'concern'";
        $concernStatusParams = [$fromValue, $endValue]; $concernStatusTypes = 'ss';
        if ($block !== '') { $concernStatusWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $concernStatusParams[] = $block; $concernStatusTypes .= 's'; }
        $concernJoin = $block !== '' ? $feedbackJoin : 'FROM resident_feedback f';
        $feedbackStatusRows = report_rows($conn, "SELECT f.status AS label, COUNT(*) AS total {$concernJoin} WHERE {$concernStatusWhere} GROUP BY f.status ORDER BY f.status", $concernStatusTypes, $concernStatusParams);
    }
} catch (Throwable $e) {
    error_log('Visual analytics report failed: ' . $e->getMessage());
    http_response_code(500); exit('The report could not be generated.');
}
$summaryRows = [
    ['Total homeowners', $homeTotal], ['Approved homeowners (active status)', $approved], ['Pending registrations', $pending],
    ['Reservations in selected period', $reservationTotal], ['Unresolved concerns in selected period', $concernUnresolved], ['Resolved concerns in selected period', $concernResolved],
    ['Payments collected in selected period', '₱' . number_format($paymentTotal, 2)], ['Payment transactions', $paymentCount], ['Estimated dues outstanding', '₱' . number_format($duesOutstandingEstimate, 2)],
];
$reportInsights = [];
if ($homeTotal > 0) {
    if (isset($streetRows[0])) $reportInsights[] = $streetRows[0]['label'] . ' has the largest number of current homeowner profiles (' . (int) $streetRows[0]['total'] . ').';
    $reportInsights[] = $approved . ' of ' . $homeTotal . ' current homeowner profiles are active.';
}
$registrationCount = array_sum(array_map(static fn($row) => (int) $row['total'], $registrationRows));
if ($registrationCount > 0) $reportInsights[] = $registrationCount . ' homeowner profiles were recorded during the selected period, including archived profiles.';
if ($reservationTotal > 0 && isset($reservationRows[0])) $reportInsights[] = $reservationRows[0]['label'] . ' is the most reserved facility in the selected period (' . (int) $reservationRows[0]['total'] . ' bookings).';
$reservationTrendCount = array_sum(array_map(static fn($row) => (int) $row['total'], $reservationTrendRows));
if ($reservationTrendCount > 0) $reportInsights[] = $reservationTrendCount . ' reservation requests were recorded during the selected period, including archived reservations.';
$concernCount = $concernUnresolved + $concernResolved;
if ($concernCount > 0) {
    $reportInsights[] = $concernCount . ' concerns were recorded during the selected period.';
    $reportInsights[] = $concernUnresolved . ' of those concerns remain unresolved.';
}
if (!$reportInsights) $reportInsights[] = 'No activity is available for an insight in the selected filters yet.';
$sections = [
    ['Monthly homeowner registrations', ['Month', 'Registrations'], array_map(static fn($row) => [(string) $row['label'], (int) $row['total']], $registrationRows)],
    ['Homeowners by current status', ['Status', 'Profiles'], array_map(static fn($row) => [ucfirst(str_replace('_', ' ', (string) $row['label'])), (int) $row['total']], $homeStatusRows)],
    ['Current homeowners by street', ['Street', 'Profiles'], array_map(static fn($row) => [(string) $row['label'], (int) $row['total']], $streetRows)],
    ['Reservations by facility', ['Facility', 'Reservations'], array_map(static fn($row) => [(string) $row['label'], (int) $row['total']], $reservationRows)],
    ['Monthly reservation activity', ['Month', 'Reservations'], array_map(static fn($row) => [(string) $row['label'], (int) $row['total']], $reservationTrendRows)],
    ['Reservation status in selected period', ['Status', 'Reservations'], array_map(static fn($row) => [ucfirst((string) $row['label']), (int) $row['total']], $reservationStatusRows)],
    ['Resident feedback by type', ['Feedback type', 'Items'], array_map(static fn($row) => [ucfirst((string) $row['label']), (int) $row['total']], $feedbackRows)],
    ['Monthly resident feedback activity', ['Month', 'Feedback items'], array_map(static fn($row) => [(string) $row['label'], (int) $row['total']], $feedbackTrendRows)],
    ['Concerns by street', ['Street', 'Concerns'], array_map(static fn($row) => [(string) $row['label'], (int) $row['total']], $concernStreetRows)],
    ['Concern status in selected period', ['Status', 'Concerns'], array_map(static fn($row) => [ucfirst(str_replace('_', ' ', (string) $row['label'])), (int) $row['total']], $feedbackStatusRows)],
    ['Payments by method', ['Payment method', 'Transactions', 'Amount collected'], array_map(static fn($row) => [(string) $row['label'], (int) $row['transactions'], '₱' . number_format((float) $row['amount'], 2)], $paymentRows)],
];
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Community Analytics Summary</title><style>
*{box-sizing:border-box}body{margin:0;padding:28px;font-family:Arial,Helvetica,sans-serif;color:#203128;background:#eef3ef}.toolbar{max-width:1050px;margin:0 auto 16px;display:flex;justify-content:flex-end;gap:8px}.toolbar button{border:0;border-radius:7px;padding:10px 15px;background:#176b3a;color:#fff;font-weight:700;cursor:pointer}.toolbar .secondary{background:#fff;color:#244533;border:1px solid #cad8cf}.report{max-width:1050px;margin:auto;padding:38px;background:#fff;border:1px solid #dce6df;border-radius:14px;box-shadow:0 12px 36px #24453312}.kicker{color:#176b3a;font-size:11px;font-weight:bold;letter-spacing:.15em;text-transform:uppercase}.report h1{margin:6px 0;color:#1b3024;font-size:29px}.meta{margin:0 0 24px;color:#64736b;font-size:12px;line-height:1.7}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:26px}.metric{padding:14px;border:1px solid #dce7df;border-radius:9px;background:linear-gradient(145deg,#fbfdfb,#f4f8f5)}.metric span{display:block;color:#637168;font-size:11px}.metric strong{display:block;margin-top:6px;color:#176b3a;font-size:21px}.section{margin-top:25px;break-inside:avoid}.section h2{margin:0 0 10px;padding-left:10px;border-left:3px solid #31915a;font-size:15px;color:#244533}.section table{width:100%;border-collapse:collapse}.section th,.section td{padding:9px 10px;border-bottom:1px solid #e6ece8;text-align:left;font-size:12px}.section th{background:#edf4ef;color:#294636;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.section td:last-child,.section th:last-child{text-align:right}.empty{color:#718077;font-style:italic}.footer{margin-top:30px;padding-top:12px;border-top:1px solid #dfe7e2;color:#728078;font-size:10px;display:flex;justify-content:space-between}@media(max-width:650px){body{padding:10px}.report{padding:18px}.metrics{grid-template-columns:repeat(2,1fr)}}@page{size:A4 portrait;margin:15mm 14mm 17mm}@media print{html,body{padding:0;background:#fff}.toolbar{display:none}.report{max-width:none;border:0;border-radius:0;padding:0;box-shadow:none}.metrics{grid-template-columns:repeat(3,1fr)}.metric,.section{break-inside:avoid;page-break-inside:avoid}.section table{break-inside:auto;page-break-inside:auto}.section thead{display:table-header-group}.section tr{break-inside:avoid;page-break-inside:avoid}.section th{print-color-adjust:exact;-webkit-print-color-adjust:exact}.footer{break-inside:avoid}}
</style></head>
<body><div class="toolbar"><button class="secondary" onclick="history.back()">Back to analytics</button><button onclick="window.print()">Print / Save PDF</button></div>
<main class="report"><p class="kicker">Manuela 4B · Visual Data Analytics</p><h1>Community activity summary</h1><p class="meta">Period: <?= report_h($fromValue) ?> to <?= report_h($to->format('Y-m-d')) ?><?= $block !== '' ? ' · Street: ' . report_h($block) : '' ?><br>Filters: Facility <?= report_h($facility !== '' ? $facility : 'All facilities') ?> · Reservation status <?= report_h($reservationStatus !== '' ? ucfirst($reservationStatus) : 'All statuses') ?> · Feedback category <?= report_h(ucfirst($feedbackCategory)) ?><br>Generated <?= report_h(date('M j, Y g:i A')) ?><br>Homeowner registrations and reservation activity include archived records; current homeowner snapshot, payment collection and concern counts exclude archived records. Feedback category filter applies to feedback breakdowns; concern counts always show concerns. Collection uses payment date. Estimated dues use the current active-homeowner snapshot at ₱250 per month, from each dues start month, and payments assigned to those billing months.</p>
<section class="metrics"><?php foreach ($summaryRows as [$label, $value]): ?><div class="metric"><span><?= report_h($label) ?></span><strong><?= report_h(is_numeric($value) ? number_format((float) $value, 0) : $value) ?></strong></div><?php endforeach; ?></section>
<section class="section"><h2>Automated insights</h2><ul><?php foreach ($reportInsights as $insight): ?><li><?= report_h($insight) ?></li><?php endforeach; ?></ul></section>
<?php foreach ($sections as [$title, $headers, $rows]): ?><section class="section"><h2><?= report_h($title) ?></h2><table><thead><tr><?php foreach ($headers as $header): ?><th><?= report_h($header) ?></th><?php endforeach; ?></tr></thead><tbody><?php if (!$rows): ?><tr><td class="empty" colspan="<?= count($headers) ?>">No records for this selection.</td></tr><?php else: foreach ($rows as $row): ?><tr><?php foreach ($row as $cell): ?><td><?= report_h($cell) ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?></tbody></table></section><?php endforeach; ?>
<footer class="footer"><span>Counts use the archive rules described above for each metric.</span><span>Manuela 4B MIS</span></footer></main><script>window.addEventListener('load',function(){setTimeout(function(){window.print()},350)});</script></body></html>
