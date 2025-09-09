<?php
// Keep the existing PHP code as it was working correctly
session_start();
require '../auth_check.php';
require '../db_connection.php';

$success = '';
$error = '';
$user_role = $_SESSION['role'];

// Check if user has access to add vendors (L2 Supervisor and above)
if (!in_array($user_role, ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'])) {
    $_SESSION['error'] = "You don't have permission to register vendors.";
    header("Location: dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Collect form data
        $vendor_data = [
            'company_name' => trim($_POST['company_name']),
            'registered_address' => trim($_POST['registered_address']),
            'invoice_address' => trim($_POST['invoice_address']),
            'contact_person' => trim($_POST['contact_person']),
            'mobile' => trim($_POST['mobile']),
            'email' => trim($_POST['email']),
            'constitution_type' => $_POST['constitution_type'],
            'business_nature' => $_POST['business_nature'],
            'bank_name' => trim($_POST['bank_name']),
            'account_number' => trim($_POST['account_number']),
            'branch_name' => trim($_POST['branch_name']),
            'ifsc_code' => strtoupper(trim($_POST['ifsc_code'])),
            'pan_number' => strtoupper(trim($_POST['pan_number'])),
            'gst_number' => strtoupper(trim($_POST['gst_number'])),
            'state' => $_POST['state'],
            'state_code' => $_POST['state_code'],
            'vendor_type' => $_POST['vendor_type'],
            'total_vehicles' => !empty($_POST['total_vehicles']) ? (int)$_POST['total_vehicles'] : 0,
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Set status based on user role
        if ($user_role === 'l2_supervisor') {
            $vendor_data['status'] = 'pending';
            $vendor_data['approval_status'] = 'pending';
        } else {
            // Managers and above can add vendors directly
            $vendor_data['status'] = 'approved';
            $vendor_data['approval_status'] = 'approved';
            $vendor_data['approved_by'] = $_SESSION['user_id'];
            $vendor_data['approved_at'] = date('Y-m-d H:i:s');
        }
        
        // Validation
        $errors = [];
        
        // Required field validation
        if (empty($vendor_data['company_name'])) {
            $errors[] = "Company name is required";
        }
        
        if (empty($vendor_data['contact_person'])) {
            $errors[] = "Contact person name is required";
        }
        
        if (empty($vendor_data['mobile']) || !preg_match('/^[0-9]{10}$/', $vendor_data['mobile'])) {
            $errors[] = "Valid 10-digit mobile number is required";
        }
        
        if (empty($vendor_data['email']) || !filter_var($vendor_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required";
        }
        
        if (empty($vendor_data['registered_address'])) {
            $errors[] = "Registered address is required";
        }
        
        if (empty($vendor_data['invoice_address'])) {
            $errors[] = "Invoice address is required";
        }
        
        if (empty($vendor_data['constitution_type'])) {
            $errors[] = "Constitution type is required";
        }
        
        if (empty($vendor_data['business_nature'])) {
            $errors[] = "Business nature is required";
        }
        
        if (empty($vendor_data['vendor_type'])) {
            $errors[] = "Vendor type is required";
        }
        
        // Bank details validation
        if (empty($vendor_data['bank_name'])) {
            $errors[] = "Bank name is required";
        }
        
        if (empty($vendor_data['account_number'])) {
            $errors[] = "Account number is required";
        }
        
        if (empty($vendor_data['branch_name'])) {
            $errors[] = "Branch name is required";
        }
        
        if (empty($vendor_data['ifsc_code']) || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $vendor_data['ifsc_code'])) {
            $errors[] = "Valid IFSC code is required (e.g., ABCD0123456)";
        }
        
        // Tax details validation
        if (empty($vendor_data['pan_number']) || !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $vendor_data['pan_number'])) {
            $errors[] = "Valid PAN number is required (e.g., ABCDE1234F)";
        }
        
        if (empty($vendor_data['gst_number'])) {
            $errors[] = "GST number is required";
        }
        
        if (empty($vendor_data['state'])) {
            $errors[] = "State is required";
        }
        
        if (empty($vendor_data['state_code'])) {
            $errors[] = "State code is required";
        }
        
        // Check for duplicate email
        $emailCheck = $pdo->prepare("SELECT id FROM vendors WHERE email = ? AND deleted_at IS NULL");
        $emailCheck->execute([$vendor_data['email']]);
        if ($emailCheck->rowCount() > 0) {
            $errors[] = "Email address already exists";
        }
        
        // Check for duplicate PAN
        $panCheck = $pdo->prepare("SELECT id FROM vendors WHERE pan_number = ? AND deleted_at IS NULL");
        $panCheck->execute([$vendor_data['pan_number']]);
        if ($panCheck->rowCount() > 0) {
            $errors[] = "PAN number already exists";
        }
        
        // Check for duplicate GST
        $gstCheck = $pdo->prepare("SELECT id FROM vendors WHERE gst_number = ? AND deleted_at IS NULL");
        $gstCheck->execute([$vendor_data['gst_number']]);
        if ($gstCheck->rowCount() > 0) {
            $errors[] = "GST number already exists";
        }
        
        // Validate vehicle numbers if provided
        $vehicle_numbers = [];
        if (!empty($_POST['vehicle_numbers'])) {
            $vehicle_numbers = array_filter(array_map('trim', $_POST['vehicle_numbers']));
            $used_numbers = [];
            
            foreach ($vehicle_numbers as $vehicle_number) {
                if (!empty($vehicle_number)) {
                    // Normalize vehicle number
                    $normalized_number = preg_replace('/[ -]/', '', strtoupper($vehicle_number));
                    
                    // Validate vehicle number format
                    if (!preg_match('/^[A-Z]{2}[0-9]{1,2}[A-Z]{1,2}[0-9]{4}$/i', $normalized_number)) {
                        $errors[] = "Invalid vehicle number format: {$vehicle_number}. Use format like UP25GT0880";
                    }
                    
                    // Check for duplicates in this submission
                    if (in_array($normalized_number, $used_numbers)) {
                        $errors[] = "Duplicate vehicle number in submission: {$vehicle_number}";
                    } else {
                        $used_numbers[] = $normalized_number;
                    }
                    
                    // Check if vehicle number already exists in database
                    $vehicleCheck = $pdo->prepare("SELECT id FROM vendor_vehicles WHERE REGEXP_REPLACE(vehicle_number, '[ -]', '') = ? AND deleted_at IS NULL");
                    $vehicleCheck->execute([$normalized_number]);
                    if ($vehicleCheck->rowCount() > 0) {
                        $errors[] = "Vehicle number already exists: {$vehicle_number}";
                    }
                }
            }
        }
        
        if (empty($errors)) {
            // Generate vendor code
            $vendor_code = 'VDR' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Check if vendor code already exists
            while (true) {
                $codeCheck = $pdo->prepare("SELECT id FROM vendors WHERE vendor_code = ?");
                $codeCheck->execute([$vendor_code]);
                if ($codeCheck->rowCount() == 0) {
                    break;
                }
                $vendor_code = 'VDR' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            
            $vendor_data['vendor_code'] = $vendor_code;
            
            // Insert vendor
            $columns = implode(', ', array_keys($vendor_data));
            $placeholders = ':' . implode(', :', array_keys($vendor_data));
            
            $stmt = $pdo->prepare("INSERT INTO vendors ({$columns}) VALUES ({$placeholders})");
            $stmt->execute($vendor_data);
            
            $vendor_id = $pdo->lastInsertId();
            
            // Insert vehicle numbers if provided
            if (!empty($vehicle_numbers)) {
                $vehicleStmt = $pdo->prepare("
                    INSERT INTO vendor_vehicles (vendor_id, vehicle_number, created_at) 
                    VALUES (?, ?, NOW())
                ");
                
                foreach ($vehicle_numbers as $vehicle_number) {
                    if (!empty($vehicle_number)) {
                        $vehicleStmt->execute([$vendor_id, trim($vehicle_number)]);
                    }
                }
            }

            // Log the submission
            $logStmt = $pdo->prepare("
                INSERT INTO vendor_approval_logs 
                (vendor_id, action, action_by, action_at, comments, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $logStmt->execute([
                $vendor_id,
                $user_role === 'l2_supervisor' ? 'submitted' : 'approved',
                $_SESSION['user_id'],
                date('Y-m-d H:i:s'),
                $user_role === 'l2_supervisor' ? "Vendor submitted for approval by " . $_SESSION['full_name'] : "Vendor added directly by " . $_SESSION['full_name'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            // Send notification if approval is needed
            if ($user_role === 'l2_supervisor') {
                // Get all managers/admins for notification
                $managerStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('manager1', 'manager2', 'admin', 'superadmin') AND status = 'active'");
                $managerStmt->execute();
                $managers = $managerStmt->fetchAll(PDO::FETCH_ASSOC);

                $notificationStmt = $pdo->prepare("
                    INSERT INTO notifications 
                    (user_id, title, message, type, reference_type, reference_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($managers as $manager) {
                    $notificationStmt->execute([
                        $manager['id'],
                        'New Vendor Approval Required',
                        "A new vendor '{$vendor_data['company_name']}' has been submitted for approval by " . $_SESSION['full_name'],
                        'info',
                        'vendor',
                        $vendor_id,
                        date('Y-m-d H:i:s')
                    ]);
                }
            }
            
            $pdo->commit();
            
            if ($user_role === 'l2_supervisor') {
                $success = "Vendor registration submitted successfully! Your vendor code is: " . $vendor_code . ". 
                           The registration is pending approval from a manager.";
            } else {
                $success = "Vendor registered successfully! Vendor code: " . $vendor_code;
            }
            
            // Clear form data on success
            $_POST = [];
            
        } else {
            $error = implode("<br>", $errors);
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Pre-fill form data if there was an error
$formData = [
    'company_name' => $_POST['company_name'] ?? '',
    'contact_person' => $_POST['contact_person'] ?? '',
    'mobile' => $_POST['mobile'] ?? '',
    'email' => $_POST['email'] ?? '',
    'registered_address' => $_POST['registered_address'] ?? '',
    'invoice_address' => $_POST['invoice_address'] ?? '',
    'constitution_type' => $_POST['constitution_type'] ?? '',
    'business_nature' => $_POST['business_nature'] ?? '',
    'vendor_type' => $_POST['vendor_type'] ?? '',
    'bank_name' => $_POST['bank_name'] ?? '',
    'account_number' => $_POST['account_number'] ?? '',
    'branch_name' => $_POST['branch_name'] ?? '',
    'ifsc_code' => $_POST['ifsc_code'] ?? '',
    'pan_number' => $_POST['pan_number'] ?? '',
    'gst_number' => $_POST['gst_number'] ?? '',
    'state' => $_POST['state'] ?? '',
    'state_code' => $_POST['state_code'] ?? '',
    'total_vehicles' => $_POST['total_vehicles'] ?? '',
    'vehicle_numbers' => $_POST['vehicle_numbers'] ?? ['']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Vendor - Absuma Logistics</title>
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
                        'glow': '0 0 15px -3px rgba(229, 62, 62, 0.3)',
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .vehicle-input {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .remove-vehicle {
            color: #dc2626;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.375rem;
            background-color: #fef2f2;
            transition: all 0.2s ease;
        }
        
        .remove-vehicle:hover {
            background-color: #fecaca;
            color: #991b1b;
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
                        <h1 class="text-2xl font-bold text-gray-900">Add Vendor</h1>
                        <p class="text-sm text-gray-600 mt-1">
                            <?php if ($user_role === 'l2_supervisor'): ?>
                                Vendor registrations require manager approval • Submit for review
                            <?php else: ?>
                                Add vendors directly to the system • No approval required
                            <?php endif; ?>
                        </p>
                    </div>
                   
                </div>
            </header>

            <!-- Main Content -->
            <main class="flex-1 p-6 overflow-auto">
                <div class="max-w-6xl mx-auto">
                    <!-- Alert Messages -->
                    <?php if (!empty($success)): ?>
                        <div class="bg-green-50 border border-green-200 p-4 mb-6 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-check-circle text-green-400 mr-3"></i>
                                <p class="text-green-700"><?= htmlspecialchars($success) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="bg-red-50 border border-red-200 p-4 mb-6 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-circle text-red-400 mr-3"></i>
                                <div class="text-red-700"><?= $error ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Vendor Registration Form -->
                    <form method="POST" class="space-y-6" id="vendorForm">
                        <!-- Company Information -->
                        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <div class="bg-gray-50 px-4 py-2">
                                <h2 class="text-base font-medium text-gray-700 flex items-center gap-2">
                                    <i class="fas fa-building text-gray-500"></i> Company Information
                                </h2>
                            </div>
                            <div class="p-4">
                                <div class="form-grid">
                                    <div>
                                        <label for="company_name" class="block text-sm font-medium text-gray-700">
                                            Company Name <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="company_name" 
                                            name="company_name" 
                                            required 
                                            value="<?= htmlspecialchars($formData['company_name']) ?>"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="contact_person" class="block text-sm font-medium text-gray-700">
                                            Contact Person <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="contact_person" 
                                            name="contact_person" 
                                            required 
                                            value="<?= htmlspecialchars($formData['contact_person']) ?>"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="mobile" class="block text-sm font-medium text-gray-700">
                                            Mobile Number <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="tel" 
                                            id="mobile" 
                                            name="mobile" 
                                            required 
                                            pattern="[0-9]{10}"
                                            value="<?= htmlspecialchars($formData['mobile']) ?>"
                                            placeholder="e.g., 9876543210"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="email" class="block text-sm font-medium text-gray-700">
                                            Email Address <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="email" 
                                            id="email" 
                                            name="email" 
                                            required 
                                            value="<?= htmlspecialchars($formData['email']) ?>"
                                            placeholder="e.g., contact@company.com"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div class="col-span-2">
                                        <label for="registered_address" class="block text-sm font-medium text-gray-700">
                                            Registered Address <span class="text-red-500">*</span>
                                        </label>
                                        <textarea 
                                            id="registered_address" 
                                            name="registered_address" 
                                            required 
                                            rows="2"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        ><?= htmlspecialchars($formData['registered_address']) ?></textarea>
                                    </div>
                                    
                                    <div class="col-span-2">
                                        <div class="flex items-center mb-1">
                                            <input 
                                                type="checkbox" 
                                                id="same_as_registered" 
                                                class="h-4 w-4 text-gray-600 border-gray-300 rounded focus:ring-gray-500"
                                            >
                                            <label for="same_as_registered" class="ml-2 text-sm text-gray-700">
                                                Invoice address same as registered
                                            </label>
                                        </div>
                                        <label for="invoice_address" class="block text-sm font-medium text-gray-700">
                                            Invoice Address <span class="text-red-500">*</span>
                                        </label>
                                        <textarea 
                                            id="invoice_address" 
                                            name="invoice_address" 
                                            required 
                                            rows="2"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        ><?= htmlspecialchars($formData['invoice_address']) ?></textarea>
                                    </div>
                                    
                                    <div>
                                        <label for="constitution_type" class="block text-sm font-medium text-gray-700">
                                            Constitution Type <span class="text-red-500">*</span>
                                        </label>
                                        <select 
                                            id="constitution_type" 
                                            name="constitution_type" 
                                            required 
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                            <option value="">Select Constitution Type</option>
                                            <option value="Proprietorship" <?= $formData['constitution_type'] === 'Proprietorship' ? 'selected' : '' ?>>Proprietorship</option>
                                            <option value="Partnership" <?= $formData['constitution_type'] === 'Partnership' ? 'selected' : '' ?>>Partnership</option>
                                            <option value="Private Limited" <?= $formData['constitution_type'] === 'Private Limited' ? 'selected' : '' ?>>Private Limited</option>
                                            <option value="Public Limited" <?= $formData['constitution_type'] === 'Public Limited' ? 'selected' : '' ?>>Public Limited</option>
                                            <option value="Other" <?= $formData['constitution_type'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="business_nature" class="block text-sm font-medium text-gray-700">
                                            Nature of Business <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="business_nature" 
                                            name="business_nature" 
                                            required 
                                            value="<?= htmlspecialchars($formData['business_nature']) ?>"
                                            placeholder="e.g., Transport Services"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="vendor_type" class="block text-sm font-medium text-gray-700">
                                            Vendor Type <span class="text-red-500">*</span>
                                        </label>
                                        <select 
                                            id="vendor_type" 
                                            name="vendor_type" 
                                            required 
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                            <option value="">Select Vendor Type</option>
                                            <option value="Transport" <?= $formData['vendor_type'] === 'Transport' ? 'selected' : '' ?>>Transport</option>
                                            <option value="Maintenance" <?= $formData['vendor_type'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                            <option value="Fuel" <?= $formData['vendor_type'] === 'Fuel' ? 'selected' : '' ?>>Fuel</option>
                                            <option value="Parts Supplier" <?= $formData['vendor_type'] === 'Parts Supplier' ? 'selected' : '' ?>>Parts Supplier</option>
                                            <option value="Insurance" <?= $formData['vendor_type'] === 'Insurance' ? 'selected' : '' ?>>Insurance</option>
                                            <option value="Logistics" <?= $formData['vendor_type'] === 'Logistics' ? 'selected' : '' ?>>Logistics</option>
                                            <option value="Other" <?= $formData['vendor_type'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="state" class="block text-sm font-medium text-gray-700">
                                            State <span class="text-red-500">*</span>
                                        </label>
                                        <select 
                                            id="state" 
                                            name="state" 
                                            required 
                                            onchange="updateStateCode()"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                            <option value="">Select State</option>
                                            <option value="Andaman and Nicobar Islands" data-code="35" <?= $formData['state'] === 'Andaman and Nicobar Islands' ? 'selected' : '' ?>>Andaman and Nicobar Islands</option>
                                            <option value="Andhra Pradesh" data-code="28" <?= $formData['state'] === 'Andhra Pradesh' ? 'selected' : '' ?>>Andhra Pradesh</option>
                                            <option value="Arunachal Pradesh" data-code="12" <?= $formData['state'] === 'Arunachal Pradesh' ? 'selected' : '' ?>>Arunachal Pradesh</option>
                                            <option value="Assam" data-code="18" <?= $formData['state'] === 'Assam' ? 'selected' : '' ?>>Assam</option>
                                            <option value="Bihar" data-code="10" <?= $formData['state'] === 'Bihar' ? 'selected' : '' ?>>Bihar</option>
                                            <option value="Chandigarh" data-code="04" <?= $formData['state'] === 'Chandigarh' ? 'selected' : '' ?>>Chandigarh</option>
                                            <option value="Chhattisgarh" data-code="22" <?= $formData['state'] === 'Chhattisgarh' ? 'selected' : '' ?>>Chhattisgarh</option>
                                            <option value="Dadra and Nagar Haveli and Daman and Diu" data-code="26" <?= $formData['state'] === 'Dadra and Nagar Haveli and Daman and Diu' ? 'selected' : '' ?>>Dadra and Nagar Haveli and Daman and Diu</option>
                                            <option value="Delhi" data-code="07" <?= $formData['state'] === 'Delhi' ? 'selected' : '' ?>>Delhi</option>
                                            <option value="Goa" data-code="30" <?= $formData['state'] === 'Goa' ? 'selected' : '' ?>>Goa</option>
                                            <option value="Gujarat" data-code="24" <?= $formData['state'] === 'Gujarat' ? 'selected' : '' ?>>Gujarat</option>
                                            <option value="Haryana" data-code="06" <?= $formData['state'] === 'Haryana' ? 'selected' : '' ?>>Haryana</option>
                                            <option value="Himachal Pradesh" data-code="02" <?= $formData['state'] === 'Himachal Pradesh' ? 'selected' : '' ?>>Himachal Pradesh</option>
                                            <option value="Jammu and Kashmir" data-code="01" <?= $formData['state'] === 'Jammu and Kashmir' ? 'selected' : '' ?>>Jammu and Kashmir</option>
                                            <option value="Jharkhand" data-code="20" <?= $formData['state'] === 'Jharkhand' ? 'selected' : '' ?>>Jharkhand</option>
                                            <option value="Karnataka" data-code="29" <?= $formData['state'] === 'Karnataka' ? 'selected' : '' ?>>Karnataka</option>
                                            <option value="Kerala" data-code="32" <?= $formData['state'] === 'Kerala' ? 'selected' : '' ?>>Kerala</option>
                                            <option value="Lakshadweep" data-code="31" <?= $formData['state'] === 'Lakshadweep' ? 'selected' : '' ?>>Lakshadweep</option>
                                            <option value="Madhya Pradesh" data-code="23" <?= $formData['state'] === 'Madhya Pradesh' ? 'selected' : '' ?>>Madhya Pradesh</option>
                                            <option value="Maharashtra" data-code="27" <?= $formData['state'] === 'Maharashtra' ? 'selected' : '' ?>>Maharashtra</option>
                                            <option value="Manipur" data-code="14" <?= $formData['state'] === 'Manipur' ? 'selected' : '' ?>>Manipur</option>
                                            <option value="Meghalaya" data-code="17" <?= $formData['state'] === 'Meghalaya' ? 'selected' : '' ?>>Meghalaya</option>
                                            <option value="Mizoram" data-code="15" <?= $formData['state'] === 'Mizoram' ? 'selected' : '' ?>>Mizoram</option>
                                            <option value="Nagaland" data-code="13" <?= $formData['state'] === 'Nagaland' ? 'selected' : '' ?>>Nagaland</option>
                                            <option value="Odisha" data-code="21" <?= $formData['state'] === 'Odisha' ? 'selected' : '' ?>>Odisha</option>
                                            <option value="Puducherry" data-code="34" <?= $formData['state'] === 'Puducherry' ? 'selected' : '' ?>>Puducherry</option>
                                            <option value="Punjab" data-code="03" <?= $formData['state'] === 'Punjab' ? 'selected' : '' ?>>Punjab</option>
                                            <option value="Rajasthan" data-code="08" <?= $formData['state'] === 'Rajasthan' ? 'selected' : '' ?>>Rajasthan</option>
                                            <option value="Sikkim" data-code="11" <?= $formData['state'] === 'Sikkim' ? 'selected' : '' ?>>Sikkim</option>
                                            <option value="Tamil Nadu" data-code="33" <?= $formData['state'] === 'Tamil Nadu' ? 'selected' : '' ?>>Tamil Nadu</option>
                                            <option value="Telangana" data-code="36" <?= $formData['state'] === 'Telangana' ? 'selected' : '' ?>>Telangana</option>
                                            <option value="Tripura" data-code="16" <?= $formData['state'] === 'Tripura' ? 'selected' : '' ?>>Tripura</option>
                                            <option value="Uttar Pradesh" data-code="09" <?= $formData['state'] === 'Uttar Pradesh' ? 'selected' : '' ?>>Uttar Pradesh</option>
                                            <option value="Uttarakhand" data-code="05" <?= $formData['state'] === 'Uttarakhand' ? 'selected' : '' ?>>Uttarakhand</option>
                                            <option value="West Bengal" data-code="19" <?= $formData['state'] === 'West Bengal' ? 'selected' : '' ?>>West Bengal</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="state_code" class="block text-sm font-medium text-gray-700">
                                            State Code <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="state_code" 
                                            name="state_code" 
                                            required 
                                            readonly 
                                            value="<?= htmlspecialchars($formData['state_code']) ?>"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500 bg-gray-50"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bank and Tax Details -->
                        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <div class="bg-gray-50 px-4 py-2">
                                <h2 class="text-base font-medium text-gray-700 flex items-center gap-2">
                                    <i class="fas fa-file-invoice text-gray-500"></i> Bank and Tax Details
                                </h2>
                            </div>
                            <div class="p-4">
                                <div class="form-grid">
                                    <div>
                                        <label for="bank_name" class="block text-sm font-medium text-gray-700">
                                            Bank Name <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="bank_name" 
                                            name="bank_name" 
                                            required 
                                            value="<?= htmlspecialchars($formData['bank_name']) ?>"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="account_number" class="block text-sm font-medium text-gray-700">
                                            Account Number <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="account_number" 
                                            name="account_number" 
                                            required 
                                            value="<?= htmlspecialchars($formData['account_number']) ?>"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="branch_name" class="block text-sm font-medium text-gray-700">
                                            Branch Name <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="branch_name" 
                                            name="branch_name" 
                                            required 
                                            value="<?= htmlspecialchars($formData['branch_name']) ?>"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="ifsc_code" class="block text-sm font-medium text-gray-700">
                                            IFSC Code <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="ifsc_code" 
                                            name="ifsc_code" 
                                            required 
                                            pattern="[A-Z]{4}0[A-Z0-9]{6}"
                                            value="<?= htmlspecialchars($formData['ifsc_code']) ?>"
                                            placeholder="e.g., SBIN0001234"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="pan_number" class="block text-sm font-medium text-gray-700">
                                            PAN Number <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="pan_number" 
                                            name="pan_number" 
                                            required 
                                            pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}"
                                            value="<?= htmlspecialchars($formData['pan_number']) ?>"
                                            placeholder="e.g., ABCDE1234F"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="gst_number" class="block text-sm font-medium text-gray-700">
                                            GST Number <span class="text-red-500">*</span>
                                        </label>
                                        <input 
                                            type="text" 
                                            id="gst_number" 
                                            name="gst_number" 
                                            required 
                                            value="<?= htmlspecialchars($formData['gst_number']) ?>"
                                            placeholder="e.g., 09ABCDE1234F1Z5"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Vehicle Information (Optional) -->
                        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <div class="bg-gray-50 px-4 py-2">
                                <h2 class="text-base font-medium text-gray-700 flex items-center gap-2">
                                    <i class="fas fa-truck text-gray-500"></i> Vehicle Information
                                </h2>
                            </div>
                            <div class="p-4">
                                <div class="form-grid">
                                    <div>
                                        <label for="total_vehicles" class="block text-sm font-medium text-gray-700">
                                            Total Number of Vehicles
                                        </label>
                                        <input 
                                            type="number" 
                                            id="total_vehicles" 
                                            name="total_vehicles" 
                                            min="0" 
                                            value="<?= htmlspecialchars($formData['total_vehicles']) ?>"
                                            onchange="updateVehicleInputs()"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                                        >
                                    </div>
                                </div>
                                
                                <div id="vehicle_numbers_section" class="mt-4" style="display: none;">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Vehicle Numbers</label>
                                    <div id="vehicle_inputs">
                                        <!-- Vehicle number inputs will be dynamically added here -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <div class="p-4">
                                <div class="flex flex-col sm:flex-row gap-4 justify-end">
                                    <button 
                                        type="reset" 
                                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-gray-500"
                                    >
                                        <i class="fas fa-undo mr-2"></i> Reset Form
                                    </button>
                                    <button 
                                        type="submit" 
                                        id="submitBtn"
                                        class="px-4 py-2 bg-teal-600 text-white rounded-md hover:bg-gray-300 focus:outline-none focus:ring-1 focus:ring-gray-500"
                                    >
                                        <i class="fas fa-save mr-2"></i>
                                        <?= $user_role === 'l2_supervisor' ? 'Submit for Approval' : 'Register Vendor' ?>
                                    </button>
                                </div>
                                <div class="mt-4 text-center text-sm text-gray-600">
                                    <?php if ($user_role === 'l2_supervisor'): ?>
                                        Vendor registration will be submitted for approval.
                                    <?php else: ?>
                                        Vendor will be registered immediately.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Update state code based on state selection
        function updateStateCode() {
            const stateSelect = document.getElementById('state');
            const stateCodeInput = document.getElementById('state_code');
            const selectedOption = stateSelect.options[stateSelect.selectedIndex];
            
            if (selectedOption.dataset.code) {
                stateCodeInput.value = selectedOption.dataset.code;
            } else {
                stateCodeInput.value = '';
            }
        }

        // Copy registered address to invoice address
        document.getElementById('same_as_registered').addEventListener('change', function() {
            const registeredAddress = document.getElementById('registered_address').value;
            const invoiceAddress = document.getElementById('invoice_address');
            
            if (this.checked) {
                invoiceAddress.value = registeredAddress;
            } else {
                invoiceAddress.value = '';
            }
        });

        // Update vehicle inputs based on total vehicles
        function updateVehicleInputs() {
            const totalVehicles = parseInt(document.getElementById('total_vehicles').value) || 0;
            const vehicleSection = document.getElementById('vehicle_numbers_section');
            const vehicleInputs = document.getElementById('vehicle_inputs');
            
            if (totalVehicles > 0) {
                vehicleSection.style.display = 'block';
                vehicleInputs.innerHTML = '';
                
                for (let i = 0; i < totalVehicles; i++) {
                    const inputDiv = document.createElement('div');
                    inputDiv.className = 'vehicle-input';
                    inputDiv.innerHTML = `
                        <input 
                            type="text" 
                            name="vehicle_numbers[]" 
                            placeholder="Vehicle Number ${i + 1} (e.g., UP25GT0880)"
                            class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                        >
                        <button 
                            type="button" 
                            onclick="removeVehicleInput(this)" 
                            class="remove-vehicle"
                            title="Remove this vehicle"
                        >
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    vehicleInputs.appendChild(inputDiv);
                }
            } else {
                vehicleSection.style.display = 'none';
                vehicleInputs.innerHTML = '';
            }
        }

        function removeVehicleInput(button) {
            button.parentElement.remove();
            // Update total vehicles count
            const remainingInputs = document.querySelectorAll('input[name="vehicle_numbers[]"]').length;
            document.getElementById('total_vehicles').value = remainingInputs;
        }

        // Form validation with enhanced UX
        function validateForm() {
            const requiredFields = document.querySelectorAll('[required]');
            let isValid = true;
            let firstInvalidField = null;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('border-red-500', 'ring-1', 'ring-red-500');
                    field.classList.remove('border-gray-300');
                    
                    if (!firstInvalidField) {
                        firstInvalidField = field;
                    }
                } else {
                    field.classList.remove('border-red-500', 'ring-1', 'ring-red-500');
                    field.classList.add('border-gray-300');
                }
            });
            
            if (!isValid) {
                // Show error message
                showErrorMessage('Please fill in all required fields.');
                
                // Focus on first invalid field
                if (firstInvalidField) {
                    firstInvalidField.focus();
                    firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
            
            return isValid;
        }

        function showErrorMessage(message) {
            // Remove existing error messages
            const existingErrors = document.querySelectorAll('.validation-error');
            existingErrors.forEach(error => error.remove());
            
            const errorDiv = document.createElement('div');
            errorDiv.className = 'validation-error bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-lg animate-fade-in';
            errorDiv.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-400 mr-3"></i>
                    <p class="text-red-700">${message}</p>
                </div>
            `;
            
            const mainContent = document.querySelector('main .max-w-6xl');
            mainContent.insertBefore(errorDiv, mainContent.firstChild);
            
            // Scroll to top to show error
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            // Remove error after 5 seconds
            setTimeout(() => {
                errorDiv.remove();
            }, 5000);
        }

        // Initialize form functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize vehicle inputs and state code
            updateVehicleInputs();
            updateStateCode();
            
            // Add staggered animation to cards
            const cards = document.querySelectorAll('.animate-fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Form submission handler
            document.getElementById('vendorForm').addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return;
                }
                
                // Add loading state to submit button
                const submitBtn = document.getElementById('submitBtn');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                
                // Re-enable button after 10 seconds as fallback
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }, 10000);
            });
            
            // Real-time validation for specific fields
            document.getElementById('mobile').addEventListener('input', function() {
                const value = this.value;
                if (value.length > 0 && !/^\d+$/.test(value)) {
                    this.setCustomValidity('Please enter only numbers');
                    this.classList.add('border-red-500');
                } else if (value.length > 0 && value.length !== 10) {
                    this.setCustomValidity('Mobile number must be exactly 10 digits');
                    this.classList.add('border-red-500');
                } else {
                    this.setCustomValidity('');
                    this.classList.remove('border-red-500');
                    this.classList.add('border-gray-300');
                }
            });

            document.getElementById('email').addEventListener('input', function() {
                const value = this.value;
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (value.length > 0 && !emailRegex.test(value)) {
                    this.setCustomValidity('Please enter a valid email address');
                    this.classList.add('border-red-500');
                } else {
                    this.setCustomValidity('');
                    this.classList.remove('border-red-500');
                    this.classList.add('border-gray-300');
                }
            });

            // Format inputs to uppercase
            document.getElementById('pan_number').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });

            document.getElementById('gst_number').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });

            document.getElementById('ifsc_code').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
            
            // Reset form functionality
            document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                    e.preventDefault();
                }
            });
        });

        // Auto-save functionality (optional)
        function autoSaveForm() {
            const formData = new FormData(document.getElementById('vendorForm'));
            const data = {};
            
            for (let [key, value] of formData.entries()) {
                if (data[key]) {
                    if (Array.isArray(data[key])) {
                        data[key].push(value);
                    } else {
                        data[key] = [data[key], value];
                    }
                } else {
                    data[key] = value;
                }
            }
            
            localStorage.setItem('vendorFormData', JSON.stringify(data));
        }

        // Auto-save every 30 seconds
        setInterval(autoSaveForm, 30000);
    </script>
</body>
</html>