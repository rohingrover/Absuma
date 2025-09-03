<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Get form data
        $vehicle_data = explode('|', $_POST['vehicle_id']); // Split vehicle_id and type
        $vehicle_id = $vehicle_data[0];
        $vehicle_type = $vehicle_data[1] ?? 'owned'; // 'owned' or 'vendor'
        $trip_date = $_POST['trip_date'];
        $movement_type = $_POST['movement_type'];
        $client_id = $_POST['client_id'];
        $container_type = $_POST['container_type'];
        $vessel_name = $_POST['vessel_name'] ?? '';
        $from_location = $_POST['from_location'];
        $to_location = $_POST['to_location'];
        $container_numbers = array_filter($_POST['container_numbers']); // Remove empty values
        $vendor_rate = $_POST['vendor_rate'] ?? null;

        // Validation
        if (empty($trip_date) || empty($vehicle_id) || empty($movement_type) || 
            empty($client_id) || empty($container_type) || empty($from_location) || 
            empty($to_location) || empty($container_numbers)) {
            throw new Exception('Please fill all required fields including at least one container number.');
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

        // Determine if it's a 20ft or 40ft based on container count allowed
        $container_size = count($container_numbers) > 1 ? '20ft' : '40ft';
        
        // For 20ft containers, max 2 containers allowed
        if ($container_size === '20ft' && count($container_numbers) > 2) {
            throw new Exception('Maximum 2 container numbers allowed for 20ft containers.');
        }
        
        // For 40ft containers, only 1 container allowed
        if ($container_size === '40ft' && count($container_numbers) > 1) {
            throw new Exception('Only 1 container number allowed for 40ft containers.');
        }

        // Generate unique reference number
        $reference_number = 'LR-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        
        // Check if reference number already exists
        $check_ref = $pdo->prepare("SELECT id FROM trips WHERE reference_number = ?");
        $check_ref->execute([$reference_number]);
        while ($check_ref->rowCount() > 0) {
            $reference_number = 'LR-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $check_ref->execute([$reference_number]);
        }

        // Validate vendor rate for vendor vehicles
        $is_vendor_vehicle = ($vehicle_type === 'vendor');
        if ($is_vendor_vehicle && (empty($vendor_rate) || !is_numeric($vendor_rate) || $vendor_rate < 0)) {
            throw new Exception('Valid rate is required for vendor vehicles.');
        }

        // Insert trip record
        $stmt = $pdo->prepare("
            INSERT INTO trips (
                reference_number, trip_date, vehicle_id, vehicle_type, movement_type, client_id, 
                container_type, container_size, vessel_name, from_location, to_location, 
                vendor_rate, is_vendor_vehicle, status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        
        $stmt->execute([
            $reference_number, $trip_date, $vehicle_id, $vehicle_type, $movement_type, $client_id,
            $container_type, $container_size, $vessel_name, $from_location, $to_location,
            $is_vendor_vehicle ? $vendor_rate : null, $is_vendor_vehicle ? 1 : 0
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

        $pdo->commit();
        
        $success = "Trip created successfully! Reference Number: <strong>$reference_number</strong>";

        // Clear form data after successful submission
        $_POST = [];

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Handle AJAX requests for vehicle search
if (isset($_GET['search_vehicles'])) {
    header('Content-Type: application/json');
    $search = $_GET['search_vehicles'];
    
    try {
        $vehicles = [];
        
        if (strlen($search) >= 2) {
            // Check if search is numeric (likely last 4 digits)
            $isNumericSearch = is_numeric($search);
            
            // Search owned vehicles
            if ($isNumericSearch && strlen($search) <= 4) {
                // Priority search for last 4 digits
                $ownedVehiclesStmt = $pdo->prepare("
                    SELECT v.id, v.vehicle_number, v.make_model, v.driver_name,
                           'owned' as vehicle_type, 'Owned Vehicle' as vendor_name
                    FROM vehicles v 
                    WHERE v.current_status = 'available' 
                    AND RIGHT(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), 4) LIKE ?
                    ORDER BY v.vehicle_number ASC
                    LIMIT 10
                ");
                $searchParam = "%$search%";
                $ownedVehiclesStmt->execute([$searchParam]);
            } else {
                // General search for text or full vehicle numbers
                $ownedVehiclesStmt = $pdo->prepare("
                    SELECT v.id, v.vehicle_number, v.make_model, v.driver_name,
                           'owned' as vehicle_type, 'Owned Vehicle' as vendor_name
                    FROM vehicles v 
                    WHERE v.current_status = 'available' 
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
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $client) {
                $clients[] = [
                    'id' => $client['id'],
                    'name' => $client['client_name'],
                    'code' => $client['client_code'],
                    'contact' => $client['contact_person']
                ];
            }
        }
        
        echo json_encode($clients);
        exit;
    } catch (Exception $e) {
        echo json_encode([]);
        exit;
    }
}

// Get clients - removed since we're using AJAX search
$clients = [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Trip - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="notifications.js"></script>
    <style>
        .form-grid {
            @apply grid grid-cols-1 md:grid-cols-2 gap-6;
        }
        .form-field {
            @apply space-y-2;
        }
        .card-hover-effect {
            @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
        }
        .container-input {
            @apply flex items-center gap-2 mb-3;
        }
        .remove-container {
            @apply text-red-600 hover:text-red-800 cursor-pointer p-1 rounded hover:bg-red-100;
        }
        .vendor-rate-field {
            display: none;
        }
        .vendor-rate-field.show {
            display: block;
        }
        
        /* Search dropdown styles */
        .search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .search-dropdown.show {
            display: block;
        }
        
        .search-dropdown-item {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }
        
        .search-dropdown-item:hover,
        .search-dropdown-item.highlighted {
            background-color: #f3f4f6;
        }
        
        .search-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .search-input-container {
            position: relative;
        }
        
        .loading-spinner {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1rem;
            height: 1rem;
            border: 2px solid #f3f4f6;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            display: none;
        }
        
        @keyframes spin {
            0% { transform: translateY(-50%) rotate(0deg); }
            100% { transform: translateY(-50%) rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <aside class="w-64 bg-white border-r border-gray-200 shadow-sm">
            <div class="p-6">
                <div class="flex items-center gap-3 mb-8">
                    <div class="bg-blue-600 p-2 rounded-lg text-white">
                        <i class="fas fa-truck text-lg"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-gray-800">Fleet Manager</h2>
                        <p class="text-xs text-gray-500">Trip Management</p>
                    </div>
                </div>
                
                <nav class="space-y-2">
                    <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors">
                        <i class="fas fa-tachometer-alt w-5 text-center"></i> Dashboard
                    </a>
                    <div class="px-2 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider">Trip Management</div>
                    <a href="create_trip.php" class="flex items-center gap-3 px-4 py-2 bg-blue-50 text-blue-600 rounded-lg">
                        <i class="fas fa-plus w-5 text-center"></i> Create Trip
                    </a>
                    <a href="manage_trips.php" class="flex items-center gap-3 px-4 py-2 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors">
                        <i class="fas fa-list w-5 text-center"></i> Manage Trips
                    </a>
                    <a href="trip_reports.php" class="flex items-center gap-3 px-4 py-2 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors">
                        <i class="fas fa-chart-line w-5 text-center"></i> Trip Reports
                    </a>
                    <div class="px-2 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider">Management</div>
                    <a href="manage_clients.php" class="flex items-center gap-3 px-4 py-2 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors">
                        <i class="fas fa-users w-5 text-center"></i> Manage Clients
                    </a>
                    <a href="manage_vendors.php" class="flex items-center gap-3 px-4 py-2 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors">
                        <i class="fas fa-handshake w-5 text-center"></i> Manage Vendors
                    </a>
                    <a href="manage_vehicles.php" class="flex items-center gap-3 px-4 py-2 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors">
                        <i class="fas fa-truck w-5 text-center"></i> Manage Vehicles
                    </a>
                    <a href="manage_drivers.php" class="flex items-center gap-3 px-4 py-2 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-colors">
                        <i class="fas fa-id-card w-5 text-center"></i> Manage Drivers
                    </a>
                </nav>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6">
            <!-- Header -->
            <header class="mb-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Create New Trip</h1>
                        <p class="text-gray-600">Create and schedule a new trip assignment</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-50 px-4 py-2 rounded-lg border border-blue-100">
                            <div class="text-blue-800 font-medium"><?= $_SESSION['username'] ?></div>
                            <div class="text-blue-600 text-sm">Administrator</div>
                        </div>
                        <a href="logout.php" class="text-red-600 hover:text-red-800 p-2 rounded-lg hover:bg-red-50 transition-colors" title="Logout">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                </div>
            </header>

            <!-- Success/Error Messages -->
            <?php if (!empty($success)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-check-circle text-green-600"></i>
                        <p><?= $success ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-circle text-red-600"></i>
                        <p><?= htmlspecialchars($error) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Trip Creation Form -->
            <div class="bg-white rounded-xl shadow-sm card-hover-effect">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-route text-blue-600"></i> Trip Details
                    </h2>
                </div>

                <form method="POST" class="p-6">
                    <div class="form-grid">
                        <!-- Trip Date -->
                        <div class="form-field">
                            <label for="trip_date" class="block text-sm font-medium text-gray-700">Trip Date *</label>
                            <input type="date" id="trip_date" name="trip_date" value="<?= $_POST['trip_date'] ?? date('Y-m-d') ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <!-- Vehicle Number -->
                        <div class="form-field">
                            <label for="vehicle_search" class="block text-sm font-medium text-gray-700">Vehicle Number *</label>
                            <div class="search-input-container">
                                <input type="text" id="vehicle_search" name="vehicle_search" required 
                                       placeholder="Type last 4 digits or vehicle number..." 
                                       autocomplete="off"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <input type="hidden" id="vehicle_id" name="vehicle_id" value="<?= htmlspecialchars($_POST['vehicle_id'] ?? '') ?>">
                                <div class="loading-spinner" id="vehicle_loading"></div>
                                <div class="search-dropdown" id="vehicle_dropdown"></div>
                            </div>
                            <p class="text-sm text-gray-500 mt-1">
                                <i class="fas fa-info-circle mr-1"></i>
                                Quick search: Type last 4 digits (e.g., 1234) or search by vehicle number, make/model, driver name
                            </p>
                        </div>

                        <!-- Movement Type -->
                        <div class="form-field">
                            <label for="movement_type" class="block text-sm font-medium text-gray-700">Type of Movement *</label>
                            <select id="movement_type" name="movement_type" required class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Movement Type</option>
                                <option value="Port Movement" <?= ($_POST['movement_type'] ?? '') === 'Port Movement' ? 'selected' : '' ?>>Port Movement</option>
                                <option value="Import Movement" <?= ($_POST['movement_type'] ?? '') === 'Import Movement' ? 'selected' : '' ?>>Import Movement</option>
                                <option value="Export Movement" <?= ($_POST['movement_type'] ?? '') === 'Export Movement' ? 'selected' : '' ?>>Export Movement</option>
                                <option value="Long Distance" <?= ($_POST['movement_type'] ?? '') === 'Long Distance' ? 'selected' : '' ?>>Long Distance</option>
                            </select>
                        </div>

                        <!-- Client Name -->
                        <div class="form-field">
                            <label for="client_search" class="block text-sm font-medium text-gray-700">Client Name *</label>
                            <div class="search-input-container">
                                <input type="text" id="client_search" name="client_search" required 
                                       placeholder="Type to search clients..." 
                                       autocomplete="off"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <input type="hidden" id="client_id" name="client_id" value="<?= htmlspecialchars($_POST['client_id'] ?? '') ?>">
                                <div class="loading-spinner" id="client_loading"></div>
                                <div class="search-dropdown" id="client_dropdown"></div>
                            </div>
                            <p class="text-sm text-gray-500 mt-1">Search by client name, code, or contact person</p>
                        </div>

                        <!-- Container Type -->
                        <div class="form-field">
                            <label for="container_type" class="block text-sm font-medium text-gray-700">Container Type *</label>
                            <select id="container_type" name="container_type" required class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Container Type</option>
                                <option value="Empty" <?= ($_POST['container_type'] ?? '') === 'Empty' ? 'selected' : '' ?>>Empty</option>
                                <option value="Loaded" <?= ($_POST['container_type'] ?? '') === 'Loaded' ? 'selected' : '' ?>>Loaded</option>
                            </select>
                        </div>

                        <!-- Name of Vessel -->
                        <div class="form-field">
                            <label for="vessel_name" class="block text-sm font-medium text-gray-700">Name of Vessel</label>
                            <input type="text" id="vessel_name" name="vessel_name" value="<?= htmlspecialchars($_POST['vessel_name'] ?? '') ?>" placeholder="Enter vessel name" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <!-- From Location -->
                        <div class="form-field">
                            <label for="from_location" class="block text-sm font-medium text-gray-700">From Location *</label>
                            <input type="text" id="from_location" name="from_location" value="<?= htmlspecialchars($_POST['from_location'] ?? '') ?>" required placeholder="Enter pickup location" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <!-- To Location -->
                        <div class="form-field">
                            <label for="to_location" class="block text-sm font-medium text-gray-700">To Location *</label>
                            <input type="text" id="to_location" name="to_location" value="<?= htmlspecialchars($_POST['to_location'] ?? '') ?>" required placeholder="Enter delivery location" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <!-- Vendor Rate (Hidden by default) -->
                        <div class="form-field vendor-rate-field" id="vendor_rate_field">
                            <label for="vendor_rate" class="block text-sm font-medium text-gray-700">Vendor Rate (₹) *</label>
                            <input type="number" id="vendor_rate" name="vendor_rate" value="<?= htmlspecialchars($_POST['vendor_rate'] ?? '') ?>" step="0.01" min="0" placeholder="Enter agreed rate" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-sm text-gray-500 mt-1">Rate to be paid to the vendor for this trip</p>
                        </div>
                    </div>

                    <!-- Container Numbers -->
                    <div class="mt-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Container Numbers *</label>
                        <div id="container_numbers_section">
                            <div class="container-input">
                                <input type="text" name="container_numbers[]" value="<?= htmlspecialchars(($_POST['container_numbers'][0] ?? '')) ?>" placeholder="Enter container number" class="flex-1 px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <button type="button" onclick="addContainerField()" class="px-3 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>
                            For 20ft containers: Maximum 2 container numbers | For 40ft containers: Only 1 container number
                        </p>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="flex justify-end gap-4 mt-8 pt-6 border-t border-gray-200">
                        <a href="dashboard.php" class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Cancel
                        </a>
                        <button type="submit" class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <i class="fas fa-plus mr-2"></i>Create Trip
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        let containerCount = 1;
        let vehicleSearchTimeout;
        let clientSearchTimeout;
        let selectedVehicle = null;
        let selectedClient = null;

        // Vehicle search functionality
        function initializeVehicleSearch() {
            const searchInput = document.getElementById('vehicle_search');
            const dropdown = document.getElementById('vehicle_dropdown');
            const hiddenInput = document.getElementById('vehicle_id');
            const loading = document.getElementById('vehicle_loading');

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                if (query.length < 2) {
                    dropdown.classList.remove('show');
                    return;
                }

                loading.style.display = 'block';
                
                clearTimeout(vehicleSearchTimeout);
                vehicleSearchTimeout = setTimeout(() => {
                    searchVehicles(query);
                }, 300);
            });

            searchInput.addEventListener('blur', function() {
                setTimeout(() => {
                    dropdown.classList.remove('show');
                }, 200);
            });

            searchInput.addEventListener('focus', function() {
                if (this.value.length >= 2) {
                    searchVehicles(this.value.trim());
                }
            });
        }

        function searchVehicles(query) {
            const loading = document.getElementById('vehicle_loading');
            const dropdown = document.getElementById('vehicle_dropdown');

            fetch(`create_trip.php?search_vehicles=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(vehicles => {
                    loading.style.display = 'none';
                    displayVehicleResults(vehicles);
                })
                .catch(error => {
                    console.error('Error:', error);
                    loading.style.display = 'none';
                    dropdown.classList.remove('show');
                });
        }

        function displayVehicleResults(vehicles) {
            const dropdown = document.getElementById('vehicle_dropdown');
            
            if (vehicles.length === 0) {
                dropdown.innerHTML = '<div class="search-dropdown-item">No vehicles found</div>';
                dropdown.classList.add('show');
                return;
            }

            dropdown.innerHTML = vehicles.map(vehicle => `
                <div class="search-dropdown-item" onclick="selectVehicle('${vehicle.id}', '${vehicle.vehicle_number}', '${vehicle.details}', '${vehicle.type}', ${vehicle.is_vendor})">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="font-medium text-gray-900">${vehicle.vehicle_number}</div>
                            <div class="text-sm text-gray-600">${vehicle.details}</div>
                            <div class="text-xs text-blue-600">${vehicle.type}</div>
                        </div>
                        <div class="ml-2 px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded">
                            Last 4: ${vehicle.last4}
                        </div>
                    </div>
                </div>
            `).join('');
            
            dropdown.classList.add('show');
        }

        function selectVehicle(id, vehicleNumber, details, type, isVendor) {
            document.getElementById('vehicle_search').value = `${vehicleNumber} - ${details}`;
            document.getElementById('vehicle_id').value = id;
            document.getElementById('vehicle_dropdown').classList.remove('show');
            
            selectedVehicle = {id, vehicleNumber, details, type, isVendor};
            
            // Toggle vendor rate field
            toggleVendorRate(isVendor);
        }

        // Client search functionality
        function initializeClientSearch() {
            const searchInput = document.getElementById('client_search');
            const dropdown = document.getElementById('client_dropdown');
            const hiddenInput = document.getElementById('client_id');
            const loading = document.getElementById('client_loading');

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                if (query.length < 2) {
                    dropdown.classList.remove('show');
                    return;
                }

                loading.style.display = 'block';
                
                clearTimeout(clientSearchTimeout);
                clientSearchTimeout = setTimeout(() => {
                    searchClients(query);
                }, 300);
            });

            searchInput.addEventListener('blur', function() {
                setTimeout(() => {
                    dropdown.classList.remove('show');
                }, 200);
            });

            searchInput.addEventListener('focus', function() {
                if (this.value.length >= 2) {
                    searchClients(this.value.trim());
                }
            });
        }

        function searchClients(query) {
            const loading = document.getElementById('client_loading');
            const dropdown = document.getElementById('client_dropdown');

            fetch(`create_trip.php?search_clients=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(clients => {
                    loading.style.display = 'none';
                    displayClientResults(clients);
                })
                .catch(error => {
                    console.error('Error:', error);
                    loading.style.display = 'none';
                    dropdown.classList.remove('show');
                });
        }

        function displayClientResults(clients) {
            const dropdown = document.getElementById('client_dropdown');
            
            if (clients.length === 0) {
                dropdown.innerHTML = '<div class="search-dropdown-item">No clients found</div>';
                dropdown.classList.add('show');
                return;
            }

            dropdown.innerHTML = clients.map(client => `
                <div class="search-dropdown-item" onclick="selectClient('${client.id}', '${client.name}', '${client.code}', '${client.contact}')">
                    <div class="font-medium text-gray-900">${client.name}</div>
                    <div class="text-sm text-gray-600">Code: ${client.code}</div>
                    <div class="text-xs text-gray-500">Contact: ${client.contact}</div>
                </div>
            `).join('');
            
            dropdown.classList.add('show');
        }

        function selectClient(id, name, code, contact) {
            document.getElementById('client_search').value = `${name} (${code})`;
            document.getElementById('client_id').value = id;
            document.getElementById('client_dropdown').classList.remove('show');
            
            selectedClient = {id, name, code, contact};
        }

        function addContainerField() {
            if (containerCount >= 2) {
                alert('Maximum 2 container numbers allowed');
                return;
            }

            const container = document.createElement('div');
            container.className = 'container-input';
            container.innerHTML = `
                <input type="text" name="container_numbers[]" placeholder="Enter container number" class="flex-1 px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                <button type="button" onclick="removeContainerField(this)" class="remove-container">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            document.getElementById('container_numbers_section').appendChild(container);
            containerCount++;
        }

        function removeContainerField(button) {
            button.parentElement.remove();
            containerCount--;
        }

        function toggleVendorRate(isVendor = null) {
            if (isVendor === null) {
                // Called from old select element - not used anymore
                return;
            }
            
            const vendorRateField = document.getElementById('vendor_rate_field');
            const vendorRateInput = document.getElementById('vendor_rate');

            if (isVendor) {
                vendorRateField.classList.add('show');
                vendorRateInput.required = true;
            } else {
                vendorRateField.classList.remove('show');
                vendorRateInput.required = false;
                vendorRateInput.value = '';
            }
        }

        // Initialize search functionality when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initializeVehicleSearch();
            initializeClientSearch();
            
            // Set minimum date to today
            document.getElementById('trip_date').min = new Date().toISOString().split('T')[0];
        });
    </script>
</body>
</html>