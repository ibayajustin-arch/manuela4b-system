<?php
// LOCAL TESTING COPY ONLY. DO NOT UPLOAD: the server has the real version that sends the reminders.
// This copy never sends email, so running the site locally cannot email real residents.

if (!defined('DUES_REMINDER_MAX_ATTEMPTS')) {
    define('DUES_REMINDER_MAX_ATTEMPTS', 3);
}

if (!function_exists('dues_reminder_month_label')) {
    // Turns a period like "2026-11" into "November 2026".
    function dues_reminder_month_label(string $period): string {
        $date = DateTime::createFromFormat('!Y-m', $period);
        return $date ? $date->format('F Y') : $period;
    }
}

if (!function_exists('dues_reminder_schedule')) {
    // Called on every notification poll. Intentionally does nothing for now.
    function dues_reminder_schedule(mysqli $conn): void {
    }
}
