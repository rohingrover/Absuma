<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Check if user has L2 supervisor or superadmin access
$user_role = $_SESSION['role'];
$allowed_roles = ['l2_supervisor', 'superadmin'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. This page is only for L2 supervisors and superadmins.";
    header("Location: dashboard.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            $booking_id = $_POST['booking_id'];
            $action = $_POST['action'];
            
            $pdo->beginTransaction();
            
            switch ($action) {
                case 'assign_owned_vehicle':
                    handleOwnedVehicleAssignment($pdo, $_POST);
                    break;
                    
                case 'assign_vendor_vehicle':
                    handleVendorVehicleAssignment($pdo, $_POST);
                    break;
                    
                case 'reject_booking':
                    handleBookingRejection($pdo, $_POST);
                    break;
                    
                default:
                    throw new Exception("Invalid action");
            }
            
            $pdo->commit();
            $success_message = "Booking processed successfully!";
        }
    } catch (Exception $e) {
        $pdo->rollback();
        $error_message = "Error processing booking: " . $e->getMessage();
    }
}

// Function to handle owned vehicle assignment
function handleOwnedVehicleAssignment($pdo, $data) {
    $booking_id = $data['booking_id'];
    $vehicle_id = $data['vehicle_id'];
    $driver_notes = $data['driver_notes'] ?? '';
    
    // Check if booking exists and is in pending status
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'pending'");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        throw new Exception("Booking not found or not in pending status");
    }
    
    // Check if vehicle is available
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND current_status IN ('available', 'idle') AND approval_status = 'approved'");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        throw new Exception("Selected vehicle is not available");
    }
    
    // Update booking status to in_progress and assign vehicle
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'in_progress', 
            assigned_vehicle_id = ?, 
            vehicle_type = 'owned',
            driver_notes = ?,
            assigned_by = ?,
            assigned_at = NOW(),
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$vehicle_id, $driver_notes, $_SESSION['user_id'], $_SESSION['user_id'], $booking_id]);
    
    // Update vehicle status to assigned
    $stmt = $pdo->prepare("UPDATE vehicles SET current_status = 'assigned' WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    
    // Create activity log
    $stmt = $pdo->prepare("
        INSERT INTO booking_activities (booking_id, activity_type, description, created_by, created_at)
        VALUES (?, 'vehicle_assigned', ?, ?, NOW())
    ");
    $description = "Owned vehicle " . $vehicle['vehicle_number'] . " assigned by " . $_SESSION['full_name'];
    $stmt->execute([$booking_id, $description, $_SESSION['user_id']]);
}

// Function to handle vendor vehicle assignment (needs approval)
function handleVendorVehicleAssignment($pdo, $data) {
    $booking_id = $data['booking_id'];
    $vendor_id = $data['vendor_id'];
    $vehicle_details = $data['vehicle_details'];
    $driver_name = $data['driver_name'];
    $driver_contact = $data['driver_contact'];
    $rate_per_day = floatval($data['rate_per_day']);
    $estimated_days = intval($data['estimated_days']);
    $total_cost = $rate_per_day * $estimated_days;
    $notes = $data['vendor_notes'] ?? '';
    
    // Validate inputs
    if ($rate_per_day <= 0) {
        throw new Exception("Rate per day must be greater than 0");
    }
    
    if ($estimated_days <= 0) {
        throw new Exception("Estimated days must be greater than 0");
    }
    
    // Check if booking exists and is in pending status
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'pending'");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        throw new Exception("Booking not found or not in pending status");
    }
    
    // Check if vendor exists and is active
    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ? AND status = 'active'");
    $stmt->execute([$vendor_id]);
    $vendor = $stmt->fetch();
    
    if (!$vendor) {
        throw new Exception("Selected vendor is not available");
    }
    
    // Update booking status to pending_vendor_approval
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'pending_vendor_approval',
            vendor_id = ?,
            vendor_vehicle_details = ?,
            vendor_driver_name = ?,
            vendor_driver_contact = ?,
            vendor_rate_per_day = ?,
            vendor_estimated_days = ?,
            vendor_total_cost = ?,
            vendor_notes = ?,
            assigned_by = ?,
            assigned_at = NOW(),
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $vendor_id, $vehicle_details, $driver_name, $driver_contact,
        $rate_per_day, $estimated_days, $total_cost, $notes,
        $_SESSION['user_id'], $_SESSION['user_id'], $booking_id
    ]);
    
    // Create activity log
    $stmt = $pdo->prepare("
        INSERT INTO booking_activities (booking_id, activity_type, description, created_by, created_at)
        VALUES (?, 'vendor_vehicle_assigned', ?, ?, NOW())
    ");
    $description = "Vendor vehicle from " . $vendor['company_name'] . " assigned by " . $_SESSION['full_name'] . " - Awaiting manager approval for rate ₹" . number_format($total_cost, 2);
    $stmt->execute([$booking_id, $description, $_SESSION['user_id']]);
}

// Function to handle booking rejection
function handleBookingRejection($pdo, $data) {
    $booking_id = $data['booking_id'];
    $rejection_reason = trim($data['rejection_reason']);
    
    if (empty($rejection_reason)) {
        throw new Exception("Rejection reason is required");
    }
    
    // Check if booking exists and is in pending status
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'pending'");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        throw new Exception("Booking not found or not in pending status");
    }
    
    // Update booking status to rejected
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'rejected',
            rejection_reason = ?,
            rejected_by = ?,
            rejected_at = NOW(),
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$rejection_reason, $_SESSION['user_id'], $_SESSION['user_id'], $booking_id]);
    
    // Create activity log
    $stmt = $pdo->prepare("
        INSERT INTO booking_activities (booking_id, activity_type, description, created_by, created_at)
        VALUES (?, 'booking_rejected', ?, ?, NOW())
    ");
    $description = "Booking rejected by " . $_SESSION['full_name'] . ": " . $rejection_reason;
    $stmt->execute([$booking_id, $description, $_SESSION['user_id']]);
}

// Get pending bookings for L2 supervisor to work on
$pending_bookings = [];
$stmt = $pdo->prepare("
    SELECT 
        b.*,
        c.client_name,
        c.client_code,
        loc_from.location as from_location_name,
        loc_to.location as to_location_name,
        creator.full_name as created_by_name,
        creator.role as creator_role
    FROM bookings b
    LEFT JOIN clients c ON b.client_id = c.id
    LEFT JOIN location loc_from ON b.from_location_id = loc_from.id
    LEFT JOIN location loc_to ON b.to_location_id = loc_to.id
    LEFT JOIN users creator ON b.created_by = creator.id
    WHERE b.status = 'pending'
    ORDER BY b.created_at ASC
");
$stmt->execute();
$pending_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available owned vehicles
$owned_vehicles = [];
$stmt = $pdo->prepare("
    SELECT id, vehicle_number, make_model, current_status, driver_name
    FROM vehicles 
    WHERE current_status IN ('available', 'idle') 
    AND approval_status = 'approved'
    ORDER BY vehicle_number
");
$stmt->execute();
$owned_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active vendors with corrected schema
$vendors = [];
$stmt = $pdo->prepare("
    SELECT id, vendor_code, company_name, contact_person, mobile, email, vendor_type, total_vehicles
    FROM vendors 
    WHERE status = 'active'
    ORDER BY company_name
");
$stmt->execute();
$vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'pending' => count($pending_bookings),
    'available_vehicles' => count($owned_vehicles),
    'active_vendors' => count($vendors)
];

// Get container details for each booking
foreach ($pending_bookings as &$booking) {
    $stmt = $pdo->prepare("
        SELECT container_sequence, container_type, container_number_1, container_number_2,
               from_location_id, to_location_id
        FROM booking_containers 
        WHERE booking_id = ? 
        ORDER BY container_sequence
    ");
    $stmt->execute([$booking['id']]);
    $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get location names for containers if they have individual locations
    foreach ($containers as &$container) {
        if ($container['from_location_id']) {
            $stmt = $pdo->prepare("SELECT location FROM location WHERE id = ?");
            $stmt->execute([$container['from_location_id']]);
            $result = $stmt->fetch();
            $container['from_location_name'] = $result ? $result['location'] : null;
        }
        
        if ($container['to_location_id']) {
            $stmt = $pdo->prepare("SELECT location FROM location WHERE id = ?");
            $stmt->execute([$container['to_location_id']]);
            $result = $stmt->fetch();
            $container['to_location_name'] = $result ? $result['location'] : null;
        }
    }
    
    $booking['containers'] = $containers;
}

// Get recent activities for dashboard (optional)
$recent_activities = [];
$stmt = $pdo->prepare("
    SELECT ba.*, b.booking_id, u.full_name as user_name
    FROM booking_activities ba
    LEFT JOIN bookings b ON ba.booking_id = b.id
    LEFT JOIN users u ON ba.created_by = u.id
    WHERE ba.created_by = ?
    ORDER BY ba.created_at DESC
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Requests - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'teal-600': '#0d9488',
                        'teal-700': '#0f766e',
                        'teal-500': '#14b8a6',
                        'teal-50': '#f0fdfa',
                        'teal-100': '#ccfbf1',
                        'teal-200': '#99f6e4',
                        'teal-300': '#5eead4',
                        'teal-400': '#2dd4bf',
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f0fdfa 0%, #e6fffa 100%);
        }
        
        .shadow-soft {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .card-hover-effect {
            transition: all 0.3s ease;
        }
        
        .card-hover-effect:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-pending_vendor_approval {
            background-color: #ddd6fe;
            color: #5b21b6;
        }
        
        .status-in_progress {
            background-color: #bfdbfe;
            color: #1e40af;
        }
        
        .status-completed {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
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
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1), 0 4px 6px -1px rgba(0, 0, 0, 0.1);
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
        
        .btn-primary {
            background-color: #0d9488;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0f766e;
        }
        
        .btn-secondary {
            background-color: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        
        .btn-danger {
            background-color: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b91c1c;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .priority-high {
            border-left: 4px solid #dc2626;
        }
        
        .priority-medium {
            border-left: 4px solid #d97706;
        }
        
        .priority-normal {
            border-left: 4px solid #059669;
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
        <?php include 'sidebar_navigation.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Pending Requests</h1>
                        <p class="text-sm text-gray-600 mt-1">Manage booking requests and assign vehicles</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="bg-teal-50 px-4 py-2 rounded-lg">
                            <span class="text-sm font-medium text-teal-800"><?= ucfirst(str_replace('_', ' ', $user_role)) ?> Dashboard</span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="mx-6 mt-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg animate-fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mx-6 mt-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg animate-fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="mx-6 mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-2xl font-bold text-gray-900"><?= $stats['pending'] ?></p>
                            <p class="text-sm text-gray-600">Pending Requests</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-truck text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-2xl font-bold text-gray-900"><?= $stats['available_vehicles'] ?></p>
                            <p class="text-sm text-gray-600">Available Vehicles</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-handshake text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-2xl font-bold text-gray-900"><?= $stats['active_vendors'] ?></p>
                            <p class="text-sm text-gray-600">Active Vendors</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-7xl mx-auto">
                    <?php if (empty($pending_bookings)): ?>
                        <!-- No Pending Requests -->
                        <div class="bg-white rounded-xl shadow-soft p-12 text-center">
                            <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
                                <i class="fas fa-clipboard-check text-3xl text-gray-400"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-900 mb-2">No Pending Requests</h3>
                            <p class="text-gray-600 mb-6">All booking requests have been processed. Great job!</p>
                            <a href="dashboard.php" class="btn-enhanced btn-primary">
                                <i class="fas fa-tachometer-alt mr-2"></i>
                                Go to Dashboard
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- Pending Requests List -->
                        <div class="space-y-6">
                            <?php foreach ($pending_bookings as $booking): ?>
                                <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect priority-normal">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h3 class="text-xl font-bold text-gray-900 mb-2">
                                                Booking #<?= htmlspecialchars($booking['booking_id']) ?>
                                            </h3>
                                            <div class="flex flex-wrap gap-2 mb-3">
                                                <span class="status-badge status-<?= $booking['status'] ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $booking['status'])) ?>
                                                </span>
                                                <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                                                    <?= $booking['no_of_containers'] ?> Container<?= $booking['no_of_containers'] > 1 ? 's' : '' ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm text-gray-500">Created by</p>
                                            <p class="font-medium text-gray-900"><?= htmlspecialchars($booking['created_by_name']) ?></p>
                                            <p class="text-xs text-gray-500"><?= ucfirst(str_replace('_', ' ', $booking['creator_role'])) ?></p>
                                            <p class="text-xs text-gray-500 mt-1"><?= date('M d, Y H:i', strtotime($booking['created_at'])) ?></p>
                                        </div>
                                    </div>

                                    <!-- Booking Details -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                        <div>
                                            <h4 class="font-semibold text-gray-900 mb-3">Client Information</h4>
                                            <div class="space-y-2">
                                                <p><span class="text-gray-600">Client:</span> <span class="font-medium"><?= htmlspecialchars($booking['client_name']) ?></span></p>
                                                <p><span class="text-gray-600">Code:</span> <span class="font-medium"><?= htmlspecialchars($booking['client_code']) ?></span></p>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <h4 class="font-semibold text-gray-900 mb-3">Location Details</h4>
                                            <div class="space-y-2">
                                                <p><span class="text-gray-600">From:</span> <span class="font-medium"><?= htmlspecialchars($booking['from_location_name'] ?? 'Not specified') ?></span></p>
                                                <p><span class="text-gray-600">To:</span> <span class="font-medium"><?= htmlspecialchars($booking['to_location_name'] ?? 'Not specified') ?></span></p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Container Details -->
                                    <?php if (!empty($booking['containers'])): ?>
                                        <div class="mb-6">
                                            <h4 class="font-semibold text-gray-900 mb-3">Container Details</h4>
                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                                <?php foreach ($booking['containers'] as $container): ?>
                                                    <div class="bg-gray-50 rounded-lg p-4">
                                                        <div class="flex items-center gap-2 mb-2">
                                                            <span class="w-6 h-6 bg-teal-600 text-white rounded-full flex items-center justify-center text-xs font-bold">
                                                                <?= $container['container_sequence'] ?>
                                                            </span>
                                                            <span class="font-medium"><?= htmlspecialchars($container['container_type']) ?></span>
                                                        </div>
                                                        <div class="text-sm text-gray-600">
                                                            <?php if ($container['container_number_1']): ?>
                                                                <p>№1: <?= htmlspecialchars($container['container_number_1']) ?></p>
                                                            <?php endif; ?>
                                                            <?php if ($container['container_number_2']): ?>
                                                                <p>№2: <?= htmlspecialchars($container['container_number_2']) ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (isset($container['from_location_name']) || isset($container['to_location_name'])): ?>
                                                            <div class="text-xs text-blue-600 mt-2 border-t border-gray-200 pt-2">
                                                                <?php if (isset($container['from_location_name'])): ?>
                                                                    <p>From: <?= htmlspecialchars($container['from_location_name']) ?></p>
                                                                <?php endif; ?>
                                                                <?php if (isset($container['to_location_name'])): ?>
                                                                    <p>To: <?= htmlspecialchars($container['to_location_name']) ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Action Buttons -->
                                    <div class="flex flex-wrap gap-3 pt-4 border-t border-gray-200">
                                        <button onclick="openOwnedVehicleModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['booking_id']) ?>')" 
                                                class="btn-enhanced btn-primary">
                                            <i class="fas fa-truck mr-2"></i>
                                            Assign Owned Vehicle
                                        </button>
                                        
                                        <button onclick="openVendorVehicleModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['booking_id']) ?>')" 
                                                class="btn-enhanced btn-secondary">
                                            <i class="fas fa-handshake mr-2"></i>
                                            Assign Vendor Vehicle
                                        </button>
                                        
                                        <button onclick="openRejectModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['booking_id']) ?>')" 
                                                class="btn-enhanced btn-danger">
                                            <i class="fas fa-times mr-2"></i>
                                            Reject Booking
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Owned Vehicle Assignment Modal -->
    <div id="ownedVehicleModal" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Assign Owned Vehicle</h3>
                    <button onclick="closeModal('ownedVehicleModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="assign_owned_vehicle">
                    <input type="hidden" name="booking_id" id="owned_booking_id">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-truck text-teal-600 mr-2"></i>Select Vehicle *
                        </label>
                        <select name="vehicle_id" class="input-enhanced" required>
                            <option value="">Choose available vehicle...</option>
                            <?php foreach ($owned_vehicles as $vehicle): ?>
                                <option value="<?= $vehicle['id'] ?>">
                                    <?= htmlspecialchars($vehicle['vehicle_number']) ?> - 
                                    <?= htmlspecialchars($vehicle['make_model']) ?>
                                    <?php if ($vehicle['driver_name']): ?>
                                        (Driver: <?= htmlspecialchars($vehicle['driver_name']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-sticky-note text-teal-600 mr-2"></i>Driver Notes
                        </label>
                        <textarea name="driver_notes" rows="3" class="input-enhanced" 
                                  placeholder="Any special instructions or notes for the driver..."></textarea>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="submit" class="btn-enhanced btn-primary flex-1">
                            <i class="fas fa-check mr-2"></i>Assign Vehicle
                        </button>
                        <button type="button" onclick="closeModal('ownedVehicleModal')" class="btn-enhanced btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Vendor Vehicle Assignment Modal -->
    <div id="vendorVehicleModal" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Assign Vendor Vehicle</h3>
                    <button onclick="closeModal('vendorVehicleModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>
                        <span class="text-sm text-yellow-800 font-medium">
                            Vendor vehicle assignments require manager approval for rates
                        </span>
                    </div>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="assign_vendor_vehicle">
                    <input type="hidden" name="booking_id" id="vendor_booking_id">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-handshake text-teal-600 mr-2"></i>Select Vendor *
                        </label>
                        <select name="vendor_id" class="input-enhanced" required>
                            <option value="">Choose vendor...</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?= $vendor['id'] ?>">
                                    <?= htmlspecialchars($vendor['company_name']) ?> (<?= htmlspecialchars($vendor['vendor_code']) ?>) - 
                                    <?= htmlspecialchars($vendor['contact_person']) ?>
                                    <?php if ($vendor['total_vehicles']): ?>
                                        [<?= $vendor['total_vehicles'] ?> vehicles]
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-truck text-teal-600 mr-2"></i>Vehicle Details *
                            </label>
                            <input type="text" name="vehicle_details" class="input-enhanced" required
                                   placeholder="e.g., Tata 407, License: MH01AB1234">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-user text-teal-600 mr-2"></i>Driver Name *
                            </label>
                            <input type="text" name="driver_name" class="input-enhanced" required
                                   placeholder="Driver's full name">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone text-teal-600 mr-2"></i>Driver Contact *
                        </label>
                        <input type="tel" name="driver_contact" class="input-enhanced" required
                               placeholder="Driver's mobile number">
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-rupee-sign text-teal-600 mr-2"></i>Rate per Day *
                            </label>
                            <input type="number" name="rate_per_day" id="rate_per_day" class="input-enhanced" required
                                   min="0" step="0.01" placeholder="0.00" onchange="calculateTotal()">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar text-teal-600 mr-2"></i>Estimated Days *
                            </label>
                            <input type="number" name="estimated_days" id="estimated_days" class="input-enhanced" required
                                   min="1" placeholder="1" onchange="calculateTotal()">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calculator text-teal-600 mr-2"></i>Total Cost
                            </label>
                            <input type="text" id="total_cost_display" class="input-enhanced bg-gray-100" readonly
                                   placeholder="Auto-calculated">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-sticky-note text-teal-600 mr-2"></i>Additional Notes
                        </label>
                        <textarea name="vendor_notes" rows="3" class="input-enhanced" 
                                  placeholder="Any special requirements, terms, or conditions..."></textarea>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="submit" class="btn-enhanced btn-primary flex-1">
                            <i class="fas fa-check mr-2"></i>Submit for Approval
                        </button>
                        <button type="button" onclick="closeModal('vendorVehicleModal')" class="btn-enhanced btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Reject Booking</h3>
                    <button onclick="closeModal('rejectModal')" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle text-red-600 mr-2"></i>
                        <span class="text-sm text-red-800 font-medium">
                            This action will permanently reject the booking request
                        </span>
                    </div>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="reject_booking">
                    <input type="hidden" name="booking_id" id="reject_booking_id">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-comment text-teal-600 mr-2"></i>Rejection Reason *
                        </label>
                        <textarea name="rejection_reason" rows="4" class="input-enhanced" required
                                  placeholder="Please provide a clear reason for rejecting this booking..."></textarea>
                        <p class="text-xs text-gray-500 mt-1">This reason will be shared with the booking creator</p>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="submit" class="btn-enhanced btn-danger flex-1">
                            <i class="fas fa-times mr-2"></i>Reject Booking
                        </button>
                        <button type="button" onclick="closeModal('rejectModal')" class="btn-enhanced btn-secondary">
                            <i class="fas fa-arrow-left mr-2"></i>Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
	<script>
    // Modal functions
    function openOwnedVehicleModal(bookingId, bookingRef) {
        document.getElementById('owned_booking_id').value = bookingId;
        document.getElementById('ownedVehicleModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    
    function openVendorVehicleModal(bookingId, bookingRef) {
        document.getElementById('vendor_booking_id').value = bookingId;
        document.getElementById('vendorVehicleModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    
    function openRejectModal(bookingId, bookingRef) {
        document.getElementById('reject_booking_id').value = bookingId;
        document.getElementById('rejectModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Reset forms
        const form = document.querySelector('#' + modalId + ' form');
        if (form) {
            form.reset();
            // Clear calculated fields
            if (modalId === 'vendorVehicleModal') {
                document.getElementById('total_cost_display').value = '';
            }
        }
    }
    
    // Calculate total cost for vendor vehicle
    function calculateTotal() {
        const rate = parseFloat(document.getElementById('rate_per_day').value) || 0;
        const days = parseInt(document.getElementById('estimated_days').value) || 0;
        const total = rate * days;
        
        document.getElementById('total_cost_display').value = total > 0 ? 
            '₹' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modals = ['ownedVehicleModal', 'vendorVehicleModal', 'rejectModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (event.target === modal) {
                closeModal(modalId);
            }
        });
    }
    
    // Auto-refresh page every 30 seconds to check for new requests
    let autoRefreshInterval;
    function startAutoRefresh() {
        autoRefreshInterval = setInterval(function() {
            // Only refresh if no modals are open
            const modalsOpen = ['ownedVehicleModal', 'vendorVehicleModal', 'rejectModal']
                .some(id => document.getElementById(id).style.display === 'block');
            
            if (!modalsOpen) {
                // Show subtle notification before refresh
                showRefreshNotification();
                setTimeout(() => {
                    location.reload();
                }, 2000);
            }
        }, 30000);
    }
    
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
    }
    
    function showRefreshNotification() {
        const notification = document.createElement('div');
        notification.className = 'fixed top-4 right-4 bg-blue-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-fade-in';
        notification.innerHTML = '<i class="fas fa-sync-alt mr-2"></i>Checking for new requests...';
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Escape key to close modals
        if (e.key === 'Escape') {
            const modals = ['ownedVehicleModal', 'vendorVehicleModal', 'rejectModal'];
            modals.forEach(modalId => {
                if (document.getElementById(modalId).style.display === 'block') {
                    closeModal(modalId);
                }
            });
        }
        
        // Ctrl+R to refresh (stop auto-refresh temporarily)
        if (e.ctrlKey && e.key === 'r') {
            stopAutoRefresh();
            setTimeout(startAutoRefresh, 60000); // Restart after 1 minute
        }
    });
    
    // Form validation and enhancement
    document.addEventListener('DOMContentLoaded', function() {
        // Start auto-refresh
        startAutoRefresh();
        
        // Validate phone numbers
        const phoneInputs = document.querySelectorAll('input[type="tel"]');
        phoneInputs.forEach(input => {
            input.addEventListener('input', function() {
                // Allow only numbers, +, -, and spaces
                this.value = this.value.replace(/[^0-9+\-\s]/g, '');
                
                // Basic validation feedback
                if (this.value.length >= 10) {
                    this.classList.add('border-green-500');
                    this.classList.remove('border-red-500');
                } else if (this.value.length > 0) {
                    this.classList.add('border-red-500');
                    this.classList.remove('border-green-500');
                } else {
                    this.classList.remove('border-green-500', 'border-red-500');
                }
            });
        });
        
        // Validate numeric inputs
        const numericInputs = document.querySelectorAll('input[type="number"]');
        numericInputs.forEach(input => {
            input.addEventListener('input', function() {
                if (this.value < 0) this.value = 0;
                
                // Add visual feedback for rate validation
                if (this.name === 'rate_per_day' && this.value > 0) {
                    this.classList.add('border-green-500');
                    this.classList.remove('border-red-500');
                } else if (this.name === 'rate_per_day' && this.value <= 0 && this.value !== '') {
                    this.classList.add('border-red-500');
                    this.classList.remove('border-green-500');
                }
            });
        });
        
        // Enhanced form submission with loading states
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                    submitBtn.disabled = true;
                    
                    // Re-enable button after 5 seconds as fallback
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                }
            });
        });
        
        // Add confirmation for reject action
        const rejectForm = document.querySelector('#rejectModal form');
        if (rejectForm) {
            rejectForm.addEventListener('submit', function(e) {
                const reason = this.querySelector('textarea[name="rejection_reason"]').value.trim();
                if (reason.length < 10) {
                    e.preventDefault();
                    alert('Please provide a more detailed rejection reason (at least 10 characters).');
                    return false;
                }
                
                if (!confirm('Are you sure you want to reject this booking? This action cannot be undone.')) {
                    e.preventDefault();
                    return false;
                }
            });
        }
        
        // Auto-save vendor form data in localStorage (in case of accidental refresh)
        const vendorForm = document.querySelector('#vendorVehicleModal form');
        if (vendorForm) {
            const formInputs = vendorForm.querySelectorAll('input, select, textarea');
            
            formInputs.forEach(input => {
                // Load saved data
                const savedValue = localStorage.getItem(`vendor_form_${input.name}`);
                if (savedValue && input.type !== 'hidden') {
                    input.value = savedValue;
                    if (input.name === 'rate_per_day' || input.name === 'estimated_days') {
                        calculateTotal();
                    }
                }
                
                // Save data on change
                input.addEventListener('change', function() {
                    if (this.type !== 'hidden') {
                        localStorage.setItem(`vendor_form_${this.name}`, this.value);
                    }
                });
            });
            
            // Clear saved data on successful submission
            vendorForm.addEventListener('submit', function() {
                formInputs.forEach(input => {
                    localStorage.removeItem(`vendor_form_${input.name}`);
                });
            });
        }
        
        // Add tooltips for better UX
        addTooltips();
        
        // Initialize drag and drop for file uploads (if needed in future)
        initializeDragDrop();
        
        // Add keyboard navigation for modals
        addModalKeyboardNavigation();
    });
    
    // Add tooltips function
    function addTooltips() {
        const tooltipElements = [
            { selector: 'input[name="rate_per_day"]', text: 'Enter the daily rate charged by the vendor' },
            { selector: 'input[name="estimated_days"]', text: 'Estimate how many days the vehicle will be needed' },
            { selector: 'textarea[name="vendor_notes"]', text: 'Include any special terms, conditions, or requirements' },
            { selector: 'textarea[name="driver_notes"]', text: 'Special instructions for the driver (route, timings, etc.)' }
        ];
        
        tooltipElements.forEach(item => {
            const element = document.querySelector(item.selector);
            if (element) {
                element.title = item.text;
                element.addEventListener('mouseenter', showTooltip);
                element.addEventListener('mouseleave', hideTooltip);
            }
        });
    }
    
    function showTooltip(e) {
        // Custom tooltip implementation could go here
    }
    
    function hideTooltip(e) {
        // Hide custom tooltip
    }
    
    // Initialize drag and drop (placeholder for future file upload features)
    function initializeDragDrop() {
        // Future implementation for document uploads
    }
    
    // Add keyboard navigation for modals
    function addModalKeyboardNavigation() {
        const modals = ['ownedVehicleModal', 'vendorVehicleModal', 'rejectModal'];
        
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('keydown', function(e) {
                    // Tab navigation within modal
                    if (e.key === 'Tab') {
                        const focusableElements = this.querySelectorAll(
                            'input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])'
                        );
                        const firstElement = focusableElements[0];
                        const lastElement = focusableElements[focusableElements.length - 1];
                        
                        if (e.shiftKey) {
                            if (document.activeElement === firstElement) {
                                e.preventDefault();
                                lastElement.focus();
                            }
                        } else {
                            if (document.activeElement === lastElement) {
                                e.preventDefault();
                                firstElement.focus();
                            }
                        }
                    }
                });
            }
        });
    }
    
    // Utility function to format currency
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-IN', {
            style: 'currency',
            currency: 'INR',
            minimumFractionDigits: 2
        }).format(amount);
    }
    
    // Utility function to show notifications
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        const bgColor = type === 'success' ? 'bg-green-500' : 
                        type === 'error' ? 'bg-red-500' : 
                        type === 'warning' ? 'bg-yellow-500' : 'bg-blue-500';
        
        notification.className = `fixed top-4 right-4 ${bgColor} text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-fade-in`;
        notification.innerHTML = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 5000);
    }
    
    // Handle connection status
    function handleConnectionStatus() {
        window.addEventListener('online', function() {
            showNotification('<i class="fas fa-wifi mr-2"></i>Connection restored', 'success');
            startAutoRefresh();
        });
        
        window.addEventListener('offline', function() {
            showNotification('<i class="fas fa-wifi-slash mr-2"></i>Connection lost - Working offline', 'warning');
            stopAutoRefresh();
        });
    }
    
    // Initialize connection monitoring
    handleConnectionStatus();
    
    // Cleanup on page unload
    window.addEventListener('beforeunload', function() {
        stopAutoRefresh();
    });
</script>
</body>
</html>

    <script>
        // Modal functions
        function openOwnedVehicleModal(bookingId, bookingRef) {
            document.getElementById('owned_booking_id').value = bookingId;
            document.getElementById('ownedVehicleModal').style.display = 'block';
        }
        
        function openVendorVehicleModal(bookingId, bookingRef) {
            document.getElementById('vendor_booking_id').value = bookingId;
            document.getElementById('vendorVehicleModal').style.display = 'block';
        }
        
        function openRejectModal(bookingId, bookingRef) {
            document.getElementById('reject_booking_id').value = bookingId;
            document.getElementById('rejectModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // Reset forms
            const form = document.querySelector('#' + modalId + ' form');
            if (form) {
                form.reset();
                // Clear calculated fields
                if (modalId === 'vendorVehicleModal') {
                    document.getElementById('total_cost_display').value = '';
                }
            }
        }
        
        // Calculate total cost for vendor vehicle
        function calculateTotal() {
            const rate = parseFloat(document.getElementById('rate_per_day').value) || 0;
            const days = parseInt(document.getElementById('estimated_days').value) || 0;
            const total = rate * days;
            
            document.getElementById('total_cost_display').value = total > 0 ? 
                '₹' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['ownedVehicleModal', 'vendorVehicleModal', 'rejectModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        }
        
        // Auto-refresh page every 30 seconds to check for new requests
        setInterval(function() {
            // Only refresh if no modals are open
            const modalsOpen = ['ownedVehicleModal', 'vendorVehicleModal', 'rejectModal']
                .some(id => document.getElementById(id).style.display === 'block');
            
            if (!modalsOpen) {
                location.reload();
            }
        }, 30000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key to close modals
            if (e.key === 'Escape') {
                const modals = ['ownedVehicleModal', 'vendorVehicleModal', 'rejectModal'];
                modals.forEach(modalId => {
                    if (document.getElementById(modalId).style.display === 'block') {
                        closeModal(modalId);
                    }
                });
            }
        });
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            // Validate phone numbers
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9+\-\s]/g, '');
                });
            });
            
            // Validate numeric inputs
            const numericInputs = document.querySelectorAll('input[type="number"]');
            numericInputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.value < 0) this.value = 0;
                });
            });
        });
    </script>
</body>
</html>