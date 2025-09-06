<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Check if user has manager1 access
if ($_SESSION['role'] !== 'manager1' && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin') {
    header("Location: ../dashboard.php");
    exit();
}

// Set page title and subtitle for header component
$page_title = 'Create New Booking';
$page_subtitle = 'Container Booking Management';

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $no_of_containers = isset($_POST['no_of_containers']) ? (int)$_POST['no_of_containers'] : 0;
        $container_types = $_POST['container_types'] ?? [];
        $container_numbers = $_POST['container_numbers'] ?? [];
        $container_from_location_ids = $_POST['container_from_location_ids'] ?? [];
        $container_to_location_ids = $_POST['container_to_location_ids'] ?? [];
        $same_locations_all = isset($_POST['same_locations_all']) ? 1 : 0;
        // Normalize optional FK fields to NULL when empty
        $from_location_id = isset($_POST['from_location_id']) && $_POST['from_location_id'] !== ''
            ? (int)$_POST['from_location_id']
            : null;
        $to_location_id = isset($_POST['to_location_id']) && $_POST['to_location_id'] !== ''
            ? (int)$_POST['to_location_id']
            : null;
        // Read booking id from visible or hidden field
        $booking_id = trim($_POST['booking_id'] ?? '');
        if ($booking_id === '' && isset($_POST['booking_id_hidden'])) {
            $booking_id = trim($_POST['booking_id_hidden']);
        }
        
        
        // Validate booking ID
        if (empty($booking_id)) {
            throw new Exception("Booking ID is required");
        }
        
        // Check if booking ID already exists
        $check_stmt = $pdo->prepare("SELECT id FROM bookings WHERE booking_id = ?");
        $check_stmt->execute([$booking_id]);
        if ($check_stmt->fetch()) {
            throw new Exception("Booking ID already exists. Please use a different ID.");
        }
        
        // Start transaction for data integrity
        $pdo->beginTransaction();

        // Detect if booking_containers table exists (graceful degradation)
        $hasContainersTable = false;
        try {
            $existsStmt = $pdo->query("SHOW TABLES LIKE 'booking_containers'");
            if ($existsStmt->fetch()) { $hasContainersTable = true; }
        } catch (Exception $e) { $hasContainersTable = false; }
        
        try {
            // Insert main booking record (handle legacy schemas with container_type/container_number)
            $columnsStmt = $pdo->query("SHOW COLUMNS FROM bookings");
            $existingColumns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN, 0);

            $insertColumns = ['booking_id','client_id','no_of_containers','from_location_id','to_location_id','created_by','updated_by'];
            $placeholders = ['?','?','?','?','?','?','?'];
            $params = [
            $booking_id,
            $client_id,
            $no_of_containers,
            $from_location_id,
            $to_location_id,
            $_SESSION['user_id'],
            $_SESSION['user_id']
            ];

            if (in_array('container_type', $existingColumns, true)) {
                $insertColumns[] = 'container_type';
                $placeholders[] = '?';
                $params[] = '';
            }
            if (in_array('container_number', $existingColumns, true)) {
                $insertColumns[] = 'container_number';
                $placeholders[] = '?';
                $params[] = '';
            }

            $sql = 'INSERT INTO bookings (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $booking_db_id = $pdo->lastInsertId();
            
            // Insert individual container records (only if table exists)
            // Save rows if types are provided, even if numbers are left blank (optional)
            if ($hasContainersTable && !empty($container_types)) {
                // Detect optional per-container location columns
                $bcColumns = [];
                try {
                    $bcColsStmt = $pdo->query("SHOW COLUMNS FROM booking_containers");
                    $bcColumns = $bcColsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                } catch (Exception $e) { $bcColumns = []; }

                $hasPerContainerLocations = in_array('from_location_id', $bcColumns, true) && in_array('to_location_id', $bcColumns, true);

                // Detect if container_type allows NULL
                $containerTypeAllowsNull = false;
                try {
                    $colStmt = $pdo->query("SHOW COLUMNS FROM booking_containers LIKE 'container_type'");
                    $colInfo = $colStmt->fetch(PDO::FETCH_ASSOC);
                    if ($colInfo && isset($colInfo['Null'])) {
                        $containerTypeAllowsNull = strtoupper($colInfo['Null']) === 'YES';
                    }
                } catch (Exception $e) { $containerTypeAllowsNull = false; }

                if ($hasPerContainerLocations) {
                    $container_stmt_any = $pdo->prepare("\n                        INSERT INTO booking_containers (booking_id, container_sequence, container_type, container_number_1, container_number_2, from_location_id, to_location_id, created_by, updated_by) \n                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n                    ");
                } else {
                    $container_stmt_any = $pdo->prepare("\n                        INSERT INTO booking_containers (booking_id, container_sequence, container_type, container_number_1, container_number_2, created_by, updated_by) \n                        VALUES (?, ?, ?, ?, ?, ?, ?)\n                    ");
                }

                $number_index = 0;
                $insertCount = max((int)$no_of_containers, count($container_types), count($container_from_location_ids), count($container_to_location_ids));
                for ($i = 0; $i < $insertCount; $i++) {
                    $sequence = $i + 1;

                    $rawType = isset($container_types[$i]) ? trim($container_types[$i]) : '';
                    $typeValid = ($rawType === '20ft' || $rawType === '40ft');
                    $container_type = $typeValid ? $rawType : null;

                    // Determine container numbers based on type (if known), else accept none
                    $number1 = null; $number2 = null;
                    if ($container_type === '20ft') {
                        $number1 = isset($container_numbers[$number_index]) && $container_numbers[$number_index] !== '' ? trim($container_numbers[$number_index]) : null;
                        $number2 = isset($container_numbers[$number_index + 1]) && $container_numbers[$number_index + 1] !== '' ? trim($container_numbers[$number_index + 1]) : null;
                        $number_index += 2;
                    } elseif ($container_type === '40ft') {
                        $number1 = isset($container_numbers[$number_index]) && $container_numbers[$number_index] !== '' ? trim($container_numbers[$number_index]) : null;
                        $number2 = null;
                        $number_index += 1;
                    }

                    // Determine per-container locations
                    $container_from_id = null;
                    $container_to_id = null;
                    if ($hasPerContainerLocations) {
                        $container_from_id = isset($container_from_location_ids[$i]) && $container_from_location_ids[$i] !== '' ? (int)$container_from_location_ids[$i] : null;
                        $container_to_id = isset($container_to_location_ids[$i]) && $container_to_location_ids[$i] !== '' ? (int)$container_to_location_ids[$i] : null;
                        if ($same_locations_all) {
                            if ($container_from_id === null) { $container_from_id = $from_location_id; }
                            if ($container_to_id === null) { $container_to_id = $to_location_id; }
                        } else {
                            if ($container_from_id === null) { $container_from_id = $from_location_id; }
                            if ($container_to_id === null) { $container_to_id = $to_location_id; }
                        }
                    }

                    // Decide whether to insert this row
                    $hasAnyData = $typeValid || $number1 !== null || $number2 !== null || ($hasPerContainerLocations && ($container_from_id !== null || $container_to_id !== null));
                    if (!$hasAnyData) {
                        continue; // nothing to save for this sequence
                    }

                    // If type is missing and DB doesn't allow NULL, default to '20ft' to persist locations
                    if ($container_type === null && !$containerTypeAllowsNull) {
                        $container_type = '20ft';
                    }

                    if ($hasPerContainerLocations) {
                        $container_stmt_any->execute([
                            $booking_db_id,
                            $sequence,
                            $container_type,
                            $number1,
                            $number2,
                            $container_from_id,
                            $container_to_id,
                            $_SESSION['user_id'],
                            $_SESSION['user_id']
                        ]);
                    } else {
                        $container_stmt_any->execute([
                            $booking_db_id,
                            $sequence,
                            $container_type,
                            $number1,
                            $number2,
                            $_SESSION['user_id'],
                            $_SESSION['user_id']
                        ]);
                    }
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollback();
            throw $e;
        }
        
        $success_message = "Booking created successfully! Booking ID: " . $booking_id;
        
        // Clear form data
        $_POST = array();
        
    } catch (Exception $e) {
        $error_message = "Error creating booking: " . $e->getMessage();
    }
}

// Get clients for dropdown
$clients_stmt = $pdo->query("SELECT id, client_name, client_code FROM clients WHERE status = 'active' ORDER BY client_name");
$clients = $clients_stmt->fetchAll();

// Get locations for autocomplete
$locations_stmt = $pdo->query("SELECT id, location FROM location ORDER BY location");
$locations = $locations_stmt->fetchAll();

// Function to generate next booking ID
function generateNextBookingId($pdo) {
    $current_year = date('Y');
    $prefix = "AB-{$current_year}-";
    
    // Get the last booking ID with this prefix
    $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE booking_id LIKE ? ORDER BY booking_id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last_booking = $stmt->fetch();
    
    if ($last_booking) {
        // Extract the number part and increment
        $last_number = intval(substr($last_booking['booking_id'], strlen($prefix)));
        $next_number = $last_number + 1;
    } else {
        // First booking of the year
        $next_number = 1;
    }
    
    return $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
}

// Handle AJAX request for generating booking ID
if (isset($_GET['action']) && $_GET['action'] === 'generate_booking_id') {
    header('Content-Type: application/json');
    echo json_encode(['booking_id' => generateNextBookingId($pdo)]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Booking - Absuma Logistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --absuma-red: #dc2625;
            --absuma-red-light: #fef2f2;
            --absuma-red-dark: #b91c1c;
        }
        
        .text-absuma-red { color: var(--absuma-red); }
        .bg-absuma-red { background-color: var(--absuma-red); }
        .border-absuma-red { border-color: var(--absuma-red); }
        .hover\:bg-absuma-red:hover { background-color: var(--absuma-red); }
        .hover\:text-absuma-red:hover { color: var(--absuma-red); }
        
        .gradient-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .shadow-soft {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .shadow-glow-red {
            box-shadow: 0 0 20px rgba(220, 38, 37, 0.3);
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animation-delay-100 { animation-delay: 0.1s; }
        .animation-delay-200 { animation-delay: 0.2s; }
        .animation-delay-300 { animation-delay: 0.3s; }
        
        .form-input {
            @apply w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200;
        }
        
        .form-label {
            @apply block text-sm font-semibold text-gray-700 mb-2;
        }
        
        .btn-primary {
            @apply bg-absuma-red hover:bg-absuma-red-dark text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200 shadow-md hover:shadow-lg;
        }
        
        .btn-secondary {
            @apply bg-gray-500 hover:bg-gray-600 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200 shadow-md hover:shadow-lg;
        }
        
        .autocomplete-container {
            position: relative;
        }
        
        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-top: none;
            border-radius: 0 0 0.75rem 0.75rem;
            max-height: 250px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .autocomplete-item {
            padding: 0.875rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .autocomplete-item:hover {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            transform: translateX(4px);
        }
        
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        
        .autocomplete-item-icon {
            width: 8px;
            height: 8px;
            background: #dc2625;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .autocomplete-item-text {
            flex: 1;
            font-weight: 500;
        }
        
        .autocomplete-no-results {
            padding: 1rem;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }
        
        /* Enhanced form styling */
        .form-section {
            @apply bg-white rounded-xl shadow-soft p-6 border border-gray-100;
        }
        
        .form-section-header {
            @apply flex items-center gap-3 mb-6 pb-4 border-b border-gray-200;
        }
        
        .form-section-title {
            @apply text-lg font-semibold text-gray-800 flex items-center;
        }
        
        .form-section-icon {
            @apply w-8 h-8 bg-absuma-red text-white rounded-lg flex items-center justify-center;
        }
        
        .container-field {
            @apply bg-white rounded-lg p-4 border border-gray-200 shadow-sm transition-all duration-200 hover:shadow-md;
        }
        
        .container-number {
            @apply w-10 h-10 bg-absuma-red text-white rounded-full flex items-center justify-center font-semibold shadow-md;
        }
        
        .input-enhanced {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background-color: white;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        
        .input-enhanced:focus {
            outline: none;
            border-color: #dc2625;
            box-shadow: 0 0 0 3px rgba(220, 38, 37, 0.1), 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transform: scale(1.02);
        }
        
        .btn-enhanced {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: none;
            cursor: pointer;
        }
        
        .btn-enhanced:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .btn-primary-enhanced {
            background-color: #dc2625;
            color: white;
        }
        
        .btn-primary-enhanced:hover {
            background-color: #b91c1c;
        }
        
        .btn-secondary-enhanced {
            background-color: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary-enhanced:hover {
            background-color: #e5e7eb;
            border-color: #9ca3af;
        }
        
        /* Modern form styling */
        .form-section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .form-section-icon {
            width: 40px;
            height: 40px;
            background-color: #dc2625;
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        
        .form-section-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }
        
        /* Compact layout improvements */
        .form-section {
            margin-bottom: 24px;
        }
        
        .form-section:last-child {
            margin-bottom: 0;
        }
        
        /* Better spacing for form elements */
        .space-y-6 > * + * {
            margin-top: 20px;
        }
        
        .space-y-4 > * + * {
            margin-top: 16px;
        }
        
        /* Ensure buttons are not white */
        button[type="submit"] {
            background: linear-gradient(135deg, #dc2625 0%, #b91c1c 100%) !important;
            color: white !important;
            border: none !important;
        }
        
        button[type="submit"]:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%) !important;
        }
        
        /* Fix dropdown styling */
        select.input-enhanced {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 40px;
        }
        
        /* Autocomplete dropdown styling */
        .autocomplete-container {
            position: relative;
        }
        
        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-top: none;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            z-index: 50;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }
        
        .autocomplete-item {
            transition: background-color 0.2s ease;
        }
        
        .autocomplete-item:hover {
            background-color: #fef2f2;
        }
        
        .autocomplete-item.active {
            background-color: #fef2f2;
            border-left: 3px solid #dc2625;
        }
        
        /* Ensure input has rounded bottom when dropdown is open */
        .autocomplete-container input:focus {
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        /* Animation classes */
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stagger-animation > * {
            animation: fadeInUp 0.6s ease-out;
        }
        
        .stagger-animation > *:nth-child(1) { animation-delay: 0.1s; }
        .stagger-animation > *:nth-child(2) { animation-delay: 0.2s; }
        .stagger-animation > *:nth-child(3) { animation-delay: 0.3s; }
        .stagger-animation > *:nth-child(4) { animation-delay: 0.4s; }
        .stagger-animation > *:nth-child(5) { animation-delay: 0.5s; }
        
        /* Enhanced input focus states */
        .input-enhanced:focus {
            @apply ring-2 ring-red-500 ring-opacity-50 border-red-500 shadow-lg;
        }
        
        /* Loading state for button */
        .btn-loading {
            @apply opacity-75 cursor-not-allowed;
        }
        
        .btn-loading .fas {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Success state for form fields */
        .field-success {
            @apply border-green-500 bg-green-50;
        }
        
        /* Error state for form fields */
        .field-error {
            @apply border-red-500 bg-red-50;
        }
        
        /* Generate button animation */
        .generate-btn {
            transition: all 0.3s ease;
        }
        
        .generate-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -5px rgba(220, 38, 37, 0.4);
        }
        
        .generate-btn:active {
            transform: translateY(0);
        }
        
        /* Booking ID input success state */
        .booking-id-success {
            @apply border-green-500 bg-green-50;
            animation: successPulse 0.6s ease-out;
        }
        
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen">
        <!-- Header with Absuma Branding -->
        <?php include '../header_component.php'; ?>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar with Absuma colors -->
                <aside class="w-full lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-soft p-4 sticky top-20 border border-white/20 backdrop-blur-sm bg-white/70">
                        <!-- Booking Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Booking Section</h3>
                            <nav class="space-y-1.5">
                                <a href="create.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-absuma-red bg-red-50 rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red"></i>Create Booking
                                </a>
                                <a href="manage.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-list w-5 text-center text-absuma-red"></i>Manage Bookings
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Vehicle Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Vehicle Section</h3>
                            <nav class="space-y-1.5">
                                <a href="../add_vehicle.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-dark hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Add Vehicle & Driver
                                </a>
                                <a href="../manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Vehicles
                                </a>
                                <a href="../manage_drivers.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-users w-5 text-center text-absuma-red group-hover:text-absuma-red"></i> Manage Drivers
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Client Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Client Section</h3>
                            <nav class="space-y-1">
                                <a href="../add_client.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red"></i> Register Client
                                </a>
                                <a href="../manage_clients.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i> Manage Clients
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div class="space-y-4">
                        <!-- Page Header -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                        <i class="fas fa-shipping-fast text-absuma-red mr-3"></i>
                                        Create New Booking
                                    </h2>
                                    <p class="text-gray-600">Fill in the container booking details below</p>
                                </div>
                                <div class="hidden md:block">
                                    <div class="bg-red-50 p-4 rounded-lg">
                                        <i class="fas fa-clipboard-list text-3xl text-absuma-red"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Booking ID Input Section -->
                            <div class="mt-4 p-4 bg-gradient-to-r from-red-50 to-red-100 rounded-lg border border-red-200">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 bg-absuma-red text-white rounded-full flex items-center justify-center">
                                        <i class="fas fa-hashtag text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-600">Booking ID</p>
                                        <p class="text-xs text-gray-500">Enter manually or generate automatically</p>
                                    </div>
                                </div>
                                
                                <div class="flex gap-3">
                                    <div class="flex-1">
                                        <input type="text" 
                                               name="booking_id" 
                                               id="booking_id" 
                                               class="input-enhanced" 
                                               placeholder="Enter booking ID"
                                               value="<?= htmlspecialchars($_POST['booking_id'] ?? '') ?>"
                                               maxlength="50"
                                               required>
                                    </div>
                                    <button type="button" 
                                            id="generate-booking-id-btn"
                                            class="generate-btn bg-absuma-red hover:bg-absuma-red-dark text-white px-4 py-3 rounded-lg shadow-md flex items-center gap-2"
                                            onclick="console.log('Generate button clicked via onclick')">
                                        <i class="fas fa-magic"></i>
                                        <span class="hidden sm:inline">Generate</span>
                                    </button>
                                </div>
                                
                                
                            </div>
                        </div>

                        <!-- Success/Error Messages -->
                        <?php if ($success_message): ?>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4 animate-fade-in">
                                <div class="flex items-center">
                                    <i class="fas fa-check-circle text-green-600 mr-3"></i>
                                    <span class="text-green-800 font-medium"><?= htmlspecialchars($success_message) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4 animate-fade-in">
                                <div class="flex items-center">
                                    <i class="fas fa-exclamation-circle text-red-600 mr-3"></i>
                                    <span class="text-red-800 font-medium"><?= htmlspecialchars($error_message) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Booking Form -->
                        <div class="form-section fade-in-up">
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="booking_id" id="booking_id_hidden">
                                <!-- Basic Information Section -->
                                <div class="form-section-header">
                                    <div class="form-section-icon">
                                        <i class="fas fa-info-circle"></i>
                                    </div>
                                    <h2 class="form-section-title">Basic Information</h2>
                                </div>
                                
                                <!-- Client and Number of Containers in Same Row -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                    <!-- Client Selection with Autocomplete -->
                                    <div>
                                        <label for="client_search" class="form-label">
                                            <i class="fas fa-building text-absuma-red mr-2"></i>Client *
                                        </label>
                                        <div class="relative">
                                            <div class="autocomplete-container">
                                                <input type="text" 
                                                       name="client_search" 
                                                       id="client_search" 
                                                       class="input-enhanced pr-10" 
                                                       placeholder="Type to search clients..."
                                                       autocomplete="off"
                                                       value="<?= htmlspecialchars($_POST['client_search'] ?? '') ?>">
                                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                                    <i class="fas fa-search text-gray-400"></i>
                                                </div>
                                                <input type="hidden" name="client_id" id="client_id" value="<?= htmlspecialchars($_POST['client_id'] ?? '') ?>">
                                                <div class="autocomplete-dropdown" id="client_dropdown"></div>
                                            </div>
                                            <div class="mt-2 text-xs text-gray-500">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                Start typing to see client suggestions
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Number of Containers -->
                                    <div>
                                        <label for="no_of_containers" class="form-label">
                                            <i class="fas fa-cubes text-absuma-red mr-2"></i>Number of Containers *
                                        </label>
                                        <input type="number" name="no_of_containers" id="no_of_containers" 
                                               class="input-enhanced" min="1" max="20" required
                                               value="<?= htmlspecialchars($_POST['no_of_containers'] ?? '') ?>">
                                        <p class="text-xs text-gray-500 mt-2 flex items-center">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Maximum 20 containers per booking
                                        </p>
                                    </div>
                                    </div>

                                <!-- Location Information Section -->
                                <div class="form-section-header">
                                    <div class="form-section-icon">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                    <h2 class="form-section-title">Location Information</h2>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 stagger-animation">

                                    <!-- From Location -->
                                    <div>
                                        <label for="from_location" class="form-label">
                                            <i class="fas fa-map-marker-alt text-absuma-red mr-2"></i>From Location (Optional)
                                        </label>
                                        <div class="relative">
                                        <div class="autocomplete-container">
                                            <input type="text" name="from_location" id="from_location" 
                                                       class="input-enhanced pr-10" placeholder="Type to search locations..."
                                                   autocomplete="off">
                                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                                    <i class="fas fa-search text-gray-400"></i>
                                                </div>
                                            <input type="hidden" name="from_location_id" id="from_location_id">
                                            <div class="autocomplete-dropdown" id="from_location_dropdown"></div>
                                            </div>
                                            <div class="mt-2 text-xs text-gray-500">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                Start typing to see location suggestions
                                            </div>
                                        </div>
                                    </div>

                                    <!-- To Location -->
                                    <div>
                                        <label for="to_location" class="form-label">
                                            <i class="fas fa-map-marker-alt text-absuma-red mr-2"></i>To Location (Optional)
                                        </label>
                                        <div class="relative">
                                        <div class="autocomplete-container">
                                            <input type="text" name="to_location" id="to_location" 
                                                       class="input-enhanced pr-10" placeholder="Type to search locations..."
                                                   autocomplete="off">
                                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                                    <i class="fas fa-search text-gray-400"></i>
                                                </div>
                                            <input type="hidden" name="to_location_id" id="to_location_id">
                                            <div class="autocomplete-dropdown" id="to_location_dropdown"></div>
                                        </div>
                                            <div class="mt-2 text-xs text-gray-500">
                                                <i class="fas fa-info-circle mr-1"></i>
                                                Start typing to see location suggestions
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Same for all containers checkbox -->
                                <div class="mt-2 flex items-center gap-3">
                                    <input type="checkbox" id="same_locations_all" name="same_locations_all" class="h-4 w-4 text-absuma-red border-gray-300 rounded">
                                    <label for="same_locations_all" class="text-sm text-gray-700">Use the same From/To locations for all containers</label>
                                </div>

                                <!-- Location Creation Link -->
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-3">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-info-circle text-blue-600"></i>
                                            <span class="text-sm text-blue-800 font-medium">Location not found?</span>
                                        </div>
                                        <button type="button" 
                                                onclick="openLocationModal()"
                                                class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-md transition-colors duration-200">
                                            <i class="fas fa-plus mr-1"></i>Add New Location
                                        </button>
                                    </div>
                                </div>

                                <!-- Container Details Section (Optional) -->
                                <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
                                    <!-- Optional Notice for Managers -->
                                    <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-info-circle text-blue-600"></i>
                                            <span class="text-sm text-blue-800 font-medium">Container details are optional</span>
                                        </div>
                                        <p class="text-xs text-blue-700 mt-1">You can create a booking with just basic information. Container details can be added later if needed.</p>
                                    </div>
                                    <div class="flex items-center justify-between mb-6">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-absuma-red text-white rounded-lg flex items-center justify-center">
                                                <i class="fas fa-barcode text-sm"></i>
                                            </div>
                                    <div>
                                                <h3 class="text-lg font-semibold text-gray-900">Container Details <span class="text-sm font-normal text-gray-500">(Optional)</span></h3>
                                                <p class="text-sm text-gray-500">Configure each container with type and numbers - leave empty to skip container details</p>
                                    </div>
                                </div>
                                        <div class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full" id="container-count-display">
                                            No containers selected
                                        </div>
                                    </div>
                                    
                                    <div id="container-details-container" class="space-y-4">
                                        <div class="text-center text-gray-500 py-8">
                                            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                                <i class="fas fa-cube text-2xl text-gray-400"></i>
                                            </div>
                                            <p class="text-base font-medium mb-1">No containers selected</p>
                                            <p class="text-sm">Select number of containers above to configure container details (optional)</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Form Actions -->
                                <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-6 border border-gray-200">
                                    <div class="flex flex-col sm:flex-row gap-4">
                                        <button type="submit" class="group relative overflow-hidden bg-gradient-to-r from-absuma-red to-red-600 hover:from-red-600 hover:to-absuma-red text-white font-bold py-2 px-4 rounded-lg transition-all duration-300 transform hover:scale-105 hover:shadow-lg shadow-md flex items-center justify-center text-sm">
                                            <div class="absolute inset-0 bg-gradient-to-r from-white/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                                            <div class="relative flex items-center">
                                                <i class="fas fa-shipping-fast mr-2 text-sm group-hover:animate-pulse"></i>
                                                <span class="text-sm">Create Booking</span>
                                            </div>
                                            <div class="absolute inset-0 bg-white/10 transform -skew-x-12 -translate-x-full group-hover:translate-x-full transition-transform duration-700"></div>
                                    </button>
                                        
                                        <a href="../dashboard.php" class="group bg-white hover:bg-gray-50 text-gray-700 hover:text-gray-900 font-semibold py-2 px-4 rounded-lg border-2 border-gray-300 hover:border-gray-400 transition-all duration-300 transform hover:scale-105 shadow-md hover:shadow-lg flex items-center justify-center text-sm">
                                            <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform duration-300"></i>
                                            <span>Back to Dashboard</span>
                                        </a>
                                    </div>
                                    
                                </div>
                            </form>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </div>

    <script>
        // Prevent unnecessary regenerations
        let lastGeneratedContainerCount = null;
        // Location autocomplete functionality
        const locations = <?= json_encode($locations) ?>;
        console.log('Locations loaded:', locations);
        
        // Client autocomplete setup function
        function setupClientAutocomplete() {
            console.log('setupClientAutocomplete called');
            const input = document.getElementById('client_search');
            const dropdown = document.getElementById('client_dropdown');
            const hiddenInput = document.getElementById('client_id');
            
            console.log('Elements found:', { input: !!input, dropdown: !!dropdown, hiddenInput: !!hiddenInput });
            
            if (!input || !dropdown || !hiddenInput) {
                console.error('Required elements not found for client autocomplete');
                return;
            }
            
            const allClients = <?= json_encode($clients) ?>;
            console.log('Clients loaded:', allClients);
            let activeIndex = -1;
            let currentActive = null;
            let isDropdownOpen = false;
            
            // Function to render clients in dropdown
            function renderClients(clients) {
                if (clients.length === 0) {
                    dropdown.innerHTML = '<div class="p-3 text-gray-500 text-sm">No clients found</div>';
                } else {
                    dropdown.innerHTML = clients.map((client, index) => 
                        '<div class="autocomplete-item p-3 cursor-pointer hover:bg-red-50 border-b border-gray-100 transition-colors" ' +
                        'data-value="' + client.id + '" data-text="' + client.client_name + ' (' + client.client_code + ')">' +
                        '<div class="font-medium text-gray-900">' + client.client_name + '</div>' +
                        '<div class="text-sm text-gray-500">' + client.client_code + '</div>' +
                        '</div>'
                    ).join('');
                }
            }
            
            // Function to filter clients based on search query
            function filterClients(query) {
                if (!query || query.trim().length === 0) {
                    return allClients;
                }
                
                const searchTerm = query.toLowerCase().trim();
                return allClients.filter(client => 
                    client.client_name.toLowerCase().includes(searchTerm) ||
                    client.client_code.toLowerCase().includes(searchTerm)
                );
            }
            
            // Show dropdown with all clients initially
            function showDropdown() {
                if (!isDropdownOpen) {
                    renderClients(allClients);
                    dropdown.style.display = 'block';
                    isDropdownOpen = true;
                }
            }
            
            // Hide dropdown
            function hideDropdown() {
                dropdown.style.display = 'none';
                isDropdownOpen = false;
                activeIndex = -1;
            }
            
            // Input focus - show all clients
            input.addEventListener('focus', function() {
                showDropdown();
            });
            
            // Input change - filter clients
            input.addEventListener('input', function() {
                const query = this.value;
                const filteredClients = filterClients(query);
                renderClients(filteredClients);
                
                // Clear hidden input if search is empty
                if (!query || query.trim().length === 0) {
                    hiddenInput.value = '';
                }
                
                activeIndex = -1;
            });
            
            // Dropdown click handler
            dropdown.addEventListener('click', function(e) {
                const item = e.target.closest('.autocomplete-item');
                if (item) {
                    const value = item.getAttribute('data-value');
                    const text = item.getAttribute('data-text');
                    
                    input.value = text;
                    hiddenInput.value = value;
                    hideDropdown();
                }
            });
            
            // Click outside to close dropdown
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    hideDropdown();
                }
            });
            
            // Keyboard navigation
            input.addEventListener('keydown', function(e) {
                if (!isDropdownOpen) {
                    if (e.key === 'ArrowDown' || e.key === 'Enter') {
                        e.preventDefault();
                        showDropdown();
                    }
                    return;
                }
                
                const items = dropdown.querySelectorAll('.autocomplete-item');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActiveItem(items, activeIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, -1);
                    updateActiveItem(items, activeIndex);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentActive) {
                        currentActive.click();
                    }
                } else if (e.key === 'Escape') {
                    hideDropdown();
                }
            });
            
            // Initialize dropdown as hidden
            hideDropdown();
        }
        
        function setupAutocomplete(inputId, dropdownId, hiddenId) {
            console.log('setupAutocomplete called for:', inputId, dropdownId, hiddenId);
            const input = document.getElementById(inputId);
            const dropdown = document.getElementById(dropdownId);
            const hidden = document.getElementById(hiddenId);
            
            console.log('Location elements found:', { input: !!input, dropdown: !!dropdown, hidden: !!hidden });
            
            if (!input || !dropdown || !hidden) {
                console.error('Required elements not found for location autocomplete');
                    return;
                }
                
            // Show dropdown on focus
            input.addEventListener('focus', function() {
                const query = this.value.toLowerCase().trim();
                const filtered = locations.filter(loc => 
                    loc.location.toLowerCase().includes(query)
                );
                
                if (filtered.length > 0) {
                    dropdown.innerHTML = filtered.map(loc => 
                        `<div class="autocomplete-item p-3 cursor-pointer hover:bg-red-50 border-b border-gray-100 transition-colors" data-id="${loc.id}" data-name="${loc.location}">
                            <div class="font-medium text-gray-900">${loc.location}</div>
                        </div>`
                    ).join('');
                } else {
                    dropdown.innerHTML = '<div class="p-3 text-gray-500 text-sm">No locations found</div>';
                }
                    dropdown.style.display = 'block';
            });
            
            input.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                
                // Clear hidden input if search is empty
                if (!query || query.trim().length === 0) {
                    hidden.value = '';
                }
                
                const filtered = locations.filter(loc => 
                    loc.location.toLowerCase().includes(query)
                );
                
                if (filtered.length > 0) {
                    dropdown.innerHTML = filtered.map(loc => 
                        `<div class="autocomplete-item p-3 cursor-pointer hover:bg-red-50 border-b border-gray-100 transition-colors" data-id="${loc.id}" data-name="${loc.location}">
                            <div class="font-medium text-gray-900">${loc.location}</div>
                        </div>`
                    ).join('');
                } else {
                    dropdown.innerHTML = '<div class="p-3 text-gray-500 text-sm">No locations found</div>';
                }
                dropdown.style.display = 'block';
            });
            
            dropdown.addEventListener('click', function(e) {
                if (e.target.closest('.autocomplete-item')) {
                    const item = e.target.closest('.autocomplete-item');
                    const id = item.dataset.id;
                    const name = item.dataset.name;
                    
                    input.value = name;
                    hidden.value = id;
                    dropdown.style.display = 'none';
                    
                    // Add visual feedback
                    input.classList.add('border-green-500', 'bg-green-50');
                    setTimeout(() => {
                        input.classList.remove('border-green-500', 'bg-green-50');
                    }, 1000);
                }
            });
            
            // Hide dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
            
            // Handle keyboard navigation
            input.addEventListener('keydown', function(e) {
                const items = dropdown.querySelectorAll('.autocomplete-item');
                const currentActive = dropdown.querySelector('.autocomplete-item.active');
                let activeIndex = -1;
                
                if (currentActive) {
                    activeIndex = Array.from(items).indexOf(currentActive);
                }
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIndex = Math.min(activeIndex + 1, items.length - 1);
                    updateActiveItem(items, activeIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIndex = Math.max(activeIndex - 1, -1);
                    updateActiveItem(items, activeIndex);
                } else if (e.key === 'Enter' && currentActive) {
                    e.preventDefault();
                    currentActive.click();
                } else if (e.key === 'Escape') {
                    dropdown.style.display = 'none';
                }
            });
        }
        
        function updateActiveItem(items, activeIndex) {
            items.forEach((item, index) => {
                item.classList.toggle('active', index === activeIndex);
                if (index === activeIndex) {
                    item.style.background = 'linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%)';
                } else {
                    item.style.background = '';
                }
            });
        }
        
        // Dynamic container details generation with individual type selection
        function generateContainerFields() {
            const noOfContainers = parseInt(document.getElementById('no_of_containers').value) || 0;
            const container = document.getElementById('container-details-container');
            const countDisplay = document.getElementById('container-count-display');
            
            // If already generated for the same count and real fields exist, skip
            const existingFields = container.querySelectorAll('.container-field');
            if (lastGeneratedContainerCount === noOfContainers && existingFields.length === noOfContainers) {
                return;
            }
            
            if (noOfContainers === 0) {
                container.innerHTML = '<div class="text-center text-gray-500 py-8">' +
                    '<div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">' +
                        '<i class="fas fa-cube text-2xl text-gray-400"></i>' +
                    '</div>' +
                    '<p class="text-base font-medium mb-1">No containers selected</p>' +
                    '<p class="text-sm">Select number of containers above to configure container details</p>' +
                '</div>';
                countDisplay.textContent = 'No containers selected';
                return;
            }
            
            countDisplay.textContent = noOfContainers + ' container' + (noOfContainers > 1 ? 's' : '') + ' selected';
            
            let containerFields = '';
            
            for (let i = 1; i <= noOfContainers; i++) {
                containerFields += '<div class="container-field bg-gray-50 rounded-lg p-4 border border-gray-200">' +
                    '<div class="flex items-center gap-3 mb-4">' +
                        '<div class="w-8 h-8 bg-absuma-red text-white rounded-full flex items-center justify-center text-sm font-semibold">' +
                            i +
                        '</div>' +
                        '<div class="flex-1">' +
                            '<h4 class="font-medium text-gray-900">Container ' + i + '</h4>' +
                            '<p class="text-sm text-gray-500">Configure type and container numbers</p>' +
                        '</div>' +
                    '</div>' +
                    
                    '<div class="grid grid-cols-1 md:grid-cols-3 gap-4">' +
                        '<div>' +
                            '<label class="block text-sm font-medium text-gray-700 mb-1">Container Type</label>' +
                            '<select name="container_types[]" id="container_type_' + i + '" class="input-enhanced" onchange="updateContainerNumbers(event, ' + i + ')">' +
                                '<option value="">Select type</option>' +
                                '<option value="20ft">20ft Container (2 numbers)</option>' +
                                '<option value="40ft">40ft Container (1 number)</option>' +
                            '</select>' +
                        '</div>' +
                        '<div id="container_numbers_' + i + '">' +
                            '<label class="block text-sm font-medium text-gray-700 mb-1">Container Numbers</label>' +
                            '<div class="text-sm text-gray-500">Select container type first</div>' +
                        '</div>' +
                    '</div>' +
                    
                    // Per-container locations
                    '<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">' +
                        '<div>' +
                            '<label class="block text-sm font-medium text-gray-700 mb-1">From Location (Optional)</label>' +
                            '<div class="relative autocomplete-container">' +
                                '<input type="text" id="container_from_location_' + i + '" class="input-enhanced pr-10 container-from-input" placeholder="Type to search locations..." autocomplete="off" />' +
                                '<div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">' +
                                    '<i class="fas fa-search text-gray-400"></i>' +
                                '</div>' +
                                '<input type="hidden" name="container_from_location_ids[]" id="container_from_location_id_' + i + '" />' +
                                '<div class="autocomplete-dropdown" id="container_from_location_dropdown_' + i + '"></div>' +
                            '</div>' +
                        '</div>' +
                        '<div>' +
                            '<label class="block text-sm font-medium text-gray-700 mb-1">To Location (Optional)</label>' +
                            '<div class="relative autocomplete-container">' +
                                '<input type="text" id="container_to_location_' + i + '" class="input-enhanced pr-10 container-to-input" placeholder="Type to search locations..." autocomplete="off" />' +
                                '<div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">' +
                                    '<i class="fas fa-search text-gray-400"></i>' +
                                '</div>' +
                                '<input type="hidden" name="container_to_location_ids[]" id="container_to_location_id_' + i + '" />' +
                                '<div class="autocomplete-dropdown" id="container_to_location_dropdown_' + i + '"></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    
                    '<div class="mt-3 flex justify-end">' +
                        '<button type="button" class="text-sm text-gray-500 hover:text-red-500 hover:bg-red-50 px-2 py-1 rounded transition-colors" ' +
                                'onclick="clearContainerField(' + i + ')" title="Clear container ' + i + '">' +
                            '<i class="fas fa-times mr-1"></i>Clear' +
                        '</button>' +
                    '</div>' +
                '</div>';
            }
            
            container.innerHTML = containerFields;
            lastGeneratedContainerCount = noOfContainers;
            
            // Initialize per-container autocompletes
            for (let j = 1; j <= noOfContainers; j++) {
                setupAutocomplete('container_from_location_' + j, 'container_from_location_dropdown_' + j, 'container_from_location_id_' + j);
                setupAutocomplete('container_to_location_' + j, 'container_to_location_dropdown_' + j, 'container_to_location_id_' + j);
            }
            
            // Add animation to new fields
            const newFields = container.querySelectorAll('.container-field');
            newFields.forEach((field, index) => {
                field.style.opacity = '0';
                field.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    field.style.transition = 'all 0.3s ease';
                    field.style.opacity = '1';
                    field.style.transform = 'translateY(0)';
                }, index * 100);
            });
        }
        
        // Update container numbers based on selected type
        function updateContainerNumbers(e, containerIndex) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
                if (typeof e.stopPropagation === 'function') e.stopPropagation();
            }
            const containerType = document.getElementById('container_type_' + containerIndex).value;
            const numbersContainer = document.getElementById('container_numbers_' + containerIndex);
            
            if (containerType === '20ft') {
                numbersContainer.innerHTML = '<label class="block text-sm font-medium text-gray-700 mb-1">Container Numbers *</label>' +
                    '<div class="space-y-2">' +
                        '<input type="text" name="container_numbers[]" placeholder="First container number" ' +
                               'class="input-enhanced" maxlength="20" required>' +
                        '<input type="text" name="container_numbers[]" placeholder="Second container number" ' +
                               'class="input-enhanced" maxlength="20" required>' +
                    '</div>';
            } else if (containerType === '40ft') {
                numbersContainer.innerHTML = '<label class="block text-sm font-medium text-gray-700 mb-1">Container Number *</label>' +
                    '<input type="text" name="container_numbers[]" placeholder="Container number" ' +
                           'class="input-enhanced" maxlength="20" required>';
            } else {
                numbersContainer.innerHTML = '<label class="block text-sm font-medium text-gray-700 mb-1">Container Numbers</label>' +
                    '<div class="text-sm text-gray-500">Select container type first</div>';
            }
        }
        
        // Clear individual container field
        function clearContainerField(containerNumber) {
            // Clear container type
            const typeSelect = document.getElementById('container_type_' + containerNumber);
            if (typeSelect) {
                typeSelect.value = '';
            }
            
            // Reset container numbers section
            const numbersContainer = document.getElementById('container_numbers_' + containerNumber);
            if (numbersContainer) {
                numbersContainer.innerHTML = '<label class="block text-sm font-medium text-gray-700 mb-1">Container Numbers</label>' +
                    '<div class="text-sm text-gray-500">Select container type first</div>';
            }
        }
        
        // Clear all container fields
        function clearAllContainerFields() {
            const fields = document.querySelectorAll('input[name="container_numbers[]"]');
            fields.forEach(field => field.value = '');
        }
        
        // Initialize everything when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing all functionality...');
            
            // Initialize autocomplete for client field
            console.log('Setting up client autocomplete...');
            setupClientAutocomplete();
        
        // Initialize autocomplete for both location fields
            console.log('Setting up location autocomplete...');
            setupAutocomplete('from_location', 'from_location_dropdown', 'from_location_id');
            setupAutocomplete('to_location', 'to_location_dropdown', 'to_location_id');
        
            // Same for all checkbox behavior
            const sameAll = document.getElementById('same_locations_all');
            function applyGlobalLocationsToContainers() {
                const fromName = document.getElementById('from_location').value || '';
                const fromId = document.getElementById('from_location_id').value || '';
                const toName = document.getElementById('to_location').value || '';
                const toId = document.getElementById('to_location_id').value || '';
                const count = parseInt(document.getElementById('no_of_containers').value) || 0;
                for (let i = 1; i <= count; i++) {
                    const fromInput = document.getElementById('container_from_location_' + i);
                    const fromHidden = document.getElementById('container_from_location_id_' + i);
                    const toInput = document.getElementById('container_to_location_' + i);
                    const toHidden = document.getElementById('container_to_location_id_' + i);
                    if (!fromInput || !fromHidden || !toInput || !toHidden) continue;
                    if (sameAll.checked) {
                        fromInput.value = fromName;
                        fromHidden.value = fromId;
                        toInput.value = toName;
                        toHidden.value = toId;
                    }
                }
            }
            function autoCheckSameAllIfMatching() {
                const fromId = document.getElementById('from_location_id').value || '';
                const toId = document.getElementById('to_location_id').value || '';
                const count = parseInt(document.getElementById('no_of_containers').value) || 0;
                if (count === 0) return;
                let allMatch = true;
                for (let i = 1; i <= count; i++) {
                    const fromHidden = document.getElementById('container_from_location_id_' + i);
                    const toHidden = document.getElementById('container_to_location_id_' + i);
                    if (!fromHidden || !toHidden) { allMatch = false; break; }
                    if ((fromHidden.value || '') !== fromId || (toHidden.value || '') !== toId) { allMatch = false; break; }
                }
                if (sameAll) sameAll.checked = allMatch;
            }
            function watchPerContainerDeviation() {
                const count = parseInt(document.getElementById('no_of_containers').value) || 0;
                for (let i = 1; i <= count; i++) {
                    const fromInput = document.getElementById('container_from_location_' + i);
                    const toInput = document.getElementById('container_to_location_' + i);
                    if (fromInput) fromInput.addEventListener('input', () => { if (sameAll) sameAll.checked = false; });
                    if (toInput) toInput.addEventListener('input', () => { if (sameAll) sameAll.checked = false; });
                }
            }
            if (sameAll) {
                sameAll.addEventListener('change', applyGlobalLocationsToContainers);
                document.getElementById('from_location').addEventListener('input', function(){ if (sameAll.checked) applyGlobalLocationsToContainers(); });
                document.getElementById('to_location').addEventListener('input', function(){ if (sameAll.checked) applyGlobalLocationsToContainers(); });
            }
        
            // Generate booking ID functionality
            const generateBtn = document.getElementById('generate-booking-id-btn');
            if (generateBtn) {
                generateBtn.addEventListener('click', function() {
                    console.log('Generate button clicked');
                    const button = this;
                    const bookingIdInput = document.getElementById('booking_id');
                    
                    if (!bookingIdInput) {
                        console.error('Booking ID input not found');
                        return;
                    }
                    
                    // Prevent regenerating if already present (typed or generated)
                    if (bookingIdInput.value && bookingIdInput.value.trim().length > 0) {
                        showNotification('Booking ID already set. Clear it first if you want to regenerate.', 'info');
                        return;
                    }
                    
                    // Add loading state to button
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span class="hidden sm:inline">Generating...</span>';
                    
                    // Fetch new booking ID
                    fetch('?action=generate_booking_id')
                        .then(response => {
                            console.log('Response received:', response);
                            return response.json();
                        })
                        .then(data => {
                            console.log('Data received:', data);
                            bookingIdInput.value = data.booking_id;
                            bookingIdInput.classList.add('booking-id-success');
                            
                            // Debug: Check if value was set correctly
                            console.log('Booking ID field value after setting:', bookingIdInput.value);
                            console.log('Booking ID field name:', bookingIdInput.name);
                            console.log('Booking ID field disabled:', bookingIdInput.disabled);
                            // Sync hidden field used for submission
                            const hidden = document.getElementById('booking_id_hidden');
                            if (hidden) hidden.value = data.booking_id;
                            
                            // Show success feedback
                            showNotification('Booking ID generated successfully!', 'success');
                            
                            // Remove success styling after 2 seconds
                            setTimeout(() => {
                                bookingIdInput.classList.remove('booking-id-success');
                            }, 2000);
                        })
                        .catch(error => {
                            console.error('Error generating booking ID:', error);
                            showNotification('Error generating booking ID. Please try again.', 'error');
                        })
                        .finally(() => {
                            // Lock generate after first successful generation in this session
                            button.disabled = true;
                            button.title = 'Booking ID already generated';
                            button.classList.add('opacity-60', 'cursor-not-allowed');
                            button.innerHTML = '<i class="fas fa-check"></i> <span class="hidden sm:inline">Generated</span>';
                        });
                });
            } else {
                console.error('Generate button not found');
            }
            
            // Form validation and submission
            const form = document.querySelector('form');
            if (form) {
                console.log('Form found:', form);
                
                // Add event listener for form validation
                form.addEventListener('submit', function(e) {
                    console.log('Form submit event triggered');
                    
                    // Elements
                    const clientIdEl = document.getElementById('client_id');
                    const clientSearchEl = document.getElementById('client_search');
                    const noOfContainersEl = document.getElementById('no_of_containers');
                    const bookingIdEl = document.getElementById('booking_id');
                    const bookingIdHidden = document.getElementById('booking_id_hidden');
                    
                    // Sync hidden booking id
                    const bookingId = (bookingIdEl?.value || '').trim();
                    if (bookingIdHidden) bookingIdHidden.value = bookingId;
                    
                    const clientId = clientIdEl?.value || '';
                    const noOfContainers = (noOfContainersEl?.value || '').trim();
                    
                    console.log('Form validation values:', { clientId, noOfContainers, bookingId, bookingIdLength: bookingId.length });
                    
                    // Clear previous error states
                    [clientSearchEl, noOfContainersEl, bookingIdEl].forEach(el => {
                        if (!el) return;
                        el.classList.remove('border-red-500','bg-red-50','ring-2','ring-red-500');
                        const err = el.parentElement?.querySelector('.field-error-text');
                        if (err) err.remove();
                    });
                    
                    // Validate requireds
                    const missing = [];
                    const firstToFocus = [];
                    
                    if (!clientId) {
                        missing.push('Client');
                        if (clientSearchEl) {
                            clientSearchEl.classList.add('border-red-500','bg-red-50');
                            const span = document.createElement('div');
                            span.className = 'field-error-text text-xs text-red-600 mt-1';
                            span.textContent = 'Please select a client from the list';
                            clientSearchEl.parentElement.appendChild(span);
                            firstToFocus.push(clientSearchEl);
                        }
                    }
                    
                    if (!noOfContainers || parseInt(noOfContainers) <= 0 || isNaN(parseInt(noOfContainers))) {
                        missing.push('Number of Containers');
                        if (noOfContainersEl) {
                            noOfContainersEl.classList.add('border-red-500','bg-red-50');
                            const span = document.createElement('div');
                            span.className = 'field-error-text text-xs text-red-600 mt-1';
                            span.textContent = 'Please enter a number greater than 0';
                            noOfContainersEl.parentElement.appendChild(span);
                            firstToFocus.push(noOfContainersEl);
                        }
                    }
                    
                    if (!bookingId) {
                        missing.push('Booking ID');
                        if (bookingIdEl) {
                            bookingIdEl.classList.add('border-red-500','bg-red-50');
                            const span = document.createElement('div');
                            span.className = 'field-error-text text-xs text-red-600 mt-1';
                            span.textContent = 'Please enter or generate a booking ID';
                            bookingIdEl.parentElement.appendChild(span);
                            firstToFocus.push(bookingIdEl);
                        }
                    }
                    
                    const submitButton = document.querySelector('button[type="submit"]');
                    
                    if (missing.length > 0) {
                        e.preventDefault();
                        showNotification('Missing: ' + missing.join(', '), 'error');
                        // Focus/scroll to first problematic field
                        const target = firstToFocus[0];
                        if (target) {
                            try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {}
                            try { target.focus({ preventScroll: true }); } catch (_) {}
                        }
                        return false;
                    }
            
                    // No strict booking ID format validation; allow any non-empty value
                    
                    // Container details validation (completely optional for managers and above)
                    // Only validate if user has actually filled in container details
                    const containerTypes = document.querySelectorAll('select[name="container_types[]"]');
            const containerNumbers = document.querySelectorAll('input[name="container_numbers[]"]');
                    
                    // Check if user has filled any container details at all
                    let hasAnyContainerData = false;
                    
                    // Check if any container type is selected
                    for (let i = 0; i < parseInt(noOfContainers); i++) {
                        const typeSelect = document.getElementById('container_type_' + (i + 1));
                        if (typeSelect && typeSelect.value && typeSelect.value.trim() !== '') {
                            hasAnyContainerData = true;
                            break;
                        }
                    }
                    
                    // Check if any container number is filled
                    if (!hasAnyContainerData) {
            const filledNumbers = Array.from(containerNumbers).filter(field => field.value.trim() !== '');
                        if (filledNumbers.length > 0) {
                            hasAnyContainerData = true;
                        }
                    }
                    
                    // Only validate if user has actually provided container data
                    if (hasAnyContainerData) {
                        // Check if all containers have types selected (only if user started filling)
                        for (let i = 0; i < parseInt(noOfContainers); i++) {
                            const typeSelect = document.getElementById('container_type_' + (i + 1));
                            if (typeSelect && !typeSelect.value) {
                                e.preventDefault();
                                showNotification('Please select container type for Container ' + (i + 1) + ' or leave all container details empty.', 'error');
                                return false;
                            }
                        }
                        
                        // Check for duplicate container numbers if any are provided
                        const filledNumbers = Array.from(containerNumbers).filter(field => field.value.trim() !== '');
            if (filledNumbers.length > 0) {
                const numbers = filledNumbers.map(field => field.value.trim().toUpperCase());
                const uniqueNumbers = [...new Set(numbers)];
                
                if (numbers.length !== uniqueNumbers.length) {
                    e.preventDefault();
                                showNotification('Please ensure all container numbers are unique.', 'error');
                    return false;
                }
            }
                    }
                    
                    // Add loading state to button
                    submitButton.classList.add('btn-loading');
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<div class="relative flex items-center">' +
                        '<i class="fas fa-spinner mr-3 text-lg"></i>' +
                        '<span class="text-lg">Creating Booking...</span>' +
                    '</div>';
                });
            }
            
            // Add event listeners for dynamic field generation
            document.getElementById('no_of_containers').addEventListener('input', function(e){ e.stopPropagation(); generateContainerFields(); });
            document.getElementById('no_of_containers').addEventListener('change', function(e){ e.stopPropagation(); generateContainerFields(); });
            
            // Guard: clicks inside container details should not trigger any regeneration
            const containerWrapper = document.getElementById('container-details-container');
            containerWrapper.addEventListener('click', function(e){
                e.stopPropagation();
        });
        
        // Initialize container fields if form has existing data
            const noOfContainers = document.getElementById('no_of_containers').value;
            if (noOfContainers && parseInt(noOfContainers) > 0) {
                generateContainerFields();
                setTimeout(() => { autoCheckSameAllIfMatching(); watchPerContainerDeviation(); if (sameAll && sameAll.checked) applyGlobalLocationsToContainers(); }, 50);
            }
            
            // Test booking ID field
            const bookingIdField = document.getElementById('booking_id');
            const bookingIdHiddenInit = document.getElementById('booking_id_hidden');
            if (bookingIdField) {
                bookingIdField.addEventListener('input', function() {
                    console.log('Booking ID field changed:', this.value);
                    if (bookingIdHiddenInit) bookingIdHiddenInit.value = this.value;
                });
                console.log('Booking ID field initial value:', bookingIdField.value);
                console.log('Booking ID field attributes:', {
                    disabled: bookingIdField.disabled,
                    readonly: bookingIdField.readOnly,
                    type: bookingIdField.type,
                    name: bookingIdField.name
                });
                if (bookingIdHiddenInit) bookingIdHiddenInit.value = bookingIdField.value;
            } else {
                console.error('Booking ID field not found!');
            }
            
            console.log('All event listeners added successfully');
            
            // Test JavaScript functionality
            console.log('JavaScript is working! All functions loaded successfully.');
            
            // Add a simple test to verify DOM is ready
            const testElement = document.createElement('div');
            testElement.style.display = 'none';
            testElement.id = 'js-test';
            document.body.appendChild(testElement);
            console.log('DOM test element added successfully');
        });
        
        // Show notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-sm transform transition-all duration-300 ${
                type === 'error' ? 'bg-red-100 border border-red-300 text-red-800' : 
                type === 'success' ? 'bg-green-100 border border-green-300 text-green-800' :
                'bg-blue-100 border border-blue-300 text-blue-800'
            }`;
            
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'error' ? 'fa-exclamation-circle' : type === 'success' ? 'fa-check-circle' : 'fa-info-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 5000);
        }
        
        // Location Modal Functions
        function openLocationModal() {
            const modal = document.getElementById('locationModal');
            const modalTitle = document.getElementById('modalTitle');
            
            modalTitle.textContent = 'Add New Location';
            
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLocationModal() {
            const modal = document.getElementById('locationModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
            
            // Clear form
            document.getElementById('newLocationName').value = '';
        }
        
        function saveNewLocation() {
            const name = document.getElementById('newLocationName').value.trim();
            
            if (!name) {
                showNotification('Please enter location name', 'error');
                return;
            }
            
            // Create form data
            const formData = new FormData();
            formData.append('action', 'add_location');
            formData.append('location_name', name);
            
            // Show loading state
            const saveBtn = document.getElementById('saveLocationBtn');
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            saveBtn.disabled = true;
            
            // Send AJAX request
            fetch('../location/add_location.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Location added successfully!', 'success');
                    
                    // Add to locations array and update dropdowns
                    const newLocation = {
                        id: data.location_id,
                        location: name
                    };
                    
                    // Add to global locations array
                    locations.push(newLocation);
                    
                    // Refresh both location dropdowns to show the new location
                    setupAutocomplete('from_location', 'from_location_dropdown', 'from_location_id');
                    setupAutocomplete('to_location', 'to_location_dropdown', 'to_location_id');
                    
                    closeLocationModal();
                } else {
                    showNotification(data.message || 'Error adding location', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error adding location. Please try again.', 'error');
            })
            .finally(() => {
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
            });
        }
        
    </script>

    <!-- Location Modal -->
    <div id="locationModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-xl shadow-2xl max-w-md w-full transform transition-all duration-300">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 id="modalTitle" class="text-xl font-bold text-gray-800">Add New Location</h3>
                        <button onclick="closeLocationModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <form id="locationForm" class="space-y-4">
                        <div>
                            <label for="newLocationName" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-map-marker-alt text-absuma-red mr-2"></i>Location Name *
                            </label>
                            <input type="text" id="newLocationName" class="input-enhanced" placeholder="Enter location name" required>
                        </div>
                    </form>
                    
                    <div class="flex gap-3 mt-6">
                        <button id="saveLocationBtn" onclick="saveNewLocation()" 
                                class="flex-1 bg-absuma-red hover:bg-absuma-red-dark text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-save mr-2"></i>Save Location
                        </button>
                        <button onclick="closeLocationModal()" 
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
