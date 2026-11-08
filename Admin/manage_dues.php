<?php
require_once '../config/init.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

// Dues management has been consolidated into Settings -> Dues
header('Location: ../settings.php?section=dues');
exit;