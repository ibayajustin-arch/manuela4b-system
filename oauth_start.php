<?php
//   Login:   oauth_start.php?provider=google           (GET)
//   Connect: POST provider=google, mode=connect, csrf_token, password
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/auth_helpers.php';

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$in     = $isPost ? $_POST : $_GET;
$name   = $in['provider'] ?? '';
$mode   = (($in['mode'] ?? '') === 'connect') ? 'connect' : 'login';
$loginRole = $mode === 'login' && ($in['login_role'] ?? '') === 'admin' ? 'admin' : '';
$loginPath = $loginRole === 'admin' ? '/admin/login.php' : '/login.php';

$p = oauth_provider($name);
if (!$p) {
    $_SESSION['login_error'] = "That login method is not available yet.";
    header("Location: " . BASE_PATH . $loginPath);
    exit;
}

if ($mode === 'connect') {
    // Only for someone already logged in
    if (empty($_SESSION['user_id'])) {
        header("Location: " . BASE_PATH . "/login.php");
        exit;
    }

    $back  = role_home($_SESSION['role'] ?? 'homeowner');
    $flash = function ($msg) use ($back) {
        $_SESSION['oauth_flash'] = ['type' => 'danger', 'msg' => $msg];
        header("Location: " . $back);
        exit;
    };

    if (!$isPost || !validateCsrfToken($in['csrf_token'] ?? '')) {
        $flash('Your session expired. Please try again.');
    }

    // Re-authenticate with the current password
    $check = reauth_check($conn, (int) $_SESSION['user_id'], $in['password'] ?? '');
    if ($check !== true) $flash($check);
}

// state (CSRF) + PKCE
$state     = bin2hex(random_bytes(16));
$verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

$_SESSION['oauth_state']    = $state;
$_SESSION['oauth_verifier'] = $verifier;
$_SESSION['oauth_provider'] = $name;
$_SESSION['oauth_mode']     = $mode;
$_SESSION['oauth_login_role'] = $loginRole;

$params = [
    'client_id'             => $p['client_id'],
    'redirect_uri'          => oauth_redirect_uri(),
    'response_type'         => 'code',
    'scope'                 => $p['scope'],
    'state'                 => $state,
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
    'prompt'                => 'select_account',
];

header("Location: " . $p['auth_url'] . '?' . http_build_query($params));
exit;