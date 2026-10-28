<?php
// oauth_callback.php  (this exact URL is what you register as the "Redirect URI")
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/auth_helpers.php';

$expected = $_SESSION['oauth_state'] ?? '';
$verifier = $_SESSION['oauth_verifier'] ?? '';
$name     = $_SESSION['oauth_provider'] ?? '';
$mode     = $_SESSION['oauth_mode'] ?? 'login';
unset($_SESSION['oauth_state'], $_SESSION['oauth_verifier'], $_SESSION['oauth_provider'], $_SESSION['oauth_mode']);

function oauth_fail($mode, $msg) {
    if ($mode === 'connect' && !empty($_SESSION['user_id'])) {
        $_SESSION['oauth_flash'] = ['type' => 'danger', 'msg' => $msg];
        header("Location: " . role_home($_SESSION['role'] ?? 'homeowner'));
    } else {
        $_SESSION['login_error'] = $msg;
        header("Location: " . BASE_PATH . "/login.php");
    }
    exit;
}

// 1. CSRF (state) check
if ($expected === '' || $verifier === '' || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
    oauth_fail($mode, "Login session expired. Please try again.");
}

$p = oauth_provider($name);
if (!$p) oauth_fail($mode, "That login method is not available.");

// 2. User pressed Cancel / Google returned an error
if (isset($_GET['error']) || empty($_GET['code'])) {
    oauth_fail($mode, "{$p['label']} sign-in was cancelled.");
}

// 3. Get the verified profile from Google
try {
    $profile = oauth_fetch_profile($p, $_GET['code'], $verifier);
} catch (Exception $e) {
    error_log('OAuth error: ' . $e->getMessage());
    oauth_fail($mode, "Could not verify with {$p['label']}. Please try again.");
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
    header("Location: " . role_home($_SESSION['role'] ?? 'homeowner'));
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
    oauth_fail('login', "No account is linked to this {$p['label']} yet. Log in with your username first, then connect it from your profile.");
}

complete_login($conn, $user);