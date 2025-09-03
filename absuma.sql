-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 03, 2025 at 08:14 PM
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
-- Database: `absuma`
--

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `client_code` varchar(20) DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `contact_person` varchar(100) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email_address` varchar(100) DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `billing_cycle_days` int(11) DEFAULT 30,
  `pan_number` varchar(20) DEFAULT NULL,
  `gst_number` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_contacts`
--

CREATE TABLE `client_contacts` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `contact_name` varchar(100) NOT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email_address` varchar(100) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_documents`
--

CREATE TABLE `client_documents` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_type` varchar(20) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_document_alerts`
--

CREATE TABLE `client_document_alerts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `client_name` varchar(255) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `expiry_date` date NOT NULL,
  `days_remaining` int(11) NOT NULL,
  `alert_level` enum('normal','warning','critical','expired') NOT NULL DEFAULT 'normal',
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_rates`
--

CREATE TABLE `client_rates` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `container_size` enum('20ft','40ft') NOT NULL,
  `movement_type` enum('export','import','domestic') NOT NULL,
  `from_location` varchar(255) DEFAULT NULL,
  `to_location` varchar(255) DEFAULT NULL,
  `rate` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_alerts`
--

CREATE TABLE `document_alerts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `expiry_date` date NOT NULL,
  `days_remaining` int(11) NOT NULL,
  `alert_level` enum('normal','warning','critical','expired') GENERATED ALWAYS AS (case when `days_remaining` < 0 then 'expired' when `days_remaining` <= 7 then 'critical' when `days_remaining` <= 30 then 'warning' else 'normal' end) STORED,
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `name`, `vehicle_number`, `license_number`, `mobile`, `address`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Raju', 'MH46CU2902', NULL, NULL, NULL, 'Active', '2025-09-03 16:32:45', NULL),
(2, 'Pawan', 'MH46CU2903', NULL, NULL, NULL, 'Active', '2025-09-03 16:33:24', NULL),
(5, 'Guddu', 'MH46BF3412', NULL, NULL, NULL, 'Active', '2025-09-03 16:37:58', NULL),
(8, 'Ram', 'MH46CU2888', NULL, NULL, NULL, 'Active', '2025-09-03 17:36:17', '2025-09-03 17:36:30');

-- --------------------------------------------------------

--
-- Table structure for table `driver_documents`
--

CREATE TABLE `driver_documents` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `document_number` varchar(100) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `is_expired` tinyint(1) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `driver_documents`
--

INSERT INTO `driver_documents` (`id`, `driver_id`, `document_type`, `document_number`, `file_path`, `issue_date`, `expiry_date`, `is_expired`, `uploaded_at`, `updated_at`, `deleted_at`) VALUES
(1, 5, 'Aadhar Card', NULL, '68b87b68a83cf_0126 urea 0039.jpeg', NULL, NULL, 0, '2025-09-03 17:31:20', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `driver_document_alerts`
--

CREATE TABLE `driver_document_alerts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `driver_id` int(11) NOT NULL,
  `driver_name` varchar(100) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `expiry_date` date NOT NULL,
  `days_remaining` int(11) NOT NULL,
  `alert_level` enum('normal','warning','critical','expired') GENERATED ALWAYS AS (case when `days_remaining` < 0 then 'expired' when `days_remaining` <= 7 then 'critical' when `days_remaining` <= 30 then 'warning' else 'normal' end) STORED,
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trips`
--

CREATE TABLE `trips` (
  `id` int(11) NOT NULL,
  `reference_number` varchar(30) NOT NULL,
  `trip_date` date NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `vehicle_type` enum('owned','vendor') NOT NULL DEFAULT 'owned',
  `movement_type` enum('export','import','domestic') NOT NULL,
  `client_id` int(11) NOT NULL,
  `container_type` enum('full','empty') NOT NULL,
  `container_size` enum('20ft','40ft') NOT NULL,
  `vessel_name` varchar(100) DEFAULT NULL,
  `from_location` varchar(255) NOT NULL,
  `to_location` varchar(255) NOT NULL,
  `vendor_rate` decimal(10,2) DEFAULT NULL,
  `is_vendor_vehicle` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trip_containers`
--

CREATE TABLE `trip_containers` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `container_number` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','manager','staff') NOT NULL DEFAULT 'staff',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$ZbJFybbwv.OK0E/eko2rmO1RtVwFzMgiFz6hCHjVa8SF/QDzPKRNW', 'System Administrator', 'admin@absuma.com', 'admin', 'active', '2025-09-03 16:25:47', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` varchar(50) NOT NULL,
  `activity_description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_activity_logs`
--

INSERT INTO `user_activity_logs` (`id`, `user_id`, `activity_type`, `activity_description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'vendor_approval', 'Approved vendor: ANSH TRANSPORT (Code: VND000001)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36 Edg/139.0.0.0', '2025-09-03 17:47:24');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `owner_name` varchar(100) DEFAULT NULL,
  `make_model` varchar(100) DEFAULT NULL,
  `manufacturing_year` year(4) DEFAULT NULL,
  `gvw` decimal(10,2) DEFAULT NULL,
  `is_financed` tinyint(1) NOT NULL DEFAULT 0,
  `current_status` enum('available','on_trip','maintenance','inactive') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `vehicle_number`, `driver_name`, `owner_name`, `make_model`, `manufacturing_year`, `gvw`, `is_financed`, `current_status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'MH46CU2902', 'Raju', 'ABSUMA LOGISTICS INDIA PVT LTD', 'Tata 4623', '2024', 45500.00, 0, 'available', '2025-09-03 16:32:45', NULL, NULL),
(2, 'MH46CU2903', 'Pawan', 'ABSUMA LOGISTICS INDIA PVT LTD', 'Tata 4623', '2024', 45500.00, 0, 'available', '2025-09-03 16:33:24', NULL, NULL),
(3, 'MH46CU2888', 'Ram', 'ABSUMA LOGISTICS INDIA PVT LTD', 'Tata 4623', '2024', 45500.00, 1, 'available', '2025-09-03 16:33:52', '2025-09-03 17:36:17', NULL),
(4, 'MH46BF3412', 'Guddu', 'ABSUMA LOGISTICS INDIA PVT LTD', 'AL 3518', '2018', 35200.00, 1, 'available', '2025-09-03 16:37:58', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_documents`
--

CREATE TABLE `vehicle_documents` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `document_number` varchar(100) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `is_expired` tinyint(1) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_financing`
--

CREATE TABLE `vehicle_financing` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `is_financed` tinyint(1) NOT NULL DEFAULT 0,
  `bank_name` varchar(100) DEFAULT NULL,
  `loan_amount` decimal(12,2) DEFAULT NULL,
  `interest_rate` decimal(5,2) DEFAULT NULL,
  `loan_tenure_months` int(11) DEFAULT NULL,
  `emi_amount` decimal(10,2) DEFAULT NULL,
  `loan_start_date` date DEFAULT NULL,
  `loan_end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicle_financing`
--

INSERT INTO `vehicle_financing` (`id`, `vehicle_id`, `is_financed`, `bank_name`, `loan_amount`, `interest_rate`, `loan_tenure_months`, `emi_amount`, `loan_start_date`, `loan_end_date`, `created_at`, `updated_at`) VALUES
(1, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-03 16:32:45', NULL),
(2, 2, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-03 16:33:24', NULL),
(3, 3, 1, 'HDFC BANK', 2750000.00, 11.00, 60, 65200.00, '2025-04-01', '2030-04-01', '2025-09-03 16:33:52', '2025-09-03 16:36:17'),
(4, 4, 1, 'ICICI BANK', 3100000.00, 11.00, 48, 72800.00, '2024-05-10', '2028-05-10', '2025-09-03 16:37:58', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `id` int(11) NOT NULL,
  `vendor_code` varchar(20) DEFAULT NULL,
  `company_name` varchar(255) NOT NULL,
  `registered_address` text NOT NULL,
  `invoice_address` text DEFAULT NULL,
  `contact_person` varchar(100) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `constitution_type` varchar(50) DEFAULT NULL,
  `business_nature` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `ifsc_code` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) NOT NULL,
  `gst_number` varchar(20) NOT NULL,
  `state` varchar(50) DEFAULT NULL,
  `state_code` varchar(10) DEFAULT NULL,
  `vendor_type` enum('transporter','owner_cum_driver','fleet_owner') NOT NULL,
  `total_vehicles` int(11) DEFAULT 0,
  `status` enum('pending','active','inactive','suspended') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vendors`
--

INSERT INTO `vendors` (`id`, `vendor_code`, `company_name`, `registered_address`, `invoice_address`, `contact_person`, `mobile`, `email`, `constitution_type`, `business_nature`, `bank_name`, `account_number`, `branch_name`, `ifsc_code`, `pan_number`, `gst_number`, `state`, `state_code`, `vendor_type`, `total_vehicles`, `status`, `approved_by`, `approved_at`, `rejected_by`, `rejected_at`, `rejection_reason`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'VND000001', 'ANSH TRANSPORT', 'BGTA Bldg, 3rd Floor, Off No.302, Plot No.04, Sec-11, Navi Mumbai', 'BGTA Bldg, 3rd Floor, Off No.302, Plot No.04, Sec-11, Navi Mumbai', 'Pradeep', '8171334593', 'pradeep@anshtrans.in', 'Sole Proprietor', 'Logistics Provider', 'IDBI BANK', '0238102000006477', 'Raigad, Navi Mumbai', 'IBKL0000238', 'CITPK2358G', '27CITPK2358G1ZE', 'Maharashtra', 'MH', '', 2, 'active', 1, '2025-09-03 17:47:24', NULL, NULL, NULL, 1, '2025-09-03 14:11:04', '2025-09-03 17:47:24', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vendor_documents`
--

CREATE TABLE `vendor_documents` (
  `id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_type` varchar(20) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendor_vehicles`
--

CREATE TABLE `vendor_vehicles` (
  `id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `make` varchar(50) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_license` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vendor_vehicles`
--

INSERT INTO `vendor_vehicles` (`id`, `vendor_id`, `vehicle_number`, `make`, `model`, `driver_name`, `driver_license`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 'MH06AQ5447', NULL, NULL, NULL, NULL, 'active', '2025-09-03 17:41:04', NULL, NULL),
(2, 1, 'MH46H1793', NULL, NULL, NULL, NULL, 'active', '2025-09-03 17:41:04', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `client_code` (`client_code`),
  ADD KEY `idx_client_code` (`client_code`),
  ADD KEY `idx_client_name` (`client_name`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_deleted` (`deleted_at`);

--
-- Indexes for table `client_contacts`
--
ALTER TABLE `client_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_id` (`client_id`),
  ADD KEY `idx_is_primary` (`is_primary`),
  ADD KEY `idx_deleted` (`deleted_at`);

--
-- Indexes for table `client_documents`
--
ALTER TABLE `client_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_id` (`client_id`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_is_verified` (`is_verified`),
  ADD KEY `idx_deleted` (`deleted_at`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `client_document_alerts`
--
ALTER TABLE `client_document_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_client_id` (`client_id`),
  ADD KEY `idx_client_name` (`client_name`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_expiry_date` (`expiry_date`),
  ADD KEY `idx_days_remaining` (`days_remaining`),
  ADD KEY `idx_alert_level` (`alert_level`),
  ADD KEY `idx_is_dismissed` (`is_dismissed`);

--
-- Indexes for table `client_rates`
--
ALTER TABLE `client_rates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_id` (`client_id`),
  ADD KEY `idx_container_size` (`container_size`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `document_alerts`
--
ALTER TABLE `document_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_vehicle_number` (`vehicle_number`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_expiry_date` (`expiry_date`),
  ADD KEY `idx_days_remaining` (`days_remaining`),
  ADD KEY `idx_alert_level` (`alert_level`),
  ADD KEY `idx_is_dismissed` (`is_dismissed`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_number` (`vehicle_number`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `driver_documents`
--
ALTER TABLE `driver_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_expiry_date` (`expiry_date`),
  ADD KEY `idx_is_expired` (`is_expired`),
  ADD KEY `idx_deleted` (`deleted_at`);

--
-- Indexes for table `driver_document_alerts`
--
ALTER TABLE `driver_document_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_driver_id` (`driver_id`),
  ADD KEY `idx_driver_name` (`driver_name`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_expiry_date` (`expiry_date`),
  ADD KEY `idx_days_remaining` (`days_remaining`),
  ADD KEY `idx_alert_level` (`alert_level`),
  ADD KEY `idx_is_dismissed` (`is_dismissed`);

--
-- Indexes for table `trips`
--
ALTER TABLE `trips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `idx_reference_number` (`reference_number`),
  ADD KEY `idx_trip_date` (`trip_date`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_vehicle_type` (`vehicle_type`),
  ADD KEY `idx_client_id` (`client_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_trips_date_status` (`trip_date`,`status`);

--
-- Indexes for table `trip_containers`
--
ALTER TABLE `trip_containers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_trip_id` (`trip_id`),
  ADD KEY `idx_container_number` (`container_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vehicle_number` (`vehicle_number`),
  ADD KEY `idx_vehicle_number` (`vehicle_number`),
  ADD KEY `idx_current_status` (`current_status`),
  ADD KEY `idx_deleted` (`deleted_at`);

--
-- Indexes for table `vehicle_documents`
--
ALTER TABLE `vehicle_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_vehicle_number` (`vehicle_number`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_expiry_date` (`expiry_date`),
  ADD KEY `idx_is_expired` (`is_expired`),
  ADD KEY `idx_deleted` (`deleted_at`);

--
-- Indexes for table `vehicle_financing`
--
ALTER TABLE `vehicle_financing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_vehicle_financing` (`vehicle_id`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `pan_number` (`pan_number`),
  ADD UNIQUE KEY `gst_number` (`gst_number`),
  ADD UNIQUE KEY `vendor_code` (`vendor_code`),
  ADD KEY `idx_vendor_code` (`vendor_code`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_pan` (`pan_number`),
  ADD KEY `idx_gst` (`gst_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_deleted` (`deleted_at`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_vendors_company_name` (`company_name`),
  ADD KEY `idx_vendors_approved_by` (`approved_by`),
  ADD KEY `idx_vendors_rejected_by` (`rejected_by`),
  ADD KEY `idx_vendors_approved_at` (`approved_at`),
  ADD KEY `idx_vendors_rejected_at` (`rejected_at`);

--
-- Indexes for table `vendor_documents`
--
ALTER TABLE `vendor_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vendor_id` (`vendor_id`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_is_verified` (`is_verified`),
  ADD KEY `idx_deleted` (`deleted_at`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `vendor_vehicles`
--
ALTER TABLE `vendor_vehicles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vendor_id` (`vendor_id`),
  ADD KEY `idx_vehicle_number` (`vehicle_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_deleted` (`deleted_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `client_contacts`
--
ALTER TABLE `client_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `client_documents`
--
ALTER TABLE `client_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `client_document_alerts`
--
ALTER TABLE `client_document_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `client_rates`
--
ALTER TABLE `client_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_alerts`
--
ALTER TABLE `document_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `driver_documents`
--
ALTER TABLE `driver_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `driver_document_alerts`
--
ALTER TABLE `driver_document_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trips`
--
ALTER TABLE `trips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trip_containers`
--
ALTER TABLE `trip_containers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `vehicle_documents`
--
ALTER TABLE `vehicle_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_financing`
--
ALTER TABLE `vehicle_financing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vendor_documents`
--
ALTER TABLE `vendor_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendor_vehicles`
--
ALTER TABLE `vendor_vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `client_contacts`
--
ALTER TABLE `client_contacts`
  ADD CONSTRAINT `client_contacts_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `client_documents`
--
ALTER TABLE `client_documents`
  ADD CONSTRAINT `client_documents_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `client_documents_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `client_document_alerts`
--
ALTER TABLE `client_document_alerts`
  ADD CONSTRAINT `client_document_alerts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `client_document_alerts_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `client_rates`
--
ALTER TABLE `client_rates`
  ADD CONSTRAINT `client_rates_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_alerts`
--
ALTER TABLE `document_alerts`
  ADD CONSTRAINT `document_alerts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `document_alerts_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_document_alerts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_document_alerts_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `driver_documents`
--
ALTER TABLE `driver_documents`
  ADD CONSTRAINT `fk_driver_documents_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `driver_document_alerts`
--
ALTER TABLE `driver_document_alerts`
  ADD CONSTRAINT `driver_document_alerts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `driver_document_alerts_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_driver_alerts_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_driver_alerts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `trips`
--
ALTER TABLE `trips`
  ADD CONSTRAINT `trips_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`);

--
-- Constraints for table `trip_containers`
--
ALTER TABLE `trip_containers`
  ADD CONSTRAINT `trip_containers_ibfk_1` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD CONSTRAINT `user_activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicle_documents`
--
ALTER TABLE `vehicle_documents`
  ADD CONSTRAINT `fk_vehicle_documents_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicle_financing`
--
ALTER TABLE `vehicle_financing`
  ADD CONSTRAINT `vehicle_financing_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vendors`
--
ALTER TABLE `vendors`
  ADD CONSTRAINT `fk_vendor_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vendor_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vendors_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vendor_documents`
--
ALTER TABLE `vendor_documents`
  ADD CONSTRAINT `vendor_documents_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vendor_documents_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vendor_vehicles`
--
ALTER TABLE `vendor_vehicles`
  ADD CONSTRAINT `vendor_vehicles_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
