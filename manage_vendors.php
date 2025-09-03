<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Handle document uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_vendor_document'])) {
    header('Content-Type: application/json');
    try {
        $vendorId = $_POST['vendor_id'] ?? null;
        $documentType = $_POST['document_type'] ?? null;
        $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        if (!$vendorId || !$documentType || !isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception('Missing required fields or no file uploaded');
        }

        // Validate expiry date format if provided
        if ($expiryDate) {
            if (!DateTime::createFromFormat('Y-m-d', $expiryDate)) {
                throw new Exception('Invalid expiry date format. Use YYYY-MM-DD.');
            }
        }

        // Check if document type already exists for this vendor
        $checkStmt = $pdo->prepare("SELECT id FROM vendor_documents WHERE vendor_id = ? AND document_type = ?");
        $checkStmt->execute([$vendorId, $documentType]);
        if ($checkStmt->rowCount() > 0) {
            throw new Exception("A {$documentType} document already exists for this vendor");
        }

        // File upload handling
        $uploadDir = 'Uploads/vendor_docs/';
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

        // Insert into vendor_documents
        $stmt = $pdo->prepare("
            INSERT INTO vendor_documents 
            (vendor_id, document_type, document_name, file_path, file_size, file_type, expiry_date, uploaded_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $vendorId, 
            $documentType, 
            $_FILES['document_file']['name'],
            $fileName, 
            $_FILES['document_file']['size'],
            $fileExtension,
            $expiryDate
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Document uploaded successfully'
        ]);
        exit;

    } catch (Exception $e) {
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
if (isset($_GET['view_vendor_docs'])) {
    try {
        $vendorId = $_GET['view_vendor_docs'];
        $stmt = $pdo->prepare("
            SELECT id, document_type, document_name, file_path, file_size, file_type, expiry_date, is_verified, uploaded_at 
            FROM vendor_documents 
            WHERE vendor_id = ? AND deleted_at IS NULL
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$vendorId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($documents);
        exit;
    } catch (Exception $e) {
        error_log("Error fetching vendor documents: " . $e->getMessage());
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle vendor deletion
if (isset($_GET['delete_vendor'])) {
    $vendorId = $_GET['delete_vendor'];

    try {
        $pdo->beginTransaction();

        // Get vendor details before deletion
        $stmt = $pdo->prepare("SELECT vendor_code, company_name FROM vendors WHERE id = ?");
        $stmt->execute([$vendorId]);
        $vendor = $stmt->fetch();

        if ($vendor) {
            // Soft delete vendor vehicles
            $pdo->prepare("UPDATE vendor_vehicles SET deleted_at = NOW() WHERE vendor_id = ?")->execute([$vendorId]);

            // Soft delete vendor documents
            $pdo->prepare("UPDATE vendor_documents SET deleted_at = NOW() WHERE vendor_id = ?")->execute([$vendorId]);

            // Soft delete vendor contacts
            $pdo->prepare("DELETE FROM vendor_contacts WHERE vendor_id = ?")->execute([$vendorId]);

            // Soft delete vendor services
            $pdo->prepare("DELETE FROM vendor_services WHERE vendor_id = ?")->execute([$vendorId]);

            // Soft delete the vendor
            $pdo->prepare("UPDATE vendors SET deleted_at = NOW() WHERE id = ?")->execute([$vendorId]);

            $pdo->commit();
            $_SESSION['success'] = "Vendor '{$vendor['company_name']}' and all associated data deleted successfully";
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Vendor not found";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: manage_vendors.php");
    exit();
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';
$search_term = $_GET['search'] ?? '';

// Base query - removed establishment_year and division references
$query = "
    SELECT 
        v.*,
        COUNT(DISTINCT vv.id) as vehicle_count,
        COUNT(DISTINCT vd.id) as document_count,
        COUNT(DISTINCT CASE WHEN vd.is_verified = 1 THEN vd.id END) as verified_documents
    FROM vendors v
    LEFT JOIN vendor_vehicles vv ON v.id = vv.vendor_id AND vv.deleted_at IS NULL
    LEFT JOIN vendor_documents vd ON v.id = vd.vendor_id AND vd.deleted_at IS NULL
    WHERE v.deleted_at IS NULL
";

// Add filters
$params = [];
if ($filter_status) {
    $query .= " AND v.status = ?";
    $params[] = $filter_status;
}
if ($filter_type) {
    $query .= " AND v.vendor_type = ?";
    $params[] = $filter_type;
}
if ($search_term) {
    $query .= " AND (v.company_name LIKE ? OR v.vendor_code LIKE ? OR v.contact_person LIKE ?)";
    $searchParam = "%$search_term%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " GROUP BY v.id ORDER BY v.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Display session messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Get vendor statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_vendors,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_vendors,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_vendors,
        COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_vendors
    FROM vendors 
    WHERE deleted_at IS NULL
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Vendors - Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="notifications.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @layer utilities {
            .badge {
                @apply inline-block px-2.5 py-1 rounded-full text-xs font-semibold;
            }
            .badge-active {
                @apply bg-green-100 text-green-800;
            }
            .badge-pending {
                @apply bg-yellow-100 text-yellow-800;
            }
            .badge-approved {
                @apply bg-blue-100 text-blue-800;
            }
            .badge-rejected {
                @apply bg-red-100 text-red-800;
            }
            .badge-suspended {
                @apply bg-gray-100 text-gray-800;
            }
            .badge-transport {
                @apply bg-purple-100 text-purple-800;
            }
            .badge-maintenance {
                @apply bg-orange-100 text-orange-800;
            }
            .badge-fuel {
                @apply bg-red-100 text-red-800;
            }
            .badge-parts {
                @apply bg-indigo-100 text-indigo-800;
            }
            .badge-other {
                @apply bg-gray-100 text-gray-800;
            }
            .card-hover-effect {
                @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
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
                            <i class="fas fa-handshake text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Manage Vendors</p>
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
                    
                    <!-- Vendor Statistics -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-chart-bar text-blue-600"></i> Vendor Overview
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-blue-600"><?= $stats['total_vendors'] ?></div>
                                <div class="text-sm text-blue-800">Total Vendors</div>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-green-600"><?= $stats['active_vendors'] ?></div>
                                <div class="text-sm text-green-800">Active</div>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-yellow-600"><?= $stats['pending_vendors'] ?></div>
                                <div class="text-sm text-yellow-800">Pending</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-red-600"><?= $stats['suspended_vendors'] ?></div>
                                <div class="text-sm text-red-800">Suspended</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters and Search - Removed division filter -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                            <i class="fas fa-filter text-blue-600"></i> Filter Vendors
                        </h2>

                        <form method="GET" class="flex flex-wrap gap-6 items-end">
                            <div class="flex flex-col w-48">
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">All Status</option>
                                    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                    <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                            </div>
                            
                            <div class="flex flex-col w-48">
                                <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Vendor Type</label>
                                <select id="type" name="type" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">All Types</option>
                                    <option value="Transport" <?= $filter_type === 'Transport' ? 'selected' : '' ?>>Transport</option>
                                    <option value="Maintenance" <?= $filter_type === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                    <option value="Fuel" <?= $filter_type === 'Fuel' ? 'selected' : '' ?>>Fuel</option>
                                    <option value="Parts Supplier" <?= $filter_type === 'Parts Supplier' ? 'selected' : '' ?>>Parts Supplier</option>
                                    <option value="Other" <?= $filter_type === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="flex flex-col w-64">
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_term) ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Search vendors...">
                            </div>
                            
                            <div class="flex gap-2">
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-filter mr-2"></i> Apply Filters
                                </button>
                                <a href="manage_vendors.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-times mr-2"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Vendors List -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                            <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-users text-blue-600"></i> Vendor List (<?= count($vendors) ?>)
                            </h2>
                            
                            <a href="vendor_registration.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-plus mr-2"></i> Add New Vendor
                            </a>
                        </div>
                        
                        <?php if (empty($vendors)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-handshake text-4xl text-gray-400 mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-700">No Vendors Found</h3>
                                <p class="text-gray-500 mt-2">Get started by registering your first vendor</p>
                                <a href="vendor_registration.php" class="inline-flex items-center px-4 py-2 mt-4 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-plus mr-2"></i> Register Vendor
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor Details</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Info</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type & Business</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fleet & Docs</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($vendors as $vendor): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                            <i class="fas fa-building text-blue-600"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($vendor['company_name']) ?></div>
                                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($vendor['vendor_code']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <div class="font-medium"><?= htmlspecialchars($vendor['contact_person']) ?></div>
                                                        <div class="text-gray-500"><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($vendor['mobile']) ?></div>
                                                        <div class="text-gray-500"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($vendor['email']) ?></div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="space-y-1">
                                                        <span class="badge badge-<?= strtolower(str_replace(' ', '', $vendor['vendor_type'])) ?>">
                                                            <?= htmlspecialchars($vendor['vendor_type']) ?>
                                                        </span>
                                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($vendor['business_nature']) ?></div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <div><i class="fas fa-truck text-blue-500 mr-1"></i><?= $vendor['vehicle_count'] ?> vehicles</div>
                                                        <div><i class="fas fa-file text-green-500 mr-1"></i><?= $vendor['document_count'] ?> docs (<?= $vendor['verified_documents'] ?> verified)</div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="badge badge-<?= strtolower($vendor['status']) ?>">
                                                        <?= ucfirst($vendor['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center gap-2">
                                                        <a href="edit_vendor.php?id=<?= $vendor['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Edit Vendor">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button onclick="showVendorDocumentModal(<?= $vendor['id'] ?>)" class="text-green-600 hover:text-green-800" title="View/Upload Documents">
                                                            <i class="fas fa-file-alt"></i>
                                                        </button>
                                                        <button onclick="viewVendorVehicles(<?= $vendor['id'] ?>)" class="text-purple-600 hover:text-purple-800" title="View Vehicles">
                                                            <i class="fas fa-truck"></i>
                                                        </button>
                                                        <?php if ($vendor['status'] === 'pending'): ?>
                                                            <button onclick="approveVendor(<?= $vendor['id'] ?>)" class="text-green-600 hover:text-green-800" title="Approve Vendor">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button onclick="rejectVendor(<?= $vendor['id'] ?>)" class="text-red-600 hover:text-red-800" title="Reject Vendor">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <a href="manage_vendors.php?delete_vendor=<?= $vendor['id'] ?>" class="text-red-600 hover:text-red-800" title="Delete Vendor" onclick="return confirm('Are you sure you want to delete this vendor and all associated data? This action cannot be undone.');">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
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

            <!-- Vendor Documents Modal -->
            <div class="modal-overlay" id="vendorDocsModal" style="display: none !important;">
                <div class="modal-container" onclick="event.stopPropagation()">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6 border-b border-gray-200 pb-4">
                            <h3 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-file-alt text-blue-600"></i>
                                Vendor Documents
                            </h3>
                            <button type="button" class="text-gray-400 hover:text-gray-600 p-2 rounded-full hover:bg-gray-100 transition-colors" onclick="hideVendorDocumentModal()">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                        
                        <!-- Status Summary -->
                        <div id="vendorDocStatusSummary" class="mb-4 flex gap-2"></div>
                        
                        <!-- Documents List -->
                        <div id="vendorDocumentsList" class="mb-6">
                            <!-- Content loaded dynamically -->
                        </div>
                        
                        <!-- Upload Section -->
                        <div class="border-t border-gray-200 pt-6 mt-6 bg-gray-50 -mx-6 px-6 rounded-b-xl">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <i class="fas fa-upload text-blue-600"></i>
                                Upload New Document
                            </h4>
                            
                            <form id="uploadVendorDocumentForm" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="vendor_id" id="modalVendorId">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Left Column -->
                                    <div class="space-y-4">
                                        <div>
                                            <label for="vendor_document_type" class="block text-sm font-medium text-gray-700 mb-2">Document Type</label>
                                            <select name="document_type" id="vendor_document_type" required 
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                <option value="">Select Document Type</option>
                                                <option value="PAN Copy">PAN Copy</option>
                                                <option value="GST Certificate">GST Certificate</option>
                                                <option value="Constitution Proof">Constitution Proof</option>
                                                <option value="Cancelled Cheque">Cancelled Cheque</option>
                                                <option value="Bank Statement">Bank Statement</option>
                                                <option value="Address Proof">Address Proof</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        
                                        <div>
                                            <label for="vendor_expiry_date" class="block text-sm font-medium text-gray-700 mb-2">Expiry Date (Optional)</label>
                                            <input type="date" name="expiry_date" id="vendor_expiry_date"
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        </div>
                                    </div>
                                    
                                    <!-- Right Column - File Upload -->
                                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 bg-white hover:border-blue-400 transition-colors">
                                        <div class="text-center">
                                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                                            <div class="space-y-2">
                                                <label for="vendorDocumentFileInput" class="cursor-pointer">
                                                    <span class="text-sm font-medium text-blue-600 hover:text-blue-500">Click to upload</span>
                                                    <input type="file" name="document_file" id="vendorDocumentFileInput" 
                                                           accept=".jpg,.jpeg,.png,.pdf" required class="hidden">
                                                </label>
                                                <p class="text-xs text-gray-500">JPG, PNG, PDF up to 10MB</p>
                                            </div>
                                            <p id="vendorFileNameDisplay" class="text-sm text-gray-600 mt-3 font-medium">No file chosen</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="flex justify-end gap-3 pt-6">
                                    <button type="button" onclick="hideVendorDocumentModal()" 
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

            <!-- Vendor Vehicles Modal -->
            <div class="modal-overlay" id="vendorVehiclesModal" style="display: none !important;">
                <div class="modal-container" onclick="event.stopPropagation()">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6 border-b border-gray-200 pb-4">
                            <h3 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-truck text-blue-600"></i>
                                Vendor Vehicles
                            </h3>
                            <button type="button" class="text-gray-400 hover:text-gray-600 p-2 rounded-full hover:bg-gray-100 transition-colors" onclick="hideVendorVehiclesModal()">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                        
                        <!-- Vehicles List -->
                        <div id="vendorVehiclesList">
                            <!-- Content loaded dynamically -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Vendor document modal functions
            function showVendorDocumentModal(vendorId) {
                const modal = document.getElementById('vendorDocsModal');
                document.getElementById('modalVendorId').value = vendorId;
                
                // Show modal with animation
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.classList.add('active');
                    modal.style.opacity = '1';
                }, 10);
                
                document.body.classList.add('modal-open');
                loadVendorDocuments(vendorId);
            }

            function hideVendorDocumentModal() {
                const modal = document.getElementById('vendorDocsModal');
                
                // Hide modal with animation
                modal.classList.remove('active');
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.style.display = 'none';
                    modal.style.visibility = 'hidden';
                    window.location.reload();
                }, 300);
                
                document.body.classList.remove('modal-open');
                
                // Reset form
                const form = document.getElementById('uploadVendorDocumentForm');
                if (form) {
                    form.reset();
                }
                
                const fileDisplay = document.getElementById('vendorFileNameDisplay');
                if (fileDisplay) {
                    fileDisplay.textContent = 'No file chosen';
                }
            }

            function loadVendorDocuments(vendorId) {
                fetch(`manage_vendors.php?view_vendor_docs=${vendorId}`)
                    .then(response => response.json())
                    .then(documents => {
                        displayVendorDocuments(documents);
                        updateVendorDocumentStatus(documents);
                    })
                    .catch(error => {
                        console.error('Error loading vendor documents:', error);
                        document.getElementById('vendorDocumentsList').innerHTML = '<p class="text-red-600">Error loading documents</p>';
                    });
            }

            function displayVendorDocuments(documents) {
                const container = document.getElementById('vendorDocumentsList');
                
                if (documents.length === 0) {
                    container.innerHTML = '<p class="text-gray-500 text-center py-4">No documents uploaded yet</p>';
                    return;
                }

                const html = documents.map(doc => {
                    const expiryBadge = getVendorDocExpiryBadge(doc.expiry_date);
                    const verifiedBadge = doc.is_verified ? '<span class="badge badge-active">Verified</span>' : '<span class="badge badge-pending">Pending</span>';
                    const fileIcon = doc.file_path.toLowerCase().endsWith('.pdf') ? 'fas fa-file-pdf text-red-600' : 'fas fa-image text-blue-600';
                    
                    return `
                        <div class="border border-gray-200 rounded-lg p-4 bg-white">
                            <div class="flex items-center justify-between mb-2">
                                <h5 class="font-medium text-gray-900">${doc.document_type}</h5>
                                <div class="flex gap-2">
                                    ${verifiedBadge}
                                    ${expiryBadge}
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <i class="${fileIcon} text-2xl"></i>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-700">${doc.document_name}</p>
                                    <p class="text-sm text-gray-600">Size: ${formatFileSize(doc.file_size)}</p>
                                    <p class="text-sm text-gray-600">Uploaded: ${new Date(doc.uploaded_at).toLocaleDateString()}</p>
                                    ${doc.expiry_date ? `<p class="text-sm text-gray-600">Expires: ${new Date(doc.expiry_date).toLocaleDateString()}</p>` : ''}
                                </div>
                                <div class="flex flex-col gap-2">
                                    <a href="Uploads/vendor_docs/${doc.file_path}" target="_blank" 
                                       class="text-blue-600 hover:text-blue-800 text-sm font-medium px-3 py-1 border border-blue-300 rounded">
                                        <i class="fas fa-external-link-alt mr-1"></i>View
                                    </a>
                                    <button onclick="deleteVendorDocument(${doc.id})" 
                                            class="text-red-600 hover:text-red-800 text-sm font-medium px-3 py-1 border border-red-300 rounded">
                                        <i class="fas fa-trash mr-1"></i>Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                container.innerHTML = `<div class="space-y-3">${html}</div>`;
            }

            function getVendorDocExpiryBadge(expiryDate) {
                if (!expiryDate) return '';
                
                const today = new Date();
                const expiry = new Date(expiryDate);
                const diffTime = expiry - today;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                if (diffDays < 0) {
                    return '<span class="badge badge-rejected">Expired</span>';
                } else if (diffDays <= 30) {
                    return '<span class="badge badge-pending">Expiring Soon</span>';
                } else {
                    return '<span class="badge badge-active">Valid</span>';
                }
            }

            function updateVendorDocumentStatus(documents) {
                const summary = document.getElementById('vendorDocStatusSummary');
                const verified = documents.filter(doc => doc.is_verified).length;
                const expired = documents.filter(doc => doc.expiry_date && new Date(doc.expiry_date) < new Date()).length;
                const expiring = documents.filter(doc => {
                    if (!doc.expiry_date) return false;
                    const diffDays = Math.ceil((new Date(doc.expiry_date) - new Date()) / (1000 * 60 * 60 * 24));
                    return diffDays > 0 && diffDays <= 30;
                }).length;

                summary.innerHTML = `
                    <span class="badge badge-active">${documents.length} Total</span>
                    ${verified > 0 ? `<span class="badge badge-active">${verified} Verified</span>` : ''}
                    ${expiring > 0 ? `<span class="badge badge-pending">${expiring} Expiring</span>` : ''}
                    ${expired > 0 ? `<span class="badge badge-rejected">${expired} Expired</span>` : ''}
                `;
            }

            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            // Vendor vehicles modal functions
            function viewVendorVehicles(vendorId) {
                const modal = document.getElementById('vendorVehiclesModal');
                
                // Show modal with animation
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.classList.add('active');
                    modal.style.opacity = '1';
                }, 10);
                
                document.body.classList.add('modal-open');
                loadVendorVehicles(vendorId);
            }

            function hideVendorVehiclesModal() {
                const modal = document.getElementById('vendorVehiclesModal');
                
                // Hide modal with animation
                modal.classList.remove('active');
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.style.display = 'none';
                    modal.style.visibility = 'hidden';
                }, 300);
                
                document.body.classList.remove('modal-open');
            }

            function loadVendorVehicles(vendorId) {
                fetch(`get_vendor_vehicles.php?vendor_id=${vendorId}`)
                    .then(response => response.json())
                    .then(vehicles => {
                        displayVendorVehicles(vehicles);
                    })
                    .catch(error => {
                        console.error('Error loading vendor vehicles:', error);
                        document.getElementById('vendorVehiclesList').innerHTML = '<p class="text-red-600">Error loading vehicles</p>';
                    });
            }

            function displayVendorVehicles(vehicles) {
                const container = document.getElementById('vendorVehiclesList');
                
                if (vehicles.length === 0) {
                    container.innerHTML = '<p class="text-gray-500 text-center py-4">No vehicles registered yet</p>';
                    return;
                }

                const html = vehicles.map(vehicle => {
                    const statusBadge = `<span class="badge badge-${vehicle.status}">${vehicle.status.charAt(0).toUpperCase() + vehicle.status.slice(1)}</span>`;
                    
                    return `
                        <div class="border border-gray-200 rounded-lg p-4 bg-white mb-3">
                            <div class="flex items-center justify-between mb-2">
                                <h5 class="font-medium text-gray-900 flex items-center gap-2">
                                    <i class="fas fa-truck text-blue-600"></i>
                                    ${vehicle.vehicle_number}
                                </h5>
                                ${statusBadge}
                            </div>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="text-gray-600"><strong>Type:</strong> ${vehicle.vehicle_type || 'N/A'}</p>
                                    <p class="text-gray-600"><strong>Make/Model:</strong> ${vehicle.make_model || 'N/A'}</p>
                                    <p class="text-gray-600"><strong>Year:</strong> ${vehicle.manufacturing_year || 'N/A'}</p>
                                </div>
                                <div>
                                    <p class="text-gray-600"><strong>Driver:</strong> ${vehicle.driver_name || 'N/A'}</p>
                                    <p class="text-gray-600"><strong>GVW:</strong> ${vehicle.gvw ? vehicle.gvw + ' kg' : 'N/A'}</p>
                                    <p class="text-gray-600"><strong>Added:</strong> ${new Date(vehicle.created_at).toLocaleDateString()}</p>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                container.innerHTML = html;
            }

            // Vendor approval functions
            function approveVendor(vendorId) {
    // Show confirmation dialog
    if (!confirm('Are you sure you want to approve this vendor?')) {
        return;
    }
    
    // Show loading state
    const approveBtn = document.querySelector(`button[onclick="approveVendor(${vendorId})"]`);
    const rejectBtn = document.querySelector(`button[onclick="rejectVendor(${vendorId})"]`);
    
    if (approveBtn) {
        approveBtn.disabled = true;
        approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }
    if (rejectBtn) {
        rejectBtn.disabled = true;
    }
    
    // Make AJAX request
    fetch('vendor_approval_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            vendor_id: vendorId,
            action: 'approve'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showNotification('success', `Vendor "${data.vendor_name}" approved successfully!`);
            
            // Update the row status immediately
            updateVendorRowStatus(vendorId, 'active');
            
            // Optionally reload the page stats
            setTimeout(() => {
                location.reload();
            }, 1500);
            
        } else {
            // Show error message
            showNotification('error', data.message);
            
            // Re-enable buttons
            if (approveBtn) {
                approveBtn.disabled = false;
                approveBtn.innerHTML = '<i class="fas fa-check"></i>';
            }
            if (rejectBtn) {
                rejectBtn.disabled = false;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'An error occurred while approving the vendor.');
        
        // Re-enable buttons
        if (approveBtn) {
            approveBtn.disabled = false;
            approveBtn.innerHTML = '<i class="fas fa-check"></i>';
        }
        if (rejectBtn) {
            rejectBtn.disabled = false;
        }
    });
}

function rejectVendor(vendorId) {
    // Get rejection reason
    const reason = prompt('Please provide a reason for rejection:');
    if (!reason || reason.trim() === '') {
        return;
    }
    
    // Show confirmation
    if (!confirm(`Are you sure you want to reject this vendor?\n\nReason: ${reason}`)) {
        return;
    }
    
    // Show loading state
    const approveBtn = document.querySelector(`button[onclick="approveVendor(${vendorId})"]`);
    const rejectBtn = document.querySelector(`button[onclick="rejectVendor(${vendorId})"]`);
    
    if (rejectBtn) {
        rejectBtn.disabled = true;
        rejectBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }
    if (approveBtn) {
        approveBtn.disabled = true;
    }
    
    // Make AJAX request
    fetch('vendor_approval_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            vendor_id: vendorId,
            action: 'reject',
            reason: reason.trim()
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showNotification('success', `Vendor "${data.vendor_name}" rejected successfully!`);
            
            // Update the row status immediately
            updateVendorRowStatus(vendorId, 'rejected');
            
            // Optionally reload the page stats
            setTimeout(() => {
                location.reload();
            }, 1500);
            
        } else {
            // Show error message
            showNotification('error', data.message);
            
            // Re-enable buttons
            if (rejectBtn) {
                rejectBtn.disabled = false;
                rejectBtn.innerHTML = '<i class="fas fa-times"></i>';
            }
            if (approveBtn) {
                approveBtn.disabled = false;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'An error occurred while rejecting the vendor.');
        
        // Re-enable buttons
        if (rejectBtn) {
            rejectBtn.disabled = false;
            rejectBtn.innerHTML = '<i class="fas fa-times"></i>';
        }
        if (approveBtn) {
            approveBtn.disabled = false;
        }
    });
}

// Helper function to update vendor row status without page reload
function updateVendorRowStatus(vendorId, newStatus) {
    const row = document.querySelector(`tr[data-vendor-id="${vendorId}"]`);
    if (!row) return;
    
    // Update status badge
    const statusCell = row.querySelector('.badge');
    if (statusCell) {
        statusCell.className = `badge badge-${newStatus}`;
        statusCell.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
    }
    
    // Remove approval/rejection buttons
    const actionsCell = row.querySelector('td:last-child div');
    if (actionsCell) {
        const approveBtn = actionsCell.querySelector(`button[onclick="approveVendor(${vendorId})"]`);
        const rejectBtn = actionsCell.querySelector(`button[onclick="rejectVendor(${vendorId})"]`);
        
        if (approveBtn) approveBtn.remove();
        if (rejectBtn) rejectBtn.remove();
    }
}

// Notification system
function showNotification(type, message) {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.notification-toast');
    existingNotifications.forEach(notification => notification.remove());
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification-toast fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-md ${
        type === 'success' ? 'bg-green-100 border-l-4 border-green-500 text-green-700' : 
        type === 'error' ? 'bg-red-100 border-l-4 border-red-500 text-red-700' : 
        'bg-blue-100 border-l-4 border-blue-500 text-blue-700'
    } animate-fade-in`;
    
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-3"></i>
            <div>
                <p class="font-medium">${message}</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

// Add CSS for animations and notifications
const notificationStyles = `
<style>
.animate-fade-in {
    animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.notification-toast {
    transition: all 0.3s ease;
}

.notification-toast:hover {
    transform: translateX(-4px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}
</style>
`;

// Insert styles into head
document.head.insertAdjacentHTML('beforeend', notificationStyles);

            function deleteVendorDocument(docId) {
                if (confirm('Are you sure you want to delete this document?')) {
                    fetch(`delete_vendor_document.php?id=${docId}`, {
                        method: 'POST'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Document deleted successfully');
                            const vendorId = document.getElementById('modalVendorId').value;
                            loadVendorDocuments(vendorId);
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while deleting the document.');
                    });
                }
            }

            // Initialize document upload functionality
            document.addEventListener('DOMContentLoaded', function() {
                // File input change handler for vendor documents
                const vendorFileInput = document.getElementById('vendorDocumentFileInput');
                if (vendorFileInput) {
                    vendorFileInput.addEventListener('change', function() {
                        const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
                        const displayElement = document.getElementById('vendorFileNameDisplay');
                        if (displayElement) {
                            displayElement.textContent = fileName;
                        }
                    });
                }

                // Upload form handler for vendor documents
                const vendorUploadForm = document.getElementById('uploadVendorDocumentForm');
                if (vendorUploadForm) {
                    vendorUploadForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        
                        const formData = new FormData(this);
                        formData.append('upload_vendor_document', '1');
                        
                        fetch('manage_vendors.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Document uploaded successfully!');
                                const vendorId = document.getElementById('modalVendorId').value;
                                loadVendorDocuments(vendorId);
                                this.reset();
                                document.getElementById('vendorFileNameDisplay').textContent = 'No file chosen';
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
                const modals = document.querySelectorAll('.modal-overlay');
                modals.forEach(modal => {
                    modal.addEventListener('click', function(e) {
                        if (e.target === this) {
                            if (this.id === 'vendorDocsModal') {
                                hideVendorDocumentModal();
                            } else if (this.id === 'vendorVehiclesModal') {
                                hideVendorVehiclesModal();
                            }
                        }
                    });
                });

                // Close modal with escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        const activeModal = document.querySelector('.modal-overlay.active');
                        if (activeModal) {
                            if (activeModal.id === 'vendorDocsModal') {
                                hideVendorDocumentModal();
                            } else if (activeModal.id === 'vendorVehiclesModal') {
                                hideVendorVehiclesModal();
                            }
                        }
                    }
                });
            });
        </script>
    </body>
    </html>