-- Clean any previous sample data (in case script was run before)
DELETE FROM `reservations`
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `username` LIKE 'sample_user%');

DELETE FROM `payments`
WHERE `homeowner_id` IN (SELECT `id` FROM `homeowners` WHERE `email` LIKE 'sample%@example.com');

DELETE FROM `homeowners`
WHERE `email` LIKE 'sample%@example.com';

DELETE FROM `users`
WHERE `username` LIKE 'sample_user%';

-- Insert sample homeowner user accounts (role = 'homeowner')
INSERT INTO `users` (`username`, `password`, `full_name`, `role`, `created_at`)
VALUES
  ('sample_user1', '$2y$10$samplehash0000000000000000000000000000000000000000', 'Sample User One',   'homeowner', NOW()),
  ('sample_user2', '$2y$10$samplehash1111111111111111111111111111111111111111', 'Sample User Two',   'homeowner', NOW()),
  ('sample_user3', '$2y$10$samplehash2222222222222222222222222222222222222222', 'Sample User Three', 'homeowner', NOW());

-- Insert sample homeowners (residents) – each linked to a user above
INSERT INTO `homeowners`
  (`user_id`, `block`, `lot`, `full_name`, `address`, `contact`, `email`, `monthly_due`, `status`, `created_at`)
VALUES
  ((SELECT id FROM `users` WHERE username='sample_user1'), 'Duhat',   'A', 'John Doe',         'Block Duhat Lot A', '09171234567', 'sample1@example.com', 250.00, 'Active', NOW()),
  ((SELECT id FROM `users` WHERE username='sample_user2'), 'Santol',  'B', 'Jane Smith',       'Block Santol Lot B','09181234568', 'sample2@example.com', 250.00, 'Active', NOW()),
  ((SELECT id FROM `users` WHERE username='sample_user3'), 'Mansanas','C', 'Carlos Rivera',    'Block Mansanas Lot C','09191234569', 'sample3@example.com', 250.00, 'Active', NOW());

-- Insert sample payments (methods: PayMaya & GCash)
INSERT INTO `payments`
  (`homeowner_id`, `amount_paid`, `payment_date`, `period_covered`, `receipt_no`, `payment_method`, `collector_id`, `notes`, `created_at`)
VALUES
  ((SELECT id FROM `homeowners` WHERE email='sample1@example.com'), 250.00, CURDATE(), '2026-10', 'RCPT-1001', 'PayMaya', (SELECT id FROM `users` WHERE username='collector'), 'Online PayMaya payment (Succeeded)', NOW()),
  ((SELECT id FROM `homeowners` WHERE email='sample2@example.com'), 250.00, CURDATE(), '2026-10', 'RCPT-1002', 'GCash',   (SELECT id FROM `users` WHERE username='collector'), 'Online GCash payment (Pending)', NOW()),
  ((SELECT id FROM `homeowners` WHERE email='sample3@example.com'), 250.00, CURDATE(), '2026-10', 'RCPT-1003', 'PayMaya', (SELECT id FROM `users` WHERE username='collector'), 'Online PayMaya payment (Succeeded)', NOW());

-- Insert sample reservations (facilities: Clubhouse & Court)
INSERT INTO `reservations`
  (`user_id`, `facility`, `res_date`, `start_time`, `end_time`, `status`, `created_at`)
VALUES
  ((SELECT id FROM `users` WHERE username='sample_user1'), 'Clubhouse', DATE_ADD(CURDATE(), INTERVAL 7 DAY), '10:00:00', '12:00:00', 'pending', NOW()),
  ((SELECT id FROM `users` WHERE username='sample_user2'), 'Court',     DATE_ADD(CURDATE(), INTERVAL 10 DAY), '14:00:00', '16:00:00', 'pending', NOW()),
  ((SELECT id FROM `users` WHERE username='sample_user3'), 'Clubhouse', DATE_ADD(CURDATE(), INTERVAL 12 DAY), '09:00:00', '11:00:00', 'pending', NOW());
