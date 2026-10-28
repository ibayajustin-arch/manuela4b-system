-- Run after a full database backup. Archives resident records first, then removes
-- only homeowner login accounts and legacy blank-role accounts linked to a home.
-- Admin, treasurer, and collector accounts are excluded from the deletion set.

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS archived_resident_name VARCHAR(100) NULL DEFAULT NULL;

ALTER TABLE resident_feedback
    ADD COLUMN IF NOT EXISTS resident_name VARCHAR(100) NULL DEFAULT NULL;

DROP TEMPORARY TABLE IF EXISTS resident_delete_targets;
CREATE TEMPORARY TABLE resident_delete_targets (
    id INT NOT NULL PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NULL
) ENGINE=MEMORY
SELECT DISTINCT u.id, u.username, u.full_name
FROM users u
WHERE u.role = 'homeowner'
   OR (TRIM(COALESCE(u.role, '')) = '' AND EXISTS (
       SELECT 1 FROM homeowners h WHERE h.user_id = u.id
   ));

START TRANSACTION;

UPDATE homeowners
SET archived_status = COALESCE(archived_status, status),
    status = 'inactive',
    archived_at = NOW()
WHERE archived_at IS NULL;

UPDATE payments p
JOIN homeowners h ON h.id = p.homeowner_id
SET p.archived_at = NOW()
WHERE p.archived_at IS NULL;

UPDATE reservations r
JOIN resident_delete_targets t ON t.id = r.user_id
SET r.archived_resident_name = COALESCE(t.full_name, r.archived_resident_name),
    r.archived_at = NOW()
WHERE r.archived_at IS NULL;

UPDATE resident_feedback f
JOIN resident_delete_targets t ON t.id = f.user_id
SET f.resident_name = COALESCE(t.full_name, f.resident_name),
    f.archived_at = NOW()
WHERE f.archived_at IS NULL;

DELETE i FROM user_identities i JOIN resident_delete_targets t ON t.id = i.user_id;
DELETE o FROM otp_codes o JOIN resident_delete_targets t ON t.id = o.user_id;
DELETE p FROM password_resets p JOIN resident_delete_targets t ON t.id = p.user_id;

DELETE u FROM users u JOIN resident_delete_targets t ON t.id = u.id;

COMMIT;
DROP TEMPORARY TABLE resident_delete_targets;
