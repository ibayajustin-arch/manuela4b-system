<?php
date_default_timezone_set('Asia/Manila');

if (!function_exists('fac_config')) {
    function fac_config() {
        static $c = null;
        if ($c === null) $c = require __DIR__ . '/../config/facilities.php';
        return $c;
    }
}

if (!function_exists('facility_venue')) {
    function facility_venue($facility) {
        // Legacy reservations keep their original saved names in the database.
        if (in_array($facility, ['Basketball Court', 'Badminton Court', 'Tennis Court'], true)) {
            return 'court';
        }
        return fac_config()['facilities'][$facility]['venue'] ?? null;
    }
}

if (!function_exists('fac_reservation_total')) {
    function fac_reservation_total($startMinute, $endMinute) {
        $total = 0;
        $periods = fac_config()['rate_periods'];

        for ($minute = $startMinute; $minute < $endMinute; $minute += 60) {
            $rate = null;
            foreach ($periods as $period) {
                $periodStart = fac_minutes($period['start']);
                $periodEnd = fac_minutes($period['end']);
                if ($minute >= $periodStart && $minute + 60 <= $periodEnd) {
                    $rate = (float) $period['rate'];
                    break;
                }
            }

            if ($rate === null) {
                throw new RuntimeException('No hourly facility rate is configured for the selected time.');
            }
            $total += $rate;
        }

        return $total;
    }
}

if (!function_exists('venue_facilities')) {
    // All facility names that share the same venue as $facility
    function venue_facilities($facility) {
        $venue = facility_venue($facility);
        $out = [];
        foreach (fac_config()['facilities'] as $name => $f) {
            if ($f['venue'] === $venue) $out[] = $name;
        }
        if ($venue === 'court') {
            $out = array_merge($out, ['Basketball Court', 'Badminton Court']);
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('fac_minutes')) {
    // "14:00" / "14:00:00" -> minutes since midnight (or null)
    function fac_minutes($t) {
        $ts = strtotime((string) $t);
        if ($ts === false) return null;
        return ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
    }
}

if (!function_exists('reservation_block_sql')) {
    // Which existing reservations occupy a time slot.
    // A slot belongs to whoever has PAID (downpayment or full). Pending/unpaid bookings
    // do not block the schedule. (Optional: set 'hold_minutes' > 0 in config/facilities.php
    // to also hold a just-created unpaid booking for that many minutes.)
    function reservation_block_sql() {
        $hold = (int) (fac_config()['hold_minutes'] ?? 0);
        $sql  = "status <> 'rejected' AND (payment_status = 'paid' OR status = 'approved'";
        if ($hold > 0) {
            $sql .= " OR created_at > (NOW() - INTERVAL {$hold} MINUTE)";
        }
        return $sql . ")";
    }
}

if (!function_exists('reservation_conflict')) {
    // True if another reservation overlaps this slot (same venue). $approvedOnly = used when approving.
    function reservation_conflict($conn, $facility, $date, $start, $end, $excludeId = 0, $approvedOnly = false) {
        $names = venue_facilities($facility);
        if (!$names) return false;

        $start = date('H:i:s', strtotime($start));
        $end   = date('H:i:s', strtotime($end));
        $cond  = $approvedOnly ? "status = 'approved'" : reservation_block_sql();
        $ph    = implode(',', array_fill(0, count($names), '?'));

        $sql = "SELECT id FROM reservations
                WHERE facility IN ($ph) AND res_date = ? AND id <> ?
                  AND start_time < ? AND end_time > ? AND $cond LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return true;   // be safe: treat a DB problem as "not available"

        $params = array_merge($names, [$date, (int) $excludeId, $end, $start]);
        $types  = str_repeat('s', count($names)) . 'siss';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $found;
    }
}

if (!function_exists('reservation_auto_approve')) {
    // Call this right after a reservation's payment_status becomes 'paid'.
    // Returns true if the reservation is now approved. If another approved booking
    // already overlaps (rare), it stays 'pending' so the admin can handle/refund it.
    function reservation_auto_approve($conn, $rid) {
        $rid = (int) $rid;
        $stmt = $conn->prepare("SELECT facility, res_date, start_time, end_time, status FROM reservations WHERE id = ?");
        $stmt->bind_param("i", $rid);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$r) return false;
        if ($r['status'] === 'approved') return true;
        if ($r['status'] !== 'pending') return false;   // rejected stays rejected

        if (reservation_conflict($conn, $r['facility'], $r['res_date'], $r['start_time'], $r['end_time'], $rid, true)) {
            error_log("Auto-approve blocked for reservation {$rid}: overlaps an approved booking.");
            return false;
        }

        $stmt = $conn->prepare("UPDATE reservations SET status = 'approved' WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("i", $rid);
        $stmt->execute();
        $stmt->close();
        return true;
    }
}
