<?php

$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = strtolower(explode(':', $httpHost)[0]);
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)
        || (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST']));

if ($isLocal) {
    $host     = "localhost";
    $username = "root";
    $password = "";
    $database = "manuela4b_mis";
} else {
    // InfinityFree Live Hosting
    $host     = "sql201.infinityfree.com";
    $username = "if0_43275271";
    $password = "4JpHeM0gdIM4ZHX";
    $database = "if0_43275271_manuela4b";
}

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    error_log('Database connection failed: ' . mysqli_connect_error());
    http_response_code(503);
    die("<div style='font-family:sans-serif;padding:24px;background:#1e1e2f;color:#fff;border-radius:8px;max-width:600px;margin:40px auto;'>"
        . "<h2 style='color:#f87171;'>Service temporarily unavailable</h2>"
        . "<p>Please try again later.</p>"
        . "</div>");
}

// Support emojis and special characters (ñ, etc.) properly
mysqli_set_charset($conn, 'utf8mb4');

// Philippine time for NOW(), CURRENT_TIMESTAMP, created_at, etc.
mysqli_query($conn, "SET time_zone = '+08:00'");

// Clear the temporary connection variables so they don't leak into other pages
unset($host, $username, $password, $database);