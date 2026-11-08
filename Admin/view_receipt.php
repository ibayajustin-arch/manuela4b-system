<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$receipt_no = trim($_GET['receipt_no'] ?? '');

if ($id > 0) {
    header("Location: ../receipt.php?id=" . $id);
    exit;
}

if ($receipt_no !== '') {
    require_once '../config/db.php';
    $stmt = $conn->prepare("SELECT id FROM payments WHERE receipt_no = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $receipt_no);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res) {
            header("Location: ../receipt.php?id=" . (int) $res['id']);
            exit;
        }
    }
}

header("Location: payments.php");
exit;