<?php
// Set timezone to Asia/Kolkata
date_default_timezone_set('Asia/Kolkata');

session_start();
require '../auth_check.php';
require '../db_connection.php';

// RBAC: allow manager1, admin, superadmin to view
if (!in_array($_SESSION['role'] ?? '', ['manager1', 'admin', 'superadmin'])) {
    header('Location: ../dashboard.php');
    exit();
}

$bookingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$bookingCode = isset($_GET['booking_id']) ? trim($_GET['booking_id']) : '';
if ($bookingId <= 0 && $bookingCode === '') {
    header('Location: manage.php');
    exit();
}

// Fetch booking with joins
$sql = "SELECT b.*, c.client_name, c.client_code, 
        CASE 
            WHEN b.from_location_type = 'yard' THEN yfl.yard_name
            ELSE fl.location
        END as from_location,
        CASE 
            WHEN b.to_location_type = 'yard' THEN ytl.yard_name
            ELSE tl.location
        END as to_location,
        u.full_name AS created_by_name
        FROM bookings b
        LEFT JOIN clients c ON b.client_id = c.id
        LEFT JOIN location fl ON b.from_location_id = fl.id AND b.from_location_type = 'location'
        LEFT JOIN location tl ON b.to_location_id = tl.id AND b.to_location_type = 'location'
        LEFT JOIN yard_locations yfl ON b.from_location_id = yfl.id AND b.from_location_type = 'yard'
        LEFT JOIN yard_locations ytl ON b.to_location_id = ytl.id AND b.to_location_type = 'yard'
        LEFT JOIN users u ON b.created_by = u.id
        WHERE ";
if ($bookingId > 0) {
    $sql .= "b.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bookingId]);
} else {
    $sql .= "b.booking_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bookingCode]);
}
$booking = $stmt->fetch();
if (!$booking) {
    header('Location: manage.php');
    exit();
}

// Fetch containers if table exists
$containers = [];
$legacy_containers = [];
try {
    $existsStmt = $pdo->query("SHOW TABLES LIKE 'booking_containers'");
    if ($existsStmt->fetch()) {
        // Detect if per-container location columns exist
        $bcCols = [];
        try {
            $colsStmt = $pdo->query("SHOW COLUMNS FROM booking_containers");
            $bcCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Exception $e) { $bcCols = []; }
        $hasPerContainerLoc = in_array('from_location_id', $bcCols, true) && in_array('to_location_id', $bcCols, true);

        if ($hasPerContainerLoc) {
            $c = $pdo->prepare("SELECT bc.*, 
                                 CASE 
                                     WHEN bc.from_location_type = 'yard' THEN yfl.yard_name
                                     ELSE fl.location
                                 END as from_location_name,
                                 CASE 
                                     WHEN bc.to_location_type = 'yard' THEN ytl.yard_name
                                     ELSE tl.location
                                 END as to_location_name
                                 FROM booking_containers bc
                                 LEFT JOIN location fl ON bc.from_location_id = fl.id AND bc.from_location_type = 'location'
                                 LEFT JOIN location tl ON bc.to_location_id = tl.id AND bc.to_location_type = 'location'
                                 LEFT JOIN yard_locations yfl ON bc.from_location_id = yfl.id AND bc.from_location_type = 'yard'
                                 LEFT JOIN yard_locations ytl ON bc.to_location_id = ytl.id AND bc.to_location_type = 'yard'
                                 WHERE bc.booking_id = ?
                                 ORDER BY bc.container_sequence");
        } else {
            $c = $pdo->prepare("SELECT * FROM booking_containers WHERE booking_id = ? ORDER BY container_sequence");
        }
        $c->execute([$booking['id']]);
        $containers = $c->fetchAll();
    }
} catch (Exception $e) {}

// Fetch users with phone numbers for WhatsApp dropdown
$users = [];
try {
    $usersStmt = $pdo->prepare("SELECT id, full_name, phone_number FROM users WHERE status = 'active' AND phone_number IS NOT NULL AND phone_number != '' ORDER BY full_name");
    $usersStmt->execute();
    $users = $usersStmt->fetchAll();
} catch (Exception $e) {
    // If error, users array remains empty
}

// Fallback for legacy schemas: if no rows in booking_containers, try old columns on bookings
if (empty($containers)) {
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM bookings");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        if (in_array('container_type', $cols, true) || in_array('container_number', $cols, true)) {
            if (!empty($booking['container_type']) || !empty($booking['container_number'])) {
                $legacy_containers[] = [
                    'container_sequence' => 1,
                    'container_type' => $booking['container_type'] ?? '',
                    'container_number_1' => $booking['container_number'] ?? '',
                    'container_number_2' => null,
                ];
            }
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Booking - Absuma Logistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --absuma-red: #0d9488; /* teal-600 */
            --absuma-red-light: #f0fdfa; /* teal-50 */
            --absuma-red-dark: #0f766e; /* teal-700 */
        }
        
        .text-absuma-red { color: var(--absuma-red); }
        .bg-absuma-red { background-color: var(--absuma-red); }
        .border-absuma-red { border-color: var(--absuma-red); }
        .hover\:bg-absuma-red:hover { background-color: var(--absuma-red); }
        .hover\:text-absuma-red:hover { color: var(--absuma-red); }
        
        .gradient-bg {
            background: linear-gradient(135deg, #f0fdfa 0%, #e6fffa 100%);
            min-height: 100vh;
        }
        
        .shadow-soft {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .card-hover-effect {
            transition: all 0.3s ease;
        }
        
        .card-hover-effect:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        /* Enhanced input styling to match create booking page */
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
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1), 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transform: scale(1.02);
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
    </style>
</head>
<body class="gradient-bg overflow-x-hidden">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <?php include '../sidebar_navigation.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">View Booking</h1>
                            <p class="text-sm text-gray-600 mt-1">
                                Booking ID: <?= htmlspecialchars($booking['booking_id']) ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a href="edit.php?booking_id=<?= urlencode($booking['booking_id']) ?>" class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg transition-colors font-medium">
                            <i class="fas fa-edit mr-2"></i>Edit
                        </a>
                        <a href="manage.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg transition-colors font-medium">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                    </div>
                        </div>
            </header>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-7xl mx-auto">
                    <div class="space-y-6">
                        <!-- Booking Overview -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <div class="flex items-center justify-between mb-6">
                                <h2 class="text-xl font-bold text-gray-900 flex items-center">
                                    <i class="fas fa-shipping-fast text-absuma-red mr-3"></i>
                                    Booking Overview
                                </h2>
                                <div class="flex items-center space-x-4">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-sm text-gray-500">Status:</span>
                                        <?php
                                        $status = $booking['status'];
                                        $statusColors = [
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'confirmed' => 'bg-blue-100 text-blue-800',
                                            'in_progress' => 'bg-purple-100 text-purple-800',
                                            'completed' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        $statusColor = $statusColors[$status] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full <?= $statusColor ?>">
                                            <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                        </span>
                        </div>
                        
                                    <div class="flex items-center space-x-2">
                                        <button type="button" 
                                                onclick="window.open('generate_booking_pdf.php?booking_id=<?= urlencode($booking['booking_id']) ?>', '_blank'); return false;"
                                                class="inline-flex items-center px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors">
                                            <i class="fas fa-file-pdf mr-1.5"></i>
                                            PDF
                                        </button>
                                        
                                        <button type="button" onclick="openWhatsAppModal();" 
                                                class="inline-flex items-center px-3 py-1.5 bg-green-500 hover:bg-green-600 text-white text-sm font-medium rounded-lg transition-colors">
                                            <i class="fab fa-whatsapp mr-1.5"></i>
                                            WhatsApp
                                        </button>
                                    </div>
                        </div>
                    </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <!-- Booking ID -->
                                <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg border border-blue-200">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-hashtag text-blue-600 mr-2"></i>
                                        <div class="text-sm font-medium text-blue-800">Booking ID</div>
                                    </div>
                                    <div class="text-xl font-bold text-blue-900"><?= htmlspecialchars($booking['booking_id']) ?></div>
                                </div>
                                
                                <!-- Client -->
                                <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg border border-green-200">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-building text-green-600 mr-2"></i>
                                        <div class="text-sm font-medium text-green-800">Client</div>
                                    </div>
                                    <div class="text-lg font-bold text-green-900"><?= htmlspecialchars($booking['client_name']) ?></div>
                                    <div class="text-sm text-green-700"><?= htmlspecialchars($booking['client_code']) ?></div>
                                </div>
                                
                                <!-- Movement Type -->
                                <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg border border-blue-200">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-truck text-blue-600 mr-2"></i>
                                        <div class="text-sm font-medium text-blue-800">Movement Type</div>
                                    </div>
                                    <div class="text-lg font-bold text-blue-900">
                                        <?php 
                                        $movement_type = $booking['movement_type'] ?? '';
                                        $display_type = '';
                                        switch($movement_type) {
                                            case 'import': $display_type = 'Import'; break;
                                            case 'export': $display_type = 'Export'; break;
                                            case 'port_yard_movement': $display_type = 'Port/Yard Movement'; break;
                                            case 'domestic_movement': $display_type = 'Domestic Movement'; break;
                                            default: $display_type = 'Not specified';
                                        }
                                        echo htmlspecialchars($display_type);
                                        ?>
                                    </div>
                                </div>
                                
                                <!-- Containers -->
                                <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-4 rounded-lg border border-purple-200">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-boxes text-purple-600 mr-2"></i>
                                        <div class="text-sm font-medium text-purple-800">Total Containers</div>
                                    </div>
                                    <div class="text-2xl font-bold text-purple-900"><?= (int)$booking['no_of_containers'] ?></div>
                                </div>
                                
                                <?php
                                // Check if all containers have the same route
                                $showMainRoute = true;
                                $allContainers = !empty($containers) ? $containers : $legacy_containers;
                                if (!empty($allContainers)) {
                                    $firstFrom = $allContainers[0]['from_location_name'] ?? $booking['from_location'];
                                    $firstTo = $allContainers[0]['to_location_name'] ?? $booking['to_location'];
                                    
                                    foreach ($allContainers as $c) {
                                        $containerFrom = $c['from_location_name'] ?? $booking['from_location'];
                                        $containerTo = $c['to_location_name'] ?? $booking['to_location'];
                                        
                                        if ($containerFrom !== $firstFrom || $containerTo !== $firstTo) {
                                            $showMainRoute = false;
                                            break;
                                        }
                                    }
                                }
                                
                                if ($showMainRoute): ?>
                                <!-- Route -->
                                <div class="bg-gradient-to-br from-teal-50 to-teal-100 p-4 rounded-lg border border-teal-200 col-span-1 md:col-span-2 lg:col-span-4">
                                    <div class="flex items-center mb-3">
                                        <i class="fas fa-route text-teal-600 mr-2"></i>
                                        <div class="text-sm font-medium text-teal-800">Route</div>
                                    </div>
                                    <div class="flex items-center justify-center space-x-4">
                                        <div class="text-center">
                                            <div class="text-sm text-teal-700 mb-1">From</div>
                                            <div class="text-lg font-bold text-teal-900"><?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : 'N/A' ?></div>
                                        </div>
                                        <div class="flex items-center">
                                            <i class="fas fa-arrow-right text-teal-500 text-xl"></i>
                                        </div>
                                        <div class="text-center">
                                            <div class="text-sm text-teal-700 mb-1">To</div>
                                            <div class="text-lg font-bold text-teal-900"><?= $booking['to_location'] ? htmlspecialchars($booking['to_location']) : 'N/A' ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Booking Details -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-info-circle text-absuma-red mr-3"></i>
                                Booking Details
                            </h2>
                            
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-white rounded-lg border border-gray-100 p-4 shadow-sm">
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-plus text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Created Date</span>
                                        </div>
                                        <span class="text-sm font-semibold text-gray-900">
                                            <?= date('M j, Y', strtotime($booking['created_at'])) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-clock text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Created Time</span>
                                        </div>
                                        <span class="text-sm font-semibold text-gray-900">
                                            <?= date('g:i A', strtotime($booking['created_at'])) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-user text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Created By</span>
                                        </div>
                                        <span class="text-sm font-semibold text-gray-900">
                                            <?= htmlspecialchars($booking['created_by_name'] ?? 'Unknown') ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg border border-gray-100 p-4 shadow-sm">
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-edit text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Last Updated</span>
                                        </div>
                                        <span class="text-sm font-semibold text-gray-900">
                                            <?= isset($booking['updated_at']) && $booking['updated_at'] ? date('M j, Y g:i A', strtotime($booking['updated_at'])) : 'Never' ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-id-badge text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Booking ID</span>
                                        </div>
                                        <span class="text-sm font-semibold text-gray-900 font-mono">
                                            <?= htmlspecialchars($booking['booking_id']) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-tag text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Client Code</span>
                                        </div>
                                        <span class="text-sm font-semibold text-gray-900 font-mono">
                                            <?= htmlspecialchars($booking['client_code']) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="bg-white rounded-lg border border-gray-100 p-4 shadow-sm">
                                    <?php if (!empty($booking['booking_receipt_pdf'])): ?>
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-file-pdf text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Booking Receipt</span>
                                        </div>
                                        <a href="../Uploads/booking_docs/<?= htmlspecialchars($booking['booking_receipt_pdf']) ?>" 
                                           target="_blank" 
                                           class="inline-flex items-center px-3 py-1 bg-red-100 text-red-800 text-xs font-medium rounded-full hover:bg-red-200 transition-colors">
                                            <i class="fas fa-download mr-1"></i>
                                            Download PDF
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-truck-loading text-teal-600 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-600">Status</span>
                                        </div>
                                        <?php
                                        $status = $booking['status'];
                                        $statusColors = [
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'confirmed' => 'bg-blue-100 text-blue-800',
                                            'in_progress' => 'bg-purple-100 text-purple-800',
                                            'completed' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        $statusColor = $statusColors[$status] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full <?= $statusColor ?>">
                                            <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                        </span>
                                    </div>
                                </div>
                    </div>
                        </div>
                        
                        <!-- Container Details -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <div class="flex items-center justify-between mb-6">
                                <h2 class="text-xl font-bold text-gray-900 flex items-center">
                                    <i class="fas fa-boxes text-absuma-red mr-3"></i>
                                    Container Details
                                </h2>
                                <div class="flex items-center space-x-2">
                                    <span class="text-sm text-gray-500">Total:</span>
                                    <span class="text-lg font-bold text-absuma-red"><?= count(!empty($containers) ? $containers : $legacy_containers) ?></span>
                    </div>
                </div>
                            
                    <?php if (empty($containers) && empty($legacy_containers)): ?>
                                <div class="text-center py-12">
                                    <i class="fas fa-box-open text-gray-300 text-4xl mb-4"></i>
                                    <div class="text-lg text-gray-500 mb-2">No Container Details</div>
                                    <div class="text-sm text-gray-400">Container information is not available for this booking.</div>
                                </div>
                    <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead>
                                            <tr class="bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
                                                <th class="text-left px-6 py-4 text-sm font-semibold text-gray-700 uppercase tracking-wider">
                                                    <i class="fas fa-hashtag mr-2"></i>#
                                                </th>
                                                <th class="text-left px-6 py-4 text-sm font-semibold text-gray-700 uppercase tracking-wider">
                                                    <i class="fas fa-ruler mr-2"></i>Size
                                                </th>
                                                <th class="text-left px-6 py-4 text-sm font-semibold text-gray-700 uppercase tracking-wider">
                                                    <i class="fas fa-barcode mr-2"></i>Container Number(s)
                                                </th>
                                                <th class="text-left px-6 py-4 text-sm font-semibold text-gray-700 uppercase tracking-wider">
                                                    <i class="fas fa-map-marker-alt mr-2"></i>From Location
                                                </th>
                                                <th class="text-left px-6 py-4 text-sm font-semibold text-gray-700 uppercase tracking-wider">
                                                    <i class="fas fa-map-marker-alt mr-2"></i>To Location
                                                </th>
                                    </tr>
                                </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach (!empty($containers) ? $containers : $legacy_containers as $index => $c): ?>
                                                <tr class="hover:bg-gray-50 transition-colors">
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <div class="w-8 h-8 bg-absuma-red text-white rounded-full flex items-center justify-center text-sm font-bold">
                                                                <?= (int)$c['container_sequence'] ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <?php
                                                        $size = $c['container_type'] ?? '';
                                                        $sizeColor = strpos($size, '20ft') !== false ? 'bg-blue-100 text-blue-800' : 
                                                                   (strpos($size, '40ft') !== false ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800');
                                                        ?>
                                                        <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full <?= $sizeColor ?>">
                                                            <?= htmlspecialchars($size) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($c['container_number_1'] ?? '') ?>
                                                        </div>
                                                        <?php if (!empty($c['container_number_2'])): ?>
                                                            <div class="text-sm text-gray-500">
                                                                <?= htmlspecialchars($c['container_number_2']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                            </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <i class="fas fa-map-marker-alt text-teal-500 mr-2"></i>
                                                            <span class="text-sm font-medium text-gray-900">
                                                <?php if (isset($c['from_location_name'])): ?>
                                                    <?= $c['from_location_name'] ? htmlspecialchars($c['from_location_name']) : '—' ?>
                                                <?php else: ?>
                                                    <?= $booking['from_location'] ? htmlspecialchars($booking['from_location']) : '—' ?>
                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                            </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <i class="fas fa-map-marker-alt text-teal-600 mr-2"></i>
                                                            <span class="text-sm font-medium text-gray-900">
                                                <?php if (isset($c['to_location_name'])): ?>
                                                    <?= $c['to_location_name'] ? htmlspecialchars($c['to_location_name']) : '—' ?>
                                                <?php else: ?>
                                                    <?= $booking['to_location'] ? htmlspecialchars($booking['to_location']) : '—' ?>
                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                        </div>
                        
                        <!-- Booking Summary -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-chart-pie text-absuma-red mr-3"></i>
                                Booking Summary
                            </h2>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <!-- Total Containers -->
                                <div class="text-center p-4 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg border border-blue-200 shadow-sm">
                                    <div class="w-10 h-10 bg-blue-500 text-white rounded-full flex items-center justify-center mx-auto mb-2">
                                        <i class="fas fa-boxes text-lg"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-blue-900"><?= (int)$booking['no_of_containers'] ?></div>
                                    <div class="text-sm text-blue-700">Total Containers</div>
                                </div>
                                
                                <!-- 20ft Containers -->
                                <div class="text-center p-4 bg-gradient-to-br from-green-50 to-green-100 rounded-lg border border-green-200 shadow-sm">
                                    <div class="w-10 h-10 bg-green-500 text-white rounded-full flex items-center justify-center mx-auto mb-2">
                                        <i class="fas fa-cube text-lg"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-green-900">
                                        <?php
                                        $count20ft = 0;
                                        foreach (!empty($containers) ? $containers : $legacy_containers as $c) {
                                            if (strpos($c['container_type'] ?? '', '20ft') !== false) {
                                                $count20ft++;
                                            }
                                        }
                                        echo $count20ft;
                                        ?>
                                    </div>
                                    <div class="text-sm text-green-700">20ft Containers</div>
                                </div>
                                
                                <!-- 40ft Containers -->
                                <div class="text-center p-4 bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg border border-purple-200 shadow-sm">
                                    <div class="w-10 h-10 bg-purple-500 text-white rounded-full flex items-center justify-center mx-auto mb-2">
                                        <i class="fas fa-cubes text-lg"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-purple-900">
                                        <?php
                                        $count40ft = 0;
                                        foreach (!empty($containers) ? $containers : $legacy_containers as $c) {
                                            if (strpos($c['container_type'] ?? '', '40ft') !== false) {
                                                $count40ft++;
                                            }
                                        }
                                        echo $count40ft;
                                        ?>
                                    </div>
                                    <div class="text-sm text-purple-700">40ft Containers</div>
                                </div>
                                
                                <!-- Booking Age -->
                                <div class="text-center p-4 bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg border border-orange-200 shadow-sm">
                                    <div class="w-10 h-10 bg-orange-500 text-white rounded-full flex items-center justify-center mx-auto mb-2">
                                        <i class="fas fa-calendar-day text-lg"></i>
                                    </div>
                                    <div class="text-2xl font-bold text-orange-900">
                                        <?= max(1, floor((time() - strtotime($booking['created_at'])) / 86400)) ?>
                                    </div>
                                    <div class="text-sm text-orange-700">Days Old</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-bolt text-absuma-red mr-3"></i>
                                Quick Actions
                            </h2>
                            
                            <div class="flex flex-wrap gap-3">
                                <a href="edit.php?booking_id=<?= urlencode($booking['booking_id']) ?>" 
                                   class="inline-flex items-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                                    <i class="fas fa-edit mr-2"></i>
                                    Edit Booking
                                </a>
                                
                                <a href="manage.php" 
                                   class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-lg transition-colors">
                                    <i class="fas fa-arrow-left mr-2"></i>
                                    Back to Manage
                                </a>
                                
                                <button type="button" onclick="openWhatsAppModal();" 
                                        class="inline-flex items-center px-4 py-2 bg-green-500 hover:bg-green-600 text-white text-sm font-medium rounded-lg transition-colors">
                                    <i class="fab fa-whatsapp mr-2"></i>
                                    Send via WhatsApp
                                </button>
                            </div>
                        </div>
                    </div>
                </main>
        </div>
    </div>

    <!-- WhatsApp Modal -->
    <div id="whatsappModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50" style="display: none;">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900 flex items-center">
                        <i class="fab fa-whatsapp text-teal-600 mr-2"></i>
                        Send via WhatsApp
                    </h3>
                    <button onclick="closeWhatsAppModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select User</label>
                        <select id="userSelect" class="input-enhanced">
                            <option value="">Select a user...</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= htmlspecialchars($user['phone_number']) ?>" data-name="<?= htmlspecialchars($user['full_name']) ?>">
                                    <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['phone_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Select from users with phone numbers in the system</p>
                    </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Message (Optional)</label>
                    <textarea id="whatsappMessage" rows="8" placeholder="Add a custom message..." 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"><?php
// Build simplified WhatsApp message
$message = "*BOOKING RECEIPT*\n\n";
$message .= "*Booking ID:* " . htmlspecialchars($booking['booking_id']) . "\n";
$message .= "*Client:* " . htmlspecialchars($booking['client_name']) . "\n";
$message .= "*Client ID:* " . htmlspecialchars($booking['client_code'] ?? 'N/A') . "\n";
$message .= "*Date:* " . date('d M Y', strtotime($booking['created_at'])) . "\n";

// Add movement type
$movement_type = $booking['movement_type'] ?? '';
$display_type = '';
switch($movement_type) {
    case 'import': $display_type = 'Import'; break;
    case 'export': $display_type = 'Export'; break;
    case 'port_yard_movement': $display_type = 'Port/Yard Movement'; break;
    case 'domestic_movement': $display_type = 'Domestic Movement'; break;
    default: $display_type = 'Not specified';
}
$message .= "*Movement Type:* " . $display_type . "\n";

$message .= "*Route:* " . htmlspecialchars($booking['from_location'] ?? 'N/A') . " → " . htmlspecialchars($booking['to_location'] ?? 'N/A') . "\n";

if (!empty($containers)) {
    $message .= "*Total Containers:* " . count($containers) . "\n";
    
    // Add container details
    $message .= "\n*Container Details:*\n";
    foreach ($containers as $idx => $container) {
        $message .= ($idx + 1) . ". " . ($container['container_type'] ?? 'N/A') . " - " . ($container['container_number_1'] ?? 'N/A') . "\n";
    }
}

$message .= "\n*PDF Link:*\n";
$message .= "https://" . $_SERVER['HTTP_HOST'] . "/booking/generate_booking_pdf.php?booking_id=" . urlencode($booking['booking_id']);

// Don't HTML encode the entire message to preserve emojis
echo $message;
?></textarea>
                </div>
                
                <div class="flex gap-3 justify-end">
                    <button onclick="closeWhatsAppModal()" 
                            class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-lg text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button onclick="sendWhatsApp()" 
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium transition-colors">
                        <i class="fab fa-whatsapp mr-1"></i>
                        Send
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Ensure modal is hidden on page load
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('whatsappModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.add('hidden');
            }
        });

        function openWhatsAppModal() {
            const modal = document.getElementById('whatsappModal');
            modal.style.display = 'block';
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeWhatsAppModal() {
            const modal = document.getElementById('whatsappModal');
            modal.style.display = 'none';
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function sendWhatsApp() {
            const userSelect = document.getElementById('userSelect');
            const selectedOption = userSelect.options[userSelect.selectedIndex];
            const message = document.getElementById('whatsappMessage').value.trim();
            
            if (!userSelect.value) {
                alert('Please select a user');
                return;
            }
            
            const phoneNumber = userSelect.value;
            const userName = selectedOption.getAttribute('data-name');
            
            // Clean phone number (remove spaces, dashes, etc.)
            const cleanPhone = phoneNumber.replace(/[\s\-\(\)]/g, '');
            
            // Create WhatsApp URL
            const whatsappUrl = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(message)}`;
            
            // Open WhatsApp in new tab
            window.open(whatsappUrl, '_blank');
            
            // Close modal
            closeWhatsAppModal();
        }

        // Close modal when clicking outside
        document.getElementById('whatsappModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeWhatsAppModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeWhatsAppModal();
            }
        });
    </script>
</body>
</html>
