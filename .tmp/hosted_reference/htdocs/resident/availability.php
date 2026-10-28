<?php
// resident/availability.php?facility=Clubhouse&month=2026-10   -> JSON of booked slots
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/availability.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function av_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['user_id'])) av_out(['ok' => false, 'error' => 'Not logged in.'], 401);

$facility = (string) ($_GET['facility'] ?? '');
$month    = (string) ($_GET['month'] ?? '');

if (!array_key_exists($facility, fac_config()['facilities'])) av_out(['ok' => false, 'error' => 'Unknown facility.'], 400);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) av_out(['ok' => false, 'error' => 'Invalid month.'], 400);

$first = $month . '-01';
$last  = date('Y-m-t', strtotime($first));
$names = venue_facilities($facility);
$ph    = implode(',', array_fill(0, count($names), '?'));
$uid   = (int) $_SESSION['user_id'];

$sql = "SELECT res_date, start_time, end_time, user_id FROM reservations
        WHERE facility IN ($ph) AND res_date BETWEEN ? AND ? AND " . reservation_block_sql() . "
        ORDER BY res_date, start_time";
$stmt = $conn->prepare($sql);
if (!$stmt) av_out(['ok' => false, 'error' => 'Database error.'], 500);

$params = array_merge($names, [$first, $last]);
$stmt->bind_param(str_repeat('s', count($names)) . 'ss', ...$params);
$stmt->execute();
$res = $stmt->get_result();

$days = [];
while ($r = $res->fetch_assoc()) {
    $days[$r['res_date']][] = [
        's'    => substr($r['start_time'], 0, 5),
        'e'    => substr($r['end_time'], 0, 5),
        'mine' => ((int) $r['user_id'] === $uid),   // never expose who else booked
    ];
}
$stmt->close();

$cfg = fac_config();
av_out(['ok' => true, 'facility' => $facility, 'month' => $month,
        'open' => $cfg['open'], 'close' => $cfg['close'], 'days' => $days]);