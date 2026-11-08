<?php
require_once 'config/init.php';

// Only a real click may log out. Hover prefetches and background requests must not end the session.
$fetchMode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'navigate';
$fetchPurpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_PURPOSE'] ?? ''));
if ($fetchMode !== 'navigate' || strpos($fetchPurpose, 'prefetch') !== false) {
    header('Cache-Control: no-store');
    http_response_code(204);
    exit;
}

// Unset all session variables
$_SESSION = [];

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login
header("Location: " . BASE_PATH . "/login.php");
exit;