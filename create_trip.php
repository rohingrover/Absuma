<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Set page title and subtitle for header component
$page_title = "Create New Trip";
$page_subtitle = "Schedule and manage container movements";

$success = '';
$error = '';

// Handle AJAX requests for vehicle search
if (isset($_GET['search_vehicles'])) {
    header('Content-Type: application/json');
    $search = $_GET['search_vehicles'];
    
    try {
        $vehicles = [];
        
        if (strlen($search) >= 2) {
            $isNumericSearch = is_numeric($search);
            
            // Search owned vehicles
            if ($isNumericSearch && strlen($search) <= 4) {
                // Priority search for last 4 digits
                $ownedVehiclesStmt = $pdo->prepare("
                    SELECT v.id, v.vehicle_number, 
                           COALESCE(NULLIF(v.make_model, ''), 'Unknown') as make_model,
                           COALESCE(v.driver_name, 'No Driver') as driver_name,
                           'owned' as vehicle_type
                    FROM vehicles v 
                    WHERE v.deleted_at IS NULL AND v.current_status = 'available'
                    AND RIGHT(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), 4) LIKE ?
                    ORDER BY v.vehicle_number ASC
                    LIMIT 10
                ");
                $searchParam = "%$search%";
                $ownedVehiclesStmt->execute([$searchParam]);
            } else {
                // General search for text or full vehicle numbers
                $ownedVehiclesStmt = $pdo->prepare("
                    SELECT v.id, v.vehicle_number, 
                           COALESCE(NULLIF(v.make_model, ''), 'Unknown') as make_model,
                           COALESCE(v.driver_name, 'No Driver') as driver_name,
                           'owned' as vehicle_type
                    FROM vehicles v 
                    WHERE v.deleted_at IS NULL AND v.current_status = 'available'
                    AND (v.vehicle_number LIKE ? OR v.make_model LIKE ? OR v.driver_name LIKE ?
                         OR RIGHT(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), 4) LIKE ?)
                    ORDER BY 
                        CASE 
                            WHEN RIGHT(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), 4) LIKE ? THEN 1
                            WHEN v.vehicle_number LIKE ? THEN 2
                            ELSE 3
                        END,
                        v.vehicle_number ASC
                    LIMIT 10
                ");
                $searchParam = "%$search%";
                $ownedVehiclesStmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
            }
            $ownedVehicles = $ownedVehiclesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Search vendor vehicles
            if ($isNumericSearch && strlen($search) <= 4) {
                // Priority search for last 4 digits
                $vendorVehiclesStmt = $pdo->prepare("
                    SELECT vv.id, vv.vehicle_number, 
                           COALESCE(NULLIF(CONCAT(vv.make, ' ', vv.model), ' '), vv.make, vv.model, 'Unknown') as make_model,
                           COALESCE(vv.driver_name, 'No Driver') as driver_name,
                           'vendor' as vehicle_type, vn.company_name as vendor_name
                    FROM vendor_vehicles vv 
                    LEFT JOIN vendors vn ON vv.vendor_id = vn.id 
                    WHERE vv.deleted_at IS NULL AND vn.status = 'active'
                    AND RIGHT(REPLACE(REPLACE(vv.vehicle_number, ' ', ''), '-', ''), 4) LIKE ?
                    ORDER BY vv.vehicle_number ASC
                    LIMIT 10
                ");
                $searchParam = "%$search%";
                $vendorVehiclesStmt->execute([$searchParam]);
            } else {
                // General search for text or full vehicle numbers
                $vendorVehiclesStmt = $pdo->prepare("
                    SELECT vv.id, vv.vehicle_number, 
                           COALESCE(NULLIF(CONCAT(vv.make, ' ', vv.model), ' '), vv.make, vv.model, 'Unknown') as make_model,
                           COALESCE(vv.driver_name, 'No Driver') as driver_name,
                           'vendor' as vehicle_type, vn.company_name as vendor_name
                    FROM vendor_vehicles vv 
                    LEFT JOIN vendors vn ON vv.vendor_id = vn.id 
                    WHERE vv.deleted_at IS NULL AND vn.status = 'active'
                    AND (vv.vehicle_number LIKE ? OR vv.make LIKE ? OR vv.model LIKE ? OR vv.driver_name LIKE ?
                         OR RIGHT(REPLACE(REPLACE(vv.vehicle_number, ' ', ''), '-', ''), 4) LIKE ?)
                    ORDER BY 
                        CASE 
                            WHEN RIGHT(REPLACE(REPLACE(vv.vehicle_number, ' ', ''), '-', ''), 4) LIKE ? THEN 1
                            WHEN vv.vehicle_number LIKE ? THEN 2
                            ELSE 3
                        END,
                        vv.vehicle_number ASC
                    LIMIT 10
                ");
                $searchParam = "%$search%";
                $vendorVehiclesStmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
            }
            $vendorVehicles = $vendorVehiclesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Combine and format results
            foreach ($ownedVehicles as $vehicle) {
                $last4 = substr(preg_replace('/[\s\-]/', '', $vehicle['vehicle_number']), -4);
                $vehicles[] = [
                    'id' => $vehicle['id'] . '|owned',
                    'vehicle_number' => $vehicle['vehicle_number'],
                    'last4' => $last4,
                    'details' => $vehicle['make_model'] . ' - ' . $vehicle['driver_name'],
                    'type' => 'Owned Vehicle',
                    'is_vendor' => false
                ];
            }
            
            foreach ($vendorVehicles as $vehicle) {
                $last4 = substr(preg_replace('/[\s\-]/', '', $vehicle['vehicle_number']), -4);
                $vehicles[] = [
                    'id' => $vehicle['id'] . '|vendor',
                    'vehicle_number' => $vehicle['vehicle_number'],
                    'last4' => $last4,
                    'details' => $vehicle['make_model'] . ' - ' . $vehicle['driver_name'],
                    'type' => 'Vendor: ' . $vehicle['vendor_name'],
                    'is_vendor' => true
                ];
            }
        }
        
        echo json_encode($vehicles);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX requests for client search
if (isset($_GET['search_clients'])) {
    header('Content-Type: application/json');
    $search = $_GET['search_clients'];
    
    try {
        $clients = [];
        
        if (strlen($search) >= 2) {
            $stmt = $pdo->prepare("
                SELECT id, client_name, client_code, contact_person
                FROM clients 
                WHERE status = 'active' AND deleted_at IS NULL 
                AND (client_name LIKE ? OR client_code LIKE ? OR contact_person LIKE ?)
                ORDER BY client_name ASC
                LIMIT 10
            ");
            $searchParam = "%$search%";
            $stmt->execute([$searchParam, $searchParam, $searchParam]);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode($clients);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX requests for location search
if (isset($_GET['search_locations'])) {
    header('Content-Type: application/json');
    $search = $_GET['search_locations'];
    
    try {
        $locations = [];
        
        if (strlen($search) >= 2) {
            // Search both locations and yard locations
            $stmt = $pdo->prepare("
                SELECT 'location' as type, id, location as name, location as display_name 
                FROM location 
                WHERE location LIKE ?
                UNION ALL
                SELECT 'yard' as type, yl.id, yl.yard_name as name, 
                       CONCAT(yl.yard_name, ' (', l.location, ')') as display_name
                FROM yard_locations yl 
                LEFT JOIN location l ON yl.location_id = l.id
                WHERE yl.is_active = 1 AND yl.deleted_at IS NULL 
                AND (yl.yard_name LIKE ? OR l.location LIKE ?)
                ORDER BY display_name ASC
                LIMIT 15
            ");
            $searchParam = "%$search%";
            $stmt->execute([$searchParam, $searchParam, $searchParam]);
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode($locations);
        exit;
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Get form data
        $vehicle_data = explode('|', $_POST['vehicle_id']); // Split vehicle_id and type
        $vehicle_id = $vehicle_data[0];
        $vehicle_type = $vehicle_data[1] ?? 'owned'; // 'owned' or 'vendor'
        $trip_date = $_POST['trip_date'];
        $client_id = $_POST['client_id'];
        $container_type = $_POST['container_type'];
        $from_location = $_POST['from_location'];
        $to_location = $_POST['to_location'];
        $booking_number = trim($_POST['booking_number']);
        $container_numbers = array_filter($_POST['container_numbers']); // Remove empty values
        $vendor_rate = $_POST['vendor_rate'] ?? null;
        $multiple_movements = isset($_POST['multiple_movements']) ? 1 : 0;

        // Validation
        if (empty($trip_date) || empty($vehicle_id) || empty($client_id) || 
            empty($container_type) || empty($from_location) || 
            empty($to_location) || empty($container_numbers) || empty($booking_number)) {
            throw new Exception('Please fill all required fields including at least one container number.');
        }

        // Container validation based on type
        if ($container_type === '20ft' && count($container_numbers) > 2) {
            throw new Exception('Maximum 2 container numbers allowed for 20ft containers.');
        }
        
        if ($container_type === '40ft' && count($container_numbers) > 1) {
            throw new Exception('Only 1 container number allowed for 40ft containers.');
        }

        if ($container_type === '20ft' && count($container_numbers) < 2) {
            throw new Exception('2 container numbers required for 20ft containers.');
        }

        // Get vehicle info based on type
        if ($vehicle_type === 'vendor') {
            $vehicle_info = $pdo->prepare("SELECT vv.*, v.company_name as vendor_name FROM vendor_vehicles vv LEFT JOIN vendors v ON vv.vendor_id = v.id WHERE vv.id = ?");
        } else {
            $vehicle_info = $pdo->prepare("SELECT *, NULL as vendor_id, 'Owned Vehicle' as vendor_name FROM vehicles WHERE id = ?");
        }
        $vehicle_info->execute([$vehicle_id]);
        $vehicle = $vehicle_info->fetch(PDO::FETCH_ASSOC);

        if (!$vehicle) {
            throw new Exception('Invalid vehicle selected.');
        }

        // Validate vendor rate for vendor vehicles
        $is_vendor_vehicle = ($vehicle_type === 'vendor');
        if ($is_vendor_vehicle && (empty($vendor_rate) || !is_numeric($vendor_rate) || $vendor_rate < 0)) {
            throw new Exception('Valid rate is required for vendor vehicles.');
        }

        // Generate unique reference number
        $reference_number = 'ABS-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        
        // Check if reference number already exists
        $check_ref = $pdo->prepare("SELECT id FROM trips WHERE reference_number = ?");
        $check_ref->execute([$reference_number]);
        while ($check_ref->rowCount() > 0) {
            $reference_number = 'ABS-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $check_ref->execute([$reference_number]);
        }

        // Insert trip record
        $stmt = $pdo->prepare("
            INSERT INTO trips (
                reference_number, trip_date, vehicle_id, vehicle_type, client_id, 
                container_type, from_location, to_location, booking_number,
                vendor_rate, is_vendor_vehicle, multiple_movements, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        
        $stmt->execute([
            $reference_number, $trip_date, $vehicle_id, $vehicle_type, $client_id,
            $container_type, $from_location, $to_location, $booking_number,
            $is_vendor_vehicle ? $vendor_rate : null, $is_vendor_vehicle ? 1 : 0, $multiple_movements
        ]);

        $trip_id = $pdo->lastInsertId();

        // Insert container numbers
        foreach ($container_numbers as $container_number) {
            if (!empty(trim($container_number))) {
                $container_stmt = $pdo->prepare("
                    INSERT INTO trip_containers (trip_id, container_number, created_at) 
                    VALUES (?, ?, NOW())
                ");
                $container_stmt->execute([$trip_id, trim($container_number)]);
            }
        }

        // Insert booking entry for tracking multiple movements
        $booking_stmt = $pdo->prepare("
            INSERT INTO booking_movements (booking_number, trip_id, multiple_movements_flag, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $booking_stmt->execute([$booking_number, $trip_id, $multiple_movements]);

        $pdo->commit();
        
        $success = "Trip created successfully! Reference Number: " . $reference_number;
        
        // Redirect to trip details or PDF generation
        $_SESSION['trip_created'] = $trip_id;
        $_SESSION['success'] = $success;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Trip - Absuma Logistics Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'absuma-red': '#e53e3e',
                        'absuma-red-dark': '#c53030',
                        primary: {
                            light: '#fed7d7',
                            DEFAULT: '#e53e3e',
                            dark: '#c53030',
                        },
                        secondary: {
                            light: '#6ee7b7',
                            DEFAULT: '#10b981',
                            dark: '#059669',
                        },
                        accent: {
                            light: '#fcd34d',
                            DEFAULT: '#f59e0b',
                            dark: '#d97706',
                        },
                        dark: '#1e293b',
                        light: '#f8fafc',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.4s ease-out forwards',
                        'slide-down': 'slideDown 0.3s ease-out forwards',
                        'pulse-slow': 'pulse 3s infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: 0, transform: 'translateY(20px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' }
                        },
                        slideDown: {
                            '0%': { opacity: 0, transform: 'translateY(-10px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' }
                        }
                    },
                    boxShadow: {
                        'soft': '0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025)',
                        'glow': '0 0 15px -3px rgba(229, 62, 62, 0.3)',
                        'glow-blue': '0 0 15px -3px rgba(59, 130, 246, 0.3)',
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        
        .absuma-gradient {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
        }
        
        .stats-card {
            @apply bg-white rounded-xl shadow-soft p-6 border-l-4 transition-all duration-300 hover:shadow-glow-blue hover:-translate-y-1;
        }
        
        .card-hover-effect {
            @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
        }

        .form-input {
            @apply w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-absuma-red focus:border-absuma-red transition-all;
        }
        
        .autocomplete-container {
            position: relative;
        }
        
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-top: none;
            border-radius: 0 0 0.5rem 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 50;
            display: none;
        }
        
        .autocomplete-suggestion {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .autocomplete-suggestion:hover,
        .autocomplete-suggestion.selected {
            background-color: #fef2f2;
            color: #e53e3e;
        }
        
        .container-photo-preview {
            max-width: 100px;
            max-height: 100px;
            object-fit: cover;
            border-radius: 0.5rem;
        }
        
        .photo-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .photo-upload-area:hover {
            border-color: #e53e3e;
            background-color: #fef2f2;
        }
        
        .photo-upload-area.dragover {
            border-color: #e53e3e;
            background-color: #fef2f2;
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen">
        <!-- Header with Absuma Branding -->
        <?php include 'header_component.php'; ?>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar with Absuma colors -->
                <aside class="w-full lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-soft p-4 sticky top-20 border border-white/20 backdrop-blur-sm bg-white/70">
                        <!-- Trip Management Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Trip Management</h3>
                            <nav class="space-y-1.5">
                                <a href="create_trip.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium absuma-gradient text-white rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center"></i>Create Trip
                                </a>
                                <a href="manage_trips.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Trips
                                </a>
                                <a href="trip_reports.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-chart-line w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Trip Reports
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Quick Access -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Quick Access</h3>
                            <nav class="space-y-1.5">
                                <a href="manage_vehicles.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-truck w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Vehicles
                                </a>
                                <a href="manage_clients.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-users w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Clients
                                </a>
                                <a href="manage_vendors.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-handshake w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Vendors
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-tachometer-alt w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Dashboard
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Trip Creation Tips -->
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-100 rounded-lg p-4 border border-blue-200">
                            <h4 class="text-sm font-semibold text-blue-800 mb-2 flex items-center">
                                <i class="fas fa-lightbulb mr-2"></i>Quick Tips
                            </h4>
                            <div class="text-xs text-blue-700 space-y-1">
                                <div>• Use last 4 digits for quick vehicle search</div>
                                <div>• 20ft containers require exactly 2 numbers</div>
                                <div>• 40ft containers require only 1 number</div>
                                <div>• Upload container photos for verification</div>
                                <div>• Mark multiple movements if booking has more trips</div>
                            </div>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div class="space-y-6">
                        <!-- Welcome Section -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                        Create New Trip
                                    </h2>
                                    <p class="text-gray-600">Schedule and manage container movements efficiently</p>
                                </div>
                                <div class="hidden md:block">
                                    <div class="bg-red-50 p-4 rounded-lg">
                                        <i class="fas fa-shipping-fast text-3xl text-absuma-red"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Success/Error Messages -->
                        <?php if ($success): ?>
                            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg animate-fade-in">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <?= htmlspecialchars($success) ?>
                                    </div>
                                    <div class="flex gap-2">
                                        <button onclick="generatePDF()" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700 transition-colors">
                                            <i class="fas fa-file-pdf mr-1"></i>Download PDF
                                        </button>
                                        <button onclick="shareTrip()" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700 transition-colors">
                                            <i class="fas fa-share mr-1"></i>Share
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg animate-fade-in">
                                <div class="flex items-center">
                                    <i class="fas fa-exclamation-circle mr-2"></i>
                                    <?= htmlspecialchars($error) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Trip Creation Form -->
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden card-hover-effect">
                            <div class="px-6 py-4 bg-gradient-to-r from-red-50 to-pink-50 border-b border-gray-200">
                                <h3 class="text-xl font-semibold text-gray-800 flex items-center">
                                    <i class="fas fa-clipboard-list text-absuma-red mr-2"></i>Trip Details
                                </h3>
                            </div>

                            <form method="POST" enctype="multipart/form-data" class="p-6" id="tripForm">
                                <!-- Basic Trip Information -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-calendar text-blue-500 mr-2"></i>Basic Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Trip Date -->
                                        <div>
                                            <label for="trip_date" class="block text-sm font-medium text-gray-700 mb-2">
                                                Trip Date <span class="text-absuma-red">*</span>
                                            </label>
                                            <input type="date" id="trip_date" name="trip_date" required
                                                   min="<?= date('Y-m-d') ?>"
                                                   value="<?= date('Y-m-d') ?>"
                                                   class="form-input">
                                        </div>

                                        <!-- Booking Number -->
                                        <div>
                                            <label for="booking_number" class="block text-sm font-medium text-gray-700 mb-2">
                                                Booking Number <span class="text-absuma-red">*</span>
                                            </label>
                                            <input type="text" id="booking_number" name="booking_number" required
                                                   class="form-input"
                                                   placeholder="e.g., BKG-2024-001">
                                            <div class="mt-2">
                                                <label class="inline-flex items-center">
                                                    <input type="checkbox" name="multiple_movements" value="1" class="rounded border-gray-300 text-absuma-red focus:ring-absuma-red">
                                                    <span class="ml-2 text-sm text-gray-600">This booking has multiple movements</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Vehicle Information -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-truck text-green-500 mr-2"></i>Vehicle Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Vehicle Search -->
                                        <div class="autocomplete-container">
                                            <label for="vehicle_search" class="block text-sm font-medium text-gray-700 mb-2">
                                                Vehicle Number <span class="text-absuma-red">*</span>
                                            </label>
                                            <input type="text" id="vehicle_search" 
                                                   class="form-input"
                                                   placeholder="Search by vehicle number or last 4 digits..."
                                                   autocomplete="off">
                                            <input type="hidden" id="vehicle_id" name="vehicle_id" required>
                                            <div id="vehicle_suggestions" class="autocomplete-suggestions"></div>
                                            <div id="vehicle_details" class="mt-2 text-sm text-gray-600 hidden">
                                                <div class="bg-gray-50 p-3 rounded">
                                                    <div class="flex items-center justify-between">
                                                        <span id="selected_vehicle_info"></span>
                                                        <button type="button" onclick="clearVehicleSelection()" class="text-red-500 hover:text-red-700">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <button type="button" onclick="showAddVehicleOptions()" class="text-blue-600 hover:text-blue-800 text-sm">
                                                    <i class="fas fa-plus mr-1"></i>Vehicle not found? Add new vehicle
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Vendor Rate (shown for vendor vehicles) -->
                                        <div id="vendor_rate_section" class="hidden">
                                            <label for="vendor_rate" class="block text-sm font-medium text-gray-700 mb-2">
                                                Vendor Rate <span class="text-absuma-red">*</span>
                                            </label>
                                            <div class="relative">
                                                <span class="absolute left-3 top-2 text-gray-500">₹</span>
                                                <input type="number" id="vendor_rate" name="vendor_rate" 
                                                       class="form-input pl-8" 
                                                       placeholder="0.00" step="0.01" min="0">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Container Information -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-shipping-fast text-purple-500 mr-2"></i>Container Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Container Type -->
                                        <div>
                                            <label for="container_type" class="block text-sm font-medium text-gray-700 mb-2">
                                                Container Type <span class="text-absuma-red">*</span>
                                            </label>
                                            <select id="container_type" name="container_type" required class="form-input" onchange="updateContainerInputs()">
                                                <option value="">Select Container Type</option>
                                                <option value="20ft">20ft Container (2 numbers required)</option>
                                                <option value="40ft">40ft Container (1 number required)</option>
                                            </select>
                                        </div>

                                        <!-- Container Numbers -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                                Container Numbers <span class="text-absuma-red">*</span>
                                            </label>
                                            <div id="container_inputs">
                                                <input type="text" name="container_numbers[]" 
                                                       class="form-input mb-2" 
                                                       placeholder="Enter container number..." 
                                                       disabled>
                                                <p class="text-sm text-gray-500">Select container type first</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Container Photos -->
                                    <div class="mt-6">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Container Photos (1-2 photos)
                                        </label>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="photo-upload-area" onclick="document.getElementById('photo1').click()">
                                                <input type="file" id="photo1" name="container_photos[]" accept="image/*" style="display: none" onchange="previewPhoto(this, 'preview1')">
                                                <div id="preview1_container">
                                                    <i class="fas fa-camera text-gray-400 text-2xl mb-2"></i>
                                                    <p class="text-sm text-gray-600">Click to upload photo 1</p>
                                                </div>
                                            </div>
                                            <div class="photo-upload-area" onclick="document.getElementById('photo2').click()">
                                                <input type="file" id="photo2" name="container_photos[]" accept="image/*" style="display: none" onchange="previewPhoto(this, 'preview2')">
                                                <div id="preview2_container">
                                                    <i class="fas fa-camera text-gray-400 text-2xl mb-2"></i>
                                                    <p class="text-sm text-gray-600">Click to upload photo 2 (optional)</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Location Information -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-map-marker-alt text-orange-500 mr-2"></i>Location Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- From Location -->
                                        <div class="autocomplete-container">
                                            <label for="from_location_search" class="block text-sm font-medium text-gray-700 mb-2">
                                                From Location <span class="text-absuma-red">*</span>
                                            </label>
                                            <input type="text" id="from_location_search" 
                                                   class="form-input"
                                                   placeholder="Search locations or yards..."
                                                   autocomplete="off">
                                            <input type="hidden" id="from_location" name="from_location" required>
                                            <div id="from_location_suggestions" class="autocomplete-suggestions"></div>
                                            <div id="from_location_details" class="mt-2 text-sm text-gray-600 hidden">
                                                <div class="bg-gray-50 p-3 rounded">
                                                    <div class="flex items-center justify-between">
                                                        <span id="selected_from_location"></span>
                                                        <button type="button" onclick="clearLocationSelection('from')" class="text-red-500 hover:text-red-700">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <button type="button" onclick="showAddLocationOptions('from')" class="text-blue-600 hover:text-blue-800 text-sm">
                                                    <i class="fas fa-plus mr-1"></i>Location not found? Add new location
                                                </button>
                                            </div>
                                        </div>

                                        <!-- To Location -->
                                        <div class="autocomplete-container">
                                            <label for="to_location_search" class="block text-sm font-medium text-gray-700 mb-2">
                                                To Location <span class="text-absuma-red">*</span>
                                            </label>
                                            <input type="text" id="to_location_search" 
                                                   class="form-input"
                                                   placeholder="Search locations or yards..."
                                                   autocomplete="off">
                                            <input type="hidden" id="to_location" name="to_location" required>
                                            <div id="to_location_suggestions" class="autocomplete-suggestions"></div>
                                            <div id="to_location_details" class="mt-2 text-sm text-gray-600 hidden">
                                                <div class="bg-gray-50 p-3 rounded">
                                                    <div class="flex items-center justify-between">
                                                        <span id="selected_to_location"></span>
                                                        <button type="button" onclick="clearLocationSelection('to')" class="text-red-500 hover:text-red-700">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <button type="button" onclick="showAddLocationOptions('to')" class="text-blue-600 hover:text-blue-800 text-sm">
                                                    <i class="fas fa-plus mr-1"></i>Location not found? Add new location
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Client Information -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-user-tie text-indigo-500 mr-2"></i>Client Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Client Search -->
                                        <div class="autocomplete-container">
                                            <label for="client_search" class="block text-sm font-medium text-gray-700 mb-2">
                                                Client <span class="text-absuma-red">*</span>
                                            </label>
                                            <input type="text" id="client_search" 
                                                   class="form-input"
                                                   placeholder="Search by client name or code..."
                                                   autocomplete="off">
                                            <input type="hidden" id="client_id" name="client_id" required>
                                            <div id="client_suggestions" class="autocomplete-suggestions"></div>
                                            <div id="client_details" class="mt-2 text-sm text-gray-600 hidden">
                                                <div class="bg-gray-50 p-3 rounded">
                                                    <div class="flex items-center justify-between">
                                                        <span id="selected_client_info"></span>
                                                        <button type="button" onclick="clearClientSelection()" class="text-red-500 hover:text-red-700">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <button type="button" onclick="showAddClientOptions()" class="text-blue-600 hover:text-blue-800 text-sm">
                                                    <i class="fas fa-plus mr-1"></i>Client not found? Add new client
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="border-t border-gray-200 pt-6">
                                    <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3">
                                        <button type="button" onclick="resetForm()" 
                                                class="px-6 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors flex items-center justify-center">
                                            <i class="fas fa-undo mr-2"></i>Reset Form
                                        </button>
                                        <button type="submit" 
                                                class="absuma-gradient text-white px-6 py-2 rounded-lg shadow-sm text-sm font-medium hover:shadow-glow focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-absuma-red transition-all flex items-center justify-center">
                                            <i class="fas fa-plus mr-2"></i>Create Trip
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                    </div>
                </main>
            </div>
        </div>
    </div>

    <!-- Add Vehicle Modal -->
    <div id="addVehicleModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Add New Vehicle</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <button onclick="redirectToAddVehicle()" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-plus mr-2"></i>Add to Own Fleet
                        </button>
                        <button onclick="redirectToAddVendorVehicle()" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                            <i class="fas fa-handshake mr-2"></i>Add to Existing Vendor
                        </button>
                        <button onclick="redirectToAddVendor()" class="w-full bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-colors">
                            <i class="fas fa-building mr-2"></i>Add New Vendor
                        </button>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 text-right">
                    <button onclick="closeAddVehicleModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Location Modal -->
    <div id="addLocationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Add New Location</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <button onclick="redirectToAddLocation()" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-map-marker-alt mr-2"></i>Add Main Location
                        </button>
                        <button onclick="redirectToAddYard()" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                            <i class="fas fa-warehouse mr-2"></i>Add Yard Location
                        </button>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 text-right">
                    <button onclick="closeAddLocationModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Client Modal -->
    <div id="addClientModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Add New Client</h3>
                </div>
                <div class="p-6">
                    <button onclick="redirectToAddClient()" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-user-plus mr-2"></i>Add New Client
                    </button>
                </div>
                <div class="px-6 py-4 bg-gray-50 text-right">
                    <button onclick="closeAddClientModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for functionality -->
    <script>
        // Global variables
        let selectedVehicle = null;
        let selectedClient = null;
        let selectedFromLocation = null;
        let selectedToLocation = null;
        
        // Debounce function for AJAX calls
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Vehicle search functionality
        const vehicleSearch = document.getElementById('vehicle_search');
        const vehicleSuggestions = document.getElementById('vehicle_suggestions');
        
        const debouncedVehicleSearch = debounce(function(query) {
            if (query.length >= 2) {
                fetch(`?search_vehicles=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('Vehicle search error:', data.error);
                            return;
                        }
                        
                        vehicleSuggestions.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach(vehicle => {
                                const suggestion = document.createElement('div');
                                suggestion.className = 'autocomplete-suggestion';
                                suggestion.innerHTML = `
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <div class="font-medium">${vehicle.vehicle_number}</div>
                                            <div class="text-xs text-gray-500">${vehicle.details}</div>
                                        </div>
                                        <div class="text-xs ${vehicle.is_vendor ? 'text-orange-600' : 'text-blue-600'}">${vehicle.type}</div>
                                    </div>
                                `;
                                suggestion.onclick = () => selectVehicle(vehicle);
                                vehicleSuggestions.appendChild(suggestion);
                            });
                            vehicleSuggestions.style.display = 'block';
                        } else {
                            vehicleSuggestions.style.display = 'none';
                        }
                    })
                    .catch(error => console.error('Vehicle search error:', error));
            } else {
                vehicleSuggestions.style.display = 'none';
            }
        }, 300);

        vehicleSearch.addEventListener('input', function() {
            debouncedVehicleSearch(this.value);
        });

        function selectVehicle(vehicle) {
            selectedVehicle = vehicle;
            document.getElementById('vehicle_search').value = vehicle.vehicle_number;
            document.getElementById('vehicle_id').value = vehicle.id;
            document.getElementById('selected_vehicle_info').textContent = `${vehicle.vehicle_number} - ${vehicle.details} (${vehicle.type_label})`;
            document.getElementById('vehicle_details').classList.remove('hidden');
            vehicleSuggestions.style.display = 'none';
            
            // Show/hide vendor rate section
            const vendorRateSection = document.getElementById('vendor_rate_section');
            if (vehicle.is_vendor) {
                vendorRateSection.classList.remove('hidden');
                document.getElementById('vendor_rate').required = true;
            } else {
                vendorRateSection.classList.add('hidden');
                document.getElementById('vendor_rate').required = false;
            }
        }

        function clearVehicleSelection() {
            selectedVehicle = null;
            document.getElementById('vehicle_search').value = '';
            document.getElementById('vehicle_id').value = '';
            document.getElementById('vehicle_details').classList.add('hidden');
            document.getElementById('vendor_rate_section').classList.add('hidden');
            document.getElementById('vendor_rate').required = false;
        }

        // Client search functionality
        const clientSearch = document.getElementById('client_search');
        const clientSuggestions = document.getElementById('client_suggestions');
        
        const debouncedClientSearch = debounce(function(query) {
            if (query.length >= 2) {
                fetch(`?search_clients=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('Client search error:', data.error);
                            return;
                        }
                        
                        clientSuggestions.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach(client => {
                                const suggestion = document.createElement('div');
                                suggestion.className = 'autocomplete-suggestion';
                                suggestion.innerHTML = `
                                    <div>
                                        <div class="font-medium">${client.client_name}</div>
                                        <div class="text-xs text-gray-500">Code: ${client.client_code} | Contact: ${client.contact_person || 'N/A'}</div>
                                    </div>
                                `;
                                suggestion.onclick = () => selectClient(client);
                                clientSuggestions.appendChild(suggestion);
                            });
                            clientSuggestions.style.display = 'block';
                        } else {
                            clientSuggestions.style.display = 'none';
                        }
                    })
                    .catch(error => console.error('Client search error:', error));
            } else {
                clientSuggestions.style.display = 'none';
            }
        }, 300);

        clientSearch.addEventListener('input', function() {
            debouncedClientSearch(this.value);
        });

        function selectClient(client) {
            selectedClient = client;
            document.getElementById('client_search').value = client.client_name;
            document.getElementById('client_id').value = client.id;
            document.getElementById('selected_client_info').textContent = `${client.client_name} (${client.client_code})`;
            document.getElementById('client_details').classList.remove('hidden');
            clientSuggestions.style.display = 'none';
        }

        function clearClientSelection() {
            selectedClient = null;
            document.getElementById('client_search').value = '';
            document.getElementById('client_id').value = '';
            document.getElementById('client_details').classList.add('hidden');
        }

        // Location search functionality
        function setupLocationSearch(searchInputId, suggestionsId, hiddenInputId, detailsId, selectedInfoId, type) {
            const searchInput = document.getElementById(searchInputId);
            const suggestions = document.getElementById(suggestionsId);
            
            const debouncedLocationSearch = debounce(function(query) {
                if (query.length >= 2) {
                    fetch(`?search_locations=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                console.error('Location search error:', data.error);
                                return;
                            }
                            
                            suggestions.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(location => {
                                    const suggestion = document.createElement('div');
                                    suggestion.className = 'autocomplete-suggestion';
                                    suggestion.innerHTML = `
                                        <div class="flex justify-between items-center">
                                            <div class="font-medium">${location.display_name}</div>
                                            <div class="text-xs ${location.type === 'yard' ? 'text-green-600' : 'text-blue-600'}">${location.type === 'yard' ? 'Yard' : 'Location'}</div>
                                        </div>
                                    `;
                                    suggestion.onclick = () => selectLocation(location, hiddenInputId, detailsId, selectedInfoId, type, suggestions, searchInput);
                                    suggestions.appendChild(suggestion);
                                });
                                suggestions.style.display = 'block';
                            } else {
                                suggestions.style.display = 'none';
                            }
                        })
                        .catch(error => console.error('Location search error:', error));
                } else {
                    suggestions.style.display = 'none';
                }
            }, 300);

            searchInput.addEventListener('input', function() {
                debouncedLocationSearch(this.value);
            });
        }

        function selectLocation(location, hiddenInputId, detailsId, selectedInfoId, type, suggestions, searchInput) {
            if (type === 'from') {
                selectedFromLocation = location;
            } else {
                selectedToLocation = location;
            }
            
            searchInput.value = location.display_name;
            document.getElementById(hiddenInputId).value = `${location.type}:${location.id}`;
            document.getElementById(selectedInfoId).textContent = location.display_name;
            document.getElementById(detailsId).classList.remove('hidden');
            suggestions.style.display = 'none';
        }

        function clearLocationSelection(type) {
            if (type === 'from') {
                selectedFromLocation = null;
                document.getElementById('from_location_search').value = '';
                document.getElementById('from_location').value = '';
                document.getElementById('from_location_details').classList.add('hidden');
            } else {
                selectedToLocation = null;
                document.getElementById('to_location_search').value = '';
                document.getElementById('to_location').value = '';
                document.getElementById('to_location_details').classList.add('hidden');
            }
        }

        // Initialize location search
        setupLocationSearch('from_location_search', 'from_location_suggestions', 'from_location', 'from_location_details', 'selected_from_location', 'from');
        setupLocationSearch('to_location_search', 'to_location_suggestions', 'to_location', 'to_location_details', 'selected_to_location', 'to');

        // Container type and input management
        function updateContainerInputs() {
            const containerType = document.getElementById('container_type').value;
            const containerInputs = document.getElementById('container_inputs');
            
            if (containerType === '20ft') {
                containerInputs.innerHTML = `
                    <input type="text" name="container_numbers[]" class="form-input mb-2" placeholder="Enter first container number..." required>
                    <input type="text" name="container_numbers[]" class="form-input mb-2" placeholder="Enter second container number..." required>
                    <p class="text-sm text-green-600"><i class="fas fa-info-circle mr-1"></i>20ft containers require exactly 2 numbers</p>
                `;
            } else if (containerType === '40ft') {
                containerInputs.innerHTML = `
                    <input type="text" name="container_numbers[]" class="form-input mb-2" placeholder="Enter container number..." required>
                    <p class="text-sm text-blue-600"><i class="fas fa-info-circle mr-1"></i>40ft containers require only 1 number</p>
                `;
            } else {
                containerInputs.innerHTML = `
                    <input type="text" name="container_numbers[]" class="form-input mb-2" placeholder="Enter container number..." disabled>
                    <p class="text-sm text-gray-500">Select container type first</p>
                `;
            }
        }

        // Photo preview functionality
        function previewPhoto(input, previewId) {
            const file = input.files[0];
            const previewContainer = document.getElementById(previewId + '_container');
            
            if (file) {
                if (file.size > 5 * 1024 * 1024) { // 5MB limit
                    alert('File size must be less than 5MB');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewContainer.innerHTML = `
                        <img src="${e.target.result}" class="container-photo-preview mx-auto mb-2" alt="Container photo">
                        <p class="text-sm text-green-600">Photo uploaded</p>
                        <button type="button" onclick="removePhoto('${input.id}', '${previewId}')" class="text-red-500 text-xs hover:text-red-700 mt-1">
                            <i class="fas fa-trash mr-1"></i>Remove
                        </button>
                    `;
                };
                reader.readAsDataURL(file);
            }
        }

        function removePhoto(inputId, previewId) {
            document.getElementById(inputId).value = '';
            const previewContainer = document.getElementById(previewId + '_container');
            const photoNumber = previewId.includes('1') ? '1' : '2 (optional)';
            previewContainer.innerHTML = `
                <i class="fas fa-camera text-gray-400 text-2xl mb-2"></i>
                <p class="text-sm text-gray-600">Click to upload photo ${photoNumber}</p>
            `;
        }

        // Modal functions
        function showAddVehicleOptions() {
            document.getElementById('addVehicleModal').classList.remove('hidden');
        }

        function closeAddVehicleModal() {
            document.getElementById('addVehicleModal').classList.add('hidden');
        }

        function showAddLocationOptions(type) {
            window.currentLocationType = type;
            document.getElementById('addLocationModal').classList.remove('hidden');
        }

        function closeAddLocationModal() {
            document.getElementById('addLocationModal').classList.add('hidden');
        }

        function showAddClientOptions() {
            document.getElementById('addClientModal').classList.remove('hidden');
        }

        function closeAddClientModal() {
            document.getElementById('addClientModal').classList.add('hidden');
        }

        // Redirect functions
        function redirectToAddVehicle() {
            window.open('add_vehicle.php', '_blank');
        }

        function redirectToAddVendorVehicle() {
            window.open('manage_vendors.php', '_blank');
        }

        function redirectToAddVendor() {
            window.open('vendor_registration.php', '_blank');
        }

        function redirectToAddLocation() {
            window.open('add_location.php', '_blank');
        }

        function redirectToAddYard() {
            window.open('add_yard_location.php', '_blank');
        }

        function redirectToAddClient() {
            window.open('add_client.php', '_blank');
        }

        // Form validation and submission
        document.getElementById('tripForm').addEventListener('submit', function(e) {
            // Validate required fields
            const requiredFields = ['trip_date', 'booking_number', 'vehicle_id', 'container_type', 'from_location', 'to_location', 'client_id'];
            let isValid = true;
            
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('border-red-300', 'bg-red-50');
                } else {
                    field.classList.remove('border-red-300', 'bg-red-50');
                }
            });

            // Validate container numbers
            const containerNumbers = document.querySelectorAll('input[name="container_numbers[]"]');
            const containerType = document.getElementById('container_type').value;
            
            if (containerType === '20ft') {
                let filledContainers = 0;
                containerNumbers.forEach(input => {
                    if (input.value.trim()) filledContainers++;
                });
                if (filledContainers !== 2) {
                    isValid = false;
                    alert('20ft containers require exactly 2 container numbers');
                }
            } else if (containerType === '40ft') {
                if (!containerNumbers[0] || !containerNumbers[0].value.trim()) {
                    isValid = false;
                    alert('40ft containers require 1 container number');
                }
            }

            // Validate vendor rate for vendor vehicles
            if (selectedVehicle && selectedVehicle.is_vendor) {
                const vendorRate = document.getElementById('vendor_rate');
                if (!vendorRate.value || parseFloat(vendorRate.value) <= 0) {
                    isValid = false;
                    vendorRate.classList.add('border-red-300', 'bg-red-50');
                    alert('Valid vendor rate is required for vendor vehicles');
                } else {
                    vendorRate.classList.remove('border-red-300', 'bg-red-50');
                }
            }

            // Validate at least one container photo
            const photo1 = document.getElementById('photo1').files[0];
            const photo2 = document.getElementById('photo2').files[0];
            
            if (!photo1 && !photo2) {
                isValid = false;
                alert('At least one container photo is required');
            }

            if (!isValid) {
                e.preventDefault();
                return false;
            }

            // Show loading state
            const submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creating Trip...';
        });

        // Reset form function
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All data will be lost.')) {
                document.getElementById('tripForm').reset();
                clearVehicleSelection();
                clearClientSelection();
                clearLocationSelection('from');
                clearLocationSelection('to');
                updateContainerInputs();
                removePhoto('photo1', 'preview1');
                removePhoto('photo2', 'preview2');
            }
        }

        // PDF generation and sharing functions
        function generatePDF() {
            if (<?= json_encode(isset($_SESSION['trip_created'])) ?>) {
                const tripId = <?= json_encode($_SESSION['trip_created'] ?? 0) ?>;
                window.open(`generate_trip_pdf.php?trip_id=${tripId}`, '_blank');
            }
        }

        function shareTrip() {
            if (<?= json_encode(isset($_SESSION['trip_created'])) ?>) {
                const tripId = <?= json_encode($_SESSION['trip_created'] ?? 0) ?>;
                
                // Create share options modal
                const shareModal = document.createElement('div');
                shareModal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 z-50 flex items-center justify-center p-4';
                shareModal.innerHTML = `
                    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900">Share Trip</h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <button onclick="shareViaEmail(${tripId})" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                                <i class="fas fa-envelope mr-2"></i>Share via Email
                            </button>
                            <button onclick="shareViaWhatsApp(${tripId})" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                                <i class="fab fa-whatsapp mr-2"></i>Share via WhatsApp
                            </button>
                            <button onclick="copyTripLink(${tripId})" class="w-full bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors">
                                <i class="fas fa-copy mr-2"></i>Copy Link
                            </button>
                        </div>
                        <div class="px-6 py-4 bg-gray-50 text-right">
                            <button onclick="this.closest('.fixed').remove()" class="px-4 py-2 text-gray-600 hover:text-gray-800">Close</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(shareModal);
            }
        }

        function shareViaEmail(tripId) {
            const subject = encodeURIComponent('Trip Details - Absuma Logistics');
            const body = encodeURIComponent(`Please find the trip details attached. View online: ${window.location.origin}/view_trip.php?id=${tripId}`);
            window.open(`mailto:?subject=${subject}&body=${body}`);
        }

        function shareViaWhatsApp(tripId) {
            const message = encodeURIComponent(`Trip created successfully! View details: ${window.location.origin}/view_trip.php?id=${tripId}`);
            window.open(`https://wa.me/?text=${message}`);
        }

        function copyTripLink(tripId) {
            const link = `${window.location.origin}/view_trip.php?id=${tripId}`;
            navigator.clipboard.writeText(link).then(() => {
                alert('Trip link copied to clipboard!');
            });
        }

        // Auto-hide success messages
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.animate-fade-in');
            alerts.forEach(alert => {
                if (alert.classList.contains('bg-green-50') || alert.classList.contains('bg-red-50')) {
                    setTimeout(() => {
                        alert.style.transition = 'opacity 0.5s ease-out';
                        alert.style.opacity = '0';
                        setTimeout(() => {
                            alert.remove();
                        }, 500);
                    }, 10000); // 10 seconds for trip creation success
                }
            });
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.autocomplete-container')) {
                document.querySelectorAll('.autocomplete-suggestions').forEach(suggestions => {
                    suggestions.style.display = 'none';
                });
            }
        });

        // Keyboard navigation for autocomplete
        document.addEventListener('keydown', function(e) {
            const activeSuggestions = document.querySelector('.autocomplete-suggestions[style*="block"]');
            if (activeSuggestions && (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter')) {
                e.preventDefault();
                const suggestions = activeSuggestions.querySelectorAll('.autocomplete-suggestion');
                const selected = activeSuggestions.querySelector('.autocomplete-suggestion.selected');
                
                if (e.key === 'ArrowDown') {
                    if (selected) {
                        selected.classList.remove('selected');
                        const next = selected.nextElementSibling || suggestions[0];
                        next.classList.add('selected');
                    } else {
                        suggestions[0]?.classList.add('selected');
                    }
                } else if (e.key === 'ArrowUp') {
                    if (selected) {
                        selected.classList.remove('selected');
                        const prev = selected.previousElementSibling || suggestions[suggestions.length - 1];
                        prev.classList.add('selected');
                    } else {
                        suggestions[suggestions.length - 1]?.classList.add('selected');
                    }
                } else if (e.key === 'Enter') {
                    if (selected) {
                        selected.click();
                    }
                }
            }
        });

        // Drag and drop for photos
        ['photo1', 'photo2'].forEach(photoId => {
            const photoArea = document.querySelector(`[onclick="document.getElementById('${photoId}').click()"]`);
            
            photoArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            photoArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            photoArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const photoInput = document.getElementById(photoId);
                    photoInput.files = files;
                    previewPhoto(photoInput, photoId.replace('photo', 'preview'));
                }
            });
        });

        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            updateContainerInputs();
        });
    </script>
</body>
</html>