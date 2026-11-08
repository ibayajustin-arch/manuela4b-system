<?php
// Used by admin/record_payment.php and admin/edit_payment.php.
// Needs homeowner_helpers.php (homeowner_account_summary, log_activity).

if (!function_exists('recorded_payment_methods')) {
    // Stored value => label shown in the form. The HOA accepts GCash and Maya only.
    function recorded_payment_methods(): array {
        return [
            'GCash (admin-assisted)' => 'GCash',
            'Maya (admin-assisted)' => 'Maya',
        ];
    }
}

if (!function_exists('record_dues_payment')) {
    /**
     * Records one unpaid month for a homeowner, using the logged-in admin as the recorder.
     * Returns ['id' => payment id, 'receipt_no' => receipt number].
     * Throws InvalidArgumentException with a message that is safe to show the user.
     */
    function record_dues_payment($conn, int $homeownerId, string $period, string $paymentDate, string $method, string $reference, string $notes): array {
        $date = DateTime::createFromFormat('!Y-m-d', $paymentDate);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException('Please select a valid unpaid month.');
        }
        if (!$date || $date->format('Y-m-d') !== $paymentDate || $paymentDate > date('Y-m-d')) {
            throw new InvalidArgumentException('Please enter a valid payment date that is not in the future.');
        }
        if (!isset(recorded_payment_methods()[$method])) {
            throw new InvalidArgumentException('Please select GCash or Maya.');
        }
        if ($reference === '') {
            throw new InvalidArgumentException('Please enter the transaction/reference number.');
        }
        if (strlen($reference) > 100 || strlen($notes) > 500) {
            throw new InvalidArgumentException('The reference or notes exceed the allowed length.');
        }

        $recorderId = (int) ($_SESSION['user_id'] ?? 0);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT * FROM homeowners WHERE id = ? AND archived_at IS NULL FOR UPDATE');
            if (!$stmt) throw new RuntimeException('Unable to lock homeowner record.');
            $stmt->bind_param('i', $homeownerId);
            $stmt->execute();
            $homeowner = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$homeowner) {
                throw new InvalidArgumentException('Homeowner not found. Please select a valid homeowner.');
            }

            $summary = homeowner_account_summary($conn, $homeowner);
            if (!in_array($period, $summary['unpaid'], true)) {
                throw new InvalidArgumentException('That month is already paid or is not due for this homeowner.');
            }

            $receiptNo = 'ADM-' . strtoupper(bin2hex(random_bytes(5)));
            $recordNotes = 'Recorded by admin | Reference: ' . $reference;
            if ($notes !== '') {
                $recordNotes .= ' | ' . $notes;
            }
            $amount = $summary['monthly_due'];

            $stmt = $conn->prepare(
                'INSERT INTO payments (homeowner_id, amount_paid, payment_date, period_covered, receipt_no, payment_method, collector_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) throw new RuntimeException('Unable to prepare payment record.');
            $stmt->bind_param('idssssis', $homeownerId, $amount, $paymentDate, $period, $receiptNo, $method, $recorderId, $recordNotes);
            $stmt->execute();
            $paymentId = (int) $stmt->insert_id;
            $stmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        log_activity($conn, $homeownerId, 'Recorded payment', 'Receipt ' . $receiptNo . ' for ' . date('F Y', strtotime($period . '-01')));
        return ['id' => $paymentId, 'receipt_no' => $receiptNo];
    }
}
