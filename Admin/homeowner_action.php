<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: homeowners.php");
    exit;
}

$id = (int) ($_POST['homeowner_id'] ?? 0);
$back = "homeowner_view.php?id=" . $id;

function fail($msg, $back) {
    $_SESSION['flash_error'] = $msg;
    header("Location: " . $back);
    exit;
}

if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    fail("Your session expired. Please try again.", $back);
}

$stmt = $conn->prepare("SELECT * FROM homeowners WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$h = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$h) {
    header("Location: homeowners.php");
    exit;
}

$action = $_POST['action'] ?? '';
$migrationMsg = "This feature needs the database upgrade. Run sql/homeowner_profile_upgrade.sql first.";

switch ($action) {
    // ---------- Ownership / emergency contact / household ----------
    case 'save_details':
        foreach (['ownership_type', 'emergency_contact_name', 'emergency_contact_number', 'household_members'] as $col) {
            if (!column_exists($conn, 'homeowners', $col)) fail($migrationMsg, $back);
        }
        $type = $_POST['ownership_type'] ?? 'Owner';
        if (!in_array($type, ['Owner', 'Tenant', 'Caretaker'], true)) $type = 'Owner';
        $ecName = trim($_POST['emergency_contact_name'] ?? '');
        $ecNum = trim($_POST['emergency_contact_number'] ?? '');
        $members = trim($_POST['household_members'] ?? '');
        $stmt = $conn->prepare("UPDATE homeowners SET ownership_type = ?, emergency_contact_name = ?, emergency_contact_number = ?, household_members = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $type, $ecName, $ecNum, $members, $id);
        $stmt->execute();
        $stmt->close();     
        log_activity($conn, $id, 'Updated additional details', "Ownership: $type");
        $_SESSION['flash_success'] = "Additional details saved.";
        break;

    // ---------- Reset the linked resident's password ----------
    case 'reset_password':
        $userId = (int) ($h['user_id'] ?? 0);
        if ($userId <= 0) fail("This homeowner has no resident login to reset.", $back);

        $pwCol = column_exists($conn, 'users', 'password') ? 'password'
               : (column_exists($conn, 'users', 'password_hash') ? 'password_hash' : null);
        if (!$pwCol) fail("Could not find the password column in the users table.", $back);

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 10; $i++) $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $hash = password_hash($temp, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET `$pwCol` = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $userId);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, $id, 'Reset account password');
        $_SESSION['flash_success'] = "Password reset. Temporary password (shown only once): " . $temp;
        break;

    // ---------- Activate / deactivate ----------
    case 'set_status':
        $new = $_POST['new_status'] ?? '';
        if (!in_array($new, ['active', 'inactive'], true)) fail("Invalid status.", $back);
        if (strtolower((string) ($h['status'] ?? '')) === 'pending' && $new === 'active') {
            fail('Pending resident applications must be approved from the Pending Residents page.', $back);
        }
        $stmt = $conn->prepare("UPDATE homeowners SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new, $id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, $id, $new === 'inactive' ? 'Deactivated homeowner' : 'Reactivated homeowner');
        $_SESSION['flash_success'] = "Homeowner marked as " . $new . ".";
        break;

    default:
        fail("Unknown action.", $back);
}

header("Location: " . $back);
exit;
