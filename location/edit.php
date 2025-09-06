<?php
session_start();
require '../auth_check.php';
require '../db_connection.php';

// Check if user has permission to edit locations
$allowed_roles = ['l2_supervisor', 'manager1', 'manager2', 'admin', 'superadmin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../dashboard.php");
    exit();
}

$location_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($location_id <= 0) {
    header("Location: manage.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $location_name = trim($_POST['location_name']);
        
        if (empty($location_name)) {
            $error_message = "Location name is required.";
        } else {
            // Check if location already exists (excluding current location)
            $check_stmt = $pdo->prepare("SELECT id FROM location WHERE location = ? AND id != ?");
            $check_stmt->execute([$location_name, $location_id]);
            
            if ($check_stmt->fetch()) {
                $error_message = "Location already exists.";
            } else {
                // Update location
                $stmt = $pdo->prepare("UPDATE location SET location = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$location_name, $_SESSION['user_id'], $location_id]);
                
                $success_message = "Location updated successfully!";
            }
        }
    } catch (Exception $e) {
        $error_message = "Error updating location: " . $e->getMessage();
    }
}

// Get current location data
$stmt = $pdo->prepare("SELECT * FROM location WHERE id = ?");
$stmt->execute([$location_id]);
$location = $stmt->fetch();

if (!$location) {
    header("Location: manage.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Location - Absuma Logistics</title>
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
        
        .input-enhanced {
            transition: all 0.2s;
            border: 1px solid #d1d5db;
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
    </style>
</head>
<body class="gradient-bg">
    <div class="min-h-screen">
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
                        
                        <!-- Vehicle Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Vehicle Section</h3>
                            <nav class="space-y-1.5">
                                <a href="../manage_vehicles.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-dark hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Add Vehicle & Driver
                                </a>
                                <a href="../manage_vehicles.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-truck w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Vehicles
                                </a>
                                <a href="../manage_drivers.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-users w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Drivers
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Client Section -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Client Section</h3>
                            <nav class="space-y-1.5">
                                <a href="../manage_clients.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-dark hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-plus w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Register Client
                                </a>
                                <a href="../manage_clients.php" class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-md transition-colors">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Clients
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1">
                    <div class="bg-white rounded-xl shadow-soft p-6 border-l-4 border-absuma-red">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800 mb-2">
                                    <i class="fas fa-edit text-absuma-red mr-3"></i>
                                    Edit Location
                                </h1>
                                <p class="text-gray-600">Update location information</p>
                            </div>
                        </div>

                        <?php if ($success_message): ?>
                            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-6">
                                <i class="fas fa-check-circle mr-2"></i>
                                <?= htmlspecialchars($success_message) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($error_message): ?>
                            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <?= htmlspecialchars($error_message) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="space-y-6">
                            <div class="bg-gray-50 rounded-lg p-6">
                                <div class="grid grid-cols-1 gap-6">
                                    <div>
                                        <label for="location_name" class="block text-sm font-medium text-gray-700 mb-2">
                                            Location Name *
                                        </label>
                                        <input type="text" 
                                               id="location_name" 
                                               name="location_name" 
                                               value="<?= htmlspecialchars($location['location']) ?>"
                                               class="input-enhanced w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-absuma-red focus:border-absuma-red"
                                               required>
                                        <p class="text-sm text-gray-500 mt-1">
                                            Enter the location name (e.g., Mumbai Port, Delhi Warehouse)
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t border-gray-200">
                                <button type="submit" class="btn-enhanced btn-primary flex items-center justify-center">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Location
                                </button>
                                <a href="manage.php" class="btn-enhanced btn-secondary flex items-center justify-center">
                                    <i class="fas fa-list mr-2"></i>
                                    Back to Locations
                                </a>
                                <a href="../dashboard.php" class="btn-enhanced btn-secondary flex items-center justify-center">
                                    <i class="fas fa-arrow-left mr-2"></i>
                                    Back to Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                </main>
            </div>
        </div>
    </div>
</body>
</html>
