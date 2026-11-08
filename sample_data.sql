-- Compact synthetic dataset for local analytics/dashboard previews only.
-- Covers the latest 24 months, including the previous calendar year and month.
-- Re-runnable: removes only sample_ho* accounts, their records, and SMP-* receipts.
-- Requires the current app schema (including dues_start_date and reservation payment columns).
-- All sample homeowners owe PHP 250/month; payment methods are GCash or Maya.
-- Reservations use Court/Clubhouse, app rates (PHP 200/300 per hour), and approved status.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS resident_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    resident_name VARCHAR(100) DEFAULT NULL,
    category ENUM('suggestion', 'concern') NOT NULL,
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    admin_reply TEXT DEFAULT NULL,
    admin_replied_at TIMESTAMP NULL DEFAULT NULL,
    archived_at DATETIME NULL DEFAULT NULL,
    status ENUM('new', 'in_review', 'resolved') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resident_feedback_user_created (user_id, created_at),
    KEY idx_resident_feedback_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE resident_feedback
    ADD COLUMN IF NOT EXISTS admin_reply TEXT NULL AFTER message,
    ADD COLUMN IF NOT EXISTS admin_replied_at TIMESTAMP NULL DEFAULT NULL AFTER admin_reply;

START TRANSACTION;

DELETE FROM payments WHERE receipt_no LIKE 'SMP-%';
DELETE FROM reservations WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%');
DELETE FROM resident_feedback WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'sample_ho%');
DELETE FROM homeowners WHERE email LIKE 'sample_ho%@sample.invalid';
DELETE FROM users WHERE username LIKE 'sample_ho%';

INSERT INTO users (username, password, full_name, role) VALUES
('sample_ho001','SAMPLE-NO-LOGIN','Ana Evangelista','homeowner'),
('sample_ho002','SAMPLE-NO-LOGIN','Gina Valdez','homeowner'),
('sample_ho003','SAMPLE-NO-LOGIN','Vilma Perez','homeowner'),
('sample_ho004','SAMPLE-NO-LOGIN','Felicidad Pineda','homeowner'),
('sample_ho005','SAMPLE-NO-LOGIN','Kevin Garcia','homeowner'),
('sample_ho006','SAMPLE-NO-LOGIN','Jessa Ocampo','homeowner'),
('sample_ho007','SAMPLE-NO-LOGIN','Grace Abad','homeowner'),
('sample_ho008','SAMPLE-NO-LOGIN','Ben Mercado','homeowner'),
('sample_ho009','SAMPLE-NO-LOGIN','Nena Gonzales','homeowner'),
('sample_ho010','SAMPLE-NO-LOGIN','Rey Macaraeg','homeowner'),
('sample_ho011','SAMPLE-NO-LOGIN','Teresita Cruz','homeowner'),
('sample_ho012','SAMPLE-NO-LOGIN','Noel Evangelista','homeowner'),
('sample_ho013','SAMPLE-NO-LOGIN','Erlinda Fernandez','homeowner'),
('sample_ho014','SAMPLE-NO-LOGIN','Randy Mercado','homeowner'),
('sample_ho015','SAMPLE-NO-LOGIN','Luz Cabrera','homeowner'),
('sample_ho016','SAMPLE-NO-LOGIN','Rodel Evangelista','homeowner');

INSERT INTO homeowners
    (user_id, block, lot, lot_number, full_name, address, email, monthly_due, dues_start_date, created_at, status)
VALUES
((SELECT id FROM users WHERE username='sample_ho001'),'Duhat','1','DUHAT-01','Ana Evangelista','1 Duhat St., Manuela 4B','sample_ho001@sample.invalid',250.00,'2025-01-01','2024-11-01 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho002'),'Duhat','2','DUHAT-02','Gina Valdez','2 Duhat St., Manuela 4B','sample_ho002@sample.invalid',250.00,'2025-01-01','2024-11-01 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho003'),'Santol','1','SANTOL-01','Vilma Perez','1 Santol St., Manuela 4B','sample_ho003@sample.invalid',250.00,'2025-01-01','2024-11-01 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho004'),'Santol','2','SANTOL-02','Felicidad Pineda','2 Santol St., Manuela 4B','sample_ho004@sample.invalid',250.00,'2025-01-01','2024-11-01 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho005'),'Mansanas','1','MANSANAS-01','Kevin Garcia','1 Mansanas St., Manuela 4B','sample_ho005@sample.invalid',250.00,'2025-04-01','2025-03-15 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho006'),'Mansanas','2','MANSANAS-02','Jessa Ocampo','2 Mansanas St., Manuela 4B','sample_ho006@sample.invalid',250.00,'2025-05-01','2025-04-18 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho007'),'Granada','1','GRANADA-01','Grace Abad','1 Granada St., Manuela 4B','sample_ho007@sample.invalid',250.00,'2025-07-01','2025-06-02 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho008'),'Granada','2','GRANADA-02','Ben Mercado','2 Granada St., Manuela 4B','sample_ho008@sample.invalid',250.00,'2025-09-01','2025-08-12 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho009'),'Guyabano','1','GUYABANO-01','Nena Gonzales','1 Guyabano St., Manuela 4B','sample_ho009@sample.invalid',250.00,'2025-12-01','2025-11-05 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho010'),'Guyabano','2','GUYABANO-02','Rey Macaraeg','2 Guyabano St., Manuela 4B','sample_ho010@sample.invalid',250.00,'2026-02-01','2026-01-20 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho011'),'Atis','1','ATIS-01','Teresita Cruz','1 Atis St., Manuela 4B','sample_ho011@sample.invalid',250.00,'2026-04-01','2026-03-08 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho012'),'Atis','2','ATIS-02','Noel Evangelista','2 Atis St., Manuela 4B','sample_ho012@sample.invalid',250.00,'2026-06-01','2026-05-22 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho013'),'Ubas','1','UBAS-01','Erlinda Fernandez','1 Ubas St., Manuela 4B','sample_ho013@sample.invalid',250.00,'2026-08-01','2026-07-04 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho014'),'Ubas','2','UBAS-02','Randy Mercado','2 Ubas St., Manuela 4B','sample_ho014@sample.invalid',250.00,'2026-10-01','2026-09-06 08:00:00','active'),
((SELECT id FROM users WHERE username='sample_ho015'),'Ubas','3','UBAS-03','Luz Cabrera','3 Ubas St., Manuela 4B','sample_ho015@sample.invalid',250.00,NULL,'2026-10-06 08:00:00','pending'),
((SELECT id FROM users WHERE username='sample_ho016'),'Ubas','4','UBAS-04','Rodel Evangelista','4 Ubas St., Manuela 4B','sample_ho016@sample.invalid',250.00,'2025-03-01','2025-02-04 08:00:00','inactive');

-- 14 active households generate about 280 full or partial monthly payments.
INSERT INTO payments (homeowner_id, amount_paid, payment_date, period_covered, receipt_no, payment_method, collector_id, notes)
SELECT h.id,
       CASE WHEN MOD(CAST(SUBSTRING(u.username, 10) AS UNSIGNED) + m.month_offset, 7) = 1
            THEN h.monthly_due / 2 ELSE h.monthly_due END,
       LEAST(CURDATE(), CASE
           WHEN m.month_offset > 0 AND MOD(CAST(SUBSTRING(u.username, 10) AS UNSIGNED) + m.month_offset, 4) = 0
           THEN DATE_ADD(DATE_ADD(m.month_start, INTERVAL 1 MONTH), INTERVAL 2 DAY)
           ELSE DATE_ADD(m.month_start, INTERVAL 2 + MOD(CAST(SUBSTRING(u.username, 10) AS UNSIGNED) * 3 + m.month_offset, 7) DAY)
       END),
       DATE_FORMAT(m.month_start, '%Y-%m'),
       CONCAT('SMP-', LPAD(CAST(SUBSTRING(u.username, 10) AS UNSIGNED), 3, '0'), '-', DATE_FORMAT(m.month_start, '%Y%m')),
       IF(MOD(CAST(SUBSTRING(u.username, 10) AS UNSIGNED) + m.month_offset, 2) = 0, 'GCash', 'Maya'),
       NULL, 'Synthetic sample analytics record'
FROM homeowners h
JOIN users u ON u.id = h.user_id
CROSS JOIN (
    SELECT n.month_offset, DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n.month_offset MONTH) AS month_start
    FROM (
        SELECT 0 AS month_offset UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
        UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
        UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
        UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
        UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
    ) n
) m
WHERE h.email LIKE 'sample_ho%@sample.invalid'
  AND LOWER(h.status) = 'active'
  AND h.dues_start_date IS NOT NULL
  AND m.month_start >= h.dues_start_date
  AND MOD(CAST(SUBSTRING(u.username, 10) AS UNSIGNED) + m.month_offset, 7) <> 0;

-- One dated booking per month; two more appear in the dashboard upcoming list.
INSERT INTO reservations
    (user_id, facility, res_date, start_time, end_time, total_amount, payment_amount, payment_status, payment_option, status, created_at)
SELECT u.id,
       IF(MOD(m.month_offset, 2) = 0, 'Court', 'Clubhouse'),
       IF(m.month_offset = 0, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(m.month_start, INTERVAL 7 DAY)),
       IF(MOD(m.month_offset, 2) = 0, '09:00:00', '17:00:00'),
       IF(MOD(m.month_offset, 2) = 0, '10:00:00', '18:00:00'),
       IF(MOD(m.month_offset, 2) = 0, 200.00, 300.00),
       IF(MOD(m.month_offset, 2) = 0, 200.00, 300.00),
       'paid', 'full', 'approved',
       DATE_SUB(IF(m.month_offset = 0, DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(m.month_start, INTERVAL 7 DAY)), INTERVAL 7 DAY)
FROM (
    SELECT n.month_offset, DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n.month_offset MONTH) AS month_start
    FROM (
        SELECT 0 AS month_offset UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
        UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
        UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
        UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
        UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
    ) n
) m
JOIN users u ON u.username = CONCAT('sample_ho', LPAD(MOD(m.month_offset, 14) + 1, 3, '0'));

INSERT INTO reservations
    (user_id, facility, res_date, start_time, end_time, total_amount, payment_amount, payment_status, payment_option, status, created_at)
SELECT u.id, upcoming.facility, DATE_ADD(CURDATE(), INTERVAL upcoming.day_offset DAY),
       upcoming.start_time, upcoming.end_time, upcoming.amount, upcoming.amount,
       'paid', 'full', 'approved', NOW()
FROM (
    SELECT 1 AS sample_no, 3 AS day_offset, 'Court' AS facility, '09:00:00' AS start_time, '10:00:00' AS end_time, 200.00 AS amount
    UNION ALL SELECT 2, 6, 'Clubhouse', '17:00:00', '18:00:00', 300.00
) upcoming
JOIN users u ON u.username = CONCAT('sample_ho', LPAD(upcoming.sample_no, 3, '0'));

-- Feedback spans the recent 22 months and includes both categories and statuses.
INSERT INTO resident_feedback (user_id, category, subject, message, admin_reply, admin_replied_at, status, created_at)
SELECT u.id, f.category, f.subject, f.message,
       CASE WHEN f.status = 'resolved' THEN 'Management reviewed this item and completed the follow-up.' ELSE NULL END,
       CASE WHEN f.status = 'resolved' THEN LEAST(CURDATE(), DATE_ADD(DATE_ADD(m.month_start, INTERVAL 5 + MOD(f.sample_no, 10) DAY), INTERVAL 2 DAY)) ELSE NULL END,
       f.status, LEAST(CURDATE(), DATE_ADD(m.month_start, INTERVAL 5 + MOD(f.sample_no, 10) DAY))
FROM (
    SELECT 1 AS sample_no, 0 AS month_offset, 'suggestion' AS category, 'Reservation calendar' AS subject, 'A shared calendar for Court and Clubhouse availability would help residents plan.' AS message, 'new' AS status
    UNION ALL SELECT 2, 1, 'concern', 'Streetlight repair', 'The light near the entrance has been out for several evenings.', 'in_review'
    UNION ALL SELECT 3, 2, 'suggestion', 'Earlier clean-up notices', 'Posting the clean-up schedule earlier would help households prepare.', 'resolved'
    UNION ALL SELECT 4, 4, 'concern', 'Drainage after rain', 'Water is pooling near the drainage after heavy rain.', 'resolved'
    UNION ALL SELECT 5, 6, 'suggestion', 'Recycling reminders', 'A reminder about sorting recyclable bottles and cans could be useful.', 'new'
    UNION ALL SELECT 6, 8, 'concern', 'Loose court net post', 'Please inspect the net post before the next court booking.', 'in_review'
    UNION ALL SELECT 7, 10, 'suggestion', 'Monthly community notice', 'A short monthly notice with maintenance updates would be helpful.', 'resolved'
    UNION ALL SELECT 8, 12, 'concern', 'Clubhouse faucet', 'The clubhouse sink faucet continues to drip after use.', 'resolved'
    UNION ALL SELECT 9, 14, 'suggestion', 'Shaded seating', 'A shaded bench near the Court would make it more comfortable for spectators.', 'new'
    UNION ALL SELECT 10, 16, 'concern', 'Uneven pathway', 'A section of pavement near the corner is difficult to walk across.', 'in_review'
    UNION ALL SELECT 11, 19, 'suggestion', 'Dues payment reminders', 'A monthly reminder with the covered period and accepted payment methods would help.', 'resolved'
    UNION ALL SELECT 12, 21, 'concern', 'Gate latch', 'The pedestrian gate latch is sticking and takes several tries to close.', 'new'
) f
CROSS JOIN (
    SELECT n.month_offset, DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL n.month_offset MONTH) AS month_start
    FROM (
        SELECT 0 AS month_offset UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
        UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
        UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
        UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
        UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23
    ) n
) m ON m.month_offset = f.month_offset
JOIN users u ON u.username = CONCAT('sample_ho', LPAD(f.sample_no, 3, '0'));

COMMIT;
