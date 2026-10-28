<?php
require_once __DIR__ . '/../../config/init.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function analytics_send(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function analytics_date_value(string $value): ?DateTimeImmutable
{
    $zone = new DateTimeZone('Asia/Manila');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format('Y-m-d') === $value ? $date : null;
}

function analytics_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Analytics query could not be prepared.');
    if ($types !== '' && !$stmt->bind_param($types, ...$params)) throw new RuntimeException('Analytics filters could not be applied.');
    if (!$stmt->execute()) throw new RuntimeException('Analytics query could not be completed.');
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function analytics_has_column(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    analytics_send(['error' => 'Administrator access is required.'], 401);
}

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
        $from = analytics_date_value(is_string($_GET['from'] ?? null) ? $_GET['from'] : '');
        $to = analytics_date_value(is_string($_GET['to'] ?? null) ? $_GET['to'] : '');
        if (!$from || !$to) analytics_send(['error' => 'Enter a valid custom date range.'], 400);
        break;
    default: analytics_send(['error' => 'Choose a valid date range.'], 400);
}
if ($from > $to || (int) $from->diff($to)->format('%a') > 3660) analytics_send(['error' => 'Choose a valid date range of ten years or less.'], 400);
$end = $to->modify('+1 day');
$fromValue = $from->format('Y-m-d');
$endValue = $end->format('Y-m-d');

// Previous comparison window — same length, immediately before the selected range
$spanDays = (int) $from->diff($to)->format('%a') + 1;
$prevFromValue = $from->modify('-' . $spanDays . ' day')->format('Y-m-d');
$prevEndValue = $fromValue; // exclusive upper bound

$block = is_string($_GET['block'] ?? null) ? trim($_GET['block']) : '';
$facility = is_string($_GET['facility'] ?? null) ? trim($_GET['facility']) : '';
$reservationStatus = is_string($_GET['reservation_status'] ?? null) ? strtolower(trim($_GET['reservation_status'])) : '';
$feedbackCategory = is_string($_GET['feedback_category'] ?? null) ? strtolower(trim($_GET['feedback_category'])) : 'concern';
if (strlen($block) > 50 || strlen($facility) > 100 || !in_array($feedbackCategory, ['concern', 'suggestion', 'all'], true)
    || ($reservationStatus !== '' && !in_array($reservationStatus, ['approved', 'pending', 'rejected'], true))) {
    analytics_send(['error' => 'One of the selected filters is invalid.'], 400);
}

try {
    require_once __DIR__ . '/../../config/db.php';
    $conn->set_charset('utf8mb4');
    $homeArchived = analytics_has_column($conn, 'homeowners', 'archived_at') ? 'archived_at IS NULL' : '1=1';
    $homeArchivedSub = analytics_has_column($conn, 'homeowners', 'archived_at') ? ' AND h.archived_at IS NULL' : '';
    $reservationArchived = analytics_has_column($conn, 'reservations', 'archived_at') ? 'r.archived_at IS NULL' : '1=1';
    $feedbackExists = (bool) analytics_rows($conn, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resident_feedback'");
    $feedbackArchived = $feedbackExists && analytics_has_column($conn, 'resident_feedback', 'archived_at') ? 'f.archived_at IS NULL' : '1=1';
    $streetWhere = $block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : '';
    $streetParams = $block !== '' ? [$block] : [];
    $streetTypes = $block !== '' ? 's' : '';

// ---------- Homeowners: status + streets ----------
    $homeRows = analytics_rows($conn, "SELECT LOWER(TRIM(status)) AS label, COUNT(*) AS total FROM homeowners WHERE {$homeArchived}{$streetWhere} GROUP BY LOWER(TRIM(status)) ORDER BY label", $streetTypes, $streetParams);
    $homeStatus = [];
    $homeTotal = $approved = $pending = 0;
    foreach ($homeRows as $row) {
        $label = (string) $row['label']; $count = (int) $row['total'];
        $homeStatus[$label] = $count; $homeTotal += $count;
        if ($label === 'active') $approved = $count;
        if ($label === 'pending') $pending = $count;
    }
    $streetRows = analytics_rows($conn, "SELECT TRIM(block) AS label, COUNT(*) AS total FROM homeowners WHERE {$homeArchived} AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned'{$streetWhere} GROUP BY TRIM(block) ORDER BY total DESC, label", $streetTypes, $streetParams);
    $homeStreetLabels = array_column($streetRows, 'label');
    $homeStreetValues = array_map('intval', array_column($streetRows, 'total'));

// ---------- Homeowners: registration trend ----------
    $registrationRows = analytics_rows($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS total FROM homeowners WHERE created_at >= ? AND created_at < ?" . ($block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : '') . ' GROUP BY period ORDER BY period', 'ss' . $streetTypes, array_merge([$fromValue, $endValue], $streetParams));
    $registrationMap = [];
    foreach ($registrationRows as $row) $registrationMap[(string) $row['period']] = (int) $row['total'];
    $trendPeriods = [];
    $point = $from->modify('first day of this month');
    $lastPeriod = $to->format('Y-m');
    while ($point->format('Y-m') <= $lastPeriod) { $trendPeriods[] = $point->format('Y-m'); $point = $point->modify('+1 month'); }
    $registrationValues = array_map(static fn($period) => $registrationMap[$period] ?? 0, $trendPeriods);
    $registrationLabels = array_map(static fn($period) => date('M Y', strtotime($period . '-01')), $trendPeriods);
    $registrationCount = array_sum($registrationValues);

$prevRegistrationWhere = 'created_at >= ? AND created_at < ?' . ($block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : '');
    $prevRegistrations = (int) (analytics_rows($conn, "SELECT COUNT(*) AS total FROM homeowners WHERE {$prevRegistrationWhere}", 'ss' . $streetTypes, array_merge([$prevFromValue, $prevEndValue], $streetParams))[0]['total'] ?? 0);

// ---------- Reservations ----------
    $buildReservationFilter = static function (string $fromVal, string $toVal, bool $withStatus) use ($reservationArchived, $reservationStatus, $facility, $block, $homeArchivedSub): array {
        $where = "{$reservationArchived} AND r.res_date >= ? AND r.res_date < ?";
        $params = [$fromVal, $toVal];
        $types = 'ss';
        if ($withStatus && $reservationStatus !== '') { $where .= ' AND r.status = ?'; $params[] = $reservationStatus; $types .= 's'; }
        if ($facility !== '') { $where .= ' AND r.facility = ?'; $params[] = $facility; $types .= 's'; }
        if ($block !== '') { $where .= " AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id{$homeArchivedSub} AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))"; $params[] = $block; $types .= 's'; }
        return [$where, $params, $types];
    };

[$reservationWhere, $reservationParams, $reservationTypes] = $buildReservationFilter($fromValue, $endValue, true);
    $reservationRows = analytics_rows($conn, "SELECT r.facility AS label, COUNT(*) AS total FROM reservations r WHERE {$reservationWhere} GROUP BY r.facility ORDER BY total DESC, r.facility", $reservationTypes, $reservationParams);
    $facilityLabels = array_column($reservationRows, 'label');
    $facilityValues = array_map('intval', array_column($reservationRows, 'total'));
    $reservationTotal = array_sum($facilityValues);

[$allResWhere, $allResParams, $allResTypes] = $buildReservationFilter($fromValue, $endValue, false);
    $allReservationRows = analytics_rows($conn, "SELECT r.status AS label, COUNT(*) AS total FROM reservations r WHERE {$allResWhere} GROUP BY r.status ORDER BY r.status", $allResTypes, $allResParams);
    $reservationStatusLabels = array_map(static fn($row) => ucfirst((string) $row['label']), $allReservationRows);
    $reservationStatusValues = array_map('intval', array_column($allReservationRows, 'total'));

$pendingReservationCount = static function (string $fromVal, string $toVal) use ($conn, $reservationArchived, $facility, $block, $homeArchivedSub): int {
        $where = "{$reservationArchived} AND r.res_date >= ? AND r.res_date < ? AND r.status = 'pending'";
        $params = [$fromVal, $toVal]; $types = 'ss';
        if ($facility !== '') { $where .= ' AND r.facility = ?'; $params[] = $facility; $types .= 's'; }
        if ($block !== '') { $where .= " AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id{$homeArchivedSub} AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))"; $params[] = $block; $types .= 's'; }
        return (int) (analytics_rows($conn, "SELECT COUNT(*) AS total FROM reservations r WHERE {$where}", $types, $params)[0]['total'] ?? 0);
    };
    $pendingReservationsNow = $pendingReservationCount($fromValue, $endValue);
    $pendingReservationsPrev = $pendingReservationCount($prevFromValue, $prevEndValue);

[$prevResWhere, $prevResParams, $prevResTypes] = $buildReservationFilter($prevFromValue, $prevEndValue, true);
    $prevReservations = (int) (analytics_rows($conn, "SELECT COUNT(*) AS total FROM reservations r WHERE {$prevResWhere}", $prevResTypes, $prevResParams)[0]['total'] ?? 0);

$reservationTrendRows = analytics_rows($conn, "SELECT DATE_FORMAT(r.res_date, '%Y-%m') AS period, COUNT(*) AS total FROM reservations r WHERE {$reservationWhere} GROUP BY period ORDER BY period", $reservationTypes, $reservationParams);
    $reservationTrendMap = [];
    foreach ($reservationTrendRows as $row) $reservationTrendMap[(string) $row['period']] = (int) $row['total'];
    $reservationTrendValues = array_map(static fn($period) => $reservationTrendMap[$period] ?? 0, $trendPeriods);

$reservationUsageWhere = "{$reservationArchived} AND r.res_date >= ? AND r.res_date < ? AND r.status = 'approved'";
    $reservationUsageParams = [$fromValue, $endValue];
    $reservationUsageTypes = 'ss';
    if ($facility !== '') { $reservationUsageWhere .= ' AND r.facility = ?'; $reservationUsageParams[] = $facility; $reservationUsageTypes .= 's'; }
    if ($block !== '') { $reservationUsageWhere .= " AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id{$homeArchivedSub} AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))"; $reservationUsageParams[] = $block; $reservationUsageTypes .= 's'; }
    $hourRows = analytics_rows($conn, "SELECT HOUR(r.start_time) AS hour_of_day, COUNT(*) AS total FROM reservations r WHERE {$reservationUsageWhere} GROUP BY HOUR(r.start_time) ORDER BY hour_of_day", $reservationUsageTypes, $reservationUsageParams);
    $hourMap = [];
    foreach ($hourRows as $row) $hourMap[(int) $row['hour_of_day']] = (int) $row['total'];
    $hourLabels = []; $hourValues = [];
    foreach ($hourMap as $hour => $count) { $hourLabels[] = date('g A', strtotime(sprintf('%02d:00:00', $hour))); $hourValues[] = $count; }

$peakHour = null;
    if ($hourValues) {
        $peakIndex = array_keys($hourValues, max($hourValues))[0];
        $peakHour = ['label' => $hourLabels[$peakIndex], 'count' => $hourValues[$peakIndex]];
    }

// ---------- Feedback ----------
    $concernStatus = [];
    $concernUnresolved = $concernResolved = 0;
    $prevConcernUnresolved = $prevConcernResolved = 0;
    $feedbackTrendMap = []; $feedbackStreetMap = []; $feedbackCategoryMap = [];
    $feedbackTrendByCat = [];
    $feedbackInRange = 0;
    $feedbackJoin = '';
    if ($feedbackExists) {
        $feedbackJoin = "FROM resident_feedback f LEFT JOIN (SELECT user_id, MAX(TRIM(block)) AS block FROM homeowners WHERE {$homeArchived} GROUP BY user_id) h ON h.user_id = f.user_id";
        $fbWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ?";
        $fbParams = [$fromValue, $endValue]; $fbTypes = 'ss';
        if ($block !== '') { $fbWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $fbParams[] = $block; $fbTypes .= 's'; }
        if ($feedbackCategory !== 'all') { $fbWhere .= ' AND f.category = ?'; $fbParams[] = $feedbackCategory; $fbTypes .= 's'; }
        $fbRows = analytics_rows($conn, "SELECT f.category, f.status, DATE_FORMAT(f.created_at, '%Y-%m') AS period, COALESCE(NULLIF(TRIM(h.block), ''), 'Unlinked') AS street, COUNT(*) AS total {$feedbackJoin} WHERE {$fbWhere} GROUP BY f.category, f.status, period, street", $fbTypes, $fbParams);
        foreach ($fbRows as $row) {
            $count = (int) $row['total']; $feedbackInRange += $count;
            $period = (string) $row['period'];
            $feedbackTrendMap[$period] = ($feedbackTrendMap[$period] ?? 0) + $count;
            $cat = (string) $row['category'];
            $feedbackTrendByCat[$cat][$period] = ($feedbackTrendByCat[$cat][$period] ?? 0) + $count;
            $street = (string) $row['street']; $feedbackStreetMap[$street] = ($feedbackStreetMap[$street] ?? 0) + $count;
        }
        $categoryWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ?";
        $categoryParams = [$fromValue, $endValue]; $categoryTypes = 'ss';
        if ($block !== '') { $categoryWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $categoryParams[] = $block; $categoryTypes .= 's'; }
        $categoryRows = analytics_rows($conn, "SELECT f.category, COUNT(*) AS total {$feedbackJoin} WHERE {$categoryWhere} GROUP BY f.category ORDER BY total DESC, f.category", $categoryTypes, $categoryParams);
        foreach ($categoryRows as $row) $feedbackCategoryMap[(string) $row['category']] = (int) $row['total'];

$concernCountRows = static function (string $fromVal, string $toVal) use ($conn, $feedbackJoin, $feedbackArchived, $block, $streetTypes, $streetParams): array {
            $where = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ? AND f.category = 'concern'" . ($block !== '' ? ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '');
            return analytics_rows($conn, "SELECT f.status, COUNT(*) AS total {$feedbackJoin} WHERE {$where} GROUP BY f.status", 'ss' . $streetTypes, array_merge([$fromVal, $toVal], $streetParams));
        };
        foreach ($concernCountRows($fromValue, $endValue) as $row) {
            $status = (string) $row['status']; $count = (int) $row['total'];
            $concernStatus[$status] = $count;
            if ($status === 'resolved') $concernResolved += $count; else $concernUnresolved += $count;
        }
        foreach ($concernCountRows($prevFromValue, $prevEndValue) as $row) {
            if ((string) $row['status'] === 'resolved') $prevConcernResolved += (int) $row['total'];
            else $prevConcernUnresolved += (int) $row['total'];
        }
    }
    $feedbackTrendValues = array_map(static fn($period) => $feedbackTrendMap[$period] ?? 0, $trendPeriods);
    arsort($feedbackStreetMap); arsort($feedbackCategoryMap);
    $trendCats = array_keys($feedbackTrendByCat);

// ---------- Compare-mode previous-window monthly trends ----------
    $prevTrendPeriods = [];
    $prevPoint = (new DateTimeImmutable($prevFromValue, $zone))->modify('first day of this month');
    $prevLastPeriod = (new DateTimeImmutable($fromValue, $zone))->modify('-1 day')->format('Y-m');
    while ($prevPoint->format('Y-m') <= $prevLastPeriod) { $prevTrendPeriods[] = $prevPoint->format('Y-m'); $prevPoint = $prevPoint->modify('+1 month'); }

$prevRegRows = analytics_rows($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period, COUNT(*) AS total FROM homeowners WHERE created_at >= ? AND created_at < ?" . ($block !== '' ? ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))' : '') . ' GROUP BY period ORDER BY period', 'ss' . $streetTypes, array_merge([$prevFromValue, $prevEndValue], $streetParams));
    $prevRegMap = [];
    foreach ($prevRegRows as $row) $prevRegMap[(string) $row['period']] = (int) $row['total'];
    $prevRegValues = array_map(static fn($p) => $prevRegMap[$p] ?? 0, $prevTrendPeriods);

[$prevTrendWhere, $prevTrendParams, $prevTrendTypes] = $buildReservationFilter($prevFromValue, $prevEndValue, true);
    $prevResTrendRows = analytics_rows($conn, "SELECT DATE_FORMAT(r.res_date, '%Y-%m') AS period, COUNT(*) AS total FROM reservations r WHERE {$prevTrendWhere} GROUP BY period ORDER BY period", $prevTrendTypes, $prevTrendParams);
    $prevResTrendMap = [];
    foreach ($prevResTrendRows as $row) $prevResTrendMap[(string) $row['period']] = (int) $row['total'];
    $prevResTrendValues = array_map(static fn($p) => $prevResTrendMap[$p] ?? 0, $prevTrendPeriods);

$prevFbTrendValues = [];
    if ($feedbackExists) {
        $prevFbWhere = "{$feedbackArchived} AND f.created_at >= ? AND f.created_at < ?";
        $prevFbParams = [$prevFromValue, $prevEndValue]; $prevFbTypes = 'ss';
        if ($block !== '') { $prevFbWhere .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $prevFbParams[] = $block; $prevFbTypes .= 's'; }
        if ($feedbackCategory !== 'all') { $prevFbWhere .= ' AND f.category = ?'; $prevFbParams[] = $feedbackCategory; $prevFbTypes .= 's'; }
        $prevFbRows = analytics_rows($conn, "SELECT DATE_FORMAT(f.created_at, '%Y-%m') AS period, COUNT(*) AS total {$feedbackJoin} WHERE {$prevFbWhere} GROUP BY period ORDER BY period", $prevFbTypes, $prevFbParams);
        $prevFbMap = [];
        foreach ($prevFbRows as $row) $prevFbMap[(string) $row['period']] = (int) $row['total'];
        $prevFbTrendValues = array_map(static fn($p) => $prevFbMap[$p] ?? 0, $prevTrendPeriods);
    }
    $prevTrendLabels = array_map(static fn($p) => date('M Y', strtotime($p . '-01')), $prevTrendPeriods);

// ---------- Automated insights ----------
    $insights = [];
    if ($homeTotal > 0) {
        $topStreet = $homeStreetLabels[0] ?? null;
        if ($topStreet !== null) $insights[] = ['icon' => 'bi-house-heart', 'text' => $topStreet . ' has the largest number of current homeowner profiles (' . $homeStreetValues[0] . ').'];
        $insights[] = ['icon' => 'bi-person-check', 'text' => $approved . ' of ' . $homeTotal . ' current homeowner profiles are active.'];
    }
    if ($registrationCount > 0) $insights[] = ['icon' => 'bi-person-plus', 'text' => $registrationCount . ' homeowner profiles were recorded during the selected period, including archived profiles.'];
    if ($reservationTotal > 0) $insights[] = ['icon' => 'bi-building', 'text' => $facilityLabels[0] . ' is the most reserved facility in the selected period (' . $facilityValues[0] . ' bookings).'];
    if (array_sum($reservationTrendValues) > 0) $insights[] = ['icon' => 'bi-calendar2-check', 'text' => array_sum($reservationTrendValues) . ' reservation requests were recorded during the selected period, including archived reservations.'];
    if ($peakHour !== null) $insights[] = ['icon' => 'bi-clock-history', 'text' => $peakHour['label'] . ' is the busiest approved start time (' . $peakHour['count'] . ' bookings).'];
    $concernCount = $concernUnresolved + $concernResolved;
    if ($concernCount > 0) {
        $insights[] = ['icon' => 'bi-chat-left-dots', 'text' => $concernCount . ' concerns were recorded during the selected period.'];
        $insights[] = ['icon' => 'bi-chat-left-dots', 'text' => $concernUnresolved . ' of those concerns remain unresolved.'];
    }
    if (!$insights) $insights[] = ['icon' => 'bi-info-circle', 'text' => 'No activity is available for an insight in the selected filters yet.'];

analytics_send([
        'range' => ['type' => $range, 'from' => $fromValue, 'to' => $to->format('Y-m-d')],
        'comparison' => ['from' => $prevFromValue, 'to' => (new DateTimeImmutable($prevEndValue, $zone))->modify('-1 day')->format('Y-m-d')],
        'last_updated' => (new DateTimeImmutable('now', $zone))->format(DateTimeInterface::ATOM),
        'filters' => ['block' => $block, 'facility' => $facility, 'reservation_status' => $reservationStatus, 'feedback_category' => $feedbackCategory],
        'kpis' => ['homeowners' => $homeTotal, 'approved_homeowners' => $approved, 'pending_homeowners' => $pending, 'reservations' => $reservationTotal, 'unresolved_concerns' => $concernUnresolved, 'resolved_concerns' => $concernResolved, 'new_registrations' => $registrationCount, 'pending_reservations' => $pendingReservationsNow],
        'kpis_previous' => ['reservations' => $prevReservations, 'unresolved_concerns' => $prevConcernUnresolved, 'resolved_concerns' => $prevConcernResolved, 'new_registrations' => $prevRegistrations, 'pending_reservations' => $pendingReservationsPrev],
        'comparison_trends' => ['labels' => $prevTrendLabels, 'homeowners' => $prevRegValues, 'reservations' => $prevResTrendValues, 'feedback' => $prevFbTrendValues],
        'peak_hour' => $peakHour,
        'homeowners' => ['status_labels' => array_map(static fn($value) => ucfirst(str_replace('_', ' ', $value)), array_keys($homeStatus)), 'status_values' => array_values($homeStatus), 'street_labels' => $homeStreetLabels, 'street_values' => $homeStreetValues, 'trend_labels' => $registrationLabels, 'trend_periods' => $trendPeriods, 'trend_values' => $registrationValues, 'trend_includes_archived' => true],
        'reservations' => ['total' => $reservationTotal, 'facility_labels' => $facilityLabels, 'facility_values' => $facilityValues, 'status_labels' => $reservationStatusLabels, 'status_values' => $reservationStatusValues, 'trend_labels' => $registrationLabels, 'trend_periods' => $trendPeriods, 'trend_values' => $reservationTrendValues, 'trend_includes_archived' => true, 'hour_labels' => $hourLabels, 'hour_values' => $hourValues, 'busy_hours_status' => 'approved'],
        'feedback' => ['available' => $feedbackExists, 'trend_labels' => $registrationLabels, 'trend_periods' => $trendPeriods, 'trend_values' => $feedbackTrendValues, 'trend_series' => ['labels' => $registrationLabels, 'periods' => $trendPeriods, 'categories' => $trendCats, 'values' => array_map(static fn($cat) => array_map(static fn($p) => $feedbackTrendByCat[$cat][$p] ?? 0, $trendPeriods), $trendCats)], 'status_labels' => array_map(static fn($value) => ucfirst(str_replace('_', ' ', $value)), array_keys($concernStatus)), 'status_values' => array_values($concernStatus), 'status_category' => 'concern', 'street_labels' => array_keys($feedbackStreetMap), 'street_values' => array_values($feedbackStreetMap), 'category_labels' => array_keys($feedbackCategoryMap), 'category_values' => array_values($feedbackCategoryMap), 'total' => $feedbackInRange],
        'insights' => $insights,
    ]);
} catch (Throwable $e) {
    error_log('Visual analytics request failed: ' . $e->getMessage());
    analytics_send(['error' => 'Analytics could not be loaded. Please try again.'], 500);
}
