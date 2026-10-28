<?php
// resident/change_password.php  (POST only)
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/auth_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header("Location: ../login.php");
    exit;
}

function pw_back($type, $msg) {
    $_SESSION[$type === 'ok' ? 'settings_success' : 'settings_error'] = $msg;
    header("Location: ../settings.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    pw_back('err', 'Your session expired. Please try again.');
}

$uid     = (int) $_SESSION['user_id'];
$current = (string) ($_POST['current_password'] ?? '');
$new     = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

// 1. Current password (with lockout after 5 wrong tries)
$check = reauth_check($conn, $uid, $current);
if ($check !== true) {
    pw_back('err', $check === 'Incorrect password.' ? 'Your current password is incorrect.' : $check);
}

// 2. New password rules
if (strlen($new) < 8) {
    pw_back('err', 'New password must be at least 8 characters.');
}
if ($new !== $confirm) {
    pw_back('err', 'New passwords do not match.');
}
if ($new === $current) {
    pw_back('err', 'New password must be different from your current password.');
}

// 3. Save
$hash = password_hash($new, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $hash, $uid);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    pw_back('err', 'Something went wrong. Please try again.');
}

// Cancel any pending reset links
$stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$stmt->close();

session_regenerate_id(true);
pw_back('ok', 'Your password was updated.');