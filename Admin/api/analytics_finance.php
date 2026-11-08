<?php
// Money-focused analytics endpoint for Admin/analytics.php.
// Separate from api/analytics.php so the dashboard keeps working untouched.
require_once '../../config/init.php';
require_once '../../config/db.php';
require_once '../../includes/archive_helpers.php';
require_once '../../includes/payment_methods.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DUE_DAY = 10; // paying after this day of the billing month counts as "late"

function fin_fail(int $code, string $msg): void { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') fin_fail(401, 'Administrator access is required.');

// Nothing below writes to the session, so release the lock: other requests
// (notifications, page preloading) no longer wait for these queries.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

function fin_rows(mysqli $c, string $sql, string $types = '', array $params = []): array {
    $s = $c->prepare($sql);
    if (!$s) throw new RuntimeException('Query could not be prepared.');
    if ($types !== '') $s->bind_param($types, ...$params);
    if (!$s->execute()) throw new RuntimeException('Query failed.');
    $r = $s->get_result();
    $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $s->close();
    return $rows;
}
/** Payments scoped by date (payment_date) and optional street. */
function pq(mysqli $c, string $select, string $tail, array $dates, string $block): array {
    $jn = $block !== '' ? ' INNER JOIN homeowners h ON h.id = p.homeowner_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '';
    return fin_rows($c, "$select FROM payments p$jn WHERE p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ? $tail",
        ($block !== '' ? 's' : '') . 'ss', array_merge($block !== '' ? [$block] : [], $dates));
}
/** One row per homeowner-month paid, with days late vs DUE_DAY; wrapped by $outer using {INNER}. */
function late_q(mysqli $c, string $outer, string $f, string $e, string $block): array {
    $d = str_pad((string) DUE_DAY, 2, '0', STR_PAD_LEFT);
    $jn = $block !== '' ? ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '';
    $inner = "SELECT h.id AS hid, h.full_name AS name, TRIM(h.block) AS street, h.lot AS lot,
        DATEDIFF(pp.last_paid, STR_TO_DATE(CONCAT(pp.period_covered, '-{$d}'), '%Y-%m-%d')) AS dl
        FROM (SELECT homeowner_id, period_covered, MAX(payment_date) AS last_paid FROM payments
              WHERE archived_at IS NULL AND period_covered REGEXP '^[0-9]{4}-[0-9]{2}\$' GROUP BY homeowner_id, period_covered) pp
        INNER JOIN homeowners h ON h.id = pp.homeowner_id AND h.archived_at IS NULL{$jn}
        WHERE pp.last_paid >= ? AND pp.last_paid < ?";
    return fin_rows($c, str_replace('{INNER}', $inner, $outer), ($block !== '' ? 's' : '') . 'ss', array_merge($block !== '' ? [$block] : [], [$f, $e]));
}
function res_q(mysqli $c, string $select, string $tail, string $f, string $e, string $block): array {
    $ex = $block !== '' ? ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))' : '';
    return fin_rows($c, "$select FROM reservations r WHERE r.archived_at IS NULL AND r.res_date >= ? AND r.res_date < ?$ex $tail",
        'ss' . ($block !== '' ? 's' : ''), array_merge([$f, $e], $block !== '' ? [$block] : []));
}
/** Billing standing as of a month, reusing the shared analytics_data.php rules. */
function fin_billing(mysqli $conn, string $fromYm, string $toYm, string $block): array {
    $analyticsFrom = $fromYm; $analyticsTo = $toYm; $selectedBlock = $block; $blockPaymentHomeownerIds = [];
    require __DIR__ . '/../../includes/analytics_data.php';
    $expected = 0.0; $paid = 0.0; $accounts = 0; $paidAccounts = 0;
    foreach ($homeowners as $h) {
        if (homeowner_dues_start_ym($h) > $current_period) continue;
        $due = (float) $h['monthly_due'];
        $got = (float) ($paymentsByHomeowner[(int) $h['id']][$current_period] ?? 0);
        $expected += $due;
        $paid += min($due, $got);
        $accounts++;
        if ($due <= 0 || $got >= $due) $paidAccounts++;
    }
    $streaks = []; $behind = 0;
    // "Behind" counts every account with a balance, so it matches the Outstanding total.
    foreach ($reliabilityList as $entry) { $streaks[(int) $entry['id']] = (int) $entry['streak']; if ($entry['amount_at_risk'] > 0) $behind++; }
    return [
        'as_of' => date('F Y', strtotime($current_period . '-01')),
        'expected' => round($expected, 2), 'collected' => round($paid, 2),
        'rate' => $expected > 0 ? (int) round($paid / $expected * 100) : null,
        'accounts' => $accounts, 'paid_accounts' => $paidAccounts,
        'outstanding' => round(array_sum(array_column($reliabilityList, 'amount_at_risk')), 2),
        'behind' => $behind, 'delinquent' => count($agingBuckets['61-90']) + count($agingBuckets['90+']),
        'aging' => ['labels' => ['1 month', '2 months', '3 months', '4+ months'],
            'counts' => [count($agingBuckets['0-30']), count($agingBuckets['31-60']), count($agingBuckets['61-90']), count($agingBuckets['90+'])]],
        'streaks' => $streaks,
    ];
}

/**
 * Moving-average forecast of monthly cash collected.
 * - History = completed months only (the current month is unfinished, so it is left out).
 * - Forecast for a month = average of the previous 3 months.
 * - Future months reuse earlier forecasts as if they were real, so the line flattens.
 * - Range = forecast +/- average backtest error, widening with sqrt(months ahead).
 */
function fin_forecast(array $byM, DateTimeImmutable $today): array {
    if (!$byM) return ['ok' => false, 'reason' => 'No payments have been recorded yet.'];
    $zone = $today->getTimezone();
    $lastDone = $today->modify('first day of this month')->modify('-1 month');
    $first = DateTimeImmutable::createFromFormat('!Y-m', (string) min(array_keys($byM)), $zone);
    if (!$first || $first > $lastDone) return ['ok' => false, 'reason' => 'Not enough completed months of payment history yet.'];

    $labels = []; $actual = [];
    for ($m = $first; $m <= $lastDone; $m = $m->modify('first day of next month')) {
        $labels[] = $m->format('M Y');
        $actual[] = round((float) ($byM[$m->format('Y-m')] ?? 0), 2);
    }
    $n = count($actual);
    if ($n < 6) return ['ok' => false, 'reason' => "Need at least 6 completed months of payments to forecast (found $n)."];

    $avg = static function (array $series, int $upto, int $w): float {
        return array_sum(array_slice($series, $upto - $w, $w)) / $w;
    };
    $stat = static function (array $rows): array {
        $abs = 0.0; $pct = 0.0; $pctN = 0;
        foreach ($rows as $r) {
            $err = abs($r['a'] - $r['f']); $abs += $err;
            if ($r['a'] > 0) { $pct += $err / $r['a'] * 100; $pctN++; }
        }
        $c = count($rows);
        return [$c ? $abs / $c : 0.0, $pctN ? $pct / $pctN : null];
    };

    $w = 3; // always a 3-month moving average
    // Backtest on up to the latest 12 months; each month only sees the 3 months before it.
    $rows = [];
    for ($t = max($w, $n - 12); $t < $n; $t++) $rows[] = ['t' => $t, 'f' => $avg($actual, $t, $w), 'a' => $actual[$t]];
    [$mae, $mape] = $stat($rows);

    $series = $actual; $points = [];
    for ($k = 1; $k <= 6; $k++) {
        $v = array_sum(array_slice($series, -$w)) / $w;
        $series[] = $v;
        $band = max($mae, $v * 0.05) * sqrt($k);
        $points[] = [
            'label' => $lastDone->modify("+$k month")->format('M Y'),
            'value' => round($v, 2),
            'low' => round(max(0, $v - $band), 2),
            'high' => round($v + $band, 2),
        ];
    }

    $backtest = [];
    foreach ($rows as $r) {
        $err = $r['f'] - $r['a'];
        $backtest[] = [
            'label' => $labels[$r['t']],
            'forecast' => round($r['f'], 2),
            'actual' => round($r['a'], 2),
            'error' => round($err, 2),
            'pct' => $r['a'] > 0 ? round(abs($err) / $r['a'] * 100, 1) : null,
        ];
    }

    return [
        'ok' => true,
        'window' => $w,
        'history_months' => $n,
        'first_month' => $labels[0],
        'last_month' => $labels[$n - 1],
        'labels' => $labels,
        'actual' => $actual,
        'points' => $points,
        'next1' => $points[0]['value'],
        'next3' => round(array_sum(array_column(array_slice($points, 0, 3), 'value')), 2),
        'next6' => round(array_sum(array_column($points, 'value')), 2),
        'last_actual' => $actual[$n - 1],
        'mape' => $mape === null ? null : round($mape, 1),
        'mae' => round($mae, 2),
        'backtest' => $backtest,
    ];
}

// ---------------- Input ----------------
$zone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $zone);
$range = is_string($_GET['range'] ?? null) ? $_GET['range'] : 'year';
switch ($range) {
    case 'month': $from = $today->modify('first day of this month'); $to = $today; break;
    case '30d': $from = $today->modify('-29 days'); $to = $today; break;
    case '90d': $from = $today->modify('-89 days'); $to = $today; break;
    case 'year': $from = $today->setDate((int) $today->format('Y'), 1, 1); $to = $today; break;
    case 'last_year': $y = (int) $today->format('Y') - 1; $from = $today->setDate($y, 1, 1); $to = $today->setDate($y, 12, 31); break;
    case 'custom':
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['from'] ?? ''), $zone);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['to'] ?? ''), $zone);
        if (!$from || !$to) fin_fail(400, 'Enter valid custom dates.');
        break;
    default: fin_fail(400, 'Choose a valid date range.');
}
if ($from > $to || $from->diff($to)->days > 3660) fin_fail(400, 'Choose a valid range of ten years or less.');
$block = is_string($_GET['block'] ?? null) ? trim($_GET['block']) : '';
if (strlen($block) > 50) fin_fail(400, 'Invalid street.');

$f = $from->format('Y-m-d');
$e = $to->modify('+1 day')->format('Y-m-d');
$days = $from->diff($to)->days + 1;
$pTo = $from->modify('-1 day');
$pf = $pTo->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
$previousLabel = $pTo->modify('-' . ($days - 1) . ' days')->format('M j, Y') . ' – ' . $pTo->format('M j, Y');
$rangeLabel = $from->format('Y') === $to->format('Y')
    ? $from->format('M j') . ' – ' . $to->format('M j, Y')
    : $from->format('M j, Y') . ' – ' . $to->format('M j, Y');

try {
    ensure_archive_columns($conn, 'homeowners');
    ensure_archive_columns($conn, 'payments');
    ensure_archive_columns($conn, 'reservations');
    $conn->set_charset('utf8mb4');

    // ---------- Sales (cash received) ----------
    $daily = []; $total = 0.0; $count = 0;
    foreach (pq($conn, 'SELECT p.payment_date AS d, SUM(p.amount_paid) AS amt, COUNT(*) AS n', 'GROUP BY p.payment_date', [$f, $e], $block) as $r) {
        $daily[$r['d']] = [(float) $r['amt'], (int) $r['n']];
        $total += (float) $r['amt']; $count += (int) $r['n'];
    }
    $prevTotal = (float) (pq($conn, 'SELECT COALESCE(SUM(p.amount_paid), 0) AS t', '', [$pf, $f], $block)[0]['t'] ?? 0);

    $dl = []; $dv = [];
    for ($d = max($from, $to->modify('-61 days')); $d <= $to; $d = $d->modify('+1 day')) {
        $dl[] = $d->format('M j'); $dv[] = round($daily[$d->format('Y-m-d')][0] ?? 0, 2);
    }
    $wk = []; $mo = [];
    foreach ($daily as $k => [$amt, $n]) {
        $dt = new DateTimeImmutable($k, $zone);
        $w = $dt->modify('monday this week')->format('Y-m-d'); $wk[$w] = ($wk[$w] ?? 0) + $amt;
        $m = $dt->format('Y-m'); $mo[$m] = ($mo[$m] ?? 0) + $amt;
    }
    $wl = []; $wv = [];
    for ($w = $from->modify('monday this week'); $w <= $to; $w = $w->modify('+7 days')) { $wl[] = 'Wk ' . $w->format('M j'); $wv[] = round($wk[$w->format('Y-m-d')] ?? 0, 2); }
    $ml = []; $mv = [];
    for ($m = $from->modify('first day of this month'); $m <= $to; $m = $m->modify('first day of next month')) { $ml[] = $m->format('M Y'); $mv[] = round($mo[$m->format('Y-m')] ?? 0, 2); }

    $dow = array_fill(1, 7, [0.0, 0]);
    foreach ($daily as $k => [$amt, $n]) { $i = (int) date('N', strtotime($k)); $dow[$i][0] += $amt; $dow[$i][1] += $n; }

    $methods = pq($conn, 'SELECT ' . payment_method_sql_expression('p') . ' AS label, COUNT(*) AS n, SUM(p.amount_paid) AS amt', 'GROUP BY label ORDER BY amt DESC', [$f, $e], $block);

    // ---------- Late payment analytics ----------
    $leader = late_q($conn, "SELECT hid, name, street, lot, COUNT(*) AS periods, SUM(dl > 0) AS late, ROUND(AVG(CASE WHEN dl > 0 THEN dl END), 1) AS avg_late, MAX(dl) AS max_late FROM ({INNER}) t GROUP BY hid, name, street, lot HAVING late > 0 ORDER BY late DESC, avg_late DESC, name LIMIT 10", $f, $e, $block);
    $bucketRows = late_q($conn, "SELECT CASE WHEN dl <= 0 THEN 'On time' WHEN dl <= 5 THEN '1–5 days late' WHEN dl <= 10 THEN '6–10 days late' WHEN dl <= 20 THEN '11–20 days late' ELSE '21+ days late' END AS b, COUNT(*) AS n FROM ({INNER}) t GROUP BY b", $f, $e, $block);
    $bmap = array_column($bucketRows, 'n', 'b');
    $bLabels = ['On time', '1–5 days late', '6–10 days late', '11–20 days late', '21+ days late'];
    $byStreet = late_q($conn, "SELECT street, COUNT(*) AS n, SUM(dl > 0) AS late FROM ({INNER}) t GROUP BY street ORDER BY street", $f, $e, $block);
    $paidPeriods = array_sum(array_map('intval', array_column($byStreet, 'n')));
    $latePeriods = array_sum(array_map('intval', array_column($byStreet, 'late')));

    // ---------- Reservations by week ----------
    $weekly = []; $fac = ['Court' => 0, 'Tennis Court' => 0, 'Clubhouse' => 0]; $resTotal = 0; $resApproved = 0; $resRevenue = 0.0;
    // Older bookings used these names; they are counted under Court. Stored records are not changed.
    $facAliases = ['Basketball Court' => 'Court', 'Badminton Court' => 'Court'];
    foreach (res_q($conn, 'SELECT r.res_date AS d, r.facility AS fac, r.status AS st, r.payment_amount AS amt, r.payment_status AS paid_st', '', $f, $e, $block) as $r) {
        $w = (new DateTimeImmutable($r['d'], $zone))->modify('monday this week')->format('Y-m-d');
        $resTotal++;
        if ($r['st'] === 'approved') { $weekly[$w][0] = ($weekly[$w][0] ?? 0) + 1; $facName = trim((string) $r['fac']); $facName = $facAliases[$facName] ?? $facName; $fac[$facName] = ($fac[$facName] ?? 0) + 1; $resApproved++; if ($r['paid_st'] === 'paid') $resRevenue += (float) $r['amt']; }
        else $weekly[$w][1] = ($weekly[$w][1] ?? 0) + 1;
    }
    $rwl = []; $rwa = []; $rwo = [];
    for ($w = $from->modify('monday this week'); $w <= $to; $w = $w->modify('+7 days')) {
        $k = $w->format('Y-m-d'); $rwl[] = $w->format('M j'); $rwa[] = $weekly[$k][0] ?? 0; $rwo[] = $weekly[$k][1] ?? 0;
    }
    // Same-length window just before this range, for the "vs previous period" pill
    $resApprovedPrev = (int) (res_q($conn, 'SELECT COUNT(*) AS n', "AND r.status = 'approved'", $pf, $f, $block)[0]['n'] ?? 0);

    // ---------- Resident feedback (selected date range and street) ----------
    $feedbackBlock = $block !== ''
        ? ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = f.user_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))'
        : '';
    $feedbackRows = [];
    try {
        ensure_archive_columns($conn, 'resident_feedback');
        $feedbackRows = fin_rows($conn,
            "SELECT DATE_FORMAT(f.created_at, '%Y-%m') AS ym, f.category, f.status, COUNT(*) AS n
             FROM resident_feedback f
             WHERE f.archived_at IS NULL AND f.created_at >= ? AND f.created_at < ?{$feedbackBlock}
             GROUP BY ym, f.category, f.status ORDER BY ym",
            'ss' . ($block !== '' ? 's' : ''), array_merge([$f, $e], $block !== '' ? [$block] : []));
    } catch (Throwable $feedbackError) {
        error_log('Analytics feedback aggregation is unavailable: ' . $feedbackError->getMessage());
    }
    $feedbackByMonth = []; $feedbackStatus = ['new' => 0, 'in_review' => 0, 'resolved' => 0];
    $feedbackTotal = 0;
    $monthCursor = $from->modify('first day of this month');
    $monthEnd = $to->modify('first day of next month');
    for (; $monthCursor < $monthEnd; $monthCursor = $monthCursor->modify('+1 month')) {
        $ym = $monthCursor->format('Y-m');
        $feedbackByMonth[$ym] = ['suggestion' => 0, 'concern' => 0, 'incident' => 0, 'status' => ['new' => 0, 'in_review' => 0, 'resolved' => 0]];
    }
    foreach ($feedbackRows as $row) {
        $ym = (string) $row['ym'];
        $category = (string) $row['category'];
        $status = (string) $row['status'];
        $n = (int) $row['n'];
        if (isset($feedbackByMonth[$ym]) && isset($feedbackByMonth[$ym][$category])) $feedbackByMonth[$ym][$category] += $n;
        if (isset($feedbackStatus[$status])) $feedbackStatus[$status] += $n;
        if (isset($feedbackByMonth[$ym]['status'][$status])) $feedbackByMonth[$ym]['status'][$status] += $n;
        $feedbackTotal += $n;
    }
    $feedbackMonths = array_keys($feedbackByMonth);
    $feedback = [
        'month_keys' => $feedbackMonths,
        'labels' => array_map(static fn($ym) => date('M Y', strtotime($ym . '-01')), $feedbackMonths),
        'suggestions' => array_map(static fn($ym) => $feedbackByMonth[$ym]['suggestion'], $feedbackMonths),
        'concerns' => array_map(static fn($ym) => $feedbackByMonth[$ym]['concern'], $feedbackMonths),
        'incidents' => array_map(static fn($ym) => $feedbackByMonth[$ym]['incident'], $feedbackMonths),
        'incident_total' => array_sum(array_column($feedbackByMonth, 'incident')),
        'month_status' => array_map(static fn($ym) => array_values($feedbackByMonth[$ym]['status']), $feedbackMonths),
        'status_labels' => ['New', 'In review', 'Resolved'],
        'status_values' => [$feedbackStatus['new'], $feedbackStatus['in_review'], $feedbackStatus['resolved']],
        'total' => $feedbackTotal,
        'open' => $feedbackStatus['new'] + $feedbackStatus['in_review'],
        'resolution_rate' => $feedbackTotal > 0 ? (int) round($feedbackStatus['resolved'] / $feedbackTotal * 100) : null,
    ];

    arsort($fac);
    $Y = (int) $to->format('Y');
    $heat = [];
    // ISO weeks can cross New Year, so read a little past both ends and keep only this ISO year.
    foreach (res_q($conn, 'SELECT r.res_date AS d', "AND r.status = 'approved'", ($Y - 1) . '-12-25', ($Y + 1) . '-01-08', $block) as $r) {
        $ts = strtotime($r['d']);
        if ((int) date('o', $ts) !== $Y) continue;
        $iso = (int) date('W', $ts); $heat[$iso] = ($heat[$iso] ?? 0) + 1;
    }
    $weeksInYear = (int) date('W', strtotime("$Y-12-28"));
    $heatCells = [];
    for ($w = 1; $w <= $weeksInYear; $w++) $heatCells[] = ['w' => $w, 'n' => $heat[$w] ?? 0, 'start' => (new DateTime())->setISODate($Y, $w)->format('M j')];

    // ---------- Year over year + trend forecast ----------
    $byM = [];
    $sql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, SUM(p.amount_paid) AS amt FROM payments p"
        . ($block !== '' ? ' INNER JOIN homeowners h ON h.id = p.homeowner_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '')
        . " WHERE p.archived_at IS NULL AND p.payment_date >= '2000-01-01' GROUP BY ym"; // skips blank 0000-00-00 dates
    foreach (fin_rows($conn, $sql, $block !== '' ? 's' : '', $block !== '' ? [$block] : []) as $r) $byM[$r['ym']] = (float) $r['amt'];
    $g = fn($y, $m) => (float) ($byM[sprintf('%04d-%02d', $y, $m)] ?? 0);
    $thisY = (int) $today->format('Y'); $thisM = (int) $today->format('n');
    $m0 = $Y === $thisY ? $thisM : 12;
    $cur = []; $prev = []; $fc = array_fill(0, 12, null);
    for ($m = 1; $m <= 12; $m++) { $prev[] = round($g($Y - 1, $m), 2); $cur[] = $m <= $m0 ? round($g($Y, $m), 2) : null; }
    $sc = 0; $sp = 0;
    for ($m = 1; $m < $m0; $m++) { $sc += $g($Y, $m); $sp += $g($Y - 1, $m); }
    $growth = $sp > 0 ? min(2, max(.5, $sc / $sp)) : 1;
    $fullEst = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        if ($m <= $m0) { $fullEst += $g($Y, $m); continue; }
        if ($Y !== $thisY) continue;
        $v = $g($Y - 1, $m) > 0 ? $g($Y - 1, $m) * $growth : ($m0 > 1 ? $sc / ($m0 - 1) : 0);
        $fc[$m - 1] = round($v, 2); $fullEst += $v;
    }
    $firstY = $byM ? (int) substr(min(array_keys($byM)), 0, 4) : $Y;
    $yrs = []; $tot = [];
    for ($y = $firstY; $y <= $Y; $y++) {
        $t = ($y === $Y && $Y === $thisY) ? $fullEst : array_sum(array_map(fn($m) => $g($y, $m), range(1, 12)));
        if ($t > 0 || $yrs) { $yrs[] = $y; $tot[] = round($t, 2); }
    }
    $n = count($tot); $slope = 0; $icpt = $n ? $tot[$n - 1] : 0; $sd = 0;
    if ($n >= 2) {
        $sx = array_sum(range(0, $n - 1)); $sy = array_sum($tot); $sxy = 0; $sxx = 0;
        foreach ($tot as $i => $v) { $sxy += $i * $v; $sxx += $i * $i; }
        $den = $n * $sxx - $sx * $sx;
        $slope = $den ? ($n * $sxy - $sx * $sy) / $den : 0; $icpt = ($sy - $slope * $sx) / $n;
        foreach ($tot as $i => $v) $sd += ($v - ($icpt + $slope * $i)) ** 2;
        $sd = sqrt($sd / max(1, $n - 2));
    }
    $fy = [];
    for ($k = 1; $k <= 3; $k++) {
        $v = max(0, $icpt + $slope * ($n - 1 + $k)); $band = max($v * .1, $sd);
        $fy[] = ['year' => $Y + $k, 'value' => round($v, 2), 'low' => round(max(0, $v - $band), 2), 'high' => round($v + $band, 2)];
    }
    // Finished months only: an unfinished month must not be compared with a full one.
    $ytdMonths = $Y === $thisY ? $thisM - 1 : 12;
    $ytd = 0.0; $ytdPrev = 0.0;
    for ($m = 1; $m <= $ytdMonths; $m++) { $ytd += $g($Y, $m); $ytdPrev += $g($Y - 1, $m); }

    // ---------- Moving-average forecast (follows the street filter, not the date range) ----------
    $forecast = fin_forecast($byM, $today);

    // ---------- Latest activity timeline ----------
    $timeline = fin_rows($conn, "(SELECT 'payment' AS type, p.payment_date AS d, h.full_name AS who, p.amount_paid AS amt, " . payment_method_sql_expression('p') . " AS note
            FROM payments p INNER JOIN homeowners h ON h.id = p.homeowner_id WHERE p.archived_at IS NULL ORDER BY p.payment_date DESC, p.id DESC LIMIT 8)
        UNION ALL
        (SELECT 'reservation', r.res_date, COALESCE(h.full_name, r.archived_resident_name, 'Resident'), r.total_amount, r.facility
            FROM reservations r LEFT JOIN homeowners h ON h.user_id = r.user_id AND h.archived_at IS NULL
            WHERE r.archived_at IS NULL AND r.status = 'approved' AND r.res_date <= CURDATE() ORDER BY r.res_date DESC, r.id DESC LIMIT 8)
        ORDER BY d DESC LIMIT 12");

    $billing = fin_billing($conn, max($from->format('Y-m'), date('Y-m', strtotime($to->format('Y-m') . '-01 -11 months'))), $to->format('Y-m'), $block);
    // The billed month still counts as unpaid before its due day; the page says so instead of hiding it.
    $billing['due_date_label'] = ($to->format('Y-m') === $today->format('Y-m') && (int) $today->format('j') <= DUE_DAY)
        ? $today->format('M') . ' ' . DUE_DAY : null;

    echo json_encode([
        'range' => ['type' => $range, 'from' => $f, 'to' => $to->format('Y-m-d'), 'label' => $rangeLabel, 'previous_label' => $previousLabel, 'days' => $days, 'due_day' => DUE_DAY],
        'kpis' => ['collected' => round($total, 2), 'previous' => round($prevTotal, 2), 'payments' => $count,
            'late_rate' => $paidPeriods ? (int) round($latePeriods / $paidPeriods * 100) : null,
            'reservations' => $resApproved, 'reservations_previous' => $resApprovedPrev,
            'reservation_revenue' => round($resRevenue, 2), 'reservation_requests' => $resTotal],
        'billing' => $billing,
        'sales' => ['days' => ['labels' => $dl, 'values' => $dv, 'cut_off' => $days > 62], 'weeks' => ['labels' => array_slice($wl, -53), 'values' => array_slice($wv, -53), 'cut_off' => count($wl) > 53], 'months' => ['labels' => $ml, 'values' => $mv]],
        'dow' => ['labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 'amounts' => array_map(fn($x) => round($x[0], 2), array_values($dow)), 'counts' => array_map(fn($x) => $x[1], array_values($dow))],
        'methods' => ['labels' => array_map(fn($r) => (string) $r['label'], $methods), 'amounts' => array_map(fn($r) => round((float) $r['amt'], 2), $methods), 'counts' => array_map(fn($r) => (int) $r['n'], $methods)],
        'late' => [
            'leaders' => array_map(fn($r) => ['id' => (int) $r['hid'], 'name' => $r['name'], 'street' => $r['street'], 'lot' => $r['lot'], 'periods' => (int) $r['periods'], 'late' => (int) $r['late'], 'avg_late' => (float) $r['avg_late'], 'max_late' => (int) $r['max_late']], $leader),
            'bucket_labels' => $bLabels, 'bucket_values' => array_map(fn($l) => (int) ($bmap[$l] ?? 0), $bLabels),
            'street_labels' => array_column($byStreet, 'street'),
            'street_rates' => array_map(fn($r) => (int) round(((int) $r['late'] / max(1, (int) $r['n'])) * 100), $byStreet),
            'street_late' => array_map(fn($r) => (int) $r['late'], $byStreet),
            'street_paid' => array_map(fn($r) => (int) $r['n'], $byStreet),
        ],
        'feedback' => $feedback,
        'reservations' => ['week_labels' => $rwl, 'approved' => $rwa, 'other' => $rwo, 'facility_labels' => array_keys($fac), 'facility_values' => array_values($fac), 'heat_year' => $Y, 'heat' => $heatCells],
        'yoy' => ['year' => $Y, 'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], 'previous' => $prev, 'current' => $cur, 'forecast' => $fc,
            'years' => $yrs, 'totals' => $tot, 'estimated_current' => $Y === $thisY, 'forecast_years' => $fy,
            'ytd' => round($ytd, 2), 'ytd_previous' => round($ytdPrev, 2), 'ytd_months' => $ytdMonths, 'full_year_estimate' => round($fullEst, 2)],
        'forecast' => $forecast,
        'timeline' => array_map(fn($r) => ['type' => $r['type'], 'date' => $r['d'], 'who' => $r['who'], 'amount' => round((float) $r['amt'], 2), 'note' => (string) $r['note']], $timeline),
        'updated' => (new DateTimeImmutable('now', $zone))->format(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $ex) {
    error_log('Finance analytics failed: ' . $ex->getMessage());
    fin_fail(500, 'Analytics could not be loaded right now.');
}