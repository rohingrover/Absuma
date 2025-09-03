<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Collect general info
        $client_data = [
            'client_name' => trim($_POST['client_name']),
            'contact_person' => trim($_POST['contact_person']),
            'phone_number' => trim($_POST['phone_number']),
            'email_address' => trim($_POST['email_address']),
            'billing_cycle_days' => (int)$_POST['billing_cycle_days'],
            'pan_number' => strtoupper(trim($_POST['pan_number'])),
            'gst_number' => strtoupper(trim($_POST['gst_number'])),
            'created_by' => $_SESSION['user_id'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Validation
        $errors = [];
        
        if (empty($client_data['client_name'])) {
            $errors[] = "Client name is required";
        }
        
        if (empty($client_data['contact_person'])) {
            $errors[] = "Contact person name is required";
        }
        
        if (empty($client_data['phone_number']) || !preg_match('/^[0-9]{10}$/', $client_data['phone_number'])) {
            $errors[] = "Valid 10-digit phone number is required";
        }
        
        if (empty($client_data['email_address']) || !filter_var($client_data['email_address'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required";
        }
        
        if ($client_data['billing_cycle_days'] < 1 || $client_data['billing_cycle_days'] > 365) {
            $errors[] = "Billing cycle must be between 1 and 365 days";
        }
        
        if (!empty($client_data['pan_number']) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $client_data['pan_number'])) {
            $errors[] = "Invalid PAN number format (e.g., ABCDE1234F)";
        }
        
        if (!empty($client_data['gst_number']) && strlen($client_data['gst_number']) !== 15) {
            $errors[] = "GST number must be 15 characters long";
        }
        
        // Check for duplicate email
        $emailCheck = $pdo->prepare("SELECT id FROM clients WHERE email_address = ? AND deleted_at IS NULL");
        $emailCheck->execute([$client_data['email_address']]);
        if ($emailCheck->rowCount() > 0) {
            $errors[] = "Email address already exists";
        }
        
        // Check for duplicate PAN if provided
        if (!empty($client_data['pan_number'])) {
            $panCheck = $pdo->prepare("SELECT id FROM clients WHERE pan_number = ? AND deleted_at IS NULL");
            $panCheck->execute([$client_data['pan_number']]);
            if ($panCheck->rowCount() > 0) {
                $errors[] = "PAN number already exists";
            }
        }
        
        if (!empty($errors)) {
            throw new Exception(implode("<br>", $errors));
        }
        
        // Insert client data
        $sql = "INSERT INTO clients (
            client_name, contact_person, phone_number, email_address, 
            billing_cycle_days, pan_number, gst_number, status, 
            created_by, created_at
        ) VALUES (
            :client_name, :contact_person, :phone_number, :email_address,
            :billing_cycle_days, :pan_number, :gst_number, 'active',
            :created_by, :created_at
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($client_data);
        $client_id = $pdo->lastInsertId();
        
        // Generate client code
        $client_code = 'CLT' . str_pad($client_id, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE clients SET client_code = ? WHERE id = ?")
             ->execute([$client_code, $client_id]);
        
        // Handle contractual rates for 20ft containers
        if (isset($_POST['movement_types_20ft']) && is_array($_POST['movement_types_20ft'])) {
            foreach ($_POST['movement_types_20ft'] as $index => $movement_type) {
                if (!empty($movement_type) && !empty($_POST['rates_20ft'][$index])) {
                    $container_type = $_POST['container_types_20ft'][$index] ?? '';
                    $import_export = $_POST['import_export_20ft'][$index] ?? '';
                    $rate = (float)$_POST['rates_20ft'][$index];
                    $remarks = trim($_POST['remarks_20ft'][$index] ?? '');
                    
                    $rateStmt = $pdo->prepare("
                        INSERT INTO client_rates 
                        (client_id, container_size, movement_type, container_type, import_export, rate, remarks, created_at) 
                        VALUES (?, '20ft', ?, ?, ?, ?, ?, NOW())
                    ");
                    $rateStmt->execute([$client_id, $movement_type, $container_type, $import_export, $rate, $remarks]);
                }
            }
        }
        
        // Handle contractual rates for 40ft containers
        if (isset($_POST['movement_types_40ft']) && is_array($_POST['movement_types_40ft'])) {
            foreach ($_POST['movement_types_40ft'] as $index => $movement_type) {
                if (!empty($movement_type) && !empty($_POST['rates_40ft'][$index])) {
                    $container_type = $_POST['container_types_40ft'][$index] ?? '';
                    $import_export = $_POST['import_export_40ft'][$index] ?? '';
                    $rate = (float)$_POST['rates_40ft'][$index];
                    $remarks = trim($_POST['remarks_40ft'][$index] ?? '');
                    
                    $rateStmt = $pdo->prepare("
                        INSERT INTO client_rates 
                        (client_id, container_size, movement_type, container_type, import_export, rate, remarks, created_at) 
                        VALUES (?, '40ft', ?, ?, ?, ?, ?, NOW())
                    ");
                    $rateStmt->execute([$client_id, $movement_type, $container_type, $import_export, $rate, $remarks]);
                }
            }
        }
        
        $pdo->commit();
        $success = "Client registered successfully! Client Code: " . $client_code;
        
        // Clear form data on success
        $_POST = [];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Pre-fill form data if there was an error
$formData = [
    'client_name' => $_POST['client_name'] ?? '',
    'contact_person' => $_POST['contact_person'] ?? '',
    'phone_number' => $_POST['phone_number'] ?? '',
    'email_address' => $_POST['email_address'] ?? '',
    'billing_cycle_days' => $_POST['billing_cycle_days'] ?? 30,
    'pan_number' => $_POST['pan_number'] ?? '',
    'gst_number' => $_POST['gst_number'] ?? ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client - Fleet Management</title>
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
        .card-hover-effect {
            @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
        }
        .rate-row {
            @apply grid grid-cols-12 gap-2 items-end mb-3 p-3 bg-gray-50 rounded-lg;
        }
        .rate-row:hover {
            @apply bg-gray-100;
        }
        .remove-rate {
            @apply text-red-600 hover:text-red-800 cursor-pointer p-1 rounded hover:bg-red-100;
        }
		.bg-blue-25 {
		background-color: rgba(59, 130, 246, 0.05);
		}

		.bg-green-25 {
			background-color: rgba(34, 197, 94, 0.05);
		}

		.location-field {
			transition: opacity 0.3s ease;
		}

		.rate-row {
			transition: all 0.3s ease;
		}

		.rate-row:hover {
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
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
                            <i class="fas fa-user-tie text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Add New Client</p>
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
                            <h3 class="text-xs uppercase tracking-wider text-blue-600 font-bold mb-3 pl-2 border-l-4 border-blue-600/50">Client Section</h3>
                            <nav class="space-y-1">
                                <a href="add_client.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
                                    <i class="fas fa-plus w-5 text-center"></i> Add New Client
                                </a>
                                <a href="manage_clients.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center"></i> Manage Clients
                                </a>
                                <a href="client_reports.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-chart-line w-5 text-center"></i> Client Reports
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

                    <form method="POST" class="space-y-6">
                        <!-- General Information -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-info-circle text-blue-600"></i> General Information
                                </h2>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="client_name" class="block text-sm font-medium text-gray-700">Client Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="client_name" id="client_name" required 
                                               value="<?= htmlspecialchars($formData['client_name']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter client/company name">
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person Name <span class="text-red-500">*</span></label>
                                        <input type="text" name="contact_person" id="contact_person" required 
                                               value="<?= htmlspecialchars($formData['contact_person']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter contact person name">
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="phone_number" class="block text-sm font-medium text-gray-700">Phone Number <span class="text-red-500">*</span></label>
                                        <input type="tel" name="phone_number" id="phone_number" required 
                                               value="<?= htmlspecialchars($formData['phone_number']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter 10-digit phone number" pattern="[0-9]{10}">
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="email_address" class="block text-sm font-medium text-gray-700">Email Address <span class="text-red-500">*</span></label>
                                        <input type="email" name="email_address" id="email_address" required 
                                               value="<?= htmlspecialchars($formData['email_address']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter email address">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Billing Information -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                    <i class="fas fa-file-invoice text-blue-600"></i> Billing Information
                                </h2>
                                <div class="form-grid">
                                    <div class="form-field">
                                        <label for="billing_cycle_days" class="block text-sm font-medium text-gray-700">Billing Cycle (Days) <span class="text-red-500">*</span></label>
                                        <input type="number" name="billing_cycle_days" id="billing_cycle_days" required min="1" max="365"
                                               value="<?= htmlspecialchars($formData['billing_cycle_days']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="e.g. 30">
                                        <p class="text-xs text-gray-500 mt-1">Number of days between billing cycles (1-365)</p>
                                    </div>
                                    
                                    <div class="form-field">
                                        <label for="pan_number" class="block text-sm font-medium text-gray-700">PAN Number</label>
                                        <input type="text" name="pan_number" id="pan_number" 
                                               value="<?= htmlspecialchars($formData['pan_number']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="e.g. ABCDE1234F" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}">
                                    </div>
                                    
                                    <div class="form-field md:col-span-2">
                                        <label for="gst_number" class="block text-sm font-medium text-gray-700">GST Number</label>
                                        <input type="text" name="gst_number" id="gst_number" 
                                               value="<?= htmlspecialchars($formData['gst_number']) ?>"
                                               class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                               placeholder="Enter 15-character GST number" maxlength="15">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contractual Rates for 20ft Containers -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
    <div class="p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
            <i class="fas fa-money-bill-wave text-green-600"></i> Rate Configuration
            <span class="text-sm font-normal text-gray-500 ml-2">(Optional - Add rates only for container sizes you work with)</span>
        </h2>

        <!-- Container Size Selection -->
        <div class="mb-6 p-4 bg-gray-50 rounded-lg">
            <h3 class="text-md font-medium text-gray-700 mb-3">Select Container Sizes</h3>
            <div class="flex gap-4">
                <label class="flex items-center">
                    <input type="checkbox" id="enable20ft" class="mr-2 text-blue-600 focus:ring-blue-500" onchange="toggleContainerSection('20ft')">
                    <span class="text-sm font-medium">20ft Containers</span>
                </label>
                <label class="flex items-center">
                    <input type="checkbox" id="enable40ft" class="mr-2 text-blue-600 focus:ring-blue-500" onchange="toggleContainerSection('40ft')">
                    <span class="text-sm font-medium">40ft Containers</span>
                </label>
            </div>
            <p class="text-xs text-gray-600 mt-2">Select only the container sizes this client works with</p>
        </div>

        <!-- 20ft Container Rates Section -->
        <div id="rates20ftSection" class="rate-section mb-6" style="display: none;">
            <div class="flex justify-between items-center mb-4 p-3 bg-blue-50 rounded-lg">
                <h3 class="text-md font-semibold text-blue-800">20ft Container Rates</h3>
                <button type="button" id="add20ftRate" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700 focus:outline-none">
                    <i class="fas fa-plus mr-1"></i> Add Rate
                </button>
            </div>
            <div id="rates20ftContainer" class="space-y-4">
                <!-- Rates will be added dynamically -->
            </div>
        </div>

        <!-- 40ft Container Rates Section -->
        <div id="rates40ftSection" class="rate-section mb-6" style="display: none;">
            <div class="flex justify-between items-center mb-4 p-3 bg-green-50 rounded-lg">
                <h3 class="text-md font-semibold text-green-800">40ft Container Rates</h3>
                <button type="button" id="add40ftRate" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-green-600 hover:bg-green-700 focus:outline-none">
                    <i class="fas fa-plus mr-1"></i> Add Rate
                </button>
            </div>
            <div id="rates40ftContainer" class="space-y-4">
                <!-- Rates will be added dynamically -->
            </div>
        </div>

        <div class="text-sm text-gray-600 bg-yellow-50 p-3 rounded-lg">
            <i class="fas fa-info-circle text-yellow-600 mr-1"></i>
            <strong>Note:</strong> For Long Distance movements, From and To locations are mandatory. 
            Local movements within the same city may not require specific locations.
        </div>
    </div>
</div>

                        <!-- Form Actions -->
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                            <div class="p-6">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="dashboard.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Cancel
                                    </a>
                                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-save mr-2"></i> Add Client
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
// Updated JavaScript for flexible rate management with locations
let rate20ftCount = 0;
let rate40ftCount = 0;

// Toggle container size sections
function toggleContainerSection(containerSize) {
    const checkbox = document.getElementById(`enable${containerSize}`);
    const section = document.getElementById(`rates${containerSize}Section`);
    const container = document.getElementById(`rates${containerSize}Container`);
    
    if (checkbox.checked) {
        section.style.display = 'block';
        // Add initial rate row if none exists
        if (container.children.length === 0) {
            if (containerSize === '20ft') {
                addRate20ft();
            } else {
                addRate40ft();
            }
        }
    } else {
        section.style.display = 'none';
        // Clear all rates for this container size
        container.innerHTML = '';
        if (containerSize === '20ft') {
            rate20ftCount = 0;
        } else {
            rate40ftCount = 0;
        }
    }
}

// Add 20ft rate row
function addRate20ft() {
    rate20ftCount++;
    const container = document.getElementById('rates20ftContainer');
    const rateRow = document.createElement('div');
    rateRow.className = 'rate-row p-4 border border-blue-200 rounded-lg bg-blue-25';
    rateRow.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Movement Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Movement Type *</label>
                <select name="rates_20ft[${rate20ftCount}][movement_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="handleMovementTypeChange(this)">
                    <option value="">Select Movement</option>
                    <option value="export">Export</option>
                    <option value="import">Import</option>
                    <option value="domestic">Port Movement</option>
                    <option value="local">Long Distance</option>
                </select>
            </div>

            <!-- Container Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Container Type *</label>
                <select name="rates_20ft[${rate20ftCount}][container_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Select Type</option>
                    <option value="full">Full Container</option>
                    <option value="empty">Empty Container</option>
                </select>
            </div>

            <!-- From Location -->
            <div class="location-field">
                <label class="block text-xs font-medium text-gray-700 mb-1">From Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <input type="text" name="rates_20ft[${rate20ftCount}][from_location]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 location-input"
                       placeholder="e.g., Mumbai Port, JNPT">
            </div>

            <!-- To Location -->
            <div class="location-field">
                <label class="block text-xs font-medium text-gray-700 mb-1">To Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <input type="text" name="rates_20ft[${rate20ftCount}][to_location]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 location-input"
                       placeholder="e.g., Pune, Nashik">
            </div>

            <!-- Rate -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Rate (₹) *</label>
                <input type="number" name="rates_20ft[${rate20ftCount}][rate]" required min="0" step="0.01"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Enter rate">
            </div>
        </div>

        <!-- Additional Details Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective From</label>
                <input type="date" name="rates_20ft[${rate20ftCount}][effective_from]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective To</label>
                <input type="date" name="rates_20ft[${rate20ftCount}][effective_to]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>

        <div class="mt-4">
            <label class="block text-xs font-medium text-gray-700 mb-1">Remarks</label>
            <input type="text" name="rates_20ft[${rate20ftCount}][remarks]" 
                   class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="Any additional notes about this rate">
        </div>

        <div class="flex justify-end mt-4">
            <button type="button" onclick="removeRate20ft(this)" class="remove-rate inline-flex items-center px-2 py-1 border border-red-300 text-xs font-medium rounded text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none" ${rate20ftCount === 1 ? 'style="display: none;"' : ''}>
                <i class="fas fa-trash mr-1"></i> Remove
            </button>
        </div>
    `;
    container.appendChild(rateRow);
    toggleRemoveButtons20ft();
}

// Add 40ft rate row
function addRate40ft() {
    rate40ftCount++;
    const container = document.getElementById('rates40ftContainer');
    const rateRow = document.createElement('div');
    rateRow.className = 'rate-row p-4 border border-green-200 rounded-lg bg-green-25';
    rateRow.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Movement Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Movement Type *</label>
                <select name="rates_40ft[${rate40ftCount}][movement_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500" onchange="handleMovementTypeChange(this)">
                    <option value="">Select Movement</option>
                    <option value="export">Export</option>
                    <option value="import">Import</option>
                    <option value="domestic">Domestic</option>
                    <option value="local">Local</option>
                </select>
            </div>

            <!-- Container Type -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Container Type *</label>
                <select name="rates_40ft[${rate40ftCount}][container_type]" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <option value="">Select Type</option>
                    <option value="full">Full Container</option>
                    <option value="empty">Empty Container</option>
                </select>
            </div>

            <!-- From Location -->
            <div class="location-field">
                <label class="block text-xs font-medium text-gray-700 mb-1">From Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <input type="text" name="rates_40ft[${rate40ftCount}][from_location]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500 location-input"
                       placeholder="e.g., Mumbai Port, JNPT">
            </div>

            <!-- To Location -->
            <div class="location-field">
                <label class="block text-xs font-medium text-gray-700 mb-1">To Location <span class="location-required text-red-500" style="display: none;">*</span></label>
                <input type="text" name="rates_40ft[${rate40ftCount}][to_location]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500 location-input"
                       placeholder="e.g., Pune, Nashik">
            </div>

            <!-- Rate -->
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Rate (₹) *</label>
                <input type="number" name="rates_40ft[${rate40ftCount}][rate]" required min="0" step="0.01"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500"
                       placeholder="Enter rate">
            </div>
        </div>

        <!-- Additional Details Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective From</label>
                <input type="date" name="rates_40ft[${rate40ftCount}][effective_from]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Effective To</label>
                <input type="date" name="rates_40ft[${rate40ftCount}][effective_to]" 
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
        </div>

        <div class="mt-4">
            <label class="block text-xs font-medium text-gray-700 mb-1">Remarks</label>
            <input type="text" name="rates_40ft[${rate40ftCount}][remarks]" 
                   class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500 focus:border-green-500"
                   placeholder="Any additional notes about this rate">
        </div>

        <div class="flex justify-end mt-4">
            <button type="button" onclick="removeRate40ft(this)" class="remove-rate inline-flex items-center px-2 py-1 border border-red-300 text-xs font-medium rounded text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none" ${rate40ftCount === 1 ? 'style="display: none;"' : ''}>
                <i class="fas fa-trash mr-1"></i> Remove
            </button>
        </div>
    `;
    container.appendChild(rateRow);
    toggleRemoveButtons40ft();
}

// Handle movement type changes - show/hide location requirement
function handleMovementTypeChange(selectElement) {
    const rateRow = selectElement.closest('.rate-row');
    const locationFields = rateRow.querySelectorAll('.location-field');
    const locationInputs = rateRow.querySelectorAll('.location-input');
    const locationRequired = rateRow.querySelectorAll('.location-required');
    const movementType = selectElement.value;
    
    // Show location requirement for long distance movements
    const requiresLocation = ['export', 'import', 'domestic'].includes(movementType);
    
    locationRequired.forEach(span => {
        span.style.display = requiresLocation ? 'inline' : 'none';
    });
    
    locationInputs.forEach(input => {
        if (requiresLocation) {
            input.setAttribute('required', '');
            input.style.borderColor = '#d1d5db';
        } else {
            input.removeAttribute('required');
            input.value = ''; // Clear location values for local movements
            input.style.borderColor = '#d1d5db';
        }
    });
    
    // Add visual feedback
    locationFields.forEach(field => {
        if (requiresLocation) {
            field.style.opacity = '1';
        } else {
            field.style.opacity = '0.6';
        }
    });
}

// Remove rate functions
function removeRate20ft(button) {
    button.closest('.rate-row').remove();
    rate20ftCount--;
    toggleRemoveButtons20ft();
}

function removeRate40ft(button) {
    button.closest('.rate-row').remove();
    rate40ftCount--;
    toggleRemoveButtons40ft();
}

// Toggle remove buttons visibility
function toggleRemoveButtons20ft() {
    const removeButtons = document.querySelectorAll('#rates20ftContainer .remove-rate');
    removeButtons.forEach(button => {
        button.style.display = rate20ftCount > 1 ? 'block' : 'none';
    });
}

function toggleRemoveButtons40ft() {
    const removeButtons = document.querySelectorAll('#rates40ftContainer .remove-rate');
    removeButtons.forEach(button => {
        button.style.display = rate40ftCount > 1 ? 'block' : 'none';
    });
}

// Form validation enhancement
document.addEventListener('DOMContentLoaded', function() {
    // Add button listeners
    document.getElementById('add20ftRate').addEventListener('click', addRate20ft);
    document.getElementById('add40ftRate').addEventListener('click', addRate40ft);

    // Enhanced form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        let hasValidRates = false;
        
        // Check if at least one container size is enabled and has valid rates
        const enable20ft = document.getElementById('enable20ft').checked;
        const enable40ft = document.getElementById('enable40ft').checked;
        
        if (enable20ft || enable40ft) {
            // Check for movement type and location validation
            const allRateRows = document.querySelectorAll('.rate-row');
            
            for (let row of allRateRows) {
                const movementType = row.querySelector('select[name*="movement_type"]').value;
                const containerType = row.querySelector('select[name*="container_type"]').value;
                const rate = row.querySelector('input[name*="rate"]').value;
                const fromLocation = row.querySelector('input[name*="from_location"]').value;
                const toLocation = row.querySelector('input[name*="to_location"]').value;
                
                if (movementType && containerType && rate) {
                    // Check location requirement for long distance movements
                    if (['export', 'import', 'domestic'].includes(movementType)) {
                        if (!fromLocation.trim() || !toLocation.trim()) {
                            e.preventDefault();
                            alert(`From and To locations are mandatory for ${movementType} movements.`);
                            return false;
                        }
                    }
                    hasValidRates = true;
                }
            }
        }
        
        // Optional: You can make rates completely optional
        // if (!hasValidRates) {
        //     e.preventDefault();
        //     alert('Please add at least one valid rate configuration.');
        //     return false;
        // }
    });
});
</script>
</body>
</html>


                 