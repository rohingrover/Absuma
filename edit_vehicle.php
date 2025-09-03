<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Initialize variables
$error = '';
$success = '';
$vehicle = [];
$financing = [];

// Get vehicle ID from URL
$vehicleId = $_GET['id'] ?? null;

if (!$vehicleId || !is_numeric($vehicleId)) {
    header("Location: manage_vehicles.php");
    exit();
}

try {
    // Fetch vehicle details with financing information
    $stmt = $pdo->prepare("
        SELECT v.*, vf.is_financed, vf.bank_name, vf.loan_amount, vf.interest_rate, 
               vf.loan_tenure_months, vf.emi_amount, vf.loan_start_date, vf.loan_end_date
        FROM vehicles v
        LEFT JOIN vehicle_financing vf ON v.id = vf.vehicle_id
        WHERE v.id = ?
    ");
    $stmt->execute([$vehicleId]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vehicle) {
        $_SESSION['error'] = "Vehicle not found";
        header("Location: manage_vehicles.php");
        exit();
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_vehicle'])) {
        $vehicleData = [
            'vehicle_number' => trim($_POST['vehicle_number']),
            'driver_name' => trim($_POST['driver_name']),
            'owner_name' => trim($_POST['owner_name']),
            'make_model' => trim($_POST['make_model']),
            'manufacturing_year' => $_POST['manufacturing_year'] ?? null,
            'gvw' => $_POST['gvw'] ?? null,
            'is_financed' => isset($_POST['is_financed']) ? 1 : 0,
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'emi_amount' => $_POST['emi_amount'] ?? '',
            'loan_amount' => $_POST['loan_amount'] ?? '',
            'interest_rate' => $_POST['interest_rate'] ?? '',
            'loan_tenure_months' => $_POST['loan_tenure_months'] ?? '',
            'loan_start_date' => $_POST['loan_start_date'] ?? '',
            'loan_end_date' => $_POST['loan_end_date'] ?? ''
        ];

        // Validate
        $errors = [];
        if (empty($vehicleData['vehicle_number'])) {
            $errors[] = "Vehicle number is required";
        }
        if (empty($vehicleData['driver_name'])) {
            $errors[] = "Driver name is required";
        }
        if (empty($vehicleData['owner_name'])) {
            $errors[] = "Owner name is required";
        }
        if (!empty($vehicleData['manufacturing_year'])) {
            $currentYear = date('Y');
            if (!is_numeric($vehicleData['manufacturing_year']) || 
                $vehicleData['manufacturing_year'] < 1900 || 
                $vehicleData['manufacturing_year'] > $currentYear) {
                $errors[] = "Manufacturing year must be between 1900 and $currentYear";
            }
        }
        if (!empty($vehicleData['gvw']) && (!is_numeric($vehicleData['gvw']) || $vehicleData['gvw'] <= 0)) {
            $errors[] = "GVW must be a positive number";
        }
        
        // Financing validation
        if ($vehicleData['is_financed']) {
            if (empty($vehicleData['bank_name'])) {
                $errors[] = "Bank name is required for financed vehicles";
            }
            if (empty($vehicleData['emi_amount']) || !is_numeric($vehicleData['emi_amount']) || $vehicleData['emi_amount'] <= 0) {
                $errors[] = "Valid EMI amount is required for financed vehicles";
            }
            if (!empty($vehicleData['loan_amount']) && (!is_numeric($vehicleData['loan_amount']) || $vehicleData['loan_amount'] <= 0)) {
                $errors[] = "Invalid loan amount";
            }
            if (!empty($vehicleData['interest_rate']) && (!is_numeric($vehicleData['interest_rate']) || $vehicleData['interest_rate'] <= 0)) {
                $errors[] = "Invalid interest rate";
            }
            if (!empty($vehicleData['loan_tenure_months']) && (!is_numeric($vehicleData['loan_tenure_months']) || $vehicleData['loan_tenure_months'] <= 0)) {
                $errors[] = "Invalid loan tenure";
            }
            if (!empty($vehicleData['loan_start_date']) && !DateTime::createFromFormat('Y-m-d', $vehicleData['loan_start_date'])) {
                $errors[] = "Invalid loan start date format (YYYY-MM-DD)";
            }
            if (!empty($vehicleData['loan_end_date']) && !DateTime::createFromFormat('Y-m-d', $vehicleData['loan_end_date'])) {
                $errors[] = "Invalid loan end date format (YYYY-MM-DD)";
            }
        }

       if (empty($errors)) {
    $pdo->beginTransaction();

    try {
        // Normalize vehicle numbers for comparison
        $oldVehicleNumber = $vehicle['vehicle_number'];
        $newVehicleNumber = $vehicleData['vehicle_number'];
        $oldDriverName = $vehicle['driver_name'];
        $newDriverName = $vehicleData['driver_name'];
        
        $isVehicleNumberChanged = ($oldVehicleNumber !== $newVehicleNumber);
        $isDriverNameChanged = ($oldDriverName !== $newDriverName);
        
        // If either vehicle number or driver name is changing, perform duplicate checks
        if ($isVehicleNumberChanged || $isDriverNameChanged) {
            
            // Check if the new driver name is already assigned to a different vehicle
            if ($isDriverNameChanged) {
                $driverNameCheckStmt = $pdo->prepare("
                    SELECT id, name, vehicle_number 
                    FROM drivers 
                    WHERE name = ? AND vehicle_number != ?
                ");
                $driverNameCheckStmt->execute([$newDriverName, $newVehicleNumber]);
                $driverWithDifferentVehicle = $driverNameCheckStmt->fetch();
                
                if ($driverWithDifferentVehicle) {
                    throw new Exception("Driver '{$newDriverName}' is already assigned to vehicle '{$driverWithDifferentVehicle['vehicle_number']}'. A driver cannot be assigned to multiple vehicles.");
                }
            }
            
            // Check if the new vehicle number already has a different driver assigned
            if ($isVehicleNumberChanged) {
                $vehicleDriverCheckStmt = $pdo->prepare("
                    SELECT id, name, vehicle_number 
                    FROM drivers 
                    WHERE vehicle_number = ? AND name != ?
                ");
                $vehicleDriverCheckStmt->execute([$newVehicleNumber, $newDriverName]);
                $vehicleWithDifferentDriver = $vehicleDriverCheckStmt->fetch();
                
                if ($vehicleWithDifferentDriver) {
                    throw new Exception("Vehicle '{$newVehicleNumber}' already has driver '{$vehicleWithDifferentDriver['name']}' assigned. Please remove the existing assignment first.");
                }
            }
            
            // Check for exact duplicate (same driver name + same vehicle number)
            if ($newVehicleNumber !== $oldVehicleNumber || $newDriverName !== $oldDriverName) {
                $exactDuplicateStmt = $pdo->prepare("
                    SELECT id, name, vehicle_number 
                    FROM drivers 
                    WHERE name = ? AND vehicle_number = ?
                ");
                $exactDuplicateStmt->execute([$newDriverName, $newVehicleNumber]);
                $exactDuplicate = $exactDuplicateStmt->fetch();
                
                if ($exactDuplicate) {
                    throw new Exception("A driver with name '{$newDriverName}' is already assigned to vehicle '{$newVehicleNumber}'. This combination already exists.");
                }
            }
        }
        
        // Update vehicle
        $updateVehicle = $pdo->prepare("
            UPDATE vehicles SET
                vehicle_number = ?,
                driver_name = ?,
                owner_name = ?,
                make_model = ?,
                manufacturing_year = ?,
                gvw = ?,
                is_financed = ?
            WHERE id = ?
        ");
        $updateVehicle->execute([
            $vehicleData['vehicle_number'],
            $vehicleData['driver_name'],
            $vehicleData['owner_name'],
            $vehicleData['make_model'],
            !empty($vehicleData['manufacturing_year']) ? $vehicleData['manufacturing_year'] : null,
            !empty($vehicleData['gvw']) ? $vehicleData['gvw'] : null,
            $vehicleData['is_financed'],
            $vehicleId
        ]);

        // Update or insert financing details
        $checkFinancing = $pdo->prepare("SELECT id FROM vehicle_financing WHERE vehicle_id = ?");
        $checkFinancing->execute([$vehicleId]);
        $financingExists = $checkFinancing->fetch();

        if ($financingExists) {
            // Update existing financing record
            $updateFinancing = $pdo->prepare("
                UPDATE vehicle_financing SET
                    is_financed = ?,
                    bank_name = ?,
                    loan_amount = ?,
                    interest_rate = ?,
                    loan_tenure_months = ?,
                    emi_amount = ?,
                    loan_start_date = ?,
                    loan_end_date = ?
                WHERE vehicle_id = ?
            ");
            $updateFinancing->execute([
                $vehicleData['is_financed'],
                $vehicleData['is_financed'] ? ($vehicleData['bank_name'] ?: null) : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_amount']) ? $vehicleData['loan_amount'] : null,
                $vehicleData['is_financed'] && !empty($vehicleData['interest_rate']) ? $vehicleData['interest_rate'] : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_tenure_months']) ? $vehicleData['loan_tenure_months'] : null,
                $vehicleData['is_financed'] ? ($vehicleData['emi_amount'] ?: null) : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_start_date']) ? $vehicleData['loan_start_date'] : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_end_date']) ? $vehicleData['loan_end_date'] : null,
                $vehicleId
            ]);
        } else {
            // Insert new financing record
            $insertFinancing = $pdo->prepare("
                INSERT INTO vehicle_financing 
                (vehicle_id, is_financed, bank_name, loan_amount, interest_rate, loan_tenure_months, emi_amount, loan_start_date, loan_end_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertFinancing->execute([
                $vehicleId,
                $vehicleData['is_financed'],
                $vehicleData['is_financed'] ? ($vehicleData['bank_name'] ?: null) : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_amount']) ? $vehicleData['loan_amount'] : null,
                $vehicleData['is_financed'] && !empty($vehicleData['interest_rate']) ? $vehicleData['interest_rate'] : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_tenure_months']) ? $vehicleData['loan_tenure_months'] : null,
                $vehicleData['is_financed'] ? ($vehicleData['emi_amount'] ?: null) : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_start_date']) ? $vehicleData['loan_start_date'] : null,
                $vehicleData['is_financed'] && !empty($vehicleData['loan_end_date']) ? $vehicleData['loan_end_date'] : null
            ]);
        }

        // Update driver information
        $updateDriver = $pdo->prepare("
            UPDATE drivers SET
                name = ?,
                vehicle_number = ?,
                updated_at = NOW()
            WHERE vehicle_number = ?
        ");
        $updateDriver->execute([
            $vehicleData['driver_name'],
            $vehicleData['vehicle_number'],
            $vehicle['vehicle_number'] // Use old vehicle number to find the record
        ]);

        // If no driver record was updated (no existing driver), create one ONLY after validation
        if ($updateDriver->rowCount() == 0) {
            $insertDriver = $pdo->prepare("
                INSERT INTO drivers (name, vehicle_number, status, created_at) 
                VALUES (?, ?, 'Active', NOW())
            ");
            $insertDriver->execute([
                $vehicleData['driver_name'],
                $vehicleData['vehicle_number']
            ]);
        }

        $pdo->commit();
        $success = "Vehicle, driver, and financing details updated successfully!";
        
        // Refresh data
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
} else {
    $error = implode("<br>", $errors);
}
    }

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Vehicle - Fleet Management</title>
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
            <div class="max-w-7xl mx-auto px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-600 p-3 rounded-lg text-white">
                            <i class="fas fa-truck-pickup text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Edit Vehicle - <?= htmlspecialchars($vehicle['vehicle_number']) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="bg-blue-50 px-4 py-2 rounded-lg border border-blue-100">
                            <div class="text-blue-800 font-medium"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                        </div>
                        <a href="logout.php" class="text-blue-600 hover:text-blue-800 p-2">
                            <i class="fas fa-sign-out-alt text-lg"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar -->
                <aside class="w-full lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-sm p-4 sticky top-20">
                        <!-- Vehicle Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-blue-600 font-bold mb-3 pl-2 border-l-4 border-blue-600/50">Vehicle Section</h3>
                            <nav class="space-y-1.5">
                                <a href="add_vehicle.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center"></i> 
                                    <span>Add Vehicle & Driver</span>
                                </a>
                                <a href="manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg transition-all">
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
                    
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 card-hover-effect">
                        <div class="p-6">
                            <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                <i class="fas fa-edit text-blue-600"></i> Edit Vehicle & Driver Details
                            </h2>
                            
                            <form method="POST" class="form-grid">
                                <!-- Vehicle Number -->
                                <div class="form-field">
                                    <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number <span class="text-red-500">*</span></label>
                                    <input type="text" id="vehicle_number" name="vehicle_number" 
                                           value="<?= htmlspecialchars($vehicle['vehicle_number']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="e.g. UP25GT0880" required>
                                </div>
                                
                                <!-- Driver Name -->
                                <div class="form-field">
                                    <label for="driver_name" class="block text-sm font-medium text-gray-700">Driver Name <span class="text-red-500">*</span></label>
                                    <input type="text" id="driver_name" name="driver_name" 
                                           value="<?= htmlspecialchars($vehicle['driver_name']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="Enter driver name" required>
                                </div>
                                
                                <!-- Owner Name -->
                                <div class="form-field">
                                    <label for="owner_name" class="block text-sm font-medium text-gray-700">Owner Name <span class="text-red-500">*</span></label>
                                    <input type="text" id="owner_name" name="owner_name" 
                                           value="<?= htmlspecialchars($vehicle['owner_name']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="Enter owner name" required>
                                </div>
                                
                                <!-- Make/Model -->
                                <div class="form-field">
                                    <label for="make_model" class="block text-sm font-medium text-gray-700">Make/Model</label>
                                    <input type="text" id="make_model" name="make_model" 
                                           value="<?= htmlspecialchars($vehicle['make_model']) ?>" 
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
                                            <option value="<?= $year ?>" <?= $vehicle['manufacturing_year'] == $year ? 'selected' : '' ?>>
                                                <?= $year ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <!-- GVW -->
                                <div class="form-field">
                                    <label for="gvw" class="block text-sm font-medium text-gray-700">Gross Vehicle Weight (GVW)</label>
                                    <input type="number" id="gvw" name="gvw" step="0.01" min="0"
                                           value="<?= htmlspecialchars($vehicle['gvw']) ?>" 
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="e.g. 16500.00">
                                    <p class="text-xs text-gray-500">Weight in kilograms</p>
                                </div>
                                
                                <!-- Financed Vehicle -->
                                <div class="form-field">
                                    <div class="flex items-center">
                                        <input type="checkbox" id="is_financed" name="is_financed" value="1" 
                                               <?= $vehicle['is_financed'] ? 'checked' : '' ?>
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
                                                       value="<?= htmlspecialchars($vehicle['bank_name']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. HDFC Bank">
                                            </div>
                                            <!-- EMI Amount -->
                                            <div class="form-field">
                                                <label for="emi_amount" class="block text-sm font-medium text-gray-700">EMI Amount (₹) <span class="text-red-500">*</span></label>
                                                <input type="number" id="emi_amount" name="emi_amount" step="0.01" min="0" 
                                                       value="<?= htmlspecialchars($vehicle['emi_amount']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 31500.00">
                                            </div>
                                            <!-- Loan Amount -->
                                            <div class="form-field">
                                                <label for="loan_amount" class="block text-sm font-medium text-gray-700">Loan Amount (₹)</label>
                                                <input type="number" id="loan_amount" name="loan_amount" step="0.01" min="0" 
                                                       value="<?= htmlspecialchars($vehicle['loan_amount']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 1500000.00">
                                            </div>
                                            <!-- Interest Rate -->
                                            <div class="form-field">
                                                <label for="interest_rate" class="block text-sm font-medium text-gray-700">Interest Rate (%)</label>
                                                <input type="number" id="interest_rate" name="interest_rate" step="0.01" min="0" 
                                                       value="<?= htmlspecialchars($vehicle['interest_rate']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 8.5">
                                            </div>
                                            <!-- Loan Tenure -->
                                            <div class="form-field">
                                                <label for="loan_tenure_months" class="block text-sm font-medium text-gray-700">Loan Tenure (Months)</label>
                                                <input type="number" id="loan_tenure_months" name="loan_tenure_months" min="0" 
                                                       value="<?= htmlspecialchars($vehicle['loan_tenure_months']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="e.g. 60">
                                            </div>
                                            <!-- Loan Start Date -->
                                            <div class="form-field">
                                                <label for="loan_start_date" class="block text-sm font-medium text-gray-700">Loan Start Date</label>
                                                <input type="date" id="loan_start_date" name="loan_start_date" 
                                                       value="<?= htmlspecialchars($vehicle['loan_start_date']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            </div>
                                            <!-- Loan End Date -->
                                            <div class="form-field">
                                                <label for="loan_end_date" class="block text-sm font-medium text-gray-700">Loan End Date</label>
                                                <input type="date" id="loan_end_date" name="loan_end_date" 
                                                       value="<?= htmlspecialchars($vehicle['loan_end_date']) ?>" 
                                                       class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Actions -->
                                <div class="form-actions flex items-center gap-3">
                                    <button type="submit" name="update_vehicle" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-save mr-2"></i> Save Changes
                                    </button>
                                    <a href="manage_vehicles.php" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Cancel
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
        });
    </script>
</body>
</html>