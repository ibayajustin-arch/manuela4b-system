-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 29, 2026 at 11:49 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `manuela4b_mis`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homeowners`
--

CREATE TABLE `homeowners` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `block` varchar(50) NOT NULL,
  `lot` varchar(50) NOT NULL,
  `lot_number` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `address` varchar(150) DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `monthly_due` decimal(10,2) DEFAULT 250.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(50) NOT NULL DEFAULT 'Active',
  `verification_id_path` varchar(255) DEFAULT NULL,
  `verification_id_back_path` varchar(255) DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `archived_status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `homeowners`
--

INSERT INTO `homeowners` (`id`, `user_id`, `block`, `lot`, `lot_number`, `full_name`, `address`, `contact`, `email`, `monthly_due`, `created_at`, `status`) VALUES
(1, 5, 'Ubas', '4b', 'BLK4-LOT1', 'Jose Rizal', 'Phase 4B, Lot 1', '09123456789', 'eme@Gmail.com', 500.00, '2026-03-26 12:48:35', 'active'),
(2, NULL, 'Ubas', '2d', 'BLK4-LOT2', 'Maria Clara', 'Phase 4B, Lot 2', '09123456790', 'ibayajustin@gmail.com', 500.00, '2026-03-26 12:48:35', 'active'),
(3, NULL, 'qweqwe', 'qweqweqwe', 'BLK4-LOT3', 'Andres Bonifacio', 'Phase 4B, Lot 3', '09123456791', 'realynjoyibaya@gmail.com', 500.00, '2026-03-26 12:48:35', 'inactive'),
(4, NULL, '', '', 'BLK4-LOT4', 'Emilio Aguinaldo', 'Phase 4B, Lot 4', '09123456792', NULL, 500.00, '2026-03-26 12:48:35', 'Active'),
(5, NULL, '', '', 'BLK4-LOT5', 'Gabriela Silang', 'Phase 4B, Lot 5', '09123456793', NULL, 500.00, '2026-03-26 12:48:35', 'Active'),
(6, NULL, '', '', 'BLK4-LOT6', 'Lapu-Lapu', 'Phase 4B, Lot 6', '09123456794', NULL, 500.00, '2026-03-26 12:48:35', 'Active'),
(7, NULL, '', '', 'BLK4-LOT7', 'Melchora Aquino', 'Phase 4B, Lot 7', '09123456795', NULL, 500.00, '2026-03-26 12:48:35', 'Active'),
(8, NULL, '', '', 'BLK4-LOT8', 'Apolinario Mabini', 'Phase 4B, Lot 8', '09123456796', NULL, 500.00, '2026-03-26 12:48:35', 'Active'),
(9, NULL, '', '', 'BLK4-LOT9', 'Marcelo del Pilar', 'Phase 4B, Lot 9', '09123456797', NULL, 500.00, '2026-03-26 12:48:35', 'Active'),
(10, NULL, '', '', 'BLK4-LOT10', 'Juan Luna', 'Phase 4B, Lot 10', '09123456798', NULL, 500.00, '2026-03-26 12:48:35', 'Active'),
(11, NULL, '23', '6', '', 'Realyn Joy', NULL, '09123456789', 'realynjoyibaya@gmail.com', 500.00, '2026-08-28 10:18:33', 'active'),
(13, 4, '23', '6', '4b-6-2026', 'Ibaya, Fernando A. Jr.', 'Block 23 Lot 6 Saint Louie Village Talon 3 Las Pinas CIty Admiral', '09569458813', 'ibayajustin@gmail.com', 500.00, '2026-08-29 06:22:14', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `homeowner_id` int(11) DEFAULT NULL,
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `period_covered` varchar(20) NOT NULL,
  `receipt_no` varchar(50) DEFAULT NULL,
  `payment_method` varchar(30) DEFAULT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `homeowner_id`, `amount_paid`, `payment_date`, `period_covered`, `receipt_no`, `payment_method`, `collector_id`, `notes`, `created_at`) VALUES
(2, 2, 500.00, '0000-00-00', '2026-03', 'RCP-00002', NULL, 2, NULL, '2026-03-26 12:48:35'),
(3, 3, 250.00, '0000-00-00', '2026-03', 'RCP-00003', NULL, 2, NULL, '2026-03-26 12:48:35'),
(4, 1, 500.00, '2026-04-02', '2026-04', 'RCP-00004', NULL, 2, NULL, '2026-03-26 12:48:35'),
(5, 1, 500.00, '2026-08-29', '2026-08', 'PM-E50DC742', NULL, NULL, 'Paid online via PayMongo (Succeeded)', '2026-08-29 06:37:44'),
(6, 13, 500.00, '2026-08-30', '2026-08', 'PM-14659662', 'Unknown', NULL, 'Paid online via PayMongo; provider not recorded', '2026-08-29 21:05:41');

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `facility` varchar(100) NOT NULL,
  `res_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`id`, `user_id`, `facility`, `res_date`, `start_time`, `end_time`, `status`, `created_at`) VALUES
(1, 1, 'Tennis Court', '2026-04-27', '15:50:00', '16:50:00', 'approved', '2026-04-19 07:51:01'),
(2, 1, 'Clubhouse', '2026-04-21', '19:40:00', '20:40:00', 'rejected', '2026-04-19 07:51:38'),
(3, 1, 'Tennis Court', '2026-04-24', '15:30:00', '04:41:00', 'approved', '2026-04-21 16:41:35'),
(4, 4, 'Basketball Court', '2026-08-26', '22:17:00', '22:17:00', 'rejected', '2026-08-25 14:18:00');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','treasurer','collector','homeowner') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User', 'admin', '2026-03-26 12:48:35'),
(2, 'treasurer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos', 'treasurer', '2026-03-26 12:48:35'),
(3, 'collector', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Juan Dela Cruz', 'collector', '2026-03-26 12:48:35'),
(4, 'resident01', '$2y$10$TAPNZUF4yeZ3654MDg7t/uskMJUBigT0XmkoF751bPfkZuP7wB/yW', 'Juan Dela Cruz', '', '2026-07-18 07:48:52'),
(5, 'edisonshow', '$2y$10$hqjBgmlmyfSFEMQ7Hrc7N.fuh13EkVdO6S4Xe8sxbpw80MlfNwItm', 'Belerick Dela Cruz', 'homeowner', '2026-08-29 06:31:32'),
(6, 'lyd', '$2y$10$GHjnFPApM4M9I14G9tSeFe/ttwwd5xVi0Y3todzkajIuOztPXdBza', 'John Loyld Estardo', 'homeowner', '2026-08-29 06:45:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `homeowners`
--
ALTER TABLE `homeowners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lot_number` (`lot_number`),
  ADD KEY `fk_homeowners_user` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_no` (`receipt_no`),
  ADD KEY `homeowner_id` (`homeowner_id`),
  ADD KEY `collector_id` (`collector_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `homeowners`
--
ALTER TABLE `homeowners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `homeowners`
--
ALTER TABLE `homeowners`
  ADD CONSTRAINT `fk_homeowners_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`homeowner_id`) REFERENCES `homeowners` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
