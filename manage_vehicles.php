<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Handle document uploads (keeping the same functionality)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    header('Content-Type: application/json');
    try {
        $vehicleId = $_POST['vehicle_id'] ?? null;
        $documentType = $_POST['document_type'] ?? null;
        $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        if (!$vehicleId || !$documentType || !isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Missing required fields or no file uploaded');
        }

        // Validate expiry date format if provided
        if ($expiryDate) {
            if (!DateTime::createFromFormat('Y-m-d', $expiryDate)) {
                throw new Exception('Invalid expiry date format. Use YYYY-MM-DD.');
            }
        }

        // Check if document type already exists for this vehicle
        $checkStmt = $pdo->prepare("SELECT id FROM vehicle_documents WHERE vehicle_id = ? AND document_type = ?");
        $checkStmt->execute([$vehicleId, $documentType]);
        if ($checkStmt->rowCount() > 0) {
            throw new Exception("A {$documentType} document already exists for this vehicle");
        }

        // File upload handling
        $uploadDir = 'Uploads/vehicle_docs/';
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

        // Insert into vehicle_documents
        $stmt = $pdo->prepare("
            INSERT INTO vehicle_documents 
            (vehicle_id, document_type, file_path, expiry_date, uploaded_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$vehicleId, $documentType, $fileName, $expiryDate]);

        // Calculate days remaining for alert if expiry date is provided
        if ($expiryDate) {
            try {
                $today = new DateTime();
                $expiry = new DateTime($expiryDate);
                $interval = $today->diff($expiry);
                $daysRemaining = $interval->days * ($interval->invert ? -1 : 1);

                // Insert into document_alerts if document is expired or expiring soon
                if ($daysRemaining <= 30) {
                    $vehicleStmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
                    $vehicleStmt->execute([$vehicleId]);
                    $vehicle = $vehicleStmt->fetch(PDO::FETCH_ASSOC);

                    if ($vehicle) {
                        $alertStmt = $pdo->prepare("
                            INSERT INTO document_alerts 
                            (user_id, vehicle_id, vehicle_number, document_type, expiry_date, days_remaining, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $alertStmt->execute([
                            $_SESSION['user_id'] ?? 0,
                            $vehicleId,
                            $vehicle['vehicle_number'],
                            $documentType,
                            $expiryDate,
                            $daysRemaining
                        ]);
                    }
                }
            } catch (Exception $e) {
                error_log("Date processing error: " . $e->getMessage());
                throw new Exception('Error processing expiry date: ' . $e->getMessage());
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
        $vehicleId = $_GET['view_docs'];
        $stmt = $pdo->prepare("
            SELECT id, document_type, file_path, expiry_date, uploaded_at 
            FROM vehicle_documents 
            WHERE vehicle_id = ?
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$vehicleId]);
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

// Handle delete action
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];

    try {
        $pdo->beginTransaction();

        // First get vehicle number before deleting
        $stmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
        $stmt->execute([$id]);
        $vehicle = $stmt->fetch();

        if ($vehicle) {
            // Delete associated driver
            $pdo->prepare("DELETE FROM drivers WHERE vehicle_number = ?")->execute([$vehicle['vehicle_number']]);

            // Delete associated document alerts
            $pdo->prepare("DELETE FROM document_alerts WHERE vehicle_id = ?")->execute([$id]);

            // Delete vehicle financing
            $pdo->prepare("DELETE FROM vehicle_financing WHERE vehicle_id = ?")->execute([$id]);

            // Delete vehicle documents
            $pdo->prepare("DELETE FROM vehicle_documents WHERE vehicle_id = ?")->execute([$id]);

            // Delete vehicle
            $pdo->prepare("DELETE FROM vehicles WHERE id = ?")->execute([$id]);

            $pdo->commit();
            $_SESSION['success'] = "Vehicle and associated data deleted successfully";
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Vehicle not found";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: manage_vehicles.php");
    exit();
}

// Get filter parameters (updated with new filters)
$filter_status = $_GET['status'] ?? '';
$filter_financing = $_GET['financing'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_owner = $_GET['owner'] ?? '';

// Base query (updated with new fields)
$query = "
    SELECT 
        v.*, 
        vf.is_financed,
        vf.bank_name,
        vf.emi_amount
    FROM vehicles v
    LEFT JOIN vehicle_financing vf ON v.id = vf.vehicle_id
";

// Add filters if specified
$params = [];
$whereAdded = false;

if ($filter_status) {
    $query .= " WHERE v.current_status = ?";
    $params[] = $filter_status;
    $whereAdded = true;
}

if ($filter_financing) {
    $query .= $whereAdded ? " AND" : " WHERE";
    if ($filter_financing === 'financed') {
        $query .= " v.is_financed = 1";
    } elseif ($filter_financing === 'non-financed') {
        $query .= " v.is_financed = 0";
    }
    $whereAdded = true;
}

if ($filter_year) {
    $query .= $whereAdded ? " AND" : " WHERE";
    $query .= " v.manufacturing_year = ?";
    $params[] = $filter_year;
    $whereAdded = true;
}

if ($filter_owner) {
    $query .= $whereAdded ? " AND" : " WHERE";
    $query .= " v.owner_name LIKE ?";
    $params[] = "%$filter_owner%";
}

$query .= " ORDER BY v.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct years for filter
$years = $pdo->query("SELECT DISTINCT manufacturing_year FROM vehicles WHERE manufacturing_year IS NOT NULL ORDER BY manufacturing_year DESC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Vehicles - Fleet Management</title>
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
            .badge-available {
                @apply bg-green-100 text-green-800;
            }
            .badge-loaded {
                @apply bg-blue-100 text-blue-800;
            }
            .badge-on_trip {
                @apply bg-amber-100 text-amber-800;
            }
            .badge-maintenance {
                @apply bg-red-100 text-red-800;
            }
            .badge-financed {
                @apply bg-purple-100 text-purple-800;
            }
            .badge-non-financed {
                @apply bg-gray-100 text-gray-800;
            }
            .card-hover-effect {
                @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
            }
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
            z-index: 1000;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-container {
            background: white;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        body.modal-open {
            overflow: hidden;
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
                            <p class="text-blue-600 text-sm">Manage Vehicles</p>
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
                                <a href="manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
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
                <main class="flex-1" style="max-width:80%">
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-check-circle text-green-600"></i>
                                <p><?= htmlspecialchars($_SESSION['success']) ?></p>
                            </div>
                            <?php unset($_SESSION['success']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-exclamation-circle text-red-600"></i>
                                <p><?= htmlspecialchars($_SESSION['error']) ?></p>
                            </div>
                            <?php unset($_SESSION['error']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                            <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-list text-blue-600"></i> Vehicle List
                            </h2>
                            
                            <!-- Search Bar -->
                            <div class="relative w-full md:w-64">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" id="searchInput" placeholder="Search vehicles..." 
                                       class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                       onkeyup="filterVehicles()">
                            </div>
                        </div>
                        
                        <!-- Filters -->
                        <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                            <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                                <i class="fas fa-filter text-blue-600"></i> Filter Vehicles
                            </h2>

                            <form method="GET" class="flex flex-wrap gap-6 items-end">
                                <div class="flex flex-col w-48">
                                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                    <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">All Status</option>
                                        <option value="available" <?= $filter_status === 'available' ? 'selected' : '' ?>>Available</option>
                                        <option value="loaded" <?= $filter_status === 'loaded' ? 'selected' : '' ?>>Loaded</option>
                                        <option value="on_trip" <?= $filter_status === 'on_trip' ? 'selected' : '' ?>>On Trip</option>
                                        <option value="maintenance" <?= $filter_status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                    </select>
                                </div>
                                
                                <div class="flex flex-col w-48">
                                    <label for="financing" class="block text-sm font-medium text-gray-700 mb-1">Financing</label>
                                    <select id="financing" name="financing" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">All Vehicles</option>
                                        <option value="financed" <?= $filter_financing === 'financed' ? 'selected' : '' ?>>Financed</option>
                                        <option value="non-financed" <?= $filter_financing === 'non-financed' ? 'selected' : '' ?>>Non-Financed</option>
                                    </select>
                                </div>
                                
                                <div class="flex flex-col w-48">
                                    <label for="year" class="block text-sm font-medium text-gray-700 mb-1">Manufacturing Year</label>
                                    <select id="year" name="year" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">All Years</option>
                                        <?php foreach ($years as $year): ?>
                                            <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="flex flex-col w-48">
                                    <label for="owner" class="block text-sm font-medium text-gray-700 mb-1">Owner Name</label>
                                    <input type="text" id="owner" name="owner" value="<?= htmlspecialchars($filter_owner) ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="Search by owner name">
                                </div>
                                
                                <div class="flex gap-2">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-filter mr-2"></i> Apply Filters
                                    </button>
                                    <a href="manage_vehicles.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Clear
                                    </a>
                                </div>
                            </form>
                        </div>
                            
                        </div>
                        
                        <?php if (empty($vehicles)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-truck-loading text-4xl text-gray-400 mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-700">No Vehicles Found</h3>
                                <p class="text-gray-500 mt-2">Get started by adding your first vehicle</p>
                                <a href="add_vehicle.php" class="inline-flex items-center px-4 py-2 mt-4 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-plus mr-2"></i> Add Vehicle
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle Details</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Driver & Owner</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle Info</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Financing</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">EMI</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="vehicleTableBody" class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <tr class="vehicle-row hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="font-medium text-gray-900 flex items-center gap-2">
                                                        <i class="fa-solid fa-truck-moving text-blue-600"></i>
                                                        <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                                    </div>
                                                    <?php if ($vehicle['make_model']): ?>
                                                        <div class="text-sm text-gray-500"><?= htmlspecialchars($vehicle['make_model']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($vehicle['manufacturing_year']): ?>
                                                        <div class="text-xs text-gray-400">Year: <?= $vehicle['manufacturing_year'] ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <i class="fas fa-user text-gray-400 mr-1"></i>
                                                        <?= htmlspecialchars($vehicle['driver_name']) ?>
                                                    </div>
                                                    <?php if ($vehicle['owner_name']): ?>
                                                        <div class="text-sm text-gray-500">
                                                            <i class="fas fa-crown text-yellow-500 mr-1"></i>
                                                            <?= htmlspecialchars($vehicle['owner_name']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($vehicle['gvw']): ?>
                                                        <div class="text-sm text-gray-900">
                                                            <i class="fas fa-weight-hanging text-gray-400 mr-1"></i>
                                                            <?= number_format($vehicle['gvw'], 2) ?> kg
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">GVW: N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="badge badge-<?= strtolower($vehicle['current_status']) ?>">
                                                        <?= ucfirst(str_replace('_', ' ', $vehicle['current_status'])) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="badge badge-<?= $vehicle['is_financed'] ? 'financed' : 'non-financed' ?>">
                                                        <?= $vehicle['is_financed'] ? 'Financed' : 'No' ?>
                                                    </span>
                                                    <?php if ($vehicle['is_financed'] && $vehicle['bank_name']): ?>
                                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($vehicle['bank_name']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($vehicle['is_financed'] && $vehicle['emi_amount']): ?>
                                                        <div class="text-sm font-medium text-gray-900">₹<?= number_format($vehicle['emi_amount'], 2) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center gap-2">
                                                        <a href="edit_vehicle.php?id=<?= $vehicle['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Edit Vehicle">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="manage_vehicles.php?delete=<?= $vehicle['id'] ?>" class="text-red-600 hover:text-red-800" title="Delete Vehicle" onclick="return confirm('Are you sure you want to delete this vehicle and its associated data?');">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                        <button onclick="showDocumentModal(<?= $vehicle['id'] ?>)" class="text-green-600 hover:text-green-800" title="View/Upload Documents">
                                                            <i class="fas fa-file-alt"></i>
                                                        </button>
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

            <!-- Documents Modal -->
            <div class="modal-overlay" id="docsModal">
                <div class="modal-container">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Vehicle Documents</h3>
                            <button type="button" class="text-gray-400 hover:text-gray-500" onclick="hideDocumentModal()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Status Summary -->
                        <div id="docStatusSummary" class="mb-4 flex gap-2"></div>
                        
                        <!-- Documents List -->
                        <div id="documentsList" class="mb-6">
                            <!-- Content loaded dynamically -->
                        </div>
                        
                        <!-- Upload Section -->
                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-md font-medium text-gray-900 mb-4">Upload New Document</h4>
                            
                            <form id="uploadDocumentForm" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="vehicle_id" id="modalVehicleId">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <!-- Left Column -->
                                    <div class="space-y-4">
                                        <div>
                                            <label for="document_type" class="block text-sm font-medium text-gray-700 mb-1">Document Type</label>
                                            <select name="document_type" id="document_type" required 
                                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                                <option value="">Select Document Type</option>
                                                <option value="Registration">Registration</option>
                                                <option value="Insurance">Insurance</option>
                                                <option value="Fitness Certificate">Fitness Certificate</option>
                                                <option value="Permit">Permit</option>
                                                <option value="Permit Authorization">Permit Authorization</option>
                                                <option value="Road Tax">Road Tax</option>
                                                <option value="Pollution Certificate">Pollution Certificate</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        
                                        <div>
                                            <label for="expiry_date" class="block text-sm font-medium text-gray-700 mb-1">Expiry Date</label>
                                            <input type="date" name="expiry_date" id="expiry_date"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        </div>
                                    </div>
                                    
                                    <!-- Right Column - File Upload -->
                                    <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                        <div class="flex items-center gap-4">
                                            <div class="flex-1">
                                                <label for="document_file" class="block text-sm font-medium text-gray-700 mb-1">Document File</label>
                                                <input type="file" name="document_file" id="documentFileInput" 
                                                       accept=".jpg,.jpeg,.png,.pdf" required 
                                                       class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                            </div>
                                            <button type="submit" 
                                                    class="h-fit px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                <i class="fas fa-upload mr-2"></i> Upload
                                            </button>
                                        </div>
                                        <p id="fileNameDisplay" class="text-xs text-gray-500 mt-2 truncate">No file chosen</p>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="flex justify-end gap-2 pt-4">
                                    <button type="button" onclick="hideDocumentModal()" 
                                            class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        Close
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script src="vehicle-docs.js"></script>
        <script>
            // Vehicle search functionality
            function filterVehicles() {
                const input = document.getElementById('searchInput');
                const filter = input.value.toUpperCase();
                const table = document.getElementById('vehicleTableBody');
                const rows = table.getElementsByClassName('vehicle-row');
                
                for (let i = 0; i < rows.length; i++) {
                    const vehicleNumber = rows[i].cells[0].textContent || rows[i].cells[0].innerText;
                    const driverOwner = rows[i].cells[1].textContent || rows[i].cells[1].innerText;
                    const makeModel = rows[i].cells[2].textContent || rows[i].cells[2].innerText;
                    const last4Digits = vehicleNumber.slice(-4);
                    
                    if (vehicleNumber.toUpperCase().indexOf(filter) > -1 || 
                        driverOwner.toUpperCase().indexOf(filter) > -1 ||
                        makeModel.toUpperCase().indexOf(filter) > -1 ||
                        (filter.length <= 4 && last4Digits.indexOf(filter) > -1)) {
                        rows[i].style.display = "";
                    } else {
                        rows[i].style.display = "none";
                    }
                }
            }

            // Document modal functions
            function showDocumentModal(vehicleId) {
                document.getElementById('modalVehicleId').value = vehicleId;
                document.getElementById('docsModal').classList.add('active');
                document.body.classList.add('modal-open');
                loadDocuments(vehicleId);
            }

            function hideDocumentModal() {
                document.getElementById('docsModal').classList.remove('active');
                document.body.classList.remove('modal-open');
                document.getElementById('uploadDocumentForm').reset();
                document.getElementById('fileNameDisplay').textContent = 'No file chosen';
            }

            function loadDocuments(vehicleId) {
                fetch(`manage_vehicles.php?view_docs=${vehicleId}`)
                    .then(response => response.json())
                    .then(documents => {
                        displayDocuments(documents);
                        updateDocumentStatus(documents);
                    })
                    .catch(error => {
                        console.error('Error loading documents:', error);
                        document.getElementById('documentsList').innerHTML = '<p class="text-red-600">Error loading documents</p>';
                    });
            }

            function displayDocuments(documents) {
                const container = document.getElementById('documentsList');
                
                if (documents.length === 0) {
                    container.innerHTML = '<p class="text-gray-500 text-center py-4">No documents uploaded yet</p>';
                    return;
                }

                const html = documents.map(doc => {
                    const expiryBadge = getExpiryBadge(doc.expiry_date);
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
                                <a href="Uploads/vehicle_docs/${doc.file_path}" target="_blank" 
                                   class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    <i class="fas fa-external-link-alt mr-1"></i>View
                                </a>
                            </div>
                        </div>
                    `;
                }).join('');

                container.innerHTML = `<div class="space-y-3">${html}</div>`;
            }

            function getExpiryBadge(expiryDate) {
                if (!expiryDate) return '';
                
                const today = new Date();
                const expiry = new Date(expiryDate);
                const diffTime = expiry - today;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                if (diffDays < 0) {
                    return '<span class="badge badge-expired">Expired</span>';
                } else if (diffDays <= 30) {
                    return '<span class="badge badge-expiring">Expiring Soon</span>';
                } else {
                    return '<span class="badge badge-valid">Valid</span>';
                }
            }

            function updateDocumentStatus(documents) {
                const summary = document.getElementById('docStatusSummary');
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
                    ${valid > 0 ? `<span class="badge badge-valid">${valid} Valid</span>` : ''}
                    ${expiring > 0 ? `<span class="badge badge-expiring">${expiring} Expiring</span>` : ''}
                    ${expired > 0 ? `<span class="badge badge-expired">${expired} Expired</span>` : ''}
                `;
            }

            // File input change handler
            document.getElementById('documentFileInput').addEventListener('change', function() {
                const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
                document.getElementById('fileNameDisplay').textContent = fileName;
            });

            // Upload form handler
            document.getElementById('uploadDocumentForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                formData.append('upload_document', '1');
                
                fetch('manage_vehicles.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Document uploaded successfully!');
                        loadDocuments(document.getElementById('modalVehicleId').value);
                        this.reset();
                        document.getElementById('fileNameDisplay').textContent = 'No file chosen';
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while uploading the document.');
                });
            });
        </script>
</body>
</html>