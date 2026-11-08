<?php
require_once 'config/init.php';

if (isset($_SESSION['user_id'])) {
    // If logged in as admin, go to the new admin folder
    if ($_SESSION['role'] === 'admin') {
        header("Location: " . BASE_PATH . "/admin/dashboard.php");
    } else {
        // If logged in as resident, go to the new resident folder
        header("Location: " . BASE_PATH . "/resident/dashboard.php");
    }
} else {
    // If not logged in, go to login
    header("Location: " . BASE_PATH . "/login.php");
}
exit;