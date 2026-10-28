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
        'gcash (admin-assisted)' => 'GCash (admin-assisted)',
        'maya (admin-assisted)' => 'Maya (admin-assisted)',
    ];

    return $labels[$value] ?? ucfirst($value);
}

function payment_method_indicator_html(?string $method, ?string $notes = null): string {
    $label = payment_method_label($method, $notes);
    $normalized = strtolower($label);
    $asset = null;
    $class = 'method-other';
    if (strpos($normalized, 'gcash') === 0) {
        $asset = 'gcash.png';
        $class = 'method-gcash';
    } elseif (strpos($normalized, 'maya') === 0 || strpos($normalized, 'paymaya') === 0) {
        $asset = 'maya.png';
        $class = 'method-maya';
    }

    if ($asset === null) {
        return '<span class="payment-method-indicator ' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $basePath = defined('BASE_PATH') ? BASE_PATH : '/MANUELA';
    $src = $basePath . '/Assets/images/payments/' . $asset;
    return '<span class="payment-method-indicator ' . $class . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' logo">'
        . '</span>';
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
        WHEN LOWER(TRIM({$tableAlias}.payment_method)) IN ('paymaya', 'maya') THEN 'Maya'
        WHEN LOWER(TRIM({$tableAlias}.payment_method)) = 'gcash' THEN 'GCash'
        ELSE {$tableAlias}.payment_method
    END";
}
