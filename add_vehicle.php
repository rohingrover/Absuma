<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

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
            
            // Insert vehicle
            $insertStmt = $pdo->prepare("
                INSERT INTO vehicles 
                (vehicle_number, driver_name, owner_name, make_model, manufacturing_year, gvw, is_financed, current_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'available')
            ");
            $insertStmt->execute([
                $formData['vehicle_number'],
                $formData['driver_name'],
                $formData['owner_name'],
                $formData['make_model'],
                !empty($formData['manufacturing_year']) ? $formData['manufacturing_year'] : null,
                !empty($formData['gvw']) ? $formData['gvw'] : null,
                $formData['is_financed']
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
            
            // Insert driver (without ON DUPLICATE KEY UPDATE)
            $driverStmt = $pdo->prepare("
                INSERT INTO drivers 
                (name, vehicle_number, status, created_at) 
                VALUES (?, ?, 'Active', NOW())
            ");
            $driverStmt->execute([
                $formData['driver_name'],
                $formData['vehicle_number']
            ]);
            
            $pdo->commit();
            $success = "Vehicle, driver, and financing details added successfully!";
            
            // Reset form data
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
                            <i class="fas fa-truck-pickup text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Add New Vehicle</p>
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
                        <!-- Vehicle Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-blue-600 font-bold mb-3 pl-2 border-l-4 border-blue-600/50">Vehicle Section</h3>
                            <nav class="space-y-1">
                                <a href="add_vehicle.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
                                    <i class="fas fa-plus w-5 text-center"></i> Add Vehicle & Driver
                                </a>
                                <a href="manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center"></i> Manage Vehicles
                                </a>
                                <a href="manage_drivers.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-users w-5 text-center"></i> Manage Drivers
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
                                    <p><?= $error ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                        <div class="p-6">
                            <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                <i class="fas fa-truck text-blue-600"></i> Add Vehicle & Driver
                            </h2>
                            
                            <form id="vehicleForm" method="POST" action="add_vehicle.php" class="form-grid">
                                <!-- Vehicle Number -->
                                <div class="form-field">
                                    <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number <span class="text-red-500">*</span></label>
                                    <input type="text" id="vehicle_number" name="vehicle_number" 
                                           value="<?= htmlspecialchars($formData['vehicle_number']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="e.g. UP25GT0880" required>
                                    <p class="text-xs text-gray-500">Format: Two letters, 1-2 digits, 1-2 letters, 4 digits</p>
                                </div>
                                
                                <!-- Driver Name -->
                                <div class="form-field">
                                    <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name <span class="text-red-500">*</span></label>
                                    <input type="text" id="driver_name" name="driver_name" 
                                           value="<?= htmlspecialchars($formData['driver_name']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="Enter driver name" required>
                                </div>
                                
                                <!-- Owner Name -->
                                <div class="form-field">
                                    <label for="owner_name" class="block text-sm font-medium text-gray-700">Owner Name <span class="text-red-500">*</span></label>
                                    <input type="text" id="owner_name" name="owner_name" 
                                           value="<?= htmlspecialchars($formData['owner_name']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="Enter owner name" required>
                                </div>
                                
                                <!-- Make/Model -->
                                <div class="form-field">
                                    <label for="make_model" class="block text-sm font-medium text-gray-700">Make/Model</label>
                                    <input type="text" id="make_model" name="make_model" 
                                           value="<?= htmlspecialchars($formData['make_model']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="e.g. Tata 1109, Ashok Leyland 2518">
                                </div>
                                
                                <!-- Manufacturing Year -->
                                <div class="form-field">
                                    <label for="manufacturing_year" class="block text-sm font-medium text-gray-700">Year of Manufacturing</label>
                                    <select id="manufacturing_year" name="manufacturing_year" 
                                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
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
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="e.g. 16500.00">
                                    <p class="text-xs text-gray-500">Weight in kilograms</p>
                                </div>
                                
                                <!-- Financed Vehicle -->
                                <div class="form-field">
                                    <div class="flex items-center">
                                        <input type="checkbox" id="is_financed" name="is_financed" value="1" 
                                               <?= $formData['is_financed'] ? 'checked' : '' ?>
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <label for="is_financed" class="ml-2 block text-sm text-gray-700">Financed Vehicle</label>
                                    </div>
                                </div>
                                
                                <!-- Financing Fields -->
                                <div class="form-field hidden md:col-span-2" id="financingFields">
                                    <div class="space-y-4 bg-gray-50 p-4 rounded-md">
                                        <h4 class="text-sm font-medium text-gray-700">Financing Details</h4>
                                        <div class="form-grid">
                                            <!-- Bank Name -->
                                            <div class="form-field">
                                                <label for="bank_name" class="block text-sm font-medium text-gray-700">Bank Name <span class="text-red-500">*</span></label>
                                                <input type="text" id="bank_name" name="bank_name" 
                                                       value="<?= htmlspecialchars($formData['bank_name']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. HDFC Bank">
                                            </div>
                                            <!-- EMI Amount -->
                                            <div class="form-field">
                                                <label for="emi_amount" class="block text-sm font-medium text-gray-700">EMI Amount (₹) <span class="text-red-500">*</span></label>
                                                <input type="number" id="emi_amount" name="emi_amount" step="0.01" min="0" 
                                                       value="<?= htmlspecialchars($formData['emi_amount']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 31500.00">
                                            </div>
                                            <!-- Loan Amount -->
                                            <div class="form-field">
                                                <label for="loan_amount" class="block text-sm font-medium text-gray-700">Loan Amount (₹)</label>
                                                <input type="number" id="loan_amount" name="loan_amount" step="0.01" min="0" 
                                                       value="<?= htmlspecialchars($formData['loan_amount']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 1500000.00">
                                            </div>
                                            <!-- Interest Rate -->
                                            <div class="form-field">
                                                <label for="interest_rate" class="block text-sm font-medium text-gray-700">Interest Rate (%)</label>
                                                <input type="number" id="interest_rate" name="interest_rate" step="0.01" min="0" 
                                                       value="<?= htmlspecialchars($formData['interest_rate']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 8.5">
                                            </div>
                                            <!-- Loan Tenure -->
                                            <div class="form-field">
                                                <label for="loan_tenure_months" class="block text-sm font-medium text-gray-700">Loan Tenure (Months)</label>
                                                <input type="number" id="loan_tenure_months" name="loan_tenure_months" min="0" 
                                                       value="<?= htmlspecialchars($formData['loan_tenure_months']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 60">
                                            </div>
                                            <!-- Loan Start Date -->
                                            <div class="form-field">
                                                <label for="loan_start_date" class="block text-sm font-medium text-gray-700">Loan Start Date</label>
                                                <input type="date" id="loan_start_date" name="loan_start_date" 
                                                       value="<?= htmlspecialchars($formData['loan_start_date']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            </div>
                                            <!-- Loan End Date -->
                                            <div class="form-field">
                                                <label for="loan_end_date" class="block text-sm font-medium text-gray-700">Loan End Date</label>
                                                <input type="date" id="loan_end_date" name="loan_end_date" 
                                                       value="<?= htmlspecialchars($formData['loan_end_date']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Actions -->
                                <div class="form-actions flex items-center gap-3">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-save mr-2"></i> Add Vehicle
                                    </button>
                                    <a href="manage_vehicles.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-list mr-2"></i> View All Vehicles
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </main>
            </div>
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
            
            // Form validation
            document.getElementById('vehicleForm').addEventListener('submit', function(e) {
                const vehicleNumber = document.getElementById('vehicle_number').value.trim();
                const driverName = document.getElementById('driver_name').value.trim();
                const ownerName = document.getElementById('owner_name').value.trim();
                const gvw = document.getElementById('gvw').value.trim();
                const manufacturingYear = document.getElementById('manufacturing_year').value;
                
                if (!vehicleNumber) {
                    e.preventDefault();
                    alert('Vehicle number is required');
                    return;
                }
                
                if (!driverName) {
                    e.preventDefault();
                    alert('Driver name is required');
                    return;
                }
                
                if (!ownerName) {
                    e.preventDefault();
                    alert('Owner name is required');
                    return;
                }
                
                if (manufacturingYear) {
                    const currentYear = new Date().getFullYear();
                    if (manufacturingYear < 1900 || manufacturingYear > currentYear) {
                        e.preventDefault();
                        alert('Manufacturing year must be between 1900 and ' + currentYear);
                        return;
                    }
                }
                
                if (gvw && (isNaN(gvw) || parseFloat(gvw) <= 0)) {
                    e.preventDefault();
                    alert('GVW must be a positive number');
                    return;
                }
                
                if (financedCheckbox.checked) {
                    const bankName = document.getElementById('bank_name').value.trim();
                    const emiAmount = document.getElementById('emi_amount').value.trim();
                    
                    if (!bankName) {
                        e.preventDefault();
                        alert('Bank name is required for financed vehicles');
                        return;
                    }
                    
                    if (!emiAmount || isNaN(emiAmount) || parseFloat(emiAmount) <= 0) {
                        e.preventDefault();
                        alert('Valid EMI amount is required for financed vehicles');
                        return;
                    }
                }
            });
        });
    </script>