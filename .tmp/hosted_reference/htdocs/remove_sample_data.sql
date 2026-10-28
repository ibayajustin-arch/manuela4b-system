-- Remove only the synthetic homeowner dataset created by sample_data.sql.
-- Synthetic identifiers are sample_ho* users, @sample.invalid emails, and
-- SMP-* receipts. Manually registered accounts and their records are retained.

START TRANSACTION;

-- Remove child reservation payments before deleting their sample reservations.
DELETE rp
FROM reservation_payments AS rp
JOIN reservations AS r ON r.id = rp.reservation_id
JOIN users AS u ON u.id = r.user_id
WHERE u.username LIKE 'sample_ho%';

DELETE FROM reservations
WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%');

-- SMP-* is the dedicated sample receipt prefix; other real receipts are untouched.
DELETE FROM payments
WHERE receipt_no LIKE 'SMP-%'
   OR homeowner_id IN (
       SELECT h.id
       FROM homeowners AS h
       LEFT JOIN users AS u ON u.id = h.user_id
       WHERE u.username LIKE 'sample_ho%'
          OR h.email LIKE '%@sample.invalid'
   );

DELETE FROM activity_log
WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%')
   OR homeowner_id IN (
       SELECT h.id
       FROM homeowners AS h
       LEFT JOIN users AS u ON u.id = h.user_id
       WHERE u.username LIKE 'sample_ho%'
          OR h.email LIKE '%@sample.invalid'
   );

DELETE FROM resident_feedback
WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%');

DELETE FROM otp_codes
WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%');

DELETE FROM password_resets
WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%');

DELETE FROM user_identities
WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%');

DELETE h
FROM homeowners AS h
LEFT JOIN users AS u ON u.id = h.user_id
WHERE u.username LIKE 'sample_ho%'
   OR h.email LIKE '%@sample.invalid';

DELETE FROM users
WHERE username LIKE 'sample_ho%';

COMMIT;
