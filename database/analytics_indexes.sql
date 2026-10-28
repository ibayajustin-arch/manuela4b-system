-- Supports date-range totals and payment-method charts.
-- Take a database backup before applying this migration.
ALTER TABLE payments
    ADD INDEX IF NOT EXISTS idx_payments_archive_date (archived_at, payment_date);
