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
    $homeownerSql = "SELECT * FROM homeowners
        WHERE archived_at IS NULL AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned'
          AND LOWER(TRIM(status)) = 'active'
          AND COALESCE(DATE_FORMAT(dues_start_date, '%Y-%m'), DATE_FORMAT(created_at, '%Y-%m')) <= ?";
    $homeownerTypes = 's';
    $homeownerParams = [$current_period];
    if ($selectedBlock !== '') {
        $homeownerSql .= ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))';
        $homeownerTypes .= 's';
        $homeownerParams[] = $selectedBlock;
    }
    $homeownerSql .= ' ORDER BY block, lot';
    $homeownerStmt = $conn->prepare($homeownerSql);
    if ($homeownerStmt) {
        $homeownerStmt->bind_param($homeownerTypes, ...$homeownerParams);
        if ($homeownerStmt->execute()) $homeowners = $homeownerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $homeownerStmt->close();
    }
} catch (Exception $e) { $homeowners = []; }

// ---------- All payments ----------
$paymentsByHomeowner = [];
$allPayments = [];
try {
    $paymentStmt = $conn->prepare('SELECT homeowner_id, period_covered, amount_paid FROM payments WHERE archived_at IS NULL AND period_covered <= ?');
    if ($paymentStmt) {
        $paymentStmt->bind_param('s', $current_period);
        if ($paymentStmt->execute()) $res = $paymentStmt->get_result();
        else $res = false;
    } else {
        $res = false;
    }
    if ($res) $allPayments = $res->fetch_all(MYSQLI_ASSOC);
    if (isset($paymentStmt) && $paymentStmt) $paymentStmt->close();
    foreach ($allPayments as $p) {
        $period = (string) $p['period_covered'];
        // Multiple transactions can cover one billing period; aggregate them
        // before classifying the period as paid or overdue.
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) && $period <= $current_period) {
            $hid = (int) $p['homeowner_id'];
            $paymentsByHomeowner[$hid][$period] = ($paymentsByHomeowner[$hid][$period] ?? 0) + (float) $p['amount_paid'];
        }
    }
} catch (Exception $e) { $allPayments = []; }

// ================================================================
// MODULE 1: COLLECTION TREND + FORECAST
// ================================================================
$monthlyTotals = [];
try {
    $previousTrendPeriod = date('Y-m', strtotime($current_period . '-01 -1 month'));
    $trendStartPeriod = min($analyticsFrom, $previousTrendPeriod);
    $trendFrom = $trendStartPeriod . '-01';
    $trendEnd = (new DateTimeImmutable($current_period . '-01', new DateTimeZone('Asia/Manila')))->modify('first day of next month')->format('Y-m-d');
    $join = $selectedBlock !== '' ? ' INNER JOIN homeowners h ON h.id = p.homeowner_id AND h.archived_at IS NULL' : '';
    $blockWhere = $selectedBlock !== '' ? ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '';
    $trendSql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS period, COALESCE(SUM(p.amount_paid), 0) AS total
                 FROM payments p{$join}
                 WHERE p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ?{$blockWhere}
                 GROUP BY period ORDER BY period";
    $trendStmt = $conn->prepare($trendSql);
    if ($trendStmt) {
        if ($selectedBlock !== '') $trendStmt->bind_param('sss', $trendFrom, $trendEnd, $selectedBlock);
        else $trendStmt->bind_param('ss', $trendFrom, $trendEnd);
        if ($trendStmt->execute()) {
            $trendResult = $trendStmt->get_result();
            while ($row = $trendResult->fetch_assoc()) $monthlyTotals[$row['period']] = (float) $row['total'];
            $trendResult->free();
        }
        $trendStmt->close();
    }
} catch (Exception $e) {
    $monthlyTotals = [];
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
$prevPeriod = $previousTrendPeriod ?? date('Y-m', strtotime($current_period . '-01 -1 month'));
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

// ================================================================
// BILLING COVERAGE + RELIABILITY MIX + STREET RATES
// ================================================================
$expectedByPeriod = [];
$billedCollectedByPeriod = [];
$blockStatsByPeriod = [];
foreach ($trendPeriods as $period) {
    $expectedByPeriod[$period] = 0.0;
    $billedCollectedByPeriod[$period] = 0.0;
    foreach ($homeowners as $h) {
        if (homeowner_dues_start_ym($h) > $period) continue;
        $due = (float) $h['monthly_due'];
        $paid = (float) ($paymentsByHomeowner[(int) $h['id']][$period] ?? 0);
        $expectedByPeriod[$period] += $due;
        $billedCollectedByPeriod[$period] += min($due, $paid);
        $block = trim((string) $h['block']);
        if ($block === '') continue;
        if (!isset($blockStatsByPeriod[$period][$block])) {
            $blockStatsByPeriod[$period][$block] = ['total' => 0, 'paid' => 0];
        }
        $blockStatsByPeriod[$period][$block]['total']++;
        if ($paid >= $due) $blockStatsByPeriod[$period][$block]['paid']++;
    }
}

$scoreBuckets = ['0–49%' => 0, '50–79%' => 0, '80–99%' => 0, '100%' => 0];
foreach ($reliabilityList as $entry) {
    $score = (int) $entry['score'];
    if ($score >= 100) $scoreBuckets['100%']++;
    elseif ($score >= 80) $scoreBuckets['80–99%']++;
    elseif ($score >= 50) $scoreBuckets['50–79%']++;
    else $scoreBuckets['0–49%']++;
}
