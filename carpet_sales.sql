-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 07, 2026 at 10:14 AM
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
-- Database: `carpet_sales`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `account_type` enum('cash','bank','mobile_money','other') NOT NULL DEFAULT 'cash',
  `currency_id` int(10) UNSIGNED NOT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'maintained by app against ledger',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `code` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `branch_id`, `name`, `account_type`, `currency_id`, `opening_balance`, `current_balance`, `status`, `deleted_at`, `created_at`, `updated_at`, `code`) VALUES
(20, NULL, 'Office Account', 'cash', 24, 0.00, 0.00, 'active', NULL, '2026-11-07 06:49:49', '2026-11-07 02:19:49', 'GH23423');

-- --------------------------------------------------------

--
-- Table structure for table `account_transactions`
--

CREATE TABLE `account_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `exchange_rate` decimal(20,8) DEFAULT NULL,
  `ref_type` enum('sale','customer_payment','weaver_payment','purchase','expense','other_income','transfer','adjustment') NOT NULL,
  `ref_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'polymorphic reference',
  `transaction_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `idempotency_key` varchar(100) DEFAULT NULL,
  `request_hash` char(64) DEFAULT NULL,
  `rate_to_base` decimal(20,8) DEFAULT NULL,
  `entry_type` enum('opening','income','expense','reversal') DEFAULT NULL,
  `reversal_of_id` bigint(20) UNSIGNED DEFAULT NULL
) ;

--
-- Dumping data for table `account_transactions`
--

INSERT INTO `account_transactions` (`id`, `account_id`, `direction`, `amount`, `currency_id`, `exchange_rate`, `ref_type`, `ref_id`, `transaction_date`, `description`, `created_by`, `created_at`, `idempotency_key`, `request_hash`, `rate_to_base`, `entry_type`, `reversal_of_id`) VALUES
(106, 20, 'in', 20000.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Opening balance on account creation', 1, '2026-11-07 02:19:49', 'account-opening:20', '434e6c534f30a61511f3055612a8e54afa28b6f8e53dafe29181b77decd8f2cb', 1.00000000, 'opening', NULL),
(107, 20, 'out', 9500.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Payment for SUP-2026-00000001', 1, '2026-11-07 02:49:51', 'business:d9a332143d4c0b2145a00be1553da3f70b45b7309ede8973950ffc5fc494f1da', '738eaa211e7a4d1eb2d2e9ace7296dc1c180fc000493a2fa0fa4b91ff756605f', 1.00000000, 'expense', NULL),
(108, 20, 'out', 500.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Washing payment for MPR-2026-00000001', 1, '2026-11-07 03:27:52', 'ab6424277e108cfcfa8bb43273cc0667763e52b632ffe881a2b97ed20c733d9a:ledger', 'f04bb4cc73255577f9f58c31e1aaed45ede7f4334b685137c79a77abeed800e3', 1.00000000, 'expense', NULL),
(109, 20, 'out', 3600.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Payment for MPR-2026-00000001', 1, '2026-11-07 03:31:43', 'business:334e5c328aa302356bd1bc3a4b99b6438eb40a0bf0b7c12c7e3ebbc276d1ada0', '7425e095c797b3b9829dc1173e99f14835c1ba3119f0969c4870a7d957136baa', 1.00000000, 'expense', NULL),
(110, 20, 'out', 500.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Washing payment for MPR-2026-00000001', 1, '2026-11-07 03:33:01', '0d8adb8901a1f0bbdc438257f2bed8e1bf41b7935d8fa25383f2013699ab322f:ledger', 'f04bb4cc73255577f9f58c31e1aaed45ede7f4334b685137c79a77abeed800e3', 1.00000000, 'expense', NULL),
(111, 20, 'out', 3600.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Payment for MPR-2026-00000001', 1, '2026-11-07 03:33:06', 'business:dbb0cf671a5e22b9c8af9825323dd59b1a94ca3382d05074aa96696100f53806', '7425e095c797b3b9829dc1173e99f14835c1ba3119f0969c4870a7d957136baa', 1.00000000, 'expense', NULL),
(112, 20, 'out', 500.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Washing payment for MPR-2026-00000002', 1, '2026-11-07 03:44:16', '4d5e75e1d3596c236e0e57588f8eaa0ff39a792dc965f241b84f0be576fd1232:ledger', 'fdd1719803db1909ff9757fcbef2e901e6996498cf6c6ceb6a971a4dcaaabfda', 1.00000000, 'expense', NULL),
(113, 20, 'in', 50000.00, 24, 1.00000000, 'other_income', NULL, '2026-11-07', 'asfdghj', 1, '2026-11-07 03:44:59', '9569251f-2cfa-44dd-bc2d-3a556afa23d8', 'b386117f1b1a079b9e8f53fc877440f6cfdb333ecb959d7f174b6626866c44eb', 1.00000000, 'income', NULL),
(114, 20, 'out', 30000.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Payment for MPR-2026-00000002', 1, '2026-11-07 03:45:25', 'business:0eade15e8282014274a895290cff12dc917953ca105aae786301dd45eb3b1f86', '46179fc5ac7db100e7011ae4d42d1e82748b578552d44dc0985bf804ae5b07b6', 1.00000000, 'expense', NULL),
(115, 20, 'out', 500.00, 24, 1.00000000, 'adjustment', NULL, '2026-11-07', 'Washing payment for MPR-2026-00000003', 1, '2026-11-07 03:48:39', '01da8c1669dc528bdf6454a15dc57bf7ee79c65cb4878b36b5fc1f2af4fef5d9:ledger', '8c7787840dff0f2c17728de7cfbb9b1bfba1b6925a6998ff8dfc3b0f22a0a6db', 1.00000000, 'expense', NULL);

--
-- Triggers `account_transactions`
--
DELIMITER $$
CREATE TRIGGER `ledger_no_delete` BEFORE DELETE ON `account_transactions` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `ledger_no_update` BEFORE UPDATE ON `account_transactions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `areas`
--

CREATE TABLE `areas` (
  `id` int(10) UNSIGNED NOT NULL,
  `district_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_fa` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` enum('create','update','delete','login','logout','export') NOT NULL,
  `table_name` varchar(60) NOT NULL,
  `record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(973, NULL, 'login', 'users', 1, NULL, '{\"username\":\"admin\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:17:49'),
(974, 1, 'create', 'currencies', 24, NULL, '{\"id\":24,\"code\":\"AFN\",\"name\":\"Office Account\",\"symbol\":\"AFN\",\"decimal_places\":1,\"is_default\":0,\"is_active\":1,\"created_at\":\"2026-11-07 11:18:48\",\"updated_at\":\"2026-11-07 06:48:48\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:18:48'),
(975, 1, 'update', 'currencies', 24, '{\"id\":24,\"code\":\"AFN\",\"name\":\"Office Account\",\"symbol\":\"AFN\",\"decimal_places\":1,\"is_default\":0,\"is_active\":1,\"created_at\":\"2026-11-07 11:18:48\",\"updated_at\":\"2026-11-07 06:48:48\"}', '{\"id\":24,\"code\":\"AFN\",\"name\":\"AFN\",\"symbol\":\"AFN\",\"decimal_places\":1,\"is_default\":0,\"is_active\":1,\"created_at\":\"2026-11-07 11:18:48\",\"updated_at\":\"2026-11-07 06:49:17\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:19:17'),
(976, 1, 'update', 'company_settings', 1, '{\"id\":1,\"base_currency_id\":null,\"default_locale\":\"en\",\"timezone\":\"Asia\\/Kabul\",\"reminder_days_before\":3,\"updated_by\":null,\"created_at\":\"2026-11-07 06:47:43\",\"updated_at\":\"2026-11-07 06:47:43\",\"reset_version\":11}', '{\"base_currency_id\":24,\"default_locale\":\"en\",\"timezone\":\"Asia\\/Kabul\",\"reminder_days_before\":3}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:19:25'),
(977, 1, 'create', 'account_transactions', 106, NULL, '{\"id\":106,\"account_id\":20,\"direction\":\"in\",\"amount\":\"20000.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Opening balance on account creation\",\"created_by\":1,\"created_at\":\"2026-11-07 06:49:49\",\"idempotency_key\":\"account-opening:20\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"opening\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:19:49'),
(978, 1, 'create', 'accounts', 20, NULL, '{\"id\":20,\"branch_id\":null,\"name\":\"Office Account\",\"account_type\":\"cash\",\"currency_id\":24,\"status\":\"active\",\"deleted_at\":null,\"created_at\":\"2026-11-07 11:19:49\",\"updated_at\":\"2026-11-07 06:49:49\",\"code\":\"GH23423\",\"balance\":\"20000.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:19:49'),
(979, 1, 'create', 'branches', 17, NULL, '{\"id\":17,\"code\":\"GH32423\",\"name\":\"\\u0634\\u0639\\u0628\\u0647 \\u0645\\u0631\\u06a9\\u0632\\u06cc \\u06a9\\u0627\\u0628\\u0644\",\"location\":\"\\u06cc\\u0633\\u0628\\u0633\\u06cc\\u0628\",\"phone\":\"+3789647998\",\"is_main\":0,\"status\":\"active\",\"deleted_at\":null,\"created_at\":\"2026-11-07 11:20:26\",\"updated_at\":\"2026-11-07 06:50:26\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:20:26'),
(980, NULL, 'login', 'users', 1, NULL, '{\"username\":\"admin\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:23:09'),
(981, 1, 'create', 'stock_locations', 39, NULL, '{\"id\":39,\"code\":\"MAN261104-RAW\",\"name\":\"\\u06af\\u062f\\u0627\\u0645 \\u0645\\u0648\\u0627\\u062f \\u0627\\u0648\\u0644\\u06cc\\u0647 \\u0647\\u0631\\u0627\\u062a \\u0640 \\u062a\\u0645\\u0631\\u06cc\\u0646 \\u062f\\u0633\\u062a\\u06cc\",\"branch_id\":17,\"washing_facility_id\":null,\"location_type\":\"warehouse\",\"status\":\"active\",\"created_at\":null,\"updated_at\":\"2026-11-07 06:53:49\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:23:49'),
(982, 1, 'create', 'stock_locations', 40, NULL, '{\"id\":40,\"code\":\"MAN261104-FIN\",\"name\":\"\\u06af\\u062f\\u0627\\u0645 \\u0642\\u0627\\u0644\\u06cc\\u0646 \\u0622\\u0645\\u0627\\u062f\\u0647 \\u0647\\u0631\\u0627\\u062a \\u0640 \\u062a\\u0645\\u0631\\u06cc\\u0646 \\u062f\\u0633\\u062a\\u06cc\",\"branch_id\":17,\"washing_facility_id\":null,\"location_type\":\"warehouse\",\"status\":\"active\",\"created_at\":null,\"updated_at\":\"2026-11-07 06:54:20\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:24:20'),
(983, 1, 'create', 'stock_locations', 41, NULL, '{\"id\":41,\"code\":\"MAN261104-SHOW\",\"name\":\"\\u0646\\u0645\\u0627\\u06cc\\u0634\\u06af\\u0627\\u0647 \\u0647\\u0631\\u0627\\u062a \\u0640 \\u062a\\u0645\\u0631\\u06cc\\u0646 \\u062f\\u0633\\u062a\\u06cc\",\"branch_id\":17,\"washing_facility_id\":null,\"location_type\":\"showroom\",\"status\":\"active\",\"created_at\":null,\"updated_at\":\"2026-11-07 06:54:43\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:24:43'),
(984, 1, 'create', 'washing_facilities', 15, NULL, '{\"id\":15,\"name\":\"\\u0645\\u0631\\u06a9\\u0632 \\u0634\\u0634\\u062a\\u0634\\u0648 \\u0628\\u0647\\u0627\\u0631\",\"location\":\"\\u06a9\\u0627\\u0628\\u0644\",\"contact_name\":\"\\u06a9\\u0631\\u06cc\\u0645\",\"phone\":\"+9378967898\",\"status\":\"active\",\"deleted_at\":null,\"created_at\":\"2026-11-07 11:33:41\",\"updated_at\":\"2026-11-07 07:03:41\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:33:41'),
(985, 1, 'create', 'stock_locations', 42, NULL, '{\"id\":42,\"code\":\"MAN261104-WASH\",\"name\":\"\\u0645\\u062d\\u0648\\u0637\\u0647 \\u0634\\u0633\\u062a\\u0634\\u0648\\u06cc \\u0628\\u0647\\u0627\\u0631 \\u0640 \\u062a\\u0645\\u0631\\u06cc\\u0646 \\u062f\\u0633\\u062a\\u06cc\",\"branch_id\":null,\"washing_facility_id\":15,\"location_type\":\"washing\",\"status\":\"active\",\"created_at\":null,\"updated_at\":\"2026-11-07 07:07:20\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:37:20'),
(986, 1, 'create', 'suppliers', 12, NULL, '{\"id\":12,\"name\":\"\\u06a9\\u0631\\u06cc\\u0645\",\"phone\":\"+9378964545\",\"address\":\"\\u06a9\\u0627\\u0628 \\u0627\\u0641\\u063a\\u0627\\u0646\\u0633\\u062a\\u0627\\u0646\",\"notes\":\"\\u06a9\\u0627\\u0628\\u0644\",\"status\":\"active\",\"deleted_at\":null,\"created_at\":\"2026-11-07 07:07:58\",\"updated_at\":\"2026-11-07 07:07:58\",\"created_by\":1}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:37:58'),
(987, 1, 'create', 'materials', 13, NULL, '{\"code\":\"MAN261104-WOOL\",\"name\":\"\\u067e\\u0634\\u0645 \\u0637\\u0628\\u06cc\\u0639\\u06cc\",\"unit\":\"kg\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:41:32'),
(988, 1, 'create', 'production_tools', 9, NULL, '{\"code\":\"MAN261104-COMB\",\"name\":\"\\u0634\\u0627\\u0646\\u0647 \\u0642\\u0627\\u0644\\u06cc\\u0646\\u200c\\u0628\\u0627\\u0641\\u06cc \\u0640 \\u062a\\u0645\\u0631\\u06cc\\u0646 \\u062f\\u0633\\u062a\\u06cc\",\"status\":\"active\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:45:36'),
(989, 1, 'create', 'supply_purchases', 9, NULL, '{\"branch_id\":17,\"location_id\":39,\"supplier_id\":12,\"currency_id\":24,\"rate_to_base\":\"1\",\"purchased_on\":\"2026-11-07\",\"purchase_no\":\"SUP-2026-00000001\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:46:53'),
(990, 1, 'create', 'production_tool_lots', 8, NULL, '{\"branch_id\":17,\"location_id\":39,\"supplier_id\":12,\"currency_id\":24,\"rate_to_base\":\"1\",\"received_on\":\"2026-11-07\",\"quantity\":\"2\",\"unit_cost\":\"750\",\"tool_id\":9,\"lot_no\":\"MTL-2026-00000001\",\"total_cost\":\"1500.0\",\"total_cost_base\":\"1500.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:46:53'),
(991, 1, 'create', 'supply_purchase_lines', 17, NULL, '{\"purchase_id\":9,\"tool_lot_id\":8}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:46:53'),
(992, 1, 'create', 'material_lots', 11, NULL, '{\"branch_id\":17,\"location_id\":39,\"supplier_id\":12,\"currency_id\":24,\"rate_to_base\":\"1\",\"received_on\":\"2026-11-07\",\"quantity\":\"20\",\"unit_cost\":\"400\",\"material_id\":13,\"lot_no\":\"MAT-2026-00000001\",\"total_cost\":\"8000.0\",\"total_cost_base\":\"8000.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:46:53'),
(993, 1, 'create', 'supply_purchase_lines', 18, NULL, '{\"purchase_id\":9,\"material_lot_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:46:53'),
(994, 1, 'create', 'account_transactions', 107, NULL, '{\"id\":107,\"account_id\":20,\"direction\":\"out\",\"amount\":\"9500.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Payment for SUP-2026-00000001\",\"created_by\":1,\"created_at\":\"2026-11-07 07:19:51\",\"idempotency_key\":\"business:d9a332143d4c0b2145a00be1553da3f70b45b7309ede8973950ffc5fc494f1da\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:49:51'),
(995, 1, 'create', 'production_tool_payments', 12, NULL, '{\"lot_id\":8,\"account_transaction_id\":107,\"document_amount\":\"1500.00\",\"units_per_payment_unit\":\"1\",\"amount_in_payment_currency\":\"1500.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:49:51'),
(996, 1, 'create', 'material_lot_payments', 18, NULL, '{\"lot_id\":11,\"account_transaction_id\":107,\"document_amount\":\"8000.00\",\"units_per_payment_unit\":\"1\",\"amount_in_payment_currency\":\"8000.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:49:51'),
(997, 1, 'create', 'production_managers', 11, NULL, '{\"code\":\"MAN261104-MGR\",\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u0627\\u0633\\u0645\\u0627\\u0639\\u06cc\\u0644 \\u0640 \\u062a\\u0645\\u0631\\u06cc\\u0646 \\u062f\\u0633\\u062a\\u06cc\",\"branch_id\":17,\"status\":\"active\",\"phone\":null,\"address\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:50:21'),
(998, 1, 'create', 'production_workflow_defaults', 8, NULL, '{\"branch_id\":17,\"materials_location_id\":39,\"finished_location_id\":40,\"facility_id\":15,\"facility_location_id\":42,\"payment_trigger\":\"stock_acceptance\",\"updated_at\":\"2026-11-07T07:21:38.751884Z\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:51:38'),
(999, 1, 'create', 'carpet_categories', 15, NULL, '{\"id\":15,\"name\":\"GHDGSF\",\"name_fa\":\"\\u0642\\u0627\\u0644\\u06cc\\u0646 \\u0686\\u0648\\u0628\\u06cc \\u0640 \\u062a\\u0645\\u0631\\u06cc\\u0646 \\u062f\\u0633\\u062a\\u06cc\",\"description\":\"dfghjk\",\"status\":\"active\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 02:52:53'),
(1000, 1, 'create', 'manager_production_orders', 11, NULL, '{\"manager_id\":11,\"branch_id\":17,\"currency_id\":24,\"rate_to_base\":\"1\",\"ordered_on\":\"2026-11-07\",\"due_on\":\"2026-11-07\",\"description\":\"Production: \\u0641\\u0631\\u0634 \\u0686\\u0648\\u0628\\u06cc \\u06af\\u0644\\u062f\\u0627\\u0631 \\u0633\\u0647 \\u062f\\u0631 \\u062f\\u0648\",\"payment_trigger\":\"stock_acceptance\",\"order_no\":\"MPR-2026-00000001\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:23:33'),
(1001, 1, 'create', 'manager_production_lines', 11, NULL, '{\"name\":\"\\u0641\\u0631\\u0634 \\u0686\\u0648\\u0628\\u06cc \\u06af\\u0644\\u062f\\u0627\\u0631 \\u0633\\u0647 \\u062f\\u0631 \\u062f\\u0648\",\"category_id\":15,\"target_quantity\":2,\"length_cm\":\"300\",\"width_cm\":\"200\",\"pricing_method\":\"area\",\"rate\":\"600\",\"notes\":\"sdfsdf\",\"production_order_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:23:33'),
(1002, 1, 'create', 'manager_material_movements', 34, NULL, '{\"lot_id\":11,\"movement_type\":\"issue\",\"quantity\":\"10\",\"moved_on\":\"2026-11-07\",\"reason\":\"Give supplies for MPR-2026-00000001\",\"production_order_id\":11,\"cost_base\":\"0\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:24:03'),
(1003, 1, 'create', 'manager_tool_movements', 12, NULL, '{\"lot_id\":8,\"movement_type\":\"issue\",\"quantity\":2,\"moved_on\":\"2026-11-07\",\"reason\":\"Give supplies for MPR-2026-00000001\",\"production_order_id\":11,\"condition\":\"good\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:24:03'),
(1004, 1, 'create', 'manager_material_movements', 35, NULL, '{\"lot_id\":11,\"movement_type\":\"consume\",\"quantity\":\"10\",\"moved_on\":\"2026-11-07\",\"reason\":\"Materials used for MPR-2026-00000001\",\"production_order_id\":11,\"cost_base\":\"4000.00\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1005, 1, 'create', 'manager_production_deliveries', 14, NULL, '{\"production_order_id\":11,\"delivery_no\":\"MDL-2026-00000001\",\"delivered_on\":\"2026-11-07\",\"charge_amount\":\"3600.00\",\"material_cost_base\":\"4000.00\",\"total_cost_base\":\"7600.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1006, 1, 'create', 'manager_delivery_materials', 18, NULL, '{\"delivery_id\":14,\"movement_id\":35}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1007, 1, 'create', 'carpets', 28, NULL, '{\"id\":28,\"carpet_code\":\"MPC-2026-00000001\",\"name\":\"\\u0641\\u0631\\u0634 \\u0686\\u0648\\u0628\\u06cc \\u06af\\u0644\\u062f\\u0627\\u0631 \\u0633\\u0647 \\u062f\\u0631 \\u062f\\u0648\",\"registration_branch_id\":17,\"category_id\":15,\"size_label\":null,\"length_cm\":\"300.00\",\"width_cm\":\"200.00\",\"color\":null,\"material\":null,\"notes\":\"sdfsdf\",\"version\":1,\"created_by\":1,\"updated_by\":1,\"created_at\":\"2026-11-07 07:57:21\",\"updated_at\":\"2026-11-07 07:57:21\",\"deleted_at\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1008, 1, 'create', 'manager_delivery_items', 14, NULL, '{\"delivery_id\":14,\"production_order_id\":11,\"line_id\":11,\"carpet_id\":28,\"length_cm\":\"300.00\",\"width_cm\":\"200.00\",\"area_m2\":\"6.000000\",\"charge_amount\":\"3600.00\",\"cost_base\":\"7600.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1009, 1, 'create', 'stock_events', 89, NULL, '{\"carpet_id\":28,\"sequence_no\":1,\"event_type\":\"production_complete\",\"resulting_status\":\"production_completed\",\"resulting_location_id\":null,\"manager_delivery_item_id\":14,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07T07:57:21.676935Z\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1010, 1, 'create', 'stock_events', 90, NULL, '{\"id\":90,\"carpet_id\":28,\"sequence_no\":2,\"event_type\":\"washing_dispatch\",\"resulting_status\":\"at_washing\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 07:57:21\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":14,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1011, 1, 'create', 'washing_orders', 14, NULL, '{\"order_no\":\"MWASH-2026-00000001\",\"branch_id\":17,\"facility_id\":15,\"facility_location_id\":42,\"sent_on\":\"2026-11-07\",\"items\":[{\"carpet_id\":28,\"expected_sequence\":1}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:21'),
(1012, 1, 'create', 'stock_events', 91, NULL, '{\"id\":91,\"carpet_id\":28,\"sequence_no\":3,\"event_type\":\"washing_complete\",\"resulting_status\":\"washing_completed\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 07:57:52\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":14,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:52'),
(1013, 1, 'create', 'stock_events', 92, NULL, '{\"id\":92,\"carpet_id\":28,\"sequence_no\":4,\"event_type\":\"washing_return\",\"resulting_status\":\"in_stock\",\"resulting_location_id\":40,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 07:57:52\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":14,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:52'),
(1014, 1, 'create', 'manager_delivery_acceptances', 14, NULL, '{\"item_id\":14,\"washing_order_item_id\":14,\"accepted_on\":\"2026-11-07\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:52'),
(1015, 1, 'create', 'manager_earned_charges', 14, NULL, '{\"production_order_id\":11,\"item_id\":14,\"earned_on\":\"2026-11-07\",\"amount\":\"3600.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:52'),
(1016, 1, 'create', 'washing_bills', 13, NULL, '{\"expense_no\":\"WBILL-2026-00000001\",\"invoice_no\":\"WBILL-2026-00000001\",\"branch_id\":17,\"facility_id\":15,\"currency_id\":24,\"rate_to_base\":\"1.00000000\",\"billed_on\":\"2026-11-07\",\"amount\":\"500\",\"amount_base\":\"500.0\",\"allocations\":[{\"washing_order_item_id\":14,\"amount\":\"500.00\"}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:52'),
(1017, 1, 'create', 'account_transactions', 108, NULL, '{\"id\":108,\"account_id\":20,\"direction\":\"out\",\"amount\":\"500.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Washing payment for MPR-2026-00000001\",\"created_by\":1,\"created_at\":\"2026-11-07 07:57:52\",\"idempotency_key\":\"ab6424277e108cfcfa8bb43273cc0667763e52b632ffe881a2b97ed20c733d9a:ledger\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:52'),
(1018, 1, 'create', 'washing_payments', 13, NULL, '{\"id\":13,\"payment_no\":\"WMPAY-2026-00000001\",\"washing_bill_id\":13,\"account_transaction_id\":108,\"amount_in_bill_currency\":\"500.00\",\"bill_units_per_payment_unit\":\"1.00000000\",\"created_by\":1,\"created_at\":\"2026-11-07 07:57:52\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:27:52'),
(1019, 1, 'create', 'account_transactions', 109, NULL, '{\"id\":109,\"account_id\":20,\"direction\":\"out\",\"amount\":\"3600.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Payment for MPR-2026-00000001\",\"created_by\":1,\"created_at\":\"2026-11-07 08:01:43\",\"idempotency_key\":\"business:334e5c328aa302356bd1bc3a4b99b6438eb40a0bf0b7c12c7e3ebbc276d1ada0\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:31:43'),
(1020, 1, 'create', 'manager_production_payments', 14, NULL, '{\"account_transaction_id\":109,\"document_amount\":\"3600.0\",\"units_per_payment_unit\":\"1\",\"production_order_id\":11,\"payment_type\":\"production\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:31:43'),
(1021, 1, 'create', 'manager_production_deliveries', 15, NULL, '{\"production_order_id\":11,\"delivery_no\":\"MDL-2026-00000002\",\"delivered_on\":\"2026-11-07\",\"charge_amount\":\"3600.00\",\"material_cost_base\":\"0\",\"total_cost_base\":\"3600.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:32:42'),
(1022, 1, 'create', 'carpets', 29, NULL, '{\"id\":29,\"carpet_code\":\"MPC-2026-00000002\",\"name\":\"\\u0641\\u0631\\u0634 \\u0686\\u0648\\u0628\\u06cc \\u06af\\u0644\\u062f\\u0627\\u0631 \\u0633\\u0647 \\u062f\\u0631 \\u062f\\u0648\",\"registration_branch_id\":17,\"category_id\":15,\"size_label\":null,\"length_cm\":\"300.00\",\"width_cm\":\"200.00\",\"color\":null,\"material\":null,\"notes\":\"sdfsdf\",\"version\":1,\"created_by\":1,\"updated_by\":1,\"created_at\":\"2026-11-07 08:02:42\",\"updated_at\":\"2026-11-07 08:02:42\",\"deleted_at\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:32:42'),
(1023, 1, 'create', 'manager_delivery_items', 15, NULL, '{\"delivery_id\":15,\"production_order_id\":11,\"line_id\":11,\"carpet_id\":29,\"length_cm\":\"300.00\",\"width_cm\":\"200.00\",\"area_m2\":\"6.000000\",\"charge_amount\":\"3600.00\",\"cost_base\":\"3600.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:32:42'),
(1024, 1, 'create', 'stock_events', 93, NULL, '{\"carpet_id\":29,\"sequence_no\":1,\"event_type\":\"production_complete\",\"resulting_status\":\"production_completed\",\"resulting_location_id\":null,\"manager_delivery_item_id\":15,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07T08:02:42.942211Z\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:32:42'),
(1025, 1, 'create', 'stock_events', 94, NULL, '{\"id\":94,\"carpet_id\":29,\"sequence_no\":2,\"event_type\":\"washing_dispatch\",\"resulting_status\":\"at_washing\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:02:42\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":15,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:32:42'),
(1026, 1, 'create', 'washing_orders', 15, NULL, '{\"order_no\":\"MWASH-2026-00000002\",\"branch_id\":17,\"facility_id\":15,\"facility_location_id\":42,\"sent_on\":\"2026-11-07\",\"items\":[{\"carpet_id\":29,\"expected_sequence\":1}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:32:42'),
(1027, 1, 'create', 'stock_events', 95, NULL, '{\"id\":95,\"carpet_id\":29,\"sequence_no\":3,\"event_type\":\"washing_complete\",\"resulting_status\":\"washing_completed\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:03:01\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":15,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:01'),
(1028, 1, 'create', 'stock_events', 96, NULL, '{\"id\":96,\"carpet_id\":29,\"sequence_no\":4,\"event_type\":\"washing_return\",\"resulting_status\":\"in_stock\",\"resulting_location_id\":40,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:03:01\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":15,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:01'),
(1029, 1, 'create', 'manager_delivery_acceptances', 15, NULL, '{\"item_id\":15,\"washing_order_item_id\":15,\"accepted_on\":\"2026-11-07\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:01'),
(1030, 1, 'create', 'manager_earned_charges', 15, NULL, '{\"production_order_id\":11,\"item_id\":15,\"earned_on\":\"2026-11-07\",\"amount\":\"3600.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:01'),
(1031, 1, 'create', 'washing_bills', 14, NULL, '{\"expense_no\":\"WBILL-2026-00000002\",\"invoice_no\":\"WBILL-2026-00000002\",\"branch_id\":17,\"facility_id\":15,\"currency_id\":24,\"rate_to_base\":\"1.00000000\",\"billed_on\":\"2026-11-07\",\"amount\":\"500\",\"amount_base\":\"500.0\",\"allocations\":[{\"washing_order_item_id\":15,\"amount\":\"500.00\"}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:01'),
(1032, 1, 'create', 'account_transactions', 110, NULL, '{\"id\":110,\"account_id\":20,\"direction\":\"out\",\"amount\":\"500.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Washing payment for MPR-2026-00000001\",\"created_by\":1,\"created_at\":\"2026-11-07 08:03:01\",\"idempotency_key\":\"0d8adb8901a1f0bbdc438257f2bed8e1bf41b7935d8fa25383f2013699ab322f:ledger\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:01'),
(1033, 1, 'create', 'washing_payments', 14, NULL, '{\"id\":14,\"payment_no\":\"WMPAY-2026-00000002\",\"washing_bill_id\":14,\"account_transaction_id\":110,\"amount_in_bill_currency\":\"500.00\",\"bill_units_per_payment_unit\":\"1.00000000\",\"created_by\":1,\"created_at\":\"2026-11-07 08:03:01\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:01'),
(1034, 1, 'create', 'account_transactions', 111, NULL, '{\"id\":111,\"account_id\":20,\"direction\":\"out\",\"amount\":\"3600.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Payment for MPR-2026-00000001\",\"created_by\":1,\"created_at\":\"2026-11-07 08:03:06\",\"idempotency_key\":\"business:dbb0cf671a5e22b9c8af9825323dd59b1a94ca3382d05074aa96696100f53806\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:06'),
(1035, 1, 'create', 'manager_production_payments', 15, NULL, '{\"account_transaction_id\":111,\"document_amount\":\"3600.0\",\"units_per_payment_unit\":\"1\",\"production_order_id\":11,\"payment_type\":\"production\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:33:06'),
(1036, 1, 'create', 'manager_production_orders', 12, NULL, '{\"manager_id\":11,\"branch_id\":17,\"currency_id\":24,\"rate_to_base\":\"1\",\"ordered_on\":\"2026-11-07\",\"due_on\":\"2026-11-07\",\"description\":\"Production: \\u0642\\u0627\\u0644\\u06cc\\u0646 \\u0627\\u0648\\u0632\\u0628\\u06cc\\u06a9 \\u0686\\u0647\\u0627\\u0631 \\u0645\\u063a\\u0632\\u06cc\",\"payment_trigger\":\"stock_acceptance\",\"order_no\":\"MPR-2026-00000002\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:41:20'),
(1037, 1, 'create', 'manager_production_lines', 12, NULL, '{\"name\":\"\\u0642\\u0627\\u0644\\u06cc\\u0646 \\u0627\\u0648\\u0632\\u0628\\u06cc\\u06a9 \\u0686\\u0647\\u0627\\u0631 \\u0645\\u063a\\u0632\\u06cc\",\"category_id\":15,\"target_quantity\":1,\"pricing_method\":\"area\",\"rate\":\"5000\",\"production_order_id\":12}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:41:21'),
(1038, 1, 'create', 'manager_material_movements', 36, NULL, '{\"lot_id\":11,\"movement_type\":\"issue\",\"quantity\":\"5\",\"moved_on\":\"2026-11-07\",\"reason\":\"Give supplies for MPR-2026-00000002\",\"production_order_id\":12,\"cost_base\":\"0\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:06'),
(1039, 1, 'create', 'manager_material_movements', 37, NULL, '{\"lot_id\":11,\"movement_type\":\"consume\",\"quantity\":\"1\",\"moved_on\":\"2026-11-07\",\"reason\":\"Materials used for MPR-2026-00000002\",\"production_order_id\":12,\"cost_base\":\"400.00\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1040, 1, 'create', 'manager_production_deliveries', 16, NULL, '{\"production_order_id\":12,\"delivery_no\":\"MDL-2026-00000003\",\"delivered_on\":\"2026-11-07\",\"charge_amount\":\"30000.00\",\"material_cost_base\":\"400.00\",\"total_cost_base\":\"30400.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1041, 1, 'create', 'manager_delivery_materials', 19, NULL, '{\"delivery_id\":16,\"movement_id\":37}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1042, 1, 'create', 'carpets', 30, NULL, '{\"id\":30,\"carpet_code\":\"MPC-2026-00000003\",\"name\":\"\\u0642\\u0627\\u0644\\u06cc\\u0646 \\u0627\\u0648\\u0632\\u0628\\u06cc\\u06a9 \\u0686\\u0647\\u0627\\u0631 \\u0645\\u063a\\u0632\\u06cc\",\"registration_branch_id\":17,\"category_id\":15,\"size_label\":null,\"length_cm\":\"200.00\",\"width_cm\":\"300.00\",\"color\":null,\"material\":null,\"notes\":null,\"version\":1,\"created_by\":1,\"updated_by\":1,\"created_at\":\"2026-11-07 08:13:59\",\"updated_at\":\"2026-11-07 08:13:59\",\"deleted_at\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1043, 1, 'create', 'manager_delivery_items', 16, NULL, '{\"delivery_id\":16,\"production_order_id\":12,\"line_id\":12,\"carpet_id\":30,\"length_cm\":\"200\",\"width_cm\":\"300\",\"area_m2\":\"6.000000\",\"charge_amount\":\"30000.00\",\"cost_base\":\"30400.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1044, 1, 'create', 'stock_events', 97, NULL, '{\"carpet_id\":30,\"sequence_no\":1,\"event_type\":\"production_complete\",\"resulting_status\":\"production_completed\",\"resulting_location_id\":null,\"manager_delivery_item_id\":16,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07T08:13:59.947470Z\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1045, 1, 'create', 'stock_events', 98, NULL, '{\"id\":98,\"carpet_id\":30,\"sequence_no\":2,\"event_type\":\"washing_dispatch\",\"resulting_status\":\"at_washing\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:13:59\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":16,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1046, 1, 'create', 'washing_orders', 16, NULL, '{\"order_no\":\"MWASH-2026-00000003\",\"branch_id\":17,\"facility_id\":15,\"facility_location_id\":42,\"sent_on\":\"2026-11-07\",\"items\":[{\"carpet_id\":30,\"expected_sequence\":1}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:43:59'),
(1047, 1, 'create', 'stock_events', 99, NULL, '{\"id\":99,\"carpet_id\":30,\"sequence_no\":3,\"event_type\":\"washing_complete\",\"resulting_status\":\"washing_completed\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:14:16\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":16,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:16'),
(1048, 1, 'create', 'stock_events', 100, NULL, '{\"id\":100,\"carpet_id\":30,\"sequence_no\":4,\"event_type\":\"washing_return\",\"resulting_status\":\"in_stock\",\"resulting_location_id\":40,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:14:16\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":16,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:16'),
(1049, 1, 'create', 'manager_delivery_acceptances', 16, NULL, '{\"item_id\":16,\"washing_order_item_id\":16,\"accepted_on\":\"2026-11-07\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:16'),
(1050, 1, 'create', 'manager_earned_charges', 16, NULL, '{\"production_order_id\":12,\"item_id\":16,\"earned_on\":\"2026-11-07\",\"amount\":\"30000.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:16'),
(1051, 1, 'create', 'washing_bills', 15, NULL, '{\"expense_no\":\"WBILL-2026-00000003\",\"invoice_no\":\"WBILL-2026-00000003\",\"branch_id\":17,\"facility_id\":15,\"currency_id\":24,\"rate_to_base\":\"1.00000000\",\"billed_on\":\"2026-11-07\",\"amount\":\"500\",\"amount_base\":\"500.0\",\"allocations\":[{\"washing_order_item_id\":16,\"amount\":\"500.00\"}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:16'),
(1052, 1, 'create', 'account_transactions', 112, NULL, '{\"id\":112,\"account_id\":20,\"direction\":\"out\",\"amount\":\"500.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Washing payment for MPR-2026-00000002\",\"created_by\":1,\"created_at\":\"2026-11-07 08:14:16\",\"idempotency_key\":\"4d5e75e1d3596c236e0e57588f8eaa0ff39a792dc965f241b84f0be576fd1232:ledger\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:16'),
(1053, 1, 'create', 'washing_payments', 15, NULL, '{\"id\":15,\"payment_no\":\"WMPAY-2026-00000003\",\"washing_bill_id\":15,\"account_transaction_id\":112,\"amount_in_bill_currency\":\"500.00\",\"bill_units_per_payment_unit\":\"1.00000000\",\"created_by\":1,\"created_at\":\"2026-11-07 08:14:16\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:16'),
(1054, 1, 'create', 'account_transactions', 113, NULL, '{\"id\":113,\"account_id\":20,\"direction\":\"in\",\"amount\":\"50000.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"other_income\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"asfdghj\",\"created_by\":1,\"created_at\":\"2026-11-07 08:14:59\",\"idempotency_key\":\"9569251f-2cfa-44dd-bc2d-3a556afa23d8\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"income\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:44:59'),
(1055, 1, 'create', 'account_transactions', 114, NULL, '{\"id\":114,\"account_id\":20,\"direction\":\"out\",\"amount\":\"30000.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Payment for MPR-2026-00000002\",\"created_by\":1,\"created_at\":\"2026-11-07 08:15:25\",\"idempotency_key\":\"business:0eade15e8282014274a895290cff12dc917953ca105aae786301dd45eb3b1f86\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:45:25'),
(1056, 1, 'create', 'manager_production_payments', 16, NULL, '{\"account_transaction_id\":114,\"document_amount\":\"30000.0\",\"units_per_payment_unit\":\"1\",\"production_order_id\":12,\"payment_type\":\"production\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:45:25'),
(1057, 1, 'create', 'manager_production_orders', 13, NULL, '{\"manager_id\":11,\"branch_id\":17,\"currency_id\":24,\"rate_to_base\":\"1\",\"ordered_on\":\"2026-11-07\",\"due_on\":\"2026-11-07\",\"description\":\"Production: \\u0641\\u0631\\u0634 \\u0627\\u0632\\u067e\\u06cc\\u06a9\\u06cc \\u0627\\u0646\\u0627\\u0631\\u06cc\",\"payment_trigger\":\"stock_acceptance\",\"order_no\":\"MPR-2026-00000003\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:46:54'),
(1058, 1, 'create', 'manager_production_lines', 13, NULL, '{\"name\":\"\\u0641\\u0631\\u0634 \\u0627\\u0632\\u067e\\u06cc\\u06a9\\u06cc \\u0627\\u0646\\u0627\\u0631\\u06cc\",\"category_id\":15,\"target_quantity\":1,\"length_cm\":\"400\",\"width_cm\":\"200\",\"pricing_method\":\"area\",\"rate\":\"0\",\"production_order_id\":13}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:46:54'),
(1059, 1, 'create', 'manager_material_movements', 38, NULL, '{\"lot_id\":11,\"movement_type\":\"consume\",\"quantity\":\"2\",\"moved_on\":\"2026-11-07\",\"reason\":\"Materials used for MPR-2026-00000003\",\"production_order_id\":13,\"cost_base\":\"800.00\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1060, 1, 'create', 'manager_production_deliveries', 17, NULL, '{\"production_order_id\":13,\"delivery_no\":\"MDL-2026-00000004\",\"delivered_on\":\"2026-11-07\",\"charge_amount\":\"0.00\",\"material_cost_base\":\"800.00\",\"total_cost_base\":\"800.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1061, 1, 'create', 'manager_delivery_materials', 20, NULL, '{\"delivery_id\":17,\"movement_id\":38}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1062, 1, 'create', 'carpets', 31, NULL, '{\"id\":31,\"carpet_code\":\"MPC-2026-00000004\",\"name\":\"\\u0641\\u0631\\u0634 \\u0627\\u0632\\u067e\\u06cc\\u06a9\\u06cc \\u0627\\u0646\\u0627\\u0631\\u06cc\",\"registration_branch_id\":17,\"category_id\":15,\"size_label\":null,\"length_cm\":\"400.00\",\"width_cm\":\"200.00\",\"color\":null,\"material\":null,\"notes\":null,\"version\":1,\"created_by\":1,\"updated_by\":1,\"created_at\":\"2026-11-07 08:17:59\",\"updated_at\":\"2026-11-07 08:17:59\",\"deleted_at\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1063, 1, 'create', 'manager_delivery_items', 17, NULL, '{\"delivery_id\":17,\"production_order_id\":13,\"line_id\":13,\"carpet_id\":31,\"length_cm\":\"400.00\",\"width_cm\":\"200.00\",\"area_m2\":\"8.000000\",\"charge_amount\":\"0.00\",\"cost_base\":\"800.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1064, 1, 'create', 'stock_events', 101, NULL, '{\"carpet_id\":31,\"sequence_no\":1,\"event_type\":\"production_complete\",\"resulting_status\":\"production_completed\",\"resulting_location_id\":null,\"manager_delivery_item_id\":17,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07T08:17:59.675649Z\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1065, 1, 'create', 'stock_events', 102, NULL, '{\"id\":102,\"carpet_id\":31,\"sequence_no\":2,\"event_type\":\"washing_dispatch\",\"resulting_status\":\"at_washing\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:17:59\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":17,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1066, 1, 'create', 'washing_orders', 17, NULL, '{\"order_no\":\"MWASH-2026-00000004\",\"branch_id\":17,\"facility_id\":15,\"facility_location_id\":42,\"sent_on\":\"2026-11-07\",\"items\":[{\"carpet_id\":31,\"expected_sequence\":1}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:47:59'),
(1067, 1, 'create', 'stock_events', 103, NULL, '{\"id\":103,\"carpet_id\":31,\"sequence_no\":3,\"event_type\":\"washing_complete\",\"resulting_status\":\"washing_completed\",\"resulting_location_id\":42,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:18:39\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":17,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:48:39'),
(1068, 1, 'create', 'stock_events', 104, NULL, '{\"id\":104,\"carpet_id\":31,\"sequence_no\":4,\"event_type\":\"washing_return\",\"resulting_status\":\"in_stock\",\"resulting_location_id\":40,\"weaver_purchase_item_id\":null,\"stock_receipt_id\":null,\"stock_transfer_id\":null,\"occurred_on\":\"2026-11-07\",\"handled_by\":1,\"created_at\":\"2026-11-07 08:18:39\",\"purchase_void_id\":null,\"stock_adjustment_id\":null,\"weaver_return_item_id\":null,\"washing_order_item_id\":17,\"production_receipt_item_id\":null,\"trade_sale_item_id\":null,\"manager_delivery_item_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:48:39'),
(1069, 1, 'create', 'manager_delivery_acceptances', 17, NULL, '{\"item_id\":17,\"washing_order_item_id\":17,\"accepted_on\":\"2026-11-07\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:48:39'),
(1070, 1, 'create', 'manager_earned_charges', 17, NULL, '{\"production_order_id\":13,\"item_id\":17,\"earned_on\":\"2026-11-07\",\"amount\":\"0.00\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:48:39'),
(1071, 1, 'create', 'washing_bills', 16, NULL, '{\"expense_no\":\"WBILL-2026-00000004\",\"invoice_no\":\"WBILL-2026-00000004\",\"branch_id\":17,\"facility_id\":15,\"currency_id\":24,\"rate_to_base\":\"1.00000000\",\"billed_on\":\"2026-11-07\",\"amount\":\"500\",\"amount_base\":\"500.0\",\"allocations\":[{\"washing_order_item_id\":17,\"amount\":\"500.00\"}]}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:48:39');
INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1072, 1, 'create', 'account_transactions', 115, NULL, '{\"id\":115,\"account_id\":20,\"direction\":\"out\",\"amount\":\"500.00\",\"currency_id\":24,\"exchange_rate\":\"1.00000000\",\"ref_type\":\"adjustment\",\"ref_id\":null,\"transaction_date\":\"2026-11-07\",\"description\":\"Washing payment for MPR-2026-00000003\",\"created_by\":1,\"created_at\":\"2026-11-07 08:18:39\",\"idempotency_key\":\"01da8c1669dc528bdf6454a15dc57bf7ee79c65cb4878b36b5fc1f2af4fef5d9:ledger\",\"rate_to_base\":\"1.00000000\",\"entry_type\":\"expense\",\"reversal_of_id\":null}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:48:39'),
(1073, 1, 'create', 'washing_payments', 16, NULL, '{\"id\":16,\"payment_no\":\"WMPAY-2026-00000004\",\"washing_bill_id\":16,\"account_transaction_id\":115,\"amount_in_bill_currency\":\"500.00\",\"bill_units_per_payment_unit\":\"1.00000000\",\"created_by\":1,\"created_at\":\"2026-11-07 08:18:39\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:48:39'),
(1074, 1, 'create', 'manager_material_movements', 39, NULL, '{\"lot_id\":11,\"movement_type\":\"return\",\"quantity\":\"2\",\"moved_on\":\"2026-11-07\",\"reason\":\"asfdggf\",\"cost_base\":\"0\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:49:08'),
(1075, 1, 'create', 'manager_tool_movements', 13, NULL, '{\"lot_id\":8,\"movement_type\":\"return\",\"quantity\":2,\"moved_on\":\"2026-11-07\",\"condition\":\"good\",\"reason\":\"fsbbhgfhfg\",\"manager_id\":11}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:49:43'),
(1076, 1, 'create', 'customers', 13, NULL, '{\"id\":13,\"name\":\"\\u0634\\u0627\\u06a9\\u0631 \\u062e\\u0627\\u0646\",\"phone\":\"+9378975667\",\"address\":\"kabul,afghanisan\",\"customer_type\":\"retail\",\"registration_date\":\"2026-11-07\",\"notes\":\"sdfds\",\"status\":\"active\",\"deleted_at\":null,\"created_by\":1,\"created_at\":\"2026-11-07T08:26:28.000000Z\",\"updated_at\":\"2026-11-07T08:26:28.000000Z\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36', '2026-11-07 03:56:28');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `code`, `name`, `location`, `phone`, `is_main`, `status`, `deleted_at`, `created_at`, `updated_at`) VALUES
(17, 'GH32423', 'شعبه مرکزی کابل', 'یسبسیب', '+3789647998', 0, 'active', NULL, '2026-11-07 06:50:26', '2026-11-07 02:20:26');

-- --------------------------------------------------------

--
-- Table structure for table `branding_images`
--

CREATE TABLE `branding_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slot` enum('story_light','story_dark','logo_light','logo_dark','footer_signature_light','footer_signature_dark') NOT NULL,
  `version` char(36) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `mime_type` enum('image/png','image/jpeg','image/webp') NOT NULL,
  `bytes` int(10) UNSIGNED NOT NULL,
  `width` smallint(5) UNSIGNED NOT NULL,
  `height` smallint(5) UNSIGNED NOT NULL,
  `updated_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `business_voids`
--

CREATE TABLE `business_voids` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `expense_id` bigint(20) UNSIGNED NOT NULL,
  `voided_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `business_voids`
--
DELIMITER $$
CREATE TRIGGER `business_voids_no_delete` BEFORE DELETE ON `business_voids` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `business_voids_no_update` BEFORE UPDATE ON `business_voids` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `carpets`
--

CREATE TABLE `carpets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `carpet_code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `registration_branch_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `size_label` varchar(60) DEFAULT NULL,
  `length_cm` decimal(10,2) DEFAULT NULL,
  `width_cm` decimal(10,2) DEFAULT NULL,
  `color` varchar(60) DEFAULT NULL,
  `material` varchar(100) DEFAULT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ;

--
-- Dumping data for table `carpets`
--

INSERT INTO `carpets` (`id`, `carpet_code`, `name`, `registration_branch_id`, `category_id`, `size_label`, `length_cm`, `width_cm`, `color`, `material`, `notes`, `version`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(28, 'MPC-2026-00000001', 'فرش چوبی گلدار سه در دو', 17, 15, NULL, 300.00, 200.00, NULL, NULL, 'sdfsdf', 1, 1, 1, '2026-11-07 03:27:21', '2026-11-07 03:27:21', NULL),
(29, 'MPC-2026-00000002', 'فرش چوبی گلدار سه در دو', 17, 15, NULL, 300.00, 200.00, NULL, NULL, 'sdfsdf', 1, 1, 1, '2026-11-07 03:32:42', '2026-11-07 03:32:42', NULL),
(30, 'MPC-2026-00000003', 'قالین اوزبیک چهار مغزی', 17, 15, NULL, 200.00, 300.00, NULL, NULL, NULL, 1, 1, 1, '2026-11-07 03:43:59', '2026-11-07 03:43:59', NULL),
(31, 'MPC-2026-00000004', 'فرش ازپیکی اناری', 17, 15, NULL, 400.00, 200.00, NULL, NULL, NULL, 1, 1, 1, '2026-11-07 03:47:59', '2026-11-07 03:47:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `carpet_categories`
--

CREATE TABLE `carpet_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_fa` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `carpet_categories`
--

INSERT INTO `carpet_categories` (`id`, `name`, `name_fa`, `description`, `status`) VALUES
(15, 'GHDGSF', 'قالین چوبی ـ تمرین دستی', 'dfghjk', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `carpet_images`
--

CREATE TABLE `carpet_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `storage_path` varchar(500) NOT NULL,
  `mime_type` varchar(30) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `carpet_purchases`
--

CREATE TABLE `carpet_purchases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_no` varchar(30) NOT NULL,
  `weaver_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `purchase_date` date NOT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(14,2) GENERATED ALWAYS AS (`total_amount` - `paid_amount`) STORED,
  `payment_status` enum('unpaid','partial','paid') GENERATED ALWAYS AS (case when `paid_amount` <= 0 then 'unpaid' when `paid_amount` >= `total_amount` then 'paid' else 'partial' end) STORED,
  `responsible_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `carpet_purchase_items`
--

CREATE TABLE `carpet_purchase_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(14,2) NOT NULL,
  `subtotal` decimal(14,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE `company_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `base_currency_id` int(10) UNSIGNED DEFAULT NULL,
  `default_locale` enum('en','fa') NOT NULL DEFAULT 'en',
  `timezone` varchar(60) NOT NULL DEFAULT 'Asia/Kabul',
  `reminder_days_before` smallint(5) UNSIGNED NOT NULL DEFAULT 3,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `reset_version` bigint(20) UNSIGNED NOT NULL DEFAULT 0
) ;

--
-- Dumping data for table `company_settings`
--

INSERT INTO `company_settings` (`id`, `base_currency_id`, `default_locale`, `timezone`, `reminder_days_before`, `updated_by`, `created_at`, `updated_at`, `reset_version`) VALUES
(1, 24, 'en', 'Asia/Kabul', 3, 1, '2026-11-07 02:17:43', '2026-11-07 02:19:25', 11);

-- --------------------------------------------------------

--
-- Table structure for table `currencies`
--

CREATE TABLE `currencies` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` char(3) NOT NULL,
  `name` varchar(50) NOT NULL,
  `symbol` varchar(8) NOT NULL,
  `decimal_places` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `currencies`
--

INSERT INTO `currencies` (`id`, `code`, `name`, `symbol`, `decimal_places`, `is_default`, `is_active`, `created_at`, `updated_at`) VALUES
(24, 'AFN', 'AFN', 'AFN', 1, 1, 1, '2026-11-07 06:48:48', '2026-11-07 06:49:25');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `customer_type` enum('retail','wholesale','vip','other') NOT NULL DEFAULT 'retail',
  `registration_date` date NOT NULL DEFAULT curdate(),
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `address`, `customer_type`, `registration_date`, `notes`, `status`, `deleted_at`, `created_by`, `created_at`, `updated_at`) VALUES
(13, 'شاکر خان', '+9378975667', 'kabul,afghanisan', 'retail', '2026-11-07', 'sdfds', 'active', NULL, 1, '2026-11-07 03:56:28', '2026-11-07 03:56:28');

-- --------------------------------------------------------

--
-- Table structure for table `customer_payments`
--

CREATE TABLE `customer_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_no` varchar(30) NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = payment on account',
  `schedule_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'settles a specific installment',
  `amount` decimal(14,2) NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK added after accounts',
  `responsible_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `districts`
--

CREATE TABLE `districts` (
  `id` int(10) UNSIGNED NOT NULL,
  `province_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_fa` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_sequences`
--

CREATE TABLE `document_sequences` (
  `kind` varchar(40) NOT NULL,
  `last_number` bigint(20) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_sequences`
--

INSERT INTO `document_sequences` (`kind`, `last_number`) VALUES
('MAT', 1),
('MDL', 4),
('MPC', 4),
('MPR', 3),
('MTL', 1),
('MWASH', 4),
('SUP', 1),
('WBILL', 4),
('WMPAY', 4);

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `status` enum('active','inactive','terminated') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates`
--

CREATE TABLE `exchange_rates` (
  `id` int(10) UNSIGNED NOT NULL,
  `base_currency_id` int(10) UNSIGNED NOT NULL,
  `quote_currency_id` int(10) UNSIGNED NOT NULL,
  `rate` decimal(20,8) NOT NULL,
  `effective_date` date NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `expense_no` varchar(30) NOT NULL,
  `expense_category_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `expense_date` date NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `responsible_employee_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `expense_attachments`
--

CREATE TABLE `expense_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `expense_id` bigint(20) UNSIGNED NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `original_name` varchar(150) NOT NULL,
  `mime_type` varchar(60) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_fa` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_commands`
--

CREATE TABLE `inventory_commands` (
  `idempotency_key` varchar(100) NOT NULL,
  `actor_id` bigint(20) UNSIGNED NOT NULL,
  `operation` varchar(80) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`response`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_commands`
--

INSERT INTO `inventory_commands` (`idempotency_key`, `actor_id`, `operation`, `request_hash`, `response`, `created_at`) VALUES
('07940470-4aa6-49b7-ba15-ab3ebb048044', 1, 'production-workflow:accept:11', 'fff4fbcab217c9a5f4d1cfe5d3316cdbda4e759234356b80489415a2c46f2112', '{\"id\":11,\"washing_bill_id\":13}', '2026-11-07 03:27:52'),
('0a771b40-b42a-47e9-9f69-77aa056553c8', 1, 'production-workflow:accept:11', '774abca750c973d891dbacc01a1a5c52dfb1e617d2084227aac17a4ec89fb81b', '{\"id\":11,\"washing_bill_id\":14}', '2026-11-07 03:33:01'),
('0e175e5b-ff1d-4ce1-b4bd-1401edb2194e', 1, 'production-workflow:supply-pay:9', 'b6816bff6924821cd04452f6fdd92b653eb2ab5875cddbfba7f8675c2aa17d28', '{\"id\":9,\"account_transaction_id\":107}', '2026-11-07 02:49:51'),
('214b967d-fbc9-4593-82dd-fe7247b2cb53', 1, 'production-workflow:issue:11', 'd70089a86ceb27c01cd037203da11fbe2a553a0fd4713cf90fe1cb71ed07fe94', '{\"id\":11}', '2026-11-07 03:24:03'),
('21d07f83-54ee-4635-b0de-bea79c0d87b7', 1, 'production-workflow:receive:13', 'fa75b53cf3de17e6456007375e49db760af1322369ea2e3b93cfcb35fed6a696', '{\"id\":17,\"delivery_no\":\"MDL-2026-00000004\",\"washing_order_id\":17,\"charge_amount\":\"0.00\",\"total_cost_base\":\"800.00\"}', '2026-11-07 03:47:59'),
('23e9975a-8201-4d29-9fa2-edf8069f474a', 1, 'production-workflow:setup:', '6cc88b33e992570a6e567487f882f5a80e0bbab9890f5701926fc7c8b10ef797', '{\"id\":8}', '2026-11-07 02:51:38'),
('2f9550dd-4f3d-43ef-b60a-4a8752a1127d', 1, 'production-workflow:settle:12', 'a871cd8c00bc112bfcef841bc1ee246249b25d86389ba3f00eff6eb220af26a8', '{\"id\":16}', '2026-11-07 03:45:25'),
('38d9f867-55f7-44b0-b376-87753eb55fb8', 1, 'business:materials:create:', 'edd982f59df3db9fbe5072e91850ed5af400bf2f164287f065e86a5695b0ba03', '{\"id\":13}', '2026-11-07 02:41:32'),
('4cb97f27-a780-460b-bdf1-7db3cffbd0a8', 1, 'production-workflow:settle:11', '4299f0bf5ec762e2105964504e327a505c61a665902878ac3837a63b7ac58728', '{\"id\":15}', '2026-11-07 03:33:06'),
('4f027857-b942-4dba-8b3e-d4b3616b96d1', 1, 'production-workflow:issue:12', '80576581a61511d6c956525019a6cbb69e2386c2b345d1deed133a5ebe8cd8a4', '{\"id\":12}', '2026-11-07 03:43:06'),
('52448c4e-4be6-4954-8b57-be640d048767', 1, 'manager-production:order:', 'a5dab48864f09a51d0f84c64ecfe01be96043f8e38bd7cdcf8890d56169a87c4', '{\"id\":11,\"order_no\":\"MPR-2026-00000001\"}', '2026-11-07 03:23:33'),
('61093cdc-3aa0-4800-960f-69754f996a50', 1, 'production-workflow:accept:13', '41fe18a822521b5fd0c78e6bd507a8eceff7c844bfd53aaca3e0da7332d85aba', '{\"id\":13,\"washing_bill_id\":16}', '2026-11-07 03:48:39'),
('62f2381e-d24f-4701-9ccf-b8ddf14f428f', 1, 'production-workflow:receive:12', '4f28be24ff3a355badd71cc45243f28d65c0c53ad42d97898c08c6d489834222', '{\"id\":16,\"delivery_no\":\"MDL-2026-00000003\",\"washing_order_id\":16,\"charge_amount\":\"30000.00\",\"total_cost_base\":\"30400.00\"}', '2026-11-07 03:43:59'),
('64623606-d45d-4db6-8869-413dfdaeb20c', 1, 'production-workflow:supply:', '34c94e5ffb73b7d0e223fd551f892c9d7b742832ccaab88bf5a78fa36186c437', '{\"id\":9,\"purchase_no\":\"SUP-2026-00000001\",\"total_amount\":\"9500.00\"}', '2026-11-07 02:46:53'),
('6f1e24ff-cb32-45f8-bf9c-16b519b9f962', 1, 'manager-production:order:', '80d9604e1e7c6dc7f7d3d70bf3dc3475930698976e91fee84d38bdd8e316079d', '{\"id\":13,\"order_no\":\"MPR-2026-00000003\"}', '2026-11-07 03:46:54'),
('9076e52e-036a-4f1c-8c22-17d4c9bd85fa', 1, 'production-workflow:receive:11', '132fd03f17d65c37ca55b395d6e91e832502c2d45d99381f83318c494df31a20', '{\"id\":14,\"delivery_no\":\"MDL-2026-00000001\",\"washing_order_id\":14,\"charge_amount\":\"3600.00\",\"total_cost_base\":\"7600.00\"}', '2026-11-07 03:27:21'),
('9c09afdf-18a3-4d31-9f72-70b7583050f4', 1, 'production-workflow:accept:12', 'c3336ee39b643b498012f511495c76779e010bf98532938ecc4ae8a28c7e93ef', '{\"id\":12,\"washing_bill_id\":15}', '2026-11-07 03:44:16'),
('b5cca5b8-7341-4fbf-ae97-d7098ab54893', 1, 'manager-resources:tools:create', 'd08dda3a2ff734ebd49d1c59b0eae8ebc1b9fff1c4e9c7a7d4e0188ff4861011', '{\"id\":9}', '2026-11-07 02:45:36'),
('b8d6ddb7-52a9-4f37-936f-6e204945f9e1', 1, 'production-workflow:receive:11', 'd7e72f28e2dc3ab7cf111e2bea06bae1daa94747367b120873cfb2746f58fa1d', '{\"id\":15,\"delivery_no\":\"MDL-2026-00000002\",\"washing_order_id\":15,\"charge_amount\":\"3600.00\",\"total_cost_base\":\"3600.00\"}', '2026-11-07 03:32:42'),
('cfdd4390-b7cb-4a81-b9e3-3162ec2a2057', 1, 'production-workflow:settle:11', '4299f0bf5ec762e2105964504e327a505c61a665902878ac3837a63b7ac58728', '{\"id\":14}', '2026-11-07 03:31:43'),
('e2fd2761-4e91-4459-b17c-cd85d83cd2ad', 1, 'manager-resources:managers:create', '1cf8a7b62c87fa396863c0d6a660cfb9fdc93fd091399edb9040655f530e9e3c', '{\"id\":11}', '2026-11-07 02:50:21'),
('e5727dc2-8ab4-488c-9d64-aec57dac1827', 1, 'manager-production:order:', '67525149736a850794707bbf4d506323462e83f2b66dc49287555f23f0aef338', '{\"id\":12,\"order_no\":\"MPR-2026-00000002\"}', '2026-11-07 03:41:21'),
('e582120d-f846-43e4-b2ca-1dac2713a1e0', 1, 'manager-resources:manager-tool:11', '639a35a11e76eea0cfb83a9856a740a0bcd7267b532f29be351b2747fbe946a8', '{\"id\":13}', '2026-11-07 03:49:43'),
('f458c673-706c-4df5-8cf6-8461b7f4333d', 1, 'manager-resources:manager-material:11', 'cb834030acdda95709ef2459119b5d31d3a1ec178a104c15e7aafec2a0c8c1c2', '{\"id\":39}', '2026-11-07 03:49:08');

--
-- Triggers `inventory_commands`
--
DELIMITER $$
CREATE TRIGGER `inventory_commands_no_delete` BEFORE DELETE ON `inventory_commands` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `inventory_commands_no_update` BEFORE UPDATE ON `inventory_commands` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `legacy_carpets`
--

CREATE TABLE `legacy_carpets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `carpet_code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `size_name` varchar(60) DEFAULT NULL,
  `length_cm` decimal(8,2) DEFAULT NULL,
  `width_cm` decimal(8,2) DEFAULT NULL,
  `color` varchar(60) DEFAULT NULL,
  `material` varchar(60) DEFAULT NULL,
  `weaver_id` bigint(20) UNSIGNED DEFAULT NULL,
  `purchase_price` decimal(14,2) DEFAULT NULL,
  `washing_cost` decimal(14,2) DEFAULT NULL,
  `selling_price` decimal(14,2) DEFAULT NULL,
  `currency_id` int(10) UNSIGNED DEFAULT NULL,
  `current_branch_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'where the carpet is now (§5)',
  `current_location` varchar(120) DEFAULT NULL COMMENT 'shelf/room note inside the branch',
  `status` enum('purchased','received','at_washing','washing_completed','in_stock','reserved','sold','transferred') NOT NULL DEFAULT 'purchased',
  `image_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `legacy_washing_orders`
--

CREATE TABLE `legacy_washing_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_no` varchar(30) NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `washing_facility_id` int(10) UNSIGNED NOT NULL,
  `sent_date` date NOT NULL,
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` date DEFAULT NULL,
  `washing_cost` decimal(14,2) DEFAULT NULL,
  `currency_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('sent','in_progress','completed','cancelled') NOT NULL DEFAULT 'sent',
  `responsible_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `legacy_weaver_payments`
--

CREATE TABLE `legacy_weaver_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_no` varchar(30) NOT NULL,
  `weaver_id` bigint(20) UNSIGNED NOT NULL,
  `payment_type` enum('purchase_payment','additional_payment') NOT NULL,
  `carpet_purchase_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'required for purchase_payment, optional link for additional',
  `amount` decimal(14,2) NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL COMMENT 'why an additional payment was made (§9)',
  `account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'account used (§14) — FK added after accounts',
  `responsible_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_delivery_acceptances`
--

CREATE TABLE `manager_delivery_acceptances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `item_id` bigint(20) UNSIGNED NOT NULL,
  `washing_order_item_id` bigint(20) UNSIGNED NOT NULL,
  `accepted_on` date NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `manager_delivery_acceptances`
--

INSERT INTO `manager_delivery_acceptances` (`id`, `item_id`, `washing_order_item_id`, `accepted_on`, `created_by`, `created_at`) VALUES
(14, 14, 14, '2026-11-07', 1, '2026-11-07 03:27:52'),
(15, 15, 15, '2026-11-07', 1, '2026-11-07 03:33:01'),
(16, 16, 16, '2026-11-07', 1, '2026-11-07 03:44:16'),
(17, 17, 17, '2026-11-07', 1, '2026-11-07 03:48:39');

--
-- Triggers `manager_delivery_acceptances`
--
DELIMITER $$
CREATE TRIGGER `manager_delivery_acceptances_no_delete` BEFORE DELETE ON `manager_delivery_acceptances` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_delivery_acceptances_no_update` BEFORE UPDATE ON `manager_delivery_acceptances` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_delivery_items`
--

CREATE TABLE `manager_delivery_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `delivery_id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `line_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `length_cm` decimal(10,2) NOT NULL,
  `width_cm` decimal(10,2) NOT NULL,
  `area_m2` decimal(18,6) NOT NULL,
  `charge_amount` decimal(18,2) NOT NULL,
  `cost_base` decimal(18,2) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `manager_delivery_items`
--

INSERT INTO `manager_delivery_items` (`id`, `delivery_id`, `production_order_id`, `line_id`, `carpet_id`, `length_cm`, `width_cm`, `area_m2`, `charge_amount`, `cost_base`, `created_by`, `created_at`) VALUES
(14, 14, 11, 11, 28, 300.00, 200.00, 6.000000, 3600.00, 7600.00, 1, '2026-11-07 03:27:21'),
(15, 15, 11, 11, 29, 300.00, 200.00, 6.000000, 3600.00, 3600.00, 1, '2026-11-07 03:32:42'),
(16, 16, 12, 12, 30, 200.00, 300.00, 6.000000, 30000.00, 30400.00, 1, '2026-11-07 03:43:59'),
(17, 17, 13, 13, 31, 400.00, 200.00, 8.000000, 0.00, 800.00, 1, '2026-11-07 03:47:59');

--
-- Triggers `manager_delivery_items`
--
DELIMITER $$
CREATE TRIGGER `manager_delivery_items_no_delete` BEFORE DELETE ON `manager_delivery_items` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_delivery_items_no_update` BEFORE UPDATE ON `manager_delivery_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_delivery_materials`
--

CREATE TABLE `manager_delivery_materials` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `delivery_id` bigint(20) UNSIGNED NOT NULL,
  `movement_id` bigint(20) UNSIGNED NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `manager_delivery_materials`
--

INSERT INTO `manager_delivery_materials` (`id`, `delivery_id`, `movement_id`, `created_by`, `created_at`) VALUES
(18, 14, 35, 1, '2026-11-07 03:27:21'),
(19, 16, 37, 1, '2026-11-07 03:43:59'),
(20, 17, 38, 1, '2026-11-07 03:47:59');

--
-- Triggers `manager_delivery_materials`
--
DELIMITER $$
CREATE TRIGGER `manager_delivery_materials_no_delete` BEFORE DELETE ON `manager_delivery_materials` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_delivery_materials_no_update` BEFORE UPDATE ON `manager_delivery_materials` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_earned_charges`
--

CREATE TABLE `manager_earned_charges` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `item_id` bigint(20) UNSIGNED NOT NULL,
  `earned_on` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `manager_earned_charges`
--

INSERT INTO `manager_earned_charges` (`id`, `production_order_id`, `item_id`, `earned_on`, `amount`, `created_by`, `created_at`) VALUES
(14, 11, 14, '2026-11-07', 3600.00, 1, '2026-11-07 03:27:52'),
(15, 11, 15, '2026-11-07', 3600.00, 1, '2026-11-07 03:33:01'),
(16, 12, 16, '2026-11-07', 30000.00, 1, '2026-11-07 03:44:16'),
(17, 13, 17, '2026-11-07', 0.00, 1, '2026-11-07 03:48:39');

--
-- Triggers `manager_earned_charges`
--
DELIMITER $$
CREATE TRIGGER `manager_earned_charges_no_delete` BEFORE DELETE ON `manager_earned_charges` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_earned_charges_no_update` BEFORE UPDATE ON `manager_earned_charges` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_material_movements`
--

CREATE TABLE `manager_material_movements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `manager_id` bigint(20) UNSIGNED NOT NULL,
  `lot_id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `movement_type` varchar(12) NOT NULL,
  `quantity` decimal(18,3) NOT NULL,
  `cost_base` decimal(18,2) NOT NULL,
  `moved_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `manager_material_movements`
--

INSERT INTO `manager_material_movements` (`id`, `manager_id`, `lot_id`, `production_order_id`, `movement_type`, `quantity`, `cost_base`, `moved_on`, `reason`, `created_by`, `created_at`) VALUES
(34, 11, 11, 11, 'issue', 10.000, 0.00, '2026-11-07', 'Give supplies for MPR-2026-00000001', 1, '2026-11-07 03:24:03'),
(35, 11, 11, 11, 'consume', 10.000, 4000.00, '2026-11-07', 'Materials used for MPR-2026-00000001', 1, '2026-11-07 03:27:21'),
(36, 11, 11, 12, 'issue', 5.000, 0.00, '2026-11-07', 'Give supplies for MPR-2026-00000002', 1, '2026-11-07 03:43:06'),
(37, 11, 11, 12, 'consume', 1.000, 400.00, '2026-11-07', 'Materials used for MPR-2026-00000002', 1, '2026-11-07 03:43:59'),
(38, 11, 11, 13, 'consume', 2.000, 800.00, '2026-11-07', 'Materials used for MPR-2026-00000003', 1, '2026-11-07 03:47:59'),
(39, 11, 11, NULL, 'return', 2.000, 0.00, '2026-11-07', 'asfdggf', 1, '2026-11-07 03:49:08');

--
-- Triggers `manager_material_movements`
--
DELIMITER $$
CREATE TRIGGER `manager_material_movements_no_delete` BEFORE DELETE ON `manager_material_movements` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_material_movements_no_update` BEFORE UPDATE ON `manager_material_movements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_pricing_factors`
--

CREATE TABLE `manager_pricing_factors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `line_id` bigint(20) UNSIGNED DEFAULT NULL,
  `label` varchar(150) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `operation` varchar(10) NOT NULL,
  `value` decimal(20,6) NOT NULL,
  `quantity` decimal(18,6) DEFAULT NULL,
  `position` smallint(5) UNSIGNED NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `manager_pricing_factors`
--
DELIMITER $$
CREATE TRIGGER `manager_pricing_factors_no_delete` BEFORE DELETE ON `manager_pricing_factors` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_pricing_factors_no_update` BEFORE UPDATE ON `manager_pricing_factors` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_production_closures`
--

CREATE TABLE `manager_production_closures` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `closed_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `manager_production_closures`
--
DELIMITER $$
CREATE TRIGGER `manager_production_closures_no_delete` BEFORE DELETE ON `manager_production_closures` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_production_closures_no_update` BEFORE UPDATE ON `manager_production_closures` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_production_deliveries`
--

CREATE TABLE `manager_production_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `delivery_no` varchar(40) NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `delivered_on` date NOT NULL,
  `charge_amount` decimal(18,2) NOT NULL,
  `material_cost_base` decimal(18,2) NOT NULL,
  `total_cost_base` decimal(18,2) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `manager_production_deliveries`
--

INSERT INTO `manager_production_deliveries` (`id`, `delivery_no`, `production_order_id`, `delivered_on`, `charge_amount`, `material_cost_base`, `total_cost_base`, `created_by`, `created_at`) VALUES
(14, 'MDL-2026-00000001', 11, '2026-11-07', 3600.00, 4000.00, 7600.00, 1, '2026-11-07 03:27:21'),
(15, 'MDL-2026-00000002', 11, '2026-11-07', 3600.00, 0.00, 3600.00, 1, '2026-11-07 03:32:42'),
(16, 'MDL-2026-00000003', 12, '2026-11-07', 30000.00, 400.00, 30400.00, 1, '2026-11-07 03:43:59'),
(17, 'MDL-2026-00000004', 13, '2026-11-07', 0.00, 800.00, 800.00, 1, '2026-11-07 03:47:59');

--
-- Triggers `manager_production_deliveries`
--
DELIMITER $$
CREATE TRIGGER `manager_production_deliveries_no_delete` BEFORE DELETE ON `manager_production_deliveries` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_production_deliveries_no_update` BEFORE UPDATE ON `manager_production_deliveries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_production_lines`
--

CREATE TABLE `manager_production_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `target_quantity` int(10) UNSIGNED NOT NULL,
  `length_cm` decimal(10,2) DEFAULT NULL,
  `width_cm` decimal(10,2) DEFAULT NULL,
  `pricing_method` varchar(10) NOT NULL,
  `rate` decimal(18,2) NOT NULL,
  `manual_reason` varchar(500) DEFAULT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `manager_production_lines`
--

INSERT INTO `manager_production_lines` (`id`, `production_order_id`, `name`, `category_id`, `target_quantity`, `length_cm`, `width_cm`, `pricing_method`, `rate`, `manual_reason`, `notes`, `created_by`, `created_at`) VALUES
(11, 11, 'فرش چوبی گلدار سه در دو', 15, 2, 300.00, 200.00, 'area', 600.00, NULL, 'sdfsdf', 1, '2026-11-07 03:23:33'),
(12, 12, 'قالین اوزبیک چهار مغزی', 15, 1, NULL, NULL, 'area', 5000.00, NULL, NULL, 1, '2026-11-07 03:41:21'),
(13, 13, 'فرش ازپیکی اناری', 15, 1, 400.00, 200.00, 'area', 0.00, NULL, NULL, 1, '2026-11-07 03:46:54');

--
-- Triggers `manager_production_lines`
--
DELIMITER $$
CREATE TRIGGER `manager_production_lines_no_delete` BEFORE DELETE ON `manager_production_lines` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_production_lines_no_update` BEFORE UPDATE ON `manager_production_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_production_orders`
--

CREATE TABLE `manager_production_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_no` varchar(40) NOT NULL,
  `manager_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `ordered_on` date NOT NULL,
  `due_on` date NOT NULL,
  `description` varchar(1000) NOT NULL,
  `payment_trigger` varchar(20) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `manager_production_orders`
--

INSERT INTO `manager_production_orders` (`id`, `order_no`, `manager_id`, `branch_id`, `currency_id`, `rate_to_base`, `ordered_on`, `due_on`, `description`, `payment_trigger`, `created_by`, `created_at`) VALUES
(11, 'MPR-2026-00000001', 11, 17, 24, 1.00000000, '2026-11-07', '2026-11-07', 'Production: فرش چوبی گلدار سه در دو', 'stock_acceptance', 1, '2026-11-07 03:23:33'),
(12, 'MPR-2026-00000002', 11, 17, 24, 1.00000000, '2026-11-07', '2026-11-07', 'Production: قالین اوزبیک چهار مغزی', 'stock_acceptance', 1, '2026-11-07 03:41:20'),
(13, 'MPR-2026-00000003', 11, 17, 24, 1.00000000, '2026-11-07', '2026-11-07', 'Production: فرش ازپیکی اناری', 'stock_acceptance', 1, '2026-11-07 03:46:54');

--
-- Triggers `manager_production_orders`
--
DELIMITER $$
CREATE TRIGGER `manager_production_orders_no_delete` BEFORE DELETE ON `manager_production_orders` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_production_orders_no_update` BEFORE UPDATE ON `manager_production_orders` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_production_payments`
--

CREATE TABLE `manager_production_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `payment_type` varchar(12) NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `manager_production_payments`
--

INSERT INTO `manager_production_payments` (`id`, `production_order_id`, `account_transaction_id`, `payment_type`, `document_amount`, `units_per_payment_unit`, `created_by`, `created_at`) VALUES
(14, 11, 109, 'production', 3600.00, 1.00000000, 1, '2026-11-07 03:31:43'),
(15, 11, 111, 'production', 3600.00, 1.00000000, 1, '2026-11-07 03:33:06'),
(16, 12, 114, 'production', 30000.00, 1.00000000, 1, '2026-11-07 03:45:25');

--
-- Triggers `manager_production_payments`
--
DELIMITER $$
CREATE TRIGGER `manager_production_payments_no_delete` BEFORE DELETE ON `manager_production_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_production_payments_no_update` BEFORE UPDATE ON `manager_production_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Production facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manager_tool_movements`
--

CREATE TABLE `manager_tool_movements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `manager_id` bigint(20) UNSIGNED NOT NULL,
  `lot_id` bigint(20) UNSIGNED NOT NULL,
  `movement_type` varchar(12) NOT NULL,
  `quantity` bigint(20) UNSIGNED NOT NULL,
  `condition` varchar(255) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `moved_on` date NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `production_order_id` bigint(20) UNSIGNED DEFAULT NULL
) ;

--
-- Dumping data for table `manager_tool_movements`
--

INSERT INTO `manager_tool_movements` (`id`, `manager_id`, `lot_id`, `movement_type`, `quantity`, `condition`, `reason`, `moved_on`, `created_by`, `created_at`, `production_order_id`) VALUES
(12, 11, 8, 'issue', 2, 'good', 'Give supplies for MPR-2026-00000001', '2026-11-07', 1, '2026-11-07 03:24:03', 11),
(13, 11, 8, 'return', 2, 'good', 'fsbbhgfhfg', '2026-11-07', 1, '2026-11-07 03:49:43', NULL);

--
-- Triggers `manager_tool_movements`
--
DELIMITER $$
CREATE TRIGGER `manager_tool_movements_no_delete` BEFORE DELETE ON `manager_tool_movements` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `manager_tool_movements_no_update` BEFORE UPDATE ON `manager_tool_movements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit` varchar(20) NOT NULL,
  `color` varchar(60) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `materials`
--

INSERT INTO `materials` (`id`, `code`, `name`, `unit`, `color`, `status`, `created_by`, `created_at`) VALUES
(13, 'MAN261104-WOOL', 'پشم طبیعی', 'kg', NULL, 'active', 1, '2026-11-07 02:41:32');

-- --------------------------------------------------------

--
-- Table structure for table `material_lots`
--

CREATE TABLE `material_lots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lot_no` varchar(40) NOT NULL,
  `material_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `received_on` date NOT NULL,
  `quantity` decimal(18,3) NOT NULL,
  `unit_cost` decimal(18,2) NOT NULL,
  `total_cost` decimal(18,2) NOT NULL,
  `total_cost_base` decimal(18,2) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `material_lots`
--

INSERT INTO `material_lots` (`id`, `lot_no`, `material_id`, `supplier_id`, `location_id`, `branch_id`, `currency_id`, `rate_to_base`, `received_on`, `quantity`, `unit_cost`, `total_cost`, `total_cost_base`, `created_by`, `created_at`) VALUES
(11, 'MAT-2026-00000001', 13, 12, 39, 17, 24, 1.00000000, '2026-11-07', 20.000, 400.00, 8000.00, 8000.00, 1, '2026-11-07 02:46:53');

--
-- Triggers `material_lots`
--
DELIMITER $$
CREATE TRIGGER `material_lots_no_delete` BEFORE DELETE ON `material_lots` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `material_lots_no_update` BEFORE UPDATE ON `material_lots` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `material_lot_payments`
--

CREATE TABLE `material_lot_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lot_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `amount_in_payment_currency` decimal(18,2) DEFAULT NULL
) ;

--
-- Dumping data for table `material_lot_payments`
--

INSERT INTO `material_lot_payments` (`id`, `lot_id`, `account_transaction_id`, `document_amount`, `units_per_payment_unit`, `created_by`, `created_at`, `amount_in_payment_currency`) VALUES
(18, 11, 107, 8000.00, 1.00000000, 1, '2026-11-07 02:49:51', 8000.00);

--
-- Triggers `material_lot_payments`
--
DELIMITER $$
CREATE TRIGGER `material_lot_payments_no_delete` BEFORE DELETE ON `material_lot_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `material_lot_payments_no_update` BEFORE UPDATE ON `material_lot_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `material_movements`
--

CREATE TABLE `material_movements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lot_id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `movement_type` varchar(12) NOT NULL,
  `moved_on` date NOT NULL,
  `quantity` decimal(18,3) NOT NULL,
  `cost_base` decimal(18,2) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `material_movements`
--
DELIMITER $$
CREATE TRIGGER `material_movements_no_delete` BEFORE DELETE ON `material_movements` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `material_movements_no_update` BEFORE UPDATE ON `material_movements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_10_17_000001_install_foundation', 1),
(5, '2026_10_17_000002_harden_foundation_constraints', 2),
(6, '2026_10_17_000003_install_directories', 3),
(7, '2026_10_19_000004_install_inventory', 4),
(8, '2026_10_19_000005_harden_inventory_dimensions', 5),
(9, '2026_10_19_000006_install_inventory_corrections', 6),
(10, '2026_10_19_000007_allow_unreceived_item_returns', 7),
(11, '2026_10_19_000008_enable_confirmed_system_reset', 8),
(12, '2026_10_20_000009_create_branding_images', 9),
(13, '2026_10_20_000010_install_washing', 10),
(14, '2026_10_21_000011_company_accounts', 11),
(15, '2026_10_21_000012_business_workflows', 11),
(16, '2026_10_21_000013_business_integrity', 12),
(17, '2026_10_24_000014_manager_resources', 13),
(18, '2026_10_24_000015_manager_production', 13),
(19, '2026_10_24_000016_link_manager_production_stock', 13),
(20, '2026_10_24_000017_simplify_production_workflow', 14),
(21, '2026_10_25_000018_allocate_supply_payments', 15);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('customer_overdue','customer_upcoming','customer_balance','weaver_balance','stock_alert','other') NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` varchar(500) DEFAULT NULL,
  `ref_type` varchar(40) DEFAULT NULL,
  `ref_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'recipient; NULL = all authorized users',
  `due_date` date DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `operating_expenses`
--

CREATE TABLE `operating_expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `expense_no` varchar(40) NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `expense_category_id` int(10) UNSIGNED NOT NULL,
  `expense_on` date NOT NULL,
  `payee` varchar(150) NOT NULL,
  `description` varchar(500) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `invoice_no` varchar(80) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `operating_expenses`
--
DELIMITER $$
CREATE TRIGGER `operating_expenses_no_delete` BEFORE DELETE ON `operating_expenses` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `operating_expenses_no_update` BEFORE UPDATE ON `operating_expenses` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `operating_expense_payments`
--

CREATE TABLE `operating_expense_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `expense_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `operating_expense_payments`
--
DELIMITER $$
CREATE TRIGGER `operating_expense_payments_no_delete` BEFORE DELETE ON `operating_expense_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `operating_expense_payments_no_update` BEFORE UPDATE ON `operating_expense_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `partnership_contributions`
--

CREATE TABLE `partnership_contributions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `contributed_on` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `description` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `partnership_contributions`
--
DELIMITER $$
CREATE TRIGGER `partnership_contributions_no_delete` BEFORE DELETE ON `partnership_contributions` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `partnership_contributions_no_update` BEFORE UPDATE ON `partnership_contributions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `partnership_settlements`
--

CREATE TABLE `partnership_settlements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `settled_on` date NOT NULL,
  `revenue_base` decimal(18,2) NOT NULL,
  `company_cost_base` decimal(18,2) NOT NULL,
  `partner_cost_base` decimal(18,2) NOT NULL,
  `company_entitlement_base` decimal(18,2) NOT NULL,
  `partner_entitlement_base` decimal(18,2) NOT NULL,
  `partner_paid_base` decimal(18,2) NOT NULL,
  `balance_due_base` decimal(18,2) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `partnership_settlements`
--
DELIMITER $$
CREATE TRIGGER `partnership_settlements_no_delete` BEFORE DELETE ON `partnership_settlements` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `partnership_settlements_no_update` BEFORE UPDATE ON `partnership_settlements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `partnership_settlement_payments`
--

CREATE TABLE `partnership_settlement_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `settlement_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `amount_base` decimal(18,2) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `partnership_settlement_payments`
--
DELIMITER $$
CREATE TRIGGER `partnership_settlement_payments_no_delete` BEFORE DELETE ON `partnership_settlement_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `partnership_settlement_payments_no_update` BEFORE UPDATE ON `partnership_settlement_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_schedules`
--

CREATE TABLE `payment_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED NOT NULL,
  `installment_no` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `due_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','partially_paid','paid','overdue') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(80) NOT NULL,
  `module` varchar(40) NOT NULL,
  `action` varchar(30) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `code`, `module`, `action`, `description`) VALUES
(1, 'dashboard.view', 'dashboard', 'view', 'Allow view on dashboard'),
(2, 'stock.view', 'stock', 'view', 'Allow view on stock'),
(3, 'stock.create', 'stock', 'create', 'Allow create on stock'),
(4, 'stock.update', 'stock', 'update', 'Allow update on stock'),
(5, 'stock.export', 'stock', 'export', 'Allow export on stock'),
(6, 'orders.view', 'orders', 'view', 'Allow view on orders'),
(7, 'orders.create', 'orders', 'create', 'Allow create on orders'),
(8, 'orders.update', 'orders', 'update', 'Allow update on orders'),
(9, 'orders.delete', 'orders', 'delete', 'Allow delete on orders'),
(10, 'customers.view', 'customers', 'view', 'Allow view on customers'),
(11, 'customers.create', 'customers', 'create', 'Allow create on customers'),
(12, 'customers.update', 'customers', 'update', 'Allow update on customers'),
(13, 'customers.delete', 'customers', 'delete', 'Allow delete on customers'),
(14, 'customers.export', 'customers', 'export', 'Allow export on customers'),
(15, 'weavers.view', 'weavers', 'view', 'Allow view on weavers'),
(16, 'weavers.create', 'weavers', 'create', 'Allow create on weavers'),
(17, 'weavers.update', 'weavers', 'update', 'Allow update on weavers'),
(18, 'weavers.delete', 'weavers', 'delete', 'Allow delete on weavers'),
(19, 'shipments.view', 'shipments', 'view', 'Allow view on shipments'),
(20, 'shipments.update', 'shipments', 'update', 'Allow update on shipments'),
(21, 'exports.view', 'exports', 'view', 'Allow view on exports'),
(22, 'exports.create', 'exports', 'create', 'Allow create on exports'),
(23, 'warehouses.view', 'warehouses', 'view', 'Allow view on warehouses'),
(24, 'analytics.view', 'analytics', 'view', 'Allow view on analytics'),
(25, 'reports.view', 'reports', 'view', 'Allow view on reports'),
(26, 'reports.export', 'reports', 'export', 'Allow export on reports'),
(27, 'users.view', 'users', 'view', 'Allow view on users'),
(28, 'users.create', 'users', 'create', 'Allow create on users'),
(29, 'users.update', 'users', 'update', 'Allow update on users'),
(30, 'users.delete', 'users', 'delete', 'Allow delete on users'),
(31, 'settings.view', 'settings', 'view', 'Allow view on settings'),
(32, 'settings.update', 'settings', 'update', 'Allow update on settings'),
(33, 'branches.view', 'branches', 'view', 'Allow view on branches'),
(34, 'branches.create', 'branches', 'create', 'Allow create on branches'),
(35, 'branches.update', 'branches', 'update', 'Allow update on branches'),
(36, 'locations.view', 'locations', 'view', 'Allow view on locations'),
(37, 'locations.create', 'locations', 'create', 'Allow create on locations'),
(38, 'locations.update', 'locations', 'update', 'Allow update on locations'),
(39, 'currencies.view', 'currencies', 'view', 'Allow view on currencies'),
(40, 'currencies.create', 'currencies', 'create', 'Allow create on currencies'),
(41, 'currencies.update', 'currencies', 'update', 'Allow update on currencies'),
(42, 'employees.view', 'employees', 'view', 'Allow view on employees'),
(43, 'employees.create', 'employees', 'create', 'Allow create on employees'),
(44, 'employees.update', 'employees', 'update', 'Allow update on employees'),
(45, 'categories.view', 'categories', 'view', 'Allow view on categories'),
(46, 'categories.create', 'categories', 'create', 'Allow create on categories'),
(47, 'categories.update', 'categories', 'update', 'Allow update on categories'),
(48, 'facilities.view', 'facilities', 'view', 'Allow view on facilities'),
(49, 'facilities.create', 'facilities', 'create', 'Allow create on facilities'),
(50, 'facilities.update', 'facilities', 'update', 'Allow update on facilities'),
(51, 'accounts.view', 'accounts', 'view', 'Allow view on accounts'),
(52, 'accounts.create', 'accounts', 'create', 'Allow create on accounts'),
(53, 'accounts.update', 'accounts', 'update', 'Allow update on accounts'),
(54, 'ledger.view', 'ledger', 'view', 'Allow view on ledger'),
(55, 'ledger.create', 'ledger', 'create', 'Allow create on ledger'),
(56, 'ledger.reverse', 'ledger', 'reverse', 'Allow reverse on ledger'),
(57, 'audit.view', 'audit', 'view', 'Allow view on audit'),
(58, 'geography.view', 'geography', 'view', 'Allow view on geography'),
(59, 'geography.create', 'geography', 'create', 'Allow create on geography'),
(60, 'geography.update', 'geography', 'update', 'Allow update on geography'),
(61, 'geography.delete', 'geography', 'delete', 'Allow delete on geography'),
(62, 'suppliers.view', 'suppliers', 'view', 'Allow view on suppliers'),
(63, 'suppliers.create', 'suppliers', 'create', 'Allow create on suppliers'),
(64, 'suppliers.update', 'suppliers', 'update', 'Allow update on suppliers'),
(65, 'suppliers.delete', 'suppliers', 'delete', 'Allow delete on suppliers'),
(66, 'stock.receive', 'stock', 'receive', 'Allow receive on stock'),
(67, 'stock.transfer', 'stock', 'transfer', 'Allow transfer on stock'),
(68, 'weaver-purchases.view', 'weaver-purchases', 'view', 'Allow view on weaver-purchases'),
(69, 'weaver-purchases.create', 'weaver-purchases', 'create', 'Allow create on weaver-purchases'),
(70, 'weaver-payments.view', 'weaver-payments', 'view', 'Allow view on weaver-payments'),
(71, 'weaver-payments.create', 'weaver-payments', 'create', 'Allow create on weaver-payments'),
(72, 'weaver-payments.reverse', 'weaver-payments', 'reverse', 'Allow reverse on weaver-payments'),
(73, 'stock.adjust', 'stock', 'adjust', 'Allow adjust on stock'),
(74, 'weaver-purchases.draft', 'weaver-purchases', 'draft', 'Allow draft on weaver-purchases'),
(75, 'weaver-purchases.cancel', 'weaver-purchases', 'cancel', 'Allow cancel on weaver-purchases'),
(76, 'weaver-purchases.return', 'weaver-purchases', 'return', 'Allow return on weaver-purchases'),
(77, 'weaver-refunds.view', 'weaver-refunds', 'view', 'Allow view on weaver-refunds'),
(78, 'weaver-refunds.create', 'weaver-refunds', 'create', 'Allow create on weaver-refunds'),
(79, 'weaver-refunds.reverse', 'weaver-refunds', 'reverse', 'Allow reverse on weaver-refunds'),
(80, 'washing.view', 'washing', 'view', 'Allow view on washing'),
(81, 'washing.send', 'washing', 'send', 'Allow send on washing'),
(82, 'washing.complete', 'washing', 'complete', 'Allow complete on washing'),
(83, 'washing.return', 'washing', 'return', 'Allow return on washing'),
(84, 'washing.export', 'washing', 'export', 'Allow export on washing'),
(85, 'washing-costs.view', 'washing-costs', 'view', 'Allow view on washing-costs'),
(86, 'washing-costs.create', 'washing-costs', 'create', 'Allow create on washing-costs'),
(87, 'washing-costs.void', 'washing-costs', 'void', 'Allow void on washing-costs'),
(88, 'washing-costs.pay', 'washing-costs', 'pay', 'Allow pay on washing-costs'),
(89, 'washing-costs.reverse', 'washing-costs', 'reverse', 'Allow reverse on washing-costs'),
(90, 'purchasing.view', 'purchasing', 'view', 'Allow view on purchasing'),
(91, 'purchasing.create', 'purchasing', 'create', 'Allow create on purchasing'),
(92, 'purchasing.pay', 'purchasing', 'pay', 'Allow pay on purchasing'),
(93, 'purchasing.reverse', 'purchasing', 'reverse', 'Allow reverse on purchasing'),
(94, 'expenses.view', 'expenses', 'view', 'Allow view on expenses'),
(95, 'expenses.create', 'expenses', 'create', 'Allow create on expenses'),
(96, 'expenses.pay', 'expenses', 'pay', 'Allow pay on expenses'),
(97, 'expenses.void', 'expenses', 'void', 'Allow void on expenses'),
(98, 'expenses.reverse', 'expenses', 'reverse', 'Allow reverse on expenses'),
(99, 'production.view', 'production', 'view', 'Allow view on production'),
(100, 'production.create', 'production', 'create', 'Allow create on production'),
(101, 'production.manage', 'production', 'manage', 'Allow manage on production'),
(102, 'production.pay', 'production', 'pay', 'Allow pay on production'),
(103, 'production.receive', 'production', 'receive', 'Allow receive on production'),
(104, 'production.reverse', 'production', 'reverse', 'Allow reverse on production'),
(105, 'materials.view', 'materials', 'view', 'Allow view on materials'),
(106, 'materials.create', 'materials', 'create', 'Allow create on materials'),
(107, 'materials.receive', 'materials', 'receive', 'Allow receive on materials'),
(108, 'materials.move', 'materials', 'move', 'Allow move on materials'),
(109, 'materials.pay', 'materials', 'pay', 'Allow pay on materials'),
(110, 'materials.reverse', 'materials', 'reverse', 'Allow reverse on materials'),
(111, 'sales.view', 'sales', 'view', 'Allow view on sales'),
(112, 'sales.create', 'sales', 'create', 'Allow create on sales'),
(113, 'sales.collect', 'sales', 'collect', 'Allow collect on sales'),
(114, 'sales.reverse', 'sales', 'reverse', 'Allow reverse on sales'),
(115, 'partnerships.view', 'partnerships', 'view', 'Allow view on partnerships'),
(116, 'partnerships.create', 'partnerships', 'create', 'Allow create on partnerships'),
(117, 'partnerships.manage', 'partnerships', 'manage', 'Allow manage on partnerships'),
(118, 'partnerships.settle', 'partnerships', 'settle', 'Allow settle on partnerships'),
(119, 'partnerships.pay', 'partnerships', 'pay', 'Allow pay on partnerships'),
(120, 'partnerships.reverse', 'partnerships', 'reverse', 'Allow reverse on partnerships');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(80) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(75, 'App\\Models\\User', 1, 'web', 'b325ee104bfe211fa23d70bdc72280043c7409854de4508a22dc005d01adf5b0', '[\"*\"]', '2026-11-07 02:21:38', '2026-11-07 14:17:49', '2026-11-07 02:17:49', '2026-11-07 02:21:38'),
(76, 'App\\Models\\User', 1, 'web', 'b57e85dc2f639090e48e4a19cf965298b58153e2a47e068cd09478f9f0b66218', '[\"*\"]', '2026-11-07 03:56:29', '2026-11-07 14:23:09', '2026-11-07 02:23:09', '2026-11-07 03:56:29');

-- --------------------------------------------------------

--
-- Table structure for table `production_carpets`
--

CREATE TABLE `production_carpets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `cost_weight` decimal(12,3) NOT NULL
) ;

--
-- Triggers `production_carpets`
--
DELIMITER $$
CREATE TRIGGER `production_carpets_no_delete` BEFORE DELETE ON `production_carpets` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_carpets_no_update` BEFORE UPDATE ON `production_carpets` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_charges`
--

CREATE TABLE `production_charges` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `earned_on` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `description` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `production_charges`
--
DELIMITER $$
CREATE TRIGGER `production_charges_no_delete` BEFORE DELETE ON `production_charges` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_charges_no_update` BEFORE UPDATE ON `production_charges` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_managers`
--

CREATE TABLE `production_managers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `employee_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `production_managers`
--

INSERT INTO `production_managers` (`id`, `code`, `name`, `phone`, `address`, `branch_id`, `employee_id`, `status`, `created_by`, `created_at`) VALUES
(11, 'MAN261104-MGR', 'محمد اسماعیل ـ تمرین دستی', NULL, NULL, 17, NULL, 'active', 1, '2026-11-07 02:50:21');

-- --------------------------------------------------------

--
-- Table structure for table `production_orders`
--

CREATE TABLE `production_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_no` varchar(40) NOT NULL,
  `model` varchar(20) NOT NULL,
  `weaver_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `ordered_on` date NOT NULL,
  `due_on` date NOT NULL,
  `description` varchar(500) NOT NULL,
  `share_basis` varchar(20) DEFAULT NULL,
  `company_share` decimal(5,2) DEFAULT NULL,
  `company_loss_share` decimal(5,2) DEFAULT NULL,
  `agreement_terms` varchar(2000) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `production_orders`
--
DELIMITER $$
CREATE TRIGGER `production_orders_no_delete` BEFORE DELETE ON `production_orders` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_orders_no_update` BEFORE UPDATE ON `production_orders` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_payments`
--

CREATE TABLE `production_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `payment_type` varchar(12) NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `production_payments`
--
DELIMITER $$
CREATE TRIGGER `production_payments_no_delete` BEFORE DELETE ON `production_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_payments_no_update` BEFORE UPDATE ON `production_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_progress`
--

CREATE TABLE `production_progress` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `progress_on` date NOT NULL,
  `percent` tinyint(3) UNSIGNED NOT NULL,
  `notes` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `production_progress`
--
DELIMITER $$
CREATE TRIGGER `production_progress_no_delete` BEFORE DELETE ON `production_progress` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_progress_no_update` BEFORE UPDATE ON `production_progress` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_receipts`
--

CREATE TABLE `production_receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_order_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `received_on` date NOT NULL,
  `total_cost_base` decimal(18,2) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `production_receipts`
--
DELIMITER $$
CREATE TRIGGER `production_receipts_no_delete` BEFORE DELETE ON `production_receipts` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_receipts_no_update` BEFORE UPDATE ON `production_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_receipt_items`
--

CREATE TABLE `production_receipt_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `production_receipt_id` bigint(20) UNSIGNED NOT NULL,
  `production_carpet_id` bigint(20) UNSIGNED NOT NULL,
  `cost_base` decimal(18,2) NOT NULL
) ;

--
-- Triggers `production_receipt_items`
--
DELIMITER $$
CREATE TRIGGER `production_receipt_items_no_delete` BEFORE DELETE ON `production_receipt_items` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_receipt_items_no_update` BEFORE UPDATE ON `production_receipt_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_tools`
--

CREATE TABLE `production_tools` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `production_tools`
--

INSERT INTO `production_tools` (`id`, `code`, `name`, `status`, `created_by`, `created_at`) VALUES
(9, 'MAN261104-COMB', 'شانه قالین‌بافی ـ تمرین دستی', 'active', 1, '2026-11-07 02:45:36');

-- --------------------------------------------------------

--
-- Table structure for table `production_tool_lots`
--

CREATE TABLE `production_tool_lots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lot_no` varchar(40) NOT NULL,
  `tool_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `quantity` bigint(20) UNSIGNED NOT NULL,
  `unit_cost` decimal(18,2) NOT NULL,
  `total_cost` decimal(18,2) NOT NULL,
  `total_cost_base` decimal(18,2) NOT NULL,
  `received_on` date NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `production_tool_lots`
--

INSERT INTO `production_tool_lots` (`id`, `lot_no`, `tool_id`, `supplier_id`, `location_id`, `branch_id`, `currency_id`, `rate_to_base`, `quantity`, `unit_cost`, `total_cost`, `total_cost_base`, `received_on`, `created_by`, `created_at`) VALUES
(8, 'MTL-2026-00000001', 9, 12, 39, 17, 24, 1.00000000, 2, 750.00, 1500.00, 1500.00, '2026-11-07', 1, '2026-11-07 02:46:53');

--
-- Triggers `production_tool_lots`
--
DELIMITER $$
CREATE TRIGGER `production_tool_lots_no_delete` BEFORE DELETE ON `production_tool_lots` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_tool_lots_no_update` BEFORE UPDATE ON `production_tool_lots` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_tool_payments`
--

CREATE TABLE `production_tool_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lot_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `amount_in_payment_currency` decimal(18,2) DEFAULT NULL
) ;

--
-- Dumping data for table `production_tool_payments`
--

INSERT INTO `production_tool_payments` (`id`, `lot_id`, `account_transaction_id`, `document_amount`, `units_per_payment_unit`, `created_by`, `created_at`, `amount_in_payment_currency`) VALUES
(12, 8, 107, 1500.00, 1.00000000, 1, '2026-11-07 02:49:51', 1500.00);

--
-- Triggers `production_tool_payments`
--
DELIMITER $$
CREATE TRIGGER `production_tool_payments_no_delete` BEFORE DELETE ON `production_tool_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `production_tool_payments_no_update` BEFORE UPDATE ON `production_tool_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `production_workflow_defaults`
--

CREATE TABLE `production_workflow_defaults` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `materials_location_id` bigint(20) UNSIGNED NOT NULL,
  `finished_location_id` bigint(20) UNSIGNED NOT NULL,
  `facility_id` int(10) UNSIGNED NOT NULL,
  `facility_location_id` bigint(20) UNSIGNED NOT NULL,
  `payment_trigger` varchar(20) NOT NULL DEFAULT 'stock_acceptance',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ;

--
-- Dumping data for table `production_workflow_defaults`
--

INSERT INTO `production_workflow_defaults` (`id`, `branch_id`, `materials_location_id`, `finished_location_id`, `facility_id`, `facility_location_id`, `payment_trigger`, `created_by`, `created_at`, `updated_at`) VALUES
(8, 17, 39, 40, 15, 42, 'stock_acceptance', 1, '2026-11-07 02:51:38', '2026-11-07 02:51:38');

-- --------------------------------------------------------

--
-- Table structure for table `provinces`
--

CREATE TABLE `provinces` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_fa` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_no` varchar(30) NOT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'weaver-purchases live in carpet_purchases',
  `washing_facility_id` int(10) UNSIGNED DEFAULT NULL COMMENT '§11 facility-related purchases reportable separately',
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `purchase_date` date NOT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(14,2) GENERATED ALWAYS AS (`total_amount` - `paid_amount`) STORED,
  `payment_status` enum('unpaid','partial','paid') GENERATED ALWAYS AS (case when `paid_amount` <= 0 then 'unpaid' when `paid_amount` >= `total_amount` then 'paid' else 'partial' end) STORED,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK added after accounts',
  `responsible_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_drafts`
--

CREATE TABLE `purchase_drafts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_no` varchar(40) NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `weaver_id` bigint(20) UNSIGNED DEFAULT NULL,
  `currency_id` int(10) UNSIGNED DEFAULT NULL,
  `rate_to_base` decimal(20,8) DEFAULT NULL,
  `purchase_on` date DEFAULT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `state` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `posted_purchase_id` bigint(20) UNSIGNED DEFAULT NULL,
  `cancel_reason` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `updated_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ;

--
-- Triggers `purchase_drafts`
--
DELIMITER $$
CREATE TRIGGER `draft_no_delete` BEFORE DELETE ON `purchase_drafts` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cancel drafts instead of deleting them'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `draft_no_final_update` BEFORE UPDATE ON `purchase_drafts` FOR EACH ROW BEGIN IF OLD.state <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized drafts are immutable'; END IF; END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_draft_items`
--

CREATE TABLE `purchase_draft_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_draft_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `unit_price` decimal(18,2) DEFAULT NULL
) ;

--
-- Triggers `purchase_draft_items`
--
DELIMITER $$
CREATE TRIGGER `draft_item_delete` BEFORE DELETE ON `purchase_draft_items` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN BEGIN IF (SELECT state FROM purchase_drafts WHERE id=OLD.purchase_draft_id) <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized draft items are immutable'; END IF; END; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `draft_item_insert` BEFORE INSERT ON `purchase_draft_items` FOR EACH ROW BEGIN IF (SELECT state FROM purchase_drafts WHERE id=NEW.purchase_draft_id) <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized draft items are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `draft_item_update` BEFORE UPDATE ON `purchase_draft_items` FOR EACH ROW BEGIN IF (SELECT state FROM purchase_drafts WHERE id=OLD.purchase_draft_id) <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized draft items are immutable'; END IF; END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_id` bigint(20) UNSIGNED NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(14,2) NOT NULL,
  `subtotal` decimal(14,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_voids`
--

CREATE TABLE `purchase_voids` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `weaver_purchase_id` bigint(20) UNSIGNED NOT NULL,
  `voided_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `purchase_voids`
--
DELIMITER $$
CREATE TRIGGER `purchase_voids_no_delete` BEFORE DELETE ON `purchase_voids` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `purchase_voids_no_update` BEFORE UPDATE ON `purchase_voids` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Administrator', 'Full system access'),
(2, 'Branch Manager', 'Manages a branch and its operations'),
(3, 'Sales User', 'Records sales and customer payments'),
(4, 'Stock User', 'Manages stock and transfers'),
(5, 'Accountant', 'Manages accounts, expenses, financial reports'),
(6, 'Purchase User', 'Records purchases and weaver payments'),
(7, 'Washing Facility User', 'Manages washing orders'),
(8, 'DEMO - Workflow Operator', 'Training role for the two DEMO branches; no system administration.');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(10) UNSIGNED NOT NULL,
  `permission_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(1, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14),
(1, 15),
(1, 16),
(1, 17),
(1, 18),
(1, 19),
(1, 20),
(1, 21),
(1, 22),
(1, 23),
(1, 24),
(1, 25),
(1, 26),
(1, 27),
(1, 28),
(1, 29),
(1, 30),
(1, 31),
(1, 32),
(1, 33),
(1, 34),
(1, 35),
(1, 36),
(1, 37),
(1, 38),
(1, 39),
(1, 40),
(1, 41),
(1, 42),
(1, 43),
(1, 44),
(1, 45),
(1, 46),
(1, 47),
(1, 48),
(1, 49),
(1, 50),
(1, 51),
(1, 52),
(1, 53),
(1, 54),
(1, 55),
(1, 56),
(1, 57),
(1, 58),
(1, 59),
(1, 60),
(1, 61),
(1, 62),
(1, 63),
(1, 64),
(1, 65),
(1, 66),
(1, 67),
(1, 68),
(1, 69),
(1, 70),
(1, 71),
(1, 72),
(1, 73),
(1, 74),
(1, 75),
(1, 76),
(1, 77),
(1, 78),
(1, 79),
(1, 80),
(1, 81),
(1, 82),
(1, 83),
(1, 84),
(1, 85),
(1, 86),
(1, 87),
(1, 88),
(1, 89),
(1, 90),
(1, 91),
(1, 92),
(1, 93),
(1, 94),
(1, 95),
(1, 96),
(1, 97),
(1, 98),
(1, 99),
(1, 100),
(1, 101),
(1, 102),
(1, 103),
(1, 104),
(1, 105),
(1, 106),
(1, 107),
(1, 108),
(1, 109),
(1, 110),
(1, 111),
(1, 112),
(1, 113),
(1, 114),
(1, 115),
(1, 116),
(1, 117),
(1, 118),
(1, 119),
(1, 120),
(2, 1),
(2, 2),
(2, 3),
(2, 4),
(2, 5),
(2, 6),
(2, 7),
(2, 8),
(2, 9),
(2, 10),
(2, 11),
(2, 12),
(2, 13),
(2, 14),
(2, 15),
(2, 16),
(2, 17),
(2, 18),
(2, 19),
(2, 20),
(2, 21),
(2, 22),
(2, 23),
(2, 24),
(2, 25),
(2, 26),
(2, 27),
(2, 31),
(2, 33),
(2, 36),
(2, 37),
(2, 38),
(2, 39),
(2, 42),
(2, 43),
(2, 44),
(2, 45),
(2, 48),
(2, 51),
(2, 54),
(2, 57),
(2, 58),
(2, 62),
(2, 66),
(2, 67),
(2, 68),
(2, 69),
(2, 70),
(2, 73),
(2, 74),
(2, 75),
(2, 76),
(2, 77),
(2, 80),
(2, 81),
(2, 82),
(2, 83),
(2, 84),
(2, 85),
(2, 90),
(2, 91),
(2, 94),
(2, 95),
(2, 99),
(2, 100),
(2, 101),
(2, 103),
(2, 105),
(2, 106),
(2, 107),
(2, 108),
(2, 111),
(2, 112),
(2, 115),
(2, 116),
(2, 117),
(3, 1),
(3, 2),
(3, 6),
(3, 7),
(3, 8),
(3, 10),
(3, 11),
(3, 12),
(3, 14),
(3, 19),
(3, 21),
(3, 23),
(3, 24),
(3, 25),
(3, 33),
(3, 36),
(3, 39),
(3, 45),
(3, 80),
(3, 111),
(3, 112),
(3, 113),
(4, 1),
(4, 2),
(4, 3),
(4, 4),
(4, 5),
(4, 6),
(4, 10),
(4, 19),
(4, 20),
(4, 23),
(4, 25),
(4, 33),
(4, 36),
(4, 37),
(4, 38),
(4, 39),
(4, 45),
(4, 66),
(4, 67),
(4, 73),
(4, 80),
(4, 81),
(4, 83),
(4, 84),
(4, 99),
(4, 103),
(4, 105),
(4, 107),
(4, 108),
(5, 1),
(5, 6),
(5, 10),
(5, 24),
(5, 25),
(5, 26),
(5, 31),
(5, 33),
(5, 39),
(5, 51),
(5, 52),
(5, 53),
(5, 54),
(5, 55),
(5, 56),
(5, 68),
(5, 70),
(5, 71),
(5, 72),
(5, 77),
(5, 78),
(5, 79),
(5, 80),
(5, 85),
(5, 86),
(5, 87),
(5, 88),
(5, 89),
(5, 90),
(5, 92),
(5, 93),
(5, 94),
(5, 95),
(5, 96),
(5, 97),
(5, 98),
(5, 99),
(5, 102),
(5, 104),
(5, 105),
(5, 109),
(5, 110),
(5, 111),
(5, 113),
(5, 114),
(5, 115),
(5, 118),
(5, 119),
(5, 120),
(6, 1),
(6, 2),
(6, 3),
(6, 4),
(6, 6),
(6, 15),
(6, 16),
(6, 17),
(6, 18),
(6, 25),
(6, 33),
(6, 36),
(6, 39),
(6, 45),
(6, 58),
(6, 62),
(6, 63),
(6, 64),
(6, 65),
(6, 68),
(6, 69),
(6, 74),
(6, 75),
(6, 76),
(6, 80),
(6, 81),
(6, 90),
(6, 91),
(6, 99),
(6, 100),
(6, 101),
(6, 105),
(6, 106),
(6, 107),
(6, 108),
(7, 1),
(7, 6),
(7, 19),
(7, 23),
(7, 33),
(7, 36),
(7, 39),
(7, 48),
(7, 80),
(7, 82),
(8, 1),
(8, 2),
(8, 3),
(8, 4),
(8, 5),
(8, 6),
(8, 7),
(8, 8),
(8, 9),
(8, 10),
(8, 11),
(8, 12),
(8, 13),
(8, 14),
(8, 15),
(8, 16),
(8, 17),
(8, 18),
(8, 19),
(8, 20),
(8, 21),
(8, 22),
(8, 23),
(8, 24),
(8, 25),
(8, 26),
(8, 33),
(8, 36),
(8, 37),
(8, 38),
(8, 39),
(8, 42),
(8, 43),
(8, 44),
(8, 45),
(8, 48),
(8, 51),
(8, 52),
(8, 53),
(8, 54),
(8, 55),
(8, 56),
(8, 57),
(8, 58),
(8, 62),
(8, 66),
(8, 67),
(8, 68),
(8, 69),
(8, 70),
(8, 71),
(8, 72),
(8, 73),
(8, 74),
(8, 75),
(8, 76),
(8, 77),
(8, 78),
(8, 79);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_no` varchar(30) NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `salesperson_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'users.id of the seller',
  `currency_id` int(10) UNSIGNED NOT NULL,
  `sale_date` date NOT NULL,
  `subtotal_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(14,2) GENERATED ALWAYS AS (`final_amount` - `paid_amount`) STORED,
  `payment_type` enum('full','partial','installment') NOT NULL DEFAULT 'full',
  `payment_status` enum('unpaid','partial','paid') GENERATED ALWAYS AS (case when `paid_amount` <= 0 then 'unpaid' when `paid_amount` >= `final_amount` then 'paid' else 'partial' end) STORED,
  `notes` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `subtotal` decimal(14,2) GENERATED ALWAYS AS (`unit_price` * 1) STORED COMMENT 'one carpet per line; qty conceptually 1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `key` varchar(80) NOT NULL,
  `value` varchar(255) NOT NULL,
  `type` enum('string','number','boolean','json') NOT NULL DEFAULT 'string',
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_adjustments`
--

CREATE TABLE `stock_adjustments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `adjustment_no` varchar(40) NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `adjustment_type` enum('relocate','missing','found') NOT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `adjusted_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `stock_adjustments`
--
DELIMITER $$
CREATE TRIGGER `stock_adjustments_no_delete` BEFORE DELETE ON `stock_adjustments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `stock_adjustments_no_update` BEFORE UPDATE ON `stock_adjustments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_events`
--

CREATE TABLE `stock_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `sequence_no` int(10) UNSIGNED NOT NULL,
  `event_type` enum('purchase','receipt','transfer','purchase_void','adjustment','weaver_return','washing_dispatch','washing_complete','washing_return','production_receive','sale','production_complete') NOT NULL,
  `resulting_status` enum('purchased','in_stock','registered','missing','returned','at_washing','washing_completed','sold','production_completed') NOT NULL,
  `resulting_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `weaver_purchase_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `stock_receipt_id` bigint(20) UNSIGNED DEFAULT NULL,
  `stock_transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `occurred_on` date NOT NULL,
  `handled_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `purchase_void_id` bigint(20) UNSIGNED DEFAULT NULL,
  `stock_adjustment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `weaver_return_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `washing_order_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `production_receipt_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `trade_sale_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `manager_delivery_item_id` bigint(20) UNSIGNED DEFAULT NULL
) ;

--
-- Dumping data for table `stock_events`
--

INSERT INTO `stock_events` (`id`, `carpet_id`, `sequence_no`, `event_type`, `resulting_status`, `resulting_location_id`, `weaver_purchase_item_id`, `stock_receipt_id`, `stock_transfer_id`, `occurred_on`, `handled_by`, `created_at`, `purchase_void_id`, `stock_adjustment_id`, `weaver_return_item_id`, `washing_order_item_id`, `production_receipt_item_id`, `trade_sale_item_id`, `manager_delivery_item_id`) VALUES
(89, 28, 1, 'production_complete', 'production_completed', NULL, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:27:21', NULL, NULL, NULL, NULL, NULL, NULL, 14),
(90, 28, 2, 'washing_dispatch', 'at_washing', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:27:21', NULL, NULL, NULL, 14, NULL, NULL, NULL),
(91, 28, 3, 'washing_complete', 'washing_completed', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:27:52', NULL, NULL, NULL, 14, NULL, NULL, NULL),
(92, 28, 4, 'washing_return', 'in_stock', 40, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:27:52', NULL, NULL, NULL, 14, NULL, NULL, NULL),
(93, 29, 1, 'production_complete', 'production_completed', NULL, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:32:42', NULL, NULL, NULL, NULL, NULL, NULL, 15),
(94, 29, 2, 'washing_dispatch', 'at_washing', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:32:42', NULL, NULL, NULL, 15, NULL, NULL, NULL),
(95, 29, 3, 'washing_complete', 'washing_completed', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:33:01', NULL, NULL, NULL, 15, NULL, NULL, NULL),
(96, 29, 4, 'washing_return', 'in_stock', 40, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:33:01', NULL, NULL, NULL, 15, NULL, NULL, NULL),
(97, 30, 1, 'production_complete', 'production_completed', NULL, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:43:59', NULL, NULL, NULL, NULL, NULL, NULL, 16),
(98, 30, 2, 'washing_dispatch', 'at_washing', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:43:59', NULL, NULL, NULL, 16, NULL, NULL, NULL),
(99, 30, 3, 'washing_complete', 'washing_completed', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:44:16', NULL, NULL, NULL, 16, NULL, NULL, NULL),
(100, 30, 4, 'washing_return', 'in_stock', 40, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:44:16', NULL, NULL, NULL, 16, NULL, NULL, NULL),
(101, 31, 1, 'production_complete', 'production_completed', NULL, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:47:59', NULL, NULL, NULL, NULL, NULL, NULL, 17),
(102, 31, 2, 'washing_dispatch', 'at_washing', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:47:59', NULL, NULL, NULL, 17, NULL, NULL, NULL),
(103, 31, 3, 'washing_complete', 'washing_completed', 42, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:48:39', NULL, NULL, NULL, 17, NULL, NULL, NULL),
(104, 31, 4, 'washing_return', 'in_stock', 40, NULL, NULL, NULL, '2026-11-07', 1, '2026-11-07 03:48:39', NULL, NULL, NULL, 17, NULL, NULL, NULL);

--
-- Triggers `stock_events`
--
DELIMITER $$
CREATE TRIGGER `stock_events_no_delete` BEFORE DELETE ON `stock_events` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `stock_events_no_update` BEFORE UPDATE ON `stock_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_locations`
--

CREATE TABLE `stock_locations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(120) NOT NULL,
  `branch_id` int(10) UNSIGNED DEFAULT NULL,
  `washing_facility_id` int(10) UNSIGNED DEFAULT NULL,
  `location_type` enum('showroom','warehouse','shelf','washing','other') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ;

--
-- Dumping data for table `stock_locations`
--

INSERT INTO `stock_locations` (`id`, `code`, `name`, `branch_id`, `washing_facility_id`, `location_type`, `status`, `created_at`, `updated_at`) VALUES
(39, 'MAN261104-RAW', 'گدام مواد اولیه هرات ـ تمرین دستی', 17, NULL, 'warehouse', 'active', NULL, '2026-11-07 02:23:49'),
(40, 'MAN261104-FIN', 'گدام قالین آماده هرات ـ تمرین دستی', 17, NULL, 'warehouse', 'active', NULL, '2026-11-07 02:24:20'),
(41, 'MAN261104-SHOW', 'نمایشگاه هرات ـ تمرین دستی', 17, NULL, 'showroom', 'active', NULL, '2026-11-07 02:24:43'),
(42, 'MAN261104-WASH', 'محوطه شستشوی بهار ـ تمرین دستی', NULL, 15, 'washing', 'active', NULL, '2026-11-07 02:37:20');

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `movement_type` enum('received','wash_sent','wash_returned','sold','transferred','adjustment') NOT NULL,
  `from_branch_id` int(10) UNSIGNED DEFAULT NULL,
  `to_branch_id` int(10) UNSIGNED DEFAULT NULL,
  `reference_type` enum('carpet_purchase','washing_order','sale','transfer','adjustment') NOT NULL,
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `moved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `handled_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'which user handled it (§5)',
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_receipts`
--

CREATE TABLE `stock_receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `receipt_no` varchar(40) NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `received_on` date NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `stock_receipts`
--
DELIMITER $$
CREATE TRIGGER `stock_receipts_no_delete` BEFORE DELETE ON `stock_receipts` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `stock_receipts_no_update` BEFORE UPDATE ON `stock_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfers`
--

CREATE TABLE `stock_transfers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `transfer_no` varchar(40) NOT NULL,
  `from_location_id` bigint(20) UNSIGNED NOT NULL,
  `to_location_id` bigint(20) UNSIGNED NOT NULL,
  `transferred_on` date NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `stock_transfers`
--
DELIMITER $$
CREATE TRIGGER `stock_transfers_no_delete` BEFORE DELETE ON `stock_transfers` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `stock_transfers_no_update` BEFORE UPDATE ON `stock_transfers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `phone`, `address`, `notes`, `status`, `deleted_at`, `created_at`, `updated_at`, `created_by`) VALUES
(12, 'کریم', '+9378964545', 'کاب افغانستان', 'کابل', 'active', NULL, '2026-11-07 02:37:58', '2026-11-07 02:37:58', 1);

-- --------------------------------------------------------

--
-- Table structure for table `supplier_carpet_payments`
--

CREATE TABLE `supplier_carpet_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_no` varchar(40) NOT NULL,
  `purchase_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `supplier_carpet_payments`
--
DELIMITER $$
CREATE TRIGGER `supplier_carpet_payments_no_delete` BEFORE DELETE ON `supplier_carpet_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `supplier_carpet_payments_no_update` BEFORE UPDATE ON `supplier_carpet_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `supply_purchases`
--

CREATE TABLE `supply_purchases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_no` varchar(40) NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `purchased_on` date NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `supply_purchases`
--

INSERT INTO `supply_purchases` (`id`, `purchase_no`, `branch_id`, `location_id`, `supplier_id`, `currency_id`, `rate_to_base`, `purchased_on`, `created_by`, `created_at`) VALUES
(9, 'SUP-2026-00000001', 17, 39, 12, 24, 1.00000000, '2026-11-07', 1, '2026-11-07 02:46:53');

--
-- Triggers `supply_purchases`
--
DELIMITER $$
CREATE TRIGGER `supply_purchases_no_delete` BEFORE DELETE ON `supply_purchases` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supply purchase facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `supply_purchases_no_update` BEFORE UPDATE ON `supply_purchases` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supply purchase facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `supply_purchase_lines`
--

CREATE TABLE `supply_purchase_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_id` bigint(20) UNSIGNED NOT NULL,
  `material_lot_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tool_lot_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `supply_purchase_lines`
--

INSERT INTO `supply_purchase_lines` (`id`, `purchase_id`, `material_lot_id`, `tool_lot_id`, `created_by`, `created_at`) VALUES
(17, 9, NULL, 8, 1, '2026-11-07 02:46:53'),
(18, 9, 11, NULL, 1, '2026-11-07 02:46:53');

--
-- Triggers `supply_purchase_lines`
--
DELIMITER $$
CREATE TRIGGER `supply_purchase_lines_no_delete` BEFORE DELETE ON `supply_purchase_lines` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supply purchase facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `supply_purchase_lines_no_update` BEFORE UPDATE ON `supply_purchase_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Supply purchase facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `trade_customer_receipts`
--

CREATE TABLE `trade_customer_receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `trade_customer_receipts`
--
DELIMITER $$
CREATE TRIGGER `trade_customer_receipts_no_delete` BEFORE DELETE ON `trade_customer_receipts` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trade_customer_receipts_no_update` BEFORE UPDATE ON `trade_customer_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `trade_sales`
--

CREATE TABLE `trade_sales` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_no` varchar(40) NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `sold_on` date NOT NULL,
  `due_on` date NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `trade_sales`
--
DELIMITER $$
CREATE TRIGGER `trade_sales_no_delete` BEFORE DELETE ON `trade_sales` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trade_sales_no_update` BEFORE UPDATE ON `trade_sales` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `trade_sale_items`
--

CREATE TABLE `trade_sale_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sale_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `unit_price` decimal(18,2) NOT NULL,
  `discount` decimal(18,2) NOT NULL,
  `cost_base` decimal(18,2) NOT NULL
) ;

--
-- Triggers `trade_sale_items`
--
DELIMITER $$
CREATE TRIGGER `trade_sale_items_no_delete` BEFORE DELETE ON `trade_sale_items` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trade_sale_items_no_update` BEFORE UPDATE ON `trade_sale_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Business facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `employee_id` bigint(20) UNSIGNED DEFAULT NULL,
  `locale` enum('en','fa') NOT NULL DEFAULT 'en',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password_hash`, `employee_id`, `locale`, `is_active`, `last_login_at`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'admin', 'admin@csms.local', '$2y$12$QQGaMwgh6OBUcYU/8pkQ6O12Q.xUixvXflZc73PeN506rZNyubSWy', NULL, 'fa', 1, '2026-11-07 02:23:09', NULL, '2026-10-05 03:08:13', '2026-11-07 02:23:09'),
(2, 'Sales Demo User', 'sales', NULL, '$2y$12$cGhAob.1jLD8ecyZevqimu1r27QRquNa6WsrmvbjcbfaGIeJJ864C', NULL, 'en', 1, '2026-10-05 06:52:27', NULL, '2026-10-05 05:38:51', '2026-10-05 06:52:27'),
(3, 'DEMO Workflow Operator', 'demo_workflow_261019', NULL, '$2y$12$ou/fuOYEmXqFBocwEcmApe5Kiu9vGssIzbSlPn7KfU.CfHiemgXpK', NULL, 'en', 1, '2026-10-20 00:47:51', NULL, '2026-10-19 10:51:39', '2026-10-20 00:47:51');

-- --------------------------------------------------------

--
-- Table structure for table `user_accounts`
--

CREATE TABLE `user_accounts` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_branches`
--

CREATE TABLE `user_branches` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 1),
(2, 3),
(3, 8);

-- --------------------------------------------------------

--
-- Table structure for table `user_washing_facilities`
--

CREATE TABLE `user_washing_facilities` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `washing_facility_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_account_balances`
-- (See below for the actual view)
--
CREATE TABLE `v_account_balances` (
`account_id` bigint(20) unsigned
,`currency_id` int(10) unsigned
,`balance` decimal(40,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_current_stock`
-- (See below for the actual view)
--
CREATE TABLE `v_current_stock` (
`id` bigint(20) unsigned
,`carpet_code` varchar(40)
,`name` varchar(150)
,`registration_branch_id` int(10) unsigned
,`category_id` int(10) unsigned
,`size_label` varchar(60)
,`length_cm` decimal(10,2)
,`width_cm` decimal(10,2)
,`color` varchar(60)
,`material` varchar(100)
,`notes` varchar(1000)
,`version` int(10) unsigned
,`created_by` bigint(20) unsigned
,`updated_by` bigint(20) unsigned
,`created_at` timestamp
,`updated_at` timestamp
,`deleted_at` timestamp
,`sequence_no` decimal(10,0)
,`stock_status` varchar(20)
,`location_id` bigint(20) unsigned
,`custody_branch_id` int(10) unsigned
,`occurred_on` date
,`acquisition_item_id` bigint(20) unsigned
,`production_order_id` bigint(20) unsigned
,`business_model` varchar(20)
,`manager_production_order_id` bigint(20) unsigned
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_washing_bill_balances`
-- (See below for the actual view)
--
CREATE TABLE `v_washing_bill_balances` (
`bill_id` bigint(20) unsigned
,`total_amount` decimal(18,2)
,`state` varchar(6)
,`paid_amount` decimal(40,2)
,`remaining_amount` decimal(41,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_weaver_purchase_balances`
-- (See below for the actual view)
--
CREATE TABLE `v_weaver_purchase_balances` (
`purchase_id` bigint(20) unsigned
,`total_amount` decimal(40,2)
,`cancelled_amount` decimal(40,2)
,`returned_amount` decimal(40,2)
,`net_amount` decimal(41,2)
,`paid_amount` decimal(41,2)
,`refunded_amount` decimal(40,2)
,`remaining_amount` decimal(44,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `washing_bills`
--

CREATE TABLE `washing_bills` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `expense_no` varchar(40) NOT NULL,
  `invoice_no` varchar(80) NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `facility_id` int(10) UNSIGNED NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `billed_on` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `amount_base` decimal(18,2) NOT NULL,
  `cost_classification` varchar(20) NOT NULL DEFAULT 'inventory',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `washing_bills`
--

INSERT INTO `washing_bills` (`id`, `expense_no`, `invoice_no`, `branch_id`, `facility_id`, `currency_id`, `rate_to_base`, `billed_on`, `amount`, `amount_base`, `cost_classification`, `notes`, `created_by`, `created_at`) VALUES
(13, 'WBILL-2026-00000001', 'WBILL-2026-00000001', 17, 15, 24, 1.00000000, '2026-11-07', 500.00, 500.00, 'inventory', NULL, 1, '2026-11-07 03:27:52'),
(14, 'WBILL-2026-00000002', 'WBILL-2026-00000002', 17, 15, 24, 1.00000000, '2026-11-07', 500.00, 500.00, 'inventory', NULL, 1, '2026-11-07 03:33:01'),
(15, 'WBILL-2026-00000003', 'WBILL-2026-00000003', 17, 15, 24, 1.00000000, '2026-11-07', 500.00, 500.00, 'inventory', NULL, 1, '2026-11-07 03:44:16'),
(16, 'WBILL-2026-00000004', 'WBILL-2026-00000004', 17, 15, 24, 1.00000000, '2026-11-07', 500.00, 500.00, 'inventory', NULL, 1, '2026-11-07 03:48:39');

--
-- Triggers `washing_bills`
--
DELIMITER $$
CREATE TRIGGER `washing_bills_no_delete` BEFORE DELETE ON `washing_bills` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `washing_bills_no_update` BEFORE UPDATE ON `washing_bills` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `washing_bill_allocations`
--

CREATE TABLE `washing_bill_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `washing_bill_id` bigint(20) UNSIGNED NOT NULL,
  `washing_order_item_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `amount_base` decimal(18,2) NOT NULL
) ;

--
-- Dumping data for table `washing_bill_allocations`
--

INSERT INTO `washing_bill_allocations` (`id`, `washing_bill_id`, `washing_order_item_id`, `amount`, `amount_base`) VALUES
(13, 13, 14, 500.00, 500.00),
(14, 14, 15, 500.00, 500.00),
(15, 15, 16, 500.00, 500.00),
(16, 16, 17, 500.00, 500.00);

--
-- Triggers `washing_bill_allocations`
--
DELIMITER $$
CREATE TRIGGER `washing_bill_allocations_no_delete` BEFORE DELETE ON `washing_bill_allocations` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `washing_bill_allocations_no_update` BEFORE UPDATE ON `washing_bill_allocations` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `washing_bill_voids`
--

CREATE TABLE `washing_bill_voids` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `washing_bill_id` bigint(20) UNSIGNED NOT NULL,
  `voided_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `washing_bill_voids`
--
DELIMITER $$
CREATE TRIGGER `washing_bill_voids_no_delete` BEFORE DELETE ON `washing_bill_voids` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `washing_bill_voids_no_update` BEFORE UPDATE ON `washing_bill_voids` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `washing_facilities`
--

CREATE TABLE `washing_facilities` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `contact_name` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `washing_facilities`
--

INSERT INTO `washing_facilities` (`id`, `name`, `location`, `contact_name`, `phone`, `status`, `deleted_at`, `created_at`, `updated_at`) VALUES
(15, 'مرکز ششتشو بهار', 'کابل', 'کریم', '+9378967898', 'active', NULL, '2026-11-07 07:03:41', '2026-11-07 02:33:41');

-- --------------------------------------------------------

--
-- Table structure for table `washing_orders`
--

CREATE TABLE `washing_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_no` varchar(40) NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `facility_id` int(10) UNSIGNED NOT NULL,
  `facility_location_id` bigint(20) UNSIGNED NOT NULL,
  `sent_on` date NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `washing_orders`
--

INSERT INTO `washing_orders` (`id`, `order_no`, `branch_id`, `facility_id`, `facility_location_id`, `sent_on`, `notes`, `created_by`, `created_at`) VALUES
(14, 'MWASH-2026-00000001', 17, 15, 42, '2026-11-07', NULL, 1, '2026-11-07 03:27:21'),
(15, 'MWASH-2026-00000002', 17, 15, 42, '2026-11-07', NULL, 1, '2026-11-07 03:32:42'),
(16, 'MWASH-2026-00000003', 17, 15, 42, '2026-11-07', NULL, 1, '2026-11-07 03:43:59'),
(17, 'MWASH-2026-00000004', 17, 15, 42, '2026-11-07', NULL, 1, '2026-11-07 03:47:59');

--
-- Triggers `washing_orders`
--
DELIMITER $$
CREATE TRIGGER `washing_orders_no_delete` BEFORE DELETE ON `washing_orders` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `washing_orders_no_update` BEFORE UPDATE ON `washing_orders` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `washing_order_items`
--

CREATE TABLE `washing_order_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `washing_order_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `acquisition_item_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_location_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `washing_order_items`
--

INSERT INTO `washing_order_items` (`id`, `washing_order_id`, `carpet_id`, `acquisition_item_id`, `source_location_id`) VALUES
(14, 14, 28, NULL, NULL),
(15, 15, 29, NULL, NULL),
(16, 16, 30, NULL, NULL),
(17, 17, 31, NULL, NULL);

--
-- Triggers `washing_order_items`
--
DELIMITER $$
CREATE TRIGGER `washing_order_items_no_delete` BEFORE DELETE ON `washing_order_items` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `washing_order_items_no_update` BEFORE UPDATE ON `washing_order_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `washing_payments`
--

CREATE TABLE `washing_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_no` varchar(40) NOT NULL,
  `washing_bill_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `amount_in_bill_currency` decimal(18,2) NOT NULL,
  `bill_units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `washing_payments`
--

INSERT INTO `washing_payments` (`id`, `payment_no`, `washing_bill_id`, `account_transaction_id`, `amount_in_bill_currency`, `bill_units_per_payment_unit`, `created_by`, `created_at`) VALUES
(13, 'WMPAY-2026-00000001', 13, 108, 500.00, 1.00000000, 1, '2026-11-07 03:27:52'),
(14, 'WMPAY-2026-00000002', 14, 110, 500.00, 1.00000000, 1, '2026-11-07 03:33:01'),
(15, 'WMPAY-2026-00000003', 15, 112, 500.00, 1.00000000, 1, '2026-11-07 03:44:16'),
(16, 'WMPAY-2026-00000004', 16, 115, 500.00, 1.00000000, 1, '2026-11-07 03:48:39');

--
-- Triggers `washing_payments`
--
DELIMITER $$
CREATE TRIGGER `washing_payments_no_delete` BEFORE DELETE ON `washing_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `washing_payments_no_update` BEFORE UPDATE ON `washing_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Washing facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `weavers`
--

CREATE TABLE `weavers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `area_id` int(10) UNSIGNED DEFAULT NULL,
  `manager_employee_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'assigned manager (employees)',
  `registration_date` date NOT NULL DEFAULT curdate(),
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `weaver_payments`
--

CREATE TABLE `weaver_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_no` varchar(40) NOT NULL,
  `weaver_id` bigint(20) UNSIGNED NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `payment_type` enum('purchase','additional') NOT NULL,
  `expense_category_id` int(10) UNSIGNED DEFAULT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `weaver_payments`
--
DELIMITER $$
CREATE TRIGGER `weaver_payments_no_delete` BEFORE DELETE ON `weaver_payments` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `weaver_payments_no_update` BEFORE UPDATE ON `weaver_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `weaver_payment_allocations`
--

CREATE TABLE `weaver_payment_allocations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `weaver_payment_id` bigint(20) UNSIGNED NOT NULL,
  `weaver_purchase_id` bigint(20) UNSIGNED NOT NULL,
  `amount_in_payment_currency` decimal(18,2) NOT NULL,
  `purchase_units_per_payment_unit` decimal(20,8) NOT NULL,
  `amount_in_purchase_currency` decimal(18,2) NOT NULL
) ;

--
-- Triggers `weaver_payment_allocations`
--
DELIMITER $$
CREATE TRIGGER `weaver_payment_allocations_no_delete` BEFORE DELETE ON `weaver_payment_allocations` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `weaver_payment_allocations_no_update` BEFORE UPDATE ON `weaver_payment_allocations` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `weaver_purchases`
--

CREATE TABLE `weaver_purchases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_no` varchar(40) NOT NULL,
  `weaver_id` bigint(20) UNSIGNED DEFAULT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `purchase_on` date NOT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `seller_invoice` varchar(80) DEFAULT NULL
) ;

--
-- Triggers `weaver_purchases`
--
DELIMITER $$
CREATE TRIGGER `weaver_purchases_no_delete` BEFORE DELETE ON `weaver_purchases` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `weaver_purchases_no_update` BEFORE UPDATE ON `weaver_purchases` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `weaver_purchase_items`
--

CREATE TABLE `weaver_purchase_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `weaver_purchase_id` bigint(20) UNSIGNED NOT NULL,
  `carpet_id` bigint(20) UNSIGNED NOT NULL,
  `unit_price` decimal(18,2) NOT NULL
) ;

--
-- Triggers `weaver_purchase_items`
--
DELIMITER $$
CREATE TRIGGER `weaver_purchase_items_no_delete` BEFORE DELETE ON `weaver_purchase_items` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `weaver_purchase_items_no_update` BEFORE UPDATE ON `weaver_purchase_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted inventory facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `weaver_refunds`
--

CREATE TABLE `weaver_refunds` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `refund_no` varchar(40) NOT NULL,
  `weaver_purchase_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `amount_in_purchase_currency` decimal(18,2) NOT NULL,
  `purchase_units_per_refund_unit` decimal(20,8) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `weaver_refunds`
--
DELIMITER $$
CREATE TRIGGER `weaver_refunds_no_delete` BEFORE DELETE ON `weaver_refunds` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `weaver_refunds_no_update` BEFORE UPDATE ON `weaver_refunds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `weaver_returns`
--

CREATE TABLE `weaver_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `return_no` varchar(40) NOT NULL,
  `weaver_purchase_id` bigint(20) UNSIGNED NOT NULL,
  `from_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `returned_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `weaver_returns`
--
DELIMITER $$
CREATE TRIGGER `weaver_returns_no_delete` BEFORE DELETE ON `weaver_returns` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `weaver_returns_no_update` BEFORE UPDATE ON `weaver_returns` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `weaver_return_items`
--

CREATE TABLE `weaver_return_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `weaver_return_id` bigint(20) UNSIGNED NOT NULL,
  `weaver_purchase_item_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `weaver_return_items`
--
DELIMITER $$
CREATE TRIGGER `weaver_return_items_no_delete` BEFORE DELETE ON `weaver_return_items` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `weaver_return_items_no_update` BEFORE UPDATE ON `weaver_return_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `worker_assistance`
--

CREATE TABLE `worker_assistance` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `assistance_no` varchar(40) NOT NULL,
  `branch_id` int(10) UNSIGNED NOT NULL,
  `manager_id` bigint(20) UNSIGNED NOT NULL,
  `beneficiary_name` varchar(150) NOT NULL,
  `beneficiary_phone` varchar(30) DEFAULT NULL,
  `assistance_type` varchar(12) NOT NULL,
  `responsible_party` varchar(12) DEFAULT NULL,
  `recovery_terms` varchar(1000) DEFAULT NULL,
  `currency_id` int(10) UNSIGNED NOT NULL,
  `rate_to_base` decimal(20,8) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `assistance_on` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `worker_assistance`
--
DELIMITER $$
CREATE TRIGGER `worker_assistance_no_delete` BEFORE DELETE ON `worker_assistance` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `worker_assistance_no_update` BEFORE UPDATE ON `worker_assistance` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `worker_assistance_deliveries`
--

CREATE TABLE `worker_assistance_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `assistance_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `delivered_on` date NOT NULL,
  `acknowledgement` varchar(500) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `worker_assistance_deliveries`
--
DELIMITER $$
CREATE TRIGGER `worker_assistance_deliveries_no_delete` BEFORE DELETE ON `worker_assistance_deliveries` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `worker_assistance_deliveries_no_update` BEFORE UPDATE ON `worker_assistance_deliveries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `worker_assistance_fundings`
--

CREATE TABLE `worker_assistance_fundings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `assistance_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `worker_assistance_fundings`
--
DELIMITER $$
CREATE TRIGGER `worker_assistance_fundings_no_delete` BEFORE DELETE ON `worker_assistance_fundings` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `worker_assistance_fundings_no_update` BEFORE UPDATE ON `worker_assistance_fundings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `worker_assistance_recoveries`
--

CREATE TABLE `worker_assistance_recoveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `assistance_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `worker_assistance_recoveries`
--
DELIMITER $$
CREATE TRIGGER `worker_assistance_recoveries_no_delete` BEFORE DELETE ON `worker_assistance_recoveries` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `worker_assistance_recoveries_no_update` BEFORE UPDATE ON `worker_assistance_recoveries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `worker_assistance_returns`
--

CREATE TABLE `worker_assistance_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `assistance_id` bigint(20) UNSIGNED NOT NULL,
  `account_transaction_id` bigint(20) UNSIGNED NOT NULL,
  `document_amount` decimal(18,2) NOT NULL,
  `units_per_payment_unit` decimal(20,8) NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Triggers `worker_assistance_returns`
--
DELIMITER $$
CREATE TRIGGER `worker_assistance_returns_no_delete` BEFORE DELETE ON `worker_assistance_returns` FOR EACH ROW BEGIN IF COALESCE(@csms_system_reset,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'; END IF; END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `worker_assistance_returns_no_update` BEFORE UPDATE ON `worker_assistance_returns` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Manager resource facts are immutable'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure for view `v_account_balances`
--
DROP TABLE IF EXISTS `v_account_balances`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_account_balances`  AS SELECT `a`.`id` AS `account_id`, `a`.`currency_id` AS `currency_id`, coalesce(sum(case when `t`.`direction` = 'in' then `t`.`amount` else -`t`.`amount` end),0) AS `balance` FROM (`accounts` `a` left join `account_transactions` `t` on(`t`.`account_id` = `a`.`id`)) GROUP BY `a`.`id`, `a`.`currency_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_current_stock`
--
DROP TABLE IF EXISTS `v_current_stock`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_current_stock`  AS SELECT `c`.`id` AS `id`, `c`.`carpet_code` AS `carpet_code`, `c`.`name` AS `name`, `c`.`registration_branch_id` AS `registration_branch_id`, `c`.`category_id` AS `category_id`, `c`.`size_label` AS `size_label`, `c`.`length_cm` AS `length_cm`, `c`.`width_cm` AS `width_cm`, `c`.`color` AS `color`, `c`.`material` AS `material`, `c`.`notes` AS `notes`, `c`.`version` AS `version`, `c`.`created_by` AS `created_by`, `c`.`updated_by` AS `updated_by`, `c`.`created_at` AS `created_at`, `c`.`updated_at` AS `updated_at`, `c`.`deleted_at` AS `deleted_at`, coalesce(`e`.`sequence_no`,0) AS `sequence_no`, coalesce(`e`.`resulting_status`,'registered') AS `stock_status`, `e`.`resulting_location_id` AS `location_id`, CASE WHEN `e`.`resulting_status` = 'registered' THEN `c`.`registration_branch_id` ELSE coalesce(`l`.`branch_id`,`wo`.`branch_id`,`ts`.`branch_id`,`p`.`branch_id`,`po`.`branch_id`,`mo`.`branch_id`,`c`.`registration_branch_id`) END AS `custody_branch_id`, `e`.`occurred_on` AS `occurred_on`, `i`.`id` AS `acquisition_item_id`, `pc`.`production_order_id` AS `production_order_id`, coalesce(`po`.`model`,if(`mi`.`id` is not null,'company_funded',NULL),'buy_resell') AS `business_model`, `mi`.`production_order_id` AS `manager_production_order_id` FROM ((((((((((((`carpets` `c` left join `stock_events` `e` on(`e`.`carpet_id` = `c`.`id` and !exists(select 1 from `stock_events` `n` where `n`.`carpet_id` = `c`.`id` and `n`.`sequence_no` > `e`.`sequence_no` limit 1))) left join `stock_locations` `l` on(`l`.`id` = `e`.`resulting_location_id`)) left join `washing_order_items` `wi` on(`wi`.`id` = `e`.`washing_order_item_id`)) left join `washing_orders` `wo` on(`wo`.`id` = `wi`.`washing_order_id`)) left join `weaver_purchase_items` `i` on(`i`.`carpet_id` = `c`.`id` and !exists(select 1 from `weaver_purchase_items` `ni` where `ni`.`carpet_id` = `c`.`id` and `ni`.`id` > `i`.`id` limit 1))) left join `weaver_purchases` `p` on(`p`.`id` = `i`.`weaver_purchase_id`)) left join `production_carpets` `pc` on(`pc`.`carpet_id` = `c`.`id`)) left join `production_orders` `po` on(`po`.`id` = `pc`.`production_order_id`)) left join `trade_sale_items` `si` on(`si`.`id` = `e`.`trade_sale_item_id`)) left join `trade_sales` `ts` on(`ts`.`id` = `si`.`sale_id`)) left join `manager_delivery_items` `mi` on(`mi`.`carpet_id` = `c`.`id`)) left join `manager_production_orders` `mo` on(`mo`.`id` = `mi`.`production_order_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_washing_bill_balances`
--
DROP TABLE IF EXISTS `v_washing_bill_balances`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_washing_bill_balances`  AS SELECT `b`.`id` AS `bill_id`, `b`.`amount` AS `total_amount`, if(`v`.`id` is null,'posted','void') AS `state`, coalesce(`p`.`paid`,0) AS `paid_amount`, if(`v`.`id` is null,`b`.`amount`,0) - coalesce(`p`.`paid`,0) AS `remaining_amount` FROM ((`washing_bills` `b` left join `washing_bill_voids` `v` on(`v`.`washing_bill_id` = `b`.`id`)) left join (select `w`.`washing_bill_id` AS `washing_bill_id`,sum(`w`.`amount_in_bill_currency`) AS `paid` from `washing_payments` `w` where !exists(select 1 from `account_transactions` `r` where `r`.`reversal_of_id` = `w`.`account_transaction_id` limit 1) group by `w`.`washing_bill_id`) `p` on(`p`.`washing_bill_id` = `b`.`id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_weaver_purchase_balances`
--
DROP TABLE IF EXISTS `v_weaver_purchase_balances`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_weaver_purchase_balances`  AS SELECT `p`.`id` AS `purchase_id`, coalesce(`i`.`total`,0) AS `total_amount`, if(`v`.`id` is null,0,coalesce(`i`.`total`,0)) AS `cancelled_amount`, coalesce(`ret`.`total`,0) AS `returned_amount`, if(`v`.`id` is null,coalesce(`i`.`total`,0) - coalesce(`ret`.`total`,0),0) AS `net_amount`, coalesce(`a`.`paid`,0) + coalesce(`sp`.`paid`,0) AS `paid_amount`, coalesce(`f`.`refunded`,0) AS `refunded_amount`, if(`v`.`id` is null,coalesce(`i`.`total`,0) - coalesce(`ret`.`total`,0),0) - coalesce(`a`.`paid`,0) - coalesce(`sp`.`paid`,0) + coalesce(`f`.`refunded`,0) AS `remaining_amount` FROM ((((((`weaver_purchases` `p` left join `purchase_voids` `v` on(`v`.`weaver_purchase_id` = `p`.`id`)) left join (select `weaver_purchase_items`.`weaver_purchase_id` AS `weaver_purchase_id`,sum(`weaver_purchase_items`.`unit_price`) AS `total` from `weaver_purchase_items` group by `weaver_purchase_items`.`weaver_purchase_id`) `i` on(`i`.`weaver_purchase_id` = `p`.`id`)) left join (select `x`.`weaver_purchase_id` AS `weaver_purchase_id`,sum(`x`.`amount_in_purchase_currency`) AS `paid` from (`weaver_payment_allocations` `x` join `weaver_payments` `w` on(`w`.`id` = `x`.`weaver_payment_id`)) where !exists(select 1 from `account_transactions` `r` where `r`.`reversal_of_id` = `w`.`account_transaction_id` limit 1) group by `x`.`weaver_purchase_id`) `a` on(`a`.`weaver_purchase_id` = `p`.`id`)) left join (select `ri`.`weaver_purchase_id` AS `weaver_purchase_id`,sum(`ri`.`unit_price`) AS `total` from (`weaver_return_items` `r` join `weaver_purchase_items` `ri` on(`ri`.`id` = `r`.`weaver_purchase_item_id`)) group by `ri`.`weaver_purchase_id`) `ret` on(`ret`.`weaver_purchase_id` = `p`.`id`)) left join (select `w`.`weaver_purchase_id` AS `weaver_purchase_id`,sum(`w`.`amount_in_purchase_currency`) AS `refunded` from `weaver_refunds` `w` where !exists(select 1 from `account_transactions` `r` where `r`.`reversal_of_id` = `w`.`account_transaction_id` limit 1) group by `w`.`weaver_purchase_id`) `f` on(`f`.`weaver_purchase_id` = `p`.`id`)) left join (select `w`.`purchase_id` AS `purchase_id`,sum(`w`.`document_amount`) AS `paid` from `supplier_carpet_payments` `w` where !exists(select 1 from `account_transactions` `r` where `r`.`reversal_of_id` = `w`.`account_transaction_id` limit 1) group by `w`.`purchase_id`) `sp` on(`sp`.`purchase_id` = `p`.`id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `accounts_code_unique` (`code`),
  ADD KEY `fk_acc_branch` (`branch_id`),
  ADD KEY `fk_acc_currency` (`currency_id`);

--
-- Indexes for table `account_transactions`
--
ALTER TABLE `account_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_transactions_idempotency_key_unique` (`idempotency_key`),
  ADD UNIQUE KEY `account_transactions_reversal_of_id_unique` (`reversal_of_id`),
  ADD KEY `idx_acctx_account` (`account_id`,`transaction_date`),
  ADD KEY `idx_acctx_ref` (`ref_type`,`ref_id`),
  ADD KEY `fk_acctx_currency` (`currency_id`),
  ADD KEY `fk_acctx_creator` (`created_by`);

--
-- Indexes for table `areas`
--
ALTER TABLE `areas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_phase2_areas_name` (`district_id`,`name`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_table` (`table_name`,`record_id`),
  ADD KEY `idx_audit_user` (`user_id`,`created_at`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `branding_images`
--
ALTER TABLE `branding_images`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `branding_images_slot_unique` (`slot`),
  ADD UNIQUE KEY `branding_images_version_unique` (`version`),
  ADD UNIQUE KEY `branding_images_storage_path_unique` (`storage_path`),
  ADD KEY `branding_images_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `business_voids`
--
ALTER TABLE `business_voids`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `business_voids_expense_id_unique` (`expense_id`),
  ADD KEY `business_voids_created_by_foreign` (`created_by`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `carpets`
--
ALTER TABLE `carpets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `carpets_carpet_code_unique` (`carpet_code`),
  ADD KEY `carpets_registration_branch_id_foreign` (`registration_branch_id`),
  ADD KEY `carpets_category_id_foreign` (`category_id`),
  ADD KEY `carpets_created_by_foreign` (`created_by`),
  ADD KEY `carpets_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `carpet_categories`
--
ALTER TABLE `carpet_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_name` (`name`);

--
-- Indexes for table `carpet_images`
--
ALTER TABLE `carpet_images`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `carpet_images_storage_path_unique` (`storage_path`),
  ADD KEY `carpet_images_carpet_id_foreign` (`carpet_id`),
  ADD KEY `carpet_images_created_by_foreign` (`created_by`);

--
-- Indexes for table `carpet_purchases`
--
ALTER TABLE `carpet_purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_no` (`purchase_no`),
  ADD KEY `idx_cpur_weaver` (`weaver_id`),
  ADD KEY `idx_cpur_date` (`purchase_date`),
  ADD KEY `idx_cpur_branch` (`branch_id`),
  ADD KEY `fk_cpur_currency` (`currency_id`),
  ADD KEY `fk_cpur_user` (`responsible_user_id`),
  ADD KEY `fk_cpur_creator` (`created_by`);

--
-- Indexes for table `carpet_purchase_items`
--
ALTER TABLE `carpet_purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cpi_purchase` (`purchase_id`),
  ADD KEY `fk_cpi_carpet` (`carpet_id`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_settings_base_currency_id_foreign` (`base_currency_id`),
  ADD KEY `company_settings_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `currencies`
--
ALTER TABLE `currencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customers_phone` (`phone`),
  ADD KEY `idx_customers_type` (`customer_type`),
  ADD KEY `fk_cust_creator` (`created_by`);

--
-- Indexes for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_no` (`payment_no`),
  ADD KEY `idx_cpay_customer` (`customer_id`),
  ADD KEY `idx_cpay_sale` (`sale_id`),
  ADD KEY `idx_cpay_date` (`payment_date`),
  ADD KEY `fk_cpay_schedule` (`schedule_id`),
  ADD KEY `fk_cpay_currency` (`currency_id`),
  ADD KEY `fk_cpay_user` (`responsible_user_id`),
  ADD KEY `fk_cpay_creator` (`created_by`),
  ADD KEY `fk_cpay_account` (`account_id`);

--
-- Indexes for table `districts`
--
ALTER TABLE `districts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_phase2_districts_name` (`province_id`,`name`);

--
-- Indexes for table `document_sequences`
--
ALTER TABLE `document_sequences`
  ADD PRIMARY KEY (`kind`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_emp_branch` (`branch_id`);

--
-- Indexes for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rate_day` (`base_currency_id`,`quote_currency_id`,`effective_date`),
  ADD KEY `fk_fx_quote` (`quote_currency_id`),
  ADD KEY `fk_fx_creator` (`created_by`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `expense_no` (`expense_no`),
  ADD KEY `idx_exp_date` (`expense_date`),
  ADD KEY `idx_exp_category` (`expense_category_id`),
  ADD KEY `fk_exp_currency` (`currency_id`),
  ADD KEY `fk_exp_branch` (`branch_id`),
  ADD KEY `fk_exp_account` (`account_id`),
  ADD KEY `fk_exp_employee` (`responsible_employee_id`),
  ADD KEY `fk_exp_creator` (`created_by`);

--
-- Indexes for table `expense_attachments`
--
ALTER TABLE `expense_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `expense_attachments_expense_id_foreign` (`expense_id`),
  ADD KEY `expense_attachments_created_by_foreign` (`created_by`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_expense_cat` (`name`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `inventory_commands`
--
ALTER TABLE `inventory_commands`
  ADD PRIMARY KEY (`idempotency_key`),
  ADD KEY `inventory_commands_actor_id_foreign` (`actor_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `legacy_carpets`
--
ALTER TABLE `legacy_carpets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `carpet_code` (`carpet_code`),
  ADD KEY `idx_carpets_status` (`status`),
  ADD KEY `idx_carpets_branch` (`current_branch_id`),
  ADD KEY `idx_carpets_weaver` (`weaver_id`),
  ADD KEY `idx_carpets_category` (`category_id`),
  ADD KEY `fk_cp_currency` (`currency_id`),
  ADD KEY `fk_cp_creator` (`created_by`);

--
-- Indexes for table `legacy_washing_orders`
--
ALTER TABLE `legacy_washing_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_no` (`order_no`),
  ADD KEY `idx_wash_facility` (`washing_facility_id`),
  ADD KEY `idx_wash_status` (`status`),
  ADD KEY `idx_wash_carpet` (`carpet_id`),
  ADD KEY `fk_wo_currency` (`currency_id`),
  ADD KEY `fk_wo_user` (`responsible_user_id`),
  ADD KEY `fk_wo_creator` (`created_by`);

--
-- Indexes for table `legacy_weaver_payments`
--
ALTER TABLE `legacy_weaver_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_no` (`payment_no`),
  ADD KEY `idx_wpay_weaver` (`weaver_id`),
  ADD KEY `idx_wpay_date` (`payment_date`),
  ADD KEY `idx_wpay_type` (`payment_type`),
  ADD KEY `fk_wpay_purchase` (`carpet_purchase_id`),
  ADD KEY `fk_wpay_currency` (`currency_id`),
  ADD KEY `fk_wpay_user` (`responsible_user_id`),
  ADD KEY `fk_wpay_creator` (`created_by`),
  ADD KEY `fk_wpay_account` (`account_id`);

--
-- Indexes for table `manager_delivery_acceptances`
--
ALTER TABLE `manager_delivery_acceptances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_delivery_acceptances_item_id_unique` (`item_id`),
  ADD UNIQUE KEY `manager_delivery_acceptances_washing_order_item_id_unique` (`washing_order_item_id`),
  ADD KEY `manager_delivery_acceptances_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_delivery_items`
--
ALTER TABLE `manager_delivery_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_delivery_items_carpet_id_unique` (`carpet_id`),
  ADD KEY `manager_delivery_items_delivery_id_foreign` (`delivery_id`),
  ADD KEY `manager_delivery_items_production_order_id_foreign` (`production_order_id`),
  ADD KEY `manager_delivery_items_line_id_foreign` (`line_id`),
  ADD KEY `manager_delivery_items_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_delivery_materials`
--
ALTER TABLE `manager_delivery_materials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_delivery_materials_movement_id_unique` (`movement_id`),
  ADD KEY `manager_delivery_materials_delivery_id_foreign` (`delivery_id`),
  ADD KEY `manager_delivery_materials_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_earned_charges`
--
ALTER TABLE `manager_earned_charges`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_earned_charges_item_id_unique` (`item_id`),
  ADD KEY `manager_earned_charges_production_order_id_foreign` (`production_order_id`),
  ADD KEY `manager_earned_charges_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_material_movements`
--
ALTER TABLE `manager_material_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `manager_material_movements_manager_id_lot_id_index` (`manager_id`,`lot_id`),
  ADD KEY `manager_material_movements_lot_id_movement_type_index` (`lot_id`,`movement_type`),
  ADD KEY `manager_material_movements_created_by_foreign` (`created_by`),
  ADD KEY `manager_material_movements_production_order_id_index` (`production_order_id`);

--
-- Indexes for table `manager_pricing_factors`
--
ALTER TABLE `manager_pricing_factors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `manager_pricing_factors_production_order_id_foreign` (`production_order_id`),
  ADD KEY `manager_pricing_factors_line_id_foreign` (`line_id`),
  ADD KEY `manager_pricing_factors_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_production_closures`
--
ALTER TABLE `manager_production_closures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_production_closures_production_order_id_unique` (`production_order_id`),
  ADD KEY `manager_production_closures_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_production_deliveries`
--
ALTER TABLE `manager_production_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_production_deliveries_delivery_no_unique` (`delivery_no`),
  ADD KEY `manager_production_deliveries_production_order_id_foreign` (`production_order_id`),
  ADD KEY `manager_production_deliveries_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_production_lines`
--
ALTER TABLE `manager_production_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `manager_production_lines_production_order_id_foreign` (`production_order_id`),
  ADD KEY `manager_production_lines_category_id_foreign` (`category_id`),
  ADD KEY `manager_production_lines_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_production_orders`
--
ALTER TABLE `manager_production_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_production_orders_order_no_unique` (`order_no`),
  ADD KEY `manager_production_orders_manager_id_foreign` (`manager_id`),
  ADD KEY `manager_production_orders_branch_id_foreign` (`branch_id`),
  ADD KEY `manager_production_orders_currency_id_foreign` (`currency_id`),
  ADD KEY `manager_production_orders_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_production_payments`
--
ALTER TABLE `manager_production_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `manager_production_payments_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `manager_production_payments_production_order_id_foreign` (`production_order_id`),
  ADD KEY `manager_production_payments_created_by_foreign` (`created_by`);

--
-- Indexes for table `manager_tool_movements`
--
ALTER TABLE `manager_tool_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `manager_tool_movements_lot_id_foreign` (`lot_id`),
  ADD KEY `manager_tool_movements_manager_id_lot_id_index` (`manager_id`,`lot_id`),
  ADD KEY `manager_tool_movements_created_by_foreign` (`created_by`),
  ADD KEY `manager_tool_movements_production_order_id_foreign` (`production_order_id`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `materials_code_unique` (`code`),
  ADD KEY `materials_created_by_foreign` (`created_by`);

--
-- Indexes for table `material_lots`
--
ALTER TABLE `material_lots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `material_lots_lot_no_unique` (`lot_no`),
  ADD KEY `material_lots_material_id_foreign` (`material_id`),
  ADD KEY `material_lots_supplier_id_foreign` (`supplier_id`),
  ADD KEY `material_lots_location_id_foreign` (`location_id`),
  ADD KEY `material_lots_branch_id_foreign` (`branch_id`),
  ADD KEY `material_lots_currency_id_foreign` (`currency_id`),
  ADD KEY `material_lots_created_by_foreign` (`created_by`);

--
-- Indexes for table `material_lot_payments`
--
ALTER TABLE `material_lot_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_material_lot_payment` (`lot_id`,`account_transaction_id`),
  ADD KEY `material_lot_payments_created_by_foreign` (`created_by`),
  ADD KEY `idx_material_payment_ledger` (`account_transaction_id`);

--
-- Indexes for table `material_movements`
--
ALTER TABLE `material_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `material_movements_created_by_foreign` (`created_by`),
  ADD KEY `idx_material_lot_type` (`lot_id`,`movement_type`),
  ADD KEY `idx_production_material_type` (`production_order_id`,`movement_type`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`,`is_read`),
  ADD KEY `idx_notif_type` (`type`);

--
-- Indexes for table `operating_expenses`
--
ALTER TABLE `operating_expenses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `operating_expenses_expense_no_unique` (`expense_no`),
  ADD KEY `operating_expenses_branch_id_foreign` (`branch_id`),
  ADD KEY `operating_expenses_currency_id_foreign` (`currency_id`),
  ADD KEY `operating_expenses_expense_category_id_foreign` (`expense_category_id`),
  ADD KEY `operating_expenses_created_by_foreign` (`created_by`);

--
-- Indexes for table `operating_expense_payments`
--
ALTER TABLE `operating_expense_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `operating_expense_payments_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `operating_expense_payments_expense_id_foreign` (`expense_id`),
  ADD KEY `operating_expense_payments_created_by_foreign` (`created_by`);

--
-- Indexes for table `partnership_contributions`
--
ALTER TABLE `partnership_contributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `partnership_contributions_production_order_id_foreign` (`production_order_id`),
  ADD KEY `partnership_contributions_created_by_foreign` (`created_by`);

--
-- Indexes for table `partnership_settlements`
--
ALTER TABLE `partnership_settlements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `partnership_settlements_production_order_id_unique` (`production_order_id`),
  ADD KEY `partnership_settlements_created_by_foreign` (`created_by`);

--
-- Indexes for table `partnership_settlement_payments`
--
ALTER TABLE `partnership_settlement_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `partnership_settlement_payments_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `partnership_settlement_payments_settlement_id_foreign` (`settlement_id`),
  ADD KEY `partnership_settlement_payments_created_by_foreign` (`created_by`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_schedule` (`sale_id`,`installment_no`),
  ADD KEY `idx_sched_due` (`due_date`,`status`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_pat_tokenable` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `production_carpets`
--
ALTER TABLE `production_carpets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_carpets_carpet_id_unique` (`carpet_id`),
  ADD KEY `production_carpets_production_order_id_foreign` (`production_order_id`);

--
-- Indexes for table `production_charges`
--
ALTER TABLE `production_charges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `production_charges_production_order_id_foreign` (`production_order_id`),
  ADD KEY `production_charges_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_managers`
--
ALTER TABLE `production_managers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_managers_code_unique` (`code`),
  ADD KEY `production_managers_branch_id_foreign` (`branch_id`),
  ADD KEY `production_managers_employee_id_foreign` (`employee_id`),
  ADD KEY `production_managers_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_orders`
--
ALTER TABLE `production_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_orders_order_no_unique` (`order_no`),
  ADD KEY `production_orders_weaver_id_foreign` (`weaver_id`),
  ADD KEY `production_orders_branch_id_foreign` (`branch_id`),
  ADD KEY `production_orders_currency_id_foreign` (`currency_id`),
  ADD KEY `production_orders_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_payments`
--
ALTER TABLE `production_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_payments_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `production_payments_production_order_id_foreign` (`production_order_id`),
  ADD KEY `production_payments_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_progress`
--
ALTER TABLE `production_progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `production_progress_production_order_id_foreign` (`production_order_id`),
  ADD KEY `production_progress_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_receipts`
--
ALTER TABLE `production_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_receipts_production_order_id_unique` (`production_order_id`),
  ADD KEY `production_receipts_location_id_foreign` (`location_id`),
  ADD KEY `production_receipts_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_receipt_items`
--
ALTER TABLE `production_receipt_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_receipt_items_production_carpet_id_unique` (`production_carpet_id`),
  ADD KEY `production_receipt_items_production_receipt_id_foreign` (`production_receipt_id`);

--
-- Indexes for table `production_tools`
--
ALTER TABLE `production_tools`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_tools_code_unique` (`code`),
  ADD KEY `production_tools_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_tool_lots`
--
ALTER TABLE `production_tool_lots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_tool_lots_lot_no_unique` (`lot_no`),
  ADD KEY `production_tool_lots_tool_id_foreign` (`tool_id`),
  ADD KEY `production_tool_lots_supplier_id_foreign` (`supplier_id`),
  ADD KEY `production_tool_lots_location_id_foreign` (`location_id`),
  ADD KEY `production_tool_lots_branch_id_foreign` (`branch_id`),
  ADD KEY `production_tool_lots_currency_id_foreign` (`currency_id`),
  ADD KEY `production_tool_lots_created_by_foreign` (`created_by`);

--
-- Indexes for table `production_tool_payments`
--
ALTER TABLE `production_tool_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tool_lot_payment` (`lot_id`,`account_transaction_id`),
  ADD KEY `production_tool_payments_created_by_foreign` (`created_by`),
  ADD KEY `idx_tool_payment_ledger` (`account_transaction_id`);

--
-- Indexes for table `production_workflow_defaults`
--
ALTER TABLE `production_workflow_defaults`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_workflow_defaults_branch_id_unique` (`branch_id`),
  ADD KEY `production_workflow_defaults_materials_location_id_foreign` (`materials_location_id`),
  ADD KEY `production_workflow_defaults_finished_location_id_foreign` (`finished_location_id`),
  ADD KEY `production_workflow_defaults_facility_id_foreign` (`facility_id`),
  ADD KEY `production_workflow_defaults_facility_location_id_foreign` (`facility_location_id`),
  ADD KEY `production_workflow_defaults_created_by_foreign` (`created_by`);

--
-- Indexes for table `provinces`
--
ALTER TABLE `provinces`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_phase2_provinces_name` (`name`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_no` (`purchase_no`),
  ADD KEY `idx_pur_supplier` (`supplier_id`),
  ADD KEY `idx_pur_facility` (`washing_facility_id`),
  ADD KEY `idx_pur_date` (`purchase_date`),
  ADD KEY `fk_pur_branch` (`branch_id`),
  ADD KEY `fk_pur_currency` (`currency_id`),
  ADD KEY `fk_pur_user` (`responsible_user_id`),
  ADD KEY `fk_pur_creator` (`created_by`),
  ADD KEY `fk_pur_account` (`account_id`);

--
-- Indexes for table `purchase_drafts`
--
ALTER TABLE `purchase_drafts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_drafts_purchase_no_unique` (`purchase_no`),
  ADD UNIQUE KEY `purchase_drafts_posted_purchase_id_unique` (`posted_purchase_id`),
  ADD KEY `purchase_drafts_weaver_id_foreign` (`weaver_id`),
  ADD KEY `purchase_drafts_branch_id_foreign` (`branch_id`),
  ADD KEY `purchase_drafts_currency_id_foreign` (`currency_id`),
  ADD KEY `purchase_drafts_created_by_foreign` (`created_by`),
  ADD KEY `purchase_drafts_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `purchase_draft_items`
--
ALTER TABLE `purchase_draft_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_draft_carpet` (`purchase_draft_id`,`carpet_id`),
  ADD KEY `purchase_draft_items_carpet_id_foreign` (`carpet_id`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pi_purchase` (`purchase_id`);

--
-- Indexes for table `purchase_voids`
--
ALTER TABLE `purchase_voids`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `purchase_voids_weaver_purchase_id_unique` (`weaver_purchase_id`),
  ADD KEY `purchase_voids_created_by_foreign` (`created_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_rp_perm` (`permission_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sale_no` (`sale_no`),
  ADD KEY `idx_sales_customer` (`customer_id`),
  ADD KEY `idx_sales_branch` (`branch_id`),
  ADD KEY `idx_sales_date` (`sale_date`),
  ADD KEY `idx_sales_status` (`payment_status`),
  ADD KEY `fk_sale_seller` (`salesperson_id`),
  ADD KEY `fk_sale_currency` (`currency_id`),
  ADD KEY `fk_sale_creator` (`created_by`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_si_sale` (`sale_id`),
  ADD KEY `fk_si_carpet` (`carpet_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stock_adjustments_adjustment_no_unique` (`adjustment_no`),
  ADD KEY `stock_adjustments_location_id_foreign` (`location_id`),
  ADD KEY `stock_adjustments_branch_id_foreign` (`branch_id`),
  ADD KEY `stock_adjustments_created_by_foreign` (`created_by`);

--
-- Indexes for table `stock_events`
--
ALTER TABLE `stock_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stock_events_carpet_id_sequence_no_unique` (`carpet_id`,`sequence_no`),
  ADD UNIQUE KEY `stock_events_stock_receipt_id_carpet_id_unique` (`stock_receipt_id`,`carpet_id`),
  ADD UNIQUE KEY `stock_events_stock_transfer_id_carpet_id_unique` (`stock_transfer_id`,`carpet_id`),
  ADD UNIQUE KEY `stock_events_weaver_purchase_item_id_unique` (`weaver_purchase_item_id`),
  ADD UNIQUE KEY `stock_events_weaver_return_item_id_unique` (`weaver_return_item_id`),
  ADD UNIQUE KEY `uq_washing_visit_event` (`event_type`,`washing_order_item_id`),
  ADD UNIQUE KEY `stock_events_production_receipt_item_id_unique` (`production_receipt_item_id`),
  ADD UNIQUE KEY `stock_events_trade_sale_item_id_unique` (`trade_sale_item_id`),
  ADD UNIQUE KEY `stock_events_manager_delivery_item_id_unique` (`manager_delivery_item_id`),
  ADD KEY `stock_events_resulting_location_id_foreign` (`resulting_location_id`),
  ADD KEY `stock_events_handled_by_foreign` (`handled_by`),
  ADD KEY `stock_events_purchase_void_id_foreign` (`purchase_void_id`),
  ADD KEY `stock_events_stock_adjustment_id_foreign` (`stock_adjustment_id`),
  ADD KEY `stock_events_washing_order_item_id_foreign` (`washing_order_item_id`);

--
-- Indexes for table `stock_locations`
--
ALTER TABLE `stock_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stock_locations_code_unique` (`code`),
  ADD KEY `stock_locations_branch_id_foreign` (`branch_id`),
  ADD KEY `stock_locations_washing_facility_id_foreign` (`washing_facility_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mv_carpet` (`carpet_id`,`moved_at`),
  ADD KEY `idx_mv_branch` (`to_branch_id`),
  ADD KEY `fk_mv_from` (`from_branch_id`),
  ADD KEY `fk_mv_handler` (`handled_by`);

--
-- Indexes for table `stock_receipts`
--
ALTER TABLE `stock_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stock_receipts_receipt_no_unique` (`receipt_no`),
  ADD KEY `stock_receipts_location_id_foreign` (`location_id`),
  ADD KEY `stock_receipts_created_by_foreign` (`created_by`);

--
-- Indexes for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stock_transfers_transfer_no_unique` (`transfer_no`),
  ADD KEY `stock_transfers_from_location_id_foreign` (`from_location_id`),
  ADD KEY `stock_transfers_to_location_id_foreign` (`to_location_id`),
  ADD KEY `stock_transfers_created_by_foreign` (`created_by`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_phase2_suppliers_created_by` (`created_by`);

--
-- Indexes for table `supplier_carpet_payments`
--
ALTER TABLE `supplier_carpet_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_carpet_payments_payment_no_unique` (`payment_no`),
  ADD UNIQUE KEY `supplier_carpet_payments_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `supplier_carpet_payments_purchase_id_foreign` (`purchase_id`),
  ADD KEY `supplier_carpet_payments_created_by_foreign` (`created_by`);

--
-- Indexes for table `supply_purchases`
--
ALTER TABLE `supply_purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supply_purchases_purchase_no_unique` (`purchase_no`),
  ADD KEY `supply_purchases_branch_id_foreign` (`branch_id`),
  ADD KEY `supply_purchases_location_id_foreign` (`location_id`),
  ADD KEY `supply_purchases_supplier_id_foreign` (`supplier_id`),
  ADD KEY `supply_purchases_currency_id_foreign` (`currency_id`),
  ADD KEY `supply_purchases_created_by_foreign` (`created_by`);

--
-- Indexes for table `supply_purchase_lines`
--
ALTER TABLE `supply_purchase_lines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supply_purchase_lines_material_lot_id_unique` (`material_lot_id`),
  ADD UNIQUE KEY `supply_purchase_lines_tool_lot_id_unique` (`tool_lot_id`),
  ADD KEY `supply_purchase_lines_purchase_id_foreign` (`purchase_id`),
  ADD KEY `supply_purchase_lines_created_by_foreign` (`created_by`);

--
-- Indexes for table `trade_customer_receipts`
--
ALTER TABLE `trade_customer_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trade_customer_receipts_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `trade_customer_receipts_sale_id_foreign` (`sale_id`),
  ADD KEY `trade_customer_receipts_created_by_foreign` (`created_by`);

--
-- Indexes for table `trade_sales`
--
ALTER TABLE `trade_sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trade_sales_sale_no_unique` (`sale_no`),
  ADD KEY `trade_sales_customer_id_foreign` (`customer_id`),
  ADD KEY `trade_sales_branch_id_foreign` (`branch_id`),
  ADD KEY `trade_sales_currency_id_foreign` (`currency_id`),
  ADD KEY `trade_sales_created_by_foreign` (`created_by`);

--
-- Indexes for table `trade_sale_items`
--
ALTER TABLE `trade_sale_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trade_sale_items_carpet_id_unique` (`carpet_id`),
  ADD KEY `trade_sale_items_sale_id_foreign` (`sale_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `users_employee_unique` (`employee_id`);

--
-- Indexes for table `user_accounts`
--
ALTER TABLE `user_accounts`
  ADD PRIMARY KEY (`user_id`,`account_id`),
  ADD KEY `user_accounts_account_id_foreign` (`account_id`);

--
-- Indexes for table `user_branches`
--
ALTER TABLE `user_branches`
  ADD PRIMARY KEY (`user_id`,`branch_id`),
  ADD KEY `fk_ub_branch` (`branch_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_ur_role` (`role_id`);

--
-- Indexes for table `user_washing_facilities`
--
ALTER TABLE `user_washing_facilities`
  ADD PRIMARY KEY (`user_id`,`washing_facility_id`),
  ADD KEY `user_washing_facilities_washing_facility_id_foreign` (`washing_facility_id`);

--
-- Indexes for table `washing_bills`
--
ALTER TABLE `washing_bills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_washing_facility_invoice` (`facility_id`,`invoice_no`),
  ADD UNIQUE KEY `washing_bills_expense_no_unique` (`expense_no`),
  ADD KEY `washing_bills_branch_id_foreign` (`branch_id`),
  ADD KEY `washing_bills_currency_id_foreign` (`currency_id`),
  ADD KEY `washing_bills_created_by_foreign` (`created_by`);

--
-- Indexes for table `washing_bill_allocations`
--
ALTER TABLE `washing_bill_allocations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_washing_bill_visit` (`washing_bill_id`,`washing_order_item_id`),
  ADD KEY `washing_bill_allocations_washing_order_item_id_foreign` (`washing_order_item_id`);

--
-- Indexes for table `washing_bill_voids`
--
ALTER TABLE `washing_bill_voids`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `washing_bill_voids_washing_bill_id_unique` (`washing_bill_id`),
  ADD KEY `washing_bill_voids_created_by_foreign` (`created_by`);

--
-- Indexes for table `washing_facilities`
--
ALTER TABLE `washing_facilities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `washing_orders`
--
ALTER TABLE `washing_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `washing_orders_order_no_unique` (`order_no`),
  ADD KEY `washing_orders_facility_location_id_foreign` (`facility_location_id`),
  ADD KEY `washing_orders_branch_id_foreign` (`branch_id`),
  ADD KEY `washing_orders_facility_id_foreign` (`facility_id`),
  ADD KEY `washing_orders_created_by_foreign` (`created_by`);

--
-- Indexes for table `washing_order_items`
--
ALTER TABLE `washing_order_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_washing_order_carpet` (`washing_order_id`,`carpet_id`),
  ADD KEY `washing_order_items_carpet_id_foreign` (`carpet_id`),
  ADD KEY `washing_order_items_acquisition_item_id_foreign` (`acquisition_item_id`),
  ADD KEY `washing_order_items_source_location_id_foreign` (`source_location_id`);

--
-- Indexes for table `washing_payments`
--
ALTER TABLE `washing_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `washing_payments_payment_no_unique` (`payment_no`),
  ADD UNIQUE KEY `washing_payments_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `washing_payments_washing_bill_id_foreign` (`washing_bill_id`),
  ADD KEY `washing_payments_created_by_foreign` (`created_by`);

--
-- Indexes for table `weavers`
--
ALTER TABLE `weavers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_weavers_geo` (`area_id`),
  ADD KEY `fk_phase2_weavers_manager_employee_id` (`manager_employee_id`),
  ADD KEY `fk_phase2_weavers_created_by` (`created_by`);

--
-- Indexes for table `weaver_payments`
--
ALTER TABLE `weaver_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `weaver_payments_payment_no_unique` (`payment_no`),
  ADD UNIQUE KEY `weaver_payments_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `weaver_payments_weaver_id_foreign` (`weaver_id`),
  ADD KEY `weaver_payments_branch_id_foreign` (`branch_id`),
  ADD KEY `weaver_payments_expense_category_id_foreign` (`expense_category_id`),
  ADD KEY `weaver_payments_created_by_foreign` (`created_by`);

--
-- Indexes for table `weaver_payment_allocations`
--
ALTER TABLE `weaver_payment_allocations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_weaver_payment_purchase` (`weaver_payment_id`,`weaver_purchase_id`),
  ADD KEY `weaver_payment_allocations_weaver_purchase_id_foreign` (`weaver_purchase_id`);

--
-- Indexes for table `weaver_purchases`
--
ALTER TABLE `weaver_purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `weaver_purchases_purchase_no_unique` (`purchase_no`),
  ADD KEY `weaver_purchases_weaver_id_foreign` (`weaver_id`),
  ADD KEY `weaver_purchases_branch_id_foreign` (`branch_id`),
  ADD KEY `weaver_purchases_currency_id_foreign` (`currency_id`),
  ADD KEY `weaver_purchases_created_by_foreign` (`created_by`),
  ADD KEY `weaver_purchases_supplier_id_foreign` (`supplier_id`);

--
-- Indexes for table `weaver_purchase_items`
--
ALTER TABLE `weaver_purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_purchase_carpet` (`weaver_purchase_id`,`carpet_id`),
  ADD KEY `ix_carpet_acquisitions` (`carpet_id`,`id`);

--
-- Indexes for table `weaver_refunds`
--
ALTER TABLE `weaver_refunds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `weaver_refunds_refund_no_unique` (`refund_no`),
  ADD UNIQUE KEY `weaver_refunds_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `weaver_refunds_weaver_purchase_id_foreign` (`weaver_purchase_id`),
  ADD KEY `weaver_refunds_created_by_foreign` (`created_by`);

--
-- Indexes for table `weaver_returns`
--
ALTER TABLE `weaver_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `weaver_returns_return_no_unique` (`return_no`),
  ADD KEY `weaver_returns_weaver_purchase_id_foreign` (`weaver_purchase_id`),
  ADD KEY `weaver_returns_from_location_id_foreign` (`from_location_id`),
  ADD KEY `weaver_returns_created_by_foreign` (`created_by`);

--
-- Indexes for table `weaver_return_items`
--
ALTER TABLE `weaver_return_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `weaver_return_items_weaver_purchase_item_id_unique` (`weaver_purchase_item_id`),
  ADD KEY `weaver_return_items_weaver_return_id_foreign` (`weaver_return_id`);

--
-- Indexes for table `worker_assistance`
--
ALTER TABLE `worker_assistance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `worker_assistance_assistance_no_unique` (`assistance_no`),
  ADD KEY `worker_assistance_branch_id_foreign` (`branch_id`),
  ADD KEY `worker_assistance_manager_id_foreign` (`manager_id`),
  ADD KEY `worker_assistance_currency_id_foreign` (`currency_id`),
  ADD KEY `worker_assistance_created_by_foreign` (`created_by`);

--
-- Indexes for table `worker_assistance_deliveries`
--
ALTER TABLE `worker_assistance_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `worker_assistance_deliveries_assistance_id_foreign` (`assistance_id`),
  ADD KEY `worker_assistance_deliveries_created_by_foreign` (`created_by`);

--
-- Indexes for table `worker_assistance_fundings`
--
ALTER TABLE `worker_assistance_fundings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `worker_assistance_fundings_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `worker_assistance_fundings_assistance_id_foreign` (`assistance_id`),
  ADD KEY `worker_assistance_fundings_created_by_foreign` (`created_by`);

--
-- Indexes for table `worker_assistance_recoveries`
--
ALTER TABLE `worker_assistance_recoveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `worker_assistance_recoveries_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `worker_assistance_recoveries_assistance_id_foreign` (`assistance_id`),
  ADD KEY `worker_assistance_recoveries_created_by_foreign` (`created_by`);

--
-- Indexes for table `worker_assistance_returns`
--
ALTER TABLE `worker_assistance_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `worker_assistance_returns_account_transaction_id_unique` (`account_transaction_id`),
  ADD KEY `worker_assistance_returns_assistance_id_foreign` (`assistance_id`),
  ADD KEY `worker_assistance_returns_created_by_foreign` (`created_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `account_transactions`
--
ALTER TABLE `account_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `areas`
--
ALTER TABLE `areas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1077;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `branding_images`
--
ALTER TABLE `branding_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `business_voids`
--
ALTER TABLE `business_voids`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `carpets`
--
ALTER TABLE `carpets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `carpet_categories`
--
ALTER TABLE `carpet_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `carpet_images`
--
ALTER TABLE `carpet_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `carpet_purchases`
--
ALTER TABLE `carpet_purchases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `carpet_purchase_items`
--
ALTER TABLE `carpet_purchase_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `currencies`
--
ALTER TABLE `currencies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `customer_payments`
--
ALTER TABLE `customer_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `districts`
--
ALTER TABLE `districts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_attachments`
--
ALTER TABLE `expense_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `legacy_carpets`
--
ALTER TABLE `legacy_carpets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `legacy_washing_orders`
--
ALTER TABLE `legacy_washing_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `legacy_weaver_payments`
--
ALTER TABLE `legacy_weaver_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_delivery_acceptances`
--
ALTER TABLE `manager_delivery_acceptances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `manager_delivery_items`
--
ALTER TABLE `manager_delivery_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_delivery_materials`
--
ALTER TABLE `manager_delivery_materials`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `manager_earned_charges`
--
ALTER TABLE `manager_earned_charges`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_material_movements`
--
ALTER TABLE `manager_material_movements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_pricing_factors`
--
ALTER TABLE `manager_pricing_factors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_production_closures`
--
ALTER TABLE `manager_production_closures`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_production_deliveries`
--
ALTER TABLE `manager_production_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_production_lines`
--
ALTER TABLE `manager_production_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_production_orders`
--
ALTER TABLE `manager_production_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_production_payments`
--
ALTER TABLE `manager_production_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manager_tool_movements`
--
ALTER TABLE `manager_tool_movements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `material_lots`
--
ALTER TABLE `material_lots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_lot_payments`
--
ALTER TABLE `material_lot_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `material_movements`
--
ALTER TABLE `material_movements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operating_expenses`
--
ALTER TABLE `operating_expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operating_expense_payments`
--
ALTER TABLE `operating_expense_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `partnership_contributions`
--
ALTER TABLE `partnership_contributions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `partnership_settlements`
--
ALTER TABLE `partnership_settlements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `partnership_settlement_payments`
--
ALTER TABLE `partnership_settlement_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `production_carpets`
--
ALTER TABLE `production_carpets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_charges`
--
ALTER TABLE `production_charges`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_managers`
--
ALTER TABLE `production_managers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_orders`
--
ALTER TABLE `production_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_payments`
--
ALTER TABLE `production_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_progress`
--
ALTER TABLE `production_progress`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_receipts`
--
ALTER TABLE `production_receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_receipt_items`
--
ALTER TABLE `production_receipt_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_tools`
--
ALTER TABLE `production_tools`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_tool_lots`
--
ALTER TABLE `production_tool_lots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_tool_payments`
--
ALTER TABLE `production_tool_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_workflow_defaults`
--
ALTER TABLE `production_workflow_defaults`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `provinces`
--
ALTER TABLE `provinces`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_drafts`
--
ALTER TABLE `purchase_drafts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_draft_items`
--
ALTER TABLE `purchase_draft_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_voids`
--
ALTER TABLE `purchase_voids`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_events`
--
ALTER TABLE `stock_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_locations`
--
ALTER TABLE `stock_locations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_receipts`
--
ALTER TABLE `stock_receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `supplier_carpet_payments`
--
ALTER TABLE `supplier_carpet_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supply_purchases`
--
ALTER TABLE `supply_purchases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supply_purchase_lines`
--
ALTER TABLE `supply_purchase_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_customer_receipts`
--
ALTER TABLE `trade_customer_receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_sales`
--
ALTER TABLE `trade_sales`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trade_sale_items`
--
ALTER TABLE `trade_sale_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `washing_bills`
--
ALTER TABLE `washing_bills`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `washing_bill_allocations`
--
ALTER TABLE `washing_bill_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `washing_bill_voids`
--
ALTER TABLE `washing_bill_voids`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `washing_facilities`
--
ALTER TABLE `washing_facilities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `washing_orders`
--
ALTER TABLE `washing_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `washing_order_items`
--
ALTER TABLE `washing_order_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `washing_payments`
--
ALTER TABLE `washing_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weavers`
--
ALTER TABLE `weavers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `weaver_payments`
--
ALTER TABLE `weaver_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weaver_payment_allocations`
--
ALTER TABLE `weaver_payment_allocations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weaver_purchases`
--
ALTER TABLE `weaver_purchases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weaver_purchase_items`
--
ALTER TABLE `weaver_purchase_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weaver_refunds`
--
ALTER TABLE `weaver_refunds`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weaver_returns`
--
ALTER TABLE `weaver_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `weaver_return_items`
--
ALTER TABLE `weaver_return_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `worker_assistance`
--
ALTER TABLE `worker_assistance`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `worker_assistance_deliveries`
--
ALTER TABLE `worker_assistance_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `worker_assistance_fundings`
--
ALTER TABLE `worker_assistance_fundings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `worker_assistance_recoveries`
--
ALTER TABLE `worker_assistance_recoveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `worker_assistance_returns`
--
ALTER TABLE `worker_assistance_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `fk_acc_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_acc_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `account_transactions`
--
ALTER TABLE `account_transactions`
  ADD CONSTRAINT `account_transactions_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `fk_acctx_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_acctx_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_acctx_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `areas`
--
ALTER TABLE `areas`
  ADD CONSTRAINT `fk_phase2_areas_district_id` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `branding_images`
--
ALTER TABLE `branding_images`
  ADD CONSTRAINT `branding_images_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `business_voids`
--
ALTER TABLE `business_voids`
  ADD CONSTRAINT `business_voids_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `business_voids_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `operating_expenses` (`id`);

--
-- Constraints for table `carpets`
--
ALTER TABLE `carpets`
  ADD CONSTRAINT `carpets_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `carpet_categories` (`id`),
  ADD CONSTRAINT `carpets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `carpets_registration_branch_id_foreign` FOREIGN KEY (`registration_branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `carpets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `carpet_images`
--
ALTER TABLE `carpet_images`
  ADD CONSTRAINT `carpet_images_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `carpet_images_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `carpet_purchases`
--
ALTER TABLE `carpet_purchases`
  ADD CONSTRAINT `fk_cpur_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cpur_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cpur_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_cpur_user` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cpur_weaver` FOREIGN KEY (`weaver_id`) REFERENCES `weavers` (`id`);

--
-- Constraints for table `carpet_purchase_items`
--
ALTER TABLE `carpet_purchase_items`
  ADD CONSTRAINT `fk_cpi_carpet` FOREIGN KEY (`carpet_id`) REFERENCES `legacy_carpets` (`id`),
  ADD CONSTRAINT `fk_cpi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `carpet_purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD CONSTRAINT `company_settings_base_currency_id_foreign` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `company_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_cust_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD CONSTRAINT `fk_cpay_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `fk_cpay_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cpay_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_cpay_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_cpay_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `fk_cpay_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `payment_schedules` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cpay_user` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `districts`
--
ALTER TABLE `districts`
  ADD CONSTRAINT `fk_phase2_districts_province_id` FOREIGN KEY (`province_id`) REFERENCES `provinces` (`id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_emp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  ADD CONSTRAINT `fk_fx_base` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_fx_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fx_quote` FOREIGN KEY (`quote_currency_id`) REFERENCES `currencies` (`id`);

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_exp_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `fk_exp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_category` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  ADD CONSTRAINT `fk_exp_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_exp_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_exp_employee` FOREIGN KEY (`responsible_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expense_attachments`
--
ALTER TABLE `expense_attachments`
  ADD CONSTRAINT `expense_attachments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `expense_attachments_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `operating_expenses` (`id`);

--
-- Constraints for table `inventory_commands`
--
ALTER TABLE `inventory_commands`
  ADD CONSTRAINT `inventory_commands_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `legacy_carpets`
--
ALTER TABLE `legacy_carpets`
  ADD CONSTRAINT `fk_cp_branch` FOREIGN KEY (`current_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cp_category` FOREIGN KEY (`category_id`) REFERENCES `carpet_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cp_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cp_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_cp_weaver` FOREIGN KEY (`weaver_id`) REFERENCES `weavers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `legacy_washing_orders`
--
ALTER TABLE `legacy_washing_orders`
  ADD CONSTRAINT `fk_wo_carpet` FOREIGN KEY (`carpet_id`) REFERENCES `legacy_carpets` (`id`),
  ADD CONSTRAINT `fk_wo_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wo_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_wo_facility` FOREIGN KEY (`washing_facility_id`) REFERENCES `washing_facilities` (`id`),
  ADD CONSTRAINT `fk_wo_user` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `legacy_weaver_payments`
--
ALTER TABLE `legacy_weaver_payments`
  ADD CONSTRAINT `fk_wpay_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `fk_wpay_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wpay_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_wpay_purchase` FOREIGN KEY (`carpet_purchase_id`) REFERENCES `carpet_purchases` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wpay_user` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wpay_weaver` FOREIGN KEY (`weaver_id`) REFERENCES `weavers` (`id`);

--
-- Constraints for table `manager_delivery_acceptances`
--
ALTER TABLE `manager_delivery_acceptances`
  ADD CONSTRAINT `manager_delivery_acceptances_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_delivery_acceptances_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `manager_delivery_items` (`id`),
  ADD CONSTRAINT `manager_delivery_acceptances_washing_order_item_id_foreign` FOREIGN KEY (`washing_order_item_id`) REFERENCES `washing_order_items` (`id`);

--
-- Constraints for table `manager_delivery_items`
--
ALTER TABLE `manager_delivery_items`
  ADD CONSTRAINT `manager_delivery_items_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `manager_delivery_items_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_delivery_items_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `manager_production_deliveries` (`id`),
  ADD CONSTRAINT `manager_delivery_items_line_id_foreign` FOREIGN KEY (`line_id`) REFERENCES `manager_production_lines` (`id`),
  ADD CONSTRAINT `manager_delivery_items_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_delivery_materials`
--
ALTER TABLE `manager_delivery_materials`
  ADD CONSTRAINT `manager_delivery_materials_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_delivery_materials_delivery_id_foreign` FOREIGN KEY (`delivery_id`) REFERENCES `manager_production_deliveries` (`id`),
  ADD CONSTRAINT `manager_delivery_materials_movement_id_foreign` FOREIGN KEY (`movement_id`) REFERENCES `manager_material_movements` (`id`);

--
-- Constraints for table `manager_earned_charges`
--
ALTER TABLE `manager_earned_charges`
  ADD CONSTRAINT `manager_earned_charges_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_earned_charges_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `manager_delivery_items` (`id`),
  ADD CONSTRAINT `manager_earned_charges_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_material_movements`
--
ALTER TABLE `manager_material_movements`
  ADD CONSTRAINT `manager_material_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_material_movements_lot_id_foreign` FOREIGN KEY (`lot_id`) REFERENCES `material_lots` (`id`),
  ADD CONSTRAINT `manager_material_movements_manager_id_foreign` FOREIGN KEY (`manager_id`) REFERENCES `production_managers` (`id`),
  ADD CONSTRAINT `manager_material_movements_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_pricing_factors`
--
ALTER TABLE `manager_pricing_factors`
  ADD CONSTRAINT `manager_pricing_factors_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_pricing_factors_line_id_foreign` FOREIGN KEY (`line_id`) REFERENCES `manager_production_lines` (`id`),
  ADD CONSTRAINT `manager_pricing_factors_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_production_closures`
--
ALTER TABLE `manager_production_closures`
  ADD CONSTRAINT `manager_production_closures_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_production_closures_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_production_deliveries`
--
ALTER TABLE `manager_production_deliveries`
  ADD CONSTRAINT `manager_production_deliveries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_production_deliveries_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_production_lines`
--
ALTER TABLE `manager_production_lines`
  ADD CONSTRAINT `manager_production_lines_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `carpet_categories` (`id`),
  ADD CONSTRAINT `manager_production_lines_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_production_lines_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_production_orders`
--
ALTER TABLE `manager_production_orders`
  ADD CONSTRAINT `manager_production_orders_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `manager_production_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_production_orders_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `manager_production_orders_manager_id_foreign` FOREIGN KEY (`manager_id`) REFERENCES `production_managers` (`id`);

--
-- Constraints for table `manager_production_payments`
--
ALTER TABLE `manager_production_payments`
  ADD CONSTRAINT `manager_production_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `manager_production_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_production_payments_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `manager_tool_movements`
--
ALTER TABLE `manager_tool_movements`
  ADD CONSTRAINT `manager_tool_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manager_tool_movements_lot_id_foreign` FOREIGN KEY (`lot_id`) REFERENCES `production_tool_lots` (`id`),
  ADD CONSTRAINT `manager_tool_movements_manager_id_foreign` FOREIGN KEY (`manager_id`) REFERENCES `production_managers` (`id`),
  ADD CONSTRAINT `manager_tool_movements_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `manager_production_orders` (`id`);

--
-- Constraints for table `materials`
--
ALTER TABLE `materials`
  ADD CONSTRAINT `materials_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `material_lots`
--
ALTER TABLE `material_lots`
  ADD CONSTRAINT `material_lots_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `material_lots_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `material_lots_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `material_lots_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `material_lots_material_id_foreign` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  ADD CONSTRAINT `material_lots_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `material_lot_payments`
--
ALTER TABLE `material_lot_payments`
  ADD CONSTRAINT `material_lot_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `material_lot_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `material_lot_payments_lot_id_foreign` FOREIGN KEY (`lot_id`) REFERENCES `material_lots` (`id`);

--
-- Constraints for table `material_movements`
--
ALTER TABLE `material_movements`
  ADD CONSTRAINT `material_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `material_movements_lot_id_foreign` FOREIGN KEY (`lot_id`) REFERENCES `material_lots` (`id`),
  ADD CONSTRAINT `material_movements_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `operating_expenses`
--
ALTER TABLE `operating_expenses`
  ADD CONSTRAINT `operating_expenses_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `operating_expenses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `operating_expenses_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `operating_expenses_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`);

--
-- Constraints for table `operating_expense_payments`
--
ALTER TABLE `operating_expense_payments`
  ADD CONSTRAINT `operating_expense_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `operating_expense_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `operating_expense_payments_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `operating_expenses` (`id`);

--
-- Constraints for table `partnership_contributions`
--
ALTER TABLE `partnership_contributions`
  ADD CONSTRAINT `partnership_contributions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `partnership_contributions_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `partnership_settlements`
--
ALTER TABLE `partnership_settlements`
  ADD CONSTRAINT `partnership_settlements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `partnership_settlements_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `partnership_settlement_payments`
--
ALTER TABLE `partnership_settlement_payments`
  ADD CONSTRAINT `partnership_settlement_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `partnership_settlement_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `partnership_settlement_payments_settlement_id_foreign` FOREIGN KEY (`settlement_id`) REFERENCES `partnership_settlements` (`id`);

--
-- Constraints for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  ADD CONSTRAINT `fk_ps_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_carpets`
--
ALTER TABLE `production_carpets`
  ADD CONSTRAINT `production_carpets_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `production_carpets_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `production_charges`
--
ALTER TABLE `production_charges`
  ADD CONSTRAINT `production_charges_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_charges_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `production_managers`
--
ALTER TABLE `production_managers`
  ADD CONSTRAINT `production_managers_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `production_managers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_managers_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `production_orders`
--
ALTER TABLE `production_orders`
  ADD CONSTRAINT `production_orders_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `production_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_orders_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `production_orders_weaver_id_foreign` FOREIGN KEY (`weaver_id`) REFERENCES `weavers` (`id`);

--
-- Constraints for table `production_payments`
--
ALTER TABLE `production_payments`
  ADD CONSTRAINT `production_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `production_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_payments_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `production_progress`
--
ALTER TABLE `production_progress`
  ADD CONSTRAINT `production_progress_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_progress_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `production_receipts`
--
ALTER TABLE `production_receipts`
  ADD CONSTRAINT `production_receipts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_receipts_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `production_receipts_production_order_id_foreign` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`);

--
-- Constraints for table `production_receipt_items`
--
ALTER TABLE `production_receipt_items`
  ADD CONSTRAINT `production_receipt_items_production_carpet_id_foreign` FOREIGN KEY (`production_carpet_id`) REFERENCES `production_carpets` (`id`),
  ADD CONSTRAINT `production_receipt_items_production_receipt_id_foreign` FOREIGN KEY (`production_receipt_id`) REFERENCES `production_receipts` (`id`);

--
-- Constraints for table `production_tools`
--
ALTER TABLE `production_tools`
  ADD CONSTRAINT `production_tools_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `production_tool_lots`
--
ALTER TABLE `production_tool_lots`
  ADD CONSTRAINT `production_tool_lots_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `production_tool_lots_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_tool_lots_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `production_tool_lots_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `production_tool_lots_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `production_tool_lots_tool_id_foreign` FOREIGN KEY (`tool_id`) REFERENCES `production_tools` (`id`);

--
-- Constraints for table `production_tool_payments`
--
ALTER TABLE `production_tool_payments`
  ADD CONSTRAINT `production_tool_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `production_tool_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_tool_payments_lot_id_foreign` FOREIGN KEY (`lot_id`) REFERENCES `production_tool_lots` (`id`);

--
-- Constraints for table `production_workflow_defaults`
--
ALTER TABLE `production_workflow_defaults`
  ADD CONSTRAINT `production_workflow_defaults_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `production_workflow_defaults_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `production_workflow_defaults_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `washing_facilities` (`id`),
  ADD CONSTRAINT `production_workflow_defaults_facility_location_id_foreign` FOREIGN KEY (`facility_location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `production_workflow_defaults_finished_location_id_foreign` FOREIGN KEY (`finished_location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `production_workflow_defaults_materials_location_id_foreign` FOREIGN KEY (`materials_location_id`) REFERENCES `stock_locations` (`id`);

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `fk_pur_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE NO ACTION,
  ADD CONSTRAINT `fk_pur_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pur_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pur_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_pur_facility` FOREIGN KEY (`washing_facility_id`) REFERENCES `washing_facilities` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_pur_user` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_drafts`
--
ALTER TABLE `purchase_drafts`
  ADD CONSTRAINT `purchase_drafts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `purchase_drafts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `purchase_drafts_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `purchase_drafts_posted_purchase_id_foreign` FOREIGN KEY (`posted_purchase_id`) REFERENCES `weaver_purchases` (`id`),
  ADD CONSTRAINT `purchase_drafts_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `purchase_drafts_weaver_id_foreign` FOREIGN KEY (`weaver_id`) REFERENCES `weavers` (`id`);

--
-- Constraints for table `purchase_draft_items`
--
ALTER TABLE `purchase_draft_items`
  ADD CONSTRAINT `purchase_draft_items_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `purchase_draft_items_purchase_draft_id_foreign` FOREIGN KEY (`purchase_draft_id`) REFERENCES `purchase_drafts` (`id`);

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_voids`
--
ALTER TABLE `purchase_voids`
  ADD CONSTRAINT `purchase_voids_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `purchase_voids_weaver_purchase_id_foreign` FOREIGN KEY (`weaver_purchase_id`) REFERENCES `weaver_purchases` (`id`);

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sale_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sale_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `fk_sale_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `fk_sale_seller` FOREIGN KEY (`salesperson_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_si_carpet` FOREIGN KEY (`carpet_id`) REFERENCES `legacy_carpets` (`id`),
  ADD CONSTRAINT `fk_si_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD CONSTRAINT `stock_adjustments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `stock_adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `stock_adjustments_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `stock_locations` (`id`);

--
-- Constraints for table `stock_events`
--
ALTER TABLE `stock_events`
  ADD CONSTRAINT `stock_events_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `stock_events_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `stock_events_manager_delivery_item_id_foreign` FOREIGN KEY (`manager_delivery_item_id`) REFERENCES `manager_delivery_items` (`id`),
  ADD CONSTRAINT `stock_events_production_receipt_item_id_foreign` FOREIGN KEY (`production_receipt_item_id`) REFERENCES `production_receipt_items` (`id`),
  ADD CONSTRAINT `stock_events_purchase_void_id_foreign` FOREIGN KEY (`purchase_void_id`) REFERENCES `purchase_voids` (`id`),
  ADD CONSTRAINT `stock_events_resulting_location_id_foreign` FOREIGN KEY (`resulting_location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `stock_events_stock_adjustment_id_foreign` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`id`),
  ADD CONSTRAINT `stock_events_stock_receipt_id_foreign` FOREIGN KEY (`stock_receipt_id`) REFERENCES `stock_receipts` (`id`),
  ADD CONSTRAINT `stock_events_stock_transfer_id_foreign` FOREIGN KEY (`stock_transfer_id`) REFERENCES `stock_transfers` (`id`),
  ADD CONSTRAINT `stock_events_trade_sale_item_id_foreign` FOREIGN KEY (`trade_sale_item_id`) REFERENCES `trade_sale_items` (`id`),
  ADD CONSTRAINT `stock_events_washing_order_item_id_foreign` FOREIGN KEY (`washing_order_item_id`) REFERENCES `washing_order_items` (`id`),
  ADD CONSTRAINT `stock_events_weaver_purchase_item_id_foreign` FOREIGN KEY (`weaver_purchase_item_id`) REFERENCES `weaver_purchase_items` (`id`),
  ADD CONSTRAINT `stock_events_weaver_return_item_id_foreign` FOREIGN KEY (`weaver_return_item_id`) REFERENCES `weaver_return_items` (`id`);

--
-- Constraints for table `stock_locations`
--
ALTER TABLE `stock_locations`
  ADD CONSTRAINT `stock_locations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `stock_locations_washing_facility_id_foreign` FOREIGN KEY (`washing_facility_id`) REFERENCES `washing_facilities` (`id`);

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_mv_carpet` FOREIGN KEY (`carpet_id`) REFERENCES `legacy_carpets` (`id`),
  ADD CONSTRAINT `fk_mv_from` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_mv_handler` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_mv_to` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `stock_receipts`
--
ALTER TABLE `stock_receipts`
  ADD CONSTRAINT `stock_receipts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `stock_receipts_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `stock_locations` (`id`);

--
-- Constraints for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD CONSTRAINT `stock_transfers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `stock_transfers_from_location_id_foreign` FOREIGN KEY (`from_location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `stock_transfers_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `stock_locations` (`id`);

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `fk_phase2_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `supplier_carpet_payments`
--
ALTER TABLE `supplier_carpet_payments`
  ADD CONSTRAINT `supplier_carpet_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `supplier_carpet_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `supplier_carpet_payments_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `weaver_purchases` (`id`);

--
-- Constraints for table `supply_purchases`
--
ALTER TABLE `supply_purchases`
  ADD CONSTRAINT `supply_purchases_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `supply_purchases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `supply_purchases_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `supply_purchases_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `supply_purchases_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `supply_purchase_lines`
--
ALTER TABLE `supply_purchase_lines`
  ADD CONSTRAINT `supply_purchase_lines_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `supply_purchase_lines_material_lot_id_foreign` FOREIGN KEY (`material_lot_id`) REFERENCES `material_lots` (`id`),
  ADD CONSTRAINT `supply_purchase_lines_purchase_id_foreign` FOREIGN KEY (`purchase_id`) REFERENCES `supply_purchases` (`id`),
  ADD CONSTRAINT `supply_purchase_lines_tool_lot_id_foreign` FOREIGN KEY (`tool_lot_id`) REFERENCES `production_tool_lots` (`id`);

--
-- Constraints for table `trade_customer_receipts`
--
ALTER TABLE `trade_customer_receipts`
  ADD CONSTRAINT `trade_customer_receipts_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `trade_customer_receipts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `trade_customer_receipts_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `trade_sales` (`id`);

--
-- Constraints for table `trade_sales`
--
ALTER TABLE `trade_sales`
  ADD CONSTRAINT `trade_sales_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `trade_sales_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `trade_sales_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `trade_sales_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`);

--
-- Constraints for table `trade_sale_items`
--
ALTER TABLE `trade_sale_items`
  ADD CONSTRAINT `trade_sale_items_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `trade_sale_items_sale_id_foreign` FOREIGN KEY (`sale_id`) REFERENCES `trade_sales` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_accounts`
--
ALTER TABLE `user_accounts`
  ADD CONSTRAINT `user_accounts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `user_accounts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_branches`
--
ALTER TABLE `user_branches`
  ADD CONSTRAINT `fk_ub_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_washing_facilities`
--
ALTER TABLE `user_washing_facilities`
  ADD CONSTRAINT `user_washing_facilities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `user_washing_facilities_washing_facility_id_foreign` FOREIGN KEY (`washing_facility_id`) REFERENCES `washing_facilities` (`id`);

--
-- Constraints for table `washing_bills`
--
ALTER TABLE `washing_bills`
  ADD CONSTRAINT `washing_bills_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `washing_bills_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `washing_bills_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `washing_bills_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `washing_facilities` (`id`);

--
-- Constraints for table `washing_bill_allocations`
--
ALTER TABLE `washing_bill_allocations`
  ADD CONSTRAINT `washing_bill_allocations_washing_bill_id_foreign` FOREIGN KEY (`washing_bill_id`) REFERENCES `washing_bills` (`id`),
  ADD CONSTRAINT `washing_bill_allocations_washing_order_item_id_foreign` FOREIGN KEY (`washing_order_item_id`) REFERENCES `washing_order_items` (`id`);

--
-- Constraints for table `washing_bill_voids`
--
ALTER TABLE `washing_bill_voids`
  ADD CONSTRAINT `washing_bill_voids_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `washing_bill_voids_washing_bill_id_foreign` FOREIGN KEY (`washing_bill_id`) REFERENCES `washing_bills` (`id`);

--
-- Constraints for table `washing_orders`
--
ALTER TABLE `washing_orders`
  ADD CONSTRAINT `washing_orders_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `washing_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `washing_orders_facility_id_foreign` FOREIGN KEY (`facility_id`) REFERENCES `washing_facilities` (`id`),
  ADD CONSTRAINT `washing_orders_facility_location_id_foreign` FOREIGN KEY (`facility_location_id`) REFERENCES `stock_locations` (`id`);

--
-- Constraints for table `washing_order_items`
--
ALTER TABLE `washing_order_items`
  ADD CONSTRAINT `washing_order_items_acquisition_item_id_foreign` FOREIGN KEY (`acquisition_item_id`) REFERENCES `weaver_purchase_items` (`id`),
  ADD CONSTRAINT `washing_order_items_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `washing_order_items_source_location_id_foreign` FOREIGN KEY (`source_location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `washing_order_items_washing_order_id_foreign` FOREIGN KEY (`washing_order_id`) REFERENCES `washing_orders` (`id`);

--
-- Constraints for table `washing_payments`
--
ALTER TABLE `washing_payments`
  ADD CONSTRAINT `washing_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `washing_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `washing_payments_washing_bill_id_foreign` FOREIGN KEY (`washing_bill_id`) REFERENCES `washing_bills` (`id`);

--
-- Constraints for table `weavers`
--
ALTER TABLE `weavers`
  ADD CONSTRAINT `fk_phase2_weavers_area_id` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  ADD CONSTRAINT `fk_phase2_weavers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_phase2_weavers_manager_employee_id` FOREIGN KEY (`manager_employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `weaver_payments`
--
ALTER TABLE `weaver_payments`
  ADD CONSTRAINT `weaver_payments_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `weaver_payments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `weaver_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `weaver_payments_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`),
  ADD CONSTRAINT `weaver_payments_weaver_id_foreign` FOREIGN KEY (`weaver_id`) REFERENCES `weavers` (`id`);

--
-- Constraints for table `weaver_payment_allocations`
--
ALTER TABLE `weaver_payment_allocations`
  ADD CONSTRAINT `weaver_payment_allocations_weaver_payment_id_foreign` FOREIGN KEY (`weaver_payment_id`) REFERENCES `weaver_payments` (`id`),
  ADD CONSTRAINT `weaver_payment_allocations_weaver_purchase_id_foreign` FOREIGN KEY (`weaver_purchase_id`) REFERENCES `weaver_purchases` (`id`);

--
-- Constraints for table `weaver_purchases`
--
ALTER TABLE `weaver_purchases`
  ADD CONSTRAINT `weaver_purchases_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `weaver_purchases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `weaver_purchases_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `weaver_purchases_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `weaver_purchases_weaver_id_foreign` FOREIGN KEY (`weaver_id`) REFERENCES `weavers` (`id`);

--
-- Constraints for table `weaver_purchase_items`
--
ALTER TABLE `weaver_purchase_items`
  ADD CONSTRAINT `weaver_purchase_items_carpet_id_foreign` FOREIGN KEY (`carpet_id`) REFERENCES `carpets` (`id`),
  ADD CONSTRAINT `weaver_purchase_items_weaver_purchase_id_foreign` FOREIGN KEY (`weaver_purchase_id`) REFERENCES `weaver_purchases` (`id`);

--
-- Constraints for table `weaver_refunds`
--
ALTER TABLE `weaver_refunds`
  ADD CONSTRAINT `weaver_refunds_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `weaver_refunds_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `weaver_refunds_weaver_purchase_id_foreign` FOREIGN KEY (`weaver_purchase_id`) REFERENCES `weaver_purchases` (`id`);

--
-- Constraints for table `weaver_returns`
--
ALTER TABLE `weaver_returns`
  ADD CONSTRAINT `weaver_returns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `weaver_returns_from_location_id_foreign` FOREIGN KEY (`from_location_id`) REFERENCES `stock_locations` (`id`),
  ADD CONSTRAINT `weaver_returns_weaver_purchase_id_foreign` FOREIGN KEY (`weaver_purchase_id`) REFERENCES `weaver_purchases` (`id`);

--
-- Constraints for table `weaver_return_items`
--
ALTER TABLE `weaver_return_items`
  ADD CONSTRAINT `weaver_return_items_weaver_purchase_item_id_foreign` FOREIGN KEY (`weaver_purchase_item_id`) REFERENCES `weaver_purchase_items` (`id`),
  ADD CONSTRAINT `weaver_return_items_weaver_return_id_foreign` FOREIGN KEY (`weaver_return_id`) REFERENCES `weaver_returns` (`id`);

--
-- Constraints for table `worker_assistance`
--
ALTER TABLE `worker_assistance`
  ADD CONSTRAINT `worker_assistance_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  ADD CONSTRAINT `worker_assistance_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `worker_assistance_currency_id_foreign` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`),
  ADD CONSTRAINT `worker_assistance_manager_id_foreign` FOREIGN KEY (`manager_id`) REFERENCES `production_managers` (`id`);

--
-- Constraints for table `worker_assistance_deliveries`
--
ALTER TABLE `worker_assistance_deliveries`
  ADD CONSTRAINT `worker_assistance_deliveries_assistance_id_foreign` FOREIGN KEY (`assistance_id`) REFERENCES `worker_assistance` (`id`),
  ADD CONSTRAINT `worker_assistance_deliveries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `worker_assistance_fundings`
--
ALTER TABLE `worker_assistance_fundings`
  ADD CONSTRAINT `worker_assistance_fundings_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `worker_assistance_fundings_assistance_id_foreign` FOREIGN KEY (`assistance_id`) REFERENCES `worker_assistance` (`id`),
  ADD CONSTRAINT `worker_assistance_fundings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `worker_assistance_recoveries`
--
ALTER TABLE `worker_assistance_recoveries`
  ADD CONSTRAINT `worker_assistance_recoveries_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `worker_assistance_recoveries_assistance_id_foreign` FOREIGN KEY (`assistance_id`) REFERENCES `worker_assistance` (`id`),
  ADD CONSTRAINT `worker_assistance_recoveries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `worker_assistance_returns`
--
ALTER TABLE `worker_assistance_returns`
  ADD CONSTRAINT `worker_assistance_returns_account_transaction_id_foreign` FOREIGN KEY (`account_transaction_id`) REFERENCES `account_transactions` (`id`),
  ADD CONSTRAINT `worker_assistance_returns_assistance_id_foreign` FOREIGN KEY (`assistance_id`) REFERENCES `worker_assistance` (`id`),
  ADD CONSTRAINT `worker_assistance_returns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
