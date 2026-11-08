<?php
// oauth_callback.php  (this exact URL is what you register as the "Redirect URI")
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/auth_helpers.php';

$expected = $_SESSION['oauth_state'] ?? '';
$verifier = $_SESSION['oauth_verifier'] ?? '';
$name     = $_SESSION['oauth_provider'] ?? '';
$mode     = $_SESSION['oauth_mode'] ?? 'login';
$loginRole = $_SESSION['oauth_login_role'] ?? '';
unset($_SESSION['oauth_state'], $_SESSION['oauth_verifier'], $_SESSION['oauth_provider'], $_SESSION['oauth_mode'], $_SESSION['oauth_login_role']);

// The connect result is shown in the Connected Accounts card on the resident profile.
function oauth_connect_return() {
    return ($_SESSION['role'] ?? '') === 'admin'
        ? role_home('admin')
        : BASE_PATH . '/resident/profile.php#connectedAccounts';
}

function oauth_fail($mode, $msg, $loginRole = '') {
    if ($mode === 'connect' && !empty($_SESSION['user_id'])) {
        $_SESSION['oauth_flash'] = ['type' => 'danger', 'msg' => $msg];
        header("Location: " . oauth_connect_return());
    } else {
        $_SESSION['login_error'] = $msg;
        header("Location: " . BASE_PATH . ($loginRole === 'admin' ? '/admin/login.php' : '/login.php'));
    }
    exit;
}

// 1. CSRF (state) check
if ($expected === '' || $verifier === '' || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
    oauth_fail($mode, "Login session expired. Please try again.", $loginRole);
}

$p = oauth_provider($name);
if (!$p) oauth_fail($mode, "That login method is not available.", $loginRole);

// 2. User pressed Cancel / Google returned an error
if (isset($_GET['error']) || empty($_GET['code'])) {
    oauth_fail($mode, "{$p['label']} sign-in was cancelled.", $loginRole);
}

// 3. Get the verified profile from Google
try {
    $profile = oauth_fetch_profile($p, $_GET['code'], $verifier);
} catch (Exception $e) {
    error_log('OAuth error: ' . $e->getMessage());
    oauth_fail($mode, "Could not verify with {$p['label']}. Please try again.", $loginRole);
}

/* ------------------------------------------------------------
   CONNECT: attach this Google account to the logged-in user
   ------------------------------------------------------------ */
if ($mode === 'connect') {
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) oauth_fail('login', "Please log in first.");

    $stmt = $conn->prepare("SELECT user_id FROM user_identities WHERE provider = ? AND provider_user_id = ?");
    $stmt->bind_param("ss", $name, $profile['id']);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing && (int) $existing['user_id'] !== $uid) {
        oauth_fail('connect', "That {$p['label']} account is already linked to a different resident.");
    }

    if (!$existing) {
        if ($profile['email'] === '') {
            oauth_fail('connect', "Please use a {$p['label']} account with a verified email address.");
        }
        if (email_in_use($conn, $profile['email'], $uid)) {
            oauth_fail('connect', "That {$p['label']} email is already used by another account. Please use a different one.");
        }

        // One Google account per user: replace the old one, if any
        $stmt = $conn->prepare("DELETE FROM user_identities WHERE user_id = ? AND provider = ?");
        $stmt->bind_param("is", $uid, $name);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO user_identities (user_id, provider, provider_user_id, email, display_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $uid, $name, $profile['id'], $profile['email'], $profile['name']);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION['oauth_flash'] = ['type' => 'success', 'msg' => "{$p['label']} connected. You can now log in with it."];
    header("Location: " . oauth_connect_return());
    exit;
}

/* ------------------------------------------------------------
   LOGIN: find the account linked to this Google ID (sub)
   ------------------------------------------------------------ */
$stmt = $conn->prepare("SELECT u.* FROM user_identities i
                        JOIN users u ON u.id = i.user_id
                        WHERE i.provider = ? AND i.provider_user_id = ?");
$stmt->bind_param("ss", $name, $profile['id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $message = $loginRole === 'admin'
        ? 'No administrator account is linked to this Google account. Use the Google account linked to your admin profile.'
        : "No account is linked to this {$p['label']} yet. Log in with your username first, then connect it from your profile.";
    oauth_fail('login', $message, $loginRole);
}

if ($loginRole !== '' && ($user['role'] ?? '') !== $loginRole) {
    oauth_fail('login', 'This Google account is not linked to an administrator account.', $loginRole);
}

complete_login($conn, $user);