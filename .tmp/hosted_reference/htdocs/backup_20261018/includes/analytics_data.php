<?php
// Shared data layer for analytics.php and report.php
// Requires $conn (mysqli) to already be available.

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/homeowner_helpers.php';

// ---------- Helper: inclusive list of "Y-m" periods ----------
if (!function_exists('generate_period_range')) {
    function generate_period_range($start_ym, $end_ym) {
        $periods = [];
        $start = DateTime::createFromFormat('!Y-m', $start_ym);
        $end = DateTime::createFromFormat('!Y-m', $end_ym);
        if (!$start || !$end || $start > $end) return $periods;
        while ($start <= $end) {
            $periods[] = $start->format('Y-m');
            $start->modify('+1 month');
        }
        return $periods;
    }
}

$current_period = isset($analyticsTo) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $analyticsTo)
    ? $analyticsTo
    : date('Y-m');
$analyticsFrom = isset($analyticsFrom) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $analyticsFrom)
    ? $analyticsFrom
    : date('Y-m', strtotime('-5 months', strtotime($current_period . '-01')));
$selectedBlock = isset($selectedBlock) ? trim((string) $selectedBlock) : '';
$blockPaymentHomeownerIds = isset($blockPaymentHomeownerIds) ? array_map('intval', $blockPaymentHomeownerIds) : [];

// ---------- Billable homeowners ----------
$homeowners = [];
try {
    $blockSql = $selectedBlock !== ''
        ? " AND LOWER(TRIM(block)) = LOWER('" . $conn->real_escape_string($selectedBlock) . "')"
        : '';
    $res = $conn->query("SELECT * FROM homeowners WHERE archived_at IS NULL AND TRIM(block) != '' AND LOWER(TRIM(block)) != 'unassigned' AND LOWER(TRIM(status)) = 'active' AND COALESCE(DATE_FORMAT(dues_start_date, '%Y-%m'), DATE_FORMAT(created_at, '%Y-%m')) <= '{$current_period}'{$blockSql} ORDER BY block, lot");
    if ($res) $homeowners = $res->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) { $homeowners = []; }

// ---------- All payments ----------
$paymentsByHomeowner = [];
$allPayments = [];
try {
    $res = $conn->query("SELECT * FROM payments WHERE archived_at IS NULL AND period_covered <= '{$current_period}' ORDER BY payment_date ASC");
    if ($res) $allPayments = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($allPayments as $p) {
        // Multiple transactions can cover one billing period; aggregate them
        // before classifying the period as paid or overdue.
        $hid = (int) $p['homeowner_id'];
        $period = (string) $p['period_covered'];
        $paymentsByHomeowner[$hid][$period] = ($paymentsByHomeowner[$hid][$period] ?? 0) + (float) $p['amount_paid'];
    }
} catch (Exception $e) { $allPayments = []; }

// ================================================================
// MODULE 1: COLLECTION TREND + FORECAST
// ================================================================
$monthlyTotals = [];
foreach ($allPayments as $p) {
    if ($selectedBlock !== '' && !in_array((int) $p['homeowner_id'], $blockPaymentHomeownerIds, true)) continue;
    $key = $p['period_covered'];
    $monthlyTotals[$key] = ($monthlyTotals[$key] ?? 0) + (float) $p['amount_paid'];
}
ksort($monthlyTotals);
$trendPeriods = generate_period_range($analyticsFrom, $current_period);
$trendLabels = [];
$trendData = [];
foreach ($trendPeriods as $period) {
    $trendLabels[] = date('M Y', strtotime($period . '-01'));
    $trendData[] = round($monthlyTotals[$period] ?? 0, 2);
}

$thisMonthTotal = $monthlyTotals[$current_period] ?? 0;
$prevPeriod = date('Y-m', strtotime($current_period . '-01 -1 month'));
$prevMonthTotal = $monthlyTotals[$prevPeriod] ?? 0;
$momChange = $prevMonthTotal > 0 ? (($thisMonthTotal - $prevMonthTotal) / $prevMonthTotal) * 100 : null;

// Simple linear forecast (least squares over the last up to 6 months)
$forecastNext = 0;
if (count($trendData) >= 2) {
    $forecastData = array_slice($trendData, -6);
    $n = count($forecastData);
    $xSum = $ySum = $xySum = $xxSum = 0;
    foreach ($forecastData as $i => $y) {
        $xSum += $i; $ySum += $y; $xySum += $i * $y; $xxSum += $i * $i;
    }
    $denom = ($n * $xxSum - $xSum * $xSum);
    if ($denom != 0) {
        $slope = ($n * $xySum - $xSum * $ySum) / $denom;
        $intercept = ($ySum - $slope * $xSum) / $n;
        $forecastNext = max(0, $slope * $n + $intercept);
    } else {
        $forecastNext = end($trendData);
    }
} else {
    $forecastNext = $thisMonthTotal;
}

// ================================================================
// MODULES 2, 3, 6: AGING, RELIABILITY, AT-RISK
// ================================================================
$agingBuckets = ['current' => [], '0-30' => [], '31-60' => [], '61-90' => [], '90+' => []];
$reliabilityList = [];

foreach ($homeowners as $h) {
    $hid = $h['id'];
    $duesStartYm = homeowner_dues_start_ym($h);
    $expectedPeriods = generate_period_range($duesStartYm, $current_period);
    $paidByPeriod = $paymentsByHomeowner[$hid] ?? [];

    $expectedCount = count($expectedPeriods);
    $due = (float) $h['monthly_due'];
    $paidCount = 0;
    $outstanding = 0;
    foreach ($expectedPeriods as $period) {
        $periodPaid = (float) ($paidByPeriod[$period] ?? 0);
        if ($periodPaid >= $due) $paidCount++;
        $outstanding += max(0, $due - $periodPaid);
    }
    $score = $expectedCount > 0 ? round(($paidCount / $expectedCount) * 100) : 100;

    $streak = 0;
    for ($i = count($expectedPeriods) - 1; $i >= 0; $i--) {
        if ((float) ($paidByPeriod[$expectedPeriods[$i]] ?? 0) < $due) {
            $streak++;
        } else {
            break;
        }
    }

    $entry = [
        'id' => $hid,
        'name' => $h['full_name'],
        'block' => $h['block'],
        'lot' => $h['lot'],
        'contact' => $h['contact'],
        'monthly_due' => (float) $h['monthly_due'],
        'streak' => $streak,
        'score' => $score,
        'amount_at_risk' => $outstanding,
    ];

    if ($streak === 0) $agingBuckets['current'][] = $entry;
    elseif ($streak === 1) $agingBuckets['0-30'][] = $entry;
    elseif ($streak === 2) $agingBuckets['31-60'][] = $entry;
    elseif ($streak === 3) $agingBuckets['61-90'][] = $entry;
    else $agingBuckets['90+'][] = $entry;

    $reliabilityList[] = $entry;
}

usort($reliabilityList, fn($a, $b) => $a['score'] <=> $b['score']);

$atRiskList = $reliabilityList;
usort($atRiskList, fn($a, $b) => $b['streak'] <=> $a['streak'] ?: $b['amount_at_risk'] <=> $a['amount_at_risk']);
$atRiskList = array_filter($atRiskList, fn($e) => $e['streak'] > 0);
$atRiskList = array_slice($atRiskList, 0, 5);
