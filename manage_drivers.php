<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Handle document uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    header('Content-Type: application/json');
    try {
        $driverId = $_POST['driver_id'] ?? null;
        $documentType = $_POST['document_type'] ?? null;
        $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        if (!$driverId || !$documentType || !isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Missing required fields or no file uploaded');
        }

        // Validate expiry date format if provided
        if ($expiryDate) {
            if (!DateTime::createFromFormat('Y-m-d', $expiryDate)) {
                throw new Exception('Invalid expiry date format. Use YYYY-MM-DD.');
            }
        }

        // Check if document type already exists for this driver
        $checkStmt = $pdo->prepare("SELECT id FROM driver_documents WHERE driver_id = ? AND document_type = ?");
        $checkStmt->execute([$driverId, $documentType]);
        if ($checkStmt->rowCount() > 0) {
            throw new Exception("A {$documentType} document already exists for this driver");
        }

        // File upload handling
        $uploadDir = 'Uploads/driver_docs/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }

        $fileExtension = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($fileExtension, $allowedExtensions)) {
            throw new Exception('Invalid file type. Only JPG, JPEG, PNG, and PDF are allowed');
        }

        $fileName = uniqid() . '_' . basename($_FILES['document_file']['name']);
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($_FILES['document_file']['tmp_name'], $targetPath)) {
            throw new Exception('Failed to move uploaded file');
        }

        $pdo->beginTransaction();

        // Insert into driver_documents
        $stmt = $pdo->prepare("
            INSERT INTO driver_documents 
            (driver_id, document_type, file_path, expiry_date, uploaded_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$driverId, $documentType, $fileName, $expiryDate]);

        // Calculate days remaining for alert if expiry date is provided
        if ($expiryDate) {
            try {
                $today = new DateTime();
                $expiry = new DateTime($expiryDate);
                $interval = $today->diff($expiry);
                $daysRemaining = $interval->days * ($interval->invert ? -1 : 1);

                // Insert into driver_document_alerts if document is expired or expiring soon
                if ($daysRemaining <= 30) {
                    $driverStmt = $pdo->prepare("SELECT name FROM drivers WHERE id = ?");
                    $driverStmt->execute([$driverId]);
                    $driver = $driverStmt->fetch(PDO::FETCH_ASSOC);

                    if ($driver) {
                        $alertStmt = $pdo->prepare("
                            INSERT INTO driver_document_alerts 
                            (user_id, driver_id, driver_name, document_type, expiry_date, days_remaining, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $alertStmt->execute([
                            $_SESSION['user_id'] ?? 0,
                            $driverId,
                            $driver['name'],
                            $documentType,
                            $expiryDate,
                            $daysRemaining
                        ]);
                    }
                }
            } catch (Exception $e) {
                error_log("Date processing error: " . $e->getMessage());
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Document uploaded successfully'
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Upload error: " . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle document viewing requests
if (isset($_GET['view_docs'])) {
    try {
        $driverId = $_GET['view_docs'];
        $stmt = $pdo->prepare("
            SELECT id, document_type, file_path, expiry_date, uploaded_at 
            FROM driver_documents 
            WHERE driver_id = ?
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$driverId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($documents);
        exit;
    } catch (Exception $e) {
        error_log("Error fetching documents: " . $e->getMessage());
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Initialize variables
$error = '';
$success = '';
$searchTerm = '';
$drivers = [];

// Handle search
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
}

// Handle driver deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_driver'])) {
    try {
        $pdo->beginTransaction();
        
        // First get the driver's vehicle number before deleting
        $stmt = $pdo->prepare("SELECT vehicle_number FROM drivers WHERE id = ?");
        $stmt->execute([$_POST['driver_id']]);
        $vehicle_number = $stmt->fetchColumn();
        
        // Delete driver documents
        $pdo->prepare("DELETE FROM driver_documents WHERE driver_id = ?")->execute([$_POST['driver_id']]);
        
        // Delete driver document alerts
        $pdo->prepare("DELETE FROM driver_document_alerts WHERE driver_id = ?")->execute([$_POST['driver_id']]);
        
        // Delete the driver
        $stmt = $pdo->prepare("DELETE FROM drivers WHERE id = ?");
        $stmt->execute([$_POST['driver_id']]);
        
        // Set driver name to NULL in vehicle if exists
        if (!empty($vehicle_number)) {
            $stmt = $pdo->prepare("UPDATE vehicles SET driver_name = NULL WHERE vehicle_number = ?");
            $stmt->execute([$vehicle_number]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Driver and all associated documents deleted successfully!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error deleting driver: " . $e->getMessage();
    }
    header("Location: manage_drivers.php");
    exit;
}

// Fetch drivers with KYC status and optional search
try {
    $query = "SELECT d.*, v.owner_name, v.make_model, v.manufacturing_year, v.gvw,
                     (SELECT COUNT(*) FROM driver_documents dd WHERE dd.driver_id = d.id) as document_count,
                     CASE 
                         WHEN (SELECT COUNT(*) FROM driver_documents dd WHERE dd.driver_id = d.id) > 0 THEN 1 
                         ELSE 0 
                     END as kyc_completed
              FROM drivers d
              LEFT JOIN vehicles v ON d.vehicle_number = v.vehicle_number";
    
    if (!empty($searchTerm)) {
        $query .= " WHERE d.name LIKE :search OR d.vehicle_number LIKE :search";
    }
    
    $query .= " ORDER BY d.name ASC";
    
    $stmt = $pdo->prepare($query);
    
    if (!empty($searchTerm)) {
        $searchParam = "%$searchTerm%";
        $stmt->bindParam(':search', $searchParam);
    }
    
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Display session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle AJAX search request
if (isset($_GET['ajax_search'])) {
    $searchTerm = trim($_GET['ajax_search']);
    $response = ['drivers' => [], 'error' => ''];

    try {
        $query = "SELECT d.*, v.owner_name, v.make_model, v.manufacturing_year, v.gvw,
                         (SELECT COUNT(*) FROM driver_documents dd WHERE dd.driver_id = d.id) as document_count,
                         CASE 
                             WHEN (SELECT COUNT(*) FROM driver_documents dd WHERE dd.driver_id = d.id) > 0 THEN 1 
                             ELSE 0 
                         END as kyc_completed
                  FROM drivers d
                  LEFT JOIN vehicles v ON d.vehicle_number = v.vehicle_number";
        
        if (!empty($searchTerm)) {
            $query .= " WHERE d.name LIKE ? OR d.vehicle_number LIKE ?";
        }
        
        $query .= " ORDER BY d.name ASC";
        
        $stmt = $pdo->prepare($query);
        
        if (!empty($searchTerm)) {
            $searchParam = "%$searchTerm%";
            $stmt->bindParam(1, $searchParam);
            $stmt->bindParam(2, $searchParam);
        }
        
        $stmt->execute();
        $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['drivers'] = $drivers;
    } catch (PDOException $e) {
        $response['error'] = "Database error: " . $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get KYC statistics
$kycStats = $pdo->query("
    SELECT 
        COUNT(*) as total_drivers,
        SUM(CASE WHEN (SELECT COUNT(*) FROM driver_documents dd WHERE dd.driver_id = d.id) > 0 THEN 1 ELSE 0 END) as kyc_completed,
        COUNT(*) - SUM(CASE WHEN (SELECT COUNT(*) FROM driver_documents dd WHERE dd.driver_id = d.id) > 0 THEN 1 ELSE 0 END) as kyc_pending
    FROM drivers d
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Drivers - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="notifications.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @layer utilities {
            .badge {
                @apply inline-block px-2.5 py-1 rounded-full text-xs font-semibold;
            }
            .badge-success {
                @apply bg-green-100 text-green-800;
            }
            .badge-error {
                @apply bg-red-100 text-red-800;
            }
            .badge-warning {
                @apply bg-amber-100 text-amber-800;
            }
            .badge-active {
                @apply bg-green-100 text-green-800;
            }
            .badge-inactive {
                @apply bg-red-100 text-red-800;
            }
            .badge-on-leave {
                @apply bg-amber-100 text-amber-800;
            }
            .badge-kyc-completed {
                @apply bg-green-100 text-green-800;
            }
            .badge-kyc-pending {
                @apply bg-red-100 text-red-800;
            }
            .card-hover-effect {
                @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
            }
            .kyc-completed {
                @apply bg-green-50 border-l-4 border-green-400;
            }
            .kyc-pending {
                @apply bg-red-50 border-l-4 border-red-400;
            }
        }
        
        .modal-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            background: rgba(0, 0, 0, 0.5) !important;
            display: none !important;
            justify-content: center !important;
            align-items: center !important;
            z-index: 99999 !important;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .modal-overlay.active {
            display: flex !important;
            opacity: 1 !important;
            visibility: visible !important;
        }
        
        .modal-container {
            background: white !important;
            border-radius: 0.75rem !important;
            width: 90% !important;
            max-width: 900px !important;
            max-height: 85vh !important;
            overflow-y: auto !important;
            transform: scale(0.9);
            transition: transform 0.3s ease;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
            position: relative !important;
            margin: auto !important;
        }
        
        .modal-overlay.active .modal-container {
            transform: scale(1) !important;
        }
        
        body.modal-open {
            overflow: hidden !important;
        }
        
        .kyc-indicator {
            position: relative;
        }
        
        .kyc-tick {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .kyc-pending-indicator {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            background: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
                            <i class="fas fa-id-card text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Manage Drivers</p>
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
                                <a href="add_vehicle.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center"></i> Add Vehicle & Driver
                                </a>
                                <a href="manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center"></i> Manage Vehicles
                                </a>
                                <a href="manage_drivers.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
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
                <main class="flex-1" style="max-width:75%">
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
                                <p><?= htmlspecialchars($error) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- KYC Statistics -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-chart-bar text-blue-600"></i> KYC Status Overview
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-blue-600"><?= $kycStats['total_drivers'] ?></div>
                                <div class="text-sm text-blue-800">Total Drivers</div>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-green-600"><?= $kycStats['kyc_completed'] ?></div>
                                <div class="text-sm text-green-800">KYC Completed</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-red-600"><?= $kycStats['kyc_pending'] ?></div>
                                <div class="text-sm text-red-800">KYC Pending</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                            <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-users text-blue-600"></i> Driver List
                            </h2>
                            
                            <!-- Search Bar -->
                            <div class="relative w-full md:w-64">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="search" id="driver-search" placeholder="Search drivers..." 
                                       value="<?= htmlspecialchars($searchTerm) ?>"
                                       class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <div id="clear-search" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-500 cursor-pointer <?= $searchTerm ? '' : 'hidden' ?>">
                                    <i class="fas fa-times"></i>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (empty($drivers)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-user-slash text-4xl text-gray-400 mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-700">No Drivers Found</h3>
                                <p class="text-gray-500 mt-2">Get started by adding your first driver</p>
                                <a href="add_vehicle.php" class="inline-flex items-center px-4 py-2 mt-4 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-plus mr-2"></i> Add Vehicle & Driver
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Driver Details</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle Info</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle Owner</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle Details</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                           
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200" id="driverTableBody">
                                        <?php foreach ($drivers as $driver): ?>
                                            <tr class="hover:bg-gray-50 <?= $driver['kyc_completed'] ? 'kyc-completed' : 'kyc-pending' ?>">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center gap-3">
                                                        <div class="kyc-indicator">
                                                            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                                <i class="fas fa-user text-blue-600"></i>
                                                            </div>
                                                            <?php if ($driver['kyc_completed']): ?>
                                                                <div class="kyc-tick">
                                                                    <i class="fas fa-check text-white text-xs"></i>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="kyc-pending-indicator">
                                                                    <i class="fas fa-exclamation text-white text-xs"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($driver['name']) ?></div>
                                                            <div class="text-sm text-gray-500">
                                                                <?= $driver['document_count'] ?> document(s) uploaded
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($driver['vehicle_number']): ?>
                                                        <div class="font-medium text-gray-900 flex items-center gap-2">
                                                            <i class="fas fa-truck text-green-600"></i>
                                                            <?= htmlspecialchars($driver['vehicle_number']) ?>
                                                        </div>
                                                        <?php if ($driver['make_model']): ?>
                                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($driver['make_model']) ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($driver['manufacturing_year']): ?>
                                                            <div class="text-xs text-gray-400">Year: <?= $driver['manufacturing_year'] ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-gray-400 italic">No vehicle assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($driver['owner_name']): ?>
                                                        <div class="text-sm text-gray-900 flex items-center gap-1">
                                                            <i class="fas fa-crown text-yellow-500"></i>
                                                            <?= htmlspecialchars($driver['owner_name']) ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($driver['gvw']): ?>
                                                        <div class="text-sm text-gray-900 flex items-center gap-1">
                                                            <i class="fas fa-weight-hanging text-gray-400"></i>
                                                            <?= number_format($driver['gvw'], 0) ?> kg
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($driver['status'] == 'Active'): ?>
                                                        <span class="badge badge-active">Active</span>
                                                    <?php elseif ($driver['status'] == 'Inactive'): ?>
                                                        <span class="badge badge-inactive">Inactive</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-on-leave">On Leave</span>
                                                    <?php endif; ?>
                                                </td>
                                              
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center gap-2">
                                                        <?php if ($driver['vehicle_number']): ?>
                                                            <a href="edit_vehicle.php?id=<?= $driver['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Edit Vehicle">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <button onclick="showDriverDocumentModal(<?= $driver['id'] ?>)" class="text-green-600 hover:text-green-800 relative" title="View/Upload Driver Documents">
                                                            <i class="fas fa-id-card"></i>
                                                            <?php if (!$driver['kyc_completed']): ?>
                                                                <span class="absolute -top-1 -right-1 w-3 h-3 bg-red-500 border-2 border-white rounded-full"></span>
                                                            <?php endif; ?>
                                                        </button>
                                                        <form method="POST" action="manage_drivers.php" class="inline">
                                                            <input type="hidden" name="driver_id" value="<?= $driver['id'] ?>">
                                                            <button type="submit" name="delete_driver" class="text-red-600 hover:text-red-800" title="Delete Driver" onclick="return confirm('Are you sure? This will permanently delete the driver and all associated documents.')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </main>
            </div>

            <!-- Driver Documents Modal -->
            <div class="modal-overlay" id="driverDocsModal" style="display: none !important;">
                <div class="modal-container" onclick="event.stopPropagation()">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6 border-b border-gray-200 pb-4">
                            <h3 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-id-card text-blue-600"></i>
                                Driver Documents
                            </h3>
                            <button type="button" class="text-gray-400 hover:text-gray-600 p-2 rounded-full hover:bg-gray-100 transition-colors" onclick="hideDriverDocumentModal()">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                        
                        <!-- Status Summary -->
                        <div id="driverDocStatusSummary" class="mb-4 flex gap-2"></div>
                        
                        <!-- Documents List -->
                        <div id="driverDocumentsList" class="mb-6">
                            <!-- Content loaded dynamically -->
                        </div>
                        
                        <!-- Upload Section -->
                        <div class="border-t border-gray-200 pt-6 mt-6 bg-gray-50 -mx-6 px-6 rounded-b-xl">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <i class="fas fa-upload text-blue-600"></i>
                                Upload New Document
                            </h4>
                            
                            <form id="uploadDriverDocumentForm" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="driver_id" id="modalDriverId">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Left Column -->
                                    <div class="space-y-4">
                                        <div>
                                            <label for="driver_document_type" class="block text-sm font-medium text-gray-700 mb-2">Document Type</label>
                                            <select name="document_type" id="driver_document_type" required 
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                <option value="">Select Document Type</option>
                                                <option value="Aadhar Card">Aadhar Card</option>
                                                <option value="PAN Card">PAN Card</option>
                                                <option value="Driving License">Driving License</option>
                                                <option value="Voter ID">Voter ID</option>
                                                <option value="Passport">Passport</option>
                                                <option value="Medical Certificate">Medical Certificate</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        
                                        <div>
                                            <label for="driver_expiry_date" class="block text-sm font-medium text-gray-700 mb-2">Expiry Date (Optional)</label>
                                            <input type="date" name="expiry_date" id="driver_expiry_date"
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        </div>
                                    </div>
                                    
                                    <!-- Right Column - File Upload -->
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 bg-white hover:border-blue-400 transition-colors">
                                        <div class="text-center">
                                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                                            <div class="space-y-2">
                                                <label for="driverDocumentFileInput" class="cursor-pointer">
                                                    <span class="text-sm font-medium text-blue-600 hover:text-blue-500">Click to upload</span>
                                                    <input type="file" name="document_file" id="driverDocumentFileInput" 
                                                           accept=".jpg,.jpeg,.png,.pdf" required class="hidden">
                                                </label>
                                                <p class="text-xs text-gray-500">JPG, PNG, PDF up to 10MB</p>
                                            </div>
                                            <p id="driverFileNameDisplay" class="text-sm text-gray-600 mt-3 font-medium">No file chosen</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="flex justify-end gap-3 pt-6">
                                    <button type="button" onclick="hideDriverDocumentModal()" 
                                            class="px-6 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                        Cancel
                                    </button>
                                    <button type="submit" 
                                            class="px-6 py-2 border border-transparent rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                        <i class="fas fa-upload mr-2"></i> Upload Document
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const searchInput = document.getElementById('driver-search');
                const clearSearch = document.getElementById('clear-search');
                const driverTableBody = document.getElementById('driverTableBody');
                let debounceTimer;

                // Show/hide clear button based on input
                function toggleClearButton() {
                    clearSearch.classList.toggle('hidden', !searchInput.value);
                }

                // Clear search input
                clearSearch.addEventListener('click', () => {
                    searchInput.value = '';
                    toggleClearButton();
                    fetchDrivers('');
                });

                // Debounced search
                searchInput.addEventListener('input', () => {
                    toggleClearButton();
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        fetchDrivers(searchInput.value);
                    }, 300);
                });

                // Fetch drivers via AJAX
                function fetchDrivers(searchTerm) {
                    fetch(`manage_drivers.php?ajax_search=${encodeURIComponent(searchTerm)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                alert(data.error);
                                return;
                            }
                            updateDriverTable(data.drivers);
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while fetching drivers.');
                        });
                }

                // Update driver table
                function updateDriverTable(drivers) {
                    driverTableBody.innerHTML = '';
                    if (drivers.length === 0) {
                        driverTableBody.innerHTML = `
                            <tr>
                                <td colspan="7" class="text-center py-12">
                                    <i class="fas fa-user-slash text-4xl text-gray-400 mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-700">No Drivers Found</h3>
                                    <p class="text-gray-500 mt-2">Try adjusting your search criteria</p>
                                </td>
                            </tr>
                        `;
                        return;
                    }

                    drivers.forEach(driver => {
                        const statusBadge = driver.status === 'Active' 
                            ? '<span class="badge badge-active">Active</span>'
                            : driver.status === 'Inactive'
                            ? '<span class="badge badge-inactive">Inactive</span>'
                            : '<span class="badge badge-on-leave">On Leave</span>';

                        const kycBadge = driver.kyc_completed == 1
                            ? '<span class="badge badge-kyc-completed flex items-center gap-1"><i class="fas fa-check-circle"></i> KYC Complete</span>'
                            : '<span class="badge badge-kyc-pending flex items-center gap-1"><i class="fas fa-exclamation-triangle"></i> KYC Pending</span>';

                        const kycIndicator = driver.kyc_completed == 1
                            ? '<div class="kyc-tick"><i class="fas fa-check text-white text-xs"></i></div>'
                            : '<div class="kyc-pending-indicator"><i class="fas fa-exclamation text-white text-xs"></i></div>';

                        const documentAlert = driver.kyc_completed == 1 ? '' : '<span class="absolute -top-1 -right-1 w-3 h-3 bg-red-500 border-2 border-white rounded-full"></span>';

                        const vehicleInfo = driver.vehicle_number 
                            ? `<div class="font-medium text-gray-900 flex items-center gap-2">
                                   <i class="fas fa-truck text-green-600"></i>
                                   ${driver.vehicle_number}
                               </div>
                               ${driver.make_model ? `<div class="text-sm text-gray-500">${driver.make_model}</div>` : ''}
                               ${driver.manufacturing_year ? `<div class="text-xs text-gray-400">Year: ${driver.manufacturing_year}</div>` : ''}`
                            : '<span class="text-gray-400 italic">No vehicle assigned</span>';

                        const ownerInfo = driver.owner_name 
                            ? `<div class="text-sm text-gray-900 flex items-center gap-1">
                                   <i class="fas fa-crown text-yellow-500"></i>
                                   ${driver.owner_name}
                               </div>`
                            : '<span class="text-gray-400">N/A</span>';

                        const gvwInfo = driver.gvw 
                            ? `<div class="text-sm text-gray-900 flex items-center gap-1">
                                   <i class="fas fa-weight-hanging text-gray-400"></i>
                                   ${Number(driver.gvw).toLocaleString()} kg
                               </div>`
                            : '<span class="text-gray-400">N/A</span>';

                        const editButton = driver.vehicle_number 
                            ? `<a href="edit_vehicle.php?id=${driver.id}" class="text-blue-600 hover:text-blue-800" title="Edit Vehicle">
                                   <i class="fas fa-edit"></i>
                               </a>`
                            : '';

                        const rowClass = driver.kyc_completed == 1 ? 'kyc-completed' : 'kyc-pending';

                        const row = `
                            <tr class="hover:bg-gray-50 ${rowClass}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="kyc-indicator">
                                            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600"></i>
                                            </div>
                                            ${kycIndicator}
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900">${driver.name || 'N/A'}</div>
                                            <div class="text-sm text-gray-500">${driver.document_count} document(s) uploaded</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">${vehicleInfo}</td>
                                <td class="px-6 py-4 whitespace-nowrap">${ownerInfo}</td>
                                <td class="px-6 py-4 whitespace-nowrap">${gvwInfo}</td>
                                <td class="px-6 py-4 whitespace-nowrap">${statusBadge}</td>
                                <td class="px-6 py-4 whitespace-nowrap">${kycBadge}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center gap-2">
                                        ${editButton}
                                        <button onclick="showDriverDocumentModal(${driver.id})" class="text-green-600 hover:text-green-800 relative" title="View/Upload Driver Documents">
                                            <i class="fas fa-id-card"></i>
                                            ${documentAlert}
                                        </button>
                                        <form method="POST" action="manage_drivers.php" class="inline">
                                            <input type="hidden" name="driver_id" value="${driver.id}">
                                            <button type="submit" name="delete_driver" class="text-red-600 hover:text-red-800" title="Delete Driver" onclick="return confirm('Are you sure? This will permanently delete the driver and all associated documents.')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        `;
                        driverTableBody.insertAdjacentHTML('beforeend', row);
                    });
                }
            });

            // Driver Document modal functions (Global scope)
            function showDriverDocumentModal(driverId) {
                const modal = document.getElementById('driverDocsModal');
                document.getElementById('modalDriverId').value = driverId;
                
                // Show modal with animation
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.classList.add('active');
                    modal.style.opacity = '1';
                }, 10);
                
                document.body.classList.add('modal-open');
                loadDriverDocuments(driverId);
            }

            function hideDriverDocumentModal() {
                const modal = document.getElementById('driverDocsModal');
                
                // Hide modal with animation
                modal.classList.remove('active');
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.style.display = 'none';
                    modal.style.visibility = 'hidden';
                    // Refresh the page to update KYC status
                    window.location.reload();
                }, 300);
                
                document.body.classList.remove('modal-open');
                
                // Reset form
                const form = document.getElementById('uploadDriverDocumentForm');
                if (form) {
                    form.reset();
                }
                
                const fileDisplay = document.getElementById('driverFileNameDisplay');
                if (fileDisplay) {
                    fileDisplay.textContent = 'No file chosen';
                }
            }

            function loadDriverDocuments(driverId) {
                fetch(`manage_drivers.php?view_docs=${driverId}`)
                    .then(response => response.json())
                    .then(documents => {
                        displayDriverDocuments(documents);
                        updateDriverDocumentStatus(documents);
                    })
                    .catch(error => {
                        console.error('Error loading driver documents:', error);
                        document.getElementById('driverDocumentsList').innerHTML = '<p class="text-red-600">Error loading documents</p>';
                    });
            }

            function displayDriverDocuments(documents) {
                const container = document.getElementById('driverDocumentsList');
                
                if (documents.length === 0) {
                    container.innerHTML = '<p class="text-gray-500 text-center py-4">No documents uploaded yet</p>';
                    return;
                }

                const html = documents.map(doc => {
                    const expiryBadge = getDriverDocExpiryBadge(doc.expiry_date);
                    const fileIcon = doc.file_path.toLowerCase().endsWith('.pdf') ? 'fas fa-file-pdf text-red-600' : 'fas fa-image text-blue-600';
                    
                    return `
                        <div class="border border-gray-200 rounded-lg p-4 bg-white">
                            <div class="flex items-center justify-between mb-2">
                                <h5 class="font-medium text-gray-900">${doc.document_type}</h5>
                                ${expiryBadge}
                            </div>
                            <div class="flex items-center gap-3">
                                <i class="${fileIcon} text-2xl"></i>
                                <div class="flex-1">
                                    <p class="text-sm text-gray-600">Uploaded: ${new Date(doc.uploaded_at).toLocaleDateString()}</p>
                                    ${doc.expiry_date ? `<p class="text-sm text-gray-600">Expires: ${new Date(doc.expiry_date).toLocaleDateString()}</p>` : ''}
                                </div>
                                <a href="Uploads/driver_docs/${doc.file_path}" target="_blank" 
                                   class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    <i class="fas fa-external-link-alt mr-1"></i>View
                                </a>
                            </div>
                        </div>
                    `;
                }).join('');

                container.innerHTML = `<div class="space-y-3">${html}</div>`;
            }

            function getDriverDocExpiryBadge(expiryDate) {
                if (!expiryDate) return '';
                
                const today = new Date();
                const expiry = new Date(expiryDate);
                const diffTime = expiry - today;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                if (diffDays < 0) {
                    return '<span class="badge badge-error">Expired</span>';
                } else if (diffDays <= 30) {
                    return '<span class="badge badge-warning">Expiring Soon</span>';
                } else {
                    return '<span class="badge badge-success">Valid</span>';
                }
            }

            function updateDriverDocumentStatus(documents) {
                const summary = document.getElementById('driverDocStatusSummary');
                const expired = documents.filter(doc => doc.expiry_date && new Date(doc.expiry_date) < new Date()).length;
                const expiring = documents.filter(doc => {
                    if (!doc.expiry_date) return false;
                    const diffDays = Math.ceil((new Date(doc.expiry_date) - new Date()) / (1000 * 60 * 60 * 24));
                    return diffDays > 0 && diffDays <= 30;
                }).length;
                const valid = documents.filter(doc => {
                    if (!doc.expiry_date) return true;
                    const diffDays = Math.ceil((new Date(doc.expiry_date) - new Date()) / (1000 * 60 * 60 * 24));
                    return diffDays > 30;
                }).length;

                summary.innerHTML = `
                    <span class="badge badge-success">${documents.length} Total</span>
                    ${valid > 0 ? `<span class="badge badge-success">${valid} Valid</span>` : ''}
                    ${expiring > 0 ? `<span class="badge badge-warning">${expiring} Expiring</span>` : ''}
                    ${expired > 0 ? `<span class="badge badge-error">${expired} Expired</span>` : ''}
                `;
            }

            // Initialize document upload functionality
            document.addEventListener('DOMContentLoaded', function() {
                // File input change handler for driver documents
                const driverFileInput = document.getElementById('driverDocumentFileInput');
                if (driverFileInput) {
                    driverFileInput.addEventListener('change', function() {
                        const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
                        const displayElement = document.getElementById('driverFileNameDisplay');
                        if (displayElement) {
                            displayElement.textContent = fileName;
                        }
                    });
                }

                // Upload form handler for driver documents
                const driverUploadForm = document.getElementById('uploadDriverDocumentForm');
                if (driverUploadForm) {
                    driverUploadForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        
                        const formData = new FormData(this);
                        formData.append('upload_document', '1');
                        
                        fetch('manage_drivers.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Document uploaded successfully!');
                                const driverId = document.getElementById('modalDriverId').value;
                                loadDriverDocuments(driverId);
                                this.reset();
                                document.getElementById('driverFileNameDisplay').textContent = 'No file chosen';
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while uploading the document.');
                        });
                    });
                }

                // Close modal when clicking outside
                const modal = document.getElementById('driverDocsModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === this) {
                            hideDriverDocumentModal();
                        }
                    });
                }

                // Close modal with escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        const modal = document.getElementById('driverDocsModal');
                        if (modal && modal.classList.contains('active')) {
                            hideDriverDocumentModal();
                        }
                    }
                });

                // Ensure modal is hidden on page load
                const driverModal = document.getElementById('driverDocsModal');
                if (driverModal) {
                    driverModal.style.display = 'none';
                    driverModal.classList.remove('active');
                }
            });
        </script>
    </body>
    </html> 