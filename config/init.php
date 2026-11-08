<?php

// --- Timezone (Philippines) ---
date_default_timezone_set('Asia/Manila');

// TEMPORARY: set to true to see the real PHP error on the live site.
// Set back to false once the page works.
const FORCE_DEBUG = true;

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = strtolower(explode(':', $httpHost)[0]);
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)
        || (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST']));
$debugEnabled = FORCE_DEBUG || $isLocal || getenv('APP_DEBUG') === '1';

// Never expose PHP errors to public hosting visitors. Use server logs there.
ini_set('display_errors', $debugEnabled ? '1' : '0');
ini_set('display_startup_errors', $debugEnabled ? '1' : '0');
error_reporting(E_ALL);

// --- Secure session settings ---
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,          // until browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // automatic: true on HTTPS (production), false on http://localhost
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// --- Base URL (adjust if needed) ---
if ($isLocal) {
    define('BASE_PATH', '/MANUELA');
    define('BASE_URL', 'http://localhost/MANUELA');
} else {
    // Support deployments both at the domain root and inside a subfolder
    // (for example, https://example.com/MANUELA/).
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (preg_match('#^(.*?)/(?:admin|resident|includes)/#i', $scriptPath, $matches)) {
        $basePath = $matches[1];
    } else {
        $basePath = dirname($scriptPath);
    }
    $basePath = rtrim($basePath, '/.');
    define('BASE_PATH', $basePath === '' ? '' : '/' . ltrim($basePath, '/'));
    define('BASE_URL', ($isHttps ? 'https://' : 'http://') . ($hostOnly ?: 'manuela4b.infy.click') . BASE_PATH);
}
define('MONTHLY_DUES_AMOUNT', 250.00);

// --- CSRF Token Helpers ---
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $_SESSION['csrf'] = $_SESSION['csrf_token']; // Compatibility fallback
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    if (!is_string($token) || $token === '') return false;
    $valid = false;
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        $valid = true;
    } elseif (isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token)) {
        $valid = true;
    }
    return $valid;
}