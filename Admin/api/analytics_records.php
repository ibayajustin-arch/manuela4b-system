<?php
require_once __DIR__ . '/../../config/init.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function records_send(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function records_date(string $value): ?DateTimeImmutable
{
    $zone = new DateTimeZone('Asia/Manila');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format('Y-m-d') === $value ? $date : null;
}

function records_rows(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Detail query could not be prepared.');
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Detail query could not be completed.');
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function records_has_column(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') records_send(['error' => 'Administrator access is required.'], 401);

$metric = is_string($_GET['metric'] ?? null) ? $_GET['metric'] : '';
if (!in_array($metric, ['homeowners', 'reservations', 'feedback'], true)) records_send(['error' => 'Choose a valid record type.'], 400);
if (array_key_exists('include_archived', $_GET) && (!is_string($_GET['include_archived']) || !in_array($_GET['include_archived'], ['0', '1'], true))) records_send(['error' => 'Choose a valid archive-history option.'], 400);
$includeArchivedHistory = is_string($_GET['include_archived'] ?? null) && $_GET['include_archived'] === '1';
if ($includeArchivedHistory && !in_array($metric, ['homeowners', 'reservations'], true)) records_send(['error' => 'Archived history is not available for this record type.'], 400);
$from = records_date(is_string($_GET['from'] ?? null) ? $_GET['from'] : '');
$to = records_date(is_string($_GET['to'] ?? null) ? $_GET['to'] : '');
if (!$from || !$to || $from > $to || (int) $from->diff($to)->format('%a') > 3660) records_send(['error' => 'Enter a valid date range.'], 400);
$block = is_string($_GET['block'] ?? null) ? trim($_GET['block']) : '';
$facility = is_string($_GET['facility'] ?? null) ? trim($_GET['facility']) : '';
$category = is_string($_GET['feedback_category'] ?? null) ? strtolower(trim($_GET['feedback_category'])) : 'concern';
$status = is_string($_GET['status'] ?? null) ? strtolower(trim($_GET['status'])) : '';
$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 25)));
$sort = max(0, min(10, (int) ($_GET['sort'] ?? 0)));
$direction = strtolower((string) ($_GET['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
if (strlen($block) > 50 || strlen($facility) > 100 || strlen($search) > 100 || !in_array($category, ['concern', 'suggestion', 'all'], true)) records_send(['error' => 'One of the selected filters is too long or invalid.'], 400);
$fromValue = $from->format('Y-m-d'); $endValue = $to->modify('+1 day')->format('Y-m-d');

try {
    require_once __DIR__ . '/../../config/db.php';
    $conn->set_charset('utf8mb4');
    $where = []; $params = []; $types = ''; $columns = []; $searchColumns = []; $sortColumns = [];
    if ($metric === 'homeowners') {
        $archived = records_has_column($conn, 'homeowners', 'archived_at') ? 'archived_at IS NULL' : '1=1';
        $dateFilter = ($_GET['date_filter'] ?? '') === '1';
        $month = is_string($_GET['month'] ?? null) ? $_GET['month'] : '';
        if ($includeArchivedHistory && $month === '') records_send(['error' => 'Archived records can only be included in a historical month view.'], 400);
        if ($month !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) records_send(['error' => 'Choose a valid month.'], 400);
            $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('Asia/Manila'));
            if (!$monthStart || $monthStart->format('Y-m') !== $month) records_send(['error' => 'Choose a valid month.'], 400);
            $monthEnd = $monthStart->modify('+1 month')->format('Y-m-d');
            $rangeStart = max($fromValue, $monthStart->format('Y-m-d'));
            $rangeEnd = min($endValue, $monthEnd);
            if ($rangeStart >= $rangeEnd) records_send(['error' => 'The selected month does not overlap the date range.'], 400);
            $where[] = 'created_at >= ? AND created_at < ?'; $params[] = $rangeStart; $params[] = $rangeEnd; $types .= 'ss';
        } elseif ($dateFilter) { $where[] = 'created_at >= ? AND created_at < ?'; $params[] = $fromValue; $params[] = $endValue; $types .= 'ss'; }
        if ($block !== '') { $where[] = 'LOWER(TRIM(block)) = LOWER(TRIM(?))'; $params[] = $block; $types .= 's'; }
        if ($status !== '' && $status !== 'all') { if (strlen($status) > 50) records_send(['error' => 'That status filter is too long.'], 400); $where[] = 'LOWER(status) = ?'; $params[] = $status; $types .= 's'; }
        if ($search !== '') { $where[] = '(block LIKE ? OR status LIKE ?)'; $like = '%' . $search . '%'; array_push($params, $like, $like); $types .= 'ss'; }
        $sqlWhere = $where ? implode(' AND ', $where) : '1=1';
        $archiveWhere = $includeArchivedHistory ? '1=1' : $archived;
        $total = (int) (records_rows($conn, "SELECT COUNT(*) AS total FROM homeowners WHERE {$archiveWhere} AND {$sqlWhere}", $types, $params)[0]['total'] ?? 0);
        $columns = ['Street', 'Status', 'Registered'];
        $sortColumns = ['block', 'status', 'created_at'];
        $sort = min($sort, count($sortColumns) - 1);
        $rows = records_rows($conn, "SELECT TRIM(block) AS street, LOWER(status) AS status, DATE_FORMAT(created_at, '%Y-%m-%d') AS registered FROM homeowners WHERE {$archiveWhere} AND {$sqlWhere} ORDER BY {$sortColumns[$sort]} {$direction}, id DESC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$perPage, ($page - 1) * $perPage]));
    } elseif ($metric === 'reservations') {
        $where = ['r.res_date >= ?', 'r.res_date < ?']; $params = [$fromValue, $endValue]; $types = 'ss';
        $month = is_string($_GET['month'] ?? null) ? $_GET['month'] : '';
        if ($includeArchivedHistory && $month === '') records_send(['error' => 'Archived records can only be included in a historical month view.'], 400);
        if ($month !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) records_send(['error' => 'Choose a valid month.'], 400);
            $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('Asia/Manila'));
            if (!$monthStart || $monthStart->format('Y-m') !== $month) records_send(['error' => 'Choose a valid month.'], 400);
            $monthEnd = $monthStart->modify('+1 month')->format('Y-m-d');
            $rangeStart = max($fromValue, $monthStart->format('Y-m-d'));
            $rangeEnd = min($endValue, $monthEnd);
            if ($rangeStart >= $rangeEnd) records_send(['error' => 'The selected month does not overlap the date range.'], 400);
            $where[0] = 'r.res_date >= ?'; $where[1] = 'r.res_date < ?'; $params[0] = $rangeStart; $params[1] = $rangeEnd;
        }
        if (isset($_GET['hour']) && is_string($_GET['hour']) && preg_match('/^(?:1[0-2]|0?[1-9]) [AP]M$/', $_GET['hour'])) {
            $hour = (int) date('G', strtotime($_GET['hour'])); $where[] = 'HOUR(r.start_time) = ?'; $params[] = $hour; $types .= 'i';
        }
        if (!$includeArchivedHistory && records_has_column($conn, 'reservations', 'archived_at')) $where[] = 'r.archived_at IS NULL';
        if ($status !== '') { if (!in_array($status, ['approved', 'pending', 'rejected'], true)) records_send(['error' => 'That status does not apply to reservations.'], 400); $where[] = 'r.status = ?'; $params[] = $status; $types .= 's'; }
        if ($block !== '') { $homeArchived = records_has_column($conn, 'homeowners', 'archived_at') ? ' AND h.archived_at IS NULL' : ''; $where[] = "EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id{$homeArchived} AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))"; $params[] = $block; $types .= 's'; }
        if ($facility !== '') { $where[] = 'r.facility = ?'; $params[] = $facility; $types .= 's'; }
        if ($search !== '') { $where[] = '(r.facility LIKE ? OR r.status LIKE ? OR r.res_date LIKE ?)'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like); $types .= 'sss'; }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (records_rows($conn, "SELECT COUNT(*) AS total FROM reservations r WHERE {$sqlWhere}", $types, $params)[0]['total'] ?? 0);
        $columns = ['Facility', 'Date', 'Start time', 'End time', 'Status'];
        $sortColumns = ['r.facility', 'r.res_date', 'r.start_time', 'r.end_time', 'r.status'];
        $sort = min($sort, count($sortColumns) - 1);
        $rows = records_rows($conn, "SELECT r.facility, DATE_FORMAT(r.res_date, '%Y-%m-%d') AS date, TIME_FORMAT(r.start_time, '%h:%i %p') AS start_time, TIME_FORMAT(r.end_time, '%h:%i %p') AS end_time, r.status FROM reservations r WHERE {$sqlWhere} ORDER BY {$sortColumns[$sort]} {$direction}, r.id DESC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$perPage, ($page - 1) * $perPage]));
    } else {
        $exists = records_rows($conn, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resident_feedback'", '', []);
        if (!$exists) records_send(['rows' => [], 'columns' => ['Category', 'Status', 'Street', 'Date'], 'total' => 0, 'page' => 1, 'per_page' => $perPage]);
        $where = ['f.created_at >= ?', 'f.created_at < ?']; $params = [$fromValue, $endValue]; $types = 'ss';
        $month = is_string($_GET['month'] ?? null) ? $_GET['month'] : '';
        if ($month !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) records_send(['error' => 'Choose a valid month.'], 400);
            $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('Asia/Manila'));
            if (!$monthStart || $monthStart->format('Y-m') !== $month) records_send(['error' => 'Choose a valid month.'], 400);
            $monthEnd = $monthStart->modify('+1 month')->format('Y-m-d');
            $rangeStart = max($fromValue, $monthStart->format('Y-m-d'));
            $rangeEnd = min($endValue, $monthEnd);
            if ($rangeStart >= $rangeEnd) records_send(['error' => 'The selected month does not overlap the date range.'], 400);
            $params[0] = $rangeStart; $params[1] = $rangeEnd;
        }
        if ($category !== 'all') { $where[] = 'f.category = ?'; $params[] = $category; $types .= 's'; }
        if ($status === 'unresolved') $where[] = "f.status <> 'resolved'";
        elseif ($status !== '' && !in_array($status, ['new', 'in_review', 'resolved'], true)) records_send(['error' => 'That status does not apply to feedback.'], 400);
        elseif ($status !== '') { $where[] = 'f.status = ?'; $params[] = $status; $types .= 's'; }
        if (records_has_column($conn, 'resident_feedback', 'archived_at')) $where[] = 'f.archived_at IS NULL';
        if ($block !== '') { $where[] = 'LOWER(TRIM(h.block)) = LOWER(TRIM(?))'; $params[] = $block; $types .= 's'; }
        if ($search !== '') { $where[] = '(f.category LIKE ? OR f.status LIKE ? OR h.block LIKE ?)'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like); $types .= 'sss'; }
        $homeArchived = records_has_column($conn, 'homeowners', 'archived_at') ? ' WHERE archived_at IS NULL' : '';
        $fromSql = 'FROM resident_feedback f LEFT JOIN (SELECT user_id, MAX(TRIM(block)) AS block FROM homeowners' . $homeArchived . ' GROUP BY user_id) h ON h.user_id = f.user_id';
        $sqlWhere = implode(' AND ', $where);
        $total = (int) (records_rows($conn, "SELECT COUNT(*) AS total {$fromSql} WHERE {$sqlWhere}", $types, $params)[0]['total'] ?? 0);
        $columns = ['Category', 'Status', 'Street', 'Date'];
        $sortColumns = ['f.category', 'f.status', 'h.block', 'f.created_at'];
        $rows = records_rows($conn, "SELECT f.category, f.status, COALESCE(NULLIF(TRIM(h.block), ''), 'Unlinked') AS street, DATE_FORMAT(f.created_at, '%Y-%m-%d') AS date {$fromSql} WHERE {$sqlWhere} ORDER BY {$sortColumns[min($sort, 3)]} {$direction}, f.id DESC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$perPage, ($page - 1) * $perPage]));
    }
    records_send(['rows' => $rows, 'columns' => $columns, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => max(1, (int) ceil($total / $perPage))]);
} catch (Throwable $e) {
    error_log('Visual analytics records request failed: ' . $e->getMessage());
    records_send(['error' => 'Matching records could not be loaded.'], 500);
}
