<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

$user_role = $_SESSION['role'];

// Check if user has access (managers and above)
if (!in_array($user_role, ['manager1', 'manager2', 'admin', 'superadmin'])) {
    $_SESSION['error'] = "You don't have permission to manage clients.";
    header("Location: dashboard.php");
    exit();
}

// Check user permissions
$can_delete_directly = in_array($user_role, ['admin', 'superadmin']);

// Handle client deletion
if (isset($_GET['delete_client'])) {
    $clientId = $_GET['delete_client'];

    try {
        $pdo->beginTransaction();

        // Get client details before deletion
        $stmt = $pdo->prepare("SELECT client_code, client_name FROM clients WHERE id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();

        if ($client) {
            if ($can_delete_directly) {
                // Direct delete
                $pdo->prepare("UPDATE client_rates SET deleted_at = NOW() WHERE client_id = ?")->execute([$clientId]);
                $pdo->prepare("UPDATE client_documents SET deleted_at = NOW() WHERE client_id = ?")->execute([$clientId]);
                $pdo->prepare("DELETE FROM client_contacts WHERE client_id = ?")->execute([$clientId]);
                $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ?")->execute([$clientId]);

                $pdo->commit();
                $_SESSION['success'] = "Client '{$client['client_name']}' deleted successfully";
            } else {
                // Request deletion
                $requestStmt = $pdo->prepare("
                    INSERT INTO client_deletion_requests 
                    (client_id, requested_by, requested_at, reason, status) 
                    VALUES (?, ?, NOW(), ?, 'pending')
                ");
                $requestStmt->execute([
                    $clientId,
                    $_SESSION['user_id'],
                    "Deletion requested by " . $_SESSION['full_name']
                ]);

                // Notify admins/superadmins
                $managerStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'superadmin') AND status = 'active'");
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
                        'Client Deletion Approval Required',
                        "Deletion of client '{$client['client_name']}' has been requested by " . $_SESSION['full_name'],
                        'warning'
                    ]);
                }

                $pdo->commit();
                $_SESSION['success'] = "Client deletion request submitted for approval";
            }
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Client not found";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: manage_clients.php");
    exit();
}

// Handle status updates
if (isset($_POST['update_status'])) {
    $clientId = $_POST['client_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE clients SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $clientId]);
        $_SESSION['success'] = "Client status updated successfully";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Status update failed: " . $e->getMessage();
    }
    header("Location: manage_clients.php");
    exit();
}

// Handle rate update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_rate'])) {
    try {
        $rateId = $_POST['rate_id'];
        $rate = trim($_POST['rate']);
        $effective_from = $_POST['effective_from'] ?: null;
        $effective_to = $_POST['effective_to'] ?: null;
        $remarks = trim($_POST['remarks']);

        if (empty($rate) || !is_numeric($rate)) {
            throw new Exception("Valid rate is required");
        }

        $updateStmt = $pdo->prepare("
            UPDATE client_rates 
            SET rate = ?, effective_from = ?, effective_to = ?, remarks = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$rate, $effective_from, $effective_to, $remarks, $rateId]);

        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX request for client rates
if (isset($_GET['get_client_rates'])) {
    try {
        $clientId = $_GET['get_client_rates'];
        $stmt = $pdo->prepare("
            SELECT * FROM client_rates 
            WHERE client_id = ? AND deleted_at IS NULL 
            ORDER BY movement_type, container_type, from_location
        ");
        $stmt->execute([$clientId]);
        $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($rates);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$search_term = $_GET['search'] ?? '';

// Base query
$query = "
    SELECT 
        c.*,
        COUNT(DISTINCT cr.id) as rate_count,
        COUNT(DISTINCT cd.id) as document_count,
        COUNT(DISTINCT cc.id) as contact_count
    FROM clients c
    LEFT JOIN client_rates cr ON c.id = cr.client_id AND cr.deleted_at IS NULL
    LEFT JOIN client_documents cd ON c.id = cd.client_id AND cd.deleted_at IS NULL
    LEFT JOIN client_contacts cc ON c.id = cc.client_id AND cc.deleted_at IS NULL
    WHERE c.deleted_at IS NULL
";

// Add filters
$params = [];
if ($filter_status) {
    $query .= " AND c.status = ?";
    $params[] = $filter_status;
}
if ($search_term) {
    $query .= " AND (c.client_name LIKE ? OR c.client_code LIKE ? OR c.contact_person LIKE ? OR c.email_address LIKE ?)";
    $searchParam = "%$search_term%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " GROUP BY c.id ORDER BY c.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Display session messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Get client statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_clients,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_clients,
        COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_clients,
        COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_clients
    FROM clients 
    WHERE deleted_at IS NULL
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Clients - Absuma Logistics</title>
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
                        <h1 class="text-2xl font-bold text-gray-900">Manage Clients</h1>
                        <p class="text-sm text-gray-600 mt-1">View and manage client information</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                            <form method="GET" class="inline">
                                <input
                                    type="text"
                                    name="search"
                                    value="<?= htmlspecialchars($search_term) ?>"
                                    placeholder="Search clients..."
                                    class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500 text-sm w-64"
                                />
                                <?php if ($filter_status): ?>
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
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
                            <i class="fas fa-chart-bar text-teal-600"></i> Client Statistics
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="bg-gray-50 p-4 rounded-lg text-center border border-gray-100">
                                <div class="text-2xl font-bold text-gray-600"><?= $stats['total_clients'] ?></div>
                                <div class="text-sm text-gray-800">Total Clients</div>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg text-center border border-green-100">
                                <div class="text-2xl font-bold text-green-600"><?= $stats['active_clients'] ?></div>
                                <div class="text-sm text-green-800">Active</div>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg text-center border border-yellow-100">
                                <div class="text-2xl font-bold text-yellow-600"><?= $stats['inactive_clients'] ?></div>
                                <div class="text-sm text-yellow-800">Inactive</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg text-center border border-red-100">
                                <div class="text-2xl font-bold text-red-600"><?= $stats['suspended_clients'] ?></div>
                                <div class="text-sm text-red-800">Suspended</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="bg-white rounded-xl shadow-soft p-6 mb-6 card-hover-effect">
                        <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                            <i class="fas fa-filter text-teal-600"></i> Filter Clients
                        </h2>

                        <form method="GET" class="flex flex-wrap gap-6 items-end">
                            <div class="flex flex-col w-48">
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">All Status</option>
                                    <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                </select>
                            </div>

                            <div class="flex gap-2">
                                <button type="submit" class="px-6 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition-colors">
                                    <i class="fas fa-search mr-2"></i> Filter
                                </button>
                                <a href="manage_clients.php" class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors">
                                    <i class="fas fa-times mr-2"></i> Clear
                                </a>
                            </div>
                            
                            <?php if ($search_term): ?>
                                <input type="hidden" name="search" value="<?= htmlspecialchars($search_term) ?>">
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Clients List -->
                    <?php if (empty($clients)): ?>
                        <div class="bg-white rounded-xl shadow-soft p-12 text-center card-hover-effect">
                            <i class="fas fa-handshake text-6xl text-gray-400 mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-700 mb-2">No Clients Found</h3>
                            <p class="text-gray-500 mb-6">Get started by registering your first client or adjust your filters</p>
                            <a href="add_client.php" class="inline-flex items-center px-6 py-3 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors font-medium">
                                <i class="fas fa-plus mr-2"></i> Add First Client
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden card-hover-effect">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client Details</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Info</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Counts</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($clients as $client): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-10 w-10">
                                                            <div class="h-10 w-10 rounded-full bg-teal-100 flex items-center justify-center">
                                                                <i class="fas fa-handshake text-teal-600"></i>
                                                            </div>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($client['client_name']) ?></div>
                                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($client['client_code']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div><?= htmlspecialchars($client['contact_person']) ?></div>
                                                   
                                                    <div class="text-gray-400"><?= htmlspecialchars($client['email_address']) ?></div>
                                                </td>
                                               
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                        <?= $client['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                            ($client['status'] === 'inactive' ? 'bg-gray-100 text-gray-800' : 'bg-red-100 text-red-800') ?>">
                                                        <?= ucfirst($client['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="flex items-center space-x-4">
                                                        <div class="text-center">
                                                            <div class="text-xs text-gray-400">Rates</div>
                                                            <div class="font-medium"><?= $client['rate_count'] ?></div>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="text-xs text-gray-400">Docs</div>
                                                            <div class="font-medium"><?= $client['document_count'] ?></div>
                                                        </div>
                                                        <div class="text-center">
                                                            <div class="text-xs text-gray-400">Contacts</div>
                                                            <div class="font-medium"><?= $client['contact_count'] ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div class="flex items-center space-x-2">
                                                        <button onclick="showRatesModal(<?= $client['id'] ?>)" class="text-teal-600 hover:text-teal-800" title="View/Edit Rates">
                                                            <i class="fas fa-money-bill-wave"></i>
                                                        </button>
                                                        <button onclick="showStatusModal(<?= $client['id'] ?>, '<?= $client['status'] ?>')" class="text-blue-600 hover:text-blue-800" title="Update Status">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <a href="manage_clients.php?delete_client=<?= $client['id'] ?>" class="text-red-600 hover:text-red-800" onclick="return confirm('Are you sure you want to <?= $can_delete_directly ? 'delete' : 'request deletion for' ?> this client?')" title="<?= $can_delete_directly ? 'Delete' : 'Request Deletion' ?> Client">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
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

    <!-- Rates Modal -->
    <div id="ratesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-4/5 lg:w-3/4 xl:w-2/3 shadow-lg rounded-xl bg-white max-h-[90vh] overflow-y-auto">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-2xl font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-money-bill-wave text-teal-600"></i> Client Rates
                    </h3>
                    <button onclick="hideRatesModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <!-- Search in Modal -->
                <div class="mb-4">
                    <label for="rateSearch" class="block text-sm font-medium text-gray-700 mb-1">Search by Location</label>
                    <input type="text" id="rateSearch" oninput="filterRates()" placeholder="Search location..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                </div>

                <!-- Rates Table -->
                <div id="ratesTable" class="overflow-x-auto">
                    <!-- Rates will be loaded here via JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Update Client Status</h3>
                <form method="POST" class="mt-2 text-left">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" id="statusClientId" name="client_id">
                    <div class="mb-4">
                        <label for="new_status" class="block text-sm font-medium text-gray-700">New Status</label>
                        <select id="new_status" name="new_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" onclick="hideStatusModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-teal-600 text-white rounded-md">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Rates modal functions
        function showRatesModal(clientId) {
            const modal = document.getElementById('ratesModal');
            modal.classList.remove('hidden');
            loadClientRates(clientId);
        }

        function hideRatesModal() {
            const modal = document.getElementById('ratesModal');
            modal.classList.add('hidden');
        }

        function loadClientRates(clientId) {
            fetch(`manage_clients.php?get_client_rates=${clientId}`)
                .then(response => response.json())
                .then(rates => {
                    const table = document.getElementById('ratesTable');
                    let html = '<table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Movement</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Container</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective From</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective To</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remarks</th><th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th></tr></thead><tbody class="bg-white divide-y divide-gray-200">';
                    rates.forEach(rate => {
                        html += `<tr data-location="${rate.from_location?.toLowerCase() || ''} ${rate.to_location?.toLowerCase() || ''}">
                            <td class="px-6 py-4">${rate.movement_type}</td>
                            <td class="px-6 py-4">${rate.container_type}</td>
                            <td class="px-6 py-4">${rate.from_location || ''}</td>
                            <td class="px-6 py-4">${rate.to_location || ''}</td>
                            <td class="px-6 py-4"><input type="number" value="${rate.rate}" class="w-full px-2 py-1 border rounded" data-field="rate"></td>
                            <td class="px-6 py-4"><input type="date" value="${rate.effective_from || ''}" class="w-full px-2 py-1 border rounded" data-field="effective_from"></td>
                            <td class="px-6 py-4"><input type="date" value="${rate.effective_to || ''}" class="w-full px-2 py-1 border rounded" data-field="effective_to"></td>
                            <td class="px-6 py-4"><input type="text" value="${rate.remarks || ''}" class="w-full px-2 py-1 border rounded" data-field="remarks"></td>
                            <td class="px-6 py-4"><button onclick="saveRate(${rate.id}, this)" class="text-teal-600 hover:text-teal-800"><i class="fas fa-save"></i></button></td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                    table.innerHTML = html;
                })
                .catch(error => console.error('Error:', error));
        }

        function saveRate(rateId, button) {
            const row = button.closest('tr');
            const data = {
                update_rate: 1,
                rate_id: rateId,
                rate: row.querySelector('[data-field="rate"]').value,
                effective_from: row.querySelector('[data-field="effective_from"]').value,
                effective_to: row.querySelector('[data-field="effective_to"]').value,
                remarks: row.querySelector('[data-field="remarks"]').value
            };

            fetch('manage_clients.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Rate updated successfully');
                } else {
                    alert(result.message);
                }
            });
        }

        function filterRates() {
            const search = document.getElementById('rateSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#ratesTable tr[data-location]');
            rows.forEach(row => {
                row.style.display = row.dataset.location.includes(search) ? '' : 'none';
            });
        }

        // Status modal functions
        function showStatusModal(clientId, currentStatus) {
            const modal = document.getElementById('statusModal');
            document.getElementById('statusClientId').value = clientId;
            document.getElementById('new_status').value = currentStatus;
            modal.classList.remove('hidden');
        }

        function hideStatusModal() {
            const modal = document.getElementById('statusModal');
            modal.classList.add('hidden');
        }

        // Handle outside clicks and escape key
        document.addEventListener('DOMContentLoaded', () => {
            const modals = [document.getElementById('ratesModal'), document.getElementById('statusModal')];
            modals.forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        modal.classList.add('hidden');
                    }
                });
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    modals.forEach(modal => modal.classList.add('hidden'));
                }
            });
        });
    </script>
</body>
</html>