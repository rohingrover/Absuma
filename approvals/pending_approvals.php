<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Role-based access control - Only managers and above can approve
$user_role = $_SESSION['role'];
$allowed_roles = ['manager1', 'manager2', 'admin', 'superadmin'];

if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to access approvals.";
    header("Location: dashboard.php");
    exit();
}

// Define role hierarchy (higher number = more permissions)
$role_hierarchy = [
    'staff' => 1,
    'l1_supervisor' => 2,
    'l2_supervisor' => 3,
    'manager1' => 4,
    'manager2' => 4,
    'admin' => 5,
    'superadmin' => 6
];

// Check if user can approve changes
if (!function_exists('canApprove')) {
    function canApprove($user_role) {
        return in_array($user_role, ['manager1', 'manager2', 'admin', 'superadmin']);
    }
}

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $request_type = $_POST['request_type']; // 'vehicle', 'vendor', 'vendor_bank', 'vendor_deletion'
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'approve') {
            // Handle different types of approvals
            switch ($request_type) {
                case 'vehicle':
                    handleVehicleApproval($pdo, $request_id, $_SESSION['user_id']);
                    break;
                case 'vendor':
                    handleVendorApproval($pdo, $request_id, $_SESSION['user_id']);
                    break;
                case 'vendor_bank':
                    handleVendorBankApproval($pdo, $request_id, $_SESSION['user_id']);
                    break;
                case 'vendor_deletion':
                    handleVendorDeletionApproval($pdo, $request_id, $_SESSION['user_id']);
                    break;
                default:
                    throw new Exception("Invalid request type");
            }
            
            $_SESSION['success'] = ucfirst(str_replace('_', ' ', $request_type)) . " request approved successfully!";
            
        } elseif ($action === 'reject') {
            $rejection_reason = $_POST['rejection_reason'] ?? 'No reason provided';
            
            // Handle different types of rejections
            switch ($request_type) {
                case 'vehicle':
                    handleVehicleRejection($pdo, $request_id, $_SESSION['user_id'], $rejection_reason);
                    break;
                case 'vendor':
                    handleVendorRejection($pdo, $request_id, $_SESSION['user_id'], $rejection_reason);
                    break;
                case 'vendor_bank':
                    handleVendorBankRejection($pdo, $request_id, $_SESSION['user_id'], $rejection_reason);
                    break;
                case 'vendor_deletion':
                    handleVendorDeletionRejection($pdo, $request_id, $_SESSION['user_id'], $rejection_reason);
                    break;
                default:
                    throw new Exception("Invalid request type");
            }
            
            $_SESSION['success'] = ucfirst(str_replace('_', ' ', $request_type)) . " request rejected.";
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error processing request: " . $e->getMessage();
    }
    
    header("Location: pending_approvals.php");
    exit();
}

// Vehicle approval handler (existing)
function handleVehicleApproval($pdo, $request_id, $approver_id) {
    // Get the change request
    $stmt = $pdo->prepare("SELECT * FROM vehicle_change_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception("Vehicle request not found or already processed");
    }
    
    $proposed_data = json_decode($request['proposed_data'], true);
    
    if ($request['request_type'] === 'create') {
        if ($request['vehicle_id']) {
            // Update existing placeholder vehicle to approved status
            $stmt = $pdo->prepare("UPDATE vehicles SET 
                current_status = 'available',
                approval_status = 'approved',
                last_approved_by = ?,
                last_approved_at = NOW()
                WHERE id = ?");
            $stmt->execute([$approver_id, $request['vehicle_id']]);
        }
    }
    
    // Update request status
    $stmt = $pdo->prepare("UPDATE vehicle_change_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->execute([$approver_id, $request_id]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Vehicle Request Approved', 'Your vehicle addition request has been approved', 'success', NOW())
    ");
    $stmt->execute([$request['requested_by']]);
}

// Vehicle rejection handler (existing)
function handleVehicleRejection($pdo, $request_id, $approver_id, $rejection_reason) {
    // Get the change request
    $stmt = $pdo->prepare("SELECT * FROM vehicle_change_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception("Vehicle request not found or already processed");
    }
    
    // If it's a create request, delete the placeholder vehicle
    if ($request['request_type'] === 'create' && $request['vehicle_id']) {
        $stmt = $pdo->prepare("DELETE FROM vehicles WHERE id = ? AND approval_status = 'pending_approval'");
        $stmt->execute([$request['vehicle_id']]);
    }
    
    // Update request status
    $stmt = $pdo->prepare("UPDATE vehicle_change_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
    $stmt->execute([$approver_id, $rejection_reason, $request_id]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Vehicle Request Rejected', ?, 'error', NOW())
    ");
    $stmt->execute([$request['requested_by'], "Your vehicle request was rejected. Reason: $rejection_reason"]);
}

// NEW: Vendor approval handler
function handleVendorApproval($pdo, $request_id, $approver_id) {
    // Update vendor approval status
    $stmt = $pdo->prepare("
        UPDATE vendors 
        SET approval_status = 'approved', approved_by = ?, approved_at = NOW() 
        WHERE id = ? AND approval_status = 'pending'
    ");
    $stmt->execute([$approver_id, $request_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Vendor not found or already processed");
    }
    
    // Get vendor details for notification
    $stmt = $pdo->prepare("SELECT company_name, created_by FROM vendors WHERE id = ?");
    $stmt->execute([$request_id]);
    $vendor = $stmt->fetch();
    
    // Log the approval
    $stmt = $pdo->prepare("
        INSERT INTO vendor_approval_logs 
        (vendor_id, action, action_by, action_at, comments, ip_address) 
        VALUES (?, 'approved', ?, NOW(), ?, ?)
    ");
    $stmt->execute([
        $request_id,
        $approver_id,
        "Vendor approved by manager/admin",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Vendor Approved', ?, 'success', NOW())
    ");
    $stmt->execute([
        $vendor['created_by'], 
        "Your vendor '{$vendor['company_name']}' has been approved"
    ]);
}

// NEW: Vendor rejection handler
function handleVendorRejection($pdo, $request_id, $approver_id, $rejection_reason) {
    // Update vendor approval status
    $stmt = $pdo->prepare("
        UPDATE vendors 
        SET approval_status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? 
        WHERE id = ? AND approval_status = 'pending'
    ");
    $stmt->execute([$approver_id, $rejection_reason, $request_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Vendor not found or already processed");
    }
    
    // Get vendor details for notification
    $stmt = $pdo->prepare("SELECT company_name, created_by FROM vendors WHERE id = ?");
    $stmt->execute([$request_id]);
    $vendor = $stmt->fetch();
    
    // Log the rejection
    $stmt = $pdo->prepare("
        INSERT INTO vendor_approval_logs 
        (vendor_id, action, action_by, action_at, comments, ip_address) 
        VALUES (?, 'rejected', ?, NOW(), ?, ?)
    ");
    $stmt->execute([
        $request_id,
        $approver_id,
        "Vendor rejected. Reason: $rejection_reason",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Vendor Rejected', ?, 'error', NOW())
    ");
    $stmt->execute([
        $vendor['created_by'], 
        "Your vendor '{$vendor['company_name']}' has been rejected. Reason: $rejection_reason"
    ]);
}

// NEW: Vendor bank details approval handler
function handleVendorBankApproval($pdo, $request_id, $approver_id) {
    // Get the bank change request
    $stmt = $pdo->prepare("SELECT * FROM vendor_bank_change_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception("Bank change request not found or already processed");
    }
    
    // Update vendor bank details
    $stmt = $pdo->prepare("
        UPDATE vendors 
        SET bank_name = ?, account_number = ?, branch_name = ?, ifsc_code = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $request['bank_name'],
        $request['account_number'],
        $request['branch_name'],
        $request['ifsc_code'],
        $request['vendor_id']
    ]);
    
    // Update request status
    $stmt = $pdo->prepare("UPDATE vendor_bank_change_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->execute([$approver_id, $request_id]);
    
    // Get vendor details for notification
    $stmt = $pdo->prepare("SELECT company_name FROM vendors WHERE id = ?");
    $stmt->execute([$request['vendor_id']]);
    $vendor = $stmt->fetch();
    
    // Log the approval
    $stmt = $pdo->prepare("
        INSERT INTO vendor_approval_logs 
        (vendor_id, action, action_by, action_at, comments, ip_address) 
        VALUES (?, 'bank_details_approved', ?, NOW(), ?, ?)
    ");
    $stmt->execute([
        $request['vendor_id'],
        $approver_id,
        "Bank details change approved",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Bank Details Approved', ?, 'success', NOW())
    ");
    $stmt->execute([
        $request['requested_by'], 
        "Bank details change for vendor '{$vendor['company_name']}' has been approved"
    ]);
}

// NEW: Vendor bank details rejection handler
function handleVendorBankRejection($pdo, $request_id, $approver_id, $rejection_reason) {
    // Get the bank change request
    $stmt = $pdo->prepare("SELECT * FROM vendor_bank_change_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception("Bank change request not found or already processed");
    }
    
    // Update request status
    $stmt = $pdo->prepare("UPDATE vendor_bank_change_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
    $stmt->execute([$approver_id, $rejection_reason, $request_id]);
    
    // Get vendor details for notification
    $stmt = $pdo->prepare("SELECT company_name FROM vendors WHERE id = ?");
    $stmt->execute([$request['vendor_id']]);
    $vendor = $stmt->fetch();
    
    // Log the rejection
    $stmt = $pdo->prepare("
        INSERT INTO vendor_approval_logs 
        (vendor_id, action, action_by, action_at, comments, ip_address) 
        VALUES (?, 'bank_details_rejected', ?, NOW(), ?, ?)
    ");
    $stmt->execute([
        $request['vendor_id'],
        $approver_id,
        "Bank details change rejected. Reason: $rejection_reason",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Bank Details Rejected', ?, 'error', NOW())
    ");
    $stmt->execute([
        $request['requested_by'], 
        "Bank details change for vendor '{$vendor['company_name']}' has been rejected. Reason: $rejection_reason"
    ]);
}

// NEW: Vendor deletion approval handler
function handleVendorDeletionApproval($pdo, $request_id, $approver_id) {
    // Get the deletion request
    $stmt = $pdo->prepare("SELECT * FROM vendor_deletion_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception("Deletion request not found or already processed");
    }
    
    // Get vendor details before deletion
    $stmt = $pdo->prepare("SELECT company_name FROM vendors WHERE id = ?");
    $stmt->execute([$request['vendor_id']]);
    $vendor = $stmt->fetch();
    
    // Perform the deletion
    $pdo->prepare("UPDATE vendor_vehicles SET deleted_at = NOW() WHERE vendor_id = ?")->execute([$request['vendor_id']]);
    $pdo->prepare("UPDATE vendor_documents SET deleted_at = NOW() WHERE vendor_id = ?")->execute([$request['vendor_id']]);
    $pdo->prepare("DELETE FROM vendor_contacts WHERE vendor_id = ?")->execute([$request['vendor_id']]);
    $pdo->prepare("DELETE FROM vendor_services WHERE vendor_id = ?")->execute([$request['vendor_id']]);
    $pdo->prepare("UPDATE vendors SET deleted_at = NOW() WHERE id = ?")->execute([$request['vendor_id']]);
    
    // Update request status
    $stmt = $pdo->prepare("UPDATE vendor_deletion_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->execute([$approver_id, $request_id]);
    
    // Log the deletion
    $stmt = $pdo->prepare("
        INSERT INTO vendor_approval_logs 
        (vendor_id, action, action_by, action_at, comments, ip_address) 
        VALUES (?, 'deleted', ?, NOW(), ?, ?)
    ");
    $stmt->execute([
        $request['vendor_id'],
        $approver_id,
        "Vendor deletion approved and executed",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Vendor Deletion Approved', ?, 'success', NOW())
    ");
    $stmt->execute([
        $request['requested_by'], 
        "Vendor '{$vendor['company_name']}' has been deleted as requested"
    ]);
}

// NEW: Vendor deletion rejection handler
function handleVendorDeletionRejection($pdo, $request_id, $approver_id, $rejection_reason) {
    // Get the deletion request
    $stmt = $pdo->prepare("SELECT * FROM vendor_deletion_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception("Deletion request not found or already processed");
    }
    
    // Update request status
    $stmt = $pdo->prepare("UPDATE vendor_deletion_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->execute([$approver_id, $request_id]);
    
    // Get vendor details for notification
    $stmt = $pdo->prepare("SELECT company_name FROM vendors WHERE id = ?");
    $stmt->execute([$request['vendor_id']]);
    $vendor = $stmt->fetch();
    
    // Log the rejection
    $stmt = $pdo->prepare("
        INSERT INTO vendor_approval_logs 
        (vendor_id, action, action_by, action_at, comments, ip_address) 
        VALUES (?, 'deletion_rejected', ?, NOW(), ?, ?)
    ");
    $stmt->execute([
        $request['vendor_id'],
        $approver_id,
        "Vendor deletion rejected. Reason: $rejection_reason",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Create notification for requester
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, created_at) 
        VALUES (?, 'Vendor Deletion Rejected', ?, 'error', NOW())
    ");
    $stmt->execute([
        $request['requested_by'], 
        "Vendor deletion request for '{$vendor['company_name']}' has been rejected. Reason: $rejection_reason"
    ]);
}

// Get all pending requests
$pending_requests = [];

// Vehicle requests
$stmt = $pdo->prepare("
    SELECT 
        vcr.id,
        vcr.request_type,
        vcr.proposed_data,
        vcr.reason,
        vcr.created_at,
        vcr.vehicle_id,
        v.vehicle_number,
        u.full_name as requested_by_name,
        u.role as requester_role,
        'vehicle' as category
    FROM vehicle_change_requests vcr 
    LEFT JOIN vehicles v ON vcr.vehicle_id = v.id 
    LEFT JOIN users u ON vcr.requested_by = u.id 
    WHERE vcr.status = 'pending' 
    ORDER BY vcr.created_at DESC
");
$stmt->execute();
$vehicle_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Vendor registration requests (pending approval)
$stmt = $pdo->prepare("
    SELECT 
        v.id,
        v.company_name,
        v.vendor_code,
        v.contact_person,
        v.vendor_type,
        v.created_at,
        u.full_name as requested_by_name,
        u.role as requester_role,
        'vendor' as category,
        'registration' as request_type
    FROM vendors v
    LEFT JOIN users u ON v.created_by = u.id 
    WHERE v.approval_status = 'pending' AND v.deleted_at IS NULL
    ORDER BY v.created_at DESC
");
$stmt->execute();
$vendor_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Vendor bank change requests
$stmt = $pdo->prepare("
    SELECT 
        vbcr.id,
        vbcr.vendor_id,
        vbcr.bank_name,
        vbcr.account_number,
        vbcr.branch_name,
        vbcr.ifsc_code,
        vbcr.requested_at as created_at,
        v.company_name,
        v.vendor_code,
        u.full_name as requested_by_name,
        u.role as requester_role,
        'vendor_bank' as category,
        'bank_change' as request_type
    FROM vendor_bank_change_requests vbcr
    LEFT JOIN vendors v ON vbcr.vendor_id = v.id
    LEFT JOIN users u ON vbcr.requested_by = u.id 
    WHERE vbcr.status = 'pending'
    ORDER BY vbcr.requested_at DESC
");
$stmt->execute();
$vendor_bank_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Vendor deletion requests
$stmt = $pdo->prepare("
    SELECT 
        vdr.id,
        vdr.vendor_id,
        vdr.reason,
        vdr.requested_at as created_at,
        v.company_name,
        v.vendor_code,
        u.full_name as requested_by_name,
        u.role as requester_role,
        'vendor_deletion' as category,
        'deletion' as request_type
    FROM vendor_deletion_requests vdr
    LEFT JOIN vendors v ON vdr.vendor_id = v.id
    LEFT JOIN users u ON vdr.requested_by = u.id 
    WHERE vdr.status = 'pending'
    ORDER BY vdr.requested_at DESC
");
$stmt->execute();
$vendor_deletion_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Combine all requests
foreach ($vehicle_requests as $request) {
    $pending_requests[] = $request;
}
foreach ($vendor_requests as $request) {
    $pending_requests[] = $request;
}
foreach ($vendor_bank_requests as $request) {
    $pending_requests[] = $request;
}
foreach ($vendor_deletion_requests as $request) {
    $pending_requests[] = $request;
}

// Sort all requests by creation date
usort($pending_requests, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Get statistics
$stats = [
    'total' => count($pending_requests),
    'vehicle' => count($vehicle_requests),
    'vendor' => count($vendor_requests),
    'vendor_bank' => count($vendor_bank_requests),
    'vendor_deletion' => count($vendor_deletion_requests)
];

// Display messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - Absuma Logistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'absuma-red': '#e53e3e',
                        'absuma-dark': '#c53030',
                        'teal': {
                            50: '#f0fdfa',
                            600: '#0d9488',
                            700: '#0f766e',
                            800: '#115e59',
                            900: '#134e4a',
                        }
                    },
                    boxShadow: {
                        'soft': '0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025)',
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        
        .card-hover-effect {
            transition: all 0.3s ease;
        }
        
        .card-hover-effect:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
            opacity: 0;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .category-vehicle { background-color: #dbeafe; color: #1e40af; }
        .category-vendor { background-color: #d1fae5; color: #065f46; }
        .category-vendor_bank { background-color: #fed7aa; color: #ea580c; }
        .category-vendor_deletion { background-color: #fecaca; color: #991b1b; }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
        <?php include '../sidebar_navigation.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Pending Approvals</h1>
                        <p class="text-sm text-gray-600 mt-1">
                            Review and approve requests from team members • Total: <?= $stats['total'] ?> pending
                        </p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <!-- Quick Stats -->
                        <div class="hidden md:flex items-center space-x-4 text-sm">
                            <div class="flex items-center text-blue-600">
                                <i class="fas fa-truck mr-1"></i>
                                <?= $stats['vehicle'] ?> Vehicles
                            </div>
                            <div class="flex items-center text-green-600">
                                <i class="fas fa-handshake mr-1"></i>
                                <?= $stats['vendor'] ?> Vendors
                            </div>
                            <div class="flex items-center text-orange-600">
                                <i class="fas fa-university mr-1"></i>
                                <?= $stats['vendor_bank'] ?> Bank Changes
                            </div>
                            <div class="flex items-center text-red-600">
                                <i class="fas fa-trash mr-1"></i>
                                <?= $stats['vendor_deletion'] ?> Deletions
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-7xl mx-auto">
                    <!-- Alert Messages -->
                    <?php if (!empty($success)): ?>
                        <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6 rounded-lg animate-fade-in">
                            <div class="flex items-center">
                                <i class="fas fa-check-circle text-green-400 mr-3"></i>
                                <p class="text-green-700"><?= htmlspecialchars($success) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-lg animate-fade-in">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-circle text-red-400 mr-3"></i>
                                <p class="text-red-700"><?= htmlspecialchars($error) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                        <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                                    <i class="fas fa-truck text-blue-600 text-xl"></i>
                                </div>
                                <div>
                                    <div class="text-2xl font-bold text-gray-900"><?= $stats['vehicle'] ?></div>
                                    <div class="text-sm text-gray-500">Vehicle Requests</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                                    <i class="fas fa-handshake text-green-600 text-xl"></i>
                                </div>
                                <div>
                                    <div class="text-2xl font-bold text-gray-900"><?= $stats['vendor'] ?></div>
                                    <div class="text-sm text-gray-500">Vendor Registrations</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center mr-4">
                                    <i class="fas fa-university text-orange-600 text-xl"></i>
                                </div>
                                <div>
                                    <div class="text-2xl font-bold text-gray-900"><?= $stats['vendor_bank'] ?></div>
                                    <div class="text-sm text-gray-500">Bank Changes</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-soft p-6 card-hover-effect animate-fade-in">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                                    <i class="fas fa-trash text-red-600 text-xl"></i>
                                </div>
                                <div>
                                    <div class="text-2xl font-bold text-gray-900"><?= $stats['vendor_deletion'] ?></div>
                                    <div class="text-sm text-gray-500">Deletion Requests</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Requests List -->
                    <?php if (empty($pending_requests)): ?>
                        <div class="bg-white rounded-xl shadow-soft p-12 text-center card-hover-effect">
                            <i class="fas fa-clipboard-check text-6xl text-gray-400 mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-700 mb-2">No Pending Approvals</h3>
                            <p class="text-gray-500">All requests have been processed. Great work!</p>
                        </div>
                    <?php else: ?>
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden card-hover-effect">
                            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
                                <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                    <i class="fas fa-clipboard-check text-teal-600"></i> 
                                    Pending Approval Requests (<?= count($pending_requests) ?>)
                                </h2>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request Details</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requested By</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($pending_requests as $request): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-10 w-10">
                                                            <?php 
                                                            $icon_class = '';
                                                            $bg_class = '';
                                                            switch($request['category']) {
                                                                case 'vehicle':
                                                                    $icon_class = 'fas fa-truck';
                                                                    $bg_class = 'bg-blue-100 text-blue-600';
                                                                    break;
                                                                case 'vendor':
                                                                    $icon_class = 'fas fa-handshake';
                                                                    $bg_class = 'bg-green-100 text-green-600';
                                                                    break;
                                                                case 'vendor_bank':
                                                                    $icon_class = 'fas fa-university';
                                                                    $bg_class = 'bg-orange-100 text-orange-600';
                                                                    break;
                                                                case 'vendor_deletion':
                                                                    $icon_class = 'fas fa-trash';
                                                                    $bg_class = 'bg-red-100 text-red-600';
                                                                    break;
                                                            }
                                                            ?>
                                                            <div class="h-10 w-10 rounded-full <?= $bg_class ?> flex items-center justify-center">
                                                                <i class="<?= $icon_class ?>"></i>
                                                            </div>
                                                        </div>
                                                        <div class="ml-4">
                                                            <?php if ($request['category'] === 'vehicle'): ?>
                                                                <div class="text-sm font-medium text-gray-900">
                                                                    <?= htmlspecialchars($request['vehicle_number'] ?: 'New Vehicle') ?>
                                                                </div>
                                                                <div class="text-sm text-gray-500">Vehicle Registration</div>
                                                            <?php elseif ($request['category'] === 'vendor'): ?>
                                                                <div class="text-sm font-medium text-gray-900">
                                                                    <?= htmlspecialchars($request['company_name']) ?>
                                                                </div>
                                                                <div class="text-sm text-gray-500">
                                                                    <?= htmlspecialchars($request['vendor_code']) ?> • <?= htmlspecialchars($request['vendor_type']) ?>
                                                                </div>
                                                            <?php elseif ($request['category'] === 'vendor_bank'): ?>
                                                                <div class="text-sm font-medium text-gray-900">
                                                                    <?= htmlspecialchars($request['company_name']) ?>
                                                                </div>
                                                                <div class="text-sm text-gray-500">
                                                                    Bank: <?= htmlspecialchars($request['bank_name']) ?> • <?= htmlspecialchars($request['account_number']) ?>
                                                                </div>
                                                            <?php elseif ($request['category'] === 'vendor_deletion'): ?>
                                                                <div class="text-sm font-medium text-gray-900">
                                                                    <?= htmlspecialchars($request['company_name']) ?>
                                                                </div>
                                                                <div class="text-sm text-gray-500">
                                                                    Deletion Request • <?= htmlspecialchars($request['vendor_code']) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="badge category-<?= $request['category'] ?>">
                                                        <?php 
                                                        switch($request['category']) {
                                                            case 'vehicle': echo 'Vehicle'; break;
                                                            case 'vendor': echo 'Vendor Registration'; break;
                                                            case 'vendor_bank': echo 'Bank Change'; break;
                                                            case 'vendor_deletion': echo 'Vendor Deletion'; break;
                                                        }
                                                        ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <div><?= htmlspecialchars($request['requested_by_name']) ?></div>
                                                    <div class="text-xs text-gray-500"><?= ucfirst($request['requester_role']) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= date('M j, Y', strtotime($request['created_at'])) ?>
                                                    <div class="text-xs text-gray-400"><?= date('g:i A', strtotime($request['created_at'])) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center space-x-2">
                                                        <button onclick="approveRequest(<?= $request['id'] ?>, '<?= $request['category'] ?>')" 
                                                                class="bg-green-100 hover:bg-green-200 text-green-800 px-3 py-1 rounded-md text-sm font-medium transition-colors">
                                                            <i class="fas fa-check mr-1"></i>Approve
                                                        </button>
                                                        <button onclick="showRejectModal(<?= $request['id'] ?>, '<?= $request['category'] ?>', '<?= htmlspecialchars($request['company_name'] ?? $request['vehicle_number'] ?? 'Request') ?>')" 
                                                                class="bg-red-100 hover:bg-red-200 text-red-800 px-3 py-1 rounded-md text-sm font-medium transition-colors">
                                                            <i class="fas fa-times mr-1"></i>Reject
                                                        </button>
                                                        <button onclick="showRequestDetails(<?= htmlspecialchars(json_encode($request)) ?>)" 
                                                                class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-3 py-1 rounded-md text-sm font-medium transition-colors">
                                                            <i class="fas fa-eye mr-1"></i>View
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-xl bg-white">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Reject Request</h3>
                    <button onclick="hideRejectModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="rejectForm" method="POST">
                    <input type="hidden" id="rejectRequestId" name="request_id">
                    <input type="hidden" id="rejectRequestType" name="request_type">
                    <input type="hidden" name="action" value="reject">
                    
                    <div class="mb-4">
                        <label for="rejection_reason" class="block text-sm font-medium text-gray-700 mb-2">
                            Reason for Rejection <span class="text-red-500">*</span>
                        </label>
                        <textarea id="rejection_reason" name="rejection_reason" rows="4" required 
                                  placeholder="Please provide a detailed reason for rejecting this request..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="hideRejectModal()" 
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-times mr-2"></i>Reject Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Request Details Modal -->
    <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-xl bg-white max-h-[90vh] overflow-y-auto">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Request Details</h3>
                    <button onclick="hideDetailsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="detailsContent">
                    <!-- Details will be populated here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        function approveRequest(requestId, requestType) {
            if (confirm('Are you sure you want to approve this request?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="request_id" value="${requestId}">
                    <input type="hidden" name="request_type" value="${requestType}">
                    <input type="hidden" name="action" value="approve">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showRejectModal(requestId, requestType, requestName) {
            document.getElementById('rejectRequestId').value = requestId;
            document.getElementById('rejectRequestType').value = requestType;
            document.getElementById('rejectModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function hideRejectModal() {
            document.getElementById('rejectModal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            document.getElementById('rejectForm').reset();
        }

        function showRequestDetails(requestData) {
            const request = requestData;
            let detailsHtml = '';
            
            if (request.category === 'vehicle') {
                const proposedData = request.proposed_data ? JSON.parse(request.proposed_data) : {};
                detailsHtml = `
                    <div class="space-y-4">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h4 class="font-medium text-blue-800">Vehicle Details</h4>
                            <div class="mt-2 text-sm">
                                <p><strong>Vehicle Number:</strong> ${proposedData.vehicle_number || 'N/A'}</p>
                                <p><strong>Driver:</strong> ${proposedData.driver_name || 'N/A'}</p>
                                <p><strong>Owner:</strong> ${proposedData.owner_name || 'N/A'}</p>
                                <p><strong>Make/Model:</strong> ${proposedData.make_model || 'N/A'}</p>
                                <p><strong>GVW:</strong> ${proposedData.gvw || 'N/A'} kg</p>
                            </div>
                        </div>
                        ${request.reason ? `<div class="bg-gray-50 p-4 rounded-lg">
                            <h4 class="font-medium text-gray-800">Reason</h4>
                            <p class="mt-2 text-sm text-gray-600">${request.reason}</p>
                        </div>` : ''}
                    </div>
                `;
            } else if (request.category === 'vendor') {
                detailsHtml = `
                    <div class="space-y-4">
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h4 class="font-medium text-green-800">Vendor Registration</h4>
                            <div class="mt-2 text-sm">
                                <p><strong>Company:</strong> ${request.company_name || 'N/A'}</p>
                                <p><strong>Vendor Code:</strong> ${request.vendor_code || 'N/A'}</p>
                                <p><strong>Contact Person:</strong> ${request.contact_person || 'N/A'}</p>
                                <p><strong>Type:</strong> ${request.vendor_type || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                `;
            } else if (request.category === 'vendor_bank') {
                detailsHtml = `
                    <div class="space-y-4">
                        <div class="bg-orange-50 p-4 rounded-lg">
                            <h4 class="font-medium text-orange-800">Bank Details Change</h4>
                            <div class="mt-2 text-sm">
                                <p><strong>Vendor:</strong> ${request.company_name || 'N/A'}</p>
                                <p><strong>Bank Name:</strong> ${request.bank_name || 'N/A'}</p>
                                <p><strong>Account Number:</strong> ${request.account_number || 'N/A'}</p>
                                <p><strong>Branch:</strong> ${request.branch_name || 'N/A'}</p>
                                <p><strong>IFSC Code:</strong> ${request.ifsc_code || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                `;
            } else if (request.category === 'vendor_deletion') {
                detailsHtml = `
                    <div class="space-y-4">
                        <div class="bg-red-50 p-4 rounded-lg">
                            <h4 class="font-medium text-red-800">Vendor Deletion Request</h4>
                            <div class="mt-2 text-sm">
                                <p><strong>Vendor:</strong> ${request.company_name || 'N/A'}</p>
                                <p><strong>Vendor Code:</strong> ${request.vendor_code || 'N/A'}</p>
                                ${request.reason ? `<p><strong>Reason:</strong> ${request.reason}</p>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }
            
            detailsHtml += `
                <div class="bg-gray-50 p-4 rounded-lg mt-4">
                    <h4 class="font-medium text-gray-800">Request Information</h4>
                    <div class="mt-2 text-sm">
                        <p><strong>Requested By:</strong> ${request.requested_by_name} (${request.requester_role})</p>
                        <p><strong>Date:</strong> ${new Date(request.created_at).toLocaleString()}</p>
                    </div>
                </div>
            `;
            
            document.getElementById('detailsContent').innerHTML = detailsHtml;
            document.getElementById('detailsModal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function hideDetailsModal() {
            document.getElementById('detailsModal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        // Initialize functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add fade-in animation delays
            const elements = document.querySelectorAll('.animate-fade-in');
            elements.forEach((el, index) => {
                el.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>