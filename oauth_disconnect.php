<?php
// oauth_disconnect.php  (POST only)
require_once 'config/init.php';
require_once 'config/db.php';
require_once 'includes/auth_helpers.php';

if (empty($_SESSION['user_id'])) {
    header("Location: " . BASE_PATH . "/login.php");
    exit;
}

$back = role_home($_SESSION['role'] ?? 'homeowner');
$uid  = (int) $_SESSION['user_id'];

function disconnect_flash($type, $msg, $back) {
    $_SESSION['oauth_flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: " . $back);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    disconnect_flash('danger', 'Your session expired. Please try again.', $back);
}

if (($_POST['provider'] ?? '') !== 'google') {
    disconnect_flash('danger', 'Unknown login method.', $back);
}

// Re-authenticate with the current password
$check = reauth_check($conn, $uid, $_POST['password'] ?? '');
if ($check !== true) {
    disconnect_flash('danger', $check, $back);
}

$stmt = $conn->prepare("DELETE FROM user_identities WHERE user_id = ? AND provider = 'google'");
$stmt->bind_param("i", $uid);
$stmt->execute();
$stmt->close();

disconnect_flash('success', 'Google disconnected.', $back);