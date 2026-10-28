-- Apply after taking a database backup. This is a non-destructive index upgrade.
-- The existing homeowners.block field stores the street name.
ALTER TABLE homeowners ADD INDEX IF NOT EXISTS idx_homeowners_street_active_created (block, archived_at, created_at);
ALTER TABLE homeowners ADD INDEX IF NOT EXISTS idx_homeowners_status_archive (status, archived_at);
ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_payments_homeowner_period (homeowner_id, period_covered, payment_date);
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_reservations_user_date_status (user_id, res_date, status);
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_reservations_date_status (res_date, status);
ALTER TABLE reservations ADD INDEX IF NOT EXISTS idx_reservations_archive_date (archived_at, res_date, start_time);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_role_id (role, id);
