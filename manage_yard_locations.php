<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Set page title and subtitle for header component
$page_title = "Manage Yard Locations";
$page_subtitle = "View and manage all yard locations";

// Handle deletion
if (isset($_GET['delete_yard'])) {
    $yardId = $_GET['delete_yard'];
    
    try {
        $pdo->beginTransaction();
        
        // Get yard details before deletion
        $stmt = $pdo->prepare("SELECT yard_name FROM yard_locations WHERE id = ?");
        $stmt->execute([$yardId]);
        $yard = $stmt->fetch();
        
        if ($yard) {
            // Soft delete the yard
            $pdo->prepare("UPDATE yard_locations SET deleted_at = NOW() WHERE id = ?")
                 ->execute([$yardId]);
            
            $pdo->commit();
            $_SESSION['success'] = "Yard '{$yard['yard_name']}' deleted successfully";
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Yard not found";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Delete failed: " . $e->getMessage();
    }
    header("Location: manage_yard_locations.php");
    exit();
}

// Handle status updates
if (isset($_POST['update_status'])) {
    $yardId = $_POST['yard_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE yard_locations SET is_active = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $yardId]);
        $_SESSION['success'] = "Yard status updated successfully";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Status update failed: " . $e->getMessage();
    }
    header("Location: manage_yard_locations.php");
    exit();
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_location = $_GET['location'] ?? '';
$filter_type = $_GET['type'] ?? '';
$search_term = $_GET['search'] ?? '';

// Base query
$query = "
    SELECT 
        yl.*,
        l.location as location_name
    FROM yard_locations yl
    LEFT JOIN location l ON yl.location_id = l.id
    WHERE yl.deleted_at IS NULL
";

// Add filters
$params = [];
if ($filter_status !== '') {
    $query .= " AND yl.is_active = ?";
    $params[] = $filter_status;
}
if ($filter_location) {
    $query .= " AND yl.location_id = ?";
    $params[] = $filter_location;
}
if ($filter_type) {
    $query .= " AND yl.yard_type = ?";
    $params[] = $filter_type;
}
if ($search_term) {
    $query .= " AND (yl.yard_name LIKE ? OR yl.yard_code LIKE ? OR yl.contact_person LIKE ?)";
    $searchParam = "%$search_term%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " ORDER BY l.location, yl.yard_name";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$yards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get locations for filter dropdown
$locations = $pdo->query("SELECT id, location FROM location ORDER BY location")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for summary cards
$totalYards = count($yards);
$activeYards = count(array_filter($yards, fn($yard) => $yard['is_active']));
$inactiveYards = $totalYards - $activeYards;
$locationCount = count(array_unique(array_column($yards, 'location_id')));

// Display session messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Yard Locations - Absuma Logistics Fleet Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'absuma-red': '#e53e3e',
                        'absuma-red-dark': '#c53030',
                        primary: {
                            light: '#fed7d7',
                            DEFAULT: '#e53e3e',
                            dark: '#c53030',
                        },
                        secondary: {
                            light: '#6ee7b7',
                            DEFAULT: '#10b981',
                            dark: '#059669',
                        },
                        accent: {
                            light: '#fcd34d',
                            DEFAULT: '#f59e0b',
                            dark: '#d97706',
                        },
                        dark: '#1e293b',
                        light: '#f8fafc',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.4s ease-out forwards',
                        'float': 'float 3s ease-in-out infinite',
                        'pulse-slow': 'pulse 3s infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: 0, transform: 'translateY(20px)' },
                            '100%': { opacity: 1, transform: 'translateY(0)' }
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-5px)' }
                        }
                    },
                    boxShadow: {
                        'soft': '0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025)',
                        'glow': '0 0 15px -3px rgba(229, 62, 62, 0.3)',
                        'glow-blue': '0 0 15px -3px rgba(59, 130, 246, 0.3)',
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        
        .absuma-gradient {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
        }
        
        .stats-card {
            @apply bg-white rounded-xl shadow-soft p-6 border-l-4 transition-all duration-300 hover:shadow-glow-blue hover:-translate-y-1;
        }
        
        .card-hover-effect {
            @apply transition-all duration-300 hover:-translate-y-1 hover:shadow-lg;
        }
        
        .table-hover-effect {
            @apply hover:bg-red-50/50 transition-colors;
        }
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen">
        <!-- Header with Absuma Branding -->
        <?php include 'header_component.php'; ?>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Sidebar with Absuma colors -->
                <aside class="w-full lg:w-64 flex-shrink-0">
                    <div class="bg-white rounded-xl shadow-soft p-4 sticky top-20 border border-white/20 backdrop-blur-sm bg-white/70">
                        <!-- Yard Management Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Yard Management</h3>
                            <nav class="space-y-1.5">
                                <a href="add_yard_location.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Add Yard Location
                                </a>
                                <a href="manage_yard_locations.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium absuma-gradient text-white rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center"></i>Manage Yards
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-tachometer-alt w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Dashboard
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Vehicle Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Vehicle Section</h3>
                            <nav class="space-y-1.5">
                                <a href="add_vehicle.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Add Vehicle & Driver
                                </a>
                                <a href="manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Vehicles
                                </a>
                                <a href="manage_drivers.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-users w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Drivers
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Vendors Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Vendors Section</h3>
                            <nav class="space-y-1">
                                <a href="vendor_registration.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red"></i>Register Vendor
                                </a>
                                <a href="manage_vendors.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all">
                                    <i class="fas fa-list w-5 text-center text-absuma-red"></i>Manage Vendors
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div class="space-y-6">
                        <!-- Welcome Section -->
                        <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-800 mb-2">
                                        Yard Location Management
                                    </h2>
                                    <p class="text-gray-600">Manage and monitor all yard locations</p>
                                </div>
                                <div class="hidden md:block">
                                    <div class="bg-red-50 p-4 rounded-lg">
                                        <i class="fas fa-warehouse text-3xl text-absuma-red"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Success/Error Messages -->
                        <?php if ($success): ?>
                            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg animate-fade-in">
                                <div class="flex items-center">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    <?= htmlspecialchars($success) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg animate-fade-in">
                                <div class="flex items-center">
                                    <i class="fas fa-exclamation-circle mr-2"></i>
                                    <?= htmlspecialchars($error) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Stats Grid with Absuma colors -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <!-- Total Yards Card -->
                            <div class="stats-card border-blue-300 animate-fade-in animation-delay-100">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-blue-200/70 flex items-center justify-center text-blue-700 shadow-inner">
                                        <i class="fas fa-warehouse text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-blue-800">Total Yards</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-blue-900"><?= $totalYards ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Active Yards Card -->
                            <div class="stats-card border-green-300 animate-fade-in animation-delay-200">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-green-200/70 flex items-center justify-center text-green-700 shadow-inner">
                                        <i class="fas fa-check-circle text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-green-800">Active Yards</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-green-900"><?= $activeYards ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Inactive Yards Card -->
                            <div class="stats-card border-amber-300 animate-fade-in animation-delay-300">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-amber-200/70 flex items-center justify-center text-amber-700 shadow-inner">
                                        <i class="fas fa-pause-circle text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-amber-800">Inactive Yards</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-amber-900"><?= $inactiveYards ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Locations Card -->
                            <div class="stats-card border-purple-300 animate-fade-in animation-delay-400">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-full bg-purple-200/70 flex items-center justify-center text-purple-700 shadow-inner">
                                        <i class="fas fa-map-marker-alt text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-purple-800">Locations</p>
                                        <div class="flex items-end gap-2">
                                            <p class="text-2xl font-bold text-purple-900"><?= $locationCount ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters Section -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                <i class="fas fa-filter text-absuma-red mr-2"></i>
                                Filters & Search
                            </h3>
                            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                    <div class="relative">
                                        <input type="text" name="search" value="<?= htmlspecialchars($search_term) ?>"
                                               placeholder="Name, code, contact..." 
                                               class="w-full px-3 py-2 pl-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-absuma-red focus:border-absuma-red">
                                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-absuma-red focus:border-absuma-red">
                                        <option value="">All Status</option>
                                        <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                                        <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                    <select name="location" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-absuma-red focus:border-absuma-red">
                                        <option value="">All Locations</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?= $location['id'] ?>" <?= $filter_location == $location['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($location['location']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                    <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-absuma-red focus:border-absuma-red">
                                        <option value="">All Types</option>
                                        <option value="warehouse" <?= $filter_type === 'warehouse' ? 'selected' : '' ?>>Warehouse</option>
                                        <option value="storage" <?= $filter_type === 'storage' ? 'selected' : '' ?>>Storage</option>
                                        <option value="distribution" <?= $filter_type === 'distribution' ? 'selected' : '' ?>>Distribution</option>
                                        <option value="transit" <?= $filter_type === 'transit' ? 'selected' : '' ?>>Transit</option>
                                    </select>
                                </div>
                                <div class="flex items-end gap-2">
                                    <button type="submit" class="absuma-gradient text-white px-4 py-2 rounded-lg font-medium hover:shadow-glow transition-all flex-1">
                                        <i class="fas fa-search mr-2"></i>Filter
                                    </button>
                                    <a href="manage_yard_locations.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                                        <i class="fas fa-undo"></i>
                                    </a>
                                </div>
                            </form>
                        </div>

                        <!-- Actions Bar -->
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div class="flex items-center gap-4">
                                <h3 class="text-lg font-semibold text-gray-800">
                                    Yard Locations
                                    <span class="text-sm text-gray-500 font-normal">(<?= count($yards) ?> results)</span>
                                </h3>
                            </div>
                            <div class="flex gap-2">
                                <a href="add_yard_location.php" 
                                   class="absuma-gradient text-white px-4 py-2 rounded-lg font-medium hover:shadow-glow transition-all flex items-center gap-2">
                                    <i class="fas fa-plus"></i>Add Yard
                                </a>
                                <a href="dashboard.php" 
                                   class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-medium transition-colors flex items-center gap-2">
                                    <i class="fas fa-home"></i>Dashboard
                                </a>
                            </div>
                        </div>

                        <!-- Yards Table -->
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden card-hover-effect">
                            <?php if (empty($yards)): ?>
                                <div class="text-center py-12">
                                    <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-warehouse text-gray-400 text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No yard locations found</h3>
                                    <p class="text-gray-500 mb-6">Try adjusting your search criteria or add a new yard location.</p>
                                    <a href="add_yard_location.php" 
                                       class="absuma-gradient text-white px-6 py-3 rounded-lg font-medium hover:shadow-glow transition-all inline-flex items-center gap-2">
                                        <i class="fas fa-plus"></i>Add First Yard
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200/50">
                                        <thead class="bg-gray-50/50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Yard Details</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Location</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Type</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Contact</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Status</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold text-absuma-red uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200/30">
                                            <?php foreach ($yards as $yard): ?>
                                                <tr class="table-hover-effect">
                                                    <!-- Yard Details -->
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <div class="flex-shrink-0 h-10 w-10 rounded-full bg-red-100 flex items-center justify-center text-absuma-red">
                                                                <i class="fas fa-warehouse text-sm"></i>
                                                            </div>
                                                            <div class="ml-3">
                                                                <div class="text-sm font-medium text-gray-900">
                                                                    <?= htmlspecialchars($yard['yard_name']) ?>
                                                                </div>
                                                                <?php if ($yard['yard_code']): ?>
                                                                    <div class="text-sm text-gray-500">
                                                                        Code: <?= htmlspecialchars($yard['yard_code']) ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <div class="text-xs text-gray-400">
                                                                    ID: <?= $yard['id'] ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    
                                                    <!-- Location -->
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900">
                                                            <i class="fas fa-map-marker-alt text-blue-500 mr-1"></i>
                                                            <?= htmlspecialchars($yard['location_name'] ?? 'Unknown') ?>
                                                        </div>
                                                        <?php if ($yard['address']): ?>
                                                            <div class="text-xs text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($yard['address']) ?>">
                                                                <?= htmlspecialchars($yard['address']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <!-- Type -->
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize
                                                            <?= $yard['yard_type'] === 'warehouse' ? 'bg-blue-100 text-blue-800' : 
                                                                ($yard['yard_type'] === 'storage' ? 'bg-green-100 text-green-800' : 
                                                                ($yard['yard_type'] === 'distribution' ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800')) ?>">
                                                            <i class="fas <?= $yard['yard_type'] === 'warehouse' ? 'fa-warehouse' : 
                                                                ($yard['yard_type'] === 'storage' ? 'fa-boxes' : 
                                                                ($yard['yard_type'] === 'distribution' ? 'fa-shipping-fast' : 'fa-truck')) ?> mr-1"></i>
                                                            <?= htmlspecialchars($yard['yard_type']) ?>
                                                        </span>
                                                    </td>
                                                    
                                                    <!-- Contact -->
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-900">
                                                            <?php if ($yard['contact_person']): ?>
                                                                <div class="font-medium"><?= htmlspecialchars($yard['contact_person']) ?></div>
                                                            <?php else: ?>
                                                                <span class="text-gray-400">No contact</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($yard['phone_number']): ?>
                                                            <div class="text-xs text-gray-500">
                                                                <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($yard['phone_number']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($yard['email']): ?>
                                                            <div class="text-xs text-gray-500">
                                                                <i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($yard['email']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <!-- Status -->
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                            <?= $yard['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                            <i class="fas <?= $yard['is_active'] ? 'fa-check-circle' : 'fa-times-circle' ?> mr-1"></i>
                                                            <?= $yard['is_active'] ? 'Active' : 'Inactive' ?>
                                                        </span>
                                                        <div class="text-xs text-gray-500 mt-1">
                                                            Added: <?= date('M j, Y', strtotime($yard['created_at'])) ?>
                                                        </div>
                                                    </td>
                                                    
                                                    <!-- Actions -->
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <div class="flex items-center space-x-2">
                                                            <a href="edit_yard_location.php?id=<?= $yard['id'] ?>" 
                                                               class="text-blue-600 hover:text-blue-900 transition-colors" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="yard_id" value="<?= $yard['id'] ?>">
                                                                <input type="hidden" name="new_status" value="<?= $yard['is_active'] ? 0 : 1 ?>">
                                                                <button type="submit" name="update_status" 
                                                                        class="<?= $yard['is_active'] ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900' ?> transition-colors" 
                                                                        title="<?= $yard['is_active'] ? 'Deactivate' : 'Activate' ?>"
                                                                        onclick="return confirm('Are you sure you want to <?= $yard['is_active'] ? 'deactivate' : 'activate' ?> this yard?');">
                                                                    <i class="fas <?= $yard['is_active'] ? 'fa-ban' : 'fa-check' ?>"></i>
                                                                </button>
                                                            </form>
                                                            
                                                            <a href="?delete_yard=<?= $yard['id'] ?>" 
                                                               class="text-red-600 hover:text-red-900 transition-colors" 
                                                               title="Delete"
                                                               onclick="return confirm('Are you sure you want to delete this yard? This action cannot be undone.');">
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

                        <!-- Summary Information -->
                        <?php if (!empty($yards)): ?>
                            <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                    <i class="fas fa-info-circle text-absuma-red mr-2"></i>
                                    Summary Information
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                                        <div class="text-2xl font-bold text-blue-600 mb-1"><?= $totalYards ?></div>
                                        <div class="text-sm text-blue-800">Total Yards</div>
                                    </div>
                                    <div class="text-center p-4 bg-green-50 rounded-lg">
                                        <div class="text-2xl font-bold text-green-600 mb-1"><?= $activeYards ?></div>
                                        <div class="text-sm text-green-800">Active Yards</div>
                                        <div class="text-xs text-green-600"><?= $totalYards > 0 ? round(($activeYards / $totalYards) * 100, 1) : 0 ?>% of total</div>
                                    </div>
                                    <div class="text-center p-4 bg-purple-50 rounded-lg">
                                        <div class="text-2xl font-bold text-purple-600 mb-1"><?= $locationCount ?></div>
                                        <div class="text-sm text-purple-800">Unique Locations</div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </main>
            </div>
        </div>
    </div>

    <!-- JavaScript for enhanced functionality -->
    <script>
        // Auto-hide success/error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.animate-fade-in');
            alerts.forEach(alert => {
                if (alert.classList.contains('bg-green-50') || alert.classList.contains('bg-red-50')) {
                    setTimeout(() => {
                        alert.style.transition = 'opacity 0.5s ease-out';
                        alert.style.opacity = '0';
                        setTimeout(() => {
                            alert.remove();
                        }, 500);
                    }, 5000);
                }
            });
        });

        // Confirm deletion
        function confirmDelete(yardName) {
            return confirm(`Are you sure you want to delete the yard "${yardName}"? This action cannot be undone.`);
        }

        // Enhanced search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') {
                        this.closest('form').submit();
                    }
                });
            }
        });

        // Add loading state to buttons
        document.querySelectorAll('button[type="submit"]').forEach(button => {
            button.addEventListener('click', function() {
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                setTimeout(() => {
                    this.disabled = false;
                }, 3000);
            });
        });

        // Tooltip functionality for truncated text
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.className = 'absolute z-50 p-2 text-xs bg-gray-800 text-white rounded shadow-lg max-w-xs';
                tooltip.textContent = this.getAttribute('title');
                tooltip.style.top = (this.offsetTop - 30) + 'px';
                tooltip.style.left = this.offsetLeft + 'px';
                this.appendChild(tooltip);
            });
            
            element.addEventListener('mouseleave', function() {
                const tooltip = this.querySelector('.absolute.z-50');
                if (tooltip) tooltip.remove();
            });
        });
    </script>
</body>
</html>