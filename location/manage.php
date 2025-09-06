<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Check if user has permission to manage locations
$allowed_roles = ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../dashboard.php");
    exit();
}

// Set page title and subtitle for header component
$page_title = 'Manage Locations';
$page_subtitle = 'Location Management';

$success_message = '';
$error_message = '';

// Handle form submission for adding new location
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_location') {
    try {
        $location_name = trim($_POST['location_name']);
        
        if (empty($location_name)) {
            $error_message = "Location name is required.";
        } else {
            // Check if location already exists
            $check_stmt = $pdo->prepare("SELECT id FROM location WHERE location = ?");
            $check_stmt->execute([$location_name]);
            
            if ($check_stmt->fetch()) {
                $error_message = "Location already exists.";
            } else {
                // Insert new location
                $stmt = $pdo->prepare("INSERT INTO location (location, updated_by, updated_at) VALUES (?, ?, NOW())");
                $stmt->execute([$location_name, $_SESSION['user_id']]);
                
                $success_message = "Location added successfully!";
            }
        }
    } catch (Exception $e) {
        $error_message = "Error adding location: " . $e->getMessage();
    }
}

// Handle location deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    try {
        $location_id = (int)$_GET['id'];
        
        // Check if location is being used in bookings
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE from_location_id = ? OR to_location_id = ?");
        $check_stmt->execute([$location_id, $location_id]);
        $usage_count = $check_stmt->fetchColumn();
        
        if ($usage_count > 0) {
            $error_message = "Cannot delete location. It is being used in " . $usage_count . " booking(s).";
        } else {
            $stmt = $pdo->prepare("DELETE FROM location WHERE id = ?");
            $stmt->execute([$location_id]);
            $success_message = "Location deleted successfully!";
        }
    } catch (Exception $e) {
        $error_message = "Error deleting location: " . $e->getMessage();
    }
}

// Get all locations
$locations_stmt = $pdo->query("
    SELECT l.*, u.username as updated_by_name 
    FROM location l 
    LEFT JOIN users u ON l.updated_by = u.id 
    ORDER BY l.location
");
$locations = $locations_stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Locations - Absuma Logistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --absuma-red: #dc2625;
            --absuma-red-light: #fef2f2;
            --absuma-red-dark: #b91c1c;
        }
        
        .text-absuma-red { color: var(--absuma-red); }
        .bg-absuma-red { background-color: var(--absuma-red); }
        .border-absuma-red { border-color: var(--absuma-red); }
        .hover\:bg-absuma-red:hover { background-color: var(--absuma-red); }
        .hover\:text-absuma-red:hover { color: var(--absuma-red); }
        
        .gradient-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .shadow-soft {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .input-enhanced {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            background-color: white;
            transition: all 0.2s;
        }
        
        .input-enhanced:focus {
            outline: none;
            border-color: var(--absuma-red);
            box-shadow: 0 0 0 3px rgba(220, 38, 37, 0.1);
        }
        
        .btn-enhanced {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.2s;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            font-size: 0.875rem;
        }
        
        .btn-enhanced:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }
        
        .btn-primary {
            background-color: var(--absuma-red);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--absuma-red-dark);
        }
        
        .btn-secondary {
            background-color: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        
        .btn-danger {
            background-color: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b91c1c;
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen">
        <!-- Header with Absuma Branding -->
        <?php include '../header_component.php'; ?>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar with Absuma colors -->
                <aside class="w-full lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-soft p-4 sticky top-20 border border-white/20 backdrop-blur-sm bg-white/70">
                        <!-- Location Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Location Section</h3>
                            <nav class="space-y-1.5">
                                <a href="create.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-dark hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Add Location
                                </a>
                                <a href="manage.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-absuma-red bg-red-50 rounded-lg transition-all group">
                                    <i class="fas fa-list w-5 text-center text-absuma-red"></i>Manage Locations
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Booking Section -->
                        <?php if (in_array($_SESSION['role'], ['manager1', 'admin', 'superadmin'])): ?>
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Booking Section</h3>
                            <nav class="space-y-1.5">
                                <a href="../booking/create.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-dark hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Create Booking
                                </a>
                                <a href="../booking/manage.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Bookings
                                </a>
                            </nav>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Vehicle Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Vehicle Section</h3>
                            <nav class="space-y-1.5">
                                <a href="../add_vehicle.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-dark hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Add Vehicle & Driver
                                </a>
                                <a href="../manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Vehicles
                                </a>
                                <a href="../manage_drivers.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-users w-5 text-center text-absuma-red group-hover:text-absuma-red"></i> Manage Drivers
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Client Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Client Section</h3>
                            <nav class="space-y-1">
                                <a href="../add_client.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red"></i> Register Client
                                </a>
                                <a href="../manage_clients.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i> Manage Clients
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div class="space-y-6">
                        <!-- Page Header -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                        <i class="fas fa-map-marker-alt text-absuma-red mr-3"></i>
                                        Manage Locations
                                    </h2>
                                    <p class="text-gray-600">Add, edit, and manage location information</p>
                                </div>
                                <div class="hidden md:block">
                                    <div class="bg-red-50 p-4 rounded-lg">
                                        <i class="fas fa-globe text-3xl text-absuma-red"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Success/Error Messages -->
                        <?php if ($success_message): ?>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4 animate-fade-in">
                                <div class="flex items-center">
                                    <i class="fas fa-check-circle text-green-600 mr-3"></i>
                                    <span class="text-green-800 font-medium"><?= htmlspecialchars($success_message) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4 animate-fade-in">
                                <div class="flex items-center">
                                    <i class="fas fa-exclamation-circle text-red-600 mr-3"></i>
                                    <span class="text-red-800 font-medium"><?= htmlspecialchars($error_message) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Add New Location Form -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border border-gray-100">
                            <div class="flex items-center gap-3 mb-6">
                                <div class="w-10 h-10 bg-absuma-red text-white rounded-lg flex items-center justify-center">
                                    <i class="fas fa-plus text-sm"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800">Add New Location</h3>
                            </div>
                            
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="add_location">
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="md:col-span-2">
                                        <label for="location_name" class="block text-sm font-medium text-gray-700 mb-2">
                                            <i class="fas fa-map-marker-alt text-absuma-red mr-2"></i>Location Name *
                                        </label>
                                        <input type="text" 
                                               name="location_name" 
                                               id="location_name" 
                                               class="input-enhanced" 
                                               placeholder="Enter location name"
                                               required>
                                    </div>
                                    
                                    <div class="flex items-end">
                                        <button type="submit" class="btn-enhanced btn-primary w-full">
                                            <i class="fas fa-save mr-2"></i>Add Location
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Locations List -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border border-gray-100">
                            <div class="flex items-center gap-3 mb-6">
                                <div class="w-10 h-10 bg-absuma-red text-white rounded-lg flex items-center justify-center">
                                    <i class="fas fa-list text-sm"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800">All Locations (<?= count($locations) ?>)</h3>
                            </div>
                            
                            <?php if (empty($locations)): ?>
                                <div class="text-center py-12">
                                    <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-map-marker-alt text-3xl text-gray-400"></i>
                                    </div>
                                    <p class="text-lg font-medium text-gray-500 mb-2">No locations found</p>
                                    <p class="text-sm text-gray-400">Add your first location using the form above</p>
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location Name</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Updated By</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Updated</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($locations as $location): ?>
                                                <tr class="hover:bg-gray-50 transition-colors">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        #<?= $location['id'] ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        <div class="flex items-center">
                                                            <i class="fas fa-map-marker-alt text-absuma-red mr-2"></i>
                                                            <?= htmlspecialchars($location['location']) ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?= htmlspecialchars($location['updated_by_name'] ?? 'System') ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?= $location['updated_at'] ? date('M j, Y g:i A', strtotime($location['updated_at'])) : 'Never' ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <div class="flex gap-2">
                                                            <a href="edit.php?id=<?= (int)$location['id'] ?>" class="text-blue-600 hover:text-blue-800 transition-colors" title="Edit Location">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <button onclick="showDeleteModal(<?= $location['id'] ?>, '<?= htmlspecialchars($location['location']) ?>')" 
                                                                    class="text-red-600 hover:text-red-800 transition-colors" title="Delete Location">
                                                                <i class="fas fa-trash"></i>
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
                    </div>
                </main>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Delete Location</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500">
                        Are you sure you want to delete the location <span id="locationName" class="font-semibold text-gray-900"></span>?
                    </p>
                    <p class="text-sm text-red-600 mt-2">
                        This action cannot be undone.
                    </p>
                </div>
                <div class="flex gap-3 justify-center mt-4">
                    <button id="confirmDelete" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        <i class="fas fa-trash mr-1"></i>Delete
                    </button>
                    <button onclick="hideDeleteModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        <i class="fas fa-times mr-1"></i>Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let deleteLocationId = null;
        
        function showDeleteModal(id, name) {
            deleteLocationId = id;
            document.getElementById('locationName').textContent = `"${name}"`;
            document.getElementById('deleteModal').classList.remove('hidden');
        }
        
        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
            deleteLocationId = null;
        }
        
        document.getElementById('confirmDelete').addEventListener('click', function() {
            if (deleteLocationId) {
                window.location.href = `?action=delete&id=${deleteLocationId}`;
            }
        });
        
        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideDeleteModal();
            }
        });
    </script>
</body>
</html>
