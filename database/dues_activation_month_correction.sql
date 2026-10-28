-- Active records with a future start were migrated under the old next-month rule.
-- Under the adopted rule, dues become payable in the current month once active.
UPDATE homeowners
SET dues_start_date = DATE_FORMAT(CURDATE(), '%Y-%m-01')
WHERE archived_at IS NULL
  AND LOWER(COALESCE(status, '')) = 'active'
  AND dues_start_date > CURDATE();
