<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

$user_role = $_SESSION['role'];

// Check if user has access to manage vendors (L2 Supervisor and above)
if (!in_array($user_role, ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'])) {
    $_SESSION['error'] = "You don't have permission to manage vendors.";
    header("Location: dashboard.php");
    exit();
}

// Check user permissions
$can_edit = in_array($user_role, ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin']);
$can_approve = in_array($user_role, ['manager1', 'manager2', 'admin', 'superadmin']);
$can_delete_directly = in_array($user_role, ['admin', 'superadmin']);

// Handle vendor edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_vendor'])) {
    $vendor_id = $_POST['vendor_id'];
    $contact_person = trim($_POST['contact_person']);
    $mobile = trim($_POST['mobile']);
    $email = trim($_POST['email']);
    $total_vehicles = !empty($_POST['total_vehicles']) ? (int)$_POST['total_vehicles'] : 0;
    $vehicle_numbers = isset($_POST['vehicle_numbers']) ? array_filter(array_map('trim', $_POST['vehicle_numbers'])) : [];
    
    // Bank details
    $bank_name = trim($_POST['bank_name']);
    $account_number = trim($_POST['account_number']);
    $branch_name = trim($_POST['branch_name']);
    $ifsc_code = strtoupper(trim($_POST['ifsc_code']));
    
    try {
        $pdo->beginTransaction();
        
        // Get current vendor details
        $currentVendor = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
        $currentVendor->execute([$vendor_id]);
        $vendor = $currentVendor->fetch(PDO::FETCH_ASSOC);
        
        if (!$vendor) {
            throw new Exception("Vendor not found");
        }
        
        // Check if bank details have changed
        $bank_details_changed = (
            $vendor['bank_name'] !== $bank_name ||
            $vendor['account_number'] !== $account_number ||
            $vendor['branch_name'] !== $branch_name ||
            $vendor['ifsc_code'] !== $ifsc_code
        );
        
        // Validation
        $errors = [];
        
        if (empty($contact_person)) {
            $errors[] = "Contact person name is required";
        }
        
        if (empty($mobile) || !preg_match('/^[0-9]{10}$/', $mobile)) {
            $errors[] = "Valid 10-digit mobile number is required";
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email address is required";
        }
        
        if (empty($bank_name)) {
            $errors[] = "Bank name is required";
        }
        
        if (empty($account_number)) {
            $errors[] = "Account number is required";
        }
        
        if (empty($branch_name)) {
            $errors[] = "Branch name is required";
        }
        
        if (empty($ifsc_code) || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc_code)) {
            $errors[] = "Valid IFSC code is required";
        }
        
        // Check for duplicate email (excluding current vendor)
        $emailCheck = $pdo->prepare("SELECT id FROM vendors WHERE email = ? AND id != ? AND deleted_at IS NULL");
        $emailCheck->execute([$email, $vendor_id]);
        if ($emailCheck->rowCount() > 0) {
            $errors[] = "Email address already exists";
        }
        
        if (empty($errors)) {
            if ($bank_details_changed && $user_role === 'l2_supervisor') {
                // L2 Supervisor bank changes need approval
                // Store bank change request
                $changeRequest = $pdo->prepare("
                    INSERT INTO vendor_bank_change_requests 
                    (vendor_id, requested_by, bank_name, account_number, branch_name, ifsc_code, requested_at, status) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending')
                ");
                $changeRequest->execute([
                    $vendor_id,
                    $_SESSION['user_id'],
                    $bank_name,
                    $account_number,
                    $branch_name,
                    $ifsc_code
                ]);
                
                // Send notification to managers
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
                        'Vendor Bank Details Change Approval Required',
                        "Bank details change for vendor '{$vendor['company_name']}' has been requested by " . $_SESSION['full_name'],
                        'info',
                                                
                    ]);
                }
                
                // Update only contact and vehicle info
                $updateVendor = $pdo->prepare("
                    UPDATE vendors 
                    SET contact_person = ?, mobile = ?, email = ?, total_vehicles = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateVendor->execute([$contact_person, $mobile, $email, $total_vehicles, $vendor_id]);
                
                $_SESSION['success'] = "Contact and vehicle information updated successfully. Bank details change request submitted for approval.";
            } else {
                // Update all fields (for managers and above, or no bank changes)
                $updateVendor = $pdo->prepare("
                    UPDATE vendors 
                    SET contact_person = ?, mobile = ?, email = ?, total_vehicles = ?, 
                        bank_name = ?, account_number = ?, branch_name = ?, ifsc_code = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateVendor->execute([
                    $contact_person, $mobile, $email, $total_vehicles,
                    $bank_name, $account_number, $branch_name, $ifsc_code,
                    $vendor_id
                ]);
                
                $_SESSION['success'] = "Vendor information updated successfully.";
            }
            
            // Update vehicle numbers
            // First, delete existing vehicle numbers
            $pdo->prepare("DELETE FROM vendor_vehicles WHERE vendor_id = ?")->execute([$vendor_id]);
            
            // Insert new vehicle numbers
            if (!empty($vehicle_numbers)) {
                $vehicleStmt = $pdo->prepare("
                    INSERT INTO vendor_vehicles (vendor_id, vehicle_number, created_at) 
                    VALUES (?, ?, NOW())
                ");
                
                foreach ($vehicle_numbers as $vehicle_number) {
                    if (!empty($vehicle_number)) {
                        $vehicleStmt->execute([$vendor_id, $vehicle_number]);
                    }
                }
            }
            
            $pdo->commit();
        } else {
            $_SESSION['error'] = implode("<br>", $errors);
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Update failed: " . $e->getMessage();
    }
    
    header("Location: manage_vendors.php");
    exit;
}

// Handle vendor document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_vendor_document'])) {
    $vendor_id = $_POST['vendor_id'];
    $document_type = $_POST['document_type'];
    $document_name = $_POST['document_name'] ?? '';
    $expiry_date = $_POST['expiry_date'] ?? null;
    
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        try {
            $upload_dir = 'uploads/vendor_documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_info = pathinfo($_FILES['document_file']['name']);
            $file_extension = strtolower($file_info['extension']);
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowed_extensions));
            }
            
            $file_name = 'vendor_' . $vendor_id . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $file_path)) {
                $stmt = $pdo->prepare("
                    INSERT INTO vendor_documents 
                    (vendor_id, document_type, document_name, file_path, file_size, file_type, expiry_date, uploaded_by, uploaded_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $vendor_id,
                    $document_type,
                    $document_name ?: $document_type,
                    $file_path,
                    $_FILES['document_file']['size'],
                    $_FILES['document_file']['type'],
                    $expiry_date ?: null,
                    $_SESSION['user_id']
                ]);
                
                $_SESSION['success'] = "Document uploaded successfully!";
            } else {
                throw new Exception("Failed to upload file");
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Upload failed: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "No file uploaded or upload error occurred";
    }
    
    header("Location: manage_vendors.php");
    exit;
}

// Handle AJAX request for vendor details
if (isset($_GET['get_vendor_details'])) {
    try {
        $vendorId = $_GET['get_vendor_details'];
        $stmt = $pdo->prepare("
            SELECT v.*, 
                   GROUP_CONCAT(vv.vehicle_number) as vehicle_numbers_list
            FROM vendors v
            LEFT JOIN vendor_vehicles vv ON v.id = vv.vendor_id AND vv.deleted_at IS NULL
            WHERE v.id = ? AND v.deleted_at IS NULL
            GROUP BY v.id
        ");
        $stmt->execute([$vendorId]);
        $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($vendor) {
            $vendor['vehicle_numbers'] = $vendor['vehicle_numbers_list'] ? explode(',', $vendor['vehicle_numbers_list']) : [];
        }

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($vendor);
        exit;
    } catch (Exception $e) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
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

// Handle vendor deletion requests
if (isset($_GET['delete_vendor'])) {
    $vendorId = $_GET['delete_vendor'];

    try {
        $pdo->beginTransaction();

        // Get vendor details before deletion
        $stmt = $pdo->prepare("SELECT vendor_code, company_name, created_by FROM vendors WHERE id = ?");
        $stmt->execute([$vendorId]);
        $vendor = $stmt->fetch();

        if ($vendor) {
            if ($can_delete_directly) {
                // Admin/Superadmin can delete directly
                $pdo->prepare("UPDATE vendor_vehicles SET deleted_at = NOW() WHERE vendor_id = ?")->execute([$vendorId]);
                $pdo->prepare("UPDATE vendor_documents SET deleted_at = NOW() WHERE vendor_id = ?")->execute([$vendorId]);
                $pdo->prepare("DELETE FROM vendor_contacts WHERE vendor_id = ?")->execute([$vendorId]);
                $pdo->prepare("DELETE FROM vendor_services WHERE vendor_id = ?")->execute([$vendorId]);
                $pdo->prepare("UPDATE vendors SET deleted_at = NOW() WHERE id = ?")->execute([$vendorId]);

                $pdo->commit();
                $_SESSION['success'] = "Vendor '{$vendor['company_name']}' deleted successfully";
            } else {
                // L2 Supervisor/Manager need approval for deletion
                $deleteRequest = $pdo->prepare("
                    INSERT INTO vendor_deletion_requests 
                    (vendor_id, requested_by, requested_at, reason, status) 
                    VALUES (?, ?, NOW(), ?, 'pending')
                ");
                $deleteRequest->execute([
                    $vendorId,
                    $_SESSION['user_id'],
                    "Deletion requested by " . $_SESSION['full_name']
                ]);

                // Send notification to managers/admins
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
                        'Vendor Deletion Approval Required',
                        "Deletion of vendor '{$vendor['company_name']}' has been requested by " . $_SESSION['full_name'],
                        'warning'
                    ]);
                }

                $pdo->commit();
                $_SESSION['success'] = "Vendor deletion request submitted for approval";
            }
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Vendor not found";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Delete operation failed: " . $e->getMessage();
    }
    header("Location: manage_vendors.php");
    exit();
}

// Get filter parameters
$filter_approval = $_GET['approval'] ?? '';
$filter_type = $_GET['type'] ?? '';
$search_term = $_GET['search'] ?? '';

// Build query with filters (using approval_status instead of status)
$query = "
    SELECT v.*, u.full_name as created_by_name, au.full_name as approved_by_name,
           COUNT(vd.id) as document_count,
           COUNT(vv.id) as vehicle_count,
           CASE WHEN vdr.id IS NOT NULL THEN 'deletion_pending' ELSE v.approval_status END as display_status
    FROM vendors v
    LEFT JOIN users u ON v.created_by = u.id
    LEFT JOIN users au ON v.approved_by = au.id
    LEFT JOIN vendor_documents vd ON v.id = vd.vendor_id AND vd.deleted_at IS NULL
    LEFT JOIN vendor_vehicles vv ON v.id = vv.vendor_id AND vv.deleted_at IS NULL
    LEFT JOIN vendor_deletion_requests vdr ON v.id = vdr.vendor_id AND vdr.status = 'pending'
    WHERE v.deleted_at IS NULL
";

$params = [];

if ($filter_approval) {
    $query .= " AND v.approval_status = ?";
    $params[] = $filter_approval;
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

// Get vendor statistics (using approval_status)
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_vendors,
        COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) as pending_vendors,
        COUNT(CASE WHEN approval_status = 'approved' THEN 1 END) as approved_vendors,
        COUNT(CASE WHEN approval_status = 'rejected' THEN 1 END) as rejected_vendors,
        COUNT(CASE WHEN approval_status = 'active' THEN 1 END) as active_vendors,
        COUNT(CASE WHEN approval_status = 'suspended' THEN 1 END) as suspended_vendors
    FROM vendors 
    WHERE deleted_at IS NULL
")->fetch(PDO::FETCH_ASSOC);

// Get pending deletion requests count for display
$pendingDeletions = $pdo->query("
    SELECT COUNT(*) as count FROM vendor_deletion_requests WHERE status = 'pending'
")->fetch(PDO::FETCH_ASSOC)['count'];

// Get pending bank change requests count
$pendingBankChanges = $pdo->query("
    SELECT COUNT(*) as count FROM vendor_bank_change_requests WHERE status = 'pending'
")->fetch(PDO::FETCH_ASSOC)['count'];
?>
								  
								 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Vendors - Absuma Logistics</title>
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

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-active { background-color: #dcfce7; color: #166534; }
        .badge-pending { background-color: #fef3c7; color: #92400e; }
        .badge-approved { background-color: #dbeafe; color: #1e40af; }
        .badge-rejected { background-color: #fecaca; color: #991b1b; }
        .badge-suspended { background-color: #f3f4f6; color: #374151; }
        .badge-deletion-pending { background-color: #fed7d7; color: #c53030; }
        
        .badge-transport { background-color: #f3e8ff; color: #7c3aed; }
        .badge-maintenance { background-color: #fed7aa; color: #ea580c; }
        .badge-fuel { background-color: #fecaca; color: #dc2626; }
        .badge-parts { background-color: #e0e7ff; color: #4338ca; }
        .badge-insurance { background-color: #ccfbf1; color: #0f766e; }
        .badge-logistics { background-color: #fde68a; color: #d97706; }
        .badge-other { background-color: #f3f4f6; color: #6b7280; }

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
                    <div class="flex items-center space-x-4">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">Manage Vendors</h1>
                            <p class="text-sm text-gray-600 mt-1">
                                <?php if ($user_role === 'l2_supervisor'): ?>
                                    Vendor changes require manager approval • Total: <?= $stats['total_vendors'] ?> vendors
                                <?php else: ?>
                                    Complete vendor management system • Total: <?= $stats['total_vendors'] ?> vendors
                                <?php endif; ?>
                            </p>
                        </div>
                        
                       
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <!-- Quick Stats -->
                        <div class="hidden md:flex items-center space-x-4 text-sm">
                            <div class="flex items-center text-green-600">
                                <i class="fas fa-check-circle mr-1"></i>
                                <?= $stats['approved_vendors'] ?> Approved
                            </div>
                            <div class="flex items-center text-yellow-600">
                                <i class="fas fa-clock mr-1"></i>
                                <?= $stats['pending_vendors'] ?> Pending
                            </div>
                            <?php if ($pendingDeletions > 0): ?>
                                <div class="flex items-center text-red-600">
                                    <i class="fas fa-trash mr-1"></i>
                                    <?= $pendingDeletions ?> Deletion Requests
                                </div>
                            <?php endif; ?>
                            <?php if ($pendingBankChanges > 0): ?>
                                <div class="flex items-center text-orange-600">
                                    <i class="fas fa-university mr-1"></i>
                                    <?= $pendingBankChanges ?> Bank Changes
                                </div>
                            <?php endif; ?>
                        </div>
                      <!-- Search Bar -->
                        <div class="relative ml-8">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                            <form method="GET" class="inline">
                                <input
                                    type="text"
                                    name="search"
                                    value="<?= htmlspecialchars($search_term) ?>"
                                    placeholder="Search vendors..."
                                    class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm w-64"
                                />
                                <?php if ($filter_approval): ?>
                                    <input type="hidden" name="approval" value="<?= htmlspecialchars($filter_approval) ?>">
                                <?php endif; ?>
                                <?php if ($filter_type): ?>
                                    <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type) ?>">
                                <?php endif; ?>
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
                            <i class="fas fa-chart-bar text-teal-600"></i> Vendor Statistics
                        </h2>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg text-center border border-blue-100">
                                <div class="text-2xl font-bold text-blue-600"><?= $stats['total_vendors'] ?></div>
                                <div class="text-sm text-blue-800">Total Vendors</div>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg text-center border border-yellow-100">
                                <div class="text-2xl font-bold text-yellow-600"><?= $stats['pending_vendors'] ?></div>
                                <div class="text-sm text-yellow-800">Pending Approval</div>
                            </div>
							 <div class="bg-green-50 p-4 rounded-lg text-center border border-green-100">
                                <div class="text-2xl font-bold text-green-600"><?= $stats['approved_vendors'] ?></div>
                                <div class="text-sm text-green-800">Approved</div>
                            </div>
                            <div class="bg-blue-50 p-4 rounded-lg text-center border border-blue-100">
                                <div class="text-2xl font-bold text-blue-600"><?= $stats['active_vendors'] ?></div>
                                <div class="text-sm text-blue-800">Active</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg text-center border border-red-100">
                                <div class="text-2xl font-bold text-red-600"><?= $stats['rejected_vendors'] ?></div>
                                <div class="text-sm text-red-800">Rejected</div>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg text-center border border-gray-100">
                                <div class="text-2xl font-bold text-gray-600"><?= $stats['suspended_vendors'] ?></div>
                                <div class="text-sm text-gray-800">Suspended</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters -->
                    <div class="bg-white rounded-xl shadow-soft p-6 mb-6 card-hover-effect">
                        <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                            <i class="fas fa-filter text-teal-600"></i> Filter Vendors
                        </h2>

                        <form method="GET" class="flex flex-wrap gap-6 items-end">
                            <div class="flex flex-col w-48">
                                <label for="approval" class="block text-sm font-medium text-gray-700 mb-1">Approval Status</label>
                                <select id="approval" name="approval" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">All Approval Status</option>
                                    <option value="pending" <?= $filter_approval === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
                                    <option value="approved" <?= $filter_approval === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="active" <?= $filter_approval === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="rejected" <?= $filter_approval === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="suspended" <?= $filter_approval === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                </select>
                            </div>
                            
                            <div class="flex flex-col w-48">
                                <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Vendor Type</label>
                                <select id="type" name="type" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">All Types</option>
                                    <option value="Transport" <?= $filter_type === 'Transport' ? 'selected' : '' ?>>Transport</option>
                                    <option value="Maintenance" <?= $filter_type === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                    <option value="Fuel" <?= $filter_type === 'Fuel' ? 'selected' : '' ?>>Fuel</option>
                                    <option value="Parts Supplier" <?= $filter_type === 'Parts Supplier' ? 'selected' : '' ?>>Parts Supplier</option>
                                    <option value="Insurance" <?= $filter_type === 'Insurance' ? 'selected' : '' ?>>Insurance</option>
                                    <option value="Logistics" <?= $filter_type === 'Logistics' ? 'selected' : '' ?>>Logistics</option>
                                    <option value="Other" <?= $filter_type === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <div class="flex gap-2">
                                <button type="submit" class="px-6 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition-colors">
                                    <i class="fas fa-search mr-2"></i> Filter
                                </button>
                                <a href="manage_vendors.php" class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors">
                                    <i class="fas fa-times mr-2"></i> Clear
                                </a>
                            </div>
                            
                            <?php if ($search_term): ?>
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search_term) ?>">
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Vendors List -->
                    <?php if (empty($vendors)): ?>
                        <div class="bg-white rounded-xl shadow-soft p-12 text-center card-hover-effect">
                            <i class="fas fa-handshake text-6xl text-gray-400 mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-700 mb-2">No Vendors Found</h3>
                            <p class="text-gray-500 mb-6">Get started by registering your first vendor or adjust your filters</p>
                            <a href="vendor_registration.php" class="inline-flex items-center px-6 py-3 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors font-medium">
                                <i class="fas fa-plus mr-2"></i> Register First Vendor
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden card-hover-effect">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                        <tr>
                                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor Details</th>
                                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Info</th>
                                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type & Business</th>
                                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Documents</th>
                                            <th scope="col" class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($vendors as $vendor): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-12 w-12">
                                                            <div class="h-12 w-12 rounded-full bg-gradient-to-r from-teal-400 to-blue-500 flex items-center justify-center">
                                                                <i class="fas fa-handshake text-white text-lg"></i>
                                                            </div>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($vendor['company_name']) ?></div>
                                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($vendor['vendor_code']) ?></div>
                                                            <div class="text-xs text-gray-400">By: <?= htmlspecialchars($vendor['created_by_name']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <div><?= htmlspecialchars($vendor['contact_person']) ?></div>
                                                    <div class="text-gray-500"><?= htmlspecialchars($vendor['mobile']) ?></div>
                                                    <div class="text-gray-500"><?= htmlspecialchars($vendor['email']) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="badge badge-<?= strtolower(str_replace(' ', '', $vendor['vendor_type'])) ?>">
                                                        <?= htmlspecialchars($vendor['vendor_type']) ?>
                                                    </span>
                                                    <div class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($vendor['business_nature']) ?></div>
                                                    <div class="text-xs text-gray-400"><?= htmlspecialchars($vendor['state']) ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($vendor['display_status'] === 'deletion_pending'): ?>
                                                        <span class="badge badge-deletion-pending">
                                                            <i class="fas fa-trash mr-1"></i>Deletion Pending
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-<?= $vendor['approval_status'] ?>">
                                                            <?= ucfirst($vendor['approval_status']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <?= $vendor['vehicle_count'] ?> vehicles
                                                    </div>
                                                    <?php if ($vendor['approved_by_name']): ?>
                                                        <div class="text-xs text-gray-400 mt-1">
                                                            By: <?= htmlspecialchars($vendor['approved_by_name']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-sm"><?= $vendor['document_count'] ?> docs</span>
                                                        <button onclick="showVendorDocumentModal(<?= $vendor['id'] ?>)" class="text-blue-600 hover:text-blue-800 p-1 rounded transition-colors" title="View/Upload Documents">
                                                            <i class="fas fa-file-alt"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="flex items-center gap-2">
                                                        <?php if ($can_edit): ?>
                                                            <button onclick="showEditVendorModal(<?= $vendor['id'] ?>)" class="text-blue-600 hover:text-blue-800 p-1 rounded transition-colors" title="Edit Vendor">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <button onclick="viewVendorVehicles(<?= $vendor['id'] ?>)" class="text-purple-600 hover:text-purple-800 p-1 rounded transition-colors" title="View Vehicles">
                                                            <i class="fas fa-truck"></i>
                                                        </button>
                                                        
                                                        <?php if ($can_approve && $vendor['approval_status'] === 'pending'): ?>
                                                            <button onclick="approveVendor(<?= $vendor['id'] ?>)" class="text-green-600 hover:text-green-800 p-1 rounded transition-colors" title="Approve Vendor">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button onclick="rejectVendor(<?= $vendor['id'] ?>)" class="text-red-600 hover:text-red-800 p-1 rounded transition-colors" title="Reject Vendor">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($vendor['display_status'] !== 'deletion_pending'): ?>
                                                            <?php if ($can_delete_directly): ?>
                                                                <button onclick="deleteVendorDirect(<?= $vendor['id'] ?>, '<?= htmlspecialchars($vendor['company_name']) ?>')" class="text-red-600 hover:text-red-800 p-1 rounded transition-colors" title="Delete Vendor">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <button onclick="requestVendorDeletion(<?= $vendor['id'] ?>, '<?= htmlspecialchars($vendor['company_name']) ?>')" class="text-orange-600 hover:text-orange-800 p-1 rounded transition-colors" title="Request Deletion">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-gray-400 p-1" title="Deletion request pending">
                                                                <i class="fas fa-clock"></i>
                                                            </span>
                                                        <?php endif; ?>
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

    <!-- Edit Vendor Modal -->
    <div id="editVendorModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-4/5 lg:w-3/4 xl:w-2/3 shadow-lg rounded-xl bg-white max-h-[90vh] overflow-y-auto">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-edit text-teal-600"></i> Edit Vendor
                    </h3>
                    <button onclick="hideEditVendorModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Edit Vendor Form -->
                <form id="editVendorForm" method="POST" class="space-y-6">
                    <input type="hidden" id="editVendorId" name="vendor_id" value="">
                    <input type="hidden" name="edit_vendor" value="1">
                    
                    <!-- Contact Information -->
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-xl p-6">
                        <h4 class="text-lg font-semibold text-blue-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-user text-blue-600"></i> Contact Information
                        </h4>
                        <div class="form-grid">
                            <div>
                                <label for="edit_contact_person" class="block text-sm font-medium text-gray-700 mb-1">Contact Person <span class="text-red-500">*</span></label>
                                <input type="text" id="edit_contact_person" name="contact_person" required class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                            <div>
                                <label for="edit_mobile" class="block text-sm font-medium text-gray-700 mb-1">Mobile Number <span class="text-red-500">*</span></label>
                                <input type="tel" id="edit_mobile" name="mobile" required pattern="[0-9]{10}" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                            <div class="md:col-span-2">
                                <label for="edit_email" class="block text-sm font-medium text-gray-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                                <input type="email" id="edit_email" name="email" required class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bank Details -->
                    <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-xl p-6">
                        <h4 class="text-lg font-semibold text-orange-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-university text-orange-600"></i> Bank Details
                            <?php if ($user_role === 'l2_supervisor'): ?>
                                <span class="text-xs bg-amber-100 text-amber-800 px-2 py-1 rounded-full">Changes require approval</span>
                            <?php endif; ?>
                        </h4>
                        <div class="form-grid">
                            <div>
                                <label for="edit_bank_name" class="block text-sm font-medium text-gray-700 mb-1">Bank Name <span class="text-red-500">*</span></label>
                                <input type="text" id="edit_bank_name" name="bank_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                            <div>
                                <label for="edit_account_number" class="block text-sm font-medium text-gray-700 mb-1">Account Number <span class="text-red-500">*</span></label>
                                <input type="text" id="edit_account_number" name="account_number" required class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                            <div>
                                <label for="edit_branch_name" class="block text-sm font-medium text-gray-700 mb-1">Branch Name <span class="text-red-500">*</span></label>
                                <input type="text" id="edit_branch_name" name="branch_name" required class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                            <div>
                                <label for="edit_ifsc_code" class="block text-sm font-medium text-gray-700 mb-1">IFSC Code <span class="text-red-500">*</span></label>
                                <input type="text" id="edit_ifsc_code" name="ifsc_code" required pattern="[A-Z]{4}0[A-Z0-9]{6}" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Vehicle Information -->
                    <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-xl p-6">
                        <h4 class="text-lg font-semibold text-purple-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-truck text-purple-600"></i> Vehicle Information
                        </h4>
                        <div class="form-grid">
                            <div>
                                <label for="edit_total_vehicles" class="block text-sm font-medium text-gray-700 mb-1">Total Number of Vehicles</label>
                                <input type="number" id="edit_total_vehicles" name="total_vehicles" min="0" onchange="updateEditVehicleInputs()" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            </div>
                        </div>
                        
                        <div id="edit_vehicle_numbers_section" class="mt-4" style="display: none;">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Vehicle Numbers</label>
                            <div id="edit_vehicle_inputs">
                                <!-- Vehicle number inputs will be dynamically added here -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Buttons -->
                    <div class="flex justify-end space-x-4 pt-6 border-t border-gray-200">
                        <button type="button" onclick="hideEditVendorModal()" class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-3 bg-teal-600 text-white rounded-lg hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 transition-colors">
                            <i class="fas fa-save mr-2"></i>Update Vendor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Upload Modal -->
    <div id="vendorDocumentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-xl bg-white">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Vendor Documents</h3>
                    <button onclick="hideVendorDocumentModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Document Upload Form -->
                <form id="uploadVendorDocumentForm" method="POST" enctype="multipart/form-data" class="mb-6 p-4 bg-gray-50 rounded-lg">
                    <input type="hidden" id="modalVendorId" name="vendor_id" value="">
                    <input type="hidden" name="upload_vendor_document" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="document_type" class="block text-sm font-medium text-gray-700 mb-1">Document Type <span class="text-red-500">*</span></label>
                            <select id="document_type" name="document_type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                <option value="">Select Document Type</option>
                                <option value="Company Registration">Company Registration</option>
                                <option value="GST Certificate">GST Certificate</option>
                                <option value="PAN Card">PAN Card</option>
                                <option value="Bank Statement">Bank Statement</option>
                                <option value="Address Proof">Address Proof</option>
                                <option value="License">License</option>
                                <option value="Insurance Certificate">Insurance Certificate</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="document_name" class="block text-sm font-medium text-gray-700 mb-1">Document Name</label>
                            <input type="text" id="document_name" name="document_name" placeholder="Optional custom name" class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        </div>
                        
                        <div>
                            <label for="expiry_date" class="block text-sm font-medium text-gray-700 mb-1">Expiry Date</label>
                            <input type="date" id="expiry_date" name="expiry_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        </div>
                        
                        <div>
                            <label for="document_file" class="block text-sm font-medium text-gray-700 mb-1">Choose File <span class="text-red-500">*</span></label>
                            <input type="file" id="vendorDocumentFileInput" name="document_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                            <div id="vendorFileNameDisplay" class="text-sm text-gray-500 mt-1">No file chosen</div>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-full px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition-colors">
                        <i class="fas fa-upload mr-2"></i> Upload Document
                    </button>
                </form>
                
                <!-- Documents List -->
                <div id="vendorDocumentsList" class="space-y-3">
                    <!-- Documents will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Vendor Vehicles Modal -->
    <div id="vendorVehiclesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-xl bg-white">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Vendor Vehicles</h3>
                    <button onclick="hideVendorVehiclesModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Vehicles List -->
                <div id="vendorVehiclesList" class="space-y-3">
                    <!-- Vehicles will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Edit vendor modal functions
        function showEditVendorModal(vendorId) {
            const modal = document.getElementById('editVendorModal');
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            loadVendorDetails(vendorId);
        }

        function hideEditVendorModal() {
            const modal = document.getElementById('editVendorModal');
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            
            // Reset form
            const form = document.getElementById('editVendorForm');
            if (form) form.reset();
        }

        function loadVendorDetails(vendorId) {
            fetch(`manage_vendors.php?get_vendor_details=${vendorId}`)
                .then(response => response.json())
                .then(vendor => {
                    if (vendor.error) {
                        throw new Error(vendor.error);
                    }
                    
                    // Populate form fields
                    document.getElementById('editVendorId').value = vendor.id;
                    document.getElementById('edit_contact_person').value = vendor.contact_person || '';
                    document.getElementById('edit_mobile').value = vendor.mobile || '';
                    document.getElementById('edit_email').value = vendor.email || '';
                    document.getElementById('edit_bank_name').value = vendor.bank_name || '';
                    document.getElementById('edit_account_number').value = vendor.account_number || '';
                    document.getElementById('edit_branch_name').value = vendor.branch_name || '';
                    document.getElementById('edit_ifsc_code').value = vendor.ifsc_code || '';
                    document.getElementById('edit_total_vehicles').value = vendor.total_vehicles || 0;
                    
                    // Update vehicle inputs
                    updateEditVehicleInputs();
                    
                    // Populate existing vehicle numbers
                    if (vendor.vehicle_numbers && vendor.vehicle_numbers.length > 0) {
                        const vehicleInputs = document.querySelectorAll('#edit_vehicle_inputs input[name="vehicle_numbers[]"]');
                        vendor.vehicle_numbers.forEach((vehicleNumber, index) => {
                            if (vehicleInputs[index]) {
                                vehicleInputs[index].value = vehicleNumber;
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading vendor details:', error);
                    alert('Error loading vendor details. Please try again.');
                });
        }

        function updateEditVehicleInputs() {
            const totalVehicles = parseInt(document.getElementById('edit_total_vehicles').value) || 0;
            const vehicleSection = document.getElementById('edit_vehicle_numbers_section');
            const vehicleInputsContainer = document.getElementById('edit_vehicle_inputs');

            // Collect current vehicle numbers
            let currentVehicles = [];
            const existingInputs = vehicleInputsContainer.querySelectorAll('input[name="vehicle_numbers[]"]');
            existingInputs.forEach(input => {
                if (input.value.trim()) {
                    currentVehicles.push(input.value.trim());
                }
            });

            if (totalVehicles > 0) {
                vehicleSection.style.display = 'block';
                vehicleInputsContainer.innerHTML = '';

                for (let i = 0; i < totalVehicles; i++) {
                    const inputDiv = document.createElement('div');
                    inputDiv.className = 'vehicle-input';

                    const input = document.createElement('input');
                    input.type = 'text';
                    input.name = 'vehicle_numbers[]';
                    input.placeholder = `Vehicle Number ${i + 1} (e.g., UP25GT0880)`;
                    input.className = 'flex-1 px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500';

                    if (i < currentVehicles.length) {
                        input.value = currentVehicles[i];
                    }

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'remove-vehicle';
                    removeButton.title = 'Remove this vehicle';
                    removeButton.onclick = function() { removeEditVehicleInput(this); };
                    removeButton.innerHTML = '<i class="fas fa-times"></i>';

                    inputDiv.appendChild(input);
                    inputDiv.appendChild(removeButton);
                    vehicleInputsContainer.appendChild(inputDiv);
                }
            } else {
                vehicleSection.style.display = 'none';
                vehicleInputsContainer.innerHTML = '';
            }
        }

        function removeEditVehicleInput(button) {
            button.parentElement.remove();
            // Update total vehicles count
            const remainingInputs = document.querySelectorAll('#edit_vehicle_inputs input[name="vehicle_numbers[]"]').length;
            document.getElementById('edit_total_vehicles').value = remainingInputs;
        }

        // Document modal functions
        function showVendorDocumentModal(vendorId) {
            const modal = document.getElementById('vendorDocumentModal');
            document.getElementById('modalVendorId').value = vendorId;
            
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            loadVendorDocuments(vendorId);
        }

        function hideVendorDocumentModal() {
            const modal = document.getElementById('vendorDocumentModal');
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            
            // Reset form
            const form = document.getElementById('uploadVendorDocumentForm');
            if (form) form.reset();
            
            const fileDisplay = document.getElementById('vendorFileNameDisplay');
            if (fileDisplay) fileDisplay.textContent = 'No file chosen';
        }

        function loadVendorDocuments(vendorId) {
            fetch(`manage_vendors.php?view_vendor_docs=${vendorId}`)
                .then(response => response.json())
                .then(documents => {
                    displayVendorDocuments(documents);
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
                const verifiedBadge = doc.is_verified ? '<span class="badge badge-approved">Verified</span>' : '<span class="badge badge-pending">Pending</span>';
                const fileIcon = doc.file_path.toLowerCase().endsWith('.pdf') ? 'fa-file-pdf text-red-500' : 'fa-file-image text-blue-500';
                
                return `
                    <div class="flex items-center justify-between p-3 bg-white border border-gray-200 rounded-lg hover:shadow-md transition-shadow">
                        <div class="flex items-center space-x-3">
                            <i class="fas ${fileIcon} text-lg"></i>
                            <div>
                                <p class="text-sm font-medium text-gray-900">${doc.document_name}</p>
                                <p class="text-xs text-gray-500">${doc.document_type} • ${formatFileSize(doc.file_size)}</p>
                                <p class="text-xs text-gray-400">Uploaded: ${new Date(doc.uploaded_at).toLocaleDateString()}</p>
                                ${doc.expiry_date ? `<p class="text-xs text-gray-400">Expires: ${new Date(doc.expiry_date).toLocaleDateString()}</p>` : ''}
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            ${verifiedBadge}
                            ${expiryBadge}
                            <a href="${doc.file_path}" target="_blank" class="text-blue-600 hover:text-blue-800 p-1 rounded transition-colors" title="View Document">
                                <i class="fas fa-eye"></i>
                            </a>
                            <button onclick="deleteVendorDocument(${doc.id})" class="text-red-600 hover:text-red-800 p-1 rounded transition-colors" title="Delete Document">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');

            container.innerHTML = html;
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
                return '<span class="badge badge-approved">Valid</span>';
            }
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
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            loadVendorVehicles(vendorId);
        }

        function hideVendorVehiclesModal() {
            const modal = document.getElementById('vendorVehiclesModal');
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        function loadVendorVehicles(vendorId) {
            // Placeholder for vehicle loading functionality
            document.getElementById('vendorVehiclesList').innerHTML = '<p class="text-gray-500 text-center py-4">Vehicle information will be implemented here</p>';
        }

        // Vendor approval functions
        function approveVendor(vendorId) {
            if (confirm('Are you sure you want to approve this vendor?')) {
                window.location.href = `approve_vendor.php?id=${vendorId}&action=approve`;
            }
        }

        function rejectVendor(vendorId) {
            const reason = prompt('Please provide a reason for rejection:');
            if (reason && reason.trim()) {
                window.location.href = `approve_vendor.php?id=${vendorId}&action=reject&reason=${encodeURIComponent(reason)}`;
            }
        }

        // Vendor deletion functions
        function deleteVendorDirect(vendorId, vendorName) {
            if (confirm(`Are you sure you want to delete vendor "${vendorName}"? This action cannot be undone and will remove all associated data.`)) {
                window.location.href = `manage_vendors.php?delete_vendor=${vendorId}`;
            }
        }

        function requestVendorDeletion(vendorId, vendorName) {
            if (confirm(`Are you sure you want to request deletion of vendor "${vendorName}"? This will be sent to managers for approval.`)) {
                window.location.href = `manage_vendors.php?delete_vendor=${vendorId}`;
            }
        }

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

        // Initialize functionality
        document.addEventListener('DOMContentLoaded', function() {
            // File input change handler for vendor documents
            const vendorFileInput = document.getElementById('vendorDocumentFileInput');
            if (vendorFileInput) {
                vendorFileInput.addEventListener('change', function() {
                    const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
                    document.getElementById('vendorFileNameDisplay').textContent = fileName;
                });
            }

            // Add fade-in animation to elements
            const elements = document.querySelectorAll('.animate-fade-in');
            elements.forEach((el, index) => {
                el.style.animationDelay = `${index * 0.1}s`;
            });

            // Form validation for edit form
            const editForm = document.getElementById('editVendorForm');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const requiredFields = this.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.classList.add('border-red-500');
                        } else {
                            field.classList.remove('border-red-500');
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            }

            // Real-time validation for mobile and email in edit form
            const editMobile = document.getElementById('edit_mobile');
            if (editMobile) {
                editMobile.addEventListener('input', function() {
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
                    }
                });
            }

            const editEmail = document.getElementById('edit_email');
            if (editEmail) {
                editEmail.addEventListener('input', function() {
                    const value = this.value;
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (value.length > 0 && !emailRegex.test(value)) {
                        this.setCustomValidity('Please enter a valid email address');
                        this.classList.add('border-red-500');
                    } else {
                        this.setCustomValidity('');
                        this.classList.remove('border-red-500');
                    }
                });
            }

            // Format IFSC code to uppercase in edit form
            const editIfsc = document.getElementById('edit_ifsc_code');
            if (editIfsc) {
                editIfsc.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }

            // Auto-submit search form on enter
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.closest('form').submit();
                    }
                });
            }
        });
    </script>
</body>
</html>