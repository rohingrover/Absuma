<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Role-based access control
$user_role = $_SESSION['role'];
$allowed_roles = ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'];

if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to access vehicle management.";
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

// Function to check if user has permission for a specific role level
//function hasRoleAccess($required_role, $user_role, $role_hierarchy) {
//    return $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
//}

// Check if user can approve changes
function canApprove($role) {
   return in_array($role, ['manager1', 'manager2', 'admin', 'superadmin']);
}

// Handle approval/rejection of vehicle changes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && canApprove($user_role)) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'approve') {
            // Get the change request
            $stmt = $pdo->prepare("SELECT * FROM vehicle_change_requests WHERE id = ? AND status = 'pending'");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            if ($request) {
                $proposed_data = json_decode($request['proposed_data'], true);
                
                if ($request['request_type'] === 'create') {
                    // Separate vehicle data from financing data
                    $vehicle_columns = ['vehicle_number', 'driver_name', 'owner_name', 'make_model', 'manufacturing_year', 'gvw', 'is_financed'];
                    $financing_columns = ['bank_name', 'loan_amount', 'interest_rate', 'loan_tenure_months', 'emi_amount', 'loan_start_date', 'loan_end_date'];
                    
                    $vehicle_data = array_intersect_key($proposed_data, array_flip($vehicle_columns));
                    $financing_data = array_intersect_key($proposed_data, array_flip($financing_columns));
                    
                    // Insert vehicle
                    $columns = implode(', ', array_keys($vehicle_data));
                    $placeholders = ':' . implode(', :', array_keys($vehicle_data));
                    $sql = "INSERT INTO vehicles ($columns, approval_status, last_approved_by, last_approved_at) VALUES ($placeholders, 'approved', :approved_by, NOW())";
                    $stmt = $pdo->prepare($sql);
                    $vehicle_data['approved_by'] = $_SESSION['user_id'];
                    $stmt->execute($vehicle_data);
                    
                    $vehicle_id = $pdo->lastInsertId();
                    
                    // Insert financing data if vehicle is financed
                    if ($vehicle_data['is_financed'] == 1 && !empty(array_filter($financing_data))) {
                        $financing_data['vehicle_id'] = $vehicle_id;
                        $financing_data['is_financed'] = 1;
                        $financing_columns_str = implode(', ', array_keys($financing_data));
                        $financing_placeholders = ':' . implode(', :', array_keys($financing_data));
                        $financing_sql = "INSERT INTO vehicle_financing ($financing_columns_str) VALUES ($financing_placeholders)";
                        $stmt = $pdo->prepare($financing_sql);
                        $stmt->execute($financing_data);
                    }
                    
                } elseif ($request['request_type'] === 'update') {
                    // Update existing vehicle
                    $set_clause = [];
                    foreach ($proposed_data as $key => $value) {
                        $set_clause[] = "$key = :$key";
                    }
                    $sql = "UPDATE vehicles SET " . implode(', ', $set_clause) . ", approval_status = 'approved', last_approved_by = :approved_by, last_approved_at = NOW() WHERE id = :vehicle_id";
                    $stmt = $pdo->prepare($sql);
                    $proposed_data['approved_by'] = $_SESSION['user_id'];
                    $proposed_data['vehicle_id'] = $request['vehicle_id'];
                    $stmt->execute($proposed_data);
                }
                
                // Update request status
                $stmt = $pdo->prepare("UPDATE vehicle_change_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $request_id]);
                
                // Create notification for requester
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, related_table, related_id, created_at) 
                    VALUES (?, 'Request Approved', 'Your vehicle change request has been approved', 'success', 'vehicle_change_requests', ?, NOW())
                ");
                $stmt->execute([$request['requested_by'], $request_id]);
                
                $_SESSION['success'] = "Vehicle change request approved successfully!";
            }
        } elseif ($action === 'reject') {
            $rejection_reason = $_POST['rejection_reason'] ?? 'No reason provided';
            $stmt = $pdo->prepare("UPDATE vehicle_change_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $rejection_reason, $request_id]);
            
            // Get request details for notification
            $stmt = $pdo->prepare("SELECT requested_by FROM vehicle_change_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            if ($request) {
                // Create notification for requester
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, related_table, related_id, created_at) 
                    VALUES (?, 'Request Rejected', ?, 'error', 'vehicle_change_requests', ?, NOW())
                ");
                $stmt->execute([$request['requested_by'], "Your vehicle change request was rejected. Reason: $rejection_reason", $request_id]);
            }
            
            $_SESSION['success'] = "Vehicle change request rejected.";
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error processing request: " . $e->getMessage();
    }
    
    header("Location: manage.php");
    exit();
}

// Handle delete action (only for managers and above)
if (isset($_GET['delete']) && canApprove($user_role)) {
    $id = $_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // Get vehicle info before deletion
        $stmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
        $stmt->execute([$id]);
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            // Log the deletion in audit_logs
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, ip_address, user_agent, created_at) 
                VALUES (?, 'DELETE', 'vehicles', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user_id'], 
                $id, 
                json_encode($vehicle), 
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            // Delete associated records
            $pdo->prepare("DELETE FROM drivers WHERE vehicle_number = ?")->execute([$vehicle['vehicle_number']]);
            $pdo->prepare("DELETE FROM document_alerts WHERE vehicle_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM vehicle_financing WHERE vehicle_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM vehicle_documents WHERE vehicle_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM vehicle_change_requests WHERE vehicle_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM vehicles WHERE id = ?")->execute([$id]);
            
            $pdo->commit();
            $_SESSION['success'] = "Vehicle '{$vehicle['vehicle_number']}' deleted successfully";
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Vehicle not found";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: manage.php");
    exit();
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_financing = $_GET['financing'] ?? '';
$filter_year = $_GET['year'] ?? '';
$search_term = $_GET['search'] ?? '';

// Build query with filters
$query = "
    SELECT 
        v.*, 
        vf.is_financed,
        vf.bank_name,
        vf.emi_amount,
        u1.full_name as last_modified_by_name,
        u2.full_name as last_approved_by_name
    FROM vehicles v
    LEFT JOIN vehicle_financing vf ON v.id = vf.vehicle_id
    LEFT JOIN users u1 ON v.last_modified_by = u1.id
    LEFT JOIN users u2 ON v.last_approved_by = u2.id
    WHERE 1=1
";

$params = [];

if ($filter_status) {
    $query .= " AND v.current_status = ?";
    $params[] = $filter_status;
}

if ($filter_financing) {
    if ($filter_financing === 'financed') {
        $query .= " AND v.is_financed = 1";
    } elseif ($filter_financing === 'non-financed') {
        $query .= " AND v.is_financed = 0";
    }
}

if ($filter_year) {
    $query .= " AND v.manufacturing_year = ?";
    $params[] = $filter_year;
}

if ($search_term) {
    $query .= " AND (v.vehicle_number LIKE ? OR v.driver_name LIKE ? OR v.owner_name LIKE ? OR v.make_model LIKE ?)";
    $searchParam = "%$search_term%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " ORDER BY v.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending requests for current user (if they are l2_supervisor)
$pending_requests = [];
if ($user_role === 'l2_supervisor') {
    $stmt = $pdo->prepare("
        SELECT vcr.*, v.vehicle_number 
        FROM vehicle_change_requests vcr 
        LEFT JOIN vehicles v ON vcr.vehicle_id = v.id 
        WHERE vcr.requested_by = ? AND vcr.status = 'pending' 
        ORDER BY vcr.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all pending requests for managers
$all_pending_requests = [];
if (canApprove($user_role)) {
    $stmt = $pdo->prepare("
        SELECT vcr.*, v.vehicle_number, u.full_name as requested_by_name 
        FROM vehicle_change_requests vcr 
        LEFT JOIN vehicles v ON vcr.vehicle_id = v.id 
        LEFT JOIN users u ON vcr.requested_by = u.id 
        WHERE vcr.status = 'pending' 
        ORDER BY vcr.created_at DESC
    ");
    $stmt->execute();
    $all_pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get unique years for filter dropdown
$stmt = $pdo->prepare("SELECT DISTINCT manufacturing_year FROM vehicles WHERE manufacturing_year IS NOT NULL ORDER BY manufacturing_year DESC");
$stmt->execute();
$available_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get vehicle statistics
$stats = [];
$stats['total'] = count($vehicles);
$stats['available'] = count(array_filter($vehicles, fn($v) => $v['current_status'] === 'available'));
$stats['on_trip'] = count(array_filter($vehicles, fn($v) => in_array($v['current_status'], ['on_trip', 'loaded'])));
$stats['maintenance'] = count(array_filter($vehicles, fn($v) => $v['current_status'] === 'maintenance'));
$stats['financed'] = count(array_filter($vehicles, fn($v) => $v['is_financed'] == 1));

// Display messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);



$current_page = basename($_SERVER['PHP_SELF']);
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_action'])) {
    $vehicle_id = $_POST['vehicle_id'];
    $edit_action = $_POST['edit_action'];
    
    if ($edit_action === 'submit_edit') {
        // Get form data
        $editData = [
            'vehicle_number' => trim($_POST['edit_vehicle_number']),
            'driver_name' => trim($_POST['edit_driver_name']),
            'owner_name' => trim($_POST['edit_owner_name']),
            'make_model' => trim($_POST['edit_make_model']),
            'manufacturing_year' => $_POST['edit_manufacturing_year'] ?? '',
            'gvw' => trim($_POST['edit_gvw']),
            'is_financed' => isset($_POST['edit_is_financed']) ? 1 : 0,
            'bank_name' => trim($_POST['edit_bank_name'] ?? ''),
            'emi_amount' => $_POST['edit_emi_amount'] ?? '',
            'loan_amount' => $_POST['edit_loan_amount'] ?? '',
            'interest_rate' => $_POST['edit_interest_rate'] ?? '',
            'loan_tenure_months' => $_POST['edit_loan_tenure_months'] ?? '',
            'loan_start_date' => $_POST['edit_loan_start_date'] ?? '',
            'loan_end_date' => $_POST['edit_loan_end_date'] ?? ''
        ];

        try {
            $pdo->beginTransaction();
            
            // Get current vehicle data for comparison
            $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            $currentVehicle = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentVehicle) {
                throw new Exception("Vehicle not found");
            }
            
            // Determine if approval is needed
            $needsApproval = ($user_role === 'l2_supervisor');
            
            if ($needsApproval) {
                // Create change request for approval
                $currentData = [
                    'vehicle_number' => $currentVehicle['vehicle_number'],
                    'driver_name' => $currentVehicle['driver_name'],
                    'owner_name' => $currentVehicle['owner_name'],
                    'make_model' => $currentVehicle['make_model'],
                    'manufacturing_year' => $currentVehicle['manufacturing_year'],
                    'gvw' => $currentVehicle['gvw'],
                    'is_financed' => $currentVehicle['is_financed']
                ];
                
                $requestStmt = $pdo->prepare("
                    INSERT INTO vehicle_change_requests 
                    (vehicle_id, request_type, current_data, proposed_data, requested_by, reason, status, created_at) 
                    VALUES (?, 'update', ?, ?, ?, 'Vehicle information update', 'pending', NOW())
                ");
                $requestStmt->execute([
                    $vehicle_id,
                    json_encode($currentData),
                    json_encode($editData),
                    $_SESSION['user_id']
                ]);
                
                $success = "Vehicle edit request submitted for approval. A manager will review your changes shortly.";
                
                // Create notification for managers
                $managerStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('manager1', 'manager2', 'admin', 'superadmin') AND status = 'active'");
                $managerStmt->execute();
                $managers = $managerStmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($managers as $managerId) {
                    $notificationStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, title, message, type, related_table, related_id, created_at) 
                        VALUES (?, 'Vehicle Edit Request', ?, 'info', 'vehicle_change_requests', ?, NOW())
                    ");
                    $notificationStmt->execute([
                        $managerId,
                        "Vehicle edit request for {$currentVehicle['vehicle_number']} by " . $_SESSION['full_name'],
                        $pdo->lastInsertId()
                    ]);
                }
                
            } else {
                // Direct update for managers and above
                $updateStmt = $pdo->prepare("
                    UPDATE vehicles SET 
                    vehicle_number = ?, driver_name = ?, owner_name = ?, make_model = ?, 
                    manufacturing_year = ?, gvw = ?, is_financed = ?, 
                    last_modified_by = ?, last_modified_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $editData['vehicle_number'],
                    $editData['driver_name'],
                    $editData['owner_name'],
                    $editData['make_model'],
                    !empty($editData['manufacturing_year']) ? $editData['manufacturing_year'] : null,
                    !empty($editData['gvw']) ? $editData['gvw'] : null,
                    $editData['is_financed'],
                    $_SESSION['user_id'],
                    $vehicle_id
                ]);
                
                // Update financing details
                $financingUpdateStmt = $pdo->prepare("
                    UPDATE vehicle_financing SET 
                    is_financed = ?, bank_name = ?, loan_amount = ?, interest_rate = ?, 
                    loan_tenure_months = ?, emi_amount = ?, loan_start_date = ?, loan_end_date = ?
                    WHERE vehicle_id = ?
                ");
                $financingUpdateStmt->execute([
                    $editData['is_financed'],
                    $editData['is_financed'] ? ($editData['bank_name'] ?: null) : null,
                    $editData['is_financed'] && !empty($editData['loan_amount']) ? $editData['loan_amount'] : null,
                    $editData['is_financed'] && !empty($editData['interest_rate']) ? $editData['interest_rate'] : null,
                    $editData['is_financed'] && !empty($editData['loan_tenure_months']) ? $editData['loan_tenure_months'] : null,
                    $editData['is_financed'] ? ($editData['emi_amount'] ?: null) : null,
                    $editData['is_financed'] && !empty($editData['loan_start_date']) ? $editData['loan_start_date'] : null,
                    $editData['is_financed'] && !empty($editData['loan_end_date']) ? $editData['loan_end_date'] : null,
                    $vehicle_id
                ]);
                
                // Update driver information
                $driverUpdateStmt = $pdo->prepare("
                    UPDATE drivers SET name = ? WHERE vehicle_number = ?
                ");
                $driverUpdateStmt->execute([
                    $editData['driver_name'],
                    $currentVehicle['vehicle_number']
                ]);
                
                $success = "Vehicle information updated successfully!";
            }
            
            $pdo->commit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error updating vehicle: " . $e->getMessage();
        }
        
        header("Location: manage.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Vehicles - Fleet Management</title>
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
        
        .nav-active {
            background: white;
            color: #0d9488;
        }
        
        .nav-item {
            transition: all 0.2s ease;
        }
        
        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .status-badge {
            @apply px-2 py-1 text-xs font-medium rounded-full;
        }
        
        .status-available { @apply bg-green-100 text-green-800; }
        .status-on_trip { @apply bg-blue-100 text-blue-800; }
        .status-loaded { @apply bg-yellow-100 text-yellow-800; }
        .status-maintenance { @apply bg-red-100 text-red-800; }
        .status-pending_approval { @apply bg-orange-100 text-orange-800; }
        .status-approved { @apply bg-green-100 text-green-800; }
        .status-rejected { @apply bg-red-100 text-red-800; }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
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

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .shadow-soft { box-shadow: none !important; }
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen flex">
        <!-- Sidebar Navigation -->
        <?php include '../sidebar_navigation.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4 no-print">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Manage Vehicles</h1>
                        <p class="text-sm text-gray-600 mt-1">
                            <?php if ($user_role === 'l2_supervisor'): ?>
                                Vehicle changes require manager approval • Total: <?= $stats['total'] ?> vehicles
                            <?php else: ?>
                                Complete vehicle management system • Total: <?= $stats['total'] ?> vehicles
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <!-- Quick Stats -->
                        <div class="hidden md:flex items-center space-x-4 text-sm">
                            <div class="flex items-center text-green-600">
                                <i class="fas fa-circle mr-1"></i>
                                <?= $stats['available'] ?> Available
                            </div>
                            <div class="flex items-center text-blue-600">
                                <i class="fas fa-circle mr-1"></i>
                                <?= $stats['on_trip'] ?> On Trip
                            </div>
                            <div class="flex items-center text-red-600">
                                <i class="fas fa-circle mr-1"></i>
                                <?= $stats['maintenance'] ?> Maintenance
                            </div>
                        </div>
                        
                        <!-- Search -->
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input
                                type="text"
                                placeholder="Search vehicles..."
                                class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm w-64"
                                id="searchInput"
                                value="<?= htmlspecialchars($search_term) ?>"
                            />
                        </div>
                        
                        <?php if (hasRoleAccess('l2_supervisor', $user_role, $role_hierarchy)): ?>
                        
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <!-- Messages -->
            <?php if ($success): ?>
                <div class="mx-6 mt-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg animate-fade-in no-print">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mx-6 mt-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg animate-fade-in no-print">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                
                <!-- My Pending Requests (for L2_Supervisor) -->
                <?php if ($user_role === 'l2_supervisor' && !empty($pending_requests)): ?>
                <div class="bg-orange-50 rounded-lg shadow-soft p-6 mb-6 card-hover-effect animate-fade-in no-print">
                    <h2 class="text-lg font-semibold text-orange-800 mb-4 flex items-center">
                        <i class="fas fa-clock text-orange-600 mr-2"></i>
                        My Pending Requests (<?= count($pending_requests) ?>)
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($pending_requests as $request): ?>
                        <div class="bg-white p-4 rounded-lg border border-orange-200">
                            <div class="flex items-center justify-between mb-2">
                                <span class="status-badge status-pending_approval">
                                    <?= ucfirst(str_replace('_', ' ', $request['request_type'])) ?>
                                </span>
                                <span class="text-xs text-gray-500">
                                    <?= date('M d, H:i', strtotime($request['created_at'])) ?>
                                </span>
                            </div>
                            <div class="text-sm font-medium text-gray-900 mb-1">
                                <?= htmlspecialchars($request['vehicle_number'] ?? 'New Vehicle') ?>
                            </div>
                            <?php if ($request['reason']): ?>
                            <div class="text-xs text-gray-600">
                                Reason: <?= htmlspecialchars($request['reason']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pending Approvals Section (for managers) -->
                <?php if (canApprove($user_role) && !empty($all_pending_requests)): ?>
                <div class="bg-white rounded-lg shadow-soft p-6 mb-6 card-hover-effect animate-fade-in no-print">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-clipboard-check text-teal-600 mr-2"></i>
                        Pending Approvals (<?= count($all_pending_requests) ?>)
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requested By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($all_pending_requests as $request): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <span class="status-badge status-pending_approval">
                                                <?= ucfirst(str_replace('_', ' ', $request['request_type'])) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($request['vehicle_number'] ?? 'New Vehicle') ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($request['requested_by_name']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-500"><?= date('M d, Y H:i', strtotime($request['created_at'])) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-xs transition-colors">
                                                    <i class="fas fa-check mr-1"></i>Approve
                                                </button>
                                            </form>
                                            <button onclick="openRejectModal(<?= $request['id'] ?>)" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-xs transition-colors">
                                                <i class="fas fa-times mr-1"></i>Reject
                                            </button>
                                            <button onclick="viewRequestDetails(<?= $request['id'] ?>)" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs transition-colors">
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

                <!-- Filters and Search -->
                <div class="bg-white rounded-lg shadow-soft p-6 mb-6 card-hover-effect animate-fade-in no-print">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex flex-wrap items-center gap-4">
                            <!-- Status Filter -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="statusFilter" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">All Status</option>
                                    <option value="available" <?= $filter_status === 'available' ? 'selected' : '' ?>>Available</option>
                                    <option value="on_trip" <?= $filter_status === 'on_trip' ? 'selected' : '' ?>>On Trip</option>
                                    <option value="loaded" <?= $filter_status === 'loaded' ? 'selected' : '' ?>>Loaded</option>
                                    <option value="maintenance" <?= $filter_status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                </select>
                            </div>

                            <!-- Financing Filter -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Financing</label>
                                <select id="financingFilter" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">All Types</option>
                                    <option value="financed" <?= $filter_financing === 'financed' ? 'selected' : '' ?>>Financed</option>
                                    <option value="non-financed" <?= $filter_financing === 'non-financed' ? 'selected' : '' ?>>Non-Financed</option>
                                </select>
                            </div>

                            <!-- Year Filter -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                                <select id="yearFilter" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">All Years</option>
                                    <?php foreach ($available_years as $year): ?>
                                        <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Clear Filters -->
                            <div class="flex items-end">
                                <button id="clearFilters" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </button>
                            </div>
                        </div>

                        <!-- Export Options -->
                        <div class="flex items-center gap-2">
                            <button onclick="exportToCSV()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                                <i class="fas fa-file-csv mr-2"></i>Export CSV
                            </button>
                            <button onclick="printTable()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                                <i class="fas fa-print mr-2"></i>Print
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Vehicles Table -->
                <div class="bg-white rounded-lg shadow-soft overflow-hidden card-hover-effect animate-fade-in">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-teal-50 to-white">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-truck text-teal-600 mr-2"></i>
                            Fleet Vehicles (<?= count($vehicles) ?>)
                            <?php if ($search_term || $filter_status || $filter_financing || $filter_year): ?>
                                <span class="ml-2 text-sm font-normal text-gray-600">- Filtered Results</span>
                            <?php endif; ?>
                        </h2>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200" id="vehiclesTable">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Driver & Owner</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Financing</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Approval Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($vehicles as $vehicle): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center mr-3">
                                                <i class="fas fa-truck text-teal-600"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($vehicle['vehicle_number']) ?></div>
                                                <?php if ($vehicle['make_model']): ?>
                                                    <div class="text-sm text-gray-500"><?= htmlspecialchars($vehicle['make_model']) ?></div>
                                                <?php endif; ?>
                                                <div class="flex items-center space-x-2 mt-1">
                                                    <?php if ($vehicle['manufacturing_year']): ?>
                                                        <div class="text-xs text-gray-400">Year: <?= $vehicle['manufacturing_year'] ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($vehicle['gvw']): ?>
                                                        <div class="text-xs text-gray-400">• GVW: <?= $vehicle['gvw'] ?>T</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <div class="font-medium flex items-center mb-1">
                                                <i class="fas fa-user text-gray-400 mr-1 text-xs"></i>
                                                <span><?= htmlspecialchars($vehicle['driver_name'] ?: 'No Driver Assigned') ?></span>
                                            </div>
                                            <div class="text-gray-500 flex items-center">
                                                <i class="fas fa-id-card text-gray-400 mr-1 text-xs"></i>
                                                <span><?= htmlspecialchars($vehicle['owner_name'] ?: 'No Owner Info') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="status-badge status-<?= $vehicle['current_status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $vehicle['current_status'])) ?>
                                        </span>
                                        <?php if ($vehicle['current_status'] === 'maintenance'): ?>
                                            <div class="text-xs text-red-500 mt-1">
                                                <i class="fas fa-wrench mr-1"></i>Service Required
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($vehicle['is_financed']): ?>
                                            <div class="text-sm">
                                                <div class="text-orange-600 font-medium flex items-center">
                                                    <i class="fas fa-university mr-1"></i>Financed
                                                </div>
                                                <?php if ($vehicle['bank_name']): ?>
                                                    <div class="text-gray-500 text-xs mt-1"><?= htmlspecialchars($vehicle['bank_name']) ?></div>
                                                <?php endif; ?>
                                                <?php if ($vehicle['emi_amount']): ?>
                                                    <div class="text-gray-500 text-xs">EMI: ₹<?= number_format($vehicle['emi_amount']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-green-600 font-medium flex items-center">
                                                <i class="fas fa-check-circle mr-1"></i>No
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="status-badge status-<?= $vehicle['approval_status'] ?? 'approved' ?>">
                                            <?= ucfirst(str_replace('_', ' ', $vehicle['approval_status'] ?? 'approved')) ?>
                                        </span>
                                        <?php if ($vehicle['last_approved_by_name']): ?>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-user-check mr-1"></i>
                                                By: <?= htmlspecialchars($vehicle['last_approved_by_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($vehicle['last_approved_at']): ?>
                                            <div class="text-xs text-gray-400">
                                                <?= date('M d, Y', strtotime($vehicle['last_approved_at'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium no-print">
                                        <div class="flex items-center space-x-3">
                                            <!-- View Button -->
                                            <button onclick="viewVehicleDetails(<?= $vehicle['id'] ?>)" class="text-blue-600 hover:text-blue-900 transition-colors" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- Edit Button -->
                                            <?php if (hasRoleAccess('l2_supervisor', $user_role, $role_hierarchy)): ?>
                                            <button onclick="openEditModal(<?= $vehicle['id'] ?>)" class="text-teal-600 hover:text-teal-900 transition-colors" title="Edit Vehicle">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            
                                            <!-- Documents Button -->
                                            <button onclick="viewDocuments(<?= $vehicle['id'] ?>)" class="text-purple-600 hover:text-purple-900 transition-colors" title="View Documents">
                                                <i class="fas fa-file-alt"></i>
                                            </button>
                                            
                                            <!-- Delete Button (Only for Managers and above) -->
                                            <?php if (canApprove($user_role)): ?>
                                            <button onclick="confirmDelete(<?= $vehicle['id'] ?>, '<?= htmlspecialchars($vehicle['vehicle_number']) ?>')" class="text-red-600 hover:text-red-900 transition-colors" title="Delete Vehicle">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (empty($vehicles)): ?>
                    <div class="text-center py-12">
                        <div class="w-24 h-24 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-truck text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">
                            <?php if ($search_term || $filter_status || $filter_financing || $filter_year): ?>
                                No vehicles match your filters
                            <?php else: ?>
                                No vehicles found
                            <?php endif; ?>
                        </h3>
                        <p class="text-gray-500 mb-4">
                            <?php if ($search_term || $filter_status || $filter_financing || $filter_year): ?>
                                Try adjusting your search criteria or clear all filters.
                            <?php else: ?>
                                Get started by adding your first vehicle to the fleet.
                            <?php endif; ?>
                        </p>
                        <?php if (hasRoleAccess('l2_supervisor', $user_role, $role_hierarchy)): ?>
                        <a href="add_vehicle.php" class="inline-flex items-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Add Vehicle
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Statistics Summary -->
                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 no-print">
                    <div class="bg-white rounded-lg shadow-soft p-4 card-hover-effect">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-check-circle text-green-600"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900"><?= $stats['available'] ?></div>
                                <div class="text-sm text-gray-500">Available</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-soft p-4 card-hover-effect">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-truck-moving text-blue-600"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900"><?= $stats['on_trip'] ?></div>
                                <div class="text-sm text-gray-500">On Trip</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-soft p-4 card-hover-effect">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-wrench text-red-600"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900"><?= $stats['maintenance'] ?></div>
                                <div class="text-sm text-gray-500">Maintenance</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-soft p-4 card-hover-effect">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-university text-orange-600"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900"><?= $stats['financed'] ?></div>
                                <div class="text-sm text-gray-500">Financed</div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Reject Request</h3>
                    <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="rejectForm" method="POST">
                    <input type="hidden" name="request_id" id="rejectRequestId">
                    <input type="hidden" name="action" value="reject">
                    <div class="mb-4">
                        <label for="rejection_reason" class="block text-sm font-medium text-gray-700 mb-2">Reason for rejection:</label>
                        <textarea name="rejection_reason" id="rejection_reason" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="Please provide a detailed reason for rejection..." required></textarea>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeRejectModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            <i class="fas fa-times mr-2"></i>Reject Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Vehicle Details Modal -->
    <div id="vehicleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-4/5 max-w-4xl shadow-lg rounded-lg bg-white">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">Vehicle Details</h3>
                <button onclick="closeVehicleModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="vehicleDetailsContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>
	<div id="editVehicleModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-5 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-lg bg-white">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900 flex items-center">
                <i class="fas fa-edit text-teal-600 mr-2"></i>
                Edit Vehicle Information
                <?php if ($user_role === 'l2_supervisor'): ?>
                    <span class="ml-2 text-sm bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full">Requires Approval</span>
                <?php endif; ?>
            </h3>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form id="editVehicleForm" method="POST">
            <input type="hidden" name="edit_action" value="submit_edit">
            <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-96 overflow-y-auto">
                <!-- Vehicle Number -->
                <div class="form-field">
                    <label for="edit_vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_vehicle_number" name="edit_vehicle_number" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm"
                           required>
                </div>
                
                <!-- Driver Name -->
                <div class="form-field">
                    <label for="edit_driver_name" class="block text-sm font-medium text-gray-700">Driver Name <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_driver_name" name="edit_driver_name" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm"
                           required>
                </div>
                
                <!-- Owner Name -->
                <div class="form-field">
                    <label for="edit_owner_name" class="block text-sm font-medium text-gray-700">Owner Name <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_owner_name" name="edit_owner_name" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm"
                           required>
                </div>
                
                <!-- Make/Model -->
                <div class="form-field">
                    <label for="edit_make_model" class="block text-sm font-medium text-gray-700">Make/Model</label>
                    <input type="text" id="edit_make_model" name="edit_make_model" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                </div>
                
                <!-- Manufacturing Year -->
                <div class="form-field">
                    <label for="edit_manufacturing_year" class="block text-sm font-medium text-gray-700">Manufacturing Year</label>
                    <select id="edit_manufacturing_year" name="edit_manufacturing_year" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                        <option value="">Select Year</option>
                        <?php
                        $currentYear = date('Y');
                        for ($year = $currentYear; $year >= 1990; $year--): ?>
                            <option value="<?= $year ?>"><?= $year ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <!-- GVW -->
                <div class="form-field">
                    <label for="edit_gvw" class="block text-sm font-medium text-gray-700">GVW (kg)</label>
                    <input type="number" id="edit_gvw" name="edit_gvw" step="0.01" min="0"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                </div>
                
                <!-- Financed Vehicle -->
                <div class="form-field md:col-span-2">
                    <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                        <input type="checkbox" id="edit_is_financed" name="edit_is_financed" value="1" 
                               class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300 rounded">
                        <label for="edit_is_financed" class="text-sm font-medium text-gray-700">
                            <i class="fas fa-university mr-2 text-teal-600"></i>
                            This vehicle is financed
                        </label>
                    </div>
                </div>
                
                <!-- Financing Fields -->
                <div class="form-field md:col-span-2 hidden" id="editFinancingFields">
                    <div class="space-y-3 bg-gradient-to-r from-teal-50 to-blue-50 p-4 rounded-lg border border-teal-200">
                        <h4 class="text-sm font-medium text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-credit-card text-teal-600 mr-2"></i>
                            Financing Details
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <!-- Bank Name -->
                            <div class="form-field">
                                <label for="edit_bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label>
                                <input type="text" id="edit_bank_name" name="edit_bank_name" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                            </div>
                            <!-- EMI Amount -->
                            <div class="form-field">
                                <label for="edit_emi_amount" class="block text-sm font-medium text-gray-700">EMI Amount (₹)</label>
                                <input type="number" id="edit_emi_amount" name="edit_emi_amount" step="0.01" min="0" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                            </div>
                            <!-- Loan Amount -->
                            <div class="form-field">
                                <label for="edit_loan_amount" class="block text-sm font-medium text-gray-700">Loan Amount (₹)</label>
                                <input type="number" id="edit_loan_amount" name="edit_loan_amount" step="0.01" min="0" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                            </div>
                            <!-- Interest Rate -->
                            <div class="form-field">
                                <label for="edit_interest_rate" class="block text-sm font-medium text-gray-700">Interest Rate (%)</label>
                                <input type="number" id="edit_interest_rate" name="edit_interest_rate" step="0.01" min="0" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                            </div>
                            <!-- Loan Tenure -->
                            <div class="form-field">
                                <label for="edit_loan_tenure_months" class="block text-sm font-medium text-gray-700">Loan Tenure (Months)</label>
                                <input type="number" id="edit_loan_tenure_months" name="edit_loan_tenure_months" min="0" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                            </div>
                            <!-- Loan Start Date -->
                            <div class="form-field">
                                <label for="edit_loan_start_date" class="block text-sm font-medium text-gray-700">Loan Start Date</label>
                                <input type="date" id="edit_loan_start_date" name="edit_loan_start_date" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                            </div>
                            <!-- Loan End Date -->
                            <div class="form-field">
                                <label for="edit_loan_end_date" class="block text-sm font-medium text-gray-700">Loan End Date</label>
                                <input type="date" id="edit_loan_end_date" name="edit_loan_end_date" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($user_role === 'l2_supervisor'): ?>
            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-600"></i>
                    </div>
                    <div class="ml-3">
                        <h4 class="text-sm font-medium text-blue-800">Approval Required</h4>
                        <p class="text-sm text-blue-700 mt-1">
                            As an L2 Supervisor, your changes will be sent for manager approval before being applied to the vehicle record.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Form Actions -->
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors">
                    <i class="fas fa-save mr-2"></i>
                    <?php if ($user_role === 'l2_supervisor'): ?>
                        Submit for Approval
                    <?php else: ?>
                        Save Changes
                    <?php endif; ?>
                </button>
            </div>
        </form>
    </div>
</div>
	
<script>
        // Vehicle Management JavaScript
        class VehicleManager {
            constructor() {
                this.searchTimeout = null;
                this.init();
            }

            init() {
                this.setupEventListeners();
                this.setupKeyboardShortcuts();
                this.loadSavedFilters();
            }

            setupEventListeners() {
                // Search functionality with debounce
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        clearTimeout(this.searchTimeout);
                        this.searchTimeout = setTimeout(() => {
                            this.updateURL('search', e.target.value);
                        }, 500);
                    });
                }

                // Filter event listeners
                const statusFilter = document.getElementById('statusFilter');
                const financingFilter = document.getElementById('financingFilter');
                const yearFilter = document.getElementById('yearFilter');
                const clearFilters = document.getElementById('clearFilters');

                if (statusFilter) statusFilter.addEventListener('change', () => this.updateFilters());
                if (financingFilter) financingFilter.addEventListener('change', () => this.updateFilters());
                if (yearFilter) yearFilter.addEventListener('change', () => this.updateFilters());
                if (clearFilters) clearFilters.addEventListener('click', () => this.clearAllFilters());

                // Modal event listeners
                const rejectModal = document.getElementById('rejectModal');
                const vehicleModal = document.getElementById('vehicleModal');

                if (rejectModal) {
                    rejectModal.addEventListener('click', (e) => {
                        if (e.target === rejectModal) this.closeRejectModal();
                    });
                }

                if (vehicleModal) {
                    vehicleModal.addEventListener('click', (e) => {
                        if (e.target === vehicleModal) this.closeVehicleModal();
                    });
                }

                // Auto-refresh for pending approvals (every 2 minutes)
                if (document.querySelector('.pending-approvals')) {
                    setInterval(() => this.checkForNewApprovals(), 120000);
                }
            }

            setupKeyboardShortcuts() {
                document.addEventListener('keydown', (e) => {
                    // Ctrl/Cmd + F for search focus
                    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                        e.preventDefault();
                        const searchInput = document.getElementById('searchInput');
                        if (searchInput) {
                            searchInput.focus();
                            searchInput.select();
                        }
                    }

                    // Escape to close modals
                    if (e.key === 'Escape') {
                        this.closeRejectModal();
                        this.closeVehicleModal();
                    }

                    // Ctrl/Cmd + N for new vehicle (if authorized)
                    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                        const addButton = document.querySelector('a[href="add_vehicle.php"]');
                        if (addButton) {
                            e.preventDefault();
                            window.location.href = 'add_vehicle.php';
                        }
                    }
                });
            }

            loadSavedFilters() {
                // Load any saved filter preferences from localStorage
                const savedFilters = localStorage.getItem('vehicle_filters');
                if (savedFilters) {
                    try {
                        const filters = JSON.parse(savedFilters);
                        // Apply saved filters if URL doesn't have them
                        const url = new URL(window.location);
                        if (!url.searchParams.has('status') && filters.status) {
                            document.getElementById('statusFilter').value = filters.status;
                        }
                        if (!url.searchParams.has('financing') && filters.financing) {
                            document.getElementById('financingFilter').value = filters.financing;
                        }
                    } catch (e) {
                        console.warn('Failed to load saved filters:', e);
                    }
                }
            }

            saveFilters() {
                const filters = {
                    status: document.getElementById('statusFilter')?.value || '',
                    financing: document.getElementById('financingFilter')?.value || '',
                    year: document.getElementById('yearFilter')?.value || ''
                };
                localStorage.setItem('vehicle_filters', JSON.stringify(filters));
            }

            updateFilters() {
                const url = new URL(window.location);
                const status = document.getElementById('statusFilter')?.value || '';
                const financing = document.getElementById('financingFilter')?.value || '';
                const year = document.getElementById('yearFilter')?.value || '';

                // Update URL parameters
                this.updateURLParam(url, 'status', status);
                this.updateURLParam(url, 'financing', financing);
                this.updateURLParam(url, 'year', year);

                // Save filters and navigate
                this.saveFilters();
                window.location.href = url.toString();
            }

            updateURL(param, value) {
                const url = new URL(window.location);
                this.updateURLParam(url, param, value);
                window.location.href = url.toString();
            }

            updateURLParam(url, param, value) {
                if (value && value.trim() !== '') {
                    url.searchParams.set(param, value);
                } else {
                    url.searchParams.delete(param);
                }
            }

            clearAllFilters() {
                // Clear all URL parameters
                const url = new URL(window.location.pathname, window.location.origin);
                
                // Clear localStorage
                localStorage.removeItem('vehicle_filters');
                
                // Navigate to clean URL
                window.location.href = url.toString();
            }

            // Modal Management
            openRejectModal(requestId) {
                const modal = document.getElementById('rejectModal');
                const requestIdInput = document.getElementById('rejectRequestId');
                const reasonTextarea = document.getElementById('rejection_reason');

                if (modal && requestIdInput) {
                    requestIdInput.value = requestId;
                    if (reasonTextarea) reasonTextarea.value = '';
                    modal.classList.remove('hidden');
                    
                    // Focus on textarea
                    setTimeout(() => reasonTextarea?.focus(), 100);
                }
            }

            closeRejectModal() {
                const modal = document.getElementById('rejectModal');
                const reasonTextarea = document.getElementById('rejection_reason');
                
                if (modal) {
                    modal.classList.add('hidden');
                    if (reasonTextarea) reasonTextarea.value = '';
                }
            }

            // Vehicle Details Management
            async viewVehicleDetails(vehicleId) {
                const modal = document.getElementById('vehicleModal');
                const content = document.getElementById('vehicleDetailsContent');

                if (!modal || !content) return;

                // Show loading state
                content.innerHTML = `
                    <div class="flex items-center justify-center py-8">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-teal-600"></div>
                        <span class="ml-2 text-gray-600">Loading vehicle details...</span>
                    </div>
                `;
                modal.classList.remove('hidden');

                try {
                    // Fetch vehicle details (you'll need to create this endpoint)
                    const response = await fetch(`vehicle_details_api.php?id=${vehicleId}`);
                    const data = await response.json();

                    if (data.success) {
                        content.innerHTML = this.buildVehicleDetailsHTML(data.vehicle);
                    } else {
                        content.innerHTML = `
                            <div class="text-center py-8">
                                <i class="fas fa-exclamation-triangle text-red-500 text-3xl mb-2"></i>
                                <p class="text-red-600">${data.message || 'Failed to load vehicle details'}</p>
                            </div>
                        `;
                    }
                } catch (error) {
                    console.error('Error fetching vehicle details:', error);
                    content.innerHTML = `
                        <div class="text-center py-8">
                            <i class="fas fa-exclamation-triangle text-red-500 text-3xl mb-2"></i>
                            <p class="text-red-600">Error loading vehicle details. Please try again.</p>
                        </div>
                    `;
                }
            }

            buildVehicleDetailsHTML(vehicle) {
                return `
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <h4 class="font-semibold text-gray-900 border-b pb-2">Basic Information</h4>
                            <div class="space-y-2">
                                <div><span class="font-medium">Vehicle Number:</span> ${vehicle.vehicle_number}</div>
                                <div><span class="font-medium">Make & Model:</span> ${vehicle.make_model || 'N/A'}</div>
                                <div><span class="font-medium">Year:</span> ${vehicle.manufacturing_year || 'N/A'}</div>
                                <div><span class="font-medium">GVW:</span> ${vehicle.gvw ? vehicle.gvw + 'T' : 'N/A'}</div>
                                <div><span class="font-medium">Status:</span> 
                                    <span class="status-badge status-${vehicle.current_status}">
                                        ${vehicle.current_status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <h4 class="font-semibold text-gray-900 border-b pb-2">People & Ownership</h4>
                            <div class="space-y-2">
                                <div><span class="font-medium">Driver:</span> ${vehicle.driver_name || 'Not Assigned'}</div>
                                <div><span class="font-medium">Owner:</span> ${vehicle.owner_name || 'N/A'}</div>
                                <div><span class="font-medium">Financing:</span> 
                                    ${vehicle.is_financed ? 
                                        `<span class="text-orange-600">Financed${vehicle.bank_name ? ' by ' + vehicle.bank_name : ''}</span>` : 
                                        '<span class="text-green-600">No</span>'
                                    }
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    ${vehicle.approval_status !== 'approved' ? `
                    <div class="mt-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                        <h4 class="font-semibold text-orange-800 mb-2">Approval Status</h4>
                        <div class="text-sm text-orange-700">
                            Status: <span class="status-badge status-${vehicle.approval_status}">${vehicle.approval_status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</span>
                            ${vehicle.last_approved_by_name ? `<br>Last Action By: ${vehicle.last_approved_by_name}` : ''}
                            ${vehicle.last_approved_at ? `<br>Date: ${new Date(vehicle.last_approved_at).toLocaleDateString()}` : ''}
                        </div>
                    </div>
                    ` : ''}
                `;
            }

            closeVehicleModal() {
                const modal = document.getElementById('vehicleModal');
                if (modal) {
                    modal.classList.add('hidden');
                }
            }

            // Documents Management
            async viewDocuments(vehicleId) {
                try {
                    const response = await fetch(`manage.php?view_docs=${vehicleId}`);
                    const documents = await response.json();

                    if (documents && documents.length > 0) {
                        this.showDocumentsModal(documents);
                    } else {
                        this.showAlert('No documents found for this vehicle.', 'info');
                    }
                } catch (error) {
                    console.error('Error fetching documents:', error);
                    this.showAlert('Error loading documents. Please try again.', 'error');
                }
            }

            showDocumentsModal(documents) {
                const modalHTML = `
                    <div id="documentsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                        <div class="relative top-10 mx-auto p-5 border w-4/5 max-w-3xl shadow-lg rounded-lg bg-white">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-medium text-gray-900">Vehicle Documents</h3>
                                <button onclick="vehicleManager.closeDocumentsModal()" class="text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document Type</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expiry Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uploaded</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        ${documents.map(doc => `
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    ${doc.document_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    ${doc.expiry_date ? new Date(doc.expiry_date).toLocaleDateString() : 'N/A'}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    ${new Date(doc.uploaded_at).toLocaleDateString()}
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button onclick="vehicleManager.viewDocument('${doc.file_path}')" class="text-blue-600 hover:text-blue-900">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </button>
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                document.body.insertAdjacentHTML('beforeend', modalHTML);
            }

            closeDocumentsModal() {
                const modal = document.getElementById('documentsModal');
                if (modal) {
                    modal.remove();
                }
            }

            viewDocument(filePath) {
                // Open document in new tab
                window.open(`Uploads/vehicle_docs/${filePath}`, '_blank');
            }

            // Delete Confirmation
            confirmDelete(vehicleId, vehicleNumber) {
                const confirmation = confirm(
                    `Are you sure you want to delete vehicle "${vehicleNumber}"?\n\n` +
                    `This action will permanently remove:\n` +
                    `• Vehicle record\n` +
                    `• Associated driver information\n` +
                    `• All documents\n` +
                    `• Financial records\n\n` +
                    `This action cannot be undone.`
                );

                if (confirmation) {
                    // Show loading state
                    this.showAlert('Deleting vehicle...', 'info');
                    
                    // Redirect to delete
                    window.location.href = `manage.php?delete=${vehicleId}`;
                }
            }

            // Export Functions
            exportToCSV() {
                const table = document.getElementById('vehiclesTable');
                if (!table) return;

                const csv = this.tableToCSV(table);
                const filename = `vehicles_export_${new Date().toISOString().split('T')[0]}.csv`;
                this.downloadCSV(csv, filename);
            }

            tableToCSV(table) {
                const rows = Array.from(table.querySelectorAll('tr'));
                return rows.map(row => {
                    const cells = Array.from(row.querySelectorAll('th, td'));
                    return cells.map(cell => {
                        // Clean cell content
                        const text = cell.textContent.trim()
                            .replace(/\s+/g, ' ') // Replace multiple spaces with single space
                            .replace(/"/g, '""'); // Escape quotes
                        return `"${text}"`;
                    }).join(',');
                }).join('\n');
            }

            downloadCSV(csv, filename) {
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                
                if (link.download !== undefined) {
                    const url = URL.createObjectURL(blob);
                    link.setAttribute('href', url);
                    link.setAttribute('download', filename);
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    this.showAlert('CSV exported successfully!', 'success');
                } else {
                    this.showAlert('CSV export not supported in this browser.', 'error');
                }
            }

            printTable() {
                // Create a new window with print-friendly content
                const printWindow = window.open('', '_blank');
                const tableContent = document.getElementById('vehiclesTable').outerHTML;
                
                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Vehicle List - ${new Date().toLocaleDateString()}</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            h1 { color: #0d9488; margin-bottom: 20px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                            th { background-color: #f9fafb; font-weight: bold; }
                            .status-badge { padding: 2px 6px; border-radius: 4px; font-size: 11px; }
                            .status-available { background-color: #dcfce7; color: #166534; }
                            .status-on_trip { background-color: #dbeafe; color: #1d4ed8; }
                            .status-loaded { background-color: #fef3c7; color: #92400e; }
                            .status-maintenance { background-color: #fee2e2; color: #dc2626; }
                            .no-print { display: none; }
                            @media print {
                                body { margin: 0; }
                                .no-print { display: none !important; }
                            }
                        </style>
                    </head>
                    <body>
                        <h1>Fleet Vehicle List</h1>
                        <p>Generated on: ${new Date().toLocaleString()}</p>
                        ${tableContent}
                    </body>
                    </html>
                `);
                
                printWindow.document.close();
                
                // Wait for content to load then print
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 250);
            }

            // Request Details
            async viewRequestDetails(requestId) {
                try {
                    const response = await fetch(`request_details_api.php?id=${requestId}`);
                    const data = await response.json();

                    if (data.success) {
                        this.showRequestDetailsModal(data.request);
                    } else {
                        this.showAlert('Failed to load request details.', 'error');
                    }
                } catch (error) {
                    console.error('Error fetching request details:', error);
                    this.showAlert('Error loading request details.', 'error');
                }
            }

            showRequestDetailsModal(request) {
                const currentData = request.current_data ? JSON.parse(request.current_data) : {};
                const proposedData = JSON.parse(request.proposed_data);

                const modalHTML = `
                    <div id="requestDetailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                        <div class="relative top-10 mx-auto p-5 border w-4/5 max-w-4xl shadow-lg rounded-lg bg-white">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-medium text-gray-900">Change Request Details</h3>
                                <button onclick="vehicleManager.closeRequestDetailsModal()" class="text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Request Information</h4>
                                    <div class="space-y-2 text-sm">
                                        <div><span class="font-medium">Type:</span> ${request.request_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</div>
                                        <div><span class="font-medium">Status:</span> <span class="status-badge status-${request.status}">${request.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</span></div>
                                        <div><span class="font-medium">Requested:</span> ${new Date(request.created_at).toLocaleString()}</div>
                                        ${request.reason ? `<div><span class="font-medium">Reason:</span> ${request.reason}</div>` : ''}
                                    </div>
                                </div>
                                
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-3">Proposed Changes</h4>
                                    <div class="space-y-2 text-sm max-h-40 overflow-y-auto">
                                        ${Object.entries(proposedData).map(([key, value]) => `
                                            <div class="flex justify-between">
                                                <span class="font-medium">${key.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}:</span>
                                                <span class="text-green-600">${value || 'N/A'}</span>
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            </div>
                            
                            ${request.status === 'rejected' && request.rejection_reason ? `
                                <div class="mt-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                                    <h4 class="font-semibold text-red-800 mb-2">Rejection Reason</h4>
                                    <p class="text-sm text-red-700">${request.rejection_reason}</p>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;

                document.body.insertAdjacentHTML('beforeend', modalHTML);
            }

            closeRequestDetailsModal() {
                const modal = document.getElementById('requestDetailsModal');
                if (modal) {
                    modal.remove();
                }
            }

            // Utility Functions
            showAlert(message, type = 'info') {
                const alertColors = {
                    success: 'bg-green-100 border-green-500 text-green-700',
                    error: 'bg-red-100 border-red-500 text-red-700',
                    warning: 'bg-yellow-100 border-yellow-500 text-yellow-700',
                    info: 'bg-blue-100 border-blue-500 text-blue-700'
                };

                const icons = {
                    success: 'fas fa-check-circle',
                    error: 'fas fa-exclamation-circle',
                    warning: 'fas fa-exclamation-triangle',
                    info: 'fas fa-info-circle'
                };

                const alertHTML = `
                    <div id="tempAlert" class="fixed top-4 right-4 ${alertColors[type]} border-l-4 p-4 rounded-lg shadow-lg z-50 max-w-md">
                        <div class="flex items-center">
                            <i class="${icons[type]} mr-2"></i>
                            ${message}
                        </div>
                    </div>
                `;

                // Remove existing alerts
                const existingAlert = document.getElementById('tempAlert');
                if (existingAlert) existingAlert.remove();

                // Add new alert
                document.body.insertAdjacentHTML('beforeend', alertHTML);

                // Auto remove after 5 seconds
                setTimeout(() => {
                    const alert = document.getElementById('tempAlert');
                    if (alert) alert.remove();
                }, 5000);
            }

            async checkForNewApprovals() {
                try {
                    const response = await fetch('check_pending_approvals.php');
                    const data = await response.json();

                    if (data.new_requests && data.new_requests > 0) {
                        this.showAlert(`${data.new_requests} new approval request(s) pending`, 'info');
                        
                        // Update badge if exists
                        const badge = document.querySelector('.approval-badge');
                        if (badge) {
                            badge.textContent = data.total_pending;
                        }
                    }
                } catch (error) {
                    console.warn('Failed to check for new approvals:', error);
                }
            }

            // Table sorting functionality
            setupTableSorting() {
                const headers = document.querySelectorAll('#vehiclesTable th');
                headers.forEach((header, index) => {
                    if (index < headers.length - 1) { // Skip actions column
                        header.style.cursor = 'pointer';
                        header.addEventListener('click', () => this.sortTable(index));
                    }
                });
            }

            sortTable(columnIndex) {
                const table = document.getElementById('vehiclesTable');
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));

                // Determine sort direction
                const header = table.querySelectorAll('th')[columnIndex];
                const isAscending = !header.classList.contains('sort-desc');

                // Remove existing sort classes
                table.querySelectorAll('th').forEach(th => {
                    th.classList.remove('sort-asc', 'sort-desc');
                });

                // Add appropriate sort class
                header.classList.add(isAscending ? 'sort-asc' : 'sort-desc');

                // Sort rows
                rows.sort((a, b) => {
                    const aValue = a.cells[columnIndex].textContent.trim();
                    const bValue = b.cells[columnIndex].textContent.trim();

                    let comparison = 0;
                    if (aValue < bValue) comparison = -1;
                    else if (aValue > bValue) comparison = 1;

                    return isAscending ? comparison : -comparison;
                });

                // Rebuild tbody
                tbody.innerHTML = '';
                rows.forEach(row => tbody.appendChild(row));
            }
        }

        // Initialize Vehicle Manager when DOM is loaded
        let vehicleManager;
        document.addEventListener('DOMContentLoaded', function() {
            vehicleManager = new VehicleManager();
            
            // Setup table sorting
            vehicleManager.setupTableSorting();
            
            console.log('Vehicle Management System initialized');
        });

        // Global functions for onclick handlers
        function openRejectModal(requestId) {
            vehicleManager.openRejectModal(requestId);
        }

        function closeRejectModal() {
            vehicleManager.closeRejectModal();
        }

        function viewVehicleDetails(vehicleId) {
            vehicleManager.viewVehicleDetails(vehicleId);
        }

        function closeVehicleModal() {
            vehicleManager.closeVehicleModal();
        }

        function viewDocuments(vehicleId) {
            vehicleManager.viewDocuments(vehicleId);
        }

        function confirmDelete(vehicleId, vehicleNumber) {
            vehicleManager.confirmDelete(vehicleId, vehicleNumber);
        }

        function exportToCSV() {
            vehicleManager.exportToCSV();
        }

        function printTable() {
            vehicleManager.printTable();
        }

        function viewRequestDetails(requestId) {
            vehicleManager.viewRequestDetails(requestId);
        }
		VehicleManager.prototype.openEditModal = function(vehicleId) {
    // Fetch vehicle data and populate the form
    fetch(`get_vehicle_data.php?id=${vehicleId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.populateEditForm(data.vehicle, data.financing);
                document.getElementById('editVehicleModal').classList.remove('hidden');
            } else {
                this.showAlert('Failed to load vehicle data', 'error');
            }
        })
        .catch(error => {
            console.error('Error fetching vehicle data:', error);
            this.showAlert('Error loading vehicle data', 'error');
        });
};

VehicleManager.prototype.populateEditForm = function(vehicle, financing) {
    // Populate basic vehicle data
    document.getElementById('edit_vehicle_id').value = vehicle.id;
    document.getElementById('edit_vehicle_number').value = vehicle.vehicle_number || '';
    document.getElementById('edit_driver_name').value = vehicle.driver_name || '';
    document.getElementById('edit_owner_name').value = vehicle.owner_name || '';
    document.getElementById('edit_make_model').value = vehicle.make_model || '';
    document.getElementById('edit_manufacturing_year').value = vehicle.manufacturing_year || '';
    document.getElementById('edit_gvw').value = vehicle.gvw || '';
    
    // Populate financing data
    const isFinanced = vehicle.is_financed == 1;
    document.getElementById('edit_is_financed').checked = isFinanced;
    
    if (financing) {
        document.getElementById('edit_bank_name').value = financing.bank_name || '';
        document.getElementById('edit_emi_amount').value = financing.emi_amount || '';
        document.getElementById('edit_loan_amount').value = financing.loan_amount || '';
        document.getElementById('edit_interest_rate').value = financing.interest_rate || '';
        document.getElementById('edit_loan_tenure_months').value = financing.loan_tenure_months || '';
        document.getElementById('edit_loan_start_date').value = financing.loan_start_date || '';
        document.getElementById('edit_loan_end_date').value = financing.loan_end_date || '';
    }
    
    this.toggleEditFinancingFields();
};

VehicleManager.prototype.closeEditModal = function() {
    document.getElementById('editVehicleModal').classList.add('hidden');
    document.getElementById('editVehicleForm').reset();
};

VehicleManager.prototype.toggleEditFinancingFields = function() {
    const isFinanced = document.getElementById('edit_is_financed').checked;
    const financingFields = document.getElementById('editFinancingFields');
    financingFields.classList.toggle('hidden', !isFinanced);
};

// Setup edit modal event listeners
document.addEventListener('DOMContentLoaded', function() {
    const editFinancedCheckbox = document.getElementById('edit_is_financed');
    if (editFinancedCheckbox) {
        editFinancedCheckbox.addEventListener('change', function() {
            vehicleManager.toggleEditFinancingFields();
        });
    }
    
    // Auto-calculate edit loan end date
    const editLoanStartDate = document.getElementById('edit_loan_start_date');
    const editLoanTenure = document.getElementById('edit_loan_tenure_months');
    const editLoanEndDate = document.getElementById('edit_loan_end_date');
    
    function calculateEditEndDate() {
        if (editLoanStartDate.value && editLoanTenure.value) {
            const startDate = new Date(editLoanStartDate.value);
            const months = parseInt(editLoanTenure.value);
            const endDate = new Date(startDate.setMonth(startDate.getMonth() + months));
            editLoanEndDate.value = endDate.toISOString().split('T')[0];
        }
    }
    
    if (editLoanStartDate && editLoanTenure) {
        editLoanStartDate.addEventListener('change', calculateEditEndDate);
        editLoanTenure.addEventListener('input', calculateEditEndDate);
    }
    
    // Edit modal form validation
    document.getElementById('editVehicleForm').addEventListener('submit', function(e) {
        const vehicleNumber = document.getElementById('edit_vehicle_number').value.trim();
        const driverName = document.getElementById('edit_driver_name').value.trim();
        const ownerName = document.getElementById('edit_owner_name').value.trim();
        
        if (!vehicleNumber || !driverName || !ownerName) {
            e.preventDefault();
            vehicleManager.showAlert('Please fill in all required fields', 'error');
            return;
        }
        
        // Show loading state
        const submitButton = e.target.querySelector('button[type="submit"]');
        const originalText = submitButton.innerHTML;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
        submitButton.disabled = true;
    });
});

// Global functions for onclick handlers
function openEditModal(vehicleId) {
    vehicleManager.openEditModal(vehicleId);
}

function closeEditModal() {
    vehicleManager.closeEditModal();
}
    </script>
</body>
</html>	