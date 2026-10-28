<?php
// Notification helpers for Admin
require_once __DIR__ . '/payment_methods.php';
require_once __DIR__ . '/homeowner_helpers.php';

if (!function_exists('notification_url')) {
    function notification_url(string $path): string {
        $basePath = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
        return $basePath . '/' . ltrim($path, '/');
    }
}
if (!function_exists('human_time_ago')) {
    function human_time_ago($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '';
        $time = strtotime($datetime);
        $diff = time() - $time;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) {
            $mins = max(1, (int)floor($diff / 60));
            return $mins === 1 ? '1 min ago' : "{$mins} mins ago";
        }
        if ($diff < 86400) {
            $hrs = max(1, (int)floor($diff / 3600));
            return $hrs === 1 ? '1 hr ago' : "{$hrs} hrs ago";
        }
        if ($diff < 172800) return 'Yesterday at ' . date('g:i A', $time);
        return date('M d, Y · g:i A', $time);
    }
}

if (!function_exists('human_time_taglish')) {
    function human_time_taglish($datetime) {
        return human_time_ago($datetime);
    }
}

if (!function_exists('get_admin_notifications')) {
    function get_admin_notifications($conn, $limit = 25) {
        $items = [];
        $lastRead = $_SESSION['admin_last_read_notif'] ?? date('Y-m-d H:i:s', strtotime('-3 days'));

        if (!$conn || !($conn instanceof mysqli)) {
            return ['items' => [], 'unread_count' => 0];
        }

        // 1. Recent Payments
        try {
            $qPay = $conn->query(
                "SELECT p.id, p.amount_paid, p.payment_method, p.notes, p.period_covered, p.receipt_no, p.created_at,
                        h.full_name AS homeowner_name, h.block, h.lot
                 FROM payments p
                 LEFT JOIN homeowners h ON p.homeowner_id = h.id
                 ORDER BY p.created_at DESC LIMIT 10"
            );
            if ($qPay) {
                while ($r = $qPay->fetch_assoc()) {
                    $name = !empty($r['homeowner_name']) ? $r['homeowner_name'] : 'Homeowner';
                    $residentAddress = homeowner_address_label($r['block'] ?? '', $r['lot'] ?? '');
                    $method = payment_method_label($r['payment_method'] ?? null, $r['notes'] ?? null);
                    $period = !empty($r['period_covered']) ? $r['period_covered'] : '';
                    $periodText = !empty($period) ? " for {$period}" : '';

                    $items[] = [
                        'id' => 'pay_' . $r['id'],
                        'type' => 'payment',
                        'category_label' => 'Payment',
                        'title' => 'Payment Received',
                        'description' => "<strong>" . htmlspecialchars($name . ' · ' . $residentAddress) . "</strong> paid <strong>₱" . number_format((float)$r['amount_paid'], 2) . "</strong> via {$method}{$periodText}.",
                        'time' => $r['created_at'],
                        'time_ago' => human_time_ago($r['created_at']),
                        'link' => notification_url('/admin/payments.php#payment-record-' . (int) $r['id']),
                        'icon' => 'bi-cash-coin',
                        'icon_bg' => '#DCFCE7',
                        'icon_color' => '#15803D',
                        'is_unread' => strtotime($r['created_at']) > strtotime($lastRead)
                    ];
                }
            }
        } catch (Exception $e) {}

        // 2. Recent Reservations
        try {
            $qRes = $conn->query(
                "SELECT r.id, r.facility, r.res_date, r.start_time, r.end_time, r.status, r.created_at,
                        u.full_name AS resident_name
                 FROM reservations r
                 LEFT JOIN users u ON r.user_id = u.id
                 ORDER BY r.created_at DESC LIMIT 10"
            );
            if ($qRes) {
                while ($r = $qRes->fetch_assoc()) {
                    $name = !empty($r['resident_name']) ? $r['resident_name'] : 'Resident';
                    $venue = htmlspecialchars($r['facility'] ?? 'Venue');
                    $date = !empty($r['res_date']) ? date('M d, Y', strtotime($r['res_date'])) : '';
                    $timeSlot = (!empty($r['start_time']) && !empty($r['end_time'])) 
                        ? date('g:i A', strtotime($r['start_time'])) . ' - ' . date('g:i A', strtotime($r['end_time']))
                        : '';
                    $status = strtolower($r['status'] ?? 'pending');
                    $statusTag = ($status === 'pending') 
                        ? '<span class="badge bg-warning text-dark">Pending</span>' 
                        : '<span class="badge bg-success">Approved</span>';

                    $timeText = !empty($timeSlot) ? " ({$timeSlot})" : '';

                    $items[] = [
                        'id' => 'res_' . $r['id'],
                        'type' => 'reservation',
                        'category_label' => 'Reservation',
                        'title' => 'New Facility Booking',
                        'description' => "<strong>" . htmlspecialchars($name) . "</strong> booked <strong>{$venue}</strong> on {$date}{$timeText}. {$statusTag}",
                        'time' => $r['created_at'],
                        'time_ago' => human_time_ago($r['created_at']),
                        'link' => notification_url('/admin/reservations.php'),
                        'icon' => 'bi-calendar-event-fill',
                        'icon_bg' => '#FEF3C7',
                        'icon_color' => '#92400E',
                        'is_unread' => strtotime($r['created_at']) > strtotime($lastRead)
                    ];
                }
            }
        } catch (Exception $e) {}

        // 3. Recent Resident Feedback
        try {
            $qFb = $conn->query(
                "SELECT f.id, f.category, f.subject, f.message, f.status, f.created_at,
                        u.full_name AS resident_name
                 FROM resident_feedback f
                 LEFT JOIN users u ON f.user_id = u.id
                 ORDER BY f.created_at DESC LIMIT 10"
            );
            if ($qFb) {
                while ($r = $qFb->fetch_assoc()) {
                    $name = !empty($r['resident_name']) ? $r['resident_name'] : 'Resident';
                    $cat = ($r['category'] === 'concern') ? 'Concern' : 'Suggestion';
                    $subj = htmlspecialchars($r['subject'] ?? 'No subject');

                    $items[] = [
                        'id' => 'fb_' . $r['id'],
                        'type' => 'feedback',
                        'category_label' => 'Feedback',
                        'title' => "New Resident {$cat}",
                        'description' => "Message from <strong>" . htmlspecialchars($name) . "</strong>: &ldquo;{$subj}&rdquo;.",
                        'time' => $r['created_at'],
                        'time_ago' => human_time_ago($r['created_at']),
                        'link' => notification_url('/admin/feedback.php'),
                        'icon' => 'bi-chat-left-heart-fill',
                        'icon_bg' => '#FEE2E2',
                        'icon_color' => '#B91C1C',
                        'is_unread' => strtotime($r['created_at']) > strtotime($lastRead)
                    ];
                }
            }
        } catch (Exception $e) {}

        // 4. Recent homeowner registrations. These are pending applications
        // that admins need to verify before the account is activated.
        try {
            $qReg = $conn->query(
                "SELECT u.id, u.full_name, u.created_at, h.block, h.lot
                 FROM users u
                 INNER JOIN homeowners h ON h.user_id = u.id
                 WHERE u.role = 'homeowner'
                 ORDER BY u.created_at DESC LIMIT 10"
            );
            if ($qReg) {
                while ($r = $qReg->fetch_assoc()) {
                    $name = htmlspecialchars($r['full_name'] ?: 'Homeowner');
                    $address = homeowner_address_label($r['block'] ?? '', $r['lot'] ?? '');
                    $createdAt = $r['created_at'] ?? '';
                    $items[] = [
                        'id' => 'reg_' . $r['id'],
                        'type' => 'homeowner_registration',
                        'category_label' => 'Registration',
                        'title' => 'New Homeowner Registration',
                        'description' => "<strong>{$name}</strong> · " . htmlspecialchars($address) . ' submitted an account application for verification.',
                        'time' => $createdAt,
                        'time_ago' => human_time_ago($createdAt),
                        'link' => notification_url('/admin/pending_homeowners.php'),
                        'icon' => 'bi-person-plus-fill',
                        'icon_bg' => '#DBEAFE',
                        'icon_color' => '#1D4ED8',
                        'is_unread' => strtotime($createdAt) > strtotime($lastRead)
                    ];
                }
            }
        } catch (Exception $e) {}

        // 5. Other recorded actions (homeowner changes, account actions,
        // announcements, reminders, and reservation/payment administration).
        // Exclude payment and reservation events already represented above.
        try {
            $qActivity = $conn->query(
                "SELECT a.id, a.user_id, a.action, a.details, a.created_at,
                        COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), NULLIF(a.admin_name, ''), 'Admin') AS actor_name
                 FROM activity_log a
                 LEFT JOIN users u ON a.user_id = u.id
                 WHERE LOWER(COALESCE(a.action, '')) NOT LIKE '%payment%'
                   AND LOWER(COALESCE(a.action, '')) NOT LIKE '%reservation%'
                 ORDER BY a.created_at DESC, a.id DESC LIMIT 25"
            );
            if ($qActivity) {
                while ($r = $qActivity->fetch_assoc()) {
                    $action = trim((string) ($r['action'] ?? 'Activity'));
                    $actor = htmlspecialchars((string) ($r['actor_name'] ?? 'Admin'));
                    $details = trim((string) ($r['details'] ?? ''));
                    $description = "<strong>{$actor}</strong> " . htmlspecialchars(lcfirst($action));
                    if ($details !== '') $description .= ': ' . htmlspecialchars($details);
                    $lowerAction = strtolower($action);
                    $link = (strpos($lowerAction, 'announcement') !== false)
                        ? notification_url('/admin/announcements.php')
                        : ((strpos($lowerAction, 'feedback') !== false)
                            ? notification_url('/admin/feedback.php')
                            : ((strpos($lowerAction, 'homeowner') !== false || strpos($lowerAction, 'profile') !== false || strpos($lowerAction, 'account') !== false)
                                ? notification_url('/admin/homeowners.php')
                                : notification_url('/admin/dashboard.php')));
                    $createdAt = $r['created_at'] ?? '';
                    $items[] = [
                        'id' => 'activity_' . $r['id'],
                        'type' => 'activity',
                        'category_label' => 'Activity',
                        'title' => ucfirst($action),
                        'description' => $description,
                        'time' => $createdAt,
                        'time_ago' => human_time_ago($createdAt),
                        'link' => $link,
                        'icon' => 'bi-activity',
                        'icon_bg' => '#EDE9FE',
                        'icon_color' => '#6D28D9',
                        'is_unread' => strtotime($createdAt) > strtotime($lastRead)
                    ];
                }
            }
        } catch (Exception $e) {}

        // Sort by newest first
        usort($items, function($a, $b) {
            return strcmp($b['time'], $a['time']);
        });

        $unreadCount = 0;
        foreach ($items as $item) {
            if (!empty($item['is_unread'])) {
                $unreadCount++;
            }
        }

        return [
            'items' => array_slice($items, 0, $limit),
            'unread_count' => $unreadCount
        ];
    }
}

if (!function_exists('get_resident_notifications')) {
    function get_resident_notifications(mysqli $conn, int $userId, int $limit = 25): array {
        $items = [];
        $lastRead = $_SESSION['resident_last_read_notif'] ?? date('Y-m-d H:i:s', strtotime('-3 days'));
        $lastReadTs = strtotime((string) $lastRead) ?: 0;
        $add = static function (array $item) use (&$items, $lastReadTs): void {
            $item['is_unread'] = (strtotime((string) ($item['time'] ?? '')) ?: 0) > $lastReadTs;
            $item['time_ago'] = human_time_ago($item['time'] ?? '');
            $items[] = $item;
        };

        // Community announcements
        try {
            $result = $conn->query("SELECT id, title, message, created_at FROM announcements WHERE archived_at IS NULL ORDER BY created_at DESC LIMIT 15");
            while ($result && ($row = $result->fetch_assoc())) {
                $add(['id' => 'announcement_' . $row['id'], 'type' => 'announcement', 'category_label' => 'Announcement',
                    'title' => (string) $row['title'], 'description' => htmlspecialchars(mb_strimwidth(strip_tags((string) $row['message']), 0, 180, '…')),
                    'time' => $row['created_at'], 'link' => notification_url('/resident/profile.php'), 'icon' => 'bi-megaphone-fill', 'icon_bg' => '#EDE9FE', 'icon_color' => '#6D28D9']);
            }
        } catch (Throwable $e) {}

        // Reservation updates for this resident.
        try {
            $stmt = $conn->prepare("SELECT id, facility, res_date, status, created_at FROM reservations WHERE user_id = ? AND COALESCE(archived_at, '') = '' ORDER BY created_at DESC LIMIT 15");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $status = ucfirst((string) $row['status']);
                $date = !empty($row['res_date']) ? date('M d, Y', strtotime($row['res_date'])) : '';
                $add(['id' => 'resident_res_' . $row['id'], 'type' => 'reservation', 'category_label' => 'Reservation',
                    'title' => 'Reservation ' . $status, 'description' => htmlspecialchars($row['facility'] . ($date ? ' · ' . $date : '') . ' — ' . $status),
                    'time' => $row['created_at'], 'link' => notification_url('/resident/reservations.php'), 'icon' => 'bi-calendar-event-fill', 'icon_bg' => '#FEF3C7', 'icon_color' => '#92400E']);
            }
            $stmt->close();
        } catch (Throwable $e) {}

        // Account-specific actions such as reservation approval/rejection.
        try {
            $stmt = $conn->prepare("SELECT h.id FROM homeowners h WHERE h.user_id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $homeownerId = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
            $stmt->close();
            if ($homeownerId > 0) {
                $stmt = $conn->prepare("SELECT id, action, details, created_at FROM activity_log WHERE homeowner_id = ? ORDER BY created_at DESC, id DESC LIMIT 15");
                $stmt->bind_param('i', $homeownerId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $add(['id' => 'resident_activity_' . $row['id'], 'type' => 'activity', 'category_label' => 'Account Update',
                        'title' => ucfirst((string) $row['action']), 'description' => htmlspecialchars((string) ($row['details'] ?: 'Your account has an update.')),
                        'time' => $row['created_at'], 'link' => notification_url('/resident/profile.php'), 'icon' => 'bi-info-circle-fill', 'icon_bg' => '#DBEAFE', 'icon_color' => '#1D4ED8']);
                }
                $stmt->close();
            }
        } catch (Throwable $e) {}

        // Payments recorded for this resident.
        try {
            $stmt = $conn->prepare("SELECT p.id, p.amount_paid, p.period_covered, p.created_at FROM payments p INNER JOIN homeowners h ON h.id = p.homeowner_id WHERE h.user_id = ? AND p.archived_at IS NULL ORDER BY p.created_at DESC LIMIT 15");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $add(['id' => 'resident_pay_' . $row['id'], 'type' => 'payment', 'category_label' => 'Payment',
                    'title' => 'Payment Recorded', 'description' => '₱' . number_format((float) $row['amount_paid'], 2) . ' payment' . (!empty($row['period_covered']) ? ' for ' . $row['period_covered'] : '') . ' recorded.',
                    'time' => $row['created_at'], 'link' => notification_url('/resident/payments.php'), 'icon' => 'bi-cash-coin', 'icon_bg' => '#DCFCE7', 'icon_color' => '#15803D']);
            }
            $stmt->close();
        } catch (Throwable $e) {}

        // Replies to suggestions and concerns.
        try {
            $stmt = $conn->prepare("SELECT id, subject, admin_reply, admin_replied_at FROM resident_feedback WHERE user_id = ? AND archived_at IS NULL AND admin_reply IS NOT NULL AND admin_reply <> '' ORDER BY admin_replied_at DESC LIMIT 15");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $add(['id' => 'resident_feedback_' . $row['id'], 'type' => 'feedback', 'category_label' => 'Feedback',
                    'title' => 'You received a reply', 'description' => htmlspecialchars((string) $row['subject'] . ': ' . mb_strimwidth(strip_tags((string) $row['admin_reply']), 0, 140, '…')),
                    'time' => $row['admin_replied_at'], 'link' => notification_url('/resident/feedback.php'), 'icon' => 'bi-chat-left-heart-fill', 'icon_bg' => '#FEE2E2', 'icon_color' => '#B91C1C']);
            }
            $stmt->close();
        } catch (Throwable $e) {}

        usort($items, static fn($a, $b) => strcmp((string) $b['time'], (string) $a['time']));
        $items = array_slice($items, 0, $limit);
        return ['items' => $items, 'unread_count' => count(array_filter($items, static fn($item) => !empty($item['is_unread'])))];
    }
}
