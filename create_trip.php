<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

$success = '';
$error = '';
$booking_data = null;
$booking_containers = [];

// Handle booking search via AJAX
if (isset($_GET['search_booking'])) {
    header('Content-Type: application/json');
    try {
        $booking_id = $_GET['search_booking'];
        
        // Search for booking details
        $stmt = $pdo->prepare("
            SELECT b.*, c.client_name, c.client_code, c.contact_person,
                   fl.location as from_location_name, tl.location as to_location_name
            FROM bookings b 
            LEFT JOIN clients c ON b.client_id = c.id
            LEFT JOIN location fl ON b.from_location_id = fl.id
            LEFT JOIN location tl ON b.to_location_id = tl.id
            WHERE b.booking_id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($booking) {
            // Get container details for this booking
            $containerStmt = $pdo->prepare("
                SELECT * FROM booking_containers 
                WHERE booking_id = ? 
                ORDER BY container_sequence
            ");
            $containerStmt->execute([$booking['id']]);
            $containers = $containerStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'booking' => $booking,
                'containers' => $containers
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Booking not found'
            ]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error searching booking: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle client search
if (isset($_GET['search_clients'])) {
    header('Content-Type: application/json');
    $clients = [];
    try {
        $search = $_GET['search_clients'];
        if (strlen($search) >= 2) {
            $stmt = $pdo->prepare("
                SELECT id, client_name, client_code, contact_person 
                FROM clients 
                WHERE (client_name LIKE ? OR client_code LIKE ? OR contact_person LIKE ?)
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

// Handle vehicle search
if (isset($_GET['search_vehicles'])) {
    header('Content-Type: application/json');
    $vehicles = [];
    try {
        $search = $_GET['search_vehicles'];
        if (strlen($search) >= 2) {
            // Search owned vehicles
            $stmt = $pdo->prepare("
                SELECT v.id, v.vehicle_number, v.make_model, v.driver_name, 'owned' as vehicle_type
                FROM vehicles v
                WHERE v.vehicle_number LIKE ? OR v.make_model LIKE ? OR v.driver_name LIKE ?
                   OR v.vehicle_number LIKE ?
                ORDER BY v.vehicle_number ASC
                LIMIT 5
            ");
            $searchParam = "%$search%";
            $lastFourParam = "%$search";
            $stmt->execute([$searchParam, $searchParam, $searchParam, $lastFourParam]);
            $ownedVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Search vendor vehicles
            $vendorStmt = $pdo->prepare("
                SELECT vv.id, vv.vehicle_number, vv.make_model, vv.driver_name, 'vendor' as vehicle_type,
                       v.company_name as vendor_name
                FROM vendor_vehicles vv
                LEFT JOIN vendors v ON vv.vendor_id = v.id
                WHERE vv.vehicle_number LIKE ? OR vv.make_model LIKE ? OR vv.driver_name LIKE ?
                   OR vv.vehicle_number LIKE ?
                ORDER BY vv.vehicle_number ASC
                LIMIT 5
            ");
            $vendorStmt->execute([$searchParam, $searchParam, $searchParam, $lastFourParam]);
            $vendorVehicles = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ownedVehicles as $vehicle) {
                $vehicles[] = [
                    'id' => $vehicle['id'] . '|owned',
                    'vehicle_number' => $vehicle['vehicle_number'],
                    'make_model' => $vehicle['make_model'],
                    'driver_name' => $vehicle['driver_name'],
                    'type' => 'Owned Vehicle',
                    'vendor_name' => null
                ];
            }

            foreach ($vendorVehicles as $vehicle) {
                $vehicles[] = [
                    'id' => $vehicle['id'] . '|vendor',
                    'vehicle_number' => $vehicle['vehicle_number'],
                    'make_model' => $vehicle['make_model'],
                    'driver_name' => $vehicle['driver_name'],
                    'type' => 'Vendor Vehicle',
                    'vendor_name' => $vehicle['vendor_name']
                ];
            }
        }
        echo json_encode($vehicles);
        exit;
    } catch (Exception $e) {
        echo json_encode([]);
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Get form data
        $booking_id_input = $_POST['booking_id'] ?? '';
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
        if ($check_ref->rowCount() > 0) {
            $reference_number = 'LR-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        }

        // Prepare arrays for container numbers
        $container_1 = $container_numbers[0] ?? null;
        $container_2 = $container_numbers[1] ?? null;

        // Insert trip
        $stmt = $pdo->prepare("
            INSERT INTO trips (reference_number, trip_date, vehicle_id, vehicle_type, driver_name, 
                             vehicle_number, movement_type, client_id, container_type, container_number_1, 
                             container_number_2, vessel_name, from_location, to_location, vendor_rate, 
                             created_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $reference_number,
            $trip_date,
            $vehicle_id,
            $vehicle_type,
            $vehicle['driver_name'],
            $vehicle['vehicle_number'],
            $movement_type,
            $client_id,
            $container_type,
            $container_1,
            $container_2,
            $vessel_name,
            $from_location,
            $to_location,
            $vendor_rate,
            $_SESSION['user_id']
        ]);

        $trip_id = $pdo->lastInsertId();

        // If this trip was created from a booking, update the booking status
        if (!empty($booking_id_input)) {
            $update_booking = $pdo->prepare("
                UPDATE bookings 
                SET status = 'completed', updated_at = NOW() 
                WHERE booking_id = ?
            ");
            $update_booking->execute([$booking_id_input]);
        }

        $pdo->commit();
        $success = "Trip created successfully! Reference Number: $reference_number";

        // Clear form data
        $_POST = [];

    } catch (Exception $e) {
        $pdo->rollback();
        $error = $e->getMessage();
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
        .search-input-container {
            position: relative;
        }
        
        .search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }
        
        .search-dropdown.show {
            display: block;
        }
        
        .search-dropdown-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .search-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .search-dropdown-item:hover {
            background-color: #f9fafb;
        }
        
        .loading-spinner {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border: 2px solid #e5e7eb;
            border-radius: 50%;
            border-top-color: #3b82f6;
            animation: spin 1s ease-in-out infinite;
            display: none;
        }
        
        @keyframes spin {
            to { transform: translateY(-50%) rotate(360deg); }
        }

        .booking-info-card {
            @apply bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded-lg;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
        <!-- Navigation -->
        <nav class="bg-white shadow-sm border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <a href="dashboard.php" class="text-xl font-bold text-gray-800">Fleet Management</a>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-gray-600">Welcome, <?= htmlspecialchars($_SESSION['username']) ?></span>
                        <a href="logout.php" class="text-gray-600 hover:text-gray-800">Logout</a>
                    </div>
                </div>
            </div>
        </nav>

        <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <!-- Header -->
            <header class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Create New Trip</h1>
                    <p class="text-gray-600 mt-1">Create a new trip entry or complete a booking request</p>
                </div>
                <div class="flex items-center gap-4">
                    <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
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

            <!-- Booking Search Section -->
            <div class="bg-white rounded-xl shadow-sm card-hover-effect mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-search text-blue-600"></i> Search Booking (Optional)
                    </h2>
                    <p class="text-sm text-gray-600 mt-1">Enter a booking ID to pre-fill trip details, or leave empty to create a new trip</p>
                </div>
                <div class="p-6">
                    <div class="flex gap-4 items-end">
                        <div class="flex-1">
                            <label for="booking_search" class="block text-sm font-medium text-gray-700">Booking ID</label>
                            <input type="text" id="booking_search" placeholder="Enter booking ID to search..." 
                                   class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <button type="button" id="search_booking_btn" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition-colors">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <button type="button" id="clear_booking_btn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors" style="display: none;">
                            <i class="fas fa-times mr-2"></i>Clear
                        </button>
                    </div>
                </div>
            </div>

            <!-- Booking Info Display -->
            <div id="booking_info" class="booking-info-card" style="display: none;">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-medium text-blue-900 mb-2">
                            <i class="fas fa-info-circle mr-2"></i>Booking Found
                        </h3>
                        <div id="booking_details"></div>
                    </div>
                </div>
            </div>

            <!-- Trip Creation Form -->
            <div class="bg-white rounded-xl shadow-sm card-hover-effect">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-route text-blue-600"></i> Trip Details
                    </h2>
                </div>

                <form method="POST" class="p-6">
                    <!-- Hidden booking ID field -->
                    <input type="hidden" id="booking_id" name="booking_id">
                    
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

                        <!-- Client -->
                        <div class="form-field">
                            <label for="client_search" class="block text-sm font-medium text-gray-700">Client *</label>
                            <div class="search-input-container">
                                <input type="text" id="client_search" name="client_search" required 
                                       placeholder="Type to search clients..." 
                                       autocomplete="off"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <input type="hidden" id="client_id" name="client_id" value="<?= htmlspecialchars($_POST['client_id'] ?? '') ?>">
                                <div class="loading-spinner" id="client_loading"></div>
                                <div class="search-dropdown" id="client_dropdown"></div>
                            </div>
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
                                <button type="button" class="add-container bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-md transition-colors">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Add up to 2 container numbers. For 40ft containers, only one number is required.
                        </p>
                    </div>

                    <!-- Submit Button -->
                    <div class="mt-8 flex justify-end gap-4">
                        <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg transition-colors">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2 rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>Create Trip
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initializeBookingSearch();
            initializeVehicleSearch();
            initializeClientSearch();
            initializeContainerInputs();
        });

        // Booking search functionality
        function initializeBookingSearch() {
            const searchBtn = document.getElementById('search_booking_btn');
            const clearBtn = document.getElementById('clear_booking_btn');
            const searchInput = document.getElementById('booking_search');
            const bookingInfo = document.getElementById('booking_info');
            const bookingDetails = document.getElementById('booking_details');

            searchBtn.addEventListener('click', function() {
                const bookingId = searchInput.value.trim();
                if (!bookingId) {
                    alert('Please enter a booking ID');
                    return;
                }

                // Show loading state
                searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Searching...';
                searchBtn.disabled = true;

                fetch(`create_trip.php?search_booking=${encodeURIComponent(bookingId)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            populateFormFromBooking(data.booking, data.containers);
                            displayBookingInfo(data.booking, data.containers);
                            clearBtn.style.display = 'inline-block';
                        } else {
                            alert(data.message || 'Booking not found');
                            bookingInfo.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error searching for booking');
                        bookingInfo.style.display = 'none';
                    })
                    .finally(() => {
                        searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i>Search';
                        searchBtn.disabled = false;
                    });
            });

            clearBtn.addEventListener('click', function() {
                clearBookingData();
            });

            // Allow search on Enter key
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchBtn.click();
                }
            });
        }

        function populateFormFromBooking(booking, containers) {
            // Set booking ID
            document.getElementById('booking_id').value = booking.booking_id;

            // Set client info
            if (booking.client_name) {
                document.getElementById('client_search').value = `${booking.client_name} (${booking.client_code})`;
                document.getElementById('client_id').value = booking.client_id;
            }

            // Set locations
            if (booking.from_location_name) {
                document.getElementById('from_location').value = booking.from_location_name;
            }
            if (booking.to_location_name) {
                document.getElementById('to_location').value = booking.to_location_name;
            }

            // Populate container numbers from booking_containers
            const containerSection = document.getElementById('container_numbers_section');
            containerSection.innerHTML = ''; // Clear existing inputs

            containers.forEach((container, index) => {
                // Add container input for container_number_1
                if (container.container_number_1) {
                    addContainerInput(container.container_number_1, index === 0);
                }
                // Add container input for container_number_2 if exists
                if (container.container_number_2) {
                    addContainerInput(container.container_number_2, false);
                }
            });

            // If no containers were added, add at least one empty input
            if (containerSection.children.length === 0) {
                addContainerInput('', true);
            }
        }

        function displayBookingInfo(booking, containers) {
            const bookingInfo = document.getElementById('booking_info');
            const bookingDetails = document.getElementById('booking_details');

            let containerInfo = '';
            containers.forEach(container => {
                containerInfo += `<div class="text-sm">
                    <strong>Container ${container.container_sequence}:</strong> 
                    ${container.container_type}`;
                if (container.container_number_1) {
                    containerInfo += ` - ${container.container_number_1}`;
                }
                if (container.container_number_2) {
                    containerInfo += `, ${container.container_number_2}`;
                }
                containerInfo += '</div>';
            });

            bookingDetails.innerHTML = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><strong>Booking ID:</strong> ${booking.booking_id}</div>
                    <div><strong>Status:</strong> <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">${booking.status}</span></div>
                    <div><strong>Client:</strong> ${booking.client_name} (${booking.client_code})</div>
                    <div><strong>Containers:</strong> ${booking.no_of_containers}</div>
                    <div><strong>From:</strong> ${booking.from_location_name || 'Not specified'}</div>
                    <div><strong>To:</strong> ${booking.to_location_name || 'Not specified'}</div>
                    <div class="md:col-span-2">
                        <strong>Container Details:</strong>
                        ${containerInfo}
                    </div>
                </div>
            `;

            bookingInfo.style.display = 'block';
        }

        function clearBookingData() {
            // Clear booking search
            document.getElementById('booking_search').value = '';
            document.getElementById('booking_id').value = '';
            document.getElementById('booking_info').style.display = 'none';
            document.getElementById('clear_booking_btn').style.display = 'none';

            // Reset form fields (but keep manually entered data)
            // Only clear if they were populated from booking
            const confirmClear = confirm('This will clear all form data populated from the booking. Continue?');
            if (confirmClear) {
                document.getElementById('client_search').value = '';
                document.getElementById('client_id').value = '';
                document.getElementById('from_location_search').value = '';
                document.getElementById('from_location').value = '';
                document.getElementById('to_location_search').value = '';
                document.getElementById('to_location').value = '';
                
                // Reset container inputs
                const containerSection = document.getElementById('container_numbers_section');
                containerSection.innerHTML = `
                    <div class="container-input">
                        <input type="text" name="container_numbers[]" placeholder="Enter container number" class="flex-1 px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <button type="button" class="add-container bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-md transition-colors">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                `;
                initializeContainerInputs();
            }
        }

        // Vehicle search functionality
        function initializeVehicleSearch() {
            const searchInput = document.getElementById('vehicle_search');
            const dropdown = document.getElementById('vehicle_dropdown');
            const loading = document.getElementById('vehicle_loading');
            let searchTimeout;

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                clearTimeout(searchTimeout);
                
                if (query.length >= 2) {
                    loading.style.display = 'block';
                    dropdown.classList.remove('show');
                    
                    searchTimeout = setTimeout(() => {
                        searchVehicles(query);
                    }, 300);
                } else {
                    dropdown.classList.remove('show');
                    loading.style.display = 'none';
                }
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
                <div class="search-dropdown-item" onclick="selectVehicle('${vehicle.id}', '${vehicle.vehicle_number}', '${vehicle.make_model}', '${vehicle.driver_name}', '${vehicle.type}', '${vehicle.vendor_name || ''}')">
                    <div class="font-medium text-gray-900">${vehicle.vehicle_number}</div>
                    <div class="text-sm text-gray-600">${vehicle.make_model} | Driver: ${vehicle.driver_name}</div>
                    <div class="text-xs text-gray-500">${vehicle.type}${vehicle.vendor_name ? ` - ${vehicle.vendor_name}` : ''}</div>
                </div>
            `).join('');
            
            dropdown.classList.add('show');
        }

        function selectVehicle(id, vehicleNumber, makeModel, driverName, type, vendorName) {
            document.getElementById('vehicle_search').value = `${vehicleNumber} - ${makeModel}`;
            document.getElementById('vehicle_id').value = id;
            document.getElementById('vehicle_dropdown').classList.remove('show');

            // Show/hide vendor rate field based on vehicle type
            const vendorRateField = document.getElementById('vendor_rate_field');
            if (id.includes('|vendor')) {
                vendorRateField.classList.add('show');
                document.getElementById('vendor_rate').required = true;
            } else {
                vendorRateField.classList.remove('show');
                document.getElementById('vendor_rate').required = false;
            }
        }

        // Client search functionality  
        function initializeClientSearch() {
            const searchInput = document.getElementById('client_search');
            const dropdown = document.getElementById('client_dropdown');
            const loading = document.getElementById('client_loading');
            let searchTimeout;

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                clearTimeout(searchTimeout);
                
                if (query.length >= 2) {
                    loading.style.display = 'block';
                    dropdown.classList.remove('show');
                    
                    searchTimeout = setTimeout(() => {
                        searchClients(query);
                    }, 300);
                } else {
                    dropdown.classList.remove('show');
                    loading.style.display = 'none';
                }
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
        }

        // Container inputs functionality
        function initializeContainerInputs() {
            updateContainerButtons();
        }

        function addContainerInput(value = '', isFirst = false) {
            const section = document.getElementById('container_numbers_section');
            const currentCount = section.children.length;
            
            if (currentCount >= 2) {
                alert('Maximum 2 container numbers allowed');
                return;
            }

            const containerDiv = document.createElement('div');
            containerDiv.className = 'container-input';
            
            containerDiv.innerHTML = `
                <input type="text" name="container_numbers[]" value="${value}" placeholder="Enter container number" class="flex-1 px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                ${currentCount === 0 && isFirst ? 
                    `<button type="button" class="add-container bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-md transition-colors">
                        <i class="fas fa-plus"></i>
                    </button>` :
                    `<button type="button" class="remove-container">
                        <i class="fas fa-minus"></i>
                    </button>`
                }
            `;

            if (isFirst && currentCount === 0) {
                section.appendChild(containerDiv);
            } else {
                section.appendChild(containerDiv);
            }
            
            updateContainerButtons();
        }

        function updateContainerButtons() {
            const section = document.getElementById('container_numbers_section');
            
            // Remove existing event listeners and re-add
            section.removeEventListener('click', handleContainerButtons);
            section.addEventListener('click', handleContainerButtons);
        }

        function handleContainerButtons(e) {
            if (e.target.closest('.add-container')) {
                addContainerInput();
            } else if (e.target.closest('.remove-container')) {
                const containerDiv = e.target.closest('.container-input');
                containerDiv.remove();
                updateContainerButtons();
            }
        }
    </script>
</body>
</html>