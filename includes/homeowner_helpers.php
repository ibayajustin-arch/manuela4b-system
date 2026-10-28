<?php
// Shared helpers for homeowner_view.php, homeowner_action.php and statement.php

if (!function_exists('homeowner_address_label')) {
    function homeowner_address_label($street, $houseNumber): string {
        $street = trim((string) $street);
        $houseNumber = trim((string) $houseNumber);
        $streets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];
        foreach ($streets as $knownStreet) {
            if (strcasecmp($street, $knownStreet) === 0) {
                $street = $knownStreet;
                break;
            }
        }

        if ($street !== '' && in_array(strtolower($street), array_map('strtolower', $streets), true)) {
            $address = trim(($houseNumber !== '' ? $houseNumber . ' ' : '') . $street . ' St.');
            return $address . ', Manuela 4B, Pamplona Tres';
        }

        // Keep legacy numeric block data truthful until a street name is recorded.
        if ($street !== '' && $houseNumber !== '') return 'Block ' . $street . ', Lot ' . $houseNumber;
        if ($street !== '') return 'Block ' . $street;
        if ($houseNumber !== '') return 'House no. ' . $houseNumber;
        return 'Address unavailable';
    }
}

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

if (!function_exists('column_exists')) {
    function column_exists($conn, $table, $column) {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('table_exists')) {
    function table_exists($conn, $table) {
        $t = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '$t'");
        return $res && $res->num_rows > 0;
    }
}

if (!function_exists('homeowner_activity_count')) {
    /** Count distinct account events shown in the homeowner activity timeline. */
    function homeowner_activity_count($conn, int $homeownerId, ?int $userId): int {
        $total = 0;
        if (table_exists($conn, 'activity_log')) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM activity_log a
                WHERE a.homeowner_id = ?
                  AND NOT (LOWER(COALESCE(a.action, '')) = 'recorded payment'
                    AND EXISTS (SELECT 1 FROM payments p
                      WHERE p.homeowner_id = a.homeowner_id
                        AND a.details LIKE CONCAT('%', p.receipt_no, '%')))");
            if ($stmt) {
                $stmt->bind_param('i', $homeownerId);
                if ($stmt->execute()) $total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                $stmt->close();
            }
        }
        if (table_exists($conn, 'payments')) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM payments WHERE homeowner_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $homeownerId);
                if ($stmt->execute()) $total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                $stmt->close();
            }
        }
        if ($userId && table_exists($conn, 'reservations')) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM reservations WHERE user_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                if ($stmt->execute()) $total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                $stmt->close();
            }
        }
        return $total;
    }
}

if (!function_exists('hv_aging_label')) {
    function hv_aging_label($streak) {
        if ($streak <= 0) return 'Current';
        if ($streak == 1) return 'Due This Month';
        if ($streak == 2) return '31-60 Days';
        if ($streak == 3) return '61-90 Days';
        return '90+ Days';
    }
}

if (!function_exists('homeowner_account_summary')) {
    /** Billing starts in the month of activation (legacy records fall back to creation month). */
    function homeowner_dues_start_ym(array $h): string {
        $start = trim((string) ($h['dues_start_date'] ?? ''));
        if ($start !== '' && $start !== '0000-00-00') return date('Y-m', strtotime($start));
        $created = $h['created_at'] ?? 'now';
        return date('Y-m', strtotime($created));
    }

    /**
     * Payment standing for one homeowner (same rules as analytics_data.php):
     * expected months = activation month .. current month.
     */
    function homeowner_account_summary($conn, array $h, ?array $preloadedPayments = null) {
        $payments = $preloadedPayments ?? [];
        if ($preloadedPayments === null) {
            $stmt = $conn->prepare("SELECT * FROM payments WHERE homeowner_id = ? ORDER BY payment_date DESC, id DESC");
            if ($stmt) {
                $stmt->bind_param("i", $h['id']);
                $stmt->execute();
                $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }

        $paidMap = [];
        $activePayments = array_values(array_filter($payments, static function ($payment) {
            return empty($payment['archived_at']);
        }));
        foreach ($activePayments as $p) {
            $period = (string) $p['period_covered'];
            $paidMap[$period] = ($paidMap[$period] ?? 0) + (float) ($p['amount_paid'] ?? 0);
        }

        $duesStartYm = homeowner_dues_start_ym($h);
        $expected = generate_period_range($duesStartYm, date('Y-m'));
        $monthlyDue = (float) ($h['monthly_due'] ?? 0);
        $unpaid = array_values(array_filter($expected, fn($ym) => (float) ($paidMap[$ym] ?? 0) < $monthlyDue));

        $streak = 0;
        for ($i = count($expected) - 1; $i >= 0; $i--) {
            if ((float) ($paidMap[$expected[$i]] ?? 0) >= $monthlyDue) break;
            $streak++;
        }

        $expectedCount = count($expected);
        $paidCount = count(array_filter($expected, fn($ym) => (float) ($paidMap[$ym] ?? 0) >= $monthlyDue));
        $outstanding = array_sum(array_map(fn($ym) => max(0, $monthlyDue - (float) ($paidMap[$ym] ?? 0)), $unpaid));

        return [
            'payments'      => $activePayments,
            'all_payments'  => $payments,
            'paid_map'      => $paidMap,
            'expected'      => $expected,
            'unpaid'        => $unpaid,
            'unpaid_count'  => count($unpaid),
            'streak'        => $streak,
            'score'         => $expectedCount > 0 ? (int) round(($paidCount / $expectedCount) * 100) : 100,
            'monthly_due'   => $monthlyDue,
            'outstanding'   => $outstanding,
            'last_payment'  => $activePayments[0] ?? null,
        ];
    }
}

if (!function_exists('log_activity')) {
    function log_activity($conn, $homeowner_id, $action, $details = null) {
        try {
            $adminId = (int) ($_SESSION['user_id'] ?? 0);

            // Get the admin name for the activity log.
            $adminName = 'Admin';
            $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['username'])) $adminName = $row['username'];

            $stmt = $conn->prepare(
                "INSERT INTO activity_log (user_id, homeowner_id, admin_name, action, details)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iisss", $adminId, $homeowner_id, $adminName, $action, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            // Activity logging must not interrupt the action.
        }
    }
}
