<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Role-based access control
$user_role = $_SESSION['role'];
$allowed_roles = ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'];

if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to add vehicles.";
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

// Check if user has permission for a specific role level
if (!function_exists('hasRoleAccess')) {
    function hasRoleAccess($required_role, $user_role, $role_hierarchy) {
        return $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
    }
}

// Check if user can approve changes
if (!function_exists('canApprove')) {
    function canApprove($user_role) {
        return in_array($user_role, ['manager1', 'manager2', 'admin', 'superadmin']);
    }
}

// Initialize variables
$error = '';
$success = '';
$formData = [
    'vehicle_number' => '',
    'driver_name' => '',
    'owner_name' => '',
    'make_model' => '',
    'manufacturing_year' => '',
    'gvw' => '',
    'is_financed' => 0,
    'bank_name' => '',
    'emi_amount' => '',
    'loan_amount' => '',
    'interest_rate' => '',
    'loan_tenure_months' => '',
    'loan_start_date' => '',
    'loan_end_date' => ''
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $formData = [
        'vehicle_number' => trim($_POST['vehicle_number']),
        'driver_name' => trim($_POST['driver_name']),
        'owner_name' => trim($_POST['owner_name']),
        'make_model' => trim($_POST['make_model']),
        'manufacturing_year' => $_POST['manufacturing_year'] ?? '',
        'gvw' => trim($_POST['gvw']),
        'is_financed' => isset($_POST['is_financed']) ? 1 : 0,
        'bank_name' => trim($_POST['bank_name'] ?? ''),
        'emi_amount' => $_POST['emi_amount'] ?? '',
        'loan_amount' => $_POST['loan_amount'] ?? '',
        'interest_rate' => $_POST['interest_rate'] ?? '',
        'loan_tenure_months' => $_POST['loan_tenure_months'] ?? '',
        'loan_start_date' => $_POST['loan_start_date'] ?? '',
        'loan_end_date' => $_POST['loan_end_date'] ?? ''
    ];

    // Normalize vehicle number for duplicate check
    $normalized_number = preg_replace('/[ -]/', '', strtoupper($formData['vehicle_number']));

    try {
        // Validate required fields
        $errors = [];
        
        if (empty($formData['vehicle_number'])) {
            $errors[] = "Vehicle number is required";
        } elseif (!preg_match('/^[A-Z]{2}[0-9]{1,2}[A-Z]{1,2}[0-9]{4}$/i', $normalized_number)) {
            $errors[] = "Invalid vehicle number format. Use format like UP25GT0880";
        }
        
        if (empty($formData['driver_name'])) {
            $errors[] = "Driver name is required";
        }
        
        if (empty($formData['owner_name'])) {
            $errors[] = "Owner name is required";
        }
        
        if (!empty($formData['manufacturing_year'])) {
            $currentYear = date('Y');
            if (!is_numeric($formData['manufacturing_year']) || 
                $formData['manufacturing_year'] < 1900 || 
                $formData['manufacturing_year'] > $currentYear) {
                $errors[] = "Manufacturing year must be between 1900 and $currentYear";
            }
        }
        
        if (!empty($formData['gvw']) && (!is_numeric($formData['gvw']) || $formData['gvw'] <= 0)) {
            $errors[] = "GVW must be a positive number";
        }
        
        // Financing validation
        if ($formData['is_financed']) {
            if (empty($formData['bank_name'])) {
                $errors[] = "Bank name is required for financed vehicles";
            }
            if (empty($formData['emi_amount']) || !is_numeric($formData['emi_amount']) || $formData['emi_amount'] <= 0) {
                $errors[] = "Valid EMI amount is required for financed vehicles";
            }
            if (!empty($formData['loan_amount']) && (!is_numeric($formData['loan_amount']) || $formData['loan_amount'] <= 0)) {
                $errors[] = "Invalid loan amount";
            }
            if (!empty($formData['interest_rate']) && (!is_numeric($formData['interest_rate']) || $formData['interest_rate'] <= 0)) {
                $errors[] = "Invalid interest rate";
            }
            if (!empty($formData['loan_tenure_months']) && (!is_numeric($formData['loan_tenure_months']) || $formData['loan_tenure_months'] <= 0)) {
                $errors[] = "Invalid loan tenure";
            }
            if (!empty($formData['loan_start_date']) && !DateTime::createFromFormat('Y-m-d', $formData['loan_start_date'])) {
                $errors[] = "Invalid loan start date format (YYYY-MM-DD)";
            }
            if (!empty($formData['loan_end_date']) && !DateTime::createFromFormat('Y-m-d', $formData['loan_end_date'])) {
                $errors[] = "Invalid loan end date format (YYYY-MM-DD)";
            }
        }

        if (empty($errors)) {
            // Check for duplicate vehicle number
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE REGEXP_REPLACE(vehicle_number, '[ -]', '') = ?");
            $stmt->execute([$normalized_number]);
            
            if ($stmt->fetch()) {
                $error = "Vehicle number already exists";
            } else {
                $pdo->beginTransaction();
                
                try {
                    // Check for existing driver with same name and vehicle number combination
                    $driverCheckStmt = $pdo->prepare("
                        SELECT id, name, vehicle_number 
                        FROM drivers 
                        WHERE name = ? AND vehicle_number = ?
                    ");
                    $driverCheckStmt->execute([$formData['driver_name'], $formData['vehicle_number']]);
                    $existingDriver = $driverCheckStmt->fetch();
                    
                    if ($existingDriver) {
                        throw new Exception("A driver with name '{$formData['driver_name']}' is already assigned to vehicle '{$formData['vehicle_number']}'. Please check existing records or use different details.");
                    }
                    
                    // Check if the driver name is already assigned to a different vehicle
                    $driverNameCheckStmt = $pdo->prepare("
                        SELECT id, name, vehicle_number 
                        FROM drivers 
                        WHERE name = ? AND vehicle_number != ?
                    ");
                    $driverNameCheckStmt->execute([$formData['driver_name'], $formData['vehicle_number']]);
                    $driverWithDifferentVehicle = $driverNameCheckStmt->fetch();
                    
                    if ($driverWithDifferentVehicle) {
                        throw new Exception("Driver '{$formData['driver_name']}' is already assigned to vehicle '{$driverWithDifferentVehicle['vehicle_number']}'. A driver cannot be assigned to multiple vehicles.");
                    }
                    
                    // Check if the vehicle already has a different driver assigned
                    $vehicleDriverCheckStmt = $pdo->prepare("
                        SELECT id, name, vehicle_number 
                        FROM drivers 
                        WHERE vehicle_number = ? AND name != ?
                    ");
                    $vehicleDriverCheckStmt->execute([$formData['vehicle_number'], $formData['driver_name']]);
                    $vehicleWithDifferentDriver = $vehicleDriverCheckStmt->fetch();
                    
                    if ($vehicleWithDifferentDriver) {
                        throw new Exception("Vehicle '{$formData['vehicle_number']}' already has driver '{$vehicleWithDifferentDriver['name']}' assigned. Please remove the existing assignment first or use different vehicle details.");
                    }

                    // Determine if approval is needed
                    $needsApproval = ($user_role === 'l2_supervisor');
                    
                    if ($needsApproval) {
                        // Create vehicle change request for approval
                        $proposed_data = [
                            'vehicle_number' => $formData['vehicle_number'],
                            'driver_name' => $formData['driver_name'],
                            'owner_name' => $formData['owner_name'],
                            'make_model' => $formData['make_model'],
                            'manufacturing_year' => !empty($formData['manufacturing_year']) ? $formData['manufacturing_year'] : null,
                            'gvw' => !empty($formData['gvw']) ? $formData['gvw'] : null,
                            'is_financed' => $formData['is_financed'],
                            'bank_name' => $formData['is_financed'] ? ($formData['bank_name'] ?: null) : null,
                            'loan_amount' => $formData['is_financed'] && !empty($formData['loan_amount']) ? $formData['loan_amount'] : null,
                            'interest_rate' => $formData['is_financed'] && !empty($formData['interest_rate']) ? $formData['interest_rate'] : null,
                            'loan_tenure_months' => $formData['is_financed'] && !empty($formData['loan_tenure_months']) ? $formData['loan_tenure_months'] : null,
                            'emi_amount' => $formData['is_financed'] ? ($formData['emi_amount'] ?: null) : null,
                            'loan_start_date' => $formData['is_financed'] && !empty($formData['loan_start_date']) ? $formData['loan_start_date'] : null,
                            'loan_end_date' => $formData['is_financed'] && !empty($formData['loan_end_date']) ? $formData['loan_end_date'] : null
                        ];
                        
                        $requestStmt = $pdo->prepare("
                            INSERT INTO vehicle_change_requests 
                            (vehicle_id, request_type, current_data, proposed_data, requested_by, reason, status, created_at) 
                            VALUES (NULL, 'create', NULL, ?, ?, 'New vehicle addition', 'pending', NOW())
                        ");
                        $requestStmt->execute([
                            json_encode($proposed_data),
                            $_SESSION['user_id']
                        ]);
                        
                        $success = "Vehicle addition request submitted for approval. A manager will review your request shortly.";
                        
                        // Create notification for managers
                        $managerStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('manager1', 'manager2', 'admin', 'superadmin') AND status = 'active'");
                        $managerStmt->execute();
                        $managers = $managerStmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        foreach ($managers as $managerId) {
                            $notificationStmt = $pdo->prepare("
                                INSERT INTO notifications (user_id, title, message, type, related_table, related_id, created_at) 
                                VALUES (?, 'New Vehicle Addition Request', ?, 'info', 'vehicle_change_requests', ?, NOW())
                            ");
                            $notificationStmt->execute([
                                $managerId,
                                "New vehicle addition request for {$formData['vehicle_number']} by " . $_SESSION['full_name'],
                                $pdo->lastInsertId()
                            ]);
                        }
                        
                    } else {
                        // Direct creation for managers and above
                        $insertStmt = $pdo->prepare("
                            INSERT INTO vehicles 
                            (vehicle_number, driver_name, owner_name, make_model, manufacturing_year, gvw, is_financed, current_status, approval_status, last_approved_by, last_approved_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'available', 'approved', ?, NOW())
                        ");
                        $insertStmt->execute([
                            $formData['vehicle_number'],
                            $formData['driver_name'],
                            $formData['owner_name'],
                            $formData['make_model'],
                            !empty($formData['manufacturing_year']) ? $formData['manufacturing_year'] : null,
                            !empty($formData['gvw']) ? $formData['gvw'] : null,
                            $formData['is_financed'],
                            $_SESSION['user_id']
                        ]);
                        
                        $vehicleId = $pdo->lastInsertId();
                        
                        // Insert financing details
                        $financingStmt = $pdo->prepare("
                            INSERT INTO vehicle_financing 
                            (vehicle_id, is_financed, bank_name, loan_amount, interest_rate, loan_tenure_months, emi_amount, loan_start_date, loan_end_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $financingStmt->execute([
                            $vehicleId,
                            $formData['is_financed'],
                            $formData['is_financed'] ? ($formData['bank_name'] ?: null) : null,
                            $formData['is_financed'] && !empty($formData['loan_amount']) ? $formData['loan_amount'] : null,
                            $formData['is_financed'] && !empty($formData['interest_rate']) ? $formData['interest_rate'] : null,
                            $formData['is_financed'] && !empty($formData['loan_tenure_months']) ? $formData['loan_tenure_months'] : null,
                            $formData['is_financed'] ? ($formData['emi_amount'] ?: null) : null,
                            $formData['is_financed'] && !empty($formData['loan_start_date']) ? $formData['loan_start_date'] : null,
                            $formData['is_financed'] && !empty($formData['loan_end_date']) ? $formData['loan_end_date'] : null
                        ]);
                        
                        // Insert driver
                        $driverStmt = $pdo->prepare("
                            INSERT INTO drivers 
                            (name, vehicle_number, status, created_at) 
                            VALUES (?, ?, 'Active', NOW())
                        ");
                        $driverStmt->execute([
                            $formData['driver_name'],
                            $formData['vehicle_number']
                        ]);
                        
                        $success = "Vehicle, driver, and financing details added successfully!";
                    }
                    
                    $pdo->commit();
                    
                    // Reset form data on success
                    $formData = [
                        'vehicle_number' => '',
                        'driver_name' => '',
                        'owner_name' => '',
                        'make_model' => '',
                        'manufacturing_year' => '',
                        'gvw' => '',
                        'is_financed' => 0,
                        'bank_name' => '',
                        'emi_amount' => '',
                        'loan_amount' => '',
                        'interest_rate' => '',
                        'loan_tenure_months' => '',
                        'loan_start_date' => '',
                        'loan_end_date' => ''
                    ];
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = $e->getMessage();
                }
            }
        } else {
            $error = implode("<br>", $errors);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Database error: " . $e->getMessage();
    }
}

// Display success/error messages from session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Vehicle - Fleet Management</title>
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
        
        .form-grid {
            @apply grid grid-cols-1 md:grid-cols-2 gap-4;
        }
        
        .form-field {
            @apply space-y-1;
        }
        
        .form-actions {
            @apply md:col-span-2 pt-2;
        }
        
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
                        <h1 class="text-2xl font-bold text-gray-900">Add Vehicle</h1>
                        <p class="text-sm text-gray-600 mt-1">
                            <?php if ($user_role === 'l2_supervisor'): ?>
                                Vehicle additions require manager approval • Submit for review
                            <?php else: ?>
                                Add vehicles directly to the fleet • No approval required
                            <?php endif; ?>
                        </p>
                    </div>
                    
                </div>
            </header>

            <!-- Messages -->
            <?php if ($success): ?>
                <div class="mx-6 mt-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg animate-fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mx-6 mt-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg animate-fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= $error ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                
                <!-- Information Card for L2 Supervisors -->
                <?php if ($user_role === 'l2_supervisor'): ?>
                <div class="bg-blue-50 rounded-lg shadow-soft p-6 mb-6 card-hover-effect animate-fade-in">
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-medium text-blue-900 mb-2">Approval Required</h3>
                            <p class="text-blue-800 mb-3">
                                As an L2 Supervisor, vehicle additions you submit will be sent for manager approval. 
                                You will be notified once your request is reviewed.
                            </p>
                            <div class="text-sm text-blue-700">
                                <div class="flex items-center mb-1">
                                    <i class="fas fa-clock mr-2"></i>
                                    <span>Expected review time: 1-2 business days</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-bell mr-2"></i>
                                    <span>You'll receive notifications about status updates</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Add Vehicle Form -->
                <div class="bg-white rounded-lg shadow-soft overflow-hidden card-hover-effect animate-fade-in">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-teal-50 to-white">
                        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-truck text-teal-600 mr-2"></i>
                            Vehicle & Driver Information
                        </h2>
                    </div>
                    
                    <div class="p-6">
                        <form id="vehicleForm" method="POST" action="add_vehicle.php" class="form-grid">
                            <!-- Vehicle Number -->
                            <div class="form-field">
                                <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number <span class="text-red-500">*</span></label>
                                <input type="text" id="vehicle_number" name="vehicle_number" 
                                       value="<?= htmlspecialchars($formData['vehicle_number']) ?>" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                       placeholder="e.g. UP25GT0880" required>
                                <p class="text-xs text-gray-500 mt-1">Format: Two letters, 1-2 digits, 1-2 letters, 4 digits</p>
                            </div>
                            
                            <!-- Driver Name -->
                            <div class="form-field">
                                <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name <span class="text-red-500">*</span></label>
                                <input type="text" id="driver_name" name="driver_name" 
                                       value="<?= htmlspecialchars($formData['driver_name']) ?>" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                       placeholder="Enter driver name" required>
                            </div>
                            
                            <!-- Owner Name -->
                            <div class="form-field">
                                <label for="owner_name" class="block text-sm font-medium text-gray-700">Owner Name <span class="text-red-500">*</span></label>
                                <input type="text" id="owner_name" name="owner_name" 
                                       value="<?= htmlspecialchars($formData['owner_name']) ?>" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                       placeholder="Enter owner name" required>
                            </div>
                            
                            <!-- Make/Model -->
                            <div class="form-field">
                                <label for="make_model" class="block text-sm font-medium text-gray-700">Make/Model</label>
                                <input type="text" id="make_model" name="make_model" 
                                       value="<?= htmlspecialchars($formData['make_model']) ?>" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                       placeholder="e.g. Tata 1109, Ashok Leyland 2518">
                            </div>
                            
                            <!-- Manufacturing Year -->
                            <div class="form-field">
                                <label for="manufacturing_year" class="block text-sm font-medium text-gray-700">Year of Manufacturing</label>
                                <select id="manufacturing_year" name="manufacturing_year" 
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">Select Year</option>
                                    <?php
                                    $currentYear = date('Y');
                                    for ($year = $currentYear; $year >= 1990; $year--): ?>
                                        <option value="<?= $year ?>" <?= $formData['manufacturing_year'] == $year ? 'selected' : '' ?>>
                                            <?= $year ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <!-- GVW -->
                            <div class="form-field">
                                <label for="gvw" class="block text-sm font-medium text-gray-700">Gross Vehicle Weight (GVW)</label>
                                <input type="number" id="gvw" name="gvw" step="0.01" min="0"
                                       value="<?= htmlspecialchars($formData['gvw']) ?>" 
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                       placeholder="e.g. 16500.00">
                                <p class="text-xs text-gray-500 mt-1">Weight in kilograms</p>
                            </div>
                            
                            <!-- Financed Vehicle Checkbox -->
                            <div class="form-field md:col-span-2">
                                <div class="flex items-center space-x-3 p-4 bg-gray-50 rounded-lg">
                                    <input type="checkbox" id="is_financed" name="is_financed" value="1" 
                                           <?= $formData['is_financed'] ? 'checked' : '' ?>
                                           class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300 rounded">
                                    <label for="is_financed" class="text-sm font-medium text-gray-700">
                                        <i class="fas fa-university mr-2 text-teal-600"></i>
                                        This vehicle is financed
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Financing Fields -->
                            <div class="form-field md:col-span-2 <?= !$formData['is_financed'] ? 'hidden' : '' ?>" id="financingFields">
                                <div class="space-y-4 bg-gradient-to-r from-teal-50 to-blue-50 p-6 rounded-lg border border-teal-200">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-credit-card text-teal-600 mr-2"></i>
                                        Financing Details
                                    </h4>
                                    <div class="form-grid">
                                        <!-- Bank Name -->
                                        <div class="form-field">
                                            <label for="bank_name" class="block text-sm font-medium text-gray-700">Bank Name <span class="text-red-500">*</span></label>
                                            <input type="text" id="bank_name" name="bank_name" 
                                                   value="<?= htmlspecialchars($formData['bank_name']) ?>" 
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                                   placeholder="e.g. HDFC Bank">
                                        </div>
                                        <!-- EMI Amount -->
                                        <div class="form-field">
                                            <label for="emi_amount" class="block text-sm font-medium text-gray-700">EMI Amount (₹) <span class="text-red-500">*</span></label>
                                            <input type="number" id="emi_amount" name="emi_amount" step="0.01" min="0" 
                                                   value="<?= htmlspecialchars($formData['emi_amount']) ?>" 
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                                   placeholder="e.g. 31500.00">
                                        </div>
                                        <!-- Loan Amount -->
                                        <div class="form-field">
                                            <label for="loan_amount" class="block text-sm font-medium text-gray-700">Loan Amount (₹)</label>
                                            <input type="number" id="loan_amount" name="loan_amount" step="0.01" min="0" 
                                                   value="<?= htmlspecialchars($formData['loan_amount']) ?>" 
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                                   placeholder="e.g. 1500000.00">
                                        </div>
                                        <!-- Interest Rate -->
                                        <div class="form-field">
                                            <label for="interest_rate" class="block text-sm font-medium text-gray-700">Interest Rate (%)</label>
                                            <input type="number" id="interest_rate" name="interest_rate" step="0.01" min="0" 
                                                   value="<?= htmlspecialchars($formData['interest_rate']) ?>" 
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                                   placeholder="e.g. 8.5">
                                        </div>
                                        <!-- Loan Tenure -->
                                        <div class="form-field">
                                            <label for="loan_tenure_months" class="block text-sm font-medium text-gray-700">Loan Tenure (Months)</label>
                                            <input type="number" id="loan_tenure_months" name="loan_tenure_months" min="0" 
                                                   value="<?= htmlspecialchars($formData['loan_tenure_months']) ?>" 
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                                   placeholder="e.g. 60">
                                        </div>
                                        <!-- Loan Start Date -->
                                        <div class="form-field">
                                            <label for="loan_start_date" class="block text-sm font-medium text-gray-700">Loan Start Date</label>
                                            <input type="date" id="loan_start_date" name="loan_start_date" 
                                                   value="<?= htmlspecialchars($formData['loan_start_date']) ?>" 
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                        </div>
                                        <!-- Loan End Date -->
                                        <div class="form-field">
                                            <label for="loan_end_date" class="block text-sm font-medium text-gray-700">Loan End Date</label>
                                            <input type="date" id="loan_end_date" name="loan_end_date" 
                                                   value="<?= htmlspecialchars($formData['loan_end_date']) ?>" 
                                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="form-actions flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors">
                                        <i class="fas fa-plus mr-2"></i>
                                        <?php if ($user_role === 'l2_supervisor'): ?>
                                            Submit for Approval
                                        <?php else: ?>
                                            Add Vehicle
                                        <?php endif; ?>
                                    </button>
                                    <button type="button" onclick="resetForm()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-lg shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors">
                                        <i class="fas fa-undo mr-2"></i>Reset Form
                                    </button>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <a href="manage.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-lg shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors">
                                        <i class="fas fa-list mr-2"></i>View All Vehicles
                                    </a>
                                    <a href="dashboard.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-lg shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors">
                                        <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Help Section -->
                <div class="mt-6 bg-white rounded-lg shadow-soft overflow-hidden card-hover-effect">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50">
                        <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-question-circle text-blue-600 mr-2"></i>
                            Need Help?
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-medium text-gray-900 mb-2">Vehicle Number Format</h4>
                                <p class="text-sm text-gray-600 mb-3">
                                    Follow the standard Indian vehicle registration format:
                                </p>
                                <ul class="text-sm text-gray-600 space-y-1">
                                    <li>• State code: 2 letters (e.g., UP, DL, MH)</li>
                                    <li>• District code: 1-2 digits (e.g., 25, 07)</li>
                                    <li>• Series: 1-2 letters (e.g., GT, AB)</li>
                                    <li>• Number: 4 digits (e.g., 0880)</li>
                                </ul>
                                <p class="text-sm text-teal-600 mt-2 font-medium">Example: UP25GT0880</p>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-900 mb-2">Financing Information</h4>
                                <p class="text-sm text-gray-600 mb-3">
                                    If the vehicle is purchased through financing:
                                </p>
                                <ul class="text-sm text-gray-600 space-y-1">
                                    <li>• Check "Financed Vehicle" option</li>
                                    <li>• Bank name and EMI amount are required</li>
                                    <li>• Other details are optional but recommended</li>
                                    <li>• Loan dates help track payment schedules</li>
                                </ul>
                            </div>
                        </div>
                        <?php if ($user_role === 'l2_supervisor'): ?>
                        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-lightbulb text-yellow-600"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-yellow-800">Approval Process</h4>
                                    <p class="text-sm text-yellow-700 mt-1">
                                        Your vehicle addition will be reviewed by a manager. Ensure all required information is accurate to speed up the approval process. You can track the status in the "Manage Vehicles" section.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const financedCheckbox = document.getElementById('is_financed');
            const financingFields = document.getElementById('financingFields');
            const bankNameInput = document.getElementById('bank_name');
            const emiAmountInput = document.getElementById('emi_amount');
            
            function toggleFinancingFields() {
                const isFinanced = financedCheckbox.checked;
                financingFields.classList.toggle('hidden', !isFinanced);
                bankNameInput.required = isFinanced;
                emiAmountInput.required = isFinanced;
            }
            
            // Initialize
            toggleFinancingFields();
            
            // Event listener
            financedCheckbox.addEventListener('change', toggleFinancingFields);
            
            // Vehicle number formatting
            const vehicleNumberInput = document.getElementById('vehicle_number');
            vehicleNumberInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
            
            // Auto-calculate loan end date based on start date and tenure
            const loanStartDate = document.getElementById('loan_start_date');
            const loanTenure = document.getElementById('loan_tenure_months');
            const loanEndDate = document.getElementById('loan_end_date');
            
            function calculateEndDate() {
                if (loanStartDate.value && loanTenure.value) {
                    const startDate = new Date(loanStartDate.value);
                    const months = parseInt(loanTenure.value);
                    const endDate = new Date(startDate.setMonth(startDate.getMonth() + months));
                    loanEndDate.value = endDate.toISOString().split('T')[0];
                }
            }
            
            loanStartDate.addEventListener('change', calculateEndDate);
            loanTenure.addEventListener('input', calculateEndDate);
            
            // Form validation
            document.getElementById('vehicleForm').addEventListener('submit', function(e) {
                const vehicleNumber = document.getElementById('vehicle_number').value.trim();
                const driverName = document.getElementById('driver_name').value.trim();
                const ownerName = document.getElementById('owner_name').value.trim();
                const gvw = document.getElementById('gvw').value.trim();
                const manufacturingYear = document.getElementById('manufacturing_year').value;
                
                if (!vehicleNumber) {
                    e.preventDefault();
                    showAlert('Vehicle number is required', 'error');
                    return;
                }
                
                // Validate vehicle number format
                const vehicleRegex = /^[A-Z]{2}[0-9]{1,2}[A-Z]{1,2}[0-9]{4}$/;
                const normalizedNumber = vehicleNumber.replace(/[ -]/g, '');
                if (!vehicleRegex.test(normalizedNumber)) {
                    e.preventDefault();
                    showAlert('Invalid vehicle number format. Use format like UP25GT0880', 'error');
                    return;
                }
                
                if (!driverName) {
                    e.preventDefault();
                    showAlert('Driver name is required', 'error');
                    return;
                }
                
                if (!ownerName) {
                    e.preventDefault();
                    showAlert('Owner name is required', 'error');
                    return;
                }
                
                if (manufacturingYear) {
                    const currentYear = new Date().getFullYear();
                    if (manufacturingYear < 1900 || manufacturingYear > currentYear) {
                        e.preventDefault();
                        showAlert('Manufacturing year must be between 1900 and ' + currentYear, 'error');
                        return;
                    }
                }
                
                if (gvw && (isNaN(gvw) || parseFloat(gvw) <= 0)) {
                    e.preventDefault();
                    showAlert('GVW must be a positive number', 'error');
                    return;
                }
                
                if (financedCheckbox.checked) {
                    const bankName = document.getElementById('bank_name').value.trim();
                    const emiAmount = document.getElementById('emi_amount').value.trim();
                    
                    if (!bankName) {
                        e.preventDefault();
                        showAlert('Bank name is required for financed vehicles', 'error');
                        return;
                    }
                    
                    if (!emiAmount || isNaN(emiAmount) || parseFloat(emiAmount) <= 0) {
                        e.preventDefault();
                        showAlert('Valid EMI amount is required for financed vehicles', 'error');
                        return;
                    }
                }
                
                // Show loading state
                const submitButton = e.target.querySelector('button[type="submit"]');
                const originalText = submitButton.innerHTML;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                submitButton.disabled = true;
                
                // Re-enable button after 5 seconds in case of issues
                setTimeout(() => {
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                }, 5000);
            });
        });
        
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                document.getElementById('vehicleForm').reset();
                document.getElementById('financingFields').classList.add('hidden');
                document.getElementById('bank_name').required = false;
                document.getElementById('emi_amount').required = false;
            }
        }
        
        function showAlert(message, type = 'info') {
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
                <div id="tempAlert" class="fixed top-4 right-4 ${alertColors[type]} border-l-4 p-4 rounded-lg shadow-lg z-50 max-w-md animate-fade-in">
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
    </script>
</body>
</html>