<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

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
            // Soft delete client rates
            $pdo->prepare("UPDATE client_rates SET deleted_at = NOW() WHERE client_id = ?")->execute([$clientId]);

            // Soft delete client documents
            $pdo->prepare("UPDATE client_documents SET deleted_at = NOW() WHERE client_id = ?")->execute([$clientId]);

            // Delete client contacts
            $pdo->prepare("DELETE FROM client_contacts WHERE client_id = ?")->execute([$clientId]);

            // Soft delete the client
            $pdo->prepare("UPDATE clients SET deleted_at = NOW() WHERE id = ?")->execute([$clientId]);

            $pdo->commit();
            $_SESSION['success'] = "Client '{$client['client_name']}' and all associated data deleted successfully";
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
    <title>Manage Clients - Fleet Management</title>
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
            .badge-inactive {
                @apply bg-gray-100 text-gray-800;
            }
            .badge-suspended {
                @apply bg-red-100 text-red-800;
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
            max-width: 1000px !important;
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
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Fleet Management System</h1>
                            <p class="text-blue-600 text-sm">Manage Clients</p>
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
                                <a href="add_client.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-blue-50 hover:text-blue-600 rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center"></i> Add New Client
                                </a>
                                <a href="manage_clients.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg">
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
                    
                    <!-- Client Statistics -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-chart-bar text-blue-600"></i> Client Overview
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-blue-600"><?= $stats['total_clients'] ?></div>
                                <div class="text-sm text-blue-800">Total Clients</div>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-green-600"><?= $stats['active_clients'] ?></div>
                                <div class="text-sm text-green-800">Active</div>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-gray-600"><?= $stats['inactive_clients'] ?></div>
                                <div class="text-sm text-gray-800">Inactive</div>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-red-600"><?= $stats['suspended_clients'] ?></div>
                                <div class="text-sm text-red-800">Suspended</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filters and Search -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <h2 class="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                            <i class="fas fa-filter text-blue-600"></i> Filter Clients
                        </h2>

                        <form method="GET" class="flex flex-wrap gap-6 items-end">
                            <div class="flex flex-col w-48">
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select id="status" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">All Status</option>
                                    <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                </select>
                            </div>
                            
                            <div class="flex flex-col w-64">
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_term) ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="Search clients...">
                            </div>
                            
                            <div class="flex gap-2">
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-filter mr-2"></i> Apply Filters
                                </button>
                                <a href="manage_clients.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-times mr-2"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Clients List -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6 card-hover-effect">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                            <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-users text-blue-600"></i> Client List (<?= count($clients) ?>)
                            </h2>
                            
                            <a href="add_client.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-plus mr-2"></i> Add New Client
                            </a>
                        </div>
                        
                        <?php if (empty($clients)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-user-friends text-4xl text-gray-400 mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-700">No Clients Found</h3>
                                <p class="text-gray-500 mt-2">Get started by adding your first client</p>
                                <a href="add_client.php" class="inline-flex items-center px-4 py-2 mt-4 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-plus mr-2"></i> Add Client
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client Details</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Info</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Billing Info</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rates & Docs</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($clients as $client): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                            <i class="fas fa-building text-blue-600"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($client['client_name']) ?></div>
                                                            <div class="text-sm text-gray-500"><?= htmlspecialchars($client['client_code']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <div class="font-medium"><?= htmlspecialchars($client['contact_person']) ?></div>
                                                        <div class="text-gray-500"><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($client['phone_number']) ?></div>
                                                        <div class="text-gray-500"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($client['email_address']) ?></div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <div>Cycle: <?= $client['billing_cycle_days'] ?> days</div>
                                                        <?php if ($client['pan_number']): ?>
                                                            <div class="text-xs text-gray-500">PAN: <?= htmlspecialchars($client['pan_number']) ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($client['gst_number']): ?>
                                                            <div class="text-xs text-gray-500">GST: <?= htmlspecialchars($client['gst_number']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <div><i class="fas fa-money-bill text-green-500 mr-1"></i><?= $client['rate_count'] ?> rates</div>
                                                        <div><i class="fas fa-file text-blue-500 mr-1"></i><?= $client['document_count'] ?> docs</div>
                                                        <div><i class="fas fa-users text-purple-500 mr-1"></i><?= $client['contact_count'] ?> contacts</div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="badge badge-<?= strtolower($client['status']) ?>">
                                                        <?= ucfirst($client['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center gap-2">
                                                        <a href="edit_client.php?id=<?= $client['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Edit Client">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button onclick="showClientRates(<?= $client['id'] ?>)" class="text-green-600 hover:text-green-800" title="View Rates">
                                                            <i class="fas fa-money-bill"></i>
                                                        </button>
                                                        <button onclick="updateClientStatus(<?= $client['id'] ?>, '<?= $client['status'] ?>')" class="text-purple-600 hover:text-purple-800" title="Update Status">
                                                            <i class="fas fa-toggle-on"></i>
                                                        </button>
                                                        <a href="manage_clients.php?delete_client=<?= $client['id'] ?>" class="text-red-600 hover:text-red-800" title="Delete Client" onclick="return confirm('Are you sure you want to delete this client and all associated data? This action cannot be undone.');">
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

            <!-- Client Rates Modal -->
            <div class="modal-overlay" id="clientRatesModal" style="display: none !important;">
                <div class="modal-container" onclick="event.stopPropagation()">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6 border-b border-gray-200 pb-4">
                            <h3 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-money-bill text-green-600"></i>
                                Client Rates
                            </h3>
                            <button type="button" class="text-gray-400 hover:text-gray-600 p-2 rounded-full hover:bg-gray-100 transition-colors" onclick="hideClientRatesModal()">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                        
                        <!-- Rates List -->
                        <div id="clientRatesList">
                            <!-- Content loaded dynamically -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Update Modal -->
            <div class="modal-overlay" id="statusModal" style="display: none !important;">
                <div class="modal-container" onclick="event.stopPropagation()" style="max-width: 400px;">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6 border-b border-gray-200 pb-4">
                            <h3 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-toggle-on text-purple-600"></i>
                                Update Status
                            </h3>
                            <button type="button" class="text-gray-400 hover:text-gray-600 p-2 rounded-full hover:bg-gray-100 transition-colors" onclick="hideStatusModal()">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                        
                        <form method="POST" action="manage_clients.php">
                            <input type="hidden" name="client_id" id="statusClientId">
                            
                            <div class="mb-4">
                                <label for="new_status" class="block text-sm font-medium text-gray-700 mb-2">Select New Status</label>
                                <select name="new_status" id="new_status" required class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                </select>
                            </div>
                            
                            <div class="flex justify-end gap-3">
                                <button type="button" onclick="hideStatusModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Cancel
                                </button>
                                <button type="submit" name="update_status" class="px-4 py-2 border border-transparent rounded-md text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Update Status
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Client rates modal functions
// ===========================================
// UPDATED: showClientRates function for manage_clients.php
// Replace the existing showClientRates function with this updated version:
// ===========================================

function showClientRates(clientId) {
    if (!clientId) {
        alert('Invalid client ID');
        return;
    }

    // Show loading state
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    modal.innerHTML = `
        <div class="bg-white rounded-xl p-6 max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-center py-8">
                <i class="fas fa-spinner fa-spin text-2xl text-blue-600 mr-3"></i>
                <span class="text-lg">Loading client rates...</span>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Fetch client rates
    fetch(`get_client_rates.php?client_id=${clientId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showClientRatesModal(data.client, data.rates);
                modal.remove();
            } else {
                modal.remove();
                alert('Error loading client rates: ' + data.message);
            }
        })
        .catch(error => {
            modal.remove();
            console.error('Error fetching client rates:', error);
            alert('Error loading client rates. Please try again.');
        });
}

function showClientRatesModal(client, rates) {
    // Remove any existing modals
    const existingModals = document.querySelectorAll('.client-rates-modal');
    existingModals.forEach(modal => modal.remove());

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'client-rates-modal fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
    
    // Separate rates by container size
    const rates20ft = rates.filter(rate => rate.container_size === '20ft');
    const rates40ft = rates.filter(rate => rate.container_size === '40ft');

    modal.innerHTML = `
        <div class="bg-white rounded-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-hidden shadow-2xl">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4 text-white">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold flex items-center gap-2">
                            <i class="fas fa-money-bill-wave"></i>
                            Client Rate Structure
                        </h2>
                        <p class="text-blue-100 text-sm mt-1">
                            ${client.client_name} (${client.client_code})
                        </p>
                    </div>
                    <button onclick="this.closest('.client-rates-modal').remove()" 
                            class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-white hover:bg-opacity-20 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>

            <!-- Modal Content -->
            <div class="p-6 overflow-y-auto" style="max-height: calc(90vh - 120px);">
                ${rates.length === 0 ? `
                    <div class="text-center py-12">
                        <i class="fas fa-chart-line text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-700 mb-2">No Rates Configured</h3>
                        <p class="text-gray-500">This client doesn't have any rate configurations yet.</p>
                        <a href="edit_client.php?id=${client.id}" 
                           class="inline-flex items-center px-4 py-2 mt-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-plus mr-2"></i> Add Rates
                        </a>
                    </div>
                ` : `
                    <!-- Rate Statistics -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-shipping-fast text-blue-600"></i>
                                <div>
                                    <div class="text-2xl font-bold text-blue-600">${rates.length}</div>
                                    <div class="text-xs text-blue-700">Total Rates</div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-box text-green-600"></i>
                                <div>
                                    <div class="text-2xl font-bold text-green-600">${rates20ft.length}</div>
                                    <div class="text-xs text-green-700">20ft Rates</div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg border border-purple-200">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-cube text-purple-600"></i>
                                <div>
                                    <div class="text-2xl font-bold text-purple-600">${rates40ft.length}</div>
                                    <div class="text-xs text-purple-700">40ft Rates</div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-rupee-sign text-yellow-600"></i>
                                <div>
                                    <div class="text-2xl font-bold text-yellow-600">₹${Math.round(rates.reduce((sum, rate) => sum + parseFloat(rate.rate || 0), 0) / rates.length).toLocaleString()}</div>
                                    <div class="text-xs text-yellow-700">Avg Rate</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 20ft Container Rates -->
                    ${rates20ft.length > 0 ? `
                        <div class="mb-6">
                            <div class="bg-blue-50 p-3 rounded-lg mb-4">
                                <h3 class="text-lg font-semibold text-blue-800 flex items-center gap-2">
                                    <i class="fas fa-box"></i>
                                    20ft Container Rates (${rates20ft.length})
                                </h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Movement</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Container</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Route</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Rate</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Validity</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        ${rates20ft.map(rate => `
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getMovementTypeBadgeClass(rate.movement_type)}">
                                                        ${capitalizeFirst(rate.movement_type)}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getContainerTypeBadgeClass(rate.container_type)}">
                                                        ${capitalizeFirst(rate.container_type)}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    ${rate.from_location && rate.to_location ? `
                                                        <div class="text-sm">
                                                            <div class="flex items-center gap-1 text-green-600">
                                                                <i class="fas fa-map-marker-alt text-xs"></i>
                                                                <span class="font-medium">${rate.from_location}</span>
                                                            </div>
                                                            <div class="flex items-center gap-1 text-red-600 mt-1">
                                                                <i class="fas fa-map-marker-alt text-xs"></i>
                                                                <span class="font-medium">${rate.to_location}</span>
                                                            </div>
                                                        </div>
                                                    ` : `
                                                        <span class="text-gray-400 text-sm">Local Movement</span>
                                                    `}
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="text-lg font-bold text-green-600">₹${parseFloat(rate.rate).toLocaleString()}</span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    ${rate.effective_from || rate.effective_to ? `
                                                        <div class="text-xs text-gray-600">
                                                            ${rate.effective_from ? `<div>From: ${formatDate(rate.effective_from)}</div>` : ''}
                                                            ${rate.effective_to ? `<div>To: ${formatDate(rate.effective_to)}</div>` : ''}
                                                        </div>
                                                    ` : `
                                                        <span class="text-gray-400 text-xs">No expiry</span>
                                                    `}
                                                </td>
                                                <td class="px-4 py-3">
                                                    ${rate.remarks ? `
                                                        <span class="text-xs text-gray-600 bg-gray-100 px-2 py-1 rounded">${rate.remarks}</span>
                                                    ` : `
                                                        <span class="text-gray-400 text-xs">-</span>
                                                    `}
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ` : ''}

                    <!-- 40ft Container Rates -->
                    ${rates40ft.length > 0 ? `
                        <div class="mb-6">
                            <div class="bg-green-50 p-3 rounded-lg mb-4">
                                <h3 class="text-lg font-semibold text-green-800 flex items-center gap-2">
                                    <i class="fas fa-cube"></i>
                                    40ft Container Rates (${rates40ft.length})
                                </h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Movement</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Container</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Route</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Rate</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Validity</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        ${rates40ft.map(rate => `
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getMovementTypeBadgeClass(rate.movement_type)}">
                                                        ${capitalizeFirst(rate.movement_type)}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getContainerTypeBadgeClass(rate.container_type)}">
                                                        ${capitalizeFirst(rate.container_type)}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    ${rate.from_location && rate.to_location ? `
                                                        <div class="text-sm">
                                                            <div class="flex items-center gap-1 text-green-600">
                                                                <i class="fas fa-map-marker-alt text-xs"></i>
                                                                <span class="font-medium">${rate.from_location}</span>
                                                            </div>
                                                            <div class="flex items-center gap-1 text-red-600 mt-1">
                                                                <i class="fas fa-map-marker-alt text-xs"></i>
                                                                <span class="font-medium">${rate.to_location}</span>
                                                            </div>
                                                        </div>
                                                    ` : `
                                                        <span class="text-gray-400 text-sm">Local Movement</span>
                                                    `}
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="text-lg font-bold text-green-600">₹${parseFloat(rate.rate).toLocaleString()}</span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    ${rate.effective_from || rate.effective_to ? `
                                                        <div class="text-xs text-gray-600">
                                                            ${rate.effective_from ? `<div>From: ${formatDate(rate.effective_from)}</div>` : ''}
                                                            ${rate.effective_to ? `<div>To: ${formatDate(rate.effective_to)}</div>` : ''}
                                                        </div>
                                                    ` : `
                                                        <span class="text-gray-400 text-xs">No expiry</span>
                                                    `}
                                                </td>
                                                <td class="px-4 py-3">
                                                    ${rate.remarks ? `
                                                        <span class="text-xs text-gray-600 bg-gray-100 px-2 py-1 rounded">${rate.remarks}</span>
                                                    ` : `
                                                        <span class="text-gray-400 text-xs">-</span>
                                                    `}
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ` : ''}

                    <!-- Action Buttons -->
                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                        <a href="edit_client.php?id=${client.id}" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-edit mr-2"></i> Edit Rates
                        </a>
                        <button onclick="exportClientRates(${client.id}, '${client.client_name}')" 
                                class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                            <i class="fas fa-download mr-2"></i> Export
                        </button>
                    </div>
                `}
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    // Add click outside to close
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

// Helper functions for styling and formatting
function getMovementTypeBadgeClass(movementType) {
    const classes = {
        'export': 'bg-blue-100 text-blue-800',
        'import': 'bg-green-100 text-green-800',
        'domestic': 'bg-yellow-100 text-yellow-800',
        'local': 'bg-gray-100 text-gray-800'
    };
    return classes[movementType] || 'bg-gray-100 text-gray-800';
}

function getContainerTypeBadgeClass(containerType) {
    const classes = {
        'full': 'bg-purple-100 text-purple-800',
        'empty': 'bg-orange-100 text-orange-800'
    };
    return classes[containerType] || 'bg-gray-100 text-gray-800';
}

function capitalizeFirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-IN', { 
        day: '2-digit', 
        month: 'short', 
        year: 'numeric' 
    });
}

// Export function for client rates
function exportClientRates(clientId, clientName) {
    const exportUrl = `export_client_rates.php?client_id=${clientId}&format=csv`;
    const link = document.createElement('a');
    link.href = exportUrl;
    link.download = `${clientName.replace(/[^a-zA-Z0-9]/g, '_')}_rates.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}


            // Status update modal functions
            function updateClientStatus(clientId, currentStatus) {
                const modal = document.getElementById('statusModal');
                document.getElementById('statusClientId').value = clientId;
                document.getElementById('new_status').value = currentStatus;
                
                // Show modal with animation
                modal.style.display = 'flex';
                modal.style.visibility = 'visible';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.classList.add('active');
                    modal.style.opacity = '1';
                }, 10);
                
                document.body.classList.add('modal-open');
            }

            function hideStatusModal() {
                const modal = document.getElementById('statusModal');
                
                // Hide modal with animation
                modal.classList.remove('active');
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.style.display = 'none';
                    modal.style.visibility = 'hidden';
                }, 300);
                
                document.body.classList.remove('modal-open');
            }

            // Initialize modal event listeners
            document.addEventListener('DOMContentLoaded', function() {
                // Close modal when clicking outside
                const modals = document.querySelectorAll('.modal-overlay');
                modals.forEach(modal => {
                    modal.addEventListener('click', function(e) {
                        if (e.target === this) {
                            if (this.id === 'clientRatesModal') {
                                hideClientRatesModal();
                            } else if (this.id === 'statusModal') {
                                hideStatusModal();
                            }
                        }
                    });
                });

                // Close modal with escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        const activeModal = document.querySelector('.modal-overlay.active');
                        if (activeModal) {
                            if (activeModal.id === 'clientRatesModal') {
                                hideClientRatesModal();
                            } else if (activeModal.id === 'statusModal') {
                                hideStatusModal();
                            }
                        }
                    }
                });
            });
        </script>
    </body>
</html>