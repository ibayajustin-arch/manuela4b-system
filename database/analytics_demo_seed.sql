-- Synthetic analytics data for a LOCAL / TEST database only.
-- Safe to run repeatedly: demo homeowners are keyed by their demo block, lot,
-- and name; payment rows are keyed by receipt_no. It never deletes or updates.
-- To remove the demo rows, delete payments with receipt_no LIKE 'DEMO-AN-%'
-- first, then remove the six fictional demo homeowners by their Demo Household names.

START TRANSACTION;

INSERT INTO homeowners
    (user_id, block, lot, full_name, address, contact, email, monthly_due, created_at, status)
SELECT NULL, demo.block_name, demo.lot, demo.full_name,
       'Synthetic analytics test record', NULL, NULL, demo.monthly_due,
       DATE_SUB(CURDATE(), INTERVAL 11 MONTH), 'active'
FROM (
    SELECT 'Demo A' block_name, '1' lot, 'Demo Household A1' full_name, 500.00 monthly_due
    UNION ALL SELECT 'Demo A', '2', 'Demo Household A2', 500.00
    UNION ALL SELECT 'Demo B', '1', 'Demo Household B1', 650.00
    UNION ALL SELECT 'Demo B', '2', 'Demo Household B2', 750.00
    UNION ALL SELECT 'Demo C', '1', 'Demo Household C1', 500.00
    UNION ALL SELECT 'Demo C', '2', 'Demo Household C2', 600.00
) AS demo
WHERE NOT EXISTS (
    SELECT 1 FROM homeowners existing
    WHERE existing.block = demo.block_name AND existing.lot = demo.lot AND existing.full_name = demo.full_name
);

-- 12 months of varied full, partial, and missed payments. The month offsets
-- are relative to today, so the recent trend remains visible whenever seeded.
INSERT INTO payments
    (homeowner_id, amount_paid, payment_date, period_covered, receipt_no, payment_method, collector_id, notes)
SELECT h.id,
       CASE
           WHEN h.full_name = 'Demo Household B2' AND m.month_offset = 11 THEN 200.00
           ELSE h.monthly_due
       END,
       LEAST(CURDATE(), DATE_ADD(STR_TO_DATE(CONCAT(DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL (11 - m.month_offset) MONTH), '%Y-%m'), '-01'), '%Y-%m-%d'), INTERVAL 14 DAY)),
       DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL (11 - m.month_offset) MONTH), '%Y-%m'),
       CONCAT('DEMO-AN-', REPLACE(h.block, 'Demo ', ''), h.lot, '-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL (11 - m.month_offset) MONTH), '%Y%m')),
       CASE
           WHEN MOD(m.month_offset, 3) = 0 THEN
               CASE WHEN MOD(m.month_offset, 2) = 0 THEN 'GCash' ELSE 'Maya' END
           ELSE 'Cash'
       END,
       NULL,
       'Synthetic analytics demo data; not a real payment'
FROM homeowners h
CROSS JOIN (
    SELECT 0 month_offset UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
    UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
    UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
) m
WHERE h.full_name LIKE 'Demo Household %'
  AND h.block LIKE 'Demo %'
  AND NOT (h.full_name = 'Demo Household A2' AND m.month_offset >= 10)
  AND NOT (h.full_name = 'Demo Household B1' AND MOD(m.month_offset, 2) = 0)
  AND NOT (h.full_name = 'Demo Household C1' AND m.month_offset >= 9)
  AND NOT (h.full_name = 'Demo Household C2' AND m.month_offset >= 7)
  AND NOT EXISTS (
      SELECT 1 FROM payments existing
      WHERE existing.receipt_no = CONCAT('DEMO-AN-', REPLACE(h.block, 'Demo ', ''), h.lot, '-', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL (11 - m.month_offset) MONTH), '%Y%m'))
  );

-- Small synthetic facility sample for the utilization chart. The records use
-- the first admin account only as the required reservation owner; run in a
-- disposable test database because other reservation screens will show them.
INSERT INTO reservations (user_id, facility, res_date, start_time, end_time, status)
SELECT admin_user.id, sample.facility, DATE_SUB(CURDATE(), INTERVAL sample.month_offset MONTH),
       '09:00:00', '10:00:00', sample.status
FROM (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1) AS admin_user
CROSS JOIN (
    SELECT 'Clubhouse' facility, 0 month_offset, 'approved' status
    UNION ALL SELECT 'Clubhouse', 1, 'approved'
    UNION ALL SELECT 'Tennis Court', 1, 'approved'
    UNION ALL SELECT 'Tennis Court', 3, 'approved'
    UNION ALL SELECT 'Basketball Court', 2, 'approved'
    UNION ALL SELECT 'Basketball Court', 4, 'pending'
) AS sample
WHERE NOT EXISTS (
    SELECT 1 FROM reservations existing
    WHERE existing.user_id = admin_user.id
      AND existing.facility = sample.facility
      AND existing.res_date = DATE_SUB(CURDATE(), INTERVAL sample.month_offset MONTH)
      AND existing.start_time = '09:00:00'
      AND existing.end_time = '10:00:00'
      AND existing.status = sample.status
);

COMMIT;

-- Expected demo coverage: 6 active synthetic households across 3 blocks,
-- 12 billing periods with two late accounts, one partial current-period payment,
-- varied payment channels, and enough months to show the trend and estimate.
-- Facility chart sample rows above use an existing admin as the reservation
-- owner because the schema has no synthetic/demo-owner relation.
