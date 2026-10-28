-- Monthly dues begin with the first full calendar month after activation.
ALTER TABLE homeowners
    ADD COLUMN IF NOT EXISTS dues_start_date DATE NULL DEFAULT NULL AFTER monthly_due;

UPDATE homeowners
SET dues_start_date = DATE_FORMAT(created_at, '%Y-%m-01')
WHERE dues_start_date IS NULL
  AND LOWER(COALESCE(status, '')) <> 'pending';

ALTER TABLE homeowners
    ADD INDEX IF NOT EXISTS idx_homeowners_dues_start (archived_at, status, dues_start_date);
