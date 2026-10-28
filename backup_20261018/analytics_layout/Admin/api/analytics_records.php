<?php
require_once __DIR__ . '/../../config/init.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function records_json(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function records_date(string $value): ?DateTimeImmutable
{
    $zone = new DateTimeZone('Asia/Manila');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) return null;
    return $date;
}

function records_run(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare detail query.');
    if ($types !== '' && !$stmt->bind_param($types, ...$params)) {
        $stmt->close();
        throw new RuntimeException('Unable to bind detail filters.');
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to run detail query.');
    }
    $result = $stmt->get_result();
    if (!$result) {
        $stmt->close();
        throw new RuntimeException('Unable to read detail results.');
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $stmt->close();
    return $rows;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    records_json(['error' => ['code' => 'unauthorized', 'message' => 'Please sign in as an administrator.'], 'rows' => []], 401);
}

$metric = is_string($_GET['metric'] ?? null) ? $_GET['metric'] : '';
$allowedMetrics = ['collections', 'facilities', 'payment_methods', 'account_status', 'dues', 'homeowners_by_block'];
if (!in_array($metric, $allowedMetrics, true)) {
    records_json(['error' => ['code' => 'invalid_metric', 'message' => 'Choose a valid chart.'], 'rows' => []], 400);
}
$from = records_date(is_string($_GET['from'] ?? null) ? $_GET['from'] : '');
$to = records_date(is_string($_GET['to'] ?? null) ? $_GET['to'] : '');
if (!$from || !$to || $from > $to || (int) $from->diff($to)->format('%a') > 3660) {
    records_json(['error' => ['code' => 'invalid_date', 'message' => 'Enter a valid date range of 10 years or less.'], 'rows' => []], 400);
}
$block = is_string($_GET['block'] ?? null) ? trim($_GET['block']) : '';
$category = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$status = is_string($_GET['status'] ?? null) ? strtolower(trim($_GET['status'])) : '';
$bucket = is_string($_GET['bucket'] ?? null) ? trim($_GET['bucket']) : '';
$selectedFrom = $from;
$selectedTo = $to;
if (strlen($block) > 50 || strlen($category) > 100 || strlen($bucket) > 10) {
    records_json(['error' => ['code' => 'invalid_filter', 'message' => 'One of the selected filters is too long.'], 'rows' => []], 400);
}

if ($metric === 'collections' && $bucket !== '') {
    if (preg_match('/^\d{4}-\d{2}$/', $bucket)) {
        $bucketStart = DateTimeImmutable::createFromFormat('!Y-m-d', $bucket . '-01', new DateTimeZone('Asia/Manila'));
        if (!$bucketStart || $bucketStart->format('Y-m') !== $bucket) records_json(['error' => ['code' => 'invalid_bucket', 'message' => 'Choose a valid chart date.'], 'rows' => []], 400);
        $bucketEnd = $bucketStart->modify('last day of this month');
        $from = $bucketStart > $selectedFrom ? $bucketStart : $selectedFrom;
        $to = $bucketEnd < $selectedTo ? $bucketEnd : $selectedTo;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bucket) && records_date($bucket)) {
        $from = records_date($bucket);
        $to = $from;
    } else {
        records_json(['error' => ['code' => 'invalid_bucket', 'message' => 'Choose a valid chart date.'], 'rows' => []], 400);
    }
}

try {
    require_once __DIR__ . '/../../config/db.php';
    $conn->set_charset('utf8mb4');
    $fromValue = $from->format('Y-m-d');
    $endValue = $to->modify('+1 day')->format('Y-m-d');
    $where = [];
    $params = [];
    $types = '';
    $columns = [];
    $limit = 100;

    if ($metric === 'collections' || $metric === 'payment_methods') {
        require_once __DIR__ . '/../../includes/payment_methods.php';
        $selectMethod = payment_method_sql_expression('p');
        $sql = "SELECT p.id, p.payment_date, p.period_covered, p.amount_paid, {$selectMethod} AS category,
                       COALESCE(h.full_name, 'Former household') AS homeowner, COALESCE(h.block, '') AS street,
                       COALESCE(h.lot, '') AS lot
                FROM payments p LEFT JOIN homeowners h ON h.id = p.homeowner_id
                WHERE p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ?";
        $params = [$fromValue, $endValue]; $types = 'ss';
        if ($block !== '') { $sql .= ' AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $params[] = $block; $types .= 's'; }
        if ($metric === 'payment_methods' && $category !== '') { $sql .= " AND {$selectMethod} = ?"; $params[] = $category; $types .= 's'; }
        $sql .= ' ORDER BY p.payment_date DESC, p.id DESC LIMIT ?'; $params[] = $limit; $types .= 'i';
        $columns = ['Payment date', 'Billing month', 'Amount', 'Payment method', 'Household', 'Street', 'Lot'];
    } elseif ($metric === 'facilities') {
        $status = $status !== '' ? $status : 'approved';
        if (!in_array($status, ['approved', 'pending', 'rejected'], true)) records_json(['error' => ['code' => 'invalid_filter', 'message' => 'Choose a valid booking status.'], 'rows' => []], 400);
        $sql = "SELECT r.id, r.facility, r.res_date, r.start_time, r.end_time, r.status, COALESCE(u.full_name, 'Unknown') AS reserved_by
                FROM reservations r LEFT JOIN users u ON u.id = r.user_id
                WHERE r.archived_at IS NULL AND r.res_date >= ? AND r.res_date < ? AND r.status = ?";
        $params = [$fromValue, $endValue, $status]; $types = 'sss';
        if ($block !== '') { $sql .= ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))'; $params[] = $block; $types .= 's'; }
        if ($category !== '') { $sql .= ' AND r.facility = ?'; $params[] = $category; $types .= 's'; }
        $sql .= ' ORDER BY r.res_date DESC, r.start_time DESC, r.id DESC LIMIT ?'; $params[] = $limit; $types .= 'i';
        $columns = ['Facility', 'Date', 'Start time', 'End time', 'Status', 'Reserved by'];
    } elseif ($metric === 'account_status') {
        if ($status !== '' && !in_array($status, ['active', 'pending', 'inactive'], true)) records_json(['error' => ['code' => 'invalid_filter', 'message' => 'Choose a valid household status.'], 'rows' => []], 400);
        $sql = 'SELECT id, full_name, block, lot, LOWER(status) AS status FROM homeowners WHERE archived_at IS NULL';
        $params = []; $types = '';
        if ($block !== '') { $sql .= ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))'; $params[] = $block; $types .= 's'; }
        if ($status !== '') { $sql .= ' AND LOWER(status) = ?'; $params[] = $status; $types .= 's'; }
        if ($category !== '') { $sql .= ' AND LOWER(status) = ?'; $params[] = strtolower($category); $types .= 's'; }
        $sql .= ' ORDER BY block, lot, full_name LIMIT ?'; $params[] = $limit; $types .= 'i';
        $columns = ['Household', 'Street', 'Lot', 'Status'];
    } elseif ($metric === 'homeowners_by_block') {
        $sql = "SELECT id, full_name, block, lot, LOWER(status) AS status FROM homeowners WHERE archived_at IS NULL AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned'";
        $params = []; $types = '';
        $street = $category !== '' ? $category : $block;
        if ($street !== '') { $sql .= ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))'; $params[] = $street; $types .= 's'; }
        if ($status !== '') { $sql .= ' AND LOWER(status) = ?'; $params[] = $status; $types .= 's'; }
        $sql .= ' ORDER BY block, lot, full_name LIMIT ?'; $params[] = $limit; $types .= 'i';
        $columns = ['Household', 'Street', 'Lot', 'Status'];
    } else { // monthly dues details
        if ($status !== '' && !in_array($status, ['paid', 'partial', 'unpaid'], true)) records_json(['error' => ['code' => 'invalid_filter', 'message' => 'Choose a valid dues status.'], 'rows' => []], 400);
        $period = $to->format('Y-m');
        $dueStart = "CASE WHEN h.dues_start_date IS NULL OR h.dues_start_date = '0000-00-00' THEN DATE_FORMAT(h.created_at, '%Y-%m') ELSE DATE_FORMAT(h.dues_start_date, '%Y-%m') END";
        $paidAmount = 'COALESCE(p.paid, 0)';
        $state = "CASE WHEN h.monthly_due <= 0 OR {$paidAmount} >= h.monthly_due THEN 'paid' WHEN {$paidAmount} > 0 THEN 'partial' ELSE 'unpaid' END";
        $sql = "SELECT h.id, h.full_name, h.block, h.lot, h.monthly_due, {$paidAmount} AS paid,
                       GREATEST(h.monthly_due - {$paidAmount}, 0) AS remaining, {$state} AS status
                FROM homeowners h
                LEFT JOIN (SELECT homeowner_id, SUM(amount_paid) AS paid FROM payments WHERE archived_at IS NULL AND period_covered = ? GROUP BY homeowner_id) p ON p.homeowner_id = h.id
                WHERE h.archived_at IS NULL AND LOWER(TRIM(h.status)) = 'active' AND TRIM(h.block) <> '' AND LOWER(TRIM(h.block)) <> 'unassigned' AND {$dueStart} <= ?";
        $params = [$period, $period]; $types = 'ss';
        if ($block !== '') { $sql .= ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $params[] = $block; $types .= 's'; }
        if ($status !== '') { $sql .= " AND {$state} = ?"; $params[] = $status; $types .= 's'; }
        if ($category !== '') { $sql .= " AND {$state} = ?"; $params[] = strtolower($category); $types .= 's'; }
        $sql .= " ORDER BY CASE {$state} WHEN 'unpaid' THEN 0 WHEN 'partial' THEN 1 ELSE 2 END, h.block, h.lot LIMIT ?";
        $params[] = $limit; $types .= 'i';
        $columns = ['Household', 'Street', 'Lot', 'Monthly due', 'Paid', 'Remaining', 'Status'];
    }

    $rows = records_run($conn, $sql, $types, $params);
    $displayRows = [];
    foreach ($rows as $row) {
        if ($metric === 'collections' || $metric === 'payment_methods') {
            $displayRows[] = [$row['payment_date'], $row['period_covered'], (float) $row['amount_paid'], $row['category'], $row['homeowner'], $row['street'], $row['lot']];
        } elseif ($metric === 'facilities') {
            $displayRows[] = [$row['facility'], $row['res_date'], $row['start_time'], $row['end_time'], ucfirst($row['status']), $row['reserved_by']];
        } elseif ($metric === 'dues') {
            $displayRows[] = [$row['full_name'], $row['block'], $row['lot'], (float) $row['monthly_due'], (float) $row['paid'], (float) $row['remaining'], ucfirst($row['status'])];
        } else {
            $displayRows[] = [$row['full_name'], $row['block'], $row['lot'], ucfirst($row['status'])];
        }
    }
    records_json(['columns' => $columns, 'rows' => $displayRows, 'limit' => $limit, 'last_updated' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM)]);
} catch (Throwable $e) {
    error_log('Analytics records API error: ' . $e->getMessage());
    records_json(['error' => ['code' => 'records_unavailable', 'message' => 'The matching records could not be loaded. Please try again.'], 'rows' => []], 500);
}
