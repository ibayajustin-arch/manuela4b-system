<?php
// Reusable OTP helpers (email only): otp_create(), otp_verify(), otp_deliver(), otp_request_for_identifier()
require_once __DIR__ . '/auth_helpers.php';

/* Create a 6-digit code; prior unused codes remain valid until success or expiry. */
if (!function_exists('otp_create')) {
    function otp_create($conn, $uid, $purpose) {
        $uid  = (int) $uid;
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("DELETE FROM otp_codes
                                                                WHERE user_id = ? AND purpose = ?
                                                                    AND (used_at IS NOT NULL OR expires_at <= NOW())");
        $stmt->bind_param("is", $uid, $purpose);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO otp_codes (user_id, purpose, code_hash, expires_at)
                                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        $stmt->bind_param("iss", $uid, $purpose, $hash);
        $stmt->execute();
        $stmt->close();

        return $code;
    }
}

/* Seconds until a new code may be sent (60-second cooldown), or 0 */
if (!function_exists('otp_cooldown_left')) {
    function otp_cooldown_left($conn, $uid, $purpose) {
        $uid = (int) $uid;
        $stmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS s
                                FROM otp_codes WHERE user_id = ? AND purpose = ?
                                ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("is", $uid, $purpose);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return 0;
        return max(0, 60 - (int) $row['s']);
    }
}

/* Check a code. Returns true on success, or 'none' | 'locked' | 'wrong' */
if (!function_exists('otp_verify')) {
    function otp_verify($conn, $uid, $purpose, $code) {
        $uid = (int) $uid;
        $stmt = $conn->prepare("SELECT id, code_hash, attempts FROM otp_codes
                                WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
                                ORDER BY id DESC");
        $stmt->bind_param("is", $uid, $purpose);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!$rows) return 'none';
        $hasAttemptsLeft = false;
        foreach ($rows as $row) {
            if ((int) $row['attempts'] < 5) {
                $hasAttemptsLeft = true;
                if (password_verify((string) $code, $row['code_hash'])) {
                    $stmt = $conn->prepare("UPDATE otp_codes SET used_at = NOW()
                                            WHERE user_id = ? AND purpose = ? AND used_at IS NULL");
                    $stmt->bind_param("is", $uid, $purpose);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            }
        }

        if (!$hasAttemptsLeft) return 'locked';

        $stmt = $conn->prepare("UPDATE otp_codes SET attempts = attempts + 1
                                WHERE user_id = ? AND purpose = ? AND used_at IS NULL
                                  AND expires_at > NOW() AND attempts < 5");
        $stmt->bind_param("is", $uid, $purpose);
        $stmt->execute();
        $stmt->close();
        return 'wrong';
    }
}

/* Send the code by email. Returns true if the email was sent. */
if (!function_exists('otp_deliver')) {
    function otp_deliver($conn, array $user, $code, $label = 'password reset') {
        $email = get_user_email($conn, (int) $user['id']);
        if ($email === '') {
            error_log('OTP: no email on file for user ' . (int) $user['id']);
            return false;
        }

        $name = htmlspecialchars($user['full_name'] ?: 'Resident', ENT_QUOTES, 'UTF-8');
        $body = "<p>Hello {$name},</p>"
              . "<p>Your Manuela 4B MIS {$label} code is:</p>"
              . "<p style=\"font-size:28px;font-weight:700;letter-spacing:6px;color:#1b4332;\">{$code}</p>"
              . "<p>This code expires in 10 minutes. Do not share it with anyone. If you did not request it, you can ignore this email.</p>";
        try {
            app_send_mail($email, $user['full_name'] ?: 'Resident', "Your verification code - Manuela 4B MIS", $body);
            return true;
        } catch (Throwable $e) {
            error_log('OTP mail failed: ' . $e->getMessage());
            return false;
        }
    }
}

/* Find the account(s) that use this EMAIL, email each a code, return their user ids.
   Matches homeowners.email or the verified email of a linked Google account. */
if (!function_exists('otp_request_for_identifier')) {
    function otp_request_for_identifier($conn, $raw, $purpose = 'reset') {
        $email = strtolower(trim((string) $raw));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [];

        $users = [];
        $seen  = [];

        $sqls = [
            "SELECT u.* FROM homeowners h JOIN users u ON u.id = h.user_id
             WHERE LOWER(h.email) = ? LIMIT 3",
            "SELECT u.* FROM user_identities i JOIN users u ON u.id = i.user_id
             WHERE LOWER(i.email) = ? LIMIT 3",
        ];
        foreach ($sqls as $sql) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) continue;
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($u = $res->fetch_assoc()) {
                if (isset($seen[$u['id']])) continue;
                $seen[$u['id']] = true;
                $users[] = $u;
            }
            $stmt->close();
        }

        $uids = [];
        foreach (array_slice($users, 0, 3) as $u) {
            $uid    = (int) $u['id'];
            $uids[] = $uid;
            if (otp_cooldown_left($conn, $uid, $purpose) > 0) continue;   // a fresh code was just sent
            $code = otp_create($conn, $uid, $purpose);
            otp_deliver($conn, $u, $code, 'password reset');
        }
        return $uids;
    }
}