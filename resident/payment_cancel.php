<?php
// resident/payment_cancel.php  (PayMongo sends the resident here when they cancel)
require_once '../config/init.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header("Location: ../login.php");
    exit;
}

$_SESSION['error_msg'] = 'Payment was cancelled. Your reservation is saved. You can pay anytime using the Pay Now button.';
header("Location: reservations.php");
exit;