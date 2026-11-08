<?php
// Shared by login.php, oauth_*.php, forgot_password.php, reset_password.php, resident/change_password.php

/* ---------------------------------------------------------
   Basic helpers
   --------------------------------------------------------- */
if (!function_exists('role_home')) {
    function role_home($role) {
        return BASE_PATH . ($role === 'admin' ? '/admin/dashboard.php' : '/resident/dashboard.php');
    }
}

if (!function_exists('client_ip')) {
    function client_ip() {
        return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    }
}

/* ---------------------------------------------------------
   Throttling / lockout (uses the login_attempts table)
   --------------------------------------------------------- */
if (!function_exists('throttle_left')) {
    // Seconds left in the lockout for this key, or 0 if not locked
    function throttle_left($conn, $key, $max, $minutes) {
        $key = substr($key, 0, 120);
        $minutes = (int) $minutes;
        $stmt = $conn->prepare("SELECT COUNT(*) AS c,
                                       TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) AS since
                                FROM login_attempts
                                WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)");
        $stmt->bind_param("si", $key, $minutes);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int) ($row['c'] ?? 0) >= $max) {
            return max(1, ($minutes * 60) - (int) ($row['since'] ?? 0));
        }
        return 0;
    }
}

if (!function_exists('throttle_hit')) {
    function throttle_hit($conn, $key) {
        $key = substr($key, 0, 120);
        $ip  = client_ip();
        $stmt = $conn->prepare("INSERT INTO login_attempts (identifier, ip) VALUES (?, ?)");
        $stmt->bind_param("ss", $key, $ip);
        $stmt->execute();
        $stmt->close();

        // Occasionally clean up old rows
        if (random_int(1, 50) === 1) {
            $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
        }
    }
}

if (!function_exists('throttle_clear')) {
    function throttle_clear($conn, $key) {
        $key = substr($key, 0, 120);
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE identifier = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->close();
    }
}

/* ---- Login lockout: 5 wrong tries per username/number OR 20 per IP -> locked for 10 minutes ---- */
if (!function_exists('login_identifier')) {
    function login_identifier($raw) {
        $raw = trim((string) $raw);
        return looks_like_phone($raw) ? normalize_phone($raw) : strtolower($raw);
    }
}

if (!function_exists('login_locked_seconds')) {
    function login_locked_seconds($conn, $identifier) {
        return max(
            throttle_left($conn, 'login:' . $identifier, 5, 10),
            throttle_left($conn, 'loginip:' . client_ip(), 20, 10)
        );
    }
}

if (!function_exists('login_record_failure')) {
    function login_record_failure($conn, $identifier) {
        throttle_hit($conn, 'login:' . $identifier);
        throttle_hit($conn, 'loginip:' . client_ip());
    }
}

if (!function_exists('login_clear_failures')) {
    function login_clear_failures($conn, $identifier) {
        throttle_clear($conn, 'login:' . $identifier);
    }
}

/* ---------------------------------------------------------
   Re-check the current password (before connect / disconnect Google, change password)
   Returns true, or an error message string
   --------------------------------------------------------- */
if (!function_exists('reauth_check')) {
    function reauth_check($conn, $uid, $password) {
        $key = 'reauth:' . (int) $uid;
        $left = throttle_left($conn, $key, 5, 10);
        if ($left > 0) {
            return 'Too many wrong passwords. Try again in ' . ceil($left / 60) . ' minute(s).';
        }

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && password_verify((string) $password, $row['password'])) {
            throttle_clear($conn, $key);
            return true;
        }
        throttle_hit($conn, $key);
        return 'Incorrect password.';
    }
}

/* ---------------------------------------------------------
   Finish a successful login (password, phone, Google)
   --------------------------------------------------------- */
if (!function_exists('complete_login')) {
    function complete_login($conn, array $user, $identifier = null) {
        $_SESSION['login_attempts'] = 0;
        session_regenerate_id(true);

        // Reset lockout counters
        if ($identifier !== null && $identifier !== '') login_clear_failures($conn, $identifier);
        if (!empty($user['username'])) login_clear_failures($conn, strtolower($user['username']));

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];

        $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: " . role_home($user['role']));
        exit;
    }
}

/* ---------------------------------------------------------
   Phone number helpers (PH numbers)
   0912 345 6789 / +63 912 345 6789 / 912-345-6789  ->  09123456789
   --------------------------------------------------------- */
if (!function_exists('normalize_phone')) {
    function normalize_phone($raw) {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') return '';
        if (strpos($d, '63') === 0 && strlen($d) === 12) return '0' . substr($d, 2);
        if (strlen($d) === 10 && $d[0] === '9') return '0' . $d;
        return $d;
    }
}

if (!function_exists('looks_like_phone')) {
    function looks_like_phone($s) {
        return (bool) preg_match('/^\+?[\d\s\-()]{10,}$/', trim($s));
    }
}

if (!function_exists('find_users_by_phone')) {
    // Accounts whose linked homeowner record has this contact number
    function find_users_by_phone($conn, $phone) {
        $norm = normalize_phone($phone);
        $out = [];
        if ($norm === '') return $out;

        $res = $conn->query("SELECT h.contact, u.* FROM homeowners h
                             JOIN users u ON u.id = h.user_id
                             WHERE h.contact IS NOT NULL AND h.contact <> ''");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (normalize_phone($row['contact']) === $norm) $out[] = $row;
            }
        }
        return $out;
    }
}

/* ---------------------------------------------------------
   One email per account
   --------------------------------------------------------- */
if (!function_exists('normalize_email')) {
    // Same mailbox = same value. Gmail ignores dots and anything after a plus sign.
    function normalize_email($raw) {
        $email = strtolower(trim((string) $raw));
        if (strpos($email, '@') === false) return $email;

        [$local, $domain] = explode('@', $email, 2);
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', explode('+', $local, 2)[0]);
            $domain = 'gmail.com';
        }
        return $local . '@' . $domain;
    }
}

if (!function_exists('email_in_use')) {
    // True when another account has this email on its profile or linked Google account.
    // Also true when the check cannot finish, so a database problem never lets a duplicate through.
    function email_in_use($conn, $email, $exceptUserId = 0, $exceptHomeownerId = 0) {
        $target = normalize_email($email);
        if ($target === '') return false;

        $exceptUserId = (int) $exceptUserId;
        $exceptHomeownerId = (int) $exceptHomeownerId;
        try {
            // Held until this request ends, so two people cannot claim one email at the same moment.
            $lock = $conn->query("SELECT GET_LOCK('manuela4b_email_check', 5) AS got");
            if (!$lock || (int) ($lock->fetch_assoc()['got'] ?? 0) !== 1) {
                throw new RuntimeException('Could not get the email check lock.');
            }

            $stmt = $conn->prepare("SELECT email FROM homeowners WHERE email IS NOT NULL AND email <> '' AND id <> ? AND (user_id IS NULL OR user_id <> ?)");
            $stmt->bind_param("ii", $exceptHomeownerId, $exceptUserId);
            $stmt->execute();
            $emails = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $stmt = $conn->prepare("SELECT email FROM user_identities WHERE email IS NOT NULL AND email <> '' AND user_id <> ?");
            $stmt->bind_param("i", $exceptUserId);
            $stmt->execute();
            $emails = array_merge($emails, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();

            foreach ($emails as $row) {
                if (normalize_email($row['email']) === $target) return true;
            }
            return false;
        } catch (Throwable $e) {
            error_log('Email uniqueness check failed: ' . $e->getMessage());
            return true;
        }
    }
}

/* ---------------------------------------------------------
   OAuth (Google only)
   --------------------------------------------------------- */
if (!function_exists('oauth_config')) {
    function oauth_config() {
        static $cfg = null;
        if ($cfg === null) $cfg = require __DIR__ . '/../config/oauth.php';
        return $cfg;
    }
}

if (!function_exists('oauth_redirect_uri')) {
    function oauth_redirect_uri() {
        return rtrim(oauth_config()['base_url'], '/') . '/oauth_callback.php';
    }
}

if (!function_exists('oauth_provider')) {
    // Returns provider settings, or null if unknown / not configured
    function oauth_provider($name) {
        if ($name !== 'google') return null;
        $cfg = oauth_config();
        $p = [
            'name'          => 'google',
            'label'         => 'Google',
            'auth_url'      => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'     => 'https://oauth2.googleapis.com/token',
            'scope'         => 'openid email profile',
            'client_id'     => $cfg['google']['client_id'] ?? '',
            'client_secret' => $cfg['google']['client_secret'] ?? '',
        ];
        if ($p['client_id'] === '' || $p['client_secret'] === '' || strpos($p['client_secret'], 'ILAGAY') === 0) return null;
        return $p;
    }
}

if (!function_exists('oauth_http')) {
    function oauth_http($url, $post = null, array $headers = []) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) throw new Exception($err ?: 'Connection failed');
        $json = json_decode($res, true);
        if (!is_array($json)) throw new Exception('Unexpected response from provider');
        return $json;
    }
}

if (!function_exists('oauth_fetch_profile')) {
    // Exchange the ?code for the user's profile: ['id' => sub, 'name' => ..., 'email' => ...]
    function oauth_fetch_profile(array $p, $code, $verifier) {
        $token = oauth_http($p['token_url'], [
            'code'          => $code,
            'client_id'     => $p['client_id'],
            'client_secret' => $p['client_secret'],
            'redirect_uri'  => oauth_redirect_uri(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,   // PKCE
        ]);
        if (empty($token['access_token'])) {
            throw new Exception($token['error_description'] ?? ($token['error'] ?? 'No access token received'));
        }

        $u  = oauth_http('https://openidconnect.googleapis.com/v1/userinfo', null, ['Authorization: Bearer ' . $token['access_token']]);
        $id = $u['sub'] ?? '';
        if ($id === '') throw new Exception('Could not read your Google profile');

        // Only trust the email if Google says it is verified
        $email = (!empty($u['email']) && !empty($u['email_verified'])) ? $u['email'] : '';

        return [
            'id'    => (string) $id,
            'name'  => $u['name'] ?? '',
            'email' => $email,
        ];
    }
}

/* ---------------------------------------------------------
   Forgot password: email sending + reset links
   --------------------------------------------------------- */
if (!function_exists('app_send_mail')) {
    function app_send_mail($toEmail, $toName, $subject, $htmlBody) {
        $cfg = require __DIR__ . '/mail.php';

        // Your PHPMailer files sit directly in /PHPMailer (no /src folder)
        require_once __DIR__ . '/../PHPMailer/Exception.php';
        require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/../PHPMailer/SMTP.php';

        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host       = $cfg['host'];
        $m->SMTPAuth   = true;
        $m->Username   = $cfg['username'];
        $m->Password   = $cfg['password'];
        $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->Port       = $cfg['port'];
        $m->CharSet    = 'UTF-8';

        $m->setFrom($cfg['username'], $cfg['from_name']);
        $m->addAddress($toEmail, $toName);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body    = $htmlBody;
        $m->AltBody = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $htmlBody)));
        $m->send();
    }
}

if (!function_exists('get_user_email')) {
    // Tries homeowners.email first, then the verified email of a linked Google account
    function get_user_email($conn, $uid) {
        try {
            $stmt = $conn->prepare("SELECT email FROM homeowners WHERE user_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return $row['email'];
            }
        } catch (Throwable $e) { /* fall through */ }

        try {
            $stmt = $conn->prepare("SELECT email FROM user_identities WHERE user_id = ? AND email <> '' LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!empty($row['email'])) return $row['email'];
            }
        } catch (Throwable $e) {}

        return '';
    }
}

if (!function_exists('send_reset_links')) {
    // Never reveals to the caller whether the account exists
    function send_reset_links($conn, $raw) {
        $raw = trim((string) $raw);
        $users = [];

        if (looks_like_phone($raw)) {
            $users = find_users_by_phone($conn, $raw);
        } else {
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param("s", $raw);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($u) $users[] = $u;
        }

        foreach (array_slice($users, 0, 3) as $u) {
            $uid   = (int) $u['id'];
            $email = get_user_email($conn, $uid);
            if ($email === '') continue;

            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);

            $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at)
                                    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
            $stmt->bind_param("is", $uid, $hash);
            $stmt->execute();
            $stmt->close();

            $link = rtrim(BASE_URL, '/') . '/reset_password.php?token=' . $token;
            $name = htmlspecialchars($u['full_name'] ?: 'Resident', ENT_QUOTES, 'UTF-8');
            $body = "<p>Hello {$name},</p>"
                  . "<p>We received a request to reset your Manuela 4B MIS password. Click the button below to choose a new one. This link expires in 30 minutes and can be used once.</p>"
                  . "<p><a href=\"{$link}\" style=\"background:#1b4332;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;display:inline-block;\">Reset Password</a></p>"
                  . "<p>If you did not ask for this, you can ignore this email. Your password will not change.</p>";

            try {
                app_send_mail($email, $u['full_name'] ?: 'Resident', 'Reset your password - Manuela 4B MIS', $body);
            } catch (Throwable $e) {
                error_log('Reset mail failed: ' . $e->getMessage());
            }
        }
    }
}