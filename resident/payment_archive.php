<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/archive_helpers.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ../login.php');
    exit;
}

$returnToArchive = ($_POST['return_view'] ?? '') === 'archive';
$redirect = 'payments.php' . ($returnToArchive ? '?view=archive' : '');
$action = $_POST['action'] ?? '';
$paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Your session expired. Please refresh the page and try again.';
    header('Location: ' . $redirect);
    exit;
}
if (!in_array($action, ['archive', 'restore'], true) || $paymentId === false) {
    $_SESSION['flash_error'] = 'The selected receipt could not be found.';
    header('Location: ' . $redirect);
    exit;
}

try {
    ensure_archive_columns($conn, 'payments');
    $homeownerStmt = $conn->prepare('SELECT id FROM homeowners WHERE user_id = ? LIMIT 1');
    if (!$homeownerStmt) throw new RuntimeException($conn->error);
    $userId = (int) $_SESSION['user_id'];
    $homeownerStmt->bind_param('i', $userId);
    if (!$homeownerStmt->execute()) throw new RuntimeException($homeownerStmt->error);
    $homeownerId = (int) ($homeownerStmt->get_result()->fetch_assoc()['id'] ?? 0);
    $homeownerStmt->close();
    if ($homeownerId <= 0) throw new RuntimeException('Resident homeowner record is unavailable.');

    $isArchiveAction = $action === 'archive';
    $archiveValue = $isArchiveAction ? 'NOW()' : 'NULL';
    $archiveCondition = $isArchiveAction ? 'archived_at IS NULL' : 'archived_at IS NOT NULL';
    $stmt = $conn->prepare("UPDATE payments SET archived_at = {$archiveValue} WHERE id = ? AND homeowner_id = ? AND {$archiveCondition}");
    if (!$stmt) throw new RuntimeException($conn->error);
    $stmt->bind_param('ii', $paymentId, $homeownerId);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    $_SESSION['flash_success'] = $changed
        ? ($isArchiveAction ? 'Receipt archived. You can restore it from Archives.' : 'Receipt restored successfully.')
        : 'Receipt not found or already updated.';
} catch (Throwable $e) {
    error_log('Resident payment archive action failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'The receipt could not be updated. Please try again.';
}

header('Location: ' . $redirect);
exit;
