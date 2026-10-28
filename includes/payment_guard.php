<?php
// A resident must verify an emailed OTP before starting any online payment.

if (!function_exists('pay_verified')) {
    function pay_verified() {
        return time() < (int) ($_SESSION['pay_verified_until'] ?? 0);
    }
}

if (!function_exists('pay_next_allowed')) {
    // Only these pages may be used as the "go here after verifying" target
    function pay_next_allowed($n) {
        return (bool) preg_match(
            '/^(payments\.php|create_checkout\.php|reservations\.php(\?open=book(&(facility|date|start|end)=[A-Za-z0-9%:+ -]{0,40})*)?|pay_reservation\.php\?id=\d+)$/',
            (string) $n
        );
    }
}

if (!function_exists('require_pay_verified')) {
    function require_pay_verified($next) {
        if (!pay_verified()) {
            header("Location: " . BASE_PATH . "/resident/verify_payment.php?next=" . urlencode($next));
            exit;
        }
    }
}

if (!function_exists('pay_consume')) {
    // Each verification covers ONE payment
    function pay_consume() {
        unset($_SESSION['pay_verified_until']);
    }
}