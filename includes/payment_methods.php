<?php
function payment_method_label(?string $method, ?string $notes = null): string {
    $value = strtolower(trim((string) $method));
    if ($value === '') return 'Manual / Cash';

    if ($value === 'unknown' || $value === 'online') {
        $noteText = strtolower((string) $notes);
        if (strpos($noteText, 'gcash') !== false) return 'GCash';
        if (strpos($noteText, 'paymaya') !== false || strpos($noteText, 'maya') !== false) return 'Maya';
        return 'Unknown';
    }

    $labels = [
        'gcash' => 'GCash',
        'paymaya' => 'Maya',
        'maya' => 'Maya',
        'grab_pay' => 'GrabPay',
        'card' => 'Credit/Debit Card',
        'qrph' => 'QR Ph',
        'gcash (admin-assisted)' => 'GCash',
        'maya (admin-assisted)' => 'Maya',
    ];

    return $labels[$value] ?? ucfirst($value);
}

/**
 * Payment method shown as plain text (e.g. "GCash", "Maya").
 * The GCash / Maya logo images are no longer used anywhere in the system.
 * Kept under the same name so every page that already calls it keeps working.
 */
function payment_method_indicator_html(?string $method, ?string $notes = null): string {
    $label = payment_method_label($method, $notes);
    return '<span class="payment-method-text">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

function payment_method_sql_expression(string $tableAlias = 'payments'): string {
    if (!in_array($tableAlias, ['payments', 'p'], true)) {
        throw new InvalidArgumentException('Unsupported payment table alias.');
    }

    return "CASE
        WHEN LOWER(TRIM(COALESCE({$tableAlias}.payment_method, ''))) = ''
            THEN 'Manual / Cash'
        WHEN LOWER(TRIM({$tableAlias}.payment_method)) IN ('online', 'unknown')
            THEN CASE
                WHEN LOWER(COALESCE({$tableAlias}.notes, '')) LIKE '%gcash%' THEN 'GCash'
                WHEN LOWER(COALESCE({$tableAlias}.notes, '')) LIKE '%paymaya%'
                  OR LOWER(COALESCE({$tableAlias}.notes, '')) LIKE '%maya%' THEN 'Maya'
                ELSE 'Unknown'
            END
        WHEN LOWER(TRIM({$tableAlias}.payment_method)) IN ('paymaya', 'maya', 'maya (admin-assisted)') THEN 'Maya'
        WHEN LOWER(TRIM({$tableAlias}.payment_method)) IN ('gcash', 'gcash (admin-assisted)') THEN 'GCash'
        ELSE {$tableAlias}.payment_method
    END";
}