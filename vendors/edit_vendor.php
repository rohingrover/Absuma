<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

$success = '';
$error = '';
$vendor = [];

// Get vendor ID from URL
$vendorId = $_GET['id'] ?? null;

if (!$vendorId || !is_numeric($vendorId)) {
    header("Location: manage_vendors.php");
    exit();
}

try {
    // Fetch vendor details
    $stmt = $pdo->prepare("
        SELECT * FROM vendors 
        WHERE id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        $_SESSION['error'] = "Vendor not found";
        header("Location: manage_vendors.php");
        exit();
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_vendor'])) {
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
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
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
            
            // Check for duplicate email (excluding current vendor)
            $emailCheck = $pdo->prepare("SELECT id FROM vendors WHERE email = ? AND id != ? AND deleted_at IS NULL");
            $emailCheck->execute([$vendor_data['email'], $vendorId]);
            if ($emailCheck->rowCount() > 0) {
                $errors[] = "Email address already exists";
            }
            
            // Check for duplicate PAN (excluding current vendor)
            $panCheck = $pdo->prepare("SELECT id FROM vendors WHERE pan_number = ? AND id != ? AND deleted_at IS NULL");
            $panCheck->execute([$vendor_data['pan_number'], $vendorId]);
            if ($panCheck->rowCount() > 0) {
                $errors[] = "PAN number already exists";
            }
            
            // Check for duplicate GST (excluding current vendor)
            $gstCheck = $pdo->prepare("SELECT id FROM vendors WHERE gst_number = ? AND id != ? AND deleted_at IS NULL");
            $gstCheck->execute([$vendor_data['gst_number'], $vendorId]);
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
                        
                        // Check if vehicle number already exists in database (excluding current vendor's vehicles)
                        $vehicleCheck = $pdo->prepare("SELECT id FROM vendor_vehicles WHERE REGEXP_REPLACE(vehicle_number, '[ -]', '') = ? AND vendor_id != ? AND deleted_at IS NULL");
                        $vehicleCheck->execute([$normalized_number, $vendorId]);
                        if ($vehicleCheck->rowCount() > 0) {
                            $errors[] = "Vehicle number already exists: {$vehicle_number}";
                        }
                    }
                }
            }
            
            if (!empty($errors)) {
                throw new Exception(implode("<br>", $errors));
            }
            
            // Update vendor data
            $sql = "UPDATE vendors SET
                company_name = :company_name,
                registered_address = :registered_address,
                invoice_address = :invoice_address,
                contact_person = :contact_person,
                mobile = :mobile,
                email = :email,
                constitution_type = :constitution_type,
                business_nature = :business_nature,
                bank_name = :bank_name,
                account_number = :account_number,
                branch_name = :branch_name,
                ifsc_code = :ifsc_code,
                pan_number = :pan_number,
                gst_number = :gst_number,
                state = :state,
                state_code = :state_code,
                vendor_type = :vendor_type,
                total_vehicles = :total_vehicles,
                updated_at = :updated_at
                WHERE id = :id";
            
            $vendor_data['id'] = $vendorId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vendor_data);
            
            // Handle vehicle numbers - Delete existing and add new ones
            $pdo->prepare("UPDATE vendor_vehicles SET deleted_at = NOW() WHERE vendor_id = ?")->execute([$vendorId]);
            
            if (!empty($vehicle_numbers)) {
                foreach ($vehicle_numbers as $vehicle_number) {
                    if (!empty($vehicle_number)) {
                        // Insert vendor vehicle
                        $vehicleStmt = $pdo->prepare("
                            INSERT INTO vendor_vehicles 
                            (vendor_id, vehicle_number, status, created_at) 
                            VALUES (?, ?, 'active', NOW())
                        ");
                        $vehicleStmt->execute([$vendorId, $vehicle_number]);
                    }
                }
            }
            
            // Handle file uploads (optional)
            $upload_dir = 'Uploads/vendor_docs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $documents = [
                'pan_copy' => 'PAN Copy',
                'gst_certificate' => 'GST Certificate',
                'constitution_proof' => 'Constitution Proof',
                'cancelled_cheque' => 'Cancelled Cheque'
            ];
            
            foreach ($documents as $field => $doc_type) {
                if (isset($_FILES[$field]) && $_FILES[$field]['error'] == 0) {
                    // Validate file size (5MB max)
                    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
                        $errors[] = "File size for {$doc_type} must be less than 5MB";
                        continue;
                    }
                    
                    $file_extension = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
                    
                    if (in_array($file_extension, $allowed_extensions)) {
                        $file_name = $vendor['vendor_code'] . '_' . $field . '_' . time() . '.' . $file_extension;
                        $target_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES[$field]['tmp_name'], $target_path)) {
                            // Check if document type already exists, update or insert
                            $checkDoc = $pdo->prepare("SELECT id FROM vendor_documents WHERE vendor_id = ? AND document_type = ? AND deleted_at IS NULL");
                            $checkDoc->execute([$vendorId, $doc_type]);
                            
                            if ($checkDoc->rowCount() > 0) {
                                // Update existing document
                                $sql = "UPDATE vendor_documents SET 
                                    document_name = ?, file_path = ?, file_size = ?, file_type = ?, uploaded_at = NOW()
                                    WHERE vendor_id = ? AND document_type = ? AND deleted_at IS NULL";
                                $pdo->prepare($sql)->execute([
                                    $_FILES[$field]['name'],
                                    $file_name,
                                    $_FILES[$field]['size'],
                                    $file_extension,
                                    $vendorId,
                                    $doc_type
                                ]);
                            } else {
                                // Insert new document
                                $sql = "INSERT INTO vendor_documents (
                                    vendor_id, document_type, document_name, file_path, 
                                    file_size, file_type, uploaded_at
                                ) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                                $pdo->prepare($sql)->execute([
                                    $vendorId, 
                                    $doc_type, 
                                    $_FILES[$field]['name'],
                                    $file_name,
                                    $_FILES[$field]['size'],
                                    $file_extension
                                ]);
                            }
                        }
                    }
                }
            }
            
            $pdo->commit();
            $success = "Vendor updated successfully!";
            
            // Refresh vendor data
            $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$vendorId]);
            $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }

    // Get vendor vehicles for display
    $vehicleStmt = $pdo->prepare("
        SELECT vehicle_number FROM vendor_vehicles 
        WHERE vendor_id = ? AND deleted_at IS NULL 
        ORDER BY created_at ASC
    ");
    $vehicleStmt->execute([$vendorId]);
    $vendor_vehicles = $vehicleStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vendor - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="notifications.js"></script>
    <style>
        .form-grid {
            @apply grid grid-cols-1 md:grid-cols-2 gap-4;
        }
        .form-field {
            @apply space-y-1;
        }
        .form-actions {
            @apply md:col-span-2 pt-2;
        }
        .card-hover-effect {
            @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
        }
        .vehicle-input {
            @apply flex items-center gap-2 mb-2;
        }
        .remove-vehicle {
            @apply text-red-600 hover:text-red-800 cursor-pointer p-1;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-[1340px] mx-auto px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-600 p-3 rounded-lg text-white">
                            <i class="fas fa-edit text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Edit Vendor - <?= htmlspecialchars($vendor['vendor_code'] ?? 'Unknown') ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-50 px-4 py-2 rounded-lg border border-blue-100">
                            <div class="text-blue-800 font-medium"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                        </div>
                        <?php include 'notification_bell.php'; ?>
                        <a href="logout.php" class="text-blue-600 hover:text-blue-800 p-2">
                            <i class="fas fa-sign-out-alt text-lg"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar -->
                <aside class="w-full lg:w-72 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-sm p-4 sticky top-20">
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-blue-600 font-bold mb-3 pl-2 border-l-4 border-blue-600/50">Vendor Section</h3>
                            <nav class="space-y-1">
                                <a href="vendor_registration.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center"></i> Register Vendor
                                </a>
                                <a href="manage_vendors.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
                                    <i class="fas fa-list w-5 text-center"></i> Manage Vendors
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-tachometer-alt w-5 text-center"></i> Dashboard
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div id="alertContainer">
                        <?php if (!empty($success)): ?>
                            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-check-circle text-green-600"></i>
                                    <p><?= htmlspecialchars($success) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($error)): ?>
                            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-exclamation-circle text-red-600"></i>
                                    <div><?= $error ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <!-- Vendor Type & Classification -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-tag text-blue-600"></i> Vendor Classification
                                </h2>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="vendor_type" class="block text-sm font-medium text-gray-700">Vendor Type <span class="text-red-500">*</span></label>
                                        <select name="vendor_type" id="vendor_type" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="">Select Vendor Type</option>
                                            <option value="Transport" <?= ($vendor['vendor_type'] ?? '') === 'Transport' ? 'selected' : '' ?>>Transport</option>
                                            <option value="Maintenance" <?= ($vendor['vendor_type'] ?? '') === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                            <option value="Fuel" <?= ($vendor['vendor_type'] ?? '') === 'Fuel' ? 'selected' : '' ?>>Fuel</option>
                                            <option value="Parts Supplier" <?= ($vendor['vendor_type'] ?? '') === 'Parts Supplier' ? 'selected' : '' ?>>Parts Supplier</option>
                                            <option value="Other" <?= ($vendor['vendor_type'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Vehicle Information Section -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-truck text-blue-600"></i> Vehicle Information
                                </h2>
                                
                                <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mb-6 rounded-lg">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-info-circle text-blue-600"></i>
                                        <div>
                                            <p class="font-medium">Vehicle Registration Information</p>
                                            <p class="text-sm">Update all commercial vehicles that will be operating under our services. These vehicles will be tracked separately for billing and service purposes.</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="total_vehicles" class="block text-sm font-medium text-gray-700">Total Number of Vehicles</label>
                                        <input type="number" name="total_vehicles" id="total_vehicles" min="0" max="50" 
                                               value="<?= htmlspecialchars($vendor['total_vehicles'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter total vehicle count" onchange="updateVehicleCount()">
                                        <p class="text-xs text-gray-500 mt-1">This helps us track your fleet size</p>
                                    </div>
                                </div>

                                <div class="mt-6">
                                    <div class="flex items-center justify-between mb-4">
                                        <label class="block text-sm font-medium text-gray-700">Vehicle Numbers</label>
                                        <button type="button" id="addVehicleBtn" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                            <i class="fas fa-plus mr-2"></i> Add Vehicle
                                        </button>
                                    </div>
                                    
                                    <div id="vehicleContainer" class="space-y-2">
                                        <?php if (!empty($vendor_vehicles)): ?>
                                            <?php foreach ($vendor_vehicles as $index => $vehicle_number): ?>
                                                <div class="vehicle-input">
                                                    <input type="text" name="vehicle_numbers[]" 
                                                           value="<?= htmlspecialchars($vehicle_number) ?>"
                                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                                           placeholder="e.g. UP25GT0880" pattern="^[A-Z]{2}[0-9]{1,2}[A-Z]{1,2}[0-9]{4}$"
                                                           title="Format: Two letters, 1-2 digits, 1-2 letters, 4 digits">
                                                    <button type="button" class="remove-vehicle" onclick="removeVehicle(this)" style="<?= $index === 0 ? 'display: none;' : '' ?>">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="vehicle-input">
                                                <input type="text" name="vehicle_numbers[]" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                                       placeholder="e.g. UP25GT0880" pattern="^[A-Z]{2}[0-9]{1,2}[A-Z]{1,2}[0-9]{4}$"
                                                       title="Format: Two letters, 1-2 digits, 1-2 letters, 4 digits">
                                                <button type="button" class="remove-vehicle" onclick="removeVehicle(this)" style="display: none;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="text-xs text-gray-500 mt-2">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Vehicle number format: Two letters (state code), 1-2 digits (district code), 1-2 letters (series), 4 digits (unique number)
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- General Information -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-info-circle text-blue-600"></i> General Information
                                </h2>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="company_name" class="block text-sm font-medium text-gray-700">Company Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="company_name" id="company_name" required 
                                               value="<?= htmlspecialchars($vendor['company_name'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter company name">
                                    </div>
                                    <div class="form-field">
                                        <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="contact_person" id="contact_person" required 
                                               value="<?= htmlspecialchars($vendor['contact_person'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter contact person name">
                                    </div>
                                    <div class="form-field">
                                        <label for="mobile" class="block text-sm font-medium text-gray-700">Mobile No. <span class="text-red-500">*</span></label>
                                        <input type="tel" name="mobile" id="mobile" required 
                                               value="<?= htmlspecialchars($vendor['mobile'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter mobile number" pattern="[0-9]{10}">
                                    </div>
                                    <div class="form-field">
                                        <label for="email" class="block text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></label>
                                        <input type="email" name="email" id="email" required 
                                               value="<?= htmlspecialchars($vendor['email'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter email address">
                                    </div>
                                   
                                    <div class="form-field">
                                        <label for="constitution_type" class="block text-sm font-medium text-gray-700">Constitution <span class="text-red-500">*</span></label>
                                        <select name="constitution_type" id="constitution_type" required 
                                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="">Select Constitution Type</option>
                                            <option value="Sole Proprietor" <?= ($vendor['constitution_type'] ?? '') === 'Sole Proprietor' ? 'selected' : '' ?>>Sole Proprietor</option>
                                            <option value="Partnership" <?= ($vendor['constitution_type'] ?? '') === 'Partnership' ? 'selected' : '' ?>>Partnership</option>
                                            <option value="Private Limited" <?= ($vendor['constitution_type'] ?? '') === 'Private Limited' ? 'selected' : '' ?>>Private Limited</option>
                                            <option value="Public Limited" <?= ($vendor['constitution_type'] ?? '') === 'Public Limited' ? 'selected' : '' ?>>Public Limited</option>
                                            <option value="Public Sector" <?= ($vendor['constitution_type'] ?? '') === 'Public Sector' ? 'selected' : '' ?>>Public Sector</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="mt-6">
                                    <label for="registered_address" class="block text-sm font-medium text-gray-700">Address <span class="text-red-500">*</span></label>
                                    <textarea name="registered_address" id="registered_address" required rows="3" 
                                              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                              placeholder="Enter complete address"><?= htmlspecialchars($vendor['registered_address'] ?? '') ?></textarea>
                                </div>
                                
                                <div class="mt-4">
                                    <label for="invoice_address" class="block text-sm font-medium text-gray-700">Invoice Address (for GST) <span class="text-red-500">*</span></label>
                                    <div class="flex items-center gap-3 mb-2">
                                        <input type="checkbox" id="same_address" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        <label for="same_address" class="text-sm text-gray-700">Same as registered address</label>
                                    </div>
                                    <textarea name="invoice_address" id="invoice_address" required rows="3" 
                                              class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                              placeholder="Enter invoice address"><?= htmlspecialchars($vendor['invoice_address'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Business Nature -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-briefcase text-blue-600"></i> Nature of Business
                                </h2>
                                <div class="form-field">
                                    <label for="business_nature" class="block text-sm font-medium text-gray-700">Select Business Nature <span class="text-red-500">*</span></label>
                                    <select name="business_nature" id="business_nature" required 
                                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Select Nature</option>
                                        <option value="Transport Contractor" <?= ($vendor['business_nature'] ?? '') === 'Transport Contractor' ? 'selected' : '' ?>>Transport Contractor</option>
                                        <option value="Logistics Provider" <?= ($vendor['business_nature'] ?? '') === 'Logistics Provider' ? 'selected' : '' ?>>Logistics Provider</option>
                                        <option value="Other" <?= ($vendor['business_nature'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Bank Details -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-university text-blue-600"></i> Bank Details
                                </h2>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="bank_name" class="block text-sm font-medium text-gray-700">Bank Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="bank_name" id="bank_name" required 
                                               value="<?= htmlspecialchars($vendor['bank_name'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter bank name">
                                    </div>
                                    <div class="form-field">
                                        <label for="account_number" class="block text-sm font-medium text-gray-700">Account Number <span class="text-red-500">*</span></label>
                                        <input type="text" name="account_number" id="account_number" required 
                                               value="<?= htmlspecialchars($vendor['account_number'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter account number">
                                    </div>
                                    <div class="form-field">
                                        <label for="branch_name" class="block text-sm font-medium text-gray-700">Branch Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="branch_name" id="branch_name" required 
                                               value="<?= htmlspecialchars($vendor['branch_name'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter branch name">
                                    </div>
                                    <div class="form-field">
                                        <label for="ifsc_code" class="block text-sm font-medium text-gray-700">IFSC Code <span class="text-red-500">*</span></label>
                                        <input type="text" name="ifsc_code" id="ifsc_code" required 
                                               value="<?= htmlspecialchars($vendor['ifsc_code'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter IFSC code" pattern="[A-Z]{4}0[A-Z0-9]{6}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Details -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-file-invoice text-blue-600"></i> Tax Details
                                </h2>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="pan_number" class="block text-sm font-medium text-gray-700">PAN Number <span class="text-red-500">*</span></label>
                                        <input type="text" name="pan_number" id="pan_number" required 
                                               value="<?= htmlspecialchars($vendor['pan_number'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter PAN number" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}">
                                    </div>
                                    <div class="form-field">
                                        <label for="gst_number" class="block text-sm font-medium text-gray-700">GST Number <span class="text-red-500">*</span></label>
                                        <input type="text" name="gst_number" id="gst_number" required 
                                               value="<?= htmlspecialchars($vendor['gst_number'] ?? '') ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter GST number">
                                    </div>
                                   
                                    <div class="form-field">
                                        <label for="state" class="block text-sm font-medium text-gray-700">State <span class="text-red-500">*</span></label>
                                        <select name="state" id="state_select" required 
                                                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="">Select State</option>
                                            <option value="Andhra Pradesh" data-code="AP" <?= ($vendor['state'] ?? '') === 'Andhra Pradesh' ? 'selected' : '' ?>>Andhra Pradesh</option>
                                            <option value="Bihar" data-code="BR" <?= ($vendor['state'] ?? '') === 'Bihar' ? 'selected' : '' ?>>Bihar</option>
                                            <option value="Delhi" data-code="DL" <?= ($vendor['state'] ?? '') === 'Delhi' ? 'selected' : '' ?>>Delhi</option>
                                            <option value="Gujarat" data-code="GJ" <?= ($vendor['state'] ?? '') === 'Gujarat' ? 'selected' : '' ?>>Gujarat</option>
                                            <option value="Haryana" data-code="HR" <?= ($vendor['state'] ?? '') === 'Haryana' ? 'selected' : '' ?>>Haryana</option>
                                            <option value="Karnataka" data-code="KA" <?= ($vendor['state'] ?? '') === 'Karnataka' ? 'selected' : '' ?>>Karnataka</option>
                                            <option value="Maharashtra" data-code="MH" <?= ($vendor['state'] ?? '') === 'Maharashtra' ? 'selected' : '' ?>>Maharashtra</option>
                                            <option value="Punjab" data-code="PB" <?= ($vendor['state'] ?? '') === 'Punjab' ? 'selected' : '' ?>>Punjab</option>
                                            <option value="Rajasthan" data-code="RJ" <?= ($vendor['state'] ?? '') === 'Rajasthan' ? 'selected' : '' ?>>Rajasthan</option>
                                            <option value="Tamil Nadu" data-code="TN" <?= ($vendor['state'] ?? '') === 'Tamil Nadu' ? 'selected' : '' ?>>Tamil Nadu</option>
                                            <option value="Uttar Pradesh" data-code="UP" <?= ($vendor['state'] ?? '') === 'Uttar Pradesh' ? 'selected' : '' ?>>Uttar Pradesh</option>
                                            <option value="West Bengal" data-code="WB" <?= ($vendor['state'] ?? '') === 'West Bengal' ? 'selected' : '' ?>>West Bengal</option>
                                        </select>
                                        <input type="hidden" name="state_code" id="state_code" value="<?= htmlspecialchars($vendor['state_code'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Document Upload (Optional) -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-paperclip text-blue-600"></i> Document Upload <span class="text-sm text-gray-500 font-normal">(Optional - Will replace existing documents)</span>
                                </h2>
                                
                                <div class="bg-orange-50 border-l-4 border-orange-500 text-orange-700 p-4 mb-6 rounded-lg">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-info-circle text-orange-600"></i>
                                        <div>
                                            <p class="font-medium">Document Update Guidelines</p>
                                            <p class="text-sm">Uploading new documents will replace existing ones. Use the document management section to view current documents. Accepted formats: JPG, PNG, PDF (Max 5MB each)</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="pan_copy" class="block text-sm font-medium text-gray-700">Copy of PAN</label>
                                        <input type="file" name="pan_copy" id="pan_copy" 
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               accept=".jpg,.jpeg,.png,.pdf">
                                        <p class="text-xs text-gray-500 mt-1">Accepted: JPG, PNG, PDF (Max 5MB)</p>
                                    </div>
                                    <div class="form-field">
                                        <label for="gst_certificate" class="block text-sm font-medium text-gray-700">GST Registration Certificate</label>
                                        <input type="file" name="gst_certificate" id="gst_certificate" 
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               accept=".jpg,.jpeg,.png,.pdf">
                                        <p class="text-xs text-gray-500 mt-1">Accepted: JPG, PNG, PDF (Max 5MB)</p>
                                    </div>
                                    <div class="form-field">
                                        <label for="constitution_proof" class="block text-sm font-medium text-gray-700">Proof of Constitution</label>
                                        <input type="file" name="constitution_proof" id="constitution_proof" 
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               accept=".jpg,.jpeg,.png,.pdf">
                                        <p class="text-xs text-gray-500 mt-1">MOA for Limited companies</p>
                                    </div>
                                    <div class="form-field">
                                        <label for="cancelled_cheque" class="block text-sm font-medium text-gray-700">Cancelled Cheque/Bank Letter</label>
                                        <input type="file" name="cancelled_cheque" id="cancelled_cheque" 
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               accept=".jpg,.jpeg,.png,.pdf">
                                        <p class="text-xs text-gray-500 mt-1">For bank account verification</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="manage_vendors.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Cancel
                                    </a>
                                    <button type="submit" name="update_vendor" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-save mr-2"></i> Update Vendor
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </main>
            </div>
        </div>
    </div>

    <script>
        // Vehicle management functions
        let vehicleCount = <?= count($vendor_vehicles) ?: 1 ?>;

        function addVehicle() {
            vehicleCount++;
            const container = document.getElementById('vehicleContainer');
            const vehicleInput = document.createElement('div');
            vehicleInput.className = 'vehicle-input';
            vehicleInput.innerHTML = `
                <input type="text" name="vehicle_numbers[]" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="e.g. UP25GT0880" pattern="^[A-Z]{2}[0-9]{1,2}[A-Z]{1,2}[0-9]{4}$"
                       title="Format: Two letters, 1-2 digits, 1-2 letters, 4 digits">
                <button type="button" class="remove-vehicle" onclick="removeVehicle(this)">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(vehicleInput);
            
            // Update total vehicles count
            document.getElementById('total_vehicles').value = vehicleCount;
            
            // Show remove buttons if more than one vehicle
            toggleRemoveButtons();
        }

        function removeVehicle(button) {
            button.parentElement.remove();
            vehicleCount--;
            
            // Update total vehicles count
            document.getElementById('total_vehicles').value = vehicleCount;
            
            // Hide remove buttons if only one vehicle left
            toggleRemoveButtons();
        }

        function toggleRemoveButtons() {
            const removeButtons = document.querySelectorAll('.remove-vehicle');
            removeButtons.forEach(button => {
                button.style.display = vehicleCount > 1 ? 'block' : 'none';
            });
        }

        function updateVehicleCount() {
            const totalVehicles = parseInt(document.getElementById('total_vehicles').value) || 0;
            const container = document.getElementById('vehicleContainer');
            const currentInputs = container.querySelectorAll('.vehicle-input').length;
            
            if (totalVehicles > currentInputs) {
                // Add more inputs
                for (let i = currentInputs; i < totalVehicles; i++) {
                    addVehicle();
                }
            } else if (totalVehicles < currentInputs && totalVehicles > 0) {
                // Remove excess inputs
                const inputs = container.querySelectorAll('.vehicle-input');
                for (let i = currentInputs - 1; i >= totalVehicles; i--) {
                    inputs[i].remove();
                    vehicleCount--;
                }
            }
            
            vehicleCount = Math.max(totalVehicles, 1);
            toggleRemoveButtons();
        }

        // Event listeners
        document.getElementById('addVehicleBtn').addEventListener('click', addVehicle);

        // Vehicle number formatting
        document.addEventListener('input', function(e) {
            if (e.target.name === 'vehicle_numbers[]') {
                e.target.value = e.target.value.toUpperCase();
            }
        });

        // Handle same address checkbox
        document.getElementById('same_address').addEventListener('change', function() {
            const regAddress = document.querySelector('[name="registered_address"]').value;
            const invAddress = document.getElementById('invoice_address');
            
            if (this.checked) {
                invAddress.value = regAddress;
                invAddress.readOnly = true;
            } else {
                invAddress.readOnly = false;
            }
        });

        // Update registered address when changed if checkbox is checked
        document.querySelector('[name="registered_address"]').addEventListener('input', function() {
            if (document.getElementById('same_address').checked) {
                document.getElementById('invoice_address').value = this.value;
            }
        });

        // Handle state code
        document.getElementById('state_select').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const stateCode = selectedOption.getAttribute('data-code');
            document.getElementById('state_code').value = stateCode || '';
        });

        // File size validation
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const file = this.files[0];
                if (file && file.size > 5 * 1024 * 1024) { // 5MB
                    alert('File size must be less than 5MB');
                    this.value = '';
                }
            });
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const pan = document.querySelector('[name="pan_number"]').value;
            const ifsc = document.querySelector('[name="ifsc_code"]').value;
            
            // PAN validation
            if (!/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan)) {
                e.preventDefault();
                alert('Invalid PAN format. Should be like: ABCDE1234F');
                return;
            }
            
            // IFSC validation
            if (!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifsc)) {
                e.preventDefault();
                alert('Invalid IFSC format. Should be like: ABCD0123456');
                return;
            }

            // Vehicle number validation
            const vehicleNumbers = document.querySelectorAll('[name="vehicle_numbers[]"]');
            const vehiclePattern = /^[A-Z]{2}[0-9]{1,2}[A-Z]{1,2}[0-9]{4}$/;
            const usedNumbers = new Set();
            
            for (let input of vehicleNumbers) {
                const value = input.value.trim();
                if (value) {
                    const normalized = value.replace(/[ -]/g, '').toUpperCase();
                    
                    if (!vehiclePattern.test(normalized)) {
                        e.preventDefault();
                        alert(`Invalid vehicle number format: ${value}. Should be like: UP25GT0880`);
                        input.focus();
                        return;
                    }
                    
                    if (usedNumbers.has(normalized)) {
                        e.preventDefault();
                        alert(`Duplicate vehicle number: ${value}`);
                        input.focus();
                        return;
                    }
                    
                    usedNumbers.add(normalized);
                }
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            toggleRemoveButtons();
            
            // Set state code on page load if state is already selected
            const stateSelect = document.getElementById('state_select');
            if (stateSelect.value) {
                const selectedOption = stateSelect.options[stateSelect.selectedIndex];
                const stateCode = selectedOption.getAttribute('data-code');
                document.getElementById('state_code').value = stateCode || '';
            }
        });
    </script>
</body>
</html>