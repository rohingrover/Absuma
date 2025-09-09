<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

$user_role = $_SESSION['role'];

// Check user permissions
$can_edit = in_array($user_role, ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin']);
$can_approve = in_array($user_role, ['manager1', 'manager2', 'admin', 'superadmin']);
$can_delete_directly = in_array($user_role, ['admin', 'superadmin']);

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

// Handle driver edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_driver'])) {
    try {
        $driver_id = $_POST['driver_id'];
        $name = trim($_POST['name']);
        $status = $_POST['status'];

        // Validation
        if (empty($name)) {
            throw new Exception("Name is required");
        }

        $updateStmt = $pdo->prepare("UPDATE drivers SET name = ?, status = ? WHERE id = ?");
        $updateStmt->execute([$name, $status, $driver_id]);

        $_SESSION['success'] = "Driver updated successfully";
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: manage_drivers.php");
    exit;
}

// Handle AJAX request for driver details
if (isset($_GET['get_driver_details'])) {
    try {
        $driverId = $_GET['get_driver_details'];
        $stmt = $pdo->prepare("SELECT * FROM drivers WHERE id = ?");
        $stmt->execute([$driverId]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($driver);
        exit;
    } catch (Exception $e) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle driver deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_driver'])) {
    $driverId = $_POST['driver_id'];

    try {
        $pdo->beginTransaction();
        
        // Get driver details
        $stmt = $pdo->prepare("SELECT name, vehicle_number FROM drivers WHERE id = ?");
        $stmt->execute([$driverId]);
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$driver) {
            throw new Exception("Driver not found");
        }

        if ($can_delete_directly) {
            // Direct delete
            $pdo->prepare("DELETE FROM driver_documents WHERE driver_id = ?")->execute([$driverId]);
            $pdo->prepare("DELETE FROM driver_document_alerts WHERE driver_id = ?")->execute([$driverId]);
            $pdo->prepare("DELETE FROM drivers WHERE id = ?")->execute([$driverId]);
            
            // Unassign from vehicle
            if (!empty($driver['vehicle_number'])) {
                $pdo->prepare("UPDATE vehicles SET driver_name = NULL WHERE vehicle_number = ?")->execute([$driver['vehicle_number']]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Driver deleted successfully";
        } else {
            // Request deletion
            $requestStmt = $pdo->prepare("
                INSERT INTO driver_deletion_requests 
                (driver_id, requested_by, requested_at, reason, status) 
                VALUES (?, ?, NOW(), ?, 'pending')
            ");
            $requestStmt->execute([
                $driverId,
                $_SESSION['user_id'],
                "Deletion requested by " . $_SESSION['full_name']
            ]);

            // Notify managers
            $managerStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('manager1', 'manager2', 'admin', 'superadmin') AND status = 'active'");
            $managerStmt->execute();
            $managers = $managerStmt->fetchAll(PDO::FETCH_ASSOC);

            $notificationStmt = $pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");

            foreach ($managers as $manager) {
                $notificationStmt->execute([
                    $manager['id'],
                    'Driver Deletion Approval Required',
                    "Deletion of driver '{$driver['name']}' has been requested by " . $_SESSION['full_name'],
                    'warning'
                ]);
            }

            $pdo->commit();
            $_SESSION['success'] = "Driver deletion request submitted for approval";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: manage_drivers.php");
    exit;
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
    <title>Manage Drivers - Absuma Logistics</title>
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

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0s linear 0.3s;
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
            visibility: visible;
            transition: opacity 0.3s ease, visibility 0s linear;
        }

        .modal-container {
            background: white;
            border-radius: 0.75rem;
            width: 90%;
            max-width: 900px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 1.5rem;
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
                        <h1 class="text-2xl font-bold text-gray-900">Manage Drivers</h1>
                        <p class="text-sm text-gray-600 mt-1">View and manage driver information and KYC</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                            <form method="GET" class="inline">
                                <input
                                    type="text"
                                    name="search"
                                    value="<?= htmlspecialchars($searchTerm) ?>"
                                    placeholder="Search drivers..."
                                    class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm w-64"
                                />
                            </form>
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
                                <div class="text-red-700"><?= $error ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="bg-white rounded-xl shadow-soft p-6 mb-6 card-hover-effect animate-fade-in">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-chart-bar text-teal-600"></i> Driver Statistics
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-gray-50 p-4 rounded-lg text-center border border-gray-100">
                                <div class="text-2xl font-bold text-gray-600"><?= $kycStats['total_drivers'] ?></div>
                                <div class="text-sm text-gray-800">Total Drivers</div>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg text-center border border-green-100">
                                <div class="text-2xl font-bold text-green-600"><?= $kycStats['kyc_completed'] ?></div>
                                <div class="text-sm text-green-800">KYC Completed</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg text-center border border-red-100">
                                <div class="text-2xl font-bold text-red-600"><?= $kycStats['kyc_pending'] ?></div>
                                <div class="text-sm text-red-800">KYC Pending</div>
                            </div>
                        </div>
                    </div>

                    <!-- Drivers List -->
                    <?php if (empty($drivers)): ?>
                        <div class="bg-white rounded-xl shadow-soft p-12 text-center card-hover-effect">
                            <i class="fas fa-users text-6xl text-gray-400 mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-700 mb-2">No Drivers Found</h3>
                            <p class="text-gray-500 mb-6">Get started by adding your first driver or adjust your search</p>
                            <a href="add_driver.php" class="inline-flex items-center px-6 py-3 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors font-medium">
                                <i class="fas fa-plus mr-2"></i> Add First Driver
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden card-hover-effect">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Driver</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Owner</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">GVW</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">KYC</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="driverTableBody" class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($drivers as $driver): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-10 w-10">
                                                            <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                                <i class="fas fa-user text-gray-500"></i>
                                                            </div>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($driver['name'] ?? 'N/A') ?></div>
                                                            <div class="text-sm text-gray-500"><?= $driver['document_count'] ?> document(s)</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= htmlspecialchars($driver['vehicle_number'] ?? 'No vehicle assigned') ?>
                                                    <?php if ($driver['make_model']): ?>
                                                        <div class="text-xs text-gray-400"><?= htmlspecialchars($driver['make_model']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($driver['manufacturing_year']): ?>
                                                        <div class="text-xs text-gray-400">Year: <?= htmlspecialchars($driver['manufacturing_year']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($driver['owner_name'] ?? 'N/A') ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= number_format($driver['gvw'] ?? 0) ?> kg</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= ucfirst($driver['status'] ?? 'active') ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?= $driver['kyc_completed'] ? 'Completed' : 'Pending' ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="flex items-center space-x-3">
                                                        <?php if ($can_edit): ?>
                                                            <button onclick="showEditDriverModal(<?= $driver['id'] ?>)" class="text-blue-600 hover:text-blue-800" title="Edit Driver">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <button onclick="showDriverDocumentModal(<?= $driver['id'] ?>)" class="text-green-600 hover:text-green-800" title="Manage Documents">
                                                            <i class="fas fa-file-alt"></i>
                                                        </button>
                                                        <form method="POST">
                                                            <input type="hidden" name="driver_id" value="<?= $driver['id'] ?>">
                                                            <button type="submit" name="delete_driver" class="text-red-600 hover:text-red-800" onclick="return confirm('Are you sure you want to <?= $can_delete_directly ? 'delete' : 'request deletion for' ?> this driver?')" title="<?= $can_delete_directly ? 'Delete' : 'Request Deletion' ?> Driver">
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
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Edit Driver Modal -->
    <div id="editDriverModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Edit Driver</h3>
                <form method="POST" class="mt-2 text-left">
                    <input type="hidden" name="edit_driver" value="1">
                    <input type="hidden" id="edit_driver_id" name="driver_id">
                    <div class="mb-4">
                        <label for="edit_name" class="block text-sm font-medium text-gray-700">Name</label>
                        <input type="text" id="edit_name" name="name" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <div class="mb-4">
                        <label for="edit_status" class="block text-sm font-medium text-gray-700">Status</label>
                        <select id="edit_status" name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" onclick="hideEditDriverModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-teal-600 text-white rounded-md">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Driver Documents Modal -->
    <div id="driverDocsModal" class="modal-overlay">
        <div class="modal-container">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Driver Documents</h3>
                    <button onclick="hideDriverDocumentModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- Document Upload Form -->
                <form id="uploadDriverDocumentForm" method="POST" enctype="multipart/form-data" class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <input type="hidden" id="modalDriverId" name="driver_id">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="document_type" class="block text-sm font-medium text-gray-700">Document Type</label>
                            <select id="document_type" name="document_type" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option value="">Select Type</option>
                                <option value="Aadhar Card">Aadhar Card</option>
                                <option value="PAN Card">PAN Card</option>
                                <option value="Driving License">Driving License</option>
                                <option value="Medical Certificate">Medical Certificate</option>
                                <option value="Police Verification">Police Verification</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label for="expiry_date" class="block text-sm font-medium text-gray-700">Expiry Date (if applicable)</label>
                            <input type="date" id="expiry_date" name="expiry_date" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                        </div>
                        <div class="col-span-2">
                            <label for="document_file" class="block text-sm font-medium text-gray-700">Upload File</label>
                            <input type="file" id="driverDocumentFileInput" name="document_file" required accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <p id="driverFileNameDisplay" class="text-sm text-gray-500 mt-1">No file chosen</p>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-teal-600 text-white py-2 rounded-md hover:bg-teal-700">Upload Document</button>
                </form>
                
                <!-- Documents List -->
                <div id="driverDocumentsList" class="space-y-4"></div>
            </div>
        </div>
    </div>

    <script>
        // Edit driver modal functions
        function showEditDriverModal(driverId) {
            const modal = document.getElementById('editDriverModal');
            modal.classList.remove('hidden');
            fetchDriverDetails(driverId);
        }

        function hideEditDriverModal() {
            const modal = document.getElementById('editDriverModal');
            modal.classList.add('hidden');
        }

        function fetchDriverDetails(driverId) {
            fetch(`manage_drivers.php?get_driver_details=${driverId}`)
                .then(response => response.json())
                .then(driver => {
                    document.getElementById('edit_driver_id').value = driver.id;
                    document.getElementById('edit_name').value = driver.name;
                    document.getElementById('edit_status').value = driver.status;
                })
                .catch(error => console.error('Error:', error));
        }

        // Document modal functions
        function showDriverDocumentModal(driverId) {
            const modal = document.getElementById('driverDocsModal');
            document.getElementById('modalDriverId').value = driverId;
            modal.classList.add('active');
            loadDriverDocuments(driverId);
        }

        function hideDriverDocumentModal() {
            const modal = document.getElementById('driverDocsModal');
            modal.classList.remove('active');
        }

        function loadDriverDocuments(driverId) {
            fetch(`manage_drivers.php?view_docs=${driverId}`)
                .then(response => response.json())
                .then(documents => {
                    const container = document.getElementById('driverDocumentsList');
                    container.innerHTML = documents.map(doc => `
                        <div class="p-4 border rounded-md">
                            <p>${doc.document_type}</p>
                            <p>Uploaded: ${new Date(doc.uploaded_at).toLocaleDateString()}</p>
                            ${doc.expiry_date ? `<p>Expires: ${new Date(doc.expiry_date).toLocaleDateString()}</p>` : ''}
                            <a href="Uploads/driver_docs/${doc.file_path}" target="_blank">View</a>
                        </div>
                    `).join('');
                });
        }

        // Upload form
        document.getElementById('uploadDriverDocumentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('upload_document', '1');
            fetch('manage_drivers.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadDriverDocuments(document.getElementById('modalDriverId').value);
                        this.reset();
                        document.getElementById('driverFileNameDisplay').textContent = 'No file chosen';
                    } else {
                        alert(data.message);
                    }
                });
        });

        // Initialize modals
        document.addEventListener('DOMContentLoaded', () => {
            const editModal = document.getElementById('editDriverModal');
            const docModal = document.getElementById('driverDocsModal');

            // Close modals on outside click
            [editModal, docModal].forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        modal.classList.add('hidden');
                        docModal.classList.remove('active');
                    }
                });
            });

            // Close modals on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    editModal.classList.add('hidden');
                    docModal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>