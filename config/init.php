<?php
// config/init.php

// --- Secure session settings ---
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,                // until browser closes
        'path'     => '/',
        'domain'   => '',               // your domain, e.g. 'example.com'
        'secure'   => true,             // only over HTTPS (set to true in production)
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// --- Base URL (adjust if needed) ---
define('BASE_PATH', '/MANUELA');

// --- CSRF Token Helpers ---
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}