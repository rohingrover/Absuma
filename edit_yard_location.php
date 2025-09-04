<?php
session_start();
require 'auth_check.php';
require 'db_connection.php';

// Set page title and subtitle for header component
$page_title = "Edit Yard Location";
$page_subtitle = "Update yard location details";

$success = '';
$error = '';
$yard = [];

// Get yard ID from URL
$yardId = $_GET['id'] ?? null;

if (!$yardId || !is_numeric($yardId)) {
    header("Location: manage_yard_locations.php");
    exit();
}

try {
    // Fetch yard details
    $stmt = $pdo->prepare("
        SELECT yl.*, l.location as location_name 
        FROM yard_locations yl
        LEFT JOIN location l ON yl.location_id = l.id
        WHERE yl.id = ? AND yl.deleted_at IS NULL
    ");
    $stmt->execute([$yardId]);
    $yard = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$yard) {
        $_SESSION['error'] = "Yard location not found";
        header("Location: manage_yard_locations.php");
        exit();
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Failed to fetch yard details: " . $e->getMessage();
    header("Location: manage_yard_locations.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Validate required fields
        $required_fields = ['yard_name', 'location_id', 'yard_type'];
        $errors = [];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        if (!empty($errors)) {
            throw new Exception(implode('<br>', $errors));
        }
        
        // Prepare data
        $yard_data = [
            'yard_name' => trim($_POST['yard_name']),
            'location_id' => (int)$_POST['location_id'],
            'yard_code' => trim($_POST['yard_code'] ?? ''),
            'yard_type' => $_POST['yard_type'],
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'phone_number' => trim($_POST['phone_number'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $yardId
        ];
        
        // Check for duplicate yard name in same location (excluding current yard)
        $duplicateCheck = $pdo->prepare("
            SELECT id FROM yard_locations 
            WHERE yard_name = ? AND location_id = ? AND id != ? AND deleted_at IS NULL
        ");
        $duplicateCheck->execute([$yard_data['yard_name'], $yard_data['location_id'], $yardId]);
        if ($duplicateCheck->rowCount() > 0) {
            throw new Exception('Another yard with this name already exists in the selected location');
        }
        
        // Check for duplicate yard code if provided (excluding current yard)
        if (!empty($yard_data['yard_code'])) {
            $codeCheck = $pdo->prepare("
                SELECT id FROM yard_locations 
                WHERE yard_code = ? AND id != ? AND deleted_at IS NULL
            ");
            $codeCheck->execute([$yard_data['yard_code'], $yardId]);
            if ($codeCheck->rowCount() > 0) {
                throw new Exception('Yard code already exists');
            }
        }
        
        // Update yard location
        $sql = "UPDATE yard_locations SET
            yard_name = :yard_name,
            location_id = :location_id,
            yard_code = :yard_code,
            yard_type = :yard_type,
            contact_person = :contact_person,
            phone_number = :phone_number,
            email = :email,
            address = :address,
            updated_at = :updated_at
            WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($yard_data);
        
        $pdo->commit();
        $success = "Yard location updated successfully!";
        
        // Refresh yard data
        $stmt = $pdo->prepare("
            SELECT yl.*, l.location as location_name 
            FROM yard_locations yl
            LEFT JOIN location l ON yl.location_id = l.id
            WHERE yl.id = ?
        ");
        $stmt->execute([$yardId]);
        $yard = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Get locations for dropdown
$locations = [];
try {
    $locStmt = $pdo->prepare("SELECT id, location FROM location ORDER BY location ASC");
    $locStmt->execute();
    $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Failed to load locations: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Yard Location - Absuma Logistics Fleet Management</title>
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

        .form-input {
            @apply w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-absuma-red focus:border-absuma-red transition-all;
        }
        
        .form-input:focus {
            @apply shadow-glow;
        }
        
        .field-changed {
            @apply border-yellow-400 bg-yellow-50;
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
                                <a href="manage_yard_locations.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-list w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Manage Yards
                                </a>
                                <a href="#" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium absuma-gradient text-white rounded-lg transition-all">
                                    <i class="fas fa-edit w-5 text-center"></i>Edit Yard
                                </a>
                                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all group">
                                    <i class="fas fa-tachometer-alt w-5 text-center text-absuma-red group-hover:text-absuma-red"></i>Dashboard
                                </a>
                            </nav>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-wider text-absuma-red font-bold mb-3 pl-2 border-l-4 border-absuma-red/50">Quick Actions</h3>
                            <nav class="space-y-1.5">
                                <!-- Status Toggle -->
                                <form method="POST" action="manage_yard_locations.php" class="inline w-full">
                                    <input type="hidden" name="yard_id" value="<?= $yard['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $yard['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" name="update_status" 
                                            class="w-full text-left px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-red-50 hover:text-absuma-red rounded-lg transition-all flex items-center gap-3"
                                            onclick="return confirm('Are you sure you want to <?= $yard['is_active'] ? 'deactivate' : 'activate' ?> this yard?');">
                                        <i class="fas <?= $yard['is_active'] ? 'fa-ban text-red-500' : 'fa-check text-green-500' ?> w-5 text-center"></i>
                                        <?= $yard['is_active'] ? 'Deactivate' : 'Activate' ?> Yard
                                    </button>
                                </form>
                            </nav>
                        </div>
                        
                        <!-- Current Yard Info -->
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-100 rounded-lg p-4 border border-blue-200">
                            <h4 class="text-sm font-semibold text-blue-800 mb-2 flex items-center">
                                <i class="fas fa-info-circle mr-2"></i>Current Yard
                            </h4>
                            <div class="text-xs text-blue-700 space-y-1">
                                <div class="font-medium"><?= htmlspecialchars($yard['yard_name']) ?></div>
                                <div class="flex items-center">
                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                    <?= htmlspecialchars($yard['location_name'] ?? 'Unknown') ?>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-tag mr-1"></i>
                                    <span class="capitalize"><?= htmlspecialchars($yard['yard_type']) ?></span>
                                </div>
                                <div class="flex items-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $yard['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <i class="fas <?= $yard['is_active'] ? 'fa-check-circle' : 'fa-times-circle' ?> mr-1"></i>
                                        <?= $yard['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                            </div>
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
                                        Edit Yard Location
                                    </h2>
                                    <p class="text-gray-600">Update the details for "<?= htmlspecialchars($yard['yard_name']) ?>"</p>
                                </div>
                                <div class="hidden md:block">
                                    <div class="bg-red-50 p-4 rounded-lg">
                                        <i class="fas fa-edit text-3xl text-absuma-red"></i>
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

                        <!-- Edit Form -->
                        <div class="bg-white rounded-xl shadow-soft overflow-hidden card-hover-effect">
                            <div class="px-6 py-4 bg-gradient-to-r from-red-50 to-pink-50 border-b border-gray-200">
                                <h3 class="text-xl font-semibold text-gray-800 flex items-center">
                                    <i class="fas fa-warehouse text-absuma-red mr-2"></i>Yard Location Details
                                </h3>
                            </div>

                            <form method="POST" class="p-6">
                                <!-- Basic Information Section -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>Basic Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Yard Name -->
                                        <div>
                                            <label for="yard_name" class="block text-sm font-medium text-gray-700 mb-2">
                                                Yard Name <span class="text-absuma-red">*</span>
                                            </label>
                                            <input type="text" id="yard_name" name="yard_name" required
                                                   value="<?= htmlspecialchars($yard['yard_name']) ?>"
                                                   class="form-input"
                                                   placeholder="e.g., JNPT Container Terminal">
                                        </div>

                                        <!-- Location -->
                                        <div>
                                            <label for="location_id" class="block text-sm font-medium text-gray-700 mb-2">
                                                Main Location <span class="text-absuma-red">*</span>
                                            </label>
                                            <select id="location_id" name="location_id" required class="form-input">
                                                <option value="">Select Location</option>
                                                <?php foreach ($locations as $location): ?>
                                                    <option value="<?= $location['id'] ?>" 
                                                            <?= ($yard['location_id'] == $location['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($location['location']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Yard Code -->
                                        <div>
                                            <label for="yard_code" class="block text-sm font-medium text-gray-700 mb-2">
                                                Yard Code
                                            </label>
                                            <input type="text" id="yard_code" name="yard_code"
                                                   value="<?= htmlspecialchars($yard['yard_code']) ?>"
                                                   class="form-input"
                                                   placeholder="e.g., JNPT-01">
                                            <p class="text-xs text-gray-500 mt-1">Optional unique identifier for the yard</p>
                                        </div>

                                        <!-- Yard Type -->
                                        <div>
                                            <label for="yard_type" class="block text-sm font-medium text-gray-700 mb-2">
                                                Yard Type <span class="text-absuma-red">*</span>
                                            </label>
                                            <select id="yard_type" name="yard_type" required class="form-input">
                                                <option value="">Select Type</option>
                                                <option value="warehouse" <?= $yard['yard_type'] === 'warehouse' ? 'selected' : '' ?>>
                                                    <i class="fas fa-warehouse"></i> Warehouse
                                                </option>
                                                <option value="storage" <?= $yard['yard_type'] === 'storage' ? 'selected' : '' ?>>
                                                    Storage
                                                </option>
                                                <option value="distribution" <?= $yard['yard_type'] === 'distribution' ? 'selected' : '' ?>>
                                                    Distribution Center
                                                </option>
                                                <option value="transit" <?= $yard['yard_type'] === 'transit' ? 'selected' : '' ?>>
                                                    Transit Hub
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Contact Information Section -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-address-book text-green-500 mr-2"></i>Contact Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Contact Person -->
                                        <div>
                                            <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-2">
                                                Contact Person
                                            </label>
                                            <input type="text" id="contact_person" name="contact_person"
                                                   value="<?= htmlspecialchars($yard['contact_person']) ?>"
                                                   class="form-input"
                                                   placeholder="e.g., John Doe">
                                        </div>

                                        <!-- Phone Number -->
                                        <div>
                                            <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-2">
                                                Phone Number
                                            </label>
                                            <input type="tel" id="phone_number" name="phone_number"
                                                   value="<?= htmlspecialchars($yard['phone_number']) ?>"
                                                   class="form-input"
                                                   placeholder="+91 9876543210">
                                        </div>

                                        <!-- Email -->
                                        <div>
                                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                                Email Address
                                            </label>
                                            <input type="email" id="email" name="email"
                                                   value="<?= htmlspecialchars($yard['email']) ?>"
                                                   class="form-input"
                                                   placeholder="contact@example.com">
                                        </div>

                                        <!-- Address -->
                                        <div>
                                            <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                                                Address
                                            </label>
                                            <textarea id="address" name="address" rows="3"
                                                      class="form-input resize-none"
                                                      placeholder="Full address of the yard"><?= htmlspecialchars($yard['address']) ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Record Information -->
                                <div class="mb-8">
                                    <h4 class="text-lg font-medium text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-history text-purple-500 mr-2"></i>Record Information
                                    </h4>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div class="bg-blue-50 p-4 rounded-lg">
                                            <div class="text-sm font-medium text-blue-800">Status</div>
                                            <div class="mt-1">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $yard['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                    <i class="fas <?= $yard['is_active'] ? 'fa-check-circle' : 'fa-times-circle' ?> mr-1"></i>
                                                    <?= $yard['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="bg-green-50 p-4 rounded-lg">
                                            <div class="text-sm font-medium text-green-800">Created</div>
                                            <div class="mt-1 text-sm text-green-700">
                                                <?= date('F j, Y \a\t g:i A', strtotime($yard['created_at'])) ?>
                                            </div>
                                        </div>
                                        
                                        <div class="bg-amber-50 p-4 rounded-lg">
                                            <div class="text-sm font-medium text-amber-800">Last Updated</div>
                                            <div class="mt-1 text-sm text-amber-700">
                                                <?php 
                                                if ($yard['updated_at']) {
                                                    echo date('F j, Y \a\t g:i A', strtotime($yard['updated_at']));
                                                } else {
                                                    echo "Never updated";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!$yard['is_active']): ?>
                                        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                            <div class="flex">
                                                <i class="fas fa-exclamation-triangle text-yellow-400 mr-2 mt-0.5"></i>
                                                <div class="text-yellow-700 text-sm">
                                                    This yard is currently inactive and won't appear in location selections.
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Action Buttons -->
                                <div class="border-t border-gray-200 pt-6">
                                    <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3">
                                        <a href="manage_yard_locations.php" 
                                           class="px-6 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors flex items-center justify-center">
                                            <i class="fas fa-times mr-2"></i>Cancel
                                        </a>
                                        <button type="reset" 
                                                class="px-6 py-2 border border-amber-300 rounded-lg shadow-sm text-sm font-medium text-amber-700 bg-amber-50 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition-colors flex items-center justify-center">
                                            <i class="fas fa-undo mr-2"></i>Reset Changes
                                        </button>
                                        <button type="submit" 
                                                class="absuma-gradient text-white px-6 py-2 rounded-lg shadow-sm text-sm font-medium hover:shadow-glow focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-absuma-red transition-all flex items-center justify-center">
                                            <i class="fas fa-save mr-2"></i>Update Yard Location
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Additional Information -->
                        <div class="bg-white rounded-xl shadow-soft p-6 card-hover-effect">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
                                Tips for Editing Yard Location
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-600">
                                <div class="space-y-3">
                                    <div class="flex items-start">
                                        <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                                        <div>
                                            <strong>Yard Name:</strong> Choose a descriptive name that clearly identifies the location
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                                        <div>
                                            <strong>Yard Code:</strong> Use a short, unique identifier for easy reference
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                                        <div>
                                            <strong>Contact Info:</strong> Keep contact details updated for effective communication
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div class="flex items-start">
                                        <i class="fas fa-info-circle text-blue-500 mr-2 mt-0.5"></i>
                                        <div>
                                            <strong>Required Fields:</strong> Fields marked with red asterisk (*) are mandatory
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <i class="fas fa-info-circle text-blue-500 mr-2 mt-0.5"></i>
                                        <div>
                                            <strong>Duplicate Check:</strong> System prevents duplicate names within same location
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <i class="fas fa-info-circle text-blue-500 mr-2 mt-0.5"></i>
                                        <div>
                                            <strong>Status Changes:</strong> Use Quick Actions in sidebar to activate/deactivate
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </main>
            </div>
        </div>
    </div>

    <!-- JavaScript for enhanced functionality -->
    <script>
        // Form validation and enhancements
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form[method="POST"]:not([action])');
            const yardNameInput = document.getElementById('yard_name');
            const yardCodeInput = document.getElementById('yard_code');
            
            // Store original values for change detection
            const originalValues = {};
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                originalValues[input.name] = input.value;
                
                // Highlight changed fields
                input.addEventListener('input', function() {
                    if (this.value !== originalValues[this.name]) {
                        this.classList.add('field-changed');
                    } else {
                        this.classList.remove('field-changed');
                    }
                });
            });
            
            // Phone number formatting
            const phoneInput = document.getElementById('phone_number');
            phoneInput.addEventListener('input', function() {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 10) value = value.substring(0, 10);
                this.value = value;
            });
            
            // Form submission validation and confirmation
            form.addEventListener('submit', function(e) {
                const yardName = yardNameInput.value.trim();
                const locationId = document.getElementById('location_id').value;
                const yardType = document.getElementById('yard_type').value;
                
                // Validate required fields
                if (!yardName || !locationId || !yardType) {
                    e.preventDefault();
                    alert('Please fill all required fields marked with *');
                    return false;
                }
                
                // Check if any changes were made
                let hasChanges = false;
                inputs.forEach(input => {
                    if (input.value !== originalValues[input.name]) {
                        hasChanges = true;
                    }
                });
                
                if (!hasChanges) {
                    e.preventDefault();
                    alert('No changes detected. Please make some changes before updating.');
                    return false;
                }
                
                // Confirm update
                if (!confirm('Are you sure you want to update this yard location?')) {
                    e.preventDefault();
                    return false;
                }
                
                // Show loading state
                const submitBtn = document.querySelector('button[type="submit"]:not([name])');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Updating...';
                
                // Re-enable button after delay (in case of validation errors)
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Update Yard Location';
                }, 5000);
            });
            
            // Reset form to original values
            const resetBtn = document.querySelector('button[type="reset"]');
            resetBtn.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to reset all changes?')) {
                    e.preventDefault();
                    return false;
                }
                
                // Reset all fields to original values and remove change indicators
                inputs.forEach(input => {
                    input.value = originalValues[input.name];
                    input.classList.remove('field-changed');
                });
            });
            
            // Auto-hide success/error messages after 5 seconds
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
            
            // Enhanced form field interactions
            inputs.forEach(input => {
                // Add focus effects
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('ring-2', 'ring-absuma-red', 'ring-opacity-20');
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('ring-2', 'ring-absuma-red', 'ring-opacity-20');
                });
            });
            
            // Validate email format
            const emailInput = document.getElementById('email');
            emailInput.addEventListener('blur', function() {
                if (this.value && !this.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                    this.classList.add('border-red-300', 'bg-red-50');
                    if (!this.nextElementSibling || !this.nextElementSibling.classList.contains('error-message')) {
                        const errorMsg = document.createElement('p');
                        errorMsg.className = 'text-xs text-red-500 mt-1 error-message';
                        errorMsg.textContent = 'Please enter a valid email address';
                        this.parentElement.appendChild(errorMsg);
                    }
                } else {
                    this.classList.remove('border-red-300', 'bg-red-50');
                    const errorMsg = this.parentElement.querySelector('.error-message');
                    if (errorMsg) errorMsg.remove();
                }
            });
            
            // Yard code formatting
            yardCodeInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
            });
            
            // Character count for textarea
            const addressTextarea = document.getElementById('address');
            const charCountDiv = document.createElement('div');
            charCountDiv.className = 'text-xs text-gray-500 mt-1 text-right';
            addressTextarea.parentElement.appendChild(charCountDiv);
            
            addressTextarea.addEventListener('input', function() {
                const remaining = 500 - this.value.length;
                charCountDiv.textContent = `${this.value.length}/500 characters`;
                
                if (remaining < 50) {
                    charCountDiv.className = 'text-xs text-red-500 mt-1 text-right';
                } else if (remaining < 100) {
                    charCountDiv.className = 'text-xs text-yellow-500 mt-1 text-right';
                } else {
                    charCountDiv.className = 'text-xs text-gray-500 mt-1 text-right';
                }
                
                if (this.value.length > 500) {
                    this.value = this.value.substring(0, 500);
                }
            });
            
            // Trigger initial character count
            addressTextarea.dispatchEvent(new Event('input'));
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                const submitBtn = document.querySelector('button[type="submit"]:not([name])');
                if (submitBtn) submitBtn.click();
            }
            
            // Ctrl+R to reset
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                const resetBtn = document.querySelector('button[type="reset"]');
                if (resetBtn) resetBtn.click();
            }
            
            // Escape to cancel
            if (e.key === 'Escape') {
                const cancelBtn = document.querySelector('a[href="manage_yard_locations.php"]');
                if (cancelBtn && confirm('Are you sure you want to cancel editing? Any unsaved changes will be lost.')) {
                    window.location.href = cancelBtn.href;
                }
            }
        });
        
        // Warn about unsaved changes when leaving page
        let hasUnsavedChanges = false;
        
        document.querySelectorAll('input, select, textarea').forEach(input => {
            input.addEventListener('input', function() {
                hasUnsavedChanges = true;
            });
        });
        
        document.querySelector('form[method="POST"]').addEventListener('submit', function() {
            hasUnsavedChanges = false;
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
        
        // Enhanced tooltip functionality
        document.querySelectorAll('[title]').forEach(element => {
            let tooltip = null;
            
            element.addEventListener('mouseenter', function() {
                tooltip = document.createElement('div');
                tooltip.className = 'absolute z-50 p-2 text-xs bg-gray-800 text-white rounded shadow-lg max-w-xs pointer-events-none';
                tooltip.textContent = this.getAttribute('title');
                
                const rect = this.getBoundingClientRect();
                tooltip.style.top = (rect.top - 35) + 'px';
                tooltip.style.left = rect.left + 'px';
                
                document.body.appendChild(tooltip);
                this.removeAttribute('title'); // Prevent default browser tooltip
            });
            
            element.addEventListener('mouseleave', function() {
                if (tooltip) {
                    tooltip.remove();
                    tooltip = null;
                }
                this.setAttribute('title', this.getAttribute('data-title') || ''); // Restore title
            });
        });
    </script>
</body>
</html>