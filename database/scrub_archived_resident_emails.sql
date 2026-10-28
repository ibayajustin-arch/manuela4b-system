-- Remove resident email identifiers from retained archive profiles and login throttles.
-- Staff accounts remain untouched; archived homeowner profiles retain non-email history.
START TRANSACTION;

DELETE la
FROM login_attempts la
JOIN homeowners h
  ON h.archived_at IS NOT NULL
 AND h.email IS NOT NULL
 AND LOCATE(LOWER(h.email), LOWER(la.identifier)) > 0;

-- Clear any remaining email-based throttle key; these are transient lockout records.
DELETE FROM login_attempts WHERE identifier LIKE '%@%';

UPDATE homeowners
SET email = NULL
WHERE archived_at IS NOT NULL
  AND email IS NOT NULL;

COMMIT;
